<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/client.php';

const EDUTRACK_RECOMMENDER_VERSION = 'adaptive-rule-fallback-v1';
const EDUTRACK_ML_CACHE_SECONDS = 86400;
const EDUTRACK_PERSONAL_FORECAST_MINIMUM = 5;
const EDUTRACK_PERSONAL_FORECAST_VERSION = 'personal-linear-trend-v1';

function mlMean(array $values): float {
    return $values ? array_sum($values) / count($values) : 0.0;
}

function loadExamPredictionModel(): ?array {
    static $model = false;
    if ($model !== false) return $model;
    $path = __DIR__ . '/models/exam_predictor.json';
    if (!is_file($path)) return $model = null;
    $decoded = json_decode((string)file_get_contents($path), true);
    return $model = is_array($decoded) ? $decoded : null;
}

function getStudentMLFeatures(int $studentId, bool $refresh = false): array {
    static $featureCache = [];
    if (!$refresh && isset($featureCache[$studentId])) return $featureCache[$studentId];
    $student = dbRow('SELECT login_count,current_streak,class_level,school_id FROM students WHERE id=?', [$studentId]) ?? [];
    $attempts = dbRows(
        'SELECT score,passed,time_taken_seconds FROM quiz_attempts
         WHERE student_id=? AND completed_at IS NOT NULL ORDER BY completed_at,id',
        [$studentId]
    );
    $scores = array_map(static fn(array $row): float => (float)$row['score'], $attempts);
    $recent = array_slice($scores, -3);
    $older = array_slice($scores, max(0, count($scores) - 6), 3);
    $times = array_values(array_filter(array_map(
        static fn(array $row): float => (float)$row['time_taken_seconds'],
        $attempts
    ), static fn(float $time): bool => $time > 0 && $time < 7200));
    $mastery = (float)(dbValue(
        'SELECT COALESCE(AVG(mastery_level) * 100,0) FROM student_learning_profiles WHERE student_id=?',
        [$studentId]
    ) ?: 0);
    $completion = (float)(dbValue(
        "SELECT COALESCE(SUM(COALESCE(tp.completion_percent,0)) / NULLIF(COUNT(t.id),0),0)
         FROM topics t
         LEFT JOIN topic_progress tp ON tp.topic_id=t.id AND tp.student_id=?
         WHERE t.class_level=? AND t.approval_status='approved' AND t.is_active=1
           AND (t.school_id IS NULL OR t.school_id=?)",
        [$studentId, $student['class_level'] ?? 'JHS1', (int)($student['school_id'] ?? 1)]
    ) ?: 0);

    return $featureCache[$studentId] = [
        'attempt_count' => count($attempts),
        'avg_score' => mlMean($scores),
        'recent_avg' => mlMean($recent),
        'trend' => mlMean($recent) - mlMean($older ?: $recent),
        'pass_rate' => mlMean(array_map(static fn(array $row): float => (float)$row['passed'], $attempts)) * 100,
        'attempt_count_log' => log(1 + count($attempts)),
        'avg_time_minutes' => $times ? min(60, mlMean($times) / 60) : 0,
        'mastery' => $mastery,
        'topic_completion' => $completion,
        'login_count_log' => log(1 + (int)($student['login_count'] ?? 0)),
        'current_streak' => min(30, (int)($student['current_streak'] ?? 0)),
    ];
}

function scoreToBeceGrade(float $score): string {
    foreach ([[90,'1'],[80,'2'],[70,'3'],[60,'4'],[55,'5'],[50,'6'],[40,'7'],[35,'8']] as [$minimum, $grade]) {
        if ($score >= $minimum) return $grade;
    }
    return '9';
}

function getStudentLearningGoal(int $studentId): array {
    $row = dbRow('SELECT target_mastery,updated_at FROM student_learning_goals WHERE student_id=?', [$studentId]);
    return [
        'target_mastery' => max(50, min(95, (int)($row['target_mastery'] ?? 70))),
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function predictPersonalQuizTrend(int $studentId, array $features): array {
    $scores = array_map(
        static fn(array $row): float => (float)$row['score'],
        dbRows('SELECT score FROM quiz_attempts WHERE student_id=? AND completed_at IS NOT NULL ORDER BY completed_at,id', [$studentId])
    );
    $count = count($scores);
    $meanX = ($count - 1) / 2;
    $meanY = mlMean($scores);
    $numerator = 0.0;
    $denominator = 0.0;
    foreach ($scores as $index => $value) {
        $numerator += ($index - $meanX) * ($value - $meanY);
        $denominator += ($index - $meanX) ** 2;
    }
    $slope = $denominator > 0 ? $numerator / $denominator : 0.0;
    $projectedTrend = $meanY + ($slope * min(3, max(1, $count / 3)));
    $rawScore = ((float)$features['recent_avg'] * 0.55) + ($meanY * 0.30) + ($projectedTrend * 0.15);
    $score = round(max(0, min(100, max($meanY - 20, min($meanY + 20, $rawScore)))), 1);
    $variance = mlMean(array_map(static fn(float $value): float => ($value - $meanY) ** 2, $scores));
    $spread = sqrt($variance);
    $confidence = round(max(40, min(70, 48 + (($count - EDUTRACK_PERSONAL_FORECAST_MINIMUM) * 2) - min(15, $spread / 2))), 1);
    return [
        'available' => true,
        'score' => $score,
        'grade' => scoreToBeceGrade($score),
        'confidence' => $confidence,
        'risk_level' => $score < 50 ? 'high' : ($score < 60 ? 'medium' : 'low'),
        'attempts_needed' => 0,
        'factors' => [
            ['name' => 'Recent quiz performance', 'direction' => 'positive', 'effect' => round((float)$features['recent_avg'], 1)],
            ['name' => 'Overall quiz performance', 'direction' => 'positive', 'effect' => round($meanY, 1)],
            ['name' => 'Personal performance trend', 'direction' => $slope >= 0 ? 'positive' : 'negative', 'effect' => round(abs($slope), 1)],
        ],
        'features' => $features,
        'model_version' => EDUTRACK_PERSONAL_FORECAST_VERSION,
        'inference_source' => 'personal_linear_regression',
        'provisional' => true,
    ];
}

function predictStudentExamPerformance(int $studentId, bool $persist = true, bool $forceRefresh = false): array {
    static $requestCache = [];
    if (!$forceRefresh && isset($requestCache[$studentId])) return $requestCache[$studentId];

    $model = loadExamPredictionModel();
    $features = getStudentMLFeatures($studentId, $forceRefresh);
    $minimum = EDUTRACK_PERSONAL_FORECAST_MINIMUM;
    $hasEnoughEvidence = $features['attempt_count'] >= $minimum;
    if (!$hasEnoughEvidence) {
        if ($persist) dbQuery("DELETE FROM student_predictions WHERE student_id=? AND prediction_type='final_exam_score'", [$studentId]);
        return $requestCache[$studentId] = [
            'available' => false,
            'score' => null,
            'grade' => null,
            'confidence' => 0,
            'risk_level' => 'insufficient_data',
            'attempts_needed' => max(0, $minimum - $features['attempt_count']),
            'factors' => [],
            'features' => $features,
            'model_version' => $model['version'] ?? null,
            'inference_source' => 'evidence_guard',
            'data_message' => 'Complete ' . max(0, $minimum - $features['attempt_count']) . ' more completed quiz' .
                (max(0, $minimum - $features['attempt_count']) === 1 ? '' : 'zes') . ' to unlock a provisional forecast.',
        ];
    }
    if (!$forceRefresh) {
        $cached = dbRow(
            "SELECT * FROM student_predictions
             WHERE student_id=? AND prediction_type='final_exam_score'
               AND TIMESTAMPDIFF(SECOND,generated_at,NOW()) <= ?
             ORDER BY generated_at DESC LIMIT 1",
            [$studentId, EDUTRACK_ML_CACHE_SECONDS]
        );
        $validVersions = [(string)($model['version'] ?? ''), EDUTRACK_PERSONAL_FORECAST_VERSION];
        $activeXgboostVersion = dbValue(
            "SELECT model_version FROM ml_model_metadata
             WHERE model_name='xgboost_exam_prediction' AND is_active=1
             ORDER BY trained_at DESC LIMIT 1"
        );
        if ($activeXgboostVersion) $validVersions[] = (string)$activeXgboostVersion;
        if ($cached && in_array((string)$cached['model_version'], $validVersions, true)) {
            $score = $cached['predicted_score'] === null ? null : (float)$cached['predicted_score'];
            return $requestCache[$studentId] = [
                'available' => $score !== null,
                'score' => $score,
                'grade' => $cached['predicted_grade'],
                'confidence' => (float)$cached['confidence'],
                'risk_level' => $cached['risk_level'],
                'attempts_needed' => $score === null ? max(0, $minimum - $features['attempt_count']) : 0,
                'factors' => json_decode((string)$cached['factors_json'], true) ?: [],
                'features' => $features,
                'model_version' => $cached['model_version'],
                'inference_source' => 'database_cache',
                'provisional' => (string)$cached['model_version'] === EDUTRACK_PERSONAL_FORECAST_VERSION,
            ];
        }
    }
    $activeXgboostVersion = dbValue(
        "SELECT model_version FROM ml_model_metadata
         WHERE model_name='xgboost_exam_prediction' AND is_active=1
         ORDER BY trained_at DESC LIMIT 1"
    );
    $remoteResult = $activeXgboostVersion && $features['attempt_count'] >= $minimum
        ? callMLService('/api/v1/predict', [
            'attempt_count' => $features['attempt_count'],
            'features' => $features,
        ], 3.0)
        : null;
    if ($remoteResult !== null && array_key_exists('available', $remoteResult)) {
        $result = array_merge([
            'score' => null, 'grade' => null, 'confidence' => 0,
            'risk_level' => 'insufficient_data', 'attempts_needed' => 0,
            'factors' => [], 'model_version' => $model['version'] ?? null,
            'inference_source' => 'flask_service',
        ], $remoteResult);
        $result['features'] = $features;
    } elseif (!$model) {
        $result = predictPersonalQuizTrend($studentId, $features);
    } else {
        $value = (float)$model['bias'];
        $contributions = [];
        foreach ($model['features'] as $index => $name) {
            $standardized = ((float)$features[$name] - (float)$model['means'][$index]) / max(0.000001, (float)$model['stds'][$index]);
            $effect = (float)$model['weights'][$index] * $standardized;
            $value += $effect;
            $contributions[$name] = $effect;
        }
        $score = round(max(0, min(100, $value)), 1);
        arsort($contributions, SORT_NUMERIC);
        $labels = [
            'avg_score' => 'Overall quiz performance', 'recent_avg' => 'Recent quiz performance',
            'trend' => 'Performance trend', 'pass_rate' => 'Quiz pass rate',
            'attempt_count_log' => 'Practice volume', 'avg_time_minutes' => 'Quiz completion time',
            'mastery' => 'Topic mastery', 'topic_completion' => 'Curriculum completion',
            'login_count_log' => 'Platform activity', 'current_streak' => 'Learning consistency',
        ];
        $factors = [];
        foreach (array_slice(array_keys($contributions), 0, 4) as $name) {
            $factors[] = [
                'name' => $labels[$name] ?? $name,
                'direction' => $contributions[$name] >= 0 ? 'positive' : 'negative',
                'effect' => round(abs($contributions[$name]), 1),
            ];
        }
        $rmse = (float)($model['metrics']['rmse'] ?? 15);
        $confidence = round(max(35, min(95, 100 - ($rmse * 4) + min(12, ($features['attempt_count'] - $minimum) * 1.5))), 1);
        $result = [
            'available' => true,
            'score' => $score,
            'grade' => scoreToBeceGrade($score),
            'confidence' => $confidence,
            'risk_level' => $score < 50 ? 'high' : ($score < 60 ? 'medium' : 'low'),
            'attempts_needed' => 0,
            'factors' => $factors,
            'features' => $features,
            'model_version' => (string)$model['version'],
            'inference_source' => 'php_fallback',
        ];
    }

    if ($persist) {
        try {
            dbQuery(
                "INSERT INTO student_predictions
                 (student_id,prediction_type,predicted_score,predicted_grade,confidence,risk_level,factors_json,model_version,generated_at)
                 VALUES (?,'final_exam_score',?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE predicted_score=VALUES(predicted_score),predicted_grade=VALUES(predicted_grade),
                 confidence=VALUES(confidence),risk_level=VALUES(risk_level),factors_json=VALUES(factors_json),
                 model_version=VALUES(model_version),generated_at=NOW()",
                [$studentId, $result['score'], $result['grade'], $result['confidence'], $result['risk_level'],
                 json_encode($result['factors']), $result['model_version']]
            );
        } catch (Throwable $error) {
            error_log('EduTrack prediction cache failed: ' . $error->getMessage());
        }
    }
    return $requestCache[$studentId] = $result;
}

function generateMLRecommendations(int $studentId, int $limit = 5, bool $forceRefresh = false): array {
    static $requestCache = [];
    $cacheKey = $studentId . ':' . $limit;
    if (!$forceRefresh && isset($requestCache[$cacheKey])) return $requestCache[$cacheKey];

    if (!$forceRefresh) {
        $cachedRows = dbRows(
            "SELECT sr.*,t.*,s.name AS subject_name,s.color
             FROM student_recommendations sr
             JOIN topics t ON t.id=sr.topic_id
             JOIN subjects s ON s.id=t.subject_id
             WHERE sr.student_id=? AND TIMESTAMPDIFF(SECOND,sr.generated_at,NOW()) <= ?
             ORDER BY sr.recommendation_score DESC LIMIT " . max(1, (int)$limit),
            [$studentId, EDUTRACK_ML_CACHE_SECONDS]
        );
        if ($cachedRows) {
            $cachedRecommendations = [];
            foreach ($cachedRows as $row) {
                $explanation = json_decode((string)$row['explanation_json'], true) ?: [];
                $cachedRecommendations[] = [
                    'topic' => $row,
                    'priority' => (float)$row['recommendation_score'],
                    'reason' => $row['reason'],
                    'study_tip' => $explanation['study_tip'] ?? 'Review the lesson, then practise with a quiz.',
                    'model_version' => $row['model_version'],
                    'inference_source' => 'database_cache',
                ];
            }
            return $requestCache[$cacheKey] = $cachedRecommendations;
        }
    }

    $student = dbRow('SELECT * FROM students WHERE id=?', [$studentId]);
    if (!$student) return [];
    $learningGoal = getStudentLearningGoal($studentId);
    $prediction = predictStudentExamPerformance($studentId, false, $forceRefresh);
    $topics = dbRows(
        "SELECT t.*,s.name AS subject_name,s.color,
                COALESCE(tp.status,'not_started') AS progress_status,
                COALESCE(tp.completion_percent,0) AS completion_pct,
                COALESCE(slp.mastery_level,0) AS mastery_level,
                (SELECT AVG(qa.score) FROM quiz_attempts qa JOIN quizzes q ON q.id=qa.quiz_id
                 WHERE qa.student_id=? AND q.topic_id=t.id AND qa.completed_at IS NOT NULL) AS avg_quiz_score
         FROM topics t JOIN subjects s ON s.id=t.subject_id
         LEFT JOIN topic_progress tp ON tp.topic_id=t.id AND tp.student_id=?
         LEFT JOIN student_learning_profiles slp ON slp.topic_id=t.id AND slp.student_id=?
         WHERE t.class_level=? AND t.approval_status='approved' AND t.is_active=1
           AND (t.school_id IS NULL OR t.school_id=?)",
        [$studentId, $studentId, $studentId, $student['class_level'], (int)$student['school_id']]
    );
    $difficultyMap = ['beginner' => 'easy', 'intermediate' => 'medium', 'advanced' => 'hard'];
    $preferredDifficulty = $difficultyMap[$student['difficulty_level']] ?? 'easy';
    $guide = function_exists('getLearningPreferenceGuide')
        ? getLearningPreferenceGuide($student['learning_style'] ?? 'visual')
        : ['recommendation_tip' => 'Review the lesson, then practise with a quiz.'];
    $features = $prediction['features'] ?? getStudentMLFeatures($studentId, $forceRefresh);
    $minimum = (int)(loadExamPredictionModel()['minimum_attempts'] ?? 3);
    $remoteRanking = $features['attempt_count'] >= $minimum ? callMLService('/api/v1/recommendations', [
        'learner' => [
            'preferred_difficulty' => $preferredDifficulty,
            'risk_level' => $prediction['risk_level'] ?? 'insufficient_data',
            'features' => $features,
            'target_mastery' => $learningGoal['target_mastery'],
        ],
        'candidates' => array_map(static fn(array $topic): array => [
            'id' => (int)$topic['id'],
            'mastery_level' => (float)$topic['mastery_level'],
            'avg_quiz_score' => $topic['avg_quiz_score'] === null ? null : (float)$topic['avg_quiz_score'],
            'progress_status' => $topic['progress_status'],
            'difficulty' => $topic['difficulty'],
            'subject_id' => (int)$topic['subject_id'],
            'sequence_order' => (int)$topic['sequence_order'],
        ], $topics),
        'limit' => $limit,
    ], 5.0) : null;
    $ranked = [];
    if ($remoteRanking !== null && isset($remoteRanking['recommendations'])) {
        $topicsById = [];
        foreach ($topics as $topic) $topicsById[(int)$topic['id']] = $topic;
        foreach ($remoteRanking['recommendations'] as $remoteItem) {
            $topicId = (int)($remoteItem['topic_id'] ?? 0);
            if (!isset($topicsById[$topicId])) continue;
            $ranked[] = [
                'topic' => $topicsById[$topicId],
                'priority' => (float)$remoteItem['score'],
                'reason' => (string)$remoteItem['reason'],
                'study_tip' => $guide['recommendation_tip'],
                'model_version' => (string)($remoteRanking['model_version'] ?? 'tensorflow-bandit-service-v1'),
                'inference_source' => 'flask_service',
            ];
        }
    } else foreach ($topics as $topic) {
        $mastery = (float)$topic['mastery_level'];
        $quizScore = $topic['avg_quiz_score'] === null ? null : (float)$topic['avg_quiz_score'];
        if ($topic['progress_status'] === 'completed' && $mastery >= 0.75 && ($quizScore === null || $quizScore >= 70)) continue;
        $need = 1 - max(0, min(1, $mastery));
        $statusBoost = $topic['progress_status'] === 'in_progress' ? 0.25 : 0.12;
        $weaknessBoost = $quizScore !== null ? max(0, (70 - $quizScore) / 100) : 0.08;
        $difficultyFit = $topic['difficulty'] === $preferredDifficulty ? 0.12 : 0.04;
        $riskBoost = ($prediction['risk_level'] ?? '') === 'high' ? 0.08 : 0;
        $goalGap = max(0, ($learningGoal['target_mastery'] / 100) - $mastery);
        $score = ($need * 0.35) + ($goalGap * 0.25) + $statusBoost + ($weaknessBoost * 0.25) + $difficultyFit + $riskBoost;
        $reason = $topic['progress_status'] === 'in_progress'
            ? 'Continue this topic to complete your current learning sequence'
            : ($quizScore !== null && $quizScore < 60
                ? 'Recommended because your previous score was ' . round($quizScore) . '%'
                : 'Recommended to strengthen an unmastered curriculum area');
        $ranked[] = [
            'topic' => $topic,
            'priority' => round($score * 100, 2),
            'reason' => $reason,
            'study_tip' => $guide['recommendation_tip'],
            'model_version' => EDUTRACK_RECOMMENDER_VERSION,
            'inference_source' => 'php_fallback',
        ];
    }
    usort($ranked, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
    $ranked = array_slice($ranked, 0, $limit);
    try {
        dbQuery('DELETE FROM student_recommendations WHERE student_id=?', [$studentId]);
        foreach ($ranked as $item) {
            dbQuery(
                'INSERT INTO student_recommendations
                 (student_id,topic_id,recommendation_score,reason,explanation_json,model_version,generated_at)
                 VALUES (?,?,?,?,?,?,NOW())',
                [$studentId, (int)$item['topic']['id'], $item['priority'], $item['reason'],
                 json_encode(['study_tip' => $item['study_tip'], 'inference_source' => $item['inference_source']]), $item['model_version']]
            );
        }
    } catch (Throwable $error) {
        error_log('EduTrack recommendation cache failed: ' . $error->getMessage());
    }
    return $requestCache[$cacheKey] = $ranked;
}

function getNeuralLearnerProfile(int $studentId, bool $persist = true, bool $forceRefresh = false): array {
    static $requestCache = [];
    if (!$forceRefresh && isset($requestCache[$studentId])) return $requestCache[$studentId];

    $features = getStudentMLFeatures($studentId, $forceRefresh);
    if (!$forceRefresh) {
        $cached = dbRow(
            'SELECT * FROM student_ml_profiles WHERE student_id=? AND TIMESTAMPDIFF(SECOND,generated_at,NOW()) <= ?',
            [$studentId, EDUTRACK_ML_CACHE_SECONDS]
        );
        if ($cached) {
            return $requestCache[$studentId] = [
                'segment' => $cached['segment'],
                'embedding' => json_decode((string)$cached['embedding_json'], true) ?: [],
                'model_version' => $cached['model_version'],
                'inference_source' => 'database_cache',
                'features' => $features,
            ];
        }
    }
    $remote = $features['attempt_count'] > 0
        ? callMLService('/api/v1/profile', ['features' => $features], 5.0)
        : null;
    if ($remote !== null && isset($remote['segment'])) {
        $profile = array_merge([
            'embedding' => [], 'model_version' => 'unknown', 'inference_source' => 'flask_tensorflow',
        ], $remote);
    } else {
        $segment = $features['mastery'] >= 75 && $features['recent_avg'] >= 70
            ? 'mastering'
            : ($features['trend'] >= 5 ? 'improving' : ($features['recent_avg'] > 0 && $features['recent_avg'] < 50 ? 'needs_support' : 'developing'));
        $profile = [
            'segment' => $segment,
            'embedding' => [],
            'model_version' => 'rule-fallback-v1',
            'inference_source' => 'php_fallback',
        ];
    }
    if ($persist) {
        try {
            dbQuery(
                "INSERT INTO student_ml_profiles (student_id,segment,embedding_json,model_version,inference_source,generated_at)
                 VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE segment=VALUES(segment),embedding_json=VALUES(embedding_json),
                 model_version=VALUES(model_version),inference_source=VALUES(inference_source),generated_at=NOW()",
                [$studentId, $profile['segment'], json_encode($profile['embedding']), $profile['model_version'], $profile['inference_source']]
            );
        } catch (Throwable $error) {
            error_log('EduTrack neural profile cache failed: ' . $error->getMessage());
        }
    }
    $profile['features'] = $features;
    return $requestCache[$studentId] = $profile;
}

function registerActiveMLModel(): void {
    $model = loadExamPredictionModel();
    if (!$model) return;
    try {
        dbQuery(
            "UPDATE ml_model_metadata SET is_active=0 WHERE model_name=? AND model_version<>?",
            [$model['model_name'], $model['version']]
        );
        dbQuery(
            "INSERT INTO ml_model_metadata
             (model_name,model_version,algorithm,target_name,feature_names_json,metrics_json,artifact_path,training_samples,is_active,trained_at)
             VALUES (?,?,?,?,?,?,?,?,1,?)
             ON DUPLICATE KEY UPDATE metrics_json=VALUES(metrics_json),training_samples=VALUES(training_samples),is_active=1",
            [$model['model_name'], $model['version'], $model['algorithm'], $model['target'], json_encode($model['features']),
             json_encode($model['metrics']), 'ml/models/exam_predictor.json', (int)$model['training_samples'],
             date('Y-m-d H:i:s', strtotime($model['trained_at']))]
        );
    } catch (Throwable $error) {
        error_log('EduTrack model metadata registration failed: ' . $error->getMessage());
    }
}
