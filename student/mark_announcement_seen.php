<?php
require_once __DIR__ . '/../auth/auth.php';
requireStudent();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}

$studentId = (int)$_SESSION['user_id'];
$announcementId = (int)($_POST['announcement_id'] ?? 0);
$student = getCurrentUser();

if (!$announcementId || !$student) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$eligible = dbRow(
    "SELECT id, COALESCE(edited_at, created_at) AS announcement_version
     FROM announcements
     WHERE id=? AND is_active=1 AND is_pinned=1
       AND (target='all' OR target=?)
       AND (scheduled_at IS NULL OR scheduled_at<=NOW())
       AND (expires_at IS NULL OR expires_at>=CURDATE())",
    [$announcementId, $student['class_level']]
);

if (!$eligible) {
    http_response_code(404);
    echo json_encode(['success' => false]);
    exit;
}

dbQuery(
    "INSERT INTO announcement_views (student_id,announcement_id,seen_version)
     VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE seen_version=VALUES(seen_version),seen_at=CURRENT_TIMESTAMP",
    [$studentId, $announcementId, $eligible['announcement_version']]
);

echo json_encode(['success' => true]);
