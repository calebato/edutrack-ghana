<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../src/Exception.php';
require __DIR__ . '/../src/PHPMailer.php';
require __DIR__ . '/../src/SMTP.php';

require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/teacher.php';
require_once __DIR__ . '/report_email_template.php';

function reportConfig(string $key): string {
    $environmentValue = getenv($key);
    if ($environmentValue !== false && $environmentValue !== '') return $environmentValue;

    static $localConfig = null;
    if ($localConfig === null) {
        $path = __DIR__ . '/../config/.env';
        $localConfig = is_file($path) ? (parse_ini_file($path, false, INI_SCANNER_RAW) ?: []) : [];
    }
    return (string)($localConfig[$key] ?? '');
}

requireTeacher();

$teacherId = $_SESSION['user_id'];
$teacher   = getCurrentUser();
$assignedClasses = teacherAssignedClasses($teacher);
$isGeneralTeacher = ($teacher['subject'] ?? 'General') === 'General';
$school = dbRow('SELECT * FROM schools WHERE id=?', [(int)$teacher['school_id']]) ?? [
    'name' => 'EduTrack Ghana', 'region' => '', 'district' => ''
];
$month = (int)date('n');
$term = $month >= 9 ? 'Term 1' : ($month <= 4 ? 'Term 2' : 'Term 3');
$year = (int)date('Y');
$academicYear = $month >= 9 ? $year . '/' . ($year + 1) : ($year - 1) . '/' . $year;
$uiMessage = '';
$uiError = '';

if ($isGeneralTeacher) {
    $students = getAllStudentsDetailedForSchool((int)$teacher['school_id'], $assignedClasses);
} else {
    $subject = dbRow('SELECT id FROM subjects WHERE name = ?', [$teacher['subject']]);
    $subjectId = (int)($subject['id'] ?? 0);
    $students = getAllStudentsDetailed($subjectId, (int)$teacher['school_id'], $assignedClasses);
}

$reportStudent = null;
$report        = null;
$reportRemarks = null;
$allowedStudentIds = array_map(static fn(array $student): int => (int)$student['id'], $students);
$selectedStudentId = (int)($_GET['student'] ?? $_POST['student_id'] ?? 0);

if ($selectedStudentId && in_array($selectedStudentId, $allowedStudentIds, true)) {
        $report = generateStudentReport($selectedStudentId, (int)$teacher['school_id'], $teacher);
        $reportStudent = $report['student'] ?? null;
        $reportRemarks = dbRow(
            'SELECT * FROM student_report_remarks WHERE teacher_id=? AND student_id=? AND academic_year=? AND term=?',
            [$teacherId, $selectedStudentId, $academicYear, $term]
        );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report_remarks']) && $reportStudent) {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $uiError = 'Your session expired. Please try again.';
    } else {
        $present = ($_POST['attendance_present'] ?? '') === '' ? null : max(0, (int)$_POST['attendance_present']);
        $total = ($_POST['attendance_total'] ?? '') === '' ? null : max(0, (int)$_POST['attendance_total']);
        $conduct = $_POST['conduct'] ?? 'Good';
        if (!in_array($conduct, ['Excellent','Very Good','Good','Satisfactory','Needs Improvement'], true)) $conduct = 'Good';
        if ($present !== null && $total !== null && $present > $total) {
            $uiError = 'Attendance present cannot be greater than total school days.';
        } else {
            dbQuery(
                "INSERT INTO student_report_remarks
                 (teacher_id,student_id,academic_year,term,attendance_present,attendance_total,conduct,teacher_comment,recommendations)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE attendance_present=VALUES(attendance_present),attendance_total=VALUES(attendance_total),
                 conduct=VALUES(conduct),teacher_comment=VALUES(teacher_comment),recommendations=VALUES(recommendations)",
                [$teacherId, $selectedStudentId, $academicYear, $term, $present, $total, $conduct,
                 trim($_POST['teacher_comment'] ?? ''), trim($_POST['recommendations'] ?? '')]
            );
            $reportRemarks = dbRow(
                'SELECT * FROM student_report_remarks WHERE teacher_id=? AND student_id=? AND academic_year=? AND term=?',
                [$teacherId, $selectedStudentId, $academicYear, $term]
            );
            $uiMessage = 'Report remarks saved.';
        }
    }
}
// SEND REPORT TO PARENT

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_report']) && $reportStudent) {

    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $uiError = 'Your session expired. Please try again.';
    } elseif (!$isGeneralTeacher) {
        $uiError = 'Only General teachers can send full parent reports.';
    } elseif (empty($reportStudent['parent_email'])) {
        $uiError = 'This student does not have a parent email address.';
    } else {

    $mail = new PHPMailer(true);

    try {

        // SERVER SETTINGS

        $mail->isSMTP();

        $smtpHost = reportConfig('EDUTRACK_SMTP_HOST');
        $smtpUser = reportConfig('EDUTRACK_SMTP_USER');
        $smtpPass = reportConfig('EDUTRACK_SMTP_PASS');
        $smtpPort = (int)(reportConfig('EDUTRACK_SMTP_PORT') ?: 587);
        if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '') {
            throw new RuntimeException('SMTP is not configured.');
        }
        $mail->Host = $smtpHost;

        $mail->SMTPAuth = true;

        $mail->Username = $smtpUser;

        $mail->Password = $smtpPass;

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port = $smtpPort;

        // RECIPIENT

        $mail->setFrom($smtpUser, $school['name']);

        $mail->addAddress(
            $reportStudent['parent_email'],
            $reportStudent['parent_name']
        );

        // EMAIL CONTENT

        $mail->isHTML(true);

        $mail->Subject = $school['name'] . ' Progress Report - ' . $reportStudent['full_name'];

        $parentName = htmlspecialchars($reportStudent['parent_name'] ?: 'Parent/Guardian');
        $studentName = htmlspecialchars($reportStudent['full_name']);
        $studentId = htmlspecialchars($reportStudent['student_id']);
        $classLevel = htmlspecialchars($reportStudent['class_level']);
        $lastActive = $reportStudent['last_login'] ? date('M d, Y', strtotime($reportStudent['last_login'])) : 'N/A';
        $totalPoints = number_format($reportStudent['total_points']);
        $quizzesPassed = $report['quiz_stats']['passed_count'] ?? 0;
        $avgScore = round($report['quiz_stats']['avg'] ?? 0);
        $streak = $reportStudent['longest_streak'];
        $generatedAt = $report['generated_at'];
        $performanceValue = (int)($report['quiz_stats']['cnt'] ?? 0) > 0 ? $avgScore . '%' : 'No quiz data';
        $performanceNarrative = (int)($report['quiz_stats']['cnt'] ?? 0) > 0
            ? '<strong>' . $studentName . '</strong> has a recorded quiz average of <strong>' . $avgScore . '%</strong> across ' . (int)$report['quiz_stats']['cnt'] . ' completed attempts.'
            : 'No completed quizzes have been recorded for this student yet.';
        $year = date('Y');

        $mail->Body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Progress Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5; color: #333; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center; }
        .header-logo { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
        .header-subtitle { font-size: 14px; opacity: 0.9; }
        .content { padding: 30px 20px; }
        .greeting { font-size: 16px; margin-bottom: 20px; line-height: 1.6; }
        .greeting strong { color: #667eea; }
        .intro-box { background-color: #f0f4ff; border-left: 4px solid #667eea; padding: 15px; margin-bottom: 25px; border-radius: 4px; font-size: 14px; }
        .info-card { background-color: #fafafa; padding: 20px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #eee; }
        .info-card h3 { color: #333; font-size: 16px; margin-bottom: 15px; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; }
        .info-label { font-weight: 600; color: #555; }
        .info-value { color: #333; }
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 25px; }
        .stat-box { color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-box.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-box.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-box.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-box.purple { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-value { font-size: 28px; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 12px; opacity: 0.9; text-transform: uppercase; letter-spacing: 0.5px; }
        .subjects-section { margin-bottom: 25px; }
        .subjects-section h3 { color: #333; font-size: 16px; margin-bottom: 15px; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .subject-row { display: flex; align-items: center; margin-bottom: 12px; padding: 10px; background: #fafafa; border-radius: 4px; }
        .subject-name { font-weight: 600; width: 100px; color: #333; font-size: 13px; }
        .progress-bar-container { flex: 1; height: 8px; background: #e0e0e0; border-radius: 4px; margin: 0 10px; overflow: hidden; }
        .progress-bar { height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); border-radius: 4px; }
        .subject-score { font-weight: bold; width: 50px; text-align: right; font-size: 14px; color: #667eea; }
        .insights { background-color: #f9f9f9; padding: 15px; border-radius: 6px; margin-bottom: 25px; border-left: 4px solid #764ba2; }
        .insights h3 { color: #333; font-size: 14px; margin-bottom: 10px; }
        .insight-item { font-size: 13px; margin-bottom: 8px; padding: 8px; background: white; border-radius: 4px; }
        .strength { border-left: 3px solid #38ef7d; }
        .weakness { border-left: 3px solid #f5576c; }
        .recommendation { background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); padding: 20px; border-radius: 6px; margin-bottom: 25px; }
        .recommendation h3 { color: #d84315; font-size: 14px; margin-bottom: 10px; }
        .recommendation ul { list-style: none; padding-left: 0; font-size: 13px; }
        .recommendation li { margin-bottom: 8px; padding-left: 20px; position: relative; }
        .recommendation li:before { content: "→"; position: absolute; left: 0; color: #d84315; font-weight: bold; }
        .cta-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 6px; margin-bottom: 25px; }
        .cta-box p { font-size: 14px; margin-bottom: 15px; }
        .footer { background-color: #f5f5f5; padding: 20px; text-align: center; border-top: 1px solid #eee; font-size: 12px; color: #999; }
        .footer a { color: #667eea; text-decoration: none; }
        .prediction-card { background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); border: none; }
        .prediction-card h3 { color: #d84315; border-color: #d84315; }
        .prediction-value { font-size: 36px; font-weight: bold; color: #d84315; }
        .prediction-grade { font-size: 18px; color: #d84315; margin-bottom: 10px; }
        @media (max-width: 600px) {
            .stats-grid { grid-template-columns: 1fr; }
            .subject-row { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <div class="header-logo">🎓 EduTrack Ghana</div>
            <div class="header-subtitle">Student Progress Report</div>
        </div>
        <div class="content">
            <div class="greeting">
                Dear <strong>' . $parentName . '</strong>,<br><br>
                We are pleased to share your child\'s latest academic progress report. This comprehensive overview highlights their performance, achievements, and areas for growth.
            </div>
            <div class="intro-box">
                📚 <strong>Student:</strong> ' . $studentName . ' | <strong>Generated:</strong> ' . $generatedAt . '
            </div>
            <div class="info-card">
                <h3>👤 Student Information</h3>
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span class="info-value">' . $studentName . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Student ID:</span>
                    <span class="info-value">' . $studentId . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Class:</span>
                    <span class="info-value">' . $classLevel . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Last Active:</span>
                    <span class="info-value">' . $lastActive . '</span>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-box green">
                    <div class="stat-value">⭐ ' . $totalPoints . '</div>
                    <div class="stat-label">Total Points</div>
                </div>
                <div class="stat-box blue">
                    <div class="stat-value">' . $quizzesPassed . '</div>
                    <div class="stat-label">Quizzes Passed</div>
                </div>
                <div class="stat-box orange">
                    <div class="stat-value">' . $avgScore . '%</div>
                    <div class="stat-label">Average Score</div>
                </div>
                <div class="stat-box purple">
                    <div class="stat-value">🔥 ' . $streak . '</div>
                    <div class="stat-label">Day Streak</div>
                </div>
            </div>' . 
            (!empty($report['subject_scores']) ? '
            <div class="subjects-section">
                <h3>📊 Subject Performance</h3>' . 
                implode('', array_map(function($sub) {
                    $scorePercent = round($sub['avg_score']);
                    return '
                <div class="subject-row">
                    <div class="subject-name">' . htmlspecialchars($sub['name']) . '</div>
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: ' . $scorePercent . '%;"></div>
                    </div>
                    <div class="subject-score">' . $scorePercent . '%</div>
                </div>';
                }, $report['subject_scores'])) . '
            </div>' : '') . 
            (!empty($report['strengths']) || !empty($report['weaknesses']) ? '
            <div class="insights">
                <h3>💡 Key Insights</h3>' . 
                implode('', array_map(function($s) {
                    return '<div class="insight-item strength">✅ <strong>Strength:</strong> ' . htmlspecialchars($s) . '</div>';
                }, $report['strengths'] ?? [])) . 
                implode('', array_map(function($w) {
                    return '<div class="insight-item weakness">⚠️ <strong>Area for Improvement:</strong> ' . htmlspecialchars($w) . '</div>';
                }, $report['weaknesses'] ?? [])) . '
            </div>' : '') . '
            <div class="info-card prediction-card">
                <h3>Current Quiz Performance</h3>
                <div style="text-align: center; padding: 15px 0;">
                    <div class="prediction-value">' . $performanceValue . '</div>
                    <div class="prediction-grade">Recorded results</div>
                    <p style="font-size: 13px; color: #333; margin: 10px 0 0 0;">
                        ' . $performanceNarrative . '
                    </p>
                </div>
            </div>
            <div class="recommendation">
                <h3>💬 Recommendations for Parents</h3>
                <ul>
                    <li>Encourage consistent daily learning to maintain the current streak</li>
                    <li>Review strong areas and use them as motivation for improvement</li>
                    <li>Focus additional attention on areas marked for improvement</li>
                    <li>Monitor progress regularly through the EduTrack dashboard</li>
                    <li>Contact your child\'s teacher if you have any concerns</li>
                </ul>
            </div>
            <div class="cta-box">
                <p>Questions about this report? Contact your child\'s teacher for more details.</p>
            </div>
            <div class="greeting" style="margin-bottom: 30px;">
                <p style="margin-bottom: 15px;">Thank you for supporting your child\'s learning journey!</p>
                <p style="margin-bottom: 0;">
                    <strong>Best regards,</strong><br>
                    The EduTrack Ghana Team
                </p>
            </div>
        </div>
        <div class="footer">
            <p style="margin-bottom: 10px;">© ' . $year . ' EduTrack Ghana Learning System | All Rights Reserved</p>
        </div>
    </div>
</body>
</html>';

        $mail->Body = buildReportEmailHtml($school, $teacher, $reportStudent, $report, $reportRemarks, $term, $academicYear);
        $mail->AltBody = $school['name'] . ' progress report for ' . $reportStudent['full_name']
            . ' — ' . $term . ', ' . $academicYear . '. Average score: '
            . round($report['quiz_stats']['avg'] ?? 0) . '%. Please contact the teacher for details.';
        $mail->send();
        $uiMessage = 'Report sent successfully to parent.';
        logActivity($teacherId, 'teacher', 'report_emailed', 'Progress report emailed for student #' . $selectedStudentId);

        echo "
<div class='toast-message success-toast'>
    ✅ Report sent successfully to parent
</div>
";

    } catch (Throwable $e) {
       $uiError = 'Email could not be sent. Please check the SMTP configuration.';
       error_log('EduTrack report email error: ' . $e->getMessage());

       echo "
<div class='toast-message error-toast'>
    ❌ Email could not be sent
</div>
";
    }
}
}

$pageTitle = 'Reports';
$activeNav = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>


<?php if ($report): ?>
<!-- Report View -->
<?php if ($uiMessage): ?><div class="alert alert-success no-print"><?= htmlspecialchars($uiMessage) ?></div><?php endif; ?>
<?php if ($uiError): ?><div class="alert alert-danger no-print"><?= htmlspecialchars($uiError) ?></div><?php endif; ?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="reports.php" class="btn btn-sm btn-outline-secondary">← All Reports</a>
    <h4 class="mb-0 flex-grow-1">
    Progress Report:
    <?= htmlspecialchars($reportStudent['full_name']) ?>
</h4>
    <div class="ms-auto d-flex gap-2">

    <button
        class="btn btn-sm btn-success"
        onclick="window.print()"
    >
        🖨 Print Report
    </button>

    <?php if ($isGeneralTeacher && !empty($reportStudent['parent_email'])): ?>

        <form method="post" onsubmit="return confirm('Send this report to the parent email address?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">
            <input type="hidden" name="student_id" value="<?= (int)$reportStudent['id'] ?>">
            <button type="submit" name="send_report" class="btn btn-sm btn-primary">Send to Parent</button>
        </form>

        <a hidden aria-hidden="true"
            href="reports.php?student=<?= $reportStudent['id'] ?>&send=1"
            class="btn btn-sm btn-primary"
        >
            📧 Send to Parent
        </a>

    <?php elseif (!$isGeneralTeacher): ?>
        <span class="badge bg-light text-muted align-self-center">Subject report only</span>
    <?php endif; ?>

</div>
</div>

<div class="card edu-card mb-4 no-print"><div class="card-body">
    <h5 class="card-title mb-3">Term Report Details</h5>
    <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRF()) ?>">
        <input type="hidden" name="student_id" value="<?= (int)$reportStudent['id'] ?>">
        <div class="col-md-3"><label class="form-label">Academic year</label><input class="form-control" value="<?= htmlspecialchars($academicYear) ?>" disabled></div>
        <div class="col-md-3"><label class="form-label">Term</label><input class="form-control" value="<?= htmlspecialchars($term) ?>" disabled></div>
        <div class="col-md-3"><label class="form-label">Days present</label><input type="number" min="0" class="form-control" name="attendance_present" value="<?= htmlspecialchars((string)($reportRemarks['attendance_present'] ?? '')) ?>"></div>
        <div class="col-md-3"><label class="form-label">Total school days</label><input type="number" min="0" class="form-control" name="attendance_total" value="<?= htmlspecialchars((string)($reportRemarks['attendance_total'] ?? '')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Conduct</label><select class="form-select" name="conduct"><?php foreach(['Excellent','Very Good','Good','Satisfactory','Needs Improvement'] as $conduct): ?><option <?= ($reportRemarks['conduct'] ?? 'Good')===$conduct?'selected':'' ?>><?= $conduct ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8"><label class="form-label">Teacher comment</label><textarea class="form-control" name="teacher_comment" rows="3"><?= htmlspecialchars($reportRemarks['teacher_comment'] ?? '') ?></textarea></div>
        <div class="col-12"><label class="form-label">Recommendations</label><textarea class="form-control" name="recommendations" rows="3"><?= htmlspecialchars($reportRemarks['recommendations'] ?? '') ?></textarea></div>
        <div class="col-12"><button class="btn btn-primary" type="submit" name="save_report_remarks">Save Report Details</button></div>
    </form>
</div></div>

<div class="report-container" id="printArea">
    <!-- Report Header -->
    <div class="report-header">
        <div class="report-school-name"><?= htmlspecialchars($school['name']) ?></div>
        <div class="report-logo">🎓 EduTrack Ghana</div>
        <div class="report-school-meta"><?= htmlspecialchars(trim($school['district'] . ', ' . $school['region'], ', ')) ?></div>
        <div class="report-title">Student Progress Report</div>
        <div class="report-meta"><?= htmlspecialchars($term) ?> &middot; Academic Year <?= htmlspecialchars($academicYear) ?> &middot; Generated <?= date('M d, Y') ?></div>
    </div>

    <!-- Student Info -->
    <div class="report-section">
        <h5 class="report-section-title">Student Information</h5>
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr><td width="150"><strong>Name:</strong></td><td><?= htmlspecialchars($reportStudent['full_name']) ?></td></tr>
                    <tr><td><strong>Student ID:</strong></td><td><?= htmlspecialchars($reportStudent['student_id']) ?></td></tr>
                    <tr><td><strong>Class:</strong></td><td><?= $reportStudent['class_level'] ?></td></tr>
                    <tr><td><strong>Gender:</strong></td><td><?= $reportStudent['gender'] ?></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr><td width="150"><strong>Total Points:</strong></td><td><strong class="text-primary">⭐ <?= number_format($reportStudent['total_points']) ?></strong></td></tr>
                    <tr><td><strong>Longest Streak:</strong></td><td>🔥 <?= $reportStudent['longest_streak'] ?> days</td></tr>
                    <tr><td><strong>Badges Earned:</strong></td><td>🏅 <?= count($report['badges']) ?></td></tr>
                    <tr><td><strong>Last Active:</strong></td><td><?= $reportStudent['last_login'] ? date('M d, Y', strtotime($reportStudent['last_login'])) : 'Never' ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Parent Information -->

<div class="report-section">

    <h5 class="report-section-title">
        👨‍👩‍👧 Parent / Guardian Information
    </h5>

    <div class="row">

        <div class="col-md-6">

            <table class="table table-sm table-borderless">

                <tr>

                    <td width="180">
                        <strong>Parent Name:</strong>
                    </td>

                    <td>
                        <?= !empty($reportStudent['parent_name'])
                            ? htmlspecialchars($reportStudent['parent_name'])
                            : 'Not Provided' ?>
                    </td>

                </tr>

                <tr>

                    <td>
                        <strong>Parent Email:</strong>
                    </td>

                    <td>
                        <?= !empty($reportStudent['parent_email'])
                            ? htmlspecialchars($reportStudent['parent_email'])
                            : 'Not Provided' ?>
                    </td>

                </tr>

                <tr>

                    <td>
                        <strong>Parent Phone:</strong>
                    </td>

                    <td>
                        <?= !empty($reportStudent['parent_phone'])
                            ? htmlspecialchars($reportStudent['parent_phone'])
                            : 'Not Provided' ?>
                    </td>

                </tr>

            </table>

        </div>

    </div>

</div>

    <div class="report-section report-keep-together">
        <h5 class="report-section-title">Term Information</h5>
        <div class="row g-3">
            <div class="col-6 col-md-3"><div class="report-field"><span>Academic Year</span><strong><?= htmlspecialchars($academicYear) ?></strong></div></div>
            <div class="col-6 col-md-3"><div class="report-field"><span>Term</span><strong><?= htmlspecialchars($term) ?></strong></div></div>
            <div class="col-6 col-md-3"><div class="report-field"><span>Attendance</span><strong><?= $reportRemarks && $reportRemarks['attendance_total'] !== null ? (int)$reportRemarks['attendance_present'] . '/' . (int)$reportRemarks['attendance_total'] : 'Not recorded' ?></strong></div></div>
            <div class="col-6 col-md-3"><div class="report-field"><span>Conduct</span><strong><?= htmlspecialchars($reportRemarks['conduct'] ?? 'Not recorded') ?></strong></div></div>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="report-section">
        <h5 class="report-section-title">Academic Summary</h5>
        <div class="row g-3">
            <div class="col-3 text-center">
                <div class="report-stat">
                    <div class="rs-big"><?= $report['quiz_stats']['cnt'] ?? 0 ?></div>
                    <div class="rs-lbl">Total Quizzes</div>
                </div>
            </div>
            <div class="col-3 text-center">
                <div class="report-stat">
                    <div class="rs-big"><?= round($report['quiz_stats']['avg'] ?? 0) ?>%</div>
                    <div class="rs-lbl">Average Score</div>
                </div>
            </div>
            <div class="col-3 text-center">
                <div class="report-stat">
                    <div class="rs-big"><?= $report['completed_topics'] ?>/<?= $report['total_topics'] ?></div>
                    <div class="rs-lbl">Topics Done</div>
                </div>
            </div>
            <div class="col-3 text-center">
                <div class="report-stat">
                    <div class="rs-big"><?= $report['quiz_stats']['passed_count'] ?? 0 ?></div>
                    <div class="rs-lbl">Quizzes Passed</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Predictive performance -->
    <div class="report-section">
        <h5 class="report-section-title">Predictive Exam Performance</h5>
        <div class="row align-items-center">
            <div class="col-md-3 text-center">
                <div class="prediction-circle">
                    <div class="prediction-value"><?= $report['exam_prediction']['available'] ? $report['exam_prediction']['score'] . '%' : '--' ?></div>
                    <div class="prediction-grade"><?= $report['exam_prediction']['available'] ? 'BECE Grade ' . $report['exam_prediction']['grade'] : 'Insufficient data' ?></div>
                </div>
            </div>
            <div class="col-md-9">
                <?php if ($report['exam_prediction']['available']): ?>
                    <p class="mb-2"><strong><?= htmlspecialchars($reportStudent['full_name']) ?></strong>
                       has a projected exam score of <strong><?= $report['exam_prediction']['score'] ?>%</strong>
                       with <?= $report['exam_prediction']['confidence'] ?>% model confidence.</p>
                    <p class="mb-2 text-muted small">This forecast is based on quiz performance, mastery, completion, activity, and consistency. It is not a final examination result.</p>
                <?php else: ?>
                    <p class="mb-2 text-muted">Prediction unavailable. The learner needs <?= (int)$report['exam_prediction']['attempts_needed'] ?> more completed quiz<?= (int)$report['exam_prediction']['attempts_needed'] === 1 ? '' : 'zes' ?>.</p>
                <?php endif; ?>

                <?php if (!empty($report['strengths'])): ?>
                <p class="mb-1 text-success"><strong>💪 Strong areas:</strong> <?= implode(', ', $report['strengths']) ?></p>
                <?php endif; ?>

                <?php if (!empty($report['weaknesses'])): ?>
                <p class="mb-0 text-danger"><strong>⚠️ Areas for improvement:</strong> <?= implode(', ', $report['weaknesses']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Subject Scores -->
    <?php if (!empty($report['subject_scores'])): ?>
    <div class="report-section">
        <h5 class="report-section-title">📊 Subject Performance</h5>
        <table class="table edu-table">
            <thead><tr><th>Subject</th><th>Attempts</th><th>Avg Score</th><th>Performance</th></tr></thead>
            <tbody>
                <?php foreach ($report['subject_scores'] as $sub): ?>
                <tr>
                    <td><?= htmlspecialchars($sub['name']) ?></td>
                    <td><?= $sub['attempts'] ?></td>
                    <td>
                        <span class="score-badge <?= $sub['avg_score'] >= 60 ? 'score-pass' : 'score-fail' ?>">
                            <?= round($sub['avg_score']) ?>%
                        </span>
                    </td>
                    <td style="min-width:120px">
                        <div class="progress" style="height:6px">
                            <div class="progress-bar" style="width:<?= round($sub['avg_score']) ?>%;background:<?= $sub['color'] ?>"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Recent Quizzes -->
    <?php if (!empty($report['quiz_history'])): ?>
    <div class="report-section">
        <h5 class="report-section-title">📝 Recent Quiz Results</h5>
        <table class="table edu-table table-sm">
            <thead><tr><th>Quiz</th><th>Subject</th><th>Score</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
                <?php foreach ($report['quiz_history'] as $q): ?>
                <tr>
                    <td><?= htmlspecialchars($q['quiz_title']) ?></td>
                    <td><?= htmlspecialchars($q['subject_name']) ?></td>
                    <td><span class="score-badge <?= $q['score'] >= 60 ? 'score-pass' : 'score-fail' ?>"><?= $q['score'] ?>%</span></td>
                    <td><?= $q['passed'] ? '✅ Pass' : '❌ Fail' ?></td>
                    <td class="text-muted small"><?= $q['completed_at'] ? date('M d, Y', strtotime($q['completed_at'])) : '–' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="report-section report-keep-together">
        <h5 class="report-section-title">Teacher Remarks and Recommendations</h5>
        <div class="remarks-box"><strong>Comment:</strong><br><?= nl2br(htmlspecialchars($reportRemarks['teacher_comment'] ?? 'No teacher comment recorded for this term.')) ?></div>
        <div class="remarks-box mt-3"><strong>Recommendations:</strong><br><?= nl2br(htmlspecialchars($reportRemarks['recommendations'] ?? 'Continue regular study and review areas identified for improvement.')) ?></div>
    </div>

    <div class="report-section report-keep-together">
        <h5 class="report-section-title">Grading Scale</h5>
        <div class="grading-grid"><span>A: 80–100</span><span>B: 70–79</span><span>C: 60–69</span><span>D: 50–59</span><span>E: 40–49</span><span>F: Below 40</span></div>
    </div>

    <!-- Teacher Signature -->
    <div class="report-footer mt-4">
        <div class="row">
            <div class="col-6">
                <div class="signature-line"></div>
                <div class="text-muted small">Teacher's Signature</div>
                <div class="small"><?= htmlspecialchars(teacherDisplayName($teacher['full_name'])) ?></div>
            </div>
            <div class="col-6 text-end">
                <div class="text-muted small mt-4">EduTrack Ghana Learning System</div>
                <div class="text-muted" style="font-size:10px">Report generated automatically • <?= date('Y') ?></div>
            </div>
        </div>
        <div class="row mt-5">
            <div class="col-6 text-center"><div class="signature-line"></div><div class="text-muted small">Headteacher's Signature</div></div>
            <div class="col-6 text-center"><div class="signature-line"></div><div class="text-muted small">Parent / Guardian Signature</div></div>
        </div>
    </div>
</div>

<style>
.report-container{max-width:900px;margin:0 auto;background:#fff;border:1px solid #dbe3ef;border-radius:12px;padding:32px;color:#172033}.report-logo{display:none}.report-header{text-align:center;border-bottom:3px solid #4f46e5;padding-bottom:20px;margin-bottom:24px}.report-school-name{font-size:28px;font-weight:800;color:#1e293b}.report-school-meta{font-size:13px;color:#64748b;margin:3px 0 10px}.report-title{font-size:20px;font-weight:700;text-transform:uppercase;letter-spacing:.08em}.report-section{padding:18px 0;border-bottom:1px solid #e5e7eb}.report-section-title{color:#3730a3;font-weight:700;margin-bottom:14px}.report-field{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;height:100%}.report-field span{display:block;color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.report-field strong{display:block;margin-top:4px}.remarks-box{min-height:72px;background:#f8fafc;border-left:4px solid #4f46e5;padding:14px;border-radius:6px}.grading-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}.grading-grid span{background:#eef2ff;color:#3730a3;text-align:center;padding:10px 5px;border-radius:6px;font-weight:700;font-size:12px}.signature-line{border-top:1px solid #475569;margin:42px 20px 7px}.no-print{display:block}a[href*="&send=1"]{display:none!important}
@page{size:A4;margin:12mm}
@media print {
    body{background:#fff!important;font-size:10pt}.sidebar,.topbar,.no-print,.d-flex.gap-3.mb-4{display:none!important}.main-content{margin:0!important}.page-content{padding:0!important}.report-container{max-width:none;border:0;border-radius:0;padding:0}.report-header{margin-bottom:12px;padding-bottom:12px}.report-section{padding:10px 0}.report-section,.report-keep-together,.report-stat,.remarks-box,table,tr{break-inside:avoid;page-break-inside:avoid}.edu-table{font-size:9pt}.progress-bar,.report-header{-webkit-print-color-adjust:exact;print-color-adjust:exact}.report-footer{break-inside:avoid;page-break-inside:avoid}
}
@media(max-width:700px){.report-container{padding:18px}.grading-grid{grid-template-columns:repeat(3,1fr)}}
.toast-message{

    position: fixed;

    top: 20px;

    right: 20px;

    z-index: 9999;

    padding: 14px 22px;

    border-radius: 12px;

    color: white;

    font-weight: 600;

    box-shadow: 0 10px 25px rgba(0,0,0,0.15);

    animation: slideIn 0.4s ease,
               fadeOut 0.5s ease 4s forwards;
}

.success-toast{

    background: #16a34a;
}

.error-toast{

    background: #dc2626;
}

@keyframes slideIn{

    from{

        opacity:0;

        transform:translateX(100%);
    }

    to{

        opacity:1;

        transform:translateX(0);
    }
}

@keyframes fadeOut{

    to{

        opacity:0;

        transform:translateX(100%);
    }
}
</style>

<?php else: ?>
<!-- Select Student for Report -->
<div class="card edu-card">
    <div class="card-body">
        <h5 class="card-title mb-3">📋 Generate Student Report</h5>
        <p class="text-muted">Select a student to generate a progress report from recorded quizzes, topics, and teacher remarks.</p>

        <div class="row g-3">
            <?php foreach ($students as $s): ?>
            <div class="col-md-4">
                <div class="student-report-card" onclick="window.location='?student=<?= $s['id'] ?>'">
                    <div class="d-flex align-items-center gap-3">
                        <div class="user-avatar-sm"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
                        <div>
                            <div class="fw-600"><?= htmlspecialchars($s['full_name']) ?></div>
                            <div class="text-muted small"><?= $s['class_level'] ?> • <?= $s['quiz_count'] ?> quizzes</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
