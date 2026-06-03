<?php
/*
 * فایل: public_html/admin/fin_preinvoice.php
 * توضیحات: پیشفاکتور / استعلام قیمت — بدون ثبت سند حسابداری
 * قابل تبدیل یک‌کلیک به فاکتور رسمی
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
        $finRoles = ['admin','management','manager','finance_manager','finance_expert','accountant','sales','sales_manager'];
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_preinvoices` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `quote_number` VARCHAR(30) NOT NULL,
        `quote_date` DATE NOT NULL,
        `valid_until` DATE DEFAULT NULL,
        `person_id` INT NOT NULL DEFAULT 0,
        `customer_name` VARCHAR(200) DEFAULT NULL,
        `subtotal` DECIMAL(20,0) DEFAULT 0,
        `discount` DECIMAL(20,0) DEFAULT 0,
        `tax` DECIMAL(20,0) DEFAULT 0,
        `shipping` DECIMAL(20,0) DEFAULT 0,
        `total_amount` DECIMAL(20,0) DEFAULT 0,
        `status` ENUM('draft','sent','accepted','rejected','converted') DEFAULT 'draft',
        `opportunity_id` INT DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `invoice_id` INT DEFAULT NULL COMMENT 'پس از تبدیل به فاکتور',
        `fiscal_year_id` INT DEFAULT NULL,
        `created_by` INT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted` TINYINT DEFAULT 0,
        UNIQUE KEY `uq_quote_number` (`quote_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_preinvoice_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `quote_id` INT NOT NULL,
        `stuff_id` INT DEFAULT NULL,
        `description` VARCHAR(500) NOT NULL DEFAULT '',
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
} catch (Throwable $e) { /* جداول از قبل وجود داشتند */ }

$fiscalYear   = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? (int)$fiscalYear['id'] : null;

// ---- شماره‌گذاری خودکار پیشفاکتور ----
function nextQuoteNumber($pdo) {
    $jYear = (int)date('Y');
    try {
        $last = $pdo->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(quote_number,'-',-1) AS UNSIGNED))
             FROM fin_preinvoices WHERE quote_number LIKE 'QT-{$jYear}-%'"
        )->fetchColumn();
    } catch (Throwable $e) { $last = 0; }
    $seq = (int)$last + 1;
    return 'QT-' . $jYear . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
}

// ---- تبدیل پیشفاکتور به فاکتور ----
function convertToInvoice($pdo, $quoteId, $userId, $fiscalYearId) {
    $stQ = $pdo->prepare("SELECT * FROM fin_preinvoices WHERE id = ? AND is_deleted = 0");
    $stQ->execute([$quoteId]);
    $quote = $stQ->fetch(PDO::FETCH_ASSOC);
    if (!$quote) return ['ok' => false, 'msg' => 'پیشفاکتور یافت نشد.'];
    if ($quote['status'] === 'converted') return ['ok' => false, 'msg' => 'پیشفاکتور قبلاً تبدیل شده است.'];
    if ($quote['status'] === 'rejected')  return ['ok' => false, 'msg' => 'پیشفاکتور رد شده قابل تبدیل نیست.'];

    $stI = $pdo->prepare("SELECT * FROM fin_preinvoice_items WHERE quote_id = ? ORDER BY row_order");
    $stI->execute([$quoteId]);
    $items = $stI->fetchAll(PDO::FETCH_ASSOC);
    if (empty($items)) return ['ok' => false, 'msg' => 'پیشفاکتور ردیف ندارد.'];

    // شماره فاکتور
    $jYear = (int)date('Y');
    try {
        $last = $pdo->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED))
             FROM fin_invoices WHERE invoice_number LIKE 'S-{$jYear}-%'"
        )->fetchColumn();
    } catch (Throwable $e) { $last = 0; }
    $invNum = 'S-' . $jYear . '-' . str_pad((int)$last + 1, 4, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();

    $pdo->prepare(
        "INSERT INTO fin_invoices
            (invoice_number, invoice_date, type, person_id, customer_name,
             subtotal, discount, tax, shipping, total_amount, paid_amount,
             status, notes, opportunity_id, fiscal_year_id, created_by, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,NOW())"
    )->execute([
        $invNum, $quote['quote_date'], 'sell', $quote['person_id'], $quote['customer_name'],
        $quote['subtotal'], $quote['discount'], $quote['tax'], $quote['shipping'], $quote['total_amount'],
        'draft', $quote['notes'], $quote['opportunity_id'], $fiscalYearId, $userId,
    ]);
    $newInvId = (int)$pdo->lastInsertId();

    $stItem = $pdo->prepare(
        "INSERT INTO fin_invoice_items
            (invoice_id, stuff_id, description, unit, qty, unit_price,
             discount_pct, discount_amt, tax_pct, tax_amt, total, row_order)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ($items as $idx => $it) {
        $stItem->execute([
            $newInvId, $it['stuff_id'], $it['description'], $it['unit'],
            $it['qty'], $it['unit_price'],
            $it['discount_pct'], $it['discount_amt'],
            $it['tax_pct'], $it['tax_amt'],
            $it['total'], $idx + 1,
        ]);
    }

    $pdo->prepare("UPDATE fin_preinvoices SET status='converted', invoice_id=?, updated_at=NOW() WHERE id=?")
        ->execute([$newInvId, $quoteId]);

    $pdo->commit();
    return ['ok' => true, 'invoice_id' => $newInvId, 'invoice_number' => $invNum];
}

// ===========================================================
// پردازش درخواست‌های AJAX
// ===========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action'] ?? '');

    try {

        // ---- لیست پیشفاکتورها ----
        if ($action === 'list') {
            $page    = max(1, (int)($_POST['page'] ?? 1));
            $perPage = 20;
            $offset  = ($page - 1) * $perPage;
            $search  = trim($_POST['search'] ?? '');
            $status  = trim($_POST['status'] ?? '');
            $oppId   = (int)($_POST['opportunity_id'] ?? 0);

            $where  = ["q.is_deleted = 0"];
            $params = [];

            if ($search) {
                $where[] = '(q.quote_number LIKE ? OR q.customer_name LIKE ? OR p.name LIKE ? OR p.company_name LIKE ?)';
                $s = "%$search%";
                array_push($params, $s, $s, $s, $s);
            }
            if ($status) { $where[] = 'q.status = ?'; $params[] = $status; }
            if ($oppId)  { $where[] = 'q.opportunity_id = ?'; $params[] = $oppId; }

            $ws = implode(' AND ', $where);

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM fin_preinvoices q LEFT JOIN fin_persons p ON p.id = q.person_id WHERE $ws");
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();

            $stL = $pdo->prepare(
                "SELECT q.id, q.quote_number, q.quote_date, q.valid_until, q.status,
                        q.total_amount, q.customer_name, q.opportunity_id, q.invoice_id,
                        COALESCE(p.company_name, p.name, q.customer_name) AS person_name
                 FROM fin_preinvoices q
                 LEFT JOIN fin_persons p ON p.id = q.person_id
                 WHERE $ws
                 ORDER BY q.id DESC
                 LIMIT $perPage OFFSET $offset"
            );
            $stL->execute($params);
            $rows = $stL->fetchAll(PDO::FETCH_ASSOC);

            $statusMap = [
                'draft'     => ['label' => 'پیش‌نویس',         'cls' => 'gray'],
                'sent'      => ['label' => 'ارسال شده',        'cls' => 'blue'],
                'accepted'  => ['label' => 'پذیرفته شده',     'cls' => 'green'],
                'rejected'  => ['label' => 'رد شده',           'cls' => 'red'],
                'converted' => ['label' => 'تبدیل به فاکتور', 'cls' => 'purple'],
            ];

            foreach ($rows as &$r) {
                $r['total_fmt']   = number_format((int)$r['total_amount']);
                $r['date_jalali'] = jdate('Y/m/d', $r['quote_date']);
                $sl = $statusMap[$r['status']] ?? ['label' => $r['status'], 'cls' => 'gray'];
                $r['status_label'] = $sl['label'];
                $r['status_class'] = $sl['cls'];
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

        // ---- ذخیره پیش‌نویس ----
        if ($action === 'save') {
            $editId    = (int)($_POST['quote_id'] ?? 0);
            $personId  = (int)($_POST['person_id'] ?? 0);
            $qDate     = trim(faToEn($_POST['quote_date'] ?? ''));
            $vUntil    = trim(faToEn($_POST['valid_until'] ?? '')) ?: null;
            $notes     = trim($_POST['notes'] ?? '');
            $shipping  = (int)str_replace([',', ' '], '', faToEn($_POST['shipping'] ?? '0'));
            $oppId     = (int)($_POST['opportunity_id'] ?? 0) ?: null;
            $items     = $_POST['items'] ?? [];

            if (!$personId) { echo json_encode(['ok' => false, 'msg' => 'انتخاب طرف حساب الزامی است.']); exit; }
            if (empty($items)) { echo json_encode(['ok' => false, 'msg' => 'حداقل یک ردیف الزامی است.']); exit; }
            if (!$qDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $qDate)) {
                echo json_encode(['ok' => false, 'msg' => 'فرمت تاریخ نادرست است.']); exit;
            }

            // نام طرف حساب
            $stP = $pdo->prepare('SELECT COALESCE(company_name, name) AS n FROM fin_persons WHERE id = ?');
            $stP->execute([$personId]);
            $personName = $stP->fetchColumn() ?: '';

            // محاسبه مبالغ
            $subtotal = $totalDiscount = $totalTax = 0;
            $cleanItems = [];

            foreach ($items as $item) {
                $desc  = trim($item['description'] ?? '');
                if (!$desc) continue;
                $qty   = (float)faToEn($item['qty'] ?? 1);
                $price = (int)str_replace([',', ' '], '', faToEn($item['unit_price'] ?? '0'));
                $discP = (float)faToEn($item['discount_pct'] ?? '0');
                $taxP  = (float)faToEn($item['tax_pct'] ?? '9');
                $sid   = (int)($item['stuff_id'] ?? 0) ?: null;
                $unit  = trim($item['unit'] ?? '');

                $gross = (int)round($qty * $price);
                $dAmt  = (int)round($gross * $discP / 100);
                $after = $gross - $dAmt;
                $tAmt  = (int)round($after * $taxP / 100);
                $total = $after + $tAmt;

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
            if (empty($cleanItems)) { echo json_encode(['ok' => false, 'msg' => 'ردیف‌های معتبر وارد نشده.']); exit; }

            $netSales    = $subtotal - $totalDiscount;
            $totalAmount = $netSales + $totalTax + $shipping;

            $pdo->beginTransaction();

            if ($editId > 0) {
                $chk = $pdo->prepare("SELECT status FROM fin_preinvoices WHERE id = ? AND is_deleted = 0");
                $chk->execute([$editId]);
                $curStatus = $chk->fetchColumn();
                if ($curStatus === 'converted') {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'msg' => 'پیشفاکتور تبدیل‌شده قابل ویرایش نیست.']); exit;
                }
                $pdo->prepare('DELETE FROM fin_preinvoice_items WHERE quote_id = ?')->execute([$editId]);
                $pdo->prepare(
                    "UPDATE fin_preinvoices SET person_id=?, customer_name=?, quote_date=?, valid_until=?,
                             subtotal=?, discount=?, tax=?, shipping=?, total_amount=?,
                             notes=?, opportunity_id=?, updated_at=NOW()
                     WHERE id=? AND is_deleted=0"
                )->execute([
                    $personId, $personName, $qDate, $vUntil,
                    $subtotal, $totalDiscount, $totalTax, $shipping, $totalAmount,
                    $notes, $oppId, $editId,
                ]);
                $quoteId     = $editId;
                $quoteNumber = $pdo->query("SELECT quote_number FROM fin_preinvoices WHERE id=$editId")->fetchColumn();
            } else {
                $quoteNumber = nextQuoteNumber($pdo);
                $pdo->prepare(
                    "INSERT INTO fin_preinvoices
                        (quote_number, quote_date, valid_until, person_id, customer_name,
                         subtotal, discount, tax, shipping, total_amount,
                         status, notes, opportunity_id, fiscal_year_id, created_by, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
                )->execute([
                    $quoteNumber, $qDate, $vUntil, $personId, $personName,
                    $subtotal, $totalDiscount, $totalTax, $shipping, $totalAmount,
                    'draft', $notes, $oppId, $fiscalYearId, $userId,
                ]);
                $quoteId = (int)$pdo->lastInsertId();
            }

            $stItem = $pdo->prepare(
                "INSERT INTO fin_preinvoice_items
                    (quote_id, stuff_id, description, unit, qty, unit_price,
                     discount_pct, discount_amt, tax_pct, tax_amt, total, row_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            foreach ($cleanItems as $idx => $it) {
                $stItem->execute([
                    $quoteId, $it['stuff_id'], $it['description'], $it['unit'],
                    $it['qty'], $it['unit_price'],
                    $it['discount_pct'], $it['discount_amt'],
                    $it['tax_pct'], $it['tax_amt'],
                    $it['total'], $idx + 1,
                ]);
            }
            $pdo->commit();

            echo json_encode([
                'ok'           => true,
                'quote_id'     => $quoteId,
                'quote_number' => $quoteNumber,
                'msg'          => 'پیشفاکتور ذخیره شد.',
            ]);
            exit;
        }

        // ---- دریافت یک پیشفاکتور ----
        if ($action === 'get_one') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare(
                "SELECT q.*, COALESCE(p.company_name, p.name, q.customer_name) AS person_name
                 FROM fin_preinvoices q
                 LEFT JOIN fin_persons p ON p.id = q.person_id
                 WHERE q.id = ? AND q.is_deleted = 0"
            );
            $st->execute([$id]);
            $quote = $st->fetch(PDO::FETCH_ASSOC);
            if (!$quote) { echo json_encode(['ok' => false, 'msg' => 'پیشفاکتور یافت نشد.']); exit; }

            $stI = $pdo->prepare('SELECT * FROM fin_preinvoice_items WHERE quote_id = ? ORDER BY row_order');
            $stI->execute([$id]);
            $quote['items']        = $stI->fetchAll(PDO::FETCH_ASSOC);
            $quote['date_jalali']  = jdate('Y/m/d', $quote['quote_date']);
            $quote['valid_jalali'] = $quote['valid_until'] ? jdate('Y/m/d', $quote['valid_until']) : '';
            echo json_encode(['ok' => true, 'quote' => $quote]);
            exit;
        }

        // ---- حذف نرم ----
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT status FROM fin_preinvoices WHERE id = ? AND is_deleted = 0");
            $st->execute([$id]);
            $q = $st->fetch();
            if (!$q) { echo json_encode(['ok' => false, 'msg' => 'پیشفاکتور یافت نشد.']); exit; }
            if (!in_array($q['status'], ['draft', 'rejected'])) {
                echo json_encode(['ok' => false, 'msg' => 'فقط پیشفاکتورهای پیش‌نویس یا رد شده قابل حذف هستند.']); exit;
            }
            $pdo->prepare("UPDATE fin_preinvoices SET is_deleted = 1, updated_at = NOW() WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'پیشفاکتور حذف شد.']);
            exit;
        }

        // ---- تبدیل به فاکتور ----
        if ($action === 'convert_to_invoice') {
            $id = (int)($_POST['id'] ?? 0);
            echo json_encode(convertToInvoice($pdo, $id, $userId, $fiscalYearId));
            exit;
        }

        // ---- جستجوی طرف حساب ----
        if ($action === 'search_persons') {
            $q  = trim($_POST['q'] ?? '');
            $st = $pdo->prepare(
                "SELECT id, COALESCE(company_name, name) AS label, mobile
                 FROM fin_persons
                 WHERE is_deleted = 0 AND type IN ('customer','both')
                   AND (name LIKE ? OR company_name LIKE ? OR mobile LIKE ?)
                 ORDER BY company_name, name
                 LIMIT 20"
            );
            $s = "%$q%";
            $st->execute([$s, $s, $s]);
            echo json_encode(['ok' => true, 'results' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ---- جستجوی کالا ----
        if ($action === 'search_stuffs') {
            $q  = trim($_POST['q'] ?? '');
            $st = $pdo->prepare(
                "SELECT s.id, s.name AS label, s.unit,
                        COALESCE(sp.sell_price, 0) AS sell_price,
                        COALESCE(sp.total_inventory, 0) AS stock
                 FROM stuffs s
                 LEFT JOIN stuff_price_list sp ON sp.stuff_id = s.id
                 WHERE s.is_deleted = 0 AND s.name LIKE ?
                 ORDER BY s.name
                 LIMIT 20"
            );
            $st->execute(["%$q%"]);
            echo json_encode(['ok' => true, 'results' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ---- به‌روزرسانی وضعیت ----
        if ($action === 'update_status') {
            $id       = (int)($_POST['id'] ?? 0);
            $status   = trim($_POST['status'] ?? '');
            $extraMsg = trim($_POST['extra_msg'] ?? '');
            if (!in_array($status, ['sent', 'accepted', 'rejected', 'notify'])) {
                echo json_encode(['ok' => false, 'msg' => 'وضعیت نامعتبر است.']); exit;
            }
            $st = $pdo->prepare("SELECT status,quote_number,total_amount,customer_name FROM fin_preinvoices WHERE id = ? AND is_deleted = 0");
            $st->execute([$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) { echo json_encode(['ok' => false, 'msg' => 'پیشفاکتور یافت نشد.']); exit; }
            if ($cur['status'] === 'converted') { echo json_encode(['ok' => false, 'msg' => 'پیشفاکتور تبدیل‌شده را نمی‌توان ویرایش کرد.']); exit; }

            // بررسی موجودی انبار قبل از تأیید پیشفاکتور
            if ($status === 'accepted') {
                $itemsStmt = $pdo->prepare(
                    "SELECT pi.stuff_id, pi.description, pi.qty,
                            COALESCE(spl.total_inventory,0) AS available,
                            COALESCE(s.name,'') AS stuff_name
                     FROM fin_preinvoice_items pi
                     LEFT JOIN stuffs s ON s.id = pi.stuff_id
                     LEFT JOIN stuff_price_list spl ON spl.stuff_id = pi.stuff_id
                     WHERE pi.quote_id = ? AND pi.stuff_id IS NOT NULL AND pi.stuff_id > 0"
                );
                $itemsStmt->execute([$id]);
                $stockErrors = [];
                foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    if ((float)$item['qty'] > (float)$item['available']) {
                        $stockErrors[] = '«' . $item['stuff_name'] . '» — نیاز: ' . $item['qty']
                            . ' / موجودی: ' . $item['available'];
                    }
                }
                if (!empty($stockErrors)) {
                    echo json_encode([
                        'ok'    => false,
                        'msg'   => 'موجودی انبار کافی نیست:',
                        'items' => $stockErrors,
                    ]);
                    exit;
                }
            }

            // notify = ارسال اعلان بدون تغییر وضعیت
            if ($status !== 'notify') {
                $pdo->prepare("UPDATE fin_preinvoices SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
            }
            // اعلان به مدیران در صورت تأیید یا ارسال دستی
            if ($status === 'accepted' || $status === 'notify') {
                try {
                    $managers = $pdo->query("SELECT id FROM users WHERE status='active' AND (role IN ('admin','sales_manager','warehouse_manager','inv_manager') OR role LIKE '%admin%') LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
                    $baseMsg = $status === 'accepted'
                        ? 'پیشفاکتور '.$cur['quote_number'].' به مبلغ '.number_format((int)$cur['total_amount']).' ریال برای «'.$cur['customer_name'].'» تأیید شد.'
                        : 'پیام از پیشفاکتور '.$cur['quote_number'].' (مشتری: '.$cur['customer_name'].'):';
                    $notifMsg = $extraMsg ? $baseMsg . ' — ' . $extraMsg : $baseMsg;
                    $notifTitle = $status === 'accepted' ? 'پیشفاکتور تأیید شد' : 'پیام از پیشفاکتور';
                    foreach ($managers as $mId) {
                        if ((int)$mId === $userId) continue;
                        try {
                            $pdo->prepare("INSERT INTO user_notifications (user_id, type, title, message, is_read, created_at) VALUES (?,?,?,?,0,NOW())")
                                ->execute([$mId, 'preinvoice_accepted', $notifTitle, $notifMsg]);
                        } catch (Throwable $ne) {}
                    }
                } catch (Throwable $ne) {}
            }
            echo json_encode(['ok' => true, 'msg' => 'انجام شد.']);
            exit;
        }

        // ---- افزودن یادداشت / گزارش ----
        if ($action === 'add_note') {
            $id      = (int)($_POST['quote_id'] ?? 0);
            $content = trim($_POST['content'] ?? '');
            if (!$id || !$content) { echo json_encode(['ok'=>false,'msg'=>'متن یادداشت الزامی است']); exit; }
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_preinvoice_notes` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `quote_id` INT NOT NULL,
                    `user_id` INT NOT NULL,
                    `content` TEXT NOT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_qn_quote` (`quote_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Throwable $e) {}
            $pdo->prepare("INSERT INTO fin_preinvoice_notes (quote_id,user_id,content) VALUES (?,?,?)")->execute([$id,$userId,$content]);
            echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
            exit;
        }

        // ---- دریافت یادداشت‌ها ----
        if ($action === 'get_notes') {
            $id = (int)($_POST['quote_id'] ?? 0);
            try {
                $rows = $pdo->prepare("SELECT n.id, n.content, n.created_at, CONCAT(u.first_name,' ',u.last_name) AS user_name FROM fin_preinvoice_notes n LEFT JOIN users u ON u.id=n.user_id WHERE n.quote_id=? ORDER BY n.id ASC");
                $rows->execute([$id]);
                echo json_encode(['ok'=>true,'notes'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Throwable $e) { echo json_encode(['ok'=>true,'notes'=>[]]); }
            exit;
        }

        // ---- آپلود سند ----
        if ($action === 'upload_doc') {
            $id = (int)($_POST['quote_id'] ?? 0);
            if (!$id || empty($_FILES['file']['tmp_name'])) { echo json_encode(['ok'=>false,'msg'=>'فایل ارسال نشده']); exit; }
            $allowedExt = ['pdf','jpg','jpeg','png','docx','xlsx'];
            $origName   = $_FILES['file']['name'];
            $ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt)) { echo json_encode(['ok'=>false,'msg'=>'فرمت مجاز: pdf, jpg, png, docx, xlsx']); exit; }
            $uploadDir = __DIR__.'/../uploads/preinvoices/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $safeName  = 'qi_'.$id.'_'.time().'.'.$ext;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir.$safeName)) {
                echo json_encode(['ok'=>false,'msg'=>'آپلود ناموفق']); exit;
            }
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_preinvoice_docs` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `quote_id` INT NOT NULL,
                    `file_path` VARCHAR(300) NOT NULL,
                    `original_name` VARCHAR(200) NOT NULL,
                    `uploaded_by` INT NOT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_qd_quote` (`quote_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Throwable $e) {}
            $pdo->prepare("INSERT INTO fin_preinvoice_docs (quote_id,file_path,original_name,uploaded_by) VALUES (?,?,?,?)")
                ->execute([$id, 'uploads/preinvoices/'.$safeName, $origName, $userId]);
            echo json_encode(['ok'=>true,'file'=>$safeName,'orig'=>$origName]);
            exit;
        }

        // ---- دریافت اسناد ----
        if ($action === 'get_docs') {
            $id = (int)($_POST['quote_id'] ?? 0);
            try {
                $rows = $pdo->prepare("SELECT d.id, d.file_path, d.original_name, d.created_at, CONCAT(u.first_name,' ',u.last_name) AS uploader FROM fin_preinvoice_docs d LEFT JOIN users u ON u.id=d.uploaded_by WHERE d.quote_id=? ORDER BY d.id ASC");
                $rows->execute([$id]);
                echo json_encode(['ok'=>true,'docs'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Throwable $e) { echo json_encode(['ok'=>true,'docs'=>[]]); }
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'درخواست نامعتبر.']);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('fin_preinvoice AJAX error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'خطای سرور: ' . $e->getMessage()]);
    }
    exit;
}

// ===========================================================
// رندر HTML
// ===========================================================
$basePath  = '../../';
$pageTitle = 'پیشفاکتور';
$csrfToken = csrf_token();

// پیش‌بارگذاری مشتری از opportunity
$preOppId = (int)($_GET['opportunity_id'] ?? 0);
$prePersonId = 0; $prePersonName = ''; $preOppTitle = '';
if ($preOppId) {
    try {
        $stOpp = $pdo->prepare(
            "SELECT o.id, c.company_name, fp.id AS fin_person_id, fp.company_name AS fp_cname, fp.name AS fp_name
             FROM crm_opportunities o
             JOIN customers c ON o.customer_id = c.id
             LEFT JOIN fin_persons fp ON (fp.company_name = c.company_name OR fp.name = c.company_name) AND fp.is_deleted = 0
             WHERE o.id = ? LIMIT 1"
        );
        $stOpp->execute([$preOppId]);
        $oppRow = $stOpp->fetch(PDO::FETCH_ASSOC);
        if ($oppRow) {
            $prePersonId   = (int)($oppRow['fin_person_id'] ?? 0);
            $prePersonName = $oppRow['fp_cname'] ?: ($oppRow['fp_name'] ?: $oppRow['company_name']);
            $preOppTitle   = $oppRow['company_name'];
        }
    } catch (Throwable $e) {}
}

$extraCss = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';
require_once __DIR__ . '/../../templates/header.php';
?>
<style>
/* ===== پیشفاکتور — تم زرد/آمبر ===== */
.pre-page-icon{width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#92400e,#f59e0b);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff;box-shadow:0 6px 16px rgba(245,158,11,.35)}
.pre-form-hdr{background:linear-gradient(135deg,#78350f,#d97706);padding:18px 26px;display:flex;align-items:center;justify-content:space-between;color:#fff;border-radius:18px 18px 0 0}
.pre-form-hdr-title{font-size:1.05rem;font-weight:900}
.pre-form-hdr-num{font-size:.95rem;font-weight:700;direction:ltr;background:rgba(255,255,255,.18);padding:5px 14px;border-radius:8px}
.inv-page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.inv-page-title{display:flex;align-items:center;gap:12px}
.inv-page-title h1{font-size:1.25rem;font-weight:900;color:#1e293b;margin:0}
.inv-page-title p{font-size:0.78rem;color:#64748b;margin:3px 0 0}
.inv-form-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06)}
.inv-form-body{padding:26px}
.inv-sec-title{font-size:.8rem;font-weight:900;color:#64748b;margin:0 0 12px;display:flex;align-items:center;gap:6px}
.inv-sec-title::before{content:'';display:inline-block;width:4px;height:15px;background:#f59e0b;border-radius:2px}
.inv-meta-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px;margin-bottom:24px}
.inv-items-section{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:22px}
.inv-items-thead{background:#fffbeb;display:grid;grid-template-columns:34px 1fr 60px 80px 130px 70px 70px 110px 38px;gap:0;padding:9px 12px;border-bottom:2px solid #fcd34d}
.inv-items-thead span{font-size:.72rem;font-weight:800;color:#92400e;white-space:nowrap}
.inv-item-row{display:grid;grid-template-columns:34px 1fr 60px 80px 130px 70px 70px 110px 38px;gap:0;padding:7px 12px;border-bottom:1px solid #fef9c3;align-items:center}
.inv-item-row:last-child{border-bottom:none}
.inv-item-row:hover{background:#fffde7}
.row-num{font-size:.75rem;color:#92400e;font-weight:700;text-align:center}
.inv-inp{width:100%;border:1.5px solid transparent;border-radius:6px;padding:5px 7px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.8rem;color:#1e293b;background:transparent;direction:rtl}
.inv-inp.n{direction:ltr;text-align:right}
.inv-inp:focus{outline:none;border-color:#f59e0b;background:#fffde7}
.inv-item-total{font-size:.8rem;font-weight:800;color:#92400e;direction:ltr;text-align:right;padding:0 4px}
.inv-row-del{width:28px;height:28px;border:none;background:#fff7ed;color:#c2410c;border-radius:6px;cursor:pointer;font-size:.85rem;display:flex;align-items:center;justify-content:center;margin:auto}
.inv-row-del:hover{background:#fed7aa}
.inv-add-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border:2px dashed #fcd34d;border-radius:8px;background:none;color:#92400e;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;margin:10px 12px;transition:all .2s}
.inv-add-btn:hover{border-color:#f59e0b;color:#b45309;background:#fffde7}
.inv-summary-box{background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1px solid #fcd34d;border-radius:14px;padding:20px 24px;min-width:300px}
.inv-sum-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:.85rem;color:#78350f;border-bottom:1px dashed #fde68a}
.inv-sum-row:last-child{border-bottom:none}
.inv-sum-total{font-size:1rem;font-weight:900;color:#92400e}
.inv-sum-total-val{font-size:1.1rem;font-weight:900;color:#b45309;direction:ltr}
.pre-btn-amber{background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;border:none;padding:10px 20px;border-radius:10px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.pre-btn-amber:hover{background:linear-gradient(135deg,#b45309,#d97706);transform:translateY(-1px)}
.pre-btn-sent{background:linear-gradient(135deg,#1e40af,#2563eb);color:#fff;border:none;padding:10px 20px;border-radius:10px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.pre-btn-sent:hover{background:linear-gradient(135deg,#1e3a8a,#1d4ed8);transform:translateY(-1px)}
.pre-btn-convert{background:linear-gradient(135deg,#166534,#16a34a);color:#fff;border:none;padding:10px 20px;border-radius:10px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.pre-btn-convert:hover{background:linear-gradient(135deg,#14532d,#15803d);transform:translateY(-1px)}
.badge-gray{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
.badge-blue{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd}
.badge-green{background:#dcfce7;color:#166534;border:1px solid #86efac}
.badge-red{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.badge-purple{background:#f3e8ff;color:#7c3aed;border:1px solid #c4b5fd}
.pre-tbl-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.quote-list-table{width:100%;border-collapse:collapse}
.quote-list-table th{background:#fffbeb;color:#92400e;font-size:.78rem;font-weight:800;padding:10px 14px;border-bottom:2px solid #fcd34d;text-align:right;white-space:nowrap}
.quote-list-table td{padding:9px 14px;font-size:.82rem;color:#374151;border-bottom:1px solid #fef9c3;vertical-align:middle}
.quote-list-table tr:hover td{background:#fffde7}
.person-search-wrap{position:relative}
.person-suggest-list{position:absolute;top:100%;right:0;width:100%;background:#fff;border:1.5px solid #fcd34d;border-radius:0 0 10px 10px;z-index:200;max-height:200px;overflow-y:auto;box-shadow:0 8px 20px rgba(0,0,0,.1)}
.person-suggest-item{padding:9px 14px;cursor:pointer;font-size:.82rem;border-bottom:1px solid #fef9c3}
.person-suggest-item:hover{background:#fffde7;color:#92400e;font-weight:700}
@media(max-width:768px){.inv-meta-grid{grid-template-columns:1fr}.inv-items-thead,.inv-item-row{grid-template-columns:28px 1fr 55px 55px 95px 50px 50px 85px 30px}}
</style>

<main class="main-content">
    <div class="inv-page-header">
        <div class="inv-page-title">
            <div class="pre-page-icon">🧾</div>
            <div>
                <h1>پیشفاکتور / استعلام قیمت</h1>
                <p>صدور پیشفاکتور بدون ثبت سند حسابداری — قابل تبدیل به فاکتور رسمی</p>
            </div>
        </div>
        <button class="pre-btn-amber" onclick="showView('form')">➕ پیشفاکتور جدید</button>
    </div>

    <!-- آمار سریع -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px">
        <div class="fin-stat-card">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#78350f,#d97706)">🧾</div>
            <div><div class="fin-stat-label">کل پیشفاکتورها</div><div class="fin-stat-num" id="statTotal">—</div></div>
        </div>
        <div class="fin-stat-card">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#1e40af,#2563eb)">📤</div>
            <div><div class="fin-stat-label">ارسال شده</div><div class="fin-stat-num" id="statSent">—</div></div>
        </div>
        <div class="fin-stat-card">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#166534,#16a34a)">✅</div>
            <div><div class="fin-stat-label">پذیرفته شده</div><div class="fin-stat-num" id="statAccepted">—</div></div>
        </div>
        <div class="fin-stat-card">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#7c3aed,#9333ea)">🔄</div>
            <div><div class="fin-stat-label">تبدیل به فاکتور</div><div class="fin-stat-num" id="statConverted">—</div></div>
        </div>
    </div>

    <!-- نمای لیست -->
    <div id="viewList">
        <div class="fin-panel" style="margin-bottom:16px">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                <div style="flex:1;min-width:200px">
                    <label class="fin-label">جستجو</label>
                    <input type="text" id="fSearch" class="fin-input" placeholder="شماره / نام مشتری..." onkeyup="debounceList()">
                </div>
                <div>
                    <label class="fin-label">وضعیت</label>
                    <select id="fStatus" class="fin-input" onchange="loadList(1)">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="draft">پیش‌نویس</option>
                        <option value="sent">ارسال شده</option>
                        <option value="accepted">پذیرفته شده</option>
                        <option value="rejected">رد شده</option>
                        <option value="converted">تبدیل به فاکتور</option>
                    </select>
                </div>
                <button class="fin-btn fin-btn-primary" onclick="loadList(1)" style="background:#f59e0b;border-color:#d97706">🔍 جستجو</button>
            </div>
        </div>

        <div class="fin-panel" style="padding:0">
            <div style="overflow-x:auto">
                <table class="quote-list-table">
                    <thead>
                        <tr>
                            <th>شماره پیشفاکتور</th>
                            <th>تاریخ</th>
                            <th>مشتری</th>
                            <th>مبلغ کل (ریال)</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody id="quoteListBody">
                        <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">در حال بارگذاری...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="quotePagination" style="padding:14px 16px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap"></div>
        </div>
    </div>

    <!-- نمای فرم -->
    <div id="viewForm" style="display:none">
        <div class="inv-form-wrap">
            <div class="pre-form-hdr">
                <div class="pre-form-hdr-title">🧾 <span id="formTitle">پیشفاکتور جدید</span></div>
                <div style="display:flex;gap:10px;align-items:center">
                    <div class="pre-form-hdr-num" id="quoteNumDisplay">شماره: خودکار</div>
                    <button onclick="showView('list')" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 14px;border-radius:8px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.8rem">🔙 بازگشت</button>
                </div>
            </div>
            <div class="inv-form-body">
                <input type="hidden" id="editQuoteId" value="0">
                <input type="hidden" id="hiddenOppId" value="<?= (int)$preOppId ?>">

                <!-- اطلاعات اصلی -->
                <div class="inv-sec-title">اطلاعات پیشفاکتور</div>
                <div class="inv-meta-grid">
                    <div>
                        <label class="fin-label">طرف حساب (مشتری) <span style="color:#e11d48">*</span></label>
                        <div class="person-search-wrap">
                            <input type="text" id="personSearchInput" class="fin-input"
                                   placeholder="جستجوی نام مشتری..."
                                   value="<?= htmlspecialchars($prePersonName) ?>"
                                   autocomplete="off" oninput="searchPersons(this.value)">
                            <input type="hidden" id="personIdHidden" value="<?= (int)$prePersonId ?>">
                            <div id="personSuggestList" class="person-suggest-list" style="display:none"></div>
                        </div>
                    </div>
                    <div>
                        <label class="fin-label">تاریخ پیشفاکتور <span style="color:#e11d48">*</span></label>
                        <input type="text" id="quoteDate" class="fin-input" placeholder="1404/01/01">
                    </div>
                    <div>
                        <label class="fin-label">اعتبار تا</label>
                        <input type="text" id="validUntil" class="fin-input" placeholder="1404/02/01 (اختیاری)">
                    </div>
                </div>

                <!-- ردیف‌های کالا -->
                <div class="inv-sec-title">ردیف‌های کالا / خدمت</div>
                <div class="inv-items-section">
                    <div class="inv-items-thead">
                        <span>#</span>
                        <span>شرح کالا / خدمت</span>
                        <span>واحد</span>
                        <span>تعداد</span>
                        <span>قیمت واحد</span>
                        <span>تخفیف %</span>
                        <span>مالیات %</span>
                        <span>مبلغ کل</span>
                        <span></span>
                    </div>
                    <div id="itemsContainer"></div>
                </div>
                <button class="inv-add-btn" onclick="addItemRow()">➕ افزودن ردیف</button>

                <!-- جمع‌بندی و توضیحات -->
                <div style="display:flex;gap:24px;margin-top:16px;flex-wrap:wrap">
                    <div style="flex:1;min-width:280px">
                        <div class="inv-sec-title">توضیحات</div>
                        <textarea id="quoteNotes" class="fin-input" rows="4"
                                  placeholder="توضیحات اختیاری..." style="resize:vertical"></textarea>
                    </div>
                    <div class="inv-summary-box" style="min-width:280px">
                        <div class="inv-sum-row"><span>جمع کالاها:</span><span id="sumSubtotal">۰</span></div>
                        <div class="inv-sum-row"><span>تخفیف کل:</span><span id="sumDiscount" style="color:#e11d48">-۰</span></div>
                        <div class="inv-sum-row">
                            <span>هزینه حمل:</span>
                            <input type="text" id="shippingInput" class="inv-inp n" value="0"
                                   style="width:100px;border:1px solid #fcd34d;text-align:left"
                                   oninput="updateTotals()">
                        </div>
                        <div class="inv-sum-row"><span>مالیات:</span><span id="sumTax">۰</span></div>
                        <div class="inv-sum-row" style="margin-top:4px;padding-top:10px;border-top:2px solid #fcd34d">
                            <span class="inv-sum-total">مبلغ نهایی:</span>
                            <span class="inv-sum-total-val" id="sumTotal">۰</span>
                        </div>
                    </div>
                </div>

                <!-- دکمه‌های عملیات -->
                <div style="display:flex;gap:12px;margin-top:24px;flex-wrap:wrap">
                    <button class="pre-btn-amber" onclick="saveQuote('draft')" id="btnSave">💾 ذخیره پیش‌نویس</button>
                    <button class="pre-btn-sent" onclick="saveAndSend()" id="btnSend">📤 ارسال به مشتری</button>
                    <button onclick="acceptQuote()" id="btnAccept" style="display:none;background:#16a34a;color:#fff;border:none;padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;font-weight:700">
                        ✅ تأیید پیشفاکتور
                    </button>
                    <button class="pre-btn-convert" onclick="convertQuote()" id="btnConvert" style="display:none">🧾 تبدیل به فاکتور</button>
                    <button onclick="showView('list')"
                            style="background:#f1f5f9;border:1px solid #e2e8f0;padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;color:#64748b">
                        🔙 انصراف
                    </button>
                </div>

                <!-- پنل یادداشت‌ها و اسناد — فقط در حالت ویرایش نمایش داده می‌شود -->
                <div id="notesDocsPanel" style="display:none;margin-top:28px">
                    <div style="display:flex;gap:16px;flex-wrap:wrap">

                        <!-- یادداشت‌ها / گزارش‌نویسی -->
                        <div class="fin-panel" style="flex:1;min-width:300px">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                                <div style="font-weight:700;font-size:.95rem;color:#92400e">📝 گزارش‌ها و یادداشت‌ها</div>
                            </div>
                            <div id="notesList" style="max-height:200px;overflow-y:auto;margin-bottom:10px;font-size:.85rem"></div>
                            <div style="display:flex;gap:8px">
                                <textarea id="noteInput" rows="2" placeholder="گزارش یا یادداشت جدید..."
                                    style="flex:1;border:1px solid #fcd34d;border-radius:8px;padding:8px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.85rem;resize:vertical"></textarea>
                                <button onclick="submitNote()"
                                    style="background:#f59e0b;color:#fff;border:none;padding:8px 14px;border-radius:8px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;white-space:nowrap;align-self:flex-end">
                                    ➕ ثبت
                                </button>
                            </div>
                        </div>

                        <!-- آپلود اسناد -->
                        <div class="fin-panel" style="flex:1;min-width:300px">
                            <div style="font-weight:700;font-size:.95rem;color:#1e40af;margin-bottom:12px">📎 اسناد پیوست</div>
                            <div id="docsList" style="max-height:200px;overflow-y:auto;margin-bottom:10px;font-size:.85rem"></div>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;border:2px dashed #fcd34d;border-radius:10px;padding:10px;color:#92400e;font-size:.85rem"
                                   ondragover="event.preventDefault()" ondrop="handleDocDrop(event)">
                                <input type="file" id="docFileInput" style="display:none"
                                    accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx"
                                    onchange="uploadDoc(this.files[0])">
                                📂 انتخاب یا کشیدن فایل (PDF، تصویر، Word، Excel)
                            </label>
                        </div>

                    </div>

                    <!-- ارسال اعلان به مدیران -->
                    <div class="fin-panel" style="margin-top:12px;background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1px solid #fcd34d">
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                            <div style="flex:1;font-size:.88rem;color:#78350f">
                                🔔 پس از تأیید پیشفاکتور، اعلان خودکار به مدیر فروش و مدیر انبار ارسال می‌شود.
                                همچنین می‌توانید یک پیام اضافه ارسال کنید:
                            </div>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input type="text" id="notifMsgInput" placeholder="پیام اختیاری..."
                                    style="border:1px solid #fcd34d;border-radius:8px;padding:7px 12px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.85rem;width:220px">
                                <button onclick="sendManagerNotif()"
                                    style="background:#d97706;color:#fff;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;white-space:nowrap">
                                    📨 ارسال اعلان
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</main>

<script>
(function() {
var currentPage = 1;
var debounceTimer = null;

// ===== ابزارهای عمومی =====
function n2fa(n) {
    return String(n).replace(/\d/g, function(d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
}
function numFmt(n) { return n2fa(Number(n || 0).toLocaleString('en')); }

function faToEnStr(s) {
    return String(s || '').replace(/[۰-۹]/g, function(c) { return c.charCodeAt(0) - 1776; })
                          .replace(/[٠-٩]/g, function(c) { return c.charCodeAt(0) - 1632; });
}

function jalaliToGregorian(jy, jm, jd) {
    jy = parseInt(jy); jm = parseInt(jm); jd = parseInt(jd); jy += 1595;
    var days = -355668 + (365 * jy) + (Math.floor(jy / 33) * 8) +
               Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
    var gy = 400 * Math.floor(days / 146097); days %= 146097;
    if (days > 36524) { days--; gy += 100 * Math.floor(days / 36524); days %= 36524; if (days >= 365) days++; }
    gy += 4 * Math.floor(days / 1461); days %= 1461;
    if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    var gd = days + 1;
    var m = [0,31,(gy%4===0&&gy%100!==0)||gy%400===0?29:28,31,30,31,30,31,31,30,31,30,31];
    var gm = 0; for (var i = 1; i <= 12; i++) { if (gd <= m[i]) { gm = i; break; } gd -= m[i]; }
    var p2 = function(v) { return String(v).padStart(2, '0'); };
    return gy + '-' + p2(gm) + '-' + p2(gd);
}

function parseJalaliDate(str) {
    var s = faToEnStr(str).replace(/[^0-9\/]/g, '');
    var p = s.split('/');
    if (p.length !== 3) return '';
    return jalaliToGregorian(p[0], p[1], p[2]);
}

function post(data) {
    var fd = new FormData();
    for (var k in data) fd.append(k, data[k]);
    return fetch(window.location.href.split('?')[0], {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r) { return r.json(); });
}

// ===== نمایش نما =====
window.showView = function(v) {
    document.getElementById('viewList').style.display = v === 'list' ? '' : 'none';
    document.getElementById('viewForm').style.display = v === 'form' ? '' : 'none';
    if (v === 'form') initNewForm();
    if (v === 'list') loadList(1);
};

// ===== لیست =====
function loadList(page) {
    currentPage = page || 1;
    post({
        action: 'list',
        page: currentPage,
        search: document.getElementById('fSearch').value,
        status: document.getElementById('fStatus').value,
        opportunity_id: '<?= (int)$preOppId ?>'
    }).then(function(res) {
        if (!res.ok) return;
        var statusMap = {
            draft:     ['پیش‌نویس',      'gray'],
            sent:      ['ارسال شده',     'blue'],
            accepted:  ['پذیرفته شده',  'green'],
            rejected:  ['رد شده',        'red'],
            converted: ['تبدیل به فاکتور','purple']
        };
        var tbody = document.getElementById('quoteListBody');
        if (!res.rows || res.rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">هیچ پیشفاکتوری یافت نشد.</td></tr>';
        } else {
            var html = '';
            res.rows.forEach(function(r) {
                var sl = statusMap[r.status] || [r.status, 'gray'];
                var actions = '<button onclick="editQuote('+r.id+')" style="padding:3px 10px;border:1px solid #fcd34d;background:#fffde7;color:#92400e;border-radius:6px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.78rem;margin-left:4px">✏️ ویرایش</button>';
                if (r.status === 'draft' || r.status === 'accepted' || r.status === 'sent') {
                    actions += '<button onclick="doConvert('+r.id+')" style="padding:3px 10px;border:1px solid #86efac;background:#dcfce7;color:#166534;border-radius:6px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.78rem;margin-left:4px">✅ تبدیل</button>';
                }
                if (r.invoice_id) {
                    actions += '<a href="fin_invoice_sell.php" style="padding:3px 10px;border:1px solid #c4b5fd;background:#f3e8ff;color:#7c3aed;border-radius:6px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.78rem;margin-left:4px;text-decoration:none">🧾 فاکتور #'+r.invoice_id+'</a>';
                }
                if (r.status === 'draft' || r.status === 'rejected') {
                    actions += '<button onclick="doDelete('+r.id+')" style="padding:3px 10px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;border-radius:6px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.78rem">🗑️</button>';
                }
                html += '<tr>' +
                    '<td style="font-weight:700;color:#92400e;direction:ltr">' + r.quote_number + '</td>' +
                    '<td>' + (r.date_jalali || r.quote_date) + '</td>' +
                    '<td>' + (r.person_name || r.customer_name || '—') + '</td>' +
                    '<td style="direction:ltr;text-align:right;font-weight:700">' + numFmt(r.total_amount) + '</td>' +
                    '<td><span class="pre-tbl-badge badge-'+sl[1]+'">' + sl[0] + '</span></td>' +
                    '<td>' + actions + '</td>' +
                    '</tr>';
            });
            tbody.innerHTML = html;
        }
        // صفحه‌بندی
        var pg = res.pagination || {};
        var pagHtml = '';
        for (var i = 1; i <= (pg.total_pages || 1); i++) {
            pagHtml += '<button onclick="loadList('+i+')" style="padding:4px 10px;border:1px solid '+(i===currentPage?'#f59e0b':'#e2e8f0')+';background:'+(i===currentPage?'#fef3c7':'#fff')+';color:'+(i===currentPage?'#92400e':'#374151')+';border-radius:6px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.8rem">'+n2fa(i)+'</button>';
        }
        document.getElementById('quotePagination').innerHTML = pagHtml;
        loadStats();
    });
}

function loadStats() {
    post({ action: 'list', page: 1, status: '', search: '', per_page: 1000, opportunity_id: '' }).then(function(res) {
        if (!res.ok) return;
        var t=0, s=0, a=0, c=0;
        (res.rows||[]).forEach(function(r){
            t++;
            if(r.status==='sent') s++;
            if(r.status==='accepted') a++;
            if(r.status==='converted') c++;
        });
        document.getElementById('statTotal').innerText = n2fa(t);
        document.getElementById('statSent').innerText = n2fa(s);
        document.getElementById('statAccepted').innerText = n2fa(a);
        document.getElementById('statConverted').innerText = n2fa(c);
    });
}

window.debounceList = function() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function() { loadList(1); }, 400);
};

// ===== فرم جدید =====
function initNewForm() {
    document.getElementById('editQuoteId').value = '0';
    document.getElementById('formTitle').innerText = 'پیشفاکتور جدید';
    document.getElementById('quoteNumDisplay').innerText = 'شماره: خودکار';
    document.getElementById('personSearchInput').value = '<?= addslashes(htmlspecialchars($prePersonName, ENT_QUOTES)) ?>';
    document.getElementById('personIdHidden').value = '<?= (int)$prePersonId ?>';
    document.getElementById('quoteDate').value = '';
    document.getElementById('validUntil').value = '';
    document.getElementById('quoteNotes').value = '';
    document.getElementById('shippingInput').value = '0';
    document.getElementById('itemsContainer').innerHTML = '';
    document.getElementById('btnConvert').style.display = 'none';
    addItemRow();
    updateTotals();
}

// ===== ردیف کالا =====
window.addItemRow = function(item) {
    item = item || {};
    var idx = document.querySelectorAll('.inv-item-row').length + 1;
    var row = document.createElement('div');
    row.className = 'inv-item-row';
    row.innerHTML =
        '<span class="row-num">' + n2fa(idx) + '</span>' +
        '<div style="position:relative">' +
            '<input type="text" class="inv-inp item-desc" placeholder="جستجوی کالا یا شرح..." value="' + (item.description || '') + '" oninput="searchStuffsInRow(this)" autocomplete="off">' +
            '<input type="hidden" class="item-stuff-id" value="' + (item.stuff_id || '') + '">' +
            '<div class="person-suggest-list stuff-suggest" style="display:none"></div>' +
        '</div>' +
        '<input type="text" class="inv-inp item-unit" placeholder="عدد" value="' + (item.unit || '') + '">' +
        '<input type="text" class="inv-inp n item-qty" placeholder="1" value="' + (item.qty || '1') + '" oninput="updateTotals()">' +
        '<input type="text" class="inv-inp n item-price" placeholder="0" value="' + (item.unit_price ? Number(item.unit_price).toLocaleString('en') : '0') + '" oninput="updateTotals()">' +
        '<input type="text" class="inv-inp n item-disc" placeholder="0" value="' + (item.discount_pct || '0') + '" oninput="updateTotals()">' +
        '<input type="text" class="inv-inp n item-tax" placeholder="9" value="' + (item.tax_pct !== undefined ? item.tax_pct : '9') + '" oninput="updateTotals()">' +
        '<span class="inv-item-total item-total-display">۰</span>' +
        '<button class="inv-row-del" type="button" onclick="this.closest(\'.inv-item-row\').remove(); updateTotals(); reNumberRows()">×</button>';
    document.getElementById('itemsContainer').appendChild(row);
    updateTotals();
};

window.reNumberRows = function() {
    document.querySelectorAll('.inv-item-row .row-num').forEach(function(el, i) {
        el.innerText = n2fa(i + 1);
    });
};

window.updateTotals = function() {
    var subtotal = 0, disc = 0, tax = 0;
    document.querySelectorAll('.inv-item-row').forEach(function(row) {
        var qty   = parseFloat(faToEnStr(row.querySelector('.item-qty').value)) || 0;
        var price = parseInt(faToEnStr(row.querySelector('.item-price').value).replace(/,/g, '')) || 0;
        var discP = parseFloat(faToEnStr(row.querySelector('.item-disc').value)) || 0;
        var taxP  = parseFloat(faToEnStr(row.querySelector('.item-tax').value)) || 0;
        var gross = Math.round(qty * price);
        var dAmt  = Math.round(gross * discP / 100);
        var after = gross - dAmt;
        var tAmt  = Math.round(after * taxP / 100);
        var total = after + tAmt;
        subtotal += gross; disc += dAmt; tax += tAmt;
        row.querySelector('.item-total-display').innerText = numFmt(total);
    });
    var shipping = parseInt(faToEnStr(document.getElementById('shippingInput').value).replace(/,/g, '')) || 0;
    var grandTotal = (subtotal - disc) + tax + shipping;
    document.getElementById('sumSubtotal').innerText = numFmt(subtotal);
    document.getElementById('sumDiscount').innerText = '-' + numFmt(disc);
    document.getElementById('sumTax').innerText = numFmt(tax);
    document.getElementById('sumTotal').innerText = numFmt(grandTotal);
};

// ===== جستجوی طرف حساب =====
window.searchPersons = function(q) {
    if (q.length < 1) { document.getElementById('personSuggestList').style.display = 'none'; return; }
    post({ action: 'search_persons', q: q }).then(function(res) {
        if (!res.ok) return;
        var list = document.getElementById('personSuggestList');
        if (!res.results || !res.results.length) { list.style.display = 'none'; return; }
        list.innerHTML = res.results.map(function(p) {
            return '<div class="person-suggest-item" onclick="selectPerson('+p.id+', \''+String(p.label||'').replace(/'/g, '')+'\')">' +
                (p.label||'') + (p.mobile ? ' — ' + p.mobile : '') + '</div>';
        }).join('');
        list.style.display = 'block';
    });
};
window.selectPerson = function(id, name) {
    document.getElementById('personIdHidden').value = id;
    document.getElementById('personSearchInput').value = name;
    document.getElementById('personSuggestList').style.display = 'none';
};
document.addEventListener('click', function(e) {
    if (!e.target.closest('.person-search-wrap')) {
        document.getElementById('personSuggestList').style.display = 'none';
    }
});

// ===== جستجوی کالا در ردیف =====
window.searchStuffsInRow = function(input) {
    var row = input.closest('.inv-item-row');
    var suggest = row.querySelector('.stuff-suggest');
    var q = input.value;
    if (q.length < 1) { suggest.style.display = 'none'; return; }
    post({ action: 'search_stuffs', q: q }).then(function(res) {
        if (!res.ok || !res.results || !res.results.length) { suggest.style.display = 'none'; return; }
        suggest.innerHTML = res.results.map(function(s) {
            return '<div class="person-suggest-item" onclick="selectStuffInRow(this,'+s.id+',\''+String(s.label||'').replace(/'/g,'')+'\',\''+String(s.unit||'')+'\','+Number(s.sell_price||0)+')">' +
                (s.label||'') + ' — موجودی: ' + numFmt(s.stock) + ' — قیمت: ' + numFmt(s.sell_price) + '</div>';
        }).join('');
        suggest.style.display = 'block';
    });
};
window.selectStuffInRow = function(el, id, name, unit, price) {
    var row = el.closest('.inv-item-row');
    row.querySelector('.item-desc').value = name;
    row.querySelector('.item-stuff-id').value = id;
    row.querySelector('.item-unit').value = unit;
    row.querySelector('.item-price').value = Number(price).toLocaleString('en');
    el.closest('.stuff-suggest').style.display = 'none';
    updateTotals();
};
document.addEventListener('click', function(e) {
    if (!e.target.closest('.inv-item-row')) {
        document.querySelectorAll('.stuff-suggest').forEach(function(el) { el.style.display = 'none'; });
    }
});

// ===== ذخیره =====
function buildItemsData() {
    var items = [];
    document.querySelectorAll('.inv-item-row').forEach(function(row) {
        items.push({
            stuff_id:     row.querySelector('.item-stuff-id').value || '',
            description:  row.querySelector('.item-desc').value,
            unit:         row.querySelector('.item-unit').value,
            qty:          faToEnStr(row.querySelector('.item-qty').value),
            unit_price:   faToEnStr(row.querySelector('.item-price').value).replace(/,/g, ''),
            discount_pct: faToEnStr(row.querySelector('.item-disc').value),
            tax_pct:      faToEnStr(row.querySelector('.item-tax').value),
        });
    });
    return items;
}

window.saveQuote = function(afterAction) {
    var personId = document.getElementById('personIdHidden').value;
    if (!personId || personId === '0') { alert('لطفاً طرف حساب (مشتری) را انتخاب کنید.'); return; }
    var qDate = parseJalaliDate(document.getElementById('quoteDate').value);
    if (!qDate) { alert('لطفاً تاریخ پیشفاکتور را به درستی وارد کنید.'); return; }
    var vUntil = '';
    if (document.getElementById('validUntil').value) {
        vUntil = parseJalaliDate(document.getElementById('validUntil').value);
    }
    var items = buildItemsData().filter(function(i) { return i.description.trim(); });
    if (!items.length) { alert('حداقل یک ردیف کالا وارد کنید.'); return; }

    var fd = new FormData();
    fd.append('action', 'save');
    fd.append('quote_id', document.getElementById('editQuoteId').value);
    fd.append('person_id', personId);
    fd.append('quote_date', qDate);
    fd.append('valid_until', vUntil);
    fd.append('notes', document.getElementById('quoteNotes').value);
    fd.append('shipping', faToEnStr(document.getElementById('shippingInput').value).replace(/,/g,'') || '0');
    fd.append('opportunity_id', document.getElementById('hiddenOppId').value || '0');
    items.forEach(function(item, i) {
        for (var k in item) fd.append('items['+i+']['+k+']', item[k]);
    });

    var btn = document.getElementById('btnSave');
    btn.disabled = true; btn.innerHTML = '⏳ در حال ذخیره...';

    fetch(window.location.href.split('?')[0], {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r) { return r.json(); }).then(function(res) {
        btn.disabled = false; btn.innerHTML = '💾 ذخیره پیش‌نویس';
        if (res.ok) {
            document.getElementById('editQuoteId').value = res.quote_id;
            document.getElementById('quoteNumDisplay').innerText = 'شماره: ' + res.quote_number;
            document.getElementById('formTitle').innerText = 'ویرایش پیشفاکتور ' + res.quote_number;
            document.getElementById('btnConvert').style.display = 'inline-flex';
            // نمایش پنل یادداشت/اسناد پس از اولین ذخیره
            document.getElementById('notesDocsPanel').style.display = 'block';
            loadNotes(res.quote_id);
            loadDocs(res.quote_id);
            if (afterAction === 'send') {
                post({ action: 'update_status', id: res.quote_id, status: 'sent' }).then(function(r2) {
                    alert(r2.ok ? '✅ پیشفاکتور ذخیره و به وضعیت ارسال‌شده تغییر یافت.' : '✅ ذخیره شد اما ' + r2.msg);
                });
            } else {
                alert('✅ ' + res.msg);
            }
        } else { alert('❌ ' + res.msg); }
    }).catch(function() {
        btn.disabled = false; btn.innerHTML = '💾 ذخیره پیش‌نویس';
        alert('خطا در ارتباط با سرور.');
    });
};

window.saveAndSend = function() { saveQuote('send'); };

window.convertQuote = function() {
    var id = document.getElementById('editQuoteId').value;
    if (!id || id === '0') { alert('ابتدا پیشفاکتور را ذخیره کنید.'); return; }
    if (!confirm('آیا می‌خواهید این پیشفاکتور را به فاکتور رسمی تبدیل کنید؟')) return;
    post({ action: 'convert_to_invoice', id: id }).then(function(res) {
        if (res.ok) {
            alert('✅ فاکتور شماره ' + res.invoice_number + ' ایجاد شد!\nاکنون به صفحه فاکتور فروش هدایت می‌شوید.');
            window.location.href = 'fin_invoice_sell.php';
        } else { alert('❌ ' + res.msg); }
    });
};

window.doConvert = function(id) {
    if (!confirm('آیا می‌خواهید این پیشفاکتور را به فاکتور رسمی تبدیل کنید؟')) return;
    post({ action: 'convert_to_invoice', id: id }).then(function(res) {
        if (res.ok) { alert('✅ فاکتور ' + res.invoice_number + ' ایجاد شد.'); loadList(currentPage); }
        else { alert('❌ ' + res.msg); }
    });
};

window.doDelete = function(id) {
    if (!confirm('آیا از حذف این پیشفاکتور اطمینان دارید؟')) return;
    post({ action: 'delete', id: id }).then(function(res) {
        if (res.ok) { loadList(currentPage); }
        else { alert('❌ ' + res.msg); }
    });
};

window.editQuote = function(id) {
    post({ action: 'get_one', id: id }).then(function(res) {
        if (!res.ok) { alert('❌ ' + res.msg); return; }
        var q = res.quote;
        document.getElementById('editQuoteId').value = q.id;
        document.getElementById('formTitle').innerText = 'ویرایش پیشفاکتور ' + q.quote_number;
        document.getElementById('quoteNumDisplay').innerText = 'شماره: ' + q.quote_number;
        document.getElementById('personIdHidden').value = q.person_id;
        document.getElementById('personSearchInput').value = q.person_name || q.customer_name || '';
        document.getElementById('quoteDate').value = q.date_jalali || '';
        document.getElementById('validUntil').value = q.valid_jalali || '';
        document.getElementById('quoteNotes').value = q.notes || '';
        document.getElementById('shippingInput').value = Number(q.shipping || 0).toLocaleString('en');
        document.getElementById('hiddenOppId').value = q.opportunity_id || '';
        document.getElementById('btnConvert').style.display =
            (['draft','accepted','sent'].indexOf(q.status) >= 0) ? 'inline-flex' : 'none';
        document.getElementById('btnAccept').style.display =
            (['draft','sent'].indexOf(q.status) >= 0) ? 'inline-flex' : 'none';
        document.getElementById('itemsContainer').innerHTML = '';
        (q.items || []).forEach(function(item) { addItemRow(item); });
        updateTotals();
        showView('form');
        // نمایش پنل یادداشت/اسناد و بارگذاری داده‌ها
        document.getElementById('notesDocsPanel').style.display = 'block';
        loadNotes(q.id);
        loadDocs(q.id);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
};

// ===== یادداشت‌ها =====
function loadNotes(qid) {
    if (!qid) return;
    post({ action: 'get_notes', quote_id: qid }).then(function(res) {
        var el = document.getElementById('notesList');
        if (!res.ok || !res.notes || !res.notes.length) {
            el.innerHTML = '<div style="color:#9ca3af;text-align:center;padding:8px">هنوز یادداشتی ثبت نشده</div>';
            return;
        }
        el.innerHTML = res.notes.map(function(n) {
            return '<div style="border-bottom:1px solid #fef3c7;padding:6px 0">' +
                '<span style="color:#92400e;font-weight:600">' + (n.user_name || 'کاربر') + '</span>' +
                '<span style="color:#9ca3af;font-size:.78rem;margin-right:8px">' + (n.created_at || '') + '</span>' +
                '<div style="margin-top:3px;color:#374151">' + (n.content || '').replace(/\n/g,'<br>') + '</div>' +
                '</div>';
        }).join('');
    });
}

window.submitNote = function() {
    var qid = parseInt(document.getElementById('editQuoteId').value);
    var content = document.getElementById('noteInput').value.trim();
    if (!qid || !content) { alert('ابتدا پیشفاکتور را ذخیره کنید و متن یادداشت را بنویسید.'); return; }
    post({ action: 'add_note', quote_id: qid, content: content }).then(function(res) {
        if (res.ok) { document.getElementById('noteInput').value = ''; loadNotes(qid); }
        else { alert('❌ ' + res.msg); }
    });
};

// ===== اسناد =====
function loadDocs(qid) {
    if (!qid) return;
    post({ action: 'get_docs', quote_id: qid }).then(function(res) {
        var el = document.getElementById('docsList');
        if (!res.ok || !res.docs || !res.docs.length) {
            el.innerHTML = '<div style="color:#9ca3af;text-align:center;padding:8px">هنوز فایلی پیوست نشده</div>';
            return;
        }
        el.innerHTML = res.docs.map(function(d) {
            return '<div style="display:flex;align-items:center;gap:8px;border-bottom:1px solid #e0f2fe;padding:5px 0">' +
                '<span style="font-size:1.1rem">📄</span>' +
                '<a href="../../' + d.file_path + '" target="_blank" ' +
                'style="color:#1d4ed8;text-decoration:none;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                (d.original_name || d.file_path) + '</a>' +
                '<span style="color:#9ca3af;font-size:.78rem;white-space:nowrap">' + (d.created_at || '') + '</span>' +
                '</div>';
        }).join('');
    });
}

window.uploadDoc = function(file) {
    var qid = parseInt(document.getElementById('editQuoteId').value);
    if (!qid) { alert('ابتدا پیشفاکتور را ذخیره کنید.'); return; }
    if (!file) return;
    var fd = new FormData();
    fd.append('action', 'upload_doc');
    fd.append('quote_id', qid);
    fd.append('file', file);
    fetch(window.location.pathname, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.ok) { loadDocs(qid); }
            else { alert('❌ ' + res.msg); }
        });
};

window.handleDocDrop = function(e) {
    e.preventDefault();
    var f = e.dataTransfer.files[0];
    if (f) uploadDoc(f);
};

// ===== ارسال اعلان دستی =====
window.sendManagerNotif = function() {
    var qid = parseInt(document.getElementById('editQuoteId').value);
    if (!qid) { alert('ابتدا پیشفاکتور را ذخیره کنید.'); return; }
    var msg = document.getElementById('notifMsgInput').value.trim();
    post({ action: 'update_status', id: qid, status: 'notify', extra_msg: msg }).then(function(res) {
        if (res.ok) {
            alert('✅ اعلان ارسال شد.');
            document.getElementById('notifMsgInput').value = '';
        } else { alert('❌ ' + (res.msg || 'خطا')); }
    });
};

// ===== تأیید پیشفاکتور با بررسی موجودی =====
window.acceptQuote = function() {
    var id = parseInt(document.getElementById('editQuoteId').value);
    if (!id) { alert('ابتدا پیشفاکتور را ذخیره کنید.'); return; }
    if (!confirm('آیا از تأیید این پیشفاکتور اطمینان دارید؟\nبررسی موجودی انبار انجام می‌شود.')) return;
    var btn = document.getElementById('btnAccept');
    btn.disabled = true; btn.textContent = '⏳ در حال بررسی...';
    post({ action: 'update_status', id: id, status: 'accepted' }).then(function(res) {
        btn.disabled = false; btn.textContent = '✅ تأیید پیشفاکتور';
        if (res.ok) {
            btn.style.display = 'none';
            alert('✅ پیشفاکتور تأیید شد. اعلان به مدیران ارسال گردید.');
            loadList(1);
        } else {
            // نمایش خطای موجودی
            var msg = res.msg || 'خطا';
            if (res.items && res.items.length) {
                msg += '\n' + res.items.join('\n');
            }
            alert('❌ ' + msg);
        }
    }).catch(function() {
        btn.disabled = false; btn.textContent = '✅ تأیید پیشفاکتور';
        alert('خطا در ارتباط با سرور.');
    });
};

// ===== راه‌اندازی =====
loadList(1);
<?php if ($preOppId): ?>
showView('form');
<?php endif; ?>

})();
</script>

<?php
$basePath = '../../';
require_once __DIR__ . '/../../templates/footer.php';
