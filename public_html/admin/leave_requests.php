<?php
/*
 * فایل: public_html/admin/leave_requests.php
 * توضیحات: فرم ثبت درخواست مرخصی (ارسال پیامک ایمن و یکپارچه کاملا مشابه فایل ماموریت)
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

if (!$isAdmin && !hasPermission('leaves_list')) {
    die('<div style="text-align:center; padding:50px; font-weight:bold; color:red;">⛔ دسترسی غیرمجاز.</div>');
}

$stmtMgr = $pdo->prepare("SELECT id FROM departments WHERE manager_id = ?");
$stmtMgr->execute([$userId]);
$managedDeptIds = $stmtMgr->fetchAll(PDO::FETCH_COLUMN);
$isDeptManager = count($managedDeptIds) > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && isset($_POST['action']) && $_POST['action'] === 'delete_request') {
    ob_clean(); header('Content-Type: application/json');
    try {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status' => 'error', 'message' => 'خطای امنیتی. صفحه را رفرش کنید.']); exit;
        }

        $reqId = (int)$_POST['request_id'];

        $stmtCheck = $pdo->prepare("SELECT status, user_id, attachment FROM leave_requests WHERE id = ?");
        $stmtCheck->execute([$reqId]);
        $reqData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$reqData || $reqData['user_id'] != $userId) {
            echo json_encode(['status' => 'error', 'message' => 'این درخواست یافت نشد یا متعلق به شما نیست.']); exit;
        }
        
        if ($reqData['status'] !== 'pending_manager') {
            echo json_encode(['status' => 'error', 'message' => 'فقط درخواست‌هایی که در انتظار بررسی مدیر دپارتمان هستند قابل لغو می‌باشند.']); exit;
        }

        if (!empty($reqData['attachment'])) {
            $filePath = __DIR__ . '/../../public_html/uploads/leaves/' . $reqData['attachment'];
            if (file_exists($filePath)) { @unlink($filePath); }
        }

        $pdo->prepare("DELETE FROM leave_requests WHERE id = ?")->execute([$reqId]);
        if(function_exists('logSystem')) logSystem('Leave', 'delete', $reqId, "حذف درخواست مرخصی توسط کارمند");

        echo json_encode(['status' => 'success', 'message' => 'درخواست با موفقیت لغو و حذف شد.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'خطا در حذف: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && (isset($_POST['action']) && in_array($_POST['action'], ['add_request', 'edit_request']))) {
    ob_clean(); header('Content-Type: application/json');
    try {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status' => 'error', 'message' => 'خطای امنیتی. صفحه را رفرش کنید.']); exit;
        }

        $action = $_POST['action'];
        $reqId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $targetUserId = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : $userId;
        
        $type = $_POST['leave_type'] ?? '';
        $start_date_fa = trim($_POST['start_date'] ?? '');
        $end_date_fa = trim($_POST['end_date'] ?? '');
        $time_start = trim($_POST['time_start'] ?? '');
        $time_end = trim($_POST['time_end'] ?? '');
        $user_reason = trim($_POST['user_reason'] ?? '');

        if ($targetUserId !== $userId && !$isAdmin && !$isDeptManager) {
            echo json_encode(['status' => 'error', 'message' => 'شما مجاز به ثبت درخواست برای دیگران نیستید.']); exit;
        }

        if (empty($type) || empty($start_date_fa) || empty($user_reason)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً تمامی فیلدهای ستاره‌دار را پر کنید.']); exit;
        }

        $pStart = explode('/', faToEn($start_date_fa));
        if (count($pStart) !== 3) { echo json_encode(['status' => 'error', 'message' => 'فرمت تاریخ شروع نامعتبر است.']); exit; }
        $start_date_en = implode('-', jalali_to_gregorian($pStart[0], $pStart[1], $pStart[2]));

        $end_date_en = null;
        if ($type === 'hourly') {
            if (empty($time_start) || empty($time_end)) {
                echo json_encode(['status' => 'error', 'message' => 'برای مرخصی ساعتی، ثبت ساعت خروج و برگشت الزامی است.']); exit;
            }
        } else {
            if (empty($end_date_fa)) {
                echo json_encode(['status' => 'error', 'message' => 'ثبت تاریخ پایان الزامی است.']); exit;
            }
            $pEnd = explode('/', faToEn($end_date_fa));
            if (count($pEnd) !== 3) { echo json_encode(['status' => 'error', 'message' => 'فرمت تاریخ پایان نامعتبر است.']); exit; }
            $end_date_en = implode('-', jalali_to_gregorian($pEnd[0], $pEnd[1], $pEnd[2]));
            
            if (strtotime($end_date_en) < strtotime($start_date_en)) {
                echo json_encode(['status' => 'error', 'message' => 'تاریخ پایان نمی‌تواند قبل از شروع باشد.']); exit;
            }
            $time_start = null; $time_end = null;
        }

        $fiscalYear = getActiveFiscalYear();
        if (!$fiscalYear) { echo json_encode(['status' => 'error', 'message' => 'سال مالی فعالی یافت نشد.']); exit; }

        $overlapParams = [$targetUserId];
        $overlapQuery = "SELECT id, leave_type, start_date, end_date, start_time, end_time 
                         FROM leave_requests 
                         WHERE user_id = ? AND status NOT IN ('rejected_admin', 'rejected_manager', 'rejected')";
        if ($action === 'edit_request') {
            $overlapQuery .= " AND id != ?";
            $overlapParams[] = $reqId;
        }
        
        $stmtOverlap = $pdo->prepare($overlapQuery);
        $stmtOverlap->execute($overlapParams);
        $existingLeaves = $stmtOverlap->fetchAll(PDO::FETCH_ASSOC);
        
        $hasOverlap = false;
        $newStart = strtotime($start_date_en . ($type === 'hourly' ? ' ' . $time_start : ' 00:00:00'));
        $newEnd   = strtotime(($type === 'hourly' ? $start_date_en : $end_date_en) . ($type === 'hourly' ? ' ' . $time_end : ' 23:59:59'));

        foreach ($existingLeaves as $el) {
            $elStart = strtotime($el['start_date'] . ($el['leave_type'] === 'hourly' ? ' ' . $el['start_time'] : ' 00:00:00'));
            $elEnd   = strtotime(($el['leave_type'] === 'hourly' ? $el['start_date'] : ($el['end_date'] ?? $el['start_date'])) . ($el['leave_type'] === 'hourly' ? ' ' . $el['end_time'] : ' 23:59:59'));
            
            if ($newStart < $elEnd && $newEnd > $elStart) {
                $hasOverlap = true; break;
            }
        }
        
        if ($hasOverlap) {
            echo json_encode(['status' => 'error', 'message' => 'درخواست شما با مرخصی‌های قبلی تداخل زمانی دارد!']); exit;
        }

        $attachmentName = null;
        if ($action === 'edit_request') {
            $stmtOldFile = $pdo->prepare("SELECT attachment FROM leave_requests WHERE id = ?");
            $stmtOldFile->execute([$reqId]);
            $attachmentName = $stmtOldFile->fetchColumn();
        }

        if ($type === 'daily_sick' && isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['attachment'];
            if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['status' => 'error', 'message' => 'خطا در آپلود مدارک.']); exit; }
            if ($file['size'] > 2 * 1024 * 1024) { echo json_encode(['status' => 'error', 'message' => 'حجم فایل حداکثر 2 مگابایت.']); exit; }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime = mime_content_type($file['tmp_name']);

            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf']) || !in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'])) {
                echo json_encode(['status' => 'error', 'message' => 'فرمت فایل غیرمجاز است.']); exit;
            }

            $uploadDir = __DIR__ . '/../../public_html/uploads/leaves/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }

            $newFileName = 'leave_' . uniqid() . '_' . bin2hex(random_bytes(2)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFileName)) {
                $attachmentName = $newFileName;
            }
        } elseif ($type !== 'daily_sick') {
            $attachmentName = null;
        }

        $pdo->beginTransaction();

        $stmtDeptMgr = $pdo->prepare("SELECT d.manager_id FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.id = ?");
        $stmtDeptMgr->execute([$targetUserId]);
        $deptManagerId = $stmtDeptMgr->fetchColumn();

        $initialStatus = 'pending_manager';
        if (empty($deptManagerId) || $deptManagerId == $targetUserId) {
            $initialStatus = 'pending_admin';
        }

        $stmtSender = $pdo->prepare("SELECT first_name, last_name, profile_image FROM users WHERE id = ?");
        $stmtSender->execute([$targetUserId]);
        $senderData = $stmtSender->fetch(PDO::FETCH_ASSOC);
        $senderName = $senderData['first_name'] . ' ' . $senderData['last_name'];
        $senderIcon = (!empty($senderData['profile_image']) && $senderData['profile_image'] != 'default.png') ? '../uploads/profiles/' . $senderData['profile_image'] : '../assets/images/profile-icon.png';

        $managerIdForRecord = null;
        $managerNoteForRecord = null;
        if ($initialStatus === 'pending_admin' && $targetUserId !== $userId && $isAdmin) {
            $managerIdForRecord = $userId;
            $managerNoteForRecord = 'ثبت و تایید مستقیم توسط مدیریت';
        }

        if ($action === 'add_request') {
            $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, fiscal_year_id, leave_type, start_date, end_date, start_time, end_time, user_reason, attachment, status, manager_id, manager_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$targetUserId, $fiscalYear['id'], $type, $start_date_en, $end_date_en, $time_start, $time_end, $user_reason, $attachmentName, $initialStatus, $managerIdForRecord, $managerNoteForRecord]);
            $newReqId = $pdo->lastInsertId();
            
            if(function_exists('logSystem')) logSystem('Leave', 'create', $newReqId, "ثبت مرخصی: $type");
            $msg = 'درخواست شما ثبت شد.';

            // --- 1. جمع‌آوری هوشمند افراد هدف (مدیر دپارتمان یا ادمین‌ها) ---
            $targetIds = [];
            if ($initialStatus === 'pending_manager' && $deptManagerId) {
                if ($deptManagerId != $userId) $targetIds[] = $deptManagerId;
            } elseif ($initialStatus === 'pending_admin') {
                $stmtAdmins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'management')");
                while ($row = $stmtAdmins->fetch()) {
                    if ($row['id'] != $userId) {
                        $targetIds[] = $row['id'];
                    }
                }
            }
            $targetIds = array_values(array_unique($targetIds));

            // --- 2. ارسال اعلان درون سیستمی ---
            if (function_exists('send_user_notification') && !empty($targetIds)) {
                $annTitle = "درخواست مرخصی جدید";
                $annContent = "همکار گرامی، {$senderName} درخواست مرخصی جدیدی ثبت کرده است.";
                $annLink = "admin/leave_manage.php";
                
                foreach ($targetIds as $tId) {
                    send_user_notification($pdo, $tId, $annTitle, $annContent, $annLink, 'leave', $newReqId, $senderIcon);
                }
            }

            // --- 3. ارسال پیامک هوشمند (کاملاً ایمن مشابه فایل ماموریت) ---
            $smsSetting = '0';
            try {
                $stmtSet = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sms_leave_new'");
                $smsSetting = $stmtSet->fetchColumn();
            } catch (Exception $e) {}

            if ($smsSetting === '1' && !empty($targetIds) && function_exists('send_sms_pattern')) {
                $sysConfig = @include __DIR__ . '/../../Config/config.php';
                $patternCode = $sysConfig['leave_new_req_pattern'] ?? '';

                if (!empty($patternCode)) {
                    $typeNamesFa = ['daily_entitled'=>'استحقاقی', 'daily_sick'=>'استعلاجی', 'daily_unpaid'=>'بدون حقوق', 'hourly'=>'ساعتی'];
                    $leaveTypeFa = $typeNamesFa[$type] ?? 'مرخصی';

                    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                    $stmtMob = $pdo->prepare("SELECT mobile FROM users WHERE id IN ($placeholders) AND mobile IS NOT NULL AND mobile != ''");
                    $stmtMob->execute($targetIds);
                    $mobiles = $stmtMob->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($mobiles as $mob) {
                        send_sms_pattern($mob, $patternCode, [
                            'user' => $senderName,
                            'type' => $leaveTypeFa
                        ]);
                    }
                }
            }

        } 
        else { 
            $stmtCheck = $pdo->prepare("SELECT status, user_id FROM leave_requests WHERE id = ?");
            $stmtCheck->execute([$reqId]);
            $reqData = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$reqData || $reqData['user_id'] != $userId) { echo json_encode(['status' => 'error', 'message' => 'درخواست نامعتبر.']); exit; }
            if (!in_array($reqData['status'], ['rejected_manager', 'rejected_admin'])) { echo json_encode(['status' => 'error', 'message' => 'فقط درخواست‌های رد شده قابل ویرایش هستند.']); exit; }

            $stmt = $pdo->prepare("UPDATE leave_requests SET leave_type=?, start_date=?, end_date=?, start_time=?, end_time=?, user_reason=?, attachment=?, status=?, manager_note=NULL, admin_note=NULL WHERE id=?");
            $stmt->execute([$type, $start_date_en, $end_date_en, $time_start, $time_end, $user_reason, $attachmentName, $initialStatus, $reqId]);
            
            if(function_exists('logSystem')) logSystem('Leave', 'update', $reqId, "ویرایش و ارسال مجدد مرخصی");
            $msg = 'درخواست شما ویرایش و مجدداً در جریان قرار گرفت.';
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => $msg]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'خطای سیستمی: ' . $e->getMessage()]);
    }
    exit;
}

$allowedUsers = [];
if ($isAdmin || $isDeptManager) {
    $qUsers = "SELECT id, first_name, last_name, personnel_code FROM users WHERE status = 'active'";
    if (!$isAdmin) $qUsers .= " AND department_id IN (" . implode(',', $managedDeptIds) . ")";
    $allowedUsers = $pdo->query($qUsers)->fetchAll(PDO::FETCH_ASSOC);
}

$limit = 20; 
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$countStmt = $pdo->prepare("SELECT COUNT(id) FROM leave_requests WHERE user_id = ?");
$countStmt->execute([$userId]);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$userId, $limit, $offset]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$typeLabels = [
    'daily_entitled' => '<span style="background:#e0f2fe; color:#0369a1; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">روزانه استحقاقی</span>',
    'daily_sick'     => '<span style="background:#fee2e2; color:#991b1b; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">روزانه استعلاجی</span>',
    'daily_unpaid'   => '<span style="background:#f1f5f9; color:#475569; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">بدون حقوق</span>',
    'hourly'         => '<span style="background:#e0e7ff; color:#3730a3; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">ساعتی</span>'
];

$statusLabels = [
    'pending_manager' => '<span style="background:#fef08a; color:#854d0e; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:bold;">⏳ در انتظار مدیر دپارتمان</span>',
    'pending_admin'   => '<span style="background:#bfdbfe; color:#1e3a8a; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:bold;">⏳ در انتظار مدیریت کل</span>',
    'approved'        => '<span style="background:#dcfce7; color:#166534; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:bold;">✅ تایید نهایی</span>',
    'rejected_manager'=> '<span style="background:#fecaca; color:#991b1b; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:bold;">❌ رد شده (دپارتمان)</span>',
    'rejected_admin'  => '<span style="background:#fca5a5; color:#7f1d1d; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:bold;">❌ رد شده (مدیریت کل)</span>'
];

$pageTitle = 'درخواست‌های مرخصی من';
$basePath = '../';
$extraCss = '<link rel="stylesheet" href="../vendor/kamadatepicker/kamadatepicker.min.css">
<style>
    .container-centered { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    .panel-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
    
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .styled-table { width: 100%; border-collapse: collapse; min-width: 900px; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; color: #475569; font-weight: 900; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 0.95rem; vertical-align: middle; }
    .styled-table tr:hover td { background: #f8fafc; }
    
    .action-btn { font-family: inherit; padding: 6px 12px; font-size: 0.8rem; font-weight: bold; border-radius: 6px; cursor: pointer; border: 1px solid transparent; display:inline-flex; align-items:center; justify-content:center;}
    .btn-edit { background: #fef3c7; color: #b45309; border-color: #fde68a; } .btn-edit:hover { background: #fde68a; }
    .btn-delete { background: #fee2e2; color: #991b1b; border-color: #fecaca; } .btn-delete:hover { background: #fecaca; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 10px; backdrop-filter: blur(4px); }
    .modal-box { background: #fff; width: 100%; max-width: 600px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
    .modal-header { padding: 20px 25px; background: #f8fafc; font-weight: 900; border-bottom: 1px solid #e2e8f0; display:flex; justify-content: space-between; }
    .modal-body { padding: 25px; max-height: 75vh; overflow-y: auto; }
    .modal-footer { padding: 20px 25px; border-top: 1px solid #e2e8f0; background: #f8fafc; display:flex; justify-content: flex-end; gap:12px;}

    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; margin-top: 25px; padding-top: 20px; border-top: 1px dashed #e2e8f0; }
    .page-link { font-family: inherit; display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; border-radius: 8px; background: #fff; border: 1px solid #cbd5e1; color: #475569; font-weight: bold; text-decoration: none; transition: 0.2s; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            
            <div class="page-header" style="margin-bottom: 25px; display: flex; justify-content: space-between; flex-wrap: wrap; gap:15px;">
                <div><span class="page-title">📅 درخواست‌های مرخصی من</span></div>
                <div>
                    <button onclick="openReqModal()" class="btn btn-primary" style="font-weight:bold; font-family: inherit; box-shadow:0 4px 6px rgba(37,99,235,0.2);">➕ ثبت مرخصی جدید</button>
                </div>
            </div>

            <div class="panel-card">
                <?php if (empty($requests)): ?>
                    <div style="text-align:center; padding:40px; color:#94a3b8; font-weight:bold; background:#f8fafc; border-radius:8px; border:1px dashed #cbd5e1;">
                        شما تاکنون هیچ درخواستی ثبت نکرده‌اید.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="styled-table">
                            <thead>
                                <tr>
                                    <th>کد</th>
                                    <th>نوع مرخصی</th>
                                    <th>تاریخ / زمان</th>
                                    <th>علت درخواست</th>
                                    <th>وضعیت / توضیحات مدیر</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $req): 
                                    $jStartDate = $req['start_date'] ? jdate('Y/m/d', strtotime($req['start_date'])) : '-';
                                    $jEndDate = $req['end_date'] ? jdate('Y/m/d', strtotime($req['end_date'])) : '-';
                                    $isRejected = in_array($req['status'], ['rejected_manager', 'rejected_admin']);
                                    $isPendingManager = ($req['status'] === 'pending_manager');
                                    
                                    $jsData = [
                                        'id' => $req['id'], 'type' => $req['leave_type'], 
                                        'start_date' => $jStartDate, 'end_date' => ($req['leave_type'] === 'hourly' ? '' : $jEndDate),
                                        'time_in' => $req['start_time'] ? date('H:i', strtotime($req['start_time'])) : '',
                                        'time_out' => $req['end_time'] ? date('H:i', strtotime($req['end_time'])) : '',
                                        'reason' => $req['user_reason']
                                    ];
                                ?>
                                <tr>
                                    <td style="font-weight:bold; color:#64748b;">#<?php echo $req['id']; ?></td>
                                    <td><?php echo $typeLabels[$req['leave_type']] ?? 'نامشخص'; ?></td>
                                    <td style="font-size: 0.85rem; font-weight:bold; color:#475569; direction:ltr; text-align:right;">
                                        <?php if($req['leave_type'] === 'hourly'): ?>
                                            <div><?php echo $jStartDate; ?></div>
                                            <div style="color:#2563eb; margin-top:3px;"><?php echo $jsData['time_in'] . ' الی ' . $jsData['time_out']; ?></div>
                                        <?php else: ?>
                                            <div><?php echo $jStartDate; ?> <span style="color:#cbd5e1;">تا</span> <?php echo $jEndDate; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width: 250px; white-space: normal; line-height: 1.6; font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($req['user_reason']); ?>
                                        <?php if (!empty($req['attachment'])): ?>
                                            <div style="margin-top: 5px;"><a href="../uploads/leaves/<?php echo htmlspecialchars($req['attachment']); ?>" target="_blank" style="color:#0284c7; text-decoration:none; font-weight:bold; font-size:0.75rem;">🖼️ مشاهده مدرک پیوست</a></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $statusLabels[$req['status']] ?? 'نامشخص'; ?>
                                        <?php if (!empty($req['manager_note'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.75rem; color: #991b1b; background: #fef2f2; padding: 4px; border-radius: 4px; border: 1px solid #fecaca;">
                                                مدیر دپارتمان: <?php echo htmlspecialchars($req['manager_note']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($req['admin_note'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.75rem; color: #991b1b; background: #fef2f2; padding: 4px; border-radius: 4px; border: 1px solid #fecaca;">
                                                مدیریت کل: <?php echo htmlspecialchars($req['admin_note']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:5px;">
                                            <?php if ($isRejected): ?>
                                                <button class="action-btn btn-edit" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($jsData), ENT_QUOTES, 'UTF-8'); ?>)'>✏️ ویرایش</button>
                                            <?php endif; ?>
                                            <?php if ($isPendingManager): ?>
                                                <button class="action-btn btn-delete" onclick='deleteRequest(<?php echo $req['id']; ?>)'>❌ لغو</button>
                                            <?php endif; ?>
                                            <?php if(!$isRejected && !$isPendingManager): ?>
                                                <span style="font-size:0.75rem; color:#94a3b8; background:#f1f5f9; padding:4px 8px; border-radius:6px; border:1px dashed #cbd5e1;">🔒 قفل سیستم</span>
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
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<div class="modal-overlay" id="reqModal">
    <div class="modal-box">
        <div class="modal-header">
            <span id="modalTitleText">ثبت درخواست مرخصی</span>
            <span style="cursor:pointer; color:#ef4444; font-size:1.5rem;" onclick="closeReqModal()">✖</span>
        </div>
        
        <div class="modal-body">
            <div id="modalAlert" style="display:none; padding:10px; border-radius:8px; font-weight:bold; margin-bottom:15px; font-size:0.85rem; text-align:center;"></div>
            <form id="reqForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                <input type="hidden" name="action" id="formAction" value="add_request">
                <input type="hidden" name="request_id" id="formReqId" value="0">
                
                <?php if (!empty($allowedUsers)): ?>
                <div class="form-group" style="margin-bottom: 15px;" id="targetUserBox">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">ثبت برای کارمند (دسترسی مدیران) <span style="color:red">*</span></label>
                    <select name="target_user_id" id="targetUserId" class="form-control" style="width:100%; padding:10px; font-family:inherit;" required>
                        <option value="<?php echo $userId; ?>">برای خودم (<?php echo htmlspecialchars($_SESSION['fullname']); ?>)</option>
                        <?php foreach($allowedUsers as $u): if($u['id'] != $userId): ?>
                            <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">نوع مرخصی <span style="color:red">*</span></label>
                    <select name="leave_type" id="leaveType" class="form-control" style="width:100%; padding:10px; font-family:inherit;" onchange="toggleLeaveFields()" required>
                        <option value="">انتخاب کنید...</option>
                        <option value="daily_entitled">روزانه استحقاقی</option>
                        <option value="daily_sick">روزانه استعلاجی (نیاز به گواهی)</option>
                        <option value="daily_unpaid">روزانه بدون حقوق</option>
                        <option value="hourly">ساعتی</option>
                    </select>
                </div>

                <div style="display:flex; gap:15px; margin-bottom:15px;">
                    <div style="flex:1;">
                        <label id="lblStartDate" style="font-weight:bold; display:block; margin-bottom:5px;">تاریخ شروع <span style="color:red">*</span></label>
                        <input type="text" name="start_date" id="startDate" class="form-control" style="width:100%; padding:10px; font-family:inherit; direction:ltr; text-align:right;" autocomplete="off" required>
                    </div>
                    <div style="flex:1;" id="boxEndDate">
                        <label style="font-weight:bold; display:block; margin-bottom:5px;">تاریخ پایان <span style="color:red">*</span></label>
                        <input type="text" name="end_date" id="endDate" class="form-control" style="width:100%; padding:10px; font-family:inherit; direction:ltr; text-align:right;" autocomplete="off">
                    </div>
                </div>

                <div style="display:flex; gap:15px; margin-bottom:15px; display:none;" id="boxTime">
                    <div style="flex:1;">
                        <label style="font-weight:bold; display:block; margin-bottom:5px;">ساعت خروج <span style="color:red">*</span></label>
                        <input type="time" name="time_start" id="timeStart" class="form-control" style="width:100%; padding:10px; font-family:inherit; direction:ltr;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-weight:bold; display:block; margin-bottom:5px;">ساعت برگشت <span style="color:red">*</span></label>
                        <input type="time" name="time_end" id="timeEnd" class="form-control" style="width:100%; padding:10px; font-family:inherit; direction:ltr;">
                    </div>
                </div>

                <div class="form-group" id="boxAttachment" style="margin-bottom: 15px; display:none; background:#fef2f2; padding:15px; border-radius:10px; border:1px dashed #fecaca;">
                    <label style="color:#991b1b; font-weight:bold; display:block; margin-bottom:5px;">آپلود گواهی/مدرک پزشکی <span style="color:red">*</span></label>
                    <input type="file" name="attachment" id="attachmentFile" class="form-control" accept=".jpg,.jpeg,.png,.pdf" style="font-family:inherit; width:100%;">
                    <div style="font-size:0.75rem; color:#b91c1c; margin-top:5px;">فایل‌های مجاز: JPG, PNG, PDF | حداکثر 2 مگابایت</div>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">علت درخواست / توضیحات <span style="color:red">*</span></label>
                    <textarea name="user_reason" id="userReason" class="form-control" rows="3" style="width:100%; padding:10px; font-family:inherit; resize:vertical;" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" style="font-family: inherit; padding:10px 20px;" onclick="closeReqModal()">انصراف</button>
                <button type="submit" class="btn btn-primary" id="submitBtn" style="font-family: inherit; padding:10px 20px;">ثبت نهایی درخواست</button>
            </div>
            </form>
        </div>
    </div>
</div>

<script src="../vendor/kamadatepicker/kamadatepicker.min.js"></script>
<script>
    let pickerStart, pickerEnd;

    function openReqModal() {
        document.getElementById('reqModal').style.display = 'flex';
        document.getElementById('modalAlert').style.display = 'none';
        document.getElementById('reqForm').reset();
        document.getElementById('formAction').value = 'add_request';
        document.getElementById('modalTitleText').innerText = 'ثبت درخواست مرخصی';
        
        const targetBox = document.getElementById('targetUserBox');
        if (targetBox) {
            targetBox.style.display = 'block';
            document.getElementById('targetUserId').value = '<?php echo $userId; ?>';
        }
        
        toggleLeaveFields();
        initDatePickers();
    }
    
    function openEditModal(data) {
        document.getElementById('reqModal').style.display = 'flex';
        document.getElementById('modalAlert').style.display = 'none';
        
        document.getElementById('formAction').value = 'edit_request';
        document.getElementById('formReqId').value = data.id;
        document.getElementById('modalTitleText').innerText = 'ویرایش و ارسال مجدد مرخصی';
        
        const targetBox = document.getElementById('targetUserBox');
        if (targetBox) targetBox.style.display = 'none'; 
        
        document.getElementById('leaveType').value = data.type;
        document.getElementById('startDate').value = data.start_date;
        document.getElementById('endDate').value = data.end_date;
        document.getElementById('timeStart').value = data.time_in;
        document.getElementById('timeEnd').value = data.time_out;
        document.getElementById('userReason').value = data.reason;
        
        toggleLeaveFields();
        initDatePickers();
    }

    function closeReqModal() { document.getElementById('reqModal').style.display = 'none'; }

    function initDatePickers() {
        setTimeout(() => {
            const options = { forceFarsiDigits: true, gotoToday: true, markToday: true };
            if(!pickerStart) pickerStart = kamaDatepicker('startDate', options);
            if(!pickerEnd) pickerEnd = kamaDatepicker('endDate', options);
        }, 100);
    }

    function toggleLeaveFields() {
        const type = document.getElementById('leaveType').value;
        const boxEndDate = document.getElementById('boxEndDate');
        const boxTime = document.getElementById('boxTime');
        const boxAttachment = document.getElementById('boxAttachment');
        const fileInput = document.getElementById('attachmentFile');
        
        const lblStart = document.getElementById('lblStartDate');
        const endInput = document.getElementById('endDate');
        const timeStart = document.getElementById('timeStart');
        const timeEnd = document.getElementById('timeEnd');

        if (type === 'daily_sick') {
            boxAttachment.style.display = 'block';
            fileInput.required = (document.getElementById('formAction').value === 'add_request');
        } else {
            boxAttachment.style.display = 'none';
            fileInput.required = false;
            fileInput.value = '';
        }

        if (type === 'hourly') {
            boxEndDate.style.display = 'none';
            boxTime.style.display = 'flex';
            lblStart.innerHTML = 'تاریخ مرخصی <span style="color:red">*</span>';
            endInput.required = false;
            timeStart.required = true;
            timeEnd.required = true;
            endInput.value = ''; 
        } else {
            boxEndDate.style.display = 'block';
            boxTime.style.display = 'none';
            lblStart.innerHTML = 'تاریخ شروع <span style="color:red">*</span>';
            endInput.required = true;
            timeStart.required = false;
            timeEnd.required = false;
            timeStart.value = ''; timeEnd.value = ''; 
        }
    }

    function deleteRequest(id) {
        if (!confirm('آیا از لغو این درخواست اطمینان دارید؟ این عمل غیرقابل بازگشت است.')) return;
        
        const fd = new FormData();
        fd.append('action', 'delete_request');
        fd.append('request_id', id);
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        fetch('leave_requests.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            alert(data.message);
            if (data.status === 'success') location.reload();
        }).catch(() => alert('خطا در ارتباط با سرور.'));
    }

    document.getElementById('reqForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('submitBtn');
        const alertBox = document.getElementById('modalAlert');
        const originalText = btn.innerText;
        
        btn.disabled = true; btn.innerText = 'در حال پردازش...';
        alertBox.style.display = 'none';

        const fd = new FormData(this);

        fetch('leave_requests.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
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
                btn.disabled = false; btn.innerText = originalText;
            }
        })
        .catch(() => {
            alertBox.style.background = '#fee2e2'; alertBox.style.color = '#991b1b'; alertBox.style.border = '1px solid #fecaca';
            alertBox.innerText = 'خطا در ارتباط با سرور.';
            alertBox.style.display = 'block';
            btn.disabled = false; btn.innerText = originalText;
        });
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>