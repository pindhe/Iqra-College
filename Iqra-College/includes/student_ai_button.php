<?php
/**
 * Floating AI Assistant Button Component
 * Include this in all student pages for easy access to AI Assistant
 */

// Don't show on AI Assistant or Messages pages
$currentPage = basename($_SERVER['PHP_SELF']);
if ($currentPage === 'messages.php' || $currentPage === 'ai_assistant.php') {
    return;
}

$sitePhone = function_exists('getSetting') ? getSetting('site_phone', '636249555') : '';
$siteFacebook = function_exists('getSetting') ? getSetting('site_facebook', 'https://facebook.com') : 'https://facebook.com';
$whatsappNumber = preg_replace('/\D+/', '', (string)$sitePhone);
$whatsappLink = $whatsappNumber !== '' ? 'https://wa.me/' . $whatsappNumber : 'https://wa.me/';
$facebookLink = $siteFacebook !== '' ? $siteFacebook : 'https://facebook.com';
?>
<!-- Floating Contact + AI Buttons -->
<div class="fixed bottom-6 right-6 z-50 flex flex-col items-end gap-3" id="floating-contact-ai">
    <!-- Contact Button -->
    <div class="relative group">
        <button type="button" id="contact-fab"
            class="bg-gradient-to-r from-sky-500 to-blue-600 hover:from-sky-600 hover:to-blue-700 text-white rounded-full p-4 shadow-2xl hover:shadow-blue-500/50 transition-all duration-300 hover:scale-110 flex items-center justify-center w-14 h-14">
            <i class="fas fa-headset text-xl"></i>
        </button>
        <div id="contact-menu" class="hidden absolute bottom-16 right-0 w-56 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl shadow-2xl p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white">Contact</h3>
                <button type="button" id="contact-menu-close" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <a href="<?php echo htmlspecialchars($whatsappLink); ?>" target="_blank" rel="noopener"
                   class="flex flex-col items-center justify-center p-3 rounded-xl bg-green-50 dark:bg-green-900/20 hover:bg-green-100 dark:hover:bg-green-900/30 transition-colors">
                    <span class="w-10 h-10 rounded-full bg-green-500 text-white flex items-center justify-center">
                        <i class="fab fa-whatsapp"></i>
                    </span>
                    <span class="text-xs font-semibold text-gray-800 dark:text-white mt-2">WhatsApp</span>
                </a>
                <a href="<?php echo htmlspecialchars($facebookLink); ?>" target="_blank" rel="noopener"
                   class="flex flex-col items-center justify-center p-3 rounded-xl bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/30 transition-colors">
                    <span class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center">
                        <i class="fab fa-facebook-f"></i>
                    </span>
                    <span class="text-xs font-semibold text-gray-800 dark:text-white mt-2">Facebook</span>
                </a>
            </div>
        </div>
        <div class="absolute right-16 top-1/2 transform -translate-y-1/2 bg-gray-900 dark:bg-gray-800 text-white px-3 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none shadow-xl">
            Contact
            <div class="absolute right-0 top-1/2 transform translate-x-full -translate-y-1/2">
                <div class="border-8 border-transparent border-l-gray-900 dark:border-l-gray-800"></div>
            </div>
        </div>
    </div>

    <!-- AI Assistant Button -->
    <a href="/Iqra-College/student/ai_assistant.php" class="relative group">
        <div class="bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-full p-4 shadow-2xl hover:shadow-green-500/50 transition-all duration-300 hover:scale-110 flex items-center justify-center w-16 h-16 animate-pulse hover:animate-none relative">
            <i class="fas fa-robot text-2xl"></i>
            <span class="absolute -top-1 -right-1 w-4 h-4 bg-yellow-400 rounded-full border-2 border-white dark:border-gray-800 animate-ping"></span>
        </div>
        <div class="absolute right-20 top-1/2 transform -translate-y-1/2 bg-gray-900 dark:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none shadow-xl">
            AI Assistant
            <div class="absolute right-0 top-1/2 transform translate-x-full -translate-y-1/2">
                <div class="border-8 border-transparent border-l-gray-900 dark:border-l-gray-800"></div>
            </div>
        </div>
    </a>
</div>

<script>
    (function() {
        const contactFab = document.getElementById('contact-fab');
        const contactMenu = document.getElementById('contact-menu');
        const contactMenuClose = document.getElementById('contact-menu-close');
        const contactQuickAction = document.getElementById('contact-quick-action');

        if (contactFab && contactMenu) {
            contactFab.addEventListener('click', () => {
                contactMenu.classList.toggle('hidden');
            });
        }

        if (contactMenuClose && contactMenu) {
            contactMenuClose.addEventListener('click', () => {
                contactMenu.classList.add('hidden');
            });
        }

        if (contactQuickAction && contactMenu) {
            contactQuickAction.addEventListener('click', () => {
                contactMenu.classList.toggle('hidden');
            });
        }
    })();
</script>
