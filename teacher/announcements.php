<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../includes/log_helper.php';

requireTeacher();

$teacherId = $_SESSION['user_id'];
$teacher   = getCurrentUser();

// Handle post
$success = '';
$error   = '';
$editAnnouncement = null;

// EDIT MODE - fetch announcement if editing
$editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
if ($editingId) {
    $editAnnouncement = dbRow(
        "SELECT * FROM announcements WHERE id = ? AND teacher_id = ?",
        [$editingId, $teacherId]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Your session expired. Please reload the page and try again.');
    }

    if (isset($_POST['delete_announcement'])) {
        $announcementId = (int)($_POST['announcement_id'] ?? 0);
        dbQuery(
            "UPDATE announcements SET is_active = 0 WHERE id = ? AND teacher_id = ?",
            [$announcementId, $teacherId]
        );
        addLog(null, $teacher['full_name'], 'Teacher removed an announcement');
        header('Location: announcements.php?msg=deleted');
        exit;
    }

    $id       = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $title    = trim($_POST['title'] ?? '');
    $content  = trim($_POST['content'] ?? '');
    $target   = $_POST['target'] ?? 'all';
    $scheduled_at = !empty($_POST['scheduled_at']) ? $_POST['scheduled_at'] : null;
    $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;

    if (!in_array($target, ['all', 'JHS1', 'JHS2', 'JHS3'], true)) {
        $error = 'Select a valid target audience.';
    } elseif (!$title || !$content) {

        $error = 'Title and content are required.';

    } else {

        if ($id) {
            // UPDATE
            dbQuery(
                "UPDATE announcements SET title = ?, content = ?, target = ?, scheduled_at = ?, is_pinned = ?, edited_at = NOW() WHERE id = ? AND teacher_id = ?",
                [$title, $content, $target, $scheduled_at, $is_pinned, $id, $teacherId]
            );

            addLog(
                null,
                $teacher['full_name'],
                "Teacher updated announcement: " . $title
            );

            $success = 'Announcement updated successfully!';
        } else {
            // CREATE
            dbInsert(
                "INSERT INTO announcements (teacher_id, title, content, target, scheduled_at, is_pinned) VALUES (?, ?, ?, ?, ?, ?)",
                [$teacherId, $title, $content, $target, $scheduled_at, $is_pinned]
            );

            addLog(
                null,
                $teacher['full_name'],
                "Teacher posted announcement: " . $title
            );

            $success = 'Announcement posted successfully!';
        }
    }
}

$announcements = dbRows(
    "SELECT * FROM announcements WHERE teacher_id = ? AND is_active = 1 ORDER BY is_pinned DESC, created_at DESC",
    [$teacherId]
);

$pageTitle = 'Announcements';
$activeNav = 'announcements';

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>

    <div class="alert alert-success">
        Announcement removed.
    </div>

<?php endif; ?>

<div class="row g-4">

    <div class="col-lg-5">

        <div class="card edu-card">

            <div class="card-body">

                <h5 class="card-title mb-3">
                    <?= $editAnnouncement ? '✏️ Edit Announcement' : '📢 Post Announcement' ?>
                </h5>

                <?php if ($success): ?>

                    <div class="alert alert-success">
                        <?= $success ?>
                    </div>

                <?php endif; ?>

                <?php if ($error): ?>

                    <div class="alert alert-danger">
                        <?= $error ?>
                    </div>

                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">

                    <?php if($editAnnouncement): ?>
                        <input type="hidden" name="id" value="<?= $editAnnouncement['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">

                        <label class="form-label fw-600">
                            Title
                        </label>

                        <input
                            type="text"
                            name="title"
                            class="form-control form-control-edu"
                            placeholder="Announcement title"
                            value="<?= $editAnnouncement ? htmlspecialchars($editAnnouncement['title']) : '' ?>"
                            required
                            maxlength="200"
                        >

                    </div>

                    <div class="mb-3">

                        <label class="form-label fw-600">
                            Message
                        </label>

                        <textarea
                            name="content"
                            class="form-control form-control-edu"
                            rows="4"
                            placeholder="Write your announcement..."
                            required
                        ><?= $editAnnouncement ? htmlspecialchars($editAnnouncement['content']) : '' ?></textarea>

                    </div>

                    <div class="mb-4">

                        <label class="form-label fw-600">
                            Target Audience
                        </label>

                        <select
                            name="target"
                            class="form-select form-control-edu"
                        >

                            <option value="all" <?= (!$editAnnouncement || $editAnnouncement['target'] === 'all') ? 'selected' : '' ?>>
                                All Students
                            </option>

                            <option value="JHS1" <?= ($editAnnouncement && $editAnnouncement['target'] === 'JHS1') ? 'selected' : '' ?>>
                                JHS 1 Only
                            </option>

                            <option value="JHS2" <?= ($editAnnouncement && $editAnnouncement['target'] === 'JHS2') ? 'selected' : '' ?>>
                                JHS 2 Only
                            </option>

                            <option value="JHS3" <?= ($editAnnouncement && $editAnnouncement['target'] === 'JHS3') ? 'selected' : '' ?>>
                                JHS 3 Only
                            </option>

                        </select>

                    </div>

                    <div class="mb-4">

                        <label class="form-label fw-600">
                            Schedule for Later (Optional)
                        </label>

                        <input
                            type="datetime-local"
                            name="scheduled_at"
                            class="form-control form-control-edu"
                            value="<?= $editAnnouncement && $editAnnouncement['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($editAnnouncement['scheduled_at'])) : '' ?>"
                        >

                        <small class="text-muted">Leave empty to post immediately</small>

                    </div>

                    <div class="mb-4">

                        <div class="form-check">

                            <input
                                type="checkbox"
                                name="is_pinned"
                                class="form-check-input"
                                id="isPinned"
                                <?= ($editAnnouncement && $editAnnouncement['is_pinned']) ? 'checked' : '' ?>
                            >

                            <label class="form-check-label" for="isPinned">
                                📌 Pin to top (mark as important)
                            </label>

                        </div>

                    </div>

                    <div class="d-flex gap-2">

                        <button
                            type="submit"
                            class="btn btn-primary btn-edu flex-grow-1"
                        >
                            <?= $editAnnouncement ? '✏️ Update Announcement' : '📢 Post Announcement' ?>
                        </button>

                        <?php if($editAnnouncement): ?>
                            <a href="announcements.php" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        <?php endif; ?>

                    </div>

                </form>

            </div>

        </div>

    </div>

    <div class="col-lg-7">

        <div class="card edu-card">

            <div class="card-body">

                <h5 class="card-title mb-3">
                    📋 Your Announcements
                </h5>

                <?php if (empty($announcements)): ?>

                    <p class="text-muted text-center py-4">
                        No announcements yet. Post one to notify your students!
                    </p>

                <?php else: ?>

                    <?php foreach ($announcements as $ann): ?>

                    <div class="announcement-item announcement-teacher mb-3">

                        <div class="d-flex justify-content-between align-items-start">

                            <div>

                                <?php if($ann['is_pinned']): ?>
                                    <span class="badge bg-warning text-dark me-2">📌 PINNED</span>
                                <?php endif; ?>

                                <div class="ann-title">
                                    <?= htmlspecialchars($ann['title']) ?>
                                </div>

                                <div class="ann-body mt-1">
                                    <?= htmlspecialchars($ann['content']) ?>
                                </div>

                                <div class="mt-2">

                                    <span class="badge bg-primary-soft text-primary me-2">

                                        <?= $ann['target'] === 'all'
                                            ? 'All Classes'
                                            : $ann['target'] ?>

                                    </span>

                                    <?php if($ann['scheduled_at'] && strtotime($ann['scheduled_at']) > time()): ?>
                                        <span class="badge bg-warning text-dark me-2">
                                            ⏰ Scheduled: <?= date('M d, H:i', strtotime($ann['scheduled_at'])) ?>
                                        </span>
                                    <?php endif; ?>

                                    <span class="text-muted small">

                                        <?= date(
                                            'M d, Y H:i',
                                            strtotime($ann['created_at'])
                                        ) ?>

                                    </span>

                                </div>

                            </div>

                            <div class="ms-2">

                                <a
                                    href="?edit=<?= $ann['id'] ?>"
                                    class="btn btn-sm btn-outline-primary me-1"
                                    title="Edit announcement"
                                >
                                    ✏️
                                </a>

                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove this announcement?')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">
                                    <input type="hidden" name="announcement_id" value="<?= (int)$ann['id'] ?>">
                                    <button type="submit" name="delete_announcement" class="btn btn-sm btn-outline-danger" title="Delete announcement">
                                    🗑
                                    </button>
                                </form>

                            </div>

                        </div>

                    </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
