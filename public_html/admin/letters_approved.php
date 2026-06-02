<?php
/*
 * فایل: public_html/admin/letters_approved.php
 * توضیحات: کارتابل اختصاصی امضا (طراحی کاملاً رسپانسیو برای موبایل، تبلت و دسکتاپ)
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$isAdmin = ($role === 'admin');

$hasAccess = $isAdmin || (function_exists('hasPermission') && hasPermission('letters_sign')) || in_array($role, ['manager', 'management']);
if (!$hasAccess) {
    die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma; color:red; font-weight:bold;">⛔ شما مجوز امضای نامه‌ها را ندارید.</div>');
}

try {
    $pdo->query("SELECT signed_at, sign_type FROM letter_signers LIMIT 1");
} catch (Throwable $e) {
    try { $pdo->exec("ALTER TABLE letter_signers ADD COLUMN signed_at DATETIME NULL AFTER status"); } catch(Throwable $e2) {}
    try { $pdo->exec("ALTER TABLE letter_signers ADD COLUMN sign_type VARCHAR(50) NULL AFTER signed_at"); } catch(Throwable $e2) {}
}

$msg = ''; $msgType = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'sign_partial') { $msg = "✅ امضای شما ثبت شد. در انتظار امضای نفر بعدی."; $msgType = "info"; }
    elseif ($_GET['msg'] === 'sign_success') { $msg = "✅ امضا تکمیل و اندیکاتور نهایی صادر شد."; $msgType = "success"; }
    elseif ($_GET['msg'] === 'unsign_success') { $msg = "🔓 امضا لغو شد. می‌توانید مجدداً امضا کنید."; $msgType = "success"; }
    elseif ($_GET['msg'] === 'archive_success') { $msg = "🗄️ نامه با موفقیت بایگانی شد و پرونده بسته شد."; $msgType = "success"; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");
    $action = $_POST['action_type'] ?? '';
    $letterId = (int)$_POST['letter_id'];

    try {
        $pdo->beginTransaction();

        if ($action === 'sign_letter' || $action === 'remove_sign') {
            $enteredPin = trim($_POST[$action === 'sign_letter' ? 'pin_code' : 'pin_code_unsign'] ?? '');
            
            $stmtPinCheck = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'signature_pin_$userId'");
            $hasPin = $stmtPinCheck->fetchColumn();

            if (!$hasPin) {
                if (faToEn($enteredPin) !== '1234') {
                    throw new Exception("پین‌کد وارد شده اشتباه است! (رمز پیش‌فرض سیستم 1234 است. لطفاً از تنظیمات تغییر دهید)");
                }
            } else {
                if (!verify_user_pin($pdo, $userId, $enteredPin)) {
                    throw new Exception("پین‌کد وارد شده اشتباه است!");
                }
            }
        }

        if ($action === 'sign_letter') {
            $stmtCheckType = $pdo->prepare("SELECT type, indicator_number, department_prefix FROM letters WHERE id = ?");
            $stmtCheckType->execute([$letterId]);
            $letterData = $stmtCheckType->fetch();

            $signType = $_POST['sign_type'] ?? 'simple';
            $useLetterhead = isset($_POST['use_letterhead']) ? 1 : 0;
            
            $pdo->prepare("UPDATE letter_signers SET status = 'signed', signed_at = NOW(), sign_type = ? WHERE letter_id = ? AND user_id = ?")
                ->execute([$signType, $letterId, $userId]);

            $pdo->prepare("INSERT INTO letter_referrals (letter_id, sender_id, receiver_id, action_type, description, is_completed, completed_at, completion_note) VALUES (?, ?, ?, 'for_signature', 'ثبت امضای دیجیتال', 1, NOW(), 'امضای این شخص با موفقیت ثبت شد.')")
                ->execute([$letterId, $userId, $userId]);

            $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM letter_signers WHERE letter_id = ? AND status != 'signed'");
            $stmtPending->execute([$letterId]);
            $pendingCount = $stmtPending->fetchColumn();

            if ($pendingCount == 0) {
                $newIndicatorNumber = $letterData['indicator_number'];
                if (empty($newIndicatorNumber)) {
                    $activeFiscalYear = getActiveFiscalYear();
                    $fiscalYearId = $activeFiscalYear ? $activeFiscalYear['id'] : null;
                    if (!$fiscalYearId) throw new Exception("سال مالی فعال نیست.");

                    $prefix = $letterData['department_prefix'] ?: 'الف'; 
                    $fullPrefix = $prefix . '/ص';

                    $stmtInd = $pdo->prepare("SELECT id, last_sequence FROM letter_indicators WHERE fiscal_year_id = ? AND department_prefix = ? FOR UPDATE");
                    $stmtInd->execute([$fiscalYearId, $prefix]);
                    $indRow = $stmtInd->fetch(PDO::FETCH_ASSOC);

                    if ($indRow) {
                        $nextSeq = (int)$indRow['last_sequence'] + 1;
                        $pdo->prepare("UPDATE letter_indicators SET last_sequence = ? WHERE id = ?")->execute([$nextSeq, $indRow['id']]);
                    } else {
                        $nextSeq = 1;
                        $pdo->prepare("INSERT INTO letter_indicators (fiscal_year_id, department_prefix, last_sequence) VALUES (?, ?, 1)")->execute([$fiscalYearId, $prefix]);
                    }

                    $jy = function_exists('jdate') ? jdate('Y', '', '', '', 'en') : date('Y'); 
                    $jm = function_exists('jdate') ? jdate('m', '', '', '', 'en') : date('m'); 
                    $year3Digit = substr($jy, 1, 3); 
                    $datePart = $year3Digit . $jm; 
                    $seqPart = str_pad($nextSeq, 3, '0', STR_PAD_LEFT); 
                    $newIndicatorNumber = "{$fullPrefix}-{$seqPart}-{$datePart}"; 
                }

                $pdo->prepare("UPDATE letters SET signature_status = ?, use_letterhead = ?, indicator_number = COALESCE(indicator_number, ?), status = 'registered', registered_at = COALESCE(registered_at, NOW()) WHERE id = ?")
                    ->execute([$signType, $useLetterhead, $newIndicatorNumber, $letterId]);

                // سیستم ارجاع خودکار برای گیرندگان داخلی پس از صدور نهایی نامه صادره
                $stmtRecs = $pdo->prepare("SELECT receiver_id FROM letter_receivers WHERE letter_id = ? AND receiver_type = 'user'");
                $stmtRecs->execute([$letterId]);
                $receivers = $stmtRecs->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($receivers)) {
                    $stmtCheckDuplicate = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND is_completed = 0");
                    $stmtRef = $pdo->prepare("INSERT INTO letter_referrals (letter_id, sender_id, receiver_id, action_type, description, is_completed, created_at) VALUES (?, ?, ?, 'for_action', 'ارجاع خودکار سیستمی پس از امضای نهایی و صدور شماره', 0, NOW())");
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
                header("Location: letters_approved.php?msg=sign_success");
                exit;
            } else {
                $pdo->prepare("UPDATE letters SET signature_status = 'partial', use_letterhead = ? WHERE id = ?")->execute([$useLetterhead, $letterId]);
                $pdo->commit();
                header("Location: letters_approved.php?msg=sign_partial");
                exit;
            }
        }
        elseif ($action === 'remove_sign') {
            $pdo->prepare("UPDATE letter_signers SET status = 'accepted', signed_at = NULL WHERE letter_id = ? AND user_id = ?")->execute([$letterId, $userId]);
            $pdo->prepare("UPDATE letters SET status = 'approved_for_sign', indicator_number = NULL, signature_status = 'partial' WHERE id = ?")->execute([$letterId]);
            $pdo->commit();
            header("Location: letters_approved.php?msg=unsign_success");
            exit;
        }
        elseif ($action === 'archive_letter') {
            $canArchive = $isAdmin || (function_exists('hasPermission') && hasPermission('letters_archive'));
            if (!$canArchive) throw new Exception("فقط مدیریت مجاز به بایگانی است.");
            
            $pdo->prepare("UPDATE letters SET is_archived = 1, status = 'registered' WHERE id = ?")->execute([$letterId]);
            $pdo->commit();
            header("Location: letters_approved.php?msg=archive_success");
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $msg = "خطا: " . $e->getMessage();
        $msgType = "error";
    }
}

$approvedLetters = [];
try {
    $sql = "SELECT l.*, u.first_name, u.last_name,
            (SELECT COUNT(*) FROM letter_signers WHERE letter_id = l.id) as total_signers,
            (SELECT COUNT(*) FROM letter_signers WHERE letter_id = l.id AND status = 'signed') as completed_signers,
            ls.status as my_sign_status, ls.sign_type as my_sign_type
            FROM letters l 
            LEFT JOIN users u ON l.created_by = u.id 
            JOIN letter_signers ls ON l.id = ls.letter_id
            WHERE ls.user_id = $userId AND ls.status IN ('accepted', 'signed') AND l.status NOT IN ('draft', 'terminated') ";

    if (function_exists('db_has_column')) {
        if (db_has_column($pdo, 'letters', 'is_archived')) $sql .= " AND (l.is_archived = 0 OR l.is_archived IS NULL) ";
        if (db_has_column($pdo, 'letters', 'is_deleted')) $sql .= " AND (l.is_deleted = 0 OR l.is_deleted IS NULL) ";
    }
    $sql .= " ORDER BY l.updated_at DESC, l.created_at DESC";

    $approvedLetters = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $msg = "خطا در بارگذاری لیست.";
    $msgType = "error";
}

$pageTitle = '✍️ کارتابل امضا';
$basePath = '../';

$extraCss = '<style>
    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    
    .table-container { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px; background: #fff; box-shadow: 0 4px 15px rgba(0,0,0,0.02);}
    .l-table { width: 100%; border-collapse: collapse; background: #fff; min-width: 950px; table-layout: auto;}
    .l-table th, .l-table td { padding: 14px 12px; text-align: right; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .l-table th { background: #f8fafc; font-weight: 800; color: #475569; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .l-table tr:hover { background: #f8fafc; }
    
    .col-creator { width: 15%; }
    .col-ind { width: 12%; }
    .col-subj { width: 28%; }
    .col-sign-all { width: 12%; }
    .col-sign-me { width: 13%; }
    .col-actions { width: 20%; text-align: center; }

    .status-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; display: inline-block;}
    
    .btn-group-row { display: flex; align-items: center; justify-content: center; gap: 5px; flex-wrap: wrap; }
    .action-btn { padding: 6px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 4px; border: none; cursor: pointer; text-decoration: none; white-space: nowrap; }
    .btn-view { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
    .btn-sign { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .btn-edit-sign { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
    .btn-unsign { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    .btn-archive { background: #10b981; color: #fff; border: 1px solid #059669; }
    .action-btn:hover { opacity: 0.85; transform: translateY(-1px); }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-box { background: #fff; width: 90%; max-width: 500px; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
    .modal-header { padding: 15px 20px; background: #f8fafc; font-weight: 800; border-bottom: 1px solid #e2e8f0; display:flex; justify-content: space-between; align-items: center;}
    .modal-body { padding: 20px; }

    @media (min-width: 769px) {
        .nowrap-desktop { white-space: nowrap !important; }
        .wrap-subject { white-space: normal !important; word-wrap: break-word !important; line-height: 1.8; }
    }

    @media (max-width: 992px) {
        .table-container { border: 1px solid #cbd5e1; }
    }

    @media (max-width: 768px) {
        .content-wrapper { padding: 10px !important; overflow-x: hidden; }
        
        .table-container { overflow: visible; border: none; background: transparent; box-shadow: none;}
        .l-table, .l-table tbody, .l-table tr, .l-table td { display: block; width: 100%; min-width: 100%; box-sizing: border-box;}
        .l-table thead { display: none; }
        .l-table tr { margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
        
        .l-table td { 
            display: flex; 
            flex-direction: column; 
            align-items: flex-start;
            gap: 6px;
            text-align: right; 
            padding: 12px 0; 
            border-bottom: 1px dashed #e2e8f0; 
            word-break: break-word; 
            white-space: normal;
        }
        .l-table td:last-child { border-bottom: none; padding-top: 15px; align-items: stretch; }
        .l-table td::before { 
            content: attr(data-label); 
            font-weight: bold; 
            color: #64748b; 
            font-size: 0.85rem;
            background: #f8fafc;
            padding: 2px 8px;
            border-radius: 4px;
        }
        
        .btn-group-row { flex-wrap: wrap; width: 100%; gap: 10px; }
        .action-btn { flex: 1; width: 100%; padding: 12px; font-size: 0.9rem;}
        .btn-group-row form { width: 100%; margin: 0; }
        .btn-group-row form button { width: 100%; padding: 12px; font-size: 0.9rem;}
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content">
    <div class="content-wrapper">
        <div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div><span class="page-title fw-bold" style="font-size:1.2rem;">✍️ میز کار امضا (تایید شده‌ها)</span></div>
        </div>

        <?php if($msg): ?>
            <div id="alertMsg" class="alert alert-<?php echo $msgType; ?> fw-bold" style="border-radius: 8px;"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="table-container">
            <table class="l-table">
                <thead>
                    <tr>
                        <th class="col-creator nowrap-desktop">ایجاد کننده</th>
                        <th class="col-ind nowrap-desktop">شماره اندیکاتور</th>
                        <th class="col-subj text-right">موضوع نامه</th>
                        <th class="col-sign-all nowrap-desktop">وضعیت کلی امضاها</th>
                        <th class="col-sign-me nowrap-desktop">وضعیت امضای شما</th>
                        <th class="col-actions nowrap-desktop text-center">عملیات مدیر</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($approvedLetters as $l): 
                        $mySignStatus = ($l['my_sign_status'] === 'signed');
                        $mySignType = $l['my_sign_type'] ?? 'none';
                        $useLh = $l['use_letterhead'] ?? 0;
                        $allSigned = ($l['total_signers'] == $l['completed_signers']);
                    ?>
                    <tr>
                        <td class="nowrap-desktop" data-label="ایجاد کننده:"><?php echo htmlspecialchars($l['first_name'].' '.$l['last_name']); ?></td>
                        <td class="nowrap-desktop" data-label="شماره:"><strong dir="ltr"><?php echo $l['indicator_number'] ?: '---'; ?></strong></td>
                        <td class="wrap-subject fw-bold" data-label="موضوع:" style="color:#1d4ed8;"><?php echo htmlspecialchars($l['subject']); ?></td>
                        <td class="nowrap-desktop" data-label="کل امضاها:">
                            <span class="status-badge" style="background:#f1f5f9; color:#0f172a; border:1px solid #cbd5e1;">
                                <?php echo $l['completed_signers'] . ' از ' . $l['total_signers'] . ' امضا'; ?>
                            </span>
                        </td>
                        <td class="nowrap-desktop" data-label="امضای شما:">
                            <?php if($mySignStatus): ?>
                                <span class="status-badge" style="background:#dcfce7; color:#166534; border: 1px solid #bbf7d0;">امضا کرده‌اید</span>
                            <?php else: ?>
                                <span class="status-badge" style="background:#e0e7ff; color:#1d4ed8; border: 1px solid #c7d2fe;">آماده درج امضا</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap-desktop" data-label="عملیات:" style="text-align:center;">
                            <div class="btn-group-row">
                                <a href="letter_view.php?id=<?php echo $l['id']; ?>" class="action-btn btn-view" title="مشاهده نامه">👁️ مشاهده</a>
                                
                                <?php if(!$mySignStatus): ?>
                                    <button onclick="openSignModal(<?php echo $l['id']; ?>, 'simple', 0)" class="action-btn btn-sign">✍️ ثبت امضا</button>
                                <?php else: ?>
                                    <button onclick="openSignModal(<?php echo $l['id']; ?>, '<?php echo $mySignType; ?>', <?php echo $useLh; ?>)" class="action-btn btn-edit-sign">🔄 تغییر</button>
                                    <button onclick="openUnsignModal(<?php echo $l['id']; ?>)" class="action-btn btn-unsign">🔓 لغو</button>
                                    
                                    <?php if($isAdmin && $allSigned): ?>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('آیا از بایگانی نهایی پرونده اطمینان دارید؟');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action_type" value="archive_letter">
                                        <input type="hidden" name="letter_id" value="<?php echo $l['id']; ?>">
                                        <button type="submit" class="action-btn btn-archive">🗄️ بایگانی</button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; if(empty($approvedLetters)) echo '<tr><td colspan="6" class="text-center py-5 fw-bold" style="color:#94a3b8;">شما نامه‌ای در انتظار امضا ندارید.</td></tr>'; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div id="signModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header"><span>✍️ تنظیمات امضای الکترونیکی</span><span style="cursor:pointer; color:#ef4444; font-size:1.5rem;" onclick="document.getElementById('signModal').style.display='none'">&times;</span></div>
        <div class="modal-body">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action_type" value="sign_letter">
                <input type="hidden" name="letter_id" id="sign_letter_id" value="">
                
                <div style="margin-bottom:15px;">
                    <label style="font-weight:bold; font-size:0.85rem; color:#475569; display:block; margin-bottom:5px;">نوع درج امضای شما در پرینت</label>
                    <select name="sign_type" id="sign_type_select" class="form-control" style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-family:inherit;">
                        <option value="simple">متن ساده (نام و سمت بدون عکس)</option>
                        <option value="sign">تصویر امضا + متن</option>
                        <option value="stamp">مهر شرکت + متن</option>
                        <option value="both">تصویر امضا + مهر شرکت + متن</option>
                    </select>
                </div>
                
                <div style="margin-bottom:15px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:bold; font-size:0.85rem; color:#0f766e; background:#ecfeff; padding:12px; border-radius:8px; border:1px solid #a5f3fc;">
                        <input type="checkbox" name="use_letterhead" id="use_letterhead_chk" value="1" style="width:20px; height:20px;">
                        🖨️ چاپ نامه روی سربرگ شرکت (پس‌زمینه رنگی)
                    </label>
                </div>
                <div style="margin-bottom:25px;">
                    <label style="font-weight:bold; font-size:0.85rem; color:#475569; display:block; margin-bottom:5px;">پین‌کد اختصاصی شما</label>
                    <input type="password" name="pin_code" required class="form-control" placeholder="****" style="width:100%; padding:12px; border-radius:8px; border:1px solid #cbd5e1; text-align:center; letter-spacing:5px; font-size:1.2rem; font-weight:bold;">
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="button" class="btn btn-outline-secondary" style="flex:1; font-weight:bold;" onclick="document.getElementById('signModal').style.display='none'">انصراف</button>
                    <button type="submit" class="btn btn-success" style="flex:1; background:#10b981; font-weight:bold; color:white; border:none;">🔐 اعمال و ثبت</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="unsignModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header"><span>🔓 لغو امضای شما</span><span style="cursor:pointer; color:#ef4444; font-size:1.5rem;" onclick="document.getElementById('unsignModal').style.display='none'">&times;</span></div>
        <div class="modal-body">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action_type" value="remove_sign">
                <input type="hidden" name="letter_id" id="unsign_letter_id" value="">
                <div style="margin-bottom:25px;">
                    <label style="font-weight:bold; font-size:0.85rem; color:#475569; display:block; margin-bottom:5px;">برای لغو امضا، پین‌کد خود را وارد کنید:</label>
                    <input type="password" name="pin_code_unsign" required class="form-control" placeholder="****" style="width:100%; padding:12px; border-radius:8px; border:1px solid #cbd5e1; text-align:center; letter-spacing:5px; font-size:1.2rem; font-weight:bold;">
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="button" class="btn btn-outline-secondary" style="flex:1; font-weight:bold;" onclick="document.getElementById('unsignModal').style.display='none'">انصراف</button>
                    <button type="submit" class="btn btn-danger" style="flex:1; font-weight:bold; color:white; border:none;">🔓 لغو قطعی</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const alertBox = document.getElementById('alertMsg');
        if (alertBox) { setTimeout(() => alertBox.remove(), 5000); }
    });

    function openSignModal(id, currentSignType, currentUseLetterhead) {
        document.getElementById('sign_letter_id').value = id;
        document.getElementById('sign_type_select').value = (currentSignType === 'none') ? 'simple' : currentSignType;
        document.getElementById('use_letterhead_chk').checked = (currentUseLetterhead == 1);
        document.getElementById('signModal').style.display = 'flex';
    }

    function openUnsignModal(id) {
        document.getElementById('unsign_letter_id').value = id;
        document.getElementById('unsignModal').style.display = 'flex';
    }
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>