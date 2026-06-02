<?php
/*
 * فایل: public_html/admin/discount_codes.php
 * توضیحات: مدیریت کدهای تخفیف (CRUD) با مودال + پشتیبانی از مبلغ ثابت
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
    $code = trim($_POST['code'] ?? '');
    $title = trim($_POST['title'] ?? '');
    
    // دریافت مبلغ تخفیف (پاکسازی کاماها و تبدیل اعداد فارسی)
    $discountAmountRaw = trim($_POST['discount_amount'] ?? '0');
    if (function_exists('faToEn')) {
        $discountAmountRaw = faToEn($discountAmountRaw);
    }
    $discountAmountRaw = str_replace(',', '', $discountAmountRaw);
    $discountAmount = (float)$discountAmountRaw;
    
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    try {
        if ($action === 'create') {
            if ($code === '') throw new Exception('کد الزامی است.');
            if ($discountAmount < 0) throw new Exception('مبلغ تخفیف نمی‌تواند منفی باشد.');
            
            $stmt = $pdo->prepare("INSERT INTO discount_codes (code, title, discount_amount, is_active) VALUES (?, ?, ?, ?)");
            $stmt->execute([$code, $title ?: null, $discountAmount, $isActive]);
            $msg = 'کد تخفیف ثبت شد.';
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0 || $code === '') throw new Exception('اطلاعات نامعتبر است.');
            if ($discountAmount < 0) throw new Exception('مبلغ تخفیف نمی‌تواند منفی باشد.');
            
            $stmt = $pdo->prepare("UPDATE discount_codes SET code=?, title=?, discount_amount=?, is_active=? WHERE id=?");
            $stmt->execute([$code, $title ?: null, $discountAmount, $isActive, $id]);
            $msg = 'کد تخفیف بروزرسانی شد.';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('شناسه نامعتبر است.');
            $stmt = $pdo->prepare("DELETE FROM discount_codes WHERE id=?");
            $stmt->execute([$id]);
            $msg = 'کد تخفیف حذف شد.';
        }
    } catch (Exception $e) {
        $msgType = 'danger';
        $msg = $e->getMessage();
    }
}

$codes = $pdo->query("SELECT * FROM discount_codes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'مدیریت کدهای تخفیف';
$basePath = '../';
$extraCss = '<style>
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:right; padding:10px; border-bottom:1px solid #f1f5f9; font-size:0.85rem; }
    th { color:#6b7280; font-weight:600; }
    .btn-sm { padding:6px 10px; font-size:0.75rem; }
    .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.35); display:none; align-items:center; justify-content:center; z-index:3000; }
    .modal-box { background:#fff; border-radius:12px; padding:20px; width:100%; max-width:420px; }
    .form-control { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-family:"Vazirmatn", Tahoma, sans-serif; font-size:0.85rem; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">مدیریت کدهای تخفیف</span></div>
            <div><button class="btn btn-primary" onclick="openModal()">+ افزودن کد جدید</button></div>
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
                            <th>کد</th>
                            <th>عنوان</th>
                            <th>مبلغ ثابت (ریال)</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($codes)): ?>
                        <tr><td colspan="6">موردی ثبت نشده است.</td></tr>
                    <?php else: foreach ($codes as $c): ?>
                        <tr>
                            <td>#<?php echo (int)$c['id']; ?></td>
                            <td style="font-weight:bold; color:#2563eb;"><?php echo htmlspecialchars($c['code']); ?></td>
                            <td><?php echo htmlspecialchars($c['title'] ?? '-'); ?></td>
                            <td style="font-weight:bold; color:#166534;"><?php echo number_format((float)($c['discount_amount'] ?? 0)); ?></td>
                            <td>
                                <?php if($c['is_active']): ?>
                                    <span style="background:#dcfce7; color:#166534; padding:2px 8px; border-radius:10px; font-size:0.75rem;">فعال</span>
                                <?php else: ?>
                                    <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:10px; font-size:0.75rem;">غیرفعال</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-outline btn-sm" onclick='openModal(<?php echo json_encode($c, JSON_UNESCAPED_UNICODE); ?>)'>ویرایش</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                    <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('آیا از حذف این کد اطمینان دارید؟');">حذف</button>
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

<div class="modal-overlay" id="codeModal">
    <div class="modal-box">
        <h5 class="mb-3" style="font-weight:bold; border-bottom:1px solid #e5e7eb; padding-bottom:10px;">مدیریت کد تخفیف</h5>
        <form method="POST" id="codeForm">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="id" id="codeId">
            <div class="mb-3">
                <label class="form-label" style="font-size:0.8rem; color:#4b5563; margin-bottom:5px; display:block;">کد (انگلیسی وارد کنید)</label>
                <input type="text" name="code" id="codeValue" class="form-control" dir="ltr" style="text-align:left;" required>
            </div>
            <div class="mb-3">
                <label class="form-label" style="font-size:0.8rem; color:#4b5563; margin-bottom:5px; display:block;">عنوان (راهنما)</label>
                <input type="text" name="title" id="codeTitle" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label" style="font-size:0.8rem; color:#4b5563; margin-bottom:5px; display:block;">مبلغ ثابت تخفیف (ریال)</label>
                <input type="text" name="discount_amount" id="codeAmount" class="form-control" value="0" required>
            </div>
            <div class="mb-3" style="margin-top:15px; margin-bottom:20px;">
                <label style="cursor:pointer; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="is_active" id="codeActive" checked style="width:18px; height:18px;"> 
                    <span style="font-weight:bold; color:#1f2937;">کد فعال باشد</span>
                </label>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; border-top:1px solid #e5e7eb; padding-top:15px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره کد</button>
            </div>
        </form>
    </div>
</div>

<script>
    // تابع کمکی برای فرمت کردن اعداد (جداکننده هزارگان) در هنگام تایپ
    function formatNumberInput(input) {
        let val = input.value.replace(/,/g, '').replace(/\D/g, '');
        if(val !== '') {
            input.value = Number(val).toLocaleString('en-US');
        } else {
            input.value = '';
        }
    }

    // اتصال فرمت‌کننده به کادر مبلغ
    document.getElementById('codeAmount').addEventListener('input', function() {
        formatNumberInput(this);
    });

    function openModal(data) {
        const modal = document.getElementById('codeModal');
        const form = document.getElementById('codeForm');
        form.action.value = data ? 'update' : 'create';
        document.getElementById('codeId').value = data ? data.id : '';
        document.getElementById('codeValue').value = data ? data.code : '';
        document.getElementById('codeTitle').value = data ? (data.title || '') : '';
        
        // قرار دادن مبلغ و فرمت کردن آن
        let amt = data ? (data.discount_amount || 0) : 0;
        let amtInput = document.getElementById('codeAmount');
        amtInput.value = amt;
        formatNumberInput(amtInput); // اعمال جداکننده هزارگان

        document.getElementById('codeActive').checked = data ? (data.is_active == 1) : true;
        modal.style.display = 'flex';
    }
    
    function closeModal() { document.getElementById('codeModal').style.display = 'none'; }
    
    document.getElementById('codeModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>