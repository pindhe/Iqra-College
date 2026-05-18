<?php
/**
 * Admin - Manage Teachers & Cashiers
 * Add and manage users with role: teacher or cashier
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

$pdo = getDBConnection();
$error = '';
$success = '';

$allowedRoles = ['teacher', 'cashier'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = sanitize($_POST['role'] ?? 'teacher');
        
        if (!in_array($role, $allowedRoles)) {
            $role = 'teacher';
        }
        
        if (empty($name) || empty($email) || empty($password)) {
            $error = 'Please fill in all fields';
        } elseif (!validateEmail($email)) {
            $error = 'Invalid email format';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'Email already exists';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $email, $hashedPassword, $role]);
                    $label = $role === 'cashier' ? 'Cashier' : 'Teacher';
                    $success = $label . ' added successfully';
                }
            } catch (PDOException $e) {
                $error = 'Failed to add user';
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $role = sanitize($_POST['role'] ?? 'teacher');
        if (!in_array($role, $allowedRoles)) {
            $role = 'teacher';
        }
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ? AND role IN ('teacher', 'cashier')");
                $stmt->execute([$role, $id]);
                if ($stmt->rowCount() > 0) {
                    $success = 'Role updated to ' . ($role === 'cashier' ? 'Cashier' : 'Teacher');
                } else {
                    $error = 'User not found or cannot be updated';
                }
            } catch (PDOException $e) {
                $error = 'Failed to update role';
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role IN ('teacher', 'cashier')");
                $stmt->execute([$id]);
                if ($stmt->rowCount() > 0) {
                    $success = 'User deleted successfully';
                } else {
                    $error = 'User not found or cannot be deleted';
                }
            } catch (PDOException $e) {
                $error = 'Failed to delete user';
            }
        }
    }
}

// Get edit id
$editId = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$editUser = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role IN ('teacher', 'cashier')");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();
}

// Get all teachers and cashiers
$stmt = $pdo->query("SELECT * FROM users WHERE role IN ('teacher', 'cashier') ORDER BY role, created_at DESC");
$teachers = $stmt->fetchAll();

$currentPage = 'teachers.php';
$pageTitle = 'Teachers & Cashiers';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode'])&&$_COOKIE['dark_mode']==='enabled'?'dark':''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Admin - IQRA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{colors:{primary:{500:'#3b82f6',600:'#2563eb'}}}}};</script>
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">Add and manage teachers or cashiers</p>
                        </div>
                    </div>
                    <?php include __DIR__.'/../includes/admin_header.php'; ?>
                </div>
            </div>
        </header>
        <main class="p-4 sm:p-6 lg:p-8">
        <?php if ($error): ?>
            <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-300 rounded-lg">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 border-l-4 border-green-500 text-green-700 dark:text-green-300 rounded-lg">
                <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4"><?php echo $editUser ? 'Edit Role' : 'Add New User (Teacher or Cashier)'; ?></h2>
            <?php if ($editUser): ?>
            <form method="POST" class="flex flex-wrap items-end gap-4">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">User</label>
                    <span class="text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($editUser['name']); ?> (<?php echo htmlspecialchars($editUser['email']); ?>)</span>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Role</label>
                    <select name="role" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                        <option value="teacher" <?php echo ($editUser['role'] ?? '') === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                        <option value="cashier" <?php echo ($editUser['role'] ?? '') === 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-xl font-semibold hover:bg-indigo-700">Update Role</button>
                    <a href="teachers.php" class="bg-gray-500 text-white px-6 py-2 rounded-xl font-semibold hover:bg-gray-600">Cancel</a>
                </div>
            </form>
            <?php else: ?>
            <form method="POST" class="grid md:grid-cols-2 lg:grid-cols-4 gap-4">
                <input type="hidden" name="action" value="add">
                <input type="text" name="name" required placeholder="Name" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                <input type="email" name="email" required placeholder="Email" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                <input type="password" name="password" required placeholder="Password" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                <div class="flex gap-2">
                    <select name="role" class="flex-1 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                        <option value="teacher">Teacher</option>
                        <option value="cashier">Cashier</option>
                    </select>
                    <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-xl font-semibold hover:bg-indigo-700">Add</button>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">All Teachers & Cashiers</h2>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-700/50">
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">ID</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Name</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Email</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Role</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Created</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teachers)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No teachers or cashiers found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($teachers as $t): ?>
                                <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-3 text-gray-800 dark:text-gray-200"><?php echo $t['id']; ?></td>
                                    <td class="px-4 py-3 font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($t['name']); ?></td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($t['email']); ?></td>
                                    <td class="px-4 py-3">
                                        <?php if (($t['role'] ?? '') === 'cashier'): ?>
                                            <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300">Cashier</span>
                                        <?php else: ?>
                                            <span class="inline-block px-2 py-1 text-xs font-semibold rounded-full bg-indigo-100 dark:bg-indigo-900/40 text-indigo-800 dark:text-indigo-300">Teacher</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400"><?php echo !empty($t['created_at']) ? date('Y-m-d', strtotime($t['created_at'])) : '—'; ?></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex flex-wrap gap-2 items-center">
                                        <a href="teachers.php?edit=<?php echo (int)$t['id']; ?>" class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700">Edit</a>
                                        <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-red-700">Delete</button>
                                        </form>
                                        </span>
                                    </td>
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
