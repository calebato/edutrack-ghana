<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/teacher.php';
require_once __DIR__ . '/../includes/question_quality.php';
requireTeacher();

$teacher = getCurrentUser();
$teacherId = (int)$teacher['id'];
$schoolId = (int)$teacher['school_id'];
$assignedClasses = teacherAssignedClasses($teacher);
[$topicClassSql, $topicClassParams] = teacherClassSql('t.class_level', $assignedClasses);
$errors = [];
$flash = $_SESSION['quiz_flash'] ?? '';
unset($_SESSION['quiz_flash']);

$isGeneral = $teacher['subject'] === 'General';
$subject = $isGeneral ? ['id' => 0, 'name' => 'General'] : dbRow('SELECT id,name FROM subjects WHERE name=?', [$teacher['subject']]);
if ($isGeneral) {
    $topics = dbRows(
        "SELECT t.id,t.title,t.class_level,s.name AS subject_name
         FROM topics t JOIN subjects s ON s.id=t.subject_id
         WHERE t.approval_status='approved' AND t.is_active=1
           AND $topicClassSql
           AND (t.school_id IS NULL OR t.school_id=?)
         ORDER BY s.name,FIELD(t.class_level,'JHS1','JHS2','JHS3'),t.sequence_order,t.title",
        array_merge($topicClassParams, [$schoolId])
    );
} else {
    $topics = $subject ? dbRows(
        "SELECT t.id,t.title,t.class_level,s.name AS subject_name
         FROM topics t JOIN subjects s ON s.id=t.subject_id
         WHERE t.subject_id=? AND t.approval_status='approved' AND t.is_active=1
           AND $topicClassSql
           AND (t.school_id IS NULL OR t.school_id=?)
         ORDER BY FIELD(t.class_level,'JHS1','JHS2','JHS3'),t.sequence_order,t.title",
        array_merge([(int)$subject['id']], $topicClassParams, [$schoolId])
    ) : [];
}
$allowedTopicIds = array_map(static fn(array $topic): int => (int)$topic['id'], $topics);

$quizId = (int)($_GET['edit'] ?? $_POST['quiz_id'] ?? 0);
$editingQuiz = null;
$editingQuestions = [];
$attemptCount = 0;

if ($quizId) {
    $editingQuiz = dbRow(
        "SELECT q.*,t.title AS topic_title,t.class_level,
                (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id=q.id) AS attempt_count
         FROM quizzes q JOIN topics t ON t.id=q.topic_id
         WHERE q.id=? AND q.teacher_id=?",
        [$quizId, $teacherId]
    );
    if (!$editingQuiz) {
        $errors[] = 'Quiz not found or you do not have permission to edit it.';
        $quizId = 0;
    } else {
        $attemptCount = (int)$editingQuiz['attempt_count'];
        $editingQuestions = dbRows('SELECT * FROM questions WHERE quiz_id=? ORDER BY id', [$quizId]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quiz'])) {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) $errors[] = 'Your session expired. Please try again.';

    $topicId = (int)($_POST['topic_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $timeLimit = max(1, min(180, (int)($_POST['time_limit'] ?? 15)));
    $passScore = max(0, min(100, (int)($_POST['pass_score'] ?? 60)));
    $maxAttempts = max(1, min(10, (int)($_POST['max_attempts'] ?? 3)));
    $publish = ($_POST['save_mode'] ?? 'draft') === 'publish';

    if ($attemptCount && $editingQuiz) $topicId = (int)$editingQuiz['topic_id'];

    if (!in_array($topicId, $allowedTopicIds, true)) $errors[] = 'Choose an approved topic available to your account.';
    if ($title === '' || mb_strlen($title) > 200) $errors[] = 'Enter a quiz title of 200 characters or fewer.';
    $selectedTopic = null;
    foreach ($topics as $topicOption) {
        if ((int)$topicOption['id'] === $topicId) {
            $selectedTopic = $topicOption;
            break;
        }
    }

    $questionRows = [];
    if (!$attemptCount) {
        if (count($_POST['question_text'] ?? []) > 10) $errors[] = 'A quiz may contain no more than 10 questions.';
        foreach (array_slice($_POST['question_text'] ?? [], 0, 10) as $index => $questionText) {
            $questionText = trim($questionText);
            $options = [
                trim($_POST['option_a'][$index] ?? ''), trim($_POST['option_b'][$index] ?? ''),
                trim($_POST['option_c'][$index] ?? ''), trim($_POST['option_d'][$index] ?? '')
            ];
            $answer = $_POST['correct_answer'][$index] ?? 'A';
            $explanation = trim($_POST['explanation'][$index] ?? '');
            $difficulty = $_POST['question_difficulty'][$index] ?? 'easy';
            $bloomLevel = $_POST['bloom_level'][$index] ?? 'remember';
            $points = max(1, min(100, (int)($_POST['points'][$index] ?? 10)));

            if ($questionText === '' && implode('', $options) === '') continue;
            if ($questionText === '' || in_array('', $options, true)) {
                $errors[] = 'Question ' . ($index + 1) . ' needs text and all four options.';
                continue;
            }
            if (!in_array($answer, ['A','B','C','D'], true)) $answer = 'A';
            if (!in_array($difficulty, ['easy','medium','hard'], true)) $difficulty = 'easy';
            if (!in_array($bloomLevel, ['remember','understand','apply','analyze','evaluate','create'], true)) $bloomLevel = 'remember';
            $quality = assessQuestionQuality(
                $questionText,
                $options,
                $bloomLevel,
                (string)($selectedTopic['class_level'] ?? ''),
                (string)($selectedTopic['title'] ?? '')
            );
            foreach ($quality['errors'] as $qualityError) {
                $errors[] = 'Question ' . ($index + 1) . ': ' . $qualityError;
            }
            $questionRows[] = compact('questionText', 'options', 'answer', 'explanation', 'difficulty', 'bloomLevel', 'points');
        }
        if ($publish && !$questionRows) $errors[] = 'A published quiz needs at least one complete question.';
        if (count($questionRows) > 10) $errors[] = 'A quiz may contain no more than 10 questions.';
    }

    if ($quizId && !$editingQuiz) $errors[] = 'You may only edit quizzes that you created.';

    if (!$errors) {
        $pdo = getDB();
        $pdo->beginTransaction();
        try {
            if ($quizId) {
                dbQuery(
                    'UPDATE quizzes SET topic_id=?,title=?,description=?,time_limit_minutes=?,pass_score=?,max_attempts=?,is_active=? WHERE id=? AND teacher_id=?',
                    [$topicId, $title, $description, $timeLimit, $passScore, $maxAttempts, $publish ? 1 : 0, $quizId, $teacherId]
                );
            } else {
                $quizId = dbInsert(
                    'INSERT INTO quizzes (teacher_id,topic_id,title,description,time_limit_minutes,pass_score,max_attempts,is_active) VALUES (?,?,?,?,?,?,?,?)',
                    [$teacherId, $topicId, $title, $description, $timeLimit, $passScore, $maxAttempts, $publish ? 1 : 0]
                );
            }

            if (!$attemptCount) {
                dbQuery('DELETE FROM questions WHERE quiz_id=?', [$quizId]);
                foreach ($questionRows as $row) {
                    dbInsert(
                        'INSERT INTO questions (quiz_id,question_text,option_a,option_b,option_c,option_d,correct_answer,explanation,points,difficulty,bloom_level) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                        [$quizId, $row['questionText'], $row['options'][0], $row['options'][1], $row['options'][2], $row['options'][3],
                         $row['answer'], $row['explanation'], $row['points'], $row['difficulty'], $row['bloomLevel']]
                    );
                }
            }
            $pdo->commit();
            logActivity($teacherId, 'teacher', $publish ? 'quiz_published' : 'quiz_saved_draft', 'Quiz #' . $quizId . ': ' . $title);
            $_SESSION['quiz_flash'] = $publish ? 'Quiz published successfully.' : 'Quiz saved as a draft.';
            header('Location: ' . BASE_URL . '/teacher/create_quiz.php?edit=' . $quizId);
            exit;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'The quiz could not be saved. Please try again.';
        }
    }

    $editingQuiz = [
        'id' => $quizId, 'topic_id' => $topicId, 'title' => $title, 'description' => $description,
        'time_limit_minutes' => $timeLimit, 'pass_score' => $passScore, 'max_attempts' => $maxAttempts,
        'is_active' => $publish ? 1 : 0, 'attempt_count' => $attemptCount
    ];
    if (!$attemptCount) {
        $editingQuestions = array_map(static fn(array $row): array => [
            'question_text' => $row['questionText'], 'option_a' => $row['options'][0], 'option_b' => $row['options'][1],
            'option_c' => $row['options'][2], 'option_d' => $row['options'][3], 'correct_answer' => $row['answer'],
            'explanation' => $row['explanation'], 'points' => $row['points'], 'difficulty' => $row['difficulty'],
            'bloom_level' => $row['bloomLevel']
        ], $questionRows);
    }
}

if (!$editingQuestions) {
    $editingQuestions = [[
        'question_text' => '', 'option_a' => '', 'option_b' => '', 'option_c' => '', 'option_d' => '',
        'correct_answer' => 'A', 'explanation' => '', 'points' => 10, 'difficulty' => 'easy', 'bloom_level' => 'remember'
    ]];
}

$myQuizzes = dbRows(
    "SELECT q.*,t.title AS topic_title,t.class_level,
            (SELECT COUNT(*) FROM questions qu WHERE qu.quiz_id=q.id) AS question_count,
            (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id=q.id) AS attempt_count
     FROM quizzes q JOIN topics t ON t.id=q.topic_id
     WHERE q.teacher_id=? ORDER BY q.created_at DESC,q.id DESC",
    [$teacherId]
);

$pageTitle = $quizId ? 'Edit Quiz' : 'Create Quiz';
$activeNav = 'create_quiz';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h4 class="mb-1"><?= $quizId ? 'Edit Quiz' : 'Create a Quiz' ?></h4><p class="text-muted mb-0">Build up to 10 questions for approved <?= $isGeneral ? 'topics across all subjects' : htmlspecialchars($teacher['subject']) . ' topics' ?>.</p></div>
    <?php if ($quizId): ?><a href="create_quiz.php" class="btn btn-outline-primary">Create New Quiz</a><?php endif; ?>
</div>

<?php if (!$subject): ?><div class="alert alert-warning">Ask an administrator to assign a valid subject before creating quizzes.</div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($attemptCount): ?><div class="alert alert-info">This quiz has <?= $attemptCount ?> student attempt<?= $attemptCount===1?'':'s' ?>. Questions are locked to preserve historical results; quiz details and publication status may still be changed.</div><?php endif; ?>

<?php if ($subject): ?>
<form method="post" id="quizEditor">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">
    <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
    <input type="hidden" name="save_mode" id="saveMode" value="draft">

    <div class="card edu-card mb-4"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="card-title mb-0">Quiz Details</h5><span class="badge <?= !empty($editingQuiz['is_active'])?'bg-success':'bg-secondary' ?>"><?= !empty($editingQuiz['is_active'])?'Published':'Draft' ?></span></div>
        <div class="row g-3">
            <div class="col-md-7"><label class="form-label">Approved Topic</label><?php if($attemptCount): ?><input type="hidden" name="topic_id" value="<?= (int)$editingQuiz['topic_id'] ?>"><?php endif; ?><select name="<?= $attemptCount?'locked_topic':'topic_id' ?>" class="form-select" required <?= $attemptCount?'disabled':'' ?>><?php if(!$topics): ?><option value="">No approved topics available</option><?php else: ?><option value="">Choose a topic</option><?php foreach($topics as $topic): ?><option value="<?= (int)$topic['id'] ?>" <?= (int)($editingQuiz['topic_id']??0)===(int)$topic['id']?'selected':'' ?>><?= htmlspecialchars($topic['class_level'].' — '.$topic['title']) ?></option><?php endforeach; ?><?php endif; ?></select></div>
            <div class="col-md-5"><label class="form-label">Quiz Title</label><input class="form-control" name="title" maxlength="200" required value="<?= htmlspecialchars($editingQuiz['title']??'') ?>"></div>
            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($editingQuiz['description']??'') ?></textarea></div>
            <div class="col-md-4"><label class="form-label">Time Limit (minutes)</label><input class="form-control" type="number" name="time_limit" min="1" max="180" value="<?= (int)($editingQuiz['time_limit_minutes']??15) ?>"></div>
            <div class="col-md-4"><label class="form-label">Pass Score (%)</label><input class="form-control" type="number" name="pass_score" min="0" max="100" value="<?= (int)($editingQuiz['pass_score']??60) ?>"></div>
            <div class="col-md-4"><label class="form-label">Maximum Attempts</label><input class="form-control" type="number" name="max_attempts" min="1" max="10" value="<?= (int)($editingQuiz['max_attempts']??3) ?>"></div>
        </div>
    </div></div>

    <div class="card edu-card mb-4"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3"><div><h5 class="card-title mb-1">Questions</h5><div class="small text-muted"><span id="questionCount"><?= count($editingQuestions) ?></span>/10 questions · Easy: Remember/Understand · Medium: Apply/Analyze · Hard: Evaluate (Create suits project work)</div></div><?php if(!$attemptCount): ?><button type="button" class="btn btn-outline-primary" id="addQuestion">+ Add Question</button><?php endif; ?></div>
        <div id="questionsContainer">
        <?php foreach($editingQuestions as $index=>$question): ?>
            <div class="question-editor border rounded-3 p-3 mb-3 bg-light" data-question>
                <div class="d-flex justify-content-between align-items-center mb-3"><strong>Question <span data-number><?= $index+1 ?></span></strong><?php if(!$attemptCount): ?><button type="button" class="btn btn-sm btn-outline-danger" data-remove>Remove</button><?php endif; ?></div>
                <div class="mb-3"><label class="form-label">Question text</label><textarea class="form-control" name="question_text[]" rows="2" <?= $attemptCount?'disabled':'' ?>><?= htmlspecialchars($question['question_text']) ?></textarea></div>
                <div class="row g-3 mb-3"><?php foreach(['a'=>'A','b'=>'B','c'=>'C','d'=>'D'] as $key=>$label): ?><div class="col-md-6"><label class="form-label">Option <?= $label ?></label><input class="form-control" name="option_<?= $key ?>[]" <?= $attemptCount?'disabled':'' ?> value="<?= htmlspecialchars($question['option_'.$key]) ?>"></div><?php endforeach; ?></div>
                <div class="row g-3">
                    <div class="col-md-2"><label class="form-label">Correct Answer</label><select class="form-select" name="correct_answer[]" <?= $attemptCount?'disabled':'' ?>><?php foreach(['A','B','C','D'] as $answer): ?><option <?= $question['correct_answer']===$answer?'selected':'' ?>><?= $answer ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-2"><label class="form-label">Difficulty</label><select class="form-select" name="question_difficulty[]" <?= $attemptCount?'disabled':'' ?>><?php foreach(['easy','medium','hard'] as $difficulty): ?><option value="<?= $difficulty ?>" <?= $question['difficulty']===$difficulty?'selected':'' ?>><?= ucfirst($difficulty) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label">Bloom's Level</label><select class="form-select" name="bloom_level[]" <?= $attemptCount?'disabled':'' ?>><?php foreach(['remember','understand','apply','analyze','evaluate','create'] as $level): ?><option value="<?= $level ?>" <?= ($question['bloom_level']??'remember')===$level?'selected':'' ?>><?= ucfirst($level) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-2"><label class="form-label">Points</label><input class="form-control" type="number" name="points[]" min="1" max="100" <?= $attemptCount?'disabled':'' ?> value="<?= (int)$question['points'] ?>"></div>
                    <div class="col-md-3"><label class="form-label">Answer Explanation</label><input class="form-control" name="explanation[]" <?= $attemptCount?'disabled':'' ?> value="<?= htmlspecialchars($question['explanation']??'') ?>"></div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div></div>

    <div class="d-flex flex-wrap gap-2 mb-5">
        <button type="submit" name="save_quiz" class="btn btn-outline-primary" onclick="document.getElementById('saveMode').value='draft'">Save Draft</button>
        <button type="button" class="btn btn-outline-secondary" id="previewQuiz">Preview</button>
        <button type="submit" name="save_quiz" class="btn btn-primary" onclick="document.getElementById('saveMode').value='publish'">Publish Quiz</button>
    </div>
</form>
<?php endif; ?>

<div class="card edu-card mb-4"><div class="card-body"><h5 class="card-title mb-3">My Quizzes</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Quiz</th><th>Topic</th><th>Questions</th><th>Attempts</th><th>Status</th><th></th></tr></thead><tbody><?php if(!$myQuizzes): ?><tr><td colspan="6" class="text-muted">You have not created any quizzes yet.</td></tr><?php endif; ?><?php foreach($myQuizzes as $quiz): ?><tr><td><strong><?= htmlspecialchars($quiz['title']) ?></strong></td><td><?= htmlspecialchars($quiz['class_level'].' — '.$quiz['topic_title']) ?></td><td><?= (int)$quiz['question_count'] ?></td><td><?= (int)$quiz['attempt_count'] ?></td><td><span class="badge <?= $quiz['is_active']?'bg-success':'bg-secondary' ?>"><?= $quiz['is_active']?'Published':'Draft' ?></span></td><td><a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$quiz['id'] ?>">Edit</a></td></tr><?php endforeach; ?></tbody></table></div></div></div>

<div class="modal fade" id="quizPreviewModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="previewTitle">Quiz Preview</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="previewBody"></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>

<template id="questionTemplate"><div class="question-editor border rounded-3 p-3 mb-3 bg-light" data-question><div class="d-flex justify-content-between align-items-center mb-3"><strong>Question <span data-number></span></strong><button type="button" class="btn btn-sm btn-outline-danger" data-remove>Remove</button></div><div class="mb-3"><label class="form-label">Question text</label><textarea class="form-control" name="question_text[]" rows="2"></textarea></div><div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label">Option A</label><input class="form-control" name="option_a[]"></div><div class="col-md-6"><label class="form-label">Option B</label><input class="form-control" name="option_b[]"></div><div class="col-md-6"><label class="form-label">Option C</label><input class="form-control" name="option_c[]"></div><div class="col-md-6"><label class="form-label">Option D</label><input class="form-control" name="option_d[]"></div></div><div class="row g-3"><div class="col-md-2"><label class="form-label">Correct Answer</label><select class="form-select" name="correct_answer[]"><option>A</option><option>B</option><option>C</option><option>D</option></select></div><div class="col-md-2"><label class="form-label">Difficulty</label><select class="form-select" name="question_difficulty[]"><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></div><div class="col-md-3"><label class="form-label">Bloom's Level</label><select class="form-select" name="bloom_level[]"><option value="remember">Remember</option><option value="understand">Understand</option><option value="apply">Apply</option><option value="analyze">Analyze</option><option value="evaluate">Evaluate</option><option value="create">Create</option></select></div><div class="col-md-2"><label class="form-label">Points</label><input class="form-control" type="number" name="points[]" min="1" max="100" value="10"></div><div class="col-md-3"><label class="form-label">Answer Explanation</label><input class="form-control" name="explanation[]"></div></div></div></template>

<script>
document.addEventListener('DOMContentLoaded',function(){
 const container=document.getElementById('questionsContainer'),add=document.getElementById('addQuestion'),count=document.getElementById('questionCount');
 const refresh=()=>{if(!container)return;container.querySelectorAll('[data-question]').forEach((box,i)=>box.querySelector('[data-number]').textContent=i+1);if(count)count.textContent=container.querySelectorAll('[data-question]').length;if(add)add.disabled=container.querySelectorAll('[data-question]').length>=10;};
 if(add)add.addEventListener('click',()=>{if(container.querySelectorAll('[data-question]').length>=10)return;container.appendChild(document.getElementById('questionTemplate').content.cloneNode(true));refresh();});
 if(container)container.addEventListener('click',e=>{const button=e.target.closest('[data-remove]');if(!button)return;const boxes=container.querySelectorAll('[data-question]');if(boxes.length===1){boxes[0].querySelectorAll('input,textarea').forEach(input=>input.value='');return;}button.closest('[data-question]').remove();refresh();});
 const preview=document.getElementById('previewQuiz');if(preview)preview.addEventListener('click',()=>{document.getElementById('previewTitle').textContent=document.querySelector('[name="title"]').value||'Untitled Quiz';let html='';container.querySelectorAll('[data-question]').forEach((box,i)=>{const text=box.querySelector('[name="question_text[]"]').value.trim();if(!text)return;const answer=box.querySelector('[name="correct_answer[]"]').value;html+=`<div class="border rounded p-3 mb-3"><strong>${i+1}. ${escapeHtml(text)}</strong><div class="row mt-2">`;['A','B','C','D'].forEach(letter=>{const value=box.querySelector(`[name="option_${letter.toLowerCase()}[]"]`).value;html+=`<div class="col-md-6 py-1 ${letter===answer?'text-success fw-bold':''}">${letter}. ${escapeHtml(value)}</div>`;});html+='</div></div>';});document.getElementById('previewBody').innerHTML=html||'<p class="text-muted">Add a complete question to preview the quiz.</p>';new bootstrap.Modal(document.getElementById('quizPreviewModal')).show();});
 const escapeHtml=value=>{const div=document.createElement('div');div.textContent=value;return div.innerHTML;};refresh();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
