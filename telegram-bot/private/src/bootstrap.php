<?php

declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config/config.php';

if (!is_file($configFile)) {
    throw new RuntimeException('Не найден private/config/config.php');
}

$config = require $configFile;

if (!is_array($config)) {
    throw new RuntimeException('Конфигурация должна возвращать массив.');
}

$allowedUsersFile = dirname(__DIR__) . '/config/allowed-user-ids.php';
$serverAllowedUsers = is_file($allowedUsersFile) ? require $allowedUsersFile : [];

if (!is_array($serverAllowedUsers)) {
    throw new RuntimeException('Список разрешённых пользователей должен возвращать массив.');
}

$config['telegram']['allowed_user_ids'] = array_values(array_unique(array_merge(
    array_map('intval', $config['telegram']['allowed_user_ids'] ?? []),
    array_map('intval', $serverAllowedUsers)
)));

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/TelegramClient.php';
require_once __DIR__ . '/Bot.php';

return $config;

