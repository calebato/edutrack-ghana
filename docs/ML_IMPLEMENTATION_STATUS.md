# Submitted ML Architecture: Implementation Status

The submitted architecture is implemented as a research prototype. The following wording is defensible:

> EduTrack integrates a TensorFlow neural learner profiler, a TensorFlow offline contextual-bandit recommender, XGBoost performance and risk models, automated teacher and parent reporting, and local multilingual Whisper transcription. Models are versioned behind a Flask API with PHP fallbacks. Current predictive artifacts are prototype models because the pilot dataset contains 21 learners and no verified final-exam labels. Whisper currently uses pretrained multilingual weights; a consent-aware Ghanaian-accent fine-tuning pipeline is prepared but awaits a sufficiently large labelled audio dataset.

Do not state that Whisper is fine-tuned until a trained checkpoint and speaker-separated word-error-rate evaluation exist. Do not present XGBoost output as a validated final-exam result until verified outcomes are collected.
