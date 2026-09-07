<?php

declare(strict_types=1);

try {
    $config = require dirname(__DIR__) . '/private/src/bootstrap.php';
    new Database($config['database']);

    $requiredExtensions = ['curl', 'json', 'pdo_mysql', 'mbstring'];
    $missing = array_values(array_filter(
        $requiredExtensions,
        static fn (string $extension): bool => !extension_loaded($extension)
    ));

    if ($missing !== []) {
        throw new RuntimeException('Не хватает PHP-расширений: ' . implode(', ', $missing));
    }

    foreach (['media_dir', 'drafts_dir'] as $key) {
        $directory = (string) $config['app'][$key];
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Не удалось создать папку {$directory}");
        }
        if (!is_writable($directory)) {
            throw new RuntimeException("Нет прав на запись в {$directory}");
        }
    }

    fwrite(STDOUT, "OK: PHP, MySQL и папки готовы.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

