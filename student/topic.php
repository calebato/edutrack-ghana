<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/student.php';
requireStudent();

$studentId = $_SESSION['user_id'];
$student = getCurrentUser();
$topicId = (int)($_GET['id'] ?? 0);

$topic = dbRow(
    "SELECT t.*, s.name as subject_name, s.color 
     FROM topics t JOIN subjects s ON t.subject_id = s.id
     WHERE t.id = ? AND t.class_level = ? AND t.approval_status='approved' AND t.is_active=1
       AND (t.school_id IS NULL OR t.school_id = ?)",
    [$topicId, $student['class_level'], (int)$student['school_id']]
);

if (!$topic) { header('Location: ' . BASE_URL . '/student/subjects.php'); exit; }

// Start/track progress
startTopic($studentId, $topicId);
$prog = dbRow(
    "SELECT * FROM topic_progress WHERE student_id = ? AND topic_id = ?",
    [$studentId, $topicId]
);
$studyGuide = getLearningPreferenceGuide($student['learning_style'] ?? 'visual');
$studyTips = getSubjectStudyTips($topic['subject_name'], $student['learning_style'] ?? 'visual');

// Mark complete if requested
if (isset($_GET['complete'])) {
    $newlyCompleted = completeTopic($studentId, $topicId);
    $_SESSION['flash'] = $newlyCompleted
        ? 'Topic completed! +20 points earned! 🎉'
        : 'This topic was already completed. No additional points were awarded.';
    $_SESSION['flash_type'] = $newlyCompleted ? 'success' : 'warning';
    header('Location: ' . BASE_URL . '/student/subject_topics.php?id=' . $topic['subject_id']);
    exit;
}

// Get quizzes for this topic
$quizzes = dbRows(
    "SELECT q.*, 
     (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as q_count,
     (SELECT MAX(score) FROM quiz_attempts WHERE quiz_id = q.id AND student_id = ?) as best_score
     FROM quizzes q WHERE q.topic_id = ? AND q.is_active = 1",
    [$studentId, $topicId]
);

$pageTitle = $topic['title'];
$activeNav = 'subjects';
require_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['flash'])):
    $flashType = $_SESSION['flash_type'] ?? 'success';
    echo '<div class="alert alert-' . htmlspecialchars($flashType) . ' alert-dismissible">' . htmlspecialchars($_SESSION['flash']) . 
         '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
endif;
?>

<!-- Topic Header -->
<div class="topic-header-card mb-4" style="border-left:4px solid <?= $topic['color'] ?>">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <div class="text-muted small mb-1">
                <a href="<?= BASE_URL ?>/student/subject_topics.php?id=<?= $topic['subject_id'] ?>">
                    ← <?= htmlspecialchars($topic['subject_name']) ?>
                </a>
            </div>
            <h3><?= htmlspecialchars($topic['title']) ?></h3>
            <div class="d-flex gap-2">
                <span class="difficulty-badge diff-<?= $topic['difficulty'] ?>"><?= ucfirst($topic['difficulty']) ?></span>
                <span class="text-muted small">⏱ ~<?= $topic['estimated_minutes'] ?> minutes</span>
                <span class="text-muted small">📚 <?= htmlspecialchars($topic['subject_name']) ?></span>
            </div>
        </div>
        <?php if (($prog['status'] ?? '') === 'completed'): ?>
            <span class="btn btn-outline-success btn-edu disabled" aria-disabled="true">✅ Completed</span>
        <?php else: ?>
            <a href="?id=<?= $topicId ?>&complete=1"
               class="btn btn-success btn-edu"
               onclick="return confirm('Mark this topic as complete and earn 20 points?')">
                ✅ Mark Complete
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Topic Content -->
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card edu-card mb-4 border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                    <div>
                        <h5 class="card-title mb-1"><?= $studyGuide['icon'] ?> <?= htmlspecialchars($studyGuide['title']) ?></h5>
                        <p class="text-muted mb-0"><?= htmlspecialchars($studyGuide['summary']) ?></p>
                    </div>
                    <a href="<?= BASE_URL ?>/student/profile.php" class="btn btn-sm btn-outline-primary">Change preference</a>
                </div>
                <div class="row g-2 mt-2">
                    <?php foreach ($studyGuide['steps'] as $step): ?>
                        <div class="col-md-4">
                            <div class="p-2 rounded bg-light h-100">✓ <?= htmlspecialchars($step) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card edu-card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">📖 Learning Content</h5>
               <div class="speech-toolbar mb-3">
    <div class="alert alert-info mb-3">
        <?php if (($student['learning_style'] ?? 'visual') === 'auditory'): ?>
            🎧 Auditory mode: Use Listen, pause when needed, and repeat the main ideas aloud.
        <?php else: ?>
            ♿ Accessibility feature: Select Listen to hear this lesson read aloud.
        <?php endif; ?>
    </div>

<div class="speech-toolbar mb-3">

    <button class="btn btn-primary btn-sm" onclick="readContent()">
        🔊 <?= (($student['learning_style'] ?? 'visual') === 'auditory') ? 'Start Audio Lesson' : 'Listen' ?>
    </button>

    <button class="btn btn-danger btn-sm" onclick="stopReading()">
        ⏹ Stop
    </button>

</div>
</div>

                <div class="topic-content-body">
                    <?php
                    // Render content with some basic formatting
                    $content = $topic['content'] ?? 'Content coming soon!';
                    $paragraphs = explode('. ', $content);
                    echo '<p>' . nl2br(htmlspecialchars($content)) . '</p>';
                    ?>
                </div>

                <!-- Key Points -->
                <div class="key-points mt-4">
                    <h6>💡 Key Points to Remember:</h6>
                    <ul>
                        <li>Read and understand the main concept before attempting quizzes</li>
                        <li>Take notes as you learn for better retention</li>
                        <li>Complete all quizzes to earn maximum points</li>
                        <li>Review explanations for any wrong answers</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Quizzes for this topic -->
        <?php if (!empty($quizzes)): ?>
        <div class="card edu-card">
            <div class="card-body">
                <h5 class="card-title mb-3">❓ Practice Quizzes</h5>
                <div class="row g-3">
                    <?php foreach ($quizzes as $quiz): ?>
                    <div class="col-md-6">
                        <div class="quiz-card">
                            <h6><?= htmlspecialchars($quiz['title']) ?></h6>
                            <div class="quiz-meta-row">
                                <span>📝 <?= $quiz['q_count'] ?> questions</span>
                                <span>⏱ <?= $quiz['time_limit_minutes'] ?>min</span>
                            </div>
                            <?php if ($quiz['best_score'] !== null): ?>
                                <div class="mt-2">
                                    Best: <strong class="<?= $quiz['best_score']>=60?'text-success':'text-danger' ?>">
                                        <?= $quiz['best_score'] ?>%
                                    </strong>
                                </div>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>/student/quizzes.php?start=<?= $quiz['id'] ?>" 
                               class="btn btn-primary btn-sm btn-edu mt-2 w-100"
                               onclick="return confirm('Start this quiz?')">
                                ▶ Take Quiz
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <!-- Study Tips -->
        <div class="card edu-card mb-4">
            <div class="card-body">
                <h6 class="card-title">📌 Study Tips</h6>
                <div class="study-tips">
                    <?php foreach ($studyTips as $tip): ?>
                        <div class="tip-item"><?= htmlspecialchars($tip) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Progress tracker -->
        <div class="card edu-card">
            <div class="card-body text-center">
                <h6>Your Progress</h6>
                <?php
                $status = $prog['status'] ?? 'in_progress';
                ?>
                <?php if ($status === 'completed'): ?>
                    <div style="font-size:3rem">✅</div>
                    <p class="text-success fw-600">Topic Completed!</p>
                    <p class="text-muted small">Completed on <?= date('M d, Y', strtotime($prog['completed_at'])) ?></p>
                <?php else: ?>
                    <div style="font-size:3rem">📖</div>
                    <p class="text-warning fw-600">In Progress</p>
                    <a href="?id=<?= $topicId ?>&complete=1" class="btn btn-success btn-edu w-100"
                       onclick="return confirm('Mark as complete?')">
                        ✅ Mark as Complete
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function readContent() {

    const content = document.querySelector('.topic-content-body');

    if (!content) return;

    window.speechSynthesis.cancel();

    const speech = new SpeechSynthesisUtterance(
        content.innerText
    );

    speech.lang = 'en-US';
    speech.rate = 0.9;

    window.speechSynthesis.speak(speech);
}

function stopReading() {
    window.speechSynthesis.cancel();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
