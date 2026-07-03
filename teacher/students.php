<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/teacher.php';
requireTeacher();

$teacherId = $_SESSION['user_id'];
$teacher   = getCurrentUser();
$assignedClasses = teacherAssignedClasses($teacher);
if ($teacher['subject'] === 'General') {

    $students = getAllStudentsDetailedForSchool((int)$teacher['school_id'], $assignedClasses);

} else {

    $subject = dbRow(
        "SELECT id FROM subjects WHERE name = ?",
        [$teacher['subject']]
    );

    $subjectId = $subject['id'] ?? 0;

    $students = getAllStudentsDetailed($subjectId, (int)$teacher['school_id'], $assignedClasses);
}


// Filter
$filterClass = $_GET['class'] ?? '';
$search      = trim($_GET['search'] ?? '');

if ($filterClass) {
    $students = array_filter($students, fn($s) => $s['class_level'] === $filterClass);
}
if ($search) {
    $students = array_filter($students, fn($s) =>
        stripos($s['full_name'], $search) !== false ||
        stripos($s['email'], $search) !== false
    );
}

$pageTitle = 'Students';
$activeNav = 'students';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Filter Bar -->
<div class="card edu-card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small fw-600 mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-edu form-control-sm"
                       placeholder="Name or email..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-600 mb-1">Class Level</label>
                <select name="class" class="form-select form-control-edu form-control-sm">
                    <option value="">Assigned Classes</option>
                    <?php foreach ($assignedClasses as $classLevel): ?>
                        <option value="<?= htmlspecialchars($classLevel) ?>" <?= $filterClass === $classLevel ? 'selected' : '' ?>><?= htmlspecialchars(str_replace('JHS', 'JHS ', $classLevel)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm btn-edu">Filter</button>
                <a href="students.php" class="btn btn-outline-secondary btn-sm">Clear</a>
            </div>
            <div class="col-auto ms-auto">
                <span class="text-muted small"><?= count($students) ?> student(s)</span>
            </div>
        </form>
    </div>
</div>

<div class="card edu-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table edu-table mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Total Points</th>
                        <th>Avg Score</th>
                        <th>Quizzes</th>
                        <th>Topics Done</th>
                        <th>Streak</th>
                        <th>Last Quiz</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No students found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($students as $s): ?>
                    <?php
                    $isAtRisk = ($s['avg_score'] < 50 && $s['quiz_count'] > 0) || $s['quiz_count'] == 0;
                    $lastLogin = $s['last_login'] ? (time() - strtotime($s['last_login'])) : null;
                    $isActive  = $lastLogin && $lastLogin < 7 * 86400;
                    ?>
                    <tr class="<?= $isAtRisk ? 'table-warning-soft' : '' ?>">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="user-avatar-sm <?= $isAtRisk ? 'bg-warning' : '' ?>">
                                    <?= strtoupper(substr($s['full_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="fw-600 small"><?= htmlspecialchars($s['full_name']) ?></div>
                                    <div class="text-muted" style="font-size:11px"><?= htmlspecialchars($s['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge bg-light text-dark"><?= $s['class_level'] ?></span></td>
                        <td><strong>⭐ <?= number_format($s['total_points']) ?></strong></td>
                        <td>
                            <?php if ($s['avg_score']): ?>
                                <span class="score-badge <?= $s['avg_score'] >= 60 ? 'score-pass' : 'score-fail' ?>">
                                    <?= round($s['avg_score']) ?>%
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">No data</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $s['quiz_count'] ?></td>
                        <td><?= $s['topics_done'] ?></td>
                        <td>🔥 <?= $s['current_streak'] ?></td>
                        <td class="text-muted small">
                            <?= $s['last_quiz_date'] ? date('M d', strtotime($s['last_quiz_date'])) : '–' ?>
                        </td>
                        <td>
                            <?php if ($isAtRisk): ?>
                                <span class="badge bg-warning text-dark">⚠ At Risk</span>
                            <?php elseif ($isActive): ?>
                                <span class="badge bg-success-soft text-success">● Active</span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= BASE_URL ?>/teacher/student_detail.php?id=<?= $s['id'] ?>"
                               class="btn btn-xs btn-outline-primary">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
