<?php
/**
 * EduTrack Ghana - Quiz Result Page
 * student/quiz_result.php
 */
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/student.php';
requireStudent();

$studentId = $_SESSION['user_id'];
$attemptId = (int)($_GET['attempt'] ?? 0);

// Get attempt details
$attempt = dbRow(
    "SELECT qa.*, q.title as quiz_title, q.pass_score, t.title as topic_title, s.name as subject_name
     FROM quiz_attempts qa
     JOIN quizzes q ON qa.quiz_id = q.id
     JOIN topics t ON q.topic_id = t.id
     JOIN subjects s ON t.subject_id = s.id
     WHERE qa.id = ? AND qa.student_id = ?",
    [$attemptId, $studentId]
);

if (!$attempt) {
    header('Location: ' . BASE_URL . '/student/quizzes.php');
    exit;
}

$answersJson = json_decode($attempt['answers_json'] ?? '{}', true);
$questionIds = array_values(array_filter(array_map('intval', json_decode($attempt['question_ids_json'] ?? '[]', true) ?: [])));
if ($questionIds) {
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    $order = implode(',', $questionIds);
    $questions = dbRows(
        "SELECT q.* FROM questions q WHERE q.id IN ($placeholders) ORDER BY FIELD(q.id,$order)",
        $questionIds
    );
} else {
    // Compatibility for attempts created before adaptive question tracking.
    $questions = dbRows('SELECT q.* FROM questions q WHERE q.quiz_id=? ORDER BY q.id', [(int)$attempt['quiz_id']]);
}

$passed = (bool)$attempt['passed'];
$score = (int)$attempt['score'];
$reviewTip = $passed
    ? 'Review the explanations below to keep the ideas fresh before your next topic.'
    : 'Focus on the questions marked wrong, read the explanations, then try the quiz again.';

// Submission awards badges before redirecting here. Preserve that list so the
// result page can celebrate the unlock instead of checking after it is consumed.
$quizResult = $_SESSION['quiz_result'] ?? [];
$newBadges = is_array($quizResult) && isset($quizResult['new_badges'])
    ? $quizResult['new_badges']
    : checkAndAwardBadges($studentId);
unset($_SESSION['quiz_result']);

$pageTitle = 'Quiz Result';
$activeNav = 'quizzes';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Confetti script -->
<?php if ($passed): ?>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
<?php endif; ?>

<!-- Result Hero -->
<div class="result-hero <?= $passed ? 'result-pass' : 'result-fail' ?> mb-4">
    <div class="result-emoji"><?= $passed ? '🎉' : '😤' ?></div>
    <h2><?= $passed ? 'Congratulations!' : 'Keep Trying!' ?></h2>
    <div class="result-score"><?= $score ?>%</div>
    <p class="result-subtitle">
        <?= $passed 
            ? "You passed with $score%! Great work!" 
            : "You scored $score%. You need " . $attempt['pass_score'] . "% to pass. Try again!" ?>
    </p>
    <p class="result-subtitle mb-3"><?= htmlspecialchars($reviewTip) ?></p>
    <div class="result-stats">
        <div class="rs-item">
            <div class="rs-val"><?= $attempt['correct_answers'] ?>/<?= $attempt['total_questions'] ?></div>
            <div class="rs-label">Correct</div>
        </div>
        <div class="rs-item">
            <div class="rs-val"><?= gmdate('i:s', (int)$attempt['time_taken_seconds']) ?></div>
            <div class="rs-label">Time Taken</div>
        </div>
        <div class="rs-item">
            <div class="rs-val"><?= $passed ? '+' . (($attempt['correct_answers'] * 10) + 25) : ($attempt['correct_answers'] * 10) ?></div>
            <div class="rs-label">Points Earned</div>
        </div>
    </div>
</div>

<!-- New Badges Alert -->
<?php if (!empty($newBadges)): ?>
<div class="card edu-card mb-4 border-warning">
    <div class="card-body text-center">
        <h5>🏆 New Badge<?= count($newBadges) > 1 ? 's' : '' ?> Earned!</h5>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <?php foreach ($newBadges as $badge): ?>
            <div class="badge-earned-card">
                <div class="badge-icon" style="color:<?= $badge['color'] ?>">🏅</div>
                <div class="badge-name"><?= htmlspecialchars($badge['name']) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($badge['description']) ?></div>
                <div class="text-success small">+<?= $badge['points_reward'] ?> pts</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Answer Review -->
<div class="card edu-card mb-4">
    <div class="card-body">
        <h5 class="card-title mb-4">📋 Answer Review</h5>
        <?php foreach ($questions as $i => $q): ?>
        <?php
        $qAnswers = $answersJson[$q['id']] ?? null;
        $userAnswer = $qAnswers['user_answer'] ?? '';
        $isCorrect = $qAnswers['is_correct'] ?? false;
        ?>
        <div class="review-question <?= $isCorrect ? 'review-correct' : 'review-wrong' ?>">
            <div class="review-header">
                <span class="review-num"><?= $i+1 ?></span>
                <span class="review-q"><?= htmlspecialchars($q['question_text']) ?></span>
                <span class="review-status"><?= $isCorrect ? '✅' : '❌' ?></span>
            </div>
            <div class="review-options">
                <?php foreach (['A','B','C','D'] as $letter): ?>
                <?php $optKey = 'option_' . strtolower($letter); ?>
                <div class="review-option 
                    <?= $letter === $q['correct_answer'] ? 'correct-option' : '' ?>
                    <?= $letter === $userAnswer && !$isCorrect ? 'wrong-option' : '' ?>">
                    <span class="opt-letter"><?= $letter ?></span>
                    <?= htmlspecialchars($q[$optKey]) ?>
                    <?php if ($letter === $q['correct_answer']): ?>
                        <span class="ms-1">✓</span>
                    <?php endif; ?>
                    <?php if ($letter === $userAnswer && !$isCorrect): ?>
                        <span class="ms-1">✗</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="review-feedback">
                <span>Your answer: <?= $userAnswer ? htmlspecialchars($userAnswer) : 'Not answered' ?></span>
                <span>Correct answer: <?= htmlspecialchars($q['correct_answer']) ?></span>
            </div>
            <?php if ($q['explanation']): ?>
            <div class="review-explanation">
                💡 <strong>Explanation:</strong> <?= htmlspecialchars($q['explanation']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Actions -->
<div class="d-flex gap-3 flex-wrap mb-4">
    <a href="<?= BASE_URL ?>/student/quizzes.php" class="btn btn-primary btn-edu">
        📝 Back to Quizzes
    </a>
    <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn btn-outline-primary btn-edu">
        🏠 Dashboard
    </a>
    <?php if (!$passed): ?>
    <a href="<?= BASE_URL ?>/student/quizzes.php?start=<?= $attempt['quiz_id'] ?>" 
       class="btn btn-warning btn-edu"
       onclick="return confirm('Try this quiz again?')">
        🔄 Try Again
    </a>
    <?php endif; ?>
</div>

<?php if ($passed): ?>
<script>
// Confetti animation
window.onload = function() {
    const duration = 3000;
    const end = Date.now() + duration;
    
    (function frame() {
        confetti({
            particleCount: 3,
            angle: 60,
            spread: 55,
            origin: { x: 0 },
            colors: ['#4F46E5', '#7C3AED', '#EC4899', '#F59E0B']
        });
        confetti({
            particleCount: 3,
            angle: 120,
            spread: 55,
            origin: { x: 1 },
            colors: ['#4F46E5', '#7C3AED', '#EC4899', '#F59E0B']
        });
        if (Date.now() < end) {
            requestAnimationFrame(frame);
        }
    }());
};
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
