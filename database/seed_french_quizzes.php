<?php
require_once __DIR__ . '/../config/db.php';

$teacher = dbRow("SELECT id FROM teachers WHERE subject='French' ORDER BY id LIMIT 1")
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
     WHERE s.name='French' AND t.approval_status='approved' AND t.is_active=1
     ORDER BY FIELD(t.class_level,'JHS1','JHS2','JHS3'),t.sequence_order,t.id"
);

if (!$topics) {
    exit("No approved French topics found.\n");
}

function frenchQuestionBank(string $title, string $level): array {
    $lower = mb_strtolower($title);

    if (str_contains($lower, 'salutation') || str_contains($lower, 'presentation')) {
        return [
            ['What does "Bonjour" mean in English?', 'Good morning/Hello', 'Good night', 'Thank you', 'Goodbye', 'A', 'Bonjour is used to greet someone during the day.', 'easy', 'remember'],
            ['Which phrase means "My name is Ama"?', 'Je suis douze ans', 'Je m appelle Ama', 'J habite a Accra', 'Je vais bien', 'B', 'Je m appelle means my name is.', 'easy', 'understand'],
            ['Which response fits "Comment ca va?"', 'Ca va bien', 'Je m appelle Kofi', 'Au revoir', 'Merci beaucoup', 'A', 'Ca va bien answers how are you.', 'easy', 'apply'],
            ['Which phrase is used when leaving?', 'Au revoir', 'Bonjour', 'Enchanté', 'Salut', 'A', 'Au revoir means goodbye.', 'easy', 'remember'],
            ['Choose the polite greeting for a teacher.', 'Bonjour Monsieur', 'Salut mon ami', 'A demain mon frere', 'Je joue au football', 'A', 'Bonjour Monsieur is polite and formal.', 'medium', 'apply'],
            ['What does "Enchanté" express?', 'Nice to meet you', 'I am hungry', 'I am late', 'Good evening', 'A', 'Enchanté is used when meeting someone.', 'medium', 'understand'],
            ['Which pair is a greeting and a farewell?', 'Bonjour / Au revoir', 'Merci / Livre', 'Pomme / Stylo', 'Rouge / Vert', 'A', 'Bonjour greets and Au revoir says goodbye.', 'medium', 'analyze'],
            ['Which sentence introduces a person?', 'Je m appelle Abena', 'Le stylo est bleu', 'Il fait chaud', 'J aime le riz', 'A', 'Je m appelle introduces a name.', 'medium', 'apply'],
            ['Which response is most appropriate after "Merci"?', 'De rien', 'Bonsoir', 'Je suis eleve', 'A bientot', 'A', 'De rien means you are welcome.', 'hard', 'evaluate'],
            ['Which mini-dialogue is correct?', 'Bonjour - Bonjour', 'Merci - Pardon?', 'Au revoir - Bonjour', 'Ca va? - Stylo', 'A', 'The same greeting can be returned politely.', 'hard', 'analyze'],
        ];
    }

    if (str_contains($lower, 'nombre') || str_contains($lower, 'age')) {
        return [
            ['What number is "un"?', '1', '2', '3', '10', 'A', 'Un means one.', 'easy', 'remember'],
            ['What number is "deux"?', '1', '2', '5', '12', 'B', 'Deux means two.', 'easy', 'remember'],
            ['Which phrase means "I am 12 years old"?', 'J ai douze ans', 'Je suis douze', 'Il y a douze', 'Douze merci', 'A', 'Age in French uses avoir: J ai ... ans.', 'easy', 'understand'],
            ['What is "dix" in English?', 'Ten', 'Six', 'Two', 'Twenty', 'A', 'Dix means ten.', 'easy', 'remember'],
            ['Choose the correct answer to "Quel age as-tu?"', 'J ai treize ans', 'Je vais a l ecole', 'Je mange du riz', 'Il est rouge', 'A', 'The question asks for age.', 'medium', 'apply'],
            ['Which number comes after "quatre"?', 'cinq', 'trois', 'deux', 'neuf', 'A', 'Cinq comes after quatre.', 'medium', 'understand'],
            ['Which pair matches correctly?', 'sept = 7', 'huit = 5', 'neuf = 2', 'six = 10', 'A', 'Sept means seven.', 'medium', 'analyze'],
            ['How do you say "14" in French?', 'quatorze', 'quatre', 'quarante', 'quinze', 'A', 'Quatorze means fourteen.', 'medium', 'remember'],
            ['Which sentence is grammatically best?', 'J ai onze ans', 'Je onze ans', 'Moi onze age', 'Age je onze', 'A', 'French age uses J ai plus number plus ans.', 'hard', 'evaluate'],
            ['A student says "Je suis 13 ans." What is the correction?', 'J ai treize ans', 'Je suis treize age', 'Moi treize ans', 'Il a treize', 'A', 'Avoir is used for age.', 'hard', 'analyze'],
        ];
    }

    if (str_contains($lower, 'famille') || str_contains($lower, 'amis')) {
        return [
            ['What does "mere" mean?', 'Mother', 'Father', 'Brother', 'Friend', 'A', 'Mere means mother.', 'easy', 'remember'],
            ['What does "pere" mean?', 'Father', 'Sister', 'Aunt', 'Teacher', 'A', 'Pere means father.', 'easy', 'remember'],
            ['Which word means friend?', 'ami', 'livre', 'table', 'chaise', 'A', 'Ami means friend.', 'easy', 'remember'],
            ['Choose the sentence meaning "This is my brother."', 'Voici mon frere', 'Voici ma mere', 'Voici mon ecole', 'Voici ma classe', 'A', 'Mon frere means my brother.', 'easy', 'understand'],
            ['Which word is feminine?', 'soeur', 'frere', 'pere', 'ami', 'A', 'Soeur means sister.', 'medium', 'analyze'],
            ['Which phrase means "my family"?', 'ma famille', 'mon stylo', 'mes devoirs', 'son livre', 'A', 'Famille is feminine, so ma is used.', 'medium', 'apply'],
            ['How do you say "my parents"?', 'mes parents', 'mon parents', 'ma parents', 'le parents', 'A', 'Plural nouns use mes for my.', 'medium', 'understand'],
            ['Which sentence describes a friend?', 'Mon ami est gentil', 'Le livre est rouge', 'Je vais au marche', 'Il fait froid', 'A', 'Gentil describes a person as kind.', 'medium', 'apply'],
            ['Which possessive is best before "soeur"?', 'ma', 'mon', 'mes', 'le', 'A', 'Soeur is singular feminine, so ma is used.', 'hard', 'evaluate'],
            ['Which pair correctly shows masculine and feminine?', 'frere / soeur', 'table / chaise', 'jour / mois', 'riz / eau', 'A', 'Frere and soeur are brother and sister.', 'hard', 'analyze'],
        ];
    }

    if (str_contains($lower, 'ecole') || str_contains($lower, 'classe') || str_contains($lower, '‚cole')) {
        return [
            ['What does "ecole" mean?', 'School', 'Market', 'House', 'Hospital', 'A', 'Ecole means school.', 'easy', 'remember'],
            ['What does "stylo" mean?', 'Pen', 'Book', 'Desk', 'Door', 'A', 'Stylo means pen.', 'easy', 'remember'],
            ['Which word means classroom?', 'salle de classe', 'maison', 'quartier', 'terrain', 'A', 'Salle de classe means classroom.', 'easy', 'understand'],
            ['Choose the school object.', 'cahier', 'pomme', 'chemise', 'ballon', 'A', 'Cahier means exercise book.', 'easy', 'remember'],
            ['Which phrase means "I am a student"?', 'Je suis eleve', 'Je suis professeur', 'Je suis malade', 'Je suis au marche', 'A', 'Eleve means student.', 'medium', 'apply'],
            ['What is "livre"?', 'Book', 'Chair', 'Window', 'Bag', 'A', 'Livre means book.', 'medium', 'remember'],
            ['Which sentence fits the classroom?', 'Le professeur enseigne', 'Le pilote vole', 'Le poisson nage', 'Le vendeur vend', 'A', 'A teacher teaches in class.', 'medium', 'analyze'],
            ['Which instruction means "Open your book"?', 'Ouvrez votre livre', 'Fermez la porte', 'Levez-vous', 'Asseyez-vous', 'A', 'Ouvrez means open.', 'medium', 'understand'],
            ['Which object helps you write?', 'un stylo', 'une porte', 'une fenetre', 'une chaise', 'A', 'A pen is used for writing.', 'hard', 'evaluate'],
            ['Which phrase is most useful before answering in class?', 'Je ne comprends pas', 'Je dors toujours', 'Je ferme le cahier', 'Je quitte la classe', 'A', 'It means I do not understand.', 'hard', 'apply'],
        ];
    }

    if (str_contains($lower, 'jour') || str_contains($lower, 'mois') || str_contains($lower, 'heure')) {
        return [
            ['What does "lundi" mean?', 'Monday', 'Friday', 'Sunday', 'Month', 'A', 'Lundi means Monday.', 'easy', 'remember'],
            ['What does "mardi" mean?', 'Tuesday', 'Thursday', 'Saturday', 'January', 'A', 'Mardi means Tuesday.', 'easy', 'remember'],
            ['Which word means month?', 'mois', 'jour', 'heure', 'semaine', 'A', 'Mois means month.', 'easy', 'remember'],
            ['What does "heure" mean?', 'Hour/time', 'Day', 'Year', 'Class', 'A', 'Heure is used for time.', 'easy', 'understand'],
            ['How do you ask "What time is it?"', 'Quelle heure est-il?', 'Comment tu t appelles?', 'Ou vas-tu?', 'Quel age as-tu?', 'A', 'Quelle heure est-il asks for time.', 'medium', 'apply'],
            ['Which is a month?', 'janvier', 'lundi', 'matin', 'soir', 'A', 'Janvier is January.', 'medium', 'remember'],
            ['Which is a day of the week?', 'vendredi', 'mars', 'heure', 'annee', 'A', 'Vendredi is Friday.', 'medium', 'analyze'],
            ['What does "aujourd hui" mean?', 'today', 'tomorrow', 'yesterday', 'evening', 'A', 'Aujourd hui means today.', 'medium', 'understand'],
            ['Which expression best answers time?', 'Il est huit heures', 'Je suis huit ans', 'Bonjour huit', 'Huit livre', 'A', 'Il est ... heures gives time.', 'hard', 'evaluate'],
            ['Which sequence is correct?', 'lundi, mardi, mercredi', 'mars, lundi, jeudi', 'heure, jour, samedi', 'dimanche, janvier, mardi', 'A', 'The first option lists days in order.', 'hard', 'analyze'],
        ];
    }

    if (str_contains($lower, 'aliment') || str_contains($lower, 'boisson')) {
        return [
            ['What does "eau" mean?', 'Water', 'Bread', 'Milk', 'Rice', 'A', 'Eau means water.', 'easy', 'remember'],
            ['What does "pain" mean?', 'Bread', 'Fish', 'Meat', 'Fruit', 'A', 'Pain means bread.', 'easy', 'remember'],
            ['Which item is a drink?', 'jus', 'riz', 'pain', 'poisson', 'A', 'Jus means juice.', 'easy', 'understand'],
            ['Which phrase means "I am hungry"?', 'J ai faim', 'J ai soif', 'Je suis livre', 'Je vais bien', 'A', 'J ai faim means I am hungry.', 'easy', 'remember'],
            ['Which phrase means "I am thirsty"?', 'J ai soif', 'J ai faim', 'Je suis douze', 'Je joue', 'A', 'J ai soif means I am thirsty.', 'medium', 'apply'],
            ['What is "riz"?', 'Rice', 'Water', 'Juice', 'Bread', 'A', 'Riz means rice.', 'medium', 'remember'],
            ['Which sentence orders food politely?', 'Je voudrais du riz, s il vous plait', 'Je suis un livre', 'Fermez la porte', 'Je joue au foot', 'A', 'Je voudrais politely requests something.', 'medium', 'apply'],
            ['Which group contains only foods?', 'pain, riz, poisson', 'eau, jus, lait', 'stylo, livre, table', 'lundi, mardi, juin', 'A', 'The first group contains food items.', 'medium', 'analyze'],
            ['Which word does not belong?', 'stylo', 'pain', 'riz', 'poisson', 'A', 'Stylo is a pen, not food.', 'hard', 'evaluate'],
            ['Which response fits "Qu est-ce que tu bois?"', 'Je bois de l eau', 'Je mange du pain', 'Je vais a l ecole', 'Je suis eleve', 'A', 'The question asks what you drink.', 'hard', 'apply'],
        ];
    }

    $topic = preg_replace('/\s+/', ' ', trim($title));
    return [
        ["Which option best describes the French topic \"$topic\"?", "A topic for $level French learning", 'A Mathematics formula', 'A Science experiment', 'A Social Studies map', 'A', "The topic belongs to French at $level level.", 'easy', 'understand'],
        ["At which level is \"$topic\" studied?", $level, 'Primary 1', 'Senior High School', 'University', 'A', "The database class level for this topic is $level.", 'easy', 'remember'],
        ['Which skill is important when learning French vocabulary?', 'Practising words in short sentences', 'Ignoring pronunciation', 'Guessing without reading', 'Skipping all examples', 'A', 'Vocabulary improves through practice and use.', 'easy', 'understand'],
        ['Which action helps a learner improve in French?', 'Read, listen, speak, and practise regularly', 'Only copy answers', 'Avoid speaking', 'Study only once', 'A', 'French improves through repeated practice.', 'medium', 'apply'],
        ['Which activity shows application in French?', 'Using a learned phrase in a new conversation', 'Only reading the title', 'Choosing random answers', 'Closing the book', 'A', 'Application means using knowledge in a new situation.', 'medium', 'apply'],
        ['Which activity shows analysis in French learning?', 'Comparing two sentences and identifying the grammar difference', 'Memorising one word only', 'Writing unrelated numbers', 'Skipping feedback', 'A', 'Analysis compares parts and explains differences.', 'medium', 'analyze'],
        ['Which revision plan is strongest?', 'Review vocabulary, practise examples, correct mistakes, and try again', 'Read the title once', 'Avoid difficult words', 'Guess every answer', 'A', 'A strong plan uses practice and correction.', 'hard', 'evaluate'],
        ['Which evidence best shows understanding?', 'Correctly using vocabulary in a meaningful sentence', 'Copying without meaning', 'Leaving questions blank', 'Selecting the longest answer', 'A', 'Understanding is shown by meaningful use.', 'hard', 'evaluate'],
        ['What should a learner do after an error?', 'Read the feedback and practise a similar example', 'Ignore the feedback', 'Stop learning French', 'Delete the question', 'A', 'Feedback helps correct and improve learning.', 'medium', 'apply'],
        ['Why are quizzes useful in French?', 'They check understanding and guide revision', 'They replace all lessons', 'They remove the need to practise', 'They are only for points', 'A', 'Quizzes reveal strengths and weak areas.', 'easy', 'understand'],
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
            $topic['class_level'] . ' French Practice - ' . $topic['title'],
            'Practice quiz for ' . $topic['title'] . ' in French.',
            15,
            60,
            3,
        ]
    );

    foreach (frenchQuestionBank((string)$topic['title'], (string)$topic['class_level']) as $question) {
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

echo "French quiz seed complete. Created {$created} quizzes, skipped {$skipped} topics with existing quizzes. Teacher ID: {$teacherId}\n";
