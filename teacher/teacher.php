<?php
/**
 * EduTrack Ghana - Teacher Module
 * teacher/teacher.php
 */

require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../ml/ml.php';

const TEACHER_CLASS_LEVELS = ['JHS1', 'JHS2', 'JHS3'];

function teacherAssignedClasses(?array $teacher): array {
    $raw = trim((string)($teacher['class_levels'] ?? ''));
    if ($raw === '') return TEACHER_CLASS_LEVELS;

    $classes = array_values(array_intersect(
        TEACHER_CLASS_LEVELS,
        array_map('trim', explode(',', $raw))
    ));

    return $classes ?: TEACHER_CLASS_LEVELS;
}

function teacherClassSql(string $column, array $classes): array {
    $classes = array_values(array_intersect(TEACHER_CLASS_LEVELS, $classes));
    if (!$classes) $classes = TEACHER_CLASS_LEVELS;

    return [
        $column . ' IN (' . implode(',', array_fill(0, count($classes), '?')) . ')',
        $classes,
    ];
}

// -----------------------------------------------
// Teacher Dashboard Stats
// -----------------------------------------------

function getTeacherStats(int $teacherId): array {
    $teacher = dbRow("SELECT * FROM teachers WHERE id = ?", [$teacherId]);
    if (!$teacher) return [];
    $isGeneral = $teacher['subject'] === 'General';
    $subject = $isGeneral ? null : dbRow("SELECT id FROM subjects WHERE name = ?", [$teacher['subject']]);
    $subjectId = (int)($subject['id'] ?? 0);
    $schoolId = (int)$teacher['school_id'];
    $assignedClasses = teacherAssignedClasses($teacher);
    [$studentClassSql, $studentClassParams] = teacherClassSql('class_level', $assignedClasses);
    [$aliasedClassSql, $aliasedClassParams] = teacherClassSql('s.class_level', $assignedClasses);

    // Engagement is school-based, so students do not need a prior quiz attempt to count.
    $totalStudents = dbRow(
        "SELECT COUNT(*) AS cnt FROM students WHERE school_id = ? AND is_active = 1 AND $studentClassSql",
        array_merge([$schoolId], $studentClassParams)
    );

    $activeStudents = dbRow(
        "SELECT COUNT(*) AS cnt
         FROM students
         WHERE school_id = ? AND is_active = 1
           AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND $studentClassSql",
        array_merge([$schoolId], $studentClassParams)
    );

    // General teachers see all school quiz activity; specialists see only their subject.
    if ($isGeneral) {
        $weeklyAttempts = dbRow(
            "SELECT COUNT(*) AS cnt, AVG(qa.score) AS avg
             FROM quiz_attempts qa
             JOIN students s ON s.id = qa.student_id
             WHERE s.school_id = ? AND s.is_active = 1 AND $aliasedClassSql
               AND qa.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            array_merge([$schoolId], $aliasedClassParams)
        );
        $topStudents = dbRows(
            "SELECT s.id,s.full_name,s.class_level,s.total_points,s.current_streak,
                    AVG(qa.score) AS avg_score,COUNT(qa.id) AS quiz_count
             FROM students s
             JOIN quiz_attempts qa ON qa.student_id = s.id
             WHERE s.school_id = ? AND s.is_active = 1 AND $aliasedClassSql
             GROUP BY s.id
             ORDER BY avg_score DESC LIMIT 5",
            array_merge([$schoolId], $aliasedClassParams)
        );
    } else {
        $weeklyAttempts = dbRow(
            "SELECT COUNT(*) AS cnt, AVG(qa.score) AS avg
             FROM quiz_attempts qa
             JOIN students s ON s.id = qa.student_id
             JOIN quizzes q ON q.id = qa.quiz_id
             JOIN topics t ON t.id = q.topic_id
             WHERE s.school_id = ? AND t.subject_id = ?
               AND s.is_active = 1 AND $aliasedClassSql
               AND qa.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            array_merge([$schoolId, $subjectId], $aliasedClassParams)
        );
        $topStudents = dbRows(
            "SELECT s.id,s.full_name,s.class_level,s.total_points,s.current_streak,
                    AVG(qa.score) AS avg_score,COUNT(qa.id) AS quiz_count
             FROM students s
             JOIN quiz_attempts qa ON qa.student_id = s.id
             JOIN quizzes q ON q.id = qa.quiz_id
             JOIN topics t ON t.id = q.topic_id
             WHERE s.school_id = ? AND t.subject_id = ?
               AND s.is_active = 1 AND $aliasedClassSql
             GROUP BY s.id
             ORDER BY avg_score DESC LIMIT 5",
            array_merge([$schoolId, $subjectId], $aliasedClassParams)
        );
    }

    // Recent activity
    $recentActivity = dbRows(
        "SELECT al.*, UNIX_TIMESTAMP(al.created_at) AS created_at_unix, s.full_name
         FROM activity_logs al
         JOIN students s ON al.user_id = s.id
         WHERE al.user_type = 'student' AND s.school_id = ? AND $aliasedClassSql
         ORDER BY al.created_at DESC LIMIT 10",
        array_merge([$teacher['school_id']], $aliasedClassParams)
    );

    // The activity chart represents actual student logins, not quiz attempts.
    $loginStats = dbRows(
        "SELECT DATE(ll.login_time) AS date, COUNT(*) AS logins
         FROM login_logs ll
         JOIN students s ON s.id = ll.user_id
         WHERE ll.user_type = 'student' AND s.school_id = ?
           AND $aliasedClassSql
           AND ll.login_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(ll.login_time)
         ORDER BY date",
        array_merge([$schoolId], $aliasedClassParams)
    );
    return [
        'teacher'         => $teacher,
        'total_students'  => (int)($totalStudents['cnt'] ?? 0),
        'active_students' => (int)($activeStudents['cnt'] ?? 0),
        'weekly_attempts' => (int)($weeklyAttempts['cnt'] ?? 0),
        'weekly_avg'      => round($weeklyAttempts['avg'] ?? 0),
        'top_students'    => $topStudents,
        'recent_activity' => $recentActivity,
        'login_stats'     => $loginStats,
    ];
}

// -----------------------------------------------
// Get all students with detailed stats
// -----------------------------------------------

function getAllStudentsDetailedForSchool(int $schoolId, ?array $classes = null): array {
    [$classSql, $classParams] = teacherClassSql('s.class_level', $classes ?? TEACHER_CLASS_LEVELS);
    return dbRows(
        "SELECT s.*,
                COUNT(DISTINCT qa.id) as quiz_count,
                AVG(qa.score) as avg_score,
                COUNT(DISTINCT tp.id) as topics_done,
                COUNT(DISTINCT sb.id) as badge_count,
                MAX(qa.completed_at) as last_quiz_date
         FROM students s
         LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
         LEFT JOIN topic_progress tp ON tp.student_id = s.id
         LEFT JOIN student_badges sb ON sb.student_id = s.id
         WHERE s.school_id = ? AND s.is_active = 1 AND $classSql
         GROUP BY s.id
         ORDER BY s.total_points DESC",
        array_merge([$schoolId], $classParams)
    );
}

/**
 * Get students in a teacher's school with statistics limited to one subject.
 * The school filter is required to prevent teachers from seeing other schools.
 */
function getAllStudentsDetailed(int $subjectId, int $schoolId, ?array $classes = null): array {
    [$classSql, $classParams] = teacherClassSql('s.class_level', $classes ?? TEACHER_CLASS_LEVELS);
    return dbRows(
        "SELECT s.*,
                COUNT(DISTINCT CASE WHEN qt.id IS NOT NULL THEN qa.id END) AS quiz_count,
                AVG(CASE WHEN qt.id IS NOT NULL THEN qa.score END) AS avg_score,
                COUNT(DISTINCT CASE WHEN pt.id IS NOT NULL THEN tp.id END) AS topics_done,
                COUNT(DISTINCT sb.id) AS badge_count,
                MAX(CASE WHEN qt.id IS NOT NULL THEN qa.completed_at END) AS last_quiz_date
         FROM students s
         LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
         LEFT JOIN quizzes q ON q.id = qa.quiz_id
         LEFT JOIN topics qt ON qt.id = q.topic_id AND qt.subject_id = ?
         LEFT JOIN topic_progress tp ON tp.student_id = s.id
         LEFT JOIN topics pt ON pt.id = tp.topic_id AND pt.subject_id = ?
         LEFT JOIN student_badges sb ON sb.student_id = s.id
         WHERE s.school_id = ? AND s.is_active = 1 AND $classSql
         GROUP BY s.id
         ORDER BY s.total_points DESC",
        array_merge([$subjectId, $subjectId, $schoolId], $classParams)
    );
}
// -----------------------------------------------
// Get student detail for teacher view
// -----------------------------------------------

function getStudentDetailForTeacher(int $studentId, ?int $schoolId = null, ?array $teacher = null): array {
    $classes = $teacher ? teacherAssignedClasses($teacher) : TEACHER_CLASS_LEVELS;
    [$classSql, $classParams] = teacherClassSql('class_level', $classes);
    $student = $schoolId === null
        ? dbRow("SELECT * FROM students WHERE id = ? AND is_active = 1 AND $classSql", array_merge([$studentId], $classParams))
        : dbRow("SELECT * FROM students WHERE id = ? AND school_id = ? AND is_active = 1 AND $classSql", array_merge([$studentId, $schoolId], $classParams));
    if (!$student) return [];

    $subjectId = null;
    if ($teacher && ($teacher['subject'] ?? 'General') !== 'General') {
        $subject = dbRow('SELECT id FROM subjects WHERE name = ?', [$teacher['subject']]);
        $subjectId = (int)($subject['id'] ?? 0);
    }
    $subjectClause = $subjectId === null ? '' : ' AND t.subject_id = ?';
    $subjectParams = $subjectId === null ? [] : [$subjectId];

    $quizHistory = dbRows(
        "SELECT qa.*, q.title as quiz_title, s.name as subject_name
         FROM quiz_attempts qa
         JOIN quizzes q ON qa.quiz_id = q.id
         JOIN topics t ON q.topic_id = t.id
         JOIN subjects s ON t.subject_id = s.id
         WHERE qa.student_id = ?{$subjectClause}
         ORDER BY qa.created_at DESC",
        array_merge([$studentId], $subjectParams)
    );

    $subjectScores = dbRows(
        "SELECT s.name, s.color, AVG(qa.score) as avg_score, COUNT(qa.id) as attempts
         FROM quiz_attempts qa
         JOIN quizzes q ON qa.quiz_id = q.id
         JOIN topics t ON q.topic_id = t.id
         JOIN subjects s ON t.subject_id = s.id
         WHERE qa.student_id = ?{$subjectClause}
         GROUP BY s.id, s.name, s.color",
        array_merge([$studentId], $subjectParams)
    );

    $badges = dbRows(
        "SELECT b.* FROM student_badges sb JOIN badges b ON sb.badge_id = b.id WHERE sb.student_id = ?",
        [$studentId]
    );

    return [
        'student'        => $student,
        'quiz_history'   => $quizHistory,
        'subject_scores' => $subjectScores,
        'badges'         => $badges,
    ];
}

// -----------------------------------------------
// Analytics: Quiz performance across school
// -----------------------------------------------

function getSchoolAnalytics(?int $subjectId, int $schoolId, ?array $classes = null): array {
    // General teachers aggregate all subjects; specialists are restricted to one subject.
    $subjectClause = $subjectId === null ? '' : ' AND sub.id = ?';
    [$classSql, $classParams] = teacherClassSql('st.class_level', $classes ?? TEACHER_CLASS_LEVELS);
    $params = array_merge([$schoolId], $classParams, $subjectId === null ? [] : [$subjectId]);

    $subjectPassRate = dbRows(
        "SELECT sub.name AS subject,sub.color,COUNT(qa.id) AS total_attempts,
                SUM(qa.passed) AS passed_count,AVG(qa.score) AS avg_score
         FROM quiz_attempts qa
         JOIN students st ON st.id=qa.student_id
         JOIN quizzes q ON q.id=qa.quiz_id
         JOIN topics t ON t.id=q.topic_id
         JOIN subjects sub ON sub.id=t.subject_id
         WHERE st.school_id=? AND st.is_active=1 AND $classSql{$subjectClause}
         GROUP BY sub.id,sub.name,sub.color
         ORDER BY avg_score DESC",
        $params
    );

    $distribution = dbRows(
        "SELECT SUM(CASE WHEN qa.score>=80 THEN 1 ELSE 0 END) AS excellent,
                SUM(CASE WHEN qa.score>=60 AND qa.score<80 THEN 1 ELSE 0 END) AS good,
                SUM(CASE WHEN qa.score>=40 AND qa.score<60 THEN 1 ELSE 0 END) AS average,
                SUM(CASE WHEN qa.score<40 THEN 1 ELSE 0 END) AS poor
         FROM quiz_attempts qa
         JOIN students st ON st.id=qa.student_id
         JOIN quizzes q ON q.id=qa.quiz_id
         JOIN topics t ON t.id=q.topic_id
         JOIN subjects sub ON sub.id=t.subject_id
         WHERE st.school_id=? AND st.is_active=1 AND $classSql{$subjectClause}",
        $params
    );

    $monthlyActivity = dbRows(
        "SELECT DATE_FORMAT(qa.completed_at,'%Y-%m') AS month,COUNT(*) AS attempts,AVG(qa.score) AS avg_score
         FROM quiz_attempts qa
         JOIN students st ON st.id=qa.student_id
         JOIN quizzes q ON q.id=qa.quiz_id
         JOIN topics t ON t.id=q.topic_id
         JOIN subjects sub ON sub.id=t.subject_id
         WHERE st.school_id=? AND st.is_active=1 AND $classSql{$subjectClause}
           AND qa.completed_at>=DATE_SUB(NOW(),INTERVAL 6 MONTH)
         GROUP BY DATE_FORMAT(qa.completed_at,'%Y-%m') ORDER BY month",
        $params
    );

    $classPerfomance = dbRows(
        "SELECT st.class_level,COUNT(DISTINCT st.id) AS student_count,
                AVG(qa.score) AS avg_score,COUNT(qa.id) AS total_quizzes
         FROM students st
         JOIN quiz_attempts qa ON qa.student_id=st.id
         JOIN quizzes q ON q.id=qa.quiz_id
         JOIN topics t ON t.id=q.topic_id
         JOIN subjects sub ON sub.id=t.subject_id
         WHERE st.school_id=? AND st.is_active=1 AND $classSql{$subjectClause}
         GROUP BY st.class_level ORDER BY st.class_level",
        $params
    );

    return [
        'subject_pass_rate'  => $subjectPassRate,
        'distribution'       => $distribution[0] ?? [],
        'monthly_activity'   => $monthlyActivity,
        'class_performance'  => $classPerfomance,
    ];
}

// -----------------------------------------------
// Generate Progress Report for student
// -----------------------------------------------

function generateStudentReport(int $studentId, ?int $schoolId = null, ?array $teacher = null): array {
    $detail = getStudentDetailForTeacher($studentId, $schoolId, $teacher);
    if (!$detail) return [];
    $student = $detail['student'];
    $subjectId = null;
    if ($teacher && ($teacher['subject'] ?? 'General') !== 'General') {
        $subject = dbRow('SELECT id FROM subjects WHERE name = ?', [$teacher['subject']]);
        $subjectId = (int)($subject['id'] ?? 0);
    }

    if ($subjectId === null) {
        $totalTopics = dbRow(
            "SELECT COUNT(*) as cnt FROM topics WHERE class_level = ?",
            [$student['class_level']]
        );

        $completedTopics = dbRow(
            "SELECT COUNT(*) as cnt FROM topic_progress WHERE student_id = ? AND status = 'completed'",
            [$studentId]
        );

        $quizStats = dbRow(
            "SELECT COUNT(*) as cnt, AVG(score) as avg, MAX(score) as max_score, MIN(score) as min_score,
                    SUM(passed) as passed_count
             FROM quiz_attempts WHERE student_id = ?",
            [$studentId]
        );
    } else {
        $totalTopics = dbRow(
            "SELECT COUNT(*) as cnt FROM topics WHERE class_level = ? AND subject_id = ?",
            [$student['class_level'], $subjectId]
        );

        $completedTopics = dbRow(
            "SELECT COUNT(*) as cnt
             FROM topic_progress tp
             JOIN topics t ON t.id = tp.topic_id
             WHERE tp.student_id = ? AND tp.status = 'completed' AND t.subject_id = ?",
            [$studentId, $subjectId]
        );

        $quizStats = dbRow(
            "SELECT COUNT(*) as cnt, AVG(qa.score) as avg, MAX(qa.score) as max_score, MIN(qa.score) as min_score,
                    SUM(qa.passed) as passed_count
             FROM quiz_attempts qa
             JOIN quizzes q ON q.id = qa.quiz_id
             JOIN topics t ON t.id = q.topic_id
             WHERE qa.student_id = ? AND t.subject_id = ?",
            [$studentId, $subjectId]
        );
    }
    $examPrediction = predictStudentExamPerformance($studentId);

    $strengths = [];
    $weaknesses = [];
    foreach ($detail['subject_scores'] as $sub) {
        if ($sub['avg_score'] >= 70) $strengths[] = $sub['name'];
        elseif ($sub['avg_score'] < 50 && $sub['avg_score'] > 0) $weaknesses[] = $sub['name'];
    }

    return [
        'student'          => $student,
        'subject_scores'   => $detail['subject_scores'],
        'quiz_history'     => array_slice($detail['quiz_history'], 0, 10),
        'badges'           => $detail['badges'],
        'total_topics'     => (int)($totalTopics['cnt'] ?? 0),
        'completed_topics' => (int)($completedTopics['cnt'] ?? 0),
        'quiz_stats'       => $quizStats,
        'strengths'        => $strengths,
        'weaknesses'       => $weaknesses,
        'exam_prediction'  => $examPrediction,
        'generated_at'     => date('F j, Y \a\t g:i A'),
    ];
}
