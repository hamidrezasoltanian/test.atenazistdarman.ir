<?php
/*
 * فایل: public_html/admin/mission_view.php
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($requestId <= 0) die('شناسه نامعتبر است.');

try {
    $pdo->exec("ALTER TABLE `mission_requests` MODIFY COLUMN `status` ENUM('pending', 'pending_admin', 'approved', 'expenses_open', 'expenses_submitted', 'finance_process', 'finance_review', 'completed', 'rejected') DEFAULT 'pending'");
} catch (\Exception $e) {}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        $length = strlen($needle);
        if ($length === 0) return true;
        return (substr($haystack, -$length) === $needle);
    }
}

$isAdmin = in_array($userRole, ['admin', 'management'], true);
$isFinance = in_array($userRole, ['finance_expert', 'finance_manager'], true);
$isDeptManager = (!$isAdmin && !$isFinance && str_ends_with($userRole, '_manager'));

$stmt = $pdo->prepare("SELECT r.*, u.first_name, u.last_name, u.mobile FROM mission_requests r JOIN users u ON r.user_id = u.id WHERE r.id = ? LIMIT 1");
$stmt->execute([$requestId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$req) die('درخواست یافت نشد.');

// --- اصلاح دسترسی: واحد مالی به تمام پرونده‌ها دسترسی خواندن دارد ---
$canView = false;
if ($isAdmin || $isFinance) $canView = true;
elseif ($req['user_id'] == $userId) $canView = true;
elseif ($isDeptManager) {
    $stmtChk = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND manager_id = ? LIMIT 1");
    $stmtChk->execute([$req['department_id'], $userId]);
    if ($stmtChk->fetchColumn()) $canView = true;
}
if (!$canView) die('<div style="text-align:center; margin-top:50px; font-family:Tahoma; color:red; font-weight:bold;">⛔ شما دسترسی ندارید.</div>');

$reviewerIcon = '../assets/images/profile-icon.png';
try {
    $stmtRev = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
    $stmtRev->execute([$userId]);
    $revImg = $stmtRev->fetchColumn();
    if (!empty($revImg) && $revImg != 'default.png') {
        $reviewerIcon = '../uploads/profiles/' . $revImg;
    }
} catch (Exception $e) {}

$smsStatusSetting = '0';
try {
    $stmtSet = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sms_on_mission_status'");
    $smsStatusSetting = $stmtSet->fetchColumn();
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($action === 'reopen_request' && $isAdmin && $req['status'] === 'completed') {
        $pdo->prepare("UPDATE mission_requests SET status='finance_review' WHERE id=?")->execute([$requestId]);
        header("Location: mission_view.php?id=$requestId");
        exit;
    }

    if ($action === 'reject' && ($isAdmin || $isDeptManager)) {
        $stmt = $pdo->prepare("UPDATE mission_requests SET status='rejected', rejection_reason=? WHERE id=?");
        $stmt->execute([$reason ?: 'رد شد', $requestId]);

        if ($smsStatusSetting === '1' && !empty($req['mobile']) && function_exists('send_sms_pattern')) {
            $fullName = trim($req['first_name'] . ' ' . $req['last_name']);
            $sysConfig = @include __DIR__ . '/../../Config/config.php';
            $patternCode = $sysConfig['mission_pattern'] ?? '';
            if($patternCode) send_sms_pattern($req['mobile'], $patternCode, ['name' => $fullName, 'id' => (string)$requestId, 'status' => 'رد']);
        }

        if (function_exists('send_user_notification')) {
            send_user_notification($pdo, $req['user_id'], "❌ ماموریت رد شد", "درخواست ماموریت شما رد شد. دلیل: " . ($reason ?: 'ثبت نشده'), "admin/mission_view.php?id=$requestId", 'mission', $requestId, $reviewerIcon);
        }

        header("Location: mission_view.php?id=$requestId");
        exit;
    }

    if ($action === 'item_decision' && ($isAdmin || $isDeptManager)) {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $itemStatus = $_POST['item_status'] ?? '';
        $note = trim($_POST['item_note'] ?? '');
        if ($itemId > 0 && in_array($itemStatus, ['approved','rejected']) && in_array($req['status'], ['pending', 'pending_admin'])) {
            $stmt = $pdo->prepare("UPDATE mission_items SET status=?, manager_note=? WHERE id=? AND request_id=?");
            $stmt->execute([$itemStatus, $note ?: null, $itemId, $requestId]);
        }
        header("Location: mission_view.php?id=$requestId");
        exit;
    }

    if ($action === 'final_approve' && ($isAdmin || $isDeptManager)) {
        if (in_array($req['status'], ['pending', 'pending_admin'])) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM mission_items WHERE request_id=? AND status!='approved'");
            $cnt->execute([$requestId]);
            if ($cnt->fetchColumn() == 0) {
                $pdo->prepare("UPDATE mission_requests SET status='expenses_open' WHERE id=?")->execute([$requestId]);

                if ($smsStatusSetting === '1' && !empty($req['mobile']) && function_exists('send_sms_pattern')) {
                    $fullName = trim($req['first_name'] . ' ' . $req['last_name']);
                    $sysConfig = @include __DIR__ . '/../../Config/config.php';
                    $patternCode = $sysConfig['mission_pattern'] ?? '';
                    if($patternCode) send_sms_pattern($req['mobile'], $patternCode, ['name' => $fullName, 'id' => (string)$requestId, 'status' => 'تایید اولیه']);
                }

                if (function_exists('send_user_notification')) {
                    send_user_notification($pdo, $req['user_id'], "✅ ماموریت تایید شد", "ماموریت شما تایید شد. اکنون می‌توانید هزینه‌های آن را ثبت کنید.", "admin/mission_expenses.php?id=$requestId", 'mission', $requestId, $reviewerIcon);
                }
            }
        }
        header("Location: mission_view.php?id=$requestId");
        exit;
    }
}

$stmtItems = $pdo->prepare("SELECT i.*, t.title as type_title, c.company_name, c.company_code, c.type_name, c.manager_name, c.address, c.mobile, c.phone, c.state, c.city, (SELECT GROUP_CONCAT(full_name SEPARATOR '، ') FROM customer_followers WHERE company_num = c.company_num) AS followers_list FROM mission_items i LEFT JOIN mission_types t ON i.type_id = t.id LEFT JOIN customers c ON i.customer_id = c.id WHERE i.request_id = ? ORDER BY i.id ASC");
$stmtItems->execute([$requestId]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$stmtExp = $pdo->prepare("SELECT e.*, c.company_name, t.title as type_title FROM mission_expenses e LEFT JOIN mission_items i ON e.item_id = i.id LEFT JOIN customers c ON i.customer_id = c.id LEFT JOIN mission_types t ON i.type_id = t.id WHERE e.request_id = ? ORDER BY e.id ASC");
$stmtExp->execute([$requestId]);
$expenses = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

$sumStmt = $pdo->prepare("SELECT SUM(CASE WHEN status IN ('approved','paid') THEN COALESCE(approved_amount, amount) ELSE 0 END) as approved_sum, SUM(CASE WHEN status='rejected' THEN amount WHEN status IN ('approved','paid') THEN (amount - COALESCE(approved_amount, amount)) ELSE 0 END) as rejected_sum, SUM(CASE WHEN status='pending' THEN amount ELSE 0 END) as pending_sum, COUNT(*) as total_count FROM mission_expenses WHERE request_id = ?");
$sumStmt->execute([$requestId]);
$expenseSummary = $sumStmt->fetch(PDO::FETCH_ASSOC);

$notApprovedCount = 0;
$cnt = $pdo->prepare("SELECT COUNT(*) FROM mission_items WHERE request_id=? AND status!='approved'");
$cnt->execute([$requestId]);
$notApprovedCount = (int)$cnt->fetchColumn();

// همان signature با missions.php (role-aware)
function mission_status_label($status, $role = '') {
    $isPriv = in_array($role, ['admin','management','finance_expert','finance_manager'], true);
    $map = $isPriv ? [
        'pending'            => 'در انتظار تأیید مدیر',
        'pending_admin'      => 'در انتظار تأیید مدیریت کل',
        'approved'           => 'تأیید شده',
        'expenses_open'      => 'ثبت هزینه توسط کارمند',
        'expenses_submitted' => 'ارسال هزینه به مالی',
        'finance_process'    => 'در بررسی مالی',
        'finance_review'     => 'در بررسی مالی',
        'completed'          => 'تسویه شده',
        'rejected'           => 'رد شده',
    ] : [
        'pending'            => 'در انتظار تأیید',
        'pending_admin'      => 'در انتظار تأیید',
        'approved'           => 'تأیید شده — ثبت هزینه',
        'expenses_open'      => 'در حال ثبت هزینه',
        'expenses_submitted' => 'ارسال شده به مالی',
        'finance_process'    => 'بررسی مالی',
        'finance_review'     => 'بررسی مالی',
        'completed'          => 'تسویه شده',
        'rejected'           => 'رد شده',
    ];
    return $map[$status] ?? $status;
}

// نمایش stepper پیشرفت ماموریت
function mission_stepper_html($status) {
    $steps = [
        ['key'=>'pending',            'label'=>'ثبت درخواست',      'icon'=>'📝'],
        ['key'=>'approved',           'label'=>'تأیید مدیر',        'icon'=>'✅'],
        ['key'=>'expenses_open',      'label'=>'ثبت هزینه‌ها',     'icon'=>'📋'],
        ['key'=>'expenses_submitted', 'label'=>'ارسال به مالی',     'icon'=>'📤'],
        ['key'=>'finance_review',     'label'=>'بررسی مالی',        'icon'=>'💼'],
        ['key'=>'completed',          'label'=>'تسویه',             'icon'=>'🏁'],
    ];
    $active = ['pending_admin'=>1,'pending'=>0,'approved'=>1,'expenses_open'=>2,'expenses_submitted'=>3,'finance_process'=>4,'finance_review'=>4,'completed'=>5,'rejected'=>-1];
    $curIdx = $active[$status] ?? 0;
    $isRej  = $status === 'rejected';

    $html = '<div style="display:flex;align-items:flex-start;justify-content:center;gap:0;margin:16px 0 8px;overflow-x:auto;padding-bottom:4px;">';
    foreach ($steps as $i => $s) {
        $done   = !$isRej && $i < $curIdx;
        $isCur  = !$isRej && $i === $curIdx;
        $cirClr = $done ? '#10b981' : ($isCur ? '#3b82f6' : '#e2e8f0');
        $txtClr = $done ? '#059669' : ($isCur ? '#1d4ed8' : '#94a3b8');
        $html .= '<div style="display:flex;flex-direction:column;align-items:center;min-width:80px;">';
        $html .= "<div style=\"width:36px;height:36px;border-radius:50%;background:{$cirClr};display:flex;align-items:center;justify-content:center;font-size:.9rem;box-shadow:" . ($isCur ? '0 0 0 4px #bfdbfe' : 'none') . ";\">" . ($done ? '✓' : $s['icon']) . "</div>";
        $html .= "<div style=\"font-size:.65rem;color:{$txtClr};margin-top:6px;text-align:center;line-height:1.3;\">" . $s['label'] . "</div>";
        $html .= '</div>';
        if ($i < count($steps)-1) {
            $lineClr = $done ? '#10b981' : '#e2e8f0';
            $html .= "<div style=\"flex:1;height:2px;background:{$lineClr};margin-top:17px;min-width:20px;\"></div>";
        }
    }
    if ($isRej) {
        $html .= '<div style="display:flex;flex-direction:column;align-items:center;min-width:80px;">';
        $html .= '<div style="width:36px;height:36px;border-radius:50%;background:#ef4444;display:flex;align-items:center;justify-content:center;font-size:.9rem;">✖</div>';
        $html .= '<div style="font-size:.65rem;color:#ef4444;margin-top:6px;text-align:center;">رد شده</div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}
function translate_item_status($status) {
    $map = ['pending' => 'در انتظار', 'approved' => 'تایید شده', 'rejected' => 'رد شده'];
    return $map[$status] ?? $status;
}
function translate_expense_type($type) {
    $map = ['personal' => 'هزینه شخصی', 'org_snap' => 'اسنپ سازمانی', 'discount_code' => 'کد تخفیف'];
    return $map[$type] ?? $type;
}
function translate_journey_type($type) {
    $map = ['outbound' => 'رفت', 'return' => 'برگشت', 'other' => 'سایر'];
    return $map[$type] ?? 'سایر';
}
function translate_expense_status($status) {
    $map = ['pending' => 'در انتظار بررسی', 'approved' => 'تایید شده', 'paid' => 'پرداخت شده', 'rejected' => 'رد شده'];
    return $map[$status] ?? $status;
}

$pageTitle = "جزئیات ماموریت #$requestId";
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1300px; margin: 0 auto; padding: 0 20px; }
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:16px; }
    .ops-box { background: #fff; padding: 15px 20px; border-radius: 12px; border: 1px solid #cbd5e1; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; box-shadow: 0 4px 10px rgba(0,0,0,0.03); }
    .ops-title { flex: 1; font-weight: bold; color: #1e293b; }
    .ops-btn-group { display: flex; gap: 10px; }
    .kv { display:flex; gap:8px; flex-wrap:wrap; }
    .kv div { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:8px 10px; font-size:0.85rem; }
    .status-badge { padding:4px 10px; border-radius:999px; font-size:0.75rem; display:inline-block; border:1px solid #e5e7eb; font-weight:bold; }
    .st-pending_admin, .st-pending { background:#fffbeb; color:#b45309; border-color:#fde68a; }
    .st-expenses_open, .st-approved { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; }
    .st-finance_review { background:#faf5ff; color:#7c3aed; border-color:#e9d5ff; }
    .st-completed { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .st-rejected { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
    .st-paid { background:#e0e7ff; color:#1d4ed8; border-color:#bfdbfe; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:right; padding:12px 10px; border-bottom:1px solid #f1f5f9; font-size:0.85rem; vertical-align: middle; }
    th { color:#6b7280; font-weight:600; background-color: #f8fafc; }
    .col-desc { width: 25%; min-width: 200px; white-space: normal; line-height: 1.6; }
    .col-action { width: 30%; min-width: 320px; }
    .action-form { display: flex; gap: 6px; align-items: center; width: 100%; }
    .action-form select { flex: 0 0 90px; }
    .action-form input { flex: 1; min-width: 0; }
    .form-select, .form-control { border: 1px solid #d1d5db; border-radius: 6px; padding: 6px; font-size: 0.8rem; font-family: inherit; }
    @media (max-width: 992px) {
        .container-centered { padding: 0 10px; }
        .ops-box { flex-direction: column; align-items: stretch; text-align:center;}
        table, thead, tbody, th, td, tr { display:block; }
        thead { display:none; }
        tr { border:1px solid #e5e7eb; border-radius:10px; padding:15px; margin-bottom:15px; background:#fff; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        td { border:none; padding:8px 0; border-bottom: 1px solid #f1f5f9; }
        td:last-child { border-bottom: none; }
        td::before { content: attr(data-label) " : "; color:#6b7280; font-weight:600; display: inline-block; margin-left: 5px; }
        .col-desc { width: 100%; display: block; background: #f9fafb; padding: 10px; border-radius: 8px; }
        .col-action { width: 100%; margin-top: 10px; }
        .action-form { flex-direction: column; align-items: stretch; }
        .action-form select, .action-form input, .action-form button { width: 100%; margin-bottom: 5px; height: 35px; flex: none; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            
            <div class="page-header page-actions">
                <div><span class="page-title">جزئیات ماموریت #<?php echo $requestId; ?></span></div>
                <div><a href="missions.php" class="btn btn-secondary">بازگشت</a></div>
            </div>

            <!-- ── Stepper پیشرفت ماموریت ── -->
            <div style="background:#fff;border-radius:14px;padding:18px 24px;box-shadow:0 1px 8px rgba(0,0,0,.05);margin-bottom:18px;border:1px solid #e5e7eb;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="font-size:.85rem;font-weight:700;color:#1e293b;">مرحله جاری ماموریت</span>
                    <span class="status-badge st-<?php echo $req['status']; ?>" style="padding:5px 14px;border-radius:20px;font-size:.8rem;font-weight:700;">
                        <?php echo mission_status_label($req['status']); ?>
                    </span>
                </div>
                <?php echo mission_stepper_html($req['status']); ?>
            </div>

            <?php /* page-header div was closed above, removing extra closing div */ ?>
            </div>

            <?php if ($req['status'] === 'completed' && $isAdmin): ?>
                <div class="ops-box">
                    <div class="ops-title">🛠️ عملیات مدیریت کل:</div>
                    <div class="ops-btn-group">
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="reopen_request">
                            <button type="submit" class="btn btn-warning" style="font-weight:900; color:#000;" onclick="return confirm('آیا از بازگشایی پرونده جهت ویرایش مالی اطمینان دارید؟')">🔓 بازگشایی پرونده مختومه</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card-box">
                <div class="kv">
                    <div>کارمند: <strong><?php echo htmlspecialchars(trim($req['first_name'].' '.$req['last_name'])); ?></strong></div>
                    <div>وضعیت: <span class="status-badge st-<?php echo $req['status']; ?>"><?php echo mission_status_label($req['status']); ?></span></div>
                    <div>تاریخ ثبت: <span dir="ltr"><?php echo function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($req['created_at'])) : $req['created_at']; ?></span></div>
                </div>
                <?php if ($req['status'] === 'rejected' && $req['rejection_reason']): ?>
                    <div class="alert alert-danger mt-3">
                        <strong>دلیل رد:</strong> <?php echo htmlspecialchars($req['rejection_reason']); ?>
                        <?php if($req['user_id'] == $userId): ?>
                            <div class="mt-2">
                                <a href="mission_edit.php?id=<?php echo $requestId; ?>" class="btn btn-primary">ویرایش و ارسال مجدد</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card-box">
                <div class="page-title" style="margin-bottom:10px;">آیتم‌های ماموریت</div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>نوع</th>
                                <th>طرف حساب</th>
                                <th>تاریخ / زمان</th>
                                <th class="col-desc">توضیحات کاربر</th>
                                <th>وضعیت</th>
                                <th class="col-action">اقدام مدیریت</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td data-label="نوع"><?php echo htmlspecialchars($it['type_title'] ?? '-'); ?></td>
                                <td data-label="طرف حساب">
                                    <span class="text-primary fw-bold"><?php echo htmlspecialchars($it['company_name'] ?? 'آزاد'); ?></span>
                                    <div style="font-size:0.75rem; color:#4b5563; margin-top:2px;">
                                        موبایل: <span dir="ltr"><?php echo htmlspecialchars($it['mobile'] ?? '-'); ?></span>
                                    </div>
                                </td>
                                <td data-label="تاریخ / زمان">
                                    <div dir="ltr"><?php echo function_exists('jdate') ? jdate('Y/m/d', strtotime($it['mission_date'])) : $it['mission_date']; ?></div>
                                    <div class="small text-muted" dir="ltr"><?php echo htmlspecialchars($it['start_time']); ?> - <?php echo htmlspecialchars($it['end_time']); ?></div>
                                </td>
                                <td data-label="توضیحات کاربر" class="col-desc"><?php echo nl2br(htmlspecialchars($it['description'] ?? '-')); ?></td>
                                <td data-label="وضعیت">
                                    <span class="status-badge st-<?php echo $it['status']; ?>"><?php echo translate_item_status($it['status']); ?></span>
                                </td>
                                <td data-label="اقدام مدیریت" class="col-action">
                                    <?php if ($isAdmin || $isDeptManager): ?>
                                        <?php if (in_array($req['status'], ['pending', 'pending_admin'])): ?>
                                            <form method="POST" class="action-form">
                                                <input type="hidden" name="action" value="item_decision">
                                                <input type="hidden" name="item_id" value="<?php echo (int)$it['id']; ?>">
                                                <select name="item_status" class="form-select">
                                                    <option value="approved" <?php echo $it['status'] === 'approved' ? 'selected' : ''; ?>>تایید</option>
                                                    <option value="rejected" <?php echo $it['status'] === 'rejected' ? 'selected' : ''; ?>>رد</option>
                                                </select>
                                                <input type="text" name="item_note" class="form-control" placeholder="یادداشت مدیریت" value="<?php echo htmlspecialchars($it['manager_note'] ?? ''); ?>">
                                                <button class="btn btn-primary btn-sm" type="submit">ثبت</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="text-muted small"><i class="fas fa-lock"></i> نهایی شده</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (!empty($expenses)): ?>
            <div class="card-box">
                <div class="page-title" style="margin-bottom:10px;">هزینه‌های ثبت شده</div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>نوع/مسیر</th>
                                <th>درخواستی (ریال)</th>
                                <th>تاییدی (ریال)</th>
                                <th>وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($expenses as $e): ?>
                            <tr>
                                <td data-label="نوع/مسیر"><?php echo translate_expense_type($e['expense_type']); ?> <br> <span class="small text-muted">مسیر: <?php echo translate_journey_type($e['journey_type'] ?? ''); ?></span></td>
                                <td data-label="درخواستی" class="fw-bold"><?php echo number_format($e['amount']); ?></td>
                                <td data-label="تاییدی" class="text-success fw-bold"><?php echo $e['approved_amount'] !== null ? number_format($e['approved_amount']) : '-'; ?></td>
                                <td data-label="وضعیت"><span class="status-badge st-<?php echo $e['status']; ?>"><?php echo translate_expense_status($e['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (($isAdmin || $isDeptManager) && in_array($req['status'], ['pending', 'pending_admin'])): ?>
            <div class="card-box">
                <div class="page-title" style="margin-bottom:10px;">اقدامات نهایی</div>
                <form method="POST">
                    <input type="hidden" name="action" value="">
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <?php if ($notApprovedCount === 0): ?>
                            <button type="submit" class="btn btn-success" onclick="this.form.elements['action'].value='final_approve'">تایید کلی و ارجاع برای ثبت هزینه</button>
                        <?php else: ?>
                            <div class="alert alert-warning small py-2 px-3 m-0">برای تایید کلی، ابتدا وضعیت تمام آیتم‌ها را مشخص کنید.</div>
                        <?php endif; ?>
                        
                        <div style="display:flex; gap:5px; margin-right:auto;">
                            <input type="text" name="reason" class="form-control" placeholder="دلیل رد (اختیاری)" style="max-width:250px;">
                            <button type="submit" class="btn btn-danger" onclick="this.form.elements['action'].value='reject'">رد کل درخواست</button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            
        </div>
    </div>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>