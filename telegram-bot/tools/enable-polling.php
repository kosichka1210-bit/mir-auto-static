<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$config=require __DIR__.'/../private/src/bootstrap.php';
$client=new TelegramClient($config['telegram']['token']);
$response=$client->disableWebhookPreservingUpdates();
echo json_encode(['webhook_disabled'=>($response['ok']??false)===true,'pending_updates_preserved'=>true]),PHP_EOL;
