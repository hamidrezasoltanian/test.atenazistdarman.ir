<?php
/*
 * فایل: public_html/admin/mission_reports.php
 * توضیحات: داشبورد گزارشات پیشرفته ماموریت‌ها + ویجت‌های تفکیکی پرداخت
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// -------------------------------------------------------------
// بررسی سطح دسترسی داینامیک
// -------------------------------------------------------------
$hasAccess = false;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $hasAccess = true;
} elseif (function_exists('hasPermission') && hasPermission('rep_missions')) {
    $hasAccess = true;
}

if (!$hasAccess) {
    die('<div style="text-align:center; margin-top:50px; font-family:Tahoma; color:red; font-weight:bold;">⛔ شما دسترسی لازم برای مشاهده گزارشات کلان را ندارید.</div>');
}

// -------------------------------------------------------------
// پردازش فیلتر تاریخ
// -------------------------------------------------------------
$startDateJalali = $_GET['start_date'] ?? '';
$endDateJalali = $_GET['end_date'] ?? '';

$whereReq = "1=1"; 
$whereItem = "1=1"; 
$paramsReq = [];
$paramsItem = [];

if (!empty($startDateJalali)) {
    $enStart = faToEn($startDateJalali);
    $p = explode('/', $enStart);
    if (count($p) == 3) {
        $gStart = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2])) . ' 00:00:00';
        $whereReq .= " AND r.created_at >= ?";
        $whereItem .= " AND i.mission_date >= ?";
        $paramsReq[] = $gStart;
        $paramsItem[] = substr($gStart, 0, 10);
    }
}

if (!empty($endDateJalali)) {
    $enEnd = faToEn($endDateJalali);
    $p = explode('/', $enEnd);
    if (count($p) == 3) {
        $gEnd = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2])) . ' 23:59:59';
        $whereReq .= " AND r.created_at <= ?";
        $whereItem .= " AND i.mission_date <= ?";
        $paramsReq[] = $gEnd;
        $paramsItem[] = substr($gEnd, 0, 10);
    }
}

// -------------------------------------------------------------
// کوئری‌های استخراج آمار
// -------------------------------------------------------------

// 1. قیف وضعیت ماموریت‌ها
$funnelData = [];
$stmtFunnel = $pdo->prepare("SELECT r.status, COUNT(r.id) as count FROM mission_requests r WHERE $whereReq GROUP BY r.status");
$stmtFunnel->execute($paramsReq);
while ($row = $stmtFunnel->fetch(PDO::FETCH_ASSOC)) {
    $funnelData[$row['status']] = (int)$row['count'];
}

// آمارهای کلیدی (KPIs) شامل تایید شده، پرداخت شده و پرداخت نشده
$stmtKpi = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN e.status IN ('approved', 'paid') THEN COALESCE(e.approved_amount, e.amount) ELSE 0 END) as total_approved_cost,
        SUM(CASE WHEN e.status = 'paid' THEN COALESCE(e.approved_amount, e.amount) ELSE 0 END) as total_paid_cost,
        SUM(CASE WHEN e.status = 'approved' THEN COALESCE(e.approved_amount, e.amount) ELSE 0 END) as total_unpaid_cost,
        COUNT(DISTINCT r.id) as total_missions 
    FROM mission_expenses e JOIN mission_requests r ON e.request_id = r.id 
    WHERE e.status IN ('approved', 'paid') AND $whereReq
");
$stmtKpi->execute($paramsReq);
$kpiData = $stmtKpi->fetch(PDO::FETCH_ASSOC);

$totalApprovedCost = (float)($kpiData['total_approved_cost'] ?? 0);
$totalPaidCost = (float)($kpiData['total_paid_cost'] ?? 0);
$totalUnpaidCost = (float)($kpiData['total_unpaid_cost'] ?? 0);
$totalMissionsWithCost = (int)($kpiData['total_missions'] ?? 0);
$avgCostPerMission = $totalMissionsWithCost > 0 ? ($totalApprovedCost / $totalMissionsWithCost) : 0;

// 2. پرهزینه‌ترین کارمندان
$stmtEmp = $pdo->prepare("
    SELECT u.first_name, u.last_name, u.personnel_code, COUNT(DISTINCT r.id) as mission_count, SUM(COALESCE(e.approved_amount, e.amount)) as total_cost
    FROM mission_expenses e JOIN mission_requests r ON e.request_id = r.id JOIN users u ON r.user_id = u.id
    WHERE e.status IN ('approved', 'paid') AND $whereReq GROUP BY u.id ORDER BY total_cost DESC LIMIT 20
");
$stmtEmp->execute($paramsReq);
$topCostlyEmployees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

// 3. پربازدیدترین مشتریان
$stmtCus = $pdo->prepare("
    SELECT c.id, c.company_name, COUNT(i.id) as visit_count FROM mission_items i JOIN customers c ON i.customer_id = c.id
    WHERE $whereItem GROUP BY c.id ORDER BY visit_count DESC LIMIT 20
");
$stmtCus->execute($paramsItem);
$topCustomers = $stmtCus->fetchAll(PDO::FETCH_ASSOC);

// 4. مجموع هزینه‌های سازمان به تفکیک دپارتمان
$stmtDept = $pdo->prepare("
    SELECT d.name as dept_name, SUM(COALESCE(e.approved_amount, e.amount)) as total_cost FROM mission_expenses e JOIN mission_requests r ON e.request_id = r.id JOIN departments d ON r.department_id = d.id
    WHERE e.status IN ('approved', 'paid') AND $whereReq GROUP BY d.id ORDER BY total_cost DESC
");
$stmtDept->execute($paramsReq);
$deptCosts = $stmtDept->fetchAll(PDO::FETCH_ASSOC);

// 5. پراکندگی انواع ماموریت
$stmtTypes = $pdo->prepare("
    SELECT t.title as type_name, COUNT(i.id) as type_count FROM mission_items i JOIN mission_types t ON i.type_id = t.id
    WHERE $whereItem GROUP BY t.id ORDER BY type_count DESC
");
$stmtTypes->execute($paramsItem);
$missionTypesDist = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);

// 6. پراکندگی برچسب‌های مشتریان
$stmtTags = $pdo->prepare("
    SELECT tg.title as tag_name, COUNT(i.id) as tag_count FROM mission_items i JOIN customer_tag_links ctl ON i.customer_id = ctl.customer_id JOIN tags tg ON ctl.tag_id = tg.id
    WHERE $whereItem GROUP BY tg.id ORDER BY tag_count DESC LIMIT 15
");
$stmtTags->execute($paramsItem);
$tagsDist = $stmtTags->fetchAll(PDO::FETCH_ASSOC);

// 7. روند زمانی ماموریت‌ها (Trend)
$stmtTrend = $pdo->prepare("
    SELECT DATE(i.mission_date) as m_date, COUNT(i.id) as m_count FROM mission_items i
    WHERE $whereItem AND i.mission_date IS NOT NULL GROUP BY m_date ORDER BY m_date ASC
");
$stmtTrend->execute($paramsItem);
$trendData = $stmtTrend->fetchAll(PDO::FETCH_ASSOC);

// 8. تفکیک نوع هزینه‌ها
$stmtExpType = $pdo->prepare("
    SELECT e.expense_type, SUM(COALESCE(e.approved_amount, e.amount)) as total_cost FROM mission_expenses e JOIN mission_requests r ON e.request_id = r.id
    WHERE e.status IN ('approved', 'paid') AND $whereReq GROUP BY e.expense_type
");
$stmtExpType->execute($paramsReq);
$expTypeData = $stmtExpType->fetchAll(PDO::FETCH_ASSOC);

// 9. پراکندگی جغرافیایی (استان‌ها)
$stmtGeo = $pdo->prepare("
    SELECT c.state, COUNT(i.id) as count FROM mission_items i JOIN customers c ON i.customer_id = c.id
    WHERE $whereItem AND c.state IS NOT NULL AND c.state != '' GROUP BY c.state ORDER BY count DESC LIMIT 15
");
$stmtGeo->execute($paramsItem);
$geoData = $stmtGeo->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// خروجی اکسل
// -------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    while (ob_get_level()) ob_end_clean();
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Mission_Reports_" . date('Ymd') . ".xls");
    header("Pragma: no-cache"); header("Expires: 0");
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>';
    echo '<body dir="rtl" style="font-family: Tahoma, sans-serif;">';
    echo '<h2>گزارش جامع ماموریت‌ها</h2>';
    if ($startDateJalali || $endDateJalali) echo '<p>بازه گزارش: ' . ($startDateJalali ?: 'ابتدا') . ' تا ' . ($endDateJalali ?: 'اکنون') . '</p>';
    
    echo '<h3>پرهزینه‌ترین کارمندان</h3>';
    echo '<table border="1" cellpadding="5"><tr><th>نام کارمند</th><th>تعداد ماموریت</th><th>مجموع هزینه تایید شده (ریال)</th></tr>';
    foreach($topCostlyEmployees as $emp) echo '<tr><td>'.$emp['first_name'].' '.$emp['last_name'].'</td><td>'.$emp['mission_count'].'</td><td>'.$emp['total_cost'].'</td></tr>';
    echo '</table><br><h3>جدول مشتریان با بیشترین ماموریت</h3>';
    echo '<table border="1" cellpadding="5"><tr><th>نام مشتری</th><th>دفعات مراجعه</th></tr>';
    foreach($topCustomers as $cus) echo '<tr><td>'.$cus['company_name'].'</td><td>'.$cus['visit_count'].'</td></tr>';
    echo '</table><br><h3>هزینه به تفکیک دپارتمان</h3>';
    echo '<table border="1" cellpadding="5"><tr><th>دپارتمان</th><th>مجموع هزینه (ریال)</th></tr>';
    foreach($deptCosts as $dc) echo '<tr><td>'.($dc['dept_name'] ?? 'بدون دپارتمان').'</td><td>'.$dc['total_cost'].'</td></tr>';
    echo '</table></body></html>';
    exit;
}

$pageTitle = 'گزارشات جامع ماموریت‌ها';
$basePath = '../';
$vendorPath = __DIR__ . '/../../vendor/kamaDatepicker/';
$cssContent = '';
if (file_exists($vendorPath . 'kamadatepicker.min.css')) $cssContent = file_get_contents($vendorPath . 'kamadatepicker.min.css');

$extraCss = '<style>' . $cssContent . '</style>';
$extraCss .= '<style>
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:20px; }
    .report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 20px; margin-bottom: 20px;}
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px; }
    .stat-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:15px; text-align:center; display:flex; flex-direction:column; justify-content:center; position:relative; overflow:hidden;}
    .stat-card::after { content:""; position:absolute; bottom:0; left:0; width:100%; height:4px; opacity:0.8;}
    .stat-value { font-size: 1.6rem; font-weight: bold; margin-bottom: 5px; }
    .stat-label { font-size: 0.8rem; color: #64748b; font-weight: 600; }
    .kpi-total { border: 1px solid #bbf7d0; background: #f0fdf4; } .kpi-total .stat-value { color: #166534; } .kpi-total::after { background: #166534; }
    .kpi-unpaid { border: 1px solid #fca5a5; background: #fef2f2; } .kpi-unpaid .stat-value { color: #991b1b; } .kpi-unpaid::after { background: #991b1b; }
    .kpi-paid { border: 1px solid #93c5fd; background: #eff6ff; } .kpi-paid .stat-value { color: #1e40af; } .kpi-paid::after { background: #1e40af; }
    .kpi-avg { border: 1px solid #bae6fd; background: #f0f9ff; } .kpi-avg .stat-value { color: #0369a1; } .kpi-avg::after { background: #0369a1; }
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    th, td { text-align:right; padding:10px; border-bottom:1px solid #f1f5f9; font-size:0.85rem; }
    th { color:#6b7280; font-weight:600; background-color: #f8fafc; position: sticky; top: 0; z-index: 10; }
    .chart-container { position: relative; height: 320px; width: 100%; }
    .filter-bar { display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end; background:#f8fafc; padding:15px; border-radius:10px; border:1px solid #e2e8f0; margin-bottom:20px; }
    .filter-item { display:flex; flex-direction:column; gap:5px; }
    .filter-item label { font-size:0.8rem; color:#4b5563; font-weight:bold; }
    .filter-item input { border:1px solid #d1d5db; border-radius:6px; padding:8px 12px; font-family:"Vazirmatn", Tahoma; width:150px; }
    @media print {
        @page { size: A4 landscape; margin: 1.5cm; }
        .main-header, .sidebar, .page-actions, .filter-bar { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        .content-wrapper { padding: 0 !important; max-width: 100% !important; }
        .report-grid, .kpi-grid { display: flex; flex-wrap: wrap; }
        .card-box { border: 1px solid #000; box-shadow: none; break-inside: avoid; page-break-inside: avoid; margin-bottom: 20px; width: 48%; margin-left:1%; margin-right:1%; }
        .kpi-grid .stat-card { width: 23%; margin: 5px; }
        body { background: #fff; font-size: 10pt; }
        .chart-container { height: 250px; page-break-inside: avoid; }
        .table-responsive { max-height: none !important; overflow: visible !important; }
        th { position: static; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">📊 داشبورد و گزارشات کلان هوش تجاری</span></div>
            <div style="display:flex; gap:10px;">
                <form method="GET" target="_blank" style="margin:0;">
                    <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDateJalali); ?>">
                    <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDateJalali); ?>">
                    <input type="hidden" name="export" value="excel">
                    <button type="submit" class="btn btn-success" style="background:#10b981; border:none;">📥 خروجی اکسل جداول</button>
                </form>
                <button onclick="window.print()" class="btn btn-secondary">🖨️ چاپ داشبورد</button>
            </div>
        </div>

        <form method="GET" class="filter-bar page-actions">
            <div class="filter-item">
                <label>از تاریخ (شمسی):</label>
                <input type="text" name="start_date" id="pd_start" autocomplete="off" value="<?php echo htmlspecialchars($startDateJalali); ?>" placeholder="مثال: 1403/01/01">
            </div>
            <div class="filter-item">
                <label>تا تاریخ (شمسی):</label>
                <input type="text" name="end_date" id="pd_end" autocomplete="off" value="<?php echo htmlspecialchars($endDateJalali); ?>" placeholder="مثال: 1403/12/29">
            </div>
            <div class="filter-item" style="flex-direction:row; align-items:center; padding-bottom:2px;">
                <button type="submit" class="btn btn-primary">اعمال فیلتر</button>
                <?php if($startDateJalali || $endDateJalali): ?>
                    <a href="mission_reports.php" class="btn btn-outline">حذف فیلتر</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="kpi-grid">
            <div class="stat-card kpi-total">
                <div class="stat-value"><?php echo number_format($totalApprovedCost); ?></div>
                <div class="stat-label">💰 کل تایید شده (ریال)</div>
            </div>
            <div class="stat-card kpi-paid">
                <div class="stat-value"><?php echo number_format($totalPaidCost); ?></div>
                <div class="stat-label">✅ پرداخت شده (ریال)</div>
            </div>
            <div class="stat-card kpi-unpaid">
                <div class="stat-value"><?php echo number_format($totalUnpaidCost); ?></div>
                <div class="stat-label">⏳ پرداخت نشده (ریال)</div>
            </div>
            <div class="stat-card kpi-avg">
                <div class="stat-value"><?php echo number_format($avgCostPerMission); ?></div>
                <div class="stat-label">📈 میانگین هر ماموریت</div>
            </div>
            
            <div class="stat-card" style="border-color: #fde68a;">
                <div class="stat-value text-warning"><?php echo $funnelData['pending_admin'] ?? 0; ?></div>
                <div class="stat-label">در انتظار تایید مدیر</div>
            </div>
            <div class="stat-card" style="border-color: #bae6fd;">
                <div class="stat-value text-info"><?php echo $funnelData['expenses_open'] ?? 0; ?></div>
                <div class="stat-label">در حال ثبت هزینه توسط پرسنل</div>
            </div>
            <div class="stat-card" style="border-color: #e9d5ff;">
                <div class="stat-value" style="color:#9333ea;"><?php echo ($funnelData['finance_review'] ?? 0) + ($funnelData['expenses_submitted'] ?? 0); ?></div>
                <div class="stat-label">در صف بررسی مالی</div>
            </div>
            <div class="stat-card" style="border-color: #bbf7d0;">
                <div class="stat-value text-success"><?php echo $funnelData['completed'] ?? 0; ?></div>
                <div class="stat-label">پایان یافته</div>
            </div>
        </div>

        <div class="report-grid">
            <div class="card-box">
                <h3 style="font-size:1.1rem; color:#991b1b; margin-bottom:15px; border-bottom:2px solid #fecaca; padding-bottom:10px;">💸 جدول پرهزینه‌ترین کارمندان (Top 20)</h3>
                <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr><th>رتبه</th><th>نام کارمند</th><th>تعداد ماموریت</th><th>هزینه (ریال)</th></tr>
                        </thead>
                        <tbody>
                            <?php if(empty($topCostlyEmployees)): ?>
                                <tr><td colspan="4" style="text-align:center;">داده‌ای موجود نیست.</td></tr>
                            <?php else: foreach($topCostlyEmployees as $index => $emp): ?>
                                <tr>
                                    <td><strong>#<?php echo $index + 1; ?></strong></td>
                                    <td style="font-weight:bold; color:#1f2937;"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></td>
                                    <td><?php echo $emp['mission_count']; ?></td>
                                    <td style="color:#166534; font-weight:bold;"><?php echo number_format($emp['total_cost']); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-box">
                <h3 style="font-size:1.1rem; color:#0369a1; margin-bottom:15px; border-bottom:2px solid #bae6fd; padding-bottom:10px;">🏢 جدول مشتریان با بیشترین ماموریت (Top 20)</h3>
                <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr><th>رتبه</th><th>نام طرف حساب / شرکت</th><th>دفعات مراجعه پرسنل</th></tr>
                        </thead>
                        <tbody>
                            <?php if(empty($topCustomers)): ?>
                                <tr><td colspan="3" style="text-align:center;">داده‌ای موجود نیست.</td></tr>
                            <?php else: foreach($topCustomers as $index => $cus): ?>
                                <tr>
                                    <td><strong>#<?php echo $index + 1; ?></strong></td>
                                    <td style="font-weight:bold;">
                                        <a href="customer_profile.php?id=<?php echo $cus['id']; ?>" style="color:#1e40af; text-decoration:none; border-bottom:1px dashed #1e40af; transition: 0.2s;" onmouseover="this.style.color='#2563eb'" onmouseout="this.style.color='#1e40af'">
                                            <?php echo htmlspecialchars($cus['company_name']); ?>
                                        </a>
                                    </td>
                                    <td><span style="background:#eff6ff; padding:2px 8px; border-radius:10px; font-weight:bold; color:#1d4ed8;"><?php echo $cus['visit_count']; ?> بار</span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="report-grid">
            <div class="card-box">
                <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">🏷️ پراکندگی برچسب‌های مشتریان</h3>
                <div class="chart-container"><canvas id="tagsChart"></canvas></div>
            </div>
            <div class="card-box">
                <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">📈 روند زمانی سفرهای پرسنل</h3>
                <div class="chart-container"><canvas id="trendChart"></canvas></div>
            </div>
        </div>

        <div class="report-grid">
            <div class="card-box">
                <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">💳 تفکیک منابع مالی خرج شده</h3>
                <div class="chart-container"><canvas id="expenseTypeChart"></canvas></div>
            </div>
            <div class="card-box">
                <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">🏢 سهم هزینه‌ها به تفکیک دپارتمان</h3>
                <div class="chart-container"><canvas id="deptCostChart"></canvas></div>
            </div>
        </div>

        <div class="report-grid">
            <div class="card-box">
                <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">🗺️ پراکندگی جغرافیایی (استان‌ها)</h3>
                <div class="chart-container"><canvas id="geoChart"></canvas></div>
            </div>
            <div class="card-box">
                <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">🎯 پراکندگی انواع ماموریت</h3>
                <div class="chart-container"><canvas id="missionTypeChart"></canvas></div>
            </div>
        </div>

    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    <?php 
    if (file_exists($vendorPath . 'kamadatepicker.min.js')) echo file_get_contents($vendorPath . 'kamadatepicker.min.js');
    echo "\n";
    if (file_exists($vendorPath . 'kamadatepicker.holidays.js')) echo file_get_contents($vendorPath . 'kamadatepicker.holidays.js');
    ?>
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    
    if (typeof kamaDatepicker === 'function') {
        const pickerOptions = {
            buttonsColor: "#2563eb", forceFarsiDigits: true, markToday: true, markHolidays: true,
            highlightSelectedDay: true, sync: true, gotoToday: true, twodigit: true
        };
        kamaDatepicker('pd_start', pickerOptions);
        kamaDatepicker('pd_end', pickerOptions);
    }

    const bgColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#6366f1', '#ec4899', '#14b8a6', '#f43f5e', '#84cc16', '#0ea5e9', '#d946ef', '#f97316', '#06b6d4'];
    Chart.defaults.font.family = 'Vazirmatn, Tahoma, sans-serif';

    const tagLabels = []; const tagData = [];
    <?php foreach($tagsDist as $tg): ?>
        tagLabels.push("<?php echo htmlspecialchars($tg['tag_name']); ?>");
        tagData.push(<?php echo (int)$tg['tag_count']; ?>);
    <?php endforeach; ?>
    if(document.getElementById('tagsChart') && tagData.length > 0) {
        new Chart(document.getElementById('tagsChart').getContext('2d'), {
            type: 'doughnut',
            data: { labels: tagLabels, datasets: [{ data: tagData, backgroundColor: bgColors, borderWidth: 2, borderColor: '#fff' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'left' } } }
        });
    }

    const trendLabels = []; const trendData = [];
    <?php foreach($trendData as $tr): 
        $jD = function_exists('jdate') ? jdate('m/d', strtotime($tr['m_date'])) : $tr['m_date'];
    ?>
        trendLabels.push("<?php echo $jD; ?>");
        trendData.push(<?php echo (int)$tr['m_count']; ?>);
    <?php endforeach; ?>
    if(document.getElementById('trendChart') && trendData.length > 0) {
        new Chart(document.getElementById('trendChart').getContext('2d'), {
            type: 'line',
            data: { 
                labels: trendLabels, 
                datasets: [{ label: 'تعداد سفرها در روز', data: trendData, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.1)', fill: true, tension: 0.3, pointRadius: 4 }] 
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    const deptLabels = []; const deptData = [];
    <?php foreach($deptCosts as $dc): ?>
        deptLabels.push("<?php echo htmlspecialchars($dc['dept_name'] ?? 'بدون دپارتمان'); ?>");
        deptData.push(<?php echo (float)$dc['total_cost']; ?>);
    <?php endforeach; ?>
    if(document.getElementById('deptCostChart') && deptData.length > 0) {
        new Chart(document.getElementById('deptCostChart').getContext('2d'), {
            type: 'bar',
            data: { labels: deptLabels, datasets: [{ label: 'مجموع هزینه (ریال)', data: deptData, backgroundColor: '#3b82f6', borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
    }

    const expTypeMap = { 'personal': 'هزینه شخصی', 'org_snap': 'اسنپ سازمانی', 'discount_code': 'کد تخفیف' };
    const expLabels = []; const expData = [];
    <?php foreach($expTypeData as $et): ?>
        expLabels.push(expTypeMap["<?php echo $et['expense_type']; ?>"] || "نامشخص");
        expData.push(<?php echo (float)$et['total_cost']; ?>);
    <?php endforeach; ?>
    if(document.getElementById('expenseTypeChart') && expData.length > 0) {
        new Chart(document.getElementById('expenseTypeChart').getContext('2d'), {
            type: 'pie',
            data: { labels: expLabels, datasets: [{ data: expData, backgroundColor: ['#10b981', '#f59e0b', '#8b5cf6'], borderWidth: 2, borderColor: '#fff' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'left' } } }
        });
    }

    const geoLabels = []; const geoData = [];
    <?php foreach($geoData as $gd): ?>
        geoLabels.push("<?php echo htmlspecialchars($gd['state']); ?>");
        geoData.push(<?php echo (int)$gd['count']; ?>);
    <?php endforeach; ?>
    if(document.getElementById('geoChart') && geoData.length > 0) {
        new Chart(document.getElementById('geoChart').getContext('2d'), {
            type: 'bar',
            data: { labels: geoLabels, datasets: [{ label: 'تعداد سفر', data: geoData, backgroundColor: '#14b8a6', borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    const typeLabels = []; const typeData = [];
    <?php foreach($missionTypesDist as $mt): ?>
        typeLabels.push("<?php echo htmlspecialchars($mt['type_name']); ?>");
        typeData.push(<?php echo (int)$mt['type_count']; ?>);
    <?php endforeach; ?>
    if(document.getElementById('missionTypeChart') && typeData.length > 0) {
        new Chart(document.getElementById('missionTypeChart').getContext('2d'), {
            type: 'doughnut',
            data: { labels: typeLabels, datasets: [{ data: typeData, backgroundColor: bgColors.slice().reverse(), borderWidth: 2, borderColor: '#fff' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'left' } } }
        });
    }

});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>