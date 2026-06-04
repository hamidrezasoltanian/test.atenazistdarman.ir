<?php
/*
 * فایل: public_html/admin/crm_followup.php
 * اتوماسیون پیگیری مشتریان — یادآوری خودکار برای مشتریانی که مدتی سفارش نداده‌اند
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
        $allowedRoles = ['admin','management','manager','sales','sales_manager','crm_manager'];
        if (in_array($roleName, $allowedRoles) || $roleData['is_system'] == 1) {
            $hasAccess = true;
        } else {
            $perms = json_decode($roleData['permissions'] ?? '[]', true) ?? [];
            if (in_array('crm', $perms) || in_array('all', $perms)) $hasAccess = true;
        }
    }
    if (in_array($rawRole, ['1','2','5','10','14'])) $hasAccess = true;
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_followup_rules` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `name`             VARCHAR(200) NOT NULL,
        `days_since_last_order` INT DEFAULT 30,
        `message_template` TEXT         DEFAULT NULL,
        `send_sms`         TINYINT(1)   DEFAULT 0,
        `is_active`        TINYINT(1)   DEFAULT 1,
        `created_by`       INT          DEFAULT NULL,
        `created_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_followup_log` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `rule_id`         INT          DEFAULT NULL,
        `person_id`       INT          DEFAULT NULL,
        `customer_name`   VARCHAR(200) DEFAULT NULL,
        `last_order_date` DATE         DEFAULT NULL,
        `sent_at`         DATETIME     DEFAULT CURRENT_TIMESTAMP,
        `sms_status`      VARCHAR(50)  DEFAULT NULL,
        `notes`           TEXT         DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ─── تابع ارسال SMS متن آزاد ─────────────────────────────
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

// ═══════════════════════════════════════════════════════════
// AJAX
// ═══════════════════════════════════════════════════════════
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_REQUEST['action'] ?? '');

    try {

        // ── لیست قوانین ──────────────────────────────────────
        if ($action === 'list_rules') {
            $rows = $pdo->query("
                SELECT r.*, u.full_name AS creator_name,
                       (SELECT COUNT(*) FROM crm_followup_log l WHERE l.rule_id=r.id) AS log_count
                FROM crm_followup_rules r
                LEFT JOIN users u ON u.id = r.created_by
                ORDER BY r.is_active DESC, r.created_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── ذخیره قانون ──────────────────────────────────────
        if ($action === 'save_rule') {
            csrf_verify();
            $id       = (int)($_POST['id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $days     = max(1, (int)($_POST['days_since_last_order'] ?? 30));
            $tpl      = trim($_POST['message_template'] ?? '');
            $sendSms  = (int)!empty($_POST['send_sms']);
            $isActive = (int)!empty($_POST['is_active']);

            if ($name === '') {
                echo json_encode(['ok' => false, 'msg' => 'نام قانون الزامی است']);
                exit;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE crm_followup_rules SET name=?, days_since_last_order=?,
                    message_template=?, send_sms=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $days, $tpl, $sendSms, $isActive, $id]);
                echo json_encode(['ok' => true, 'msg' => 'قانون ویرایش شد']);
            } else {
                $stmt = $pdo->prepare("INSERT INTO crm_followup_rules
                    (name, days_since_last_order, message_template, send_sms, is_active, created_by, created_at)
                    VALUES (?,?,?,?,?,?,NOW())");
                $stmt->execute([$name, $days, $tpl, $sendSms, $isActive, $userId]);
                echo json_encode(['ok' => true, 'msg' => 'قانون افزوده شد', 'id' => $pdo->lastInsertId()]);
            }
            exit;
        }

        // ── فعال/غیرفعال کردن قانون ────────────────────────
        if ($action === 'toggle_rule') {
            csrf_verify();
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE crm_followup_rules SET is_active = 1 - is_active WHERE id=?")
                ->execute([$id]);
            $newState = $pdo->query("SELECT is_active FROM crm_followup_rules WHERE id=$id")->fetchColumn();
            echo json_encode(['ok' => true, 'is_active' => (int)$newState]);
            exit;
        }

        // ── حذف قانون ────────────────────────────────────────
        if ($action === 'delete_rule') {
            csrf_verify();
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM crm_followup_rules WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'قانون حذف شد']);
            exit;
        }

        // ── مشتریان نیازمند پیگیری ──────────────────────────
        if ($action === 'get_customers_needing_followup') {
            $ruleId = (int)($_GET['rule_id'] ?? 0);

            // آستانه روز را از قانون بگیریم یا از پارامتر
            $threshold = 30;
            if ($ruleId > 0) {
                $r = $pdo->prepare("SELECT days_since_last_order FROM crm_followup_rules WHERE id=?");
                $r->execute([$ruleId]);
                $threshold = (int)($r->fetchColumn() ?: 30);
            } else {
                $threshold = max(1, (int)($_GET['days'] ?? 30));
            }

            $rows = $pdo->prepare("
                SELECT fp.id AS person_id,
                       COALESCE(fp.name, fp.company_name, '—') AS customer_name,
                       fp.mobile,
                       MAX(DATE(fi.created_at)) AS last_order_date,
                       DATEDIFF(NOW(), MAX(fi.created_at)) AS days_since,
                       COUNT(fi.id) AS order_count
                FROM fin_persons fp
                JOIN fin_invoices fi ON fi.person_id = fp.id
                WHERE fi.type='sell' AND fi.status='confirmed' AND fi.is_deleted=0
                  AND fp.is_deleted=0
                GROUP BY fp.id, fp.name, fp.company_name, fp.mobile
                HAVING days_since > ?
                ORDER BY days_since DESC
                LIMIT 200
            ");
            $rows->execute([$threshold]);
            $data = $rows->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'data' => $data, 'threshold' => $threshold]);
            exit;
        }

        // ── ثبت تماس در لاگ ──────────────────────────────────
        if ($action === 'log_contact') {
            csrf_verify();
            $ruleId   = (int)($_POST['rule_id'] ?? 0) ?: null;
            $personId = (int)($_POST['person_id'] ?? 0);
            $custName = trim($_POST['customer_name'] ?? '');
            $lastDate = trim($_POST['last_order_date'] ?? '') ?: null;
            $notes    = trim($_POST['notes'] ?? '');
            $smsStatus = trim($_POST['sms_status'] ?? '');

            if ($personId <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'شناسه مشتری نامعتبر']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO crm_followup_log
                (rule_id, person_id, customer_name, last_order_date, sent_at, sms_status, notes)
                VALUES (?,?,?,?,NOW(),?,?)");
            $stmt->execute([$ruleId, $personId, $custName, $lastDate, $smsStatus, $notes]);
            echo json_encode(['ok' => true, 'msg' => 'تماس ثبت شد']);
            exit;
        }

        // ── ارسال SMS ─────────────────────────────────────────
        if ($action === 'send_sms') {
            csrf_verify();
            $ruleId    = (int)($_POST['rule_id'] ?? 0) ?: null;
            $personId  = (int)($_POST['person_id'] ?? 0);
            $mobile    = trim($_POST['mobile'] ?? '');
            $message   = trim($_POST['message'] ?? '');
            $custName  = trim($_POST['customer_name'] ?? '');
            $lastDate  = trim($_POST['last_order_date'] ?? '') ?: null;

            if ($mobile === '' || $message === '') {
                echo json_encode(['ok' => false, 'msg' => 'شماره موبایل و متن پیام الزامی است']);
                exit;
            }

            $sent = sendFreeSms($mobile, $message);
            $status = $sent ? 'sent' : 'failed';

            // ثبت لاگ
            $stmt = $pdo->prepare("INSERT INTO crm_followup_log
                (rule_id, person_id, customer_name, last_order_date, sent_at, sms_status, notes)
                VALUES (?,?,?,?,NOW(),?,?)");
            $stmt->execute([$ruleId, $personId, $custName, $lastDate, $status, 'ارسال SMS']);

            echo json_encode([
                'ok'  => true,
                'sent' => $sent,
                'msg' => $sent ? 'پیامک با موفقیت ارسال شد' : 'ارسال پیامک ناموفق بود (ثبت در لاگ)'
            ]);
            exit;
        }

        // ── اجرا برای همه (bulk log) ─────────────────────────
        if ($action === 'bulk_log') {
            csrf_verify();
            $ruleId   = (int)($_POST['rule_id'] ?? 0) ?: null;
            $dataJson = $_POST['customers'] ?? '[]';
            $customers = json_decode($dataJson, true);

            if (!is_array($customers) || !count($customers)) {
                echo json_encode(['ok' => false, 'msg' => 'لیست مشتریان خالی است']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO crm_followup_log
                (rule_id, person_id, customer_name, last_order_date, sent_at, sms_status, notes)
                VALUES (?,?,?,?,NOW(),'pending','افزودن دسته‌جمعی')");

            $count = 0;
            foreach ($customers as $c) {
                $pid  = (int)($c['person_id'] ?? 0);
                $name = trim($c['customer_name'] ?? '');
                $date = trim($c['last_order_date'] ?? '') ?: null;
                if ($pid > 0) {
                    $stmt->execute([$ruleId, $pid, $name, $date]);
                    $count++;
                }
            }

            echo json_encode(['ok' => true, 'msg' => "$count مشتری به لیست پیگیری افزوده شد"]);
            exit;
        }

        // ── آمار کارت‌ها ─────────────────────────────────────
        if ($action === 'stats') {
            // مشتریان نیازمند پیگیری — با کمترین آستانه فعال
            $minDays = $pdo->query("SELECT COALESCE(MIN(days_since_last_order),30)
                FROM crm_followup_rules WHERE is_active=1")->fetchColumn();
            $minDays = (int)$minDays ?: 30;

            $needFollowup = $pdo->prepare("
                SELECT COUNT(DISTINCT fp.id)
                FROM fin_persons fp
                JOIN fin_invoices fi ON fi.person_id = fp.id
                WHERE fi.type='sell' AND fi.status='confirmed' AND fi.is_deleted=0
                  AND fp.is_deleted=0
                GROUP BY fp.id
                HAVING DATEDIFF(NOW(), MAX(fi.created_at)) > ?
            ");
            $needFollowup->execute([$minDays]);
            $needCount = count($needFollowup->fetchAll());

            $sentThisWeek = $pdo->query("
                SELECT COUNT(*) FROM crm_followup_log
                WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ")->fetchColumn();

            $activeRules = $pdo->query("SELECT COUNT(*) FROM crm_followup_rules WHERE is_active=1")
                ->fetchColumn();

            echo json_encode([
                'ok'           => true,
                'need_followup'=> (int)$needCount,
                'sent_week'    => (int)$sentThisWeek,
                'active_rules' => (int)$activeRules,
            ]);
            exit;
        }

        // ── لاگ ارسال‌ها ─────────────────────────────────────
        if ($action === 'get_log') {
            $limit = max(10, min(100, (int)($_GET['limit'] ?? 50)));
            $rows  = $pdo->prepare("
                SELECT l.*, r.name AS rule_name
                FROM crm_followup_log l
                LEFT JOIN crm_followup_rules r ON r.id = l.rule_id
                ORDER BY l.sent_at DESC
                LIMIT ?
            ");
            $rows->execute([$limit]);
            echo json_encode(['ok' => true, 'data' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'عملیات نامعتبر']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// HTML
// ═══════════════════════════════════════════════════════════
$pageTitle = 'پیگیری خودکار مشتریان';
$basePath  = '../../';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<link rel="stylesheet" href="<?= $basePath ?>public_html/assets/css/fin_module.css">
<style>
.rule-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 20px;
    background: #fff;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: box-shadow .2s;
}
.rule-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.07); }
.rule-card .rule-icon {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: #eff6ff;
    color: #3b82f6;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; flex-shrink: 0;
}
.rule-card .rule-info { flex: 1; min-width: 0; }
.rule-card .rule-name { font-weight: 700; font-size: .95rem; color: #1e293b; }
.rule-card .rule-meta { font-size: .78rem; color: #64748b; margin-top: 3px; }
.toggle-switch {
    position: relative; display: inline-block; width: 44px; height: 24px;
}
.toggle-switch input { display: none; }
.toggle-slider {
    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
    background: #cbd5e1; border-radius: 24px; transition: .3s;
}
.toggle-slider:before {
    position: absolute; content: ''; height: 18px; width: 18px;
    right: 3px; bottom: 3px;
    background: white; border-radius: 50%; transition: .3s;
}
input:checked + .toggle-slider { background: #22c55e; }
input:checked + .toggle-slider:before { transform: translateX(-20px); }

.customer-row-urgent { background: #fff7ed; }
.customer-row-critical { background: #fef2f2; }

.fin-tabs .tab-btn {
    cursor: pointer; padding: 8px 20px; border: none; background: transparent;
    border-bottom: 3px solid transparent;
    font-family: Vazirmatn, Tahoma, sans-serif;
    font-size: .9rem; color: #64748b; transition: all .2s;
}
.fin-tabs .tab-btn.active { border-bottom-color: #3b82f6; color: #1d4ed8; font-weight: 600; }
.tab-pane { display: none; }
.tab-pane.active { display: block; }
</style>

<div class="main-content" dir="rtl">
  <div style="padding:24px">

    <!-- سربرگ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <div>
        <h2 style="margin:0;font-size:1.4rem;font-weight:700;color:#1e293b">پیگیری خودکار مشتریان</h2>
        <p style="margin:4px 0 0;color:#64748b;font-size:.85rem">یادآوری خودکار برای مشتریانی که مدتی سفارش نداده‌اند</p>
      </div>
    </div>

    <!-- کارت‌های آمار -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px">
      <div class="fin-stat-card rose"><div class="label">نیازمند پیگیری</div><div class="value" id="statNeed">…</div></div>
      <div class="fin-stat-card green"><div class="label">ارسال‌شده این هفته</div><div class="value" id="statSent">…</div></div>
      <div class="fin-stat-card blue"><div class="label">قوانین فعال</div><div class="value" id="statRules">…</div></div>
    </div>

    <!-- تب‌ها -->
    <div class="fin-tabs" style="margin-bottom:16px;border-bottom:1px solid #e2e8f0">
      <button class="tab-btn active" data-tab="rules">⚙️ قوانین پیگیری</button>
      <button class="tab-btn" data-tab="customers">👥 مشتریان نیازمند پیگیری</button>
      <button class="tab-btn" data-tab="log">📋 تاریخچه ارسال</button>
    </div>

    <!-- تب قوانین -->
    <div class="tab-pane active" id="tab-rules">
      <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
        <button class="fin-btn blue" onclick="openRuleDrawer()">+ افزودن قانون</button>
      </div>
      <div id="rulesList">
        <div style="text-align:center;padding:40px;color:#94a3b8">در حال بارگذاری…</div>
      </div>
    </div>

    <!-- تب مشتریان -->
    <div class="tab-pane" id="tab-customers">
      <div class="fin-panel" style="margin-bottom:16px;padding:14px 16px">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <div>
            <label class="fin-label">قانون پیگیری</label>
            <select id="custRule" class="fin-input" style="width:220px">
              <option value="0">انتخاب قانون...</option>
            </select>
          </div>
          <div>
            <label class="fin-label">یا بازه دستی (روز)</label>
            <input type="number" id="custDays" class="fin-input" value="30" min="1" style="width:100px">
          </div>
          <button class="fin-btn blue" onclick="loadCustomers()">🔍 نمایش مشتریان</button>
          <button class="fin-btn amber" id="bulkLogBtn" onclick="bulkLog()" style="display:none">
            📋 افزودن همه به لیست پیگیری
          </button>
        </div>
      </div>

      <div class="fin-panel" style="overflow-x:auto">
        <table class="fin-table" id="custTable">
          <thead>
            <tr>
              <th><input type="checkbox" id="checkAll" onclick="toggleAll(this)"></th>
              <th>نام مشتری</th>
              <th>موبایل</th>
              <th>آخرین سفارش</th>
              <th>روزهای گذشته</th>
              <th>تعداد سفارش</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody id="custBody">
            <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8">ابتدا فیلتر را اعمال کنید</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- تب تاریخچه -->
    <div class="tab-pane" id="tab-log">
      <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
        <button class="fin-btn" onclick="loadLog()">🔄 بارگذاری</button>
      </div>
      <div class="fin-panel" style="overflow-x:auto">
        <table class="fin-table">
          <thead>
            <tr>
              <th>مشتری</th>
              <th>قانون</th>
              <th>آخرین سفارش</th>
              <th>تاریخ ارسال</th>
              <th>وضعیت SMS</th>
              <th>یادداشت</th>
            </tr>
          </thead>
          <tbody id="logBody">
            <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">بارگذاری نشده</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /padding -->
</div><!-- /main-content -->

<!-- ═══════════ Drawer قانون ═══════════ -->
<div class="fin-drawer-overlay" id="ruleDrawerOverlay" onclick="closeRuleDrawer()" style="display:none"></div>
<div class="fin-drawer" id="ruleDrawer" style="display:none;width:460px">
  <div class="fin-drawer-header">
    <span id="ruleDrawerTitle">افزودن قانون پیگیری</span>
    <button onclick="closeRuleDrawer()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#64748b">&times;</button>
  </div>
  <div class="fin-drawer-body" style="padding:20px;overflow-y:auto;max-height:calc(100vh - 80px)">
    <form id="ruleForm" onsubmit="saveRule(event)">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" id="rId" name="id" value="0">

      <div style="margin-bottom:14px">
        <label class="fin-label">نام قانون <span style="color:#e11d48">*</span></label>
        <input type="text" name="name" id="rName" class="fin-input" required style="width:100%"
               placeholder="مثال: پیگیری ۳۰ روزه">
      </div>

      <div style="margin-bottom:14px">
        <label class="fin-label">تعداد روز از آخرین سفارش</label>
        <input type="number" name="days_since_last_order" id="rDays" class="fin-input"
               min="1" value="30" style="width:100%">
        <small style="color:#94a3b8">مشتریانی که بیش از این روز سفارش نداده‌اند شامل می‌شوند</small>
      </div>

      <div style="margin-bottom:14px">
        <label class="fin-label">متن پیام پیش‌فرض</label>
        <textarea name="message_template" id="rTemplate" class="fin-input" rows="5" style="width:100%"
                  placeholder="سلام {نام} عزیز، {روز} روز است که سفارشی نداده‌اید. ما مشتاق خدمت‌رسانی مجدد هستیم."></textarea>
        <small style="color:#94a3b8">متغیرها: <code>{نام}</code> — <code>{روز}</code></small>
      </div>

      <div style="margin-bottom:14px;display:flex;align-items:center;gap:12px">
        <label class="fin-label" style="margin:0">ارسال SMS فعال</label>
        <label class="toggle-switch">
          <input type="checkbox" name="send_sms" id="rSendSms" value="1">
          <span class="toggle-slider"></span>
        </label>
      </div>

      <div style="margin-bottom:20px;display:flex;align-items:center;gap:12px">
        <label class="fin-label" style="margin:0">قانون فعال</label>
        <label class="toggle-switch">
          <input type="checkbox" name="is_active" id="rIsActive" value="1" checked>
          <span class="toggle-slider"></span>
        </label>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" class="fin-btn" onclick="closeRuleDrawer()">انصراف</button>
        <button type="submit" class="fin-btn blue" id="ruleSaveBtn">ذخیره</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════ Modal ارسال پیام ═══════════ -->
<div id="smsModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000"></div>
<div id="smsModal" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
     background:#fff;border-radius:14px;padding:28px;width:480px;max-width:94vw;z-index:1001;
     font-family:Vazirmatn,Tahoma,sans-serif" dir="rtl">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
    <h3 style="margin:0;font-size:1.1rem;color:#1e293b">ارسال پیام به مشتری</h3>
    <button onclick="closeSmsModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b">&times;</button>
  </div>
  <div style="margin-bottom:12px">
    <label class="fin-label">نام مشتری</label>
    <input type="text" id="smsCustomer" class="fin-input" readonly style="width:100%">
  </div>
  <div style="margin-bottom:12px">
    <label class="fin-label">موبایل</label>
    <input type="text" id="smsMobile" class="fin-input" style="width:100%" dir="ltr">
  </div>
  <div style="margin-bottom:12px">
    <label class="fin-label">متن پیام</label>
    <textarea id="smsText" class="fin-input" rows="5" style="width:100%"></textarea>
  </div>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
    <button class="fin-btn" onclick="closeSmsModal()">انصراف</button>
    <button class="fin-btn" onclick="logOnly()" style="background:#f1f5f9;color:#475569">فقط ثبت (بدون SMS)</button>
    <button class="fin-btn blue" onclick="sendSms()" id="smsBtn">📤 ارسال پیامک</button>
  </div>
</div>

<script>
// ─── وضعیت جهانی ───────────────────────────────────────────
let rulesCache     = [];
let currentCustomers = [];
let activeSmsData  = null;

// ─── بارگذاری اولیه ────────────────────────────────────────
$(function () {
    loadStats();
    loadRules();
    loadLog();

    $('.tab-btn').on('click', function () {
        $('.tab-btn').removeClass('active');
        $('.tab-pane').removeClass('active');
        $(this).addClass('active');
        $('#tab-' + $(this).data('tab')).addClass('active');
    });
});

// ─── آمار ──────────────────────────────────────────────────
function loadStats() {
    $.get('', { action: 'stats' }, function (r) {
        if (!r.ok) return;
        $('#statNeed').text(r.need_followup);
        $('#statSent').text(r.sent_week);
        $('#statRules').text(r.active_rules);
    });
}

// ─── قوانین ────────────────────────────────────────────────
function loadRules() {
    $.get('', { action: 'list_rules' }, function (r) {
        if (!r.ok) { $('#rulesList').html('<div style="color:#e11d48;padding:20px">خطا در بارگذاری قوانین</div>'); return; }
        rulesCache = r.data;

        // بارگذاری در selector مشتریان
        let opts = '<option value="0">انتخاب قانون...</option>';
        r.data.forEach(function (rule) {
            opts += `<option value="${rule.id}" data-days="${rule.days_since_last_order}"
                            data-tpl="${esc(rule.message_template || '')}"
                            data-sms="${rule.send_sms}">${esc(rule.name)} (${rule.days_since_last_order} روز)</option>`;
        });
        $('#custRule').html(opts);

        if (!r.data.length) {
            $('#rulesList').html('<div style="text-align:center;padding:40px;color:#94a3b8">هیچ قانونی تعریف نشده است</div>');
            return;
        }

        let html = '';
        r.data.forEach(function (rule) {
            const activeClass = rule.is_active == 1 ? 'checked' : '';
            html += `
              <div class="rule-card" id="ruleCard-${rule.id}">
                <div class="rule-icon">${rule.send_sms == 1 ? '📲' : '📋'}</div>
                <div class="rule-info">
                  <div class="rule-name">${esc(rule.name)}</div>
                  <div class="rule-meta">
                    هر <strong>${rule.days_since_last_order}</strong> روز بدون سفارش •
                    ${rule.send_sms == 1 ? '📲 SMS فعال' : '📋 بدون SMS'} •
                    ${rule.log_count} بار اجرا
                  </div>
                  ${rule.message_template ? `<div class="rule-meta" style="margin-top:5px;font-style:italic;color:#94a3b8">"${esc(rule.message_template.substring(0,80))}…"</div>` : ''}
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                  <label class="toggle-switch" title="${rule.is_active ? 'فعال' : 'غیرفعال'}">
                    <input type="checkbox" ${activeClass} onchange="toggleRule(${rule.id}, this)">
                    <span class="toggle-slider"></span>
                  </label>
                  <button class="fin-btn" style="padding:4px 10px;font-size:.75rem" onclick='editRule(${JSON.stringify(rule)})'>ویرایش</button>
                  <button class="fin-btn rose" style="padding:4px 10px;font-size:.75rem" onclick="deleteRule(${rule.id})">حذف</button>
                </div>
              </div>`;
        });
        $('#rulesList').html(html);
    });
}

// ─── drawer قانون ──────────────────────────────────────────
function openRuleDrawer(data) {
    if (data) {
        $('#ruleDrawerTitle').text('ویرایش قانون');
        $('#rId').val(data.id);
        $('#rName').val(data.name);
        $('#rDays').val(data.days_since_last_order);
        $('#rTemplate').val(data.message_template || '');
        $('#rSendSms').prop('checked', data.send_sms == 1);
        $('#rIsActive').prop('checked', data.is_active == 1);
    } else {
        $('#ruleDrawerTitle').text('افزودن قانون پیگیری');
        $('#ruleForm')[0].reset();
        $('#rId').val(0);
        $('#rIsActive').prop('checked', true);
    }
    $('#ruleDrawerOverlay, #ruleDrawer').show();
}

function editRule(data) { openRuleDrawer(data); }

function closeRuleDrawer() { $('#ruleDrawerOverlay, #ruleDrawer').hide(); }

// ─── ذخیره قانون ───────────────────────────────────────────
function saveRule(e) {
    e.preventDefault();
    $('#ruleSaveBtn').prop('disabled', true).text('در حال ذخیره…');
    const formData = $('#ruleForm').serialize() + '&action=save_rule';
    // افزودن وضعیت checkbox‌ها به صورت صریح
    const sendSms = $('#rSendSms').is(':checked') ? '&send_sms=1' : '&send_sms=0';
    const isActive = $('#rIsActive').is(':checked') ? '&is_active=1' : '&is_active=0';

    $.post('', formData + sendSms + isActive, function (r) {
        if (r.ok) {
            closeRuleDrawer();
            loadRules();
            loadStats();
            showToast(r.msg, 'success');
        } else {
            showToast(r.msg || 'خطا در ذخیره', 'error');
        }
    }).always(function () {
        $('#ruleSaveBtn').prop('disabled', false).text('ذخیره');
    });
}

// ─── فعال/غیرفعال ──────────────────────────────────────────
function toggleRule(id, cb) {
    $.post('', { action: 'toggle_rule', id: id, <?php if (function_exists('csrf_token')) echo "'csrf_token': '" . csrf_token() . "'"; ?> }, function (r) {
        if (!r.ok) { cb.checked = !cb.checked; showToast('خطا در تغییر وضعیت', 'error'); }
        else { loadStats(); }
    });
}

// ─── حذف قانون ─────────────────────────────────────────────
function deleteRule(id) {
    if (!confirm('آیا از حذف این قانون مطمئن هستید؟')) return;
    $.post('', { action: 'delete_rule', id: id, <?php if (function_exists('csrf_token')) echo "'csrf_token': '" . csrf_token() . "'"; ?> }, function (r) {
        if (r.ok) { loadRules(); loadStats(); showToast(r.msg, 'success'); }
        else showToast(r.msg || 'خطا', 'error');
    });
}

// ─── لیست مشتریان ──────────────────────────────────────────
function loadCustomers() {
    const ruleId = $('#custRule').val();
    const days   = $('#custDays').val();

    $('#custBody').html('<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8">در حال بارگذاری…</td></tr>');
    $('#bulkLogBtn').hide();

    $.get('', { action: 'get_customers_needing_followup', rule_id: ruleId, days: days }, function (r) {
        if (!r.ok || !r.data.length) {
            $('#custBody').html('<tr><td colspan="7" style="text-align:center;padding:30px;color:#28a745">هیچ مشتری نیازمند پیگیری یافت نشد 🎉</td></tr>');
            return;
        }

        currentCustomers = r.data;
        $('#bulkLogBtn').show();

        // قالب پیام از قانون انتخابی
        const selectedOpt = $('#custRule option:selected');
        const tpl = selectedOpt.data('tpl') || '';

        let html = '';
        r.data.forEach(function (c, i) {
            const urgentClass = c.days_since > 90 ? 'customer-row-critical' : (c.days_since > 60 ? 'customer-row-urgent' : '');
            const lastDate = c.last_order_date ? jdateFormat(c.last_order_date) : '—';
            html += `<tr class="${urgentClass}">
                <td><input type="checkbox" class="custCheck" value="${i}"></td>
                <td><strong>${esc(c.customer_name)}</strong></td>
                <td dir="ltr">${esc(c.mobile || '—')}</td>
                <td>${lastDate}</td>
                <td style="text-align:center">
                  <span style="font-weight:600;color:${c.days_since > 90 ? '#dc2626' : c.days_since > 60 ? '#d97706' : '#64748b'}">
                    ${c.days_since} روز
                  </span>
                </td>
                <td style="text-align:center">${c.order_count}</td>
                <td>
                  <button class="fin-btn" style="padding:4px 10px;font-size:.75rem"
                    onclick="openSmsModal(${JSON.stringify(c)}, ${JSON.stringify(tpl)})">
                    💬 ارسال پیام
                  </button>
                </td>
              </tr>`;
        });
        $('#custBody').html(html);
    });
}

// ─── انتخاب همه ────────────────────────────────────────────
function toggleAll(cb) {
    $('.custCheck').prop('checked', cb.checked);
}

// ─── bulk log ──────────────────────────────────────────────
function bulkLog() {
    const ruleId = $('#custRule').val();
    let selected = [];
    $('.custCheck:checked').each(function () {
        const idx = parseInt($(this).val());
        if (currentCustomers[idx]) selected.push(currentCustomers[idx]);
    });
    if (!selected.length) selected = currentCustomers;

    if (!confirm(`آیا از افزودن ${selected.length} مشتری به لیست پیگیری مطمئن هستید؟`)) return;

    $.post('', {
        action: 'bulk_log',
        rule_id: ruleId,
        customers: JSON.stringify(selected),
        <?php if (function_exists('csrf_token')) echo "'csrf_token': '" . csrf_token() . "'"; ?>
    }, function (r) {
        if (r.ok) { showToast(r.msg, 'success'); loadStats(); loadLog(); }
        else showToast(r.msg || 'خطا', 'error');
    });
}

// ─── Modal SMS ──────────────────────────────────────────────
function openSmsModal(customer, tpl) {
    activeSmsData = customer;
    const ruleId = parseInt($('#custRule').val()) || 0;
    const rule   = rulesCache.find(r => r.id == ruleId);

    let message = tpl || (rule ? rule.message_template : '') || '';
    // جایگزینی متغیرها
    message = message.replace(/\{نام\}/g, customer.customer_name || '');
    message = message.replace(/\{روز\}/g, customer.days_since || '');

    $('#smsCustomer').val(customer.customer_name);
    $('#smsMobile').val(customer.mobile || '');
    $('#smsText').val(message);

    $('#smsModalOverlay, #smsModal').show();
}

function closeSmsModal() {
    activeSmsData = null;
    $('#smsModalOverlay, #smsModal').hide();
}

// ─── ارسال SMS ──────────────────────────────────────────────
function sendSms() {
    if (!activeSmsData) return;
    const mobile  = $('#smsMobile').val().trim();
    const message = $('#smsText').val().trim();
    const ruleId  = parseInt($('#custRule').val()) || 0;

    if (!mobile) { showToast('شماره موبایل الزامی است', 'error'); return; }
    if (!message) { showToast('متن پیام الزامی است', 'error'); return; }

    $('#smsBtn').prop('disabled', true).text('در حال ارسال…');

    $.post('', {
        action: 'send_sms',
        rule_id: ruleId,
        person_id: activeSmsData.person_id,
        mobile: mobile,
        message: message,
        customer_name: activeSmsData.customer_name,
        last_order_date: activeSmsData.last_order_date || '',
        <?php if (function_exists('csrf_token')) echo "'csrf_token': '" . csrf_token() . "'"; ?>
    }, function (r) {
        closeSmsModal();
        showToast(r.msg, r.sent ? 'success' : 'error');
        if (r.ok) { loadStats(); loadLog(); }
    }).always(function () {
        $('#smsBtn').prop('disabled', false).text('📤 ارسال پیامک');
    });
}

// ─── فقط ثبت ───────────────────────────────────────────────
function logOnly() {
    if (!activeSmsData) return;
    const ruleId = parseInt($('#custRule').val()) || 0;
    $.post('', {
        action: 'log_contact',
        rule_id: ruleId,
        person_id: activeSmsData.person_id,
        customer_name: activeSmsData.customer_name,
        last_order_date: activeSmsData.last_order_date || '',
        sms_status: 'manual',
        notes: 'ثبت دستی بدون ارسال SMS',
        <?php if (function_exists('csrf_token')) echo "'csrf_token': '" . csrf_token() . "'"; ?>
    }, function (r) {
        closeSmsModal();
        showToast(r.ok ? r.msg : (r.msg || 'خطا'), r.ok ? 'success' : 'error');
        if (r.ok) { loadStats(); loadLog(); }
    });
}

// ─── تاریخچه ───────────────────────────────────────────────
function loadLog() {
    $.get('', { action: 'get_log', limit: 50 }, function (r) {
        if (!r.ok || !r.data.length) {
            $('#logBody').html('<tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8">هیچ رکوردی ثبت نشده</td></tr>');
            return;
        }
        let html = '';
        r.data.forEach(function (l) {
            const statusMap = { sent: '✅ ارسال شد', failed: '❌ ناموفق', manual: '📋 دستی', pending: '⏳ در انتظار' };
            const statusText = statusMap[l.sms_status] || (l.sms_status || '—');
            html += `<tr>
                <td><strong>${esc(l.customer_name || '—')}</strong></td>
                <td>${esc(l.rule_name || '—')}</td>
                <td>${l.last_order_date ? jdateFormat(l.last_order_date) : '—'}</td>
                <td>${jdateFormat(l.sent_at)}</td>
                <td>${statusText}</td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis">${esc(l.notes || '—')}</td>
              </tr>`;
        });
        $('#logBody').html(html);
    });
}

// ─── ابزارهای کمکی ─────────────────────────────────────────
function jdateFormat(dateStr) {
    if (!dateStr) return '—';
    // فرمت ساده — برگرداندن تاریخ میلادی چون jdate() در PHP است
    return dateStr.substring(0, 10);
}
function esc(s) {
    return $('<span>').text(s || '').html();
}
function showToast(msg, type) {
    const color = type === 'success' ? '#065f46' : '#991b1b';
    const bg    = type === 'success' ? '#d1fae5' : '#fee2e2';
    const el    = $('<div>').text(msg).css({
        position: 'fixed', bottom: '24px', left: '50%', transform: 'translateX(-50%)',
        background: bg, color: color, padding: '10px 24px', borderRadius: '8px',
        fontFamily: 'Vazirmatn,Tahoma,sans-serif', zIndex: 9999, fontSize: '.9rem',
        boxShadow: '0 4px 12px rgba(0,0,0,.1)'
    }).appendTo('body');
    setTimeout(() => el.fadeOut(400, () => el.remove()), 3500);
}
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
