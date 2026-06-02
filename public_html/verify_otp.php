<?php
/* فایل: public_html/verify_otp.php */
ob_start();
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// اگر دسترسی موقت وجود ندارد، به لاگین برگرد
if (!isset($_SESSION['otp_temp_user_id']) || !isset($_SESSION['otp_code'])) {
    header("Location: login.php");
    exit;
}

$error_message = null;

// بررسی ارسال فرم کد
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // برای ارسال مجدد کد
    if (isset($_POST['resend'])) {
        if (time() > $_SESSION['otp_expires_at']) {
            $new_code = rand(1000, 9999);
            $_SESSION['otp_code'] = $new_code;
            $_SESSION['otp_expires_at'] = time() + 180;
            send_otp_sms($_SESSION['otp_mobile'], $new_code);
            $success_message = "کد جدید ارسال شد.";
        } else {
            $error_message = "لطفاً تا پایان تایمر صبر کنید.";
        }
    } 
    // برای تایید کد
    else {
        $user_otp = trim($_POST['otp_code'] ?? '');
        
        if (empty($user_otp)) {
            $error_message = "لطفاً کد تایید را وارد کنید.";
        } elseif (time() > $_SESSION['otp_expires_at']) {
            $error_message = "کد منقضی شده است. درخواست ارسال مجدد دهید.";
        } elseif ($user_otp == $_SESSION['otp_code']) {
            // --- کد صحیح است: لاگین نهایی ---
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$_SESSION['otp_temp_user_id']]);
                $user = $stmt->fetch();

                if ($user) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['fullname'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['department_id'] = $user['department_id'] ?? null;

                    // پاک کردن اطلاعات موقت OTP
                    unset($_SESSION['otp_temp_user_id'], $_SESSION['otp_code'], $_SESSION['otp_expires_at'], $_SESSION['otp_mobile']);

                    header("Location: dashboard.php");
                    exit;
                } else {
                    $error_message = "کاربر یافت نشد.";
                }
            } catch (Exception $e) {
                $error_message = "خطای سیستم.";
            }
        } else {
            $error_message = "کد وارد شده اشتباه است.";
        }
    }
}

// محاسبه زمان باقی‌مانده برای تایمر JS
$time_left = max(0, $_SESSION['otp_expires_at'] - time());
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>تایید هویت | آتنا زیست درمان</title>
    <style>
        @font-face { font-family: 'Vazirmatn'; src: url('assets/fonts/Vazirmatn-Regular.ttf') format('truetype'); font-weight: normal; }
        :root { --primary: #4f46e5; --primary-hover: #4338ca; --bg: #f3f4f6; --card-bg: rgba(255, 255, 255, 0.95); --text: #1f2937; --muted: #6b7280; --border: #d1d5db; }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; outline: none; }
        body { font-family: 'Vazirmatn', Tahoma, sans-serif; background: var(--bg); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 15px; position: relative; overflow-x: hidden; }
        .background { position: fixed; inset: 0; z-index: -1; overflow: hidden; }
        .shape { position: absolute; width: 50vw; height: 50vw; max-width: 600px; max-height: 600px; border-radius: 50%; filter: blur(80px); opacity: 0.4; animation: spin 25s linear infinite; }
        .shape.one { background: #6366f1; top: -10%; left: -10%; }
        .shape.two { background: #ec4899; bottom: -10%; right: -10%; animation-direction: reverse; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .card { background: var(--card-bg); backdrop-filter: blur(12px); border-radius: 1.5rem; padding: 2.5rem 2rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid rgba(255,255,255,0.6); width: 100%; max-width: 400px; text-align: center; }
        .brand h1 { font-size: 1.2rem; font-weight: 800; color: var(--text); margin-bottom: 0.5rem; }
        .brand p { font-size: 0.85rem; color: var(--muted); margin-bottom: 2rem; }
        input[type="text"] { width: 100%; padding: 0.85rem; border: 2px solid var(--border); border-radius: 0.75rem; font-size: 1.5rem; text-align: center; letter-spacing: 10px; background: rgba(255, 255, 255, 0.8); color: var(--text); transition: all 0.2s; margin-bottom: 1.5rem; }
        input:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }
        button { width: 100%; padding: 0.9rem; border: none; border-radius: 0.75rem; background: linear-gradient(135deg, var(--primary), var(--primary-hover)); color: #fff; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.3s ease; font-family: 'Vazirmatn', sans-serif; margin-bottom: 1rem; }
        button:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3); }
        .resend-link { font-size: 0.9rem; color: var(--muted); background: none; border: none; cursor: default; text-decoration: none; padding: 0; width: auto; box-shadow: none; display: inline-block; }
        .resend-link.active { color: var(--primary); cursor: pointer; font-weight: bold; }
        .resend-link:hover.active { color: var(--primary-hover); transform: none; background: none; box-shadow: none; }
        .timer { font-weight: bold; color: var(--primary); margin-right: 5px; }
        .back-link { display: block; margin-top: 1rem; font-size: 0.85rem; color: var(--muted); text-decoration: none; }
        .toast-container { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; display: flex; flex-direction: column; gap: 10px; width: 90%; max-width: 350px; pointer-events: none; }
        .toast { background: #ef4444; color: white; padding: 1rem; border-radius: 0.75rem; font-size: 0.9rem; text-align: center; pointer-events: auto; animation: slideUp 0.3s ease; }
        .toast.success { background: #10b981; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <div class="background"><div class="shape one"></div><div class="shape two"></div></div>
    
    <div class="card">
        <div class="brand">
            <h1>تایید هویت</h1>
            <p>کد ۴ رقمی ارسال شده به <?php echo substr($_SESSION['otp_mobile'], -4) . '***' . substr($_SESSION['otp_mobile'], 0, 4); ?> را وارد کنید</p>
        </div>
        
        <form method="post" autocomplete="off">
            <input type="text" name="otp_code" maxlength="4" placeholder="----" autofocus required pattern="\d*">
            <button type="submit">تایید و ورود</button>
        </form>

        <form method="post" id="resendForm">
            <input type="hidden" name="resend" value="1">
            <button type="submit" id="resendBtn" class="resend-link" disabled>
                ارسال مجدد کد تا <span class="timer" id="timer"></span> دیگر
            </button>
        </form>

        <a href="login.php" class="back-link">بازگشت به صفحه ورود</a>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        // تایمر معکوس
        let timeLeft = <?php echo $time_left; ?>;
        const timerElem = document.getElementById('timer');
        const resendBtn = document.getElementById('resendBtn');

        const countdown = setInterval(() => {
            if (timeLeft <= 0) {
                clearInterval(countdown);
                resendBtn.innerHTML = "ارسال مجدد کد";
                resendBtn.disabled = false;
                resendBtn.classList.add('active');
            } else {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerElem.textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
                timeLeft--;
            }
        }, 1000);

        function showToast(msg, type = 'error') {
            const c = document.getElementById('toastContainer');
            const t = document.createElement('div');
            t.className = 'toast ' + (type === 'success' ? 'success' : '');
            t.textContent = msg;
            c.appendChild(t);
            setTimeout(() => { t.style.opacity='0'; setTimeout(() => t.remove(), 300); }, 4000);
        }

        <?php if ($error_message): ?>showToast("<?php echo addslashes($error_message); ?>");<?php endif; ?>
        <?php if (isset($success_message)): ?>showToast("<?php echo addslashes($success_message); ?>", 'success');<?php endif; ?>
    </script>
</body>
</html>