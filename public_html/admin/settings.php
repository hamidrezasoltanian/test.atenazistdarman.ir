<?php
/*
 * فایل: public_html/admin/settings.php
 * اضافه شدن تنظیمات پیامکی ماژول مرخصی
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
if (!in_array($userRole, ['admin', 'management'], true)) {
    die('<div style="text-align:center; margin-top:50px; font-family:tahoma; color:red; font-weight:bold;">⛔ شما دسترسی لازم برای این بخش را ندارید.</div>');
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
} catch (Exception $e) {}

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        
        // تب پیامک‌ها
        if (isset($_POST['tab_type']) && $_POST['tab_type'] === 'sms') {
            $smsSettings = [
                'sms_on_mission_create', 'sms_on_mission_status',
                'sms_attendance_new', 'sms_attendance_status',
                'sms_leave_new', 'sms_leave_status' // افزوده شده برای مرخصی
            ];
            foreach ($smsSettings as $key) {
                $val = isset($_POST[$key]) ? '1' : '0';
                $stmt->execute([$key, $val]);
            }
            $msg = 'تنظیمات پیامک با موفقیت ذخیره شد.';
        }
        
        // تب قوانین عمومی
        if (isset($_POST['tab_type']) && $_POST['tab_type'] === 'general') {
            $attendance_max_requests = $_POST['attendance_max_requests'] ?? '3';
            $attendance_max_past_days = $_POST['attendance_max_past_days'] ?? '7';
            
            $stmt->execute(['attendance_max_requests', $attendance_max_requests]);
            $stmt->execute(['attendance_max_past_days', $attendance_max_past_days]);
            $msg = 'قوانین و تنظیمات عمومی با موفقیت ذخیره شد.';
        }

    } catch (Exception $e) {
        $msgType = 'danger';
        $msg = 'خطا در ذخیره تنظیمات: ' . $e->getMessage();
    }
}

$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

$maxReqs = $settings['attendance_max_requests'] ?? '3';
$maxDays = $settings['attendance_max_past_days'] ?? '7';

$pageTitle = 'تنظیمات سیستم';
$basePath = '../';
$extraCss = '<style>
    .card-box { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; box-shadow:0 2px 6px rgba(0,0,0,0.04); margin-bottom:20px; }
    .profile-tabs { display: flex; gap: 20px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; }
    .tab-btn { background: none; border: none; padding: 12px 10px; font-family: "Vazirmatn", sans-serif; font-size: 1rem; font-weight: 700; color: #64748b; cursor: pointer; border-bottom: 3px solid transparent; transition: 0.3s; }
    .tab-btn:hover { color: var(--primary); }
    .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
    .tab-content { display: none; animation: fadeIn 0.3s ease-out; }
    .tab-content.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    
    .setting-row { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px dashed #e2e8f0; flex-wrap:wrap; gap:15px;}
    .setting-row:last-child { border-bottom: none; }
    .setting-info { flex: 1; min-width: 250px; }
    .setting-title { font-weight: bold; color: #1e293b; margin-bottom: 4px; font-size: 0.95rem; }
    .setting-desc { font-size: 0.8rem; color: #64748b; }
    
    .switch { position: relative; display: inline-block; width: 46px; height: 24px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 34px; }
    .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: #10b981; }
    input:checked + .slider:before { transform: translateX(22px); }
    
    .setting-input { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; width: 100px; text-align: center; font-weight: bold; }
    .setting-input:focus { border-color: var(--primary); outline: none; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">⚙️ تنظیمات اتوماسیون</span></div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="profile-tabs">
            <button class="tab-btn active" onclick="switchTab('sms')">📱 تنظیمات پیامک</button>
            <button class="tab-btn" onclick="switchTab('general')">🛠️ قوانین عمومی</button>
        </div>

        <div id="tab-sms" class="tab-content active card-box">
            <form method="POST">
                <input type="hidden" name="tab_type" value="sms">
                
                <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; padding-bottom:10px; border-bottom:2px solid #e5e7eb;">پیامک‌های ماموریت</h3>
                <div class="setting-row">
                    <div class="setting-info">
                        <div class="setting-title">ارسال پیامک ثبت/ویرایش به مدیر</div>
                    </div>
                    <div><label class="switch"><input type="checkbox" name="sms_on_mission_create" <?php echo ($settings['sms_on_mission_create'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                </div>
                <div class="setting-row">
                    <div class="setting-info">
                        <div class="setting-title">ارسال نتیجه ماموریت به کارمند</div>
                    </div>
                    <div><label class="switch"><input type="checkbox" name="sms_on_mission_status" <?php echo ($settings['sms_on_mission_status'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                </div>

                <h3 style="font-size:1.1rem; color:#1f2937; margin-top:30px; margin-bottom:15px; padding-bottom:10px; border-bottom:2px solid #e5e7eb;">پیامک‌های اصلاح تردد</h3>
                <div class="setting-row">
                    <div class="setting-info">
                        <div class="setting-title">ارسال پیامک ثبت درخواست به مدیر</div>
                    </div>
                    <div><label class="switch"><input type="checkbox" name="sms_attendance_new" <?php echo ($settings['sms_attendance_new'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                </div>
                <div class="setting-row">
                    <div class="setting-info">
                        <div class="setting-title">ارسال نتیجه تردد به کارمند</div>
                    </div>
                    <div><label class="switch"><input type="checkbox" name="sms_attendance_status" <?php echo ($settings['sms_attendance_status'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                </div>

                <h3 style="font-size:1.1rem; color:#1f2937; margin-top:30px; margin-bottom:15px; padding-bottom:10px; border-bottom:2px solid #e5e7eb;">پیامک‌های مرخصی</h3>
                <div class="setting-row">
                    <div class="setting-info">
                        <div class="setting-title">ارسال پیامک ثبت درخواست مرخصی به مدیر</div>
                        <div class="setting-desc">هنگام ثبت مرخصی جدید توسط پرسنل، مدیر دپارتمان مطلع شود.</div>
                    </div>
                    <div><label class="switch"><input type="checkbox" name="sms_leave_new" <?php echo ($settings['sms_leave_new'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                </div>
                <div class="setting-row">
                    <div class="setting-info">
                        <div class="setting-title">ارسال نتیجه مرخصی به کارمند</div>
                        <div class="setting-desc">با تایید یا رد درخواست مرخصی، نتیجه برای کارمند پیامک شود.</div>
                    </div>
                    <div><label class="switch"><input type="checkbox" name="sms_leave_status" <?php echo ($settings['sms_leave_status'] ?? '0') === '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                </div>

                <div style="margin-top: 25px; text-align: left;">
                    <button type="submit" class="btn btn-primary px-4 py-2">ذخیره تنظیمات پیامک</button>
                </div>
            </form>
        </div>

        <div id="tab-general" class="tab-content card-box">
            <form method="POST">
                <input type="hidden" name="tab_type" value="general">
                <h3 style="font-size:1.1rem; color:#1f2937; margin-bottom:15px; padding-bottom:10px; border-bottom:2px solid #e5e7eb;">قوانین ماژول تردد</h3>
                
                <div class="alert alert-warning" style="font-size: 0.85rem; border-radius: 8px;">
                    <i class="fas fa-exclamation-triangle"></i> <strong>توجه:</strong> مدیران از قوانین زمانی زیر مستثنی هستند.
                </div>

                <div class="setting-row">
                    <div class="setting-info">
                        <div class="setting-title">حداکثر تعداد مجاز اصلاح تردد</div>
                    </div>
                    <div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="number" name="attendance_max_requests" class="setting-input" value="<?php echo htmlspecialchars($maxReqs); ?>" min="1" max="30">
                            <span>بار در ماه</span>
                        </div>
                    </div>
                </div>
                
                <div class="setting-row">
                    <div class="setting-info">
                        <div class="setting-title">محدودیت ثبت در گذشته (تردد)</div>
                    </div>
                    <div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="number" name="attendance_max_past_days" class="setting-input" value="<?php echo htmlspecialchars($maxDays); ?>" min="1" max="365">
                            <span>روز گذشته</span>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 25px; text-align: left;">
                    <button type="submit" class="btn btn-primary px-4 py-2">ذخیره قوانین عمومی</button>
                </div>
            </form>
        </div>

    </div>
</main>

<script>
    function switchTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById('tab-' + tabName).classList.add('active');
        event.currentTarget.classList.add('active');
    }
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>