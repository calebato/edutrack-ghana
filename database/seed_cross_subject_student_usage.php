<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../ml/ml.php';

$studentProfiles = [
    'ama@gmail.com' => 'high',
    'kwame@gmail.com' => 'good',
    'efua@gmail.com' => 'risk',
    'yaw@gmail.com' => 'average',
    'akosua@gmail.com' => 'good',
    'kofi@gmail.com' => 'good',
    'abena@gmail.com' => 'high',
    'kojo@gmail.com' => 'risk',
    'adwoa@gmail.com' => 'average',
    'nana@gmail.com' => 'good',
    'esi@gmail.com' => 'high',
    'kwesi@gmail.com' => 'risk',
    'afia@gmail.com' => 'average',
    'kwaku@gmail.com' => 'good',
    'akua@gmail.com' => 'good',
];

function seedScorePlan(string $profile, int $subjectIndex): int {
    $plans = [
        'high' => [92, 88, 95, 86, 90, 84, 94],
        'good' => [78, 82, 74, 80, 76, 84, 72],
        'average' => [58, 64, 55, 62, 60, 52, 68],
        'risk' => [38, 45, 32, 48, 42, 35, 40],
    ];
    $scores = $plans[$profile] ?? $plans['average'];
    return $scores[$subjectIndex % count($scores)];
}

function seedTime(int $daysAgo, int $hour, int $minute): string {
    return (new DateTimeImmutable('today'))
        ->modify("-{$daysAgo} days")
        ->setTime($hour, $minute)
        ->format('Y-m-d H:i:s');
}

function seedWrongAnswer(string $correct): string {
    return match ($correct) {
        'A' => 'B',
        'B' => 'C',
        'C' => 'D',
        default => 'A',
    };
}

function seedLogAt(int $studentId, string $action, string $details, string $time): void {
    dbInsert(
        'INSERT INTO activity_logs (user_id,user_type,action,details,ip_address,created_at) VALUES (?,?,?,?,?,?)',
        [$studentId, 'student', $action, $details, '127.0.0.1', $time]
    );
}

function seedRefreshLearningProfile(int $studentId, int $quizId): void {
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

function seedQuizAttempt(int $studentId, array $quiz, int $score, string $startedAt, int $duration): void {
    $questions = dbRows('SELECT id,correct_answer,explanation FROM questions WHERE quiz_id=? ORDER BY id LIMIT 10', [(int)$quiz['id']]);
    if (!$questions) return;

    $correctTarget = max(0, min(count($questions), (int)round(($score / 100) * count($questions))));
    $answers = [];
    $correct = 0;
    foreach ($questions as $index => $question) {
        $isCorrect = $index < $correctTarget;
        if ($isCorrect) $correct++;
        $answers[(int)$question['id']] = [
            'user_answer' => $isCorrect ? $question['correct_answer'] : seedWrongAnswer((string)$question['correct_answer']),
            'correct_answer' => $question['correct_answer'],
            'is_correct' => $isCorrect,
            'explanation' => $question['explanation'],
        ];
    }

    $completedAt = (new DateTimeImmutable($startedAt))->modify("+{$duration} seconds")->format('Y-m-d H:i:s');
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
            $score >= (int)$quiz['pass_score'] ? 1 : 0,
            1,
            json_encode($answers),
            json_encode(array_map('intval', array_column($questions, 'id'))),
            $startedAt,
            $completedAt,
            $completedAt,
        ]
    );
    seedRefreshLearningProfile($studentId, (int)$quiz['id']);
    seedLogAt($studentId, 'quiz_start', 'Started quiz #' . (int)$quiz['id'] . ' (attempt 1)', $startedAt);
    seedLogAt($studentId, 'quiz_complete', 'Quiz #' . (int)$quiz['id'] . " score: {$score}%", $completedAt);
}

$db = getDB();
$db->beginTransaction();
try {
    foreach ($studentProfiles as $email => $profile) {
        $student = dbRow('SELECT id,class_level FROM students WHERE email=?', [$email]);
        if (!$student) continue;
        $studentId = (int)$student['id'];
        $classLevel = (string)$student['class_level'];

        // Refresh non-Mathematics seeded activity for these prepared accounts, then rebuild it evenly.
        dbQuery(
            'DELETE qa FROM quiz_attempts qa
             JOIN quizzes q ON q.id=qa.quiz_id
             JOIN topics t ON t.id=q.topic_id
             WHERE qa.student_id=? AND t.subject_id<>1',
            [$studentId]
        );
        dbQuery(
            'DELETE tp FROM topic_progress tp
             JOIN topics t ON t.id=tp.topic_id
             WHERE tp.student_id=? AND t.subject_id<>1',
            [$studentId]
        );
        dbQuery(
            'DELETE slp FROM student_learning_profiles slp
             JOIN topics t ON t.id=slp.topic_id
             WHERE slp.student_id=? AND t.subject_id<>1',
            [$studentId]
        );

        $subjects = dbRows('SELECT id,name FROM subjects WHERE id<>1 ORDER BY id');
        foreach ($subjects as $subjectIndex => $subject) {
            $topics = dbRows(
                'SELECT id,title FROM topics
                 WHERE subject_id=? AND class_level=? AND approval_status="approved" AND is_active=1
                 ORDER BY sequence_order,id LIMIT 2',
                [(int)$subject['id'], $classLevel]
            );
            foreach ($topics as $topicIndex => $topic) {
                $daysAgo = 8 - (($subjectIndex + $topicIndex + $studentId) % 7);
                $startedAt = seedTime(max(0, $daysAgo), 9 + (($subjectIndex + $topicIndex) % 7), ($studentId + $subjectIndex * 6 + $topicIndex * 9) % 55);
                $completedAt = (new DateTimeImmutable($startedAt))->modify('+' . (15 + $subjectIndex + $topicIndex) . ' minutes')->format('Y-m-d H:i:s');
                $complete = $profile !== 'risk' || $topicIndex === 0 || $subjectIndex % 2 === 0;
                dbInsert(
                    'INSERT INTO topic_progress (student_id,topic_id,status,time_spent_minutes,completion_percent,started_at,completed_at)
                     VALUES (?,?,?,?,?,?,?)',
                    [
                        $studentId,
                        (int)$topic['id'],
                        $complete ? 'completed' : 'in_progress',
                        14 + ($subjectIndex * 3) + $topicIndex,
                        $complete ? 100 : 50 + ($subjectIndex * 4),
                        $startedAt,
                        $complete ? $completedAt : null,
                    ]
                );
                seedLogAt($studentId, 'topic_start', 'Started topic #' . (int)$topic['id'], $startedAt);
                if ($complete) {
                    seedLogAt($studentId, 'topic_complete', 'Completed topic #' . (int)$topic['id'], $completedAt);
                }
            }

            $quiz = dbRow(
                'SELECT q.*
                 FROM quizzes q
                 JOIN topics t ON t.id=q.topic_id
                 WHERE q.is_active=1 AND t.subject_id=? AND t.class_level=?
                 ORDER BY t.sequence_order,q.id LIMIT 1',
                [(int)$subject['id'], $classLevel]
            );
            if ($quiz) {
                $score = seedScorePlan($profile, $subjectIndex);
                $startedAt = seedTime(max(0, 6 - (($subjectIndex + $studentId) % 6)), 10 + ($subjectIndex % 6), ($studentId * 2 + $subjectIndex * 7) % 55);
                seedQuizAttempt($studentId, $quiz, $score, $startedAt, 185 + (($studentId + $subjectIndex) % 8) * 27);
            }
        }

        $topicPoints = (int)dbValue(
            "SELECT COUNT(*) * 20 FROM topic_progress WHERE student_id=? AND status='completed'",
            [$studentId]
        );
        $quizPoints = (int)dbValue(
            'SELECT COALESCE(SUM(correct_answers * 10 + CASE WHEN passed=1 THEN 25 ELSE 0 END + CASE WHEN score=100 THEN 50 ELSE 0 END),0)
             FROM quiz_attempts WHERE student_id=?',
            [$studentId]
        );
        dbQuery('UPDATE students SET total_points=? WHERE id=?', [$topicPoints + $quizPoints, $studentId]);
        predictStudentExamPerformance($studentId, true, true);
        generateMLRecommendations($studentId, 5, true);
    }

    $db->commit();
    echo "Cross-subject student usage updated.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}
