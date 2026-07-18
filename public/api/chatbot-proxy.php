<?php
/**
 * Sanctuary Shine - OpenRouter Chatbot Proxy
 * 
 * This secure endpoint proxies AI requests to OpenRouter.
 * The API key is stored in .env (not exposed to browsers).
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

// Get API key from .env file (public_html/.env on Hostinger)
$envFile = __DIR__ . '/../.env';
$apiKey = '';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, 'OPENROUTER_API_KEY=') === 0) {
            $apiKey = trim(substr($line, strlen('OPENROUTER_API_KEY=')));
            break;
        }
    }
}

if (empty($apiKey)) {
    echo json_encode(['reply' => "I'm sorry, the AI assistant is not configured yet. Please call us at 0161 123 4567 or email contact@sanctuaryshine.co.uk and our team will be happy to help!"]);
    exit;
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing messages']);
    exit;
}

// Call OpenRouter API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://openrouter.ai/api/v1/chat/completions');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
    'HTTP-Referer: https://sanctuaryshine.co.uk',
    'X-Title: Sanctuary Shine Chatbot'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => $input['model'] ?? 'meta-llama/llama-3.2-3b-instruct:free',
    'messages' => $input['messages'],
    'max_tokens' => $input['max_tokens'] ?? 300,
    'temperature' => $input['temperature'] ?? 0.7
]));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['reply' => "I'm having trouble connecting right now. Please call us at 0161 123 4567 or email contact@sanctuaryshine.co.uk and we'll be happy to help!"]);
    exit;
}

// Forward the OpenRouter response
echo $response;