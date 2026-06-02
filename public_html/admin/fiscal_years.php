<?php
/*
 * فایل: public_html/admin/fiscal_years.php
 */

ob_start();
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
    if ($isAjax) { 
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است.']); 
        exit; 
    }
    header("Location: ../login.php"); exit;
}

// بررسی دسترسی
if (!hasPermission('fiscal_year_manage') && $_SESSION['role'] !== 'admin') {
    if ($isAjax) { echo json_encode(['status' => 'error', 'message' => 'دسترسی غیرمجاز']); exit; }
    die('دسترسی غیرمجاز');
}

// --- پردازش AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add' || $action === 'edit') {
            $title = trim($_POST['title']);
            $start_date_input = trim($_POST['start_date']);
            $end_date_input = trim($_POST['end_date']);
            $status = $_POST['status'];
            $description = trim($_POST['description']);
            $id = $_POST['id'] ?? null;

            if (empty($title) || empty($start_date_input) || empty($end_date_input)) {
                echo json_encode(['status'=>'error', 'message'=>'عنوان و تاریخ‌ها الزامی هستند.']); exit;
            }

            // تبدیل تاریخ‌ها به میلادی
            $start_date = null; $end_date = null;
            if(!empty($start_date_input)) {
                $p = explode('/', faToEn($start_date_input));
                if(count($p)==3) $start_date = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));
            }
            if(!empty($end_date_input)) {
                $p = explode('/', faToEn($end_date_input));
                if(count($p)==3) $end_date = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));
            }

            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO fiscal_years (title, start_date, end_date, status, description, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $start_date, $end_date, $status, $description, $_SESSION['user_id']]);
                $newId = $pdo->lastInsertId();
                
                logSystem('FiscalYear', 'create', $newId, "ایجاد سال مالی جدید: $title");
                echo json_encode(['status'=>'success', 'message'=>'سال مالی ایجاد شد.']);
            } else {
                $stmt = $pdo->prepare("UPDATE fiscal_years SET title=?, start_date=?, end_date=?, status=?, description=? WHERE id=?");
                $stmt->execute([$title, $start_date, $end_date, $status, $description, $id]);
                
                logSystem('FiscalYear', 'update', $id, "ویرایش سال مالی: $title - وضعیت: $status");
                echo json_encode(['status'=>'success', 'message'=>'سال مالی ویرایش شد.']);
            }
        }
        elseif ($action === 'set_current') {
            $id = $_POST['id'];
            $pdo->beginTransaction();
            // غیرفعال کردن بقیه
            $pdo->query("UPDATE fiscal_years SET is_current = 0");
            // فعال کردن این یکی
            $stmt = $pdo->prepare("UPDATE fiscal_years SET is_current = 1, status = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            $pdo->commit();
            
            logSystem('FiscalYear', 'set_current', $id, "تنظیم به عنوان سال مالی پیش‌فرض");
            echo json_encode(['status'=>'success', 'message'=>'سال مالی پیش‌فرض تغییر کرد.']);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status'=>'error', 'message'=>'خطا: ' . $e->getMessage()]);
    }
    exit;
}

// --- نمایش ---
$years = $pdo->query("SELECT * FROM fiscal_years ORDER BY start_date DESC")->fetchAll();

$pageTitle = 'مدیریت سال مالی';
$basePath = '../';
$extraCss = '<link rel="stylesheet" href="../assets/css/kamadatepicker.min.css">';
$extraCss .= '<style>
    .fy-card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 20px; position: relative; transition: 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    .fy-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px rgba(0,0,0,0.05); }
    .fy-card.current { border: 2px solid var(--primary); background: #eff6ff; }
    .fy-badge { position: absolute; top: 20px; left: 20px; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; }
    .badge-active { background: #dcfce7; color: #166534; }
    .badge-closed { background: #fee2e2; color: #991b1b; }
    .badge-locked { background: #fef9c3; color: #854d0e; }
    .current-label { color: var(--primary); font-weight: bold; display: flex; align-items: center; gap: 5px; margin-bottom: 10px; font-size: 0.9rem; }
    .fy-dates { display: flex; gap: 20px; color: #6b7280; font-size: 0.9rem; margin-top: 10px; }
    .fy-desc { margin-top: 10px; font-size: 0.85rem; color: #4b5563; }
    .fy-actions { margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 15px; display: flex; gap: 10px; justify-content: flex-end; }
    .modal-body { padding: 25px; }
    /* استایل مودال تمام صفحه مشابه کاربران */
    .modal-overlay { align-items: flex-start; padding: 0; background: #fff; }
    .modal { width: 100%; max-width: 100%; height: 100%; min-height: 100vh; margin: 0; border-radius: 0; box-shadow: none; overflow-y: auto; }
    .modal-header { position: sticky; top: 0; background: #f9fafb; z-index: 10; border-bottom: 1px solid #e5e7eb; padding: 15px 20px; }
    #fyForm { max-width: 800px; margin: 0 auto; padding: 20px; }
    #bd-root { z-index: 2000000 !important; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">تنظیمات سال مالی</span></div>
            <div><button onclick="openModal('add')" class="btn btn-primary">+ سال مالی جدید</button></div>
        </div>

        <div class="row">
            <?php foreach ($years as $fy): 
                $sDate = jdate('Y/m/d', strtotime($fy['start_date']));
                $eDate = jdate('Y/m/d', strtotime($fy['end_date']));
                
                // اضافه کردن تاریخ‌های شمسی به آرایه برای استفاده در ویرایش
                $fy['start_date_jalali'] = $sDate;
                $fy['end_date_jalali'] = $eDate;
                
                $statusMap = ['active'=>'فعال', 'closed'=>'بسته شده', 'locked'=>'قفل موقت'];
            ?>
            <div class="col" style="min-width: 320px; flex: 1;">
                <div class="fy-card <?php echo $fy['is_current'] ? 'current' : ''; ?>">
                    <?php if($fy['is_current']): ?>
                        <div class="current-label">✅ سال مالی جاری سیستم</div>
                    <?php endif; ?>
                    
                    <span class="fy-badge badge-<?php echo $fy['status']; ?>">
                        <?php echo $statusMap[$fy['status']]; ?>
                    </span>

                    <h3 style="font-weight:bold; font-size:1.1rem; margin-top: 5px;"><?php echo htmlspecialchars($fy['title']); ?></h3>
                    
                    <div class="fy-dates">
                        <div><span style="color:#9ca3af;">شروع:</span> <?php echo $sDate; ?></div>
                        <div><span style="color:#9ca3af;">پایان:</span> <?php echo $eDate; ?></div>
                    </div>
                    
                    <?php if($fy['description']): ?>
                        <div class="fy-desc"><?php echo htmlspecialchars($fy['description']); ?></div>
                    <?php endif; ?>

                    <div class="fy-actions">
                        <?php if(!$fy['is_current'] && $fy['status'] !== 'closed'): ?>
                            <button onclick="setCurrentYear(<?php echo $fy['id']; ?>)" class="btn btn-outline" style="font-size:0.8rem">تنظیم به عنوان پیش‌فرض</button>
                        <?php endif; ?>
                        
                        <?php if($fy['status'] !== 'closed' || $_SESSION['role'] === 'admin'): ?>
                            <button onclick='editYear(<?php echo json_encode($fy); ?>)' class="btn btn-secondary" style="font-size:0.8rem">ویرایش</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری</footer>
</main>

<!-- مودال تمام صفحه -->
<div class="modal-overlay" id="fyModal">
    <div class="modal">
        <div class="modal-header"><h3 id="modalTitle">سال مالی</h3><span class="close-modal" onclick="closeModal()">×</span></div>
        <div class="modal-body">
            <div id="modalMessage" class="alert" style="display:none;"></div>
            <form id="fyForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="fyId">
                
                <div class="form-group">
                    <label class="form-label">عنوان سال <span style="color:red">*</span></label>
                    <input type="text" name="title" id="fyTitle" class="form-control" placeholder="مثال: سال مالی 1403" required>
                </div>
                
                <div class="row" style="display: flex; gap: 15px;">
                    <div class="col form-group" style="flex:1">
                        <label class="form-label">تاریخ شروع <span style="color:red">*</span></label>
                        <input type="text" name="start_date" id="startDate" class="form-control date-input" placeholder="1403/01/01" required autocomplete="off">
                    </div>
                    <div class="col form-group" style="flex:1">
                        <label class="form-label">تاریخ پایان <span style="color:red">*</span></label>
                        <input type="text" name="end_date" id="endDate" class="form-control date-input" placeholder="1403/12/29" required autocomplete="off">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">وضعیت</label>
                    <select name="status" id="fyStatus" class="form-control">
                        <option value="active">فعال (قابل ثبت سند)</option>
                        <option value="locked">قفل موقت (فقط خواندنی)</option>
                        <option value="closed">بسته شده (بایگانی)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">توضیحات</label>
                    <textarea name="description" id="fyDesc" class="form-control" rows="3"></textarea>
                </div>

                <div style="text-align: left; margin-top: 20px; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">انصراف</button>
                    <button type="submit" class="btn btn-primary">ذخیره اطلاعات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../vendor/kamadatepicker/kamadatepicker.min.js"></script>
<script>
    function openModal(mode) {
        document.getElementById('fyModal').style.display = 'flex';
        document.getElementById('modalMessage').style.display = 'none';
        
        // فعال‌سازی تقویم با تاخیر برای اطمینان از رندر
        setTimeout(() => {
            kamaDatepicker('startDate', { buttonsColor: "#2563eb", forceFarsiDigits: true, markToday: true });
            kamaDatepicker('endDate', { buttonsColor: "#2563eb", forceFarsiDigits: true, markToday: true });
        }, 100);

        if(mode === 'add') {
            document.getElementById('modalTitle').innerText = 'سال مالی جدید';
            document.getElementById('formAction').value = 'add';
            document.getElementById('fyId').value = '';
            document.getElementById('fyForm').reset();
            document.getElementById('fyStatus').value = 'active';
        }
    }

    function editYear(data) {
        openModal('edit');
        document.getElementById('modalTitle').innerText = 'ویرایش سال مالی';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('fyId').value = data.id;
        document.getElementById('fyTitle').value = data.title;
        document.getElementById('fyStatus').value = data.status;
        document.getElementById('fyDesc').value = data.description;
        
        // قرار دادن تاریخ شمسی در فیلدها
        if(data.start_date_jalali) document.getElementById('startDate').value = data.start_date_jalali;
        if(data.end_date_jalali) document.getElementById('endDate').value = data.end_date_jalali;
    }

    function closeModal() { document.getElementById('fyModal').style.display = 'none'; }

    function setCurrentYear(id) {
        if(!confirm('آیا مطمئن هستید؟ با این کار این سال به عنوان سال پیش‌فرض تمام عملیات‌ها انتخاب می‌شود.')) return;
        
        const formData = new FormData();
        formData.append('action', 'set_current');
        formData.append('id', id);

        fetch('fiscal_years.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(r => r.json())
        .then(d => {
            if(d.status === 'success') location.reload();
            else alert(d.message);
        });
    }

    document.getElementById('fyForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const btn = this.querySelector('button[type="submit"]');
        const txt = btn.innerText;
        btn.disabled = true; btn.innerText = 'در حال پردازش...';

        fetch('fiscal_years.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
        .then(r => r.json())
        .then(d => {
            if(d.status === 'success') { 
                const msg = document.getElementById('modalMessage');
                msg.innerHTML = `<div class="alert alert-success">${d.message}</div>`;
                msg.style.display = 'block';
                setTimeout(() => location.reload(), 1000);
            }
            else { 
                const msg = document.getElementById('modalMessage');
                msg.innerHTML = `<div class="alert alert-error">${d.message}</div>`;
                msg.style.display = 'block';
                btn.disabled = false; btn.innerText = txt;
            }
        })
        .catch(err => {
            alert('خطا در ارتباط');
            btn.disabled = false; btn.innerText = txt;
        });
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>