<?php
/**
 * Reset Password Page
 * Verify code and reset password
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
$success = '';
$token = $_GET['token'] ?? '';
$step = 'verify'; // 'verify' or 'reset'

// Check if token is valid
if (empty($token) && empty($_SESSION['reset_token'])) {
    header('Location: forgot-password.php');
    exit();
}

if (empty($token) && !empty($_SESSION['reset_token'])) {
    $token = $_SESSION['reset_token'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_code'])) {
        // Step 1: Verify code
        $code = sanitize($_POST['code'] ?? '');
        
        if (empty($code)) {
            $error = 'Please enter the verification code';
        } elseif (!isset($_SESSION['reset_code']) || $code !== $_SESSION['reset_code']) {
            $error = 'Invalid verification code. Please try again.';
        } elseif (isset($_SESSION['reset_expires']) && strtotime($_SESSION['reset_expires']) < time()) {
            $error = 'Verification code has expired. Please request a new one.';
            unset($_SESSION['reset_token'], $_SESSION['reset_code'], $_SESSION['reset_email'], $_SESSION['reset_expires']);
            header('Location: forgot-password.php');
            exit();
        } else {
            // Code verified, proceed to reset password
            $step = 'reset';
            $success = 'Verification code confirmed. Please enter your new password.';
        }
    } elseif (isset($_POST['reset_password'])) {
        // Step 2: Reset password
        $newPassword = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($newPassword) || empty($confirmPassword)) {
            $error = 'Please fill in all fields';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match';
        } elseif (strlen($newPassword) < 6) {
            $error = 'Password must be at least 6 characters';
        } elseif (!isset($_SESSION['reset_email'])) {
            $error = 'Session expired. Please start over.';
            header('Location: forgot-password.php');
            exit();
        } else {
            try {
                $pdo = getDBConnection();
                
                // Verify token is still valid
                $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ? AND token = ? AND used = 0 AND expires_at > NOW()");
                $stmt->execute([$_SESSION['reset_email'], $token]);
                $resetRecord = $stmt->fetch();
                
                if (!$resetRecord) {
                    $error = 'Invalid or expired reset link. Please request a new one.';
                    unset($_SESSION['reset_token'], $_SESSION['reset_code'], $_SESSION['reset_email'], $_SESSION['reset_expires']);
                    header('Location: forgot-password.php');
                    exit();
                } else {
                    // Update password
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
                    $stmt->execute([$hashedPassword, $_SESSION['reset_email']]);
                    
                    // Mark token as used
                    $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND token = ?");
                    $stmt->execute([$_SESSION['reset_email'], $token]);
                    
                    // Clear session
                    unset($_SESSION['reset_token'], $_SESSION['reset_code'], $_SESSION['reset_email'], $_SESSION['reset_expires']);
                    
                    $success = 'Password reset successfully! You can now login with your new password.';
                    $step = 'success';
                }
            } catch (PDOException $e) {
                $error = 'An error occurred. Please try again.';
                error_log("Reset password error: " . $e->getMessage());
            }
        }
    }
}

// Background: try local images first
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
    <title>Reset Password - IQRA College</title>
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
        .code-input {
            letter-spacing: 0.5em;
            text-align: center;
            font-size: 1.5rem;
            font-weight: bold;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-6 auth-bg">
    <!-- Overlay -->
    <div class="fixed inset-0 bg-gradient-to-br from-blue-900/75 via-slate-800/65 to-slate-900/80 pointer-events-none" aria-hidden="true"></div>

    <div class="relative w-full max-w-md">
        <div class="glass rounded-2xl auth-card p-8 sm:p-10 animate-fade-up">
            <div class="text-center mb-8 animate-fade-up animate-fade-up-1">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/30 mb-4">
                    <?php if ($step === 'success'): ?>
                        <i class="fas fa-check-circle text-xl"></i>
                    <?php else: ?>
                        <i class="fas fa-shield-alt text-xl"></i>
                    <?php endif; ?>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-800 tracking-tight">
                    <?php if ($step === 'success'): ?>
                        Password Reset
                    <?php elseif ($step === 'reset'): ?>
                        Set New Password
                    <?php else: ?>
                        Verify Code
                    <?php endif; ?>
                </h1>
                <p class="text-slate-500 mt-1 text-sm">
                    <?php if ($step === 'success'): ?>
                        Your password has been reset successfully
                    <?php elseif ($step === 'reset'): ?>
                        Enter your new password below
                    <?php else: ?>
                        Enter the 6-digit code sent to your WhatsApp
                    <?php endif; ?>
                </p>
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

            <?php if ($step === 'verify'): ?>
                <form method="POST" action="" class="space-y-5 animate-fade-up animate-fade-up-2" id="verifyForm">
                    <div>
                        <label for="code" class="block text-sm font-semibold text-slate-700 mb-2">Verification Code</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><i class="fab fa-whatsapp text-sm"></i></span>
                            <input type="text" name="code" id="code" required maxlength="6" pattern="[0-9]{6}"
                                   class="w-full pl-11 pr-4 py-3.5 rounded-xl border-2 border-slate-200 focus:border-blue-500 focus:outline-none input-focus transition-all text-slate-800 placeholder-slate-400 code-input"
                                   placeholder="000000" autocomplete="off">
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Check your WhatsApp for the 6-digit code</p>
                    </div>

                    <button type="submit" name="verify_code" class="w-full btn-primary text-white font-bold py-3.5 px-6 rounded-xl transition-all duration-300 active:scale-[0.98] animate-fade-up animate-fade-up-3">
                        <i class="fas fa-check mr-2"></i>Verify Code
                    </button>
                </form>
            <?php elseif ($step === 'reset'): ?>
                <form method="POST" action="" class="space-y-5 animate-fade-up animate-fade-up-2" id="resetForm">
                    <div>
                        <label for="password" class="block text-sm font-semibold text-slate-700 mb-2">New Password</label>
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

                    <button type="submit" name="reset_password" class="w-full btn-primary text-white font-bold py-3.5 px-6 rounded-xl transition-all duration-300 active:scale-[0.98] animate-fade-up animate-fade-up-3">
                        <i class="fas fa-key mr-2"></i>Reset Password
                    </button>
                </form>
            <?php else: ?>
                <!-- Success step -->
                <div class="animate-fade-up animate-fade-up-2">
                    <a href="login.php" class="block w-full text-center btn-primary text-white font-bold py-3.5 px-6 rounded-xl transition-all duration-300 active:scale-[0.98]">
                        <i class="fas fa-sign-in-alt mr-2"></i>Go to Login
                    </a>
                </div>
            <?php endif; ?>

            <div class="mt-8 text-center animate-fade-up animate-fade-up-3">
                <?php if ($step !== 'success'): ?>
                    <p class="text-slate-600 text-sm">
                        <a href="forgot-password.php" class="font-semibold link-accent hover:underline transition-colors">Request new code</a>
                    </p>
                <?php endif; ?>
                <p class="text-slate-600 text-sm mt-3">
                    <a href="login.php" class="font-semibold link-accent hover:underline transition-colors inline-flex items-center gap-1.5">
                        <i class="fas fa-arrow-left text-xs"></i> Back to Login
                    </a>
                </p>
            </div>
        </div>
        <p class="text-center text-white/90 text-sm mt-6 drop-shadow-md">© IQRA College</p>
    </div>

    <script>
    (function() {
        // Password toggle
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

        // Code input - auto format
        var codeInput = document.getElementById('code');
        if (codeInput) {
            codeInput.addEventListener('input', function(e) {
                e.target.value = e.target.value.replace(/[^0-9]/g, '').slice(0, 6);
            });
        }

        // Form submission
        var verifyForm = document.getElementById('verifyForm');
        var resetForm = document.getElementById('resetForm');
        
        if (verifyForm) {
            verifyForm.addEventListener('submit', function() {
                var submit = verifyForm.querySelector('button[type="submit"]');
                if (submit) {
                    submit.disabled = true;
                    submit.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Verifying...';
                }
            });
        }
        
        if (resetForm) {
            resetForm.addEventListener('submit', function() {
                var submit = resetForm.querySelector('button[type="submit"]');
                if (submit) {
                    submit.disabled = true;
                    submit.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Resetting...';
                }
            });
        }
    })();
    </script>
</body>
</html>

