<?php
require_once __DIR__ . '/../auth/auth.php';
requireAdmin();

$adminName = trim((string)($_SESSION['user_name'] ?? 'System Admin')) ?: 'System Admin';

// Student account actions are POST-only and CSRF protected.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $_SESSION['admin_error'] = 'Your session expired. Please try again.';
    } else {
        $id = (int)($_POST['student_id'] ?? 0);
        $student = dbRow('SELECT full_name FROM students WHERE id = ?', [$id]);

        if (!$student) {
            $_SESSION['admin_error'] = 'The selected student could not be found.';
        } elseif (isset($_POST['reset_password'])) {
            $temporaryPassword = 'Edu' . random_int(1000, 9999) . '-' . strtoupper(bin2hex(random_bytes(3)));
            $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            $action = "Issued temporary password for student ID $id";
            $pdo = getDB();
            $pdo->beginTransaction();
            try {
                dbQuery('UPDATE students SET password_hash = ?, must_change_password = 1 WHERE id = ?', [$passwordHash, $id]);
                dbInsert('INSERT INTO system_logs (user_name, action) VALUES (?, ?)', [$adminName, $action]);
                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            $_SESSION['admin_temp_password'] = $temporaryPassword;
            $_SESSION['admin_temp_student'] = $student['full_name'];
        } elseif (isset($_POST['delete_student'])) {
            try {
                $pdo = getDB();
                $pdo->beginTransaction();
                dbQuery('DELETE FROM students WHERE id = ?', [$id]);
                dbInsert('INSERT INTO system_logs (user_name, action) VALUES (?, ?)', [$adminName, "Deleted student ID $id"]);
                $pdo->commit();
                $_SESSION['admin_success'] = $student['full_name'] . ' was deleted.';
            } catch (PDOException $exception) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['admin_error'] = 'This student could not be deleted because related records still exist.';
            }
        }
    }

    header('Location: students.php');
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$classFilter = (string)($_GET['class'] ?? '');
$allowedClasses = ['JHS1', 'JHS2', 'JHS3'];
if (!in_array($classFilter, $allowedClasses, true)) $classFilter = '';

$sql = "SELECT s.*, sc.name AS school_name
        FROM students s
        LEFT JOIN schools sc ON sc.id = s.school_id
        WHERE (s.full_name LIKE ? OR s.email LIKE ? OR s.student_id LIKE ?)";
$like = '%' . $search . '%';
$params = [$like, $like, $like];
if ($classFilter !== '') {
    $sql .= ' AND s.class_level = ?';
    $params[] = $classFilter;
}
$sql .= ' ORDER BY s.created_at DESC';
$students = dbRows($sql, $params);

$totalStudents = (int)dbValue('SELECT COUNT(*) FROM students');
$activeStudents = (int)dbValue('SELECT COUNT(*) FROM students WHERE is_active = 1');
$schoolCount = (int)dbValue('SELECT COUNT(DISTINCT school_id) FROM students');
$pendingViolations = (int)dbValue("SELECT COUNT(*) FROM violation_reports WHERE status = 'Pending'");
$csrf = generateCSRF();
$adminInitial = strtoupper(substr($adminName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - EduTrack Ghana</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/admin-dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin-dashboard.css') ?>" rel="stylesheet">
</head>
<body class="admin-body">
<div class="admin-overlay" id="adminOverlay"></div>

<aside class="admin-sidebar" id="adminSidebar">
    <a class="admin-brand" href="dashboard.php">
        <span class="admin-brand-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" role="img" focusable="false">
                <path d="M22 10.5 12 5 2 10.5 12 16l10-5.5Z"></path>
                <path d="M6 12.7v3.1c0 1.8 2.7 3.2 6 3.2s6-1.4 6-3.2v-3.1"></path>
                <path d="M22 10.5v5"></path>
            </svg>
        </span>
        <span><strong>EduTrack Ghana</strong><small>Administration</small></span>
    </a>
    <nav class="admin-nav" aria-label="Admin navigation">
        <span class="admin-nav-label">Overview</span>
        <a href="dashboard.php"><span>D</span>Dashboard</a>
        <span class="admin-nav-label">Management</span>
        <a class="active" href="students.php"><span>S</span>Students</a>
        <a href="teachers.php"><span>T</span>Teachers</a>
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
        <div class="admin-topbar-title">Manage Students</div>
        <div class="dropdown">
            <button class="admin-avatar dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open account menu"><?= htmlspecialchars($adminInitial) ?></button>
            <ul class="dropdown-menu dropdown-menu-end admin-account-menu">
                <li class="admin-account-heading"><strong><?= htmlspecialchars($adminName) ?></strong><small>Administrator</small></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="logs.php">System Logs</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/auth/logout.php">Logout</a></li>
            </ul>
        </div>
    </header>

    <div class="admin-content">
        <section class="admin-page-heading">
            <div><span class="admin-eyebrow">User management</span><h1>Students</h1><p>Search accounts, review school placement, and manage student access.</p></div>
            <span class="admin-result-count"><?= count($students) ?> shown</span>
        </section>

        <?php if (!empty($_SESSION['admin_temp_password'])): ?>
            <div class="admin-flash warning">
                <strong>Temporary password for <?= htmlspecialchars($_SESSION['admin_temp_student']) ?></strong>
                <code><?= htmlspecialchars($_SESSION['admin_temp_password']) ?></code>
                <span>Copy it now and share it securely. It will not be shown again.</span>
            </div>
            <?php unset($_SESSION['admin_temp_password'], $_SESSION['admin_temp_student']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['admin_error'])): ?>
            <div class="admin-flash danger"><?= htmlspecialchars($_SESSION['admin_error']) ?></div>
            <?php unset($_SESSION['admin_error']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['admin_success'])): ?>
            <div class="admin-flash success"><?= htmlspecialchars($_SESSION['admin_success']) ?></div>
            <?php unset($_SESSION['admin_success']); ?>
        <?php endif; ?>

        <section class="admin-compact-metrics">
            <div><strong><?= number_format($totalStudents) ?></strong><span>Total students</span></div>
            <div><strong><?= number_format($activeStudents) ?></strong><span>Enabled accounts</span></div>
            <div><strong><?= number_format($schoolCount) ?></strong><span>Schools represented</span></div>
        </section>

        <section class="admin-panel admin-student-panel">
            <form method="get" class="admin-filter-bar">
                <label><span>Search students</span><input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, email, or student ID"></label>
                <label><span>Class level</span><select name="class"><option value="">All classes</option><?php foreach ($allowedClasses as $class): ?><option value="<?= $class ?>" <?= $classFilter === $class ? 'selected' : '' ?>><?= $class ?></option><?php endforeach; ?></select></label>
                <button type="submit" class="admin-primary-button">Apply filters</button>
                <?php if ($search !== '' || $classFilter !== ''): ?><a href="students.php" class="admin-clear-button">Clear</a><?php endif; ?>
            </form>

            <?php if (!$students): ?>
                <div class="admin-empty"><strong>No students found</strong><p>Try a different name, email, student ID, or class.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table admin-student-table">
                        <thead><tr><th>Student</th><th>School</th><th>Class</th><th>Parent / guardian</th><th>Points</th><th>Account</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td><div class="admin-student-cell"><span><?= strtoupper(substr($student['full_name'], 0, 1)) ?></span><div><strong><?= htmlspecialchars($student['full_name']) ?></strong><small><?= htmlspecialchars($student['email']) ?><br><?= htmlspecialchars($student['student_id'] ?? '') ?></small></div></div></td>
                                <td><?= htmlspecialchars($student['school_name'] ?? 'Unassigned') ?></td>
                                <td><span class="admin-class-chip"><?= htmlspecialchars($student['class_level']) ?></span></td>
                                <td><strong class="admin-parent-name"><?= htmlspecialchars($student['parent_name'] ?: 'Not provided') ?></strong><small class="admin-parent-contact"><?= htmlspecialchars($student['parent_phone'] ?: ($student['parent_email'] ?: 'No contact')) ?></small></td>
                                <td><strong><?= number_format((int)$student['total_points']) ?></strong></td>
                                <td><span class="admin-status <?= $student['is_active'] ? 'ok' : 'warning' ?>"><?= $student['is_active'] ? 'Enabled' : 'Disabled' ?></span></td>
                                <td>
                                    <div class="admin-row-actions">
                                        <form method="post" onsubmit="return confirm('Issue a temporary password for this student?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                            <button type="submit" name="reset_password" class="admin-action-button">Reset password</button>
                                        </form>
                                        <form method="post" onsubmit="return confirm('Permanently delete this student? This cannot be undone.')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                            <button type="submit" name="delete_student" class="admin-action-button danger">Delete</button>
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
