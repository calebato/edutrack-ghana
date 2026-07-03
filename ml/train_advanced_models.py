"""Run all data-backed advanced EduTrack training pipelines."""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent

for script in ("train_learner_profile.py", "train_bandit.py", "train_xgboost.py"):
    print(f"\n=== {script} ===", flush=True)
    subprocess.run([sys.executable, str(ROOT / script)], check=True, cwd=ROOT)

print("\nAll advanced model artifacts were trained successfully.")
