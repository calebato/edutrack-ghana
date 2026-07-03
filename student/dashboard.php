<?php
/**
 * EduTrack Ghana - Student Dashboard
 * student/dashboard.php
 */

// GHANA TIMEZONE

date_default_timezone_set('Africa/Accra');

require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/student.php';
require_once __DIR__ . '/../ml/ml.php';

requireStudent();

$studentId = $_SESSION['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_learning_goal') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid request token.');
    }
    $targetMastery = max(50, min(95, (int)($_POST['target_mastery'] ?? 70)));
    dbQuery(
        'INSERT INTO student_learning_goals (student_id,target_mastery) VALUES (?,?)
         ON DUPLICATE KEY UPDATE target_mastery=VALUES(target_mastery),updated_at=NOW()',
        [$studentId, $targetMastery]
    );
    dbQuery('DELETE FROM student_recommendations WHERE student_id=?', [$studentId]);
    header('Location: ' . BASE_URL . '/student/dashboard.php#learning-goal');
    exit;
}

$stats = getStudentStats($studentId);
$student = $stats['student'];
$prediction = predictStudentExamPerformance($studentId);
$recommendations = generateMLRecommendations($studentId);
$learningGoal = getStudentLearningGoal($studentId);
$totalTopics = (int)dbValue(
    "SELECT COUNT(*) FROM topics WHERE class_level = ? AND approval_status = 'approved' AND is_active = 1
     AND (school_id IS NULL OR school_id = ?)",
    [$student['class_level'], (int)$student['school_id']]
);
$topicCoverage = $totalTopics > 0 ? round(((int)$stats['topics_completed'] / $totalTopics) * 100) : 0;

if ($stats['quiz_count'] === 0) {
    $progressInsightTitle = 'Start with a quiz';
    $progressInsightText = 'Complete your first quiz to begin recording subject performance.';
} elseif ($stats['avg_score'] < 60) {
    $progressInsightTitle = 'Review and retry';
    $progressInsightText = 'Review low-scoring topics and use the answer explanations before trying again.';
} elseif ($topicCoverage < 50) {
    $progressInsightTitle = 'Continue your topics';
    $progressInsightText = 'Complete more approved topics to broaden your curriculum coverage.';
} else {
    $progressInsightTitle = 'Keep the momentum';
    $progressInsightText = 'Your recorded activity is progressing well. Continue practising regularly.';
}

// Announcements
$announcements = dbRows(
    "SELECT a.*, COALESCE(t.full_name, 'EduTrack Admin') as teacher_name
     FROM announcements a
     LEFT JOIN teachers t ON a.teacher_id = t.id
     WHERE a.is_active = 1 AND (a.target = 'all' OR a.target = ?)
     AND (a.scheduled_at IS NULL OR a.scheduled_at <= NOW())
     AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
     ORDER BY a.is_pinned DESC, a.created_at DESC LIMIT 3",
    [$student['class_level']]
);
foreach ($announcements as &$announcement) {
    if (!empty($announcement['teacher_name']) && $announcement['teacher_name'] !== 'EduTrack Admin') {
        $announcement['teacher_name'] = teacherDisplayName($announcement['teacher_name']);
    }
}
unset($announcement);

// Show the newest pinned announcement once per student. If an admin edits it,
// the changed version is eligible to appear once again.
$popupAnnouncement = dbRow(
    "SELECT a.*, COALESCE(t.full_name, 'EduTrack Admin') AS teacher_name
     FROM announcements a
     LEFT JOIN teachers t ON a.teacher_id=t.id
     LEFT JOIN announcement_views av ON av.announcement_id=a.id AND av.student_id=?
     WHERE a.is_active=1 AND a.is_pinned=1
       AND (a.target='all' OR a.target=?)
       AND (a.scheduled_at IS NULL OR a.scheduled_at<=NOW())
       AND (a.expires_at IS NULL OR a.expires_at>=CURDATE())
       AND (av.id IS NULL OR av.seen_version < COALESCE(a.edited_at, a.created_at))
     ORDER BY a.created_at DESC LIMIT 1",
    [$studentId, $student['class_level']]
);
if ($popupAnnouncement && $popupAnnouncement['teacher_name'] !== 'EduTrack Admin') {
    $popupAnnouncement['teacher_name'] = teacherDisplayName($popupAnnouncement['teacher_name']);
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Dashboard Grid -->
<div class="row g-4 mb-4">
    <!-- Welcome Card -->
    <div class="col-12">
        <div class="welcome-card">
            <div class="welcome-text">
                <h2>Good <?= date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening') ?>, 
                    <?= htmlspecialchars(explode(' ', $student['full_name'])[0]) ?>! 👋</h2>
                <p class="mb-0"><?= $student['class_level'] ?> • <?= $student['class_level'] === 'JHS3' ? 'BECE year – keep pushing!' : 'Keep learning every day!' ?></p>
            </div>
            <div class="welcome-stats">
                <div class="ws-item">
                    <div class="ws-value">🔥 <?= (int)$student['current_streak'] ?></div>
                    <div class="ws-label">Day Streak</div>
                </div>
                <div class="ws-item">
                    <div class="ws-value">⭐ <?= number_format($student['total_points']) ?></div>
                    <div class="ws-label">Points</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#EEF2FF">📝</div>
            <div class="stat-value"><?= $stats['quiz_count'] ?></div>
            <div class="stat-label">Quizzes Done</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#F0FDF4">📊</div>
            <div class="stat-value"><?= $stats['avg_score'] ?>%</div>
            <div class="stat-label">Avg Score</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#FFF7ED">📚</div>
            <div class="stat-value"><?= $stats['topics_completed'] ?></div>
            <div class="stat-label">Topics Done</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#FDF4FF">🏆</div>
            <div class="stat-value"><?= $stats['badge_count'] ?></div>
            <div class="stat-label">Badges</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column -->
    <div class="col-lg-8">

        <!-- ML learning progress -->
        <div class="card edu-card mb-4">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="card-title mb-0">Exam Performance Forecast</h5>
                    <span class="badge bg-purple-soft text-purple"><?=
                        $prediction['available']
                            ? (!empty($prediction['provisional']) || ($prediction['inference_source'] ?? '') === 'personal_linear_regression'
                                ? 'Provisional personal forecast'
                                : 'ML prediction')
                            : 'Building learner profile'
                    ?></span>
                </div>
                <div class="row align-items-center">
                    <div class="col-md-4 text-center mb-3 mb-md-0">
                        <div class="prediction-circle">
                            <div class="prediction-value"><?= $prediction['available'] ? $prediction['score'] . '%' : '--' ?></div>
                            <div class="prediction-grade"><?= $prediction['available'] ? 'Projected score · Grade ' . $prediction['grade'] : 'Insufficient data' ?></div>
                        </div>
                        <div class="text-muted small mt-2">
                            <?php if ($prediction['available']): ?>
                                <?= $prediction['confidence'] ?>% confidence · <?= ucfirst($prediction['risk_level']) ?> risk
                            <?php else: ?>
                                <?= htmlspecialchars($prediction['data_message'] ?? ('Complete ' . (int)$prediction['attempts_needed'] . ' more quizzes to unlock')) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="prediction-factors">
                            <div class="factor-row">
                                <span>Quiz Performance</span>
                                <div class="factor-bar">
                                    <div class="factor-fill" style="width:<?= $stats['avg_score'] ?>%"></div>
                                </div>
                                <span><?= $stats['avg_score'] ?>%</span>
                            </div>
                            <div class="factor-row">
                                <span>Topic Coverage</span>
                                <div class="factor-bar">
                                    <div class="factor-fill" style="width:<?= $topicCoverage ?>%"></div>
                                </div>
                                <span><?= $topicCoverage ?>%</span>
                            </div>
                            <div class="factor-row">
                                <span>Consistency</span>
                                <div class="factor-bar">
                                    <div class="factor-fill" style="width:<?= min(100, (int)$student['current_streak'] * 10) ?>%"></div>
                                </div>
                                <span><?= (int)$student['current_streak'] ?> days</span>
                            </div>
                        </div>
                        <div class="prediction-insight">
                            <div class="prediction-insight-title"><?= $prediction['available'] ? 'Explainable forecast' : htmlspecialchars($progressInsightTitle) ?></div>
                            <div>
                                <?php if ($prediction['available'] && $prediction['factors']): ?>
                                    Main factors: <?= htmlspecialchars(implode(', ', array_column($prediction['factors'], 'name'))) ?>.
                                <?php else: ?>
                                    <?= htmlspecialchars($progressInsightText) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ML study recommendations -->
        <div class="card edu-card mb-4">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="card-title mb-0">Personalized Study Sequence</h5>
                    <span class="badge bg-purple-soft text-purple">Personalized recommendations</span>
                </div>
                <?php if (empty($recommendations)): ?>
                    <p class="text-muted">Great job! You're all caught up. Keep completing quizzes! 🎉</p>
                <?php else: ?>
                    <div class="rec-list">
                        <?php foreach ($recommendations as $rec): ?>
                        <div class="rec-item" onclick="window.location='<?= BASE_URL ?>/student/topic.php?id=<?= $rec['topic']['id'] ?>'">
                            <div class="rec-color" style="background:<?= htmlspecialchars($rec['topic']['color'] ?? '#4F46E5') ?>"></div>
                            <div class="rec-content">
                                <div class="rec-subject"><?= htmlspecialchars($rec['topic']['subject_name']) ?></div>
                                <div class="rec-title"><?= htmlspecialchars($rec['topic']['title']) ?></div>
                                <div class="rec-reason">💡 <?= htmlspecialchars($rec['reason']) ?></div>
                                <div class="text-muted small mt-1"><?= htmlspecialchars($rec['study_tip']) ?></div>
                            </div>
                            <div class="rec-meta">
                                <span class="difficulty-badge diff-<?= $rec['topic']['difficulty'] ?>">
                                    <?= ucfirst($rec['topic']['difficulty']) ?>
                                </span>
                                <span class="text-muted small"><?= $rec['topic']['estimated_minutes'] ?>min</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card edu-card">
    <div class="card-body">

        <?php
        $masteryValue = dbValue(
            'SELECT AVG(mastery_level) FROM student_learning_profiles WHERE student_id = ?',
            [$studentId]
        );
        $hasMasteryData = $masteryValue !== false && $masteryValue !== null;
        $mastery = $hasMasteryData ? round((float)$masteryValue * 100) : 0;
        $target = $learningGoal['target_mastery'];
        $remaining = max(0, $target - $mastery);
        ?>

        <h5 class="card-title mb-3">🎯 Learning Goal</h5>

        <div class="mb-3">
            <strong>Current Mastery:</strong> <?= $hasMasteryData ? $mastery . '%' : 'Not measured yet' ?>
        </div>

        <form method="post" class="mb-3" id="learning-goal">
            <input type="hidden" name="action" value="update_learning_goal">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">
            <label for="target-mastery" class="form-label"><strong>Target Mastery:</strong> <span id="target-mastery-value"><?= $target ?>%</span></label>
            <div class="d-flex align-items-center gap-3">
                <input class="form-range flex-grow-1" type="range" id="target-mastery" name="target_mastery"
                       min="50" max="95" step="5" value="<?= $target ?>"
                       oninput="document.getElementById('target-mastery-value').textContent=this.value+'%'">
                <button class="btn btn-primary btn-sm" type="submit">Update</button>
            </div>
        </form>

        <div class="progress mb-3" style="height:12px;">
            <div class="progress-bar bg-success"
                 style="width: <?= min($mastery,100) ?>%;">
            </div>
        </div>

        <div class="text-muted">
            <?= $hasMasteryData
                ? ($remaining > 0
                    ? "You need {$remaining} percentage points to reach your goal. We'll recommend the topics that need the most improvement."
                    : 'You have reached your mastery goal. Recommendations will help you maintain and extend your progress.')
                : 'Complete your first quiz to begin measuring mastery.' ?>
        </div>

    </div>

</div>
    </div>

    <!-- Right Column -->
    <div class="col-lg-4">

        <!-- Streak Card -->
        <div class="card edu-card mb-4 streak-card">
            <div class="card-body text-center">
                <div class="streak-fire">🔥</div>
                <h3 class="streak-number"><?= (int)$student['current_streak'] ?></h3>
                <p class="mb-1 fw-600">Day Streak!</p>
                <p class="text-muted small mb-3">Longest: <?= (int)$student['longest_streak'] ?> days</p>
                <?php
                $days = ['M','T','W','T','F','S','S'];
                $streak = min(7, (int)$student['current_streak']);
                ?>
                <div class="streak-dots">
                    <?php for ($i = 0; $i < 7; $i++): ?>
                        <div class="streak-dot <?= $i < $streak ? 'active' : '' ?>"><?= $days[$i] ?></div>
                    <?php endfor; ?>
                </div>
                <?php if ($student['current_streak'] == 0): ?>
                    <p class="text-muted small mt-2">Complete a quiz today to start your streak!</p>
                <?php else: ?>
                    <p class="text-success small mt-2">Keep it up! Come back tomorrow!</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Announcements -->
        <?php if (!empty($announcements)): ?>
        <div class="card edu-card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">📢 Announcements</h5>
                <?php foreach ($announcements as $ann): ?>
                <div class="announcement-item">
                    <div class="ann-title"><?= htmlspecialchars($ann['title']) ?></div>
                    <div class="ann-body"><?= htmlspecialchars($ann['content']) ?></div>
                    <div class="ann-meta text-muted small">
                        By <?= htmlspecialchars($ann['teacher_name']) ?> • 
                        <?= date('M d', strtotime($ann['created_at'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="card edu-card">
            <div class="card-body">
                <h5 class="card-title mb-3">⚡ Quick Actions</h5>
                <div class="d-grid gap-2">
                    <a href="<?= BASE_URL ?>/student/quizzes.php" class="btn btn-primary btn-edu">
                        📝 Take a Quiz
                    </a>
                    <a href="<?= BASE_URL ?>/student/subjects.php" class="btn btn-outline-primary btn-edu">
                        📚 Browse Subjects
                    </a>
                    <a href="<?= BASE_URL ?>/student/badges.php" class="btn btn-outline-warning btn-edu">
                        🏆 My Badges (<?= $stats['badge_count'] ?>)
                    </a>
                    <a href="<?= BASE_URL ?>/student/leaderboard.php" class="btn btn-outline-success btn-edu">
                        🥇 Leaderboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($popupAnnouncement): ?>
<div class="modal fade" id="importantAnnouncementModal" tabindex="-1" aria-labelledby="importantAnnouncementTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="importantAnnouncementTitle">&#x1F4CC; <?= htmlspecialchars($popupAnnouncement['title']) ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><?= nl2br(htmlspecialchars($popupAnnouncement['content'])) ?></div>
                <div class="small text-muted">By <?= htmlspecialchars($popupAnnouncement['teacher_name']) ?> &middot; <?= date('M d, Y', strtotime($popupAnnouncement['created_at'])) ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got it</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const element = document.getElementById('importantAnnouncementModal');
    if (!element || typeof bootstrap === 'undefined') return;
    let recorded = false;
    const recordSeen = function () {
        if (recorded) return;
        recorded = true;
        const data = new FormData();
        data.append('announcement_id', '<?= (int)$popupAnnouncement['id'] ?>');
        data.append('csrf_token', '<?= htmlspecialchars(generateCSRF(), ENT_QUOTES) ?>');
        fetch('<?= BASE_URL ?>/student/mark_announcement_seen.php', {
            method: 'POST', body: data, credentials: 'same-origin', keepalive: true
        }).catch(function () { recorded = false; });
    };
    element.addEventListener('hidden.bs.modal', recordSeen, {once: true});
    new bootstrap.Modal(element).show();
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
