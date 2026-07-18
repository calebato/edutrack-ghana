-- Upgrade ML metadata restored from the legacy EduTrack schema.

ALTER TABLE ml_model_metadata
    ADD COLUMN IF NOT EXISTS model_version VARCHAR(50) NULL AFTER model_name,
    ADD COLUMN IF NOT EXISTS algorithm VARCHAR(100) NULL AFTER model_version,
    ADD COLUMN IF NOT EXISTS target_name VARCHAR(100) NULL AFTER algorithm,
    ADD COLUMN IF NOT EXISTS feature_names_json JSON NULL AFTER target_name,
    ADD COLUMN IF NOT EXISTS metrics_json JSON NULL AFTER feature_names_json,
    ADD COLUMN IF NOT EXISTS artifact_path VARCHAR(255) NULL AFTER metrics_json,
    ADD COLUMN IF NOT EXISTS training_samples INT NOT NULL DEFAULT 0 AFTER artifact_path,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER training_samples;

UPDATE ml_model_metadata
SET model_version = COALESCE(model_version, version, 'legacy-v1'),
    algorithm = COALESCE(algorithm, model_type, 'legacy'),
    target_name = COALESCE(target_name, 'final_exam_score'),
    feature_names_json = COALESCE(feature_names_json, JSON_ARRAY()),
    metrics_json = COALESCE(metrics_json, JSON_OBJECT('accuracy', accuracy)),
    artifact_path = COALESCE(artifact_path, ''),
    training_samples = COALESCE(training_samples, training_records, 0),
    trained_at = COALESCE(trained_at, created_at, NOW());

ALTER TABLE ml_model_metadata
    MODIFY model_version VARCHAR(50) NOT NULL,
    MODIFY algorithm VARCHAR(100) NOT NULL,
    MODIFY target_name VARCHAR(100) NOT NULL,
    MODIFY feature_names_json JSON NOT NULL,
    MODIFY artifact_path VARCHAR(255) NOT NULL,
    MODIFY trained_at DATETIME NOT NULL,
    DROP INDEX IF EXISTS unique_model_name,
    ADD UNIQUE INDEX IF NOT EXISTS uq_ml_model_version (model_name, model_version);
