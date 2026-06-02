<?php
/* فایل: public_html/login.php */
ob_start();
session_start();

// فراخوانی دیتابیس و توابع
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// اگر کاربر قبلاً لاگین کرده، مستقیم به داشبورد برود
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error_message = null;

// جلوگیری از حملات Brute Force (قفل شدن بعد از 5 تلاش ناموفق)
if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 5) {
    $lockout_duration = 300; // 5 دقیقه
    $time_since_last_attempt = time() - $_SESSION['last_attempt_time'];
    
    if ($time_since_last_attempt < $lockout_duration) {
        $remaining_minutes = ceil(($lockout_duration - $time_since_last_attempt) / 60);
        $error_message = "تعداد تلاش‌های ناموفق زیاد بوده است. لطفاً $remaining_minutes دقیقه دیگر تلاش کنید.";
    } else {
        // ریست کردن قفل بعد از گذشت زمان
        unset($_SESSION['login_attempts'], $_SESSION['last_attempt_time']);
    }
}

// تولید توکن CSRF برای امنیت فرم
if (function_exists('csrf_token')) {
    csrf_token();
} else if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// پردازش فرم ورود
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error_message) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error_message = "نشست نامعتبر است. لطفاً صفحه را رفرش کنید.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username && $password) {
            
            // ========================================================
            // مسیر ورود سریع (برای دوران توسعه پروژه)
            // ========================================================
            if ($username === 'admin' && $password === 'admin') {
                // واکشی اطلاعات ادمین از دیتابیس تا داشبورد به درستی کار کند
                $stmtAdmin = $pdo->prepare("SELECT * FROM users WHERE username = 'admin' LIMIT 1");
                $stmtAdmin->execute();
                $adminUser = $stmtAdmin->fetch();
                
                if ($adminUser) {
                    $_SESSION['user_id'] = $adminUser['id'];
                    $_SESSION['role'] = $adminUser['role'];
                    $_SESSION['username'] = $adminUser['username'];
                    $_SESSION['fullname'] = $adminUser['first_name'] . ' ' . $adminUser['last_name'];
                } else {
                    // مقادیر پیش‌فرض در صورتی که کاربری با نام admin در دیتابیس نباشد
                    $_SESSION['user_id'] = 1;
                    $_SESSION['role'] = 'admin';
                    $_SESSION['username'] = 'admin';
                    $_SESSION['fullname'] = 'مدیر سیستم (تست)';
                }
                
                unset($_SESSION['login_attempts'], $_SESSION['last_attempt_time']);
                header("Location: dashboard.php");
                exit;
            }
            // ========================================================

            try {
                // جستجوی کاربر
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                // بررسی رمز عبور
                if ($user && password_verify($password, $user['password'])) {
                    if ($user['status'] !== 'active') {
                        $error_message = "حساب کاربری شما غیرفعال شده است.";
                    } else {
                        // --- ورود مستقیم با تایید نام کاربری و رمز عبور ---
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['fullname'] = $user['first_name'] . ' ' . $user['last_name'];

                        // موفقیت: پاک کردن لاگ تلاش‌ها و هدایت به داشبورد
                        unset($_SESSION['login_attempts'], $_SESSION['last_attempt_time']);
                        header("Location: dashboard.php");
                        exit;
                    }
                } else {
                    // رمز عبور اشتباه
                    $error_message = "نام کاربری یا رمز عبور اشتباه است.";
                    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                    $_SESSION['last_attempt_time'] = time();
                }
            } catch (PDOException $e) {
                error_log("Login DB Error: " . $e->getMessage());
                $error_message = "خطای سیستمی رخ داده است.";
            }
        } else {
            $error_message = "لطفاً نام کاربری و رمز عبور را وارد کنید.";
        }
    }
}
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ورود | سامانه اتوماسیون آتنا زیست درمان</title>
    <style>
        /* فونت وزیرمتن */
        @font-face {
            font-family: 'Vazirmatn';
            src: url('assets/fonts/Vazirmatn-Regular.ttf') format('truetype');
            font-weight: normal;
        }
        
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --bg: #f3f4f6;
            --card-bg: rgba(255, 255, 255, 0.95);
            --text: #1f2937;
            --muted: #6b7280;
            --border: #d1d5db;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            outline: none;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            position: relative;
            overflow-x: hidden;
        }

        /* پس‌زمینه متحرک */
        .background { position: fixed; inset: 0; z-index: -1; overflow: hidden; }
        .shape { position: absolute; width: 50vw; height: 50vw; max-width: 600px; max-height: 600px; border-radius: 50%; filter: blur(80px); opacity: 0.4; animation: spin 25s linear infinite; }
        .shape.one { background: #6366f1; top: -10%; left: -10%; }
        .shape.two { background: #ec4899; bottom: -10%; right: -10%; animation-direction: reverse; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* کارت لاگین */
        .login-wrapper { width: 100%; display: flex; justify-content: center; z-index: 10; }
        .card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border-radius: 1.5rem;
            padding: 2.5rem 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255,255,255,0.6);
            width: 100%;
            max-width: 400px;
            animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }

        /* برند */
        .brand { text-align: center; margin-bottom: 2rem; }
        .brand img { width: 80px; height: auto; margin-bottom: 1rem; }
        .brand h1 { font-size: 1.2rem; font-weight: 800; color: var(--text); margin-bottom: 0.5rem; }
        .brand p { font-size: 0.85rem; color: var(--muted); }

        /* فرم */
        .group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; margin-bottom: 0.5rem; color: var(--text); font-weight: 500; }
        
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 0.85rem 1rem;
            border: 2px solid var(--border);
            border-radius: 0.75rem;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.8);
            font-family: 'Vazirmatn', sans-serif;
            color: var(--text);
            transition: all 0.2s;
        }
        input:focus {
            border-color: var(--primary);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        /* نمایش رمز */
        .show-pass {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 1.5rem;
            cursor: pointer;
        }
        .show-pass input { cursor: pointer; }

        /* دکمه */
        button {
            width: 100%;
            padding: 0.9rem;
            border: none;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: #fff;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Vazirmatn', sans-serif;
        }
        button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3);
        }
        button:disabled { opacity: 0.7; cursor: not-allowed; }

        /* فوتر و تست */
        .footer { margin-top: 2rem; font-size: 0.75rem; color: var(--muted); text-align: center; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 1rem; }
        
        /* نوتیفیکیشن خطا */
        .toast-container { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; display: flex; flex-direction: column; gap: 10px; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: #ef4444; color: white; padding: 1rem; border-radius: 0.75rem; font-size: 0.9rem; text-align: center; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2); pointer-events: auto; animation: slideUp 0.3s ease; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        /* ریسپانسیو */
        @media (max-width: 480px) {
            body { padding: 15px; align-items: center; }
            .card { padding: 1.5rem; border-radius: 1rem; margin: 0 auto; }
            .brand { margin-bottom: 1rem; }
            .brand h1 { font-size: 1.1rem; }
            .brand img { width: 60px; }
            input[type="text"], input[type="password"] { font-size: 0.9rem; padding: 0.6rem 0.8rem; }
            button { padding: 0.7rem; font-size: 0.95rem; }
        }
    </style>
</head>
<body>
    <div class="background">
        <div class="shape one"></div>
        <div class="shape two"></div>
    </div>

    <div class="login-wrapper">
        <div class="card">
            <div class="brand">
                <img src="assets/images/logo.png" alt="آتنا زیست درمان" onerror="this.style.display='none'">
                <h1>آتنا زیست درمان</h1>
                <p>سامانه جامع مدیریت و اتوماسیون اداری</p>
            </div>

            <form method="post" id="loginForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="group">
                    <label for="username">نام کاربری</label>
                    <input type="text" name="username" id="username" placeholder="نام کاربری خود را وارد کنید">
                </div>

                <div class="group">
                    <label for="password">رمز عبور</label>
                    <input type="password" name="password" id="password" placeholder="••••••••">
                </div>

                <div class="show-pass">
                    <input type="checkbox" id="showPass">
                    <label for="showPass">نمایش رمز عبور</label>
                </div>

                <?php $is_locked = (isset($error_message) && strpos($error_message, 'تلاش کنید') !== false); ?>
                <button type="submit" id="submitBtn" <?php echo $is_locked ? 'disabled' : ''; ?>>
                    <?php echo $is_locked ? 'لطفاً صبر کنید...' : 'ورود به سامانه'; ?>
                </button>
            </form>

            <div class="footer">© <?php echo date("Y"); ?> تمامی حقوق محفوظ است.</div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        // اسکریپت نمایش/مخفی کردن رمز
        const passInput = document.getElementById('password');
        document.getElementById('showPass').addEventListener('change', function() {
            passInput.type = this.checked ? 'text' : 'password';
        });

        // تابع نمایش نوتیفیکیشن
        function showToast(msg) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.textContent = msg;
            container.appendChild(toast);
            
            // حذف خودکار بعد از 4 ثانیه
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // اعتبارسنجی سمت کلاینت (قبل از ارسال)
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const userVal = document.getElementById('username').value.trim();
            const passVal = document.getElementById('password').value.trim();
            
            if (!userVal || !passVal) {
                e.preventDefault();
                showToast('لطفاً نام کاربری و رمز عبور را وارد نمایید.');
            }
        });

        // نمایش خطای PHP (اگر وجود داشته باشد)
        <?php if ($error_message): ?>
            showToast("<?php echo addslashes($error_message); ?>");
        <?php endif; ?>
    </script>
</body>
</html>