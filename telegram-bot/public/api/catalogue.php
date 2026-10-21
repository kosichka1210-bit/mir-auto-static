<?php
declare(strict_types=1);

// Serve the same live database-backed cards to people and search crawlers.
// No crawler-specific content and no dependency on a second HTTP/API request.
$template = file_get_contents(dirname(__DIR__) . '/avtomobili/index.html');
$savedQuery = $_GET;
$_GET = [];
ob_start();
require __DIR__ . '/cars.php';
$payload = json_decode((string) ob_get_clean(), true);
$_GET = $savedQuery;
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$number = static fn($value): string => number_format((int) $value, 0, ',', ' ');
$cards = '';
if (!($payload['ok'] ?? false)) {
    http_response_code(503);
    header('Retry-After: 60');
    $cards = '<p class="catalog-state">Не удалось загрузить предложения из-за ошибки соединения. Попробуйте обновить страницу.</p>';
} else {
    foreach ($payload['cars'] as $index => $car) {
        $title = $escape($car['title']);
        $url = '/avtomobili/' . rawurlencode($car['slug']) . '/';
        $price = $car['price_rub'] ? $number($car['price_rub']).' ₽' : 'Цена по запросу';
        $previews = $car['card_previews'];
        $image = $previews['480'] ?? ($car['images'][0] ?? '');
        $responsive = isset($previews['480'], $previews['800']) ? ' srcset="'.$escape($previews['480']).' 480w, '.$escape($previews['800']).' 800w" sizes="(max-width:600px) calc(100vw - 40px), (max-width:1000px) 46vw, 440px"' : '';
        $photo = $image ? '<img src="'.$escape($image).'" alt="'.$title.' из Китая — MIR AUTO" loading="'.($index === 0 ? 'eager' : 'lazy').'" decoding="async"'.($index === 0 ? ' fetchpriority="high"' : '').$responsive.'>' : '<span class="car-photo-placeholder">Фото скоро</span>';
        $cards .= '<article class="car-card live-car-card"><a class="car-photo" href="'.$url.'" aria-label="Подробнее о '.$title.'">'.$photo.'</a><div class="car-copy"><a class="car-summary-link" href="'.$url.'"><div class="car-top"><p class="status">'.$escape($car['status']).'</p><p class="price">'.$price.'</p></div><h3>'.$title.'</h3></a><div class="catalog-card-actions"><a class="button" href="'.$url.'">Подробнее</a><a class="catalog-discuss button button-outline" href="/#contacts">Обсудить автомобиль</a></div></div></article>';
    }
    if ($cards === '') $cards = '<p class="catalog-state">Сейчас нет опубликованных предложений. Обратитесь к представителям для подбора.</p>';
}
// The template grid contains only the loading placeholder, never hard-coded inventory.
$template = preg_replace_callback('~(<div class="car-grid catalog-grid"[^>]*>).*?(</div>)~s', static fn($m): string => $m[1].$cards.$m[2], $template, 1, $replaced);
if ($replaced !== 1) { http_response_code(503); exit('Каталог временно недоступен'); }
// Server cards are already current. Avoid clearing them and re-fetching the same data.
$template = preg_replace('~<script src="\.\./live-catalog\.js\?[^"<>]+" defer></script>~', '', $template);
echo $template;
