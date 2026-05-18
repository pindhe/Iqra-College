<?php
/**
 * Student - View Certificate
 * Premium certificate of completion with print support
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

requireRole('student');

$studentId = getCurrentUserId();
$courseId = intval($_GET['course_id'] ?? 0);

if ($courseId <= 0) {
    header('Location: courses.php');
    exit();
}

// Get course details
$course = getCourseById($courseId);
if (!$course) {
    header('Location: courses.php');
    exit();
}

// Check enrollment
if (!isEnrolled($studentId, $courseId)) {
    header('Location: courses.php');
    exit();
}

// Only allow certificate if course is completed (all lessons done)
if (!isCourseCompleted($studentId, $courseId)) {
    header('Location: courses.php?id=' . $courseId . '&error=complete_required');
    exit();
}

// Get or generate certificate
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT * FROM certificates WHERE student_id = ? AND course_id = ?");
$stmt->execute([$studentId, $courseId]);
$certificate = $stmt->fetch();

if (!$certificate) {
    // Generate certificate if it doesn't exist
    $certificateId = generateCertificate($studentId, $courseId);
    if ($certificateId) {
        $stmt = $pdo->prepare("SELECT * FROM certificates WHERE id = ?");
        $stmt->execute([$certificateId]);
        $certificate = $stmt->fetch();
    }
}

if (!$certificate) {
    header('Location: courses.php?id=' . $courseId);
    exit();
}

$user = getUserById($studentId);
$studentName = $user['name'] ?? 'Student';
$studentCode = getUserCode($studentId);

$teacher = getUserById($course['teacher_id']);
$teacherName = $teacher['name'] ?? 'Course Facilitator';
$collegePresident = 'Nour Hassan';

$issuedDate = !empty($certificate['issued_at']) ? $certificate['issued_at'] : date('Y-m-d');
$showCongrats = isset($_GET['congrats']) && $_GET['congrats'] === '1';

$currentPage = 'courses';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled' ? 'dark' : ''; ?>">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate - <?php echo htmlspecialchars($course['title']); ?> - IQRA Online College</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .font-playfair { font-family: 'Playfair Display', Georgia, serif; }
        .certificate {
            background: #FDFBF7;
            border: 12px solid #1e3a5f;
            box-shadow: inset 0 0 0 3px #C9A227;
        }
        .seal {
            width: 72px; height: 72px;
            border-radius: 50%;
            border: 3px solid #C9A227;
            background: #FDFBF7;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; color: #1e3a5f; font-size: 0.9rem;
            font-family: 'Playfair Display', serif;
        }
        @media print {
            body { margin: 0; background: #fff !important; }
            .no-print { display: none !important; }
            .certificate { box-shadow: none; }
            @page { margin: 12mm; size: A4 landscape; }
        }
        @media (max-width: 640px) {
            .certificate { padding: 1.5rem !important; }
            .certificate .text-5xl { font-size: 1.75rem; }
            .certificate .text-4xl { font-size: 1.5rem; }
            .certificate .text-3xl { font-size: 1.25rem; }
            .certificate .text-2xl { font-size: 1.125rem; }
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    <!-- Navigation -->
    <nav class="no-print bg-white dark:bg-gray-800 shadow-lg border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-4">
                    <a href="index.php" class="text-indigo-600 dark:text-indigo-400 hover:underline font-semibold flex items-center gap-1">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                    <span class="text-gray-400 dark:text-gray-500">|</span>
                    <a href="courses.php?id=<?php echo (int)$courseId; ?>" class="text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 font-semibold flex items-center gap-1">
                        <i class="fas fa-arrow-left"></i> Back to Course
                    </a>
                </div>
                <div class="flex items-center gap-2">
                    <h1 class="text-lg font-bold text-gray-800 dark:text-white hidden sm:block">Certificate of Completion</h1>
                    <button onclick="window.print()" class="bg-gradient-to-r from-indigo-500 to-purple-600 text-white px-5 py-2.5 rounded-xl font-semibold hover:from-indigo-600 hover:to-purple-700 transition-all shadow-lg flex items-center gap-2">
                        <i class="fas fa-print"></i> Print Certificate
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <?php if ($showCongrats): ?>
    <div class="bg-gradient-to-r from-green-500 to-emerald-600 text-white text-center py-6 px-4 no-print">
        <div class="max-w-2xl mx-auto">
            <p class="text-3xl font-bold mb-2">🎉 Congratulations!</p>
            <p class="text-lg opacity-95">You have successfully completed <strong><?php echo htmlspecialchars($course['title']); ?></strong>. Here is your certificate of completion.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">
        <div class="certificate max-w-4xl mx-auto p-8 sm:p-12 shadow-2xl relative" style="min-height: 560px;">
            <!-- Seal -->
            <div class="absolute top-8 right-8 seal font-playfair">IQRA</div>

            <!-- Header -->
            <div class="text-center mb-6 sm:mb-8">
                <h1 class="text-4xl sm:text-5xl font-bold text-[#1e3a5f] mb-1 font-playfair">IQRA COLLEGE</h1>
                <p class="text-xl sm:text-2xl text-[#1e3a5f]/80 font-semibold tracking-widest">ONLINE LEARNING</p>
            </div>

            <!-- Certificate Title -->
            <div class="text-center mb-6 sm:mb-8">
                <h2 class="text-2xl sm:text-3xl font-bold text-[#1e3a5f] mb-3 font-playfair">CERTIFICATE OF COMPLETION</h2>
                <div class="border-t-2 border-[#C9A227] w-24 sm:w-32 mx-auto"></div>
            </div>

            <!-- Presented To -->
            <div class="text-center mb-6 sm:mb-8">
                <p class="text-base sm:text-lg text-[#1e3a5f] font-bold mb-3 tracking-wider">PRESENTED TO</p>
                <div class="border-t border-[#1e3a5f]/30 w-full max-w-md mx-auto mb-2"></div>
                <p class="text-3xl sm:text-4xl font-bold text-[#1e3a5f] mb-1 font-playfair">
                    <?php echo htmlspecialchars($studentName); ?>
                </p>
                <?php if (!empty($studentCode)): ?>
                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($studentCode); ?></p>
                <?php endif; ?>
                <div class="border-t border-[#1e3a5f]/30 w-full max-w-md mx-auto mt-2"></div>
            </div>

            <!-- Course Information -->
            <div class="text-center mb-8 sm:mb-10">
                <p class="text-base sm:text-lg text-gray-700 mb-2">
                    has successfully completed the course
                </p>
                <p class="text-xl sm:text-2xl font-bold text-[#1e3a5f] mb-2 font-playfair">
                    <?php echo htmlspecialchars($course['title']); ?>
                </p>
                <?php if (!empty($course['level']) || ((int)($course['duration'] ?? 0) > 0)): ?>
                <p class="text-sm text-gray-600 mb-2">
                    <?php if (!empty($course['level'])): ?><span class="capitalize"><?php echo htmlspecialchars($course['level']); ?></span><?php endif; ?>
                    <?php if (!empty($course['level']) && (int)($course['duration'] ?? 0) > 0): ?> · <?php endif; ?>
                    <?php if ((int)($course['duration'] ?? 0) > 0): ?><?php echo (int)$course['duration']; ?> hours<?php endif; ?>
                </p>
                <?php endif; ?>
                <p class="text-sm text-gray-600 mt-2">
                    <?php if (!empty($certificate['certificate_number'])): ?>
                    Certificate No. <span class="font-semibold text-[#1e3a5f]"><?php echo htmlspecialchars($certificate['certificate_number']); ?></span>
                    <?php else: ?>
                    Certificate ID <span class="font-semibold text-[#1e3a5f]">#<?php echo (int)$certificate['id']; ?></span>
                    <?php endif; ?>
                </p>
                <p class="text-sm text-gray-600 mt-1">
                    Issued <?php echo date('F j, Y', strtotime($issuedDate)); ?>
                </p>
            </div>

            <!-- Signatures -->
            <div class="absolute bottom-8 sm:bottom-12 left-0 right-0">
                <div class="flex flex-col sm:flex-row justify-between gap-8 px-4 sm:px-12">
                    <div class="text-center flex-1">
                        <div class="border-t-2 border-[#1e3a5f] w-32 sm:w-40 mx-auto mb-2"></div>
                        <p class="text-base sm:text-lg font-bold text-[#1e3a5f] font-playfair"><?php echo htmlspecialchars($teacherName); ?></p>
                        <p class="text-xs sm:text-sm text-gray-600">Course Facilitator</p>
                    </div>
                    <div class="text-center flex-1">
                        <div class="border-t-2 border-[#1e3a5f] w-32 sm:w-40 mx-auto mb-2"></div>
                        <p class="text-base sm:text-lg font-bold text-[#1e3a5f] font-playfair"><?php echo htmlspecialchars($collegePresident); ?></p>
                        <p class="text-xs sm:text-sm text-gray-600">College President</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="no-print text-center mt-8 space-y-3">
            <div class="flex flex-wrap justify-center gap-3">
                <a href="courses.php?id=<?php echo (int)$courseId; ?>" 
                   class="inline-flex items-center gap-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-5 py-2.5 rounded-xl font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    <i class="fas fa-arrow-left"></i> Back to Course
                </a>
                <a href="courses.php" 
                   class="inline-flex items-center gap-2 bg-gradient-to-r from-indigo-500 to-purple-600 text-white px-5 py-2.5 rounded-xl font-semibold hover:from-indigo-600 hover:to-purple-700 transition-all shadow-lg">
                    <i class="fas fa-graduation-cap"></i> View My Courses
                </a>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400">Use <strong>Print</strong> → Save as PDF to download a PDF copy.</p>
        </div>
    </div>
    
    <div class="no-print"><?php include __DIR__ . '/../includes/student_ai_button.php'; ?></div>
</body>
</html>
