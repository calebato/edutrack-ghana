<?php
/**
 * Create a repeatable defense/demo dataset without deleting existing users.
 *
 * Target after the first run:
 * - 20 students total (8 existing + 12 defense accounts)
 * - 8 teachers total (4 existing + 4 defense accounts)
 * - Realistic quiz, topic, login, badge, mastery, and recommendation data
 *
 * Run from the project root:
 *   C:\xampp\php\php.exe database\seed_defense_demo_data.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('session.save_path', sys_get_temp_dir());
require_once __DIR__ . '/../student/student.php';

$db = getDB();
$password = 'Demo@123';
$passwordHash = password_hash($password, PASSWORD_BCRYPT);
$now = new DateTimeImmutable('now', new DateTimeZone('Africa/Accra'));

$teachers = [
    ['Mrs. Grace Osei', 'grace.osei@edutrack.com', 'Integrated Science', 1, 'TCH-DEMO-001'],
    ['Mr. Daniel Koomson', 'daniel.koomson@edutrack.com', 'English Language', 1, 'TCH-DEMO-002'],
    ['Madam Akua Badu', 'akua.badu@edutrack.com', 'Social Studies', 1, 'TCH-DEMO-003'],
    ['Mr. Kofi Nartey', 'kofi.nartey@edutrack.com', 'General', 1, 'TCH-DEMO-004'],
];

$students = [
    ['Akosua Mensimah', 'akosua.mensimah@edutrack.com', 1, 'JHS1', 'Female', 'visual', 'intermediate', 'high', 'JHS-DEMO-001', 'Adwoa Mensimah', '0244001001', 16],
    ['Yaw Ofori', 'yaw.ofori@edutrack.com', 1, 'JHS1', 'Male', 'kinesthetic', 'intermediate', 'medium', 'JHS-DEMO-002', 'Kwesi Ofori', '0244001002', 13],
    ['Abena Serwaa', 'abena.serwaa@edutrack.com', 1, 'JHS2', 'Female', 'reading', 'advanced', 'high', 'JHS-DEMO-003', 'Ama Serwaa', '0244001003', 14],
    ['Kojo Antwi', 'kojo.antwi@edutrack.com', 1, 'JHS2', 'Male', 'auditory', 'beginner', 'risk', 'JHS-DEMO-004', 'Kweku Antwi', '0244001004', 8],
    ['Esi Nyarko', 'esi.nyarko@edutrack.com', 1, 'JHS3', 'Female', 'visual', 'intermediate', 'medium', 'JHS-DEMO-005', 'Efua Nyarko', '0244001005', 12],
    ['Kwame Bediako', 'kwame.bediako@edutrack.com', 1, 'JHS1', 'Male', 'kinesthetic', 'beginner', 'risk', 'JHS-DEMO-006', 'Yaw Bediako', '0244001006', 7],
    ['Adjoa Sarpong', 'adjoa.sarpong@edutrack.com', 1, 'JHS2', 'Female', 'reading', 'intermediate', 'high', 'JHS-DEMO-007', 'Grace Sarpong', '0244001007', 15],
    ['Kwaku Frimpong', 'kwaku.frimpong@edutrack.com', 1, 'JHS3', 'Male', 'auditory', 'intermediate', 'medium', 'JHS-DEMO-008', 'Nana Frimpong', '0244001008', 10],
    ['Nana Ama Ansah', 'nana.ansah@edutrack.com', 1, 'JHS1', 'Female', 'visual', 'advanced', 'high', 'JHS-DEMO-009', 'Akua Ansah', '0244001009', 16],
    ['Fiifi Quaye', 'fiifi.quaye@edutrack.com', 1, 'JHS2', 'Male', 'kinesthetic', 'beginner', 'risk', 'JHS-DEMO-010', 'Kofi Quaye', '0244001010', 9],
    ['Mavis Agyeman', 'mavis.agyeman@edutrack.com', 1, 'JHS3', 'Female', 'reading', 'intermediate', 'medium', 'JHS-DEMO-011', 'Mercy Agyeman', '0244001011', 11],
    ['Daniel Tetteh', 'daniel.tetteh@edutrack.com', 1, 'JHS3', 'Male', 'auditory', 'beginner', 'risk', 'JHS-DEMO-012', 'Samuel Tetteh', '0244001012', 6],
];

$scorePatterns = [
    'high' => [90, 80, 100, 90, 80, 90, 70, 100, 80, 90, 80, 100, 90, 80, 90, 100],
    'medium' => [70, 60, 80, 70, 60, 70, 50, 80, 60, 70, 60, 80, 70, 60, 70, 80],
    'risk' => [40, 30, 50, 40, 20, 50, 30, 40, 50, 30, 40, 20, 50, 30, 40, 30],
];

function chooseDefenseQuizzes(string $classLevel, int $schoolId): array {
    $rows = dbRows(
        "SELECT q.id,q.topic_id,q.pass_score,t.subject_id,t.sequence_order,s.name AS subject_name
         FROM quizzes q
         JOIN topics t ON t.id=q.topic_id
         JOIN subjects s ON s.id=t.subject_id
         WHERE q.is_active=1 AND t.is_active=1 AND t.approval_status='approved'
           AND t.class_level=? AND (t.school_id IS NULL OR t.school_id=?)
         ORDER BY t.subject_id,t.sequence_order,q.id",
        [$classLevel, $schoolId]
    );

    // Two quizzes per subject gives broad coverage without an excessive dataset.
    $selected = [];
    $perSubject = [];
    foreach ($rows as $row) {
        $subjectId = (int)$row['subject_id'];
        $perSubject[$subjectId] = ($perSubject[$subjectId] ?? 0);
        if ($perSubject[$subjectId] >= 2) continue;
        $selected[] = $row;
        $perSubject[$subjectId]++;
    }
    return array_slice($selected, 0, 16);
}

function wrongOption(string $correct): string {
    foreach (['A', 'B', 'C', 'D'] as $option) {
        if ($option !== $correct) return $option;
    }
    return 'A';
}

try {
    $db->beginTransaction();

    foreach ($teachers as $teacher) {
        [$name, $email, $subject, $schoolId, $staffId] = $teacher;
        $existing = dbRow('SELECT id FROM teachers WHERE email=?', [$email]);
        if (!$existing) {
            dbInsert(
                'INSERT INTO teachers (school_id,full_name,email,password_hash,subject,staff_id,is_active,must_change_password) VALUES (?,?,?,?,?,?,1,0)',
                [$schoolId, $name, $email, $passwordHash, $subject, $staffId]
            );
        }
    }

    $schoolTeacherIds = [];
    foreach ([1] as $schoolId) {
        $teacher = dbRow("SELECT id FROM teachers WHERE school_id=? AND is_active=1 ORDER BY (subject='General') DESC,id LIMIT 1", [$schoolId]);
        $schoolTeacherIds[$schoolId] = (int)($teacher['id'] ?? 0);
    }

    foreach ($students as $studentIndex => $studentData) {
        [$name, $email, $schoolId, $classLevel, $gender, $learningStyle, $difficulty, $profile, $publicId, $parentName, $parentPhone, $targetAttempts] = $studentData;
        $student = dbRow('SELECT id FROM students WHERE email=?', [$email]);
        if (!$student) {
            $birthYear = 2011 + ($classLevel === 'JHS1' ? 1 : ($classLevel === 'JHS2' ? 0 : -1));
            $birthDate = sprintf('%04d-%02d-%02d', $birthYear, ($studentIndex % 9) + 1, ($studentIndex * 2 % 24) + 2);
            $studentId = dbInsert(
                "INSERT INTO students
                 (school_id,teacher_id,full_name,email,password_hash,student_id,class_level,gender,date_of_birth,
                  learning_style,difficulty_level,is_active,must_change_password,parent_name,parent_email,parent_phone)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$schoolId, $schoolTeacherIds[$schoolId] ?: null, $name, $email, $passwordHash, $publicId, $classLevel,
                 $gender, $birthDate, $learningStyle, $difficulty, 1, 0, $parentName,
                 strtolower(str_replace(' ', '.', $parentName)) . '@parent.demo', $parentPhone]
            );
        } else {
            $studentId = (int)$student['id'];
        }

        $attemptCount = (int)(dbRow('SELECT COUNT(*) c FROM quiz_attempts WHERE student_id=?', [$studentId])['c'] ?? 0);
        if ($attemptCount > 0) continue;

        $quizzes = array_slice(chooseDefenseQuizzes($classLevel, $schoolId), 0, $targetAttempts);
        $trackedTopicCount = (int)ceil(count($quizzes) / 2);
        $totalPoints = 0;
        $pattern = $scorePatterns[$profile];

        foreach ($quizzes as $quizIndex => $quiz) {
            $questions = dbRows('SELECT id,correct_answer,explanation,points FROM questions WHERE quiz_id=? ORDER BY id', [(int)$quiz['id']]);
            if (!$questions) continue;

            $targetScore = $pattern[$quizIndex % count($pattern)];
            $correctTarget = max(0, min(count($questions), (int)round(count($questions) * $targetScore / 100)));
            $correctCount = 0;
            $questionPoints = 0;
            $answers = [];
            foreach ($questions as $questionIndex => $question) {
                $isCorrect = $questionIndex < $correctTarget;
                $answer = $isCorrect ? $question['correct_answer'] : wrongOption($question['correct_answer']);
                if ($isCorrect) {
                    $correctCount++;
                    $questionPoints += (int)$question['points'];
                }
                $answers[$question['id']] = [
                    'user_answer' => $answer,
                    'correct_answer' => $question['correct_answer'],
                    'is_correct' => $isCorrect,
                    'explanation' => $question['explanation'],
                ];
            }

            $score = (int)round($correctCount / count($questions) * 100);
            $passed = $score >= (int)$quiz['pass_score'];
            $seconds = 210 + (($studentIndex * 31 + $quizIndex * 19) % 300);
            $daysAgo = ($studentIndex * 3 + $quizIndex) % 28;
            $completedAt = $now->sub(new DateInterval('P' . $daysAgo . 'D'))->setTime(15 + ($quizIndex % 4), ($studentIndex * 7 + $quizIndex * 3) % 60);
            $startedAt = $completedAt->sub(new DateInterval('PT' . $seconds . 'S'));

            dbInsert(
                "INSERT INTO quiz_attempts
                 (student_id,quiz_id,score,total_questions,correct_answers,time_taken_seconds,passed,attempt_number,
                  answers_json,started_at,completed_at,created_at)
                 VALUES (?,?,?,?,?,?,?,1,?,?,?,?)",
                [$studentId, (int)$quiz['id'], $score, count($questions), $correctCount, $seconds, $passed ? 1 : 0,
                 json_encode($answers), $startedAt->format('Y-m-d H:i:s'), $completedAt->format('Y-m-d H:i:s'),
                 $completedAt->format('Y-m-d H:i:s')]
            );

            $topicOrdinal = intdiv($quizIndex, 2) + 1;
            $leaveInProgress =
                ($profile === 'high' && $topicOrdinal === $trackedTopicCount) ||
                ($profile === 'medium' && $topicOrdinal > max(0, $trackedTopicCount - 2));
            $progressStatus = $score >= 60 && !$leaveInProgress ? 'completed' : 'in_progress';
            $completion = $progressStatus === 'completed' ? 100 : min(90, max(25, $score));
            dbQuery(
                "INSERT INTO topic_progress
                 (student_id,topic_id,status,time_spent_minutes,completion_percent,started_at,completed_at)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE status=VALUES(status),time_spent_minutes=VALUES(time_spent_minutes),
                    completion_percent=VALUES(completion_percent),started_at=VALUES(started_at),completed_at=VALUES(completed_at)",
                [$studentId, (int)$quiz['topic_id'], $progressStatus, (int)ceil($seconds / 60), $completion,
                 $startedAt->format('Y-m-d H:i:s'), $progressStatus === 'completed' ? $completedAt->format('Y-m-d H:i:s') : null]
            );

            $totalPoints += $questionPoints + ($passed ? POINTS_QUIZ_COMPLETE : 0) + ($score === 100 ? POINTS_PERFECT_SCORE : 0);
        }

        dbQuery(
            "INSERT INTO student_learning_profiles (student_id,topic_id,mastery_level,attempts,last_assessed)
             SELECT qa.student_id,q.topic_id,ROUND(MAX(qa.score)/100,2),COUNT(*),MAX(qa.completed_at)
             FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id
             WHERE qa.student_id=? AND qa.completed_at IS NOT NULL
             GROUP BY qa.student_id,q.topic_id
             ON DUPLICATE KEY UPDATE mastery_level=VALUES(mastery_level),attempts=VALUES(attempts),last_assessed=VALUES(last_assessed)",
            [$studentId]
        );

        $activeDaysAgo = $studentIndex % 6;
        $lastLogin = $now->sub(new DateInterval('P' . $activeDaysAgo . 'D'))->setTime(8 + ($studentIndex % 9), ($studentIndex * 11) % 60);
        $currentStreak = $profile === 'high' ? 7 : ($profile === 'medium' ? 4 : 2);
        dbQuery(
            'UPDATE students SET total_points=?,current_streak=?,longest_streak=?,last_activity_date=?,last_login=?,login_count=? WHERE id=?',
            [$totalPoints, $currentStreak, $currentStreak + 3, $lastLogin->format('Y-m-d'), $lastLogin->format('Y-m-d H:i:s'), 4 + $studentIndex, $studentId]
        );

        for ($loginIndex = 0; $loginIndex < 3; $loginIndex++) {
            $loginAt = $lastLogin->sub(new DateInterval('P' . ($loginIndex * 3) . 'D'));
            dbInsert(
                "INSERT INTO login_logs (user_id,user_type,login_time,logout_time,ip_address,session_duration_minutes)
                 VALUES (?,'student',?,?,?,?)",
                [$studentId, $loginAt->format('Y-m-d H:i:s'), $loginAt->add(new DateInterval('PT35M'))->format('Y-m-d H:i:s'),
                 '192.168.10.' . (30 + $studentIndex), 35]
            );
        }
        dbInsert(
            "INSERT INTO activity_logs (user_id,user_type,action,details,ip_address,created_at)
             VALUES (?,'student','demo_data_seeded','Defense demonstration activity generated',?,?)",
            [$studentId, '192.168.10.' . (30 + $studentIndex), $lastLogin->format('Y-m-d H:i:s')]
        );
    }

    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Defense seeder failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

// Award badges only after the main transaction is committed.
foreach ($students as $studentData) {
    $student = dbRow('SELECT id FROM students WHERE email=?', [$studentData[1]]);
    if ($student) checkAndAwardBadges((int)$student['id']);
}

echo "Defense dataset is ready.\n";
echo "Shared password: {$password}\n\n";
echo "Teacher accounts:\n";
foreach ($teachers as $teacher) echo "  {$teacher[1]}  ({$teacher[2]})\n";
echo "\nStudent accounts:\n";
foreach ($students as $student) echo "  {$student[1]}  ({$student[3]}, {$student[7]})\n";
