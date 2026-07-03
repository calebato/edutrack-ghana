<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../student/student.php';
require_once __DIR__ . '/../ml/ml.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$action = $_GET['action'] ?? 'health';
if ($action === 'health') {
    $health = mlServiceHealth();
    echo json_encode($health ?? ['status' => 'offline', 'fallback' => 'php']);
    exit;
}

$studentId = isStudent() ? (int)$_SESSION['user_id'] : (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'A valid student is required.']);
    exit;
}

if (isTeacher()) {
    $teacher = getCurrentUser();
    $allowed = dbValue('SELECT COUNT(*) FROM students WHERE id=? AND school_id=?', [$studentId, (int)$teacher['school_id']]);
    if (!(int)$allowed) {
        http_response_code(403);
        echo json_encode(['error' => 'Student access denied.']);
        exit;
    }
} elseif (!isStudent() && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Role not permitted.']);
    exit;
}

if ($action === 'predict') {
    echo json_encode(predictStudentExamPerformance($studentId));
    exit;
}

if ($action === 'recommendations') {
    if (!isStudent() || $studentId !== (int)$_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Recommendations are available to the learner only.']);
        exit;
    }
    echo json_encode(['recommendations' => generateMLRecommendations($studentId)]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown ML action.']);
