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

            if ($command === '/addcar') {
                $stageStarted = hrtime(true);
                $database = new Database($config['database']);
                $database->saveSession($userId, $chatId, 'brand', ['images' => []]);
                $stages['mysql_ms'] = round((hrtime(true) - $stageStarted) / 1_000_000, 2);
                $deliveryMode = 'telegram_webhook_reply';
                $replyThroughWebhook($chatId, Bot::addCarPromptMessage());
            }

            if ($command === '/cancel') {
                $stageStarted = hrtime(true);
                $database = new Database($config['database']);
                $existingSession = $database->getSession($userId);
                Bot::cleanupDraftPhotoFiles($existingSession['draft'] ?? [], $config['app'] ?? []);
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

    $updateId = filter_var($update['update_id'] ?? null, FILTER_VALIDATE_INT);
    if ($updateId === false || $updateId === null || $updateId < 0) {
        throw new RuntimeException('В обновлении отсутствует корректный update_id.');
    }

    $queueDir = dirname(__DIR__, 2) . '/private/storage/update-queue';
    $doneDir = $queueDir . '/done';
    foreach ([$queueDir, $doneDir] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить закрытую очередь обновлений.');
        }
        @chmod($directory, 0700);
    }

    $queueName = sprintf('%020d', $updateId);
    $queuePath = $queueDir . '/' . $queueName . '.json';
    $processingPath = $queuePath . '.processing';
    $donePath = $doneDir . '/' . $queueName . '.done';
    if (!is_file($donePath) && !is_file($processingPath) && !is_file($queuePath)) {
        $temporaryPath = tempnam($queueDir, '.incoming-');
        if ($temporaryPath === false || file_put_contents($temporaryPath, (string) $rawBody, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать обновление в закрытую очередь.');
        }
        @chmod($temporaryPath, 0600);
        if (!@link($temporaryPath, $queuePath) && !is_file($queuePath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Не удалось добавить обновление в очередь.');
        }
        @unlink($temporaryPath);
    }

    $stages['queue_ms'] = round((hrtime(true) - $stageStarted) / 1_000_000, 2);
    $deliveryMode = 'durable_queue';
    $logTiming('queued');
    http_response_code(200);
    echo json_encode(['ok' => true]);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        flush();
    }
    exit;
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
