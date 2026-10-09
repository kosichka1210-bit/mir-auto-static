<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/src/TelegramCarPostParser.php';

$post = "Haval H6 Champion Edition\n2022.06\n1.5T\n150 л.с.\n2WD — передний привод\nРобот\nПробег: 37 000 км\nКомплектация: Champion Edition\nСтоимость автомобиля: 1.425.000 ₽\nГород: Владивосток";
$parsed = TelegramCarPostParser::parse($post);
$expected = [
    'brand' => 'Haval', 'model' => 'H6', 'year' => 2022, 'year_detail' => '2022',
    'engine' => '1.5T', 'power' => '150 л.с.', 'drivetrain' => '2WD — передний привод',
    'transmission' => 'Робот', 'mileage_km' => 37000, 'price_rub' => 1425000,
    'city' => 'Владивосток',
];
foreach ($expected as $field => $value) {
    if (($parsed[$field] ?? null) !== $value) {
        fwrite(STDERR, "FAIL {$field}: expected " . var_export($value, true) . ', got ' . var_export($parsed[$field] ?? null, true) . PHP_EOL);
        exit(1);
    }
}
if (($parsed['trim_name'] ?? '') !== 'Champion Edition') {
    fwrite(STDERR, 'FAIL trim_name: ' . var_export($parsed['trim_name'] ?? null, true) . PHP_EOL);
    exit(1);
}
$messyPost = "☄️ 😉😉😉✅\n\n🚙GAC Trumpchi GS4 (270T Smart Technology)\n\n⚙️\n- 2022.10 год\n- 1.5T (169 л.с.)\n- 2WD передний привод\n- Автомат\n- пробег 33.000 км.\n\n➡️ Хорошая комплектация. Ассистенты, кожаный салон, панорама, повторители в зеркалах, круиз, климат, сенсорная мультимедия, багажник с кнопки, обзор 360, выбор режимов движения, мультируль, управление светом.\n\n✅ Мы — Китайская экспортная компания с прямой продажей без посредников.\nСопровождение сделки под ключ!\n\nСтоимость автомобиля, под ключ в г. Владивостоке:\n💸1.380.000 по актуальному курсу\n\n«Мир Авто» — Ваш путь к идеальному автомобилю!";
$cleanDescription = TelegramCarPostParser::parse($messyPost);
if (($cleanDescription['year_detail'] ?? null) !== '2022'
    || !str_starts_with((string) ($cleanDescription['description'] ?? ''), 'Ассистенты, кожаный салон')
    || preg_match('/^Автомобиль из опубликованного предложения MIR AUTO|^Хорошая комплектация/ui', (string) ($cleanDescription['description'] ?? '')) === 1
    || preg_match('/[\x{1F000}-\x{1FAFF}]|2022\.10|пробег|Стоимость автомобиля|Китайская экспортная компания/ui', (string) ($cleanDescription['description'] ?? '')) === 1) {
    fwrite(STDERR, 'FAIL clean description/month normalization: ' . json_encode([
        'year_detail' => $cleanDescription['year_detail'] ?? null,
        'description' => $cleanDescription['description'] ?? null,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
$withoutDescription = TelegramCarPostParser::parse("Haval H6\n2022.06\nЦена: 1.425.000\nГод: 2022\nПробег: 37 000 км");
if (isset($withoutDescription['description']) && $withoutDescription['description'] !== '') {
    fwrite(STDERR, 'FAIL empty factual copy must not get generated description: ' . (string) $withoutDescription['description'] . PHP_EOL);
    exit(1);
}
$multilinePrice = TelegramCarPostParser::parse(
    "GAC Trumpchi GS8\n2022.10\nСтоимость автомобиля, под ключ в г. Владивостоке:\n💵 1.380.000 💵 по актуальному курсу"
);
if (($multilinePrice['price_rub'] ?? null) !== 1380000 || ($multilinePrice['city'] ?? null) !== 'Владивосток') {
    fwrite(STDERR, 'FAIL multiline price/city: ' . json_encode([
        'price' => $multilinePrice['price_rub'] ?? null,
        'city' => $multilinePrice['city'] ?? null,
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
foreach (['1.425.000', '1 425 000', '1425000'] as $price) {
    $variant = TelegramCarPostParser::parse("Haval H6\n2022.06\nЦена: {$price}");
    if (($variant['price_rub'] ?? null) !== 1425000) {
        fwrite(STDERR, "FAIL price variant {$price}" . PHP_EOL);
        exit(1);
    }
}
$aliases = TelegramCarPostParser::parse("Haval H6\nГод: 2022.06\nПривод: 4WD\nКоробка: CVT\nПробег: 37 000 км\nЦена: 1 425 000");
if (($aliases['drivetrain'] ?? null) !== '4WD' || ($aliases['transmission'] ?? null) !== 'CVT') {
    fwrite(STDERR, 'FAIL 4WD/CVT aliases' . PHP_EOL);
    exit(1);
}
$front = TelegramCarPostParser::parse("Haval H6\nГод: 2022.06\nПередний привод\nРобот\nЦена: 1.425.000");
if (empty($front['drivetrain']) || empty($front['transmission'])) {
    fwrite(STDERR, 'FAIL front-drive/robot aliases' . PHP_EOL);
    exit(1);
}
$request = TelegramCarPostParser::parse("Марка: Haval\nМодель: H6\nГод: 2022.06\nЦена: по запросу");
if (empty($request['price_on_request']) || ($request['price_rub'] ?? null) !== null) {
    fwrite(STDERR, 'FAIL price-on-request marker' . PHP_EOL);
    exit(1);
}
$missing = TelegramCarPostParser::missing($request);
if ($missing !== ['хотя бы одна фотография']) {
    fwrite(STDERR, 'FAIL required fields: ' . json_encode($missing, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
echo "Parser tests passed: Haval fields, month-year, powertrain aliases, three price formats and optionality." . PHP_EOL;
