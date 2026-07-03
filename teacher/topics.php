<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/teacher.php';
requireTeacher();

$teacher = getCurrentUser();
$teacherId = (int)$teacher['id'];
$schoolId = (int)$teacher['school_id'];
$assignedClasses = teacherAssignedClasses($teacher);
[$topicClassSql, $topicClassParams] = teacherClassSql('t.class_level', $assignedClasses);
$isGeneral = $teacher['subject'] === 'General';
$subject = $isGeneral ? null : dbRow('SELECT id, name FROM subjects WHERE name = ?', [$teacher['subject']]);
$subjectOptions = $isGeneral ? dbRows('SELECT id,name FROM subjects ORDER BY name') : [];
$errors = [];
$success = $_SESSION['topic_flash'] ?? '';
unset($_SESSION['topic_flash']);

$editing = null;
if (isset($_GET['edit'])) {
    $editing = dbRow(
        'SELECT * FROM topics WHERE id = ? AND created_by_teacher_id = ? AND school_id = ? AND class_level IN (' . implode(',', array_fill(0, count($assignedClasses), '?')) . ')',
        array_merge([(int)$_GET['edit'], $teacherId, $schoolId], $assignedClasses)
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$isGeneral && !$subject) {
        $errors[] = 'Your account does not have an assigned curriculum subject.';
    } else {
        $action = $_POST['action'] ?? 'save';
        $topicId = (int)($_POST['topic_id'] ?? 0);

        if ($action === 'deactivate') {
            dbQuery(
                'UPDATE topics SET is_active = 0 WHERE id = ? AND created_by_teacher_id = ? AND school_id = ?',
                [$topicId, $teacherId, $schoolId]
            );
            $_SESSION['topic_flash'] = 'Topic deactivated.';
            header('Location: ' . BASE_URL . '/teacher/topics.php');
            exit;
        }

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $classLevel = $_POST['class_level'] ?? '';
        $difficulty = $_POST['difficulty'] ?? '';
        $estimatedMinutes = max(5, min(180, (int)($_POST['estimated_minutes'] ?? 30)));
        $topicSubjectId = $isGeneral ? (int)($_POST['subject_id'] ?? 0) : (int)$subject['id'];

        if ($title === '' || mb_strlen($title) > 200) $errors[] = 'Enter a topic title of 200 characters or fewer.';
        if (!in_array($classLevel, $assignedClasses, true)) $errors[] = 'Choose one of your assigned class levels.';
        if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) $errors[] = 'Choose a valid difficulty.';
        if ($content === '') $errors[] = 'Add lesson content before submitting the topic.';
        if (!dbRow('SELECT id FROM subjects WHERE id = ?', [$topicSubjectId])) $errors[] = 'Choose a valid subject.';

        if (!$errors) {
            if ($topicId) {
                $owned = dbRow(
                    'SELECT id FROM topics WHERE id = ? AND created_by_teacher_id = ? AND school_id = ? AND class_level IN (' . implode(',', array_fill(0, count($assignedClasses), '?')) . ')',
                    array_merge([$topicId, $teacherId, $schoolId], $assignedClasses)
                );
                if (!$owned) {
                    $errors[] = 'You may only edit topics that you created.';
                } else {
                    dbQuery(
                        "UPDATE topics SET subject_id=?, title=?, description=?, content=?, class_level=?, difficulty=?, estimated_minutes=?,
                         approval_status='pending', is_active=1, reviewed_by_admin_id=NULL, reviewed_at=NULL, rejection_reason=NULL
                         WHERE id=? AND created_by_teacher_id=? AND school_id=?",
                        [$topicSubjectId, $title, $description, $content, $classLevel, $difficulty, $estimatedMinutes, $topicId, $teacherId, $schoolId]
                    );
                    $_SESSION['topic_flash'] = 'Changes submitted for admin approval.';
                }
            } else {
                $next = dbRow(
                    'SELECT COALESCE(MAX(sequence_order),0)+1 AS next_order FROM topics WHERE subject_id=? AND class_level=?',
                    [$topicSubjectId, $classLevel]
                );
                dbInsert(
                    "INSERT INTO topics
                     (subject_id, school_id, created_by_teacher_id, title, description, difficulty, sequence_order,
                      class_level, estimated_minutes, content, approval_status, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1)",
                    [$topicSubjectId, $schoolId, $teacherId, $title, $description, $difficulty,
                     (int)$next['next_order'], $classLevel, $estimatedMinutes, $content]
                );
                $_SESSION['topic_flash'] = 'Topic submitted for admin approval.';
            }

            if (!$errors) {
                header('Location: ' . BASE_URL . '/teacher/topics.php');
                exit;
            }
        }
    }
}

$myTopics = dbRows(
    "SELECT t.*, a.full_name AS reviewer_name, s.name AS subject_name
     FROM topics t
     JOIN subjects s ON s.id=t.subject_id
     LEFT JOIN admins a ON a.id=t.reviewed_by_admin_id
     WHERE t.created_by_teacher_id=? AND t.school_id=? AND $topicClassSql
     ORDER BY FIELD(t.approval_status,'pending','rejected','approved'), t.created_at DESC",
    array_merge([$teacherId, $schoolId], $topicClassParams)
);

$pageTitle = 'Manage Topics';
$activeNav = 'topics';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$isGeneral && !$subject): ?>
<div class="alert alert-warning">Your account is assigned to “<?= htmlspecialchars($teacher['subject']) ?>”. Ask an administrator to assign a specific subject before creating topics.</div>
<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><?= $isGeneral ? 'My Topics' : 'My ' . htmlspecialchars($subject['name']) . ' Topics' ?></h4>
        <p class="text-muted mb-0">New and edited topics become visible after admin approval.</p>
    </div>
    <?php if ($editing): ?><a class="btn btn-outline-secondary" href="topics.php">Cancel edit</a><?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="card edu-card mb-4"><div class="card-body">
    <h5 class="card-title mb-3"><?= $editing ? 'Edit Topic' : 'Create Topic' ?></h5>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="topic_id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Subject</label>
                <?php if ($isGeneral): ?>
                    <select class="form-select" name="subject_id" required><option value="">Choose subject</option><?php foreach ($subjectOptions as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (int)($editing['subject_id'] ?? 0)===(int)$option['id']?'selected':'' ?>><?= htmlspecialchars($option['name']) ?></option><?php endforeach; ?></select>
                <?php else: ?>
                    <input class="form-control" value="<?= htmlspecialchars($subject['name']) ?>" disabled>
                <?php endif; ?>
            </div>
            <div class="col-md-3"><label class="form-label">Class</label><select class="form-select" name="class_level" required><?php foreach ($assignedClasses as $level): ?><option value="<?= $level ?>" <?= ($editing['class_level'] ?? '') === $level ? 'selected' : '' ?>><?= $level ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Difficulty</label><select class="form-select" name="difficulty" required><?php foreach (['easy','medium','hard'] as $value): ?><option value="<?= $value ?>" <?= ($editing['difficulty'] ?? 'easy') === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">Topic title</label><input class="form-control" name="title" maxlength="200" required value="<?= htmlspecialchars($editing['title'] ?? '') ?>"></div>
            <div class="col-md-9"><label class="form-label">Short description</label><textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea></div>
            <div class="col-md-3"><label class="form-label">Estimated minutes</label><input class="form-control" type="number" name="estimated_minutes" min="5" max="180" value="<?= (int)($editing['estimated_minutes'] ?? 30) ?>"></div>
            <div class="col-12"><label class="form-label">Lesson content</label><textarea class="form-control" name="content" rows="8" required><?= htmlspecialchars($editing['content'] ?? '') ?></textarea></div>
            <div class="col-12"><button class="btn btn-primary" type="submit"><?= $editing ? 'Submit Changes' : 'Submit for Approval' ?></button></div>
        </div>
    </form>
</div></div>

<div class="card edu-card"><div class="card-body">
    <h5 class="card-title mb-3">Submitted Topics</h5>
    <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Topic</th><th>Class</th><th>Status</th><th>Visibility</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$myTopics): ?><tr><td colspan="5" class="text-muted">You have not submitted a topic yet.</td></tr><?php endif; ?>
        <?php foreach ($myTopics as $topic): ?>
            <tr>
                <td><strong><?= htmlspecialchars($topic['title']) ?></strong><div class="small text-muted"><?= htmlspecialchars($topic['subject_name']) ?></div><?php if ($topic['rejection_reason']): ?><div class="small text-danger">Reason: <?= htmlspecialchars($topic['rejection_reason']) ?></div><?php endif; ?></td>
                <td><?= htmlspecialchars($topic['class_level']) ?></td>
                <td><span class="badge <?= $topic['approval_status']==='approved'?'bg-success':($topic['approval_status']==='rejected'?'bg-danger':'bg-warning text-dark') ?>"><?= ucfirst($topic['approval_status']) ?></span></td>
                <td><?= $topic['is_active'] ? 'Active' : 'Inactive' ?></td>
                <td class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$topic['id'] ?>">Edit</a><?php if ($topic['is_active']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="topic_id" value="<?= (int)$topic['id'] ?>"><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this topic?')">Deactivate</button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
