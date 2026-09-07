<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $config = require dirname(__DIR__, 2) . '/private/src/bootstrap.php';
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowedOrigins = $config['app']['cors_origins'] ?? [];

    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }

    $database = new Database($config['database']);
    $pdo = $database->pdo();
    $slug = trim((string) ($_GET['slug'] ?? ''));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));

    $sql = 'SELECT id, slug, brand, model, year, mileage_km, engine, transmission,
                   drivetrain, trim_name, price_rub, price_location, status,
                   description, notes, published_at
            FROM cars
            WHERE published_at IS NOT NULL';
    $parameters = [];

    if ($slug !== '') {
        $sql .= ' AND slug = :slug';
        $parameters['slug'] = $slug;
    }

    $sql .= ' ORDER BY published_at DESC, id DESC LIMIT ' . $limit;
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $cars = $statement->fetchAll();

    $imageStatement = $pdo->prepare(
        'SELECT path FROM car_images WHERE car_id = :car_id ORDER BY sort_order ASC, id ASC'
    );
    $baseUrl = rtrim((string) $config['app']['base_url'], '/');

    foreach ($cars as &$car) {
        $imageStatement->execute(['car_id' => $car['id']]);
        $car['images'] = array_map(
            static fn (array $image): string => $baseUrl . $image['path'],
            $imageStatement->fetchAll()
        );
        $car['year'] = (int) $car['year'];
        $car['mileage_km'] = $car['mileage_km'] !== null ? (int) $car['mileage_km'] : null;
        $car['price_rub'] = $car['price_rub'] !== null ? (int) $car['price_rub'] : null;
        $car['id'] = (int) $car['id'];
    }
    unset($car);

    echo json_encode(
        ['ok' => true, 'count' => count($cars), 'cars' => $cars],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    error_log('MIR AUTO cars API: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}
