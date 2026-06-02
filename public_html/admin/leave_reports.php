<?php
/*
 * فایل: public_html/admin/leave_reports.php
 * توضیحات: گزارشات جامع و تفکیکی ماژول مرخصی‌ها + فیلتر پیشرفته + خروجی اکسل + پرینت + دسترسی داینامیک
 */

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userRole = $_SESSION['role'] ?? '';
$userId = (int)$_SESSION['user_id'];

// بررسی دسترسی داینامیک (ادمین کل یا داشتن مجوز صریحِ گزارش مرخصی)
$canViewAll = ($userRole === 'admin' || (function_exists('hasPermission') && hasPermission('rep_leaves')));

// --- بررسی دسترسی مدیر دپارتمان ---
$stmtMgr = $pdo->prepare("SELECT id FROM departments WHERE manager_id = ?");
$stmtMgr->execute([$userId]);
$managedDeptIds = $stmtMgr->fetchAll(PDO::FETCH_COLUMN);
$isDeptManager = count($managedDeptIds) > 0;

// اگر کاربر نه دسترسی کل دارد و نه مدیر دپارتمان است، اجازه ورود ندارد
if (!$canViewAll && !$isDeptManager) {
    die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma; color:red; font-weight:bold;">⛔ شما مجوز مشاهده گزارشات مرخصی را ندارید.</div>');
}

// -------------------------------------------------------------
// دریافت اطلاعات پایه‌ای برای پر کردن لیست‌های کشویی
// -------------------------------------------------------------
$usersQuery = "SELECT id, first_name, last_name FROM users WHERE status = 'active'";
if (!$canViewAll && $isDeptManager) {
    $usersQuery .= " AND department_id IN (" . implode(',', $managedDeptIds) . ")";
}
$usersQuery .= " ORDER BY last_name ASC";
$usersList = $pdo->query($usersQuery)->fetchAll(PDO::FETCH_ASSOC);

$deptsList = $pdo->query("SELECT id, name FROM departments")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// پردازش فیلترها (ساخت WHERE و Bind Params)
// -------------------------------------------------------------
$where = "1=1"; 
$params = [];

// محدودیت دپارتمان برای مدیرانی که فقط دسترسی دپارتمان خود را دارند
if (!$canViewAll && $isDeptManager) {
    $deptPlaceholders = implode(',', array_fill(0, count($managedDeptIds), '?'));
    $where .= " AND u.department_id IN ($deptPlaceholders)";
    foreach ($managedDeptIds as $did) {
        $params[] = $did;
    }
}

// 1. تاریخ شمسی (بر اساس تاریخ شروع مرخصی)
$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';

if (!empty($startDate)) {
    $enStart = faToEn($startDate);
    $p = explode('/', $enStart);
    if (count($p) == 3) {
        $gStart = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));
        $where .= " AND l.start_date >= ?";
        $params[] = $gStart;
    }
}
if (!empty($endDate)) {
    $enEnd = faToEn($endDate);
    $p = explode('/', $enEnd);
    if (count($p) == 3) {
        $gEnd = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));
        $where .= " AND l.start_date <= ?";
        $params[] = $gEnd;
    }
}

// 2. کاربر و دپارتمان
if (!empty($_GET['user_id'])) {
    $where .= " AND l.user_id = ?";
    $params[] = $_GET['user_id'];
}
if ($canViewAll && !empty($_GET['department_id'])) {
    $where .= " AND u.department_id = ?";
    $params[] = $_GET['department_id'];
}

// 3. وضعیت درخواست
if (!empty($_GET['status'])) {
    $where .= " AND l.status = ?";
    $params[] = $_GET['status'];
}

// 4. نوع مرخصی
if (!empty($_GET['leave_type'])) {
    $where .= " AND l.leave_type = ?";
    $params[] = $_GET['leave_type'];
}

// -------------------------------------------------------------
// تنظیمات صفحه‌بندی (Pagination)
// -------------------------------------------------------------
$limit = 20; 
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$isExport = (isset($_GET['export']) && $_GET['export'] == 'excel');

// -------------------------------------------------------------
// اجرای کوئری‌ها
// -------------------------------------------------------------

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM leave_requests l JOIN users u ON l.user_id = u.id WHERE $where");
$stmtCount->execute($params);
$totalRecords = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$limitClause = $isExport ? "" : " LIMIT $limit OFFSET $offset";
$sql = "
    SELECT l.*, u.first_name, u.last_name, u.personnel_code, d.name as dept_name
    FROM leave_requests l
    JOIN users u ON l.user_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE $where
    ORDER BY l.start_date DESC, l.created_at DESC
    $limitClause
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// توابع و مپ‌های ترجمه
// -------------------------------------------------------------
$typeLabels = [
    'daily_entitled' => 'روزانه استحقاقی',
    'daily_sick' => 'روزانه استعلاجی',
    'daily_unpaid' => 'روزانه بدون حقوق',
    'hourly' => 'ساعتی'
];

if (!function_exists('translate_status')) {
    function translate_status($status) {
        $map = [
            'pending' => 'در حال بررسی (قدیم)',
            'pending_manager' => 'بررسی مدیر دپارتمان',
            'pending_admin' => 'بررسی مدیریت کل',
            'approved' => 'تایید نهایی',
            'rejected' => 'رد شده (قدیم)',
            'rejected_manager' => 'رد مدیر دپارتمان',
            'rejected_admin' => 'رد مدیریت کل'
        ];
        return $map[$status] ?? $status;
    }
}

if (!function_exists('buildPageUrl')) {
    function buildPageUrl($getArray, $pageParamName, $pageNum) {
        $getArray[$pageParamName] = $pageNum;
        unset($getArray['export']); 
        return '?' . http_build_query($getArray);
    }
}

// محاسبه مدت زمان مرخصی
if (!function_exists('calculateLeaveDuration')) {
    function calculateLeaveDuration($req) {
        if ($req['leave_type'] === 'hourly') {
            if (!$req['start_time'] || !$req['end_time']) return '-';
            $t1 = strtotime($req['start_time']);
            $t2 = strtotime($req['end_time']);
            $diffMin = round(abs($t2 - $t1) / 60, 2);
            $hours = floor($diffMin / 60);
            $minutes = $diffMin % 60;
            return ($hours > 0 ? $hours . ' ساعت ' : '') . ($minutes > 0 ? 'و ' . $minutes . ' دقیقه' : '');
        } else {
            if (!$req['start_date'] || !$req['end_date']) return '۱ روز';
            $d1 = strtotime($req['start_date']);
            $d2 = strtotime($req['end_date']);
            $days = round(($d2 - $d1) / (60 * 60 * 24)) + 1;
            return $days . ' روز';
        }
    }
}

// -------------------------------------------------------------
// خروجی اکسل
// -------------------------------------------------------------
if ($isExport) {
    while (ob_get_level()) ob_end_clean();
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Leave_Reports_Export_" . date('Ymd_His') . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>';
    echo '<body dir="rtl" style="font-family: Tahoma, sans-serif; font-size:12px;">';
    
    echo '<h2>گزارش جامع مرخصی‌های پرسنل</h2>';
    echo '<table border="1" cellpadding="5"><thead><tr>
        <th>ردیف</th><th>نام کارمند</th><th>کد پرسنلی</th><th>دپارتمان</th><th>نوع مرخصی</th><th>تاریخ شروع</th><th>تاریخ پایان</th><th>ساعت خروج/برگشت</th><th>مدت مرخصی</th><th>علت مرخصی</th><th>وضعیت نهایی</th><th>توضیحات مدیر/ادمین</th>
    </tr></thead><tbody>';
    
    $rowNum = 1;
    foreach($records as $r) {
        $startD = $r['start_date'] ? jdate('Y/m/d', strtotime($r['start_date'])) : '-';
        $endD = $r['end_date'] ? jdate('Y/m/d', strtotime($r['end_date'])) : '-';
        $tIn = $r['start_time'] ? substr($r['start_time'], 0, 5) : '-';
        $tOut = $r['end_time'] ? substr($r['end_time'], 0, 5) : '-';
        $timeStr = ($r['leave_type'] === 'hourly') ? "$tIn الی $tOut" : '-';
        $endStr = ($r['leave_type'] === 'hourly') ? '-' : $endD;
        $duration = calculateLeaveDuration($r);
        $note = trim($r['admin_note'] . ' ' . $r['manager_note']) ?: '-';
        
        echo '<tr>';
        echo '<td>'.$rowNum++.'</td>';
        echo '<td>'.$r['first_name'].' '.$r['last_name'].'</td>';
        echo '<td>'.($r['personnel_code'] ?? '-').'</td>';
        echo '<td>'.($r['dept_name'] ?? '-').'</td>';
        echo '<td>'.($typeLabels[$r['leave_type']] ?? '-').'</td>';
        echo '<td>'.$startD.'</td>';
        echo '<td>'.$endStr.'</td>';
        echo '<td style="mso-number-format:\@;">'.$timeStr.'</td>';
        echo '<td>'.$duration.'</td>';
        echo '<td>'.htmlspecialchars($r['user_reason']).'</td>';
        echo '<td>'.translate_status($r['status']).'</td>';
        echo '<td>'.htmlspecialchars($note).'</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

$pageTitle = 'گزارش جامع مرخصی‌ها';
$basePath = '../';

// CSS تقویم و استایل‌ها
$vendorPath = __DIR__ . '/../../vendor/kamaDatepicker/';
$cssContent = '';
if (file_exists($vendorPath . 'kamadatepicker.min.css')) {
    $cssContent = file_get_contents($vendorPath . 'kamadatepicker.min.css');
}

$extraCss = '<style>' . $cssContent . '</style>';
$extraCss .= '<style>
    * { font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:20px; }
    
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;}
    .filter-item { display:flex; flex-direction:column; gap:5px; position:relative; }
    .filter-item label { font-size:0.75rem; color:#4b5563; font-weight:bold; }
    .filter-item input, .filter-item select { border:1px solid #d1d5db; border-radius:6px; padding:8px 10px; font-family:"Vazirmatn", Tahoma; font-size: 0.85rem; width: 100%;}
    .filter-item input:focus, .filter-item select:focus { border-color: #2563eb; outline: none; }
    .filter-actions { grid-column: 1 / -1; display: flex; gap: 10px; justify-content: flex-end; border-top: 1px dashed #cbd5e1; padding-top: 15px; margin-top: 5px; }
    
    .btn { display: inline-flex; align-items: center; justify-content: center; text-align: center; }
    
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    th, td { text-align:right; padding:12px 10px; border-bottom:1px solid #f1f5f9; font-size:0.85rem; vertical-align: middle; }
    th { color:#6b7280; font-weight:600; background-color: #f8fafc; white-space: nowrap;}
    
    .customer-col { white-space: normal !important; word-wrap: break-word; overflow-wrap: break-word; }

    .badge-status { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; border: 1px solid transparent;}
    .bg-green { background: #f0fdf4; color: #166534; border-color: #bbf7d0;}
    .bg-red { background: #fef2f2; color: #991b1b; border-color: #fecaca;}
    .bg-yellow { background: #fffbeb; color: #b45309; border-color: #fde68a;}
    .bg-blue { background: #eff6ff; color: #1e3a8a; border-color: #bfdbfe;}
    .bg-gray { background: #f3f4f6; color: #4b5563; border-color: #d1d5db;}
    
    .badge-leave { background: #e2e8f0; color: #334155; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight:bold; }
    
    .table-responsive { overflow-x: auto; }
    .pagination { display: flex; gap: 5px; margin-top: 15px; justify-content: center; flex-wrap: wrap;}
    .page-link { padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; color: #374151; font-size: 0.85rem; }
    .page-link:hover { background: #f1f5f9; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }
    
    /* تنظیمات پرینت (Landscape) */
    @media print {
        @page { size: A4 landscape; margin: 1cm; }
        .main-header, .sidebar, .page-actions, .filter-grid, .filter-actions, .pagination { display: none !important; }
        body, .main-content, .content-wrapper { display: block !important; margin: 0 !important; padding: 0 !important; width: 100% !important; position: static !important;}
        .card-box { border: none; box-shadow: none; padding: 0; margin: 0 !important; page-break-inside: auto; }
        table { border: 1px solid #000; width: 100%; table-layout: auto; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #ccc; padding: 6px; font-size: 9pt; white-space: normal !important; }
        a { text-decoration: none !important; color: #000 !important; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div>
                <span class="page-title fw-bold" style="font-size: 1.2rem;">📑 گزارشات جامع مرخصی پرسنل</span>
            </div>
            <div style="display:flex; gap:10px;">
                <button onclick="document.getElementById('exportFlag').value='excel'; document.getElementById('filterForm').submit(); document.getElementById('exportFlag').value='';" class="btn btn-success" style="background:#10b981; border:none; font-weight:bold;">📥 خروجی اکسل</button>
                <button onclick="window.print()" class="btn btn-secondary fw-bold">🖨️ چاپ فرم</button>
            </div>
        </div>

        <form method="GET" id="filterForm" class="filter-grid page-actions">
            <input type="hidden" name="export" id="exportFlag" value="">
            
            <div class="filter-item">
                <label>از تاریخ (شروع مرخصی):</label>
                <input type="text" name="start_date" id="pd_start" autocomplete="off" value="<?php echo htmlspecialchars($startDate); ?>" placeholder="مثال: 1403/01/01">
            </div>
            <div class="filter-item">
                <label>تا تاریخ:</label>
                <input type="text" name="end_date" id="pd_end" autocomplete="off" value="<?php echo htmlspecialchars($endDate); ?>" placeholder="مثال: 1403/12/29">
            </div>
            <div class="filter-item">
                <label>کارمند:</label>
                <select name="user_id">
                    <option value="">همه پرسنل</option>
                    <?php foreach($usersList as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php if(($_GET['user_id']??'') == $u['id']) echo 'selected'; ?>><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if ($canViewAll): ?>
            <div class="filter-item">
                <label>دپارتمان:</label>
                <select name="department_id">
                    <option value="">همه دپارتمان‌ها</option>
                    <?php foreach($deptsList as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php if(($_GET['department_id']??'') == $d['id']) echo 'selected'; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="filter-item">
                <label>وضعیت بررسی:</label>
                <select name="status">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="pending_manager" <?php if(($_GET['status']??'') == 'pending_manager') echo 'selected'; ?>>در حال بررسی مدیر دپارتمان</option>
                    <option value="pending_admin" <?php if(($_GET['status']??'') == 'pending_admin') echo 'selected'; ?>>در حال بررسی مدیریت کل</option>
                    <option value="approved" <?php if(($_GET['status']??'') == 'approved') echo 'selected'; ?>>تایید نهایی شده</option>
                    <option value="rejected_manager" <?php if(($_GET['status']??'') == 'rejected_manager') echo 'selected'; ?>>رد شده دپارتمان</option>
                    <option value="rejected_admin" <?php if(($_GET['status']??'') == 'rejected_admin') echo 'selected'; ?>>رد شده مدیریت کل</option>
                </select>
            </div>
            <div class="filter-item">
                <label>نوع مرخصی:</label>
                <select name="leave_type">
                    <option value="">همه انواع</option>
                    <option value="daily_entitled" <?php if(($_GET['leave_type']??'') == 'daily_entitled') echo 'selected'; ?>>روزانه استحقاقی</option>
                    <option value="daily_sick" <?php if(($_GET['leave_type']??'') == 'daily_sick') echo 'selected'; ?>>روزانه استعلاجی</option>
                    <option value="daily_unpaid" <?php if(($_GET['leave_type']??'') == 'daily_unpaid') echo 'selected'; ?>>بدون حقوق</option>
                    <option value="hourly" <?php if(($_GET['leave_type']??'') == 'hourly') echo 'selected'; ?>>ساعتی</option>
                </select>
            </div>
            
            <div class="filter-actions">
                <a href="leave_reports.php" class="btn btn-outline fw-bold" style="padding: 8px 15px;">پاک کردن فیلترها</a>
                <button type="submit" class="btn btn-primary fw-bold" style="padding: 8px 15px;">🔍 اعمال فیلتر</button>
            </div>
        </form>

        <div class="card-box">
            <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px; font-weight:bold;">جدول گزارشات مرخصی (تعداد کل: <?php echo $totalRecords; ?> مورد)</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ردیف</th>
                            <th>نام کارمند</th>
                            <th>دپارتمان</th>
                            <th>نوع مرخصی</th>
                            <th>تاریخ / بازه زمانی</th>
                            <th>مدت محاسبه شده</th>
                            <th class="customer-col">علت درخواست</th>
                            <th>وضعیت نهایی</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($records)): ?>
                            <tr><td colspan="8" style="text-align:center; padding: 20px; font-weight:bold; color:#64748b;">داده‌ای یافت نشد.</td></tr>
                        <?php else: ?>
                            <?php $i = $offset + 1; foreach($records as $m): 
                                $startD = $m['start_date'] ? jdate('Y/m/d', strtotime($m['start_date'])) : '-';
                                $endD = $m['end_date'] ? jdate('Y/m/d', strtotime($m['end_date'])) : '-';
                                
                                $timeStr = "";
                                if ($m['leave_type'] === 'hourly') {
                                    $timeStr = "$startD <br><span style='font-size:0.75rem; color:#64748b;'>ساعت ".substr($m['start_time'],0,5)." تا ".substr($m['end_time'],0,5)."</span>";
                                } else {
                                    $timeStr = "$startD <br><span style='font-size:0.75rem; color:#64748b;'>تا $endD</span>";
                                }

                                $duration = calculateLeaveDuration($m);
                                
                                $sClass = 'bg-gray';
                                if($m['status'] == 'approved') $sClass = 'bg-green';
                                elseif(strpos($m['status'], 'rejected') !== false) $sClass = 'bg-red';
                                elseif($m['status'] == 'pending_manager') $sClass = 'bg-yellow';
                                elseif($m['status'] == 'pending_admin') $sClass = 'bg-blue';
                            ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td style="font-weight:bold;"><?php echo htmlspecialchars($m['first_name'].' '.$m['last_name']); ?>
                                    <div style="font-size:0.7rem; color:#9ca3af;">کد: <?php echo htmlspecialchars($m['personnel_code'] ?? '-'); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($m['dept_name'] ?? '-'); ?></td>
                                <td><span class="badge-leave"><?php echo $typeLabels[$m['leave_type']] ?? '-'; ?></span></td>
                                <td dir="ltr" style="font-weight:bold;"><?php echo $timeStr; ?></td>
                                <td dir="ltr"><span style="background:#f1f5f9; padding:4px 8px; border-radius:6px; font-weight:bold; color:#0f172a;"><?php echo $duration; ?></span></td>
                                <td class="customer-col" style="font-size: 0.8rem; color: #475569;"><?php echo htmlspecialchars(mb_strimwidth($m['user_reason'], 0, 60, '...')); ?></td>
                                <td><span class="badge-status <?php echo $sClass; ?>"><?php echo translate_status($m['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div class="pagination page-actions">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="<?php echo buildPageUrl($_GET, 'page', $p); ?>" class="page-link <?php echo ($p == $page) ? 'active' : ''; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
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
});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>