<?php
/**
 * Student - AI Assistant (Separate Page)
 * AI Chat functionality for students
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api.php';

requireRole('student');

$studentId = getCurrentUserId();
$studentCode = getUserCode($studentId);
$pdo = getDBConnection();
$name = getCurrentUserName();

// Get student's enrolled courses for context
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.title, c.course_code 
        FROM enrollments e
        JOIN courses c ON e.course_id = c.id
        WHERE e.student_id = ? AND e.access_granted = 1
    ");
    $stmt->execute([$studentId]);
    $enrolledCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $enrolledCourses = [];
}

// Get upcoming assignments for context
try {
    $stmt = $pdo->prepare("
        SELECT a.title, a.due_date, c.title as course_name
        FROM assignments a
        JOIN courses c ON a.course_id = c.id
        JOIN enrollments e ON c.id = e.course_id
        WHERE e.student_id = ? AND a.due_date > NOW()
        ORDER BY a.due_date ASC
        LIMIT 5
    ");
    $stmt->execute([$studentId]);
    $upcomingAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $upcomingAssignments = [];
}

// Get student's quiz performance
try {
    $stmt = $pdo->prepare("
        SELECT AVG(percentage) as avg_score, COUNT(*) as total_quizzes
        FROM quiz_attempts
        WHERE student_id = ? AND completed_at IS NOT NULL
    ");
    $stmt->execute([$studentId]);
    $quizStats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $quizStats = ['avg_score' => 0, 'total_quizzes' => 0];
}

// Get payment status
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_payments
        FROM payments
        WHERE student_id = ? AND status = 'pending'
    ");
    $stmt->execute([$studentId]);
    $paymentStats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $paymentStats = ['pending_payments' => 0];
}

// Handle AJAX AI chat request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'chat') {
        $message = trim($_POST['message'] ?? '');
        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
            exit;
        }
        
        // Save student message to database
        try {
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, subject, message) VALUES (?, 0, 'AI Chat', ?)");
            $stmt->execute([$studentId, $message]);
            $studentMessageId = $pdo->lastInsertId();
        } catch (PDOException $e) {
            // Continue even if save fails
        }
        
        // Try OpenAI first, then DeepSeek, then fallback
        $aiResponse = null;
        $fallback = false;
        $apiUsed = 'none';
        
        // Try OpenAI API
        $openaiKey = getOpenAIApiKey();
        if (!empty($openaiKey) && $openaiKey !== 'your-openai-api-key-here') {
            try {
                // Get conversation history
                $stmt = $pdo->prepare("
                    SELECT sender_id, message, created_at 
                    FROM messages 
                    WHERE (sender_id = ? OR receiver_id = ?) 
                    AND (receiver_id = 0 OR sender_id = 0)
                    ORDER BY created_at DESC 
                    LIMIT 10
                ");
                $stmt->execute([$studentId, $studentId]);
                $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
                
                $conversationHistory = [];
                foreach ($history as $msg) {
                    if ($msg['sender_id'] == $studentId) {
                        $conversationHistory[] = ['role' => 'user', 'content' => $msg['message']];
                    } else {
                        $conversationHistory[] = ['role' => 'assistant', 'content' => $msg['message']];
                    }
                }
                
                $aiResponse = callOpenAIChat($message, $conversationHistory);
                $apiUsed = 'openai';
            } catch (Exception $e) {
                error_log("OpenAI API Error: " . $e->getMessage());
            }
        }
        
        // Try DeepSeek API if OpenAI failed
        if (empty($aiResponse)) {
            $deepseekKey = getDeepSeekApiKey();
            if (!empty($deepseekKey) && $deepseekKey !== 'your-deepseek-api-key-here') {
                try {
                    // Get conversation history
                    $stmt = $pdo->prepare("
                        SELECT sender_id, message, created_at 
                        FROM messages 
                        WHERE (sender_id = ? OR receiver_id = ?) 
                        AND (receiver_id = 0 OR sender_id = 0)
                        ORDER BY created_at DESC 
                        LIMIT 10
                    ");
                    $stmt->execute([$studentId, $studentId]);
                    $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
                    
                    $systemPrompt = buildSystemPrompt($studentId, $enrolledCourses, $upcomingAssignments, $quizStats, $paymentStats);
                    
                    $messages = [
                        ['role' => 'system', 'content' => $systemPrompt]
                    ];
                    
                    foreach ($history as $msg) {
                        if ($msg['sender_id'] == $studentId) {
                            $messages[] = ['role' => 'user', 'content' => $msg['message']];
                        } else {
                            $messages[] = ['role' => 'assistant', 'content' => $msg['message']];
                        }
                    }
                    
                    $messages[] = ['role' => 'user', 'content' => $message];
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, DEEPSEEK_API_URL);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $deepseekKey
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'model' => 'deepseek-chat',
                        'messages' => $messages,
                        'temperature' => 0.7,
                        'max_tokens' => 1000
                    ]));
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($httpCode === 200) {
                        $data = json_decode($response, true);
                        $aiResponse = $data['choices'][0]['message']['content'] ?? null;
                        $apiUsed = 'deepseek';
                    }
                } catch (Exception $e) {
                    error_log("DeepSeek API Error: " . $e->getMessage());
                }
            }
        }
        
        // Fallback to smart response if both APIs failed
        if (empty($aiResponse)) {
            $aiResponse = generateSmartResponse($message, $studentId, $pdo, $enrolledCourses, $upcomingAssignments, $quizStats, $paymentStats);
            $fallback = true;
            $apiUsed = 'fallback';
        }
        
        // Save AI response to database
        try {
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, subject, message) VALUES (0, ?, 'AI Chat', ?)");
            $stmt->execute([$studentId, $aiResponse]);
        } catch (PDOException $e) {
            // Continue even if save fails
        }
        
        echo json_encode([
            'success' => true,
            'response' => $aiResponse,
            'fallback' => $fallback,
            'api_used' => $apiUsed
        ]);
        exit;
        
    } elseif ($_POST['action'] === 'clear_history') {
        try {
            $stmt = $pdo->prepare("DELETE FROM messages WHERE (sender_id = ? OR receiver_id = ?) AND (receiver_id = 0 OR sender_id = 0)");
            $stmt->execute([$studentId, $studentId]);
            echo json_encode(['success' => true, 'message' => 'Chat history cleared']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to clear history']);
        }
        exit;
        
    } elseif ($_POST['action'] === 'get_suggestions') {
        $suggestions = [];
        
        // Dynamic suggestions based on student data
        if (!empty($enrolledCourses)) {
            $suggestions[] = "What courses am I currently enrolled in?";
            $suggestions[] = "Tell me about my enrolled courses";
        }
        
        if (!empty($upcomingAssignments)) {
            $suggestions[] = "Do I have any upcoming assignments?";
            $suggestions[] = "What assignments are due soon?";
        }
        
        if ($paymentStats['pending_payments'] > 0) {
            $suggestions[] = "How can I check my payment status?";
        }
        
        $suggestions = array_merge($suggestions, [
            "Where can I find course materials?",
            "Can you explain the grading system?",
            "How do I submit an assignment?",
            "What's my average score in quizzes?",
            "How do I contact my teachers?",
            "Can you help me with English grammar?",
            "What are the office hours for support?"
        ]);
        
        echo json_encode(['success' => true, 'suggestions' => array_slice($suggestions, 0, 10)]);
        exit;
        
    } elseif ($_POST['action'] === 'search_messages') {
        $searchTerm = trim($_POST['search_term'] ?? '');
        if (empty($searchTerm)) {
            echo json_encode(['success' => false, 'error' => 'Search term cannot be empty']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT m.*,
                    CASE 
                        WHEN m.sender_id = ? THEN 'student'
                        WHEN m.sender_id = 0 THEN 'ai'
                        ELSE 'other'
                    END as message_type
                FROM messages m
                WHERE (m.sender_id = ? OR m.receiver_id = ?)
                AND (m.receiver_id = 0 OR m.sender_id = 0)
                AND m.message LIKE ?
                ORDER BY m.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$studentId, $studentId, $studentId, '%' . $searchTerm . '%']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'results' => $results]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Search failed']);
        }
        exit;
    }
}

// Get chat history
try {
    $stmt = $pdo->prepare("
        SELECT 
            m.*,
            CASE 
                WHEN m.sender_id = ? THEN 'student'
                WHEN m.sender_id = 0 THEN 'ai'
                ELSE 'other'
            END as message_type
        FROM messages m
        WHERE (m.sender_id = ? OR m.receiver_id = ?)
        AND (m.receiver_id = 0 OR m.sender_id = 0)
        ORDER BY m.created_at ASC
        LIMIT 100
    ");
    $stmt->execute([$studentId, $studentId, $studentId]);
    $chatHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $chatHistory = [];
}

// Helper functions
function buildSystemPrompt($studentId, $enrolledCourses, $upcomingAssignments, $quizStats, $paymentStats) {
    $prompt = "You are a helpful AI assistant for Iqra College Learning Management System. You help students with their academic queries. ";
    $prompt .= "The student you're chatting with has student ID: {$studentId}. ";
    
    if (!empty($enrolledCourses)) {
        $prompt .= "They are enrolled in the following courses: ";
        foreach ($enrolledCourses as $course) {
            $prompt .= "{$course['title']} ({$course['course_code']}), ";
        }
        $prompt = rtrim($prompt, ', ') . ". ";
    }
    
    if (!empty($upcomingAssignments)) {
        $prompt .= "They have upcoming assignments: ";
        foreach ($upcomingAssignments as $assignment) {
            $dueDate = date('M d, Y', strtotime($assignment['due_date']));
            $prompt .= "{$assignment['title']} for {$assignment['course_name']} (due {$dueDate}), ";
        }
        $prompt = rtrim($prompt, ', ') . ". ";
    }
    
    if ($quizStats && $quizStats['total_quizzes'] > 0) {
        $prompt .= "Their quiz performance: Average score of " . number_format($quizStats['avg_score'], 1) . "% from {$quizStats['total_quizzes']} quizzes. ";
    }
    
    if ($paymentStats && $paymentStats['pending_payments'] > 0) {
        $prompt .= "They have {$paymentStats['pending_payments']} pending payment(s). ";
    }
    
    $prompt .= "You should: 
    1. Be friendly, patient, and encouraging
    2. Provide accurate information about the LMS
    3. Guide students to appropriate resources
    4. Keep responses concise but helpful (2-3 sentences max)
    5. If you don't know something, admit it and suggest contacting support
    6. Help with course queries, assignments, payments, and general LMS usage
    7. Use the student's specific data to provide personalized responses
    
    Current date: " . date('F j, Y') . ".";
    
    return $prompt;
}

function generateSmartResponse($message, $studentId, $pdo, $enrolledCourses = [], $upcomingAssignments = [], $quizStats = [], $paymentStats = []) {
    $message = strtolower($message);
    
    // Enhanced responses with student-specific data
    $responses = [
        'course' => [
            'pattern' => ['course', 'enroll', 'class', 'subject'],
            'response' => !empty($enrolledCourses) 
                ? "You are currently enrolled in " . count($enrolledCourses) . " course(s). You can view them in the 'My Courses' section. To enroll in more courses, visit the Courses page."
                : "You can view all available courses in the 'My Courses' section. To enroll, go to the Courses page and click 'Enroll Now' for the course you're interested in."
        ],
        'assignment' => [
            'pattern' => ['assignment', 'homework', 'task', 'due', 'deadline'],
            'response' => !empty($upcomingAssignments)
                ? "You have " . count($upcomingAssignments) . " upcoming assignment(s). Check the Assignments section for details and due dates. Make sure to submit before the deadline!"
                : "Your assignments are listed in the Assignments section. You can submit assignments by clicking on the assignment and uploading your files."
        ],
        'payment' => [
            'pattern' => ['payment', 'fee', 'price', 'cost', 'pay', 'paid'],
            'response' => ($paymentStats['pending_payments'] > 0)
                ? "You have " . $paymentStats['pending_payments'] . " pending payment(s). Check the Payments section for details. Payment must be verified before course access is granted."
                : "You can check your payment status in the Payments section. For paid courses, payment must be completed before accessing course materials."
        ],
        'quiz' => [
            'pattern' => ['quiz', 'test', 'exam', 'question', 'score'],
            'response' => ($quizStats && $quizStats['total_quizzes'] > 0)
                ? "You've completed {$quizStats['total_quizzes']} quiz(es) with an average score of " . number_format($quizStats['avg_score'], 1) . "%. Check the Quizzes section for more quizzes and the Results section for detailed scores."
                : "Quizzes are available in the Quizzes section. Take quizzes to test your knowledge and track your progress!"
        ],
        'material' => [
            'pattern' => ['material', 'resource', 'download', 'file', 'document'],
            'response' => "Learning materials are available in the Materials section. You can download PDFs, watch videos, and access other resources for your enrolled courses."
        ],
        'help' => [
            'pattern' => ['help', 'support', 'assistance', 'problem', 'issue'],
            'response' => "I'm here to help! For technical issues, contact IT support. For academic questions, message your instructors. For urgent matters, call the administration office."
        ],
        'grade' => [
            'pattern' => ['grade', 'score', 'result', 'mark', 'percentage'],
            'response' => ($quizStats && $quizStats['total_quizzes'] > 0)
                ? "Your average quiz score is " . number_format($quizStats['avg_score'], 1) . "%. View detailed grades and scores in the Results section."
                : "You can view your grades and scores in the Results section. Complete quizzes and assignments to see your performance."
        ],
        'contact' => [
            'pattern' => ['contact', 'email', 'phone', 'message', 'teacher'],
            'response' => "You can contact your teachers through the Messages section. For administrative queries, email support@iqracollege.edu or call during office hours (9 AM - 5 PM, Monday to Friday)."
        ]
    ];
    
    // Check for matching patterns
    foreach ($responses as $category => $data) {
        foreach ($data['pattern'] as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return $data['response'];
            }
        }
    }
    
    // Default response
    return "Thank you for your message! I'm here to help you with your learning journey at Iqra College. You can ask me about courses, assignments, payments, grades, or any other academic matters. How can I assist you today?";
}

$pageTitle = 'AI Assistant';
$pageSubtitle = 'Your personal AI assistant for course guidance and support';
$currentPage = 'ai_assistant';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - IQRA Online College</title>
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
                    },
                    animation: {
                        'float': 'float 3s ease-in-out infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-10px)' },
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            background-size: 200% 200%;
            animation: gradient-shift 3s ease infinite;
        }
        @keyframes gradient-shift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .message-enter {
            animation: messageEnter 0.3s ease-out;
        }
        @keyframes messageEnter {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        .typing-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .typing-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #667eea;
            animation: typing 1.4s infinite;
        }
        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-8px); }
        }
        .scrollbar-thin {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        .dark .scrollbar-thin {
            scrollbar-color: #4b5563 transparent;
        }
        .pulse-recording {
            animation: pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 min-h-screen transition-colors duration-300">
    <div class="flex h-screen">
        <?php include __DIR__ . '/../includes/student_sidebar.php'; ?>

        <main class="ml-0 lg:ml-64 flex-1 flex flex-col h-screen transition-all duration-300">
            <!-- Header -->
            <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <button id="mobile-menu-toggle" class="lg:hidden text-gray-600 dark:text-gray-400">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <div>
                            <h1 class="text-2xl lg:text-3xl font-bold text-gray-800 dark:text-white flex items-center">
                                <i class="fas fa-robot text-primary-600 dark:text-primary-400 mr-3 animate-float"></i>
                                <?php echo $pageTitle; ?>
                            </h1>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                <?php echo $pageSubtitle; ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="flex items-center space-x-3">
                        <button id="search-btn" class="hidden lg:flex items-center space-x-2 px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                            <i class="fas fa-search"></i>
                            <span>Search</span>
                        </button>
                        
                        <button id="clear-history" class="hidden lg:flex items-center space-x-2 px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                            <i class="fas fa-trash-alt"></i>
                            <span>Clear</span>
                        </button>
                        
                        <button id="suggestions-btn" class="flex items-center space-x-2 px-4 py-2 bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white rounded-xl transition-all transform hover:scale-105 shadow-lg">
                            <i class="fas fa-lightbulb"></i>
                            <span class="hidden lg:inline">Suggestions</span>
                        </button>
                    </div>
                </div>
                
                <!-- Search Bar (Hidden by default) -->
                <div id="search-bar" class="hidden mt-4">
                    <div class="flex items-center space-x-3">
                        <div class="relative flex-1">
                            <input type="text" 
                                   id="search-input"
                                   placeholder="Search in conversation history..."
                                   class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500 text-gray-800 dark:text-white">
                            <i class="fas fa-search absolute right-3 top-3 text-gray-400"></i>
                        </div>
                        <button id="close-search" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="search-results" class="mt-3 max-h-48 overflow-y-auto space-y-2"></div>
                </div>
                
                <!-- Suggestions Panel -->
                <div id="suggestions-panel" class="hidden mt-6 p-4 bg-gradient-to-r from-primary-50 to-blue-50 dark:from-gray-700/50 dark:to-gray-800/50 rounded-xl border border-primary-200 dark:border-primary-800">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-semibold text-gray-800 dark:text-white">Quick Questions</h3>
                        <button id="close-suggestions" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="suggestions-list" class="flex flex-wrap gap-2">
                        <!-- Suggestions will be loaded here -->
                    </div>
                </div>
            </div>

            <!-- Chat Container -->
            <div class="flex-1 flex flex-col overflow-hidden">
                <!-- Welcome Message -->
                <div id="welcome-message" class="<?php echo !empty($chatHistory) ? 'hidden' : ''; ?> bg-gradient-to-r from-primary-50 via-blue-50 to-purple-50 dark:from-gray-800 dark:via-gray-900 dark:to-gray-800 p-8">
                    <div class="max-w-4xl mx-auto text-center">
                        <div class="inline-block bg-gradient-to-br from-primary-500 to-purple-600 p-6 rounded-2xl mb-6 shadow-lg">
                            <i class="fas fa-robot text-white text-5xl animate-float"></i>
                        </div>
                        <h2 class="text-3xl font-bold gradient-text mb-4">Welcome to Your AI Assistant!</h2>
                        <p class="text-gray-600 dark:text-gray-300 mb-6 text-lg">
                            I'm here to help you navigate your learning journey at Iqra College. 
                            Ask me anything about courses, assignments, payments, or academic support.
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                            <div class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-primary-200 dark:border-primary-800 shadow-lg">
                                <h4 class="font-semibold text-gray-800 dark:text-white mb-2 flex items-center justify-center">
                                    <i class="fas fa-book text-primary-600 dark:text-primary-400 mr-2"></i>
                                    Course Information
                                </h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    Ask about enrolled courses, materials, schedules
                                </p>
                            </div>
                            <div class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-primary-200 dark:border-primary-800 shadow-lg">
                                <h4 class="font-semibold text-gray-800 dark:text-white mb-2 flex items-center justify-center">
                                    <i class="fas fa-tasks text-primary-600 dark:text-primary-400 mr-2"></i>
                                    Assignment Help
                                </h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    Get assistance with assignments and deadlines
                                </p>
                            </div>
                            <div class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-primary-200 dark:border-primary-800 shadow-lg">
                                <h4 class="font-semibold text-gray-800 dark:text-white mb-2 flex items-center justify-center">
                                    <i class="fas fa-chart-line text-primary-600 dark:text-primary-400 mr-2"></i>
                                    Performance
                                </h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    Check your grades, scores, and progress
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chat Messages -->
                <div id="chat-messages" class="flex-1 overflow-y-auto p-4 lg:p-6 space-y-6 scrollbar-thin">
                    <?php if (!empty($chatHistory)): ?>
                        <?php foreach ($chatHistory as $msg): ?>
                            <div class="message-enter <?php echo $msg['message_type'] === 'student' ? 'flex justify-end' : 'flex justify-start'; ?>" data-message-id="<?php echo $msg['id']; ?>">
                                <div class="max-w-2xl lg:max-w-3xl <?php echo $msg['message_type'] === 'student' ? 'bg-gradient-to-r from-primary-600 to-primary-700 text-white rounded-br-none' : 'bg-white dark:bg-gray-800 text-gray-800 dark:text-white border border-gray-200 dark:border-gray-700 rounded-bl-none'; ?> rounded-2xl px-4 py-3 shadow-lg group relative">
                                    <?php if ($msg['message_type'] === 'ai'): ?>
                                        <div class="flex items-center space-x-2 mb-2">
                                            <div class="relative">
                                                <i class="fas fa-robot text-primary-600 dark:text-primary-400"></i>
                                                <div class="absolute -top-1 -right-1 w-2 h-2 bg-green-500 rounded-full"></div>
                                            </div>
                                            <span class="text-xs font-semibold text-primary-600 dark:text-primary-400">IQRA Assistant</span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400 ml-auto">
                                                <?php echo date('g:i A', strtotime($msg['created_at'])); ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-xs font-semibold">You</span>
                                            <span class="text-xs opacity-70">
                                                <?php echo date('g:i A', strtotime($msg['created_at'])); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <p class="whitespace-pre-wrap"><?php echo htmlspecialchars($msg['message']); ?></p>
                                    
                                    <!-- Message Actions (on hover) -->
                                    <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button onclick="copyMessage(this)" class="p-1.5 bg-white dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" title="Copy message">
                                            <i class="fas fa-copy text-xs text-gray-600 dark:text-gray-400"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <!-- Typing Indicator -->
                    <div id="typing-indicator" class="hidden flex justify-start">
                        <div class="max-w-2xl lg:max-w-3xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl px-4 py-3 shadow-lg">
                            <div class="flex items-center space-x-2 mb-2">
                                <div class="typing-indicator">
                                    <span class="typing-dot"></span>
                                    <span class="typing-dot"></span>
                                    <span class="typing-dot"></span>
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">AI Assistant is typing...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chat Input -->
                <div class="bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 p-4">
                    <div class="max-w-4xl mx-auto">
                        <form id="chat-form" class="flex items-end space-x-3">
                            <div class="flex-1 relative">
                                <textarea 
                                    id="message-input" 
                                    rows="1"
                                    placeholder="Ask me anything about your courses, assignments, or payments..."
                                    class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500 resize-none text-gray-800 dark:text-white pr-12 scrollbar-thin"
                                ></textarea>
                                <div class="absolute right-3 bottom-3 flex items-center space-x-2">
                                    <button type="button" id="microphone-btn" class="text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors relative" title="Voice input">
                                        <i class="fas fa-microphone"></i>
                                        <span id="recording-indicator" class="hidden absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full pulse-recording"></span>
                                    </button>
                                    <button type="button" id="attach-btn" class="text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors" title="Attach file">
                                        <i class="fas fa-paperclip"></i>
                                    </button>
                                </div>
                            </div>
                            <button 
                                type="submit"
                                id="send-btn"
                                class="bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white px-6 py-3 rounded-xl transition-all transform hover:scale-105 shadow-lg disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                            >
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                        
                        <!-- Quick Actions -->
                        <div class="mt-3 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                            <div class="flex items-center space-x-4">
                                <span>Press Enter to send, Shift+Enter for new line</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span id="char-count">0/1000</span>
                                <span class="text-xs">
                                    <i class="fas fa-shield-alt text-green-500 mr-1"></i>
                                    Secure
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 hidden lg:hidden"></div>

    <script>
        // DOM Elements
        const chatMessages = document.getElementById('chat-messages');
        const chatForm = document.getElementById('chat-form');
        const messageInput = document.getElementById('message-input');
        const sendBtn = document.getElementById('send-btn');
        const typingIndicator = document.getElementById('typing-indicator');
        const welcomeMessage = document.getElementById('welcome-message');
        const charCount = document.getElementById('char-count');
        const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const clearHistoryBtn = document.getElementById('clear-history');
        const suggestionsBtn = document.getElementById('suggestions-btn');
        const suggestionsPanel = document.getElementById('suggestions-panel');
        const closeSuggestionsBtn = document.getElementById('close-suggestions');
        const suggestionsList = document.getElementById('suggestions-list');
        const microphoneBtn = document.getElementById('microphone-btn');
        const recordingIndicator = document.getElementById('recording-indicator');
        const searchBtn = document.getElementById('search-btn');
        const searchBar = document.getElementById('search-bar');
        const searchInput = document.getElementById('search-input');
        const closeSearch = document.getElementById('close-search');
        const searchResults = document.getElementById('search-results');

        // Voice recognition
        let recognition = null;
        let isRecording = false;

        // Check for browser support
        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            recognition = new SpeechRecognition();
            recognition.continuous = false;
            recognition.interimResults = false;
            recognition.lang = 'en-US';

            recognition.onresult = function(event) {
                const transcript = event.results[0][0].transcript;
                messageInput.value = transcript;
                updateCharCount();
                autoResizeTextarea();
                stopRecording();
            };

            recognition.onerror = function(event) {
                console.error('Speech recognition error:', event.error);
                stopRecording();
                alert('Speech recognition error. Please try typing instead.');
            };

            recognition.onend = function() {
                stopRecording();
            };
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            scrollToBottom();
            updateCharCount();
            loadSuggestions();
            
            // Set up event listeners
            messageInput.addEventListener('input', updateCharCount);
            messageInput.addEventListener('input', autoResizeTextarea);
            mobileMenuToggle.addEventListener('click', toggleSidebar);
            sidebarOverlay.addEventListener('click', toggleSidebar);
            clearHistoryBtn.addEventListener('click', clearChatHistory);
            suggestionsBtn.addEventListener('click', toggleSuggestions);
            closeSuggestionsBtn.addEventListener('click', toggleSuggestions);
            chatForm.addEventListener('submit', handleSubmit);
            searchBtn.addEventListener('click', toggleSearch);
            closeSearch.addEventListener('click', toggleSearch);
            searchInput.addEventListener('input', debounce(handleSearch, 500));
            
            if (recognition) {
                microphoneBtn.addEventListener('click', toggleVoiceInput);
            } else {
                microphoneBtn.addEventListener('click', function() {
                    alert('Voice input is not supported in your browser. Please use Chrome or Edge.');
                });
            }
        });

        // Auto-resize textarea
        function autoResizeTextarea() {
            messageInput.style.height = 'auto';
            messageInput.style.height = (messageInput.scrollHeight) + 'px';
        }

        // Update character count
        function updateCharCount() {
            const length = messageInput.value.length;
            charCount.textContent = `${length}/1000`;
            
            if (length > 900) {
                charCount.classList.add('text-red-500');
            } else if (length > 800) {
                charCount.classList.add('text-yellow-500');
            } else {
                charCount.classList.remove('text-red-500', 'text-yellow-500');
            }
        }

        // Toggle sidebar on mobile
        function toggleSidebar() {
            sidebar.classList.toggle('hidden');
            sidebarOverlay.classList.toggle('hidden');
        }

        // Toggle suggestions panel
        function toggleSuggestions() {
            suggestionsPanel.classList.toggle('hidden');
        }

        // Toggle search bar
        function toggleSearch() {
            searchBar.classList.toggle('hidden');
            if (!searchBar.classList.contains('hidden')) {
                searchInput.focus();
            } else {
                searchResults.innerHTML = '';
            }
        }

        // Handle search
        function handleSearch() {
            const searchTerm = searchInput.value.trim();
            if (searchTerm.length < 2) {
                searchResults.innerHTML = '';
                return;
            }

            fetch('ai_assistant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=search_messages&search_term=${encodeURIComponent(searchTerm)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.results.length > 0) {
                    searchResults.innerHTML = '';
                    data.results.forEach(result => {
                        const div = document.createElement('div');
                        div.className = 'p-3 bg-white dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 cursor-pointer';
                        div.innerHTML = `
                            <p class="text-sm text-gray-800 dark:text-white">${escapeHtml(result.message.substring(0, 100))}${result.message.length > 100 ? '...' : ''}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">${new Date(result.created_at).toLocaleString()}</p>
                        `;
                        div.addEventListener('click', () => {
                            scrollToMessage(result.id);
                            toggleSearch();
                        });
                        searchResults.appendChild(div);
                    });
                } else {
                    searchResults.innerHTML = '<p class="text-sm text-gray-500 dark:text-gray-400 p-3">No results found</p>';
                }
            });
        }

        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Scroll to message
        function scrollToMessage(messageId) {
            const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
            if (messageElement) {
                messageElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                messageElement.style.animation = 'none';
                setTimeout(() => {
                    messageElement.style.animation = 'messageEnter 0.3s ease-out';
                }, 10);
            }
        }

        // Toggle voice input
        function toggleVoiceInput() {
            if (isRecording) {
                stopRecording();
            } else {
                startRecording();
            }
        }

        function startRecording() {
            if (!recognition) return;
            
            try {
                recognition.start();
                isRecording = true;
                microphoneBtn.classList.add('text-red-500');
                recordingIndicator.classList.remove('hidden');
            } catch (e) {
                console.error('Error starting recognition:', e);
            }
        }

        function stopRecording() {
            if (recognition && isRecording) {
                recognition.stop();
                isRecording = false;
                microphoneBtn.classList.remove('text-red-500');
                recordingIndicator.classList.add('hidden');
            }
        }

        // Copy message
        function copyMessage(button) {
            const messageDiv = button.closest('.message-enter').querySelector('p');
            const text = messageDiv.textContent;
            
            navigator.clipboard.writeText(text).then(() => {
                const icon = button.querySelector('i');
                const originalClass = icon.className;
                icon.className = 'fas fa-check text-xs text-green-500';
                setTimeout(() => {
                    icon.className = originalClass;
                }, 2000);
            });
        }

        // Load suggestions
        function loadSuggestions() {
            fetch('ai_assistant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_suggestions'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    suggestionsList.innerHTML = '';
                    data.suggestions.forEach(suggestion => {
                        const button = document.createElement('button');
                        button.className = 'px-3 py-2 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors text-sm border border-gray-200 dark:border-gray-600';
                        button.textContent = suggestion;
                        button.addEventListener('click', () => {
                            messageInput.value = suggestion;
                            messageInput.focus();
                            autoResizeTextarea();
                            updateCharCount();
                        });
                        suggestionsList.appendChild(button);
                    });
                }
            });
        }

        // Clear chat history
        function clearChatHistory() {
            if (!confirm('Are you sure you want to clear all chat history? This action cannot be undone.')) {
                return;
            }
            
            fetch('ai_assistant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=clear_history'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const messages = chatMessages.querySelectorAll('.message-enter');
                    messages.forEach(msg => msg.remove());
                    welcomeMessage.classList.remove('hidden');
                    chatMessages.scrollTop = 0;
                }
            });
        }

        // Handle form submission
        function handleSubmit(e) {
            e.preventDefault();
            sendMessage();
        }

        // Send message
        function sendMessage() {
            const message = messageInput.value.trim();
            if (!message || message.length > 1000) return;

            if (!welcomeMessage.classList.contains('hidden')) {
                welcomeMessage.classList.add('hidden');
            }

            messageInput.disabled = true;
            sendBtn.disabled = true;
            messageInput.value = '';
            autoResizeTextarea();
            updateCharCount();

            addMessage(message, 'student');
            typingIndicator.classList.remove('hidden');
            scrollToBottom();

            const formData = new FormData();
            formData.append('action', 'chat');
            formData.append('message', message);

            fetch('ai_assistant.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                typingIndicator.classList.add('hidden');
                
                if (data.success) {
                    addMessage(data.response, 'ai', data.fallback || false, data.api_used || 'unknown');
                } else {
                    addMessage(data.response || data.error || 'Sorry, I encountered an error. Please try again.', 'ai', true, 'error');
                }
                
                messageInput.disabled = false;
                sendBtn.disabled = false;
                messageInput.focus();
                scrollToBottom();
            })
            .catch(error => {
                typingIndicator.classList.add('hidden');
                addMessage('Sorry, I encountered an error. Please check your connection and try again.', 'ai', true, 'error');
                messageInput.disabled = false;
                sendBtn.disabled = false;
                messageInput.focus();
                scrollToBottom();
            });
        }

        // Add message to chat
        function addMessage(text, type, isFallback = false, apiUsed = 'unknown') {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message-enter flex ${type === 'student' ? 'justify-end' : 'justify-start'}`;
            
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            
            let apiBadge = '';
            if (type === 'ai' && !isFallback) {
                if (apiUsed === 'openai') {
                    apiBadge = '<span class="text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 px-2 py-1 rounded">OpenAI</span>';
                } else if (apiUsed === 'deepseek') {
                    apiBadge = '<span class="text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 px-2 py-1 rounded">DeepSeek</span>';
                }
            }
            
            messageDiv.innerHTML = `
                <div class="max-w-2xl lg:max-w-3xl ${type === 'student' ? 'bg-gradient-to-r from-primary-600 to-primary-700 text-white rounded-br-none' : 'bg-white dark:bg-gray-800 text-gray-800 dark:text-white border border-gray-200 dark:border-gray-700 rounded-bl-none'} rounded-2xl px-4 py-3 shadow-lg group relative">
                    ${type === 'ai' ? `
                        <div class="flex items-center space-x-2 mb-2">
                            <div class="relative">
                                <i class="fas fa-robot text-primary-600 dark:text-primary-400"></i>
                                ${isFallback ? '<div class="absolute -top-1 -right-1 w-2 h-2 bg-yellow-500 rounded-full"></div>' : '<div class="absolute -top-1 -right-1 w-2 h-2 bg-green-500 rounded-full"></div>'}
                            </div>
                            <span class="text-xs font-semibold text-primary-600 dark:text-primary-400">IQRA Assistant</span>
                            ${apiBadge}
                            ${isFallback ? '<span class="text-xs bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 px-2 py-1 rounded">Local Response</span>' : ''}
                            <span class="text-xs text-gray-500 dark:text-gray-400 ml-auto">
                                ${timeStr}
                            </span>
                        </div>
                    ` : `
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold">You</span>
                            <span class="text-xs opacity-70">
                                ${timeStr}
                            </span>
                        </div>
                    `}
                    <p class="whitespace-pre-wrap">${escapeHtml(text)}</p>
                    <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button onclick="copyMessage(this)" class="p-1.5 bg-white dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" title="Copy message">
                            <i class="fas fa-copy text-xs text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>
                </div>
            `;
            
            chatMessages.insertBefore(messageDiv, typingIndicator);
            scrollToBottom();
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-scroll to bottom
        function scrollToBottom() {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === '/') {
                e.preventDefault();
                messageInput.focus();
            }
            
            if (e.key === 'Escape') {
                if (!suggestionsPanel.classList.contains('hidden')) {
                    suggestionsPanel.classList.add('hidden');
                }
                if (!searchBar.classList.contains('hidden')) {
                    toggleSearch();
                }
            }
        });

        // Handle Enter key in textarea
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (!messageInput.disabled) {
                    sendMessage();
                }
            }
        });
        
        // Ensure dark mode is initialized on page load
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
    
    <?php include __DIR__ . '/../includes/student_ai_button.php'; ?>
</body>
</html>
