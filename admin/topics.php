<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/_layout.php';
requireAdmin();
$csrf = generateCSRF();
$adminId = (int)($_SESSION['user_id'] ?? 0);
$errors = [];
$message = $_SESSION['admin_topic_flash'] ?? '';
unset($_SESSION['admin_topic_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $topicId = (int)($_POST['topic_id'] ?? 0);
        if (in_array($action, ['approve','reject','deactivate','activate'], true)) {
            if ($action === 'approve') {
                dbQuery("UPDATE topics SET approval_status='approved',is_active=1,reviewed_by_admin_id=?,reviewed_at=NOW(),rejection_reason=NULL WHERE id=?", [$adminId, $topicId]); $flash = 'Topic approved and published.';
            } elseif ($action === 'reject') {
                $reason = trim((string)($_POST['rejection_reason'] ?? 'Please revise this topic and submit it again.'));
                dbQuery("UPDATE topics SET approval_status='rejected',is_active=0,reviewed_by_admin_id=?,reviewed_at=NOW(),rejection_reason=? WHERE id=?", [$adminId, $reason, $topicId]); $flash = 'Topic returned to the teacher.';
            } elseif ($action === 'deactivate') {
                dbQuery('UPDATE topics SET is_active=0,reviewed_by_admin_id=?,reviewed_at=NOW() WHERE id=?', [$adminId, $topicId]); $flash = 'Topic deactivated.';
            } else {
                dbQuery("UPDATE topics SET is_active=1,approval_status='approved',reviewed_by_admin_id=?,reviewed_at=NOW(),rejection_reason=NULL WHERE id=?", [$adminId, $topicId]); $flash = 'Topic activated.';
            }
            $_SESSION['admin_topic_flash'] = $flash;
            header('Location: topics.php' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : '')); exit;
        }

        if ($action === 'save') {
            $subjectId = (int)($_POST['subject_id'] ?? 0); $schoolId = (int)($_POST['school_id'] ?? 0); $schoolValue = $schoolId > 0 ? $schoolId : null;
            $title = trim((string)($_POST['title'] ?? '')); $description = trim((string)($_POST['description'] ?? '')); $content = trim((string)($_POST['content'] ?? ''));
            $classLevel = (string)($_POST['class_level'] ?? ''); $difficulty = (string)($_POST['difficulty'] ?? '');
            $sequence = max(1, (int)($_POST['sequence_order'] ?? 1)); $minutes = max(5, min(180, (int)($_POST['estimated_minutes'] ?? 30)));
            if ($title === '' || mb_strlen($title) > 200) $errors[] = 'Enter a valid title.';
            if (!dbRow('SELECT id FROM subjects WHERE id=?', [$subjectId])) $errors[] = 'Choose a valid subject.';
            if (!in_array($classLevel, ['JHS1','JHS2','JHS3'], true)) $errors[] = 'Choose a valid class.';
            if (!in_array($difficulty, ['easy','medium','hard'], true)) $errors[] = 'Choose a valid difficulty.';
            if ($content === '') $errors[] = 'Lesson content is required.';
            if (!$errors) {
                if ($topicId) {
                    dbQuery('UPDATE topics SET subject_id=?,school_id=?,title=?,description=?,content=?,class_level=?,difficulty=?,sequence_order=?,estimated_minutes=?,reviewed_by_admin_id=?,reviewed_at=NOW() WHERE id=?', [$subjectId, $schoolValue, $title, $description, $content, $classLevel, $difficulty, $sequence, $minutes, $adminId, $topicId]); $flash = 'Topic updated.';
                } else {
                    dbInsert("INSERT INTO topics (subject_id,school_id,title,description,content,class_level,difficulty,sequence_order,estimated_minutes,approval_status,is_active,reviewed_by_admin_id,reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,'approved',1,?,NOW())", [$subjectId, $schoolValue, $title, $description, $content, $classLevel, $difficulty, $sequence, $minutes, $adminId]); $flash = 'Topic created and published.';
                }
                $_SESSION['admin_topic_flash'] = $flash; header('Location: topics.php'); exit;
            }
        }
    }
}

$editing = null;
if (isset($_GET['edit'])) { $editId=(int)$_GET['edit']; $editing=dbRow('SELECT * FROM topics WHERE id=?', [$editId]); }
elseif (isset($_GET['new'])) { $editing=['id'=>0,'subject_id'=>0,'school_id'=>null,'title'=>'','description'=>'','content'=>'','class_level'=>'JHS1','difficulty'=>'easy','sequence_order'=>1,'estimated_minutes'=>30]; }
$subjectOptions = dbRows('SELECT id,name FROM subjects ORDER BY name');
$schoolOptions = dbRows('SELECT id,name FROM schools ORDER BY name');
$status = (string)($_GET['status'] ?? 'pending'); $validStatuses=['pending','approved','rejected','inactive','all']; if (!in_array($status,$validStatuses,true)) $status='pending';
$where = $status === 'all' ? '1=1' : ($status === 'inactive' ? 't.is_active=0' : 't.approval_status=?');
$topicParams = in_array($status, ['all', 'inactive'], true) ? [] : [$status];
$topics = dbRows("SELECT t.*,s.name subject_name,sc.name school_name,tr.full_name teacher_name,(SELECT COUNT(*) FROM quizzes q WHERE q.topic_id=t.id) quiz_count FROM topics t JOIN subjects s ON s.id=t.subject_id LEFT JOIN schools sc ON sc.id=t.school_id LEFT JOIN teachers tr ON tr.id=t.created_by_teacher_id WHERE $where ORDER BY FIELD(t.approval_status,'pending','rejected','approved'),t.created_at DESC,t.id DESC", $topicParams);
$topicCounts=['pending'=>0,'approved'=>0,'rejected'=>0,'inactive'=>0]; foreach(dbRows('SELECT approval_status,is_active,COUNT(*) total FROM topics GROUP BY approval_status,is_active') as $row){ $topicCounts[$row['approval_status']]+=(int)$row['total']; if(!$row['is_active'])$topicCounts['inactive']+=(int)$row['total']; }
$pendingViolations=(int)dbValue("SELECT COUNT(*) FROM violation_reports WHERE status='Pending'");
renderAdminHeader('Manage Topics', 'topics', $pendingViolations, $topicCounts['pending']);
?>
<section class="admin-page-heading"><div><span class="admin-eyebrow">Curriculum review</span><h1>Topics</h1><p>Approve teacher submissions and manage lesson visibility.</p></div><a class="admin-primary-button" href="?new=1">Create topic</a></section>
<?php if ($message): ?><div class="admin-flash success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="admin-flash danger"><?php foreach($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<section class="admin-compact-metrics admin-teacher-metrics"><div><strong><?= $topicCounts['pending'] ?></strong><span>Pending review</span></div><div><strong><?= $topicCounts['approved'] ?></strong><span>Approved</span></div><div><strong><?= $topicCounts['rejected'] ?></strong><span>Rejected</span></div><div><strong><?= $topicCounts['inactive'] ?></strong><span>Inactive</span></div></section>

<?php if ($editing): ?><section class="admin-panel admin-form-panel mb-3"><div class="admin-panel-header"><div><h2><?= (int)$editing['id'] ? 'Edit topic' : 'Create topic' ?></h2><p>Set curriculum details and publication scope.</p></div><a href="topics.php">Cancel</a></div>
<form method="post" class="admin-form-grid two"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="topic_id" value="<?= (int)$editing['id'] ?>">
<div class="admin-form-field"><label>Subject</label><select name="subject_id" required><option value="">Choose subject</option><?php foreach($subjectOptions as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (int)$editing['subject_id']===(int)$option['id']?'selected':'' ?>><?= htmlspecialchars($option['name']) ?></option><?php endforeach; ?></select></div>
<div class="admin-form-field"><label>Visibility</label><select name="school_id"><option value="0">All schools</option><?php foreach($schoolOptions as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (int)($editing['school_id']??0)===(int)$option['id']?'selected':'' ?>><?= htmlspecialchars($option['name']) ?> only</option><?php endforeach; ?></select></div>
<div class="admin-form-field full"><label>Title</label><input name="title" maxlength="200" required value="<?= htmlspecialchars($editing['title']) ?>"></div>
<div class="admin-form-field"><label>Class</label><select name="class_level"><?php foreach(['JHS1','JHS2','JHS3'] as $value): ?><option <?= $editing['class_level']===$value?'selected':'' ?>><?= $value ?></option><?php endforeach; ?></select></div>
<div class="admin-form-field"><label>Difficulty</label><select name="difficulty"><?php foreach(['easy','medium','hard'] as $value): ?><option value="<?= $value ?>" <?= $editing['difficulty']===$value?'selected':'' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div>
<div class="admin-form-field"><label>Sequence</label><input type="number" min="1" name="sequence_order" value="<?= (int)$editing['sequence_order'] ?>"></div><div class="admin-form-field"><label>Estimated minutes</label><input type="number" min="5" max="180" name="estimated_minutes" value="<?= (int)$editing['estimated_minutes'] ?>"></div>
<div class="admin-form-field full"><label>Description</label><textarea name="description" rows="2"><?= htmlspecialchars($editing['description']??'') ?></textarea></div><div class="admin-form-field full"><label>Lesson content</label><textarea name="content" rows="9" required><?= htmlspecialchars($editing['content']??'') ?></textarea></div>
<div><button class="admin-primary-button">Save topic</button></div></form></section><?php endif; ?>

<section class="admin-panel admin-student-panel"><div class="admin-panel-body pt-3"><nav class="admin-status-tabs"><?php foreach($validStatuses as $filter): ?><a class="<?= $status===$filter?'active':'' ?>" href="?status=<?= $filter ?>"><?= ucfirst($filter) ?><?= isset($topicCounts[$filter])?' ('.$topicCounts[$filter].')':'' ?></a><?php endforeach; ?></nav></div>
<?php if(!$topics): ?><div class="admin-empty"><strong>No topics in this view</strong></div><?php else: ?><div class="table-responsive"><table class="admin-table admin-student-table"><thead><tr><th>Topic</th><th>Owner</th><th>Class / order</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($topics as $topic): ?><tr>
<td class="admin-description"><strong><?= htmlspecialchars($topic['title']) ?></strong><small><?= htmlspecialchars($topic['subject_name']) ?> · <?= (int)$topic['quiz_count'] ?> quizzes</small></td><td><?= htmlspecialchars($topic['teacher_name'] ? teacherDisplayName($topic['teacher_name']) : 'Core curriculum') ?><small class="admin-parent-contact"><?= htmlspecialchars($topic['school_name']??'All schools') ?></small></td><td><?= htmlspecialchars($topic['class_level']) ?> / <?= (int)$topic['sequence_order'] ?></td><td><span class="admin-badge <?= $topic['approval_status']==='approved'?'success':($topic['approval_status']==='rejected'?'danger':'warning') ?>"><?= ucfirst($topic['approval_status']) ?></span><?php if(!$topic['is_active']): ?> <span class="admin-badge neutral">Inactive</span><?php endif; ?></td>
<td><div class="admin-row-actions"><a class="admin-action-button" href="?edit=<?= (int)$topic['id'] ?>">Edit</a><?php if($topic['approval_status']!=='approved'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="topic_id" value="<?= (int)$topic['id'] ?>"><button class="admin-action-button">Approve</button></form><?php endif; ?><?php if($topic['approval_status']==='pending'): ?><form method="post" class="admin-inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="topic_id" value="<?= (int)$topic['id'] ?>"><input name="rejection_reason" placeholder="Reason" required><button class="admin-action-button danger">Reject</button></form><?php endif; ?><?php if($topic['is_active']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="topic_id" value="<?= (int)$topic['id'] ?>"><button class="admin-action-button danger">Deactivate</button></form><?php elseif($topic['approval_status']==='approved'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="topic_id" value="<?= (int)$topic['id'] ?>"><button class="admin-action-button">Activate</button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php renderAdminFooter(); ?>
