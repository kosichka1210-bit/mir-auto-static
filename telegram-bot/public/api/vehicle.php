<?php

declare(strict_types=1);

if (($_SERVER['HTTP_HOST'] ?? '') !== 'mir-auto-china.ru') {
    http_response_code(404);
    exit;
}

$slug = (string) ($_GET['slug'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,150}$/', $slug)) {
    http_response_code(404);
    exit;
}

$publicRoot = dirname(__DIR__);
$staticPage = $publicRoot . '/avtomobili/' . $slug . '/index.html';
if (is_file($staticPage)) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    readfile($staticPage);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60, stale-while-revalidate=60');

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$notFound = static function () use ($escape): never {
    http_response_code(404);
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow"><title>Автомобиль не найден — MIR AUTO</title><link rel="canonical" href="https://mir-auto-china.ru/avtomobili/"><link rel="stylesheet" href="/style.css?v=21"></head><body><main class="vehicle shell"><h1>Автомобиль не найден</h1><p>Возможно, предложение уже снято с публикации.</p><a class="button" href="/avtomobili/">Вернуться в каталог</a></main></body></html>';
    exit;
};

try {
    $config = require dirname(__DIR__, 2) . '/private/src/bootstrap.php';
    $database = new Database($config['database']);
    $statement = $database->pdo()->prepare(
        'SELECT id, slug, brand, model, title, year, year_detail, mileage_km, engine, power, transmission,
                drivetrain, trim_name, price_rub, price_location, city, status, description
         FROM cars WHERE slug = :slug AND published_at IS NOT NULL LIMIT 1'
    );
    $statement->execute(['slug' => $slug]);
    $car = $statement->fetch();
    if (!$car) {
        $notFound();
    }

    $imageStatement = $database->pdo()->prepare(
        'SELECT path FROM car_images WHERE car_id = :car_id ORDER BY sort_order ASC, id ASC'
    );
    $imageStatement->execute(['car_id' => $car['id']]);
    $baseUrl = rtrim((string) ($config['app']['base_url'] ?? 'https://mir-auto-china.ru'), '/');
    $images = array_map(
        static fn (array $row): string => $baseUrl . '/' . ltrim((string) $row['path'], '/'),
        $imageStatement->fetchAll()
    );
} catch (Throwable $exception) {
    error_log('MIR_AUTO vehicle_page_error ' . json_encode([
        'reason' => $exception instanceof PDOException ? 'mysql_error' : 'render_error',
        'exception' => get_class($exception),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    http_response_code(503);
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex,follow"><title>Каталог временно недоступен — MIR AUTO</title></head><body><main><h1>Каталог временно недоступен</h1><a href="/avtomobili/">Вернуться в каталог</a></main></body></html>';
    exit;
}

$title = trim((string) ($car['title'] ?: $car['brand'] . ' ' . $car['model']));
$canonical = $baseUrl . '/avtomobili/' . rawurlencode($slug) . '/';
$description = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($car['description'] ?? ''))) ?? '');
if ($description === '') {
    $description = $title . ' из опубликованного предложения MIR AUTO. Уточняйте актуальность, наличие и условия заказа у представителей.';
}
$description = mb_substr($description, 0, 260);
$image = $images[0] ?? ($baseUrl . '/og.png');
$price = $car['price_rub'] !== null ? (int) $car['price_rub'] : null;
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'Car',
    'name' => $title,
    'url' => $canonical,
    'description' => $description,
    'image' => $images,
    'brand' => ['@type' => 'Brand', 'name' => (string) $car['brand']],
    'model' => (string) $car['model'],
    'vehicleModelDate' => (string) $car['year'],
];
if ($car['mileage_km'] !== null) {
    $schema['mileageFromOdometer'] = [
        '@type' => 'QuantitativeValue',
        'value' => (int) $car['mileage_km'],
        'unitCode' => 'KMT',
    ];
}
if ($price !== null && $price > 0 && (string) $car['status'] !== 'Продано') {
    $availability = match ((string) $car['status']) {
        'В наличии' => 'https://schema.org/InStock',
        'Под заказ' => 'https://schema.org/PreOrder',
        default => null,
    };
    if ($availability !== null) {
        $schema['offers'] = [
            '@type' => 'Offer',
            'url' => $canonical,
            'price' => $price,
            'priceCurrency' => 'RUB',
            'availability' => $availability,
        ];
    }
}
$jsonLd = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$specs = [
    'Год' => (string) ($car['year_detail'] ?: $car['year']),
    'Пробег' => $car['mileage_km'] !== null ? number_format((int) $car['mileage_km'], 0, ',', ' ') . ' км' : '',
    'Двигатель' => trim(implode(' · ', array_filter([(string) ($car['engine'] ?? ''), (string) ($car['power'] ?? '')]))),
    'Коробка передач' => (string) ($car['transmission'] ?? ''),
    'Привод' => (string) ($car['drivetrain'] ?? ''),
    'Комплектация' => (string) ($car['trim_name'] ?? ''),
    'Город' => (string) ($car['city'] ?? ''),
];
$specHtml = '';
foreach ($specs as $label => $value) {
    if ($value !== '') {
        $specHtml .= '<div><dt>' . $escape($label) . '</dt><dd>' . $escape($value) . '</dd></div>';
    }
}
$gallery = '';
if ($images === []) {
    $gallery = '<section class="vehicle-gallery"><div class="vehicle-gallery-stage is-missing"><span>Фотографии скоро появятся</span></div></section>';
} else {
    $gallery .= '<section class="vehicle-gallery" aria-label="Фотографии ' . $escape($title) . '"><div class="vehicle-gallery-stage"><img class="vehicle-gallery-main" fetchpriority="high" src="' . $escape($image) . '" alt="' . $escape($title) . ' — автомобиль из Китая, MIR AUTO">';
    if (count($images) > 1) {
        $gallery .= '<button class="gallery-arrow gallery-prev" type="button" aria-label="Предыдущее фото">←</button><button class="gallery-arrow gallery-next" type="button" aria-label="Следующее фото">→</button>';
    }
    $gallery .= '</div>';
    if (count($images) > 1) {
        $gallery .= '<div class="gallery-thumbs">';
        foreach ($images as $index => $photo) {
            $gallery .= '<button class="gallery-thumb' . ($index === 0 ? ' is-active' : '') . '" type="button" data-index="' . $index . '" aria-label="Открыть фото ' . ($index + 1) . '"><img loading="lazy" decoding="async" src="' . $escape($photo) . '" alt="' . $escape($title) . ' — фото ' . ($index + 1) . '"></button>';
        }
        $gallery .= '</div>';
    }
    $gallery .= '</section>';
}

?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $escape($title) ?> из Китая — MIR AUTO</title>
<meta name="description" content="<?= $escape($description) ?>">
<link rel="canonical" href="<?= $escape($canonical) ?>">
<meta property="og:type" content="website"><meta property="og:site_name" content="MIR AUTO"><meta property="og:title" content="<?= $escape($title) ?> — MIR AUTO"><meta property="og:description" content="<?= $escape($description) ?>"><meta property="og:url" content="<?= $escape($canonical) ?>"><meta property="og:image" content="<?= $escape($image) ?>"><meta property="og:image:alt" content="<?= $escape($title) ?> — фотографии автомобиля"><meta property="og:locale" content="ru_RU"><meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.svg"><link rel="stylesheet" href="/style.css?v=21"><link rel="stylesheet" href="/layout-fixes.css?v=24"><link rel="stylesheet" href="/vehicle-gallery.css?v=2">
<script type="application/ld+json"><?= $jsonLd ?></script>
</head>
<body>
<header class="site-header catalog-header"><a class="brand-mark text-brand" href="/" aria-label="MIR AUTO — на главную"><span class="brand-avatar"><img src="/images/mir-auto-logo.jpg" alt="Логотип MIR AUTO"></span><span class="brand-words"><b>MIR AUTO</b><small>Автомобили из Китая</small></span></a><nav class="main-nav" aria-label="Основная навигация"><a href="/">Главная</a><a href="/avtomobili/">Автомобили</a><a href="/#process">Как мы работаем</a><a href="/#form">Контакты</a></nav><a class="button button-small desktop-action" href="/#form">Подобрать автомобиль</a><details class="mobile-menu"><summary aria-label="Открыть меню"><i></i><i></i></summary><nav><a href="/avtomobili/">Автомобили</a><a href="/#process">Как мы работаем</a><a href="/#form">Контакты</a><a class="button" href="/#form">Подобрать автомобиль</a></nav></details></header>
<main class="vehicle shell"><a class="back" href="/avtomobili/">← Все автомобили</a><div class="vehicle-grid"><?= $gallery ?><section class="vehicle-details"><p class="status"><?= $escape((string) $car['status']) ?></p><h1><?= $escape($title) ?></h1><?php if ($price !== null && $price > 0): ?><p class="vehicle-price"><?= number_format($price, 0, ',', ' ') ?> ₽</p><?php else: ?><p class="vehicle-price">Цена по запросу</p><?php endif; ?><?php if (!empty($car['price_location'])): ?><p class="vehicle-note">Опубликованная стоимость для <?= $escape((string) $car['price_location']) ?>. Уточняйте актуальность.</p><?php endif; ?><?php if ($specHtml !== ''): ?><dl class="spec-list"><?= $specHtml ?></dl><?php endif; ?><?php if ($description !== ''): ?><p class="vehicle-text"><?= $escape($description) ?></p><?php endif; ?><div class="vehicle-actions"><a class="button" href="/#form">Обсудить автомобиль</a><a class="button button-outline" href="https://t.me/mirautochina125" target="_blank" rel="noopener">Написать в Telegram</a></div></section></div></main>
<footer class="compact-footer"><div class="shell compact-footer-inner"><a class="footer-logo text-brand" href="/" aria-label="MIR AUTO — на главную"><span class="brand-avatar"><img src="/images/mir-auto-logo.jpg" alt="Логотип MIR AUTO"></span><span class="brand-words"><b>MIR AUTO</b><small>Автомобили из Китая</small></span></a><p class="footer-copy">© 2026 MIR AUTO</p></div></footer>
<?php if (count($images) > 1): ?><script>
(() => { const root=document.querySelector('.vehicle-gallery');const main=root?.querySelector('.vehicle-gallery-main');const thumbs=[...root.querySelectorAll('.gallery-thumb')];if(!main||thumbs.length<2)return;let current=0;const show=(n)=>{current=(n+thumbs.length)%thumbs.length;main.src=thumbs[current].querySelector('img').src;main.alt=<?= json_encode($title, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>+' — фото '+(current+1)+' — MIR AUTO';thumbs.forEach((item,index)=>item.classList.toggle('is-active',index===current));};root.querySelector('.gallery-prev').addEventListener('click',()=>show(current-1));root.querySelector('.gallery-next').addEventListener('click',()=>show(current+1));thumbs.forEach((item,index)=>item.addEventListener('click',()=>show(index)));})();
</script><?php endif; ?>
</body></html>
