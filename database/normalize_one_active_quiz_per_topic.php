<?php
require_once __DIR__ . '/../config/db.php';

$topics = dbRows(
    "SELECT t.id
     FROM topics t
     WHERE t.approval_status='approved' AND t.is_active=1
     ORDER BY t.id"
);

$deactivated = 0;

foreach ($topics as $topic) {
    $quizzes = dbRows(
        "SELECT q.id,
                (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id=q.id) AS attempts,
                (SELECT COUNT(*) FROM questions qu WHERE qu.quiz_id=q.id) AS questions
         FROM quizzes q
         WHERE q.topic_id=? AND q.is_active=1
         ORDER BY attempts DESC, questions DESC, q.id ASC",
        [(int)$topic['id']]
    );

    if (count($quizzes) <= 1) {
        continue;
    }

    $keepId = (int)$quizzes[0]['id'];
    foreach (array_slice($quizzes, 1) as $quiz) {
        dbQuery('UPDATE quizzes SET is_active=0 WHERE id=?', [(int)$quiz['id']]);
        $deactivated++;
    }
}

echo "Normalization complete. Deactivated {$deactivated} duplicate active quizzes. Existing attempts were not deleted.\n";
