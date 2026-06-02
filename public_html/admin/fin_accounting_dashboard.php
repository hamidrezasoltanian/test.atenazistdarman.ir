<?php
/*
 * فایل: public_html/admin/fin_accounting_dashboard.php
 * توضیحات: داشبورد حسابداری — نمای کلی فروش، وصولی، مطالبات، چک‌ها، بانک‌ها و صندوق‌ها
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>'نشست منقضی']); exit; }
    header('Location: ../login.php'); exit;
}

$userId  = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// ── بررسی دسترسی ──────────────────────────────────────────────
$isAdmin  = false;
$hasAccess = false;

$stmtChk = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$rawRole, $rawRole]);
$roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($roleData) {
    if ($roleData['name'] === 'admin' || $roleData['is_system'] == 1) {
        $isAdmin = true; $hasAccess = true;
    } else {
        $perms = json_decode($roleData['permissions'], true) ?? [];
        if (in_array('fin_accounting', $perms) || in_array('all', $perms)) $hasAccess = true;
    }
}
// دسترسی گسترده برای نقش‌های مالی
$roleName = strtolower(trim($roleData['name'] ?? $rawRole));
if (in_array($roleName, ['admin','management','manager','finance_manager','accountant','finance_expert'])) {
    $hasAccess = true; $isAdmin = true;
}

if (!$hasAccess) {
    if ($isAjax) { echo json_encode(['status'=>'error','message'=>'دسترسی غیرمجاز']); exit; }
    die('<div style="text-align:center;padding:50px;font-family:Tahoma;color:red;font-weight:bold;font-size:1.1rem;line-height:1.6;">⛔ دسترسی غیرمجاز.</div>');
}

// ── ساخت جداول (در صورت نبودن) ────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS fin_bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(30),
    sheba_number VARCHAR(30),
    account_owner VARCHAR(200),
    branch_name VARCHAR(100),
    balance DECIMAL(20,0) DEFAULT 0,
    is_active TINYINT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS fin_cashdesks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(200),
    balance DECIMAL(20,0) DEFAULT 0,
    user_id INT DEFAULT NULL,
    is_active TINYINT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS fin_cheques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('received','issued') NOT NULL,
    cheque_number VARCHAR(50) NOT NULL,
    bank_name VARCHAR(100),
    amount DECIMAL(20,0) NOT NULL,
    person_id INT DEFAULT NULL,
    issue_date DATE,
    due_date DATE NOT NULL,
    status ENUM('pending','cleared','bounced','transferred') DEFAULT 'pending',
    doc_id INT DEFAULT NULL,
    bank_account_id INT DEFAULT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS fin_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30),
    type ENUM('sell','buy') DEFAULT 'sell',
    customer_id INT DEFAULT NULL,
    person_id INT DEFAULT NULL,
    user_id INT DEFAULT NULL,
    invoice_date DATE,
    total_amount DECIMAL(20,0) DEFAULT 0,
    paid_amount DECIMAL(20,0) DEFAULT 0,
    status ENUM('draft','issued','paid','cancelled') DEFAULT 'draft',
    fiscal_year_id INT DEFAULT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS fin_persons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20),
    name VARCHAR(200) NOT NULL,
    type ENUM('customer','supplier','both') DEFAULT 'customer',
    mobile VARCHAR(15),
    phone VARCHAR(15),
    address TEXT,
    balance DECIMAL(20,0) DEFAULT 0,
    is_active TINYINT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");

// ── پردازش درخواست‌های AJAX ───────────────────────────────────
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ─ ذخیره حساب بانکی ─
    if ($action === 'save_bank') {
        $id       = (int)($_POST['id'] ?? 0);
        $bankName = trim($_POST['bank_name'] ?? '');
        $accNum   = trim($_POST['account_number'] ?? '');
        $sheba    = trim($_POST['sheba_number'] ?? '');
        $owner    = trim($_POST['account_owner'] ?? '');
        $branch   = trim($_POST['branch_name'] ?? '');
        $balance  = (int)str_replace(',', '', $_POST['balance'] ?? '0');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($bankName)) { echo json_encode(['status'=>'error','message'=>'نام بانک الزامی است']); exit; }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE fin_bank_accounts SET bank_name=?, account_number=?, sheba_number=?, account_owner=?, branch_name=?, is_active=? WHERE id=?");
            $stmt->execute([$bankName, $accNum, $sheba, $owner, $branch, $isActive, $id]);
            echo json_encode(['status'=>'success','message'=>'اطلاعات بانک ویرایش شد']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO fin_bank_accounts (bank_name,account_number,sheba_number,account_owner,branch_name,balance,is_active) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$bankName, $accNum, $sheba, $owner, $branch, $balance, $isActive]);
            echo json_encode(['status'=>'success','message'=>'حساب بانکی جدید اضافه شد','id'=>$pdo->lastInsertId()]);
        }
        exit;
    }

    // ─ ذخیره صندوق ─
    if ($action === 'save_cashdesk') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $balance = (int)str_replace(',', '', $_POST['balance'] ?? '0');
        $uid     = (int)($_POST['user_id'] ?? 0) ?: null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name)) { echo json_encode(['status'=>'error','message'=>'نام صندوق الزامی است']); exit; }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE fin_cashdesks SET name=?, description=?, user_id=?, is_active=? WHERE id=?");
            $stmt->execute([$name, $desc, $uid, $isActive, $id]);
            echo json_encode(['status'=>'success','message'=>'صندوق ویرایش شد']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO fin_cashdesks (name,description,balance,user_id,is_active) VALUES (?,?,?,?,?)");
            $stmt->execute([$name, $desc, $balance, $uid, $isActive]);
            echo json_encode(['status'=>'success','message'=>'صندوق جدید اضافه شد','id'=>$pdo->lastInsertId()]);
        }
        exit;
    }

    // ─ حذف بانک ─
    if ($action === 'delete_bank') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) { echo json_encode(['status'=>'error','message'=>'شناسه نامعتبر']); exit; }
        $pdo->prepare("DELETE FROM fin_bank_accounts WHERE id=?")->execute([$id]);
        echo json_encode(['status'=>'success','message'=>'حساب بانکی حذف شد']);
        exit;
    }

    // ─ حذف صندوق ─
    if ($action === 'delete_cashdesk') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) { echo json_encode(['status'=>'error','message'=>'شناسه نامعتبر']); exit; }
        $pdo->prepare("DELETE FROM fin_cashdesks WHERE id=?")->execute([$id]);
        echo json_encode(['status'=>'success','message'=>'صندوق حذف شد']);
        exit;
    }

    // ─ دریافت اطلاعات بانک برای ویرایش ─
    if ($action === 'get_bank') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare("SELECT * FROM fin_bank_accounts WHERE id=?");
        $row->execute([$id]);
        $data = $row->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'success','data'=>$data]);
        exit;
    }

    // ─ دریافت اطلاعات صندوق برای ویرایش ─
    if ($action === 'get_cashdesk') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare("SELECT * FROM fin_cashdesks WHERE id=?");
        $row->execute([$id]);
        $data = $row->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'success','data'=>$data]);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'عملیات نامعتبر']); exit;
}

// ── آمار مالی سال جاری ────────────────────────────────────────
$fiscalYear   = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? $fiscalYear['id'] : 0;

// بررسی وجود جدول fin_invoices
$hasInvoices = false;
try {
    $chk = $pdo->query("SHOW TABLES LIKE 'fin_invoices'"); $hasInvoices = $chk->rowCount() > 0;
} catch (Throwable $e) {}

$totalSales       = 0;
$totalCollections = 0;
$totalOutstanding = 0;
$invoiceCount     = 0;
$recentInvoices   = [];

if ($hasInvoices) {
    $fyWhere = $fiscalYearId ? "AND fiscal_year_id = $fiscalYearId" : "";

    $row = $pdo->query("SELECT
        COALESCE(SUM(total_amount),0) as total_sales,
        COALESCE(SUM(paid_amount),0) as total_collections,
        COALESCE(SUM(total_amount - paid_amount),0) as outstanding,
        COUNT(*) as cnt
        FROM fin_invoices WHERE type='sell' AND is_deleted=0 $fyWhere")->fetch(PDO::FETCH_ASSOC);

    $totalSales       = (int)($row['total_sales'] ?? 0);
    $totalCollections = (int)($row['total_collections'] ?? 0);
    $totalOutstanding = (int)($row['outstanding'] ?? 0);
    $invoiceCount     = (int)($row['cnt'] ?? 0);

    // ۱۰ فاکتور اخیر
    $stmtRecent = $pdo->query("
        SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount, i.status,
               COALESCE(c.company_name, p.name, 'نامشخص') as person_name
        FROM fin_invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN fin_persons p ON i.person_id = p.id
        WHERE i.type='sell' AND i.is_deleted=0 $fyWhere
        ORDER BY i.created_at DESC LIMIT 10
    ");
    $recentInvoices = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
}

// چک‌های در انتظار
$pendingChequeAmount = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM fin_cheques WHERE status='pending' AND is_deleted=0")->fetchColumn();
$pendingChequeCount  = (int)$pdo->query("SELECT COUNT(*) FROM fin_cheques WHERE status='pending' AND is_deleted=0")->fetchColumn();

// چک‌های سررسید ۷ روز آینده
$chequeDueSoon = $pdo->query("
    SELECT c.*, COALESCE(p.name,'نامشخص') as person_name
    FROM fin_cheques c LEFT JOIN fin_persons p ON c.person_id = p.id
    WHERE c.status='pending' AND c.is_deleted=0
      AND c.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY c.due_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// حساب‌های بانکی
$bankAccounts = $pdo->query("SELECT * FROM fin_bank_accounts WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$totalBankBalance = array_sum(array_column($bankAccounts, 'balance'));

// صندوق‌ها
$cashdesks = $pdo->query("SELECT cd.*, u.first_name, u.last_name FROM fin_cashdesks cd LEFT JOIN users u ON cd.user_id=u.id WHERE cd.is_active=1 ORDER BY cd.id ASC")->fetchAll(PDO::FETCH_ASSOC);
$totalCashBalance = array_sum(array_column($cashdesks, 'balance'));

// کاربران برای فرم صندوق
$allUsers = $pdo->query("SELECT id, first_name, last_name FROM users WHERE is_active=1 ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

// ── نام بانک‌های معمول ایران ──────────────────────────────────
$iranianBanks = ['ملی ایران','ملت','صادرات','تجارت','رفاه کارگران','مسکن','کشاورزی','توسعه صادرات','سپه','آینده','پارسیان','پاسارگاد','سامان','سینا','اقتصاد نوین','کارآفرین','خاورمیانه','دی','قرض‌الحسنه مهر ایران','شهر','گردشگری','ایران زمین','تعاون','انصار','حکمت','نور'];

$pageTitle = 'داشبورد حسابداری';
$basePath  = '../../';
$extraCss  = '
<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">
<style>
    .accdash-wrap { max-width: 1280px; margin: 0 auto; padding: 0 16px 40px; }
    .accdash-grid-main { display: grid; grid-template-columns: 1fr 340px; gap: 22px; }
    .accdash-col-right { display: flex; flex-direction: column; gap: 22px; }
    .inv-status-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:700; }
    .inv-status-draft    { background:#f1f5f9; color:#475569; }
    .inv-status-issued   { background:#eff6ff; color:#2563eb; }
    .inv-status-paid     { background:#ecfdf5; color:#059669; }
    .inv-status-cancelled{ background:#fff1f2; color:#e11d48; }
    .cheque-due-row { background:#fffbeb !important; }
    .cheque-overdue-row { background:#fff1f2 !important; }
    .bank-list-wrap, .cash-list-wrap { display:flex; flex-direction:column; gap:8px; }
    .fin-drawer { position:fixed; top:0; left:-500px; width:460px; height:100vh; background:#fff;
        box-shadow:-8px 0 30px rgba(0,0,0,0.12); z-index:1200; transition:left 0.35s cubic-bezier(.4,0,.2,1);
        overflow-y:auto; display:flex; flex-direction:column; }
    .fin-drawer.open { left:0; }
    .fin-drawer-header { padding:20px 24px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:12px; background:#f8fafc; flex-shrink:0; }
    .fin-drawer-title { font-size:1.05rem; font-weight:900; color:#1e293b; flex:1; }
    .fin-drawer-body { padding:24px; flex:1; }
    .fin-form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
    .fin-form-row.single { grid-template-columns:1fr; }
    .fin-form-group { display:flex; flex-direction:column; gap:5px; }
    .fin-form-group label { font-size:0.8rem; font-weight:700; color:#475569; }
    .fin-form-group input, .fin-form-group select, .fin-form-group textarea {
        padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px;
        font-family:Vazirmatn,Tahoma,sans-serif; font-size:0.875rem; color:#1e293b;
        background:#fff; transition:border-color 0.2s; }
    .fin-form-group input:focus, .fin-form-group select:focus, .fin-form-group textarea:focus {
        outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
    .fin-drawer-footer { padding:16px 24px; border-top:1px solid #e5e7eb; background:#f8fafc; display:flex; gap:10px; }
    .overlay { position:fixed; inset:0; background:rgba(0,0,0,0.35); z-index:1100; display:none; }
    .overlay.active { display:block; }
    .alert-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(80px);
        background:#1e293b; color:#fff; padding:12px 24px; border-radius:12px; font-size:0.9rem;
        font-weight:600; z-index:2000; transition:transform 0.3s; white-space:nowrap; }
    .alert-toast.show { transform:translateX(-50%) translateY(0); }
    .alert-toast.success { background:#059669; }
    .alert-toast.error   { background:#e11d48; }
    @media(max-width:1024px){ .accdash-grid-main{ grid-template-columns:1fr; } }
    @media(max-width:768px){ .fin-form-row{ grid-template-columns:1fr; } .fin-drawer{ width:100%; left:-100%; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
<div class="content-wrapper">
<div class="accdash-wrap">

    <!-- سرتیتر صفحه -->
    <div class="page-header" style="margin-bottom:22px;">
        <div>
            <span class="page-title">📊 داشبورد حسابداری</span>
            <div style="font-size:0.82rem;color:#64748b;margin-top:4px;">
                سال مالی فعال:
                <strong style="color:#2563eb;"><?php echo $fiscalYear ? htmlspecialchars($fiscalYear['title']) : 'تعریف نشده'; ?></strong>
            </div>
        </div>
        <div class="fin-quick-actions" style="margin-bottom:0;">
            <button class="fin-quick-btn blue" onclick="window.location.href='fin_invoices.php?new=1'">
                ➕ فاکتور جدید
            </button>
            <button class="fin-quick-btn green" onclick="window.location.href='fin_receipts.php?new=1'">
                💰 دریافت وجه
            </button>
            <button class="fin-quick-btn amber" onclick="window.location.href='fin_cheques.php?new=1'">
                📝 چک جدید
            </button>
        </div>
    </div>

    <!-- کارت‌های آماری -->
    <div class="fin-stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));margin-bottom:22px;">
        <div class="fin-stat-card blue">
            <div class="fin-stat-icon">📦</div>
            <div>
                <div class="fin-stat-label">کل فروش سال جاری</div>
                <div class="fin-stat-value"><?php echo number_format($totalSales); ?> <span class="fin-stat-unit">تومان</span></div>
                <div style="font-size:0.75rem;opacity:0.8;margin-top:3px;"><?php echo number_format($invoiceCount); ?> فاکتور</div>
            </div>
        </div>
        <div class="fin-stat-card green">
            <div class="fin-stat-icon">✅</div>
            <div>
                <div class="fin-stat-label">کل وصولی‌ها</div>
                <div class="fin-stat-value"><?php echo number_format($totalCollections); ?> <span class="fin-stat-unit">تومان</span></div>
                <?php if($totalSales > 0): ?>
                <div style="font-size:0.75rem;opacity:0.8;margin-top:3px;"><?php echo round($totalCollections/$totalSales*100); ?>٪ وصول شده</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="fin-stat-card rose">
            <div class="fin-stat-icon">⏳</div>
            <div>
                <div class="fin-stat-label">مانده مطالبات</div>
                <div class="fin-stat-value"><?php echo number_format($totalOutstanding); ?> <span class="fin-stat-unit">تومان</span></div>
                <div style="font-size:0.75rem;opacity:0.8;margin-top:3px;">وصول نشده</div>
            </div>
        </div>
        <div class="fin-stat-card amber">
            <div class="fin-stat-icon">📝</div>
            <div>
                <div class="fin-stat-label">چک‌های در جریان</div>
                <div class="fin-stat-value"><?php echo number_format($pendingChequeAmount); ?> <span class="fin-stat-unit">تومان</span></div>
                <div style="font-size:0.75rem;opacity:0.8;margin-top:3px;"><?php echo $pendingChequeCount; ?> چک در انتظار</div>
            </div>
        </div>
    </div>

    <!-- هشدار چک‌های سررسید نزدیک -->
    <?php if (!empty($chequeDueSoon)): ?>
    <div class="fin-alert warning" style="margin-bottom:22px;">
        <span style="font-size:1.3rem;">⚠️</span>
        <div>
            <strong>هشدار:</strong> <?php echo count($chequeDueSoon); ?> چک در ۷ روز آینده سررسید دارند —
            <a href="fin_cheques.php" style="color:#78350f;font-weight:700;text-decoration:underline;">مشاهده همه</a>
            <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach($chequeDueSoon as $dc): ?>
                <span style="background:#fef3c7;border:1px solid #fde68a;padding:4px 10px;border-radius:8px;font-size:0.78rem;">
                    شماره <?php echo htmlspecialchars($dc['cheque_number']); ?> —
                    <?php echo number_format($dc['amount']); ?> ت —
                    سررسید: <?php echo jdate('Y/m/d', strtotime($dc['due_date'])); ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- گرید اصلی: جدول + ستون کناری -->
    <div class="accdash-grid-main">

        <!-- ستون چپ: فاکتورهای اخیر -->
        <div style="display:flex;flex-direction:column;gap:22px;">

            <div class="fin-panel">
                <div class="fin-panel-title">
                    <span class="title-icon" style="background:#eff6ff;">🧾</span>
                    آخرین فاکتورهای فروش
                    <a href="fin_invoices.php" style="margin-right:auto;font-size:0.8rem;color:#2563eb;text-decoration:none;font-weight:600;">مشاهده همه ←</a>
                </div>

                <?php if (empty($recentInvoices)): ?>
                <div class="fin-empty">
                    <div class="empty-icon">🧾</div>
                    <p>هنوز فاکتوری ثبت نشده است</p>
                </div>
                <?php else: ?>
                <div class="fin-table-wrap">
                    <table class="fin-table">
                        <thead>
                            <tr>
                                <th>شماره فاکتور</th>
                                <th>مشتری</th>
                                <th>تاریخ</th>
                                <th>مبلغ کل</th>
                                <th>وصول شده</th>
                                <th>مانده</th>
                                <th>وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recentInvoices as $inv): ?>
                            <?php
                                $remaining = $inv['total_amount'] - $inv['paid_amount'];
                                $statusMap = ['draft'=>['label'=>'پیش‌نویس','cls'=>'inv-status-draft'],'issued'=>['label'=>'صادر شده','cls'=>'inv-status-issued'],'paid'=>['label'=>'پرداخت شده','cls'=>'inv-status-paid'],'cancelled'=>['label'=>'لغو شده','cls'=>'inv-status-cancelled']];
                                $st = $statusMap[$inv['status']] ?? ['label'=>$inv['status'],'cls'=>'inv-status-draft'];
                            ?>
                            <tr>
                                <td><strong style="color:#2563eb;"><?php echo htmlspecialchars($inv['invoice_number'] ?: '#'.$inv['id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($inv['person_name']); ?></td>
                                <td style="direction:ltr;text-align:right;"><?php echo $inv['invoice_date'] ? jdate('Y/m/d', strtotime($inv['invoice_date'])) : '—'; ?></td>
                                <td><?php echo number_format($inv['total_amount']); ?></td>
                                <td style="color:#059669;"><?php echo number_format($inv['paid_amount']); ?></td>
                                <td style="color:<?php echo $remaining > 0 ? '#e11d48' : '#059669'; ?>;"><?php echo number_format($remaining); ?></td>
                                <td><span class="inv-status-badge <?php echo $st['cls']; ?>"><?php echo $st['label']; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- جدول چک‌های سررسید نزدیک (جزئیات) -->
            <?php if(!empty($chequeDueSoon)): ?>
            <div class="fin-panel">
                <div class="fin-panel-title">
                    <span class="title-icon" style="background:#fffbeb;">📝</span>
                    چک‌های سررسید در ۷ روز آینده
                    <a href="fin_cheques.php" style="margin-right:auto;font-size:0.8rem;color:#d97706;text-decoration:none;font-weight:600;">مدیریت چک‌ها ←</a>
                </div>
                <table class="fin-table">
                    <thead>
                        <tr>
                            <th>نوع</th>
                            <th>شماره چک</th>
                            <th>بانک</th>
                            <th>مبلغ</th>
                            <th>طرف حساب</th>
                            <th>سررسید</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($chequeDueSoon as $ch): ?>
                        <?php $isPast = strtotime($ch['due_date']) < strtotime('today'); ?>
                        <tr class="<?php echo $isPast ? 'cheque-overdue-row' : 'cheque-due-row'; ?>">
                            <td>
                                <?php if($ch['type']==='received'): ?>
                                    <span style="background:#ecfdf5;color:#059669;padding:2px 8px;border-radius:6px;font-size:0.75rem;font-weight:700;">دریافتی</span>
                                <?php else: ?>
                                    <span style="background:#fff1f2;color:#e11d48;padding:2px 8px;border-radius:6px;font-size:0.75rem;font-weight:700;">پرداختی</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($ch['cheque_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($ch['bank_name'] ?? '—'); ?></td>
                            <td><?php echo number_format($ch['amount']); ?> <small style="color:#94a3b8;">ت</small></td>
                            <td><?php echo htmlspecialchars($ch['person_name']); ?></td>
                            <td style="direction:ltr;text-align:right;color:<?php echo $isPast?'#e11d48':'#d97706'; ?>;font-weight:700;">
                                <?php echo jdate('Y/m/d', strtotime($ch['due_date'])); ?>
                                <?php if($isPast): ?><span style="font-size:0.7rem;"> (سررسید گذشته)</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div><!-- end col-left -->

        <!-- ستون راست: بانک‌ها و صندوق‌ها -->
        <div class="accdash-col-right">

            <!-- حساب‌های بانکی -->
            <div class="fin-panel">
                <div class="fin-panel-title">
                    <span class="title-icon" style="background:#eff6ff;">🏦</span>
                    حساب‌های بانکی
                    <button onclick="openDrawer('bank')" class="fin-btn fin-btn-primary fin-btn-sm" style="margin-right:auto;">+ افزودن</button>
                </div>

                <div style="background:#eff6ff;border-radius:10px;padding:10px 14px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:0.82rem;color:#1e40af;font-weight:700;">جمع موجودی بانکی</span>
                    <span style="font-size:1.1rem;font-weight:900;color:#1d4ed8;"><?php echo number_format($totalBankBalance); ?> <small>ت</small></span>
                </div>

                <?php if(empty($bankAccounts)): ?>
                <div class="fin-empty" style="padding:30px 0;">
                    <div class="empty-icon" style="font-size:2rem;">🏦</div>
                    <p>هنوز حساب بانکی تعریف نشده</p>
                </div>
                <?php else: ?>
                <div class="bank-list-wrap">
                    <?php foreach($bankAccounts as $ba): ?>
                    <div class="fin-account-item">
                        <div class="fin-account-avatar bank">🏦</div>
                        <div class="fin-account-info">
                            <div class="fin-account-name"><?php echo htmlspecialchars($ba['bank_name']); ?></div>
                            <div class="fin-account-sub">
                                <?php if($ba['account_number']): ?>شماره: <?php echo htmlspecialchars($ba['account_number']); ?><?php endif; ?>
                                <?php if($ba['branch_name']): ?> — <?php echo htmlspecialchars($ba['branch_name']); ?><?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align:left;">
                            <div class="fin-account-balance"><?php echo number_format($ba['balance']); ?> <span class="unit">ت</span></div>
                            <div style="display:flex;gap:4px;margin-top:4px;justify-content:flex-end;">
                                <button class="fin-btn fin-btn-outline fin-btn-sm" onclick="editBank(<?php echo $ba['id']; ?>)" title="ویرایش">✏️</button>
                                <button class="fin-btn fin-btn-danger fin-btn-sm" onclick="deleteBank(<?php echo $ba['id']; ?>,'<?php echo addslashes(htmlspecialchars($ba['bank_name'])); ?>')" title="حذف">🗑️</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- صندوق‌های نقدی -->
            <div class="fin-panel">
                <div class="fin-panel-title">
                    <span class="title-icon" style="background:#ecfdf5;">💵</span>
                    صندوق‌های نقدی
                    <button onclick="openDrawer('cash')" class="fin-btn fin-btn-success fin-btn-sm" style="margin-right:auto;">+ افزودن</button>
                </div>

                <div style="background:#ecfdf5;border-radius:10px;padding:10px 14px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:0.82rem;color:#065f46;font-weight:700;">جمع موجودی صندوق‌ها</span>
                    <span style="font-size:1.1rem;font-weight:900;color:#059669;"><?php echo number_format($totalCashBalance); ?> <small>ت</small></span>
                </div>

                <?php if(empty($cashdesks)): ?>
                <div class="fin-empty" style="padding:30px 0;">
                    <div class="empty-icon" style="font-size:2rem;">💵</div>
                    <p>هنوز صندوق نقدی تعریف نشده</p>
                </div>
                <?php else: ?>
                <div class="cash-list-wrap">
                    <?php foreach($cashdesks as $cd): ?>
                    <div class="fin-account-item">
                        <div class="fin-account-avatar cash">💵</div>
                        <div class="fin-account-info">
                            <div class="fin-account-name"><?php echo htmlspecialchars($cd['name']); ?></div>
                            <div class="fin-account-sub">
                                <?php if($cd['first_name']): ?><?php echo htmlspecialchars($cd['first_name'].' '.$cd['last_name']); ?><?php elseif($cd['description']): ?><?php echo htmlspecialchars($cd['description']); ?><?php else: ?>صندوق عمومی<?php endif; ?>
                            </div>
                        </div>
                        <div style="text-align:left;">
                            <div class="fin-account-balance"><?php echo number_format($cd['balance']); ?> <span class="unit">ت</span></div>
                            <div style="display:flex;gap:4px;margin-top:4px;justify-content:flex-end;">
                                <button class="fin-btn fin-btn-outline fin-btn-sm" onclick="editCash(<?php echo $cd['id']; ?>)" title="ویرایش">✏️</button>
                                <button class="fin-btn fin-btn-danger fin-btn-sm" onclick="deleteCash(<?php echo $cd['id']; ?>,'<?php echo addslashes(htmlspecialchars($cd['name'])); ?>')" title="حذف">🗑️</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- خلاصه نقدینگی -->
            <div class="fin-panel" style="background:linear-gradient(135deg,#1e293b 0%,#334155 100%);border:none;color:#fff;">
                <div style="font-size:0.9rem;font-weight:700;opacity:0.8;margin-bottom:14px;">💼 خلاصه نقدینگی</div>
                <div style="display:flex;justify-content:space-between;margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid rgba(255,255,255,0.1);">
                    <span style="opacity:0.75;">موجودی بانکی</span>
                    <span style="font-weight:900;"><?php echo number_format($totalBankBalance); ?> <small>ت</small></span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid rgba(255,255,255,0.1);">
                    <span style="opacity:0.75;">موجودی صندوق</span>
                    <span style="font-weight:900;"><?php echo number_format($totalCashBalance); ?> <small>ت</small></span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="font-weight:700;">جمع نقدینگی</span>
                    <span style="font-size:1.2rem;font-weight:900;color:#34d399;"><?php echo number_format($totalBankBalance + $totalCashBalance); ?> <small style="font-size:0.7rem;">ت</small></span>
                </div>
            </div>

        </div><!-- end col-right -->
    </div><!-- end grid-main -->

</div><!-- end accdash-wrap -->
</div><!-- end content-wrapper -->
</main>

<!-- Overlay -->
<div class="overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<!-- Drawer: حساب بانکی -->
<div class="fin-drawer" id="bankDrawer">
    <div class="fin-drawer-header">
        <span style="font-size:1.4rem;">🏦</span>
        <div class="fin-drawer-title" id="bankDrawerTitle">حساب بانکی جدید</div>
        <button onclick="closeDrawer()" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:#64748b;">✕</button>
    </div>
    <div class="fin-drawer-body">
        <form id="bankForm">
            <input type="hidden" name="action" value="save_bank">
            <input type="hidden" name="id" id="bankId" value="0">
            <div class="fin-form-row">
                <div class="fin-form-group">
                    <label>نام بانک *</label>
                    <select name="bank_name" id="bankName" required>
                        <option value="">انتخاب کنید...</option>
                        <?php foreach($iranianBanks as $b): ?>
                        <option value="بانک <?php echo $b; ?>">بانک <?php echo $b; ?></option>
                        <?php endforeach; ?>
                        <option value="سایر">سایر</option>
                    </select>
                </div>
                <div class="fin-form-group">
                    <label>شعبه</label>
                    <input type="text" name="branch_name" id="bankBranch" placeholder="نام شعبه">
                </div>
            </div>
            <div class="fin-form-row">
                <div class="fin-form-group">
                    <label>شماره حساب</label>
                    <input type="text" name="account_number" id="bankAccNum" placeholder="مثال: ۱۲۳۴۵۶۷۸" dir="ltr">
                </div>
                <div class="fin-form-group">
                    <label>شماره شبا</label>
                    <input type="text" name="sheba_number" id="bankSheba" placeholder="IR..." dir="ltr">
                </div>
            </div>
            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label>صاحب حساب</label>
                    <input type="text" name="account_owner" id="bankOwner" placeholder="نام صاحب حساب">
                </div>
            </div>
            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label>موجودی اولیه (تومان)</label>
                    <input type="text" name="balance" id="bankBalance" placeholder="0" dir="ltr" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                </div>
            </div>
            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label style="flex-direction:row;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_active" id="bankActive" checked>
                        <span>حساب فعال است</span>
                    </label>
                </div>
            </div>
        </form>
    </div>
    <div class="fin-drawer-footer">
        <button class="fin-btn fin-btn-primary" onclick="saveBank()" id="bankSaveBtn">💾 ذخیره</button>
        <button class="fin-btn fin-btn-outline" onclick="closeDrawer()">انصراف</button>
    </div>
</div>

<!-- Drawer: صندوق نقدی -->
<div class="fin-drawer" id="cashDrawer">
    <div class="fin-drawer-header">
        <span style="font-size:1.4rem;">💵</span>
        <div class="fin-drawer-title" id="cashDrawerTitle">صندوق نقدی جدید</div>
        <button onclick="closeDrawer()" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:#64748b;">✕</button>
    </div>
    <div class="fin-drawer-body">
        <form id="cashForm">
            <input type="hidden" name="action" value="save_cashdesk">
            <input type="hidden" name="id" id="cashId" value="0">
            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label>نام صندوق *</label>
                    <input type="text" name="name" id="cashName" placeholder="مثال: صندوق مرکزی، صندوق تهران" required>
                </div>
            </div>
            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label>توضیحات</label>
                    <input type="text" name="description" id="cashDesc" placeholder="توضیح مختصر">
                </div>
            </div>
            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label>مسئول صندوق</label>
                    <select name="user_id" id="cashUser">
                        <option value="">انتخاب کنید (اختیاری)</option>
                        <?php foreach($allUsers as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label>موجودی اولیه (تومان)</label>
                    <input type="text" name="balance" id="cashBalance" placeholder="0" dir="ltr" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                </div>
            </div>
            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label style="flex-direction:row;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_active" id="cashActive" checked>
                        <span>صندوق فعال است</span>
                    </label>
                </div>
            </div>
        </form>
    </div>
    <div class="fin-drawer-footer">
        <button class="fin-btn fin-btn-success" onclick="saveCash()" id="cashSaveBtn">💾 ذخیره</button>
        <button class="fin-btn fin-btn-outline" onclick="closeDrawer()">انصراف</button>
    </div>
</div>

<!-- Toast -->
<div class="alert-toast" id="toast"></div>

<script>
const PAGE_URL = window.location.pathname;

// ── Toast ──────────────────────────────────────────────────────
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'alert-toast ' + type + ' show';
    setTimeout(() => { t.className = 'alert-toast'; }, 3200);
}

// ── Drawer ─────────────────────────────────────────────────────
let activeDrawer = null;
function openDrawer(type) {
    closeDrawer();
    activeDrawer = type;
    const id = type === 'bank' ? 'bankDrawer' : 'cashDrawer';
    document.getElementById(id).classList.add('open');
    document.getElementById('drawerOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
    if (type === 'bank') {
        document.getElementById('bankDrawerTitle').textContent = 'حساب بانکی جدید';
        document.getElementById('bankId').value = '0';
        document.getElementById('bankForm').reset();
        document.getElementById('bankActive').checked = true;
    } else {
        document.getElementById('cashDrawerTitle').textContent = 'صندوق نقدی جدید';
        document.getElementById('cashId').value = '0';
        document.getElementById('cashForm').reset();
        document.getElementById('cashActive').checked = true;
    }
}
function closeDrawer() {
    ['bankDrawer','cashDrawer'].forEach(id => document.getElementById(id).classList.remove('open'));
    document.getElementById('drawerOverlay').classList.remove('active');
    document.body.style.overflow = '';
    activeDrawer = null;
}

// ── AJAX Helper ───────────────────────────────────────────────
function ajaxPost(data, cb) {
    const fd = new FormData();
    for (let [k,v] of Object.entries(data)) fd.append(k,v);
    fetch(PAGE_URL, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
        .then(r => r.json()).then(cb)
        .catch(() => showToast('خطا در ارتباط با سرور', 'error'));
}

// ── بانک ──────────────────────────────────────────────────────
function editBank(id) {
    ajaxPost({action:'get_bank',id}, res => {
        if (!res.data) return showToast('خطا در دریافت اطلاعات','error');
        const d = res.data;
        document.getElementById('bankDrawerTitle').textContent = 'ویرایش حساب بانکی';
        document.getElementById('bankId').value = d.id;
        document.getElementById('bankName').value = d.bank_name;
        document.getElementById('bankBranch').value = d.branch_name || '';
        document.getElementById('bankAccNum').value = d.account_number || '';
        document.getElementById('bankSheba').value = d.sheba_number || '';
        document.getElementById('bankOwner').value = d.account_owner || '';
        document.getElementById('bankBalance').value = d.balance || '0';
        document.getElementById('bankActive').checked = d.is_active == 1;
        document.getElementById('bankDrawer').classList.add('open');
        document.getElementById('drawerOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
        activeDrawer = 'bank';
    });
}
function saveBank() {
    const form = document.getElementById('bankForm');
    const btn  = document.getElementById('bankSaveBtn');
    if (!form.bank_name.value) return showToast('نام بانک الزامی است','error');
    btn.disabled = true; btn.textContent = 'در حال ذخیره...';
    const fd = new FormData(form);
    fetch(PAGE_URL, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
        .then(r => r.json()).then(res => {
            btn.disabled = false; btn.textContent = '💾 ذخیره';
            if (res.status === 'success') {
                showToast(res.message,'success');
                closeDrawer();
                setTimeout(() => location.reload(), 900);
            } else {
                showToast(res.message || 'خطا','error');
            }
        }).catch(() => { btn.disabled = false; showToast('خطا در ارتباط','error'); });
}
function deleteBank(id, name) {
    if (!confirm('آیا از حذف «' + name + '» مطمئن هستید؟')) return;
    ajaxPost({action:'delete_bank',id}, res => {
        if (res.status === 'success') { showToast(res.message,'success'); setTimeout(() => location.reload(), 800); }
        else showToast(res.message,'error');
    });
}

// ── صندوق ─────────────────────────────────────────────────────
function editCash(id) {
    ajaxPost({action:'get_cashdesk',id}, res => {
        if (!res.data) return showToast('خطا در دریافت اطلاعات','error');
        const d = res.data;
        document.getElementById('cashDrawerTitle').textContent = 'ویرایش صندوق';
        document.getElementById('cashId').value = d.id;
        document.getElementById('cashName').value = d.name;
        document.getElementById('cashDesc').value = d.description || '';
        document.getElementById('cashUser').value = d.user_id || '';
        document.getElementById('cashBalance').value = d.balance || '0';
        document.getElementById('cashActive').checked = d.is_active == 1;
        document.getElementById('cashDrawer').classList.add('open');
        document.getElementById('drawerOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
        activeDrawer = 'cash';
    });
}
function saveCash() {
    const form = document.getElementById('cashForm');
    const btn  = document.getElementById('cashSaveBtn');
    if (!form.name.value) return showToast('نام صندوق الزامی است','error');
    btn.disabled = true; btn.textContent = 'در حال ذخیره...';
    const fd = new FormData(form);
    fetch(PAGE_URL, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
        .then(r => r.json()).then(res => {
            btn.disabled = false; btn.textContent = '💾 ذخیره';
            if (res.status === 'success') {
                showToast(res.message,'success');
                closeDrawer();
                setTimeout(() => location.reload(), 900);
            } else {
                showToast(res.message || 'خطا','error');
            }
        }).catch(() => { btn.disabled = false; showToast('خطا در ارتباط','error'); });
}
function deleteCash(id, name) {
    if (!confirm('آیا از حذف «' + name + '» مطمئن هستید؟')) return;
    ajaxPost({action:'delete_cashdesk',id}, res => {
        if (res.status === 'success') { showToast(res.message,'success'); setTimeout(() => location.reload(), 800); }
        else showToast(res.message,'error');
    });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
