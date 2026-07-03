<?php

function reportEmailEscape(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function buildReportEmailHtml(
    array $school,
    array $teacher,
    array $student,
    array $report,
    ?array $remarks,
    string $term,
    string $academicYear
): string {
    $schoolName = reportEmailEscape($school['name'] ?? 'EduTrack Ghana');
    $schoolLocation = reportEmailEscape(trim(($school['district'] ?? '') . ', ' . ($school['region'] ?? ''), ', '));
    $studentName = reportEmailEscape($student['full_name'] ?? 'Student');
    $parentName = reportEmailEscape($student['parent_name'] ?: 'Parent / Guardian');
    $studentNumber = reportEmailEscape($student['student_id'] ?? 'N/A');
    $classLevel = reportEmailEscape($student['class_level'] ?? 'N/A');
    $teacherName = reportEmailEscape(teacherDisplayName($teacher['full_name'] ?? ''));
    $average = (int)round($report['quiz_stats']['avg'] ?? 0);
    $attempts = (int)($report['quiz_stats']['cnt'] ?? 0);
    $passed = (int)($report['quiz_stats']['passed_count'] ?? 0);
    $topics = (int)($report['completed_topics'] ?? 0) . '/' . (int)($report['total_topics'] ?? 0);
    $conduct = reportEmailEscape($remarks['conduct'] ?? 'Not recorded');
    $attendance = ($remarks && $remarks['attendance_total'] !== null)
        ? (int)$remarks['attendance_present'] . '/' . (int)$remarks['attendance_total']
        : 'Not recorded';
    $comment = nl2br(reportEmailEscape($remarks['teacher_comment'] ?? 'No teacher comment has been recorded for this term.'));
    $recommendations = nl2br(reportEmailEscape($remarks['recommendations'] ?? 'Continue regular study and review areas identified for improvement.'));
    $prediction = $report['exam_prediction'] ?? ['available' => false, 'attempts_needed' => 3];
    $forecastBlock = $prediction['available']
        ? '<div style="background:#eef2ff;border-left:4px solid #4f46e5;border-radius:8px;padding:15px;margin-bottom:22px"><strong style="color:#3730a3">ML exam forecast</strong><div style="margin-top:6px;font-size:14px;line-height:1.6">Projected score: <strong>' . reportEmailEscape($prediction['score']) . '%</strong> (BECE Grade ' . reportEmailEscape($prediction['grade']) . '), with ' . reportEmailEscape($prediction['confidence']) . '% model confidence. This is a planning estimate, not a final examination result.</div></div>'
        : '<div style="background:#f8fafc;border-left:4px solid #94a3b8;border-radius:8px;padding:15px;margin-bottom:22px"><strong>Exam forecast pending</strong><div style="margin-top:6px;font-size:14px;line-height:1.6">The learner needs ' . (int)$prediction['attempts_needed'] . ' more completed quizzes before EduTrack can produce a responsible prediction.</div></div>';

    $subjectRows = '';
    foreach ($report['subject_scores'] ?? [] as $subject) {
        $score = (int)round($subject['avg_score'] ?? 0);
        $scoreColor = $score >= 60 ? '#15803d' : '#b91c1c';
        $subjectRows .= '<tr>'
            . '<td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:600">' . reportEmailEscape($subject['name']) . '</td>'
            . '<td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:center">' . (int)$subject['attempts'] . '</td>'
            . '<td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:700;color:' . $scoreColor . '">' . $score . '%</td>'
            . '</tr>';
    }
    if ($subjectRows === '') {
        $subjectRows = '<tr><td colspan="3" style="padding:14px;text-align:center;color:#64748b">No subject scores recorded yet.</td></tr>';
    }

    $strengths = !empty($report['strengths']) ? reportEmailEscape(implode(', ', $report['strengths'])) : 'Still developing';
    $weaknesses = !empty($report['weaknesses']) ? reportEmailEscape(implode(', ', $report['weaknesses'])) : 'No major concern identified';

    return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>@media only screen and (max-width:640px){.email-shell{width:100%!important}.email-pad{padding:20px!important}.stat-cell{display:block!important;width:100%!important;margin-bottom:8px}.two-column{display:block!important;width:100%!important}}</style>'
        . '</head><body style="margin:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#172033">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f7;padding:24px 10px"><tr><td align="center">'
        . '<table role="presentation" class="email-shell" width="640" cellspacing="0" cellpadding="0" style="width:640px;max-width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(15,23,42,.08)">'
        . '<tr><td style="background:#4f46e5;padding:28px;color:#fff;text-align:center"><div style="font-size:25px;font-weight:800">' . $schoolName . '</div><div style="font-size:13px;opacity:.9;margin-top:4px">' . $schoolLocation . '</div><div style="margin-top:15px;font-size:15px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Student Progress Report</div><div style="font-size:12px;margin-top:5px">' . reportEmailEscape($term) . ' &middot; Academic Year ' . reportEmailEscape($academicYear) . '</div></td></tr>'
        . '<tr><td class="email-pad" style="padding:30px">'
        . '<p style="margin:0 0 18px;line-height:1.6">Dear <strong>' . $parentName . '</strong>,</p><p style="line-height:1.6;margin:0 0 22px">Please find below a concise progress update for <strong>' . $studentName . '</strong>. It combines current learning activity with the teacher&apos;s term remarks.</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;margin-bottom:22px"><tr><td style="padding:15px"><strong>' . $studentName . '</strong><br><span style="font-size:13px;color:#64748b">Student ID: ' . $studentNumber . ' &middot; Class: ' . $classLevel . '</span></td><td style="padding:15px;text-align:right;font-size:13px;color:#64748b">Conduct<br><strong style="color:#172033">' . $conduct . '</strong></td></tr></table>'
        . '<table role="presentation" width="100%" cellspacing="8" cellpadding="0" style="margin:0 -8px 22px"><tr>'
        . '<td class="stat-cell" width="25%" style="background:#eef2ff;border-radius:8px;padding:14px;text-align:center"><div style="font-size:23px;font-weight:800;color:#4338ca">' . $average . '%</div><div style="font-size:11px;color:#64748b">AVERAGE</div></td>'
        . '<td class="stat-cell" width="25%" style="background:#ecfdf5;border-radius:8px;padding:14px;text-align:center"><div style="font-size:23px;font-weight:800;color:#047857">' . $passed . '</div><div style="font-size:11px;color:#64748b">QUIZZES PASSED</div></td>'
        . '<td class="stat-cell" width="25%" style="background:#fff7ed;border-radius:8px;padding:14px;text-align:center"><div style="font-size:23px;font-weight:800;color:#c2410c">' . $topics . '</div><div style="font-size:11px;color:#64748b">TOPICS</div></td>'
        . '<td class="stat-cell" width="25%" style="background:#fdf2f8;border-radius:8px;padding:14px;text-align:center"><div style="font-size:23px;font-weight:800;color:#be185d">' . reportEmailEscape($attendance) . '</div><div style="font-size:11px;color:#64748b">ATTENDANCE</div></td>'
        . '</tr></table>'
        . $forecastBlock
        . '<h3 style="font-size:16px;color:#3730a3;margin:0 0 10px">Subject Performance</h3><table width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;border-spacing:0;margin-bottom:22px"><tr style="background:#f8fafc"><th style="padding:10px;text-align:left">Subject</th><th style="padding:10px;text-align:center">Attempts</th><th style="padding:10px;text-align:right">Average</th></tr>' . $subjectRows . '</table>'
        . '<table role="presentation" width="100%" cellspacing="8" cellpadding="0" style="margin:0 -8px 22px"><tr><td class="two-column" width="50%" style="vertical-align:top;background:#f0fdf4;border-left:4px solid #16a34a;padding:14px;border-radius:7px"><strong style="color:#166534">Strong areas</strong><div style="font-size:13px;line-height:1.5;margin-top:6px">' . $strengths . '</div></td><td class="two-column" width="50%" style="vertical-align:top;background:#fff7ed;border-left:4px solid #ea580c;padding:14px;border-radius:7px"><strong style="color:#9a3412">Focus areas</strong><div style="font-size:13px;line-height:1.5;margin-top:6px">' . $weaknesses . '</div></td></tr></table>'
        . '<div style="background:#eef2ff;border-radius:9px;padding:17px;margin-bottom:18px"><strong style="color:#3730a3">Teacher comment</strong><div style="font-size:14px;line-height:1.6;margin-top:7px">' . $comment . '</div></div>'
        . '<div style="background:#f8fafc;border-radius:9px;padding:17px;margin-bottom:20px"><strong>Recommendations</strong><div style="font-size:14px;line-height:1.6;margin-top:7px">' . $recommendations . '</div></div>'
        . '<div style="font-size:12px;color:#64748b;line-height:1.6"><strong>Grading:</strong> A 80–100 &middot; B 70–79 &middot; C 60–69 &middot; D 50–59 &middot; E 40–49 &middot; F below 40</div>'
        . '<p style="margin:25px 0 0;line-height:1.6">Regards,<br><strong>' . $teacherName . '</strong><br>' . $schoolName . '</p>'
        . '</td></tr><tr><td style="background:#f8fafc;padding:18px;text-align:center;color:#64748b;font-size:11px">Generated securely by EduTrack Ghana &middot; ' . date('M d, Y') . '</td></tr></table>'
        . '</td></tr></table></body></html>';
}
