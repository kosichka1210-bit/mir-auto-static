<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$config=require __DIR__.'/../private/src/bootstrap.php';
$db=new Database($config['database']);
$db->pdo()->exec('CREATE TABLE IF NOT EXISTS bot_publication_receipts (publication_key CHAR(32) NOT NULL PRIMARY KEY,car_id BIGINT UNSIGNED NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
echo "Additive publication receipts table ready; existing cars untouched.\n";
