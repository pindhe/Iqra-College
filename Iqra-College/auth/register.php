<?php
/**
 * Registration Page
 * Student registration only
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $role = getCurrentUserRole();
    if ($role === 'admin') {
        header('Location: /Iqra-College/admin/index.php');
    } elseif ($role === 'teacher') {
        header('Location: /Iqra-College/teacher/index.php');
    } else {
        header('Location: /Iqra-College/student/index.php');
    }
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all fields';
    } elseif (!validateEmail($email)) {
        $error = 'Invalid email format';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        try {
            $pdo = getDBConnection();

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Email already registered';
            } else {
                $studentId = generateStudentId($pdo);
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, student_id) VALUES (?, ?, ?, 'student', ?)");
                $stmt->execute([$name, $email, $hashedPassword, $studentId]);

                $success = 'Registration successful! Your Student ID is: <strong>' . htmlspecialchars($studentId) . '</strong><br>Please pay the course fee to access courses. You can now login.';
            }
        } catch (PDOException $e) {
            $error = 'Registration failed. Please try again.';
        }
    }
}

// Background: try local images first (auth-bg.jpg, auth-bg.png, arday.jpg), else Unsplash
$imgDir = __DIR__ . '/../assets/images';
$authBg = 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?auto=format&fit=crop&w=1920&q=80';
if (file_exists($imgDir . '/auth-bg.jpg')) {
    $authBg = '../assets/images/auth-bg.jpg';
} elseif (file_exists($imgDir . '/auth-bg.png')) {
    $authBg = '../assets/images/auth-bg.png';
} elseif (file_exists($imgDir . '/arday.jpg')) {
    $authBg = '../assets/images/arday.jpg';
} elseif (file_exists($imgDir . '/arday.png')) {
    $authBg = '../assets/images/arday.png';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="/Iqra-College/assets/images/iqra2.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - IQRA College</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { 50: '#eff6ff', 100: '#dbeafe', 400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8' }
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
        .auth-bg {
            background-image: url('<?php echo htmlspecialchars($authBg); ?>');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        @media (max-width: 768px) { .auth-bg { background-attachment: scroll; } }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-up { opacity: 0; animation: fadeUp 0.5s ease-out forwards; }
        .animate-fade-up-1 { animation-delay: 0.08s; }
        .animate-fade-up-2 { animation-delay: 0.12s; }
        .animate-fade-up-3 { animation-delay: 0.18s; }
        .animate-fade-up-4 { animation-delay: 0.24s; }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 50%, #60a5fa 100%);
            background-size: 200% 200%;
            box-shadow: 0 4px 14px 0 rgba(59, 130, 246, 0.4);
        }
        .btn-primary:hover { background-position: 100% 0; box-shadow: 0 6px 20px 0 rgba(59, 130, 246, 0.5); }
        .input-focus:focus { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
        .glass {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(255, 255, 255, 0.5) inset;
        }
        .auth-card { position: relative; overflow: hidden; }
        .auth-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1d4ed8, #3b82f6, #60a5fa);
        }
        .toggle-password, .toggle-password-conf { cursor: pointer; -webkit-tap-highlight-color: transparent; }
        .link-accent { color: #2563eb; }
        .link-accent:hover { color: #1d4ed8; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-6 auth-bg py-10">
    <!-- Overlay: blue + white -->
    <div class="fixed inset-0 bg-gradient-to-br from-blue-900/75 via-slate-800/65 to-slate-900/80 pointer-events-none" aria-hidden="true"></div>

    <div class="relative w-full max-w-md">
        <div class="glass rounded-2xl auth-card p-8 sm:p-10 animate-fade-up">
            <div class="text-center mb-8 animate-fade-up animate-fade-up-1">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/30 mb-4">
                    <i class="fas fa-user-plus text-xl"></i>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-800 tracking-tight">Student Registration</h1>
                <p class="text-slate-500 mt-1 text-sm">Join IQRA College</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200/80 text-rose-700 text-sm flex items-center gap-3 animate-fade-up animate-fade-up-2" role="alert">
                    <i class="fas fa-circle-exclamation text-rose-500 flex-shrink-0"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200/80 text-emerald-800 text-sm flex items-start gap-3 animate-fade-up animate-fade-up-2" role="status">
                    <i class="fas fa-circle-check text-emerald-500 flex-shrink-0 mt-0.5"></i>
                    <div><?php echo $success; ?></div>
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="POST" action="" class="space-y-4 animate-fade-up animate-fade-up-2" id="registerForm">
                <div>
                    <label for="name" class="block text-sm font-semibold text-slate-700 mb-2">Full Name</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-user text-sm"></i></span>
                        <input type="text" name="name" id="name" required autocomplete="name"
                               class="w-full pl-11 pr-4 py-3.5 rounded-xl border-2 border-slate-200 focus:border-blue-500 focus:outline-none input-focus transition-all text-slate-800 placeholder-slate-400"
                               placeholder="Your full name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                    </div>
                </div>

                <div>
                    <label for="email" class="block text-sm font-semibold text-slate-700 mb-2">Email</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-envelope text-sm"></i></span>
                        <input type="email" name="email" id="email" required autocomplete="email"
                               class="w-full pl-11 pr-4 py-3.5 rounded-xl border-2 border-slate-200 focus:border-blue-500 focus:outline-none input-focus transition-all text-slate-800 placeholder-slate-400"
                               placeholder="you@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-semibold text-slate-700 mb-2">Password</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-lock text-sm"></i></span>
                        <input type="password" name="password" id="password" required autocomplete="new-password"
                               class="w-full pl-11 pr-12 py-3.5 rounded-xl border-2 border-slate-200 focus:border-blue-500 focus:outline-none input-focus transition-all text-slate-800 placeholder-slate-400"
                               placeholder="Min 6 characters">
                        <button type="button" class="toggle-password absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-blue-600 transition-colors" aria-label="Toggle password">
                            <i class="fas fa-eye" id="pwIcon"></i>
                        </button>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">At least 6 characters</p>
                </div>

                <div>
                    <label for="confirm_password" class="block text-sm font-semibold text-slate-700 mb-2">Confirm Password</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fas fa-lock text-sm"></i></span>
                        <input type="password" name="confirm_password" id="confirm_password" required autocomplete="new-password"
                               class="w-full pl-11 pr-12 py-3.5 rounded-xl border-2 border-slate-200 focus:border-blue-500 focus:outline-none input-focus transition-all text-slate-800 placeholder-slate-400"
                               placeholder="Re-enter password">
                        <button type="button" class="toggle-password-conf absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-blue-600 transition-colors" aria-label="Toggle password">
                            <i class="fas fa-eye" id="pwIconConfirm"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full btn-primary text-white font-bold py-3.5 px-6 rounded-xl transition-all duration-300 active:scale-[0.98] animate-fade-up animate-fade-up-4">
                    <i class="fas fa-user-plus mr-2"></i>Create account
                </button>
            </form>
            <?php else: ?>
                <div class="animate-fade-up animate-fade-up-3">
                    <a href="login.php" class="block w-full text-center btn-primary text-white font-bold py-3.5 px-6 rounded-xl transition-all duration-300 active:scale-[0.98]">
                        <i class="fas fa-sign-in-alt mr-2"></i>Go to Login
                    </a>
                </div>
            <?php endif; ?>

            <div class="mt-8 text-center animate-fade-up animate-fade-up-4">
                <p class="text-slate-600 text-sm">
                    Already have an account?
                    <a href="login.php" class="font-semibold link-accent hover:underline transition-colors">Sign in</a>
                </p>
            </div>
        </div>
        <p class="text-center text-white/90 text-sm mt-6 drop-shadow-md">© IQRA College</p>
    </div>

    <script>
(function() {
    var pw = document.getElementById('password');
    var pwConf = document.getElementById('confirm_password');
    var icon = document.getElementById('pwIcon');
    var iconConf = document.getElementById('pwIconConfirm');
    var btn = document.querySelector('.toggle-password');
    var btnConf = document.querySelector('.toggle-password-conf');

    function toggle(p, i) {
        if (!p || !i) return;
        var isHidden = p.type === 'password';
        p.type = isHidden ? 'text' : 'password';
        i.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
    }
    if (btn && pw && icon) btn.addEventListener('click', function(){ toggle(pw, icon); });
    if (btnConf && pwConf && iconConf) btnConf.addEventListener('click', function(){ toggle(pwConf, iconConf); });

    var form = document.getElementById('registerForm');
    if (form) {
        form.addEventListener('submit', function() {
            var submit = form.querySelector('button[type="submit"]');
            if (submit) {
                submit.disabled = true;
                submit.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Creating account...';
            }
        });
    }
})();
    </script>
</body>
</html>
