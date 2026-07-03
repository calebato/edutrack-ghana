"""EduTrack Flask ML service.

The service owns prediction, learner profiling, and recommendation ranking.
PHP sends privacy-minimized numeric features and retains local inference only as
an availability fallback.
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import sys
import tempfile
from pathlib import Path
from typing import Any

VENDOR_PATH = Path(__file__).resolve().parent / "vendor"
ADVANCED_SITE = Path(__file__).resolve().parent / ".venv" / "Lib" / "site-packages"
if ADVANCED_SITE.is_dir():
    sys.path.insert(0, str(ADVANCED_SITE))
if VENDOR_PATH.is_dir():
    sys.path.insert(0, str(VENDOR_PATH))

from flask import Flask, jsonify, request

MODEL_PATH = Path(__file__).resolve().parent / "models" / "exam_predictor.json"


def load_model() -> dict[str, Any]:
    if not MODEL_PATH.exists():
        return {
            "model_name": "exam_prediction_untrained",
            "version": "awaiting-real-data",
            "algorithm": "not_trained",
            "minimum_attempts": 5,
            "trained": False,
        }
    return json.loads(MODEL_PATH.read_text(encoding="utf-8"))


MODEL = load_model()
app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 15 * 1024 * 1024
ADVANCED_DIR = Path(__file__).resolve().parent / "models" / "advanced"
ADVANCED = {}
WHISPER_MODEL = None

try:
    import joblib
    import numpy as np
    import tensorflow as tf
    from xgboost import XGBClassifier, XGBRegressor

    profile_metadata = json.loads((ADVANCED_DIR / "learner_profile_metadata.json").read_text(encoding="utf-8"))
    bandit_metadata = json.loads((ADVANCED_DIR / "bandit_metadata.json").read_text(encoding="utf-8"))
    xgb_metadata = json.loads((ADVANCED_DIR / "xgboost_metadata.json").read_text(encoding="utf-8"))
    xgb_regressor = XGBRegressor()
    xgb_regressor.load_model(ADVANCED_DIR / "xgboost_exam_score.json")
    xgb_classifier = XGBClassifier()
    xgb_classifier.load_model(ADVANCED_DIR / "xgboost_risk.json")
    ADVANCED = {
        "np": np,
        "profile_encoder": tf.keras.models.load_model(ADVANCED_DIR / "learner_encoder.keras"),
        "profile_scaler": joblib.load(ADVANCED_DIR / "learner_scaler.joblib"),
        "profile_clusters": joblib.load(ADVANCED_DIR / "learner_clusters.joblib"),
        "profile_metadata": profile_metadata,
        "bandit_model": tf.keras.models.load_model(ADVANCED_DIR / "bandit_reward.keras"),
        "bandit_scaler": joblib.load(ADVANCED_DIR / "bandit_scaler.joblib"),
        "bandit_metadata": bandit_metadata,
        "xgb_regressor": xgb_regressor,
        "xgb_classifier": xgb_classifier,
        "xgb_metadata": xgb_metadata,
    }
except (ImportError, OSError, ValueError, FileNotFoundError) as advanced_error:
    print(f"Advanced models unavailable; using baseline artifacts: {advanced_error}")

FEATURE_LABELS = {
    "avg_score": "Overall quiz performance",
    "recent_avg": "Recent quiz performance",
    "trend": "Performance trend",
    "pass_rate": "Quiz pass rate",
    "attempt_count_log": "Practice volume",
    "avg_time_minutes": "Quiz completion time",
    "mastery": "Topic mastery",
    "topic_completion": "Curriculum completion",
    "login_count_log": "Platform activity",
    "current_streak": "Learning consistency",
}


def bounded_prediction(features: dict[str, Any]) -> tuple[float, dict[str, float]]:
    value = float(MODEL["bias"])
    contributions: dict[str, float] = {}
    for index, name in enumerate(MODEL["features"]):
        if name not in features:
            raise ValueError(f"Missing feature: {name}")
        standard = (float(features[name]) - float(MODEL["means"][index])) / max(
            0.000001, float(MODEL["stds"][index])
        )
        effect = float(MODEL["weights"][index]) * standard
        value += effect
        contributions[name] = effect
    return round(max(0.0, min(100.0, value)), 1), contributions


def bece_grade(score: float) -> str:
    for minimum, grade in ((90, "1"), (80, "2"), (70, "3"), (60, "4"), (55, "5"), (50, "6"), (40, "7"), (35, "8")):
        if score >= minimum:
            return grade
    return "9"


@app.get("/health")
@app.get("/api/v1/health")
def health():
    whisper_installed = False
    try:
        import importlib.util
        whisper_installed = importlib.util.find_spec("whisper") is not None
    except ImportError:
        pass
    active_model = ADVANCED.get("xgb_metadata", {})
    return jsonify(
        status="ok",
        service="edutrack-ml",
        model=active_model.get("model_name", MODEL["model_name"]),
        version=active_model.get("version", MODEL["version"]),
        algorithm="xgboost_regressor_and_classifier" if active_model else MODEL["algorithm"],
        minimum_attempts=MODEL["minimum_attempts"],
        advanced_models={
            "learner_profile": bool(ADVANCED.get("profile_encoder")),
            "contextual_bandit": bool(ADVANCED.get("bandit_model")),
            "xgboost_prediction": bool(ADVANCED.get("xgb_regressor")),
        },
        accessibility={"whisper_installed": whisper_installed, "fine_tuned": False},
    )


@app.post("/predict")
@app.post("/api/v1/predict")
def predict():
    payload = request.get_json(silent=True) or {}
    features = payload.get("features") or {}
    attempts = int(payload.get("attempt_count", 0))
    minimum = int(MODEL["minimum_attempts"])
    topic_completion = float(features.get("topic_completion", 0))
    if not MODEL.get("trained", True) and not ADVANCED.get("xgb_regressor"):
        return jsonify(
            available=False,
            score=None,
            grade=None,
            confidence=0,
            risk_level="insufficient_data",
            attempts_needed=0,
            factors=[],
            data_message="The prediction model is awaiting enough real training data.",
            model_version=MODEL["version"],
            inference_source="untrained",
        )
    enough_evidence = attempts >= minimum and (attempts >= 10 or topic_completion >= 10)
    if not enough_evidence:
        return jsonify(
            available=False,
            score=None,
            grade=None,
            confidence=0,
            risk_level="insufficient_data",
            attempts_needed=max(0, minimum - attempts),
            data_message=(
                "Complete more quizzes to build a reliable forecast."
                if attempts < minimum
                else "Complete 10 quizzes or 10% of your curriculum topics to unlock a forecast."
            ),
            factors=[],
            model_version=MODEL["version"],
            inference_source="flask_service",
        )
    try:
        if ADVANCED.get("xgb_regressor"):
            names = ADVANCED["xgb_metadata"]["features"]
            row = ADVANCED["np"].asarray([[float(features[name]) for name in names]], dtype="float32")
            score = round(max(0.0, min(100.0, float(ADVANCED["xgb_regressor"].predict(row)[0]))), 1)
            importances = ADVANCED["xgb_metadata"]["feature_importance"]
            contributions = {name: float(importances.get(name, 0)) for name in names}
            model_version = ADVANCED["xgb_metadata"]["version"]
            inference_source = "flask_xgboost"
        else:
            score, contributions = bounded_prediction(features)
            model_version = MODEL["version"]
            inference_source = "flask_ridge_fallback"
    except (TypeError, ValueError) as error:
        return jsonify(error=str(error)), 400

    strongest = sorted(contributions.items(), key=lambda item: abs(item[1]), reverse=True)[:4]
    factors = [
        {
            "name": FEATURE_LABELS.get(name, name),
            "direction": "positive" if effect >= 0 else "negative",
            "effect": round(abs(effect), 1),
        }
        for name, effect in strongest
    ]
    metrics = ADVANCED.get("xgb_metadata", {}).get("metrics", MODEL.get("metrics", {}))
    rmse = float(metrics.get("rmse", 15))
    confidence = round(max(35, min(95, 100 - rmse * 4 + min(12, (attempts - minimum) * 1.5))), 1)
    return jsonify(
        available=True,
        score=score,
        grade=bece_grade(score),
        confidence=confidence,
        risk_level="high" if score < 50 else ("medium" if score < 60 else "low"),
        attempts_needed=0,
        factors=factors,
        model_version=model_version,
        inference_source=inference_source,
    )


@app.post("/api/v1/profile")
def profile():
    payload = request.get_json(silent=True) or {}
    features = payload.get("features") or {}
    if ADVANCED.get("profile_encoder"):
        names = ADVANCED["profile_metadata"]["features"]
        row = ADVANCED["np"].asarray([[float(features.get(name, 0)) for name in names]], dtype="float32")
        scaled = ADVANCED["profile_scaler"].transform(row)
        embedding = ADVANCED["profile_encoder"].predict(scaled, verbose=0)
        cluster = int(ADVANCED["profile_clusters"].predict(embedding)[0])
        segment = ADVANCED["profile_metadata"]["cluster_labels"][str(cluster)]
        return jsonify(
            segment=segment,
            embedding=[round(float(value), 5) for value in embedding[0]],
            model_version=ADVANCED["profile_metadata"]["version"],
            inference_source="flask_tensorflow",
        )
    mastery = float(features.get("mastery", 0))
    recent = float(features.get("recent_avg", 0))
    trend = float(features.get("trend", 0))
    if mastery >= 75 and recent >= 70:
        segment = "mastering"
    elif trend >= 5:
        segment = "improving"
    elif recent and recent < 50:
        segment = "needs_support"
    else:
        segment = "developing"
    return jsonify(segment=segment, features=features, model_version=MODEL["version"], inference_source="rule_fallback")


@app.post("/api/v1/recommendations")
def recommendations():
    payload = request.get_json(silent=True) or {}
    learner = payload.get("learner") or {}
    candidates = payload.get("candidates") or []
    preferred = learner.get("preferred_difficulty", "easy")
    high_risk = learner.get("risk_level") == "high"
    target_mastery = max(0.5, min(0.95, float(learner.get("target_mastery", 70)) / 100.0))
    context = learner.get("features") or {}
    ranked = []
    for topic in candidates:
        mastery = max(0.0, min(1.0, float(topic.get("mastery_level", 0))))
        quiz_score = topic.get("avg_quiz_score")
        status = topic.get("progress_status", "not_started")
        if status == "completed" and mastery >= 0.75 and (quiz_score is None or float(quiz_score) >= 70):
            continue
        need = 1 - mastery
        goal_gap = max(0.0, target_mastery - mastery)
        status_boost = 0.25 if status == "in_progress" else 0.12
        weakness = max(0.0, (70 - float(quiz_score)) / 100) if quiz_score is not None else 0.08
        difficulty_fit = 0.12 if topic.get("difficulty") == preferred else 0.04
        if ADVANCED.get("bandit_model"):
            names = ADVANCED["bandit_metadata"]["context_features"]
            context_values = [float(context.get(name, 0)) for name in names]
            subject_values = [1.0 if int(topic.get("subject_id", 0)) == index else 0.0 for index in range(1, 9)]
            difficulty_values = [
                1.0 if topic.get("difficulty") == "easy" else 0.0,
                1.0 if topic.get("difficulty") == "medium" else 0.0,
                1.0 if topic.get("difficulty") == "hard" else 0.0,
            ]
            sequence_value = min(1.0, max(0.0, float(topic.get("sequence_order", 0)) / 20.0))
            row = ADVANCED["np"].asarray([context_values + subject_values + difficulty_values + [sequence_value]], dtype="float32")
            scaled = ADVANCED["bandit_scaler"].transform(row)
            expected_reward = float(ADVANCED["bandit_model"].predict(scaled, verbose=0)[0][0])
            score = expected_reward + need * 0.08 + goal_gap * 0.18 + status_boost * 0.10
        else:
            score = need * 0.35 + goal_gap * 0.25 + status_boost + weakness * 0.25 + difficulty_fit + (0.08 if high_risk else 0)
        reason = (
            "Continue this topic to complete your current learning sequence"
            if status == "in_progress"
            else f"Recommended because your previous score was {round(float(quiz_score))}%"
            if quiz_score is not None and float(quiz_score) < 60
            else f"Recommended to help close your mastery gap toward {round(target_mastery * 100)}%"
        )
        ranked.append({"topic_id": int(topic["id"]), "score": round(score * 100, 2), "reason": reason})
    ranked.sort(key=lambda item: item["score"], reverse=True)
    return jsonify(
        recommendations=ranked[: max(1, min(10, int(payload.get("limit", 5))))],
        model_version=ADVANCED.get("bandit_metadata", {}).get("version", "tensorflow-bandit-service-v1"),
        inference_source="flask_tensorflow_bandit" if ADVANCED.get("bandit_model") else "flask_adaptive_fallback",
    )


def get_whisper_model():
    global WHISPER_MODEL
    if WHISPER_MODEL is None:
        import imageio_ffmpeg
        import whisper

        ffmpeg_source = Path(imageio_ffmpeg.get_ffmpeg_exe())
        ffmpeg_directory = Path(__file__).resolve().parent / "bin"
        ffmpeg_directory.mkdir(parents=True, exist_ok=True)
        ffmpeg_target = ffmpeg_directory / "ffmpeg.exe"
        if not ffmpeg_target.exists():
            shutil.copy2(ffmpeg_source, ffmpeg_target)
        os.environ["PATH"] = str(ffmpeg_directory) + os.pathsep + os.environ.get("PATH", "")
        model_name = os.environ.get("EDUTRACK_WHISPER_MODEL", "base")
        model_root = Path(__file__).resolve().parent / "models" / "whisper"
        model_root.mkdir(parents=True, exist_ok=True)
        WHISPER_MODEL = whisper.load_model(model_name, download_root=str(model_root))
    return WHISPER_MODEL


@app.post("/api/v1/transcribe")
def transcribe():
    audio = request.files.get("audio")
    if audio is None or not audio.filename:
        return jsonify(error="An audio file is required."), 400
    suffix = Path(audio.filename).suffix.lower()
    if suffix not in {".wav", ".mp3", ".m4a", ".mp4", ".webm", ".ogg"}:
        return jsonify(error="Unsupported audio format."), 415
    temporary_path = None
    try:
        with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as temporary:
            audio.save(temporary)
            temporary_path = temporary.name
        result = get_whisper_model().transcribe(
            temporary_path,
            language=request.form.get("language") or None,
            task="transcribe",
            fp16=False,
            initial_prompt="Ghanaian junior high school lesson, Ghana Education Service curriculum.",
        )
        return jsonify(
            text=(result.get("text") or "").strip(),
            language=result.get("language"),
            model=os.environ.get("EDUTRACK_WHISPER_MODEL", "base"),
            fine_tuned=False,
            inference_source="whisper_pretrained_multilingual",
        )
    except Exception as error:
        app.logger.exception("Whisper transcription failed")
        return jsonify(error=str(error)), 500
    finally:
        if temporary_path:
            Path(temporary_path).unlink(missing_ok=True)


def main() -> None:
    parser = argparse.ArgumentParser(description="Run the EduTrack Flask ML service")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=5000)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    if args.check:
        print(f"Flask service ready: {MODEL['model_name']} v{MODEL['version']}")
        return
    if ADVANCED:
        names = ADVANCED["profile_metadata"]["features"]
        zeros = ADVANCED["np"].zeros((1, len(names)), dtype="float32")
        ADVANCED["profile_encoder"].predict(zeros, verbose=0)
        bandit_width = int(ADVANCED["bandit_scaler"].n_features_in_)
        ADVANCED["bandit_model"].predict(ADVANCED["np"].zeros((1, bandit_width), dtype="float32"), verbose=0)
    print(f"EduTrack Flask ML service: http://{args.host}:{args.port}")
    app.run(host=args.host, port=args.port, debug=False)


if __name__ == "__main__":
    main()
