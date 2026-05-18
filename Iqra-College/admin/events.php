<?php
/**
 * Admin - Events Management
 * Create and manage events for the calendar
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

$pdo = getDBConnection();
$name = getCurrentUserName();
$error = '';
$success = '';

// Handle event creation/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $eventId = intval($_POST['event_id'] ?? 0);
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $eventDate = sanitize($_POST['event_date'] ?? '');
        $eventTime = sanitize($_POST['event_time'] ?? '');
        $location = sanitize($_POST['location'] ?? '');
        $eventType = sanitize($_POST['event_type'] ?? 'general');
        
        if (empty($title) || empty($eventDate)) {
            $error = 'Title and date are required.';
        } else {
            try {
                $datetime = $eventDate . ($eventTime ? ' ' . $eventTime : ' 00:00:00');
                
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO events (title, description, event_date, location, event_type, created_by) 
                                          VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$title, $description, $datetime, $location, $eventType, getCurrentUserId()]);
                    $success = 'Event created successfully!';
                } else {
                    $stmt = $pdo->prepare("UPDATE events SET title = ?, description = ?, event_date = ?, location = ?, event_type = ? 
                                          WHERE id = ?");
                    $stmt->execute([$title, $description, $datetime, $location, $eventType, $eventId]);
                    $success = 'Event updated successfully!';
                }
            } catch (PDOException $e) {
                $error = 'Failed to save event. Please try again.';
            }
        }
    } elseif ($action === 'delete') {
        $eventId = intval($_POST['event_id'] ?? 0);
        if ($eventId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
                $stmt->execute([$eventId]);
                $success = 'Event deleted successfully!';
            } catch (PDOException $e) {
                $error = 'Failed to delete event.';
            }
        }
    }
}

// Get all events
try {
    $stmt = $pdo->query("SELECT e.*, u.name as created_by_name 
                         FROM events e 
                         LEFT JOIN users u ON e.created_by = u.id 
                         ORDER BY e.event_date DESC");
    $events = $stmt->fetchAll();
} catch (PDOException $e) {
    // If events table doesn't exist, create it
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            event_date DATETIME NOT NULL,
            location VARCHAR(255),
            event_type VARCHAR(50) DEFAULT 'general',
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )");
        $events = [];
    } catch (PDOException $ex) {
        $events = [];
    }
}

// Get event for editing
$editEvent = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    foreach ($events as $event) {
        if ($event['id'] == $editId) {
            $editEvent = $event;
            break;
        }
    }
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
    <title><?php echo $pageTitle; ?> - Admin</title>
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
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <div class="lg:ml-64">
        <header class="bg-white dark:bg-gray-800 shadow border-b border-gray-200 dark:border-gray-700 sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <button id="mobile-menu-toggle" class="lg:hidden p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-bars text-xl"></i></button>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Manage calendar events</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <?php include __DIR__ . '/../includes/admin_header.php'; ?>
                        <button onclick="openEventModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl font-semibold transition-all">
                            <i class="fas fa-plus mr-2"></i>New Event
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 sm:p-6 lg:p-8">
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

            <!-- Events List -->
            <?php if (empty($events)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-12 text-center">
                    <i class="fas fa-calendar-check text-6xl text-gray-400 mb-4"></i>
                    <p class="text-xl text-gray-600 dark:text-gray-400">No events found.</p>
                    <button onclick="openEventModal()" 
                        class="mt-4 bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-2 rounded-lg font-semibold hover:from-primary-600 hover:to-purple-700 transition-all">
                        <i class="fas fa-plus mr-2"></i>Create First Event
                    </button>
                </div>
            <?php else: ?>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6 fade-in">
                    <?php foreach ($events as $event): 
                        $eventDate = new DateTime($event['event_date']);
                        $isPast = $eventDate < new DateTime();
                    ?>
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 border-2 border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-600 transition-all <?php echo $isPast ? 'opacity-75' : ''; ?>">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex-1">
                                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-2">
                                        <?php echo htmlspecialchars($event['title']); ?>
                                    </h3>
                                    <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-2">
                                        <i class="fas fa-calendar-days text-primary-500"></i>
                                        <span><?php echo $eventDate->format('M d, Y'); ?></span>
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
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-3 line-clamp-2">
                                            <?php echo htmlspecialchars($event['description']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-col space-y-2 ml-4">
                                    <span class="px-3 py-1 bg-primary-100 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400 rounded-full text-xs font-semibold">
                                        <?php echo ucfirst($event['event_type'] ?? 'general'); ?>
                                    </span>
                                    <?php if ($isPast): ?>
                                        <span class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded-full text-xs font-semibold">
                                            Past
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="pt-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    Created by <?php echo htmlspecialchars($event['created_by_name'] ?? 'Admin'); ?>
                                </span>
                                <div class="flex space-x-2">
                                    <a href="?edit=<?php echo $event['id']; ?>" 
                                       class="p-2 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition-colors"
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this event?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                                        <button type="submit" 
                                                class="p-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Event Modal -->
    <div id="eventModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div class="bg-white dark:bg-gray-800 rounded-3xl p-8 max-w-md w-full shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-2xl font-bold text-gray-800 dark:text-white">
                    <?php echo $editEvent ? 'Edit Event' : 'Create New Event'; ?>
                </h3>
                <button onclick="closeEventModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" id="eventForm">
                <input type="hidden" name="action" value="<?php echo $editEvent ? 'edit' : 'add'; ?>">
                <?php if ($editEvent): ?>
                    <input type="hidden" name="event_id" value="<?php echo $editEvent['id']; ?>">
                <?php endif; ?>
                
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Title *</label>
                    <input type="text" name="title" required 
                           value="<?php echo htmlspecialchars($editEvent['title'] ?? ''); ?>"
                           class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Description</label>
                    <textarea name="description" rows="3" 
                              class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($editEvent['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="grid md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Date *</label>
                        <input type="date" name="event_date" required 
                               value="<?php echo $editEvent ? date('Y-m-d', strtotime($editEvent['event_date'])) : ''; ?>"
                               class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Time</label>
                        <input type="time" name="event_time" 
                               value="<?php echo $editEvent && $editEvent['event_date'] ? date('H:i', strtotime($editEvent['event_date'])) : ''; ?>"
                               class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Location</label>
                    <input type="text" name="location" 
                           value="<?php echo htmlspecialchars($editEvent['location'] ?? ''); ?>"
                           placeholder="Event location (optional)"
                           class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Event Type</label>
                    <select name="event_type" 
                            class="w-full px-4 py-2 border-2 border-gray-200 dark:border-gray-700 rounded-lg focus:border-primary-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                        <option value="general" <?php echo ($editEvent['event_type'] ?? 'general') === 'general' ? 'selected' : ''; ?>>General</option>
                        <option value="meeting" <?php echo ($editEvent['event_type'] ?? '') === 'meeting' ? 'selected' : ''; ?>>Meeting</option>
                        <option value="deadline" <?php echo ($editEvent['event_type'] ?? '') === 'deadline' ? 'selected' : ''; ?>>Deadline</option>
                        <option value="holiday" <?php echo ($editEvent['event_type'] ?? '') === 'holiday' ? 'selected' : ''; ?>>Holiday</option>
                        <option value="announcement" <?php echo ($editEvent['event_type'] ?? '') === 'announcement' ? 'selected' : ''; ?>>Announcement</option>
                    </select>
                </div>
                
                <div class="flex gap-4">
                    <button type="submit" 
                            class="flex-1 bg-gradient-to-r from-primary-500 to-purple-600 text-white px-6 py-3 rounded-lg font-bold hover:from-primary-600 hover:to-purple-700 transition-all">
                        <i class="fas fa-save mr-2"></i><?php echo $editEvent ? 'Update' : 'Create'; ?> Event
                    </button>
                    <button type="button" onclick="closeEventModal()" 
                            class="bg-gray-500 text-white px-6 py-3 rounded-lg font-bold hover:bg-gray-600 transition-all">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEventModal() {
            document.getElementById('eventModal').classList.remove('hidden');
        }
        
        function closeEventModal() {
            document.getElementById('eventModal').classList.add('hidden');
            <?php if ($editEvent): ?>
                window.location.href = 'events.php';
            <?php endif; ?>
        }
        
        document.getElementById('eventModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeEventModal();
            }
        });
        
        <?php if ($editEvent): ?>
            openEventModal();
        <?php endif; ?>
        
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
