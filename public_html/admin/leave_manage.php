<?php
/*
 * فایل: public_html/admin/leave_manage.php
 */

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { ob_clean(); header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است.']); exit; }
    header("Location: ../login.php"); exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'user';
$isAdmin = in_array($userRole, ['admin', 'management']);

$stmtMgr = $pdo->prepare("SELECT id FROM departments WHERE manager_id = ?");
$stmtMgr->execute([$userId]);
$managedDeptIds = $stmtMgr->fetchAll(PDO::FETCH_COLUMN);
$isDeptManager = count($managedDeptIds) > 0;

if (!$isAdmin && !$isDeptManager) {
    if ($isAjax) { echo json_encode(['status' => 'error', 'message' => 'شما مدیر هیچ دپارتمانی نیستید.']); exit; }
    die('<div style="text-align:center; margin-top:50px; font-family:tahoma; color:red; font-size:1.2rem; font-weight:bold;">⛔ دسترسی غیرمجاز. شما در سیستم به عنوان مدیر دپارتمان ثبت نشده‌اید.</div>');
}

try {
    $pdo->exec("ALTER TABLE `leave_requests` MODIFY COLUMN `status` ENUM('pending_manager', 'pending_admin', 'approved', 'rejected_manager', 'rejected_admin', 'pending', 'rejected') DEFAULT 'pending_manager'");
} catch (\Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && isset($_POST['action']) && $_POST['action'] === 'review_request') {
    ob_clean(); header('Content-Type: application/json');
    try {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status' => 'error', 'message' => 'خطای امنیتی. صفحه را رفرش کنید.']); exit;
        }

        $reqId = (int)$_POST['request_id'];
        $reviewStatus = $_POST['status']; 
        $note = trim($_POST['manager_note'] ?? '');

        if (!in_array($reviewStatus, ['approved', 'rejected'])) {
            echo json_encode(['status' => 'error', 'message' => 'وضعیت نامعتبر است.']); exit;
        }
        
        $stmtFetch = $pdo->prepare("SELECT l.*, u.first_name, u.last_name, u.profile_image, u.mobile, u.department_id FROM leave_requests l JOIN users u ON l.user_id = u.id WHERE l.id = ?");
        $stmtFetch->execute([$reqId]);
        $req = $stmtFetch->fetch(PDO::FETCH_ASSOC);

        if (!$req) { echo json_encode(['status' => 'error', 'message' => 'درخواست یافت نشد.']); exit; }

        $pdo->beginTransaction();

        $newStatus = '';
        $msgResult = '';
        $currentStatus = $req['status'];

        $actingAs = 'admin';
        if (in_array($currentStatus, ['pending_manager', 'pending', ''])) {
            $actingAs = 'manager';
        }

        $stmtRev = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
        $stmtRev->execute([$userId]);
        $revImg = $stmtRev->fetchColumn();
        $reviewerName = $_SESSION['fullname'] ?? 'مدیریت';
        $reviewerIcon = (!empty($revImg) && $revImg != 'default.png') ? '../uploads/profiles/' . $revImg : '../assets/images/profile-icon.png';
        
        $empName = $req['first_name'] . ' ' . $req['last_name'];
        $empIcon = (!empty($req['profile_image']) && $req['profile_image'] != 'default.png') ? '../uploads/profiles/' . $req['profile_image'] : '../assets/images/profile-icon.png';

        // ------------------ منطق مدیر دپارتمان ------------------
        if ($actingAs === 'manager') {
            if (!in_array($req['department_id'], $managedDeptIds)) {
                echo json_encode(['status' => 'error', 'message' => 'این کارمند در دپارتمان شما نیست.']); exit;
            }

            if ($reviewStatus === 'approved') {
                $newStatus = 'pending_admin';
                $msgResult = 'درخواست تایید اولیه شد و به صندوق مدیریت کل ارجاع گردید.';
                $pdo->prepare("UPDATE leave_requests SET status = ?, manager_id = ?, manager_note = ? WHERE id = ?")->execute([$newStatus, $userId, ($note ?: 'تایید مدیر دپارتمان'), $reqId]);
                
                if (function_exists('send_user_notification')) {
                    $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin','management')")->fetchAll(PDO::FETCH_COLUMN);
                    foreach($admins as $admId) send_user_notification($pdo, $admId, "بررسی مرخصی در کارتابل مدیریت", "درخواست مرخصی {$empName} توسط مدیر دپارتمان ({$reviewerName}) تایید و به کارتابل شما ارجاع شد.", "admin/leave_manage.php", 'leave', $reqId, $empIcon);
                }
            } else {
                $newStatus = 'rejected_manager';
                $msgResult = 'درخواست رد شد و به آرشیو و کارتابل کارمند منتقل گردید.';
                $pdo->prepare("UPDATE leave_requests SET status = ?, manager_id = ?, manager_note = ? WHERE id = ?")->execute([$newStatus, $userId, $note, $reqId]);
                
                if (function_exists('send_user_notification')) send_user_notification($pdo, $req['user_id'], "رد درخواست مرخصی", "درخواست مرخصی شما توسط مدیر دپارتمان ({$reviewerName}) رد شد.", "admin/leave_requests.php", 'leave_result', $reqId, $reviewerIcon);
            }
        } 
        // ------------------ منطق مدیریت کل (Admin) ------------------
        else {
            if (!$isAdmin) { echo json_encode(['status' => 'error', 'message' => 'شما دسترسی تایید نهایی را ندارید.']); exit; }

            if ($reviewStatus === 'approved') {
                $newStatus = 'approved';
                $msgResult = 'درخواست تایید نهایی شد و در پرونده پرسنل اعمال گردید.';
                $pdo->prepare("UPDATE leave_requests SET status = ?, admin_id = ?, admin_note = ? WHERE id = ?")->execute([$newStatus, $userId, ($note ?: 'تایید نهایی مدیریت کل'), $reqId]);
            } else {
                $newStatus = 'rejected_admin';
                $msgResult = 'درخواست توسط مدیریت کل رد شد و به آرشیو منتقل گردید.';
                $pdo->prepare("UPDATE leave_requests SET status = ?, admin_id = ?, admin_note = ? WHERE id = ?")->execute([$newStatus, $userId, $note, $reqId]);
            }
            
            if (function_exists('send_user_notification')) {
                send_user_notification($pdo, $req['user_id'], "نتیجه نهایی مرخصی", "درخواست مرخصی شما توسط مدیریت کل ({$reviewerName}) " . ($reviewStatus=='approved'?'تایید':'رد') . " شد.", "admin/leave_requests.php", 'leave_result', $reqId, $reviewerIcon);
            }
        }

        // --- ارسال پیامک نتیجه به کارمند (در صورت فعال بودن) ---
        $settingStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sms_leave_status'");
        if ($settingStmt->fetchColumn() === '1' && !empty($req['mobile']) && function_exists('send_sms_pattern')) {
            $config = @include __DIR__ . '/../../Config/config.php';
            $patternCode = $config['leave_result_pattern'] ?? 'Kii4C4nluL';
            
            $typeNamesFa = ['daily_entitled'=>'استحقاقی','daily_sick'=>'استعلاجی','daily_unpaid'=>'بدون حقوق','hourly'=>'ساعتی'];
            $typeNameFa = $typeNamesFa[$req['leave_type']] ?? 'مرخصی';
            $statusFa = ($reviewStatus == 'approved') ? 'تایید' : 'رد';

            send_sms_pattern($req['mobile'], $patternCode, [
                'type' => $typeNameFa,
                'status' => $statusFa
            ]);
        }

        if(function_exists('logSystem')) logSystem('Leave', 'review', $reqId, "بررسی و تغییر وضعیت به: $newStatus");
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => $msgResult]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'خطای سیستمی: ' . $e->getMessage()]);
    }
    exit;
}

$limit = 10; 
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$filterStatus = $_GET['status'] ?? 'pending';
$filterUser = $_GET['user_id'] ?? '';

$where = "1=1";
$params = [];

if ($filterStatus === 'pending') {
    $pendingConds = [];
    if ($isAdmin) {
        $pendingConds[] = "l.status = 'pending_admin'";
    }
    if ($isDeptManager) {
        $deptPlaceholders = implode(',', array_fill(0, count($managedDeptIds), '?'));
        $pendingConds[] = "(l.status IN ('pending_manager', 'pending', '') AND u.department_id IN ($deptPlaceholders))";
        foreach ($managedDeptIds as $did) $params[] = $did;
    }
    if (empty($pendingConds)) {
        $where .= " AND 1=0"; 
    } else {
        $where .= " AND (" . implode(" OR ", $pendingConds) . ")";
    }
} 
elseif ($filterStatus === 'archive') {
    $where .= " AND l.status NOT IN ('pending_manager', 'pending_admin', 'pending', '')";
    if (!$isAdmin) {
        $deptPlaceholders = implode(',', array_fill(0, count($managedDeptIds), '?'));
        $where .= " AND u.department_id IN ($deptPlaceholders)";
        foreach ($managedDeptIds as $did) $params[] = $did;
    }
} 
elseif ($filterStatus === 'all') {
    if (!$isAdmin) {
        $deptPlaceholders = implode(',', array_fill(0, count($managedDeptIds), '?'));
        $where .= " AND u.department_id IN ($deptPlaceholders)";
        foreach ($managedDeptIds as $did) $params[] = $did;
    }
}

if (!empty($filterUser)) {
    $where .= " AND l.user_id = ?";
    $params[] = $filterUser;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests l JOIN users u ON l.user_id = u.id WHERE $where");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$sql = "SELECT l.*, u.first_name, u.last_name, u.personnel_code, u.department_id 
        FROM leave_requests l JOIN users u ON l.user_id = u.id 
        WHERE $where ORDER BY l.created_at ASC LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($sql);
$bindIndex = 1;
foreach ($params as $p) $stmt->bindValue($bindIndex++, $p);
$stmt->bindValue($bindIndex++, $limit, PDO::PARAM_INT);
$stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allUsersQuery = "SELECT id, first_name, last_name FROM users WHERE status = 'active'";
if (!$isAdmin) {
    $allUsersQuery .= " AND department_id IN (" . implode(',', $managedDeptIds) . ")";
}
$allUsers = $pdo->query($allUsersQuery)->fetchAll(PDO::FETCH_ASSOC);

$typeLabels = [
    'daily_entitled' => '<span style="background:#e0f2fe; color:#0369a1; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold; white-space:nowrap;">روزانه استحقاقی</span>',
    'daily_sick'     => '<span style="background:#fee2e2; color:#991b1b; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold; white-space:nowrap;">روزانه استعلاجی</span>',
    'daily_unpaid'   => '<span style="background:#f1f5f9; color:#475569; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold; white-space:nowrap;">بدون حقوق</span>',
    'hourly'         => '<span style="background:#e0e7ff; color:#3730a3; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold; white-space:nowrap;">ساعتی</span>'
];

$statusLabels = [
    'pending_manager' => '<span style="background:#fef08a; color:#854d0e; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:bold; white-space:nowrap;">⏳ بررسی دپارتمان</span>',
    'pending_admin'   => '<span style="background:#bfdbfe; color:#1e3a8a; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:bold; white-space:nowrap;">⏳ بررسی مدیریت کل</span>',
    'approved'        => '<span style="background:#dcfce7; color:#166534; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:bold; white-space:nowrap;">✅ تایید نهایی</span>',
    'rejected_manager'=> '<span style="background:#fecaca; color:#991b1b; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:bold; white-space:nowrap;">❌ رد دپارتمان</span>',
    'rejected_admin'  => '<span style="background:#fca5a5; color:#7f1d1d; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:bold; white-space:nowrap;">❌ رد مدیریت کل</span>'
];

$pageTitle = 'بررسی درخواست‌های مرخصی';
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    .panel-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
    
    .filter-box { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 20px; margin-bottom: 25px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
    .filter-group { flex: 1; min-width: 200px; }
    
    .table-scroll-hint { display: none; text-align: center; font-size: 0.8rem; color: #64748b; background: #f8fafc; padding: 8px; border-radius: 8px; margin-bottom: 10px; border: 1px dashed #cbd5e1; }
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .styled-table { width: 100%; border-collapse: collapse; min-width: 950px; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; color: #475569; font-weight: 900; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 0.95rem; vertical-align: middle; }
    .styled-table tr:hover td { background: #f8fafc; }
    
    .btn-action-primary { font-family: inherit; padding: 6px 15px; font-size: 0.85rem; font-weight: bold; border-radius: 6px; cursor: pointer; border: 1px solid transparent; background: #2563eb; color: #fff; white-space: nowrap; transition: 0.2s; text-align: center;}
    .btn-action-primary:hover { background: #1d4ed8; }
    .btn-action-disabled { font-family: inherit; padding: 6px 15px; font-size: 0.85rem; font-weight: bold; border-radius: 6px; border: 1px solid #e2e8f0; background: #f1f5f9; color: #94a3b8; white-space: nowrap; cursor: not-allowed; text-align: center;}

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 10px; backdrop-filter: blur(4px); }
    .modal-box { background: #fff; width: 100%; max-width: 600px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
    .modal-header { padding: 20px 25px; background: #f8fafc; font-weight: 900; border-bottom: 1px solid #e2e8f0; display:flex; justify-content: space-between; align-items:center; }
    .modal-body { padding: 25px; max-height: 75vh; overflow-y: auto; }
    
    .modal-actions-wrapper { display: flex; gap: 15px; }
    .btn-modal-action {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        flex: 1;
        border-radius: 10px;
        font-weight: bold;
        padding: 12px;
        font-size: 1rem;
        font-family: inherit;
        border: none;
        cursor: pointer;
        text-align: center;
        transition: 0.2s;
    }
    .btn-modal-success { background: #16a34a; color: #fff; }
    .btn-modal-success:hover { background: #15803d; }
    .btn-modal-danger { background: #dc2626; color: #fff; }
    .btn-modal-danger:hover { background: #b91c1c; }

    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; margin-top: 25px; padding-top: 20px; border-top: 1px dashed #e2e8f0; }
    .page-link { font-family: inherit; display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; border-radius: 8px; background: #fff; border: 1px solid #cbd5e1; color: #475569; font-weight: bold; text-decoration: none; transition: 0.2s; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }

    @media(max-width: 768px){ 
        .container-centered { padding: 0 10px; } 
        .table-scroll-hint { display: block; }
        .filter-box { flex-direction: column; align-items: stretch; }
        .filter-group { width: 100%; }
        .modal-actions-wrapper { flex-direction: column; } 
        .btn-modal-action { width: 100%; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            
            <div class="page-header" style="margin-bottom: 25px;">
                <div><span class="page-title">📋 کارتابل بررسی مرخصی‌ها</span></div>
            </div>

            <form method="GET" class="filter-box">
                <div class="filter-group">
                    <label class="form-label" style="font-weight:bold; margin-bottom:5px; display:block;">وضعیت کارتابل</label>
                    <select name="status" class="form-control" style="width:100%; padding:10px; font-family:inherit;" onchange="this.form.submit()">
                        <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>📥 صندوق ورودی (نیازمند اقدام)</option>
                        <option value="archive" <?php echo $filterStatus === 'archive' ? 'selected' : ''; ?>>🗄️ آرشیو (بررسی شده‌ها)</option>
                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>همه موارد</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="form-label" style="font-weight:bold; margin-bottom:5px; display:block;">فیلتر کارمند</label>
                    <select name="user_id" class="form-control" style="width:100%; padding:10px; font-family:inherit;" onchange="this.form.submit()">
                        <option value="">همه پرسنل مجاز</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $filterUser == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <a href="leave_manage.php" class="btn btn-outline" style="padding: 10px 20px; font-weight:bold; display:block; text-align:center; width: 100%;">بازنشانی فیلتر</a>
                </div>
            </form>

            <div class="panel-card">
                <?php if (empty($requests)): ?>
                    <div style="text-align:center; padding:40px; color:#94a3b8; font-weight:bold; background:#f8fafc; border-radius:8px; border:1px dashed #cbd5e1;">
                        درخواستی در این بخش یافت نشد.
                    </div>
                <?php else: ?>
                    <div class="table-scroll-hint">👈 برای مشاهده کامل جدول به چپ و راست بکشید 👉</div>
                    <div class="table-responsive">
                        <table class="styled-table">
                            <thead>
                                <tr>
                                    <th>کد</th>
                                    <th>کارمند / ثبت</th>
                                    <th>نوع مرخصی</th>
                                    <th>تاریخ / زمان مرخصی</th>
                                    <th>توضیحات و علت</th>
                                    <th>وضعیت</th>
                                    <th style="width: 120px; text-align:center;">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $req): 
                                    $jStartDate = $req['start_date'] ? jdate('Y/m/d', strtotime($req['start_date'])) : '-';
                                    $jEndDate = $req['end_date'] ? jdate('Y/m/d', strtotime($req['end_date'])) : '-';
                                    $regDate = jdate('Y/m/d', strtotime($req['created_at'])) . ' ' . date('H:i', strtotime($req['created_at']));
                                    $currStatus = $req['status'] ?? '';
                                    
                                    $canApprove = false;
                                    if ($isDeptManager && in_array($currStatus, ['pending', 'pending_manager', '']) && in_array($req['department_id'], $managedDeptIds)) {
                                        $canApprove = true;
                                    }
                                    if ($isAdmin && $currStatus === 'pending_admin') {
                                        $canApprove = true;
                                    }

                                    $timeIn = $req['start_time'] ? date('H:i', strtotime($req['start_time'])) : '';
                                    $timeOut = $req['end_time'] ? date('H:i', strtotime($req['end_time'])) : '';

                                    $dateStr = "";
                                    if ($req['leave_type'] === 'hourly') {
                                        $dateStr = "تاریخ: <span dir='ltr'>$jStartDate</span> | از: <span dir='ltr'>$timeIn</span> تا: <span dir='ltr'>$timeOut</span>";
                                    } else {
                                        $dateStr = "از تاریخ: <span dir='ltr'>$jStartDate</span> تا: <span dir='ltr'>$jEndDate</span>";
                                    }

                                    $jsData = [
                                        'id' => $req['id'], 'emp' => $req['first_name'] . ' ' . $req['last_name'],
                                        'type_lbl' => strip_tags($typeLabels[$req['leave_type']] ?? 'نامشخص'), 'date_str' => $dateStr, 'reason' => $req['user_reason']
                                    ];
                                ?>
                                <tr>
                                    <td style="font-weight:bold; color:#64748b;">#<?php echo $req['id']; ?></td>
                                    <td>
                                        <div style="font-weight:bold; color:#1e293b; font-size:1rem;"><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></div>
                                        <div style="color:#64748b; font-size:0.75rem; margin-top:3px;">ثبت: <span dir="ltr"><?php echo $regDate; ?></span></div>
                                    </td>
                                    <td><?php echo $typeLabels[$req['leave_type']] ?? 'نامشخص'; ?></td>
                                    <td style="font-size: 0.85rem; font-weight:bold; color:#475569; direction:ltr; text-align:right;">
                                        <?php if($req['leave_type'] === 'hourly'): ?>
                                            <div><?php echo $jStartDate; ?></div>
                                            <div style="color:#2563eb; margin-top:3px;"><?php echo $timeIn . ' الی ' . $timeOut; ?></div>
                                        <?php else: ?>
                                            <div><?php echo $jStartDate; ?> <span style="color:#cbd5e1;">تا</span> <?php echo $jEndDate; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width: 200px; white-space: normal; line-height: 1.6; font-size: 0.85rem;">
                                        <div style="margin-bottom: 5px;"><?php echo htmlspecialchars(mb_substr($req['user_reason'], 0, 80)); ?>...</div>
                                        <?php if (!empty($req['attachment'])): ?>
                                            <div><a href="../uploads/leaves/<?php echo htmlspecialchars($req['attachment']); ?>" target="_blank" style="color:#0284c7; text-decoration:none; font-weight:bold; font-size:0.75rem;">🖼️ مشاهده مدرک پیوست</a></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo $statusLabels[$currStatus] ?? 'نامشخص'; ?></div>
                                        <?php if (!empty($req['manager_note'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.75rem; color: #854d0e; background: #fef9c3; padding: 4px; border-radius: 4px; border: 1px solid #fde047;">دپارتمان: <?php echo htmlspecialchars(mb_substr($req['manager_note'],0,30)); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($req['admin_note'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.75rem; color: #1e3a8a; background: #dbeafe; padding: 4px; border-radius: 4px; border: 1px solid #bfdbfe;">ادمین: <?php echo htmlspecialchars(mb_substr($req['admin_note'],0,30)); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($canApprove): ?>
                                            <button class="btn-action-primary" style="width:100%;" onclick='openReviewModal(<?php echo htmlspecialchars(json_encode($jsData), ENT_QUOTES, 'UTF-8'); ?>)'>بررسی</button>
                                        <?php else: ?>
                                            <button class="btn-action-disabled" style="width:100%;" disabled>بایگانی</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php 
                        $queryParams = $_GET; unset($queryParams['page']); $queryString = http_build_query($queryParams);
                        for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&<?php echo $queryString; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<div class="modal-overlay" id="reviewModal">
    <div class="modal-box">
        <div class="modal-header">
            <span style="font-size:1.1rem; font-weight:900;">بررسی درخواست مرخصی</span>
            <span style="cursor:pointer; color:#ef4444; font-size:1.5rem; line-height:1;" onclick="closeReviewModal()">✖</span>
        </div>
        
        <div class="modal-body">
            <div id="modalAlert" style="display:none; padding:10px; border-radius:8px; font-weight:bold; margin-bottom:15px; font-size:0.85rem; text-align:center;"></div>
            
            <div style="background:#eff6ff; padding:15px; border-radius:10px; margin-bottom:25px; font-size:0.95rem; line-height:1.8; border:1px solid #bfdbfe; color:#1e3a8a;">
                <div><strong>کارمند:</strong> <span id="lblEmp"></span></div>
                <div style="display:flex; align-items:center; margin: 8px 0;"><strong>نوع مرخصی:</strong> <span id="lblType" style="margin-right:10px; background:#dbeafe; padding:2px 8px; border-radius:6px; font-weight:bold;"></span></div>
                <div id="lblDate" style="font-weight:bold; direction:rtl;"></div>
                <div style="margin-top:10px; border-top:1px dashed #93c5fd; padding-top:10px;"><strong>علت کارمند:</strong> <span id="lblReason"></span></div>
            </div>

            <form id="reviewForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                <input type="hidden" name="action" value="review_request">
                <input type="hidden" name="request_id" id="reviewReqId">
                <input type="hidden" name="status" id="reviewStatus">

                <div class="form-group" style="margin-bottom: 25px;">
                    <label style="font-weight:bold; display:block; margin-bottom:8px; color:#475569;">توضیحات شما (جهت نمایش به کارمند)</label>
                    <textarea name="manager_note" class="form-control" rows="4" placeholder="در صورت رد کردن، نوشتن دلیل اجباری است..." style="width:100%; border-radius:10px; resize:vertical; padding:12px; font-family:inherit; border:1px solid #cbd5e1;"></textarea>
                </div>

                <div class="modal-actions-wrapper">
                    <?php if($isAdmin): ?>
                        <button type="button" class="btn-modal-action btn-modal-success" onclick="submitReview('approved')"><span>✅</span> تایید نهایی</button>
                    <?php else: ?>
                        <button type="button" class="btn-modal-action btn-modal-success" onclick="submitReview('approved')"><span>✅</span> تایید درخواست</button>
                    <?php endif; ?>
                    <button type="button" class="btn-modal-action btn-modal-danger" onclick="submitReview('rejected')"><span>❌</span> رد مرخصی</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function openReviewModal(data) {
        document.getElementById('reviewModal').style.display = 'flex';
        document.getElementById('modalAlert').style.display = 'none';
        document.getElementById('reviewForm').reset();
        
        document.getElementById('reviewReqId').value = data.id;
        document.getElementById('lblEmp').innerText = data.emp;
        document.getElementById('lblType').innerHTML = data.type_lbl;
        document.getElementById('lblDate').innerHTML = data.date_str;
        document.getElementById('lblReason').innerText = data.reason;
    }

    function closeReviewModal() { document.getElementById('reviewModal').style.display = 'none'; }

    function submitReview(status) {
        document.getElementById('reviewStatus').value = status;
        
        const form = document.getElementById('reviewForm');
        const alertBox = document.getElementById('modalAlert');
        
        if (status === 'rejected' && form.manager_note.value.trim() === '') {
            alertBox.style.background = '#fee2e2'; alertBox.style.color = '#991b1b'; alertBox.style.border = '1px solid #fecaca';
            alertBox.innerText = 'برای رد درخواست، باید علت آن را در بخش توضیحات بنویسید.';
            alertBox.style.display = 'block';
            return;
        }

        if (!confirm('آیا از ' + (status === 'approved' ? 'تایید' : 'رد') + ' این مرخصی اطمینان دارید؟')) return;

        const fd = new FormData(form);

        fetch('leave_manage.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            alertBox.style.display = 'block';
            if (data.status === 'success') {
                alertBox.style.background = '#dcfce7'; alertBox.style.color = '#166534'; alertBox.style.border = '1px solid #bbf7d0';
                alertBox.innerText = data.message;
                setTimeout(() => location.reload(), 1500);
            } else {
                alertBox.style.background = '#fee2e2'; alertBox.style.color = '#991b1b'; alertBox.style.border = '1px solid #fecaca';
                alertBox.innerText = data.message;
            }
        })
        .catch(() => {
            alertBox.style.background = '#fee2e2'; alertBox.style.color = '#991b1b'; alertBox.style.border = '1px solid #fecaca';
            alertBox.innerText = 'خطا در ارتباط با سرور.';
            alertBox.style.display = 'block';
        });
    }
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>