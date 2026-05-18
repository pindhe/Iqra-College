<?php
/**
 * Shared Admin Header Component
 * User dropdown for admin pages
 */

if (!isset($adminId)) {
    $adminId = getCurrentUserId();
}
if (!isset($name)) {
    $name = getCurrentUserName();
}
if (!isset($userAvatar)) {
    try {
        $p = getDBConnection();
        $st = $p->prepare("SELECT avatar FROM users WHERE id = ?");
        $st->execute([$adminId]);
        $userAvatar = $st->fetch(PDO::FETCH_ASSOC)['avatar'] ?? null;
    } catch (PDOException $e) {
        $userAvatar = null;
    }
}
?>
<div class="relative">
    <button id="user-menu-button" class="flex items-center space-x-3 bg-white dark:bg-gray-800 p-2 rounded-xl shadow border border-gray-200 dark:border-gray-700 hover:shadow-md transition-all">
        <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/../uploads/avatars/' . $userAvatar)): ?>
            <img src="../uploads/avatars/<?php echo htmlspecialchars($userAvatar); ?>" alt="Profile" class="w-10 h-10 rounded-xl object-cover border-2 border-indigo-500">
        <?php else: ?>
            <div class="bg-gradient-to-br from-indigo-500 to-purple-600 w-10 h-10 rounded-xl flex items-center justify-center">
                <span class="text-white text-base font-bold"><?php echo strtoupper(substr($name, 0, 1)); ?></span>
            </div>
        <?php endif; ?>
        <div class="hidden sm:block text-left">
            <span class="font-bold text-gray-800 dark:text-white block text-sm"><?php echo htmlspecialchars(explode(' ', $name)[0]); ?></span>
            <span class="text-xs text-gray-500 dark:text-gray-400">Admin</span>
        </div>
        <i class="fas fa-chevron-down text-gray-500 dark:text-gray-400 text-xs"></i>
    </button>
    <div id="user-dropdown" class="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 hidden z-50 overflow-hidden">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <p class="font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($name); ?></p>
            <p class="text-sm text-gray-500 dark:text-gray-400">Admin</p>
        </div>
        <div class="p-2">
            <a href="../auth/logout.php" class="flex items-center space-x-3 p-3 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 text-red-600 dark:text-red-400 transition-colors">
                <i class="fas fa-sign-out-alt w-5"></i>
                <span class="font-medium">Logout</span>
            </a>
        </div>
    </div>
</div>
<script>
(function(){
    var b=document.getElementById('user-menu-button'),d=document.getElementById('user-dropdown');
    if(b&&d){b.addEventListener('click',function(e){e.stopPropagation();d.classList.toggle('hidden');});document.addEventListener('click',function(e){if(!b.contains(e.target)&&!d.contains(e.target))d.classList.add('hidden');});}
})();
</script>
