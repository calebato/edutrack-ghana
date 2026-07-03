<?php
/**
 * EduTrack Ghana - Database Configuration
 * config/db.php
 */

date_default_timezone_set('Africa/Accra');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'edutrack_ghana');
define('DB_CHARSET', 'utf8mb4');

// App settings
define('APP_NAME', 'EduTrack Ghana');
define('APP_VERSION', '1.0');
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/edutrack');
}
define('SESSION_LIFETIME', 3600); // 1 hour

// Scoring
define('POINTS_PER_CORRECT', 10);
define('POINTS_QUIZ_COMPLETE', 25);
define('POINTS_PERFECT_SCORE', 50);
define('STREAK_BONUS', 5);

/**
 * Get database connection (PDO)
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $exception) {
            error_log('EduTrack database connection failed: ' . $exception->getMessage());
            http_response_code(500);
            exit('Database connection unavailable.');
        }
    }
    return $pdo;
}

/**
 * Safe query helper
 */
function dbQuery(string $sql, array $params = []): \PDOStatement {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch single row
 */
function dbRow(string $sql, array $params = []): ?array {
    return dbQuery($sql, $params)->fetch() ?: null;
}

/**
 * Fetch all rows
 */
function dbRows(string $sql, array $params = []): array {
    return dbQuery($sql, $params)->fetchAll();
}

function dbValue(string $sql, array $params = []): mixed {
    return dbQuery($sql, $params)->fetchColumn();
}

/**
 * Insert and return last insert ID
 */
function dbInsert(string $sql, array $params = []): int {
    dbQuery($sql, $params);
    return (int)getDB()->lastInsertId();
}
