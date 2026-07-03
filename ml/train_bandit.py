"""Train a TensorFlow offline contextual-bandit reward model."""

from __future__ import annotations

import json
import os
from datetime import datetime, timezone

os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")

import joblib
import numpy as np
import tensorflow as tf
from sklearn.metrics import mean_absolute_error, mean_squared_error
from sklearn.model_selection import GroupShuffleSplit
from sklearn.preprocessing import StandardScaler

from advanced_common import MODEL_DIR, action_vector, feature_matrix, load_payload

tf.keras.utils.set_random_seed(42)


def main() -> None:
    payload = load_payload()
    rows = payload["bandit_events"]
    names = payload["feature_names"]
    if len(rows) < 80:
        raise SystemExit("At least 80 logged recommendation events are required.")
    contexts = feature_matrix(rows, names, "context")
    actions = np.asarray(
        [action_vector(int(row["action_subject_id"]), row["action_difficulty"], int(row["action_sequence_order"])) for row in rows],
        dtype=np.float32,
    )
    x = np.concatenate([contexts, actions], axis=1)
    y = np.asarray([float(row["reward"]) for row in rows], dtype=np.float32)
    groups = np.asarray([int(row["student_id"]) for row in rows])
    train_idx, test_idx = next(GroupShuffleSplit(test_size=0.25, random_state=42).split(x, y, groups))
    scaler = StandardScaler().fit(x[train_idx])
    x_train = scaler.transform(x[train_idx]).astype(np.float32)
    x_test = scaler.transform(x[test_idx]).astype(np.float32)

    model = tf.keras.Sequential([
        tf.keras.layers.Input(shape=(x.shape[1],)),
        tf.keras.layers.Dense(32, activation="relu"),
        tf.keras.layers.Dropout(0.15),
        tf.keras.layers.Dense(16, activation="relu"),
        tf.keras.layers.Dense(1, activation="linear"),
    ], name="contextual_bandit_reward_model")
    model.compile(optimizer=tf.keras.optimizers.Adam(0.003), loss="mse", metrics=["mae"])
    model.fit(
        x_train, y[train_idx], epochs=120, batch_size=24, validation_split=0.2, verbose=0,
        callbacks=[tf.keras.callbacks.EarlyStopping(patience=12, restore_best_weights=True)],
    )
    predicted = model.predict(x_test, verbose=0).reshape(-1)
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    model.save(MODEL_DIR / "bandit_reward.keras")
    joblib.dump(scaler, MODEL_DIR / "bandit_scaler.joblib")
    metadata = {
        "model_name": "tensorflow_contextual_bandit",
        "version": datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S"),
        "framework": f"TensorFlow {tf.__version__}",
        "algorithm": "offline_contextual_bandit_reward_model",
        "context_features": names,
        "action_features": ["subject_one_hot_1_8", "difficulty_one_hot", "sequence_order"],
        "training_events": int(len(train_idx)),
        "test_events": int(len(test_idx)),
        "metrics": {
            "mae": float(mean_absolute_error(y[test_idx], predicted)),
            "rmse": float(np.sqrt(mean_squared_error(y[test_idx], predicted))),
        },
        "reward": "score + score improvement + pass completion bonus",
        "status": "prototype",
        "trained_at": datetime.now(timezone.utc).isoformat(),
    }
    (MODEL_DIR / "bandit_metadata.json").write_text(json.dumps(metadata, indent=2), encoding="utf-8")
    print(json.dumps(metadata, indent=2))


if __name__ == "__main__":
    main()
