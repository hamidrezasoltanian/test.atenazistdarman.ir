<?php
/*
 * فایل: public_html/admin/create_announcement.php
 * توضیحات: صفحه اختصاصی برای ایجاد یا ویرایش اعلان (انتخاب هوشمند بین دپارتمان و کاربر)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// بررسی لاگین
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

// بررسی دسترسی
if ($_SESSION['role'] !== 'admin' && !hasPermission('announcements_create')) {
    die('<div style="text-align:center; padding:50px;">شما مجوز ارسال اعلان ندارید. <a href="dashboard.php">بازگشت</a></div>');
}

$userId = $_SESSION['user_id'];
$message = '';
$msgType = '';

// حالت ویرایش
$id = $_GET['id'] ?? null;
$announcement = null;
$selectedUsersForEdit = [];

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([$id]);
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$announcement) { die("اعلان یافت نشد."); }
    
    // دریافت کاربرانی که قبلا انتخاب شده‌اند (برای حالت ویرایش)
    if (db_has_column($pdo, 'announcement_targets', 'announcement_id')) {
        $stT = $pdo->prepare("SELECT user_id FROM announcement_targets WHERE announcement_id = ?");
        $stT->execute([$id]);
        $selectedUsersForEdit = $stT->fetchAll(PDO::FETCH_COLUMN);
    }
}

// پردازش فرم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $message = "خطای امنیتی (CSRF). لطفاً صفحه را رفرش کنید.";
        $msgType = "danger";
    }
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $priority = $_POST['priority'];
    $status = $_POST['status'];
    $category_id = $_POST['category_id'] ?? 1;
    $type = 'manual';

    $target_mode = $_POST['target_mode'] ?? 'all'; // all, departments, users
    $target_department_id = null;
    $target_users_list = [];

    // پردازش بر اساس حالت انتخاب شده
    if ($target_mode === 'departments') {
        $depts = $_POST['target_department_id'] ?? [];
        if (!is_array($depts)) $depts = [$depts];
        
        $depts = array_map('intval', $depts);
        $depts = array_filter($depts, function($v) { return $v > 0; });
        if (!empty($depts)) {
            $target_department_id = implode(',', $depts);
        }
    } elseif ($target_mode === 'users') {
        $usrs = $_POST['target_users'] ?? [];
        if (!is_array($usrs)) $usrs = [$usrs];
        $target_users_list = array_map('intval', $usrs);
        $target_users_list = array_filter($target_users_list, function($v) { return $v > 0; });
    }

    if (empty($message) && (empty($title) || empty($content))) {
        $message = "عنوان و متن الزامی است.";
        $msgType = "danger";
    } else {
        // آپلود فایل
        $attachment = $announcement['attachment'] ?? null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'zip', 'doc', 'docx'];
            if (in_array($ext, $allowed)) {
                $newName = uniqid('ann_') . '.' . $ext;
                $uploadDir = __DIR__ . '/../uploads/announcements/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $newName)) {
                    $attachment = $newName;
                }
            } else {
                $message = "فرمت فایل مجاز نیست.";
                $msgType = "danger";
            }
        }

        if (empty($message)) { 
            if ($id) {
                // ویرایش
                $sqlUpdate = "UPDATE announcements SET title=?, content=?, priority=?, status=?, category_id=?, attachment=?";
                $updateParams = [$title, $content, $priority, $status, $category_id, $attachment];
                
                if (isset($pdo) && db_has_column($pdo, 'announcements', 'target_department_id')) {
                    $sqlUpdate .= ", target_department_id=?";
                    $updateParams[] = $target_department_id;
                }
                
                // پاک کردن target_role قبلی (چون حذف شد)
                if (isset($pdo) && db_has_column($pdo, 'announcements', 'target_role')) {
                    $sqlUpdate .= ", target_role=NULL";
                }

                $sqlUpdate .= " WHERE id=?";
                $updateParams[] = $id;

                $stmt = $pdo->prepare($sqlUpdate);
                $stmt->execute($updateParams);
                
                // بروزرسانی کاربران هدف
                $pdo->prepare("DELETE FROM announcement_targets WHERE announcement_id = ?")->execute([$id]);
                if (!empty($target_users_list)) {
                    $stmtT = $pdo->prepare("INSERT INTO announcement_targets (announcement_id, user_id) VALUES (?, ?)");
                    foreach ($target_users_list as $uId) {
                        $stmtT->execute([$id, $uId]);
                    }
                }

                logSystem('Announcement', 'update', $id, "ویرایش اعلان: $title");
                $message = "اعلان با موفقیت ویرایش شد.";
                $msgType = "success";
                
                // رفرش کردن دیتای فرم
                $announcement['target_department_id'] = $target_department_id;
                $selectedUsersForEdit = $target_users_list;

            } else {
                // ایجاد
                $cols = ['title','content','priority','status','category_id','attachment','created_by','type'];
                $vals = [$title, $content, $priority, $status, $category_id, $attachment, $userId, $type];
                
                if (isset($pdo) && db_has_column($pdo, 'announcements', 'target_department_id')) {
                    $cols[] = 'target_department_id';
                    $vals[] = $target_department_id;
                }
                
                $placeholders = rtrim(str_repeat('?,', count($cols)), ',');
                $stmt = $pdo->prepare("INSERT INTO announcements (" . implode(',', $cols) . ") VALUES ($placeholders)");
                $stmt->execute($vals);
                $newId = $pdo->lastInsertId();
                
                // ثبت کاربران هدف
                if (!empty($target_users_list)) {
                    $stmtT = $pdo->prepare("INSERT INTO announcement_targets (announcement_id, user_id) VALUES (?, ?)");
                    foreach ($target_users_list as $uId) {
                        $stmtT->execute([$newId, $uId]);
                    }
                }

                logSystem('Announcement', 'create', $newId, "ایجاد اعلان: $title");

                // === بخش ثبت نوتیفیکیشن (زنگوله و پاپ‌آپ) هدفمند ===
                if ($status === 'published' && function_exists('send_user_notification')) {
                    $targetUsersToNotify = [];
                    
                    if ($target_mode === 'all') {
                        $uStmt = $pdo->query("SELECT id FROM users WHERE status = 'active'");
                        $targetUsersToNotify = $uStmt->fetchAll(PDO::FETCH_COLUMN);
                    } 
                    elseif ($target_mode === 'departments' && !empty($target_department_id)) {
                        $deptArr = explode(',', $target_department_id);
                        $inQuery = implode(',', array_fill(0, count($deptArr), '?'));
                        $uStmt = $pdo->prepare("SELECT id FROM users WHERE status = 'active' AND department_id IN ($inQuery)");
                        $uStmt->execute($deptArr);
                        $targetUsersToNotify = $uStmt->fetchAll(PDO::FETCH_COLUMN);
                    } 
                    elseif ($target_mode === 'users' && !empty($target_users_list)) {
                        $targetUsersToNotify = $target_users_list;
                    }

                    // ارسال اعلان
                    if (!empty($targetUsersToNotify)) {
                        $annTitle = "📢 اعلان جدید: " . mb_strimwidth($title, 0, 40, '...');
                        $annContent = "برای مشاهده جزئیات کلیک کنید.";
                        $annLink = "admin/announcements.php"; 
                        
                        foreach (array_unique($targetUsersToNotify) as $tUid) {
                            send_user_notification($pdo, $tUid, $annTitle, $annContent, $annLink, 'announcement', $newId);
                        }
                    }
                }

                $message = "اعلان با موفقیت ایجاد شد و برای مخاطبین هدف ارسال گردید.";
                $msgType = "success";
                $title = $content = ''; 
                $target_users_list = [];
                $target_department_id = null;
            }
        }
    }
}

// دریافت دسته‌بندی‌ها، دپارتمان‌ها و کاربران
$categories = $pdo->query("SELECT * FROM announcement_categories")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM departments WHERE status='active' ORDER BY name ASC")->fetchAll();
$allUsers = $pdo->query("SELECT id, first_name, last_name, username FROM users WHERE status='active' ORDER BY first_name ASC")->fetchAll();

$pageTitle = $id ? 'ویرایش اعلان' : 'ارسال اعلان جدید';
$basePath = '../';

$extraCss = '<style>
    .content-wrapper { padding: 30px; max-width: 1000px; margin: 0 auto; }
    @media (max-width: 768px) { .content-wrapper { padding: 15px; } }
    .fade-out { opacity: 0; transition: opacity 0.5s ease-out; }
    .target-box { background: #f8fafc; border: 1px dashed #cbd5e1; padding: 15px; border-radius: 10px; margin-top: 15px; display: none; }
    .target-box.active { display: block; animation: fadeIn 0.3s; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';

// تشخیص حالت فعلی برای ویرایش
$currentMode = 'all';
if ($id) {
    if (!empty($selectedUsersForEdit)) $currentMode = 'users';
    elseif (!empty($announcement['target_department_id'])) $currentMode = 'departments';
}
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title"><?php echo $pageTitle; ?></span></div>
            <div><a href="announcements.php" class="btn btn-secondary">بازگشت به لیست</a></div>
        </div>

        <?php if($message): ?>
            <div id="alertBox" class="alert alert-<?php echo $msgType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="card" style="background: white; padding: 30px; border-radius: 12px; border: 1px solid #e5e7eb;">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <div class="form-group mb-3">
                    <label class="form-label">عنوان اعلان <span style="color:red">*</span></label>
                    <input type="text" name="title" class="form-control" required value="<?php echo htmlspecialchars($title ?? $announcement['title'] ?? ''); ?>">
                </div>
                
                <div class="row" style="display:flex; gap:15px; flex-wrap:wrap;">
                    <div class="col" style="flex:1; min-width:200px;">
                        <label class="form-label">دسته‌بندی</label>
                        <select name="category_id" class="form-control">
                            <?php foreach($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo ($announcement['category_id'] ?? 1) == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col" style="flex:1; min-width:200px;">
                        <label class="form-label">اولویت</label>
                        <select name="priority" class="form-control">
                            <option value="normal" <?php echo ($announcement['priority'] ?? '') == 'normal' ? 'selected' : ''; ?>>عادی</option>
                            <option value="high" <?php echo ($announcement['priority'] ?? '') == 'high' ? 'selected' : ''; ?>>مهم</option>
                            <option value="critical" <?php echo ($announcement['priority'] ?? '') == 'critical' ? 'selected' : ''; ?>>فوری</option>
                        </select>
                    </div>
                    <div class="col" style="flex:1; min-width:200px;">
                        <label class="form-label">وضعیت انتشار</label>
                        <select name="status" class="form-control">
                            <option value="published" <?php echo ($announcement['status'] ?? '') == 'published' ? 'selected' : ''; ?>>منتشر شود</option>
                            <option value="draft" <?php echo ($announcement['status'] ?? '') == 'draft' ? 'selected' : ''; ?>>پیش‌نویس (مخفی)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group mt-3 mb-3">
                    <label class="form-label">متن کامل <span style="color:red">*</span></label>
                    <textarea name="content" class="form-control" rows="8" required><?php echo htmlspecialchars($content ?? $announcement['content'] ?? ''); ?></textarea>
                </div>

                <div style="border:1px solid #e2e8f0; padding:15px; border-radius:10px; margin-bottom:20px;">
                    <label class="form-label fw-bold text-primary">هدف‌گیری مخاطبان (چه کسانی این اعلان را ببینند؟)</label>
                    <select name="target_mode" id="targetMode" class="form-control" onchange="toggleTargetBoxes()">
                        <option value="all" <?php echo $currentMode === 'all' ? 'selected' : ''; ?>>ارسال برای همه</option>
                        <option value="departments" <?php echo $currentMode === 'departments' ? 'selected' : ''; ?>>ارسال به دپارتمان(ها)</option>
                        <option value="users" <?php echo $currentMode === 'users' ? 'selected' : ''; ?>>ارسال به کاربر(ان) خاص</option>
                    </select>

                    <div id="box-departments" class="target-box <?php echo $currentMode === 'departments' ? 'active' : ''; ?>">
                        <label class="form-label">انتخاب دپارتمان (چند انتخابی)</label>
                        <select name="target_department_id[]" class="form-control" multiple style="min-height: 120px;">
                            <?php 
                            $curDepts = [];
                            if (!empty($announcement['target_department_id'])) $curDepts = explode(',', $announcement['target_department_id']);
                            ?>
                            <?php foreach($departments as $d): ?>
                                <option value="<?php echo (int)$d['id']; ?>" <?php echo in_array((int)$d['id'], $curDepts) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small text-muted mt-1">برای انتخاب چند دپارتمان، کلید Ctrl را نگه دارید.</div>
                    </div>

                    <div id="box-users" class="target-box <?php echo $currentMode === 'users' ? 'active' : ''; ?>">
                        <label class="form-label">انتخاب کاربران (چند انتخابی)</label>
                        <select name="target_users[]" class="form-control" multiple style="min-height: 150px;">
                            <?php foreach($allUsers as $u): ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo in_array($u['id'], $selectedUsersForEdit) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['username'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small text-muted mt-1">برای انتخاب چند کاربر، کلید Ctrl را نگه دارید.</div>
                    </div>
                </div>

                <div class="form-group mb-4 mt-3">
                    <label class="form-label">فایل پیوست</label>
                    <input type="file" name="attachment" class="form-control">
                    <?php if(!empty($announcement['attachment'])): ?>
                        <div class="mt-2 small text-muted">
                            فایل فعلی: <a href="<?php echo $basePath; ?>uploads/announcements/<?php echo rawurlencode($announcement['attachment']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($announcement['attachment']); ?></a>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="text-align: left;">
                    <button type="submit" class="btn btn-primary px-4"><?php echo $id ? 'ذخیره تغییرات' : 'ارسال اعلان'; ?></button>
                </div>
            </form>
        </div>
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری</footer>
</main>

<script>
    function toggleTargetBoxes() {
        const mode = document.getElementById('targetMode').value;
        document.getElementById('box-departments').classList.remove('active');
        document.getElementById('box-users').classList.remove('active');
        
        if (mode === 'departments') {
            document.getElementById('box-departments').classList.add('active');
        } else if (mode === 'users') {
            document.getElementById('box-users').classList.add('active');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const alertBox = document.getElementById('alertBox');
        if (alertBox) {
            setTimeout(function() {
                alertBox.classList.add('fade-out');
                setTimeout(function() { alertBox.remove(); }, 500);
            }, 4000); 
        }
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>