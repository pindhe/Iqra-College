<?php
/**
 * Student - Messages (Contact Staff)
 * Send messages to Admin, Teacher, or Cashier
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$studentCode = getUserCode($studentId);
$pdo = getDBConnection();
$name = getCurrentUserName();

// Staff messaging feedback
$staffMessageSuccess = '';
$staffMessageError = '';

// Handle direct message to staff (admin / teacher / cashier)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_message'])) {
    $recipientType = trim($_POST['recipient_type'] ?? '');
    $staffSubject  = trim($_POST['staff_subject'] ?? '');
    $staffBody     = trim($_POST['staff_body'] ?? '');

    if ($recipientType === '' || $staffBody === '') {
        $staffMessageError = 'Please choose who you want to contact and write your message.';
    } else {
        // Map selection to user role
        $roleMap = [
            'admin'   => 'admin',
            'teacher' => 'teacher',
            'cashier' => 'cashier',
        ];

        if (!isset($roleMap[$recipientType])) {
            $staffMessageError = 'Invalid recipient selected.';
        } else {
            try {
                // Find first active user for that role
                $stmt = $pdo->prepare("
                    SELECT id 
                    FROM users 
                    WHERE role = ? AND status = 'active'
                    ORDER BY id ASC
                    LIMIT 1
                ");
                $stmt->execute([$roleMap[$recipientType]]);
                $receiver = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$receiver) {
                    $staffMessageError = 'No user found for the selected role. Please try again later.';
                    } else {
                    $receiverId = (int) $receiver['id'];
                    if ($staffSubject === '') {
                        // Default subject based on role
                        $staffSubject = ucfirst($recipientType) . ' Support Message';
                    }

                    // Save message
                    $stmt = $pdo->prepare("
                        INSERT INTO messages (sender_id, receiver_id, subject, message) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$studentId, $receiverId, $staffSubject, $staffBody]);

                    // Optional: create notification for receiver
                    try {
                        $nStmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, title, message, type, created_at)
                            VALUES (?, ?, ?, 'info', NOW())
                        ");
                        $title = 'New message from student';
                        $preview = mb_strimwidth($staffBody, 0, 120, '...');
                        $nStmt->execute([$receiverId, $title, $preview]);
                    } catch (PDOException $e) {
                        // notifications table may not exist; ignore
                    }

                    $staffMessageSuccess = 'Your message has been sent successfully to ' . ucfirst($recipientType) . '.';
                    // Clear POST data to avoid re-population on refresh
                    $_POST = [];
                }
            } catch (PDOException $e) {
                $staffMessageError = 'Failed to send message. Please try again.';
                error_log("Message send error: " . $e->getMessage());
            }
        }
    }
}

// Get sent messages history
        try {
            $stmt = $pdo->prepare("
                SELECT m.*,
               u.name as receiver_name,
               u.role as receiver_role
                FROM messages m
        JOIN users u ON m.receiver_id = u.id
        WHERE m.sender_id = ? 
        AND m.receiver_id != 0
                ORDER BY m.created_at DESC
                LIMIT 50
            ");
    $stmt->execute([$studentId]);
    $sentMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
    $sentMessages = [];
}

// Get received messages (replies from staff)
try {
    $stmt = $pdo->prepare("
        SELECT m.*, 
               u.name as sender_name,
               u.role as sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.receiver_id = ? 
        AND m.sender_id != 0
        ORDER BY m.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$studentId]);
    $receivedMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $receivedMessages = [];
}

$pageTitle = 'Messages';
$pageSubtitle = 'Contact Admin, Teacher, or Cashier';
$currentPage = 'messages';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - IQRA Online College</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd',
                            400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8',
                            800: '#1e40af', 900: '#1e3a8a',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            background-size: 200% 200%;
            animation: gradient-shift 3s ease infinite;
        }
        @keyframes gradient-shift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .card-hover {
            transition: all 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .dark .card-hover:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen transition-colors duration-300">
    <div class="flex h-screen">
        <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>

        <main class="ml-0 lg:ml-64 flex-1 flex flex-col h-screen transition-all duration-300">
            <!-- Header -->
            <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <button id="mobile-menu-toggle" class="lg:hidden text-gray-600 dark:text-gray-400">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <div>
                            <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 dark:text-white flex items-center">
                                <i class="fas fa-envelope text-primary-600 dark:text-primary-400 mr-3"></i>
                                <?php echo $pageTitle; ?>
                            </h1>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                <?php echo $pageSubtitle; ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="flex items-center space-x-3">
                        <a href="ai_assistant.php" class="flex items-center space-x-2 px-4 py-2 bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white rounded-xl transition-all transform hover:scale-105 shadow-lg">
                            <i class="fas fa-robot"></i>
                            <span class="hidden lg:inline">AI Assistant</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <div class="max-w-5xl mx-auto space-y-6">
                    <!-- Success/Error Messages -->
                    <?php if ($staffMessageSuccess): ?>
                        <div class="bg-emerald-100 dark:bg-emerald-900/30 border-l-4 border-emerald-500 text-emerald-700 dark:text-emerald-300 px-4 py-3 rounded-lg fade-in">
                            <div class="flex items-center">
                                <i class="fas fa-check-circle mr-2"></i>
                                <span><?php echo htmlspecialchars($staffMessageSuccess); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($staffMessageError): ?>
                        <div class="bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg fade-in">
                            <div class="flex items-center">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                <span><?php echo htmlspecialchars($staffMessageError); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Send Message Form -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-6 lg:p-8 fade-in">
                        <div class="flex items-center space-x-3 mb-6">
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
                                <i class="fas fa-paper-plane text-white text-xl"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Send Message</h2>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Contact Admin, Teacher, or Cashier</p>
                            </div>
                        </div>

                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="staff_message" value="1">
                            
                            <!-- Recipient Selection -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-user-tag mr-2 text-primary-600"></i>Send To <span class="text-red-500">*</span>
                                </label>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <label class="relative flex items-center p-4 border-2 border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:border-primary-500 dark:hover:border-primary-600 transition-all card-hover group">
                                        <input type="radio" name="recipient_type" value="admin" class="sr-only peer" required>
                                        <div class="flex-1">
                                            <div class="flex items-center justify-between mb-2">
                                                <i class="fas fa-user-shield text-blue-600 dark:text-blue-400 text-2xl group-hover:scale-110 transition-transform"></i>
                                                <div class="w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 peer-checked:border-primary-500 peer-checked:bg-primary-500 flex items-center justify-center transition-all">
                                                    <i class="fas fa-check text-white text-xs opacity-0 peer-checked:opacity-100"></i>
                    </div>
                </div>
                                            <h3 class="font-bold text-gray-800 dark:text-white">Admin</h3>
                                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">Administrative support</p>
                                        </div>
                                    </label>

                                    <label class="relative flex items-center p-4 border-2 border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:border-primary-500 dark:hover:border-primary-600 transition-all card-hover group">
                                        <input type="radio" name="recipient_type" value="teacher" class="sr-only peer" required>
                                        <div class="flex-1">
                                            <div class="flex items-center justify-between mb-2">
                                                <i class="fas fa-chalkboard-teacher text-purple-600 dark:text-purple-400 text-2xl group-hover:scale-110 transition-transform"></i>
                                                <div class="w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 peer-checked:border-primary-500 peer-checked:bg-primary-500 flex items-center justify-center transition-all">
                                                    <i class="fas fa-check text-white text-xs opacity-0 peer-checked:opacity-100"></i>
                                                </div>
                                            </div>
                                            <h3 class="font-bold text-gray-800 dark:text-white">Teacher</h3>
                                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">Academic questions</p>
                                        </div>
                                    </label>

                                    <label class="relative flex items-center p-4 border-2 border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:border-primary-500 dark:hover:border-primary-600 transition-all card-hover group">
                                        <input type="radio" name="recipient_type" value="cashier" class="sr-only peer" required>
                                        <div class="flex-1">
                                        <div class="flex items-center justify-between mb-2">
                                                <i class="fas fa-cash-register text-emerald-600 dark:text-emerald-400 text-2xl group-hover:scale-110 transition-transform"></i>
                                                <div class="w-5 h-5 rounded-full border-2 border-gray-300 dark:border-gray-600 peer-checked:border-primary-500 peer-checked:bg-primary-500 flex items-center justify-center transition-all">
                                                    <i class="fas fa-check text-white text-xs opacity-0 peer-checked:opacity-100"></i>
                                                </div>
                                            </div>
                                            <h3 class="font-bold text-gray-800 dark:text-white">Cashier</h3>
                                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">Payment inquiries</p>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Subject -->
                            <div>
                                <label for="staff_subject" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-tag mr-2 text-primary-600"></i>Subject (Optional)
                                </label>
                                <input 
                                    type="text" 
                                    id="staff_subject"
                                    name="staff_subject" 
                                    value="<?php echo htmlspecialchars($_POST['staff_subject'] ?? ''); ?>"
                                    placeholder="Enter message subject..."
                                    class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500 text-gray-800 dark:text-white"
                                >
                </div>

                            <!-- Message -->
                            <div>
                                <label for="staff_body" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-comment-dots mr-2 text-primary-600"></i>Message <span class="text-red-500">*</span>
                                </label>
                                <textarea 
                                    id="staff_body"
                                    name="staff_body" 
                                    rows="6"
                                    required
                                    placeholder="Type your message here..."
                                    class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none text-gray-800 dark:text-white"
                                ><?php echo htmlspecialchars($_POST['staff_body'] ?? ''); ?></textarea>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Your message will be sent to the selected staff member. They will receive a notification.
                                </p>
                            </div>

                            <!-- Submit Button -->
                            <div class="flex justify-end">
                            <button 
                                type="submit"
                                    class="inline-flex items-center space-x-2 px-8 py-3 bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white font-semibold rounded-xl transition-all transform hover:scale-105 shadow-lg"
                            >
                                <i class="fas fa-paper-plane"></i>
                                    <span>Send Message</span>
                            </button>
                            </div>
                        </form>
                    </div>

                    <!-- Message History -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Sent Messages -->
                        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-6 fade-in">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-bold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-paper-plane text-primary-600 mr-2"></i>
                                    Sent Messages
                                </h3>
                                <span class="px-3 py-1 bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 rounded-full text-xs font-semibold">
                                    <?php echo count($sentMessages); ?>
                                </span>
                            </div>
                            <div class="space-y-3 max-h-96 overflow-y-auto scrollbar-thin">
                                <?php if (empty($sentMessages)): ?>
                                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                                        <i class="fas fa-inbox text-4xl mb-3 opacity-50"></i>
                                        <p>No messages sent yet</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($sentMessages as $msg): ?>
                                        <div class="p-4 bg-gray-50 dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700">
                                            <div class="flex items-start justify-between mb-2">
                            <div class="flex items-center space-x-2">
                                                    <span class="px-2 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded text-xs font-semibold">
                                                        <?php echo ucfirst($msg['receiver_role']); ?>
                                                    </span>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                                        To: <?php echo htmlspecialchars($msg['receiver_name']); ?>
                                                    </span>
                                                </div>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    <?php echo date('M d, g:i A', strtotime($msg['created_at'])); ?>
                                                </span>
                                            </div>
                                            <h4 class="font-semibold text-gray-800 dark:text-white text-sm mb-1">
                                                <?php echo htmlspecialchars($msg['subject']); ?>
                                            </h4>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 line-clamp-2">
                                                <?php echo htmlspecialchars($msg['message']); ?>
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Received Messages -->
                        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-6 fade-in">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-bold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-inbox text-emerald-600 mr-2"></i>
                                    Received Messages
                                </h3>
                                <span class="px-3 py-1 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 rounded-full text-xs font-semibold">
                                    <?php echo count($receivedMessages); ?>
                                </span>
                            </div>
                            <div class="space-y-3 max-h-96 overflow-y-auto scrollbar-thin">
                                <?php if (empty($receivedMessages)): ?>
                                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                                        <i class="fas fa-inbox text-4xl mb-3 opacity-50"></i>
                                        <p>No messages received yet</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($receivedMessages as $msg): ?>
                                        <div class="p-4 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl border border-emerald-200 dark:border-emerald-800">
                                            <div class="flex items-start justify-between mb-2">
                                                <div class="flex items-center space-x-2">
                                                    <span class="px-2 py-1 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 rounded text-xs font-semibold">
                                                        <?php echo ucfirst($msg['sender_role']); ?>
                                                    </span>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                                        From: <?php echo htmlspecialchars($msg['sender_name']); ?>
                                                    </span>
                                                </div>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    <?php echo date('M d, g:i A', strtotime($msg['created_at'])); ?>
                                                </span>
                                            </div>
                                            <h4 class="font-semibold text-gray-800 dark:text-white text-sm mb-1">
                                                <?php echo htmlspecialchars($msg['subject']); ?>
                                            </h4>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 line-clamp-2">
                                                <?php echo htmlspecialchars($msg['message']); ?>
                                            </p>
                                            <?php if (!$msg['is_read']): ?>
                                                <span class="inline-block mt-2 px-2 py-1 bg-blue-500 text-white text-xs rounded-full">
                                                    New
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 hidden lg:hidden"></div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-toggle')?.addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            }
        });

        // Close sidebar when clicking overlay
        document.getElementById('sidebar-overlay')?.addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (sidebar && overlay) {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            }
        });

        // Radio button visual feedback
        document.querySelectorAll('input[type="radio"][name="recipient_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('label').forEach(label => {
                    label.classList.remove('border-primary-500', 'dark:border-primary-600', 'bg-primary-50', 'dark:bg-primary-900/20');
                });
                if (this.checked) {
                    this.closest('label').classList.add('border-primary-500', 'dark:border-primary-600', 'bg-primary-50', 'dark:bg-primary-900/20');
                }
            });
        });
    </script>
    
    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>
</body>
</html>
