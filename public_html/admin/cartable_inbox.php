<?php
/*
 * فایل: public_html/admin/cartable_inbox.php
 * توضیحات: کارتابل ورودی (مدیریت دکمه مختومه قبل از امضا + رسپانسیو کامل + شکستن صحیح متون طولانی)
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$isAdmin = (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager']));

// رفع هوشمند باگ دیتابیس: تبدیل ستون وضعیت امضا به نوعی که کلمه accepted را بپذیرد
try {
    $pdo->exec("ALTER TABLE letter_signers MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
} catch (Throwable $e) {}

// مینی‌سرویس AJAX برای واکشی گردش نامه
if (isset($_GET['get_timeline'])) {
    $lid = (int)$_GET['get_timeline'];
    try {
        $sqlTimeline = "SELECT r.*, s.first_name as s_fn, s.last_name as s_ln, rec.first_name as r_fn, rec.last_name as r_ln FROM letter_referrals r LEFT JOIN users s ON r.sender_id = s.id LEFT JOIN users rec ON r.receiver_id = rec.id WHERE r.letter_id = ? ORDER BY r.created_at ASC";
        $stmtT = $pdo->prepare($sqlTimeline);
        $stmtT->execute([$lid]);
        $timeline = $stmtT->fetchAll(PDO::FETCH_ASSOC);

        if (empty($timeline)) {
            echo '<div class="text-center py-4 text-muted fw-bold">گردش کار ثبت نشده است.</div>';
        } else {
            echo '<div class="timeline-modal-body">';
            foreach ($timeline as $t) {
                $isCompleted = ($t['is_completed'] == 1);
                $dotClass = $isCompleted ? 'completed' : '';
                $formattedTime = date('H:i:s', strtotime($t['created_at'])) . ' - ' . (function_exists('jdate') ? jdate('Y/m/d', strtotime($t['created_at'])) : date('Y/m/d', strtotime($t['created_at'])));
                
                $priv = $t['private_note'] ?? '';
                $noteHtml = $t['description'] ? '💬 '.nl2br(htmlspecialchars($t['description'])) : '<small class="text-muted">بدون هامش</small>';
                if (!empty($priv) && ($userId == $t['receiver_id'] || $userId == $t['sender_id'] || $isAdmin)) {
                    $noteHtml .= '<div style="margin-top:8px; padding:8px; background:#fff1f2; border:1px dashed #fecaca; color:#be123c; border-radius:6px; font-size:0.8rem;">🔒 <b>پیام خصوصی:</b> '.nl2br(htmlspecialchars($priv)).'</div>';
                }

                echo '
                <div class="tl-item">
                    <div class="tl-dot '.$dotClass.'"></div>
                    <div class="tl-content">
                        <div class="tl-header">
                            <span>از: <b>'.htmlspecialchars($t['s_fn'].' '.$t['s_ln']).'</b></span>
                            <span class="text-primary">به: <b>'.htmlspecialchars($t['r_fn'].' '.$t['r_ln']).'</b></span>
                        </div>
                        <div class="tl-note">'.$noteHtml.'</div>
                        <div class="tl-meta">
                            <span class="fw-bold text-dark">📅 '.$formattedTime.'</span> | 
                            <span>جهت: '.getActionTypeFa($t['action_type']).'</span>
                            '.($isCompleted ? '<div class="text-success fw-bold mt-1">✅ تکمیل شده: '.htmlspecialchars($t['completion_note'] ?? '').'</div>' : '<div class="text-warning mt-1">⏳ در جریان...</div>').'
                        </div>
                    </div>
                </div>';
            }
            echo '</div>';
        }
    } catch (Throwable $e) { echo '<div class="alert alert-danger">خطا در دریافت اطلاعات</div>'; }
    exit;
}

// پردازش عملیات مختومه کردن یا تایید امضا توسط کارمند از داخل کارتابل
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF");
    
    $action = $_POST['action_type'] ?? '';

    if ($action === 'complete_referral') {
        $refIdPost = (int)$_POST['ref_id'];
        $pdo->prepare("UPDATE letter_referrals SET is_completed = 1, completed_at = NOW(), completion_note = 'مختومه توسط گیرنده از کارتابل' WHERE id = ? AND receiver_id = ? AND is_completed = 0")->execute([$refIdPost, $userId]);
        header("Location: cartable_inbox.php?msg=completed");
        exit;
    } elseif ($action === 'accept_for_sign') {
        $refIdPost = (int)$_POST['ref_id'];
        $letterIdPost = (int)$_POST['letter_id'];
        
        try {
            $pdo->beginTransaction();
            
            // اصلاح باگ: اگر نامه به اشتباه در وضعیت in_referral گیر کرده باشد، آن را به حالت تایید شده برای امضا در می‌آورد
            $pdo->prepare("UPDATE letters SET status = 'approved_for_sign' WHERE id = ? AND status = 'in_referral'")->execute([$letterIdPost]);
            
            // بستن ارجاع جاری
            $pdo->prepare("UPDATE letter_referrals SET is_completed = 1, completed_at = NOW(), completion_note = 'تایید جهت درج امضا' WHERE id = ? AND receiver_id = ?")->execute([$refIdPost, $userId]);
            
            // بررسی می‌کنیم که آیا رکورد امضا برای این شخص وجود دارد یا خیر (اگر نداشت می‌سازیم تا ارور ندهد)
            $checkSig = $pdo->prepare("SELECT COUNT(*) FROM letter_signers WHERE letter_id = ? AND user_id = ?");
            $checkSig->execute([$letterIdPost, $userId]);
            if ($checkSig->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO letter_signers (letter_id, user_id, status) VALUES (?, ?, 'accepted')")->execute([$letterIdPost, $userId]);
            } else {
                $pdo->prepare("UPDATE letter_signers SET status = 'accepted' WHERE letter_id = ? AND user_id = ?")->execute([$letterIdPost, $userId]);
            }
            
            $pdo->commit();
            header("Location: cartable_inbox.php?msg=accepted_for_sign");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $err = urlencode($e->getMessage());
            header("Location: cartable_inbox.php?msg=error&err=" . $err);
            exit;
        }
    }
}

$tab = $_GET['tab'] ?? 'all'; 
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20; 
$offset = ($page - 1) * $limit;

$whereClause = "WHERE r.receiver_id = :uid AND r.is_completed = 0";
$params = [':uid' => $userId];

if ($tab !== 'all') {
    $whereClause .= " AND r.action_type = :tab";
    $params[':tab'] = $tab;
}

if (function_exists('db_has_column')) {
    if (db_has_column($pdo, 'letters', 'is_archived')) {
        $whereClause .= " AND (l.is_archived = 0 OR l.is_archived IS NULL)";
    }
}

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals r LEFT JOIN letters l ON r.letter_id = l.id $whereClause");
$stmtCount->execute($params);
$totalItems = $stmtCount->fetchColumn();
$totalPages = ceil($totalItems / $limit);

$sql = "SELECT r.*, l.subject, l.indicator_number, l.type as letter_type, l.status as letter_status, u.first_name, u.last_name 
        FROM letter_referrals r
        JOIN letters l ON r.letter_id = l.id
        JOIN users u ON r.sender_id = u.id
        $whereClause
        ORDER BY r.created_at DESC
        LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('getActionTypeFa')) {
    function getActionTypeFa($type) {
        $arr = [
            'for_action' => 'اقدام', 'for_signature' => 'امضا', 'for_order' => 'دستور',
            'for_followup' => 'پیگیری', 'for_awareness' => 'استحضار', 'for_info' => 'اطلاع'
        ];
        return $arr[$type] ?? 'اقدام';
    }
}

$pageTitle = '📥 کارتابل ورودی';
$basePath = '../';

$extraCss = '
<style>
    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    
    .table-container { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px;}
    
    /* تنظیمات شکستن متن (Wrap) به صورت کاملاً استاندارد برای جلوگیری از تداخل */
    .l-table { width: 100%; border-collapse: collapse; background: #fff; min-width: 950px; table-layout: fixed; }
    .l-table th, .l-table td { 
        padding: 12px 10px; 
        text-align: right; 
        border-bottom: 1px solid #f1f5f9; 
        vertical-align: middle; 
        word-wrap: break-word !important; 
        overflow-wrap: break-word !important;
        white-space: normal !important;
        line-height: 1.8;
    }
    .l-table th { background: #f8fafc; font-weight: 800; color: #475569; }
    .l-table tr:hover { background: #f8fafc; }
    
    .col-sender { width: 15%; }
    .col-ind { width: 12%; }
    .col-subj { width: 28%; }
    .col-type { width: 10%; }
    .col-date { width: 13%; }
    .col-status { width: 10%; }
    .col-actions { width: 12%; text-align: center; }

    .status-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; display: inline-block; white-space: nowrap;}
    .st-new { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    .st-viewed { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .st-action { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    
    .profile-tabs { display: flex; gap: 5px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; flex-wrap: wrap; }
    .tab-btn { padding: 10px 15px; font-size: 0.95rem; font-weight: 700; color: #64748b; text-decoration: none; border-bottom: 3px solid transparent; transition: 0.2s;}
    .tab-btn:hover { background: #f8fafc; color: #2563eb; border-radius: 8px 8px 0 0; }
    .tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; }

    .btn-group-row { display: flex; align-items: center; justify-content: center; gap: 5px; flex-wrap: wrap; }
    .btn-group-row form { margin: 0; display: flex; align-items: center; }
    .action-btn { display: inline-flex; align-items: center; justify-content: center; padding: 6px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; transition: 0.2s; border: none; cursor: pointer; text-decoration: none; white-space: nowrap; text-align: center;}
    
    .btn-view { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
    .btn-timeline { background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; }
    .btn-refer { background: #faf5ff; color: #9333ea; border: 1px solid #e9d5ff; }
    .action-btn:hover { opacity: 0.85; transform: translateY(-1px); }

    .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(4px);}
    .modal-content { background:#fff; width:90%; max-width:750px; border-radius:16px; padding:25px; max-height:85vh; overflow-y:auto; position:relative; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
    .timeline-modal-body { position: relative; padding-right: 25px; border-right: 2px solid #e2e8f0; margin-top: 15px; }
    .tl-item { position: relative; margin-bottom: 25px; padding-right: 15px; }
    .tl-dot { position: absolute; right: -33px; top: 0; width: 16px; height: 14px; border-radius: 50%; background: #3b82f6; border: 3px solid #fff; box-shadow: 0 0 0 1px #3b82f6; }
    .tl-dot.completed { background: #10b981; box-shadow: 0 0 0 1px #10b981; }
    .tl-header { display: flex; justify-content: space-between; flex-wrap: wrap; font-size: 0.9rem; margin-bottom: 5px; gap: 5px; }
    .tl-note { background: #f8fafc; padding: 10px 15px; border-radius: 8px; font-size: 0.85rem; border: 1px solid #edf2f7; color: #334155; line-height:1.7; }
    .tl-meta { font-size: 0.8rem; color: #64748b; margin-top: 8px; display: flex; gap: 10px; flex-wrap: wrap; }
    
    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 5px; margin-top: 20px; }
    .page-link { padding: 8px 16px; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #475569; background: #fff; font-weight: bold; transition: 0.2s; }
    .page-link:hover { background: #f1f5f9; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }

    @media (max-width: 900px) {
        .table-container { border: 1px solid #cbd5e1; }
    }

    @media (max-width: 768px) {
        .content-wrapper { padding: 10px !important; overflow-x: hidden; }
        .card { padding: 15px !important; border-radius: 12px !important; border: none !important; box-shadow: none !important; }
        
        .profile-tabs { justify-content: center; }
        .tab-btn { flex: 1; text-align: center; min-width: 30%; font-size: 0.85rem; padding: 8px 5px; }

        .table-container { overflow: visible; border: none; background: transparent; padding: 0;}
        .l-table { display: block; table-layout: auto; min-width: 100%; border: none;}
        .l-table tbody, .l-table tr, .l-table td { display: block; width: 100%; box-sizing: border-box;}
        .l-table thead { display: none; }
        .l-table tr { margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
        
        .l-table td { 
            display: flex; 
            flex-direction: column; 
            align-items: flex-start; 
            text-align: right; 
            gap: 6px; 
            padding: 12px 0; 
            border-bottom: 1px dashed #e2e8f0; 
            word-break: break-word !important; 
            white-space: normal !important; 
        }
        .l-table td:last-child { border-bottom: none; padding-top: 15px; align-items: stretch; }
        .l-table td::before { content: attr(data-label); font-weight: bold; color: #64748b; font-size: 0.85rem; background: #f8fafc; padding: 2px 8px; border-radius: 4px; }
        
        .btn-group-row { flex-direction: row; flex-wrap: wrap; justify-content: space-between; gap: 10px; width: 100%; }
        .btn-group-row > a.action-btn, 
        .btn-group-row > button.action-btn, 
        .btn-group-row > form { flex: 1; min-width: calc(45% - 5px); display: flex; margin: 0; }
        .btn-group-row > form > button.action-btn { width: 100%; flex: 1; display: flex; justify-content: center; align-items: center; text-align: center; }
        .action-btn { justify-content: center; align-items: center; text-align: center; padding: 12px 5px; font-size: 0.9rem; margin: 0; white-space: nowrap; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header d-flex justify-content-between align-items-center mb-4">
            <div><span class="page-title fw-bold">📥 کارتابل ورودی</span></div>
            
            <?php if(isset($_GET['msg']) && $_GET['msg']=='completed'): ?>
                <div class="alert alert-success py-2 px-4 m-0 fw-bold" style="font-size:0.9rem; border-radius:8px;">✅ اقدام روی این نامه با موفقیت مختومه شد.</div>
            <?php elseif(isset($_GET['msg']) && $_GET['msg']=='accepted_for_sign'): ?>
                <div class="alert alert-success py-2 px-4 m-0 fw-bold" style="font-size:0.9rem; border-radius:8px;">✅ نامه جهت درج امضا به میز کار شما (تایید شده‌ها) منتقل گردید.</div>
            <?php elseif(isset($_GET['msg']) && $_GET['msg']=='error'): ?>
                <div class="alert alert-danger py-2 px-4 m-0 fw-bold" style="font-size:0.9rem; border-radius:8px;">❌ خطا: <?php echo htmlspecialchars($_GET['err'] ?? 'مشکلی رخ داد'); ?></div>
            <?php endif; ?>
            
        </div>

        <div class="profile-tabs mb-4">
            <a href="?tab=all" class="tab-btn <?php echo $tab=='all'?'active':''; ?>">📁 همه موارد</a>
            <a href="?tab=for_action" class="tab-btn <?php echo $tab=='for_action'?'active':''; ?>">🛠️ اقدام</a>
            <a href="?tab=for_signature" class="tab-btn <?php echo $tab=='for_signature'?'active':''; ?>">✍️ امضا</a>
            <a href="?tab=for_order" class="tab-btn <?php echo $tab=='for_order'?'active':''; ?>">📜 دستور</a>
            <a href="?tab=for_followup" class="tab-btn <?php echo $tab=='for_followup'?'active':''; ?>">⏳ پیگیری</a>
            <a href="?tab=for_awareness" class="tab-btn <?php echo $tab=='for_awareness'?'active':''; ?>">🔔 استحضار</a>
            <a href="?tab=for_info" class="tab-btn <?php echo $tab=='for_info'?'active':''; ?>">ℹ️ اطلاع</a>
        </div>

        <div class="card" style="padding: 20px; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
            <div class="table-container">
                <table class="l-table">
                    <thead>
                        <tr>
                            <th class="col-sender">فرستنده ارجاع</th>
                            <th class="col-ind">شماره اندیکاتور</th>
                            <th class="col-subj text-right">موضوع نامه</th>
                            <th class="col-type">جهت ارجاع</th>
                            <th class="col-date">زمان دریافت</th>
                            <th class="col-status">وضعیت</th>
                            <th class="col-actions">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($items as $item): ?>
                        <tr>
                            <td data-label="فرستنده:"><?php echo htmlspecialchars($item['first_name'].' '.$item['last_name']); ?></td>
                            <td data-label="شماره:"><strong dir="ltr"><?php echo $item['indicator_number'] ?: '---'; ?></strong></td>
                            <td data-label="موضوع:" class="fw-bold" style="color:#1d4ed8;"><?php echo htmlspecialchars($item['subject']); ?></td>
                            <td data-label="جهت:"><span class="status-badge st-action"><?php echo getActionTypeFa($item['action_type']); ?></span></td>
                            <td data-label="دریافت:"><span dir="ltr" class="fw-bold" style="font-size:0.9rem; color:#0f172a; white-space:nowrap;"><?php echo date('H:i', strtotime($item['created_at'])) . ' - ' . (function_exists('jdate') ? jdate('Y/m/d', strtotime($item['created_at'])) : date('Y/m/d', strtotime($item['created_at']))); ?></span></td>
                            <td data-label="وضعیت:">
                                <span class="status-badge <?php echo $item['is_read'] ? 'st-viewed' : 'st-new'; ?>">
                                    <?php echo $item['is_read'] ? 'مشاهده شده' : 'جدید'; ?>
                                </span>
                            </td>
                            <td data-label="عملیات:">
                                <div class="btn-group-row">
                                    <a href="letter_view.php?id=<?php echo $item['letter_id']; ?>&ref_id=<?php echo $item['id']; ?>" class="action-btn btn-view" title="مشاهده و اقدام" onclick="markAsRead(this)">👁️ مشاهده</a>
                                    
                                    <?php 
                                    $canRefer = true;
                                    if (in_array($item['letter_type'], ['internal', 'incoming']) && empty($item['indicator_number'])) {
                                        $canRefer = false;
                                    }
                                    if ($canRefer): ?>
                                        <a href="letter_refer.php?id=<?php echo $item['letter_id']; ?>&ref_id=<?php echo $item['id']; ?>" class="action-btn btn-refer" title="ارجاع به همکار دیگر">📤 ارجاع</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($item['action_type'] === 'for_signature'): ?>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('آیا نامه را جهت امضا تایید می‌کنید؟ (نامه به میز کار امضا منتقل خواهد شد)');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action_type" value="accept_for_sign">
                                            <input type="hidden" name="ref_id" value="<?php echo $item['id']; ?>">
                                            <input type="hidden" name="letter_id" value="<?php echo $item['letter_id']; ?>">
                                            <button type="submit" class="action-btn" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0;">✔️ تایید برای امضا</button>
                                        </form>
                                    <?php else: ?>
                                        <?php 
                                        $showCompleteBtn = true;
                                        if ($item['letter_type'] === 'outgoing' && empty($item['indicator_number'])) {
                                            $showCompleteBtn = false;
                                        }
                                        if ($showCompleteBtn): 
                                        ?>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('آیا از مختومه کردن این نامه در کارتابل خود اطمینان دارید؟');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action_type" value="complete_referral">
                                            <input type="hidden" name="ref_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" class="action-btn" style="background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0;">✅ مختومه</button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <button type="button" onclick="showTimeline(<?php echo $item['letter_id']; ?>)" class="action-btn btn-timeline" title="تاریخچه گردش">🔄 گردش</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($items)) echo '<tr><td colspan="7" class="text-center py-5 fw-bold" style="color:#94a3b8;">هیچ نامه‌ای در این کارتابل یافت نشد.</td></tr>'; ?>
                    </tbody>
                </table>
            </div>

            <?php if($totalPages > 1): ?>
            <div class="pagination mt-4">
                <?php for($i=1; $i<=$totalPages; $i++): ?>
                    <a href="?tab=<?php echo htmlspecialchars($tab); ?>&page=<?php echo $i; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<div id="timelineModal" class="modal-overlay">
    <div class="modal-content">
        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
            <h5 class="fw-bold m-0" style="color:#0f172a;">🔄 تاریخچه گردش نامه</h5>
            <span style="cursor:pointer; font-size:1.8rem; color:#ef4444; line-height:1;" onclick="closeModal()">&times;</span>
        </div>
        <div id="timelineResult">
            <div class="text-center py-5 fw-bold" style="color:#64748b;">⏳ در حال بارگذاری اطلاعات...</div>
        </div>
        <div class="text-start mt-4 pt-3 border-top">
            <button class="btn btn-secondary px-4 fw-bold" style="border-radius:8px;" onclick="closeModal()">بستن</button>
        </div>
    </div>
</div>

<script>
// تغییر سریع وضعیت مشاهده به خوانده شده در رابط کاربری
function markAsRead(btn) {
    let row = btn.closest('tr');
    if (row) {
        let badge = row.querySelector('.st-new');
        if (badge) {
            badge.classList.remove('st-new');
            badge.classList.add('st-viewed');
            badge.innerText = 'مشاهده شده';
        }
    }
}

function showTimeline(letterId) {
    document.getElementById('timelineModal').style.display = 'flex';
    document.getElementById('timelineResult').innerHTML = '<div class="text-center py-5 fw-bold" style="color:#64748b;">⏳ در حال بارگذاری اطلاعات...</div>';
    fetch('cartable_inbox.php?get_timeline=' + letterId)
        .then(response => response.text())
        .then(html => { document.getElementById('timelineResult').innerHTML = html; })
        .catch(err => { document.getElementById('timelineResult').innerHTML = '<div class="alert alert-danger fw-bold">❌ خطا در برقراری ارتباط با سرور.</div>'; });
}
function closeModal() { document.getElementById('timelineModal').style.display = 'none'; }
window.onclick = function(event) { if (event.target == document.getElementById('timelineModal')) { closeModal(); } }

setTimeout(() => {
    const alertBox = document.querySelector('.alert');
    if (alertBox) alertBox.style.display = 'none';
}, 6000);

// این قطعه کد برای اطمینان از آپدیت کامل کش مرورگر است
window.addEventListener('pageshow', function(event) {
    if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
        window.location.reload();
    }
});
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>