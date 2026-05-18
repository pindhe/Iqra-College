<?php
/**
 * Test Voice Call AI System
 * Simple web interface to test the AI voice response system
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDBConnection();
$response = '';
$userMessage = '';
$conversationHistory = [];

// Get or create session ID
session_start();
if (!isset($_SESSION['test_call_sid'])) {
    $_SESSION['test_call_sid'] = 'test_' . uniqid();
}

$callSid = $_SESSION['test_call_sid'];

// Get conversation history
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
    // Table might not exist
}

// Handle message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $userMessage = trim($_POST['message']);
    
    if (!empty($userMessage)) {
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
                error_log("Error: " . $e2->getMessage());
            }
        }
        
        $response = $aiResponse;
    }
}

// Clear conversation
if (isset($_GET['clear'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM voice_call_messages WHERE call_sid = ?");
        $stmt->execute([$callSid]);
    } catch (PDOException $e) {
        // Silent fail
    }
    header('Location: test-voice.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Voice Call AI - IQRA College</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen">
    <div class="container mx-auto p-8 max-w-4xl">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-8">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-white mb-2">
                <i class="fas fa-phone-alt text-primary-600 dark:text-primary-400 mr-3"></i>
                Test Voice Call AI System
            </h1>
            <p class="text-gray-600 dark:text-gray-400 mb-6">
                Test the AI voice response system. This simulates a phone call conversation.
            </p>

            <!-- Conversation History -->
            <div class="bg-gray-50 dark:bg-gray-900 rounded-xl p-6 mb-6 max-h-96 overflow-y-auto">
                <?php if (empty($history)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-comments text-gray-300 dark:text-gray-600 text-4xl mb-4"></i>
                        <p class="text-gray-500 dark:text-gray-400">No conversation yet. Start by asking a question!</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($history as $msg): ?>
                            <div class="flex flex-col space-y-2">
                                <!-- User Message -->
                                <div class="flex justify-end">
                                    <div class="bg-primary-600 text-white rounded-xl px-4 py-2 max-w-md">
                                        <p class="text-sm"><?php echo htmlspecialchars($msg['user_message']); ?></p>
                                    </div>
                                </div>
                                <!-- AI Response -->
                                <div class="flex justify-start">
                                    <div class="bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-white rounded-xl px-4 py-2 max-w-md">
                                        <p class="text-sm"><?php echo htmlspecialchars($msg['ai_response']); ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if ($response): ?>
                            <div class="flex flex-col space-y-2">
                                <div class="flex justify-end">
                                    <div class="bg-primary-600 text-white rounded-xl px-4 py-2 max-w-md">
                                        <p class="text-sm"><?php echo htmlspecialchars($userMessage); ?></p>
                                    </div>
                                </div>
                                <div class="flex justify-start">
                                    <div class="bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-white rounded-xl px-4 py-2 max-w-md">
                                        <p class="text-sm"><?php echo htmlspecialchars($response); ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Input Form -->
            <form method="POST" class="space-y-4">
                <div class="flex space-x-3">
                    <input type="text" 
                           name="message" 
                           placeholder="Type your question or message..."
                           class="flex-1 px-4 py-3 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500 text-gray-800 dark:text-white"
                           required
                           autofocus>
                    <button type="submit" 
                            class="bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white px-6 py-3 rounded-xl font-semibold transition-all transform hover:scale-105 shadow-lg">
                        <i class="fas fa-paper-plane mr-2"></i>Send
                    </button>
                    <a href="?clear=1" 
                       class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-4 py-3 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                        <i class="fas fa-trash"></i>
                    </a>
                </div>
            </form>

            <!-- Quick Questions -->
            <div class="mt-6">
                <p class="text-sm font-semibold text-gray-600 dark:text-gray-400 mb-3">Quick Questions:</p>
                <div class="flex flex-wrap gap-2">
                    <?php 
                    $quickQuestions = [
                        "What courses do you offer?",
                        "How do I enroll?",
                        "What are the payment options?",
                        "When do classes start?",
                        "How can I contact support?"
                    ];
                    foreach ($quickQuestions as $question): 
                    ?>
                        <button onclick="setQuestion('<?php echo htmlspecialchars($question, ENT_QUOTES); ?>')" 
                                class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-sm">
                            <?php echo htmlspecialchars($question); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- API Status -->
            <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-800">
                <p class="text-sm font-semibold text-blue-800 dark:text-blue-300 mb-2">
                    <i class="fas fa-info-circle mr-2"></i>API Status
                </p>
                <p class="text-xs text-blue-600 dark:text-blue-400">
                    <?php 
                    $apiKey = getOpenAIApiKey();
                    if (!empty($apiKey)): 
                    ?>
                        <i class="fas fa-check-circle text-green-500 mr-1"></i>OpenAI API configured
                    <?php else: ?>
                        <i class="fas fa-exclamation-circle text-yellow-500 mr-1"></i>OpenAI API key not configured. Please configure it in admin panel.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <script>
        function setQuestion(question) {
            document.querySelector('input[name="message"]').value = question;
            document.querySelector('input[name="message"]').focus();
        }
    </script>
</body>
</html>
