<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

try {
    $privateDir = dirname(__DIR__, 2) . '/private';
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $privateDir . '/storage/contact-error.log');
    $config = require $privateDir . '/src/bootstrap.php';
    $allowedOrigins = array_values(array_filter(array_map('strval', $config['app']['cors_origins'] ?? [])));

    if ($method === 'OPTIONS') {
        if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'origin_not_allowed']);
            exit;
        }
        if ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
        http_response_code(204);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST, OPTIONS');
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }

    if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'origin_not_allowed']);
        exit;
    }
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    $body = (string) file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($body) > 8192) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'request_too_large']);
        exit;
    }
    $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Некорректный формат заявки.');
    }

    // Honeypot for simple bots. Real users never see or fill this field.
    if (!is_string($payload['website'] ?? '') || trim($payload['website'] ?? '') !== '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_request']);
        exit;
    }

    $clean = static function (mixed $value, int $maxLength): string {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid field type');
        }
        $value = trim(strip_tags((string) $value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return mb_substr($value, 0, $maxLength);
    };

    $fields = [
        'name' => $clean($payload['name'] ?? '', 120),
        'contact' => $clean($payload['contact'] ?? '', 160),
        'model' => $clean($payload['model'] ?? '', 160),
        'budget' => $clean($payload['budget'] ?? '', 80),
        'comment' => $clean($payload['comment'] ?? '', 1000),
    ];
    foreach (['name', 'contact', 'model', 'budget'] as $required) {
        if ($fields[$required] === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'required_field', 'field' => $required]);
            exit;
        }
    }

    // Lock before sending: concurrent requests cannot bypass throttling.
    $rateDir = $privateDir . '/storage/contact-rate';
    if (!is_dir($rateDir)) {
        @mkdir($rateDir, 0700, true);
    }
    $rateKey = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $rateFile = $rateDir . '/' . $rateKey;
    $lock = fopen($rateFile, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'request_in_progress']);
        exit;
    }
    $previous = json_decode(stream_get_contents($lock), true) ?: [];
    $fingerprint = hash('sha256', json_encode($fields));
    if (($previous['sent'] ?? false) && ($previous['fingerprint'] ?? '') === $fingerprint && time() - ($previous['time'] ?? 0) < 600) {
        echo json_encode(['ok' => true]);
        exit;
    }
    if (time() - ($previous['time'] ?? 0) < 60) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'too_many_requests']);
        exit;
    }
    $saveState = static function (bool $sent) use ($lock, $fingerprint): void {
        rewind($lock);
        ftruncate($lock, 0);
        fwrite($lock, json_encode(['time' => time(), 'fingerprint' => $fingerprint, 'sent' => $sent]));
        fflush($lock);
    };
    $saveState(false);

    // The private bootstrap merges the administrator and approved staff IDs.
    // Keep recipients server-side; never accept a destination from the form.
    $recipientIds = array_values(array_unique(array_filter(array_map(
        'intval',
        $config['telegram']['allowed_user_ids'] ?? []
    ), static fn(int $id): bool => $id > 0)));
    if ($recipientIds === []) {
        throw new RuntimeException('Не настроены получатели заявок.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
    $message = "🟣 <b>Новая заявка MIR AUTO</b>\n\n"
        . 'Имя: ' . htmlspecialchars($fields['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
        . 'Телефон / Telegram: ' . htmlspecialchars($fields['contact'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
        . 'Марка или модель: ' . htmlspecialchars($fields['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
        . 'Бюджет: ' . htmlspecialchars($fields['budget'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
        . 'Комментарий: ' . htmlspecialchars($fields['comment'] !== '' ? $fields['comment'] : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
        . "Источник: сайт MIR AUTO\n"
        . 'Дата и время: ' . $now->format('d.m.Y H:i');

    $sent = 0;
    foreach ($recipientIds as $recipientId) {
        if ($recipientId <= 0) {
            continue;
        }
        try {
            $curl = curl_init('https://api.telegram.org/bot' . $config['telegram']['token'] . '/sendMessage');
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['chat_id' => $recipientId, 'text' => $message, 'parse_mode' => 'HTML'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($curl);
            $http = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($curl);
            curl_close($curl);
            $result = is_string($response) ? json_decode($response, true) : null;
            if ($http !== 200 || ($result['ok'] ?? false) !== true) {
                error_log('MIR AUTO contact: telegram_http=' . $http . ' curl_errno=' . $errno);
                throw new RuntimeException('Telegram rejected delivery');
            }
            error_log('MIR AUTO contact: delivered message_id=' . (int) ($result['result']['message_id'] ?? 0));
            $sent++;
        } catch (Throwable $exception) {
            error_log('MIR AUTO contact: delivery failed');
        }
    }

    if ($sent !== count($recipientIds)) {
        throw new RuntimeException('Не удалось доставить заявку.');
    }

    $saveState(true);
    echo json_encode(['ok' => true]);
} catch (JsonException | InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_request']);
} catch (Throwable $exception) {
    error_log('MIR AUTO contact: delivery_failed type=' . get_class($exception));
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'delivery_failed']);
}
