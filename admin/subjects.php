<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/_layout.php';
requireAdmin();

$adminName = trim((string)($_SESSION['user_name'] ?? 'System Admin')) ?: 'System Admin';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $_SESSION['admin_error'] = 'Your session expired. Please try again.';
    } elseif (isset($_POST['add_subject'])) {
        $name = trim((string)($_POST['name'] ?? ''));
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $description = trim((string)($_POST['description'] ?? ''));
        $classLevel = (string)($_POST['class_level'] ?? 'ALL');
        if ($name === '' || $code === '' || !in_array($classLevel, ['JHS1','JHS2','JHS3','ALL'], true)) {
            $_SESSION['admin_error'] = 'Subject name, code, and class level are required.';
        } else {
            try {
                dbInsert('INSERT INTO subjects (name, code, description, class_level) VALUES (?, ?, ?, ?)', [$name, $code, $description, $classLevel]);
                $_SESSION['admin_success'] = $name . ' was added.';
            } catch (PDOException $exception) {
                $_SESSION['admin_error'] = 'The subject could not be added. Check that its name and code are unique.';
            }
        }
    } elseif (isset($_POST['delete_subject'])) {
        $id = (int)($_POST['subject_id'] ?? 0);
        try {
            dbQuery('DELETE FROM subjects WHERE id = ?', [$id]);
            $_SESSION['admin_success'] = 'Subject deleted.';
        } catch (PDOException $exception) {
            $_SESSION['admin_error'] = 'This subject cannot be deleted while topics or quizzes still use it.';
        }
    }
    header('Location: subjects.php');
    exit;
}

$subjects = dbRows('SELECT s.*, COUNT(DISTINCT t.id) topic_count FROM subjects s LEFT JOIN topics t ON t.subject_id=s.id GROUP BY s.id ORDER BY s.name');
$pendingViolations = (int)dbValue("SELECT COUNT(*) FROM violation_reports WHERE status='Pending'");
$pendingTopics = (int)dbValue("SELECT COUNT(*) FROM topics WHERE approval_status='pending'");
$csrf = generateCSRF();
renderAdminHeader('Manage Subjects', 'subjects', $pendingViolations, $pendingTopics);
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">Curriculum</span><h1>Subjects</h1><p>Create and review the subjects available across EduTrack.</p></div><span class="admin-result-count"><?= count($subjects) ?> subjects</span></section>
<?php if (!empty($_SESSION['admin_error'])): ?><div class="admin-flash danger"><?= htmlspecialchars($_SESSION['admin_error']) ?></div><?php unset($_SESSION['admin_error']); endif; ?>
<?php if (!empty($_SESSION['admin_success'])): ?><div class="admin-flash success"><?= htmlspecialchars($_SESSION['admin_success']) ?></div><?php unset($_SESSION['admin_success']); endif; ?>
<div class="admin-two-column">
    <section class="admin-panel admin-form-panel"><h2>Add subject</h2><p>Define a subject before creating its curriculum topics.</p>
        <form method="post" class="admin-form-grid"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="admin-form-field"><label>Subject name</label><input name="name" maxlength="100" required placeholder="e.g. Mathematics"></div>
            <div class="admin-form-grid two"><div class="admin-form-field"><label>Short code</label><input name="code" maxlength="20" required placeholder="MATH"></div><div class="admin-form-field"><label>Class coverage</label><select name="class_level"><option value="ALL">All classes</option><option>JHS1</option><option>JHS2</option><option>JHS3</option></select></div></div>
            <div class="admin-form-field"><label>Description</label><textarea name="description" rows="4" placeholder="What students learn in this subject"></textarea></div>
            <button class="admin-primary-button" name="add_subject" type="submit">Add subject</button>
        </form>
    </section>
    <section class="admin-panel admin-student-panel"><div class="admin-panel-title"><div><h2>Subject catalogue</h2><p>Current curriculum areas and topic coverage</p></div></div>
        <div class="table-responsive"><table class="admin-table admin-student-table"><thead><tr><th>Subject</th><th>Code</th><th>Class</th><th>Topics</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($subjects as $subject): ?><tr><td class="admin-description"><strong><?= htmlspecialchars($subject['name']) ?></strong><small><?= htmlspecialchars($subject['description'] ?: 'No description') ?></small></td><td><span class="admin-badge primary"><?= htmlspecialchars($subject['code']) ?></span></td><td><?= htmlspecialchars($subject['class_level']) ?></td><td><strong><?= (int)$subject['topic_count'] ?></strong></td><td><form method="post" onsubmit="return confirm('Delete this subject?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="subject_id" value="<?= (int)$subject['id'] ?>"><button name="delete_subject" class="admin-action-button danger">Delete</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php renderAdminFooter(); ?>
