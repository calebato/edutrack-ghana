<?php
require_once __DIR__ . '/../auth/auth.php';

requireTeacher();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$teacher = getCurrentUser();
if (!$teacher) {
    http_response_code(401);
    echo json_encode(['success' => false, 'activities' => []]);
    exit;
}

$activities = dbRows(
    "SELECT al.id, al.action, al.details, al.created_at,
            UNIX_TIMESTAMP(al.created_at) AS created_at_unix, s.full_name
     FROM activity_logs al
     JOIN students s ON al.user_id = s.id
     WHERE al.user_type = 'student' AND s.school_id = ?
     ORDER BY al.created_at DESC
     LIMIT 10",
    [(int)$teacher['school_id']]
);

echo json_encode([
    'success' => true,
    'activities' => $activities,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
