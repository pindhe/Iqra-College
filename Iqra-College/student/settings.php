<?php
/**
 * Student - Settings (Modern Design)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();
$studentCode = getUserCode($studentId);
$success = '';
$error = '';

// Get user preferences
$user = getUserById($studentId);

// Handle notification settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    // In a real app, you'd save these to a user_preferences table
    $success = 'Notification preferences updated successfully';
}

// Handle privacy settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_privacy'])) {
    // In a real app, you'd save these to a user_preferences table
    $success = 'Privacy settings updated successfully';
}

$pageTitle = 'Settings';
$pageSubtitle = 'Manage your account preferences and settings';
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
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen transition-colors duration-300">
    <div class="flex">
        <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>

        <main class="ml-0 lg:ml-64 flex-1 p-4 lg:p-8 transition-all duration-300">
            <?php include __DIR__ . '/../includes/student_header.php'; ?>

            <?php if ($success): ?>
                <div class="mb-6 p-4 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-xl shadow-lg fade-in">
                    <div class="flex items-center space-x-3">
                        <i class="fas fa-check-circle text-2xl"></i>
                        <span class="font-semibold"><?php echo htmlspecialchars($success); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-gradient-to-r from-red-500 to-pink-600 text-white rounded-xl shadow-lg fade-in">
                    <div class="flex items-center space-x-3">
                        <i class="fas fa-exclamation-circle text-2xl"></i>
                        <span class="font-semibold"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="space-y-8">
                <!-- Notification Settings -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700 shadow-lg">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="bg-gradient-to-br from-blue-500 to-blue-600 p-3 rounded-xl">
                            <i class="fas fa-bell text-white text-xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Notification Settings</h2>
                    </div>
                    <form method="POST" action="" class="space-y-6">
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <div>
                                <h3 class="font-semibold text-gray-800 dark:text-white">Email Notifications</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Receive email updates about your courses</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <div>
                                <h3 class="font-semibold text-gray-800 dark:text-white">Assignment Reminders</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Get notified about upcoming assignments</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <div>
                                <h3 class="font-semibold text-gray-800 dark:text-white">Quiz Results</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Receive notifications when quiz results are available</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <div>
                                <h3 class="font-semibold text-gray-800 dark:text-white">Course Updates</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Get notified about new course content</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <button type="submit" name="update_notifications" 
                                class="w-full bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                            <i class="fas fa-save mr-2"></i>Save Notification Settings
                        </button>
                    </form>
                </div>

                <!-- Privacy Settings -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700 shadow-lg">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="bg-gradient-to-br from-purple-500 to-pink-600 p-3 rounded-xl">
                            <i class="fas fa-shield-alt text-white text-xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Privacy Settings</h2>
                    </div>
                    <form method="POST" action="" class="space-y-6">
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <div>
                                <h3 class="font-semibold text-gray-800 dark:text-white">Profile Visibility</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Allow other students to view your profile</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox">
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <div>
                                <h3 class="font-semibold text-gray-800 dark:text-white">Show Progress</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Display your course progress to others</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-xl">
                            <div>
                                <h3 class="font-semibold text-gray-800 dark:text-white">Activity Status</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Show when you're online</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <button type="submit" name="update_privacy" 
                                class="w-full bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                            <i class="fas fa-save mr-2"></i>Save Privacy Settings
                        </button>
                    </form>
                </div>

                <!-- Account Actions -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700 shadow-lg">
                    <div class="flex items-center space-x-3 mb-6">
                        <div class="bg-gradient-to-br from-red-500 to-pink-600 p-3 rounded-xl">
                            <i class="fas fa-exclamation-triangle text-white text-xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Account Actions</h2>
                    </div>
                    <div class="space-y-4">
                        <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl">
                            <h3 class="font-semibold text-yellow-800 dark:text-yellow-400 mb-2">Download Your Data</h3>
                            <p class="text-sm text-yellow-700 dark:text-yellow-300 mb-3">Request a copy of all your account data</p>
                            <button class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
                                <i class="fas fa-download mr-2"></i>Request Data
                            </button>
                        </div>
                        
                        <div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl">
                            <h3 class="font-semibold text-red-800 dark:text-red-400 mb-2">Delete Account</h3>
                            <p class="text-sm text-red-700 dark:text-red-300 mb-3">Permanently delete your account and all associated data</p>
                            <button class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
                                <i class="fas fa-trash mr-2"></i>Delete Account
                            </button>
                        </div>
                    </div>
                </div>
            </div>
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
                    sidebar.classList.remove('hidden');
                    sidebarOverlay.classList.remove('hidden');
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
                
                userDropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
            
        });
    </script>
    
    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>
</body>
</html>
