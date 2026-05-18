<?php
/**
 * Shared Teacher Header Component
 * Include this in all teacher pages for consistent header with dropdown menu
 */

// Get teacher data if not already loaded
if (!isset($teacherId)) {
    $teacherId = getCurrentUserId();
}
if (!isset($name)) {
    $name = getCurrentUserName();
}

// Get user avatar if not already loaded
if (!isset($userAvatar)) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$teacherId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $userAvatar = $result['avatar'] ?? null;
    } catch (PDOException $e) {
        $userAvatar = null;
    }
}
?>
<!-- User Profile Dropdown -->
<div class="relative">
    <button id="user-menu-button" 
            class="flex items-center space-x-3 bg-gradient-to-r from-white to-gray-50 dark:from-gray-800 dark:to-gray-700 p-2 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all group">
        <div class="relative">
            <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/../uploads/avatars/' . $userAvatar)): ?>
                <img src="/Iqra-College/uploads/avatars/<?php echo htmlspecialchars($userAvatar); ?>" 
                     alt="Profile" 
                     class="w-10 h-10 rounded-xl object-cover border-2 border-primary-500 shadow-lg group-hover:scale-110 transition-transform">
            <?php else: ?>
                <div class="bg-gradient-to-br from-primary-500 via-purple-500 to-pink-500 w-10 h-10 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                    <span class="text-white text-base font-bold">
                        <?php echo strtoupper(substr($name, 0, 1)); ?>
                    </span>
                </div>
            <?php endif; ?>
            <div class="absolute -bottom-1 -right-1 w-3 h-3 bg-green-500 rounded-full border-2 border-white dark:border-gray-800 shadow-md animate-pulse"></div>
        </div>
        <div class="hidden lg:block text-left">
            <span class="font-bold text-gray-800 dark:text-white block text-sm"><?php echo htmlspecialchars(explode(' ', $name)[0]); ?></span>
            <span class="text-xs text-gray-500 dark:text-gray-400 font-medium">Teacher</span>
        </div>
        <i class="fas fa-chevron-down text-gray-500 dark:text-gray-400 text-xs ml-1"></i>
    </button>
    
    <!-- Dropdown Menu -->
    <div id="user-dropdown" 
         class="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 hidden z-50 overflow-hidden">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-r from-primary-50 to-purple-50 dark:from-primary-900/20 dark:to-purple-900/20">
            <p class="font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($name); ?></p>
            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Teacher</p>
        </div>
        <div class="p-2">
            <a href="/Iqra-College/teacher/profile.php" class="flex items-center space-x-3 p-3 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors group">
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 p-2 rounded-lg group-hover:scale-110 transition-transform">
                    <i class="fas fa-user text-white text-sm"></i>
                </div>
                <span class="font-medium text-gray-700 dark:text-gray-300">My Profile</span>
            </a>
            <a href="/Iqra-College/teacher/settings.php" class="flex items-center space-x-3 p-3 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors group">
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

<script>
    // User dropdown menu functionality
    (function() {
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
    })();
</script>
