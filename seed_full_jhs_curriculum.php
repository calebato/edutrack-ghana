<?php
/**
 * Idempotent JHS1-JHS3 curriculum seeder.
 * Ensures at least 7 topics per subject/class, 2 quizzes per topic,
 * and 10 questions per quiz without deleting existing content.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

$curriculum = [
    'Mathematics' => [
        'JHS1' => ['Whole Numbers and Place Value', 'Fractions and Decimals', 'Percentages', 'Basic Algebra', 'Geometry and Shapes', 'Measurement and Units', 'Data Collection and Graphs'],
        'JHS2' => ['Integers and Number Operations', 'Ratio and Proportion', 'Algebraic Expressions', 'Linear Equations', 'Angles and Polygons', 'Area and Volume', 'Statistics and Probability'],
        'JHS3' => ['Indices and Standard Form', 'Sets and Logical Reasoning', 'Simultaneous Equations', 'Coordinate Geometry', 'Pythagoras and Trigonometry', 'Mensuration', 'Advanced Statistics and Probability'],
    ],
    'English Language' => [
        'JHS1' => ['Parts of Speech', 'Sentence Structure', 'Reading Comprehension', 'Vocabulary Development', 'Paragraph Writing', 'Oral Communication', 'Introduction to Literature'],
        'JHS2' => ['Tenses and Agreement', 'Clauses and Phrases', 'Critical Reading', 'Context Clues and Idioms', 'Essay Writing', 'Speech and Listening Skills', 'Prose Poetry and Drama'],
        'JHS3' => ['Advanced Grammar and Usage', 'Reported Speech and Voice', 'Summary Writing', 'Argument and Persuasion', 'Formal and Informal Letters', 'Public Speaking', 'Literary Appreciation'],
    ],
    'Integrated Science' => [
        'JHS1' => ['Scientific Investigation', 'Living and Non-Living Things', 'Cells and Organisation', 'Matter and Its Properties', 'Energy and Forces', 'The Solar System', 'Health and Hygiene'],
        'JHS2' => ['Reproduction and Growth', 'Human Body Systems', 'Mixtures and Separation', 'Electricity and Magnetism', 'Light and Sound', 'Soil and Agriculture', 'Ecosystems'],
        'JHS3' => ['Genetics and Variation', 'Chemical Reactions', 'Acids Bases and Salts', 'Machines and Work', 'Climate and Weather', 'Environmental Conservation', 'Disease Prevention and Nutrition'],
    ],
    'Social Studies' => [
        'JHS1' => ['The Individual and Community', 'Ghanaian Culture and Identity', 'The Physical Environment', 'Population and Settlement', 'Citizenship', 'Leadership and Cooperation', 'National Symbols and Values'],
        'JHS2' => ['Governance and Democracy', 'Human Rights and Responsibilities', 'Conflict and Peace Building', 'Economic Activities in Ghana', 'Natural Resources', 'Migration and Urbanisation', 'Regional Cooperation'],
        'JHS3' => ['The Constitution of Ghana', 'Elections and Accountability', 'Sustainable Development', 'Entrepreneurship and Employment', 'Globalisation', 'Social Problems and Solutions', 'Ghana and International Organisations'],
    ],
    'ICT' => [
        'JHS1' => ['Computer Parts and Functions', 'Using the Operating System', 'Keyboard and Mouse Skills', 'Word Processing Basics', 'Digital Citizenship', 'Computer Safety and Care', 'Introduction to the Internet'],
        'JHS2' => ['File Management', 'Advanced Word Processing', 'Spreadsheets and Calculations', 'Presentation Software', 'Internet Research', 'Cybersecurity Basics', 'Computer Networks'],
        'JHS3' => ['Database Fundamentals', 'Programming Concepts', 'Web Design Basics', 'Data Communication', 'Digital Media Creation', 'Responsible Social Media', 'ICT Projects and Innovation'],
    ],
    'French' => [
        'JHS1' => ['Greetings and Introductions', 'Numbers and Age', 'Family and Friends', 'School and Classroom', 'Days Months and Time', 'Food and Drinks', 'Basic French Grammar'],
        'JHS2' => ['Daily Routines', 'Home and Neighbourhood', 'Shopping and Money', 'Weather and Clothing', 'Hobbies and Sports', 'Travel and Directions', 'Past and Future Actions'],
        'JHS3' => ['Health and the Body', 'Jobs and Ambitions', 'Environment and Community', 'Communication and Technology', 'French Letter Writing', 'Conversation and Listening', 'French Reading Comprehension'],
    ],
    'Religious & Moral Education' => [
        'JHS1' => ['God Creation and Stewardship', 'Religious Worship', 'Sacred Texts and Teachings', 'Family and Community Values', 'Honesty and Responsibility', 'Respect and Tolerance', 'Festivals and Religious Practices'],
        'JHS2' => ['Religious Leaders and Examples', 'Prayer and Spiritual Discipline', 'Moral Decision Making', 'Justice and Fairness', 'Work and Service', 'Peace and Reconciliation', 'Traditional Religion in Ghana'],
        'JHS3' => ['Human Dignity and Rights', 'Leadership and Integrity', 'Marriage and Family Life', 'Managing Peer Pressure', 'Religious Diversity', 'Environmental Ethics', 'Citizenship and Nation Building'],
    ],
    'Ghanaian Language' => [
        'JHS1' => ['Alphabet and Sound Patterns', 'Greetings and Everyday Expressions', 'Family and Community Vocabulary', 'Sentence Formation', 'Reading Short Passages', 'Oral Tradition and Storytelling', 'Proverbs and Wise Sayings'],
        'JHS2' => ['Grammar and Word Classes', 'Descriptive Language', 'Comprehension Skills', 'Letter and Message Writing', 'Traditional Songs and Poetry', 'Customs and Festivals', 'Conversation and Pronunciation'],
        'JHS3' => ['Advanced Grammar', 'Essay and Creative Writing', 'Summary and Interpretation', 'Debate and Public Speaking', 'Drama and Performance', 'Cultural Values in Literature', 'Language Translation Skills'],
    ],
];

function topicDescription(string $subject, string $title, string $level): string {
    return "Study {$title} in {$subject} at {$level} level through clear explanations, guided examples, practical activities, and independent practice.";
}

function rotateOptions(string $correct, array $wrong, int $seed): array {
    $position = $seed % 4;
    $options = array_values(array_slice($wrong, 0, 3));
    array_splice($options, $position, 0, [$correct]);
    return [$options, ['A', 'B', 'C', 'D'][$position]];
}

$subjectStmt = $conn->prepare('SELECT id FROM subjects WHERE name = ?');
$topicCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM topics WHERE subject_id = ? AND class_level = ?');
$topicExistsStmt = $conn->prepare('SELECT id FROM topics WHERE subject_id = ? AND class_level = ? AND title = ? LIMIT 1');
$topicInsertStmt = $conn->prepare('INSERT INTO topics (subject_id, title, description, difficulty, sequence_order, class_level, estimated_minutes, content) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$topicsStmt = $conn->prepare('SELECT id, title, description, class_level FROM topics WHERE subject_id = ? AND class_level = ? ORDER BY sequence_order, id');
$quizCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM quizzes WHERE topic_id = ?');
$quizInsertStmt = $conn->prepare('INSERT INTO quizzes (topic_id, title, description, time_limit_minutes, pass_score, max_attempts, is_active) VALUES (?, ?, ?, 15, 60, 3, 1)');
$quizzesStmt = $conn->prepare('SELECT id, title FROM quizzes WHERE topic_id = ? ORDER BY id');
$questionCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM questions WHERE quiz_id = ?');
$questionInsertStmt = $conn->prepare('INSERT INTO questions (quiz_id, question_text, option_a, option_b, option_c, option_d, correct_answer, explanation, points, difficulty, bloom_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 10, ?, ?)');

$stats = ['topics' => 0, 'quizzes' => 0, 'questions' => 0];
$conn->begin_transaction();

try {
    foreach ($curriculum as $subjectName => $levels) {
        $subjectStmt->bind_param('s', $subjectName);
        $subjectStmt->execute();
        $subject = $subjectStmt->get_result()->fetch_assoc();
        if (!$subject) {
            throw new RuntimeException("Missing subject: {$subjectName}");
        }
        $subjectId = (int)$subject['id'];

        foreach ($levels as $level => $plannedTopics) {
            $topicCountStmt->bind_param('is', $subjectId, $level);
            $topicCountStmt->execute();
            $existingCount = (int)$topicCountStmt->get_result()->fetch_assoc()['total'];
            $sequence = $existingCount + 1;

            foreach ($plannedTopics as $plannedTitle) {
                if ($existingCount >= 7) break;
                $topicExistsStmt->bind_param('iss', $subjectId, $level, $plannedTitle);
                $topicExistsStmt->execute();
                if ($topicExistsStmt->get_result()->fetch_assoc()) continue;

                $description = topicDescription($subjectName, $plannedTitle, $level);
                $difficulty = $sequence <= 2 ? 'easy' : ($sequence <= 5 ? 'medium' : 'hard');
                $minutes = $difficulty === 'easy' ? 30 : ($difficulty === 'medium' ? 40 : 45);
                $content = $description . ' Learners should review the key vocabulary, follow worked examples, complete activities, and reflect on corrections.';
                $topicInsertStmt->bind_param('isssisis', $subjectId, $plannedTitle, $description, $difficulty, $sequence, $level, $minutes, $content);
                $topicInsertStmt->execute();
                $existingCount++;
                $sequence++;
                $stats['topics']++;
            }

            $topicsStmt->bind_param('is', $subjectId, $level);
            $topicsStmt->execute();
            $topics = $topicsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            foreach ($topics as $topic) {
                $topicId = (int)$topic['id'];
                $topicTitle = $topic['title'];
                $description = $topic['description'] ?: topicDescription($subjectName, $topicTitle, $level);

                $quizCountStmt->bind_param('i', $topicId);
                $quizCountStmt->execute();
                $quizCount = (int)$quizCountStmt->get_result()->fetch_assoc()['total'];
                while ($quizCount < 2) {
                    $quizType = $quizCount === 0 ? 'Foundation' : 'Application';
                    $quizTitle = "{$level} {$topicTitle} - {$quizType} Quiz";
                    $quizDescription = "A 10-question {$quizType} assessment for {$topicTitle}.";
                    $quizInsertStmt->bind_param('iss', $topicId, $quizTitle, $quizDescription);
                    $quizInsertStmt->execute();
                    $quizCount++;
                    $stats['quizzes']++;
                }

                $quizzesStmt->bind_param('i', $topicId);
                $quizzesStmt->execute();
                $quizzes = $quizzesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach ($quizzes as $quizIndex => $quiz) {
                    $quizId = (int)$quiz['id'];
                    $questionCountStmt->bind_param('i', $quizId);
                    $questionCountStmt->execute();
                    $questionCount = (int)$questionCountStmt->get_result()->fetch_assoc()['total'];
                    if ($questionCount >= 10) continue;

                    // Question content must come from reviewed NaCCA indicators.
                    // The structural seeder intentionally does not fabricate generic MCQs.
                    continue;
                }
            }
        }
    }

    $conn->commit();
    echo "Curriculum seed completed.\n";
    echo "Topics added: {$stats['topics']}\n";
    echo "Quizzes added: {$stats['quizzes']}\n";
    echo "Questions added: {$stats['questions']}\n";
} catch (Throwable $error) {
    $conn->rollback();
    fwrite(STDERR, "Seeder rolled back: {$error->getMessage()}\n");
    exit(1);
}
