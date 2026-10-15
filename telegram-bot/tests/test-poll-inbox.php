<?php
declare(strict_types=1);
require __DIR__.'/../private/src/TelegramUpdateInbox.php';
$dir=sys_get_temp_dir().'/mir-inbox-'.bin2hex(random_bytes(8));
mkdir($dir,0700);mkdir($dir.'/done',0700);
try {
    $updates=[['update_id'=>101,'message'=>['text'=>'fixture']],['update_id'=>102,'callback_query'=>['id'=>'fixture']]];
    $next=TelegramUpdateInbox::persist($dir,$updates,0);
    if($next!==103||trim(file_get_contents($dir.'/poll-offset'))!=='103'||count(glob($dir.'/*.json'))!==2)throw new RuntimeException('Offset advanced without durable updates');
    unlink($dir.'/00000000000000000101.json');file_put_contents($dir.'/done/00000000000000000101.done','done');
    TelegramUpdateInbox::persist($dir,$updates,103);
    if(count(glob($dir.'/*.json'))!==1)throw new RuntimeException('Delivered update was requeued');
    try{TelegramUpdateInbox::persist($dir,[['update_id'=>'invalid']],103);throw new LogicException('Invalid ID accepted');}catch(RuntimeException $e){}
    if(trim(file_get_contents($dir.'/poll-offset'))!=='103')throw new RuntimeException('Invalid update changed checkpoint');
    echo "Poll inbox passed: durable checkpoint, replay dedup, invalid update not acknowledged.\n";
}finally{foreach(glob($dir.'/done/*')?:[]as$f)unlink($f);rmdir($dir.'/done');foreach(glob($dir.'/*')?:[]as$f)unlink($f);rmdir($dir);}
