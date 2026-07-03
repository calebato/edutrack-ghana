<?php
/** Localise the generated Ghanaian Language curriculum into Twi (Akan). */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

$titles = [
    'JHS1' => ['Nkyerɛwde ne nnyegyei', 'Nkyea ne daa daa nsɛm', 'Abusua ne mpɔtam nsɛmfua', 'Kasamu nhyehyɛe', 'Nsɛm tiawa akenkan', 'Ano kasa ne anansesɛm', 'Mmebusɛm ne nyansa nsɛm'],
    'JHS2' => ['Kasa mmara ne nsɛmfua akuw', 'Nkyerɛkyerɛmu kasa', 'Akenkan ne ntease', 'Krataa ne nkra kyerɛw', 'Amammerɛ nnwom ne anwensɛm', 'Amammerɛ ne afahyɛ', 'Nkɔmmɔ ne sɛnea wɔbɔ nsɛm'],
    'JHS3' => ['Kasa mmara a emu dɔ', 'Asɛm ne adebɔ kyerɛw', 'Nsɛm tɔfabɔ ne nkyerɛase', 'Akyinnye ne baguam kasa', 'Agorɔ ne ɔyɛkyerɛ', 'Amammerɛ gyinapɛn wɔ nhoma mu', 'Kasa nkyerɛase ho nimdeɛ'],
];

function twiDescription(string $title, string $level): string {
    return "{$level} adesua a ɛfa {$title} ho. Ɛnam nkyerɛkyerɛmu, nhwɛso, akenkan, kasa ne nkyerɛw dwumadi so boa osuani ma ɔte adesua no ase.";
}

function twiQuestions(string $title, string $level, bool $application): array {
    $type = $application ? 'dwumadi' : 'mfitiase';
    return [
        ["Saa {$type} sɔhwɛ yi asɛntitiriw ne dɛn?", $title, ['Asɛm foforo a ɛmfa ho', 'Akontaabu nkutoo', 'Agumadi nkutoo']],
        ["Adesua bɛn na « {$title} » ka ho?", 'Akan (Twi)', ['Borɔfo', 'Franse kasa', 'Saense']],
        ["Sukuu gyinabea bɛn na wɔayɛ adesua yi ama no?", $level, ['Primary 1', 'SHS 3', 'Sukuupɔn']],
        ["Dɛn na ɛboa ma obi sua « {$title} » yiye?", 'Kenkan, tie, kasa na kyerɛw Twi daa', ['Bɔ mmuae ho ntonto', 'Kwati nsɛmfua no', 'Sua mmuae nkyerɛwde nkutoo']],
        ["Ɔkwan bɛn na eye sen biara a wɔfa so ma wɔn Twi tu mpɔn?", 'Fa nsɛmfua ne kasamu pa yɛ dwumadi daa', ['Nkasa da', 'Ntie nteɛso biara', 'Sua pɛnkoro pɛ']],
        ["Sɛ Twi asɛmfua bi yɛ den a, dɛn na ɛsɛ sɛ osuani yɛ?", 'Hwehwɛ ase na fa yɛ kasamu', ['Yi fi nhoma no mu', 'Fa nkyerɛase biara ma no', 'Gyae akenkan no']],
        ["Dwumadi bɛn na ɛboa ma osuani te « {$title} » ase?", 'Kenkan anaa tie nhwɛso na fa Twi bua nsɛmmisa', ['Kyerɛw a wonte ase', 'Tra dwumadi nyinaa', 'Fa adesua foforo bua']],
        ["Dɛn na ɛkyerɛ sɛ osuani ate asɛntitiriw no ase?", 'Ɔde nsɛmfua ne kasa mmara no di dwuma yiye', ['Ɔkwati kasamu nyinaa', 'Ɔsan bua mfomso koro no', 'Ɔpaw mmuae a edi kan daa']],
        ["Adesua no mu nsiesiei bɛn na eye sen biara?", 'San hwɛ nsɛmfua no, yɛ kasamu na siesie mfomso', ['Kenkan asɛmti no nkutoo', 'Nsan nhwɛ mfomso', 'Twɛn kosi sɔhwɛ da']],
        ["Dɛn nti na ɛsɛ sɛ yɛyɛ Twi kasa ho dwumadi?", 'Sɛnea yɛbɛkasa pefee na afoforo ate yɛn ase', ['Sɛnea yɛrenkasa bio', 'Sɛnea yɛbɛyi kasa mmara afi hɔ', 'Sɛnea yɛrentie afoforo']],
    ];
}

function twiOptions(string $correct, array $wrong, int $seed): array {
    $position = $seed % 4;
    $options = array_values(array_slice($wrong, 0, 3));
    array_splice($options, $position, 0, [$correct]);
    return [$options, ['A', 'B', 'C', 'D'][$position]];
}

$subject = $conn->query("SELECT id FROM subjects WHERE name='Ghanaian Language' LIMIT 1")->fetch_assoc();
if (!$subject) {
    fwrite(STDERR, "Ghanaian Language subject not found.\n");
    exit(1);
}

$topicSelect = $conn->prepare('SELECT id FROM topics WHERE subject_id=? AND class_level=? ORDER BY sequence_order,id');
$topicUpdate = $conn->prepare('UPDATE topics SET title=?,description=?,content=? WHERE id=?');
$quizSelect = $conn->prepare('SELECT id FROM quizzes WHERE topic_id=? ORDER BY id');
$quizUpdate = $conn->prepare('UPDATE quizzes SET title=?,description=? WHERE id=?');
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
            $description = twiDescription($title, $level);
            $content = $description . ' Ɛsɛ sɛ osuani sua nsɛmfua titiriw, hwɛ nhwɛso, yɛ kasa ne nkyerɛw dwumadi, na ɔsiesie ne mfomso.';
            $topicId = (int)$topic['id'];
            $topicUpdate->bind_param('sssi', $title, $description, $content, $topicId);
            $topicUpdate->execute();
            $topicTotal++;

            $quizSelect->bind_param('i', $topicId);
            $quizSelect->execute();
            $quizzes = $quizSelect->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($quizzes as $quizIndex => $quiz) {
                $application = $quizIndex > 0;
                $quizType = $application ? 'Dwumadi Sɔhwɛ' : 'Mfitiase Sɔhwɛ';
                $quizTitle = "{$level} {$title} — {$quizType}";
                $quizDescription = "Nsɛmmisa du a ɛfa {$title} ho.";
                $quizId = (int)$quiz['id'];
                $quizUpdate->bind_param('ssi', $quizTitle, $quizDescription, $quizId);
                $quizUpdate->execute();
                $quizTotal++;

                // Reviewed Twi questions must be authored from the relevant
                // NaCCA indicator; localisation must not create meta-questions.
            }
        }
    }
    $conn->commit();
    echo "Twi localisation complete.\nTopics: {$topicTotal}\nQuizzes: {$quizTotal}\nQuestions: {$questionTotal}\n";
} catch (Throwable $error) {
    $conn->rollback();
    fwrite(STDERR, "Localisation rolled back: {$error->getMessage()}\n");
    exit(1);
}
