<?php
/*
 * فایل: public_html/admin/crm_calendar.php
 * بلوک ۱: اتصال به دیتابیس و پردازش رویدادهای تقویم (عدم نمایش مأموریت‌های پایان یافته)
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
    if ($isAjax) { 
        header('Content-Type: application/json'); 
        echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است.']); exit; 
    }
    header("Location: ../login.php"); exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$isAdminOrManager = in_array($userRole, ['admin', 'manager', 'sales_manager']);

// ==================== توابع تبدیل تاریخ شمسی ====================
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

// ==================== پردازش AJAX برای دریافت رویدادهای تقویم ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'get_events') {
        $params = [];
        $userFilter = "";
        $oppUserFilter = "";
        $oppParams = [];

        if (!$isAdminOrManager) {
            $userFilter = " AND user_id = ? ";
            $params[] = $userId;
            
            $oppUserFilter = " AND (o.created_by = ? OR EXISTS (SELECT 1 FROM crm_opportunity_assignees WHERE opportunity_id = o.id AND user_id = ?)) ";
            $oppParams = [$userId, $userId];
        }

        $events = [];

        try {
            // ۱. تماس‌ها
            $stmt = $pdo->prepare("SELECT c_call.id, c_call.opportunity_id, c_call.subject, c_call.call_date as ev_date, c.company_name 
                                   FROM crm_opportunity_calls c_call 
                                   JOIN crm_opportunities o ON c_call.opportunity_id = o.id 
                                   JOIN customers c ON o.customer_id = c.id 
                                   WHERE c_call.call_date IS NOT NULL " . str_replace("user_id", "c_call.user_id", $userFilter));
            $stmt->execute($params);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $events[] = ['id' => $row['id'], 'opp_id' => $row['opportunity_id'], 'title' => '📞 ' . $row['subject'], 'customer' => '🏢 ' . $row['company_name'], 'date' => $row['ev_date'], 'type' => 'call', 'color' => '#3b82f6'];
            }

            // ۲. وظایف
            $stmt = $pdo->prepare("SELECT t.id, t.opportunity_id, t.subject, t.deadline as ev_date, c.company_name FROM crm_opportunity_tasks t JOIN crm_opportunities o ON t.opportunity_id = o.id JOIN customers c ON o.customer_id = c.id WHERE t.deadline IS NOT NULL AND t.status != 'انجام شد' " . str_replace("user_id", "t.user_id", $userFilter));
            $stmt->execute($params);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $events[] = ['id' => $row['id'], 'opp_id' => $row['opportunity_id'], 'title' => '📝 ' . $row['subject'], 'customer' => '🏢 ' . $row['company_name'], 'date' => $row['ev_date'], 'type' => 'task', 'color' => '#ef4444'];
            }

            // ۳. مأموریت‌ها (عدم نمایش پایان‌یافته و رد شده)
            $missionUserFilter = !$isAdminOrManager ? " AND mr.user_id = ? " : "";
            $missionParams = !$isAdminOrManager ? [$userId] : [];
            
            $stmt = $pdo->prepare("SELECT mi.id, mr.id as request_id, mt.title as subject, mi.mission_date as ev_date, c.company_name 
                                   FROM mission_items mi 
                                   JOIN mission_requests mr ON mi.request_id = mr.id 
                                   LEFT JOIN mission_types mt ON mi.type_id = mt.id
                                   LEFT JOIN customers c ON mi.customer_id = c.id 
                                   WHERE mi.mission_date IS NOT NULL AND mr.status NOT IN ('completed', 'rejected') $missionUserFilter");
            $stmt->execute($missionParams);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $subject = $row['subject'] ?: 'مأموریت';
                $company = $row['company_name'] ?: 'آزاد';
                $events[] = ['id' => $row['request_id'], 'opp_id' => null, 'title' => '🚗 ' . $subject, 'customer' => '🏢 ' . $company, 'date' => $row['ev_date'], 'type' => 'mission', 'color' => '#8b5cf6'];
            }

            // ۴. تاریخ اقدام فرصت‌ها
            $stmt = $pdo->prepare("SELECT o.id, o.title, o.action_date as ev_date, c.company_name 
                                   FROM crm_opportunities o 
                                   JOIN customers c ON o.customer_id = c.id 
                                   WHERE o.action_date IS NOT NULL AND o.status != 'deleted' $oppUserFilter");
            $stmt->execute($oppParams);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $events[] = ['id' => $row['id'], 'opp_id' => $row['id'], 'title' => '🎯 ' . $row['title'], 'customer' => '🏢 ' . $row['company_name'], 'date' => $row['ev_date'], 'type' => 'opportunity', 'color' => '#10b981'];
            }

            echo json_encode(['status' => 'success', 'data' => $events]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // دریافت جزئیات یک پرونده جهت نمایش در مودال سریع تقویم
    if ($action === 'get_opp_full_details') {
        $oppId = (int)$_POST['opp_id'];
        try {
            $stmt = $pdo->prepare("SELECT o.*, c.company_name, u.first_name, u.last_name, u.profile_image,
                                   (SELECT GROUP_CONCAT(CONCAT(t.title, '::', IFNULL(t.color, '#e0f2fe')) SEPARATOR '||') 
                                    FROM customer_tag_links ctl 
                                    JOIN tags t ON ctl.tag_id = t.id 
                                    WHERE ctl.customer_id = c.id) as tags_data
                                   FROM crm_opportunities o 
                                   JOIN customers c ON o.customer_id = c.id 
                                   LEFT JOIN crm_opportunity_assignees coa ON o.id = coa.opportunity_id 
                                   LEFT JOIN users u ON coa.user_id = u.id 
                                   WHERE o.id = ? LIMIT 1");
            $stmt->execute([$oppId]); 
            $opp = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'opportunity' => $opp]);
        } catch (Exception $e) { 
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
        }
        exit;
    }
}

$pageTitle = 'تقویم کاری و برنامه‌ها';
$basePath = '../';
?>
<?php
$extraCss = '
<style>
    @font-face {
        font-family: "Vazirmatn";
        src: url("../assets/fonts/Vazirmatn-Regular.ttf") format("truetype");
        font-weight: normal;
        font-style: normal;
        font-display: swap;
    }

    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    .main-content { background: #f1f5f9; min-height: calc(100vh - 70px); padding-top: 20px; }
    
    .cal-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; background: white; padding: 15px 25px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    .cal-nav-btn { background: #f8fafc; border: 1px solid #cbd5e1; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; color: #334155; transition: 0.2s; flex-shrink: 0; }
    .cal-nav-btn:hover { background: #e2e8f0; color: #2563eb; }
    .cal-title { font-size: 1.3rem; font-weight: 900; color: #1e293b; display: flex; align-items: center; justify-content: center; gap: 10px; }
    
    .view-toggle { display: flex; background: #e2e8f0; border-radius: 8px; padding: 3px; }
    .view-toggle button { background: transparent; border: none; padding: 6px 15px; font-weight: bold; color: #475569; border-radius: 6px; cursor: pointer; transition: 0.2s; }
    .view-toggle button.active { background: white; color: #2563eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    
    .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; }
    .cal-dow { background: #334155; color: white; text-align: center; padding: 12px; border-radius: 8px; font-weight: bold; font-size: 0.95rem; }
    .cal-dow.friday { background: #ef4444; }
    
    .cal-cell { background: white; border: 1px solid #e2e8f0; border-radius: 10px; min-height: 130px; padding: 8px; display: flex; flex-direction: column; transition: 0.2s; }
    .cal-cell.week-mode { min-height: 400px; }
    .cal-cell:hover { border-color: #94a3b8; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
    .cal-cell.empty { background: transparent; border: none; box-shadow: none; pointer-events: none; }
    .cal-cell.today { border: 2px solid #3b82f6; background: #eff6ff; }
    
    .cal-day-num { font-size: 1.1rem; font-weight: 800; color: #475569; margin-bottom: 8px; align-self: flex-start; background: #f1f5f9; padding: 3px 8px; border-radius: 6px; display: flex; flex-direction: column; align-items: center; }
    .cal-cell.today .cal-day-num { background: #3b82f6; color: white; }
    
    .cal-events { display: flex; flex-direction: column; gap: 5px; flex: 1; overflow-y: auto; }
    .cal-events::-webkit-scrollbar { width: 4px; }
    .cal-events::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    
    .event-badge { padding: 8px 10px; border-radius: 6px; color: white; cursor: pointer; line-height: 1.5; font-weight: bold; box-shadow: 0 1px 2px rgba(0,0,0,0.1); transition: 0.2s; border-right: 3px solid rgba(0,0,0,0.2); }
    .event-badge:hover { transform: scale(1.02); filter: brightness(1.1); }
    .event-title { font-size: 0.8rem; margin-bottom: 3px; display: block;}
    .event-customer { font-size: 0.7rem; opacity: 0.9; font-weight: normal; display: block;}
    
    .cal-legend { display: flex; gap: 15px; margin-top: 20px; flex-wrap: wrap; justify-content: center; background: white; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; }
    .legend-item { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: bold; color: #475569; }
    .legend-color { width: 14px; height: 14px; border-radius: 4px; }

    .d-name { display: none; }

    /* استایل مودال سریع */
    .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(4px); }
    .modal-content { background:#fff; width:90%; max-width:500px; border-radius:16px; overflow:hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); display: flex; flex-direction: column; animation: modalIn 0.3s ease; }
    @keyframes modalIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .modal-header { padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 20px; overflow-y: auto; }
    
    /* رسپانسیو موبایل */
    @media (max-width: 992px) {
        .cal-grid { display: flex; flex-direction: column; gap: 15px; }
        .cal-dow { display: none; }
        .cal-cell { min-height: auto !important; border: 1px solid #cbd5e1; }
        .cal-cell.empty { display: none; }
        .cal-day-num { font-size: 1.2rem; margin-bottom: 10px; display: block; width: 100%; text-align: center; }
    }

    @media (max-width: 768px) {
        .main-content { padding-top: 70px !important; padding-bottom: 20px !important; }
        .cal-header { flex-direction: column; gap: 12px; padding: 15px; text-align: center; }
        .cal-header .cal-nav-wrap { display: flex; width: 100%; justify-content: space-between; gap: 10px; }
        .cal-header .cal-nav-btn { flex: 1; text-align: center; font-size: 0.9rem; padding: 10px 5px; }
        .cal-title { font-size: 1.1rem; width: 100%; margin-bottom: 5px; }
        .view-toggle { width: 100%; display: flex; }
        .view-toggle button { flex: 1; }
        .cal-cell { padding: 12px; flex-direction: row; align-items: flex-start; gap: 15px; }
        .cal-cell.has-events .cal-day-num { width: 70px; height: 70px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border-radius: 12px; background: #f1f5f9; color: #1e293b; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); }
        .cal-cell.today.has-events .cal-day-num { background: #3b82f6; color: white; }
        .cal-cell:not(.has-events) { padding: 8px 12px; align-items: center; background: #f8fafc; border: 1px dashed #e2e8f0; }
        .cal-cell:not(.has-events) .cal-day-num { width: auto; height: auto; flex-direction: row; gap: 8px; background: transparent; padding: 0; box-shadow: none; margin: 0; color: #94a3b8; }
        .d-name { display: block; font-size: 0.75rem; font-weight: normal; opacity: 0.8; }
        .cal-cell:not(.has-events) .d-name { display: inline; font-size: 0.85rem; }
        .d-num { font-size: 1.4rem; font-weight: 900; line-height: 1; margin-bottom: 2px; }
        .cal-cell:not(.has-events) .d-num { font-size: 1rem; margin-bottom: 0; }
        .cal-events { flex: 1; flex-direction: column; gap: 6px; }
        .cal-legend { flex-direction: column; align-items: flex-start; gap: 10px; padding: 15px 20px; }
    }
</style>
';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        
        <div class="cal-header">
            <div class="cal-nav-wrap">
                <button id="btnNext" class="cal-nav-btn" onclick="window.navigateCal(1)">▶ بعدی</button>
                <button id="btnPrev" class="cal-nav-btn" onclick="window.navigateCal(-1)">قبلی ◀</button>
            </div>
            
            <div style="display:flex; flex-direction:column; align-items:center; flex:1;">
                <div class="cal-title">📅 <span id="calMonthYear">در حال بارگذاری...</span></div>
                <div class="view-toggle">
                    <button id="btnViewMonth" class="active" onclick="window.toggleView('month')">ماهانه</button>
                    <button id="btnViewWeek" onclick="window.toggleView('week')">هفتگی</button>
                </div>
            </div>

            <div class="cal-nav-wrap" style="justify-content: center;">
                <button class="cal-nav-btn" onclick="window.goToday()" style="background:#eff6ff; color:#2563eb; border-color:#bfdbfe; width:100%;">امروز</button>
            </div>
        </div>

        <div class="cal-grid" id="calendarGrid">
            <div class="cal-dow">شنبه</div>
            <div class="cal-dow">یکشنبه</div>
            <div class="cal-dow">دوشنبه</div>
            <div class="cal-dow">سه‌شنبه</div>
            <div class="cal-dow">چهارشنبه</div>
            <div class="cal-dow">پنج‌شنبه</div>
            <div class="cal-dow friday">جمعه</div>
        </div>

        <div class="cal-legend">
            <div class="legend-item"><div class="legend-color" style="background:#10b981;"></div> تاریخ اقدام (فرصت)</div>
            <div class="legend-item"><div class="legend-color" style="background:#3b82f6;"></div> تماس‌ها</div>
            <div class="legend-item"><div class="legend-color" style="background:#ef4444;"></div> وظایف (مهلت)</div>
            <div class="legend-item"><div class="legend-color" style="background:#8b5cf6;"></div> مأموریت‌ها</div>
        </div>

    </div>
</main>

<div id="eventDetailModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h4 style="margin:0; font-weight:bold; font-size:1.1rem; color:#1e293b;" id="evModalTitle">جزئیات پرونده و مشتری</h4>
            <span style="cursor:pointer; font-size:1.5rem; color:#ef4444;" onclick="document.getElementById('eventDetailModal').style.display='none'">&times;</span>
        </div>
        <div class="modal-body" id="evModalBody" style="line-height: 1.8;">
            <div style="text-align:center; color:#64748b;">⏳ در حال بارگذاری اطلاعات...</div>
        </div>
        <div style="padding:15px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:center; align-items:center;">
            <a id="evModalLink" href="#" class="btn btn-success" style="text-decoration:none; width: 100%; display:flex; justify-content:center; align-items:center; text-align:center;">🚀 ورود به پرونده در کانبان و پیام‌رسان</a>
        </div>
    </div>
</div>
<script>
    // ==================================================================
    // توابع ایمن جاوااسکریپت و الگوریتم‌های تقویم شمسی
    // ==================================================================
    window.csrfToken = '<?php echo function_exists("create_csrf_token") ? create_csrf_token() : ""; ?>';
    
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
    window.todayJ = function() { var d = new Date(); return window.g2j(d.getFullYear(), d.getMonth() + 1, d.getDate()); };
    window.jAdd = function(jy, jm, jd, n) { var g = window.j2g(jy, jm, jd); var d = new Date(g[0], g[1] - 1, g[2] + n); return window.g2j(d.getFullYear(), d.getMonth() + 1, d.getDate()); };
    window.wkStart = function(jy, jm, jd) { var dow = window.jDow(jy, jm, jd); return window.jAdd(jy, jm, jd, -dow); };
    window.escapeHtml = function(text) { return (text||'').toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"); };

    window.monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    window.dowNames = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];
    
    // متغیرهای وضعیت تقویم
    window.currentJDate = window.todayJ();
    window.viewDate = window.currentJDate.slice(); // تاریخ مرجع [سال، ماه، روز]
    window.currentView = 'month'; // 'month' یا 'week'
    window.allEvents = [];

    // ==================== AJAX و لود داده‌ها ====================
    window.fetchEventsAndRender = function() {
        var fd = new FormData();
        fd.append('action', 'get_events');
        fd.append('csrf_token', window.csrfToken);
        
        fetch('crm_calendar.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if(res && res.status === 'success') {
                window.allEvents = res.data.map(function(ev) {
                    if(ev.date) {
                        var pts = ev.date.split(' ')[0].split('-');
                        if(pts.length === 3) {
                            var jd = window.g2j(parseInt(pts[0]), parseInt(pts[1]), parseInt(pts[2]));
                            ev.jY = jd[0]; ev.jM = jd[1]; ev.jD = jd[2];
                        }
                    }
                    return ev;
                });
                window.renderCalendar();
            }
        });
    };

    // ==================== مودال کارت مشتری (اصلاح شده) ====================
    window.openEventModal = function(oppId, evType, evId) {
        // اگر نوع فعالیت مأموریت باشد، مستقیماً به صفحه جزئیات آن مأموریت هدایت شود
        if (evType === 'mission') {
            window.location.href = 'mission_view.php?id=' + evId;
            return;
        }

        // برای بقیه فعالیت‌ها، مودال جزئیات فرصت باز شود
        var modal = document.getElementById('eventDetailModal');
        var body = document.getElementById('evModalBody');
        var link = document.getElementById('evModalLink');
        
        modal.style.display = 'flex';
        body.innerHTML = '<div style="text-align:center; color:#64748b;">⏳ در حال بارگذاری اطلاعات...</div>';
        
        var fd = new FormData();
        fd.append('action', 'get_opp_full_details');
        fd.append('opp_id', oppId);
        fd.append('csrf_token', window.csrfToken);

        fetch('crm_calendar.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.status === 'success' && res.opportunity) {
                var o = res.opportunity;
                link.href = 'crm_opportunities_kanban.php?board_id=' + o.board_id + '&jump_conv=' + o.id;
                
                var tagsHtml = '<span style="color:#94a3b8; font-size:0.8rem;">بدون برچسب</span>';
                if (o.tags_data) {
                    tagsHtml = o.tags_data.split('||').map(function(t) { 
                        var p = t.split('::'); 
                        return '<span style="background-color:'+p[1]+'; color:#fff; padding:2px 6px; border-radius:4px; font-size:0.75rem; font-weight:bold; margin-left:4px;">'+window.escapeHtml(p[0])+'</span>'; 
                    }).join('');
                }

                body.innerHTML = `
                    <div style="display:flex; align-items:center; gap:15px; margin-bottom:15px; background:#eff6ff; padding:15px; border-radius:10px; border:1px solid #bfdbfe;">
                        <div style="font-size:2rem;">🏢</div>
                        <div>
                            <div style="font-weight:bold; font-size:1.1rem; color:#1e3a8a;">${window.escapeHtml(o.company_name)}</div>
                            <div style="font-size:0.85rem; color:#3b82f6;">${window.escapeHtml(o.title)}</div>
                        </div>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:10px; font-size:0.9rem;">
                        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed #e2e8f0; padding-bottom:5px;">
                            <span style="color:#64748b; font-weight:bold;">کارشناس فروش:</span>
                            <span style="color:#0f172a;">${window.escapeHtml(o.first_name + ' ' + o.last_name)}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed #e2e8f0; padding-bottom:5px;">
                            <span style="color:#64748b; font-weight:bold;">برچسب‌ها:</span>
                            <div style="display:flex; flex-wrap:wrap; gap:4px; justify-content:flex-end;">${tagsHtml}</div>
                        </div>
                    </div>
                `;
            } else {
                body.innerHTML = '<div style="color:red; text-align:center;">خطا در لود اطلاعات</div>';
            }
        });
    };

    // ==================== کنترلرهای تقویم ====================
    window.toggleView = function(view) {
        window.currentView = view;
        document.getElementById('btnViewMonth').classList.remove('active');
        document.getElementById('btnViewWeek').classList.remove('active');
        
        if (view === 'month') {
            document.getElementById('btnViewMonth').classList.add('active');
            document.getElementById('btnNext').innerText = '▶ ماه بعد';
            document.getElementById('btnPrev').innerText = 'ماه قبل ◀';
            window.viewDate[2] = 1; 
        } else {
            document.getElementById('btnViewWeek').classList.add('active');
            document.getElementById('btnNext').innerText = '▶ هفته بعد';
            document.getElementById('btnPrev').innerText = 'هفته قبل ◀';
        }
        window.renderCalendar();
    };

    window.navigateCal = function(step) {
        if (window.currentView === 'month') {
            window.viewDate[1] += step;
            if(window.viewDate[1] > 12) { window.viewDate[1] = 1; window.viewDate[0]++; }
            else if(window.viewDate[1] < 1) { window.viewDate[1] = 12; window.viewDate[0]--; }
            window.viewDate[2] = 1;
        } else {
            window.viewDate = window.jAdd(window.viewDate[0], window.viewDate[1], window.viewDate[2], step * 7);
        }
        window.renderCalendar();
    };

    window.goToday = function() {
        window.viewDate = window.currentJDate.slice(); 
        window.renderCalendar();
    };

    window.renderCalendar = function() {
        var grid = document.getElementById('calendarGrid');
        
        var dows = grid.querySelectorAll('.cal-dow');
        grid.innerHTML = '';
        dows.forEach(function(d) { grid.appendChild(d); });

        var vY = window.viewDate[0];
        var vM = window.viewDate[1];
        var vD = window.viewDate[2];

        if (window.currentView === 'month') {
            // ==================== رندر نمای ماهانه ====================
            document.getElementById('calMonthYear').innerText = window.monthNames[vM - 1] + ' ' + vY;
            
            var totalDays = window.jDays(vY, vM);
            var startDow = window.jDow(vY, vM, 1);
            var startOffset = (startDow + 1) % 7; 

            if (window.innerWidth > 992) {
                for(var i=0; i<startOffset; i++) {
                    var emptyCell = document.createElement('div');
                    emptyCell.className = 'cal-cell empty';
                    grid.appendChild(emptyCell);
                }
            }

            for(var d=1; d<=totalDays; d++) {
                var cell = document.createElement('div');
                var isToday = (vY === window.currentJDate[0] && vM === window.currentJDate[1] && d === window.currentJDate[2]);
                
                var dayEvents = window.allEvents.filter(function(ev) {
                    return ev.jY === vY && ev.jM === vM && ev.jD === d;
                });

                var hasEvents = dayEvents.length > 0;
                cell.className = 'cal-cell' + (isToday ? ' today' : '') + (hasEvents ? ' has-events' : '');
                
                var dowIdx = window.jDow(vY, vM, d);
                var dowName = window.dowNames[dowIdx];

                var dayNum = document.createElement('div');
                dayNum.className = 'cal-day-num';
                dayNum.innerHTML = '<span class="d-num">' + d + '</span><span class="d-name">' + dowName + '</span>';
                cell.appendChild(dayNum);

                var eventsContainer = document.createElement('div');
                eventsContainer.className = 'cal-events';

                dayEvents.forEach(function(ev) {
                    var badge = document.createElement('div');
                    badge.className = 'event-badge';
                    badge.style.backgroundColor = ev.color;
                    
                    // ترکیب نام فعالیت و نام مشتری در دو خط
                    var titleSpan = '<span class="event-title">' + window.escapeHtml(ev.title) + '</span>';
                    var customerSpan = '<span class="event-customer">' + window.escapeHtml(ev.customer) + '</span>';
                    badge.innerHTML = titleSpan + customerSpan;
                    
                    badge.title = ev.title + ' ' + ev.customer;
                    // ارجاع نوع رویداد و آی‌دی رویداد برای تشخیص مأموریت
                    badge.onclick = function() { window.openEventModal(ev.opp_id, ev.type, ev.id); };
                    eventsContainer.appendChild(badge);
                });

                cell.appendChild(eventsContainer);
                grid.appendChild(cell);
            }
        } 
        else {
            // ==================== رندر نمای هفتگی ====================
            var wStart = window.wkStart(vY, vM, vD);
            var wEnd = window.jAdd(wStart[0], wStart[1], wStart[2], 6); 
            
            document.getElementById('calMonthYear').innerText = 'هفته: ' + wStart[2] + ' ' + window.monthNames[wStart[1]-1] + ' تا ' + wEnd[2] + ' ' + window.monthNames[wEnd[1]-1];
            
            for(var i=0; i<7; i++) {
                var dDate = window.jAdd(wStart[0], wStart[1], wStart[2], i);
                var cell = document.createElement('div');
                var isToday = (dDate[0] === window.currentJDate[0] && dDate[1] === window.currentJDate[1] && dDate[2] === window.currentJDate[2]);
                
                var dayEvents = window.allEvents.filter(function(ev) {
                    return ev.jY === dDate[0] && ev.jM === dDate[1] && ev.jD === dDate[2];
                });
                
                var hasEvents = dayEvents.length > 0;
                cell.className = 'cal-cell week-mode' + (isToday ? ' today' : '') + (hasEvents ? ' has-events' : '');
                
                var dowIdx = window.jDow(dDate[0], dDate[1], dDate[2]);
                var dowName = window.dowNames[dowIdx];

                var dayNum = document.createElement('div');
                dayNum.className = 'cal-day-num';
                dayNum.innerHTML = '<span class="d-num">' + dDate[2] + '</span><span class="d-name">' + dowName + ' ' + window.monthNames[dDate[1]-1] + '</span>';
                cell.appendChild(dayNum);

                var eventsContainer = document.createElement('div');
                eventsContainer.className = 'cal-events';

                dayEvents.forEach(function(ev) {
                    var badge = document.createElement('div');
                    badge.className = 'event-badge';
                    badge.style.backgroundColor = ev.color;
                    
                    var titleSpan = '<span class="event-title">' + window.escapeHtml(ev.title) + '</span>';
                    var customerSpan = '<span class="event-customer">' + window.escapeHtml(ev.customer) + '</span>';
                    badge.innerHTML = titleSpan + customerSpan;

                    badge.title = ev.title + ' ' + ev.customer;
                    // ارجاع نوع رویداد و آی‌دی رویداد برای تشخیص مأموریت
                    badge.onclick = function() { window.openEventModal(ev.opp_id, ev.type, ev.id); };
                    eventsContainer.appendChild(badge);
                });

                cell.appendChild(eventsContainer);
                grid.appendChild(cell);
            }
        }
    };

    // اجرا هنگام لود صفحه
    document.addEventListener("DOMContentLoaded", function() {
        window.fetchEventsAndRender();
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>