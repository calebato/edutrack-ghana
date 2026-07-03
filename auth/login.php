<?php
/**
 * EduTrack Ghana - Login Page
 * auth/login.php
 */

require_once __DIR__ . '/../auth/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/' . $_SESSION['user_type'] . '/dashboard.php');
    exit;
}

$error = '';
$msg = sanitize($_GET['msg'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email    = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $userType = $_POST['user_type'] ?? 'student';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } else {
            $result = loginUser($email, $password, $userType);

            if ($result['success']) {
                if (isset($_SESSION['must_change_password']) && $_SESSION['must_change_password'] == 1) {
                    $redirect = $userType === 'teacher'
                        ? BASE_URL . '/teacher/change_password.php'
                        : BASE_URL . '/student/change_password.php';
                } else {
                    if ($userType === 'teacher') {
                        $redirect = BASE_URL . '/teacher/dashboard.php';
                    } elseif ($userType === 'admin') {
                        $redirect = BASE_URL . '/admin/dashboard.php';
                    } else {
                        $redirect = BASE_URL . '/student/dashboard.php';
                    }
                }
                header('Location: ' . $redirect);
                exit;
            } else {
                $error = $result['error'];
            }
        }
    }
}

$csrf = generateCSRF();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – EduTrack Ghana</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">

    <style>
        html, body {
            background: #172033;
        }

        .auth-page {
            background:
                radial-gradient(circle at 18% 18%, rgba(141,228,194,.28), transparent 28%),
                radial-gradient(circle at 82% 78%, rgba(255,209,102,.24), transparent 30%),
                url('../assets/images/classroom.jpg') 42% center / cover no-repeat fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            position: relative;
            animation: classroomDrift 18s ease-in-out infinite alternate;
        }

        .auth-page::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                linear-gradient(115deg, rgba(15,23,42,.82) 0%, rgba(28,37,68,.66) 45%, rgba(8,47,73,.56) 100%),
                rgba(15, 23, 42, 0.42);
        }

        .auth-page::after {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(115deg, transparent 0 34%, rgba(255,255,255,.1) 42%, transparent 50% 100%);
            transform: translateX(-40%);
            animation: softLightSweep 9s ease-in-out infinite;
        }

        .study-shapes {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: 0;
        }

        .study-shapes span {
            position: absolute;
            display: block;
            border: 1px solid rgba(255,255,255,.18);
            background: rgba(255,255,255,.1);
            box-shadow: 0 18px 50px rgba(0,0,0,.16);
            backdrop-filter: blur(4px);
            animation: floatShape 10s ease-in-out infinite;
        }

        .study-shapes span:nth-child(1) {
            width: 88px;
            height: 58px;
            left: 7%;
            top: 18%;
            border-radius: 8px;
            transform: rotate(-9deg);
        }

        .study-shapes span:nth-child(2) {
            width: 68px;
            height: 68px;
            right: 9%;
            top: 22%;
            border-radius: 50%;
            background: rgba(141,228,194,.16);
            animation-delay: -2s;
        }

        .study-shapes span:nth-child(3) {
            width: 96px;
            height: 42px;
            left: 12%;
            bottom: 18%;
            border-radius: 999px;
            background: rgba(255,209,102,.15);
            animation-delay: -4s;
        }

        .study-shapes span:nth-child(4) {
            width: 62px;
            height: 62px;
            right: 15%;
            bottom: 14%;
            border-radius: 10px;
            transform: rotate(12deg);
            background: rgba(199,194,255,.16);
            animation-delay: -6s;
        }

        .auth-container {
            display: flex;
            width: 100%;
            max-width: 900px;
            min-height: 500px;
            background: transparent;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            position: relative;
            z-index: 1;
            animation: authEnter .65s ease-out both;
        }

        .auth-left {
            flex: 0.9;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
            color: white;
            min-width: 0;
            position: relative;
        }

        .auth-left-content { max-width: 300px; position: relative; z-index: 1; animation: contentRise .75s ease-out .12s both; }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
        }
        .logo-icon { font-size: 26px; }
        .logo-text { font-size: 20px; font-weight: 900; color: white; }
        .logo-sub { font-size: 11px; opacity: 0.7; align-self: flex-end; margin-bottom: 3px; }

        .auth-left h2 { font-size: 22px; font-weight: 800; margin-bottom: 10px; }
        .auth-left p { opacity: 0.85; line-height: 1.6; font-size: 13px; }

        .auth-features { margin: 14px 0; }
        .feature-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 7px;
            font-size: 12px;
            opacity: 0.9;
            animation: featureIn .5s ease-out both;
        }
        .feature-item:nth-child(1) { animation-delay: .2s; }
        .feature-item:nth-child(2) { animation-delay: .32s; }
        .feature-item:nth-child(3) { animation-delay: .44s; }
        .feature-item:nth-child(4) { animation-delay: .56s; }
        .feature-item span { font-size: 15px; }

        .learning-meter {
            display: flex;
            align-items: end;
            gap: 7px;
            width: 128px;
            height: 38px;
            margin-top: 15px;
            padding: 8px 10px;
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 8px;
            background: rgba(255,255,255,.1);
            backdrop-filter: blur(6px);
        }

        .learning-meter span {
            display: block;
            width: 18px;
            border-radius: 5px 5px 2px 2px;
            background: #8de4c2;
            box-shadow: 0 0 16px rgba(141,228,194,.45);
            animation: meterLift 1.8s ease-in-out infinite;
        }
        .learning-meter span:nth-child(1) { height: 12px; animation-delay: 0s; }
        .learning-meter span:nth-child(2) { height: 18px; animation-delay: .15s; background: #ffd166; }
        .learning-meter span:nth-child(3) { height: 24px; animation-delay: .3s; background: #9ee7ff; }
        .learning-meter span:nth-child(4) { height: 16px; animation-delay: .45s; background: #c7c2ff; }

        .auth-right {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
            flex: 1;
            background: rgba(255, 255, 255, 0.97);
        }

        .auth-form-wrapper { width: 100%; max-width: 360px; animation: contentRise .7s ease-out .2s both; }

        .brand-mobile { margin-bottom: 20px; }

        .auth-title { font-size: 24px; font-weight: 800; color: var(--text); margin-bottom: 5px; }
        .auth-subtitle { color: var(--text-muted); margin-bottom: 20px; font-size: 13px; }

        .user-type-toggle {
            display: flex;
            background: var(--bg);
            border-radius: 10px;
            padding: 3px;
            gap: 3px;
            margin-bottom: 20px;
        }

        .type-btn {
            flex: 1;
            border: none;
            background: transparent;
            border-radius: 7px;
            padding: 7px 6px;
            font-weight: 600;
            font-family: 'Nunito', sans-serif;
            font-size: 12px;
            cursor: pointer;
            transition: all .2s;
            color: var(--text-muted);
        }

        .type-btn:hover { color: var(--primary); transform: translateY(-1px); }

        .type-btn.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 2px 8px rgba(79,70,229,.15);
            animation: selectedPulse .32s ease-out;
        }

        .form-control-edu {
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 10px 12px;
            font-family: 'Nunito', sans-serif;
            font-size: 14px;
            transition: border-color .2s, box-shadow .2s, transform .2s;
        }

        .form-control-edu:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,.15);
            outline: none;
            transform: translateY(-1px);
        }

        .form-label { font-size: 13px; font-weight: 700; color: var(--text); }

        .input-group .btn {
            border: 2px solid var(--border);
            background: white;
            color: var(--text-muted);
            padding: 10px 14px;
            font-size: 14px;
        }

        .input-group .btn:hover {
            background: var(--bg);
        }

        .btn-edu {
            border-radius: 8px !important;
            font-weight: 700 !important;
            font-family: 'Nunito', sans-serif !important;
            padding: 9px 16px !important;
            font-size: 14px !important;
            transition: transform .2s, box-shadow .2s !important;
        }

        .btn-edu:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(79,70,229,.2);
        }

        @keyframes authEnter {
            from { opacity: 0; transform: translateY(18px) scale(.985); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes contentRise {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes featureIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: .9; transform: translateX(0); }
        }

        @keyframes meterLift {
            0%, 100% { transform: scaleY(.72); opacity: .78; }
            45% { transform: scaleY(1); opacity: 1; }
        }

        @keyframes selectedPulse {
            0% { transform: scale(.98); }
            70% { transform: scale(1.03); }
            100% { transform: scale(1); }
        }

        @keyframes classroomDrift {
            from { background-position: 40% center; }
            to { background-position: 46% center; }
        }

        @keyframes softLightSweep {
            0%, 58% { transform: translateX(-45%); opacity: 0; }
            70% { opacity: .55; }
            100% { transform: translateX(45%); opacity: 0; }
        }

        @keyframes floatShape {
            0%, 100% { translate: 0 0; }
            50% { translate: 0 -18px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }
        }

        .alert-sm {
            padding: 10px 12px;
            font-size: 13px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .mb-3 { margin-bottom: 14px !important; }

        /* Responsive */
        @media (max-width: 768px) {
            .auth-page { background-attachment: scroll; }
            .study-shapes span { opacity: .45; }
            .auth-container { flex-direction: column; min-height: auto; max-width: 430px; }
            .auth-left { padding: 20px; flex: none; }
            .auth-left-content { max-width: 100%; }
            .auth-right { padding: 24px 20px; border-radius: 8px; }
            .auth-form-wrapper { max-width: 100%; }
        }

        @media (min-width: 1600px) {
            .auth-container { max-width: 1080px; min-height: 600px; }
            .auth-left { padding: 42px; }
            .auth-left-content { max-width: 330px; }
            .auth-left h2 { font-size: 29px; }
            .auth-left p, .feature-item { font-size: 15px; }
            .auth-right { padding: 42px; }
            .auth-form-wrapper { max-width: 430px; }
            .auth-title { font-size: 30px; }
            .auth-subtitle, .type-btn, .form-control-edu, .btn-edu { font-size: 15px; }
        }
    </style>
</head>

<body class="auth-page">

<div class="study-shapes" aria-hidden="true">
    <span></span>
    <span></span>
    <span></span>
    <span></span>
</div>

<div class="auth-container">

    <!-- LEFT SIDE -->
    <div class="auth-left d-none d-lg-flex">
        <div class="auth-left-content">
            <div class="brand-logo">
                <span class="logo-icon">🎓</span>
                <span class="logo-text">EduTrack</span>
                <span class="logo-sub">Ghana</span>
            </div>

            <h2>Welcome Back!</h2>
            <p>Continue your learning journey. Every day you learn is a step closer to your dreams.</p>

            <div class="auth-features">
                <div class="feature-item"><span>🏆</span> Earn badges & rewards</div>
                <div class="feature-item"><span>📊</span> Track your progress</div>
                <div class="feature-item"><span>✓</span> Guided study suggestions</div>
                <div class="feature-item"><span>🔥</span> Keep your streak alive</div>
            </div>

            <div class="learning-meter" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
                <span></span>
            </div>

        </div>
    </div>

    <!-- RIGHT SIDE -->
    <div class="auth-right">
        <div class="auth-form-wrapper">

            <div class="brand-mobile d-lg-none text-center mb-4">
                <span class="logo-icon">🎓</span>
                <span class="logo-text" style="color:#4F46E5">EduTrack</span>
            </div>

            <h3 class="auth-title">Sign In</h3>
            <p class="auth-subtitle">Access your learning dashboard</p>

            <?php if ($msg): ?>
                <div class="alert alert-info alert-sm">
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-sm">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- USER TYPE TOGGLE -->
            <div class="user-type-toggle mb-4">
                <button type="button" class="type-btn <?= ($_POST['user_type'] ?? 'student') === 'student' ? 'active' : '' ?>" data-type="student" onclick="setType('student')">👨‍🎓 Student</button>
                <button type="button" class="type-btn <?= ($_POST['user_type'] ?? '') === 'teacher' ? 'active' : '' ?>" data-type="teacher" onclick="setType('teacher')">👨‍🏫 Teacher</button>
                <button type="button" class="type-btn <?= ($_POST['user_type'] ?? '') === 'admin' ? 'active' : '' ?>" data-type="admin" onclick="setType('admin')">🛡️ Admin</button>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="user_type" id="user_type" value="<?= htmlspecialchars($_POST['user_type'] ?? $_GET['user_type'] ?? 'student') ?>">

                <div class="mb-3">
                    <label class="form-label fw-600">Email Address</label>
                    <input type="email" name="email" class="form-control form-control-edu" placeholder="your@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-600">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" id="passwordField" class="form-control form-control-edu" placeholder="••••••••" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword()">👁</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-edu w-100 mb-3">Sign In →</button>

                <p class="text-center text-muted small">
                    Don't have an account?
                    <a href="register.php" class="text-primary fw-600">Register here</a>
                </p>
            </form>

            <div class="text-center mt-3">
                <a href="../index.php" class="text-muted small">← Back to Home</a>
            </div>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    function setType(type) {
        document.getElementById('user_type').value = type;
        document.querySelectorAll('.type-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.type === type);
        });

        const emailField = document.querySelector('[name="email"]');
        if (type === 'student') {
            emailField.placeholder = 'student@edutrack.edu.gh';
        } else if (type === 'teacher') {
            emailField.placeholder = 'teacher@edutrack.edu.gh';
        } else {
            emailField.placeholder = 'admin@edutrack.com';
        }
    }

    window.onload = function () {
        const currentType = document.getElementById('user_type').value;
        setType(currentType);
    };

    function togglePassword() {
        const f = document.getElementById('passwordField');
        f.type = f.type === 'password' ? 'text' : 'password';
    }
</script>

</body>
</html>
