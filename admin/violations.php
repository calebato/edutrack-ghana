<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../includes/log_helper.php';
require_once __DIR__ . '/_layout.php';
requireAdmin();

$adminName = trim((string)($_SESSION['user_name'] ?? 'System Admin')) ?: 'System Admin';
$allowedStatuses = ['Pending', 'Reviewing', 'Resolved'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $_SESSION['admin_error'] = 'Your session expired. Please try again.';
    } else {
        $id = (int)($_POST['report_id'] ?? 0);
        if (isset($_POST['update_status'])) {
            $newStatus = (string)($_POST['status'] ?? '');
            if (in_array($newStatus, $allowedStatuses, true)) {
                dbQuery('UPDATE violation_reports SET status=? WHERE id=?', [$newStatus, $id]);
                addLog(null, $adminName, "Updated violation report ID $id status to $newStatus");
                $_SESSION['admin_success'] = 'Report status updated.';
            }
        } elseif (isset($_POST['delete_report'])) {
            dbQuery('DELETE FROM violation_reports WHERE id=?', [$id]);
            addLog(null, $adminName, "Deleted violation report ID $id");
            $_SESSION['admin_success'] = 'Report deleted.';
        }
    }
    header('Location: violations.php');
    exit;
}

$statusFilter = (string)($_GET['status'] ?? 'all');
if ($statusFilter !== 'all' && !in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'all';
$sql = 'SELECT vr.*,s.full_name,s.class_level FROM violation_reports vr LEFT JOIN students s ON s.id=vr.student_id';
$params = [];
if ($statusFilter !== 'all') { $sql .= ' WHERE vr.status = ?'; $params[] = $statusFilter; }
$sql .= ' ORDER BY vr.created_at DESC';
$reports = dbRows($sql, $params);
$counts = ['Pending'=>0,'Reviewing'=>0,'Resolved'=>0];
foreach (dbRows('SELECT status,COUNT(*) total FROM violation_reports GROUP BY status') as $row) {
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['total'];
}
$pendingTopics = (int)dbValue("SELECT COUNT(*) FROM topics WHERE approval_status='pending'");
$csrf = generateCSRF();
renderAdminHeader('Violation Reports', 'violations', $counts['Pending'], $pendingTopics);
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">Student safety</span><h1>Violation Reports</h1><p>Review submitted concerns, track investigations, and resolve reports.</p></div><span class="admin-result-count"><?= count($reports) ?> shown</span></section>
<?php if (!empty($_SESSION['admin_error'])): ?><div class="admin-flash danger"><?= htmlspecialchars($_SESSION['admin_error']) ?></div><?php unset($_SESSION['admin_error']); endif; ?>
<?php if (!empty($_SESSION['admin_success'])): ?><div class="admin-flash success"><?= htmlspecialchars($_SESSION['admin_success']) ?></div><?php unset($_SESSION['admin_success']); endif; ?>
<section class="admin-compact-metrics"><div><strong><?= $counts['Pending'] ?></strong><span>Pending review</span></div><div><strong><?= $counts['Reviewing'] ?></strong><span>Under review</span></div><div><strong><?= $counts['Resolved'] ?></strong><span>Resolved</span></div></section>
<section class="admin-panel admin-student-panel">
    <div class="admin-panel-body pt-3"><nav class="admin-status-tabs"><?php foreach (array_merge(['all'], $allowedStatuses) as $filter): ?><a class="<?= $statusFilter === $filter ? 'active' : '' ?>" href="?status=<?= urlencode($filter) ?>"><?= ucfirst($filter) ?><?= isset($counts[$filter]) ? ' (' . $counts[$filter] . ')' : '' ?></a><?php endforeach; ?></nav></div>
    <?php if (!$reports): ?><div class="admin-empty"><strong>No reports in this view</strong></div><?php else: ?><div class="table-responsive"><table class="admin-table admin-student-table"><thead><tr><th>Reporter</th><th>Concern</th><th>Description</th><th>Evidence</th><th>Status</th><th>Submitted</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($reports as $report): ?><tr>
        <td><strong><?= $report['is_anonymous'] ? 'Anonymous' : htmlspecialchars($report['full_name'] ?? 'Unknown student') ?></strong><small class="admin-parent-contact"><?= htmlspecialchars($report['class_level'] ?? '') ?></small></td>
        <td><span class="admin-badge warning"><?= htmlspecialchars($report['violation_type']) ?></span></td>
        <td class="admin-description"><?= htmlspecialchars(mb_strimwidth($report['description'], 0, 100, '…')) ?></td>
        <td><?php if ($report['evidence']): ?><a class="admin-evidence-link" target="_blank" rel="noopener" href="<?= BASE_URL ?>/uploads/violations/<?= rawurlencode(basename($report['evidence'])) ?>">View evidence</a><?php else: ?><span class="admin-muted-value">None</span><?php endif; ?></td>
        <td><form method="post" class="admin-inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><select name="status"><?php foreach ($allowedStatuses as $value): ?><option <?= $report['status'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select><button name="update_status" class="admin-action-button">Update</button></form></td>
        <td><?= htmlspecialchars(date('M j, Y', strtotime($report['created_at']))) ?></td>
        <td><form method="post" onsubmit="return confirm('Delete this report permanently?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>"><button name="delete_report" class="admin-action-button danger">Delete</button></form></td>
    </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php renderAdminFooter(); ?>
