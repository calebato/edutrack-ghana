<?php
require_once __DIR__ . '/../auth/auth.php';
requireStudent();

$studentId = $_SESSION['user_id'];
$student = getCurrentUser();
$classLevel = (string)($student['class_level'] ?? '');
$schoolId = (int)($student['school_id'] ?? 0);

// Top students by points within the learner's own class and school.
$topStudents = dbRows(
    "SELECT s.id, s.full_name, s.class_level, s.total_points, s.current_streak,
            (SELECT COUNT(*) FROM student_badges sb WHERE sb.student_id = s.id) as badge_count,
            (SELECT AVG(qa.score) FROM quiz_attempts qa WHERE qa.student_id = s.id) as avg_score
     FROM students s
     WHERE s.is_active = 1 AND s.class_level = ? AND s.school_id = ?
     ORDER BY s.total_points DESC, s.full_name ASC, s.id ASC
     LIMIT 20",
    [$classLevel, $schoolId]
);

// My rank within the same class and school, even when outside the visible top 20.
$myRank = (int)dbValue(
    "SELECT COUNT(*) + 1
     FROM students s
     WHERE s.is_active = 1
       AND s.class_level = ?
       AND s.school_id = ?
       AND (
           s.total_points > ?
           OR (s.total_points = ? AND s.full_name < ?)
           OR (s.total_points = ? AND s.full_name = ? AND s.id < ?)
       )",
    [
        $classLevel,
        $schoolId,
        (int)($student['total_points'] ?? 0),
        (int)($student['total_points'] ?? 0),
        (string)($student['full_name'] ?? ''),
        (int)($student['total_points'] ?? 0),
        (string)($student['full_name'] ?? ''),
        $studentId,
    ]
);

$pageTitle = 'Leaderboard';
$activeNav = 'leaderboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card edu-card mb-4">
    <div class="card-body text-center py-4" style="background:linear-gradient(135deg,#4F46E5,#7C3AED);border-radius:12px;color:white">
        <h4>&#127942; <?= htmlspecialchars($classLevel) ?> Leaderboard</h4>
        <p class="mb-0 opacity-75">Top students in your class by total points earned</p>
        <?php if ($myRank): ?>
            <div class="mt-3">
                <span class="badge bg-white text-primary fs-6">Your Rank: #<?= $myRank ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card edu-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table edu-table mb-0">
                <thead>
                    <tr>
                        <th width="60">Rank</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Points</th>
                        <th>Streak</th>
                        <th>Avg Score</th>
                        <th>Badges</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$topStudents): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No leaderboard entries for your class yet.</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($topStudents as $i => $s): ?>
                    <tr class="<?= $s['id'] == $studentId ? 'table-primary fw-bold' : '' ?>">
                        <td>
                            <?php if ($i === 0): ?>&#129351;
                            <?php elseif ($i === 1): ?>&#129352;
                            <?php elseif ($i === 2): ?>&#129353;
                            <?php else: ?>#<?= $i + 1 ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="user-avatar-sm"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
                                <?= htmlspecialchars($s['full_name']) ?>
                                <?php if ($s['id'] == $studentId): ?>
                                    <span class="badge bg-primary">You</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($s['class_level']) ?></td>
                        <td><strong class="text-primary">&#11088; <?= number_format((int)$s['total_points']) ?></strong></td>
                        <td>&#128293; <?= (int)$s['current_streak'] ?></td>
                        <td>
                            <?php if ($s['avg_score']): ?>
                                <span class="score-badge <?= $s['avg_score'] >= 60 ? 'score-pass' : 'score-fail' ?>">
                                    <?= round($s['avg_score']) ?>%
                                </span>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td>&#127941; <?= (int)$s['badge_count'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
