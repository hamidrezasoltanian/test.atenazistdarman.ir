<?php
/*
 * فایل: public_html/admin/letters_trash.php
 * توضیحات: مدیریت زباله‌دان نامه‌ها (استایل استاندارد، رسپانسیو ۱۰۰٪، اسکرول تبلت و حذف فیزیکی)
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// بررسی لاگین
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$isAdmin = (in_array($_SESSION['role'] ?? '', ['admin', 'manager']));

// فقط ادمین و مدیران به زباله‌دان دسترسی دارند
if (!$isAdmin) {
    die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma, sans-serif; color:red; font-weight:bold;">⛔ شما مجوز دسترسی به زباله‌دان را ندارید.</div>');
}

$msg = ''; 
$msgType = '';

// پردازش عملیات (بازیابی یا حذف دائمی)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");
    
    $action = $_POST['action_type'] ?? '';
    $letterId = (int)$_POST['letter_id'];

    try {
        $pdo->beginTransaction();

        if ($action === 'restore') {
            // بازیابی نامه
            $pdo->prepare("UPDATE letters SET is_deleted = 0 WHERE id = ?")->execute([$letterId]);
            if(function_exists('logSystem')) logSystem('Letters', 'restore_trash', $letterId, "بازیابی نامه از زباله‌دان");
            
            $msg = "✅ نامه با موفقیت بازیابی شد و به سیستم بازگشت.";
            $msgType = "success";
        } 
        elseif ($action === 'hard_delete') {
            // حذف دائمی فقط برای ادمین کل مجاز است
            if ($_SESSION['role'] !== 'admin') {
                throw new Exception("فقط ادمین کل می‌تواند نامه‌ها را برای همیشه حذف کند.");
            }
            
            // 1. پیدا کردن و پاک کردن فایل‌های فیزیکی پیوست از روی هاست
            $stmtAtt = $pdo->prepare("SELECT file_path FROM letter_attachments WHERE letter_id = ?");
            $stmtAtt->execute([$letterId]);
            $attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);
            foreach($attachments as $att) {
                $filePath = __DIR__ . '/../../public_html/uploads/letters/' . $att['file_path'];
                if (file_exists($filePath)) {
                    @unlink($filePath); // حذف فایل از سرور
                }
            }

            // 2. پاک کردن رکوردهای وابسته دیتابیس
            $pdo->prepare("DELETE FROM letter_attachments WHERE letter_id = ?")->execute([$letterId]);
            $pdo->prepare("DELETE FROM letter_receivers WHERE letter_id = ?")->execute([$letterId]);
            $pdo->prepare("DELETE FROM letter_signers WHERE letter_id = ?")->execute([$letterId]);
            $pdo->prepare("DELETE FROM letter_referrals WHERE letter_id = ?")->execute([$letterId]);
            
            // 3. پاک کردن خود نامه
            $pdo->prepare("DELETE FROM letters WHERE id = ?")->execute([$letterId]);
            
            if(function_exists('logSystem')) logSystem('Letters', 'hard_delete', $letterId, "حذف فیزیکی و دائمی نامه و متعلقات");
            
            $msg = "🗑️ نامه و تمامی پیوست‌های آن برای همیشه پاک شد.";
            $msgType = "success";
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "خطا: " . $e->getMessage();
        $msgType = "error";
    }
}

// واکشی نامه‌های حذف شده
$sql = "SELECT l.*, u.first_name, u.last_name 
        FROM letters l 
        LEFT JOIN users u ON l.created_by = u.id 
        WHERE l.is_deleted = 1 
        ORDER BY l.updated_at DESC, l.created_at DESC";

$trashedLetters = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = '🗑️ زباله‌دان نامه‌ها';
$basePath = '../';

// استایل‌های یکپارچه و استاندارد با فونت وزیرمتن
$extraCss = '<style>
    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    
    .table-container { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px; background: #fff; box-shadow: 0 4px 15px rgba(0,0,0,0.02);}
    .l-table { width: 100%; border-collapse: collapse; background: #fff; min-width: 950px; table-layout: auto;}
    .l-table th, .l-table td { padding: 14px 12px; text-align: right; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .l-table th { background: #f8fafc; font-weight: 800; color: #475569; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .l-table tr:hover { background: #f8fafc; }
    
    /* تنظیم عرض ستون‌ها در دسکتاپ */
    .col-creator { width: 15%; }
    .col-ind { width: 15%; }
    .col-subj { width: 30%; }
    .col-type { width: 8%; }
    .col-date { width: 10%; }
    .col-status { width: 7%; }
    .col-actions { width: 15%; text-align: center; }
    
    .status-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; display: inline-block; }
    .st-deleted { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    
    .btn-group-row { display: flex; align-items: center; justify-content: center; gap: 5px; flex-wrap: nowrap; }
    .btn-group-row form { margin: 0; display: flex; align-items: center; }
    .action-btn { padding: 6px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 4px; border: none; cursor: pointer; text-decoration: none; white-space: nowrap; }
    .btn-restore { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .btn-delete { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
    .action-btn:hover { opacity: 0.85; transform: translateY(-1px); }

    @media (min-width: 769px) {
        .nowrap-desktop { white-space: nowrap !important; }
        .wrap-subject { white-space: normal !important; word-wrap: break-word !important; line-height: 1.8; }
    }

    @media (max-width: 992px) {
        /* در تبلت جدول اسکرول افقی بخورد */
        .table-container { border: 1px solid #cbd5e1; }
    }

    @media (max-width: 768px) {
        .content-wrapper { padding: 10px !important; overflow-x: hidden; }
        
        .table-container { overflow: visible; border: none; background: transparent; box-shadow: none;}
        .l-table, .l-table tbody, .l-table tr, .l-table td { display: block; width: 100%; min-width: 100%; box-sizing: border-box;}
        .l-table thead { display: none; }
        .l-table tr { margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
        
        /* چیدمان اصولی موبایل: لیبل بالا، محتوا پایین */
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
            <div><span class="page-title fw-bold" style="font-size:1.2rem;">🗑️ زباله‌دان نامه‌ها</span></div>
            <div><a href="cartable_inbox.php" class="btn btn-secondary btn-sm px-3 fw-bold">بازگشت به کارتابل</a></div>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msgType; ?> fw-bold" style="border-radius: 8px;"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="table-container">
            <table class="l-table">
                <thead>
                    <tr>
                        <th class="col-creator nowrap-desktop">ایجاد کننده</th>
                        <th class="col-ind nowrap-desktop">شماره اندیکاتور</th>
                        <th class="col-subj text-right">موضوع نامه</th>
                        <th class="col-type nowrap-desktop">نوع نامه</th>
                        <th class="col-date nowrap-desktop">تاریخ حذف</th>
                        <th class="col-status nowrap-desktop">وضعیت</th>
                        <th class="col-actions nowrap-desktop text-center">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($trashedLetters as $l): ?>
                    <tr>
                        <td class="nowrap-desktop" data-label="ایجاد کننده:"><?php echo htmlspecialchars($l['first_name'].' '.$l['last_name']); ?></td>
                        <td class="nowrap-desktop" data-label="شماره:"><strong dir="ltr"><?php echo $l['indicator_number'] ?: '---'; ?></strong></td>
                        <td class="wrap-subject fw-bold" data-label="موضوع:" style="color:#475569; text-decoration: line-through;"><?php echo htmlspecialchars($l['subject']); ?></td>
                        <td class="nowrap-desktop" data-label="نوع:">
                            <?php 
                                $types = ['internal'=>'داخلی', 'outgoing'=>'صادره', 'incoming'=>'وارده'];
                                echo $types[$l['type']] ?? 'نامشخص';
                            ?>
                        </td>
                        <td class="nowrap-desktop" data-label="تاریخ حذف:">
                            <span dir="ltr" class="fw-bold" style="font-size:0.9rem; color:#0f172a;">
                                <?php 
                                    $ts = strtotime($l['updated_at'] ?: $l['created_at']);
                                    echo date('H:i', $ts) . ' - ' . (function_exists('jdate') ? jdate('Y/m/d', $ts) : date('Y/m/d', $ts));
                                ?>
                            </span>
                        </td>
                        <td class="nowrap-desktop" data-label="وضعیت:"><span class="status-badge st-deleted">حذف شده</span></td>
                        <td class="nowrap-desktop" data-label="عملیات:" style="text-align:center;">
                            <div class="btn-group-row">
                                <form method="POST" onsubmit="return confirm('آیا از بازیابی این نامه اطمینان دارید؟ نامه مجدداً در سیستم فعال خواهد شد.');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action_type" value="restore">
                                    <input type="hidden" name="letter_id" value="<?php echo $l['id']; ?>">
                                    <button type="submit" class="action-btn btn-restore" title="بازگردانی نامه">🔄 بازیابی</button>
                                </form>

                                <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                    <form method="POST" onsubmit="return confirm('⚠️ هشدار جدی: آیا از حذف دائمی این نامه اطمینان دارید؟\nتمام پیوست‌ها و رکوردهای مربوط به این نامه برای همیشه از سرور پاک خواهند شد و قابل بازگشت نیستند!');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action_type" value="hard_delete">
                                        <input type="hidden" name="letter_id" value="<?php echo $l['id']; ?>">
                                        <button type="submit" class="action-btn btn-delete" title="حذف دائمی از دیتابیس">❌ حذف فیزیکی</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; if(empty($trashedLetters)) echo '<tr><td colspan="7" class="text-center py-5 fw-bold" style="color:#94a3b8;">موردی در زباله‌دان یافت نشد.</td></tr>'; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    setTimeout(() => {
        const alertBox = document.querySelector('.alert');
        if (alertBox) alertBox.style.display = 'none';
    }, 6000);
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>