<?php

require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/student.php';
requireStudent();

$studentId = (int)$_SESSION['user_id'];
$progress = getProgressOverview($studentId);
$stats = getStudentStats($studentId);
$student = $progress['student'];
$subjectScores = $progress['subject_scores'] ?? [];
$scoreHistory = $progress['score_history'] ?? [];

$totalTopics = (int)dbValue(
    "SELECT COUNT(*) FROM topics WHERE class_level = ? AND approval_status = 'approved' AND is_active = 1
     AND (school_id IS NULL OR school_id = ?)",
    [$student['class_level'], (int)$student['school_id']]
);
$topicCoverage = $totalTopics > 0 ? round(((int)$stats['topics_completed'] / $totalTopics) * 100) : 0;

$bestSubject = null;
$weakestSubject = null;
$needsAttention = [];
foreach ($subjectScores as $subject) {
    if ($bestSubject === null || (float)$subject['avg_score'] > (float)$bestSubject['avg_score']) $bestSubject = $subject;
    if ($weakestSubject === null || (float)$subject['avg_score'] < (float)$weakestSubject['avg_score']) $weakestSubject = $subject;
    if ((float)$subject['avg_score'] < 60) $needsAttention[] = $subject;
}

$improvementText = 'Complete more quizzes to see your progress trend.';
if (count($scoreHistory) >= 4) {
    $recentScores = array_slice($scoreHistory, 0, 3);
    $olderScores = array_slice($scoreHistory, 3, 3);
    $recentAverage = array_sum(array_column($recentScores, 'score')) / count($recentScores);
    $olderAverage = array_sum(array_column($olderScores, 'score')) / count($olderScores);
    $difference = round($recentAverage - $olderAverage);
    if ($difference > 0) $improvementText = "Your recent quiz average is up by {$difference} points.";
    elseif ($difference < 0) $improvementText = 'Your recent scores dipped. Review weaker topics before the next quiz.';
    else $improvementText = 'Your recent scores are steady.';
}

$pageTitle = 'My Progress';
$activeNav = 'progress';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-icon" style="background:#EEF2FF">%</div><div class="stat-value"><?= (int)$stats['avg_score'] ?>%</div><div class="stat-label">Quiz Average</div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-icon" style="background:#F0FDF4">#</div><div class="stat-value"><?= (int)$stats['quiz_count'] ?></div><div class="stat-label">Quizzes Taken</div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-icon" style="background:#FFF7ED">%</div><div class="stat-value"><?= $topicCoverage ?>%</div><div class="stat-label">Topic Coverage</div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-icon" style="background:#FDF4FF">+</div><div class="stat-value"><?= (int)$student['current_streak'] ?></div><div class="stat-label">Day Streak</div></div></div>
</div>

<div class="card edu-card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3"><h5 class="card-title mb-0">Progress Snapshot</h5><span class="badge bg-purple-soft text-purple">Recorded Activity</span></div>
        <div class="row g-3">
            <div class="col-md-4"><div class="subject-perf-card h-100"><div class="text-muted small mb-1">Best Subject</div><div class="fw-700"><?= $bestSubject ? htmlspecialchars($bestSubject['name']) : 'Not enough data' ?></div><div class="text-success fw-700 mt-1"><?= $bestSubject ? round($bestSubject['avg_score']) . '%' : '--' ?></div></div></div>
            <div class="col-md-4"><div class="subject-perf-card h-100"><div class="text-muted small mb-1">Needs Most Work</div><div class="fw-700"><?= $weakestSubject ? htmlspecialchars($weakestSubject['name']) : 'Not enough data' ?></div><div class="<?= $weakestSubject && $weakestSubject['avg_score'] < 60 ? 'text-danger' : 'text-muted' ?> fw-700 mt-1"><?= $weakestSubject ? round($weakestSubject['avg_score']) . '%' : '--' ?></div></div></div>
            <div class="col-md-4"><div class="subject-perf-card h-100"><div class="text-muted small mb-1">Recent Trend</div><div class="fw-700"><?= htmlspecialchars($improvementText) ?></div></div></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card edu-card mb-4"><div class="card-body">
            <h5 class="card-title mb-4">Subject Performance</h5>
            <?php if (!$subjectScores): ?>
                <div class="empty-state"><div class="empty-state-icon">%</div><div class="empty-state-title">No performance data yet</div><p class="empty-state-text">Complete quizzes to record subject performance.</p><a href="<?= BASE_URL ?>/student/quizzes.php" class="btn btn-sm btn-primary btn-edu">Take a Quiz</a></div>
            <?php else: ?><div class="row g-3">
                <?php foreach ($subjectScores as $subject): ?><div class="col-md-6"><div class="subject-perf-card"><div class="spf-header"><span><?= htmlspecialchars($subject['name']) ?></span><span class="fw-700 <?= $subject['avg_score'] >= 60 ? 'text-success' : 'text-danger' ?>"><?= round($subject['avg_score']) ?>%</span></div><div class="progress mt-2" style="height:8px"><div class="progress-bar" style="width:<?= round($subject['avg_score']) ?>%;background:<?= htmlspecialchars($subject['color']) ?>"></div></div><div class="spf-meta text-muted small mt-1"><?= (int)$subject['attempts'] ?> attempt<?= (int)$subject['attempts'] === 1 ? '' : 's' ?></div></div></div><?php endforeach; ?>
            </div><?php endif; ?>
        </div></div>

        <div class="card edu-card"><div class="card-body">
            <h5 class="card-title mb-3">Recent Quiz Scores</h5>
            <?php if (!$scoreHistory): ?>
                <div class="empty-state"><div class="empty-state-icon">?</div><div class="empty-state-title">No quiz history yet</div><p class="empty-state-text">Completed quizzes will appear here.</p></div>
            <?php else: ?><div class="table-responsive"><table class="table edu-table"><thead><tr><th>Quiz</th><th>Subject</th><th>Score</th><th>Date</th></tr></thead><tbody>
                <?php foreach ($scoreHistory as $score): ?><tr><td><?= htmlspecialchars($score['title']) ?></td><td><?= htmlspecialchars($score['subject']) ?></td><td><span class="score-badge <?= $score['score'] >= 60 ? 'score-pass' : 'score-fail' ?>"><?= (int)$score['score'] ?>%</span></td><td class="text-muted small"><?= $score['completed_at'] ? date('M d, Y', strtotime($score['completed_at'])) : '-' ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div></div>
    </div>

    <div class="col-lg-4"><div class="card edu-card"><div class="card-body">
        <h5 class="card-title mb-3">Study Focus</h5>
        <?php if (!$subjectScores): ?><p class="text-muted mb-0">Complete a quiz to identify subjects that need more practice.</p>
        <?php elseif (!$needsAttention): ?><p class="text-success mb-0">All recorded subject averages are at least 60%. Keep practising.</p>
        <?php else: ?><p class="text-muted small">Review these subjects before your next quiz:</p><?php foreach ($needsAttention as $subject): ?><div class="rec-item mb-2"><div class="rec-content"><div class="rec-title"><?= htmlspecialchars($subject['name']) ?></div><div class="rec-subject"><?= round($subject['avg_score']) ?>% recorded average</div></div></div><?php endforeach; ?><?php endif; ?>
    </div></div></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
