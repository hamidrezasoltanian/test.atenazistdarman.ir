<?php
/*
 * فایل: public_html/admin/fin_cheques.php
 * توضیحات: مدیریت چک‌ها — دریافتی و پرداختی با تقویم سررسید
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
$isAdmin   = false;
$hasAccess = false;

$stmtChk = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$rawRole, $rawRole]);
$roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($roleData) {
    if ($roleData['name'] === 'admin' || $roleData['is_system'] == 1) {
        $isAdmin = true; $hasAccess = true;
    } else {
        $perms = json_decode($roleData['permissions'], true) ?? [];
        if (in_array('fin_cheques', $perms) || in_array('fin_accounting', $perms) || in_array('all', $perms)) $hasAccess = true;
    }
}
$roleName = strtolower(trim($roleData['name'] ?? $rawRole));
if (in_array($roleName, ['admin','management','manager','finance_manager','accountant','finance_expert'])) {
    $hasAccess = true; $isAdmin = true;
}

if (!$hasAccess) {
    if ($isAjax) { echo json_encode(['status'=>'error','message'=>'دسترسی غیرمجاز']); exit; }
    die('<div style="text-align:center;padding:50px;font-family:Tahoma;color:red;font-weight:bold;line-height:1.6;">⛔ دسترسی غیرمجاز.</div>');
}

// ── ایجاد جداول لازم ──────────────────────────────────────────
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

// ── پردازش AJAX ────────────────────────────────────────────────
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ─ لیست چک‌ها با فیلتر ─
    if ($action === 'list') {
        $type      = in_array($_POST['type'] ?? '', ['received','issued']) ? $_POST['type'] : '';
        $status    = in_array($_POST['status'] ?? '', ['pending','cleared','bounced','transferred','']) ? ($_POST['status'] ?? '') : '';
        $dateFrom  = trim($_POST['date_from'] ?? '');
        $dateTo    = trim($_POST['date_to'] ?? '');
        $search    = trim($_POST['search'] ?? '');
        $page      = max(1, (int)($_POST['page'] ?? 1));
        $perPage   = 20;
        $offset    = ($page - 1) * $perPage;

        $where  = ['c.is_deleted = 0'];
        $params = [];

        if ($type)     { $where[] = 'c.type = ?';           $params[] = $type; }
        if ($status)   { $where[] = 'c.status = ?';         $params[] = $status; }
        if ($dateFrom) { $where[] = 'c.due_date >= ?';      $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = 'c.due_date <= ?';      $params[] = $dateTo; }
        if ($search)   { $where[] = '(c.cheque_number LIKE ? OR c.bank_name LIKE ? OR p.name LIKE ?)';
                         $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

        $whereSql = implode(' AND ', $where);

        // شمارش کل
        $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM fin_cheques c LEFT JOIN fin_persons p ON c.person_id=p.id WHERE $whereSql");
        $stmtCnt->execute($params);
        $total = (int)$stmtCnt->fetchColumn();

        // دریافت داده
        $paramsPage = array_merge($params, [$perPage, $offset]);
        $stmt = $pdo->prepare("
            SELECT c.*, p.name as person_name, ba.bank_name as linked_bank
            FROM fin_cheques c
            LEFT JOIN fin_persons p ON c.person_id = p.id
            LEFT JOIN fin_bank_accounts ba ON c.bank_account_id = ba.id
            WHERE $whereSql
            ORDER BY c.due_date ASC, c.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($paramsPage);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // آمار
        $statsStmt = $pdo->prepare("SELECT status, SUM(amount) as total_amount, COUNT(*) as cnt FROM fin_cheques c WHERE c.is_deleted=0 " . ($type ? "AND c.type='$type'" : "") . " GROUP BY status");
        $statsStmt->execute();
        $statsRaw = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
        $stats = [];
        foreach ($statsRaw as $sr) { $stats[$sr['status']] = ['amount' => $sr['total_amount'], 'count' => $sr['cnt']]; }

        // سررسید این هفته
        $weekStmt = $pdo->prepare("SELECT COUNT(*), SUM(amount) FROM fin_cheques c WHERE c.is_deleted=0 AND c.status='pending' AND c.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) " . ($type ? "AND c.type=?" : ""));
        $weekStmt->execute($type ? [$type] : []);
        $weekRow = $weekStmt->fetch(PDO::FETCH_NUM);

        echo json_encode([
            'status' => 'success',
            'rows'   => $rows,
            'total'  => $total,
            'pages'  => ceil($total / $perPage),
            'stats'  => $stats,
            'week'   => ['count' => (int)$weekRow[0], 'amount' => (int)$weekRow[1]],
        ]);
        exit;
    }

    // ─ ذخیره / ویرایش چک ─
    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $type        = in_array($_POST['type'] ?? '', ['received','issued']) ? $_POST['type'] : 'received';
        $chequeNum   = trim($_POST['cheque_number'] ?? '');
        $bankName    = trim($_POST['bank_name'] ?? '');
        $amount      = (int)str_replace([',', ' '], '', $_POST['amount'] ?? '0');
        $personId    = (int)($_POST['person_id'] ?? 0) ?: null;
        $issueDate   = trim($_POST['issue_date'] ?? '') ?: null;
        $dueDate     = trim($_POST['due_date'] ?? '');
        $bankAccId   = (int)($_POST['bank_account_id'] ?? 0) ?: null;
        $desc        = trim($_POST['description'] ?? '');

        if (empty($chequeNum)) { echo json_encode(['status'=>'error','message'=>'شماره چک الزامی است']); exit; }
        if ($amount <= 0)      { echo json_encode(['status'=>'error','message'=>'مبلغ چک باید بزرگتر از صفر باشد']); exit; }
        if (empty($dueDate))   { echo json_encode(['status'=>'error','message'=>'تاریخ سررسید الزامی است']); exit; }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE fin_cheques SET type=?, cheque_number=?, bank_name=?, amount=?, person_id=?, issue_date=?, due_date=?, bank_account_id=?, description=?, updated_at=NOW() WHERE id=? AND is_deleted=0");
            $stmt->execute([$type, $chequeNum, $bankName, $amount, $personId, $issueDate, $dueDate, $bankAccId, $desc, $id]);
            echo json_encode(['status'=>'success','message'=>'چک ویرایش شد']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO fin_cheques (type, cheque_number, bank_name, amount, person_id, issue_date, due_date, bank_account_id, description) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$type, $chequeNum, $bankName, $amount, $personId, $issueDate, $dueDate, $bankAccId, $desc]);
            echo json_encode(['status'=>'success','message'=>'چک جدید ثبت شد','id'=>$pdo->lastInsertId()]);
        }
        exit;
    }

    // ─ تغییر وضعیت ─
    if ($action === 'change_status') {
        $id        = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if (!in_array($newStatus, ['pending','cleared','bounced','transferred'])) {
            echo json_encode(['status'=>'error','message'=>'وضعیت نامعتبر']); exit;
        }
        if ($id < 1) { echo json_encode(['status'=>'error','message'=>'شناسه نامعتبر']); exit; }

        $pdo->prepare("UPDATE fin_cheques SET status=?, updated_at=NOW() WHERE id=? AND is_deleted=0")->execute([$newStatus, $id]);

        $statusLabels = ['pending'=>'در جریان','cleared'=>'وصول شده','bounced'=>'برگشتی','transferred'=>'منتقل شده'];
        echo json_encode(['status'=>'success','message'=>'وضعیت چک به «'.$statusLabels[$newStatus].'» تغییر یافت']);
        exit;
    }

    // ─ حذف چک ─
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) { echo json_encode(['status'=>'error','message'=>'شناسه نامعتبر']); exit; }
        $pdo->prepare("UPDATE fin_cheques SET is_deleted=1, updated_at=NOW() WHERE id=?")->execute([$id]);
        echo json_encode(['status'=>'success','message'=>'چک حذف شد']);
        exit;
    }

    // ─ دریافت اطلاعات چک برای ویرایش ─
    if ($action === 'get') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT c.*, p.name as person_name FROM fin_cheques c LEFT JOIN fin_persons p ON c.person_id=p.id WHERE c.id=? AND c.is_deleted=0");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'success','data'=>$row]);
        exit;
    }

    // ─ جستجوی طرف حساب ─
    if ($action === 'search_persons') {
        $q = trim($_POST['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode(['status'=>'success','rows',[]]); exit; }
        $stmt = $pdo->prepare("SELECT id, name, mobile FROM fin_persons WHERE is_active=1 AND name LIKE ? LIMIT 10");
        $stmt->execute(["%$q%"]);
        echo json_encode(['status'=>'success','rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ─ داده‌های تقویم ─
    if ($action === 'calendar_data') {
        $year  = (int)($_POST['year'] ?? date('Y'));
        $month = (int)($_POST['month'] ?? date('m'));
        // محدوده ماه میلادی
        $firstDay = "$year-$month-01";
        $lastDay  = date('Y-m-t', strtotime($firstDay));

        $stmt = $pdo->prepare("SELECT id, type, cheque_number, amount, due_date, status FROM fin_cheques WHERE is_deleted=0 AND due_date BETWEEN ? AND ? ORDER BY due_date ASC");
        $stmt->execute([$firstDay, $lastDay]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // گروه‌بندی بر اساس تاریخ
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['due_date']][] = $r;
        }
        echo json_encode(['status'=>'success','data'=>$grouped,'first'=>$firstDay,'last'=>$lastDay]);
        exit;
    }

    // ─ افزودن طرف حساب سریع ─
    if ($action === 'add_person') {
        $name = trim($_POST['name'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        if (empty($name)) { echo json_encode(['status'=>'error','message'=>'نام الزامی است']); exit; }
        $stmt = $pdo->prepare("INSERT INTO fin_persons (name, mobile) VALUES (?,?)");
        $stmt->execute([$name, $mobile]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['status'=>'success','id'=>$newId,'name'=>$name]);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'عملیات نامعتبر']); exit;
}

// ── داده‌های اولیه صفحه ──────────────────────────────────────
$bankAccounts = $pdo->query("SELECT id, bank_name FROM fin_bank_accounts WHERE is_active=1 ORDER BY bank_name")->fetchAll(PDO::FETCH_ASSOC);
$openFromGet  = (int)($_GET['new'] ?? 0); // باز کردن drawer از لینک خارجی

$pageTitle = 'مدیریت چک‌ها';
$basePath  = '../../';
$extraCss  = '
<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">
<style>
    .cheq-wrap { max-width: 1280px; margin: 0 auto; padding: 0 16px 40px; }

    /* ── کارت‌های آمار ── */
    .cheq-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px; }
    .cheq-stat  { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:18px 20px;
                  box-shadow:0 2px 8px rgba(0,0,0,0.03); display:flex; gap:14px; align-items:center; }
    .cheq-stat-ico { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.4rem; flex-shrink:0; }
    .cheq-stat-lbl { font-size:0.78rem; font-weight:700; color:#64748b; }
    .cheq-stat-val { font-size:1.2rem; font-weight:900; color:#1e293b; margin-top:2px; }
    .cheq-stat-sub { font-size:0.72rem; color:#94a3b8; margin-top:1px; }

    /* ── تب‌ها ── */
    .cheq-tabs { display:flex; gap:4px; border-bottom:2px solid #e2e8f0; margin-bottom:20px; }
    .cheq-tab  { padding:10px 22px; border:none; background:none; cursor:pointer;
                 font-family:"Vazirmatn",Tahoma,sans-serif; font-size:0.88rem; font-weight:700;
                 color:#64748b; border-bottom:3px solid transparent; margin-bottom:-2px;
                 border-radius:8px 8px 0 0; transition:all 0.2s; display:flex; align-items:center; gap:7px; }
    .cheq-tab:hover { color:#2563eb; background:#eff6ff; }
    .cheq-tab.active { color:#2563eb; border-bottom-color:#2563eb; background:#eff6ff; }
    .cheq-tab .tab-count { background:#2563eb; color:#fff; border-radius:20px; padding:1px 7px; font-size:0.72rem; }

    /* ── فیلتر ── */
    .cheq-filter { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 18px;
                   margin-bottom:20px; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
    .cheq-filter-group { display:flex; flex-direction:column; gap:4px; min-width:120px; flex:1; }
    .cheq-filter-group label { font-size:0.75rem; font-weight:700; color:#64748b; }
    .cheq-filter-group input, .cheq-filter-group select {
        padding:7px 10px; border:1.5px solid #e2e8f0; border-radius:7px;
        font-family:"Vazirmatn",Tahoma,sans-serif; font-size:0.82rem; color:#1e293b; background:#fff; }
    .cheq-filter-group input:focus, .cheq-filter-group select:focus { outline:none; border-color:#2563eb; }

    /* ── جدول چک‌ها ── */
    .cheq-row-overdue { background:#fff1f2 !important; }
    .cheq-row-duesoon { background:#fffbeb !important; }

    /* ── بج وضعیت ── */
    .cheq-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:700; }
    .cheq-badge.pending     { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
    .cheq-badge.cleared     { background:#ecfdf5; color:#065f46; border:1px solid #6ee7b7; }
    .cheq-badge.bounced     { background:#fff1f2; color:#9f1239; border:1px solid #fecdd3; }
    .cheq-badge.transferred { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }

    /* ── دکمه‌های عملیات ── */
    .cheq-action-btn { padding:4px 9px; border-radius:6px; border:none; cursor:pointer; font-size:0.72rem; font-weight:700; font-family:"Vazirmatn",Tahoma,sans-serif; transition:all 0.15s; }
    .cheq-action-btn:hover { filter:brightness(0.9); }
    .btn-clear    { background:#ecfdf5; color:#059669; }
    .btn-bounce   { background:#fff1f2; color:#e11d48; }
    .btn-transfer { background:#eff6ff; color:#2563eb; }
    .btn-edit     { background:#f1f5f9; color:#475569; }
    .btn-delete   { background:#fff1f2; color:#e11d48; }

    /* ── Drawer ── */
    .fin-drawer { position:fixed; top:0; left:-520px; width:500px; height:100vh; background:#fff;
        box-shadow:-8px 0 30px rgba(0,0,0,0.12); z-index:1200; transition:left 0.35s cubic-bezier(.4,0,.2,1);
        overflow-y:auto; display:flex; flex-direction:column; }
    .fin-drawer.open { left:0; }
    .fin-drawer-header { padding:20px 24px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:12px; background:#f8fafc; flex-shrink:0; }
    .fin-drawer-title  { font-size:1.05rem; font-weight:900; color:#1e293b; flex:1; }
    .fin-drawer-body   { padding:22px; flex:1; }
    .fin-drawer-footer { padding:16px 22px; border-top:1px solid #e5e7eb; background:#f8fafc; display:flex; gap:10px; }
    .fin-form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
    .fin-form-row.single { grid-template-columns:1fr; }
    .fin-form-group { display:flex; flex-direction:column; gap:5px; }
    .fin-form-group label { font-size:0.8rem; font-weight:700; color:#475569; }
    .fin-form-group input, .fin-form-group select, .fin-form-group textarea {
        padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px;
        font-family:"Vazirmatn",Tahoma,sans-serif; font-size:0.875rem; color:#1e293b;
        background:#fff; transition:border-color 0.2s; }
    .fin-form-group input:focus, .fin-form-group select:focus, .fin-form-group textarea:focus {
        outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.1); }

    /* ── جستجوی طرف حساب ── */
    .person-search-wrap { position:relative; }
    .person-dropdown    { position:absolute; top:100%; right:0; left:0; background:#fff; border:1.5px solid #e2e8f0;
                          border-radius:8px; box-shadow:0 6px 16px rgba(0,0,0,0.1); z-index:100; max-height:200px; overflow-y:auto; display:none; }
    .person-dropdown.active { display:block; }
    .person-item { padding:8px 12px; cursor:pointer; font-size:0.85rem; border-bottom:1px solid #f1f5f9; }
    .person-item:hover { background:#eff6ff; }
    .person-item:last-child { border-bottom:none; }

    /* ── تقویم ── */
    .cheq-calendar-wrap { display:none; }
    .cheq-calendar-wrap.active { display:block; }
    .cal-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
    .cal-nav-btn { background:none; border:1.5px solid #e2e8f0; padding:6px 14px; border-radius:8px; cursor:pointer; font-size:1rem; color:#475569; }
    .cal-nav-btn:hover { background:#f1f5f9; }
    .cal-month-label { font-size:1rem; font-weight:900; color:#1e293b; }
    .fin-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
    .fin-cal-header-day { text-align:center; font-size:0.72rem; font-weight:700; color:#64748b; padding:6px 2px; }
    .fin-cal-cell { min-height:64px; border:1px solid #e5e7eb; border-radius:6px; padding:4px;
                    background:#fff; position:relative; font-size:0.72rem; }
    .fin-cal-cell.empty { background:#f8fafc; border-color:transparent; }
    .fin-cal-cell.today { border-color:#2563eb; background:#eff6ff; }
    .fin-cal-cell .day-num { font-weight:700; color:#475569; font-size:0.78rem; margin-bottom:3px; }
    .fin-cal-cell.today .day-num { color:#2563eb; }
    .fin-cal-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin:1px; cursor:pointer; }
    .fin-cal-dot.received    { background:#059669; }
    .fin-cal-dot.issued      { background:#e11d48; }
    .fin-cal-dot.overdue     { background:#7c3aed; }
    .fin-cal-dot.cleared     { background:#94a3b8; }

    /* ── Overlay ── */
    .overlay { position:fixed; inset:0; background:rgba(0,0,0,0.35); z-index:1100; display:none; }
    .overlay.active { display:block; }

    /* ── Toast ── */
    .alert-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(80px);
        background:#1e293b; color:#fff; padding:12px 24px; border-radius:12px; font-size:0.9rem;
        font-weight:600; z-index:2000; transition:transform 0.3s; white-space:nowrap; }
    .alert-toast.show { transform:translateX(-50%) translateY(0); }
    .alert-toast.success { background:#059669; }
    .alert-toast.error   { background:#e11d48; }
    .alert-toast.warning { background:#d97706; }

    /* ── ریسپانسیو ── */
    @media(max-width:768px) {
        .fin-form-row { grid-template-columns:1fr; }
        .fin-drawer { width:100%; left:-100%; }
        .cheq-stats { grid-template-columns:1fr 1fr; }
        .fin-table { display:block; overflow-x:auto; }
    }
    @media(max-width:480px) { .cheq-stats { grid-template-columns:1fr; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
<div class="content-wrapper">
<div class="cheq-wrap">

    <!-- سرتیتر -->
    <div class="page-header" style="margin-bottom:22px;">
        <div>
            <span class="page-title">📝 مدیریت چک‌ها</span>
            <div style="font-size:0.82rem;color:#64748b;margin-top:4px;">مدیریت چک‌های دریافتی و پرداختی شرکت</div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="fin-quick-btn amber" onclick="openDrawer()">+ ثبت چک جدید</button>
            <button class="fin-quick-btn blue" id="btnCalToggle" onclick="toggleCalendar()" style="background:#6366f1;">📅 تقویم سررسید</button>
            <a href="fin_accounting_dashboard.php" class="fin-quick-btn" style="background:#64748b;color:#fff;text-decoration:none;">← داشبورد مالی</a>
        </div>
    </div>

    <!-- کارت‌های آمار -->
    <div class="cheq-stats" id="statsWrap">
        <div class="cheq-stat">
            <div class="cheq-stat-ico" style="background:#fffbeb;">⏳</div>
            <div>
                <div class="cheq-stat-lbl">در جریان وصول</div>
                <div class="cheq-stat-val" id="statPendingAmt">—</div>
                <div class="cheq-stat-sub" id="statPendingCnt">—</div>
            </div>
        </div>
        <div class="cheq-stat">
            <div class="cheq-stat-ico" style="background:#ecfdf5;">✅</div>
            <div>
                <div class="cheq-stat-lbl">وصول شده</div>
                <div class="cheq-stat-val" id="statClearedAmt">—</div>
                <div class="cheq-stat-sub" id="statClearedCnt">—</div>
            </div>
        </div>
        <div class="cheq-stat">
            <div class="cheq-stat-ico" style="background:#fff1f2;">❌</div>
            <div>
                <div class="cheq-stat-lbl">برگشتی</div>
                <div class="cheq-stat-val" id="statBouncedAmt">—</div>
                <div class="cheq-stat-sub" id="statBouncedCnt">—</div>
            </div>
        </div>
        <div class="cheq-stat">
            <div class="cheq-stat-ico" style="background:#eff6ff;">📅</div>
            <div>
                <div class="cheq-stat-lbl">سررسید این هفته</div>
                <div class="cheq-stat-val" id="statWeekAmt">—</div>
                <div class="cheq-stat-sub" id="statWeekCnt">—</div>
            </div>
        </div>
    </div>

    <!-- تقویم (پنهان به صورت پیش‌فرض) -->
    <div class="fin-panel cheq-calendar-wrap" id="calendarWrap" style="margin-bottom:22px;">
        <div class="fin-panel-title" style="margin-bottom:16px;">
            <span class="title-icon" style="background:#eff6ff;">📅</span>
            تقویم سررسید چک‌ها
            <button onclick="toggleCalendar()" class="fin-btn fin-btn-outline fin-btn-sm" style="margin-right:auto;">بستن تقویم</button>
        </div>
        <div class="cal-nav">
            <button class="cal-nav-btn" onclick="changeCalMonth(-1)">→ ماه قبل</button>
            <span class="cal-month-label" id="calMonthLabel">بارگذاری...</span>
            <button class="cal-nav-btn" onclick="changeCalMonth(1)">ماه بعد ←</button>
        </div>
        <div style="margin-bottom:12px;display:flex;gap:12px;font-size:0.78rem;flex-wrap:wrap;">
            <span><span class="fin-cal-dot received" style="display:inline-block;vertical-align:middle;"></span> دریافتی</span>
            <span><span class="fin-cal-dot issued" style="display:inline-block;vertical-align:middle;"></span> پرداختی</span>
            <span><span class="fin-cal-dot cleared" style="display:inline-block;vertical-align:middle;"></span> وصول شده</span>
            <span><span class="fin-cal-dot overdue" style="display:inline-block;vertical-align:middle;"></span> سررسید گذشته</span>
        </div>
        <div id="calendarGrid">بارگذاری تقویم...</div>
    </div>

    <!-- تب‌ها -->
    <div class="cheq-tabs">
        <button class="cheq-tab active" data-tab="received" onclick="switchTab('received', this)">
            <span>🟢</span> چک‌های دریافتی
            <span class="tab-count" id="tabCountReceived">0</span>
        </button>
        <button class="cheq-tab" data-tab="issued" onclick="switchTab('issued', this)">
            <span>🔴</span> چک‌های پرداختی
            <span class="tab-count" id="tabCountIssued">0</span>
        </button>
    </div>

    <!-- فیلتر -->
    <div class="cheq-filter">
        <div class="cheq-filter-group" style="min-width:180px;flex:2;">
            <label>جستجو</label>
            <input type="text" id="filterSearch" placeholder="شماره چک، بانک، طرف حساب..." oninput="debouncedLoad()">
        </div>
        <div class="cheq-filter-group">
            <label>وضعیت</label>
            <select id="filterStatus" onchange="loadCheques()">
                <option value="">همه</option>
                <option value="pending">در جریان</option>
                <option value="cleared">وصول شده</option>
                <option value="bounced">برگشتی</option>
                <option value="transferred">منتقل شده</option>
            </select>
        </div>
        <div class="cheq-filter-group">
            <label>از تاریخ سررسید</label>
            <input type="date" id="filterDateFrom" onchange="loadCheques()">
        </div>
        <div class="cheq-filter-group">
            <label>تا تاریخ سررسید</label>
            <input type="date" id="filterDateTo" onchange="loadCheques()">
        </div>
        <div>
            <button class="fin-btn fin-btn-outline" onclick="clearFilters()">پاکسازی</button>
        </div>
    </div>

    <!-- جدول -->
    <div class="fin-panel" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
            <span id="tableTitle" style="font-weight:700;color:#1e293b;">چک‌های دریافتی</span>
            <span id="tableCount" style="font-size:0.8rem;color:#64748b;"></span>
        </div>
        <div id="tableWrap">
            <div class="fin-empty" style="padding:60px 20px;"><div class="empty-icon">⌛</div><p>در حال بارگذاری...</p></div>
        </div>
        <!-- صفحه‌بندی -->
        <div class="fin-pagination" style="padding:14px 20px;" id="paginationWrap" style="display:none;"></div>
    </div>

</div><!-- end cheq-wrap -->
</div><!-- end content-wrapper -->
</main>

<!-- Overlay -->
<div class="overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<!-- Drawer: ثبت / ویرایش چک -->
<div class="fin-drawer" id="chequeDrawer">
    <div class="fin-drawer-header">
        <span style="font-size:1.4rem;">📝</span>
        <div class="fin-drawer-title" id="drawerTitle">ثبت چک جدید</div>
        <button onclick="closeDrawer()" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:#64748b;">✕</button>
    </div>
    <div class="fin-drawer-body">
        <form id="chequeForm" autocomplete="off">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="chqId" value="0">

            <!-- نوع چک -->
            <div class="fin-form-row" style="margin-bottom:16px;">
                <label style="display:flex;align-items:center;gap:8px;padding:12px 16px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:all 0.2s;" id="lblReceived">
                    <input type="radio" name="type" value="received" checked onchange="updateTypeStyle()">
                    <span style="font-size:1.1rem;">🟢</span>
                    <div>
                        <div style="font-size:0.85rem;font-weight:700;color:#059669;">چک دریافتی</div>
                        <div style="font-size:0.72rem;color:#64748b;">از مشتری</div>
                    </div>
                </label>
                <label style="display:flex;align-items:center;gap:8px;padding:12px 16px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:all 0.2s;" id="lblIssued">
                    <input type="radio" name="type" value="issued" onchange="updateTypeStyle()">
                    <span style="font-size:1.1rem;">🔴</span>
                    <div>
                        <div style="font-size:0.85rem;font-weight:700;color:#e11d48;">چک پرداختی</div>
                        <div style="font-size:0.72rem;color:#64748b;">به تامین‌کننده</div>
                    </div>
                </label>
            </div>

            <div class="fin-form-row">
                <div class="fin-form-group">
                    <label>شماره چک *</label>
                    <input type="text" name="cheque_number" id="chqNum" placeholder="مثال: ۱۲۳۴۵۶" dir="ltr" required>
                </div>
                <div class="fin-form-group">
                    <label>نام بانک</label>
                    <input type="text" name="bank_name" id="chqBankName" placeholder="نام بانک صادرکننده" list="bankSuggestions">
                    <datalist id="bankSuggestions">
                        <?php foreach(['ملی ایران','ملت','صادرات','تجارت','رفاه','مسکن','کشاورزی','سپه','آینده','پارسیان','پاسارگاد','سامان','سینا','اقتصاد نوین','شهر'] as $b): ?>
                        <option value="بانک <?php echo $b; ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label>مبلغ (تومان) *</label>
                    <input type="text" name="amount" id="chqAmount" placeholder="مثال: ۵۰۰۰۰۰۰۰" dir="ltr" required oninput="formatAmountInput(this)">
                </div>
            </div>

            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label>طرف حساب</label>
                    <div class="person-search-wrap">
                        <input type="text" id="personSearchInput" placeholder="جستجوی طرف حساب..." autocomplete="off" oninput="searchPersons(this.value)">
                        <input type="hidden" name="person_id" id="chqPersonId" value="">
                        <div class="person-dropdown" id="personDropdown"></div>
                    </div>
                    <div id="selectedPersonDisplay" style="font-size:0.78rem;color:#059669;margin-top:4px;display:none;"></div>
                    <button type="button" onclick="showAddPersonModal()" style="margin-top:6px;background:none;border:none;color:#2563eb;font-size:0.78rem;cursor:pointer;font-family:inherit;font-weight:700;padding:0;">+ افزودن طرف حساب جدید</button>
                </div>
            </div>

            <div class="fin-form-row">
                <div class="fin-form-group">
                    <label>تاریخ صدور</label>
                    <input type="date" name="issue_date" id="chqIssueDate">
                </div>
                <div class="fin-form-group">
                    <label>تاریخ سررسید *</label>
                    <input type="date" name="due_date" id="chqDueDate" required>
                </div>
            </div>

            <?php if(!empty($bankAccounts)): ?>
            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label>حساب بانکی مرتبط</label>
                    <select name="bank_account_id" id="chqBankAccId">
                        <option value="">انتخاب کنید (اختیاری)</option>
                        <?php foreach($bankAccounts as $ba): ?>
                        <option value="<?php echo $ba['id']; ?>"><?php echo htmlspecialchars($ba['bank_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <div class="fin-form-row single">
                <div class="fin-form-group">
                    <label>توضیحات</label>
                    <textarea name="description" id="chqDesc" rows="3" placeholder="توضیح اختیاری..."></textarea>
                </div>
            </div>
        </form>

        <!-- افزودن طرف حساب سریع -->
        <div id="addPersonForm" style="display:none;background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:10px;padding:14px;margin-top:4px;">
            <div style="font-weight:700;font-size:0.85rem;color:#1e293b;margin-bottom:10px;">افزودن طرف حساب جدید</div>
            <div class="fin-form-row">
                <div class="fin-form-group">
                    <label>نام *</label>
                    <input type="text" id="newPersonName" placeholder="نام کامل">
                </div>
                <div class="fin-form-group">
                    <label>موبایل</label>
                    <input type="text" id="newPersonMobile" placeholder="۰۹..." dir="ltr">
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="fin-btn fin-btn-success fin-btn-sm" onclick="addPerson()">ذخیره طرف حساب</button>
                <button type="button" class="fin-btn fin-btn-outline fin-btn-sm" onclick="document.getElementById('addPersonForm').style.display='none'">انصراف</button>
            </div>
        </div>
    </div>
    <div class="fin-drawer-footer">
        <button class="fin-btn fin-btn-primary" onclick="saveCheque()" id="chequeSubmitBtn">💾 ذخیره چک</button>
        <button class="fin-btn fin-btn-outline" onclick="closeDrawer()">انصراف</button>
    </div>
</div>

<!-- Toast -->
<div class="alert-toast" id="toast"></div>

<script>
// ────────────────────────────────────────────────────────────────
// متغیرهای سراسری
// ────────────────────────────────────────────────────────────────
const PAGE_URL     = window.location.pathname;
let currentTab     = 'received';
let currentPage    = 1;
let calYear        = new Date().getFullYear();
let calMonth       = new Date().getMonth() + 1; // 1–12 میلادی
let searchTimer    = null;
let personSearchTimer = null;

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'alert-toast ' + type + ' show';
    setTimeout(() => { t.className = 'alert-toast'; }, 3200);
}

// ── AJAX Helper ───────────────────────────────────────────────
function ajaxPost(data, cb) {
    const fd = new FormData();
    for (let [k,v] of Object.entries(data)) fd.append(k,v);
    fetch(PAGE_URL, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
        .then(r => r.json()).then(cb)
        .catch(() => showToast('خطا در ارتباط با سرور','error'));
}

// ── فرمت مبلغ ─────────────────────────────────────────────────
function formatAmountInput(el) {
    let v = el.value.replace(/[^0-9]/g,'');
    if (v) el.value = parseInt(v,10).toLocaleString('en-US');
}
function numFa(n) {
    if (!n && n !== 0) return '—';
    return Number(n).toLocaleString('en-US');
}

// ── Drawer ────────────────────────────────────────────────────
function openDrawer(id=0) {
    document.getElementById('chequeDrawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
    if (id > 0) {
        loadChequeForEdit(id);
    } else {
        resetForm();
        document.getElementById('drawerTitle').textContent = 'ثبت چک جدید';
        // پیش‌فرض تب
        const typeInput = document.querySelector('input[name="type"][value="'+currentTab+'"]');
        if (typeInput) { typeInput.checked = true; updateTypeStyle(); }
    }
}
function closeDrawer() {
    document.getElementById('chequeDrawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
function resetForm() {
    document.getElementById('chequeForm').reset();
    document.getElementById('chqId').value = '0';
    document.getElementById('chqPersonId').value = '';
    document.getElementById('personSearchInput').value = '';
    document.getElementById('selectedPersonDisplay').style.display = 'none';
    document.getElementById('addPersonForm').style.display = 'none';
    document.getElementById('personDropdown').classList.remove('active');
    updateTypeStyle();
}

// ── استایل نوع چک ─────────────────────────────────────────────
function updateTypeStyle() {
    const val = document.querySelector('input[name="type"]:checked')?.value;
    document.getElementById('lblReceived').style.borderColor = val==='received' ? '#059669' : '#e2e8f0';
    document.getElementById('lblReceived').style.background  = val==='received' ? '#ecfdf5' : '#fff';
    document.getElementById('lblIssued').style.borderColor   = val==='issued'   ? '#e11d48' : '#e2e8f0';
    document.getElementById('lblIssued').style.background    = val==='issued'   ? '#fff1f2' : '#fff';
}

// ── بارگذاری برای ویرایش ──────────────────────────────────────
function loadChequeForEdit(id) {
    document.getElementById('drawerTitle').textContent = 'ویرایش چک';
    document.getElementById('chequeSubmitBtn').textContent = '💾 ذخیره تغییرات';
    ajaxPost({action:'get', id}, res => {
        if (!res.data) return showToast('خطا در دریافت اطلاعات','error');
        const d = res.data;
        document.getElementById('chqId').value       = d.id;
        document.getElementById('chqNum').value      = d.cheque_number || '';
        document.getElementById('chqBankName').value = d.bank_name || '';
        document.getElementById('chqAmount').value   = d.amount ? Number(d.amount).toLocaleString('en-US') : '';
        document.getElementById('chqIssueDate').value= d.issue_date || '';
        document.getElementById('chqDueDate').value  = d.due_date || '';
        document.getElementById('chqDesc').value     = d.description || '';
        if (document.getElementById('chqBankAccId')) document.getElementById('chqBankAccId').value = d.bank_account_id || '';
        // نوع
        const typeInput = document.querySelector('input[name="type"][value="'+d.type+'"]');
        if (typeInput) { typeInput.checked = true; }
        updateTypeStyle();
        // طرف حساب
        if (d.person_id && d.person_name) {
            document.getElementById('chqPersonId').value         = d.person_id;
            document.getElementById('personSearchInput').value   = d.person_name;
            document.getElementById('selectedPersonDisplay').textContent = '✓ ' + d.person_name;
            document.getElementById('selectedPersonDisplay').style.display = 'block';
        }
    });
}

// ── ذخیره چک ──────────────────────────────────────────────────
function saveCheque() {
    const form = document.getElementById('chequeForm');
    const btn  = document.getElementById('chequeSubmitBtn');

    // پاکسازی مبلغ
    const rawAmt = document.getElementById('chqAmount').value.replace(/,/g,'');
    if (!document.getElementById('chqNum').value) return showToast('شماره چک الزامی است','error');
    if (!rawAmt || parseInt(rawAmt) <= 0)          return showToast('مبلغ چک الزامی است','error');
    if (!document.getElementById('chqDueDate').value) return showToast('تاریخ سررسید الزامی است','error');

    btn.disabled = true; btn.textContent = 'در حال ذخیره...';

    const fd = new FormData(form);
    fd.set('amount', rawAmt);

    fetch(PAGE_URL, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
        .then(r => r.json()).then(res => {
            btn.disabled = false;
            btn.textContent = document.getElementById('chqId').value !== '0' ? '💾 ذخیره تغییرات' : '💾 ذخیره چک';
            if (res.status === 'success') {
                showToast(res.message,'success');
                closeDrawer();
                loadCheques();
            } else {
                showToast(res.message || 'خطا','error');
            }
        }).catch(() => { btn.disabled=false; showToast('خطا در ارتباط','error'); });
}

// ── تغییر وضعیت ───────────────────────────────────────────────
function changeStatus(id, newStatus) {
    const labels = {pending:'در جریان', cleared:'وصول شده', bounced:'برگشتی', transferred:'منتقل شده'};
    if (!confirm('آیا از تغییر وضعیت به «' + labels[newStatus] + '» مطمئن هستید؟')) return;
    ajaxPost({action:'change_status', id, new_status: newStatus}, res => {
        if (res.status === 'success') { showToast(res.message,'success'); loadCheques(); }
        else showToast(res.message,'error');
    });
}

// ── حذف ───────────────────────────────────────────────────────
function deleteCheque(id, num) {
    if (!confirm('آیا از حذف چک شماره «' + num + '» مطمئن هستید؟')) return;
    ajaxPost({action:'delete', id}, res => {
        if (res.status === 'success') { showToast(res.message,'success'); loadCheques(); }
        else showToast(res.message,'error');
    });
}

// ── تب ────────────────────────────────────────────────────────
function switchTab(tab, el) {
    currentTab = tab;
    currentPage = 1;
    document.querySelectorAll('.cheq-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('tableTitle').textContent = tab === 'received' ? 'چک‌های دریافتی' : 'چک‌های پرداختی';
    loadCheques();
}

// ── جستجوی با تاخیر ───────────────────────────────────────────
function debouncedLoad() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadCheques, 420);
}

// ── پاکسازی فیلتر ─────────────────────────────────────────────
function clearFilters() {
    document.getElementById('filterSearch').value   = '';
    document.getElementById('filterStatus').value   = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value   = '';
    loadCheques();
}

// ── بارگذاری جدول ─────────────────────────────────────────────
function loadCheques(page=1) {
    currentPage = page;
    const tableWrap = document.getElementById('tableWrap');
    tableWrap.innerHTML = '<div class="fin-empty" style="padding:50px 20px;"><div class="empty-icon">⌛</div><p>در حال بارگذاری...</p></div>';

    ajaxPost({
        action:    'list',
        type:      currentTab,
        status:    document.getElementById('filterStatus').value,
        date_from: document.getElementById('filterDateFrom').value,
        date_to:   document.getElementById('filterDateTo').value,
        search:    document.getElementById('filterSearch').value,
        page:      currentPage,
    }, res => {
        if (res.status !== 'success') { showToast(res.message || 'خطا','error'); return; }

        // به‌روزرسانی آمار
        const s = res.stats || {};
        const w = res.week  || {};
        document.getElementById('statPendingAmt').textContent  = numFa(s.pending?.amount)   + ' ت';
        document.getElementById('statPendingCnt').textContent  = (s.pending?.count || 0)    + ' فقره';
        document.getElementById('statClearedAmt').textContent  = numFa(s.cleared?.amount)   + ' ت';
        document.getElementById('statClearedCnt').textContent  = (s.cleared?.count || 0)    + ' فقره';
        document.getElementById('statBouncedAmt').textContent  = numFa(s.bounced?.amount)   + ' ت';
        document.getElementById('statBouncedCnt').textContent  = (s.bounced?.count || 0)    + ' فقره';
        document.getElementById('statWeekAmt').textContent     = numFa(w.amount || 0)       + ' ت';
        document.getElementById('statWeekCnt').textContent     = (w.count || 0)             + ' فقره';

        // شمارش تب
        document.getElementById('tabCountReceived').textContent = currentTab==='received' ? res.total : '';
        document.getElementById('tabCountIssued').textContent   = currentTab==='issued'   ? res.total : '';

        document.getElementById('tableCount').textContent = `${res.total} مورد`;

        // رندر جدول
        if (!res.rows || res.rows.length === 0) {
            tableWrap.innerHTML = '<div class="fin-empty" style="padding:60px 20px;"><div class="empty-icon">📝</div><p>چکی یافت نشد</p></div>';
            document.getElementById('paginationWrap').innerHTML = '';
            return;
        }

        const today = new Date().toISOString().split('T')[0];
        const soon  = new Date(Date.now() + 3 * 86400000).toISOString().split('T')[0];

        let html = `
        <table class="fin-table">
            <thead>
                <tr>
                    <th style="padding:12px 16px;">شماره چک</th>
                    <th>بانک</th>
                    <th>مبلغ (تومان)</th>
                    <th>طرف حساب</th>
                    <th>تاریخ صدور</th>
                    <th>تاریخ سررسید</th>
                    <th>وضعیت</th>
                    <th style="text-align:center;">عملیات</th>
                </tr>
            </thead>
            <tbody>`;

        res.rows.forEach(r => {
            const overdue = r.status === 'pending' && r.due_date < today;
            const duesoon = r.status === 'pending' && !overdue && r.due_date <= soon;
            const rowCls  = overdue ? 'cheq-row-overdue' : duesoon ? 'cheq-row-duesoon' : '';

            const badgeMap = {
                pending:     '<span class="cheq-badge pending">⏳ در جریان</span>',
                cleared:     '<span class="cheq-badge cleared">✅ وصول شده</span>',
                bounced:     '<span class="cheq-badge bounced">❌ برگشتی</span>',
                transferred: '<span class="cheq-badge transferred">↩️ منتقل شده</span>',
            };

            let actionBtns = `<button class="cheq-action-btn btn-edit" onclick="openDrawer(${r.id})" title="ویرایش">✏️ ویرایش</button> `;

            if (r.status === 'pending') {
                actionBtns += `<button class="cheq-action-btn btn-clear" onclick="changeStatus(${r.id},'cleared')" title="وصول">✅ وصول</button> `;
                actionBtns += `<button class="cheq-action-btn btn-bounce" onclick="changeStatus(${r.id},'bounced')" title="برگشت">❌ برگشت</button> `;
                actionBtns += `<button class="cheq-action-btn btn-transfer" onclick="changeStatus(${r.id},'transferred')" title="انتقال">↩️ انتقال</button> `;
            } else if (r.status === 'bounced') {
                actionBtns += `<button class="cheq-action-btn btn-clear" onclick="changeStatus(${r.id},'pending')" title="بازگشت به جریان">↺ بازگشت</button> `;
            }
            actionBtns += `<button class="cheq-action-btn btn-delete" onclick="deleteCheque(${r.id},'${(r.cheque_number||'').replace(/'/g,"\\'")}')" title="حذف">🗑️</button>`;

            html += `
            <tr class="${rowCls}">
                <td style="padding:11px 16px;">
                    <strong style="color:#2563eb;">${escHtml(r.cheque_number)}</strong>
                    ${overdue ? '<span style="display:block;font-size:0.68rem;color:#e11d48;font-weight:700;">⚠ سررسید گذشته</span>' : ''}
                    ${duesoon ? '<span style="display:block;font-size:0.68rem;color:#d97706;font-weight:700;">⚡ سررسید نزدیک</span>' : ''}
                </td>
                <td>${escHtml(r.bank_name || '—')}</td>
                <td style="font-weight:700;direction:ltr;text-align:right;">${numFa(r.amount)}</td>
                <td>${escHtml(r.person_name || '—')}</td>
                <td style="direction:ltr;text-align:right;font-size:0.82rem;">${r.issue_date || '—'}</td>
                <td style="direction:ltr;text-align:right;font-weight:700;color:${overdue ? '#e11d48' : duesoon ? '#d97706' : '#475569'};">${r.due_date}</td>
                <td>${badgeMap[r.status] || r.status}</td>
                <td style="white-space:nowrap;text-align:center;">${actionBtns}</td>
            </tr>`;
        });

        html += '</tbody></table>';
        tableWrap.innerHTML = html;

        // صفحه‌بندی
        renderPagination(res.pages, currentPage);
    });
}

// ── صفحه‌بندی ──────────────────────────────────────────────────
function renderPagination(totalPages, current) {
    const wrap = document.getElementById('paginationWrap');
    if (totalPages <= 1) { wrap.innerHTML = ''; return; }
    let html = `<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">`;
    for (let p = 1; p <= totalPages; p++) {
        html += `<button onclick="loadCheques(${p})" style="padding:5px 12px;border-radius:7px;border:1.5px solid ${p===current?'#2563eb':'#e2e8f0'};background:${p===current?'#eff6ff':'#fff'};color:${p===current?'#2563eb':'#475569'};font-family:inherit;cursor:pointer;font-weight:700;font-size:0.8rem;">${p}</button>`;
    }
    html += `</div>`;
    wrap.innerHTML = html;
}

// ── Escape HTML ────────────────────────────────────────────────
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── جستجوی طرف حساب ───────────────────────────────────────────
function searchPersons(q) {
    const dd = document.getElementById('personDropdown');
    clearTimeout(personSearchTimer);
    if (q.length < 2) { dd.classList.remove('active'); return; }
    personSearchTimer = setTimeout(() => {
        ajaxPost({action:'search_persons', q}, res => {
            if (!res.rows || !res.rows.length) { dd.innerHTML='<div class="person-item" style="color:#94a3b8;">نتیجه‌ای یافت نشد</div>'; dd.classList.add('active'); return; }
            dd.innerHTML = res.rows.map(p => `<div class="person-item" onclick="selectPerson(${p.id},'${escHtml(p.name)}')">${escHtml(p.name)} ${p.mobile ? '<small style=\'color:#94a3b8;\'>— '+p.mobile+'</small>' : ''}</div>`).join('');
            dd.classList.add('active');
        });
    }, 350);
}
function selectPerson(id, name) {
    document.getElementById('chqPersonId').value         = id;
    document.getElementById('personSearchInput').value   = name;
    document.getElementById('personDropdown').classList.remove('active');
    document.getElementById('selectedPersonDisplay').textContent = '✓ ' + name;
    document.getElementById('selectedPersonDisplay').style.display = 'block';
}
document.addEventListener('click', e => {
    if (!e.target.closest('.person-search-wrap')) {
        document.getElementById('personDropdown').classList.remove('active');
    }
});

// ── افزودن طرف حساب ───────────────────────────────────────────
function showAddPersonModal() {
    const f = document.getElementById('addPersonForm');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
function addPerson() {
    const name   = document.getElementById('newPersonName').value.trim();
    const mobile = document.getElementById('newPersonMobile').value.trim();
    if (!name) return showToast('نام الزامی است','error');
    ajaxPost({action:'add_person', name, mobile}, res => {
        if (res.status === 'success') {
            showToast('طرف حساب اضافه شد','success');
            selectPerson(res.id, res.name);
            document.getElementById('addPersonForm').style.display = 'none';
            document.getElementById('newPersonName').value = '';
            document.getElementById('newPersonMobile').value = '';
        } else showToast(res.message,'error');
    });
}

// ── تقویم ─────────────────────────────────────────────────────
let calendarVisible = false;
function toggleCalendar() {
    const wrap = document.getElementById('calendarWrap');
    calendarVisible = !calendarVisible;
    wrap.classList.toggle('active', calendarVisible);
    if (calendarVisible) renderCalendar();
}
function changeCalMonth(dir) {
    calMonth += dir;
    if (calMonth > 12) { calMonth = 1; calYear++; }
    if (calMonth < 1)  { calMonth = 12; calYear--; }
    renderCalendar();
}

const FA_MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
const EN_DAYS   = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];

function renderCalendar() {
    const grid = document.getElementById('calendarGrid');
    const label= document.getElementById('calMonthLabel');
    grid.innerHTML = '<div style="text-align:center;padding:20px;color:#94a3b8;">بارگذاری...</div>';

    ajaxPost({action:'calendar_data', year:calYear, month:calMonth}, res => {
        if (res.status !== 'success') { grid.innerHTML='<p style="color:red;">خطا در بارگذاری تقویم</p>'; return; }

        const firstDay  = new Date(res.first);
        const lastDay   = new Date(res.last);
        const today     = new Date().toISOString().split('T')[0];
        const data      = res.data || {};

        // نام ماه
        label.textContent = `${calYear}/${String(calMonth).padStart(2,'0')} — ماه ${FA_MONTHS[calMonth-1]}`;

        let html = '<div class="fin-cal-grid">';
        // سرستون روزها
        EN_DAYS.forEach(d => { html += `<div class="fin-cal-header-day">${d}</div>`; });

        // تعیین روز هفته شروع ماه (0=شنبه در تقویم ایرانی، اما اینجا از میلادی استفاده می‌کنیم)
        let startDow = firstDay.getDay(); // 0=Sunday
        // تبدیل به شنبه‌اول: شنبه=6, یکشنبه=0, ...
        const persStartDow = (startDow + 1) % 7; // شنبه = 0
        for (let i = 0; i < persStartDow; i++) html += '<div class="fin-cal-cell empty"></div>';

        const daysInMonth = lastDay.getDate();
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${calYear}-${String(calMonth).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            const isToday = dateStr === today;
            const cheques = data[dateStr] || [];

            html += `<div class="fin-cal-cell ${isToday ? 'today' : ''}">`;
            html += `<div class="day-num">${d}</div>`;
            cheques.forEach(c => {
                const isPast = c.due_date < today && c.status === 'pending';
                const cls = isPast ? 'overdue' : (c.status !== 'pending' ? 'cleared' : c.type);
                html += `<span class="fin-cal-dot ${cls}" title="ش: ${c.cheque_number} | ${numFa(c.amount)} ت"></span>`;
            });
            html += '</div>';
        }
        html += '</div>';
        grid.innerHTML = html;
    });
}

// ── کیبورد ────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeDrawer();
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') { e.preventDefault(); openDrawer(); }
});

// ── اجرای اولیه ────────────────────────────────────────────────
updateTypeStyle();
loadCheques();

<?php if($openFromGet): ?>
// باز کردن Drawer از لینک خارجی
setTimeout(() => openDrawer(), 400);
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
