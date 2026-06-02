<?php
/*
 * فایل: public_html/admin/attendance_manage.php
 * بررسی درخواست‌های تردد (نمایش جدولی ۲۰تایی، فیلترهای داشبورد، تایید/رد هوشمند)
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

// --- بررسی هوشمند مدیریت دپارتمان (بدون توجه به اسم نقش) ---
$stmtMgr = $pdo->prepare("SELECT id FROM departments WHERE manager_id = ?");
$stmtMgr->execute([$userId]);
$managedDeptIds = $stmtMgr->fetchAll(PDO::FETCH_COLUMN);
$isDeptManager = count($managedDeptIds) > 0;

if (!$isAdmin && !$isDeptManager) {
    if ($isAjax) { echo json_encode(['status' => 'error', 'message' => 'شما مدیر هیچ دپارتمانی نیستید.']); exit; }
    die('<div style="text-align:center; margin-top:50px; font-family:tahoma; color:red; font-size:1.2rem; font-weight:bold;">⛔ دسترسی غیرمجاز. شما در سیستم به عنوان مدیر هیچ دپارتمانی ثبت نشده‌اید.</div>');
}

// --- پردازش تایید/رد درخواست (AJAX) ---
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
        
        $stmtFetch = $pdo->prepare("SELECT a.*, u.first_name, u.last_name, u.mobile, u.department_id FROM attendance_corrections a JOIN users u ON a.user_id = u.id WHERE a.id = ?");
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

        // ------------------ منطق مدیر دپارتمان ------------------
        if ($actingAs === 'manager') {
            if (!in_array($req['department_id'], $managedDeptIds)) {
                echo json_encode(['status' => 'error', 'message' => 'این کارمند در دپارتمان شما نیست.']); exit;
            }

            if ($reviewStatus === 'approved') {
                $newStatus = 'pending_admin';
                $msgResult = 'درخواست تایید اولیه شد و به صندوق مدیریت کل ارجاع گردید.';
                $pdo->prepare("UPDATE attendance_corrections SET status = ?, manager_id = ?, manager_note = ? WHERE id = ?")->execute([$newStatus, $userId, ($note ?: 'تایید مدیر دپارتمان'), $reqId]);
                
                if (function_exists('send_user_notification')) {
                    $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin','management')")->fetchAll(PDO::FETCH_COLUMN);
                    foreach($admins as $admId) send_user_notification($pdo, $admId, "درخواست جدید در کارتابل مدیریت", "یک اصلاح تردد توسط مدیر دپارتمان تایید و به کارتابل شما ارجاع شد.", "admin/attendance_manage.php", 'attendance', $reqId);
                }
            } else {
                $newStatus = 'rejected_manager';
                $msgResult = 'درخواست رد شد و به آرشیو و کارتابل کارمند منتقل گردید.';
                $pdo->prepare("UPDATE attendance_corrections SET status = ?, manager_id = ?, manager_note = ? WHERE id = ?")->execute([$newStatus, $userId, $note, $reqId]);
                
                if (function_exists('send_user_notification')) send_user_notification($pdo, $req['user_id'], "رد درخواست تردد", "درخواست شما توسط مدیر دپارتمان رد شد.", "admin/attendance_requests.php", 'attendance_result', $reqId);
            }
        } 
        // ------------------ منطق مدیریت کل (Admin) ------------------
        else {
            if (!$isAdmin) { echo json_encode(['status' => 'error', 'message' => 'شما دسترسی تایید نهایی را ندارید.']); exit; }

            if ($reviewStatus === 'approved') {
                $newStatus = 'approved';
                $msgResult = 'درخواست تایید نهایی شد و در کارکرد ماهانه اعمال گردید.';
                $pdo->prepare("UPDATE attendance_corrections SET status = ?, admin_id = ?, admin_note = ? WHERE id = ?")->execute([$newStatus, $userId, ($note ?: 'تایید نهایی مدیریت کل'), $reqId]);
                
                $stmtTsCheck = $pdo->prepare("SELECT id FROM timesheets WHERE user_id = ? AND date = ? LIMIT 1");
                $stmtTsCheck->execute([$req['user_id'], $req['target_date']]);
                $tsId = $stmtTsCheck->fetchColumn();

                if ($tsId) {
                    $updFields = []; $updParams = [];
                    if ($req['time_start'] !== null) { $updFields[] = "time_in = ?"; $updParams[] = $req['time_start']; }
                    if ($req['time_end'] !== null) { $updFields[] = "time_out = ?"; $updParams[] = $req['time_end']; }
                    if (!empty($updFields)) {
                        $updParams[] = $tsId;
                        $pdo->prepare("UPDATE timesheets SET " . implode(', ', $updFields) . " WHERE id = ?")->execute($updParams);
                    }
                } else {
                    $pdo->prepare("INSERT INTO timesheets (user_id, fiscal_year_id, date, time_in, time_out) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$req['user_id'], $req['fiscal_year_id'], $req['target_date'], $req['time_start'], $req['time_end']]);
                }
            } else {
                $newStatus = 'rejected_admin';
                $msgResult = 'درخواست توسط مدیریت کل رد شد و به آرشیو منتقل گردید.';
                $pdo->prepare("UPDATE attendance_corrections SET status = ?, admin_id = ?, admin_note = ? WHERE id = ?")->execute([$newStatus, $userId, $note, $reqId]);
            }
            
            if (function_exists('send_user_notification')) {
                send_user_notification($pdo, $req['user_id'], "نتیجه نهایی اصلاح تردد", "درخواست شما توسط مدیریت کل " . ($reviewStatus=='approved'?'تایید':'رد') . " شد.", "admin/attendance_requests.php", 'attendance_result', $reqId);
            }
            $settingStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sms_attendance_status'");
            if ($settingStmt->fetchColumn() === '1' && !empty($req['mobile']) && function_exists('send_sms_pattern')) {
                $config = @include __DIR__ . '/../../Config/config.php';
                if (!empty($config['attendance_result_pattern'])) {
                    send_sms_pattern($req['mobile'], $config['attendance_result_pattern'], [
                        'date' => jdate('Y/m/d', strtotime($req['target_date'])),
                        'status' => ($reviewStatus=='approved' ? 'تایید' : 'رد')
                    ]);
                }
            }
        }

        logSystem('Attendance', 'review', $reqId, "بررسی و تغییر وضعیت به: $newStatus");
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => $msgResult]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'خطای سیستمی: ' . $e->getMessage()]);
    }
    exit;
}

// --- نمایش لیست (GET) ---
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20; 
$offset = ($page - 1) * $limit;

$filterStatus = $_GET['status'] ?? 'pending';
$filterUser = $_GET['user_id'] ?? '';

$where = "1=1";
$params = [];

if ($filterStatus === 'pending') {
    $pendingConds = [];
    if ($isAdmin) {
        $pendingConds[] = "a.status = 'pending_admin'";
    }
    if ($isDeptManager) {
        $deptPlaceholders = implode(',', array_fill(0, count($managedDeptIds), '?'));
        $pendingConds[] = "(a.status IN ('pending_manager', 'pending', '') AND u.department_id IN ($deptPlaceholders))";
        foreach ($managedDeptIds as $did) $params[] = $did;
    }
    if (empty($pendingConds)) {
        $where .= " AND 1=0"; 
    } else {
        $where .= " AND (" . implode(" OR ", $pendingConds) . ")";
    }
} 
elseif ($filterStatus === 'archive') {
    $where .= " AND a.status NOT IN ('pending_manager', 'pending_admin', 'pending', '')";
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
    $where .= " AND a.user_id = ?";
    $params[] = $filterUser;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_corrections a JOIN users u ON a.user_id = u.id WHERE $where");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$sql = "SELECT a.*, u.first_name, u.last_name, u.personnel_code, u.department_id 
        FROM attendance_corrections a JOIN users u ON a.user_id = u.id 
        WHERE $where ORDER BY a.created_at ASC LIMIT ? OFFSET ?";

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
    'forgot_in'   => '<span class="req-badge badge-forgot-in"><i class="fas fa-sign-in-alt"></i> فراموشی ورود</span>',
    'forgot_out'  => '<span class="req-badge badge-forgot-out"><i class="fas fa-sign-out-alt"></i> فراموشی خروج</span>',
    'wrong_punch' => '<span class="req-badge badge-wrong-punch"><i class="fas fa-user-clock"></i> اصلاح ساعت</span>'
];

$statusLabels = [
    'pending'         => '<span class="status-badge" style="background:#fef08a; color:#854d0e;">در حال بررسی</span>',
    'pending_manager' => '<span class="status-badge" style="background:#fef08a; color:#854d0e;">در انتظار تایید </span>',
    'pending_admin'   => '<span class="status-badge" style="background:#bfdbfe; color:#1e3a8a;">منتظر تایید نهایی</span>',
    'approved'        => '<span class="status-badge" style="background:#dcfce7; color:#166534;">تایید نهایی شده</span>',
    'rejected'        => '<span class="status-badge" style="background:#fee2e2; color:#991b1b;">رد شده</span>',
    'rejected_manager'=> '<span class="status-badge" style="background:#fee2e2; color:#991b1b;">رد شده (مدیر واحد)</span>',
    'rejected_admin'  => '<span class="status-badge" style="background:#fecaca; color:#7f1d1d;">رد شده (مدیریت کل)</span>'
];

$pageTitle = 'بررسی درخواست‌های تردد';
$basePath = '../';
$extraCss = '<style>
    .btn { display: inline-flex; align-items: center; justify-content: center; text-align: center; }

    .container-centered { max-width: 1300px; margin: 0 auto; padding: 0 20px; }
    .styled-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; font-weight: 800; color: #475569; border-bottom: 2px solid #e2e8f0; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; vertical-align: middle; }
    .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; white-space: nowrap; }
    
    .mc-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;}
    .mc-title { font-weight: 800; font-size: 1.3rem; color: #1e293b; display: flex; align-items: center; gap: 10px; }
    
    .filter-box { background: #fff; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 20px; margin-bottom: 25px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; box-shadow: 0 2px 4px rgba(0,0,0,0.02);}
    .filter-group { flex: 1; min-width: 200px; }
    
    .req-badge { padding: 6px 14px; border-radius: 30px; font-size: 0.85rem; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; }
    .badge-forgot-in { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; box-shadow: 0 2px 4px rgba(22,101,52,0.1); }
    .badge-forgot-out { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; box-shadow: 0 2px 4px rgba(154,52,18,0.1); }
    .badge-wrong-punch { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; box-shadow: 0 2px 4px rgba(55,48,163,0.1); }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(248, 250, 252, 0.95); z-index: 2000; align-items: flex-start; justify-content: center; overflow-y: auto; padding: 0; backdrop-filter: blur(4px); }
    .modal-wrapper { width: 100%; min-height: 100vh; padding: 30px 15px; display: flex; flex-direction: column; align-items: center; }
    .modal-header-top { width: 100%; display: flex; justify-content: space-between; align-items: center; padding-bottom: 20px; max-width: 600px; }
    .btn-close { background: #fff; border: 1px solid #cbd5e1; width: 40px; height: 40px; border-radius: 50%; font-size: 1.5rem; cursor: pointer; display:flex; align-items:center; justify-content:center; color:#64748b; transition:0.2s; box-shadow:0 2px 4px rgba(0,0,0,0.05);}
    .btn-close:hover { background: #fee2e2; color: #b91c1c; border-color: #fecaca;}
    .modal-content-box { background: #fff; width: 100%; max-width: 600px; border-radius: 16px; position: relative; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); padding: 30px; border: 1px solid #e2e8f0; }
    
    @media(max-width: 768px){ .container-centered { padding: 0 10px; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            <div class="mc-header mt-4">
                <div class="mc-title"><i class="fas fa-clipboard-check text-primary"></i> کارتابل مدیریت تردد</div>
            </div>

            <form method="GET" class="filter-box page-actions">
                <div class="filter-group">
                    <label class="form-label">وضعیت کارتابل</label>
                    <select name="status" class="form-control" onchange="this.form.submit()">
                        <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>صندوق ورودی (نیازمند اقدام)</option>
                        <option value="archive" <?php echo $filterStatus === 'archive' ? 'selected' : ''; ?>>آرشیو (بررسی شده‌ها)</option>
                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>همه موارد</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="form-label">کارمند</label>
                    <select name="user_id" class="form-control" onchange="this.form.submit()">
                        <option value="">همه پرسنل مجاز</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $filterUser == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <a href="attendance_manage.php" class="btn btn-outline" style="padding: 9px 20px; font-weight:bold;">بازنشانی فیلتر</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>کارمند</th>
                            <th>نوع درخواست</th>
                            <th>تاریخ مورد نظر</th>
                            <th>ساعت ثبت شده</th>
                            <th>علت درخواست</th>
                            <th>وضعیت</th>
                            <th style="width: 140px; text-align: center;">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">درخواستی در این بخش یافت نشد.</td></tr>
                        <?php else: foreach ($requests as $index => $req): 
                            $jDate = jdate('Y/m/d', strtotime($req['target_date']));
                            $currStatus = $req['status'] ?? '';
                            $statusBadge = $statusLabels[$currStatus] ?? '<span class="status-badge" style="background:#f1f5f9; color:#64748b;">نامشخص</span>';
                            
                            $canApprove = false;
                            if ($isDeptManager && in_array($currStatus, ['pending', 'pending_manager', '']) && in_array($req['department_id'], $managedDeptIds)) {
                                $canApprove = true;
                            }
                            if ($isAdmin && $currStatus === 'pending_admin') {
                                $canApprove = true;
                            }

                            $jsData = [
                                'id' => $req['id'], 'emp' => $req['first_name'] . ' ' . $req['last_name'],
                                'type_lbl' => $typeLabels[$req['type']] ?? 'نامشخص', 'date' => $jDate, 'reason' => $req['user_reason']
                            ];
                        ?>
                            <tr>
                                <td style="font-weight:bold; color:#64748b;"><?php echo $offset + $index + 1; ?></td>
                                <td>
                                    <strong class="text-dark"><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></strong><br>
                                    <small class="text-muted">کد: <?php echo htmlspecialchars($req['personnel_code'] ?? '-'); ?></small>
                                </td>
                                <td><strong><?php echo $typeLabels[$req['type']] ?? 'نامشخص'; ?></strong></td>
                                <td dir="ltr"><?php echo $jDate; ?></td>
                                <td dir="ltr" style="color: #64748b; font-weight:bold;">
                                    <?php echo ($req['time_start'] ? substr($req['time_start'], 0, 5) : '--') . ' الی ' . ($req['time_end'] ? substr($req['time_end'], 0, 5) : '--'); ?>
                                </td>
                                <td><small class="text-dark" title="<?php echo htmlspecialchars($req['user_reason']); ?>"><?php echo mb_substr($req['user_reason'], 0, 30) . '...'; ?></small></td>
                                <td><?php echo $statusBadge; ?></td>
                                <td style="text-align: center;">
                                    <?php if ($canApprove): ?>
                                        <button class="btn btn-sm btn-primary" style="font-family:'Vazirmatn', Tahoma, sans-serif !important;" onclick='openReviewModal(<?php echo htmlspecialchars(json_encode($jsData), ENT_QUOTES, 'UTF-8'); ?>)'>بررسی و اقدام</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" disabled style="cursor:not-allowed; font-family:'Vazirmatn', Tahoma, sans-serif !important;">غیرقابل اقدام</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination page-actions" style="margin-top: 25px; display:flex; justify-content:center; gap:5px;">
                    <?php 
                    $queryParams = $_GET; unset($queryParams['page']); $queryString = http_build_query($queryParams);
                    for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&<?php echo $queryString; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>" style="padding:5px 12px; border:1px solid #cbd5e1; border-radius:6px; text-decoration:none; color:#334155;"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری</footer>
</main>

<div class="modal-overlay" id="reviewModal">
    <div class="modal-wrapper">
        <div class="modal-header-top">
            <h2 style="font-weight:900; color:#1e293b; margin:0;">بررسی درخواست تردد</h2>
            <button class="btn-close" onclick="closeReviewModal()">×</button>
        </div>
        
        <div class="modal-content-box">
            <div id="modalAlert" class="alert" style="display:none; border-radius:8px;"></div>
            
            <div style="background:#eff6ff; padding:15px; border-radius:10px; margin-bottom:25px; font-size:0.95rem; line-height:1.8; border:1px solid #bfdbfe; color:#1e3a8a;">
                <div><strong>کارمند:</strong> <span id="lblEmp"></span></div>
                <div style="display:flex; align-items:center; margin: 8px 0;"><strong>نوع درخواست:</strong> <span id="lblType" class="ms-2"></span></div>
                <div><strong>تاریخ لحاظ:</strong> <span id="lblDate" dir="ltr"></span></div>
                <div style="margin-top:10px; border-top:1px dashed #93c5fd; padding-top:10px;"><strong>علت کارمند:</strong> <span id="lblReason"></span></div>
            </div>

            <form id="reviewForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="review_request">
                <input type="hidden" name="request_id" id="reviewReqId">
                <input type="hidden" name="status" id="reviewStatus">

                <div class="form-group" style="margin-bottom: 25px;">
                    <label class="form-label" style="font-weight:bold; color:#475569;">توضیحات شما (جهت نمایش به کارمند)</label>
                    <textarea name="manager_note" class="form-control" rows="4" placeholder="در صورت رد کردن، نوشتن دلیل اجباری است..." style="border-radius:10px; resize:vertical; padding:12px; border:1px solid #cbd5e1;"></textarea>
                </div>

                <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <?php if($isAdmin): ?>
                        <button type="button" class="btn btn-success" style="flex:1; border-radius:10px; font-weight:bold; padding:14px; font-size:1rem;" onclick="submitReview('approved')">✅ تایید نهایی</button>
                    <?php else: ?>
                        <button type="button" class="btn btn-success" style="flex:1; border-radius:10px; font-weight:bold; padding:14px; font-size:1rem;" onclick="submitReview('approved')">✅ تایید درخواست</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-danger" style="flex:1; border-radius:10px; font-weight:bold; padding:14px; font-size:1rem;" onclick="submitReview('rejected')">❌ رد درخواست</button>
                </div>
                <div style="text-align: center;">
                    <button type="button" class="btn btn-outline" style="width: 100%; border-radius:10px; padding:10px;" onclick="closeReviewModal()">انصراف از بررسی</button>
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
        document.getElementById('lblDate').innerText = data.date;
        document.getElementById('lblReason').innerText = data.reason;
    }

    function closeReviewModal() { document.getElementById('reviewModal').style.display = 'none'; }

    function submitReview(status) {
        document.getElementById('reviewStatus').value = status;
        
        const form = document.getElementById('reviewForm');
        const alertBox = document.getElementById('modalAlert');
        
        if (status === 'rejected' && form.manager_note.value.trim() === '') {
            alertBox.className = 'alert alert-danger';
            alertBox.innerText = 'برای رد درخواست، باید علت آن را در بخش توضیحات بنویسید.';
            alertBox.style.display = 'block';
            return;
        }

        if (!confirm('آیا از ' + (status === 'approved' ? 'تایید' : 'رد') + ' این درخواست اطمینان دارید؟')) return;

        const fd = new FormData(form);

        fetch('attendance_manage.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(res => res.json())
        .then(data => {
            alertBox.style.display = 'block';
            if (data.status === 'success') {
                alertBox.className = 'alert alert-success';
                alertBox.innerText = data.message;
                setTimeout(() => location.reload(), 1500);
            } else {
                alertBox.className = 'alert alert-danger';
                alertBox.innerText = data.message;
            }
        })
        .catch(() => {
            alertBox.className = 'alert alert-danger';
            alertBox.innerText = 'خطا در ارتباط با سرور.';
            alertBox.style.display = 'block';
        });
    }
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>