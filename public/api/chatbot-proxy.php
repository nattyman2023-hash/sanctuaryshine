<?php
/**
 * Sanctuary Shine - DeepSeek Chatbot Proxy
 *
 * The DeepSeek API key is read server-side from crm-config.php (or the
 * DEEPSEEK_API_KEY environment variable) and is never sent to the browser.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$config = [];
$configPath = dirname(__DIR__) . '/crm-config.php';
if (is_file($configPath)) {
    $loadedConfig = include $configPath;
    if (is_array($loadedConfig)) $config = $loadedConfig;
}

$apiKey = trim((string)($config['deepseek_api_key'] ?? getenv('DEEPSEEK_API_KEY') ?: ''));

if ($apiKey === '') {
    http_response_code(503);
    echo json_encode(['error' => 'missing_api_key', 'reply' => null]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['messages']) || !is_array($input['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing messages']);
    exit;
}

// Use DeepSeek's current fast chat model. The non-thinking mode keeps replies
// quick and avoids exposing internal reasoning in a visitor-facing chat.
$payload = [
    'model' => 'deepseek-v4-flash',
    'messages' => $input['messages'],
    'max_tokens' => min((int)($input['max_tokens'] ?? 300), 400),
    'temperature' => $input['temperature'] ?? 0.7,
    'thinking' => ['type' => 'disabled'],
    'stream' => false,
];

$ch = curl_init('https://api.deepseek.com/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 8,
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if (!$curlErr && $httpCode >= 200 && $httpCode < 300 && is_string($response) && $response !== '') {
    echo $response;
    exit;
}

// Signal the client to use its local FAQ fallback without exposing the API key.
http_response_code($httpCode >= 400 ? $httpCode : 503);
$message = $curlErr ?: 'upstream_unavailable';
if (is_string($response) && $response !== '') {
    $decoded = json_decode($response, true);
    $message = $decoded['error']['message'] ?? $message;
}
echo json_encode([
    'error' => 'upstream_unavailable',
    'http' => $httpCode,
    'message' => substr((string)$message, 0, 180),
    'reply' => null
]);
