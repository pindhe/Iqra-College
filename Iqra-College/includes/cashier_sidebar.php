<?php
/**
 * Shared Cashier Sidebar Component
 */

if (!isset($cashierId)) { $cashierId = getCurrentUserId(); }
if (!isset($name)) { $name = getCurrentUserName(); }
if (!isset($pdo)) { $pdo = getDBConnection(); }
if (!isset($userAvatar)) {
    try {
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$cashierId]);
        $userAvatar = $stmt->fetch(PDO::FETCH_ASSOC)['avatar'] ?? null;
    } catch (PDOException $e) { $userAvatar = null; }
}
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);

$cashierNav = [
    ['href' => 'index.php', 'icon' => 'fa-home', 'label' => 'Dashboard'],
    ['href' => 'payments.php', 'icon' => 'fa-history', 'label' => 'Payment History'],
];
?>
<style>
.sidebar-link { transition: all 0.3s ease; }
.sidebar-link:hover { background: linear-gradient(90deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1)); transform: translateX(5px); }
.sidebar-link.active { background: linear-gradient(90deg, rgba(16, 185, 129, 0.2), rgba(5, 150, 105, 0.2)); border-left: 4px solid #10b981; font-weight: bold; }
.cashier-bg { background: linear-gradient(135deg, #059669 0%, #047857 100%); }
</style>

<div id="sidebar-overlay" class="lg:hidden fixed inset-0 bg-black bg-opacity-50 z-30 hidden"></div>

<aside id="sidebar" class="hidden lg:flex fixed left-0 top-0 h-full w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 z-40 flex-col">
    <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-br from-white to-emerald-50/30 dark:from-gray-800 dark:to-gray-900">
        <div class="flex items-center space-x-3">
            <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/../uploads/avatars/' . $userAvatar)): ?>
                <img src="../uploads/avatars/<?php echo htmlspecialchars($userAvatar); ?>" alt="Profile" class="w-14 h-14 rounded-2xl object-cover border-2 border-emerald-500 shadow-lg">
            <?php else: ?>
                <div class="cashier-bg w-14 h-14 rounded-2xl flex items-center justify-center shadow-lg">
                    <span class="text-white text-2xl font-bold"><?php echo strtoupper(substr($name, 0, 1)); ?></span>
                </div>
            <?php endif; ?>
            <div class="flex-1 min-w-0">
                <h2 class="font-extrabold text-gray-800 dark:text-white text-lg truncate"><?php echo htmlspecialchars(explode(' ', $name)[0]); ?></h2>
                <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium uppercase tracking-wide">Cashier</p>
            </div>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto p-4 space-y-1">
        <?php foreach ($cashierNav as $item): ?>
        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="sidebar-link <?php echo $currentPage === $item['href'] ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas <?php echo $item['icon']; ?> text-emerald-600 dark:text-emerald-400 w-5"></i>
            <span class="font-medium"><?php echo htmlspecialchars($item['label']); ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="p-4 border-t border-gray-200 dark:border-gray-700 space-y-3">
        <button id="dark-mode-toggle" onclick="if(window.toggleDarkMode) window.toggleDarkMode();" class="w-full flex items-center justify-between px-3 py-3 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors cursor-pointer">
            <div class="flex items-center space-x-2">
                <i class="fas fa-moon text-amber-500 dark:hidden w-5"></i>
                <i class="fas fa-sun text-amber-400 hidden dark:inline w-5"></i>
                <span class="font-medium">Dark Mode</span>
            </div>
        </button>
        <a href="../auth/logout.php" class="flex items-center space-x-3 p-3 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
            <i class="fas fa-sign-out-alt w-5"></i>
            <span class="font-medium">Logout</span>
        </a>
    </div>
</aside>

<aside id="mobile-sidebar" class="lg:hidden fixed left-0 top-0 h-full w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 z-40 flex flex-col -translate-x-full transition-transform duration-300">
    <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/../uploads/avatars/' . $userAvatar)): ?>
                <img src="../uploads/avatars/<?php echo htmlspecialchars($userAvatar); ?>" alt="Profile" class="w-12 h-12 rounded-2xl object-cover border-2 border-emerald-500">
            <?php else: ?>
                <div class="cashier-bg w-12 h-12 rounded-2xl flex items-center justify-center">
                    <span class="text-white text-xl font-bold"><?php echo strtoupper(substr($name, 0, 1)); ?></span>
                </div>
            <?php endif; ?>
            <div>
                <h2 class="font-extrabold text-gray-800 dark:text-white"><?php echo htmlspecialchars(explode(' ', $name)[0]); ?></h2>
                <p class="text-xs text-emerald-600 dark:text-emerald-400">Cashier</p>
            </div>
        </div>
        <button id="close-mobile-sidebar" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-200 p-2">
            <i class="fas fa-times text-xl"></i>
        </button>
    </div>
    <nav class="flex-1 overflow-y-auto p-4 space-y-1">
        <?php foreach ($cashierNav as $item): ?>
        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="sidebar-link <?php echo $currentPage === $item['href'] ? 'active' : ''; ?> flex items-center space-x-3 p-3 rounded-lg text-gray-700 dark:text-gray-300">
            <i class="fas <?php echo $item['icon']; ?> text-emerald-600 dark:text-emerald-400 w-5"></i>
            <span class="font-medium"><?php echo htmlspecialchars($item['label']); ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="p-4 border-t border-gray-200 dark:border-gray-700">
        <button id="dark-mode-toggle-mobile" onclick="if(window.toggleDarkMode) window.toggleDarkMode();" class="w-full flex items-center space-x-2 px-3 py-3 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer">
            <i class="fas fa-moon text-amber-500 dark:hidden w-5"></i>
            <i class="fas fa-sun text-amber-400 hidden dark:inline w-5"></i>
            <span class="font-medium">Dark Mode</span>
        </button>
        <a href="../auth/logout.php" class="flex items-center space-x-3 p-3 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">
            <i class="fas fa-sign-out-alt w-5"></i>
            <span class="font-medium">Logout</span>
        </a>
    </div>
</aside>

<script>
(function(){
    function getCookie(n){const v='; '+document.cookie;const p=v.split('; '+n+'=');return p.length===2?p.pop().split(';').shift():null;}
    function setCookie(n,v,d){const e=new Date();e.setTime(e.getTime()+(d*864e5));document.cookie=n+'='+v+'; expires='+e.toUTCString()+'; path=/';}
    if(getCookie('dark_mode')==='enabled')document.documentElement.classList.add('dark');
    else{document.documentElement.classList.remove('dark');if(!getCookie('dark_mode'))setCookie('dark_mode','disabled',365);}
    window.toggleDarkMode=function(){const h=document.documentElement;if(h.classList.contains('dark')){h.classList.remove('dark');setCookie('dark_mode','disabled',365);}else{h.classList.add('dark');setCookie('dark_mode','enabled',365);}};
})();
document.addEventListener('DOMContentLoaded',function(){
    var t=document.getElementById('mobile-menu-toggle'),s=document.getElementById('mobile-sidebar'),o=document.getElementById('sidebar-overlay'),c=document.getElementById('close-mobile-sidebar');
    t?.addEventListener('click',function(){s?.classList.remove('-translate-x-full');o?.classList.remove('hidden');});
    c?.addEventListener('click',function(){s?.classList.add('-translate-x-full');o?.classList.add('hidden');});
    o?.addEventListener('click',function(){s?.classList.add('-translate-x-full');o?.classList.add('hidden');});
});
</script>
