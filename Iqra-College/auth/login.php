<?php
/**
 * Login Page
 * User authentication
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
    } elseif ($role === 'cashier') {
        header('Location: /Iqra-College/cashier/index.php');
    } else {
        header('Location: /Iqra-College/student/index.php');
    }
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields';
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_email'] = $user['email'];

                if ($user['role'] === 'admin') {
                    header('Location: /Iqra-College/admin/index.php');
                } elseif ($user['role'] === 'teacher') {
                    header('Location: /Iqra-College/teacher/index.php');
                } elseif ($user['role'] === 'cashier') {
                    header('Location: /Iqra-College/cashier/index.php');
                } else {
                    header('Location: /Iqra-College/student/index.php');
                }
                exit();
            } else {
                $error = 'Invalid email or password';
            }
        } catch (PDOException $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}

// Background: try local images first (auth-bg.jpg, auth-bg.png, arday.jpg), else Unsplash
$imgDir = __DIR__ . '/../assets/images';
$authBg = 'https://i.pinimg.com/736x/04/02/8a/04028ade7be4053e3958619e085559dd.jpg';
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
    <title>Login - IQRA College</title>
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
        .animate-fade-up-2 { animation-delay: 0.16s; }
        .animate-fade-up-3 { animation-delay: 0.24s; }
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
        .toggle-password { cursor: pointer; -webkit-tap-highlight-color: transparent; }
        .link-accent { color: #2563eb; }
        .link-accent:hover { color: #1d4ed8; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-6 auth-bg">
    <!-- Overlay: blue + white readability -->
    <div class="fixed inset-0 bg-gradient-to-br from-blue-900/75 via-slate-800/65 to-slate-900/80 pointer-events-none" aria-hidden="true"></div>

    <div class="relative w-full max-w-md">
        <div class="glass rounded-2xl auth-card p-8 sm:p-10 animate-fade-up">
            <div class="text-center mb-8 animate-fade-up animate-fade-up-1">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/30 mb-4">
                    <i class="fas fa-graduation-cap text-xl"></i>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-800 tracking-tight">IQRA College</h1>
                <p class="text-slate-500 mt-1 text-sm">Learning Management System</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200/80 text-rose-700 text-sm flex items-center gap-3 animate-fade-up animate-fade-up-2" role="alert">
                    <i class="fas fa-circle-exclamation text-rose-500 flex-shrink-0"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5 animate-fade-up animate-fade-up-2" id="loginForm">
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
                        <input type="password" name="password" id="password" required autocomplete="current-password"
                               class="w-full pl-11 pr-12 py-3.5 rounded-xl border-2 border-slate-200 focus:border-blue-500 focus:outline-none input-focus transition-all text-slate-800 placeholder-slate-400"
                               placeholder="••••••••">
                        <button type="button" class="toggle-password absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-blue-600 transition-colors" aria-label="Toggle password visibility">
                            <i class="fas fa-eye" id="pwIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full btn-primary text-white font-bold py-3.5 px-6 rounded-xl transition-all duration-300 active:scale-[0.98] animate-fade-up animate-fade-up-3">
                    <i class="fas fa-sign-in-alt mr-2"></i>Sign in
                </button>
            </form>

            <div class="mt-6 text-center animate-fade-up animate-fade-up-3">
                <a href="forgot-password.php" class="text-sm font-semibold link-accent hover:underline transition-colors">
                    <i class="fas fa-key mr-1"></i>Forgot Password?
                </a>
            </div>

            <div class="mt-8 text-center animate-fade-up animate-fade-up-3">
                <p class="text-slate-600 text-sm">
                    Don't have an account?
                    <a href="register.php" class="font-semibold link-accent hover:underline transition-colors">Register as Student</a>
                </p>
                <p class="text-slate-600 text-sm mt-3">
                    <a href="../index.php" class="font-semibold link-accent hover:underline transition-colors inline-flex items-center gap-1.5">
                        <i class="fas fa-arrow-left text-xs"></i> Back to Home
                    </a>
                </p>
            </div>
        </div>
        <p class="text-center text-white/90 text-sm mt-6 drop-shadow-md">© IQRA College</p>
    </div>

    <script>
(function() {
    var form = document.getElementById('loginForm');
    var pw = document.getElementById('password');
    var icon = document.getElementById('pwIcon');
    var btn = document.querySelector('.toggle-password');

    if (btn && pw && icon) {
        btn.addEventListener('click', function() {
            var isHidden = pw.type === 'password';
            pw.type = isHidden ? 'text' : 'password';
            icon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    }

    if (form) {
        form.addEventListener('submit', function() {
            var submit = form.querySelector('button[type="submit"]');
            if (submit) {
                submit.disabled = true;
                submit.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Signing in...';
            }
        });
    }
})();
    </script>
</body>
</html>
