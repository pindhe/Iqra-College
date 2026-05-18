<?php
/**
 * Teacher - Messages
 * Communicate with students
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('teacher');

$teacherId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();
$error = '';
$success = '';

// Handle sending message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    $recipientId = intval($_POST['recipient_id'] ?? 0);
    $subject = sanitize($_POST['subject'] ?? '');
    $content = sanitize($_POST['content'] ?? '');
    
    if ($recipientId > 0 && !empty($subject) && !empty($content)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, recipient_id, subject, content) 
                                  VALUES (?, ?, ?, ?)");
            $stmt->execute([$teacherId, $recipientId, $subject, $content]);
            $success = 'Message sent successfully!';
        } catch (PDOException $e) {
            $error = 'Failed to send message. Please try again.';
        }
    } else {
        $error = 'Please fill in all required fields.';
    }
}

// Get students enrolled in teacher's courses
try {
    $stmt = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.email, u.student_id as student_code
                          FROM users u
                          JOIN enrollments e ON u.id = e.student_id
                          JOIN courses c ON e.course_id = c.id
                          WHERE u.role = 'student' AND u.status = 'active' AND c.teacher_id = ?
                          ORDER BY u.name ASC");
    $stmt->execute([$teacherId]);
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    $students = [];
}

// Get received messages
try {
    $stmt = $pdo->prepare("SELECT m.*, u.name as sender_name, u.email as sender_email
                          FROM messages m
                          JOIN users u ON m.sender_id = u.id
                          WHERE m.recipient_id = ?
                          ORDER BY m.created_at DESC
                          LIMIT 20");
    $stmt->execute([$teacherId]);
    $receivedMessages = $stmt->fetchAll();
} catch (PDOException $e) {
    $receivedMessages = [];
}

// Get sent messages
try {
    $stmt = $pdo->prepare("SELECT m.*, u.name as recipient_name, u.email as recipient_email
                          FROM messages m
                          JOIN users u ON m.recipient_id = u.id
                          WHERE m.sender_id = ?
                          ORDER BY m.created_at DESC
                          LIMIT 20");
    $stmt->execute([$teacherId]);
    $sentMessages = $stmt->fetchAll();
} catch (PDOException $e) {
    $sentMessages = [];
}

$pageTitle = 'Messages';
$currentPage = 'messages';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Teacher</title>
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
        .fade-in { animation: fadeIn 0.6s ease-out; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-purple-50 to-pink-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/teacher_sidebar.php'; ?>
    
    <div class="lg:ml-64">
        <nav class="bg-white dark:bg-gray-800 shadow-xl border-b border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-4">
                        <button id="mobile-menu-toggle" class="lg:hidden text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded-lg transition-colors">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <div>
                            <h1 class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Communicate with students</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <?php include __DIR__ . '/../includes/teacher_header.php'; ?>
                        <button onclick="openComposeModal()" 
                            class="bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-2 rounded-lg font-semibold hover:from-primary-600 hover:to-purple-700 transition-all">
                        <i class="fas fa-plus mr-2"></i>New Message
                    </button>
                </div>
            </div>
        </nav>

        <div class="p-6 lg:p-8">
            <?php if ($error): ?>
                <div class="bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6 fade-in">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="bg-green-100 dark:bg-green-900/30 border-l-4 border-green-500 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg mb-6 fade-in">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <div class="grid lg:grid-cols-2 gap-6 fade-in">
                <!-- Received Messages -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">
                        <i class="fas fa-inbox mr-2"></i>Received (<?php echo count($receivedMessages); ?>)
                    </h2>
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        <?php if (empty($receivedMessages)): ?>
                            <p class="text-gray-600 dark:text-gray-400 text-center py-8">No messages received.</p>
                        <?php else: ?>
                            <?php foreach ($receivedMessages as $message): ?>
                                <div class="border-2 border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:border-primary-400 dark:hover:border-primary-600 transition-all">
                                    <div class="flex items-start justify-between mb-2">
                                        <div>
                                            <h4 class="font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($message['subject']); ?></h4>
                                            <p class="text-sm text-gray-600 dark:text-gray-400">From: <?php echo htmlspecialchars($message['sender_name']); ?></p>
                                        </div>
                                        <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo date('M d, Y', strtotime($message['created_at'])); ?></span>
                                    </div>
                                    <p class="text-sm text-gray-700 dark:text-gray-300 line-clamp-2"><?php echo htmlspecialchars($message['content']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sent Messages -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">
                        <i class="fas fa-paper-plane mr-2"></i>Sent (<?php echo count($sentMessages); ?>)
                    </h2>
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        <?php if (empty($sentMessages)): ?>
                            <p class="text-gray-600 dark:text-gray-400 text-center py-8">No messages sent.</p>
                        <?php else: ?>
                            <?php foreach ($sentMessages as $message): ?>
                                <div class="border-2 border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:border-primary-400 dark:hover:border-primary-600 transition-all">
                                    <div class="flex items-start justify-between mb-2">
                                        <div>
                                            <h4 class="font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($message['subject']); ?></h4>
                                            <p class="text-sm text-gray-600 dark:text-gray-400">To: <?php echo htmlspecialchars($message['recipient_name']); ?></p>
                                        </div>
                                        <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo date('M d, Y', strtotime($message['created_at'])); ?></span>
                                    </div>
                                    <p class="text-sm text-gray-700 dark:text-gray-300 line-clamp-2"><?php echo htmlspecialchars($message['content']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Compose Modal -->
    <div id="composeModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div class="bg-white dark:bg-gray-800 rounded-3xl p-8 max-w-md w-full shadow-2xl">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-2xl font-bold text-gray-800 dark:text-white">Compose Message</h3>
                <button onclick="closeComposeModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" id="composeForm">
                <input type="hidden" name="action" value="send">
                
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">To *</label>
                    <select name="recipient_id" required 
                            class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                        <option value="">Select Student</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>">
                                <?php echo htmlspecialchars($student['name'] . ' (' . $student['email'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Subject *</label>
                    <input type="text" name="subject" required 
                           class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Message *</label>
                    <textarea name="content" required rows="5" 
                              class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white"></textarea>
                </div>
                
                <div class="flex gap-4">
                    <button type="submit" 
                            class="flex-1 bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-3 rounded-lg font-bold hover:from-primary-600 hover:to-purple-700 transition-all">
                        <i class="fas fa-paper-plane mr-2"></i>Send
                    </button>
                    <button type="button" onclick="closeComposeModal()" 
                            class="bg-gray-500 text-white px-6 py-3 rounded-lg font-bold hover:bg-gray-600 transition-all">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openComposeModal() {
            document.getElementById('composeModal').classList.remove('hidden');
        }
        
        function closeComposeModal() {
            document.getElementById('composeModal').classList.add('hidden');
            document.getElementById('composeForm').reset();
        }
        
        document.getElementById('composeModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeComposeModal();
            }
        });
        
        // Ensure dark mode toggle works on this page
        document.addEventListener('DOMContentLoaded', function() {
            const html = document.documentElement;
            const darkModeCookie = document.cookie.split('; ').find(row => row.startsWith('dark_mode='));
            if (darkModeCookie && darkModeCookie.split('=')[1] === 'enabled') {
                html.classList.add('dark');
            } else {
                html.classList.remove('dark');
            }
        });
    </script>
</body>
</html>
