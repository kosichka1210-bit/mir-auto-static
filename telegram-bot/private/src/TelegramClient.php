<?php

declare(strict_types=1);

final class TelegramClient
{
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

        $handle = fopen($targetPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось создать временный файл фотографии.');
        }

        $curl = curl_init('https://api.telegram.org/file/bot' . $this->token . '/' . ltrim($remotePath, '/'));
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FAILONERROR => true,
        ]);

        $success = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($success === false) {
            @unlink($targetPath);
            throw new RuntimeException('Не удалось скачать фотографию: ' . $error);
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
            'drop_pending_updates' => true,
        ]);
    }

    public function setCommands(): array
    {
        return $this->request('setMyCommands', [
            'commands' => json_encode([
                ['command' => 'start', 'description' => 'Открыть меню'],
                ['command' => 'addcar', 'description' => 'Добавить автомобиль'],
                ['command' => 'cars', 'description' => 'Последние автомобили'],
                ['command' => 'cancel', 'description' => 'Отменить черновик'],
                ['command' => 'help', 'description' => 'Помощь'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    private function request(string $method, array $payload): array
    {
        $curl = curl_init($this->apiBase . $method);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Ошибка соединения с Telegram: ' . $error);
        }

        $decoded = json_decode($body, true);
        if ($status >= 400 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $description = is_array($decoded) ? ($decoded['description'] ?? 'неизвестная ошибка') : 'некорректный ответ';
            throw new RuntimeException('Telegram Bot API: ' . $description);
        }

        return $decoded;
    }
}
