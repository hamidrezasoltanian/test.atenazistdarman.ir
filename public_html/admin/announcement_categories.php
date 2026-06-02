<?php
/*
 * فایل: public_html/admin/announcement_categories.php
 * توضیحات: مدیریت دسته‌بندی‌ها و رنگ‌های اعلانات با طراحی ریسپانسیو و استاندارد
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// بررسی لاگین
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// بررسی دسترسی (فقط ادمین یا کسانی که دسترسی دارند)
if ($_SESSION['role'] !== 'admin' && !hasPermission('announcements_cat')) {
    die('<div style="text-align:center; padding:50px; font-family:tahoma;">شما مجوز دسترسی به این صفحه را ندارید. <a href="dashboard.php">بازگشت</a></div>');
}

$msg = '';
$msgType = '';

// --- پردازش فرم (افزودن / ویرایش / حذف) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $msg = "خطای امنیتی (CSRF). لطفاً صفحه را رفرش کنید.";
        $msgType = "danger";
    }
    $action = $_POST['action'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $color = $_POST['color'] ?? '#3b82f6';
    $id = $_POST['id'] ?? null;

    try {
        if (empty($msg) && ($action === 'add' || $action === 'edit')) {
            if (empty($title)) {
                $msg = "عنوان دسته الزامی است.";
                $msgType = "danger";
            } else {
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO announcement_categories (title, color) VALUES (?, ?)");
                    $stmt->execute([$title, $color]);
                    $msg = "دسته جدید با موفقیت ایجاد شد.";
                    $msgType = "success";
                } else {
                    $stmt = $pdo->prepare("UPDATE announcement_categories SET title=?, color=? WHERE id=?");
                    $stmt->execute([$title, $color, $id]);
                    $msg = "دسته ویرایش شد.";
                    $msgType = "success";
                }
            }
        } elseif (empty($msg) && $action === 'delete') {
            // بررسی اینکه آیا اعلانی با این دسته وجود دارد؟
            $check = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE category_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                $msg = "این دسته قابل حذف نیست زیرا اعلان‌هایی به آن متصل هستند.";
                $msgType = "danger";
            } else {
                $pdo->prepare("DELETE FROM announcement_categories WHERE id=?")->execute([$id]);
                $msg = "دسته حذف شد.";
                $msgType = "success";
            }
        }
    } catch (Exception $e) {
        $msg = "خطا: " . $e->getMessage();
        $msgType = "danger";
    }
}

// دریافت لیست دسته‌ها
$cats = $pdo->query("SELECT * FROM announcement_categories ORDER BY id DESC")->fetchAll();

$pageTitle = 'مدیریت دسته‌بندی اعلانات';
$basePath = '../';

// استایل‌های اختصاصی صفحه
$extraCss = '<style>
    /* تنظیم کانتینر اصلی مشابه سایر صفحات */
    .content-wrapper {
        padding: 30px;
        max-width: 1200px;
        margin: 0 auto;
    }

    /* ساختار گرید: فرم سمت راست، جدول سمت چپ */
    .categories-grid {
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 25px;
        align-items: start;
    }

    .card-custom {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);
    }

    .card-header-custom {
        font-weight: bold;
        font-size: 1.1rem;
        color: #1f2937;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #f3f4f6;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* جدول */
    .table-custom {
        width: 100%;
        border-collapse: collapse;
    }
    .table-custom th {
        background: #f8fafc;
        color: #64748b;
        font-weight: 600;
        font-size: 0.9rem;
        padding: 12px 15px;
        text-align: right;
        border-bottom: 1px solid #e2e8f0;
    }
    .table-custom td {
        padding: 12px 15px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.95rem;
        color: #334155;
        vertical-align: middle;
    }
    .table-custom tr:last-child td { border-bottom: none; }

    .color-preview-box {
        width: 35px;
        height: 35px;
        border-radius: 8px;
        display: inline-block;
        vertical-align: middle;
        border: 1px solid #e5e7eb;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    .color-input-wrapper {
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid #d1d5db;
        padding: 5px 10px;
        border-radius: 8px;
        background: #fff;
    }
    
    .form-control-color {
        width: 50px;
        height: 35px;
        padding: 0;
        border: none;
        background: none;
        cursor: pointer;
    }

    /* استایل دکمه‌های عملیاتی */
    .btn-action-group {
        display: flex;
        gap: 5px;
        justify-content: flex-end;
    }
    .btn-action-group .btn {
        padding: 4px 10px;
        font-size: 0.85rem;
    }

    /* ریسپانسیو */
    @media (max-width: 992px) {
        .categories-grid {
            grid-template-columns: 1fr; /* ستون‌ها زیر هم */
        }
        .content-wrapper {
            padding: 15px;
        }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        
        <!-- هدر صفحه -->
        <div class="page-header page-actions">
            <div>
                <span class="page-title">دسته‌بندی اعلانات</span>
            </div>
            <div>
                <a href="announcements.php" class="btn btn-secondary">
                    بازگشت به لیست اعلانات
                </a>
            </div>
        </div>

        <!-- نمایش پیام -->
        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msgType; ?> mb-4"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="categories-grid">
            
            <!-- ستون اول: فرم افزودن/ویرایش -->
            <div class="card-custom">
                <div class="card-header-custom">
                    <span id="formTitle">افزودن دسته جدید</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetForm()" id="resetBtn" style="display:none;">لغو ویرایش</button>
                </div>
                
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="catId">
                    
                    <div class="form-group mb-3">
                        <label class="form-label">عنوان دسته <span style="color:red">*</span></label>
                        <input type="text" name="title" id="catTitle" class="form-control" required placeholder="مثلاً: اخبار مالی">
                    </div>
                    
                    <div class="form-group mb-4">
                        <label class="form-label">رنگ برچسب</label>
                        <div class="color-input-wrapper">
                            <input type="color" name="color" id="catColor" class="form-control-color" value="#3b82f6">
                            <span class="text-muted small">برای انتخاب رنگ کلیک کنید</span>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100" id="submitBtn">
                        ذخیره اطلاعات
                    </button>
                </form>
            </div>

            <!-- ستون دوم: جدول لیست -->
            <div class="card-custom" style="padding: 0;">
                <div class="card-header-custom" style="margin: 0; padding: 20px; border-bottom: 1px solid #f1f5f9;">
                    <span>لیست دسته‌ها</span>
                    <span class="badge bg-light text-dark"><?php echo count($cats); ?> مورد</span>
                </div>
                
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>عنوان</th>
                                <th>رنگ</th>
                                <th style="width: 180px;">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($cats) > 0): ?>
                                <?php foreach($cats as $c): ?>
                                <tr>
                                    <td><?php echo $c['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($c['title']); ?></strong></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span class="color-preview-box" style="background:<?php echo $c['color']; ?>"></span>
                                            <span class="small text-muted" dir="ltr"><?php echo $c['color']; ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-action-group">
                                            <button onclick='editCat(<?php echo json_encode($c); ?>)' class="btn btn-sm btn-outline-primary">
                                                ویرایش
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف این دسته مطمئن هستید؟');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger">
                                                    حذف
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">هنوز دسته‌ای تعریف نشده است.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </div>
    
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری آتنا زیست درمان</footer>
</main>

<script>
    function editCat(data) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('catId').value = data.id;
        document.getElementById('catTitle').value = data.title;
        document.getElementById('catColor').value = data.color;
        
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.innerHTML = 'ویرایش اطلاعات';
        // برای تغییر کلاس دکمه به warning (زرد) جهت تمایز حالت ویرایش
        submitBtn.classList.remove('btn-primary');
        submitBtn.classList.add('btn-warning');
        submitBtn.style.color = '#000'; // تغییر رنگ متن به مشکی
        
        document.getElementById('formTitle').innerText = 'ویرایش دسته: ' + data.title;
        document.getElementById('resetBtn').style.display = 'inline-block';
        
        // اسکرول به فرم در موبایل
        if(window.innerWidth < 992) {
            document.querySelector('.content-wrapper').scrollIntoView({behavior: 'smooth'});
        }
    }

    function resetForm() {
        document.getElementById('formAction').value = 'add';
        document.getElementById('catId').value = '';
        document.getElementById('catTitle').value = '';
        document.getElementById('catColor').value = '#3b82f6';
        
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.innerHTML = 'ذخیره اطلاعات';
        // بازگشت کلاس دکمه به حالت اولیه
        submitBtn.classList.remove('btn-warning');
        submitBtn.classList.add('btn-primary');
        submitBtn.style.color = ''; // ریست کردن استایل رنگ
        
        document.getElementById('formTitle').innerText = 'افزودن دسته جدید';
        document.getElementById('resetBtn').style.display = 'none';
    }
    
    // محو شدن پیام بعد از چند ثانیه
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 3000);
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>