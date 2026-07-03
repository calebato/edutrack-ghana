<?php
/**
 * EduTrack Ghana - Registration Page
 * auth/register.php
 */
require_once __DIR__ . '/../auth/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/' . $_SESSION['user_type'] . '/dashboard.php');
    exit;
}

$errors = [];
$success = '';
$userType = $_POST['user_type'] ?? 'student';
$schools = dbRows(
    "SELECT sc.id, sc.name, sc.region, sc.district, COUNT(t.id) AS teacher_count
     FROM schools sc
     LEFT JOIN teachers t ON t.school_id = sc.id AND t.is_active = 1
     GROUP BY sc.id, sc.name, sc.region, sc.district
     ORDER BY sc.name"
);
$subjects = dbRows('SELECT name FROM subjects ORDER BY name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $userType = $_POST['user_type'] ?? 'student';
        if ($userType === 'teacher') {
            $result = registerTeacher($_POST);
        } else {
            $result = registerStudent($_POST);
        }

        if ($result['success']) {
            $success = $userType === 'teacher'
                ? 'Registration submitted. An administrator must approve your teacher account before you can sign in.'
                : 'Account created successfully! You can now login.';
        } else {
            $errors = $result['errors'];
        }
    }
}

$initialStep = 1;
if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $errorText = strtolower(implode(' ', $errors));
    if (str_contains($errorText, 'parent')) $initialStep = 2;
    elseif (str_contains($errorText, 'password') || str_contains($errorText, 'school')) $initialStep = 3;
}
$csrf = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - EduTrack Ghana</title>
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
            min-height: auto;
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
        }

        .auth-left-content { max-width: 300px; animation: contentRise .75s ease-out .12s both; }

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
            align-items: flex-start;
            justify-content: center;
            padding: 24px;
            flex: 1;
            background: rgba(255, 255, 255, 0.97);
        }

        .auth-form-wrapper { width: 100%; max-width: 360px; margin-top: 8px; animation: contentRise .7s ease-out .2s both; }

        .brand-mobile { margin-bottom: 16px; }

        .auth-title { font-size: 24px; font-weight: 800; color: var(--text); margin-bottom: 4px; }
        .auth-subtitle { color: var(--text-muted); margin-bottom: 18px; font-size: 12px; }

        .user-type-toggle {
            display: flex;
            background: var(--bg);
            border-radius: 10px;
            padding: 3px;
            gap: 3px;
            margin-bottom: 18px;
        }

        .type-btn {
            flex: 1;
            border: none;
            background: transparent;
            border-radius: 7px;
            padding: 6px;
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

        /* Wizard */
        .wizard-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            gap: 4px;
        }

        .wizard-circle {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 65px;
        }

        .wizard-circle span {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #E5E7EB;
            color: #6B7280;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }

        .wizard-circle small {
            margin-top: 5px;
            font-weight: 600;
            font-size: 10px;
        }

        .wizard-circle.active span {
            background: #4F46E5;
            color: white;
            animation: selectedPulse .32s ease-out;
        }

        .wizard-line {
            flex: 1;
            height: 3px;
            background: #E5E7EB;
            margin: 0 3px;
        }

        .wizard-line.active {
            background: #4F46E5;
        }

        .form-control-edu {
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 9px 11px;
            font-family: 'Nunito', sans-serif;
            font-size: 13px;
            transition: border-color .2s, box-shadow .2s, transform .2s;
        }

        .form-control-edu:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,.15);
            outline: none;
            transform: translateY(-1px);
        }

        .form-label { font-size: 12px; font-weight: 700; color: var(--text); }
        .form-text { font-size: 11px; color: var(--text-muted); }
        .school-note { margin-top: 6px; padding: 8px 10px; border-radius: 8px; background: #F8FAFC; color: var(--text-muted); font-size: 10px; line-height: 1.4; }
        .teacher-approval-note { padding: 9px 10px; border: 1px solid #C7D2FE; border-radius: 8px; background: #EEF2FF; color: #3730A3; font-size: 11px; line-height: 1.45; }
        .password-wrap { position: relative; }
        .password-toggle { position: absolute; right: 9px; top: 50%; transform: translateY(-50%); padding: 3px 5px; border: 0; background: transparent; color: var(--primary); font-size: 10px; font-weight: 800; }
        .password-wrap input { padding-right: 48px; }
        .password-strength { height: 4px; margin-top: 7px; border-radius: 4px; background: #E5E7EB; overflow: hidden; }
        .password-strength span { display: block; width: 0; height: 100%; background: #EF4444; transition: width .2s, background .2s; }

        .mb-3 { margin-bottom: 12px !important; }
        .mb-4 { margin-bottom: 16px !important; }

        .btn-edu {
            border-radius: 8px !important;
            font-weight: 700 !important;
            font-family: 'Nunito', sans-serif !important;
            padding: 8px 14px !important;
            font-size: 13px !important;
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

        .alert { padding: 10px 12px; font-size: 13px; border-radius: 8px; margin-bottom: 14px; }
        .alert ul { font-size: 13px; }

        .btn-secondary { font-size: 13px; padding: 8px 12px; }
        .btn-primary { font-size: 13px; padding: 8px 12px; }

        .mt-3 { margin-top: 12px !important; }
        .mt-4 { margin-top: 14px !important; }

        h6 { font-size: 14px; font-weight: 700; margin-bottom: 12px; }

        .text-center.text-muted.small { font-size: 12px; margin-top: 14px; }

        /* Responsive */
        @media (max-width: 768px) {
            .auth-page { background-attachment: scroll; }
            .study-shapes span { opacity: .45; }
            .auth-container { flex-direction: column; max-width: 430px; }
            .auth-left { padding: 20px; flex: none; display: none; }
            .auth-right { padding: 24px 20px; max-height: 100%; border-radius: 8px; }
            .auth-form-wrapper { max-width: 100%; }
            .brand-mobile { display: block !important; }
        }

        @media (min-width: 1600px) {
            .auth-container { max-width: 1080px; }
            .auth-left { padding: 42px; }
            .auth-left-content { max-width: 330px; }
            .auth-left h2 { font-size: 29px; }
            .auth-left p, .feature-item { font-size: 15px; }
            .auth-right { padding: 34px 42px; }
            .auth-form-wrapper { max-width: 440px; }
            .auth-title { font-size: 30px; }
            .auth-subtitle, .type-btn, .form-control-edu, .btn-edu { font-size: 15px; }
            .form-label { font-size: 14px; }
        }

        .row.mb-3 { margin-bottom: 12px; }
        .col-6 { padding-right: 6px; }
        .col-6:last-child { padding-left: 6px; padding-right: 0; }
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

            <h2>Join EduTrack!</h2>
            <p>Create your free account and begin practising the Ghanaian JHS curriculum.</p>

            <div class="auth-features">
                <div class="feature-item"><span>✅</span> Free for all JHS students</div>
                <div class="feature-item"><span>📱</span> Works on any device</div>
                <div class="feature-item"><span>🏫</span> All Ghana JHS subjects</div>
                <div class="feature-item"><span>🎯</span> Exam preparation</div>
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

            <div class="brand-mobile d-lg-none text-center mb-3">
                <span class="logo-icon">🎓</span>
                <span class="logo-text" style="color:#4F46E5">EduTrack</span>
            </div>

            <h3 class="auth-title">Create Account</h3>
            <p class="auth-subtitle">Create a student or teacher account</p>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                    <br><a href="login.php" class="btn btn-success btn-sm mt-2">Login Now</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- User Type Toggle -->
            <div class="user-type-toggle mb-4">
                <button type="button" class="type-btn <?= $userType==='student'?'active':'' ?>" data-type="student" onclick="setType('student')">👨‍🎓 Student</button>
                <button type="button" class="type-btn <?= $userType==='teacher'?'active':'' ?>" data-type="teacher" onclick="setType('teacher')">👨‍🏫 Teacher</button>
            </div>

            <form method="POST" action="">
                <!-- Wizard Steps Indicator -->
                <div class="wizard-container">
                    <div class="wizard-circle active" id="wizard1">
                        <span>1</span>
                        <small>Student</small>
                    </div>
                    <div class="wizard-line" id="line1"></div>
                    <div class="wizard-circle" id="wizard2">
                        <span>2</span>
                        <small>Parent</small>
                    </div>
                    <div class="wizard-line" id="line2"></div>
                    <div class="wizard-circle" id="wizard3">
                        <span>3</span>
                        <small>Account</small>
                    </div>
                </div>

                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="user_type" id="user_type" value="<?= htmlspecialchars($userType) ?>">

                <!-- ───── STEP 1 ───── -->
                <div id="step1">
                    <div class="mb-3">
                        <label class="form-label fw-600">Full Name</label>
                        <input type="text" name="full_name" class="form-control form-control-edu" placeholder="e.g. Ama Owusu" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-600">Email Address</label>
                        <input type="email" name="email" class="form-control form-control-edu" placeholder="your@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>

                    <!-- Student fields -->
                    <div id="student-fields">
                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label fw-600">Class Level</label>
                                <select name="class_level" class="form-select form-control-edu">
                                    <option value="JHS1" <?= ($_POST['class_level']??'JHS1')==='JHS1'?'selected':'' ?>>JHS 1</option>
                                    <option value="JHS2" <?= ($_POST['class_level']??'')==='JHS2'?'selected':'' ?>>JHS 2</option>
                                    <option value="JHS3" <?= ($_POST['class_level']??'')==='JHS3'?'selected':'' ?>>JHS 3</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-600">Gender</label>
                                <select name="gender" class="form-select form-control-edu">
                                    <option value="Male" <?= ($_POST['gender'] ?? 'Male') === 'Male' ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= ($_POST['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?= ($_POST['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Teacher fields -->
                    <div id="teacher-fields" style="display:none">
                        <div class="mb-3">
                            <label class="form-label fw-600">Title</label>
                            <select name="teacher_title" class="form-select form-control-edu">
                                <?php foreach (['Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Madam'] as $title): ?>
                                    <option value="<?= $title ?>" <?= ($_POST['teacher_title'] ?? 'Mr.') === $title ? 'selected' : '' ?>><?= $title ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-600">Subject Specialization</label>
                            <select name="subject" class="form-select form-control-edu">
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= htmlspecialchars($subject['name']) ?>" <?= ($_POST['subject'] ?? '') === $subject['name'] ? 'selected' : '' ?>><?= htmlspecialchars($subject['name']) ?></option>
                                <?php endforeach; ?>
                                <option value="General" <?= ($_POST['subject'] ?? '') === 'General' ? 'selected' : '' ?>>General (All Subjects)</option>
                            </select>
                        </div>
                        <div class="teacher-approval-note">Teacher registrations are reviewed by an administrator before access is granted.</div>
                    </div>

                    <div class="text-end mt-3">
                        <button type="button" class="btn btn-primary" onclick="goNextFromStep1()">Next →</button>
                    </div>
                </div>

                <!-- ───── STEP 2 ───── -->
                <div id="step2" style="display:none;">
                    <h6>👨‍👩‍👧 Parent / Guardian Information</h6>

                    <div class="mb-3">
                        <label class="form-label fw-600">Parent Name</label>
                        <input type="text" name="parent_name" class="form-control form-control-edu" placeholder="Parent or guardian full name" value="<?= htmlspecialchars($_POST['parent_name'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-600">Parent Email</label>
                        <input type="email" name="parent_email" class="form-control form-control-edu" placeholder="parent@email.com" value="<?= htmlspecialchars($_POST['parent_email'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-600">Parent Phone</label>
                        <input type="tel" name="parent_phone" class="form-control form-control-edu"
                               inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                               title="Enter exactly 10 digits" placeholder="024XXXXXXX"
                               value="<?= htmlspecialchars($_POST['parent_phone'] ?? '') ?>">
                    </div>

                    <div class="d-flex justify-content-between mt-3">
                        <button type="button" class="btn btn-secondary" onclick="showStep(1)">← Back</button>
                        <button type="button" class="btn btn-primary" onclick="goNextFromStep2()">Next →</button>
                    </div>
                </div>

                <!-- ───── STEP 3 ───── -->
                <div id="step3" style="display:none;">
                    <div class="mb-3">
                        <label class="form-label fw-600">School</label>
                        <select name="school_id" id="school_id" class="form-select form-control-edu" required>
                            <?php foreach ($schools as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"
                                        data-location="<?= htmlspecialchars($s['district'] . ', ' . $s['region']) ?>"
                                        data-teachers="<?= (int)$s['teacher_count'] ?>"
                                        <?= (int)($_POST['school_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name']) ?><?= (int)$s['teacher_count'] === 0 ? ' — no active teachers yet' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="school-note" id="schoolNote"></div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="school_confirmed" value="1" id="schoolConfirmed" required <?= !empty($_POST['school_confirmed']) ? 'checked' : '' ?>>
                            <label class="form-check-label form-text" for="schoolConfirmed">I confirm this is my correct school.</label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-600">Password</label>
                        <div class="password-wrap">
                            <input type="password" id="password" name="password" class="form-control form-control-edu" placeholder="Min 8 chars, 1 uppercase, 1 number" required autocomplete="new-password">
                            <button type="button" class="password-toggle" onclick="togglePassword('password', this)">Show</button>
                        </div>
                        <div class="password-strength"><span id="passwordStrength"></span></div>
                        <div class="form-text">Must be 8+ characters with uppercase &amp; number</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-600">Confirm Password</label>
                        <div class="password-wrap">
                            <input type="password" id="password2" name="password2" class="form-control form-control-edu" placeholder="Repeat password" required autocomplete="new-password">
                            <button type="button" class="password-toggle" onclick="togglePassword('password2', this)">Show</button>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-secondary" onclick="goBackFromStep3()">← Back</button>
                        <button type="submit" class="btn btn-primary btn-edu">Create Account 🚀</button>
                    </div>
                </div>

                <p class="text-center text-muted small">
                    Already have an account?
                    <a href="login.php" class="text-primary fw-600">Sign in</a>
                </p>
            </form>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    function showStep(step) {
        [1, 2, 3].forEach(function(s) {
            document.getElementById('step' + s).style.display = 'none';
            document.getElementById('wizard' + s).classList.remove('active');
        });

        document.getElementById('step' + step).style.display = 'block';
        document.getElementById('wizard' + step).classList.add('active');

        document.getElementById('line1').classList.toggle('active', step >= 2);
        document.getElementById('line2').classList.toggle('active', step >= 3);
    }

    function goNextFromStep1() {
        if (!validateStep(1)) return;
        var type = document.getElementById('user_type').value;
        if (type === 'teacher') {
            showStep(3);
            document.getElementById('line1').classList.add('active');
        } else {
            showStep(2);
        }
    }

    function goNextFromStep2() {
        if (validateStep(2)) showStep(3);
    }

    function goBackFromStep3() {
        var type = document.getElementById('user_type').value;
        if (type === 'teacher') {
            showStep(1);
        } else {
            showStep(2);
        }
    }

    function setType(type) {
        document.getElementById('user_type').value = type;

        document.querySelectorAll('.type-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.type === type);
        });

        var isStudent = (type === 'student');

        document.getElementById('student-fields').style.display = isStudent ? 'block' : 'none';
        document.getElementById('teacher-fields').style.display = isStudent ? 'none' : 'block';

        document.getElementById('wizard2').style.display = isStudent ? 'flex' : 'none';
        document.getElementById('line2').style.display = isStudent ? 'block' : 'none';

        document.querySelector('#wizard1 small').innerText = isStudent ? 'Student' : 'Teacher';
        document.querySelector('#wizard3 small').innerText = 'Account';

        showStep(1);
    }

    function validateStep(step) {
        var fields = document.querySelectorAll('#step' + step + ' input, #step' + step + ' select, #step' + step + ' textarea');
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].offsetParent !== null && !fields[i].checkValidity()) {
                fields[i].reportValidity();
                return false;
            }
        }
        return true;
    }

    function togglePassword(id, button) {
        var input = document.getElementById(id);
        input.type = input.type === 'password' ? 'text' : 'password';
        button.textContent = input.type === 'password' ? 'Show' : 'Hide';
    }

    function updateSchoolNote() {
        var select = document.getElementById('school_id');
        var option = select.options[select.selectedIndex];
        var teachers = Number(option.dataset.teachers || 0);
        document.getElementById('schoolNote').textContent = option.dataset.location + ' · ' +
            (teachers ? teachers + ' active teacher' + (teachers === 1 ? '' : 's') : 'No active teachers registered yet');
    }

    document.getElementById('school_id').addEventListener('change', function () {
        document.getElementById('schoolConfirmed').checked = false;
        updateSchoolNote();
    });

    document.getElementById('password').addEventListener('input', function () {
        var value = this.value;
        var score = [value.length >= 8, /[A-Z]/.test(value), /[0-9]/.test(value), /[^A-Za-z0-9]/.test(value)].filter(Boolean).length;
        var bar = document.getElementById('passwordStrength');
        bar.style.width = (score * 25) + '%';
        bar.style.background = score < 2 ? '#EF4444' : (score < 4 ? '#F59E0B' : '#10B981');
    });

    // Initialize
    setType('<?= htmlspecialchars($userType) ?>');
    showStep(<?= (int)$initialStep ?>);
    updateSchoolNote();
</script>

</body>
</html>
