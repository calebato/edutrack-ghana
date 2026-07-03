<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/teacher.php';
require_once __DIR__ . '/../ml/ml.php';
requireTeacher();

$studentId = (int)($_GET['id'] ?? 0);
if (!$studentId) { header('Location: ' . BASE_URL . '/teacher/students.php'); exit; }

$teacher = getCurrentUser();
$data = getStudentDetailForTeacher($studentId, (int)$teacher['school_id'], $teacher);
if (empty($data)) { header('Location: ' . BASE_URL . '/teacher/students.php'); exit; }

$student    = $data['student'];
$quizCount = count($data['quiz_history']);
$quizAverage = $quizCount > 0
    ? round(array_sum(array_column($data['quiz_history'], 'score')) / $quizCount)
    : 0;
$quizzesPassed = count(array_filter($data['quiz_history'], static fn(array $attempt): bool => !empty($attempt['passed'])));
$prediction = predictStudentExamPerformance($studentId);

$pageTitle = 'Student: ' . $student['full_name'];
$activeNav = 'students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="students.php" class="btn btn-sm btn-outline-secondary">← Back</a>
    <h4 class="mb-0"><?= htmlspecialchars($student['full_name']) ?></h4>
    <span class="badge bg-primary"><?= $student['class_level'] ?></span>
    <a href="<?= BASE_URL ?>/teacher/reports.php?student=<?= $studentId ?>" class="btn btn-sm btn-success ms-auto">
        📋 Generate Report
    </a>
</div>

<div class="row g-4">
    <!-- Left -->
    <div class="col-lg-4">
        <!-- Profile Card -->
        <div class="card edu-card mb-4">
            <div class="card-body text-center">
                <div class="user-avatar-lg mx-auto mb-3">
                    <?= strtoupper(substr($student['full_name'], 0, 1)) ?>
                </div>
                <h5><?= htmlspecialchars($student['full_name']) ?></h5>
                <p class="text-muted small mb-1"><?= htmlspecialchars($student['email']) ?></p>
                <p class="text-muted small mb-3"><?= $student['student_id'] ?></p>
                <div class="row text-center g-2">
                    <div class="col-4">
                        <div class="fw-700 text-primary"><?= number_format($student['total_points']) ?></div>
                        <div class="text-muted" style="font-size:10px">Points</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-700 text-warning">🔥 <?= $student['current_streak'] ?></div>
                        <div class="text-muted" style="font-size:10px">Streak</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-700 text-success"><?= count($data['badges']) ?></div>
                        <div class="text-muted" style="font-size:10px">Badges</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ML exam forecast -->
        <div class="card edu-card mb-4">
            <div class="card-body text-center">
                <h6 class="card-title">Predicted Exam Performance</h6>
                <div class="prediction-circle mx-auto mb-2">
                    <div class="prediction-value"><?= $prediction['available'] ? $prediction['score'] . '%' : '--' ?></div>
                    <div class="prediction-grade"><?= $prediction['available'] ? 'BECE Grade ' . $prediction['grade'] : 'Insufficient data' ?></div>
                </div>
                <p class="text-muted small">
                    <?= $prediction['available']
                        ? $prediction['confidence'] . '% confidence · ' . ucfirst($prediction['risk_level']) . ' risk'
                        : 'Requires ' . (int)$prediction['attempts_needed'] . ' more completed quiz' . ((int)$prediction['attempts_needed'] === 1 ? '' : 'zes') ?>
                </p>
                <div class="text-start">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>Quizzes Passed</span><strong><?= $quizzesPassed ?></strong>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span>Current Streak</span><strong><?= (int)$student['current_streak'] ?> days</strong>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span>Quizzes Taken</span><strong><?= $quizCount ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Strengths / Weaknesses -->
        <div class="card edu-card">
            <div class="card-body">
                <h6 class="card-title">💪 Strengths & Weaknesses</h6>
                <?php if (!empty($data['subject_scores'])): ?>
                    <div class="mb-2">
                        <div class="text-success small fw-600 mb-1">Strong Subjects (≥70%)</div>
                        <?php
                        $strong = array_filter($data['subject_scores'], fn($s) => $s['avg_score'] >= 70);
                        foreach ($strong as $s): ?>
                            <span class="badge bg-success-soft text-success me-1 mb-1"><?= htmlspecialchars($s['name']) ?></span>
                        <?php endforeach; ?>
                        <?php if (empty($strong)): ?>
                            <span class="text-muted small">None yet</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="text-danger small fw-600 mb-1">Needs Improvement (&lt;50%)</div>
                        <?php
                        $weak = array_filter($data['subject_scores'], fn($s) => $s['avg_score'] > 0 && $s['avg_score'] < 50);
                        foreach ($weak as $s): ?>
                            <span class="badge bg-danger-soft text-danger me-1 mb-1"><?= htmlspecialchars($s['name']) ?></span>
                        <?php endforeach; ?>
                        <?php if (empty($weak)): ?>
                            <span class="text-muted small">None identified</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted small">Not enough data yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right -->
    <div class="col-lg-8">
        <!-- Subject Performance -->
        <div class="card edu-card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">📊 Subject Performance</h5>
                <?php if (empty($data['subject_scores'])): ?>
                    <p class="text-muted">No quiz data available yet.</p>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($data['subject_scores'] as $sub): ?>
                    <div class="col-md-6">
                        <div class="subject-perf-card">
                            <div class="spf-header">
                                <span><?= htmlspecialchars($sub['name']) ?></span>
                                <strong class="<?= $sub['avg_score'] >= 60 ? 'text-success' : 'text-danger' ?>">
                                    <?= round($sub['avg_score']) ?>%
                                </strong>
                            </div>
                            <div class="progress mt-2" style="height:8px">
                                <div class="progress-bar" style="width:<?= round($sub['avg_score']) ?>%;background:<?= $sub['color'] ?>"></div>
                            </div>
                            <div class="spf-meta text-muted small mt-1"><?= $sub['attempts'] ?> attempts</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quiz History -->
        <div class="card edu-card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">📝 Quiz History</h5>
                <?php if (empty($data['quiz_history'])): ?>
                    <p class="text-muted">No quizzes taken yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table edu-table table-sm">
                        <thead>
                            <tr><th>Quiz</th><th>Subject</th><th>Score</th><th>Status</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($data['quiz_history'], 0, 15) as $q): ?>
                            <tr>
                                <td><?= htmlspecialchars($q['quiz_title']) ?></td>
                                <td><span class="subject-tag small"><?= htmlspecialchars($q['subject_name']) ?></span></td>
                                <td>
                                    <span class="score-badge <?= $q['score'] >= 60 ? 'score-pass' : 'score-fail' ?>">
                                        <?= $q['score'] ?>%
                                    </span>
                                </td>
                                <td>
                                    <?php if ($q['passed']): ?>
                                        <span class="badge bg-success-soft text-success">Passed</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-soft text-danger">Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small">
                                    <?= $q['completed_at'] ? date('M d, Y', strtotime($q['completed_at'])) : '–' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Badges -->
        <div class="card edu-card">
            <div class="card-body">
                <h5 class="card-title mb-3">🏅 Badges Earned (<?= count($data['badges']) ?>)</h5>
                <?php if (empty($data['badges'])): ?>
                    <p class="text-muted">No badges earned yet.</p>
                <?php else: ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($data['badges'] as $badge): ?>
                    <span class="badge-pill" style="background:<?= $badge['color'] ?>20;color:<?= $badge['color'] ?>;border:1px solid <?= $badge['color'] ?>40" title="<?= htmlspecialchars($badge['description']) ?>">
                        🏅 <?= htmlspecialchars($badge['name']) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
