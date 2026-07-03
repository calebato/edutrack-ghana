<?php
/** Localise the generated French curriculum and assessments into French. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

$titles = [
    'JHS1' => ['Les salutations et les présentations', "Les nombres et l'âge", 'La famille et les amis', "L'école et la salle de classe", "Les jours, les mois et l'heure", 'Les aliments et les boissons', 'La grammaire française de base'],
    'JHS2' => ['Les activités quotidiennes', 'La maison et le quartier', "Les achats et l'argent", 'Le temps et les vêtements', 'Les loisirs et les sports', 'Les voyages et les directions', 'Les actions passées et futures'],
    'JHS3' => ['La santé et le corps', 'Les métiers et les ambitions', "L'environnement et la communauté", 'La communication et la technologie', 'La rédaction de lettres en français', "La conversation et l'écoute", 'La compréhension écrite en français'],
];

function frenchDescription(string $title, string $level): string {
    return "Étude de {$title} au niveau {$level} à travers le vocabulaire, la grammaire, la compréhension, des exemples guidés et des activités pratiques.";
}

function frenchQuestions(string $title, string $level, bool $application): array {
    $type = $application ? "d'application" : 'de base';
    return [
        ["Quel est le thème principal de ce quiz {$type} ?", $title, ['Un thème sans rapport', 'Les mathématiques', "L'éducation physique"]],
        ["Dans quelle matière étudie-t-on « {$title} » ?", 'Le français', ["L'anglais", 'Les sciences', "L'informatique"]],
        ["À quel niveau ce cours est-il destiné ?", $level, ['École primaire 1', 'Lycée 3', 'Université']],
        ["Quelle compétence est importante pour apprendre « {$title} » ?", 'Lire, écouter, parler et pratiquer en français', ['Deviner sans lire', 'Éviter le vocabulaire', 'Mémoriser seulement les lettres des réponses']],
        ["Quelle est la meilleure façon d'améliorer son français ?", 'Pratiquer régulièrement avec des phrases correctes', ['Ne jamais parler', 'Ignorer les corrections', 'Étudier une seule fois']],
        ["Que faut-il faire lorsqu'un mot français est difficile ?", "Chercher son sens et l'utiliser dans une phrase", ["L'effacer du cahier", 'Choisir un sens au hasard', 'Ne plus lire le texte']],
        ["Quelle activité développe la compréhension de « {$title} » ?", 'Lire ou écouter un exemple puis répondre en français', ['Copier sans comprendre', 'Sauter toutes les activités', 'Répondre dans une matière différente']],
        ["Quel résultat montre une bonne maîtrise du thème ?", 'Employer correctement le vocabulaire et les structures', ['Éviter toutes les phrases', 'Répéter une réponse fausse', 'Choisir toujours la première option']],
        ["Quel plan de révision est le plus efficace ?", 'Réviser les mots, former des phrases et corriger ses erreurs', ['Lire seulement le titre', 'Ne jamais revoir ses erreurs', 'Attendre le jour du test']],
        ["Pourquoi faut-il pratiquer la prononciation française ?", 'Pour parler clairement et être compris', ['Pour éviter de communiquer', 'Pour remplacer la grammaire', 'Pour ne jamais écouter les autres']],
    ];
}

function localizedOptions(string $correct, array $wrong, int $seed): array {
    $position = $seed % 4;
    $options = array_values(array_slice($wrong, 0, 3));
    array_splice($options, $position, 0, [$correct]);
    return [$options, ['A', 'B', 'C', 'D'][$position]];
}

$subject = $conn->query("SELECT id FROM subjects WHERE name='French' LIMIT 1")->fetch_assoc();
if (!$subject) {
    fwrite(STDERR, "French subject not found.\n");
    exit(1);
}

$topicSelect = $conn->prepare('SELECT id FROM topics WHERE subject_id=? AND class_level=? ORDER BY sequence_order,id');
$topicUpdate = $conn->prepare('UPDATE topics SET title=?, description=?, content=? WHERE id=?');
$quizSelect = $conn->prepare('SELECT id FROM quizzes WHERE topic_id=? ORDER BY id');
$quizUpdate = $conn->prepare('UPDATE quizzes SET title=?, description=? WHERE id=?');
$questionSelect = $conn->prepare('SELECT id FROM questions WHERE quiz_id=? ORDER BY id LIMIT 10');
$questionUpdate = $conn->prepare('UPDATE questions SET question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_answer=?,explanation=?,difficulty=? WHERE id=?');

$conn->begin_transaction();
try {
    $topicTotal = $quizTotal = $questionTotal = 0;
    foreach ($titles as $level => $localizedTitles) {
        $subjectId = (int)$subject['id'];
        $topicSelect->bind_param('is', $subjectId, $level);
        $topicSelect->execute();
        $topics = $topicSelect->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($topics as $index => $topic) {
            if (!isset($localizedTitles[$index])) continue;
            $title = $localizedTitles[$index];
            $description = frenchDescription($title, $level);
            $content = $description . " L'élève doit apprendre les mots clés, observer les exemples, s'exprimer en français et corriger ses erreurs.";
            $topicId = (int)$topic['id'];
            $topicUpdate->bind_param('sssi', $title, $description, $content, $topicId);
            $topicUpdate->execute();
            $topicTotal++;

            $quizSelect->bind_param('i', $topicId);
            $quizSelect->execute();
            $quizzes = $quizSelect->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($quizzes as $quizIndex => $quiz) {
                $application = $quizIndex > 0;
                $quizType = $application ? "Quiz d'application" : 'Quiz de base';
                $quizTitle = "{$level} {$title} — {$quizType}";
                $quizDescription = "Évaluation de 10 questions sur {$title}.";
                $quizId = (int)$quiz['id'];
                $quizUpdate->bind_param('ssi', $quizTitle, $quizDescription, $quizId);
                $quizUpdate->execute();
                $quizTotal++;

                // Reviewed French questions must be authored from the relevant
                // NaCCA indicator; localisation must not create meta-questions.
            }
        }
    }
    $conn->commit();
    echo "French localisation complete.\nTopics: {$topicTotal}\nQuizzes: {$quizTotal}\nQuestions: {$questionTotal}\n";
} catch (Throwable $error) {
    $conn->rollback();
    fwrite(STDERR, "Localisation rolled back: {$error->getMessage()}\n");
    exit(1);
}
