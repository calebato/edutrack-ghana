<?php
require_once __DIR__ . '/../auth/auth.php';
requireStudent();
$pageTitle = 'Voice Accessibility';
$activeNav = 'accessibility';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card edu-card" style="max-width:760px">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div><h4 class="mb-1">Voice Transcription</h4><p class="text-muted mb-0">Ghanaian classroom speech support</p></div>
            <span class="badge bg-purple-soft text-purple">Whisper</span>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" class="btn btn-primary btn-edu" id="startRecording">Start recording</button>
            <button type="button" class="btn btn-outline-danger btn-edu" id="stopRecording" disabled>Stop</button>
            <select id="speechLanguage" class="form-select" style="max-width:190px" aria-label="Speech language">
                <option value="">Detect language</option>
                <option value="en">English</option>
                <option value="fr">French</option>
            </select>
        </div>
        <div id="recordingStatus" class="alert alert-light" role="status">Ready</div>
        <label for="transcript" class="form-label fw-bold">Transcript</label>
        <textarea id="transcript" class="form-control" rows="9"></textarea>
    </div>
</div>

<script>
let recorder;
let chunks = [];
const startButton = document.getElementById('startRecording');
const stopButton = document.getElementById('stopRecording');
const statusBox = document.getElementById('recordingStatus');

startButton.addEventListener('click', async () => {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        recorder = new MediaRecorder(stream);
        chunks = [];
        recorder.addEventListener('dataavailable', event => { if (event.data.size) chunks.push(event.data); });
        recorder.addEventListener('stop', async () => {
            recorder.stream.getTracks().forEach(track => track.stop());
            const form = new FormData();
            form.append('audio', new Blob(chunks, { type: recorder.mimeType }), 'recording.webm');
            form.append('language', document.getElementById('speechLanguage').value);
            statusBox.textContent = 'Transcribing...';
            try {
                const response = await fetch('<?= BASE_URL ?>/api/transcribe.php', { method: 'POST', body: form });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'Transcription failed.');
                document.getElementById('transcript').value = result.text || '';
                statusBox.textContent = 'Transcription complete';
            } catch (error) {
                statusBox.textContent = error.message;
            }
        });
        recorder.start();
        startButton.disabled = true;
        stopButton.disabled = false;
        statusBox.textContent = 'Recording';
    } catch (error) {
        statusBox.textContent = 'Microphone access was not available.';
    }
});
stopButton.addEventListener('click', () => {
    if (recorder && recorder.state === 'recording') recorder.stop();
    startButton.disabled = false;
    stopButton.disabled = true;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
