<?php
/**
 * Home Page - Dynamic Landing
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    $role = getCurrentUserRole();
    if ($role === 'admin') { header('Location: /Iqra-College/admin/index.php'); exit(); }
    if ($role === 'teacher') { header('Location: /Iqra-College/teacher/index.php'); exit(); }
    if ($role === 'cashier') { header('Location: /Iqra-College/cashier/index.php'); exit(); }
    header('Location: /Iqra-College/student/index.php');
    exit();
}

$pdo = null;
$totalCourses = 0;
$totalStudents = 0;
$totalTeachers = 0;
$featuredCourses = [];
$upcomingEvents = [];
$footerCategories = [];
$siteName = 'IQRA College';
$siteEmail = 'kharash420@gmail.com';
$sitePhone = '';
$siteAddress = '';
$contactSuccess = '';
$contactError = '';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'published'");
    $totalCourses = (int) $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
    $totalStudents = (int) $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher','cashier')");
    $totalTeachers = (int) $stmt->fetchColumn();
} catch (PDOException $e) { /* use 0 */ }

try {
    if ($pdo) {
        $stmt = $pdo->query("
            SELECT c.id, c.title, c.slug, c.thumbnail, c.price, c.is_free, c.level,
                   (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id) AS enrolled
            FROM courses c
            WHERE c.status = 'published'
            ORDER BY enrolled DESC, c.created_at DESC
            LIMIT 6
        ");
        $featuredCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $featuredCourses = []; }

try {
    if ($pdo) {
        $stmt = $pdo->query("
            SELECT id, title, event_date, location, event_type
            FROM events
            WHERE event_date >= CURDATE()
            ORDER BY event_date ASC
            LIMIT 5
        ");
        $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $upcomingEvents = []; }

try {
    if ($pdo) {
        $stmt = $pdo->query("SELECT id, name, slug FROM categories ORDER BY name LIMIT 8");
        $footerCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $footerCategories = []; }

if (function_exists('getSetting')) {
    $siteName = getSetting('site_name', $siteName);
    $siteEmail = getSetting('site_email', $siteEmail);
    $sitePhone = getSetting('site_phone', $sitePhone);
    $siteAddress = getSetting('site_address', $siteAddress);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $contactName = trim($_POST['contact_name'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $contactSubject = trim($_POST['contact_subject'] ?? '');
    $contactMessage = trim($_POST['contact_message'] ?? '');

    if ($contactName === '' || $contactEmail === '' || $contactSubject === '' || $contactMessage === '') {
        $contactError = 'Please fill in all fields.';
    } elseif (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $contactError = 'Please enter a valid email address.';
    } else {
        $to = $siteEmail ?: 'support@iqracollege.com';
        $subject = '[Contact Form] ' . $contactSubject;
        $body = "Name: {$contactName}\nEmail: {$contactEmail}\n\nMessage:\n{$contactMessage}\n";
        $headers = "From: {$contactName} <{$contactEmail}>\r\n";
        $headers .= "Reply-To: {$contactEmail}\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        if (@mail($to, $subject, $body, $headers)) {
            $contactSuccess = 'Your message has been sent. We will get back to you soon.';
        } else {
            $contactError = 'Failed to send message. Please try again later.';
        }
    }
}

$heroInstructorUrl = null;
foreach (['hero-instructor.png', 'hero-instructor.webp'] as $f) {
    if (file_exists(__DIR__ . '/assets/images/' . $f)) {
        $heroInstructorUrl = 'assets/images/' . $f;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($siteName); ?> – Online Learning</title>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={theme:{extend:{colors:{brand:{400:'#60a5fa',500:'#3b82f6',600:'#2563eb',700:'#1d4ed8'}}}}};</script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Plus Jakarta Sans',system-ui,sans-serif}
        .nav-blur{background:rgba(255,255,255,.92);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
        .gradient-text{background:linear-gradient(135deg,#1d4ed8,#3b82f6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
        .card-lift{transition:transform .25s ease,box-shadow .25s ease}
        .card-lift:hover{transform:translateY(-6px);box-shadow:0 24px 48px -12px rgba(0,0,0,.15)}
        .reveal{opacity:0;transform:translateY(24px);transition:opacity .6s ease,transform .6s ease}
        .reveal.visible{opacity:1;transform:translateY(0)}
        .hero-pattern{background-image:radial-gradient(circle at 20% 80%, rgba(59,130,246,.08) 0%, transparent 50%),radial-gradient(circle at 80% 20%, rgba(99,102,241,.06) 0%, transparent 50%)}
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased min-h-screen">
    <!-- Nav -->
    <nav class="nav-blur fixed w-full top-0 z-50 border-b border-slate-200/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16 md:h-18">
                <a href="#home" class="flex items-center gap-2.5 flex-shrink-0">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center shadow-lg shadow-brand-500/25">
                        <span class="text-white font-bold text-lg">I</span>
                    </div>
                    <span class="text-xl font-extrabold text-slate-800">IQRA<span class="text-brand-600">College</span></span>
                </a>
                <div class="hidden lg:flex items-center gap-8 absolute left-1/2 transform -translate-x-1/2">
                    <a href="#home" class="text-slate-600 hover:text-brand-600 font-medium transition-colors">Home</a>
                    <a href="#courses" class="text-slate-600 hover:text-brand-600 font-medium transition-colors">Courses</a>
                    <a href="#about" class="text-slate-600 hover:text-brand-600 font-medium transition-colors">About</a>
                    <a href="#contact" class="text-slate-600 hover:text-brand-600 font-medium transition-colors">Contact</a>
                </div>
                <div class="hidden lg:flex items-center gap-3 flex-shrink-0">
                    <a href="auth/login.php" class="px-5 py-2.5 rounded-xl font-semibold text-slate-700 hover:bg-slate-100 transition-colors">Login</a>
                    <a href="auth/register.php" class="px-5 py-2.5 rounded-xl font-semibold bg-gradient-to-r from-brand-500 to-brand-600 text-white shadow-lg shadow-brand-500/25 hover:shadow-brand-500/40 transition-all">Register</a>
                </div>
                <button id="mobile-menu-btn" class="lg:hidden p-2.5 rounded-lg text-slate-600 hover:bg-slate-100" aria-label="Menu">
                    <i class="fas fa-bars text-xl"></i>
                </button>
            </div>
        </div>
        <div id="mobile-menu" class="lg:hidden hidden border-t border-slate-200 bg-white/98">
            <div class="px-4 py-4 space-y-1">
                <a href="#home" class="block py-3 text-slate-600 hover:text-brand-600 font-medium">Home</a>
                <a href="#courses" class="block py-3 text-slate-600 hover:text-brand-600 font-medium">Courses</a>
                <a href="#about" class="block py-3 text-slate-600 hover:text-brand-600 font-medium">About</a>
                <a href="#contact" class="block py-3 text-slate-600 hover:text-brand-600 font-medium">Contact</a>
                <div class="pt-4 flex flex-col gap-2">
                    <a href="auth/login.php" class="block text-center py-3 rounded-xl font-semibold text-slate-700 bg-slate-100">Login</a>
                    <a href="auth/register.php" class="block text-center py-3 rounded-xl font-semibold bg-gradient-to-r from-brand-500 to-brand-600 text-white">Register</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero -->
   <!-- ================= HERO SECTION ================= -->
<section id="home"
    class="relative pt-28 pb-20 md:pt-36 md:pb-28 bg-cover bg-center overflow-hidden
    <?php echo file_exists(__DIR__ . '/assets/images/bg.jpg') ? '' : ' hero-pattern'; ?>"
    <?php if (file_exists(__DIR__ . '/assets/images/bg.jpg')): ?>
        style="background-image: url('assets/images/bg.jpg');"
    <?php endif; ?>>

    <!-- Overlay -->
    <div class="absolute inset-0 bg-gradient-to-r from-white/95 via-white/85 to-white/60"></div>

    <!-- Blur Decorations -->
    <div class="absolute -top-32 -left-32 w-96 h-96 bg-brand-400/20 rounded-full blur-3xl animate-pulse"></div>
    <div class="absolute -bottom-32 -right-32 w-96 h-96 bg-indigo-400/20 rounded-full blur-3xl animate-pulse"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">

            <!-- LEFT CONTENT -->
            <div class="hero-left">
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-slate-900 leading-tight mb-6">
                    Master English at
                    <span class="gradient-text">College Level</span>
                </h1>

                <p class="text-lg sm:text-xl text-slate-700 mb-8 max-w-xl leading-relaxed">
                    Join <?php echo htmlspecialchars($siteName); ?>’s Learning Management System.
                    Grammar, Writing, Reading, and Listening with expert-led courses.
                </p>

                <div class="flex flex-wrap gap-4 mb-10">
                    <a href="auth/register.php"
                        class="inline-flex items-center gap-2 px-8 py-4 rounded-2xl font-bold text-white
                               bg-gradient-to-r from-brand-500 to-brand-600
                               shadow-lg hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                        <i class="fas fa-rocket"></i> Get Started Free
                    </a>

                    <a href="#courses"
                        class="inline-flex items-center gap-2 px-8 py-4 rounded-2xl font-bold text-slate-700
                               bg-white border-2 border-slate-200
                               hover:border-brand-400 hover:-translate-y-1 hover:shadow-lg transition-all duration-300">
                        <i class="fas fa-book-open"></i> View Courses
                    </a>
                </div>

                <div class="flex gap-10">
                    <div class="hero-stat delay-1">
                        <p class="text-2xl font-bold text-slate-900"><?php echo $totalCourses; ?></p>
                        <p class="text-sm text-slate-600">Courses</p>
                    </div>
                    <div class="hero-stat delay-2">
                        <p class="text-2xl font-bold text-slate-900"><?php echo $totalStudents; ?>+</p>
                        <p class="text-sm text-slate-600">Students</p>
                    </div>
                    <div class="hero-stat delay-3">
                        <p class="text-2xl font-bold text-slate-900"><?php echo $totalTeachers; ?></p>
                        <p class="text-sm text-slate-600">Instructors</p>
                    </div>
                </div>
            </div>

            <!-- RIGHT IMAGE -->
            <div class="hero-right flex justify-center">
                <?php
                $studentImg = file_exists(__DIR__ . '/assets/images/pin.png')
                    ? 'assets/images/pin.png'
                    : null;
                ?>
                <?php if ($studentImg): ?>
                    <img src="<?php echo htmlspecialchars($studentImg); ?>"
                         alt="Online Student"
                         class="w-full max-w-[400px] rounded-3xl shadow-2xl
                                hover:scale-105 transition-transform duration-500">
                <?php else: ?>
                    <div class="w-72 h-72 rounded-3xl bg-gradient-to-br from-brand-100 to-indigo-100
                                flex items-center justify-center shadow-2xl">
                        <i class="fas fa-user-graduate text-7xl text-brand-500/80"></i>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</section>

<!-- ================= HERO ANIMATIONS ================= -->
<style>
.hero-left {
    opacity: 0;
    transform: translateX(-40px);
    animation: heroLeft 1s ease forwards;
}
.hero-right {
    opacity: 0;
    transform: translateX(40px);
    animation: heroRight 1s ease forwards;
}
.hero-stat {
    opacity: 0;
    transform: translateY(20px);
    animation: heroUp 1s ease forwards;
}
.delay-1 { animation-delay: .3s; }
.delay-2 { animation-delay: .5s; }
.delay-3 { animation-delay: .7s; }

@keyframes heroLeft {
    to { opacity: 1; transform: translateX(0); }
}
@keyframes heroRight {
    to { opacity: 1; transform: translateX(0); }
}
@keyframes heroUp {
    to { opacity: 1; transform: translateY(0); }
}
</style>
<!-- ================================================== -->


    <!-- Featured Courses (dynamic) -->
    <section id="courses" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-14 reveal">
                <h2 class="text-3xl md:text-4xl font-extrabold text-slate-900 mb-4">Featured Courses</h2>
                <p class="text-slate-600 text-lg max-w-2xl mx-auto">Published courses you can start today. Sign up to view details and enroll.</p>
            </div>
            <?php if (empty($featuredCourses)): ?>
                <div class="text-center py-16 rounded-3xl bg-slate-50 border-2 border-dashed border-slate-200 reveal">
                    <i class="fas fa-book-open text-5xl text-slate-300 mb-4"></i>
                    <p class="text-slate-500 text-lg">No published courses yet. Check back soon.</p>
                    <a href="auth/register.php" class="inline-block mt-4 text-brand-600 font-semibold hover:underline">Create an account</a>
                </div>
            <?php else: ?>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($featuredCourses as $c): 
                        $thumb = !empty($c['thumbnail']) && file_exists(__DIR__ . '/uploads/courses/' . $c['thumbnail'])
                            ? 'uploads/courses/' . $c['thumbnail'] : null;
                        $price = ($c['is_free'] ?? 0) ? 'Free' : ('$' . number_format((float)($c['price'] ?? 0), 2));
                        $enrolled = (int)($c['enrolled'] ?? 0);
                    ?>
                        <a href="auth/register.php" class="group block card-lift bg-white rounded-2xl border border-slate-200 overflow-hidden reveal">
                            <?php if ($thumb): ?>
                                <img src="<?php echo htmlspecialchars($thumb); ?>" alt="" class="w-full h-44 object-cover">
                            <?php else: ?>
                                <div class="w-full h-44 bg-gradient-to-br from-brand-100 to-indigo-100 flex items-center justify-center">
                                    <i class="fas fa-book text-5xl text-brand-400"></i>
                                </div>
                            <?php endif; ?>
                            <div class="p-5">
                                <span class="inline-block px-2.5 py-1 rounded-lg text-xs font-semibold bg-brand-100 text-brand-700"><?php echo htmlspecialchars(ucfirst($c['level'] ?? 'beginner')); ?></span>
                                <h3 class="mt-2 font-bold text-slate-900 group-hover:text-brand-600 transition-colors line-clamp-2"><?php echo htmlspecialchars($c['title']); ?></h3>
                                <p class="mt-1 text-xs text-slate-500">Sign up to view full details</p>
                                <div class="mt-3 flex items-center justify-between text-sm text-slate-500">
                                    <span><i class="fas fa-users mr-1"></i><?php echo $enrolled; ?> enrolled</span>
                                    <span class="font-bold text-slate-800"><?php echo $price; ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="text-center mt-10 reveal">
                    <a href="auth/register.php" class="inline-flex items-center gap-2 text-brand-600 font-semibold hover:underline">Sign up to view all courses <i class="fas fa-arrow-right"></i></a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Upcoming Events (dynamic) -->
    <?php if (!empty($upcomingEvents)): ?>
    <section class="py-16 bg-slate-50 border-y border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-6 reveal">Upcoming Events</h2>
            <div class="flex flex-wrap gap-4">
                <?php foreach ($upcomingEvents as $ev): 
                    $d = new DateTime($ev['event_date']);
                ?>
                    <div class="flex items-center gap-4 px-5 py-4 rounded-2xl bg-white border border-slate-200 shadow-sm card-lift reveal">
                        <div class="w-14 h-14 rounded-xl bg-brand-100 flex flex-col items-center justify-center text-brand-700 shrink-0">
                            <span class="text-xs font-bold uppercase"><?php echo $d->format('M'); ?></span>
                            <span class="text-lg font-extrabold leading-none"><?php echo $d->format('d'); ?></span>
                        </div>
                        <div>
                            <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($ev['title']); ?></p>
                            <p class="text-sm text-slate-500"><?php echo $d->format('l, g:i A'); ?><?php if (!empty($ev['location'])): ?> · <?php echo htmlspecialchars($ev['location']); ?><?php endif; ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- About -->
    <section id="about" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-2 gap-12 lg:gap-16 items-center">
                <div class="reveal">
                    <h2 class="text-3xl md:text-4xl font-extrabold text-slate-900 mb-6">About <?php echo htmlspecialchars($siteName); ?></h2>
                    <p class="text-slate-600 text-lg mb-6">We provide college-level English education online: accredited courses, experienced instructors, and an interactive platform.</p>
                    <ul class="space-y-3">
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-brand-500"></i> Accredited courses</li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-brand-500"></i> Expert instructors</li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-brand-500"></i> Flexible schedules</li>
                    </ul>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 reveal">
                    <?php if ($heroInstructorUrl): ?>
                    <div class="sm:col-span-2 p-6 rounded-2xl bg-gradient-to-br from-slate-50 to-brand-50/30 border border-slate-100 card-lift flex flex-col sm:flex-row items-center gap-6">
                        <div class="w-32 h-32 sm:w-36 sm:h-40 rounded-2xl overflow-hidden bg-slate-100 flex-shrink-0 ring-2 ring-white/80 ring-offset-2 shadow-inner">
                            <img src="<?php echo htmlspecialchars($heroInstructorUrl); ?>" alt="Expert instructor" class="w-full h-full object-cover object-top" width="144" height="160">
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-900 mb-1">Meet our instructor</h3>
                            <p class="text-slate-600 text-sm">Learn from certified English professors and industry experts.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="p-6 rounded-2xl bg-slate-50 border border-slate-100 card-lift">
                        <div class="w-12 h-12 rounded-xl bg-brand-100 flex items-center justify-center mb-4"><i class="fas fa-user-graduate text-brand-600"></i></div>
                        <h3 class="font-bold text-slate-900 mb-2">Expert Instructors</h3>
                        <p class="text-slate-600 text-sm">Learn from certified English professors.</p>
                    </div>
                    <div class="p-6 rounded-2xl bg-slate-50 border border-slate-100 card-lift">
                        <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4"><i class="fas fa-laptop-code text-emerald-600"></i></div>
                        <h3 class="font-bold text-slate-900 mb-2">Modern Platform</h3>
                        <p class="text-slate-600 text-sm">Interactive LMS with quizzes and materials.</p>
                    </div>
                    <div class="p-6 rounded-2xl bg-slate-50 border border-slate-100 card-lift sm:col-span-2">
                        <div class="w-12 h-12 rounded-xl bg-amber-100 flex items-center justify-center mb-4"><i class="fas fa-award text-amber-600"></i></div>
                        <h3 class="font-bold text-slate-900 mb-2">Certification</h3>
                        <p class="text-slate-600 text-sm">Certificates on course completion.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact -->
    <section id="contact" class="py-20 bg-slate-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-14 reveal">
                <h2 class="text-3xl md:text-4xl font-extrabold text-slate-900 mb-4">Contact</h2>
                <p class="text-slate-600 text-lg max-w-2xl mx-auto">Questions? Reach out to our team.</p>
            </div>
            <div class="grid md:grid-cols-2 gap-12">
                <div class="reveal">
                    <div class="space-y-6">
                        <?php if ($siteAddress): ?>
                        <div class="flex gap-4">
                            <div class="w-12 h-12 rounded-xl bg-brand-100 flex items-center justify-center shrink-0"><i class="fas fa-map-marker-alt text-brand-600"></i></div>
                            <div><h4 class="font-bold text-slate-900">Address</h4><p class="text-slate-600"><?php echo htmlspecialchars($siteAddress); ?></p></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($sitePhone): ?>
                        <div class="flex gap-4">
                            <div class="w-12 h-12 rounded-xl bg-brand-100 flex items-center justify-center shrink-0"><i class="fas fa-phone text-brand-600"></i></div>
                            <div><h4 class="font-bold text-slate-900">Phone</h4><p class="text-slate-600"><?php echo htmlspecialchars($sitePhone); ?></p></div>
                        </div>
                        <?php endif; ?>
                        <div class="flex gap-4">
                            <div class="w-12 h-12 rounded-xl bg-brand-100 flex items-center justify-center shrink-0"><i class="fas fa-envelope text-brand-600"></i></div>
                            <div><h4 class="font-bold text-slate-900">Email</h4><p class="text-slate-600"><?php echo htmlspecialchars($siteEmail); ?></p></div>
                        </div>
                    </div>
                    <div class="flex gap-3 mt-8">
                        <a href="#" class="w-11 h-11 rounded-xl bg-slate-200 hover:bg-brand-500 hover:text-white flex items-center justify-center transition-colors"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="w-11 h-11 rounded-xl bg-slate-200 hover:bg-brand-500 hover:text-white flex items-center justify-center transition-colors"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="w-11 h-11 rounded-xl bg-slate-200 hover:bg-brand-500 hover:text-white flex items-center justify-center transition-colors"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                <div class="reveal">
                    <?php if ($contactError): ?>
                        <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 text-sm">
                            <i class="fas fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($contactError); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($contactSuccess): ?>
                        <div class="rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">
                            <i class="fas fa-circle-check mr-2"></i><?php echo htmlspecialchars($contactSuccess); ?>
                        </div>
                    <?php endif; ?>
                    <form id="contact-form" method="POST" class="space-y-5">
                        <input type="hidden" name="contact_submit" value="1">
                        <div><label class="block text-slate-700 font-medium mb-2">Name</label><input type="text" name="contact_name" required class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none" placeholder="Your name" value="<?php echo htmlspecialchars($_POST['contact_name'] ?? ''); ?>"></div>
                        <div><label class="block text-slate-700 font-medium mb-2">Email</label><input type="email" name="contact_email" required class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none" placeholder="Your email" value="<?php echo htmlspecialchars($_POST['contact_email'] ?? ''); ?>"></div>
                        <div><label class="block text-slate-700 font-medium mb-2">Subject</label><input type="text" name="contact_subject" required class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none" placeholder="Subject" value="<?php echo htmlspecialchars($_POST['contact_subject'] ?? ''); ?>"></div>
                        <div><label class="block text-slate-700 font-medium mb-2">Message</label><textarea rows="4" name="contact_message" required class="w-full px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none resize-none" placeholder="Your message"><?php echo htmlspecialchars($_POST['contact_message'] ?? ''); ?></textarea></div>
                        <button type="submit" class="w-full py-3.5 rounded-xl font-bold bg-gradient-to-r from-brand-500 to-brand-600 text-white shadow-lg hover:shadow-xl transition-all">Send Message</button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-slate-900 text-white py-14">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-4 gap-10 mb-12">
                <div>
                    <div class="flex items-center gap-2.5 mb-5">
                        <div class="w-10 h-10 rounded-xl bg-brand-500 flex items-center justify-center"><span class="font-bold">I</span></div>
                        <span class="text-xl font-extrabold">IQRA<span class="text-brand-400">College</span></span>
                    </div>
                    <p class="text-slate-400 text-sm">College-level English learning, accessible to everyone.</p>
                </div>
                <div>
                    <h4 class="font-bold mb-5">Links</h4>
                    <ul class="space-y-2.5 text-slate-400 text-sm">
                        <li><a href="#home" class="hover:text-white transition-colors">Home</a></li>
                        <li><a href="#about" class="hover:text-white transition-colors">About</a></li>
                        <li><a href="#contact" class="hover:text-white transition-colors">Contact</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-5">Categories</h4>
                    <ul class="space-y-2.5 text-slate-400 text-sm">
                        <?php if (empty($footerCategories)): ?>
                            <li><a href="auth/register.php" class="hover:text-white transition-colors">All courses</a></li>
                        <?php else: ?>
                            <?php foreach ($footerCategories as $cat): ?>
                                <li><a href="auth/register.php" class="hover:text-white transition-colors"><?php echo htmlspecialchars($cat['name']); ?></a></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-5">Newsletter</h4>
                    <p class="text-slate-400 text-sm mb-4">Updates on new courses and offers.</p>
                    <form id="newsletter-form" class="flex">
                        <input type="email" placeholder="Email" class="flex-1 px-4 py-2.5 rounded-l-lg text-slate-900 outline-none">
                        <button type="submit" class="px-4 py-2.5 rounded-r-lg bg-brand-500 hover:bg-brand-600 font-semibold"><i class="fas fa-paper-plane"></i></button>
                    </form>
                </div>
            </div>
            <div class="pt-8 border-t border-slate-800 flex flex-col sm:flex-row justify-between items-center gap-4">
                <p class="text-slate-500 text-sm">© <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?>.</p>
                <div class="flex gap-5 text-slate-400">
                    <a href="#" class="hover:text-white"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="hover:text-white"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="hover:text-white"><i class="fab fa-linkedin-in"></i></a>
                    <a href="#" class="hover:text-white"><i class="fab fa-instagram"></i></a>
                </div>
            </div>
        </div>
    </footer>

    <script>
(function(){
    var btn=document.getElementById('mobile-menu-btn'), m=document.getElementById('mobile-menu'), icon=btn&&btn.querySelector('i');
    if(btn&&m){btn.onclick=function(){m.classList.toggle('hidden'); if(icon){icon.className=m.classList.contains('hidden')?'fas fa-bars text-xl':'fas fa-times text-xl';}}}

    document.querySelectorAll('a[href^="#"]').forEach(function(a){
        var h=a.getAttribute('href'); if(h==='#')return;
        a.addEventListener('click',function(e){ e.preventDefault();
            var el=document.querySelector(h); if(el){ el.scrollIntoView({behavior:'smooth',block:'start'}); m&&m.classList.add('hidden'); if(icon)icon.className='fas fa-bars text-xl'; }
        });
    });

    var cf=document.getElementById('contact-form');
    if(cf)cf.onsubmit=function(e){e.preventDefault();alert('Thanks! We’ll get back to you soon.');cf.reset();};

    var nf=document.getElementById('newsletter-form');
    if(nf)nf.onsubmit=function(e){e.preventDefault();alert('Thanks for subscribing!');nf.reset();};

    var reveals=document.querySelectorAll('.reveal');
    function reveal(){
        reveals.forEach(function(r){
            var top=r.getBoundingClientRect().top, win=window.innerHeight;
            if(top<win-80){r.classList.add('visible');}
        });
    }
    window.addEventListener('scroll',reveal); window.addEventListener('load',reveal); reveal();
})();
    </script>
</body>
</html>
