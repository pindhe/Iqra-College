<?php
/**
 * WhatsApp Message API Endpoint
 * Sends WhatsApp messages via configured API service
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get request data
$data = json_decode(file_get_contents('php://input'), true);
$phone = $data['phone'] ?? $_POST['phone'] ?? '';
$message = $data['message'] ?? $_POST['message'] ?? '';

if (empty($phone) || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Phone number and message are required']);
    exit;
}

// Send WhatsApp message
$result = sendWhatsAppMessage($phone, $message);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'WhatsApp message sent successfully']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to send WhatsApp message']);
}

/**
 * Send WhatsApp Message
 * @param string $phone Phone number (with country code)
 * @param string $message Message to send
 * @return bool
 */
function sendWhatsAppMessage($phone, $message) {
    // Remove any non-numeric characters except +
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Ensure phone starts with country code
    if (!preg_match('/^\+/', $phone)) {
        // Assume local number, adjust based on your country
        if (substr($phone, 0, 1) === '0') {
            $phone = '+62' . substr($phone, 1); // Example for Indonesia
        } else {
            $phone = '+62' . $phone; // Default country code
        }
    }
    
    // Get WhatsApp API configuration from settings
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'whatsapp_api_url'");
        $stmt->execute();
        $apiUrl = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'whatsapp_api_key'");
        $stmt->execute();
        $apiKey = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'whatsapp_api_type'");
        $stmt->execute();
        $apiType = $stmt->fetchColumn() ?: 'webhook'; // Default to webhook
    } catch (PDOException $e) {
        $apiUrl = null;
        $apiKey = null;
        $apiType = 'webhook';
    }
    
    // If no API configured, use fallback
    if (empty($apiUrl)) {
        // For development/testing: Log the message
        error_log("WhatsApp Message to {$phone}: {$message}");
        
        // In production, configure a real WhatsApp API
        // For now, return true for testing (change to false if you want to require real API)
        return true;
    }
    
    // Send via configured API
    $ch = curl_init();
    
    // Prepare request based on API type
    $headers = ['Content-Type: application/json'];
    $postData = [];
    
    switch ($apiType) {
        case 'twilio':
            // Twilio WhatsApp API format
            $accountSid = $apiKey;
            $authToken = getSetting('whatsapp_api_token', '');
            $fromNumber = getSetting('whatsapp_from_number', '');
            
            curl_setopt($ch, CURLOPT_URL, "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json");
            curl_setopt($ch, CURLOPT_USERPWD, "{$accountSid}:{$authToken}");
            $postData = [
                'From' => "whatsapp:{$fromNumber}",
                'To' => "whatsapp:{$phone}",
                'Body' => $message
            ];
            break;
            
        case 'chatapi':
            // ChatAPI format
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            $headers[] = "Authorization: Bearer {$apiKey}";
            $postData = [
                'phone' => $phone,
                'body' => $message
            ];
            break;
            
        case 'whatsapp_business':
            // WhatsApp Business API format
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            $headers[] = "Authorization: Bearer {$apiKey}";
            $postData = [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'text',
                'text' => ['body' => $message]
            ];
            break;
            
        default:
            // Generic webhook format
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            if (!empty($apiKey)) {
                $headers[] = "Authorization: Bearer {$apiKey}";
            }
            $postData = [
                'phone' => $phone,
                'message' => $message
            ];
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("WhatsApp API cURL Error: {$curlError}");
        return false;
    }
    
    return $httpCode >= 200 && $httpCode < 300;
}

