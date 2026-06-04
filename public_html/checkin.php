<?php
/*
 * فایل: public_html/checkin.php
 * صفحه موبایل ثبت ورود/خروج با QR
 * کارمند این صفحه را پس از اسکن QR می‌بیند
 */
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// ── احراز هویت ─────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI']);
    header('Location: login.php?redirect=' . $redirect);
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userFull = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if (!$userFull) {
    try {
        $st = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id=?");
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        $userFull = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'کاربر';
    } catch (Throwable $e) { $userFull = 'کاربر'; }
}

// ── Helper: IP Subnet Check ──────────────────────────────────
function ipInSubnet(string $ip, string $subnet): bool {
    if (!$subnet) return true; // اگر تنظیم نشده → همه مجاز
    // پشتیبانی از چند subnet با کاما
    foreach (explode(',', $subnet) as $cidr) {
        $cidr = trim($cidr);
        if (!$cidr) continue;
        if (!str_contains($cidr, '/')) {
            if ($ip === $cidr) return true;
            continue;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        $mask = $bits === '' ? -1 : ~((1 << (32 - (int)$bits)) - 1);
        if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) return true;
    }
    return false;
}

function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v !== false) ? $v : $default;
    } catch (Throwable $e) { return $default; }
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$ip     = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip     = trim(explode(',', $ip)[0]);

// ── جدول‌سازی ────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `attendance_qr_tokens` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `token` VARCHAR(64) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at` DATETIME NOT NULL,
        `used_by` INT UNSIGNED DEFAULT NULL,
        `used_at` DATETIME DEFAULT NULL,
        `check_type` ENUM('in','out') DEFAULT NULL,
        `used_ip` VARCHAR(45) DEFAULT NULL,
        UNIQUE KEY `uq_token` (`token`),
        INDEX `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `attendance_checkins` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `check_in_time` DATETIME NULL,
        `check_out_time` DATETIME NULL,
        `check_in_ip` VARCHAR(45) NULL,
        `check_out_ip` VARCHAR(45) NULL,
        `check_in_device` VARCHAR(255) NULL,
        `check_out_device` VARCHAR(255) NULL,
        `ip_valid` TINYINT(1) NOT NULL DEFAULT 0,
        `mock_flag` TINYINT(1) NOT NULL DEFAULT 0,
        `status` ENUM('present','partial','flagged') NOT NULL DEFAULT 'present',
        `work_date` DATE NOT NULL,
        `notes` TEXT NULL,
        `admin_note` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_user_date` (`user_id`, `work_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// ── پردازش POST (ثبت check-in/out) ──────────────────────────
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $token       = trim($_POST['token']      ?? '');
    $deviceId    = trim($_POST['device_id']  ?? '');
    $checkType   = ($_POST['check_type'] ?? '') === 'out' ? 'out' : 'in';

    if (!$token) { echo json_encode(['ok'=>false,'msg'=>'توکن نامعتبر است']); exit; }

    // ── ۱. بررسی token ──────────────────────────────────────
    try {
        $st = $pdo->prepare("SELECT * FROM attendance_qr_tokens WHERE token=? LIMIT 1");
        $st->execute([$token]);
        $qr = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>'خطای پایگاه داده']); exit; }

    if (!$qr) {
        echo json_encode(['ok'=>false,'msg'=>'کیوآر نامعتبر است','code'=>'invalid_token']);
        exit;
    }
    if ($qr['used_by']) {
        echo json_encode(['ok'=>false,'msg'=>'این کیوآر قبلاً استفاده شده است','code'=>'used']);
        exit;
    }
    if (strtotime($qr['expires_at']) < time()) {
        echo json_encode(['ok'=>false,'msg'=>'کیوآر منقضی شده — لطفاً کیوآر جدید اسکن کنید','code'=>'expired']);
        exit;
    }

    // ── ۲. بررسی IP ─────────────────────────────────────────
    $subnet    = getSetting($pdo, 'checkin_office_subnet', '');
    $strict    = getSetting($pdo, 'checkin_subnet_strict', '0') === '1';
    $ipValid   = ipInSubnet($ip, $subnet);
    $mockFlag  = 0;

    if (!$ipValid) {
        if ($strict) {
            echo json_encode(['ok'=>false,'msg'=>'دسترسی از خارج شبکه دفتر مجاز نیست','code'=>'ip_blocked']);
            exit;
        }
        $mockFlag = 1; // flag ولی اجازه بده
    }

    // ── ۳. ثبت در جدول attendance_checkins ─────────────────
    $today = date('Y-m-d');
    $now   = date('Y-m-d H:i:s');
    try {
        // وضعیت فعلی
        $cur = $pdo->prepare("SELECT * FROM attendance_checkins WHERE user_id=? AND work_date=?");
        $cur->execute([$userId, $today]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);

        if ($checkType === 'in') {
            if ($row && $row['check_in_time']) {
                echo json_encode(['ok'=>false,'msg'=>'ورود شما قبلاً ثبت شده است','code'=>'already_in',
                    'check_in_time' => $row['check_in_time']]);
                exit;
            }
            if ($row) {
                $pdo->prepare("UPDATE attendance_checkins SET check_in_time=?,check_in_ip=?,check_in_device=?,ip_valid=?,mock_flag=?,status=?,updated_at=NOW() WHERE id=?")
                    ->execute([$now,$ip,$deviceId,(int)$ipValid,max($mockFlag,(int)$row['mock_flag']),$mockFlag?'flagged':'present',$row['id']]);
            } else {
                $pdo->prepare("INSERT INTO attendance_checkins (user_id,work_date,check_in_time,check_in_ip,check_in_device,ip_valid,mock_flag,status) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$userId,$today,$now,$ip,$deviceId,(int)$ipValid,$mockFlag,$mockFlag?'flagged':'present']);
            }
            $msg = 'ورود شما ثبت شد' . ($mockFlag ? ' ⚠️ از خارج شبکه' : '');
        } else {
            // check-out
            if (!$row || !$row['check_in_time']) {
                echo json_encode(['ok'=>false,'msg'=>'ابتدا باید ورود ثبت کنید','code'=>'no_checkin']);
                exit;
            }
            if ($row['check_out_time']) {
                echo json_encode(['ok'=>false,'msg'=>'خروج شما قبلاً ثبت شده است','code'=>'already_out',
                    'check_out_time' => $row['check_out_time']]);
                exit;
            }
            $pdo->prepare("UPDATE attendance_checkins SET check_out_time=?,check_out_ip=?,check_out_device=?,mock_flag=?,status=? WHERE id=?")
                ->execute([$now,$ip,$deviceId,max($mockFlag,(int)$row['mock_flag']),$mockFlag?'flagged':'present',$row['id']]);
            // مدت کاری
            $secs   = strtotime($now) - strtotime($row['check_in_time']);
            $hours  = floor($secs/3600);
            $mins   = floor(($secs%3600)/60);
            $msg = "خروج ثبت شد — مدت حضور: {$hours}h {$mins}m" . ($mockFlag ? ' ⚠️ از خارج شبکه' : '');
        }

        // ── ۴. مارک token به‌عنوان مصرف‌شده ─────────────────
        $pdo->prepare("UPDATE attendance_qr_tokens SET used_by=?,used_at=NOW(),check_type=?,used_ip=? WHERE token=?")
            ->execute([$userId, $checkType, $ip, $token]);

        echo json_encode(['ok'=>true,'msg'=>$msg,'check_type'=>$checkType,'flagged'=>(bool)$mockFlag]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── وضعیت امروز ─────────────────────────────────────────────
$todayStatus = null;
$token       = trim($_GET['t'] ?? '');
try {
    $st = $pdo->prepare("SELECT * FROM attendance_checkins WHERE user_id=? AND work_date=?");
    $st->execute([$userId, date('Y-m-d')]);
    $todayStatus = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// وضعیت token
$tokenValid   = false;
$tokenExpired = false;
$tokenUsed    = false;
if ($token) {
    try {
        $st = $pdo->prepare("SELECT * FROM attendance_qr_tokens WHERE token=? LIMIT 1");
        $st->execute([$token]);
        $qrRow = $st->fetch(PDO::FETCH_ASSOC);
        if ($qrRow) {
            $tokenUsed    = (bool)$qrRow['used_by'];
            $tokenExpired = strtotime($qrRow['expires_at']) < time();
            $tokenValid   = !$tokenUsed && !$tokenExpired;
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="theme-color" content="#0f172a">
<title>ثبت حضور</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent }
body {
    background: #0f172a;
    color: #f1f5f9;
    font-family: Vazirmatn, Tahoma, sans-serif;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
}
.card {
    background: #1e293b;
    border-radius: 24px;
    padding: 32px 28px;
    width: 100%;
    max-width: 380px;
    text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,.5);
    border: 1px solid #334155;
}
.avatar {
    width: 72px; height: 72px; border-radius: 50%;
    background: linear-gradient(135deg,#4f46e5,#7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem; margin: 0 auto 16px; font-weight: 700;
}
.user-name { font-size: 1.2rem; font-weight: 700; color: #f1f5f9; margin-bottom: 4px }
.user-sub  { font-size: .82rem; color: #64748b; margin-bottom: 24px }

.time-badge {
    background: #0f172a;
    border-radius: 14px;
    padding: 12px 20px;
    margin-bottom: 24px;
    font-size: 2rem;
    font-weight: 800;
    color: #38bdf8;
    letter-spacing: 2px;
    font-variant-numeric: tabular-nums;
}

.status-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 24px;
}
.status-box {
    background: #0f172a;
    border-radius: 12px;
    padding: 12px;
    border: 1px solid #334155;
}
.status-box .lbl { font-size: .7rem; color: #64748b; margin-bottom: 4px }
.status-box .val { font-size: .95rem; font-weight: 700 }
.val-green { color: #34d399 }
.val-amber { color: #f59e0b }
.val-gray  { color: #475569 }

.btn-checkin {
    width: 100%;
    padding: 18px;
    border: none;
    border-radius: 16px;
    font-family: Vazirmatn, Tahoma, sans-serif;
    font-size: 1.1rem;
    font-weight: 800;
    cursor: pointer;
    transition: all .2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}
.btn-in  { background: linear-gradient(135deg,#059669,#10b981); color: #fff }
.btn-out { background: linear-gradient(135deg,#dc2626,#ef4444); color: #fff }
.btn-checkin:active { transform: scale(.97); }
.btn-checkin:disabled { opacity:.5; cursor:not-allowed; transform:none }

.alert {
    border-radius: 12px;
    padding: 14px 18px;
    font-size: .88rem;
    margin-bottom: 20px;
    line-height: 1.6;
}
.alert-red    { background: #450a0a; border: 1px solid #7f1d1d; color: #fca5a5 }
.alert-green  { background: #052e16; border: 1px solid #14532d; color: #86efac }
.alert-amber  { background: #431407; border: 1px solid #7c2d12; color: #fdba74 }
.alert-blue   { background: #0c1a3d; border: 1px solid #1e3a8a; color: #93c5fd }

.result-icon { font-size: 3.5rem; margin-bottom: 12px }
.result-msg  { font-size: 1.1rem; font-weight: 700; margin-bottom: 8px }
.result-sub  { font-size: .82rem; color: #64748b }

.flag-warn {
    background: #431407;
    border: 1px solid #7c2d12;
    color: #fdba74;
    border-radius: 10px;
    padding: 8px 14px;
    font-size: .78rem;
    margin-top: 14px;
    display: none;
}
</style>
</head>
<body>

<div class="card" id="mainCard">
    <div class="avatar"><?php echo mb_substr($userFull, 0, 1) ?></div>
    <div class="user-name"><?php echo htmlspecialchars($userFull) ?></div>
    <div class="user-sub"><?php echo jdate('Y/m/d') ?></div>

    <div class="time-badge" id="liveClock">--:--:--</div>

    <!-- وضعیت امروز -->
    <div class="status-row">
        <div class="status-box">
            <div class="lbl">ورود</div>
            <div class="val <?php echo $todayStatus && $todayStatus['check_in_time'] ? 'val-green' : 'val-gray' ?>">
                <?php
                if ($todayStatus && $todayStatus['check_in_time']) {
                    echo date('H:i', strtotime($todayStatus['check_in_time']));
                } else { echo '—'; }
                ?>
            </div>
        </div>
        <div class="status-box">
            <div class="lbl">خروج</div>
            <div class="val <?php echo $todayStatus && $todayStatus['check_out_time'] ? 'val-amber' : 'val-gray' ?>">
                <?php
                if ($todayStatus && $todayStatus['check_out_time']) {
                    echo date('H:i', strtotime($todayStatus['check_out_time']));
                } else { echo '—'; }
                ?>
            </div>
        </div>
    </div>

    <!-- پیام‌های token -->
    <?php if (!$token): ?>
    <div class="alert alert-amber">برای ثبت حضور، کیوآر کد روی صفحه دفتر را اسکن کنید.</div>
    <?php elseif ($tokenUsed): ?>
    <div class="alert alert-red">⛔ این کیوآر قبلاً استفاده شده است. لطفاً کیوآر جدید اسکن کنید.</div>
    <?php elseif ($tokenExpired): ?>
    <div class="alert alert-red">⏱ کیوآر منقضی شده است. لطفاً کیوآر جدید اسکن کنید.</div>
    <?php elseif (!$tokenValid): ?>
    <div class="alert alert-red">❌ کیوآر نامعتبر است.</div>
    <?php endif; ?>

    <!-- نتیجه AJAX -->
    <div id="resultBox" style="display:none; text-align:center; padding: 12px 0">
        <div class="result-icon" id="resultIcon">✅</div>
        <div class="result-msg" id="resultMsg"></div>
        <div class="result-sub" id="resultSub"></div>
    </div>

    <!-- دکمه عمل -->
    <?php if ($tokenValid): ?>
    <div id="actionArea">
        <?php
        $showIn  = !($todayStatus && $todayStatus['check_in_time']);
        $showOut = ($todayStatus && $todayStatus['check_in_time'] && !$todayStatus['check_out_time']);
        ?>
        <?php if ($showIn): ?>
        <button class="btn-checkin btn-in" id="btnCheckIn" onclick="doCheckin('in')">
            <span>↩</span> ثبت ورود
        </button>
        <?php elseif ($showOut): ?>
        <button class="btn-checkin btn-out" id="btnCheckOut" onclick="doCheckin('out')">
            <span>↪</span> ثبت خروج
        </button>
        <?php else: ?>
        <div class="alert alert-blue">✅ ورود و خروج امروز شما ثبت شده است.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="flag-warn" id="flagWarn">⚠️ اتصال از خارج شبکه دفتر شناسایی شد. رکورد شما علامت‌گذاری خواهد شد.</div>
</div>

<script>
// ── ساعت زنده ─────────────────────────────────────────────
function tick() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2,'0');
    const m = String(now.getMinutes()).padStart(2,'0');
    const s = String(now.getSeconds()).padStart(2,'0');
    document.getElementById('liveClock').textContent = h+':'+m+':'+s;
}
setInterval(tick, 1000); tick();

// ── Device Fingerprint ────────────────────────────────────
function getDeviceId() {
    let id = localStorage.getItem('atena_device_id');
    if (!id) {
        // ساخت fingerprint از مشخصات مرورگر
        const raw = [
            navigator.userAgent,
            screen.width + 'x' + screen.height,
            Intl.DateTimeFormat().resolvedOptions().timeZone,
            navigator.language,
            navigator.hardwareConcurrency || 0,
        ].join('|');
        // hash ساده
        let h = 0;
        for (let i = 0; i < raw.length; i++) {
            h = (Math.imul(31, h) + raw.charCodeAt(i)) | 0;
        }
        id = Math.abs(h).toString(16).padStart(8,'0') + '-' + Date.now().toString(16);
        localStorage.setItem('atena_device_id', id);
    }
    return id;
}

// ── ثبت check-in/out ─────────────────────────────────────
const TOKEN = '<?php echo htmlspecialchars($token, ENT_QUOTES) ?>';

function doCheckin(type) {
    const btn = document.getElementById(type === 'in' ? 'btnCheckIn' : 'btnCheckOut');
    if (btn) btn.disabled = true;

    const fd = new FormData();
    fd.append('token',      TOKEN);
    fd.append('device_id',  getDeviceId());
    fd.append('check_type', type);

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        document.getElementById('actionArea').style.display = 'none';
        document.getElementById('resultBox').style.display  = '';

        if (res.ok) {
            document.getElementById('resultIcon').textContent = type === 'in' ? '✅' : '🏁';
            document.getElementById('resultMsg').textContent  = res.msg;
            document.getElementById('resultSub').textContent  = new Date().toLocaleTimeString('fa-IR');
            if (res.flagged) {
                document.getElementById('flagWarn').style.display = '';
            }
        } else {
            document.getElementById('resultIcon').textContent = '❌';
            document.getElementById('resultMsg').textContent  = res.msg;
            document.getElementById('resultSub').textContent  = 'کد: ' + (res.code || 'unknown');
        }
    })
    .catch(() => {
        if (btn) btn.disabled = false;
        alert('خطای شبکه — دوباره تلاش کنید');
    });
}
</script>
</body>
</html>
