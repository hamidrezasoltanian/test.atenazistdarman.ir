<?php
/*
 * فایل: public_html/admin/crm_contacts.php
 * توضیحات: ماژول CRM - مدیریت استان‌ها برای پرسنل فروش (انحصار همه استان‌ها به جز تهران)
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است.']); exit; }
    header("Location: ../login.php"); exit;
}

// ساخت و اصلاح ساختار جدول برای پشتیبانی از چند کاربره بودن تهران
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_user_provinces` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `province_name` varchar(100) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `user_id_idx` (`user_id`),
        CONSTRAINT `fk_crm_prov_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    // حذف کلید یکتای قبلی که مانع از تخصیص یک استان به چند نفر می‌شد
    try { $pdo->exec("ALTER TABLE `crm_user_provinces` DROP INDEX `unique_province`"); } catch (Throwable $e) {}
    // افزودن کلید یکتای ترکیبی (هر کاربر یک استان را فقط یکبار داشته باشد)
    try { $pdo->exec("ALTER TABLE `crm_user_provinces` ADD UNIQUE KEY `unique_user_prov` (`user_id`, `province_name`)"); } catch (Throwable $e) {}
} catch (Throwable $e) {}

// ==================== دریافت استان‌ها از دیتابیس ====================
$errorMessage = '';
$allProvinces = [];

try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'customer_addresses'");
    if ($checkTable->rowCount() == 0) {
        $errorMessage = '❌ جدول customer_addresses در دیتابیس وجود ندارد.';
    } else {
        $stmtProvinces = $pdo->query("SELECT DISTINCT state FROM customer_addresses WHERE state IS NOT NULL AND state != '' AND TRIM(state) != '' ORDER BY state ASC");
        $allProvinces = $stmtProvinces->fetchAll(PDO::FETCH_COLUMN);
        $allProvinces = array_values(array_filter(array_unique($allProvinces)));
        if (empty($allProvinces)) {
            $errorMessage = '⚠️ هیچ استانی در جدول customer_addresses ثبت نشده است.';
        }
    }
} catch (PDOException $e) { $errorMessage = '❌ خطا: ' . $e->getMessage(); }

$salesRoleCondition = " AND u.role IN (SELECT name FROM roles WHERE permissions LIKE '%crm_contacts%' OR name LIKE '%sales%') ";

// --- پردازش درخواست‌های AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'get_form_data') {
        $editUserId = (int)($_POST['user_id'] ?? 0);
        
        // استخراج استان‌هایی که توسط بقیه رزرو شده (به جز تهران)
        $stmtTaken = $pdo->prepare("SELECT province_name FROM crm_user_provinces WHERE user_id != ? AND province_name != 'تهران'");
        $stmtTaken->execute([$editUserId]);
        $takenProvinces = $stmtTaken->fetchAll(PDO::FETCH_COLUMN);

        $availableProvinces = array_diff($allProvinces, $takenProvinces);

        $myProvinces = [];
        if ($editUserId > 0) {
            $stmtMy = $pdo->prepare("SELECT province_name FROM crm_user_provinces WHERE user_id = ?");
            $stmtMy->execute([$editUserId]);
            $myProvinces = $stmtMy->fetchAll(PDO::FETCH_COLUMN);
        }

        $usersQuery = "SELECT id, first_name, last_name, personnel_code FROM users u WHERE status='active' $salesRoleCondition AND (u.id NOT IN (SELECT DISTINCT user_id FROM crm_user_provinces) OR u.id = ?) ORDER BY last_name ASC";
        $stmtUsers = $pdo->prepare($usersQuery);
        $stmtUsers->execute([$editUserId]);
        $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'available_provinces' => array_values($availableProvinces), 'my_provinces' => $myProvinces, 'users' => $users]);
        exit;
    }

    if ($action === 'save_assignment') {
        $u_id = (int)($_POST['user_id'] ?? 0);
        $provinces = isset($_POST['provinces']) && is_array($_POST['provinces']) ? $_POST['provinces'] : [];
        if ($u_id <= 0) { echo json_encode(['status' => 'error', 'message' => 'کاربر را انتخاب کنید.']); exit; }

        try {
            $pdo->beginTransaction();
            $stmtCurrent = $pdo->prepare("SELECT province_name FROM crm_user_provinces WHERE user_id = ?");
            $stmtCurrent->execute([$u_id]);
            $currentProvinces = $stmtCurrent->fetchAll(PDO::FETCH_COLUMN);
            
            $toDelete = array_diff($currentProvinces, $provinces);
            $toAdd = array_diff($provinces, $currentProvinces);
            
            if (!empty($toDelete)) {
                $deletePlaceholders = implode(',', array_fill(0, count($toDelete), '?'));
                $stmtDel = $pdo->prepare("DELETE FROM crm_user_provinces WHERE user_id = ? AND province_name IN ($deletePlaceholders)");
                $params = array_merge([$u_id], $toDelete);
                $stmtDel->execute($params);
            }
            
            if (!empty($toAdd)) {
                $stmtIns = $pdo->prepare("INSERT INTO crm_user_provinces (user_id, province_name) VALUES (?, ?)");
                foreach ($toAdd as $prov) {
                    if ($prov === 'تهران') {
                        $stmtIns->execute([$u_id, $prov]);
                    } else {
                        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM crm_user_provinces WHERE province_name = ? AND user_id != ?");
                        $stmtCheck->execute([$prov, $u_id]);
                        if ($stmtCheck->fetchColumn() == 0) {
                            $stmtIns->execute([$u_id, $prov]);
                        } else {
                            throw new Exception("استان {$prov} قبلاً تخصیص داده شده است.");
                        }
                    }
                }
            }
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'ذخیره شد.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_assignment') {
        $u_id = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_user_provinces WHERE user_id = ?")->execute([$u_id]);
        echo json_encode(['status' => 'success']); exit;
    }
}

$search = trim($_GET['q'] ?? '');
$params = [];
$searchQuery = "";
if ($search) {
    $searchQuery = " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.personnel_code LIKE ?) ";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$sql = "SELECT u.id, u.first_name, u.last_name, u.personnel_code, u.profile_image, u.role, d.name as department_name, r.title as role_title,
        GROUP_CONCAT(DISTINCT cp.province_name SEPARATOR ',') as assigned_provinces
        FROM users u 
        INNER JOIN crm_user_provinces cp ON u.id = cp.user_id
        LEFT JOIN departments d ON u.department_id = d.id 
        LEFT JOIN roles r ON u.role = r.name
        WHERE u.status = 'active' $salesRoleCondition $searchQuery
        GROUP BY u.id ORDER BY u.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$assignedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'تخصیص استان (CRM)';
$basePath = '../';
$extraCss = '<link href="../assets/css/select2.min.css" rel="stylesheet" />
<style>
    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    .table-responsive { width: 100%; overflow-x: auto; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-top:20px;}
    .c-table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .c-table th, .c-table td { padding: 12px 15px; text-align: right; border-bottom: 1px solid #f1f5f9; vertical-align: middle;}
    .c-table th { background: #f8fafc; color: #475569; font-weight: 800; font-size: 0.9rem; text-align: right; }
    .user-info-wrapper { display: flex; align-items: center; gap: 12px; }
    .user-avatar img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; }
    .user-name { font-weight: bold; color: #1e293b; font-size: 0.95rem; }
    .role-dept-wrapper { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .dept-badge { background: #f1f5f9; color: #475569; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; }
    .role-badge { background: #f3e8ff; color: #7e22ce; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; }
    .province-badge { display: inline-block; background: #eff6ff; color: #2563eb; padding: 4px 8px; border-radius: 6px; border: 1px solid #bfdbfe; font-size: 0.75rem; font-weight: bold; margin: 2px; }
    .btn-group { display: flex; gap: 8px; }
    .btn-action { border: none; padding: 6px 12px; border-radius: 6px; font-weight: bold; font-size: 0.8rem; cursor: pointer; transition: 0.2s;}
    .btn-edit { background: #fef9c3; color: #b45309; border: 1px solid #fde047; }
    .btn-delete { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    .select2-container--default .select2-selection--multiple, .select2-container--default .select2-selection--single { border: 1px solid #cbd5e1; border-radius: 8px; min-height: 42px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 40px; color:#1e293b; font-weight:bold;}
    .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center; }
    .modal-content { background:#fff; width:90%; max-width:550px; border-radius:12px; overflow:hidden; }
    .modal-header { padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 20px; }
    .modal-footer { padding: 15px 20px; border-top: 1px solid #e2e8f0; text-align: left; background: #f8fafc; display: flex; justify-content: flex-end; gap: 10px;}
</style>';
include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div><span class="page-title">تخصیص استان‌ها (تیم فروش)</span></div>
            <div class="header-actions" style="display: flex; align-items: center; gap: 10px;">
                <form method="GET" class="search-box" style="margin: 0; display: flex; gap: 5px;">
                    <input type="text" name="q" class="search-input" placeholder="جستجو..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary">🔍</button>
                </form>
                <button onclick="openModal('add', 0)" class="btn btn-primary" <?php echo !empty($errorMessage) ? 'disabled' : ''; ?>>➕ تخصیص جدید</button>
            </div>
        </div>

        <?php if(!empty($errorMessage)): ?>
            <div style="background:#fee2e2; border:1px solid #fecaca; border-radius:12px; padding:20px; text-align:center; color:#b91c1c; margin-bottom:15px;">
                <p><?php echo htmlspecialchars($errorMessage); ?></p>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="c-table">
                <thead><tr><th>#</th><th>کد پرسنلی</th><th>مشخصات کارمند</th><th>دپارتمان</th><th>استان‌ها</th><th>عملیات</th></tr></thead>
                <tbody>
                    <?php if(empty($assignedUsers)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:30px;">هنوز استانی تخصیص داده نشده است.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($assignedUsers as $u): $imgSrc = ($u['profile_image'] && $u['profile_image'] != 'default.png') ? '../uploads/profiles/' . $u['profile_image'] : '../assets/images/profile-icon.png'; ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($u['personnel_code'] ?? '-'); ?></td>
                            <td><div class="user-info-wrapper"><div class="user-avatar"><img src="<?php echo $imgSrc; ?>"></div><div class="user-name"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></div></div></td>
                            <td><div class="role-dept-wrapper"><div class="dept-badge">🏢 <?php echo htmlspecialchars($u['department_name'] ?? '-'); ?></div></div></td>
                            <td><?php foreach(explode(',', $u['assigned_provinces']) as $p) echo "<span class='province-badge'>$p</span> "; ?></td>
                            <td><div class="btn-group"><button onclick='openModal("edit", <?php echo $u['id']; ?>)' class="btn-action btn-edit">✏️</button><button onclick='deleteAssignment(<?php echo $u['id']; ?>)' class="btn-action btn-delete">🗑️</button></div></td>
                        </tr>
                        <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div id="assignModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header"><h4 class="m-0 fw-bold" id="modalTitle">تخصیص استان</h4><span style="cursor:pointer;" onclick="closeModal()">&times;</span></div>
        <form id="assignForm" onsubmit="submitForm(event)">
            <div class="modal-body">
                <input type="hidden" name="action" value="save_assignment">
                <div class="form-group mb-3">
                    <label class="fw-bold mb-2 d-block">کارمند فروش <span class="text-danger">*</span></label>
                    <select name="user_id" id="modalUserId" class="form-control" style="width:100%;"></select>
                </div>
                <div class="form-group mb-3">
                    <label class="fw-bold mb-2 d-block">استان‌ها (تهران قابلیت انتخاب برای همه را دارد)</label>
                    <select name="provinces[]" id="provincesSelect" class="form-control" multiple="multiple" style="width:100%;"></select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary px-4" onclick="closeModal()">انصراف</button>
                <button type="submit" id="btnSubmit" class="btn btn-success px-4">💾 ذخیره</button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/jquery-3.6.0.min.js"></script>
<script src="../assets/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('#provincesSelect').select2({ dir: "rtl", placeholder: "استان‌ها...", dropdownParent: $('#assignModal') });
        $('#modalUserId').select2({ dir: "rtl", placeholder: "جستجوی کارمند...", dropdownParent: $('#assignModal') });
    });
    function openModal(mode, userId) {
        document.getElementById('assignModal').style.display = 'flex';
        document.getElementById('modalTitle').innerText = mode === 'add' ? '➕ تخصیص جدید' : '✏️ ویرایش تخصیص';
        $('#provincesSelect').empty().trigger('change');
        $('#modalUserId').empty().prop('disabled', false).trigger('change');
        $('#hiddenUserId').remove();
        $.post('crm_contacts.php', { action: 'get_form_data', user_id: userId }, function(res) {
            if(res.status === 'success') {
                if (res.users.length > 0) { res.users.forEach(u => $('#modalUserId').append(new Option(u.first_name + ' ' + u.last_name, u.id, false, u.id == userId))); }
                if (mode === 'edit') { $('#modalUserId').prop('disabled', true); $('#assignForm').append('<input type="hidden" name="user_id" id="hiddenUserId" value="'+userId+'">'); }
                if (res.available_provinces.length > 0) {
                    res.available_provinces.forEach(p => $('#provincesSelect').append(new Option(p, p, false, res.my_provinces.includes(p))));
                }
                $('#provincesSelect, #modalUserId').trigger('change');
            }
        });
    }
    function closeModal() { document.getElementById('assignModal').style.display = 'none'; }
    function submitForm(e) {
        e.preventDefault();
        fetch('crm_contacts.php', { method: 'POST', body: new FormData(e.target), headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(res => res.json()).then(data => { if(data.status === 'success') window.location.reload(); else alert('❌ ' + data.message); });
    }
    function deleteAssignment(userId) {
        if(confirm('آیا مطمئن هستید؟')) {
            $.post('crm_contacts.php', { action: 'delete_assignment', user_id: userId }, function(res) { if(res.status === 'success') window.location.reload(); });
        }
    }
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>