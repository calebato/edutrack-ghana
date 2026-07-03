"""Train EduTrack's explainable performance model using only the Python standard library."""

from __future__ import annotations

import json
import math
import random
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DATA_PATH = ROOT / "training_data.json"
MODEL_PATH = ROOT / "models" / "exam_predictor.json"
FEATURES = [
    "avg_score",
    "recent_avg",
    "trend",
    "pass_rate",
    "attempt_count_log",
    "avg_time_minutes",
    "mastery",
    "topic_completion",
    "login_count_log",
    "current_streak",
]


def mean(values: list[float]) -> float:
    return sum(values) / len(values) if values else 0.0


def train_ridge(rows: list[dict]) -> dict:
    random.Random(42).shuffle(rows)
    split = max(1, int(len(rows) * 0.8))
    train_rows, test_rows = rows[:split], rows[split:] or rows[-1:]
    columns = [[float(row["features"][name]) for row in train_rows] for name in FEATURES]
    means = [mean(column) for column in columns]
    stds = [max(1e-6, math.sqrt(mean([(value - means[i]) ** 2 for value in column]))) for i, column in enumerate(columns)]

    def vector(row: dict) -> list[float]:
        return [(float(row["features"][name]) - means[i]) / stds[i] for i, name in enumerate(FEATURES)]

    x_train = [vector(row) for row in train_rows]
    y_train = [float(row["target"]) for row in train_rows]
    weights = [0.0] * len(FEATURES)
    bias = mean(y_train)
    learning_rate = 0.02
    l2 = 0.015

    for _ in range(5000):
        grad_w = [0.0] * len(weights)
        grad_b = 0.0
        for features, target in zip(x_train, y_train):
            error = bias + sum(w * value for w, value in zip(weights, features)) - target
            grad_b += error
            for i, value in enumerate(features):
                grad_w[i] += error * value
        scale = 2.0 / len(x_train)
        bias -= learning_rate * scale * grad_b
        for i in range(len(weights)):
            weights[i] -= learning_rate * (scale * grad_w[i] + 2 * l2 * weights[i])

    def predict(row: dict) -> float:
        return max(0.0, min(100.0, bias + sum(w * value for w, value in zip(weights, vector(row)))))

    predictions = [predict(row) for row in test_rows]
    actual = [float(row["target"]) for row in test_rows]
    mae = mean([abs(p - y) for p, y in zip(predictions, actual)])
    rmse = math.sqrt(mean([(p - y) ** 2 for p, y in zip(predictions, actual)]))
    baseline = mean(actual)
    denominator = sum((value - baseline) ** 2 for value in actual)
    r2 = 1 - (sum((p - y) ** 2 for p, y in zip(predictions, actual)) / denominator) if denominator else 0.0

    return {
        "model_name": "exam_performance_predictor",
        "version": datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S"),
        "algorithm": "ridge_linear_regression_gradient_descent",
        "target": "future_assessed_performance",
        "minimum_attempts": 3,
        "features": FEATURES,
        "means": means,
        "stds": stds,
        "weights": weights,
        "bias": bias,
        "metrics": {"mae": mae, "rmse": rmse, "r2": r2},
        "training_samples": len(train_rows),
        "test_samples": len(test_rows),
        "trained_at": datetime.now(timezone.utc).isoformat(),
        "limitations": "Projects future assessed performance from EduTrack activity; replace proxy targets with verified final_exam_results when available.",
    }


def main() -> None:
    payload = json.loads(DATA_PATH.read_text(encoding="utf-8"))
    rows = payload.get("samples", [])
    if len(rows) < 12:
        raise SystemExit(f"Need at least 12 temporal samples; found {len(rows)}")
    model = train_ridge(rows)
    MODEL_PATH.parent.mkdir(parents=True, exist_ok=True)
    MODEL_PATH.write_text(json.dumps(model, indent=2), encoding="utf-8")
    print(f"Trained {model['model_name']} on {model['training_samples']} samples")
    print(f"MAE={model['metrics']['mae']:.2f} RMSE={model['metrics']['rmse']:.2f} R2={model['metrics']['r2']:.3f}")
    print(f"Saved {MODEL_PATH}")


if __name__ == "__main__":
    main()
