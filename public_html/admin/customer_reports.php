<?php
/*
 * فایل: public_html/admin/customer_reports.php
 * توضیحات: گزارشات جامع و تفکیکی مشتریان + صفحه‌بندی هوشمند و جمع‌وجور
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

// بررسی سطح دسترسی
if (!$isAdmin && $userRole !== 'manager' && $userRole !== 'sales_manager' && $userRole !== 'sales_expert') {
    die('<div style="text-align:center; margin-top:50px; font-family:Tahoma; color:red; font-weight:bold;">⛔ شما دسترسی لازم برای مشاهده این گزارشات را ندارید.</div>');
}

// -------------------------------------------------------------
// دریافت اطلاعات پایه‌ای برای فیلترها
// -------------------------------------------------------------
$typesList = $pdo->query("SELECT DISTINCT type_name FROM customers WHERE type_name IS NOT NULL AND type_name != '' ORDER BY type_name")->fetchAll(PDO::FETCH_COLUMN);
$managersList = $pdo->query("SELECT DISTINCT manager_name FROM customers WHERE manager_name IS NOT NULL AND manager_name != '' ORDER BY manager_name")->fetchAll(PDO::FETCH_COLUMN);
$followersList = $pdo->query("SELECT DISTINCT full_name FROM customer_followers WHERE full_name IS NOT NULL AND full_name != '' ORDER BY full_name")->fetchAll(PDO::FETCH_COLUMN);
$statesList = $pdo->query("SELECT DISTINCT state FROM customers WHERE state IS NOT NULL AND state != '' ORDER BY state")->fetchAll(PDO::FETCH_COLUMN);
$allTags = $pdo->query("SELECT id, title FROM tags ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

$f_state = $_GET['state'] ?? '';
$citiesQuery = "SELECT DISTINCT city FROM customers WHERE city IS NOT NULL AND city != ''";
if(!empty($f_state)) $citiesQuery .= " AND state = " . $pdo->quote($f_state);
$citiesQuery .= " ORDER BY city";
$citiesList = $pdo->query($citiesQuery)->fetchAll(PDO::FETCH_COLUMN);

// -------------------------------------------------------------
// پردازش فیلترها
// -------------------------------------------------------------
$where = ["1=1"]; 
$params = [];

$q = trim($_GET['q'] ?? '');
if (!empty($q)) {
    $where[] = "(c.company_name LIKE ? OR c.mobile LIKE ? OR c.company_code LIKE ? OR c.national_id LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}

$f_type = $_GET['type'] ?? '';
if (!empty($f_type)) { $where[] = "c.type_name = ?"; $params[] = $f_type; }

$f_manager = $_GET['manager'] ?? '';
if (!empty($f_manager)) { $where[] = "c.manager_name = ?"; $params[] = $f_manager; }

$f_follower = $_GET['follower'] ?? '';
if (!empty($f_follower)) { 
    $where[] = "EXISTS (SELECT 1 FROM customer_followers cf WHERE cf.company_num = c.company_num AND cf.full_name = ?)"; 
    $params[] = $f_follower; 
}

$f_city = $_GET['city'] ?? '';
if (!empty($f_state)) { $where[] = "c.state = ?"; $params[] = $f_state; }
if (!empty($f_city)) { $where[] = "c.city = ?"; $params[] = $f_city; }

$f_tags = isset($_GET['tags']) && is_array($_GET['tags']) ? array_map('intval', $_GET['tags']) : [];
$f_tags = array_filter($f_tags, function($v) { return $v > 0; });
if (!empty($f_tags)) {
    $inQuery = implode(',', array_fill(0, count($f_tags), '?'));
    $where[] = "EXISTS (SELECT 1 FROM customer_tag_links ctl WHERE ctl.customer_id = c.id AND ctl.tag_id IN ($inQuery))";
    $params = array_merge($params, $f_tags);
}

$whereSql = implode(" AND ", $where);

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
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereSql");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$limitClause = $isExport ? "" : " LIMIT $limit OFFSET $offset";

$sql = "
    SELECT c.*, 
        (SELECT GROUP_CONCAT(full_name SEPARATOR '، ') FROM customer_followers cf WHERE cf.company_num = c.company_num) AS followers_list,
        (SELECT GROUP_CONCAT(t.title SEPARATOR '، ') FROM customer_tag_links ctl JOIN tags t ON ctl.tag_id = t.id WHERE ctl.customer_id = c.id) AS tags_list
    FROM customers c
    WHERE $whereSql
    ORDER BY c.id DESC
    $limitClause
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    header("Content-Disposition: attachment; filename=Customers_Report_" . date('Ymd_His') . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>';
    echo '<body dir="rtl" style="font-family: Tahoma, sans-serif; font-size:12px;">';
    
    echo '<h2>گزارش جامع پایگاه داده مشتریان</h2>';
    echo '<table border="1" cellpadding="5"><thead><tr>
        <th>ردیف</th><th>کد مشتری</th><th>نام شرکت / شخص</th><th>شناسه ملی</th><th>نوع مشتری</th><th>ثبت‌کننده</th><th>پیگیری‌کنندگان</th><th>موبایل</th><th>تلفن ثابت</th><th>استان</th><th>شهر</th><th>آدرس</th><th>برچسب‌ها</th>
    </tr></thead><tbody>';
    
    $rowNum = 1;
    foreach($records as $r) {
        echo '<tr>';
        echo '<td>'.$rowNum++.'</td>';
        echo '<td style="mso-number-format:\@;">'.($r['company_code'] ?? '-').'</td>';
        echo '<td>'.htmlspecialchars($r['company_name'] ?? '-').'</td>';
        echo '<td style="mso-number-format:\@;">'.($r['national_id'] ?? '-').'</td>';
        echo '<td>'.htmlspecialchars($r['type_name'] ?? '-').'</td>';
        echo '<td>'.htmlspecialchars($r['manager_name'] ?? '-').'</td>';
        echo '<td>'.htmlspecialchars($r['followers_list'] ?? '-').'</td>';
        echo '<td style="mso-number-format:\@;">'.($r['mobile'] ?? '-').'</td>';
        echo '<td style="mso-number-format:\@;">'.($r['phone'] ?? '-').'</td>';
        echo '<td>'.htmlspecialchars($r['state'] ?? '-').'</td>';
        echo '<td>'.htmlspecialchars($r['city'] ?? '-').'</td>';
        echo '<td>'.htmlspecialchars($r['address'] ?? '-').'</td>';
        echo '<td>'.htmlspecialchars($r['tags_list'] ?? '-').'</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

$pageTitle = 'گزارش جامع مشتریان';
$basePath = '../';

$extraCss = '<style>
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:20px; }
    
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 20px;}
    .filter-item { display:flex; flex-direction:column; gap:5px; position:relative; }
    .filter-item.span-2 { grid-column: span 2; }
    @media(max-width:768px){ .filter-item.span-2 { grid-column: span 1; } }
    
    .filter-item label { font-size:0.75rem; color:#4b5563; font-weight:bold; }
    .filter-item input, .filter-item select { border:1px solid #d1d5db; border-radius:6px; padding:8px 10px; font-family:"Vazirmatn", Tahoma; font-size: 0.85rem; width: 100%;}
    .filter-item input:focus, .filter-item select:focus { border-color: #2563eb; outline: none; }
    .filter-actions { grid-column: 1 / -1; display: flex; gap: 10px; justify-content: flex-end; border-top: 1px dashed #cbd5e1; padding-top: 15px; margin-top: 5px; }
    
    .btn { display: inline-flex; align-items: center; justify-content: center; text-align: center; }
    
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    th, td { text-align:right; padding:12px 10px; border-bottom:1px solid #f1f5f9; font-size:0.85rem; vertical-align: middle; }
    th { color:#6b7280; font-weight:600; background-color: #f8fafc; white-space: nowrap;}
    
    .customer-col { white-space: normal !important; word-wrap: break-word; overflow-wrap: break-word; }
    .badge-status { background: #e0f2fe; color: #1e40af; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight:bold; }
    .tag-item { display:inline-block; background:#f1f5f9; border:1px solid #cbd5e1; padding:2px 6px; border-radius:4px; font-size:0.7rem; margin:2px; color:#475569;}
    
    .table-responsive { overflow-x: auto; }
    
    /* استایل صفحه‌بندی */
    .pagination { display: flex; gap: 5px; margin-top: 15px; justify-content: center; flex-wrap: wrap; align-items: center;}
    .page-link { padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; color: #374151; font-size: 0.85rem; font-weight: bold; transition: 0.2s;}
    .page-link:hover { background: #f1f5f9; border-color: #cbd5e1; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }
    .page-ellipsis { color: #64748b; padding: 0 5px; font-weight: bold; }
    
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
                <span class="page-title">📑 گزارش جامع پایگاه داده مشتریان</span>
            </div>
            <div style="display:flex; gap:10px;">
                <button onclick="document.getElementById('exportFlag').value='excel'; document.getElementById('filterForm').submit(); document.getElementById('exportFlag').value='';" class="btn btn-success" style="background:#10b981; border:none;">📥 خروجی اکسل</button>
                <button onclick="window.print()" class="btn btn-secondary">🖨️ چاپ فرم</button>
            </div>
        </div>

        <form method="GET" id="filterForm" class="filter-grid page-actions">
            <input type="hidden" name="export" id="exportFlag" value="">
            
            <div class="filter-item span-2">
                <label>جستجوی سریع (نام، کد، موبایل، شناسه ملی):</label>
                <input type="text" name="q" autocomplete="off" value="<?php echo htmlspecialchars($q); ?>" placeholder="عبارت مورد نظر را تایپ کنید...">
            </div>
            
            <div class="filter-item">
                <label>نوع مشتری:</label>
                <select name="type">
                    <option value="">همه انواع</option>
                    <?php foreach($typesList as $t): ?>
                        <option value="<?php echo $t; ?>" <?php if($f_type == $t) echo 'selected'; ?>><?php echo htmlspecialchars($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>ثبت‌کننده (مدیر):</label>
                <select name="manager">
                    <option value="">همه مدیران</option>
                    <?php foreach($managersList as $m): ?>
                        <option value="<?php echo $m; ?>" <?php if($f_manager == $m) echo 'selected'; ?>><?php echo htmlspecialchars($m); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>پیگیری‌کننده (فالوور):</label>
                <select name="follower">
                    <option value="">همه پیگیری‌کنندگان</option>
                    <?php foreach($followersList as $f): ?>
                        <option value="<?php echo $f; ?>" <?php if($f_follower == $f) echo 'selected'; ?>><?php echo htmlspecialchars($f); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>استان:</label>
                <select name="state" onchange="this.form.submit()">
                    <option value="">همه استان‌ها</option>
                    <?php foreach($statesList as $s): ?>
                        <option value="<?php echo $s; ?>" <?php if($f_state == $s) echo 'selected'; ?>><?php echo htmlspecialchars($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item">
                <label>شهر:</label>
                <select name="city">
                    <option value="">همه شهرها</option>
                    <?php foreach($citiesList as $c): ?>
                        <option value="<?php echo $c; ?>" <?php if($f_city == $c) echo 'selected'; ?>><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item span-2">
                <label title="با نگه‌داشتن کلید Ctrl می‌توانید چند برچسب را همزمان انتخاب کنید.">برچسب‌ها (انتخاب چندگانه با Ctrl):</label>
                <select name="tags[]" multiple style="min-height: 80px;">
                    <?php foreach ($allTags as $tagItem): ?>
                        <option value="<?php echo $tagItem['id']; ?>" <?php echo in_array($tagItem['id'], $f_tags) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($tagItem['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-actions">
                <a href="customer_reports.php" class="btn btn-outline" style="padding: 8px 15px;">پاک کردن فیلترها</a>
                <button type="submit" class="btn btn-primary" style="padding: 8px 15px;">🔍 اعمال فیلتر</button>
            </div>
        </form>

        <div class="card-box">
            <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; border-bottom:2px solid #e5e7eb; padding-bottom:10px;">جدول گزارش مشتریان (تعداد کل: <?php echo $totalRecords; ?> شرکت/شخص)</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ردیف</th>
                            <th>کد مشتری</th>
                            <th class="customer-col">نام شرکت / شخص</th>
                            <th>موبایل / تلفن</th>
                            <th>موقعیت مکانی</th>
                            <th>نوع / برچسب‌ها</th>
                            <th>تیم فروش (ثبت و پیگیری)</th>
                            <th class="btn-action page-actions">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($records)): ?>
                            <tr><td colspan="8" style="text-align:center; padding: 20px;">داده‌ای یافت نشد.</td></tr>
                        <?php else: ?>
                            <?php $i = $offset + 1; foreach($records as $c): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td style="font-weight:bold; color:#1e3a8a;"><?php echo htmlspecialchars($c['company_code'] ?? '-'); ?></td>
                                <td class="customer-col" style="font-weight:bold; color:#0f172a;">
                                    <?php echo htmlspecialchars($c['company_name'] ?? '-'); ?>
                                    <?php if(!empty($c['national_id'])): ?>
                                        <div style="font-size:0.7rem; color:#64748b; font-weight:normal;">شناسه ملی: <?php echo htmlspecialchars($c['national_id']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div dir="ltr" style="font-weight:bold;"><?php echo htmlspecialchars($c['mobile'] ?? '-'); ?></div>
                                    <div dir="ltr" style="font-size:0.75rem; color:#64748b;"><?php echo htmlspecialchars($c['phone'] ?? '-'); ?></div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($c['state'] ?? '-'); ?>
                                    <div style="font-size:0.75rem; color:#64748b;"><?php echo htmlspecialchars($c['city'] ?? '-'); ?></div>
                                </td>
                                <td class="customer-col">
                                    <span class="badge-status"><?php echo htmlspecialchars($c['type_name'] ?? '-'); ?></span><br>
                                    <?php 
                                    if(!empty($c['tags_list'])) {
                                        $tagsArr = explode('، ', $c['tags_list']);
                                        foreach($tagsArr as $tag) {
                                            echo "<span class='tag-item'>".htmlspecialchars($tag)."</span>";
                                        }
                                    }
                                    ?>
                                </td>
                                <td class="customer-col" style="font-size:0.8rem;">
                                    <strong style="color:#047857;">ثبت:</strong> <?php echo htmlspecialchars($c['manager_name'] ?? '-'); ?><br>
                                    <strong style="color:#4338ca;">پیگیری:</strong> <?php echo htmlspecialchars($c['followers_list'] ?? '-'); ?>
                                </td>
                                <td class="btn-action page-actions">
                                    <a href="customer_profile.php?id=<?php echo $c['id']; ?>" target="_blank" class="btn btn-outline" style="font-size:0.75rem; padding:4px 8px;">مشاهده پرونده</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div class="pagination page-actions">
                <?php 
                // دکمه قبلی
                if ($page > 1): ?>
                    <a href="<?php echo buildPageUrl($_GET, 'page', $page - 1); ?>" class="page-link">قبلی</a>
                <?php endif;

                // الگوریتم صفحه‌بندی هوشمند (پنجره لغزان)
                $window = 2; // تعداد صفحاتی که اطراف صفحه فعلی نشان داده می‌شود
                $ellipsisStart = false;
                $ellipsisEnd = false;

                for ($p = 1; $p <= $totalPages; $p++): 
                    // همیشه صفحه اول و آخر، به علاوه محدوده اطراف صفحه فعلی را نشان بده
                    if ($p == 1 || $p == $totalPages || ($p >= $page - $window && $p <= $page + $window)):
                ?>
                        <a href="<?php echo buildPageUrl($_GET, 'page', $p); ?>" class="page-link <?php echo ($p == $page) ? 'active' : ''; ?>"><?php echo $p; ?></a>
                <?php 
                    elseif ($p < $page - $window && !$ellipsisStart): 
                        echo '<span class="page-ellipsis">...</span>';
                        $ellipsisStart = true;
                    elseif ($p > $page + $window && !$ellipsisEnd):
                        echo '<span class="page-ellipsis">...</span>';
                        $ellipsisEnd = true;
                    endif;
                endfor; 

                // دکمه بعدی
                if ($page < $totalPages): ?>
                    <a href="<?php echo buildPageUrl($_GET, 'page', $page + 1); ?>" class="page-link">بعدی</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>