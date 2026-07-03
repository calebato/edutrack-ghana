<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/student.php';
requireStudent();

$studentId = $_SESSION['user_id'];
$student = getCurrentUser();
$subjects = getSubjectsWithProgress($studentId, $student['class_level']);

$pageTitle = 'My Subjects';
$activeNav = 'subjects';
require_once __DIR__ . '/../includes/header.php';
?>

<p class="text-muted mb-4">Explore all subjects for <?= $student['class_level'] ?>. Track your progress and master every topic.</p>

<div class="row g-4">
    <?php foreach ($subjects as $subject): ?>
    <div class="col-md-6 col-lg-4">
        <div class="subject-card" onclick="window.location='<?= BASE_URL ?>/student/subject_topics.php?id=<?= $subject['id'] ?>'">
            <div class="subject-icon-wrap" style="background:<?= $subject['color'] ?>20">
                <span style="font-size:2rem">
                    <?php
                    $icons = ['Mathematics'=>'🔢','English Language'=>'📖','Integrated Science'=>'🔬',
                              'Social Studies'=>'🌍','ICT'=>'💻','French'=>'🇫🇷',
                              'Religious & Moral Education'=>'🕊️','Ghanaian Language'=>'🗣️'];
                    echo $icons[$subject['name']] ?? '📚';
                    ?>
                </span>
            </div>
            <h5 class="subject-name"><?= htmlspecialchars($subject['name']) ?></h5>
            <p class="text-muted small mb-3"><?= htmlspecialchars($subject['description'] ?? '') ?></p>

            <div class="progress mb-2" style="height:6px">
                <div class="progress-bar" style="width:<?= $subject['progress_pct'] ?>%;background:<?= $subject['color'] ?>"></div>
            </div>
            <div class="d-flex justify-content-between text-muted small">
                <span><?= $subject['completed_topics'] ?>/<?= $subject['total_topics'] ?> topics</span>
                <span><?= $subject['progress_pct'] ?>%</span>
            </div>

            <?php if ($subject['avg_score'] > 0): ?>
            <div class="mt-2">
                <span class="score-badge <?= $subject['avg_score'] >= 60 ? 'score-pass' : 'score-fail' ?>">
                    Avg: <?= $subject['avg_score'] ?>%
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
