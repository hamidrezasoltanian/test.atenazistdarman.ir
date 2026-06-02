<?php
/*
 * فایل: public_html/admin/mission_detailed_reports.php
 * توضیحات: گزارشات ریز با فیلتر پرداخت شده و پرداخت نشده (تایید شده)
 */

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userRole = $_SESSION['role'] ?? '';
$allowedRoles = ['admin', 'management', 'manager', 'finance_manager', 'finance_expert'];
$isAllowed = false;
foreach ($allowedRoles as $r) {
    if ($userRole === $r || strpos($userRole, '_manager') !== false) {
        $isAllowed = true; break;
    }
}
if (!$isAllowed) die('<div style="text-align:center; margin-top:50px; font-family:Tahoma; color:red; font-weight:bold;">⛔ شما دسترسی لازم برای مشاهده این گزارشات را ندارید.</div>');

$usersList = $pdo->query("SELECT id, first_name, last_name FROM users ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$deptsList = $pdo->query("SELECT id, name FROM departments")->fetchAll(PDO::FETCH_ASSOC);
$typesList = $pdo->query("SELECT id, title FROM mission_types")->fetchAll(PDO::FETCH_ASSOC);
$tagsList  = $pdo->query("SELECT id, title FROM tags")->fetchAll(PDO::FETCH_ASSOC);
$statesList = $pdo->query("SELECT DISTINCT state FROM customers WHERE state IS NOT NULL AND state != '' ORDER BY state ASC")->fetchAll(PDO::FETCH_ASSOC);
$citiesList = $pdo->query("SELECT DISTINCT city FROM customers WHERE city IS NOT NULL AND city != '' ORDER BY city ASC")->fetchAll(PDO::FETCH_ASSOC);

$whereItem = "1=1"; 
$whereExp  = "1=1"; 
$paramsItem = [];
$paramsExp  = [];

$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';

if (!empty($startDate)) {
    $enStart = faToEn($startDate);
    $p = explode('/', $enStart);
    if (count($p) == 3) {
        $gStart = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));
        $whereItem .= " AND i.mission_date >= ?";
        $whereExp  .= " AND i.mission_date >= ?";
        $paramsItem[] = $gStart; $paramsExp[]  = $gStart;
    }
}
if (!empty($endDate)) {
    $enEnd = faToEn($endDate);
    $p = explode('/', $enEnd);
    if (count($p) == 3) {
        $gEnd = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));
        $whereItem .= " AND i.mission_date <= ?";
        $whereExp  .= " AND i.mission_date <= ?";
        $paramsItem[] = $gEnd; $paramsExp[]  = $gEnd;
    }
}

if (!empty($_GET['user_id'])) {
    $whereItem .= " AND r.user_id = ?"; $whereExp  .= " AND r.user_id = ?";
    $paramsItem[] = $_GET['user_id']; $paramsExp[]  = $_GET['user_id'];
}
if (!empty($_GET['department_id'])) {
    $whereItem .= " AND r.department_id = ?"; $whereExp  .= " AND r.department_id = ?";
    $paramsItem[] = $_GET['department_id']; $paramsExp[]  = $_GET['department_id'];
}
if (!empty($_GET['req_status'])) {
    $whereItem .= " AND r.status = ?"; $whereExp  .= " AND r.status = ?";
    $paramsItem[] = $_GET['req_status']; $paramsExp[]  = $_GET['req_status'];
}
if (!empty($_GET['type_id'])) {
    $whereItem .= " AND i.type_id = ?"; $whereExp  .= " AND i.type_id = ?";
    $paramsItem[] = $_GET['type_id']; $paramsExp[]  = $_GET['type_id'];
}
if (!empty($_GET['customer_id'])) {
    $whereItem .= " AND c.id = ?"; $whereExp  .= " AND c.id = ?";
    $paramsItem[] = $_GET['customer_id']; $paramsExp[]  = $_GET['customer_id'];
} elseif (!empty($_GET['customer_name'])) {
    $whereItem .= " AND c.company_name LIKE ?"; $whereExp  .= " AND c.company_name LIKE ?";
    $paramsItem[] = "%" . trim($_GET['customer_name']) . "%"; $paramsExp[]  = "%" . trim($_GET['customer_name']) . "%";
}
if (!empty($_GET['state'])) {
    $whereItem .= " AND c.state = ?"; $whereExp  .= " AND c.state = ?";
    $paramsItem[] = $_GET['state']; $paramsExp[]  = $_GET['state'];
}
if (!empty($_GET['city'])) {
    $whereItem .= " AND c.city = ?"; $whereExp  .= " AND c.city = ?";
    $paramsItem[] = $_GET['city']; $paramsExp[]  = $_GET['city'];
}
if (!empty($_GET['tag_id'])) {
    $tagFilter = " AND EXISTS (SELECT 1 FROM customer_tag_links ctl WHERE ctl.customer_id = c.id AND ctl.tag_id = ?)";
    $whereItem .= $tagFilter; $whereExp  .= $tagFilter;
    $paramsItem[] = $_GET['tag_id']; $paramsExp[]  = $_GET['tag_id'];
}
if (!empty($_GET['expense_type'])) {
    $whereExp .= " AND e.expense_type = ?";
    $paramsExp[] = $_GET['expense_type'];
}
if (!empty($_GET['expense_status'])) {
    $whereExp .= " AND e.status = ?";
    $paramsExp[] = $_GET['expense_status'];
}

$limit = 15; 
$pageM = isset($_GET['page_m']) ? max(1, (int)$_GET['page_m']) : 1;
$pageE = isset($_GET['page_e']) ? max(1, (int)$_GET['page_e']) : 1;
$offsetM = ($pageM - 1) * $limit;
$offsetE = ($pageE - 1) * $limit;
$isExport = (isset($_GET['export']) && $_GET['export'] == 'excel');

$stmtCountM = $pdo->prepare("SELECT COUNT(*) FROM mission_items i JOIN mission_requests r ON i.request_id = r.id LEFT JOIN customers c ON i.customer_id = c.id WHERE $whereItem");
$stmtCountM->execute($paramsItem);
$totalMissions = (int)$stmtCountM->fetchColumn();
$totalPagesM = ceil($totalMissions / $limit);

$limitClauseM = $isExport ? "" : " LIMIT $limit OFFSET $offsetM";
$sqlItems = "SELECT i.*, r.status as req_status, r.created_at, u.first_name, u.last_name, d.name as dept_name, c.id as customer_id, c.company_name, c.state, c.city, t.title as type_title, (SELECT GROUP_CONCAT(tg.title SEPARATOR '، ') FROM customer_tag_links ctl JOIN tags tg ON ctl.tag_id = tg.id WHERE ctl.customer_id = c.id) as tags_list FROM mission_items i JOIN mission_requests r ON i.request_id = r.id JOIN users u ON r.user_id = u.id LEFT JOIN departments d ON r.department_id = d.id LEFT JOIN customers c ON i.customer_id = c.id LEFT JOIN mission_types t ON i.type_id = t.id WHERE $whereItem ORDER BY i.mission_date DESC, i.id DESC $limitClauseM";
$stmtItems = $pdo->prepare($sqlItems);
$stmtItems->execute($paramsItem);
$missions = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$stmtCountE = $pdo->prepare("SELECT COUNT(*) FROM mission_expenses e JOIN mission_requests r ON e.request_id = r.id LEFT JOIN mission_items i ON e.item_id = i.id LEFT JOIN customers c ON i.customer_id = c.id WHERE $whereExp");
$stmtCountE->execute($paramsExp);
$totalExpenses = (int)$stmtCountE->fetchColumn();
$totalPagesE = ceil($totalExpenses / $limit);

$limitClauseE = $isExport ? "" : " LIMIT $limit OFFSET $offsetE";
$sqlExpenses = "SELECT e.*, r.status as req_status, u.first_name, u.last_name, c.company_name, t.title as type_title, i.mission_date FROM mission_expenses e JOIN mission_requests r ON e.request_id = r.id JOIN users u ON r.user_id = u.id LEFT JOIN mission_items i ON e.item_id = i.id LEFT JOIN customers c ON i.customer_id = c.id LEFT JOIN mission_types t ON i.type_id = t.id WHERE $whereExp ORDER BY e.id DESC $limitClauseE";
$stmtExp = $pdo->prepare($sqlExpenses);
$stmtExp->execute($paramsExp);
$expenses = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

$stmtSumE = $pdo->prepare("SELECT SUM(e.amount) as total_req, SUM(CASE WHEN e.status IN ('approved','paid') THEN COALESCE(e.approved_amount, e.amount) ELSE 0 END) as total_app FROM mission_expenses e JOIN mission_requests r ON e.request_id = r.id JOIN users u ON r.user_id = u.id LEFT JOIN mission_items i ON e.item_id = i.id LEFT JOIN customers c ON i.customer_id = c.id LEFT JOIN mission_types t ON i.type_id = t.id WHERE $whereExp");
$stmtSumE->execute($paramsExp);
$sumsE = $stmtSumE->fetch(PDO::FETCH_ASSOC);
$totalReqSum = (float)($sumsE['total_req'] ?? 0);
$totalAppSum = (float)($sumsE['total_app'] ?? 0);

function translate_mission_status($status) {
    $map = ['pending_admin' => 'در انتظار مدیر', 'expenses_open' => 'ثبت هزینه', 'expenses_submitted' => 'ارسال به مالی', 'finance_review' => 'بررسی مالی', 'completed' => 'پایان یافته', 'rejected' => 'رد شده'];
    return $map[$status] ?? $status;
}
function translate_exp_type($type) {
    $map = ['personal' => 'هزینه شخصی', 'org_snap' => 'اسنپ سازمانی', 'discount_code' => 'کد تخفیف'];
    return $map[$type] ?? $type;
}
function translate_exp_status($status) {
    $map = ['pending' => 'در انتظار', 'approved' => 'تایید شده (پرداخت نشده)', 'paid' => 'پرداخت شده', 'rejected' => 'رد شده'];
    return $map[$status] ?? $status;
}
function buildPageUrl($getArray, $pageParamName, $pageNum) {
    $getArray[$pageParamName] = $pageNum;
    unset($getArray['export']); 
    return '?' . http_build_query($getArray);
}

if ($isExport) {
    while (ob_get_level()) ob_end_clean();
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Detailed_Missions_Export_" . date('Ymd_His') . ".xls");
    header("Pragma: no-cache"); header("Expires: 0");
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>';
    echo '<body dir="rtl" style="font-family: Tahoma, sans-serif; font-size:12px;">';
    
    echo '<h2>گزارش ریز سفرهای ماموریت</h2>';
    echo '<table border="1" cellpadding="5"><thead><tr><th>ردیف</th><th>نام کارمند</th><th>دپارتمان</th><th>مشتری (طرف حساب)</th><th>استان/شهر</th><th>برچسب‌ها</th><th>نوع ماموریت</th><th>تاریخ ماموریت</th><th>شروع-پایان</th><th>وضعیت کل</th></tr></thead><tbody>';
    $rowNum = 1;
    foreach($missions as $m) {
        $loc = ($m['state'] ?? '-') . ' / ' . ($m['city'] ?? '-');
        $date = $m['mission_date'] ? jdate('Y/m/d', strtotime($m['mission_date'])) : '-';
        echo '<tr><td>'.$rowNum++.'</td><td>'.$m['first_name'].' '.$m['last_name'].'</td><td>'.($m['dept_name'] ?? '-').'</td><td>'.($m['company_name'] ?? '-').'</td><td>'.$loc.'</td><td>'.($m['tags_list'] ?? '-').'</td><td>'.($m['type_title'] ?? '-').'</td><td>'.$date.'</td><td>'.$m['start_time'].' - '.$m['end_time'].'</td><td>'.translate_mission_status($m['req_status']).'</td></tr>';
    }
    echo '</tbody></table><br><br>';

    echo '<h2>گزارش ریز هزینه‌های ماموریت</h2>';
    echo '<table border="1" cellpadding="5"><thead><tr><th>ردیف</th><th>کارمند</th><th>مشتری مرتبط</th><th>نوع هزینه</th><th>مبلغ درخواستی (ریال)</th><th>مبلغ تاییدی (ریال)</th><th>وضعیت هزینه</th><th>تاریخ پرداخت</th><th>یادداشت مالی</th></tr></thead><tbody>';
    $rowNum = 1;
    foreach($expenses as $e) {
        $approved = $e['approved_amount'] !== null ? $e['approved_amount'] : $e['amount'];
        if($e['status'] === 'rejected') $approved = 0;
        $paidDate = $e['paid_at'] ? jdate('Y/m/d H:i', strtotime($e['paid_at'])) : '-';
        
        echo '<tr><td>'.$rowNum++.'</td><td>'.$e['first_name'].' '.$e['last_name'].'</td><td>'.($e['company_name'] ?? '-').'</td><td>'.translate_exp_type($e['expense_type']).'</td><td>'.number_format($e['amount']).'</td><td>'.number_format($approved).'</td><td>'.translate_exp_status($e['status']).'</td><td dir="ltr">'.$paidDate.'</td><td>'.($e['finance_note'] ?? '-').'</td></tr>';
    }
    if(!empty($expenses)) {
        echo '<tr style="font-weight:bold; background-color:#e2e8f0;"><td colspan="4" style="text-align:left;">جمع کل (فیلتر شده):</td><td>'.number_format($totalReqSum).'</td><td>'.number_format($totalAppSum).'</td><td colspan="3"></td></tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

$pageTitle = 'گزارش ریزداده‌های ماموریت';
$basePath = '../';
$vendorPath = __DIR__ . '/../../vendor/kamaDatepicker/';
$cssContent = file_exists($vendorPath . 'kamadatepicker.min.css') ? file_get_contents($vendorPath . 'kamadatepicker.min.css') : '';
$extraCss = '<style>' . $cssContent . '</style>';
$extraCss .= '<style>
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:20px; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;}
    .filter-item { display:flex; flex-direction:column; gap:5px; position:relative; }
    .filter-item label { font-size:0.75rem; color:#4b5563; font-weight:bold; }
    .filter-item input, .filter-item select { border:1px solid #d1d5db; border-radius:6px; padding:8px 10px; font-family:"Vazirmatn", Tahoma; font-size: 0.85rem; width: 100%;}
    .filter-actions { grid-column: 1 / -1; display: flex; gap: 10px; justify-content: flex-end; border-top: 1px dashed #cbd5e1; padding-top: 15px; margin-top: 5px; }
    .customer-search-wrap { position: relative; width: 100%; }
    .customer-results { position:absolute; top:100%; right:0; left:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; max-height:200px; overflow-y:auto; z-index:100; display:none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    .customer-results button { width:100%; text-align:right; padding:8px 10px; border:none; border-bottom:1px solid #f1f5f9; background:#fff; cursor:pointer; font-size:0.85rem; font-family:"Vazirmatn", Tahoma; }
    .customer-results button:hover { background:#f8fafc; color:#2563eb; }
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    th, td { text-align:right; padding:12px 10px; border-bottom:1px solid #f1f5f9; font-size:0.8rem; vertical-align: middle; }
    th { color:#6b7280; font-weight:600; background-color: #f8fafc; white-space: nowrap;}
    .customer-col { white-space: normal !important; word-wrap: break-word; overflow-wrap: break-word; }
    .badge-status { padding: 3px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: bold; border: 1px solid transparent;}
    .bg-green { background: #f0fdf4; color: #166534; border-color: #bbf7d0;}
    .bg-purple { background: #e0e7ff; color: #1d4ed8; border-color: #bfdbfe;}
    .bg-red { background: #fef2f2; color: #991b1b; border-color: #fecaca;}
    .bg-yellow { background: #fffbeb; color: #b45309; border-color: #fde68a;}
    .table-responsive { overflow-x: auto; }
    .pagination { display: flex; gap: 5px; margin-top: 15px; justify-content: center; flex-wrap: wrap;}
    .page-link { padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; color: #374151; font-size: 0.85rem; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">📑 گزارشات تفکیکی ماموریت و هزینه‌ها</span></div>
            <div style="display:flex; gap:10px;">
                <button onclick="document.getElementById('exportFlag').value='excel'; document.getElementById('filterForm').submit(); document.getElementById('exportFlag').value='';" class="btn btn-success" style="background:#10b981; border:none;">📥 خروجی اکسل کامل</button>
            </div>
        </div>

        <form method="GET" id="filterForm" class="filter-grid page-actions">
            <input type="hidden" name="export" id="exportFlag" value="">
            <div class="filter-item"><label>از تاریخ (انجام ماموریت):</label><input type="text" name="start_date" id="pd_start" autocomplete="off" value="<?php echo htmlspecialchars($startDate); ?>" placeholder="مثال: 1403/01/01"></div>
            <div class="filter-item"><label>تا تاریخ:</label><input type="text" name="end_date" id="pd_end" autocomplete="off" value="<?php echo htmlspecialchars($endDate); ?>" placeholder="مثال: 1403/12/29"></div>
            <div class="filter-item"><label>کارمند:</label><select name="user_id"><option value="">همه کارمندان</option><?php foreach($usersList as $u): ?><option value="<?php echo $u['id']; ?>" <?php if(($_GET['user_id']??'') == $u['id']) echo 'selected'; ?>><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></option><?php endforeach; ?></select></div>
            <div class="filter-item"><label>دپارتمان:</label><select name="department_id"><option value="">همه دپارتمان‌ها</option><?php foreach($deptsList as $d): ?><option value="<?php echo $d['id']; ?>" <?php if(($_GET['department_id']??'') == $d['id']) echo 'selected'; ?>><?php echo htmlspecialchars($d['name']); ?></option><?php endforeach; ?></select></div>
            <div class="filter-item"><label>وضعیت پرونده (ماموریت):</label><select name="req_status"><option value="">همه وضعیت‌ها</option><option value="pending_admin" <?php if(($_GET['req_status']??'') == 'pending_admin') echo 'selected'; ?>>در انتظار مدیر</option><option value="expenses_open" <?php if(($_GET['req_status']??'') == 'expenses_open') echo 'selected'; ?>>ثبت هزینه</option><option value="finance_review" <?php if(($_GET['req_status']??'') == 'finance_review') echo 'selected'; ?>>بررسی مالی</option><option value="completed" <?php if(($_GET['req_status']??'') == 'completed') echo 'selected'; ?>>تایید و پایان یافته</option><option value="rejected" <?php if(($_GET['req_status']??'') == 'rejected') echo 'selected'; ?>>رد شده</option></select></div>
            <div class="filter-item"><label>نام مشتری (تایپ کنید):</label><div class="customer-search-wrap"><input type="text" name="customer_name" class="customer-input" autocomplete="off" placeholder="جستجو..." value="<?php echo htmlspecialchars($_GET['customer_name'] ?? ''); ?>" oninput="searchCustomersFilter(this)"><input type="hidden" name="customer_id" class="customer-id" value="<?php echo htmlspecialchars($_GET['customer_id'] ?? ''); ?>"><div class="customer-results"></div></div></div>
            <div class="filter-item" style="border-right: 2px solid #cbd5e1; padding-right:15px;">
                <label style="color:#2563eb;">نوع هزینه (جدول ۲):</label>
                <select name="expense_type">
                    <option value="">همه هزینه‌ها</option>
                    <option value="personal" <?php if(($_GET['expense_type']??'') == 'personal') echo 'selected'; ?>>هزینه شخصی</option>
                    <option value="org_snap" <?php if(($_GET['expense_type']??'') == 'org_snap') echo 'selected'; ?>>اسنپ سازمانی</option>
                    <option value="discount_code" <?php if(($_GET['expense_type']??'') == 'discount_code') echo 'selected'; ?>>کد تخفیف</option>
                </select>
            </div>
            <div class="filter-item">
                <label style="color:#2563eb;">وضعیت هزینه (جدول ۲):</label>
                <select name="expense_status">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="pending" <?php if(($_GET['expense_status']??'') == 'pending') echo 'selected'; ?>>در انتظار</option>
                    <option value="approved" <?php if(($_GET['expense_status']??'') == 'approved') echo 'selected'; ?>>تایید شده (پرداخت نشده)</option>
                    <option value="paid" <?php if(($_GET['expense_status']??'') == 'paid') echo 'selected'; ?>>پرداخت شده</option>
                    <option value="rejected" <?php if(($_GET['expense_status']??'') == 'rejected') echo 'selected'; ?>>رد شده</option>
                </select>
            </div>
            <div class="filter-actions"><a href="mission_detailed_reports.php" class="btn btn-outline">پاک کردن فیلترها</a><button type="submit" class="btn btn-primary">🔍 اعمال فیلتر و جستجو</button></div>
        </form>

        <div class="card-box">
            <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">جدول ۱: لیست ریز سفرهای پرسنل (تعداد کل: <?php echo $totalMissions; ?>)</h3>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>ردیف</th><th>نام کارمند</th><th class="customer-col">مشتری (طرف حساب)</th><th>استان / شهر</th><th>نوع ماموریت</th><th>تاریخ</th><th>وضعیت پرونده</th></tr></thead>
                    <tbody>
                        <?php if(empty($missions)): ?><tr><td colspan="7" style="text-align:center;">داده‌ای یافت نشد.</td></tr>
                        <?php else: $i = $offsetM + 1; foreach($missions as $m): 
                            $date = $m['mission_date'] ? jdate('Y/m/d', strtotime($m['mission_date'])) : '-';
                            $loc = ($m['state'] ?? '-') . ' / ' . ($m['city'] ?? '-');
                        ?>
                            <tr><td><?php echo $i++; ?></td><td style="font-weight:bold;"><?php echo htmlspecialchars($m['first_name'].' '.$m['last_name']); ?></td><td class="customer-col"><?php echo htmlspecialchars($m['company_name'] ?? '-'); ?></td><td><?php echo htmlspecialchars($loc); ?></td><td><?php echo htmlspecialchars($m['type_title'] ?? '-'); ?></td><td dir="ltr"><?php echo $date; ?></td><td><?php echo translate_mission_status($m['req_status']); ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPagesM > 1): ?><div class="pagination"><?php for ($p = 1; $p <= $totalPagesM; $p++): ?><a href="<?php echo buildPageUrl($_GET, 'page_m', $p); ?>" class="page-link <?php echo ($p == $pageM) ? 'active' : ''; ?>"><?php echo $p; ?></a><?php endfor; ?></div><?php endif; ?>
        </div>

        <div class="card-box">
            <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">جدول ۲: ریز تراکنش‌ها و هزینه‌های ثبت شده (تعداد کل: <?php echo $totalExpenses; ?>)</h3>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>ردیف</th><th>کارمند</th><th class="customer-col">مشتری مرتبط</th><th>نوع هزینه</th><th>مبلغ درخواستی</th><th>مبلغ تاییدی</th><th>وضعیت</th><th>تاریخ پرداخت</th></tr></thead>
                    <tbody>
                        <?php if(empty($expenses)): ?><tr><td colspan="8" style="text-align:center;">تراکنشی یافت نشد.</td></tr>
                        <?php else: $j = $offsetE + 1; foreach($expenses as $e): 
                            $approved = $e['approved_amount'] !== null ? $e['approved_amount'] : $e['amount'];
                            if($e['status'] === 'rejected') $approved = 0;
                            
                            $esClass = 'bg-yellow';
                            if($e['status'] == 'approved') $esClass = 'bg-green';
                            elseif($e['status'] == 'paid') $esClass = 'bg-purple';
                            elseif($e['status'] == 'rejected') $esClass = 'bg-red';
                            
                            $paidDate = $e['paid_at'] ? jdate('Y/m/d H:i', strtotime($e['paid_at'])) : '-';
                        ?>
                            <tr><td><?php echo $j++; ?></td><td><?php echo htmlspecialchars($e['first_name'].' '.$e['last_name']); ?></td><td class="customer-col"><?php echo htmlspecialchars($e['company_name'] ?? '-'); ?></td><td><?php echo translate_exp_type($e['expense_type']); ?></td><td style="font-weight:bold;"><?php echo number_format($e['amount']); ?></td><td style="font-weight:bold; color:<?php echo ($approved < $e['amount']) ? '#b91c1c' : '#166534'; ?>;"><?php echo number_format($approved); ?></td><td><span class="badge-status <?php echo $esClass; ?>"><?php echo translate_exp_status($e['status']); ?></span></td><td dir="ltr" style="font-size:0.75rem; color:#64748b;"><?php echo $paidDate; ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <?php if(!empty($expenses)): ?>
                    <tfoot><tr style="background-color:#e2e8f0; font-weight:bold;"><td colspan="4" style="text-align:left; padding-left:15px;">جمع کل مبالغ فیلتر شده (در تمام صفحات):</td><td><?php echo number_format($totalReqSum); ?></td><td style="color:#166534;"><?php echo number_format($totalAppSum); ?></td><td colspan="2"></td></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
            <?php if ($totalPagesE > 1): ?><div class="pagination"><?php for ($p = 1; $p <= $totalPagesE; $p++): ?><a href="<?php echo buildPageUrl($_GET, 'page_e', $p); ?>" class="page-link <?php echo ($p == $pageE) ? 'active' : ''; ?>"><?php echo $p; ?></a><?php endfor; ?></div><?php endif; ?>
        </div>

    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    <?php if (file_exists($vendorPath . 'kamadatepicker.min.js')) echo file_get_contents($vendorPath . 'kamadatepicker.min.js'); ?>
</script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (typeof kamaDatepicker === 'function') {
        kamaDatepicker('pd_start', { buttonsColor: "#2563eb", forceFarsiDigits: true, markToday: true, gotoToday: true });
        kamaDatepicker('pd_end', { buttonsColor: "#2563eb", forceFarsiDigits: true, markToday: true, gotoToday: true });
    }
});
let searchTimerFilter;
async function searchCustomersFilter(input) {
    const wrap = input.closest('.customer-search-wrap');
    const list = wrap.querySelector('.customer-results');
    const hidden = wrap.querySelector('.customer-id');
    const q = input.value.trim();
    hidden.value = '';
    if (q.length < 2) { list.style.display = 'none'; return; }
    clearTimeout(searchTimerFilter);
    searchTimerFilter = setTimeout(async () => {
        try {
            const res = await fetch(`customers.php?action=search_customers&q=${encodeURIComponent(q)}`);
            const data = await res.json();
            if (!data || data.status !== 'success') return;
            list.innerHTML = data.items.map(it => `<button type="button" onclick="selectCustomerFilter(this)" data-id="${it.id}" data-name="${it.company_name}">${it.company_name}</button>`).join('');
            list.style.display = data.items.length ? 'block' : 'none';
        } catch (e) {}
    }, 300);
}
function selectCustomerFilter(btn) {
    const wrap = btn.closest('.customer-search-wrap');
    wrap.querySelector('.customer-id').value = btn.dataset.id;
    wrap.querySelector('.customer-input').value = btn.dataset.name;
    wrap.querySelector('.customer-results').style.display = 'none';
}
document.addEventListener('click', e => { if (!e.target.closest('.customer-search-wrap')) document.querySelectorAll('.customer-results').forEach(el => el.style.display = 'none'); });
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>