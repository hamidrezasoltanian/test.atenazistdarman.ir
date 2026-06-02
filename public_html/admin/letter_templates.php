<?php
/*
 * فایل: public_html/admin/letter_templates.php
 * توضیحات: مدیریت قالب‌های متنی آماده برای نامه‌ها (نسخه هوشمند با حذف خطوط خالی اضافی)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$msg = '';
$msgType = '';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `letter_templates` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `title` varchar(255) NOT NULL,
      `content` text NOT NULL,
      `created_by` int(11) NOT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (\Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");
    
    $action = $_POST['action_type'] ?? '';
    
    try {
        if ($action === 'save') {
            $id = (int)($_POST['template_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            
            // --- فیلتر هوشمند: پاک کردن خطوط خالی (اینترهای اضافی) از ابتدا و انتهای قالب ---
            $content = preg_replace('/^(<p>\s*(?:&nbsp;|<br\s*\/?>|\s)*<\/p>\s*)+/i', '', $content);
            $content = preg_replace('/(<p>\s*(?:&nbsp;|<br\s*\/?>|\s)*<\/p>\s*)+$/i', '', $content);
            
            if (empty($title) || empty($content)) {
                throw new Exception("عنوان و متن قالب نمی‌تواند خالی باشد.");
            }
            
            if ($id > 0) {
                $pdo->prepare("UPDATE letter_templates SET title = ?, content = ? WHERE id = ? AND created_by = ?")
                    ->execute([$title, $content, $id, $userId]);
                $msg = "قالب با موفقیت ویرایش شد.";
            } else {
                $pdo->prepare("INSERT INTO letter_templates (title, content, created_by) VALUES (?, ?, ?)")
                    ->execute([$title, $content, $userId]);
                $msg = "قالب جدید با موفقیت ذخیره شد.";
            }
            $msgType = "success";
            
        } elseif ($action === 'delete') {
            $id = (int)$_POST['template_id'];
            $pdo->prepare("DELETE FROM letter_templates WHERE id = ? AND created_by = ?")->execute([$id, $userId]);
            $msg = "قالب متنی با موفقیت حذف شد.";
            $msgType = "success";
        }
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $msgType = "error";
    }
}

$stmt = $pdo->prepare("SELECT * FROM letter_templates WHERE created_by = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editTemplate = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmtEdit = $pdo->prepare("SELECT * FROM letter_templates WHERE id = ? AND created_by = ?");
    $stmtEdit->execute([$editId, $userId]);
    $editTemplate = $stmtEdit->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'مدیریت قالب‌های متنی';
$basePath = '../';
$extraCss = '<style>
    .t-card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e5e7eb; margin-bottom: 25px; }
    .t-header { font-size: 1.1rem; font-weight: 800; color: #1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
    .t-table { width: 100%; border-collapse: collapse; }
    .t-table th, .t-table td { padding: 12px 15px; text-align: right; border-bottom: 1px solid #f1f5f9; }
    .t-table th { background: #f8fafc; color: #475569; font-weight: bold; }
    .t-table tr:hover { background: #f8fafc; }
    .btn-xs { padding: 5px 12px; font-size: 0.85rem; border-radius: 6px; font-weight: bold; text-decoration: none; display: inline-block; cursor:pointer; border:none;}
    
    .tox-tinymce { border-radius: 8px !important; border-color: #cbd5e1 !important; }
    @media (max-width: 768px) {
        .content-wrapper { padding: 10px !important; }
        .t-table thead { display: none; }
        .t-table tr { display: flex; flex-direction: column; border: 1px solid #e2e8f0; margin-bottom: 15px; border-radius: 8px; padding: 10px; }
        .t-table td { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #f1f5f9; padding: 10px 0; text-align:left;}
        .t-table td:last-child { border-bottom: none; justify-content: center; gap:10px; }
        .t-table td::before { content: attr(data-label); font-weight: bold; color: #64748b; margin-left: 10px; text-align: right; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">📝 مدیریت قالب‌های آماده متن</span></div>
            <div>
                <?php if($editTemplate): ?>
                    <a href="letter_templates.php" class="btn btn-secondary">ثبت قالب جدید</a>
                <?php endif; ?>
                <button onclick="window.close()" class="btn btn-outline">بستن صفحه</button>
            </div>
        </div>

        <?php if($msg): ?><div id="alertMsg" class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div><?php endif; ?>

        <div class="t-card">
            <div class="t-header"><?php echo $editTemplate ? '✏️ ویرایش قالب' : '➕ تعریف قالب جدید'; ?></div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action_type" value="save">
                <input type="hidden" name="template_id" value="<?php echo $editTemplate['id'] ?? 0; ?>">
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">عنوان قالب (برای جستجو و انتخاب)</label>
                    <input type="text" name="title" class="form-control" required value="<?php echo htmlspecialchars($editTemplate['title'] ?? ''); ?>" placeholder="مثال: قالب استعلام قیمت کالا">
                </div>
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">متن آماده (با فرمت و استایل)</label>
                    <textarea id="tplEditor" name="content"><?php echo $editTemplate['content'] ?? ''; ?></textarea>
                </div>
                
                <div style="text-align: left;">
                    <button type="submit" class="btn btn-primary" style="padding: 10px 30px; font-weight: bold;">💾 ذخیره قالب</button>
                </div>
            </form>
        </div>

        <div class="t-card">
            <div class="t-header">📋 لیست قالب‌های ذخیره شده شما</div>
            <div style="overflow-x:auto;">
                <table class="t-table">
                    <thead>
                        <tr>
                            <th>عنوان قالب</th>
                            <th>تاریخ ایجاد</th>
                            <th style="text-align:center;">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($templates)): ?>
                            <tr><td colspan="3" style="text-align:center; padding:30px; color:#94a3b8;">قالبی ثبت نشده است.</td></tr>
                        <?php else: foreach($templates as $tpl): ?>
                            <tr>
                                <td data-label="عنوان:"><strong style="color:#0f172a;"><?php echo htmlspecialchars($tpl['title']); ?></strong></td>
                                <td data-label="تاریخ:"><span style="direction:ltr; display:inline-block; font-size:0.85rem; color:#64748b;"><?php echo function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($tpl['created_at'])) : $tpl['created_at']; ?></span></td>
                                <td data-label="عملیات:" style="display:flex; justify-content:center; gap:5px;">
                                    <a href="?edit=<?php echo $tpl['id']; ?>" class="btn-xs" style="background:#eff6ff; color:#2563eb;">ویرایش</a>
                                    <form method="POST" onsubmit="return confirm('حذف شود؟');" style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action_type" value="delete">
                                        <input type="hidden" name="template_id" value="<?php echo $tpl['id']; ?>">
                                        <button type="submit" class="btn-xs" style="background:#fee2e2; color:#b91c1c;">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</main>

<script src="../assets/js/tinymce/tinymce.min.js"></script>
<script>
    setTimeout(() => { if(document.getElementById('alertMsg')) document.getElementById('alertMsg').remove(); }, 5000);
    
    tinymce.init({
        selector: '#tplEditor', 
        license_key: 'gpl',
        directionality: 'rtl',
        language: 'fa', 

        plugins: 'advlist autolink lists link image charmap preview table code wordcount fullscreen pagebreak searchreplace visualblocks',

        toolbar: 'undo redo | fontfamily fontsize lineheight | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table pagebreak | charmap | fullscreen preview code',

        font_family_formats: 'وزیرمتن=Vazirmatn,sans-serif; بی تیتر=BTitr,Tahoma; بی نازنین=BNazanin,Tahoma; ساحل=SahelFD,Tahoma; تاهوما=Tahoma',
        line_height_formats: '1 1.15 1.2 1.5 1.8 2.0 2.5 3.0',
        
        height: 450,
        menubar: true, 
        branding: false,

        content_style: `
            @font-face { font-family: 'BTitr'; src: url('../assets/fonts/BTitr.ttf') format('truetype'); }
            @font-face { font-family: 'BNazanin'; src: url('../assets/fonts/BNazanin.ttf') format('truetype'); }
            @font-face { font-family: 'SahelFD'; src: url('../assets/fonts/SahelFD.ttf') format('truetype'); }
            @font-face { font-family: 'Vazirmatn'; src: url('../assets/fonts/Vazirmatn-Regular.ttf') format('truetype'); }
            
            body { 
                font-family: 'Vazirmatn', Tahoma, sans-serif !important; 
                font-size: 15px; 
                direction: rtl; 
                text-align: right; 
                line-height: 1.8;
            } 
            table { border-collapse: collapse; width: 100%; } 
            table td, table th { border: 1px solid #cbd5e1; padding: 8px; }
        `
    });
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>