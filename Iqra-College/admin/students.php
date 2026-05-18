<?php
/**
 * Admin - View Students
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

$pdo = getDBConnection();
try {
    $stmt = $pdo->query("SELECT * FROM users WHERE role = 'student' ORDER BY created_at DESC");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $students = [];
}

$currentPage = 'students.php';
$pageTitle = 'Students';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode'])&&$_COOKIE['dark_mode']==='enabled'?'dark':''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Admin - IQRA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class'};</script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen">
    <?php include __DIR__.'/../includes/admin_sidebar.php'; ?>
    <div class="lg:ml-64">
        <header class="bg-white dark:bg-gray-800 shadow border-b border-gray-200 dark:border-gray-700 sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <button id="mobile-menu-toggle" class="lg:hidden p-2 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-bars text-xl"></i></button>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo count($students); ?> registered students</p>
                        </div>
                    </div>
                    <?php include __DIR__.'/../includes/admin_header.php'; ?>
                </div>
            </div>
        </header>
        <main class="p-4 sm:p-6 lg:p-8">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-user-graduate mr-2 text-emerald-500"></i>All Students</h2>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-700/50">
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">ID</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Name</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Email</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Student ID</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr><td colspan="5" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400"><i class="fas fa-inbox text-4xl mb-2 block"></i>No students found</td></tr>
                        <?php else: ?>
                            <?php foreach ($students as $s): ?>
                                <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-3 text-gray-800 dark:text-gray-200"><?php echo $s['id']; ?></td>
                                    <td class="px-4 py-3 font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($s['name']); ?></td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($s['email']); ?></td>
                                    <td class="px-4 py-3 font-mono text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($s['student_id'] ?? '—'); ?></td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 text-sm"><?php echo !empty($s['created_at']) ? date('M j, Y', strtotime($s['created_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </main>
    </div>
</body>
</html>
