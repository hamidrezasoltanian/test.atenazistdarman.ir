<?php
/*
 * فایل: public_html/admin/customers.php — بازطراحی کامل با فیلترینگ پیشرفته
 */

ob_start();
session_start();

$root = dirname(__DIR__, 2);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['error' => 'نشست منقضی']); exit; }
    header('Location: ../login.php'); exit;
}

// ── AJAX: جزئیات مشتری ──────────────────────────────────────────
if ($isAjax && isset($_POST['action']) && $_POST['action'] === 'get_details') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare(
            "SELECT *, (SELECT GROUP_CONCAT(full_name SEPARATOR '، ')
             FROM customer_followers WHERE company_num = customers.company_num) AS followers_list
             FROM customers WHERE id = ?"
        );
        $stmt->execute([$id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            $st = $pdo->prepare("SELECT t.title, t.color FROM customer_tag_links ctl JOIN tags t ON ctl.tag_id = t.id WHERE ctl.customer_id = ?");
            $st->execute([$id]);
            $customer['tags'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['status' => $customer ? 'success' : 'error', 'data' => $customer], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'خطای دیتابیس']); }
    exit;
}

// ── AJAX: جستجوی سریع (برای سایر ماژول‌ها) ─────────────────────
if ($action === 'search_customers') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(['status' => 'success', 'items' => []]); exit; }
    try {
        $stmt = $pdo->prepare("SELECT id, company_name, company_code FROM customers WHERE company_name LIKE ? OR company_code LIKE ? LIMIT 20");
        $stmt->execute(["%$q%", "%$q%"]);
        echo json_encode(['status' => 'success', 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'خطای دیتابیس']); }
    exit;
}

// ── AJAX: شهرهای یک استان ─────────────────────────────────────────
if ($action === 'get_cities') {
    header('Content-Type: application/json; charset=utf-8');
    $state = trim($_GET['state'] ?? '');
    if ($state === '') { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("SELECT DISTINCT city FROM customers WHERE city IS NOT NULL AND city != '' AND state = ? ORDER BY city");
    $stmt->execute([$state]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── تابع ساخت WHERE از فیلترها ──────────────────────────────────
function buildWhere(array $f): array {
    $where  = ['1=1'];
    $params = [];
    if (!empty($f['q'])) {
        $like = "%{$f['q']}%";
        $where[] = '(c.company_name LIKE ? OR c.mobile LIKE ? OR c.company_code LIKE ? OR c.phone LIKE ?)';
        $params  = array_merge($params, [$like, $like, $like, $like]);
    }
    if (!empty($f['type']))     { $where[] = 'c.type_name = ?';    $params[] = $f['type']; }
    if (!empty($f['manager']))  { $where[] = 'c.manager_name = ?'; $params[] = $f['manager']; }
    if (!empty($f['follower'])) {
        $where[] = 'EXISTS (SELECT 1 FROM customer_followers cf WHERE cf.company_num = c.company_num AND cf.full_name = ?)';
        $params[] = $f['follower'];
    }
    if (!empty($f['state'])) { $where[] = 'c.state = ?'; $params[] = $f['state']; }
    if (!empty($f['city']))  { $where[] = 'c.city = ?';  $params[] = $f['city']; }
    if (!empty($f['tags'])) {
        $tags = array_values(array_filter(array_map('intval', (array)$f['tags']), fn($v) => $v > 0));
        if ($tags) {
            $in = implode(',', array_fill(0, count($tags), '?'));
            $where[] = "EXISTS (SELECT 1 FROM customer_tag_links ctl WHERE ctl.customer_id = c.id AND ctl.tag_id IN ($in))";
            $params  = array_merge($params, $tags);
        }
    }
    return [implode(' AND ', $where), $params];
}

// ── AJAX: لیست مشتریان ───────────────────────────────────────────
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $f = [
        'q'        => trim($_GET['q'] ?? ''),
        'type'     => trim($_GET['type'] ?? ''),
        'manager'  => trim($_GET['manager'] ?? ''),
        'follower' => trim($_GET['follower'] ?? ''),
        'state'    => trim($_GET['state'] ?? ''),
        'city'     => trim($_GET['city'] ?? ''),
        'tags'     => (array)($_GET['tags'] ?? []),
    ];
    $allowedSorts = ['id','company_code','company_name','type_name','manager_name','mobile','state'];
    $sortBy    = in_array($_GET['sort_by'] ?? '', $allowedSorts) ? $_GET['sort_by'] : 'id';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 25;
    $offset = ($page - 1) * $limit;

    [$whereSql, $params] = buildWhere($f);

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereSql");
    $cntStmt->execute($params);
    $totalRows = (int)$cntStmt->fetchColumn();

    $sql = "SELECT c.*,
                   (SELECT GROUP_CONCAT(cf.full_name SEPARATOR '، ')
                    FROM customer_followers cf WHERE cf.company_num = c.company_num) AS followers_list
            FROM customers c
            WHERE $whereSql
            ORDER BY c.`$sortBy` $sortOrder
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $st = $pdo->prepare("SELECT t.title, t.color FROM customer_tag_links ctl JOIN tags t ON ctl.tag_id = t.id WHERE ctl.customer_id = ?");
        $st->execute([$row['id']]);
        $row['tags'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($row);

    echo json_encode(['total' => $totalRows, 'page' => $page, 'limit' => $limit, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: پرینت ──────────────────────────────────────────────────
if (isset($_GET['print_data'])) {
    $f = [
        'q'        => trim($_GET['q'] ?? ''),
        'type'     => trim($_GET['type'] ?? ''),
        'manager'  => trim($_GET['manager'] ?? ''),
        'follower' => trim($_GET['follower'] ?? ''),
        'state'    => trim($_GET['state'] ?? ''),
        'city'     => trim($_GET['city'] ?? ''),
        'tags'     => (array)($_GET['tags'] ?? []),
    ];
    $allowedSorts = ['id','company_code','company_name','type_name','manager_name','mobile','state'];
    $sortBy    = in_array($_GET['sort_by'] ?? '', $allowedSorts) ? $_GET['sort_by'] : 'id';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    [$whereSql, $params] = buildWhere($f);
    $stmt = $pdo->prepare(
        "SELECT c.*, (SELECT GROUP_CONCAT(cf.full_name SEPARATOR '، ') FROM customer_followers cf WHERE cf.company_num = c.company_num) AS followers_list
         FROM customers c WHERE $whereSql ORDER BY c.`$sortBy` $sortOrder"
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $c) {
        echo "<tr>
            <td>".htmlspecialchars($c['company_code'] ?? '')."</td>
            <td>".htmlspecialchars($c['company_name'] ?? '')."</td>
            <td>".htmlspecialchars($c['type_name'] ?? '')."</td>
            <td>".htmlspecialchars($c['manager_name'] ?? '')."</td>
            <td>".htmlspecialchars($c['followers_list'] ?? '-')."</td>
            <td>".htmlspecialchars($c['mobile'] ?? '')."</td>
            <td>".htmlspecialchars($c['phone'] ?? '')."</td>
            <td>".htmlspecialchars(($c['state'] ?? '').($c['city'] ? ' — '.$c['city'] : ''))."</td>
        </tr>";
    }
    exit;
}

// ── داده‌های اولیه صفحه ──────────────────────────────────────────
$types     = $pdo->query("SELECT DISTINCT type_name FROM customers WHERE type_name IS NOT NULL AND type_name != '' ORDER BY type_name")->fetchAll(PDO::FETCH_COLUMN);
$managers  = $pdo->query("SELECT DISTINCT manager_name FROM customers WHERE manager_name IS NOT NULL AND manager_name != '' ORDER BY manager_name")->fetchAll(PDO::FETCH_COLUMN);
$followers = $pdo->query("SELECT DISTINCT full_name FROM customer_followers WHERE full_name IS NOT NULL AND full_name != '' ORDER BY full_name")->fetchAll(PDO::FETCH_COLUMN);
$states    = $pdo->query("SELECT DISTINCT state FROM customers WHERE state IS NOT NULL AND state != '' ORDER BY state")->fetchAll(PDO::FETCH_COLUMN);
$allTags   = $pdo->query("SELECT id, title, color FROM tags WHERE is_deleted=0 ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);

// آمار کلی
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$typeStats = $pdo->query(
    "SELECT type_name, COUNT(*) AS cnt FROM customers WHERE type_name IS NOT NULL AND type_name != '' GROUP BY type_name ORDER BY cnt DESC LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'مدیریت مشتریان';
$basePath  = '../';

ob_end_clean();
require_once $root . '/templates/header.php';
require_once $root . '/templates/sidebar.php';
?>

<style>
/* ── بازطراحی صفحه مشتریان ──────────────────────────────────── */
.cust-page { padding: 24px; }

/* کارت‌های آمار */
.cust-stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 14px; margin-bottom: 24px; }
.cust-stat { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; display: flex; align-items: center; gap: 14px; }
.cust-stat-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
.cust-stat-icon.blue  { background: #eff6ff; }
.cust-stat-icon.green { background: #f0fdf4; }
.cust-stat-icon.amber { background: #fffbeb; }
.cust-stat-icon.rose  { background: #fff1f2; }
.cust-stat-icon.purple{ background: #faf5ff; }
.cust-stat-val  { font-size: 1.35rem; font-weight: 700; color: #0f172a; line-height: 1; }
.cust-stat-lbl  { font-size: .75rem; color: #64748b; margin-top: 3px; }

/* پنل فیلترها */
.cust-filter-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 16px;
}
.cust-filter-top {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.cust-search-wrap {
    flex: 1;
    min-width: 220px;
    position: relative;
}
.cust-search-wrap input {
    width: 100%;
    height: 42px;
    padding: 0 42px 0 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-family: Vazirmatn, sans-serif;
    font-size: .9rem;
    color: #0f172a;
    background: #f8fafc;
    transition: border-color .2s, background .2s;
    box-sizing: border-box;
}
.cust-search-wrap input:focus { outline: none; border-color: #3b82f6; background: #fff; }
.cust-search-wrap .search-icon {
    position: absolute;
    right: 13px; top: 50%; transform: translateY(-50%);
    color: #94a3b8; font-size: .95rem; pointer-events: none;
}
.cust-search-wrap .search-clear {
    position: absolute;
    left: 11px; top: 50%; transform: translateY(-50%);
    color: #94a3b8; font-size: .8rem; cursor: pointer;
    display: none; background: none; border: none; padding: 2px;
    line-height: 1;
}
.cust-filter-toggle {
    height: 42px; padding: 0 16px;
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    background: #f8fafc; color: #475569; font-family: Vazirmatn, sans-serif;
    font-size: .85rem; cursor: pointer; display: flex; align-items: center; gap: 8px;
    transition: all .2s; white-space: nowrap;
}
.cust-filter-toggle:hover, .cust-filter-toggle.active { border-color: #3b82f6; background: #eff6ff; color: #2563eb; }
.cust-filter-toggle .badge-count { background: #3b82f6; color: #fff; border-radius: 999px; padding: 1px 7px; font-size: .72rem; }

/* گرید فیلترهای پیشرفته */
.cust-filter-body { display: none; border-top: 1px solid #f1f5f9; margin-top: 14px; padding-top: 16px; }
.cust-filter-body.open { display: block; }
.cust-filter-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
@media (max-width: 900px) { .cust-filter-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 600px) { .cust-filter-grid { grid-template-columns: 1fr; } }

.cust-filter-field label {
    display: block; font-size: .78rem; font-weight: 600;
    color: #475569; margin-bottom: 5px;
}
.cust-filter-field select, .cust-filter-field input {
    width: 100%; height: 40px;
    border: 1.5px solid #e2e8f0; border-radius: 9px;
    padding: 0 12px; font-family: Vazirmatn, sans-serif; font-size: .85rem;
    color: #0f172a; background: #f8fafc;
    transition: border-color .2s;
    box-sizing: border-box;
    appearance: none;
}
.cust-filter-field select:focus, .cust-filter-field input:focus { outline: none; border-color: #3b82f6; background: #fff; }

/* تگ‌های قابل کلیک */
.tags-chip-wrap { grid-column: span 2; }
@media (max-width: 900px) { .tags-chip-wrap { grid-column: span 2; } }
@media (max-width: 600px) { .tags-chip-wrap { grid-column: span 1; } }
.tag-chips { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 5px; }
.tag-chip {
    padding: 4px 12px; border-radius: 999px; font-size: .78rem; font-weight: 600;
    border: 1.5px solid #e2e8f0; cursor: pointer; transition: all .15s;
    background: #f8fafc; color: #64748b; user-select: none;
}
.tag-chip:hover { border-color: #93c5fd; color: #2563eb; background: #eff6ff; }
.tag-chip.selected { border-color: #3b82f6; background: #3b82f6; color: #fff; }

/* چیپ‌های فیلتر فعال */
.active-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; min-height: 0; }
.active-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px 4px 8px; border-radius: 999px;
    background: #eff6ff; border: 1px solid #bfdbfe;
    font-size: .78rem; color: #1d4ed8; font-weight: 500;
}
.active-chip .rm { cursor: pointer; font-size: .85rem; color: #60a5fa; line-height: 1; }
.active-chip .rm:hover { color: #1d4ed8; }
.chips-clear-all {
    padding: 4px 10px; border-radius: 999px;
    background: #fff1f2; border: 1px solid #fecdd3;
    font-size: .78rem; color: #e11d48; cursor: pointer; font-weight: 500;
}

/* جدول */
.cust-table-wrap {
    background: #fff; border: 1px solid #e2e8f0;
    border-radius: 14px; overflow: hidden;
}
.cust-table-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; border-bottom: 1px solid #f1f5f9;
    gap: 12px; flex-wrap: wrap;
}
.cust-table-header-title { font-size: .9rem; font-weight: 700; color: #0f172a; }
.cust-table-header-actions { display: flex; gap: 8px; }
.cust-count-badge { font-size: .8rem; color: #64748b; background: #f1f5f9; padding: 3px 10px; border-radius: 999px; }

table.cust-tbl { width: 100%; border-collapse: collapse; }
table.cust-tbl thead th {
    background: #f8fafc; color: #475569; font-size: .78rem;
    font-weight: 700; padding: 11px 14px; text-align: right;
    border-bottom: 1px solid #e2e8f0; white-space: nowrap;
}
table.cust-tbl thead th.sortable { cursor: pointer; user-select: none; }
table.cust-tbl thead th.sortable:hover { background: #eff6ff; color: #2563eb; }
table.cust-tbl thead th .sort-icon { margin-right: 5px; opacity: .4; font-size: .8rem; }
table.cust-tbl thead th.sort-asc .sort-icon, table.cust-tbl thead th.sort-desc .sort-icon { opacity: 1; color: #2563eb; }
table.cust-tbl thead th.sort-asc .sort-icon::after { content: '↑'; }
table.cust-tbl thead th.sort-desc .sort-icon::after { content: '↓'; }
table.cust-tbl thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '↕'; }

table.cust-tbl tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .12s; }
table.cust-tbl tbody tr:last-child { border-bottom: none; }
table.cust-tbl tbody tr:hover { background: #f8fafc; }
table.cust-tbl td { padding: 11px 14px; font-size: .83rem; color: #334155; vertical-align: middle; }

.cust-name-link { color: #1d4ed8; font-weight: 600; text-decoration: none; font-size: .88rem; }
.cust-name-link:hover { text-decoration: underline; }
.cust-code { font-size: .75rem; color: #94a3b8; display: block; margin-top: 2px; }

.fin-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 999px; font-size: .72rem; font-weight: 600; }
.fin-badge.blue   { background: #eff6ff; color: #1d4ed8; }
.fin-badge.green  { background: #f0fdf4; color: #16a34a; }
.fin-badge.amber  { background: #fffbeb; color: #d97706; }
.fin-badge.rose   { background: #fff1f2; color: #e11d48; }
.fin-badge.purple { background: #faf5ff; color: #7c3aed; }
.fin-badge.gray   { background: #f1f5f9; color: #475569; }
.fin-badge.cyan   { background: #ecfeff; color: #0891b2; }

.row-tag { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: .68rem; font-weight: 600; margin: 1px 2px; }

.cust-actions-cell { white-space: nowrap; }
.btn-detail {
    padding: 5px 13px; border-radius: 8px; font-size: .78rem; font-family: Vazirmatn, sans-serif;
    cursor: pointer; border: 1.5px solid #e2e8f0; background: #fff; color: #475569;
    transition: all .15s; font-weight: 500;
}
.btn-detail:hover { border-color: #3b82f6; color: #2563eb; background: #eff6ff; }

/* اسکلتون لودینگ */
.skeleton { background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%); background-size: 200% 100%; animation: shimmer 1.2s infinite; border-radius: 6px; height: 16px; }
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
.skeleton-row td { padding: 14px 14px; }

/* صفحه‌بندی */
.cust-pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 18px; border-top: 1px solid #f1f5f9; flex-wrap: wrap; gap: 10px;
}
.cust-pagination-info { font-size: .8rem; color: #64748b; }
.cust-pagination-pages { display: flex; gap: 4px; }
.pg-btn {
    min-width: 34px; height: 34px; border-radius: 8px; border: 1.5px solid #e2e8f0;
    background: #fff; color: #475569; font-family: Vazirmatn, sans-serif; font-size: .82rem;
    cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
    font-weight: 500; transition: all .15s; padding: 0 8px;
}
.pg-btn:hover { border-color: #3b82f6; color: #2563eb; background: #eff6ff; }
.pg-btn.active { border-color: #3b82f6; background: #3b82f6; color: #fff; }
.pg-btn:disabled { opacity: .4; cursor: not-allowed; }

/* مودال */
.cust-modal-overlay {
    position: fixed; inset: 0; background: rgba(15,23,42,.45);
    display: none; align-items: center; justify-content: center;
    z-index: 9999; padding: 20px;
}
.cust-modal-overlay.open { display: flex; }
.cust-modal {
    background: #fff; border-radius: 18px; width: 100%; max-width: 640px;
    max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 60px rgba(15,23,42,.25);
    animation: modalIn .22s ease;
}
@keyframes modalIn { from{opacity:0;transform:scale(.94)} to{opacity:1;transform:scale(1)} }
.cust-modal-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1px solid #f1f5f9; position: sticky; top: 0; background: #fff; z-index: 1;
}
.cust-modal-head h3 { font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0; }
.cust-modal-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: #f1f5f9; cursor: pointer; font-size: 1.1rem; color: #64748b; display: flex; align-items: center; justify-content: center; transition: background .15s; }
.cust-modal-close:hover { background: #e2e8f0; }
.cust-modal-body { padding: 20px 22px; }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 500px) { .detail-grid { grid-template-columns: 1fr; } }
.detail-item label { display: block; font-size: .72rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 3px; }
.detail-item span { font-size: .88rem; color: #0f172a; font-weight: 500; }
.detail-full { grid-column: span 2; }
@media (max-width: 500px) { .detail-full { grid-column: span 1; } }

.modal-tags-row { margin-top: 14px; }
.modal-tags-row label { font-size: .72rem; font-weight: 700; color: #94a3b8; margin-bottom: 6px; display: block; }

.modal-actions { display: flex; gap: 10px; margin-top: 18px; padding-top: 16px; border-top: 1px solid #f1f5f9; }

/* خالی */
.empty-state { text-align: center; padding: 48px 24px; color: #94a3b8; }
.empty-state .icon { font-size: 2.5rem; display: block; margin-bottom: 12px; }
.empty-state p { font-size: .9rem; margin: 0; }

/* پرینت */
.print-wrap { display: none; }
@media print {
    .cust-page .cust-stats, .cust-page .cust-filter-panel,
    .cust-page .cust-table-header-actions, .cust-page .cust-pagination,
    .main-sidebar, .main-header, .cust-modal-overlay { display: none !important; }
    .print-wrap { display: block !important; }
    .cust-table-wrap { border: none; box-shadow: none; }
    table.cust-tbl { font-size: 9pt; }
}
.print-tbl { display: none; }
@media print {
    .print-tbl { display: table !important; width: 100%; border-collapse: collapse; }
    .print-tbl th, .print-tbl td { border: 1px solid #000; padding: 5px 8px; font-size: 9pt; }
    .print-tbl th { background: #eee !important; -webkit-print-color-adjust: exact; }
}
</style>

<div class="content-wrapper cust-page">

    <!-- ── هدر صفحه ── -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="font-size:1.15rem;font-weight:800;color:#0f172a;margin:0;">مدیریت مشتریان</h1>
            <p style="font-size:.8rem;color:#64748b;margin:3px 0 0;">جستجو، فیلتر و مدیریت لیست کامل مشتریان</p>
        </div>
        <div style="display:flex;gap:10px;">
            <button onclick="printAllCustomers()" class="fin-btn" style="gap:6px;background:#fff;border:1.5px solid #e2e8f0;color:#475569;height:40px;padding:0 16px;border-radius:10px;font-family:Vazirmatn,sans-serif;font-size:.83rem;cursor:pointer;display:inline-flex;align-items:center;">
                🖨️ پرینت
            </button>
        </div>
    </div>

    <!-- ── کارت‌های آمار ── -->
    <div class="cust-stats">
        <div class="cust-stat">
            <div class="cust-stat-icon blue">👥</div>
            <div>
                <div class="cust-stat-val"><?= number_format($totalCustomers) ?></div>
                <div class="cust-stat-lbl">کل مشتریان</div>
            </div>
        </div>
        <?php
        $iconColors = ['green','amber','rose','purple','cyan'];
        $icons = ['🏥','🏪','🏢','🔬','🏬'];
        foreach ($typeStats as $i => $ts):
            $clr = $iconColors[$i % count($iconColors)];
            $icn = $icons[$i % count($icons)];
        ?>
        <div class="cust-stat">
            <div class="cust-stat-icon <?= $clr ?>"><?= $icn ?></div>
            <div>
                <div class="cust-stat-val"><?= number_format($ts['cnt']) ?></div>
                <div class="cust-stat-lbl"><?= htmlspecialchars($ts['type_name']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── پنل فیلترها ── -->
    <div class="cust-filter-panel">
        <div class="cust-filter-top">
            <!-- جستجوی زنده -->
            <div class="cust-search-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchInput" placeholder="جستجو در نام، کد، موبایل..." autocomplete="off">
                <button class="search-clear" id="searchClear" title="پاک کردن جستجو">✕</button>
            </div>

            <!-- دکمه باز/بستن فیلترهای پیشرفته -->
            <button class="cust-filter-toggle" id="filterToggleBtn" onclick="toggleFilters()">
                ⚙️ فیلترهای پیشرفته
                <span class="badge-count" id="activeFilterCount" style="display:none;">0</span>
            </button>

            <!-- دکمه ریست کل فیلترها -->
            <button onclick="resetAllFilters()" id="resetFiltersBtn"
                style="height:42px;padding:0 14px;border:1.5px solid #fecdd3;border-radius:10px;background:#fff1f2;color:#e11d48;font-family:Vazirmatn,sans-serif;font-size:.83rem;cursor:pointer;display:none;align-items:center;gap:6px;">
                ✕ حذف فیلترها
            </button>
        </div>

        <!-- فیلترهای پیشرفته (قابل باز/بستن) -->
        <div class="cust-filter-body" id="filterBody">
            <div class="cust-filter-grid">
                <!-- نوع مشتری -->
                <div class="cust-filter-field">
                    <label>نوع مشتری</label>
                    <select id="f_type">
                        <option value="">همه انواع</option>
                        <?php foreach ($types as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- ثبت‌کننده -->
                <div class="cust-filter-field">
                    <label>ثبت‌کننده</label>
                    <select id="f_manager">
                        <option value="">همه</option>
                        <?php foreach ($managers as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- پیگیری‌کننده -->
                <div class="cust-filter-field">
                    <label>پیگیری‌کننده</label>
                    <select id="f_follower">
                        <option value="">همه</option>
                        <?php foreach ($followers as $f): ?>
                        <option value="<?= htmlspecialchars($f) ?>"><?= htmlspecialchars($f) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- استان -->
                <div class="cust-filter-field">
                    <label>استان</label>
                    <select id="f_state" onchange="onStateChange()">
                        <option value="">همه استان‌ها</option>
                        <?php foreach ($states as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- شهر -->
                <div class="cust-filter-field">
                    <label>شهر</label>
                    <select id="f_city">
                        <option value="">همه شهرها</option>
                    </select>
                </div>

                <!-- تگ‌ها -->
                <div class="cust-filter-field tags-chip-wrap">
                    <label>برچسب‌ها</label>
                    <?php if ($allTags): ?>
                    <div class="tag-chips" id="tagChips">
                        <?php foreach ($allTags as $tag): ?>
                        <span class="tag-chip" data-id="<?= $tag['id'] ?>"
                              style="<?= $tag['color'] ? '--tc:'.htmlspecialchars($tag['color']) : '' ?>"
                              onclick="toggleTag(this, <?= $tag['id'] ?>)">
                            <?= htmlspecialchars($tag['title']) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p style="font-size:.8rem;color:#94a3b8;margin:4px 0;">برچسبی تعریف نشده است.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── چیپ‌های فیلتر فعال ── -->
    <div class="active-chips" id="activeChips"></div>

    <!-- ── جدول مشتریان ── -->
    <div class="cust-table-wrap">
        <div class="cust-table-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="cust-table-header-title">لیست مشتریان</span>
                <span class="cust-count-badge" id="resultCount">در حال بارگذاری...</span>
            </div>
            <div class="cust-table-header-actions">
                <span style="font-size:.78rem;color:#94a3b8;align-self:center;">مرتب‌سازی:</span>
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="cust-tbl">
                <thead>
                    <tr>
                        <th class="sortable" data-col="company_code">کد <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="company_name">نام مشتری <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="type_name">نوع <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="manager_name">ثبت‌کننده <span class="sort-icon"></span></th>
                        <th>پیگیری‌کنندگان</th>
                        <th class="sortable" data-col="mobile">موبایل <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="state">استان / شهر <span class="sort-icon"></span></th>
                        <th>برچسب</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody id="custTbody">
                    <tr class="skeleton-row"><td colspan="9"><div class="skeleton"></div></td></tr>
                </tbody>
            </table>
        </div>

        <div class="cust-pagination" id="custPagination" style="display:none;"></div>
    </div>

    <!-- جدول مخفی برای پرینت -->
    <div class="print-wrap">
        <table class="print-tbl">
            <thead><tr>
                <th>کد</th><th>نام مشتری</th><th>نوع</th><th>ثبت‌کننده</th>
                <th>پیگیری‌کنندگان</th><th>موبایل</th><th>تلفن</th><th>استان / شهر</th>
            </tr></thead>
            <tbody id="printTableBody"></tbody>
        </table>
    </div>
</div>

<!-- ── مودال جزئیات ── -->
<div class="cust-modal-overlay" id="custModal" onclick="if(event.target===this)closeModal()">
    <div class="cust-modal">
        <div class="cust-modal-head">
            <h3>جزئیات مشتری</h3>
            <button class="cust-modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="cust-modal-body" id="modalBody">
            <div style="text-align:center;padding:30px;color:#94a3b8;">در حال بارگذاری...</div>
        </div>
    </div>
</div>

<script>
(function() {
'use strict';

// ── وضعیت ─────────────────────────────────────────────────────────
const state = {
    q:        '',
    type:     '',
    manager:  '',
    follower: '',
    state_:   '',
    city:     '',
    tags:     [],
    sortBy:   'id',
    sortOrder:'DESC',
    page:     1,
    total:    0,
    limit:    25,
    loading:  false,
};

// ── debounce ──────────────────────────────────────────────────────
let debTimer = null;
function debounce(fn, ms) {
    clearTimeout(debTimer);
    debTimer = setTimeout(fn, ms);
}

// ── بارگذاری لیست ─────────────────────────────────────────────────
function loadCustomers() {
    if (state.loading) return;
    state.loading = true;

    const params = new URLSearchParams();
    if (state.q)        params.set('q', state.q);
    if (state.type)     params.set('type', state.type);
    if (state.manager)  params.set('manager', state.manager);
    if (state.follower) params.set('follower', state.follower);
    if (state.state_)   params.set('state', state.state_);
    if (state.city)     params.set('city', state.city);
    state.tags.forEach(t => params.append('tags[]', t));
    params.set('sort_by', state.sortBy);
    params.set('sort_order', state.sortOrder);
    params.set('page', state.page);
    params.set('action', 'list');

    const tbody = document.getElementById('custTbody');
    tbody.innerHTML = skeletonRows(5);

    fetch('customers.php?' + params.toString(), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(r => r.json())
        .then(data => {
            state.total   = data.total || 0;
            state.loading = false;
            renderRows(data.rows || []);
            renderPagination(data.total, data.page, data.limit);
            document.getElementById('resultCount').textContent = number_fa(data.total) + ' مشتری';
        })
        .catch(() => {
            state.loading = false;
            document.getElementById('custTbody').innerHTML =
                '<tr><td colspan="9" style="text-align:center;padding:30px;color:#ef4444;">خطا در بارگذاری</td></tr>';
        });
}

function skeletonRows(n) {
    let h = '';
    for (let i = 0; i < n; i++) {
        h += `<tr class="skeleton-row">
            <td><div class="skeleton" style="width:50px"></div></td>
            <td><div class="skeleton" style="width:140px"></div></td>
            <td><div class="skeleton" style="width:70px"></div></td>
            <td><div class="skeleton" style="width:80px"></div></td>
            <td><div class="skeleton" style="width:100px"></div></td>
            <td><div class="skeleton" style="width:90px"></div></td>
            <td><div class="skeleton" style="width:100px"></div></td>
            <td><div class="skeleton" style="width:60px"></div></td>
            <td><div class="skeleton" style="width:60px"></div></td>
        </tr>`;
    }
    return h;
}

// ── رندر ردیف‌ها ──────────────────────────────────────────────────
function renderRows(rows) {
    const tbody = document.getElementById('custTbody');
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="9"><div class="empty-state">
            <span class="icon">🔍</span>
            <p>موردی با این فیلترها یافت نشد.</p>
        </div></td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map(r => {
        const tagsHtml = (r.tags || []).map(t =>
            `<span class="row-tag" style="background:${t.color || '#f1f5f9'};color:${t.color ? textColor(t.color) : '#475569'}">${esc(t.title)}</span>`
        ).join('');

        const location = [r.state, r.city].filter(Boolean).join(' / ');
        const typeBadge = r.type_name ? `<span class="fin-badge ${typeBadgeColor(r.type_name)}">${esc(r.type_name)}</span>` : '—';

        return `<tr>
            <td><span style="font-size:.75rem;color:#94a3b8;">${esc(r.company_code||'')}</span></td>
            <td>
                <a href="customer_profile.php?id=${r.id}" class="cust-name-link">${esc(r.company_name||'')}</a>
            </td>
            <td>${typeBadge}</td>
            <td style="color:#475569;">${esc(r.manager_name||'—')}</td>
            <td style="color:#64748b;font-size:.78rem;">${esc(r.followers_list||'—')}</td>
            <td dir="ltr" style="text-align:right;">${esc(r.mobile||'')}</td>
            <td style="font-size:.8rem;color:#64748b;">${esc(location||'—')}</td>
            <td>${tagsHtml || '<span style="color:#cbd5e1;font-size:.75rem;">—</span>'}</td>
            <td class="cust-actions-cell">
                <button class="btn-detail" onclick="viewCustomer(${r.id})">جزئیات</button>
            </td>
        </tr>`;
    }).join('');
}

function typeBadgeColor(type) {
    const map = {'بیمارستان':'blue','کلینیک':'green','پزشک':'amber','داروخانه':'rose','آزمایشگاه':'purple'};
    for (const k in map) if (type.includes(k)) return map[k];
    return 'gray';
}

function textColor(hex) {
    if (!hex || hex.length < 4) return '#475569';
    const h = hex.replace('#','');
    const r = parseInt(h.substring(0,2),16), g = parseInt(h.substring(2,4),16), b = parseInt(h.substring(4,6),16);
    return (r*299 + g*587 + b*114) / 1000 > 128 ? '#374151' : '#ffffff';
}

// ── صفحه‌بندی ─────────────────────────────────────────────────────
function renderPagination(total, page, limit) {
    const el = document.getElementById('custPagination');
    const totalPages = Math.ceil(total / limit);
    if (totalPages <= 1) { el.style.display = 'none'; return; }
    el.style.display = 'flex';
    const from = (page - 1) * limit + 1;
    const to   = Math.min(page * limit, total);
    let pages = '';

    // First
    pages += `<button class="pg-btn" onclick="goPage(1)" ${page===1?'disabled':''}>«</button>`;
    pages += `<button class="pg-btn" onclick="goPage(${page-1})" ${page===1?'disabled':''}>‹</button>`;

    // Page numbers (window of 5)
    const start = Math.max(1, page - 2);
    const end   = Math.min(totalPages, page + 2);
    if (start > 1) pages += `<button class="pg-btn" onclick="goPage(1)">1</button>`;
    if (start > 2) pages += `<span class="pg-btn" style="cursor:default;border:none;">…</span>`;
    for (let i = start; i <= end; i++) {
        pages += `<button class="pg-btn ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
    }
    if (end < totalPages - 1) pages += `<span class="pg-btn" style="cursor:default;border:none;">…</span>`;
    if (end < totalPages) pages += `<button class="pg-btn" onclick="goPage(${totalPages})">${totalPages}</button>`;

    pages += `<button class="pg-btn" onclick="goPage(${page+1})" ${page===totalPages?'disabled':''}>›</button>`;
    pages += `<button class="pg-btn" onclick="goPage(${totalPages})" ${page===totalPages?'disabled':''}>»</button>`;

    el.innerHTML = `
        <span class="cust-pagination-info">نمایش ${number_fa(from)}–${number_fa(to)} از ${number_fa(total)} مشتری</span>
        <div class="cust-pagination-pages">${pages}</div>`;
}

function goPage(p) {
    state.page = p;
    loadCustomers();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── مرتب‌سازی ─────────────────────────────────────────────────────
document.querySelectorAll('table.cust-tbl thead th.sortable').forEach(th => {
    th.addEventListener('click', () => {
        const col = th.dataset.col;
        if (state.sortBy === col) {
            state.sortOrder = state.sortOrder === 'ASC' ? 'DESC' : 'ASC';
        } else {
            state.sortBy    = col;
            state.sortOrder = 'ASC';
        }
        state.page = 1;
        updateSortHeaders();
        loadCustomers();
    });
});

function updateSortHeaders() {
    document.querySelectorAll('table.cust-tbl thead th.sortable').forEach(th => {
        th.classList.remove('sort-asc','sort-desc');
        if (th.dataset.col === state.sortBy) {
            th.classList.add(state.sortOrder === 'ASC' ? 'sort-asc' : 'sort-desc');
        }
    });
}

// ── جستجو ─────────────────────────────────────────────────────────
const searchInput = document.getElementById('searchInput');
const searchClear = document.getElementById('searchClear');

searchInput.addEventListener('input', () => {
    searchClear.style.display = searchInput.value ? 'flex' : 'none';
    debounce(() => {
        state.q = searchInput.value.trim();
        state.page = 1;
        updateChips();
        loadCustomers();
    }, 320);
});

searchClear.addEventListener('click', () => {
    searchInput.value = '';
    searchClear.style.display = 'none';
    state.q = '';
    state.page = 1;
    updateChips();
    loadCustomers();
});

// ── فیلترهای dropdown ──────────────────────────────────────────────
['f_type','f_manager','f_follower','f_city'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => {
        state[id === 'f_type' ? 'type' : id === 'f_manager' ? 'manager' : id === 'f_follower' ? 'follower' : 'city']
            = document.getElementById(id).value;
        state.page = 1;
        updateChips();
        loadCustomers();
    });
});

// استان با بارگذاری شهرها
window.onStateChange = function() {
    const val = document.getElementById('f_state').value;
    state.state_ = val;
    state.city   = '';
    document.getElementById('f_city').innerHTML = '<option value="">همه شهرها</option>';
    state.page = 1;
    updateChips();
    loadCustomers();
    if (!val) return;
    fetch(`customers.php?action=get_cities&state=${encodeURIComponent(val)}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r => r.json())
        .then(cities => {
            const sel = document.getElementById('f_city');
            cities.forEach(c => sel.insertAdjacentHTML('beforeend', `<option value="${esc(c)}">${esc(c)}</option>`));
        });
};

// ── تگ‌ها ──────────────────────────────────────────────────────────
window.toggleTag = function(el, id) {
    const idx = state.tags.indexOf(id);
    if (idx === -1) { state.tags.push(id); el.classList.add('selected'); }
    else            { state.tags.splice(idx, 1); el.classList.remove('selected'); }
    state.page = 1;
    updateChips();
    loadCustomers();
};

// ── باز/بستن فیلترها ──────────────────────────────────────────────
window.toggleFilters = function() {
    const body = document.getElementById('filterBody');
    const btn  = document.getElementById('filterToggleBtn');
    body.classList.toggle('open');
    btn.classList.toggle('active', body.classList.contains('open'));
};

// ── آپدیت چیپ‌های فعال ───────────────────────────────────────────
const filterLabels = {
    type:     'نوع',
    manager:  'ثبت‌کننده',
    follower: 'پیگیری‌کننده',
    state_:   'استان',
    city:     'شهر',
};
const tagTitles = {};
document.querySelectorAll('.tag-chip').forEach(c => { tagTitles[c.dataset.id] = c.textContent.trim(); });

function updateChips() {
    const container = document.getElementById('activeChips');
    let html = '';
    let count = 0;

    const fieldMap = {type:'f_type', manager:'f_manager', follower:'f_follower', state_:'f_state', city:'f_city'};
    Object.keys(filterLabels).forEach(k => {
        const v = state[k];
        if (v) {
            count++;
            html += `<span class="active-chip">${filterLabels[k]}: <strong>${esc(v)}</strong>
                <button class="rm" onclick="clearFilter('${k}')">✕</button></span>`;
        }
    });
    state.tags.forEach(tid => {
        count++;
        html += `<span class="active-chip">برچسب: <strong>${esc(tagTitles[tid]||tid)}</strong>
            <button class="rm" onclick="clearTag(${tid})">✕</button></span>`;
    });

    container.innerHTML = count > 0 ? html + `<button class="chips-clear-all" onclick="resetAllFilters()">حذف همه</button>` : '';

    const cntBadge = document.getElementById('activeFilterCount');
    cntBadge.textContent = count;
    cntBadge.style.display = count > 0 ? 'inline' : 'none';
    document.getElementById('resetFiltersBtn').style.display = count > 0 ? 'flex' : 'none';
}

window.clearFilter = function(key) {
    state[key] = '';
    const fieldMap = {type:'f_type', manager:'f_manager', follower:'f_follower', state_:'f_state', city:'f_city'};
    if (fieldMap[key]) document.getElementById(fieldMap[key]).value = '';
    if (key === 'state_') {
        state.city = '';
        document.getElementById('f_city').innerHTML = '<option value="">همه شهرها</option>';
    }
    state.page = 1;
    updateChips();
    loadCustomers();
};

window.clearTag = function(tid) {
    const idx = state.tags.indexOf(tid);
    if (idx !== -1) state.tags.splice(idx, 1);
    const chip = document.querySelector(`.tag-chip[data-id="${tid}"]`);
    if (chip) chip.classList.remove('selected');
    state.page = 1;
    updateChips();
    loadCustomers();
};

window.resetAllFilters = function() {
    state.q = ''; state.type = ''; state.manager = ''; state.follower = '';
    state.state_ = ''; state.city = ''; state.tags = []; state.page = 1;
    searchInput.value = '';
    searchClear.style.display = 'none';
    ['f_type','f_manager','f_follower','f_state','f_city'].forEach(id => {
        document.getElementById(id).value = '';
    });
    document.getElementById('f_city').innerHTML = '<option value="">همه شهرها</option>';
    document.querySelectorAll('.tag-chip.selected').forEach(c => c.classList.remove('selected'));
    updateChips();
    loadCustomers();
};

// ── مودال جزئیات ──────────────────────────────────────────────────
window.viewCustomer = function(id) {
    document.getElementById('custModal').classList.add('open');
    document.getElementById('modalBody').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;">در حال بارگذاری...</div>';

    const fd = new FormData();
    fd.append('action', 'get_details');
    fd.append('id', id);

    fetch('customers.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd})
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') {
                document.getElementById('modalBody').innerHTML = '<p style="color:#ef4444;text-align:center;">خطا در بارگذاری</p>';
                return;
            }
            renderModal(data.data);
        })
        .catch(() => {
            document.getElementById('modalBody').innerHTML = '<p style="color:#ef4444;text-align:center;">خطا در ارتباط</p>';
        });
};

function renderModal(c) {
    const tags = (c.tags || []).map(t =>
        `<span class="row-tag" style="background:${t.color||'#f1f5f9'};color:${t.color?textColor(t.color):'#475569'}">${esc(t.title)}</span>`
    ).join('');

    document.getElementById('modalBody').innerHTML = `
        <div class="detail-grid">
            <div class="detail-item">
                <label>کد مشتری</label>
                <span>${esc(c.company_code||'—')}</span>
            </div>
            <div class="detail-item">
                <label>نوع</label>
                <span>${c.type_name ? `<span class="fin-badge ${typeBadgeColor(c.type_name)}">${esc(c.type_name)}</span>` : '—'}</span>
            </div>
            <div class="detail-item detail-full">
                <label>نام مشتری / شرکت</label>
                <span style="font-size:1rem;font-weight:700;color:#0f172a;">${esc(c.company_name||'—')}</span>
            </div>
            <div class="detail-item">
                <label>موبایل</label>
                <span dir="ltr">${esc(c.mobile||'—')}</span>
            </div>
            <div class="detail-item">
                <label>تلفن</label>
                <span dir="ltr">${esc(c.phone||'—')}</span>
            </div>
            <div class="detail-item">
                <label>ایمیل</label>
                <span>${esc(c.email||'—')}</span>
            </div>
            <div class="detail-item">
                <label>استان / شهر</label>
                <span>${esc([c.state,c.city].filter(Boolean).join(' / ')||'—')}</span>
            </div>
            <div class="detail-item">
                <label>ثبت‌کننده</label>
                <span>${esc(c.manager_name||'—')}</span>
            </div>
            <div class="detail-item detail-full">
                <label>پیگیری‌کنندگان</label>
                <span>${esc(c.followers_list||'—')}</span>
            </div>
            ${c.address ? `<div class="detail-item detail-full"><label>آدرس</label><span>${esc(c.address)}</span></div>` : ''}
        </div>
        ${tags ? `<div class="modal-tags-row"><label>برچسب‌ها</label>${tags}</div>` : ''}
        <div class="modal-actions">
            <a href="customer_profile.php?id=${c.id}"
               style="flex:1;display:flex;align-items:center;justify-content:center;height:40px;border-radius:10px;background:#3b82f6;color:#fff;font-size:.85rem;font-weight:600;text-decoration:none;gap:6px;">
               👤 مشاهده پروفایل کامل
            </a>
            <button onclick="closeModal()" style="padding:0 18px;height:40px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-family:Vazirmatn,sans-serif;font-size:.83rem;cursor:pointer;">بستن</button>
        </div>`;
}

window.closeModal = function() {
    document.getElementById('custModal').classList.remove('open');
};

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ── پرینت ─────────────────────────────────────────────────────────
window.printAllCustomers = function() {
    const params = new URLSearchParams();
    params.set('print_data', '1');
    if (state.q)        params.set('q', state.q);
    if (state.type)     params.set('type', state.type);
    if (state.manager)  params.set('manager', state.manager);
    if (state.follower) params.set('follower', state.follower);
    if (state.state_)   params.set('state', state.state_);
    if (state.city)     params.set('city', state.city);
    state.tags.forEach(t => params.append('tags[]', t));
    params.set('sort_by', state.sortBy);
    params.set('sort_order', state.sortOrder);

    fetch('customers.php?' + params.toString())
        .then(r => r.text())
        .then(html => {
            document.getElementById('printTableBody').innerHTML = html;
            window.print();
        })
        .catch(() => alert('خطا در دریافت اطلاعات'));
};

// ── کمک‌کارها ─────────────────────────────────────────────────────
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function number_fa(n) { return Number(n).toLocaleString('fa-IR'); }

// ── اجرای اولیه ───────────────────────────────────────────────────
updateSortHeaders();
loadCustomers();

})();
</script>

<?php require_once $root . '/templates/footer.php'; ?>
