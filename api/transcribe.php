<?php
require_once __DIR__ . '/../auth/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['audio'])) {
    http_response_code(400);
    echo json_encode(['error' => 'An audio recording is required.']);
    exit;
}
$audio = $_FILES['audio'];
if ($audio['error'] !== UPLOAD_ERR_OK || $audio['size'] > 15 * 1024 * 1024) {
    http_response_code(422);
    echo json_encode(['error' => 'The recording could not be accepted. Maximum size is 15 MB.']);
    exit;
}
$allowed = ['audio/webm','audio/wav','audio/x-wav','audio/mpeg','audio/mp4','audio/ogg','video/webm'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($audio['tmp_name']);
if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    echo json_encode(['error' => 'Unsupported audio format.']);
    exit;
}
$extension = match ($mime) {
    'audio/wav','audio/x-wav' => 'wav', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
    'audio/ogg' => 'ogg', default => 'webm',
};
$curl = curl_init('http://127.0.0.1:5000/api/v1/transcribe');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'audio' => new CURLFile($audio['tmp_name'], $mime, 'recording.' . $extension),
        'language' => trim($_POST['language'] ?? ''),
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 180,
]);
$response = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
curl_close($curl);
if ($response === false || $status === 0) {
    http_response_code(503);
    echo json_encode(['error' => 'Speech service unavailable.', 'detail' => $error]);
    exit;
}
http_response_code($status);
echo $response;
