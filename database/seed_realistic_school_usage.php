<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../ml/ml.php';

$teacherSeed = [
    ['full_name' => 'Emmanuel Mensah', 'email' => 'emmanuel@gmail.com', 'subject' => 'Mathematics', 'staff_id' => 'TCH-101'],
    ['full_name' => 'Abigail Owusu', 'email' => 'abigail@gmail.com', 'subject' => 'English Language', 'staff_id' => 'TCH-102'],
    ['full_name' => 'Daniel Boateng', 'email' => 'daniel@gmail.com', 'subject' => 'Integrated Science', 'staff_id' => 'TCH-103'],
    ['full_name' => 'Priscilla Addo', 'email' => 'priscilla@gmail.com', 'subject' => 'Social Studies', 'staff_id' => 'TCH-104'],
    ['full_name' => 'Josephine Asante', 'email' => 'josephine@gmail.com', 'subject' => 'General', 'staff_id' => 'TCH-105'],
];

$studentSeed = [
    ['Ama Boateng', 'ama@gmail.com', 'JHS1', 'Female', 'visual', 'advanced', 'high'],
    ['Kwame Mensah', 'kwame@gmail.com', 'JHS1', 'Male', 'kinesthetic', 'intermediate', 'good'],
    ['Efua Asante', 'efua@gmail.com', 'JHS1', 'Female', 'reading', 'beginner', 'risk'],
    ['Yaw Osei', 'yaw@gmail.com', 'JHS1', 'Male', 'auditory', 'intermediate', 'average'],
    ['Akosua Frimpong', 'akosua@gmail.com', 'JHS1', 'Female', 'visual', 'advanced', 'good'],
    ['Kofi Appiah', 'kofi@gmail.com', 'JHS2', 'Male', 'reading', 'intermediate', 'good'],
    ['Abena Darko', 'abena@gmail.com', 'JHS2', 'Female', 'visual', 'advanced', 'high'],
    ['Kojo Agyeman', 'kojo@gmail.com', 'JHS2', 'Male', 'kinesthetic', 'beginner', 'risk'],
    ['Adwoa Serwaa', 'adwoa@gmail.com', 'JHS2', 'Female', 'auditory', 'intermediate', 'average'],
    ['Nana Yeboah', 'nana@gmail.com', 'JHS2', 'Male', 'visual', 'intermediate', 'good'],
    ['Esi Biney', 'esi@gmail.com', 'JHS3', 'Female', 'reading', 'advanced', 'high'],
    ['Kwesi Tetteh', 'kwesi@gmail.com', 'JHS3', 'Male', 'kinesthetic', 'beginner', 'risk'],
    ['Afia Nyarko', 'afia@gmail.com', 'JHS3', 'Female', 'visual', 'intermediate', 'average'],
    ['Kwaku Sarpong', 'kwaku@gmail.com', 'JHS3', 'Male', 'auditory', 'advanced', 'good'],
    ['Akua Danso', 'akua@gmail.com', 'JHS3', 'Female', 'reading', 'intermediate', 'good'],
];

function seedPassword(): string {
    static $hash = null;
    if ($hash === null) {
        $hash = password_hash('Password123', PASSWORD_DEFAULT);
    }
    return $hash;
}

function placeholders(array $items): string {
    return implode(',', array_fill(0, count($items), '?'));
}

function shiftTime(int $daysAgo, int $hour, int $minute = 0): string {
    return (new DateTimeImmutable('today'))
        ->modify("-{$daysAgo} days")
        ->setTime($hour, $minute)
        ->format('Y-m-d H:i:s');
}

function wrongAnswer(string $correct): string {
    return match ($correct) {
        'A' => 'B',
        'B' => 'C',
        'C' => 'D',
        default => 'A',
    };
}

function refreshSeedLearningProfile(int $studentId, int $quizId): void {
    dbQuery(
        'INSERT INTO student_learning_profiles
         (student_id, topic_id, mastery_level, attempts, last_assessed)
         SELECT qa.student_id, q.topic_id, ROUND(MAX(qa.score) / 100, 2), COUNT(*), MAX(qa.completed_at)
         FROM quiz_attempts qa
         JOIN quizzes q ON q.id = qa.quiz_id
         WHERE qa.student_id = ?
           AND q.topic_id = (SELECT topic_id FROM quizzes WHERE id = ?)
           AND qa.completed_at IS NOT NULL
         GROUP BY qa.student_id, q.topic_id
         ON DUPLICATE KEY UPDATE
             mastery_level = VALUES(mastery_level),
             attempts = VALUES(attempts),
             last_assessed = VALUES(last_assessed)',
        [$studentId, $quizId]
    );
}

function scorePlan(string $profile): array {
    return match ($profile) {
        'high' => [95, 90, 88, 92, 86, 100, 90, 84, 94, 88],
        'good' => [78, 82, 74, 85, 80, 76, 88, 72],
        'average' => [62, 55, 68, 58, 64, 60, 52],
        default => [38, 45, 32, 48, 42, 35],
    };
}

function resetSeededStudents(array $emails): void {
    if (!$emails) return;
    $ids = array_map('intval', array_column(dbRows(
        'SELECT id FROM students WHERE email IN (' . placeholders($emails) . ')',
        $emails
    ), 'id'));
    if (!$ids) return;

    $in = placeholders($ids);
    foreach ([
        "DELETE FROM student_recommendations WHERE student_id IN ($in)",
        "DELETE FROM student_ml_profiles WHERE student_id IN ($in)",
        "DELETE FROM student_predictions WHERE student_id IN ($in)",
        "DELETE FROM final_exam_results WHERE student_id IN ($in)",
        "DELETE FROM student_learning_profiles WHERE student_id IN ($in)",
        "DELETE FROM student_badges WHERE student_id IN ($in)",
        "DELETE FROM topic_progress WHERE student_id IN ($in)",
        "DELETE FROM quiz_attempts WHERE student_id IN ($in)",
        "DELETE FROM login_logs WHERE user_type='student' AND user_id IN ($in)",
        "DELETE FROM activity_logs WHERE user_type='student' AND user_id IN ($in)",
        "DELETE FROM announcement_views WHERE student_id IN ($in)",
        "DELETE FROM violation_reports WHERE student_id IN ($in)",
        "DELETE FROM student_report_remarks WHERE student_id IN ($in)",
    ] as $sql) {
        try {
            dbQuery($sql, $ids);
        } catch (Throwable $error) {
            // Some optional tables may not exist in older database snapshots.
        }
    }
    dbQuery("DELETE FROM students WHERE id IN ($in)", $ids);
}

function makeAttempt(int $studentId, array $quiz, int $score, string $startedAt, int $duration): void {
    $questions = dbRows('SELECT id,correct_answer,explanation FROM questions WHERE quiz_id=? ORDER BY id LIMIT 10', [(int)$quiz['id']]);
    if (!$questions) return;

    $correctTarget = max(0, min(count($questions), (int)round(($score / 100) * count($questions))));
    $answers = [];
    $correct = 0;
    foreach ($questions as $index => $question) {
        $isCorrect = $index < $correctTarget;
        if ($isCorrect) $correct++;
        $answers[(int)$question['id']] = [
            'user_answer' => $isCorrect ? $question['correct_answer'] : wrongAnswer((string)$question['correct_answer']),
            'correct_answer' => $question['correct_answer'],
            'is_correct' => $isCorrect,
            'explanation' => $question['explanation'],
        ];
    }
    $completedAt = (new DateTimeImmutable($startedAt))->modify("+{$duration} seconds")->format('Y-m-d H:i:s');
    $passed = $score >= (int)$quiz['pass_score'] ? 1 : 0;

    dbInsert(
        'INSERT INTO quiz_attempts
         (student_id,quiz_id,score,total_questions,correct_answers,time_taken_seconds,passed,attempt_number,answers_json,question_ids_json,started_at,completed_at,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $studentId,
            (int)$quiz['id'],
            $score,
            count($questions),
            $correct,
            $duration,
            $passed,
            1,
            json_encode($answers),
            json_encode(array_map('intval', array_column($questions, 'id'))),
            $startedAt,
            $completedAt,
            $completedAt,
        ]
    );

    refreshSeedLearningProfile($studentId, (int)$quiz['id']);
    logAt($studentId, 'student', 'quiz_start', 'Started quiz #' . (int)$quiz['id'] . ' (attempt 1)', $startedAt);
    logAt($studentId, 'student', 'quiz_complete', 'Quiz #' . (int)$quiz['id'] . " score: {$score}%", $completedAt);
}

function logAt(int $userId, string $userType, string $action, string $details, string $time): void {
    dbInsert(
        'INSERT INTO activity_logs (user_id,user_type,action,details,ip_address,created_at) VALUES (?,?,?,?,?,?)',
        [$userId, $userType, $action, $details, '127.0.0.1', $time]
    );
}

$db = getDB();
$db->beginTransaction();
try {
    $studentEmails = array_map(static fn(array $row): string => $row[1], $studentSeed);
    resetSeededStudents($studentEmails);

    $teacherIds = [];
    foreach ($teacherSeed as $index => $teacher) {
        $existing = dbRow('SELECT id FROM teachers WHERE email=?', [$teacher['email']]);
        $lastLogin = shiftTime($index % 3, 7 + $index, 15);
        if ($existing) {
            $teacherId = (int)$existing['id'];
            dbQuery(
                'UPDATE teachers SET full_name=?,subject=?,staff_id=?,school_id=1,is_active=1,last_login=?,login_count=login_count+3,must_change_password=0 WHERE id=?',
                [$teacher['full_name'], $teacher['subject'], $teacher['staff_id'], $lastLogin, $teacherId]
            );
        } else {
            $teacherId = dbInsert(
                'INSERT INTO teachers (school_id,full_name,email,password_hash,subject,staff_id,is_active,last_login,login_count,must_change_password)
                 VALUES (1,?,?,?,?,?,1,?,3,0)',
                [$teacher['full_name'], $teacher['email'], seedPassword(), $teacher['subject'], $teacher['staff_id'], $lastLogin]
            );
        }
        $teacherIds[] = $teacherId;
        dbQuery("DELETE FROM login_logs WHERE user_type='teacher' AND user_id=?", [$teacherId]);
        dbQuery("DELETE FROM activity_logs WHERE user_type='teacher' AND user_id=?", [$teacherId]);
        dbInsert(
            'INSERT INTO login_logs (user_id,user_type,login_time,logout_time,ip_address,session_duration_minutes,created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$teacherId, 'teacher', $lastLogin, (new DateTimeImmutable($lastLogin))->modify('+42 minutes')->format('Y-m-d H:i:s'), '127.0.0.1', 42, $lastLogin]
        );
        logAt($teacherId, 'teacher', 'login', 'User logged in', $lastLogin);
    }

    $subjectIds = array_column(dbRows('SELECT id,name FROM subjects', []), 'id', 'name');
    $badgeIds = array_column(dbRows('SELECT id,name FROM badges', []), 'id', 'name');
    $createdStudents = 0;

    foreach ($studentSeed as $index => $seed) {
        [$fullName, $email, $classLevel, $gender, $style, $difficulty, $profile] = $seed;
        $teacherId = $teacherIds[$index % count($teacherIds)];
        $first = strtolower(strtok($fullName, ' '));
        $lastLogin = shiftTime($index % 6, 8 + ($index % 8), ($index * 7) % 55);
        $loginCount = 8 + ($index % 7);
        $streak = match ($profile) {
            'high' => 8,
            'good' => 5,
            'average' => 3,
            default => 1,
        };

        $studentId = dbInsert(
            'INSERT INTO students
             (school_id,teacher_id,full_name,email,password_hash,student_id,class_level,gender,date_of_birth,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,last_login,login_count,must_change_password,parent_name,parent_email,parent_phone)
             VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE(),1,?,?,0,?,?,?)',
            [
                $teacherId,
                $fullName,
                $email,
                seedPassword(),
                'STU-' . str_pad((string)($index + 101), 4, '0', STR_PAD_LEFT),
                $classLevel,
                $gender,
                (new DateTimeImmutable('2011-01-01'))->modify('+' . ($index * 67) . ' days')->format('Y-m-d'),
                $style,
                $difficulty,
                0,
                $streak,
                max($streak, $streak + 2),
                $lastLogin,
                $loginCount,
                'Parent of ' . $first,
                $first . '.parent@gmail.com',
                '024' . str_pad((string)(7000000 + $index * 317), 7, '0', STR_PAD_LEFT),
            ]
        );

        for ($d = 6; $d >= 0; $d -= 2) {
            $loginAt = shiftTime($d + ($index % 2), 7 + ($index % 5), ($index * 11 + $d) % 60);
            dbInsert(
                'INSERT INTO login_logs (user_id,user_type,login_time,logout_time,ip_address,session_duration_minutes,created_at)
                 VALUES (?,?,?,?,?,?,?)',
                [$studentId, 'student', $loginAt, (new DateTimeImmutable($loginAt))->modify('+' . (22 + $index % 20) . ' minutes')->format('Y-m-d H:i:s'), '127.0.0.1', 22 + $index % 20, $loginAt]
            );
        }
        logAt($studentId, 'student', 'login', 'User logged in', $lastLogin);

        $topics = dbRows(
            "SELECT id,subject_id,title FROM topics
             WHERE class_level=? AND approval_status='approved' AND is_active=1
             ORDER BY subject_id,sequence_order,id",
            [$classLevel]
        );
        $topicLimit = match ($profile) {
            'high' => 26,
            'good' => 20,
            'average' => 14,
            default => 9,
        };
        foreach (array_slice($topics, 0, min($topicLimit, count($topics))) as $topicIndex => $topic) {
            $complete = $profile !== 'risk' || $topicIndex < 5;
            $startedAt = shiftTime(12 - ($topicIndex % 10), 9 + ($topicIndex % 6), ($index * 5 + $topicIndex) % 55);
            $completedAt = (new DateTimeImmutable($startedAt))->modify('+' . (18 + $topicIndex) . ' minutes')->format('Y-m-d H:i:s');
            dbInsert(
                'INSERT INTO topic_progress (student_id,topic_id,status,time_spent_minutes,completion_percent,started_at,completed_at)
                 VALUES (?,?,?,?,?,?,?)',
                [
                    $studentId,
                    (int)$topic['id'],
                    $complete ? 'completed' : 'in_progress',
                    12 + ($topicIndex % 25),
                    $complete ? 100 : 45 + ($topicIndex % 35),
                    $startedAt,
                    $complete ? $completedAt : null,
                ]
            );
            logAt($studentId, 'student', 'topic_start', 'Started topic #' . (int)$topic['id'], $startedAt);
            if ($complete) {
                logAt($studentId, 'student', 'topic_complete', 'Completed topic #' . (int)$topic['id'], $completedAt);
            }
        }

        $quizzes = dbRows(
            "SELECT q.*,t.subject_id,t.title AS topic_title
             FROM quizzes q
             JOIN topics t ON t.id=q.topic_id
             WHERE q.is_active=1 AND t.class_level=? AND t.approval_status='approved' AND t.is_active=1
             ORDER BY t.subject_id,t.sequence_order,q.id",
            [$classLevel]
        );
        $scores = scorePlan($profile);
        foreach ($scores as $attemptIndex => $score) {
            if (!isset($quizzes[$attemptIndex])) break;
            $startedAt = shiftTime(9 - ($attemptIndex % 7), 10 + ($attemptIndex % 7), ($index * 3 + $attemptIndex * 4) % 55);
            makeAttempt($studentId, $quizzes[$attemptIndex], $score, $startedAt, 170 + (($index + $attemptIndex) % 9) * 34);
        }

        $points = (int)dbValue(
            'SELECT COALESCE(SUM(correct_answers * 10 + CASE WHEN passed=1 THEN 25 ELSE 0 END + CASE WHEN score=100 THEN 50 ELSE 0 END),0)
             FROM quiz_attempts WHERE student_id=?',
            [$studentId]
        );
        $points += (int)dbValue(
            "SELECT COUNT(*) * 20 FROM topic_progress WHERE student_id=? AND status='completed'",
            [$studentId]
        );
        dbQuery('UPDATE students SET total_points=? WHERE id=?', [$points, $studentId]);

        $badgeNames = ['First Steps'];
        if (count($scores) >= 5) $badgeNames[] = 'Quick Learner';
        if (count($scores) >= 10) $badgeNames[] = 'Knowledge Seeker';
        if (max($scores) >= 100) $badgeNames[] = 'Perfect Score';
        if ($streak >= 3) $badgeNames[] = 'Streak Starter';
        if ($streak >= 7) $badgeNames[] = 'Week Warrior';
        if ($points >= 500) $badgeNames[] = 'Scholar';
        if ($points >= 1000) $badgeNames[] = 'Champion';
        if (min($scores) >= 60) $badgeNames[] = 'Speed Demon';
        foreach (array_unique($badgeNames) as $badgeName) {
            if (!isset($badgeIds[$badgeName])) continue;
            dbInsert(
                'INSERT IGNORE INTO student_badges (student_id,badge_id,earned_at) VALUES (?,?,?)',
                [$studentId, (int)$badgeIds[$badgeName], shiftTime($index % 8, 15, ($index * 9) % 60)]
            );
            logAt($studentId, 'student', 'badge_earned', 'Earned badge: ' . $badgeName, shiftTime($index % 8, 15, ($index * 9) % 60));
        }

        foreach ($subjectIds as $subjectName => $subjectId) {
            $avg = (float)dbValue(
                'SELECT AVG(qa.score)
                 FROM quiz_attempts qa
                 JOIN quizzes q ON q.id=qa.quiz_id
                 JOIN topics t ON t.id=q.topic_id
                 WHERE qa.student_id=? AND t.subject_id=?',
                [$studentId, (int)$subjectId]
            );
            if ($avg > 0) {
                dbInsert(
                    'INSERT INTO final_exam_results (student_id,academic_year,subject_id,score,recorded_by)
                     VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE score=VALUES(score),recorded_by=VALUES(recorded_by)',
                    [$studentId, '2025/2026', (int)$subjectId, max(25, min(98, round($avg + (($index % 5) - 2), 2))), $teacherId]
                );
            }
        }

        $segment = match ($profile) {
            'high' => 'mastering',
            'good' => 'improving',
            'average' => 'developing',
            default => 'needs_support',
        };
        dbQuery(
            'INSERT INTO student_ml_profiles (student_id,segment,embedding_json,model_version,inference_source,generated_at)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE segment=VALUES(segment),embedding_json=VALUES(embedding_json),model_version=VALUES(model_version),inference_source=VALUES(inference_source),generated_at=NOW()',
            [$studentId, $segment, json_encode(['profile' => $profile, 'engagement' => $loginCount, 'points' => $points]), 'seeded-profile-v1', 'seeded_usage']
        );

        predictStudentExamPerformance($studentId, true, true);
        generateMLRecommendations($studentId, 5, true);
        $createdStudents++;
    }

    $db->commit();
    echo "Realistic school usage seed complete. Created {$createdStudents} students and " . count($teacherIds) . " teachers. Password for seeded accounts: Password123\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}
