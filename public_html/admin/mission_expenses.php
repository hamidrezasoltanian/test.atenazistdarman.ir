<?php
/*
 * فایل: public_html/admin/mission_expenses.php
 * توضیحات: ثبت هزینه‌های ماموریت (متصل به هر آیتم ماموریت) - اعمال قوانین ثبت یک مسیر رفت برای هر آیتم و یک مسیر برگشت برای کل درخواست
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($requestId <= 0) die('شناسه نامعتبر است.');

$isAdmin = in_array($userRole, ['admin', 'management'], true);

$stmt = $pdo->prepare("SELECT r.*, u.first_name, u.last_name
                       FROM mission_requests r
                       JOIN users u ON r.user_id = u.id
                       WHERE r.id = ? LIMIT 1");
$stmt->execute([$requestId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$req) die('درخواست یافت نشد.');

$isOwner = ((int)$req['user_id'] === $userId);
if (!$isOwner && !$isAdmin) die('دسترسی ندارید.');

$message = '';
$status = '';

// --- توابع ترجمه ---
function translate_expense_type($type) {
    $map = [
        'personal' => 'هزینه شخصی',
        'org_snap' => 'اسنپ سازمانی',
        'discount_code' => 'کد تخفیف'
    ];
    return $map[$type] ?? $type;
}

function translate_journey_type($type) {
    $map = [
        'outbound' => 'رفت',
        'return' => 'برگشت'
    ];
    return $map[$type] ?? 'نامشخص';
}

function translate_status($status) {
    $map = [
        'pending' => 'در انتظار بررسی',
        'approved' => 'تایید شده',
        'rejected' => 'رد شده'
    ];
    return $map[$status] ?? $status;
}

// محدودیت آپلود استاندارد
$allowedExt = ['jpg','jpeg','png','pdf'];
$maxSize = 5 * 1024 * 1024;

// پردازش فرم‌ها
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_expense' && !$req['expenses_locked'] && $req['status'] === 'expenses_open') {
        try {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $type = $_POST['expense_type'] ?? '';
            $journeyType = $_POST['journey_type'] ?? '';
            $amount = str_replace(',', '', trim($_POST['amount'] ?? '0'));
            $desc = trim($_POST['description'] ?? '');
            $discountCode = trim($_POST['discount_code'] ?? '');

            if ($itemId <= 0) {
                throw new Exception('لطفا مشخص کنید هزینه مربوط به کدام ماموریت است.');
            }
            if (!in_array($type, ['personal','org_snap','discount_code'], true)) {
                throw new Exception('نوع هزینه نامعتبر است.');
            }
            if (!in_array($journeyType, ['outbound','return'], true)) {
                throw new Exception('نوع مسیر نامعتبر است. فقط امکان انتخاب رفت یا برگشت وجود دارد.');
            }
            if (!is_numeric($amount) || (float)$amount <= 0) {
                throw new Exception('مبلغ نامعتبر است.');
            }
            if ($type === 'discount_code' && $discountCode === '') {
                throw new Exception('کد تخفیف را وارد کنید.');
            }
            if ($type === 'discount_code') {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM discount_codes WHERE code = ? AND is_active = 1");
                $chk->execute([$discountCode]);
                if ((int)$chk->fetchColumn() === 0) {
                    throw new Exception('کد تخفیف معتبر نیست.');
                }
            }

            // -------------------------------------------------------------
            // اعتبارسنجی‌های مسیر رفت و برگشت
            // -------------------------------------------------------------
            
            // قانون 1: فقط یک برگشت برای کل درخواست مجاز است
            if ($journeyType === 'return') {
                $checkReturn = $pdo->prepare("SELECT COUNT(*) FROM mission_expenses WHERE request_id = ? AND journey_type = 'return'");
                $checkReturn->execute([$requestId]);
                if ((int)$checkReturn->fetchColumn() > 0) {
                    throw new Exception('شما قبلاً مسیر برگشت را برای این ماموریت ثبت کرده‌اید. فقط یک مسیر برگشت برای کل ماموریت مجاز است.');
                }
            }

            // قانون 2: فقط یک رفت برای هر مقصد (item_id) مجاز است
            if ($journeyType === 'outbound') {
                $checkOutbound = $pdo->prepare("SELECT COUNT(*) FROM mission_expenses WHERE request_id = ? AND item_id = ? AND journey_type = 'outbound'");
                $checkOutbound->execute([$requestId, $itemId]);
                if ((int)$checkOutbound->fetchColumn() > 0) {
                    throw new Exception('برای این مقصد قبلاً هزینه مسیر رفت ثبت شده است. هر مکان فقط می‌تواند یک مسیر رفت داشته باشد.');
                }
            }
            // -------------------------------------------------------------

            $attachmentPath = null;
            if (!empty($_FILES['attachment']['name'])) {
                $file = $_FILES['attachment'];
                if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('خطا در آپلود فایل.');
                if ($file['size'] > $maxSize) throw new Exception('حجم فایل بیش از حد مجاز است (۵ مگابایت).');
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) throw new Exception('فرمت فایل مجاز نیست.');

                $newName = uniqid('exp_') . '.' . $ext;
                $uploadDir = __DIR__ . '/../../public_html/uploads/missions/';
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
                if (!move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
                    throw new Exception('آپلود فایل ناموفق بود.');
                }
                $attachmentPath = $newName;
            }

            $stmt = $pdo->prepare("INSERT INTO mission_expenses (request_id, item_id, expense_type, journey_type, amount, discount_code, description, attachment_path, status)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([
                $requestId,
                $itemId,
                $type,
                $journeyType,
                $amount,
                $discountCode ?: null,
                $desc ?: null,
                $attachmentPath
            ]);

            header("Location: mission_expenses.php?id=$requestId");
            exit;
        } catch (Exception $e) {
            $status = 'danger';
            $message = $e->getMessage();
        }
    }

    if ($action === 'delete_expense' && !$req['expenses_locked'] && $req['status'] === 'expenses_open') {
        $expId = (int)($_POST['exp_id'] ?? 0);
        if ($expId > 0) {
            $stmt = $pdo->prepare("DELETE FROM mission_expenses WHERE id = ? AND request_id = ?");
            $stmt->execute([$expId, $requestId]);
        }
        header("Location: mission_expenses.php?id=$requestId");
        exit;
    }

    if ($action === 'submit_expenses' && !$req['expenses_locked'] && $req['status'] === 'expenses_open') {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mission_expenses WHERE request_id = ?");
        $countStmt->execute([$requestId]);
        $count = (int)$countStmt->fetchColumn();
        if ($count <= 0) {
            $status = 'danger';
            $message = 'حداقل یک هزینه باید ثبت شود.';
        } else {
            $stmt = $pdo->prepare("UPDATE mission_requests SET status='finance_review', expenses_locked=1 WHERE id = ?");
            $stmt->execute([$requestId]);
            header("Location: missions.php?msg=sent_finance");
            exit;
        }
    }
}

// دریافت آیتم‌های تایید شده این درخواست برای دراپ‌داون
$stmtItems = $pdo->prepare("SELECT i.id, i.mission_date, c.company_name, t.title as type_title
                            FROM mission_items i
                            LEFT JOIN customers c ON i.customer_id = c.id
                            LEFT JOIN mission_types t ON i.type_id = t.id
                            WHERE i.request_id = ? AND i.status = 'approved' ORDER BY i.id ASC");
$stmtItems->execute([$requestId]);
$missionItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// دریافت هزینه‌ها (با جوین به آیتم‌ها برای نمایش نام شرکت)
$stmt = $pdo->prepare("SELECT e.*, c.company_name, t.title as type_title 
                       FROM mission_expenses e
                       LEFT JOIN mission_items i ON e.item_id = i.id
                       LEFT JOIN customers c ON i.customer_id = c.id
                       LEFT JOIN mission_types t ON i.type_id = t.id
                       WHERE e.request_id = ? ORDER BY e.id DESC");
$stmt->execute([$requestId]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دریافت کدهای تخفیف فعال
$discountCodes = $pdo->query("SELECT code, title FROM discount_codes WHERE is_active=1 ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "ثبت هزینه‌های ماموریت #$requestId";
$basePath = '../';
$extraCss = '<style>
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:16px; }
    .grid { display:grid; grid-template-columns: repeat(6, 1fr); gap:10px; }
    .grid .full { grid-column: span 6; }
    .grid .third { grid-column: span 2; }
    .form-control, .form-select { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-family:"Vazirmatn", Tahoma, sans-serif; font-size:0.85rem; }
    label { font-size:0.75rem; color:#6b7280; margin-bottom:4px; display:block; }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:right; padding:10px; border-bottom:1px solid #f1f5f9; font-size:0.85rem; }
    th { color:#6b7280; font-weight:600; }
    
    .status-badge { padding:3px 8px; border-radius:999px; font-size:0.75rem; display:inline-block; border:1px solid #e5e7eb; }
    .st-pending { background:#fffbeb; color:#b45309; border-color:#fde68a; }
    .st-approved { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; }
    .st-rejected { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
    
    .btn-sm { padding:6px 10px; font-size:0.75rem; }
    
    @media (max-width: 768px) {
        .grid { grid-template-columns: repeat(2, 1fr); }
        .grid .full, .grid .third { grid-column: span 2; }
        table, thead, tbody, th, td, tr { display:block; }
        thead { display:none; }
        tr { border:1px solid #e5e7eb; border-radius:10px; padding:10px; margin-bottom:10px; background:#fff; }
        td { border:none; padding:6px 0; }
        td::before { content: attr(data-label) " : "; color:#6b7280; font-weight:600; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">ثبت هزینه‌های ماموریت #<?php echo $requestId; ?></span></div>
            <div><a href="missions.php" class="btn btn-secondary">بازگشت</a></div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $status; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="card-box">
            <div class="page-title" style="margin-bottom:10px;">ثبت هزینه جدید</div>
            <?php if (!$isOwner): ?>
                <div class="alert alert-info">فقط صاحب ماموریت می‌تواند هزینه ثبت کند.</div>
            <?php elseif ($req['expenses_locked']): ?>
                <div class="alert alert-warning">هزینه‌ها ارسال نهایی شده‌اند و امکان ویرایش وجود ندارد.</div>
            <?php elseif ($req['status'] !== 'expenses_open'): ?>
                <div class="alert alert-warning">ثبت هزینه در این مرحله امکان‌پذیر نیست.</div>
            <?php else: ?>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_expense">
                    <div class="grid">
                        <div class="third">
                            <label>مربوط به کدام ماموریت؟</label>
                            <select name="item_id" class="form-select" required>
                                <option value="">انتخاب کنید</option>
                                <?php foreach($missionItems as $mi): ?>
                                    <option value="<?php echo $mi['id']; ?>">
                                        <?php echo htmlspecialchars($mi['company_name'] ?? 'آزاد'); ?> 
                                        (<?php echo htmlspecialchars($mi['type_title'] ?? ''); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="third">
                            <label>مسیر</label>
                            <select name="journey_type" class="form-select" required>
                                <option value="">انتخاب کنید</option>
                                <option value="outbound">رفت (یک مسیر برای هر مقصد)</option>
                                <option value="return">برگشت (فقط یک‌بار برای کل ماموریت)</option>
                            </select>
                        </div>
                        <div class="third">
                            <label>نوع هزینه</label>
                            <select name="expense_type" id="expenseType" class="form-select" required>
                                <option value="">انتخاب کنید</option>
                                <option value="personal"> هزینه شخصی | صد در صد هزینه با شخص</option>
                                <option value="org_snap">اسنپ سازمانی | صد در صد هزینه با شرکت</option>
                                <option value="discount_code">کد تخفیف ثابت</option>
                            </select>
                        </div>
                        <div class="third">
                            <label>مبلغ (ریال)</label>
                            <input type="text" name="amount" class="form-control" required>
                        </div>
                        <div class="third">
                            <label>کد تخفیف (در صورت انتخاب)</label>
                            <select name="discount_code" id="discountCode" class="form-select" disabled>
                                <option value="">انتخاب کنید</option>
                                <?php foreach ($discountCodes as $dc): ?>
                                    <option value="<?php echo htmlspecialchars($dc['code']); ?>">
                                        <?php echo htmlspecialchars($dc['code'] . ($dc['title'] ? ' - ' . $dc['title'] : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="third">
                            <label>فاکتور (اختیاری)</label>
                            <input type="file" name="attachment" class="form-control">
                        </div>
                        <div class="full">
                            <label>توضیحات</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div style="margin-top:10px;">
                        <button type="submit" class="btn btn-primary">ثبت هزینه</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="card-box">
            <div class="page-title" style="margin-bottom:10px;">لیست هزینه‌ها</div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>مربوط به</th>
                            <th>نوع</th>
                            <th>مسیر</th>
                            <th>مبلغ</th>
                            <th>کد تخفیف</th>
                            <th>فاکتور</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="8">هزینه‌ای ثبت نشده است.</td></tr>
                    <?php else: foreach ($expenses as $e): ?>
                        <tr>
                            <td data-label="مربوط به">
                                <span class="text-primary fw-bold"><?php echo htmlspecialchars($e['company_name'] ?? 'بدون مشتری'); ?></span>
                            </td>
                            <td data-label="نوع"><?php echo translate_expense_type($e['expense_type']); ?></td>
                            <td data-label="مسیر">
                                <span style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:0.75rem; border:1px solid #e2e8f0;">
                                    <?php echo translate_journey_type($e['journey_type'] ?? 'other'); ?>
                                </span>
                            </td>
                            <td data-label="مبلغ"><?php echo number_format((float)$e['amount']); ?></td>
                            <td data-label="کد تخفیف"><?php echo htmlspecialchars($e['discount_code'] ?? '-'); ?></td>
                            <td data-label="فاکتور">
                                <?php if ($e['attachment_path']): ?>
                                    <a class="btn btn-outline btn-sm" target="_blank" href="../uploads/missions/<?php echo htmlspecialchars($e['attachment_path']); ?>">دانلود</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td data-label="وضعیت"><span class="status-badge st-<?php echo $e['status']; ?>"><?php echo translate_status($e['status']); ?></span></td>
                            <td data-label="عملیات">
                                <?php if ($isOwner && !$req['expenses_locked'] && $req['status']==='expenses_open'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_expense">
                                        <input type="hidden" name="exp_id" value="<?php echo (int)$e['id']; ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($isOwner && !$req['expenses_locked'] && $req['status']==='expenses_open'): ?>
                <form method="POST" style="margin-top:12px;">
                    <input type="hidden" name="action" value="submit_expenses">
                    <button type="submit" class="btn btn-success">ارسال نهایی به مالی</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
<script>
    const expenseType = document.getElementById('expenseType');
    const discountCode = document.getElementById('discountCode');
    if (expenseType && discountCode) {
        expenseType.addEventListener('change', function() {
            if (this.value === 'discount_code') {
                discountCode.disabled = false;
            } else {
                discountCode.value = '';
                discountCode.disabled = true;
            }
        });
    }
</script>