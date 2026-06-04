<?php
/*
 * فایل: public_html/customer/index.php
 * توضیحات: پورتال مشتریان — ورود با OTP و مشاهده فاکتورها
 */

ob_start();

$basePath = '../../';

require_once __DIR__ . '/../../Config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ---- ارسال SMS (تعریف محلی اگر وجود نداشت) ----
if (!function_exists('sendFreeSms')) {
    function sendFreeSms(string $mobile, string $message): bool {
        $config  = require __DIR__ . '/../../Config/config.php';
        $apiKey  = $config['sms_api_key'] ?? '';
        $from    = $config['sms_from_number'] ?? '';
        if (!$apiKey) return false;
        if (substr($mobile, 0, 1) === '0') $mobile = '+98' . substr($mobile, 1);
        if (!preg_match('/^\+?\d{10,14}$/', $mobile)) return false;
        $url  = 'https://api2.ippanel.com/api/v1/sms/send/webservice/single';
        $body = json_encode(['sender' => $from, 'recipient' => $mobile, 'message' => $message]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300);
    }
}

// ---- ایجاد جدول session های پورتال ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `customer_portal_sessions` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `person_id`   INT NOT NULL,
        `token`       VARCHAR(64) NOT NULL,
        `phone`       VARCHAR(20) NOT NULL,
        `otp`         VARCHAR(6) DEFAULT NULL,
        `otp_expires` DATETIME DEFAULT NULL,
        `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
        `last_used`   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_active`   TINYINT(1) DEFAULT 1,
        UNIQUE KEY `uq_token` (`token`),
        KEY `idx_phone` (`phone`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ---- بررسی سشن/کوکی ----
$portalPerson = null;
$authToken    = $_COOKIE['cp_token'] ?? '';

if ($authToken) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, p.name AS person_name, p.mobile, p.email
            FROM customer_portal_sessions s
            JOIN fin_persons p ON p.id = s.person_id
            WHERE s.token = ? AND s.is_active = 1
        ");
        $stmt->execute([$authToken]);
        $portalPerson = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($portalPerson) {
            $pdo->prepare("UPDATE customer_portal_sessions SET last_used=NOW() WHERE token=?")->execute([$authToken]);
        }
    } catch (Throwable $e) { $portalPerson = null; }
}

// ===================================================================
// پردازش فرم‌ها
// ===================================================================
$message = '';
$messageType = '';
$step = 'phone'; // phone | otp | dashboard

if ($portalPerson) {
    $step = 'dashboard';
}

// ---- ارسال OTP ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_otp']) && !$portalPerson) {
    $phone = trim(faToEn($_POST['phone'] ?? ''));
    // نرمال‌سازی شماره
    if (substr($phone, 0, 2) === '98') $phone = '0' . substr($phone, 2);
    if (substr($phone, 0, 3) === '+98') $phone = '0' . substr($phone, 3);

    if (!preg_match('/^09\d{9}$/', $phone)) {
        $message = 'شماره موبایل معتبر نیست (مثال: 09123456789)';
        $messageType = 'error';
        $step = 'phone';
    } else {
        // جستجوی طرف حساب با این شماره
        try {
            $stmt = $pdo->prepare("SELECT id, name, mobile FROM fin_persons WHERE (mobile=? OR mobile2=?) AND is_deleted=0 LIMIT 1");
            $stmt->execute([$phone, $phone]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $found = null; }

        if (!$found) {
            // جستجو در جدول customers نیز
            try {
                $stmt2 = $pdo->prepare("SELECT fp.id, fp.name, fp.mobile FROM fin_persons fp
                    INNER JOIN customers c ON (c.phone=? OR c.phone2=?)
                    WHERE fp.is_deleted=0 LIMIT 1");
                $stmt2->execute([$phone, $phone]);
                $found = $stmt2->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $found = null; }
        }

        if (!$found) {
            $message = 'این شماره در سیستم ثبت نشده است. لطفاً با پشتیبانی تماس بگیرید.';
            $messageType = 'error';
            $step = 'phone';
        } else {
            // تولید OTP امن
            $otp = str_pad(random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
            $expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            // ذخیره OTP در جدول (upsert)
            try {
                $pdo->prepare("DELETE FROM customer_portal_sessions WHERE phone=? AND is_active=0")->execute([$phone]);
                $existStmt = $pdo->prepare("SELECT id FROM customer_portal_sessions WHERE phone=? AND is_active=1 LIMIT 1");
                $existStmt->execute([$phone]);
                $existId = $existStmt->fetchColumn();
                if ($existId) {
                    $pdo->prepare("UPDATE customer_portal_sessions SET otp=?, otp_expires=? WHERE id=?")->execute([$otp, $expires, $existId]);
                } else {
                    $token = bin2hex(random_bytes(32));
                    $pdo->prepare("INSERT INTO customer_portal_sessions (person_id, token, phone, otp, otp_expires, is_active) VALUES (?,?,?,?,?,0)")
                        ->execute([$found['id'], $token, $phone, $otp, $expires]);
                }
            } catch (Throwable $e) {}

            // ارسال SMS
            $smsMsg = "کد تأیید پورتال مشتریان آتنا زیست درمان:\n$otp\nاین کد ۵ دقیقه اعتبار دارد.";
            sendFreeSms($phone, $smsMsg);

            $_SESSION['portal_phone'] = $phone;
            $message = 'کد تأیید به شماره ' . $phone . ' ارسال شد';
            $messageType = 'success';
            $step = 'otp';
        }
    }
}

// ---- تأیید OTP ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp']) && !$portalPerson) {
    $phone = $_SESSION['portal_phone'] ?? '';
    $otp   = trim(faToEn($_POST['otp'] ?? ''));

    if (!$phone || !$otp) {
        $message = 'اطلاعات ناقص است';
        $messageType = 'error';
        $step = 'phone';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM customer_portal_sessions WHERE phone=? AND otp=? AND otp_expires > NOW() LIMIT 1");
            $stmt->execute([$phone, $otp]);
            $sess = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $sess = null; }

        if (!$sess) {
            $message = 'کد تأیید اشتباه یا منقضی شده است';
            $messageType = 'error';
            $step = 'otp';
        } else {
            // فعال‌سازی سشن
            $newToken = bin2hex(random_bytes(32));
            try {
                $pdo->prepare("UPDATE customer_portal_sessions SET token=?, otp=NULL, otp_expires=NULL, is_active=1, last_used=NOW() WHERE id=?")
                    ->execute([$newToken, $sess['id']]);
            } catch (Throwable $e) {}
            setcookie('cp_token', $newToken, time() + 86400 * 30, '/', '', false, true);
            unset($_SESSION['portal_phone']);
            header('Location: index.php');
            exit;
        }
    }
}

// ---- ثبت تیکت جدید ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_ticket' && $portalPerson) {
    $personId   = (int)$portalPerson['person_id'];
    $desc       = trim($_POST['description'] ?? '');
    if ($desc) {
        try {
            // ایجاد جدول support_tickets در صورت نبودن
            $pdo->exec("CREATE TABLE IF NOT EXISTS `support_tickets` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `ticket_number` VARCHAR(30) NOT NULL,
                `person_id`     INT NOT NULL,
                `customer_name` VARCHAR(200) DEFAULT NULL,
                `subject`       VARCHAR(300) DEFAULT 'درخواست پشتیبانی',
                `description`   TEXT,
                `priority`      ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
                `status`        ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
                `created_by`    INT DEFAULT NULL,
                `is_deleted`    TINYINT(1) NOT NULL DEFAULT 0,
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_person` (`person_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // شماره تیکت: TKT-YYYYMM-XXXX
            $ym   = date('Ym');
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE ticket_number LIKE ?");
            $cntStmt->execute(["TKT-$ym-%"]);
            $seq  = (int)$cntStmt->fetchColumn() + 1;
            $ticketNumber = 'TKT-' . $ym . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO support_tickets (ticket_number, person_id, customer_name, subject, description, created_by, status) VALUES (?,?,?,?,?,?,?)")
                ->execute([$ticketNumber, $personId, $portalPerson['person_name'], 'درخواست پشتیبانی', $desc, $personId, 'open']);
            $message = 'تیکت پشتیبانی ' . $ticketNumber . ' با موفقیت ثبت شد';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'خطا در ثبت تیکت';
            $messageType = 'error';
        }
    } else {
        $message = 'توضیحات تیکت الزامی است';
        $messageType = 'error';
    }
}

// ---- بارگذاری داده داشبورد ----
$invoices       = [];
$supportTickets = [];
$statOpen = 0;
$statDebt = 0;
$statLastBuy = '—';

if ($step === 'dashboard' && $portalPerson) {
    $personId = (int)$portalPerson['person_id'];
    try {
        // آخرین ۱۰ فاکتور فروش
        $stmt = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount, i.status
            FROM fin_invoices i
            WHERE i.person_id = ? AND i.invoice_type = 'sell' AND i.is_deleted = 0
            ORDER BY i.invoice_date DESC
            LIMIT 10
        ");
        $stmt->execute([$personId]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // آمار
        $stmtStat = $pdo->prepare("
            SELECT
                COUNT(CASE WHEN status NOT IN ('paid','cancelled') THEN 1 END) AS open_count,
                SUM(CASE WHEN status NOT IN ('paid','cancelled') THEN (total_amount - COALESCE(paid_amount,0)) ELSE 0 END) AS total_debt,
                MAX(invoice_date) AS last_buy
            FROM fin_invoices
            WHERE person_id=? AND invoice_type='sell' AND is_deleted=0
        ");
        $stmtStat->execute([$personId]);
        $stats = $stmtStat->fetch(PDO::FETCH_ASSOC);
        $statOpen    = (int)($stats['open_count'] ?? 0);
        $statDebt    = (float)($stats['total_debt'] ?? 0);
        $statLastBuy = $stats['last_buy'] ? jdate('Y/m/d', strtotime($stats['last_buy'])) : '—';
    } catch (Throwable $e) {}

    // بارگذاری تیکت‌های پشتیبانی
    try {
        $stmtT = $pdo->prepare("SELECT id, ticket_number, subject, status, created_at FROM support_tickets WHERE person_id = ? AND is_deleted=0 ORDER BY id DESC LIMIT 10");
        $stmtT->execute([$personId]);
        $supportTickets = $stmtT->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $supportTickets = []; }
}

// ---- نام شرکت ----
$companyName = 'آتنا زیست درمان';
try {
    $cn = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='company_name' LIMIT 1")->fetchColumn();
    if ($cn) $companyName = $cn;
} catch (Throwable $e) {}

ob_end_clean();

// ===================================================================
// خروجی HTML
// ===================================================================
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>پورتال مشتریان — <?= htmlspecialchars($companyName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --primary: #2563eb;
    --primary-light: #eff6ff;
    --success: #16a34a;
    --warning: #d97706;
    --danger: #dc2626;
    --text: #1e293b;
    --text-muted: #64748b;
    --border: #e2e8f0;
    --bg: #f8fafc;
    --card: #ffffff;
    --radius: 12px;
}
body {
    font-family: Vazirmatn, Tahoma, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    direction: rtl;
}

/* ---- ناوبار ---- */
.cp-navbar {
    background: var(--card);
    border-bottom: 1px solid var(--border);
    padding: 0 24px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.cp-navbar .logo {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: 15px;
    color: var(--primary);
    text-decoration: none;
}
.cp-navbar .logo-icon {
    width: 34px;
    height: 34px;
    background: var(--primary);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 16px;
}
.cp-navbar .nav-user {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    color: var(--text-muted);
}
.cp-navbar .logout-btn {
    padding: 6px 14px;
    background: #fff1f2;
    color: var(--danger);
    border: 1px solid #fecdd3;
    border-radius: 8px;
    font-size: 12px;
    cursor: pointer;
    text-decoration: none;
    font-family: inherit;
    transition: background .15s;
}
.cp-navbar .logout-btn:hover { background: #ffe4e6; }

/* ---- ورود ---- */
.login-wrap {
    min-height: calc(100vh - 60px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
    background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%);
}
.login-card {
    background: var(--card);
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(37,99,235,.1);
    padding: 40px;
    width: 100%;
    max-width: 420px;
}
.login-logo {
    text-align: center;
    margin-bottom: 28px;
}
.login-logo .login-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, var(--primary) 0%, #1d4ed8 100%);
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    color: #fff;
    margin-bottom: 12px;
    box-shadow: 0 8px 20px rgba(37,99,235,.3);
}
.login-logo h2 { font-size: 18px; font-weight: 700; color: var(--text); }
.login-logo p  { font-size: 13px; color: var(--text-muted); margin-top: 4px; }

.cp-label { display: block; font-size: 13px; font-weight: 500; color: var(--text); margin-bottom: 6px; }
.cp-input {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    font-size: 15px;
    font-family: inherit;
    color: var(--text);
    background: var(--card);
    direction: ltr;
    text-align: center;
    letter-spacing: 2px;
    transition: border-color .2s;
    outline: none;
}
.cp-input.ltr-phone { direction: ltr; text-align: left; letter-spacing: normal; }
.cp-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.cp-btn {
    width: 100%;
    padding: 12px;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: background .15s, transform .1s;
    margin-top: 20px;
}
.cp-btn:hover  { background: #1d4ed8; }
.cp-btn:active { transform: scale(.98); }
.cp-btn-outline {
    width: 100%;
    padding: 10px;
    background: transparent;
    color: var(--text-muted);
    border: 1.5px solid var(--border);
    border-radius: 10px;
    font-size: 13px;
    font-family: inherit;
    cursor: pointer;
    margin-top: 10px;
    transition: border-color .2s;
}
.cp-btn-outline:hover { border-color: var(--primary); color: var(--primary); }

.cp-alert {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.cp-alert.success { background: #f0fdf4; color: var(--success); border: 1px solid #bbf7d0; }
.cp-alert.error   { background: #fff1f2; color: var(--danger);  border: 1px solid #fecdd3; }

.otp-hint { text-align: center; font-size: 12px; color: var(--text-muted); margin-top: 10px; }

/* ---- داشبورد ---- */
.cp-main { max-width: 1000px; margin: 0 auto; padding: 28px 20px; }
.cp-welcome {
    background: linear-gradient(135deg, var(--primary) 0%, #1d4ed8 100%);
    border-radius: var(--radius);
    padding: 24px 28px;
    color: #fff;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.cp-welcome::before {
    content: '';
    position: absolute;
    top: -40px; left: -40px;
    width: 150px; height: 150px;
    background: rgba(255,255,255,.08);
    border-radius: 50%;
}
.cp-welcome h2 { font-size: 20px; font-weight: 700; position: relative; }
.cp-welcome p  { font-size: 13px; opacity: .8; margin-top: 4px; position: relative; }

.cp-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
.cp-stat {
    background: var(--card);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--border);
    text-align: center;
}
.cp-stat-icon { font-size: 22px; margin-bottom: 8px; }
.cp-stat-num  { font-size: 20px; font-weight: 700; color: var(--text); }
.cp-stat-label { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.cp-stat.blue   .cp-stat-icon { color: var(--primary); }
.cp-stat.green  .cp-stat-icon { color: var(--success); }
.cp-stat.amber  .cp-stat-icon { color: var(--warning); }

.cp-section { background: var(--card); border-radius: var(--radius); border: 1px solid var(--border); margin-bottom: 20px; overflow: hidden; }
.cp-section-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    font-size: 14px;
}
.cp-section-header i { color: var(--primary); }

.cp-table { width: 100%; border-collapse: collapse; }
.cp-table th {
    background: #f8fafc;
    padding: 10px 14px;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-align: right;
    border-bottom: 1px solid var(--border);
}
.cp-table td {
    padding: 12px 14px;
    font-size: 13px;
    border-bottom: 1px solid #f1f5f9;
    color: var(--text);
}
.cp-table tr:last-child td { border-bottom: none; }
.cp-table tr:hover td { background: #fafbff; }

.cp-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.badge-paid       { background: #f0fdf4; color: var(--success); border: 1px solid #bbf7d0; }
.badge-draft      { background: #f8fafc; color: var(--text-muted); border: 1px solid var(--border); }
.badge-confirmed  { background: #eff6ff; color: var(--primary); border: 1px solid #bfdbfe; }
.badge-cancelled  { background: #fff1f2; color: var(--danger); border: 1px solid #fecdd3; }
.badge-partial    { background: #fffbeb; color: var(--warning); border: 1px solid #fde68a; }

.empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
.empty-state i { font-size: 32px; margin-bottom: 12px; opacity: .4; }
.empty-state p { font-size: 13px; }

.cp-footer { text-align: center; padding: 20px; font-size: 12px; color: var(--text-muted); }

/* ---- بج وضعیت تیکت ---- */
.badge-open        { background: #fff1f2; color: var(--danger);  border: 1px solid #fecdd3; }
.badge-in_progress { background: #fffbeb; color: var(--warning); border: 1px solid #fde68a; }
.badge-resolved    { background: #f0fdf4; color: var(--success); border: 1px solid #bbf7d0; }
.badge-closed      { background: #f8fafc; color: var(--text-muted); border: 1px solid var(--border); }

/* ---- فرم تیکت جدید ---- */
.ticket-form-wrap { padding: 16px 20px; border-top: 1px solid var(--border); }
.ticket-form-wrap textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    font-family: inherit;
    font-size: 13px;
    color: var(--text);
    resize: vertical;
    min-height: 80px;
    outline: none;
    transition: border-color .2s;
}
.ticket-form-wrap textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.08); }
.ticket-submit-btn {
    margin-top: 10px;
    padding: 9px 22px;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: background .15s;
}
.ticket-submit-btn:hover { background: #1d4ed8; }
.new-ticket-toggle {
    margin-right: auto;
    padding: 5px 14px;
    background: var(--primary-light);
    color: var(--primary);
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: background .15s;
}
.new-ticket-toggle:hover { background: #dbeafe; }

@media (max-width: 600px) {
    .cp-table th:nth-child(n+4), .cp-table td:nth-child(n+4) { display: none; }
    .login-card { padding: 28px 20px; }
    .cp-main { padding: 16px 12px; }
}
</style>
</head>
<body>

<!-- ناوبار -->
<nav class="cp-navbar">
  <a href="index.php" class="logo">
    <div class="logo-icon"><i class="fas fa-leaf"></i></div>
    <?= htmlspecialchars($companyName) ?>
  </a>
  <?php if ($step === 'dashboard' && $portalPerson): ?>
  <div class="nav-user">
    <i class="fas fa-user-circle" style="font-size:18px;color:var(--primary)"></i>
    <span><?= htmlspecialchars($portalPerson['person_name'] ?? '') ?></span>
    <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> خروج</a>
  </div>
  <?php else: ?>
  <div class="nav-user">
    <i class="fas fa-lock" style="font-size:15px"></i>
    <span>پورتال اختصاصی مشتریان</span>
  </div>
  <?php endif; ?>
</nav>

<?php if ($step === 'dashboard' && $portalPerson): ?>
<!-- ======================== داشبورد ======================== -->
<div class="cp-main">

  <!-- پیام سیستمی -->
  <?php if ($message && (!isset($_POST['action']) || $_POST['action'] !== 'new_ticket')): ?>
  <div class="cp-alert <?= $messageType ?>" style="margin-bottom:16px">
    <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
    <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <!-- خوش‌آمد -->
  <div class="cp-welcome">
    <h2>خوش آمدید، <?= htmlspecialchars($portalPerson['person_name'] ?? 'مشتری') ?> عزیز</h2>
    <p>آخرین وضعیت حساب و فاکتورهای شما در زیر نمایش داده شده است.</p>
  </div>

  <!-- کارت‌های آمار -->
  <div class="cp-stats">
    <div class="cp-stat blue">
      <div class="cp-stat-icon"><i class="fas fa-file-invoice"></i></div>
      <div class="cp-stat-num"><?= number_format($statOpen) ?></div>
      <div class="cp-stat-label">فاکتورهای باز</div>
    </div>
    <div class="cp-stat <?= $statDebt > 0 ? 'amber' : 'green' ?>">
      <div class="cp-stat-icon"><i class="fas fa-<?= $statDebt > 0 ? 'exclamation-circle' : 'check-circle' ?>"></i></div>
      <div class="cp-stat-num" style="font-size:14px"><?= number_format($statDebt) ?> ریال</div>
      <div class="cp-stat-label">مبلغ بدهی</div>
    </div>
    <div class="cp-stat blue">
      <div class="cp-stat-icon"><i class="fas fa-calendar-check"></i></div>
      <div class="cp-stat-num" style="font-size:15px"><?= $statLastBuy ?></div>
      <div class="cp-stat-label">آخرین خرید</div>
    </div>
  </div>

  <!-- جدول فاکتورها -->
  <div class="cp-section">
    <div class="cp-section-header">
      <i class="fas fa-file-invoice-dollar"></i>
      آخرین فاکتورهای فروش
    </div>
    <div style="overflow-x:auto">
      <?php if ($invoices): ?>
      <table class="cp-table">
        <thead>
          <tr>
            <th>شماره فاکتور</th>
            <th>تاریخ</th>
            <th>مبلغ کل (ریال)</th>
            <th>پرداخت‌شده (ریال)</th>
            <th>وضعیت</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv): ?>
          <?php
              $statusLabel = match($inv['status'] ?? '') {
                  'paid'      => ['پرداخت‌شده','badge-paid'],
                  'confirmed' => ['تأیید شده', 'badge-confirmed'],
                  'draft'     => ['پیش‌نویس',  'badge-draft'],
                  'cancelled' => ['لغو شده',   'badge-cancelled'],
                  default     => ['جزئی',       'badge-partial']
              };
              $paid   = (float)($inv['paid_amount'] ?? 0);
              $total  = (float)($inv['total_amount'] ?? 0);
              if ($total > 0 && $paid > 0 && $paid < $total && $inv['status'] !== 'paid') {
                  $statusLabel = ['جزئی — ' . number_format($paid), 'badge-partial'];
              }
          ?>
          <tr>
            <td style="font-weight:600;direction:ltr;text-align:right"><?= htmlspecialchars($inv['invoice_number'] ?? $inv['id']) ?></td>
            <td><?= $inv['invoice_date'] ? jdate('Y/m/d', strtotime($inv['invoice_date'])) : '—' ?></td>
            <td style="direction:ltr;text-align:right"><?= number_format($total) ?></td>
            <td style="direction:ltr;text-align:right"><?= number_format($paid) ?></td>
            <td><span class="cp-badge <?= $statusLabel[1] ?>"><?= $statusLabel[0] ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <i class="fas fa-file-invoice"></i>
        <p>هیچ فاکتوری ثبت نشده است</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- تیکت‌های پشتیبانی -->
  <div class="cp-section">
    <div class="cp-section-header" style="justify-content:space-between">
      <span><i class="fas fa-ticket-alt"></i> تیکت‌های پشتیبانی</span>
      <button class="new-ticket-toggle" onclick="document.getElementById('newTicketForm').style.display=document.getElementById('newTicketForm').style.display==='none'?'block':'none'">
        + تیکت جدید
      </button>
    </div>

    <!-- فرم تیکت جدید (پنهان) -->
    <div id="newTicketForm" style="display:none">
      <div class="ticket-form-wrap">
        <?php if ($message && isset($_POST['action']) && $_POST['action'] === 'new_ticket'): ?>
        <div class="cp-alert <?= $messageType ?>" style="margin-bottom:12px">
          <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
          <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="action" value="new_ticket">
          <label class="cp-label" for="ticketDesc">شرح مشکل یا درخواست</label>
          <textarea id="ticketDesc" name="description" placeholder="مشکل یا درخواست خود را اینجا بنویسید..." required></textarea>
          <button type="submit" class="ticket-submit-btn">
            <i class="fas fa-paper-plane"></i> ارسال تیکت
          </button>
        </form>
      </div>
    </div>

    <div style="overflow-x:auto">
      <?php if ($supportTickets): ?>
      <table class="cp-table">
        <thead>
          <tr>
            <th>شماره</th>
            <th>موضوع</th>
            <th>وضعیت</th>
            <th>تاریخ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($supportTickets as $tkt): ?>
          <?php
              $tktStatus = match($tkt['status'] ?? 'open') {
                  'open'        => ['باز',          'badge-open'],
                  'in_progress' => ['در حال بررسی', 'badge-in_progress'],
                  'resolved'    => ['حل‌شده',        'badge-resolved'],
                  'closed'      => ['بسته',          'badge-closed'],
                  default       => ['باز',           'badge-open'],
              };
          ?>
          <tr>
            <td style="font-weight:600;direction:ltr;text-align:right"><?= htmlspecialchars($tkt['ticket_number'] ?? '#' . $tkt['id']) ?></td>
            <td><?= htmlspecialchars($tkt['subject'] ?? '') ?></td>
            <td><span class="cp-badge <?= $tktStatus[1] ?>"><?= $tktStatus[0] ?></span></td>
            <td><?= $tkt['created_at'] ? jdate('Y/m/d', strtotime($tkt['created_at'])) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <i class="fas fa-ticket-alt"></i>
        <p>هیچ تیکت پشتیبانی‌ای ثبت نشده است</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- راهنمای تماس -->
  <div class="cp-section">
    <div class="cp-section-header">
      <i class="fas fa-headset"></i>
      پشتیبانی و تماس
    </div>
    <div style="padding:20px;font-size:13px;color:var(--text-muted);line-height:2">
      <p><i class="fas fa-phone" style="color:var(--primary);margin-left:8px"></i> برای استعلام یا اعتراض با واحد مالی تماس بگیرید.</p>
      <p><i class="fas fa-envelope" style="color:var(--primary);margin-left:8px"></i> اطلاعات تماس از طریق نماینده فروش شما قابل دسترسی است.</p>
    </div>
  </div>

  <div class="cp-footer">
    © <?= jdate('Y') ?> <?= htmlspecialchars($companyName) ?> — تمام حقوق محفوظ است
  </div>
</div>

<?php elseif ($step === 'otp'): ?>
<!-- ======================== فرم OTP ======================== -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <div class="login-icon"><i class="fas fa-sms"></i></div>
      <h2>کد تأیید را وارد کنید</h2>
      <p>کد ۵ رقمی به شماره <?= htmlspecialchars($_SESSION['portal_phone'] ?? '') ?> ارسال شد</p>
    </div>
    <?php if ($message): ?>
    <div class="cp-alert <?= $messageType ?>">
      <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
      <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    <form method="POST">
      <label class="cp-label" for="otp">کد تأیید</label>
      <input type="text" id="otp" name="otp" class="cp-input" maxlength="5" placeholder="- - - - -" autocomplete="one-time-code" inputmode="numeric" autofocus>
      <p class="otp-hint"><i class="fas fa-clock"></i> این کد ۵ دقیقه اعتبار دارد</p>
      <button type="submit" name="verify_otp" class="cp-btn">
        <i class="fas fa-check"></i> تأیید و ورود
      </button>
      <button type="submit" name="send_otp" value="1" class="cp-btn-outline" formaction="index.php" onclick="document.querySelector('[name=phone]') || (this.closest('form').innerHTML += '<input name=phone value=\'<?= htmlspecialchars($_SESSION['portal_phone'] ?? '') ?>\')">
        <i class="fas fa-redo"></i> ارسال مجدد کد
      </button>
    </form>
    <p style="text-align:center;font-size:12px;color:var(--text-muted);margin-top:16px">
      <a href="index.php" style="color:var(--primary);text-decoration:none">← تغییر شماره موبایل</a>
    </p>
  </div>
</div>

<?php else: ?>
<!-- ======================== فرم ورود ======================== -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <div class="login-icon"><i class="fas fa-leaf"></i></div>
      <h2>پورتال مشتریان</h2>
      <p><?= htmlspecialchars($companyName) ?></p>
    </div>
    <?php if ($message): ?>
    <div class="cp-alert <?= $messageType ?>">
      <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
      <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    <form method="POST">
      <div style="margin-bottom:8px">
        <label class="cp-label" for="phone">شماره موبایل</label>
        <input type="tel" id="phone" name="phone" class="cp-input ltr-phone"
               placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" autofocus>
      </div>
      <p style="font-size:12px;color:var(--text-muted);margin-top:6px">
        <i class="fas fa-info-circle" style="color:var(--primary)"></i>
        شماره موبایل ثبت‌شده در سیستم را وارد کنید. کد تأیید ارسال خواهد شد.
      </p>
      <button type="submit" name="send_otp" value="1" class="cp-btn">
        <i class="fas fa-paper-plane"></i> ارسال کد تأیید
      </button>
    </form>
    <hr style="border:none;border-top:1px solid var(--border);margin:24px 0">
    <p style="text-align:center;font-size:12px;color:var(--text-muted)">
      در صورت عدم دسترسی با نماینده فروش خود تماس بگیرید.
    </p>
  </div>
</div>
<?php endif; ?>

</body>
</html>
