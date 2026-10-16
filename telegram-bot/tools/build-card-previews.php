<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/private/src/bootstrap.php';
require_once dirname(__DIR__) . '/private/src/CardPreview.php';
if (!function_exists('imagewebp')) throw new RuntimeException('GD WebP required');
$db = new Database($config['database']);
$paths = $db->pdo()->query('SELECT i.path FROM car_images i JOIN cars c ON c.id=i.car_id WHERE c.published_at IS NOT NULL AND i.sort_order=0')->fetchAll(PDO::FETCH_COLUMN);
$count=0;
foreach ($paths as $path) {
    if (!preg_match('~^/media/cars/[a-z0-9-]+/[^/]+\.jpg$~', $path)) continue;
    $source = dirname(__DIR__) . '/public_html' . $path;
    CardPreview::create($source);
    if (!is_file($source.'.480.webp') || !is_file($source.'.800.webp')) throw new RuntimeException('Preview missing');
    $count++;
}
echo 'Card previews verified: '.$count.PHP_EOL;
