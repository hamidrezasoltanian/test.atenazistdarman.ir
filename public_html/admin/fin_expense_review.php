<?php
/*
 * فایل: public_html/admin/fin_expense_review.php
 * توضیحات: کارتابل ممیزی فاکتورهای تنخواه توسط واحد مالی (تایید قطعی یا رد و برگشت وجه)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// این صفحه فقط برای مدیران و واحد مالی است
$isFinance = in_array($userRole, ['admin', 'finance_manager', 'finance_expert', 'Accountant']);

if (!$isFinance) {
    die('<div style="text-align:center; padding:50px; font-family:Tahoma; color:red; font-weight:bold;">⛔ شما دسترسی ممیزی اسناد مالی را ندارید.</div>');
}

$msg = '';
$msgType = '';

if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msgType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

$fiscalYear = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? $fiscalYear['id'] : null;

// --- پردازش فرم‌های تایید و رد ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fiscalYearId) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");
    
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();

        $expenseId = (int)$_POST['expense_id'];
        
        // دریافت اطلاعات فاکتور
        $stmtExp = $pdo->prepare("SELECT * FROM fin_expenses WHERE id = ? AND status = 'pending' FOR UPDATE");
        $stmtExp->execute([$expenseId]);
        $expense = $stmtExp->fetch(PDO::FETCH_ASSOC);
        
        if (!$expense) throw new Exception("فاکتور یافت نشد یا قبلاً تعیین وضعیت شده است.");
        
        $amount = (int)$expense['amount'];
        $accountId = (int)$expense['account_id'];
        $pettyCashUserId = (int)$expense['user_id'];

        if ($action === 'approve') {
            // ۱. تغییر وضعیت به تایید شده
            $pdo->prepare("UPDATE fin_expenses SET status = 'approved', reviewed_by = ? WHERE id = ?")->execute([$userId, $expenseId]);
            
            // ۲. ثبت قطعی در دفتر کل تراکنش‌ها به عنوان هزینه (expense)
            $transDesc = "تایید قطعی هزینه بابت فاکتور #" . $expenseId . " - " . $expense['description'];
            $stmtTrans = $pdo->prepare("INSERT INTO fin_transactions (fiscal_year_id, from_account_id, amount, type, reference_id, description, created_by, created_at) VALUES (?, ?, ?, 'expense', ?, ?, ?, NOW())");
            $stmtTrans->execute([$fiscalYearId, $accountId, $amount, $expenseId, $transDesc, $userId]);
            
            // ۳. ارسال اعلان
            if (function_exists('send_user_notification')) {
                send_user_notification($pdo, $pettyCashUserId, "✅ فاکتور تایید شد", "فاکتور شماره $expenseId به مبلغ " . number_format($amount) . " تومان تایید و در دفاتر ثبت شد.", "admin/fin_expenses.php", 'finance', $expenseId);
            }
            if (function_exists('logSystem')) logSystem('PettyCash', 'expense_approve', $expenseId, "تایید قطعی فاکتور تنخواه به مبلغ $amount");
            
            $msg = "فاکتور تایید شد و سند هزینه با موفقیت در دفتر کل ثبت گردید.";
            $msgType = "success";
            
        } elseif ($action === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? '');
            if (empty($reason)) throw new Exception("ذکر دلیل برای رد فاکتور الزامی است.");
            
            // ۱. تغییر وضعیت به رد شده
            $pdo->prepare("UPDATE fin_expenses SET status = 'rejected', reject_reason = ?, reviewed_by = ? WHERE id = ?")->execute([$reason, $userId, $expenseId]);
            
            // ۲. بازگشت وجه به حساب تنخواه‌دار (چون موقع ثبت فاکتور از او کم شده بود)
            $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $accountId]);
            
            // ۳. ارسال اعلان
            if (function_exists('send_user_notification')) {
                send_user_notification($pdo, $pettyCashUserId, "❌ فاکتور رد شد", "فاکتور شماره $expenseId رد شد. مبلغ به موجودی شما برگشت داده شد. دلیل: $reason", "admin/fin_expenses.php", 'finance', $expenseId);
            }
            if (function_exists('logSystem')) logSystem('PettyCash', 'expense_reject', $expenseId, "رد فاکتور تنخواه و برگشت وجه به مبلغ $amount");
            
            $msg = "فاکتور رد شد و مبلغ " . number_format($amount) . " تومان به موجودی تنخواه‌دار برگشت داده شد.";
            $msgType = "success";
        }

        $pdo->commit();
        $_SESSION['flash_msg'] = $msg;
        $_SESSION['flash_type'] = $msgType;
        header("Location: fin_expense_review.php");
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_msg'] = $e->getMessage();
        $_SESSION['flash_type'] = "error";
        header("Location: fin_expense_review.php");
        exit;
    }
}

// --- دریافت لیست فاکتورها (مرتب شده: اولویت با بررسی نشده‌ها) ---
$sqlExps = "
    SELECT e.*, c.title as category_title, a.title as account_title, u.first_name, u.last_name 
    FROM fin_expenses e 
    LEFT JOIN fin_expense_categories c ON e.category_id = c.id
    LEFT JOIN fin_accounts a ON e.account_id = a.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.fiscal_year_id = ?
    ORDER BY CASE WHEN e.status = 'pending' THEN 1 ELSE 2 END, e.created_at DESC
";
$stmtExps = $pdo->prepare($sqlExps);
$stmtExps->execute([$fiscalYearId]);
$expenses = $stmtExps->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'ممیزی و بررسی فاکتورهای تنخواه';
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    
    .expenses-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
    .exp-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); transition: 0.3s; display: flex; flex-direction: column; height: 100%; justify-content: space-between; }
    .exp-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.06); border-color: #cbd5e1; }
    
    .status-badge { padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; display: inline-block; }
    .st-pending { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
    .st-approved { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .st-rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    
    .exp-amount { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 15px; text-align: center; margin: 15px 0; }
    .amount-text { font-size: 1.6rem; font-weight: 900; color: #ef4444; letter-spacing: 1px; display: block; direction: ltr; }
    .currency-text { font-size: 0.85rem; color: #64748b; font-weight: bold; }
    
    .exp-desc { font-size: 0.85rem; color: #475569; background: #f8fafc; padding: 12px; border-radius: 8px; border-right: 3px solid #cbd5e1; line-height: 1.6; margin-bottom: 15px; }
    
    .actions-box { display: flex; gap: 10px; margin-top: 15px; }
    .btn-action { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; font-weight: bold; padding: 10px; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); padding: 10px; }
    .modal-box { background: #fff; width: 100%; max-width: 500px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); max-height: 95vh; display: flex; flex-direction: column; }
    .modal-header { padding: 20px 25px; background: #f8fafc; font-weight: 900; font-size:1.1rem; border-bottom: 1px solid #e2e8f0; display:flex; justify-content: space-between; align-items: center;}
    .modal-body { padding: 25px; overflow-y: auto; }
    .modal-footer { padding: 20px 25px; border-top: 1px solid #e2e8f0; background: #f8fafc; display:flex; justify-content: flex-end; gap:12px;}

    @media (max-width: 768px) {
        .page-header { flex-direction: column; gap: 15px; align-items: stretch !important; text-align: center; }
        .expenses-grid { grid-template-columns: 1fr; }
        .modal-footer { flex-direction: column; }
        .modal-footer button { width: 100%; margin-bottom: 5px; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';

function getStatusLabel($st) {
    if($st == 'pending') return ['منتظر ممیزی شما', 'st-pending'];
    if($st == 'approved') return ['تایید و سند شده', 'st-approved'];
    if($st == 'rejected') return ['رد و برگشت وجه', 'st-rejected'];
    return ['ناشناخته', ''];
}
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            
            <div class="page-header page-actions" style="margin-bottom: 30px;">
                <div><span class="page-title">⚖️ کارتابل ممیزی فاکتورهای تنخواه</span></div>
            </div>

            <?php if($msg): ?>
                <div class="alert alert-<?php echo $msgType; ?>" style="font-weight:bold; margin-bottom:20px; text-align: right;"><?php echo $msg; ?></div>
            <?php endif; ?>
            <?php if(!$fiscalYearId): ?>
                <div class="alert alert-error">سال مالی فعال یافت نشد. امکان بررسی وجود ندارد.</div>
            <?php endif; ?>

            <?php if(empty($expenses)): ?>
                <div style="text-align:center; padding:50px; border:2px dashed #cbd5e1; border-radius:12px; color:#94a3b8; font-weight:bold; background:#f8fafc;">فاکتوری برای بررسی در این سال مالی وجود ندارد.</div>
            <?php else: ?>
                <div class="expenses-grid">
                    <?php foreach($expenses as $exp): 
                        list($stLabel, $stClass) = getStatusLabel($exp['status']);
                    ?>
                        <div class="exp-card">
                            <div>
                                <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:15px;">
                                    <span class="status-badge <?php echo $stClass; ?>"><?php echo $stLabel; ?></span>
                                    <span style="font-size:0.8rem; color:#94a3b8; font-weight:bold;">#<?php echo $exp['id']; ?></span>
                                </div>
                                
                                <div style="font-weight:900; color:#1e293b; font-size:1.1rem; margin-bottom:8px;">
                                    👤 <?php echo htmlspecialchars($exp['first_name'].' '.$exp['last_name']); ?>
                                </div>
                                <div style="color:#0f766e; font-size:0.9rem; margin-bottom:5px; font-weight:bold; background:#f0fdf4; padding:4px 8px; border-radius:6px; display:inline-block;">
                                    🏷️ <?php echo htmlspecialchars($exp['category_title']); ?>
                                </div>
                                <div style="color:#64748b; font-size:0.85rem; margin-bottom:10px; font-weight:bold; margin-top:5px;">
                                    🏦 از: <?php echo htmlspecialchars($exp['account_title']); ?>
                                </div>
                                
                                <div style="font-size:0.85rem; color:#475569; margin-bottom:10px;">
                                    📅 تاریخ فاکتور: <span style="font-weight: bold;"><?php echo function_exists('jdate') ? jdate('Y/m/d', strtotime($exp['invoice_date'])) : $exp['invoice_date']; ?></span>
                                </div>
                                
                                <?php if($exp['description']): ?>
                                    <div class="exp-desc">
                                        <?php echo nl2br(htmlspecialchars($exp['description'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <div class="exp-amount">
                                    <span class="amount-text"><?php echo number_format($exp['amount']); ?></span>
                                    <span class="currency-text">تومان</span>
                                </div>

                                <div style="margin-bottom: 10px;">
                                    <a href="../uploads/fin_expenses/<?php echo htmlspecialchars($exp['attachment']); ?>" target="_blank" class="btn btn-outline btn-action" style="border-color:#cbd5e1; color:#334155; background:#f8fafc;">
                                        🖼️ مشاهده تصویر فاکتور
                                    </a>
                                </div>
                                
                                <?php if($exp['status'] === 'pending'): ?>
                                    <div class="actions-box">
                                        <button onclick="approveExpense(<?php echo $exp['id']; ?>)" class="btn btn-success btn-action" style="box-shadow: 0 2px 4px rgba(16,185,129,0.2);">✔️ تایید قطعی</button>
                                        <button onclick="openRejectModal(<?php echo $exp['id']; ?>)" class="btn btn-danger btn-action" style="box-shadow: 0 2px 4px rgba(239,68,68,0.2);">❌ رد فاکتور</button>
                                    </div>
                                <?php elseif($exp['status'] === 'rejected' && $exp['reject_reason']): ?>
                                    <div style="font-size: 0.85rem; color: #b91c1c; background: #fef2f2; padding: 10px; border-radius: 8px; font-weight:bold; text-align:center;">
                                        دلیل رد: <?php echo htmlspecialchars($exp['reject_reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<div id="rejectModal" class="modal-overlay">
    <div class="modal-box">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="expense_id" id="rej_exp_id">
            
            <div class="modal-header">
                <span>❌ رد فاکتور و برگشت وجه</span>
                <span style="cursor:pointer; color:#ef4444; font-size:1.8rem; line-height:1;" onclick="closeModal('rejectModal')">&times;</span>
            </div>
            
            <div class="modal-body">
                <div style="background:#fff7ed; border:1px dashed #f59e0b; padding:15px; border-radius:10px; margin-bottom:20px; font-size:0.85rem; color:#b45309; line-height:1.6;">
                    <strong>توجه حسابداری:</strong> با رد کردن این فاکتور، مبلغ کسر شده مجدداً به عنوان بستانکار به موجودی تنخواه‌دار بازگردانده می‌شود.
                </div>
                
                <div class="form-group">
                    <label class="form-label" style="font-weight:bold; color:#1e293b;">علت رد فاکتور (برای کارمند ارسال می‌شود) <span style="color:red">*</span></label>
                    <textarea name="reject_reason" class="form-control" rows="4" required placeholder="مثال: تاریخ فاکتور مخدوش است یا فاکتور رسمی نیست..." style="padding:12px;"></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline btn-action" onclick="closeModal('rejectModal')">انصراف</button>
                <button type="submit" class="btn btn-danger btn-action">تایید و برگشت وجه</button>
            </div>
        </form>
    </div>
</div>

<form id="approveForm" method="POST" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="expense_id" id="app_exp_id">
</form>

<script>
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    function openRejectModal(id) {
        document.getElementById('rej_exp_id').value = id;
        openModal('rejectModal');
    }

    function approveExpense(id) {
        if(confirm('آیا از تایید قطعی این فاکتور اطمینان دارید؟ با این کار سند در دفتر کل ثبت می‌شود.')) {
            document.getElementById('app_exp_id').value = id;
            document.getElementById('approveForm').submit();
        }
    }
    
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(a => a.remove());
    }, 5000);
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>