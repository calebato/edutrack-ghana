"""Fine-tune Whisper after a validated Ghanaian-accent dataset is prepared.

This command intentionally refuses to run on a tiny or speaker-poor dataset.
It requires ml/audio/prepared/train.jsonl and validation.jsonl produced by
prepare_whisper_dataset.py.
"""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PREPARED = ROOT / "audio" / "prepared"
SUMMARY = PREPARED / "dataset_summary.json"

if not SUMMARY.exists():
    raise SystemExit("Prepare a consented audio manifest before fine-tuning.")
summary = json.loads(SUMMARY.read_text(encoding="utf-8"))
if summary.get("recordings", 0) < 500 or summary.get("speakers", 0) < 20:
    raise SystemExit("Fine-tuning requires at least 500 recordings from 20 speaker-separated participants.")

try:
    from datasets import Audio, load_dataset
    from transformers import (
        Seq2SeqTrainer,
        Seq2SeqTrainingArguments,
        WhisperFeatureExtractor,
        WhisperForConditionalGeneration,
        WhisperProcessor,
        WhisperTokenizer,
    )
except ImportError as error:
    raise SystemExit("Install requirements-whisper-training.txt first.") from error

MODEL_ID = "openai/whisper-small"
OUTPUT = ROOT / "models" / "whisper" / "ghanaian-accent-small"
feature_extractor = WhisperFeatureExtractor.from_pretrained(MODEL_ID)
tokenizer = WhisperTokenizer.from_pretrained(MODEL_ID, task="transcribe")
processor = WhisperProcessor.from_pretrained(MODEL_ID, task="transcribe")
model = WhisperForConditionalGeneration.from_pretrained(MODEL_ID)
model.generation_config.task = "transcribe"

dataset = load_dataset(
    "json",
    data_files={"train": str(PREPARED / "train.jsonl"), "validation": str(PREPARED / "validation.jsonl")},
).cast_column("audio", Audio(sampling_rate=16000))


def prepare(batch):
    audio = batch["audio"]
    batch["input_features"] = feature_extractor(audio["array"], sampling_rate=audio["sampling_rate"]).input_features[0]
    batch["labels"] = tokenizer(batch["transcript"]).input_ids
    return batch


dataset = dataset.map(prepare, remove_columns=dataset["train"].column_names, num_proc=1)


class SpeechCollator:
    def __call__(self, features):
        inputs = processor.feature_extractor.pad(
            [{"input_features": item["input_features"]} for item in features], return_tensors="pt"
        )
        labels = processor.tokenizer.pad(
            [{"input_ids": item["labels"]} for item in features], return_tensors="pt"
        )
        labels_ids = labels["input_ids"].masked_fill(labels.attention_mask.ne(1), -100)
        inputs["labels"] = labels_ids
        return inputs


arguments = Seq2SeqTrainingArguments(
    output_dir=str(OUTPUT),
    per_device_train_batch_size=4,
    gradient_accumulation_steps=4,
    learning_rate=1e-5,
    warmup_steps=100,
    max_steps=2000,
    gradient_checkpointing=True,
    fp16=False,
    eval_strategy="steps",
    eval_steps=200,
    save_steps=200,
    predict_with_generate=True,
    generation_max_length=225,
    logging_steps=25,
    report_to=[],
    load_best_model_at_end=True,
)
trainer = Seq2SeqTrainer(
    model=model,
    args=arguments,
    train_dataset=dataset["train"],
    eval_dataset=dataset["validation"],
    data_collator=SpeechCollator(),
    processing_class=processor,
)
trainer.train()
trainer.save_model(str(OUTPUT))
processor.save_pretrained(str(OUTPUT))
print(f"Fine-tuned model saved to {OUTPUT}")
