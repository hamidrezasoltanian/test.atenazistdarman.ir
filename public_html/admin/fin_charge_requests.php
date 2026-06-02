<?php
/* فایل: public_html/admin/fin_charge_requests.php */
ob_start(); session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];

// ⭐ سیستم تشخیص نقش ضدگلوله ⭐
$stmtUserRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmtUserRole->execute([$userId]);
$dbRole = $stmtUserRole->fetchColumn();

$roleName = '';
if (is_numeric($dbRole)) {
    $stmtR = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
    $stmtR->execute([$dbRole]);
    $roleName = strtolower(trim($stmtR->fetchColumn()));
} else {
    $roleName = strtolower(trim($dbRole));
}

// 1 = admin, 2 = management, 5 = finance_manager, 10 = finance_expert, 14 = accountant
$adminRoles = ['admin', 'management', '1', '2'];
$financeRoles = ['admin', 'finance_manager', 'finance_expert', 'accountant', '1', '5', '10', '14'];

$isAdmin = in_array($roleName, $adminRoles) || in_array($dbRole, $adminRoles);
$isFinance = in_array($roleName, $financeRoles) || in_array($dbRole, $financeRoles);

$msg = ''; $msgType = '';
if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; $msgType = $_SESSION['flash_type'] ?? 'success'; unset($_SESSION['flash_msg'], $_SESSION['flash_type']); }

$fiscalYear = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? $fiscalYear['id'] : null;

// --- پردازش فرم‌ها ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fiscalYearId) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");
    $action = $_POST['action'] ?? '';
    try {
        $pdo->beginTransaction();

        if ($action === 'create_request') {
            $accountId = (int)$_POST['account_id'];
            $amount = (int)str_replace(',', '', $_POST['amount']);
            $desc = trim($_POST['description'] ?? '');

            if ($amount <= 0) throw new Exception("مبلغ نامعتبر است.");
            $stmtAcc = $pdo->prepare("SELECT id FROM fin_accounts WHERE id = ? AND user_id = ? AND type = 'petty_cash' AND status = 'active'");
            $stmtAcc->execute([$accountId, $userId]);
            if (!$stmtAcc->fetch()) throw new Exception("حساب تنخواه نامعتبر است.");

            $stmt = $pdo->prepare("INSERT INTO fin_charge_requests (fiscal_year_id, user_id, account_id, amount, description, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending_admin', NOW())");
            $stmt->execute([$fiscalYearId, $userId, $accountId, $amount, $desc]);
            $reqId = $pdo->lastInsertId();

            if (function_exists('logSystem')) logSystem('PettyCash', 'request_charge', $reqId, "درخواست شارژ تنخواه مبلغ: $amount");

            if (function_exists('send_user_notification')) {
                $stmtAdmins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'management', '1', '2') AND status = 'active'");
                while ($adm = $stmtAdmins->fetch()) {
                    send_user_notification($pdo, $adm['id'], "🔔 درخواست شارژ تنخواه", "درخواست شارژ به مبلغ " . number_format($amount) . " تومان جهت بررسی مدیریت ثبت شد.", "admin/fin_charge_requests.php", 'finance', $reqId);
                }
            }
            $msg = "درخواست شارژ با موفقیت ثبت شد و در انتظار تایید مدیریت است."; $msgType = "success";
        }
        elseif ($action === 'edit_request') {
            $reqId = (int)$_POST['request_id'];
            $accountId = (int)$_POST['account_id'];
            $amount = (int)str_replace(',', '', $_POST['amount']);
            $desc = trim($_POST['description'] ?? '');

            if ($amount <= 0) throw new Exception("مبلغ نامعتبر است.");
            $stmtCheck = $pdo->prepare("SELECT id FROM fin_charge_requests WHERE id = ? AND user_id = ? AND status = 'rejected' FOR UPDATE");
            $stmtCheck->execute([$reqId, $userId]);
            if (!$stmtCheck->fetch()) throw new Exception("درخواست نامعتبر است.");

            $stmtAcc = $pdo->prepare("SELECT id FROM fin_accounts WHERE id = ? AND user_id = ? AND type = 'petty_cash' AND status = 'active'");
            $stmtAcc->execute([$accountId, $userId]);
            if (!$stmtAcc->fetch()) throw new Exception("حساب تنخواه نامعتبر است.");

            $stmt = $pdo->prepare("UPDATE fin_charge_requests SET account_id = ?, amount = ?, description = ?, status = 'pending_admin', admin_id = NULL, finance_id = NULL WHERE id = ?");
            $stmt->execute([$accountId, $amount, $desc, $reqId]);

            if (function_exists('send_user_notification')) {
                $stmtAdmins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'management', '1', '2') AND status = 'active'");
                while ($adm = $stmtAdmins->fetch()) {
                    send_user_notification($pdo, $adm['id'], "🔔 ویرایش درخواست شارژ", "درخواست شارژ به مبلغ " . number_format($amount) . " تومان ویرایش و مجدداً جهت بررسی ارسال شد.", "admin/fin_charge_requests.php", 'finance', $reqId);
                }
            }
            $msg = "درخواست شما با موفقیت ویرایش و مجدداً برای مدیریت ارسال شد."; $msgType = "success";
        }
        elseif ($action === 'admin_review' && $isAdmin) {
            $reqId = (int)$_POST['request_id'];
            $decision = $_POST['decision'];
            $rejectReason = trim($_POST['reject_reason'] ?? '');

            $stmtReq = $pdo->prepare("SELECT user_id, amount FROM fin_charge_requests WHERE id = ?");
            $stmtReq->execute([$reqId]);
            $reqData = $stmtReq->fetch();

            if ($decision === 'approve') {
                $pdo->prepare("UPDATE fin_charge_requests SET status = 'pending_finance', admin_id = ? WHERE id = ? AND status = 'pending_admin'")->execute([$userId, $reqId]);
                $msg = "درخواست تایید شد و برای واریز به واحد مالی ارسال گردید.";
                
                if (function_exists('send_user_notification')) {
                    $stmtFins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'finance_manager', 'finance_expert', 'accountant', '1', '5', '10', '14') AND status = 'active'");
                    while ($fUser = $stmtFins->fetch()) {
                        send_user_notification($pdo, $fUser['id'], "💸 تاییدیه پرداخت شارژ", "مدیریت درخواست شارژ مبلغ " . number_format($reqData['amount']) . " را تایید کرد. لطفا واریز کنید.", "admin/fin_charge_requests.php", 'finance', $reqId);
                    }
                }
            } else {
                $desc = "❌ رد شده توسط مدیریت. دلیل: " . $rejectReason;
                $pdo->prepare("UPDATE fin_charge_requests SET status = 'rejected', description = CONCAT(description, '\n\n', ?) WHERE id = ? AND status = 'pending_admin'")->execute([$desc, $reqId]);
                $msg = "درخواست رد شد و برای ویرایش به کاربر برگشت داده شد.";
                
                if (function_exists('send_user_notification')) {
                    send_user_notification($pdo, $reqData['user_id'], "❌ رد درخواست شارژ", "مدیریت درخواست شارژ شما را رد کرد: " . $rejectReason, "admin/fin_charge_requests.php", 'finance', $reqId);
                }
            }
            $msgType = "success";
        }
        elseif ($action === 'finance_pay' && $isFinance) {
            $reqId = (int)$_POST['request_id'];
            $fromBankId = (int)$_POST['from_bank_id'];

            $stmtReq = $pdo->prepare("SELECT * FROM fin_charge_requests WHERE id = ? AND status = 'pending_finance' FOR UPDATE");
            $stmtReq->execute([$reqId]);
            $request = $stmtReq->fetch(PDO::FETCH_ASSOC);
            if (!$request) throw new Exception("درخواست نامعتبر است یا قبلاً پرداخت شده.");

            $amount = (int)$request['amount'];
            $toAccountId = (int)$request['account_id'];
            $pettyCashUserId = (int)$request['user_id'];

            $stmtBank = $pdo->prepare("SELECT balance, title FROM fin_accounts WHERE id = ? AND type IN ('bank','safe') FOR UPDATE");
            $stmtBank->execute([$fromBankId]);
            $bank = $stmtBank->fetch(PDO::FETCH_ASSOC);
            if (!$bank) throw new Exception("حساب مبدأ نامعتبر است.");
            if ($bank['balance'] < $amount) throw new Exception("موجودی حساب بانکی برای این پرداخت کافی نیست!");

            $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id = ?")->execute([$amount, $fromBankId]);
            $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $toAccountId]);

            $transDesc = "شارژ تنخواه بابت درخواست #" . $reqId;
            $stmtTrans = $pdo->prepare("INSERT INTO fin_transactions (fiscal_year_id, from_account_id, to_account_id, amount, type, reference_id, description, created_by, created_at) VALUES (?, ?, ?, ?, 'charge', ?, ?, ?, NOW())");
            $stmtTrans->execute([$fiscalYearId, $fromBankId, $toAccountId, $amount, $reqId, $transDesc, $userId]);

            $pdo->prepare("UPDATE fin_charge_requests SET status = 'paid', finance_id = ? WHERE id = ?")->execute([$userId, $reqId]);

            if (function_exists('send_user_notification')) {
                send_user_notification($pdo, $pettyCashUserId, "💰 تنخواه شارژ شد", "مبلغ " . number_format($amount) . " تومان توسط واحد مالی به حساب شما واریز شد.", "admin/fin_dashboard.php", 'finance', $reqId);
            }
            if (function_exists('logSystem')) logSystem('PettyCash', 'charge_paid', $reqId, "پرداخت و شارژ تنخواه مبلغ $amount از بانک $fromBankId");

            $msg = "پرداخت با موفقیت انجام شد و تنخواه شارژ گردید."; $msgType = "success";
        }

        $pdo->commit();
        $_SESSION['flash_msg'] = $msg; $_SESSION['flash_type'] = $msgType;
        header("Location: fin_charge_requests.php"); exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_msg'] = $e->getMessage(); $_SESSION['flash_type'] = "error";
        header("Location: fin_charge_requests.php"); exit;
    }
}

// --- دریافت اطلاعات منوها ---
$myPettyCashes = $pdo->prepare("SELECT id, title, balance FROM fin_accounts WHERE type = 'petty_cash' AND user_id = ? AND status = 'active'");
$myPettyCashes->execute([$userId]);
$myPettyCashes = $myPettyCashes->fetchAll(PDO::FETCH_ASSOC);

$companyBanks = [];
if ($isFinance) {
    $companyBanks = $pdo->query("SELECT id, title, balance FROM fin_accounts WHERE type IN ('bank','safe') AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
}

// --- ⭐ منطق صفحه‌بندی (Pagination) ⭐ ---
$limit = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$whereClause = ""; 
$queryParams = [$fiscalYearId];
if ($isAdmin || $isFinance) { 
    $whereClause = "1=1"; 
} else { 
    $whereClause = "r.user_id = ?"; 
    $queryParams[] = $userId; 
}

// محاسبه کل رکوردها
$sqlCount = "SELECT COUNT(r.id) FROM fin_charge_requests r WHERE r.fiscal_year_id = ? AND $whereClause";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute($queryParams);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// دریافت رکوردهای این صفحه
$sqlReq = "SELECT r.*, a.title as account_title, u.first_name, u.last_name 
           FROM fin_charge_requests r 
           JOIN fin_accounts a ON r.account_id = a.id 
           JOIN users u ON r.user_id = u.id 
           WHERE r.fiscal_year_id = ? AND $whereClause 
           ORDER BY r.created_at DESC 
           LIMIT $limit OFFSET $offset";
$stmtReqs = $pdo->prepare($sqlReq);
$stmtReqs->execute($queryParams);
$requests = $stmtReqs->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'درخواست‌های شارژ تنخواه';
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    .panel-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .styled-table { width: 100%; border-collapse: collapse; min-width: 900px; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; color: #475569; font-weight: 900; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 0.95rem; vertical-align: middle; }
    .styled-table tr:hover td { background: #f8fafc; }
    .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; display: inline-block; text-align: center; min-width: 100px; }
    .st-pending_admin { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
    .st-pending_finance { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
    .st-paid { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .st-rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    .action-group { display: flex; gap: 6px; flex-wrap: wrap; }
    
    /* اصلاح فونت و استایل دکمه‌ها */
    .action-btn, .btn { font-family: inherit; }
    .action-btn { padding: 6px 12px; font-size: 0.8rem; font-weight: bold; border-radius: 6px; border: 1px solid transparent; cursor: pointer; transition: 0.2s; white-space: nowrap; }
    .btn-edit { background: #fef3c7; color: #b45309; border-color: #fde68a; } .btn-edit:hover { background: #fde68a; }
    .btn-approve { background: #dcfce7; color: #15803d; border-color: #bbf7d0; } .btn-approve:hover { background: #bbf7d0; }
    .btn-reject { background: #fee2e2; color: #b91c1c; border-color: #fecaca; } .btn-reject:hover { background: #fecaca; }
    .btn-pay { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; } .btn-pay:hover { background: #dbeafe; }
    
    /* استایل صفحه‌بندی */
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 25px; padding-top: 20px; border-top: 1px dashed #e2e8f0; }
    .page-link { font-family: inherit; display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; border-radius: 8px; background: #fff; border: 1px solid #cbd5e1; color: #475569; font-weight: bold; text-decoration: none; transition: 0.2s; }
    .page-link:hover { background: #f1f5f9; border-color: #94a3b8; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 10px; backdrop-filter: blur(4px); }
    .modal-box { background: #fff; width: 100%; max-width: 550px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
    .modal-header { padding: 20px 25px; background: #f8fafc; font-weight: 900; border-bottom: 1px solid #e2e8f0; display:flex; justify-content: space-between; }
    .modal-body { padding: 25px; }
    .modal-footer { padding: 20px 25px; border-top: 1px solid #e2e8f0; background: #f8fafc; display:flex; justify-content: flex-end; gap:12px;}
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';

function getStatusLabel($st) {
    if($st == 'pending_admin') return ['منتظر تایید مدیر', 'st-pending_admin'];
    if($st == 'pending_finance') return ['منتظر واریز مالی', 'st-pending_finance'];
    if($st == 'paid') return ['شارژ انجام شد', 'st-paid'];
    if($st == 'rejected') return ['رد شده (نیاز اصلاح)', 'st-rejected'];
    return ['ناشناخته', ''];
}
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            <div class="page-header" style="margin-bottom: 30px; display: flex; justify-content: space-between; flex-wrap: wrap;">
                <div><span class="page-title">💸 درخواست‌های شارژ تنخواه</span></div>
                <div>
                    <?php if(!empty($myPettyCashes)): ?>
                        <button onclick="openModal('requestModal')" class="btn btn-primary" style="font-weight:bold; box-shadow:0 4px 6px rgba(37,99,235,0.2); font-family: inherit;">➕ ثبت درخواست شارژ جدید</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if($msg): ?><div class="alert alert-<?php echo $msgType; ?>" style="font-weight:bold; margin-bottom:20px; text-align: right;"><?php echo $msg; ?></div><?php endif; ?>

            <div class="panel-card">
                <?php if(empty($requests)): ?>
                    <div style="text-align:center; padding:40px; color:#94a3b8; font-weight:bold;">هیچ درخواستی در این سال مالی یافت نشد.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="styled-table">
                            <thead>
                                <tr>
                                    <th>کد</th>
                                    <th>درخواست دهنده</th>
                                    <th>حساب تنخواه</th>
                                    <th>مبلغ (تومان)</th>
                                    <th>وضعیت</th>
                                    <th>تاریخ ثبت</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($requests as $req): list($stLabel, $stClass) = getStatusLabel($req['status']); ?>
                                <tr>
                                    <td style="font-weight:bold; color:#64748b;">#<?php echo $req['id']; ?></td>
                                    <td style="font-weight:bold; color:#1e293b;">👤 <?php echo htmlspecialchars($req['first_name'].' '.$req['last_name']); ?></td>
                                    <td style="color:#64748b; font-size:0.85rem; font-weight:bold;">🏦 <?php echo htmlspecialchars($req['account_title']); ?></td>
                                    <td style="font-weight:900; color:#10b981; direction:ltr; text-align:right; font-size:1.1rem;">
                                        <?php echo number_format($req['amount']); ?>
                                    </td>
                                    <td><span class="status-badge <?php echo $stClass; ?>"><?php echo $stLabel; ?></span></td>
                                    <td style="direction:ltr; text-align:right; font-size:0.85rem; font-weight:bold; color:#475569;">
                                        <?php echo function_exists('jdate') ? jdate('Y/m/d', strtotime($req['created_at'])) . ' <span style="color:#94a3b8; font-size:0.75rem;">' . date('H:i', strtotime($req['created_at'])) . '</span>' : $req['created_at']; ?>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <?php if($req['user_id'] == $userId && $req['status'] === 'rejected'): ?>
                                                <button type="button" class="action-btn btn-edit" data-id="<?php echo $req['id']; ?>" data-acc="<?php echo $req['account_id']; ?>" data-amount="<?php echo $req['amount']; ?>" data-desc="<?php echo htmlspecialchars($req['description']); ?>" onclick="openEditReqModal(this)">✏️ ویرایش</button>
                                            <?php endif; ?>
                                            
                                            <?php if($isAdmin && $req['status'] === 'pending_admin'): ?>
                                                <button type="button" onclick="approveRequest(<?php echo $req['id']; ?>)" class="action-btn btn-approve">✔️ تایید</button>
                                                <button type="button" onclick="openRejectModal(<?php echo $req['id']; ?>)" class="action-btn btn-reject">❌ رد</button>
                                            <?php endif; ?>
                                            
                                            <?php if($isFinance && $req['status'] === 'pending_finance'): ?>
                                                <button type="button" onclick="openPayModal(<?php echo $req['id']; ?>, <?php echo $req['amount']; ?>)" class="action-btn btn-pay">💸 واریز وجه</button>
                                            <?php endif; ?>
                                            
                                            <?php if($req['status'] === 'paid' || ($req['status'] !== 'pending_admin' && $req['status'] !== 'pending_finance' && $req['status'] !== 'rejected')): ?>
                                                 <span style="color:#cbd5e1; font-size:0.8rem;">---</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if($req['status'] === 'rejected' && $req['description']): ?>
                                            <div style="font-size:0.75rem; color:#ef4444; margin-top:5px; max-width:200px; white-space:normal;" title="<?php echo htmlspecialchars($req['description']); ?>">
                                                <?php echo htmlspecialchars(mb_substr($req['description'], 0, 50)) . '...'; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $page == $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</main>

<?php if(!empty($myPettyCashes)): ?>
<div id="requestModal" class="modal-overlay">
    <div class="modal-box">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_request">
            <div class="modal-header"><span>➕ درخواست شارژ تنخواه</span><span style="cursor:pointer; color:#ef4444;" onclick="closeModal('requestModal')">✖</span></div>
            <div class="modal-body">
                <div class="form-group">
                    <label>حساب تنخواه</label>
                    <select name="account_id" class="form-control" required style="font-family: inherit;">
                        <?php foreach($myPettyCashes as $myAcc): ?>
                        <option value="<?php echo $myAcc['id']; ?>"><?php echo htmlspecialchars($myAcc['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>مبلغ (تومان)</label>
                    <input type="text" name="amount" class="form-control amount-input" required style="font-family: inherit;">
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>توضیحات</label>
                    <textarea name="description" class="form-control" rows="3" style="font-family: inherit;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" style="font-family: inherit;" onclick="closeModal('requestModal')">انصراف</button>
                <button type="submit" class="btn btn-primary" style="font-family: inherit;">ثبت درخواست</button>
            </div>
        </form>
    </div>
</div>

<div id="editReqModal" class="modal-overlay">
    <div class="modal-box">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_request">
            <input type="hidden" name="request_id" id="edit_req_id">
            <div class="modal-header"><span>✏️ ویرایش درخواست شارژ</span><span style="cursor:pointer; color:#ef4444;" onclick="closeModal('editReqModal')">✖</span></div>
            <div class="modal-body">
                <div class="form-group">
                    <label>حساب تنخواه</label>
                    <select name="account_id" id="edit_account_id" class="form-control" required style="font-family: inherit;">
                        <?php foreach($myPettyCashes as $myAcc): ?>
                        <option value="<?php echo $myAcc['id']; ?>"><?php echo htmlspecialchars($myAcc['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>مبلغ (تومان)</label>
                    <input type="text" name="amount" id="edit_amount" class="form-control amount-input" required style="font-family: inherit;">
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>توضیحات تکمیلی</label>
                    <textarea name="description" id="edit_desc" class="form-control" rows="3" style="font-family: inherit;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" style="font-family: inherit;" onclick="closeModal('editReqModal')">انصراف</button>
                <button type="submit" class="btn btn-primary" style="font-family: inherit;">ویرایش و ارسال مجدد</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if($isAdmin): ?>
<div id="rejectModal" class="modal-overlay">
    <div class="modal-box">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="admin_review">
            <input type="hidden" name="decision" value="reject">
            <input type="hidden" name="request_id" id="rej_req_id">
            <div class="modal-header"><span>❌ رد درخواست</span><span style="cursor:pointer; color:#ef4444;" onclick="closeModal('rejectModal')">✖</span></div>
            <div class="modal-body">
                <div class="form-group">
                    <label>دلیل رد درخواست (برای تنخواه‌دار ارسال می‌شود)</label>
                    <textarea name="reject_reason" class="form-control" rows="4" required style="font-family: inherit;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" style="font-family: inherit;" onclick="closeModal('rejectModal')">انصراف</button>
                <button type="submit" class="btn btn-danger" style="font-family: inherit;">رد درخواست</button>
            </div>
        </form>
    </div>
</div>
<form id="approveForm" method="POST" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="admin_review">
    <input type="hidden" name="decision" value="approve">
    <input type="hidden" name="request_id" id="app_req_id">
</form>
<?php endif; ?>

<?php if($isFinance): ?>
<div id="payModal" class="modal-overlay">
    <div class="modal-box">
        <form method="POST" id="payForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="finance_pay">
            <input type="hidden" name="request_id" id="pay_req_id">
            <div class="modal-header"><span>💸 واریز و شارژ تنخواه</span><span style="cursor:pointer; color:#ef4444;" onclick="closeModal('payModal')">✖</span></div>
            <div class="modal-body">
                <div style="text-align:center; margin-bottom:20px; font-size:1.5rem; font-weight:bold; color:#15803d; font-family: inherit;" id="pay_amount_display"></div>
                <div class="form-group">
                    <label>واریز از کدام حساب شرکت؟</label>
                    <select name="from_bank_id" class="form-control" required style="font-family: inherit;">
                        <option value="">-- انتخاب حساب --</option>
                        <?php foreach($companyBanks as $bank): ?>
                        <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['title']); ?> (موجودی: <?php echo number_format($bank['balance']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" style="font-family: inherit;" onclick="closeModal('payModal')">انصراف</button>
                <button type="submit" class="btn btn-primary" id="btnPay" style="font-family: inherit;">تایید و واریز</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    document.querySelectorAll('.amount-input').forEach(i => i.addEventListener('input', e => { let v = e.target.value.replace(/\D/g, ''); e.target.value = v ? parseInt(v).toLocaleString('en-US') : ''; }));
    
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    
    function openEditReqModal(btn) {
        document.getElementById('edit_req_id').value = btn.getAttribute('data-id');
        document.getElementById('edit_account_id').value = btn.getAttribute('data-acc');
        let amt = btn.getAttribute('data-amount');
        document.getElementById('edit_amount').value = parseInt(amt).toLocaleString('en-US');
        document.getElementById('edit_desc').value = btn.getAttribute('data-desc');
        openModal('editReqModal');
    }

    <?php if($isAdmin): ?>
    function approveRequest(id) { if(confirm('آیا از تایید این درخواست و ارسال برای واحد مالی جهت واریز اطمینان دارید؟')) { document.getElementById('app_req_id').value = id; document.getElementById('approveForm').submit(); } }
    function openRejectModal(id) { document.getElementById('rej_req_id').value = id; openModal('rejectModal'); }
    <?php endif; ?>
    
    <?php if($isFinance): ?>
    function openPayModal(id, amount) { document.getElementById('pay_req_id').value = id; document.getElementById('pay_amount_display').innerText = parseInt(amount).toLocaleString('en-US') + ' تومان'; openModal('payModal'); }
    document.getElementById('payForm').addEventListener('submit', function() { document.getElementById('btnPay').innerText = 'در حال واریز...'; document.getElementById('btnPay').disabled = true; });
    <?php endif; ?>
    
    setTimeout(() => document.querySelectorAll('.alert').forEach(a => a.remove()), 5000);
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>