<?php
/*
 * فایل: public_html/admin/customer_profile.php
 * بلوک ۱: توابع، پردازش‌ها، صفحه‌بندی 20تایی تمامی تب‌ها و واکشی اطلاعات
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userRole = $_SESSION['role'] ?? 'user';
$canManageTags = (in_array($userRole, ['admin', 'management', 'manager']) || strpos($userRole, '_manager') !== false);

$customerId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$searchQuery = $_GET['search'] ?? '';

// ==================== توابع داخلی ====================
if (!function_exists('crm_fa_to_en')) {
    function crm_fa_to_en($string) { return str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], ['0','1','2','3','4','5','6','7','8','9'], $string); }
}
if (!function_exists('crm_jalali_to_gregorian')) {
    function crm_jalali_to_gregorian($jy, $jm, $jd) {
        $jy = (int)$jy; $jm = (int)$jm; $jd = (int)$jd; 
        $jy += 1595;
        $days = -355668 + (365 * $jy) + ((int)($jy / 33) * 8) + (int)((($jy % 33) + 3) / 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * (int)($days / 146097); $days %= 146097;
        if ($days > 36524) { $days--; $gy += 100 * (int)($days / 36524); $days %= 36524; if ($days >= 365) $days++; }
        $gy += 4 * (int)($days / 1461); $days %= 1461;
        if ($days > 365) { $gy += (int)(($days - 1) / 365); $days = ($days - 1) % 365; }
        $gd = $days + 1;
        $sal_a = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 0; $gm < 13; $gm++) { $v = $sal_a[$gm]; if ($gd <= $v) break; $gd -= $v; }
        return [$gy, $gm, $gd];
    }
}
if (!function_exists('crm_get_auto_assignee')) {
    function crm_get_auto_assignee($pdo, $cId, $defaultUserId) {
        $stmt1 = $pdo->prepare("SELECT u.id FROM customers c INNER JOIN customer_followers cf ON c.company_num = cf.company_num INNER JOIN users u ON (u.username = cf.username OR cf.full_name LIKE CONCAT('%', u.last_name, '%')) WHERE c.id = ? AND u.status = 'active' LIMIT 1");
        $stmt1->execute([$cId]); $followerId = $stmt1->fetchColumn();
        if ($followerId) return (int)$followerId;
        $stmt2 = $pdo->prepare("SELECT cup.user_id FROM customers c INNER JOIN customer_addresses ca ON c.company_num = ca.company_num INNER JOIN crm_user_provinces cup ON ca.state = cup.province_name WHERE c.id = ? LIMIT 1");
        $stmt2->execute([$cId]); $provinceUserId = $stmt2->fetchColumn();
        if ($provinceUserId) return (int)$provinceUserId;
        return $defaultUserId;
    }
}

function get_mission_status_label($status) {
    $map = ['pending' => 'در انتظار تایید', 'pending_admin' => 'در انتظار تایید مدیریت', 'approved' => 'تایید اولیه', 'expenses_open' => 'در حال ثبت هزینه', 'expenses_submitted' => 'ارسال به مالی', 'finance_process' => 'بررسی مالی', 'finance_review' => 'در حال بررسی مالی', 'completed' => 'پایان یافته', 'rejected' => 'رد شده'];
    return $map[$status] ?? $status;
}

function renderTabPagination($total, $limit, $currentPage, $tabName, $paramName, $customerId) {
    if ($total <= $limit) return '';
    $totalPages = ceil($total / $limit);
    $html = '<div class="d-flex justify-content-center gap-2 mt-4 pt-3 border-top">';
    for ($i = 1; $i <= $totalPages; $i++) {
        $activeClass = ($i == $currentPage) ? 'btn-primary' : 'btn-outline-secondary';
        $html .= '<a href="?id='.$customerId.'&tab='.$tabName.'&'.$paramName.'='.$i.'" class="btn btn-sm '.$activeClass.'">'.$i.'</a>';
    }
    $html .= '</div>';
    return $html;
}

// --- پردازش AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'get_call_related_types') {
        try {
            $types = $pdo->query("SELECT id, title FROM crm_call_related_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $types]);
        } catch (Exception $e) { echo json_encode(['status' => 'error']); }
        exit;
    }
    if ($_POST['action'] === 'save_call') {
        $oppId = (int)($_POST['opp_id'] ?? 0);
        $callId = (int)($_POST['call_id'] ?? 0);
        $reqCustomerId = (int)($_POST['customer_id'] ?? ($customerId ?: 0)); 
        $subject = trim($_POST['subject'] ?? ''); $status = trim($_POST['status'] ?? 'برنامه ریزی شده'); 
        $relatedTo = trim($_POST['related_to'] ?? 'فرصت'); $callDateFa = trim($_POST['call_date'] ?? ''); 
        $callType = trim($_POST['call_type'] ?? 'outbound'); $desc = trim($_POST['description'] ?? '');

        if (empty($subject) || empty($callDateFa)) { echo json_encode(['status' => 'error', 'message' => 'موضوع و تاریخ الزامی است']); exit; }
        
        $callDateFa = crm_fa_to_en($callDateFa); $d = explode('/', $callDateFa); 
        $gDate = (count($d) == 3 && function_exists('crm_jalali_to_gregorian')) ? implode('-', crm_jalali_to_gregorian($d[0], $d[1], $d[2])) : date('Y-m-d');

        try {
            $pdo->beginTransaction();
            if ($oppId <= 0 && $reqCustomerId > 0) {
                $boardId = $pdo->query("SELECT id FROM crm_boards ORDER BY id DESC LIMIT 1")->fetchColumn();
                if ($boardId) {
                    $stageId = $pdo->query("SELECT id FROM crm_board_stages WHERE board_id = $boardId ORDER BY sort_order ASC LIMIT 1")->fetchColumn();
                    if ($stageId) {
                        $stmtNewOpp = $pdo->prepare("INSERT INTO crm_opportunities (board_id, stage_id, customer_id, title, created_by, status) VALUES (?, ?, ?, 'فرصت خودکار پرونده', ?, 'active')");
                        $stmtNewOpp->execute([$boardId, $stageId, $reqCustomerId, $_SESSION['user_id']]);
                        $oppId = $pdo->lastInsertId();
                        $expertId = crm_get_auto_assignee($pdo, $reqCustomerId, $_SESSION['user_id']);
                        $pdo->prepare("INSERT INTO crm_opportunity_assignees (opportunity_id, user_id) VALUES (?, ?)")->execute([$oppId, $expertId]);
                        $pdo->prepare("INSERT INTO crm_opportunity_stage_logs (opportunity_id, stage_id, entered_at) VALUES (?, ?, NOW())")->execute([$oppId, $stageId]);
                    }
                }
            }

            if ($oppId > 0) {
                if ($callId > 0) {
                    $pdo->prepare("UPDATE crm_opportunity_calls SET status=?, related_to=?, call_date=?, call_type=?, description=? WHERE id=?")->execute([$status, $relatedTo, $gDate, $callType, $desc, $callId]);
                    $pdo->prepare("INSERT INTO crm_opportunity_activities (opportunity_id, user_id, activity_type, description) VALUES (?, ?, 'call', ?)")->execute([$oppId, $_SESSION['user_id'], "📞 ویرایش تماس: $subject ($status)"]);
                } else {
                    $pdo->prepare("INSERT INTO crm_opportunity_calls (opportunity_id, user_id, subject, status, related_to, call_date, call_type, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$oppId, $_SESSION['user_id'], $subject, $status, $relatedTo, $gDate, $callType, $desc]);
                    $pdo->prepare("INSERT INTO crm_opportunity_activities (opportunity_id, user_id, activity_type, description) VALUES (?, ?, 'call', ?)")->execute([$oppId, $_SESSION['user_id'], "📞 ثبت تماس جدید: $subject ($status)"]);
                }
            } else { throw new Exception("امکان ایجاد فرصت خودکار وجود ندارد."); }
            
            $pdo->commit(); echo json_encode(['status' => 'success']);
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
        exit;
    }
}

// تنظیمات صفحه‌بندی
$limit = 20;
$callsPage = max(1, (int)($_GET['calls_page'] ?? 1));
$missionsPage = max(1, (int)($_GET['missions_page'] ?? 1));
$tasksPage = max(1, (int)($_GET['tasks_page'] ?? 1));
$lettersPage = max(1, (int)($_GET['letters_page'] ?? 1));

$callsOffset = ($callsPage - 1) * $limit;
$missionsOffset = ($missionsPage - 1) * $limit;
$tasksOffset = ($tasksPage - 1) * $limit;
$lettersOffset = ($lettersPage - 1) * $limit;

$callsTotal = 0; $missionsTotal = 0; $tasksTotal = 0; $lettersTotal = 0;

$customer = null; $searchResults = []; $calls = []; $customerMissions = []; $customerTasks = []; $customerLetters = [];
$highlightCallId = (int)($_GET['call_id'] ?? 0); $notifId = (int)($_GET['notif_id'] ?? 0);

$activeTab = 'calls';
if (isset($_GET['missions_page']) || (isset($_GET['tab']) && $_GET['tab'] == 'missions')) { $activeTab = 'missions'; }
if (isset($_GET['tasks_page']) || (isset($_GET['tab']) && $_GET['tab'] == 'tasks')) { $activeTab = 'tasks'; }
if (isset($_GET['letters_page']) || (isset($_GET['tab']) && $_GET['tab'] == 'letters')) { $activeTab = 'letters'; }
if ($highlightCallId > 0) { $activeTab = 'calls'; }

if (!empty($searchQuery) && $customerId === 0) {
    $stmt = $pdo->prepare("SELECT id, company_name, company_code FROM customers WHERE company_name LIKE ? OR company_code LIKE ? OR mobile LIKE ? LIMIT 20");
    $stmt->execute(["%$searchQuery%", "%$searchQuery%", "%$searchQuery%"]);
    $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($customerId > 0) {
    if ($notifId > 0 && isset($pdo) && isset($_SESSION['user_id'])) {
        try { $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?")->execute([$notifId, $_SESSION['user_id']]); } catch (Exception $e) {}
    }
    
    $stmt = $pdo->prepare("SELECT *, (SELECT GROUP_CONCAT(full_name SEPARATOR '، ') FROM customer_followers WHERE company_num = customers.company_num) AS followers_list FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($customer) {
        try {
            $callsTotal = (int)$pdo->query("SELECT COUNT(*) FROM crm_opportunity_calls c JOIN crm_opportunities o ON c.opportunity_id = o.id WHERE o.customer_id = $customerId")->fetchColumn();
            $stmtC = $pdo->prepare("SELECT c.*, u.first_name, u.last_name FROM crm_opportunity_calls c JOIN crm_opportunities o ON c.opportunity_id = o.id JOIN users u ON u.id = c.user_id WHERE o.customer_id = ? ORDER BY c.call_date DESC, c.created_at DESC LIMIT ? OFFSET ?");
            $stmtC->execute([$customerId, $limit, $callsOffset]); $calls = $stmtC->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        try {
            $missionsTotal = (int)$pdo->query("SELECT COUNT(mi.id) FROM mission_items mi JOIN mission_requests mr ON mi.request_id = mr.id WHERE mi.customer_id = $customerId")->fetchColumn();
            $stmtM = $pdo->prepare("SELECT mi.id as item_id, mi.mission_date, mi.description, mr.status, mr.id as request_id, u.first_name, u.last_name, mt.title as type_title FROM mission_items mi JOIN mission_requests mr ON mi.request_id = mr.id LEFT JOIN mission_types mt ON mi.type_id = mt.id LEFT JOIN users u ON mr.user_id = u.id WHERE mi.customer_id = ? ORDER BY mi.mission_date DESC, mi.id DESC LIMIT ? OFFSET ?");
            $stmtM->execute([$customerId, $limit, $missionsOffset]); $customerMissions = $stmtM->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        try {
            $tasksTotal = (int)$pdo->query("SELECT COUNT(*) FROM crm_opportunity_tasks t JOIN crm_opportunities o ON t.opportunity_id = o.id WHERE o.customer_id = $customerId")->fetchColumn();
            $stmtT = $pdo->prepare("SELECT t.id, t.subject, t.deadline, t.description, t.status, u.first_name, u.last_name, o.title as opp_title FROM crm_opportunity_tasks t JOIN crm_opportunities o ON t.opportunity_id = o.id JOIN users u ON t.user_id = u.id WHERE o.customer_id = ? ORDER BY t.deadline DESC, t.id DESC LIMIT ? OFFSET ?");
            $stmtT->execute([$customerId, $limit, $tasksOffset]); $customerTasks = $stmtT->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        try {
            $lettersTotal = (int)$pdo->query("SELECT COUNT(DISTINCT l.id) FROM letters l LEFT JOIN letter_receivers lr ON l.id = lr.letter_id WHERE (lr.receiver_type = 'external' AND lr.receiver_id = $customerId) OR (l.type = 'incoming' AND l.sender_id = $customerId)")->fetchColumn();
            $stmtL = $pdo->prepare("SELECT DISTINCT l.id, l.subject, l.indicator_number, l.type, l.status, l.registered_at, l.created_at FROM letters l LEFT JOIN letter_receivers lr ON l.id = lr.letter_id WHERE (lr.receiver_type = 'external' AND lr.receiver_id = ?) OR (l.type = 'incoming' AND l.sender_id = ?) ORDER BY l.created_at DESC LIMIT ? OFFSET ?");
            $stmtL->execute([$customerId, $customerId, $limit, $lettersOffset]); $customerLetters = $stmtL->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
}

$pageTitle = $customer ? 'پروفایل: ' . $customer['company_name'] : 'جستجوی مشتری';
$basePath = '../';
$todayJ = function_exists('jdate') ? jdate('Y/m/d') : date('Y/m/d');
?>
<?php
$extraCss = '<style>
    :root { --primary: #2563eb; --secondary: #64748b; --success: #10b981; --warning: #f59e0b; --bg-body: #f8fafc; --card-bg: #ffffff; --border: #e2e8f0; }
    body { font-family: "Vazirmatn", Tahoma, sans-serif !important; background: var(--bg-body); }
    
    .btn-icon-call { width: 42px; height: 42px; background: #f59e0b; color: white; border: none; border-radius: 10px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.25); }
    .btn-icon-call:hover { transform: translateY(-2px); background: #d97706; }
    .btn-icon-call svg { width: 22px; height: 22px; stroke-width: 2; }
    
    .call-list { display: flex; flex-direction: column; gap: 12px; }
    .call-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 14px; display: grid; grid-template-columns: 1fr auto; gap: 12px; transition: 0.2s; }
    .call-card:hover { transform: translateY(-2px); border-color: var(--primary); box-shadow: 0 10px 20px -5px rgba(37,99,235,0.1); }
    .call-meta { color: #64748b; font-size: 0.82rem; display: flex; gap: 10px; flex-wrap: wrap; }
    .call-type { font-weight: 700; color: #0f172a; }
    .call-desc { color: #475569; font-size: 0.9rem; line-height: 1.7; white-space: pre-wrap; margin-top: 6px; border-top: 1px dashed #e2e8f0; padding-top: 8px;}
    .call-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-end; justify-content:center;}
    
    .compact-mission-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; gap: 15px;}
    .compact-mission-card:hover { border-color: var(--primary); box-shadow: 0 4px 12px rgba(0,0,0,0.05); transform: translateY(-1px); }

    .profile-tabs { display: flex; gap: 20px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; flex-wrap: wrap; }
    .tab-btn { background: none; border: none; padding: 12px 10px; font-family: "Vazirmatn", sans-serif; font-size: 1rem; font-weight: 700; color: #64748b; cursor: pointer; border-bottom: 3px solid transparent; transition: 0.3s; }
    .tab-btn:hover { color: var(--primary); }
    .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
    .tab-content { display: none; animation: fadeIn 0.3s ease-out; }
    .tab-content.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    
    .search-hero { background: #fff; padding: 40px 20px; border-radius: 20px; box-shadow: 0 10px 30px -10px rgba(0,0,0,0.05); max-width: 600px; margin: 60px auto; text-align: center; border: 1px solid #f1f5f9; }
    .search-hero h2 { font-size: 1.4rem; color: #1e293b; margin-bottom: 30px; font-weight: 800; }
    .search-form-group { position: relative; max-width: 100%; }
    .search-input { width: 100%; height: 55px; padding: 0 60px 0 20px; border: 2px solid var(--border); border-radius: 14px; font-size: 1rem; transition: 0.3s; background: #fcfcfc; font-family: inherit; }
    .search-input:focus { background: #fff; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); outline: none; }
    .search-submit-btn { position: absolute; left: 8px; top: 8px; bottom: 8px; width: 45px; background: var(--primary); color: white; border: none; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
    .search-submit-btn svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }
    .search-submit-btn:hover { background: #1d4ed8; transform: scale(1.05); }
    .search-results-box { margin-top: 25px; background: #fff; border: 1px solid var(--border); border-radius: 14px; overflow: hidden; text-align: right; }
    .result-row { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid #f1f5f9; text-decoration: none; color: #334155; transition: 0.2s; }
    .result-row:last-child { border-bottom: none; }
    .result-row:hover { background: #f0f9ff; padding-right: 25px; color: var(--primary); }
    
    .profile-layout { display: grid; grid-template-columns: 320px 1fr; gap: 25px; margin-top: 25px; align-items: start; }
    .info-card { background: #fff; border-radius: 16px; border: 1px solid var(--border); overflow: hidden; position: sticky; top: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
    .info-header { height: 90px; background: linear-gradient(135deg, #1e293b, #334155); }
    .info-avatar { width: 85px; height: 85px; background: #fff; border-radius: 50%; margin: -42px auto 10px; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #334155; font-weight: bold; border: 4px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
    .info-content { padding: 10px 20px 25px; }
    .info-title { font-weight: 800; font-size: 1.1rem; color: #1e293b; text-align: center; margin-bottom: 5px; }
    .info-subtitle { color: #64748b; font-size: 0.85rem; text-align: center; margin-bottom: 25px; display: block; }
    .data-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.9rem; padding-bottom: 8px; border-bottom: 1px dashed #f1f5f9; }
    .data-val { color: #334155; font-weight: 600; }
    
    .tag-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 10px; margin-bottom: 12px; justify-content: center; }
    .tag-label { background: #eef2ff; color: #3730a3; border: 1px solid #e0e7ff; padding: 6px 10px; border-radius: 10px; font-size: 0.85rem; font-weight: 800; cursor: pointer; font-family: "Vazirmatn", Tahoma, sans-serif; }
    .tag-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .tag-chip { padding: 4px 10px; border-radius: 8px; font-size: 0.75rem; color: #0f172a; border: 1px solid rgba(0,0,0,0.05); display: inline-flex; align-items: center; gap: 6px; font-weight: bold;}
    .tag-chip-remove { background: none; border: none; color: #0f172a; font-size: 0.9rem; cursor: pointer; padding: 0; line-height: 1; }
    .tag-modal { max-width: 550px; }
    .tag-list { max-height: 300px; overflow-y: auto; border: 1px solid var(--border); border-radius: 10px; padding: 10px; background: #fff; }
    .tag-item { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: center; padding: 8px; border-bottom: 1px dashed #e2e8f0; font-size: 0.85rem; }
    .tag-item:last-child { border-bottom: none; }
    .tag-color { width: 14px; height: 14px; border-radius: 4px; display: inline-block; margin-left: 6px; border: 1px solid rgba(0,0,0,0.1); }
    .tag-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .tag-create-grid { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: center; }
    .tag-color-palette { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .color-swatch { width: 22px; height: 22px; border-radius: 6px; border: 2px solid transparent; cursor: pointer; }
    .color-swatch.active { border-color: #0f172a; }
    .tag-row-line { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .tag-color-input { width: 50px; height: 36px; border: none; background: transparent; }
    
    .missions-container { background: #fff; border-radius: 16px; border: 1px solid var(--border); display: flex; flex-direction: column; }
    .mc-header { padding: 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .mc-title { font-weight: 700; color: #1e293b; font-size: 1.1rem; }
    
    .header-btn-group { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .header-btn { height: 40px; display: flex; align-items: center; justify-content: center; padding: 0 20px; border-radius: 8px; font-size: 0.9rem; text-decoration: none; transition: 0.2s; white-space: nowrap; }
    
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
    .modal-content { background: #fff; width: 95%; max-width: 500px; border-radius: 20px; padding: 0; position: relative; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); animation: modalIn 0.3s ease; }
    @keyframes modalIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .modal-header { padding: 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-radius: 20px 20px 0 0; }
    .modal-header .btn-close { width: 34px; height: 34px; font-size: 1.2rem; opacity: 0.9; cursor: pointer; border: none; background: transparent; }
    .modal-body { padding: 30px 25px; }

    @media (max-width: 992px) { .profile-layout { grid-template-columns: 1fr; } .info-card { position: static; margin-bottom: 20px; } }
    @media (max-width: 768px) { .tag-create-grid { grid-template-columns: 1fr; } .compact-mission-card { flex-direction:column; align-items:flex-start; } .compact-mission-card > div:last-child { align-items:flex-start !important; margin-top:10px; width:100%; flex-direction:row !important; justify-content:space-between; } }
    @media (max-width: 480px) { .search-hero { padding: 30px 15px; margin: 20px auto; } .call-card { grid-template-columns: 1fr; } .call-actions { flex-direction: row; justify-content: flex-end; } .tag-item { grid-template-columns: 1fr; } .tag-actions { width: 100%; justify-content: flex-start; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <?php if (!$customer): ?>
            <div class="page-header page-actions"><div><span class="page-title">پروفایل مشتریان</span></div></div>
            <div class="search-hero">
                <h2>جستجوی پرونده مشتری</h2>
                <form method="GET" class="search-form-group">
                    <input type="text" name="search" class="search-input" placeholder="نام، کد اشتراک یا موبایل..." value="<?php echo htmlspecialchars($searchQuery); ?>" autofocus>
                    <button type="submit" class="search-submit-btn">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </button>
                </form>
                <?php if (!empty($searchQuery)): ?>
                    <div class="search-results-box">
                        <?php if (empty($searchResults)): ?>
                            <div class="p-4 text-center text-muted">موردی یافت نشد.</div>
                        <?php else: foreach ($searchResults as $res): ?>
                            <a href="customer_profile.php?id=<?php echo $res['id']; ?>" class="result-row">
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($res['company_name']); ?></div>
                                    <div class="small text-muted mt-1">کد: <?php echo $res['company_code']; ?></div>
                                </div>
                                <div><i class="fas fa-chevron-left text-muted"></i></div>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="page-header page-actions">
                <div><span class="page-title">پروفایل مشتری</span></div>
                <div class="header-btn-group">
                    <button onclick="openCallModal(0)" class="btn-icon-call" title="ثبت تماس/مذاکره">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                        </svg>
                    </button>
                    <a href="customer_profile.php" class="btn btn-secondary header-btn">جستجوی جدید</a>
                    <a href="customers.php" class="btn btn-outline header-btn">لیست مشتریان</a>
                    <a href="mission_create.php?customer_id=<?php echo (int)$customer['id']; ?>" class="btn btn-primary header-btn"><i class="fas fa-plus ms-2"></i> ثبت ماموریت</a>
                </div>
            </div>
			<div class="profile-layout">
                <!-- سایدبار اطلاعات مشتری -->
                <div class="info-card">
                    <div class="info-header"></div>
                    <div class="info-avatar"><?php echo mb_substr($customer['company_name'], 0, 1); ?></div>
                    <div class="info-content">
                        <div class="info-title"><?php echo htmlspecialchars($customer['company_name']); ?></div>
                        <span class="info-subtitle">کد اشتراک: <?php echo htmlspecialchars($customer['company_code']); ?></span>
                        <div class="tag-bar">
                            <button type="button" class="tag-label" onclick="openTagModal()">برچسب ها +</button>
                            <div class="tag-row" id="customerTags"></div>
                        </div>
                        <div class="mt-4">
                            <div class="data-row"><span class="data-label">ثبت‌کننده:</span><span class="data-val"><?php echo htmlspecialchars($customer['manager_name'] ?? ''); ?></span></div>
                            <div class="data-row"><span class="data-label">پیگیری‌کنندگان:</span><span class="data-val"><?php echo htmlspecialchars($customer['followers_list'] ?? '-'); ?></span></div>
                            <div class="data-row"><span class="data-label">موبایل:</span><span class="data-val" dir="ltr"><?php echo htmlspecialchars($customer['mobile'] ?? ''); ?></span></div>
                            <div class="data-row"><span class="data-label">تلفن ثابت:</span><span class="data-val" dir="ltr"><?php echo htmlspecialchars($customer['phone'] ?? ''); ?></span></div>
                            <div class="data-row"><span class="data-label">کد پستی:</span><span class="data-val" dir="ltr"><?php echo htmlspecialchars($customer['zip_code'] ?? '-'); ?></span></div>
                            <div class="data-row" style="flex-direction: column; gap:5px;"><span class="data-label">آدرس:</span><span class="data-val small" style="line-height: 1.6;"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></span></div>
                        </div>
                    </div>
                </div>

                <!-- بخش اصلی (تب‌ها) -->
                <div>
                    <div class="profile-tabs">
                        <button class="tab-btn <?php echo $activeTab === 'calls' ? 'active' : ''; ?>" onclick="switchTab('calls')">📞 تماس‌ها</button>
                        <button class="tab-btn <?php echo $activeTab === 'missions' ? 'active' : ''; ?>" onclick="switchTab('missions')">🗺️ مأموریت‌ها</button>
                        <button class="tab-btn <?php echo $activeTab === 'tasks' ? 'active' : ''; ?>" onclick="switchTab('tasks')">📝 وظایف</button>
                        <button class="tab-btn <?php echo $activeTab === 'letters' ? 'active' : ''; ?>" onclick="switchTab('letters')">✉️ مکاتبات اداری</button>
                    </div>

                    <!-- تب تماس‌ها -->
                    <div id="tab-calls" class="tab-content <?php echo $activeTab === 'calls' ? 'active' : ''; ?>">
                        <div class="missions-container" style="display:block; padding:20px; background:#fff; border:1px solid var(--border); border-radius:16px;">
                            <div class="mc-header" style="padding:0 0 15px 0; border:none; display:flex; justify-content:space-between; align-items:center;">
                                <div class="mc-title">لیست تماس‌ها و مذاکرات <span class="badge bg-light text-dark ms-2 border"><?php echo (int)$callsTotal; ?></span></div>
                            </div>
                            <?php if (empty($calls)): ?>
                                <div class="text-center py-5 text-muted"><i class="fas fa-phone-slash fa-3x text-gray-300 mb-3"></i><div>هنوز تماسی ثبت نشده است.</div></div>
                            <?php else: ?>
                                <div class="mc-list" style="padding:0;">
                                    <div class="call-list">
                                    <?php foreach ($calls as $call): 
                                        $callId = (int)$call['id'];
                                        $callDate = $call['call_date'] ? (function_exists('jdate') ? jdate('Y/m/d', strtotime($call['call_date'])) : $call['call_date']) : '-';
                                        $typeLabel = $call['call_type'] == 'inbound' ? '📥 ورودی' : '📤 خروجی';
                                        $bg = '#fff'; $borderColor = '#e2e8f0'; $textColor = '#1e293b';
                                        if($call['status'] === 'انجام شده') { $bg = '#dcfce7'; $borderColor = '#22c55e'; $textColor = '#166534'; }
                                        elseif($call['status'] === 'تماس برقرار نشد') { $bg = '#fee2e2'; $borderColor = '#ef4444'; $textColor = '#991b1b'; }
                                    ?>
                                        <div class="call-card" id="call-<?php echo $callId; ?>" style="background:<?php echo $bg; ?>; border-color:<?php echo $borderColor; ?>; <?php echo ($highlightCallId === $callId) ? 'box-shadow:0 0 0 3px rgba(14,165,233,0.15);' : ''; ?>">
                                            <div>
                                                <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-weight:bold; color:<?php echo $textColor; ?>;">
                                                    <span><?php echo htmlspecialchars($call['subject']); ?></span>
                                                    <div>
                                                        <?php if($call['user_id'] == $userId): ?><button class="btn btn-outline" style="padding:2px 8px; font-size:0.7rem; margin-left:10px; background:#fff;" onclick="openCallModal(<?php echo $callId; ?>)">✏️ ویرایش</button><?php endif; ?>
                                                        <span style="color:#2563eb;"><?php echo $callDate; ?></span>
                                                    </div>
                                                </div>
                                                <div class="call-meta">
                                                    <span>وضعیت: <b><?php echo htmlspecialchars($call['status']); ?></b></span>|
                                                    <span>مرتبط با: <b><?php echo htmlspecialchars($call['related_to']); ?></b></span>|
                                                    <span>نوع: <b><?php echo $typeLabel; ?></b></span>|
                                                    <span>ثبت‌کننده: <b><?php echo htmlspecialchars(trim($call['first_name'].' '.$call['last_name'])); ?></b></span>
                                                </div>
                                                <?php if (!empty($call['description'])): ?>
                                                    <div class="call-desc" style="color:<?php echo $textColor; ?>; border-top-color:<?php echo $borderColor; ?>;"><?php echo nl2br(htmlspecialchars($call['description'])); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                    <?php echo renderTabPagination($callsTotal, $limit, $callsPage, 'calls', 'calls_page', $customerId); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- تب مأموریت‌ها -->
                    <div id="tab-missions" class="tab-content <?php echo $activeTab === 'missions' ? 'active' : ''; ?>">
                        <div class="missions-container" style="display:block; padding:20px; background:#fff; border:1px solid var(--border); border-radius:16px;">
                            <div class="mc-header" style="padding:0 0 15px 0; border:none; display:flex; justify-content:space-between; align-items:center;">
                                <div class="mc-title">لیست کامل مأموریت‌ها <span class="badge bg-light text-dark ms-2 border"><?php echo (int)$missionsTotal; ?></span></div>
                            </div>
                            <?php if (empty($customerMissions)): ?>
                                <div class="text-center py-5 text-muted"><i class="fas fa-map-marked-alt fa-3x text-gray-300 mb-3"></i><div>هیچ مأموریتی برای این مشتری ثبت نشده است.</div></div>
                            <?php else: ?>
                                <div class="mc-list" style="padding:0;">
                                    <div class="call-list" style="gap:10px;">
                                    <?php foreach ($customerMissions as $m): 
                                        $mDate = $m['mission_date'] ? (function_exists('jdate') ? jdate('Y/m/d', strtotime($m['mission_date'])) : $m['mission_date']) : '-';
                                        $stLabel = get_mission_status_label($m['status']);
                                        $iconColor = '#2563eb'; $bgBox = '#eff6ff';
                                        if($m['status'] === 'completed') { $iconColor = '#16a34a'; $bgBox = '#dcfce7'; }
                                        elseif($m['status'] === 'rejected') { $iconColor = '#dc2626'; $bgBox = '#fee2e2'; }
                                    ?>
                                        <div class="compact-mission-card">
                                            <div style="display:flex; align-items:center; gap:12px;">
                                                <div style="width:42px; height:42px; background:<?php echo $bgBox; ?>; color:<?php echo $iconColor; ?>; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
                                                    <i class="fas fa-map-marked-alt"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark" style="font-size:0.95rem; margin-bottom:4px;"><?php echo htmlspecialchars($m['type_title'] ?: 'مأموریت'); ?> <span class="text-muted" style="font-size:0.75rem;">(کد: <?php echo $m['request_id']; ?>)</span></div>
                                                    <div class="text-muted" style="font-size:0.8rem;">
                                                        <span class="badge bg-light text-dark border"><?php echo $stLabel; ?></span> | کارشناس: <b><?php echo htmlspecialchars(trim($m['first_name'].' '.$m['last_name'])); ?></b>
                                                        <?php if(!empty($m['description'])): ?> | <span><?php echo mb_strimwidth(htmlspecialchars($m['description']), 0, 45, '...'); ?></span><?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div style="text-align:left; min-width:85px; display:flex; flex-direction:column; align-items:flex-end; gap:5px;">
                                                <div style="color:var(--primary); font-size:0.85rem; font-weight:bold;" dir="ltr"><?php echo $mDate; ?></div>
                                                <a href="mission_view.php?id=<?php echo $m['request_id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size:0.7rem; padding:2px 10px;">مشاهده</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                    <?php echo renderTabPagination($missionsTotal, $limit, $missionsPage, 'missions', 'missions_page', $customerId); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- تب وظایف -->
                    <div id="tab-tasks" class="tab-content <?php echo $activeTab === 'tasks' ? 'active' : ''; ?>">
                        <div class="missions-container" style="display:block; padding:20px; background:#fff; border:1px solid var(--border); border-radius:16px;">
                            <div class="mc-header" style="padding:0 0 15px 0; border:none; display:flex; justify-content:space-between; align-items:center;">
                                <div class="mc-title">لیست وظایف (Tasks) <span class="badge bg-light text-dark ms-2 border"><?php echo (int)$tasksTotal; ?></span></div>
                            </div>
                            <?php if (empty($customerTasks)): ?>
                                <div class="text-center py-5 text-muted"><i class="fas fa-tasks fa-3x text-gray-300 mb-3"></i><div>وظیفه‌ای برای فرصت‌های این مشتری ثبت نشده است.</div></div>
                            <?php else: ?>
                                <div class="mc-list" style="padding:0;">
                                    <div class="call-list">
                                    <?php foreach ($customerTasks as $t): 
                                        $tDate = $t['deadline'] ? (function_exists('jdate') ? jdate('Y/m/d', strtotime($t['deadline'])) : $t['deadline']) : '-';
                                        $stLabel = ($t['status'] === 'completed') ? 'انجام شده' : 'در حال انجام';
                                        $bg = '#fff'; $borderColor = '#e2e8f0'; $textColor = '#1e293b';
                                        if($t['status'] === 'completed') { $bg = '#f1f5f9'; $borderColor = '#cbd5e1'; $textColor = '#64748b'; }
                                    ?>
                                        <div class="call-card" style="background:<?php echo $bg; ?>; border-color:<?php echo $borderColor; ?>;">
                                            <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-weight:bold; color:<?php echo $textColor; ?>;">
                                                <span>📝 <?php echo htmlspecialchars($t['subject']); ?></span>
                                                <span style="color:#ef4444;">موعد: <?php echo $tDate; ?></span>
                                            </div>
                                            <div class="call-meta">
                                                <span>وضعیت: <b><?php echo $stLabel; ?></b></span>|
                                                <span>فرصت مرتبط: <b><?php echo htmlspecialchars($t['opp_title']); ?></b></span>|
                                                <span>کارشناس: <b><?php echo htmlspecialchars(trim($t['first_name'].' '.$t['last_name'])); ?></b></span>
                                            </div>
                                            <?php if (!empty($t['description'])): ?>
                                                <div class="call-desc" style="color:<?php echo $textColor; ?>; border-top-color:<?php echo $borderColor; ?>;"><?php echo nl2br(htmlspecialchars($t['description'])); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                    <?php echo renderTabPagination($tasksTotal, $limit, $tasksPage, 'tasks', 'tasks_page', $customerId); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- تب مکاتبات -->
                    <div id="tab-letters" class="tab-content <?php echo $activeTab === 'letters' ? 'active' : ''; ?>">
                        <div class="missions-container" style="display:block; padding:20px; background:#fff; border:1px solid var(--border); border-radius:16px;">
                            <div class="mc-header" style="padding:0 0 15px 0; border:none; display:flex; justify-content:space-between; align-items:center;">
                                <div class="mc-title">مکاتبات و نامه‌های اداری <span class="badge bg-light text-dark ms-2 border"><?php echo (int)$lettersTotal; ?></span></div>
                            </div>
                            <?php if(empty($customerLetters)): ?>
                                <div class="text-center py-5 text-muted"><i class="fas fa-envelope-open-text fa-3x text-gray-300 mb-3"></i><div>هیچ نامه‌ای در ارتباط با این مشتری ثبت نشده است.</div></div>
                            <?php else: ?>
                                <div class="mc-list" style="padding:0;">
                                    <div class="call-list">
                                    <?php foreach($customerLetters as $let): 
                                        $letDate = $let['registered_at'] ? (function_exists('jdate') ? jdate('Y/m/d', strtotime($let['registered_at'])) : $let['registered_at']) : (function_exists('jdate') ? jdate('Y/m/d', strtotime($let['created_at'])) : $let['created_at']);
                                        $letStatus = ($let['status'] == 'registered') ? 'ثبت نهایی' : 'پیش‌نویس';
                                        $letType = ($let['type'] == 'outgoing') ? 'صادره' : 'وارده';
                                    ?>
                                        <div class="call-card" style="align-items: center;">
                                            <div>
                                                <div class="call-meta">
                                                    <span class="call-type">نامه <?php echo $letType; ?></span>
                                                    <span>شماره: <strong dir="ltr"><?php echo $let['indicator_number'] ?: '---'; ?></strong></span>
                                                    <span>تاریخ: <?php echo $letDate; ?></span>
                                                    <span style="color:<?php echo $let['status']=='registered'?'#166534':'#475569'; ?>;"><?php echo $letStatus; ?></span>
                                                </div>
                                                <div class="fw-bold mt-2" style="color:#2563eb; font-size:1rem;"><?php echo htmlspecialchars($let['subject']); ?></div>
                                            </div>
                                            <div class="call-actions">
                                                <a href="letter_view.php?id=<?php echo $let['id']; ?>" target="_blank" class="btn btn-outline-primary btn-sm px-3 rounded-pill" style="white-space:nowrap;">مشاهده نامه</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                    <?php echo renderTabPagination($lettersTotal, $limit, $lettersPage, 'letters', 'letters_page', $customerId); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        <?php endif; ?>
    </div>
	<!-- مودال ثبت تماس و برچسب‌گذاری و... -->
    <div class="modal-overlay" id="tagModal">
        <div class="modal-content tag-modal">
            <div class="modal-header"><h5 class="m-0 fw-bold">برچسب‌گذاری مشتری</h5><button type="button" class="btn-close" style="background:transparent; border:none; font-size:1.5rem; color:#ef4444;" onclick="closeTagModal()">&times;</button></div>
            <div class="modal-body">
                <div style="margin-bottom: 15px;">
                    <input type="text" id="tagSearch" class="form-control" placeholder="جستجو در برچسب‌ها...">
                </div>
                <div class="tag-list" id="tagList"></div>
                <?php if ($canManageTags): ?>
                <div class="mt-3">
                    <div class="fw-bold mb-2">ایجاد برچسب جدید</div>
                    <div class="tag-create-grid">
                        <select id="newTagCategory" class="form-control" onchange="updateTagPalette('newTagCategory', 'tagPalette', 'newTagColor')">
                            <option value="type">نوع مشتری</option>
                            <option value="ownership">مالکیت</option>
                            <option value="goods">کالای مصرفی</option>
                            <option value="consumption">میزان مصرف</option>
                        </select>
                        <input type="text" id="newTagTitle" class="form-control" placeholder="عنوان برچسب">
                        <div class="tag-row-line">
                            <input type="color" id="newTagColor" class="tag-color-input">
                            <button class="btn btn-primary" type="button" onclick="createTag()">ثبت</button>
                        </div>
                    </div>
                    <div class="tag-color-palette" id="tagPalette"></div>
                </div>
                <div class="mt-3" id="editTagBox" style="display:none;">
                    <div class="fw-bold mb-2">ویرایش برچسب</div>
                    <div class="tag-create-grid">
                        <select id="editTagCategory" class="form-control" onchange="updateTagPalette('editTagCategory', 'editTagPalette', 'editTagColor')">
                            <option value="type">نوع مشتری</option>
                            <option value="ownership">مالکیت</option>
                            <option value="goods">کالای مصرفی</option>
                            <option value="consumption">میزان مصرف</option>
                        </select>
                        <input type="text" id="editTagTitle" class="form-control" placeholder="عنوان برچسب">
                        <div class="tag-row-line">
                            <input type="color" id="editTagColor" class="tag-color-input">
                            <button class="btn btn-success" type="button" onclick="saveTagEdit()">ذخیره</button>
                            <button class="btn btn-outline-secondary" type="button" onclick="cancelTagEdit()">انصراف</button>
                        </div>
                    </div>
                    <div class="tag-color-palette" id="editTagPalette"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/crm_opp_modals.php'; ?>

    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سیستم مدیریت مشتریان</footer>
</main>

<script>
    function switchTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById('tab-' + tabName).classList.add('active');
        event.currentTarget.classList.add('active');
    }

    const customerId = <?php echo (int)$customerId; ?>;
    let activeCustomerName = '<?php echo htmlspecialchars($customer['company_name'] ?? ''); ?>';
    
    let activeOppId = <?php 
        $stmtOppId = $pdo->prepare("SELECT id FROM crm_opportunities WHERE customer_id = ? AND status='active' ORDER BY id DESC LIMIT 1");
        $stmtOppId->execute([$customerId]);
        $defaultOppId = (int)$stmtOppId->fetchColumn();
        echo $defaultOppId ?: 0; 
    ?>;

    function apiPost(url, data) {
        const fd = new FormData();
        for (let key in data) { fd.append(key, data[key]); }
        return fetch(url, { method: 'POST', body: fd }).then(r => r.json());
    }

    function openCallModal(callId = 0) {
        document.getElementById('callModal').style.display = 'flex';
        document.getElementById('callOppId').value = activeOppId; 
        document.getElementById('callCustomerName').value = activeCustomerName;
        document.getElementById('callSubject').value = '';
        document.getElementById('callDesc').value = '';
        document.getElementById('callDate').value = '<?php echo $todayJ; ?>';
        document.getElementById('editCallId').value = callId;
        document.getElementById('callModalTitle').innerText = callId > 0 ? '📞 ویرایش تماس' : '📞 ثبت تماس جدید';
        
        const relSelect = document.getElementById('callRelatedTo');
        if(relSelect && relSelect.options.length <= 1) {
            apiPost('customer_profile.php', { action: 'get_call_related_types' })
            .then(res => {
                if(res.status === 'success') {
                    relSelect.innerHTML = res.data.map(t => `<option value="${t.title}">${t.title}</option>`).join('');
                }
            }).catch(e => console.error(e));
        }
    }

    function submitCallForm(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSubmitCall'); 
        if(btn) { btn.disabled = true; btn.innerHTML = '⏳...'; }
        
        const oppId = document.getElementById('callOppId').value;
        const callId = document.getElementById('editCallId').value;
        const subject = document.getElementById('callSubject').value;
        const status = document.getElementById('callStatus').value;
        const relatedTo = document.getElementById('callRelatedTo').value;
        const callDate = document.getElementById('callDate').value;
        const desc = document.getElementById('callDesc').value;
        const callType = document.querySelector('input[name="callType"]:checked').value;

        apiPost('customer_profile.php?id=' + customerId, { 
            action: 'save_call', 
            opp_id: oppId, 
            call_id: callId,
            customer_id: customerId, 
            subject: subject, 
            status: status, 
            related_to: relatedTo, 
            call_date: callDate, 
            call_type: callType, 
            description: desc
        }).then(res => {
            if(btn) { btn.disabled = false; btn.innerHTML = '💾 ذخیره'; }
            if(res.status === 'success') {
                closeModal('callModal');
                window.location.reload(); 
            } else { 
                alert(res.message || 'خطا در ثبت تماس'); 
            }
        }).catch(err => {
            if(btn) { btn.disabled = false; btn.innerHTML = '💾 ذخیره'; }
            alert('خطا در ارتباط با سرور.');
            console.error(err);
        });
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if(m) m.style.display = 'none';
    }

    // ==================== مدیریت برچسب‌ها ====================
    const canManageTags = <?php echo $canManageTags ? 'true' : 'false'; ?>;
    const CATEGORY_LABELS = { 'type': 'نوع مشتری', 'ownership': 'مالکیت', 'goods': 'کالای مصرفی', 'consumption': 'میزان مصرف' };
    const CATEGORY_COLORS = {
        'type': ['#fecaca', '#fca5a5', '#f87171', '#ef4444', '#dc2626', '#b91c1c'],
        'ownership': ['#bbf7d0', '#86efac', '#4ade80', '#22c55e', '#16a34a', '#15803d'],
        'goods': ['#e9d5ff', '#d8b4fe', '#c084fc', '#a855f7', '#9333ea', '#7e22ce'],
        'consumption': ['#bfdbfe', '#7dd3fc', '#38bdf8', '#0ea5e9', '#0284c7', '#0369a1']
    };
    
    let editingTagId = null;

    function updateTagPalette(catSelectId, containerId, inputId) {
        const cat = document.getElementById(catSelectId).value;
        const colorsArray = CATEGORY_COLORS[cat] || CATEGORY_COLORS['type'];
        document.getElementById(inputId).value = colorsArray[0];
        renderPalette(containerId, inputId, colorsArray);
    }

    function renderPalette(containerId, inputId, colorsArray) {
        const wrap = document.getElementById(containerId); const input = document.getElementById(inputId);
        if (!wrap || !input) return;
        wrap.innerHTML = '';
        colorsArray.forEach(c => {
            const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'color-swatch' + (input.value === c ? ' active' : ''); btn.style.background = c;
            btn.onclick = () => { input.value = c; renderPalette(containerId, inputId, colorsArray); };
            wrap.appendChild(btn);
        });
    }

    async function fetchCustomerTags() {
        try {
            const res = await fetch(`../api/customer_tags.php?customer_id=${customerId}`, { credentials: 'same-origin' });
            const data = await res.json();
            const wrap = document.getElementById('customerTags');
            if (!wrap) return;
            wrap.innerHTML = ''; 
            if (data && data.status === 'success') {
                data.tags.forEach(t => {
                    const chip = document.createElement('span'); 
                    chip.className = 'tag-chip'; 
                    chip.style.background = t.color || '#f8fafc'; 
                    chip.innerHTML = `<span>${t.title}</span>`;
                    const btn = document.createElement('button'); 
                    btn.type = 'button'; btn.className = 'tag-chip-remove'; btn.textContent = '×';
                    btn.onclick = () => removeCustomerTag(t.id);
                    chip.appendChild(btn); wrap.appendChild(chip);
                });
            }
        } catch(e){}
    }

    function openTagModal() { document.getElementById('tagModal').style.display = 'flex'; loadTags(); }
    function closeTagModal() { document.getElementById('tagModal').style.display = 'none'; }

    async function loadTags() {
        const q = (document.getElementById('tagSearch')?.value || '').trim();
        const res = await fetch(`../api/tags.php?q=${encodeURIComponent(q)}`, { credentials: 'same-origin' });
        const data = await res.json();
        const list = document.getElementById('tagList');
        if (!list) return;
        list.innerHTML = '';
        if (data && data.status === 'success') {
            if (data.tags.length === 0) {
                list.innerHTML = '<div style="text-align:center; padding:15px; color:#6b7280; font-size:0.85rem;">هیچ برچسبی یافت نشد.</div>';
                return;
            }
            const grouped = { 'type': [], 'ownership': [], 'goods': [], 'consumption': [] };
            data.tags.forEach(t => {
                const c = t.category || 'type';
                if(!grouped[c]) grouped[c] = [];
                grouped[c].push(t);
            });
            Object.keys(CATEGORY_LABELS).forEach(cat => {
                if (grouped[cat] && grouped[cat].length > 0) {
                    const catHeader = document.createElement('div');
                    catHeader.style.fontWeight = 'bold'; catHeader.style.padding = '8px 10px'; catHeader.style.backgroundColor = '#f1f5f9';
                    catHeader.style.color = '#334155'; catHeader.style.marginTop = '10px'; catHeader.style.marginBottom = '5px';
                    catHeader.style.borderRadius = '5px'; catHeader.style.fontSize = '0.9rem';
                    catHeader.textContent = CATEGORY_LABELS[cat];
                    list.appendChild(catHeader);
                    grouped[cat].forEach(t => {
                        const row = document.createElement('div'); row.className = 'tag-item';
                        let actionButtons = `<button class="btn btn-sm btn-outline-primary" type="button" onclick="assignTag(${t.id})">انتخاب</button>`;
                        if(canManageTags) {
                            actionButtons += `
                                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="editTag(${t.id}, '${t.title.replace(/'/g, "\\'")}', '${t.color || '#e2e8f0'}', '${cat}')">ویرایش</button>
                                <button class="btn btn-sm btn-outline-danger" type="button" onclick="deleteTag(${t.id})">حذف</button>
                            `;
                        }
                        row.innerHTML = `<div><span class="tag-color" style="background:${t.color || '#e2e8f0'}"></span>${t.title}</div><div class="tag-actions">${actionButtons}</div>`;
                        list.appendChild(row);
                    });
                }
            });
        }
    }

    document.getElementById('tagSearch')?.addEventListener('input', () => { loadTags(); });

    async function createTag() {
        const title = (document.getElementById('newTagTitle')?.value || '').trim();
        const color = document.getElementById('newTagColor')?.value || '#fca5a5';
        const category = document.getElementById('newTagCategory')?.value || 'type';
        if (!title) return;
        const fd = new FormData(); fd.append('action', 'create'); fd.append('title', title); fd.append('color', color); fd.append('category', category);
        if (window.__CSRF_TOKEN) fd.append('csrf_token', window.__CSRF_TOKEN);
        const res = await fetch('../api/tags.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data && data.status === 'success') { document.getElementById('newTagTitle').value = ''; loadTags(); updateTagPalette('newTagCategory', 'tagPalette', 'newTagColor'); }
    }

    async function editTag(id, title, color, category) {
        editingTagId = id;
        const box = document.getElementById('editTagBox'); if (box) box.style.display = 'block';
        if (document.getElementById('editTagTitle')) document.getElementById('editTagTitle').value = title;
        if (document.getElementById('editTagColor')) document.getElementById('editTagColor').value = color || '#fca5a5';
        if (document.getElementById('editTagCategory')) document.getElementById('editTagCategory').value = category || 'type';
        updateTagPalette('editTagCategory', 'editTagPalette', 'editTagColor');
        const createBox = document.getElementById('newTagTitle')?.closest('.mt-3');
        if (createBox) createBox.style.display = 'none';
    }

    async function saveTagEdit() {
        if (!editingTagId) return;
        const title = (document.getElementById('editTagTitle')?.value || '').trim();
        const color = document.getElementById('editTagColor')?.value || '#fca5a5';
        const category = document.getElementById('editTagCategory')?.value || 'type';
        if (!title) return;
        const fd = new FormData(); fd.append('action', 'update'); fd.append('id', String(editingTagId)); fd.append('title', title); fd.append('color', color); fd.append('category', category);
        if (window.__CSRF_TOKEN) fd.append('csrf_token', window.__CSRF_TOKEN);
        const res = await fetch('../api/tags.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data && data.status === 'success') { cancelTagEdit(); loadTags(); fetchCustomerTags(); }
    }

    function cancelTagEdit() {
        editingTagId = null;
        const box = document.getElementById('editTagBox'); if (box) box.style.display = 'none';
        const createBox = document.getElementById('newTagTitle')?.closest('.mt-3');
        if (createBox) createBox.style.display = 'block';
    }

    async function deleteTag(id) {
        if (!confirm('آیا از حذف این برچسب مطمئن هستید؟')) return;
        const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', String(id));
        if (window.__CSRF_TOKEN) fd.append('csrf_token', window.__CSRF_TOKEN);
        const res = await fetch('../api/tags.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data && data.status === 'success') { loadTags(); fetchCustomerTags(); }
    }

    async function assignTag(tagId) {
        const fd = new FormData(); fd.append('action', 'assign'); fd.append('customer_id', String(customerId)); fd.append('tag_id', String(tagId));
        if (window.__CSRF_TOKEN) fd.append('csrf_token', window.__CSRF_TOKEN);
        const res = await fetch('../api/customer_tags.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data && data.status === 'success') { fetchCustomerTags(); loadTags(); }
    }

    async function removeCustomerTag(tagId) {
        const fd = new FormData(); fd.append('action', 'remove'); fd.append('customer_id', String(customerId)); fd.append('tag_id', String(tagId));
        if (window.__CSRF_TOKEN) fd.append('csrf_token', window.__CSRF_TOKEN);
        const res = await fetch('../api/customer_tags.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data && data.status === 'success') { fetchCustomerTags(); loadTags(); }
    }

    if (document.getElementById('newTagCategory')) { updateTagPalette('newTagCategory', 'tagPalette', 'newTagColor'); }
    fetchCustomerTags();

    const highlightId = <?php echo (int)$highlightCallId; ?>;
    if (highlightId > 0) {
        const target = document.getElementById('call-' + highlightId);
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>