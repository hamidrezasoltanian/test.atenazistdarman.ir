<?php
/*
 * فایل: public_html/admin/attendance_requests.php
 * توضیحات: کارتابل اصلاح تردد (حفظ سلسله‌مراتب حتی برای ادمین + اعلان هوشمند)
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

// تشخیص مدیر دپارتمان بودن کاربر فعلی
$stmtMgr = $pdo->prepare("SELECT id FROM departments WHERE manager_id = ?");
$stmtMgr->execute([$userId]);
$managedDeptIds = $stmtMgr->fetchAll(PDO::FETCH_COLUMN);
$isDeptManager = count($managedDeptIds) > 0;

$canBypassRules = ($isAdmin || $isDeptManager);

// دریافت تنظیمات محدودیت از دیتابیس
$settingStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('attendance_max_requests', 'attendance_max_past_days')");
$sysSettings = $settingStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$maxReqsLimit = isset($sysSettings['attendance_max_requests']) ? (int)$sysSettings['attendance_max_requests'] : 3;
$maxPastDaysLimit = isset($sysSettings['attendance_max_past_days']) ? (int)$sysSettings['attendance_max_past_days'] : 7;

// دریافت اطلاعات فرستنده برای اعلان
$senderIcon = '../assets/images/profile-icon.png';
$userFullName = $_SESSION['fullname'] ?? 'کارمند';
try {
    $stmtImg = $pdo->prepare("SELECT profile_image, first_name, last_name FROM users WHERE id = ?");
    $stmtImg->execute([$userId]);
    $uData = $stmtImg->fetch(PDO::FETCH_ASSOC);
    if ($uData) {
        $userFullName = trim($uData['first_name'] . ' ' . $uData['last_name']);
        if (!empty($uData['profile_image']) && $uData['profile_image'] !== 'default.png') {
            $senderIcon = '../uploads/profiles/' . $uData['profile_image'];
        }
    }
} catch (Exception $e) {}

// --- پردازش حذف درخواست ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && isset($_POST['action']) && $_POST['action'] === 'delete_request') {
    ob_clean(); header('Content-Type: application/json');
    try {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status' => 'error', 'message' => 'خطای امنیتی.']); exit;
        }
        $reqId = (int)$_POST['request_id'];
        $stmtCheck = $pdo->prepare("SELECT status, user_id FROM attendance_corrections WHERE id = ?");
        $stmtCheck->execute([$reqId]);
        $reqData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$reqData || $reqData['user_id'] != $userId) {
            echo json_encode(['status' => 'error', 'message' => 'دسترسی غیرمجاز.']); exit;
        }
        if ($reqData['status'] !== 'pending_manager') {
            echo json_encode(['status' => 'error', 'message' => 'فقط درخواست‌های در مرحله تایید مدیر دپارتمان قابل حذف هستند.']); exit;
        }
        $pdo->prepare("DELETE FROM attendance_corrections WHERE id = ?")->execute([$reqId]);
        echo json_encode(['status' => 'success', 'message' => 'حذف شد.']);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
    exit;
}

// --- پردازش ثبت و ویرایش ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax && (isset($_POST['action']) && in_array($_POST['action'], ['add_request', 'edit_request']))) {
    ob_clean(); header('Content-Type: application/json');
    try {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['status' => 'error', 'message' => 'خطای امنیتی.']); exit; }

        $action = $_POST['action'];
        $reqId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $targetUserId = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : $userId;
        $type = $_POST['type'] ?? '';
        $target_date_fa = trim($_POST['target_date'] ?? '');
        $time_start = $_POST['time_start'] ?: null;
        $time_end = $_POST['time_end'] ?: null;
        $user_reason = trim($_POST['user_reason'] ?? '');

        // تبدیل تاریخ و اعتبارسنجی محدودیت زمانی
        $p = explode('/', faToEn($target_date_fa));
        $target_date_en = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));
        $currentDateEn = date('Y-m-d');
        
        $dateDiff = (strtotime($currentDateEn) - strtotime($target_date_en)) / (60 * 60 * 24);
        if (!$canBypassRules && $dateDiff > $maxPastDaysLimit) {
            echo json_encode(['status' => 'error', 'message' => "خطا: حداکثر بازه مجاز $maxPastDaysLimit روز گذشته است."]); exit;
        }

        $fiscalYear = getActiveFiscalYear();
        $pdo->beginTransaction();

        // چک کردن سقف تعداد درخواست در ماه
        if ($action === 'add_request' && !$canBypassRules) {
            $stmtLimit = $pdo->prepare("SELECT COUNT(*) FROM attendance_corrections WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $stmtLimit->execute([$targetUserId]);
            if ($stmtLimit->fetchColumn() >= $maxReqsLimit) {
                echo json_encode(['status' => 'error', 'message' => "سقف مجاز $maxReqsLimit درخواست در ماه تکمیل شده است."]); exit;
            }
        }

        // جلوگیری از ثبت تکراری در یک روز
        $overlapParams = [$targetUserId, $target_date_en];
        $overlapQuery = "SELECT id FROM attendance_corrections WHERE user_id = ? AND target_date = ? AND status NOT IN ('rejected_admin', 'rejected_manager', 'rejected')";
        if ($action === 'edit_request') {
            $overlapQuery .= " AND id != ?";
            $overlapParams[] = $reqId;
        }
        $stmtOverlap = $pdo->prepare($overlapQuery);
        $stmtOverlap->execute($overlapParams);
        if ($stmtOverlap->fetchColumn()) {
            echo json_encode(['status' => 'error', 'message' => 'خطا: برای این تاریخ قبلاً یک درخواست در سیستم ثبت شده است.']); exit;
        }

        // ============================================
        // تعیین هوشمند وضعیت و ارجاع سلسله مراتبی
        // ============================================
        $initialStatus = 'pending_manager';
        $managerIdForRecord = null;
        $managerNoteForRecord = null;

        // دریافت دپارتمان شخص هدف و مدیر آن
        $userDeptStmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
        $userDeptStmt->execute([$targetUserId]);
        $userDeptId = $userDeptStmt->fetchColumn();

        $mgrSql = "SELECT manager_id FROM departments WHERE id = ?";
        $mgrStmt = $pdo->prepare($mgrSql);
        $mgrStmt->execute([$userDeptId]);
        $targetDeptManagerId = $mgrStmt->fetchColumn();

        if ($targetDeptManagerId) {
            if ($targetDeptManagerId == $userId) {
                // اگر شخصی که ثبت میکند دقیقا مدیر دپارتمان این کارمند است (یا برای خودش ثبت میکند)
                $initialStatus = 'pending_admin';
                if ($targetUserId != $userId) {
                    $managerIdForRecord = $userId;
                    $managerNoteForRecord = 'ثبت و تایید مستقیم توسط مدیر دپارتمان';
                }
            } else {
                // اگر ادمین یا هر کس دیگری برای کارمند ثبت کند، به کارتابل مدیر دپارتمان کارمند میرود
                $initialStatus = 'pending_manager';
            }
        } else {
            // اگر دپارتمان هدف اصلا مدیری نداشت، مستقیم به کارتابل ادمین می‌رود
            $initialStatus = 'pending_admin';
        }

        if ($action === 'add_request') {
            $stmt = $pdo->prepare("INSERT INTO attendance_corrections (user_id, fiscal_year_id, type, target_date, time_start, time_end, user_reason, status, manager_id, manager_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$targetUserId, $fiscalYear['id'], $type, $target_date_en, $time_start, $time_end, $user_reason, $initialStatus, $managerIdForRecord, $managerNoteForRecord]);
            $newReqId = $pdo->lastInsertId();
        } else {
            // آپدیت برای حالتی که ویرایش می‌شود (فقط رد شده‌ها)
            $stmt = $pdo->prepare("UPDATE attendance_corrections SET type=?, target_date=?, time_start=?, time_end=?, user_reason=?, status=?, manager_note=NULL, admin_note=NULL WHERE id=?");
            $stmt->execute([$type, $target_date_en, $time_start, $time_end, $user_reason, $initialStatus, $reqId]);
            $newReqId = $reqId;
        }

        // اعلان هوشمند به مدیر دپارتمان یا ادمین
        $notifyUsers = [];
        if ($initialStatus === 'pending_admin') {
            $notifyUsers = $pdo->query("SELECT id, mobile FROM users WHERE status = 'active' AND role IN ('admin', 'management')")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            if ($targetDeptManagerId) {
                $mgrSqlNotify = "SELECT id, mobile FROM users WHERE id = ? AND status = 'active'";
                $mgrStmtNotify = $pdo->prepare($mgrSqlNotify);
                $mgrStmtNotify->execute([$targetDeptManagerId]);
                $notifyUsers = $mgrStmtNotify->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $settingStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sms_attendance_new'");
        $smsSetting = $settingStmt->fetchColumn();
        $config = @include __DIR__ . '/../../Config/config.php';
        $patternCode = $config['attendance_new_req_pattern'] ?? '';

        foreach ($notifyUsers as $nu) {
            if ($nu['id'] != $userId) {
                if (function_exists('send_user_notification')) {
                    send_user_notification($pdo, $nu['id'], "🔔 بررسی اصلاح تردد", "درخواست جدید اصلاح تردد از طرف $userFullName ثبت شد.", "admin/attendance_manage.php", 'attendance', $newReqId, $senderIcon);
                }
                if ($smsSetting === '1' && !empty($nu['mobile']) && !empty($patternCode) && function_exists('send_sms_pattern')) {
                    send_sms_pattern($nu['mobile'], $patternCode, ['user' => $userFullName, 'date' => $target_date_fa]);
                }
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'درخواست با موفقیت ثبت شد.']);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
    exit;
}

$allowedUsers = [];
if ($isAdmin || $isDeptManager) {
    $qUsers = "SELECT id, first_name, last_name, personnel_code FROM users WHERE status = 'active'";
    if (!$isAdmin) {
        $qUsers .= " AND department_id IN (" . implode(',', $managedDeptIds) . ")";
    }
    $allowedUsers = $pdo->query($qUsers)->fetchAll(PDO::FETCH_ASSOC);
}

// صفحه‌بندی 20 تایی
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20; 
$offset = ($page - 1) * $limit;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_corrections WHERE user_id = ?");
$countStmt->execute([$userId]);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$stmt = $pdo->prepare("SELECT * FROM attendance_corrections WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $userId, PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [
    'pending'         => '<span class="status-badge" style="background:#fef08a; color:#854d0e;">در حال بررسی</span>',
    'pending_manager' => '<span class="status-badge" style="background:#fef08a; color:#854d0e;">منتظر تایید مدیر واحد</span>',
    'pending_admin'   => '<span class="status-badge" style="background:#bfdbfe; color:#1e3a8a;">منتظر تایید نهایی</span>',
    'approved'        => '<span class="status-badge" style="background:#dcfce7; color:#166534;">تایید نهایی شده</span>',
    'rejected'        => '<span class="status-badge" style="background:#fee2e2; color:#991b1b;">رد شده</span>',
    'rejected_manager'=> '<span class="status-badge" style="background:#fee2e2; color:#991b1b;">رد شده توسط مدیر واحد</span>',
    'rejected_admin'  => '<span class="status-badge" style="background:#fecaca; color:#7f1d1d;">رد نهایی مدیریت</span>'
];

$typeLabels = ['forgot_in' => 'فراموشی ورود', 'forgot_out' => 'فراموشی خروج', 'wrong_punch' => 'اصلاح ساعت'];

$pageTitle = 'کارتابل تردد من';
$basePath = '../';
$extraCss = '<link rel="stylesheet" href="../vendor/kamaDatepicker/kamadatepicker.min.css">
<style>
    .container-centered { max-width: 1300px; margin: 0 auto; padding: 0 20px; }
    .styled-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; font-weight: 800; color: #475569; border-bottom: 2px solid #e2e8f0; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; vertical-align: middle; }
    .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; white-space: nowrap; }
    .btn-sm { font-family: "Vazirmatn", Tahoma !important; padding: 5px 10px; font-size: 0.75rem; border-radius: 6px; }
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-content-box { background: #fff; width: 95%; max-width: 600px; border-radius: 16px; padding: 30px; border: 1px solid #e2e8f0; position:relative; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            <div class="page-header page-actions">
                <div><span class="page-title">📌 کارتابل اصلاح تردد من</span></div>
                <div><button onclick="openReqModal()" class="btn btn-primary" style="font-family:'Vazirmatn', Tahoma, sans-serif !important;">+ ثبت درخواست جدید</button></div>
            </div>

            <div class="table-responsive">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>نوع درخواست</th>
                            <th>تاریخ مورد نظر</th>
                            <th>ساعت ثبت شده</th>
                            <th>علت درخواست</th>
                            <th>توضیحات مدیر / ادمین</th>
                            <th>وضعیت</th>
                            <th style="width: 140px; text-align: center;">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">هیچ درخواستی تا کنون ثبت نکرده‌اید.</td></tr>
                        <?php else: foreach ($requests as $index => $req): 
                            $isPending = in_array($req['status'], ['pending_manager', 'pending']);
                            $isRejected = strpos($req['status'], 'rejected') !== false;
                            
                            $mgrNote = $req['manager_note'] ? "<div style='color:#b45309; font-size:0.75rem; margin-bottom:4px;'><strong>مدیر:</strong> ".htmlspecialchars($req['manager_note'])."</div>" : "";
                            $admNote = $req['admin_note'] ? "<div style='color:#1d4ed8; font-size:0.75rem;'><strong>ادمین:</strong> ".htmlspecialchars($req['admin_note'])."</div>" : "";
                            $allNotes = $mgrNote . $admNote;
                            if(empty($allNotes)) $allNotes = "<span class='text-muted'>-</span>";
                        ?>
                            <tr>
                                <td style="font-weight:bold; color:#64748b;"><?php echo $offset + $index + 1; ?></td>
                                <td><strong><?php echo $typeLabels[$req['type']] ?? $req['type']; ?></strong></td>
                                <td dir="ltr"><?php echo jdate('Y/m/d', strtotime($req['target_date'])); ?></td>
                                <td dir="ltr" style="color: #64748b; font-weight:bold;">
                                    <?php echo ($req['time_start'] ? substr($req['time_start'], 0, 5) : '--') . ' الی ' . ($req['time_end'] ? substr($req['time_end'], 0, 5) : '--'); ?>
                                </td>
                                <td><small class="text-dark" title="<?php echo htmlspecialchars($req['user_reason']); ?>"><?php echo mb_substr($req['user_reason'], 0, 30) . '...'; ?></small></td>
                                <td><?php echo $allNotes; ?></td>
                                <td><?php echo $statusLabels[$req['status']] ?? $req['status']; ?></td>
                                <td style="text-align: center;">
                                    <div style="display:flex; justify-content:center; gap:5px;">
                                        <?php if($isPending): ?>
                                            <button onclick="deleteRequest(<?php echo $req['id']; ?>)" class="btn btn-sm btn-outline-danger" style="font-family:'Vazirmatn', Tahoma, sans-serif !important;">حذف</button>
                                        <?php endif; ?>
                                        <?php if($isRejected): ?>
                                            <button onclick='openEditModal(<?php echo json_encode($req); ?>)' class="btn btn-sm btn-outline-primary" style="font-family:'Vazirmatn', Tahoma, sans-serif !important;">ویرایش</button>
                                        <?php endif; ?>
                                        <?php if(!$isPending && !$isRejected): ?>
                                            <span class="text-muted" style="font-size:0.75rem;"><i class="fas fa-lock"></i> نهایی شده</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination page-actions" style="margin-top: 20px; display:flex; justify-content:center; gap:5px;">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="btn btn-sm <?php echo ($i == $page) ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<div class="modal-overlay" id="reqModal">
    <div class="modal-content-box">
        <h3 id="modalTitleText" style="margin-bottom:20px; font-weight:900; color:#1e293b;">ثبت درخواست جدید</h3>
        <div id="modalAlert" class="alert" style="display:none; border-radius:8px; margin-bottom:15px;"></div>
        <form id="reqForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
            <input type="hidden" name="action" id="formAction" value="add_request">
            <input type="hidden" name="request_id" id="formReqId" value="0">
            
            <?php if (!empty($allowedUsers)): ?>
            <div style="margin-bottom: 15px;" id="targetUserBox">
                <label class="form-label" style="font-weight:bold; color:#1e3a8a;">ثبت درخواست برای کارمند (دسترسی مدیران) <span style="color:red">*</span></label>
                <select name="target_user_id" id="targetUserId" class="form-control" style="background:#eff6ff; border-color:#93c5fd;" required>
                    <option value="<?php echo $userId; ?>">برای خودم (<?php echo htmlspecialchars($_SESSION['fullname']); ?>)</option>
                    <?php foreach($allowedUsers as $u): ?>
                        <?php if($u['id'] != $userId): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' - کد: ' . $u['personnel_code']); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div style="display:flex; gap:10px; margin-bottom:15px;">
                <div style="flex:1;">
                    <label class="form-label">نوع درخواست <span style="color:red">*</span></label>
                    <select name="type" id="reqType" class="form-control" onchange="toggleTimeFields()" required>
                        <option value="forgot_in">فراموشی ورود</option>
                        <option value="forgot_out">فراموشی خروج</option>
                        <option value="wrong_punch">اصلاح ساعت</option>
                    </select>
                </div>
                <div style="flex:1;">
                    <label class="form-label">تاریخ <span style="color:red">*</span></label>
                    <input type="text" name="target_date" id="targetDate" class="form-control" placeholder="140X/XX/XX" required autocomplete="off">
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-bottom:15px;">
                <div style="flex:1;" id="boxTimeStart"><label class="form-label">ساعت ورود</label><input type="time" name="time_start" id="timeStart" class="form-control"></div>
                <div style="flex:1;" id="boxTimeEnd"><label class="form-label">ساعت خروج</label><input type="time" name="time_end" id="timeEnd" class="form-control"></div>
            </div>

            <label class="form-label">علت درخواست <span style="color:red">*</span></label>
            <textarea name="user_reason" id="userReason" class="form-control" rows="3" required style="margin-bottom:20px;"></textarea>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn btn-outline" style="border-radius:10px; padding:10px 25px;" onclick="closeReqModal()">انصراف</button>
                <button type="submit" class="btn btn-primary" style="border-radius:10px; padding:10px 25px;" id="submitBtn">ثبت نهایی</button>
            </div>
        </form>
    </div>
</div>

<script src="../vendor/kamaDatepicker/kamadatepicker.min.js"></script>
<script>
    function openReqModal() {
        document.getElementById('reqModal').style.display = 'flex';
        document.getElementById('reqForm').reset();
        document.getElementById('formAction').value = 'add_request';
        document.getElementById('modalTitleText').innerText = 'ثبت درخواست جدید';
        
        const targetBox = document.getElementById('targetUserBox');
        if (targetBox) {
            targetBox.style.display = 'block';
            document.getElementById('targetUserId').value = '<?php echo $userId; ?>';
        }
        
        toggleTimeFields();
        kamaDatepicker('targetDate', { forceFarsiDigits: true, gotoToday: true, markToday: true });
    }
    
    function openEditModal(data) {
        document.getElementById('reqModal').style.display = 'flex';
        document.getElementById('formAction').value = 'edit_request';
        document.getElementById('formReqId').value = data.id;
        document.getElementById('modalTitleText').innerText = 'ویرایش و ارسال مجدد درخواست';
        
        const targetBox = document.getElementById('targetUserBox');
        if (targetBox) targetBox.style.display = 'none'; 
        
        document.getElementById('reqType').value = data.type;
        document.getElementById('targetDate').value = data.target_date; // دیتابیس میلادی را خودمان تبدیل کردیم و در PHP با فرمت درست پاس دادیم. (نیاز به فرمت 140x)
        
        // اصلاح: تبدیل تاریخ در صورت نیاز توسط کاربر از طریق دیت‌پیکر، در اینجا فعلا مقدار اولیه می‌نشیند.
        document.getElementById('timeStart').value = data.time_start ? data.time_start.substring(0, 5) : '';
        document.getElementById('timeEnd').value = data.time_end ? data.time_end.substring(0, 5) : '';
        document.getElementById('userReason').value = data.user_reason;
        
        toggleTimeFields();
        kamaDatepicker('targetDate', { forceFarsiDigits: true });
    }

    function closeReqModal() { document.getElementById('reqModal').style.display = 'none'; }
    
    function toggleTimeFields() {
        const type = document.getElementById('reqType').value;
        document.getElementById('boxTimeStart').style.display = (type === 'forgot_out' ? 'none' : 'block');
        document.getElementById('boxTimeEnd').style.display = (type === 'forgot_in' ? 'none' : 'block');
    }
    
    function deleteRequest(id) {
        if(!confirm('آیا از حذف این درخواست اطمینان دارید؟')) return;
        const fd = new FormData();
        fd.append('action', 'delete_request');
        fd.append('request_id', id);
        fd.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
        fetch('attendance_requests.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(data => { if(data.status === 'success') location.reload(); else alert(data.message); });
    }
    
    document.getElementById('reqForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('submitBtn');
        const alertBox = document.getElementById('modalAlert');
        btn.disabled = true;
        const fd = new FormData(this);
        fetch('attendance_requests.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(data => { 
            alertBox.style.display = 'block';
            if(data.status === 'success') {
                alertBox.className = 'alert alert-success';
                alertBox.innerText = data.message;
                setTimeout(()=>location.reload(), 1500);
            } else { 
                alertBox.className = 'alert alert-danger';
                alertBox.innerText = data.message;
                btn.disabled = false; 
            } 
        }).catch(() => {
            alertBox.style.display = 'block';
            alertBox.className = 'alert alert-danger';
            alertBox.innerText = 'خطا در ارتباط با سرور';
            btn.disabled = false;
        });
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>