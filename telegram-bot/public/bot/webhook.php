<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$requestStarted = hrtime(true);
$stageStarted = $requestStarted;
$stages = [];
$command = 'other';
$deliveryMode = 'bot_handler';

$logTiming = static function (string $result) use (&$stages, &$command, &$deliveryMode, $requestStarted): void {
    $timings = $stages;
    $timings['total_ms'] = round((hrtime(true) - $requestStarted) / 1_000_000, 2);
    $context = [
        'command' => $command,
        'delivery' => $deliveryMode,
        'result' => $result,
        'timings' => $timings,
    ];
    if (class_exists(TelegramClient::class)) {
        TelegramClient::logPerformance('webhook_perf', $context);
    } else {
        error_log('MIR_AUTO webhook_perf ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
};

$replyThroughWebhook = static function (int $chatId, array $message) use ($logTiming): never {
    $payload = [
        'method' => 'sendMessage',
        'chat_id' => $chatId,
        'text' => $message['text'],
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if (isset($message['reply_markup'])) {
        $payload['reply_markup'] = $message['reply_markup'];
    }

    $logTiming('accepted_for_telegram');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }

    $config = require dirname(__DIR__, 2) . '/private/src/bootstrap.php';
    $stages['bootstrap_ms'] = round((hrtime(true) - $stageStarted) / 1_000_000, 2);
    $stageStarted = hrtime(true);

    $expectedSecret = (string) ($config['telegram']['webhook_secret'] ?? '');
    $receivedSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');

    if ($expectedSecret === '' || !hash_equals($expectedSecret, $receivedSecret)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    $rawBody = file_get_contents('php://input');
    $update = json_decode((string) $rawBody, true, 512, JSON_THROW_ON_ERROR);
    $stages['validation_ms'] = round((hrtime(true) - $stageStarted) / 1_000_000, 2);

    if (!is_array($update)) {
        throw new RuntimeException('Некорректное обновление Telegram.');
    }

    $message = $update['message'] ?? null;
    $messageText = is_array($message) ? trim((string) ($message['text'] ?? $message['caption'] ?? '')) : '';
    if (preg_match('/^\/([A-Za-z0-9_]+)(?:@[A-Za-z0-9_]+)?(?:\s|$)/', $messageText, $matches) === 1) {
        $command = '/' . strtolower($matches[1]);
    }

    if (in_array($command, ['/start', '/help', '/cancel'], true)
        && is_array($message)
        && ($message['chat']['type'] ?? '') === 'private'
    ) {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $userId = (int) ($message['from']['id'] ?? 0);
        if ($chatId > 0 && $userId > 0) {
            $allowedUsers = array_map('intval', $config['telegram']['allowed_user_ids'] ?? []);
            if (!in_array($userId, $allowedUsers, true)) {
                $deliveryMode = 'telegram_webhook_reply';
                $replyThroughWebhook($chatId, Bot::accessDeniedMessage($userId));
            }

            if ($command === '/cancel') {
                $stageStarted = hrtime(true);
                $database = new Database($config['database']);
                $database->deleteSession($userId);
                $stages['mysql_ms'] = round((hrtime(true) - $stageStarted) / 1_000_000, 2);
                $deliveryMode = 'telegram_webhook_reply';
                $replyThroughWebhook($chatId, [
                    'text' => 'Черновик отменён.',
                    'reply_markup' => [
                        'keyboard' => [
                            [['text' => 'Добавить автомобиль']],
                            [['text' => 'Последние автомобили']],
                        ],
                        'resize_keyboard' => true,
                    ],
                ]);
            }

            $deliveryMode = 'telegram_webhook_reply';
            $replyThroughWebhook($chatId, Bot::homeMessage($userId));
        }
    }

    $stageStarted = hrtime(true);
    $database = new Database($config['database']);
    $stages['mysql_connect_ms'] = round((hrtime(true) - $stageStarted) / 1_000_000, 2);

    $telegram = new TelegramClient((string) $config['telegram']['token']);
    $bot = new Bot($telegram, $database, $config);
    $stageStarted = hrtime(true);
    $bot->handle($update);
    $stages['handler_ms'] = round((hrtime(true) - $stageStarted) / 1_000_000, 2);

    $logTiming('ok');
    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    $reason = $exception instanceof PDOException ? 'mysql_error' : 'processing_error';
    $errorContext = [
        'command' => $command,
        'reason' => $reason,
        'exception' => get_class($exception),
    ];
    if (class_exists(TelegramClient::class)) {
        TelegramClient::logPerformance('webhook_error', $errorContext);
    } else {
        error_log('MIR_AUTO webhook_error ' . json_encode($errorContext, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    $logTiming('error');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}
