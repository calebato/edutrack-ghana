"""Shared data helpers for EduTrack's advanced research models."""

from __future__ import annotations

import json
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parent
DATA_PATH = ROOT / "advanced_training_data.json"
MODEL_DIR = ROOT / "models" / "advanced"


def load_payload() -> dict:
    if not DATA_PATH.exists():
        raise RuntimeError("Run export_advanced_training_data.php first.")
    return json.loads(DATA_PATH.read_text(encoding="utf-8"))


def feature_matrix(rows: list[dict], feature_names: list[str], key: str) -> np.ndarray:
    return np.asarray(
        [[float(row[key][name]) for name in feature_names] for row in rows],
        dtype=np.float32,
    )


def action_vector(subject_id: int, difficulty: str, sequence_order: int) -> list[float]:
    subjects = [1.0 if subject_id == index else 0.0 for index in range(1, 9)]
    difficulties = [
        1.0 if difficulty == "easy" else 0.0,
        1.0 if difficulty == "medium" else 0.0,
        1.0 if difficulty == "hard" else 0.0,
    ]
    return subjects + difficulties + [min(1.0, max(0.0, sequence_order / 20.0))]
