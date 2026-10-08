<?php

declare(strict_types=1);

$botRoot = dirname(__DIR__, 2);
$config = require $botRoot . '/private/src/bootstrap.php';
$queueDir = $botRoot . '/private/storage/update-queue';
$doneDir = $queueDir . '/done';
$failedDir = $queueDir . '/failed';

foreach ([$queueDir, $doneDir, $failedDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        error_log('MIR AUTO queue worker: storage unavailable');
        exit(1);
    }
    @chmod($directory, 0700);
}

while (true) {
    foreach (glob($queueDir . '/*.json.processing') ?: [] as $staleProcessing) {
        if (filemtime($staleProcessing) !== false && filemtime($staleProcessing) < time() - 60) {
            @rename($staleProcessing, substr($staleProcessing, 0, -strlen('.processing')));
        }
    }

    $files = glob($queueDir . '/*.json') ?: [];
    sort($files, SORT_STRING);
    $processed = false;

    foreach ($files as $queuePath) {
        $queueName = basename($queuePath, '.json');
        if (preg_match('/^\d{20}$/', $queueName) !== 1) {
            continue;
        }

        $metaPath = $queueDir . '/' . $queueName . '.meta';
        $meta = is_file($metaPath) ? json_decode((string) @file_get_contents($metaPath), true) : null;
        if (is_array($meta) && (int) ($meta['retry_at'] ?? 0) > time()) {
            continue;
        }

        $processingPath = $queuePath . '.processing';
        if (!@rename($queuePath, $processingPath)) {
            continue;
        }
        $processed = true;

        try {
            $rawUpdate = file_get_contents($processingPath);
            if ($rawUpdate === false) {
                throw new RuntimeException('queued_update_unreadable');
            }
            $update = json_decode($rawUpdate, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($update)) {
                throw new RuntimeException('queued_update_invalid');
            }

            $database = new Database($config['database']);
            $telegram = new TelegramClient((string) $config['telegram']['token']);
            $bot = new Bot($telegram, $database, $config);
            $started = hrtime(true);
            $bot->handle($update);
            TelegramClient::logPerformance('queue_worker', [
                'update_id' => (int) ($update['update_id'] ?? 0),
                'result' => 'ok',
                'elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
            ]);

            $doneTemporary = tempnam($doneDir, '.done-');
            if ($doneTemporary === false || file_put_contents($doneTemporary, gmdate(DATE_ATOM), LOCK_EX) === false) {
                throw new RuntimeException('done_marker_write_failed');
            }
            @chmod($doneTemporary, 0600);
            if (!@rename($doneTemporary, $doneDir . '/' . $queueName . '.done')) {
                @unlink($doneTemporary);
                throw new RuntimeException('done_marker_commit_failed');
            }
            @unlink($processingPath);
            @unlink($queueDir . '/' . $queueName . '.meta');
        } catch (Throwable $exception) {
            $attempts = (int) (is_array($meta) ? ($meta['attempts'] ?? 0) : 0) + 1;

            if ($attempts >= 5) {
                @rename($processingPath, $failedDir . '/' . $queueName . '.json');
                $failedMarker = tempnam($doneDir, '.failed-');
                if ($failedMarker !== false) {
                    file_put_contents($failedMarker, gmdate(DATE_ATOM), LOCK_EX);
                    @chmod($failedMarker, 0600);
                    @rename($failedMarker, $doneDir . '/' . $queueName . '.done');
                }
                error_log('MIR AUTO queue worker: update moved to failed queue after five attempts');
                @unlink($metaPath);
            } else {
                $retryMeta = json_encode([
                    'attempts' => $attempts,
                    'retry_at' => time() + min(30, 2 ** $attempts),
                    'error_type' => get_class($exception),
                ], JSON_THROW_ON_ERROR);
                file_put_contents($metaPath, $retryMeta, LOCK_EX);
                @chmod($metaPath, 0600);
                @rename($processingPath, $queuePath);
                // Respect retry_at on the next scan without blocking unrelated
                // incoming updates behind a transient Telegram/API error.
            }
        }
    }

    if (!$processed) {
        usleep(250_000);
    }
}
