<?php
require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

function mean(array $values): float {
    return $values ? array_sum($values) / count($values) : 0.0;
}

function sampleFeatures(array $attempts, array $student): array {
    $scores = array_map(static fn(array $row): float => (float)$row['score'], $attempts);
    $recent = array_slice($scores, -3);
    $older = array_slice($scores, max(0, count($scores) - 6), 3);
    $times = array_values(array_filter(array_map(
        static fn(array $row): float => (float)$row['time_taken_seconds'],
        $attempts
    ), static fn(float $time): bool => $time > 0 && $time < 7200));

    return [
        'avg_score' => mean($scores),
        'recent_avg' => mean($recent),
        'trend' => mean($recent) - mean($older ?: $recent),
        'pass_rate' => mean(array_map(static fn(array $row): float => (float)$row['passed'], $attempts)) * 100,
        'attempt_count_log' => log(1 + count($attempts)),
        'avg_time_minutes' => $times ? min(60, mean($times) / 60) : 0,
        'mastery' => (float)$student['mastery'],
        'topic_completion' => (float)$student['topic_completion'],
        'login_count_log' => log(1 + (int)$student['login_count']),
        'current_streak' => min(30, (int)$student['current_streak']),
    ];
}

$students = dbRows(
    "SELECT s.id,s.login_count,s.current_streak,
            COALESCE((SELECT AVG(slp.mastery_level) * 100 FROM student_learning_profiles slp WHERE slp.student_id=s.id),0) AS mastery,
            COALESCE((SELECT SUM(COALESCE(tp.completion_percent,0)) / NULLIF(COUNT(t.id),0)
                      FROM topics t
                      LEFT JOIN topic_progress tp ON tp.topic_id=t.id AND tp.student_id=s.id
                      WHERE t.class_level=s.class_level AND t.approval_status='approved' AND t.is_active=1
                        AND (t.school_id IS NULL OR t.school_id=s.school_id)),0) AS topic_completion
     FROM students s WHERE s.is_active=1"
);

$samples = [];
foreach ($students as $student) {
    $attempts = dbRows(
        "SELECT score,passed,time_taken_seconds,completed_at
         FROM quiz_attempts
         WHERE student_id=? AND completed_at IS NOT NULL
         ORDER BY completed_at,id",
        [(int)$student['id']]
    );
    $count = count($attempts);
    $verifiedExamScore = dbValue(
        'SELECT AVG(score) FROM final_exam_results WHERE student_id=?',
        [(int)$student['id']]
    );
    if ($verifiedExamScore !== false && $verifiedExamScore !== null && $count >= 3) {
        $samples[] = [
            'student_id' => (int)$student['id'],
            'features' => sampleFeatures($attempts, $student),
            'target' => (float)$verifiedExamScore,
            'target_source' => 'verified_final_exam',
        ];
    }
    for ($cut = 3; $cut < $count; $cut++) {
        $future = array_slice($attempts, $cut, min(3, $count - $cut));
        if (!$future) continue;
        $samples[] = [
            'student_id' => (int)$student['id'],
            'features' => sampleFeatures(array_slice($attempts, 0, $cut), $student),
            'target' => mean(array_map(static fn(array $row): float => (float)$row['score'], $future)),
            'target_source' => 'future_assessment_proxy',
        ];
    }
}

$payload = [
    'generated_at' => date(DATE_ATOM),
    'target' => 'future_assessed_performance',
    'samples' => $samples,
];
$target = __DIR__ . '/training_data.json';
file_put_contents($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'Exported ' . count($samples) . " training samples to $target\n";
