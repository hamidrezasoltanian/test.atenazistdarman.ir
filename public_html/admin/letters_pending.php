<?php
/*
 * فایل: public_html/admin/letters_pending.php
 * توضیحات: کارتابل من (کاملاً رسپانسیو، دکمه‌های وسط‌چین در موبایل، طراحی کارت‌ویو)
 */

ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$isAdmin = (in_array($_SESSION['role'] ?? '', ['admin', 'manager']));

$activeFiscalYear = getActiveFiscalYear();
$fiscalYearId = $activeFiscalYear ? $activeFiscalYear['id'] : null;

function getActionTypeFa($type) {
    $arr = [
        'for_action' => 'اقدام', 'for_signature' => 'امضا', 'for_order' => 'دستور',
        'for_followup' => 'پیگیری', 'for_awareness' => 'استحضار', 'for_info' => 'اطلاع'
    ];
    return $arr[$type] ?? 'اقدام';
}

if (isset($_GET['get_timeline'])) {
    $lid = (int)$_GET['get_timeline'];
    $sqlTimeline = "SELECT r.*, s.first_name as s_fn, s.last_name as s_ln, rec.first_name as r_fn, rec.last_name as r_ln 
                    FROM letter_referrals r 
                    LEFT JOIN users s ON r.sender_id = s.id 
                    LEFT JOIN users rec ON r.receiver_id = rec.id 
                    WHERE r.letter_id = ? 
                    ORDER BY r.created_at ASC";
    $stmtT = $pdo->prepare($sqlTimeline);
    $stmtT->execute([$lid]);
    $timeline = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    if (empty($timeline)) {
        echo '<div class="text-center py-4 fw-bold" style="color:#94a3b8;">گردش کار برای این نامه ثبت نشده است.</div>';
    } else {
        echo '<div class="timeline-modal-body">';
        foreach ($timeline as $t) {
            $isCompleted = ($t['is_completed'] == 1);
            $dotClass = $isCompleted ? 'completed' : '';
            $formattedTime = date('H:i:s', strtotime($t['created_at'])) . ' - ' . (function_exists('jdate') ? jdate('Y/m/d', strtotime($t['created_at'])) : date('Y/m/d', strtotime($t['created_at'])));
            
            $priv = $t['private_note'] ?? '';
            $noteHtml = $t['description'] ? '💬 '.nl2br(htmlspecialchars($t['description'])) : '<span style="color:#cbd5e1;">بدون هامش</span>';
            if (!empty($priv) && ($userId == $t['receiver_id'] || $userId == $t['sender_id'] || $isAdmin)) {
                $noteHtml .= '<div style="margin-top:8px; padding:8px; background:#fff1f2; border:1px dashed #fecaca; color:#be123c; border-radius:6px; font-size:0.8rem;">🔒 <b>پیام خصوصی:</b> '.nl2br(htmlspecialchars($priv)).'</div>';
            }

            echo '
            <div class="tl-item">
                <div class="tl-dot '.$dotClass.'"></div>
                <div class="tl-content">
                    <div class="tl-header">
                        <span>از: <b>'.htmlspecialchars($t['s_fn'].' '.$t['s_ln']).'</b></span>
                        <span style="color:#3b82f6;">به: <b>'.htmlspecialchars($t['r_fn'].' '.$t['r_ln']).'</b></span>
                    </div>
                    <div class="tl-note">'.$noteHtml.'</div>
                    <div class="tl-meta">
                        <span class="fw-bold text-dark">📅 '.$formattedTime.'</span> | 
                        <span>جهت: '.getActionTypeFa($t['action_type']).'</span>
                        '.($isCompleted ? '<div style="color:#10b981; font-weight:bold; margin-top:5px;">✅ تکمیل شده: '.htmlspecialchars($t['completion_note'] ?? '').'</div>' : '<div style="color:#f59e0b; margin-top:5px;">⏳ در جریان...</div>').'
                    </div>
                </div>
            </div>';
        }
        echo '</div>';
    }
    exit;
}

$msg = '';
$msgType = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'referred') { $msg = "📤 ارجاع نامه با موفقیت انجام شد."; $msgType = "success"; }
    elseif ($_GET['msg'] === 'letter_created') { $msg = "✅ نامه با موفقیت در کارتابل ذخیره شد."; $msgType = "success"; }
    elseif ($_GET['msg'] === 'approved') { $msg = "✅ نامه با موفقیت شماره‌گذاری و به گیرندگان ارجاع شد."; $msgType = "success"; }
    elseif ($_GET['msg'] === 'approved_for_sign') { $msg = "✅ نامه صادره جهت تایید و امضا به کارتابل افراد منتخب ارجاع شد."; $msgType = "success"; }
    elseif ($_GET['msg'] === 'accepted_for_sign') { $msg = "✅ نامه تایید شد و به «میز کار امضا» (لیست تایید شده‌ها) منتقل گردید."; $msgType = "success"; }
    elseif ($_GET['msg'] === 'archived') { $msg = "🗄️ نامه با موفقیت بایگانی شد."; $msgType = "success"; }
    elseif ($_GET['msg'] === 'deleted') { $msg = "🗑️ پیش‌نویس نامه با موفقیت به زباله‌دان منتقل شد."; $msgType = "success"; }
    elseif ($_GET['msg'] === 'err_archive_ref') { $msg = "⛔ خطا: این نامه دارای ارجاع اقدام نشده (باز) است. ابتدا باید ارجاعات آن مختومه شود."; $msgType = "error"; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");

    $action = $_POST['action_type'] ?? '';
    $letterId = (int)$_POST['letter_id'];

    try {
        $pdo->beginTransaction();

        if ($action === 'approve_internal') {
            $stmtL = $pdo->prepare("SELECT type, department_prefix FROM letters WHERE id = ?");
            $stmtL->execute([$letterId]);
            $lData = $stmtL->fetch(PDO::FETCH_ASSOC);
            
            if (!$fiscalYearId) throw new Exception("سال مالی فعال یافت نشد.");

            $basePrefix = $lData['department_prefix'] ?: 'الف';
            $typeLetter = ($lData['type'] === 'internal') ? 'د' : 'و';
            $fullPrefix = $basePrefix . '/' . $typeLetter;

            $stmtInd = $pdo->prepare("SELECT id, last_sequence FROM letter_indicators WHERE fiscal_year_id = ? AND department_prefix = ? FOR UPDATE");
            $stmtInd->execute([$fiscalYearId, $basePrefix]);
            $indRow = $stmtInd->fetch(PDO::FETCH_ASSOC);

            if ($indRow) {
                $nextSeq = (int)$indRow['last_sequence'] + 1;
                $pdo->prepare("UPDATE letter_indicators SET last_sequence = ? WHERE id = ?")->execute([$nextSeq, $indRow['id']]);
            } else {
                $nextSeq = 1;
                $pdo->prepare("INSERT INTO letter_indicators (fiscal_year_id, department_prefix, last_sequence) VALUES (?, ?, 1)")->execute([$fiscalYearId, $basePrefix]);
            }

            $jy = function_exists('jdate') ? jdate('Y', '', '', '', 'en') : date('Y'); 
            $jm = function_exists('jdate') ? jdate('m', '', '', '', 'en') : date('m'); 
            $year3Digit = substr($jy, 1, 3); 
            $datePart = $year3Digit . $jm; 
            $seqPart = str_pad($nextSeq, 3, '0', STR_PAD_LEFT); 
            
            $newIndicatorNumber = "{$fullPrefix}-{$seqPart}-{$datePart}";
            
            $pdo->prepare("UPDATE letters SET status = 'registered', indicator_number = ?, registered_at = NOW() WHERE id = ?")
                ->execute([$newIndicatorNumber, $letterId]);

            $stmtRecs = $pdo->prepare("SELECT receiver_id FROM letter_receivers WHERE letter_id = ? AND receiver_type = 'user'");
            $stmtRecs->execute([$letterId]);
            $receivers = $stmtRecs->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($receivers)) {
                $stmtCheckDuplicate = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND is_completed = 0");
                $stmtRef = $pdo->prepare("INSERT INTO letter_referrals (letter_id, sender_id, receiver_id, action_type, description, is_completed, created_at) VALUES (?, ?, ?, 'for_action', 'ارجاع خودکار سیستمی پس از صدور شماره', 0, NOW())");
                foreach ($receivers as $recId) {
                    $stmtCheckDuplicate->execute([$letterId, $recId]);
                    if ($stmtCheckDuplicate->fetchColumn() == 0) {
                        $stmtRef->execute([$letterId, $userId, $recId]);
                        if (function_exists('send_user_notification')) {
                            send_user_notification($pdo, $recId, "📥 نامه جدید: {$newIndicatorNumber}", "نامه‌ای جهت بررسی و اقدام به کارتابل شما ارجاع شد.", "admin/letter_view.php?id={$letterId}", 'letter', $letterId);
                        }
                    }
                }
            }

            $pdo->commit();
            header("Location: letters_pending.php?msg=approved");
            exit;
        }

        elseif ($action === 'approve_outgoing') {
            $pdo->prepare("UPDATE letters SET status = 'approved_for_sign' WHERE id = ?")->execute([$letterId]);
            
            $stmtSigs = $pdo->prepare("SELECT user_id FROM letter_signers WHERE letter_id = ?");
            $stmtSigs->execute([$letterId]);
            $signersList = $stmtSigs->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($signersList)) {
                $stmtCheckDuplicate = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND action_type = 'for_signature' AND is_completed = 0");
                $stmtRef = $pdo->prepare("INSERT INTO letter_referrals (letter_id, sender_id, receiver_id, action_type, description, is_completed, created_at) VALUES (?, ?, ?, 'for_signature', 'ارجاع سیستمی جهت بررسی و تایید امضا', 0, NOW())");
                foreach ($signersList as $sigId) {
                    $stmtCheckDuplicate->execute([$letterId, $sigId]);
                    if ($stmtCheckDuplicate->fetchColumn() == 0) {
                        $stmtRef->execute([$letterId, $userId, $sigId]);
                        if (function_exists('send_user_notification')) {
                            send_user_notification($pdo, $sigId, "✍️ درخواست امضا", "یک نامه صادره جهت بررسی و تایید امضا به کارتابل شما ارجاع شد.", "admin/letter_view.php?id={$letterId}", 'letter', $letterId);
                        }
                    }
                }
            }
            $pdo->commit();
            header("Location: letters_pending.php?msg=approved_for_sign");
            exit;
        }

        elseif ($action === 'accept_for_sign') {
            $pdo->prepare("UPDATE letter_referrals SET is_completed = 1, completed_at = NOW(), completion_note = 'تایید جهت درج امضا' WHERE letter_id = ? AND receiver_id = ? AND action_type = 'for_signature'")->execute([$letterId, $userId]);
            $pdo->prepare("UPDATE letter_signers SET status = 'accepted' WHERE letter_id = ? AND user_id = ?")->execute([$letterId, $userId]);
            
            $pdo->commit();
            header("Location: letters_pending.php?msg=accepted_for_sign");
            exit;
        }

        elseif ($action === 'delete_letter') {
            $stmtCheck = $pdo->prepare("SELECT indicator_number, created_by, type, signature_status FROM letters WHERE id = ?");
            $stmtCheck->execute([$letterId]);
            $let = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if ($let['signature_status'] !== 'none') throw new Exception("این نامه امضا شده است و قابل حذف نیست.");
            
            if (!$isAdmin && $let['created_by'] != $userId) {
                throw new Exception("شما دسترسی حذف این نامه را ندارید.");
            }
            if (!empty($let['indicator_number']) && in_array($let['type'], ['incoming', 'internal'])) {
                throw new Exception("نامه‌ای که شماره اندیکاتور گرفته است قابل حذف نیست.");
            }
            
            $pdo->prepare("UPDATE letters SET is_deleted = 1 WHERE id = ?")->execute([$letterId]);
            if (function_exists('logSystem')) logSystem('Letters', 'delete', $letterId, "انتقال پیش‌نویس نامه به زباله‌دان");
            
            $pdo->commit();
            header("Location: letters_pending.php?msg=deleted");
            exit;
        }

        elseif ($action === 'archive_letter') {
            $stmtRefCheck = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND is_completed = 0");
            $stmtRefCheck->execute([$letterId]);
            $openRefs = (int)$stmtRefCheck->fetchColumn();

            if ($openRefs > 0) {
                $pdo->rollBack();
                header("Location: letters_pending.php?msg=err_archive_ref");
                exit;
            }

            $pdo->prepare("UPDATE letters SET is_archived = 1 WHERE id = ?")->execute([$letterId]);
            if (function_exists('logSystem')) logSystem('Letters', 'archive', $letterId, "بایگانی نامه از کارتابل من");

            $pdo->commit();
            header("Location: letters_pending.php?msg=archived");
            exit;
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "خطا: " . $e->getMessage();
        $msgType = "error";
    }
}

$sql = "SELECT l.*, u.first_name, u.last_name,
        (SELECT COUNT(id) FROM letter_referrals r WHERE r.letter_id = l.id AND (r.receiver_id = $userId OR r.sender_id = $userId)) as involved_ref_count
        FROM letters l 
        LEFT JOIN users u ON l.created_by = u.id 
        WHERE (l.is_archived = 0 OR l.is_archived IS NULL) 
        AND (l.is_deleted = 0 OR l.is_deleted IS NULL) 
        AND (
            (l.type IN ('internal', 'incoming') AND l.status IN ('pending_action', 'registered', 'in_referral'))
            OR
            (l.type = 'outgoing' AND l.status IN ('pending_action', 'in_referral', 'approved_for_sign'))
        )";

if (!$isAdmin) {
    $sql .= " AND (l.created_by = $userId OR EXISTS(SELECT 1 FROM letter_referrals r2 WHERE r2.letter_id = l.id AND r2.receiver_id = $userId AND r2.is_completed = 0)) ";
}
$sql .= " ORDER BY l.created_at DESC";

$pendingLetters = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = '⏳ کارتابل من (در دست اقدام)';
$basePath = '../';

$extraCss = '
<style>
    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    
    .table-container { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px;}
    .l-table { width: 100%; border-collapse: collapse; background: #fff; min-width: 950px; table-layout: fixed; }
    .l-table th, .l-table td { padding: 12px 10px; text-align: right; border-bottom: 1px solid #f1f5f9; vertical-align: middle; word-wrap: break-word; }
    .l-table th { background: #f8fafc; font-weight: 800; color: #475569; }
    .l-table tr:hover { background: #f8fafc; }
    
    /* قفل عرض ستون‌ها در دسکتاپ */
    .col-ind { width: 14%; }
    .col-subj { width: 34%; line-height: 1.6; }
    .col-type { width: 10%; }
    .col-date { width: 12%; }
    .col-status { width: 10%; }
    .col-actions { width: 20%; text-align: center; }

    .status-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; display: inline-block; }
    .st-pending { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
    .st-registered { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .st-wait-sign { background: #e0e7ff; color: #1d4ed8; border: 1px solid #c7d2fe; }
    
    .btn-group-row { display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap; }
    .action-btn { padding: 6px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 4px; border: none; cursor: pointer; text-decoration: none; white-space: nowrap; }
    
    .btn-view { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
    .btn-refer { background: #faf5ff; color: #9333ea; border: 1px solid #e9d5ff; }
    .btn-approve { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
    .btn-accept-sign { background: #10b981; color: #fff; border: 1px solid #059669; }
    .btn-archive { background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; }
    .btn-timeline { background: #f8fafc; color: #0f172a; border: 1px solid #94a3b8; }
    .btn-edit { background: #fef3c7; color: #d97706; border: 1px solid #fde047; }
    .btn-delete { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

    .action-btn:hover { opacity: 0.85; transform: translateY(-1px); }

    .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(4px);}
    .modal-content { background:#fff; width:90%; max-width:750px; border-radius:16px; padding:25px; max-height:85vh; overflow-y:auto; position:relative; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
    .timeline-modal-body { position: relative; padding-right: 25px; border-right: 2px solid #e2e8f0; margin-top: 15px; }
    .tl-item { position: relative; margin-bottom: 25px; padding-right: 15px; }
    .tl-dot { position: absolute; right: -33px; top: 0; width: 16px; height: 14px; border-radius: 50%; background: #3b82f6; border: 3px solid #fff; box-shadow: 0 0 0 1px #3b82f6; }
    .tl-dot.completed { background: #10b981; box-shadow: 0 0 0 1px #10b981; }
    .tl-header { display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 5px; }
    .tl-note { background: #f8fafc; padding: 10px 15px; border-radius: 8px; font-size: 0.85rem; border: 1px solid #edf2f7; color: #334155; line-height:1.7; }
    .tl-meta { font-size: 0.8rem; color: #64748b; margin-top: 8px; display: flex; gap: 10px; flex-wrap: wrap; }

    @media (max-width: 900px) {
        .table-container { border: 1px solid #cbd5e1; }
    }

    @media (max-width: 768px) {
        .content-wrapper { padding: 10px !important; overflow-x: hidden; }
        .card { padding: 15px !important; border-radius: 12px !important; border: none !important; box-shadow: none !important; }
        
        .table-container { overflow: visible; border: none; background: transparent; padding: 0;}
        .l-table { display: block; table-layout: auto; min-width: 100%; border: none;}
        .l-table tbody, .l-table tr, .l-table td { display: block; width: 100%; box-sizing: border-box;}
        .l-table thead { display: none; }
        .l-table tr { margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
        .l-table td { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #e2e8f0; padding: 10px 0; text-align: left; word-break: break-word; }
        .l-table td:last-child { border-bottom: none; justify-content: center; padding-top: 15px; flex-direction: column; gap: 10px; }
        .l-table td::before { content: attr(data-label); font-weight: bold; color: #64748b; margin-left: 10px; text-align: right; white-space: nowrap; }
        
        /* دکمه‌ها در موبایل - کاملاً وسط چین و انعطاف‌پذیر */
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

<main class="main-content">
    <div class="content-wrapper">
        <div class="page-header page-actions d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div><span class="page-title fw-bold" style="font-size:1.2rem;">⏳ کارتابل من (در دست اقدام)</span></div>
            <div><a href="letter_create.php" class="btn btn-primary fw-bold px-3 py-2" style="border-radius:8px;">+ ایجاد نامه جدید</a></div>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msgType; ?> fw-bold" style="border-radius:8px;">
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <div class="card" style="padding: 20px; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
            <div class="table-container">
                <table class="l-table">
                    <thead>
                        <tr>
                            <th class="col-ind">شماره اندیکاتور</th>
                            <th class="col-subj text-right">موضوع نامه</th>
                            <th class="col-type">نوع نامه</th>
                            <th class="col-date">تاریخ ثبت</th>
                            <th class="col-status">وضعیت فعلی</th>
                            <th class="col-actions">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($pendingLetters as $l): 
                            $isSignerPending = false;
                            $hasRef = false;
                            if ($l['type'] === 'outgoing') {
                                $stmtCheckSig = $pdo->prepare("SELECT status FROM letter_signers WHERE letter_id = ? AND user_id = ?");
                                $stmtCheckSig->execute([$l['id'], $userId]);
                                if ($stmtCheckSig->fetchColumn() === 'pending') {
                                    $stmtCheckRef = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND action_type = 'for_signature' AND is_completed = 0");
                                    $stmtCheckRef->execute([$l['id'], $userId]);
                                    if ($stmtCheckRef->fetchColumn() > 0) $isSignerPending = true;
                                }
                            } else {
                                $stmtCheckRef = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND is_completed = 0");
                                $stmtCheckRef->execute([$l['id'], $userId]);
                                if ($stmtCheckRef->fetchColumn() > 0) $hasRef = true;
                            }
                        ?>
                        <tr>
                            <td data-label="شماره:">
                                <strong dir="ltr" style="color:#0f172a;"><?php echo $l['indicator_number'] ?: '---'; ?></strong>
                            </td>
                            <td data-label="موضوع:" class="fw-bold" style="color:#1d4ed8;"><?php echo htmlspecialchars($l['subject']); ?></td>
                            <td data-label="نوع:">
                                <?php 
                                    $types = ['internal'=>'داخلی', 'outgoing'=>'صادره', 'incoming'=>'وارده'];
                                    echo '<span style="font-size:0.85rem; font-weight:bold; color:#475569;">'.($types[$l['type']] ?? 'نامشخص').'</span>';
                                ?>
                            </td>
                            <td data-label="ثبت:">
                                <span dir="ltr" class="fw-bold" style="font-size:0.9rem; color:#0f172a; white-space:nowrap;">
                                    <?php 
                                        $ts = strtotime($l['created_at']);
                                        echo date('H:i', $ts) . ' - ' . (function_exists('jdate') ? jdate('Y/m/d', $ts) : date('Y/m/d', $ts));
                                    ?>
                                </span>
                            </td>
                            <td data-label="وضعیت:">
                                <?php if ($l['status'] === 'registered'): ?>
                                    <span class="status-badge st-registered">شماره شده / فعال</span>
                                <?php elseif ($l['status'] === 'in_referral' || $l['status'] === 'approved_for_sign'): ?>
                                    <span class="status-badge st-wait-sign">در جریان / ارجاع</span>
                                <?php else: ?>
                                    <span class="status-badge st-pending">در دست بررسی</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="عملیات:">
                                <div class="btn-group-row">
                                    <a href="letter_view.php?id=<?php echo $l['id']; ?>" class="action-btn btn-view" title="مشاهده نامه">👁️ مشاهده</a>
                                    
                                    <?php 
                                    $showTimelineBtn = ($isAdmin || $l['involved_ref_count'] > 0);
                                    if ($showTimelineBtn): 
                                    ?>
                                        <button type="button" onclick="showTimeline(<?php echo $l['id']; ?>)" class="action-btn btn-timeline" title="تاریخچه گردش">🔄 گردش</button>
                                    <?php endif; ?>

                                    <?php if ($isSignerPending): ?>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('آیا نامه را جهت امضا تایید می‌کنید؟ (نامه به میز کار امضا منتقل خواهد شد)');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action_type" value="accept_for_sign">
                                            <input type="hidden" name="letter_id" value="<?php echo $l['id']; ?>">
                                            <button type="submit" class="action-btn btn-accept-sign">✔️ تایید برای امضا</button>
                                        </form>
                                    <?php else: ?>

                                        <?php if (empty($l['indicator_number']) && !in_array($l['status'], ['approved_for_sign', 'in_referral'])): ?>
                                            <a href="letter_edit.php?id=<?php echo $l['id']; ?>" class="action-btn btn-edit">✏️ ویرایش</a>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('آیا از انتقال این نامه به زباله‌دان اطمینان دارید؟');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action_type" value="delete_letter">
                                                <input type="hidden" name="letter_id" value="<?php echo $l['id']; ?>">
                                                <button type="submit" class="action-btn btn-delete">🗑️ حذف</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($l['type'] === 'outgoing'): ?>
                                            <?php // تغییر مهم: برداشتن شرط empty indicator_number تا پس از لغو امضا هم بتوان ارسال کرد ?>
                                            <?php if (!in_array($l['status'], ['approved_for_sign', 'in_referral'])): ?>
                                                <form method="POST" style="margin:0;" onsubmit="return confirm('آیا از تایید و ارسال این نامه به کارتابل امضاکنندگان اطمینان دارید؟');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action_type" value="approve_outgoing">
                                                    <input type="hidden" name="letter_id" value="<?php echo $l['id']; ?>">
                                                    <button type="submit" class="action-btn btn-approve">✔️ ارسال برای امضا</button>
                                                </form>
                                            <?php endif; ?>
                                            
                                        <?php else: ?>
                                            
                                            <?php if (empty($l['indicator_number'])): ?>
                                                <form method="POST" style="margin:0;" onsubmit="return confirm('آیا از صدور شماره اندیکاتور و ارجاع نامه به گیرندگان اطمینان دارید؟');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action_type" value="approve_internal">
                                                    <input type="hidden" name="letter_id" value="<?php echo $l['id']; ?>">
                                                    <button type="submit" class="action-btn btn-approve">✔️ صدور شماره</button>
                                                </form>
                                            <?php elseif($hasRef): ?>
                                                <a href="letter_refer.php?id=<?php echo $l['id']; ?>" class="action-btn btn-refer" title="ارجاع">📤 ارجاع</a>
                                            <?php endif; ?>
                                            
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($pendingLetters)) echo '<tr><td colspan="6" class="text-center py-5 fw-bold" style="color:#94a3b8;">شما نامه‌ای در دست اقدام ندارید.</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
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
function showTimeline(letterId) {
    document.getElementById('timelineModal').style.display = 'flex';
    document.getElementById('timelineResult').innerHTML = '<div class="text-center py-5 fw-bold" style="color:#64748b;">⏳ در حال بارگذاری اطلاعات...</div>';
    
    fetch('letters_pending.php?get_timeline=' + letterId)
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
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>