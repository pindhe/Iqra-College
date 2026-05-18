<?php
/**
 * Test OpenAI API Connection
 * Simple script to verify OpenAI API is working
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api.php';

header('Content-Type: application/json');

$apiKey = getOpenAIApiKey();

if (empty($apiKey)) {
    echo json_encode([
        'success' => false,
        'error' => 'OpenAI API key not configured. Please set it in admin panel or config file.'
    ]);
    exit;
}

// Test API call
$testMessage = "Hello, this is a test. Please respond with 'API is working correctly'.";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role' => 'user', 'content' => $testMessage]
    ],
    'max_tokens' => 50
]));
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    echo json_encode([
        'success' => true,
        'message' => 'OpenAI API is working correctly!',
        'response' => $data['choices'][0]['message']['content'] ?? 'No response',
        'model' => $data['model'] ?? 'unknown'
    ], JSON_PRETTY_PRINT);
} else {
    $errorData = json_decode($response, true);
    echo json_encode([
        'success' => false,
        'error' => 'API call failed',
        'http_code' => $httpCode,
        'error_message' => $errorData['error']['message'] ?? $curlError ?? 'Unknown error',
        'raw_response' => $response
    ], JSON_PRETTY_PRINT);
}
