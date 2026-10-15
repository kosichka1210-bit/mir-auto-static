<?php
declare(strict_types=1);

final class ContactDeliveryQueue
{
    private static function save(string $path, array $job): void
    {
        $temp = tempnam(dirname($path), '.contact-');
        if ($temp === false) throw new RuntimeException('contact_queue_unavailable');
        try {
            chmod($temp, 0600);
            if (file_put_contents($temp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false
                || !rename($temp, $path)) throw new RuntimeException('contact_queue_write_failed');
        } finally { if (is_file($temp)) unlink($temp); }
    }

    public static function enqueue(string $directory, array $recipients, string $message, ?array $replyMarkup = null): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('contact_queue_unavailable');
        self::save($directory . '/' . bin2hex(random_bytes(16)) . '.json', [
            'message' => $message, 'pending' => array_values(array_unique($recipients)),
            'delivered' => [], 'retry_at' => 0, 'attempts' => 0,
            'reply_markup' => $replyMarkup,
        ]);
    }

    /** One attempt per scan; successful recipients are checkpointed individually. */
    public static function processOne(string $directory, callable $send): bool
    {
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $lock = fopen($path . '.lock', 'c');
            if (!$lock) continue;
            chmod($path . '.lock', 0600);
            if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); continue; }
            try {
                if (!is_file($path)) continue;
                $job = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if ((int) $job['retry_at'] > time()) continue;
                $id = (int) ($job['pending'][0] ?? 0);
                if ($id <= 0) { unlink($path); continue; }
                try {
                    $send($id, (string) $job['message'], $job['reply_markup'] ?? null);
                    array_shift($job['pending']);
                    $job['delivered'][] = $id;
                    $job['attempts'] = 0;
                    $job['retry_at'] = 0;
                    TelegramClient::logPerformance('contact_queue', ['result' => 'delivered', 'remaining' => count($job['pending'])]);
                } catch (Throwable $exception) {
                    // Rotate a failing recipient so another staff member can receive now.
                    $job['pending'][] = array_shift($job['pending']);
                    $job['attempts']++;
                    $job['retry_at'] = time() + min(300, 5 * $job['attempts']);
                    TelegramClient::logPerformance('contact_queue', ['result' => 'retry', 'error_class' => get_class($exception)]);
                }
                if ($job['pending'] === []) unlink($path);
                else self::save($path, $job);
                return true;
            } finally {
                flock($lock, LOCK_UN); fclose($lock);
            }
        }
        return false;
    }
}
