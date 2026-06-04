<?php
/*
 * فایل: public_html/admin/warranty.php
 * توضیحات: سیستم ردیابی گارانتی و ضمانت‌نامه محصولات
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
        $allowedRoles = ['admin','management','manager','support','sales','sales_manager','warehouse','inventory'];
        if (in_array($roleName, $allowedRoles) || $roleData['is_system'] == 1) {
            $hasAccess = true;
        } else {
            $perms = json_decode($roleData['permissions'] ?? '[]', true) ?? [];
            if (in_array('warranty', $perms) || in_array('all', $perms)) $hasAccess = true;
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

// ---- ایجاد جدول در صورت نبود ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `warranty_records` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `serial_number` VARCHAR(100) NOT NULL,
        `stuff_id` INT DEFAULT NULL,
        `stuff_name` VARCHAR(200) DEFAULT NULL,
        `invoice_item_id` INT DEFAULT NULL,
        `invoice_id` INT DEFAULT NULL,
        `person_id` INT DEFAULT NULL,
        `customer_name` VARCHAR(200) DEFAULT NULL,
        `sale_date` DATE DEFAULT NULL,
        `warranty_months` INT NOT NULL DEFAULT 12,
        `expiry_date` DATE DEFAULT NULL,
        `status` ENUM('active','expired','claimed','void') DEFAULT 'active',
        `notes` TEXT DEFAULT NULL,
        `created_by` INT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted` TINYINT(1) DEFAULT 0,
        KEY `idx_serial` (`serial_number`),
        KEY `idx_status` (`status`),
        KEY `idx_stuff` (`stuff_id`),
        KEY `idx_expiry` (`expiry_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { /* جدول از قبل وجود داشت */ }

// ---- رنگ وضعیت ----
function warrantyStatusBadge($status, $expiryDate) {
    if ($status === 'void')    return ['gray',   'باطل'];
    if ($status === 'claimed') return ['purple', 'ادعای گارانتی'];
    if ($status === 'expired') return ['rose',   'منقضی'];

    // active: چک نزدیک انقضا (۳۰ روز)
    if ($expiryDate) {
        $daysLeft = (int)floor((strtotime($expiryDate) - time()) / 86400);
        if ($daysLeft <= 30 && $daysLeft >= 0) return ['amber', 'در شرف انقضا'];
        if ($daysLeft < 0) return ['rose', 'منقضی'];
    }
    return ['green', 'فعال'];
}

// ===========================================================
// پردازش درخواست‌های AJAX
// ===========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action'] ?? '');

    try {

        // ---- لیست گارانتی‌ها ----
        if ($action === 'list') {
            $page    = max(1, (int)($_POST['page'] ?? 1));
            $perPage = 25;
            $offset  = ($page - 1) * $perPage;
            $search  = trim($_POST['search'] ?? '');
            $status  = trim($_POST['status'] ?? '');
            $stuffId = (int)($_POST['stuff_id'] ?? 0);

            $where  = ['w.is_deleted = 0'];
            $params = [];

            if ($search) {
                $where[] = '(w.serial_number LIKE ? OR w.customer_name LIKE ? OR w.stuff_name LIKE ?)';
                $s = "%$search%";
                array_push($params, $s, $s, $s);
            }
            if ($stuffId) { $where[] = 'w.stuff_id = ?'; $params[] = $stuffId; }

            // فیلتر وضعیت با در نظر گرفتن انقضا
            if ($status === 'active') {
                $where[] = "(w.status = 'active' AND (w.expiry_date IS NULL OR w.expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)))";
            } elseif ($status === 'expiring') {
                $where[] = "(w.status = 'active' AND w.expiry_date IS NOT NULL AND w.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
            } elseif ($status === 'expired') {
                $where[] = "(w.status = 'expired' OR (w.status = 'active' AND w.expiry_date < CURDATE()))";
            } elseif ($status) {
                $where[] = 'w.status = ?'; $params[] = $status;
            }

            $whereStr = implode(' AND ', $where);

            $stCount = $pdo->prepare("SELECT COUNT(*) FROM warranty_records w WHERE $whereStr");
            $stCount->execute($params);
            $total = (int)$stCount->fetchColumn();

            $stList = $pdo->prepare(
                "SELECT w.* FROM warranty_records w
                 WHERE $whereStr
                 ORDER BY w.created_at DESC
                 LIMIT $perPage OFFSET $offset"
            );
            $stList->execute($params);
            $rows = $stList->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $r) {
                list($badgeColor, $badgeLabel) = warrantyStatusBadge($r['status'], $r['expiry_date']);
                $daysLeft = null;
                if ($r['expiry_date']) {
                    $daysLeft = (int)floor((strtotime($r['expiry_date']) - time()) / 86400);
                }
                $result[] = [
                    'id'              => $r['id'],
                    'serial_number'   => $r['serial_number'],
                    'stuff_name'      => $r['stuff_name'],
                    'customer_name'   => $r['customer_name'],
                    'sale_date'       => $r['sale_date'] ? jdate('Y/m/d', strtotime($r['sale_date'])) : '',
                    'warranty_months' => $r['warranty_months'],
                    'expiry_date'     => $r['expiry_date'] ? jdate('Y/m/d', strtotime($r['expiry_date'])) : '',
                    'expiry_raw'      => $r['expiry_date'],
                    'days_left'       => $daysLeft,
                    'status'          => $r['status'],
                    'badge_color'     => $badgeColor,
                    'badge_label'     => $badgeLabel,
                    'notes'           => $r['notes'],
                    'created_at'      => $r['created_at'] ? jdate('Y/m/d', strtotime($r['created_at'])) : '',
                ];
            }

            echo json_encode([
                'ok'    => true,
                'rows'  => $result,
                'total' => $total,
                'pages' => ceil($total / $perPage),
                'page'  => $page,
            ]);
            exit;
        }

        // ---- ذخیره گارانتی ----
        if ($action === 'save') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'توکن امنیتی نامعتبر']); exit;
            }

            $id             = (int)($_POST['id'] ?? 0);
            $serialNumber   = trim($_POST['serial_number'] ?? '');
            $stuffId        = (int)($_POST['stuff_id'] ?? 0) ?: null;
            $stuffName      = trim($_POST['stuff_name'] ?? '');
            $personId       = (int)($_POST['person_id'] ?? 0) ?: null;
            $customerName   = trim($_POST['customer_name'] ?? '');
            $invoiceId      = (int)($_POST['invoice_id'] ?? 0) ?: null;
            $saleDate       = trim($_POST['sale_date'] ?? '') ?: null;
            $warrantyMonths = max(1, (int)($_POST['warranty_months'] ?? 12));
            $notes          = trim($_POST['notes'] ?? '');

            if (!$serialNumber) { echo json_encode(['ok' => false, 'msg' => 'شماره سریال الزامی است']); exit; }

            // محاسبه تاریخ انقضا
            $expiryDate = null;
            if ($saleDate) {
                try {
                    $dt = new DateTime($saleDate);
                    $dt->modify("+{$warrantyMonths} months");
                    $expiryDate = $dt->format('Y-m-d');
                } catch (Throwable $e) { $expiryDate = null; }
            }

            // وضعیت: اگر انقضا گذشته باشد expired
            $status = 'active';
            if ($expiryDate && strtotime($expiryDate) < time()) {
                $status = 'expired';
            }

            if ($id > 0) {
                $st = $pdo->prepare(
                    "UPDATE warranty_records SET serial_number=?, stuff_id=?, stuff_name=?,
                     person_id=?, customer_name=?, invoice_id=?, sale_date=?,
                     warranty_months=?, expiry_date=?, status=?, notes=?, updated_at=NOW()
                     WHERE id=? AND is_deleted=0"
                );
                $st->execute([
                    $serialNumber, $stuffId, $stuffName, $personId, $customerName,
                    $invoiceId, $saleDate, $warrantyMonths, $expiryDate, $status, $notes, $id
                ]);
                echo json_encode(['ok' => true, 'msg' => 'گارانتی به‌روزرسانی شد', 'id' => $id]);
            } else {
                // بررسی تکراری نبودن شماره سریال
                $stChk = $pdo->prepare("SELECT id FROM warranty_records WHERE serial_number=? AND is_deleted=0");
                $stChk->execute([$serialNumber]);
                if ($stChk->fetchColumn()) {
                    echo json_encode(['ok' => false, 'msg' => 'این شماره سریال قبلاً ثبت شده است']); exit;
                }

                $st = $pdo->prepare(
                    "INSERT INTO warranty_records
                     (serial_number, stuff_id, stuff_name, person_id, customer_name, invoice_id,
                      sale_date, warranty_months, expiry_date, status, notes, created_by, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())"
                );
                $st->execute([
                    $serialNumber, $stuffId, $stuffName, $personId, $customerName,
                    $invoiceId, $saleDate, $warrantyMonths, $expiryDate, $status, $notes, $userId
                ]);
                $newId = (int)$pdo->lastInsertId();
                echo json_encode(['ok' => true, 'msg' => 'گارانتی ثبت شد', 'id' => $newId]);
            }
            exit;
        }

        // ---- ادعای گارانتی ----
        if ($action === 'claim') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'توکن امنیتی نامعتبر']); exit;
            }

            $id    = (int)($_POST['id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            $stChk = $pdo->prepare("SELECT id, status FROM warranty_records WHERE id=? AND is_deleted=0");
            $stChk->execute([$id]);
            $rec = $stChk->fetch(PDO::FETCH_ASSOC);
            if (!$rec) { echo json_encode(['ok' => false, 'msg' => 'رکورد یافت نشد']); exit; }
            if ($rec['status'] === 'void') { echo json_encode(['ok' => false, 'msg' => 'گارانتی باطل است']); exit; }

            $notesUpdate = $notes ? ', notes=CONCAT(IFNULL(notes,""), "\n[ادعای گارانتی]: ' . addslashes($notes) . '")' : '';
            $pdo->prepare("UPDATE warranty_records SET status='claimed' $notesUpdate, updated_at=NOW() WHERE id=?")
                ->execute([$id]);

            echo json_encode(['ok' => true, 'msg' => 'ادعای گارانتی ثبت شد']);
            exit;
        }

        // ---- ابطال گارانتی ----
        if ($action === 'void') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'توکن امنیتی نامعتبر']); exit;
            }

            $id     = (int)($_POST['id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $notesUpdate = $reason ? ', notes=CONCAT(IFNULL(notes,""), "\n[ابطال]: ' . addslashes($reason) . '")' : '';

            $pdo->prepare("UPDATE warranty_records SET status='void' $notesUpdate, updated_at=NOW() WHERE id=? AND is_deleted=0")
                ->execute([$id]);

            echo json_encode(['ok' => true, 'msg' => 'گارانتی باطل شد']);
            exit;
        }

        // ---- آمار ----
        if ($action === 'stats') {
            $active  = (int)$pdo->query(
                "SELECT COUNT(*) FROM warranty_records WHERE status='active' AND (expiry_date IS NULL OR expiry_date >= CURDATE()) AND is_deleted=0"
            )->fetchColumn();

            $expThisMonth = (int)$pdo->query(
                "SELECT COUNT(*) FROM warranty_records WHERE expiry_date BETWEEN CURDATE() AND LAST_DAY(CURDATE()) AND is_deleted=0"
            )->fetchColumn();

            $claimed = (int)$pdo->query(
                "SELECT COUNT(*) FROM warranty_records WHERE status='claimed' AND is_deleted=0"
            )->fetchColumn();

            $total = (int)$pdo->query(
                "SELECT COUNT(*) FROM warranty_records WHERE is_deleted=0"
            )->fetchColumn();

            echo json_encode([
                'ok'            => true,
                'active'        => $active,
                'exp_this_month'=> $expThisMonth,
                'claimed'       => $claimed,
                'total'         => $total,
            ]);
            exit;
        }

        // ---- دریافت یک رکورد برای ویرایش ----
        if ($action === 'get') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM warranty_records WHERE id=? AND is_deleted=0");
            $st->execute([$id]);
            $rec = $st->fetch(PDO::FETCH_ASSOC);
            if (!$rec) { echo json_encode(['ok' => false, 'msg' => 'یافت نشد']); exit; }
            list($badgeColor, $badgeLabel) = warrantyStatusBadge($rec['status'], $rec['expiry_date']);
            $rec['badge_color'] = $badgeColor;
            $rec['badge_label'] = $badgeLabel;
            echo json_encode(['ok' => true, 'record' => $rec]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'عملیات نامشخص']);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'خطا: ' . $e->getMessage()]);
    }
    exit;
}

// ---- بارگذاری محصولات برای فیلتر ----
$stuffList = [];
try {
    $stS = $pdo->query("SELECT id, name FROM stuffs WHERE is_deleted=0 ORDER BY name LIMIT 500");
    $stuffList = $stS->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ---- بارگذاری طرف‌حساب‌ها ----
$persons = [];
try {
    $stP = $pdo->query("SELECT id, name, company_name FROM fin_persons WHERE is_deleted=0 ORDER BY name LIMIT 300");
    $persons = $stP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$csrfToken  = csrf_token();
$basePath   = '../../';
$pageTitle  = 'گارانتی محصولات';
$activePage = 'warranty';

$extraCss = '
<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">
<style>
/* ===== warranty custom styles ===== */
.war-page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.war-page-title { display: flex; align-items: center; gap: 14px; }
.war-page-icon {
    width: 52px; height: 52px; border-radius: 14px;
    background: linear-gradient(135deg, #14532d, #16a34a);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; color: #fff; flex-shrink: 0;
}
.war-page-title h1 { font-size: 1.4rem; font-weight: 700; color: #1e293b; margin: 0 0 4px; }
.war-page-title p  { font-size: 0.82rem; color: #64748b; margin: 0; }

/* تب‌ها */
.war-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 20px; }
.war-tab {
    padding: 8px 18px; border-radius: 8px; border: 1.5px solid #e2e8f0;
    background: #f8fafc; color: #475569; font-size: 0.82rem; cursor: pointer;
    font-family: Vazirmatn, sans-serif; transition: all 0.2s;
}
.war-tab:hover { border-color: #16a34a; color: #15803d; }
.war-tab.active { background: #16a34a; color: #fff; border-color: #15803d; font-weight: 600; }

/* جدول */
.war-table { width: 100%; border-collapse: collapse; }
.war-table th { background: #f1f5f9; color: #475569; font-size: 0.78rem; font-weight: 600;
                 padding: 10px 14px; text-align: right; border-bottom: 2px solid #e2e8f0; }
.war-table td { padding: 11px 14px; border-bottom: 1px solid #f1f5f9; font-size: 0.82rem; color: #334155;
                 vertical-align: middle; }
.war-table tr:hover td { background: #f8fafc; cursor: pointer; }
.war-table tr:last-child td { border-bottom: none; }

/* badge رنگی */
.badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-size: 0.74rem; font-weight: 600; white-space: nowrap;
}
.badge-green  { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.badge-amber  { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.badge-rose   { background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; }
.badge-purple { background: #faf5ff; color: #7c3aed; border: 1px solid #e9d5ff; }
.badge-gray   { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

/* نوار پیشرفت گارانتی */
.war-progress-wrap { display: flex; align-items: center; gap: 8px; }
.war-progress-bar  {
    flex: 1; height: 6px; background: #e2e8f0; border-radius: 4px; overflow: hidden; min-width: 60px;
}
.war-progress-fill { height: 100%; border-radius: 4px; background: #16a34a; transition: width 0.3s; }
.war-progress-fill.amber { background: #d97706; }
.war-progress-fill.rose  { background: #e11d48; }
.war-days-label { font-size: 0.74rem; color: #64748b; white-space: nowrap; }

/* drawer */
.war-drawer-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1200;
}
.war-drawer-overlay.active { display: block; }
.war-drawer {
    position: fixed; top: 0; left: -640px; width: 640px; max-width: 98vw;
    height: 100vh; background: #fff; box-shadow: 4px 0 30px rgba(0,0,0,0.15);
    z-index: 1201; display: flex; flex-direction: column;
    transition: left 0.3s cubic-bezier(0.4,0,0.2,1);
    font-family: Vazirmatn, sans-serif;
}
.war-drawer.open { left: 0; }
.war-drawer-head {
    padding: 18px 22px; background: linear-gradient(135deg,#14532d,#16a34a);
    color: #fff; display: flex; align-items: flex-start; justify-content: space-between; flex-shrink: 0;
}
.war-drawer-head h3 { margin: 0 0 6px; font-size: 1rem; font-weight: 700; }
.war-drawer-head p  { margin: 0; font-size: 0.78rem; opacity: 0.85; }
.war-drawer-close {
    background: rgba(255,255,255,0.2); border: none; color: #fff; width: 34px; height: 34px;
    border-radius: 8px; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
}
.war-drawer-close:hover { background: rgba(255,255,255,0.35); }
.war-drawer-body { flex: 1; overflow-y: auto; padding: 20px 22px; }
.war-drawer-footer {
    padding: 16px 22px; border-top: 1px solid #e2e8f0; flex-shrink: 0; background: #f8fafc;
}

.war-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media(max-width:600px) { .war-form-grid { grid-template-columns: 1fr; } }

.war-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px; }
.war-info-item label { font-size: 0.74rem; color: #94a3b8; display: block; margin-bottom: 3px; }
.war-info-item span  { font-size: 0.85rem; color: #1e293b; font-weight: 500; }

.war-notes-box {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 14px; font-size: 0.84rem; color: #334155; line-height: 1.8; white-space: pre-wrap;
}
.empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; font-size: 0.9rem; }
.empty-state-icon { font-size: 3rem; margin-bottom: 12px; }

/* بنر هشدار انقضای نزدیک */
.expiry-banner {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border: 1px solid #fde68a; border-radius: 10px;
    padding: 12px 16px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 10px; font-size: 0.83rem; color: #92400e;
}
</style>
';

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content">
    <div class="war-page-header">
        <div class="war-page-title">
            <div class="war-page-icon">🛡️</div>
            <div>
                <h1>گارانتی محصولات</h1>
                <p>ردیابی ضمانت‌نامه و وضعیت گارانتی کالاهای فروخته‌شده</p>
            </div>
        </div>
        <button class="fin-btn fin-btn-primary" onclick="openAddDrawer()" style="background:#16a34a;border-color:#15803d">
            ➕ ثبت گارانتی
        </button>
    </div>

    <!-- کارت‌های آمار -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px">
        <div class="fin-stat-card green">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#14532d,#16a34a)">✅</div>
            <div><div class="fin-stat-label">گارانتی فعال</div><div class="fin-stat-num" id="statActive">—</div></div>
        </div>
        <div class="fin-stat-card amber">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#92400e,#d97706)">⌛</div>
            <div><div class="fin-stat-label">منقضی این ماه</div><div class="fin-stat-num" id="statExpMonth">—</div></div>
        </div>
        <div class="fin-stat-card purple">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#4c1d95,#7c3aed)">⚠️</div>
            <div><div class="fin-stat-label">ادعای باز</div><div class="fin-stat-num" id="statClaimed">—</div></div>
        </div>
        <div class="fin-stat-card blue">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#1e3a8a,#2563eb)">📋</div>
            <div><div class="fin-stat-label">مجموع ثبت‌شده</div><div class="fin-stat-num" id="statTotal">—</div></div>
        </div>
    </div>

    <!-- تب‌ها -->
    <div class="war-tabs" id="warTabs">
        <button class="war-tab active" data-status="" onclick="switchTab(this,'')">همه</button>
        <button class="war-tab" data-status="active" onclick="switchTab(this,'active')">فعال</button>
        <button class="war-tab" data-status="expiring" onclick="switchTab(this,'expiring')">در شرف انقضا</button>
        <button class="war-tab" data-status="expired" onclick="switchTab(this,'expired')">منقضی</button>
        <button class="war-tab" data-status="claimed" onclick="switchTab(this,'claimed')">ادعای گارانتی</button>
        <button class="war-tab" data-status="void" onclick="switchTab(this,'void')">باطل</button>
    </div>

    <!-- فیلترها -->
    <div class="fin-panel" style="margin-bottom:16px;padding:14px 16px">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:200px">
                <label class="fin-label">جستجو</label>
                <input type="text" id="fSearch" class="fin-input" placeholder="شماره سریال / نام مشتری / نام محصول..." onkeyup="debounceList()">
            </div>
            <div>
                <label class="fin-label">محصول</label>
                <select id="fStuff" class="fin-input" onchange="loadList(1)">
                    <option value="">همه محصولات</option>
                    <?php foreach ($stuffList as $st): ?>
                    <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['name'], ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="fin-btn" onclick="loadList(1)" style="background:#16a34a;color:#fff;border-color:#15803d">🔍 جستجو</button>
        </div>
    </div>

    <!-- جدول لیست -->
    <div class="fin-panel" style="padding:0">
        <div style="overflow-x:auto">
            <table class="war-table">
                <thead>
                    <tr>
                        <th>شماره سریال</th>
                        <th>محصول</th>
                        <th>مشتری</th>
                        <th>تاریخ فروش</th>
                        <th>مدت گارانتی</th>
                        <th>تاریخ انقضا</th>
                        <th>باقی‌مانده</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody id="warrantyListBody">
                    <tr><td colspan="9" class="empty-state"><div class="empty-state-icon">⏳</div>در حال بارگذاری...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="warrantyPagination" style="padding:14px 16px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap"></div>
    </div>
</main>

<!-- ======= Overlay ======= -->
<div class="war-drawer-overlay" id="warOverlay" onclick="closeDrawer()"></div>

<!-- ======= Drawer: ثبت / ویرایش ======= -->
<div class="war-drawer" id="addDrawer">
    <div class="war-drawer-head">
        <div>
            <h3 id="addDrawerTitle">ثبت گارانتی جدید</h3>
            <p>اطلاعات گارانتی محصول را وارد کنید</p>
        </div>
        <button class="war-drawer-close" onclick="closeDrawer()">✕</button>
    </div>
    <div class="war-drawer-body">
        <form id="addWarrantyForm" onsubmit="return false">
            <input type="hidden" name="id" id="editWarrantyId" value="0">
            <div class="war-form-grid" style="margin-bottom:14px">
                <div>
                    <label class="fin-label">شماره سریال *</label>
                    <input type="text" name="serial_number" id="wSerial" class="fin-input" placeholder="مثال: SN-2024-00123" required>
                </div>
                <div>
                    <label class="fin-label">محصول</label>
                    <select name="stuff_id" id="wStuffId" class="fin-input" onchange="fillStuffName(this)">
                        <option value="0">— انتخاب محصول —</option>
                        <?php foreach ($stuffList as $st): ?>
                        <option value="<?= (int)$st['id'] ?>" data-name="<?= htmlspecialchars($st['name'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($st['name'], ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="fin-label">نام محصول (دستی)</label>
                    <input type="text" name="stuff_name" id="wStuffName" class="fin-input" placeholder="اگر در لیست نیست">
                </div>
                <div>
                    <label class="fin-label">مشتری</label>
                    <select name="person_id" id="wPersonId" class="fin-input" onchange="fillPersonName(this)">
                        <option value="0">— انتخاب طرف حساب —</option>
                        <?php foreach ($persons as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars($p['company_name'] ?: $p['name'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($p['company_name'] ?: $p['name'], ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="fin-label">نام مشتری (دستی)</label>
                    <input type="text" name="customer_name" id="wCustomerName" class="fin-input" placeholder="اگر در لیست نیست">
                </div>
                <div>
                    <label class="fin-label">شماره فاکتور</label>
                    <input type="number" name="invoice_id" id="wInvoiceId" class="fin-input" placeholder="شناسه فاکتور (اختیاری)" min="0">
                </div>
                <div>
                    <label class="fin-label">تاریخ فروش (میلادی)</label>
                    <input type="date" name="sale_date" id="wSaleDate" class="fin-input" onchange="computeExpiry()">
                </div>
                <div>
                    <label class="fin-label">مدت گارانتی (ماه) *</label>
                    <input type="number" name="warranty_months" id="wMonths" class="fin-input" value="12" min="1" max="120" onchange="computeExpiry()" required>
                </div>
            </div>

            <!-- نمایش تاریخ انقضا -->
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
                <span style="font-size:1.2rem">🛡️</span>
                <div>
                    <div style="font-size:0.75rem;color:#166534;margin-bottom:2px">تاریخ انقضای گارانتی (محاسبه‌شده)</div>
                    <div id="expiryPreview" style="font-size:0.9rem;font-weight:700;color:#15803d">— تاریخ فروش را وارد کنید —</div>
                </div>
            </div>

            <div style="margin-bottom:14px">
                <label class="fin-label">یادداشت</label>
                <textarea name="notes" id="wNotes" class="fin-input" rows="3" placeholder="هرگونه توضیح..." style="width:100%;resize:vertical"></textarea>
            </div>

            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        </form>
    </div>
    <div class="war-drawer-footer">
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="fin-btn" onclick="closeDrawer()" style="background:#f1f5f9;color:#475569;border-color:#e2e8f0">انصراف</button>
            <button class="fin-btn" onclick="submitWarranty()" style="background:#16a34a;color:#fff;border-color:#15803d">
                ✅ ثبت گارانتی
            </button>
        </div>
    </div>
</div>

<!-- ======= Drawer: جزئیات ======= -->
<div class="war-drawer" id="detailDrawer" style="width:560px">
    <div class="war-drawer-head" id="detailDrawerHead">
        <div>
            <h3 id="detailSerial">جزئیات گارانتی</h3>
            <p id="detailProduct">—</p>
        </div>
        <button class="war-drawer-close" onclick="closeDetailDrawer()">✕</button>
    </div>
    <div class="war-drawer-body" id="detailBody">
        <div class="empty-state"><div class="empty-state-icon">⏳</div>در حال بارگذاری...</div>
    </div>
    <div class="war-drawer-footer" id="detailFooter" style="display:none">
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
            <button class="fin-btn" id="btnClaim" onclick="claimWarranty()" style="background:#7c3aed;color:#fff;border-color:#6d28d9">
                ⚠️ ادعای گارانتی
            </button>
            <button class="fin-btn" id="btnVoid" onclick="voidWarranty()" style="background:#f1f5f9;color:#64748b;border-color:#e2e8f0">
                🚫 ابطال
            </button>
            <button class="fin-btn" id="btnEdit" onclick="editWarranty()" style="background:#2563eb;color:#fff;border-color:#1d4ed8">
                ✏️ ویرایش
            </button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>

<script>
'use strict';
var currentStatus = '';
var currentPage   = 1;
var currentWarId  = 0;
var debounceTimer = null;
var csrfToken = <?= json_encode($csrfToken) ?>;

document.addEventListener('DOMContentLoaded', function() {
    loadStats();
    loadList(1);
});

function loadStats() {
    $.post('', { action: 'stats' }, function(res) {
        if (!res.ok) return;
        $('#statActive').text(res.active);
        $('#statExpMonth').text(res.exp_this_month);
        $('#statClaimed').text(res.claimed);
        $('#statTotal').text(res.total);
    }, 'json');
}

function switchTab(btn, status) {
    $('.war-tab').removeClass('active');
    $(btn).addClass('active');
    currentStatus = status;
    loadList(1);
}

function debounceList() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function() { loadList(1); }, 350);
}

function loadList(page) {
    currentPage = page;
    var params = {
        action:   'list',
        page:     page,
        search:   $('#fSearch').val(),
        status:   currentStatus,
        stuff_id: $('#fStuff').val(),
    };
    $('#warrantyListBody').html('<tr><td colspan="9" class="empty-state"><div class="empty-state-icon">⏳</div>در حال بارگذاری...</td></tr>');
    $.post('', params, function(res) {
        if (!res.ok) { showErrRow(); return; }
        if (!res.rows || res.rows.length === 0) {
            $('#warrantyListBody').html('<tr><td colspan="9" class="empty-state"><div class="empty-state-icon">🛡️</div>رکوردی یافت نشد</td></tr>');
            $('#warrantyPagination').html('');
            return;
        }

        var html = '';
        res.rows.forEach(function(r) {
            var progressHtml = buildProgress(r.days_left, r.warranty_months, r.status);
            html += '<tr>';
            html += '<td><span style="font-family:monospace;font-weight:700;color:#15803d;cursor:pointer" onclick="openDetailDrawer(' + r.id + ')">' + esc(r.serial_number) + '</span></td>';
            html += '<td>' + esc(r.stuff_name || '—') + '</td>';
            html += '<td>' + esc(r.customer_name || '—') + '</td>';
            html += '<td style="font-size:0.78rem">' + (r.sale_date || '—') + '</td>';
            html += '<td style="text-align:center">' + r.warranty_months + ' ماه</td>';
            html += '<td style="font-size:0.78rem">' + (r.expiry_date || '—') + '</td>';
            html += '<td>' + progressHtml + '</td>';
            html += '<td><span class="badge badge-' + r.badge_color + '">' + r.badge_label + '</span></td>';
            html += '<td>';
            html += '<button class="fin-btn" onclick="openDetailDrawer(' + r.id + ')" style="padding:4px 10px;font-size:0.76rem;background:#f8fafc;color:#475569;border-color:#e2e8f0">جزئیات</button>';
            html += '</td>';
            html += '</tr>';
        });
        $('#warrantyListBody').html(html);
        buildPagination(res.pages, res.page);
    }, 'json').fail(showErrRow);
}

function buildProgress(daysLeft, warrantyMonths, status) {
    if (status === 'void' || status === 'claimed') return '<span class="badge badge-gray">—</span>';
    if (daysLeft === null) return '<span style="color:#94a3b8;font-size:0.78rem">—</span>';

    var totalDays = warrantyMonths * 30;
    var pct = Math.max(0, Math.min(100, (daysLeft / totalDays) * 100));
    var cls = pct > 30 ? '' : (pct > 10 ? 'amber' : 'rose');

    if (daysLeft < 0) {
        return '<span class="badge badge-rose">منقضی</span>';
    }
    var label = daysLeft + ' روز';
    return '<div class="war-progress-wrap">' +
           '<div class="war-progress-bar"><div class="war-progress-fill ' + cls + '" style="width:' + pct + '%"></div></div>' +
           '<span class="war-days-label">' + label + '</span></div>';
}

function showErrRow() {
    $('#warrantyListBody').html('<tr><td colspan="9" class="empty-state" style="color:#e11d48"><div class="empty-state-icon">⚠️</div>خطا در بارگذاری</td></tr>');
}

function buildPagination(pages, current) {
    if (pages <= 1) { $('#warrantyPagination').html(''); return; }
    var html = '';
    for (var i = 1; i <= pages; i++) {
        var active = i === current ? 'style="background:#16a34a;color:#fff;border-color:#15803d"' : '';
        html += '<button class="fin-btn" ' + active + ' onclick="loadList(' + i + ')">' + i + '</button>';
    }
    $('#warrantyPagination').html(html);
}

// ---- drawer ثبت/ویرایش ----
function openAddDrawer() {
    document.getElementById('editWarrantyId').value = '0';
    document.getElementById('addWarrantyForm').reset();
    document.getElementById('editWarrantyId').value = '0';
    document.getElementById('expiryPreview').textContent = '— تاریخ فروش را وارد کنید —';
    document.getElementById('addDrawerTitle').textContent = 'ثبت گارانتی جدید';
    openWarDrawer('addDrawer');
}

function editWarranty() {
    if (!currentWarId) return;
    $.post('', { action: 'get', id: currentWarId }, function(res) {
        if (!res.ok) { alert(res.msg || 'خطا'); return; }
        var r = res.record;
        document.getElementById('editWarrantyId').value = r.id;
        document.getElementById('wSerial').value = r.serial_number || '';
        document.getElementById('wStuffId').value = r.stuff_id || '0';
        document.getElementById('wStuffName').value = r.stuff_name || '';
        document.getElementById('wPersonId').value = r.person_id || '0';
        document.getElementById('wCustomerName').value = r.customer_name || '';
        document.getElementById('wInvoiceId').value = r.invoice_id || '';
        document.getElementById('wSaleDate').value = r.sale_date || '';
        document.getElementById('wMonths').value = r.warranty_months || '12';
        document.getElementById('wNotes').value = r.notes || '';
        document.getElementById('addDrawerTitle').textContent = 'ویرایش گارانتی';
        computeExpiry();
        closeDetailDrawer();
        setTimeout(function() { openWarDrawer('addDrawer'); }, 200);
    }, 'json');
}

function fillStuffName(sel) {
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('wStuffName').value = opt.dataset.name || '';
}
function fillPersonName(sel) {
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('wCustomerName').value = opt.dataset.name || '';
}

function computeExpiry() {
    var saleDate = document.getElementById('wSaleDate').value;
    var months   = parseInt(document.getElementById('wMonths').value) || 12;
    var preview  = document.getElementById('expiryPreview');

    if (!saleDate) {
        preview.textContent = '— تاریخ فروش را وارد کنید —';
        preview.style.color = '#15803d';
        return;
    }
    try {
        var d = new Date(saleDate);
        d.setMonth(d.getMonth() + months);
        var y = d.getFullYear(), m = String(d.getMonth()+1).padStart(2,'0'), day = String(d.getDate()).padStart(2,'0');
        var expiryStr = y + '-' + m + '-' + day;
        var today = new Date(); today.setHours(0,0,0,0);
        var daysLeft = Math.floor((d - today) / 86400000);

        if (daysLeft < 0) {
            preview.textContent = expiryStr + ' (منقضی شده)';
            preview.style.color = '#e11d48';
        } else if (daysLeft <= 30) {
            preview.textContent = expiryStr + ' (' + daysLeft + ' روز مانده — نزدیک انقضا)';
            preview.style.color = '#d97706';
        } else {
            preview.textContent = expiryStr + ' (' + daysLeft + ' روز مانده)';
            preview.style.color = '#15803d';
        }
    } catch(e) {
        preview.textContent = 'تاریخ نامعتبر';
    }
}

function submitWarranty() {
    var serial = document.getElementById('wSerial').value.trim();
    var months = document.getElementById('wMonths').value;
    if (!serial) { alert('شماره سریال الزامی است'); return; }
    if (!months || parseInt(months) < 1) { alert('مدت گارانتی باید حداقل ۱ ماه باشد'); return; }

    var data = $('#addWarrantyForm').serializeArray();
    data.push({ name: 'action', value: 'save' });
    data.push({ name: 'csrf_token', value: csrfToken });

    $.post('', data, function(res) {
        if (!res.ok) { alert(res.msg || 'خطا'); return; }
        closeDrawer();
        loadList(currentPage);
        loadStats();
    }, 'json').fail(function() { alert('خطا در ارتباط با سرور'); });
}

// ---- drawer جزئیات ----
function openDetailDrawer(id) {
    currentWarId = id;
    $('#detailBody').html('<div class="empty-state"><div class="empty-state-icon">⏳</div>در حال بارگذاری...</div>');
    $('#detailFooter').hide();
    openWarDrawer('detailDrawer');

    $.post('', { action: 'get', id: id }, function(res) {
        if (!res.ok) { $('#detailBody').html('<div class="empty-state" style="color:#e11d48">خطا</div>'); return; }
        var r = res.record;
        document.getElementById('detailSerial').textContent = r.serial_number;
        document.getElementById('detailProduct').textContent = r.stuff_name || 'بدون نام محصول';

        // محاسبه روزهای باقی‌مانده
        var daysLeftTxt = '—';
        if (r.expiry_date) {
            var daysLeft = Math.floor((new Date(r.expiry_date) - new Date()) / 86400000);
            daysLeftTxt = daysLeft < 0 ? 'منقضی شده' : daysLeft + ' روز';
        }

        var html = '';
        // اگر در شرف انقضاست
        if (r.status === 'active' && r.expiry_date) {
            var dl = Math.floor((new Date(r.expiry_date) - new Date()) / 86400000);
            if (dl >= 0 && dl <= 30) {
                html += '<div class="expiry-banner">⚠️ این گارانتی در ' + dl + ' روز دیگر منقضی می‌شود</div>';
            }
        }

        html += '<div class="war-info-grid">';
        html += '<div class="war-info-item"><label>وضعیت</label><span><span class="badge badge-' + r.badge_color + '">' + r.badge_label + '</span></span></div>';
        html += '<div class="war-info-item"><label>مدت گارانتی</label><span>' + r.warranty_months + ' ماه</span></div>';
        html += '<div class="war-info-item"><label>مشتری</label><span>' + esc(r.customer_name || '—') + '</span></div>';
        html += '<div class="war-info-item"><label>محصول</label><span>' + esc(r.stuff_name || '—') + '</span></div>';
        html += '<div class="war-info-item"><label>تاریخ فروش</label><span>' + (r.sale_date || '—') + '</span></div>';
        html += '<div class="war-info-item"><label>تاریخ انقضا</label><span>' + (r.expiry_date || '—') + '</span></div>';
        html += '<div class="war-info-item"><label>باقی‌مانده</label><span>' + daysLeftTxt + '</span></div>';
        html += '<div class="war-info-item"><label>شماره فاکتور</label><span>' + (r.invoice_id || '—') + '</span></div>';
        html += '</div>';

        if (r.notes) {
            html += '<div style="margin-bottom:10px;font-size:0.8rem;font-weight:700;color:#475569">یادداشت</div>';
            html += '<div class="war-notes-box">' + esc(r.notes) + '</div>';
        }

        $('#detailBody').html(html);

        // نمایش دکمه‌های مناسب
        var isActive = (r.status === 'active' || r.badge_color === 'amber');
        $('#btnClaim').toggle(r.status === 'active' || r.status === 'expired');
        $('#btnVoid').toggle(r.status !== 'void');
        $('#detailFooter').show();
    }, 'json').fail(function() {
        $('#detailBody').html('<div class="empty-state" style="color:#e11d48">⚠️ خطا</div>');
    });
}

function claimWarranty() {
    if (!currentWarId) return;
    var notes = prompt('توضیح ادعای گارانتی (اختیاری):');
    if (notes === null) return; // لغو
    $.post('', {
        action: 'claim',
        id: currentWarId,
        notes: notes || '',
        csrf_token: csrfToken
    }, function(res) {
        if (!res.ok) { alert(res.msg || 'خطا'); return; }
        openDetailDrawer(currentWarId);
        loadList(currentPage);
        loadStats();
    }, 'json');
}

function voidWarranty() {
    if (!currentWarId) return;
    var reason = prompt('دلیل ابطال گارانتی:');
    if (reason === null) return;
    if (!reason.trim()) { alert('دلیل ابطال الزامی است'); return; }
    $.post('', {
        action: 'void',
        id: currentWarId,
        reason: reason,
        csrf_token: csrfToken
    }, function(res) {
        if (!res.ok) { alert(res.msg || 'خطا'); return; }
        closeDetailDrawer();
        loadList(currentPage);
        loadStats();
    }, 'json');
}

// ---- مدیریت drawer ----
function openWarDrawer(id) {
    document.getElementById('warOverlay').classList.add('active');
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDrawer() {
    document.getElementById('warOverlay').classList.remove('active');
    document.querySelectorAll('.war-drawer').forEach(function(d) { d.classList.remove('open'); });
    document.body.style.overflow = '';
}
function closeDetailDrawer() {
    document.getElementById('detailDrawer').classList.remove('open');
    // اگر drawer دیگری باز نیست overlay ببند
    var anyOpen = false;
    document.querySelectorAll('.war-drawer').forEach(function(d) { if(d.classList.contains('open')) anyOpen = true; });
    if (!anyOpen) {
        document.getElementById('warOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }
}

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
