<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/private/src/bootstrap.php';
$allowedUsers = array_values(array_unique(array_map('intval', $config['telegram']['allowed_user_ids'] ?? [])));
if ($allowedUsers === []) {
    fwrite(STDERR, "No whitelisted Telegram user is configured.\n");
    exit(1);
}

$probeUserId = $allowedUsers[0];
$cancelUserId = null;
$mysqlStarted = hrtime(true);
try {
    $database = new Database($config['database']);
    $database->pdo()->query('SELECT 1')->fetchColumn();
    $mysqlConnectMs = round((hrtime(true) - $mysqlStarted) / 1_000_000, 2);
    foreach ($allowedUsers as $candidate) {
        if ($database->getSession($candidate) === null) {
            $cancelUserId = $candidate;
            break;
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "MySQL benchmark setup failed.\n");
    exit(1);
}

$telegram = new TelegramClient((string) $config['telegram']['token']);
$meStarted = hrtime(true);
$me = $telegram->getMe();
$getMeMs = round((hrtime(true) - $meStarted) / 1_000_000, 2);
$webhookInfo = $telegram->getWebhookInfo()['result'] ?? [];

$measureCommand = static function (string $command, int $userId) use ($config): array {
    $samples = [];
    $validResponses = 0;
    for ($i = 0; $i < 5; $i++) {
        $update = [
            'update_id' => random_int(1_000_000_000, 1_900_000_000),
            'message' => [
                'message_id' => random_int(1, 2_000_000_000),
                'date' => time(),
                'chat' => ['id' => $userId, 'type' => 'private'],
                'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'MIR AUTO performance check'],
                'text' => $command,
            ],
        ];
        $curl = curl_init('https://bot.mir-auto-china.ru/bot/webhook.php');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($update, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Telegram-Bot-Api-Secret-Token: ' . (string) $config['telegram']['webhook_secret'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_RESOLVE => ['bot.mir-auto-china.ru:443:127.0.0.1'],
        ]);
        $started = hrtime(true);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $samples[] = round((hrtime(true) - $started) / 1_000_000, 2);
        $payload = is_string($body) ? json_decode($body, true) : null;
        if ($status === 200 && is_array($payload) && ($payload['method'] ?? '') === 'sendMessage') {
            $validResponses++;
        }
    }

    sort($samples);
    return [
        'command' => $command,
        'runs' => count($samples),
        'valid_fast_responses' => $validResponses,
        'samples_ms' => $samples,
        'average_ms' => round(array_sum($samples) / max(1, count($samples)), 2),
        'max_ms' => max($samples),
    ];
};

$results = [
    'telegram_getMe' => [
        'ok' => ($me['ok'] ?? false) === true,
        'username' => $me['result']['username'] ?? null,
        'http_api_elapsed_ms' => $getMeMs,
    ],
    'mysql' => [
        'select_1_ok' => true,
        'connect_and_query_ms' => $mysqlConnectMs,
    ],
    'start' => $measureCommand('/start', $probeUserId),
    'help' => $measureCommand('/help', $probeUserId),
    'cancel' => $cancelUserId === null
        ? ['skipped' => 'an authorized user currently has a draft; no session was touched']
        : $measureCommand('/cancel', $cancelUserId),
    'webhook' => [
        'url' => $webhookInfo['url'] ?? '',
        'pending_update_count' => (int) ($webhookInfo['pending_update_count'] ?? 0),
        'last_error_date' => (int) ($webhookInfo['last_error_date'] ?? 0),
        'last_error_message' => mb_substr((string) ($webhookInfo['last_error_message'] ?? ''), 0, 180),
    ],
];

if (in_array('--send-test', $argv, true)) {
    $sendStarted = hrtime(true);
    $sent = $telegram->sendMessage($probeUserId, 'Техническая проверка MIR AUTO: соединение с Telegram работает.');
    $results['telegram_sendMessage'] = [
        'ok' => ($sent['ok'] ?? false) === true,
        'api_elapsed_ms' => round((hrtime(true) - $sendStarted) / 1_000_000, 2),
    ];
}

fwrite(STDOUT, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
