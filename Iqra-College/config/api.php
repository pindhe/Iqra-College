<?php
/**
 * API Configuration
 * DeepSeek and OpenAI API Configuration
 */

// DeepSeek API Configuration
// Get your API key from: https://platform.deepseek.com/
define('DEEPSEEK_API_KEY', 'your-deepseek-api-key-here'); // Replace with your actual API key
define('DEEPSEEK_API_URL', 'https://api.deepseek.com/v1/chat/completions');

// OpenAI API Configuration
// Get your API key from: https://platform.openai.com/
define('OPENAI_API_KEY', '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');
define('OPENAI_API_URL', 'https://api.openai.com/v1');
define('OPENAI_CHAT_URL', 'https://api.openai.com/v1/chat/completions');
define('OPENAI_AUDIO_URL', 'https://api.openai.com/v1/audio/speech');

/**
 * Get DeepSeek API Key
 * @return string
 */
function getDeepSeekApiKey() {
    // First try to get from database settings
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'deepseek_api_key'");
        $stmt->execute();
        $apiKey = $stmt->fetchColumn();
        if ($apiKey) {
            return $apiKey;
        }
    } catch (PDOException $e) {
        // Fallback to constant
    }
    
    // Fallback to constant
    return defined('DEEPSEEK_API_KEY') ? DEEPSEEK_API_KEY : '';
}

/**
 * Get OpenAI API Key
 * @return string
 */
function getOpenAIApiKey() {
    // First try to get from database settings
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'openai_api_key'");
        $stmt->execute();
        $apiKey = $stmt->fetchColumn();
        if ($apiKey) {
            return $apiKey;
        }
    } catch (PDOException $e) {
        // Fallback to constant
    }
    
    // Fallback to constant
    return defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
}

/**
 * Call OpenAI API for chat completion
 * @param string $message User message
 * @param array $conversationHistory Previous conversation messages
 * @return string AI response
 */
function callOpenAIChat($message, $conversationHistory = []) {
    $apiKey = getOpenAIApiKey();
    if (empty($apiKey)) {
        return "I'm sorry, but the AI service is not configured. Please contact support.";
    }
    
    $messages = [];
    
    // Add system prompt
    $messages[] = [
        'role' => 'system',
        'content' => 'You are a helpful AI assistant for Iqra College. You help students and callers with information about courses, enrollment, payments, and general inquiries. Be friendly, professional, and concise. Keep responses brief for phone conversations.'
    ];
    
    // Add conversation history
    foreach ($conversationHistory as $msg) {
        $messages[] = $msg;
    }
    
    // Add current message
    $messages[] = [
        'role' => 'user',
        'content' => $message
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, OPENAI_CHAT_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 150
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("OpenAI API Error: " . $response);
        return "I'm sorry, I'm having trouble processing your request right now. Please try again later.";
    }
    
    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? "I'm sorry, I didn't understand that. Could you please repeat?";
}

/**
 * Convert text to speech using OpenAI
 * @param string $text Text to convert
 * @return string|false Audio file path or false on error
 */
function textToSpeech($text) {
    $apiKey = getOpenAIApiKey();
    if (empty($apiKey)) {
        return false;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, OPENAI_AUDIO_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'tts-1',
        'input' => $text,
        'voice' => 'alloy',
        'speed' => 1.0
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $audioData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("OpenAI TTS Error: HTTP $httpCode");
        return false;
    }
    
    // Save audio file
    $filename = 'tts_' . time() . '_' . uniqid() . '.mp3';
    $filepath = __DIR__ . '/../uploads/audio/' . $filename;
    
    // Create directory if it doesn't exist
    $dir = dirname($filepath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    file_put_contents($filepath, $audioData);
    
    return '/Iqra-College/uploads/audio/' . $filename;
}
