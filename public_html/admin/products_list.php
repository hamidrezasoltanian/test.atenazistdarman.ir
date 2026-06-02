<?php
/*
 * فایل: public_html/admin/products_list.php
 * مدیریت و لیست کالاها - فقط با فیلد جستجو و وضعیت
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
    header("Location: ../login.php");
    exit;
}

// --- دریافت جزئیات کالا (AJAX) ---
if ($isAjax && isset($_POST['action']) && $_POST['action'] === 'get_details') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0;
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, p.price, p.price_imed, p.price_faradis, p.price_dermazon, 
                   p.total_inventory, p.central_store, p.virtual_store, p.scrap_store
            FROM stuffs s
            LEFT JOIN stuff_price_list p ON s.stuff_code = p.stuff_code
            WHERE s.id = ? AND s.is_delete = 0
        ");
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            try {
                $stmtTags = $pdo->prepare("SELECT t.title, t.color FROM product_tag_links ptl JOIN tags t ON ptl.tag_id = t.id WHERE ptl.product_id = ?");
                $stmtTags->execute([$id]);
                $product['tags'] = $stmtTags->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $product['tags'] = [];
            }
        }

        echo json_encode(['status' => $product ? 'success' : 'error', 'data' => $product, 'message' => $product ? '' : 'یافت نشد']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'خطای دیتابیس']);
    }
    exit;
}

// --- جستجوی آنلاین کالا (AJAX/JSON) ---
if ((isset($_GET['action']) && $_GET['action'] === 'search_products')) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '' || mb_strlen($q) < 2) {
        echo json_encode(['status' => 'success', 'items' => []]);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, stuff_name, stuff_code FROM stuffs WHERE is_delete = 0 AND (stuff_name LIKE ? OR stuff_code LIKE ? OR technical_code LIKE ?) LIMIT 20");
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'items' => $items]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'خطای دیتابیس']);
    }
    exit;
}

// --- فیلترها (فقط جستجو و وضعیت) ---
$q = $_GET['q'] ?? '';
$f_status = $_GET['status'] ?? '';

$whereClause = ["s.is_delete = 0"];
$params = [];

if (!empty($q)) {
    $whereClause[] = "(s.stuff_name LIKE ? OR s.stuff_code LIKE ? OR s.technical_code LIKE ? OR s.iran_code LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if ($f_status === 'active') {
    $whereClause[] = "s.active = 1";
} elseif ($f_status === 'inactive') {
    $whereClause[] = "s.active = 0";
}

$whereSql = implode(" AND ", $whereClause);

// --- مرتب‌سازی ---
$sortBy = $_GET['sort_by'] ?? 'id';
$sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');

$allowedSorts = ['id', 'stuff_code', 'stuff_name', 'active', 'total_inventory', 'price', 'save_date'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'id';
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') $sortOrder = 'DESC';

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

// --- پرینت ---
if (isset($_GET['print_data'])) {
    if (ob_get_length()) ob_clean();
    $sqlAll = "
        SELECT s.*, p.total_inventory, p.price
        FROM stuffs s
        LEFT JOIN stuff_price_list p ON s.stuff_code = p.stuff_code
        WHERE $whereSql
        ORDER BY $sortBy $sortOrder
    ";
    $stmtAll = $pdo->prepare($sqlAll);
    $stmtAll->execute($params);
    $all = $stmtAll->fetchAll();

    foreach ($all as $prod) {
        echo "<tr>
            <td style='text-align:center;'>" . htmlspecialchars($prod['stuff_code'] ?? '') . "</td>
            <td class='name-col'>" . htmlspecialchars($prod['stuff_name'] ?? '') . "</td>
            <td style='text-align:center;'>" . htmlspecialchars($prod['technical_code'] ?? '-') . "</td>
            <td style='text-align:center;'>" . ($prod['active'] ? 'فعال' : 'غیرفعال') . "</td>
            <td style='text-align:center;'>" . number_format($prod['total_inventory'] ?? 0) . "</td>
            <td style='text-align:center;'>" . number_format($prod['price'] ?? 0) . "</td>
            <td style='text-align:center;'>" . date('Y/m/d', strtotime($prod['save_date'] ?? 'now')) . "</td>
        </tr>";
    }
    exit;
}

// --- صفحه‌بندی ---
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM stuffs s
    LEFT JOIN stuff_price_list p ON s.stuff_code = p.stuff_code
    WHERE $whereSql
");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$sql = "
    SELECT s.*, p.price, p.price_imed, p.price_faradis, p.price_dermazon, 
           p.total_inventory, p.central_store, p.virtual_store, p.scrap_store
    FROM stuffs s
    LEFT JOIN stuff_price_list p ON s.stuff_code = p.stuff_code
    WHERE $whereSql
    ORDER BY $sortBy $sortOrder
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle = 'مدیریت کالاها';
$basePath = '../';

$extraCss = '
<style>
    /* ========== فیلترها ========== */
    .filters-container {
        background: #fff;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 25px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        border: 1px solid #e5e7eb;
    }
    
    .filter-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        align-items: flex-end;
    }
    
    .filter-group {
        flex: 1;
        min-width: 180px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .filter-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #374151;
    }
    
    .search-input-filter,
    .filter-select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 10px;
        font-size: 0.9rem;
        transition: all 0.2s ease;
        background: #fff;
        font-family: inherit;
    }
    
    .search-input-filter:focus,
    .filter-select:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
    }
    
    .filter-actions {
        display: flex;
        gap: 12px;
        align-items: flex-end;
    }
    
    .filter-actions .btn {
        padding: 10px 20px;
        font-weight: 500;
        border-radius: 10px;
        cursor: pointer;
        border: none;
        font-size: 0.9rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    
    .btn-primary {
        background: #3b82f6;
        color: white;
    }
    
    .btn-primary:hover {
        background: #2563eb;
    }
    
    .btn-outline {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #d1d5db;
    }
    
    .btn-outline:hover {
        background: #e5e7eb;
    }
    
    /* حالت موبایل */
    @media (max-width: 640px) {
        .filter-grid {
            flex-direction: column;
        }
        .filter-actions {
            width: 100%;
        }
        .filter-actions .btn {
            flex: 1;
        }
        .filters-container {
            padding: 15px;
        }
    }
    
    /* ========== سایر استایل‌ها ========== */
    @media print {
        @page { size: landscape; margin: 10mm; }
        body { background: white !important; font-size: 9pt; }
        .print-table-container { display: block !important; width: 100%; }
        .print-table { width: 100%; border-collapse: collapse; }
        .print-table th, .print-table td { border: 1px solid #000; padding: 5px; vertical-align: middle; }
        .print-table th { background-color: #eee !important; -webkit-print-color-adjust: exact; }
        .name-col { white-space: normal !important; text-align: right; font-weight: bold; }
    }
    
    .screen-table {
        overflow-x: auto;
    }
    
    .screen-table table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    
    .screen-table th {
        background: #f8fafc;
        padding: 14px 12px;
        text-align: right;
        font-weight: 600;
        color: #1e293b;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .screen-table td {
        padding: 12px;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
    }
    
    .status-badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-block;
    }
    
    .status-active {
        background: #dcfce7;
        color: #166534;
    }
    
    .status-inactive {
        background: #fee2e2;
        color: #b91c1c;
    }
    
    .inv-badge {
        background: #eff6ff;
        color: #1d4ed8;
        padding: 4px 10px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.8rem;
        display: inline-block;
    }
    
    .price-tag {
        font-weight: 700;
        color: #0f766e;
        direction: ltr;
        display: inline-block;
    }
    
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 25px;
        flex-wrap: wrap;
    }
    
    .page-link {
        padding: 8px 14px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        color: #475569;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.2s;
    }
    
    .page-link.active {
        background: #3b82f6;
        color: white;
        border-color: #3b82f6;
    }
    
    .page-link:hover:not(.active) {
        background: #f1f5f9;
    }
    
    /* مودال */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }
    
    .modal {
        background: white;
        border-radius: 20px;
        width: 90%;
        max-width: 800px;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: 0 20px 35px rgba(0,0,0,0.2);
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 18px 24px;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .modal-title {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 600;
    }
    
    .close-modal {
        background: none;
        border: none;
        font-size: 28px;
        cursor: pointer;
        color: #94a3b8;
        line-height: 1;
    }
    
    .modal-body {
        padding: 24px;
    }
    
    .details-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }
    
    .details-card {
        background: #f8fafc;
        border-radius: 16px;
        padding: 18px;
        border: 1px solid #e2e8f0;
    }
    
    .details-card h4 {
        margin: 0 0 15px 0;
        font-size: 1rem;
        color: #1e293b;
        border-right: 3px solid #3b82f6;
        padding-right: 12px;
    }
    
    .details-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px dashed #e2e8f0;
    }
    
    .details-label {
        font-weight: 600;
        color: #475569;
    }
    
    .details-value {
        color: #0f172a;
        direction: ltr;
        text-align: left;
    }
    
    @media (max-width: 640px) {
        .details-grid {
            grid-template-columns: 1fr;
        }
        .modal-body {
            padding: 16px;
        }
    }
    
    .loading-overlay {
        text-align: center;
        padding: 40px;
        color: #3b82f6;
    }
    
    .btn-action .btn {
        padding: 6px 14px;
        font-size: 0.75rem;
        border-radius: 8px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        cursor: pointer;
    }
    
    .btn-action .btn:hover {
        background: #e2e8f0;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .page-title {
        font-size: 1.35rem;
        font-weight: 700;
        color: #0f172a;
    }
    
    .badge {
        background: #f1f5f9;
        padding: 4px 12px;
        border-radius: 30px;
        font-size: 0.8rem;
    }
    
    .main-footer {
        text-align: center;
        padding: 20px;
        margin-top: 30px;
        border-top: 1px solid #e2e8f0;
        color: #94a3b8;
        font-size: 0.8rem;
    }
</style>
';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header">
            <div>
                <span class="page-title">📦 لیست و موجودی کالاها</span>
                <span class="badge">تعداد کل: <?php echo number_format($totalRows); ?></span>
            </div>
            <div>
                <button onclick="printAllProducts()" class="btn btn-primary" style="background:#4b5563;">🖨️ پرینت کامل</button>
            </div>
        </div>

        <!-- فرم فیلترها (فقط جستجو و وضعیت) -->
        <form method="GET" class="filters-container">
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">🔍 جستجو (نام، کد، کد فنی، ایران‌کد)</label>
                    <input type="text" name="q" class="search-input-filter" placeholder="متن مورد نظر..." value="<?php echo htmlspecialchars($q); ?>">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">📌 وضعیت</label>
                    <select name="status" class="filter-select">
                        <option value="">همه</option>
                        <option value="active" <?php echo $f_status === 'active' ? 'selected' : ''; ?>>فعال</option>
                        <option value="inactive" <?php echo $f_status === 'inactive' ? 'selected' : ''; ?>>غیرفعال</option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">✓ اعمال فیلتر</button>
                    <?php if(!empty($q) || !empty($f_status)): ?>
                        <a href="products_list.php" class="btn btn-outline">✖ حذف فیلتر</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sortBy); ?>">
            <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sortOrder); ?>">
        </form>

        <!-- جدول اصلی -->
        <div class="screen-table">
            <table>
                <thead>
                    <tr>
                        <th><a href="<?php echo getSortUrl('stuff_code', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none;">📦 کد <?php echo getSortIcon('stuff_code', $sortBy, $sortOrder); ?></a></th>
                        <th><a href="<?php echo getSortUrl('stuff_name', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none;">🏷️ نام کالا <?php echo getSortIcon('stuff_name', $sortBy, $sortOrder); ?></a></th>
                        <th>🔧 کد فنی</th>
                        <th><a href="<?php echo getSortUrl('active', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none;">⚡ وضعیت <?php echo getSortIcon('active', $sortBy, $sortOrder); ?></a></th>
                        <th><a href="<?php echo getSortUrl('total_inventory', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none;">📊 موجودی <?php echo getSortIcon('total_inventory', $sortBy, $sortOrder); ?></a></th>
                        <th><a href="<?php echo getSortUrl('price', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none;">💰 قیمت <?php echo getSortIcon('price', $sortBy, $sortOrder); ?></a></th>
                        <th><a href="<?php echo getSortUrl('save_date', $sortBy, $sortOrder); ?>" style="color:inherit; text-decoration:none;">📅 تاریخ ثبت <?php echo getSortIcon('save_date', $sortBy, $sortOrder); ?></a></th>
                        <th>🔧 عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr><td colspan="8" style="text-align:center; padding:40px; color:#9ca3af;">هیچ کالایی یافت نشد. 🛒</td></tr>
                    <?php else: ?>
                        <?php foreach ($products as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['stuff_code'] ?? ''); ?></td>
                            <td style="font-weight:600;">
                                <a href="product_profile.php?id=<?php echo $p['id']; ?>" style="color:#2563eb; text-decoration:none;">
                                    <?php echo htmlspecialchars($p['stuff_name'] ?? ''); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($p['technical_code'] ?? '-'); ?></td>
                            <td>
                                <span class="status-badge <?php echo $p['active'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $p['active'] ? 'فعال' : 'غیرفعال'; ?>
                                </span>
                             </td>
                            <td><span class="inv-badge"><?php echo number_format($p['total_inventory'] ?? 0); ?></span></td>
                            <td><span class="price-tag"><?php echo number_format($p['price'] ?? 0); ?></span> ریال</td>
                            <td><?php echo $p['save_date'] ? date('Y/m/d', strtotime($p['save_date'])) : '-'; ?></td>
                            <td class="btn-action">
                                <button onclick="viewProduct(<?php echo $p['id']; ?>)" class="btn">🔍 جزئیات</button>
                             </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- جدول مخفی پرینت -->
        <div class="print-table-container" style="display:none;">
            <table class="print-table">
                <thead><tr><th>کد</th><th>نام کالا</th><th>کد فنی</th><th>وضعیت</th><th>موجودی</th><th>قیمت</th><th>تاریخ ثبت</th></tr></thead>
                <tbody id="printTableBody"></tbody>
            </table>
        </div>

        <!-- صفحه‌بندی -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php 
            $queryParams = $_GET; unset($queryParams['page']); $queryString = http_build_query($queryParams);
            if($page > 1): ?><a href="?page=<?php echo $page-1; ?>&<?php echo $queryString; ?>" class="page-link">← قبلی</a><?php endif;
            $start = max(1, $page-2); $end = min($totalPages, $page+2);
            for ($i = $start; $i <= $end; $i++): ?>
                <a href="?page=<?php echo $i; ?>&<?php echo $queryString; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor;
            if($page < $totalPages): ?>
                <a href="?page=<?php echo $page+1; ?>&<?php echo $queryString; ?>" class="page-link">بعدی →</a>
                <a href="?page=<?php echo $totalPages; ?>&<?php echo $queryString; ?>" class="page-link">آخر (<?php echo $totalPages; ?>)</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <footer class="main-footer">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری آتنا زیست درمان</footer>
</main>

<!-- مودال -->
<div class="modal-overlay" id="productModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">📦 جزئیات کالا</h3>
            <button class="close-modal" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body">
            <div id="modalLoading" class="loading-overlay" style="display:none;">⏳ در حال بارگذاری...</div>
            <div class="details-grid" id="detailsContent"></div>
        </div>
    </div>
</div>

<script>
    function viewProduct(id) {
        const modal = document.getElementById('productModal');
        const loading = document.getElementById('modalLoading');
        const detailsDiv = document.getElementById('detailsContent');
        modal.style.display = 'flex';
        loading.style.display = 'flex';
        detailsDiv.innerHTML = '';
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: 'action=get_details&id=' + id
        })
        .then(res => res.json())
        .then(data => {
            loading.style.display = 'none';
            if (data.status === 'success' && data.data) displayProductDetails(data.data);
            else detailsDiv.innerHTML = '<div style="color:red;text-align:center;">❌ خطا در دریافت اطلاعات</div>';
        })
        .catch(() => {
            loading.style.display = 'none';
            detailsDiv.innerHTML = '<div style="color:red;text-align:center;">❌ خطا در ارتباط با سرور</div>';
        });
    }
    
    function displayProductDetails(p) {
        const detailsDiv = document.getElementById('detailsContent');
        let tagsHtml = '';
        if (p.tags && p.tags.length) {
            tagsHtml = `<div class="details-card"><h4>🏷️ برچسب‌ها</h4><div style="display:flex;flex-wrap:wrap;gap:8px;">` + 
                p.tags.map(t => `<span style="background:${t.color || '#e2e8f0'};padding:4px 12px;border-radius:20px;font-size:0.75rem;">${escapeHtml(t.title)}</span>`).join('') + 
                `</div></div>`;
        }
        detailsDiv.innerHTML = `
            <div class="details-card">
                <h4>📄 اطلاعات پایه</h4>
                <div class="details-row"><span class="details-label">کد کالا:</span><span class="details-value">${escapeHtml(p.stuff_code)}</span></div>
                <div class="details-row"><span class="details-label">نام کالا:</span><span class="details-value">${escapeHtml(p.stuff_name)}</span></div>
                <div class="details-row"><span class="details-label">کد فنی:</span><span class="details-value">${escapeHtml(p.technical_code || '-')}</span></div>
                <div class="details-row"><span class="details-label">ایران‌کد:</span><span class="details-value">${escapeHtml(p.iran_code || '-')}</span></div>
                <div class="details-row"><span class="details-label">دسته‌بندی:</span><span class="details-value">${escapeHtml(p.category || '-')}</span></div>
                <div class="details-row"><span class="details-label">وضعیت:</span><span class="details-value"><span class="status-badge ${p.active ? 'status-active' : 'status-inactive'}">${p.active ? 'فعال' : 'غیرفعال'}</span></span></div>
                <div class="details-row"><span class="details-label">تاریخ ثبت:</span><span class="details-value">${p.save_date ? new Date(p.save_date).toLocaleDateString('fa-IR') : '-'}</span></div>
            </div>
            <div class="details-card">
                <h4>💰 قیمت و موجودی</h4>
                <div class="details-row"><span class="details-label">قیمت اصلی:</span><span class="details-value">${numberFormat(p.price)} ریال</span></div>
                <div class="details-row"><span class="details-label">قیمت آیمد:</span><span class="details-value">${numberFormat(p.price_imed)} ریال</span></div>
                <div class="details-row"><span class="details-label">قیمت فرادیس:</span><span class="details-value">${numberFormat(p.price_faradis)} ریال</span></div>
                <div class="details-row"><span class="details-label">قیمت درمازون:</span><span class="details-value">${numberFormat(p.price_dermazon)} ریال</span></div>
                <div class="details-row"><span class="details-label">موجودی کل:</span><span class="details-value">${numberFormat(p.total_inventory)}</span></div>
                <div class="details-row"><span class="details-label">انبار مرکزی:</span><span class="details-value">${numberFormat(p.central_store)}</span></div>
                <div class="details-row"><span class="details-label">انبار مجازی:</span><span class="details-value">${numberFormat(p.virtual_store)}</span></div>
                <div class="details-row"><span class="details-label">انبار ضایعات:</span><span class="details-value">${numberFormat(p.scrap_store)}</span></div>
            </div>
            ${tagsHtml}
        `;
    }
    
    function closeModal() { document.getElementById('productModal').style.display = 'none'; }
    function escapeHtml(str) { if(!str) return ''; return str.replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])); }
    function numberFormat(num) { if(num === null || num === undefined) return '0'; return parseFloat(num).toLocaleString('fa-IR'); }
    
    function printAllProducts() {
        const btn = document.querySelector('.page-header .btn-primary');
        if(!btn) return;
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '⏳ آماده‌سازی...';
        const params = new URLSearchParams(window.location.search);
        fetch('products_list.php?print_data=1&' + params.toString())
            .then(res => res.text())
            .then(html => {
                document.getElementById('printTableBody').innerHTML = html;
                btn.disabled = false;
                btn.innerHTML = original;
                setTimeout(() => window.print(), 300);
            })
            .catch(() => { btn.disabled = false; btn.innerHTML = original; alert('خطا در پرینت'); });
    }
    
    document.getElementById('productModal').addEventListener('click', function(e) { if(e.target === this) closeModal(); });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>