<?php
declare(strict_types=1);
require __DIR__ . '/../private/src/ContactDeliveryQueue.php';
class TelegramClient { public static function logPerformance(string $name,array $data): void {} }
$dir=sys_get_temp_dir().'/mir-contact-test-'.bin2hex(random_bytes(8));
try {
    ContactDeliveryQueue::enqueue($dir,[1,2,3],'technical fixture');
    $received=[];
    $send=static function(int $id,string $text)use(&$received):void {if($id===2)throw new RuntimeException('temporary');$received[]=$id;};
    ContactDeliveryQueue::processOne($dir,$send);
    ContactDeliveryQueue::processOne($dir,$send);
    $path=glob($dir.'/*.json')[0];$job=json_decode(file_get_contents($path),true);
    if($job['pending']!==[3,2]||$job['delivered']!==[1])throw new RuntimeException('Failure discarded a recipient');
    $job['retry_at']=0;file_put_contents($path,json_encode($job));
    ContactDeliveryQueue::processOne($dir,$send);
    ContactDeliveryQueue::processOne($dir,static function(int $id,string $text)use(&$received):void{$received[]=$id;});
    if($received!==[1,3,2]||glob($dir.'/*.json')!==[])throw new RuntimeException('Retry duplicated or lost delivery');
    echo "Contact queue test passed: failure retained, other staff delivered, successes not repeated.\n";
}finally{foreach(glob($dir.'/*')?:[]as$file)unlink($file);if(is_dir($dir))rmdir($dir);}
