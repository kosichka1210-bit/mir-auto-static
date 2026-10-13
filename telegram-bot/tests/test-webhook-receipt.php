<?php
declare(strict_types=1);

// Isolated HTTP test: no production config, Telegram calls, worker or database.
$root = sys_get_temp_dir() . '/mir-auto-hook-' . bin2hex(random_bytes(6));
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException($error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
mkdir($root . '/public/bot', 0700, true);
mkdir($root . '/private/src', 0700, true);
copy(__DIR__ . '/../public/bot/webhook.php', $root . '/public/bot/webhook.php');
file_put_contents($root . '/private/src/bootstrap.php', "<?php return ['telegram'=>['webhook_secret'=>'isolated-test-secret','allowed_user_ids'=>[123456789]]];");
$process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/public'], [0=>['pipe','r'],1=>['file',$root . '/server.log','a'],2=>['file',$root . '/server.log','a']], $pipes);
if (!is_resource($process)) throw new RuntimeException('Test server unavailable');
$send = static function(array $update, string $secret = 'isolated-test-secret') use ($address): array {
    $context = stream_context_create(['http'=>[
        'method'=>'POST', 'header'=>"Content-Type: application/json\r\nX-Telegram-Bot-Api-Secret-Token: {$secret}\r\n",
        'content'=>json_encode($update), 'timeout'=>3, 'ignore_errors'=>true,
    ]]);
    $body = file_get_contents('http://' . $address . '/bot/webhook.php', false, $context);
    return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
};
$remove = static function(string $dir) use (&$remove): void {
    foreach (scandir($dir) as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        if (is_dir($path)) $remove($path); else unlink($path);
    }
    rmdir($dir);
};
try {
    $ready=false;
    for ($attempt=0; $attempt<50; $attempt++) {
        $probe=@stream_socket_client('tcp://' . $address,$errno,$error,0.1);
        if ($probe) { fclose($probe); $ready=true; break; }
        usleep(20_000);
    }
    if (!$ready) throw new RuntimeException('Test server failed to start');
    $message=['message_id'=>1,'chat'=>['id'=>123456789,'type'=>'private'],'from'=>['id'=>123456789],
        'forward_origin'=>['type'=>'channel','date'=>1,'chat'=>['id'=>-100123,'type'=>'channel'],'message_id'=>1],
        'media_group_id'=>'isolated-album','caption'=>'GAC Trumpchi GS4','photo'=>[['file_id'=>'isolated-photo']]];
    $update=['update_id'=>100,'message'=>$message];
    if ($send($update,'wrong-secret') !== ['ok'=>false,'error'=>'forbidden']) throw new RuntimeException('Webhook secret check failed');
    $first=$send($update);
    if (($first['method']??'')!=='sendMessage' || ($first['chat_id']??0)!==123456789
        || !str_contains($first['text']??'','Пост получен')) throw new RuntimeException('Fast forwarded-post receipt missing');
    if ($send($update)!==['ok'=>true]) throw new RuntimeException('Duplicate receipt was sent');
    for ($i=1;$i<8;$i++) {
        $update['update_id']=100+$i; $update['message']['message_id']=1+$i;
        if ($send($update)!==['ok'=>true]) throw new RuntimeException('Album receipt repeated');
    }
    if (count(glob($root.'/private/storage/update-queue/*.json'))!==8) throw new RuntimeException('Album update lost or duplicated');
    $update['update_id']=200; $update['message']['from']['id']=999; $update['message']['chat']['id']=999;
    if ($send($update)!==['ok'=>true]) throw new RuntimeException('Unauthorized user received import receipt');
    echo "Webhook receipt test passed: one fast reply, 8 queued album messages, duplicate suppressed, secret and allowlist enforced.\n";
} finally {
    proc_terminate($process); fclose($pipes[0]); proc_close($process); $remove($root);
}
