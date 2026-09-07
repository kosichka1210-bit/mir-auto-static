<?php

declare(strict_types=1);

return [
    'telegram' => [
        // Получите у @BotFather и храните только на Timeweb.
        'token' => 'PASTE_TOKEN_ON_TIMEWEB_ONLY',
        // Случайная длинная строка для проверки настоящих запросов Telegram.
        'webhook_secret' => 'CHANGE_TO_A_LONG_RANDOM_SECRET',
        // Числовые Telegram ID Владимира, Олега и при необходимости владельца.
        'allowed_user_ids' => [111111111, 222222222],
    ],
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=TIMEWEB_DB_NAME;charset=utf8mb4',
        'user' => 'TIMEWEB_DB_USER',
        'password' => 'TIMEWEB_DB_PASSWORD',
    ],
    'app' => [
        // Без завершающего слеша, например https://mir-auto.ru
        'base_url' => 'https://YOUR-DOMAIN.RU',
        // При размещении по инструкции папка public копируется в public_html.
        'media_dir' => dirname(__DIR__, 2) . '/public_html/media/cars',
        'drafts_dir' => dirname(__DIR__) . '/storage/drafts',
        // Разрешённый адрес витрины. До покупки домена можно оставить GitHub Pages.
        'cors_origins' => [
            'https://kosichka1210-bit.github.io',
            'https://YOUR-DOMAIN.RU',
        ],
    ],
];
