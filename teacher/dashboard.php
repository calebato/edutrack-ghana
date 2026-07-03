<?php
/**
 * EduTrack Ghana - Teacher Dashboard
 * teacher/dashboard.php
 */
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/teacher.php';
requireTeacher();

$teacherId = $_SESSION['user_id'];
$teacher = getCurrentUser();
$stats = getTeacherStats($teacherId);
$assignedClasses = teacherAssignedClasses($teacher);
[$riskClassSql, $riskClassParams] = teacherClassSql('s.class_level', $assignedClasses);

$engagementRate = $stats['total_students'] > 0
    ? round(($stats['active_students'] / $stats['total_students']) * 100)
    : 0;

$isGeneralTeacher = $teacher['subject'] === 'General';
$subject = $isGeneralTeacher ? null : dbRow("SELECT id FROM subjects WHERE name = ?", [$teacher['subject']]);
$subjectId = (int)($subject['id'] ?? 0);
if ($isGeneralTeacher) {
    $atRisk = dbRows(
        "SELECT s.id,s.full_name,s.class_level,AVG(qa.score) AS avg_score,COUNT(qa.id) AS quiz_count
         FROM students s
         LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
         WHERE s.school_id = ? AND s.is_active = 1 AND $riskClassSql
         GROUP BY s.id
         HAVING quiz_count = 0 OR avg_score < 50
         ORDER BY quiz_count ASC, avg_score ASC LIMIT 5",
        array_merge([(int)$teacher['school_id']], $riskClassParams)
    );
} else {
    $atRisk = dbRows(
        "SELECT s.id,s.full_name,s.class_level,
                AVG(CASE WHEN t.subject_id = ? THEN qa.score END) AS avg_score,
                COUNT(CASE WHEN t.subject_id = ? THEN qa.id END) AS quiz_count
         FROM students s
         LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
         LEFT JOIN quizzes q ON q.id = qa.quiz_id
         LEFT JOIN topics t ON t.id = q.topic_id
         WHERE s.school_id = ? AND s.is_active = 1 AND $riskClassSql
         GROUP BY s.id
         HAVING quiz_count = 0 OR avg_score < 50
         ORDER BY quiz_count ASC, avg_score ASC LIMIT 5",
        array_merge([$subjectId, $subjectId, (int)$teacher['school_id']], $riskClassParams)
    );
}

foreach ($atRisk as &$studentRisk) {
    $studentRisk['prediction'] = predictStudentExamPerformance((int)$studentRisk['id']);
}
unset($studentRisk);

$priorityItems = [];
if (!empty($atRisk)) {
    $priorityItems[] = [
        'label' => count($atRisk) . ' student' . (count($atRisk) === 1 ? '' : 's') . ' need attention',
        'detail' => 'Review weak scores or students with no quiz attempts.',
        'url' => BASE_URL . '/teacher/students.php',
        'action' => 'Review students',
    ];
}
if ($engagementRate < 50 && $stats['total_students'] > 0) {
    $priorityItems[] = [
        'label' => 'Class engagement is low',
        'detail' => $engagementRate . '% active this week. A short announcement may help.',
        'url' => BASE_URL . '/teacher/announcements.php',
        'action' => 'Post announcement',
    ];
}
if ($stats['weekly_attempts'] === 0) {
    $priorityItems[] = [
        'label' => 'No quiz activity this week',
        'detail' => 'Create or assign a quiz to restart practice.',
        'url' => BASE_URL . '/teacher/create_quiz.php',
        'action' => 'Create quiz',
    ];
}

$loginDates = [];
$loginCounts = [];
$today = new DateTime();
for ($i = 6; $i >= 0; $i--) {
    $date = (clone $today)->modify("-$i days");
    $key = $date->format('Y-m-d');
    $loginDates[] = $date->format('M d');
    $loginCounts[] = 0;
    foreach ($stats['login_stats'] as $loginStat) {
        if ($loginStat['date'] === $key) {
            $loginCounts[count($loginCounts) - 1] = (int)$loginStat['logins'];
            break;
        }
    }
}

$pageTitle = 'Teacher Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="teacher-hero mb-4">
    <div>
        <div class="text-muted small mb-1"><?= date('l, F j, Y') ?></div>
        <h2 class="mb-1">Welcome, <?= htmlspecialchars(teacherDisplayName($teacher['full_name'])) ?></h2>
        <p class="mb-0"><?= htmlspecialchars($teacher['subject']) ?> Teacher · Class monitoring overview</p>
    </div>
    <div class="teacher-hero-actions">
        <a href="<?= BASE_URL ?>/teacher/topics.php" class="btn btn-outline-primary btn-edu">Manage Topics</a>
        <a href="<?= BASE_URL ?>/teacher/create_quiz.php" class="btn btn-primary btn-edu">Create Quiz</a>
        <a href="<?= BASE_URL ?>/teacher/announcements.php" class="btn btn-outline-primary btn-edu">Post Announcement</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#EEF2FF">#</div>
            <div class="stat-value"><?= $stats['total_students'] ?></div>
            <div class="stat-label">Total Students</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#F0FDF4">%</div>
            <div class="stat-value"><?= $engagementRate ?>%</div>
            <div class="stat-label">Engagement</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#FFF7ED">Q</div>
            <div class="stat-value"><?= $stats['weekly_attempts'] ?></div>
            <div class="stat-label">Quiz Attempts</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#FEF2F2">!</div>
            <div class="stat-value"><?= count($atRisk) ?></div>
            <div class="stat-label">Need Attention</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="card edu-card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0">Class Activity</h5>
                    <span class="badge bg-primary-soft text-primary"><?= $stats['active_students'] ?> logged in this week</span>
                </div>
                <canvas id="loginChart" height="88"></canvas>
            </div>
        </div>

        <div class="card edu-card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0">Class Leaders</h5>
                    <a href="<?= BASE_URL ?>/teacher/students.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>

                <?php if (empty($stats['top_students'])): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">#</div>
                        <div class="empty-state-title">No students yet</div>
                        <p class="empty-state-text">Students will appear here after they are added to your school.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table edu-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Points</th>
                                    <th>Avg Score</th>
                                    <th>Quizzes</th>
                                    <th>Streak</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['top_students'] as $studentRow): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="user-avatar-sm"><?= strtoupper(substr($studentRow['full_name'], 0, 1)) ?></div>
                                            <?= htmlspecialchars($studentRow['full_name']) ?>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-light text-dark"><?= htmlspecialchars($studentRow['class_level']) ?></span></td>
                                    <td><strong><?= number_format($studentRow['total_points']) ?></strong></td>
                                    <td>
                                        <?php if ($studentRow['avg_score']): ?>
                                            <span class="score-badge <?= $studentRow['avg_score'] >= 60 ? 'score-pass' : 'score-fail' ?>">
                                                <?= round($studentRow['avg_score']) ?>%
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $studentRow['quiz_count'] ?></td>
                                    <td><?= $studentRow['current_streak'] ?> days</td>
                                    <td>
                                        <a href="<?= BASE_URL ?>/teacher/student_detail.php?id=<?= $studentRow['id'] ?>" class="btn btn-xs btn-outline-primary">View</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card edu-card">
            <div class="card-body">
                <h5 class="card-title mb-3">Recent Student Activity</h5>

                <div id="recentActivityFeed"
                     data-endpoint="<?= BASE_URL ?>/api/teacher_recent_activity.php">
                <?php if (empty($stats['recent_activity'])): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">i</div>
                        <div class="empty-state-title">No recent activity</div>
                        <p class="empty-state-text">Student logins, quiz attempts, topic progress, and badges will show here.</p>
                    </div>
                <?php else: ?>
                    <div class="activity-feed">
                        <?php
                        $actionLabels = [
                            'login' => ['Logged in', 'text-primary'],
                            'quiz_start' => ['Started a quiz', 'text-warning'],
                            'quiz_complete' => ['Completed a quiz', 'text-success'],
                            'topic_start' => ['Started a topic', 'text-info'],
                            'topic_complete' => ['Completed a topic', 'text-success'],
                            'badge_earned' => ['Earned a badge', 'text-warning'],
                            'points_earned' => ['Earned points', 'text-success'],
                        ];

                        foreach ($stats['recent_activity'] as $activity):
                            $label = $actionLabels[$activity['action']] ?? [$activity['action'], 'text-muted'];
                            $diff = time() - (int)$activity['created_at_unix'];
                            if ($diff < 60) {
                                $timeLabel = 'Just now';
                            } elseif ($diff < 3600) {
                                $timeLabel = round($diff / 60) . 'm ago';
                            } elseif ($diff < 86400) {
                                $timeLabel = round($diff / 3600) . 'h ago';
                            } else {
                                $timeLabel = date('M d', strtotime($activity['created_at']));
                            }
                        ?>
                            <div class="activity-item">
                                <span class="act-icon">•</span>
                                <div class="act-content">
                                    <strong><?= htmlspecialchars($activity['full_name']) ?></strong>
                                    <span class="<?= $label[1] ?>"><?= htmlspecialchars($label[0]) ?></span>
                                    <?php if ($activity['details']): ?>
                                        <span class="text-muted small">(<?= htmlspecialchars($activity['details']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="act-time text-muted small"><?= $timeLabel ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card edu-card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">Today's Priorities</h5>
                <?php if (empty($priorityItems)): ?>
                    <div class="empty-state" style="min-height:150px">
                        <div class="empty-state-icon">✓</div>
                        <div class="empty-state-title">Nothing urgent</div>
                        <p class="empty-state-text mb-0">Your class is in a stable place today. Keep monitoring activity and scores.</p>
                    </div>
                <?php else: ?>
                    <div class="teacher-priority-list">
                        <?php foreach ($priorityItems as $item): ?>
                            <div class="teacher-priority-item">
                                <div>
                                    <div class="fw-700"><?= htmlspecialchars($item['label']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($item['detail']) ?></div>
                                </div>
                                <a href="<?= htmlspecialchars($item['url']) ?>" class="btn btn-xs btn-outline-primary"><?= htmlspecialchars($item['action']) ?></a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card edu-card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">Quick Actions</h5>
                <div class="d-grid gap-2">
                    <a href="<?= BASE_URL ?>/teacher/students.php" class="btn btn-primary btn-edu">View Students</a>
                    <a href="<?= BASE_URL ?>/teacher/create_quiz.php" class="btn btn-outline-info btn-edu">Create Quiz</a>
                    <a href="<?= BASE_URL ?>/teacher/analytics.php" class="btn btn-outline-primary btn-edu">Open Analytics</a>
                    <a href="<?= BASE_URL ?>/teacher/reports.php" class="btn btn-outline-success btn-edu">Generate Reports</a>
                    <a href="<?= BASE_URL ?>/teacher/announcements.php" class="btn btn-outline-warning btn-edu">Post Announcement</a>
                </div>
            </div>
        </div>

        <div class="card edu-card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">Class Health</h5>
                <div class="text-center mb-3">
                    <div class="engagement-circle">
                        <div class="engagement-value"><?= $engagementRate ?>%</div>
                        <div class="engagement-label">Logged in</div>
                    </div>
                </div>
                <div class="progress" style="height:10px">
                    <div class="progress-bar bg-primary" style="width:<?= $engagementRate ?>%"></div>
                </div>
                <p class="text-muted small mt-2 text-center mb-0">
                    <?= $stats['active_students'] ?> of <?= $stats['total_students'] ?> students logged in this week
                </p>
                <?php if ($stats['weekly_avg'] > 0): ?>
                    <div class="teacher-health-note mt-3">
                        Weekly average score: <strong><?= $stats['weekly_avg'] ?>%</strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card edu-card border-danger-soft">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="card-title mb-0">Predictive Student Risk</h5>
                    <span class="badge bg-purple-soft text-purple">ML assisted</span>
                </div>

                <?php if (empty($atRisk)): ?>
                    <div class="empty-state" style="min-height:150px">
                        <div class="empty-state-icon">✓</div>
                        <div class="empty-state-title">No students flagged</div>
                        <p class="empty-state-text mb-0">No student is currently flagged by performance or activity signals.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($atRisk as $studentRow): ?>
                        <div class="at-risk-item">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="user-avatar-sm"><?= strtoupper(substr($studentRow['full_name'], 0, 1)) ?></div>
                                    <div>
                                        <div class="small fw-600"><?= htmlspecialchars($studentRow['full_name']) ?></div>
                                        <div class="text-muted" style="font-size:11px">
                                            <?= htmlspecialchars($studentRow['class_level']) ?> ·
                                            <?php if ($studentRow['prediction']['available']): ?>
                                                Projected <?= $studentRow['prediction']['score'] ?>% · <?= ucfirst($studentRow['prediction']['risk_level']) ?> risk
                                            <?php else: ?>
                                                Learner profile pending · <?= (int)$studentRow['prediction']['attempts_needed'] ?> quiz<?= (int)$studentRow['prediction']['attempts_needed'] === 1 ? '' : 'zes' ?> needed
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <a href="<?= BASE_URL ?>/teacher/student_detail.php?id=<?= $studentRow['id'] ?>" class="btn btn-xs btn-outline-danger">View</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('loginChart').getContext('2d');

new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($loginDates) ?>,
        datasets: [{
            label: 'Student Logins',
            data: <?= json_encode($loginCounts) ?>,
            backgroundColor: 'rgba(79, 70, 229, 0.18)',
            borderColor: '#4F46E5',
            borderWidth: 2,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1 },
                grid: { color: 'rgba(0,0,0,0.05)' }
            },
            x: { grid: { display: false } }
        }
    }
});

(function () {
    const feed = document.getElementById('recentActivityFeed');
    if (!feed) return;

    const actionLabels = {
        login: ['Logged in', 'text-primary'],
        quiz_start: ['Started a quiz', 'text-warning'],
        quiz_complete: ['Completed a quiz', 'text-success'],
        topic_start: ['Started a topic', 'text-info'],
        topic_complete: ['Completed a topic', 'text-success'],
        badge_earned: ['Earned a badge', 'text-warning'],
        points_earned: ['Earned points', 'text-success']
    };

    function escapeHtml(value) {
        const element = document.createElement('div');
        element.textContent = value == null ? '' : String(value);
        return element.innerHTML;
    }

    function relativeTime(value) {
        const timestamp = Number(value) * 1000;
        if (!Number.isFinite(timestamp)) return '';
        const seconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return Math.round(seconds / 60) + 'm ago';
        if (seconds < 86400) return Math.round(seconds / 3600) + 'h ago';
        return new Date(timestamp).toLocaleDateString(undefined, { month: 'short', day: '2-digit' });
    }

    function renderActivities(activities) {
        if (!activities.length) {
            feed.innerHTML = '<div class="empty-state"><div class="empty-state-icon">i</div>' +
                '<div class="empty-state-title">No recent activity</div>' +
                '<p class="empty-state-text">Student logins, quiz attempts, topic progress, and badges will show here.</p></div>';
            return;
        }

        feed.innerHTML = '<div class="activity-feed">' + activities.map(function (activity) {
            const label = actionLabels[activity.action] || [activity.action, 'text-muted'];
            const details = activity.details
                ? ' <span class="text-muted small">(' + escapeHtml(activity.details) + ')</span>'
                : '';
            return '<div class="activity-item" data-activity-id="' + Number(activity.id) + '">' +
                '<span class="act-icon">•</span>' +
                '<div class="act-content"><strong>' + escapeHtml(activity.full_name) + '</strong> ' +
                '<span class="' + label[1] + '">' + escapeHtml(label[0]) + '</span>' + details + '</div>' +
                '<div class="act-time text-muted small">' + relativeTime(activity.created_at_unix) + '</div></div>';
        }).join('') + '</div>';
    }

    async function refreshActivity() {
        if (document.hidden) return;
        try {
            const response = await fetch(feed.dataset.endpoint, {
                headers: { Accept: 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) return;
            const result = await response.json();
            if (result.success && Array.isArray(result.activities)) {
                renderActivities(result.activities);
            }
        } catch (error) {
            // Keep the last successfully rendered feed during temporary network errors.
        }
    }

    window.setInterval(refreshActivity, 10000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refreshActivity();
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
