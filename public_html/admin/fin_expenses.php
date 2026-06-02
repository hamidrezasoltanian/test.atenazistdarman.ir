<?php
/* فایل: public_html/admin/fin_expenses.php */
ob_start(); session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

$isAdmin = false; $hasAccess = false;
$stmtChk = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$rawRole, $rawRole]);
$roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($roleData) {
    if ($roleData['name'] === 'admin' || $roleData['is_system'] == 1) {
        $isAdmin = true; $hasAccess = true;
    } else {
        $perms = json_decode($roleData['permissions'], true) ?? [];
        if (in_array('fin_expenses', $perms) || in_array('all', $perms)) $hasAccess = true;
    }
}

if (!$hasAccess) die('<div style="text-align:center; padding:50px; font-family:Tahoma; color:red; font-weight:bold;">⛔ دسترسی غیرمجاز.</div>');

// رفع باگ تاریخ شمسی در دیتابیس (جلوگیری از تبدیل تاریخ‌های شمسی به 0000-00-00 توسط MySQL)
try {
    $pdo->exec("ALTER TABLE fin_expenses MODIFY COLUMN invoice_date VARCHAR(15) NULL");
} catch (Throwable $e) {}

$msg = ''; $msgType = '';
if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; $msgType = $_SESSION['flash_type'] ?? 'success'; unset($_SESSION['flash_msg'], $_SESSION['flash_type']); }

$fiscalYear = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? $fiscalYear['id'] : null;

$uploadDir = __DIR__ . '/../uploads/fin_expenses/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);

function convertPersianToEnglishNums($string) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($persian, $english, $string);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fiscalYearId) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");
    $action = $_POST['action'] ?? '';

    try {
        $pdo->beginTransaction();

        if ($action === 'add') {
            $accountId = (int)$_POST['account_id'];
            $categoryId = (int)$_POST['category_id'];
            $applicantId = !empty($_POST['applicant_id']) ? (int)$_POST['applicant_id'] : null; // ⭐ فیلد جدید
            $amount = (int)str_replace(',', '', $_POST['amount']);
            $desc = trim($_POST['description'] ?? '');
            $invoiceDate = convertPersianToEnglishNums(trim($_POST['invoice_date'] ?? ''));
            
            if ($amount <= 0) throw new Exception("مبلغ نامعتبر است.");
            if (!preg_match('/^1[34]\d{2}\/(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/', $invoiceDate)) {
                throw new Exception("فرمت تاریخ اشتباه است! (مثال: 1403/05/12)");
            }
            $invoiceDateDB = str_replace('/', '-', $invoiceDate);

            $stmtAcc = $pdo->prepare("SELECT id, balance FROM fin_accounts WHERE id = ? AND user_id = ? AND type = 'petty_cash' FOR UPDATE");
            $stmtAcc->execute([$accountId, $userId]);
            $account = $stmtAcc->fetch();
            if (!$account) throw new Exception("حساب تنخواه نامعتبر است.");
            if ($account['balance'] < $amount) throw new Exception("موجودی تنخواه شما کافی نیست!");

            $attachment = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'pdf'])) {
                    $attachment = time() . '_' . rand(100, 999) . '.' . $ext;
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $attachment);
                } else throw new Exception("فرمت پیوست مجاز نیست.");
            }

            // ⭐ ذخیره با شناسه درخواست‌کننده
            $stmtIns = $pdo->prepare("INSERT INTO fin_expenses (fiscal_year_id, user_id, applicant_id, account_id, category_id, amount, description, invoice_date, attachment, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmtIns->execute([$fiscalYearId, $userId, $applicantId, $accountId, $categoryId, $amount, $desc, $invoiceDateDB, $attachment]);
            
            $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id = ?")->execute([$amount, $accountId]);
            if(function_exists('checkPettyCashBalance')) checkPettyCashBalance($accountId);

            $msg = "فاکتور ثبت شد و به لیست انتظار اضافه گردید."; $msgType = "success";
        }
        elseif ($action === 'edit') {
            $expId = (int)$_POST['expense_id'];
            $categoryId = (int)$_POST['category_id'];
            $applicantId = !empty($_POST['applicant_id']) ? (int)$_POST['applicant_id'] : null; // ⭐ فیلد جدید
            $newAmount = (int)str_replace(',', '', $_POST['amount']);
            $desc = trim($_POST['description'] ?? '');
            $invoiceDate = convertPersianToEnglishNums(trim($_POST['invoice_date'] ?? ''));
            
            if ($newAmount <= 0) throw new Exception("مبلغ نامعتبر است.");
            if (!preg_match('/^1[34]\d{2}\/(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/', $invoiceDate)) {
                throw new Exception("فرمت تاریخ اشتباه است!");
            }
            $invoiceDateDB = str_replace('/', '-', $invoiceDate);

            $stmtExp = $pdo->prepare("SELECT * FROM fin_expenses WHERE id = ? AND user_id = ? FOR UPDATE");
            $stmtExp->execute([$expId, $userId]);
            $exp = $stmtExp->fetch();

            if (!$exp) throw new Exception("فاکتور یافت نشد.");
            if ($exp['report_id'] !== null) {
                $stmtR = $pdo->prepare("SELECT status FROM fin_expense_reports WHERE id = ?");
                $stmtR->execute([$exp['report_id']]);
                if ($stmtR->fetchColumn() === 'sent') throw new Exception("این فاکتور در پرونده‌ای است که مالی در حال بررسی آن است.");
            }
            if (in_array($exp['status'], ['approved', 'sent'])) throw new Exception("این فاکتور تایید شده و قفل است.");

            $oldAmount = (int)$exp['amount'];
            $accountId = (int)$exp['account_id'];

            if ($exp['status'] === 'rejected') {
                $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id = ?")->execute([$newAmount, $accountId]);
            } else {
                $diff = $newAmount - $oldAmount;
                $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id = ?")->execute([$diff, $accountId]);
            }

            $attachment = $exp['attachment'];
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'pdf'])) {
                    $attachment = time() . '_' . rand(100, 999) . '.' . $ext;
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $attachment);
                }
            }

            // ⭐ آپدیت با شناسه درخواست‌کننده
            $pdo->prepare("UPDATE fin_expenses SET applicant_id=?, category_id=?, amount=?, description=?, invoice_date=?, attachment=?, status='pending', report_id=NULL, reject_reason=NULL WHERE id=?")->execute([$applicantId, $categoryId, $newAmount, $desc, $invoiceDateDB, $attachment, $expId]);

            $msg = "فاکتور ویرایش شد و مجدداً آماده ارسال است."; $msgType = "success";
        }
        elseif ($action === 'delete') {
            $expId = (int)$_POST['expense_id'];

            $stmtExp = $pdo->prepare("SELECT * FROM fin_expenses WHERE id = ? AND user_id = ? FOR UPDATE");
            $stmtExp->execute([$expId, $userId]);
            $exp = $stmtExp->fetch();

            if (!$exp) throw new Exception("فاکتور یافت نشد.");
            if ($exp['report_id'] !== null) {
                $stmtR = $pdo->prepare("SELECT status FROM fin_expense_reports WHERE id = ?");
                $stmtR->execute([$exp['report_id']]);
                if ($stmtR->fetchColumn() === 'sent') throw new Exception("پرونده در حال بررسی است و فاکتور قفل می‌باشد.");
            }
            if (in_array($exp['status'], ['approved', 'sent'])) throw new Exception("فاکتور قفل است.");

            if ($exp['status'] === 'pending') {
                $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id = ?")->execute([$exp['amount'], $exp['account_id']]);
            }

            $pdo->prepare("DELETE FROM fin_expenses WHERE id = ?")->execute([$expId]);
            $msg = "فاکتور با موفقیت حذف شد."; $msgType = "success";
        }

        $pdo->commit();
        $_SESSION['flash_msg'] = $msg; $_SESSION['flash_type'] = $msgType;
        header("Location: fin_expenses.php"); exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_msg'] = $e->getMessage(); $_SESSION['flash_type'] = "error";
        header("Location: fin_expenses.php"); exit;
    }
}

// --- اطلاعات پایه ---
$myAccounts = $pdo->prepare("SELECT id, title, balance FROM fin_accounts WHERE type = 'petty_cash' AND user_id = ? AND status = 'active'");
$myAccounts->execute([$userId]);
$myAccounts = $myAccounts->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT id, title FROM fin_expense_categories WHERE status = 'active' ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

// ⭐ دریافت تمام کاربران اتوماسیون برای لیست درخواست‌کننده‌ها
$allUsers = $pdo->query("SELECT id, first_name, last_name, role FROM users WHERE status = 'active' ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$where = ["e.user_id = ?", "e.fiscal_year_id = ?"];
$params = [$userId, $fiscalYearId];

$filterAcc = $_GET['account_id'] ?? '';
$filterCat = $_GET['category_id'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterAmount = str_replace(',', '', trim($_GET['amount'] ?? ''));
$filterFromDate = convertPersianToEnglishNums(trim($_GET['from_date'] ?? ''));
$filterToDate = convertPersianToEnglishNums(trim($_GET['to_date'] ?? ''));
$filterSearch = trim($_GET['search'] ?? '');

$hasActiveFilters = (!empty($filterAcc) || !empty($filterCat) || !empty($filterStatus) || !empty($filterAmount) || !empty($filterFromDate) || !empty($filterToDate) || !empty($filterSearch));

if ($filterAcc) { $where[] = "e.account_id = ?"; $params[] = (int)$filterAcc; }
if ($filterCat) { $where[] = "e.category_id = ?"; $params[] = (int)$filterCat; }
if ($filterStatus) { $where[] = "e.status = ?"; $params[] = $filterStatus; }
if ($filterAmount && is_numeric($filterAmount)) { $where[] = "e.amount = ?"; $params[] = (int)$filterAmount; }
if ($filterFromDate) { $where[] = "e.invoice_date >= ?"; $params[] = str_replace('/', '-', $filterFromDate); }
if ($filterToDate) { $where[] = "e.invoice_date <= ?"; $params[] = str_replace('/', '-', $filterToDate); }
if ($filterSearch) {
    $where[] = "(e.description LIKE ? OR e.id = ? OR r.title LIKE ?)";
    $params[] = "%$filterSearch%"; $params[] = (int)$filterSearch; $params[] = "%$filterSearch%";
}

$whereClause = implode(" AND ", $where);

$limit = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$urlParams = $_GET; unset($urlParams['page']);
$queryString = http_build_query($urlParams);
$pageSuffix = $queryString ? '&' . $queryString : '';

$stmtCount = $pdo->prepare("SELECT COUNT(e.id) FROM fin_expenses e LEFT JOIN fin_expense_reports r ON e.report_id = r.id WHERE $whereClause");
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// ⭐ جوین کردن جدول کاربران برای واکشی نام درخواست‌کننده
$sqlExp = "SELECT e.*, c.title as cat_title, a.title as account_title, r.title as report_title, r.status as report_status,
           u_app.first_name as app_fname, u_app.last_name as app_lname
           FROM fin_expenses e 
           LEFT JOIN fin_expense_categories c ON e.category_id = c.id 
           LEFT JOIN fin_accounts a ON e.account_id = a.id 
           LEFT JOIN fin_expense_reports r ON e.report_id = r.id
           LEFT JOIN users u_app ON e.applicant_id = u_app.id
           WHERE $whereClause 
           ORDER BY e.created_at DESC 
           LIMIT $limit OFFSET $offset";
           
$stmtExps = $pdo->prepare($sqlExp);
$stmtExps->execute($params);
$expenses = $stmtExps->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'ثبت و مدیریت فاکتورها';
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    .filter-accordion-label { display: flex; justify-content: space-between; align-items: center; background: #fff; border: 1px solid #e5e7eb; padding: 15px 20px; border-radius: 12px; margin-bottom: 15px; cursor: pointer; font-weight: 900; color: #1e293b; }
    .filter-accordion-label:hover { background: #f8fafc; }
    .filter-accordion-label .active-badge { background: #ef4444; color: #fff; font-size: 0.7rem; padding: 3px 8px; border-radius: 10px; margin-right: 10px; }
    .filter-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; margin-bottom: 25px; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
    .filter-group { display: flex; flex-direction: column; gap: 8px; }
    .filter-group label { font-size: 0.85rem; font-weight: bold; color: #475569; }
    .filter-actions { display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end; border-top: 1px dashed #e2e8f0; padding-top: 20px; }
    .panel-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 25px; }
    
    .table-scroll-hint { display: none; text-align: center; font-size: 0.8rem; color: #64748b; background: #f8fafc; padding: 8px; border-radius: 8px; margin-bottom: 10px; border: 1px dashed #cbd5e1; }
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .styled-table { width: 100%; border-collapse: collapse; min-width: 950px; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; color: #475569; font-weight: 900; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 0.95rem; vertical-align: middle; }
    
    .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; display: inline-block; text-align: center; min-width: 95px; }
    .st-pending { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; } 
    .st-sent { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; } 
    .st-approved { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .st-rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    
    .action-group { display: flex; gap: 6px; flex-wrap: nowrap !important; align-items: center; }
    .action-btn { font-family: inherit; padding: 6px 12px; font-size: 0.8rem; font-weight: bold; border-radius: 6px; border: 1px solid transparent; cursor: pointer; transition: 0.2s; white-space: nowrap; display: inline-flex; align-items: center; justify-content: center;}
    .btn-edit { background: #fef3c7; color: #b45309; border-color: #fde68a; } .btn-edit:hover { background: #fde68a; }
    .btn-delete { background: #fee2e2; color: #b91c1c; border-color: #fecaca; } .btn-delete:hover { background: #fecaca; }
    .btn-view { background: #f1f5f9; color: #475569; border-color: #cbd5e1; text-decoration:none; } .btn-view:hover { background: #e2e8f0; }
    
    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; margin-top: 25px; padding-top: 20px; border-top: 1px dashed #e2e8f0; }
    .page-link { font-family: inherit; display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; border-radius: 8px; background: #fff; border: 1px solid #cbd5e1; color: #475569; font-weight: bold; text-decoration: none; transition: 0.2s; }
    .page-link:hover { background: #f1f5f9; border-color: #94a3b8; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 10px; backdrop-filter: blur(4px); }
    .modal-box { background: #fff; width: 100%; max-width: 550px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
    .modal-header { padding: 20px 25px; background: #f8fafc; font-weight: 900; border-bottom: 1px solid #e2e8f0; display:flex; justify-content: space-between; }
    .modal-body { padding: 25px; max-height: 70vh; overflow-y: auto; }
    .modal-footer { padding: 20px 25px; border-top: 1px solid #e2e8f0; background: #f8fafc; display:flex; justify-content: flex-end; gap:12px;}
    
    @media (max-width: 768px) { 
        .container-centered { padding: 0 10px; } 
        .table-scroll-hint { display: block; }
        .modal-footer { flex-direction: column; } .modal-footer button { width: 100%; }
        .page-header { flex-direction: column; text-align: center; gap: 15px; } .page-header button { width: 100%; justify-content: center; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';

function getExpStatusLabel($st) {
    if($st == 'pending') return ['آماده به ارسال', 'st-pending'];
    if($st == 'sent') return ['در انتظار ممیزی', 'st-sent'];
    if($st == 'approved') return ['تایید نهایی', 'st-approved'];
    if($st == 'rejected') return ['رد شده (قابل ویرایش)', 'st-rejected'];
    return ['ناشناخته', ''];
}
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            <div class="page-header" style="margin-bottom: 25px; display: flex; justify-content: space-between; flex-wrap: wrap;">
                <div><span class="page-title">🧾 ثبت و مدیریت فاکتورها</span></div>
                <div>
                    <?php if(!empty($myAccounts)): ?>
                        <button onclick="openModal('addModal')" class="btn btn-primary" style="font-weight:bold; box-shadow:0 4px 6px rgba(37,99,235,0.2); font-family: inherit;">➕ ثبت فاکتور جدید</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if($msg): ?><div class="alert alert-<?php echo $msgType; ?>" style="font-weight:bold; margin-bottom:20px; text-align: right;"><?php echo $msg; ?></div><?php endif; ?>

            <div class="filter-accordion-label" id="toggleFilterBtn" onclick="toggleFilters()">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span>🔍</span><span>جستجو و فیلتر فاکتورها</span>
                    <?php if($hasActiveFilters): ?><span class="active-badge">فعال</span><?php endif; ?>
                </div>
                <span id="filterArrow"><?php echo $hasActiveFilters ? '🔼' : '🔽'; ?></span>
            </div>

            <form method="GET" class="filter-card" id="filterCard" style="display: <?php echo $hasActiveFilters ? 'block' : 'none'; ?>;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>از حساب تنخواه</label>
                        <select name="account_id" class="form-control" style="font-family: inherit; padding:10px;">
                            <option value="">همه حساب‌ها</option>
                            <?php foreach($myAccounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>" <?php if($filterAcc==$acc['id']) echo 'selected'; ?>><?php echo htmlspecialchars($acc['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>سرفصل هزینه</label>
                        <select name="category_id" class="form-control" style="font-family: inherit; padding:10px;">
                            <option value="">همه سرفصل‌ها</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php if($filterCat==$cat['id']) echo 'selected'; ?>><?php echo htmlspecialchars($cat['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>وضعیت فاکتور</label>
                        <select name="status" class="form-control" style="font-family: inherit; padding:10px;">
                            <option value="">همه وضعیت‌ها</option>
                            <option value="pending" <?php if($filterStatus=='pending') echo 'selected'; ?>>آماده به ارسال</option>
                            <option value="sent" <?php if($filterStatus=='sent') echo 'selected'; ?>>ارسال شده / در انتظار</option>
                            <option value="approved" <?php if($filterStatus=='approved') echo 'selected'; ?>>تایید شده</option>
                            <option value="rejected" <?php if($filterStatus=='rejected') echo 'selected'; ?>>رد شده</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>مبلغ دقیق (تومان)</label>
                        <input type="text" name="amount" class="form-control amount-input" value="<?php echo $filterAmount ? number_format((int)$filterAmount) : ''; ?>" placeholder="مثلاً 50,000" style="padding:10px; font-family: inherit; direction:ltr; text-align:right;">
                    </div>
                    <div class="filter-group">
                        <label>از تاریخ (شمسی)</label>
                        <input type="text" name="from_date" class="form-control date-input" value="<?php echo htmlspecialchars(str_replace('-', '/', $filterFromDate)); ?>" maxlength="10" placeholder="140X/XX/XX" autocomplete="off" style="padding:10px; font-family: inherit; direction:ltr; text-align:right;">
                    </div>
                    <div class="filter-group">
                        <label>تا تاریخ (شمسی)</label>
                        <input type="text" name="to_date" class="form-control date-input" value="<?php echo htmlspecialchars(str_replace('-', '/', $filterToDate)); ?>" maxlength="10" placeholder="140X/XX/XX" autocomplete="off" style="padding:10px; font-family: inherit; direction:ltr; text-align:right;">
                    </div>
                </div>
                <div class="filter-actions">
                    <a href="fin_expenses.php" class="btn btn-outline" style="padding:10px 25px; font-weight:bold; display:flex; align-items:center; font-family: inherit;">🧹 حذف فیلترها</a>
                    <button type="submit" class="btn btn-primary" style="padding:10px 25px; font-weight:bold; display:flex; align-items:center; box-shadow: 0 4px 10px rgba(37,99,235,0.2); font-family: inherit;">🔍 اعمال فیلتر</button>
                </div>
            </form>

            <div class="panel-card">
                <?php if(empty($expenses)): ?>
                    <div style="text-align:center; padding:40px; color:#94a3b8; font-weight:bold;">هیچ فاکتوری با این مشخصات یافت نشد.</div>
                <?php else: ?>
                    <div class="table-scroll-hint">👈 برای مشاهده کامل جدول به چپ و راست بکشید 👉</div>
                    <div class="table-responsive">
                        <table class="styled-table">
                            <thead>
                                <tr>
                                    <th>تاریخ فاکتور</th>
                                    <th>حساب تنخواه</th>
                                    <th>سرفصل</th>
                                    <th>شرح و درخواست‌کننده</th>
                                    <th>مبلغ (تومان)</th>
                                    <th>وضعیت / پیگیری</th>
                                    <th style="min-width: 180px;">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($expenses as $exp): 
                                    list($stLabel, $stClass) = getExpStatusLabel($exp['status']); 
                                    $displayDate = htmlspecialchars(str_replace('-', '/', $exp['invoice_date']));
                                    $isLockedInReport = ($exp['report_id'] !== null && $exp['report_status'] === 'sent');
                                ?>
                                <tr>
                                    <td style="font-weight:bold; color:#475569; direction:ltr; text-align:right; white-space: nowrap;">
                                        <?php echo $displayDate; ?>
                                    </td>
                                    <td style="color:#64748b; font-size:0.85rem; font-weight:bold;"><?php echo htmlspecialchars($exp['account_title']); ?></td>
                                    <td><span style="background:#e0f2fe; color:#0369a1; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold; white-space: nowrap;"><?php echo htmlspecialchars($exp['cat_title']); ?></span></td>
                                    <td style="max-width: 250px; white-space: normal; line-height: 1.6; font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($exp['description']); ?>
                                        <?php if($exp['applicant_id']): ?>
                                            <div style="margin-top: 5px;"><span style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px; padding: 2px 6px; font-size: 0.75rem; color: #475569; font-weight: bold;">👤 درخواستی: <?php echo htmlspecialchars($exp['app_fname'].' '.$exp['app_lname']); ?></span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight:900; color:#1e293b; direction:ltr; text-align:right; font-size:1.05rem;">
                                        <?php echo number_format($exp['amount']); ?>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <span class="status-badge <?php echo $stClass; ?>"><?php echo $stLabel; ?></span>
                                        <?php if($exp['report_id']): ?>
                                            <div style="font-size:0.75rem; color:#64748b; margin-top:5px; font-weight:bold;" title="<?php echo htmlspecialchars($exp['report_title']); ?>">بسته #<?php echo $exp['report_id']; ?></div>
                                        <?php endif; ?>
                                        <?php if($exp['status'] === 'rejected' && $exp['reject_reason']): ?>
                                            <div style="font-size:0.75rem; color:#ef4444; margin-top:5px; max-width:150px; white-space:normal; line-height:1.4;" title="<?php echo htmlspecialchars($exp['reject_reason']); ?>">
                                                علت: <?php echo htmlspecialchars(mb_substr($exp['reject_reason'], 0, 30)) . '...'; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <div class="action-group">
                                            <?php if($exp['attachment']): ?>
                                                <a href="../uploads/fin_expenses/<?php echo htmlspecialchars($exp['attachment']); ?>" target="_blank" class="action-btn btn-view" title="مشاهده فاکتور">🖼️ تصویر</a>
                                            <?php endif; ?>
                                            
                                            <?php if(!$isLockedInReport && in_array($exp['status'], ['pending', 'rejected'])): ?>
                                                <button type="button" class="action-btn btn-edit" 
                                                    data-id="<?php echo $exp['id']; ?>" 
                                                    data-cat="<?php echo $exp['category_id']; ?>" 
                                                    data-applicant="<?php echo $exp['applicant_id']; ?>" 
                                                    data-amount="<?php echo $exp['amount']; ?>" 
                                                    data-date="<?php echo $displayDate; ?>" 
                                                    data-desc="<?php echo htmlspecialchars($exp['description']); ?>" 
                                                    onclick="openEditModal(this)">✏️ ویرایش</button>
                                                
                                                <form method="POST" style="display:inline; margin:0; padding:0;" onsubmit="return confirm('آیا از حذف این فاکتور اطمینان دارید؟');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="expense_id" value="<?php echo $exp['id']; ?>">
                                                    <button type="submit" class="action-btn btn-delete">❌ حذف</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-size:0.8rem; font-weight:bold; background:#f1f5f9; padding:4px 8px; border-radius:6px; border:1px dashed #cbd5e1;">🔒 قفل در صورت‌حساب</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?page=<?php echo $i . $pageSuffix; ?>" class="page-link <?php echo $page == $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</main>

<?php if(!empty($myAccounts)): ?>
<div id="addModal" class="modal-overlay">
    <div class="modal-box">
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-header"><span>➕ ثبت فاکتور جدید</span><span style="cursor:pointer; color:#ef4444;" onclick="closeModal('addModal')">✖</span></div>
            <div class="modal-body">
                <div class="form-group">
                    <label>کسر از حساب تنخواه <span style="color:red">*</span></label>
                    <select name="account_id" class="form-control" required style="font-family: inherit;">
                        <?php foreach($myAccounts as $myAcc): ?>
                        <option value="<?php echo $myAcc['id']; ?>"><?php echo htmlspecialchars($myAcc['title']); ?> (موجودی: <?php echo number_format($myAcc['balance']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>سرفصل هزینه <span style="color:red">*</span></label>
                    <select name="category_id" class="form-control" required style="font-family: inherit;">
                        <option value="">-- انتخاب کنید --</option>
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>درخواست‌کننده (اختیاری)</label>
                    <select name="applicant_id" class="form-control" style="font-family: inherit; background:#f8fafc;">
                        <option value="">-- در صورت نیاز انتخاب کنید --</option>
                        <?php foreach($allUsers as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?> (<?php echo htmlspecialchars($u['role']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>مبلغ فاکتور (تومان) <span style="color:red">*</span></label>
                    <input type="text" name="amount" class="form-control amount-input" required style="font-family: inherit; direction:ltr; text-align:right;">
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>تاریخ فاکتور (شمسی) <span style="color:red">*</span></label>
                    <input type="text" name="invoice_date" class="form-control date-input" required placeholder="مثال: 1403/05/12" maxlength="10" pattern="^1[34]\d{2}/(0[1-9]|1[0-2])/(0[1-9]|[12]\d|3[01])$" title="سال باید با 13 یا 14 شروع شود" style="font-family: inherit; direction:ltr; text-align:right;">
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>تصویر فاکتور (اختیاری)</label>
                    <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf" style="font-family: inherit;">
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>شرح هزینه</label>
                    <textarea name="description" class="form-control" rows="3" style="font-family: inherit;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" style="font-family: inherit;" onclick="closeModal('addModal')">انصراف</button>
                <button type="submit" class="btn btn-primary" style="font-family: inherit;">ثبت فاکتور</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="modal-overlay">
    <div class="modal-box">
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="expense_id" id="edit_exp_id">
            <div class="modal-header"><span>✏️ ویرایش فاکتور</span><span style="cursor:pointer; color:#ef4444;" onclick="closeModal('editModal')">✖</span></div>
            <div class="modal-body">
                <div style="background:#fffbeb; color:#b45309; padding:10px; border-radius:8px; border:1px solid #fde68a; font-size:0.85rem; font-weight:bold; margin-bottom:15px;">
                    پس از ذخیره، این فاکتور مجدداً به لیست "آماده به ارسال" برمی‌گردد.
                </div>
                <div class="form-group">
                    <label>سرفصل هزینه</label>
                    <select name="category_id" id="edit_category" class="form-control" required style="font-family: inherit;">
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>درخواست‌کننده (اختیاری)</label>
                    <select name="applicant_id" id="edit_applicant" class="form-control" style="font-family: inherit; background:#f8fafc;">
                        <option value="">-- در صورت نیاز انتخاب کنید --</option>
                        <?php foreach($allUsers as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?> (<?php echo htmlspecialchars($u['role']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>مبلغ فاکتور (تومان)</label>
                    <input type="text" name="amount" id="edit_amount" class="form-control amount-input" required style="font-family: inherit; direction:ltr; text-align:right;">
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>تاریخ فاکتور (شمسی)</label>
                    <input type="text" name="invoice_date" id="edit_date" class="form-control date-input" required maxlength="10" pattern="^1[34]\d{2}/(0[1-9]|1[0-2])/(0[1-9]|[12]\d|3[01])$" title="سال باید با 13 یا 14 شروع شود" style="font-family: inherit; direction:ltr; text-align:right;">
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>تصویر جدید (اختیاری - جایگزین قبلی می‌شود)</label>
                    <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf" style="font-family: inherit;">
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>شرح هزینه</label>
                    <textarea name="description" id="edit_desc" class="form-control" rows="3" style="font-family: inherit;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" style="font-family: inherit;" onclick="closeModal('editModal')">انصراف</button>
                <button type="submit" class="btn btn-primary" style="font-family: inherit;">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    function toggleFilters() {
        const filterCard = document.getElementById('filterCard');
        const arrow = document.getElementById('filterArrow');
        if (window.getComputedStyle(filterCard).display === 'none') {
            filterCard.style.display = 'block'; if (arrow) arrow.innerText = '🔼';
        } else {
            filterCard.style.display = 'none'; if (arrow) arrow.innerText = '🔽';
        }
    }

    const convertPersianToEnglish = (str) => {
        const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        let result = str;
        for (let i = 0; i < 10; i++) { result = result.split(persian[i]).join(i); }
        return result;
    };

    document.querySelectorAll('.amount-input').forEach(i => i.addEventListener('input', e => { 
        let v = convertPersianToEnglish(e.target.value).replace(/\D/g, ''); 
        e.target.value = v ? parseInt(v).toLocaleString('en-US') : ''; 
    }));
    
    document.querySelectorAll('.date-input').forEach(input => {
        input.addEventListener('input', function(e) {
            let v = convertPersianToEnglish(e.target.value).replace(/[^\d]/g, '');
            if (v.length > 8) v = v.substring(0, 8);
            
            let formatted = v;
            if (v.length > 4) {
                formatted = v.substring(0, 4) + '/' + v.substring(4);
            }
            if (v.length > 6) {
                formatted = v.substring(0, 4) + '/' + v.substring(4, 6) + '/' + v.substring(6);
            }
            e.target.value = formatted;
        });
    });
    
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    
    function openEditModal(btn) {
        document.getElementById('edit_exp_id').value = btn.getAttribute('data-id');
        document.getElementById('edit_category').value = btn.getAttribute('data-cat');
        document.getElementById('edit_applicant').value = btn.getAttribute('data-applicant'); // ⭐ لود کردن درخواست کننده در ویرایش
        let amt = btn.getAttribute('data-amount');
        document.getElementById('edit_amount').value = parseInt(amt).toLocaleString('en-US');
        document.getElementById('edit_date').value = btn.getAttribute('data-date');
        document.getElementById('edit_desc').value = btn.getAttribute('data-desc');
        openModal('editModal');
    }
    
    setTimeout(() => document.querySelectorAll('.alert').forEach(a => a.remove()), 5000);
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>