<?php
/**
 * Voice Call Handler - AI Auto-Response System
 * Handles incoming phone calls and provides AI-powered responses
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/xml; charset=utf-8');

// Get call parameters (from Twilio or similar service)
$callerNumber = $_POST['From'] ?? $_GET['From'] ?? 'Unknown';
$callerName = $_POST['CallerName'] ?? $_GET['CallerName'] ?? 'Unknown';
$callSid = $_POST['CallSid'] ?? $_GET['CallSid'] ?? uniqid('call_');
$action = $_POST['Digits'] ?? $_GET['Digits'] ?? '';
$speechResult = $_POST['SpeechResult'] ?? $_GET['SpeechResult'] ?? '';

// Log the call
$pdo = getDBConnection();
try {
    $stmt = $pdo->prepare("
        INSERT INTO voice_calls (caller_number, caller_name, call_sid, status, created_at) 
        VALUES (?, ?, ?, 'incoming', NOW())
        ON DUPLICATE KEY UPDATE status = 'incoming', updated_at = NOW()
    ");
    $stmt->execute([$callerNumber, $callerName, $callSid]);
} catch (PDOException $e) {
    // Table might not exist, create it
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS voice_calls (
                id INT AUTO_INCREMENT PRIMARY KEY,
                caller_number VARCHAR(20),
                caller_name VARCHAR(255),
                call_sid VARCHAR(100) UNIQUE,
                status ENUM('incoming', 'answered', 'completed', 'failed') DEFAULT 'incoming',
                transcript TEXT,
                ai_response TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_call_sid (call_sid),
                INDEX idx_created (created_at)
            )
        ");
        $stmt = $pdo->prepare("
            INSERT INTO voice_calls (caller_number, caller_name, call_sid, status, created_at) 
            VALUES (?, ?, ?, 'incoming', NOW())
        ");
        $stmt->execute([$callerNumber, $callerName, $callSid]);
    } catch (PDOException $e2) {
        error_log("Error creating voice_calls table: " . $e2->getMessage());
    }
}

// Get conversation history for this caller
$conversationHistory = [];
try {
    $stmt = $pdo->prepare("
        SELECT user_message, ai_response 
        FROM voice_call_messages 
        WHERE call_sid = ? 
        ORDER BY created_at ASC 
        LIMIT 10
    ");
    $stmt->execute([$callSid]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($history as $msg) {
        $conversationHistory[] = ['role' => 'user', 'content' => $msg['user_message']];
        $conversationHistory[] = ['role' => 'assistant', 'content' => $msg['ai_response']];
    }
} catch (PDOException $e) {
    // Table might not exist yet
}

// Handle different call stages
if (empty($action) && empty($speechResult)) {
    // Initial greeting
    $greeting = "Hello! Welcome to Iqra College. I'm your AI assistant. How can I help you today?";
    echo generateTwimlResponse($greeting, true);
    
} elseif (!empty($speechResult)) {
    // User spoke something - process with AI
    $userMessage = $speechResult;
    
    // Get AI response
    $aiResponse = callOpenAIChat($userMessage, $conversationHistory);
    
    // Save conversation
    try {
        $stmt = $pdo->prepare("
            INSERT INTO voice_call_messages (call_sid, user_message, ai_response, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$callSid, $userMessage, $aiResponse]);
    } catch (PDOException $e) {
        // Table might not exist
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS voice_call_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    call_sid VARCHAR(100),
                    user_message TEXT,
                    ai_response TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_call_sid (call_sid)
                )
            ");
            $stmt = $pdo->prepare("
                INSERT INTO voice_call_messages (call_sid, user_message, ai_response, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$callSid, $userMessage, $aiResponse]);
        } catch (PDOException $e2) {
            error_log("Error creating voice_call_messages table: " . $e2->getMessage());
        }
    }
    
    // Update call status
    try {
        $stmt = $pdo->prepare("UPDATE voice_calls SET status = 'answered', transcript = ?, ai_response = ? WHERE call_sid = ?");
        $stmt->execute([$userMessage, $aiResponse, $callSid]);
    } catch (PDOException $e) {
        // Silent fail
    }
    
    // Respond with AI answer and ask for more input
    $response = $aiResponse . " Is there anything else I can help you with?";
    echo generateTwimlResponse($response, true);
    
} elseif ($action === '1') {
    // Option 1: Course Information
    $response = "You can find information about our courses on our website or speak with an enrollment advisor. What would you like to know about our courses?";
    echo generateTwimlResponse($response, true);
    
} elseif ($action === '2') {
    // Option 2: Payment Information
    $response = "For payment inquiries, you can check your payment status in your student portal or speak with our finance department. How can I help with payments?";
    echo generateTwimlResponse($response, true);
    
} elseif ($action === '3') {
    // Option 3: Speak to Human
    $response = "I'll transfer you to a human representative. Please hold.";
    echo generateTwimlResponse($response, false, true);
    
} elseif ($action === '0' || strtolower($action) === 'hangup') {
    // End call
    $response = "Thank you for calling Iqra College. Have a great day!";
    echo generateTwimlResponse($response, false);
    
    // Update call status
    try {
        $stmt = $pdo->prepare("UPDATE voice_calls SET status = 'completed' WHERE call_sid = ?");
        $stmt->execute([$callSid]);
    } catch (PDOException $e) {
        // Silent fail
    }
    
} else {
    // Default response
    $response = "I'm sorry, I didn't understand that. Could you please repeat or say 'help' for options?";
    echo generateTwimlResponse($response, true);
}

/**
 * Generate TwiML response for Twilio
 * @param string $message Message to speak
 * @param bool $listen Continue listening for input
 * @param bool $transfer Transfer to human
 * @return string TwiML XML
 */
function generateTwimlResponse($message, $listen = true, $transfer = false) {
    $twiml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $twiml .= '<Response>' . "\n";
    
    if ($transfer) {
        // Transfer to human agent
        $twiml .= '    <Dial>' . "\n";
        $twiml .= '        <Number>+1234567890</Number>' . "\n"; // Replace with actual number
        $twiml .= '    </Dial>' . "\n";
    } else {
        // Speak the message
        $twiml .= '    <Say voice="alice" language="en-US">' . htmlspecialchars($message) . '</Say>' . "\n";
        
        if ($listen) {
            // Gather speech input
            $twiml .= '    <Gather input="speech" action="voice-call.php" method="POST" speechTimeout="auto" timeout="10">' . "\n";
            $twiml .= '        <Say voice="alice" language="en-US">Please speak your question or say zero to end the call.</Say>' . "\n";
            $twiml .= '    </Gather>' . "\n";
            
            // If no input, repeat
            $twiml .= '    <Say voice="alice" language="en-US">I didn\'t hear anything. Please call again if you need assistance. Goodbye!</Say>' . "\n";
        }
    }
    
    $twiml .= '</Response>';
    
    return $twiml;
}

/**
 * Generate simple voice response (for non-Twilio systems)
 * @param string $message Message to speak
 * @return array Response data
 */
function generateVoiceResponse($message) {
    // Convert text to speech
    $audioUrl = textToSpeech($message);
    
    return [
        'success' => true,
        'message' => $message,
        'audio_url' => $audioUrl,
        'text' => $message
    ];
}
