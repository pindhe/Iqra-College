<?php
/**
 * Admin - Assign Teachers to Courses & Levels
 * Admin can assign teachers to specific courses and levels
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('admin');

$adminId = getCurrentUserId();
$pdo = getDBConnection();
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'assign') {
        $teacherId = intval($_POST['teacher_id'] ?? 0);
        $courseId = intval($_POST['course_id'] ?? 0);
        $level = sanitize($_POST['level'] ?? '');
        
        if ($teacherId > 0 && $courseId > 0 && in_array($level, ['beginner', 'intermediate', 'advanced'])) {
            if (assignTeacherToCourseLevel($teacherId, $courseId, $level, $adminId)) {
                $success = 'Teacher assigned successfully!';
            } else {
                $error = 'Failed to assign teacher. Please try again.';
            }
        } else {
            $error = 'Please fill in all fields correctly.';
        }
    } elseif ($action === 'remove') {
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        if ($assignmentId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM teacher_course_assignments WHERE id = ?");
                $stmt->execute([$assignmentId]);
                $success = 'Assignment removed successfully!';
            } catch (PDOException $e) {
                $error = 'Failed to remove assignment.';
            }
        }
    }
}

// Get all teachers
try {
    $stmt = $pdo->query("SELECT id, name, email FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name");
    $teachers = $stmt->fetchAll();
} catch (PDOException $e) {
    $teachers = [];
}

// Get all courses
try {
    $stmt = $pdo->query("SELECT id, title, level FROM courses ORDER BY title");
    $courses = $stmt->fetchAll();
} catch (PDOException $e) {
    $courses = [];
}

// Get all current assignments
try {
    $stmt = $pdo->query("SELECT tca.*, u.name as teacher_name, u.email as teacher_email, 
                        c.title as course_title, c.level as course_level
                        FROM teacher_course_assignments tca
                        JOIN users u ON tca.teacher_id = u.id
                        JOIN courses c ON tca.course_id = c.id
                        ORDER BY u.name, c.title, tca.level");
    $assignments = $stmt->fetchAll();
} catch (PDOException $e) {
    $assignments = [];
}

$currentPage = 'assign-teachers.php';
$pageTitle = 'Assign Teachers';
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">Courses & levels</p>
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

        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-plus-circle mr-2 text-indigo-500"></i>New Assignment</h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="assign">
                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Teacher *</label>
                        <select name="teacher_id" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="">Select Teacher</option>
                            <?php foreach ($teachers as $t): ?><option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name'].' ('.$t['email'].')'); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Course *</label>
                        <select name="course_id" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['title'].' ('.ucfirst($c['level']).')'); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Level *</label>
                        <select name="level" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="">Select Level</option>
                            <option value="beginner">Beginner</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="advanced">Advanced</option>
                        </select>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Teacher sees students in this level only</p>
                    </div>
                </div>
                <button type="submit" class="bg-indigo-600 text-white px-6 py-2.5 rounded-xl font-semibold hover:bg-indigo-700"><i class="fas fa-check mr-2"></i>Assign Teacher</button>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-list mr-2 text-indigo-500"></i>Current Assignments (<?php echo count($assignments); ?>)</h2>
            <?php if (empty($assignments)): ?>
                <div class="text-center py-12 text-gray-500 dark:text-gray-400"><i class="fas fa-inbox text-5xl mb-3 opacity-50"></i><p class="text-lg">No assignments yet.</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead><tr class="bg-gray-50 dark:bg-gray-700/50">
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Teacher</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Course</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Level</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Assigned</th>
                            <th class="px-4 py-3 text-center font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($assignments as $a): ?>
                                <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-3"><div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($a['teacher_name']); ?></div><div class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($a['teacher_email']); ?></div></td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($a['course_title']); ?></td>
                                    <td class="px-4 py-3"><span class="px-2 py-1 rounded-lg text-xs font-semibold <?php echo $a['level']==='beginner'?'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300':($a['level']==='intermediate'?'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300':'bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300'); ?>"><?php echo ucfirst($a['level']); ?></span></td>
                                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo date('M d, Y', strtotime($a['assigned_at'])); ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <form method="POST" class="inline" onsubmit="return confirm('Remove this assignment?');">
                                            <input type="hidden" name="action" value="remove"><input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                                            <button type="submit" class="bg-red-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-red-700"><i class="fas fa-trash mr-1"></i>Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        </main>
    </div>
</body>
</html>
