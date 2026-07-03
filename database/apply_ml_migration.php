<?php
require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$sql = file_get_contents(__DIR__ . '/migrations/20260620_restore_ml.sql');
if ($sql === false) {
    throw new RuntimeException('Unable to read the ML migration.');
}

getDB()->exec($sql);
echo "ML database migration applied.\n";
