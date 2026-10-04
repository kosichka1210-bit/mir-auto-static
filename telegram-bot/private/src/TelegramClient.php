<?php

declare(strict_types=1);

final class TelegramClient
{
    /**
     * This host's normal resolver currently returns a Telegram address that
     * times out. Prefer the verified reachable IPv4, then fall back to DNS.
     */
    private const PREFERRED_API_IPV4 = '149.154.167.220';

    private string $token;
    private string $apiBase;

    public function __construct(string $token)
    {
        if ($token === '' || str_contains($token, 'PASTE_TOKEN')) {
            throw new InvalidArgumentException('В конфигурации не указан токен Telegram-бота.');
        }

        $this->token = $token;
        $this->apiBase = 'https://api.telegram.org/bot' . $token . '/';
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $this->request('sendMessage', $payload);
    }

    public function sendMediaGroup(int $chatId, array $media): array
    {
        return $this->request('sendMediaGroup', [
            'chat_id' => $chatId,
            'media' => json_encode($media, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    public function sendPhoto(int $chatId, string $photo, string $caption = ''): array
    {
        return $this->request('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    public function getMe(): array
    {
        return $this->request('getMe', []);
    }

    public function getWebhookInfo(): array
    {
        return $this->request('getWebhookInfo', []);
    }

    /** @param array<string, scalar|null> $context */
    public static function logPerformance(string $category, array $context): void
    {
        if (preg_match('/^[a-z_]+$/', $category) !== 1) {
            return;
        }

        $line = 'MIR_AUTO ' . $category . ' ' . json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        $path = dirname(__DIR__) . '/storage/webhook-performance.log';
        $handle = @fopen($path, 'ab+');
        if ($handle === false) {
            error_log($line);
            return;
        }

        if (flock($handle, LOCK_EX)) {
            $stat = fstat($handle);
            if (($stat['size'] ?? 0) > 524288) {
                ftruncate($handle, 0);
            }
            fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        @chmod($path, 0600);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): array
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]);
    }

    public function downloadPhoto(string $fileId, string $targetPath): void
    {
        $file = $this->request('getFile', ['file_id' => $fileId]);
        $remotePath = $file['result']['file_path'] ?? null;

        if (!is_string($remotePath) || $remotePath === '') {
            throw new RuntimeException('Telegram не вернул путь к фотографии.');
        }

        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать папку для временной фотографии.');
        }

        $downloadUrl = 'https://api.telegram.org/file/bot' . $this->token . '/' . ltrim($remotePath, '/');
        $success = false;
        $error = '';
        // Retry the known-good Telegram IPv4 before falling back to DNS,
        // whose normal answer is intermittently unreachable from this host.
        foreach ([self::PREFERRED_API_IPV4, null] as $preferredIp) {
            $handle = fopen($targetPath, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Не удалось создать временный файл фотографии.');
            }

            $curl = curl_init($downloadUrl);
            $options = [
                CURLOPT_FILE => $handle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FAILONERROR => true,
            ];
            if ($preferredIp !== null) {
                $options[CURLOPT_RESOLVE] = ['api.telegram.org:443:' . $preferredIp];
            }
            curl_setopt_array($curl, $options);
            $success = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);
            fclose($handle);

            if ($success !== false) {
                break;
            }
            @unlink($targetPath);
        }

        if ($success === false) {
            @unlink($targetPath);
            throw new RuntimeException('Не удалось скачать фотографию с Telegram за отведённое время.');
        }

        $imageInfo = @getimagesize($targetPath);
        if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== 'image/jpeg') {
            @unlink($targetPath);
            throw new RuntimeException('Полученный файл не является фотографией JPEG.');
        }
    }

    public function setWebhook(string $webhookUrl, string $secret): array
    {
        return $this->request('setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secret,
            'allowed_updates' => json_encode(['message', 'callback_query'], JSON_THROW_ON_ERROR),
        ]);
    }

    public function setCommands(): array
    {
        return $this->request('setMyCommands', [
            'commands' => json_encode([
                ['command' => 'start', 'description' => 'Открыть меню'],
                ['command' => 'addcar', 'description' => 'Добавить автомобиль'],
                ['command' => 'cars', 'description' => 'Последние автомобили'],
                ['command' => 'edit', 'description' => 'Изменить автомобиль'],
                ['command' => 'sold', 'description' => 'Отметить проданной'],
                ['command' => 'delete', 'description' => 'Удалить автомобиль'],
                ['command' => 'cancel', 'description' => 'Отменить черновик'],
                ['command' => 'help', 'description' => 'Помощь'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    private function request(string $method, array $payload): array
    {
        $body = false;
        $status = 0;
        $started = hrtime(true);
        $attempt = 0;
        foreach ([self::PREFERRED_API_IPV4, null] as $preferredIp) {
            $attempt++;
            $attemptStarted = hrtime(true);
            $curl = curl_init($this->apiBase . $method);
            $options = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 5,
            ];
            if ($preferredIp !== null) {
                $options[CURLOPT_RESOLVE] = ['api.telegram.org:443:' . $preferredIp];
            }
            curl_setopt_array($curl, $options);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            self::logPerformance('telegram_api_perf', [
                'method' => preg_match('/^[A-Za-z]+$/', $method) === 1 ? $method : 'unknown',
                'attempt' => $attempt,
                'attempt_ms' => round((hrtime(true) - $attemptStarted) / 1_000_000, 2),
                'api_elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
                'http_status' => $status,
                'transport' => $body === false ? 'failed' : 'response',
            ]);

            if ($body !== false) {
                break;
            }
        }

        if ($body === false) {
            self::logPerformance('telegram_api_perf', [
                'method' => preg_match('/^[A-Za-z]+$/', $method) === 1 ? $method : 'unknown',
                'result' => 'timeout_or_connect_error',
                'api_elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
            ]);
            throw new RuntimeException('Не удалось соединиться с Telegram API за отведённое время.');
        }

        $decoded = json_decode($body, true);
        if ($status >= 400 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            self::logPerformance('telegram_api_perf', [
                'method' => preg_match('/^[A-Za-z]+$/', $method) === 1 ? $method : 'unknown',
                'result' => 'api_error',
                'http_status' => $status,
                'api_elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
            ]);
            $description = is_array($decoded) ? ($decoded['description'] ?? 'неизвестная ошибка') : 'некорректный ответ';
            throw new RuntimeException('Telegram Bot API: ' . $description);
        }

        self::logPerformance('telegram_api_perf', [
            'method' => preg_match('/^[A-Za-z]+$/', $method) === 1 ? $method : 'unknown',
            'result' => 'ok',
            'http_status' => $status,
            'api_elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ]);

        return $decoded;
    }
}
