<?php
/**
 * Shared Student Header Component
 * Include this in all student pages for consistent header
 */

// Get student data if not already loaded
if (!isset($studentId)) {
    $studentId = getCurrentUserId();
}
if (!isset($name)) {
    $name = getCurrentUserName();
}
if (!isset($studentCode)) {
    $studentCode = getUserCode($studentId);
}

// Get user avatar if not already loaded
if (!isset($userAvatar)) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$studentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $userAvatar = $result['avatar'] ?? null;
    } catch (PDOException $e) {
        $userAvatar = null;
    }
}

if (!isset($unreadNotifications)) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$studentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        // Ensure it's always an integer
        $unreadNotifications = isset($result['count']) ? intval($result['count']) : 0;
    } catch (PDOException $e) {
        $unreadNotifications = 0;
    }
}

// Ensure $unreadNotifications is always an integer (safety check)
if (isset($unreadNotifications) && is_array($unreadNotifications)) {
    $unreadNotifications = 0;
} elseif (isset($unreadNotifications)) {
    $unreadNotifications = intval($unreadNotifications);
} else {
    $unreadNotifications = 0;
}
?>
<!-- Top Navigation -->
<div class="mb-8 fade-in">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="flex items-center space-x-4">
            <button id="mobile-menu-toggle" class="lg:hidden text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded-lg transition-colors">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <div class="relative">
                <h1 class="text-3xl lg:text-4xl font-extrabold text-gray-800 dark:text-white">
                    <?php echo isset($pageTitle) ? $pageTitle : 'Dashboard'; ?>
                </h1>
                <?php if (isset($pageSubtitle)): ?>
                <p class="text-gray-600 dark:text-gray-400 mt-2 text-lg">
                    <?php echo $pageSubtitle; ?>
                </p>
                <?php endif; ?>
                <div class="absolute -bottom-2 left-0 w-24 h-1 bg-gradient-to-r from-primary-500 to-purple-500 rounded-full"></div>
            </div>
        </div>
        
        <div class="flex items-center space-x-3">
            <!-- Search Bar -->
            <div class="relative hidden lg:block">
                <div class="relative">
                    <input type="text" 
                           placeholder="Search courses, materials..."
                           class="pl-10 pr-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent w-64 transition-colors">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400 dark:text-gray-500"></i>
                </div>
            </div>
            
            <!-- Notification Bell -->
            <div class="relative">
                <a href="notifications.php" class="relative bg-white dark:bg-gray-800 p-3 rounded-xl shadow-lg hover:shadow-xl transition-all hover:scale-110 border border-gray-200 dark:border-gray-700 group block">
                    <i class="fas fa-bell text-gray-600 dark:text-gray-400 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors"></i>
                    <?php if (intval($unreadNotifications) > 0): ?>
                        <span class="absolute -top-1 -right-1 w-6 h-6 bg-gradient-to-r from-red-500 to-pink-500 text-white text-xs rounded-full flex items-center justify-center animate-bounce shadow-lg font-bold">
                            <?php echo intval($unreadNotifications); ?>
                        </span>
                    <?php endif; ?>
                </a>
            </div>
            
            <!-- User Profile Dropdown -->
            <div class="relative">
                <button id="user-menu-button" 
                        class="flex items-center space-x-3 bg-gradient-to-r from-white to-gray-50 dark:from-gray-800 dark:to-gray-700 p-3 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all group">
                    <div class="relative">
                        <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/../uploads/avatars/' . $userAvatar)): ?>
                            <img src="/Iqra-College/uploads/avatars/<?php echo htmlspecialchars($userAvatar); ?>" 
                                 alt="Profile" 
                                 class="w-12 h-12 rounded-xl object-cover border-2 border-primary-500 shadow-lg group-hover:scale-110 transition-transform">
                        <?php else: ?>
                            <div class="bg-gradient-to-br from-primary-500 via-purple-500 to-pink-500 w-12 h-12 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                                <span class="text-white text-lg font-bold">
                                    <?php echo strtoupper(substr($name, 0, 1)); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-green-500 rounded-full border-2 border-white dark:border-gray-800 shadow-md animate-pulse"></div>
                    </div>
                    <div class="hidden lg:block text-left">
                        <span class="font-bold text-gray-800 dark:text-white block"><?php echo htmlspecialchars(explode(' ', $name)[0]); ?></span>
                        <span class="text-xs text-gray-500 dark:text-gray-400 font-medium"><?php echo $studentCode; ?></span>
                    </div>
                    <i class="fas fa-chevron-down text-gray-500 dark:text-gray-400 text-sm ml-2"></i>
                </button>
                
                <!-- Dropdown Menu -->
                <div id="user-dropdown" 
                     class="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50 overflow-hidden">
                    <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-r from-primary-50 to-purple-50 dark:from-primary-900/20 dark:to-purple-900/20">
                        <p class="font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($name); ?></p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 font-mono"><?php echo $studentCode; ?></p>
                    </div>
                    <div class="p-2">
                        <a href="profile.php" class="flex items-center space-x-3 p-3 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors group">
                            <div class="bg-gradient-to-br from-blue-500 to-blue-600 p-2 rounded-lg group-hover:scale-110 transition-transform">
                                <i class="fas fa-user text-white text-sm"></i>
                            </div>
                            <span class="font-medium text-gray-700 dark:text-gray-300">My Profile</span>
                        </a>
                        <a href="settings.php" class="flex items-center space-x-3 p-3 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors group">
                            <div class="bg-gradient-to-br from-purple-500 to-pink-600 p-2 rounded-lg group-hover:scale-110 transition-transform">
                                <i class="fas fa-cog text-white text-sm"></i>
                            </div>
                            <span class="font-medium text-gray-700 dark:text-gray-300">Settings</span>
                        </a>
                        <div class="border-t border-gray-200 dark:border-gray-700 my-2"></div>
                        <a href="/Iqra-College/auth/logout.php" class="flex items-center space-x-3 p-3 rounded-xl hover:bg-red-50 dark:hover:bg-red-900/20 text-red-600 dark:text-red-400 transition-colors group">
                            <div class="bg-red-500 p-2 rounded-lg group-hover:scale-110 transition-transform">
                                <i class="fas fa-sign-out-alt text-white text-sm"></i>
                            </div>
                            <span class="font-medium">Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
