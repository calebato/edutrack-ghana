"""Exercise the installed Whisper model with a local WAV fixture."""

from pathlib import Path

from ml_service import app

fixture = Path(__file__).resolve().parent / "test_voice.wav"
if not fixture.exists():
    raise SystemExit("Create test_voice.wav before running this smoke test.")

with fixture.open("rb") as audio, app.test_client() as client:
    response = client.post(
        "/api/v1/transcribe",
        data={"audio": (audio, "test_voice.wav"), "language": "en"},
        content_type="multipart/form-data",
    )
    assert response.status_code == 200, response.text
    result = response.get_json()
    assert result.get("text"), result
    print(result)
