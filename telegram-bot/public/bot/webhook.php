<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    $config = require dirname(__DIR__, 2) . '/private/src/bootstrap.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }

    $expectedSecret = (string) ($config['telegram']['webhook_secret'] ?? '');
    $receivedSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');

    if ($expectedSecret === '' || !hash_equals($expectedSecret, $receivedSecret)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    $rawBody = file_get_contents('php://input');
    $update = json_decode((string) $rawBody, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($update)) {
        throw new RuntimeException('Некорректное обновление Telegram.');
    }

    $database = new Database($config['database']);
    $telegram = new TelegramClient((string) $config['telegram']['token']);
    $bot = new Bot($telegram, $database, $config);
    $bot->handle($update);

    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    error_log('MIR AUTO bot: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}

