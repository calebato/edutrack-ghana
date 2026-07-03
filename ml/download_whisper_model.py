"""Download and register the multilingual Whisper accessibility baseline."""

from __future__ import annotations

import json
import os
from datetime import datetime, timezone
from pathlib import Path

import imageio_ffmpeg
import whisper

ROOT = Path(__file__).resolve().parent
MODEL_ROOT = ROOT / "models" / "whisper"
MODEL_NAME = os.environ.get("EDUTRACK_WHISPER_MODEL", "base")
os.environ["PATH"] = str(Path(imageio_ffmpeg.get_ffmpeg_exe()).parent) + os.pathsep + os.environ.get("PATH", "")
MODEL_ROOT.mkdir(parents=True, exist_ok=True)
whisper.load_model(MODEL_NAME, download_root=str(MODEL_ROOT))
metadata = {
    "model_name": "whisper_accessibility",
    "version": f"openai-whisper-{whisper.__version__}-{MODEL_NAME}",
    "algorithm": "multilingual_transformer_speech_recognition",
    "base_model": MODEL_NAME,
    "fine_tuned": False,
    "training_samples": 0,
    "status": "pretrained_baseline",
    "limitations": "Not fine-tuned for Ghanaian accents; requires consented transcribed audio and speaker-separated evaluation.",
    "installed_at": datetime.now(timezone.utc).isoformat(),
}
(MODEL_ROOT / "metadata.json").write_text(json.dumps(metadata, indent=2), encoding="utf-8")
print(json.dumps(metadata, indent=2))
