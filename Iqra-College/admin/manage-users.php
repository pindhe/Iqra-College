<?php
/**
 * Admin - Manage Users (Enable/Disable Accounts)
 * Admin can enable or disable user accounts
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

$pdo = getDBConnection();
$error = '';
$success = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = intval($_POST['user_id'] ?? 0);
    $status = sanitize($_POST['status'] ?? '');
    
    if ($userId > 0 && in_array($status, ['active', 'inactive'])) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$status, $userId]);
            
            $statusText = $status === 'active' ? 'enabled' : 'disabled';
            $success = "User account {$statusText} successfully!";
        } catch (PDOException $e) {
            $error = 'Failed to update user status.';
        }
    }
}

// Get all users
try {
    $stmt = $pdo->query("SELECT id, name, email, role, status, student_id, created_at 
                        FROM users 
                        ORDER BY role, name");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $users = [];
}

// Group users by role
$usersByRole = [
    'admin' => [],
    'teacher' => [],
    'student' => [],
    'cashier' => []
];

foreach ($users as $user) {
    $role = $user['role'] ?? 'student';
    if (isset($usersByRole[$role])) {
        $usersByRole[$role][] = $user;
    }
}

$roleStats = [
    'admin' => ['count' => count($usersByRole['admin']), 'icon' => 'fa-user-shield', 'textClass' => 'text-purple-600 dark:text-purple-400', 'iconClass' => 'text-purple-500'],
    'teacher' => ['count' => count($usersByRole['teacher']), 'icon' => 'fa-chalkboard-teacher', 'textClass' => 'text-blue-600 dark:text-blue-400', 'iconClass' => 'text-blue-500'],
    'student' => ['count' => count($usersByRole['student']), 'icon' => 'fa-user-graduate', 'textClass' => 'text-emerald-600 dark:text-emerald-400', 'iconClass' => 'text-emerald-500'],
    'cashier' => ['count' => count($usersByRole['cashier']), 'icon' => 'fa-cash-register', 'textClass' => 'text-amber-600 dark:text-amber-400', 'iconClass' => 'text-amber-500']
];

$currentPage = 'manage-users.php';
$pageTitle = 'Manage Users';
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">Enable or disable accounts</p>
                        </div>
                    </div>
                    <?php include __DIR__.'/../includes/admin_header.php'; ?>
                </div>
            </div>
        </header>
        <main class="p-4 sm:p-6 lg:p-8">
        <?php if ($error): ?>
            <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-300 rounded-lg"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 border-l-4 border-green-500 text-green-700 dark:text-green-300 rounded-lg"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <?php foreach ($roleStats as $role => $stat): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm"><?php echo ucfirst($role); ?>s</p>
                            <p class="text-2xl font-bold <?php echo $stat['textClass']; ?>"><?php echo $stat['count']; ?></p>
                        </div>
                        <i class="fas <?php echo $stat['icon']; ?> <?php echo $stat['iconClass']; ?> text-3xl"></i>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($usersByRole as $role => $roleUsers): ?>
            <?php if (!empty($roleUsers)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 mb-8">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4 flex items-center">
                        <i class="fas <?php echo $roleStats[$role]['icon']; ?> mr-2 <?php echo $roleStats[$role]['iconClass']; ?>"></i>
                        <?php echo ucfirst($role); ?>s (<?php echo count($roleUsers); ?>)
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-700/50">
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Name</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Email</th>
                                    <?php if ($role === 'student'): ?><th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Student ID</th><?php endif; ?>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Status</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Created</th>
                                    <th class="px-4 py-3 text-center font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($roleUsers as $user): ?>
                                    <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                        <td class="px-4 py-3 font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($user['name']); ?></td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <?php if ($role === 'student'): ?>
                                            <td class="px-4 py-3 font-mono text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($user['student_id'] ?? '—'); ?></td>
                                        <?php endif; ?>
                                        <td class="px-4 py-3">
                                            <span class="inline-block px-2 py-1 rounded-lg text-xs font-semibold <?php echo ($user['status'] ?? 'active') === 'active' ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300' : 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300'; ?>">
                                                <i class="fas fa-<?php echo ($user['status'] ?? 'active') === 'active' ? 'check' : 'times'; ?>-circle mr-1"></i><?php echo ucfirst($user['status'] ?? 'active'); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                        <td class="px-4 py-3 text-center">
                                            <?php if (($user['status'] ?? 'active') === 'active'): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Disable this account?');">
                                                    <input type="hidden" name="action" value="update">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="status" value="inactive">
                                                    <button type="submit" class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-red-700"><i class="fas fa-ban mr-1"></i>Disable</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Enable this account?');">
                                                    <input type="hidden" name="action" value="update">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="status" value="active">
                                                    <button type="submit" class="bg-emerald-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-emerald-700"><i class="fas fa-check mr-1"></i>Enable</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        </main>
    </div>
</body>
</html>
