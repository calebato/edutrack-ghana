<?php
require_once __DIR__ . '/../auth/auth.php';
requireTeacher();

$teacherId = $_SESSION['user_id'];
$teacher   = getCurrentUser();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Your session expired. Please reload the page and try again.');
    }

    $name    = trim($_POST['full_name'] ?? '');
    if (!$name) {
        $error = 'Name is required.';
    } else {
        dbQuery(
            'UPDATE teachers SET full_name = ? WHERE id = ?',
            [$name, $teacherId]
        );
        $_SESSION['user_name'] = $name;
        $success = 'Profile updated!';
        $teacher = getCurrentUser();
    }
}

$pageTitle = 'My Profile';
$activeNav = 'profile';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card edu-card text-center">
            <div class="card-body py-4">
                <div class="user-avatar-lg mx-auto mb-3">
                    <?= strtoupper(substr($teacher['full_name'], 0, 1)) ?>
                </div>
                <h5><?= htmlspecialchars(teacherDisplayName($teacher['full_name'])) ?></h5>
                <p class="text-muted"><?= htmlspecialchars($teacher['subject']) ?> Teacher</p>
                <p class="text-muted small"><?= htmlspecialchars($teacher['email']) ?></p>
                <span class="badge bg-primary-soft text-primary"><?= htmlspecialchars($teacher['staff_id']) ?></span>
                <div class="mt-3 text-muted small">
                    <div>Member since <?= date('M Y', strtotime($teacher['created_at'])) ?></div>
                    <div>Last login: <?= $teacher['last_login'] ? date('M d, Y', strtotime($teacher['last_login'])) : 'Never' ?></div>
                    <div>Total logins: <?= $teacher['login_count'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card edu-card">
            <div class="card-body">
                <h5 class="card-title mb-3">Edit Profile</h5>
                <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">
                    <div class="mb-3">
                        <label class="form-label fw-600">Full Name</label>
                        <input type="text" name="full_name" class="form-control form-control-edu"
                               value="<?= htmlspecialchars($teacher['full_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-600">Email</label>
                        <input type="email" class="form-control form-control-edu" value="<?= htmlspecialchars($teacher['email']) ?>" disabled>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-600">Subject</label>
                        <input type="text" class="form-control form-control-edu"
                               value="<?= htmlspecialchars($teacher['subject']) ?>" disabled>
                        <div class="form-text">Subject assignments are managed by an administrator.</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-edu">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
