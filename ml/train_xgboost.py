"""Train grouped XGBoost score-regression and risk-classification models."""

from __future__ import annotations

import json
from datetime import datetime, timezone

import numpy as np
from sklearn.metrics import accuracy_score, mean_absolute_error, mean_squared_error, r2_score
from sklearn.model_selection import GroupShuffleSplit
from xgboost import XGBClassifier, XGBRegressor

from advanced_common import MODEL_DIR, feature_matrix, load_payload


def main() -> None:
    payload = load_payload()
    rows = payload["prediction_samples"]
    names = payload["feature_names"]
    if len(rows) < 80:
        raise SystemExit("At least 80 temporal prediction samples are required.")
    x = feature_matrix(rows, names, "features")
    y = np.asarray([float(row["target_score"]) for row in rows], dtype=np.float32)
    groups = np.asarray([int(row["student_id"]) for row in rows])
    train_idx, test_idx = next(GroupShuffleSplit(test_size=0.25, random_state=42).split(x, y, groups))

    regressor = XGBRegressor(
        n_estimators=180, max_depth=3, learning_rate=0.035, subsample=0.85,
        colsample_bytree=0.85, reg_lambda=2.0, objective="reg:squarederror", random_state=42,
    )
    regressor.fit(x[train_idx], y[train_idx])
    score_predictions = np.clip(regressor.predict(x[test_idx]), 0, 100)

    risk_y = (y < 50).astype(np.int32)
    classifier = XGBClassifier(
        n_estimators=140, max_depth=3, learning_rate=0.04, subsample=0.85,
        colsample_bytree=0.85, reg_lambda=2.0, objective="binary:logistic",
        eval_metric="logloss", random_state=42,
    )
    classifier.fit(x[train_idx], risk_y[train_idx])
    risk_predictions = classifier.predict(x[test_idx])

    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    regressor.save_model(MODEL_DIR / "xgboost_exam_score.json")
    classifier.save_model(MODEL_DIR / "xgboost_risk.json")
    metadata = {
        "model_name": "xgboost_exam_prediction",
        "version": datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S"),
        "framework": "XGBoost",
        "algorithm": "gradient_boosted_trees_regression_and_classification",
        "features": names,
        "training_samples": int(len(train_idx)),
        "test_samples": int(len(test_idx)),
        "verified_exam_labels": int(payload["limitations"]["exam_labels"]),
        "target": "verified final exam where available; otherwise future-assessment proxy",
        "metrics": {
            "mae": float(mean_absolute_error(y[test_idx], score_predictions)),
            "rmse": float(np.sqrt(mean_squared_error(y[test_idx], score_predictions))),
            "r2": float(r2_score(y[test_idx], score_predictions)),
            "risk_accuracy": float(accuracy_score(risk_y[test_idx], risk_predictions)),
        },
        "feature_importance": {name: float(value) for name, value in zip(names, regressor.feature_importances_)},
        "status": "prototype" if int(payload["limitations"]["exam_labels"]) < 100 else "validated_candidate",
        "trained_at": datetime.now(timezone.utc).isoformat(),
    }
    (MODEL_DIR / "xgboost_metadata.json").write_text(json.dumps(metadata, indent=2), encoding="utf-8")
    print(json.dumps(metadata, indent=2))


if __name__ == "__main__":
    main()
