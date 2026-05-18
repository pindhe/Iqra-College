<?php
/**
 * Teacher - Manage Quizzes
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('teacher');

$teacherId = getCurrentUserId();
$pdo = getDBConnection();
$name = getCurrentUserName();
$error = '';
$success = '';
$pageTitle = 'Quizzes';
$currentPage = 'quizzes';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_quiz') {
        $course_id = intval($_POST['course_id'] ?? 0);
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        
        // Verify course belongs to teacher
        $stmt = $pdo->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$course_id, $teacherId]);
        if (!$stmt->fetch()) {
            $error = 'Invalid course';
        } elseif (empty($title)) {
            $error = 'Please enter quiz title';
        } else {
            try {
                // Try with description + published flag first
                try {
                    $stmt = $pdo->prepare("INSERT INTO quizzes (course_id, title, description, is_published) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$course_id, $title, $description]);
                } catch (PDOException $e) {
                    // Fallbacks if columns don't exist
                    if (strpos($e->getMessage(), 'is_published') !== false) {
                        try {
                            $stmt = $pdo->prepare("INSERT INTO quizzes (course_id, title, description) VALUES (?, ?, ?)");
                            $stmt->execute([$course_id, $title, $description]);
                        } catch (PDOException $e2) {
                            if (strpos($e2->getMessage(), 'description') !== false) {
                                $stmt = $pdo->prepare("INSERT INTO quizzes (course_id, title) VALUES (?, ?)");
                                $stmt->execute([$course_id, $title]);
                            } else {
                                throw $e2;
                            }
                        }
                    } elseif (strpos($e->getMessage(), 'description') !== false) {
                        $stmt = $pdo->prepare("INSERT INTO quizzes (course_id, title, is_published) VALUES (?, ?, 1)");
                        $stmt->execute([$course_id, $title]);
                    } else {
                        throw $e; // Re-throw if it's a different error
                    }
                }
                $success = 'Quiz created successfully';
            } catch (PDOException $e) {
                $error = 'Failed to create quiz: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'add_question') {
        $quiz_id = intval($_POST['quiz_id'] ?? 0);
        $question = sanitize($_POST['question'] ?? '');
        $option_a = sanitize($_POST['option_a'] ?? '');
        $option_b = sanitize($_POST['option_b'] ?? '');
        $option_c = sanitize($_POST['option_c'] ?? '');
        $option_d = sanitize($_POST['option_d'] ?? '');
        $correct_answer = sanitize($_POST['correct_answer'] ?? 'a');
        
        // Verify quiz belongs to teacher's course
        $stmt = $pdo->prepare("SELECT q.id FROM quizzes q 
                              JOIN courses c ON q.course_id = c.id 
                              WHERE q.id = ? AND c.teacher_id = ?");
        $stmt->execute([$quiz_id, $teacherId]);
        if (!$stmt->fetch()) {
            $error = 'Invalid quiz';
        } elseif (empty($question) || empty($option_a) || empty($option_b) || empty($option_c) || empty($option_d)) {
            $error = 'Please fill in all question fields';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO questions (quiz_id, question, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$quiz_id, $question, $option_a, $option_b, $option_c, $option_d, $correct_answer]);
                $success = 'Question added successfully';
            } catch (PDOException $e) {
                $error = 'Failed to add question: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_quiz') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE q FROM quizzes q 
                                      JOIN courses c ON q.course_id = c.id 
                                      WHERE q.id = ? AND c.teacher_id = ?");
                $stmt->execute([$id, $teacherId]);
                $success = 'Quiz deleted successfully';
            } catch (PDOException $e) {
                $error = 'Failed to delete quiz';
            }
        }
    }
}

// Get teacher's courses for dropdown
$courses = getCoursesByTeacher($teacherId);

// Get all quizzes for teacher's courses
$courseIds = array_column($courses, 'id');
$quizzes = [];
if (!empty($courseIds)) {
    $placeholders = str_repeat('?,', count($courseIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT q.*, c.title as course_title, 
                          (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count
                          FROM quizzes q 
                          JOIN courses c ON q.course_id = c.id 
                          WHERE q.course_id IN ($placeholders) 
                          ORDER BY q.created_at DESC");
    $stmt->execute($courseIds);
    $quizzes = $stmt->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Teacher</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd',
                            400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8',
                            800: '#1e40af', 900: '#1e3a8a',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .fade-in { animation: fadeIn 0.6s ease-out; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-purple-50 to-pink-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 min-h-screen">
    <?php include __DIR__ . '/../includes/teacher_sidebar.php'; ?>
    
    <div class="lg:ml-64">
        <nav class="bg-white dark:bg-gray-800 shadow-xl border-b border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-4">
                        <button id="mobile-menu-toggle" class="lg:hidden text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded-lg transition-colors">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <div>
                            <h1 class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo $pageTitle; ?></h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Create and manage quizzes</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <?php include __DIR__ . '/../includes/teacher_header.php'; ?>
                    </div>
                </div>
            </div>
        </nav>

        <div class="p-6 lg:p-8">
            <?php if ($error): ?>
                <div class="bg-red-100 dark:bg-red-900/30 border-l-4 border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6 fade-in">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="bg-green-100 dark:bg-green-900/30 border-l-4 border-green-500 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg mb-6 fade-in">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Quiz Builder -->
            <div class="grid lg:grid-cols-3 gap-6 mb-8 fade-in">
                <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Create a Quiz</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Start with quiz details, then add questions.</p>
                        </div>
                        <span class="px-3 py-1 rounded-full bg-primary-100 text-primary-700 text-xs font-semibold">Step 1</span>
                    </div>
                    <form method="POST" class="grid md:grid-cols-2 gap-4">
                        <input type="hidden" name="action" value="add_quiz">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Course</label>
                            <select name="course_id" required 
                                    class="w-full px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Quiz Title</label>
                            <input type="text" name="title" required placeholder="e.g. Grammar Basics Quiz"
                                   class="w-full px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Description (Optional)</label>
                            <textarea name="description" rows="3" placeholder="Short description for students..."
                                      class="w-full px-4 py-2 border-2 border-blue-200 dark:border-gray-700 rounded-lg focus:border-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" 
                                    class="w-full bg-gradient-to-r from-blue-500 to-blue-600 text-white px-6 py-3 rounded-lg font-semibold hover:from-blue-600 hover:to-blue-700 transition-all shadow-lg">
                                <i class="fas fa-plus mr-2"></i>Create Quiz
                            </button>
                        </div>
                    </form>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Add Question</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Attach questions to a quiz.</p>
                        </div>
                        <span class="px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-xs font-semibold">Step 2</span>
                    </div>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_question">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Quiz</label>
                            <select name="quiz_id" required 
                                    class="w-full px-4 py-2 border-2 border-green-200 dark:border-gray-700 rounded-lg focus:border-green-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="">Select Quiz</option>
                                <?php foreach ($quizzes as $quiz): ?>
                                    <option value="<?php echo $quiz['id']; ?>"><?php echo htmlspecialchars($quiz['title']); ?> (<?php echo $quiz['question_count']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Question</label>
                            <textarea name="question" required rows="2" placeholder="Write the question..."
                                      class="w-full px-4 py-2 border-2 border-green-200 dark:border-gray-700 rounded-lg focus:border-green-500 focus:outline-none dark:bg-gray-700 dark:text-white"></textarea>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <input type="text" name="option_a" required placeholder="Option A"
                                   class="px-4 py-2 border-2 border-green-200 dark:border-gray-700 rounded-lg focus:border-green-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                            <input type="text" name="option_b" required placeholder="Option B"
                                   class="px-4 py-2 border-2 border-green-200 dark:border-gray-700 rounded-lg focus:border-green-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                            <input type="text" name="option_c" required placeholder="Option C"
                                   class="px-4 py-2 border-2 border-green-200 dark:border-gray-700 rounded-lg focus:border-green-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                            <input type="text" name="option_d" required placeholder="Option D"
                                   class="px-4 py-2 border-2 border-green-200 dark:border-gray-700 rounded-lg focus:border-green-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Correct Answer</label>
                            <select name="correct_answer" required 
                                    class="w-full px-4 py-2 border-2 border-green-200 dark:border-gray-700 rounded-lg focus:border-green-500 focus:outline-none dark:bg-gray-700 dark:text-white">
                                <option value="a">Option A</option>
                                <option value="b">Option B</option>
                                <option value="c">Option C</option>
                                <option value="d">Option D</option>
                            </select>
                        </div>
                        <button type="submit" 
                                class="w-full bg-gradient-to-r from-green-500 to-emerald-600 text-white px-6 py-3 rounded-lg font-semibold hover:from-green-600 hover:to-emerald-700 transition-all shadow-lg">
                            <i class="fas fa-plus mr-2"></i>Add Question
                        </button>
                    </form>
                </div>
            </div>

        <!-- Quizzes List -->
        <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-8 fade-in">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold text-gray-800 dark:text-white">
                    <i class="fas fa-list mr-2"></i>All Quizzes
                </h2>
                <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo count($quizzes); ?> total</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gradient-to-r from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-900/30">
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Course</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Quiz Title</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Questions</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Created</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($quizzes)): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No quizzes found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($quizzes as $quiz): ?>
                                <tr class="border-b border-gray-200 dark:border-gray-700 hover:bg-blue-50 dark:hover:bg-gray-700 transition-colors">
                                    <td class="px-4 py-3 text-gray-800 dark:text-white"><?php echo htmlspecialchars($quiz['course_title']); ?></td>
                                    <td class="px-4 py-3 font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($quiz['title']); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="bg-gradient-to-r from-blue-100 to-blue-200 dark:from-blue-900/20 dark:to-blue-900/30 text-blue-800 dark:text-blue-400 px-3 py-1 rounded-full text-sm font-bold">
                                            <?php echo $quiz['question_count']; ?> questions
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400"><?php echo date('M d, Y', strtotime($quiz['created_at'])); ?></td>
                                    <td class="px-4 py-3">
                                        <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this quiz?')">
                                            <input type="hidden" name="action" value="delete_quiz">
                                            <input type="hidden" name="id" value="<?php echo $quiz['id']; ?>">
                                            <button type="submit" 
                                                    class="bg-gradient-to-r from-red-500 to-red-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:from-red-600 hover:to-red-700 transition-all shadow-lg">
                                                <i class="fas fa-trash mr-1"></i>Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
    </div>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-toggle')?.addEventListener('click', function() {
            const mobileSidebar = document.getElementById('mobile-sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');
            if (mobileSidebar && sidebarOverlay) {
                mobileSidebar.classList.remove('-translate-x-full');
                sidebarOverlay.classList.remove('hidden');
            }
        });
        
        // Ensure dark mode toggle works on this page
        document.addEventListener('DOMContentLoaded', function() {
            const html = document.documentElement;
            const darkModeCookie = document.cookie.split('; ').find(row => row.startsWith('dark_mode='));
            if (darkModeCookie && darkModeCookie.split('=')[1] === 'enabled') {
                html.classList.add('dark');
            } else {
                html.classList.remove('dark');
            }
        });
    </script>
</body>
</html>
