<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('MIR_AUTO_APPLY_MIGRATIONS') !== '1') {
    fwrite(STDERR, "Set MIR_AUTO_APPLY_MIGRATIONS=1 to apply the reviewed additive production migrations.\n");
    exit(2);
}

$root = dirname(__DIR__);
$config = require $root . '/private/src/bootstrap.php';
$sql = file_get_contents($root . '/database/migrate-production.sql');
if (!is_string($sql) || trim($sql) === '') {
    fwrite(STDERR, "Production migration file is missing or empty.\n");
    exit(1);
}
$database = new Database($config['database']);
$statements = array_filter(array_map('trim', explode(';', $sql)), static fn (string $statement): bool => $statement !== '');
foreach ($statements as $statement) $database->pdo()->exec($statement);
echo 'Reviewed additive database migrations applied; statements=' . count($statements) . PHP_EOL;
