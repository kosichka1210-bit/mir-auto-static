<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/private/src/bootstrap.php';
$webhookUrl = $argv[1] ?? '';

if (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || !str_starts_with($webhookUrl, 'https://')) {
    fwrite(STDERR, "Использование: php tools/set-webhook.php https://домен.ru/bot/webhook.php\n");
    exit(1);
}

$telegram = new TelegramClient((string) $config['telegram']['token']);
$result = $telegram->setWebhook($webhookUrl, (string) $config['telegram']['webhook_secret']);
$telegram->setCommands();

fwrite(STDOUT, ($result['description'] ?? 'Webhook установлен.') . " Команды меню настроены." . PHP_EOL);
