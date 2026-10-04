<?php

declare(strict_types=1);

$privateDir = dirname(__DIR__);
$configFile = null;
foreach ([$privateDir . '/config/config.php', $privateDir . '/config.php'] as $candidate) {
    if (is_file($candidate)) {
        $configFile = $candidate;
        break;
    }
}

if ($configFile === null) {
    throw new RuntimeException('Не найден закрытый конфиг MIR AUTO.');
}

$rawConfig = require $configFile;

if (!is_array($rawConfig)) {
    throw new RuntimeException('Конфигурация должна возвращать массив.');
}

if (isset($rawConfig['telegram']['token'], $rawConfig['database']['dsn'])) {
    $config = $rawConfig;
} else {
    // Backward-compatible adapter for the original production private/config.php shape.
    $token = trim((string) ($rawConfig['telegram_token'] ?? ''));
    $legacyDatabase = $rawConfig['database'] ?? [];
    if ($token === '' || !is_array($legacyDatabase)) {
        throw new RuntimeException('Закрытая конфигурация MIR AUTO неполная.');
    }
    $config = [
        'telegram' => [
            'token' => $token,
            'webhook_secret' => hash('sha256', 'mir-auto-webhook-' . $token),
            'allowed_user_ids' => array_map('intval', $rawConfig['allowed_user_ids'] ?? []),
        ],
        'database' => [
            'dsn' => sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                (string) ($legacyDatabase['host'] ?? 'localhost'),
                (string) ($legacyDatabase['name'] ?? '')
            ),
            'user' => (string) ($legacyDatabase['user'] ?? ''),
            'password' => (string) ($legacyDatabase['password'] ?? ''),
        ],
        'app' => [
            'base_url' => 'https://bot.mir-auto-china.ru',
            'media_dir' => dirname(__DIR__, 2) . '/public_html/media/cars',
            'drafts_dir' => dirname(__DIR__) . '/storage/drafts',
            'cors_origins' => ['https://mir-auto-china.ru', 'https://www.mir-auto-china.ru'],
        ],
    ];
}

$allowedUsersFile = null;
foreach ([$privateDir . '/config/allowed-user-ids.php', $privateDir . '/allowed-user-ids.php'] as $candidate) {
    if (is_file($candidate)) {
        $allowedUsersFile = $candidate;
        break;
    }
}
$serverAllowedUsers = $allowedUsersFile !== null ? require $allowedUsersFile : [];

if (!is_array($serverAllowedUsers)) {
    throw new RuntimeException('Список разрешённых пользователей должен возвращать массив.');
}

$config['telegram']['allowed_user_ids'] = array_values(array_unique(array_merge(
    array_map('intval', $config['telegram']['allowed_user_ids'] ?? []),
    array_map('intval', $serverAllowedUsers)
)));

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/TelegramClient.php';
require_once __DIR__ . '/TelegramCarPostParser.php';
require_once __DIR__ . '/Bot.php';

return $config;

