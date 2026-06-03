<?php
/*
 * فایل: public_html/admin/crm_opportunities_kanban.php
 * بلوک 1: توابع پایه، دیتابیس و پردازش‌های AJAX (رفع باگ حذفیات و فرم تمام صفحه)
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

$dbPath = __DIR__ . '/../../includes/db.php';
$funcPath = __DIR__ . '/../../includes/functions.php';

if (!file_exists($dbPath) || !file_exists($funcPath)) die("خطا: فایل‌های سیستمی یافت نشدند.");
require_once $dbPath;
require_once $funcPath;

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax || isset($_POST['action'])) { 
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json'); 
        echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است.']); 
        exit; 
    }
    header("Location: ../login.php"); exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$isAdminOrManager = in_array($userRole, ['admin', 'manager', 'sales_manager']);

$stmtMe = $pdo->prepare("SELECT username, first_name, last_name, profile_image FROM users WHERE id = ?");
$stmtMe->execute([$userId]);
$me = $stmtMe->fetch(PDO::FETCH_ASSOC);

$myUsername = trim($me['username'] ?? '');
$myLastName = trim($me['last_name'] ?? ''); 
$myFullName = trim(($me['first_name'] ?? '') . ' ' . $myLastName);

// ==================== ارتقای خودکار دیتابیس ====================
try {
    $pdo->exec("ALTER TABLE `crm_opportunities` ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) DEFAULT 'active'");
    $pdo->exec("ALTER TABLE `crm_opportunities` ADD COLUMN IF NOT EXISTS `selected_weeks` VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE `crm_opportunity_stage_logs` ADD COLUMN IF NOT EXISTS `accumulated_time` INT(11) DEFAULT 0");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_opportunity_calls` (`id` INT(11) NOT NULL AUTO_INCREMENT, `opportunity_id` INT(11) NOT NULL, `user_id` INT(11) NOT NULL, `subject` VARCHAR(255) NOT NULL, `status` VARCHAR(50) NOT NULL, `related_to` VARCHAR(50) NOT NULL, `call_date` DATE NOT NULL, `call_type` ENUM('inbound', 'outbound') NOT NULL, `description` TEXT, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_opportunity_tasks` (`id` INT(11) NOT NULL AUTO_INCREMENT, `opportunity_id` INT(11) NOT NULL, `user_id` INT(11) NOT NULL, `subject` VARCHAR(255) NOT NULL, `deadline` DATE NOT NULL, `status` VARCHAR(50) DEFAULT 'pending', `description` TEXT, `file_path` VARCHAR(255) DEFAULT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_opportunity_notes` (`id` INT(11) NOT NULL AUTO_INCREMENT, `opportunity_id` INT(11) NOT NULL, `user_id` INT(11) NOT NULL, `note_text` TEXT NOT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
} catch (Throwable $e) {}

// ==================== توابع داخلی ====================
if (!function_exists('crm_fa_to_en')) { function crm_fa_to_en($str) { return str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], ['0','1','2','3','4','5','6','7','8','9'], $str); } }
if (!function_exists('crm_jalali_to_gregorian')) {
    function crm_jalali_to_gregorian($jy, $jm, $jd) {
        $jy=(int)$jy; $jm=(int)$jm; $jd=(int)$jd; $jy+=1595;
        $days=-355668+(365*$jy)+((int)($jy/33)*8)+(int)((($jy%33)+3)/4)+$jd+(($jm<7)?($jm-1)*31:(($jm-7)*30)+186);
        $gy=400*(int)($days/146097); $days%=146097;
        if($days>36524){$days--;$gy+=100*(int)($days/36524);$days%=36524;if($days>=365)$days++;}
        $gy+=4*(int)($days/1461);$days%=1461; if($days>365){$gy+=(int)(($days-1)/365);$days=($days-1)%365;}
        $gd=$days+1; $sal_a=[0,31,(($gy%4==0&&$gy%100!=0)||($gy%400==0))?29:28,31,30,31,30,31,31,30,31,30,31];
        for($gm=0;$gm<13;$gm++){$v=$sal_a[$gm];if($gd<=$v)break;$gd-=$v;} return [$gy,$gm,$gd];
    }
}
if (!function_exists('crm_greg_to_jalali')) {
    function crm_greg_to_jalali($gy, $gm, $gd, $mod='') {
        $g_d_m=[0,31,59,90,120,151,181,212,243,273,304,334]; $gy2=($gm>2)?($gy+1):$gy;
        $days=355666+(365*$gy)+((int)(($gy2+3)/4))-((int)(($gy2+99)/100))+((int)(($gy2+399)/400))+$jd+$g_d_m[$gm-1];
        $jy=-1595+(33*((int)($days/12053))); $days%=12053; $jy+=4*((int)($days/1461)); $days%=1461;
        if($days>365){$jy+=(int)(($days-1)/365);$days=($days-1)%365;}
        $jm=($days<186)?1+(int)($days/31):7+(int)(($days-186)/30); $jd=1+(($days<186)?($days%31):(($days-186)%30));
        return ($mod=='')?[$jy,$jm,$jd]:$jy.$mod.sprintf("%02d",$jm).$mod.sprintf("%02d",$jd);
    }
}
if (!function_exists('crm_get_auto_assignee')) {
    function crm_get_auto_assignee($pdo, $customerId, $defaultUserId) {
        $stmt1 = $pdo->prepare("SELECT u.id FROM customers c INNER JOIN customer_followers cf ON c.company_num = cf.company_num INNER JOIN users u ON (u.username = cf.username OR cf.full_name LIKE CONCAT('%', u.last_name, '%')) WHERE c.id = ? AND u.status = 'active' LIMIT 1");
        $stmt1->execute([$customerId]); $followerId = $stmt1->fetchColumn(); if ($followerId) return (int)$followerId;
        $stmt2 = $pdo->prepare("SELECT cup.user_id FROM customers c INNER JOIN customer_addresses ca ON c.company_num = ca.company_num INNER JOIN crm_user_provinces cup ON ca.state = cup.province_name WHERE c.id = ? LIMIT 1");
        $stmt2->execute([$customerId]); $provinceUserId = $stmt2->fetchColumn(); if ($provinceUserId) return (int)$provinceUserId;
        return $defaultUserId;
    }
}

$currentFy = null; $fyWhere = ""; $fyParams = [];
try {
    $stmtFy = $pdo->query("SELECT start_date, end_date FROM fiscal_years WHERE is_current = 1 AND status = 'active' LIMIT 1");
    if ($stmtFy) {
        $currentFy = $stmtFy->fetch(PDO::FETCH_ASSOC);
        if ($currentFy) {
            $fyWhere = " AND (o.created_at >= ? AND o.created_at <= ?) ";
            $fyParams = [$currentFy['start_date'] . ' 00:00:00', $currentFy['end_date'] . ' 23:59:59'];
        }
    }
} catch (Exception $e) {}

// ==================== پردازش AJAX ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'get_call_related_types') {
        try {
            $types = $pdo->query("SELECT id, title FROM crm_call_related_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $types]);
        } catch (Exception $e) { echo json_encode(['status' => 'error']); } exit;
    }

    if ($action === 'get_opp_full_details') {
        $oppId = (int)$_POST['opp_id'];
        try {
            $stmt = $pdo->prepare("SELECT o.*, c.company_name, b.name as board_name, s.name as stage_name, u.first_name, u.last_name, u.profile_image,
                                   (SELECT GROUP_CONCAT(CONCAT(t.title, '::', IFNULL(t.color, '#e0f2fe')) SEPARATOR '||') 
                                    FROM customer_tag_links ctl JOIN tags t ON ctl.tag_id = t.id 
                                    WHERE ctl.customer_id = c.id) as tags_data
                                   FROM crm_opportunities o 
                                   JOIN customers c ON o.customer_id = c.id 
                                   JOIN crm_boards b ON o.board_id = b.id
                                   JOIN crm_board_stages s ON o.stage_id = s.id
                                   LEFT JOIN crm_opportunity_assignees coa ON o.id = coa.opportunity_id 
                                   LEFT JOIN users u ON coa.user_id = u.id 
                                   WHERE o.id = ? LIMIT 1");
            $stmt->execute([$oppId]); $opp = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmtLogs = $pdo->prepare("SELECT stage_id, entered_at, accumulated_time FROM crm_opportunity_stage_logs WHERE opportunity_id = ?");
            $stmtLogs->execute([$oppId]); $logs = []; while($row = $stmtLogs->fetch(PDO::FETCH_ASSOC)) { $logs[$row['stage_id']] = $row; }
            
            $stmtCom = $pdo->prepare("SELECT oc.*, DATE_FORMAT(oc.created_at, '%H:%i') as chat_time, u.first_name, u.last_name, u.profile_image, (SELECT comment_text FROM crm_opportunity_comments WHERE id = oc.reply_to_id LIMIT 1) as reply_text FROM crm_opportunity_comments oc JOIN users u ON oc.user_id = u.id WHERE oc.opportunity_id = ? ORDER BY oc.id ASC");
            $stmtCom->execute([$oppId]); $comments = $stmtCom->fetchAll(PDO::FETCH_ASSOC);
            foreach ($comments as &$c) { $c['is_me'] = ($c['user_id'] == $userId); }

            $stmtCalls = $pdo->prepare("SELECT c.*, u.first_name, u.last_name FROM crm_opportunity_calls c JOIN users u ON c.user_id = u.id WHERE c.opportunity_id = ? ORDER BY c.id DESC");
            $stmtCalls->execute([$oppId]); $calls = $stmtCalls->fetchAll(PDO::FETCH_ASSOC);
            foreach ($calls as &$c) { $p = explode('-', $c['call_date']); $c['call_date_fa'] = count($p)==3 ? crm_greg_to_jalali($p[0],$p[1],$p[2],'/') : $c['call_date']; }

            $stmtMissions = $pdo->prepare("SELECT mi.id, mt.title as subject, mi.mission_date as ev_date, mi.description, mr.status, u.first_name, u.last_name 
                                           FROM mission_items mi 
                                           JOIN mission_requests mr ON mi.request_id = mr.id 
                                           LEFT JOIN mission_types mt ON mi.type_id = mt.id
                                           LEFT JOIN users u ON mr.user_id = u.id
                                           WHERE mi.customer_id = ? ORDER BY mi.mission_date DESC");
            $stmtMissions->execute([$opp['customer_id']]);
            $missions = $stmtMissions->fetchAll(PDO::FETCH_ASSOC);
            foreach ($missions as &$m) { $p = explode('-', $m['ev_date']); $m['mission_date_fa'] = count($p)==3 ? crm_greg_to_jalali($p[0],$p[1],$p[2],'/') : $m['ev_date']; }

            $stmtTasks = $pdo->prepare("SELECT t.*, u.first_name, u.last_name FROM crm_opportunity_tasks t JOIN users u ON t.user_id = u.id WHERE t.opportunity_id = ? ORDER BY t.id DESC");
            $stmtTasks->execute([$oppId]); $tasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tasks as &$t) { $p = explode('-', $t['deadline']); $t['deadline_fa'] = count($p)==3 ? crm_greg_to_jalali($p[0],$p[1],$p[2],'/') : $t['deadline']; }

            $stmtNotes = $pdo->prepare("SELECT n.*, DATE_FORMAT(n.created_at, '%Y-%m-%d') as ndate, u.first_name, u.last_name FROM crm_opportunity_notes n JOIN users u ON n.user_id = u.id WHERE n.opportunity_id = ? ORDER BY n.id DESC");
            $stmtNotes->execute([$oppId]); $notes = $stmtNotes->fetchAll(PDO::FETCH_ASSOC);
            foreach ($notes as &$n) { $p = explode('-', $n['ndate']); $n['ndate_fa'] = count($p)==3 ? crm_greg_to_jalali($p[0],$p[1],$p[2],'/') : $n['ndate']; }

            $stmtAct = $pdo->prepare("SELECT a.*, u.first_name, u.last_name FROM crm_opportunity_activities a JOIN users u ON a.user_id = u.id WHERE a.opportunity_id = ? ORDER BY a.id DESC");
            $stmtAct->execute([$oppId]); $activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC);
            foreach ($activities as &$a) { $dt = explode(' ', $a['created_at']); $p = explode('-', $dt[0]); $a['created_at_fa'] = count($p)==3 ? crm_greg_to_jalali($p[0],$p[1],$p[2],'/') . ' ' . substr($dt[1], 0, 5) : $a['created_at']; }

            // پیشفاکتورهای مرتبط
            $quotes = [];
            try {
                $stmtQ = $pdo->prepare("SELECT id, quote_number, total_amount, status, quote_date FROM fin_preinvoices WHERE opportunity_id=? AND is_deleted=0 ORDER BY id DESC");
                $stmtQ->execute([$oppId]);
                $rawQuotes = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rawQuotes as &$qr) {
                    $p = explode('-', $qr['quote_date']);
                    $qr['date_fa'] = count($p)==3 ? crm_greg_to_jalali($p[0],$p[1],$p[2],'/') : $qr['quote_date'];
                    $qr['total_fmt'] = number_format((int)$qr['total_amount']);
                }
                unset($qr);
                $quotes = $rawQuotes;
            } catch (Throwable $eQ) { $quotes = []; }

            echo json_encode(['status' => 'success', 'opportunity' => $opp, 'logs' => $logs, 'comments' => $comments, 'calls' => $calls, 'missions' => $missions, 'tasks' => $tasks, 'notes' => $notes, 'activities' => $activities, 'quotes' => $quotes]);
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); } exit;
    }

    if ($action === 'save_call') {
        $oppId = (int)$_POST['opp_id']; $callId = (int)($_POST['call_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? ''); $status = trim($_POST['status'] ?? '');
        $relatedTo = trim($_POST['related_to'] ?? ''); $callDateFa = trim($_POST['call_date'] ?? '');
        $callType = trim($_POST['call_type'] ?? 'outbound'); $desc = trim($_POST['description'] ?? '');

        if (!$oppId || empty($subject)) { echo json_encode(['status' => 'error', 'message' => 'اطلاعات ناقص است']); exit; }
        $gDate = date('Y-m-d'); 
        if(!empty($callDateFa)){ $d=explode('/', crm_fa_to_en($callDateFa)); if(count($d)==3) $gDate=implode('-',crm_jalali_to_gregorian($d[0],$d[1],$d[2])); }
        try {
            $pdo->beginTransaction();
            if ($callId > 0) {
                $pdo->prepare("UPDATE crm_opportunity_calls SET status=?, related_to=?, call_date=?, call_type=?, description=? WHERE id=?")->execute([$status, $relatedTo, $gDate, $callType, $desc, $callId]);
                $pdo->prepare("INSERT INTO crm_opportunity_activities (opportunity_id, user_id, activity_type, description) VALUES (?, ?, 'call', ?)")->execute([$oppId, $userId, "📞 ویرایش تماس: $subject ($status)"]);
            } else {
                $pdo->prepare("INSERT INTO crm_opportunity_calls (opportunity_id, user_id, subject, status, related_to, call_date, call_type, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$oppId, $userId, $subject, $status, $relatedTo, $gDate, $callType, $desc]);
                $pdo->prepare("INSERT INTO crm_opportunity_activities (opportunity_id, user_id, activity_type, description) VALUES (?, ?, 'call', ?)")->execute([$oppId, $userId, "📞 ثبت تماس: $subject ($status)"]);
            }
            $pdo->commit(); echo json_encode(['status' => 'success']);
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['status' => 'error']); } exit;
    }
?>
<?php
    if ($action === 'save_task_bulk') {
        $oppId = (int)$_POST['opp_id'];
        $activityTitle = trim($_POST['activity_title'] ?? 'فعالیت عمومی وظایف');
        $titles = $_POST['task_titles'] ?? [];
        $deadlines = $_POST['task_deadlines'] ?? [];
        $statuses = $_POST['task_statuses'] ?? [];
        $descriptions = $_POST['task_descriptions'] ?? [];

        if (!$oppId || empty($titles)) { echo json_encode(['status' => 'error', 'message' => 'اطلاعات ارسالی ناقص است']); exit; }

        try {
            $pdo->beginTransaction();
            $uploadDir = __DIR__ . '/../../public_html/uploads/crm_tasks/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);

            foreach ($titles as $index => $title) {
                $title = trim($title); if (empty($title)) continue;
                $desc = trim($descriptions[$index] ?? '');
                $status = trim($statuses[$index] ?? 'برنامه ریزی شده');
                $dateFa = trim($deadlines[$index] ?? '');
                
                $gDate = date('Y-m-d');
                if (!empty($dateFa)) { $d = explode('/', crm_fa_to_en($dateFa)); if (count($d) == 3) $gDate = implode('-', crm_jalali_to_gregorian($d[0], $d[1], $d[2])); }

                // آپلود فایل اختصاصی ردیف
                $fileName = null;
                if (isset($_FILES['task_files']['name'][$index]) && $_FILES['task_files']['error'][$index] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['task_files']['name'][$index], PATHINFO_EXTENSION);
                    $fileName = uniqid('task_') . '_' . $index . '.' . $ext;
                    move_uploaded_file($_FILES['task_files']['tmp_name'][$index], $uploadDir . $fileName);
                }

                $fullSubject = "[$activityTitle] " . $title;
                $stmt = $pdo->prepare("INSERT INTO crm_opportunity_tasks (opportunity_id, user_id, subject, deadline, status, description, file_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$oppId, $userId, $fullSubject, $gDate, $status, $desc, $fileName]);

                $pdo->prepare("INSERT INTO crm_opportunity_activities (opportunity_id, user_id, activity_type, description) VALUES (?, ?, 'task', ?)")->execute([$oppId, $userId, "📝 ثبت وظیفه: $title ($status)"]);
            }
            $pdo->commit(); echo json_encode(['status' => 'success']);
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); } exit;
    }

    if ($action === 'save_note') {
        $oppId = (int)$_POST['opp_id']; $note = trim($_POST['note_text'] ?? '');
        if (!$oppId || !$note) { echo json_encode(['status' => 'error']); exit; }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO crm_opportunity_notes (opportunity_id, user_id, note_text) VALUES (?, ?, ?)")->execute([$oppId, $userId, $note]);
            $pdo->prepare("INSERT INTO crm_opportunity_activities (opportunity_id, user_id, activity_type, description) VALUES (?, ?, 'note', ?)")->execute([$oppId, $userId, "📋 ثبت یادداشت شخصی"]);
            $pdo->commit(); echo json_encode(['status' => 'success']);
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['status' => 'error']); } exit;
    }

    if ($action === 'save_comment') {
        $oppId = (int)$_POST['opp_id']; $comment = trim($_POST['comment'] ?? ''); $replyTo = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
        $file = $_FILES['file'] ?? null; $type = $_POST['type'] ?? 'text'; 
        if (empty($comment) && empty($file)) { echo json_encode(['status' => 'error']); exit; }
        $filePath = null; $fileType = null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION); if(empty($ext) && $type === 'voice') $ext = 'webm';
            $fileName = uniqid('opp_') . '.' . $ext; $uploadDir = __DIR__ . '/../../public_html/uploads/chat/';
            if(!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            if(move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)){ $filePath = $fileName; $fileType = $type === 'voice' ? 'voice' : 'file'; }
        }
        $pdo->prepare("INSERT INTO crm_opportunity_comments (opportunity_id, user_id, comment_text, file_path, file_type, reply_to_id) VALUES (?, ?, ?, ?, ?, ?)")->execute([$oppId, $userId, $comment, $filePath, $fileType, $replyTo]);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'get_province_customers') {
        $province = trim($_POST['province'] ?? ''); $boardId = (int)($_POST['board_id'] ?? 0);
        $cQuery = "SELECT c.id, c.company_name, 
                   (SELECT COUNT(*) FROM crm_opportunities WHERE customer_id = c.id AND board_id = ? AND status='active') as is_in_board, 
                   (SELECT GROUP_CONCAT(CONCAT(t.title, '::', IFNULL(t.color, '#e0f2fe')) SEPARATOR '||') FROM customer_tag_links ctl JOIN tags t ON ctl.tag_id = t.id WHERE ctl.customer_id = c.id) as tags_data, 
                   (SELECT GROUP_CONCAT(cf.full_name SEPARATOR '، ') FROM customer_followers cf WHERE cf.company_num = c.company_num) as follower_names 
                   FROM customers c INNER JOIN customer_addresses ca ON c.company_num = ca.company_num";
        $cParams = [$boardId];
        if (!$isAdminOrManager) { $cQuery .= " INNER JOIN customer_followers cf_main ON c.company_num = cf_main.company_num INNER JOIN crm_user_provinces cup ON ca.state = cup.province_name WHERE cup.user_id = ? AND (cf_main.username = ? OR cf_main.full_name LIKE ? OR cf_main.full_name LIKE ?) AND ca.state = ?"; array_push($cParams, $userId, $myUsername, "%{$myLastName}%", "%{$myFullName}%", $province);
        } else { $cQuery .= " WHERE ca.state = ? "; $cParams[] = $province; }
        $cQuery .= " GROUP BY c.id ORDER BY c.company_name ASC";
        try { $stmtC = $pdo->prepare($cQuery); $stmtC->execute($cParams); echo json_encode(['status' => 'success', 'customers' => $stmtC->fetchAll(PDO::FETCH_ASSOC)]); } catch (Exception $e) { echo json_encode(['status' => 'error']); } exit;
    }

    if ($action === 'quick_add_opportunity') {
        $boardId = (int)$_POST['board_id']; $customersData = json_decode($_POST['customers_data'] ?? '[]', true);
        if (!$boardId || empty($customersData)) { echo json_encode(['status' => 'error']); exit; }
        try {
            $pdo->beginTransaction();
            $stmtOpp = $pdo->prepare("INSERT INTO crm_opportunities (board_id, stage_id, customer_id, title, created_by, status, selected_weeks) VALUES (?, ?, ?, ?, ?, 'active', ?)");
            $stmtAss = $pdo->prepare("INSERT INTO crm_opportunity_assignees (opportunity_id, user_id) VALUES (?, ?)");
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM crm_opportunities WHERE board_id = ? AND customer_id = ? AND status='active'");
            $stmtLog = $pdo->prepare("INSERT INTO crm_opportunity_stage_logs (opportunity_id, stage_id, entered_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE entered_at = NOW()");
            
            foreach ($customersData as $item) {
                $cId = (int)$item['id']; $sId = (int)$item['stage_id'];
                $selWeeks = isset($item['selected_weeks']) && !empty($item['selected_weeks']) ? json_encode($item['selected_weeks']) : null;
                $stmtCheck->execute([$boardId, $cId]); if ($stmtCheck->fetchColumn() > 0) continue; 
                $stmtC = $pdo->prepare("SELECT company_name FROM customers WHERE id = ?"); $stmtC->execute([$cId]); $cName = $stmtC->fetchColumn() ?: 'مشتری';
                $stmtOpp->execute([$boardId, $sId, $cId, "فرصت فروش - " . $cName, $userId, $selWeeks]); $oppId = $pdo->lastInsertId();
                $expertId = crm_get_auto_assignee($pdo, $cId, $userId); $stmtAss->execute([$oppId, $expertId]);
                $stmtLog->execute([$oppId, $sId]); 
            }
            $pdo->commit(); echo json_encode(['status' => 'success']);
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['status' => 'error']); } exit;
    }

    if ($action === 'delete_opportunity') { $pdo->prepare("UPDATE crm_opportunities SET status = 'deleted' WHERE id = ?")->execute([(int)$_POST['opp_id']]); echo json_encode(['status' => 'success']); exit; }
    if ($action === 'convert_opportunity') { $pdo->prepare("UPDATE crm_opportunities SET status = 'completed' WHERE id = ?")->execute([(int)$_POST['opp_id']]); echo json_encode(['status' => 'success']); exit; }
    
    if ($action === 'move_card') {
        $oppId = (int)$_POST['opp_id']; $stageId = (int)$_POST['stage_id'];
        $stmtOld = $pdo->prepare("SELECT stage_id FROM crm_opportunities WHERE id = ?"); $stmtOld->execute([$oppId]); $oldStageId = $stmtOld->fetchColumn();
        if ($oldStageId && $oldStageId != $stageId) {
            $pdo->prepare("UPDATE crm_opportunity_stage_logs SET accumulated_time = accumulated_time + TIMESTAMPDIFF(SECOND, entered_at, NOW()) WHERE opportunity_id = ? AND stage_id = ?")->execute([$oppId, $oldStageId]);
        }
        $pdo->prepare("UPDATE crm_opportunities SET stage_id = ?, updated_at = NOW() WHERE id = ?")->execute([$stageId, $oppId]);
        $pdo->prepare("INSERT INTO crm_opportunity_stage_logs (opportunity_id, stage_id, entered_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE entered_at = NOW()")->execute([$oppId, $stageId]);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'delete_comment') { $pdo->prepare("DELETE FROM crm_opportunity_comments WHERE id = ? AND user_id = ?")->execute([(int)$_POST['msg_id'], $userId]); echo json_encode(['status'=>'success']); exit; }
    if ($action === 'edit_comment') { $pdo->prepare("UPDATE crm_opportunity_comments SET comment_text = ?, is_edited = 1 WHERE id = ? AND user_id = ?")->execute([trim($_POST['message'] ?? ''), (int)$_POST['msg_id'], $userId]); echo json_encode(['status'=>'success']); exit; }
    if ($action === 'pin_comment') { $pdo->prepare("UPDATE crm_opportunity_comments SET is_pinned = 1 WHERE id = ?")->execute([(int)$_POST['msg_id']]); echo json_encode(['status'=>'success']); exit; }
    if ($action === 'unpin_comment') { $pdo->prepare("UPDATE crm_opportunity_comments SET is_pinned = 0 WHERE id = ?")->execute([(int)$_POST['msg_id']]); echo json_encode(['status'=>'success']); exit; }
    if ($action === 'react_comment') { $msgId = (int)$_POST['msg_id']; $stmt = $pdo->prepare("SELECT reactions FROM crm_opportunity_comments WHERE id = ?"); $stmt->execute([$msgId]); $reacts = json_decode($stmt->fetchColumn() ?: '[]', true); $reacts[] = ['user_id' => $userId, 'emoji' => trim($_POST['emoji'])]; $pdo->prepare("UPDATE crm_opportunity_comments SET reactions = ? WHERE id = ?")->execute([json_encode($reacts), $msgId]); echo json_encode(['status'=>'success']); exit; }
}

// ==================== واکشی داده‌های پایه کانبان ====================
$boards = $pdo->query("SELECT * FROM crm_boards ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$currentBoardId = isset($_GET['board_id']) ? (int)$_GET['board_id'] : ($boards[0]['id'] ?? 0);
$stages = []; if ($currentBoardId > 0) { $stages = $pdo->query("SELECT * FROM crm_board_stages WHERE board_id = $currentBoardId ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC); }

if (!$isAdminOrManager) {
    $provQuery = "SELECT cup.province_name, COUNT(DISTINCT c.id) as customer_count FROM crm_user_provinces cup INNER JOIN customer_addresses ca ON cup.province_name = ca.state INNER JOIN customers c ON ca.company_num = c.company_num INNER JOIN customer_followers cf_main ON c.company_num = cf_main.company_num WHERE cup.user_id = ? AND (cf_main.username = ? OR cf_main.full_name LIKE ? OR cf_main.full_name LIKE ?) GROUP BY cup.province_name ORDER BY cup.province_name ASC";
    $stmtProv = $pdo->prepare($provQuery); $stmtProv->execute([$userId, $myUsername, "%{$myLastName}%", "%{$myFullName}%"]);
} else { $provinceSummary = $pdo->query("SELECT ca.state as province_name, COUNT(DISTINCT c.id) as customer_count FROM customer_addresses ca JOIN customers c ON ca.company_num = c.company_num WHERE ca.state IS NOT NULL AND ca.state != '' GROUP BY ca.state ORDER BY ca.state ASC")->fetchAll(PDO::FETCH_ASSOC); }
if(isset($stmtProv)) $provinceSummary = $stmtProv->fetchAll(PDO::FETCH_ASSOC);

$allTags = $pdo->query("SELECT id, title FROM tags ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$allExperts = $pdo->query("SELECT DISTINCT u.id, u.first_name, u.last_name FROM users u JOIN crm_opportunity_assignees coa ON u.id = coa.user_id JOIN crm_opportunities o ON coa.opportunity_id = o.id WHERE o.status='active'")->fetchAll(PDO::FETCH_ASSOC);

$opportunities = [];
if ($currentBoardId > 0) {
    $oppQuery = "SELECT o.*, c.company_name, u.id as expert_id, u.first_name as expert_fname, u.last_name as expert_lname, u.profile_image as expert_image,
                (SELECT GROUP_CONCAT(tag_id) FROM customer_tag_links WHERE customer_id = o.customer_id) as tag_ids
                FROM crm_opportunities o 
                JOIN customers c ON o.customer_id = c.id 
                LEFT JOIN (SELECT opportunity_id, MIN(user_id) as assigned_user_id FROM crm_opportunity_assignees GROUP BY opportunity_id) coa ON o.id = coa.opportunity_id 
                LEFT JOIN users u ON coa.assigned_user_id = u.id 
                WHERE o.board_id = ? AND o.status='active' $fyWhere";
    
    $oppParams = array_merge([$currentBoardId], $fyParams);
    if (!$isAdminOrManager) { 
        $oppQuery .= " AND (o.created_by = ? OR EXISTS (SELECT 1 FROM crm_opportunity_assignees coa2 WHERE coa2.opportunity_id = o.id AND coa2.user_id = ?)) "; 
        array_push($oppParams, $userId, $userId); 
    }
    $oppQuery .= " ORDER BY o.updated_at DESC";
    $stmtOpp = $pdo->prepare($oppQuery); $stmtOpp->execute($oppParams);
    foreach ($stmtOpp->fetchAll(PDO::FETCH_ASSOC) as $o) { $opportunities[$o['stage_id']][] = $o; }
}
$stagesJsonForJs = json_encode($stages);

$pageTitle = 'کارتابل فرصت‌های فروش'; 
$basePath = '../';

$extraCss = '
<style>
    @font-face { font-family: "Vazirmatn"; src: url("../assets/fonts/Vazirmatn-Regular.ttf") format("truetype"); font-weight: normal; font-style: normal; font-display: swap; }
    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    .main-content { background: #f1f5f9; min-height: calc(100vh - 70px); padding-top: 20px; }
    .page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; background: white; padding: 15px 20px; border-radius: 12px; border: 1px solid #e2e8f0; }
    .page-title { font-weight: bold; font-size: 1.2rem; color: #1e293b; }
    .btn { padding: 8px 15px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: 0.2s;}
    .btn-primary { background: #2563eb !important; color: white !important; } .btn-primary:hover { background: #1d4ed8 !important; }
    .btn-success { background: #10b981; color: white; }
    .btn-outline { background: transparent; border: 1px solid #cbd5e1; color: #475569; }
    
    .kanban-filter-bar { background: white; padding: 10px 15px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filter-input { flex: 1; min-width: 150px; max-width: 250px; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-size: 0.85rem; }
    .filter-select-kanban { width: 160px; padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; background: white; font-size: 0.85rem; }
    
    .custom-select-wrapper { position: relative; width: 180px; }
    .custom-select-box { padding: 8px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; font-size: 0.85rem; display:flex; justify-content:space-between; align-items:center; }
    .custom-select-options { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 50; display: none; max-height: 200px; overflow-y: auto; padding: 5px; margin-top: 5px; }
    .custom-select-options label { display: block; padding: 6px 10px; font-size: 0.85rem; cursor: pointer; border-radius:4px; margin-bottom:2px; transition:0.2s;}
    .custom-select-options label:hover { background: #f1f5f9; color: #2563eb; }
    .custom-select-options input { margin-left: 8px; }
    
    .btn-clear-filter { background: #fef2f2; color: #ef4444; border: 1px solid #fca5a5; padding: 8px 12px; border-radius: 8px; font-size: 0.85rem; cursor: pointer; font-weight: bold; }
    .btn-clear-filter:hover { background: #fee2e2; }
    
    .kanban-wrapper { display: flex; overflow-x: auto; gap: 20px; padding-bottom: 20px; min-height: calc(100vh - 250px); align-items: flex-start; }
    .kanban-wrapper::-webkit-scrollbar { height: 8px; }
    .kanban-wrapper::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    
    .kanban-column { flex: 0 0 320px; background: #f8fafc; border-radius: 12px; display: flex; flex-direction: column; max-height: calc(100vh - 180px); border: 1px solid #e2e8f0; }
    .kanban-column-header { padding: 15px; background: white; border-radius: 12px 12px 0 0; position: sticky; top: 0; z-index: 10;}
    .col-title { font-weight: 800; font-size: 1rem; display: flex; justify-content: space-between; }
    .col-count { padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; }
    
    .kanban-quick-add-area { padding: 10px 10px 0 10px; position: sticky; top: 0; z-index: 5; background: #f8fafc; }
    .btn-quick-add { width: 100%; background: transparent; border: 2px dashed #cbd5e1; color: #64748b; padding: 10px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; }
    .btn-quick-add:hover { background: #eff6ff; border-color: #3b82f6; color: #2563eb; }
    
    .kanban-column-body { flex: 1; overflow-y: auto; padding: 10px; min-height: 200px; }
    .kanban-column-body.drag-over { background: #e0f2fe; border: 2px dashed #3b82f6; border-radius: 8px; }
    
    .k-card { background: white; border-radius: 10px; padding: 15px; margin-bottom: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; cursor: grab; position: relative; user-select: none; transition: 0.2s; }
    .k-card:hover { border-color: #3b82f6; box-shadow: 0 4px 10px rgba(59,130,246,0.1); }
    .k-card.dragging { opacity: 0.4; transform: scale(0.98); border: 2px dashed #3b82f6; }
    
    .c-title { font-weight: bold; color: #0f172a; font-size: 0.95rem; margin-bottom: 6px;}
    .c-customer { font-size: 0.8rem; margin-bottom: 8px; font-weight: bold; white-space: normal; word-wrap: break-word; }
    .c-expert { display: flex; align-items: center; gap: 8px; font-size: 0.75rem; color: #475569; margin-bottom: 8px; background: #f1f5f9; padding: 4px 8px; border-radius: 6px; }
    .c-expert img { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; }
    
    .card-actions-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.96); display: none; justify-content: center; align-items: center; gap: 6px; border-radius: 10px; z-index: 30; border: 1px solid #cbd5e1; }
    
    .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; align-items:flex-start; justify-content:center; }
    .modal-content { background:#fff; width:100%; max-width:100%; border-radius:0; overflow:hidden; box-shadow: none; display: flex; flex-direction: column; min-height: 100vh;}
    .modal-fullscreen { margin: 0 !important; border-radius:0 !important; }
    .modal-header { padding: 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; position:sticky; top:0; z-index:10;}
    .modal-body { padding: 20px; overflow-y: auto; flex: 1; }
    
    .provinces-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
    .province-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: 0.3s; }
    .province-card:hover { transform: translateY(-5px); border-color: #3b82f6; }
    .tree-container { display: flex; flex-direction: column; gap: 10px; width: 100%; max-width: 1000px; margin: 0 auto;}
    .tree-node { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    .tree-header { padding: 15px; background: #f8fafc; cursor: pointer; display: flex; justify-content: space-between; font-weight: bold; color: #1e293b; }
    
    .table-responsive { width: 100%; overflow-x: auto; }
    .prov-cust-table { width: 100%; border-collapse: collapse; min-width: 1000px; table-layout: fixed; }
    .prov-cust-table th, .prov-cust-table td { padding: 12px; text-align: right; border-bottom: 1px solid #f1f5f9; vertical-align: middle; word-wrap: break-word; }
    .prov-cust-table th:nth-child(1) { width: 40px; }
    .prov-cust-table th:nth-child(2) { width: 250px; }
    .prov-cust-table th:nth-child(3) { width: 150px; }
    .prov-cust-table th:nth-child(4) { width: auto; }
    .prov-cust-table th:nth-child(5) { width: 150px; }
    .prov-cust-table th:nth-child(6) { width: 140px; }
    .prov-cust-table th:nth-child(7) { width: 100px; }
    
    .tags-wrapper { display: flex; flex-wrap: wrap; gap: 4px; }
    .tag-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: bold; text-align: center; color: #fff; text-shadow: 0 1px 1px rgba(0,0,0,0.2); width: fit-content; white-space: nowrap; }
    
    .form-group { margin-bottom: 15px; text-align: right; }
    .form-label { display: block; font-weight: bold; font-size: 0.85rem; color: #475569; margin-bottom: 6px; }
    .form-control { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; background: #fff; outline: none; transition: 0.15s; }
    .form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    
    /* وظایف تمام صفحه */
    .task-repeater-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .task-repeater-table th { background: #f8fafc; padding: 10px; font-size: 0.8rem; color: #64748b; border-bottom: 2px solid #e2e8f0; text-align: right; }
    .task-repeater-table td { padding: 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    
    /* چت تلگرامی و پنل 360 درجه */
    .opp-modal-body { display: flex; flex-direction: row-reverse; gap: 20px; padding: 20px; overflow: hidden; height: calc(100vh - 75px); align-items: stretch; background: #f8fafc; }
    .opp-details-main { flex: 2; display: flex; flex-direction: column; overflow-y: auto; padding-right: 5px; min-width: 320px; }
    .opp-chat-sidebar { flex: 1; background: white; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; flex-direction: column; min-width: 350px; overflow: hidden; position: relative; }

    .chat-header-custom { padding: 15px; border-bottom: 1px solid #e2e8f0; background: #fff; font-weight: bold; color: #1e293b; display:flex; align-items:center; gap:10px; flex-shrink: 0; position:relative; z-index:15;}
    #pinnedMessageBar { display:none; background:#f8fafc; padding:8px 12px; border-bottom:1px solid #e2e8f0; border-right:3px solid #3b82f6; cursor:pointer; align-items:center; justify-content:space-between; z-index:10; flex-shrink:0; }
    .chat-messages { flex: 1; overflow-y: auto; padding: 15px; background: #f4f4f5; display: flex; flex-direction: column; gap: 15px; position:relative; scroll-behavior: smooth; }
    
    .msg-wrapper { display: flex; width: 100%; position:relative; align-items: flex-end; margin-bottom:10px; }
    .msg-out { justify-content: flex-start; flex-direction: row; direction: rtl; } 
    .msg-in { justify-content: flex-start; flex-direction: row-reverse; direction: ltr; } 
    .msg-in .message-bubble { direction: rtl; text-align: right; }
    
    .message-bubble { padding: 10px 14px; border-radius: 12px; max-width: 85%; font-size: 0.9rem; line-height: 1.6; position: relative; box-shadow: 0 1px 2px rgba(0,0,0,0.05); word-wrap: break-word; transition: background-color 0.5s ease;}
    .msg-out .message-bubble { background: #dcfce7; border-bottom-right-radius: 4px; border: 1px solid #bbf7d0; margin-right: 5px; }
    .msg-in .message-bubble { background: #ffffff; border-bottom-left-radius: 4px; border: 1px solid #e4e4e7; margin-left: 5px; }
    
    .msg-sender { font-size: 0.72rem; font-weight: bold; color: #2563eb; margin-bottom: 4px; }
    .msg-time { font-size: 0.65rem; color: #a1a1aa; display: inline-flex; align-items: center; gap: 4px; justify-content: flex-end; width: 100%; margin-top: 4px; }
    .msg-avatar { width:34px; height:34px; border-radius:50%; object-fit:cover; flex-shrink:0; border:1px solid #e2e8f0; margin-bottom: 2px; }

    .chat-footer-wrapper { display: flex; flex-direction: column; background: #f4f4f5; flex-shrink: 0; position: relative; z-index: 20; border-top: 1px solid #e4e4e7; }
    .preview-container { display: flex; flex-direction: column; width: 100%; }
    .reply-input-preview, .file-attachment-preview { display: none; background: #f8fafc; padding: 10px 15px; border-bottom: 1px solid #e2e8f0; border-left: 3px solid #2563eb; align-items: center; justify-content: space-between; }
    .reply-input-preview.active, .file-attachment-preview.active { display: flex; }

    .chat-footer { display: flex; align-items: flex-end; padding: 8px 12px; background: transparent; gap: 8px; }
    .icon-btn, .send-btn { width: 38px; height: 38px; flex-shrink: 0; background: transparent; border: none; outline: none; cursor: pointer; color: #71717a; border-radius: 50%; transition: 0.2s; display: flex; align-items: center; justify-content: center; }
    .icon-btn:hover { background: #e4e4e7; color: #2563eb; }
    .chat-input { flex: 1; background: #fff; padding: 9px 15px; border-radius: 20px; border: 1px solid #e4e4e7; outline: none; max-height: 120px; overflow-y: auto; font-size: 0.92rem; line-height: 1.5; word-wrap: break-word; white-space: pre-wrap; text-align: right; direction: rtl; }
    .chat-input:empty:before { content: attr(placeholder); color: #a1a1aa; pointer-events: none; display: block; }
    .send-btn { background: #2563eb; color: #fff; } .send-btn:hover { background: #1d4ed8; transform: scale(1.03); }
    
    .emoji-panel { display: none; position: absolute; bottom: 100%; right: 0; width: 100%; flex-wrap: wrap; gap: 6px; background: #fff; padding: 12px; border: 1px solid #e4e4e7; border-radius: 12px 12px 0 0; box-shadow: 0 -4px 15px rgba(0,0,0,0.08); max-height: 180px; overflow-y: auto; justify-content: center; align-items: center; z-index:20;}
    
    .chat-context-menu { display: none; position: fixed; background: #fff; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); border: 1px solid #e4e4e7; z-index: 100005; min-width: 160px; overflow: hidden; }
    .chat-context-menu ul { list-style: none; margin: 0; padding: 4px 0; }
    .chat-context-menu li { padding: 9px 14px; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.85rem; color: #27272a; font-weight: bold; transition:0.15s; text-align: right; direction: rtl; }
    .chat-context-menu li:hover { background: #f4f4f5; color: #2563eb; }
    .msg-reactions { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 4px; }
    .reaction-badge { background: rgba(0,0,0,0.04); border-radius: 10px; padding: 1px 5px; font-size: 0.72rem; }
    .recording-ui { position: absolute; left: 50px; right: 50px; bottom: 8px; background: #fff; height: 40px; border-radius: 20px; display: none; align-items: center; padding: 0 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); z-index: 10; }
    .recording-ui.active { display: flex !important; }

    .call-item { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:15px; margin-bottom:10px; box-shadow:0 1px 3px rgba(0,0,0,0.02); }
    .call-item-header { display:flex; justify-content:space-between; margin-bottom:8px; font-weight:bold; color:#1e293b; }
    .call-item-meta { font-size:0.75rem; color:#64748b; display:flex; gap:10px; margin-bottom:8px; flex-wrap:wrap; }
    .call-item-desc { font-size:0.85rem; color:#475569; border-top:1px dashed #e2e8f0; padding-top:8px; margin-top:5px;}
    
    .week-menu-box { display: grid; grid-template-columns: 1fr; gap: 5px; padding: 5px; max-height: 150px; overflow-y: auto; }
    .week-item-label { display: flex; align-items: center; gap: 8px; font-size: 0.8rem; cursor: pointer; padding: 4px; border-radius: 4px; }
    .week-item-label:hover { background: #f1f5f9; }

    .nav-tabs-custom { display: flex; overflow-x: auto; gap: 5px; padding-bottom: 5px; border-bottom: 2px solid #e2e8f0; margin-bottom: 15px; }
    .nav-tab-item { padding: 10px 20px; cursor: pointer; font-weight: bold; color: #64748b; border-bottom: 3px solid transparent; transition: 0.2s; white-space: nowrap; }
    .nav-tab-item:hover { color: #2563eb; background: #f8fafc; border-radius: 8px 8px 0 0; }
    .nav-tab-item.active { color: #2563eb; border-bottom-color: #2563eb; }

    .opp-timeline-bar { display: flex; width: 100%; border-radius: 10px; overflow: hidden; background: #f1f5f9; margin-bottom: 25px; border: 1px solid #cbd5e1; flex-wrap: wrap;}
    .stage-segment { flex: 1; min-width: 140px; text-align: center; padding: 12px 6px; font-size: 0.8rem; font-weight: bold; color: #64748b; border-left: 1px solid #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;}
    .stage-segment:last-child { border-left: none; }
    .stage-segment.active { background: #10b981; color: white; }
    .stage-segment.passed { background: #a7f3d0; color: #065f46; }

    .prov-cust-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px; width: 100%; }
    .prov-cust-toolbar input { flex: 1; min-width: 200px; max-width: 300px; }
    .prov-cust-toolbar button { flex-shrink: 0; white-space: nowrap; }
    
    @media (max-width: 992px) {
        .opp-modal-body { flex-direction: column !important; height: auto !important; overflow-y: auto !important; padding: 10px !important; }
        .opp-details-main { width: 100% !important; flex: none !important; }
        .opp-chat-sidebar { width: 100% !important; flex: none !important; height: 500px !important; margin-bottom: 20px; }
        .kanban-column { flex: 0 0 280px; }
        .prov-cust-toolbar input { width: 100%; max-width: none; }
        .prov-cust-toolbar button { width: 100%; }
        .kanban-filter-bar { flex-direction: column; align-items: stretch; }
        .filter-input, .filter-select-kanban, .custom-select-wrapper { width: 100% !important; }
    }
</style>
';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>
<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <span class="page-title">کارتابل فرصت‌های فروش</span>
                <div class="board-select-wrapper" style="display: inline-flex;">
                    <label style="font-weight: bold; color: #475569; margin-left: 5px;">📌 کانبان:</label>
                    <select class="board-select" onchange="window.location.href='?board_id='+this.value">
                        <?php foreach($boards as $b): ?><option value="<?php echo $b['id']; ?>" <?php echo $b['id'] == $currentBoardId ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div style="display: inline-flex; align-items: center;">
                    <label style="font-weight: bold; color: #166534; margin-left: 5px;">📅 برنامه هفتگی:</label>
                    <select id="kanbanWeekFilter" class="form-control" style="padding: 4px 10px; font-size: 0.85rem; width: auto;" onchange="window.filterKanbanCards()">
                        <option value="all">نمایش کل برنامه سال</option>
                    </select>
                </div>
            </div>
            <?php if($currentBoardId > 0 && !empty($stages)): ?>
                <div>
                    <button class="btn btn-primary" onclick="window.openProvinceModal(); return false;">➕ افزودن فرصت جدید</button>
                </div>
            <?php endif; ?>
        </div>

        <?php if($currentBoardId > 0 && !empty($stages)): ?>
        <div class="kanban-filter-bar">
            <input type="text" id="kanbanLiveFilter" class="filter-input" placeholder="جستجو (مشتری، فرصت)..." onkeyup="window.filterKanbanCards()">
            
            <select id="kanbanExpertFilter" class="filter-select-kanban" onchange="window.filterKanbanCards()">
                <option value="all">👤 همه کارشناسان</option>
                <?php foreach($allExperts as $exp): ?>
                    <option value="<?php echo $exp['id']; ?>"><?php echo htmlspecialchars($exp['first_name'] . ' ' . $exp['last_name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <div class="custom-select-wrapper">
                <div class="custom-select-box" onclick="window.toggleTagDropdown()">
                    <span>🏷️ انتخاب برچسب‌ها</span><span>▼</span>
                </div>
                <div class="custom-select-options" id="tagDropdownOptions">
                    <?php foreach($allTags as $tg): ?>
                        <label>
                            <input type="checkbox" class="tag-filter-cb" value="<?php echo $tg['id']; ?>" onchange="window.filterKanbanCards()"> 
                            <?php echo htmlspecialchars($tg['title']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button class="btn-clear-filter" onclick="window.clearFilters()">🗑 حذف فیلتر</button>
        </div>

        <div class="kanban-wrapper">
            <?php $isFirstStage = true; foreach($stages as $stage): 
                $stageOpps = $opportunities[$stage['id']] ?? [];
                
                $c = strtolower(trim($stage['color_class'] ?? ''));
                $colorMap = ['primary'=>'#0d6efd', 'secondary'=>'#6c757d', 'success'=>'#198754', 'danger'=>'#dc3545', 'warning'=>'#ffc107', 'info'=>'#0dcaf0', 'blue'=>'#3b82f6', 'green'=>'#10b981', 'red'=>'#ef4444', 'yellow'=>'#eab308', 'purple'=>'#8b5cf6'];
                $colorValue = '#3b82f6';
                if (strpos($c, '#') === 0) { $colorValue = $c; }
                elseif (isset($colorMap[str_replace('bg-', '', $c)])) { $colorValue = $colorMap[str_replace('bg-', '', $c)]; }
                elseif (isset($colorMap[$c])) { $colorValue = $colorMap[$c]; }
            ?>
            <div class="kanban-column" data-stage-id="<?php echo $stage['id']; ?>">
                <div class="kanban-column-header" style="border-top: 4px solid <?php echo htmlspecialchars($colorValue); ?>; background: linear-gradient(to bottom, <?php echo htmlspecialchars($colorValue); ?>11, #ffffff); border-bottom: 1px solid <?php echo htmlspecialchars($colorValue); ?>44;">
                    <div class="col-title" style="font-weight: 900; color: <?php echo htmlspecialchars($colorValue); ?>;">
                        <span><?php echo htmlspecialchars($stage['name']); ?></span>
                        <span class="col-count" id="count_stage_<?php echo $stage['id']; ?>" style="background: <?php echo htmlspecialchars($colorValue); ?>; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.8rem; box-shadow: 0 2px 4px <?php echo htmlspecialchars($colorValue); ?>66;"><?php echo count($stageOpps); ?></span>
                    </div>
                </div>
                
                <?php if($isFirstStage): ?>
                <div class="kanban-quick-add-area"><button class="btn-quick-add" onclick="window.openProvinceModal()">+ افزودن سریع</button></div>
                <?php endif; ?>

                <div class="kanban-column-body" ondragover="window.allowDrop(event)" ondrop="window.drop(event, <?php echo $stage['id']; ?>)">
                    <?php foreach($stageOpps as $opp): 
                        $ePic = (!empty($opp['expert_image']) && $opp['expert_image'] != 'default.png') ? '../uploads/profiles/' . $opp['expert_image'] : '../assets/images/profile-icon.png';
                    ?>
                        <div class="k-card draggable" 
                             id="card_<?php echo $opp['id']; ?>" 
                             data-weeks='<?php echo htmlspecialchars($opp['selected_weeks'] ?: "[]", ENT_QUOTES, 'UTF-8'); ?>'
                             data-expert="<?php echo $opp['expert_id']; ?>"
                             data-tags='<?php echo $opp['tag_ids'] ? "[".$opp['tag_ids']."]" : "[]"; ?>'
                             draggable="true" ondragstart="window.dragStart(event, <?php echo $opp['id']; ?>, <?php echo $stage['id']; ?>)"
                             onmousedown="window.startCardHold(<?php echo $opp['id']; ?>)" onmouseup="window.clearCardHold()" onmouseleave="window.clearCardHold()"
                             onclick="window.handleCardClick(<?php echo $opp['id']; ?>, event)"
                        >
                            <div class="card-actions-overlay" id="overlay_<?php echo $opp['id']; ?>">
                                <button class="btn btn-success" style="padding:4px 8px; font-size:11px;" onclick="window.actionConvert(<?php echo $opp['id']; ?>, event)">👑 تبدیل</button>
                                <button class="btn btn-outline" style="padding:4px 8px; font-size:11px; background:#f1f5f9; color:#475569;" onclick="window.closeOverlay(<?php echo $opp['id']; ?>, event)">🔙 انصراف</button>
                                <button class="btn btn-outline btn-delete" style="padding:4px 8px; font-size:11px; background:#fee2e2; color:#b91c1c;" onclick="window.actionDelete(<?php echo $opp['id']; ?>, event)">🗑️ حذف</button>
                            </div>
                            <div class="c-title" style="font-weight: bold; color: #1e293b;"><?php echo htmlspecialchars($opp['title']); ?></div>
                            <div class="c-customer" style="color: <?php echo htmlspecialchars($colorValue); ?>;">🏢 <?php echo htmlspecialchars($opp['company_name']); ?></div>
                            <div class="c-expert"><img src="<?php echo htmlspecialchars($ePic); ?>"><span><?php echo htmlspecialchars(($opp['expert_fname'] ?? 'نامشخص') . ' ' . ($opp['expert_lname'] ?? '')); ?></span></div>
                            <div style="margin-top:5px;text-align:left"><a href="fin_preinvoice.php?opportunity_id=<?php echo $opp['id']; ?>" onclick="event.stopPropagation()" style="font-size:10px;color:#92400e;background:#fffbeb;border:1px solid #fcd34d;padding:2px 7px;border-radius:8px;text-decoration:none">🧾 پیشفاکتور</a></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php $isFirstStage = false; endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<div id="provinceModal" class="modal-overlay">
    <div class="modal-content modal-fullscreen" style="max-width:100%; border-radius:0;">
        <div class="modal-header">
            <h4 class="m-0 fw-bold" id="provinceModalTitle">افزودن سریع</h4>
            <span style="cursor:pointer; font-size:2rem; color:#ef4444;" onclick="window.closeModal('provinceModal')">&times;</span>
        </div>
        <div style="background:#fff; padding:20px; border-bottom:1px solid #e2e8f0; display:flex; flex-direction:column; align-items:center; gap:12px;">
            <h5 style="color:#475569; margin:0; font-weight:bold;">نوع نمایش استان‌ها را انتخاب کنید:</h5>
            <div style="display:flex; gap:10px; justify-content:center; width:100%; flex-wrap:wrap;">
                <button class="btn btn-outline" id="btnViewTree" onclick="window.switchProvView('tree')" style="background:#e0f2fe; border-color:#3b82f6; color:#1d4ed8; padding: 10px 20px;">📑 نمای درختی</button>
                <button class="btn btn-outline" id="btnViewGrid" onclick="window.switchProvView('grid')" style="padding: 10px 20px;">🗂️ نمای کارتی</button>
            </div>
            <button id="btnBackToProvinces" class="btn btn-secondary" style="display:none;" onclick="window.showProvinceGrid()">🔙 بازگشت</button>
        </div>
        <div class="modal-body" style="background: #f1f5f9;">
            <div id="provincesTreeView">
                <div class="tree-container">
                    <?php foreach($provinceSummary as $ps): $md5Prov = md5($ps['province_name']); ?>
                        <div class="tree-node">
                            <div class="tree-header" onclick="window.toggleTreeNode('<?php echo htmlspecialchars($ps['province_name']); ?>', '<?php echo $md5Prov; ?>')">
                                <span>🗺️ <?php echo htmlspecialchars($ps['province_name']); ?> <span style="background:#3b82f6; color:#fff; padding:2px 8px; border-radius:12px; font-size:0.8rem; margin-right:5px;"><?php echo $ps['customer_count']; ?> مشتری</span></span>
                                <span id="tree_icon_<?php echo $md5Prov; ?>">▼</span>
                            </div>
                            <div class="tree-body" id="tree_body_<?php echo $md5Prov; ?>"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div id="provincesGridView" style="display:none;">
                <div class="provinces-grid">
                    <?php foreach($provinceSummary as $ps): ?><div class="province-card" onclick="window.loadCustomersForProvinceGrid('<?php echo htmlspecialchars($ps['province_name']); ?>')"><div class="province-icon">🗺️</div><div class="province-name"><?php echo htmlspecialchars($ps['province_name']); ?></div><div class="province-count"><?php echo $ps['customer_count']; ?> مشتری مجاز</div></div><?php endforeach; ?>
                </div>
            </div>
            <div id="customersListView" style="display: none; background: white; border-radius: 12px; padding: 20px;">
                <div class="prov-cust-toolbar">
                    <input type="text" id="custSearchInput" class="form-control" placeholder="🔍 جستجوی مشتری..." onkeyup="window.filterProvinceCustomers()">
                    <button class="btn btn-primary" onclick="window.bulkAddGrid()">➕ افزودن گروهی</button>
                </div>
                <div class="table-responsive"><table class="prov-cust-table"><thead><tr><th><input type="checkbox" onchange="window.toggleAllCustGrid(this)"></th><th>نام مشتری</th><th>پیگیری کننده</th><th>برچسب‌ها</th><th style="color:#166534;">هفته</th><th>مرحله کانبان</th><th>عملیات</th></tr></thead><tbody id="customersTableBody"></tbody></table></div>
            </div>
        </div>
    </div>
</div>

<div id="oppDetailsModal" class="modal-overlay">
    <div class="modal-content modal-fullscreen" style="max-width:100%; border-radius:0;">
        <div class="modal-header"><h3 class="m-0 fw-bold" id="detailOppTitle">عنوان فرصت</h3><span style="cursor:pointer; font-size:2rem; color:#ef4444;" onclick="window.closeModal('oppDetailsModal')">&times;</span></div>
        <div class="modal-body opp-modal-body">
            
            <div class="opp-chat-sidebar">
                <div class="chat-header-custom">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    <span>پیام‌رسان تیمی</span>
                </div>
                
                <div id="pinnedMessageBar" style="display:none; background:#f8fafc; padding:8px 12px; border-bottom:1px solid #e2e8f0; border-right:3px solid #3b82f6; cursor:pointer; align-items:center; justify-content:space-between; z-index:10; flex-shrink:0;">
                    <div style="flex:1; overflow:hidden;" onclick="window.cyclePinnedMessage()">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="color:#3b82f6; font-weight:bold; font-size:0.75rem;">📌 پیام سنجاق شده</span>
                            <span id="pinnedMessageCounter" style="font-size:0.7rem; color:#94a3b8; margin-left:10px;"></span>
                        </div>
                        <div id="pinnedMessageText" style="font-size:0.85rem; color:#334155; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"></div>
                    </div>
                    <button onclick="window.unpinCurrentMessage(event)" style="background:none; border:none; color:#94a3b8; font-size:1.4rem; cursor:pointer; padding:0 5px;">&times;</button>
                </div>

                <div class="chat-messages" id="commentsBox"></div>
                
                <div class="chat-footer-wrapper">
                    <div class="preview-container">
                        <div class="file-attachment-preview" id="filePreviewBox"></div>
                        <div class="reply-input-preview" id="replyPreviewBox"></div>
                    </div>
                    <div class="emoji-panel" id="emojiPanel" style="bottom:100%;"></div>

                    <div class="chat-footer">
                        <button class="icon-btn" id="emojiBtn" title="ایموجی">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg>
                        </button>
                        <button class="icon-btn" id="attachBtn" title="ارسال فایل" onclick="document.getElementById('fileInput').click();">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                        </button>
                        <input type="file" id="fileInput" hidden onchange="window.showFilePreview(this)">
                        
                        <div class="recording-ui" id="recordingUI">
                            <div style="color:red; font-weight:bold;" id="recTimer">00:00</div>
                            <button class="icon-btn" style="color:#ef4444;" type="button" onclick="window.cancelRecording()">✖</button>
                            <button class="icon-btn" style="color:#2563eb;" type="button" onclick="window.stopAndSendRecording()">▲</button>
                        </div>
                        <div class="chat-input" contenteditable="true" id="commentInput" placeholder="پیام..."></div>
                        
                        <button class="icon-btn" id="micBtn" title="ضبط صدا" type="button" onclick="window.startRecording()">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
                        </button>
                        <button class="send-btn" id="sendBtn" style="display:none;" title="ارسال" type="button" onclick="window.sendComment(event)">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                        </button>
                    </div>
                </div>
                <div id="chatContextMenu" class="chat-context-menu">
                    <ul>
                        <li onclick="window.contextAction('reply')">🔄 پاسخ دادن</li>
                        <li onclick="window.contextAction('copy')">📋 کپی متن</li>
                        <li onclick="window.contextAction('edit')">✏️ ویرایش پیام</li>
                        <li onclick="window.contextAction('pin')">📌 سنجاق پیام</li>
                        <li onclick="window.contextAction('react')">👍 پسندیدن</li>
                        <li onclick="window.contextAction('delete')" class="delete-btn" style="color:red;">🗑️ حذف پیام</li>
                    </ul>
                </div>
            </div>

            <div class="opp-details-main">
                <div style="background:white; padding:20px; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 2px 4px rgba(0,0,0,0.02); margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
                        <div style="display:flex; align-items:center; gap:15px;">
                            <div style="width:50px; height:50px; background:#e0f2fe; color:#2563eb; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.5rem;">🏢</div>
                            <div>
                                <div style="font-size:0.85rem; color:#64748b; font-weight:bold; margin-bottom:4px;">پرونده مشتری</div>
                                <h3 style="margin:0; color:#1e293b; font-weight:900; font-size:1.5rem;" id="detailCustomer">...</h3>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px; background:#f1f5f9; padding:8px 15px; border-radius:12px; border:1px solid #e2e8f0;" id="detailExpertBox">
                            <div style="text-align:left;">
                                <div style="font-size:0.75rem; color:#64748b;">کارشناس فروش</div>
                                <div id="detailExpertName" style="font-size:0.9rem; font-weight:bold; color:#1e293b;">...</div>
                            </div>
                            <img src="" id="detailExpertImg" style="width:38px; height:38px; border-radius:50%; object-fit:cover; border:2px solid #fff; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        </div>
                    </div>
                    <h6 style="color:#475569; font-weight:bold; margin-bottom:10px;">📊 نوار پیشرفت زمانی کانبان</h6>
                    <div class="opp-timeline-bar" id="timelineBar"></div>
                </div>
                
                <div style="background:white; padding:20px; border-radius:12px; flex:1; display:flex; flex-direction:column; overflow:hidden;">
                    <div class="nav-tabs-custom">
                        <div class="nav-tab-item active" onclick="window.switchMainTab('calls')">📞 تماس‌ها</div>
                        <div class="nav-tab-item" onclick="window.switchMainTab('missions')">🚗 مأموریت‌ها</div>
                        <div class="nav-tab-item" onclick="window.switchMainTab('tasks')">📝 وظایف</div>
                        <div class="nav-tab-item" onclick="window.switchMainTab('notes')">📋 یادداشت‌ها</div>
                        <div class="nav-tab-item" onclick="window.switchMainTab('quotes')">🧾 پیشفاکتورها</div>
                        <div class="nav-tab-item" onclick="window.switchMainTab('history')">⏱️ تاریخچه</div>
                    </div>
                    <div id="tabContentContainer" style="flex:1; overflow-y:auto; padding-right:5px;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="imageLightbox" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:100000; align-items:center; justify-content:center; backdrop-filter:blur(5px);">
    <span onclick="document.getElementById('imageLightbox').style.display='none'" style="position:absolute; top:20px; right:30px; color:white; font-size:40px; cursor:pointer; line-height:1;">&times;</span>
    <img id="lightboxImg" src="" style="max-width:90%; max-height:90%; object-fit:contain; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.5);">
</div>

<div id="callModal" class="modal-overlay" style="display:none; align-items:center; justify-content:center; z-index:10005;">
    <div class="modal-content" style="max-width:500px; margin:auto; flex:none; overflow:visible; border-radius:12px;">
        <div class="modal-header"><h5 id="callModalTitle" style="margin:0; font-weight:bold;">ثبت تماس</h5><span style="cursor:pointer; color:red; font-size:1.5rem;" onclick="window.closeModal('callModal')">&times;</span></div>
        <div class="modal-body">
            <form onsubmit="window.submitCallForm(event)">
                <input type="hidden" id="callOppId"><input type="hidden" id="editCallId">
                <div style="margin-bottom:10px;"><label style="font-size:0.8rem; font-weight:bold;">مشتری</label><input type="text" id="callCustomerName" class="form-control" readonly style="background:#f1f5f9;"></div>
                <div style="margin-bottom:10px;"><label style="font-size:0.8rem; font-weight:bold;">موضوع تماس</label><input type="text" id="callSubject" class="form-control" required></div>
                <div style="margin-bottom:10px;"><label style="font-size:0.8rem; font-weight:bold;">مرتبط با</label><select id="callRelatedTo" class="form-control"></select></div>
                <div style="margin-bottom:10px;"><label style="font-size:0.8rem; font-weight:bold;">وضعیت</label><select id="callStatus" class="form-control"><option value="برنامه ریزی شده">🗓️ برنامه‌ریزی شده</option><option value="انجام شده">✅ انجام شده</option><option value="تماس برقرار نشد">❌ تماس برقرار نشد</option></select></div>
                <div style="margin-bottom:10px;"><label style="font-size:0.8rem; font-weight:bold;">تاریخ تماس</label><input type="text" id="callDate" class="form-control" placeholder="140X/XX/XX" required></div>
                <div style="margin-bottom:10px; display:flex; gap:15px;"><label><input type="radio" name="callType" value="outbound" checked> خروجی</label><label><input type="radio" name="callType" value="inbound"> ورودی</label></div>
                <div style="margin-bottom:15px;"><label style="font-size:0.8rem; font-weight:bold;">توضیحات</label><textarea id="callDesc" class="form-control" rows="3"></textarea></div>
                <button type="submit" id="btnSubmitCall" class="btn btn-primary" style="width:100%;">💾 ذخیره اطلاعات</button>
            </form>
        </div>
    </div>
</div>

<div id="taskModal" class="modal-overlay" style="display:none; align-items:flex-start; justify-content:center; z-index:10005;">
    <div class="modal-content modal-fullscreen" style="max-width:100%; width:100%; height:100vh; margin:0; border-radius:0; display:flex; flex-direction:column;">
        <div class="modal-header" style="background:#f8fafc; position:sticky; top:0; z-index:10;"><h5 style="margin:0; font-weight:bold;">📝 ایجاد وظیفه</h5><span style="cursor:pointer; color:red; font-size:1.5rem;" onclick="window.closeModal('taskModal')">&times;</span></div>
        <div class="modal-body" style="padding:20px; overflow-y:auto; flex:1; display:flex; flex-direction:column;">
            <form onsubmit="window.submitTaskFormBulk(event)" id="bulkTaskForm" enctype="multipart/form-data" style="display:flex; flex-direction:column; height:100%;">
                <input type="hidden" id="taskOppId" name="opp_id">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:15px; background:#f1f5f9; padding:12px; border-radius:8px;">
                    <div><label class="form-label" style="margin:0;">📌 بورد کانبان جاری</label><input type="text" id="taskBoardLock" class="form-control" readonly style="background:#e2e8f0; font-weight:bold; color:#475569;"></div>
                    <div><label class="form-label" style="margin:0;">📊 مرحله فعلی کارت</label><input type="text" id="taskStageLock" class="form-control" readonly style="background:#e2e8f0; font-weight:bold; color:#475569;"></div>
                    <div><label class="form-label" style="margin:0;">🏢 پرونده مشتری مربوطه</label><input type="text" id="taskCustomerLock" class="form-control" readonly style="background:#e2e8f0; font-weight:bold; color:#2563eb;"></div>
                </div>

                <div class="form-group"><label class="form-label">🎬 عنوان اصلی فعالیت / وظیفه <span style="color:red;">*</span></label><input type="text" id="taskActivityTitle" name="activity_title" class="form-control" placeholder="مثال: پیگیری هماهنگی نمایشگاه غرفه" required></div>

                <div style="flex:1; overflow-y:auto; overflow-x:auto; border:1px solid #e2e8f0; border-radius:10px; background:#fff; padding:10px; min-height: 250px;">
                    <table class="task-repeater-table" style="width:100%; min-width: 800px; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="width:25%; background:#f8fafc; padding:8px; border-bottom:2px solid #e2e8f0;">عنوان وظیفه *</th>
                                <th style="width:15%; background:#f8fafc; padding:8px; border-bottom:2px solid #e2e8f0;">مهلت (موعد) *</th>
                                <th style="width:15%; background:#f8fafc; padding:8px; border-bottom:2px solid #e2e8f0;">وضعیت اولیه</th>
                                <th style="width:25%; background:#f8fafc; padding:8px; border-bottom:2px solid #e2e8f0;">شرح / توضیحات</th>
                                <th style="width:20%; background:#f8fafc; padding:8px; border-bottom:2px solid #e2e8f0;">مستندات پیوست</th>
                            </tr>
                        </thead>
                        <tbody id="taskRepeaterBody">
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:15px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <button type="button" class="btn btn-outline" style="color:#2563eb; border-color:#2563eb;" onclick="window.addTaskRow();">➕ افزودن ردیف جدید</button>
                    <button type="submit" id="btnSubmitTaskBulk" class="btn btn-primary" style="padding:10px 30px;">💾 ذخیره و درج در تقویم کاری</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="noteModal" class="modal-overlay" style="display:none; align-items:center; justify-content:center; z-index:10005;">
    <div class="modal-content" style="max-width:500px; margin:auto; flex:none; overflow:visible; border-radius:12px;">
        <div class="modal-header"><h5 style="margin:0; font-weight:bold;">ثبت یادداشت جدید</h5><span style="cursor:pointer; color:red; font-size:1.5rem;" onclick="window.closeModal('noteModal')">&times;</span></div>
        <div class="modal-body">
            <form onsubmit="window.submitNoteForm(event)">
                <div style="margin-bottom:15px;"><label style="font-size:0.8rem; font-weight:bold;">متن یادداشت (فقط برای شما قابل رؤیت است)</label><textarea id="noteText" class="form-control" rows="5" required></textarea></div>
                <button type="submit" id="btnSubmitNote" class="btn btn-primary" style="width:100%;">💾 ذخیره اطلاعات</button>
            </form>
        </div>
    </div>
</div>
<script>
    window.escapeHtml = function(text) { 
        if (!text) return "";
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;"); 
    };

    window.closeModal = function(id) { 
        var el = document.getElementById(id);
        if(el) el.style.display = 'none'; 
        if(id === 'oppDetailsModal' && window.chatPollInterval) clearInterval(window.chatPollInterval);
        if(id === 'provinceModal' && window.needsBoardRefresh) window.location.reload();
    };

    window.openImageModal = function(src) { 
        document.getElementById('lightboxImg').src = src; 
        document.getElementById('imageLightbox').style.display = 'flex'; 
    };

    window.updatePinnedBarUI = function() {
        var arr = window.currentPinnedMessages || [];
        var idx = window.currentPinnedIndex || 0;
        var pinBar = document.getElementById('pinnedMessageBar');
        if(!arr || arr.length === 0 || !pinBar) { if(pinBar) pinBar.style.display = 'none'; return; }
        var pin = arr[idx];
        window.currentPinnedId = pin.id;
        document.getElementById('pinnedMessageText').innerText = pin.comment_text;
        document.getElementById('pinnedMessageCounter').innerText = arr.length > 1 ? (idx + 1) + " از " + arr.length : '';
        pinBar.style.display = 'flex';
    };

    window.apiPost = function(action, dataObj = {}) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', window.csrfToken);
        for (var key in dataObj) { fd.append(key, dataObj[key]); }
        return fetch(window.location.href.split('?')[0], { 
            method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(async function(r) {
            var txt = await r.text();
            try { return JSON.parse(txt); } catch(e) { console.error("JSON Error on:", txt); throw e; }
        });
    };

    window.g2j = function(gy, gm, gd) {
        var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var gy2 = (gm > 2) ? (gy + 1) : gy;
        var days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
        var jy = -1595 + (33 * Math.floor(days / 12053)); days %= 12053; jy += 4 * Math.floor(days / 1461); days %= 1461;
        if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        var jm = (days < 186) ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
        var jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
        return [jy, jm, jd];
    };
    window.j2g = function(jy, jm, jd) {
        var jy2 = jy + 1595;
        var days = -355668 + (365 * jy2) + (Math.floor(jy2 / 33) * 8) + Math.floor(((jy2 % 33) + 3) / 4) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
        var gy = 400 * Math.floor(days / 146097); days %= 146097;
        if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
        gy += 4 * Math.floor(days / 1461); days %= 1461;
        if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        var gd = days + 1;
        var sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        var gm = 0; for (; gm < 13 && gd > sal_a[gm]; gm++) gd -= sal_a[gm];
        return [gy, gm, gd];
    };
    window.jDays = function(jy, jm) { if (jm <= 6) return 31; if (jm <= 11) return 30; return (((((jy - 474) % 2820) + 474 + 38) * 682) % 2816 < 682) ? 30 : 29; };
    window.jDow = function(jy, jm, jd) { var g = window.j2g(jy, jm, jd); return (new Date(g[0], g[1] - 1, g[2]).getDay() + 1) % 7; };
    window.jMs = function(jy, jm, jd) { var g = window.j2g(jy, jm, jd); return new Date(g[0], g[1] - 1, g[2]).getTime(); };
    window.jAdd = function(jy, jm, jd, n) { var g = window.j2g(jy, jm, jd); var d = new Date(g[0], g[1] - 1, g[2] + n); return window.g2j(d.getFullYear(), d.getMonth() + 1, d.getDate()); };
    window.todayJ = function() { var d = new Date(); return window.g2j(d.getFullYear(), d.getMonth() + 1, d.getDate()); };
    window.p2 = function(n) { return n < 10 ? '0' + n : String(n); };
    window.wkStart = function(jy, jm, jd) { var dow = window.jDow(jy, jm, jd); return window.jAdd(jy, jm, jd, -dow); };

    window.generateYearWeeks = function() {
        var t = window.todayJ(); var currentYear = t[0];
        var todayMs = window.jMs(t[0], t[1], t[2]);
        var startWk = window.wkStart(currentYear, 1, 1);
        var weeks = [];
        for (var w = 1; w <= 53; w++) {
            var endWk = window.jAdd(startWk[0], startWk[1], startWk[2], 6);
            var endMs = window.jMs(endWk[0], endWk[1], endWk[2]);
            if (endMs >= todayMs && startWk[0] === currentYear) {
                weeks.push({
                    id: currentYear + "-W" + w,
                    label: "هفته " + w + " (" + startWk[1] + "/" + window.p2(startWk[2]) + " تا " + endWk[1] + "/" + window.p2(endWk[2]) + ")"
                });
            }
            startWk = window.jAdd(startWk[0], startWk[1], startWk[2], 7);
            if (startWk[0] > currentYear) break;
        }
        return weeks;
    };

    window.allFutureWeeks = window.generateYearWeeks();
    var weekFilterSelect = document.getElementById('kanbanWeekFilter');
    if(weekFilterSelect) {
        window.allFutureWeeks.forEach(function(wk) {
            weekFilterSelect.insertAdjacentHTML('beforeend', '<option value="'+wk.id+'">'+wk.label+'</option>');
        });
    }

    window.kanbanStages = <?php echo $stagesJsonForJs ?: '[]'; ?>;
    window.currentUserId = <?php echo $userId; ?>;
    window.currentBoardId = <?php echo $currentBoardId; ?>;
    window.csrfToken = '<?php echo function_exists("create_csrf_token") ? create_csrf_token() : ""; ?>';
    
    window.holdTimer = null; window.chatPollInterval = null; window.needsBoardRefresh = false;
    window.activeOppId = 0; window.activeCustomerName = ''; window.activeCustomerId = 0;
    window.currentOppCalls = []; window.currentOppActivities = []; window.currentOppMissions = []; window.currentOppTasks = []; window.currentOppNotes = []; window.currentOppQuotes = [];
    window.currentReplyId = null; window.editingMessageId = null; window.activeContextMsgId = null;
    window.mediaRecorder = null; window.audioChunks = []; window.recordingInterval = null;
    window.COMMON_EMOJIS = ['😀','😂','😍','😭','😡','👍','👎','🙏','❤️','💔','🎉','🔥','👀','✅','❌'];
    window.currentPinnedMessages = []; window.currentPinnedIndex = 0;

    // توابع مدیریت ردیف‌های فرآیند وظایف تیمی (داینامیک)
    let taskRowIdx = 0;
    window.addTaskRow = function() {
        var tbody = document.getElementById('taskRepeaterBody');
        var idx = taskRowIdx++;
        var html = `
            <tr id="task_row_${idx}">
                <td style="padding: 5px; vertical-align: top;"><input type="text" name="task_titles[]" class="form-control" placeholder="عنوان وظیفه..." required></td>
                <td style="padding: 5px; vertical-align: top;"><input type="text" name="task_deadlines[]" class="form-control" placeholder="140X/XX/XX" required style="direction:ltr; text-align:right;"></td>
                <td style="padding: 5px; vertical-align: top;">
                    <select name="task_statuses[]" class="form-control">
                        <option value="برنامه ریزی شده">🗓️ برنامه‌ریزی شده</option>
                        <option value="درحال انجام">⏳ در حال انجام</option>
                        <option value="انجام شد">✅ انجام شد</option>
                    </select>
                </td>
                <td style="padding: 5px; vertical-align: top;"><input type="text" name="task_descriptions[]" class="form-control" placeholder="توضیحات کوتاه..."></td>
                <td style="padding: 5px; vertical-align: top; position:relative;">
                    <input type="file" name="task_files[]" title="پیوست مستندات" style="font-size: 11px; max-width:130px;">
                    ${idx > 0 ? `<button type="button" style="color:red; background:none; border:none; margin-top:5px; font-weight:bold; cursor:pointer;" onclick="document.getElementById('task_row_${idx}').remove();">&times; حذف</button>` : ''}
                </td>
            </tr>
        `;
        tbody.insertAdjacentHTML('beforeend', html);
    };

    window.openTaskModalBulk = function() {
        document.getElementById('taskModal').style.display = 'flex';
        document.getElementById('taskOppId').value = window.activeOppId;
        document.getElementById('taskRepeaterBody').innerHTML = '';
        taskRowIdx = 0;
        window.addTaskRow(); // لود ردیف اول دیفالت
    };

    window.submitTaskFormBulk = function(e) {
        e.preventDefault();
        var form = document.getElementById('bulkTaskForm');
        var btn = document.getElementById('btnSubmitTaskBulk');
        btn.disabled = true; btn.innerHTML = '⏳ در حال ثبت...';
        var fd = new FormData(form); fd.append('action', 'save_task_bulk'); fd.append('csrf_token', window.csrfToken);
        fetch(window.location.href.split('?')[0], { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(res => {
            btn.disabled = false; btn.innerHTML = '💾 ذخیره و درج در تقویم کاری';
            if(res.status === 'success') { window.closeModal('taskModal'); window.loadChatDetails(); } else { alert(res.message); }
        }).catch(() => { btn.disabled = false; alert('خطا در ارتباط با سرور'); });
    };

    document.addEventListener("DOMContentLoaded", function() {
        var emojiPanel = document.getElementById('emojiPanel');
        var emojiBtn = document.getElementById('emojiBtn');
        if(emojiPanel) {
            window.COMMON_EMOJIS.forEach(function(emo) {
                var span = document.createElement('span');
                span.innerText = emo; span.style.fontSize = '1.5rem'; span.style.cursor = 'pointer'; span.style.padding = '5px';
                span.onclick = function() { document.getElementById('commentInput').innerText += emo; window.toggleInputButtons(); };
                emojiPanel.appendChild(span);
            });
        }
        if (emojiBtn) {
            emojiBtn.addEventListener('click', function(e) {
                e.stopPropagation(); window.toggleEmojiPanel();
            });
        }
    });

    document.addEventListener('click', function(e) {
        var ep = document.getElementById('emojiPanel');
        var eb = document.getElementById('emojiBtn');
        if (ep && ep.style.display === 'flex' && !ep.contains(e.target) && !eb.contains(e.target)) ep.style.display = 'none';
        
        var cm = document.getElementById('chatContextMenu');
        if (cm && cm.style.display === 'block' && !e.target.closest('.chat-context-menu')) {
            cm.style.display = 'none';
        }
        
        if (!e.target.closest('.week-dropdown-container')) {
            document.querySelectorAll('.week-dropdown-menu').forEach(function(menu) { menu.style.display = 'none'; });
        }
        
        if (!e.target.closest('.custom-select-wrapper')) {
            var tagDrop = document.getElementById('tagDropdownOptions');
            if (tagDrop) tagDrop.style.display = 'none';
        }
    });

    document.addEventListener("contextmenu", function(e){
        var bubble = e.target.closest('.message-bubble');
        var cm = document.getElementById('chatContextMenu');
        if (bubble) {
            e.preventDefault();
            var msgEl = e.target.closest('.msg-wrapper');
            if(msgEl) {
                window.activeContextMsgId = parseInt(msgEl.id.replace('msg-', ''));
                cm.style.display = 'block';
                var x = e.clientX; var y = e.clientY;
                if(x + 160 > window.innerWidth) x = window.innerWidth - 170;
                if(y + 250 > window.innerHeight) y = window.innerHeight - 260;
                cm.style.left = Math.max(10, x) + 'px';
                cm.style.top = Math.max(10, y) + 'px';
            }
        } else if (cm && cm.style.display === 'block') {
            cm.style.display = 'none';
        }
    }); 

    document.addEventListener('dblclick', function(e) {
        var msgEl = e.target.closest('.msg-wrapper');
        if(msgEl && msgEl.closest('#commentsBox')) {
            window.activeContextMsgId = parseInt(msgEl.id.replace('msg-', ''));
            window.contextAction('reply');
        }
    });

    window.toggleTagDropdown = function() {
        var opts = document.getElementById('tagDropdownOptions');
        if(opts) opts.style.display = opts.style.display === 'block' ? 'none' : 'block';
    };

    window.clearFilters = function() {
        var searchEl = document.getElementById('kanbanLiveFilter');
        if (searchEl) searchEl.value = '';
        
        var expFilter = document.getElementById('kanbanExpertFilter'); 
        if (expFilter) expFilter.value = 'all';
        
        var wkFilter = document.getElementById('kanbanWeekFilter'); 
        if (wkFilter) wkFilter.value = 'all';
        
        document.querySelectorAll('.tag-filter-cb').forEach(function(cb) { cb.checked = false; });
        
        window.filterKanbanCards();
    };

    window.filterKanbanCards = function() {
        try {
            var searchEl = document.getElementById('kanbanLiveFilter');
            // یکسان‌سازی حروف (ی و ک) برای جستجوی دقیق و بدون باگ
            var textInput = searchEl ? searchEl.value.toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک').trim() : '';
            
            var expFilter = document.getElementById('kanbanExpertFilter');
            var expertVal = expFilter ? expFilter.value : 'all';
            
            var wkFilter = document.getElementById('kanbanWeekFilter');
            var selectedWk = wkFilter ? wkFilter.value : 'all';
            
            var selectedTags = [];
            document.querySelectorAll('.tag-filter-cb:checked').forEach(function(cb){ 
                selectedTags.push(parseInt(cb.value)); 
            });

            document.querySelectorAll('.kanban-column').forEach(function(col) {
                var count = 0; 
                col.querySelectorAll('.k-card').forEach(function(card) { 
                    // گرفتن متن داخل کارت و یکسان‌سازی حروف
                    var cardText = (card.innerText || card.textContent || '').toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک');
                    var cardExpert = card.getAttribute('data-expert') || '';
                    
                    var cardWeeks = []; 
                    try { cardWeeks = JSON.parse(card.getAttribute('data-weeks') || '[]'); } catch(e){}
                    
                    var cardTags = []; 
                    try { cardTags = JSON.parse(card.getAttribute('data-tags') || '[]'); } catch(e){}
                    
                    var matchText = (textInput === '' || cardText.indexOf(textInput) > -1);
                    // استفاده از == به جای === برای جلوگیری از خطای نوع متغیر (String vs Number)
                    var matchExpert = (expertVal === 'all' || cardExpert == expertVal);
                    var matchWeek = (selectedWk === 'all' || cardWeeks.includes(selectedWk));
                    
                    var matchTagsFlag = true;
                    if (selectedTags.length > 0) {
                        matchTagsFlag = selectedTags.some(function(tg) { return cardTags.includes(tg); });
                    }

                    // اعمال نتیجه فیلتر روی نمایش کارت
                    if (matchText && matchExpert && matchWeek && matchTagsFlag) {
                        card.style.display = 'block'; 
                        count++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // آپدیت عدد نشانگر بالای ستون
                var badge = col.querySelector('.col-count'); 
                if (badge) badge.innerText = count;
            });
        } catch (err) {
            console.error('Kanban Filter Error:', err);
        }
    };

    // افزودن رویداد اتوماتیک برای جستجوی تایپی زنده (بدون نیاز به زدن دکمه)
    document.addEventListener("DOMContentLoaded", function() {
        var searchInput = document.getElementById('kanbanLiveFilter');
        if (searchInput) {
            searchInput.addEventListener('input', window.filterKanbanCards);
        }
    });

    window.cyclePinnedMessage = function() {
        if(!window.currentPinnedMessages || window.currentPinnedMessages.length === 0) return;
        window.currentPinnedIndex = (window.currentPinnedIndex + 1) % window.currentPinnedMessages.length;
        window.updatePinnedBarUI();
        
        var targetMsg = document.getElementById('msg-' + window.currentPinnedMessages[window.currentPinnedIndex].id);
        if(targetMsg) {
            var box = document.getElementById('commentsBox');
            if (box) {
                var targetTop = targetMsg.offsetTop - box.offsetTop - 50;
                box.scrollTo({ top: targetTop, behavior: 'smooth' });
            }
            var bubble = targetMsg.querySelector('.message-bubble');
            if(bubble) {
                bubble.style.transition = 'background 0.5s';
                bubble.style.backgroundColor = '#fef3c7';
                setTimeout(function() { bubble.style.backgroundColor = ''; }, 2000);
            }
        }
    };

    window.unpinCurrentMessage = function(e) {
        e.stopPropagation();
        if(window.currentPinnedId) { window.apiPost('unpin_comment', { msg_id: window.currentPinnedId }).then(function() { window.loadChatDetails(); }); }
    };

    window.toggleWeekDropdown = function(btn) {
        var menu = btn.nextElementSibling;
        menu.style.display = (menu.style.display === 'none' || menu.style.display === '') ? 'block' : 'none';
    };

    window.openProvinceModal = function() { document.getElementById('provinceModal').style.display = 'flex'; };

    window.switchProvView = function(view) {
        document.getElementById('btnBackToProvinces').style.display = 'none';
        if(view === 'tree') { document.getElementById('provincesTreeView').style.display = 'block'; document.getElementById('provincesGridView').style.display = 'none'; document.getElementById('customersListView').style.display = 'none'; } 
        else { document.getElementById('provincesTreeView').style.display = 'none'; document.getElementById('provincesGridView').style.display = 'block'; document.getElementById('customersListView').style.display = 'none'; }
    };

    window.toggleTreeNode = function(provinceName, md5Prov) {
        var body = document.getElementById('tree_body_' + md5Prov); var icon = document.getElementById('tree_icon_' + md5Prov);
        if (body.style.display === 'block') { body.style.display = 'none'; icon.innerText = '▼'; return; }
        body.style.display = 'block'; icon.innerText = '▲';
        if (body.innerHTML.trim() === '') {
            body.innerHTML = '<div style="text-align:center; padding:15px;">⏳...</div>';
            window.apiPost('get_province_customers', { province: provinceName, board_id: window.currentBoardId }).then(function(res) {
                if (res.status === 'success') window.renderCustomersHTML(res.customers, body, 'tree_' + md5Prov, provinceName);
            });
        }
    };

    window.loadCustomersForProvinceGrid = function(provinceName) {
        document.getElementById('provincesGridView').style.display = 'none'; document.getElementById('customersListView').style.display = 'block'; document.getElementById('btnBackToProvinces').style.display = 'block';
        var tbody = document.getElementById('customersTableBody'); tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">⏳...</td></tr>';
        window.apiPost('get_province_customers', { province: provinceName, board_id: window.currentBoardId }).then(function(res) {
            if (res.status === 'success') window.renderCustomersHTML(res.customers, tbody, 'grid', provinceName, true);
        });
    };

    window.showProvinceGrid = function() { window.switchProvView('grid'); };

    window.renderCustomersHTML = function(customers, container, prefix, provinceName, isTableBody = false) {
        document.getElementById('provinceModalTitle').innerText = provinceName;
        if (customers.length === 0) { container.innerHTML = isTableBody ? '<tr><td colspan="7">مشتری یافت نشد.</td></tr>' : 'مشتری یافت نشد.'; return; }
        var stageOptions = ''; window.kanbanStages.forEach(function(s) { stageOptions += '<option value="'+s.id+'">'+s.name+'</option>'; });
        
        var weekOptionsHTML = window.allFutureWeeks.map(function(wk) { return '<label class="week-item-label"><input type="checkbox" value="'+wk.id+'" class="week-cb"><span>'+wk.label+'</span></label>'; }).join('');

        var html = '';
        if(!isTableBody) {
            html += '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px; width:100%;"><input type="text" class="form-control" placeholder="🔍 جستجو..." onkeyup="window.filterTreeCustomers(\''+prefix+'\', this.value)" style="flex:1; min-width:200px; max-width:300px; padding:6px 10px; font-size:0.85rem;"><button class="btn btn-primary" style="font-size:0.85rem; padding:6px 12px;" onclick="window.bulkAdd(\''+prefix+'\')">➕ افزودن گروهی</button></div><div class="table-responsive"><table class="prov-cust-table"><thead><tr><th><input type="checkbox" onchange="window.toggleAllCust(this, \''+prefix+'\')"></th><th>نام مشتری</th><th>پیگیری کننده</th><th>برچسب‌ها</th><th style="color:#166534;">هفته</th><th>مرحله</th><th>عملیات</th></tr></thead><tbody>';
        }
        customers.forEach(function(c) {
            var isInBoard = parseInt(c.is_in_board) > 0; 
            var tagsHtml = '';
            if (c.tags_data) {
                c.tags_data.split('||').forEach(function(t) { var p = t.split('::'); tagsHtml += '<span class="tag-badge" style="background-color:'+p[1]+'">'+p[0]+'</span>'; });
            }
            
            var flwName = c.follower_names ? window.escapeHtml(c.follower_names) : 'ندارد';
            var weekDropdown = isInBoard ? '---' : '<div class="week-dropdown-container" style="position:relative;"><button type="button" class="btn btn-success" style="font-size:11px; padding:4px 8px;" onclick="window.toggleWeekDropdown(this)">📅 انتخاب هفته</button><div class="week-dropdown-menu" style="display:none; position:absolute; background:white; border:1px solid #cbd5e1; border-radius:8px; padding:8px; z-index:100; max-height:180px; overflow-y:auto; box-shadow:0 4px 10px rgba(0,0,0,0.1); width:210px; text-align:right;"><div class="week-menu-box">'+weekOptionsHTML+'</div></div></div>';
            var actionBtn = isInBoard ? '<span style="background:#fef2f2; color:#ef4444; padding:4px 8px; border-radius:6px; font-size:11px;">✅ ثبت شده</span>' : '<button class="btn btn-success action-btn-'+c.id+'" style="padding:4px;" onclick="window.doQuickAddCustom('+c.id+')">➕</button>';
            
            html += '<tr class="cust-row-'+prefix+'" data-cust-id="'+c.id+'"><td><input type="checkbox" class="cust-check-'+prefix+' cust-cb-'+c.id+'" value="'+c.id+'" '+(isInBoard?'disabled':'')+'></td><td style="white-space:normal; word-wrap:break-word; font-weight:bold;">🏢 '+c.company_name+'</td><td><span style="background:#f1f5f9; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:bold; color:#475569;">'+flwName+'</span></td><td><div class="tags-wrapper">'+tagsHtml+'</div></td><td>'+weekDropdown+'</td><td><select class="form-control stage-sel-'+c.id+'" id="stage_'+prefix+'_'+c.id+'" '+(isInBoard?'disabled':'')+'>'+stageOptions+'</select></td><td class="action-td-'+c.id+'">'+actionBtn+'</td></tr>';
        });
        if(!isTableBody) html += '</tbody></table></div>';
        container.innerHTML = html;
    };

    window.doQuickAddCustom = function(cId) {
        var row = document.querySelector('tr[data-cust-id="'+cId+'"]');
        if(!row) return;
        var stageId = row.querySelector('select[id^="stage_"]').value;
        var checkedWeeks = Array.from(row.querySelectorAll('.week-cb:checked')).map(function(cb) { return cb.value; });
        if(checkedWeeks.length === 0) { alert('انتخاب حداقل یک هفته الزامی است.'); return; }
        window.doQuickAdd([{ id: cId, stage_id: stageId, selected_weeks: checkedWeeks }]);
    };

    window.filterTreeCustomers = function(prefix, val) { document.querySelectorAll('.cust-row-'+prefix).forEach(function(r) { r.style.display = r.querySelector('td:nth-child(2)').textContent.toLowerCase().includes(val.toLowerCase()) ? '' : 'none'; }); };
    window.filterProvinceCustomers = function() { var v = document.getElementById('custSearchInput').value.toLowerCase(); document.querySelectorAll('.cust-row-grid').forEach(function(r) { r.style.display = r.querySelector('td:nth-child(2)').textContent.toLowerCase().includes(v) ? '' : 'none'; }); };
    window.toggleAllCustGrid = function(src) { window.toggleAllCust(src, 'grid'); };
    window.toggleAllCust = function(src, prefix) { document.querySelectorAll('.cust-check-'+prefix+':not(:disabled)').forEach(function(cb) { if(cb.closest('tr').style.display !== 'none') cb.checked = src.checked; }); };
    window.bulkAddGrid = function() { window.bulkAdd('grid'); };
    window.bulkAdd = function(prefix) {
        var selected = []; 
        var invalid = false;
        document.querySelectorAll('.cust-check-'+prefix+':checked:not(:disabled)').forEach(function(cb) { 
            var row = cb.closest('tr');
            if(row && row.style.display !== 'none') { 
                var sId = row.querySelector('select[id^="stage_"]').value;
                var checkedWeeks = Array.from(row.querySelectorAll('.week-cb:checked')).map(function(w) { return w.value; });
                if(checkedWeeks.length === 0) { invalid = true; }
                selected.push({ id: cb.value, stage_id: sId, selected_weeks: checkedWeeks }); 
            } 
        });
        if(invalid) { alert('انتخاب حداقل یک هفته برای رکوردهای انتخابی الزامی است.'); return; }
        if (selected.length === 0) return; window.doQuickAdd(selected);
    };

    window.doQuickAdd = function(customersArray) {
        window.apiPost('quick_add_opportunity', { board_id: window.currentBoardId, customers_data: JSON.stringify(customersArray) }).then(function(res) {
            if (res.status === 'success') {
                alert('اطلاعات با موفقیت ثبت شد'); window.needsBoardRefresh = true;
                customersArray.forEach(function(c) {
                    document.querySelectorAll('.action-td-'+c.id).forEach(function(td) { td.innerHTML = '<span style="background:#fef2f2; color:#ef4444; padding:4px 8px; border-radius:6px; font-size:11px;">✅ ثبت شده</span>'; });
                    document.querySelectorAll('.cust-cb-'+c.id).forEach(function(cb) { cb.checked = false; cb.disabled = true; });
                    document.querySelectorAll('.stage-sel-'+c.id).forEach(function(s) { s.disabled = true; });
                });
            } else { alert('خطا: ' + res.message); }
        });
    };

    window.toggleEmojiPanel = function() { var p = document.getElementById('emojiPanel'); p.style.display = p.style.display === 'flex' ? 'none' : 'flex'; };
    window.showFilePreview = function(input) {
        if(input.files.length > 0) {
            document.getElementById('filePreviewBox').innerHTML = '<div style="flex:1; text-align:right;"><div style="font-weight:bold; color:#2563eb; font-size:0.75rem;">📎 فایل انتخابی:</div><div style="font-size:0.85rem; color:#334155;">'+window.escapeHtml(input.files[0].name)+'</div></div><span style="color:red; cursor:pointer; font-size:1.4rem; padding-right:10px;" onclick="document.getElementById(\'fileInput\').value=\'\'; document.getElementById(\'filePreviewBox\').classList.remove(\'active\'); window.toggleInputButtons();">&times;</span>';
            document.getElementById('filePreviewBox').classList.add('active');
        } else { document.getElementById('filePreviewBox').classList.remove('active'); }
        window.toggleInputButtons();
    };
    window.toggleInputButtons = function() {
        var text = document.getElementById('commentInput').innerText.trim(); var files = document.getElementById('fileInput').files.length;
        if(text || files > 0) { document.getElementById('sendBtn').style.display='flex'; document.getElementById('micBtn').style.display='none'; }
        else { document.getElementById('sendBtn').style.display='none'; document.getElementById('micBtn').style.display='flex'; }
    };

    window.contextAction = function(action) {
        document.getElementById('chatContextMenu').style.display = 'none';
        var msgEl = document.getElementById('msg-'+window.activeContextMsgId);
        if(!msgEl) return;
        var contentEl = msgEl.querySelector('.msg-content');
        var text = contentEl ? contentEl.innerText.replace('📌 ', '').trim() : '';

        if(action === 'delete') {
            if(confirm('آیا مایلید این پیام به طور کامل حذف شود؟')) { window.apiPost('delete_comment', { msg_id: window.activeContextMsgId }).then(function() { window.loadChatDetails(); }); }
        } else if(action === 'edit') {
            window.editingMessageId = window.activeContextMsgId; 
            var editBox = document.getElementById('replyPreviewBox');
            editBox.innerHTML = '<div style="flex:1; text-align:right;"><div style="font-weight:bold; color:#10b981; font-size:0.75rem;">✏️ در حال ویرایش...</div><div style="font-size:0.85rem; color:#334155;">'+window.escapeHtml(text.substring(0,40))+'...</div></div><span style="color:red; cursor:pointer; font-size:1.4rem;" onclick="window.editingMessageId=null; document.getElementById(\'replyPreviewBox\').classList.remove(\'active\'); document.getElementById(\'commentInput\').innerText=\'\'; window.toggleInputButtons();">&times;</span>';
            editBox.classList.add('active');
            document.getElementById('commentInput').innerText = text; window.toggleInputButtons();
        } else if(action === 'pin') {
            if(window.currentPinnedMessages.length >= 5) {
                alert('شما حداکثر می‌توانید ۵ پیام را سنجاق کنید.'); return;
            }
            window.apiPost('pin_comment', { msg_id: window.activeContextMsgId }).then(function() { window.loadChatDetails(); });
        } else if(action === 'unpin') {
            window.apiPost('unpin_comment', { msg_id: window.activeContextMsgId }).then(function() { window.loadChatDetails(); });
        } else if(action === 'react') {
            window.apiPost('react_comment', { msg_id: window.activeContextMsgId, emoji: '👍' }).then(function() { window.loadChatDetails(); });
        } else if(action === 'reply') {
            window.currentReplyId = window.activeContextMsgId;
            var replyBox = document.getElementById('replyPreviewBox');
            replyBox.innerHTML = '<div style="flex:1; text-align:right;"><div style="font-weight:bold; color:#2563eb; font-size:0.75rem;">🔄 در حال پاسخ...</div><div style="font-size:0.85rem; color:#334155;">'+window.escapeHtml(text.substring(0,40))+'...</div></div><span style="color:red; cursor:pointer; font-size:1.4rem;" onclick="window.currentReplyId=null; document.getElementById(\'replyPreviewBox\').classList.remove(\'active\');">&times;</span>';
            replyBox.classList.add('active');
        } else if(action === 'copy') { navigator.clipboard.writeText(text).then(function() { alert('متن کپی شد'); }); }
    };

    window.renderComments = function(comments) {
        var comHtml = '';
        window.currentPinnedMessages = [];
        
        comments.forEach(function(c) {
            if (c.is_pinned == 1) window.currentPinnedMessages.push(c);

            var cp = (c.profile_image && c.profile_image != 'default.png') ? '../uploads/profiles/' + c.profile_image : '../assets/images/profile-icon.png';
            var wrapClass = c.is_me ? 'msg-out' : 'msg-in';
            var nameHtml = c.is_me ? '' : '<div class="msg-sender">'+window.escapeHtml(c.first_name)+' '+window.escapeHtml(c.last_name)+'</div>';
            var avatarHtml = c.is_me ? '' : '<img src="'+cp+'" class="msg-avatar">';

            var fileHtml = '';
            if (c.file_path) {
                var ext = c.file_path.split('.').pop().toLowerCase();
                if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
                    fileHtml = '<br><img src="../uploads/chat/'+c.file_path+'" onclick="window.openImageModal(\'../uploads/chat/'+c.file_path+'\')" style="max-width:200px; max-height:200px; border-radius:8px; margin-top:5px; cursor:zoom-in; border:1px solid #cbd5e1;">';
                } else if(c.file_type === 'voice') {
                    fileHtml = '<br><audio controls style="height:35px; width:200px; margin-top:5px;"><source src="../uploads/chat/'+c.file_path+'"></audio>';
                } else {
                    fileHtml = '<br><a href="../uploads/chat/'+c.file_path+'" target="_blank" style="display:inline-block; padding:8px 12px; background:#e0f2fe; border-radius:6px; color:#0369a1; text-decoration:none; font-size:0.85rem; font-weight:bold; margin-top:5px; border:1px solid #bae6fd;">📥 دریافت سند ضمیمه</a>';
                }
            }
            var replyHtml = c.reply_text ? '<div style="background:rgba(0,0,0,0.04); border-right:3px solid #2563eb; padding:5px 8px; font-size:0.75rem; margin-bottom:5px; border-radius:4px; text-align:right;">'+window.escapeHtml(c.reply_text)+'</div>' : '';
            var editHtml = c.is_edited == 1 ? '<span style="font-size:0.6rem; color:#a1a1aa;">(ویرایش شده)</span> ' : '';
            var pinHtml = c.is_pinned == 1 ? '📌 ' : '';
            var reacts = []; try { reacts = JSON.parse(c.reactions || '[]'); } catch(e){}
            var reactsHtml = reacts.length > 0 ? '<div class="msg-reactions">'+reacts.map(function(r) { return '<span class="reaction-badge">'+window.escapeHtml(r.emoji)+'</span>'; }).join('')+'</div>' : '';
            var ticksHtml = c.is_me ? '<span style="color:#3b82f6; margin-right:4px;">✔✔</span>' : '';

            comHtml += '<div class="msg-wrapper '+wrapClass+'" id="msg-'+c.id+'">' + avatarHtml + '<div class="message-bubble">' + nameHtml + replyHtml + '<div class="msg-content">'+pinHtml+window.escapeHtml(c.comment_text)+fileHtml+'</div>' + reactsHtml + '<div class="msg-time">'+editHtml+c.chat_time+' '+ticksHtml+'</div></div></div>';
        });
        var box = document.getElementById('commentsBox'); 
        var shouldScroll = box.scrollHeight - box.scrollTop <= box.clientHeight + 50;
        box.innerHTML = comHtml || '<div style="text-align:center; color:#94a3b8; font-size:0.8rem; margin-top:20px;">پیامی ارسال نشده است.</div>';
        if(shouldScroll || comHtml.length > 0) box.scrollTop = box.scrollHeight;

        if (window.currentPinnedIndex >= window.currentPinnedMessages.length) { window.currentPinnedIndex = 0; }
        window.updatePinnedBarUI();
    };

    window.handleCardClick = function(oppId, event) {
        if(event.target.closest('.card-actions-overlay') || event.target.tagName === 'BUTTON') return;
        window.activeOppId = oppId;
        document.getElementById('oppDetailsModal').style.display = 'flex';
        window.switchMainTab('calls');
        window.loadChatDetails();
    };

    window.loadChatDetails = function() {
        if (!window.activeOppId) return;
        window.apiPost('get_opp_full_details', { opp_id: window.activeOppId }).then(function(res) {
            if(res.status === 'success') {
                var o = res.opportunity; 
                window.activeCustomerName = o.company_name; 
                window.activeCustomerId = o.customer_id;
                
                document.getElementById('detailOppTitle').innerText = o.title; 
                document.getElementById('detailCustomer').innerHTML = '<a href="customer_profile.php?id=' + o.customer_id + '" target="_blank" style="text-decoration:none; color:inherit; transition:0.2s;" onmouseover="this.style.color=\'#2563eb\'" onmouseout="this.style.color=\'inherit\'">' + window.escapeHtml(o.company_name) + ' <i class="fas fa-external-link-alt" style="font-size:0.8rem; opacity:0.6;"></i></a>';
                
                var expertName = o.first_name ? (o.first_name + ' ' + o.last_name) : 'نامشخص';
                var expertPic = (o.profile_image && o.profile_image !== 'default.png') ? '../uploads/profiles/' + o.profile_image : '../assets/images/profile-icon.png';
                document.getElementById('detailExpertName').innerText = expertName;
                document.getElementById('detailExpertImg').src = expertPic;

                if(document.getElementById('taskBoardLock')) document.getElementById('taskBoardLock').value = o.board_name || 'بورد فعلی';
                if(document.getElementById('taskStageLock')) document.getElementById('taskStageLock').value = o.stage_name || 'مرحله فعلی';
                if(document.getElementById('taskCustomerLock')) document.getElementById('taskCustomerLock').value = o.company_name;

                var barHtml = ''; var currentStageIndex = window.kanbanStages.findIndex(function(s) { return s.id == o.stage_id; });
                window.kanbanStages.forEach(function(s, idx) {
                    var stClass = ''; var timeText = ''; var logData = res.logs[s.id]; var accSeconds = logData ? parseInt(logData.accumulated_time || 0) : 0;
                    if (idx < currentStageIndex) { stClass = 'passed'; } 
                    else if (idx === currentStageIndex) { 
                        stClass = 'active'; if (logData && logData.entered_at) { accSeconds += Math.floor(Math.max(0, new Date() - new Date(logData.entered_at.replace(/-/g, '/'))) / 1000); }
                    }
                    if(accSeconds > 0 || idx <= currentStageIndex) {
                        var diffMins = Math.floor(accSeconds / 60);
                        var y = Math.floor(diffMins / 525600); diffMins %= 525600; var m = Math.floor(diffMins / 43200); diffMins %= 43200; var d = Math.floor(diffMins / 1440); diffMins %= 1440; var h = Math.floor(diffMins / 60); var min = diffMins % 60;
                        var tArr = []; if(y>0) tArr.push(y+' سال'); if(m>0) tArr.push(m+' ماه'); if(d>0) tArr.push(d+' روز'); if(h>0) tArr.push(h+' ساعت'); if(min>0 || tArr.length==0) tArr.push(min+' دقیقه');
                        timeText = '<div class="stage-time-elapsed">⏳ '+tArr.slice(0,3).join(' و ')+'</div>';
                    }
                    barHtml += '<div class="stage-segment '+stClass+'"><div>'+s.name+'</div>'+timeText+'</div>';
                });
                document.getElementById('timelineBar').innerHTML = barHtml; 
                
                window.currentOppCalls = res.calls || []; 
                window.currentOppActivities = res.activities || [];
                window.currentOppQuotes = res.quotes || [];
                window.currentOppMissions = res.missions || [];
                window.currentOppTasks = res.tasks || [];
                window.currentOppNotes = res.notes || [];
                
                window.renderComments(res.comments);
                
                var activeTabEl = document.querySelector('.nav-tab-item.active');
                if(activeTabEl) {
                    var match = activeTabEl.getAttribute('onclick').match(/'([^']+)'/);
                    if(match && match[1] !== 'chat') window.switchMainTab(match[1]);
                }
            }
        });
    };

    window.sendComment = function(e) {
        if(e) e.preventDefault(); var input = document.getElementById('commentInput'); var text = input.innerText.trim(); var fileInput = document.getElementById('fileInput');
        if(!text && fileInput.files.length === 0) return;
        
        if (window.editingMessageId) {
            window.apiPost('edit_comment', { msg_id: window.editingMessageId, message: text }).then(function(res) {
                if(res.status==='success'){ 
                    window.editingMessageId=null; input.innerText=''; 
                    document.getElementById('replyPreviewBox').classList.remove('active');
                    window.toggleInputButtons(); window.loadChatDetails(); 
                }
            });
        } else {
            var fd = new FormData();
            fd.append('action', 'save_comment'); fd.append('csrf_token', window.csrfToken);
            fd.append('opp_id', window.activeOppId); fd.append('comment', text);
            if(window.currentReplyId) fd.append('reply_to_id', window.currentReplyId);
            if(fileInput.files.length > 0) fd.append('file', fileInput.files[0]);
            
            fetch(window.location.href.split('?')[0], { 
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(r) { return r.json(); }).then(function(res) {
                if(res.status === 'success'){
                    input.innerText=''; window.currentReplyId=null; document.getElementById('replyPreviewBox').classList.remove('active');
                    fileInput.value=''; document.getElementById('filePreviewBox').classList.remove('active');
                    window.toggleInputButtons(); window.loadChatDetails();
                } else alert('خطا در ارسال پیام');
            }).catch(function() { alert('خطا در ارتباط.'); });
        }
    };

    window.startRecording = async function() {
        if (!navigator.mediaDevices) return alert("میکروفون یافت نشد");
        try {
            var stream = await navigator.mediaDevices.getUserMedia({ audio: true }); window.mediaRecorder = new MediaRecorder(stream); window.audioChunks = [];
            window.mediaRecorder.obtainData = function(e) { if (e.data.size > 0) window.audioChunks.push(e.data); }; window.mediaRecorder.ondataavailable = window.mediaRecorder.obtainData; window.mediaRecorder.start();
            document.getElementById('recordingUI').classList.add('active'); document.getElementById('commentInput').style.display = 'none'; document.getElementById('micBtn').style.display = 'none';
            var sec = 0; window.recordingInterval = setInterval(function() { sec++; document.getElementById('recTimer').innerText = "00:" + sec.toString().padStart(2,'0'); }, 1000);
        } catch(e) {}
    };
    window.cancelRecording = function() { if(window.mediaRecorder) { window.mediaRecorder.stop(); window.mediaRecorder.stream.getTracks().forEach(function(t) { t.stop(); }); } clearInterval(window.recordingInterval); document.getElementById('recordingUI').classList.remove('active'); document.getElementById('commentInput').style.display = 'block'; document.getElementById('micBtn').style.display = 'flex'; };
    window.stopAndSendRecording = function() { 
        if(window.mediaRecorder) { 
            window.mediaRecorder.onstop = function() { 
                var fd = new FormData(); fd.append('action', 'save_comment'); fd.append('csrf_token', window.csrfToken);
                fd.append('opp_id', window.activeOppId); fd.append('type', 'voice'); fd.append('file', new Blob(window.audioChunks, { type: 'audio/webm' }), 'voice.webm'); 
                fetch(window.location.href.split('?')[0], { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function(r){ return r.json(); }).then(function(){ window.loadChatDetails(); }); 
            }; 
            window.mediaRecorder.stop(); window.mediaRecorder.stream.getTracks().forEach(function(t){ t.stop(); }); 
        } 
        window.cancelRecording(); 
    };

    window.draggedCardId = null; window.draggedOldStageId = null;
    window.dragStart = function(e, cardId, oldStageId) { 
        window.clearCardHold(); 
        window.draggedCardId = cardId; window.draggedOldStageId = oldStageId; 
        e.dataTransfer.setData("text/plain", cardId); 
        setTimeout(function() { e.target.classList.add('dragging'); }, 0); 
    };
    document.addEventListener('dragend', function(e) { if(e.target.classList.contains('k-card')) e.target.classList.remove('dragging'); document.querySelectorAll('.kanban-column-body').forEach(function(col) { col.classList.remove('drag-over'); }); });
    
    window.allowDrop = function(e) {
        e.preventDefault(); var colBody = e.target.closest('.kanban-column-body');
        if (colBody) {
            colBody.classList.add('drag-over'); var draggable = document.querySelector('.dragging');
            var afterElement = [...colBody.querySelectorAll('.k-card:not(.dragging)')].reduce(function(closest, child) {
                var box = child.getBoundingClientRect(); var offset = e.clientY - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) return { offset: offset, element: child }; else return closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
            if (draggable) { if (afterElement == null) colBody.appendChild(draggable); else colBody.insertBefore(draggable, afterElement); }
        }
    };
    window.drop = function(e, newStageId) {
        e.preventDefault(); var col = e.target.closest('.kanban-column-body');
        if (col && window.draggedCardId) {
            col.classList.remove('drag-over'); var card = document.getElementById('card_' + window.draggedCardId);
            var oldC = document.getElementById('count_stage_' + window.draggedOldStageId), newC = document.getElementById('count_stage_' + newStageId);
            if (window.draggedOldStageId !== newStageId && oldC && newC) { oldC.innerText = parseInt(oldC.innerText) - 1; newC.innerText = parseInt(newC.innerText) + 1; }
            card.setAttribute('ondragstart', "window.dragStart(event, "+window.draggedCardId+", "+newStageId+")");
            window.apiPost('move_card', { opp_id: window.draggedCardId, stage_id: newStageId }); 
            window.draggedOldStageId = newStageId;
        }
    };

    window.startCardHold = function(oppId) { window.holdTimer = setTimeout(function() { document.getElementById('overlay_' + oppId).style.display = 'flex'; }, 3000); };
    window.clearCardHold = function() { clearTimeout(window.holdTimer); };
    window.closeOverlay = function(oppId, event) { if(event) event.stopPropagation(); document.getElementById('overlay_' + oppId).style.display = 'none'; };
    window.actionDelete = function(oppId, event) { event.stopPropagation(); if (confirm('⚠️ آیا از حذف این فرصت مطمئن هستید؟')) { window.apiPost('delete_opportunity', { opp_id: oppId }).then(function() { window.location.reload(); }); } };
    window.actionConvert = function(oppId, event) { event.stopPropagation(); if (confirm('🎉 آیا مایلید این لید را به مشتری نهایی تبدیل کنید؟')) { window.apiPost('convert_opportunity', { opp_id: oppId }).then(function() { window.location.reload(); }); } };

    window.switchMainTab = function(tabName) {
        document.querySelectorAll('.nav-tab-item').forEach(function(t) { t.classList.remove('active'); t.style.color = '#64748b'; t.style.borderBottom = 'none'; });
        var tabEl = document.querySelector('.nav-tab-item[onclick="window.switchMainTab(\''+tabName+'\')"]');
        if(tabEl) { tabEl.classList.add('active'); tabEl.style.color = '#2563eb'; tabEl.style.borderBottom = '2px solid #2563eb'; }
        
        var container = document.getElementById('tabContentContainer');
        if (tabName === 'calls') {
            if(!window.currentOppCalls || window.currentOppCalls.length === 0) {
                container.innerHTML = '<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:250px; gap:15px;"><span style="font-size:3rem;">📞</span><button class="btn btn-primary" onclick="window.openCallModal(0)">➕ ثبت اولین تماس</button></div>';
            } else {
                var html = '<div style="display:flex; justify-content:flex-end; margin-bottom:15px;"><button class="btn btn-primary" style="padding:4px 10px; font-size:0.8rem;" onclick="window.openCallModal(0)">➕ ثبت تماس جدید</button></div><div style="text-align:right;">';
                window.currentOppCalls.forEach(function(c) {
                    var icon = c.call_type === 'inbound' ? '📥 ورودی' : '📤 خروجی';
                    var editBtn = (c.user_id == window.currentUserId) ? '<button class="btn btn-outline" style="padding:2px 8px; font-size:0.7rem; margin-left:10px; background:#fff;" onclick="window.openCallModal('+c.id+')">✏️ ویرایش</button>' : '';
                    var bg = '#fff'; var borderColor = '#e2e8f0'; var textColor = '#1e293b';
                    if(c.status === 'انجام شده') { bg = '#dcfce7'; borderColor = '#22c55e'; textColor = '#166534'; }
                    else if(c.status === 'تماس برقرار نشد') { bg = '#fee2e2'; borderColor = '#ef4444'; textColor = '#991b1b'; }
                    html += '<div class="call-item" style="background:'+bg+'; border-color:'+borderColor+';"><div class="call-item-header" style="color:'+textColor+';"><span>'+window.escapeHtml(c.subject)+'</span><div>'+editBtn+'<span style="color:#2563eb; font-weight:bold;">'+(c.call_date_fa || c.call_date)+'</span></div></div><div class="call-item-meta"><span>وضعیت: <b>'+c.status+'</b></span>|<span>مرتبط با: <b>'+c.related_to+'</b></span>|<span>نوع: <b>'+icon+'</b></span>|<span>توسط: <b>'+c.first_name+' '+c.last_name+'</b></span></div>'+(c.description ? '<div class="call-item-desc" style="border-top-color:'+borderColor+'; color:'+textColor+'; opacity:0.9;">'+window.escapeHtml(c.description)+'</div>' : '')+'</div>';
                });
                container.innerHTML = html + '</div>';
            }
        } else if (tabName === 'missions') {
            if(!window.currentOppMissions || window.currentOppMissions.length === 0) {
                container.innerHTML = '<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:250px; gap:15px;"><span style="font-size:3rem;">🚗</span><a href="mission_create.php?customer_id='+window.activeCustomerId+'" target="_blank" class="btn btn-primary">➕ ثبت مأموریت جدید</a></div>';
            } else {
                var html = '<div style="display:flex; justify-content:flex-end; margin-bottom:15px;"><a href="mission_create.php?customer_id='+window.activeCustomerId+'" target="_blank" class="btn btn-primary" style="padding:4px 10px; font-size:0.8rem;">➕ ثبت مأموریت جدید</a></div><div style="text-align:right;">';
                window.currentOppMissions.forEach(function(m) { 
                    var statusMap = { 'pending': 'در انتظار تایید', 'pending_admin': 'در انتظار تایید', 'approved': 'تایید اولیه', 'expenses_open': 'در حال ثبت هزینه', 'expenses_submitted': 'ارسال به مالی', 'finance_process': 'بررسی مالی', 'finance_review': 'در حال بررسی مالی', 'completed': 'پایان یافته', 'rejected': 'رد شده' };
                    var stLabel = statusMap[m.status] || m.status;
                    html += '<div class="call-item"><div class="call-item-header"><span>🚗 '+window.escapeHtml(m.subject)+'</span><span style="color:#2563eb;">'+(m.mission_date_fa || m.mission_date)+'</span></div><div class="call-item-meta"><span>وضعیت: <b>'+stLabel+'</b></span>|<span>توسط: <b>'+m.first_name+' '+m.last_name+'</b></span></div><div class="call-item-desc">'+window.escapeHtml(m.description)+'</div></div>'; 
                });
                container.innerHTML = html + '</div>';
            }
        } else if (tabName === 'tasks') {
            if(!window.currentOppTasks || window.currentOppTasks.length === 0) {
                container.innerHTML = '<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:250px; gap:15px;"><span style="font-size:3rem;">📝</span><button class="btn btn-primary" onclick="window.openTaskModalBulk()">➕ ایجاد وظیفه</button></div>';
            } else {
                var html = '<div style="display:flex; justify-content:flex-end; margin-bottom:15px;"><button class="btn btn-primary" style="padding:4px 10px; font-size:0.8rem;" onclick="window.openTaskModalBulk()">➕ ایجاد وظیفه</button></div><div style="text-align:right;">';
                window.currentOppTasks.forEach(function(t) { 
                    var fLink = t.file_path ? `<br><a href="../uploads/crm_tasks/${t.file_path}" target="_blank" style="font-size:11px; color:#0369a1; font-weight:bold; background:#e0f2fe; padding:4px 8px; border-radius:6px; border:1px solid #bae6fd; display:inline-flex; align-items:center; gap:4px; margin-top:6px;">📥 دریافت مستندات</a>` : '';
                    html += '<div class="call-item"><div class="call-item-header"><span>📝 '+window.escapeHtml(t.subject)+'</span><span style="color:#ef4444;">موعد: '+(t.deadline_fa || t.deadline)+'</span></div><div class="call-item-meta"><span>وضعیت: <b>'+t.status+'</b></span></div><div class="call-item-desc">'+window.escapeHtml(t.description)+fLink+'</div></div>'; 
                });
                container.innerHTML = html + '</div>';
            }
        } else if (tabName === 'notes') {
            if(!window.currentOppNotes || window.currentOppNotes.length === 0) {
                container.innerHTML = '<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:250px; gap:15px;"><span style="font-size:3rem;">📋</span><button class="btn btn-primary" onclick="window.openNoteModal()">➕ ثبت اولین یادداشت</button></div>';
            } else {
                var html = '<div style="display:flex; justify-content:flex-end; margin-bottom:15px;"><button class="btn btn-primary" style="padding:4px 10px; font-size:0.8rem;" onclick="window.openNoteModal()">➕ ثبت یادداشت جدید</button></div><div style="text-align:right;">';
                window.currentOppNotes.forEach(function(n) { html += '<div class="call-item"><div style="font-size:0.75rem; color:#64748b; margin-bottom:5px;">ثبت در: '+(n.ndate_fa || n.ndate)+'</div><div style="font-size:0.9rem; color:#1e293b; line-height:1.6;">'+window.escapeHtml(n.note_text).replace(/\n/g, '<br>')+'</div></div>'; });
                container.innerHTML = html + '</div>';
            }
        } else if (tabName === 'quotes') {
            var qStatusMap = {draft:'پیش‌نویس',sent:'ارسال شده',accepted:'تأیید شده',rejected:'رد شده',converted:'تبدیل به فاکتور'};
            var qStatusColor = {draft:'#64748b',sent:'#1d4ed8',accepted:'#15803d',rejected:'#b91c1c',converted:'#6d28d9'};
            var qStatusBg = {draft:'#f1f5f9',sent:'#dbeafe',accepted:'#dcfce7',rejected:'#fee2e2',converted:'#f3e8ff'};
            if(!window.currentOppQuotes || window.currentOppQuotes.length===0) {
                container.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:250px;gap:15px;"><span style="font-size:3rem">🧾</span><a href="fin_preinvoice.php?opportunity_id='+window.activeOppId+'" target="_blank" style="padding:8px 18px;background:#f59e0b;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:13px">➕ صدور پیشفاکتور جدید</a></div>';
            } else {
                var qHtml = '<div style="display:flex;justify-content:flex-end;margin-bottom:12px"><a href="fin_preinvoice.php?opportunity_id='+window.activeOppId+'" target="_blank" style="padding:5px 12px;background:#f59e0b;color:#fff;border-radius:7px;text-decoration:none;font-size:12px;font-weight:700">➕ پیشفاکتور جدید</a></div><div style="direction:rtl">';
                window.currentOppQuotes.forEach(function(q){
                    var st=qStatusMap[q.status]||q.status, sc=qStatusColor[q.status]||'#64748b', sb=qStatusBg[q.status]||'#f1f5f9';
                    qHtml+='<div class="call-item" style="border-right:3px solid #f59e0b">'
                        +'<div class="call-item-header"><span style="color:#92400e;font-weight:700">🧾 '+window.escapeHtml(q.quote_number)+'</span>'
                        +'<span style="font-size:0.8rem;color:#64748b">'+(q.date_fa||q.quote_date)+'</span></div>'
                        +'<div class="call-item-meta" style="margin-top:5px">'
                        +'<span>مبلغ: <b>'+q.total_fmt+' ریال</b></span> | '
                        +'<span>وضعیت: <b style="background:'+sb+';color:'+sc+';padding:2px 8px;border-radius:10px;font-size:11px">'+st+'</b></span>'
                        +'<a href="fin_preinvoice.php?edit='+q.id+'" target="_blank" style="margin-right:10px;font-size:11px;color:#2563eb">مشاهده ←</a>'
                        +'</div></div>';
                });
                container.innerHTML = qHtml + '</div>';
            }
        } else if (tabName === 'history') {
            if(!window.currentOppActivities || window.currentOppActivities.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:50px; color:#94a3b8;">هیچ فعالیتی ثبت نشده است.</div>';
            } else {
                var actHtml = '<div style="text-align:right; direction:rtl;">';
                window.currentOppActivities.forEach(function(a) {
                    var icon = '📝'; if(a.activity_type === 'call') icon = '📞'; else if(a.activity_type === 'mission') icon = '🚗'; else if(a.activity_type === 'history') icon = '⏱️';
                    var cleanDesc = window.escapeHtml(a.description);
                    if (cleanDesc.startsWith(icon)) cleanDesc = cleanDesc.substring(icon.length).trim();
                    if (cleanDesc.startsWith('ثبت تماس:')) cleanDesc = cleanDesc.replace('ثبت تماس:', '').trim();
                    actHtml += '<div style="padding:8px; border-bottom:1px dashed #e2e8f0; display:flex; gap:10px; align-items:flex-start;"><span style="font-size:1.1rem; line-height:1;">'+icon+'</span><div style="flex:1;"><div style="font-size:0.85rem; color:#1e293b; margin-bottom:2px;">'+cleanDesc+'</div><div style="font-size:0.7rem; color:#94a3b8;">توسط '+a.first_name+' '+a.last_name+' - '+(a.created_at_fa || a.created_at)+'</div></div></div>';
                });
                container.innerHTML = actHtml + '</div>';
            }
        }
    };

    window.openCallModal = function(callId) {
        var mdl = document.getElementById('callModal');
        if(!mdl) { alert("فرم تماس یافت نشد!"); return; }
        mdl.style.display = 'flex'; 
        document.getElementById('callOppId').value = window.activeOppId; 
        document.getElementById('editCallId').value = callId;
        document.getElementById('callCustomerName').value = document.getElementById('detailCustomer').innerText.replace('🏢 ', '');
        
        const relSelect = document.getElementById('callRelatedTo');
        if(relSelect && relSelect.options.length <= 1) { 
            window.apiPost('get_call_related_types').then(function(res) {
                if(res.status === 'success') { relSelect.innerHTML = res.data.map(function(t) { return '<option value="'+t.title+'">'+t.title+'</option>'; }).join(''); } 
            }); 
        }
        if (callId > 0) {
            document.getElementById('callModalTitle').innerText = '📞 ویرایش تماس';
            var call = window.currentOppCalls.find(function(c) { return c.id == callId; });
            if (call) {
                document.getElementById('callSubject').value = call.subject; document.getElementById('callSubject').readOnly = true; 
                document.getElementById('callStatus').value = call.status; document.getElementById('callRelatedTo').value = call.related_to;
                document.getElementById('callDate').value = call.call_date_fa || call.call_date; document.getElementById('callDesc').value = call.description || '';
                var ctype = document.querySelector('input[name="callType"][value="'+call.call_type+'"]');
                if (ctype) ctype.checked = true;
            }
        } else {
            document.getElementById('callModalTitle').innerText = '📞 ثبت تماس جدید';
            document.getElementById('callSubject').value = ''; document.getElementById('callSubject').readOnly = false;
            document.getElementById('callStatus').value = 'برنامه ریزی شده'; document.getElementById('callDate').value = ''; document.getElementById('callDesc').value = '';
            var ctypeOut = document.querySelector('input[name="callType"][value="outbound"]');
            if(ctypeOut) ctypeOut.checked = true;
        }
    };

    window.submitCallForm = function(e) {
        e.preventDefault(); 
        var btn = document.getElementById('btnSubmitCall'); 
        
        var callDate = document.getElementById('callDate').value;
        var dts = callDate.split('/');
        if(dts.length === 3) {
            var inputStr = dts[0] + window.p2(dts[1]) + window.p2(dts[2]);
            var today = window.todayJ();
            var todayStr = today[0] + window.p2(today[1]) + window.p2(today[2]);
            if(inputStr < todayStr) {
                alert('تاریخ تماس نمی‌تواند در گذشته باشد! لطفاً تاریخ جاری یا آینده را انتخاب کنید.');
                return;
            }
        }

        btn.disabled = true; btn.innerHTML = '⏳ در حال ثبت...';
        window.apiPost('save_call', {
            opp_id: document.getElementById('callOppId').value, call_id: document.getElementById('editCallId').value, subject: document.getElementById('callSubject').value, 
            status: document.getElementById('callStatus').value, related_to: document.getElementById('callRelatedTo').value, call_date: callDate, 
            call_type: document.querySelector('input[name="callType"]:checked').value, description: document.getElementById('callDesc').value
        }).then(function(res) {
            btn.disabled = false; btn.innerHTML = '💾 ذخیره اطلاعات';
            if(res.status === 'success') { window.closeModal('callModal'); window.loadChatDetails(); } else { alert(res.message || 'خطا در ثبت تماس'); }
        }).catch(function(err) { btn.disabled = false; btn.innerHTML = '💾 ذخیره اطلاعات'; alert('خطا در ارتباط با سرور.'); });
    };

    window.openNoteModal = function() { document.getElementById('noteModal').style.display = 'flex'; document.getElementById('noteText').value = ''; };
    window.submitNoteForm = function(e) { e.preventDefault(); var btn = document.getElementById('btnSubmitNote'); btn.disabled = true; btn.innerHTML = '⏳...'; window.apiPost('save_note', { opp_id: window.activeOppId, note_text: document.getElementById('noteText').value }).then(function(res) { btn.disabled = false; btn.innerHTML = '💾 ذخیره اطلاعات'; if(res.status === 'success') { window.closeModal('noteModal'); window.loadChatDetails(); } }); };
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>