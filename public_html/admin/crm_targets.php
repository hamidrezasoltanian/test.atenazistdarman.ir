<?php
/*
 * فایل: public_html/admin/crm_targets.php
 * توضیحات: مدیریت اهداف فروش ماهانه — تعیین هدف، پیگیری عملکرد، نمودار پیشرفت
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
        $allowedRoles = ['admin','management','manager','sales_manager','sales','crm_manager','hr_manager'];
        if (in_array($roleName, $allowedRoles) || $roleData['is_system'] == 1) {
            $hasAccess = true;
        } else {
            $perms = json_decode($roleData['permissions'] ?? '[]', true) ?? [];
            if (in_array('crm_targets', $perms) || in_array('all', $perms)) $hasAccess = true;
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

// ---- ایجاد جدول اهداف فروش در صورت نبود ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_sales_targets` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `year` INT NOT NULL,
        `month` INT NOT NULL CHECK (`month` BETWEEN 1 AND 12),
        `target_amount` DECIMAL(20,0) NOT NULL DEFAULT 0,
        `achieved_amount` DECIMAL(20,0) NOT NULL DEFAULT 0,
        `notes` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_user_year_month` (`user_id`, `year`, `month`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { /* جدول از قبل وجود داشت */ }

// ---- نام ماه‌های شمسی ----
$jalaliMonths = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

// ---- ماه و سال جاری شمسی ----
$jNow        = jdate('Y_m', '', '', 'Asia/Tehran', 'en');
$jNowParts   = explode('_', $jNow);
$currentJYear  = (int)$jNowParts[0];
$currentJMonth = (int)$jNowParts[1];

// ---- محاسبه بازه میلادی برای یک ماه شمسی ----
function gregorianRangeForJalaliMonth($jYear, $jMonth) {
    // اول ماه شمسی
    $startG = jalali_to_gregorian($jYear, $jMonth, 1);
    // اول ماه بعدی
    if ($jMonth < 12) {
        $endG = jalali_to_gregorian($jYear, $jMonth + 1, 1);
    } else {
        $endG = jalali_to_gregorian($jYear + 1, 1, 1);
    }
    $startDate = sprintf('%04d-%02d-%02d', $startG[0], $startG[1], $startG[2]);
    $endDate   = sprintf('%04d-%02d-%02d', $endG[0],   $endG[1],   $endG[2]);
    return [$startDate, $endDate];
}

// ===========================================================
// پردازش درخواست‌های AJAX
// ===========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action'] ?? '');

    try {

        // ---- دریافت اهداف و پیشرفت ----
        if ($action === 'get_targets') {
            $jYear  = (int)($_POST['year']  ?? $currentJYear);
            $jMonth = (int)($_POST['month'] ?? $currentJMonth);

            // بازه میلادی ماه شمسی
            list($rangeStart, $rangeEnd) = gregorianRangeForJalaliMonth($jYear, $jMonth);

            // کاربران فروش
            $stUsers = $pdo->query(
                "SELECT u.id, u.fullname, r.name AS role_name
                 FROM users u
                 LEFT JOIN roles r ON r.id = u.role_id OR r.name = u.role
                 WHERE u.is_active = 1
                 AND (
                     LOWER(COALESCE(r.name,'')) IN ('sales','sales_manager','crm_expert','crm_manager','salesperson')
                     OR LOWER(COALESCE(u.role,'')) IN ('sales','sales_manager','crm_expert','crm_manager','salesperson')
                 )
                 ORDER BY u.fullname"
            );
            $salesUsers = $stUsers->fetchAll(PDO::FETCH_ASSOC);

            // اگر هیچ کاربر فروشی یافت نشد، همه کاربران فعال را نشان بده
            if (empty($salesUsers)) {
                $stUsers2 = $pdo->query(
                    "SELECT u.id, u.fullname, COALESCE(r.name, u.role, '') AS role_name
                     FROM users u
                     LEFT JOIN roles r ON r.id = u.role_id
                     WHERE u.is_active = 1
                     ORDER BY u.fullname LIMIT 50"
                );
                $salesUsers = $stUsers2->fetchAll(PDO::FETCH_ASSOC);
            }

            // اهداف ثبت‌شده برای این ماه
            $stTargets = $pdo->prepare(
                "SELECT user_id, target_amount, achieved_amount, notes
                 FROM crm_sales_targets WHERE year = ? AND month = ?"
            );
            $stTargets->execute([$jYear, $jMonth]);
            $targetsMap = [];
            foreach ($stTargets->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $targetsMap[$t['user_id']] = $t;
            }

            // فروش واقعی از فاکتورها (تأیید شده)
            $stSales = $pdo->prepare(
                "SELECT created_by, SUM(total_amount) AS sold
                 FROM fin_invoices
                 WHERE type = 'sell'
                   AND status = 'confirmed'
                   AND is_deleted = 0
                   AND created_at >= ?
                   AND created_at <  ?
                 GROUP BY created_by"
            );
            $stSales->execute([$rangeStart, $rangeEnd]);
            $salesMap = [];
            foreach ($stSales->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $salesMap[$s['created_by']] = (int)$s['sold'];
            }

            // ترکیب داده‌ها
            $rows         = [];
            $totalTarget  = 0;
            $totalAchieve = 0;

            foreach ($salesUsers as $u) {
                $uid      = (int)$u['id'];
                $target   = isset($targetsMap[$uid]) ? (int)$targetsMap[$uid]['target_amount'] : 0;
                $achieved = isset($salesMap[$uid])   ? (int)$salesMap[$uid] : 0;
                $gap      = $target - $achieved;
                $pct      = ($target > 0) ? round($achieved / $target * 100, 1) : 0;

                $totalTarget  += $target;
                $totalAchieve += $achieved;

                $rows[] = [
                    'user_id'        => $uid,
                    'fullname'       => $u['fullname'],
                    'role_name'      => $u['role_name'],
                    'target'         => $target,
                    'achieved'       => $achieved,
                    'gap'            => $gap,
                    'pct'            => $pct,
                    'target_fmt'     => number_format($target),
                    'achieved_fmt'   => number_format($achieved),
                    'gap_fmt'        => number_format(abs($gap)),
                    'notes'          => $targetsMap[$uid]['notes'] ?? '',
                    'has_target'     => isset($targetsMap[$uid]),
                    'bar_color'      => ($pct >= 80 ? 'green' : ($pct >= 50 ? 'amber' : 'rose')),
                ];
            }

            $totalPct       = ($totalTarget > 0) ? round($totalAchieve / $totalTarget * 100, 1) : 0;
            $totalRemaining = max(0, $totalTarget - $totalAchieve);

            echo json_encode([
                'ok'    => true,
                'rows'  => $rows,
                'summary' => [
                    'total_target'    => $totalTarget,
                    'total_achieve'   => $totalAchieve,
                    'total_pct'       => $totalPct,
                    'total_remaining' => $totalRemaining,
                    'target_fmt'      => number_format($totalTarget),
                    'achieve_fmt'     => number_format($totalAchieve),
                    'remaining_fmt'   => number_format($totalRemaining),
                ],
                'period' => [
                    'year'       => $jYear,
                    'month'      => $jMonth,
                    'month_name' => $GLOBALS['jalaliMonths'][$jMonth] ?? '',
                ],
            ]);
            exit;
        }

        // ---- ذخیره هدف ----
        if ($action === 'save_target') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'توکن CSRF نامعتبر است.']); exit;
            }

            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $jYear        = (int)($_POST['year']  ?? $currentJYear);
            $jMonth       = (int)($_POST['month'] ?? $currentJMonth);
            $targetAmount = (int)faToEn($_POST['target_amount'] ?? '0');
            $notes        = trim($_POST['notes'] ?? '');

            if (!$targetUserId) { echo json_encode(['ok' => false, 'msg' => 'کاربر انتخاب نشده.']); exit; }
            if ($jMonth < 1 || $jMonth > 12) { echo json_encode(['ok' => false, 'msg' => 'ماه نامعتبر است.']); exit; }
            if ($targetAmount < 0) { echo json_encode(['ok' => false, 'msg' => 'مبلغ هدف نمی‌تواند منفی باشد.']); exit; }

            $st = $pdo->prepare(
                "INSERT INTO crm_sales_targets (user_id, year, month, target_amount, notes, created_at, updated_at)
                 VALUES (?,?,?,?,?,NOW(),NOW())
                 ON DUPLICATE KEY UPDATE
                    target_amount = VALUES(target_amount),
                    notes         = VALUES(notes),
                    updated_at    = NOW()"
            );
            $st->execute([$targetUserId, $jYear, $jMonth, $targetAmount, $notes]);

            logSystem('crm_targets', 'save', $targetUserId,
                "ثبت هدف فروش: کاربر=$targetUserId سال=$jYear ماه=$jMonth مبلغ=$targetAmount");

            echo json_encode(['ok' => true, 'msg' => 'هدف فروش با موفقیت ذخیره شد.']);
            exit;
        }

        // ---- دریافت اطلاعات یک هدف برای ویرایش ----
        if ($action === 'get_progress') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $jYear        = (int)($_POST['year']  ?? $currentJYear);
            $jMonth       = (int)($_POST['month'] ?? $currentJMonth);

            $st = $pdo->prepare(
                "SELECT target_amount, achieved_amount, notes
                 FROM crm_sales_targets WHERE user_id = ? AND year = ? AND month = ?"
            );
            $st->execute([$targetUserId, $jYear, $jMonth]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'data' => $row ?: null]);
            exit;
        }

        // ---- لیست همه کاربران (برای dropdown) ----
        if ($action === 'get_users') {
            $st = $pdo->query(
                "SELECT id, fullname FROM users WHERE is_active = 1 ORDER BY fullname"
            );
            echo json_encode(['ok' => true, 'users' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'عملیات نامشخص.']);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'خطای سرور: ' . $e->getMessage()]);
    }
    exit;
}

// ---- صفحه HTML ----
$basePath  = '../../';
$pageTitle = 'اهداف فروش';
$extraCss  = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';
$csrfToken = csrf_token();

// لیست سال‌های شمسی برای dropdown
$yearOptions = '';
for ($y = $currentJYear - 2; $y <= $currentJYear + 1; $y++) {
    $sel = ($y === $currentJYear) ? 'selected' : '';
    $yearOptions .= "<option value=\"$y\" $sel>$y</option>";
}

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>

<div class="main-content" id="mainContent">
<div style="padding:24px 28px;max-width:1400px;margin:0 auto;">

    <!-- سربرگ صفحه -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="margin:0;font-size:1.5rem;font-weight:700;color:#1e293b;">
                🎯 اهداف فروش
            </h1>
            <p style="margin:4px 0 0;color:#64748b;font-size:0.9rem;">تعیین و پیگیری اهداف ماهانه تیم فروش</p>
        </div>
        <button class="fin-btn blue" onclick="openTargetDrawer()">
            ➕ تعیین هدف جدید
        </button>
    </div>

    <!-- تب‌ها -->
    <div class="fin-tabs" style="margin-bottom:20px;">
        <button class="fin-tab active" id="tabDashboard" onclick="switchTab('dashboard')">📊 داشبورد ماهانه</button>
        <button class="fin-tab" id="tabManage" onclick="switchTab('manage')">⚙ مدیریت اهداف</button>
    </div>

    <!-- انتخاب دوره -->
    <div class="fin-panel" style="padding:16px 20px;margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <label style="font-weight:600;font-size:0.9rem;">دوره:</label>
            <select id="filterYear" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;min-width:100px;">
                <?= $yearOptions ?>
            </select>
            <select id="filterMonth" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;min-width:140px;">
                <?php foreach ($jalaliMonths as $mNum => $mName): if (!$mNum) continue; ?>
                    <option value="<?= $mNum ?>" <?= ($mNum === $currentJMonth) ? 'selected' : '' ?>><?= $mName ?></option>
                <?php endforeach; ?>
            </select>
            <button class="fin-btn blue" onclick="loadTargets()">📊 نمایش</button>
            <span id="periodLabel" style="font-weight:600;color:#1e293b;font-size:1rem;"></span>
        </div>
    </div>

    <!-- ==================== بخش داشبورد ==================== -->
    <div id="sectionDashboard">

        <!-- کارت‌های آمار کلی -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
            <div class="fin-stat-card blue">
                <div class="label">هدف کل تیم</div>
                <div class="value" id="sumTarget">—</div>
                <div style="font-size:0.78rem;color:#60a5fa;margin-top:4px;">ریال</div>
            </div>
            <div class="fin-stat-card green">
                <div class="label">فروش محقق‌شده</div>
                <div class="value" id="sumAchieve">—</div>
                <div style="font-size:0.78rem;color:#4ade80;margin-top:4px;">ریال</div>
            </div>
            <div class="fin-stat-card amber">
                <div class="label">درصد تحقق</div>
                <div class="value" id="sumPct">—%</div>
                <div style="height:6px;background:#fef3c7;border-radius:3px;margin-top:8px;overflow:hidden;">
                    <div id="sumPctBar" style="height:100%;background:#f59e0b;border-radius:3px;width:0%;transition:width .5s;"></div>
                </div>
            </div>
            <div class="fin-stat-card rose">
                <div class="label">باقی‌مانده</div>
                <div class="value" id="sumRemaining">—</div>
                <div style="font-size:0.78rem;color:#fb7185;margin-top:4px;">ریال</div>
            </div>
        </div>

        <!-- جدول عملکرد فروشندگان -->
        <div class="fin-panel" style="padding:0;overflow:hidden;">
            <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:1rem;color:#1e293b;">
                عملکرد اعضای تیم فروش
            </div>
            <div style="overflow-x:auto;">
                <table class="fin-table" id="targetsTable">
                    <thead>
                        <tr>
                            <th>کارشناس فروش</th>
                            <th>هدف ماهانه (ریال)</th>
                            <th>فروش واقعی (ریال)</th>
                            <th>اختلاف (ریال)</th>
                            <th>درصد تحقق</th>
                            <th>نمودار پیشرفت</th>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody id="targetsBody">
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">در حال بارگذاری...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /sectionDashboard -->

    <!-- ==================== بخش مدیریت ==================== -->
    <div id="sectionManage" style="display:none;">

        <div class="fin-panel" style="padding:24px;">
            <h3 style="margin:0 0 20px;font-size:1rem;font-weight:700;color:#1e293b;">تعیین یا ویرایش هدف فروش</h3>

            <form id="targetForm" style="max-width:560px;">
                <input type="hidden" name="action" value="save_target">
                <input type="hidden" name="csrf_token" id="targetCsrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">سال <span style="color:#e11d48">*</span></label>
                        <select name="year" id="tYear"
                                style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;">
                            <?= $yearOptions ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">ماه <span style="color:#e11d48">*</span></label>
                        <select name="month" id="tMonth"
                                style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;">
                            <?php foreach ($jalaliMonths as $mNum => $mName): if (!$mNum) continue; ?>
                                <option value="<?= $mNum ?>" <?= ($mNum === $currentJMonth) ? 'selected' : '' ?>><?= $mName ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">کارشناس فروش <span style="color:#e11d48">*</span></label>
                    <select name="target_user_id" id="tUserId"
                            style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;">
                        <option value="">— انتخاب کارشناس —</option>
                    </select>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">مبلغ هدف (ریال) <span style="color:#e11d48">*</span></label>
                    <input type="text" name="target_amount" id="tAmount" placeholder="مثلاً: 5000000000"
                           style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;box-sizing:border-box;">
                    <div id="tAmountFormatted" style="margin-top:4px;font-size:0.82rem;color:#6b7280;"></div>
                </div>

                <div style="margin-bottom:24px;">
                    <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">یادداشت</label>
                    <textarea name="notes" id="tNotes" rows="2"
                              style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;resize:vertical;box-sizing:border-box;"></textarea>
                </div>

                <div style="display:flex;gap:10px;">
                    <button type="submit" class="fin-btn blue" id="btnSaveTarget">💾 ذخیره هدف</button>
                    <button type="button" class="fin-btn" style="background:#f1f5f9;color:#374151;" onclick="resetTargetForm()">🔄 پاک کردن</button>
                </div>
            </form>
        </div>

        <!-- جدول اهداف ثبت‌شده -->
        <div class="fin-panel" style="padding:0;overflow:hidden;margin-top:16px;">
            <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
                <span style="font-weight:700;font-size:1rem;color:#1e293b;">اهداف ثبت‌شده در این دوره</span>
            </div>
            <div style="overflow-x:auto;">
                <table class="fin-table" id="manageTable">
                    <thead>
                        <tr>
                            <th>کارشناس</th>
                            <th>هدف (ریال)</th>
                            <th>فروش واقعی (ریال)</th>
                            <th>درصد</th>
                            <th>یادداشت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody id="manageBody">
                        <tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">ابتدا دوره را انتخاب و بارگذاری کنید.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /sectionManage -->

</div><!-- /inner -->
</div><!-- /main-content -->

<!-- ==================== Drawer هدف سریع ==================== -->
<div class="fin-drawer-overlay" id="targetOverlay" onclick="closeTargetDrawer()"></div>
<div class="fin-drawer" id="targetDrawer" style="width:400px;">
    <div class="fin-drawer-header">
        <span>تعیین هدف فروش</span>
        <button class="fin-drawer-close" onclick="closeTargetDrawer()">✕</button>
    </div>
    <div class="fin-drawer-body" style="padding:24px;">
        <p style="color:#6b7280;font-size:0.88rem;margin:0 0 16px;">برای ثبت هدف به تب «مدیریت اهداف» مراجعه کنید یا از فرم زیر استفاده نمایید.</p>
        <button class="fin-btn blue" style="width:100%;" onclick="closeTargetDrawer();switchTab('manage');">⚙ رفتن به مدیریت اهداف</button>
    </div>
</div>

<script>
(function () {
    'use strict';

    var csrfToken    = '<?= addslashes($csrfToken) ?>';
    var currentTab   = 'dashboard';
    var allUsers     = [];

    // ---- تبدیل تب ----
    window.switchTab = function (tab) {
        currentTab = tab;
        $('#tabDashboard').toggleClass('active', tab === 'dashboard');
        $('#tabManage').toggleClass('active', tab === 'manage');
        $('#sectionDashboard').toggle(tab === 'dashboard');
        $('#sectionManage').toggle(tab === 'manage');
        if (tab === 'manage') loadUsers();
    };

    // ---- بارگذاری داده ----
    window.loadTargets = function () {
        var year  = parseInt($('#filterYear').val());
        var month = parseInt($('#filterMonth').val());

        $('#targetsBody').html('<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">در حال بارگذاری...</td></tr>');

        $.post('crm_targets.php', {
            action: 'get_targets',
            year: year,
            month: month
        }, function (res) {
            if (!res.ok) { showMsg(res.msg, 'error'); return; }

            var months = ['','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
            $('#periodLabel').text(res.period.month_name + ' ' + res.period.year);

            // آمار کلی
            var s = res.summary;
            $('#sumTarget').text(s.target_fmt);
            $('#sumAchieve').text(s.achieve_fmt);
            $('#sumPct').text(s.total_pct + '%');
            $('#sumRemaining').text(s.remaining_fmt);
            var barW = Math.min(100, s.total_pct);
            var barColor = s.total_pct >= 80 ? '#22c55e' : (s.total_pct >= 50 ? '#f59e0b' : '#ef4444');
            $('#sumPctBar').css({ width: barW + '%', background: barColor });

            // جدول داشبورد
            renderDashboard(res.rows);
            // جدول مدیریت
            renderManageTable(res.rows);
        }, 'json').fail(function () { showMsg('خطا در ارتباط با سرور', 'error'); });
    };

    function renderDashboard(rows) {
        if (!rows.length) {
            $('#targetsBody').html('<tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">هیچ داده‌ای یافت نشد.</td></tr>');
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            var barW     = Math.min(100, r.pct);
            var barColor = r.pct >= 80 ? '#22c55e' : (r.pct >= 50 ? '#f59e0b' : '#ef4444');
            var badgeCls = r.pct >= 80 ? 'green' : (r.pct >= 50 ? 'amber' : 'rose');
            var badgeTxt = r.pct >= 80 ? '✅ عالی' : (r.pct >= 50 ? '🔶 متوسط' : '🔴 ضعیف');
            var gapClass = r.gap <= 0 ? 'color:#16a34a' : 'color:#dc2626';

            html += '<tr>' +
                '<td><strong>' + escHtml(r.fullname) + '</strong>' +
                    (r.role_name ? '<br><small style="color:#94a3b8;font-size:0.75rem;">' + escHtml(r.role_name) + '</small>' : '') +
                '</td>' +
                '<td style="text-align:left;direction:ltr;">' + (r.target > 0 ? r.target_fmt : '<span style="color:#cbd5e1">—</span>') + '</td>' +
                '<td style="text-align:left;direction:ltr;">' + (r.achieved > 0 ? r.achieved_fmt : '<span style="color:#cbd5e1">۰</span>') + '</td>' +
                '<td style="text-align:left;direction:ltr;' + gapClass + '">' +
                    (r.target > 0 ? (r.gap > 0 ? '-' : '+') + r.gap_fmt : '—') +
                '</td>' +
                '<td style="text-align:center;font-weight:700;' + (r.target > 0 ? '' : 'color:#cbd5e1') + '">' +
                    (r.target > 0 ? r.pct + '%' : '—') +
                '</td>' +
                '<td style="min-width:140px;">' +
                    (r.target > 0 ?
                        '<div style="background:#f1f5f9;border-radius:6px;height:10px;overflow:hidden;">' +
                            '<div style="height:100%;background:' + barColor + ';border-radius:6px;width:' + barW + '%;transition:width .5s;"></div>' +
                        '</div>' +
                        '<small style="color:#94a3b8;font-size:0.75rem;">' + r.pct + '% از هدف</small>'
                        : '<span style="color:#cbd5e1;font-size:0.82rem;">هدف تعریف نشده</span>'
                    ) +
                '</td>' +
                '<td><span class="fin-badge ' + badgeCls + '">' + (r.target > 0 ? badgeTxt : 'بدون هدف') + '</span></td>' +
                '</tr>';
        });
        $('#targetsBody').html(html);
    }

    function renderManageTable(rows) {
        if (!rows.length) {
            $('#manageBody').html('<tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">هیچ داده‌ای یافت نشد.</td></tr>');
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            html += '<tr>' +
                '<td>' + escHtml(r.fullname) + '</td>' +
                '<td style="text-align:left;direction:ltr;">' + (r.target > 0 ? r.target_fmt : '—') + '</td>' +
                '<td style="text-align:left;direction:ltr;">' + r.achieved_fmt + '</td>' +
                '<td style="text-align:center;">' + (r.target > 0 ? r.pct + '%' : '—') + '</td>' +
                '<td style="font-size:0.82rem;color:#6b7280;">' + escHtml(r.notes || '') + '</td>' +
                '<td>' +
                    '<button class="fin-btn" style="padding:4px 10px;font-size:0.8rem;background:#eff6ff;color:#2563eb;" ' +
                    'onclick="prefillEdit(' + r.user_id + ',' + JSON.stringify(r.target) + ',' + JSON.stringify(r.notes || '') + ')">' +
                    '✏ ویرایش</button>' +
                '</td>' +
                '</tr>';
        });
        $('#manageBody').html(html);
    }

    // ---- پر کردن فرم برای ویرایش ----
    window.prefillEdit = function (userId, target, notes) {
        switchTab('manage');
        $('#tUserId').val(userId);
        $('#tAmount').val(target);
        updateAmountLabel(target);
        $('#tNotes').val(notes || '');
        $('html,body').animate({ scrollTop: $('#targetForm').offset().top - 80 }, 300);
    };

    // ---- بارگذاری کاربران ----
    function loadUsers() {
        if (allUsers.length) { renderUserSelect(); return; }
        $.post('crm_targets.php', { action: 'get_users' }, function (res) {
            if (res.ok) {
                allUsers = res.users;
                renderUserSelect();
            }
        }, 'json');
    }

    function renderUserSelect() {
        var html = '<option value="">— انتخاب کارشناس —</option>';
        allUsers.forEach(function (u) {
            html += '<option value="' + u.id + '">' + escHtml(u.fullname) + '</option>';
        });
        $('#tUserId').html(html);
    }

    // ---- ذخیره هدف ----
    $('#targetForm').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serialize() + '&csrf_token=' + encodeURIComponent(csrfToken);
        $('#btnSaveTarget').prop('disabled', true).text('در حال ذخیره...');
        $.ajax({
            url: 'crm_targets.php',
            type: 'POST',
            data: data,
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                $('#btnSaveTarget').prop('disabled', false).text('💾 ذخیره هدف');
                showMsg(res.msg, res.ok ? 'success' : 'error');
                if (res.ok) loadTargets();
            },
            error: function () {
                $('#btnSaveTarget').prop('disabled', false).text('💾 ذخیره هدف');
                showMsg('خطا در ارتباط با سرور', 'error');
            }
        });
    });

    // ---- نمایش مبلغ فرمت‌شده ----
    function updateAmountLabel(val) {
        var num = parseInt(String(val).replace(/,/g,'')) || 0;
        if (num > 0) {
            $('#tAmountFormatted').text('معادل: ' + num.toLocaleString('fa-IR') + ' ریال');
        } else {
            $('#tAmountFormatted').text('');
        }
    }

    $('#tAmount').on('input', function () { updateAmountLabel($(this).val()); });

    // ---- بازنشانی فرم ----
    window.resetTargetForm = function () {
        $('#targetForm')[0].reset();
        $('#tAmountFormatted').text('');
    };

    // ---- Drawer ----
    window.openTargetDrawer = function () {
        $('#targetOverlay').addClass('active');
        $('#targetDrawer').addClass('open');
    };
    window.closeTargetDrawer = function () {
        $('#targetOverlay').removeClass('active');
        $('#targetDrawer').removeClass('open');
    };

    // ---- Sync فیلتر دوره با فرم مدیریت ----
    $('#filterYear').on('change', function () { $('#tYear').val($(this).val()); });
    $('#filterMonth').on('change', function () { $('#tMonth').val($(this).val()); });

    // ---- توابع کمکی ----
    window.showMsg = function (msg, type) {
        var bg = type === 'success' ? '#22c55e' : '#ef4444';
        var el = $('<div>').text(msg).css({
            position: 'fixed', top: '20px', left: '50%', transform: 'translateX(-50%)',
            background: bg, color: '#fff', padding: '10px 24px', borderRadius: '8px',
            fontFamily: 'Vazirmatn,Tahoma,sans-serif', fontSize: '0.95rem', zIndex: 9999,
            boxShadow: '0 4px 12px rgba(0,0,0,0.15)'
        }).appendTo('body');
        setTimeout(function () { el.fadeOut(300, function () { el.remove(); }); }, 3000);
    };

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ---- بارگذاری اولیه ----
    loadTargets();

})();
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
