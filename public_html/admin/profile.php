<?php
/*
 * فایل: public_html/admin/profile.php
 * توضیحات: مدیریت پروفایل کاربر (اصلاح نمایش نقش فارسی + تاریخ دستی)
 */

// شروع بافرینگ
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
// توابع کمکی داخلی
// ---------------------------------------------------------

function faToEnProfile($string) {
    return str_replace(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
        ['0','1','2','3','4','5','6','7','8','9'],
        $string
    );
}

function jalaliToGregorianProfile($jy, $jm, $jd, $mod = '') {
    $jy = (int)$jy; $jm = (int)$jm; $jd = (int)$jd; 
    $d_4 = ($jy + 10) % 33;
    $d_33 = (int)((($jy + 10) % 33) * .0305);
    $a = ($d_33 != 3 and $d_4 <= $d_33) ? 287 : 286;
    $b = (($d_33 == 1 or $d_33 == 2) and ($d_33 == $d_4 or $d_4 == 1)) ? 78 : (($d_33 == 3 and $d_4 == 0) ? 80 : 79);
    if ((int)(($jy - 19) / 63) == 20) { $a--; $b++; }
    if ($d_4 <= $d_33) { $gy = $jy + 621; $gd = $jd + $a; } else { $gy = $jy + 622; $gd = $jd + $b; }
    if ($jm < 7) { $gd += ($jm - 1) * 31; } else { $gd += (($jm - 7) * 30) + 186; }
    $g_m = array(0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    $gy2 = ($g_m[2] == 28) ? $gy + 1 : $gy - 1;
    if ((($gy2 + 3) % 4) == 0) { $g_m[2] = 29; }
    $gm = 0;
    while ($gd > $g_m[$gm]) { $gd -= $g_m[$gm]; $gm++; }
    return ($mod == '') ? array($gy, $gm, $gd) : $gy . $mod . $gm . $mod . $gd;
}

// ---------------------------------------------------------
// پردازش درخواست‌های AJAX (ویرایش پروفایل)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        try {
            $id = $_SESSION['user_id'];
            
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $mobile = faToEnProfile(trim($_POST['mobile'] ?? ''));
            $national_code = faToEnProfile(trim($_POST['national_code'] ?? ''));
            $postal_code = faToEnProfile(trim($_POST['postal_code'] ?? ''));
            $address = trim($_POST['address'] ?? '');
            $password = $_POST['new_password'] ?? '';
            $current_password = $_POST['current_password'] ?? '';
            
            // تبدیل تاریخ تولد
            $birth_date_input = faToEnProfile(trim($_POST['birth_date'] ?? ''));
            $birth_date = null;
            if (!empty($birth_date_input)) {
                $birth_date_input = str_replace('-', '/', $birth_date_input);
                $jParts = explode('/', $birth_date_input);
                
                if (count($jParts) == 3) {
                    $gDateArr = jalaliToGregorianProfile($jParts[0], $jParts[1], $jParts[2]);
                    $birth_date = implode('-', $gDateArr);
                } else {
                     echo json_encode(['status'=>'error', 'message'=>'فرمت تاریخ تولد اشتباه است. مثال: 1370/01/01']); 
                     exit;
                }
            }

            if (empty($first_name) || empty($last_name) || empty($mobile)) {
                echo json_encode(['status'=>'error', 'message'=>'لطفاً فیلدهای ستاره‌دار را پر کنید.']); 
                exit;
            }

            // چک تکراری
            $checkSql = "SELECT id FROM users WHERE (mobile = ? ";
            $checkParams = [$mobile];
            if (!empty($national_code)) {
                $checkSql .= "OR national_code = ? ";
                $checkParams[] = $national_code;
            }
            $checkSql .= ") AND id != ?";
            $checkParams[] = $id;
            
            $stmt = $pdo->prepare($checkSql);
            $stmt->execute($checkParams);
            if ($stmt->rowCount() > 0) {
                echo json_encode(['status'=>'error', 'message'=>'شماره موبایل یا کد ملی تکراری است.']); 
                exit;
            }

            $password_sql = "";
            $password_params = [];
            
            if (!empty($password)) {
                if (empty($current_password)) {
                    echo json_encode(['status'=>'error', 'message'=>'برای تغییر رمز، وارد کردن رمز فعلی الزامی است.']); 
                    exit;
                }
                $stmtPass = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmtPass->execute([$id]);
                $currentUser = $stmtPass->fetch();
                if (!password_verify($current_password, $currentUser['password'])) {
                    echo json_encode(['status'=>'error', 'message'=>'رمز عبور فعلی اشتباه است.']); 
                    exit;
                }
                $password_sql = ", password=?";
                $password_params[] = password_hash($password, PASSWORD_DEFAULT);
            }

            $profile_sql = "";
            $profile_params = [];
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowed)) {
                    $new_name = uniqid() . '.' . $ext;
                    $upload_dir = __DIR__ . '/../../public_html/uploads/profiles/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $new_name)) {
                        $profile_sql = ", profile_image=?";
                        $profile_params[] = $new_name;
                    }
                }
            }

            $sql = "UPDATE users SET first_name=?, last_name=?, mobile=?, national_code=?, birth_date=?, postal_code=?, address=? $password_sql $profile_sql WHERE id=?";
            $params = array_merge(
                [$first_name, $last_name, $mobile, $national_code, $birth_date, $postal_code, $address],
                $password_params,
                $profile_params,
                [$id]
            );

            $pdo->prepare($sql)->execute($params);
            $_SESSION['fullname'] = $first_name . ' ' . $last_name;
            echo json_encode(['status'=>'success', 'message'=>'پروفایل شما با موفقیت بروزرسانی شد.']);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error', 'message'=>'خطای سیستمی: ' . $e->getMessage()]);
        }
        exit;
    }
}

// ---------------------------------------------------------
// بخش نمایش صفحه (GET)
// ---------------------------------------------------------

// دریافت اطلاعات کاربر + نام دپارتمان + نام نقش (از جدول roles)
$sql = "SELECT u.*, d.name as department_name, r.title as role_title 
        FROM users u 
        LEFT JOIN departments d ON u.department_id = d.id 
        LEFT JOIN roles r ON u.role = r.name
        WHERE u.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) { die("کاربر یافت نشد. لطفا مجدد وارد شوید."); }

$todayDate = jdate('Y/m/d');

// تبدیل تاریخ میلادی دیتابیس به شمسی
$birthDateDisplay = '';
if (!empty($user['birth_date']) && $user['birth_date'] != '0000-00-00') {
    $ts = strtotime($user['birth_date']);
    if ($ts !== false) {
        $birthDateDisplay = jdate('Y/m/d', $ts);
    }
}

$imgSrc = ($user['profile_image'] && $user['profile_image'] !== 'default.png' && file_exists(__DIR__ . '/../../public_html/uploads/profiles/' . $user['profile_image'])) 
    ? '../uploads/profiles/' . $user['profile_image'] 
    : '../assets/images/profile-icon.png';

// تعیین نام نقش فارسی (ابتدا از جدول roles، اگر نبود از آرایه مپ)
$roleName = $user['role_title'];
if (empty($roleName)) {
    // لیست کامل نقش‌ها جهت اطمینان
    $roleMap = [
        'admin' => 'مدیر سیستم',
        'user' => 'کاربر عادی',
        'manager' => 'مدیر میانی',
        'warehouse_manager' => 'مدیر انبار',
        'sales_manager' => 'مدیر فروش',
        'finance_manager' => 'مدیر مالی',
        'commerce_manager' => 'مدیر بازرگانی',
        'production_manager' => 'مدیر تولید',
        'sales_expert' => 'کارشناس فروش',
        'finance_expert' => 'کارشناس مالی',
        'commerce_expert' => 'کارشناس بازرگانی',
        'production_expert' => 'کارشناس تولید',
        'warehouse_unit' => 'واحد انبار',
        'it' => 'فناوری اطلاعات'
    ];
    $roleName = $roleMap[$user['role']] ?? $user['role'];
}

$pageTitle = 'پروفایل من';
$basePath = '../';
$extraCss = '<link rel="stylesheet" href="../assets/css/profile.css">';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">پروفایل کاربری</span></div>
            <div>
                <button onclick="openEditModal()" class="btn btn-primary">✏️ ویرایش پروفایل</button>
            </div>
        </div>

        <div class="profile-card page-actions">
            <div class="profile-header-bg"></div>
            <div class="profile-content">
                <div class="profile-header-info">
                    <img src="<?php echo $imgSrc; ?>" class="profile-avatar-large">
                    <div class="profile-name-large"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                    <span class="profile-role-badge">
                        <?php echo htmlspecialchars($roleName); ?> | <?php echo htmlspecialchars($user['department_name'] ?? '-'); ?>
                    </span>
                </div>

                <div class="info-grid">
                    <div class="info-item"><span class="info-label">نام کاربری</span><span class="info-value"><?php echo htmlspecialchars($user['username'] ?? ''); ?></span></div>
                    <div class="info-item"><span class="info-label">شماره موبایل</span><span class="info-value"><?php echo htmlspecialchars($user['mobile'] ?? ''); ?></span></div>
                    <div class="info-item"><span class="info-label">کد پرسنلی</span><span class="info-value"><?php echo htmlspecialchars($user['personnel_code'] ?? '-'); ?></span></div>
                    <div class="info-item"><span class="info-label">کد ملی</span><span class="info-value"><?php echo htmlspecialchars($user['national_code'] ?? '-'); ?></span></div>
                    <div class="info-item"><span class="info-label">دپارتمان</span><span class="info-value"><?php echo htmlspecialchars($user['department_name'] ?? '-'); ?></span></div>
                    <div class="info-item"><span class="info-label">تاریخ تولد</span><span class="info-value"><?php echo htmlspecialchars($birthDateDisplay); ?></span></div>
                    <div class="info-item"><span class="info-label">کد پستی</span><span class="info-value"><?php echo htmlspecialchars($user['postal_code'] ?? '-'); ?></span></div>
                    <div class="info-item" style="grid-column: 1 / -1;"><span class="info-label">آدرس</span><span class="info-value"><?php echo htmlspecialchars($user['address'] ?? '-'); ?></span></div>
                </div>
            </div>
        </div>
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری آتنا زیست درمان</footer>
</main>

<!-- مودال ویرایش پروفایل -->
<div class="modal-overlay" id="profileModal">
    <div class="modal">
        <div class="modal-header"><h3 id="modalTitle">ویرایش اطلاعات فردی</h3><span class="close-modal" onclick="closeModal()">&times;</span></div>
        <div id="modalMessage"></div>

        <form method="POST" enctype="multipart/form-data" id="profileForm">
            <input type="hidden" name="action" value="update_profile">
            
            <div class="profile-upload">
                <img src="<?php echo $imgSrc; ?>" id="modalPreview">
                <div><label class="form-label" style="margin-bottom:5px;">تغییر تصویر</label><input type="file" name="profile_image" accept="image/*" onchange="previewProfileImage(this)"></div>
            </div>

            <div class="modal-form-row">
                <div class="modal-form-col form-group"><label class="form-label">نام <span style="color:red">*</span></label><input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required></div>
                <div class="modal-form-col form-group"><label class="form-label">نام خانوادگی <span style="color:red">*</span></label><input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required></div>
            </div>

            <div class="modal-form-row">
                <div class="modal-form-col form-group">
                    <label class="form-label">نام کاربری (غیرقابل تغییر)</label>
                    <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" disabled style="background:#f3f4f6;">
                </div>
                <div class="modal-form-col form-group">
                    <label class="form-label">موبایل <span style="color:red">*</span></label>
                    <input type="text" name="mobile" id="mobile" class="form-control" value="<?php echo htmlspecialchars($user['mobile'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="modal-form-row">
                <div class="modal-form-col form-group"><label class="form-label">کد ملی</label><input type="text" name="national_code" id="nationalCode" class="form-control" value="<?php echo htmlspecialchars($user['national_code'] ?? ''); ?>"></div>
                <div class="modal-form-col form-group"><label class="form-label">تاریخ تولد (شمسی)</label>
                    <input type="text" name="birth_date" id="birthDate" class="form-control" value="<?php echo htmlspecialchars($birthDateDisplay); ?>" placeholder="مثال: 1370/01/01" autocomplete="off">
                </div>
            </div>

            <div class="password-section">
                <span class="password-title">تغییر رمز عبور (اختیاری)</span>
                <div class="modal-form-row">
                    <div class="modal-form-col form-group"><label class="form-label">رمز عبور فعلی</label><input type="password" name="current_password" class="form-control" placeholder="رمز فعلی"></div>
                    <div class="modal-form-col form-group"><label class="form-label">رمز عبور جدید</label><input type="password" name="new_password" class="form-control" placeholder="رمز جدید"></div>
                </div>
            </div>

            <div class="modal-form-row">
                <div class="modal-form-col form-group"><label class="form-label">کد پستی</label><input type="text" name="postal_code" class="form-control" value="<?php echo htmlspecialchars($user['postal_code'] ?? ''); ?>"></div>
            </div>
            <div class="form-group"><label class="form-label">آدرس</label><input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>"></div>

            <div style="text-align: left; margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeModal()" class="btn btn-outline">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/profile.js"></script>
<script>
    function toggleProfileMenu(e) { e && e.stopPropagation(); document.getElementById('profileDropdown').classList.toggle('active'); }
    window.addEventListener('click', e => { if(!e.target.closest('.profile-container')) document.getElementById('profileDropdown').classList.remove('active'); });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>