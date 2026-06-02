<?php
/*
 * فایل: public_html/admin/mission_types.php
 * توضیحات: مدیریت نوع مأموریت (CRUD) با مودال
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userRole = $_SESSION['role'] ?? '';
$isAdmin = in_array($userRole, ['admin', 'management'], true);
if (!$isAdmin) {
    die('دسترسی ندارید.');
}

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    try {
        if ($action === 'create') {
            if ($title === '') throw new Exception('عنوان الزامی است.');
            $stmt = $pdo->prepare("INSERT INTO mission_types (title, is_active) VALUES (?, ?)");
            $stmt->execute([$title, $isActive]);
            $msg = 'نوع مأموریت ثبت شد.';
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0 || $title === '') throw new Exception('اطلاعات نامعتبر است.');
            $stmt = $pdo->prepare("UPDATE mission_types SET title=?, is_active=? WHERE id=?");
            $stmt->execute([$title, $isActive, $id]);
            $msg = 'نوع مأموریت بروزرسانی شد.';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('شناسه نامعتبر است.');
            $stmt = $pdo->prepare("DELETE FROM mission_types WHERE id=?");
            $stmt->execute([$id]);
            $msg = 'نوع مأموریت حذف شد.';
        }
    } catch (Exception $e) {
        $msgType = 'danger';
        $msg = $e->getMessage();
    }
}

$types = $pdo->query("SELECT * FROM mission_types ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'مدیریت نوع مأموریت';
$basePath = '../';
$extraCss = '<style>
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:right; padding:10px; border-bottom:1px solid #f1f5f9; font-size:0.85rem; }
    th { color:#6b7280; font-weight:600; }
    .btn-sm { padding:6px 10px; font-size:0.75rem; }
    .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.35); display:none; align-items:center; justify-content:center; z-index:3000; }
    .modal-box { background:#fff; border-radius:12px; padding:16px; width:100%; max-width:420px; }
    .form-control { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-family:"Vazirmatn", Tahoma, sans-serif; font-size:0.85rem; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">مدیریت نوع مأموریت</span></div>
            <div><button class="btn btn-primary" onclick="openModal()">+ افزودن نوع جدید</button></div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="card-box">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>شناسه</th>
                            <th>عنوان</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($types)): ?>
                        <tr><td colspan="4">موردی ثبت نشده است.</td></tr>
                    <?php else: foreach ($types as $t): ?>
                        <tr>
                            <td>#<?php echo (int)$t['id']; ?></td>
                            <td><?php echo htmlspecialchars($t['title']); ?></td>
                            <td><?php echo $t['is_active'] ? 'فعال' : 'غیرفعال'; ?></td>
                            <td>
                                <button class="btn btn-outline btn-sm" onclick='openModal(<?php echo json_encode($t, JSON_UNESCAPED_UNICODE); ?>)'>ویرایش</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
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

<div class="modal-overlay" id="typeModal">
    <div class="modal-box">
        <h5 class="mb-3">نوع مأموریت</h5>
        <form method="POST" id="typeForm">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="id" id="typeId">
            <div class="mb-3">
                <label class="form-label">عنوان</label>
                <input type="text" name="title" id="typeTitle" class="form-control" required>
            </div>
            <div class="mb-3">
                <label><input type="checkbox" name="is_active" id="typeActive" checked> فعال</label>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-light" onclick="closeModal()">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(data) {
        const modal = document.getElementById('typeModal');
        const form = document.getElementById('typeForm');
        form.action.value = data ? 'update' : 'create';
        document.getElementById('typeId').value = data ? data.id : '';
        document.getElementById('typeTitle').value = data ? data.title : '';
        document.getElementById('typeActive').checked = data ? (data.is_active == 1) : true;
        modal.style.display = 'flex';
    }
    function closeModal() { document.getElementById('typeModal').style.display = 'none'; }
    document.getElementById('typeModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>