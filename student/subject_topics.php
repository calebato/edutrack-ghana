<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/student.php';
requireStudent();

$studentId = $_SESSION['user_id'];
$student = getCurrentUser();
$subjectId = (int)($_GET['id'] ?? 0);

$subject = dbRow("SELECT * FROM subjects WHERE id = ?", [$subjectId]);
if (!$subject) { header('Location: ' . BASE_URL . '/student/subjects.php'); exit; }

$topics = getTopicsForSubject($subjectId, $studentId, $student['class_level']);

$pageTitle = $subject['name'] . ' Topics';
$activeNav = 'subjects';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_SESSION['flash'])): ?>
    <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'success') ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_SESSION['flash']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= BASE_URL ?>/student/subjects.php" class="btn btn-sm btn-outline-secondary">← Back</a>
    <div>
        <h4 class="mb-0"><?= htmlspecialchars($subject['name']) ?></h4>
        <p class="text-muted small mb-0"><?= $student['class_level'] ?> • <?= count($topics) ?> topics</p>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($topics as $i => $topic): ?>
    <?php
    $status = $topic['status'] ?? 'not_started';
    $pct = (int)($topic['completion_percent'] ?? 0);
    ?>
    <div class="col-12">
        <div class="topic-row <?= $status ?>">
            <div class="topic-num"><?= $i+1 ?></div>
            <div class="topic-content">
                <div class="topic-title"><?= htmlspecialchars($topic['title']) ?></div>
                <div class="topic-meta">
                    <span class="difficulty-badge diff-<?= $topic['difficulty'] ?>"><?= ucfirst($topic['difficulty']) ?></span>
                    <span class="text-muted small">⏱ ~<?= $topic['estimated_minutes'] ?> min</span>
                    <?php if ($topic['quiz_count'] > 0): ?>
                        <span class="text-muted small">❓ <?= $topic['quiz_count'] ?> quiz</span>
                    <?php endif; ?>
                </div>
                <?php if ($status === 'in_progress'): ?>
                <div class="progress mt-2" style="height:4px;max-width:200px">
                    <div class="progress-bar bg-warning" style="width:<?= $pct ?>%"></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="topic-status">
                <?php if ($status === 'completed'): ?>
                    <span class="badge bg-success-soft text-success">✓ Done</span>
                <?php elseif ($status === 'in_progress'): ?>
                    <span class="badge bg-warning-soft text-warning">In Progress</span>
                <?php else: ?>
                    <span class="badge bg-light text-muted">Not Started</span>
                <?php endif; ?>
            </div>
            <div class="topic-action">
                <a href="<?= BASE_URL ?>/student/topic.php?id=<?= $topic['id'] ?>" 
                   class="btn btn-sm btn-edu <?= $status === 'completed' ? 'btn-outline-success' : 'btn-primary' ?>">
                    <?= $status === 'completed' ? 'Review' : ($status === 'in_progress' ? 'Continue' : 'Start') ?>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
