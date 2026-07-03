<?php
/**
 * EduTrack Ghana - Shared Header
 * includes/header.php
 * 
 * Usage: require_once with $pageTitle set and $activeNav set
 */
$user = getCurrentUser();
$userType = $_SESSION['user_type'] ?? 'student';
$displayName = $userType === 'teacher'
    ? teacherDisplayName($user['full_name'] ?? '')
    : ($user['full_name'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'EduTrack Ghana') ?> – EduTrack Ghana</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
</head>

<body class="dashboard-body">

<!-- Sidebar -->
<div class="sidebar" id="sidebar">

    <div class="sidebar-brand">
        <span class="logo-icon" aria-hidden="true">🎓</span>
        <span class="logo-text">EduTrack Ghana</span>
    </div>

    <?php if ($userType === 'student'): ?>

    <!-- Student Navigation -->
    <nav class="sidebar-nav">

        <a href="<?= BASE_URL ?>/student/dashboard.php"
           class="nav-item <?= ($activeNav??'')==='dashboard'?'active':'' ?>">
            <span class="nav-icon">🏠</span> Dashboard
        </a>

        <a href="<?= BASE_URL ?>/student/subjects.php"
           class="nav-item <?= ($activeNav??'')==='subjects'?'active':'' ?>">
            <span class="nav-icon">📚</span> My Subjects
        </a>

        <a href="<?= BASE_URL ?>/student/quizzes.php"
           class="nav-item <?= ($activeNav??'')==='quizzes'?'active':'' ?>">
            <span class="nav-icon">❓</span> Quizzes
        </a>

        <a href="<?= BASE_URL ?>/student/progress.php"
           class="nav-item <?= ($activeNav??'')==='progress'?'active':'' ?>">
            <span class="nav-icon">📈</span> My Progress
        </a>

        <a href="<?= BASE_URL ?>/student/badges.php"
           class="nav-item <?= ($activeNav??'')==='badges'?'active':'' ?>">
            <span class="nav-icon">🏆</span> Badges
        </a>

        <a href="<?= BASE_URL ?>/student/leaderboard.php"
           class="nav-item <?= ($activeNav??'')==='leaderboard'?'active':'' ?>">
            <span class="nav-icon">🥇</span> Leaderboard
        </a>

        <a href="<?= BASE_URL ?>/student/accessibility.php"
           class="nav-item <?= ($activeNav??'')==='accessibility'?'active':'' ?>">
            <span class="nav-icon">🎙️</span> Voice Access
        </a>

        <!-- REPORT VIOLATION -->
        <a href="<?= BASE_URL ?>/student/report_violation.php"
           class="nav-item <?= ($activeNav??'')==='report_violation'?'active':'' ?>">
            <span class="nav-icon">🚨</span> Report Violation
        </a>

        <a href="<?= BASE_URL ?>/student/profile.php"
           class="nav-item <?= ($activeNav??'')==='profile'?'active':'' ?>">
            <span class="nav-icon">👤</span> Profile
        </a>

    </nav>

    <?php else: ?>

    <!-- Teacher Navigation -->
    <nav class="sidebar-nav">

        <a href="<?= BASE_URL ?>/teacher/dashboard.php"
           class="nav-item <?= ($activeNav??'')==='dashboard'?'active':'' ?>">
            <span class="nav-icon">🏠</span> Dashboard
        </a>

        <a href="<?= BASE_URL ?>/teacher/students.php"
           class="nav-item <?= ($activeNav??'')==='students'?'active':'' ?>">
            <span class="nav-icon">👨‍🎓</span> Students
        </a>

        <a href="<?= BASE_URL ?>/teacher/topics.php"
           class="nav-item <?= ($activeNav??'')==='topics'?'active':'' ?>">
            <span class="nav-icon">T</span> Manage Topics
        </a>

        <!-- CREATE QUIZ -->
        <a href="<?= BASE_URL ?>/teacher/create_quiz.php"
           class="nav-item <?= ($activeNav??'')==='create_quiz'?'active':'' ?>">
            <span class="nav-icon">📝</span> Create Quiz
        </a>

        <a href="<?= BASE_URL ?>/teacher/analytics.php"
           class="nav-item <?= ($activeNav??'')==='analytics'?'active':'' ?>">
            <span class="nav-icon">📊</span> Analytics
        </a>

        <a href="<?= BASE_URL ?>/teacher/reports.php"
           class="nav-item <?= ($activeNav??'')==='reports'?'active':'' ?>">
            <span class="nav-icon">📋</span> Reports
        </a>

        <a href="<?= BASE_URL ?>/teacher/announcements.php"
           class="nav-item <?= ($activeNav??'')==='announcements'?'active':'' ?>">
            <span class="nav-icon">📢</span> Announcements
        </a>

        <!-- VIOLATIONS -->
        <a href="<?= BASE_URL ?>/teacher/violations.php"
           class="nav-item <?= ($activeNav??'')==='violations'?'active':'' ?>">
            <span class="nav-icon">🚨</span> Violations
        </a>

        <a href="<?= BASE_URL ?>/teacher/profile.php"
           class="nav-item <?= ($activeNav??'')==='profile'?'active':'' ?>">
            <span class="nav-icon">👤</span> Profile
        </a>

    </nav>

    <?php endif; ?>

</div>

<!-- Main Content Area -->
<div class="main-content" id="mainContent">

    <!-- Top Bar -->
    <div class="topbar">

        <button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>

        <div class="topbar-title">
            <?= htmlspecialchars($pageTitle ?? 'Dashboard') ?>
        </div>

        <div class="topbar-right">

            <?php if ($userType === 'student' && $user): ?>

                <div class="streak-badge" title="Current streak">
                    🔥 <?= (int)$user['current_streak'] ?> days
                </div>

                <div class="points-badge" title="Total points">
                    ⭐ <?= number_format($user['total_points']) ?>
                </div>

            <?php endif; ?>

            <div class="dropdown user-menu">
                <button class="user-avatar-top user-avatar-button dropdown-toggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Open account menu">
                    <?= strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end account-dropdown">
                    <li class="account-dropdown-header">
                        <strong><?= htmlspecialchars($displayName) ?></strong>
                        <span><?= ucfirst(htmlspecialchars($userType)) ?></span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="<?= BASE_URL ?>/<?= $userType ?>/profile.php">
                            Profile
                        </a>
                    </li>
                    <?php if (in_array($userType, ['student', 'teacher'], true)): ?>
                    <li>
                        <a class="dropdown-item" href="<?= BASE_URL ?>/<?= $userType ?>/change_password.php">
                            Change Password
                        </a>
                    </li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item account-logout" href="<?= BASE_URL ?>/auth/logout.php">
                            Logout
                        </a>
                    </li>
                </ul>
            </div>

        </div>
    </div>

    <!-- Page Content starts below -->
    <div class="page-content">
