<?php
/**
 * EduTrack Ghana - Student Module
 * student/student.php
 */

require_once __DIR__ . '/../auth/auth.php';

// -----------------------------------------------
// Get student dashboard stats
// -----------------------------------------------

function getStudentStats(int $studentId): array {
    $student = dbRow("SELECT * FROM students WHERE id = ?", [$studentId]);

    $quizCount = dbRow(
        "SELECT COUNT(*) as cnt, AVG(score) as avg_score FROM quiz_attempts
         WHERE student_id = ? AND completed_at IS NOT NULL",
        [$studentId]
    );

    $topicsCompleted = dbRow(
        "SELECT COUNT(*) as cnt FROM topic_progress WHERE student_id = ? AND status = 'completed'",
        [$studentId]
    );

    $badgeCount = dbRow(
        "SELECT COUNT(*) as cnt FROM student_badges WHERE student_id = ?",
        [$studentId]
    );

    $recentQuizzes = dbRows(
        "SELECT qa.*, q.title as quiz_title, t.title as topic_title, s.name as subject_name
         FROM quiz_attempts qa
         JOIN quizzes q ON qa.quiz_id = q.id
         JOIN topics t ON q.topic_id = t.id
         JOIN subjects s ON t.subject_id = s.id
         WHERE qa.student_id = ? AND qa.completed_at IS NOT NULL
         ORDER BY qa.created_at DESC LIMIT 5",
        [$studentId]
    );

    return [
        'student'          => $student,
        'quiz_count'       => (int)($quizCount['cnt'] ?? 0),
        'avg_score'        => round($quizCount['avg_score'] ?? 0),
        'topics_completed' => (int)($topicsCompleted['cnt'] ?? 0),
        'badge_count'      => (int)($badgeCount['cnt'] ?? 0),
        'recent_quizzes'   => $recentQuizzes,
    ];
}

// -----------------------------------------------
// Get subjects with progress
// -----------------------------------------------

function getSubjectsWithProgress(int $studentId, string $classLevel): array {
    $subjects = dbRows("SELECT * FROM subjects ORDER BY name");
    $student = dbRow('SELECT school_id FROM students WHERE id = ?', [$studentId]);
    $schoolId = (int)($student['school_id'] ?? 0);

    foreach ($subjects as &$subject) {
        // Total topics for this subject at student's level
        $totalTopics = dbRow(
            "SELECT COUNT(*) as cnt FROM topics
             WHERE subject_id = ? AND class_level = ? AND approval_status = 'approved' AND is_active = 1
             AND (school_id IS NULL OR school_id = ?)",
            [$subject['id'], $classLevel, $schoolId]
        );

        // Completed topics
        $completedTopics = dbRow(
            "SELECT COUNT(*) as cnt 
             FROM topic_progress tp
             JOIN topics t ON tp.topic_id = t.id
             WHERE tp.student_id = ? AND t.subject_id = ? AND tp.status = 'completed' AND t.class_level = ?
             AND t.approval_status = 'approved' AND t.is_active = 1
             AND (t.school_id IS NULL OR t.school_id = ?)",
            [$studentId, $subject['id'], $classLevel, $schoolId]
        );

        // Average quiz score for this subject
        $avgScore = dbRow(
            "SELECT AVG(qa.score) as avg 
             FROM quiz_attempts qa
             JOIN quizzes q ON qa.quiz_id = q.id
             JOIN topics t ON q.topic_id = t.id
             WHERE qa.student_id = ? AND t.subject_id = ?",
            [$studentId, $subject['id']]
        );

        $total = (int)($totalTopics['cnt'] ?? 0);
        $completed = (int)($completedTopics['cnt'] ?? 0);

        $subject['total_topics']     = $total;
        $subject['completed_topics'] = $completed;
        $subject['progress_pct']     = $total > 0 ? round(($completed / $total) * 100) : 0;
        $subject['avg_score']        = round($avgScore['avg'] ?? 0);
    }

    return $subjects;
}

// -----------------------------------------------
// Get topics for a subject with progress
// -----------------------------------------------

function getTopicsForSubject(int $subjectId, int $studentId, string $classLevel): array {
    $student = dbRow('SELECT school_id FROM students WHERE id = ?', [$studentId]);
    $schoolId = (int)($student['school_id'] ?? 0);
    $topics = dbRows(
        "SELECT t.*, 
                tp.status, tp.completion_percent, tp.time_spent_minutes,
                (SELECT COUNT(*) FROM quizzes WHERE topic_id = t.id) as quiz_count
         FROM topics t
         LEFT JOIN topic_progress tp ON tp.topic_id = t.id AND tp.student_id = ?
         WHERE t.subject_id = ? AND t.class_level = ?
           AND t.approval_status = 'approved' AND t.is_active = 1
           AND (t.school_id IS NULL OR t.school_id = ?)
         ORDER BY t.sequence_order",
        [$studentId, $subjectId, $classLevel, $schoolId]
    );

    return $topics;
}

// -----------------------------------------------
// Start / Update topic progress
// -----------------------------------------------

function startTopic(int $studentId, int $topicId): void {
    $existing = dbRow(
        "SELECT id, status FROM topic_progress WHERE student_id = ? AND topic_id = ?",
        [$studentId, $topicId]
    );

    if (!$existing) {
        dbInsert(
            "INSERT INTO topic_progress (student_id, topic_id, status, started_at) VALUES (?, ?, 'in_progress', NOW())",
            [$studentId, $topicId]
        );
    } elseif ($existing['status'] === 'not_started') {
        dbQuery(
            "UPDATE topic_progress SET status = 'in_progress', started_at = NOW() WHERE student_id = ? AND topic_id = ?",
            [$studentId, $topicId]
        );
    }

    logActivity($studentId, 'student', 'topic_start', "Started topic #$topicId");
    updateStreak($studentId);
}

function completeTopic(int $studentId, int $topicId): bool {
    $db = getDB();

    try {
        $db->beginTransaction();

        // Ensure a progress row exists, then atomically claim the first completion.
        // The unique (student_id, topic_id) key also protects concurrent requests.
        dbQuery(
            "INSERT IGNORE INTO topic_progress (student_id, topic_id, status, started_at)
             VALUES (?, ?, 'in_progress', NOW())",
            [$studentId, $topicId]
        );

        $completed = dbQuery(
            "UPDATE topic_progress
             SET status = 'completed', completion_percent = 100, completed_at = NOW()
             WHERE student_id = ? AND topic_id = ? AND status <> 'completed'",
            [$studentId, $topicId]
        );

        if ($completed->rowCount() === 0) {
            $db->commit();
            return false;
        }

        awardPoints($studentId, 20, 'topic_complete');
        logActivity($studentId, 'student', 'topic_complete', "Completed topic #$topicId");
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    checkAndAwardBadges($studentId);
    return true;
}

// -----------------------------------------------
// Get quizzes available for student
// -----------------------------------------------

function getAvailableQuizzes(int $studentId, string $classLevel): array {
    $student = dbRow('SELECT school_id FROM students WHERE id = ?', [$studentId]);
    $schoolId = (int)($student['school_id'] ?? 0);
    $quizzes = dbRows(
        "SELECT q.*, t.title as topic_title, t.class_level, s.name as subject_name, s.color as subject_color,
                (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count,
                (SELECT MAX(qa.score) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = ?) as best_score,
                (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = ?) as attempt_count
         FROM quizzes q
         JOIN topics t ON q.topic_id = t.id
         JOIN subjects s ON t.subject_id = s.id
         WHERE q.is_active = 1 AND t.class_level = ?
           AND t.approval_status = 'approved' AND t.is_active = 1
           AND (t.school_id IS NULL OR t.school_id = ?)
         ORDER BY s.name, t.sequence_order",
        [$studentId, $studentId, $classLevel, $schoolId]
    );

    return $quizzes;
}

// -----------------------------------------------
// Start a quiz attempt
// -----------------------------------------------

/**
 * Build a differentiated question set from the full topic bank.
 *
 * Unseen questions and questions previously answered incorrectly are favoured.
 * The difficulty mix moves upward as the learner's measured mastery improves.
 * A student-specific tie breaker makes otherwise equal question pools differ.
 */
function getAdaptiveQuizQuestions(int $studentId, int $topicId, int $attemptNum, int $limit = 10): array {
    $profile = dbRow(
        'SELECT mastery_level FROM student_learning_profiles WHERE student_id=? AND topic_id=? LIMIT 1',
        [$studentId, $topicId]
    );
    $student = dbRow('SELECT difficulty_level FROM students WHERE id=?', [$studentId]);

    $mastery = isset($profile['mastery_level']) ? (float)$profile['mastery_level'] : null;
    if ($mastery === null) {
        $mastery = match ($student['difficulty_level'] ?? 'beginner') {
            'advanced' => 0.75,
            'intermediate' => 0.50,
            default => 0.25,
        };
    }

    $history = dbRows(
        "SELECT qa.answers_json
         FROM quiz_attempts qa
         JOIN quizzes q ON q.id=qa.quiz_id
         WHERE qa.student_id=? AND q.topic_id=? AND qa.completed_at IS NOT NULL",
        [$studentId, $topicId]
    );
    $seen = [];
    $incorrect = [];
    foreach ($history as $attempt) {
        $results = json_decode($attempt['answers_json'] ?? '{}', true);
        if (!is_array($results)) continue;
        foreach ($results as $questionId => $result) {
            $questionId = (int)$questionId;
            if ($questionId <= 0) continue;
            $seen[$questionId] = true;
            if (empty($result['is_correct'])) $incorrect[$questionId] = true;
        }
    }

    $questions = dbRows(
        "SELECT qu.id,qu.question_text,qu.option_a,qu.option_b,qu.option_c,qu.option_d,
                qu.points,qu.difficulty,qu.bloom_level
         FROM questions qu
         JOIN quizzes q ON q.id=qu.quiz_id
         WHERE q.topic_id=? AND q.is_active=1",
        [$topicId]
    );

    $difficultyTarget = $mastery >= 0.75 ? 3 : ($mastery >= 0.45 ? 2 : 1);
    $difficultyValue = ['easy' => 1, 'medium' => 2, 'hard' => 3];
    $bloomTarget = $mastery >= 0.80 ? 5 : ($mastery >= 0.60 ? 4 : ($mastery >= 0.40 ? 3 : 2));
    $bloomValue = ['remember' => 1, 'understand' => 2, 'apply' => 3, 'analyze' => 4, 'evaluate' => 5, 'create' => 6];
    foreach ($questions as &$question) {
        $questionId = (int)$question['id'];
        $distance = abs(($difficultyValue[$question['difficulty']] ?? 1) - $difficultyTarget);
        $bloomDistance = abs(($bloomValue[$question['bloom_level']] ?? 1) - $bloomTarget);
        $question['_adaptive_score'] =
            (isset($seen[$questionId]) ? 0 : 120) +
            (isset($incorrect[$questionId]) ? 55 : 0) +
            (40 - ($distance * 18)) +
            (35 - ($bloomDistance * 10)) +
            (crc32($studentId . ':' . $attemptNum . ':' . $questionId) % 31);
    }
    unset($question);

    usort($questions, static fn(array $a, array $b): int => $b['_adaptive_score'] <=> $a['_adaptive_score']);
    $selected = array_slice($questions, 0, min($limit, count($questions)));

    // Present the selected set in a stable student-specific order.
    usort($selected, static function (array $a, array $b) use ($studentId, $attemptNum): int {
        return (crc32('order:' . $studentId . ':' . $attemptNum . ':' . $a['id']) % 10000)
            <=> (crc32('order:' . $studentId . ':' . $attemptNum . ':' . $b['id']) % 10000);
    });
    foreach ($selected as &$question) unset($question['_adaptive_score']);
    unset($question);

    return $selected;
}

function startQuizAttempt(int $studentId, int $quizId): array {
    $quiz = dbRow(
        "SELECT q.* FROM quizzes q
         JOIN topics t ON t.id=q.topic_id
         JOIN students s ON s.id=?
         WHERE q.id=? AND q.is_active=1 AND t.approval_status='approved' AND t.is_active=1
           AND t.class_level=s.class_level AND (t.school_id IS NULL OR t.school_id=s.school_id)",
        [$studentId, $quizId]
    );
    if (!$quiz) return ['success' => false, 'error' => 'Quiz not found.'];

    // Check attempt limit
    $attempts = dbRow(
        "SELECT COUNT(*) as cnt FROM quiz_attempts WHERE student_id = ? AND quiz_id = ?",
        [$studentId, $quizId]
    );

    if ((int)$attempts['cnt'] >= $quiz['max_attempts']) {
        return ['success' => false, 'error' => 'Maximum attempts reached for this quiz.'];
    }

    $attemptNum = (int)$attempts['cnt'] + 1;

    // Select a personalized set from every active quiz in this topic.
    $questions = getAdaptiveQuizQuestions($studentId, (int)$quiz['topic_id'], $attemptNum, 10);

    if (empty($questions)) return ['success' => false, 'error' => 'No questions found for this quiz.'];

    // Create attempt record
    $attemptId = dbInsert(
        "INSERT INTO quiz_attempts
         (student_id,quiz_id,attempt_number,total_questions,question_ids_json,started_at)
         VALUES (?,?,?,?,?,NOW())",
        [$studentId, $quizId, $attemptNum, count($questions), json_encode(array_column($questions, 'id'))]
    );

    logActivity($studentId, 'student', 'quiz_start', "Started quiz #$quizId (attempt $attemptNum)");
    updateStreak($studentId);

    return [
        'success'    => true,
        'attempt_id' => $attemptId,
        'quiz'       => $quiz,
        'questions'  => $questions,
    ];
}

// -----------------------------------------------
// Submit quiz answers
// -----------------------------------------------

function refreshStudentLearningProfile(int $studentId, int $quizId): void {
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

function submitQuizAttempt(int $studentId, int $attemptId, array $answers): array {
    $attempt = dbRow(
        "SELECT qa.*, q.pass_score, q.id as quiz_id 
         FROM quiz_attempts qa
         JOIN quizzes q ON qa.quiz_id = q.id
         WHERE qa.id = ? AND qa.student_id = ?",
        [$attemptId, $studentId]
    );

    if (!$attempt) return ['success' => false, 'error' => 'Attempt not found.'];

    // A browser retry or refresh must not score and reward the same attempt twice.
    if (!empty($attempt['completed_at'])) {
        try {
            refreshStudentLearningProfile($studentId, (int)$attempt['quiz_id']);
        } catch (Throwable $profileError) {
            error_log('EduTrack learning profile refresh failed: ' . $profileError->getMessage());
        }
        return [
            'success'       => true,
            'score'         => (int)$attempt['score'],
            'correct'       => (int)$attempt['correct_answers'],
            'total'         => (int)$attempt['total_questions'],
            'passed'        => (bool)$attempt['passed'],
            'points_earned' => 0,
            'results'       => json_decode($attempt['answers_json'] ?? '{}', true) ?: [],
            'time_taken'    => (int)$attempt['time_taken_seconds'],
            'perfect'       => (int)$attempt['score'] === 100,
            'already_submitted' => true,
        ];
    }

    // Score exactly the personalized questions stored when this attempt began.
    $questionIds = array_values(array_filter(array_map('intval', json_decode($attempt['question_ids_json'] ?? '[]', true) ?: [])));
    if ($questionIds) {
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $questions = dbRows(
            "SELECT id,correct_answer,explanation,points FROM questions WHERE id IN ($placeholders)",
            $questionIds
        );
    } else {
        // Compatibility for attempts created before adaptive question tracking.
        $questions = dbRows(
            'SELECT id,correct_answer,explanation,points FROM questions WHERE quiz_id=?',
            [$attempt['quiz_id']]
        );
    }

    $correctCount = 0;
    $totalPoints = 0;
    $results = [];

    foreach ($questions as $q) {
        $userAnswer = strtoupper(trim($answers[$q['id']] ?? ''));
        $isCorrect = $userAnswer === $q['correct_answer'];
        if ($isCorrect) {
            $correctCount++;
            $totalPoints += $q['points'];
        }
        $results[$q['id']] = [
            'user_answer'    => $userAnswer,
            'correct_answer' => $q['correct_answer'],
            'is_correct'     => $isCorrect,
            'explanation'    => $q['explanation'],
        ];
    }

    $totalQs     = count($questions);
    $scorePercent = $totalQs > 0 ? round(($correctCount / $totalQs) * 100) : 0;
    $passed       = $scorePercent >= $attempt['pass_score'];
    $timeTaken    = time() - strtotime($attempt['started_at']);

    // Update attempt
    dbQuery(
        "UPDATE quiz_attempts SET score = ?, correct_answers = ?, time_taken_seconds = ?,
         passed = ?, answers_json = ?, completed_at = NOW()
         WHERE id = ?",
        [$scorePercent, $correctCount, $timeTaken, $passed ? 1 : 0,
         json_encode($results), $attemptId]   
    );

    // Topic mastery is core application data and does not depend on the
    // optional Python recommendation service being online.
    try {
        refreshStudentLearningProfile($studentId, (int)$attempt['quiz_id']);
    } catch (Throwable $profileError) {
        error_log('EduTrack learning profile refresh failed: ' . $profileError->getMessage());
    }
    
    // Award points
    $pointsEarned = $totalPoints;
    if ($passed) $pointsEarned += POINTS_QUIZ_COMPLETE;
    if ($scorePercent === 100) $pointsEarned += POINTS_PERFECT_SCORE;

    awardPoints($studentId, $pointsEarned, 'quiz_complete');
    $newBadges = checkAndAwardBadges($studentId);
    logActivity($studentId, 'student', 'quiz_complete', "Quiz #" . $attempt['quiz_id'] . " score: $scorePercent%");

    // Refresh the real-time ML outputs after mastery and engagement data change.
    try {
        require_once __DIR__ . '/../ml/ml.php';
        getNeuralLearnerProfile($studentId, true, true);
        predictStudentExamPerformance($studentId, true, true);
        generateMLRecommendations($studentId, 5, true);
    } catch (Throwable $mlError) {
        // Quiz completion must remain available even if an ML artifact is temporarily unavailable.
        error_log('EduTrack ML refresh failed: ' . $mlError->getMessage());
    }

    return [
        'success'       => true,
        'score'         => $scorePercent,
        'correct'       => $correctCount,
        'total'         => $totalQs,
        'passed'        => $passed,
        'points_earned' => $pointsEarned,
        'results'       => $results,
        'time_taken'    => $timeTaken,
        'perfect'       => $scorePercent === 100,
        'new_badges'    => $newBadges,
    ];
}

// -----------------------------------------------
// Award points to student
// -----------------------------------------------

function awardPoints(int $studentId, int $points, string $reason = ''): void {
    if ($points <= 0) return;
    dbQuery(
        "UPDATE students SET total_points = total_points + ? WHERE id = ?",
        [$points, $studentId]
    );
    logActivity($studentId, 'student', 'points_earned', "+$points points ($reason)");
}

// -----------------------------------------------
// Badge system
// -----------------------------------------------

function checkAndAwardBadges(int $studentId): array {
    $newBadges = [];
    $student = dbRow("SELECT * FROM students WHERE id = ?", [$studentId]);
    if (!$student) return [];

    $badges = dbRows("SELECT * FROM badges");

    foreach ($badges as $badge) {
        // Check if already earned
        $has = dbRow(
            "SELECT id FROM student_badges WHERE student_id = ? AND badge_id = ?",
            [$studentId, $badge['id']]
        );
        if ($has) continue;

        $earned = false;

        switch ($badge['criteria_type']) {
            case 'quiz_count':
                $count = dbRow(
                    "SELECT COUNT(*) as c FROM quiz_attempts WHERE student_id = ? AND completed_at IS NOT NULL",
                    [$studentId]
                );
                $earned = (int)$count['c'] >= $badge['criteria_value'];
                break;
            case 'streak':
                $earned = (int)$student['current_streak'] >= $badge['criteria_value'];
                break;
            case 'total_points':
                $earned = (int)$student['total_points'] >= $badge['criteria_value'];
                break;
            case 'perfect_score':
                $count = dbRow("SELECT COUNT(*) as c FROM quiz_attempts WHERE student_id = ? AND score = 100", [$studentId]);
                $earned = (int)$count['c'] >= $badge['criteria_value'];
                break;
            case 'speed':
                $count = dbRow(
                    "SELECT COUNT(*) as c FROM quiz_attempts WHERE student_id = ? AND time_taken_seconds <= ? AND passed = 1",
                    [$studentId, $badge['criteria_value']]
                );
                $earned = (int)$count['c'] >= 1;
                break;
            case 'subject_complete':
                // A subject counts only when every approved, active topic visible
                // to this student's class and school has been completed.
                $count = dbRow(
                    "SELECT COUNT(*) AS c
                     FROM (
                         SELECT t.subject_id
                         FROM topics t
                         LEFT JOIN topic_progress tp
                           ON tp.topic_id = t.id AND tp.student_id = ?
                         WHERE t.class_level = ?
                           AND t.approval_status = 'approved'
                           AND t.is_active = 1
                           AND (t.school_id IS NULL OR t.school_id = ?)
                         GROUP BY t.subject_id
                         HAVING COUNT(*) > 0
                            AND SUM(CASE WHEN tp.status = 'completed' THEN 1 ELSE 0 END) = COUNT(*)
                     ) completed_subjects",
                    [$studentId, $student['class_level'], (int)$student['school_id']]
                );
                $earned = (int)$count['c'] >= (int)$badge['criteria_value'];
                break;
            case 'subject_score':
                // The Math Wizard rule is based on distinct Mathematics quizzes,
                // so repeating one quiz cannot be used to reach the target.
                $count = dbRow(
                    "SELECT COUNT(DISTINCT qa.quiz_id) AS c
                     FROM quiz_attempts qa
                     JOIN quizzes q ON q.id = qa.quiz_id
                     JOIN topics t ON t.id = q.topic_id
                     JOIN subjects s ON s.id = t.subject_id
                     WHERE qa.student_id = ?
                       AND qa.completed_at IS NOT NULL
                       AND qa.score >= 80
                       AND s.code = 'MATH'",
                    [$studentId]
                );
                $earned = (int)$count['c'] >= (int)$badge['criteria_value'];
                break;
        }

        if ($earned) {
            dbInsert(
                "INSERT IGNORE INTO student_badges (student_id, badge_id) VALUES (?, ?)",
                [$studentId, $badge['id']]
            );
            awardPoints($studentId, $badge['points_reward'], 'badge_' . $badge['name']);
            $newBadges[] = $badge;
            logActivity($studentId, 'student', 'badge_earned', "Earned badge: " . $badge['name']);
        }
    }

    return $newBadges;
}

// -----------------------------------------------
// Rule-based study suggestions
// -----------------------------------------------

function getLearningPreferenceGuide(string $style): array {
    $guides = [
        'visual' => [
            'icon' => '👁️',
            'title' => 'Visual study mode',
            'summary' => 'Look for patterns, organise ideas by colour, and turn key points into a simple diagram.',
            'steps' => ['Underline important words', 'Draw a small concept map', 'Use the worked examples as visual models'],
            'recommendation_tip' => 'Use colours and a quick concept map while studying.',
        ],
        'auditory' => [
            'icon' => '🎧',
            'title' => 'Auditory study mode',
            'summary' => 'Listen to the lesson, pause after each idea, and explain it aloud in your own words.',
            'steps' => ['Select Listen for the full lesson', 'Repeat important definitions aloud', 'Discuss the topic with a classmate or teacher'],
            'recommendation_tip' => 'Listen to the lesson and explain the key idea aloud.',
        ],
        'kinesthetic' => [
            'icon' => '🧩',
            'title' => 'Practical study mode',
            'summary' => 'Learn by doing: recreate examples, use everyday objects, and practise immediately.',
            'steps' => ['Try each example yourself', 'Connect the idea to a real-life activity', 'Take a practice quiz after reading'],
            'recommendation_tip' => 'Try an example yourself, then practise with a quiz.',
        ],
        'reading' => [
            'icon' => '📝',
            'title' => 'Reading and writing mode',
            'summary' => 'Read carefully, write short notes, and produce a summary before taking the quiz.',
            'steps' => ['Write down new words and meanings', 'Summarise each section in one sentence', 'Review your notes before the quiz'],
            'recommendation_tip' => 'Make short notes and write a one-sentence summary.',
        ],
    ];

    return $guides[$style] ?? $guides['visual'];
}

function getSubjectStudyTips(string $subjectName, string $learningStyle): array {
    $subjectTips = [
        'Mathematics' => [
            '🧮 Work through each example step by step',
            '✍️ Write the formula before substituting values',
            '✅ Check your answer using another method',
            '🔁 Practise similar problems without looking at the solution',
        ],
        'English Language' => [
            '📖 Read the passage once for meaning, then again for detail',
            '📝 Record unfamiliar words and their meanings',
            '🔤 Create your own examples for each grammar rule',
            '💬 Summarise the main idea in your own words',
        ],
        'Integrated Science' => [
            '🔬 Connect each concept to an observation or experiment',
            '🧠 Learn important scientific terms and meanings',
            '➡️ Trace causes, processes, and effects in order',
            '🌍 Find an example from everyday life',
        ],
        'Social Studies' => [
            '🗺️ Use maps, timelines, and community examples',
            '📌 Separate causes, events, and consequences',
            '🏘️ Connect the lesson to life in your community',
            '💬 Explain different viewpoints before choosing a conclusion',
        ],
        'ICT' => [
            '💻 Practise each procedure on a computer when possible',
            '📝 Write steps in the correct order',
            '⌨️ Repeat important commands and shortcuts',
            '🔒 Remember the safety rule connected to the task',
        ],
        'French' => [
            '🇫🇷 Read and repeat new words aloud',
            '🗂️ Keep a vocabulary list with English meanings',
            '✍️ Use every new word in a short sentence',
            '🎧 Listen carefully for pronunciation and accents',
        ],
        'Religious & Moral Education' => [
            '🤝 Connect each value to a real-life decision',
            '📖 Identify the teaching and its practical meaning',
            '💭 Reflect on how the lesson guides behaviour',
            '🗣️ Discuss respectful examples with others',
        ],
        'Ghanaian Language' => [
            '🗣️ Read words and sentences aloud',
            '📝 Record new vocabulary, proverbs, and meanings',
            '✍️ Create your own sentences using new expressions',
            '👂 Listen to fluent speakers and repeat pronunciation',
        ],
    ];

    $tips = $subjectTips[$subjectName] ?? [
        '📚 Read the lesson carefully',
        '📝 Write down the main ideas',
        '🔁 Practise with the available quizzes',
        '🔍 Review explanations for incorrect answers',
    ];

    $guide = getLearningPreferenceGuide($learningStyle);
    array_unshift($tips, $guide['icon'] . ' ' . $guide['recommendation_tip']);
    return array_slice($tips, 0, 5);
}

function generateRecommendations(int $studentId): array {
    $student = dbRow("SELECT * FROM students WHERE id = ?", [$studentId]);
    if (!$student) return [];

    $classLevel = $student['class_level'];
    $recommendations = [];
    $studyGuide = getLearningPreferenceGuide($student['learning_style'] ?? 'visual');

    // Get all topics for student's level with progress info
    $topics = dbRows(
        "SELECT t.*, s.name as subject_name, s.color,
                COALESCE(tp.status, 'not_started') as progress_status,
                COALESCE(tp.completion_percent, 0) as completion_pct,
                (SELECT AVG(qa.score) FROM quiz_attempts qa 
                 JOIN quizzes q ON qa.quiz_id = q.id
                 WHERE qa.student_id = ? AND q.topic_id = t.id) as avg_quiz_score
         FROM topics t
         JOIN subjects s ON t.subject_id = s.id
         LEFT JOIN topic_progress tp ON tp.topic_id = t.id AND tp.student_id = ?
         WHERE t.class_level = ? AND t.approval_status = 'approved' AND t.is_active = 1
           AND (t.school_id IS NULL OR t.school_id = ?)
         ORDER BY t.subject_id, t.sequence_order",
        [$studentId, $studentId, $classLevel, (int)$student['school_id']]
    );

    // Calculate priority score for each topic using weighted algorithm
    foreach ($topics as $topic) {
        $priority = 0;
        $reason = '';

        $status     = $topic['progress_status'];
        $avgScore   = (float)($topic['avg_quiz_score'] ?? 0);
        $difficulty = $topic['difficulty'];
        $diffLevel  = $student['difficulty_level'];

        // Factor 1: Not started topics in sequence (high priority)
        if ($status === 'not_started') {
            $priority += 40;
            $reason = 'New topic to explore';
        }

        // Factor 2: In-progress topics (highest priority)
        if ($status === 'in_progress') {
            $priority += 60;
            $reason = 'Continue where you left off';
        }

        // Factor 3: Low quiz score = needs review
        if ($avgScore > 0 && $avgScore < 60) {
            $priority += 30;
            $reason = 'Needs improvement – score was ' . round($avgScore) . '%';
        }

        // Factor 4: Match difficulty to student level
        $diffMatch = [
            'beginner'     => 'easy',
            'intermediate' => 'medium',
            'advanced'     => 'hard',
        ];
        if ($difficulty === ($diffMatch[$diffLevel] ?? 'easy')) {
            $priority += 15;
        }

        // Factor 5: Streak bonus - suggest fresh topics to keep streak
        if ($student['current_streak'] > 0 && $status === 'not_started') {
            $priority += 10;
        }

        // Skip completed topics unless low score
        if ($status === 'completed' && $avgScore >= 70) continue;

        if ($priority > 0) {
            $recommendations[] = [
                'topic'    => $topic,
                'priority' => $priority,
                'reason'   => $reason,
                'study_tip' => $studyGuide['recommendation_tip'],
            ];
        }
    }

    // Sort by priority score descending
    usort($recommendations, fn($a, $b) => $b['priority'] - $a['priority']);

    return array_slice($recommendations, 0, 5);
}

// -----------------------------------------------
// Get student progress overview
// -----------------------------------------------

function getProgressOverview(int $studentId): array {
    $student = dbRow("SELECT * FROM students WHERE id = ?", [$studentId]);
    $classLevel = $student['class_level'];

    // Subject-wise scores
    $subjectScores = dbRows(
        "SELECT s.name, s.color, AVG(qa.score) as avg_score, COUNT(qa.id) as attempts
         FROM quiz_attempts qa
         JOIN quizzes q ON qa.quiz_id = q.id
         JOIN topics t ON q.topic_id = t.id
         JOIN subjects s ON t.subject_id = s.id
         WHERE qa.student_id = ?
         GROUP BY s.id, s.name, s.color",
        [$studentId]
    );

    // Weekly activity (last 7 days)
    $weeklyActivity = dbRows(
        "SELECT DATE(completed_at) as date, COUNT(*) as quizzes, AVG(score) as avg
         FROM quiz_attempts
         WHERE student_id = ? AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(completed_at)
         ORDER BY date",
        [$studentId]
    );

    // All quiz scores over time
    $scoreHistory = dbRows(
        "SELECT qa.score, qa.completed_at, q.title, s.name as subject
         FROM quiz_attempts qa
         JOIN quizzes q ON qa.quiz_id = q.id
         JOIN topics t ON q.topic_id = t.id
         JOIN subjects s ON t.subject_id = s.id
         WHERE qa.student_id = ?
         ORDER BY qa.completed_at DESC LIMIT 20",
        [$studentId]
    );

    return [
        'subject_scores'  => $subjectScores,
        'weekly_activity' => $weeklyActivity,
        'score_history'   => $scoreHistory,
        'student'         => $student,
    ];
}
