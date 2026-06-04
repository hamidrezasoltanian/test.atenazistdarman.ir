<?php
/*
 * فایل: public_html/admin/fin_invoice_sell.php
 * توضیحات: فاکتور فروش — ثبت پیش‌نویس، تأیید با سند حسابداری دوطرفه، ویرایش، حذف نرم
 * منطق حسابداری: سبک hesabix
 *   بدهکار:  ۳   (حساب دریافتنی / طرف حساب مشتری)   = مبلغ نهایی
 *   بستانکار: ۵۳  (فروش کالا)                         = جمع خالص هر ردیف
 *   بستانکار: ۱۰۴ (تخفیفات)                           = کل تخفیف   (اگر > ۰)
 *   بستانکار: مالیات پرداختنی                          = کل مالیات   (اگر > ۰)
 *   بستانکار: ۶۱  (حمل)                               = هزینه حمل   (اگر > ۰)
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
        $finRoles = ['admin','management','manager','finance_manager','finance_expert','accountant'];
        if (in_array($roleName, $finRoles) || $roleData['is_system'] == 1) {
            $hasAccess = true;
        } else {
            $perms = json_decode($roleData['permissions'] ?? '[]', true) ?? [];
            if (in_array('fin_invoices', $perms) || in_array('all', $perms)) $hasAccess = true;
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

// ---- اطمینان از وجود جداول ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_persons` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `code` VARCHAR(30) DEFAULT NULL,
        `name` VARCHAR(150) NOT NULL,
        `nikename` VARCHAR(100) DEFAULT NULL,
        `company_name` VARCHAR(200) DEFAULT NULL,
        `type` ENUM('customer','supplier','both') DEFAULT 'customer',
        `mobile` VARCHAR(30) DEFAULT NULL,
        `phone` VARCHAR(30) DEFAULT NULL,
        `codeeghtesadi` VARCHAR(30) DEFAULT NULL,
        `shenasemeli` VARCHAR(20) DEFAULT NULL,
        `address` TEXT DEFAULT NULL,
        `credit_limit` DECIMAL(20,0) DEFAULT 0,
        `notes` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted` TINYINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_invoices` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_number` VARCHAR(30) NOT NULL,
        `invoice_date` DATE NOT NULL,
        `due_date` DATE DEFAULT NULL,
        `type` ENUM('sell','buy') DEFAULT 'sell',
        `person_id` INT NOT NULL,
        `customer_name` VARCHAR(200) DEFAULT NULL,
        `subtotal` DECIMAL(20,0) DEFAULT 0,
        `discount` DECIMAL(20,0) DEFAULT 0,
        `tax` DECIMAL(20,0) DEFAULT 0,
        `shipping` DECIMAL(20,0) DEFAULT 0,
        `total_amount` DECIMAL(20,0) DEFAULT 0,
        `paid_amount` DECIMAL(20,0) DEFAULT 0,
        `status` ENUM('draft','confirmed','paid','partial','cancelled') DEFAULT 'draft',
        `notes` TEXT DEFAULT NULL,
        `opportunity_id` INT DEFAULT NULL,
        `fiscal_year_id` INT DEFAULT NULL,
        `fin_doc_id` INT DEFAULT NULL,
        `created_by` INT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted` TINYINT DEFAULT 0,
        UNIQUE KEY `uq_inv_number` (`invoice_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_invoice_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_id` INT NOT NULL,
        `stuff_id` INT DEFAULT NULL,
        `description` VARCHAR(500) NOT NULL,
        `unit` VARCHAR(30) DEFAULT NULL,
        `qty` DECIMAL(12,4) NOT NULL DEFAULT 1,
        `unit_price` DECIMAL(20,0) NOT NULL DEFAULT 0,
        `discount_pct` DECIMAL(5,2) DEFAULT 0,
        `discount_amt` DECIMAL(20,0) DEFAULT 0,
        `tax_pct` DECIMAL(5,2) DEFAULT 9,
        `tax_amt` DECIMAL(20,0) DEFAULT 0,
        `total` DECIMAL(20,0) NOT NULL DEFAULT 0,
        `row_order` INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_docs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `doc_number` VARCHAR(30) DEFAULT NULL,
        `doc_date` DATE NOT NULL,
        `type` VARCHAR(50) DEFAULT 'manual',
        `tax_percent` DECIMAL(5,2) DEFAULT 0,
        `description` TEXT DEFAULT NULL,
        `discount_type` VARCHAR(20) DEFAULT NULL,
        `discount_percent` DECIMAL(5,2) DEFAULT 0,
        `fiscal_year_id` INT DEFAULT NULL,
        `ref_id` INT DEFAULT NULL,
        `ref_type` VARCHAR(50) DEFAULT NULL,
        `short_link` VARCHAR(100) DEFAULT NULL,
        `created_by` INT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `is_deleted` TINYINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_doc_rows` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `doc_id` INT NOT NULL,
        `account_id` INT DEFAULT NULL,
        `bd` DECIMAL(20,0) DEFAULT 0,
        `bs` DECIMAL(20,0) DEFAULT 0,
        `description` VARCHAR(500) DEFAULT NULL,
        `person_id` INT DEFAULT NULL,
        `commodity_id` INT DEFAULT NULL,
        `commodity_count` DECIMAL(12,4) DEFAULT NULL,
        `cashdesk_id` INT DEFAULT NULL,
        `bank_id` INT DEFAULT NULL,
        `row_discount` DECIMAL(20,0) DEFAULT 0,
        `row_tax` DECIMAL(20,0) DEFAULT 0,
        `row_order` INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_chart_of_accounts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `upper_id` INT DEFAULT NULL,
        `name` VARCHAR(200) NOT NULL,
        `type` VARCHAR(50) DEFAULT NULL,
        `code` VARCHAR(20) NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `is_deleted` TINYINT DEFAULT 0,
        UNIQUE KEY `uq_acc_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // حساب‌های پایه hesabix اگر وجود نداشتند
    $seedAccounts = [
        ['3',   'حساب دریافتنی'],
        ['53',  'فروش کالا'],
        ['61',  'درآمد حمل کالا'],
        ['104', 'تخفیف فروش'],
        ['120', 'موجودی کالا'],
        ['8',   'حساب پرداختنی'],
        ['90',  'هزینه حمل کالا'],
        ['51',  'تخفیفات نقدی خرید'],
        ['121', 'صندوق'],
        ['122', 'تنخواه گردان'],
        ['2103','مالیات ارزش افزوده پرداختنی'],
    ];
    $insAcc = $pdo->prepare('INSERT IGNORE INTO fin_chart_of_accounts (code, name, created_at) VALUES (?, ?, NOW())');
    foreach ($seedAccounts as $a) $insAcc->execute([$a[0], $a[1]]);

} catch (Throwable $e) { /* جداول از قبل وجود داشتند */ }

$fiscalYear   = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? (int)$fiscalYear['id'] : null;

// ---- تابع کمکی: شناسه حساب بر اساس کد ----
function accIdByCode($pdo, $code) {
    static $cache = [];
    if (isset($cache[$code])) return $cache[$code];
    $stmt = $pdo->prepare('SELECT id FROM fin_chart_of_accounts WHERE code = ? AND is_deleted = 0 LIMIT 1');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    $cache[$code] = $id ? (int)$id : null;
    return $cache[$code];
}

// ---- شماره‌گذاری خودکار فاکتور ----
function nextInvoiceNumber($pdo, $type = 'sell') {
    $prefix = $type === 'sell' ? 'S' : 'B';
    $year   = (int)date('Y');
    try {
        $last = $pdo->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED))
             FROM fin_invoices WHERE invoice_number LIKE '{$prefix}-{$year}-%'"
        )->fetchColumn();
    } catch (Throwable $e) { $last = 0; }
    $seq = (int)$last + 1;
    return $prefix . '-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ===========================================================
// پردازش درخواست‌های AJAX
// ===========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action'] ?? '');

    try {

        // ---- لیست فاکتورها ----
        if ($action === 'list') {
            $page     = max(1, (int)($_POST['page'] ?? 1));
            $perPage  = 20;
            $offset   = ($page - 1) * $perPage;
            $dfrom    = trim($_POST['date_from'] ?? '');
            $dto      = trim($_POST['date_to']   ?? '');
            $pid      = (int)($_POST['person_id'] ?? 0);
            $status   = trim($_POST['status']    ?? '');

            $where  = ["i.is_deleted = 0", "i.type = 'sell'"];
            $params = [];

            if ($dfrom)  { $where[] = 'i.invoice_date >= ?'; $params[] = $dfrom; }
            if ($dto)    { $where[] = 'i.invoice_date <= ?'; $params[] = $dto; }
            if ($pid)    { $where[] = 'i.person_id = ?';    $params[] = $pid; }
            if ($status) { $where[] = 'i.status = ?';       $params[] = $status; }

            $ws = implode(' AND ', $where);

            $stS = $pdo->prepare(
                "SELECT COUNT(*) cnt, COALESCE(SUM(i.total_amount),0) ts,
                        COALESCE(SUM(i.paid_amount),0) ps
                 FROM fin_invoices i WHERE $ws"
            );
            $stS->execute($params);
            $stats = $stS->fetch();

            $stL = $pdo->prepare(
                "SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
                        i.subtotal, i.discount, i.tax, i.shipping,
                        i.total_amount, i.paid_amount, i.status, i.fin_doc_id,
                        COALESCE(i.invoice_type,'official') AS invoice_type,
                        (i.total_amount - i.paid_amount) AS remaining,
                        COALESCE(p.company_name, p.name, i.customer_name) AS person_name
                 FROM fin_invoices i
                 LEFT JOIN fin_persons p ON p.id = i.person_id
                 WHERE $ws
                 ORDER BY i.id DESC
                 LIMIT $perPage OFFSET $offset"
            );
            $stL->execute($params);
            $rows = $stL->fetchAll(PDO::FETCH_ASSOC);

            $statusLabels = [
                'draft'     => ['label' => 'پیش‌نویس',     'cls' => 'draft'],
                'confirmed' => ['label' => 'تأیید شده',    'cls' => 'confirmed'],
                'paid'      => ['label' => 'پرداخت کامل', 'cls' => 'paid'],
                'partial'   => ['label' => 'پرداخت ناقص', 'cls' => 'partial'],
                'cancelled' => ['label' => 'لغو شده',      'cls' => 'cancelled'],
            ];

            foreach ($rows as &$r) {
                $r['total_fmt']   = number_format((int)$r['total_amount']);
                $r['paid_fmt']    = number_format((int)$r['paid_amount']);
                $r['remain_fmt']  = number_format(max(0, (int)$r['remaining']));
                $r['date_jalali'] = jdate('Y/m/d', $r['invoice_date']);
                $sl = $statusLabels[$r['status']] ?? ['label' => $r['status'], 'cls' => 'draft'];
                $r['status_label'] = $sl['label'];
                $r['status_class'] = $sl['cls'];
            }
            unset($r);

            $totalPages = max(1, (int)ceil($stats['cnt'] / $perPage));

            echo json_encode([
                'ok'    => true,
                'rows'  => $rows,
                'stats' => [
                    'count'     => (int)$stats['cnt'],
                    'total_sum' => number_format((int)$stats['ts']),
                    'paid_sum'  => number_format((int)$stats['ps']),
                    'remain'    => number_format(max(0, (int)$stats['ts'] - (int)$stats['ps'])),
                ],
                'pagination' => [
                    'page'        => $page,
                    'total_pages' => $totalPages,
                    'total_rows'  => (int)$stats['cnt'],
                ],
            ]);
            exit;
        }

        // ---- جستجوی طرف حساب ----
        if ($action === 'search_persons') {
            $q = '%' . trim($_POST['q'] ?? '') . '%';
            $stmt = $pdo->prepare(
                "SELECT id,
                        CONCAT(COALESCE(company_name,''), IF(company_name IS NOT NULL AND company_name != '' AND name != '', ' — ', ''), name) AS display_name
                 FROM fin_persons
                 WHERE is_deleted = 0
                   AND type IN ('customer','both')
                   AND (name LIKE ? OR company_name LIKE ? OR mobile LIKE ?)
                 ORDER BY name LIMIT 20"
            );
            $stmt->execute([$q, $q, $q]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'results' => $results]);
            exit;
        }

        // ---- جستجوی کالا ----
        if ($action === 'search_stuffs') {
            $q = '%' . trim($_POST['q'] ?? '') . '%';
            try {
                $stmt = $pdo->prepare(
                    "SELECT s.id, s.name, s.code,
                            COALESCE(spl.price_sell, s.price_sell, 0) AS price,
                            COALESCE(s.unit,'') AS unit
                     FROM stuffs s
                     LEFT JOIN stuff_price_list spl ON spl.stuff_id = s.id
                     WHERE s.is_deleted = 0 AND (s.name LIKE ? OR s.code LIKE ?)
                     ORDER BY s.name LIMIT 20"
                );
                $stmt->execute([$q, $q]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                try {
                    $stmt2 = $pdo->prepare("SELECT id, name, '' AS code, 0 AS price, '' AS unit FROM stuffs WHERE is_deleted = 0 AND name LIKE ? LIMIT 20");
                    $stmt2->execute([$q]);
                    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e2) { $rows = []; }
            }
            echo json_encode(['ok' => true, 'results' => $rows]);
            exit;
        }

        // ---- قیمت یک کالا ----
        if ($action === 'get_stuff_price') {
            $sid = (int)($_POST['stuff_id'] ?? 0);
            if (!$sid) { echo json_encode(['ok' => false]); exit; }
            try {
                $p = $pdo->prepare(
                    "SELECT COALESCE(spl.price_sell, s.price_sell, 0) AS price_sell
                     FROM stuffs s LEFT JOIN stuff_price_list spl ON spl.stuff_id = s.id
                     WHERE s.id = ? LIMIT 1"
                );
                $p->execute([$sid]);
                $price = (int)$p->fetchColumn();
            } catch (Throwable $e) { $price = 0; }
            echo json_encode(['ok' => true, 'price' => $price]);
            exit;
        }

        // ---- ذخیره پیش‌نویس ----
        if ($action === 'save_draft') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'خطای امنیتی. صفحه را رفرش کنید.']);
                exit;
            }
            $result = saveInvoiceToDb($pdo, 'sell', 'draft', $userId, $fiscalYearId);
            echo json_encode($result);
            exit;
        }

        // ---- تأیید فاکتور + سند حسابداری ----
        if ($action === 'confirm_invoice') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'خطای امنیتی.']);
                exit;
            }
            $result = saveInvoiceToDb($pdo, 'sell', 'confirmed', $userId, $fiscalYearId);
            echo json_encode($result);
            exit;
        }

        // ---- دریافت یک فاکتور ----
        if ($action === 'get_one') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare(
                "SELECT i.*, COALESCE(p.company_name, p.name, i.customer_name) AS person_name
                 FROM fin_invoices i
                 LEFT JOIN fin_persons p ON p.id = i.person_id
                 WHERE i.id = ? AND i.is_deleted = 0 AND i.type = 'sell'"
            );
            $st->execute([$id]);
            $inv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$inv) { echo json_encode(['ok' => false, 'msg' => 'فاکتور یافت نشد.']); exit; }

            $stI = $pdo->prepare('SELECT * FROM fin_invoice_items WHERE invoice_id = ? ORDER BY row_order');
            $stI->execute([$id]);
            $inv['items']       = $stI->fetchAll(PDO::FETCH_ASSOC);
            $inv['date_jalali'] = jdate('Y/m/d', $inv['invoice_date']);
            echo json_encode(['ok' => true, 'invoice' => $inv]);
            exit;
        }

        // ---- حذف نرم ----
        if ($action === 'delete') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'خطای امنیتی.']);
                exit;
            }
            $id = (int)($_POST['id'] ?? 0);
            // فاکتور تأیید شده را نمی‌توان حذف کرد مگر ادمین
            $st = $pdo->prepare("SELECT status, created_by FROM fin_invoices WHERE id = ? AND is_deleted = 0");
            $st->execute([$id]);
            $inv = $st->fetch();
            if (!$inv) { echo json_encode(['ok' => false, 'msg' => 'فاکتور یافت نشد.']); exit; }
            if ($inv['status'] === 'confirmed' && strtolower($rawRole) !== 'admin' && $inv['created_by'] != $userId) {
                echo json_encode(['ok' => false, 'msg' => 'فاکتور تأیید شده را نمی‌توان حذف کرد.']);
                exit;
            }
            $pdo->prepare('UPDATE fin_invoices SET is_deleted = 1, updated_at = NOW() WHERE id = ?')->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'فاکتور حذف شد.']);
            exit;
        }

        // ---- لغو فاکتور ----
        if ($action === 'cancel_invoice') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'خطای امنیتی.']); exit;
            }
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE fin_invoices SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND type = 'sell'")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'فاکتور لغو شد.']);
            exit;
        }

        // ---- لیست طرف حساب‌ها برای فیلتر ----
        if ($action === 'get_person_list') {
            $stmt = $pdo->query("SELECT id, COALESCE(company_name, name) AS name FROM fin_persons WHERE is_deleted = 0 AND type IN ('customer','both') ORDER BY name LIMIT 100");
            echo json_encode(['ok' => true, 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'درخواست نامعتبر.']);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('fin_invoice_sell AJAX error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'خطای سرور: ' . $e->getMessage()]);
    }
    exit;
}

// ---- تابع مشترک ذخیره/تأیید فاکتور ----
function saveInvoiceToDb($pdo, $type, $targetStatus, $userId, $fiscalYearId) {
    $editId      = (int)($_POST['invoice_id']   ?? 0);
    $personId    = (int)($_POST['person_id']     ?? 0);
    $invDate     = trim(faToEn($_POST['invoice_date'] ?? ''));
    $dueDate     = trim(faToEn($_POST['due_date']     ?? '')) ?: null;
    $notes       = trim($_POST['notes'] ?? '');
    $shipping    = (int)str_replace([',', ' '], '', faToEn($_POST['shipping'] ?? '0'));
    $items       = $_POST['items'] ?? [];
    $invType     = in_array($_POST['invoice_type'] ?? '', ['official','adjustment'])
                   ? $_POST['invoice_type'] : 'official';
    // فاکتور تنظیمی: بدون مالیات
    $noTax = ($invType === 'adjustment');

    if (!$personId) return ['ok' => false, 'msg' => 'انتخاب طرف حساب الزامی است.'];
    if (empty($items)) return ['ok' => false, 'msg' => 'حداقل یک ردیف کالا الزامی است.'];
    if (!$invDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invDate))
        return ['ok' => false, 'msg' => 'فرمت تاریخ نادرست است.'];

    // نام طرف حساب
    $stPerson = $pdo->prepare('SELECT COALESCE(company_name, name) AS n FROM fin_persons WHERE id = ?');
    $stPerson->execute([$personId]);
    $personName = $stPerson->fetchColumn() ?: '';

    // محاسبه مبالغ
    $subtotal      = 0;
    $totalDiscount = 0;
    $totalTax      = 0;
    $cleanItems    = [];

    foreach ($items as $item) {
        $desc   = trim($item['description'] ?? '');
        if (!$desc) continue;
        $qty    = (float)faToEn($item['qty'] ?? 1);
        $price  = (int)str_replace([',', ' '], '', faToEn($item['unit_price'] ?? '0'));
        $discP  = (float)faToEn($item['discount_pct'] ?? '0');
        $taxP   = $noTax ? 0.0 : (float)faToEn($item['tax_pct'] ?? '9');
        $sid    = (int)($item['stuff_id'] ?? 0) ?: null;
        $unit   = trim($item['unit'] ?? '');

        $gross  = (int)round($qty * $price);
        $dAmt   = (int)round($gross * $discP / 100);
        $after  = $gross - $dAmt;
        $tAmt   = (int)round($after * $taxP / 100);
        $total  = $after + $tAmt;

        $subtotal      += $gross;
        $totalDiscount += $dAmt;
        $totalTax      += $tAmt;

        $cleanItems[] = [
            'stuff_id'     => $sid,
            'description'  => $desc,
            'unit'         => $unit,
            'qty'          => $qty,
            'unit_price'   => $price,
            'discount_pct' => $discP,
            'discount_amt' => $dAmt,
            'tax_pct'      => $taxP,
            'tax_amt'      => $tAmt,
            'total'        => $total,
        ];
    }

    if (empty($cleanItems)) return ['ok' => false, 'msg' => 'ردیف‌های معتبر وارد نشده.'];

    $netSales    = $subtotal - $totalDiscount; // فروش خالص بدون مالیات
    $totalAmount = $netSales + $totalTax + $shipping;

    $pdo->beginTransaction();

    if ($editId > 0) {
        // ویرایش — فقط پیش‌نویس قابل ویرایش است
        $chk = $pdo->prepare("SELECT status FROM fin_invoices WHERE id = ? AND is_deleted = 0 AND type = ?");
        $chk->execute([$editId, $type]);
        $curStatus = $chk->fetchColumn();
        if ($curStatus && $curStatus !== 'draft' && $targetStatus === 'draft') {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => 'فاکتور تأیید شده را نمی‌توان ویرایش کرد.'];
        }
        $pdo->prepare('DELETE FROM fin_invoice_items WHERE invoice_id = ?')->execute([$editId]);
        $pdo->prepare(
            "UPDATE fin_invoices SET person_id=?, customer_name=?, invoice_date=?, due_date=?,
                     subtotal=?, discount=?, tax=?, shipping=?, total_amount=?,
                     status=?, notes=?, invoice_type=?, updated_at=NOW()
             WHERE id=? AND is_deleted=0"
        )->execute([
            $personId, $personName, $invDate, $dueDate,
            $subtotal, $totalDiscount, $totalTax, $shipping, $totalAmount,
            $targetStatus, $notes, $invType, $editId,
        ]);
        $invoiceId     = $editId;
        $invNumStmt = $pdo->prepare("SELECT invoice_number FROM fin_invoices WHERE id=?");
        $invNumStmt->execute([$editId]);
        $invoiceNumber = $invNumStmt->fetchColumn();
    } else {
        $invoiceNumber = nextInvoiceNumber($pdo, $type);
        // اضافه‌کردن ستون invoice_type در صورت نیاز
        try { $pdo->exec("ALTER TABLE fin_invoices ADD COLUMN IF NOT EXISTS invoice_type ENUM('official','adjustment') NOT NULL DEFAULT 'official' AFTER type"); } catch (Throwable $e) {}
        $pdo->prepare(
            "INSERT INTO fin_invoices
                (invoice_number, invoice_date, due_date, type, invoice_type, person_id, customer_name,
                 subtotal, discount, tax, shipping, total_amount, paid_amount,
                 status, notes, fiscal_year_id, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,NOW())"
        )->execute([
            $invoiceNumber, $invDate, $dueDate, $type, $invType, $personId, $personName,
            $subtotal, $totalDiscount, $totalTax, $shipping, $totalAmount,
            $targetStatus, $notes, $fiscalYearId, $userId,
        ]);
        $invoiceId = (int)$pdo->lastInsertId();
    }

    // درج ردیف‌های فاکتور
    $stItem = $pdo->prepare(
        "INSERT INTO fin_invoice_items
            (invoice_id, stuff_id, description, unit, qty, unit_price,
             discount_pct, discount_amt, tax_pct, tax_amt, total, row_order)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ($cleanItems as $idx => $it) {
        $stItem->execute([
            $invoiceId, $it['stuff_id'], $it['description'], $it['unit'],
            $it['qty'], $it['unit_price'],
            $it['discount_pct'], $it['discount_amt'],
            $it['tax_pct'], $it['tax_amt'],
            $it['total'], $idx + 1,
        ]);
    }

    // ---- سند حسابداری دوطرفه فقط در صورت تأیید ----
    $docId = null;
    if ($targetStatus === 'confirmed') {
        $accReceivable = accIdByCode($pdo, '3');    // بدهکار: حساب دریافتنی
        $accSales      = accIdByCode($pdo, '53');   // بستانکار: فروش کالا
        $accDiscount   = accIdByCode($pdo, '104');  // بستانکار: تخفیف فروش (منفی = بدهکار)
        $accTax        = accIdByCode($pdo, '2103'); // بستانکار: مالیات
        $accShipping   = accIdByCode($pdo, '61');   // بستانکار: حمل

        $docType = $invType === 'adjustment' ? 'sell_invoice_adj' : 'sell_invoice';
        $docDesc = $invType === 'adjustment'
            ? 'فاکتور تنظیمی فروش شماره ' . $invoiceNumber
            : 'فاکتور رسمی فروش شماره '  . $invoiceNumber;
        $pdo->prepare(
            "INSERT INTO fin_docs (doc_number, doc_date, type, description, fiscal_year_id, ref_id, ref_type, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())"
        )->execute([
            $invoiceNumber, $invDate, $docType, $docDesc,
            $fiscalYearId, $invoiceId, 'fin_invoice', $userId,
        ]);
        $docId = (int)$pdo->lastInsertId();

        $stRow = $pdo->prepare(
            "INSERT INTO fin_doc_rows (doc_id, account_id, bd, bs, description, person_id, row_order)
             VALUES (?,?,?,?,?,?,?)"
        );
        $rowOrder = 1;

        // بدهکار — حساب دریافتنی = مبلغ کل
        $stRow->execute([$docId, $accReceivable, $totalAmount, 0, 'بدهکار — مطالبه از مشتری', $personId, $rowOrder++]);

        // بستانکار — فروش کالا = فروش خالص (بدون مالیات، با کسر تخفیف)
        if ($netSales > 0 && $accSales) {
            $stRow->execute([$docId, $accSales, 0, $netSales, 'بستانکار — فروش کالا/خدمت', null, $rowOrder++]);
        }

        // بستانکار — مالیات ارزش افزوده
        if ($totalTax > 0 && $accTax) {
            $stRow->execute([$docId, $accTax, 0, $totalTax, 'بستانکار — مالیات ارزش افزوده', null, $rowOrder++]);
        }

        // بستانکار — هزینه حمل
        if ($shipping > 0 && $accShipping) {
            $stRow->execute([$docId, $accShipping, 0, $shipping, 'بستانکار — هزینه حمل', null, $rowOrder++]);
        }

        // اگر تخفیف وجود دارد: بدهکار حساب تخفیف / بستانکار اضافی (اصلاح سند)
        // در مدل hesabix تخفیف از مبلغ فروش کسر می‌شود — نیازی به ردیف جداگانه نیست
        // (نتِ فروش = subtotal - discount قبلاً محاسبه شده)

        $pdo->prepare('UPDATE fin_invoices SET fin_doc_id=?, updated_at=NOW() WHERE id=?')->execute([$docId, $invoiceId]);
    }

    $pdo->commit();

    // ── ارسال SMS اتوماسیون پس از تأیید فاکتور ──
    if ($targetStatus === 'confirmed' && $type === 'sell') {
        try {
            // ساخت جدول‌های اتوماسیون اگر وجود نداشت
            $pdo->exec("CREATE TABLE IF NOT EXISTS `sms_automation_rules` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `event_type`       VARCHAR(60) NOT NULL,
                `name`             VARCHAR(200) NOT NULL,
                `message_template` TEXT NOT NULL,
                `is_active`        TINYINT(1) DEFAULT 1,
                `send_days_before` INT DEFAULT 0,
                `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `sms_automation_log` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `rule_id`        INT DEFAULT NULL,
                `event_type`     VARCHAR(50) NOT NULL,
                `phone`          VARCHAR(20) NOT NULL,
                `recipient_name` VARCHAR(200) DEFAULT NULL,
                `message`        TEXT NOT NULL,
                `status`         ENUM('sent','failed','pending') DEFAULT 'pending',
                `sent_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
                `error_msg`      VARCHAR(500) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // جستجوی قانون فعال برای رویداد تأیید فاکتور
            $ruleStmt = $pdo->prepare(
                "SELECT * FROM sms_automation_rules WHERE event_type='invoice_confirmed' AND is_active=1 LIMIT 1"
            );
            $ruleStmt->execute();
            $smsRule = $ruleStmt->fetch(PDO::FETCH_ASSOC);

            if ($smsRule) {
                // دریافت شماره تلفن مشتری
                $phoneStmt = $pdo->prepare(
                    "SELECT name, COALESCE(company_name, name) AS display_name, mobile, phone
                     FROM fin_persons WHERE id = ? LIMIT 1"
                );
                $phoneStmt->execute([$personId]);
                $personRow = $phoneStmt->fetch(PDO::FETCH_ASSOC);
                $smsPhone  = trim($personRow['mobile'] ?? $personRow['phone'] ?? '');
                $smsName   = $personRow['display_name'] ?? $personName;

                if ($smsPhone) {
                    // ساخت پیام از قالب
                    $todayJalali = jdate('Y/m/d');
                    $smsMsg = $smsRule['message_template'];
                    $smsMsg = str_replace('{نام}',    $smsName,                      $smsMsg);
                    $smsMsg = str_replace('{مبلغ}',   number_format((int)$totalAmount), $smsMsg);
                    $smsMsg = str_replace('{شماره}',  $invoiceNumber,                $smsMsg);
                    $smsMsg = str_replace('{تاریخ}',  $todayJalali,                  $smsMsg);

                    // ارسال SMS
                    $smsSent = false;
                    $smsError = null;
                    try {
                        // تابع sendFreeSms در fin_cheque_reminders.php تعریف شده؛
                        // اگر از آن فایل include نشده، به‌صورت مستقیم ارسال می‌کنیم
                        if (function_exists('sendFreeSms')) {
                            $smsSent = sendFreeSms($smsPhone, $smsMsg);
                        } else {
                            // ارسال مستقیم با IPPanel
                            $config  = require __DIR__ . '/../../Config/config.php';
                            $apiKey  = $config['sms_api_key'] ?? '';
                            $from    = $config['sms_from_number'] ?? '';
                            if ($apiKey) {
                                $mob = $smsPhone;
                                if (substr($mob, 0, 1) === '0') $mob = '+98' . substr($mob, 1);
                                $url  = 'https://api2.ippanel.com/api/v1/sms/send/webservice/single';
                                $body = json_encode(['sender' => $from, 'recipient' => $mob, 'message' => $smsMsg]);
                                $ch   = curl_init($url);
                                curl_setopt_array($ch, [
                                    CURLOPT_POST           => true,
                                    CURLOPT_POSTFIELDS     => $body,
                                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $apiKey],
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_TIMEOUT        => 5,
                                ]);
                                $smsRes  = curl_exec($ch);
                                $smsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                curl_close($ch);
                                $smsSent = ($smsCode >= 200 && $smsCode < 300);
                            }
                        }
                    } catch (Throwable $smsEx) {
                        $smsError = $smsEx->getMessage();
                    }

                    // لاگ ارسال
                    $pdo->prepare(
                        "INSERT INTO sms_automation_log
                            (rule_id, event_type, phone, recipient_name, message, status, error_msg, sent_at)
                         VALUES (?, 'invoice_confirmed', ?, ?, ?, ?, ?, NOW())"
                    )->execute([
                        $smsRule['id'],
                        $smsPhone,
                        $smsName,
                        $smsMsg,
                        $smsSent ? 'sent' : 'failed',
                        $smsError,
                    ]);
                }
            }
        } catch (Throwable $smsE) {
            // شکست SMS نباید روی ذخیره فاکتور تأثیر بگذارد
            error_log('SMS automation (invoice_confirmed) error: ' . $smsE->getMessage());
        }
    }

    // ── ارسال SMS برای رویداد دریافت کامل وجه (payment_received) ──
    // این بخش در fin_receive_pay.php هنگام ثبت دریافت از مشتری فعال می‌شود؛
    // اینجا وضعیت را بررسی می‌کنیم: اگر paid_amount >= total_amount شد
    if ($type === 'sell' && in_array($targetStatus, ['confirmed', 'draft'])) {
        try {
            $checkPaidStmt = $pdo->prepare(
                "SELECT paid_amount, total_amount, person_id, invoice_number, customer_name
                 FROM fin_invoices WHERE id = ? AND is_deleted = 0 LIMIT 1"
            );
            $checkPaidStmt->execute([$invoiceId]);
            $invCheck = $checkPaidStmt->fetch(PDO::FETCH_ASSOC);

            if ($invCheck && (int)$invCheck['paid_amount'] >= (int)$invCheck['total_amount'] && (int)$invCheck['total_amount'] > 0) {
                // جستجوی قانون SMS پرداخت کامل
                $pmtRuleStmt = $pdo->prepare(
                    "SELECT * FROM sms_automation_rules WHERE event_type='payment_received' AND is_active=1 LIMIT 1"
                );
                $pmtRuleStmt->execute();
                $pmtRule = $pmtRuleStmt->fetch(PDO::FETCH_ASSOC);

                if ($pmtRule) {
                    $pmtPhoneStmt = $pdo->prepare(
                        "SELECT COALESCE(company_name, name) AS display_name, mobile, phone
                         FROM fin_persons WHERE id = ? LIMIT 1"
                    );
                    $pmtPhoneStmt->execute([$invCheck['person_id']]);
                    $pmtPerson = $pmtPhoneStmt->fetch(PDO::FETCH_ASSOC);
                    $pmtPhone  = trim($pmtPerson['mobile'] ?? $pmtPerson['phone'] ?? '');
                    $pmtName   = $pmtPerson['display_name'] ?? ($invCheck['customer_name'] ?? '');

                    if ($pmtPhone) {
                        $todayJalali = jdate('Y/m/d');
                        $pmtMsg = $pmtRule['message_template'];
                        $pmtMsg = str_replace('{نام}',   $pmtName,                                 $pmtMsg);
                        $pmtMsg = str_replace('{مبلغ}',  number_format((int)$invCheck['paid_amount']), $pmtMsg);
                        $pmtMsg = str_replace('{شماره}', $invCheck['invoice_number'],               $pmtMsg);
                        $pmtMsg = str_replace('{تاریخ}', $todayJalali,                              $pmtMsg);

                        $pmtSent  = false;
                        $pmtError = null;
                        try {
                            if (function_exists('sendFreeSms')) {
                                $pmtSent = sendFreeSms($pmtPhone, $pmtMsg);
                            } else {
                                $config2 = require __DIR__ . '/../../Config/config.php';
                                $apiKey2 = $config2['sms_api_key'] ?? '';
                                $from2   = $config2['sms_from_number'] ?? '';
                                if ($apiKey2) {
                                    $mob2 = $pmtPhone;
                                    if (substr($mob2, 0, 1) === '0') $mob2 = '+98' . substr($mob2, 1);
                                    $url2  = 'https://api2.ippanel.com/api/v1/sms/send/webservice/single';
                                    $body2 = json_encode(['sender' => $from2, 'recipient' => $mob2, 'message' => $pmtMsg]);
                                    $ch2   = curl_init($url2);
                                    curl_setopt_array($ch2, [
                                        CURLOPT_POST           => true,
                                        CURLOPT_POSTFIELDS     => $body2,
                                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $apiKey2],
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_TIMEOUT        => 5,
                                    ]);
                                    $res2  = curl_exec($ch2);
                                    $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                                    curl_close($ch2);
                                    $pmtSent = ($code2 >= 200 && $code2 < 300);
                                }
                            }
                        } catch (Throwable $pmtEx) {
                            $pmtError = $pmtEx->getMessage();
                        }

                        $pdo->prepare(
                            "INSERT INTO sms_automation_log
                                (rule_id, event_type, phone, recipient_name, message, status, error_msg, sent_at)
                             VALUES (?, 'payment_received', ?, ?, ?, ?, ?, NOW())"
                        )->execute([
                            $pmtRule['id'],
                            $pmtPhone,
                            $pmtName,
                            $pmtMsg,
                            $pmtSent ? 'sent' : 'failed',
                            $pmtError,
                        ]);
                    }
                }
            }
        } catch (Throwable $pmtE) {
            error_log('SMS automation (payment_received) error: ' . $pmtE->getMessage());
        }
    }

    // ── ثبت خودکار پورسانت هنگام تأیید فاکتور فروش ──
    if ($targetStatus === 'confirmed' && $type === 'sell') {
        try {
            $plan = $pdo->query("SELECT * FROM hr_commission_plans WHERE is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($plan) {
                $invYear    = (int)date('Y', strtotime($invDate));
                $invMonth   = (int)date('n', strtotime($invDate));
                $invQuarter = (int)ceil($invMonth / 3);

                $kpiStmt = $pdo->prepare("SELECT score FROM hr_kpi_scores WHERE user_id=? AND year=? AND quarter=?");
                $kpiStmt->execute([$userId, $invYear, $invQuarter]);
                $kpiScore = (float)($kpiStmt->fetchColumn() ?: 0);
                $stepRate = $kpiScore >= (float)$plan['kpi_min_score']
                    ? (float)$plan['kpi_step_rate'] : (float)$plan['step_rate'];

                $cumStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(net_base),0) FROM hr_commissions
                     WHERE user_id=? AND year=? AND month=? AND status!='cancelled'"
                );
                $cumStmt->execute([$userId, $invYear, $invMonth]);
                $cumulativeBefore = (int)$cumStmt->fetchColumn();

                $netBase         = $totalAmount;
                $cumulativeAfter  = $cumulativeBefore + $netBase;
                $threshold       = (int)$plan['threshold'];
                $stepSize        = (int)$plan['step_size'];
                $baseRate        = (float)$plan['base_rate'];

                if ($cumulativeAfter <= $threshold || $stepSize <= 0) {
                    $commRate = $baseRate;
                } else {
                    $steps    = (int)floor(($cumulativeAfter - $threshold) / $stepSize);
                    $commRate = $baseRate + $steps * $stepRate;
                }
                $commAmount = (int)round($netBase * $commRate / 100);

                $pdo->prepare(
                    "INSERT INTO hr_commissions
                        (user_id,invoice_id,year,month,invoice_amount,extra_discount,net_base,
                         cumulative_before,commission_rate,commission_amount,status,created_at)
                     VALUES (?,?,?,?,?,0,?,?,?,?,'pending',NOW())
                     ON DUPLICATE KEY UPDATE
                        invoice_amount=VALUES(invoice_amount),net_base=VALUES(net_base),
                        cumulative_before=VALUES(cumulative_before),commission_rate=VALUES(commission_rate),
                        commission_amount=VALUES(commission_amount),status='pending',updated_at=NOW()"
                )->execute([
                    $userId, $invoiceId, $invYear, $invMonth,
                    $totalAmount, $netBase, $cumulativeBefore, $commRate, $commAmount,
                ]);
            }
        } catch (Throwable $e) { /* پورسانت اختیاری است */ }
    }

    // ── حواله خودکار انبار پس از تأیید فاکتور ──
    if ($targetStatus === 'confirmed') {
        try {
            $hasStuffs = false;
            foreach ($cleanItems as $item) { if (!empty($item['stuff_id'])) { $hasStuffs = true; break; } }
            if ($hasStuffs) {
                $storoomId = $pdo->query("SELECT id FROM inv_storerooms WHERE is_active=1 AND is_deleted=0 ORDER BY id LIMIT 1")->fetchColumn();
                if ($storoomId) {
                    $ticketSeq = $pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM inv_tickets")->fetchColumn();
                    $ticketNum = 'DS-'.date('Y').'-'.str_pad($ticketSeq, 4, '0', STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO inv_tickets (ticket_number,type,ticket_date,storeroom_id,person_id,ref_type,ref_id,status,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$ticketNum,'dispatch',date('Y-m-d'),$storoomId,$personId,'invoice',$invoiceId,'confirmed',$userId]);
                    $ticketId = (int)$pdo->lastInsertId();
                    foreach ($cleanItems as $item) {
                        if (empty($item['stuff_id'])) continue;
                        $pdo->prepare("INSERT INTO inv_ticket_items (ticket_id,stuff_id,qty,unit_price,total) VALUES (?,?,?,?,?)")
                            ->execute([$ticketId,$item['stuff_id'],$item['qty'],$item['unit_price'],$item['total']]);
                        $pdo->prepare("UPDATE stuff_price_list SET total_inventory=total_inventory-? WHERE stuff_id=?")
                            ->execute([$item['qty'],$item['stuff_id']]);
                    }
                }
            }
        } catch (Throwable $e) { /* حواله انبار اختیاری است */ }
    }

    return [
        'ok'             => true,
        'invoice_id'     => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'status'         => $targetStatus,
        'doc_id'         => $docId,
        'msg'            => $targetStatus === 'confirmed'
            ? 'فاکتور تأیید شد، سند حسابداری و حواله انبار ثبت گردید.'
            : 'پیش‌نویس فاکتور ذخیره شد.',
    ];
}

// ===========================================================
// رندر HTML
// ===========================================================
$basePath  = '../../';
$pageTitle = 'فاکتور فروش';
$csrfToken = csrf_token();

// آمار اولیه
$statsInit = ['count' => 0, 'total_sum' => '0', 'paid_sum' => '0', 'remain' => '0'];
try {
    $s = $pdo->query(
        "SELECT COUNT(*) cnt, COALESCE(SUM(total_amount),0) ts, COALESCE(SUM(paid_amount),0) ps
         FROM fin_invoices WHERE is_deleted=0 AND type='sell'"
    )->fetch();
    $statsInit = [
        'count'     => (int)$s['cnt'],
        'total_sum' => number_format((int)$s['ts']),
        'paid_sum'  => number_format((int)$s['ps']),
        'remain'    => number_format(max(0, (int)$s['ts'] - (int)$s['ps'])),
    ];
} catch (Throwable $e) {}

$extraCss = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';
require_once __DIR__ . '/../../templates/header.php';
?>
<style>
/* ===== اختصاصی صفحه فاکتور فروش ===== */
.inv-page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.inv-page-icon{width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff;box-shadow:0 6px 16px rgba(37,99,235,.35)}
.inv-page-title{display:flex;align-items:center;gap:12px}
.inv-page-title h1{font-size:1.25rem;font-weight:900;color:#1e293b;margin:0}
.inv-page-title p{font-size:0.78rem;color:#64748b;margin:3px 0 0}
.inv-form-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06)}
.inv-form-hdr{background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:18px 26px;display:flex;align-items:center;justify-content:space-between;color:#fff}
.inv-form-hdr-title{font-size:1.05rem;font-weight:900}
.inv-form-hdr-num{font-size:.95rem;font-weight:700;direction:ltr;background:rgba(255,255,255,.18);padding:5px 14px;border-radius:8px}
.inv-form-body{padding:26px}
.inv-sec-title{font-size:.8rem;font-weight:900;color:#64748b;margin:0 0 12px;display:flex;align-items:center;gap:6px;text-transform:uppercase;letter-spacing:.5px}
.inv-sec-title::before{content:'';display:inline-block;width:4px;height:15px;background:#2563eb;border-radius:2px}
.inv-meta-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px;margin-bottom:24px}
.inv-items-section{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:22px}
.inv-items-thead{background:#f8fafc;display:grid;grid-template-columns:34px 1fr 60px 80px 130px 70px 70px 110px 38px;gap:0;padding:9px 12px;border-bottom:2px solid #e2e8f0}
.inv-items-thead span{font-size:.72rem;font-weight:800;color:#64748b;white-space:nowrap}
.inv-item-row{display:grid;grid-template-columns:34px 1fr 60px 80px 130px 70px 70px 110px 38px;gap:0;padding:7px 12px;border-bottom:1px solid #f1f5f9;align-items:center}
.inv-item-row:last-child{border-bottom:none}
.inv-item-row:hover{background:#fafbff}
.row-num{font-size:.75rem;color:#94a3b8;font-weight:700;text-align:center}
.inv-inp{width:100%;border:1.5px solid transparent;border-radius:6px;padding:5px 7px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.8rem;color:#1e293b;background:transparent;direction:rtl}
.inv-inp.n{direction:ltr;text-align:right}
.inv-inp:focus{outline:none;border-color:#2563eb;background:#eff6ff}
.inv-item-total{font-size:.8rem;font-weight:800;color:#1e293b;direction:ltr;text-align:right;padding:0 4px}
.inv-row-del{width:28px;height:28px;border:none;background:#fff1f2;color:#e11d48;border-radius:6px;cursor:pointer;font-size:.85rem;display:flex;align-items:center;justify-content:center;margin:auto}
.inv-row-del:hover{background:#fecdd3}
.inv-add-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border:2px dashed #cbd5e1;border-radius:8px;background:none;color:#64748b;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;margin:10px 12px;transition:all .2s}
.inv-add-btn:hover{border-color:#2563eb;color:#2563eb;background:#eff6ff}
.inv-summary-box{background:linear-gradient(135deg,#f8fafc,#eff6ff);border:1px solid #dbeafe;border-radius:14px;padding:20px 24px;min-width:300px}
.inv-sum-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:.85rem;color:#475569;border-bottom:1px dashed #e2e8f0}
.inv-sum-row:last-child{border-bottom:none}
.inv-sum-row .lbl{font-weight:600}
.inv-sum-row .val{font-weight:700;direction:ltr}
.inv-sum-total{display:flex;justify-content:space-between;align-items:center;padding:10px 0 0;margin-top:4px}
.inv-sum-total .lbl{font-size:.95rem;font-weight:900;color:#1e293b}
.inv-sum-total .val{font-size:1.25rem;font-weight:900;color:#059669;direction:ltr}
.inv-bottom-row{display:flex;justify-content:space-between;align-items:flex-start;gap:22px;flex-wrap:wrap;margin-top:18px}
.inv-notes-wrap{flex:1;min-width:240px}
.ac-wrap{position:relative}
.ac-dd{display:none;position:absolute;top:calc(100% + 4px);right:0;left:0;background:#fff;border:1.5px solid #dbeafe;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:600;max-height:220px;overflow-y:auto}
.ac-dd.open{display:block}
.ac-it{padding:9px 13px;font-size:.83rem;cursor:pointer;color:#1e293b}
.ac-it:hover{background:#eff6ff;color:#2563eb}
.ac-it.nr{color:#94a3b8;cursor:default}
.ac-it.nr:hover{background:transparent;color:#94a3b8}
/* بج‌های وضعیت */
.inv-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.73rem;font-weight:700}
.inv-badge.draft{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1}
.inv-badge.confirmed{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd}
.inv-badge.paid{background:#ecfdf5;color:#059669;border:1px solid #6ee7b7}
.inv-badge.partial{background:#fffbeb;color:#d97706;border:1px solid #fde68a}
.inv-badge.cancelled{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5}
/* اسپینر */
.inv-spin{display:inline-block;width:26px;height:26px;border:3px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:spin .7s linear infinite;margin-bottom:8px}
@keyframes spin{to{transform:rotate(360deg)}}
.inv-loading{text-align:center;padding:38px;color:#94a3b8;font-size:.88rem}
/* چاپ */
@media print{.inv-no-print{display:none!important}.inv-form-wrap{box-shadow:none;border:none}.inv-form-hdr{background:#1e3a8a!important;-webkit-print-color-adjust:exact}}
/* ریسپانسیو */
@media(max-width:900px){.inv-meta-grid{grid-template-columns:1fr 1fr}.inv-items-thead,.inv-item-row{grid-template-columns:28px 1fr 55px 120px 65px 65px 90px 32px}.inv-bottom-row{flex-direction:column}.inv-summary-box{width:100%}}
@media(max-width:600px){.inv-items-thead{display:none}.inv-item-row{grid-template-columns:1fr 1fr;gap:6px;padding:10px}.inv-meta-grid{grid-template-columns:1fr}}
</style>

<div class="main-content">

    <!-- هدر صفحه -->
    <div class="inv-page-header inv-no-print">
        <div class="inv-page-title">
            <div class="inv-page-icon">🧾</div>
            <div>
                <h1>فاکتور فروش</h1>
                <p>صدور، مشاهده و مدیریت فاکتورهای فروش با سند دوطرفه</p>
            </div>
        </div>
        <button class="fin-btn fin-btn-primary" onclick="switchTab('new')">➕ فاکتور جدید</button>
    </div>

    <!-- تب‌ها -->
    <div class="fin-tabs inv-no-print" id="mainTabs">
        <button class="fin-tab active" data-tab="list" onclick="switchTab('list')">📋 لیست فاکتورها</button>
        <button class="fin-tab" data-tab="new"  onclick="switchTab('new')">➕ فاکتور جدید</button>
    </div>

    <!-- ===== تب لیست ===== -->
    <div id="tab-list">
        <!-- کارت‌های آماری -->
        <div class="fin-stats-grid inv-no-print">
            <div class="fin-stat-card blue">
                <div class="fin-stat-icon">📋</div>
                <div><div class="fin-stat-label">تعداد کل</div>
                    <div class="fin-stat-value" id="stat-count"><?= number_format($statsInit['count']) ?></div></div>
            </div>
            <div class="fin-stat-card green">
                <div class="fin-stat-icon">💰</div>
                <div><div class="fin-stat-label">مجموع فروش</div>
                    <div class="fin-stat-value" id="stat-total"><?= $statsInit['total_sum'] ?></div>
                    <div class="fin-stat-unit">ریال</div></div>
            </div>
            <div class="fin-stat-card amber">
                <div class="fin-stat-icon">✅</div>
                <div><div class="fin-stat-label">وصول شده</div>
                    <div class="fin-stat-value" id="stat-paid"><?= $statsInit['paid_sum'] ?></div>
                    <div class="fin-stat-unit">ریال</div></div>
            </div>
            <div class="fin-stat-card rose">
                <div class="fin-stat-icon">⏳</div>
                <div><div class="fin-stat-label">مانده مطالبات</div>
                    <div class="fin-stat-value" id="stat-remain"><?= $statsInit['remain'] ?></div>
                    <div class="fin-stat-unit">ریال</div></div>
            </div>
        </div>

        <!-- فیلتر -->
        <div class="fin-filter-bar inv-no-print">
            <div class="fin-filter-group">
                <label>از تاریخ</label>
                <input type="date" class="fin-input" id="f-from">
            </div>
            <div class="fin-filter-group">
                <label>تا تاریخ</label>
                <input type="date" class="fin-input" id="f-to">
            </div>
            <div class="fin-filter-group">
                <label>وضعیت</label>
                <select class="fin-select" id="f-status">
                    <option value="">همه</option>
                    <option value="draft">پیش‌نویس</option>
                    <option value="confirmed">تأیید شده</option>
                    <option value="paid">پرداخت کامل</option>
                    <option value="partial">پرداخت ناقص</option>
                    <option value="cancelled">لغو شده</option>
                </select>
            </div>
            <div style="display:flex;align-items:flex-end;gap:8px">
                <button class="fin-btn fin-btn-primary" onclick="loadList(1)">🔍 اعمال</button>
                <button class="fin-btn fin-btn-outline" onclick="resetFilter()">پاک</button>
            </div>
        </div>

        <!-- جدول -->
        <div class="fin-panel">
            <div id="listContainer">
                <div class="inv-loading"><div class="inv-spin"></div><div>در حال بارگذاری...</div></div>
            </div>
            <div class="fin-pagination inv-no-print" id="pagination" style="display:none">
                <span id="pag-info"></span>
                <div style="display:flex;gap:6px">
                    <button class="fin-btn fin-btn-outline fin-btn-sm" id="btn-prev" onclick="changePage(-1)">‹ قبلی</button>
                    <button class="fin-btn fin-btn-outline fin-btn-sm" id="btn-next" onclick="changePage(1)">بعدی ›</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== تب فرم ===== -->
    <div id="tab-new" style="display:none">
        <div class="inv-form-wrap">
            <div class="inv-form-hdr">
                <div class="inv-form-hdr-title" id="formTitle">🧾 فاکتور فروش جدید</div>
                <div class="inv-form-hdr-num"   id="formNum">شماره: خودکار</div>
            </div>
            <div class="inv-form-body">

                <!-- اطلاعات پایه -->
                <div class="inv-sec-title">اطلاعات فاکتور</div>
                <div class="inv-meta-grid">
                    <div class="fin-form-group">
                        <label>مشتری / طرف حساب <span style="color:#e11d48">*</span></label>
                        <div class="ac-wrap">
                            <input type="text" class="fin-input" id="personSearch"
                                placeholder="نام یا شرکت را تایپ کنید..."
                                autocomplete="off" oninput="onPersonSearch(this.value)">
                            <input type="hidden" id="personId">
                            <div class="ac-dd" id="personDd"></div>
                        </div>
                    </div>
                    <div class="fin-form-group">
                        <label>تاریخ فاکتور <span style="color:#e11d48">*</span></label>
                        <input type="date" class="fin-input" id="invDate">
                    </div>
                    <div class="fin-form-group">
                        <label>تاریخ سررسید</label>
                        <input type="date" class="fin-input" id="dueDate">
                    </div>
                </div>

                <!-- نوع فاکتور -->
                <div style="margin-bottom:20px">
                    <div class="inv-sec-title">نوع فاکتور</div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                        <label id="lblOfficial" style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #2563eb;background:#eff6ff;font-weight:700;color:#1d4ed8;transition:all .2s">
                            <input type="radio" name="invoiceTypeRadio" value="official" checked onchange="onInvTypeChange(this.value)" style="accent-color:#2563eb">
                            🧾 رسمی
                        </label>
                        <label id="lblAdjustment" style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;transition:all .2s">
                            <input type="radio" name="invoiceTypeRadio" value="adjustment" onchange="onInvTypeChange(this.value)" style="accent-color:#7c3aed">
                            📋 تنظیمی
                            <small style="font-weight:400;font-size:.75rem">(داخلی)</small>
                        </label>
                        <input type="hidden" id="invTypeHidden" value="official">
                        <!-- گزینه حذف مالیات مستقل از نوع فاکتور -->
                        <label id="lblNoTax" style="display:flex;align-items:center;gap:7px;cursor:pointer;padding:10px 16px;border-radius:10px;border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;transition:all .2s;font-size:.88rem">
                            <input type="checkbox" id="chkNoTax" onchange="onNoTaxChange(this.checked)" style="accent-color:#0891b2;width:16px;height:16px">
                            <span>بدون مالیات</span>
                        </label>
                    </div>
                    <div id="taxNote" style="margin-top:8px;font-size:.78rem;color:#64748b;display:none">
                        ⚠️ مالیات از همه ردیف‌ها حذف شده — سند حسابداری بدون ردیف مالیات ثبت می‌شود
                    </div>
                </div>

                <!-- ردیف‌های کالا -->
                <div class="inv-sec-title">ردیف‌های کالا / خدمات</div>
                <div class="inv-items-section">
                    <div class="inv-items-thead">
                        <span>#</span>
                        <span>شرح کالا / خدمت</span>
                        <span>واحد</span>
                        <span>تعداد</span>
                        <span>قیمت واحد (ریال)</span>
                        <span>تخفیف٪</span>
                        <span>مالیات٪</span>
                        <span>مبلغ (ریال)</span>
                        <span></span>
                    </div>
                    <div id="invItems"></div>
                    <button class="inv-add-btn" onclick="addRow()">➕ افزودن ردیف</button>
                </div>

                <!-- خلاصه + توضیحات -->
                <div class="inv-bottom-row">
                    <div class="inv-notes-wrap">
                        <div class="inv-sec-title">توضیحات</div>
                        <textarea class="fin-textarea" id="invNotes" rows="4"
                            placeholder="یادداشت اختیاری..."></textarea>
                        <div class="fin-form-group" style="margin-top:14px">
                            <label>هزینه حمل (ریال)</label>
                            <input type="text" class="fin-input" id="invShipping" value="0"
                                oninput="formatNum(this);recalcAll()" placeholder="۰">
                        </div>
                    </div>
                    <div class="inv-summary-box">
                        <div class="inv-sum-row"><span class="lbl">جمع کل اقلام</span><span class="val" id="s-sub">۰ ریال</span></div>
                        <div class="inv-sum-row"><span class="lbl">مجموع تخفیف</span><span class="val" id="s-disc" style="color:#e11d48">— ۰ ریال</span></div>
                        <div class="inv-sum-row"><span class="lbl">مالیات ارزش افزوده</span><span class="val" id="s-tax">+ ۰ ریال</span></div>
                        <div class="inv-sum-row"><span class="lbl">هزینه حمل</span><span class="val" id="s-ship">+ ۰ ریال</span></div>
                        <div style="border-top:2px solid #2563eb;margin:10px 0 4px"></div>
                        <div class="inv-sum-total">
                            <span class="lbl">مبلغ قابل پرداخت</span>
                            <span class="val" id="s-total">۰ ریال</span>
                        </div>
                    </div>
                </div>

                <!-- دکمه‌های عمل -->
                <div style="display:flex;gap:10px;margin-top:26px;flex-wrap:wrap;border-top:1px solid #f1f5f9;padding-top:18px" class="inv-no-print">
                    <input type="hidden" id="editId" value="0">
                    <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button class="fin-btn fin-btn-outline" style="padding:10px 22px" onclick="doSave('draft')">
                        💾 ذخیره پیش‌نویس
                    </button>
                    <button class="fin-btn fin-btn-success" style="padding:10px 22px;font-size:.95rem" onclick="doSave('confirm')">
                        ✅ تأیید + ثبت سند
                    </button>
                    <button class="fin-btn fin-btn-outline" onclick="printInvoice()">🖨️ چاپ</button>
                    <button class="fin-btn fin-btn-outline" style="margin-right:auto" onclick="resetForm();switchTab('list')">❌ انصراف</button>
                </div>

            </div>
        </div>
    </div>

</div><!-- /main-content -->

<!-- دراور مشاهده فاکتور -->
<div class="fin-drawer-overlay" id="viewOverlay" onclick="closeView()"></div>
<div class="fin-drawer" id="viewDrawer" style="width:640px;max-width:98vw">
    <div class="fin-drawer-header">
        <span id="viewTitle">مشاهده فاکتور</span>
        <button class="fin-drawer-close" onclick="closeView()">×</button>
    </div>
    <div class="fin-drawer-body" id="viewBody">
        <div class="inv-loading"><div class="inv-spin"></div></div>
    </div>
</div>

<script>
/* ============================================================
   JS فاکتور فروش
   ============================================================ */
var curPage = 1, totPages = 1, rowCnt = 0, editMode = false;
var personTimer = null, stuffTimers = {};

function numFa(n){ return Math.round(parseFloat(n)||0).toLocaleString('fa-IR'); }
function numEn(s){ return String(s||'').replace(/[۰-۹]/g,d=>d.charCodeAt(0)-1776).replace(/[٠-٩]/g,d=>d.charCodeAt(0)-1632).replace(/,/g,''); }
function rawNum(el){ return parseInt(numEn(el.value).replace(/\D/g,'')||'0',10)||0; }
function formatNum(el){ var v=parseInt(numEn(el.value).replace(/\D/g,'')||'0',10)||0; el.value=numFa(v); }

function switchTab(t){
    document.getElementById('tab-list').style.display=t==='list'?'':'none';
    document.getElementById('tab-new').style.display=t==='new'?'':'none';
    document.querySelectorAll('.fin-tab').forEach(function(el){el.classList.toggle('active',el.dataset.tab===t);});
    if(t==='list') loadList(curPage);
}

// ============================================================
// لیست
// ============================================================
function loadList(pg){
    curPage=pg;
    document.getElementById('listContainer').innerHTML='<div class="inv-loading"><div class="inv-spin"></div><div>در حال بارگذاری...</div></div>';
    var fd=new FormData();
    fd.append('action','list'); fd.append('page',pg);
    fd.append('date_from',document.getElementById('f-from').value||'');
    fd.append('date_to',  document.getElementById('f-to').value||'');
    fd.append('status',   document.getElementById('f-status').value||'');
    post(fd,function(res){
        if(!res.ok){document.getElementById('listContainer').innerHTML='<div class="fin-alert danger">'+esc(res.msg)+'</div>';return;}
        document.getElementById('stat-count').textContent=numFa(res.stats.count);
        document.getElementById('stat-total').textContent=res.stats.total_sum;
        document.getElementById('stat-paid').textContent=res.stats.paid_sum;
        document.getElementById('stat-remain').textContent=res.stats.remain;
        totPages=res.pagination.total_pages;
        renderTable(res.rows);
        renderPag(res.pagination);
    });
}

function renderTable(rows){
    if(!rows||!rows.length){
        document.getElementById('listContainer').innerHTML='<div class="fin-empty"><div class="empty-icon">🧾</div><p>هیچ فاکتوری یافت نشد.</p></div>';
        document.getElementById('pagination').style.display='none';
        return;
    }
    var h='<div style="overflow-x:auto"><table class="fin-table"><thead><tr>'
        +'<th>شماره</th><th>نوع</th><th>تاریخ</th><th>مشتری</th>'
        +'<th>مبلغ کل (ریال)</th><th>پرداخت شده</th><th>مانده</th>'
        +'<th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
    rows.forEach(function(r){
        var badge='<span class="inv-badge '+r.status_class+'">'+r.status_label+'</span>';
        var typeBadge = r.invoice_type === 'adjustment'
            ? '<span style="font-size:.7rem;background:#f3e8ff;color:#7c3aed;padding:2px 7px;border-radius:8px;white-space:nowrap">تنظیمی</span>'
            : '<span style="font-size:.7rem;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:8px;white-space:nowrap">رسمی</span>';
        h+='<tr>'
          +'<td><strong style="color:#2563eb;direction:ltr;display:inline-block">'+esc(r.invoice_number)+'</strong></td>'
          +'<td>'+typeBadge+'</td>'
          +'<td style="direction:ltr">'+esc(r.date_jalali)+'</td>'
          +'<td>'+esc(r.person_name||'—')+'</td>'
          +'<td style="direction:ltr;font-weight:700">'+r.total_fmt+'</td>'
          +'<td style="direction:ltr;color:#059669">'+r.paid_fmt+'</td>'
          +'<td style="direction:ltr;color:#e11d48">'+r.remain_fmt+'</td>'
          +'<td>'+badge+'</td>'
          +'<td style="white-space:nowrap">'
          +'<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="viewInv('+r.id+')">👁</button> '
          +(r.status!=='draft'?'<a class="fin-btn fin-btn-outline fin-btn-sm" href="fin_invoice_pdf.php?id='+r.id+'&type=sell" target="_blank" title="چاپ PDF">🖨️</a> ':'')
          +(r.status==='draft'?'<button class="fin-btn fin-btn-primary fin-btn-sm" onclick="editInv('+r.id+')">✏️</button> ':'')
          +'<button class="fin-btn fin-btn-danger fin-btn-sm" onclick="delInv('+r.id+',\''+esc(r.invoice_number)+'\')">🗑</button>'
          +'</td></tr>';
    });
    h+='</tbody></table></div>';
    document.getElementById('listContainer').innerHTML=h;
    document.getElementById('pagination').style.display='';
}

function renderPag(p){
    document.getElementById('pag-info').textContent='صفحه '+p.page+' از '+p.total_pages+' — مجموع '+numFa(p.total_rows)+' فاکتور';
    document.getElementById('btn-prev').disabled=p.page<=1;
    document.getElementById('btn-next').disabled=p.page>=p.total_pages;
}
function changePage(d){var np=curPage+d;if(np<1||np>totPages)return;loadList(np);}
function resetFilter(){
    ['f-from','f-to'].forEach(function(id){document.getElementById(id).value='';});
    document.getElementById('f-status').value='';
    loadList(1);
}

// ============================================================
// مشاهده
// ============================================================
function viewInv(id){
    document.getElementById('viewTitle').textContent='در حال بارگذاری...';
    document.getElementById('viewBody').innerHTML='<div class="inv-loading"><div class="inv-spin"></div></div>';
    document.getElementById('viewOverlay').classList.add('open');
    document.getElementById('viewDrawer').classList.add('open');
    var fd=new FormData(); fd.append('action','get_one'); fd.append('id',id);
    post(fd,function(res){
        if(!res.ok){document.getElementById('viewBody').innerHTML='<div class="fin-alert danger">'+esc(res.msg)+'</div>';return;}
        var inv=res.invoice;
        document.getElementById('viewTitle').textContent='فاکتور '+inv.invoice_number;
        var statusLabels={draft:'پیش‌نویس',confirmed:'تأیید شده',paid:'پرداخت کامل',partial:'پرداخت ناقص',cancelled:'لغو شده'};
        var sLabel=statusLabels[inv.status]||inv.status;
        var sClass=inv.status||'draft';
        var itemsHtml='';
        (inv.items||[]).forEach(function(it,i){
            itemsHtml+='<tr><td>'+(i+1)+'</td><td>'+esc(it.description)+'</td>'
                +'<td style="direction:ltr">'+numFa(it.qty)+'</td>'
                +'<td style="direction:ltr">'+numFa(it.unit_price)+'</td>'
                +'<td style="direction:ltr;color:#e11d48">'+numFa(it.discount_amt)+'</td>'
                +'<td style="direction:ltr">'+numFa(it.tax_amt)+'</td>'
                +'<td style="direction:ltr;font-weight:800">'+numFa(it.total)+'</td></tr>';
        });
        document.getElementById('viewBody').innerHTML=`
<div style="margin-bottom:18px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <div><div style="font-size:.75rem;color:#64748b">مشتری</div>
         <div style="font-weight:800;font-size:1rem">${esc(inv.person_name||'—')}</div></div>
    <span class="inv-badge ${sClass}">${sLabel}</span>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;background:#f8fafc;padding:12px;border-radius:10px;margin-bottom:14px">
    <div><span style="color:#64748b;font-size:.73rem">شماره فاکتور</span><br><strong style="direction:ltr;display:inline-block">${esc(inv.invoice_number)}</strong></div>
    <div><span style="color:#64748b;font-size:.73rem">تاریخ</span><br><strong style="direction:ltr;display:inline-block">${esc(inv.date_jalali)}</strong></div>
    ${inv.fin_doc_id?'<div><span style="color:#64748b;font-size:.73rem">شماره سند</span><br><strong style="color:#2563eb">#'+inv.fin_doc_id+'</strong></div>':'<div></div>'}
  </div>
</div>
<div style="overflow-x:auto;margin-bottom:16px">
  <table class="fin-table"><thead><tr>
    <th>#</th><th>شرح</th><th>تعداد</th><th>قیمت واحد</th><th>تخفیف</th><th>مالیات</th><th>مبلغ</th>
  </tr></thead><tbody>${itemsHtml}</tbody></table>
</div>
<div class="inv-summary-box" style="max-width:300px;margin-right:auto">
  <div class="inv-sum-row"><span class="lbl">جمع اقلام</span><span class="val">${numFa(inv.subtotal)} ریال</span></div>
  <div class="inv-sum-row"><span class="lbl">تخفیف</span><span class="val" style="color:#e11d48">— ${numFa(inv.discount)} ریال</span></div>
  <div class="inv-sum-row"><span class="lbl">مالیات</span><span class="val">+ ${numFa(inv.tax)} ریال</span></div>
  <div class="inv-sum-row"><span class="lbl">حمل</span><span class="val">+ ${numFa(inv.shipping||0)} ریال</span></div>
  <div style="border-top:2px solid #2563eb;margin:8px 0 4px"></div>
  <div class="inv-sum-total"><span class="lbl">مبلغ نهایی</span><span class="val">${numFa(inv.total_amount)} ریال</span></div>
  <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #e2e8f0">
    <div class="inv-sum-row"><span class="lbl">پرداخت شده</span><span class="val" style="color:#059669">${numFa(inv.paid_amount)} ریال</span></div>
    <div class="inv-sum-row"><span class="lbl">مانده</span><span class="val" style="color:#e11d48">${numFa(Math.max(0,inv.total_amount-inv.paid_amount))} ریال</span></div>
  </div>
</div>
${inv.notes?'<div style="margin-top:14px;padding:10px 14px;background:#fffbeb;border-radius:8px;font-size:.83rem;color:#92400e"><strong>توضیحات: </strong>'+esc(inv.notes)+'</div>':''}
        `;
    });
}
function closeView(){
    document.getElementById('viewOverlay').classList.remove('open');
    document.getElementById('viewDrawer').classList.remove('open');
}

// ============================================================
// ویرایش
// ============================================================
function editInv(id){
    var fd=new FormData(); fd.append('action','get_one'); fd.append('id',id);
    post(fd,function(res){
        if(!res.ok){showToast(res.msg,'danger');return;}
        var inv=res.invoice;
        resetForm();
        editMode=true;
        document.getElementById('editId').value=inv.id;
        document.getElementById('personSearch').value=inv.person_name||'';
        document.getElementById('personId').value=inv.person_id;
        document.getElementById('invDate').value=inv.invoice_date;
        document.getElementById('dueDate').value=inv.due_date||'';
        document.getElementById('invNotes').value=inv.notes||'';
        document.getElementById('invShipping').value=numFa(inv.shipping||0);
        document.getElementById('formTitle').textContent='✏️ ویرایش فاکتور';
        document.getElementById('formNum').textContent='شماره: '+inv.invoice_number;
        // بازیابی نوع فاکتور و وضعیت مالیات
        var itype = inv.invoice_type || 'official';
        document.getElementById('invTypeHidden').value = itype;
        document.querySelectorAll('[name="invoiceTypeRadio"]').forEach(function(r) { r.checked = (r.value === itype); });
        // اگر مالیات کل صفر است، تیک «بدون مالیات» را فعال کن
        var allTaxZero = inv.items && inv.items.length && inv.items.every(function(it){ return !parseFloat(it.tax_pct); });
        var chk = document.getElementById('chkNoTax');
        chk.checked = (itype === 'adjustment') || allTaxZero;
        chk.disabled = (itype === 'adjustment');
        onInvTypeChange(itype);
        (inv.items||[]).forEach(function(it){
            addRow(it.stuff_id,it.description,it.unit,it.qty,it.unit_price,it.discount_pct,it.tax_pct);
        });
        recalcAll();
        switchTab('new');
    });
}

// ============================================================
// حذف
// ============================================================
function delInv(id,num){
    if(!confirm('آیا از حذف فاکتور '+num+' اطمینان دارید؟')) return;
    var fd=new FormData();
    fd.append('action','delete'); fd.append('id',id);
    fd.append('csrf_token',document.getElementById('csrfToken').value);
    post(fd,function(res){showToast(res.msg,res.ok?'success':'danger');if(res.ok)loadList(curPage);});
}

// ============================================================
// فرم
// ============================================================
function resetForm(){
    editMode=false; rowCnt=0;
    document.getElementById('editId').value='0';
    document.getElementById('personSearch').value='';
    document.getElementById('personId').value='';
    document.getElementById('invDate').value=today();
    document.getElementById('dueDate').value='';
    document.getElementById('invNotes').value='';
    document.getElementById('invShipping').value='۰';
    document.getElementById('invItems').innerHTML='';
    document.getElementById('formTitle').textContent='🧾 فاکتور فروش جدید';
    document.getElementById('formNum').textContent='شماره: خودکار';
    // بازنشانی نوع فاکتور و تیک مالیات
    document.getElementById('invTypeHidden').value='official';
    document.querySelectorAll('[name="invoiceTypeRadio"]').forEach(function(r){r.checked=(r.value==='official');});
    onInvTypeChange('official');
    document.getElementById('chkNoTax').checked=false;
    document.getElementById('chkNoTax').disabled=false;
    document.getElementById('taxNote').style.display='none';
    recalcAll();
    addRow();
}

function today(){
    var d=new Date();
    return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
}

// ---- افزودن ردیف ----
function addRow(stuffId,desc,unit,qty,price,discP,taxP){
    rowCnt++;
    var idx=rowCnt;
    var noTaxNow = document.getElementById('chkNoTax').checked || document.getElementById('invTypeHidden').value==='adjustment';
    var d=desc||'', u=unit||'', q=qty||1, p=price||0, dp=discP!==undefined?discP:0, tp=(taxP!==undefined?taxP:(noTaxNow?0:9)), sid=stuffId||'';
    var row=document.createElement('div');
    row.className='inv-item-row'; row.id='row-'+idx;
    row.innerHTML=
        '<span class="row-num">'+idx+'</span>'
        +'<div style="position:relative">'
        +  '<input type="text" class="inv-inp" id="d-'+idx+'" placeholder="شرح..." value="'+esc(d)+'" autocomplete="off" oninput="onStuffSearch('+idx+',this.value)">'
        +  '<input type="hidden" id="sid-'+idx+'" value="'+sid+'">'
        +  '<div class="ac-dd" id="sdd-'+idx+'"></div>'
        +'</div>'
        +'<input type="text" class="inv-inp" id="u-'+idx+'" value="'+esc(u)+'" placeholder="عدد">'
        +'<input type="number" class="inv-inp n" id="q-'+idx+'" value="'+q+'" min="0.001" step="any" oninput="recalcRow('+idx+')">'
        +'<input type="text" class="inv-inp n" id="p-'+idx+'" value="'+(p>0?numFa(p):'')+'" placeholder="۰" oninput="formatNum(this);recalcRow('+idx+')">'
        +'<input type="number" class="inv-inp n" id="disc-'+idx+'" value="'+dp+'" min="0" max="100" step="0.01" oninput="recalcRow('+idx+')">'
        +'<input type="number" class="inv-inp n" id="tax-'+idx+'" value="'+tp+'" min="0" max="100" step="0.01" oninput="recalcRow('+idx+')">'
        +'<div class="inv-item-total" id="tot-'+idx+'">۰</div>'
        +'<button class="inv-row-del" onclick="removeRow('+idx+')" title="حذف">✕</button>';
    document.getElementById('invItems').appendChild(row);
    recalcRow(idx);
    if(!desc) setTimeout(function(){var el=document.getElementById('d-'+idx);if(el)el.focus();},50);
}

function removeRow(idx){
    var r=document.getElementById('row-'+idx);
    if(r)r.remove();
    recalcAll();
}

function recalcRow(idx){
    var pEl=document.getElementById('p-'+idx),
        qEl=document.getElementById('q-'+idx),
        dEl=document.getElementById('disc-'+idx),
        tEl=document.getElementById('tax-'+idx),
        totEl=document.getElementById('tot-'+idx);
    if(!pEl)return;
    var p=rawNum(pEl), q=parseFloat(qEl.value)||0, d=parseFloat(dEl.value)||0, t=parseFloat(tEl.value)||0;
    var gross=Math.round(q*p), dAmt=Math.round(gross*d/100), after=gross-dAmt, tAmt=Math.round(after*t/100);
    totEl.textContent=numFa(after+tAmt);
    recalcAll();
}

function recalcAll(){
    var sub=0,disc=0,tax=0;
    document.querySelectorAll('#invItems .inv-item-row').forEach(function(r){
        var m=r.id.match(/row-(\d+)/);if(!m)return;
        var idx=m[1];
        var pEl=document.getElementById('p-'+idx),qEl=document.getElementById('q-'+idx),
            dEl=document.getElementById('disc-'+idx),tEl=document.getElementById('tax-'+idx);
        if(!pEl)return;
        var p=rawNum(pEl),q=parseFloat(qEl.value)||0,d=parseFloat(dEl.value)||0,t=parseFloat(tEl.value)||0;
        var gross=Math.round(q*p),dAmt=Math.round(gross*d/100),after=gross-dAmt;
        sub+=gross; disc+=dAmt; tax+=Math.round(after*t/100);
    });
    var ship=rawNum(document.getElementById('invShipping'));
    var total=sub-disc+tax+ship;
    document.getElementById('s-sub').textContent=numFa(sub)+' ریال';
    document.getElementById('s-disc').textContent='— '+numFa(disc)+' ریال';
    document.getElementById('s-tax').textContent='+ '+numFa(tax)+' ریال';
    document.getElementById('s-ship').textContent='+ '+numFa(ship)+' ریال';
    document.getElementById('s-total').textContent=numFa(total)+' ریال';
}

// ============================================================
// Autocomplete طرف حساب
// ============================================================
function onPersonSearch(q){
    clearTimeout(personTimer);
    document.getElementById('personId').value='';
    var dd=document.getElementById('personDd');
    if(q.length<2){dd.classList.remove('open');return;}
    personTimer=setTimeout(function(){
        var fd=new FormData(); fd.append('action','search_persons'); fd.append('q',q);
        post(fd,function(res){
            if(!res.ok||!res.results.length){
                dd.innerHTML='<div class="ac-it nr">موردی یافت نشد.</div>';
            } else {
                dd.innerHTML=res.results.map(function(p){
                    return '<div class="ac-it" onclick="selPerson('+p.id+',\''+escJ(p.display_name)+'\')">'+esc(p.display_name)+'</div>';
                }).join('');
            }
            dd.classList.add('open');
        });
    },280);
}
function selPerson(id,name){
    document.getElementById('personId').value=id;
    document.getElementById('personSearch').value=name;
    document.getElementById('personDd').classList.remove('open');
}

// ============================================================
// Autocomplete کالا
// ============================================================
function onStuffSearch(idx,q){
    clearTimeout(stuffTimers[idx]);
    document.getElementById('sid-'+idx).value='';
    var dd=document.getElementById('sdd-'+idx);
    if(q.length<2){dd.classList.remove('open');return;}
    stuffTimers[idx]=setTimeout(function(){
        var fd=new FormData(); fd.append('action','search_stuffs'); fd.append('q',q);
        post(fd,function(res){
            if(!res.ok||!res.results.length){
                dd.innerHTML='<div class="ac-it nr">کالایی یافت نشد — شرح دستی وارد کنید.</div>';
            } else {
                dd.innerHTML=res.results.map(function(c){
                    return '<div class="ac-it" onclick="selStuff('+idx+','+c.id+',\''+escJ(c.name)+'\',\''+escJ(c.unit||'')+'\','+(parseInt(c.price)||0)+')">'
                        +esc(c.name)+(c.unit?' <small style="color:#94a3b8">('+c.unit+')</small>':'')
                        +' — <span style="color:#059669;direction:ltr;display:inline-block">'+numFa(c.price)+' ریال</span>'
                        +'</div>';
                }).join('');
            }
            dd.classList.add('open');
        });
    },280);
}
function selStuff(idx,sid,name,unit,price){
    document.getElementById('sid-'+idx).value=sid;
    document.getElementById('d-'+idx).value=name;
    document.getElementById('u-'+idx).value=unit||'';
    var pEl=document.getElementById('p-'+idx);
    pEl.value=numFa(price);
    document.getElementById('sdd-'+idx).classList.remove('open');
    recalcRow(idx);
    var qEl=document.getElementById('q-'+idx);
    if(qEl){qEl.focus();qEl.select();}
}

// بستن dropdown با کلیک خارج
document.addEventListener('click',function(e){
    if(!e.target.closest('.ac-wrap')&&!e.target.closest('.inv-item-row')){
        document.querySelectorAll('.ac-dd').forEach(function(d){d.classList.remove('open');});
    }
});

// ============================================================
// ذخیره فاکتور
// ============================================================
function onInvTypeChange(val) {
    document.getElementById('invTypeHidden').value = val;
    var isAdj = (val === 'adjustment');
    document.getElementById('lblOfficial').style.cssText = !isAdj
        ? 'display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #2563eb;background:#eff6ff;font-weight:700;color:#1d4ed8;transition:all .2s'
        : 'display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;transition:all .2s';
    document.getElementById('lblAdjustment').style.cssText = isAdj
        ? 'display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #7c3aed;background:#f3e8ff;font-weight:700;color:#7c3aed;transition:all .2s'
        : 'display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;transition:all .2s';
    var noTax = isAdj || document.getElementById('chkNoTax').checked;
    applyTaxState(noTax);
    // تنظیمی → تیک «بدون مالیات» را هم فعال نشان بده
    var chk = document.getElementById('chkNoTax');
    if (isAdj) { chk.checked = true; chk.disabled = true; }
    else        { chk.disabled = false; }
    recalcAll();
}

function onNoTaxChange(checked) {
    var isAdj = (document.getElementById('invTypeHidden').value === 'adjustment');
    applyTaxState(isAdj || checked);
    document.getElementById('taxNote').style.display = (isAdj || checked) ? 'block' : 'none';
    recalcAll();
}

function applyTaxState(noTax) {
    document.querySelectorAll('[id^="tax-"]').forEach(function(el) {
        if (noTax) { el.value = '0'; el.style.opacity = '.4'; el.style.pointerEvents = 'none'; }
        else        { if (el.value === '0') el.value = '9'; el.style.opacity = '1'; el.style.pointerEvents = ''; }
    });
    document.querySelectorAll('.tax-col').forEach(function(el) {
        el.style.opacity = noTax ? '.4' : '1';
        el.style.pointerEvents = noTax ? 'none' : '';
    });
    document.getElementById('taxNote').style.display = noTax ? 'block' : 'none';
}

function doSave(mode){
    var personId=document.getElementById('personId').value;
    var invDate=document.getElementById('invDate').value;
    if(!personId){showToast('لطفاً مشتری را انتخاب کنید.','danger');document.getElementById('personSearch').focus();return;}
    if(!invDate){showToast('تاریخ فاکتور الزامی است.','danger');return;}

    var items=[]; var ok=false;
    document.querySelectorAll('#invItems .inv-item-row').forEach(function(r){
        var m=r.id.match(/row-(\d+)/);if(!m)return;
        var idx=m[1];
        var desc=(document.getElementById('d-'+idx).value||'').trim();
        if(!desc)return;
        ok=true;
        items.push({
            stuff_id:   document.getElementById('sid-'+idx).value||'',
            description:desc,
            unit:       document.getElementById('u-'+idx).value||'',
            qty:        document.getElementById('q-'+idx).value||'1',
            unit_price: numEn(document.getElementById('p-'+idx).value).replace(/\D/g,'')||'0',
            discount_pct:document.getElementById('disc-'+idx).value||'0',
            tax_pct:    document.getElementById('tax-'+idx).value||'9',
        });
    });
    if(!ok){showToast('حداقل یک ردیف با شرح وارد کنید.','danger');return;}

    var fd=new FormData();
    var action=mode==='draft'?'save_draft':'confirm_invoice';
    fd.append('action',action);
    fd.append('csrf_token',document.getElementById('csrfToken').value);
    fd.append('invoice_id',document.getElementById('editId').value);
    fd.append('person_id',personId);
    fd.append('invoice_date',invDate);
    fd.append('due_date',document.getElementById('dueDate').value||'');
    fd.append('notes',document.getElementById('invNotes').value||'');
    fd.append('shipping',numEn(document.getElementById('invShipping').value).replace(/\D/g,'')||'0');
    fd.append('invoice_type',document.getElementById('invTypeHidden').value||'official');
    items.forEach(function(it,i){Object.keys(it).forEach(function(k){fd.append('items['+i+']['+k+']',it[k]);});});

    var btn=mode==='draft'?document.querySelector('[onclick="doSave(\'draft\')"]'):document.querySelector('[onclick="doSave(\'confirm\')"]');
    if(btn){btn.disabled=true;btn.textContent='⏳ در حال ذخیره...';}

    post(fd,function(res){
        if(btn){btn.disabled=false;btn.textContent=mode==='draft'?'💾 ذخیره پیش‌نویس':'✅ تأیید + ثبت سند';}
        showToast(res.msg,res.ok?'success':'danger');
        if(res.ok){resetForm();switchTab('list');}
    });
}

function printInvoice(){ window.print(); }

// ============================================================
// AJAX
// ============================================================
function post(fd,cb){
    fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(function(r){return r.json();}).then(cb)
    .catch(function(){showToast('خطای ارتباطی رخ داد.','danger');});
}

function showToast(msg,type){
    var colors={success:'#059669',danger:'#e11d48',warning:'#d97706',info:'#2563eb'};
    var d=document.createElement('div');
    d.textContent=msg;
    d.style.cssText='position:fixed;top:20px;left:50%;transform:translateX(-50%);background:'+(colors[type]||colors.info)+';color:#fff;padding:11px 22px;border-radius:10px;font-family:Vazirmatn,Tahoma,sans-serif;font-weight:700;font-size:.88rem;z-index:9999;box-shadow:0 6px 20px rgba(0,0,0,.2)';
    document.body.appendChild(d);
    setTimeout(function(){d.remove();},3500);
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function escJ(s){return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"');}

// ============================================================
// راه‌اندازی
// ============================================================
document.addEventListener('DOMContentLoaded',function(){
    document.getElementById('invDate').value=today();
    loadList(1);
    addRow();
});
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
