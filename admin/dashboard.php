<?php
require_once __DIR__ . '/../auth/auth.php';
requireAdmin();

function adminRelativeTime(string $value): string {
    $seconds = max(0, time() - strtotime($value));
    if ($seconds < 60) return 'Just now';
    if ($seconds < 3600) return round($seconds / 60) . 'm ago';
    if ($seconds < 86400) return round($seconds / 3600) . 'h ago';
    if ($seconds < 604800) return round($seconds / 86400) . 'd ago';
    return date('M j', strtotime($value));
}

$metrics = [
    'students' => (int)dbValue('SELECT COUNT(*) FROM students'),
    'teachers' => (int)dbValue('SELECT COUNT(*) FROM teachers'),
    'quizzes' => (int)dbValue('SELECT COUNT(*) FROM quizzes'),
    'pending_violations' => (int)dbValue("SELECT COUNT(*) FROM violation_reports WHERE status = 'Pending'"),
    'schools' => (int)dbValue('SELECT COUNT(*) FROM schools'),
    'active_today' => (int)dbValue('SELECT COUNT(*) FROM students WHERE last_login >= CURDATE()'),
    'weekly_attempts' => (int)dbValue('SELECT COUNT(*) FROM quiz_attempts WHERE completed_at IS NOT NULL AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'),
];

$weeklyAverage = (int)dbValue('SELECT COALESCE(ROUND(AVG(score)), 0) FROM quiz_attempts WHERE completed_at IS NOT NULL AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');

$recentLogs = dbRows('SELECT user_name, action, created_at FROM system_logs ORDER BY created_at DESC LIMIT 6');
$pendingReports = dbRows("
    SELECT vr.id, vr.violation_type, vr.created_at, vr.is_anonymous, s.full_name
    FROM violation_reports vr
    LEFT JOIN students s ON s.id = vr.student_id
    WHERE vr.status = 'Pending'
    ORDER BY vr.created_at DESC
    LIMIT 5
");
$schoolSummary = dbRows('
    SELECT sc.id, sc.name, sc.region,
           COUNT(DISTINCT st.id) AS students,
           COUNT(DISTINCT te.id) AS teachers
    FROM schools sc
    LEFT JOIN students st ON st.school_id = sc.id
    LEFT JOIN teachers te ON te.school_id = sc.id
    GROUP BY sc.id, sc.name, sc.region
    ORDER BY students DESC, sc.name ASC
');

$adminName = trim((string)($_SESSION['user_name'] ?? 'System Admin')) ?: 'System Admin';
$adminInitial = strtoupper(substr($adminName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - EduTrack Ghana</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/admin-dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-dashboard.css') ?>" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-overlay" id="adminOverlay"></div>

<aside class="admin-sidebar" id="adminSidebar">
    <a class="admin-brand" href="dashboard.php">
        <span class="admin-brand-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" role="img" focusable="false">
                <path d="M22 10.5 12 5 2 10.5 12 16l10-5.5Z"></path>
                <path d="M6 12.7v3.1c0 1.8 2.7 3.2 6 3.2s6-1.4 6-3.2v-3.1"></path>
                <path d="M22 10.5v5"></path>
            </svg>
        </span>
        <span><strong>EduTrack Ghana</strong><small>Administration</small></span>
    </a>

    <nav class="admin-nav" aria-label="Admin navigation">
        <span class="admin-nav-label">Overview</span>
        <a class="active" href="dashboard.php"><span>D</span>Dashboard</a>

        <span class="admin-nav-label">Management</span>
        <a href="students.php"><span>S</span>Students</a>
        <a href="teachers.php"><span>T</span>Teachers</a>
        <a href="subjects.php"><span>B</span>Subjects</a>
        <a href="topics.php"><span>C</span>Topics</a>
        <a href="announcements.php"><span>A</span>Announcements</a>

        <span class="admin-nav-label">Oversight</span>
        <a href="violations.php"><span>!</span>Violations<?php if ($metrics['pending_violations']): ?><b><?= $metrics['pending_violations'] ?></b><?php endif; ?></a>
        <a href="logs.php"><span>L</span>System Logs</a>
    </nav>
</aside>

<main class="admin-main">
    <header class="admin-topbar">
        <button class="admin-menu-button" id="adminMenuButton" type="button" aria-label="Open navigation">&#9776;</button>
        <div class="admin-topbar-title">Dashboard</div>
        <div class="dropdown">
            <button class="admin-avatar dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open account menu">
                <?= htmlspecialchars($adminInitial) ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end admin-account-menu">
                <li class="admin-account-heading"><strong><?= htmlspecialchars($adminName) ?></strong><small>Administrator</small></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="logs.php">System Logs</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/auth/logout.php">Logout</a></li>
            </ul>
        </div>
    </header>

    <div class="admin-content">
        <section class="admin-hero" aria-labelledby="adminDashboardTitle">
            <div class="admin-hero-copy">
                <span class="admin-eyebrow"><?= date('l, F j') ?></span>
                <h1 id="adminDashboardTitle">Daily admin check-in</h1>
                <p>Review reports, platform activity, and school coverage before moving through the admin queue.</p>
            </div>
            <div class="admin-hero-summary" aria-label="Current queue">
                <div><strong><?= number_format($metrics['pending_violations']) ?></strong><span>reports waiting</span></div>
                <div><strong><?= number_format($metrics['weekly_attempts']) ?></strong><span>quiz attempts in 7 days</span></div>
            </div>
            <div class="admin-hero-actions">
                <a href="violations.php" class="btn btn-light">Review reports</a>
                <a href="announcements.php" class="btn btn-outline-light">Post announcement</a>
            </div>
        </section>

        <section class="admin-metrics" aria-label="Platform overview">
            <a class="admin-metric" href="students.php">
                <span class="admin-metric-icon blue">ST</span>
                <span><strong><?= number_format($metrics['students']) ?></strong><small>Registered students</small></span>
                <em><?= $metrics['active_today'] ?> active today</em>
            </a>
            <a class="admin-metric" href="teachers.php">
                <span class="admin-metric-icon slate">TR</span>
                <span><strong><?= number_format($metrics['teachers']) ?></strong><small>Teachers</small></span>
                <em><?= $metrics['schools'] ?> schools onboarded</em>
            </a>
            <a class="admin-metric" href="subjects.php">
                <span class="admin-metric-icon green">QZ</span>
                <span><strong><?= number_format($metrics['quizzes']) ?></strong><small>Quiz bank</small></span>
                <em><?= $metrics['weekly_attempts'] ?> attempts this week</em>
            </a>
            <a class="admin-metric <?= $metrics['pending_violations'] ? 'needs-attention' : '' ?>" href="violations.php">
                <span class="admin-metric-icon orange">!</span>
                <span><strong><?= number_format($metrics['pending_violations']) ?></strong><small>Reports to review</small></span>
                <em><?= $metrics['pending_violations'] ? 'Start with the report queue' : 'Queue clear' ?></em>
            </a>
        </section>

        <section class="admin-grid">
            <div class="admin-panel admin-panel-wide">
                <div class="admin-panel-header">
                    <div><h2>School coverage</h2><p>Where students and teachers are currently registered.</p></div>
                </div>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead><tr><th>School</th><th>Region</th><th>Students</th><th>Teachers</th><th>Coverage</th></tr></thead>
                        <tbody>
                        <?php foreach ($schoolSummary as $school): ?>
                            <?php $hasCoverage = (int)$school['teachers'] > 0 || (int)$school['students'] === 0; ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($school['name']) ?></strong></td>
                                <td><?= htmlspecialchars($school['region']) ?></td>
                                <td><?= number_format((int)$school['students']) ?></td>
                                <td><?= number_format((int)$school['teachers']) ?></td>
                                <td><span class="admin-status <?= $hasCoverage ? 'ok' : 'warning' ?>"><?= $hasCoverage ? 'Covered' : 'Teacher needed' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="admin-panel">
                <div class="admin-panel-header">
                    <div><h2>Learning activity</h2><p>Completed quiz work from the last 7 days.</p></div>
                </div>
                <div class="admin-weekly-score">
                    <div class="admin-weekly-primary">
                        <span>Average quiz score</span>
                        <strong><?= $weeklyAverage ?>%</strong>
                        <div class="admin-score-bar" aria-hidden="true"><span style="width: <?= min(100, $weeklyAverage) ?>%"></span></div>
                    </div>
                    <div class="admin-weekly-facts">
                        <div><strong><?= number_format($metrics['weekly_attempts']) ?></strong><span>Quiz attempts</span></div>
                        <div><strong><?= number_format($metrics['active_today']) ?></strong><span>Students active today</span></div>
                    </div>
                </div>
            </div>

            <div class="admin-panel">
                <div class="admin-panel-header">
                    <div><h2>Report queue</h2><p>Student concerns that still need a decision.</p></div>
                    <a href="violations.php">View all</a>
                </div>
                <?php if (!$pendingReports): ?>
                    <div class="admin-empty"><span>&#10003;</span><strong>No pending reports</strong><p>The report queue is clear.</p></div>
                <?php else: ?>
                    <div class="admin-list">
                    <?php foreach ($pendingReports as $report): ?>
                        <a href="violations.php" class="admin-list-item">
                            <span class="admin-list-icon alert">!</span>
                            <span><strong><?= htmlspecialchars($report['violation_type']) ?></strong><small><?= $report['is_anonymous'] ? 'Anonymous report' : htmlspecialchars($report['full_name'] ?? 'Student') ?></small></span>
                            <time><?= adminRelativeTime($report['created_at']) ?></time>
                        </a>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="admin-panel admin-panel-wide">
                <div class="admin-panel-header">
                    <div><h2>Recent activity</h2><p>Latest admin and account events.</p></div>
                    <a href="logs.php">Open logs</a>
                </div>
                <?php if (!$recentLogs): ?>
                    <div class="admin-empty"><strong>No activity yet</strong></div>
                <?php else: ?>
                    <div class="admin-timeline">
                    <?php foreach ($recentLogs as $log): ?>
                        <div class="admin-timeline-item">
                            <span></span>
                            <div><strong><?= htmlspecialchars($log['user_name'] ?? 'System') ?></strong><p><?= htmlspecialchars($log['action'] ?? '') ?></p></div>
                            <time><?= adminRelativeTime($log['created_at']) ?></time>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sidebar = document.getElementById('adminSidebar');
const overlay = document.getElementById('adminOverlay');
document.getElementById('adminMenuButton').addEventListener('click', function () {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('show');
});
overlay.addEventListener('click', function () {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
});
</script>
</body>
</html>
