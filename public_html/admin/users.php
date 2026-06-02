<?php
/*
 * فایل: public_html/admin/users.php
 * توضیحات: مدیریت کاربران + تاریخ شمسی دستی + بدون خطا
 */

// شروع بافرینگ برای جلوگیری از خطای هدر و JSON
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

$dbPath = __DIR__ . '/../../includes/db.php';
$funcPath = __DIR__ . '/../../includes/functions.php';

if (!file_exists($dbPath) || !file_exists($funcPath)) {
    die("خطا: فایل‌های سیستمی یافت نشدند.");
}

require_once $dbPath;
require_once $funcPath;

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { 
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است. لطفا مجدد وارد شوید.']); 
        exit; 
    }
    header("Location: ../login.php"); 
    exit;
}

// ---------------------------------------------------------
// توابع کمکی داخلی (برای جلوگیری از خطای Call to undefined function)
// ---------------------------------------------------------

// تبدیل اعداد فارسی به انگلیسی
function faToEnUser($string) {
    return str_replace(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
        ['0','1','2','3','4','5','6','7','8','9'],
        $string
    );
}

// تبدیل تاریخ شمسی به میلادی (الگوریتم دقیق)
function jalaliToGregorianUser($jy, $jm, $jd, $mod = '') {
    $jy = (int)$jy; $jm = (int)$jm; $jd = (int)$jd; 
    $jy += 1595;
    $days = -355668 + (365 * $jy) + ((int)($jy / 33) * 8) + (int)((($jy % 33) + 3) / 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * (int)($days / 146097);
    $days %= 146097;
    if ($days > 36524) {
        $days--;
        $gy += 100 * (int)($days / 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 0; $gm < 13; $gm++) {
        $v = $sal_a[$gm];
        if ($gd <= $v) break;
        $gd -= $v;
    }
    return ($mod == '') ? array($gy, $gm, $gd) : $gy . $mod . $gm . $mod . $gd;
}

// ---------------------------------------------------------
// پردازش درخواست‌های POST
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- حذف کاربر ---
    if (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $id = $_POST['id'];
        
        if ($id == $_SESSION['user_id']) {
            header("Location: users.php?error=cannot_delete_self");
            exit;
        }

        $stmtCheck = $pdo->prepare("SELECT role FROM users WHERE id=?");
        $stmtCheck->execute([$id]);
        $target = $stmtCheck->fetch();
        
        if ($target && $target['role'] === 'admin') {
             header("Location: users.php?error=cannot_delete_admin");
             exit;
        } else {
            try {
                $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            } catch (Exception $e) { }
            header("Location: users.php?msg=deleted");
            exit;
        }
    }
    
    // --- افزودن / ویرایش (AJAX) ---
    if ($isAjax) {
        // پاکسازی کامل بافر قبل از ارسال JSON
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            try {
                $id = $_POST['id'] ?? null;
                $first_name = trim($_POST['first_name'] ?? '');
                $last_name = trim($_POST['last_name'] ?? '');
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $mobile = faToEnUser(trim($_POST['mobile'] ?? ''));
                $national_code = faToEnUser(trim($_POST['national_code'] ?? ''));
                $personnel_code = faToEnUser(trim($_POST['personnel_code'] ?? ''));
                
                // تبدیل تاریخ تولد (شمسی به میلادی) برای ذخیره
                $birth_date_input = faToEnUser($_POST['birth_date'] ?? '');
                $birth_date = null;
                if (!empty($birth_date_input)) {
                     $jParts = explode('/', $birth_date_input);
                     if(count($jParts) == 3) {
                         // استفاده از تابع داخلی همین فایل
                         $gDateArr = jalaliToGregorianUser($jParts[0], $jParts[1], $jParts[2]);
                         $birth_date = implode('-', $gDateArr);
                     }
                }

                $postal_code = faToEnUser(trim($_POST['postal_code'] ?? ''));
                $address = trim($_POST['address'] ?? '');
                $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
                $role_user = $_POST['role'] ?? 'user';
                $status = $_POST['status'] ?? 'active';

                if (empty($first_name) || empty($last_name) || empty($username) || empty($mobile) || empty($personnel_code)) {
                    echo json_encode(['status'=>'error', 'message'=>'لطفاً تمام فیلدهای ستاره‌دار را پر کنید.']);
                    exit;
                }

                // بررسی تکراری بودن
                $checkSql = "SELECT id FROM users WHERE (username = ? OR mobile = ? OR personnel_code = ?";
                $checkParams = [$username, $mobile, $personnel_code];
                
                if (!empty($national_code)) {
                    $checkSql .= " OR national_code = ?";
                    $checkParams[] = $national_code;
                }
                $checkSql .= ")";

                if ($action === 'edit') {
                    $checkSql .= " AND id != ?";
                    $checkParams[] = $id;
                }

                $stmtCheck = $pdo->prepare($checkSql);
                $stmtCheck->execute($checkParams);
                if ($stmtCheck->rowCount() > 0) {
                    echo json_encode(['status'=>'error', 'message'=>'نام کاربری، موبایل، کد ملی یا کد پرسنلی تکراری است.']);
                    exit;
                }

                // آپلود عکس
                $profile_img_name = null;
                $has_new_image = false;
                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
                    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                    $filename = $_FILES['profile_image']['name'];
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    if (in_array($ext, $allowed)) {
                        $new_name = uniqid() . '.' . $ext;
                        $upload_dir = __DIR__ . '/../../public_html/uploads/profiles/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        
                        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $new_name)) {
                            $profile_img_name = $new_name;
                            $has_new_image = true;
                        } else {
                            echo json_encode(['status'=>'error', 'message'=>'خطا در آپلود عکس.']); exit;
                        }
                    } else {
                        echo json_encode(['status'=>'error', 'message'=>'فرمت عکس مجاز نیست.']); exit;
                    }
                }

                if ($action === 'add') {
                    if (empty($password)) {
                        echo json_encode(['status'=>'error', 'message'=>'رمز عبور برای کاربر جدید الزامی است.']); exit;
                    }
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $img = $has_new_image ? $profile_img_name : 'default.png';
                    
                    $sql = "INSERT INTO users (first_name, last_name, username, password, mobile, national_code, personnel_code, birth_date, postal_code, address, department_id, role, status, profile_image) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$first_name, $last_name, $username, $hashed, $mobile, $national_code, $personnel_code, $birth_date, $postal_code, $address, $department_id, $role_user, $status, $img]);
                    
                    echo json_encode(['status'=>'success', 'message'=>'کاربر جدید با موفقیت ایجاد شد.']);
                
                } else {
                    $sql = "UPDATE users SET first_name=?, last_name=?, username=?, mobile=?, national_code=?, personnel_code=?, birth_date=?, postal_code=?, address=?, department_id=?, role=?, status=?";
                    $params = [$first_name, $last_name, $username, $mobile, $national_code, $personnel_code, $birth_date, $postal_code, $address, $department_id, $role_user, $status];
                    
                    if (!empty($password)) {
                        $sql .= ", password=?";
                        $params[] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    if ($has_new_image) {
                        $sql .= ", profile_image=?";
                        $params[] = $profile_img_name;
                    }
                    
                    $sql .= " WHERE id=?";
                    $params[] = $id;
                    
                    $pdo->prepare($sql)->execute($params);
                    echo json_encode(['status'=>'success', 'message'=>'اطلاعات کاربر با موفقیت ویرایش شد.']);
                }

            } catch (Exception $e) {
                echo json_encode(['status'=>'error', 'message'=>'خطای سیستمی: ' . $e->getMessage()]);
            }
            exit;
        }
    }
}

// ---------------------------------------------------------
// بخش نمایش صفحه (GET)
// ---------------------------------------------------------

$todayDate = jdate('Y/m/d');
$msg = ''; $msgType = '';

if (isset($_GET['msg']) && $_GET['msg'] == 'deleted') {
    $msg = 'کاربر با موفقیت حذف شد.'; $msgType = 'success';
}
if (isset($_GET['error']) && $_GET['error'] == 'cannot_delete_admin') {
    $msg = 'خطا: حذف مدیر سیستم امکان‌پذیر نیست.'; $msgType = 'error';
}

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

// پرینت (AJAX)
if (isset($_GET['print_data'])) {
    if (ob_get_length()) ob_clean();
    
    $search = $_GET['q'] ?? '';
    $where = '';
    $params = [];
    if ($search) {
        $where = "WHERE u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.mobile LIKE ?";
        $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
    }
    
    $sql = "SELECT u.*, d.name as department_name, r.title as role_title 
            FROM users u 
            LEFT JOIN departments d ON u.department_id = d.id 
            LEFT JOIN roles r ON u.role = r.name
            $where 
            ORDER BY u.id ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $all = $stmt->fetchAll();
    
    foreach ($all as $u) {
        $dept = $u['department_name'] ?? '-';
        $roleDisplay = $u['role_title'] ?? $u['role'];
        if (empty($roleDisplay)) {
            $roleMap = ['admin'=>'مدیر سیستم', 'user'=>'کاربر', 'manager'=>'مدیر میانی'];
            $roleDisplay = $roleMap[$u['role']] ?? $u['role'];
        }
        
        echo "<tr>
            <td>".htmlspecialchars($u['personnel_code'])."</td>
            <td>".htmlspecialchars($u['first_name'] . ' ' . $u['last_name'])."</td>
            <td>".htmlspecialchars($u['national_code'])."</td>
            <td>".htmlspecialchars($u['mobile'])."</td>
            <td>".htmlspecialchars($dept)."</td>
            <td>".htmlspecialchars($roleDisplay)."</td>
        </tr>";
    }
    exit;
}

$search = $_GET['q'] ?? '';
$where = '';
$params = [];
if ($search) {
    $where = "WHERE u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.mobile LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

// کوئری پایه
$baseSql = "SELECT u.*, d.name as department_name, r.title as role_title 
            FROM users u 
            LEFT JOIN departments d ON u.department_id = d.id 
            LEFT JOIN roles r ON u.role = r.name
            $where 
            ORDER BY u.id ASC";

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 5; 
$offset = ($page - 1) * $limit;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$stmtPage = $pdo->prepare("$baseSql LIMIT $limit OFFSET $offset");
$stmtPage->execute($params);
$usersRaw = $stmtPage->fetchAll();

// پردازش داده‌ها برای نمایش (تبدیل تاریخ میلادی به شمسی)
$usersList = [];
foreach ($usersRaw as $row) {
    if (!empty($row['birth_date']) && $row['birth_date'] != '0000-00-00') {
        // تبدیل برای نمایش
        $ts = strtotime($row['birth_date']);
        $row['birth_date'] = jdate('Y/m/d', $ts);
    } else {
        $row['birth_date'] = '';
    }
    $usersList[] = $row;
}

$depts = $pdo->query("SELECT id, name FROM departments WHERE status='active'")->fetchAll();
$rolesList = $pdo->query("SELECT name, title FROM roles ORDER BY id ASC")->fetchAll();

$pageTitle = 'مدیریت کاربران';
$basePath = '../';
$extraCss = '<link rel="stylesheet" href="../vendor/kamadatepicker/kamadatepicker.min.css">';
$extraCss .= '<style>
    .profile-upload { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #f0f0f0; }
    .profile-upload img { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 2px solid #ddd; }
    .row { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 10px; }
    .col { flex: 1; min-width: 200px; }
    .form-group { margin-bottom: 5px; }
    @media print {
        .print-table-container { display: block !important; width: 100%; }
        .print-table { width: 100%; border-collapse: collapse; }
        .print-table th, .print-table td { border: 1px solid #000; padding: 5px; text-align: center; }
    }
    #bd-root { z-index: 2000000 !important; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

    <main class="main-content" id="mainContent">
        <div class="content-wrapper">
            <div class="page-header page-actions">
                <div><span class="page-title">مدیریت کاربران</span></div>
                <div>
                    <button onclick="printAllUsers()" class="btn btn-secondary">🖨️ پرینت</button>
                    <button onclick="openModal('add')" class="btn btn-primary">+ کاربر جدید</button>
                </div>
            </div>

            <?php if($msg): ?><div class="alert alert-<?php echo $msgType; ?>"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

            <div class="page-header page-actions">
                <form method="GET" class="search-box">
                    <input type="text" name="q" class="search-input" placeholder="جستجو..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary">جستجو</button>
                    <?php if($search): ?><a href="users.php" class="btn btn-outline">حذف فیلتر</a><?php endif; ?>
                </form>
            </div>

            <div class="table-responsive screen-table">
                <table>
                    <thead>
                        <tr>
                            <th>شناسه</th>
                            <th>تصویر</th>
                            <th>نام کامل</th>
                            <th>نام کاربری</th>
                            <th>دپارتمان</th>
                            <th>نقش</th>
                            <th>وضعیت</th>
                            <th class="btn-action">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersList as $u): 
                            $imgSrc = ($u['profile_image'] && $u['profile_image']!='default.png' && file_exists(__DIR__ . '/../../public_html/uploads/profiles/' . $u['profile_image'])) 
                                ? '../uploads/profiles/' . $u['profile_image'] 
                                : '../assets/images/profile-icon.png';
                            
                            $roleDisplay = $u['role_title'] ?? $u['role'];
                            if (empty($roleDisplay)) {
                                if($u['role'] == 'admin') $roleDisplay = 'مدیر سیستم';
                                elseif($u['role'] == 'user') $roleDisplay = 'کاربر عادی';
                            }
                        ?>
                        <tr>
                            <td data-label="شناسه"><?php echo $u['id']; ?></td>
                            <td data-label="تصویر"><img src="<?php echo $imgSrc; ?>" class="table-img"></td>
                            <td data-label="نام"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></td>
                            <td data-label="نام کاربری"><?php echo htmlspecialchars($u['username']); ?></td>
                            <td data-label="دپارتمان"><?php echo $u['department_name'] ?? '-'; ?></td>
                            <td data-label="نقش" style="color:#2563eb; font-weight:500;"><?php echo htmlspecialchars($roleDisplay); ?></td>
                            <td data-label="وضعیت"><span class="status-badge status-<?php echo $u['status']; ?>"><?php echo $u['status']=='active'?'فعال':'غیرفعال'; ?></span></td>
                            <td class="btn-action" data-label="عملیات">
                                <button onclick='editUser(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8'); ?>)' class="btn btn-success" style="font-size: 0.8rem;">ویرایش</button>
                                <?php if($u['role'] !== 'admin'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف اطمینان دارید؟');">
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                    <button type="submit" class="btn btn-danger" style="font-size: 0.8rem;">حذف</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- جدول پرینت (بدنه خالی، با AJAX پر می‌شود) -->
            <div class="print-table-container">
                <table class="print-table">
                    <thead>
                        <tr>
                            <th>کد پرسنلی</th>
                            <th>نام کامل</th>
                            <th>کد ملی</th>
                            <th>موبایل</th>
                            <th>دپارتمان</th>
                            <th>نقش</th>
                        </tr>
                    </thead>
                    <tbody id="printTableBody"></tbody>
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

    <div class="print-footer">© 2026 سامانه اتوماسیون اداری آتنا زیست درمان. نسخه 1.0.0</div>

    <!-- مودال کاربر -->
    <div class="modal-overlay" id="userModal">
        <div class="modal">
            <div class="modal-header"><h3 id="modalTitle">افزودن کاربر</h3><span class="close-modal" onclick="closeModal()">&times;</span></div>
            <div id="modalMessage"></div>
            <form method="POST" enctype="multipart/form-data" id="userForm" onsubmit="return submitUserForm(event)">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="userId">
                <div class="profile-upload">
                    <img src="../assets/images/profile-icon.png" id="profilePreview">
                    <div><label class="form-label" style="margin-bottom:5px;">تصویر پروفایل</label><input type="file" name="profile_image" accept="image/*" onchange="previewImage(this)"></div>
                </div>
                <div class="row">
                    <div class="col form-group"><label class="form-label">نام <span style="color:red">*</span></label><input type="text" name="first_name" id="firstName" class="form-control" required></div>
                    <div class="col form-group"><label class="form-label">نام خانوادگی <span style="color:red">*</span></label><input type="text" name="last_name" id="lastName" class="form-control" required></div>
                </div>
                <div class="row">
                    <div class="col form-group"><label class="form-label">نام کاربری <span style="color:red">*</span></label><input type="text" name="username" id="username" class="form-control" required></div>
                    <div class="col form-group"><label class="form-label">رمز عبور</label><input type="password" name="password" id="password" class="form-control"><small id="passwordHelp" style="display:none;font-size:0.7rem;">فقط جهت تغییر وارد کنید.</small></div>
                </div>
                <div class="row">
                    <div class="col form-group"><label class="form-label">موبایل <span style="color:red">*</span></label><input type="text" name="mobile" id="mobile" class="form-control" placeholder="09xxxxxxxxx" required></div>
                    <div class="col form-group"><label class="form-label">کد ملی</label><input type="text" name="national_code" id="nationalCode" class="form-control" maxlength="10"></div>
                </div>
                <div class="row">
                    <div class="col form-group"><label class="form-label">کد پرسنلی <span style="color:red">*</span></label><input type="text" name="personnel_code" id="personnelCode" class="form-control" required></div>
                    <div class="col form-group"><label class="form-label">تاریخ تولد</label>
                        <input type="text" name="birth_date" id="birthDate" class="form-control" autocomplete="off" placeholder="انتخاب کنید">
                    </div>
                </div>
                <div class="row">
                    <div class="col form-group"><label class="form-label">آدرس</label><input type="text" name="address" id="address" class="form-control"></div>
                    <div class="col form-group"><label class="form-label">کد پستی</label><input type="text" name="postal_code" id="postalCode" class="form-control"></div>
                </div>
                <div class="row">
                    <div class="col form-group">
                        <label class="form-label">دپارتمان <span style="color:red">*</span></label>
                        <select name="department_id" id="department" class="form-control" required>
                            <option value="">انتخاب...</option>
                            <?php foreach ($depts as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col form-group">
                        <label class="form-label">نقش <span style="color:red">*</span></label>
                        <select name="role" id="role" class="form-control" required>
                            <option value="">انتخاب نقش...</option>
                            <?php foreach ($rolesList as $r): ?><option value="<?php echo $r['name']; ?>"><?php echo htmlspecialchars($r['title']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col form-group">
                        <label class="form-label">وضعیت</label>
                        <select name="status" id="status" class="form-control"><option value="active">فعال</option><option value="inactive">غیرفعال</option></select>
                    </div>
                </div>
                <div style="text-align: left; margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">انصراف</button>
                    <button type="submit" class="btn btn-primary">ذخیره اطلاعات</button>
                </div>
            </form>
        </div>
    </div>

<script src="../assets/js/users.js"></script>
<?php if(!defined('JQUERY_LOADED')): define('JQUERY_LOADED', true); ?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<?php endif; ?>
<script src="../vendor/kamadatepicker/kamadatepicker.min.js"></script>
<script>
    function toggleProfileMenu(e) { e && e.stopPropagation(); document.getElementById('profileDropdown').classList.toggle('active'); }
    window.addEventListener('click', e => { if(!e.target.closest('.profile-container')) document.getElementById('profileDropdown').classList.remove('active'); });
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>