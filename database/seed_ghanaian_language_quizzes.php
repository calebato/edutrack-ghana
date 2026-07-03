<?php
require_once __DIR__ . '/../config/db.php';

$teacher = dbRow("SELECT id FROM teachers WHERE subject='Ghanaian Language' ORDER BY id LIMIT 1")
    ?: dbRow("SELECT id FROM teachers WHERE subject='General' ORDER BY id LIMIT 1")
    ?: dbRow("SELECT id FROM teachers ORDER BY id LIMIT 1");

if (!$teacher) {
    exit("No teacher account found. Create a teacher before seeding quizzes.\n");
}

$teacherId = (int)$teacher['id'];
$topics = dbRows(
    "SELECT t.id,t.title,t.class_level
     FROM topics t
     JOIN subjects s ON s.id=t.subject_id
     WHERE s.name='Ghanaian Language' AND t.approval_status='approved' AND t.is_active=1
     ORDER BY FIELD(t.class_level,'JHS1','JHS2','JHS3'),t.sequence_order,t.id"
);

if (!$topics) {
    exit("No approved Ghanaian Language topics found.\n");
}

function ghanaianLanguageQuestions(string $title, string $level): array {
    $topic = preg_replace('/\s+/', ' ', trim($title));

    return [
        [
            "Which option best describes the Ghanaian Language topic \"$topic\"?",
            "A topic for $level Ghanaian Language learning",
            'A Mathematics formula',
            'A Science experiment',
            'A foreign map activity',
            'A',
            "The topic belongs to Ghanaian Language at $level level.",
            'easy',
            'understand',
        ],
        [
            "At which level is \"$topic\" studied?",
            $level,
            'Primary 1',
            'Senior High School',
            'University',
            'A',
            "The class level for this topic is $level.",
            'easy',
            'remember',
        ],
        [
            'Which skill is most important when learning a Ghanaian language?',
            'Reading, speaking, listening, and writing regularly',
            'Avoiding all oral practice',
            'Guessing without reading',
            'Ignoring cultural examples',
            'A',
            'Language learning improves through regular use and practice.',
            'easy',
            'understand',
        ],
        [
            'Which activity helps a learner understand vocabulary better?',
            'Using new words in short meaningful sentences',
            'Copying words without meaning',
            'Skipping difficult words',
            'Choosing random answers',
            'A',
            'Vocabulary is understood better when used in context.',
            'medium',
            'apply',
        ],
        [
            'Which activity shows good oral language practice?',
            'Listening carefully and responding clearly',
            'Speaking without listening',
            'Reading silently only',
            'Avoiding conversation',
            'A',
            'Oral language requires both listening and speaking.',
            'medium',
            'apply',
        ],
        [
            'Which activity shows analysis in language learning?',
            'Comparing two sentences and explaining the difference in meaning',
            'Memorising one word only',
            'Skipping the passage',
            'Writing unrelated numbers',
            'A',
            'Analysis involves comparing language parts and explaining relationships.',
            'medium',
            'analyze',
        ],
        [
            'Which revision plan is most effective?',
            'Review vocabulary, practise reading, correct errors, and speak regularly',
            'Read the title once only',
            'Avoid feedback',
            'Study only unrelated topics',
            'A',
            'Effective revision combines practice, feedback, and correction.',
            'hard',
            'evaluate',
        ],
        [
            'Which evidence best shows understanding of a language topic?',
            'Using the idea correctly in speech or writing',
            'Copying without knowing the meaning',
            'Leaving all questions blank',
            'Selecting the longest answer',
            'A',
            'Understanding is shown by meaningful use.',
            'hard',
            'evaluate',
        ],
        [
            'What should a learner do after making an error?',
            'Read the feedback and practise a similar example',
            'Ignore the correction',
            'Stop learning the topic',
            'Delete the lesson',
            'A',
            'Feedback helps learners improve language accuracy.',
            'medium',
            'apply',
        ],
        [
            'Why are quizzes useful in Ghanaian Language learning?',
            'They check understanding and guide revision',
            'They replace all speaking practice',
            'They remove the need to read',
            'They are only for decoration',
            'A',
            'Quizzes help identify strengths and weak areas.',
            'easy',
            'understand',
        ],
    ];
}

$created = 0;
$skipped = 0;

foreach ($topics as $topic) {
    $existing = (int)dbValue('SELECT COUNT(*) FROM quizzes WHERE topic_id=?', [(int)$topic['id']]);
    if ($existing > 0) {
        $skipped++;
        continue;
    }

    $quizId = dbInsert(
        'INSERT INTO quizzes (teacher_id,topic_id,title,description,time_limit_minutes,pass_score,max_attempts,is_active) VALUES (?,?,?,?,?,?,?,1)',
        [
            $teacherId,
            (int)$topic['id'],
            $topic['class_level'] . ' Ghanaian Language Practice - ' . $topic['title'],
            'Practice quiz for ' . $topic['title'] . ' in Ghanaian Language.',
            15,
            60,
            3,
        ]
    );

    foreach (ghanaianLanguageQuestions((string)$topic['title'], (string)$topic['class_level']) as $question) {
        dbInsert(
            'INSERT INTO questions (quiz_id,question_text,option_a,option_b,option_c,option_d,correct_answer,explanation,points,difficulty,bloom_level) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $quizId,
                $question[0],
                $question[1],
                $question[2],
                $question[3],
                $question[4],
                $question[5],
                $question[6],
                10,
                $question[7],
                $question[8],
            ]
        );
    }

    $created++;
}

echo "Ghanaian Language quiz seed complete. Created {$created} quizzes, skipped {$skipped} topics with existing quizzes. Teacher ID: {$teacherId}\n";
