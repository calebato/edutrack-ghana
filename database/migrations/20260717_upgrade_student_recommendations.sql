-- Upgrade recommendation caches restored from the legacy EduTrack schema.
-- MariaDB 10.4 supports IF NOT EXISTS for ADD COLUMN, making this migration
-- safe to run more than once.

ALTER TABLE student_recommendations
    ADD COLUMN IF NOT EXISTS recommendation_score DECIMAL(8,4) NULL AFTER topic_id,
    ADD COLUMN IF NOT EXISTS explanation_json JSON NULL AFTER reason,
    ADD COLUMN IF NOT EXISTS model_version VARCHAR(50) NULL AFTER explanation_json,
    ADD COLUMN IF NOT EXISTS generated_at DATETIME NULL AFTER model_version;

UPDATE student_recommendations
SET recommendation_score = COALESCE(recommendation_score, priority_score, 0),
    explanation_json = COALESCE(explanation_json, JSON_OBJECT()),
    model_version = COALESCE(model_version, 'legacy-cache-v1'),
    generated_at = COALESCE(generated_at, updated_at, created_at, NOW());

ALTER TABLE student_recommendations
    MODIFY recommendation_score DECIMAL(8,4) NOT NULL,
    MODIFY model_version VARCHAR(50) NOT NULL,
    MODIFY generated_at DATETIME NOT NULL;

CREATE INDEX IF NOT EXISTS idx_recommendation_rank
    ON student_recommendations (student_id, recommendation_score);
