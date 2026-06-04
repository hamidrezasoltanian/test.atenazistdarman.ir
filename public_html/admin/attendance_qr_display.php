<?php
/*
 * فایل: public_html/admin/attendance_qr_display.php
 * نمایش QR یک‌بار مصرف روی صفحه دفتر — چرخش هر 90 ثانیه
 * برای نمایش روی تلویزیون/مانیتور دفتر
 */
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php'); exit;
}

// ── جدول‌سازی ──────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `attendance_qr_tokens` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `token`      VARCHAR(64) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at` DATETIME NOT NULL,
        `used_by`    INT UNSIGNED DEFAULT NULL,
        `used_at`    DATETIME DEFAULT NULL,
        `check_type` ENUM('in','out') DEFAULT NULL,
        `used_ip`    VARCHAR(45) DEFAULT NULL,
        UNIQUE KEY `uq_token` (`token`),
        INDEX `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ── تنظیمات ─────────────────────────────────────────────────────
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v !== false) ? $v : $default;
    } catch (Throwable $e) { return $default; }
}
$ttl = max(30, (int)getSetting($pdo, 'checkin_qr_ttl', '90'));

// ── AJAX: تولید token جدید ────────────────────────────────────
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($isAjax && ($_GET['action'] ?? '') === 'new_token') {
    // پاک‌سازی tokenهای منقضی
    try { $pdo->exec("DELETE FROM attendance_qr_tokens WHERE expires_at < NOW() - INTERVAL 10 MINUTE"); } catch (Throwable $e) {}
    // ساخت token جدید
    $token    = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
    try {
        $pdo->prepare("INSERT INTO attendance_qr_tokens (token, expires_at) VALUES (?,?)")->execute([$token, $expiresAt]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        exit;
    }
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST']
             . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/checkin.php';
    header('Content-Type: application/json');
    echo json_encode([
        'ok'         => true,
        'token'      => $token,
        'url'        => $baseUrl . '?t=' . $token,
        'expires_at' => $expiresAt,
        'ttl'        => $ttl,
    ]);
    exit;
}

// ── صفحه اصلی ─────────────────────────────────────────────────
$companyName = getSetting($pdo, 'company_name', 'آتنا زیست درمان');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ثبت حضور — <?php echo htmlspecialchars($companyName) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0 }
body {
    background: #0f172a;
    color: #f1f5f9;
    font-family: Vazirmatn, Tahoma, sans-serif;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    user-select: none;
}
.top-bar {
    position: fixed;
    top: 0; left: 0; right: 0;
    padding: 18px 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(to bottom, rgba(15,23,42,.9), transparent);
    z-index: 10;
}
.company { font-size: 1.1rem; font-weight: 700; color: #94a3b8 }
.live-clock { font-size: 1.6rem; font-weight: 800; color: #38bdf8; letter-spacing: 2px; font-variant-numeric: tabular-nums }
.live-date  { font-size: .85rem; color: #64748b; text-align: left }

.center-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 28px;
}
.instruction {
    font-size: 1.05rem;
    color: #94a3b8;
    text-align: center;
    letter-spacing: .5px;
}
.instruction span { color: #38bdf8; font-weight: 700 }

/* QR Container با حلقه countdown */
.qr-ring-wrap {
    position: relative;
    width: 320px;
    height: 320px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.qr-box {
    background: #fff;
    border-radius: 20px;
    padding: 20px;
    width: 280px;
    height: 280px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 2;
    box-shadow: 0 0 60px rgba(56,189,248,.25);
    transition: opacity .3s;
}
.qr-box.refreshing { opacity: .2; }
.qr-box canvas, .qr-box img { width: 240px !important; height: 240px !important; }

/* حلقه SVG countdown */
.ring-svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
    z-index: 1;
}
.ring-bg   { fill: none; stroke: #1e293b; stroke-width: 8 }
.ring-prog { fill: none; stroke: #38bdf8; stroke-width: 8; stroke-linecap: round; transition: stroke-dashoffset .9s linear, stroke .3s }
.ring-prog.warn  { stroke: #f59e0b }
.ring-prog.urgent{ stroke: #ef4444 }

/* ثانیه شمار */
.countdown-badge {
    position: absolute;
    bottom: -14px;
    left: 50%;
    transform: translateX(-50%);
    background: #1e293b;
    border: 2px solid #334155;
    border-radius: 20px;
    padding: 4px 16px;
    font-size: .85rem;
    font-weight: 700;
    color: #38bdf8;
    white-space: nowrap;
    z-index: 3;
    min-width: 90px;
    text-align: center;
}

.status-row {
    display: flex;
    gap: 20px;
    margin-top: 8px;
}
.stat-pill {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 12px;
    padding: 10px 20px;
    text-align: center;
}
.stat-pill .val { font-size: 1.4rem; font-weight: 800; color: #34d399 }
.stat-pill .lbl { font-size: .72rem; color: #64748b; margin-top: 2px }

.how-to {
    font-size: .78rem;
    color: #475569;
    text-align: center;
    max-width: 320px;
    line-height: 1.7;
    margin-top: 4px;
}
</style>
</head>
<body>

<div class="top-bar">
    <div class="company">🏢 <?php echo htmlspecialchars($companyName) ?></div>
    <div style="text-align:left">
        <div class="live-clock" id="clock">--:--:--</div>
        <div class="live-date" id="dateStr">—</div>
    </div>
</div>

<div class="center-wrap">
    <div class="instruction">
        برای ثبت ورود یا خروج <span>کیوآر کد</span> را با گوشی اسکن کنید
    </div>

    <div class="qr-ring-wrap">
        <!-- حلقه countdown -->
        <svg class="ring-svg" viewBox="0 0 320 320">
            <circle class="ring-bg"   cx="160" cy="160" r="152"/>
            <circle class="ring-prog" id="ringProg" cx="160" cy="160" r="152"
                    stroke-dasharray="955" stroke-dashoffset="0"/>
        </svg>

        <div class="qr-box" id="qrBox">
            <div id="qrCanvas"></div>
        </div>

        <div class="countdown-badge" id="countBadge">در حال بارگذاری...</div>
    </div>

    <div class="status-row">
        <div class="stat-pill">
            <div class="val" id="statPresent">—</div>
            <div class="lbl">حاضر امروز</div>
        </div>
        <div class="stat-pill">
            <div class="val" style="color:#f59e0b" id="statOut">—</div>
            <div class="lbl">خروج زده</div>
        </div>
    </div>

    <div class="how-to">
        کیوآر هر <?php echo $ttl ?> ثانیه تغییر می‌کند و فقط یک‌بار قابل استفاده است
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
const TTL = <?php echo $ttl ?>;
let remaining = TTL;
let countInterval = null;
const CIRCUMFERENCE = 2 * Math.PI * 152; // ~955

const ring      = document.getElementById('ringProg');
const badge     = document.getElementById('countBadge');
const qrBox     = document.getElementById('qrBox');
const qrCanvas  = document.getElementById('qrCanvas');

ring.style.strokeDasharray = CIRCUMFERENCE;

// ── ساعت زنده ────────────────────────────────────────────────
function updateClock() {
    const now = new Date();
    const hh = String(now.getHours()).padStart(2,'0');
    const mm = String(now.getMinutes()).padStart(2,'0');
    const ss = String(now.getSeconds()).padStart(2,'0');
    document.getElementById('clock').textContent = hh + ':' + mm + ':' + ss;
    // تاریخ ساده شمسی (نمایشی)
    document.getElementById('dateStr').textContent =
        new Date().toLocaleDateString('fa-IR', {weekday:'long',year:'numeric',month:'long',day:'numeric'});
}
setInterval(updateClock, 1000);
updateClock();

// ── دریافت token جدید ───────────────────────────────────────
function fetchToken() {
    qrBox.classList.add('refreshing');
    clearInterval(countInterval);

    fetch('attendance_qr_display.php?action=new_token', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        qrBox.classList.remove('refreshing');
        if (!res.ok) { badge.textContent = 'خطا — تلاش مجدد...'; setTimeout(fetchToken, 3000); return; }

        // رسم QR
        qrCanvas.innerHTML = '';
        QRCode.toCanvas(document.createElement('canvas'), res.url, {
            width: 240, margin: 1,
            color: { dark: '#0f172a', light: '#ffffff' }
        }, function(err, canvas) {
            if (!err) {
                qrCanvas.innerHTML = '';
                qrCanvas.appendChild(canvas);
            }
        });

        // شروع شمارش معکوس
        remaining = res.ttl || TTL;
        startCountdown(remaining);
    })
    .catch(() => { badge.textContent = 'خطای شبکه'; setTimeout(fetchToken, 5000); });
}

function startCountdown(secs) {
    remaining = secs;
    clearInterval(countInterval);
    updateRing();
    countInterval = setInterval(() => {
        remaining--;
        updateRing();
        if (remaining <= 0) {
            clearInterval(countInterval);
            fetchToken();
        }
    }, 1000);
}

function updateRing() {
    const pct    = remaining / TTL;
    const offset = CIRCUMFERENCE * (1 - pct);
    ring.style.strokeDashoffset = offset;
    badge.textContent = remaining + ' ثانیه تا کیوآر بعدی';
    if      (remaining <= 15) { ring.classList.remove('warn'); ring.classList.add('urgent'); }
    else if (remaining <= 30) { ring.classList.remove('urgent'); ring.classList.add('warn'); }
    else                      { ring.classList.remove('warn','urgent'); }
}

// ── آمار امروز ───────────────────────────────────────────────
function loadStats() {
    fetch('attendance_checkin_manage.php?action=today_stats', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            document.getElementById('statPresent').textContent = res.present ?? '—';
            document.getElementById('statOut').textContent     = res.checked_out ?? '—';
        }
    })
    .catch(() => {});
}

// ── شروع ───────────────────────────────────────────────────
fetchToken();
loadStats();
setInterval(loadStats, 30000);
</script>
</body>
</html>
