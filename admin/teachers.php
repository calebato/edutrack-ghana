<?php
require_once __DIR__ . '/../auth/auth.php';
requireAdmin();

$adminName = trim((string)($_SESSION['user_name'] ?? 'System Admin')) ?: 'System Admin';
$validClassLevels = ['JHS1', 'JHS2', 'JHS3'];

// Teacher approval and destructive actions are POST-only and CSRF protected.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $_SESSION['admin_error'] = 'Your session expired. Please try again.';
    } else {
        $id = (int)($_POST['teacher_id'] ?? 0);
        $teacher = dbRow('SELECT full_name FROM teachers WHERE id = ?', [$id]);

        if (!$teacher) {
            $_SESSION['admin_error'] = 'The selected teacher could not be found.';
        } elseif (isset($_POST['update_teacher_scope'])) {
            $classLevels = $_POST['class_levels'] ?? [];
            if (!is_array($classLevels)) $classLevels = [];
            $classLevels = array_values(array_intersect($validClassLevels, $classLevels));

            if (!$classLevels) {
                $_SESSION['admin_error'] = 'Choose at least one JHS class for this teacher.';
            } else {
                $classCsv = implode(',', $classLevels);
                $pdo = getDB();
                $pdo->beginTransaction();
                try {
                    dbQuery('UPDATE teachers SET class_levels = ? WHERE id = ?', [$classCsv, $id]);
                    dbInsert('INSERT INTO system_logs (user_name, action) VALUES (?, ?)', [$adminName, "Updated class assignment for teacher ID $id to $classCsv"]);
                    $pdo->commit();
                    $_SESSION['admin_success'] = teacherDisplayName($teacher['full_name']) . ' class assignment updated.';
                } catch (Throwable $exception) {
                    $pdo->rollBack();
                    throw $exception;
                }
            }
        } elseif (isset($_POST['reset_password'])) {
            $temporaryPassword = 'Edu' . random_int(1000, 9999) . '-' . strtoupper(bin2hex(random_bytes(3)));
            $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            $action = "Issued temporary password for teacher ID $id";
            $pdo = getDB();
            $pdo->beginTransaction();
            try {
                dbQuery('UPDATE teachers SET password_hash = ?, must_change_password = 1 WHERE id = ?', [$passwordHash, $id]);
                dbInsert('INSERT INTO system_logs (user_name, action) VALUES (?, ?)', [$adminName, $action]);
                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            $_SESSION['admin_temp_password'] = $temporaryPassword;
            $_SESSION['admin_temp_teacher'] = $teacher['full_name'];
        } elseif (isset($_POST['set_teacher_status'])) {
            $newStatus = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            $action = ($newStatus ? 'Approved' : 'Deactivated') . " teacher ID $id";
            $pdo = getDB();
            $pdo->beginTransaction();
            try {
                dbQuery('UPDATE teachers SET is_active = ? WHERE id = ?', [$newStatus, $id]);
                dbInsert('INSERT INTO system_logs (user_name, action) VALUES (?, ?)', [$adminName, $action]);
                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }
            $_SESSION['admin_success'] = $teacher['full_name'] . ($newStatus ? ' was approved.' : ' was deactivated.');
        } elseif (isset($_POST['delete_teacher'])) {
            try {
                dbQuery('DELETE FROM teachers WHERE id = ?', [$id]);
                $deleted = true;
            } catch (PDOException $exception) {
                $deleted = false;
            }

            if ($deleted) {
                $action = "Deleted teacher ID $id";
                dbInsert('INSERT INTO system_logs (user_name, action) VALUES (?, ?)', [$adminName, $action]);
                $_SESSION['admin_success'] = $teacher['full_name'] . ' was deleted.';
            } else {
                $_SESSION['admin_error'] = 'This teacher could not be deleted because related records still exist.';
            }
        }
    }

    header('Location: teachers.php');
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$subjectFilter = trim((string)($_GET['subject'] ?? ''));
$subjectOptions = array_column(dbRows("SELECT DISTINCT subject FROM teachers WHERE subject IS NOT NULL AND subject <> '' ORDER BY subject"), 'subject');
if ($subjectFilter !== '' && !in_array($subjectFilter, $subjectOptions, true)) $subjectFilter = '';

$sql = "SELECT t.*, sc.name AS school_name
        FROM teachers t
        LEFT JOIN schools sc ON sc.id = t.school_id
        WHERE (t.full_name LIKE ? OR t.email LIKE ? OR t.staff_id LIKE ?)";
$like = '%' . $search . '%';
$params = [$like, $like, $like];
if ($subjectFilter !== '') {
    $sql .= ' AND t.subject = ?';
    $params[] = $subjectFilter;
}
$sql .= ' ORDER BY t.created_at DESC';
$teachers = dbRows($sql, $params);

$totalTeachers = (int)dbValue('SELECT COUNT(*) FROM teachers');
$activeTeachers = (int)dbValue('SELECT COUNT(*) FROM teachers WHERE is_active = 1');
$pendingTeachers = $totalTeachers - $activeTeachers;
$schoolCount = (int)dbValue('SELECT COUNT(DISTINCT school_id) FROM teachers');
$specialists = (int)dbValue("SELECT COUNT(*) FROM teachers WHERE subject IS NOT NULL AND subject <> '' AND subject <> 'General'");
$pendingViolations = (int)dbValue("SELECT COUNT(*) FROM violation_reports WHERE status = 'Pending'");
$csrf = generateCSRF();
$adminInitial = strtoupper(substr($adminName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers - EduTrack Ghana</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/admin-dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-dashboard.css') ?>" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-overlay" id="adminOverlay"></div>

<aside class="admin-sidebar" id="adminSidebar">
    <a class="admin-brand" href="dashboard.php"><span class="admin-brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" role="img" focusable="false"><path d="M22 10.5 12 5 2 10.5 12 16l10-5.5Z"></path><path d="M6 12.7v3.1c0 1.8 2.7 3.2 6 3.2s6-1.4 6-3.2v-3.1"></path><path d="M22 10.5v5"></path></svg></span><span><strong>EduTrack Ghana</strong><small>Administration</small></span></a>
    <nav class="admin-nav" aria-label="Admin navigation">
        <span class="admin-nav-label">Overview</span>
        <a href="dashboard.php"><span>D</span>Dashboard</a>
        <span class="admin-nav-label">Management</span>
        <a href="students.php"><span>S</span>Students</a>
        <a class="active" href="teachers.php"><span>T</span>Teachers</a>
        <a href="subjects.php"><span>B</span>Subjects</a>
        <a href="topics.php"><span>C</span>Topics</a>
        <a href="announcements.php"><span>A</span>Announcements</a>
        <span class="admin-nav-label">Oversight</span>
        <a href="violations.php"><span>!</span>Violations<?php if ($pendingViolations): ?><b><?= $pendingViolations ?></b><?php endif; ?></a>
        <a href="logs.php"><span>L</span>System Logs</a>
    </nav>
</aside>

<main class="admin-main">
    <header class="admin-topbar">
        <button class="admin-menu-button" id="adminMenuButton" type="button" aria-label="Open navigation">&#9776;</button>
        <div class="admin-topbar-title">Manage Teachers</div>
        <div class="dropdown">
            <button class="admin-avatar dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open account menu"><?= htmlspecialchars($adminInitial) ?></button>
            <ul class="dropdown-menu dropdown-menu-end admin-account-menu">
                <li class="admin-account-heading"><strong><?= htmlspecialchars($adminName) ?></strong><small>Administrator</small></li>
                <li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="logs.php">System Logs</a></li><li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/auth/logout.php">Logout</a></li>
            </ul>
        </div>
    </header>

    <div class="admin-content">
        <section class="admin-page-heading">
            <div><span class="admin-eyebrow">Staff management</span><h1>Teachers</h1><p>Review staff coverage, subjects, school placement, and account status.</p></div>
            <span class="admin-result-count"><?= count($teachers) ?> shown</span>
        </section>

        <?php if (!empty($_SESSION['admin_temp_password'])): ?>
            <div class="admin-flash warning">
                <strong>Temporary password for <?= htmlspecialchars(teacherDisplayName($_SESSION['admin_temp_teacher'])) ?></strong>
                <code><?= htmlspecialchars($_SESSION['admin_temp_password']) ?></code>
                <span>Copy it now and share it securely. It will not be shown again.</span>
            </div>
            <?php unset($_SESSION['admin_temp_password'], $_SESSION['admin_temp_teacher']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['admin_error'])): ?><div class="admin-flash danger"><?= htmlspecialchars($_SESSION['admin_error']) ?></div><?php unset($_SESSION['admin_error']); endif; ?>
        <?php if (!empty($_SESSION['admin_success'])): ?><div class="admin-flash success"><?= htmlspecialchars($_SESSION['admin_success']) ?></div><?php unset($_SESSION['admin_success']); endif; ?>

        <section class="admin-compact-metrics admin-teacher-metrics">
            <div><strong><?= number_format($totalTeachers) ?></strong><span>Total teachers</span></div>
            <div><strong><?= number_format($activeTeachers) ?></strong><span>Active accounts</span></div>
            <div><strong><?= number_format($specialists) ?></strong><span>Subject specialists</span></div>
            <div><strong><?= number_format($pendingTeachers) ?></strong><span>Awaiting approval / inactive</span></div>
        </section>

        <section class="admin-panel admin-student-panel">
            <form method="get" class="admin-filter-bar">
                <label><span>Search teachers</span><input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, email, or staff ID"></label>
                <label><span>Subject</span><select name="subject"><option value="">All subjects</option><?php foreach ($subjectOptions as $subject): ?><option value="<?= htmlspecialchars($subject) ?>" <?= $subjectFilter === $subject ? 'selected' : '' ?>><?= htmlspecialchars($subject) ?></option><?php endforeach; ?></select></label>
                <button type="submit" class="admin-primary-button">Apply filters</button>
                <?php if ($search !== '' || $subjectFilter !== ''): ?><a href="teachers.php" class="admin-clear-button">Clear</a><?php endif; ?>
            </form>

            <?php if (!$teachers): ?>
                <div class="admin-empty"><strong>No teachers found</strong><p>Try a different name, email, staff ID, or subject.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table admin-student-table admin-teacher-table">
                        <thead><tr><th>Teacher</th><th>School</th><th>Subject</th><th>Classes</th><th>Last login</th><th>Logins</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($teachers as $teacher): ?>
                            <?php
                            $assignedClasses = array_values(array_intersect(
                                $validClassLevels,
                                array_map('trim', explode(',', (string)($teacher['class_levels'] ?? '')))
                            )) ?: $validClassLevels;
                            ?>
                            <tr>
                                <td><div class="admin-student-cell"><span><?= strtoupper(substr($teacher['full_name'], 0, 1)) ?></span><div><strong><?= htmlspecialchars(teacherDisplayName($teacher['full_name'])) ?></strong><small><?= htmlspecialchars($teacher['email']) ?><br><?= htmlspecialchars($teacher['staff_id'] ?? '') ?></small></div></div></td>
                                <td><?= htmlspecialchars($teacher['school_name'] ?? 'Unassigned') ?></td>
                                <td><span class="admin-subject-chip"><?= htmlspecialchars($teacher['subject'] ?: 'General') ?></span></td>
                                <td>
                                    <form method="post" class="admin-class-scope-form">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="teacher_id" value="<?= (int)$teacher['id'] ?>">
                                        <?php foreach ($validClassLevels as $level): ?>
                                            <label><input type="checkbox" name="class_levels[]" value="<?= $level ?>" <?= in_array($level, $assignedClasses, true) ? 'checked' : '' ?>> <?= $level ?></label>
                                        <?php endforeach; ?>
                                        <button type="submit" name="update_teacher_scope" class="admin-action-button">Save</button>
                                    </form>
                                </td>
                                <td><?= $teacher['last_login'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($teacher['last_login']))) : '<span class="admin-muted-value">Never</span>' ?></td>
                                <td><strong><?= number_format((int)$teacher['login_count']) ?></strong></td>
                                <td><span class="admin-status <?= $teacher['is_active'] ? 'ok' : 'warning' ?>"><?= $teacher['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                                <td>
                                    <div class="admin-row-actions">
                                        <form method="post" onsubmit="return confirm('Issue a temporary password for this teacher?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="teacher_id" value="<?= (int)$teacher['id'] ?>">
                                            <button type="submit" name="reset_password" class="admin-action-button">Reset password</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="teacher_id" value="<?= (int)$teacher['id'] ?>"><input type="hidden" name="is_active" value="<?= $teacher['is_active'] ? 0 : 1 ?>">
                                            <button type="submit" name="set_teacher_status" class="admin-action-button"><?= $teacher['is_active'] ? 'Deactivate' : 'Approve' ?></button>
                                        </form>
                                        <form method="post" onsubmit="return confirm('Permanently delete this teacher? This cannot be undone.')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="teacher_id" value="<?= (int)$teacher['id'] ?>">
                                            <button type="submit" name="delete_teacher" class="admin-action-button danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sidebar = document.getElementById('adminSidebar');
const overlay = document.getElementById('adminOverlay');
document.getElementById('adminMenuButton').addEventListener('click', function () { sidebar.classList.toggle('open'); overlay.classList.toggle('show'); });
overlay.addEventListener('click', function () { sidebar.classList.remove('open'); overlay.classList.remove('show'); });
</script>
</body>
</html>
