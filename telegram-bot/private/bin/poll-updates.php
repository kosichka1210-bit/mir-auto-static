<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$config = require $root . '/private/src/bootstrap.php';
require_once $root . '/private/src/TelegramUpdateInbox.php';
$directory = $root . '/private/storage/update-queue';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('poll_storage_unavailable');
$lock = fopen($directory . '/poll.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(1);
chmod($directory . '/poll.lock', 0600);
$offset = is_file($directory . '/poll-offset') ? (int) file_get_contents($directory . '/poll-offset') : 0;
$telegram = new TelegramClient($config['telegram']['token']);
while (true) {
    try {
        $response = $telegram->pollUpdates($offset);
        $updates = $response['result'] ?? null;
        if (!is_array($updates)) throw new RuntimeException('invalid_poll_response');
        $offset = TelegramUpdateInbox::persist($directory, $updates, $offset);
        TelegramUpdateInbox::atomicWrite($directory . '/poll-health.json', json_encode(['last_success' => gmdate(DATE_ATOM), 'queued' => count($updates)]));
        if ($updates !== []) TelegramClient::logPerformance('poll_updates', ['queued' => count($updates)]);
    } catch (Throwable $exception) {
        TelegramClient::logPerformance('poll_updates', ['result' => 'retry', 'error_class' => get_class($exception)]);
        usleep(500000);
    }
}
