<?php
/*
 * فایل: public_html/admin/inv_receipts.php
 * توضیحات: مدیریت رسید، حواله و انتقال انبار
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// بررسی ورود به سیستم
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>'نشست منقضی']); exit; }
    header('Location: ../login.php'); exit;
}

$userId = (int)$_SESSION['user_id'];

// ──────────────────────────────────────────────────────────────
// اطمینان از وجود جداول انبار
// ──────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inv_storerooms (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        address VARCHAR(255) NULL,
        manager_id INT UNSIGNED NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS inv_tickets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_number VARCHAR(30) NOT NULL UNIQUE,
        type ENUM('receipt','dispatch','transfer','return') NOT NULL DEFAULT 'receipt',
        ticket_date VARCHAR(12) NOT NULL,
        ticket_date_g DATE NULL,
        storeroom_id INT UNSIGNED NOT NULL,
        dest_storeroom_id INT UNSIGNED NULL,
        ref_type VARCHAR(30) NULL,
        ref_id INT UNSIGNED NULL,
        person_id INT UNSIGNED NULL,
        person_name VARCHAR(150) NULL,
        description TEXT NULL,
        status ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
        created_by INT UNSIGNED NOT NULL,
        confirmed_by INT UNSIGNED NULL,
        confirmed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS inv_ticket_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT UNSIGNED NOT NULL,
        stuff_id INT UNSIGNED NOT NULL,
        stuff_code VARCHAR(50) NULL,
        description VARCHAR(255) NULL,
        unit VARCHAR(30) NULL DEFAULT 'عدد',
        qty DECIMAL(12,3) NOT NULL DEFAULT 1,
        unit_price BIGINT NOT NULL DEFAULT 0,
        total BIGINT NOT NULL DEFAULT 0,
        sort_order TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // درج انبارهای پیش‌فرض
    $pdo->exec("INSERT IGNORE INTO inv_storerooms (code, name, is_active) VALUES ('ST01','انبار مرکزی',1),('ST02','انبار مجازی',1)");
} catch (Exception $e) {}

// ──────────────────────────────────────────────────────────────
// تابع تبدیل تاریخ شمسی به میلادی
// ──────────────────────────────────────────────────────────────
function jalaliToGregorian($jDate) {
    $jDate = trim(faToEn($jDate));
    if (!preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $jDate)) return null;
    list($jy,$jm,$jd) = array_map('intval', explode('/',$jDate));
    $jy += 1595;
    $days = -355779 + (365*$jy) + (intval($jy/33)*8) + intval((($jy%33)+3)/4) + $jd + ($jm<7 ? ($jm-1)*31 : (($jm-7)*30+186));
    $gy = 400*intval($days/146097); $days %= 146097;
    if ($days > 36524) { $days--; $gy += 100*intval($days/36524); $days %= 36524; if ($days >= 365) $days++; }
    $gy += 4*intval($days/1461); $days %= 1461;
    if ($days > 365) { $gy += intval(($days-1)/365); $days = ($days-1)%365; }
    $gd = $days+1;
    $sal_a = [0,31,(($gy%4==0&&$gy%100!=0)||$gy%400==0)?29:28,31,30,31,30,31,31,30,31,30,31];
    for($i=1;$gd>$sal_a[$i];$i++) $gd-=$sal_a[$i]; $gm=$i;
    return sprintf('%04d-%02d-%02d',$gy,$gm,$gd);
}

// تولید شماره سند اتوماتیک
function generateTicketNumber($pdo, $type) {
    $prefixMap = ['receipt'=>'RC','dispatch'=>'DS','transfer'=>'TR','return'=>'RT'];
    $prefix = $prefixMap[$type] ?? 'XX';
    $year = (int)jdate('Y'); // سال شمسی
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inv_tickets WHERE type=? AND ticket_date LIKE ?");
    $stmt->execute([$type, $year.'/%']);
    $count = (int)$stmt->fetchColumn() + 1;
    return $prefix . '-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
}

// بروزرسانی موجودی کالا پس از تأیید
function updateInventory($pdo, $ticketId) {
    $stmt = $pdo->prepare("SELECT t.type, t.storeroom_id, t.dest_storeroom_id, i.stuff_code, i.qty
        FROM inv_tickets t JOIN inv_ticket_items i ON i.ticket_id = t.id
        WHERE t.id = ? AND t.is_deleted = 0");
    $stmt->execute([$ticketId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $code = $row['stuff_code'];
        $qty  = (float)$row['qty'];
        if (!$code) continue;
        // ابتدا مطمئن می‌شویم رکورد موجودی وجود دارد
        $chk = $pdo->prepare("SELECT id FROM stuff_price_list WHERE stuff_code=?");
        $chk->execute([$code]);
        if (!$chk->fetch()) {
            $ins = $pdo->prepare("INSERT IGNORE INTO stuff_price_list (stuff_code, price, total_inventory, central_store, virtual_store, scrap_store) VALUES (?,0,0,0,0,0)");
            $ins->execute([$code]);
        }
        if ($row['type'] === 'receipt') {
            $upd = $pdo->prepare("UPDATE stuff_price_list SET total_inventory = total_inventory + ?, central_store = central_store + ? WHERE stuff_code = ?");
            $upd->execute([$qty, $qty, $code]);
        } elseif ($row['type'] === 'dispatch' || $row['type'] === 'return') {
            $upd = $pdo->prepare("UPDATE stuff_price_list SET total_inventory = GREATEST(0, total_inventory - ?), central_store = GREATEST(0, central_store - ?) WHERE stuff_code = ?");
            $upd->execute([$qty, $qty, $code]);
        } elseif ($row['type'] === 'transfer') {
            // از انبار مبدا کم می‌کنیم و به مجازی اضافه می‌کنیم
            $upd = $pdo->prepare("UPDATE stuff_price_list SET total_inventory = total_inventory, central_store = GREATEST(0, central_store - ?), virtual_store = virtual_store + ? WHERE stuff_code = ?");
            $upd->execute([$qty, $qty, $code]);
        }
    }
}

// ──────────────────────────────────────────────────────────────
// پردازش درخواست‌های AJAX
// ──────────────────────────────────────────────────────────────
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // ── لیست سندها ─────────────────────────────────────────────
    if ($action === 'list') {
        $tab     = $_GET['tab'] ?? 'receipt';
        $q       = trim($_GET['q'] ?? '');
        $srId    = (int)($_GET['storeroom_id'] ?? 0);
        $status  = $_GET['status'] ?? '';
        $dateF   = trim($_GET['date_from'] ?? '');
        $dateT   = trim($_GET['date_to'] ?? '');

        $typeMap = ['receipt'=>'receipt','dispatch'=>'dispatch','transfer'=>'transfer','return'=>'return'];
        $typeFilter = $typeMap[$tab] ?? 'receipt';

        $sql = "SELECT t.id, t.ticket_number, t.type, t.ticket_date, t.status, t.person_name,
                    s.name AS storeroom_name,
                    COUNT(i.id) AS item_count,
                    COALESCE(SUM(i.qty),0) AS total_qty,
                    COALESCE(SUM(i.total),0) AS total_amount
                FROM inv_tickets t
                LEFT JOIN inv_storerooms s ON s.id = t.storeroom_id
                LEFT JOIN inv_ticket_items i ON i.ticket_id = t.id
                WHERE t.is_deleted = 0 AND t.type = ?";
        $params = [$typeFilter];

        if ($q !== '') {
            $sql .= " AND (t.ticket_number LIKE ? OR t.person_name LIKE ?)";
            $params[] = "%$q%"; $params[] = "%$q%";
        }
        if ($srId > 0) { $sql .= " AND t.storeroom_id = ?"; $params[] = $srId; }
        if ($status !== '') { $sql .= " AND t.status = ?"; $params[] = $status; }
        if ($dateF !== '') { $sql .= " AND t.ticket_date >= ?"; $params[] = $dateF; }
        if ($dateT !== '') { $sql .= " AND t.ticket_date <= ?"; $params[] = $dateT; }

        $sql .= " GROUP BY t.id ORDER BY t.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['status'=>'ok','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── دریافت یک سند کامل ────────────────────────────────────
    if ($action === 'get_ticket') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT t.*, s.name AS storeroom_name, d.name AS dest_storeroom_name
            FROM inv_tickets t
            LEFT JOIN inv_storerooms s ON s.id = t.storeroom_id
            LEFT JOIN inv_storerooms d ON d.id = t.dest_storeroom_id
            WHERE t.id = ? AND t.is_deleted = 0");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) { echo json_encode(['status'=>'error','message'=>'سند یافت نشد']); exit; }

        $stmtI = $pdo->prepare("SELECT i.*, s.stuff_name FROM inv_ticket_items i
            LEFT JOIN stuffs s ON s.id = i.stuff_id
            WHERE i.ticket_id = ? ORDER BY i.sort_order ASC, i.id ASC");
        $stmtI->execute([$id]);
        $ticket['items'] = $stmtI->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'ok','data'=>$ticket]);
        exit;
    }

    // ── جستجوی کالا ────────────────────────────────────────────
    if ($action === 'search_stuffs') {
        $q = trim($_GET['q'] ?? '');
        $stmt = $pdo->prepare("SELECT s.id, s.stuff_name, s.stuff_code, s.unit,
                COALESCE(p.price,0) AS price, COALESCE(p.total_inventory,0) AS total_inventory
            FROM stuffs s
            LEFT JOIN stuff_price_list p ON p.stuff_code = s.stuff_code
            WHERE s.is_delete = 0 AND (s.stuff_name LIKE ? OR s.stuff_code LIKE ? OR s.technical_code LIKE ?)
            LIMIT 15");
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
        echo json_encode(['status'=>'ok','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── جستجوی طرف حساب ───────────────────────────────────────
    if ($action === 'search_persons') {
        $q = trim($_GET['q'] ?? '');
        $stmt = $pdo->prepare("SELECT id, name FROM fin_persons WHERE name LIKE ? LIMIT 10");
        $stmt->execute(["%$q%"]);
        echo json_encode(['status'=>'ok','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── ذخیره سند (ایجاد) ─────────────────────────────────────
    if ($action === 'save_ticket' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status'=>'error','message'=>'توکن امنیتی نامعتبر']); exit;
        }
        $type           = in_array($_POST['type']??'',['receipt','dispatch','transfer','return']) ? $_POST['type'] : 'receipt';
        $ticketDateJ    = trim(faToEn($_POST['ticket_date'] ?? ''));
        $storeroomId    = (int)($_POST['storeroom_id'] ?? 0);
        $destStoreroomId = (int)($_POST['dest_storeroom_id'] ?? 0);
        $personName     = trim($_POST['person_name'] ?? '');
        $description    = trim($_POST['description'] ?? '');
        $status         = in_array($_POST['status']??'',['draft','confirmed']) ? $_POST['status'] : 'draft';
        $itemsJson      = $_POST['items'] ?? '[]';

        if (!$ticketDateJ || !$storeroomId) {
            echo json_encode(['status'=>'error','message'=>'تاریخ و انبار الزامی است']); exit;
        }
        $items = json_decode($itemsJson, true);
        if (!is_array($items) || count($items) === 0) {
            echo json_encode(['status'=>'error','message'=>'حداقل یک قلم کالا باید وارد شود']); exit;
        }

        $ticketDateG = jalaliToGregorian($ticketDateJ);
        $ticketNumber = generateTicketNumber($pdo, $type);

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO inv_tickets
                (ticket_number, type, ticket_date, ticket_date_g, storeroom_id, dest_storeroom_id,
                 person_name, description, status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $ticketNumber, $type, $ticketDateJ, $ticketDateG,
                $storeroomId, $destStoreroomId ?: null,
                $personName, $description, $status, $userId
            ]);
            $ticketId = (int)$pdo->lastInsertId();

            $stmtItem = $pdo->prepare("INSERT INTO inv_ticket_items
                (ticket_id, stuff_id, stuff_code, description, unit, qty, unit_price, total, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($items as $idx => $item) {
                $stuffId   = (int)($item['stuff_id'] ?? 0);
                $stuffCode = trim($item['stuff_code'] ?? '');
                $desc      = trim($item['description'] ?? '');
                $unit      = trim($item['unit'] ?? 'عدد');
                $qty       = max(0, (float)faToEn($item['qty'] ?? 0));
                $unitPrice = max(0, (int)faToEn(str_replace(',', '', $item['unit_price'] ?? 0)));
                $total     = (int)round($qty * $unitPrice);
                if ($stuffId < 1 || $qty <= 0) continue;
                $stmtItem->execute([$ticketId, $stuffId, $stuffCode, $desc, $unit, $qty, $unitPrice, $total, $idx]);
            }

            // اگر وضعیت تأیید شده بود موجودی بروز می‌شود
            if ($status === 'confirmed') {
                $updStmt = $pdo->prepare("UPDATE inv_tickets SET confirmed_by=?, confirmed_at=NOW() WHERE id=?");
                $updStmt->execute([$userId, $ticketId]);
                updateInventory($pdo, $ticketId);
            }
            $pdo->commit();
            echo json_encode(['status'=>'ok','message'=>'سند با موفقیت ذخیره شد','id'=>$ticketId,'ticket_number'=>$ticketNumber]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status'=>'error','message'=>'خطا در ذخیره‌سازی: '.$e->getMessage()]);
        }
        exit;
    }

    // ── تأیید سند ─────────────────────────────────────────────
    if ($action === 'confirm_ticket' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status'=>'error','message'=>'توکن امنیتی نامعتبر']); exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, status FROM inv_tickets WHERE id=? AND is_deleted=0");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) { echo json_encode(['status'=>'error','message'=>'سند یافت نشد']); exit; }
        if ($ticket['status'] !== 'draft') { echo json_encode(['status'=>'error','message'=>'سند قبلاً تأیید شده']); exit; }

        try {
            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE inv_tickets SET status='confirmed', confirmed_by=?, confirmed_at=NOW() WHERE id=?");
            $upd->execute([$userId, $id]);
            updateInventory($pdo, $id);
            $pdo->commit();
            echo json_encode(['status'=>'ok','message'=>'سند با موفقیت تأیید شد']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status'=>'error','message'=>'خطا: '.$e->getMessage()]);
        }
        exit;
    }

    // ── حذف سند (پیش‌نویس) ────────────────────────────────────
    if ($action === 'delete_ticket' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status'=>'error','message'=>'توکن امنیتی نامعتبر']); exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM inv_tickets WHERE id=? AND is_deleted=0");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) { echo json_encode(['status'=>'error','message'=>'سند یافت نشد']); exit; }
        if ($ticket['status'] !== 'draft') { echo json_encode(['status'=>'error','message'=>'فقط پیش‌نویس قابل حذف است']); exit; }
        $del = $pdo->prepare("UPDATE inv_tickets SET is_deleted=1 WHERE id=?");
        $del->execute([$id]);
        echo json_encode(['status'=>'ok','message'=>'سند حذف شد']);
        exit;
    }

    // ── دریافت لیست انبارها ────────────────────────────────────
    if ($action === 'get_storerooms') {
        $stmt = $pdo->query("SELECT id, code, name FROM inv_storerooms WHERE is_active=1 ORDER BY name");
        echo json_encode(['status'=>'ok','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'عملیات نامعتبر']); exit;
}

// ──────────────────────────────────────────────────────────────
// آماده‌سازی داده‌ها برای صفحه
// ──────────────────────────────────────────────────────────────
// انبارها
$storerooms = $pdo->query("SELECT id, code, name FROM inv_storerooms WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// آمار کلی
$statsStmt = $pdo->query("SELECT type, COUNT(*) AS cnt FROM inv_tickets WHERE is_deleted=0 GROUP BY type");
$statsRaw = $statsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$cntReceipt  = (int)($statsRaw['receipt']  ?? 0);
$cntDispatch = (int)($statsRaw['dispatch'] ?? 0);
$cntTransfer = (int)($statsRaw['transfer'] ?? 0);
$cntPending  = (int)$pdo->query("SELECT COUNT(*) FROM inv_tickets WHERE is_deleted=0 AND status='draft'")->fetchColumn();

// توکن CSRF
$csrfToken = csrf_field(); // این تابع token را چاپ می‌کند — برای JS آن را جداگانه می‌سازیم
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$rawCsrfToken = $_SESSION['csrf_token'];

// عنوان صفحه
$pageTitle = 'رسید و حواله انبار';
$basePath  = '../../';
$todayJalali = jdate('Y/m/d');

include __DIR__ . '/../../templates/header.php';
?>
<link rel="stylesheet" href="../../assets/css/fin_module.css">
<style>
/* ── استایل‌های اختصاصی انبار ── */
.inv-header-gradient {
    background: linear-gradient(135deg, #065f46 0%, #047857 50%, #059669 100%);
    border-radius: 16px;
    padding: 24px 28px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}
.inv-header-gradient h1 { margin: 0; font-size: 1.4rem; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.inv-header-gradient p  { margin: 4px 0 0; opacity: .8; font-size: .9rem; }

/* جدول */
.fin-table th { white-space: nowrap; }
.fin-table td .action-btns { display: flex; gap: 6px; }

/* کشو (drawer) */
.fin-drawer { width: 680px; }
@media (max-width: 768px) { .fin-drawer { width: 100%; } }

/* ردیف کالا */
.item-row td { vertical-align: middle; padding: 6px 4px !important; }
.item-row input { padding: 5px 8px; font-size: .85rem; }
.stuff-autocomplete-wrap { position: relative; }
.stuff-dropdown {
    position: absolute; top: 100%; right: 0; left: 0; z-index: 9999;
    background: #fff; border: 1px solid #d1d5db; border-radius: 8px;
    max-height: 200px; overflow-y: auto; box-shadow: 0 4px 16px rgba(0,0,0,.1);
}
.stuff-dropdown-item {
    padding: 8px 12px; cursor: pointer; font-size: .85rem; border-bottom: 1px solid #f3f4f6;
    display: flex; justify-content: space-between; gap: 8px;
}
.stuff-dropdown-item:hover { background: #f0fdf4; }
.stuff-dropdown-item .stuff-code { color: #6b7280; font-size: .78rem; }

/* فیلد قیمت */
.price-input { text-align: left; direction: ltr; }

/* نشانگر نوع */
.badge-receipt  { background: #d1fae5; color: #065f46; }
.badge-dispatch { background: #fef3c7; color: #92400e; }
.badge-transfer { background: #dbeafe; color: #1e40af; }
.badge-return   { background: #ffe4e6; color: #9f1239; }
.badge-draft    { background: #f3f4f6; color: #374151; }
.badge-confirmed{ background: #d1fae5; color: #065f46; }

/* person autocomplete */
.person-wrap { position: relative; }
.person-dropdown {
    position: absolute; top: 100%; right: 0; left: 0; z-index: 9998;
    background: #fff; border: 1px solid #d1d5db; border-radius: 8px;
    max-height: 160px; overflow-y: auto; box-shadow: 0 4px 16px rgba(0,0,0,.1);
}
.person-dropdown-item { padding: 8px 12px; cursor: pointer; font-size: .85rem; border-bottom: 1px solid #f3f4f6; }
.person-dropdown-item:hover { background: #f0fdf4; }

/* toast */
#inv-toast {
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(80px);
    background: #1f2937; color: #fff; padding: 12px 24px; border-radius: 10px;
    font-size: .9rem; z-index: 99999; transition: transform .3s ease;
    min-width: 220px; text-align: center; pointer-events: none;
}
#inv-toast.show { transform: translateX(-50%) translateY(0); }
#inv-toast.ok   { background: #059669; }
#inv-toast.err  { background: #dc2626; }

/* چاپ */
@media print { .no-print { display: none !important; } }
</style>

<div class="main-content" style="padding: 20px;">

    <!-- هدر صفحه -->
    <div class="inv-header-gradient no-print">
        <div>
            <h1>📦 رسید و حواله انبار</h1>
            <p>ثبت، مدیریت و تأیید رسید، حواله و انتقال کالا</p>
        </div>
        <button class="fin-btn fin-btn-primary" onclick="openDrawer(0)" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);">
            <span style="font-size:1.1rem;">＋</span> ثبت جدید
        </button>
    </div>

    <!-- آمار -->
    <div class="fin-stats-grid no-print" style="margin-bottom:24px;">
        <div class="fin-stat-card green">
            <div class="fin-stat-icon">📥</div>
            <div class="fin-stat-label">رسید انبار</div>
            <div class="fin-stat-value"><?= number_format($cntReceipt) ?></div>
            <div class="fin-stat-unit">سند ثبت شده</div>
        </div>
        <div class="fin-stat-card amber">
            <div class="fin-stat-icon">📤</div>
            <div class="fin-stat-label">حواله انبار</div>
            <div class="fin-stat-value"><?= number_format($cntDispatch) ?></div>
            <div class="fin-stat-unit">سند ثبت شده</div>
        </div>
        <div class="fin-stat-card blue">
            <div class="fin-stat-icon">🔄</div>
            <div class="fin-stat-label">انتقال انبار</div>
            <div class="fin-stat-value"><?= number_format($cntTransfer) ?></div>
            <div class="fin-stat-unit">سند ثبت شده</div>
        </div>
        <div class="fin-stat-card rose">
            <div class="fin-stat-icon">⏳</div>
            <div class="fin-stat-label">در انتظار تأیید</div>
            <div class="fin-stat-value"><?= number_format($cntPending) ?></div>
            <div class="fin-stat-unit">پیش‌نویس</div>
        </div>
    </div>

    <!-- تب‌ها -->
    <div class="fin-panel no-print">
        <div class="fin-tabs" style="margin-bottom:0;padding:0 20px;border-bottom:1px solid #e5e7eb;">
            <button class="fin-tab active" data-tab="receipt"  onclick="switchTab('receipt',this)">📥 رسیدها</button>
            <button class="fin-tab"        data-tab="dispatch" onclick="switchTab('dispatch',this)">📤 حواله‌ها</button>
            <button class="fin-tab"        data-tab="transfer" onclick="switchTab('transfer',this)">🔄 انتقال‌ها</button>
            <button class="fin-tab"        data-tab="return"   onclick="switchTab('return',this)">↩️ مرجوعی‌ها</button>
        </div>

        <!-- فیلتر -->
        <div class="fin-filter-bar" style="padding:16px 20px;">
            <div class="fin-filter-group">
                <input type="text" id="fq" class="fin-input" placeholder="جستجو شماره سند یا طرف حساب..." style="min-width:220px;" oninput="debounceLoad()">
            </div>
            <div class="fin-filter-group">
                <select id="fStoreroom" class="fin-select" onchange="loadTickets()">
                    <option value="">همه انبارها</option>
                    <?php foreach($storerooms as $sr): ?>
                    <option value="<?= $sr['id'] ?>"><?= htmlspecialchars($sr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fin-filter-group">
                <select id="fStatus" class="fin-select" onchange="loadTickets()">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="draft">پیش‌نویس</option>
                    <option value="confirmed">تأیید شده</option>
                </select>
            </div>
        </div>

        <!-- جدول -->
        <div style="overflow-x:auto;padding:0 20px 20px;">
            <table class="fin-table" id="ticketsTable">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>شماره سند</th>
                        <th>نوع</th>
                        <th>انبار</th>
                        <th>طرف حساب</th>
                        <th>تاریخ</th>
                        <th>اقلام</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody id="ticketsBody">
                    <tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;">در حال بارگذاری...</td></tr>
                </tbody>
            </table>
            <div id="ticketsEmpty" class="fin-empty" style="display:none;">
                <div class="empty-icon">📭</div>
                <p>هیچ سندی در این بخش ثبت نشده است</p>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- کشوی ثبت/مشاهده سند                                        -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="fin-drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="fin-drawer" id="mainDrawer" style="width:700px;">
    <div class="fin-drawer-header">
        <span id="drawerTitle">ثبت سند جدید</span>
        <button class="fin-drawer-close" onclick="closeDrawer()">✕</button>
    </div>
    <div class="fin-drawer-body" style="padding:20px;">

        <input type="hidden" id="editId" value="0">

        <!-- فرم ورودی -->
        <div id="formArea">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <!-- نوع سند -->
                <div class="fin-form-group">
                    <label>نوع سند <span style="color:red">*</span></label>
                    <select id="fType" class="fin-select" onchange="onTypeChange()">
                        <option value="receipt">📥 رسید</option>
                        <option value="dispatch">📤 حواله</option>
                        <option value="transfer">🔄 انتقال</option>
                        <option value="return">↩️ مرجوعی</option>
                    </select>
                </div>
                <!-- تاریخ -->
                <div class="fin-form-group">
                    <label>تاریخ <span style="color:red">*</span></label>
                    <input type="text" id="fDate" class="fin-input" placeholder="۱۴۰۴/۰۱/۰۱" value="<?= $todayJalali ?>">
                </div>
                <!-- انبار مبدا -->
                <div class="fin-form-group">
                    <label>انبار <span style="color:red">*</span></label>
                    <select id="fStoreroom2" class="fin-select">
                        <option value="">-- انتخاب کنید --</option>
                        <?php foreach($storerooms as $sr): ?>
                        <option value="<?= $sr['id'] ?>"><?= htmlspecialchars($sr['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- انبار مقصد (فقط انتقال) -->
                <div class="fin-form-group" id="destStoreroomWrap" style="display:none;">
                    <label>انبار مقصد <span style="color:red">*</span></label>
                    <select id="fDestStoreroom" class="fin-select">
                        <option value="">-- انتخاب کنید --</option>
                        <?php foreach($storerooms as $sr): ?>
                        <option value="<?= $sr['id'] ?>"><?= htmlspecialchars($sr['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- طرف حساب -->
                <div class="fin-form-group" style="position:relative;">
                    <label>طرف حساب</label>
                    <div class="person-wrap">
                        <input type="text" id="fPersonName" class="fin-input" placeholder="نام مشتری یا تامین‌کننده..." autocomplete="off" oninput="searchPerson(this.value)">
                        <div class="person-dropdown" id="personDropdown" style="display:none;"></div>
                    </div>
                </div>
                <!-- وضعیت -->
                <div class="fin-form-group">
                    <label>وضعیت</label>
                    <select id="fStatus2" class="fin-select">
                        <option value="draft">پیش‌نویس</option>
                        <option value="confirmed">تأیید شده</option>
                    </select>
                </div>
            </div>
            <!-- توضیحات -->
            <div class="fin-form-group">
                <label>توضیحات</label>
                <textarea id="fDesc" class="fin-textarea" rows="2" placeholder="توضیحات اختیاری..."></textarea>
            </div>

            <!-- ── جدول اقلام ── -->
            <div style="margin-top:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <strong style="font-size:.95rem;">اقلام سند</strong>
                    <button type="button" class="fin-btn fin-btn-outline fin-btn-sm" onclick="addItemRow()">＋ افزودن کالا</button>
                </div>
                <div style="overflow-x:auto;">
                    <table class="fin-table" style="font-size:.85rem;">
                        <thead>
                            <tr>
                                <th style="width:30px;">#</th>
                                <th style="min-width:180px;">کالا</th>
                                <th style="width:80px;">کد</th>
                                <th style="width:70px;">واحد</th>
                                <th style="width:80px;">تعداد</th>
                                <th style="width:120px;">قیمت واحد (ریال)</th>
                                <th style="width:110px;">جمع (ریال)</th>
                                <th style="width:36px;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" style="text-align:left;font-weight:600;padding:8px 12px;">جمع کل:</td>
                                <td style="font-weight:700;color:#059669;padding:8px 12px;" id="grandTotal">۰</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- ناحیه نمایش (view-only) -->
        <div id="viewArea" style="display:none;"></div>
    </div>

    <div class="fin-drawer-footer" id="drawerFooter">
        <button type="button" class="fin-btn fin-btn-success" onclick="saveForm()">💾 ذخیره سند</button>
        <button type="button" class="fin-btn fin-btn-outline" onclick="closeDrawer()">انصراف</button>
    </div>
</div>

<!-- toast -->
<div id="inv-toast"></div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>

<script>
/* ══════════════════════════════════════════════════════════════
   متغیرهای سراسری
══════════════════════════════════════════════════════════════ */
const CSRF   = <?= json_encode($rawCsrfToken) ?>;
let currentTab  = 'receipt';
let rowCounter  = 0;
let debounceTimer = null;
let personDebTimer = null;

/* ══════════════════════════════════════════════════════════════
   بارگذاری اولیه
══════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    loadTickets();
});

/* ══════════════════════════════════════════════════════════════
   تب‌ها
══════════════════════════════════════════════════════════════ */
function switchTab(tab, btn) {
    currentTab = tab;
    document.querySelectorAll('.fin-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    loadTickets();
}

/* ══════════════════════════════════════════════════════════════
   بارگذاری لیست سندها
══════════════════════════════════════════════════════════════ */
function loadTickets() {
    const q         = document.getElementById('fq').value.trim();
    const srId      = document.getElementById('fStoreroom').value;
    const status    = document.getElementById('fStatus').value;
    const params    = new URLSearchParams({ action:'list', tab:currentTab, q, storeroom_id:srId, status });
    fetch('inv_receipts.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(res => {
            const tbody = document.getElementById('ticketsBody');
            const empty = document.getElementById('ticketsEmpty');
            if (!res.data || res.data.length === 0) {
                tbody.innerHTML = '';
                empty.style.display = 'block';
                document.querySelector('.fin-table').style.display = 'none';
                return;
            }
            empty.style.display = 'none';
            document.querySelector('.fin-table').style.display = 'table';

            const typeLabels = {receipt:'رسید', dispatch:'حواله', transfer:'انتقال', return:'مرجوعی'};
            const statusLabels = {draft:'پیش‌نویس', confirmed:'تأیید شده'};

            tbody.innerHTML = res.data.map((t, idx) => `
                <tr>
                    <td>${idx+1}</td>
                    <td><strong>${esc(t.ticket_number)}</strong></td>
                    <td><span class="fin-badge badge-${t.type}">${typeLabels[t.type]||t.type}</span></td>
                    <td>${esc(t.storeroom_name||'—')}</td>
                    <td>${esc(t.person_name||'—')}</td>
                    <td>${esc(t.ticket_date)}</td>
                    <td style="text-align:center;">${t.item_count}</td>
                    <td><span class="fin-badge badge-${t.status}">${statusLabels[t.status]||t.status}</span></td>
                    <td>
                        <div class="action-btns">
                            <button class="fin-btn fin-btn-outline fin-btn-sm" title="مشاهده" onclick="viewTicket(${t.id})">👁</button>
                            ${t.status==='draft'?`<button class="fin-btn fin-btn-success fin-btn-sm" title="تأیید" onclick="confirmTicket(${t.id})">✅</button>`:''}
                            ${t.status==='draft'?`<button class="fin-btn fin-btn-danger fin-btn-sm" title="حذف" onclick="deleteTicket(${t.id})">🗑</button>`:''}
                        </div>
                    </td>
                </tr>`).join('');
        }).catch(() => showToast('خطا در بارگذاری', 'err'));
}

function debounceLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(loadTickets, 400);
}

/* ══════════════════════════════════════════════════════════════
   کشو — باز و بسته
══════════════════════════════════════════════════════════════ */
function openDrawer(id = 0) {
    resetForm();
    document.getElementById('editId').value = id;
    document.getElementById('formArea').style.display = 'block';
    document.getElementById('viewArea').style.display = 'none';
    document.getElementById('drawerFooter').style.display = 'flex';
    document.getElementById('drawerTitle').textContent = 'ثبت سند جدید';
    document.getElementById('mainDrawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('active');
    addItemRow(); // یک ردیف خالی
}

function closeDrawer() {
    document.getElementById('mainDrawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('active');
    closePersonDropdown();
}

function resetForm() {
    document.getElementById('fType').value       = 'receipt';
    document.getElementById('fDate').value       = '<?= $todayJalali ?>';
    document.getElementById('fStoreroom2').value = '';
    document.getElementById('fDestStoreroom').value = '';
    document.getElementById('fPersonName').value = '';
    document.getElementById('fStatus2').value    = 'draft';
    document.getElementById('fDesc').value       = '';
    document.getElementById('itemsBody').innerHTML = '';
    document.getElementById('grandTotal').textContent = '۰';
    rowCounter = 0;
    onTypeChange();
}

function onTypeChange() {
    const type = document.getElementById('fType').value;
    document.getElementById('destStoreroomWrap').style.display = (type === 'transfer') ? 'block' : 'none';
}

/* ══════════════════════════════════════════════════════════════
   مشاهده سند موجود
══════════════════════════════════════════════════════════════ */
function viewTicket(id) {
    fetch(`inv_receipts.php?action=get_ticket&id=${id}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(res => {
            if (res.status !== 'ok') { showToast(res.message, 'err'); return; }
            const t = res.data;
            const typeLabels = {receipt:'📥 رسید', dispatch:'📤 حواله', transfer:'🔄 انتقال', return:'↩️ مرجوعی'};
            const statusLabels = {draft:'⏳ پیش‌نویس', confirmed:'✅ تأیید شده'};

            let itemsHtml = (t.items||[]).map((item, i) => `
                <tr>
                    <td>${i+1}</td>
                    <td>${esc(item.stuff_name||'—')}</td>
                    <td>${esc(item.stuff_code||'—')}</td>
                    <td>${esc(item.unit||'—')}</td>
                    <td style="text-align:left;">${fmtNum(item.qty)}</td>
                    <td style="text-align:left;">${fmtNum(item.unit_price)}</td>
                    <td style="text-align:left;color:#059669;font-weight:600;">${fmtNum(item.total)}</td>
                </tr>`).join('') || '<tr><td colspan="7" style="text-align:center;color:#9ca3af;">اقلامی ثبت نشده</td></tr>';

            document.getElementById('viewArea').innerHTML = `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div><label style="font-size:.8rem;color:#6b7280;">شماره سند</label><div style="font-weight:700;">${esc(t.ticket_number)}</div></div>
                    <div><label style="font-size:.8rem;color:#6b7280;">نوع</label><div>${typeLabels[t.type]||t.type}</div></div>
                    <div><label style="font-size:.8rem;color:#6b7280;">تاریخ</label><div>${esc(t.ticket_date)}</div></div>
                    <div><label style="font-size:.8rem;color:#6b7280;">وضعیت</label><div>${statusLabels[t.status]||t.status}</div></div>
                    <div><label style="font-size:.8rem;color:#6b7280;">انبار</label><div>${esc(t.storeroom_name||'—')}</div></div>
                    ${t.dest_storeroom_name ? `<div><label style="font-size:.8rem;color:#6b7280;">انبار مقصد</label><div>${esc(t.dest_storeroom_name)}</div></div>` : ''}
                    <div><label style="font-size:.8rem;color:#6b7280;">طرف حساب</label><div>${esc(t.person_name||'—')}</div></div>
                    ${t.description ? `<div style="grid-column:span 2;"><label style="font-size:.8rem;color:#6b7280;">توضیحات</label><div>${esc(t.description)}</div></div>` : ''}
                </div>
                <table class="fin-table" style="font-size:.85rem;">
                    <thead><tr><th>#</th><th>کالا</th><th>کد</th><th>واحد</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead>
                    <tbody>${itemsHtml}</tbody>
                </table>`;

            document.getElementById('formArea').style.display  = 'none';
            document.getElementById('viewArea').style.display  = 'block';
            document.getElementById('drawerFooter').style.display = t.status==='draft' ? 'flex' : 'none';
            if (t.status === 'draft') {
                document.getElementById('drawerFooter').innerHTML = `
                    <button class="fin-btn fin-btn-success" onclick="confirmTicket(${t.id});closeDrawer();">✅ تأیید سند</button>
                    <button class="fin-btn fin-btn-danger" onclick="deleteTicket(${t.id});closeDrawer();">🗑 حذف</button>
                    <button class="fin-btn fin-btn-outline" onclick="closeDrawer()">بستن</button>`;
            }
            document.getElementById('drawerTitle').textContent = 'مشاهده سند ' + t.ticket_number;
            document.getElementById('mainDrawer').classList.add('open');
            document.getElementById('drawerOverlay').classList.add('active');
        });
}

/* ══════════════════════════════════════════════════════════════
   ذخیره فرم
══════════════════════════════════════════════════════════════ */
function saveForm() {
    const type       = document.getElementById('fType').value;
    const ticketDate = document.getElementById('fDate').value.trim();
    const srId       = document.getElementById('fStoreroom2').value;
    const destSrId   = document.getElementById('fDestStoreroom').value;
    const personName = document.getElementById('fPersonName').value.trim();
    const desc       = document.getElementById('fDesc').value.trim();
    const status     = document.getElementById('fStatus2').value;

    if (!ticketDate) { showToast('تاریخ الزامی است', 'err'); return; }
    if (!srId)       { showToast('انتخاب انبار الزامی است', 'err'); return; }
    if (type === 'transfer' && !destSrId) { showToast('انبار مقصد الزامی است', 'err'); return; }

    // جمع‌آوری اقلام
    const rows = document.querySelectorAll('#itemsBody tr.item-row');
    const items = [];
    rows.forEach(row => {
        const stuffId = row.dataset.stuffId;
        const stuffCode = row.querySelector('.col-code').value;
        const unit    = row.querySelector('.col-unit').value;
        const qty     = row.querySelector('.col-qty').value.trim();
        const price   = row.querySelector('.col-price').value.replace(/,/g,'').trim();
        const rowDesc = row.querySelector('.col-desc') ? row.querySelector('.col-desc').value : '';
        if (!stuffId || !qty) return;
        items.push({ stuff_id:stuffId, stuff_code:stuffCode, description:rowDesc, unit, qty, unit_price:price||0 });
    });
    if (items.length === 0) { showToast('حداقل یک قلم کالا باید وارد شود', 'err'); return; }

    const body = new URLSearchParams({
        csrf_token: CSRF, action:'save_ticket', type,
        ticket_date: ticketDate, storeroom_id: srId,
        dest_storeroom_id: destSrId, person_name: personName,
        description: desc, status, items: JSON.stringify(items)
    });
    fetch('inv_receipts.php', {
        method:'POST', headers:{ 'X-Requested-With':'XMLHttpRequest', 'Content-Type':'application/x-www-form-urlencoded' },
        body
    }).then(r => r.json()).then(res => {
        if (res.status === 'ok') {
            showToast(res.message, 'ok');
            closeDrawer();
            loadTickets();
        } else {
            showToast(res.message || 'خطا در ذخیره', 'err');
        }
    }).catch(() => showToast('خطای شبکه', 'err'));
}

/* ══════════════════════════════════════════════════════════════
   تأیید سند
══════════════════════════════════════════════════════════════ */
function confirmTicket(id) {
    if (!confirm('آیا مطمئن هستید که این سند را تأیید کنید؟\nپس از تأیید، موجودی بروز می‌شود.')) return;
    fetch('inv_receipts.php', {
        method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ csrf_token:CSRF, action:'confirm_ticket', id })
    }).then(r=>r.json()).then(res=>{
        showToast(res.message, res.status==='ok'?'ok':'err');
        if (res.status==='ok') loadTickets();
    });
}

/* ══════════════════════════════════════════════════════════════
   حذف سند
══════════════════════════════════════════════════════════════ */
function deleteTicket(id) {
    if (!confirm('آیا از حذف این سند اطمینان دارید؟')) return;
    fetch('inv_receipts.php', {
        method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ csrf_token:CSRF, action:'delete_ticket', id })
    }).then(r=>r.json()).then(res=>{
        showToast(res.message, res.status==='ok'?'ok':'err');
        if (res.status==='ok') loadTickets();
    });
}

/* ══════════════════════════════════════════════════════════════
   مدیریت ردیف‌های کالا
══════════════════════════════════════════════════════════════ */
function addItemRow() {
    rowCounter++;
    const tbody = document.getElementById('itemsBody');
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.dataset.stuffId = '';
    tr.innerHTML = `
        <td style="text-align:center;color:#9ca3af;">${rowCounter}</td>
        <td>
            <div class="stuff-autocomplete-wrap">
                <input type="text" class="fin-input col-stuff-name" placeholder="جستجوی کالا..." autocomplete="off"
                    oninput="searchStuff(this)" onfocus="searchStuff(this)">
                <div class="stuff-dropdown" style="display:none;"></div>
            </div>
        </td>
        <td><input type="text" class="fin-input col-code" readonly style="background:#f9fafb;width:70px;"></td>
        <td><input type="text" class="fin-input col-unit" style="width:60px;" value="عدد"></td>
        <td><input type="number" class="fin-input col-qty" min="0.001" step="0.001" value="1" oninput="recalcRow(this.closest('tr'))" style="width:70px;"></td>
        <td><input type="text" class="fin-input col-price price-input" placeholder="0" oninput="recalcRow(this.closest('tr'))" style="width:110px;"></td>
        <td><span class="col-total" style="font-weight:600;color:#059669;">۰</span></td>
        <td><button type="button" class="fin-btn fin-btn-danger fin-btn-sm" onclick="removeRow(this)">✕</button></td>`;
    tbody.appendChild(tr);
}

function removeRow(btn) {
    btn.closest('tr').remove();
    calcTotal();
}

function recalcRow(row) {
    const qty   = parseFloat(row.querySelector('.col-qty').value) || 0;
    const price = parseInt((row.querySelector('.col-price').value||'0').replace(/,/g,'')) || 0;
    const total = Math.round(qty * price);
    row.querySelector('.col-total').textContent = formatNum(total);
    calcTotal();
}

function calcTotal() {
    let sum = 0;
    document.querySelectorAll('#itemsBody tr.item-row').forEach(row => {
        const qty   = parseFloat(row.querySelector('.col-qty').value)||0;
        const price = parseInt((row.querySelector('.col-price').value||'0').replace(/,/g,''))||0;
        sum += Math.round(qty * price);
    });
    document.getElementById('grandTotal').textContent = formatNum(sum);
}

/* ══════════════════════════════════════════════════════════════
   جستجوی کالا (autocomplete)
══════════════════════════════════════════════════════════════ */
let stuffTimers = {};
function searchStuff(input) {
    const wrap = input.closest('.stuff-autocomplete-wrap');
    const dropdown = wrap.querySelector('.stuff-dropdown');
    const q = input.value.trim();
    const key = input.dataset.rowId || (input.dataset.rowId = Date.now());
    clearTimeout(stuffTimers[key]);
    if (q.length < 1) { dropdown.style.display='none'; return; }
    stuffTimers[key] = setTimeout(() => {
        fetch(`inv_receipts.php?action=search_stuffs&q=${encodeURIComponent(q)}`, { headers: {'X-Requested-With':'XMLHttpRequest'} })
            .then(r=>r.json()).then(res=>{
                if (!res.data || res.data.length===0) { dropdown.style.display='none'; return; }
                dropdown.innerHTML = res.data.map(s => `
                    <div class="stuff-dropdown-item" onclick="selectStuffRow(this.closest('.stuff-autocomplete-wrap'),${s.id},'${escAttr(s.stuff_name)}','${escAttr(s.stuff_code)}','${escAttr(s.unit||'عدد')}',${s.price||0})">
                        <span>${esc(s.stuff_name)}</span>
                        <span class="stuff-code">${esc(s.stuff_code)} — موجودی: ${fmtNum(s.total_inventory)}</span>
                    </div>`).join('');
                dropdown.style.display = 'block';
            });
    }, 300);
}

function selectStuffRow(wrap, id, name, code, unit, price) {
    const row = wrap.closest('tr');
    row.dataset.stuffId = id;
    wrap.querySelector('input').value = name;
    row.querySelector('.col-code').value  = code;
    row.querySelector('.col-unit').value  = unit;
    row.querySelector('.col-price').value = price > 0 ? price : '';
    wrap.querySelector('.stuff-dropdown').style.display = 'none';
    recalcRow(row);
}

// بستن dropdown کالا با کلیک خارج
document.addEventListener('click', e => {
    if (!e.target.closest('.stuff-autocomplete-wrap')) {
        document.querySelectorAll('.stuff-dropdown').forEach(d => d.style.display='none');
    }
});

/* ══════════════════════════════════════════════════════════════
   جستجوی طرف حساب (autocomplete)
══════════════════════════════════════════════════════════════ */
function searchPerson(q) {
    clearTimeout(personDebTimer);
    const dropdown = document.getElementById('personDropdown');
    if (q.trim().length < 1) { dropdown.style.display='none'; return; }
    personDebTimer = setTimeout(() => {
        fetch(`inv_receipts.php?action=search_persons&q=${encodeURIComponent(q)}`, { headers:{'X-Requested-With':'XMLHttpRequest'} })
            .then(r=>r.json()).then(res=>{
                if (!res.data || res.data.length===0) { dropdown.style.display='none'; return; }
                dropdown.innerHTML = res.data.map(p =>
                    `<div class="person-dropdown-item" onclick="selectPerson('${escAttr(p.name)}')">${esc(p.name)}</div>`
                ).join('');
                dropdown.style.display = 'block';
            });
    }, 300);
}

function selectPerson(name) {
    document.getElementById('fPersonName').value = name;
    closePersonDropdown();
}
function closePersonDropdown() {
    document.getElementById('personDropdown').style.display = 'none';
}
document.addEventListener('click', e => {
    if (!e.target.closest('.person-wrap')) closePersonDropdown();
});

/* ══════════════════════════════════════════════════════════════
   ابزارها
══════════════════════════════════════════════════════════════ */
function showToast(msg, type='ok') {
    const t = document.getElementById('inv-toast');
    t.textContent = msg;
    t.className   = 'show ' + type;
    setTimeout(() => { t.className = ''; }, 3000);
}

function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(str) {
    if (!str) return '';
    return String(str).replace(/'/g,"\\'").replace(/"/g,'\\"');
}
function formatNum(n) {
    return Number(n).toLocaleString('fa-IR');
}
function fmtNum(n) {
    const v = parseFloat(n)||0;
    return v % 1 === 0 ? Number(v).toLocaleString('fa-IR') : Number(v).toLocaleString('fa-IR',{maximumFractionDigits:3});
}
</script>
