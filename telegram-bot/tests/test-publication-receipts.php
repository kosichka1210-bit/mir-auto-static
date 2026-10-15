<?php
declare(strict_types=1);
if(getenv('MIR_AUTO_DB_IMPORT_TEST')!=='1')exit(2);
$config=require __DIR__.'/../private/src/bootstrap.php';$db=new Database($config['database']);
$car=$db->pdo()->query('SELECT id,slug FROM cars ORDER BY id LIMIT 1')->fetch();
if(!$car)throw new RuntimeException('No real car for receipt lookup test');
$key=bin2hex(random_bytes(16));$before=(int)$db->pdo()->query('SELECT COUNT(*) FROM cars')->fetchColumn();
try{
 $stmt=$db->pdo()->prepare('INSERT INTO bot_publication_receipts(publication_key,car_id) VALUES(:key,:id)');$stmt->execute(['key'=>$key,'id'=>$car['id']]);
 $result=$db->publishCar(['publication_key'=>$key],0,$config['app']['media_dir']);
 if((int)$result['id']!==(int)$car['id']||$before!==(int)$db->pdo()->query('SELECT COUNT(*) FROM cars')->fetchColumn())throw new RuntimeException('Publication replay duplicated car');
 echo "Publication receipt passed: replay returns existing car, no car created; temporary receipt removed.\n";
}finally{$stmt=$db->pdo()->prepare('DELETE FROM bot_publication_receipts WHERE publication_key=:key');$stmt->execute(['key'=>$key]);}
