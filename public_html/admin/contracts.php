<?php
/*
 * فایل: public_html/admin/contracts.php
 * توضیحات: مدیریت قراردادها — ثبت، ویرایش، پیگیری وضعیت و هشدار انقضا
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
        $allowedRoles = ['admin','management','manager','finance_manager','finance_expert','accountant','sales','sales_manager','legal'];
        if (in_array($roleName, $allowedRoles) || $roleData['is_system'] == 1) {
            $hasAccess = true;
        } else {
            $perms = json_decode($roleData['permissions'] ?? '[]', true) ?? [];
            if (in_array('contracts', $perms) || in_array('all', $perms)) $hasAccess = true;
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

// ---- ایجاد جدول قراردادها در صورت نبود ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `contracts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `contract_number` VARCHAR(30) NOT NULL,
        `title` VARCHAR(200) NOT NULL,
        `person_id` INT DEFAULT 0,
        `customer_name` VARCHAR(200) DEFAULT NULL,
        `start_date` DATE DEFAULT NULL,
        `end_date` DATE DEFAULT NULL,
        `amount` DECIMAL(20,0) DEFAULT 0,
        `paid_amount` DECIMAL(20,0) DEFAULT 0,
        `status` ENUM('draft','active','expired','cancelled') DEFAULT 'draft',
        `description` TEXT DEFAULT NULL,
        `file_path` VARCHAR(255) DEFAULT NULL,
        `created_by` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted` TINYINT(1) DEFAULT 0,
        UNIQUE KEY `uq_contract_number` (`contract_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { /* جدول از قبل وجود داشت */ }

// ---- شماره‌گذاری خودکار قرارداد ----
function nextContractNumber($pdo) {
    $jYear = jdate('Y', '', '', 'Asia/Tehran', 'en');
    try {
        $last = $pdo->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(contract_number,'-',-1) AS UNSIGNED))
             FROM contracts WHERE contract_number LIKE 'CT-{$jYear}-%'"
        )->fetchColumn();
    } catch (Throwable $e) { $last = 0; }
    $seq = (int)$last + 1;
    return 'CT-' . $jYear . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
}

// ---- تبدیل تاریخ شمسی به میلادی ----
function jalaliToGregorianDate($jalaliStr) {
    $jalaliStr = faToEn(trim($jalaliStr));
    if (!$jalaliStr) return null;
    $parts = preg_split('/[\/\-]/', $jalaliStr);
    if (count($parts) !== 3) return null;
    list($jy, $jm, $jd) = $parts;
    $g = jalali_to_gregorian((int)$jy, (int)$jm, (int)$jd);
    if (!$g) return null;
    return sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
}

// ===========================================================
// پردازش درخواست‌های AJAX
// ===========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action'] ?? '');

    try {

        // ---- لیست قراردادها ----
        if ($action === 'list') {
            $page    = max(1, (int)($_POST['page'] ?? 1));
            $perPage = 20;
            $offset  = ($page - 1) * $perPage;
            $search  = trim($_POST['search'] ?? '');
            $status  = trim($_POST['status'] ?? '');

            $where  = ['c.is_deleted = 0'];
            $params = [];

            if ($search) {
                $where[] = '(c.contract_number LIKE ? OR c.title LIKE ? OR c.customer_name LIKE ? OR p.name LIKE ? OR p.company_name LIKE ?)';
                $s = "%$search%";
                array_push($params, $s, $s, $s, $s, $s);
            }
            if ($status) { $where[] = 'c.status = ?'; $params[] = $status; }

            $ws = implode(' AND ', $where);

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM contracts c LEFT JOIN fin_persons p ON p.id = c.person_id WHERE $ws");
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();

            $stL = $pdo->prepare(
                "SELECT c.id, c.contract_number, c.title, c.start_date, c.end_date,
                        c.amount, c.paid_amount, c.status, c.file_path,
                        COALESCE(p.company_name, p.name, c.customer_name) AS person_name
                 FROM contracts c
                 LEFT JOIN fin_persons p ON p.id = c.person_id
                 WHERE $ws
                 ORDER BY c.id DESC
                 LIMIT $perPage OFFSET $offset"
            );
            $stL->execute($params);
            $rows = $stL->fetchAll(PDO::FETCH_ASSOC);

            $statusMap = [
                'draft'     => ['label' => 'پیش‌نویس',   'cls' => 'amber'],
                'active'    => ['label' => 'فعال',        'cls' => 'green'],
                'expired'   => ['label' => 'منقضی',       'cls' => 'rose'],
                'cancelled' => ['label' => 'لغو شده',     'cls' => 'gray'],
            ];

            $today         = date('Y-m-d');
            $thirtyDaysOut = date('Y-m-d', strtotime('+30 days'));

            foreach ($rows as &$r) {
                $r['amount_fmt']     = number_format((int)$r['amount']);
                $r['paid_fmt']       = number_format((int)$r['paid_amount']);
                $r['start_jalali']   = $r['start_date'] ? jdate('Y/m/d', $r['start_date']) : '—';
                $r['end_jalali']     = $r['end_date']   ? jdate('Y/m/d', $r['end_date'])   : '—';
                $sl = $statusMap[$r['status']] ?? ['label' => $r['status'], 'cls' => 'gray'];
                $r['status_label'] = $sl['label'];
                $r['status_class'] = $sl['cls'];
                // هشدار انقضا: قرارداد فعال که ظرف ۳۰ روز منقضی می‌شود
                $r['expiring_soon'] = (
                    $r['status'] === 'active'
                    && $r['end_date']
                    && $r['end_date'] >= $today
                    && $r['end_date'] <= $thirtyDaysOut
                );
            }
            unset($r);

            echo json_encode([
                'ok'   => true,
                'rows' => $rows,
                'pagination' => [
                    'page'        => $page,
                    'total_pages' => max(1, (int)ceil($total / $perPage)),
                    'total_rows'  => $total,
                ],
            ]);
            exit;
        }

        // ---- بارگذاری یک قرارداد برای ویرایش ----
        if ($action === 'load') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare(
                "SELECT c.*, COALESCE(p.company_name, p.name) AS person_label
                 FROM contracts c LEFT JOIN fin_persons p ON p.id = c.person_id
                 WHERE c.id = ? AND c.is_deleted = 0"
            );
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'msg' => 'قرارداد یافت نشد.']); exit; }

            $row['start_jalali'] = $row['start_date'] ? jdate('Y/m/d', $row['start_date'], '', 'Asia/Tehran', 'en') : '';
            $row['end_jalali']   = $row['end_date']   ? jdate('Y/m/d', $row['end_date'],   '', 'Asia/Tehran', 'en') : '';
            echo json_encode(['ok' => true, 'data' => $row]);
            exit;
        }

        // ---- ذخیره (ایجاد / ویرایش) ----
        if ($action === 'save') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'توکن CSRF نامعتبر است.']); exit;
            }

            $id          = (int)($_POST['id'] ?? 0);
            $title       = trim($_POST['title'] ?? '');
            $personId    = (int)($_POST['person_id'] ?? 0);
            $custName    = trim($_POST['customer_name'] ?? '');
            $startJalali = trim($_POST['start_date'] ?? '');
            $endJalali   = trim($_POST['end_date'] ?? '');
            $amount      = (int)faToEn($_POST['amount'] ?? '0');
            $paidAmount  = (int)faToEn($_POST['paid_amount'] ?? '0');
            $status      = in_array($_POST['status'] ?? '', ['draft','active','expired','cancelled'])
                           ? $_POST['status'] : 'draft';
            $description = trim($_POST['description'] ?? '');

            if (!$title) { echo json_encode(['ok' => false, 'msg' => 'عنوان قرارداد الزامی است.']); exit; }

            $startGreg = $startJalali ? jalaliToGregorianDate($startJalali) : null;
            $endGreg   = $endJalali   ? jalaliToGregorianDate($endJalali)   : null;

            // آپلود فایل پیوست
            $filePath = null;
            if (!empty($_FILES['contract_file']['tmp_name'])) {
                $uploadDir = __DIR__ . '/../uploads/contracts/';
                $allowed   = ['application/pdf','image/jpeg','image/png',
                              'application/msword',
                              'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $res = upload_secure_file(
                    $_FILES['contract_file']['tmp_name'],
                    $_FILES['contract_file']['name'],
                    $uploadDir,
                    $allowed,
                    'contract_'
                );
                if ($res) $filePath = 'uploads/contracts/' . $res['name'];
            }

            if ($id > 0) {
                // ویرایش
                $sets  = ['title=?','person_id=?','customer_name=?','start_date=?','end_date=?',
                           'amount=?','paid_amount=?','status=?','description=?','updated_at=NOW()'];
                $vals  = [$title, $personId, $custName, $startGreg, $endGreg,
                           $amount, $paidAmount, $status, $description];
                if ($filePath !== null) { $sets[] = 'file_path=?'; $vals[] = $filePath; }
                $vals[] = $id;

                $pdo->prepare("UPDATE contracts SET " . implode(',', $sets) . " WHERE id=? AND is_deleted=0")
                    ->execute($vals);

                logSystem('contracts', 'edit', $id, "ویرایش قرارداد: $title");
                echo json_encode(['ok' => true, 'msg' => 'قرارداد با موفقیت ویرایش شد.', 'id' => $id]);
            } else {
                // ایجاد
                $contractNumber = nextContractNumber($pdo);
                $st = $pdo->prepare(
                    "INSERT INTO contracts
                        (contract_number, title, person_id, customer_name, start_date, end_date,
                         amount, paid_amount, status, description, file_path, created_by, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
                );
                $st->execute([
                    $contractNumber, $title, $personId, $custName, $startGreg, $endGreg,
                    $amount, $paidAmount, $status, $description, $filePath, $userId,
                ]);
                $newId = (int)$pdo->lastInsertId();
                logSystem('contracts', 'create', $newId, "ایجاد قرارداد: $contractNumber — $title");
                echo json_encode(['ok' => true, 'msg' => 'قرارداد با موفقیت ثبت شد.', 'id' => $newId, 'number' => $contractNumber]);
            }
            exit;
        }

        // ---- حذف نرم ----
        if ($action === 'delete') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'توکن CSRF نامعتبر است.']); exit;
            }
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE contracts SET is_deleted=1, updated_at=NOW() WHERE id=? AND is_deleted=0")
                ->execute([$id]);
            logSystem('contracts', 'delete', $id, 'حذف قرارداد');
            echo json_encode(['ok' => true, 'msg' => 'قرارداد حذف شد.']);
            exit;
        }

        // ---- تغییر وضعیت ----
        if ($action === 'change_status') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'توکن CSRF نامعتبر است.']); exit;
            }
            $id     = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['draft','active','expired','cancelled'])
                      ? $_POST['status'] : 'draft';
            $pdo->prepare("UPDATE contracts SET status=?, updated_at=NOW() WHERE id=? AND is_deleted=0")
                ->execute([$status, $id]);
            logSystem('contracts', 'status_change', $id, "تغییر وضعیت به: $status");
            echo json_encode(['ok' => true, 'msg' => 'وضعیت قرارداد تغییر یافت.']);
            exit;
        }

        // ---- جستجوی طرف حساب (autocomplete) ----
        if ($action === 'search_persons') {
            $q   = trim($_POST['q'] ?? '');
            $res = [];
            if (strlen($q) >= 1) {
                $st = $pdo->prepare(
                    "SELECT id, COALESCE(company_name, name) AS label
                     FROM fin_persons WHERE is_deleted = 0
                     AND (name LIKE ? OR company_name LIKE ?)
                     LIMIT 20"
                );
                $like = "%$q%";
                $st->execute([$like, $like]);
                $res = $st->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['ok' => true, 'items' => $res]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'عملیات نامشخص.']);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'خطای سرور: ' . $e->getMessage()]);
    }
    exit;
}

// ---- صفحه HTML ----
$basePath  = '../../';
$pageTitle = 'مدیریت قراردادها';
$extraCss  = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';
$csrfToken = csrf_token();

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>

<div class="main-content" id="mainContent">
<div style="padding:24px 28px;max-width:1400px;margin:0 auto;">

    <!-- سربرگ صفحه -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="margin:0;font-size:1.5rem;font-weight:700;color:#1e293b;">
                📋 مدیریت قراردادها
            </h1>
            <p style="margin:4px 0 0;color:#64748b;font-size:0.9rem;">ثبت، پیگیری و مدیریت قراردادهای کسب‌وکار</p>
        </div>
        <button class="fin-btn blue" id="btnNewContract" onclick="openDrawer()">
            ➕ قرارداد جدید
        </button>
    </div>

    <!-- کارت‌های آمار -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;" id="statsArea">
        <div class="fin-stat-card blue"><div class="label">کل قراردادها</div><div class="value" id="statTotal">—</div></div>
        <div class="fin-stat-card green"><div class="label">قراردادهای فعال</div><div class="value" id="statActive">—</div></div>
        <div class="fin-stat-card amber"><div class="label">در حال انقضا</div><div class="value" id="statExpiring">—</div></div>
        <div class="fin-stat-card rose"><div class="label">منقضی‌شده</div><div class="value" id="statExpired">—</div></div>
    </div>

    <!-- فیلترها -->
    <div class="fin-panel" style="padding:16px 20px;margin-bottom:16px;">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
            <input type="text" id="filterSearch" placeholder="جستجو در عنوان، طرف حساب، شماره..."
                   style="flex:1;min-width:200px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:0.9rem;">
            <select id="filterStatus" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;min-width:140px;">
                <option value="">همه وضعیت‌ها</option>
                <option value="draft">پیش‌نویس</option>
                <option value="active">فعال</option>
                <option value="expired">منقضی</option>
                <option value="cancelled">لغو شده</option>
            </select>
            <button class="fin-btn blue" onclick="loadContracts(1)">🔍 جستجو</button>
            <button class="fin-btn" style="background:#f1f5f9;color:#374151;" onclick="resetFilters()">🔄 بازنشانی</button>
        </div>
    </div>

    <!-- جدول قراردادها -->
    <div class="fin-panel" style="padding:0;overflow:hidden;">
        <div style="overflow-x:auto;">
            <table class="fin-table" id="contractsTable">
                <thead>
                    <tr>
                        <th>شماره</th>
                        <th>عنوان قرارداد</th>
                        <th>طرف حساب</th>
                        <th>تاریخ شروع</th>
                        <th>تاریخ پایان</th>
                        <th>مبلغ (ریال)</th>
                        <th>پرداخت‌شده</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody id="contractsBody">
                    <tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;">در حال بارگذاری...</td></tr>
                </tbody>
            </table>
        </div>
        <!-- صفحه‌بندی -->
        <div id="pagination" style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid #f1f5f9;background:#fafafa;font-size:0.85rem;color:#64748b;"></div>
    </div>

</div><!-- /main content inner -->
</div><!-- /main-content -->

<!-- ==================== Drawer ==================== -->
<div class="fin-drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="fin-drawer" id="contractDrawer" style="width:520px;">
    <div class="fin-drawer-header">
        <span id="drawerTitle">قرارداد جدید</span>
        <button class="fin-drawer-close" onclick="closeDrawer()">✕</button>
    </div>
    <div class="fin-drawer-body" style="padding:24px;">
        <form id="contractForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" id="drawerCsrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
            <input type="hidden" name="id" id="fId" value="0">

            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">عنوان قرارداد <span style="color:#e11d48">*</span></label>
                <input type="text" name="title" id="fTitle" required
                       style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;box-sizing:border-box;">
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">طرف حساب</label>
                <div style="position:relative;">
                    <input type="text" id="fPersonSearch" placeholder="جستجوی نام یا شرکت..."
                           style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;box-sizing:border-box;">
                    <input type="hidden" name="person_id" id="fPersonId" value="0">
                    <div id="personDropdown" style="display:none;position:absolute;top:100%;right:0;left:0;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);z-index:200;max-height:200px;overflow-y:auto;"></div>
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">نام مشتری (در صورت عدم انتخاب طرف حساب)</label>
                <input type="text" name="customer_name" id="fCustName"
                       style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;box-sizing:border-box;">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">تاریخ شروع</label>
                    <input type="text" name="start_date" id="fStartDate" placeholder="۱۴۰۳/۰۱/۰۱"
                           style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">تاریخ پایان</label>
                    <input type="text" name="end_date" id="fEndDate" placeholder="۱۴۰۴/۱۲/۲۹"
                           style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;box-sizing:border-box;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">مبلغ قرارداد (ریال)</label>
                    <input type="text" name="amount" id="fAmount" placeholder="0"
                           style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">مبلغ پرداخت‌شده (ریال)</label>
                    <input type="text" name="paid_amount" id="fPaid" placeholder="0"
                           style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;box-sizing:border-box;">
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">وضعیت</label>
                <select name="status" id="fStatus"
                        style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;">
                    <option value="draft">پیش‌نویس</option>
                    <option value="active">فعال</option>
                    <option value="expired">منقضی</option>
                    <option value="cancelled">لغو شده</option>
                </select>
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">توضیحات</label>
                <textarea name="description" id="fDesc" rows="3"
                          style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;resize:vertical;box-sizing:border-box;"></textarea>
            </div>

            <div style="margin-bottom:24px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.9rem;">فایل پیوست (PDF، Word، تصویر)</label>
                <input type="file" name="contract_file" id="fFile" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                       style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,Tahoma,sans-serif;box-sizing:border-box;">
                <div id="fCurrentFile" style="margin-top:6px;font-size:0.82rem;color:#6b7280;"></div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" class="fin-btn" style="background:#f1f5f9;color:#374151;" onclick="closeDrawer()">انصراف</button>
                <button type="submit" class="fin-btn blue" id="btnSaveContract">💾 ذخیره قرارداد</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    var csrfToken = '<?= addslashes($csrfToken) ?>';
    var currentPage = 1;

    // ---- بارگذاری لیست ----
    function loadContracts(page) {
        currentPage = page || 1;
        var search = $('#filterSearch').val();
        var status = $('#filterStatus').val();
        $('#contractsBody').html('<tr><td colspan="9" style="text-align:center;padding:30px;color:#94a3b8;">در حال بارگذاری...</td></tr>');

        $.post('contracts.php', {
            action: 'list',
            page: currentPage,
            search: search,
            status: status
        }, function (res) {
            if (!res.ok) { showMsg(res.msg, 'error'); return; }
            renderTable(res.rows);
            renderPagination(res.pagination);
            updateStats(res.rows, res.pagination.total_rows);
        }, 'json').fail(function () { showMsg('خطا در ارتباط با سرور', 'error'); });
    }

    function renderTable(rows) {
        if (!rows.length) {
            $('#contractsBody').html('<tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;">هیچ قراردادی یافت نشد.</td></tr>');
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            var expiringBadge = r.expiring_soon
                ? '<span class="fin-badge amber" style="margin-right:4px;font-size:0.7rem;">⚠ در حال انقضا</span>'
                : '';
            var fileLink = r.file_path
                ? '<a href="../../' + r.file_path + '" target="_blank" title="دانلود فایل" style="color:#3b82f6;text-decoration:none;">📎</a> '
                : '';
            html += '<tr>' +
                '<td><code style="font-size:0.82rem;background:#f1f5f9;padding:2px 6px;border-radius:4px;">' + r.contract_number + '</code></td>' +
                '<td>' + escHtml(r.title) + expiringBadge + '</td>' +
                '<td>' + escHtml(r.person_name || '—') + '</td>' +
                '<td style="direction:ltr;text-align:right;">' + r.start_jalali + '</td>' +
                '<td style="direction:ltr;text-align:right;">' + r.end_jalali + '</td>' +
                '<td style="text-align:left;direction:ltr;">' + r.amount_fmt + '</td>' +
                '<td style="text-align:left;direction:ltr;">' + r.paid_fmt + '</td>' +
                '<td><span class="fin-badge ' + r.status_class + '">' + r.status_label + '</span></td>' +
                '<td>' +
                    fileLink +
                    '<button class="fin-btn" style="padding:4px 10px;font-size:0.8rem;background:#eff6ff;color:#2563eb;" onclick="editContract(' + r.id + ')">✏ ویرایش</button> ' +
                    '<button class="fin-btn" style="padding:4px 10px;font-size:0.8rem;background:#fff1f2;color:#e11d48;" onclick="deleteContract(' + r.id + ')">🗑 حذف</button>' +
                '</td>' +
                '</tr>';
        });
        $('#contractsBody').html(html);
    }

    function renderPagination(p) {
        if (p.total_pages <= 1) { $('#pagination').html(''); return; }
        var html = '<span>' + p.total_rows + ' قرارداد</span>';
        html += '<div style="display:flex;gap:4px;">';
        if (p.page > 1) html += '<button class="fin-btn" style="padding:4px 10px;font-size:0.8rem;" onclick="loadContracts(' + (p.page - 1) + ')">‹ قبلی</button>';
        html += '<span style="padding:4px 10px;background:#e2e8f0;border-radius:6px;">صفحه ' + p.page + ' از ' + p.total_pages + '</span>';
        if (p.page < p.total_pages) html += '<button class="fin-btn blue" style="padding:4px 10px;font-size:0.8rem;" onclick="loadContracts(' + (p.page + 1) + ')">بعدی ›</button>';
        html += '</div>';
        $('#pagination').html(html);
    }

    function updateStats(rows, total) {
        $('#statTotal').text(total);
        var active = 0, expiring = 0, expired = 0;
        rows.forEach(function (r) {
            if (r.status === 'active') active++;
            if (r.expiring_soon) expiring++;
            if (r.status === 'expired') expired++;
        });
        $('#statActive').text(active);
        $('#statExpiring').text(expiring);
        $('#statExpired').text(expired);
    }

    // ---- Drawer ----
    window.openDrawer = function (id) {
        $('#fId').val(0);
        $('#contractForm')[0].reset();
        $('#fPersonId').val(0);
        $('#fPersonSearch').val('');
        $('#fCurrentFile').html('');
        $('#drawerTitle').text('قرارداد جدید');
        $('#drawerOverlay').addClass('active');
        $('#contractDrawer').addClass('open');
    };

    window.editContract = function (id) {
        $.post('contracts.php', { action: 'load', id: id }, function (res) {
            if (!res.ok) { showMsg(res.msg, 'error'); return; }
            var d = res.data;
            $('#drawerTitle').text('ویرایش قرارداد');
            $('#fId').val(d.id);
            $('#fTitle').val(d.title);
            $('#fPersonId').val(d.person_id || 0);
            $('#fPersonSearch').val(d.person_label || '');
            $('#fCustName').val(d.customer_name || '');
            $('#fStartDate').val(d.start_jalali || '');
            $('#fEndDate').val(d.end_jalali || '');
            $('#fAmount').val(d.amount || 0);
            $('#fPaid').val(d.paid_amount || 0);
            $('#fStatus').val(d.status || 'draft');
            $('#fDesc').val(d.description || '');
            if (d.file_path) {
                $('#fCurrentFile').html('فایل فعلی: <a href="../../' + d.file_path + '" target="_blank">' + d.file_path + '</a>');
            } else {
                $('#fCurrentFile').html('');
            }
            $('#drawerOverlay').addClass('active');
            $('#contractDrawer').addClass('open');
        }, 'json');
    };

    window.closeDrawer = function () {
        $('#drawerOverlay').removeClass('active');
        $('#contractDrawer').removeClass('open');
    };

    // ---- ذخیره فرم ----
    $('#contractForm').on('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(this);
        fd.set('csrf_token', csrfToken);
        $('#btnSaveContract').prop('disabled', true).text('در حال ذخیره...');
        $.ajax({
            url: 'contracts.php',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                $('#btnSaveContract').prop('disabled', false).text('💾 ذخیره قرارداد');
                if (res.ok) {
                    showMsg(res.msg, 'success');
                    closeDrawer();
                    loadContracts(currentPage);
                } else {
                    showMsg(res.msg, 'error');
                }
            },
            error: function () {
                $('#btnSaveContract').prop('disabled', false).text('💾 ذخیره قرارداد');
                showMsg('خطا در ارتباط با سرور', 'error');
            }
        });
    });

    // ---- حذف ----
    window.deleteContract = function (id) {
        if (!confirm('آیا از حذف این قرارداد اطمینان دارید؟')) return;
        $.post('contracts.php', { action: 'delete', id: id, csrf_token: csrfToken },
            function (res) {
                showMsg(res.msg, res.ok ? 'success' : 'error');
                if (res.ok) loadContracts(currentPage);
            }, 'json');
    };

    // ---- بازنشانی فیلتر ----
    window.resetFilters = function () {
        $('#filterSearch').val('');
        $('#filterStatus').val('');
        loadContracts(1);
    };

    // ---- Autocomplete طرف حساب ----
    var personTimer;
    $('#fPersonSearch').on('input', function () {
        var q = $(this).val().trim();
        clearTimeout(personTimer);
        if (q.length < 1) { $('#personDropdown').hide(); return; }
        personTimer = setTimeout(function () {
            $.post('contracts.php', { action: 'search_persons', q: q }, function (res) {
                if (!res.ok || !res.items.length) { $('#personDropdown').hide(); return; }
                var html = '';
                res.items.forEach(function (item) {
                    html += '<div class="person-option" data-id="' + item.id + '" data-label="' + escHtml(item.label) + '" ' +
                            'style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:0.88rem;" ' +
                            'onmouseenter="this.style.background=\'#f0f9ff\'" onmouseleave="this.style.background=\'\'">' +
                            escHtml(item.label) + '</div>';
                });
                $('#personDropdown').html(html).show();
            }, 'json');
        }, 300);
    });

    $(document).on('click', '.person-option', function () {
        var id    = $(this).data('id');
        var label = $(this).data('label');
        $('#fPersonId').val(id);
        $('#fPersonSearch').val(label);
        $('#personDropdown').hide();
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#fPersonSearch, #personDropdown').length) {
            $('#personDropdown').hide();
        }
    });

    // ---- Enter در فیلتر جستجو ----
    $('#filterSearch').on('keydown', function (e) {
        if (e.key === 'Enter') loadContracts(1);
    });

    // ---- توابع کمکی ----
    window.showMsg = function (msg, type) {
        var bg = type === 'success' ? '#22c55e' : '#ef4444';
        var el = $('<div>').text(msg).css({
            position: 'fixed', top: '20px', left: '50%', transform: 'translateX(-50%)',
            background: bg, color: '#fff', padding: '10px 24px', borderRadius: '8px',
            fontFamily: 'Vazirmatn,Tahoma,sans-serif', fontSize: '0.95rem', zIndex: 9999,
            boxShadow: '0 4px 12px rgba(0,0,0,0.15)'
        }).appendTo('body');
        setTimeout(function () { el.fadeOut(300, function () { el.remove(); }); }, 3000);
    };

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ---- بارگذاری اولیه ----
    loadContracts(1);

})();
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
