<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    fwrite(STDERR, "Usage: php backup-db.php /private/backup/path.sql.gz\n");
    exit(2);
}

$config = require dirname(__DIR__) . '/private/src/bootstrap.php';
$dsn = (string) ($config['database']['dsn'] ?? '');
if (preg_match('/(?:^|;)dbname=([^;]+)/', $dsn, $dbMatch) !== 1) {
    fwrite(STDERR, "Database name is not available in the server configuration.\n");
    exit(1);
}
$databaseName = $dbMatch[1];
$host = 'localhost';
if (preg_match('/(?:^|;)host=([^;]+)/', $dsn, $hostMatch) === 1) $host = $hostMatch[1];
$dumpCommand = trim((string) shell_exec('command -v mariadb-dump || command -v mysqldump 2>/dev/null'));
if ($dumpCommand === '') {
    fwrite(STDERR, "No MariaDB/MySQL dump utility is installed.\n");
    exit(1);
}

$backupPath = $argv[1];
$backupDirectory = dirname($backupPath);
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    fwrite(STDERR, "Cannot create the private backup directory.\n");
    exit(1);
}
@chmod($backupDirectory, 0700);
$optionFile = sys_get_temp_dir() . '/mir-auto-db-' . bin2hex(random_bytes(12)) . '.cnf';
$escapeOption = static function (string $value): string {
    if (str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new RuntimeException('Invalid newline in database credentials.');
    }
    return '"' . addcslashes($value, "\\\"") . '"';
};
$optionContents = "[client]\n"
    . 'user=' . $escapeOption((string) $config['database']['user']) . "\n"
    . 'password=' . $escapeOption((string) $config['database']['password']) . "\n"
    . 'host=' . $escapeOption($host) . "\n";

try {
    if (file_put_contents($optionFile, $optionContents, LOCK_EX) === false) {
        throw new RuntimeException('Cannot create a private temporary database option file.');
    }
    chmod($optionFile, 0600);
    $command = escapeshellarg($dumpCommand)
        . ' --defaults-extra-file=' . escapeshellarg($optionFile)
        . ' --single-transaction --quick --skip-lock-tables --no-tablespaces --skip-triggers --databases '
        . escapeshellarg($databaseName)
        . ' | gzip -c > ' . escapeshellarg($backupPath);
    $output = [];
    $status = 0;
    exec('bash -o pipefail -c ' . escapeshellarg($command) . ' 2>&1', $output, $status);
    if ($status !== 0 || !is_file($backupPath) || filesize($backupPath) < 100) {
        @unlink($backupPath);
        $safeOutput = strtolower(implode(' ', $output));
        $reason = str_contains($safeOutput, 'access denied')
            ? 'The backup utility is missing database dump privileges.'
            : 'The database dump utility failed; no migration was applied.';
        throw new RuntimeException($reason);
    }
    chmod($backupPath, 0600);
    echo 'Private database backup created and gzip-verified; bytes=' . filesize($backupPath) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($optionFile);
}
