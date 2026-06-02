<?php
/*
 * فایل: public_html/admin/letter_edit.php
 * توضیحات: ویرایش نامه (لغو امضای قبلی، تغییر وضعیت به در دست اقدام جهت شروع مجدد چرخه)
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

try { $pdo->query("SELECT department_prefix FROM letters LIMIT 1"); } 
catch (Throwable $e) { $pdo->exec("ALTER TABLE letters ADD COLUMN department_prefix VARCHAR(50) NULL AFTER classification"); }

try { $pdo->query("SELECT sender_external_name FROM letters LIMIT 1"); } 
catch (Throwable $e) { $pdo->exec("ALTER TABLE letters ADD COLUMN sender_external_name VARCHAR(255) NULL AFTER sender_id"); }

if (!function_exists('getNextIndicatorNumber')) {
    function getNextIndicatorNumber($pdo, $fiscalYearId, $departmentPrefix, $type) {
        $basePrefix = $departmentPrefix ?: 'الف';
        $typeLetter = ($type === 'outgoing') ? 'ص' : (($type === 'internal') ? 'د' : 'و');
        $fullPrefix = $basePrefix . '/' . $typeLetter;

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS letter_indicators (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fiscal_year_id INT,
                department_prefix VARCHAR(50),
                letter_type VARCHAR(20) DEFAULT 'internal',
                last_sequence INT DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $checkCol = $pdo->query("SHOW COLUMNS FROM letter_indicators LIKE 'letter_type'");
            if ($checkCol && $checkCol->rowCount() == 0) {
                $pdo->exec("ALTER TABLE letter_indicators ADD COLUMN letter_type VARCHAR(20) DEFAULT 'internal' AFTER department_prefix");
            }
        } catch (Throwable $e) {}

        $stmtInd = $pdo->prepare("SELECT id, last_sequence FROM letter_indicators WHERE fiscal_year_id = ? AND department_prefix = ? AND letter_type = ? FOR UPDATE");
        $stmtInd->execute([$fiscalYearId, $basePrefix, $type]);
        $indRow = $stmtInd->fetch(PDO::FETCH_ASSOC);

        if ($indRow) {
            $nextSeq = (int)$indRow['last_sequence'] + 1;
            $pdo->prepare("UPDATE letter_indicators SET last_sequence = ? WHERE id = ?")->execute([$nextSeq, $indRow['id']]);
        } else {
            $stmtMax = $pdo->prepare("SELECT indicator_number FROM letters WHERE fiscal_year_id = ? AND department_prefix = ? AND type = ? AND indicator_number IS NOT NULL");
            $stmtMax->execute([$fiscalYearId, $basePrefix, $type]);
            $existingNumbers = $stmtMax->fetchAll(PDO::FETCH_COLUMN);
            
            $maxSeq = 0;
            foreach ($existingNumbers as $num) {
                $parts = explode('-', $num);
                if (count($parts) >= 2) {
                    $seq = (int)$parts[1];
                    if ($seq > $maxSeq) $maxSeq = $seq;
                }
            }
            
            $nextSeq = $maxSeq + 1;
            $pdo->prepare("INSERT INTO letter_indicators (fiscal_year_id, department_prefix, letter_type, last_sequence) VALUES (?, ?, ?, ?)")->execute([$fiscalYearId, $basePrefix, $type, $nextSeq]);
        }

        $jy = function_exists('jdate') ? jdate('Y', '', '', '', 'en') : date('Y'); 
        $jm = function_exists('jdate') ? jdate('m', '', '', '', 'en') : date('m'); 
        $datePart = substr($jy, 1, 3) . $jm; 
        $seqPart = str_pad($nextSeq, 3, '0', STR_PAD_LEFT); 
        
        return "{$fullPrefix}-{$seqPart}-{$datePart}";
    }
}

$userId = (int)$_SESSION['user_id'];
$isAdmin = ($_SESSION['role'] === 'admin');

$letterId = (int)($_GET['id'] ?? 0);
if ($letterId <= 0) die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma;">شناسه نامه نامعتبر است.</div>');

$stmtLetter = $pdo->prepare("SELECT * FROM letters WHERE id = ?");
$stmtLetter->execute([$letterId]);
$letter = $stmtLetter->fetch(PDO::FETCH_ASSOC);

if (!$letter) die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma;">نامه یافت نشد.</div>');

$canEdit = false;
if ($isAdmin || $letter['created_by'] == $userId) {
    $canEdit = true;
} else {
    $stmtCheckRef = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND is_completed = 0");
    $stmtCheckRef->execute([$letterId, $userId]);
    $hasActiveRef = ($stmtCheckRef->fetchColumn() > 0);
    if ($hasActiveRef && empty($letter['indicator_number'])) {
        $canEdit = true;
    }
}

if (!$canEdit) {
    die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma; color:red; font-weight:bold;">⛔ شما دسترسی ویرایش این نامه را ندارید.</div>');
}

// اگر نامه شماره گرفته بود، در صورتی که صادره است اجازه ویرایش به ایجاد کننده بدهیم تا امضا لغو و به کارتابل برگردد
if (!empty($letter['indicator_number']) && !$isAdmin) {
    if (!($letter['type'] === 'outgoing' && $letter['created_by'] == $userId)) {
        die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma; background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5; margin:50px; border-radius:12px;"><h2>⛔ عدم دسترسی</h2><p>این نامه شماره اندیکاتور دریافت کرده است و فقط مدیریت مجاز به ویرایش آن می‌باشد.</p><br><a href="letters_pending.php" style="background:#b91c1c; color:#fff; padding:10px 20px; text-decoration:none; border-radius:8px; font-weight:bold;">بازگشت به کارتابل</a></div>');
    }
}

$msg = ''; $msgType = '';
$activeFiscalYear = getActiveFiscalYear();
$fiscalYearId = $activeFiscalYear ? $activeFiscalYear['id'] : 1; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");

    $type = $_POST['type'] ?? 'internal';
    $subject = trim($_POST['subject'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    $classification = $_POST['classification'] ?? 'normal';
    $departmentPrefix = $_POST['department_prefix'] ?? 'الف';
    $content = trim($_POST['content'] ?? '');
    $action = $_POST['submit_action'];
    $senderExternalName = trim($_POST['sender_external_name'] ?? '');

    $receivers = $_POST['receivers'] ?? []; 
    $signers = $_POST['signers'] ?? []; 
    $deleteAttachments = $_POST['delete_attachments'] ?? [];

    if (empty($subject)) {
        $msg = "وارد کردن موضوع الزامی است.";
        $msgType = "error";
    } elseif ($type === 'incoming' && empty($senderExternalName)) {
        $msg = "وارد کردن مبدا (فرستنده) برای نامه‌های وارده الزامی است.";
        $msgType = "error";
    } else {
        try {
            $pdo->beginTransaction();

            $status = 'draft';
            $indicatorNumber = $letter['indicator_number'];
            $registeredAt = $letter['registered_at'];

            if ($action === 'pending') {
                if (!empty($indicatorNumber)) {
                    // تغییر مهم: برگرداندن نامه صادره به کارتابل در دست اقدام
                    if ($type === 'outgoing') {
                        $status = 'pending_action';
                    } else {
                        $status = 'registered';
                    }
                } else {
                    if (in_array($type, ['incoming', 'internal'])) {
                        $status = 'registered';
                        $indicatorNumber = getNextIndicatorNumber($pdo, $fiscalYearId, $departmentPrefix, $type);
                        $registeredAt = date('Y-m-d H:i:s');
                    } else {
                        $status = 'pending_action';
                    }
                }
            }

            // بازنشانی وضعیت امضا و حذف ارجاعات امضا برای شروع مجدد چرخه
            $newSigStatus = 'none';
            if ($type === 'outgoing' && !empty($signers)) {
                $newSigStatus = 'pending';
                $pdo->prepare("DELETE FROM letter_referrals WHERE letter_id = ? AND action_type = 'for_signature'")->execute([$letterId]);
            }

            $sqlUpdate = "UPDATE letters SET type=?, subject=?, status=?, priority=?, classification=?, department_prefix=?, indicator_number=?, registered_at=?, content=?, sender_external_name=?, signature_status=? WHERE id=?";
            $pdo->prepare($sqlUpdate)->execute([$type, $subject, $status, $priority, $classification, $departmentPrefix, $indicatorNumber, $registeredAt, $content, $senderExternalName, $newSigStatus, $letterId]);

            $pdo->prepare("DELETE FROM letter_receivers WHERE letter_id = ?")->execute([$letterId]);
            if (!empty($receivers)) {
                $stmtRec = $pdo->prepare("INSERT INTO letter_receivers (letter_id, receiver_type, receiver_id) VALUES (?, ?, ?)");
                foreach ($receivers as $rec_id) {
                    $recType = ($type === 'outgoing') ? 'external' : 'user';
                    $stmtRec->execute([$letterId, $recType, $rec_id]);
                }
            }

            $pdo->prepare("DELETE FROM letter_signers WHERE letter_id = ?")->execute([$letterId]);
            if (!empty($signers) && $type === 'outgoing') {
                $stmtSign = $pdo->prepare("INSERT INTO letter_signers (letter_id, user_id, status) VALUES (?, ?, 'pending')");
                foreach ($signers as $sig_id) {
                    $stmtSign->execute([$letterId, $sig_id]);
                }
            }

            if ($action === 'pending' && in_array($type, ['incoming', 'internal']) && !empty($receivers)) {
                $stmtCheckDuplicate = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND is_completed = 0");
                $stmtRef = $pdo->prepare("INSERT INTO letter_referrals (letter_id, sender_id, receiver_id, action_type, description, is_completed, created_at) VALUES (?, ?, ?, 'for_action', 'ارجاع خودکار سیستمی پس از صدور شماره', 0, NOW())");
                foreach ($receivers as $rec_id) {
                    $stmtCheckDuplicate->execute([$letterId, $rec_id]);
                    if ($stmtCheckDuplicate->fetchColumn() == 0) {
                        $stmtRef->execute([$letterId, $userId, $rec_id]);
                        if (function_exists('send_user_notification')) {
                            send_user_notification($pdo, $rec_id, "📥 نامه جدید: {$indicatorNumber}", "نامه‌ای جهت بررسی و اقدام به کارتابل شما ارجاع شد.", "admin/letter_view.php?id={$letterId}", 'letter', $letterId);
                        }
                    }
                }
            }

            if (!empty($deleteAttachments)) {
                $stmtGetFiles = $pdo->prepare("SELECT file_path FROM letter_attachments WHERE id = ? AND letter_id = ?");
                $stmtDelFile = $pdo->prepare("DELETE FROM letter_attachments WHERE id = ?");
                foreach ($deleteAttachments as $attId) {
                    $stmtGetFiles->execute([$attId, $letterId]);
                    $filePath = $stmtGetFiles->fetchColumn();
                    if ($filePath) {
                        $fullPath = __DIR__ . '/../../public_html/uploads/letters/' . $filePath;
                        if (file_exists($fullPath)) @unlink($fullPath);
                        $stmtDelFile->execute([$attId]);
                    }
                }
            }

            if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                $uploadDir = __DIR__ . '/../../public_html/uploads/letters/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $allowedMimes = [
                    'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'image/jpeg', 'image/pjpeg', 'image/png', 'application/zip', 'application/x-zip-compressed',
                    'application/x-rar-compressed', 'application/vnd.rar', 'application/octet-stream' 
                ];

                $fileCount = count($_FILES['attachments']['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($_FILES['attachments']['error'][$i] === 0) {
                        $originalName = $_FILES['attachments']['name'][$i];
                        $tmpName = $_FILES['attachments']['tmp_name'][$i];
                        if (function_exists('upload_secure_file')) {
                            $uploadResult = upload_secure_file($tmpName, $originalName, $uploadDir, $allowedMimes, 'let_' . $letterId . '_');
                            if ($uploadResult) {
                                $pdo->prepare("INSERT INTO letter_attachments (letter_id, file_name, file_path, file_type, uploaded_by) VALUES (?, ?, ?, ?, ?)")
                                    ->execute([$letterId, $originalName, $uploadResult['name'], $uploadResult['ext'], $userId]);
                            }
                        }
                    }
                }
            }

            $pdo->commit();

            if ($action === 'draft') {
                $msg = "نامه با موفقیت بروزرسانی شد.";
                $msgType = "success";
                $stmtLetter->execute([$letterId]);
                $letter = $stmtLetter->fetch(PDO::FETCH_ASSOC);
            } elseif ($action === 'pending') {
                header("Location: letters_pending.php?msg=letter_created");
                exit;
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = "خطا در بروزرسانی: " . $e->getMessage();
            $msgType = "error";
        }
    }
}

$users = $pdo->query("SELECT id, first_name, last_name, role FROM users WHERE status='active' ORDER BY last_name")->fetchAll(PDO::FETCH_ASSOC);
$customers = $pdo->query("SELECT id, company_name, company_code FROM customers ORDER BY company_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$templates = $pdo->prepare("SELECT id, title, content FROM letter_templates WHERE created_by = ? ORDER BY title ASC");
$templates->execute([$userId]);
$templates = $templates->fetchAll(PDO::FETCH_ASSOC);

$currReceivers = [];
$currSigners = [];
try {
    $stmtRec = $pdo->prepare("SELECT lr.receiver_id, u.first_name, u.last_name, c.company_name FROM letter_receivers lr LEFT JOIN users u ON lr.receiver_type = 'user' AND lr.receiver_id = u.id LEFT JOIN customers c ON lr.receiver_type = 'external' AND lr.receiver_id = c.id WHERE lr.letter_id = ?");
    $stmtRec->execute([$letterId]);
    foreach($stmtRec->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = !empty($r['company_name']) ? $r['company_name'] : $r['first_name'].' '.$r['last_name'];
        $currReceivers[] = ['id' => $r['receiver_id'], 'name' => $name];
    }
    
    $stmtSig = $pdo->prepare("SELECT ls.user_id, u.first_name, u.last_name FROM letter_signers ls JOIN users u ON ls.user_id = u.id WHERE ls.letter_id = ?");
    $stmtSig->execute([$letterId]);
    foreach($stmtSig->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $currSigners[] = ['id' => $s['user_id'], 'name' => $s['first_name'].' '.$s['last_name']];
    }
} catch (Throwable $e) {}

$stmtAtt = $pdo->prepare("SELECT * FROM letter_attachments WHERE letter_id = ?");
$stmtAtt->execute([$letterId]);
$attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'ویرایش نامه';
$basePath = '../';
$extraCss = '
<link href="../assets/css/select2.min.css" rel="stylesheet" />
<style>
    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    
    .letter-form-card { background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e5e7eb; }
    .form-section-title { font-size: 1.05rem; font-weight: 800; color: #1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 15px; margin-top: 25px; display:flex; align-items:center; gap:8px;}
    .form-section-title:first-child { margin-top: 0; }
    
    .radio-group { display: flex; gap: 10px; flex-wrap: wrap; }
    .radio-card { flex: 1; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px 5px; text-align: center; cursor: pointer; font-weight: bold; color: #64748b; transition: all 0.2s ease; min-width: 100px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;}
    .radio-card i { font-size: 1.5rem; color: #94a3b8; transition: 0.2s; }
    .radio-card.active { border-color: #3b82f6; background: #eff6ff; color: #1d4ed8; box-shadow: 0 4px 10px rgba(59,130,246,0.15);}
    .radio-card.active i { color: #3b82f6; }
    .radio-card input { display: none; }
    
    .hidden-field { display: none !important; }
    .required-star { color: #ef4444; margin-right: 3px; }
    
    .form-row { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
    .form-col { flex: 1; min-width: 180px; }
    
    .select2-container--default .select2-selection--single { height: 40px; border: 1px solid #cbd5e1; border-radius: 8px; display: flex; align-items: center; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { color: #1f2937; padding-right: 15px; width: 100%; font-size: 0.9rem;}
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 38px; left: 10px; right: auto; }
    .select2-dropdown { border-color: #cbd5e1; border-radius: 8px; font-size: 0.9rem;}
    
    .input-btn-group { display: flex; align-items: stretch; gap: 10px; width: 100%; }
    .input-btn-group .select-wrapper { flex-grow: 1; min-width: 0; }
    
    .chip-container { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; min-height: 45px; padding: 10px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; }
    .chip { background: #e2e8f0; color: #0f172a; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; }
    .chip span { cursor: pointer; color: #ef4444; font-weight: bold; font-size: 1.1rem; line-height: 1; }
    
    .template-tools-wrapper { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 8px; gap: 10px; }
    .template-tools-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; flex-grow: 1; justify-content: flex-end; }
    .template-selector { width: 220px; font-size: 0.85rem; height: 36px; padding: 2px 10px; border-radius: 6px; border: 1px solid #cbd5e1; }
    
    .attachment-wrapper { background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px dashed #cbd5e1; margin-top: 15px; }
    .attachment-header { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
    .file-list { margin-top: 15px; display: flex; flex-direction: column; gap: 8px;}
    .file-item { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 10px 15px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem; flex-wrap: wrap; gap: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);}
    .file-actions { display: flex; gap: 8px; align-items: center; }
    .file-item button, .file-item a.preview-btn { border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.8rem; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 4px; }
    .file-item .delete-btn { background: #fee2e2; color: #b91c1c; }
    .file-item .delete-btn:hover { background: #fecaca; }
    .file-item .preview-btn { background: #e0f2fe; color: #0284c7; }
    .file-item .preview-btn:hover { background: #bae6fd; }
    
    .action-buttons { display: flex; justify-content: flex-start; gap: 10px; margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 20px; flex-wrap: wrap;}
    .btn-action-wrapper { padding: 10px 20px; font-weight: bold; border-radius: 8px; font-size: 0.95rem; }

    @media (max-width: 900px) {
        .form-col { min-width: 45%; }
        .template-tools-actions { justify-content: flex-start; width: 100%; }
        .template-selector { flex-grow: 1; width: auto; }
    }

    @media (max-width: 768px) {
        .content-wrapper { padding: 10px !important; overflow-x: hidden;}
        .letter-form-card { padding: 15px; border-radius: 8px; border: none; }
        .form-col { min-width: 100%; width: 100%; }
        .radio-card { padding: 10px 5px; flex-basis: 30%; }
        .radio-card i { font-size: 1.2rem; }
        
        .input-btn-group { flex-direction: column; }
        .input-btn-group .btn { width: 100%; margin-top: 5px; display: flex; justify-content: center; align-items: center; }
        
        .template-tools-wrapper { flex-direction: column; align-items: flex-start; }
        .template-tools-actions { width: 100%; flex-direction: column; align-items: stretch; gap: 8px; }
        .template-selector { width: 100%; }
        .template-tools-actions .btn { width: 100%; display: flex; justify-content: center; align-items: center; text-align: center; }
        
        .attachment-header { flex-direction: column; align-items: stretch; text-align: center; gap: 10px; }
        .attachment-header .btn { width: 100%; display: flex; justify-content: center; align-items: center; }
        
        .file-item { flex-direction: column; align-items: flex-start; }
        .file-actions { width: 100%; }
        .file-actions button, .file-actions a.preview-btn { flex: 1; }
        
        .action-buttons { flex-direction: column; width: 100%; }
        .btn-action-wrapper { width: 100%; text-align: center; display: flex; justify-content: center; align-items: center; margin-bottom: 5px; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div><span class="page-title fw-bold" style="font-size:1.2rem;">✏️ ویرایش نامه</span></div>
            <div><button onclick="history.back()" class="btn btn-secondary btn-sm px-3 fw-bold">بازگشت</button></div>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msgType; ?> fw-bold" style="border-radius: 8px;"><?php echo $msg; ?></div>
        <?php endif; ?>

        <?php if(!empty($letter['indicator_number'])): ?>
            <div class="alert alert-warning fw-bold" style="font-size:0.95rem; border-radius: 8px;">
                ⚠️ توجه: این نامه صادر شده و دارای شماره <strong>(<?php echo $letter['indicator_number']; ?>)</strong> است. با ویرایش مجدد، امضاها باطل شده و نامه دوباره در گردش قرار می‌گیرد.
            </div>
        <?php endif; ?>

        <div class="letter-form-card">
            <form method="POST" enctype="multipart/form-data" id="letterForm">
                <?php echo csrf_field(); ?>
                
                <div class="form-section-title"><i class="fas fa-info-circle text-primary"></i> اطلاعات پایه نامه</div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label fw-bold">نوع نامه <span class="required-star">*</span></label>
                    <div class="radio-group">
                        <label class="radio-card <?php echo $letter['type'] == 'internal' ? 'active' : ''; ?>" id="lbl-internal">
                            <input type="radio" name="type" value="internal" <?php echo $letter['type'] == 'internal' ? 'checked' : ''; ?> onchange="toggleFields()"> 
                            <i class="fas fa-building"></i><span>داخلی</span>
                        </label>
                        <label class="radio-card <?php echo $letter['type'] == 'outgoing' ? 'active' : ''; ?>" id="lbl-outgoing">
                            <input type="radio" name="type" value="outgoing" <?php echo $letter['type'] == 'outgoing' ? 'checked' : ''; ?> onchange="toggleFields()"> 
                            <i class="fas fa-paper-plane"></i><span>صادره</span>
                        </label>
                        <label class="radio-card <?php echo $letter['type'] == 'incoming' ? 'active' : ''; ?>" id="lbl-incoming">
                            <input type="radio" name="type" value="incoming" <?php echo $letter['type'] == 'incoming' ? 'checked' : ''; ?> onchange="toggleFields()"> 
                            <i class="fas fa-inbox"></i><span>وارده</span>
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col" style="flex: 2;">
                        <label class="form-label fw-bold">موضوع نامه <span class="required-star">*</span></label>
                        <input type="text" name="subject" class="form-control" required value="<?php echo htmlspecialchars($letter['subject']); ?>">
                    </div>
                    <div class="form-col">
                        <label class="form-label fw-bold">فوریت</label>
                        <select name="priority" class="form-control">
                            <option value="normal" <?php echo $letter['priority'] == 'normal' ? 'selected' : ''; ?>>عادی</option>
                            <option value="high" <?php echo $letter['priority'] == 'high' ? 'selected' : ''; ?>>فوری</option>
                            <option value="immediate" <?php echo $letter['priority'] == 'immediate' ? 'selected' : ''; ?>>آنی</option>
                        </select>
                    </div>
                    <div class="form-col">
                        <label class="form-label fw-bold">طبقه‌بندی</label>
                        <select name="classification" class="form-control">
                            <option value="normal" <?php echo $letter['classification'] == 'normal' ? 'selected' : ''; ?>>عادی</option>
                            <option value="confidential" <?php echo $letter['classification'] == 'confidential' ? 'selected' : ''; ?>>محرمانه</option>
                            <option value="secret" <?php echo $letter['classification'] == 'secret' ? 'selected' : ''; ?>>سری</option>
                        </select>
                    </div>
                    <div class="form-col">
                        <label class="form-label fw-bold">پیشوند دپارتمان <span class="required-star">*</span></label>
                        <select name="department_prefix" class="form-control">
                            <option value="الف" <?php echo ($letter['department_prefix'] ?? '') == 'الف' ? 'selected' : ''; ?>>الف (اداری)</option>
                            <option value="م" <?php echo ($letter['department_prefix'] ?? '') == 'م' ? 'selected' : ''; ?>>م (مالی)</option>
                            <option value="ب" <?php echo ($letter['department_prefix'] ?? '') == 'ب' ? 'selected' : ''; ?>>ب (بازرگانی)</option>
                            <option value="ف" <?php echo ($letter['department_prefix'] ?? '') == 'ف' ? 'selected' : ''; ?>>ف (فروش)</option>
                            <option value="م‌ع" <?php echo ($letter['department_prefix'] ?? '') == 'م‌ع' ? 'selected' : ''; ?>>م‌ع (مدیرعامل)</option>
                        </select>
                    </div>
                </div>

                <div class="form-section-title"><i class="fas fa-users text-primary"></i> تعیین فرستنده و گیرنده</div>
                
                <div class="form-row">
                    <div class="form-col hidden-field" id="box-sender-ext">
                        <label class="form-label fw-bold text-danger">فرستنده (مبدا نامه وارده) <span class="required-star">*</span></label>
                        <input type="text" name="sender_external_name" id="sender_external_name" class="form-control border-danger" placeholder="مثال: شرکت آتنازیست درمان..." value="<?php echo htmlspecialchars($letter['sender_external_name'] ?? ''); ?>">
                    </div>

                    <div class="form-col" id="box-receiver-int">
                        <label class="form-label fw-bold" id="lbl-receiver-int-text">گیرندگان (پرسنل سازمان) <span class="required-star">*</span></label>
                        <div class="input-btn-group">
                            <div class="select-wrapper">
                                <select id="int-receiver-select" class="form-control searchable-select">
                                    <option value="">-- پرسنل را جستجو و انتخاب کنید --</option>
                                    <?php foreach($users as $u): ?>
                                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="button" class="btn btn-primary px-4 fw-bold" onclick="addEntity('int-receiver-select', 'int-receivers-container', 'receivers[]')">افزودن</button>
                        </div>
                        <div id="int-receivers-container" class="chip-container">
                            <?php 
                            $hasInt = false;
                            if (in_array($letter['type'], ['internal', 'incoming'])) {
                                foreach($currReceivers as $r) {
                                    $hasInt = true;
                                    echo '<div class="chip">'.htmlspecialchars($r['name']).' <span onclick="removeEntity(this, \'int-receivers-container\')">&times;</span><input type="hidden" name="receivers[]" value="'.$r['id'].'"></div>';
                                }
                            }
                            if(!$hasInt) echo '<div class="text-muted small w-100 text-center" id="int-empty-text" style="line-height:25px;">لیست گیرندگان خالی است.</div>';
                            ?>
                        </div>
                    </div>

                    <div class="form-col hidden-field" id="box-receiver-ext">
                        <label class="form-label fw-bold">گیرندگان (مشتریان) <span class="required-star">*</span></label>
                        <div class="input-btn-group">
                            <div class="select-wrapper">
                                <select id="ext-receiver-select" class="form-control searchable-select">
                                    <option value="">-- مشتری را جستجو و انتخاب کنید --</option>
                                    <?php foreach($customers as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['company_name'] . ' (' . $c['company_code'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="button" class="btn btn-primary px-4 fw-bold" onclick="addEntity('ext-receiver-select', 'ext-receivers-container', 'receivers[]')">افزودن</button>
                        </div>
                        <div id="ext-receivers-container" class="chip-container">
                            <?php 
                            $hasExt = false;
                            if ($letter['type'] === 'outgoing') {
                                foreach($currReceivers as $r) {
                                    $hasExt = true;
                                    echo '<div class="chip">'.htmlspecialchars($r['name']).' <span onclick="removeEntity(this, \'ext-receivers-container\')">&times;</span><input type="hidden" name="receivers[]" value="'.$r['id'].'"></div>';
                                }
                            }
                            if(!$hasExt) echo '<div class="text-muted small w-100 text-center" id="ext-empty-text" style="line-height:25px;">لیست مشتریان خالی است.</div>';
                            ?>
                        </div>
                    </div>

                    <div class="form-col hidden-field" id="box-signers">
                        <label class="form-label fw-bold">امضاکنندگان نامه</label>
                        <div class="input-btn-group">
                            <div class="select-wrapper">
                                <select id="signer-select" class="form-control searchable-select">
                                    <option value="">-- شخص امضاکننده را جستجو کنید --</option>
                                    <?php foreach($users as $u): ?>
                                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name'].' ('.$u['role'].')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="button" class="btn btn-primary px-4 fw-bold" onclick="addEntity('signer-select', 'signers-container', 'signers[]')">افزودن</button>
                        </div>
                        <div id="signers-container" class="chip-container">
                            <?php 
                            $hasSig = false;
                            if ($letter['type'] === 'outgoing') {
                                foreach($currSigners as $s) {
                                    $hasSig = true;
                                    echo '<div class="chip">'.htmlspecialchars($s['name']).' <span onclick="removeEntity(this, \'signers-container\')">&times;</span><input type="hidden" name="signers[]" value="'.$s['id'].'"></div>';
                                }
                            }
                            if(!$hasSig) echo '<div class="text-muted small w-100 text-center" id="signer-empty-text" style="line-height:25px;">امضاکننده‌ای انتخاب نشده.</div>';
                            ?>
                        </div>
                    </div>
                </div>

                <div class="form-section-title"><i class="fas fa-align-right text-primary"></i> متن نامه و پیوست‌ها</div>
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <div class="template-tools-wrapper">
                        <label class="form-label fw-bold m-0">ویرایشگر متن</label>
                        <div class="template-tools-actions">
                            <select id="templateSelector" class="form-control template-selector">
                                <option value="">-- انتخاب قالب آماده --</option>
                                <?php foreach($templates as $tpl): ?>
                                    <option value="<?php echo $tpl['id']; ?>"><?php echo htmlspecialchars($tpl['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-primary btn-sm fw-bold" onclick="insertTemplate()">➕ درج قالب</button>
                            <a href="letter_templates.php" target="_blank" class="btn btn-outline-secondary btn-sm fw-bold">⚙️ مدیریت قالب‌ها</a>
                        </div>
                    </div>
                    <textarea id="contentEditor" name="content"><?php echo $letter['content'] ?? ''; ?></textarea>
                </div>

                <?php if(count($attachments) > 0): ?>
                <div class="attachment-wrapper" style="margin-bottom: 15px;">
                    <label class="form-label fw-bold">فایل‌های پیوست فعلی:</label>
                    <div class="file-list">
                        <?php foreach($attachments as $att): ?>
                            <div class="file-item" id="old-att-<?php echo $att['id']; ?>">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <i class="fas fa-file-alt text-secondary"></i> 
                                    <span style="word-break: break-all; font-weight:bold; color:#334155;"><?php echo htmlspecialchars($att['file_name']); ?></span>
                                </div>
                                <div class="file-actions">
                                    <a href="../uploads/letters/<?php echo htmlspecialchars($att['file_path']); ?>" target="_blank" class="preview-btn">👁️ مشاهده</a>
                                    <button type="button" class="delete-btn" onclick="markAttachmentForDeletion(<?php echo $att['id']; ?>)">❌ حذف</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="deleted-attachments-container"></div>
                </div>
                <?php endif; ?>

                <div class="attachment-wrapper">
                    <div class="attachment-header">
                        <label class="form-label fw-bold m-0"><i class="fas fa-paperclip"></i> افزودن پیوست‌های جدید (اختیاری)</label>
                        <button type="button" class="btn btn-secondary fw-bold px-4 py-2" onclick="document.getElementById('tempFileInput').click()">📎 انتخاب فایل‌ها</button>
                    </div>
                    <input type="file" id="tempFileInput" style="display:none;" multiple accept=".pdf,.doc,.docx,.jpg,.png,.zip,.rar" onchange="attachFiles()">
                    <div class="file-list mt-3" id="fileListUI"></div>
                    <input type="file" name="attachments[]" id="realFileInput" multiple style="display:none;">
                </div>

                <div class="action-buttons">
                    <button type="submit" name="submit_action" value="draft" class="btn btn-outline-secondary btn-action-wrapper" onclick="return validateForm()">💾 بروزرسانی نامه</button>
                    <button type="submit" name="submit_action" value="pending" class="btn btn-success btn-action-wrapper" onclick="return validateForm()">✅ ثبت در سیستم</button>
                </div>

            </form>
        </div>
    </div>
</main>
<script src="../assets/js/jquery-3.6.0.min.js"></script>
<script src="../assets/js/select2.min.js"></script>
<script src="../assets/js/tinymce/tinymce.min.js"></script>

<script>
    tinymce.init({
        selector: '#contentEditor',
        license_key: 'gpl',
        directionality: 'rtl',
        language: 'fa', 
        plugins: 'advlist autolink lists link image charmap preview table code wordcount fullscreen pagebreak searchreplace visualblocks nonbreaking insertdatetime directionality',
        toolbar1: 'undo redo | fontfamily fontsize lineheight | forecolor backcolor | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify',
        toolbar2: 'bullist numlist outdent indent | table pagebreak | subscript superscript | ltr rtl | charmap insertdatetime | fullscreen preview code',
        font_family_formats: 'وزیرمتن=Vazirmatn,sans-serif; بی تیتر=BTitrBold,Tahoma; بی نازنین=BNazanin,Tahoma; بی یکان=BYekan,Tahoma; بی رویا=BRoya,Tahoma; ساحل=IRANSansWeb,Tahoma; تاهوما=Tahoma,sans-serif; آریال=Arial,sans-serif',
        fontsize_formats: '8pt 10pt 11pt 12pt 14pt 16pt 18pt 20pt 24pt 36pt',
        line_height_formats: '1 1.15 1.2 1.5 1.8 2.0 2.5 3.0',
        height: 500,
        menubar: 'file edit view insert format tools table help',
        branding: false,
        content_style: `
            @font-face { font-family: 'Vazirmatn'; src: url('../assets/fonts/Vazirmatn-Regular.ttf') format('truetype'); font-weight: normal; }
            @font-face { font-family: 'BTitrBold'; src: url('../assets/fonts/IRTitr.ttf') format('truetype'); font-weight: bold; }
            @font-face { font-family: 'BNazanin'; src: url('../assets/fonts/IRNazanin.ttf') format('truetype'); font-weight: normal; }
            @font-face { font-family: 'BYekan'; src: url('../assets/fonts/IRYekan.ttf') format('truetype'); font-weight: normal; }
            @font-face { font-family: 'BRoya'; src: url('../assets/fonts/IRRoya.ttf') format('truetype'); font-weight: normal; }
            @font-face { font-family: 'IRANSansWeb'; src: url('../assets/fonts/SAHEL-FD.ttf') format('truetype'); font-weight: normal; }
            body { font-family: 'Vazirmatn', Tahoma, sans-serif; font-size: 12pt; direction: rtl; text-align: right; line-height: 1.8; padding: 15px; color: #0f172a; }
            p { margin: 0 0 8px 0; }
            table { border-collapse: collapse; width: 100%; border: 1px dashed #94a3b8; } 
            table td, table th { border: 1px dashed #94a3b8; padding: 8px; }
        `
    });

    const templatesData = <?php echo json_encode($templates); ?>;
    function insertTemplate() {
        const tplId = document.getElementById('templateSelector').value;
        if(!tplId) return;
        const tpl = templatesData.find(t => String(t.id) === String(tplId));
        if(tpl && typeof tinymce !== 'undefined') {
            tinymce.get('contentEditor').insertContent(tpl.content + '<br>');
        }
    }

    $(document).ready(function() {
        $('.searchable-select').select2({
            dir: "rtl", width: '100%',
            language: { noResults: function () { return "موردی یافت نشد"; } }
        });
    });

    function toggleFields() {
        const type = document.querySelector('input[name="type"]:checked').value;
        const boxInt = document.getElementById('box-receiver-int');
        const boxExt = document.getElementById('box-receiver-ext');
        const boxSign = document.getElementById('box-signers');
        const boxSenderExt = document.getElementById('box-sender-ext');
        const lblRecInt = document.getElementById('lbl-receiver-int-text');
        
        document.querySelectorAll('.radio-card').forEach(el => el.classList.remove('active'));
        document.getElementById('lbl-' + type).classList.add('active');

        boxInt.classList.add('hidden-field');
        boxExt.classList.add('hidden-field');
        boxSign.classList.add('hidden-field');
        boxSenderExt.classList.add('hidden-field');

        if (type === 'internal') {
            boxInt.classList.remove('hidden-field');
            lblRecInt.innerHTML = 'گیرندگان (پرسنل سازمان) <span class="required-star">*</span>';
        } else if (type === 'outgoing') {
            boxExt.classList.remove('hidden-field');
            boxSign.classList.remove('hidden-field');
        } else if (type === 'incoming') {
            boxInt.classList.remove('hidden-field');
            boxSenderExt.classList.remove('hidden-field');
            lblRecInt.innerHTML = 'گیرندگان / ارجاع شونده (پرسنل سازمان) <span class="required-star">*</span>';
        }
    }
    toggleFields();

    function addEntity(selectId, containerId, inputName) {
        const select = document.getElementById(selectId);
        const container = document.getElementById(containerId);
        const emptyText = container.querySelector('.text-muted');
        
        if (containerId === 'signers-container' && container.querySelectorAll('.chip').length >= 2) {
            alert("⛔ حداکثر ۲ نفر می‌توانند به عنوان امضاکننده انتخاب شوند.");
            return;
        }

        const option = select.options[select.selectedIndex];
        if (!option || !option.value) return;

        const existingInputs = container.querySelectorAll(`input[value="${option.value}"]`);
        if (existingInputs.length > 0) {
            alert("این مورد قبلاً اضافه شده است.");
            return;
        }

        if (emptyText) emptyText.style.display = 'none';

        const chip = document.createElement('div');
        chip.className = 'chip';
        chip.innerHTML = `${option.text} <span onclick="removeEntity(this, '${containerId}')">&times;</span><input type="hidden" name="${inputName}" value="${option.value}">`;
        container.appendChild(chip);
        
        $(`#${selectId}`).val(null).trigger('change');
    }

    function removeEntity(element, containerId) {
        element.parentElement.remove();
        const container = document.getElementById(containerId);
        if (container.querySelectorAll('.chip').length === 0) {
            const emptyText = container.querySelector('.text-muted');
            if(emptyText) emptyText.style.display = 'block';
        }
    }

    function markAttachmentForDeletion(attId) {
        if(confirm('آیا از حذف این فایل پیوست اطمینان دارید؟')) {
            document.getElementById('old-att-' + attId).style.display = 'none';
            const container = document.getElementById('deleted-attachments-container');
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'delete_attachments[]';
            input.value = attId;
            container.appendChild(input);
        }
    }

    const dataTransfer = new DataTransfer(); 

    function attachFiles() {
        const tempInput = document.getElementById('tempFileInput');
        const files = tempInput.files;
        if(files.length === 0) return;

        for (let i = 0; i < files.length; i++) {
            dataTransfer.items.add(files[i]);
        }
        tempInput.value = ''; 
        updateFileListUI();
    }

    function removeFile(index) {
        dataTransfer.items.remove(index);
        updateFileListUI();
    }

    function updateFileListUI() {
        const fileListUI = document.getElementById('fileListUI');
        const realInput = document.getElementById('realFileInput');
        realInput.files = dataTransfer.files;
        
        fileListUI.innerHTML = '';
        const files = dataTransfer.files;
        
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const sizeKB = (file.size / 1024).toFixed(1) + ' KB';
            
            const fileURL = URL.createObjectURL(file);
            let previewBtn = '';
            
            if (file.type.match('image.*') || file.type === 'application/pdf') {
                previewBtn = `<button type="button" class="preview-btn" onclick="window.open('${fileURL}', '_blank')">👁️ مشاهده</button>`;
            } else {
                previewBtn = `<span class="text-muted small" style="margin-left:10px; display:inline-flex; align-items:center;">(فایل دانلودی)</span>`;
            }
            
            const div = document.createElement('div');
            div.className = 'file-item';
            div.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-file-alt text-secondary"></i> 
                    <span style="word-break: break-all; font-weight:bold; color:#334155;">${file.name}</span> 
                    <span class="text-muted ms-2">(${sizeKB})</span>
                </div>
                <div class="file-actions">
                    ${previewBtn}
                    <button type="button" class="delete-btn" onclick="removeFile(${i})">❌ حذف</button>
                </div>
            `;
            fileListUI.appendChild(div);
        }
    }

    function validateForm() {
        if(typeof tinymce !== 'undefined') tinymce.triggerSave();
        const type = document.querySelector('input[name="type"]:checked').value;
        
        if (type === 'incoming') {
            const senderExt = document.getElementById('sender_external_name').value.trim();
            if (senderExt === '') {
                alert("لطفاً فرستنده (مبدا) نامه وارده را وارد کنید.");
                document.getElementById('sender_external_name').focus();
                return false;
            }
        }

        const containerId = (type === 'outgoing') ? 'ext-receivers-container' : 'int-receivers-container';
        const receiverCount = document.getElementById(containerId).querySelectorAll('.chip').length;
        if (receiverCount === 0) {
            alert("لطفاً حداقل یک گیرنده را انتخاب و دکمه افزودن را بزنید.");
            return false;
        }
        return true;
    }
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>