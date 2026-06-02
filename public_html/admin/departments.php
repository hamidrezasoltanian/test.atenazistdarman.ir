<?php
/*
 * فایل: public_html/admin/departments.php
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
    if ($isAjax) { echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شد']); exit; }
    header("Location: ../login.php"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $id = $_POST['id'];
        try {
            $pdo->prepare("DELETE FROM departments WHERE id=?")->execute([$id]);
            $msg = 'دپارتمان حذف شد.'; $msgType = 'success';
        } catch (Exception $e) {
            $msg = 'این دپارتمان قابل حذف نیست (ممکن است کاربرانی داشته باشد).'; $msgType = 'error';
        }
    }

    if ($isAjax) {
        $action = $_POST['action'];
        if ($action === 'add' || $action === 'edit') {
            try {
                $name = trim($_POST['name']);
                $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : null;
                $status = $_POST['status'];
                $id = $_POST['id'] ?? null;

                if (empty($name)) { echo json_encode(['status'=>'error', 'message'=>'نام دپارتمان الزامی است.']); exit; }

                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO departments (name, manager_id, status) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $manager_id, $status]);
                    echo json_encode(['status'=>'success', 'message'=>'دپارتمان ایجاد شد.']);
                } else {
                    $stmt = $pdo->prepare("UPDATE departments SET name=?, manager_id=?, status=? WHERE id=?");
                    $stmt->execute([$name, $manager_id, $status, $id]);
                    echo json_encode(['status'=>'success', 'message'=>'دپارتمان ویرایش شد.']);
                }
            } catch (Exception $e) {
                echo json_encode(['status'=>'error', 'message'=>'خطا: ' . $e->getMessage()]);
            }
            exit;
        }
    }
}

$todayDate = jdate('Y/m/d');
$msg = ''; $msgType = '';

$menuItems = [];
$menuPath = __DIR__ . '/../../includes/menu_config.php';
if (file_exists($menuPath)) $menuItems = require $menuPath;

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'];
$role = isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? 'مدیر سیستم' : 'کاربر';

$stmtMe = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
$stmtMe->execute([$_SESSION['user_id']]);
$me = $stmtMe->fetch();
$profileImage = ($me && $me['profile_image'] && $me['profile_image'] !== 'default.png' && file_exists(__DIR__ . '/../../public_html/uploads/profiles/' . $me['profile_image'])) 
    ? '../uploads/profiles/' . $me['profile_image'] 
    : '../assets/images/profile-icon.png';

if (!function_exists('hasPermission')) { function hasPermission($perm) { return true; } }

// --- جستجو و لیست ---
$search = $_GET['q'] ?? '';
$where = '';
$params = [];
if ($search) {
    $where = "WHERE d.name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$baseSql = "SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) as manager_name 
            FROM departments d 
            LEFT JOIN users u ON d.manager_id = u.id 
            $where ORDER BY d.id ASC";

// لیست کامل برای پرینت
$stmtAll = $pdo->prepare($baseSql);
$stmtAll->execute($params);
$allDepts = $stmtAll->fetchAll();

// صفحه‌بندی
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 5; 
$offset = ($page - 1) * $limit;

$stmtPage = $pdo->prepare("$baseSql LIMIT $limit OFFSET $offset");
$stmtPage->execute($params);
$deptList = $stmtPage->fetchAll();

$totalRows = count($allDepts);
$totalPages = ceil($totalRows / $limit);

// لیست کاربران برای انتخاب مدیر
$users = $pdo->query("SELECT id, first_name, last_name FROM users WHERE status='active'")->fetchAll();

$pageTitle = 'مدیریت دپارتمان‌ها';
$basePath = '../';
$extraCss = '<style>
    @media print {
        .print-table-container { display: block !important; }
    }
    @media (max-width: 768px) {
        .search-box { flex-direction: column; gap: 10px; }
        .search-box input { width: 100%; }
        .search-box button, .search-box a { width: 100%; justify-content: center; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">مدیریت دپارتمان‌ها</span></div>
            <div>
                <button onclick="window.print()" class="btn btn-secondary">🖨️ پرینت</button>
                <button onclick="openModal('add')" class="btn btn-primary">+ دپارتمان جدید</button>
            </div>
        </div>

        <?php if($msg): ?><div class="alert alert-<?php echo $msgType; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

        <div class="page-header page-actions">
            <form method="GET" class="search-box">
                <input type="text" name="q" class="search-input" placeholder="جستجو در عنوان یا مدیر..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary">جستجو</button>
                <?php if($search): ?><a href="departments.php" class="btn btn-outline">حذف فیلتر</a><?php endif; ?>
            </form>
        </div>

        <!-- جدول نمایش در صفحه -->
        <div class="table-responsive screen-table">
            <table>
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>عنوان دپارتمان</th>
                        <th>مدیر</th>
                        <th>وضعیت</th>
                        <th class="btn-action">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deptList as $d): ?>
                    <tr>
                        <td data-label="شناسه"><?php echo $d['id']; ?></td>
                        <td data-label="عنوان"><?php echo htmlspecialchars($d['name']); ?></td>
                        <td data-label="مدیر"><?php echo $d['manager_name'] ?? '-'; ?></td>
                        <td data-label="وضعیت"><span class="status-badge status-<?php echo $d['status']; ?>"><?php echo $d['status']=='active'?'فعال':'غیرفعال'; ?></span></td>
                        <td class="btn-action" data-label="عملیات">
                            <button onclick='editDept(<?php echo htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8'); ?>)' class="btn btn-success" style="font-size: 0.8rem;">ویرایش</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف این دپارتمان اطمینان دارید؟');">
                                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                <button type="submit" class="btn btn-danger" style="font-size: 0.8rem;">حذف</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- جدول پرینت (مخفی) -->
        <div class="print-table-container">
            <table class="print-table">
                <thead><tr><th>شناسه</th><th>عنوان دپارتمان</th><th>مدیر</th><th>وضعیت</th></tr></thead>
                <tbody>
                    <?php foreach ($allDepts as $d): ?>
                    <tr>
                        <td><?php echo $d['id']; ?></td>
                        <td><?php echo htmlspecialchars($d['name']); ?></td>
                        <td><?php echo $d['manager_name'] ?? '-'; ?></td>
                        <td><?php echo $d['status']=='active'?'فعال':'غیرفعال'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination page-actions">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?><a href="?page=<?php echo $i; ?>&q=<?php echo htmlspecialchars($search); ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a><?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری آتنا زیست درمان</footer>
</main>

<!-- مودال دپارتمان (تمام صفحه) -->
<div class="modal-overlay" id="deptModal">
    <div class="modal">
        <div class="modal-header"><h3 id="modalTitle">افزودن دپارتمان</h3><span class="close-modal" onclick="closeModal()">&times;</span></div>
        <div id="modalMessage"></div>
        <form method="POST" id="deptForm" onsubmit="return submitDeptForm(event)">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="deptId">
            <div class="form-group">
                <label class="form-label">عنوان دپارتمان <span style="color:red">*</span></label>
                <input type="text" name="name" id="deptName" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">مدیر دپارتمان</label>
                <select name="manager_id" id="deptManager" class="form-control">
                    <option value="">-- انتخاب کنید --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">وضعیت</label>
                <select name="status" id="deptStatus" class="form-control">
                    <option value="active">فعال</option>
                    <option value="inactive">غیرفعال</option>
                </select>
            </div>
            <div style="text-align: left; margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeModal()" class="btn btn-outline">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره اطلاعات</button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/departments.js"></script>
<script>
    window.addEventListener('beforeprint', function() {
        const headerTitle = document.querySelector('.print-header-center');
        if(headerTitle) headerTitle.innerText = 'گزارش لیست دپارتمان‌ها';
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>