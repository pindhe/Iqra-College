<?php
/**
 * Student - Notifications (Modern Design)
 * Auto-generates notifications for course completion and payment acceptance
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();

// Function to create notification
function createNotification($pdo, $userId, $title, $message, $type = 'info', $link = null) {
    try {
        // Check if notification already exists (to avoid duplicates)
        $stmt = $pdo->prepare("
            SELECT id FROM notifications 
            WHERE user_id = ? AND title = ? AND message = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stmt->execute([$userId, $title, $message]);
        if ($stmt->fetch()) {
            return false; // Notification already exists
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, link, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $title, $message, $type, $link]);
        return true;
    } catch (PDOException $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

// Check for completed courses and create congratulations notifications
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.title, e.enrollment_id,
               COUNT(l.id) as total_lessons,
               COUNT(ul.lesson_id) as completed_lessons
        FROM enrollments e
        JOIN courses c ON e.course_id = c.id
        LEFT JOIN lessons l ON l.course_id = c.id
        LEFT JOIN lesson_progress ul ON ul.lesson_id = l.id 
            AND ul.student_id = ? 
            AND ul.completed = 1
        WHERE e.student_id = ? 
        AND e.access_granted = 1
        GROUP BY c.id, c.title, e.enrollment_id
        HAVING total_lessons > 0 AND completed_lessons = total_lessons
    ");
    $stmt->execute([$studentId, $studentId]);
    $completedCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($completedCourses as $course) {
        $title = "🎉 Congratulations! Course Completed";
        $message = "Congratulations! You have successfully completed the course: " . $course['title'] . ". Great job on your achievement!";
        $link = "/Iqra-College/student/courses.php?id=" . $course['id'];
        createNotification($pdo, $studentId, $title, $message, 'success', $link);
    }
} catch (PDOException $e) {
    error_log("Error checking completed courses: " . $e->getMessage());
}

// Check for accepted/verified payments and create notifications
try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.course_id, p.amount, c.title as course_title, p.verified_at
        FROM payments p
        JOIN courses c ON p.course_id = c.id
        WHERE p.student_id = ? 
        AND p.status = 'verified'
        AND p.verified_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND NOT EXISTS (
            SELECT 1 FROM notifications n 
            WHERE n.user_id = ? 
            AND n.title LIKE '%Payment Accepted%'
            AND n.message LIKE CONCAT('%', c.title, '%')
            AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        )
    ");
    $stmt->execute([$studentId, $studentId]);
    $verifiedPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($verifiedPayments as $payment) {
        $title = "✅ Payment Accepted";
        $message = "Your payment of $" . number_format($payment['amount'], 2) . " for the course \"" . $payment['course_title'] . "\" has been accepted and verified. You now have full access to the course!";
        $link = "/Iqra-College/student/courses.php?id=" . $payment['course_id'];
        createNotification($pdo, $studentId, $title, $message, 'success', $link);
    }
} catch (PDOException $e) {
    error_log("Error checking verified payments: " . $e->getMessage());
}

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $notificationId = intval($_POST['notification_id'] ?? 0);
    if ($notificationId > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$notificationId, $studentId]);
        } catch (PDOException $e) {
            // Silent fail
        }
    }
    header('Location: notifications.php');
    exit;
}

// Handle mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$studentId]);
    } catch (PDOException $e) {
        // Silent fail
    }
    header('Location: notifications.php');
    exit;
}

// Get all notifications
try {
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$studentId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $notifications = [];
}

// Get unread count
$unreadCount = count(array_filter($notifications, function($n) {
    return !($n['is_read'] ?? false);
}));

// Separate read and unread
$unreadNotifications = array_filter($notifications, function($n) {
    return !($n['is_read'] ?? false);
});
$readNotifications = array_filter($notifications, function($n) {
    return $n['is_read'] ?? false;
});

function getNotificationIcon($type) {
    switch ($type) {
        case 'success': return 'fa-check-circle';
        case 'warning': return 'fa-exclamation-triangle';
        case 'error': return 'fa-times-circle';
        case 'info': 
        default: return 'fa-info-circle';
    }
}

function getNotificationColor($type) {
    switch ($type) {
        case 'success': return 'green';
        case 'warning': return 'yellow';
        case 'error': return 'red';
        case 'info':
        default: return 'blue';
    }
}

$pageTitle = 'Notifications';
$pageSubtitle = 'Stay updated with your course activities';
$currentPage = 'notifications';
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
        .card-hover {
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .card-hover:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }
        .dark .card-hover:hover {
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .stagger-item {
            opacity: 0;
            transform: translateY(30px) scale(0.95);
            animation: slideInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        @keyframes slideInUp {
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        .stagger-item:nth-child(1) { animation-delay: 0.1s; }
        .stagger-item:nth-child(2) { animation-delay: 0.2s; }
        .stagger-item:nth-child(3) { animation-delay: 0.3s; }
        .stagger-item:nth-child(4) { animation-delay: 0.4s; }
        .notification-unread {
            background: linear-gradient(to right, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05));
            border-left: 4px solid #3b82f6;
        }
        .dark .notification-unread {
            background: linear-gradient(to right, rgba(59, 130, 246, 0.2), rgba(59, 130, 246, 0.1));
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen transition-colors duration-300">
    <div class="flex">
        <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>

        <main class="ml-0 lg:ml-64 flex-1 p-4 lg:p-8 transition-all duration-300">
            <?php include __DIR__ . '/../includes/student_header.php'; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="card-hover bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-blue-200 dark:bg-blue-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Unread</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-blue-600 to-blue-800 dark:from-blue-400 dark:to-blue-600 bg-clip-text text-transparent"><?php echo $unreadCount; ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">new notifications</p>
                        </div>
                        <div class="bg-gradient-to-br from-blue-500 to-blue-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-bell text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-green-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-green-200 dark:bg-green-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Total</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-green-600 to-emerald-600 dark:from-green-400 dark:to-emerald-400 bg-clip-text text-transparent"><?php echo count($notifications); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">all notifications</p>
                        </div>
                        <div class="bg-gradient-to-br from-green-500 to-emerald-600 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-list text-white text-2xl"></i>
                        </div>
                    </div>
                </div>

                <div class="card-hover bg-gradient-to-br from-white to-purple-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-lg stagger-item relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-purple-200 dark:bg-purple-900/30 rounded-full -mr-16 -mt-16 opacity-20"></div>
                    <div class="relative flex justify-between items-center">
                        <div>
                            <p class="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-wide mb-2">Read</p>
                            <p class="text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-purple-800 dark:from-purple-400 dark:to-purple-600 bg-clip-text text-transparent"><?php echo count($readNotifications); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-2">viewed notifications</p>
                        </div>
                        <div class="bg-gradient-to-br from-purple-500 to-purple-700 p-4 rounded-2xl shadow-lg">
                            <i class="fas fa-check-double text-white text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <?php if ($unreadCount > 0): ?>
                <div class="mb-6 fade-in">
                    <form method="POST" action="" class="inline">
                        <button type="submit" name="mark_all_read" 
                                class="bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white px-6 py-3 rounded-xl font-semibold transition-all transform hover:scale-105 shadow-lg">
                            <i class="fas fa-check-double mr-2"></i>Mark All as Read
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Unread Notifications -->
            <?php if (!empty($unreadNotifications)): ?>
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white flex items-center">
                            <i class="fas fa-envelope text-primary-600 dark:text-primary-400 mr-3"></i>
                            Unread Notifications
                            <span class="ml-3 px-3 py-1 bg-primary-100 dark:bg-primary-900/20 text-primary-800 dark:text-primary-400 rounded-full text-sm font-semibold">
                                <?php echo count($unreadNotifications); ?>
                            </span>
                        </h2>
                    </div>
                    <div class="p-6 space-y-4">
                        <?php foreach ($unreadNotifications as $notification): 
                            $color = getNotificationColor($notification['type'] ?? 'info');
                            $icon = getNotificationIcon($notification['type'] ?? 'info');
                            $colorClasses = [
                                'green' => 'from-green-500 to-emerald-600',
                                'yellow' => 'from-yellow-500 to-yellow-600',
                                'red' => 'from-red-500 to-red-600',
                                'blue' => 'from-blue-500 to-blue-600'
                            ];
                            $bgColor = $colorClasses[$color] ?? $colorClasses['blue'];
                        ?>
                            <div class="notification-unread p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-all">
                                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                                    <div class="flex items-start space-x-4 flex-1">
                                        <div class="bg-gradient-to-br <?php echo $bgColor; ?> p-4 rounded-xl shadow-lg">
                                            <i class="fas <?php echo $icon; ?> text-white text-xl"></i>
                                        </div>
                                        <div class="flex-1">
                                            <h3 class="font-bold text-lg text-gray-800 dark:text-white mb-2">
                                                <?php echo htmlspecialchars($notification['title']); ?>
                                            </h3>
                                            <p class="text-gray-600 dark:text-gray-400 mb-3">
                                                <?php echo htmlspecialchars($notification['message']); ?>
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-500 flex items-center">
                                                <i class="fas fa-clock mr-2"></i>
                                                <?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <form method="POST" action="" class="inline">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                            <button type="submit" name="mark_read" 
                                                    class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-4 py-2 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-sm font-semibold">
                                                <i class="fas fa-check mr-2"></i>Mark Read
                                            </button>
                                        </form>
                                        <?php if (!empty($notification['link'])): ?>
                                            <a href="<?php echo htmlspecialchars($notification['link']); ?>" 
                                               class="bg-gradient-to-r <?php echo $bgColor; ?> hover:opacity-90 text-white px-4 py-2 rounded-xl transition-all transform hover:scale-105 shadow-lg text-sm font-semibold">
                                                <i class="fas fa-arrow-right mr-2"></i>View
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Read Notifications -->
            <?php if (!empty($readNotifications)): ?>
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white flex items-center">
                            <i class="fas fa-envelope-open text-gray-400 dark:text-gray-500 mr-3"></i>
                            Read Notifications
                            <span class="ml-3 px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded-full text-sm font-semibold">
                                <?php echo count($readNotifications); ?>
                            </span>
                        </h2>
                    </div>
                    <div class="p-6 space-y-4">
                        <?php foreach ($readNotifications as $notification): 
                            $color = getNotificationColor($notification['type'] ?? 'info');
                            $icon = getNotificationIcon($notification['type'] ?? 'info');
                        ?>
                            <div class="p-6 bg-gray-50 dark:bg-gray-700/50 rounded-xl border border-gray-200 dark:border-gray-700 opacity-75 hover:opacity-100 transition-opacity">
                                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                                    <div class="flex items-start space-x-4 flex-1">
                                        <div class="bg-gray-200 dark:bg-gray-600 p-4 rounded-xl">
                                            <i class="fas <?php echo $icon; ?> text-gray-600 dark:text-gray-400 text-xl"></i>
                                        </div>
                                        <div class="flex-1">
                                            <h3 class="font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                                <?php echo htmlspecialchars($notification['title']); ?>
                                            </h3>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                                <?php echo htmlspecialchars($notification['message']); ?>
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-500 flex items-center flex-wrap gap-3">
                                                <span>
                                                    <i class="fas fa-clock mr-1"></i>
                                                    <?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?>
                                                </span>
                                                <?php if ($notification['read_at']): ?>
                                                    <span>
                                                        <i class="fas fa-check mr-1"></i>
                                                        Read: <?php echo date('M j, Y', strtotime($notification['read_at'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <?php if (!empty($notification['link'])): ?>
                                        <div>
                                            <a href="<?php echo htmlspecialchars($notification['link']); ?>" 
                                               class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-xl transition-colors text-sm font-semibold">
                                                <i class="fas fa-arrow-right mr-2"></i>View
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Empty State -->
            <?php if (empty($notifications)): ?>
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg fade-in p-12 text-center">
                    <div class="inline-block bg-gray-100 dark:bg-gray-700 p-6 rounded-full mb-4">
                        <i class="fas fa-bell-slash text-gray-400 dark:text-gray-500 text-5xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">No Notifications</h3>
                    <p class="text-gray-600 dark:text-gray-400">You don't have any notifications yet. Notifications will appear here when you complete courses, payments are accepted, or other important updates occur.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 hidden lg:hidden"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', () => {
                    sidebar.classList.toggle('hidden');
                    sidebarOverlay.classList.toggle('hidden');
                });
            }
            
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', () => {
                    sidebar.classList.add('hidden');
                    sidebarOverlay.classList.add('hidden');
                });
            }
            
            // User dropdown menu
            const userMenuButton = document.getElementById('user-menu-button');
            const userDropdown = document.getElementById('user-dropdown');
            
            if (userMenuButton && userDropdown) {
                userMenuButton.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('hidden');
                });
                
                document.addEventListener('click', function(e) {
                    if (!userMenuButton.contains(e.target) && !userDropdown.contains(e.target)) {
                        userDropdown.classList.add('hidden');
                    }
                });
            }
            
        });
    </script>
    
    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>
</body>
</html>
