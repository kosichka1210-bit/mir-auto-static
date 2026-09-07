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

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/TelegramClient.php';
require_once __DIR__ . '/Bot.php';

return $config;

