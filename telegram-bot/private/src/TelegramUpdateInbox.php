<?php
declare(strict_types=1);

final class TelegramUpdateInbox
{
    public static function atomicWrite(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.poll-');
        if ($temporary === false) throw new RuntimeException('poll_storage_unavailable');
        try {
            chmod($temporary, 0600);
            $handle = fopen($temporary, 'wb');
            if (!$handle) throw new RuntimeException('poll_storage_unavailable');
            try {
                if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !fsync($handle)) {
                    throw new RuntimeException('poll_storage_write_failed');
                }
            } finally { fclose($handle); }
            if (!rename($temporary, $path)) throw new RuntimeException('poll_storage_commit_failed');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    /** Advance the Telegram offset only after every genuine update is durable. */
    public static function persist(string $directory, array $updates, int $offset): int
    {
        foreach ($updates as $update) {
            $id = $update['update_id'] ?? null;
            if (!is_int($id) || $id < 0) throw new RuntimeException('invalid_update_id');
            $name = sprintf('%020d', $id);
            $path = $directory . '/' . $name . '.json';
            if (!is_file($path) && !is_file($path . '.processing') && !is_file($directory . '/done/' . $name . '.done')) {
                self::atomicWrite($path, json_encode($update, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            $offset = max($offset, $id + 1);
        }
        self::atomicWrite($directory . '/poll-offset', (string) $offset);
        return $offset;
    }
}
