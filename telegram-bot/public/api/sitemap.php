<?php

declare(strict_types=1);

if (($_SERVER['HTTP_HOST'] ?? '') !== 'mir-auto-china.ru') {
    http_response_code(404);
    exit;
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=300');

$baseUrl = 'https://mir-auto-china.ru';
$urls = [$baseUrl . '/', $baseUrl . '/avtomobili/'];
$publicRoot = dirname(__DIR__);
foreach (glob($publicRoot . '/avtomobili/*/index.html') ?: [] as $page) {
    $slug = basename(dirname($page));
    if ($slug !== 'avtomobil' && preg_match('/^[a-z0-9-]{1,150}$/', $slug) === 1) {
        $urls[] = $baseUrl . '/avtomobili/' . rawurlencode($slug) . '/';
    }
}

try {
    $config = require dirname(__DIR__, 2) . '/private/src/bootstrap.php';
    $database = new Database($config['database']);
    $statement = $database->pdo()->query('SELECT slug FROM cars WHERE published_at IS NOT NULL');
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $slug) {
        $slug = (string) $slug;
        if (preg_match('/^[a-z0-9-]{1,150}$/', $slug) === 1) {
            $urls[] = $baseUrl . '/avtomobili/' . rawurlencode($slug) . '/';
        }
    }
} catch (Throwable $exception) {
    error_log('MIR_AUTO sitemap_warning ' . json_encode([
        'reason' => $exception instanceof PDOException ? 'mysql_unavailable_static_urls_only' : 'dynamic_urls_unavailable',
        'exception' => get_class($exception),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

$urls = array_values(array_unique($urls));
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach ($urls as $url) {
    echo '<url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>';
}
echo '</urlset>';
