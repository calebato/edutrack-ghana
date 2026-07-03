<?php

require_once __DIR__ . '/../config/db.php';

/**
 * Record an administrator-facing system event.
 *
 * The first argument is retained for compatibility with existing callers.
 */
function addLog($connection, string $user, string $action): void {
    dbQuery(
        'INSERT INTO system_logs (user_name, action) VALUES (?, ?)',
        [$user, $action]
    );
}
