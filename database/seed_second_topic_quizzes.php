<?php
require_once __DIR__ . '/../config/db.php';

$teacher = dbRow("SELECT id FROM teachers WHERE subject='General' ORDER BY id LIMIT 1")
    ?: dbRow("SELECT id FROM teachers ORDER BY id LIMIT 1");

if (!$teacher) {
    exit("No teacher account found. Create a teacher before seeding quizzes.\n");
}

$teacherId = (int)$teacher['id'];
$topics = dbRows(
    "SELECT t.id,t.title,t.class_level,s.name AS subject_name
     FROM topics t
     JOIN subjects s ON s.id=t.subject_id
     WHERE t.approval_status='approved' AND t.is_active=1
     ORDER BY s.id,FIELD(t.class_level,'JHS1','JHS2','JHS3'),t.sequence_order,t.id"
);

function tidyTopic(string $value): string {
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    return preg_replace('/\s+/', ' ', trim($value));
}

function subjectContext(string $subject): array {
    return match ($subject) {
        'Mathematics' => ['working step-by-step', 'checking calculations', 'solving a word problem', 'explaining the method'],
        'English Language' => ['reading the passage', 'choosing correct grammar', 'organising ideas', 'explaining word meaning'],
        'Integrated Science' => ['observing evidence', 'testing an idea', 'recording results', 'explaining a process'],
        'Social Studies' => ['using examples from Ghana', 'explaining civic responsibility', 'relating ideas to community life', 'judging a social issue'],
        'ICT' => ['using a digital tool', 'following safe computer practice', 'solving a practical task', 'explaining a technology concept'],
        'French' => ['using vocabulary in a sentence', 'answering a short conversation', 'matching meaning to context', 'checking pronunciation'],
        'Religious & Moral Education' => ['explaining a value', 'choosing a responsible action', 'respecting others', 'applying moral judgement'],
        'Ghanaian Language' => ['using vocabulary in context', 'speaking clearly', 'reading for meaning', 'connecting language to culture'],
        default => ['using the idea', 'checking understanding', 'practising examples', 'explaining the answer'],
    };
}

function secondQuizQuestions(string $subject, string $topic, string $level): array {
    $topic = tidyTopic($topic);
    [$skillOne, $skillTwo, $skillThree, $skillFour] = subjectContext($subject);

    return [
        [
            "A learner has just studied \"$topic\". Which next activity would best strengthen understanding?",
            "Practise a new example and explain the answer",
            "Close the lesson without practice",
            "Pick options randomly",
            "Avoid the topic completely",
            'A',
            'Understanding improves when a learner practises and explains the answer.',
            'easy',
            'understand',
        ],
        [
            "Which classroom action best matches $subject learning on \"$topic\"?",
            ucfirst($skillOne),
            'Ignoring all examples',
            'Writing unrelated notes',
            'Skipping teacher feedback',
            'A',
            "The action is directly related to learning $subject.",
            'easy',
            'remember',
        ],
        [
            "Why should learners receive feedback after a quiz on \"$topic\"?",
            "To know strengths, weak areas, and what to revise",
            "To stop learning the topic",
            "To hide mistakes",
            "To remove practice from the lesson",
            'A',
            'Feedback guides revision and helps learners improve.',
            'easy',
            'understand',
        ],
        [
            "Which answer shows that a learner can apply \"$topic\"?",
            "The learner uses the idea correctly in a new task",
            "The learner only repeats the title",
            "The learner refuses to attempt examples",
            "The learner chooses the longest option",
            'A',
            'Application means using knowledge correctly in a new situation.',
            'medium',
            'apply',
        ],
        [
            "A student scores low in \"$topic\". What should EduTrack Ghana recommend first?",
            "Review the lesson and try another guided quiz",
            "Move to an unrelated subject",
            "Ignore the score",
            "Delete the progress record",
            'A',
            'A weak score should lead to revision and another guided attempt.',
            'medium',
            'apply',
        ],
        [
            "Which task requires deeper thinking about \"$topic\"?",
            "Compare two examples and explain the difference",
            "Copy the title only",
            "Guess all answers quickly",
            "Avoid reading the question",
            'A',
            'Comparing examples and explaining differences requires analysis.',
            'medium',
            'analyze',
        ],
        [
            "Which learner behaviour is best during a quiz on \"$topic\"?",
            "Read each question carefully before choosing an answer",
            "Answer without reading",
            "Select only option A every time",
            "Stop at the first difficult question",
            'A',
            'Careful reading improves accuracy and shows responsible learning.',
            'medium',
            'apply',
        ],
        [
            "Which statement best evaluates progress in \"$topic\"?",
            "The learner improved after reviewing mistakes and practising again",
            "The learner never checked feedback",
            "The learner avoided all questions",
            "The learner guessed faster than before",
            'A',
            'Improvement after feedback is valid evidence of progress.',
            'hard',
            'evaluate',
        ],
        [
            "Which evidence would a teacher use to support intervention in \"$topic\"?",
            "Quiz scores, attempts, feedback history, and progress records",
            "Only the learner's seat position",
            "A random comment from another learner",
            "No learning data at all",
            'A',
            'Teacher intervention should be guided by learning data.',
            'hard',
            'evaluate',
        ],
        [
            "Which revision strategy is most suitable for \"$topic\" in $level?",
            ucfirst($skillTwo) . ', practise examples, and explain corrections',
            'Avoid the topic until exams',
            'Study without checking mistakes',
            'Memorise only option letters',
            'A',
            'Good revision combines practice, correction, and explanation.',
            'hard',
            'create',
        ],
    ];
}

$created = 0;
$skipped = 0;

foreach ($topics as $topic) {
    $topicId = (int)$topic['id'];
    $activeCount = (int)dbValue('SELECT COUNT(*) FROM quizzes WHERE topic_id=? AND is_active=1', [$topicId]);

    if ($activeCount >= 2) {
        $skipped++;
        continue;
    }

    $subject = (string)$topic['subject_name'];
    $title = tidyTopic((string)$topic['title']);
    $level = (string)$topic['class_level'];
    $quizId = dbInsert(
        'INSERT INTO quizzes (teacher_id,topic_id,title,description,time_limit_minutes,pass_score,max_attempts,is_active) VALUES (?,?,?,?,?,?,?,1)',
        [
            $teacherId,
            $topicId,
            "$level $subject Revision Challenge - $title",
            "Second practice quiz for $title in $subject with different revision questions.",
            15,
            60,
            3,
        ]
    );

    foreach (secondQuizQuestions($subject, $title, $level) as $question) {
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

echo "Second quiz seed complete. Created {$created} second quizzes, skipped {$skipped} topics already having at least two active quizzes. Teacher ID: {$teacherId}\n";
