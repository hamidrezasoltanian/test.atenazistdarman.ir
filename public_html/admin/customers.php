<?php
/*
 * فایل: public_html/admin/customers.php
 */

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

$dbPath = __DIR__ . '/../../includes/db.php';
$funcPath = __DIR__ . '/../../includes/functions.php';

if (!file_exists($dbPath) || !file_exists($funcPath)) die("خطا: فایل‌های سیستمی یافت نشدند.");
require_once $dbPath;
require_once $funcPath;

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است.']);
        exit;
    }
    header("Location: ../login.php"); exit;
}

// --- دریافت جزئیات مشتری (AJAX) ---
if ($isAjax && isset($_POST['action']) && $_POST['action'] === 'get_details') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0;
    try {
        $stmt = $pdo->prepare("SELECT *, (SELECT GROUP_CONCAT(full_name SEPARATOR '، ') FROM customer_followers WHERE company_num = customers.company_num) AS followers_list FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // استخراج برچسب‌های مشتری برای نمایش در مودال
        if ($customer) {
            $stmtTags = $pdo->prepare("SELECT t.title, t.color FROM customer_tag_links ctl JOIN tags t ON ctl.tag_id = t.id WHERE ctl.customer_id = ?");
            $stmtTags->execute([$id]);
            $customer['tags'] = $stmtTags->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['status' => $customer ? 'success' : 'error', 'data' => $customer, 'message' => $customer ? '' : 'یافت نشد']);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'خطای دیتابیس']); }
    exit;
}

// --- جستجوی آنلاین مشتری (AJAX/JSON) ---
if ((isset($_GET['action']) && $_GET['action'] === 'search_customers')) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '' || mb_strlen($q) < 2) {
        echo json_encode(['status' => 'success', 'items' => []]);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, company_name, company_code FROM customers WHERE company_name LIKE ? OR company_code LIKE ? LIMIT 20");
        $stmt->execute(["%$q%", "%$q%"]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'items' => $items]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'خطای دیتابیس']);
    }
    exit;
}

// --- فیلترها ---
$q = $_GET['q'] ?? '';
$f_type = $_GET['type'] ?? '';
$f_manager = $_GET['manager'] ?? '';
$f_follower = $_GET['follower'] ?? '';
$f_state = $_GET['state'] ?? '';
$f_city = $_GET['city'] ?? '';

// دریافت برچسب‌های انتخاب شده (به صورت آرایه)
$f_tags = isset($_GET['tags']) && is_array($_GET['tags']) ? array_map('intval', $_GET['tags']) : [];
$f_tags = array_filter($f_tags, function($v) { return $v > 0; });

$whereClause = ["1=1"];
$params = [];

if (!empty($q)) {
    $whereClause[] = "(company_name LIKE ? OR mobile LIKE ? OR company_code LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if (!empty($f_type)) { $whereClause[] = "type_name = ?"; $params[] = $f_type; }
if (!empty($f_manager)) { $whereClause[] = "manager_name = ?"; $params[] = $f_manager; }
if (!empty($f_follower)) { 
    $whereClause[] = "EXISTS (SELECT 1 FROM customer_followers cf WHERE cf.company_num = customers.company_num AND cf.full_name = ?)"; 
    $params[] = $f_follower; 
}
if (!empty($f_state)) { $whereClause[] = "state = ?"; $params[] = $f_state; }
if (!empty($f_city)) { $whereClause[] = "city = ?"; $params[] = $f_city; }

// فیلتر برچسب‌ها
if (!empty($f_tags)) {
    $inQuery = implode(',', array_fill(0, count($f_tags), '?'));
    $whereClause[] = "EXISTS (SELECT 1 FROM customer_tag_links ctl WHERE ctl.customer_id = customers.id AND ctl.tag_id IN ($inQuery))";
    $params = array_merge($params, $f_tags);
}

$whereSql = implode(" AND ", $whereClause);

// --- منطق مرتب‌سازی (سورت) ---
$sortBy = $_GET['sort_by'] ?? 'id';
$sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');

$allowedSorts = ['id', 'company_code', 'company_name', 'type_name', 'manager_name', 'mobile', 'state'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'id';
}
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
    $sortOrder = 'DESC';
}

function getSortUrl($col, $currentSort, $currentOrder) {
    $order = ($currentSort === $col && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    $params = $_GET;
    $params['sort_by'] = $col;
    $params['sort_order'] = $order;
    unset($params['page']); 
    return '?' . http_build_query($params);
}

function getSortIcon($col, $currentSort, $currentOrder) {
    if ($currentSort !== $col) return '<span style="color:#cbd5e1; font-size:0.8rem; margin-right:4px;">↕</span>';
    return $currentOrder === 'ASC' 
        ? '<span style="color:#2563eb; font-size:0.9rem; margin-right:4px;">↑</span>' 
        : '<span style="color:#2563eb; font-size:0.9rem; margin-right:4px;">↓</span>';
}

// --- پرینت کامل (AJAX) ---
if (isset($_GET['print_data'])) {
    if (ob_get_length()) ob_clean();
    $sqlAll = "SELECT *, (SELECT GROUP_CONCAT(full_name SEPARATOR '، ') FROM customer_followers WHERE company_num = customers.company_num) AS followers_list FROM customers WHERE $whereSql ORDER BY $sortBy $sortOrder";
    $stmtAll = $pdo->prepare($sqlAll);
    $stmtAll->execute($params);
    $all = $stmtAll->fetchAll();
    
    foreach ($all as $c) {
        $location = $c['state'] ?? '';
        if (!empty($c['city'])) {
            $location .= ' - ' . $c['city'];
        }

        echo "<tr>
            <td style='text-align:center;'>".htmlspecialchars($c['company_code'] ?? '')."</td>
            <td class='name-col'>".htmlspecialchars($c['company_name'] ?? '')."</td>
            <td style='text-align:center;'>".htmlspecialchars($c['type_name'] ?? '')."</td>
            <td style='text-align:center;'>".htmlspecialchars($c['manager_name'] ?? '')."</td>
            <td style='text-align:center;'>".htmlspecialchars($c['followers_list'] ?? '-')."</td>
            <td style='text-align:center;'>".htmlspecialchars($c['mobile'] ?? '')."</td>
            <td style='text-align:center;'>".htmlspecialchars($c['phone'] ?? '')."</td>
            <td style='text-align:center;'>".htmlspecialchars($location)."</td>
        </tr>";
    }
    exit;
}

// --- Dropdowns ---
$types = $pdo->query("SELECT DISTINCT type_name FROM customers WHERE type_name IS NOT NULL ORDER BY type_name")->fetchAll(PDO::FETCH_COLUMN);
$managers = $pdo->query("SELECT DISTINCT manager_name FROM customers WHERE manager_name IS NOT NULL ORDER BY manager_name")->fetchAll(PDO::FETCH_COLUMN);
$followers = $pdo->query("SELECT DISTINCT full_name FROM customer_followers WHERE full_name IS NOT NULL AND full_name != '' ORDER BY full_name")->fetchAll(PDO::FETCH_COLUMN);
$states = $pdo->query("SELECT DISTINCT state FROM customers WHERE state IS NOT NULL ORDER BY state")->fetchAll(PDO::FETCH_COLUMN);

$citiesQuery = "SELECT DISTINCT city FROM customers WHERE city IS NOT NULL";
if(!empty($f_state)) $citiesQuery .= " AND state = '$f_state'";
$citiesQuery .= " ORDER BY city";
$cities = $pdo->query($citiesQuery)->fetchAll(PDO::FETCH_COLUMN);

$allTags = [];
try {
    $allTags = $pdo->query("SELECT id, title FROM tags ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) { }

// --- صفحه‌بندی ---
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 10; 
$offset = ($page - 1) * $limit;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$sql = "SELECT *, (SELECT GROUP_CONCAT(full_name SEPARATOR '، ') FROM customer_followers WHERE company_num = customers.company_num) AS followers_list FROM customers WHERE $whereSql ORDER BY $sortBy $sortOrder LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageTitle = 'مدیریت مشتریان';
$basePath = '../';
$extraCss = '<link rel="stylesheet" href="../assets/css/customers.css">';
$extraCss .= '<style>
    @media print { 
        @page { size: landscape; margin: 10mm; }
        body { background: white !important; font-size: 9pt; }
        .print-table-container { display: block !important; width: 100%; }
        .print-table { width: 100%; border-collapse: collapse; }
        .print-table th, .print-table td { border: 1px solid #000; padding: 5px; vertical-align: middle; }
        .print-table th { background-color: #eee !important; -webkit-print-color-adjust: exact; }
        .name-col { white-space: normal !important; text-align: right; font-weight: bold; }
    } 
    
    /* استایل اختصاصی برای گرید فیلترها */
    .custom-filter-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
        width: 100%;
        align-items: start;
    }
    .custom-filter-grid > div {
        display: flex;
        flex-direction: column;
        justify-content: flex-end; /* تراز کردن فیلدها از پایین */
    }
    .custom-filter-grid .filter-group {
        margin: 0 !important;
        flex-basis: auto !important;
        max-width: 100% !important;
    }
    .custom-filter-grid .tags-wrapper {
        grid-column: span 2;
    }
    .custom-filter-grid .actions-wrapper {
        gap: 10px;
        padding-bottom: 2px;
    }
    
    /* حالت تبلت */
    @media (max-width: 992px) {
        .custom-filter-grid { grid-template-columns: repeat(2, 1fr); }
        .custom-filter-grid .tags-wrapper { grid-column: span 2; }
        .custom-filter-grid .actions-wrapper { 
            grid-column: span 2; 
            flex-direction: row; 
            padding-bottom: 0;
        }
        .custom-filter-grid .actions-wrapper > * { flex: 1; }
    }
    
    /* حالت موبایل */
    @media (max-width: 768px) {
        .custom-filter-grid { grid-template-columns: 1fr; }
        .custom-filter-grid .tags-wrapper { grid-column: span 1; }
        .custom-filter-grid .actions-wrapper { 
            grid-column: span 1; 
            flex-direction: column; 
        }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div>
                <span class="page-title">لیست مشتریان</span> 
                <span class="badge bg-light text-dark" style="margin-right: 10px;">تعداد کل رکوردها: <?php echo number_format($totalRows); ?></span>
            </div>
            <div>
                <button onclick="printAllCustomers()" class="btn btn-secondary">🖨️ پرینت</button>
            </div>
        </div>

        <form method="GET" class="filters-container page-actions" style="display: block;">
            <div class="custom-filter-grid">
                
                <div class="filter-group">
                    <label class="filter-label">جستجو (نام، کد، موبایل)</label>
                    <input type="text" name="q" class="search-input-filter" placeholder="بخشی از نام یا شماره..." value="<?php echo htmlspecialchars($q); ?>">
                </div>
                <div class="filter-group">
                    <label class="filter-label">نوع مشتری</label>
                    <select name="type" class="filter-select"><option value="">همه</option><?php foreach ($types as $t): ?><option value="<?php echo $t; ?>" <?php echo $f_type==$t ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option><?php endforeach; ?></select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">ثبت‌کننده</label>
                    <select name="manager" class="filter-select"><option value="">همه</option><?php foreach ($managers as $m): ?><option value="<?php echo $m; ?>" <?php echo $f_manager==$m ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option><?php endforeach; ?></select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">پیگیری‌کننده</label>
                    <select name="follower" class="filter-select"><option value="">همه</option><?php foreach ($followers as $f): ?><option value="<?php echo htmlspecialchars($f); ?>" <?php echo $f_follower==$f ? 'selected' : ''; ?>><?php echo htmlspecialchars($f); ?></option><?php endforeach; ?></select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">استان</label>
                    <select name="state" class="filter-select" onchange="this.form.submit()"><option value="">همه</option><?php foreach ($states as $s): ?><option value="<?php echo $s; ?>" <?php echo $f_state==$s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option><?php endforeach; ?></select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">شهر</label>
                    <select name="city" class="filter-select"><option value="">همه</option><?php foreach ($cities as $c): ?><option value="<?php echo $c; ?>" <?php echo $f_city==$c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option><?php endforeach; ?></select>
                </div>
                
                <div class="filter-group tags-wrapper">
                    <label class="filter-label" title="با نگه‌داشتن کلید Ctrl می‌توانید چند برچسب را همزمان انتخاب کنید.">برچسب‌ها (برای انتخاب چندگانه کلید Ctrl را نگه دارید)</label>
                    <select name="tags[]" class="filter-select" multiple style="min-height: 95px; padding: 8px;">
                        <?php foreach ($allTags as $tagItem): ?>
                            <option value="<?php echo $tagItem['id']; ?>" <?php echo in_array($tagItem['id'], $f_tags) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tagItem['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="actions-wrapper">
                    <button type="submit" class="btn btn-primary" style="min-height: 42px; display: flex; align-items: center; justify-content: center;">اعمال فیلتر</button>
                    <?php if(!empty($q) || !empty($f_type) || !empty($f_manager) || !empty($f_follower) || !empty($f_state) || !empty($f_city) || !empty($f_tags)): ?>
                        <a href="customers.php" class="btn btn-outline" style="min-height: 42px; display: flex; align-items: center; justify-content: center;">حذف فیلتر</a>
                    <?php endif; ?>
                </div>

            </div>

            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sortBy); ?>">
            <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sortOrder); ?>">
        </form>

        <div class="table-responsive screen-table">
            <table>
                <thead>
                    <tr>
                        <th><a href="<?php echo getSortUrl('company_code', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">کد <?php echo getSortIcon('company_code', $sortBy, $sortOrder); ?></a></th>
                        <th><a href="<?php echo getSortUrl('company_name', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">نام مشتری <?php echo getSortIcon('company_name', $sortBy, $sortOrder); ?></a></th>
                        <th><a href="<?php echo getSortUrl('type_name', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">نوع <?php echo getSortIcon('type_name', $sortBy, $sortOrder); ?></a></th>
                        <th><a href="<?php echo getSortUrl('manager_name', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">ثبت‌کننده <?php echo getSortIcon('manager_name', $sortBy, $sortOrder); ?></a></th>
                        <th>پیگیری‌کنندگان</th>
                        <th><a href="<?php echo getSortUrl('mobile', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">موبایل <?php echo getSortIcon('mobile', $sortBy, $sortOrder); ?></a></th>
                        <th><a href="<?php echo getSortUrl('state', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center;">استان / شهر <?php echo getSortIcon('state', $sortBy, $sortOrder); ?></a></th>
                        <th class="btn-action">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($customers)): ?>
                        <tr><td colspan="8" style="text-align:center; padding:30px; color:#6b7280;">موردی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($customers as $c): ?>
                        <tr>
                            <td data-label="کد"><?php echo htmlspecialchars($c['company_code'] ?? ''); ?></td>
                            <td data-label="نام" style="font-weight:bold;">
                                <a href="customer_profile.php?id=<?php echo $c['id']; ?>" style="color:#2563eb; text-decoration:none;" title="مشاهده پروفایل">
                                    <?php echo htmlspecialchars($c['company_name'] ?? ''); ?>
                                </a>
                            </td>
                            <td data-label="نوع"><span class="status-badge status-active"><?php echo htmlspecialchars($c['type_name'] ?? ''); ?></span></td>
                            <td data-label="ثبت‌کننده"><?php echo htmlspecialchars($c['manager_name'] ?? ''); ?></td>
                            <td data-label="پیگیری‌کنندگان"><?php echo htmlspecialchars($c['followers_list'] ?? '-'); ?></td>
                            <td data-label="موبایل"><?php echo htmlspecialchars($c['mobile'] ?? ''); ?></td>
                            <td data-label="مکان"><?php echo htmlspecialchars(($c['state'] ?? '') . ' / ' . ($c['city'] ?? '')); ?></td>
                            <td class="btn-action" data-label="عملیات">
                                <button onclick="viewCustomer(<?php echo $c['id']; ?>)" class="btn btn-outline" style="font-size:0.8rem">جزئیات</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="print-table-container">
            <table class="print-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">کد</th>
                        <th style="width: 20%;">نام مشتری</th>
                        <th style="width: 10%;">نوع</th>
                        <th style="width: 12%;">ثبت‌کننده</th>
                        <th style="width: 15%;">پیگیری‌کنندگان</th>
                        <th style="width: 10%;">موبایل</th>
                        <th style="width: 10%;">تلفن</th>
                        <th style="width: 15%;">استان - شهر</th>
                    </tr>
                </thead>
                <tbody id="printTableBody"></tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination page-actions">
            <?php 
            $queryParams = $_GET; unset($queryParams['page']); $queryString = http_build_query($queryParams);
            $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
            
            if($page > 1): ?><a href="?page=<?php echo $page-1; ?>&<?php echo $queryString; ?>" class="page-link">قبلی</a><?php endif;
            
            for ($i = $start; $i <= $end; $i++): ?>
                <a href="?page=<?php echo $i; ?>&<?php echo $queryString; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor;
            
            if($page < $totalPages): ?>
                <a href="?page=<?php echo $page+1; ?>&<?php echo $queryString; ?>" class="page-link">بعدی</a>
                <a href="?page=<?php echo $totalPages; ?>&<?php echo $queryString; ?>" class="page-link">آخر (<?php echo $totalPages; ?>)</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری آتنا زیست درمان</footer>
</main>

<div class="print-footer">© 2026 سامانه اتوماسیون اداری آتنا زیست درمان</div>

<div class="modal-overlay" id="customerModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">جزئیات مشتری</h3>
            <button class="close-modal" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body">
            <div id="modalLoading" class="loading-overlay" style="display:none;">در حال بارگذاری...</div>
            <div class="details-grid" id="detailsContent"></div>
        </div>
    </div>
</div>

<script src="../assets/js/customers.js"></script>
<script>
    function printAllCustomers() {
        const btn = document.querySelector('.btn-secondary');
        if(!btn) return;
        
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '⏳ آماده‌سازی...';

        const urlParams = new URLSearchParams(window.location.search);
        fetch('customers.php?print_data=1&' + urlParams.toString())
        .then(response => response.text())
        .then(html => {
            const printContainer = document.getElementById('printTableBody');
            if(printContainer) {
                printContainer.innerHTML = html;
                btn.disabled = false;
                btn.innerHTML = originalText;
                setTimeout(() => { window.print(); }, 500);
            }
        })
        .catch(error => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            alert('خطا در دریافت اطلاعات');
        });
    }

    window.addEventListener('beforeprint', function() {
        const headerTitle = document.querySelector('.print-header-center');
        if(headerTitle) headerTitle.innerText = 'گزارش لیست مشتریان';
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>