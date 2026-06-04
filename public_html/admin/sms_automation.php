<?php
/*
 * فایل: public_html/admin/sms_automation.php
 * توضیحات: مدیریت قوانین اتوماسیون پیامک — ارسال خودکار بر اساس رویدادهای سیستم
 */

ob_start();

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$userId  = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// ---- بررسی دسترسی ----
$hasAccess = false;
try {
    $stmtChk = $pdo->prepare('SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?');
    $stmtChk->execute([$rawRole, $rawRole]);
    $roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);
    if ($roleData) {
        $roleName = strtolower(trim($roleData['name'] ?? $rawRole));
        $allowRoles = ['admin','management','manager','finance_manager'];
        if (in_array($roleName, $allowRoles) || $roleData['is_system'] == 1) {
            $hasAccess = true;
        } else {
            $perms = json_decode($roleData['permissions'] ?? '[]', true) ?? [];
            if (in_array('sms_automation', $perms) || in_array('all', $perms)) $hasAccess = true;
        }
    }
    if (in_array($rawRole, ['1','2'])) $hasAccess = true;
} catch (Throwable $e) { $hasAccess = true; }

if (!$hasAccess) {
    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'دسترسی غیرمجاز']);
        exit;
    }
    die('<p style="font-family:Tahoma;color:#e11d48;text-align:center;padding:60px">دسترسی غیرمجاز</p>');
}

// ---- ایجاد جداول ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sms_automation_rules` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `event_type`       ENUM('invoice_confirmed','payment_due','payment_received','cheque_due','leave_approved','contract_expiry','welcome') NOT NULL,
        `name`             VARCHAR(200) NOT NULL,
        `message_template` TEXT NOT NULL COMMENT 'متغیرها: {نام} {مبلغ} {تاریخ} {شماره}',
        `is_active`        TINYINT(1) DEFAULT 1,
        `send_days_before` INT DEFAULT 0 COMMENT 'برای رویدادهای آینده',
        `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `sms_automation_log` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `rule_id`        INT DEFAULT NULL,
        `event_type`     VARCHAR(50) NOT NULL,
        `phone`          VARCHAR(20) NOT NULL,
        `recipient_name` VARCHAR(200) DEFAULT NULL,
        `message`        TEXT NOT NULL,
        `status`         ENUM('sent','failed','pending') DEFAULT 'pending',
        `sent_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
        `error_msg`      VARCHAR(500) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ---- درج قوانین پیش‌فرض ----
$defaultRules = [
    ['invoice_confirmed', 'تأیید فاکتور فروش',       'مشتری گرامی {نام}، فاکتور شماره {شماره} به مبلغ {مبلغ} ریال در تاریخ {تاریخ} صادر گردید. آتنا زیست درمان', 0],
    ['payment_due',       'یادآور سررسید پرداخت',    '{نام} عزیز، فاکتور شماره {شماره} به مبلغ {مبلغ} ریال در تاریخ {تاریخ} سررسید می‌شود. لطفاً پیگیری فرمایید. آتنا زیست درمان', 3],
    ['payment_received',  'تأیید دریافت وجه',         'مشتری گرامی {نام}، مبلغ {مبلغ} ریال در تاریخ {تاریخ} با موفقیت دریافت شد. با تشکر، آتنا زیست درمان', 0],
    ['cheque_due',        'یادآور سررسید چک',         '{نام} گرامی، چک شماره {شماره} به مبلغ {مبلغ} ریال در تاریخ {تاریخ} سررسید می‌شود. آتنا زیست درمان', 2],
    ['leave_approved',    'تأیید درخواست مرخصی',      '{نام} عزیز، درخواست مرخصی شما از تاریخ {تاریخ} تأیید شد. آتنا زیست درمان', 0],
    ['contract_expiry',   'یادآور انقضای قرارداد',    '{نام} گرامی، قرارداد شماره {شماره} در تاریخ {تاریخ} منقضی می‌شود. جهت تمدید اقدام فرمایید. آتنا زیست درمان', 7],
    ['welcome',           'خوش‌آمدگویی مشتری جدید',  'مشتری گرامی {نام}، به خانواده آتنا زیست درمان خوش آمدید. از اعتماد شما سپاسگزاریم.', 0],
];
try {
    $stmtIns = $pdo->prepare("INSERT IGNORE INTO `sms_automation_rules` (event_type, name, message_template, send_days_before) VALUES (?,?,?,?)");
    foreach ($defaultRules as $r) {
        $stmtIns->execute($r);
    }
} catch (Throwable $e) {}

// ---- تابع ارسال SMS ----
if (!function_exists('sendFreeSms')) {
    function sendFreeSms(string $mobile, string $message): bool {
        $config  = require __DIR__ . '/../../Config/config.php';
        $apiKey  = $config['sms_api_key'] ?? '';
        $from    = $config['sms_from_number'] ?? '';
        if (!$apiKey) return false;
        if (substr($mobile, 0, 1) === '0') $mobile = '+98' . substr($mobile, 1);
        if (!preg_match('/^\+?\d{10,14}$/', $mobile)) return false;
        $url  = 'https://api2.ippanel.com/api/v1/sms/send/webservice/single';
        $body = json_encode(['sender' => $from, 'recipient' => $mobile, 'message' => $message]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300);
    }
}

// ===================================================================
// AJAX
// ===================================================================
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // ---- لیست قوانین ----
    if ($action === 'list_rules') {
        $rows = $pdo->query("SELECT * FROM sms_automation_rules ORDER BY FIELD(event_type,'invoice_confirmed','payment_due','payment_received','cheque_due','leave_approved','contract_expiry','welcome')")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ---- ذخیره قانون ----
    if ($action === 'save_rule') {
        csrf_verify($_POST['csrf'] ?? '');
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $template = trim($_POST['message_template'] ?? '');
        $days     = (int)($_POST['send_days_before'] ?? 0);
        if (!$name || !$template) {
            echo json_encode(['ok' => false, 'msg' => 'نام و متن پیام الزامی است']);
            exit;
        }
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE sms_automation_rules SET name=?, message_template=?, send_days_before=? WHERE id=?");
            $stmt->execute([$name, $template, $days, $id]);
        }
        echo json_encode(['ok' => true, 'msg' => 'قانون با موفقیت ذخیره شد']);
        exit;
    }

    // ---- تغییر وضعیت ----
    if ($action === 'toggle_rule') {
        csrf_verify($_POST['csrf'] ?? '');
        $id     = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);
        $pdo->prepare("UPDATE sms_automation_rules SET is_active=? WHERE id=?")->execute([$active, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ---- تست ارسال ----
    if ($action === 'send_test') {
        csrf_verify($_POST['csrf'] ?? '');
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        // دریافت شماره موبایل کاربر جاری
        $stmtU = $pdo->prepare("SELECT fullname, mobile FROM users WHERE id = ?");
        $stmtU->execute([$userId]);
        $udata = $stmtU->fetch(PDO::FETCH_ASSOC);
        $phone = $udata['mobile'] ?? '';
        if (!$phone) {
            echo json_encode(['ok' => false, 'msg' => 'شماره موبایل در پروفایل شما ثبت نشده است']);
            exit;
        }
        $stmtR = $pdo->prepare("SELECT * FROM sms_automation_rules WHERE id=?");
        $stmtR->execute([$ruleId]);
        $rule = $stmtR->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            echo json_encode(['ok' => false, 'msg' => 'قانون پیدا نشد']);
            exit;
        }
        $msg = str_replace(
            ['{نام}','{مبلغ}','{تاریخ}','{شماره}'],
            [$udata['fullname'] ?? 'کاربر', '1,000,000', jdate('Y/m/d'), 'TEST-001'],
            $rule['message_template']
        );
        $sent = sendFreeSms($phone, $msg);
        $status = $sent ? 'sent' : 'failed';
        $pdo->prepare("INSERT INTO sms_automation_log (rule_id, event_type, phone, recipient_name, message, status) VALUES (?,?,?,?,?,?)")
            ->execute([$ruleId, $rule['event_type'], $phone, $udata['fullname'], $msg, $status]);
        echo json_encode(['ok' => $sent, 'msg' => $sent ? 'پیامک آزمایشی با موفقیت ارسال شد' : 'خطا در ارسال پیامک']);
        exit;
    }

    // ---- لاگ ----
    if ($action === 'get_log') {
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $filterStatus = $_GET['status'] ?? '';
        $where = '';
        $params = [];
        if (in_array($filterStatus, ['sent','failed','pending'])) {
            $where = 'WHERE l.status = ?';
            $params[] = $filterStatus;
        }
        $total = $pdo->prepare("SELECT COUNT(*) FROM sms_automation_log l $where");
        $total->execute($params);
        $totalCount = (int)$total->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT l.*, r.name AS rule_name
            FROM sms_automation_log l
            LEFT JOIN sms_automation_rules r ON r.id = l.rule_id
            $where
            ORDER BY l.sent_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'data' => $rows, 'total' => $totalCount, 'page' => $page]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'عملیات نامعتبر']);
    exit;
}

// ===================================================================
// آمار خلاصه
// ===================================================================
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('-7 days'));

try {
    $statToday  = (int)$pdo->query("SELECT COUNT(*) FROM sms_automation_log WHERE DATE(sent_at)='$today'")->fetchColumn();
    $statWeek   = (int)$pdo->query("SELECT COUNT(*) FROM sms_automation_log WHERE sent_at >= '$weekStart'")->fetchColumn();
    $statOk     = (int)$pdo->query("SELECT COUNT(*) FROM sms_automation_log WHERE status='sent'")->fetchColumn();
    $statFail   = (int)$pdo->query("SELECT COUNT(*) FROM sms_automation_log WHERE status='failed'")->fetchColumn();
    $totalRules = (int)$pdo->query("SELECT COUNT(*) FROM sms_automation_rules")->fetchColumn();
    $activeRules= (int)$pdo->query("SELECT COUNT(*) FROM sms_automation_rules WHERE is_active=1")->fetchColumn();
} catch (Throwable $e) {
    $statToday = $statWeek = $statOk = $statFail = $totalRules = $activeRules = 0;
}

// ===================================================================
// HTML
// ===================================================================
$basePath = '../../';
$pageTitle = 'اتوماسیون پیامک';
$extraCss = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';
include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<div class="main-content" id="mainContent">
<div class="fin-page-wrap" style="padding:24px">

  <!-- عنوان صفحه -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-size:22px;font-weight:700;color:#1e293b;margin:0">اتوماسیون پیامک</h1>
      <p style="color:#64748b;font-size:13px;margin:4px 0 0">مدیریت قوانین ارسال خودکار پیامک بر اساس رویدادهای سیستم</p>
    </div>
    <div style="display:flex;gap:8px">
      <button class="fin-btn fin-btn-primary" onclick="openAddModal()">
        <i class="fas fa-plus"></i> قانون جدید
      </button>
    </div>
  </div>

  <!-- کارت‌های آماری -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px">
    <div class="fin-stat-card blue">
      <div class="fin-stat-icon"><i class="fas fa-paper-plane"></i></div>
      <div class="fin-stat-num"><?= number_format($statToday) ?></div>
      <div class="fin-stat-label">ارسال‌شده امروز</div>
    </div>
    <div class="fin-stat-card purple">
      <div class="fin-stat-icon"><i class="fas fa-calendar-week"></i></div>
      <div class="fin-stat-num"><?= number_format($statWeek) ?></div>
      <div class="fin-stat-label">این هفته</div>
    </div>
    <div class="fin-stat-card green">
      <div class="fin-stat-icon"><i class="fas fa-check-circle"></i></div>
      <div class="fin-stat-num"><?= number_format($statOk) ?></div>
      <div class="fin-stat-label">موفق</div>
    </div>
    <div class="fin-stat-card rose">
      <div class="fin-stat-icon"><i class="fas fa-times-circle"></i></div>
      <div class="fin-stat-num"><?= number_format($statFail) ?></div>
      <div class="fin-stat-label">ناموفق</div>
    </div>
    <div class="fin-stat-card amber">
      <div class="fin-stat-icon"><i class="fas fa-cogs"></i></div>
      <div class="fin-stat-num"><?= $activeRules ?> / <?= $totalRules ?></div>
      <div class="fin-stat-label">قوانین فعال</div>
    </div>
  </div>

  <!-- تب‌ها -->
  <div class="fin-tabs" style="margin-bottom:20px">
    <button class="fin-tab active" data-tab="rules" onclick="switchTab('rules',this)">
      <i class="fas fa-list-ul"></i> قوانین اتوماسیون
    </button>
    <button class="fin-tab" data-tab="log" onclick="switchTab('log',this)">
      <i class="fas fa-history"></i> لاگ ارسال
    </button>
  </div>

  <!-- تب: قوانین -->
  <div id="tab-rules">
    <div class="fin-panel">
      <div class="fin-panel-body" style="padding:0">
        <table class="fin-table" id="rulesTable">
          <thead>
            <tr>
              <th>رویداد</th>
              <th>نام قانون</th>
              <th>پیش‌نمایش پیام</th>
              <th>روز قبل</th>
              <th>وضعیت</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody id="rulesTbody">
            <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">در حال بارگذاری...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- تب: لاگ -->
  <div id="tab-log" style="display:none">
    <div class="fin-panel">
      <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <label style="font-size:13px;color:#475569">فیلتر وضعیت:</label>
        <select id="logStatusFilter" class="fin-input" style="width:140px" onchange="loadLog(1)">
          <option value="">همه</option>
          <option value="sent">موفق</option>
          <option value="failed">ناموفق</option>
          <option value="pending">در انتظار</option>
        </select>
        <button class="fin-btn fin-btn-sm" onclick="loadLog(1)"><i class="fas fa-sync"></i> بروزرسانی</button>
      </div>
      <div class="fin-panel-body" style="padding:0">
        <table class="fin-table">
          <thead>
            <tr>
              <th>قانون</th>
              <th>گیرنده</th>
              <th>شماره</th>
              <th>پیام</th>
              <th>وضعیت</th>
              <th>زمان ارسال</th>
            </tr>
          </thead>
          <tbody id="logTbody">
            <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">برای مشاهده لاگ روی تب «لاگ ارسال» کلیک کنید</td></tr>
          </tbody>
        </table>
        <div id="logPagination" style="padding:12px 20px;text-align:center;border-top:1px solid #f1f5f9"></div>
      </div>
    </div>
  </div>

</div>
</div>

<!-- ======= مودال ویرایش قانون ======= -->
<div id="ruleModal" class="fin-drawer-overlay" style="display:none" onclick="if(event.target===this)closeModal()">
  <div class="fin-drawer" style="width:560px;max-width:95vw;border-radius:16px 0 0 16px">
    <div class="fin-drawer-header">
      <h3 id="modalTitle" style="margin:0;font-size:16px">ویرایش قانون پیامک</h3>
      <button class="fin-drawer-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="fin-drawer-body" style="padding:24px">
      <input type="hidden" id="editRuleId">
      <div style="margin-bottom:16px">
        <label class="fin-label">نام قانون <span style="color:#e11d48">*</span></label>
        <input type="text" id="editName" class="fin-input" placeholder="مثال: یادآور سررسید فاکتور">
      </div>
      <div style="margin-bottom:16px">
        <label class="fin-label">نوع رویداد</label>
        <input type="text" id="editEventType" class="fin-input" readonly style="background:#f8fafc;cursor:default">
      </div>
      <div style="margin-bottom:8px">
        <label class="fin-label">متن پیامک <span style="color:#e11d48">*</span></label>
        <div style="margin-bottom:8px;display:flex;gap:6px;flex-wrap:wrap">
          <span style="font-size:11px;color:#64748b;align-self:center">متغیرها:</span>
          <?php foreach (['{نام}','{مبلغ}','{تاریخ}','{شماره}'] as $v): ?>
          <button type="button" class="fin-badge" style="cursor:pointer;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;padding:3px 10px;border-radius:20px;font-size:12px" onclick="insertVar('<?= $v ?>')"><?= $v ?></button>
          <?php endforeach; ?>
        </div>
        <textarea id="editTemplate" class="fin-input" rows="5" placeholder="متن پیامک را وارد کنید...&#10;مثال: {نام} عزیز، فاکتور {شماره} به مبلغ {مبلغ} ریال صادر شد." style="resize:vertical"></textarea>
        <div id="charCount" style="font-size:11px;color:#94a3b8;text-align:left;margin-top:4px">0 کاراکتر</div>
      </div>
      <div style="margin-bottom:16px">
        <label class="fin-label">روزهای قبل از رویداد <small style="color:#64748b">(۰ = همان روز)</small></label>
        <input type="number" id="editDaysBefore" class="fin-input" min="0" max="365" value="0" style="width:120px">
      </div>
      <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:24px">
        <button class="fin-btn" onclick="closeModal()">انصراف</button>
        <button class="fin-btn fin-btn-primary" onclick="saveRule()">
          <i class="fas fa-save"></i> ذخیره تغییرات
        </button>
      </div>
    </div>
  </div>
</div>

<!-- نوتیف -->
<div id="toastMsg" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:12px 24px;border-radius:10px;font-size:14px;z-index:9999;display:none;min-width:220px;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.25)"></div>

<script>
const CSRF = '<?= csrf_field() ?>'.match(/value="([^"]+)"/)?.[1] ?? '';

const EVENT_LABELS = {
    invoice_confirmed: '📄 تأیید فاکتور',
    payment_due:       '⏰ سررسید پرداخت',
    payment_received:  '💳 دریافت وجه',
    cheque_due:        '📋 سررسید چک',
    leave_approved:    '🌴 تأیید مرخصی',
    contract_expiry:   '📅 انقضای قرارداد',
    welcome:           '👋 خوش‌آمدگویی'
};

// ---- تب‌ها ----
function switchTab(name, btn) {
    document.querySelectorAll('.fin-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-rules').style.display = name === 'rules' ? '' : 'none';
    document.getElementById('tab-log').style.display   = name === 'log'   ? '' : 'none';
    if (name === 'log') loadLog(1);
}

// ---- بارگذاری قوانین ----
function loadRules() {
    $.get('', {action: 'list_rules'}, function(r) {
        if (!r.ok) return;
        const tbody = $('#rulesTbody');
        tbody.empty();
        r.data.forEach(function(rule) {
            const label = EVENT_LABELS[rule.event_type] || rule.event_type;
            const preview = rule.message_template.substring(0, 60) + (rule.message_template.length > 60 ? '...' : '');
            const checked = rule.is_active == 1 ? 'checked' : '';
            tbody.append(`
                <tr>
                  <td><span class="fin-badge" style="background:#f0f9ff;color:#0369a1;padding:3px 10px;border-radius:20px;font-size:12px">${label}</span></td>
                  <td style="font-weight:600;color:#1e293b">${rule.name}</td>
                  <td style="color:#64748b;font-size:12px;max-width:250px">${preview}</td>
                  <td style="text-align:center">${rule.send_days_before > 0 ? rule.send_days_before + ' روز قبل' : 'همان روز'}</td>
                  <td>
                    <label class="toggle-switch">
                      <input type="checkbox" ${checked} onchange="toggleRule(${rule.id}, this.checked)">
                      <span class="toggle-slider"></span>
                    </label>
                  </td>
                  <td>
                    <div style="display:flex;gap:6px">
                      <button class="fin-btn fin-btn-sm fin-btn-primary" onclick='editRule(${JSON.stringify(rule)})' title="ویرایش">
                        <i class="fas fa-edit"></i>
                      </button>
                      <button class="fin-btn fin-btn-sm" onclick="sendTest(${rule.id})" title="تست ارسال" style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0">
                        <i class="fas fa-paper-plane"></i> تست
                      </button>
                    </div>
                  </td>
                </tr>
            `);
        });
        if (!r.data.length) tbody.html('<tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">هیچ قانونی ثبت نشده است</td></tr>');
    });
}

// ---- ویرایش قانون ----
function editRule(rule) {
    $('#editRuleId').val(rule.id);
    $('#editName').val(rule.name);
    $('#editEventType').val(EVENT_LABELS[rule.event_type] || rule.event_type);
    $('#editTemplate').val(rule.message_template);
    $('#editDaysBefore').val(rule.send_days_before);
    updateCharCount();
    $('#modalTitle').text('ویرایش قانون: ' + rule.name);
    $('#ruleModal').fadeIn(200);
}

function openAddModal() {
    toast('قوانین از پیش تعریف شده‌اند — روی دکمه ویرایش کلیک کنید');
}

function closeModal() { $('#ruleModal').fadeOut(150); }

// ---- درج متغیر ----
function insertVar(v) {
    const ta = document.getElementById('editTemplate');
    const start = ta.selectionStart, end = ta.selectionEnd;
    const val = ta.value;
    ta.value = val.substring(0, start) + v + val.substring(end);
    ta.selectionStart = ta.selectionEnd = start + v.length;
    ta.focus();
    updateCharCount();
}

function updateCharCount() {
    const len = ($('#editTemplate').val() || '').length;
    $('#charCount').text(len + ' کاراکتر' + (len > 160 ? ' (بیش از یک پیامک)' : ''));
}
$('#editTemplate').on('input', updateCharCount);

// ---- ذخیره قانون ----
function saveRule() {
    const id   = $('#editRuleId').val();
    const name = $.trim($('#editName').val());
    const tmpl = $.trim($('#editTemplate').val());
    const days = $('#editDaysBefore').val();
    if (!name || !tmpl) { toast('نام و متن پیام الزامی است', 'error'); return; }
    $.post('', {action: 'save_rule', csrf: CSRF, id, name, message_template: tmpl, send_days_before: days}, function(r) {
        toast(r.msg || (r.ok ? 'ذخیره شد' : 'خطا'), r.ok ? 'success' : 'error');
        if (r.ok) { closeModal(); loadRules(); }
    });
}

// ---- تغییر وضعیت ----
function toggleRule(id, active) {
    $.post('', {action: 'toggle_rule', csrf: CSRF, id: id, is_active: active ? 1 : 0}, function(r) {
        toast(r.ok ? (active ? 'قانون فعال شد' : 'قانون غیرفعال شد') : 'خطا', r.ok ? 'success' : 'error');
        if (!r.ok) loadRules();
    });
}

// ---- تست ارسال ----
function sendTest(ruleId) {
    if (!confirm('پیامک آزمایشی به شماره موبایل شما ارسال شود؟')) return;
    $.post('', {action: 'send_test', csrf: CSRF, rule_id: ruleId}, function(r) {
        toast(r.msg || (r.ok ? 'ارسال شد' : 'خطا'), r.ok ? 'success' : 'error');
    });
}

// ---- لاگ ----
let currentLogPage = 1;
function loadLog(page) {
    currentLogPage = page || 1;
    const status = $('#logStatusFilter').val();
    $.get('', {action: 'get_log', page: currentLogPage, status: status}, function(r) {
        if (!r.ok) return;
        const tbody = $('#logTbody');
        tbody.empty();
        r.data.forEach(function(row) {
            const statusBadge = row.status === 'sent'
                ? '<span class="fin-badge" style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0">موفق</span>'
                : row.status === 'failed'
                ? '<span class="fin-badge" style="background:#fff1f2;color:#e11d48;border:1px solid #fecdd3">ناموفق</span>'
                : '<span class="fin-badge" style="background:#fffbeb;color:#d97706;border:1px solid #fde68a">در انتظار</span>';
            const msgPrev = (row.message || '').substring(0, 50) + ((row.message || '').length > 50 ? '...' : '');
            tbody.append(`
                <tr>
                  <td>${row.rule_name || '<span style="color:#94a3b8">دستی</span>'}</td>
                  <td>${row.recipient_name || '—'}</td>
                  <td style="direction:ltr;font-size:12px">${row.phone}</td>
                  <td style="font-size:12px;color:#64748b" title="${row.message || ''}">${msgPrev}</td>
                  <td>${statusBadge}</td>
                  <td style="font-size:12px;color:#64748b">${row.sent_at || '—'}</td>
                </tr>
            `);
        });
        if (!r.data.length) tbody.html('<tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">رکوردی یافت نشد</td></tr>');
        // صفحه‌بندی
        const totalPages = Math.ceil(r.total / 20);
        let pag = '';
        for (let i = 1; i <= totalPages; i++) {
            pag += `<button class="fin-btn fin-btn-sm ${i === currentLogPage ? 'fin-btn-primary' : ''}" style="margin:0 2px" onclick="loadLog(${i})">${i}</button>`;
        }
        $('#logPagination').html(pag);
    });
}

// ---- Toast ----
function toast(msg, type) {
    const el = document.getElementById('toastMsg');
    el.textContent = msg;
    el.style.background = type === 'error' ? '#dc2626' : type === 'success' ? '#16a34a' : '#1e293b';
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 3000);
}

// ---- استایل toggle ----
document.head.insertAdjacentHTML('beforeend', `<style>
.toggle-switch { position:relative; display:inline-block; width:44px; height:24px; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; cursor:pointer; inset:0; background:#cbd5e1; border-radius:24px; transition:.3s; }
.toggle-slider:before { content:''; position:absolute; width:18px; height:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.3s; }
.toggle-switch input:checked + .toggle-slider { background:#2563eb; }
.toggle-switch input:checked + .toggle-slider:before { transform:translateX(20px); }
</style>`);

$(function() { loadRules(); });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
