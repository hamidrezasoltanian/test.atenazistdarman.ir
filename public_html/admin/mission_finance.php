<?php
/*
 * فایل: public_html/admin/mission_finance.php
 * توضیحات: بررسی مالی، تغییر وضعیت به پرداخت شده، و بازگشایی پرونده‌های مختومه
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($requestId <= 0) die('شناسه نامعتبر است.');

$allowedFinanceRoles = ['admin', 'management', 'finance_manager', 'finance_expert'];
$isAdmin = in_array($userRole, ['admin', 'management'], true);

if (!in_array($userRole, $allowedFinanceRoles, true)) {
    die('<div style="text-align:center; margin-top:50px; font-family:tahoma; color:red; font-weight:bold;">⛔ شما دسترسی لازم برای بررسی مالی را ندارید.</div>');
}

$stmt = $pdo->prepare("SELECT r.*, u.first_name, u.last_name, u.mobile FROM mission_requests r JOIN users u ON r.user_id = u.id WHERE r.id = ? LIMIT 1");
$stmt->execute([$requestId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) die('درخواست یافت نشد.');

function translate_expense_type($type) {
    $map = ['personal' => 'هزینه شخصی', 'org_snap' => 'اسنپ سازمانی', 'discount_code' => 'کد تخفیف'];
    return $map[$type] ?? $type;
}
function translate_journey_type($type) {
    $map = ['outbound' => 'رفت', 'return' => 'برگشت', 'other' => 'سایر'];
    return $map[$type] ?? 'سایر';
}
function translate_status($status) {
    $map = ['pending' => 'در انتظار بررسی', 'approved' => 'تایید شده', 'paid' => 'پرداخت شده', 'rejected' => 'رد شده'];
    return $map[$status] ?? $status;
}
function mission_status_label($status) {
    $map = ['pending_admin' => 'در انتظار تایید مدیریت', 'expenses_open' => 'در حال ثبت هزینه', 'expenses_submitted' => 'ارسال شده به مالی', 'finance_review' => 'در حال بررسی مالی', 'completed' => 'پایان یافته (آماده پرداخت)', 'rejected' => 'رد شده'];
    return $map[$status] ?? $status;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // بازگشایی پرونده مختومه توسط ادمین
    if ($action === 'reopen_request' && $isAdmin && $req['status'] === 'completed') {
        $pdo->prepare("UPDATE mission_requests SET status='finance_review' WHERE id=?")->execute([$requestId]);
        header("Location: mission_finance.php?id=$requestId");
        exit;
    }

    // ویرایش هزینه‌ها
    if ($action === 'update_expense' && in_array($req['status'], ['finance_review', 'completed'])) {
        $expId = (int)($_POST['exp_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $note = trim($_POST['note'] ?? '');
        
        $stmtExpCheck = $pdo->prepare("SELECT status, expense_type, discount_code, amount, approved_amount, paid_at FROM mission_expenses WHERE id = ? AND request_id = ?");
        $stmtExpCheck->execute([$expId, $requestId]);
        $currentExp = $stmtExpCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($currentExp) {
            $originalAmount = (float)$currentExp['amount'];
            $paidAt = $currentExp['paid_at'];
            $approvedAmount = (float)$currentExp['approved_amount']; // پیش‌فرض از دیتابیس می‌گیریم
            
            // --- فاز اول: بررسی و تایید مبالغ (finance_review) ---
            if ($req['status'] === 'finance_review') {
                if (in_array($status, ['approved', 'rejected'], true)) {
                    $approvedAmountRaw = trim($_POST['approved_amount'] ?? '0');
                    if (function_exists('faToEn')) { $approvedAmountRaw = faToEn($approvedAmountRaw); }
                    $approvedAmountRaw = str_replace(',', '', $approvedAmountRaw);
                    $approvedAmount = (float)$approvedAmountRaw;
                    
                    if ($currentExp['expense_type'] === 'discount_code' && !empty($currentExp['discount_code'])) {
                        $stmtDc = $pdo->prepare("SELECT discount_amount FROM discount_codes WHERE code = ?");
                        $stmtDc->execute([$currentExp['discount_code']]);
                        $dcData = $stmtDc->fetch(PDO::FETCH_ASSOC);
                        if ($dcData) { $approvedAmount = (float)$dcData['discount_amount']; }
                    }

                    if ($status === 'rejected') {
                        $approvedAmount = 0;
                    } elseif ($status === 'approved' && $approvedAmount <= 0) {
                        if ($currentExp['expense_type'] !== 'discount_code') { $approvedAmount = $originalAmount; }
                    }
                    if ($approvedAmount > $originalAmount) { $approvedAmount = $originalAmount; }

                    $stmt = $pdo->prepare("UPDATE mission_expenses SET status=?, finance_note=?, approved_amount=?, paid_at=NULL WHERE id=? AND request_id=?");
                    $stmt->execute([$status, $note ?: null, $approvedAmount, $expId, $requestId]);
                }
            } 
            // --- فاز دوم: ثبت پرداخت‌ها (completed) ---
            elseif ($req['status'] === 'completed') {
                // در این مرحله فقط ردیف‌های تایید شده می‌توانند پرداخت شوند
                if (in_array($currentExp['status'], ['approved', 'paid'])) {
                    if (in_array($status, ['approved', 'paid'], true)) {
                        if ($status === 'paid' && empty($paidAt)) {
                            $paidAt = date('Y-m-d H:i:s');
                        } elseif ($status === 'approved') {
                            $paidAt = null; // برگشت از حالت پرداخت شده
                        }
                        
                        // مبلغ تغییری نمی‌کند (همان که قبلا تایید شده می‌ماند)
                        $stmt = $pdo->prepare("UPDATE mission_expenses SET status=?, finance_note=?, paid_at=? WHERE id=? AND request_id=?");
                        $stmt->execute([$status, $note ?: null, $paidAt, $expId, $requestId]);
                    }
                }
            }
        }
        header("Location: mission_finance.php?id=$requestId");
        exit;
    }

    if ($action === 'final_approve' && $req['status'] === 'finance_review') {
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM mission_expenses WHERE request_id = ? AND status = 'pending'");
        $cntStmt->execute([$requestId]);
        $pending = (int)$cntStmt->fetchColumn();

        if ($pending === 0) {
            $pdo->prepare("UPDATE mission_requests SET status='completed' WHERE id=?")->execute([$requestId]);
            
            $smsStatusSetting = '0';
            try { $smsStatusSetting = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sms_on_mission_status'")->fetchColumn(); } catch(Exception $e){}
            if ($smsStatusSetting === '1' && !empty($req['mobile']) && function_exists('send_sms_pattern')) {
                $fullName = trim($req['first_name'] . ' ' . $req['last_name']);
                $sysConfig = @include __DIR__ . '/../../Config/config.php';
                $patternCode = $sysConfig['mission_pattern'] ?? 'xON6qLMC1P';
                send_sms_pattern($req['mobile'], $patternCode, ['name' => $fullName, 'id' => (string)$requestId, 'status' => 'تایید نهایی مالی']);
            }
            
            if (function_exists('send_user_notification')) {
                $reviewerIcon = '../assets/images/profile-icon.png';
                try {
                    $revImg = $pdo->query("SELECT profile_image FROM users WHERE id = $userId")->fetchColumn();
                    if ($revImg && $revImg != 'default.png') $reviewerIcon = '../uploads/profiles/' . $revImg;
                } catch(Exception $e){}
                send_user_notification($pdo, $req['user_id'], "✅ تسویه حساب ماموریت", "هزینه‌های ماموریت #$requestId توسط واحد مالی بررسی و پرونده جهت پرداخت بسته شد.", "admin/mission_view.php?id=$requestId", 'mission', $requestId, $reviewerIcon);
            }
        }
        header("Location: mission_finance.php?id=$requestId");
        exit;
    }
}

$stmt = $pdo->prepare("SELECT e.*, c.company_name, t.title as type_title, dc.discount_amount FROM mission_expenses e LEFT JOIN mission_items i ON e.item_id = i.id LEFT JOIN customers c ON i.customer_id = c.id LEFT JOIN mission_types t ON i.type_id = t.id LEFT JOIN discount_codes dc ON e.discount_code = dc.code WHERE e.request_id = ? ORDER BY e.id ASC");
$stmt->execute([$requestId]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- بررسی اینکه آیا تمامی ردیف‌ها پرداخت شده‌اند یا خیر ---
$allExpensesPaid = true;
if (!empty($expenses)) {
    foreach ($expenses as $ex) {
        if ($ex['status'] !== 'paid') {
            $allExpensesPaid = false;
            break;
        }
    }
} else {
    $allExpensesPaid = false;
}
// --------------------------------------------------------

$sumStmt = $pdo->prepare("SELECT SUM(CASE WHEN status IN ('approved','paid') THEN COALESCE(approved_amount, amount) ELSE 0 END) as approved_sum, SUM(CASE WHEN status='rejected' THEN amount WHEN status IN ('approved','paid') THEN (amount - COALESCE(approved_amount, amount)) ELSE 0 END) as rejected_sum, SUM(CASE WHEN status='pending' THEN amount ELSE 0 END) as pending_sum FROM mission_expenses WHERE request_id = ?");
$sumStmt->execute([$requestId]);
$summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

$pendingCount = 0;
try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM mission_expenses WHERE request_id = ? AND status = 'pending'");
    $cnt->execute([$requestId]);
    $pendingCount = (int)$cnt->fetchColumn();
} catch (Exception $e) { $pendingCount = 0; }

$pageTitle = "بررسی مالی ماموریت #$requestId";
$basePath = '../';
$extraCss = '<style>
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:right; padding:12px 10px; border-bottom:1px solid #f1f5f9; font-size:0.85rem; vertical-align: middle; }
    th { color:#6b7280; font-weight:600; background-color: #f8fafc; }
    .status-badge { padding:4px 10px; border-radius:999px; font-size:0.75rem; display:inline-block; border:1px solid #e5e7eb; font-weight:bold; }
    .st-pending { background:#fffbeb; color:#b45309; border-color:#fde68a; } 
    .st-approved { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; } 
    .st-paid { background:#e0e7ff; color:#1d4ed8; border-color:#bfdbfe; } 
    .st-rejected { background:#fef2f2; color:#b91c1c; border-color:#fecaca; } 
    .btn-sm { padding:6px 10px; font-size:0.75rem; }
    .kv { display:flex; gap:15px; flex-wrap:wrap; }
    .kv div { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:8px 12px; font-size:0.9rem; }
    .action-form { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
    .form-select, .form-control { border: 1px solid #d1d5db; border-radius: 6px; padding: 5px 8px; font-size: 0.8rem; font-family: inherit; }
    @media (max-width: 992px) {
        table, thead, tbody, th, td, tr { display:block; }
        thead { display:none; }
        tr { border:1px solid #e5e7eb; border-radius:10px; padding:15px; margin-bottom:15px; background:#fff; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        td { border:none; padding:8px 0; border-bottom: 1px solid #f1f5f9; }
        td:last-child { border-bottom: none; }
        td::before { content: attr(data-label) " : "; color:#6b7280; font-weight:600; margin-left: 5px; display: inline-block; }
        .action-form { flex-direction: column; width: 100%; align-items: stretch; }
        .action-form select, .action-form input, .action-form button { width: 100% !important; max-width: 100%; margin-bottom: 5px; height: 35px; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">بررسی مالی / پرداخت ماموریت #<?php echo $requestId; ?></span></div>
            <div>
                <?php if ($req['status'] === 'completed' && $isAdmin): ?>
                <form method="POST" style="display:inline-block; margin-left:10px;">
                    <input type="hidden" name="action" value="reopen_request">
                    <button type="submit" class="btn btn-warning" style="font-weight:bold; color:#000;" onclick="return confirm('آیا از بازگشایی مجدد این پرونده جهت ویرایش اطمینان دارید؟')">🔓 بازگشایی پرونده</button>
                </form>
                <?php endif; ?>
                <a href="missions.php" class="btn btn-secondary">بازگشت</a>
            </div>
        </div>

        <div class="card-box">
            <div class="kv">
                <div>کارمند: <strong><?php echo htmlspecialchars(trim($req['first_name'].' '.$req['last_name'])); ?></strong></div>
                <div>وضعیت ماموریت: 
                    <span class="status-badge" style="background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe;">
                        <?php echo mission_status_label($req['status']); ?>
                    </span>
                </div>
            </div>
            <?php if (!in_array($req['status'], ['finance_review', 'completed'])): ?>
                <div class="alert alert-warning" style="margin-top:15px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    این ماموریت در مرحله بررسی یا پرداخت مالی نیست.
                </div>
            <?php endif; ?>
        </div>

        <div class="card-box">
            <div class="page-title" style="margin-bottom:15px;">خلاصه هزینه‌ها</div>
            <div class="kv">
                <div style="color: #166534; background-color: #dcfce7; border-color: #86efac; font-weight:bold;">
                    جمع هزینه پرداختی شرکت: <?php echo number_format((float)$summary['approved_sum']); ?> ریال
                </div>
                <div style="color: #991b1b; background-color: #fee2e2; border-color: #fca5a5; font-weight:bold;">
                    جمع هزینه های کسر/رد شده: <?php echo number_format((float)$summary['rejected_sum']); ?> ریال
                </div>
                <div style="color: #854d0e; background-color: #fef9c3; border-color: #fde047; font-weight:bold;">
                    در انتظار بررسی: <?php echo number_format((float)$summary['pending_sum']); ?> ریال
                </div>
            </div>
        </div>

        <div class="card-box">
            <div class="page-title" style="margin-bottom:10px;">لیست ریز هزینه‌ها</div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>مربوط به</th>
                            <th>نوع/مسیر</th>
                            <th>درخواستی (ریال)</th>
                            <th>تاییدی (ریال)</th>
                            <th>فاکتور</th>
                            <th>وضعیت</th>
                            <th style="min-width: 360px;">عملیات (بررسی و پرداخت)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="7">هیچ هزینه‌ای ثبت نشده است.</td></tr>
                    <?php else: foreach ($expenses as $e): 
                        $originalAmount = (float)$e['amount'];
                        $isDiscountCode = ($e['expense_type'] === 'discount_code');
                        
                        if ($isDiscountCode) {
                             $sysDiscount = (float)($e['discount_amount'] ?? 0);
                             if ($e['approved_amount'] !== null) {
                                 $defaultApproved = (float)$e['approved_amount'];
                             } else {
                                 $defaultApproved = ($sysDiscount > $originalAmount) ? $originalAmount : $sysDiscount;
                             }
                        } else {
                             $defaultApproved = ($e['approved_amount'] !== null) ? (float)$e['approved_amount'] : $originalAmount;
                        }
                    ?>
                        <tr style="<?php echo (in_array($e['status'], ['approved', 'paid']) && $defaultApproved < $originalAmount) ? 'background-color:#fffbeb;' : ''; ?>">
                            <td data-label="مربوط به">
                                <span class="text-primary fw-bold"><?php echo htmlspecialchars($e['company_name'] ?? 'بدون مشتری'); ?></span>
                            </td>
                            <td data-label="نوع/مسیر">
                                <?php echo translate_expense_type($e['expense_type']); ?><br>
                                <span style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:0.7rem; border:1px solid #e2e8f0; margin-top:4px; display:inline-block;">
                                    مسیر: <?php echo translate_journey_type($e['journey_type'] ?? 'other'); ?>
                                </span>
                            </td>
                            <td data-label="درخواستی" class="fw-bold">
                                <?php echo number_format($originalAmount); ?>
                            </td>
                            <td data-label="تاییدی">
                                <?php if ($e['status'] === 'rejected'): ?>
                                    <span class="text-danger">0</span>
                                <?php elseif (in_array($e['status'], ['approved', 'paid'])): ?>
                                    <span class="text-success"><?php echo number_format($defaultApproved); ?></span>
                                    <?php if($defaultApproved < $originalAmount): ?>
                                        <div style="font-size:0.7rem; color:#b91c1c; margin-top:2px;">(کسر شده: <?php echo number_format($originalAmount - $defaultApproved); ?>)</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="فاکتور">
                                <?php if ($e['attachment_path']): ?>
                                    <a class="btn btn-outline btn-sm" target="_blank" href="../uploads/missions/<?php echo htmlspecialchars($e['attachment_path']); ?>">دانلود</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td data-label="وضعیت">
                                <span class="status-badge st-<?php echo $e['status']; ?>">
                                    <?php echo translate_status($e['status']); ?>
                                </span>
                                <?php if($e['paid_at']): ?>
                                    <div style="font-size:0.7rem; color:#1d4ed8; margin-top:4px; font-weight:bold;" title="تاریخ پرداخت">
                                        <?php echo function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($e['paid_at'])) : date('Y/m/d H:i', strtotime($e['paid_at'])); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if($e['finance_note']): ?>
                                    <div style="font-size:0.75rem; color:#64748b; margin-top:4px;" title="یادداشت">📝 <?php echo htmlspecialchars($e['finance_note']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="عملیات (بررسی و پرداخت)">
                                
                                <?php if ($req['status'] === 'finance_review'): ?>
                                    <form method="POST" class="action-form">
                                        <input type="hidden" name="action" value="update_expense">
                                        <input type="hidden" name="exp_id" value="<?php echo (int)$e['id']; ?>">
                                        
                                        <select name="status" class="form-select" style="width:105px; font-weight:bold;">
                                            <option value="approved" <?php echo $e['status'] === 'approved' ? 'selected' : ''; ?>>تایید مبلغ</option>
                                            <option value="rejected" <?php echo $e['status'] === 'rejected' ? 'selected' : ''; ?>>رد هزینه</option>
                                        </select>
                                        
                                        <?php if ($isDiscountCode): ?>
                                            <div style="position:relative; display:inline-block; width:100px;">
                                                <input type="text" name="approved_amount" class="form-control" title="مبلغ ثابت کد تخفیف سیستمی" value="<?php echo number_format($defaultApproved, 0, '', ''); ?>" style="width:100%; background-color:#f1f5f9; color:#64748b; cursor:not-allowed;" readonly>
                                                <i class="fas fa-lock" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:0.75rem;"></i>
                                            </div>
                                        <?php else: ?>
                                            <input type="text" name="approved_amount" class="form-control" title="مبلغ قابل تایید" value="<?php echo number_format($defaultApproved, 0, '', ''); ?>" placeholder="مبلغ تاییدی" style="width:100px;">
                                        <?php endif; ?>
                                        
                                        <input type="text" name="note" class="form-control" placeholder="یادداشت..." value="<?php echo htmlspecialchars($e['finance_note'] ?? ''); ?>" style="flex:1;">
                                        <button class="btn btn-primary btn-sm" type="submit">ثبت</button>
                                    </form>

                                <?php elseif ($req['status'] === 'completed'): ?>
                                    <?php if ($e['status'] === 'rejected'): ?>
                                        <span class="text-danger small fw-bold"><i class="fas fa-times"></i> رد شده (غیرقابل پرداخت)</span>
                                    <?php else: ?>
                                        <?php if ($allExpensesPaid): ?>
                                            <span class="text-success small fw-bold"><i class="fas fa-check-double"></i> پرداخت نهایی شده</span>
                                        <?php else: ?>
                                            <form method="POST" class="action-form">
                                                <input type="hidden" name="action" value="update_expense">
                                                <input type="hidden" name="exp_id" value="<?php echo (int)$e['id']; ?>">
                                                
                                                <select name="status" class="form-select" style="width:125px; font-weight:bold;">
                                                    <option value="approved" <?php echo $e['status'] === 'approved' ? 'selected' : ''; ?>>پرداخت نشده</option>
                                                    <option value="paid" <?php echo $e['status'] === 'paid' ? 'selected' : ''; ?>>✅ پرداخت شده</option>
                                                </select>
                                                
                                                <div style="position:relative; display:inline-block; width:100px;">
                                                    <input type="text" name="approved_amount" class="form-control" title="مبلغ قفل شده" value="<?php echo number_format($defaultApproved, 0, '', ''); ?>" style="width:100%; background-color:#f1f5f9; color:#64748b; cursor:not-allowed;" readonly>
                                                    <i class="fas fa-lock" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:0.75rem;"></i>
                                                </div>
                                                
                                                <input type="text" name="note" class="form-control" placeholder="یادداشت..." value="<?php echo htmlspecialchars($e['finance_note'] ?? ''); ?>" style="flex:1;">
                                                <button class="btn btn-success btn-sm" type="submit">ثبت پرداخت</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <span class="text-muted small"><i class="fas fa-lock"></i> وضعیت نهایی شده</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($req['status'] === 'finance_review'): ?>
            <div class="card-box">
                <form method="POST">
                    <input type="hidden" name="action" value="final_approve">
                    <?php if ($pendingCount === 0): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-check-circle"></i> 
                            تمام هزینه‌ها بررسی شده‌اند. جهت اتمام فرآیند و بسته شدن ماموریت دکمه زیر را بزنید.
                        </div>
                        <button class="btn btn-success w-100 py-2" type="submit">بایگانی و پایان ماموریت (ارسال پیامک)</button>
                    <?php else: ?>
                        <div class="alert alert-warning small">
                            ابتدا باید وضعیت <strong><?php echo $pendingCount; ?></strong> مورد «در انتظار بررسی» را مشخص کنید.
                        </div>
                        <button class="btn btn-success w-100 py-2" type="submit" disabled style="opacity: 0.5; cursor: not-allowed;">بایگانی و پایان ماموریت (غیرفعال)</button>
                    <?php endif; ?>
                </form>
            </div>
        <?php elseif ($req['status'] === 'completed'): ?>
            <div class="alert alert-success">
                <i class="fas fa-info-circle"></i> این ماموریت جهت پرداخت نهایی در اختیار شماست. مبالغ غیرقابل تغییر هستند.
            </div>
        <?php endif; ?>

    </div>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>