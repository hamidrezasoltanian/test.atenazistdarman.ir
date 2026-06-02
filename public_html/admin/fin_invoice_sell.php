<?php
/*
 * فایل: public_html/admin/fin_invoice_sell.php
 * توضیحات: صفحه فاکتور فروش — ثبت، مشاهده، ویرایش، و حذف فاکتورهای فروش
 * با ثبت خودکار سند حسابداری دوطرفه
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'نشست منقضی شده است.']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$rawRole  = $_SESSION['role'] ?? '';

// --- بررسی دسترسی ---
$hasAccess = false;
$stmtChk = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$rawRole, $rawRole]);
$roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($roleData) {
    if ($roleData['name'] === 'admin' || $roleData['is_system'] == 1) {
        $hasAccess = true;
    } else {
        $perms = json_decode($roleData['permissions'], true) ?? [];
        if (in_array('fin_invoices', $perms) || in_array('all', $perms)) {
            $hasAccess = true;
        }
    }
}
$roleName = strtolower(trim($roleData['name'] ?? $rawRole));
$financeRoles = ['admin', 'management', 'manager', 'finance_manager', 'finance_expert', 'accountant'];
if (in_array($roleName, $financeRoles) || in_array($rawRole, ['1', '2', '5', '10', '14'])) {
    $hasAccess = true;
}

if (!$hasAccess) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'دسترسی غیرمجاز']);
        exit;
    }
    die('<div style="text-align:center;padding:60px;font-family:Tahoma;color:#e11d48;font-weight:bold;">⛔ دسترسی غیرمجاز.</div>');
}

// --- ایجاد خودکار جداول اگر وجود نداشتند ---
try {
    // جدول اشخاص (مشتریان حسابداری)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_persons` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `code`         VARCHAR(30) DEFAULT NULL,
        `company_name` VARCHAR(200) DEFAULT NULL,
        `name`         VARCHAR(150) NOT NULL,
        `phone`        VARCHAR(30) DEFAULT NULL,
        `mobile`       VARCHAR(30) DEFAULT NULL,
        `email`        VARCHAR(150) DEFAULT NULL,
        `address`      TEXT DEFAULT NULL,
        `type`         ENUM('customer','supplier','both') DEFAULT 'customer',
        `national_id`  VARCHAR(20) DEFAULT NULL,
        `credit_limit` DECIMAL(20,0) DEFAULT 0,
        `notes`        TEXT DEFAULT NULL,
        `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted`   TINYINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول فاکتورها
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_invoices` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_number`  VARCHAR(30) NOT NULL,
        `invoice_date`    DATE NOT NULL,
        `due_date`        DATE DEFAULT NULL,
        `type`            ENUM('sell','buy') DEFAULT 'sell',
        `person_id`       INT NOT NULL,
        `doc_id`          INT DEFAULT NULL,
        `opportunity_id`  INT DEFAULT NULL,
        `fiscal_year_id`  INT DEFAULT NULL,
        `user_id`         INT NOT NULL,
        `subtotal`        DECIMAL(20,0) DEFAULT 0,
        `discount_amount` DECIMAL(20,0) DEFAULT 0,
        `tax_amount`      DECIMAL(20,0) DEFAULT 0,
        `total_amount`    DECIMAL(20,0) DEFAULT 0,
        `paid_amount`     DECIMAL(20,0) DEFAULT 0,
        `notes`           TEXT DEFAULT NULL,
        `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted`      TINYINT DEFAULT 0,
        UNIQUE KEY `uq_invoice_number` (`invoice_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول ردیف‌های فاکتور
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_invoice_items` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_id`      INT NOT NULL,
        `commodity_id`    INT DEFAULT NULL,
        `description`     VARCHAR(500) NOT NULL,
        `quantity`        DECIMAL(12,4) NOT NULL DEFAULT 1,
        `unit_price`      DECIMAL(20,0) NOT NULL DEFAULT 0,
        `discount_pct`    DECIMAL(5,2) DEFAULT 0,
        `discount_amount` DECIMAL(20,0) DEFAULT 0,
        `tax_pct`         DECIMAL(5,2) DEFAULT 9,
        `tax_amount`      DECIMAL(20,0) DEFAULT 0,
        `total`           DECIMAL(20,0) NOT NULL DEFAULT 0,
        `row_order`       INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول اسناد حسابداری (اگر وجود نداشت)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_docs` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `doc_number`     VARCHAR(30) DEFAULT NULL,
        `doc_date`       DATE NOT NULL,
        `type`           VARCHAR(50) DEFAULT 'manual',
        `description`    TEXT DEFAULT NULL,
        `fiscal_year_id` INT DEFAULT NULL,
        `user_id`        INT NOT NULL,
        `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
        `is_deleted`     TINYINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // جدول ردیف‌های سند حسابداری (اگر وجود نداشت)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_doc_rows` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `doc_id`      INT NOT NULL,
        `account_code` VARCHAR(20) NOT NULL,
        `account_name` VARCHAR(150) NOT NULL,
        `debit`       DECIMAL(20,0) DEFAULT 0,
        `credit`      DECIMAL(20,0) DEFAULT 0,
        `description` VARCHAR(500) DEFAULT NULL,
        `row_order`   INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // ادامه می‌دهیم حتی اگر جداول وجود داشتند
}

$fiscalYear   = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? $fiscalYear['id'] : null;

// ===========================================================
// پردازش درخواست‌های AJAX
// ===========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        // ---- لیست فاکتورها -------------------------------------------
        if ($action === 'list') {
            $page      = max(1, (int)($_POST['page'] ?? 1));
            $perPage   = 20;
            $offset    = ($page - 1) * $perPage;
            $dateFrom  = trim($_POST['date_from'] ?? '');
            $dateTo    = trim($_POST['date_to'] ?? '');
            $personId  = (int)($_POST['person_id'] ?? 0);
            $status    = trim($_POST['status'] ?? '');

            $where  = ['i.is_deleted = 0', "i.type = 'sell'"];
            $params = [];

            if ($dateFrom) { $where[] = 'i.invoice_date >= ?'; $params[] = $dateFrom; }
            if ($dateTo)   { $where[] = 'i.invoice_date <= ?'; $params[] = $dateTo; }
            if ($personId) { $where[] = 'i.person_id = ?';    $params[] = $personId; }
            if ($status === 'paid')    { $where[] = 'i.paid_amount >= i.total_amount AND i.total_amount > 0'; }
            if ($status === 'partial') { $where[] = 'i.paid_amount > 0 AND i.paid_amount < i.total_amount'; }
            if ($status === 'unpaid')  { $where[] = 'i.paid_amount = 0'; }

            $whereStr = implode(' AND ', $where);

            // آمار کلی
            $stmtStat = $pdo->prepare(
                "SELECT COUNT(*) as cnt, COALESCE(SUM(i.total_amount),0) as total_sum,
                        COALESCE(SUM(i.paid_amount),0) as paid_sum
                 FROM fin_invoices i WHERE $whereStr"
            );
            $stmtStat->execute($params);
            $stats = $stmtStat->fetch();

            // لیست صفحه‌بندی شده
            $stmtList = $pdo->prepare(
                "SELECT i.id, i.invoice_number, i.invoice_date, i.due_date,
                        i.total_amount, i.paid_amount,
                        (i.total_amount - i.paid_amount) AS remaining,
                        COALESCE(p.company_name, p.name) AS person_name
                 FROM fin_invoices i
                 LEFT JOIN fin_persons p ON p.id = i.person_id
                 WHERE $whereStr
                 ORDER BY i.id DESC
                 LIMIT $perPage OFFSET $offset"
            );
            $stmtList->execute($params);
            $rows = $stmtList->fetchAll();

            // تبدیل مبالغ و نشانه وضعیت
            foreach ($rows as &$r) {
                $r['total_fmt']   = number_format($r['total_amount']);
                $r['paid_fmt']    = number_format($r['paid_amount']);
                $r['remain_fmt']  = number_format($r['remaining']);
                $r['date_jalali'] = jdate('Y/m/d', $r['invoice_date']);
                if ($r['total_amount'] > 0 && $r['paid_amount'] >= $r['total_amount']) {
                    $r['status_label'] = 'پرداخت کامل';
                    $r['status_class'] = 'paid';
                } elseif ($r['paid_amount'] > 0) {
                    $r['status_label'] = 'پرداخت ناقص';
                    $r['status_class'] = 'partial';
                } else {
                    $r['status_label'] = 'پرداخت نشده';
                    $r['status_class'] = 'unpaid';
                }
            }
            unset($r);

            $totalPages = max(1, (int)ceil($stats['cnt'] / $perPage));

            echo json_encode([
                'ok'    => true,
                'rows'  => $rows,
                'stats' => [
                    'count'     => (int)$stats['cnt'],
                    'total_sum' => number_format($stats['total_sum']),
                    'paid_sum'  => number_format($stats['paid_sum']),
                    'remain'    => number_format($stats['total_sum'] - $stats['paid_sum']),
                ],
                'pagination' => [
                    'page'       => $page,
                    'total_pages'=> $totalPages,
                    'total_rows' => (int)$stats['cnt'],
                ],
            ]);
            exit;
        }

        // ---- جستجوی اشخاص (autocomplete) --------------------------------
        if ($action === 'search_persons') {
            $q = '%' . trim($_POST['q'] ?? '') . '%';
            $stmt = $pdo->prepare(
                "SELECT id,
                        CONCAT(COALESCE(company_name,''), IF(company_name IS NOT NULL AND company_name != '' AND name != '', ' — ', ''), name) AS display_name
                 FROM fin_persons
                 WHERE is_deleted = 0
                   AND (name LIKE ? OR company_name LIKE ? OR phone LIKE ?)
                 LIMIT 15"
            );
            $stmt->execute([$q, $q, $q]);
            $persons = $stmt->fetchAll();

            // اگر نتیجه‌ای نبود، در جدول customers جستجو می‌کنیم
            if (empty($persons)) {
                $qRaw = trim($_POST['q'] ?? '');
                $qLike = '%' . $qRaw . '%';
                $stmtC = $pdo->prepare(
                    "SELECT id, name, company_name, phone FROM customers
                     WHERE is_deleted = 0 AND (name LIKE ? OR company_name LIKE ?)
                     LIMIT 10"
                );
                $stmtC->execute([$qLike, $qLike]);
                $customers = $stmtC->fetchAll();
                foreach ($customers as $c) {
                    // به صورت خودکار در fin_persons ثبت می‌کنیم
                    $dispName = $c['company_name'] ?: $c['name'];
                    $stmtIns = $pdo->prepare(
                        "INSERT IGNORE INTO fin_persons (company_name, name, phone, type, created_at)
                         VALUES (?, ?, ?, 'customer', NOW())"
                    );
                    $stmtIns->execute([$c['company_name'], $c['name'], $c['phone']]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($newId > 0) {
                        $persons[] = [
                            'id'           => $newId,
                            'display_name' => trim(($c['company_name'] ? $c['company_name'] . ' — ' : '') . $c['name']),
                        ];
                    }
                }
            }

            echo json_encode(['ok' => true, 'results' => $persons]);
            exit;
        }

        // ---- جستجوی کالا (autocomplete) ----------------------------------
        if ($action === 'search_commodities') {
            $q = '%' . trim($_POST['q'] ?? '') . '%';
            $stmt = $pdo->prepare(
                "SELECT id, name,
                        COALESCE(sell_price, price, 0) AS price,
                        unit
                 FROM stuffs
                 WHERE is_deleted = 0 AND (name LIKE ? OR code LIKE ?)
                 LIMIT 15"
            );
            // اگر ستون‌های مورد نظر نباشند fallback می‌گیریم
            try {
                $stmt->execute([$q, $q]);
                $rows = $stmt->fetchAll();
            } catch (Throwable $e) {
                // سعی با ستون‌های کمتر
                $stmt2 = $pdo->prepare("SELECT id, name, 0 AS price, '' AS unit FROM stuffs WHERE name LIKE ? LIMIT 15");
                $stmt2->execute([$q]);
                $rows = $stmt2->fetchAll();
            }
            echo json_encode(['ok' => true, 'results' => $rows]);
            exit;
        }

        // ---- ذخیره فاکتور (ایجاد / ویرایش) --------------------------------
        if ($action === 'save') {
            // اعتبارسنجی CSRF
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'خطای امنیتی. صفحه را رفرش کنید.']);
                exit;
            }

            $editId    = (int)($_POST['invoice_id'] ?? 0);
            $personId  = (int)($_POST['person_id'] ?? 0);
            $invDate   = trim(faToEn($_POST['invoice_date'] ?? ''));
            $dueDate   = trim(faToEn($_POST['due_date'] ?? '')) ?: null;
            $notes     = trim($_POST['notes'] ?? '');
            $items     = $_POST['items'] ?? [];

            if (!$personId) {
                echo json_encode(['ok' => false, 'msg' => 'انتخاب مشتری الزامی است.']);
                exit;
            }
            if (empty($items)) {
                echo json_encode(['ok' => false, 'msg' => 'حداقل یک ردیف کالا/خدمات الزامی است.']);
                exit;
            }
            if (!$invDate) {
                echo json_encode(['ok' => false, 'msg' => 'تاریخ فاکتور الزامی است.']);
                exit;
            }

            // اعتبارسنجی تاریخ میلادی (اگر شمسی بود تبدیل نمی‌کنیم — کاربر باید ISO وارد کند)
            // تاریخ با فرمت Y-m-d باید وارد شود
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invDate)) {
                echo json_encode(['ok' => false, 'msg' => 'فرمت تاریخ نادرست است. لطفاً از تقویم استفاده کنید.']);
                exit;
            }

            // محاسبه مبالغ
            $subtotal       = 0;
            $totalDiscount  = 0;
            $totalTax       = 0;
            $totalAmount    = 0;
            $cleanItems     = [];

            foreach ($items as $item) {
                $desc        = trim($item['description'] ?? '');
                if (!$desc) continue;
                $qty         = (float)faToEn($item['quantity'] ?? 1);
                $unitPrice   = (int)str_replace([',', ' '], '', faToEn($item['unit_price'] ?? 0));
                $discPct     = (float)faToEn($item['discount_pct'] ?? 0);
                $taxPct      = (float)faToEn($item['tax_pct'] ?? 9);
                $commodityId = (int)($item['commodity_id'] ?? 0) ?: null;

                $lineGross   = (int)round($qty * $unitPrice);
                $lineDisc    = (int)round($lineGross * $discPct / 100);
                $lineAfterD  = $lineGross - $lineDisc;
                $lineTax     = (int)round($lineAfterD * $taxPct / 100);
                $lineTotal   = $lineAfterD + $lineTax;

                $subtotal      += $lineGross;
                $totalDiscount += $lineDisc;
                $totalTax      += $lineTax;
                $totalAmount   += $lineTotal;

                $cleanItems[] = [
                    'commodity_id'    => $commodityId,
                    'description'     => $desc,
                    'quantity'        => $qty,
                    'unit_price'      => $unitPrice,
                    'discount_pct'    => $discPct,
                    'discount_amount' => $lineDisc,
                    'tax_pct'         => $taxPct,
                    'tax_amount'      => $lineTax,
                    'total'           => $lineTotal,
                ];
            }

            if (empty($cleanItems)) {
                echo json_encode(['ok' => false, 'msg' => 'ردیف‌های معتبری وارد نشده.']);
                exit;
            }

            $pdo->beginTransaction();

            if ($editId > 0) {
                // ویرایش — حذف ردیف‌های قبلی و ویرایش فاکتور
                $pdo->prepare("DELETE FROM fin_invoice_items WHERE invoice_id = ?")->execute([$editId]);
                $stmtUpd = $pdo->prepare(
                    "UPDATE fin_invoices SET
                        person_id = ?, invoice_date = ?, due_date = ?,
                        subtotal = ?, discount_amount = ?, tax_amount = ?,
                        total_amount = ?, notes = ?, updated_at = NOW()
                     WHERE id = ? AND is_deleted = 0"
                );
                $stmtUpd->execute([
                    $personId, $invDate, $dueDate,
                    $subtotal, $totalDiscount, $totalTax, $totalAmount,
                    $notes, $editId,
                ]);
                $invoiceId     = $editId;
                $invoiceNumber = $pdo->query("SELECT invoice_number FROM fin_invoices WHERE id = $editId")->fetchColumn();
            } else {
                // ایجاد — شماره فاکتور خودکار: F-YYYY-NNNN
                $yearNum = (int)date('Y'); // سال میلادی (یا می‌توان شمسی کرد)
                try {
                    $lastNum = $pdo->query(
                        "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED))
                         FROM fin_invoices WHERE invoice_number LIKE 'F-{$yearNum}-%'"
                    )->fetchColumn();
                } catch (Throwable $e) { $lastNum = 0; }
                $seq           = (int)$lastNum + 1;
                $invoiceNumber = 'F-' . $yearNum . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

                $stmtIns = $pdo->prepare(
                    "INSERT INTO fin_invoices
                        (invoice_number, invoice_date, due_date, type, person_id,
                         fiscal_year_id, user_id, subtotal, discount_amount, tax_amount,
                         total_amount, paid_amount, notes, created_at)
                     VALUES (?, ?, ?, 'sell', ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())"
                );
                $stmtIns->execute([
                    $invoiceNumber, $invDate, $dueDate, $personId,
                    $fiscalYearId, $userId,
                    $subtotal, $totalDiscount, $totalTax, $totalAmount, $notes,
                ]);
                $invoiceId = (int)$pdo->lastInsertId();
            }

            // درج ردیف‌های فاکتور
            $stmtItem = $pdo->prepare(
                "INSERT INTO fin_invoice_items
                    (invoice_id, commodity_id, description, quantity, unit_price,
                     discount_pct, discount_amount, tax_pct, tax_amount, total, row_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($cleanItems as $idx => $item) {
                $stmtItem->execute([
                    $invoiceId, $item['commodity_id'], $item['description'],
                    $item['quantity'], $item['unit_price'],
                    $item['discount_pct'], $item['discount_amount'],
                    $item['tax_pct'], $item['tax_amount'],
                    $item['total'], $idx + 1,
                ]);
            }

            // ثبت سند حسابداری دوطرفه
            try {
                $stmtDoc = $pdo->prepare(
                    "INSERT INTO fin_docs (doc_number, doc_date, type, description, fiscal_year_id, user_id, created_at)
                     VALUES (?, ?, 'sell_invoice', ?, ?, ?, NOW())"
                );
                $docDesc = "فاکتور فروش شماره {$invoiceNumber}";
                $stmtDoc->execute([$invoiceNumber, $invDate, $docDesc, $fiscalYearId, $userId]);
                $docId = (int)$pdo->lastInsertId();

                // ردیف‌های سند
                $docRows = [
                    ['1103', 'حساب دریافتنی', $totalAmount,  0,            'بدهکار — فروش به مشتری'],
                    ['4001', 'درآمد فروش',    0,             $subtotal - $totalDiscount, 'بستانکار — درآمد فروش'],
                    ['2103', 'مالیات پرداختنی', 0,           $totalTax,    'بستانکار — مالیات ارزش افزوده'],
                ];
                $stmtRow = $pdo->prepare(
                    "INSERT INTO fin_doc_rows (doc_id, account_code, account_name, debit, credit, description, row_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($docRows as $i => $dr) {
                    if ($dr[3] == 0 && $dr[2] == 0) continue; // ردیف صفر را نمی‌زنیم
                    $stmtRow->execute([$docId, $dr[0], $dr[1], $dr[2], $dr[3], $dr[4], $i + 1]);
                }

                // به‌روزرسانی doc_id در فاکتور
                $pdo->prepare("UPDATE fin_invoices SET doc_id = ? WHERE id = ?")->execute([$docId, $invoiceId]);
            } catch (Throwable $docErr) {
                // سند ثبت نشد اما فاکتور ذخیره شد — ادامه می‌دهیم
            }

            $pdo->commit();

            echo json_encode([
                'ok'             => true,
                'invoice_id'     => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'msg'            => 'فاکتور با موفقیت ذخیره شد.',
            ]);
            exit;
        }

        // ---- دریافت یک فاکتور (برای ویرایش / مشاهده) ---------------------
        if ($action === 'get_one') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare(
                "SELECT i.*, COALESCE(p.company_name, p.name) AS person_name
                 FROM fin_invoices i
                 LEFT JOIN fin_persons p ON p.id = i.person_id
                 WHERE i.id = ? AND i.is_deleted = 0"
            );
            $stmt->execute([$id]);
            $inv = $stmt->fetch();
            if (!$inv) { echo json_encode(['ok' => false, 'msg' => 'فاکتور یافت نشد.']); exit; }

            $stmtItems = $pdo->prepare("SELECT * FROM fin_invoice_items WHERE invoice_id = ? ORDER BY row_order");
            $stmtItems->execute([$id]);
            $inv['items'] = $stmtItems->fetchAll();
            $inv['date_jalali'] = jdate('Y/m/d', $inv['invoice_date']);

            echo json_encode(['ok' => true, 'invoice' => $inv]);
            exit;
        }

        // ---- حذف نرم (soft delete) ----------------------------------------
        if ($action === 'delete') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'خطای امنیتی.']);
                exit;
            }
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE fin_invoices SET is_deleted = 1, updated_at = NOW() WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'فاکتور حذف شد.']);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'درخواست نامعتبر.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('fin_invoice_sell error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'خطای سرور: ' . $e->getMessage()]);
    }
    exit;
}

// ===========================================================
// رندر صفحه HTML
// ===========================================================
$basePath  = '../../';
$pageTitle = 'فاکتور فروش';
$csrfToken = csrf_token();

// آمار اولیه برای کارت‌ها
$statsInit = ['count' => 0, 'total_sum' => '0', 'paid_sum' => '0', 'remain' => '0'];
try {
    $s = $pdo->query(
        "SELECT COUNT(*) cnt, COALESCE(SUM(total_amount),0) ts, COALESCE(SUM(paid_amount),0) ps
         FROM fin_invoices WHERE is_deleted = 0 AND type = 'sell'"
    )->fetch();
    $statsInit = [
        'count'     => (int)$s['cnt'],
        'total_sum' => number_format($s['ts']),
        'paid_sum'  => number_format($s['ps']),
        'remain'    => number_format($s['ts'] - $s['ps']),
    ];
} catch (Throwable $e) {}

$extraCss = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';
require_once __DIR__ . '/../../templates/header.php';
?>

<style>
/* =========================================================
   استایل‌های اختصاصی صفحه فاکتور فروش
   ========================================================= */

/* --- هدر صفحه --- */
.inv-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 26px;
    flex-wrap: wrap;
    gap: 12px;
}
.inv-page-title {
    display: flex;
    align-items: center;
    gap: 12px;
}
.inv-page-icon {
    width: 48px; height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, #1e3a8a, #2563eb);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
    color: #fff;
    box-shadow: 0 6px 16px rgba(37,99,235,0.35);
}
.inv-page-title h1 {
    font-size: 1.3rem;
    font-weight: 900;
    color: #1e293b;
    margin: 0;
}
.inv-page-title p {
    font-size: 0.8rem;
    color: #64748b;
    margin: 3px 0 0;
}

/* --- فرم فاکتور --- */
.inv-form-wrap {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}
.inv-form-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    padding: 20px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #fff;
}
.inv-form-header-title {
    font-size: 1.05rem;
    font-weight: 900;
}
.inv-form-header-number {
    font-size: 1rem;
    font-weight: 700;
    direction: ltr;
    background: rgba(255,255,255,0.18);
    padding: 6px 14px;
    border-radius: 8px;
    letter-spacing: 0.5px;
}
.inv-form-body {
    padding: 28px;
}
.inv-section-title {
    font-size: 0.85rem;
    font-weight: 900;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.inv-section-title::before {
    content: '';
    display: inline-block;
    width: 4px; height: 16px;
    background: #2563eb;
    border-radius: 2px;
}
.inv-meta-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    gap: 18px;
    margin-bottom: 28px;
}

/* --- جدول ردیف‌های فاکتور --- */
.inv-items-section {
    margin-bottom: 24px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
}
.inv-items-thead {
    background: #f8fafc;
    display: grid;
    grid-template-columns: 36px 1fr 90px 130px 80px 80px 110px 44px;
    gap: 0;
    padding: 10px 14px;
    border-bottom: 2px solid #e2e8f0;
}
.inv-items-thead span {
    font-size: 0.75rem;
    font-weight: 800;
    color: #64748b;
    white-space: nowrap;
}
.inv-item-row {
    display: grid;
    grid-template-columns: 36px 1fr 90px 130px 80px 80px 110px 44px;
    gap: 0;
    padding: 8px 14px;
    border-bottom: 1px solid #f1f5f9;
    align-items: center;
    transition: background 0.15s;
}
.inv-item-row:last-child { border-bottom: none; }
.inv-item-row:hover { background: #fafbff; }
.inv-item-row .row-num {
    font-size: 0.78rem;
    color: #94a3b8;
    font-weight: 700;
    text-align: center;
}
.inv-item-input {
    width: 100%;
    border: 1.5px solid transparent;
    border-radius: 6px;
    padding: 6px 8px;
    font-family: 'Vazirmatn', Tahoma, sans-serif;
    font-size: 0.82rem;
    color: #1e293b;
    background: transparent;
    transition: border-color 0.2s, background 0.2s;
    direction: rtl;
}
.inv-item-input.num { direction: ltr; text-align: right; }
.inv-item-input:focus {
    outline: none;
    border-color: #2563eb;
    background: #eff6ff;
}
.inv-item-total {
    font-size: 0.82rem;
    font-weight: 800;
    color: #1e293b;
    direction: ltr;
    text-align: right;
    padding: 0 4px;
}
.inv-row-del {
    width: 30px; height: 30px;
    border: none;
    background: #fff1f2;
    color: #e11d48;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    display: flex; align-items: center; justify-content: center;
    transition: background 0.2s;
    margin: auto;
}
.inv-row-del:hover { background: #fecdd3; }
.inv-add-row-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    border: 2px dashed #cbd5e1;
    border-radius: 8px;
    background: none;
    color: #64748b;
    font-family: 'Vazirmatn', Tahoma, sans-serif;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    margin: 12px 14px;
}
.inv-add-row-btn:hover { border-color: #2563eb; color: #2563eb; background: #eff6ff; }

/* --- خلاصه مبالغ --- */
.inv-summary-box {
    background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
    border: 1px solid #dbeafe;
    border-radius: 14px;
    padding: 22px 26px;
    min-width: 300px;
}
.inv-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 0;
    font-size: 0.875rem;
    color: #475569;
    border-bottom: 1px dashed #e2e8f0;
}
.inv-summary-row:last-child { border-bottom: none; }
.inv-summary-row .lbl { font-weight: 600; }
.inv-summary-row .val { font-weight: 700; direction: ltr; }
.inv-summary-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0 0;
    margin-top: 4px;
}
.inv-summary-total .lbl {
    font-size: 0.95rem;
    font-weight: 900;
    color: #1e293b;
}
.inv-summary-total .val {
    font-size: 1.3rem;
    font-weight: 900;
    color: #059669;
    direction: ltr;
}
.inv-bottom-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    margin-top: 20px;
    flex-wrap: wrap;
}
.inv-notes-wrap { flex: 1; min-width: 260px; }

/* --- Autocomplete dropdown --- */
.ac-wrap { position: relative; }
.ac-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    right: 0; left: 0;
    background: #fff;
    border: 1.5px solid #dbeafe;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    z-index: 500;
    max-height: 240px;
    overflow-y: auto;
}
.ac-dropdown.open { display: block; }
.ac-item {
    padding: 10px 14px;
    font-size: 0.85rem;
    cursor: pointer;
    color: #1e293b;
    transition: background 0.15s;
}
.ac-item:hover { background: #eff6ff; color: #2563eb; }
.ac-item.no-result { color: #94a3b8; cursor: default; }
.ac-item.no-result:hover { background: transparent; color: #94a3b8; }

/* --- بج‌های وضعیت فاکتور --- */
.inv-status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
}
.inv-status-badge.paid    { background: #ecfdf5; color: #059669; border: 1px solid #6ee7b7; }
.inv-status-badge.partial { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.inv-status-badge.unpaid  { background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; }

/* --- اسپینر --- */
.inv-loading {
    text-align: center;
    padding: 40px;
    color: #94a3b8;
    font-size: 0.9rem;
}
.inv-spinner {
    display: inline-block;
    width: 28px; height: 28px;
    border: 3px solid #e2e8f0;
    border-top-color: #2563eb;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    margin-bottom: 10px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* --- print --- */
@media print {
    .inv-no-print { display: none !important; }
    .inv-form-wrap { box-shadow: none; border: none; }
    .inv-form-header { background: #1e3a8a !important; -webkit-print-color-adjust: exact; }
}

/* --- ریسپانسیو --- */
@media (max-width: 900px) {
    .inv-meta-grid { grid-template-columns: 1fr 1fr; }
    .inv-items-thead,
    .inv-item-row {
        grid-template-columns: 30px 1fr 70px 100px 60px 60px 90px 36px;
    }
    .inv-bottom-row { flex-direction: column; }
    .inv-summary-box { width: 100%; }
}
@media (max-width: 600px) {
    .inv-items-thead { display: none; }
    .inv-item-row {
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto auto auto;
        padding: 10px;
        gap: 6px;
    }
    .inv-meta-grid { grid-template-columns: 1fr; }
}
</style>

<div class="main-content">

    <!-- هدر صفحه -->
    <div class="inv-page-header inv-no-print">
        <div class="inv-page-title">
            <div class="inv-page-icon">🧾</div>
            <div>
                <h1>فاکتور فروش</h1>
                <p>صدور، مشاهده و مدیریت فاکتورهای فروش</p>
            </div>
        </div>
        <button class="fin-btn fin-btn-primary" onclick="switchTab('new')">
            ➕ فاکتور جدید
        </button>
    </div>

    <!-- تب‌ها -->
    <div class="fin-tabs inv-no-print" id="mainTabs">
        <button class="fin-tab active" data-tab="list" onclick="switchTab('list')">
            📋 لیست فاکتورها
        </button>
        <button class="fin-tab" data-tab="new" onclick="switchTab('new')">
            ➕ فاکتور جدید
        </button>
    </div>

    <!-- ======================================================
         تب ۱: لیست فاکتورها
         ====================================================== -->
    <div id="tab-list">

        <!-- کارت‌های آماری -->
        <div class="fin-stats-grid" id="statsGrid">
            <div class="fin-stat-card blue">
                <div class="fin-stat-icon">📋</div>
                <div>
                    <div class="fin-stat-label">تعداد کل فاکتورها</div>
                    <div class="fin-stat-value" id="stat-count"><?= number_format($statsInit['count']) ?></div>
                </div>
            </div>
            <div class="fin-stat-card green">
                <div class="fin-stat-icon">💰</div>
                <div>
                    <div class="fin-stat-label">مجموع فروش</div>
                    <div class="fin-stat-value" id="stat-total"><?= $statsInit['total_sum'] ?></div>
                    <div class="fin-stat-unit">ریال</div>
                </div>
            </div>
            <div class="fin-stat-card amber">
                <div class="fin-stat-icon">✅</div>
                <div>
                    <div class="fin-stat-label">وصول شده</div>
                    <div class="fin-stat-value" id="stat-paid"><?= $statsInit['paid_sum'] ?></div>
                    <div class="fin-stat-unit">ریال</div>
                </div>
            </div>
            <div class="fin-stat-card rose">
                <div class="fin-stat-icon">⏳</div>
                <div>
                    <div class="fin-stat-label">مانده مطالبات</div>
                    <div class="fin-stat-value" id="stat-remain"><?= $statsInit['remain'] ?></div>
                    <div class="fin-stat-unit">ریال</div>
                </div>
            </div>
        </div>

        <!-- فیلتر -->
        <div class="fin-filter-bar inv-no-print">
            <div class="fin-filter-group">
                <label>تاریخ از</label>
                <input type="date" class="fin-input" id="filter-date-from" placeholder="از تاریخ">
            </div>
            <div class="fin-filter-group">
                <label>تاریخ تا</label>
                <input type="date" class="fin-input" id="filter-date-to" placeholder="تا تاریخ">
            </div>
            <div class="fin-filter-group">
                <label>مشتری</label>
                <input type="text" class="fin-input" id="filter-person" placeholder="جستجوی مشتری..." readonly
                    style="cursor:pointer" onclick="openPersonFilterPicker()">
                <input type="hidden" id="filter-person-id">
            </div>
            <div class="fin-filter-group">
                <label>وضعیت پرداخت</label>
                <select class="fin-select" id="filter-status">
                    <option value="">همه</option>
                    <option value="paid">پرداخت کامل</option>
                    <option value="partial">پرداخت ناقص</option>
                    <option value="unpaid">پرداخت نشده</option>
                </select>
            </div>
            <div>
                <button class="fin-btn fin-btn-primary" onclick="loadList(1)">🔍 اعمال فیلتر</button>
                <button class="fin-btn fin-btn-outline" onclick="resetFilter()" style="margin-right:8px">پاک کردن</button>
            </div>
        </div>

        <!-- جدول -->
        <div class="fin-panel">
            <div id="listContainer">
                <div class="inv-loading">
                    <div class="inv-spinner"></div>
                    <div>در حال بارگذاری...</div>
                </div>
            </div>
            <!-- صفحه‌بندی -->
            <div class="fin-pagination inv-no-print" id="pagination" style="display:none">
                <span id="pag-info"></span>
                <div style="display:flex;gap:6px">
                    <button class="fin-btn fin-btn-outline fin-btn-sm" id="btn-prev" onclick="changePage(-1)">‹ قبلی</button>
                    <button class="fin-btn fin-btn-outline fin-btn-sm" id="btn-next" onclick="changePage(1)">بعدی ›</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================
         تب ۲: فرم فاکتور جدید / ویرایش
         ====================================================== -->
    <div id="tab-new" style="display:none">
        <div class="inv-form-wrap">

            <!-- هدر فرم -->
            <div class="inv-form-header">
                <div class="inv-form-header-title" id="formHeaderTitle">
                    🧾 فاکتور فروش جدید
                </div>
                <div class="inv-form-header-number" id="formInvNumber">
                    شماره: خودکار
                </div>
            </div>

            <!-- بدنه فرم -->
            <div class="inv-form-body">

                <!-- بخش اطلاعات پایه -->
                <div class="inv-section-title">اطلاعات فاکتور</div>
                <div class="inv-meta-grid">
                    <!-- انتخاب مشتری (autocomplete) -->
                    <div class="fin-form-group">
                        <label>مشتری <span class="req">*</span></label>
                        <div class="ac-wrap">
                            <input type="text" class="fin-input" id="personSearch"
                                placeholder="نام یا شرکت مشتری را تایپ کنید..."
                                autocomplete="off" oninput="onPersonSearch(this.value)">
                            <input type="hidden" id="personId" name="person_id">
                            <div class="ac-dropdown" id="personDropdown"></div>
                        </div>
                    </div>

                    <!-- تاریخ فاکتور -->
                    <div class="fin-form-group">
                        <label>تاریخ فاکتور <span class="req">*</span></label>
                        <input type="date" class="fin-input" id="invDate" name="invoice_date">
                    </div>

                    <!-- تاریخ سررسید -->
                    <div class="fin-form-group">
                        <label>تاریخ سررسید</label>
                        <input type="date" class="fin-input" id="dueDate" name="due_date">
                    </div>
                </div>

                <!-- بخش ردیف‌های فاکتور -->
                <div class="inv-section-title">ردیف‌های کالا / خدمات</div>
                <div class="inv-items-section">
                    <div class="inv-items-thead">
                        <span>#</span>
                        <span>شرح کالا / خدمت</span>
                        <span>تعداد</span>
                        <span>قیمت واحد (ریال)</span>
                        <span>تخفیف٪</span>
                        <span>مالیات٪</span>
                        <span>مبلغ (ریال)</span>
                        <span></span>
                    </div>
                    <div id="invItemsBody">
                        <!-- ردیف‌ها اینجا رندر می‌شوند -->
                    </div>
                    <button class="inv-add-row-btn" onclick="addRow()">
                        ➕ افزودن ردیف
                    </button>
                </div>

                <!-- خلاصه و توضیحات -->
                <div class="inv-bottom-row">
                    <!-- توضیحات -->
                    <div class="inv-notes-wrap">
                        <div class="inv-section-title">توضیحات</div>
                        <textarea class="fin-textarea" id="invNotes" rows="5"
                            placeholder="هر توضیحی درباره این فاکتور..."></textarea>
                    </div>

                    <!-- خلاصه مبالغ -->
                    <div class="inv-summary-box">
                        <div class="inv-summary-row">
                            <span class="lbl">جمع کل اقلام</span>
                            <span class="val" id="sum-subtotal">۰ ریال</span>
                        </div>
                        <div class="inv-summary-row">
                            <span class="lbl">مجموع تخفیف</span>
                            <span class="val" id="sum-discount" style="color:#e11d48">— ۰ ریال</span>
                        </div>
                        <div class="inv-summary-row">
                            <span class="lbl">مالیات ارزش افزوده</span>
                            <span class="val" id="sum-tax">+ ۰ ریال</span>
                        </div>
                        <div style="border-top: 2px solid #2563eb; margin: 10px 0 4px;"></div>
                        <div class="inv-summary-total">
                            <span class="lbl">مبلغ نهایی</span>
                            <span class="val" id="sum-total">۰ ریال</span>
                        </div>
                    </div>
                </div>

                <!-- دکمه‌های عمل -->
                <div style="display:flex; gap:10px; margin-top:28px; flex-wrap:wrap; border-top:1px solid #f1f5f9; padding-top:20px"
                     class="inv-no-print">
                    <input type="hidden" id="editInvoiceId" value="0">
                    <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button class="fin-btn fin-btn-success" style="font-size:1rem;padding:11px 28px"
                        onclick="saveInvoice()">
                        💾 ثبت فاکتور
                    </button>
                    <button class="fin-btn fin-btn-outline" onclick="printInvoice()">
                        🖨️ چاپ
                    </button>
                    <button class="fin-btn fin-btn-outline" onclick="resetForm(); switchTab('list')">
                        ❌ انصراف
                    </button>
                </div>

            </div><!-- /inv-form-body -->
        </div><!-- /inv-form-wrap -->
    </div><!-- /tab-new -->

</div><!-- /main-content -->

<!-- مودال مشاهده فاکتور -->
<div class="fin-drawer-overlay" id="viewOverlay" onclick="closeViewModal()"></div>
<div class="fin-drawer" id="viewDrawer" style="width:620px;max-width:98vw">
    <div class="fin-drawer-header">
        <span id="viewDrawerTitle">مشاهده فاکتور</span>
        <button class="fin-drawer-close" onclick="closeViewModal()">×</button>
    </div>
    <div class="fin-drawer-body" id="viewDrawerBody">
        <div class="inv-loading"><div class="inv-spinner"></div></div>
    </div>
</div>

<script>
/* ============================================================
   جاوااسکریپت صفحه فاکتور فروش
   ============================================================ */

var currentPage = 1;
var totalPages  = 1;
var rowCounter  = 0;
var editMode    = false;
var personDebounceTimer = null;
var commodityDebounceTimers = {};

// ---- تابع تبدیل عدد به فارسی با جداکننده هزار ----
function numFa(n) {
    n = Math.round(parseFloat(n) || 0);
    return n.toLocaleString('fa-IR');
}
function numEn(s) {
    return String(s).replace(/[۰-۹]/g, d => d.charCodeAt(0) - 1776)
                    .replace(/[٠-٩]/g, d => d.charCodeAt(0) - 1632)
                    .replace(/,/g, '');
}
function rawNum(inp) {
    return parseInt(numEn(inp.value) || '0', 10);
}

// ---- سوئیچ تب ----
function switchTab(tab) {
    document.getElementById('tab-list').style.display = (tab === 'list') ? '' : 'none';
    document.getElementById('tab-new').style.display  = (tab === 'new')  ? '' : 'none';
    document.querySelectorAll('.fin-tab').forEach(function(el) {
        el.classList.toggle('active', el.dataset.tab === tab);
    });
    if (tab === 'list') loadList(currentPage);
}

// ============================================================
// تب لیست
// ============================================================
function loadList(page) {
    currentPage = page;
    document.getElementById('listContainer').innerHTML =
        '<div class="inv-loading"><div class="inv-spinner"></div><div>در حال بارگذاری...</div></div>';

    var fd = new FormData();
    fd.append('action', 'list');
    fd.append('page', page);
    fd.append('date_from', document.getElementById('filter-date-from').value);
    fd.append('date_to',   document.getElementById('filter-date-to').value);
    fd.append('person_id', document.getElementById('filter-person-id').value || '');
    fd.append('status',    document.getElementById('filter-status').value);

    ajaxPost(fd, function(res) {
        if (!res.ok) { showListError(res.msg); return; }

        // آمار کارت‌ها
        document.getElementById('stat-count').textContent  = numFa(res.stats.count);
        document.getElementById('stat-total').textContent  = res.stats.total_sum;
        document.getElementById('stat-paid').textContent   = res.stats.paid_sum;
        document.getElementById('stat-remain').textContent = res.stats.remain;

        totalPages = res.pagination.total_pages;
        renderTable(res.rows);
        renderPagination(res.pagination);
    });
}

function renderTable(rows) {
    if (!rows || !rows.length) {
        document.getElementById('listContainer').innerHTML =
            '<div class="fin-empty"><div class="empty-icon">🧾</div><p>هیچ فاکتوری یافت نشد.</p></div>';
        document.getElementById('pagination').style.display = 'none';
        return;
    }
    var html = '<div style="overflow-x:auto"><table class="fin-table"><thead><tr>' +
        '<th>شماره فاکتور</th><th>تاریخ</th><th>مشتری</th>' +
        '<th>مبلغ کل (ریال)</th><th>پرداخت شده (ریال)</th><th>مانده (ریال)</th>' +
        '<th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';

    rows.forEach(function(r) {
        var badge = '<span class="inv-status-badge ' + r.status_class + '">' + r.status_label + '</span>';
        html += '<tr>' +
            '<td><strong style="color:#2563eb;direction:ltr;display:inline-block">' + r.invoice_number + '</strong></td>' +
            '<td style="direction:ltr">' + r.date_jalali + '</td>' +
            '<td>' + escHtml(r.person_name || '—') + '</td>' +
            '<td style="direction:ltr;font-weight:700">' + r.total_fmt + '</td>' +
            '<td style="direction:ltr;color:#059669">' + r.paid_fmt + '</td>' +
            '<td style="direction:ltr;color:#e11d48">' + r.remain_fmt + '</td>' +
            '<td>' + badge + '</td>' +
            '<td>' +
                '<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="viewInvoice(' + r.id + ')">👁 مشاهده</button> ' +
                '<button class="fin-btn fin-btn-primary fin-btn-sm" onclick="editInvoice(' + r.id + ')">✏️ ویرایش</button> ' +
                '<button class="fin-btn fin-btn-danger fin-btn-sm" onclick="deleteInvoice(' + r.id + ', \'' + escHtml(r.invoice_number) + '\')">🗑 حذف</button>' +
            '</td>' +
        '</tr>';
    });
    html += '</tbody></table></div>';
    document.getElementById('listContainer').innerHTML = html;
    document.getElementById('pagination').style.display = '';
}

function renderPagination(pag) {
    document.getElementById('pag-info').textContent =
        'نمایش صفحه ' + pag.page + ' از ' + pag.total_pages +
        ' — مجموع ' + numFa(pag.total_rows) + ' فاکتور';
    document.getElementById('btn-prev').disabled = pag.page <= 1;
    document.getElementById('btn-next').disabled = pag.page >= pag.total_pages;
}

function changePage(dir) {
    var newPage = currentPage + dir;
    if (newPage < 1 || newPage > totalPages) return;
    loadList(newPage);
}

function resetFilter() {
    document.getElementById('filter-date-from').value = '';
    document.getElementById('filter-date-to').value   = '';
    document.getElementById('filter-person').value    = '';
    document.getElementById('filter-person-id').value = '';
    document.getElementById('filter-status').value    = '';
    loadList(1);
}

function showListError(msg) {
    document.getElementById('listContainer').innerHTML =
        '<div class="fin-alert danger">⛔ ' + escHtml(msg) + '</div>';
}

// ---- فیلتر مشتری ----
function openPersonFilterPicker() {
    var q = prompt('جستجوی مشتری (نام یا شرکت):');
    if (q === null) return;
    var fd = new FormData(); fd.append('action','search_persons'); fd.append('q', q);
    ajaxPost(fd, function(res) {
        if (!res.ok || !res.results.length) { alert('مشتری یافت نشد.'); return; }
        var opts = res.results.map(function(p) { return p.id + ': ' + p.display_name; }).join('\n');
        var chosen = prompt('یکی را انتخاب کنید (شناسه را وارد کنید):\n' + opts);
        if (!chosen) return;
        var found = res.results.find(function(p) { return p.id == parseInt(chosen); });
        if (found) {
            document.getElementById('filter-person').value    = found.display_name;
            document.getElementById('filter-person-id').value = found.id;
            loadList(1);
        }
    });
}

// ============================================================
// مشاهده فاکتور
// ============================================================
function viewInvoice(id) {
    document.getElementById('viewDrawerTitle').textContent = 'در حال بارگذاری...';
    document.getElementById('viewDrawerBody').innerHTML =
        '<div class="inv-loading"><div class="inv-spinner"></div></div>';
    document.getElementById('viewOverlay').classList.add('open');
    document.getElementById('viewDrawer').classList.add('open');

    var fd = new FormData(); fd.append('action','get_one'); fd.append('id', id);
    ajaxPost(fd, function(res) {
        if (!res.ok) {
            document.getElementById('viewDrawerBody').innerHTML =
                '<div class="fin-alert danger">' + res.msg + '</div>';
            return;
        }
        var inv = res.invoice;
        document.getElementById('viewDrawerTitle').textContent = 'فاکتور ' + inv.invoice_number;

        var itemsHtml = '';
        (inv.items || []).forEach(function(it, idx) {
            itemsHtml += '<tr>' +
                '<td>' + (idx+1) + '</td>' +
                '<td>' + escHtml(it.description) + '</td>' +
                '<td style="direction:ltr">' + numFa(it.quantity) + '</td>' +
                '<td style="direction:ltr">' + numFa(it.unit_price) + '</td>' +
                '<td style="direction:ltr">' + numFa(it.discount_amount) + '</td>' +
                '<td style="direction:ltr">' + numFa(it.tax_amount) + '</td>' +
                '<td style="direction:ltr;font-weight:800">' + numFa(it.total) + '</td>' +
            '</tr>';
        });

        var statusClass = 'unpaid', statusLabel = 'پرداخت نشده';
        if (parseInt(inv.total_amount)>0 && parseInt(inv.paid_amount)>=parseInt(inv.total_amount)) {
            statusClass='paid'; statusLabel='پرداخت کامل';
        } else if (parseInt(inv.paid_amount)>0) {
            statusClass='partial'; statusLabel='پرداخت ناقص';
        }

        document.getElementById('viewDrawerBody').innerHTML = `
            <div style="margin-bottom:20px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
                    <div>
                        <div style="font-size:0.78rem;color:#64748b">مشتری</div>
                        <div style="font-weight:800;font-size:1rem;color:#1e293b">${escHtml(inv.person_name||'—')}</div>
                    </div>
                    <div style="text-align:left">
                        <div style="font-size:0.78rem;color:#64748b">وضعیت</div>
                        <span class="inv-status-badge ${statusClass}">${statusLabel}</span>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;background:#f8fafc;padding:14px;border-radius:10px;margin-bottom:16px">
                    <div><span style="color:#64748b;font-size:0.78rem">شماره فاکتور</span><br><strong style="direction:ltr;display:inline-block">${escHtml(inv.invoice_number)}</strong></div>
                    <div><span style="color:#64748b;font-size:0.78rem">تاریخ</span><br><strong style="direction:ltr;display:inline-block">${escHtml(inv.date_jalali)}</strong></div>
                    ${inv.due_date ? '<div><span style="color:#64748b;font-size:0.78rem">سررسید</span><br><strong>'+escHtml(inv.due_date)+'</strong></div>' : ''}
                </div>
            </div>
            <div style="overflow-x:auto;margin-bottom:18px">
                <table class="fin-table">
                    <thead><tr>
                        <th>#</th><th>شرح</th><th>تعداد</th>
                        <th>قیمت واحد</th><th>تخفیف</th><th>مالیات</th><th>مبلغ</th>
                    </tr></thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
            </div>
            <div class="inv-summary-box" style="max-width:320px;margin-right:auto">
                <div class="inv-summary-row"><span class="lbl">جمع اقلام</span><span class="val">${numFa(inv.subtotal)} ریال</span></div>
                <div class="inv-summary-row"><span class="lbl">تخفیف</span><span class="val" style="color:#e11d48">— ${numFa(inv.discount_amount)} ریال</span></div>
                <div class="inv-summary-row"><span class="lbl">مالیات</span><span class="val">+ ${numFa(inv.tax_amount)} ریال</span></div>
                <div style="border-top:2px solid #2563eb;margin:10px 0 4px"></div>
                <div class="inv-summary-total">
                    <span class="lbl">مبلغ نهایی</span>
                    <span class="val">${numFa(inv.total_amount)} ریال</span>
                </div>
                <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #e2e8f0">
                    <div class="inv-summary-row"><span class="lbl">پرداخت شده</span><span class="val" style="color:#059669">${numFa(inv.paid_amount)} ریال</span></div>
                    <div class="inv-summary-row"><span class="lbl">مانده</span><span class="val" style="color:#e11d48">${numFa(Math.max(0, inv.total_amount - inv.paid_amount))} ریال</span></div>
                </div>
            </div>
            ${inv.notes ? '<div style="margin-top:16px;padding:12px;background:#fffbeb;border-radius:8px;font-size:0.85rem;color:#92400e"><strong>توضیحات:</strong> ' + escHtml(inv.notes) + '</div>' : ''}
        `;
    });
}

function closeViewModal() {
    document.getElementById('viewOverlay').classList.remove('open');
    document.getElementById('viewDrawer').classList.remove('open');
}

// ============================================================
// ویرایش فاکتور
// ============================================================
function editInvoice(id) {
    var fd = new FormData(); fd.append('action','get_one'); fd.append('id', id);
    ajaxPost(fd, function(res) {
        if (!res.ok) { alert(res.msg); return; }
        var inv = res.invoice;

        resetForm();
        editMode = true;
        document.getElementById('editInvoiceId').value = inv.id;
        document.getElementById('personSearch').value  = inv.person_name || '';
        document.getElementById('personId').value      = inv.person_id;
        document.getElementById('invDate').value       = inv.invoice_date;
        document.getElementById('dueDate').value       = inv.due_date || '';
        document.getElementById('invNotes').value      = inv.notes || '';
        document.getElementById('formHeaderTitle').textContent = '✏️ ویرایش فاکتور';
        document.getElementById('formInvNumber').textContent   = 'شماره: ' + inv.invoice_number;

        (inv.items || []).forEach(function(it) {
            addRow(it.commodity_id, it.description, it.quantity, it.unit_price,
                   it.discount_pct, it.tax_pct);
        });
        recalcAll();
        switchTab('new');
    });
}

// ============================================================
// حذف فاکتور
// ============================================================
function deleteInvoice(id, num) {
    if (!confirm('آیا از حذف فاکتور ' + num + ' اطمینان دارید؟\nاین عمل قابل بازگشت نیست.')) return;
    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('csrf_token', document.getElementById('csrfToken').value);
    ajaxPost(fd, function(res) {
        showToast(res.msg, res.ok ? 'success' : 'danger');
        if (res.ok) loadList(currentPage);
    });
}

// ============================================================
// فرم فاکتور جدید
// ============================================================
function resetForm() {
    editMode = false;
    rowCounter = 0;
    document.getElementById('editInvoiceId').value = '0';
    document.getElementById('personSearch').value  = '';
    document.getElementById('personId').value      = '';
    document.getElementById('invDate').value       = getTodayDate();
    document.getElementById('dueDate').value       = '';
    document.getElementById('invNotes').value      = '';
    document.getElementById('invItemsBody').innerHTML = '';
    document.getElementById('formHeaderTitle').textContent = '🧾 فاکتور فروش جدید';
    document.getElementById('formInvNumber').textContent   = 'شماره: خودکار';
    recalcAll();
    // یک ردیف خالی پیش‌فرض
    addRow();
}

function getTodayDate() {
    var d = new Date();
    return d.getFullYear() + '-' +
        String(d.getMonth()+1).padStart(2,'0') + '-' +
        String(d.getDate()).padStart(2,'0');
}

// ---- افزودن ردیف ----
function addRow(commodityId, description, quantity, unitPrice, discPct, taxPct) {
    rowCounter++;
    var idx = rowCounter;
    var desc   = description  || '';
    var qty    = quantity     || 1;
    var uprice = unitPrice    || 0;
    var disc   = discPct      !== undefined ? discPct  : 0;
    var tax    = taxPct       !== undefined ? taxPct   : 9;
    var cid    = commodityId  || '';

    var row = document.createElement('div');
    row.className = 'inv-item-row';
    row.id = 'row-' + idx;
    row.innerHTML = `
        <span class="row-num">${idx}</span>
        <div style="position:relative">
            <input type="text" class="inv-item-input" id="desc-${idx}"
                placeholder="شرح کالا یا خدمت..."
                value="${escHtml(desc)}"
                autocomplete="off"
                oninput="onCommoditySearch(${idx}, this.value)">
            <input type="hidden" id="cid-${idx}" value="${cid}">
            <div class="ac-dropdown" id="cdropdown-${idx}"></div>
        </div>
        <input type="number" class="inv-item-input num" id="qty-${idx}"
            value="${qty}" min="0.001" step="any"
            oninput="recalcRow(${idx})">
        <input type="text" class="inv-item-input num" id="uprice-${idx}"
            value="${uprice > 0 ? numFa(uprice) : ''}"
            placeholder="۰"
            oninput="formatPriceInput(this); recalcRow(${idx})">
        <input type="number" class="inv-item-input num" id="disc-${idx}"
            value="${disc}" min="0" max="100" step="0.01"
            oninput="recalcRow(${idx})">
        <input type="number" class="inv-item-input num" id="tax-${idx}"
            value="${tax}" min="0" max="100" step="0.01"
            oninput="recalcRow(${idx})">
        <div class="inv-item-total" id="total-${idx}">۰</div>
        <button class="inv-row-del" onclick="removeRow(${idx})" title="حذف ردیف">✕</button>
    `;
    document.getElementById('invItemsBody').appendChild(row);
    recalcRow(idx);

    // فوکوس روی شرح اگر ردیف جدید (خالی)
    if (!description) {
        setTimeout(function() {
            var el = document.getElementById('desc-' + idx);
            if (el) el.focus();
        }, 50);
    }
}

function removeRow(idx) {
    var row = document.getElementById('row-' + idx);
    if (row) row.remove();
    recalcAll();
}

// ---- فرمت ورودی قیمت ----
function formatPriceInput(inp) {
    var raw = parseInt(numEn(inp.value).replace(/\D/g,'')) || 0;
    var pos = inp.selectionStart;
    inp.value = numFa(raw);
    // تنظیم cursor
    try { inp.setSelectionRange(inp.value.length, inp.value.length); } catch(e) {}
}

// ---- محاسبه ردیف ----
function recalcRow(idx) {
    var upriceEl = document.getElementById('uprice-' + idx);
    var qtyEl    = document.getElementById('qty-' + idx);
    var discEl   = document.getElementById('disc-' + idx);
    var taxEl    = document.getElementById('tax-' + idx);
    var totalEl  = document.getElementById('total-' + idx);
    if (!upriceEl) return;

    var uprice = parseInt(numEn(upriceEl.value).replace(/\D/g,'')) || 0;
    var qty    = parseFloat(qtyEl.value)  || 0;
    var disc   = parseFloat(discEl.value) || 0;
    var tax    = parseFloat(taxEl.value)  || 0;

    var gross  = Math.round(qty * uprice);
    var dAmt   = Math.round(gross * disc / 100);
    var afterD = gross - dAmt;
    var tAmt   = Math.round(afterD * tax / 100);
    var total  = afterD + tAmt;

    totalEl.textContent = numFa(total);
    recalcAll();
}

// ---- محاسبه کل فاکتور ----
function recalcAll() {
    var subtotal = 0, discount = 0, taxAmt = 0, total = 0;
    document.querySelectorAll('#invItemsBody .inv-item-row').forEach(function(row) {
        var m = row.id.match(/row-(\d+)/);
        if (!m) return;
        var idx = m[1];
        var upriceEl = document.getElementById('uprice-' + idx);
        var qtyEl    = document.getElementById('qty-' + idx);
        var discEl   = document.getElementById('disc-' + idx);
        var taxEl    = document.getElementById('tax-' + idx);
        if (!upriceEl) return;

        var uprice = parseInt(numEn(upriceEl.value).replace(/\D/g,'')) || 0;
        var qty    = parseFloat(qtyEl.value)  || 0;
        var disc   = parseFloat(discEl.value) || 0;
        var tax    = parseFloat(taxEl.value)  || 0;

        var gross = Math.round(qty * uprice);
        var dAmt  = Math.round(gross * disc / 100);
        var afterD= gross - dAmt;
        var tAmt  = Math.round(afterD * tax / 100);

        subtotal += gross;
        discount += dAmt;
        taxAmt   += tAmt;
        total    += (afterD + tAmt);
    });

    document.getElementById('sum-subtotal').textContent = numFa(subtotal) + ' ریال';
    document.getElementById('sum-discount').textContent = '— ' + numFa(discount) + ' ریال';
    document.getElementById('sum-tax').textContent      = '+ ' + numFa(taxAmt)   + ' ریال';
    document.getElementById('sum-total').textContent    = numFa(total) + ' ریال';
}

// ============================================================
// Autocomplete اشخاص
// ============================================================
function onPersonSearch(q) {
    clearTimeout(personDebounceTimer);
    var dd = document.getElementById('personDropdown');
    document.getElementById('personId').value = '';
    if (q.length < 2) { dd.classList.remove('open'); return; }
    personDebounceTimer = setTimeout(function() {
        var fd = new FormData(); fd.append('action','search_persons'); fd.append('q', q);
        ajaxPost(fd, function(res) {
            if (!res.ok || !res.results.length) {
                dd.innerHTML = '<div class="ac-item no-result">موردی یافت نشد.</div>';
            } else {
                dd.innerHTML = res.results.map(function(p) {
                    return '<div class="ac-item" onclick="selectPerson(' + p.id + ', \'' +
                        escJs(p.display_name) + '\')">' + escHtml(p.display_name) + '</div>';
                }).join('');
            }
            dd.classList.add('open');
        });
    }, 300);
}

function selectPerson(id, name) {
    document.getElementById('personId').value    = id;
    document.getElementById('personSearch').value = name;
    document.getElementById('personDropdown').classList.remove('open');
}

// بستن dropdown با کلیک بیرون
document.addEventListener('click', function(e) {
    if (!e.target.closest('.ac-wrap')) {
        document.querySelectorAll('.ac-dropdown').forEach(function(d) { d.classList.remove('open'); });
    }
});

// ============================================================
// Autocomplete کالا
// ============================================================
function onCommoditySearch(idx, q) {
    clearTimeout(commodityDebounceTimers[idx]);
    var dd = document.getElementById('cdropdown-' + idx);
    document.getElementById('cid-' + idx).value = '';
    if (q.length < 2) { dd.classList.remove('open'); return; }
    commodityDebounceTimers[idx] = setTimeout(function() {
        var fd = new FormData(); fd.append('action','search_commodities'); fd.append('q', q);
        ajaxPost(fd, function(res) {
            if (!res.ok || !res.results.length) {
                dd.innerHTML = '<div class="ac-item no-result">کالایی یافت نشد — شرح دستی وارد کنید.</div>';
            } else {
                dd.innerHTML = res.results.map(function(c) {
                    return '<div class="ac-item" onclick="selectCommodity(' + idx + ',' + c.id +
                        ',\'' + escJs(c.name) + '\',' + (parseInt(c.price)||0) + ')">' +
                        escHtml(c.name) + (c.unit ? ' <small style="color:#94a3b8">('+c.unit+')</small>' : '') +
                        ' — <span style="color:#059669;direction:ltr;display:inline-block">' + numFa(c.price) + ' ریال</span>' +
                        '</div>';
                }).join('');
            }
            dd.classList.add('open');
        });
    }, 300);
}

function selectCommodity(idx, cid, name, price) {
    document.getElementById('cid-' + idx).value   = cid;
    document.getElementById('desc-' + idx).value  = name;
    var upriceEl = document.getElementById('uprice-' + idx);
    upriceEl.value = numFa(price);
    document.getElementById('cdropdown-' + idx).classList.remove('open');
    recalcRow(idx);
    // فوکوس روی تعداد
    var qEl = document.getElementById('qty-' + idx);
    if (qEl) { qEl.focus(); qEl.select(); }
}

// ============================================================
// ذخیره فاکتور
// ============================================================
function saveInvoice() {
    var personId = document.getElementById('personId').value;
    var invDate  = document.getElementById('invDate').value;

    if (!personId) {
        showToast('لطفاً مشتری را انتخاب کنید.', 'danger');
        document.getElementById('personSearch').focus();
        return;
    }
    if (!invDate) {
        showToast('تاریخ فاکتور الزامی است.', 'danger');
        document.getElementById('invDate').focus();
        return;
    }

    // جمع‌آوری ردیف‌ها
    var items = [];
    var hasItem = false;
    document.querySelectorAll('#invItemsBody .inv-item-row').forEach(function(row) {
        var m = row.id.match(/row-(\d+)/);
        if (!m) return;
        var idx   = m[1];
        var desc  = (document.getElementById('desc-' + idx).value || '').trim();
        if (!desc) return;
        hasItem = true;
        items.push({
            commodity_id: document.getElementById('cid-' + idx).value || '',
            description:  desc,
            quantity:     document.getElementById('qty-' + idx).value || '1',
            unit_price:   numEn(document.getElementById('uprice-' + idx).value).replace(/\D/g,'') || '0',
            discount_pct: document.getElementById('disc-' + idx).value || '0',
            tax_pct:      document.getElementById('tax-' + idx).value  || '9',
        });
    });

    if (!hasItem) {
        showToast('حداقل یک ردیف با شرح وارد کنید.', 'danger');
        return;
    }

    var fd = new FormData();
    fd.append('action',       'save');
    fd.append('csrf_token',   document.getElementById('csrfToken').value);
    fd.append('invoice_id',   document.getElementById('editInvoiceId').value);
    fd.append('person_id',    personId);
    fd.append('invoice_date', invDate);
    fd.append('due_date',     document.getElementById('dueDate').value);
    fd.append('notes',        document.getElementById('invNotes').value);

    // ارسال آرایه آیتم‌ها
    items.forEach(function(item, i) {
        Object.keys(item).forEach(function(k) {
            fd.append('items[' + i + '][' + k + ']', item[k]);
        });
    });

    // غیرفعال کردن دکمه ذخیره
    var saveBtn = document.querySelector('[onclick="saveInvoice()"]');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = '⏳ در حال ذخیره...'; }

    ajaxPost(fd, function(res) {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = '💾 ثبت فاکتور'; }
        showToast(res.msg, res.ok ? 'success' : 'danger');
        if (res.ok) {
            resetForm();
            switchTab('list');
        }
    });
}

// ============================================================
// چاپ
// ============================================================
function printInvoice() {
    window.print();
}

// ============================================================
// AJAX helper
// ============================================================
function ajaxPost(fd, callback) {
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
    })
    .then(function(r) { return r.json(); })
    .then(callback)
    .catch(function(err) {
        console.error('AJAX error:', err);
        showToast('خطای ارتباطی رخ داد.', 'danger');
    });
}

// ============================================================
// توست اعلان
// ============================================================
function showToast(msg, type) {
    var colors = { success: '#059669', danger: '#e11d48', warning: '#d97706', info: '#2563eb' };
    var div = document.createElement('div');
    div.textContent = msg;
    div.style.cssText = [
        'position:fixed', 'top:20px', 'left:50%', 'transform:translateX(-50%)',
        'background:' + (colors[type] || colors.info),
        'color:#fff', 'padding:12px 24px', 'border-radius:10px',
        'font-family:Vazirmatn,Tahoma,sans-serif', 'font-weight:700',
        'font-size:0.9rem', 'z-index:9999',
        'box-shadow:0 6px 20px rgba(0,0,0,0.2)',
        'animation:fadeIn 0.3s ease',
    ].join(';');
    document.body.appendChild(div);
    setTimeout(function() { div.remove(); }, 3500);
}

// ============================================================
// escape helpers
// ============================================================
function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function escJs(s) {
    return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"');
}

// ============================================================
// راه‌اندازی
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // تنظیم تاریخ امروز
    document.getElementById('invDate').value = getTodayDate();
    // بارگذاری لیست اولیه
    loadList(1);
    // یک ردیف پیش‌فرض برای فرم جدید
    addRow();
});
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
