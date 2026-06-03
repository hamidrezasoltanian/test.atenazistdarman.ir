<?php
/*
 * فایل: public_html/admin/fin_invoice_buy.php
 * فاکتور خرید — پیش‌نویس، تأیید با سند دوطرفه، ویرایش، حذف نرم
 * منطق hesabix:
 *   بدهکار:   ۱۲۰  (موجودی کالا)         = جمع خالص ردیف‌ها
 *   بدهکار:   ۹۰   (هزینه حمل خرید)      = حمل (اگر > ۰)
 *   بدهکار:   ۳۳   (مالیات خرید)         = کل مالیات (اگر > ۰)
 *   بستانکار: ۸    (حساب پرداختنی)       = مبلغ نهایی
 *   بستانکار: ۵۱   (تخفیفات نقدی خرید)  = کل تخفیف (اگر > ۰)
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
            if (in_array('fin_invoices', $perms) || in_array('invoices_buy', $perms) || in_array('all', $perms)) $hasAccess = true;
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

// ---- اطمینان از وجود ستون invoice_type ----
try {
    $pdo->exec("ALTER TABLE fin_invoices ADD COLUMN IF NOT EXISTS invoice_type ENUM('official','adjustment') NOT NULL DEFAULT 'official' AFTER type");
} catch (Throwable $e) {}

// ---- اطمینان از وجود حساب‌های پایه ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fin_chart_of_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY, upper_id INT DEFAULT NULL,
        name VARCHAR(200) NOT NULL, type VARCHAR(50) DEFAULT NULL,
        code VARCHAR(20) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT DEFAULT 0, UNIQUE KEY uq_acc_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $seeds = [
        ['3','حساب دریافتنی'],['8','حساب پرداختنی'],['33','مالیات خرید'],
        ['51','تخفیفات نقدی خرید'],['53','فروش کالا'],['61','درآمد حمل'],
        ['90','هزینه حمل خرید'],['120','موجودی کالا'],['121','صندوق'],
        ['2103','مالیات ارزش افزوده پرداختنی'],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO fin_chart_of_accounts (code,name,created_at) VALUES (?,?,NOW())');
    foreach ($seeds as $s) $ins->execute($s);
} catch (Throwable $e) {}

$fiscalYear   = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? (int)$fiscalYear['id'] : null;

function buyAccId($pdo, $code) {
    static $cache = [];
    if (isset($cache[$code])) return $cache[$code];
    $s = $pdo->prepare('SELECT id FROM fin_chart_of_accounts WHERE code=? AND is_deleted=0 LIMIT 1');
    $s->execute([$code]);
    $id = $s->fetchColumn();
    $cache[$code] = $id ? (int)$id : null;
    return $cache[$code];
}

function nextBuyNumber($pdo) {
    $year = (int)date('Y');
    try {
        $last = $pdo->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED))
             FROM fin_invoices WHERE invoice_number LIKE 'B-{$year}-%'"
        )->fetchColumn();
    } catch (Throwable $e) { $last = 0; }
    return 'B-' . $year . '-' . str_pad((int)$last + 1, 4, '0', STR_PAD_LEFT);
}

// ===========================================================
// AJAX
// ===========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action'] ?? '');

    try {

        // ---- لیست ----
        if ($action === 'list') {
            $page    = max(1,(int)($_POST['page'] ?? 1));
            $perPage = 20;
            $offset  = ($page - 1) * $perPage;
            $dfrom   = trim($_POST['date_from'] ?? '');
            $dto     = trim($_POST['date_to']   ?? '');
            $status  = trim($_POST['status']    ?? '');

            $where  = ["i.is_deleted=0","i.type='buy'"];
            $params = [];
            if ($dfrom)  { $where[] = 'i.invoice_date >= ?'; $params[] = $dfrom; }
            if ($dto)    { $where[] = 'i.invoice_date <= ?'; $params[] = $dto; }
            if ($status) { $where[] = 'i.status = ?';        $params[] = $status; }

            $ws = implode(' AND ', $where);

            $stS = $pdo->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(total_amount),0) ts, COALESCE(SUM(paid_amount),0) ps FROM fin_invoices i WHERE $ws");
            $stS->execute($params);
            $stats = $stS->fetch();

            $stL = $pdo->prepare(
                "SELECT i.id, i.invoice_number, i.invoice_date,
                        i.total_amount, i.paid_amount, i.status, i.fin_doc_id,
                        COALESCE(i.invoice_type,'official') AS invoice_type,
                        (i.total_amount - i.paid_amount) AS remaining,
                        COALESCE(p.company_name, p.name, i.customer_name) AS person_name
                 FROM fin_invoices i
                 LEFT JOIN fin_persons p ON p.id = i.person_id
                 WHERE $ws ORDER BY i.id DESC LIMIT $perPage OFFSET $offset"
            );
            $stL->execute($params);
            $rows = $stL->fetchAll(PDO::FETCH_ASSOC);

            $sLabels = [
                'draft'     => ['label'=>'پیش‌نویس',     'cls'=>'draft'],
                'confirmed' => ['label'=>'تأیید شده',    'cls'=>'confirmed'],
                'paid'      => ['label'=>'پرداخت کامل', 'cls'=>'paid'],
                'partial'   => ['label'=>'پرداخت ناقص', 'cls'=>'partial'],
                'cancelled' => ['label'=>'لغو شده',      'cls'=>'cancelled'],
            ];
            foreach ($rows as &$r) {
                $r['total_fmt']   = number_format((int)$r['total_amount']);
                $r['paid_fmt']    = number_format((int)$r['paid_amount']);
                $r['remain_fmt']  = number_format(max(0,(int)$r['remaining']));
                $r['date_jalali'] = jdate('Y/m/d', $r['invoice_date']);
                $sl = $sLabels[$r['status']] ?? ['label'=>$r['status'],'cls'=>'draft'];
                $r['status_label'] = $sl['label'];
                $r['status_class'] = $sl['cls'];
            }
            unset($r);

            $totalPages = max(1,(int)ceil($stats['cnt'] / $perPage));
            echo json_encode([
                'ok'   => true, 'rows' => $rows,
                'stats' => [
                    'count'     => (int)$stats['cnt'],
                    'total_sum' => number_format((int)$stats['ts']),
                    'paid_sum'  => number_format((int)$stats['ps']),
                    'remain'    => number_format(max(0,(int)$stats['ts']-(int)$stats['ps'])),
                ],
                'pagination' => ['page'=>$page,'total_pages'=>$totalPages,'total_rows'=>(int)$stats['cnt']],
            ]);
            exit;
        }

        // ---- جستجوی تأمین‌کننده ----
        if ($action === 'search_persons') {
            $q = '%'.trim($_POST['q'] ?? '').'%';
            $stmt = $pdo->prepare(
                "SELECT id,
                        CONCAT(COALESCE(company_name,''), IF(company_name IS NOT NULL AND company_name!='' AND name!='', ' — ', ''), name) AS display_name
                 FROM fin_persons WHERE is_deleted=0 AND type IN ('supplier','both')
                   AND (name LIKE ? OR company_name LIKE ? OR mobile LIKE ?)
                 ORDER BY name LIMIT 20"
            );
            $stmt->execute([$q,$q,$q]);
            echo json_encode(['ok'=>true,'results'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ---- جستجوی کالا ----
        if ($action === 'search_stuffs') {
            $q = '%'.trim($_POST['q'] ?? '').'%';
            try {
                $stmt = $pdo->prepare(
                    "SELECT s.id, s.name, s.code,
                            COALESCE(spl.price_buy, s.price_buy, 0) AS price,
                            COALESCE(s.unit,'') AS unit
                     FROM stuffs s LEFT JOIN stuff_price_list spl ON spl.stuff_id=s.id
                     WHERE s.is_deleted=0 AND (s.name LIKE ? OR s.code LIKE ?)
                     ORDER BY s.name LIMIT 20"
                );
                $stmt->execute([$q,$q]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                try {
                    $stmt2 = $pdo->prepare("SELECT id,name,'' AS code,0 AS price,'' AS unit FROM stuffs WHERE is_deleted=0 AND name LIKE ? LIMIT 20");
                    $stmt2->execute([$q]);
                    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e2) { $rows = []; }
            }
            echo json_encode(['ok'=>true,'results'=>$rows]);
            exit;
        }

        // ---- دریافت یک فاکتور ----
        if ($action === 'get_one') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare(
                "SELECT i.*, COALESCE(p.company_name, p.name, i.customer_name) AS person_name
                 FROM fin_invoices i LEFT JOIN fin_persons p ON p.id=i.person_id
                 WHERE i.id=? AND i.is_deleted=0 AND i.type='buy'"
            );
            $st->execute([$id]);
            $inv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$inv) { echo json_encode(['ok'=>false,'msg'=>'فاکتور یافت نشد.']); exit; }
            $stI = $pdo->prepare('SELECT * FROM fin_invoice_items WHERE invoice_id=? ORDER BY row_order');
            $stI->execute([$id]);
            $inv['items']       = $stI->fetchAll(PDO::FETCH_ASSOC);
            $inv['date_jalali'] = jdate('Y/m/d', $inv['invoice_date']);
            echo json_encode(['ok'=>true,'invoice'=>$inv]);
            exit;
        }

        // ---- ذخیره پیش‌نویس ----
        if ($action === 'save_draft') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی.']); exit; }
            echo json_encode(saveBuyInvoice($pdo, 'draft', $userId, $fiscalYearId));
            exit;
        }

        // ---- تأیید ----
        if ($action === 'confirm_invoice') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی.']); exit; }
            echo json_encode(saveBuyInvoice($pdo, 'confirmed', $userId, $fiscalYearId));
            exit;
        }

        // ---- حذف نرم ----
        if ($action === 'delete') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی.']); exit; }
            $id  = (int)($_POST['id'] ?? 0);
            $chk = $pdo->prepare("SELECT status, created_by FROM fin_invoices WHERE id=? AND is_deleted=0");
            $chk->execute([$id]);
            $inv = $chk->fetch();
            if (!$inv) { echo json_encode(['ok'=>false,'msg'=>'فاکتور یافت نشد.']); exit; }
            if ($inv['status']==='confirmed' && strtolower($rawRole)!=='admin' && $inv['created_by']!=$userId) {
                echo json_encode(['ok'=>false,'msg'=>'فاکتور تأیید شده را نمی‌توان حذف کرد.']); exit;
            }
            $pdo->prepare('UPDATE fin_invoices SET is_deleted=1, updated_at=NOW() WHERE id=?')->execute([$id]);
            echo json_encode(['ok'=>true,'msg'=>'فاکتور حذف شد.']);
            exit;
        }

        echo json_encode(['ok'=>false,'msg'=>'درخواست نامعتبر.']);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('fin_invoice_buy AJAX: '.$e->getMessage());
        echo json_encode(['ok'=>false,'msg'=>'خطای سرور: '.$e->getMessage()]);
    }
    exit;
}

// ---- تابع ذخیره/تأیید فاکتور خرید ----
function saveBuyInvoice($pdo, $targetStatus, $userId, $fiscalYearId) {
    $editId   = (int)($_POST['invoice_id']   ?? 0);
    $personId = (int)($_POST['person_id']     ?? 0);
    $invDate  = trim(faToEn($_POST['invoice_date'] ?? ''));
    $dueDate  = trim(faToEn($_POST['due_date']     ?? '')) ?: null;
    $notes    = trim($_POST['notes'] ?? '');
    $shipping = (int)str_replace([',',' '],'',faToEn($_POST['shipping'] ?? '0'));
    $items    = $_POST['items'] ?? [];
    $invType  = in_array($_POST['invoice_type'] ?? '',['official','adjustment'])
                ? $_POST['invoice_type'] : 'official';
    $noTax    = ($invType === 'adjustment');

    if (!$personId) return ['ok'=>false,'msg'=>'انتخاب تأمین‌کننده الزامی است.'];
    if (empty($items)) return ['ok'=>false,'msg'=>'حداقل یک ردیف کالا الزامی است.'];
    if (!$invDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invDate))
        return ['ok'=>false,'msg'=>'فرمت تاریخ نادرست است.'];

    $stP = $pdo->prepare('SELECT COALESCE(company_name, name) AS n FROM fin_persons WHERE id=?');
    $stP->execute([$personId]);
    $personName = $stP->fetchColumn() ?: '';

    $subtotal = $totalDiscount = $totalTax = 0;
    $cleanItems = [];

    foreach ($items as $item) {
        $desc  = trim($item['description'] ?? '');
        if (!$desc) continue;
        $qty   = (float)faToEn($item['qty'] ?? 1);
        $price = (int)str_replace([',',' '],'',faToEn($item['unit_price'] ?? '0'));
        $discP = (float)faToEn($item['discount_pct'] ?? '0');
        $taxP  = $noTax ? 0.0 : (float)faToEn($item['tax_pct'] ?? '9');
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
            'stuff_id'=>$sid, 'description'=>$desc, 'unit'=>$unit, 'qty'=>$qty,
            'unit_price'=>$price, 'discount_pct'=>$discP, 'discount_amt'=>$dAmt,
            'tax_pct'=>$taxP, 'tax_amt'=>$tAmt, 'total'=>$total,
        ];
    }

    if (empty($cleanItems)) return ['ok'=>false,'msg'=>'ردیف‌های معتبر وارد نشده.'];

    $netPurchase = $subtotal - $totalDiscount;
    $totalAmount = $netPurchase + $totalTax + $shipping;

    $pdo->beginTransaction();

    if ($editId > 0) {
        $chk = $pdo->prepare("SELECT status FROM fin_invoices WHERE id=? AND is_deleted=0 AND type='buy'");
        $chk->execute([$editId]);
        $curStatus = $chk->fetchColumn();
        if ($curStatus && $curStatus !== 'draft' && $targetStatus === 'draft') {
            $pdo->rollBack();
            return ['ok'=>false,'msg'=>'فاکتور تأیید شده را نمی‌توان ویرایش کرد.'];
        }
        $pdo->prepare('DELETE FROM fin_invoice_items WHERE invoice_id=?')->execute([$editId]);
        $pdo->prepare(
            "UPDATE fin_invoices SET person_id=?,customer_name=?,invoice_date=?,due_date=?,
                     subtotal=?,discount=?,tax=?,shipping=?,total_amount=?,
                     status=?,notes=?,invoice_type=?,updated_at=NOW()
             WHERE id=? AND is_deleted=0"
        )->execute([
            $personId,$personName,$invDate,$dueDate,
            $subtotal,$totalDiscount,$totalTax,$shipping,$totalAmount,
            $targetStatus,$notes,$invType,$editId,
        ]);
        $invoiceId     = $editId;
        $invoiceNumber = $pdo->query("SELECT invoice_number FROM fin_invoices WHERE id=$editId")->fetchColumn();
    } else {
        $invoiceNumber = nextBuyNumber($pdo);
        $pdo->prepare(
            "INSERT INTO fin_invoices
                (invoice_number, invoice_date, due_date, type, invoice_type, person_id, customer_name,
                 subtotal, discount, tax, shipping, total_amount, paid_amount,
                 status, notes, fiscal_year_id, created_by, created_at)
             VALUES (?,?,?,'buy',?,?,?,?,?,?,?,?,0,?,?,?,?,NOW())"
        )->execute([
            $invoiceNumber,$invDate,$dueDate,$invType,$personId,$personName,
            $subtotal,$totalDiscount,$totalTax,$shipping,$totalAmount,
            $targetStatus,$notes,$fiscalYearId,$userId,
        ]);
        $invoiceId = (int)$pdo->lastInsertId();
    }

    $stItem = $pdo->prepare(
        "INSERT INTO fin_invoice_items
            (invoice_id,stuff_id,description,unit,qty,unit_price,
             discount_pct,discount_amt,tax_pct,tax_amt,total,row_order)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ($cleanItems as $idx => $it) {
        $stItem->execute([
            $invoiceId,$it['stuff_id'],$it['description'],$it['unit'],
            $it['qty'],$it['unit_price'],
            $it['discount_pct'],$it['discount_amt'],
            $it['tax_pct'],$it['tax_amt'],
            $it['total'],$idx+1,
        ]);
    }

    // ---- سند حسابداری دوطرفه (تأیید) ----
    $docId = null;
    if ($targetStatus === 'confirmed') {
        $accInventory = buyAccId($pdo, '120');  // بدهکار: موجودی کالا
        $accFreight   = buyAccId($pdo, '90');   // بدهکار: هزینه حمل
        $accTaxBuy    = buyAccId($pdo, '33');   // بدهکار: مالیات خرید
        $accPayable   = buyAccId($pdo, '8');    // بستانکار: حساب پرداختنی
        $accDiscount  = buyAccId($pdo, '51');   // بستانکار: تخفیف خرید

        $docDesc = $invType === 'adjustment'
            ? 'فاکتور تنظیمی خرید شماره '.$invoiceNumber
            : 'فاکتور رسمی خرید شماره '.$invoiceNumber;
        $pdo->prepare(
            "INSERT INTO fin_docs (doc_number,doc_date,type,description,fiscal_year_id,ref_id,ref_type,created_by,created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())"
        )->execute([
            $invoiceNumber,$invDate,
            $invType==='adjustment'?'buy_invoice_adj':'buy_invoice',
            $docDesc,$fiscalYearId,$invoiceId,'fin_invoice_buy',$userId,
        ]);
        $docId = (int)$pdo->lastInsertId();

        $stRow = $pdo->prepare(
            "INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,person_id,row_order)
             VALUES (?,?,?,?,?,?,?)"
        );
        $o = 1;

        // بدهکار: موجودی کالا = خالص خرید (بدون مالیات)
        if ($netPurchase > 0 && $accInventory)
            $stRow->execute([$docId,$accInventory,$netPurchase,0,'بدهکار — موجودی کالا',null,$o++]);

        // بدهکار: مالیات خرید
        if ($totalTax > 0 && $accTaxBuy)
            $stRow->execute([$docId,$accTaxBuy,$totalTax,0,'بدهکار — مالیات خرید',null,$o++]);

        // بدهکار: هزینه حمل
        if ($shipping > 0 && $accFreight)
            $stRow->execute([$docId,$accFreight,$shipping,0,'بدهکار — هزینه حمل',null,$o++]);

        // بستانکار: حساب پرداختنی = مبلغ کل
        if ($accPayable)
            $stRow->execute([$docId,$accPayable,0,$totalAmount,'بستانکار — حساب پرداختنی',$personId,$o++]);

        // بستانکار: تخفیف خرید (کاهنده بهای تمام شده)
        if ($totalDiscount > 0 && $accDiscount)
            $stRow->execute([$docId,$accDiscount,0,$totalDiscount,'بستانکار — تخفیفات خرید',null,$o++]);

        $pdo->prepare('UPDATE fin_invoices SET fin_doc_id=?,updated_at=NOW() WHERE id=?')->execute([$docId,$invoiceId]);

        // رسید انبار خودکار
        try {
            $hasStuffs = false;
            foreach ($cleanItems as $it) { if ($it['stuff_id']) { $hasStuffs=true; break; } }
            if ($hasStuffs) {
                $storoomId = $pdo->query("SELECT id FROM inv_storerooms WHERE is_active=1 AND is_deleted=0 ORDER BY id LIMIT 1")->fetchColumn();
                if ($storoomId) {
                    $seq  = $pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM inv_tickets")->fetchColumn();
                    $tNum = 'RC-'.date('Y').'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
                    $pdo->prepare(
                        "INSERT INTO inv_tickets (ticket_number,type,ticket_date,storeroom_id,person_id,ref_type,ref_id,status,created_by)
                         VALUES (?,?,?,?,?,?,?,?,?)"
                    )->execute([$tNum,'receipt',date('Y-m-d'),$storoomId,$personId,'invoice_buy',$invoiceId,'confirmed',$userId]);
                    $tid = (int)$pdo->lastInsertId();
                    foreach ($cleanItems as $it) {
                        if (!$it['stuff_id']) continue;
                        $pdo->prepare("INSERT INTO inv_ticket_items (ticket_id,stuff_id,qty,unit_price,total) VALUES (?,?,?,?,?)")
                            ->execute([$tid,$it['stuff_id'],$it['qty'],$it['unit_price'],$it['total']]);
                        $pdo->prepare("UPDATE stuff_price_list SET total_inventory=total_inventory+? WHERE stuff_id=?")
                            ->execute([$it['qty'],$it['stuff_id']]);
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    $pdo->commit();

    return [
        'ok'             => true,
        'invoice_id'     => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'status'         => $targetStatus,
        'doc_id'         => $docId,
        'msg'            => $targetStatus === 'confirmed'
            ? 'فاکتور خرید تأیید شد — سند حسابداری و رسید انبار ثبت گردید.'
            : 'پیش‌نویس فاکتور خرید ذخیره شد.',
    ];
}

// ===========================================================
// رندر HTML
// ===========================================================
$basePath  = '../../';
$pageTitle = 'فاکتور خرید';
$csrfToken = csrf_token();

$statsInit = ['count'=>0,'total_sum'=>'0','paid_sum'=>'0','remain'=>'0'];
try {
    $s = $pdo->query(
        "SELECT COUNT(*) cnt, COALESCE(SUM(total_amount),0) ts, COALESCE(SUM(paid_amount),0) ps
         FROM fin_invoices WHERE is_deleted=0 AND type='buy'"
    )->fetch();
    $statsInit = [
        'count'     => (int)$s['cnt'],
        'total_sum' => number_format((int)$s['ts']),
        'paid_sum'  => number_format((int)$s['ps']),
        'remain'    => number_format(max(0,(int)$s['ts']-(int)$s['ps'])),
    ];
} catch (Throwable $e) {}

$extraCss = '<link rel="stylesheet" href="'.$basePath.'assets/css/fin_module.css">';
require_once __DIR__ . '/../../templates/header.php';
?>
<style>
/* ===== فاکتور خرید — تم بنفش ===== */
.inv-page-icon{background:linear-gradient(135deg,#5b21b6,#7c3aed)!important;box-shadow:0 6px 16px rgba(124,58,237,.35)!important}
.inv-form-hdr{background:linear-gradient(135deg,#5b21b6,#7c3aed)!important}
.inv-sec-title::before{background:#7c3aed!important}
.inv-inp:focus{border-color:#7c3aed!important;background:#faf5ff!important}
.inv-add-btn:hover{border-color:#7c3aed!important;color:#7c3aed!important;background:#faf5ff!important}
.inv-summary-box{background:linear-gradient(135deg,#faf5ff,#f3e8ff)!important;border-color:#e9d5ff!important}
.inv-sum-total .val{color:#7c3aed!important}
.inv-page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.inv-page-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff}
.inv-page-title{display:flex;align-items:center;gap:12px}
.inv-page-title h1{font-size:1.25rem;font-weight:900;color:#1e293b;margin:0}
.inv-page-title p{font-size:.78rem;color:#64748b;margin:3px 0 0}
.inv-form-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06)}
.inv-form-hdr{padding:18px 26px;display:flex;align-items:center;justify-content:space-between;color:#fff}
.inv-form-hdr-title{font-size:1.05rem;font-weight:900}
.inv-form-hdr-num{font-size:.95rem;font-weight:700;direction:ltr;background:rgba(255,255,255,.18);padding:5px 14px;border-radius:8px}
.inv-form-body{padding:26px}
.inv-sec-title{font-size:.8rem;font-weight:900;color:#64748b;margin:0 0 12px;display:flex;align-items:center;gap:6px;text-transform:uppercase;letter-spacing:.5px}
.inv-meta-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px;margin-bottom:24px}
.inv-items-section{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:22px}
.inv-items-thead{background:#f8fafc;display:grid;grid-template-columns:34px 1fr 60px 80px 130px 70px 70px 110px 38px;gap:0;padding:9px 12px;border-bottom:2px solid #e2e8f0}
.inv-items-thead span{font-size:.72rem;font-weight:800;color:#64748b;white-space:nowrap}
.inv-item-row{display:grid;grid-template-columns:34px 1fr 60px 80px 130px 70px 70px 110px 38px;gap:0;padding:7px 12px;border-bottom:1px solid #f1f5f9;align-items:center}
.inv-item-row:last-child{border-bottom:none}
.inv-item-row:hover{background:#fdf9ff}
.row-num{font-size:.75rem;color:#94a3b8;font-weight:700;text-align:center}
.inv-inp{width:100%;border:1.5px solid transparent;border-radius:6px;padding:5px 7px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.8rem;color:#1e293b;background:transparent;direction:rtl}
.inv-inp.n{direction:ltr;text-align:right}
.inv-item-total{font-size:.8rem;font-weight:800;color:#1e293b;direction:ltr;text-align:right;padding:0 4px}
.inv-row-del{width:28px;height:28px;border:none;background:#fff1f2;color:#e11d48;border-radius:6px;cursor:pointer;font-size:.85rem;display:flex;align-items:center;justify-content:center;margin:auto}
.inv-row-del:hover{background:#fecdd3}
.inv-add-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border:2px dashed #cbd5e1;border-radius:8px;background:none;color:#64748b;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;margin:10px 12px;transition:all .2s}
.inv-summary-box{border:1px solid;border-radius:14px;padding:20px 24px;min-width:300px}
.inv-sum-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:.85rem;color:#475569;border-bottom:1px dashed #e2e8f0}
.inv-sum-row:last-child{border-bottom:none}
.inv-sum-row .lbl{font-weight:600}
.inv-sum-row .val{font-weight:700;direction:ltr}
.inv-sum-total{display:flex;justify-content:space-between;align-items:center;padding:10px 0 0;margin-top:4px}
.inv-sum-total .lbl{font-size:.95rem;font-weight:900;color:#1e293b}
.inv-sum-total .val{font-size:1.25rem;font-weight:900;direction:ltr}
.inv-bottom-row{display:flex;justify-content:space-between;align-items:flex-start;gap:22px;flex-wrap:wrap;margin-top:18px}
.inv-notes-wrap{flex:1;min-width:240px}
.ac-wrap{position:relative}
.ac-dd{display:none;position:absolute;top:calc(100% + 4px);right:0;left:0;background:#fff;border:1.5px solid #e9d5ff;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:600;max-height:220px;overflow-y:auto}
.ac-dd.open{display:block}
.ac-it{padding:9px 13px;font-size:.83rem;cursor:pointer;color:#1e293b}
.ac-it:hover{background:#faf5ff;color:#7c3aed}
.ac-it.nr{color:#94a3b8;cursor:default}
.inv-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700;white-space:nowrap}
.inv-badge.draft{background:#f1f5f9;color:#64748b}
.inv-badge.confirmed{background:#dcfce7;color:#15803d}
.inv-badge.paid{background:#d1fae5;color:#065f46}
.inv-badge.partial{background:#fef3c7;color:#92400e}
.inv-badge.cancelled{background:#fee2e2;color:#b91c1c}
.inv-loading{display:flex;justify-content:center;padding:40px}
.inv-spin{width:36px;height:36px;border:3px solid #e2e8f0;border-top-color:#7c3aed;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.fin-empty{text-align:center;padding:60px 20px;color:#94a3b8}
.fin-empty .empty-icon{font-size:3rem;margin-bottom:12px}
.tax-note{display:none;margin:8px 0 12px;padding:8px 14px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:.8rem;color:#92400e;font-weight:600}
@media print{.inv-no-print{display:none!important}}
</style>

<div class="fin-container">

    <!-- هدر -->
    <div class="inv-page-header inv-no-print">
        <div class="inv-page-title">
            <div class="inv-page-icon">🛒</div>
            <div>
                <h1>فاکتور خرید</h1>
                <p>ثبت و مدیریت فاکتورهای خرید از تأمین‌کنندگان</p>
            </div>
        </div>
        <button class="fin-btn fin-btn-primary" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);border:none" onclick="switchTab('new');resetForm()">
            + فاکتور خرید جدید
        </button>
    </div>

    <!-- تب‌ها -->
    <div class="fin-tabs inv-no-print" id="mainTabs">
        <button class="fin-tab active" id="tab-list"  onclick="switchTab('list')">📋 لیست فاکتورها</button>
        <button class="fin-tab"        id="tab-new"   onclick="switchTab('new');resetForm()">➕ فاکتور جدید</button>
    </div>

    <!-- ===== تب لیست ===== -->
    <div id="pane-list">
        <!-- آمار -->
        <div class="fin-stats-grid inv-no-print">
            <div class="fin-stat-card blue">
                <div class="stat-label">تعداد فاکتور</div>
                <div class="stat-value" id="stat-count"><?= number_format($statsInit['count']) ?></div>
            </div>
            <div class="fin-stat-card purple">
                <div class="stat-label">جمع کل (ریال)</div>
                <div class="stat-value" id="stat-total"><?= $statsInit['total_sum'] ?></div>
            </div>
            <div class="fin-stat-card green">
                <div class="stat-label">پرداخت شده (ریال)</div>
                <div class="stat-value" id="stat-paid"><?= $statsInit['paid_sum'] ?></div>
            </div>
            <div class="fin-stat-card rose">
                <div class="stat-label">مانده (ریال)</div>
                <div class="stat-value" id="stat-remain"><?= $statsInit['remain'] ?></div>
            </div>
        </div>

        <!-- فیلتر -->
        <div class="fin-filter-bar inv-no-print" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:18px;background:#fff;padding:14px 18px;border-radius:14px;border:1px solid #e2e8f0">
            <input type="date" class="fin-input" id="f-from" placeholder="از تاریخ" style="max-width:160px">
            <input type="date" class="fin-input" id="f-to"   placeholder="تا تاریخ" style="max-width:160px">
            <select class="fin-input" id="f-status" style="max-width:160px">
                <option value="">همه وضعیت‌ها</option>
                <option value="draft">پیش‌نویس</option>
                <option value="confirmed">تأیید شده</option>
                <option value="paid">پرداخت کامل</option>
                <option value="partial">پرداخت ناقص</option>
                <option value="cancelled">لغو شده</option>
            </select>
            <button class="fin-btn fin-btn-primary" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);border:none" onclick="loadList(1)">🔍 جستجو</button>
            <button class="fin-btn fin-btn-outline" onclick="resetFilter()">پاک‌کردن</button>
        </div>

        <div id="listContainer"><div class="inv-loading"><div class="inv-spin"></div></div></div>

        <div class="inv-no-print" id="pagination" style="display:none;display:flex;align-items:center;justify-content:space-between;padding:14px 0;flex-wrap:wrap;gap:10px">
            <span id="pag-info" style="font-size:.82rem;color:#64748b"></span>
            <div style="display:flex;gap:8px">
                <button class="fin-btn fin-btn-outline fin-btn-sm" id="btn-prev" onclick="changePage(-1)">◀ قبلی</button>
                <button class="fin-btn fin-btn-outline fin-btn-sm" id="btn-next" onclick="changePage(1)">بعدی ▶</button>
            </div>
        </div>
    </div>

    <!-- ===== تب فرم ===== -->
    <div id="pane-new" style="display:none">
        <div class="inv-form-wrap">
            <div class="inv-form-hdr">
                <div class="inv-form-hdr-title" id="formTitle">🛒 فاکتور خرید جدید</div>
                <div class="inv-form-hdr-num"   id="formNum">شماره: خودکار</div>
            </div>
            <div class="inv-form-body">

                <!-- نوع فاکتور -->
                <div style="margin-bottom:20px">
                    <div class="inv-sec-title">نوع فاکتور</div>
                    <input type="hidden" id="invTypeHidden" value="official">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                        <label id="lblOfficial" style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #7c3aed;background:#faf5ff;font-weight:700;color:#7c3aed;transition:all .2s">
                            <input type="radio" name="invTypeRadio" value="official" checked onchange="onInvTypeChange(this.value)" style="accent-color:#7c3aed;width:16px;height:16px">
                            <span>رسمی</span>
                        </label>
                        <label id="lblAdjustment" style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;transition:all .2s">
                            <input type="radio" name="invTypeRadio" value="adjustment" onchange="onInvTypeChange(this.value)" style="accent-color:#7c3aed;width:16px;height:16px">
                            <span>تنظیمی</span>
                        </label>
                        <label id="lblNoTax" style="display:flex;align-items:center;gap:7px;cursor:pointer;padding:10px 16px;border-radius:10px;border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;transition:all .2s">
                            <input type="checkbox" id="chkNoTax" onchange="onNoTaxChange(this.checked)" style="accent-color:#7c3aed;width:16px;height:16px">
                            <span>بدون مالیات</span>
                        </label>
                    </div>
                    <div class="tax-note" id="taxNote">⚠️ مالیات برای این فاکتور اعمال نمی‌شود.</div>
                </div>

                <!-- متادیتا -->
                <div class="inv-meta-grid">
                    <div>
                        <div class="inv-sec-title">تأمین‌کننده</div>
                        <div class="ac-wrap">
                            <input type="text" class="fin-input" id="personSearch" placeholder="جستجوی نام تأمین‌کننده..." autocomplete="off" oninput="onPersonSearch(this.value)">
                            <input type="hidden" id="personId">
                            <div class="ac-dd" id="personDd"></div>
                        </div>
                    </div>
                    <div>
                        <div class="inv-sec-title">تاریخ فاکتور</div>
                        <input type="date" class="fin-input" id="invDate">
                    </div>
                    <div>
                        <div class="inv-sec-title">تاریخ سررسید</div>
                        <input type="date" class="fin-input" id="dueDate">
                    </div>
                </div>

                <!-- ردیف‌های کالا -->
                <div class="inv-sec-title">اقلام فاکتور</div>
                <div class="inv-items-section">
                    <div class="inv-items-thead">
                        <span>#</span>
                        <span>شرح کالا / خدمت</span>
                        <span>واحد</span>
                        <span>تعداد</span>
                        <span>قیمت واحد</span>
                        <span>تخفیف %</span>
                        <span class="tax-col">مالیات %</span>
                        <span>مبلغ ردیف</span>
                        <span></span>
                    </div>
                    <div id="invItems"></div>
                    <button class="inv-add-btn" onclick="addRow()">+ افزودن ردیف</button>
                </div>

                <!-- جمع‌بندی و توضیحات -->
                <div class="inv-bottom-row">
                    <div class="inv-notes-wrap">
                        <div class="inv-sec-title">توضیحات</div>
                        <textarea class="fin-input" id="invNotes" rows="4" placeholder="توضیحات اختیاری..."></textarea>
                        <div style="margin-top:14px">
                            <div class="inv-sec-title">هزینه حمل (ریال)</div>
                            <input type="text" class="fin-input" id="invShipping" value="۰" oninput="formatNum(this);recalcAll()" style="max-width:200px">
                        </div>
                    </div>
                    <div class="inv-summary-box">
                        <div class="inv-sum-row"><span class="lbl">جمع اقلام</span><span class="val" id="s-sub">۰ ریال</span></div>
                        <div class="inv-sum-row"><span class="lbl">تخفیف</span><span class="val" id="s-disc">— ۰ ریال</span></div>
                        <div class="inv-sum-row tax-col"><span class="lbl">مالیات</span><span class="val" id="s-tax">+ ۰ ریال</span></div>
                        <div class="inv-sum-row"><span class="lbl">حمل</span><span class="val" id="s-ship">+ ۰ ریال</span></div>
                        <div style="border-top:2px solid #7c3aed;margin:8px 0 4px"></div>
                        <div class="inv-sum-total"><span class="lbl">مبلغ نهایی</span><span class="val" id="s-total">۰ ریال</span></div>
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
                    <button class="fin-btn fin-btn-outline" style="margin-right:auto" onclick="resetForm();switchTab('list')">❌ انصراف</button>
                </div>

            </div>
        </div>
    </div>

</div><!-- /fin-container -->

<script>
var curPage=1, totPages=1, rowCnt=0, editMode=false;
var personTimer=null, stuffTimers={};

function numFa(n){
    return String(n||0).replace(/\B(?=(\d{3})+(?!\d))/g,',')
        .replace(/0/g,'۰').replace(/1/g,'۱').replace(/2/g,'۲').replace(/3/g,'۳')
        .replace(/4/g,'۴').replace(/5/g,'۵').replace(/6/g,'۶').replace(/7/g,'۷')
        .replace(/8/g,'۸').replace(/9/g,'۹');
}
function numEn(s){
    return String(s||'').replace(/[۰-۹]/g,function(c){return c.charCodeAt(0)-1776;});
}
function rawNum(el){
    return parseInt(numEn((el.value||'').replace(/,/g,'')).replace(/[^\d]/g,''))||0;
}
function formatNum(el){
    var v=rawNum(el);
    el.value = v>0 ? numFa(v) : '';
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function escJ(s){return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"');}

function switchTab(t){
    ['list','new'].forEach(function(id){
        document.getElementById('pane-'+id).style.display = id===t?'':'none';
        document.getElementById('tab-'+id).classList.toggle('active', id===t);
    });
    if(t==='list') loadList(curPage);
}

// ============================================================
// لیست
// ============================================================
function loadList(page){
    curPage=page;
    document.getElementById('listContainer').innerHTML='<div class="inv-loading"><div class="inv-spin"></div></div>';
    var fd=new FormData();
    fd.append('action','list'); fd.append('page',page);
    fd.append('date_from', document.getElementById('f-from').value||'');
    fd.append('date_to',   document.getElementById('f-to').value||'');
    fd.append('status',    document.getElementById('f-status').value||'');
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
        document.getElementById('listContainer').innerHTML='<div class="fin-empty"><div class="empty-icon">🛒</div><p>هیچ فاکتور خریدی یافت نشد.</p></div>';
        document.getElementById('pagination').style.display='none';
        return;
    }
    var h='<div style="overflow-x:auto"><table class="fin-table"><thead><tr>'
        +'<th>شماره</th><th>نوع</th><th>تاریخ</th><th>تأمین‌کننده</th>'
        +'<th>مبلغ کل (ریال)</th><th>پرداخت شده</th><th>مانده</th>'
        +'<th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
    rows.forEach(function(r){
        var badge='<span class="inv-badge '+r.status_class+'">'+r.status_label+'</span>';
        var typeBadge = r.invoice_type === 'adjustment'
            ? '<span style="font-size:.7rem;background:#f3e8ff;color:#7c3aed;padding:2px 7px;border-radius:8px;white-space:nowrap">تنظیمی</span>'
            : '<span style="font-size:.7rem;background:#faf5ff;color:#5b21b6;padding:2px 7px;border-radius:8px;white-space:nowrap">رسمی</span>';
        h+='<tr>'
          +'<td><strong style="color:#7c3aed;direction:ltr;display:inline-block">'+esc(r.invoice_number)+'</strong></td>'
          +'<td>'+typeBadge+'</td>'
          +'<td style="direction:ltr">'+esc(r.date_jalali)+'</td>'
          +'<td>'+esc(r.person_name||'—')+'</td>'
          +'<td style="direction:ltr;font-weight:700">'+r.total_fmt+'</td>'
          +'<td style="direction:ltr;color:#059669">'+r.paid_fmt+'</td>'
          +'<td style="direction:ltr;color:#e11d48">'+r.remain_fmt+'</td>'
          +'<td>'+badge+'</td>'
          +'<td style="white-space:nowrap">'
          +'<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="viewInv('+r.id+')">👁</button> '
          +(r.status!=='draft'?'<a class="fin-btn fin-btn-outline fin-btn-sm" href="fin_invoice_pdf.php?id='+r.id+'&type=buy" target="_blank" title="چاپ PDF">🖨️</a> ':'')
          +(r.status==='draft'?'<button class="fin-btn fin-btn-primary fin-btn-sm" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);border:none" onclick="editInv('+r.id+')">✏️</button> ':'')
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
    var fd=new FormData(); fd.append('action','get_one'); fd.append('id',id);
    post(fd,function(res){
        if(!res.ok){showToast(res.msg,'danger');return;}
        var inv=res.invoice;
        var sLabels={draft:'پیش‌نویس',confirmed:'تأیید شده',paid:'پرداخت کامل',partial:'پرداخت ناقص',cancelled:'لغو شده'};
        var sLabel=sLabels[inv.status]||inv.status;
        var itemsHtml='';
        (inv.items||[]).forEach(function(it,i){
            itemsHtml+='<tr><td>'+(i+1)+'</td><td>'+esc(it.description)+'</td>'
                +'<td style="direction:ltr">'+numFa(it.qty)+'</td>'
                +'<td style="direction:ltr">'+numFa(it.unit_price)+'</td>'
                +'<td style="direction:ltr;color:#e11d48">'+numFa(it.discount_amt)+'</td>'
                +'<td style="direction:ltr">'+numFa(it.tax_amt)+'</td>'
                +'<td style="direction:ltr;font-weight:800">'+numFa(it.total)+'</td></tr>';
        });
        var html=`
<div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:14px;padding:18px;margin-bottom:18px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
    <div><span style="font-size:.75rem;color:#64748b">تأمین‌کننده</span>
         <div style="font-weight:800;font-size:1rem">${esc(inv.person_name||'—')}</div></div>
    <span class="inv-badge ${inv.status}">${sLabel}</span>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
    <div><span style="color:#64748b;font-size:.73rem">شماره فاکتور</span><br><strong style="direction:ltr;display:inline-block">${esc(inv.invoice_number)}</strong></div>
    <div><span style="color:#64748b;font-size:.73rem">تاریخ</span><br><strong style="direction:ltr;display:inline-block">${esc(inv.date_jalali)}</strong></div>
    ${inv.fin_doc_id?'<div><span style="color:#64748b;font-size:.73rem">شماره سند</span><br><strong style="color:#7c3aed">#'+inv.fin_doc_id+'</strong></div>':'<div></div>'}
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
  <div style="border-top:2px solid #7c3aed;margin:8px 0 4px"></div>
  <div class="inv-sum-total"><span class="lbl">مبلغ نهایی</span><span class="val">${numFa(inv.total_amount)} ریال</span></div>
  <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #e2e8f0">
    <div class="inv-sum-row"><span class="lbl">پرداخت شده</span><span class="val" style="color:#059669">${numFa(inv.paid_amount)} ریال</span></div>
    <div class="inv-sum-row"><span class="lbl">مانده</span><span class="val" style="color:#e11d48">${numFa(Math.max(0,inv.total_amount-inv.paid_amount))} ریال</span></div>
  </div>
</div>
${inv.notes?'<div style="margin-top:14px;padding:10px 14px;background:#fffbeb;border-radius:8px;font-size:.83rem;color:#92400e"><strong>توضیحات: </strong>'+esc(inv.notes)+'</div>':''}
        `;
        // نمایش در drawer
        var d=document.createElement('div');
        d.style.cssText='position:fixed;inset:0;z-index:800;display:flex;align-items:flex-start;justify-content:center;padding:60px 20px;overflow-y:auto;background:rgba(0,0,0,.4)';
        d.onclick=function(e){if(e.target===d)d.remove();};
        var box=document.createElement('div');
        box.style.cssText='background:#fff;border-radius:18px;width:100%;max-width:780px;padding:26px;box-shadow:0 20px 60px rgba(0,0,0,.2);direction:rtl;font-family:Vazirmatn,Tahoma,sans-serif';
        box.innerHTML='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">'
            +'<h2 style="margin:0;font-size:1.1rem;font-weight:900">فاکتور خرید '+esc(inv.invoice_number)+'</h2>'
            +'<button onclick="this.closest(\'div[style*=fixed]\').remove()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#64748b">✕</button>'
            +'</div>'+html;
        if(inv.status!=='draft'){
            box.innerHTML+='<div style="margin-top:16px"><a class="fin-btn fin-btn-outline" href="fin_invoice_pdf.php?id='+id+'&type=buy" target="_blank">🖨️ چاپ PDF</a></div>';
        }
        d.appendChild(box);
        document.body.appendChild(d);
    });
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
        document.getElementById('formTitle').textContent='✏️ ویرایش فاکتور خرید';
        document.getElementById('formNum').textContent='شماره: '+inv.invoice_number;
        var itype=inv.invoice_type||'official';
        document.getElementById('invTypeHidden').value=itype;
        document.querySelectorAll('[name="invTypeRadio"]').forEach(function(r){r.checked=(r.value===itype);});
        var allTaxZero = inv.items && inv.items.length && inv.items.every(function(it){return !parseFloat(it.tax_pct);});
        var chk=document.getElementById('chkNoTax');
        chk.checked=(itype==='adjustment')||allTaxZero;
        chk.disabled=(itype==='adjustment');
        onInvTypeChange(itype);
        (inv.items||[]).forEach(function(it){addRow(it.stuff_id,it.description,it.unit,it.qty,it.unit_price,it.discount_pct,it.tax_pct);});
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
    document.getElementById('formTitle').textContent='🛒 فاکتور خرید جدید';
    document.getElementById('formNum').textContent='شماره: خودکار';
    document.getElementById('invTypeHidden').value='official';
    document.querySelectorAll('[name="invTypeRadio"]').forEach(function(r){r.checked=(r.value==='official');});
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
        +'<input type="text" class="inv-inp n" id="p-'+idx+'" value="'+(p>0?numFa(p):'')+'\" placeholder="۰" oninput="formatNum(this);recalcRow('+idx+')">'
        +'<input type="number" class="inv-inp n" id="disc-'+idx+'" value="'+dp+'" min="0" max="100" step="0.01" oninput="recalcRow('+idx+')">'
        +'<input type="number" class="inv-inp n tax-col" id="tax-'+idx+'" value="'+tp+'" min="0" max="100" step="0.01" oninput="recalcRow('+idx+')">'
        +'<div class="inv-item-total" id="tot-'+idx+'">۰</div>'
        +'<button class="inv-row-del" onclick="removeRow('+idx+')" title="حذف">✕</button>';
    document.getElementById('invItems').appendChild(row);
    recalcRow(idx);
    if(!desc) setTimeout(function(){var el=document.getElementById('d-'+idx);if(el)el.focus();},50);
}

function removeRow(idx){var r=document.getElementById('row-'+idx);if(r)r.remove();recalcAll();}

function recalcRow(idx){
    var pEl=document.getElementById('p-'+idx),qEl=document.getElementById('q-'+idx),
        dEl=document.getElementById('disc-'+idx),tEl=document.getElementById('tax-'+idx),
        totEl=document.getElementById('tot-'+idx);
    if(!pEl)return;
    var p=rawNum(pEl),q=parseFloat(qEl.value)||0,d=parseFloat(dEl.value)||0,t=parseFloat(tEl.value)||0;
    var gross=Math.round(q*p),dAmt=Math.round(gross*d/100),after=gross-dAmt,tAmt=Math.round(after*t/100);
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
        sub+=gross;disc+=dAmt;tax+=Math.round(after*t/100);
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
// نوع فاکتور / مالیات
// ============================================================
function onInvTypeChange(val){
    document.getElementById('invTypeHidden').value=val;
    var isAdj=(val==='adjustment');
    document.getElementById('lblOfficial').style.cssText=!isAdj
        ?'display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #7c3aed;background:#faf5ff;font-weight:700;color:#7c3aed;transition:all .2s'
        :'display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;transition:all .2s';
    document.getElementById('lblAdjustment').style.cssText=isAdj
        ?'display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #7c3aed;background:#faf5ff;font-weight:700;color:#7c3aed;transition:all .2s'
        :'display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 18px;border-radius:10px;border:2px solid #e2e8f0;background:#f8fafc;color:#64748b;transition:all .2s';
    var noTax=isAdj||document.getElementById('chkNoTax').checked;
    applyTaxState(noTax);
    var chk=document.getElementById('chkNoTax');
    if(isAdj){chk.checked=true;chk.disabled=true;}
    else{chk.disabled=false;}
    recalcAll();
}

function onNoTaxChange(checked){
    var isAdj=(document.getElementById('invTypeHidden').value==='adjustment');
    applyTaxState(isAdj||checked);
    document.getElementById('taxNote').style.display=(isAdj||checked)?'block':'none';
    recalcAll();
}

function applyTaxState(noTax){
    document.querySelectorAll('[id^="tax-"]').forEach(function(el){
        if(noTax){el.value='0';el.style.opacity='.4';el.style.pointerEvents='none';}
        else{if(el.value==='0')el.value='9';el.style.opacity='1';el.style.pointerEvents='';}
    });
    document.querySelectorAll('.tax-col').forEach(function(el){
        el.style.opacity=noTax?'.4':'1';
        el.style.pointerEvents=noTax?'none':'';
    });
    document.getElementById('taxNote').style.display=noTax?'block':'none';
}

// ============================================================
// Autocomplete تأمین‌کننده
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
                dd.innerHTML='<div class="ac-it nr">کالایی یافت نشد.</div>';
            } else {
                dd.innerHTML=res.results.map(function(c){
                    return '<div class="ac-it" onclick="selStuff('+idx+','+c.id+',\''+escJ(c.name)+'\\',\''+escJ(c.unit||'')+'\','+(parseInt(c.price)||0)+')">'
                        +esc(c.name)+(c.unit?' <small style="color:#94a3b8">('+c.unit+')</small>':'')
                        +' — <span style="color:#7c3aed;direction:ltr;display:inline-block">'+numFa(c.price)+' ریال</span>'
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
    document.getElementById('p-'+idx).value=numFa(price);
    document.getElementById('sdd-'+idx).classList.remove('open');
    recalcRow(idx);
    var qEl=document.getElementById('q-'+idx);
    if(qEl){qEl.focus();qEl.select();}
}

document.addEventListener('click',function(e){
    if(!e.target.closest('.ac-wrap')&&!e.target.closest('.inv-item-row')){
        document.querySelectorAll('.ac-dd').forEach(function(d){d.classList.remove('open');});
    }
});

// ============================================================
// ذخیره فاکتور
// ============================================================
function doSave(mode){
    var personId=document.getElementById('personId').value;
    var invDate=document.getElementById('invDate').value;
    if(!personId){showToast('لطفاً تأمین‌کننده را انتخاب کنید.','danger');document.getElementById('personSearch').focus();return;}
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

// ============================================================
// AJAX
// ============================================================
function post(fd,cb){
    fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(function(r){return r.json();}).then(cb)
    .catch(function(){showToast('خطای ارتباطی رخ داد.','danger');});
}

function showToast(msg,type){
    var colors={success:'#059669',danger:'#e11d48',warning:'#d97706',info:'#7c3aed'};
    var d=document.createElement('div');
    d.textContent=msg;
    d.style.cssText='position:fixed;top:20px;left:50%;transform:translateX(-50%);background:'+(colors[type]||colors.info)+';color:#fff;padding:11px 22px;border-radius:10px;font-family:Vazirmatn,Tahoma,sans-serif;font-weight:700;font-size:.88rem;z-index:9999;box-shadow:0 6px 20px rgba(0,0,0,.2)';
    document.body.appendChild(d);
    setTimeout(function(){d.remove();},3500);
}

document.addEventListener('DOMContentLoaded',function(){
    document.getElementById('invDate').value=today();
    loadList(1);
    addRow();
});
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
