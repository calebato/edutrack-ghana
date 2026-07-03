"""Validate and split a consented Ghanaian-accent Whisper dataset manifest."""

from __future__ import annotations

import argparse
import json
import random
from collections import defaultdict
from pathlib import Path


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("manifest", type=Path, help="JSONL with audio, transcript, speaker_id, accent_region, consent")
    parser.add_argument("--output", type=Path, default=Path(__file__).resolve().parent / "audio" / "prepared")
    args = parser.parse_args()
    rows = []
    for line_number, line in enumerate(args.manifest.read_text(encoding="utf-8").splitlines(), 1):
        row = json.loads(line)
        required = {"audio", "transcript", "speaker_id", "accent_region", "consent"}
        missing = required - row.keys()
        if missing:
            raise SystemExit(f"Line {line_number}: missing {sorted(missing)}")
        if row["consent"] is not True:
            raise SystemExit(f"Line {line_number}: explicit consent is required")
        audio = (args.manifest.parent / row["audio"]).resolve()
        if not audio.is_file() or not str(audio).startswith(str(args.manifest.parent.resolve())):
            raise SystemExit(f"Line {line_number}: invalid audio path")
        if not str(row["transcript"]).strip():
            raise SystemExit(f"Line {line_number}: transcript is empty")
        row["audio"] = str(audio)
        rows.append(row)
    speakers = defaultdict(list)
    for row in rows:
        speakers[str(row["speaker_id"])].append(row)
    speaker_ids = list(speakers)
    random.Random(42).shuffle(speaker_ids)
    validation_count = max(1, round(len(speaker_ids) * 0.2))
    validation_speakers = set(speaker_ids[:validation_count])
    train = [row for row in rows if str(row["speaker_id"]) not in validation_speakers]
    validation = [row for row in rows if str(row["speaker_id"]) in validation_speakers]
    args.output.mkdir(parents=True, exist_ok=True)
    for name, split in (("train", train), ("validation", validation)):
        (args.output / f"{name}.jsonl").write_text("\n".join(json.dumps(row) for row in split), encoding="utf-8")
    summary = {"recordings": len(rows), "speakers": len(speakers), "train": len(train), "validation": len(validation), "accent_regions": sorted({row["accent_region"] for row in rows})}
    (args.output / "dataset_summary.json").write_text(json.dumps(summary, indent=2), encoding="utf-8")
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()
