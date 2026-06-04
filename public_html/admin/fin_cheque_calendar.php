<?php
/*
 * فایل: public_html/admin/fin_cheque_calendar.php
 * توضیحات: تقویم ماهانه سررسید چک‌ها — نمایش چک‌ها روی گرید تقویم شمسی
 */

ob_start();

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$userId  = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// ---- بررسی دسترسی ----
$hasAccess = false;
try {
    $stmtChk = $pdo->prepare('SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?');
    $stmtChk->execute([$rawRole, $rawRole]);
    $roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);
    if ($roleData) {
        $roleName = strtolower(trim($roleData['name'] ?? $rawRole));
        $finRoles = ['admin','management','manager','finance_manager','finance_expert','accountant'];
        if (in_array($roleName, $finRoles) || $roleData['is_system'] == 1) {
            $hasAccess = true;
        } else {
            $perms = json_decode($roleData['permissions'] ?? '[]', true) ?? [];
            if (in_array('fin_cheques', $perms) || in_array('fin_accounting', $perms) || in_array('all', $perms)) $hasAccess = true;
        }
    }
    if (in_array($rawRole, ['1','2','5','10','14'])) $hasAccess = true;
} catch (Throwable $e) { $hasAccess = true; }

if (!$hasAccess) {
    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'دسترسی غیرمجاز']);
        exit;
    }
    die('<p style="font-family:Tahoma;color:#e11d48;text-align:center;padding:60px">دسترسی غیرمجاز</p>');
}

// ---- اطمینان از وجود جدول چک‌ها ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_cheques` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `type` ENUM('received','issued') NOT NULL,
        `cheque_number` VARCHAR(50) NOT NULL,
        `bank_name` VARCHAR(100) DEFAULT NULL,
        `amount` DECIMAL(20,0) NOT NULL,
        `person_id` INT DEFAULT NULL,
        `issue_date` DATE DEFAULT NULL,
        `due_date` DATE NOT NULL,
        `status` ENUM('pending','cleared','bounced','transferred') DEFAULT 'pending',
        `doc_id` INT DEFAULT NULL,
        `bank_account_id` INT DEFAULT NULL,
        `description` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted` TINYINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ============================================================
// تابع کمکی: تبدیل تاریخ شمسی به میلادی
// ============================================================
function jalaliMonthToGregorianRange(int $jYear, int $jMonth): array {
    // اولین روز ماه شمسی → میلادی
    list($gy1, $gm1, $gd1) = jalali_to_gregorian($jYear, $jMonth, 1);
    // آخرین روز ماه: ماه‌های ۱-۶ = ۳۱ روز، ۷-۱۱ = ۳۰ روز، ۱۲ = ۲۹ یا ۳۰ روز
    if ($jMonth <= 6) $lastDay = 31;
    elseif ($jMonth <= 11) $lastDay = 30;
    else {
        // سال کبیسه شمسی: بررسی ساده
        $rem = $jYear % 33;
        $leapRems = [1, 5, 9, 13, 17, 22, 26, 30];
        $lastDay = in_array($rem, $leapRems) ? 30 : 29;
    }
    list($gy2, $gm2, $gd2) = jalali_to_gregorian($jYear, $jMonth, $lastDay);
    return [
        'start' => sprintf('%04d-%02d-%02d', $gy1, $gm1, $gd1),
        'end'   => sprintf('%04d-%02d-%02d', $gy2, $gm2, $gd2),
        'first_g' => [$gy1, $gm1, $gd1],
        'last_day_jalali' => $lastDay,
    ];
}

// ============================================================
// AJAX: داده‌های تقویم
// ============================================================
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = trim($_POST['action'] ?? '');

    // ---- داده تقویم ماهانه ----
    if ($action === 'calendar_data') {
        $jYear  = (int)($_POST['year']  ?? date('Y'));
        $jMonth = (int)($_POST['month'] ?? 1);
        if ($jYear < 1300 || $jYear > 1500) $jYear = (int)jdate('Y', '', '', 'Asia/Tehran', 'en');
        if ($jMonth < 1 || $jMonth > 12) $jMonth = 1;

        try {
            $range = jalaliMonthToGregorianRange($jYear, $jMonth);
            $start = $range['start'];
            $end   = $range['end'];

            // واکشی همه چک‌های این ماه
            $stmt = $pdo->prepare(
                "SELECT c.id, c.cheque_number, c.bank_name, c.amount,
                        c.type, c.status, c.due_date,
                        COALESCE(p.company_name, p.name, '') AS person_name
                 FROM fin_cheques c
                 LEFT JOIN fin_persons p ON p.id = c.person_id
                 WHERE c.is_deleted = 0
                   AND c.due_date >= ? AND c.due_date <= ?
                 ORDER BY c.due_date ASC, c.id ASC"
            );
            $stmt->execute([$start, $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // گروه‌بندی بر اساس تاریخ میلادی
            $days = [];
            foreach ($rows as $r) {
                $key = $r['due_date'];
                if (!isset($days[$key])) $days[$key] = [];
                $days[$key][] = [
                    'id'            => (int)$r['id'],
                    'cheque_number' => $r['cheque_number'],
                    'bank_name'     => $r['bank_name'] ?: '—',
                    'amount'        => (int)$r['amount'],
                    'amount_fmt'    => number_format((int)$r['amount']),
                    'type'          => $r['type'],
                    'status'        => $r['status'],
                    'person_name'   => $r['person_name'] ?: '—',
                    'due_date'      => $r['due_date'],
                ];
            }

            // آمار ماه
            $statStmt = $pdo->prepare(
                "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN status='cleared' THEN 1 ELSE 0 END), 0) AS cleared_cnt,
                    COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END), 0) AS pending_cnt,
                    COALESCE(SUM(CASE WHEN status='bounced' THEN 1 ELSE 0 END), 0) AS bounced_cnt,
                    COALESCE(SUM(CASE WHEN status='cleared' THEN amount ELSE 0 END), 0) AS cleared_sum,
                    COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END), 0) AS pending_sum,
                    COALESCE(SUM(CASE WHEN status='bounced' THEN amount ELSE 0 END), 0) AS bounced_sum
                 FROM fin_cheques
                 WHERE is_deleted=0 AND due_date >= ? AND due_date <= ?"
            );
            $statStmt->execute([$start, $end]);
            $stats = $statStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok'    => true,
                'days'  => $days,
                'stats' => [
                    'total'       => (int)$stats['total'],
                    'cleared_cnt' => (int)$stats['cleared_cnt'],
                    'pending_cnt' => (int)$stats['pending_cnt'],
                    'bounced_cnt' => (int)$stats['bounced_cnt'],
                    'cleared_sum' => number_format((int)$stats['cleared_sum']),
                    'pending_sum' => number_format((int)$stats['pending_sum']),
                    'bounced_sum' => number_format((int)$stats['bounced_sum']),
                ],
                'range' => [
                    'start'           => $start,
                    'end'             => $end,
                    'first_g'         => $range['first_g'],
                    'last_day_jalali' => $range['last_day_jalali'],
                    'year'            => $jYear,
                    'month'           => $jMonth,
                ],
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'خطای سرور: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'درخواست نامعتبر']);
    exit;
}

// ============================================================
// رندر HTML
// ============================================================
$basePath  = '../../';
$pageTitle = 'تقویم سررسید چک';

// ماه و سال جاری شمسی
$todayJYear  = (int)jdate('Y', '', '', 'Asia/Tehran', 'en');
$todayJMonth = (int)jdate('m', '', '', 'Asia/Tehran', 'en');
$todayJDay   = (int)jdate('d', '', '', 'Asia/Tehran', 'en');
$todayGreg   = date('Y-m-d');

$extraCss = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';
require_once __DIR__ . '/../../templates/header.php';
?>
<style>
/* ===== تقویم سررسید چک ===== */
.chkcal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.chkcal-icon{width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff;box-shadow:0 6px 16px rgba(37,99,235,.35)}
.chkcal-title{display:flex;align-items:center;gap:12px}
.chkcal-title h1{font-size:1.25rem;font-weight:900;color:#1e293b;margin:0}
.chkcal-title p{font-size:.78rem;color:#64748b;margin:3px 0 0}

/* ناوبری ماه */
.chkcal-nav{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.chkcal-nav-btn{width:38px;height:38px;border:2px solid #e2e8f0;border-radius:10px;background:#fff;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center;transition:all .2s;color:#374151}
.chkcal-nav-btn:hover{border-color:#2563eb;color:#2563eb;background:#eff6ff}
.chkcal-month-label{font-size:1.1rem;font-weight:900;color:#1e293b;min-width:120px;text-align:center}
.chkcal-today-btn{padding:8px 16px;border-radius:10px;border:2px solid #2563eb;background:#eff6ff;color:#1d4ed8;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;transition:all .2s}
.chkcal-today-btn:hover{background:#2563eb;color:#fff}

/* گرید تقویم */
.chkcal-grid-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06);margin-bottom:24px}
.chkcal-weekdays{display:grid;grid-template-columns:repeat(7,1fr);background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:12px 0}
.chkcal-wd{text-align:center;font-size:.78rem;font-weight:800;color:#fff;padding:4px 0}
.chkcal-days{display:grid;grid-template-columns:repeat(7,1fr);gap:0}
.chkcal-day{min-height:100px;border-left:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;padding:6px;cursor:pointer;transition:background .15s;position:relative;vertical-align:top}
.chkcal-day:nth-child(7n){border-left:none}
.chkcal-day:hover{background:#f8faff}
.chkcal-day.empty{background:#fafafa;cursor:default}
.chkcal-day.today{background:#eff6ff}
.chkcal-day.today .chkcal-day-num{background:#2563eb;color:#fff;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-weight:900}
.chkcal-day.has-cheques{background:#fefce8}
.chkcal-day-num{font-size:.82rem;font-weight:700;color:#374151;margin-bottom:4px;width:26px;height:26px;display:flex;align-items:center;justify-content:center}
.chkcal-day.empty .chkcal-day-num{color:#cbd5e1}

/* بج‌های چک */
.chkcal-badges{display:flex;flex-direction:column;gap:3px}
.chkcal-badge{font-size:.68rem;font-weight:700;padding:2px 6px;border-radius:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;cursor:pointer}
.chkcal-badge.received-pending{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd}
.chkcal-badge.paid-pending{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5}
.chkcal-badge.issued-pending{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5}
.chkcal-badge.bounced{background:#fce7f3;color:#9d174d;border:1px solid #f9a8d4}
.chkcal-badge.cleared{background:#dcfce7;color:#15803d;border:1px solid #86efac;opacity:.7}
.chkcal-badge.transferred{background:#f3f4f6;color:#6b7280;border:1px solid #d1d5db;opacity:.8}
.chkcal-more{font-size:.65rem;color:#94a3b8;font-weight:600;margin-top:2px}

/* کارت‌های آماری */
.chkcal-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
@media(max-width:800px){.chkcal-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.chkcal-stats{grid-template-columns:1fr}}

/* مودال جزئیات روز */
.chkcal-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:800;align-items:center;justify-content:center}
.chkcal-modal-overlay.open{display:flex}
.chkcal-modal{background:#fff;border-radius:18px;width:560px;max-width:95vw;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.chkcal-modal-hdr{background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:16px 22px;display:flex;align-items:center;justify-content:space-between;color:#fff}
.chkcal-modal-hdr h3{margin:0;font-size:1rem;font-weight:900}
.chkcal-modal-close{width:30px;height:30px;border:none;background:rgba(255,255,255,.2);color:#fff;border-radius:8px;cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center}
.chkcal-modal-close:hover{background:rgba(255,255,255,.35)}
.chkcal-modal-body{overflow-y:auto;padding:20px;flex:1}

/* اسپینر بارگذاری */
.chkcal-spin{display:inline-block;width:28px;height:28px;border:3px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.chkcal-loading{text-align:center;padding:40px;color:#94a3b8}

/* ریسپانسیو گرید */
@media(max-width:600px){
    .chkcal-day{min-height:70px;padding:4px}
    .chkcal-badge{font-size:.6rem;padding:1px 4px}
    .chkcal-day-num{font-size:.75rem}
}
</style>

<div class="main-content">

    <!-- هدر صفحه -->
    <div class="chkcal-header">
        <div class="chkcal-title">
            <div class="chkcal-icon">📅</div>
            <div>
                <h1>تقویم سررسید چک</h1>
                <p>نمایش ماهانه چک‌های دریافتی و پرداختی بر اساس تاریخ سررسید</p>
            </div>
        </div>
        <div class="chkcal-nav">
            <button class="chkcal-today-btn" onclick="goToday()">امروز</button>
            <button class="chkcal-nav-btn" onclick="changeMonth(-1)" title="ماه قبل">›</button>
            <div class="chkcal-month-label" id="monthLabel">...</div>
            <button class="chkcal-nav-btn" onclick="changeMonth(1)" title="ماه بعد">‹</button>
            <a href="fin_cheques.php" class="fin-btn fin-btn-outline fin-btn-sm">📋 لیست چک‌ها</a>
        </div>
    </div>

    <!-- کارت‌های آماری -->
    <div class="chkcal-stats" id="statsCards">
        <div class="fin-stat-card blue">
            <div class="fin-stat-icon">📋</div>
            <div>
                <div class="fin-stat-label">سررسید این ماه</div>
                <div class="fin-stat-value" id="stat-total">—</div>
            </div>
        </div>
        <div class="fin-stat-card green">
            <div class="fin-stat-icon">✅</div>
            <div>
                <div class="fin-stat-label">وصول‌شده</div>
                <div class="fin-stat-value" id="stat-cleared">—</div>
                <div class="fin-stat-unit" id="stat-cleared-sum" style="font-size:.7rem;color:#6ee7b7"></div>
            </div>
        </div>
        <div class="fin-stat-card amber">
            <div class="fin-stat-icon">⏳</div>
            <div>
                <div class="fin-stat-label">در انتظار</div>
                <div class="fin-stat-value" id="stat-pending">—</div>
                <div class="fin-stat-unit" id="stat-pending-sum" style="font-size:.7rem;color:#fde68a"></div>
            </div>
        </div>
        <div class="fin-stat-card rose">
            <div class="fin-stat-icon">⚠️</div>
            <div>
                <div class="fin-stat-label">برگشتی</div>
                <div class="fin-stat-value" id="stat-bounced">—</div>
                <div class="fin-stat-unit" id="stat-bounced-sum" style="font-size:.7rem;color:#fca5a5"></div>
            </div>
        </div>
    </div>

    <!-- گرید تقویم -->
    <div class="chkcal-grid-wrap">
        <div class="chkcal-weekdays">
            <div class="chkcal-wd">شنبه</div>
            <div class="chkcal-wd">یک‌شنبه</div>
            <div class="chkcal-wd">دوشنبه</div>
            <div class="chkcal-wd">سه‌شنبه</div>
            <div class="chkcal-wd">چهارشنبه</div>
            <div class="chkcal-wd">پنج‌شنبه</div>
            <div class="chkcal-wd">جمعه</div>
        </div>
        <div class="chkcal-days" id="calDays">
            <div class="chkcal-loading"><div class="chkcal-spin"></div></div>
        </div>
    </div>

</div><!-- /main-content -->

<!-- مودال جزئیات روز -->
<div class="chkcal-modal-overlay" id="dayModalOverlay" onclick="closeDayModal(event)">
    <div class="chkcal-modal" onclick="event.stopPropagation()">
        <div class="chkcal-modal-hdr">
            <h3 id="dayModalTitle">چک‌های روز</h3>
            <button class="chkcal-modal-close" onclick="closeDayModal()">×</button>
        </div>
        <div class="chkcal-modal-body" id="dayModalBody"></div>
    </div>
</div>

<script>
/* ============================================================
   JS تقویم سررسید چک
   ============================================================ */

// ماه و سال شمسی جاری (از PHP)
var curJYear  = <?= $todayJYear ?>;
var curJMonth = <?= $todayJMonth ?>;
var todayJDay = <?= $todayJDay ?>;
var todayGreg = '<?= $todayGreg ?>';

// داده روزهای بارگذاری‌شده فعلی
var calData = {};
var curRange = {};

// نام ماه‌های شمسی
var jalaliMonths = ['','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

// ============================================================
// ناوبری
// ============================================================
function changeMonth(delta) {
    curJMonth += delta;
    if (curJMonth > 12) { curJMonth = 1; curJYear++; }
    if (curJMonth < 1)  { curJMonth = 12; curJYear--; }
    loadCalendar();
}

function goToday() {
    curJYear  = <?= $todayJYear ?>;
    curJMonth = <?= $todayJMonth ?>;
    loadCalendar();
}

// ============================================================
// بارگذاری داده
// ============================================================
function loadCalendar() {
    document.getElementById('monthLabel').textContent = jalaliMonths[curJMonth] + ' ' + numFa(curJYear);
    document.getElementById('calDays').innerHTML = '<div class="chkcal-loading"><div class="chkcal-spin"></div></div>';

    var fd = new FormData();
    fd.append('action', 'calendar_data');
    fd.append('year',  curJYear);
    fd.append('month', curJMonth);

    fetch(window.location.href, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: fd
    })
    .then(function(r){ return r.json(); })
    .then(function(res) {
        if (!res.ok) {
            document.getElementById('calDays').innerHTML = '<div style="padding:30px;color:#e11d48;text-align:center">' + esc(res.msg) + '</div>';
            return;
        }
        calData  = res.days || {};
        curRange = res.range || {};
        updateStats(res.stats || {});
        renderCalendar(res.range);
    })
    .catch(function() {
        document.getElementById('calDays').innerHTML = '<div style="padding:30px;color:#e11d48;text-align:center">خطای ارتباطی رخ داد.</div>';
    });
}

// ============================================================
// آمار
// ============================================================
function updateStats(s) {
    document.getElementById('stat-total').textContent   = numFa(s.total || 0);
    document.getElementById('stat-cleared').textContent = numFa(s.cleared_cnt || 0);
    document.getElementById('stat-pending').textContent = numFa(s.pending_cnt || 0);
    document.getElementById('stat-bounced').textContent = numFa(s.bounced_cnt || 0);
    document.getElementById('stat-cleared-sum').textContent = s.cleared_sum ? numFa2(s.cleared_sum) + ' ریال' : '';
    document.getElementById('stat-pending-sum').textContent = s.pending_sum ? numFa2(s.pending_sum) + ' ریال' : '';
    document.getElementById('stat-bounced-sum').textContent = s.bounced_sum ? numFa2(s.bounced_sum) + ' ریال' : '';
}

// ============================================================
// رندر گرید تقویم
// ============================================================
function renderCalendar(range) {
    if (!range) return;

    var firstG    = range.first_g;   // [year, month, day] میلادی اول ماه
    var lastJDay  = range.last_day_jalali;
    var jYear     = range.year;
    var jMonth    = range.month;

    // روز هفته اول ماه: 0=شنبه...6=جمعه (گرید RTL شروع با شنبه)
    // تبدیل: PHP date('w') → 0=یکشنبه...6=شنبه
    // ما می‌خواهیم: شنبه=0, یکشنبه=1, ..., جمعه=6
    var firstDate  = new Date(firstG[0], firstG[1]-1, firstG[2]);
    var phpDow     = firstDate.getDay(); // 0=Sun,1=Mon,...,6=Sat
    // تبدیل به ایرانی: شنبه=0
    var iranDow    = (phpDow + 1) % 7;  // شنبه=0, یکشنبه=1, ..., جمعه=6

    var html = '';
    var totalCells = iranDow + lastJDay;
    var rows = Math.ceil(totalCells / 7);

    var dayNum = 1;
    for (var cell = 0; cell < rows * 7; cell++) {
        var col = cell % 7;
        if (cell < iranDow || dayNum > lastJDay) {
            html += '<div class="chkcal-day empty"><div class="chkcal-day-num"></div></div>';
        } else {
            // تبدیل روز شمسی به میلادی برای کلید
            var gregArr = jalaliToGreg(jYear, jMonth, dayNum);
            var gregKey = gregArr[0] + '-' + pad2(gregArr[1]) + '-' + pad2(gregArr[2]);
            var isToday = (gregKey === todayGreg) && (jYear === <?= $todayJYear ?>) && (jMonth === <?= $todayJMonth ?>);

            var dayCheques = calData[gregKey] || [];
            var classes = 'chkcal-day';
            if (isToday) classes += ' today';
            if (dayCheques.length) classes += ' has-cheques';

            var badgesHtml = '';
            var maxShow = 3;
            var shown = 0;
            for (var ci = 0; ci < dayCheques.length && shown < maxShow; ci++) {
                var ch = dayCheques[ci];
                var badgeCls = getBadgeClass(ch.type, ch.status);
                var badgeLabel = getBadgeLabel(ch.type, ch.status);
                badgesHtml += '<div class="chkcal-badge ' + badgeCls + '" title="' + esc(ch.person_name) + ' — ' + esc(ch.amount_fmt) + ' ریال">' + badgeLabel + '</div>';
                shown++;
            }
            if (dayCheques.length > maxShow) {
                badgesHtml += '<div class="chkcal-more">+ ' + numFa(dayCheques.length - maxShow) + ' مورد دیگر</div>';
            }

            var dayNumDisp = numFa(dayNum);
            var dayNumHtml = isToday
                ? '<div class="chkcal-day-num">' + dayNumDisp + '</div>'
                : '<div class="chkcal-day-num">' + dayNumDisp + '</div>';

            html += '<div class="' + classes + '" onclick="openDayModal(\'' + gregKey + '\',' + dayNum + ')">'
                  + dayNumHtml
                  + '<div class="chkcal-badges">' + badgesHtml + '</div>'
                  + '</div>';
            dayNum++;
        }
    }

    document.getElementById('calDays').innerHTML = html;
}

// ============================================================
// مودال جزئیات روز
// ============================================================
function openDayModal(gregKey, jDay) {
    var dayCheques = calData[gregKey] || [];
    var title = jalaliMonths[curJMonth] + ' ' + numFa(jDay) + ' — ' + numFa(curJYear);
    document.getElementById('dayModalTitle').textContent = 'چک‌های ' + title;

    if (!dayCheques.length) {
        document.getElementById('dayModalBody').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8">هیچ چکی برای این روز ثبت نشده است.</div>';
    } else {
        var html = '<div style="display:flex;flex-direction:column;gap:10px">';
        dayCheques.forEach(function(ch, i) {
            var badgeCls   = getBadgeClass(ch.type, ch.status);
            var typeLabel  = ch.type === 'received' ? 'دریافتی' : 'پرداختی (صادره)';
            var statusLabel = getStatusLabel(ch.status);
            html += '<div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;background:#fafafa">'
                  + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">'
                  +   '<div style="font-weight:800;color:#1e293b;font-size:.9rem">چک شماره ' + esc(ch.cheque_number) + '</div>'
                  +   '<span class="chkcal-badge ' + badgeCls + '">' + statusLabel + '</span>'
                  + '</div>'
                  + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.82rem">'
                  +   '<div><span style="color:#64748b">طرف حساب: </span><strong>' + esc(ch.person_name) + '</strong></div>'
                  +   '<div><span style="color:#64748b">نوع: </span><strong>' + typeLabel + '</strong></div>'
                  +   '<div><span style="color:#64748b">بانک: </span><strong>' + esc(ch.bank_name) + '</strong></div>'
                  +   '<div><span style="color:#64748b">مبلغ: </span><strong style="color:#059669;direction:ltr;display:inline-block">' + numFa2(ch.amount_fmt) + ' ریال</strong></div>'
                  + '</div>'
                  + '</div>';
        });
        html += '</div>';
        document.getElementById('dayModalBody').innerHTML = html;
    }

    document.getElementById('dayModalOverlay').classList.add('open');
}

function closeDayModal(e) {
    if (!e || e.target === document.getElementById('dayModalOverlay')) {
        document.getElementById('dayModalOverlay').classList.remove('open');
    }
}

// ============================================================
// توابع کمکی
// ============================================================
function getBadgeClass(type, status) {
    if (status === 'bounced')     return 'bounced';
    if (status === 'cleared')     return 'cleared';
    if (status === 'transferred') return 'transferred';
    // pending
    return type === 'received' ? 'received-pending' : 'issued-pending';
}

function getBadgeLabel(type, status) {
    if (status === 'bounced')     return '⚠ برگشتی';
    if (status === 'cleared')     return '✓ وصول';
    if (status === 'transferred') return '↗ انتقال';
    return type === 'received' ? '↙ دریافتی' : '↗ پرداختی';
}

function getStatusLabel(status) {
    var map = {pending:'در انتظار', cleared:'وصول شده', bounced:'برگشتی', transferred:'انتقال یافته'};
    return map[status] || status;
}

function pad2(n) { return n < 10 ? '0' + n : '' + n; }

function numFa(n) {
    return String(Math.round(parseFloat(n) || 0)).replace(/[0-9]/g, function(d) {
        return '۰۱۲۳۴۵۶۷۸۹'[parseInt(d)];
    });
}

function numFa2(s) {
    // اگر رشته با کاما بود (number_format PHP) فقط اعداد را فارسی کن
    return String(s || '').replace(/[0-9]/g, function(d) {
        return '۰۱۲۳۴۵۶۷۸۹'[parseInt(d)];
    });
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ============================================================
// تبدیل تاریخ شمسی به میلادی (جاوااسکریپت)
// ============================================================
function jalaliToGreg(jy, jm, jd) {
    jy += 1595;
    var days = -355668
        + (365 * jy)
        + (Math.floor(jy / 33) * 8)
        + Math.floor(((jy % 33) + 3) / 4)
        + jd
        + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30 + 186));
    var gy = 400 * Math.floor(days / 146097);
    days %= 146097;
    if (days > 36524) {
        days--;
        gy += 100 * Math.floor(days / 36524);
        days %= 36524;
        if (days >= 365) days++;
    }
    gy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) {
        gy += Math.floor((days - 1) / 365);
        days = (days - 1) % 365;
    }
    var gd = days + 1;
    var sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var gm = 0;
    for (gm = 0; gm < 13; gm++) {
        var v = sal_a[gm];
        if (gd <= v) break;
        gd -= v;
    }
    return [gy, gm, gd];
}

// ============================================================
// راه‌اندازی
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    loadCalendar();
});

// بستن مودال با Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDayModal({target: document.getElementById('dayModalOverlay')});
});
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
