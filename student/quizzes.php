<?php
/**
 * EduTrack Ghana - Quiz Page
 * student/quizzes.php + quiz taking
 */
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/student.php';
requireStudent();

$studentId = $_SESSION['user_id'];
$student = getCurrentUser();

// Handle quiz submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Your session expired. Please reload the quiz and try again.');
    }

    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $answers = $_POST['answer'] ?? [];

    if ($attemptId && !empty($answers)) {
        $result = submitQuizAttempt($studentId, $attemptId, $answers);
        $_SESSION['quiz_result'] = $result;
        header('Location: ' . BASE_URL . '/student/quiz_result.php?attempt=' . $attemptId);
        exit;
    }
}

// Handle start quiz
if (isset($_GET['start']) && is_numeric($_GET['start'])) {
    $quizId = (int)$_GET['start'];
    $attempt = startQuizAttempt($studentId, $quizId);

    if ($attempt['success']) {
        // Show quiz taking interface
        $pageTitle = 'Taking Quiz: ' . $attempt['quiz']['title'];
        require_once __DIR__ . '/../includes/header.php';
        renderQuizInterface($attempt);
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    } else {
        $startError = $attempt['error'];
    }
}

// Default: list quizzes
$quizzes = getAvailableQuizzes($studentId, $student['class_level']);

// Group by subject
$bySubject = [];
foreach ($quizzes as $quiz) {
    $bySubject[$quiz['subject_name']][] = $quiz;
}

$pageTitle = 'Quizzes';
$activeNav = 'quizzes';
require_once __DIR__ . '/../includes/header.php';

function renderQuizInterface(array $attempt): void {
    global $BASE_URL;
    $quiz = $attempt['quiz'];
    $questions = $attempt['questions'];
    $totalQ = count($questions);
    ?>
    <div class="quiz-container" id="quizContainer">
        <div class="quiz-header">
            <h4><?= htmlspecialchars($quiz['title']) ?></h4>
            <div class="quiz-meta">
                <span>📝 <?= $totalQ ?> Questions</span>
                <span>⏱ <span id="timer"><?= $quiz['time_limit_minutes'] * 60 ?></span>s</span>
            </div>
        </div>

        <!-- Progress bar -->
        <div class="quiz-progress">
            <div id="progressBar" class="quiz-progress-bar" style="width:0%"></div>
        </div>
        <div class="text-end text-muted small mb-3">
            Question <span id="currentQ">1</span> of <?= $totalQ ?>
        </div>

        <form method="POST" action="" id="quizForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">
            <input type="hidden" name="attempt_id" value="<?= $attempt['attempt_id'] ?>">
            <input type="hidden" name="submit_quiz" value="1">

            <?php foreach ($questions as $i => $q): ?>
            <div class="question-card <?= $i === 0 ? 'active' : '' ?>" id="q-<?= $i+1 ?>">
                <div class="question-number">Question <?= $i+1 ?> of <?= $totalQ ?></div>
                <div class="question-text"><?= htmlspecialchars($q['question_text']) ?></div>
                <div class="options-grid">
                    <?php foreach (['A','B','C','D'] as $letter): ?>
                    <?php $optKey = 'option_' . strtolower($letter); ?>
                    <label class="option-label" for="q<?= $q['id'] ?>_<?= $letter ?>">
                        <input type="radio" name="answer[<?= $q['id'] ?>]" 
                               id="q<?= $q['id'] ?>_<?= $letter ?>"
                               value="<?= $letter ?>"
                               class="option-radio"
                               onchange="markAnswered(<?= $i+1 ?>)">
                        <span class="option-letter"><?= $letter ?></span>
                        <span class="option-text"><?= htmlspecialchars($q[$optKey]) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Navigation -->
            <div class="quiz-nav">
                <button type="button" class="btn btn-outline-secondary" onclick="prevQ()" id="prevBtn" disabled>
                    ← Previous
                </button>
                <div class="q-dots">
                    <?php for ($i = 0; $i < $totalQ; $i++): ?>
                    <div class="q-dot" id="dot-<?= $i+1 ?>" onclick="goToQ(<?= $i+1 ?>)"><?= $i+1 ?></div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn btn-primary btn-edu" onclick="nextQ()" id="nextBtn">
                    Next →
                </button>
            </div>

            <!-- Submit button (hidden until last question) -->
            <div id="submitArea" style="display:none" class="text-center mt-4">
                <div class="alert alert-info">
                    ✅ You've reached the end! Review your answers and submit when ready.
                </div>
                <button type="submit" class="btn btn-success btn-lg btn-edu px-5" 
                        onclick="return confirm('Submit your answers?')">
                    🚀 Submit Quiz
                </button>
            </div>
        </form>
    </div>

    <script>
    let currentQ = 1;
    const totalQ = <?= $totalQ ?>;
    let timeLeft = <?= $quiz['time_limit_minutes'] * 60 ?>;
    const answered = {};

    // Timer
    const timerInterval = setInterval(() => {
        timeLeft--;
        const m = Math.floor(timeLeft / 60);
        const s = timeLeft % 60;
        document.getElementById('timer').textContent = m + ':' + String(s).padStart(2,'0');
        if (timeLeft <= 60) document.getElementById('timer').style.color = '#ef4444';
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            document.getElementById('quizForm').submit();
        }
    }, 1000);

    function goToQ(n) {
        document.getElementById('q-' + currentQ).classList.remove('active');
        document.getElementById('dot-' + currentQ).classList.remove('current');
        currentQ = n;
        document.getElementById('q-' + currentQ).classList.add('active');
        document.getElementById('dot-' + currentQ).classList.add('current');
        document.getElementById('currentQ').textContent = currentQ;
        document.getElementById('prevBtn').disabled = currentQ === 1;
        document.getElementById('nextBtn').style.display = currentQ === totalQ ? 'none' : 'inline-block';
        document.getElementById('submitArea').style.display = currentQ === totalQ ? 'block' : 'none';
        // Update progress
        document.getElementById('progressBar').style.width = ((currentQ / totalQ) * 100) + '%';
    }

    function nextQ() { if (currentQ < totalQ) goToQ(currentQ + 1); }
    function prevQ() { if (currentQ > 1) goToQ(currentQ - 1); }

    function markAnswered(n) {
        answered[n] = true;
        document.getElementById('dot-' + n).classList.add('answered');
    }

    goToQ(1);
    </script>
    <?php
}
?>

<!-- Quiz Listing Page -->
<?php if (isset($startError)): ?>
<div class="alert alert-warning"><?= htmlspecialchars($startError) ?></div>
<?php endif; ?>

<div class="mb-4">
    <p class="text-muted">Test your knowledge across all subjects. Earn points and badges by passing quizzes! 🎯</p>
</div>

<?php foreach ($bySubject as $subjectName => $subjectQuizzes): ?>
<div class="card edu-card mb-4">
    <div class="card-header-edu">
        <h5 class="mb-0">📖 <?= htmlspecialchars($subjectName) ?></h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($subjectQuizzes as $quiz): ?>
            <div class="col-md-6 col-lg-4">
                <div class="quiz-card">
                    <div class="quiz-card-header">
                        <h6><?= htmlspecialchars($quiz['title']) ?></h6>
                        <span class="badge bg-light text-dark"><?= $quiz['question_count'] ?> Qs</span>
                    </div>
                    <p class="text-muted small mb-2"><?= htmlspecialchars($quiz['description'] ?? '') ?></p>
                    <div class="quiz-meta-row">
                        <span>⏱ <?= $quiz['time_limit_minutes'] ?>min</span>
                        <span>🎯 Pass: <?= $quiz['pass_score'] ?>%</span>
                        <span>🔄 <?= $quiz['attempt_count'] ?>/<?= $quiz['max_attempts'] ?> attempts</span>
                    </div>

                    <?php if ($quiz['best_score'] !== null): ?>
                    <div class="best-score-row">
                        <span>Best: 
                            <strong class="<?= $quiz['best_score'] >= $quiz['pass_score'] ? 'text-success' : 'text-danger' ?>">
                                <?= $quiz['best_score'] ?>%
                            </strong>
                        </span>
                        <?php if ($quiz['best_score'] >= $quiz['pass_score']): ?>
                            <span class="badge bg-success-soft text-success">Passed ✓</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($quiz['attempt_count'] >= $quiz['max_attempts']): ?>
                        <button class="btn btn-secondary btn-sm w-100 mt-2" disabled>
                            Max Attempts Reached
                        </button>
                    <?php else: ?>
                        <a href="?start=<?= $quiz['id'] ?>" class="btn btn-primary btn-sm w-100 mt-2 btn-edu"
                           onclick="return confirm('Start \'<?= addslashes($quiz['title']) ?>\'?')">
                            <?= $quiz['attempt_count'] > 0 ? '🔄 Retry Quiz' : '▶ Start Quiz' ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($quizzes)): ?>
<div class="text-center py-5">
    <div style="font-size:4rem">📝</div>
    <h5>No quizzes available yet</h5>
    <p class="text-muted">Check back soon for new quizzes!</p>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
