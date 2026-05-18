<?php
/**
 * Admin - Assign Students to Levels
 * Admin assigns students to levels after enrollment
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
        $studentId = intval($_POST['student_id'] ?? 0);
        $courseId = intval($_POST['course_id'] ?? 0);
        $level = sanitize($_POST['level'] ?? '');
        $notes = sanitize($_POST['notes'] ?? '');
        
        if ($studentId > 0 && $courseId > 0 && in_array($level, ['beginner', 'intermediate', 'advanced'])) {
            if (assignStudentToLevel($studentId, $courseId, $level, $adminId, $notes)) {
                $success = 'Student assigned to level successfully!';
            } else {
                $error = 'Failed to assign student. Please try again.';
            }
        } else {
            $error = 'Please fill in all required fields correctly.';
        }
    } elseif ($action === 'update') {
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        $level = sanitize($_POST['level'] ?? '');
        $notes = sanitize($_POST['notes'] ?? '');
        
        if ($assignmentId > 0 && in_array($level, ['beginner', 'intermediate', 'advanced'])) {
            try {
                $stmt = $pdo->prepare("UPDATE student_level_assignments 
                                      SET level = ?, notes = ?, assigned_by = ?, assigned_at = CURRENT_TIMESTAMP
                                      WHERE id = ?");
                $stmt->execute([$level, $notes, $adminId, $assignmentId]);
                
                // Also update enrollments table
                try {
                    $stmt = $pdo->prepare("UPDATE enrollments e
                                          JOIN student_level_assignments sla ON e.student_id = sla.student_id AND e.course_id = sla.course_id
                                          SET e.assigned_level = ?, e.enrollment_status = 'approved'
                                          WHERE sla.id = ?");
                    $stmt->execute([$level, $assignmentId]);
                } catch (PDOException $e) {
                    // Column might not exist
                }
                
                $success = 'Student level updated successfully!';
            } catch (PDOException $e) {
                $error = 'Failed to update assignment.';
            }
        }
    }
}

// Get students pending level assignment
$pendingStudents = getStudentsPendingLevelAssignment();

// Get all current assignments
try {
    $stmt = $pdo->query("SELECT sla.*, u.name as student_name, u.email, u.student_id as student_code,
                        c.title as course_title, c.level as course_level,
                        e.enrolled_at, e.progress, e.enrollment_status
                        FROM student_level_assignments sla
                        JOIN users u ON sla.student_id = u.id
                        JOIN courses c ON sla.course_id = c.id
                        LEFT JOIN enrollments e ON e.student_id = sla.student_id AND e.course_id = sla.course_id
                        WHERE u.role = 'student' AND u.status = 'active'
                        ORDER BY sla.assigned_at DESC");
    $assignments = $stmt->fetchAll();
} catch (PDOException $e) {
    $assignments = [];
}

// Get all courses for dropdown
try {
    $stmt = $pdo->query("SELECT id, title FROM courses ORDER BY title");
    $courses = $stmt->fetchAll();
} catch (PDOException $e) {
    $courses = [];
}

$currentPage = 'assign-students.php';
$pageTitle = 'Assign Students';
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
                            <p class="text-sm text-gray-500 dark:text-gray-400">Level assignment</p>
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

        <?php if (!empty($pendingStudents)): ?>
            <div class="bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500 rounded-2xl p-6 mb-8">
                <h2 class="text-lg font-bold text-amber-800 dark:text-amber-300 mb-4"><i class="fas fa-exclamation-triangle mr-2"></i>Pending (<?php echo count($pendingStudents); ?>)</h2>
                <p class="text-amber-700 dark:text-amber-400/90 mb-4">Assign a level to these enrollments.</p>
                <div class="space-y-4">
                    <?php foreach ($pendingStudents as $s): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-amber-200 dark:border-amber-800">
                            <form method="POST" class="grid md:grid-cols-5 gap-4 items-end">
                                <input type="hidden" name="action" value="assign"><input type="hidden" name="student_id" value="<?php echo $s['student_id']; ?>"><input type="hidden" name="course_id" value="<?php echo $s['course_id']; ?>">
                                <div><label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Student</label><div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($s['student_name']); ?></div><div class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($s['student_code'] ?? '—'); ?></div></div>
                                <div><label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Course</label><div class="text-sm font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($s['course_title']); ?></div></div>
                                <div><label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Level *</label><select name="level" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white text-sm"><option value="">Select</option><option value="beginner">Beginner</option><option value="intermediate">Intermediate</option><option value="advanced">Advanced</option></select></div>
                                <div><label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Notes</label><input type="text" name="notes" placeholder="Optional" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white text-sm"></div>
                                <div><button type="submit" class="w-full bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-indigo-700"><i class="fas fa-check mr-1"></i>Assign</button></div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-100 dark:border-gray-700 p-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-list mr-2 text-emerald-500"></i>Current Assignments (<?php echo count($assignments); ?>)</h2>
            <?php if (empty($assignments)): ?>
                <div class="text-center py-12 text-gray-500 dark:text-gray-400"><i class="fas fa-inbox text-5xl mb-3 opacity-50"></i><p class="text-lg">No level assignments yet.</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead><tr class="bg-gray-50 dark:bg-gray-700/50">
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Student</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Course</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Level</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Progress</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Assigned</th>
                            <th class="px-4 py-3 text-center font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($assignments as $a): ?>
                                <tr class="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-3"><div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($a['student_name']); ?></div><div class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($a['student_code'] ?? '—'); ?></div></td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($a['course_title']); ?></td>
                                    <td class="px-4 py-3"><span class="px-2 py-1 rounded-lg text-xs font-semibold <?php echo $a['level']==='beginner'?'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300':($a['level']==='intermediate'?'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300':'bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300'); ?>"><?php echo ucfirst($a['level']); ?></span></td>
                                    <td class="px-4 py-3"><div class="w-24 bg-gray-200 dark:bg-gray-600 rounded-full h-2"><div class="bg-indigo-600 h-2 rounded-full" style="width:<?php echo $a['progress']??0; ?>%"></div></div><span class="text-xs text-gray-600 dark:text-gray-400"><?php echo $a['progress']??0; ?>%</span></td>
                                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?php echo date('M d, Y', strtotime($a['assigned_at'])); ?></td>
                                    <td class="px-4 py-3 text-center"><button type="button" onclick='openEditModal(<?php echo json_encode($a); ?>)' class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-indigo-700"><i class="fas fa-edit mr-1"></i>Edit</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        </main>
    </div>

    <div id="editModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-md w-full shadow-2xl">
            <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Edit Level</h3>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="update"><input type="hidden" name="assignment_id" id="edit_assignment_id">
                <div class="mb-4"><label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Level *</label>
                    <select name="level" id="edit_level" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white"><option value="beginner">Beginner</option><option value="intermediate">Intermediate</option><option value="advanced">Advanced</option></select></div>
                <div class="mb-6"><label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Notes</label><textarea name="notes" id="edit_notes" rows="3" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-800 dark:text-white"></textarea></div>
                <div class="flex gap-3"><button type="submit" class="flex-1 bg-indigo-600 text-white px-4 py-2.5 rounded-xl font-semibold hover:bg-indigo-700"><i class="fas fa-save mr-2"></i>Update</button><button type="button" onclick="closeEditModal()" class="flex-1 bg-gray-500 text-white px-4 py-2.5 rounded-xl font-semibold hover:bg-gray-600">Cancel</button></div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(assignment) {
            document.getElementById('edit_assignment_id').value = assignment.id;
            document.getElementById('edit_level').value = assignment.level;
            document.getElementById('edit_notes').value = assignment.notes || '';
            document.getElementById('editModal').classList.remove('hidden');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }
        
        // Close modal on outside click
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
    </script>
</body>
</html>
