<?php
/*
 * فایل: public_html/admin/mission_edit.php
 * توضیحات: ویرایش درخواست ماموریت (همراه با ثبت اعلان سیستمی و ارسال پیامک هوشمند از کانفیگ)
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
$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($requestId <= 0) die('شناسه نامعتبر است.');

// بررسی اینکه آیا درخواست متعلق به کاربر است و وضعیت آن رد شده است
$stmt = $pdo->prepare("SELECT * FROM mission_requests WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$requestId, $userId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) die('درخواست یافت نشد.');
if ($request['status'] !== 'rejected' && $request['status'] !== 'pending_admin') {
    die('فقط درخواست‌های رد شده یا در انتظار تایید قابل ویرایش هستند.');
}

// دریافت دپارتمان و نام کاربر برای ارسال اعلان
$deptId = $request['department_id'];
$userFullName = '';
try {
    $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $uData = $uStmt->fetch(PDO::FETCH_ASSOC);
    if ($uData) {
        $userFullName = trim($uData['first_name'] . ' ' . $uData['last_name']);
    }
} catch (Exception $e) {}

// دریافت آیتم‌های قبلی
$stmtItems = $pdo->prepare("SELECT i.*, c.company_name 
                            FROM mission_items i 
                            LEFT JOIN customers c ON i.customer_id = c.id
                            WHERE i.request_id = ? ORDER BY i.id ASC");
$stmtItems->execute([$requestId]);
$existingItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// آماده‌سازی داده‌های قبلی برای جاوااسکریپت
$jsItems = [];
foreach ($existingItems as $item) {
    $jsItems[] = [
        'type_id' => $item['type_id'],
        'customer_id' => $item['customer_id'],
        'customer_name' => $item['company_name'] ?? '',
        'mission_date' => jdate('Y/m/d', strtotime($item['mission_date'])),
        'start_time' => $item['start_time'],
        'end_time' => $item['end_time'],
        'description' => $item['description']
    ];
}

$message = '';
$status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception("حداقل یک ماموریت باید ثبت شود.");
        }

        // 1. حذف آیتم‌های قبلی (روش ساده‌تر: حذف و درج مجدد)
        $pdo->prepare("DELETE FROM mission_items WHERE request_id = ?")->execute([$requestId]);

        // 2. درج آیتم‌های جدید
        $stmtItem = $pdo->prepare("INSERT INTO mission_items (request_id, type_id, customer_id, mission_date, start_time, end_time, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");

        foreach ($_POST['items'] as $item) {
            $typeId = (int)($item['type_id'] ?? 0);
            $customerId = !empty($item['customer_id']) ? (int)$item['customer_id'] : null;
            $jDate = trim($item['mission_date'] ?? '');
            $startTime = trim($item['start_time'] ?? '');
            $endTime = trim($item['end_time'] ?? '');
            $desc = trim($item['description'] ?? '');

            if ($typeId <= 0 || $jDate === '' || $startTime === '' || $endTime === '') {
                throw new Exception("تمام فیلدهای ضروری هر ردیف باید تکمیل شوند.");
            }

            $jDate = faToEn($jDate);
            $p = explode('/', $jDate);
            if (count($p) !== 3) throw new Exception("فرمت تاریخ نامعتبر است.");
            $gDate = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));

            $stmtItem->execute([$requestId, $typeId, $customerId, $gDate, $startTime, $endTime, strip_tags($desc)]);
        }

        // 3. تغییر وضعیت درخواست به در انتظار تایید
        $pdo->prepare("UPDATE mission_requests SET status = 'pending_admin', rejection_reason = NULL WHERE id = ?")->execute([$requestId]);

        // --- ثبت اعلان اختصاصی برای ارسال مجدد ---
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

        // حل باگ HY093: مرتب‌سازی مجدد کلیدهای آرایه پس از حذف شناسه‌های تکراری
        $targetIds = array_values(array_unique($targetIds));

        if (function_exists('send_user_notification') && !empty($targetIds)) {
            $annTitle = "🔄 ارسال مجدد ماموریت: #" . $requestId;
            $annContent = "همکار گرامی، آقای/خانم " . ($userFullName ?: 'کاربر') . " درخواست ماموریت خود را اصلاح و مجدداً ارسال کرده است.";
            $annLink = "admin/mission_view.php?id=" . $requestId;
            
            foreach ($targetIds as $tId) {
                send_user_notification($pdo, $tId, $annTitle, $annContent, $annLink, 'mission_edit', $requestId);
            }
        }
        
        // --- اضافه شدن ارسال پیامک در صورت تایید در تنظیمات ---
        $smsSetting = '0';
        try {
            $stmtSet = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'sms_on_mission_create'");
            $smsSetting = $stmtSet->fetchColumn();
        } catch (Exception $e) {}

        if ($smsSetting === '1' && !empty($targetIds) && function_exists('send_sms_pattern')) {
            // خواندن پترن از فایل کانفیگ
            $sysConfig = require __DIR__ . '/../../Config/config.php';
            $patternCode = $sysConfig['mission_create_edit_pattern'] ?? '6sada5sbpu33zn2';

            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $stmtMob = $pdo->prepare("SELECT mobile FROM users WHERE id IN ($placeholders) AND mobile IS NOT NULL AND mobile != ''");
            $stmtMob->execute($targetIds);
            $mobiles = $stmtMob->fetchAll(PDO::FETCH_COLUMN);

            foreach ($mobiles as $mob) {
                send_sms_pattern($mob, $patternCode, [
                    'mission_id' => strval($requestId),
                    'user_name'  => $userFullName ?: 'کاربر',
                    'action'     => 'ویرایش'
                ]);
            }
        }
        // ------------------------------------------

        $pdo->commit();
        header("Location: missions.php?msg=updated");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $status = 'danger';
        $message = 'خطا در ویرایش: ' . $e->getMessage();
    }
}

$missionTypes = $pdo->query("SELECT id, title FROM mission_types WHERE is_active=1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$customers = $pdo->query("SELECT id, company_name FROM customers ORDER BY company_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'ویرایش درخواست ماموریت';
$basePath = '../';

// CSS مشابه فرم ثبت
$vendorPath = __DIR__ . '/../../vendor/kamaDatepicker/';
$cssContent = '';
if (file_exists($vendorPath . 'kamadatepicker.min.css')) {
    $cssContent = file_get_contents($vendorPath . 'kamadatepicker.min.css');
}
$extraCss = '<style>' . $cssContent . '</style>';
$extraCss .= '<style>
    .mission-form-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px; box-shadow:0 2px 6px rgba(0,0,0,0.04); }
    .item-row { border:1px solid #e5e7eb; border-radius:10px; padding:12px; margin-bottom:12px; background:#f9fafb; position:relative; }
    .item-grid { display:grid; grid-template-columns: repeat(6, 1fr); gap:10px; }
    .item-grid .full { grid-column: span 6; }
    .item-grid .half { grid-column: span 3; }
    .item-grid .third { grid-column: span 2; }
    .item-grid .two { grid-column: span 2; }
    .item-grid .one { grid-column: span 1; }
    .item-grid label { font-size:0.75rem; color:#6b7280; margin-bottom:4px; display:block; }
    .form-control, .form-select { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-family:"Vazirmatn", Tahoma, sans-serif; font-size:0.85rem; }
    .remove-btn { position:absolute; top:8px; left:8px; background:#fee2e2; color:#b91c1c; border:none; border-radius:6px; padding:4px 8px; cursor:pointer; font-size:0.75rem; }
    .add-btn { width:100%; border:1px dashed #cbd5e1; background:#f8fafc; color:#475569; border-radius:10px; padding:10px; cursor:pointer; font-family:"Vazirmatn", Tahoma, sans-serif; font-weight:600; }
    .customer-search-wrap { position: relative; }
    .customer-results { position:absolute; top:100%; right:0; left:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; max-height:220px; overflow:auto; z-index:50; display:none; }
    .customer-results button { width:100%; text-align:right; padding:8px 10px; border:none; background:#fff; cursor:pointer; font-size:0.85rem; }
    .customer-results button:hover { background:#f3f4f6; }
    .date-input { cursor: pointer; background-color: #fff !important; }
    @media (max-width: 992px) {
        .item-grid { grid-template-columns: repeat(2, 1fr); }
        .item-grid .full, .item-grid .half, .item-grid .third, .item-grid .two, .item-grid .one { grid-column: span 2; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">ویرایش و ارسال مجدد درخواست #<?php echo $requestId; ?></span></div>
            <div><a href="missions.php" class="btn btn-secondary">بازگشت</a></div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $status; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($request['rejection_reason']): ?>
            <div class="alert alert-danger mb-3">دلیل رد قبلی: <?php echo htmlspecialchars($request['rejection_reason']); ?></div>
        <?php endif; ?>

        <form method="POST" id="missionForm" class="mission-form-card">
            <div id="itemsContainer"></div>
            <button type="button" class="add-btn" onclick="addRow()">+ افزودن ردیف جدید</button>
            <div class="page-actions" style="margin-top:15px;">
                <button type="submit" class="btn btn-primary">ثبت اصلاحات و ارسال مجدد</button>
                <a href="missions.php" class="btn btn-outline">انصراف</a>
            </div>
        </form>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    <?php 
    if (file_exists($vendorPath . 'kamadatepicker.min.js')) echo file_get_contents($vendorPath . 'kamadatepicker.min.js');
    echo "\n";
    if (file_exists($vendorPath . 'kamadatepicker.holidays.js')) echo file_get_contents($vendorPath . 'kamadatepicker.holidays.js');
    ?>
</script>

<script>
    let rowIndex = 0;
    const types = <?php echo json_encode($missionTypes); ?>;
    const existingData = <?php echo json_encode($jsItems); ?>;

    function buildOptions(list, valueKey, labelKey, selectedVal = null) {
        return list.map(o => {
            const sel = (selectedVal && String(o[valueKey]) === String(selectedVal)) ? 'selected' : '';
            return `<option value="${o[valueKey]}" ${sel}>${o[labelKey]}</option>`;
        }).join('');
    }

    function addRow(data = null) {
        const idx = rowIndex++;
        const container = document.getElementById('itemsContainer');
        const div = document.createElement('div');
        div.className = 'item-row';
        
        const typeId = data ? data.type_id : '';
        const custId = data ? data.customer_id : '';
        const custName = data ? data.customer_name : '';
        const mDate = data ? data.mission_date : '';
        const sTime = data ? data.start_time : '';
        const eTime = data ? data.end_time : '';
        const desc = data ? data.description : '';

        div.innerHTML = `
            <button type="button" class="remove-btn" onclick="this.closest('.item-row').remove()">حذف</button>
            <div class="item-grid">
                <div class="third">
                    <label>نوع ماموریت</label>
                    <select name="items[${idx}][type_id]" class="form-select" required>
                        <option value="">انتخاب کنید</option>
                        ${buildOptions(types, 'id', 'title', typeId)}
                    </select>
                </div>
                <div class="third">
                    <label>طرف حساب</label>
                    <div class="customer-search-wrap">
                        <input type="text" class="form-control customer-input" placeholder="جستجو..." value="${custName}" oninput="searchCustomers(this)">
                        <input type="hidden" name="items[${idx}][customer_id]" class="customer-id" value="${custId}">
                        <div class="customer-results"></div>
                    </div>
                </div>
                <div class="third">
                    <label>تاریخ ماموریت</label>
                    <input type="text" name="items[${idx}][mission_date]" id="missionDate_${idx}" class="form-control date-input" autocomplete="off" value="${mDate}" required readonly>
                </div>
                <div class="two">
                    <label>ساعت شروع</label>
                    <input type="time" name="items[${idx}][start_time]" class="form-control" value="${sTime}" required>
                </div>
                <div class="two">
                    <label>ساعت پایان</label>
                    <input type="time" name="items[${idx}][end_time]" class="form-control" value="${eTime}" required>
                </div>
                <div class="full">
                    <label>توضیحات</label>
                    <textarea name="items[${idx}][description]" class="form-control" rows="2">${desc}</textarea>
                </div>
            </div>
        `;
        container.appendChild(div);

        if (typeof kamaDatepicker === 'function') {
            kamaDatepicker(`missionDate_${idx}`, {
                buttonsColor: "#2563eb",
                forceFarsiDigits: true,
                markToday: true,
                markHolidays: true,
                highlightSelectedDay: true,
                sync: true,
                gotoToday: true,
                twodigit: true,
                nextButtonIcon: "بعدی",
                previousButtonIcon: "قبلی"
            });
        }
    }

    // بارگذاری داده‌های قبلی
    if (existingData && existingData.length > 0) {
        existingData.forEach(item => addRow(item));
    } else {
        addRow();
    }

    let searchTimer;
    async function searchCustomers(input) {
        const wrap = input.closest('.customer-search-wrap');
        const list = wrap.querySelector('.customer-results');
        const hidden = wrap.querySelector('.customer-id');
        const q = input.value.trim();
        hidden.value = '';
        if (q.length < 2) {
            list.style.display = 'none';
            return;
        }
        clearTimeout(searchTimer);
        searchTimer = setTimeout(async () => {
            try {
                const res = await fetch(`customers.php?action=search_customers&q=${encodeURIComponent(q)}`);
                const data = await res.json();
                if (!data || data.status !== 'success') return;
                list.innerHTML = data.items.map(it =>
                    `<button type="button" onclick="selectCustomer(this)" data-id="${it.id}" data-name="${it.company_name}">${it.company_name}</button>`
                ).join('');
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