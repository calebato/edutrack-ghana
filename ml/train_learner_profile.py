"""Train a TensorFlow autoencoder and clustering layer for learner profiles."""

from __future__ import annotations

import json
import os
from datetime import datetime, timezone

os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")

import joblib
import numpy as np
import tensorflow as tf
from sklearn.cluster import KMeans
from sklearn.metrics import mean_squared_error, silhouette_score
from sklearn.preprocessing import StandardScaler

from advanced_common import MODEL_DIR, feature_matrix, load_payload

tf.keras.utils.set_random_seed(42)


def main() -> None:
    payload = load_payload()
    rows = payload["profile_samples"]
    names = payload["feature_names"]
    if len(rows) < 50:
        raise SystemExit("At least 50 interaction samples are required.")
    x = feature_matrix(rows, names, "features")
    scaler = StandardScaler()
    x_scaled = scaler.fit_transform(x).astype(np.float32)

    inputs = tf.keras.Input(shape=(len(names),), name="learner_features")
    hidden = tf.keras.layers.Dense(16, activation="relu")(inputs)
    hidden = tf.keras.layers.Dropout(0.10)(hidden)
    embedding = tf.keras.layers.Dense(4, activation="linear", name="learner_embedding")(hidden)
    decoded = tf.keras.layers.Dense(16, activation="relu")(embedding)
    outputs = tf.keras.layers.Dense(len(names), name="reconstruction")(decoded)
    autoencoder = tf.keras.Model(inputs, outputs, name="learner_profile_autoencoder")
    encoder = tf.keras.Model(inputs, embedding, name="learner_profile_encoder")
    autoencoder.compile(optimizer=tf.keras.optimizers.Adam(0.005), loss="mse")
    history = autoencoder.fit(
        x_scaled,
        x_scaled,
        epochs=100,
        batch_size=24,
        validation_split=0.2,
        verbose=0,
        callbacks=[tf.keras.callbacks.EarlyStopping(patience=12, restore_best_weights=True)],
    )
    embeddings = encoder.predict(x_scaled, verbose=0)
    clusters = KMeans(n_clusters=4, random_state=42, n_init=20).fit(embeddings)

    cluster_scores = {}
    for cluster_id in range(4):
        member_indices = np.where(clusters.labels_ == cluster_id)[0]
        score_index = names.index("recent_avg")
        mastery_index = names.index("mastery")
        trend_index = names.index("trend")
        cluster_scores[cluster_id] = float(
            np.mean(x[member_indices, score_index]) * 0.50
            + np.mean(x[member_indices, mastery_index]) * 0.35
            + np.mean(x[member_indices, trend_index]) * 0.15
        )
    ordered = sorted(cluster_scores, key=cluster_scores.get)
    labels = ["needs_support", "developing", "improving", "mastering"]
    cluster_labels = {str(cluster_id): labels[position] for position, cluster_id in enumerate(ordered)}

    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    encoder.save(MODEL_DIR / "learner_encoder.keras")
    joblib.dump(scaler, MODEL_DIR / "learner_scaler.joblib")
    joblib.dump(clusters, MODEL_DIR / "learner_clusters.joblib")
    reconstructed = autoencoder.predict(x_scaled, verbose=0)
    metadata = {
        "model_name": "tensorflow_learner_profile",
        "version": datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S"),
        "framework": f"TensorFlow {tf.__version__}",
        "algorithm": "dense_autoencoder_plus_kmeans",
        "features": names,
        "embedding_dimensions": 4,
        "cluster_labels": cluster_labels,
        "training_samples": len(rows),
        "metrics": {
            "reconstruction_mse": float(mean_squared_error(x_scaled, reconstructed)),
            "silhouette": float(silhouette_score(embeddings, clusters.labels_)),
            "validation_loss": float(min(history.history["val_loss"])),
        },
        "status": "prototype",
        "trained_at": datetime.now(timezone.utc).isoformat(),
    }
    (MODEL_DIR / "learner_profile_metadata.json").write_text(json.dumps(metadata, indent=2), encoding="utf-8")
    print(json.dumps(metadata, indent=2))


if __name__ == "__main__":
    main()
