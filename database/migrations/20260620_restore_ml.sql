CREATE TABLE IF NOT EXISTS ml_model_metadata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    model_name VARCHAR(100) NOT NULL,
    model_version VARCHAR(50) NOT NULL,
    algorithm VARCHAR(100) NOT NULL,
    target_name VARCHAR(100) NOT NULL,
    feature_names_json JSON NOT NULL,
    metrics_json JSON NULL,
    artifact_path VARCHAR(255) NOT NULL,
    training_samples INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    trained_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ml_model_version (model_name, model_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS student_predictions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    prediction_type VARCHAR(50) NOT NULL DEFAULT 'final_exam_score',
    predicted_score DECIMAL(5,2) NULL,
    predicted_grade VARCHAR(10) NULL,
    confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
    risk_level ENUM('insufficient_data','low','medium','high') NOT NULL DEFAULT 'insufficient_data',
    factors_json JSON NULL,
    model_version VARCHAR(50) NULL,
    generated_at DATETIME NOT NULL,
    UNIQUE KEY uq_student_prediction (student_id, prediction_type),
    CONSTRAINT fk_prediction_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS student_recommendations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    topic_id INT NOT NULL,
    recommendation_score DECIMAL(8,4) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    explanation_json JSON NULL,
    model_version VARCHAR(50) NOT NULL,
    generated_at DATETIME NOT NULL,
    UNIQUE KEY uq_student_topic_recommendation (student_id, topic_id),
    KEY idx_recommendation_rank (student_id, recommendation_score),
    CONSTRAINT fk_recommendation_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_recommendation_topic FOREIGN KEY (topic_id) REFERENCES topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS final_exam_results (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    subject_id INT NULL,
    score DECIMAL(5,2) NOT NULL,
    recorded_by INT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_exam_result (student_id, academic_year, subject_id),
    CONSTRAINT fk_exam_result_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_exam_result_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    CONSTRAINT fk_exam_result_teacher FOREIGN KEY (recorded_by) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS student_ml_profiles (
    student_id INT PRIMARY KEY,
    segment ENUM('needs_support','developing','improving','mastering') NOT NULL,
    embedding_json JSON NULL,
    model_version VARCHAR(50) NOT NULL,
    inference_source VARCHAR(50) NOT NULL,
    generated_at DATETIME NOT NULL,
    CONSTRAINT fk_ml_profile_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
