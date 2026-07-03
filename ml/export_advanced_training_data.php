<?php
require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

function advancedMean(array $values): float {
    return $values ? array_sum($values) / count($values) : 0.0;
}

function temporalFeatures(array $history, array $student): array {
    $scores = array_map(static fn(array $row): float => (float)$row['score'], $history);
    $recent = array_slice($scores, -3);
    $older = array_slice($scores, max(0, count($scores) - 6), 3);
    $times = array_values(array_filter(array_map(
        static fn(array $row): float => (float)$row['time_taken_seconds'],
        $history
    ), static fn(float $time): bool => $time > 0 && $time < 7200));
    return [
        'avg_score' => advancedMean($scores),
        'recent_avg' => advancedMean($recent),
        'trend' => advancedMean($recent) - advancedMean($older ?: $recent),
        'pass_rate' => advancedMean(array_map(static fn(array $row): float => (float)$row['passed'], $history)) * 100,
        'attempt_count_log' => log(1 + count($history)),
        'avg_time_minutes' => $times ? min(60, advancedMean($times) / 60) : 0,
        'mastery' => (float)$student['mastery'],
        'topic_completion' => (float)$student['topic_completion'],
        'login_count_log' => log(1 + (int)$student['login_count']),
        'current_streak' => min(30, (int)$student['current_streak']),
    ];
}

$students = dbRows(
    "SELECT s.id,s.login_count,s.current_streak,
            COALESCE((SELECT AVG(slp.mastery_level)*100 FROM student_learning_profiles slp WHERE slp.student_id=s.id),0) mastery,
            COALESCE((SELECT SUM(COALESCE(tp.completion_percent,0)) / NULLIF(COUNT(t.id),0)
                      FROM topics t
                      LEFT JOIN topic_progress tp ON tp.topic_id=t.id AND tp.student_id=s.id
                      WHERE t.class_level=s.class_level AND t.approval_status='approved' AND t.is_active=1
                        AND (t.school_id IS NULL OR t.school_id=s.school_id)),0) topic_completion
     FROM students s WHERE s.is_active=1"
);
$profileSamples = [];
$banditEvents = [];
$predictionSamples = [];

foreach ($students as $student) {
    $attempts = dbRows(
        "SELECT qa.score,qa.passed,qa.time_taken_seconds,qa.completed_at,q.topic_id,
                t.subject_id,t.difficulty,t.sequence_order
         FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id JOIN topics t ON t.id=q.topic_id
         WHERE qa.student_id=? AND qa.completed_at IS NOT NULL ORDER BY qa.completed_at,qa.id",
        [(int)$student['id']]
    );
    for ($cut = 1; $cut < count($attempts); $cut++) {
        $history = array_slice($attempts, 0, $cut);
        $next = $attempts[$cut];
        $features = temporalFeatures($history, $student);
        $previousScore = (float)$history[count($history) - 1]['score'];
        $improvement = ((float)$next['score'] - $previousScore) / 100;
        $reward = max(0, min(1.5, ((float)$next['score'] / 100) + ($improvement * 0.25) + ((int)$next['passed'] ? 0.15 : 0)));
        $profileSamples[] = ['student_id' => (int)$student['id'], 'features' => $features];
        $banditEvents[] = [
            'student_id' => (int)$student['id'],
            'context' => $features,
            'action_topic_id' => (int)$next['topic_id'],
            'action_subject_id' => (int)$next['subject_id'],
            'action_difficulty' => $next['difficulty'],
            'action_sequence_order' => (int)$next['sequence_order'],
            'reward' => $reward,
        ];
    }
    for ($cut = 3; $cut < count($attempts); $cut++) {
        $future = array_slice($attempts, $cut, min(3, count($attempts) - $cut));
        if (!$future) continue;
        $predictionSamples[] = [
            'student_id' => (int)$student['id'],
            'features' => temporalFeatures(array_slice($attempts, 0, $cut), $student),
            'target_score' => advancedMean(array_column($future, 'score')),
            'target_source' => 'future_assessment_proxy',
        ];
    }
    $verified = dbValue('SELECT AVG(score) FROM final_exam_results WHERE student_id=?', [(int)$student['id']]);
    if ($verified !== false && $verified !== null && count($attempts) >= 3) {
        $predictionSamples[] = [
            'student_id' => (int)$student['id'],
            'features' => temporalFeatures($attempts, $student),
            'target_score' => (float)$verified,
            'target_source' => 'verified_final_exam',
        ];
    }
}

$topics = dbRows(
    "SELECT t.id,t.subject_id,t.class_level,t.difficulty,t.sequence_order,
            COALESCE((SELECT AVG(slp.mastery_level) FROM student_learning_profiles slp WHERE slp.topic_id=t.id),0) global_mastery
     FROM topics t WHERE t.approval_status='approved' AND t.is_active=1"
);
$payload = [
    'generated_at' => date(DATE_ATOM),
    'feature_names' => array_keys($profileSamples[0]['features'] ?? []),
    'profile_samples' => $profileSamples,
    'bandit_events' => $banditEvents,
    'prediction_samples' => $predictionSamples,
    'topic_catalog' => $topics,
    'limitations' => [
        'exam_labels' => (int)dbValue('SELECT COUNT(*) FROM final_exam_results'),
        'audio_labels' => 0,
    ],
];
$target = __DIR__ . '/advanced_training_data.json';
file_put_contents($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf(
    "Exported %d profile samples, %d bandit events, and %d prediction samples.\n",
    count($profileSamples), count($banditEvents), count($predictionSamples)
);
