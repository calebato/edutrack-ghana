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

function cleanText(string $value): string {
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    return preg_replace('/\s+/', ' ', trim($value));
}

function subjectExamples(string $subject): array {
    return match ($subject) {
        'Mathematics' => ['number work', 'calculation steps', 'problem solving', 'checking the answer'],
        'English Language' => ['grammar', 'reading', 'writing', 'speaking clearly'],
        'Integrated Science' => ['observation', 'experiment', 'evidence', 'scientific explanation'],
        'Social Studies' => ['citizenship', 'community life', 'Ghanaian society', 'responsible decision making'],
        'ICT' => ['digital tools', 'computer use', 'online safety', 'practical task'],
        'French' => ['vocabulary', 'simple conversation', 'pronunciation', 'sentence practice'],
        'Religious & Moral Education' => ['values', 'responsibility', 'respect', 'moral decision making'],
        'Ghanaian Language' => ['vocabulary', 'oral practice', 'reading', 'cultural expression'],
        default => ['key idea', 'example', 'practice', 'feedback'],
    };
}

function makeQuestionSet(string $subject, string $topic, string $level): array {
    $topic = cleanText($topic);
    [$exampleOne, $exampleTwo, $exampleThree, $exampleFour] = subjectExamples($subject);
    $label = "$level $subject";

    return [
        [
            "In $label, what is the main purpose of studying \"$topic\"?",
            "To build understanding of $topic through $exampleOne and practice",
            "To avoid all class activities",
            "To replace every other subject",
            "To memorise answers without meaning",
            'A',
            "The topic is studied to build useful understanding and skills in $subject.",
            'easy',
            'understand',
        ],
        [
            "Which subject area does \"$topic\" belong to?",
            $subject,
            'Mathematics only',
            'Integrated Science only',
            'Social Studies only',
            'A',
            "The topic is listed under $subject.",
            'easy',
            'remember',
        ],
        [
            "Which level is linked to \"$topic\" in EduTrack Ghana?",
            $level,
            'Primary 1',
            'Senior High School',
            'University',
            'A',
            "The topic is prepared for $level learners.",
            'easy',
            'remember',
        ],
        [
            "Which activity best helps a learner understand \"$topic\"?",
            "Study the explanation, practise examples, and check feedback",
            "Skip the lesson and guess",
            "Copy only the title",
            "Ignore all corrections",
            'A',
            'Understanding improves through explanation, practice, and feedback.',
            'medium',
            'apply',
        ],
        [
            "A learner is weak in \"$topic\". What should the learner do first?",
            "Review the key idea and practise a simpler example",
            "Stop studying the subject",
            "Choose answers at random",
            "Delete the quiz",
            'A',
            'Starting with the key idea and a simpler example supports recovery.',
            'medium',
            'apply',
        ],
        [
            "Which task shows application of \"$topic\"?",
            "Using the idea in a new classroom or real-life task",
            "Only saying the topic title",
            "Avoiding all examples",
            "Selecting the longest option",
            'A',
            'Application means using knowledge in a new situation.',
            'medium',
            'apply',
        ],
        [
            "Which task shows analysis of \"$topic\"?",
            "Comparing examples and explaining why the answers or meanings differ",
            "Memorising one word only",
            "Skipping the lesson summary",
            "Writing unrelated notes",
            'A',
            'Analysis requires comparing parts and explaining relationships.',
            'medium',
            'analyze',
        ],
        [
            "Which evidence best proves understanding of \"$topic\"?",
            "A correct answer with a clear reason or example",
            "A blank response",
            "A copied answer with no meaning",
            "A random guess",
            'A',
            'Good evidence includes both correctness and explanation.',
            'hard',
            'evaluate',
        ],
        [
            "Which revision plan is strongest for \"$topic\"?",
            "Review notes, practise varied questions, correct mistakes, and retry",
            "Read the title once",
            "Avoid difficult examples",
            "Ignore teacher feedback",
            'A',
            'A strong revision plan uses practice, correction, and repetition.',
            'hard',
            'evaluate',
        ],
        [
            "How can EduTrack Ghana help with \"$topic\"?",
            "It provides quizzes, progress tracking, and feedback for revision",
            "It removes the need to learn",
            "It hides weak areas from learners",
            "It prevents teachers from monitoring progress",
            'A',
            'EduTrack uses quizzes and progress data to guide revision.',
            'easy',
            'understand',
        ],
    ];
}

function replaceQuestions(int $quizId, array $questions): void {
    dbQuery('DELETE FROM questions WHERE quiz_id=?', [$quizId]);
    foreach ($questions as $question) {
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
}

$created = 0;
$refreshed = 0;
$keptWithAttempts = 0;

foreach ($topics as $topic) {
    $topicId = (int)$topic['id'];
    $subject = (string)$topic['subject_name'];
    $title = (string)$topic['title'];
    $level = (string)$topic['class_level'];
    $questions = makeQuestionSet($subject, $title, $level);

    $quiz = dbRow(
        "SELECT q.id,
                (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id=q.id) AS attempts
         FROM quizzes q
         WHERE q.topic_id=?
         ORDER BY q.is_active DESC,q.id ASC
         LIMIT 1",
        [$topicId]
    );

    if (!$quiz) {
        $quizId = dbInsert(
            'INSERT INTO quizzes (teacher_id,topic_id,title,description,time_limit_minutes,pass_score,max_attempts,is_active) VALUES (?,?,?,?,?,?,?,1)',
            [
                $teacherId,
                $topicId,
                "$level $subject Practice - $title",
                "Practice quiz for $title in $subject.",
                15,
                60,
                3,
            ]
        );
        replaceQuestions($quizId, $questions);
        $created++;
        continue;
    }

    $quizId = (int)$quiz['id'];
    if ((int)$quiz['attempts'] > 0) {
        dbQuery('UPDATE quizzes SET is_active=1 WHERE id=?', [$quizId]);
        $keptWithAttempts++;
        continue;
    }

    dbQuery(
        'UPDATE quizzes SET teacher_id=?,title=?,description=?,time_limit_minutes=15,pass_score=60,max_attempts=3,is_active=1 WHERE id=?',
        [$teacherId, "$level $subject Practice - $title", "Practice quiz for $title in $subject.", $quizId]
    );
    replaceQuestions($quizId, $questions);
    $refreshed++;
}

echo "All-subject quiz seed complete. Created {$created}, refreshed {$refreshed}, kept with attempts {$keptWithAttempts}. Teacher ID: {$teacherId}\n";
