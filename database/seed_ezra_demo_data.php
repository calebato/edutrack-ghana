<?php
/**
 * Populate Ezra's JHS2 account with internally consistent demonstration data.
 * Run once from the project root:
 *   C:\xampp\php\php.exe database\seed_ezra_demo_data.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('session.save_path', sys_get_temp_dir());
require_once __DIR__ . '/../student/student.php';

$db = getDB();
$student = dbRow("SELECT * FROM students WHERE full_name = 'Ezra' AND class_level = 'JHS2' ORDER BY id DESC LIMIT 1");
if (!$student) {
    fwrite(STDERR, "Ezra's JHS2 account was not found.\n");
    exit(1);
}

$studentId = (int)$student['id'];
$existingAttempts = dbRow(
    "SELECT COUNT(*) AS c FROM quiz_attempts WHERE student_id = ? AND completed_at IS NOT NULL",
    [$studentId]
);
if ((int)$existingAttempts['c'] > 0) {
    fwrite(STDERR, "Seeder stopped: Ezra already has completed quiz activity.\n");
    exit(1);
}

$topics = dbRows(
    "SELECT t.id, t.subject_id, t.sequence_order, s.code AS subject_code
     FROM topics t
     JOIN subjects s ON s.id = t.subject_id
     WHERE t.class_level = 'JHS2' AND t.approval_status = 'approved' AND t.is_active = 1
       AND (t.school_id IS NULL OR t.school_id = ?)
     ORDER BY t.subject_id, t.sequence_order, t.id",
    [(int)$student['school_id']]
);

// Complete all Mathematics topics and the first six topics in every other
// subject: 49 of 56 topics and 98 of 112 quizzes in the current curriculum.
$completedTopics = array_values(array_filter($topics, static function (array $topic): bool {
    return $topic['subject_code'] === 'MATH' || (int)$topic['sequence_order'] <= 6;
}));
$completedTopicIds = array_map(static fn(array $topic): int => (int)$topic['id'], $completedTopics);

if (!$completedTopicIds) {
    fwrite(STDERR, "No eligible JHS2 topics were found.\n");
    exit(1);
}

$placeholders = implode(',', array_fill(0, count($completedTopicIds), '?'));
$quizzes = dbRows(
    "SELECT q.id, q.topic_id, q.pass_score
     FROM quizzes q
     WHERE q.is_active = 1 AND q.topic_id IN ($placeholders)
     ORDER BY q.topic_id, q.id",
    $completedTopicIds
);

$scorePattern = [80, 90, 100, 80, 90, 70, 60];
$basePoints = count($completedTopicIds) * 20;
$now = new DateTimeImmutable('now', new DateTimeZone('Africa/Accra'));

try {
    $db->beginTransaction();

    foreach ($completedTopics as $index => $topic) {
        $completedAt = $now->sub(new DateInterval('P' . (14 - ($index % 14)) . 'D'))
            ->setTime(16 + ($index % 3), ($index * 7) % 60);
        $startedAt = $completedAt->sub(new DateInterval('PT25M'));

        dbQuery(
            "INSERT INTO topic_progress
             (student_id, topic_id, status, completion_percent, time_spent_minutes, started_at, completed_at)
             VALUES (?, ?, 'completed', 100, 25, ?, ?)
             ON DUPLICATE KEY UPDATE status='completed', completion_percent=100,
                 time_spent_minutes=25, started_at=VALUES(started_at), completed_at=VALUES(completed_at)",
            [$studentId, (int)$topic['id'], $startedAt->format('Y-m-d H:i:s'), $completedAt->format('Y-m-d H:i:s')]
        );
    }

    foreach ($quizzes as $index => $quiz) {
        $questions = dbRows(
            "SELECT id, correct_answer, explanation, points FROM questions WHERE quiz_id = ? ORDER BY id",
            [(int)$quiz['id']]
        );
        if (!$questions) continue;

        $targetScore = $scorePattern[$index % count($scorePattern)];
        $correctTarget = (int)round(count($questions) * $targetScore / 100);
        $correctCount = 0;
        $earnedQuestionPoints = 0;
        $answers = [];

        foreach ($questions as $questionIndex => $question) {
            $isCorrect = $questionIndex < $correctTarget;
            $answer = $question['correct_answer'];
            if (!$isCorrect) {
                foreach (['A', 'B', 'C', 'D'] as $option) {
                    if ($option !== $question['correct_answer']) {
                        $answer = $option;
                        break;
                    }
                }
            } else {
                $correctCount++;
                $earnedQuestionPoints += (int)$question['points'];
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
        $timeTaken = 180 + (($index * 17) % 240);
        $completedAt = $now->sub(new DateInterval('P' . (13 - ($index % 14)) . 'D'))
            ->setTime(17 + ($index % 2), ($index * 5) % 60);
        $startedAt = $completedAt->sub(new DateInterval('PT' . $timeTaken . 'S'));

        dbInsert(
            "INSERT INTO quiz_attempts
             (student_id, quiz_id, score, total_questions, correct_answers, time_taken_seconds,
              passed, attempt_number, answers_json, started_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)",
            [$studentId, (int)$quiz['id'], $score, count($questions), $correctCount, $timeTaken,
             $passed ? 1 : 0, json_encode($answers), $startedAt->format('Y-m-d H:i:s'), $completedAt->format('Y-m-d H:i:s')]
        );

        $basePoints += $earnedQuestionPoints;
        if ($passed) $basePoints += POINTS_QUIZ_COMPLETE;
        if ($score === 100) $basePoints += POINTS_PERFECT_SCORE;
    }

    dbQuery(
        "INSERT INTO student_learning_profiles (student_id, topic_id, mastery_level, attempts, last_assessed)
         SELECT qa.student_id, q.topic_id, ROUND(MAX(qa.score) / 100, 2), COUNT(*), MAX(qa.completed_at)
         FROM quiz_attempts qa JOIN quizzes q ON q.id = qa.quiz_id
         WHERE qa.student_id = ? AND qa.completed_at IS NOT NULL
         GROUP BY qa.student_id, q.topic_id
         ON DUPLICATE KEY UPDATE mastery_level=VALUES(mastery_level), attempts=VALUES(attempts),
             last_assessed=VALUES(last_assessed)",
        [$studentId]
    );

    dbQuery(
        "UPDATE students SET total_points=?, current_streak=7, longest_streak=10,
         last_activity_date=CURDATE(), updated_at=NOW() WHERE id=?",
        [$basePoints, $studentId]
    );

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Seeder failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$newBadges = checkAndAwardBadges($studentId);
logActivity($studentId, 'student', 'demo_data_seeded', 'Generated consistent JHS2 demonstration activity');

$summary = dbRow(
    "SELECT s.total_points, s.current_streak, s.longest_streak,
            (SELECT COUNT(*) FROM topic_progress tp WHERE tp.student_id=s.id AND tp.status='completed') topics,
            (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.student_id=s.id AND qa.completed_at IS NOT NULL) quizzes,
            (SELECT COUNT(*) FROM student_badges sb WHERE sb.student_id=s.id) badges
     FROM students s WHERE s.id=?",
    [$studentId]
);

echo "Ezra demo data created successfully.\n";
echo "Topics: {$summary['topics']}\nQuizzes: {$summary['quizzes']}\n";
echo "Streak: {$summary['current_streak']} (best {$summary['longest_streak']})\n";
echo "Badges: {$summary['badges']}\nPoints: {$summary['total_points']}\n";
