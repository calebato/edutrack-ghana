<?php
require_once __DIR__ . '/../auth/auth.php';
requireStudent();

$studentId = (int)$_SESSION['user_id'];
$student = getCurrentUser();
$success = '';
$error = '';

$learningDescriptions = [
    'visual' => 'Diagrams, colours, charts, and written examples.',
    'auditory' => 'Spoken explanations and discussion.',
    'kinesthetic' => 'Practice, activities, and hands-on tasks.',
    'reading' => 'Notes, examples, and written explanations.',
];
$difficultyDescriptions = [
    'beginner' => 'Build confidence with easier questions.',
    'intermediate' => 'Balance revision with moderate challenge.',
    'advanced' => 'Use harder questions for an extra challenge.',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } else {
        $name = trim((string)($_POST['full_name'] ?? ''));
        $style = (string)($_POST['learning_style'] ?? 'visual');
        $difficulty = (string)($_POST['difficulty_level'] ?? 'beginner');
        if ($name === '') {
            $error = 'Name is required.';
        } elseif (!isset($learningDescriptions[$style]) || !isset($difficultyDescriptions[$difficulty])) {
            $error = 'Please choose valid learning preferences.';
        } else {
            dbQuery(
                'UPDATE students SET full_name=?,learning_style=?,difficulty_level=? WHERE id=?',
                [$name, $style, $difficulty, $studentId]
            );
            $_SESSION['user_name'] = $name;
            $success = 'Profile updated successfully.';
            $student = getCurrentUser();
        }
    }
}

$initial = strtoupper(substr($student['full_name'] ?? 'S', 0, 1));
$dob = !empty($student['date_of_birth']) ? date('M j, Y', strtotime($student['date_of_birth'])) : 'Not added';
$lastLogin = !empty($student['last_login']) ? date('M j, Y g:i A', strtotime($student['last_login'])) : 'No login recorded';
$joined = !empty($student['created_at']) ? date('M Y', strtotime($student['created_at'])) : 'Unknown';
$parentName = $student['parent_name'] ?: 'Not added';
$parentEmail = $student['parent_email'] ?: 'Not added';
$parentPhone = $student['parent_phone'] ?: 'Not added';

$pageTitle = 'My Profile';
$activeNav = 'profile';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="card edu-card compact-profile-hero mb-3">
    <div class="card-body">
        <div class="compact-profile-person">
            <div class="user-avatar-lg"><?= $initial ?></div>
            <div>
                <h3><?= htmlspecialchars($student['full_name']) ?></h3>
                <div class="compact-profile-meta">
                    <span><?= htmlspecialchars($student['class_level']) ?></span>
                    <span><?= htmlspecialchars($student['gender']) ?></span>
                    <span><?= htmlspecialchars($student['student_id']) ?></span>
                    <span>Joined <?= htmlspecialchars($joined) ?></span>
                </div>
            </div>
        </div>
        <div class="compact-profile-stats">
            <div><strong><?= number_format((int)$student['total_points']) ?></strong><span>Points</span></div>
            <div><strong><?= (int)$student['current_streak'] ?></strong><span>Streak</span></div>
            <div><strong><?= (int)$student['longest_streak'] ?></strong><span>Best</span></div>
        </div>
    </div>
</section>

<?php if ($success): ?><div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3 compact-profile-layout">
    <div class="col-xl-7">
        <section class="card edu-card h-100">
            <div class="card-body compact-profile-card-body">
                <div class="compact-section-heading">
                    <div><h5>Edit Profile</h5><p>Update your name and learning preferences.</p></div>
                    <span class="badge bg-purple-soft text-purple"><?= ucfirst($student['difficulty_level']) ?></span>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">
                    <div class="mb-3">
                        <label class="form-label fw-600">Full Name</label>
                        <input type="text" name="full_name" class="form-control form-control-edu" value="<?= htmlspecialchars($student['full_name']) ?>" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-600">Learning Style</label>
                            <select name="learning_style" class="form-select form-control-edu">
                                <?php foreach ($learningDescriptions as $value => $description): ?><option value="<?= $value ?>" <?= $student['learning_style'] === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?>
                            </select>
                            <div class="form-help-text"><?= htmlspecialchars($learningDescriptions[$student['learning_style']] ?? $learningDescriptions['visual']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-600">Difficulty Preference</label>
                            <select name="difficulty_level" class="form-select form-control-edu">
                                <?php foreach ($difficultyDescriptions as $value => $description): ?><option value="<?= $value ?>" <?= $student['difficulty_level'] === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?>
                            </select>
                            <div class="form-help-text"><?= htmlspecialchars($difficultyDescriptions[$student['difficulty_level']] ?? $difficultyDescriptions['beginner']) ?></div>
                        </div>
                    </div>
                    <div class="compact-learning-note mt-3"><strong>Personalized learning</strong><span>These preferences help EduTrack tailor recommendations and quiz practice.</span></div>
                    <div class="d-flex gap-2 flex-wrap mt-3">
                        <button type="submit" class="btn btn-primary btn-edu">Save Changes</button>
                        <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn btn-outline-secondary btn-edu">Back to Dashboard</a>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <div class="col-xl-5">
        <section class="card edu-card h-100">
            <div class="card-body compact-profile-card-body">
                <div class="compact-detail-section">
                    <h5>Account Details</h5>
                    <div class="profile-detail-list compact">
                        <div><span>Email</span><strong><?= htmlspecialchars($student['email']) ?></strong></div>
                        <div><span>Date of Birth</span><strong><?= htmlspecialchars($dob) ?></strong></div>
                        <div><span>Last Login</span><strong><?= htmlspecialchars($lastLogin) ?></strong></div>
                    </div>
                </div>
                <div class="compact-detail-section guardian">
                    <h5>Parent / Guardian</h5>
                    <div class="profile-detail-list compact">
                        <div><span>Name</span><strong><?= htmlspecialchars($parentName) ?></strong></div>
                        <div><span>Email</span><strong><?= htmlspecialchars($parentEmail) ?></strong></div>
                        <div><span>Phone</span><strong><?= htmlspecialchars($parentPhone) ?></strong></div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
