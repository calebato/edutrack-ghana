<?php

function mlServiceBaseUrl(): string {
    $configured = getenv('EDUTRACK_ML_URL');
    return rtrim($configured !== false && $configured !== '' ? $configured : 'http://127.0.0.1:5000', '/');
}

function callMLService(string $endpoint, array $payload, float $timeoutSeconds = 1.2): ?array {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) return null;
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ],
    ]);
    $previous = set_error_handler(static fn(): bool => true);
    try {
        $response = file_get_contents(mlServiceBaseUrl() . $endpoint, false, $context);
    } finally {
        restore_error_handler();
    }
    if ($response === false) return null;
    $decoded = json_decode($response, true);
    return is_array($decoded) && !isset($decoded['error']) ? $decoded : null;
}

function mlServiceHealth(float $timeoutSeconds = 0.6): ?array {
    $context = stream_context_create(['http' => ['timeout' => $timeoutSeconds, 'ignore_errors' => true]]);
    $previous = set_error_handler(static fn(): bool => true);
    try {
        $response = file_get_contents(mlServiceBaseUrl() . '/api/v1/health', false, $context);
    } finally {
        restore_error_handler();
    }
    if ($response === false) return null;
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}
