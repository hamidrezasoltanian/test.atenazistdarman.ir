<?php
/*
 * فایل: public_html/dashboard.php
 * توضیحات: داشبورد اصلی اتوماسیون (با بروزرسانی لحظه‌ای، آمار ماموریت، مرخصی و تردد)
 */

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// مسیردهی فایل‌های سیستمی
$db_path = __DIR__ . '/../includes/db.php';
$func_path = __DIR__ . '/../includes/functions.php';

if (!file_exists($db_path)) $db_path = __DIR__ . '/includes/db.php';
if (!file_exists($func_path)) $func_path = __DIR__ . '/includes/functions.php';

require_once $db_path;
require_once $func_path;

// بررسی لاگین
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ثبت آنلاین بودن کاربر فعلی
try {
    $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([$_SESSION['user_id']]);
} catch(Exception $e) {}

// =================================================================================
// مینی وب‌سرویس AJAX برای بروزرسانی زنده پرسنل آنلاین
// =================================================================================
if (isset($_GET['ajax_online_users'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmtOnline = $pdo->query("SELECT id, first_name, last_name, profile_image FROM users WHERE status = 'active' AND last_seen >= NOW() - INTERVAL 3 MINUTE ORDER BY last_seen DESC");
        $onlineUsers = $stmtOnline->fetchAll(PDO::FETCH_ASSOC);
        
        $count = count($onlineUsers);
        $maxDisplay = 8;
        $displayUsers = array_slice($onlineUsers, 0, $maxDisplay);
        $remaining = $count - $maxDisplay;
        
        $html = '';
        if ($count > 0) {
            $html .= '<div class="avatar-group">';
            foreach ($displayUsers as $index => $u) {
                $uImg = (!empty($u['profile_image']) && $u['profile_image']!='default.png' && file_exists(__DIR__ . '/uploads/profiles/' . $u['profile_image'])) 
                    ? 'uploads/profiles/' . $u['profile_image'] 
                    : 'assets/images/profile-icon.png';
                $zIndex = 10 - $index;
                $name = htmlspecialchars($u['first_name'] . ' ' . $u['last_name']);
                $html .= '<img src="' . $uImg . '" title="' . $name . '" style="z-index: ' . $zIndex . ';">';
            }
            if ($remaining > 0) {
                $html .= '<div class="avatar-more" style="z-index: 0;">+' . $remaining . '</div>';
            }
            $html .= '</div>';
        } else {
            $html .= '<div style="text-align:center; color:#9ca3af; font-size:0.85rem; padding: 20px 10px;">در حال حاضر کاربری آنلاین نیست.</div>';
        }
        
        echo json_encode(['status' => 'success', 'count' => $count, 'html' => $html]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error']);
    }
    exit;
}
// =================================================================================

$tpl_path = __DIR__ . '/../templates/';
if (!file_exists($tpl_path . 'header.php')) $tpl_path = __DIR__ . '/templates/';

$pageTitle = 'داشبورد اتوماسیون | آتنا زیست درمان';
$basePath = ''; 

// --- دریافت آمار یکپارچه برای داشبورد ---
$countUsers = 0;
try { $countUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn(); } catch (Exception $e) { }

$countAnn = 0;
try { $countAnn = $pdo->query("SELECT COUNT(*) FROM announcements WHERE status = 'published'")->fetchColumn(); } catch (Exception $e) { }

$unreadAnnCount = 0;
try {
    $sqlUnread = "SELECT COUNT(a.id) FROM announcements a LEFT JOIN announcement_reads ar ON a.id = ar.announcement_id AND ar.user_id = :uid WHERE a.status = 'published' AND ar.announcement_id IS NULL";
    $stmtUnread = $pdo->prepare($sqlUnread);
    $stmtUnread->execute([':uid' => $_SESSION['user_id']]);
    $unreadAnnCount = $stmtUnread->fetchColumn();
} catch (Exception $e) {}

$latestNews = [];
try { $latestNews = $pdo->query("SELECT * FROM announcements WHERE status = 'published' ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { }

$userRole = $_SESSION['role'] ?? '';
$isManagerOrAdmin = ($userRole === 'admin' || $userRole === 'management' || strpos($userRole, 'manager') !== false);
$baseWhere = $isManagerOrAdmin ? "1=1" : "user_id = {$_SESSION['user_id']}";

$countPendingMissions = 0;
$countPendingLeaves = 0;
$countPendingAttendances = 0;

try {
    // 1. آمار ماموریت های در انتظار تایید
    $checkMission = $pdo->query("SHOW TABLES LIKE 'mission_requests'");
    if ($checkMission->rowCount() > 0) {
        $countPendingMissions = $pdo->query("SELECT COUNT(*) FROM mission_requests WHERE $baseWhere AND status IN ('pending', 'pending_admin')")->fetchColumn();
    }

    // 2. آمار مرخصی های در انتظار تایید
    $checkLeave = $pdo->query("SHOW TABLES LIKE 'leave_requests'");
    if ($checkLeave->rowCount() > 0) {
        $countPendingLeaves = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE $baseWhere AND status IN ('pending', 'pending_manager', 'pending_admin')")->fetchColumn();
    }

    // 3. آمار تردد های در انتظار تایید
    $checkAtt = $pdo->query("SHOW TABLES LIKE 'attendance_requests'");
    if ($checkAtt->rowCount() > 0) {
        $countPendingAttendances = $pdo->query("SELECT COUNT(*) FROM attendance_requests WHERE $baseWhere AND status = 'pending'")->fetchColumn();
    }
} catch (Exception $e) { }

// --- دریافت آمار اتوماسیون نامه‌ها ---
$stats = [
    'inbox' => 0,
    'pending' => 0,
    'drafts' => 0,
    'total_archived' => 0,
    'need_sign' => 0
];
try {
    $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE receiver_id = ? AND is_completed = 0");
    $stmt1->execute([$_SESSION['user_id']]);
    $stats['inbox'] = (int)$stmt1->fetchColumn();

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM letters WHERE status = 'pending_action' AND created_by = ? AND is_archived = 0");
    $stmt2->execute([$_SESSION['user_id']]);
    $stats['pending'] = (int)$stmt2->fetchColumn();

    $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM letters WHERE status = 'draft' AND created_by = ? AND is_deleted = 0");
    $stmt3->execute([$_SESSION['user_id']]);
    $stats['drafts'] = (int)$stmt3->fetchColumn();

    if ($isManagerOrAdmin) {
        $stats['total_archived'] = (int)$pdo->query("SELECT COUNT(*) FROM letters WHERE is_archived = 1")->fetchColumn();
        $stats['need_sign'] = (int)$pdo->query("SELECT COUNT(*) FROM letters WHERE status = 'approved_for_sign' AND is_archived = 0")->fetchColumn();
    }
} catch (Exception $e) { }
// ----------------------------------------

// لینک‌های هوشمند بر اساس نقش کاربر
$leaveLink = $isManagerOrAdmin ? 'admin/leave_manage.php?status=pending' : 'admin/leave_requests.php';
$attendanceLink = $isManagerOrAdmin ? 'admin/attendance_manage.php?status=pending' : 'admin/attendance_requests.php';

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'];
$roleName = $_SESSION['role'] === 'admin' ? 'مدیر سیستم' : 'کاربر عادی';
$todayJalali = jdate('l d F Y');

$extraCss = '<style>
    .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: white; border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 15px; border: 1px solid #e5e7eb; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: 0.2s; }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
    .stat-icon { width: 50px; height: 50px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
    .stat-info h3 { font-size: 1.5rem; font-weight: bold; margin: 0; color: #1f2937; }
    .stat-info span { color: #6b7280; font-size: 0.85rem; }
    
    .card-blue .stat-icon { background: #eff6ff; color: #2563eb; } .card-blue { border-bottom: 3px solid #2563eb; }
    .card-orange .stat-icon { background: #fff7ed; color: #ea580c; } .card-orange { border-bottom: 3px solid #ea580c; }
    .card-purple .stat-icon { background: #faf5ff; color: #9333ea; } .card-purple { border-bottom: 3px solid #9333ea; }
    .card-green .stat-icon { background: #f0fdf4; color: #16a34a; } .card-green { border-bottom: 3px solid #16a34a; }

    .dashboard-sections { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; }
    .section-card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; height: 100%; }
    .news-item { display: flex; gap: 15px; padding: 15px 0; border-bottom: 1px dashed #e5e7eb; }
    .news-item:last-child { border-bottom: none; }
    .news-date { background: #f8fafc; border-radius: 8px; padding: 5px 10px; text-align: center; min-width: 70px; border: 1px solid #e2e8f0; }
    .news-day { font-weight: bold; font-size: 1.1rem; color: #334155; line-height: 1; }
    .news-month { font-size: 0.8rem; color: #64748b; }
    .news-content h4 { font-size: 0.95rem; margin: 0 0 5px 0; color: #1e293b; }
    .news-content p { font-size: 0.8rem; color: #6b7280; margin: 0; }

    .quick-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .quick-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 15px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #475569; transition: 0.2s; font-size: 0.9rem; }
    .quick-btn:hover { background: #fff; border-color: #2563eb; color: #2563eb; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.1); transform: translateY(-2px); }
    .quick-btn svg { width: 24px; height: 24px; margin-bottom: 5px; }

    .online-indicator {
        width: 10px; height: 10px; background: #10b981; border-radius: 50%; display: inline-block; margin-left: 5px;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); animation: pulseOnline 2s infinite;
    }
    @keyframes pulseOnline {
        0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
        70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .avatar-group { display: flex; flex-wrap: wrap; padding-right: 10px; padding-top: 15px; align-items: center;}
    .avatar-group img, .avatar-group .avatar-more {
        width: 48px; height: 48px; border-radius: 50%; border: 3px solid #fff;
        object-fit: cover; margin-right: -15px; transition: 0.2s; position: relative;
        background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.08); cursor: pointer;
    }
    .avatar-group img:hover { transform: translateY(-5px); z-index: 20 !important; border-color: #bfdbfe; }
    .avatar-group .avatar-more {
        background: #f1f5f9; color: #3b82f6; font-weight: 800; font-size: 0.85rem;
        display: flex; align-items: center; justify-content: center; z-index: 1; border-color: #fff;
    }

    @media (max-width: 992px) { .dashboard-sections { grid-template-columns: 1fr; } }
</style>';

include $tpl_path . 'header.php';
include $tpl_path . 'sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header" style="margin-bottom: 30px;">
            <div>
                <h2 style="font-size: 1.5rem; font-weight: 800; color: #1f2937; margin-bottom: 5px;">خوش آمدید، <?php echo htmlspecialchars($fullname); ?> 👋</h2>
                <div style="color: #6b7280; font-size: 0.95rem;">
                    <span><i class="far fa-calendar-alt"></i> امروز: <?php echo $todayJalali; ?></span> | 
                    <span><i class="far fa-user-circle"></i> نقش: <?php echo $roleName; ?></span>
                </div>
            </div>
            <div style="font-size: 2rem; font-weight: 300; color: #2563eb; font-family: sans-serif;" id="liveClock">
                <?php echo date('H:i'); ?>
            </div>
        </div>

        <div class="dashboard-grid">
            
            <a href="admin/cartable_inbox.php" class="stat-card card-blue" style="text-decoration:none;">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                </div>
                <div class="stat-info"><h3><?php echo number_format($stats['inbox']); ?></h3><span>کارتابل ورودی</span></div>
            </a>

            <a href="admin/letters_pending.php" class="stat-card card-orange" style="text-decoration:none;">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div class="stat-info"><h3><?php echo number_format($stats['pending']); ?></h3><span>در دست اقدام</span></div>
            </a>

            <a href="admin/letters_drafts.php" class="stat-card card-purple" style="text-decoration:none;">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                </div>
                <div class="stat-info"><h3><?php echo number_format($stats['drafts']); ?></h3><span>پیش‌نویس‌ها</span></div>
            </a>

            <?php if($isManagerOrAdmin): ?>
            <a href="admin/letters_approved.php" class="stat-card card-green" style="text-decoration:none;">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </div>
                <div class="stat-info"><h3><?php echo number_format($stats['need_sign']); ?></h3><span>در صف امضا</span></div>
            </a>
            <?php else: ?>
            <a href="admin/letters_archive.php" class="stat-card card-green" style="text-decoration:none;">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
                </div>
                <div class="stat-info"><h3><i class="fas fa-check"></i></h3><span>آرشیو مکاتبات</span></div>
            </a>
            <?php endif; ?>

            <a href="admin/missions.php?filter=pending" class="stat-card card-orange" style="text-decoration:none;">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                </div>
                <div class="stat-info"><h3><?php echo number_format($countPendingMissions); ?></h3><span>ماموریت های در انتظار تایید</span></div>
            </a>
            
            <a href="<?php echo $leaveLink; ?>" class="stat-card card-purple" style="text-decoration:none;">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                </div>
                <div class="stat-info"><h3><?php echo number_format($countPendingLeaves); ?></h3><span>مرخصی های در انتظار تایید</span></div>
            </a>
            
            <a href="<?php echo $attendanceLink; ?>" class="stat-card card-green" style="text-decoration:none;">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div class="stat-info"><h3><?php echo number_format($countPendingAttendances); ?></h3><span>تردد های در انتظار تایید</span></div>
            </a>

            <a href="admin/users.php" class="stat-card card-blue" style="text-decoration:none;">
                <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                <div class="stat-info"><h3><?php echo number_format($countUsers); ?></h3><span>کاربران فعال</span></div>
            </a>
            <a href="admin/announcements.php" class="stat-card card-blue" style="text-decoration:none;">
                <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg></div>
                <div class="stat-info"><h3><?php echo number_format($countAnn); ?></h3><span>اعلانات</span></div>
            </a>
            <a href="admin/announcements.php" class="stat-card card-orange" style="text-decoration:none;">
                <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 17H2a3 3 0 0 0 3-3V9a7 7 0 0 1 14 0v5a3 3 0 0 0 3 3zm-8.27 4a2 2 0 0 1-3.46 0"></path></svg></div>
                <div class="stat-info"><h3><?php echo number_format($unreadAnnCount); ?></h3><span>پیام جدید</span></div>
            </a>
        </div>

        <div class="dashboard-sections">
            <div class="section-card">
                <div class="section-header">
                    <span style="font-weight:bold">📢 آخرین اطلاعیه‌ها</span>
                    <a href="admin/announcements.php" class="btn btn-sm btn-outline-secondary">مشاهده همه</a>
                </div>
                <?php if(empty($latestNews)): ?>
                    <div class="text-center text-muted py-4">هیچ اطلاعیه‌ای وجود ندارد.</div>
                <?php else: ?>
                    <?php foreach($latestNews as $news): ?>
                    <div class="news-item">
                        <div class="news-date">
                            <div class="news-day"><?php echo jdate('d', strtotime($news['created_at'])); ?></div>
                            <div class="news-month"><?php echo jdate('F', strtotime($news['created_at'])); ?></div>
                        </div>
                        <div class="news-content">
                            <h4><?php echo htmlspecialchars($news['title']); ?></h4>
                            <p><?php echo mb_substr(strip_tags($news['content']), 0, 100) . '...'; ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div style="display:flex; flex-direction:column; gap:25px;">
                
                <div class="section-card" style="height: auto;">
                    <div class="section-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:bold; display:flex; align-items:center;">
                            <span class="online-indicator"></span> پرسنل آنلاین
                        </span>
                        <span id="onlineCountBadge" style="background:#dcfce7; color:#166534; padding:3px 10px; border-radius:20px; font-weight:bold; font-size:0.8rem; border:1px solid #bbf7d0;">
                            در حال لود...
                        </span>
                    </div>
                    
                    <div id="onlineUsersContainer">
                        <div style="text-align:center; padding:20px; color:#999;">در حال دریافت اطلاعات...</div>
                    </div>
                </div>

                <div class="section-card" style="height: auto;">
                    <div class="section-header"><span style="font-weight:bold">⚡ دسترسی سریع</span></div>
                    <div class="quick-actions">
                        <a href="admin/mission_create.php" class="quick-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                            ثبت ماموریت
                        </a>
                        <a href="admin/chat.php" class="quick-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                            پیام‌رسان
                        </a>
                        <a href="admin/notes.php" class="quick-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            یادداشت
                        </a>
                        <a href="admin/profile.php" class="quick-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            پروفایل
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری آتنا زیست درمان</footer>
</main>

<script>
    // ساعت زنده
    setInterval(() => {
        const now = new Date();
        const time = now.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
        const el = document.getElementById('liveClock');
        if(el) el.innerText = time;
    }, 1000);

    // بروزرسانی زنده (Real-time) کاربران آنلاین با AJAX
    function fetchOnlineUsers() {
        fetch('dashboard.php?ajax_online_users=1')
        .then(response => response.json())
        .then(res => {
            if(res.status === 'success') {
                const badge = document.getElementById('onlineCountBadge');
                const container = document.getElementById('onlineUsersContainer');
                if (badge) badge.innerText = res.count + ' نفر';
                if (container) container.innerHTML = res.html;
            }
        })
        .catch(err => console.error("Error fetching online users: ", err));
    }

    // فراخوانی اولیه
    fetchOnlineUsers();
    
    // تکرار هر 10 ثانیه برای زنده ماندن اطلاعات بدون رفرش صفحه
    setInterval(fetchOnlineUsers, 10000);
</script>

<?php include $tpl_path . 'footer.php'; ?>