-- Add the columns required by adaptive quiz selection and exact attempt state.
-- Safe to run repeatedly on older EduTrack database exports.

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS bloom_level
        ENUM('remember','understand','apply','analyze','evaluate','create')
        NULL DEFAULT 'understand' AFTER difficulty;

UPDATE questions
SET bloom_level = CASE difficulty
    WHEN 'hard' THEN 'analyze'
    WHEN 'medium' THEN 'apply'
    ELSE 'understand'
END;

ALTER TABLE questions
    MODIFY bloom_level
        ENUM('remember','understand','apply','analyze','evaluate','create')
        NOT NULL DEFAULT 'understand';

ALTER TABLE quiz_attempts
    ADD COLUMN IF NOT EXISTS question_ids_json LONGTEXT NULL AFTER answers_json;
