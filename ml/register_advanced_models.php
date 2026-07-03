<?php
require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$models = [
    ['learner_profile_metadata.json', 'learner_encoder.keras', 'learner mastery and engagement segment'],
    ['bandit_metadata.json', 'bandit_reward.keras', 'expected learning reward'],
    ['xgboost_metadata.json', 'xgboost_exam_score.json', 'future assessed performance'],
];
foreach ($models as [$metadataFile, $artifactFile, $target]) {
    $path = __DIR__ . '/models/advanced/' . $metadataFile;
    $metadata = json_decode((string)file_get_contents($path), true);
    $features = $metadata['features'] ?? $metadata['context_features'] ?? [];
    $samples = $metadata['training_samples'] ?? $metadata['training_events'] ?? 0;
    dbQuery('UPDATE ml_model_metadata SET is_active=0 WHERE model_name=?', [$metadata['model_name']]);
    dbQuery(
        "INSERT INTO ml_model_metadata
         (model_name,model_version,algorithm,target_name,feature_names_json,metrics_json,artifact_path,training_samples,is_active,trained_at)
         VALUES (?,?,?,?,?,?,?,?,1,?)
         ON DUPLICATE KEY UPDATE metrics_json=VALUES(metrics_json),training_samples=VALUES(training_samples),is_active=1",
        [$metadata['model_name'], $metadata['version'], $metadata['algorithm'] ?? 'gradient_boosted_trees_regression_and_classification', $target, json_encode($features),
         json_encode($metadata['metrics']), 'ml/models/advanced/' . $artifactFile, (int)$samples,
         date('Y-m-d H:i:s', strtotime($metadata['trained_at']))]
    );
    echo "Registered {$metadata['model_name']} {$metadata['version']}\n";
}

$whisperPath = __DIR__ . '/models/whisper/metadata.json';
if (is_file($whisperPath)) {
    $metadata = json_decode((string)file_get_contents($whisperPath), true);
    dbQuery('UPDATE ml_model_metadata SET is_active=0 WHERE model_name=?', [$metadata['model_name']]);
    dbQuery(
        "INSERT INTO ml_model_metadata
         (model_name,model_version,algorithm,target_name,feature_names_json,metrics_json,artifact_path,training_samples,is_active,trained_at)
         VALUES (?,?,?,?,?,?,?,?,1,?)
         ON DUPLICATE KEY UPDATE metrics_json=VALUES(metrics_json),is_active=1",
        [$metadata['model_name'], $metadata['version'], $metadata['algorithm'], 'multilingual speech transcription',
         json_encode(['audio']), json_encode(['fine_tuned' => false]), 'ml/models/whisper', 0,
         date('Y-m-d H:i:s', strtotime($metadata['installed_at']))]
    );
    echo "Registered {$metadata['model_name']} {$metadata['version']}\n";
}
