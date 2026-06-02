<?php
/*
 * فایل: public_html/admin/fin_categories.php
 * توضیحات: مدیریت سرفصل‌های هزینه + دسترسی داینامیک
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است.']); exit; }
    header("Location: ../login.php"); exit;
}

$userId = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// --- ⭐ سیستم بررسی دسترسی کاملاً داینامیک ⭐ ---
$isAdmin = false;
$hasAccess = false;
$requiredPermission = 'fin_categories'; 

$stmtChk = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$rawRole, $rawRole]);
$roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($roleData) {
    if ($roleData['name'] === 'admin' || $roleData['is_system'] == 1) {
        $isAdmin = true;
        $hasAccess = true;
    } else {
        $perms = json_decode($roleData['permissions'], true) ?? [];
        if (in_array($requiredPermission, $perms) || in_array('all', $perms)) {
            $hasAccess = true;
        }
    }
}

if (!$hasAccess) {
    if ($isAjax) { echo json_encode(['status' => 'error', 'message' => 'دسترسی غیرمجاز']); exit; }
    die('<div style="text-align:center; padding:50px; font-family:Tahoma; color:red; font-weight:bold; font-size:1.1rem; line-height:1.6;">⛔ دسترسی غیرمجاز.<br><span style="font-size:0.85rem; color:#64748b;">(دسترسی مدیریت سرفصل‌ها باید در مدیریت نقش‌ها برای شما فعال شود)</span></div>');
}
// ------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = $_POST['id'] ?? null;
            $title = trim($_POST['title'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if (empty($title)) throw new Exception('عنوان سرفصل الزامی است.');

            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO fin_expense_categories (title, status) VALUES (?, ?)");
                $stmt->execute([$title, $status]);
                if(function_exists('logSystem')) logSystem('PettyCashSettings', 'category_create', $pdo->lastInsertId(), "ایجاد سرفصل هزینه: $title");
                echo json_encode(['status'=>'success', 'message'=>'سرفصل با موفقیت ایجاد شد.']);
            } else {
                $stmt = $pdo->prepare("UPDATE fin_expense_categories SET title=?, status=? WHERE id=?");
                $stmt->execute([$title, $status, $id]);
                if(function_exists('logSystem')) logSystem('PettyCashSettings', 'category_update', $id, "ویرایش سرفصل هزینه: $title");
                echo json_encode(['status'=>'success', 'message'=>'سرفصل با موفقیت بروزرسانی شد.']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['status'=>'error', 'message'=>'خطا: ' . $e->getMessage()]);
    }
    exit;
}

$categories = $pdo->query("SELECT c.*, (SELECT COUNT(id) FROM fin_expenses WHERE category_id = c.id) as usage_count FROM fin_expense_categories c ORDER BY c.status ASC, c.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'تنظیمات سرفصل‌های هزینه';
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1000px; margin: 0 auto; padding: 0 20px; }
    .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; }
    .cat-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; position: relative; transition: 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: flex; flex-direction: column; height: 100%; justify-content: space-between; }
    .cat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.06); border-color: #cbd5e1; }
    .cat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 15px; }
    .cat-title { font-weight: 900; color: #1e293b; font-size: 1.1rem; line-height: 1.5; }
    .usage-badge { background: #f8fafc; border: 1px solid #e2e8f0; padding: 10px; border-radius: 8px; text-align: center; margin-bottom: 20px; font-size: 0.85rem; color: #64748b; font-weight: bold; }
    .usage-badge span { color: #2563eb; font-size: 1.1rem; font-weight: 900; margin: 0 5px; }
    .cat-footer button { width: 100%; padding: 10px; display: flex; justify-content: center; align-items: center; gap: 8px; font-weight:bold; }
    .status-badge { position: absolute; top: -10px; left: -10px; font-size: 0.75rem; font-weight: bold; padding: 5px 12px; border-radius: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    .status-active { background: #10b981; color: #fff; border: 2px solid #fff; }
    .status-inactive { background: #ef4444; color: #fff; border: 2px solid #fff; }
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); padding: 10px; }
    .modal-box { background: #fff; width: 100%; max-width: 450px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    .modal-header { padding: 20px 25px; background: #f8fafc; font-weight: 900; font-size:1.1rem; border-bottom: 1px solid #e2e8f0; display:flex; justify-content: space-between; align-items: center;}
    .modal-body { padding: 25px; }
    .modal-footer { padding: 20px 25px; border-top: 1px solid #e2e8f0; background: #f8fafc; display:flex; justify-content: flex-end; gap:12px;}
    @media (max-width: 768px) { .page-header { flex-direction: column; text-align: center; gap: 15px; } .page-header button, .modal-footer button { width: 100%; } .modal-footer { flex-direction: column; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            <div class="page-header" style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div><span class="page-title">🏷️ تنظیمات سرفصل‌های هزینه</span></div>
                <div><button onclick="openModal()" class="btn btn-primary" style="font-weight:bold; padding: 10px 20px; box-shadow: 0 4px 10px rgba(37,99,235,0.2);">➕ افزودن سرفصل جدید</button></div>
            </div>

            <?php if(empty($categories)): ?>
                <div style="text-align:center; padding:50px; color:#94a3b8; font-weight:bold; background: #fff; border-radius: 12px; border: 1px dashed #cbd5e1;">هیچ سرفصلی ثبت نشده است.</div>
            <?php else: ?>
                <div class="cards-grid">
                    <?php foreach($categories as $cat): ?>
                    <div class="cat-card">
                        <div class="status-badge <?php echo $cat['status']=='active'?'status-active':'status-inactive'; ?>"><?php echo $cat['status']=='active'?'فعال':'غیرفعال'; ?></div>
                        <div>
                            <div class="cat-header"><div class="cat-title">🏷️ <?php echo htmlspecialchars($cat['title']); ?></div></div>
                            <div class="usage-badge">استفاده در فاکتورها: <span><?php echo number_format($cat['usage_count']); ?></span> بار</div>
                        </div>
                        <div class="cat-footer"><button onclick='editCategory(<?php echo json_encode($cat); ?>)' class="btn btn-outline">⚙️ ویرایش سرفصل</button></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<div id="catModal" class="modal-overlay">
    <div class="modal-box">
        <form id="catForm" method="POST">
            <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="cat_id">
            <div class="modal-header"><span id="modalTitle">افزودن سرفصل جدید</span><span style="cursor:pointer; color:#ef4444; font-size:1.8rem; line-height:1;" onclick="closeModal('catModal')">&times;</span></div>
            <div class="modal-body">
                <div id="modalMsg" style="display:none; margin-bottom:20px; padding:12px; border-radius:8px; font-weight:bold; font-size:0.9rem; text-align:center;"></div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" style="font-weight:bold;">عنوان سرفصل <span style="color:red">*</span></label>
                    <input type="text" name="title" id="cat_title" class="form-control" placeholder="مثال: خرید تجهیزات اداری" required style="padding:12px; font-size:1.05rem;">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-weight:bold;">وضعیت نمایش</label>
                    <select name="status" id="cat_status" class="form-control" style="padding:12px;">
                        <option value="active">🟢 فعال</option><option value="inactive">🔴 غیرفعال</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('catModal')">انصراف</button>
                <button type="submit" class="btn btn-primary" id="btnSubmit">💾 ذخیره اطلاعات</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal() { document.getElementById('catForm').reset(); document.getElementById('cat_id').value = ''; document.getElementById('modalTitle').innerText = '➕ افزودن سرفصل جدید'; document.getElementById('modalMsg').style.display = 'none'; document.getElementById('catModal').style.display = 'flex'; }
    function editCategory(data) { document.getElementById('catForm').reset(); document.getElementById('modalMsg').style.display = 'none'; document.getElementById('modalTitle').innerText = '✏️ ویرایش سرفصل'; document.getElementById('cat_id').value = data.id; document.getElementById('cat_title').value = data.title; document.getElementById('cat_status').value = data.status; document.getElementById('catModal').style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    document.getElementById('catForm').addEventListener('submit', function(e) {
        e.preventDefault(); const formData = new FormData(this); const btn = document.getElementById('btnSubmit'); const msgBox = document.getElementById('modalMsg');
        btn.disabled = true; btn.innerText = 'در حال ذخیره...';
        fetch('fin_categories.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(r => r.json()).then(data => {
            msgBox.style.display = 'block';
            if (data.status === 'success') { msgBox.style.background = '#dcfce7'; msgBox.style.color = '#166534'; msgBox.style.border = '1px solid #bbf7d0'; msgBox.innerText = data.message; setTimeout(() => location.reload(), 1000); } 
            else { msgBox.style.background = '#fee2e2'; msgBox.style.color = '#991b1b'; msgBox.style.border = '1px solid #fecaca'; msgBox.innerText = data.message; btn.disabled = false; btn.innerText = '💾 ذخیره اطلاعات'; }
        }).catch(e => { alert('خطا در ارتباط با سرور'); btn.disabled = false; btn.innerText = '💾 ذخیره اطلاعات'; });
    });
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>