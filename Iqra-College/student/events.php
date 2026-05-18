<?php
/**
 * Student - Events (View Only)
 * View all calendar events
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();
$studentCode = getUserCode($studentId);

// Get all events
try {
    $stmt = $pdo->query("SELECT e.*, u.name as created_by_name 
                         FROM events e 
                         LEFT JOIN users u ON e.created_by = u.id 
                         WHERE e.event_date >= CURDATE()
                         ORDER BY e.event_date ASC");
    $events = $stmt->fetchAll();
} catch (PDOException $e) {
    // If events table doesn't exist, show empty
    $events = [];
}

$pageTitle = 'Events';
$currentPage = 'events.php';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Student</title>
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
    <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>
    
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">View upcoming events</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <?php include __DIR__ . '/../includes/student_header.php'; ?>
                    </div>
                </div>
            </div>
        </nav>

        <div class="p-6 lg:p-8">
            <!-- Events List -->
            <?php if (empty($events)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-12 text-center">
                    <i class="fas fa-calendar-check text-6xl text-gray-400 mb-4"></i>
                    <p class="text-xl text-gray-600 dark:text-gray-400">No upcoming events found.</p>
                </div>
            <?php else: ?>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6 fade-in">
                    <?php foreach ($events as $event): 
                        $eventDate = new DateTime($event['event_date']);
                        $isToday = $eventDate->format('Y-m-d') === date('Y-m-d');
                        $isUpcoming = $eventDate > new DateTime();
                    ?>
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 border-2 border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-600 transition-all <?php echo $isToday ? 'ring-2 ring-primary-500' : ''; ?>">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex-1">
                                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-2">
                                        <?php echo htmlspecialchars($event['title']); ?>
                                    </h3>
                                    <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-2">
                                        <i class="fas fa-calendar-days text-primary-500"></i>
                                        <span><?php echo $eventDate->format('M d, Y'); ?></span>
                                        <?php if ($isToday): ?>
                                            <span class="ml-2 px-2 py-1 bg-primary-500 text-white rounded-full text-xs font-semibold">Today</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($eventDate->format('H:i') !== '00:00'): ?>
                                        <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-2">
                                            <i class="fas fa-clock text-primary-500"></i>
                                            <span><?php echo $eventDate->format('h:i A'); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($event['location'])): ?>
                                        <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-2">
                                            <i class="fas fa-map-marker-alt text-primary-500"></i>
                                            <span><?php echo htmlspecialchars($event['location']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($event['description'])): ?>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-3 line-clamp-3">
                                            <?php echo htmlspecialchars($event['description']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div class="ml-4">
                                    <span class="px-3 py-1 bg-primary-100 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400 rounded-full text-xs font-semibold">
                                        <?php echo ucfirst($event['event_type'] ?? 'general'); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-user mr-1"></i>
                                    <?php echo htmlspecialchars($event['created_by_name'] ?? 'Admin'); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>

    <script>
        // Ensure dark mode is initialized on page load
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
