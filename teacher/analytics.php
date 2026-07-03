<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/teacher.php';
requireTeacher();

$teacherId = $_SESSION['user_id'];
$teacher   = getCurrentUser();
$isGeneral = $teacher['subject'] === 'General';
$subject = $isGeneral ? null : dbRow("SELECT id FROM subjects WHERE name = ?", [$teacher['subject']]);
$subjectId = $isGeneral ? null : (int)($subject['id'] ?? 0);
$assignedClasses = teacherAssignedClasses($teacher);
$analytics = getSchoolAnalytics($subjectId, (int)$teacher['school_id'], $assignedClasses);
[$classSql, $classParams] = teacherClassSql('class_level', $assignedClasses);
$predictiveStudents = dbRows(
    "SELECT id,full_name,class_level FROM students WHERE school_id=? AND is_active=1 AND $classSql ORDER BY full_name",
    array_merge([(int)$teacher['school_id']], $classParams)
);
foreach ($predictiveStudents as &$predictiveStudent) {
    $predictiveStudent['prediction'] = predictStudentExamPerformance((int)$predictiveStudent['id']);
}
unset($predictiveStudent);
usort($predictiveStudents, static function (array $a, array $b): int {
    $riskOrder = ['high' => 0, 'medium' => 1, 'low' => 2, 'insufficient_data' => 3];
    return ($riskOrder[$a['prediction']['risk_level']] ?? 4) <=> ($riskOrder[$b['prediction']['risk_level']] ?? 4);
});

$pageTitle = 'Analytics';
$activeNav = 'analytics';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">
    <div class="col-12">
        <div class="card edu-card mb-4">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="card-title mb-0">Predictive Exam Overview</h5>
                    <span class="badge bg-purple-soft text-purple">Explainable ML</span>
                </div>
                <div class="table-responsive">
                    <table class="table edu-table">
                        <thead><tr><th>Student</th><th>Class</th><th>Projected Score</th><th>BECE Grade</th><th>Confidence</th><th>Risk</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($predictiveStudents as $row): $forecast = $row['prediction']; ?>
                            <tr>
                                <td><?= htmlspecialchars($row['full_name']) ?></td>
                                <td><?= htmlspecialchars($row['class_level']) ?></td>
                                <td><?= $forecast['available'] ? $forecast['score'] . '%' : 'Pending' ?></td>
                                <td><?= $forecast['available'] ? $forecast['grade'] : '-' ?></td>
                                <td><?= $forecast['available'] ? $forecast['confidence'] . '%' : (int)$forecast['attempts_needed'] . ' quizzes needed' ?></td>
                                <td><span class="badge <?= $forecast['risk_level'] === 'high' ? 'bg-danger' : ($forecast['risk_level'] === 'medium' ? 'bg-warning text-dark' : ($forecast['risk_level'] === 'low' ? 'bg-success' : 'bg-light text-muted')) ?>"><?= ucwords(str_replace('_', ' ', $forecast['risk_level'])) ?></span></td>
                                <td><a class="btn btn-xs btn-outline-primary" href="student_detail.php?id=<?= (int)$row['id'] ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <!-- Class Performance -->
    <div class="col-lg-6">
        <div class="card edu-card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">📚 Performance by Class</h5>
                <?php if (empty($analytics['class_performance'])): ?>
                    <p class="text-muted">No data yet.</p>
                <?php else: ?>
                <canvas id="classChart" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Score Distribution -->
    <div class="col-lg-6">
        <div class="card edu-card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">🎯 Score Distribution</h5>
                <?php if (empty($analytics['distribution'])): ?>
                    <p class="text-muted">No data yet.</p>
                <?php else: ?>
                <canvas id="distChart" height="200"></canvas>
                <div class="row text-center mt-3">
                    <?php $d = $analytics['distribution']; ?>
                    <div class="col-3">
                        <div class="fw-700 text-success"><?= $d['excellent'] ?></div>
                        <div class="text-muted" style="font-size:10px">Excellent (80+)</div>
                    </div>
                    <div class="col-3">
                        <div class="fw-700 text-primary"><?= $d['good'] ?></div>
                        <div class="text-muted" style="font-size:10px">Good (60-79)</div>
                    </div>
                    <div class="col-3">
                        <div class="fw-700 text-warning"><?= $d['average'] ?></div>
                        <div class="text-muted" style="font-size:10px">Average (40-59)</div>
                    </div>
                    <div class="col-3">
                        <div class="fw-700 text-danger"><?= $d['poor'] ?></div>
                        <div class="text-muted" style="font-size:10px">Poor (&lt;40)</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Subject Pass Rates -->
    <div class="col-12">
        <div class="card edu-card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">📊 Subject Pass Rates</h5>
                <?php if (empty($analytics['subject_pass_rate'])): ?>
                    <p class="text-muted">No quiz data yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table edu-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Total Attempts</th>
                                <th>Pass Rate</th>
                                <th>Avg Score</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analytics['subject_pass_rate'] as $sub): ?>
                            <?php
                            $passRate = $sub['total_attempts'] > 0
                                ? round(($sub['passed_count'] / $sub['total_attempts']) * 100)
                                : 0;
                            ?>
                            <tr>
                                <td>
                                    <span class="d-inline-block w-3 h-3 rounded-circle me-2"
                                          style="background:<?= $sub['color'] ?>;width:10px;height:10px;display:inline-block;border-radius:50%"></span>
                                    <?= htmlspecialchars($sub['subject']) ?>
                                </td>
                                <td><?= $sub['total_attempts'] ?></td>
                                <td>
                                    <span class="fw-600 <?= $passRate >= 60 ? 'text-success' : 'text-danger' ?>">
                                        <?= $passRate ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="score-badge <?= $sub['avg_score'] >= 60 ? 'score-pass' : 'score-fail' ?>">
                                        <?= round($sub['avg_score']) ?>%
                                    </span>
                                </td>
                                <td style="min-width:150px">
                                    <div class="progress" style="height:6px">
                                        <div class="progress-bar" 
                                             style="width:<?= round($sub['avg_score']) ?>%;background:<?= $sub['color'] ?>">
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Monthly Activity -->
    <div class="col-12">
        <div class="card edu-card">
            <div class="card-body">
                <h5 class="card-title mb-3">📈 Monthly Quiz Activity</h5>
                <?php if (empty($analytics['monthly_activity'])): ?>
                    <p class="text-muted">No activity data yet.</p>
                <?php else: ?>
                <canvas id="monthlyChart" height="80"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
<?php
$classLabels  = array_column($analytics['class_performance'], 'class_level');
$classScores  = array_map(fn($c) => round($c['avg_score'] ?? 0), $analytics['class_performance']);
$classCounts  = array_column($analytics['class_performance'], 'student_count');

$dist = $analytics['distribution'];
$distValues = [$dist['excellent'] ?? 0, $dist['good'] ?? 0, $dist['average'] ?? 0, $dist['poor'] ?? 0];

$monthLabels = array_column($analytics['monthly_activity'], 'month');
$monthAttempts = array_column($analytics['monthly_activity'], 'attempts');
$monthScores = array_map(fn($m) => round($m['avg_score'] ?? 0), $analytics['monthly_activity']);
?>

// Class chart
<?php if (!empty($analytics['class_performance'])): ?>
new Chart(document.getElementById('classChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($classLabels) ?>,
        datasets: [
            {
                label: 'Avg Score (%)',
                data: <?= json_encode($classScores) ?>,
                backgroundColor: ['rgba(79,70,229,0.7)', 'rgba(124,58,237,0.7)', 'rgba(236,72,153,0.7)'],
                borderRadius: 6,
                yAxisID: 'y'
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            y: { beginAtZero: true, max: 100, grid: { color: 'rgba(0,0,0,0.05)' } },
            x: { grid: { display: false } }
        }
    }
});
<?php endif; ?>

// Distribution chart
<?php if (!empty($analytics['distribution'])): ?>
new Chart(document.getElementById('distChart'), {
    type: 'doughnut',
    data: {
        labels: ['Excellent (80+)', 'Good (60-79)', 'Average (40-59)', 'Poor (<40)'],
        datasets: [{
            data: <?= json_encode($distValues) ?>,
            backgroundColor: ['#10B981', '#4F46E5', '#F59E0B', '#EF4444'],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
    }
});
<?php endif; ?>

// Monthly chart
<?php if (!empty($analytics['monthly_activity'])): ?>
new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($monthLabels) ?>,
        datasets: [
            {
                label: 'Quiz Attempts',
                data: <?= json_encode($monthAttempts) ?>,
                borderColor: '#4F46E5',
                backgroundColor: 'rgba(79,70,229,0.1)',
                fill: true,
                tension: 0.4
            },
            {
                label: 'Avg Score (%)',
                data: <?= json_encode($monthScores) ?>,
                borderColor: '#10B981',
                backgroundColor: 'rgba(16,185,129,0.1)',
                fill: false,
                tension: 0.4,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
            y1: { beginAtZero: true, max: 100, position: 'right', grid: { display: false } },
            x: { grid: { display: false } }
        }
    }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
