<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/question_quality.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$questions = dbRows(
    "SELECT q.id,q.question_text,q.option_a,q.option_b,q.option_c,q.option_d,q.bloom_level,
            t.class_level,t.title AS topic_title,s.name AS subject_name
     FROM questions q
     JOIN quizzes z ON z.id=q.quiz_id
     JOIN topics t ON t.id=z.topic_id
     JOIN subjects s ON s.id=t.subject_id
     ORDER BY s.id,FIELD(t.class_level,'JHS1','JHS2','JHS3'),q.id"
);
$summary = [];
$flagged = 0;
foreach ($questions as $question) {
    $assessment = assessQuestionQuality(
        $question['question_text'],
        [$question['option_a'], $question['option_b'], $question['option_c'], $question['option_d']],
        $question['bloom_level'],
        $question['class_level'],
        $question['topic_title']
    );
    $key = $question['subject_name'] . ' / ' . $question['class_level'];
    $summary[$key] ??= ['total' => 0, 'blocking' => 0, 'warnings' => 0];
    $summary[$key]['total']++;
    if ($assessment['errors']) {
        $summary[$key]['blocking']++;
        $flagged++;
    }
    if ($assessment['warnings']) $summary[$key]['warnings']++;
}

printf("%-45s %7s %10s %10s\n", 'Subject / level', 'Total', 'Blocking', 'Warnings');
foreach ($summary as $label => $counts) {
    printf("%-45s %7d %10d %10d\n", $label, $counts['total'], $counts['blocking'], $counts['warnings']);
}
echo "\nQuestions requiring replacement: {$flagged} of " . count($questions) . "\n";
