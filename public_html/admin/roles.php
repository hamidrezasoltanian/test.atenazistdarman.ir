<?php
/*
 * فایل: public_html/admin/roles.php
 */

ini_set('display_errors', 1);
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
        ob_clean(); 
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شد']); 
        exit; 
    }
    header("Location: ../login.php"); exit;
}

// --- پردازش AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // عملیات حذف
    if (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("SELECT is_system FROM roles WHERE id=?");
        $stmt->execute([$id]);
        $role = $stmt->fetch();
        
        if ($role && $role['is_system'] == 1) {
            // نقش سیستمی حذف نمی‌شود (پیام در اینجا نمایش داده نمی‌شود چون ریدایرکت است)
        } else {
            try {
                $pdo->prepare("DELETE FROM roles WHERE id=?")->execute([$id]);
            } catch (Exception $e) { }
        }
        header("Location: roles.php");
        exit;
    }

    if ($isAjax) {
        ini_set('display_errors', 0);
        ob_clean();
        header('Content-Type: application/json');

        $action = $_POST['action'];
        if ($action === 'add' || $action === 'edit') {
            try {
                $title = trim($_POST['title']);
                $name = trim($_POST['name']);
                $id = $_POST['id'] ?? null;
                $permissions = $_POST['permissions'] ?? [];
                
                $permsJson = json_encode($permissions);

                if (empty($title)) {
                    echo json_encode(['status'=>'error', 'message'=>'عنوان نقش الزامی است.']); exit;
                }

                if ($action === 'add') {
                    if (empty($name)) { echo json_encode(['status'=>'error', 'message'=>'نام لاتین کلیدی الزامی است.']); exit; }
                    
                    $check = $pdo->prepare("SELECT id FROM roles WHERE name=?");
                    $check->execute([$name]);
                    if($check->rowCount() > 0) { echo json_encode(['status'=>'error', 'message'=>'این نام کلیدی قبلاً ثبت شده است.']); exit; }

                    $stmt = $pdo->prepare("INSERT INTO roles (name, title, permissions) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $title, $permsJson]);
                    echo json_encode(['status'=>'success', 'message'=>'نقش جدید ایجاد شد.']);
                } else {
                    // --- جلوگیری از ویرایش ادمین ---
                    $checkSys = $pdo->prepare("SELECT name, is_system FROM roles WHERE id=?");
                    $checkSys->execute([$id]);
                    $currentRole = $checkSys->fetch();
                    
                    if ($currentRole && ($currentRole['name'] === 'admin' || $currentRole['is_system'] == 1)) {
                        // اگر نقش ادمین یا سیستمی باشد، اجازه تغییر نمی‌دهیم
                        echo json_encode(['status'=>'error', 'message'=>'این نقش سیستمی است و قابل تغییر نیست.']); 
                        exit;
                    }

                    $stmt = $pdo->prepare("UPDATE roles SET title=?, permissions=? WHERE id=?");
                    $stmt->execute([$title, $permsJson, $id]);
                    echo json_encode(['status'=>'success', 'message'=>'تغییرات ذخیره شد.']);
                }
            } catch (Exception $e) {
                echo json_encode(['status'=>'error', 'message'=>'خطا: ' . $e->getMessage()]);
            }
            exit;
        }
    }
}

// --- دریافت اطلاعات ---
$rolesList = $pdo->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();

$menuItems = [];
$menuPath = __DIR__ . '/../../includes/menu_config.php';
if (file_exists($menuPath)) $menuItems = require $menuPath;

$pageTitle = 'مدیریت نقش‌ها و دسترسی‌ها';
$basePath = '../';
$extraCss = '<link rel="stylesheet" href="../assets/css/roles.css">';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">مدیریت نقش‌ها</span></div>
            <div>
                <button onclick="openModal('add')" class="btn btn-primary">+ نقش جدید</button>
            </div>
        </div>

        <div class="roles-grid">
            <?php foreach ($rolesList as $role): ?>
            <div class="role-card">
                <?php if($role['is_system']): ?>
                    <span class="system-badge">سیستمی</span>
                <?php endif; ?>
                
                <div class="role-header">
                    <div class="role-icon">🛡️</div>
                    <div class="role-info">
                        <h3><?php echo htmlspecialchars($role['title']); ?></h3>
                        <span>کلید: <?php echo htmlspecialchars($role['name']); ?></span>
                    </div>
                </div>
                
                <div class="role-actions">
                    <button onclick='editRole(<?php echo htmlspecialchars(json_encode($role), ENT_QUOTES, 'UTF-8'); ?>)' class="btn btn-success" style="font-size:0.8rem">مشاهده / ویرایش</button>
                    <?php if(!$role['is_system']): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف این نقش اطمینان دارید؟');">
                        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $role['id']; ?>">
                        <button type="submit" class="btn btn-danger" style="font-size:0.8rem">حذف</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری آتنا زیست درمان</footer>
</main>

<!-- مودال نقش تمام صفحه -->
<div class="modal-overlay" id="roleModal">
    <div class="modal">
        <div class="modal-header"><h3 id="modalTitle">تعریف نقش</h3><span class="close-modal" onclick="closeModal()">&times;</span></div>
        
        <div class="modal-body">
            <div id="modalMessage"></div>
            <form method="POST" id="roleForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="roleId">
                
                <div class="row" style="display: flex; gap: 15px; margin-bottom: 10px;">
                    <div class="col form-group" style="flex:1">
                        <label class="form-label">عنوان نمایشی (فارسی) <span style="color:red">*</span></label>
                        <input type="text" name="title" id="roleTitle" class="form-control" required>
                    </div>
                    <div class="col form-group" style="flex:1">
                        <label class="form-label">نام کلیدی (لاتین) <span style="color:red">*</span></label>
                        <input type="text" name="name" id="roleName" class="form-control" required pattern="[a-zA-Z0-9_]+">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">دسترسی‌های مجاز:</label>
                    <div class="permissions-container">
                        <!-- دسترسی عمومی داشبورد -->
                        <div class="perm-group">
                            <div class="perm-header">
                                <label><input type="checkbox" onchange="toggleGroup(this, 'perm-dashboard')"> داشبورد و عمومی</label>
                            </div>
                            <div class="perm-body">
                                <div class="perm-item"><label><input type="checkbox" name="permissions[]" value="dashboard_view" class="perm-dashboard"> مشاهده داشبورد</label></div>
                            </div>
                        </div>

                        <?php 
                        // تولید چک‌باکس‌ها به صورت خودکار از روی منو
                        // اگر منویی اضافه شود، اینجا خودکار ساخته می‌شود
                        foreach ($menuItems as $index => $item): 
                            if (isset($item['type']) && $item['type'] === 'separator') continue;
                            $groupClass = 'perm-group-' . $index;
                        ?>
                        <div class="perm-group">
                            <div class="perm-header">
                                <label>
                                    <input type="checkbox" onchange="toggleGroup(this, '<?php echo $groupClass; ?>')"> 
                                    <?php echo htmlspecialchars($item['title']); ?>
                                </label>
                            </div>
                            <div class="perm-body">
                                <?php if(isset($item['perm'])): ?>
                                    <div class="perm-item">
                                        <label><input type="checkbox" name="permissions[]" value="<?php echo $item['perm']; ?>" class="<?php echo $groupClass; ?>"> مشاهده منو</label>
                                    </div>
                                <?php endif; ?>

                                <?php if (isset($item['submenu'])): ?>
                                    <?php foreach ($item['submenu'] as $sub): if(isset($sub['perm'])): ?>
                                    <div class="perm-item">
                                        <label><input type="checkbox" name="permissions[]" value="<?php echo $sub['perm']; ?>" class="<?php echo $groupClass; ?>"> <?php echo htmlspecialchars($sub['title']); ?></label>
                                    </div>
                                    <?php endif; endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- بخش دکمه‌ها -->
                <div class="modal-footer">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">انصراف</button>
                    <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../assets/js/roles.js"></script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>