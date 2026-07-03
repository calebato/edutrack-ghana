<?php

/**
 * Validate an MCQ against EduTrack's Basic 7-9 authoring standard.
 * Returns blocking errors and non-blocking review warnings.
 */
function assessQuestionQuality(
    string $question,
    array $options,
    string $bloomLevel,
    string $classLevel,
    string $topicTitle = ''
): array {
    $question = trim(preg_replace('/\s+/u', ' ', $question) ?? $question);
    $normalised = mb_strtolower($question);
    $errors = [];
    $warnings = [];

    $metaPatterns = [
        'which explanation best summarises the purpose of studying',
        'which term identifies the main concept examined',
        'at which curriculum level',
        'which summary best matches the learning goal',
        'a learner must use',
        'two learners give different explanations of',
        'after receiving feedback on an error in',
        'which activity requires analysis of',
        'which revision plan for',
        'which assessment provides the most valid evidence',
        'what is the main topic of this quiz',
        'which subject is this topic studied in',
        'which level is this lesson meant for',
        'quel est le thème principal de ce quiz',
        'dans quelle matière étudie-t-on',
        'à quel niveau ce cours est-il destiné',
        'quelle compétence est importante pour apprendre',
        'quelle est la meilleure façon d’améliorer son français',
        "quelle est la meilleure façon d'améliorer son français",
        "que faut-il faire lorsqu'un mot français est difficile",
        'quelle activité développe la compréhension de',
        'quel résultat montre une bonne maîtrise du thème',
        'quel plan de révision est le plus efficace',
        'pourquoi faut-il pratiquer la prononciation française',
        'saa mfitiase sɔhwɛ yi asɛntitiriw ne dɛn',
        'saa dwumadi sɔhwɛ yi asɛntitiriw ne dɛn',
        'adesua bɛn na',
        'sukuu gyinabea bɛn na wɔayɛ adesua yi ama no',
        'dɛn na ɛboa ma obi sua',
        'ɔkwan bɛn na eye sen biara a wɔfa so ma wɔn twi tu mpɔn',
        'sɛ twi asɛmfua bi yɛ den a',
        'dwumadi bɛn na ɛboa ma osuani te',
        'dɛn na ɛkyerɛ sɛ osuani ate asɛntitiriw no ase',
        'adesua no mu nsiesiei bɛn na eye sen biara',
        'dɛn nti na ɛsɛ sɛ yɛyɛ twi kasa ho dwumadi',
    ];
    foreach ($metaPatterns as $pattern) {
        if (str_contains($normalised, $pattern)) {
            $errors[] = 'The question tests the quiz or topic label instead of curriculum knowledge.';
            break;
        }
    }

    $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $question);
    $limits = ['JHS1' => 32, 'JHS2' => 40, 'JHS3' => 48];
    $limit = $limits[$classLevel] ?? 40;
    if ($wordCount > $limit) {
        $basicLevel = ['JHS1' => 'Basic 7', 'JHS2' => 'Basic 8', 'JHS3' => 'Basic 9'][$classLevel] ?? $classLevel;
        $warnings[] = "The stem has {$wordCount} words; review it for the {$basicLevel} reading level.";
    }
    if (preg_match('/\b(always|never|only|completely|every)\b/ui', $question)) {
        $warnings[] = 'Avoid absolute cue words unless the curriculum fact is genuinely absolute.';
    }
    if (preg_match('/\b(NOT|EXCEPT|FALSE)\b/u', $question)) {
        $warnings[] = 'Negative stems should be rare and the negative word must remain clearly visible.';
    }

    $cleanOptions = array_map(static fn($option): string => mb_strtolower(trim((string)$option)), $options);
    if (count(array_unique($cleanOptions)) !== count($cleanOptions)) {
        $errors[] = 'All four options must be different.';
    }
    foreach ($cleanOptions as $option) {
        if (preg_match('/^(all|none) of the above\.?$/u', $option)) {
            $warnings[] = 'Replace “all/none of the above” with a plausible subject-based distractor.';
        }
        if (preg_match('/unrelated|memorising (a )?quiz|choose answers without|page numbers|topic title/u', $option)) {
            $errors[] = 'Distractors must be plausible curriculum misconceptions, not obviously absurd choices.';
            break;
        }
    }

    $signals = [
        'remember' => '/\b(what|which|who|where|when|identify|name|state|define|list)\b/ui',
        'understand' => '/\b(explain|meaning|describe|summari[sz]e|why|best describes|represents)\b/ui',
        'apply' => '/\b(use|calculate|solve|determine|find|show|complete|if|given|scenario)\b/ui',
        'analyze' => '/\b(analy[sz]e|compare|contrast|difference|relationship|evidence|infer|cause|pattern)\b/ui',
        'evaluate' => '/\b(judge|assess|evaluate|most appropriate|best decision|justify|strongest evidence)\b/ui',
    ];
    if ($bloomLevel === 'create') {
        $errors[] = 'Bloom’s Create level requires a constructed task, not a single-answer MCQ.';
    } elseif (isset($signals[$bloomLevel]) && !preg_match($signals[$bloomLevel], $question)) {
        $warnings[] = 'The wording does not clearly demonstrate the selected Bloom level.';
    }

    if ($topicTitle !== '' && !preg_match('/[\p{L}\p{N}]/u', $topicTitle)) {
        $warnings[] = 'The question is not linked to a clear curriculum topic.';
    }

    return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
}
