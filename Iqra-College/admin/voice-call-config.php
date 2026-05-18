<?php
/**
 * Admin - Voice Call AI Configuration
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api.php';

requireRole('admin');

$pdo = getDBConnection();
$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $openaiKey = trim($_POST['openai_api_key'] ?? '');
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $enabled = isset($_POST['voice_call_enabled']) ? 1 : 0;
    
    try {
        // Save OpenAI API key
        if (!empty($openaiKey)) {
            $stmt = $pdo->prepare("
                INSERT INTO settings (setting_key, setting_value, updated_at) 
                VALUES ('openai_api_key', ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $stmt->execute([$openaiKey, $openaiKey]);
        }
        
        // Save phone number
        if (!empty($phoneNumber)) {
            $stmt = $pdo->prepare("
                INSERT INTO settings (setting_key, setting_value, updated_at) 
                VALUES ('voice_call_number', ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $stmt->execute([$phoneNumber, $phoneNumber]);
        }
        
        // Save enabled status
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, updated_at) 
            VALUES ('voice_call_enabled', ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
        ");
        $stmt->execute([$enabled, $enabled]);
        
        $success = 'Voice call configuration saved successfully!';
    } catch (PDOException $e) {
        $error = 'Failed to save configuration: ' . $e->getMessage();
    }
}

// Get current settings
$openaiKey = getSetting('openai_api_key', '');
$phoneNumber = getSetting('voice_call_number', '');
$enabled = getSetting('voice_call_enabled', '0');

// Mask API key for display
$maskedKey = !empty($openaiKey) ? substr($openaiKey, 0, 10) . '...' . substr($openaiKey, -10) : '';

// Get call statistics
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_calls,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_calls,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as calls_today
        FROM voice_calls
    ");
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['total_calls' => 0, 'completed_calls' => 0, 'calls_today' => 0];
}

// Get recent calls
try {
    $stmt = $pdo->prepare("
        SELECT * FROM voice_calls 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt->execute();
    $recentCalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentCalls = [];
}

$currentPage = 'voice-call-config.php';
$pageTitle = 'Voice Call Config';
$base = rtrim(str_replace('\\','/',dirname(dirname($_SERVER['SCRIPT_NAME']??'/'))),'/');
$webhookUrl = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').$base.'/api/voice-call.php';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Admin - IQRA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{colors:{primary:{50:'#eff6ff',100:'#dbeafe',500:'#3b82f6',600:'#2563eb'}}}}};</script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <div class="lg:ml-64">
        <header class="bg-white dark:bg-gray-800 shadow border-b border-gray-200 dark:border-gray-700 sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <button id="mobile-menu-toggle" class="lg:hidden p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-bars text-xl"></i></button>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">AI voice & Twilio webhook</p>
                        </div>
                    </div>
                    <?php include __DIR__ . '/../includes/admin_header.php'; ?>
                </div>
            </div>
        </header>
        <main class="p-4 sm:p-6 lg:p-8">
            <div class="max-w-6xl mx-auto">
            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border-l-4 border-green-500 rounded">
                    <p class="text-green-800 dark:text-green-300"><?php echo htmlspecialchars($success); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 rounded">
                    <p class="text-red-800 dark:text-red-300"><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Configuration Form -->
                <div class="lg:col-span-2">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-6 mb-6">
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-6">Configuration</h2>
                        
                        <form method="POST" class="space-y-6">
                            <!-- OpenAI API Key -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                    OpenAI API Key
                                </label>
                                <input type="password" 
                                       name="openai_api_key" 
                                       value="<?php echo htmlspecialchars($openaiKey); ?>"
                                       placeholder="sk-proj-..."
                                       class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500 text-gray-800 dark:text-white">
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                    <?php if (!empty($openaiKey)): ?>
                                        Current: <?php echo htmlspecialchars($maskedKey); ?>
                                    <?php else: ?>
                                        Enter your OpenAI API key to enable AI voice responses
                                    <?php endif; ?>
                                </p>
                            </div>

                            <!-- Phone Number -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                    Phone Number (for transfers)
                                </label>
                                <input type="text" 
                                       name="phone_number" 
                                       value="<?php echo htmlspecialchars($phoneNumber); ?>"
                                       placeholder="+1234567890"
                                       class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500 text-gray-800 dark:text-white">
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                    Phone number for transferring calls to human agents
                                </p>
                            </div>

                            <!-- Enable/Disable -->
                            <div class="flex items-center">
                                <input type="checkbox" 
                                       name="voice_call_enabled" 
                                       id="voice_call_enabled"
                                       <?php echo $enabled ? 'checked' : ''; ?>
                                       class="w-5 h-5 text-primary-600 rounded focus:ring-primary-500">
                                <label for="voice_call_enabled" class="ml-3 text-sm font-semibold text-gray-700 dark:text-gray-300">
                                    Enable Voice Call AI System
                                </label>
                            </div>

                            <button type="submit" name="save_config" 
                                    class="w-full bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white px-6 py-3 rounded-xl font-semibold transition-all transform hover:scale-105 shadow-lg">
                                <i class="fas fa-save mr-2"></i>Save Configuration
                            </button>
                        </form>
                    </div>

                    <!-- Webhook URL -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-6">
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-4">Webhook URL</h2>
                        <p class="text-gray-600 dark:text-gray-400 mb-4">
                            Use this URL as your Twilio webhook or voice call endpoint:
                        </p>
                        <div class="bg-gray-50 dark:bg-gray-900 p-4 rounded-xl border border-gray-200 dark:border-gray-700">
                            <code class="text-sm text-gray-800 dark:text-gray-200 break-all">
                                <?php echo htmlspecialchars($webhookUrl); ?>
                            </code>
                        </div>
                        <button onclick="copyWebhookUrl()" 
                                class="mt-4 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-4 py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                            <i class="fas fa-copy mr-2"></i>Copy URL
                        </button>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Statistics</h2>
                        <div class="space-y-4">
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Total Calls</p>
                                <p class="text-3xl font-bold text-primary-600 dark:text-primary-400"><?php echo $stats['total_calls']; ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Completed</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo $stats['completed_calls']; ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Calls Today</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo $stats['calls_today']; ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Calls -->
                    <?php if (!empty($recentCalls)): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-6">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Recent Calls</h2>
                            <div class="space-y-3 max-h-96 overflow-y-auto">
                                <?php foreach ($recentCalls as $call): ?>
                                    <div class="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                        <p class="text-sm font-semibold text-gray-800 dark:text-white">
                                            <?php echo htmlspecialchars($call['caller_number']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            <?php echo date('M j, Y g:i A', strtotime($call['created_at'])); ?>
                                        </p>
                                        <span class="inline-block mt-2 px-2 py-1 bg-primary-100 dark:bg-primary-900/20 text-primary-800 dark:text-primary-400 rounded text-xs">
                                            <?php echo ucfirst($call['status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            </div>
        </main>
    </div>
    <script>
        function copyWebhookUrl() {
            navigator.clipboard.writeText('<?php echo addslashes($webhookUrl); ?>').then(function(){ alert('Webhook URL copied to clipboard!'); });
        }
    </script>
</body>
</html>
