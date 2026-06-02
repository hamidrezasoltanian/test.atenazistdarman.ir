<?php
/*
 * فایل: public_html/admin/mission_create.php
 * توضیحات: فرم ثبت مأموریت (ارسال موازی به مدیر دپارتمان و مدیریت + اعلان عکس‌دار + پیامک) - نسخه کاملاً آفلاین
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
$prefCustomerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

try {
    $pdo->exec("ALTER TABLE `mission_requests` ADD COLUMN IF NOT EXISTS `fiscal_year_id` INT DEFAULT NULL AFTER `department_id`"); 
    $pdo->exec("ALTER TABLE `mission_requests` MODIFY COLUMN `status` ENUM('pending', 'pending_admin', 'approved', 'expenses_open', 'expenses_submitted', 'finance_process', 'finance_review', 'completed', 'rejected') DEFAULT 'pending'"); 
    $pdo->exec("UPDATE `mission_requests` SET `status`='pending' WHERE `status` IS NULL OR `status`=''");
} catch (\Exception $e) {}

// دریافت دپارتمان، نام و عکس کاربر برای اعلانات
$deptId = 0;
$userFullName = '';
$senderIcon = '../assets/images/profile-icon.png';
try {
    $stmt = $pdo->prepare("SELECT department_id, first_name, last_name, profile_image FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $uData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($uData) {
        $deptId = (int)($uData['department_id'] ?: 0);
        $userFullName = trim($uData['first_name'] . ' ' . $uData['last_name']);
        if (!empty($uData['profile_image']) && $uData['profile_image'] !== 'default.png') {
            $senderIcon = '../uploads/profiles/' . $uData['profile_image'];
        }
    }
} catch (Exception $e) { $deptId = 0; }

$blockedMsg = null;
$blockedRequestId = null;
try {
    // جلوگیری از ثبت مأموریت جدید اگر مأموریت در جریان دارد
    $stmt = $pdo->prepare("SELECT id FROM mission_requests WHERE user_id = ? AND status IN ('pending', 'pending_admin', 'expenses_open') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $blockedRequestId = $stmt->fetchColumn();
    if ($blockedRequestId) {
        $blockedMsg = "شما یک ماموریت در حال انجام دارید. ابتدا فرآیند یا هزینه‌های آن را تکمیل کنید.";
    }
} catch (Exception $e) { }

$message = '';
$status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$blockedMsg) {
    try {
        $pdo->beginTransaction();

        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception("حداقل یک ماموریت باید ثبت شود.");
        }

        $fiscalYear = getActiveFiscalYear();
        if (!$fiscalYear) {
            throw new Exception("سال مالی فعالی در سیستم یافت نشد.");
        }

        // وضعیت روی pending تنظیم می‌شود تا هم مدیر دپارتمان و هم مدیریت کل ببینند
        $initialStatus = 'pending';

        $stmtReq = $pdo->prepare("INSERT INTO mission_requests (user_id, department_id, fiscal_year_id, status, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmtReq->execute([$userId, $deptId, $fiscalYear['id'], $initialStatus]);
        $requestId = (int)$pdo->lastInsertId();

        $stmtItem = $pdo->prepare("INSERT INTO mission_items (request_id, type_id, customer_id, mission_date, start_time, end_time, description) VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($_POST['items'] as $item) {
            $typeId = (int)($item['type_id'] ?? 0);
            $customerId = !empty($item['customer_id']) ? (int)$item['customer_id'] : null;
            $jDate = trim($item['mission_date'] ?? '');
            $startTime = trim($item['start_time'] ?? '');
            $endTime = trim($item['end_time'] ?? '');
            $desc = trim($item['description'] ?? '');

            if ($typeId <= 0 || $jDate === '' || $startTime === '' || $endTime === '') {
                throw new Exception("تمام فیلدهای ضروری باید تکمیل شوند.");
            }

            $jDate = faToEn($jDate);
            $p = explode('/', $jDate);
            if (count($p) !== 3) throw new Exception("فرمت تاریخ نامعتبر است.");
            $gDate = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));

            $stmtItem->execute([$requestId, $typeId, $customerId, $gDate, $startTime, $endTime, strip_tags($desc)]);
        }

        // --- جمع آوری اهداف (مدیر دپارتمان + مدیریت کل) ---
        $targetIds = [];
        
        if ($deptId > 0) {
            $stmtDept = $pdo->prepare("SELECT manager_id FROM departments WHERE id = ?");
            $stmtDept->execute([$deptId]);
            $deptManager = $stmtDept->fetchColumn();
            if ($deptManager && $deptManager != $userId) {
                $targetIds[] = $deptManager;
            }
        }

        $stmtAdmins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'management')");
        while ($row = $stmtAdmins->fetch()) {
            if ($row['id'] != $userId) $targetIds[] = $row['id'];
        }

        $targetIds = array_values(array_unique($targetIds));

        // 1. ثبت اعلان درون سیستمی با عکس پروفایل درخواست‌دهنده
        if (function_exists('send_user_notification') && !empty($targetIds)) {
            $annTitle = "🔔 ماموریت جدید: #" . $requestId;
            $annContent = "درخواست ماموریت جدیدی از سوی " . ($userFullName ?: 'کاربر') . " نیازمند بررسی شماست.";
            $annLink = "admin/mission_view.php?id=" . $requestId;
            
            foreach ($targetIds as $tId) {
                send_user_notification($pdo, $tId, $annTitle, $annContent, $annLink, 'mission', $requestId, $senderIcon);
            }
        }

        // 2. ارسال پیامک هوشمند (به تمام مدیران هدف)
        $smsSetting = '0';
        try {
            $stmtSet = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sms_on_mission_create'");
            $smsSetting = $stmtSet->fetchColumn();
        } catch (Exception $e) {}

        if ($smsSetting === '1' && !empty($targetIds) && function_exists('send_sms_pattern')) {
            $sysConfig = @include __DIR__ . '/../../Config/config.php';
            $patternCode = $sysConfig['mission_create_edit_pattern'] ?? '';

            if (!empty($patternCode)) {
                $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                $stmtMob = $pdo->prepare("SELECT mobile FROM users WHERE id IN ($placeholders) AND mobile IS NOT NULL AND mobile != '' AND status='active'");
                $stmtMob->execute($targetIds);
                $mobiles = $stmtMob->fetchAll(PDO::FETCH_COLUMN);

                foreach ($mobiles as $mob) {
                    send_sms_pattern($mob, $patternCode, [
                        'mission_id' => strval($requestId),
                        'user_name'  => $userFullName ?: 'کاربر',
                        'action'     => 'ثبت'
                    ]);
                }
            }
        }

        $pdo->commit();
        header("Location: missions.php?msg=created");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $status = 'danger';
        $message = 'خطا در ثبت: ' . $e->getMessage();
    }
}

$customers = $pdo->query("SELECT id, company_name FROM customers ORDER BY company_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$missionTypes = $pdo->query("SELECT id, title FROM mission_types WHERE is_active=1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'ثبت ماموریت جدید';
$basePath = '../';
$extraCss = '<style>
    .mission-form-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px; box-shadow:0 2px 6px rgba(0,0,0,0.04); }
    .item-row { border:1px solid #e5e7eb; border-radius:10px; padding:12px; margin-bottom:12px; background:#f9fafb; position:relative; }
    .item-grid { display:grid; grid-template-columns: repeat(6, 1fr); gap:10px; }
    .item-grid .full { grid-column: span 6; }
    .item-grid .third { grid-column: span 2; }
    .item-grid .two { grid-column: span 2; }
    .item-grid label { font-size:0.75rem; color:#6b7280; margin-bottom:4px; display:block; }
    .form-control, .form-select { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-family:"Vazirmatn", Tahoma, sans-serif; font-size:0.85rem; }
    .form-control:focus, .form-select:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.15); }
    .remove-btn { position:absolute; top:8px; left:8px; background:#fee2e2; color:#b91c1c; border:none; border-radius:6px; padding:4px 8px; cursor:pointer; font-size:0.75rem; font-family:"Vazirmatn", Tahoma, sans-serif !important; }
    .add-btn { width:100%; border:1px dashed #cbd5e1; background:#f8fafc; color:#475569; border-radius:10px; padding:10px; cursor:pointer; font-weight:bold; font-family:"Vazirmatn", Tahoma, sans-serif !important; }
    .customer-search-wrap { position: relative; }
    .customer-results { position:absolute; top:100%; right:0; left:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; max-height:220px; overflow:auto; z-index:50; display:none; }
    .customer-results button { width:100%; text-align:right; padding:8px 10px; border:none; background:#fff; cursor:pointer; font-size:0.85rem; }
    .customer-results button:hover { background:#f3f4f6; }
    
    @media (max-width: 992px) {
        .item-grid { grid-template-columns: repeat(2, 1fr); }
        .item-grid .full, .item-grid .third, .item-grid .two { grid-column: span 2; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">ثبت درخواست ماموریت</span></div>
            <div><a href="missions.php" class="btn btn-secondary">بازگشت</a></div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $status; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($blockedMsg): ?>
            <div class="mission-form-card">
                <div class="alert alert-warning"><?php echo $blockedMsg; ?></div>
                <?php if($blockedRequestId): ?>
                <a href="mission_view.php?id=<?php echo (int)$blockedRequestId; ?>" class="btn btn-primary">مشاهده و تکمیل فرآیند</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form method="POST" id="missionForm" class="mission-form-card">
                <div id="itemsContainer"></div>
                <button type="button" class="add-btn" onclick="addRow()">+ افزودن مقصد جدید</button>
                <div class="page-actions" style="margin-top:15px;">
                    <button type="submit" class="btn btn-primary">ثبت نهایی درخواست</button>
                    <a href="missions.php" class="btn btn-outline">انصراف</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>

<!-- استفاده از منبع داخلی (Local) به جای CDN -->
<script src="../assets/js/jquery-3.6.0.min.js"></script>
<script>
    let rowIndex = 0;
    const types = <?php echo json_encode($missionTypes); ?>;
    const customers = <?php echo json_encode($customers); ?>;
    const prefCustomerId = <?php echo (int)$prefCustomerId; ?>;

    function buildOptions(list, valueKey, labelKey) {
        return list.map(o => `<option value="${o[valueKey]}">${o[labelKey]}</option>`).join('');
    }

    function addRow() {
        const idx = rowIndex++;
        const container = document.getElementById('itemsContainer');
        const div = document.createElement('div');
        div.className = 'item-row';
        div.innerHTML = `
            <button type="button" class="remove-btn" onclick="this.closest('.item-row').remove()">حذف</button>
            <div class="item-grid">
                <div class="third">
                    <label>نوع ماموریت</label>
                    <select name="items[${idx}][type_id]" class="form-select" required>
                        <option value="">انتخاب کنید</option>
                        ${buildOptions(types, 'id', 'title')}
                    </select>
                </div>
                <div class="third">
                    <label>طرف حساب</label>
                    <div class="customer-search-wrap">
                        <input type="text" class="form-control customer-input" placeholder="جستجو..." oninput="searchCustomers(this)">
                        <input type="hidden" name="items[${idx}][customer_id]" class="customer-id">
                        <div class="customer-results"></div>
                    </div>
                </div>
                <div class="third">
                    <label>تاریخ ماموریت</label>
                    <input type="text" name="items[${idx}][mission_date]" id="missionDate_${idx}" class="form-control" autocomplete="off" placeholder="مثال: 1403/05/12" required dir="ltr" style="text-align: right;">
                </div>
                <div class="two">
                    <label>ساعت شروع</label>
                    <input type="time" name="items[${idx}][start_time]" class="form-control" required>
                </div>
                <div class="two">
                    <label>ساعت پایان</label>
                    <input type="time" name="items[${idx}][end_time]" class="form-control" required>
                </div>
                <div class="full">
                    <label>توضیحات تکمیلی</label>
                    <textarea name="items[${idx}][description]" class="form-control" rows="2"></textarea>
                </div>
            </div>
        `;
        container.appendChild(div);

        if (prefCustomerId) {
            const hiddenId = div.querySelector('.customer-id');
            hiddenId.value = String(prefCustomerId);
            const input = div.querySelector('.customer-input');
            const found = customers.find(c => String(c.id) === String(prefCustomerId));
            if (found) input.value = found.company_name;
        }
    }

    addRow();

    let searchTimer;
    async function searchCustomers(input) {
        const wrap = input.closest('.customer-search-wrap');
        const list = wrap.querySelector('.customer-results');
        const hidden = wrap.querySelector('.customer-id');
        const q = input.value.trim();
        hidden.value = '';
        if (q.length < 2) { list.style.display = 'none'; return; }
        
        clearTimeout(searchTimer);
        searchTimer = setTimeout(async () => {
            try {
                const res = await fetch(`customers.php?action=search_customers&q=${encodeURIComponent(q)}`, { credentials: 'same-origin' });
                const data = await res.json();
                if (!data || data.status !== 'success') return;
                list.innerHTML = data.items.map(it => `<button type="button" onclick="selectCustomer(this)" data-id="${it.id}" data-name="${it.company_name}">${it.company_name}</button>`).join('');
                list.style.display = data.items.length ? 'block' : 'none';
            } catch (e) {}
        }, 250);
    }

    function selectCustomer(btn) {
        const wrap = btn.closest('.customer-search-wrap');
        wrap.querySelector('.customer-id').value = btn.dataset.id;
        wrap.querySelector('.customer-input').value = btn.dataset.name;
        wrap.querySelector('.customer-results').style.display = 'none';
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.customer-search-wrap')) {
            document.querySelectorAll('.customer-results').forEach(el => el.style.display = 'none');
        }
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>