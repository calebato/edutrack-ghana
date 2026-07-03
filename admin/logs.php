<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/_layout.php';
requireAdmin();

$search = trim((string)($_GET['search'] ?? ''));
$like = '%' . $search . '%';
$logs = dbRows('SELECT * FROM system_logs WHERE user_name LIKE ? OR action LIKE ? ORDER BY created_at DESC LIMIT 250', [$like, $like]);
$totalLogs = (int)dbValue('SELECT COUNT(*) FROM system_logs');
$todayLogs = (int)dbValue('SELECT COUNT(*) FROM system_logs WHERE created_at >= CURDATE()');
$pendingViolations = (int)dbValue("SELECT COUNT(*) FROM violation_reports WHERE status='Pending'");
$pendingTopics = (int)dbValue("SELECT COUNT(*) FROM topics WHERE approval_status='pending'");
renderAdminHeader('System Logs', 'logs', $pendingViolations, $pendingTopics);
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">Platform oversight</span><h1>System Logs</h1><p>Review recent administrative and account-management events.</p></div><span class="admin-result-count"><?= count($logs) ?> shown</span></section>
<section class="admin-compact-metrics"><div><strong><?= number_format($totalLogs) ?></strong><span>Total recorded events</span></div><div><strong><?= number_format($todayLogs) ?></strong><span>Events today</span></div><div><strong>250</strong><span>Maximum rows displayed</span></div></section>
<section class="admin-panel admin-student-panel">
    <form class="admin-filter-bar" method="get"><label><span>Search logs</span><input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="User or action"></label><button class="admin-primary-button">Search</button><?php if ($search !== ''): ?><a class="admin-clear-button" href="logs.php">Clear</a><?php endif; ?></form>
    <?php if (!$logs): ?><div class="admin-empty"><strong>No logs found</strong><p>Try a different search.</p></div><?php else: ?><div class="table-responsive"><table class="admin-table admin-student-table"><thead><tr><th>ID</th><th>User</th><th>Action</th><th>Date and time</th></tr></thead><tbody><?php foreach ($logs as $log): ?><tr><td>#<?= (int)$log['id'] ?></td><td><strong><?= htmlspecialchars($log['user_name'] ?: 'System') ?></strong></td><td class="admin-description"><?= htmlspecialchars($log['action'] ?: '') ?></td><td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php renderAdminFooter(); ?>
