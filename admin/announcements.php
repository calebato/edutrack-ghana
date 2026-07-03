<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../includes/log_helper.php';
require_once __DIR__ . '/_layout.php';
requireAdmin();

$adminName = trim((string)($_SESSION['user_name'] ?? 'System Admin')) ?: 'System Admin';
$allowedTargets = ['all','JHS1','JHS2','JHS3'];
$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $_SESSION['admin_error'] = 'Your session expired. Please try again.';
        header('Location: announcements.php'); exit;
    }
    if (isset($_POST['save_announcement'])) {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $target = (string)($_POST['target'] ?? 'all');
        $scheduledAt = trim((string)($_POST['scheduled_at'] ?? '')) ?: null;
        $isPinned = isset($_POST['is_pinned']) ? 1 : 0;
        if ($title === '' || $content === '' || !in_array($target, $allowedTargets, true)) {
            $formError = 'Title, message, and a valid audience are required.';
        } else {
            if ($id > 0) {
                dbQuery('UPDATE announcements SET title=?,content=?,target=?,scheduled_at=?,is_pinned=?,edited_at=NOW() WHERE id=?', [$title, $content, $target, $scheduledAt, $isPinned, $id]);
                $action = 'Updated announcement: ' . $title;
                $_SESSION['admin_success'] = 'Announcement updated.';
            } else {
                dbInsert('INSERT INTO announcements (title,content,target,scheduled_at,is_pinned,teacher_id) VALUES (?,?,?,?,?,NULL)', [$title, $content, $target, $scheduledAt, $isPinned]);
                $action = 'Posted announcement: ' . $title;
                $_SESSION['admin_success'] = 'Announcement posted.';
            }
            addLog(null, $adminName, $action);
            header('Location: announcements.php'); exit;
        }
    } elseif (isset($_POST['delete_announcement'])) {
        $id = (int)($_POST['announcement_id'] ?? 0);
        dbQuery('UPDATE announcements SET is_active=0 WHERE id=?', [$id]);
        addLog(null, $adminName, "Archived announcement ID $id");
        $_SESSION['admin_success'] = 'Announcement archived.';
        header('Location: announcements.php'); exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editing = null;
if ($editId) $editing = dbRow('SELECT * FROM announcements WHERE id=? AND is_active=1', [$editId]);
$announcements = dbRows('SELECT * FROM announcements WHERE is_active=1 ORDER BY is_pinned DESC,created_at DESC');
$scheduledCount = 0; $pinnedCount = 0;
foreach ($announcements as $item) { if ($item['scheduled_at'] && strtotime($item['scheduled_at']) > time()) $scheduledCount++; if ($item['is_pinned']) $pinnedCount++; }
$pendingViolations = (int)dbValue("SELECT COUNT(*) FROM violation_reports WHERE status='Pending'");
$pendingTopics = (int)dbValue("SELECT COUNT(*) FROM topics WHERE approval_status='pending'");
$csrf = generateCSRF();
renderAdminHeader('Announcements', 'announcements', $pendingViolations, $pendingTopics);
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">Communication</span><h1>Announcements</h1><p>Publish updates to all students or target a specific class.</p></div><span class="admin-result-count"><?= count($announcements) ?> active</span></section>
<?php if ($formError): ?><div class="admin-flash danger"><?= htmlspecialchars($formError) ?></div><?php endif; ?>
<?php if (!empty($_SESSION['admin_error'])): ?><div class="admin-flash danger"><?= htmlspecialchars($_SESSION['admin_error']) ?></div><?php unset($_SESSION['admin_error']); endif; ?>
<?php if (!empty($_SESSION['admin_success'])): ?><div class="admin-flash success"><?= htmlspecialchars($_SESSION['admin_success']) ?></div><?php unset($_SESSION['admin_success']); endif; ?>
<section class="admin-compact-metrics"><div><strong><?= count($announcements) ?></strong><span>Active announcements</span></div><div><strong><?= $pinnedCount ?></strong><span>Pinned updates</span></div><div><strong><?= $scheduledCount ?></strong><span>Scheduled</span></div></section>
<div class="admin-two-column">
    <section class="admin-panel admin-form-panel"><h2><?= $editing ? 'Edit announcement' : 'Post announcement' ?></h2><p>Keep the message concise and choose the right audience.</p>
        <form method="post" class="admin-form-grid"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
            <div class="admin-form-field"><label>Title</label><input name="title" maxlength="200" required value="<?= htmlspecialchars($editing['title'] ?? ($_POST['title'] ?? '')) ?>"></div>
            <div class="admin-form-field"><label>Message</label><textarea name="content" rows="6" required><?= htmlspecialchars($editing['content'] ?? ($_POST['content'] ?? '')) ?></textarea></div>
            <div class="admin-form-grid two"><div class="admin-form-field"><label>Audience</label><select name="target"><?php foreach ($allowedTargets as $target): ?><option value="<?= $target ?>" <?= ($editing['target'] ?? 'all') === $target ? 'selected' : '' ?>><?= $target === 'all' ? 'All students' : $target ?></option><?php endforeach; ?></select></div><div class="admin-form-field"><label>Schedule (optional)</label><input type="datetime-local" name="scheduled_at" value="<?= !empty($editing['scheduled_at']) ? date('Y-m-d\TH:i', strtotime($editing['scheduled_at'])) : '' ?>"></div></div>
            <label class="admin-checkbox"><input type="checkbox" name="is_pinned" <?= !empty($editing['is_pinned']) ? 'checked' : '' ?>> Pin this important announcement</label>
            <div class="admin-inline-form"><button name="save_announcement" class="admin-primary-button"><?= $editing ? 'Update announcement' : 'Post announcement' ?></button><?php if ($editing): ?><a class="admin-clear-button" href="announcements.php">Cancel</a><?php endif; ?></div>
        </form>
    </section>
    <section class="admin-panel admin-student-panel"><div class="admin-panel-title"><div><h2>Published announcements</h2><p>Pinned items appear first</p></div></div>
        <?php if (!$announcements): ?><div class="admin-empty"><strong>No active announcements</strong></div><?php else: ?><div class="table-responsive"><table class="admin-table admin-student-table"><thead><tr><th>Announcement</th><th>Audience</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($announcements as $item): $scheduled = $item['scheduled_at'] && strtotime($item['scheduled_at']) > time(); ?><tr><td class="admin-description"><strong><?= htmlspecialchars($item['title']) ?></strong><small><?= htmlspecialchars(mb_strimwidth($item['content'], 0, 100, '…')) ?></small><?php if ($item['is_pinned']): ?> <span class="admin-badge warning">Pinned</span><?php endif; ?></td><td><span class="admin-badge primary"><?= $item['target'] === 'all' ? 'All' : htmlspecialchars($item['target']) ?></span></td><td><span class="admin-badge <?= $scheduled ? 'warning' : 'success' ?>"><?= $scheduled ? 'Scheduled' : 'Active' ?></span></td><td><?= date('M j, Y', strtotime($item['created_at'])) ?></td><td><div class="admin-row-actions"><a class="admin-action-button" href="?edit=<?= (int)$item['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Archive this announcement?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="announcement_id" value="<?= (int)$item['id'] ?>"><button name="delete_announcement" class="admin-action-button danger">Archive</button></form></div></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
</div>
<?php renderAdminFooter(); ?>
