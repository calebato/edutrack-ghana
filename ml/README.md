# EduTrack ML subsystem

EduTrack uses offline model training with a Flask inference service connected to the PHP/XAMPP application. PHP retains a local artifact fallback so learning and quiz workflows remain available during a service outage.

## Components

- `export_training_data.php` creates temporal training samples from completed quiz attempts, mastery, topic completion, login activity, and streaks.
- `train_model.py` trains an explainable ridge regression model without third-party Python packages.
- `models/exam_predictor.json` is the versioned artifact used by PHP inference.
- `ml_service.py` is the primary Flask API for learner profiles, guarded forecasts, and TensorFlow contextual-bandit recommendations.
- Student mastery targets are stored in `student_learning_goals`; the recommender uses each target gap when ranking its predicted topic rewards.
- `client.php` and `api/ml_bridge.php` connect PHP workflows to the service.
- `ml.php` prepares learner features, persists outputs, and provides the direct-inference fallback.
- Database tables cache predictions and recommendations, track model versions, and accept verified final-exam outcomes.
- `train_learner_profile.py` trains the TensorFlow neural profile encoder.
- `train_bandit.py` trains the TensorFlow offline contextual-bandit reward model.
- `train_xgboost.py` trains grouped score and risk models.
- `prepare_whisper_dataset.py` and `fine_tune_whisper.py` enforce consent and speaker-separated audio training requirements.

## Retraining

```powershell
C:\xampp\php\php.exe ml\export_training_data.php
python ml\train_model.py
C:\xampp\php\php.exe ml\register_model.php
```

## Flask service

Start the primary inference service with:

```powershell
cd C:\xampp\htdocs\edutrack\ml
python ml_service.py
```

Dependencies are installed into `ml/vendor`, so no virtual-environment activation is required. To reinstall them, run `python -m pip install --target ml/vendor -r ml/requirements.txt` from the project root.

The service listens on `http://127.0.0.1:5000`. It exposes health, prediction, learner-profile, and recommendation endpoints under `/api/v1`. Run `python ml_service.py --check` to validate the service and artifact without starting the server.

The site remains operational when Flask is stopped because PHP falls back to the same versioned model artifact. Predictions record `flask_service` or `php_fallback` as their inference source.

## Advanced retraining

```powershell
C:\xampp\php\php.exe ml\export_advanced_training_data.php
ml\.venv\Scripts\python.exe ml\train_advanced_models.py
C:\xampp\php\php.exe ml\register_advanced_models.php
```

Start the full service with `powershell -ExecutionPolicy Bypass -File ml\start_ml_service.ps1`.

The exporter uses verified outcomes from `final_exam_results` when they exist and chronological later-assessment performance as a proxy where they do not. A proxy forecast measures exam readiness; it is not a final result. As schools enter more verified outcomes, those labels progressively strengthen later model versions.

## Safeguards

- At least three completed quizzes are required before a forecast is shown.
- New learners receive `insufficient_data`, never a fabricated failing grade.
- Every forecast includes confidence, risk level, model version, and contributing factors.
- Quiz submission continues if model inference is unavailable.
- Recommendations combine mastery gaps, prior scores, progress state, learning preference, difficulty fit, and predicted risk.
