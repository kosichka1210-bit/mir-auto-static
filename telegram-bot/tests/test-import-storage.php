<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('MIR_AUTO_DB_IMPORT_TEST') !== '1') {
    fwrite(STDERR, "Set MIR_AUTO_DB_IMPORT_TEST=1 to run this isolated database test.\n");
    exit(2);
}

$config = require __DIR__ . '/../private/src/bootstrap.php';
$database = new Database($config['database']);
$userId = random_int(8_000_000_000_000_000_000, 9_000_000_000_000_000_000);

try {
    $first = TelegramCarPostParser::parse("Haval H6 Champion Edition\n2022.06\nЦена: 1.425.000");
    $one = $database->appendForwardedPost($userId, $userId, $first, 10001, 'test-album', 'test-file-1');
    $two = $database->appendForwardedPost($userId, $userId, [], 10002, 'test-album', 'test-file-2');
    $duplicate = $database->appendForwardedPost($userId, $userId, [], 10002, 'test-album', 'test-file-2');
    $session = $database->getSession($userId);
    $draft = $session['draft'] ?? [];
    if (($one['status'] ?? '') !== 'saved' || ($two['status'] ?? '') !== 'saved'
        || ($duplicate['status'] ?? '') !== 'duplicate'
        || count($draft['photo_file_ids'] ?? []) !== 2
        || ($draft['price_rub'] ?? null) !== 1425000) {
        throw new RuntimeException('Album append, duplicate suppression or parsed price check failed.');
    }
    $claimed = $database->claimSessionStep($userId, ['import_photos'], 'import_processing');
    $claimedAgain = $database->claimSessionStep($userId, ['import_photos'], 'import_processing');
    if ($claimed === null || $claimedAgain !== null) {
        throw new RuntimeException('Preview claim was not idempotent.');
    }
    echo "Production DB import test passed: 2 album items persisted, duplicate suppressed, preview claimed once; no car row created." . PHP_EOL;
} finally {
    $database->deleteSession($userId);
}
