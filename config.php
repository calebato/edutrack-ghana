<?php

require_once __DIR__ . '/config/db.php';

// Compatibility connection for legacy admin and maintenance pages. New code
// should use getDB(), dbQuery(), dbRow(), and dbRows() from config/db.php.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset(DB_CHARSET);
} catch (mysqli_sql_exception $exception) {
    error_log('EduTrack legacy database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection unavailable.');
}
