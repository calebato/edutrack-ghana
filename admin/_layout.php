<?php

/**
 * Shared admin shell used by management pages.
 * Pages remain responsible for authentication and loading their own data.
 */
function renderAdminHeader(string $title, string $active, int $pendingViolations = 0, int $pendingTopics = 0): void {
    $adminName = trim((string)($_SESSION['user_name'] ?? 'System Admin')) ?: 'System Admin';
    $adminInitial = strtoupper(substr($adminName, 0, 1));
    $items = [
        'dashboard' => ['D', 'Dashboard', 'dashboard.php'],
        'students' => ['S', 'Students', 'students.php'],
        'teachers' => ['T', 'Teachers', 'teachers.php'],
        'subjects' => ['B', 'Subjects', 'subjects.php'],
        'topics' => ['C', 'Topics', 'topics.php'],
        'announcements' => ['A', 'Announcements', 'announcements.php'],
        'violations' => ['!', 'Violations', 'violations.php'],
        'logs' => ['L', 'System Logs', 'logs.php'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - EduTrack Ghana</title>
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
        <?php [$icon, $label, $url] = $items['dashboard']; ?><a class="<?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= $url ?>"><span><?= $icon ?></span><?= $label ?></a>
        <span class="admin-nav-label">Management</span>
        <?php foreach (['students','teachers','subjects','topics','announcements'] as $key): [$icon, $label, $url] = $items[$key]; ?>
            <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= $url ?>"><span><?= $icon ?></span><?= $label ?><?php if ($key === 'topics' && $pendingTopics): ?><b><?= $pendingTopics ?></b><?php endif; ?></a>
        <?php endforeach; ?>
        <span class="admin-nav-label">Oversight</span>
        <?php foreach (['violations','logs'] as $key): [$icon, $label, $url] = $items[$key]; ?>
            <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= $url ?>"><span><?= $icon ?></span><?= $label ?><?php if ($key === 'violations' && $pendingViolations): ?><b><?= $pendingViolations ?></b><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
<main class="admin-main">
    <header class="admin-topbar">
        <button class="admin-menu-button" id="adminMenuButton" type="button" aria-label="Open navigation">&#9776;</button>
        <div class="admin-topbar-title"><?= htmlspecialchars($title) ?></div>
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
<?php }

function renderAdminFooter(): void { ?>
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
<?php }
