<?php
/*
 * فایل: public_html/admin/fin_petty_cash.php
 * ماژول تنخواه‌گردان
 * ارتباط: fin_chart_of_accounts (حساب تنخواه) + fin_bank_accounts/fin_cashdesks (منبع تأمین)
 * سند دوطرفه: تخصیص = بدهکار تنخواه / بستانکار بانک یا صندوق
 *             هزینه    = بدهکار هزینه / بستانکار تنخواه
 *             تسویه    = بدهکار بانک / بستانکار تنخواه
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

$isAdmin = false;
try {
    $rc = $pdo->prepare('SELECT name, is_system FROM roles WHERE id=? OR name=?');
    $rc->execute([$rawRole, $rawRole]);
    $rd = $rc->fetch(PDO::FETCH_ASSOC);
    if ($rd) {
        $rn = strtolower(trim($rd['name'] ?? $rawRole));
        if (in_array($rn, ['admin','management','manager','finance_manager','accountant']) || $rd['is_system'] == 1) $isAdmin = true;
    }
    if (in_array($rawRole, ['1','2'])) $isAdmin = true;
} catch (Throwable $e) { $isAdmin = true; }

// ── ایجاد جداول ──────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_petty_cash` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `name`            VARCHAR(150) NOT NULL,
        `holder_user_id`  INT DEFAULT NULL COMMENT 'دارنده تنخواه',
        `account_id`      INT DEFAULT NULL COMMENT 'سرفصل تنخواه در fin_chart_of_accounts',
        `source_type`     ENUM('bank','cashdesk') NOT NULL DEFAULT 'bank',
        `source_id`       INT NOT NULL COMMENT 'شناسه بانک یا صندوق',
        `allocated_amount` BIGINT NOT NULL DEFAULT 0 COMMENT 'مبلغ کل تخصیص‌داده‌شده',
        `balance`         BIGINT NOT NULL DEFAULT 0 COMMENT 'مانده فعلی',
        `notes`           TEXT DEFAULT NULL,
        `is_active`       TINYINT NOT NULL DEFAULT 1,
        `created_by`      INT DEFAULT NULL,
        `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_petty_cash_items` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `petty_cash_id` INT NOT NULL,
        `item_type`     ENUM('allocate','expense','replenish','settle') NOT NULL DEFAULT 'expense',
        `item_date`     DATE NOT NULL,
        `description`   VARCHAR(300) NOT NULL,
        `amount`        BIGINT NOT NULL DEFAULT 0,
        `expense_acc_id` INT DEFAULT NULL COMMENT 'حساب هزینه (برای نوع expense)',
        `fin_doc_id`    INT DEFAULT NULL,
        `created_by`    INT DEFAULT NULL,
        `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
        `is_deleted`    TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ── توابع کمکی ───────────────────────────────────────────────
function getActiveFy($pdo) {
    try { return (int)$pdo->query("SELECT COALESCE(MAX(id),1) FROM fiscal_years WHERE status='active'")->fetchColumn() ?: 1; } catch (Throwable $e) { return 1; }
}
function nextDoc($pdo) {
    try { $m = $pdo->query("SELECT COALESCE(MAX(CAST(doc_number AS UNSIGNED)),0) FROM fin_docs")->fetchColumn(); return str_pad((int)$m+1,5,'0',STR_PAD_LEFT); } catch (Throwable $e) { return date('YmdHis'); }
}

// ═══════════════════════════════════════════════════════════════
// AJAX
// ═══════════════════════════════════════════════════════════════
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_REQUEST['action'] ?? '');

    try {

        // ── لیست تنخواه‌ها ──
        if ($action === 'get_list') {
            $rows = $pdo->query(
                "SELECT pc.*, u.full_name holder_name,
                        ca.name acc_name, ca.code acc_code,
                        (SELECT COALESCE(SUM(pci.amount),0) FROM fin_petty_cash_items pci
                         WHERE pci.petty_cash_id=pc.id AND pci.item_type='expense' AND pci.is_deleted=0) spent
                 FROM fin_petty_cash pc
                 LEFT JOIN users u ON u.id=pc.holder_user_id
                 LEFT JOIN fin_chart_of_accounts ca ON ca.id=pc.account_id
                 WHERE pc.is_active=1
                 ORDER BY pc.id DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'rows'=>$rows]);
            exit;
        }

        // ── ایجاد تنخواه جدید ──
        if ($action === 'create') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $name       = trim($_POST['name'] ?? '');
            $holderId   = (int)($_POST['holder_user_id'] ?? 0);
            $accId      = (int)($_POST['account_id']     ?? 0);
            $srcType    = ($_POST['source_type'] ?? 'bank') === 'cashdesk' ? 'cashdesk' : 'bank';
            $srcId      = (int)($_POST['source_id']      ?? 0);
            $amount     = (int)str_replace([',',' '], '', faToEn($_POST['amount'] ?? '0'));
            $dateJ      = trim(faToEn($_POST['item_date'] ?? ''));
            $notes      = trim($_POST['notes'] ?? '');

            if (!$name || !$srcId || $amount <= 0) { echo json_encode(['ok'=>false,'msg'=>'نام، منبع و مبلغ الزامی هستند']); exit; }

            $dateG = $dateJ ? jalaliToGregorianSafe($dateJ) : date('Y-m-d');
            $fyId  = getActiveFy($pdo);

            $pdo->beginTransaction();

            // درج تنخواه
            $pdo->prepare(
                "INSERT INTO fin_petty_cash (name,holder_user_id,account_id,source_type,source_id,allocated_amount,balance,notes,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([$name,$holderId?:null,$accId?:null,$srcType,$srcId,$amount,$amount,$notes,$userId]);
            $pcId = (int)$pdo->lastInsertId();

            // سند حسابداری تخصیص
            $docNum  = nextDoc($pdo);
            $docDesc = 'تخصیص تنخواه — ' . $name;
            $pdo->prepare(
                "INSERT INTO fin_docs (doc_number,doc_date,type,description,fiscal_year_id,ref_id,ref_type,created_by,created_at)
                 VALUES (?,?,'petty_cash_allocate',?,?,?,?,?,NOW())"
            )->execute([$docNum,$dateG,$docDesc,$fyId,$pcId,'petty_cash',$userId]);
            $docId = (int)$pdo->lastInsertId();

            // بدهکار — حساب تنخواه
            if ($accId) {
                $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,row_order) VALUES (?,?,?,?,?,1)")
                    ->execute([$docId,$accId,$amount,0,'بدهکار — تنخواه تخصیص‌یافته']);
            }
            // بستانکار — بانک یا صندوق
            $srcAccId = null;
            if ($srcType === 'bank') {
                $srcAccId = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE code='121' AND is_deleted=0 LIMIT 1")->fetchColumn();
                if (!$srcAccId) $srcAccId = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE LOWER(name) LIKE '%بانک%' AND is_deleted=0 LIMIT 1")->fetchColumn();
                $pdo->prepare("UPDATE fin_bank_accounts SET balance=balance-? WHERE id=?")->execute([$amount,$srcId]);
            } else {
                $srcAccId = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE code='101' AND is_deleted=0 LIMIT 1")->fetchColumn();
                if (!$srcAccId) $srcAccId = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE LOWER(name) LIKE '%صندوق%' AND is_deleted=0 LIMIT 1")->fetchColumn();
                $pdo->prepare("UPDATE fin_cashdesks SET balance=balance-? WHERE id=?")->execute([$amount,$srcId]);
            }
            if ($srcAccId) {
                $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,row_order) VALUES (?,?,?,?,?,2)")
                    ->execute([$docId,$srcAccId,0,$amount,'بستانکار — منبع تأمین تنخواه']);
            }

            // ثبت آیتم
            $pdo->prepare(
                "INSERT INTO fin_petty_cash_items (petty_cash_id,item_type,item_date,description,amount,fin_doc_id,created_by)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$pcId,'allocate',$dateG,'تخصیص اولیه تنخواه',$amount,$docId,$userId]);

            $pdo->commit();
            echo json_encode(['ok'=>true,'msg'=>'تنخواه ایجاد و سند حسابداری ثبت شد.','id'=>$pcId]);
            exit;
        }

        // ── لیست آیتم‌های یک تنخواه ──
        if ($action === 'get_items') {
            $pcId = (int)($_GET['petty_cash_id'] ?? 0);
            $rows = $pdo->prepare(
                "SELECT pci.*, ca.name exp_acc_name, u.full_name creator_name
                 FROM fin_petty_cash_items pci
                 LEFT JOIN fin_chart_of_accounts ca ON ca.id=pci.expense_acc_id
                 LEFT JOIN users u ON u.id=pci.created_by
                 WHERE pci.petty_cash_id=? AND pci.is_deleted=0
                 ORDER BY pci.item_date DESC, pci.id DESC"
            );
            $rows->execute([$pcId]);
            echo json_encode(['ok'=>true,'rows'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── ثبت هزینه از تنخواه ──
        if ($action === 'add_expense') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $pcId    = (int)($_POST['petty_cash_id'] ?? 0);
            $expAccId = (int)($_POST['expense_acc_id'] ?? 0);
            $amount  = (int)str_replace([',',' '], '', faToEn($_POST['amount'] ?? '0'));
            $desc    = trim($_POST['description'] ?? '');
            $dateJ   = trim(faToEn($_POST['item_date'] ?? ''));

            if (!$pcId || $amount <= 0 || !$desc) { echo json_encode(['ok'=>false,'msg'=>'اطلاعات ناقص']); exit; }

            // بررسی موجودی
            $pc = $pdo->query("SELECT * FROM fin_petty_cash WHERE id=$pcId AND is_active=1")->fetch(PDO::FETCH_ASSOC);
            if (!$pc) { echo json_encode(['ok'=>false,'msg'=>'تنخواه یافت نشد']); exit; }
            if ($pc['balance'] < $amount) { echo json_encode(['ok'=>false,'msg'=>'موجودی تنخواه کافی نیست. مانده: '.number_format($pc['balance'])]); exit; }

            $dateG = $dateJ ? jalaliToGregorianSafe($dateJ) : date('Y-m-d');
            $fyId  = getActiveFy($pdo);

            $pdo->beginTransaction();

            // سند هزینه
            $docNum  = nextDoc($pdo);
            $docDesc = 'هزینه از تنخواه «' . $pc['name'] . '» — ' . $desc;
            $pdo->prepare(
                "INSERT INTO fin_docs (doc_number,doc_date,type,description,fiscal_year_id,ref_id,ref_type,created_by,created_at)
                 VALUES (?,?,'petty_cash_expense',?,?,?,?,?,NOW())"
            )->execute([$docNum,$dateG,$docDesc,$fyId,$pcId,'petty_cash',$userId]);
            $docId = (int)$pdo->lastInsertId();

            // بدهکار — حساب هزینه
            if ($expAccId) {
                $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,row_order) VALUES (?,?,?,?,?,1)")
                    ->execute([$docId,$expAccId,$amount,0,'بدهکار — هزینه: '.$desc]);
            }
            // بستانکار — حساب تنخواه
            if ($pc['account_id']) {
                $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,row_order) VALUES (?,?,?,?,?,2)")
                    ->execute([$docId,$pc['account_id'],0,$amount,'بستانکار — تنخواه']);
            }

            $pdo->prepare("UPDATE fin_petty_cash SET balance=balance-?,updated_at=NOW() WHERE id=?")->execute([$amount,$pcId]);
            $pdo->prepare(
                "INSERT INTO fin_petty_cash_items (petty_cash_id,item_type,item_date,description,amount,expense_acc_id,fin_doc_id,created_by)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([$pcId,'expense',$dateG,$desc,$amount,$expAccId?:null,$docId,$userId]);

            $pdo->commit();
            echo json_encode(['ok'=>true,'msg'=>'هزینه ثبت شد. مانده جدید: '.number_format($pc['balance']-$amount)]);
            exit;
        }

        // ── شارژ مجدد تنخواه ──
        if ($action === 'replenish') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $pcId   = (int)($_POST['petty_cash_id'] ?? 0);
            $amount = (int)str_replace([',',' '], '', faToEn($_POST['amount'] ?? '0'));
            $dateJ  = trim(faToEn($_POST['item_date'] ?? ''));
            $desc   = trim($_POST['description'] ?? 'شارژ مجدد تنخواه');

            if (!$pcId || $amount <= 0) { echo json_encode(['ok'=>false,'msg'=>'اطلاعات ناقص']); exit; }

            $pc    = $pdo->query("SELECT * FROM fin_petty_cash WHERE id=$pcId AND is_active=1")->fetch(PDO::FETCH_ASSOC);
            if (!$pc) { echo json_encode(['ok'=>false,'msg'=>'تنخواه یافت نشد']); exit; }

            $dateG = $dateJ ? jalaliToGregorianSafe($dateJ) : date('Y-m-d');
            $fyId  = getActiveFy($pdo);

            $pdo->beginTransaction();

            $docNum  = nextDoc($pdo);
            $pdo->prepare(
                "INSERT INTO fin_docs (doc_number,doc_date,type,description,fiscal_year_id,ref_id,ref_type,created_by,created_at)
                 VALUES (?,?,'petty_cash_replenish',?,?,?,?,?,NOW())"
            )->execute([$docNum,$dateG,'شارژ مجدد تنخواه — '.$pc['name'],$fyId,$pcId,'petty_cash',$userId]);
            $docId = (int)$pdo->lastInsertId();

            if ($pc['account_id']) {
                $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,row_order) VALUES (?,?,?,?,?,1)")
                    ->execute([$docId,$pc['account_id'],$amount,0,'بدهکار — شارژ تنخواه']);
            }
            // بستانکار منبع
            $srcType = $pc['source_type'];
            $srcId   = $pc['source_id'];
            $srcAccId = null;
            if ($srcType === 'bank') {
                $srcAccId = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE code='121' AND is_deleted=0 LIMIT 1")->fetchColumn();
                $pdo->prepare("UPDATE fin_bank_accounts SET balance=balance-? WHERE id=?")->execute([$amount,$srcId]);
            } else {
                $srcAccId = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE code='101' AND is_deleted=0 LIMIT 1")->fetchColumn();
                $pdo->prepare("UPDATE fin_cashdesks SET balance=balance-? WHERE id=?")->execute([$amount,$srcId]);
            }
            if ($srcAccId) {
                $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,row_order) VALUES (?,?,?,?,?,2)")
                    ->execute([$docId,$srcAccId,0,$amount,'بستانکار — منبع شارژ']);
            }

            $pdo->prepare("UPDATE fin_petty_cash SET balance=balance+?,allocated_amount=allocated_amount+?,updated_at=NOW() WHERE id=?")->execute([$amount,$amount,$pcId]);
            $pdo->prepare(
                "INSERT INTO fin_petty_cash_items (petty_cash_id,item_type,item_date,description,amount,fin_doc_id,created_by)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$pcId,'replenish',$dateG,$desc,$amount,$docId,$userId]);

            $pdo->commit();
            echo json_encode(['ok'=>true,'msg'=>'شارژ مجدد انجام شد. مانده جدید: '.number_format($pc['balance']+$amount)]);
            exit;
        }

        // ── تسویه و بستن تنخواه ──
        if ($action === 'settle') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $pcId = (int)($_POST['petty_cash_id'] ?? 0);
            $dateJ = trim(faToEn($_POST['item_date'] ?? ''));

            $pc = $pdo->query("SELECT * FROM fin_petty_cash WHERE id=$pcId AND is_active=1")->fetch(PDO::FETCH_ASSOC);
            if (!$pc) { echo json_encode(['ok'=>false,'msg'=>'تنخواه یافت نشد']); exit; }
            $remBal = (int)$pc['balance'];
            if ($remBal <= 0) { echo json_encode(['ok'=>false,'msg'=>'مانده صفر است — تسویه‌ای لازم نیست']); exit; }

            $dateG = $dateJ ? jalaliToGregorianSafe($dateJ) : date('Y-m-d');
            $fyId  = getActiveFy($pdo);

            $pdo->beginTransaction();

            $docNum = nextDoc($pdo);
            $pdo->prepare(
                "INSERT INTO fin_docs (doc_number,doc_date,type,description,fiscal_year_id,ref_id,ref_type,created_by,created_at)
                 VALUES (?,?,'petty_cash_settle',?,?,?,?,?,NOW())"
            )->execute([$docNum,$dateG,'تسویه تنخواه — '.$pc['name'],$fyId,$pcId,'petty_cash',$userId]);
            $docId = (int)$pdo->lastInsertId();

            // بدهکار — منبع (بانک یا صندوق برگشت می‌گیرد)
            $srcType = $pc['source_type'];
            $srcId   = $pc['source_id'];
            $srcAccId = null;
            if ($srcType === 'bank') {
                $srcAccId = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE code='121' AND is_deleted=0 LIMIT 1")->fetchColumn();
                $pdo->prepare("UPDATE fin_bank_accounts SET balance=balance+? WHERE id=?")->execute([$remBal,$srcId]);
            } else {
                $srcAccId = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE code='101' AND is_deleted=0 LIMIT 1")->fetchColumn();
                $pdo->prepare("UPDATE fin_cashdesks SET balance=balance+? WHERE id=?")->execute([$remBal,$srcId]);
            }
            if ($srcAccId) {
                $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,row_order) VALUES (?,?,?,?,?,1)")
                    ->execute([$docId,$srcAccId,$remBal,0,'بدهکار — برگشت مانده به منبع']);
            }
            if ($pc['account_id']) {
                $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,row_order) VALUES (?,?,?,?,?,2)")
                    ->execute([$docId,$pc['account_id'],0,$remBal,'بستانکار — بستن تنخواه']);
            }

            $pdo->prepare("UPDATE fin_petty_cash SET balance=0,is_active=0,updated_at=NOW() WHERE id=?")->execute([$pcId]);
            $pdo->prepare(
                "INSERT INTO fin_petty_cash_items (petty_cash_id,item_type,item_date,description,amount,fin_doc_id,created_by)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$pcId,'settle',$dateG,'تسویه و بستن تنخواه',$remBal,$docId,$userId]);

            $pdo->commit();
            echo json_encode(['ok'=>true,'msg'=>'تنخواه تسویه و بسته شد.']);
            exit;
        }

        // ── لیست سرفصل‌های حسابداری (برای انتخاب حساب تنخواه) ──
        if ($action === 'get_accounts') {
            $rows = $pdo->query(
                "SELECT id, code, name, type FROM fin_chart_of_accounts
                 WHERE is_deleted=0 ORDER BY code LIMIT 300"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'rows'=>$rows]);
            exit;
        }

        // ── لیست بانک‌ها و صندوق‌ها ──
        if ($action === 'get_sources') {
            $banks = $pdo->query("SELECT id, bank_name AS name, 'bank' AS type, balance FROM fin_bank_accounts WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $desks = $pdo->query("SELECT id, name, 'cashdesk' AS type, balance FROM fin_cashdesks WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'banks'=>$banks,'cashdesks'=>$desks]);
            exit;
        }

        // ── کاربران برای انتخاب دارنده ──
        if ($action === 'get_users') {
            $rows = $pdo->query("SELECT id, full_name, username FROM users WHERE is_deleted=0 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'rows'=>$rows]);
            exit;
        }

        echo json_encode(['ok'=>false,'msg'=>'درخواست نامعتبر']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'خطای سرور: '.$e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════
// HTML
// ═══════════════════════════════════════════════════════════════
$basePath = '../../';
$pageTitle = 'تنخواه‌گردان';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<link rel="stylesheet" href="<?= $basePath ?>public_html/assets/css/fin_module.css">
<style>
.pc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem;margin-bottom:1.5rem}
.pc-card{background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;padding:1.2rem;position:relative;transition:.2s}
.pc-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08)}
.pc-card .pc-name{font-weight:700;font-size:1rem;color:#1e293b;margin-bottom:.4rem}
.pc-card .pc-acc{font-size:.78rem;color:#7c3aed;margin-bottom:.6rem}
.pc-card .pc-bal{font-size:1.3rem;font-weight:700;color:#0f766e;margin-bottom:.5rem}
.pc-card .pc-meta{font-size:.78rem;color:#94a3b8}
.pc-card .pc-actions{display:flex;gap:.4rem;margin-top:.75rem;flex-wrap:wrap}
.pc-badge{display:inline-block;padding:.2em .6em;border-radius:.4rem;font-size:.72rem;font-weight:600;background:#ede9fe;color:#5b21b6}
.fin-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:9999}
.fin-modal-box{background:#fff;border-radius:1rem;padding:1.5rem;width:min(96vw,580px);max-height:90vh;overflow-y:auto}
.fin-modal-box h3{margin:0 0 1rem;font-size:1.05rem;color:#1e293b}
.form-group{margin-bottom:.9rem}
.form-group label{display:block;font-size:.82rem;color:#374151;margin-bottom:.25rem;font-weight:500}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:.48rem .7rem;border:1px solid #cbd5e1;border-radius:.45rem;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.88rem;box-sizing:border-box}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.btn-row{display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem}
table.t{width:100%;border-collapse:collapse;font-size:.83rem}
table.t th{background:#f8fafc;padding:.55rem .7rem;text-align:right;color:#64748b;border-bottom:2px solid #e2e8f0;font-weight:600}
table.t td{padding:.55rem .7rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
table.t tr:hover td{background:#fafafa}
.type-badge{display:inline-block;padding:.15em .5em;border-radius:.35rem;font-size:.72rem;font-weight:600}
.tb-allocate{background:#dbeafe;color:#1e40af}
.tb-expense{background:#fee2e2;color:#991b1b}
.tb-replenish{background:#dcfce7;color:#166534}
.tb-settle{background:#f1f5f9;color:#475569}
</style>

<div class="fin-layout" style="direction:rtl">
  <?php require_once __DIR__ . '/../../templates/sidebar.php'; ?>
  <div class="fin-main">
    <div class="fin-topbar">
      <h1 class="fin-page-title">💵 تنخواه‌گردان</h1>
      <button class="fin-btn fin-btn-primary" onclick="openCreate()">➕ تنخواه جدید</button>
    </div>

    <div id="pcGrid" class="pc-grid">
      <div style="text-align:center;padding:3rem;color:#94a3b8;grid-column:1/-1">در حال بارگذاری...</div>
    </div>

  </div>
</div>

<!-- مودال: ایجاد تنخواه -->
<div class="fin-modal-overlay" id="createModal">
  <div class="fin-modal-box">
    <h3>➕ تنخواه جدید</h3>
    <div class="form-group">
      <label>نام تنخواه</label>
      <input type="text" id="pcName" placeholder="مثال: تنخواه اداری واحد فروش">
    </div>
    <div class="g2">
      <div class="form-group">
        <label>دارنده تنخواه</label>
        <select id="pcHolder" class="fin-select" style="width:100%">
          <option value="">انتخاب کارمند</option>
        </select>
      </div>
      <div class="form-group">
        <label>سرفصل حسابداری تنخواه</label>
        <select id="pcAccount" class="fin-select" style="width:100%">
          <option value="">— انتخاب سرفصل —</option>
        </select>
      </div>
    </div>
    <div class="g2">
      <div class="form-group">
        <label>نوع منبع</label>
        <select id="pcSrcType" onchange="updateSources()" class="fin-select" style="width:100%">
          <option value="bank">بانک</option>
          <option value="cashdesk">صندوق</option>
        </select>
      </div>
      <div class="form-group">
        <label>منبع (بانک / صندوق)</label>
        <select id="pcSrcId" class="fin-select" style="width:100%"></select>
      </div>
    </div>
    <div class="g2">
      <div class="form-group">
        <label>مبلغ اولیه (ریال)</label>
        <input type="text" id="pcAmount" value="0">
      </div>
      <div class="form-group">
        <label>تاریخ تخصیص</label>
        <input type="text" id="pcDate" placeholder="۱۴۰۴/۰۳/۱۵">
      </div>
    </div>
    <div class="form-group">
      <label>یادداشت</label>
      <textarea id="pcNotes" rows="2"></textarea>
    </div>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('createModal')">لغو</button>
      <button class="fin-btn fin-btn-primary" onclick="doCreate()">💾 ایجاد تنخواه</button>
    </div>
  </div>
</div>

<!-- مودال: ثبت هزینه -->
<div class="fin-modal-overlay" id="expenseModal">
  <div class="fin-modal-box" style="width:min(96vw,460px)">
    <h3 id="expModalTitle">💸 ثبت هزینه</h3>
    <input type="hidden" id="expPcId">
    <div class="form-group">
      <label>حساب هزینه</label>
      <select id="expAccId" class="fin-select" style="width:100%">
        <option value="">— انتخاب سرفصل هزینه —</option>
      </select>
    </div>
    <div class="g2">
      <div class="form-group">
        <label>مبلغ (ریال)</label>
        <input type="text" id="expAmount" value="0">
      </div>
      <div class="form-group">
        <label>تاریخ</label>
        <input type="text" id="expDate" placeholder="۱۴۰۴/۰۳/۱۵">
      </div>
    </div>
    <div class="form-group">
      <label>شرح هزینه</label>
      <input type="text" id="expDesc" placeholder="توضیح هزینه...">
    </div>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('expenseModal')">لغو</button>
      <button class="fin-btn fin-btn-primary" onclick="doExpense()" style="background:#dc2626;border-color:#dc2626">💸 ثبت هزینه</button>
    </div>
  </div>
</div>

<!-- مودال: شارژ مجدد -->
<div class="fin-modal-overlay" id="replenishModal">
  <div class="fin-modal-box" style="width:min(96vw,420px)">
    <h3 id="repModalTitle">🔄 شارژ مجدد تنخواه</h3>
    <input type="hidden" id="repPcId">
    <div class="g2">
      <div class="form-group">
        <label>مبلغ شارژ (ریال)</label>
        <input type="text" id="repAmount" value="0">
      </div>
      <div class="form-group">
        <label>تاریخ</label>
        <input type="text" id="repDate" placeholder="۱۴۰۴/۰۳/۱۵">
      </div>
    </div>
    <div class="form-group">
      <label>شرح</label>
      <input type="text" id="repDesc" value="شارژ مجدد تنخواه">
    </div>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('replenishModal')">لغو</button>
      <button class="fin-btn fin-btn-primary" onclick="doReplenish()" style="background:#0f766e;border-color:#0f766e">🔄 شارژ</button>
    </div>
  </div>
</div>

<!-- مودال: ریز تراکنش‌ها -->
<div class="fin-modal-overlay" id="itemsModal">
  <div class="fin-modal-box" style="width:min(96vw,800px)">
    <h3 id="itemsModalTitle">📋 ریز تراکنش‌های تنخواه</h3>
    <div style="overflow-x:auto">
      <table class="t">
        <thead><tr><th>نوع</th><th>تاریخ</th><th>شرح</th><th>حساب هزینه</th><th>مبلغ</th></tr></thead>
        <tbody id="itemsRows"></tbody>
      </table>
    </div>
    <div class="btn-row" style="margin-top:.75rem">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('itemsModal')">بستن</button>
    </div>
  </div>
</div>

<!-- مودال: تسویه -->
<div class="fin-modal-overlay" id="settleModal">
  <div class="fin-modal-box" style="width:min(96vw,400px)">
    <h3>✅ تسویه تنخواه</h3>
    <input type="hidden" id="settPcId">
    <p style="color:#64748b;font-size:.88rem" id="settText"></p>
    <div class="form-group">
      <label>تاریخ تسویه</label>
      <input type="text" id="settDate" placeholder="۱۴۰۴/۰۳/۱۵">
    </div>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('settleModal')">لغو</button>
      <button class="fin-btn fin-btn-primary" onclick="doSettle()">✅ تسویه</button>
    </div>
  </div>
</div>

<script>
var csrf = '<?= htmlspecialchars(csrf_token()) ?>';
var allSources = {banks:[], cashdesks:[]};
var allAccounts = [];

function fmt(n){ return parseInt(n||0).toLocaleString('fa-IR'); }
function showToast(msg,type='success'){
    var t=document.createElement('div');
    t.textContent=msg;
    t.style.cssText='position:fixed;bottom:1.5rem;right:1.5rem;padding:.75rem 1.25rem;border-radius:.5rem;z-index:99999;font-size:.9rem;color:#fff;background:'+(type==='success'?'#10b981':'#ef4444');
    document.body.appendChild(t);setTimeout(()=>t.remove(),3500);
}
function openModal(id){document.getElementById(id).style.display='flex';}
function closeModal(id){document.getElementById(id).style.display='none';}

var typeNames={allocate:'تخصیص',expense:'هزینه',replenish:'شارژ',settle:'تسویه'};

// ── بارگذاری لیست تنخواه‌ها ──
function loadList(){
    fetch('fin_petty_cash.php?action=get_list',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(function(d){
        if(!d.ok)return;
        if(!d.rows.length){
            document.getElementById('pcGrid').innerHTML='<div style="text-align:center;padding:3rem;color:#94a3b8;grid-column:1/-1">هنوز تنخواهی تعریف نشده</div>';
            return;
        }
        var h='';
        d.rows.forEach(function(pc){
            var pct=pc.allocated_amount>0?Math.round(pc.balance/pc.allocated_amount*100):0;
            var balColor=pct<20?'#dc2626':pct<50?'#f59e0b':'#0f766e';
            h+='<div class="pc-card">';
            h+='<div class="pc-name">'+esc(pc.name)+'</div>';
            if(pc.acc_code) h+='<div class="pc-acc">🔢 سرفصل: '+esc(pc.acc_code)+' — '+esc(pc.acc_name||'')+'</div>';
            h+='<div class="pc-bal" style="color:'+balColor+'">'+fmt(pc.balance)+' ریال</div>';
            h+='<div style="background:#f1f5f9;border-radius:.5rem;height:6px;margin-bottom:.6rem;overflow:hidden"><div style="background:'+balColor+';height:100%;width:'+Math.min(pct,100)+'%;transition:.3s"></div></div>';
            h+='<div class="pc-meta">';
            if(pc.holder_name) h+='👤 '+esc(pc.holder_name)+' &nbsp;';
            h+='| تخصیص: '+fmt(pc.allocated_amount)+' | هزینه: '+fmt(pc.spent)+'</div>';
            h+='<div class="pc-actions">';
            h+='<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="openItems('+pc.id+',\''+esc(pc.name)+'\')">📋 ریز</button>';
            h+='<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="openExpense('+pc.id+',\''+esc(pc.name)+'\')">💸 هزینه</button>';
            h+='<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="openReplenish('+pc.id+',\''+esc(pc.name)+'\')">🔄 شارژ</button>';
            h+='<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="openSettle('+pc.id+',\''+esc(pc.name)+'\','+pc.balance+')">✅ تسویه</button>';
            h+='</div>';
            h+='</div>';
        });
        document.getElementById('pcGrid').innerHTML=h;
    });
}

// ── منابع و سرفصل‌ها ──
function loadSources(){
    return fetch('fin_petty_cash.php?action=get_sources',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{if(d.ok)allSources=d;});
}
function loadAccounts(){
    return fetch('fin_petty_cash.php?action=get_accounts',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{if(d.ok)allAccounts=d.rows;});
}
function loadUsers(){
    return fetch('fin_petty_cash.php?action=get_users',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.ok)return;
        var sel=document.getElementById('pcHolder');
        d.rows.forEach(function(u){
            var o=document.createElement('option');
            o.value=u.id;o.textContent=u.full_name||u.username;
            sel.appendChild(o);
        });
    });
}

function fillAccountSelect(selId, filterFn){
    var sel=document.getElementById(selId);
    while(sel.options.length>1)sel.remove(1);
    allAccounts.filter(filterFn||function(){return true;}).forEach(function(a){
        var o=document.createElement('option');
        o.value=a.id;o.textContent=a.code+' — '+a.name;
        sel.appendChild(o);
    });
}
function updateSources(){
    var type=document.getElementById('pcSrcType').value;
    var sel=document.getElementById('pcSrcId');
    while(sel.options.length>0)sel.remove(0);
    var list=type==='cashdesk'?allSources.cashdesks:allSources.banks;
    list.forEach(function(s){
        var o=document.createElement('option');
        o.value=s.id;o.textContent=s.name+' ('+fmt(s.balance)+' ریال)';
        sel.appendChild(o);
    });
}

// ── ایجاد تنخواه ──
function openCreate(){
    fillAccountSelect('pcAccount');
    fillAccountSelect('expAccId', function(a){return a.type==='expense'||parseInt(a.code)>=700;});
    updateSources();
    openModal('createModal');
}
function doCreate(){
    var fd=new FormData();
    fd.append('action','create');fd.append('csrf_token',csrf);
    fd.append('name',document.getElementById('pcName').value);
    fd.append('holder_user_id',document.getElementById('pcHolder').value);
    fd.append('account_id',document.getElementById('pcAccount').value);
    fd.append('source_type',document.getElementById('pcSrcType').value);
    fd.append('source_id',document.getElementById('pcSrcId').value);
    fd.append('amount',document.getElementById('pcAmount').value.replace(/,/g,''));
    fd.append('item_date',document.getElementById('pcDate').value);
    fd.append('notes',document.getElementById('pcNotes').value);
    fetch('fin_petty_cash.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){closeModal('createModal');loadList();showToast(d.msg);}
        else showToast(d.msg,'error');
    });
}

// ── هزینه ──
function openExpense(id,name){
    fillAccountSelect('expAccId');
    document.getElementById('expPcId').value=id;
    document.getElementById('expModalTitle').textContent='💸 هزینه از: '+name;
    document.getElementById('expAmount').value='0';
    document.getElementById('expDesc').value='';
    document.getElementById('expDate').value='';
    openModal('expenseModal');
}
function doExpense(){
    var fd=new FormData();
    fd.append('action','add_expense');fd.append('csrf_token',csrf);
    fd.append('petty_cash_id',document.getElementById('expPcId').value);
    fd.append('expense_acc_id',document.getElementById('expAccId').value);
    fd.append('amount',document.getElementById('expAmount').value.replace(/,/g,''));
    fd.append('description',document.getElementById('expDesc').value);
    fd.append('item_date',document.getElementById('expDate').value);
    fetch('fin_petty_cash.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){closeModal('expenseModal');loadList();showToast(d.msg);}
        else showToast(d.msg,'error');
    });
}

// ── شارژ مجدد ──
function openReplenish(id,name){
    document.getElementById('repPcId').value=id;
    document.getElementById('repModalTitle').textContent='🔄 شارژ: '+name;
    document.getElementById('repAmount').value='0';
    document.getElementById('repDate').value='';
    openModal('replenishModal');
}
function doReplenish(){
    var fd=new FormData();
    fd.append('action','replenish');fd.append('csrf_token',csrf);
    fd.append('petty_cash_id',document.getElementById('repPcId').value);
    fd.append('amount',document.getElementById('repAmount').value.replace(/,/g,''));
    fd.append('item_date',document.getElementById('repDate').value);
    fd.append('description',document.getElementById('repDesc').value);
    fetch('fin_petty_cash.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){closeModal('replenishModal');loadList();showToast(d.msg);}
        else showToast(d.msg,'error');
    });
}

// ── ریز تراکنش‌ها ──
function openItems(id,name){
    document.getElementById('itemsModalTitle').textContent='📋 ریز: '+name;
    document.getElementById('itemsRows').innerHTML='<tr><td colspan="5" style="text-align:center;padding:1rem;color:#94a3b8">در حال بارگذاری...</td></tr>';
    openModal('itemsModal');
    fetch('fin_petty_cash.php?action=get_items&petty_cash_id='+id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(function(d){
        if(!d.ok)return;
        var h='';
        d.rows.forEach(function(row){
            var amt=parseInt(row.amount||0);
            var isExp=row.item_type==='expense';
            h+='<tr>';
            h+='<td><span class="type-badge tb-'+row.item_type+'">'+(typeNames[row.item_type]||row.item_type)+'</span></td>';
            h+='<td>'+esc(row.item_date||'')+'</td>';
            h+='<td>'+esc(row.description||'')+'</td>';
            h+='<td style="color:#64748b">'+esc(row.exp_acc_name||'—')+'</td>';
            h+='<td style="color:'+(isExp?'#dc2626':'#0f766e')+';font-weight:600">'+(isExp?'−':'+')+fmt(amt)+'</td>';
            h+='</tr>';
        });
        if(!d.rows.length)h='<tr><td colspan="5" style="text-align:center;padding:1.5rem;color:#94a3b8">تراکنشی ثبت نشده</td></tr>';
        document.getElementById('itemsRows').innerHTML=h;
    });
}

// ── تسویه ──
function openSettle(id,name,bal){
    document.getElementById('settPcId').value=id;
    document.getElementById('settText').textContent='مانده «'+name+'» به مبلغ '+fmt(bal)+' ریال به منبع اصلی بازگشت داده می‌شود و تنخواه بسته خواهد شد.';
    document.getElementById('settDate').value='';
    openModal('settleModal');
}
function doSettle(){
    var fd=new FormData();
    fd.append('action','settle');fd.append('csrf_token',csrf);
    fd.append('petty_cash_id',document.getElementById('settPcId').value);
    fd.append('item_date',document.getElementById('settDate').value);
    fetch('fin_petty_cash.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){closeModal('settleModal');loadList();showToast(d.msg);}
        else showToast(d.msg,'error');
    });
}

function esc(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

document.addEventListener('DOMContentLoaded',function(){
    Promise.all([loadSources(),loadAccounts(),loadUsers()]).then(loadList);
});
</script>

<?php
ob_end_flush();
require_once __DIR__ . '/../../templates/footer.php';
?>
