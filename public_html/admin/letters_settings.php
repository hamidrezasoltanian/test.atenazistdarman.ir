<?php
/*
 * فایل: public_html/admin/letters_settings.php
 * توضیحات: تنظیمات امضای اختصاصی برای هر مدیر + تنظیمات سراسری (سربرگ و اندیکاتور)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$role = $_SESSION['role'] ?? '';
$isAdmin = ($role === 'admin');

// اعمال سطح دسترسی داینامیک
$hasAccess = $isAdmin || (function_exists('hasPermission') && hasPermission('letters_settings')) || in_array($role, ['manager', 'management']);
if (!$hasAccess) {
    die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma; color:red; font-weight:bold;">⛔ شما مجوز دسترسی به بخش تنظیمات مکاتبات را ندارید.</div>');
}

$userId = (int)$_SESSION['user_id'];
$msg = '';
$msgType = '';
$activeFiscalYear = getActiveFiscalYear();
$fiscalYearId = $activeFiscalYear ? $activeFiscalYear['id'] : null;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `setting_key` varchar(100) NOT NULL,
      `setting_value` text DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(Throwable $e) {}

// دریافت تنظیمات اختصاصی کاربر جاری + سربرگ عمومی
$sigSettings = [];
try {
    $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('signature_pin_$userId', 'signature_img_$userId', 'stamp_img_$userId', 'signer_name_$userId', 'signer_title_$userId', 'letterhead_img')");
    while($row = $stmtSettings->fetch()) {
        $sigSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Throwable $e) {}

$currentPinHash = $sigSettings["signature_pin_$userId"] ?? '';

// --- ذخیره تنظیمات امضا و سربرگ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_signature_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");
    
    try {
        $oldPin = trim($_POST['old_pin'] ?? '');
        $newPin = trim($_POST['new_pin'] ?? '');
        
        // استفاده از تابع یکپارچه faToEn برای تبدیل اعداد
        $oldPin = faToEn($oldPin);
        $newPin = faToEn($newPin);

        $signerName = trim($_POST['signer_name']);
        $signerTitle = trim($_POST['signer_title']);

        $stmtSave = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        
        // ذخیره پین کد
        if ($newPin !== '') {
            if ($currentPinHash !== '') {
                // استفاده از تابع امنیتی برای اعتبارسنجی
                $isValid = verify_user_pin($pdo, $userId, $oldPin);
                if (!$isValid) throw new Exception("پین‌کد فعلی اشتباه است! نمی‌توانید رمز را تغییر دهید.");
            }
            $hashedPin = password_hash($newPin, PASSWORD_DEFAULT);
            $stmtSave->execute(["signature_pin_$userId", $hashedPin, $hashedPin]);
        }

        if($signerName !== '') $stmtSave->execute(["signer_name_$userId", $signerName, $signerName]);
        if($signerTitle !== '') $stmtSave->execute(["signer_title_$userId", $signerTitle, $signerTitle]);

        $uploadDir = __DIR__ . '/../../public_html/uploads/settings/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        // Mime Type های مجاز برای تصاویر
        $allowedImageMimes = ['image/png', 'image/jpeg', 'image/pjpeg'];

        // آپلود عکس امضای اختصاصی
        if (isset($_FILES['signature_img']) && $_FILES['signature_img']['error'] === 0) {
            $uploadResult = upload_secure_file($_FILES['signature_img']['tmp_name'], $_FILES['signature_img']['name'], $uploadDir, $allowedImageMimes, 'sign_u'.$userId.'_');
            if ($uploadResult) {
                $stmtSave->execute(["signature_img_$userId", $uploadResult['name'], $uploadResult['name']]);
            } else {
                throw new Exception("فایل امضا نامعتبر است. لطفاً فقط تصویر معتبر (PNG یا JPG) آپلود کنید.");
            }
        }
        
        // آپلود عکس مهر اختصاصی
        if (isset($_FILES['stamp_img']) && $_FILES['stamp_img']['error'] === 0) {
            $uploadResult = upload_secure_file($_FILES['stamp_img']['tmp_name'], $_FILES['stamp_img']['name'], $uploadDir, $allowedImageMimes, 'stamp_u'.$userId.'_');
            if ($uploadResult) {
                $stmtSave->execute(["stamp_img_$userId", $uploadResult['name'], $uploadResult['name']]);
            } else {
                throw new Exception("فایل مهر نامعتبر است. لطفاً فقط تصویر معتبر (PNG یا JPG) آپلود کنید.");
            }
        }

        // آپلود سربرگ (فقط ادمین)
        if ($isAdmin && isset($_FILES['letterhead_img']) && $_FILES['letterhead_img']['error'] === 0) {
            $uploadResult = upload_secure_file($_FILES['letterhead_img']['tmp_name'], $_FILES['letterhead_img']['name'], $uploadDir, $allowedImageMimes, 'letterhead_');
            if ($uploadResult) {
                $stmtSave->execute(['letterhead_img', $uploadResult['name'], $uploadResult['name']]);
            } else {
                throw new Exception("فایل سربرگ نامعتبر است. لطفاً فقط تصویر معتبر (PNG یا JPG) آپلود کنید.");
            }
        }

        $msg = "تنظیمات با موفقیت ذخیره شد.";
        $msgType = "success";
        
        // رفرش داده‌ها
        $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('signature_pin_$userId', 'signature_img_$userId', 'stamp_img_$userId', 'signer_name_$userId', 'signer_title_$userId', 'letterhead_img')");
        while($row = $stmtSettings->fetch()) $sigSettings[$row['setting_key']] = $row['setting_value'];

    } catch(Throwable $e) {
        $msg = $e->getMessage();
        $msgType = "error";
    }
}

// عملیات مربوط به اندیکاتورها (فقط ادمین)
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $fiscalYearId) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");
    $action = $_POST['action'];
    try {
        if ($action === 'add') {
            $prefix = trim($_POST['prefix']);
            $startSeq = (int)($_POST['sequence'] ?? 0);
            if (empty($prefix)) throw new Exception("پیشوند نمی‌تواند خالی باشد.");
            $stmtCheck = $pdo->prepare("SELECT id FROM letter_indicators WHERE fiscal_year_id = ? AND department_prefix = ?");
            $stmtCheck->execute([$fiscalYearId, $prefix]);
            if ($stmtCheck->fetch()) throw new Exception("این پیشوند قبلاً ثبت شده.");
            $pdo->prepare("INSERT INTO letter_indicators (fiscal_year_id, department_prefix, last_sequence) VALUES (?, ?, ?)")->execute([$fiscalYearId, $prefix, $startSeq]);
            $msg = "پیشوند اضافه شد."; $msgType = "success";
        } elseif ($action === 'update') {
            $id = (int)$_POST['indicator_id']; $newSeq = (int)$_POST['sequence'];
            $pdo->prepare("UPDATE letter_indicators SET last_sequence = ? WHERE id = ?")->execute([$newSeq, $id]);
            $msg = "شمارنده به‌روزرسانی شد."; $msgType = "success";
        } elseif ($action === 'delete') {
            $id = (int)$_POST['indicator_id'];
            $pdo->prepare("DELETE FROM letter_indicators WHERE id = ?")->execute([$id]);
            $msg = "حذف گردید."; $msgType = "success";
        }
    } catch (Throwable $e) { $msg = $e->getMessage(); $msgType = "error"; }
}

$indicators = [];
if ($isAdmin && $fiscalYearId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM letter_indicators WHERE fiscal_year_id = ? ORDER BY department_prefix ASC");
        $stmt->execute([$fiscalYearId]);
        $indicators = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$signerName = $sigSettings["signer_name_$userId"] ?? '';
$signerTitle = $sigSettings["signer_title_$userId"] ?? '';
$signImg = $sigSettings["signature_img_$userId"] ?? '';
$stampImg = $sigSettings["stamp_img_$userId"] ?? '';
$letterheadImg = $sigSettings['letterhead_img'] ?? '';

$pageTitle = 'تنظیمات مکاتبات';
$basePath = '../';
$extraCss = '<style>
    .set-card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e5e7eb; margin-bottom: 25px; }
    .set-header { font-size: 1.1rem; font-weight: 800; color: #1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
    .table-ind { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .table-ind th, .table-ind td { padding: 15px; border-bottom: 1px solid #f1f5f9; text-align: right; vertical-align: middle; }
    .table-ind th { background: #f8fafc; color: #475569; font-weight: 800; }
    .btn-xs { padding: 6px 12px; font-size: 0.85rem; border-radius: 6px; font-weight: bold; }
    .form-inline { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
    .prefix-badge { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 6px 12px; border-radius: 8px; font-weight: 900; font-size: 1rem; display: inline-block; direction: ltr; text-align: right; }
    .img-preview { border: 1px dashed #cbd5e1; border-radius: 8px; padding: 10px; text-align: center; background: #f8fafc; margin-top: 10px; }
    .img-preview img { max-height: 80px; max-width: 100%; object-fit: contain; }
    @media (max-width: 768px) {
        .content-wrapper { padding: 10px !important; }
        .set-card { padding: 15px; }
        .form-inline { flex-direction: column; align-items: stretch; }
        .form-inline > div { width: 100%; }
        .form-inline input, .form-inline button { width: 100% !important; }
        .table-ind thead { display: none; }
        .table-ind tr { display: flex; flex-direction: column; border: 1px solid #e2e8f0; margin-bottom: 15px; border-radius: 8px; padding: 10px; }
        .table-ind td { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #f1f5f9; padding: 10px 0; }
        .table-ind td:last-child { border-bottom: none; }
        .table-ind td::before { content: attr(data-label); font-weight: bold; color: #64748b; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">⚙️ تنظیمات امضای اختصاصی شما</span></div>
        </div>

        <?php if($msg): ?>
            <div id="alertMsg" class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="set-card">
            <div class="set-header">✍️ مدیریت اطلاعات و تصاویر امضا</div>
            <p style="font-size: 0.9rem; color: #64748b; margin-bottom: 20px; line-height: 1.6;">
                نام، سمت و تصاویر وارد شده در این بخش، مختص حساب کاربری شماست و زمانی که نامه‌ای را امضا می‌کنید، اطلاعات زیر در نامه ثبت می‌گردد.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_signature_settings" value="1">
                
                <div style="display:flex; flex-wrap:wrap; gap:20px; margin-bottom:20px;">
                    <div style="flex:1; min-width:250px;">
                        <label style="font-weight:bold; margin-bottom:5px; display:block; color:#1e293b;">نام و نام‌خانوادگی جهت درج در نامه</label>
                        <input type="text" name="signer_name" class="form-control" value="<?php echo htmlspecialchars($signerName); ?>" required>
                    </div>
                    <div style="flex:1; min-width:250px;">
                        <label style="font-weight:bold; margin-bottom:5px; display:block; color:#1e293b;">سمت سازمانی شما</label>
                        <input type="text" name="signer_title" class="form-control" value="<?php echo htmlspecialchars($signerTitle); ?>" required>
                    </div>
                </div>

                <div style="display:flex; flex-wrap:wrap; gap:20px; margin-bottom:20px; background:#f8fafc; border:1px dashed #cbd5e1; padding:15px; border-radius:10px;">
                    <div style="flex:1; min-width:250px;">
                        <label style="font-weight:bold; margin-bottom:5px; display:block; color:#1e293b;">پین‌کد امنیتی فعلی (فقط جهت تغییر رمز)</label>
                        <input type="password" name="old_pin" class="form-control" placeholder="****" style="letter-spacing:2px; font-weight:bold; text-align:center;">
                    </div>
                    <div style="flex:1; min-width:250px;">
                        <label style="font-weight:bold; margin-bottom:5px; display:block; color:#1e293b;">پین‌کد جدید</label>
                        <input type="password" name="new_pin" class="form-control" placeholder="****" style="letter-spacing:2px; font-weight:bold; text-align:center;">
                        <small style="color:#ef4444; font-weight:bold;">در صورت خالی بودن این فیلد، پین‌کد قبلی شما حفظ می‌شود.</small>
                    </div>
                </div>

                <div style="display:flex; flex-wrap:wrap; gap:20px;">
                    <div style="flex:1; min-width:250px;">
                        <label style="font-weight:bold; margin-bottom:5px; display:block; color:#1e293b;">تصویر امضای اختصاصی (PNG بدون پس‌زمینه)</label>
                        <input type="file" name="signature_img" class="form-control" accept="image/png, image/jpeg">
                        <?php if($signImg): ?>
                            <div class="img-preview"><img src="../uploads/settings/<?php echo $signImg; ?>" alt="امضا"></div>
                        <?php endif; ?>
                    </div>

                    <div style="flex:1; min-width:250px;">
                        <label style="font-weight:bold; margin-bottom:5px; display:block; color:#1e293b;">تصویر مهر اختصاصی (PNG بدون پس‌زمینه)</label>
                        <input type="file" name="stamp_img" class="form-control" accept="image/png, image/jpeg">
                        <?php if($stampImg): ?>
                            <div class="img-preview"><img src="../uploads/settings/<?php echo $stampImg; ?>" alt="مهر"></div>
                        <?php endif; ?>
                    </div>

                    <?php if($isAdmin): ?>
                    <div style="flex:1; min-width:250px; background:#eff6ff; padding:10px; border-radius:8px; border:1px solid #bfdbfe;">
                        <label style="font-weight:bold; margin-bottom:5px; display:block; color:#1d4ed8;">سربرگ شرکت (سراسری - مخصوص ادمین)</label>
                        <input type="file" name="letterhead_img" class="form-control" accept="image/png, image/jpeg">
                        <?php if($letterheadImg): ?>
                            <div class="img-preview" style="background:#fff;"><img src="../uploads/settings/<?php echo $letterheadImg; ?>" alt="سربرگ"></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top:20px; text-align:left;">
                    <button type="submit" class="btn btn-primary" style="padding:10px 30px; font-weight:bold;">💾 ذخیره اطلاعات</button>
                </div>
            </form>
        </div>

        <?php if($isAdmin && $fiscalYearId): ?>
        <div class="set-card">
            <div class="set-header">➕ تعریف پیشوند اندیکاتور (سراسری)</div>
            <form method="POST" class="form-inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                <div style="flex: 2;">
                    <label style="font-size:0.85rem; color:#475569; font-weight:bold; margin-bottom:5px; display:block;">نام پیشوند (مثال: الف)</label>
                    <input type="text" name="prefix" class="form-control" required>
                </div>
                <div style="flex: 1;">
                    <label style="font-size:0.85rem; color:#475569; font-weight:bold; margin-bottom:5px; display:block;">شروع از شماره</label>
                    <input type="number" name="sequence" class="form-control" value="0" required>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary" style="height: 43px; padding: 0 25px;">ثبت پیشوند</button>
                </div>
            </form>
        </div>

        <div class="set-card">
            <div class="set-header">📊 مدیریت شمارنده‌های سال مالی (<?php echo htmlspecialchars($activeFiscalYear['title']); ?>)</div>
            <div style="overflow-x:auto;">
                <table class="table-ind">
                    <thead>
                        <tr>
                            <th>نام پیشوند</th>
                            <th>آخرین شماره ثبت شده</th>
                            <th>وضعیت / شماره بعدی</th>
                            <th>حذف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($indicators)): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 30px; color:#94a3b8; font-weight:bold;">هیچ پیشوندی تعریف نشده است.</td></tr>
                        <?php else: foreach($indicators as $ind): ?>
                            <tr>
                                <td data-label="نام پیشوند:"><span class="prefix-badge"><?php echo htmlspecialchars($ind['department_prefix']); ?></span></td>
                                <td data-label="آخرین شماره:">
                                    <form method="POST" class="form-inline" style="margin:0; justify-content: flex-end;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="indicator_id" value="<?php echo $ind['id']; ?>">
                                        <input type="number" name="sequence" class="form-control" value="<?php echo $ind['last_sequence']; ?>" style="width:100px; height:35px; text-align:center; font-weight:bold; direction:ltr;">
                                        <button type="submit" class="btn btn-success btn-xs">ذخیره</button>
                                    </form>
                                </td>
                                <td data-label="شماره بعدی:" style="color:#10b981; font-weight:bold; font-size:0.9rem;"><?php echo $ind['last_sequence'] + 1; ?></td>
                                <td data-label="حذف:">
                                    <form method="POST" onsubmit="return confirm('حذف شود؟');" style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="indicator_id" value="<?php echo $ind['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-xs">حذف کامل</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>
<script>setTimeout(()=>document.getElementById('alertMsg')?.remove(), 5000);</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>