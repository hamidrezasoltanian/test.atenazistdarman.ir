<?php
/*
 * فایل: public_html/admin/fin_report_details.php
 * توضیحات: ممیزی صورت‌حساب + ویرایش + چاپ کاملاً استاندارد با فونت وزیرمتن
 */

ob_start(); session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$reportId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['report_id']) ? (int)$_POST['report_id'] : 0);
$autoPrint = isset($_GET['print']) && $_GET['print'] == 1 ? true : false;

if (!$reportId) die('<div style="text-align:center; padding:50px; color:red; font-weight:bold; font-family: Tahoma;">شناسه نامعتبر است.</div>');

function convertPersianToEnglishNums($string) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($persian, $english, $string);
}

// --- ⭐ دریافت دقیق نقش و دسترسی‌ها ⭐ ---
$stmtUserRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmtUserRole->execute([$userId]);
$dbRole = $stmtUserRole->fetchColumn();

$hasFinanceAccess = false;
$isAdmin = false;

$stmtChk = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$dbRole, $dbRole]);
$rData = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($rData) {
    if ($rData['name'] === 'admin' || $rData['is_system'] == 1) {
        $isAdmin = true; $hasFinanceAccess = true;
    } else {
        $perms = json_decode($rData['permissions'], true) ?? [];
        if (in_array('fin_report_review', $perms) || in_array('fin_expense_reports', $perms) || in_array('all', $perms)) {
            $hasFinanceAccess = true;
        }
    }
}

if (!$hasFinanceAccess && !$isAdmin) {
    $roleName = strtolower(trim($rData['name'] ?? $dbRole));
    $financeRoles = ['admin', 'finance_manager', 'finance_expert', 'accountant', '1', '5', '10', '14'];
    if (in_array($roleName, $financeRoles) || in_array($dbRole, $financeRoles)) $hasFinanceAccess = true;
}

$stmtRep = $pdo->prepare("SELECT r.*, u.first_name, u.last_name FROM fin_expense_reports r JOIN users u ON r.user_id = u.id WHERE r.id = ?");
$stmtRep->execute([$reportId]);
$report = $stmtRep->fetch(PDO::FETCH_ASSOC);

if (!$report) die('<div style="text-align:center; padding:50px; color:red; font-weight:bold; font-family: Tahoma;">صورت تنخواه یافت نشد.</div>');

$isOwner = ($report['user_id'] == $userId);
if (!$isOwner && !$hasFinanceAccess && !$isAdmin) {
    die('<div style="text-align:center; padding:50px; font-family:Tahoma; color:red; font-weight:bold; line-height:1.6;">⛔ شما دسترسی مشاهده این پرونده را ندارید.</div>');
}

$fiscalYearId = $report['fiscal_year_id'];

// ====================================================
// ⭐ پردازش عملیات‌های مالی و کارمندی ⭐
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $pdo->beginTransaction();

        if ($action === 'approve_item' && $hasFinanceAccess) {
            $expId = (int)$_POST['expense_id'];
            $stmtEx = $pdo->prepare("SELECT * FROM fin_expenses WHERE id = ? FOR UPDATE");
            $stmtEx->execute([$expId]);
            $exp = $stmtEx->fetch(PDO::FETCH_ASSOC);
            
            if ($exp['status'] === 'rejected') {
                $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id=?")->execute([$exp['amount'], $exp['account_id']]);
            }
            $pdo->prepare("UPDATE fin_expenses SET status='approved', reviewed_by=?, reject_reason=NULL WHERE id=?")->execute([$userId, $expId]);
            $desc = "تایید هزینه فاکتور #" . $exp['id'] . " (صورت‌حساب #$reportId)";
            $pdo->prepare("INSERT INTO fin_transactions (fiscal_year_id, from_account_id, amount, type, reference_id, description, created_by, created_at) VALUES (?, ?, ?, 'expense', ?, ?, ?, NOW())")
                ->execute([$fiscalYearId, $exp['account_id'], $exp['amount'], $exp['id'], $desc, $userId]);
            
            $_SESSION['flash_msg'] = "فاکتور تایید شد."; $_SESSION['flash_type'] = "success";
        }
        elseif ($action === 'reject_item' && $hasFinanceAccess) {
            $expId = (int)$_POST['expense_id'];
            $reason = trim($_POST['reason'] ?? 'بدون دلیل');
            
            $stmtEx = $pdo->prepare("SELECT * FROM fin_expenses WHERE id = ? FOR UPDATE");
            $stmtEx->execute([$expId]);
            $exp = $stmtEx->fetch(PDO::FETCH_ASSOC);
            
            if ($exp['status'] !== 'rejected') {
                $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id=?")->execute([$exp['amount'], $exp['account_id']]);
                $desc = "عودت وجه فاکتور رد شده #" . $exp['id'] . " - دلیل: " . $reason;
                $pdo->prepare("INSERT INTO fin_transactions (fiscal_year_id, to_account_id, amount, type, reference_id, description, created_by, created_at) VALUES (?, ?, ?, 'return', ?, ?, ?, NOW())")
                    ->execute([$fiscalYearId, $exp['account_id'], $exp['amount'], $exp['id'], $desc, $userId]);
            }
            $pdo->prepare("UPDATE fin_expenses SET status='rejected', reject_reason=?, reviewed_by=? WHERE id=?")->execute([$reason, $userId, $expId]);
            
            $_SESSION['flash_msg'] = "فاکتور رد شد و وجه به حساب کارمند عودت داده شد."; $_SESSION['flash_type'] = "success";
        }
        elseif ($action === 'finalize_report' && $hasFinanceAccess) {
            $stmtCh = $pdo->prepare("SELECT COUNT(*) FROM fin_expenses WHERE report_id=? AND status IN ('sent', 'pending')");
            $stmtCh->execute([$reportId]);
            if($stmtCh->fetchColumn() > 0) throw new Exception('هنوز وضعیت تمام فاکتورها مشخص نشده است!');
            
            $pdo->prepare("UPDATE fin_expense_reports SET status='approved', processed_at=NOW() WHERE id=?")->execute([$reportId]);
            if(function_exists('send_user_notification')) {
                send_user_notification($pdo, $report['user_id'], "📋 نتیجه بررسی صورت تنخواه", "صورت‌حساب شماره $reportId به طور کامل بررسی و پرونده آن بسته شد.", "admin/fin_report_details.php?id=$reportId", 'finance', $reportId);
            }
            $_SESSION['flash_msg'] = "پرونده صورت‌حساب بسته شد."; $_SESSION['flash_type'] = "success";
        }
        elseif ($action === 'unlock_report' && $hasFinanceAccess) {
            $pdo->prepare("UPDATE fin_expense_reports SET status='sent', processed_at=NULL WHERE id=?")->execute([$reportId]);
            $_SESSION['flash_msg'] = "پرونده بازگشایی شد."; $_SESSION['flash_type'] = "success";
        }
        elseif ($action === 'user_edit_item' && $isOwner) {
            $stmtRepCheck = $pdo->prepare("SELECT status FROM fin_expense_reports WHERE id = ? FOR UPDATE");
            $stmtRepCheck->execute([$reportId]);
            if ($stmtRepCheck->fetchColumn() !== 'sent') throw new Exception('این پرونده بسته شده و قابل ویرایش نیست.');

            $expId = (int)$_POST['expense_id'];
            $newAccountId = (int)$_POST['account_id'];
            $categoryId = (int)$_POST['category_id'];
            $applicantId = !empty($_POST['applicant_id']) ? (int)$_POST['applicant_id'] : null;
            $newAmount = (int)str_replace(',', '', $_POST['amount']);
            $desc = trim($_POST['description'] ?? '');
            $invoiceDate = convertPersianToEnglishNums(trim($_POST['invoice_date'] ?? ''));

            if ($newAmount <= 0) throw new Exception("مبلغ نامعتبر است.");
            if (!preg_match('/^1[34]\d{2}\/(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/', $invoiceDate)) throw new Exception("فرمت تاریخ اشتباه است!");
            $invoiceDateDB = str_replace('/', '-', $invoiceDate);

            $stmtEx = $pdo->prepare("SELECT * FROM fin_expenses WHERE id = ? AND user_id = ? AND report_id = ? FOR UPDATE");
            $stmtEx->execute([$expId, $userId, $reportId]);
            $exp = $stmtEx->fetch(PDO::FETCH_ASSOC);

            if (!$exp || $exp['status'] !== 'rejected') throw new Exception('فقط فاکتورهای رد شده قابل ویرایش هستند.');

            $stmtAcc = $pdo->prepare("SELECT balance FROM fin_accounts WHERE id = ? AND user_id = ? FOR UPDATE");
            $stmtAcc->execute([$newAccountId, $userId]);
            $acc = $stmtAcc->fetch();
            if (!$acc || $acc['balance'] < $newAmount) throw new Exception("موجودی تنخواه برای کسر این مبلغ کافی نیست!");

            $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id = ?")->execute([$newAmount, $newAccountId]);

            $attachment = $exp['attachment'];
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/fin_expenses/';
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
                $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'pdf'])) {
                    $attachment = time() . '_' . rand(100, 999) . '.' . $ext;
                    move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $attachment);
                } else throw new Exception("فرمت پیوست مجاز نیست.");
            }

            $pdo->prepare("UPDATE fin_expenses SET applicant_id=?, account_id=?, category_id=?, amount=?, description=?, invoice_date=?, attachment=?, status='pending', reject_reason=NULL WHERE id=?")->execute([$applicantId, $newAccountId, $categoryId, $newAmount, $desc, $invoiceDateDB, $attachment, $expId]);
            $pdo->prepare("UPDATE fin_expense_reports SET total_amount = (SELECT SUM(amount) FROM fin_expenses WHERE report_id = ?) WHERE id = ?")->execute([$reportId, $reportId]);

            $_SESSION['flash_msg'] = "فاکتور ویرایش شد و برای بررسی مجدد به تیم مالی ارسال گردید."; $_SESSION['flash_type'] = "success";
        }

        $pdo->commit();
        header("Location: fin_report_details.php?id=$reportId"); exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_msg'] = $e->getMessage(); $_SESSION['flash_type'] = "error";
        header("Location: fin_report_details.php?id=$reportId"); exit;
    }
}

// --- دریافت فاکتورها ---
$stmtExps = $pdo->prepare("SELECT e.*, c.title as cat_title, a.title as account_title,
                           u_app.first_name as app_fname, u_app.last_name as app_lname
                           FROM fin_expenses e 
                           LEFT JOIN fin_expense_categories c ON e.category_id = c.id 
                           LEFT JOIN fin_accounts a ON e.account_id = a.id 
                           LEFT JOIN users u_app ON e.applicant_id = u_app.id
                           WHERE e.report_id = ? ORDER BY e.id ASC");
$stmtExps->execute([$reportId]);
$expenses = $stmtExps->fetchAll(PDO::FETCH_ASSOC);

$totalReport = 0; $totalApproved = 0; $totalRejected = 0; $pendingItems = 0;

foreach($expenses as $e) { 
    $totalReport += $e['amount'];
    if($e['status'] === 'approved') $totalApproved += $e['amount'];
    elseif($e['status'] === 'rejected') $totalRejected += $e['amount'];
    else $pendingItems++; 
}

$myAccounts = []; $categories = []; $allUsers = [];
if ($isOwner) {
    $myAccounts = $pdo->prepare("SELECT id, title, balance FROM fin_accounts WHERE type = 'petty_cash' AND user_id = ? AND status = 'active'");
    $myAccounts->execute([$userId]);
    $myAccounts = $myAccounts->fetchAll(PDO::FETCH_ASSOC);
    $categories = $pdo->query("SELECT id, title FROM fin_expense_categories WHERE status = 'active' ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allUsers = $pdo->query("SELECT id, first_name, last_name, role FROM users WHERE status = 'active' ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$msg = ''; $msgType = '';
if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; $msgType = $_SESSION['flash_type'] ?? 'success'; unset($_SESSION['flash_msg'], $_SESSION['flash_type']); }

$stmtRep2 = $pdo->prepare("SELECT status FROM fin_expense_reports WHERE id = ?");
$stmtRep2->execute([$reportId]);
$reportStatus = $stmtRep2->fetchColumn();

$pageTitle = 'جزئیات و ممیزی صورت تنخواه #' . $report['id'];
$basePath = '../';

// ⭐ تزریق CSS کاملاً استاندارد برای صفحه نمایش و چاپ عمودی با فونت وزیرمتن ⭐
$extraCss = '<style>
    @font-face {
        font-family: "Vazirmatn";
        src: url("../assets/fonts/Vazirmatn-Regular.ttf") format("truetype");
        font-weight: normal;
    }
    
    body, html, .styled-table, .summary-card, .panel-card, button, .btn-action, .status-badge, .alert, input, select, textarea {
        font-family: "Vazirmatn", Tahoma, Arial, sans-serif !important;
    }

    .container-centered { max-width: 1100px; margin: 0 auto; padding: 0 20px; }
    .summary-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 25px; margin-bottom: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: flex; flex-wrap: wrap; gap: 20px; justify-content: space-between; align-items: center; border-right: 4px solid #3b82f6; }
    .summary-item { display: flex; flex-direction: column; gap: 5px; }
    .summary-label { font-size: 0.85rem; color: #64748b; font-weight: bold; }
    .summary-value { font-size: 1.1rem; color: #1e293b; font-weight: 900; }
    
    .panel-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; margin-bottom: 30px; overflow: hidden; }
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    
    .styled-table { width: 100%; border-collapse: collapse; min-width: 850px; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; color: #475569; font-weight: 900; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 0.95rem; vertical-align: middle; }
    
    .status-badge { padding: 6px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: bold; display: inline-block; text-align: center; }
    .st-sent { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .st-approved { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .st-rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    
    .action-btn { font-family: inherit; padding: 6px 12px; font-size: 0.8rem; font-weight: bold; border-radius: 6px; cursor: pointer; border: 1px solid transparent; display:inline-flex; align-items:center; justify-content:center;}
    .m-approve { background: #dcfce7; color: #166534; border-color: #bbf7d0; } .m-approve:hover { background: #bbf7d0; }
    .m-reject { background: #fee2e2; color: #991b1b; border-color: #fecaca; } .m-reject:hover { background: #fecaca; }

    .bottom-actions { background: #f8fafc; padding: 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px; align-items: center; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 10px; backdrop-filter: blur(4px); }
    .modal-box { background: #fff; width: 100%; max-width: 550px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
    .modal-header { padding: 20px 25px; background: #f8fafc; font-weight: 900; border-bottom: 1px solid #e2e8f0; display:flex; justify-content: space-between; }
    .modal-body { padding: 25px; max-height: 70vh; overflow-y: auto; }
    .modal-footer { padding: 20px 25px; border-top: 1px solid #e2e8f0; background: #f8fafc; display:flex; justify-content: flex-end; gap:12px;}

    @media (max-width: 768px) { .container-centered { padding: 0 10px; } .summary-card { flex-direction: column; align-items: flex-start; } .bottom-actions { flex-direction: column; } .bottom-actions button { width: 100%; } }
    
    /* ⭐ استایل‌های حرفه‌ای پرینت به صورت عمودی (Portrait) و فشرده با فونت وزیرمتن ⭐ */
    @media print {
        @page { size: A4 portrait; margin: 10mm; }
        
        body, html, * { 
            background: #fff !important; 
            color: #000 !important; 
            font-family: "Vazirmatn", Tahoma, Arial, sans-serif !important; 
        }
        
        /* مخفی کردن تمام المان‌های اضافه قالب */
        .sidebar, .main-header, .header, footer, .main-footer, .page-header, .bottom-actions, .no-print, .action-btn { 
            display: none !important; 
        }
        
        /* مخفی کردن هدرهای پیش‌فرض مرورگر یا قالب سیستم */
        .print-header, #print-header, .system-report-header, .dt-print-header { display: none !important; }
        
        .main-content, .content-wrapper, .container-centered { 
            display: block !important; margin: 0 !important; padding: 0 !important; 
            width: 100% !important; max-width: 100% !important; position: static !important; 
        }
        
        body * { visibility: hidden !important; }
        #print-container, #print-container * { visibility: visible !important; }
        #print-container {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
        }

        .panel-card { border: none !important; box-shadow: none !important; margin: 0 !important; padding: 0 !important; }
        .table-responsive { overflow: visible !important; border: none !important; }
        
        /* هدر سفارشی چاپ (بدون لوگو و تاریخ سیستمی) */
        .print-header-custom { 
            display: flex !important; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 2px solid #000; 
            padding-bottom: 10px; 
            margin-bottom: 15px; 
            page-break-before: avoid;
        }
        .print-title h2 { margin: 0 0 5px 0; font-size: 16pt; font-weight: 900; }
        .print-info { text-align: left; font-size: 11pt; line-height: 1.6; font-weight: bold; }
        
        /* استایل جدول در حالت چاپ عمودی */
        .styled-table { 
            border: 1px solid #000; 
            width: 100% !important; 
            min-width: 0 !important; 
            table-layout: fixed; 
            margin-top: 10px; 
            border-collapse: collapse;
        }
        .styled-table thead { display: table-header-group; }
        .styled-table tr { page-break-inside: avoid; }
        
        /* تنظیم دقیق عرض ستون‌ها برای چاپ عمودی فشرده */
        .styled-table th:nth-child(1) { width: 5%; }  
        .styled-table th:nth-child(2) { width: 18%; } 
        .styled-table th:nth-child(3) { width: 37%; } 
        .styled-table th:nth-child(4) { width: 20%; } 
        .styled-table th:nth-child(5) { width: 20%; } 
        
        .styled-table th, .styled-table td { 
            border: 1px solid #000 !important; 
            padding: 4px 6px; 
            font-size: 10pt;  
            text-align: right; 
            white-space: normal !important; 
            word-wrap: break-word;
            word-break: break-word; 
            line-height: 1.4;
        }
        .styled-table th { background: #f0f0f0 !important; font-weight: bold; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        
        /* برای ستون وضعیت، کدهای HTML اضافی حذف و فقط متن نمایش داده شود */
        .status-badge { border: none !important; padding: 0 !important; background: transparent !important; color: #000 !important; }
        
        /* فوتر جدول جمع مبالغ */
        .styled-table tfoot th { font-size: 11pt; padding: 8px 6px; border: 1px solid #000 !important;}
    }
    .print-header-custom { display: none; }
</style>';

// فراخوانی استاندارد قالب سایت
include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';

function getRepStatusFa($st) {
    if($st == 'sent') return ['باز (در حال ممیزی)', 'st-sent'];
    if($st == 'approved') return ['پرونده مختومه و تایید', 'st-approved'];
    return ['ناشناخته', ''];
}
list($stLabel, $stClass) = getRepStatusFa($reportStatus);
$showActionCol = ($hasFinanceAccess && $reportStatus === 'sent') || ($isOwner && $reportStatus === 'sent');
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            
            <div class="page-header no-print" style="margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center;">
                <div><span class="page-title">🔍 بررسی و جزئیات صورت‌حساب</span></div>
                <div><a href="<?php echo $hasFinanceAccess ? 'fin_report_review.php' : 'fin_expense_reports.php'; ?>" class="btn btn-outline" style="font-family:inherit; font-weight:bold; text-decoration:none;">⬅️ بازگشت به لیست</a></div>
            </div>

            <?php if($msg): ?><div class="alert alert-<?php echo $msgType; ?> no-print" style="font-weight:bold; margin-bottom:20px; text-align: right;"><?php echo $msg; ?></div><?php endif; ?>

            <div id="print-container">
                <div class="print-header-custom">
                    <div class="print-title" style="flex:1; text-align:right;">
                        <h2>گزارش صورت تنخواه و هزینه‌ها</h2>
                    </div>
                    <div class="print-info" style="text-align:left;">
                        <div>شماره سیستم: <span style="font-weight:normal;"><?php echo $report['id']; ?></span></div>
                        <div>نام تنخواه‌دار: <span style="font-weight:normal;"><?php echo htmlspecialchars($report['first_name'].' '.$report['last_name']); ?></span></div>
                    </div>
                </div>

                <div class="summary-card no-print">
                    <div class="summary-item"><span class="summary-label">شماره سیستم</span><span class="summary-value">#<?php echo $report['id']; ?></span></div>
                    <div class="summary-item"><span class="summary-label">تنخواه‌دار</span><span class="summary-value">👤 <?php echo htmlspecialchars($report['first_name'].' '.$report['last_name']); ?></span></div>
                    <div class="summary-item"><span class="summary-label">ارسال شده در</span><span class="summary-value" style="direction:ltr;">
                        <?php echo function_exists('jdate') ? jdate('Y/m/d', strtotime($report['sent_at'])) . ' ' . date('H:i', strtotime($report['sent_at'])) : date('Y/m/d H:i', strtotime($report['sent_at'])); ?>
                    </span></div>
                    <div class="summary-item"><span class="summary-label">وضعیت پرونده</span><span class="status-badge <?php echo $stClass; ?>"><?php echo $stLabel; ?></span></div>
                </div>

                <div class="panel-card">
                    <div style="padding: 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;" class="no-print">
                        <h4 style="margin:0; font-size:1.1rem; font-weight:900;">🧾 لیست فاکتورها</h4>
                        <span style="font-weight:900; color:#1e293b; font-size:1rem; background:#fff; padding:5px 15px; border-radius:10px; border:1px solid #cbd5e1;">مجموع در سیستم: <?php echo number_format($totalReport); ?></span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="styled-table">
                            <thead>
                                <tr>
                                    <th>ردیف</th>
                                    <th>تاریخ / سرفصل</th>
                                    <th>شرح هزینه و درخواست‌کننده</th>
                                    <th>مبلغ (تومان)</th>
                                    <th>وضعیت</th>
                                    <?php if($showActionCol): ?>
                                        <th class="no-print" style="width: 200px;">عملیات</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; foreach($expenses as $exp): ?>
                                <tr>
                                    <td style="font-weight:bold; color:#94a3b8; text-align:center;"><?php echo $counter++; ?></td>
                                    <td>
                                        <div style="font-weight:bold; color:#475569; direction:ltr; text-align:right; margin-bottom:5px;"><?php echo htmlspecialchars(str_replace('-', '/', $exp['invoice_date'])); ?></div>
                                        <span style="background:#e0f2fe; color:#0369a1; padding:3px 6px; border-radius:6px; font-size:0.75rem; font-weight:bold;"><?php echo htmlspecialchars($exp['cat_title']); ?></span>
                                    </td>
                                    <td style="white-space: normal; line-height: 1.6; font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($exp['description']); ?>
                                        <?php if($exp['applicant_id']): ?>
                                            <div style="margin-top: 5px;"><span style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px; padding: 2px 6px; font-size: 0.75rem; color: #475569; font-weight: bold;">👤 درخواستی: <?php echo htmlspecialchars($exp['app_fname'].' '.$exp['app_lname']); ?></span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight:900; color:#1e293b; direction:ltr; text-align:right;">
                                        <?php echo number_format($exp['amount']); ?>
                                    </td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:8px; justify-content:flex-start;">
                                            <div>
                                                <?php if(in_array($exp['status'], ['sent', 'pending'])): ?>
                                                    <span class="status-badge st-sent">⏳ بررسی نشده</span>
                                                <?php elseif($exp['status'] === 'approved'): ?>
                                                    <span class="status-badge st-approved">✅ تایید شد</span>
                                                <?php elseif($exp['status'] === 'rejected'): ?>
                                                    <div class="status-badge st-rejected" style="text-align:right; display:block;">
                                                        ❌ رد شده<br>
                                                        <span style="font-weight:normal; font-size:0.7rem;">دلیل: <?php echo htmlspecialchars($exp['reject_reason']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if(!$showActionCol && $exp['attachment']): ?>
                                                <a href="../uploads/fin_expenses/<?php echo htmlspecialchars($exp['attachment']); ?>" target="_blank" title="مشاهده فاکتور" style="font-size:1.1rem; text-decoration:none;" class="no-print">🖼️</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <?php if($showActionCol): ?>
                                    <td class="no-print">
                                        <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                            <?php if($hasFinanceAccess && $reportStatus === 'sent'): ?>
                                                <?php if($exp['status'] !== 'approved'): ?>
                                                    <form method="POST" style="margin:0;"><input type="hidden" name="action" value="approve_item"><input type="hidden" name="expense_id" value="<?php echo $exp['id']; ?>"><button type="submit" class="action-btn m-approve">✔️ تایید</button></form>
                                                <?php endif; ?>
                                                <?php if($exp['status'] !== 'rejected'): ?>
                                                    <form method="POST" style="margin:0;" onsubmit="return promptReject(this);"><input type="hidden" name="action" value="reject_item"><input type="hidden" name="expense_id" value="<?php echo $exp['id']; ?>"><input type="hidden" name="reason" class="reject_reason_input"><button type="submit" class="action-btn m-reject">❌ رد</button></form>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if($isOwner && $reportStatus === 'sent' && $exp['status'] === 'rejected'): ?>
                                                <button type="button" class="action-btn" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a;" 
                                                    data-id="<?php echo $exp['id']; ?>" data-cat="<?php echo $exp['category_id']; ?>" data-applicant="<?php echo $exp['applicant_id']; ?>" data-acc="<?php echo $exp['account_id']; ?>" data-amount="<?php echo $exp['amount']; ?>" data-date="<?php echo htmlspecialchars(str_replace('-', '/', $exp['invoice_date'])); ?>" data-desc="<?php echo htmlspecialchars($exp['description']); ?>" onclick="openUserEditModal(this)">✏️ ویرایش</button>
                                            <?php endif; ?>

                                            <?php if($exp['attachment']): ?>
                                                <a href="../uploads/fin_expenses/<?php echo htmlspecialchars($exp['attachment']); ?>" target="_blank" class="action-btn" style="background:#f1f5f9; border:1px solid #cbd5e1; text-decoration:none;">🖼️</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            
                            <tfoot style="background: #f8fafc; border-top: 2px solid #e2e8f0;">
                                <tr>
                                    <th colspan="3" style="text-align:right; font-size:11pt; padding:10px; color:#ef4444;">
                                        مجموع فاکتورهای رد شده: <?php echo number_format($totalRejected); ?> تومان
                                    </th>
                                    <th colspan="<?php echo $showActionCol ? '3' : '2'; ?>" style="text-align:left; font-size:11pt; padding:10px; color:#166534;">
                                        جمع تایید شده (قابل پرداخت): <?php echo number_format($totalApproved); ?> تومان
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="bottom-actions no-print">
                        <?php if($hasFinanceAccess): ?>
                            <?php if($reportStatus === 'sent'): ?>
                                <?php if($pendingItems > 0): ?>
                                    <div style="flex:1; color:#b45309; font-size:0.85rem; font-weight:bold;">⚠️ هنوز وضعیت <?php echo $pendingItems; ?> فاکتور مشخص نشده است.</div>
                                    <button type="button" class="btn btn-primary" style="opacity:0.5; cursor:not-allowed;" title="ابتدا همه فاکتورها را ممیزی کنید">✅ بستن پرونده (غیرفعال)</button>
                                <?php else: ?>
                                    <div style="flex:1; color:#15803d; font-size:0.85rem; font-weight:bold;">همه فاکتورها ممیزی شدند. آماده بستن پرونده.</div>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('آیا از تایید نهایی و بستن این پرونده اطمینان دارید؟');"><input type="hidden" name="action" value="finalize_report"><button type="submit" class="btn btn-primary" style="font-family:inherit; box-shadow:0 4px 6px rgba(37,99,235,0.2);">✅ تایید نهایی و بستن پرونده</button></form>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="flex:1;"><button type="button" onclick="window.print();" class="btn btn-primary" style="font-family:inherit; box-shadow: 0 4px 6px rgba(37,99,235,0.2);">🖨️ چاپ صورت‌حساب</button></div>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('⚠️ توجه: با بازگشایی پرونده، کارمند متوجه تغییر وضعیت می‌شود و شما باید مجدداً پرونده را پس از ویرایش ببندید. آیا مطمئن هستید؟');"><input type="hidden" name="action" value="unlock_report"><button type="submit" class="btn btn-warning" style="font-family:inherit; color:#b45309; border-color:#fde68a;">🔓 بازگشایی برای ویرایش مجدد مالی</button></form>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if($reportStatus === 'approved'): ?>
                                <div style="flex:1;"><button type="button" onclick="window.print();" class="btn btn-primary" style="font-family:inherit; box-shadow: 0 4px 6px rgba(37,99,235,0.2);">🖨️ چاپ صورت‌حساب</button></div>
                            <?php else: ?>
                                <div style="flex:1; color:#64748b; font-weight:bold; font-size:0.9rem;">⏳ پرونده در حال بررسی است...</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div> </div>
    </div>
</main>

<?php if($isOwner && $reportStatus === 'sent'): ?>
<div id="userEditModal" class="modal-overlay no-print">
    <div class="modal-box">
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="user_edit_item">
            <input type="hidden" name="expense_id" id="edit_exp_id">
            <div class="modal-header"><span>✏️ ویرایش فاکتور رد شده</span><span style="cursor:pointer; color:#ef4444;" onclick="closeModal('userEditModal')">✖</span></div>
            <div class="modal-body">
                <div style="background:#fffbeb; color:#b45309; padding:10px; border-radius:8px; border:1px solid #fde68a; font-size:0.85rem; font-weight:bold; margin-bottom:15px;">
                    فاکتور پس از ویرایش، مجدداً در این صورت‌حساب جهت بررسی به مالی ارسال می‌شود.
                </div>
                <div class="form-group">
                    <label>کسر از حساب تنخواه <span style="color:red">*</span></label>
                    <select name="account_id" id="edit_account_id" class="form-control" required style="font-family: inherit;">
                        <?php foreach($myAccounts as $myAcc): ?>
                        <option value="<?php echo $myAcc['id']; ?>"><?php echo htmlspecialchars($myAcc['title']); ?> (موجودی: <?php echo number_format($myAcc['balance']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>سرفصل هزینه <span style="color:red">*</span></label>
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
                    <label>مبلغ فاکتور (تومان) <span style="color:red">*</span></label>
                    <input type="text" name="amount" id="edit_amount" class="form-control amount-input" required style="font-family: inherit; direction:ltr; text-align:right;">
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>تاریخ فاکتور (شمسی) <span style="color:red">*</span></label>
                    <input type="text" name="invoice_date" id="edit_date" class="form-control date-input" required maxlength="10" placeholder="140X/XX/XX" pattern="^1[34]\d{2}/(0[1-9]|1[0-2])/(0[1-9]|[12]\d|3[01])$" style="font-family: inherit; direction:ltr; text-align:right;">
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
                <button type="button" class="btn btn-outline" style="font-family: inherit;" onclick="closeModal('userEditModal')">انصراف</button>
                <button type="submit" class="btn btn-primary" style="font-family: inherit;">ذخیره و ارسال مجدد</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    <?php if(isset($_GET['print']) && $_GET['print'] == 1): ?>
    window.addEventListener('load', function() {
        setTimeout(function() { window.print(); }, 500);
    });
    <?php endif; ?>

    function promptReject(formElement) {
        let reason = prompt('❌ دلیل رد این فاکتور را بنویسید (مبلغ به تنخواه برمی‌گردد):');
        if (!reason || reason.trim() === '') { alert('ثبت دلیل الزامی است!'); return false; }
        formElement.querySelector('.reject_reason_input').value = reason; return true;
    }
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    function openUserEditModal(btn) {
        document.getElementById('edit_exp_id').value = btn.getAttribute('data-id');
        document.getElementById('edit_account_id').value = btn.getAttribute('data-acc');
        document.getElementById('edit_category').value = btn.getAttribute('data-cat');
        document.getElementById('edit_applicant').value = btn.getAttribute('data-applicant');
        let amt = btn.getAttribute('data-amount');
        document.getElementById('edit_amount').value = parseInt(amt).toLocaleString('en-US');
        document.getElementById('edit_date').value = btn.getAttribute('data-date');
        document.getElementById('edit_desc').value = btn.getAttribute('data-desc');
        openModal('userEditModal');
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
            if (v.length > 4) formatted = v.substring(0, 4) + '/' + v.substring(4);
            if (v.length > 6) formatted = v.substring(0, 4) + '/' + v.substring(4, 6) + '/' + v.substring(6);
            e.target.value = formatted;
        });
    });
    setTimeout(() => document.querySelectorAll('.alert').forEach(a => a.remove()), 5000);
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>