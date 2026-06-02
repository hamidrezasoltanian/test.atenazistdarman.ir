<?php
/*
 * فایل: public_html/admin/fin_accounts.php
 * توضیحات: مدیریت حساب‌ها + حذف قابلیت دلیت + اضافه شدن واریز و برداشت دستی
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است.']); exit; }
    header("Location: ../login.php"); exit;
}

$userId = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// --- ⭐ دریافت دقیق نقش و دسترسی‌ها ⭐ ---
$stmtUserRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmtUserRole->execute([$userId]);
$dbRole = $stmtUserRole->fetchColumn();

$hasAccess = false;
$isAdminOrFinance = false;

$stmtChk = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$dbRole, $dbRole]);
$rData = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($rData) {
    if ($rData['name'] === 'admin' || $rData['is_system'] == 1) {
        $hasAccess = true; $isAdminOrFinance = true;
    } else {
        $perms = json_decode($rData['permissions'], true) ?? [];
        if (in_array('fin_accounts', $perms) || in_array('all', $perms)) $hasAccess = true;
    }
}

// بررسی تیم مدیریت یا مالی (برای دسترسی به واریز و برداشت)
$roleName = strtolower(trim($rData['name'] ?? $dbRole));
$financeRoles = ['admin', 'management', 'manager', 'finance_manager', 'finance_expert', 'accountant', '1', '2', '5', '10', '14'];
if (in_array($roleName, $financeRoles) || in_array($dbRole, $financeRoles)) {
    $hasAccess = true; $isAdminOrFinance = true;
}

if (!$hasAccess) {
    if ($isAjax) { echo json_encode(['status' => 'error', 'message' => 'دسترسی غیرمجاز']); exit; }
    die('<div style="text-align:center; padding:50px; font-family:Tahoma; color:red; font-weight:bold; line-height:1.6;">⛔ دسترسی غیرمجاز.</div>');
}

$fiscalYear = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? $fiscalYear['id'] : 0;

// --- پردازش درخواست‌های AJAX (ثبت، ویرایش، واریز، برداشت) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'save') {
            $id = $_POST['id'] ?? null;
            $title = trim($_POST['title'] ?? '');
            $type = $_POST['type'] ?? 'bank';
            $status = $_POST['status'] ?? 'active';
            $accUserId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;

            if (empty($title)) throw new Exception('عنوان حساب الزامی است.');
            if ($type === 'petty_cash' && !$accUserId) throw new Exception('برای حساب تنخواه، انتخاب شخص تنخواه‌دار الزامی است.');
            if ($type !== 'petty_cash') $accUserId = null;

            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO fin_accounts (title, type, user_id, balance, status, created_at) VALUES (?, ?, ?, 0, ?, NOW())");
                $stmt->execute([$title, $type, $accUserId, $status]);
                echo json_encode(['status'=>'success', 'message'=>'حساب با موفقیت تعریف شد.']);
            } else {
                $stmt = $pdo->prepare("UPDATE fin_accounts SET title=?, type=?, user_id=?, status=? WHERE id=?");
                $stmt->execute([$title, $type, $accUserId, $status, $id]);
                echo json_encode(['status'=>'success', 'message'=>'تغییرات با موفقیت ذخیره شد.']);
            }
        }
        // ⭐ منطق واریز و برداشت از حساب ⭐
        elseif ($action === 'adjust_balance' && $isAdminOrFinance) {
            if (!$fiscalYearId) throw new Exception("سال مالی فعال در سیستم یافت نشد. امکان ثبت تراکنش وجود ندارد.");
            
            $id = (int)$_POST['id'];
            $adjType = $_POST['adj_type']; // 'deposit' or 'withdraw'
            $amount = (int)str_replace(',', '', $_POST['amount']);
            $desc = trim($_POST['description'] ?? '');

            if ($amount <= 0) throw new Exception('مبلغ وارد شده نامعتبر است.');
            if (empty($desc)) throw new Exception('ثبت توضیحات / بابت تراکنش الزامی است.');

            $pdo->beginTransaction();

            $stmtAcc = $pdo->prepare("SELECT balance, title FROM fin_accounts WHERE id = ? FOR UPDATE");
            $stmtAcc->execute([$id]);
            $acc = $stmtAcc->fetch();
            if (!$acc) throw new Exception('حساب مورد نظر یافت نشد.');

            if ($adjType === 'withdraw') {
                if ($acc['balance'] < $amount) throw new Exception('موجودی فعلی حساب برای این برداشت کافی نیست!');
                
                // کسر از حساب
                $pdo->prepare("UPDATE fin_accounts SET balance = balance - ? WHERE id = ?")->execute([$amount, $id]);
                // ثبت سند دفتر کل
                $pdo->prepare("INSERT INTO fin_transactions (fiscal_year_id, from_account_id, amount, type, description, created_by, created_at) VALUES (?, ?, ?, 'transfer', ?, ?, NOW())")
                    ->execute([$fiscalYearId, $id, $amount, $desc, $userId]);
                    
            } elseif ($adjType === 'deposit') {
                // افزایش حساب
                $pdo->prepare("UPDATE fin_accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $id]);
                // ثبت سند دفتر کل
                $pdo->prepare("INSERT INTO fin_transactions (fiscal_year_id, to_account_id, amount, type, description, created_by, created_at) VALUES (?, ?, ?, 'transfer', ?, ?, NOW())")
                    ->execute([$fiscalYearId, $id, $amount, $desc, $userId]);
            }

            $pdo->commit();
            echo json_encode(['status'=>'success', 'message'=>'تراکنش با موفقیت ثبت و موجودی حساب بروزرسانی شد.']);
        }
        else {
            throw new Exception('عملیات نامعتبر است.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]);
    }
    exit;
}

// --- دریافت لیست حساب‌ها ---
$accounts = $pdo->query("
    SELECT a.*, u.first_name, u.last_name 
    FROM fin_accounts a 
    LEFT JOIN users u ON a.user_id = u.id 
    ORDER BY CASE a.type WHEN 'bank' THEN 1 WHEN 'safe' THEN 2 WHEN 'petty_cash' THEN 3 END, a.status ASC, a.title ASC
")->fetchAll(PDO::FETCH_ASSOC);

$usersList = $pdo->query("SELECT id, first_name, last_name, role FROM users WHERE status='active' ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'تعریف حساب‌ها و تنظیم موجودی';
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    .panel-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
    
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .styled-table { width: 100%; border-collapse: collapse; min-width: 950px; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; color: #475569; font-weight: 900; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 0.95rem; vertical-align: middle; }
    .styled-table tr:hover td { background: #f8fafc; }
    
    .type-badge { padding: 6px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: bold; display: inline-block; text-align: center; }
    .t-bank { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .t-safe { background: #fdf4ff; color: #be185d; border: 1px solid #fbcfe8; }
    .t-petty { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
    
    .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: bold; display: inline-block; }
    .s-active { background: #dcfce7; color: #166534; }
    .s-inactive { background: #fee2e2; color: #991b1b; }
    
    .action-group { display: flex; gap: 6px; flex-wrap: nowrap; align-items: center; }
    .action-btn { font-family: inherit; padding: 6px 12px; font-size: 0.8rem; font-weight: bold; border-radius: 6px; cursor: pointer; border: 1px solid transparent; display:inline-flex; align-items:center; justify-content:center; gap:5px; white-space: nowrap;}
    .btn-edit { background: #fef3c7; color: #b45309; border-color: #fde68a; } .btn-edit:hover { background: #fde68a; }
    
    /* دکمه‌های واریز و برداشت */
    .btn-deposit { background: #dcfce7; color: #166534; border-color: #bbf7d0; } .btn-deposit:hover { background: #bbf7d0; }
    .btn-withdraw { background: #fee2e2; color: #991b1b; border-color: #fecaca; } .btn-withdraw:hover { background: #fecaca; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 10px; backdrop-filter: blur(4px); }
    .modal-box { background: #fff; width: 100%; max-width: 500px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
    .modal-header { padding: 20px 25px; background: #f8fafc; font-weight: 900; border-bottom: 1px solid #e2e8f0; display:flex; justify-content: space-between; }
    .modal-body { padding: 25px; }
    .modal-footer { padding: 20px 25px; border-top: 1px solid #e2e8f0; background: #f8fafc; display:flex; justify-content: flex-end; gap:12px;}

    @media (max-width: 768px) { .container-centered { padding: 0 10px; } .modal-footer { flex-direction: column; } .modal-footer button { width: 100%; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';

function getAccTypeLabel($type) {
    if($type == 'bank') return ['حساب بانکی (شرکت)', 't-bank', '🏦'];
    if($type == 'safe') return ['صندوق / گاوصندوق', 't-safe', '🗄️'];
    if($type == 'petty_cash') return ['تنخواه کارمند', 't-petty', '👤'];
    return ['ناشناخته', '', ''];
}
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            
            <div class="page-header" style="margin-bottom: 25px; display: flex; justify-content: space-between; flex-wrap: wrap; gap:15px;">
                <div><span class="page-title">🏦 مدیریت حساب‌ها و تنخواه‌داران</span></div>
                <div>
                    <button onclick="openModal()" class="btn btn-primary" style="font-weight:bold; font-family: inherit; box-shadow:0 4px 6px rgba(37,99,235,0.2);">➕ افزودن حساب جدید</button>
                </div>
            </div>

            <div class="panel-card">
                <?php if(empty($accounts)): ?>
                    <div style="text-align:center; padding:40px; color:#94a3b8; font-weight:bold;">هیچ حسابی در سیستم تعریف نشده است.</div>
                <?php else: ?>
                    <div style="text-align:center; font-size:0.8rem; color:#64748b; margin-bottom:10px; display:none;" class="mobile-hint">👈 برای مشاهده کامل به چپ و راست بکشید 👉</div>
                    <div class="table-responsive">
                        <table class="styled-table">
                            <thead>
                                <tr>
                                    <th>ردیف</th>
                                    <th>نوع حساب</th>
                                    <th>عنوان حساب</th>
                                    <th>تنخواه‌دار / شخص</th>
                                    <th>موجودی فعلی (تومان)</th>
                                    <th>وضعیت</th>
                                    <th style="min-width: 250px;">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter=1; foreach($accounts as $acc): list($tLabel, $tClass, $tIcon) = getAccTypeLabel($acc['type']); ?>
                                <tr>
                                    <td style="font-weight:bold; color:#94a3b8;"><?php echo $counter++; ?></td>
                                    <td><span class="type-badge <?php echo $tClass; ?>"><?php echo $tIcon . ' ' . $tLabel; ?></span></td>
                                    <td style="font-weight:bold; color:#1e293b;"><?php echo htmlspecialchars($acc['title']); ?></td>
                                    <td>
                                        <?php if($acc['type'] == 'petty_cash' && $acc['user_id']): ?>
                                            <span style="background:#f1f5f9; padding:4px 8px; border-radius:6px; font-size:0.85rem; font-weight:bold;">👤 <?php echo htmlspecialchars($acc['first_name'].' '.$acc['last_name']); ?></span>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1; font-size:0.8rem;">متعلق به شرکت</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight:900; color:<?php echo $acc['balance'] < 0 ? '#ef4444' : '#10b981'; ?>; direction:ltr; text-align:right; font-size:1.1rem;">
                                        <?php echo number_format($acc['balance']); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $acc['status'] == 'active' ? 's-active' : 's-inactive'; ?>">
                                            <?php echo $acc['status'] == 'active' ? '🟢 فعال' : '🔴 مسدود'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <button type="button" class="action-btn btn-edit" 
                                                data-id="<?php echo $acc['id']; ?>" 
                                                data-title="<?php echo htmlspecialchars($acc['title']); ?>" 
                                                data-type="<?php echo $acc['type']; ?>" 
                                                data-user="<?php echo $acc['user_id']; ?>" 
                                                data-status="<?php echo $acc['status']; ?>" 
                                                onclick="editAccount(this)">✏️ ویرایش</button>
                                            
                                            <?php if($isAdminOrFinance && $acc['status'] == 'active'): ?>
                                                <button type="button" class="action-btn btn-deposit" onclick="openAdjustModal(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars(addslashes($acc['title'])); ?>', 'deposit')">➕ واریز</button>
                                                <button type="button" class="action-btn btn-withdraw" onclick="openAdjustModal(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars(addslashes($acc['title'])); ?>', 'withdraw')">➖ برداشت</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</main>

<div id="accModal" class="modal-overlay">
    <div class="modal-box">
        <form id="accForm" method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="acc_id">
            <div class="modal-header"><span id="modalTitle">➕ افزودن حساب جدید</span><span style="cursor:pointer; color:#ef4444; font-size:1.5rem; line-height:1;" onclick="closeModal('accModal')">✖</span></div>
            <div class="modal-body">
                <div id="modalMsg" style="display:none; margin-bottom:15px; padding:10px; border-radius:8px; font-weight:bold; font-size:0.85rem; text-align:center;"></div>
                
                <div class="form-group">
                    <label style="font-weight:bold; margin-bottom:5px; display:block;">نوع حساب <span style="color:red">*</span></label>
                    <select name="type" id="acc_type" class="form-control" required style="font-family: inherit;" onchange="toggleUserField()">
                        <option value="bank">🏦 حساب بانکی (متعلق به شرکت)</option>
                        <option value="safe">🗄️ صندوق / گاوصندوق (داخلی)</option>
                        <option value="petty_cash">👤 حساب تنخواه (متعلق به کارمند)</option>
                    </select>
                </div>
                
                <div class="form-group" style="margin-top:15px;" id="userFieldGroup">
                    <label style="font-weight:bold; margin-bottom:5px; display:block; color:#b45309;">شخص تنخواه‌دار <span style="color:red">*</span></label>
                    <select name="user_id" id="acc_user_id" class="form-control" style="font-family: inherit; border-color:#fde68a; background:#fffbeb;">
                        <option value="">-- انتخاب کارمند --</option>
                        <?php foreach($usersList as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?> (<?php echo htmlspecialchars($u['role']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-top:15px;">
                    <label style="font-weight:bold; margin-bottom:5px; display:block;">عنوان حساب <span style="color:red">*</span></label>
                    <input type="text" name="title" id="acc_title" class="form-control" required placeholder="مثال: بانک ملت مرکزی / تنخواه آقای محمدی" style="font-family: inherit;">
                </div>

                <div class="form-group" style="margin-top:15px;">
                    <label style="font-weight:bold; margin-bottom:5px; display:block;">وضعیت</label>
                    <select name="status" id="acc_status" class="form-control" style="font-family: inherit;">
                        <option value="active">🟢 فعال (قابل استفاده)</option>
                        <option value="inactive">🔴 غیرفعال (مسدود موقت)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" style="font-family: inherit;" onclick="closeModal('accModal')">انصراف</button>
                <button type="submit" class="btn btn-primary" id="btnSubmit" style="font-family: inherit;">💾 ذخیره اطلاعات</button>
            </div>
        </form>
    </div>
</div>

<div id="adjustModal" class="modal-overlay">
    <div class="modal-box">
        <form id="adjustForm" method="POST">
            <input type="hidden" name="action" value="adjust_balance">
            <input type="hidden" name="id" id="adj_acc_id">
            <input type="hidden" name="adj_type" id="adj_type">
            
            <div class="modal-header"><span id="adjModalTitle">تنظیم موجودی</span><span style="cursor:pointer; color:#ef4444; font-size:1.5rem; line-height:1;" onclick="closeModal('adjustModal')">✖</span></div>
            <div class="modal-body">
                <div id="adjModalMsg" style="display:none; margin-bottom:15px; padding:10px; border-radius:8px; font-weight:bold; font-size:0.85rem; text-align:center;"></div>
                
                <div class="form-group">
                    <label style="font-weight:bold; margin-bottom:5px; display:block;">مبلغ (تومان) <span style="color:red">*</span></label>
                    <input type="text" name="amount" class="form-control amount-input" required style="font-family: inherit; direction:ltr; text-align:right; font-size:1.2rem; font-weight:bold;">
                </div>
                
                <div class="form-group" style="margin-top:15px;">
                    <label style="font-weight:bold; margin-bottom:5px; display:block;">بابت / توضیحات سند <span style="color:red">*</span></label>
                    <textarea name="description" class="form-control" rows="3" required placeholder="علت این واریز یا برداشت را بنویسید (در دفتر کل ثبت می‌شود)" style="font-family: inherit; line-height:1.6;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" style="font-family: inherit;" onclick="closeModal('adjustModal')">انصراف</button>
                <button type="submit" class="btn btn-primary" id="btnAdjSubmit" style="font-family: inherit;">تایید و ثبت</button>
            </div>
        </form>
    </div>
</div>

<style>
    @media (max-width: 768px) { .mobile-hint { display: block !important; } }
</style>

<script>
    // تبدیل اعداد فارسی به انگلیسی برای مبالغ
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

    function toggleUserField() {
        const type = document.getElementById('acc_type').value;
        const userGroup = document.getElementById('userFieldGroup');
        const userSelect = document.getElementById('acc_user_id');
        if (type === 'petty_cash') {
            userGroup.style.display = 'block';
            userSelect.setAttribute('required', 'required');
        } else {
            userGroup.style.display = 'none';
            userSelect.removeAttribute('required');
            userSelect.value = '';
        }
    }

    function openModal() {
        document.getElementById('accForm').reset();
        document.getElementById('acc_id').value = '';
        document.getElementById('modalTitle').innerText = '➕ افزودن حساب جدید';
        document.getElementById('modalMsg').style.display = 'none';
        toggleUserField();
        document.getElementById('accModal').style.display = 'flex';
    }

    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    function editAccount(btn) {
        document.getElementById('acc_id').value = btn.getAttribute('data-id');
        document.getElementById('acc_title').value = btn.getAttribute('data-title');
        document.getElementById('acc_type').value = btn.getAttribute('data-type');
        document.getElementById('acc_status').value = btn.getAttribute('data-status');
        
        let uid = btn.getAttribute('data-user');
        if(uid) document.getElementById('acc_user_id').value = uid;
        else document.getElementById('acc_user_id').value = '';

        document.getElementById('modalTitle').innerText = '✏️ ویرایش حساب';
        document.getElementById('modalMsg').style.display = 'none';
        toggleUserField();
        document.getElementById('accModal').style.display = 'flex';
    }

    // باز کردن مودال واریز و برداشت
    function openAdjustModal(id, title, type) {
        document.getElementById('adjustForm').reset();
        document.getElementById('adj_acc_id').value = id;
        document.getElementById('adj_type').value = type;
        document.getElementById('adjModalMsg').style.display = 'none';

        const btnSubmit = document.getElementById('btnAdjSubmit');
        const modalTitle = document.getElementById('adjModalTitle');

        if (type === 'deposit') {
            modalTitle.innerHTML = '➕ واریز به حساب: <span style="color:#047857;">' + title + '</span>';
            btnSubmit.style.background = '#10b981';
            btnSubmit.style.borderColor = '#10b981';
            btnSubmit.innerText = '✔️ ثبت واریزی';
        } else {
            modalTitle.innerHTML = '➖ برداشت از حساب: <span style="color:#b91c1c;">' + title + '</span>';
            btnSubmit.style.background = '#ef4444';
            btnSubmit.style.borderColor = '#ef4444';
            btnSubmit.innerText = '❌ ثبت برداشت';
        }

        document.getElementById('adjustModal').style.display = 'flex';
    }

    // ارسال فرم ذخیره حساب
    document.getElementById('accForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSubmit');
        const msgBox = document.getElementById('modalMsg');
        btn.disabled = true; btn.innerText = 'در حال ذخیره...';
        
        const formData = new FormData(this);
        
        fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(res => res.json())
        .then(data => {
            msgBox.style.display = 'block';
            if(data.status === 'success') {
                msgBox.style.background = '#dcfce7'; msgBox.style.color = '#166534'; msgBox.style.border = '1px solid #bbf7d0';
                msgBox.innerText = data.message;
                setTimeout(() => location.reload(), 1000);
            } else {
                msgBox.style.background = '#fee2e2'; msgBox.style.color = '#991b1b'; msgBox.style.border = '1px solid #fecaca';
                msgBox.innerText = data.message;
                btn.disabled = false; btn.innerText = '💾 ذخیره اطلاعات';
            }
        }).catch(err => {
            alert('خطا در ارتباط با سرور');
            btn.disabled = false; btn.innerText = '💾 ذخیره اطلاعات';
        });
    });

    // ارسال فرم واریز و برداشت
    document.getElementById('adjustForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnAdjSubmit');
        const msgBox = document.getElementById('adjModalMsg');
        const originalText = btn.innerText;
        btn.disabled = true; btn.innerText = 'در حال پردازش...';
        
        const formData = new FormData(this);
        
        fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(res => res.json())
        .then(data => {
            msgBox.style.display = 'block';
            if(data.status === 'success') {
                msgBox.style.background = '#dcfce7'; msgBox.style.color = '#166534'; msgBox.style.border = '1px solid #bbf7d0';
                msgBox.innerText = data.message;
                setTimeout(() => location.reload(), 1000);
            } else {
                msgBox.style.background = '#fee2e2'; msgBox.style.color = '#991b1b'; msgBox.style.border = '1px solid #fecaca';
                msgBox.innerText = data.message;
                btn.disabled = false; btn.innerText = originalText;
            }
        }).catch(err => {
            alert('خطا در ارتباط با سرور');
            btn.disabled = false; btn.innerText = originalText;
        });
    });
    
    document.addEventListener("DOMContentLoaded", function() { toggleUserField(); });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>