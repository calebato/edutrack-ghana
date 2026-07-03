<?php

require_once __DIR__ . '/../auth/auth.php';
requireTeacher();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } else {
        $password = $_POST['new_password'] ?? '';
        $confirmation = $_POST['confirm_password'] ?? '';

        if ($password !== $confirmation) {
            $errors[] = 'Passwords do not match.';
        }
        $errors = array_merge($errors, validatePassword($password));

        if (!$errors) {
            dbQuery(
                'UPDATE teachers SET password_hash = ?, must_change_password = 0 WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int)$_SESSION['user_id']]
            );
            $_SESSION['must_change_password'] = 0;
            session_regenerate_id(true);
            header('Location: ' . BASE_URL . '/teacher/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password | <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; display: grid; place-items: center; background: #f4f7fb; }
        .password-panel { width: min(400px, calc(100% - 32px)); }
    </style>
</head>
<body>
<main class="password-panel bg-white border rounded-3 p-4 shadow-sm">
    <h1 class="h4 mb-2">Change your password</h1>
    <p class="text-muted">Choose a password with at least eight characters, one uppercase letter, and one number.</p>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">
        <div class="mb-3">
            <label for="newPassword" class="form-label">New password</label>
            <input id="newPassword" type="password" name="new_password" class="form-control" required autocomplete="new-password">
        </div>
        <div class="mb-4">
            <label for="confirmPassword" class="form-label">Confirm password</label>
            <input id="confirmPassword" type="password" name="confirm_password" class="form-control" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary w-100">Update password</button>
    </form>
</main>
</body>
</html>
