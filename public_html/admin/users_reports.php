<?php
/*
 * فایل: public_html/admin/users_reports.php
 * توضیحات: گزارشات جامع و تفکیکی پرسنل و کاربران + فیلتر پیشرفته + خروجی اکسل + پرینت
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
$isAdmin = in_array($userRole, ['admin', 'management']);

// فقط ادمین و مدیریت کل به گزارش کل پرسنل دسترسی دارند
// اگر می‌خواهید مدیران دپارتمان هم ببینند، باید شرط محدودیت دپارتمان (مانند فایل‌های قبلی) اضافه شود
if (!$isAdmin) {
    die('<div style="text-align:center; margin-top:50px; font-family:Tahoma; color:red; font-weight:bold;">⛔ شما دسترسی لازم برای مشاهده این گزارشات را ندارید.</div>');
}

// -------------------------------------------------------------
// دریافت اطلاعات پایه‌ای برای پر کردن لیست‌های کشویی
// -------------------------------------------------------------
$deptsList = $pdo->query("SELECT id, name FROM departments")->fetchAll(PDO::FETCH_ASSOC);
$rolesList = $pdo->query("SELECT name, title FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// پردازش فیلترها (ساخت WHERE و Bind Params)
// -------------------------------------------------------------
$where = "1=1"; 
$params = [];

// جستجوی متنی عمومی
$searchQuery = $_GET['q'] ?? '';
if (!empty($searchQuery)) {
    $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.mobile LIKE ? OR u.national_code LIKE ? OR u.personnel_code LIKE ?)";
    $likeQuery = "%" . trim($searchQuery) . "%";
    array_push($params, $likeQuery, $likeQuery, $likeQuery, $likeQuery, $likeQuery, $likeQuery);
}

// فیلتر دپارتمان
if (!empty($_GET['department_id'])) {
    $where .= " AND u.department_id = ?";
    $params[] = $_GET['department_id'];
}

// فیلتر نقش
if (!empty($_GET['role'])) {
    $where .= " AND u.role = ?";
    $params[] = $_GET['role'];
}

// فیلتر وضعیت
if (!empty($_GET['status'])) {
    $where .= " AND u.status = ?";
    $params[] = $_GET['status'];
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
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $where");
$stmtCount->execute($params);
$totalRecords = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$limitClause = $isExport ? "" : " LIMIT $limit OFFSET $offset";
$sql = "
    SELECT u.*, d.name as dept_name, r.title as role_title
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN roles r ON u.role = r.name
    WHERE $where
    ORDER BY u.id DESC
    $limitClause
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// توابع کمکی
// -------------------------------------------------------------
function buildPageUrl($getArray, $pageParamName, $pageNum) {
    $getArray[$pageParamName] = $pageNum;
    unset($getArray['export']); 
    return '?' . http_build_query($getArray);
}

// -------------------------------------------------------------
// خروجی اکسل
// -------------------------------------------------------------
if ($isExport) {
    while (ob_get_level()) ob_end_clean();
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Users_Report_" . date('Ymd_His') . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>';
    echo '<body dir="rtl" style="font-family: Tahoma, sans-serif; font-size:12px;">';
    
    echo '<h2>گزارش جامع لیست پرسنل و کاربران</h2>';
    echo '<table border="1" cellpadding="5"><thead><tr>
        <th>ردیف</th><th>کد پرسنلی</th><th>نام و نام خانوادگی</th><th>نام کاربری</th><th>کد ملی</th><th>شماره موبایل</th><th>تاریخ تولد</th><th>دپارتمان</th><th>نقش سیستمی</th><th>وضعیت</th>
    </tr></thead><tbody>';
    
    $rowNum = 1;
    foreach($records as $r) {
        $birthDate = (!empty($r['birth_date']) && $r['birth_date'] != '0000-00-00') ? jdate('Y/m/d', strtotime($r['birth_date'])) : '-';
        $roleTitle = $r['role_title'] ?? ($r['role'] == 'admin' ? 'مدیر سیستم' : ($r['role'] == 'user' ? 'کاربر عادی' : $r['role']));
        $statusFa = ($r['status'] == 'active') ? 'فعال' : 'غیرفعال';
        
        echo '<tr>';
        echo '<td>'.$rowNum++.'</td>';
        echo '<td style="mso-number-format:\@;">'.($r['personnel_code'] ?? '-').'</td>';
        echo '<td>'.$r['first_name'].' '.$r['last_name'].'</td>';
        echo '<td>'.$r['username'].'</td>';
        echo '<td style="mso-number-format:\@;">'.($r['national_code'] ?? '-').'</td>';
        echo '<td style="mso-number-format:\@;">'.($r['mobile'] ?? '-').'</td>';
        echo '<td style="mso-number-format:\@;">'.$birthDate.'</td>';
        echo '<td>'.($r['dept_name'] ?? '-').'</td>';
        echo '<td>'.$roleTitle.'</td>';
        echo '<td>'.$statusFa.'</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

$pageTitle = 'گزارش جامع کاربران';
$basePath = '../';

$extraCss = '<style>
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
    
    .badge-status { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; border: 1px solid transparent;}
    .bg-green { background: #f0fdf4; color: #166534; border-color: #bbf7d0;}
    .bg-red { background: #fef2f2; color: #991b1b; border-color: #fecaca;}
    .bg-blue { background: #eff6ff; color: #1e3a8a; border-color: #bfdbfe;}
    
    .table-responsive { overflow-x: auto; }
    .pagination { display: flex; gap: 5px; margin-top: 15px; justify-content: center; flex-wrap: wrap;}
    .page-link { padding: 5px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; color: #374151; font-size: 0.85rem; }
    .page-link:hover { background: #f1f5f9; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }
    
    /* استایل‌های پرینت (Landscape) */
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
                <span class="page-title">📑 گزارش جامع کاربران و پرسنل</span>
            </div>
            <div style="display:flex; gap:10px;">
                <button onclick="document.getElementById('exportFlag').value='excel'; document.getElementById('filterForm').submit(); document.getElementById('exportFlag').value='';" class="btn btn-success" style="background:#10b981; border:none;">📥 خروجی اکسل</button>
                <button onclick="window.print()" class="btn btn-secondary">🖨️ چاپ فرم</button>
            </div>
        </div>

        <form method="GET" id="filterForm" class="filter-grid page-actions">
            <input type="hidden" name="export" id="exportFlag" value="">
            
            <div class="filter-item" style="grid-column: span 2;">
                <label>جستجو (نام، کد ملی، پرسنلی، موبایل):</label>
                <input type="text" name="q" autocomplete="off" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="کلمه کلیدی را وارد کنید...">
            </div>
            
            <div class="filter-item">
                <label>دپارتمان:</label>
                <select name="department_id">
                    <option value="">همه دپارتمان‌ها</option>
                    <?php foreach($deptsList as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php if(($_GET['department_id']??'') == $d['id']) echo 'selected'; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>نقش سیستمی:</label>
                <select name="role">
                    <option value="">همه نقش‌ها</option>
                    <?php foreach($rolesList as $r): ?>
                        <option value="<?php echo $r['name']; ?>" <?php if(($_GET['role']??'') == $r['name']) echo 'selected'; ?>><?php echo htmlspecialchars($r['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-item">
                <label>وضعیت حساب:</label>
                <select name="status">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="active" <?php if(($_GET['status']??'') == 'active') echo 'selected'; ?>>فعال</option>
                    <option value="inactive" <?php if(($_GET['status']??'') == 'inactive') echo 'selected'; ?>>غیرفعال</option>
                </select>
            </div>
            
            <div class="filter-actions">
                <a href="users_reports.php" class="btn btn-outline" style="padding: 8px 15px;">پاک کردن فیلترها</a>
                <button type="submit" class="btn btn-primary" style="padding: 8px 15px;">🔍 اعمال فیلتر</button>
            </div>
        </form>

        <div class="card-box">
            <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">جدول لیست پرسنل (تعداد کل: <?php echo $totalRecords; ?> نفر)</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ردیف</th>
                            <th>کد پرسنلی</th>
                            <th>نام و نام خانوادگی</th>
                            <th>موبایل / کد ملی</th>
                            <th>دپارتمان</th>
                            <th>تاریخ تولد</th>
                            <th>نقش سیستمی</th>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($records)): ?>
                            <tr><td colspan="8" style="text-align:center; padding: 20px;">داده‌ای یافت نشد.</td></tr>
                        <?php else: ?>
                            <?php $i = $offset + 1; foreach($records as $u): 
                                $birthDate = (!empty($u['birth_date']) && $u['birth_date'] != '0000-00-00') ? jdate('Y/m/d', strtotime($u['birth_date'])) : '-';
                                $roleTitle = $u['role_title'] ?? ($u['role'] == 'admin' ? 'مدیر سیستم' : ($u['role'] == 'user' ? 'کاربر عادی' : $u['role']));
                                
                                $sClass = ($u['status'] == 'active') ? 'bg-green' : 'bg-red';
                                $statusFa = ($u['status'] == 'active') ? 'فعال' : 'غیرفعال';
                            ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td style="font-weight:bold; color:#1e3a8a;"><?php echo htmlspecialchars($u['personnel_code'] ?? '-'); ?></td>
                                <td style="font-weight:bold;">
                                    <?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?>
                                    <div style="font-size:0.7rem; color:#64748b; font-weight:normal;">@<?php echo htmlspecialchars($u['username']); ?></div>
                                </td>
                                <td>
                                    <span dir="ltr"><?php echo htmlspecialchars($u['mobile'] ?? '-'); ?></span><br>
                                    <span style="font-size:0.75rem; color:#64748b;">ملی: <?php echo htmlspecialchars($u['national_code'] ?? '-'); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($u['dept_name'] ?? '-'); ?></td>
                                <td dir="ltr"><?php echo $birthDate; ?></td>
                                <td><span class="badge-status bg-blue"><?php echo htmlspecialchars($roleTitle); ?></span></td>
                                <td><span class="badge-status <?php echo $sClass; ?>"><?php echo $statusFa; ?></span></td>
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

<?php include __DIR__ . '/../../templates/footer.php'; ?>