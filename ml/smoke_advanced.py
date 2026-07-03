"""Exercise advanced Flask endpoints without opening a network port."""

from __future__ import annotations

from advanced_common import load_payload
from ml_service import app

payload = load_payload()
profile = payload["profile_samples"][-1]["features"]
topics = payload["topic_catalog"][:12]
candidates = [
    {
        "id": int(topic["id"]),
        "subject_id": int(topic["subject_id"]),
        "sequence_order": int(topic["sequence_order"]),
        "difficulty": topic["difficulty"],
        "mastery_level": float(topic["global_mastery"]),
        "avg_quiz_score": None,
        "progress_status": "not_started",
    }
    for topic in topics
]

with app.test_client() as client:
    health = client.get("/api/v1/health")
    assert health.status_code == 200, health.text
    prediction = client.post("/api/v1/predict", json={"attempt_count": 8, "features": profile})
    assert prediction.status_code == 200, prediction.text
    learner_profile = client.post("/api/v1/profile", json={"features": profile})
    assert learner_profile.status_code == 200, learner_profile.text
    recommendations = client.post(
        "/api/v1/recommendations",
        json={
            "learner": {"features": profile, "preferred_difficulty": "medium", "risk_level": "low"},
            "candidates": candidates,
            "limit": 5,
        },
    )
    assert recommendations.status_code == 200, recommendations.text
    print("health", health.get_json())
    print("prediction", prediction.get_json())
    print("profile", learner_profile.get_json())
    print("recommendations", recommendations.get_json())
