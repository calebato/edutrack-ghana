<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/student.php';
requireStudent();

$studentId = $_SESSION['user_id'];

$earnedBadges = dbRows(
    "SELECT b.*, sb.earned_at FROM student_badges sb 
     JOIN badges b ON sb.badge_id = b.id 
     WHERE sb.student_id = ? ORDER BY sb.earned_at DESC",
    [$studentId]
);

$allBadges = dbRows("SELECT * FROM badges ORDER BY criteria_value");
$earnedIds = array_column($earnedBadges, 'id');

$pageTitle = 'My Badges';
$activeNav = 'badges';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <div class="d-flex align-items-center gap-3">
        <div class="stat-card-sm">
            <span style="font-size:2rem">🏆</span>
            <strong><?= count($earnedBadges) ?></strong>
            <span class="text-muted small">Earned</span>
        </div>
        <div class="stat-card-sm">
            <span style="font-size:2rem">🎯</span>
            <strong><?= count($allBadges) - count($earnedBadges) ?></strong>
            <span class="text-muted small">Remaining</span>
        </div>
    </div>
</div>

<?php if (!empty($earnedBadges)): ?>
<div class="card edu-card mb-4">
    <div class="card-body">
        <h5 class="card-title mb-4">🌟 Your Badges</h5>
        <div class="badges-grid">
            <?php foreach ($earnedBadges as $badge): ?>
            <div class="badge-card earned">
                <div class="badge-icon-lg" style="color:<?= $badge['color'] ?>">🏅</div>
                <div class="badge-name-lg"><?= htmlspecialchars($badge['name']) ?></div>
                <div class="badge-desc"><?= htmlspecialchars($badge['description']) ?></div>
                <div class="badge-date text-muted small">
                    Earned <?= date('M d, Y', strtotime($badge['earned_at'])) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card edu-card">
    <div class="card-body">
        <h5 class="card-title mb-4">🔒 All Badges</h5>
        <div class="badges-grid">
            <?php foreach ($allBadges as $badge): ?>
            <?php $earned = in_array($badge['id'], $earnedIds); ?>
            <div class="badge-card <?= $earned ? 'earned' : 'locked' ?>">
                <div class="badge-icon-lg" style="<?= $earned ? 'color:'.$badge['color'] : 'filter:grayscale(1);opacity:0.4' ?>">🏅</div>
                <div class="badge-name-lg"><?= htmlspecialchars($badge['name']) ?></div>
                <div class="badge-desc"><?= htmlspecialchars($badge['description']) ?></div>
                <div class="badge-reward text-warning small">+<?= $badge['points_reward'] ?> pts</div>
                <?php if ($earned): ?>
                    <span class="badge bg-success-soft text-success">✓ Earned</span>
                <?php else: ?>
                    <span class="badge bg-light text-muted">🔒 Locked</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
