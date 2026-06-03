<?php
/*
 * فایل: public_html/admin/crm_weekplan.php
 * ماژول CRM — برنامه هفتگی فروش
 * ویزیت حضوری → mission_items | تماس تلفنی → crm_opportunity_calls
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'نشست منقضی']); exit; }
    header('Location: ../login.php'); exit;
}

// ─── ساخت جداول مورد نیاز ────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_weekplan` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `week_start` DATE NOT NULL COMMENT 'شروع هفته — شنبه',
        `user_id` INT NOT NULL,
        `customer_id` INT NOT NULL COMMENT 'از جدول customers',
        `action_type` ENUM('call','visit') DEFAULT 'call' COMMENT 'تماس=call ویزیت=visit',
        `scheduled_date` DATE DEFAULT NULL,
        `done` TINYINT DEFAULT 0,
        `done_date` DATE DEFAULT NULL,
        `linked_call_id` INT DEFAULT NULL COMMENT 'crm_opportunity_calls.id',
        `linked_mission_item_id` INT DEFAULT NULL COMMENT 'mission_items.id',
        `notes` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_week_user` (`week_start`, `user_id`),
        KEY `idx_customer` (`customer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // امکان nullable برای opportunity_id در جدول تماس‌ها (برای تماس‌های مستقیم از برنامه هفته)
    try { $pdo->exec("ALTER TABLE `crm_opportunity_calls` MODIFY `opportunity_id` INT(11) DEFAULT NULL"); } catch(Throwable $e) {}
    try { $pdo->exec("ALTER TABLE `crm_opportunity_calls` ADD COLUMN `customer_id` INT(11) DEFAULT NULL AFTER `opportunity_id`"); } catch(Throwable $e) {}

    // auto-create نوع ماموریت "ویزیت فروش"
    $check = $pdo->query("SELECT id FROM mission_types WHERE title='ویزیت فروش' LIMIT 1")->fetchColumn();
    if (!$check) { $pdo->exec("INSERT INTO mission_types (title, is_active) VALUES ('ویزیت فروش', 1)"); }
} catch (Throwable $e) {}

// ─── AJAX handlers ────────────────────────────────────────────
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action  = $_POST['action'] ?? '';
    $userId  = (int)$_SESSION['user_id'];

    // جستجوی مراکز برای افزودن به برنامه
    if ($action === 'search_customers') {
        $q = trim($_POST['q'] ?? '');
        if (strlen($q) < 1) { echo json_encode(['ok'=>true,'data',[]]); exit; }
        $stmt = $pdo->prepare("SELECT id, company_name, company_code, state FROM customers WHERE (company_name LIKE ? OR company_code LIKE ?) LIMIT 20");
        $stmt->execute(["%$q%", "%$q%"]);
        echo json_encode(['ok'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    // افزودن مرکز به برنامه هفته
    if ($action === 'add_to_plan') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $weekStart  = trim($_POST['week_start'] ?? '');
        $actionType = in_array($_POST['action_type'] ?? '', ['call','visit']) ? $_POST['action_type'] : 'call';
        $targetUser = (int)($_POST['target_user_id'] ?? $userId);
        if (!$customerId || !$weekStart) { echo json_encode(['ok'=>false,'msg'=>'اطلاعات ناقص']); exit; }

        // جلوگیری از تکرار
        $exists = $pdo->prepare("SELECT id FROM crm_weekplan WHERE week_start=? AND user_id=? AND customer_id=?");
        $exists->execute([$weekStart, $targetUser, $customerId]);
        if ($exists->fetchColumn()) { echo json_encode(['ok'=>false,'msg'=>'این مرکز قبلاً در برنامه این هفته افزوده شده']); exit; }

        $pdo->prepare("INSERT INTO crm_weekplan (week_start, user_id, customer_id, action_type) VALUES (?,?,?,?)")
            ->execute([$weekStart, $targetUser, $customerId, $actionType]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit;
    }

    // تنظیم تاریخ و نوع برنامه
    if ($action === 'set_schedule') {
        $planId     = (int)($_POST['plan_id'] ?? 0);
        $date       = trim($_POST['scheduled_date'] ?? '');
        $actionType = in_array($_POST['action_type'] ?? '', ['call','visit']) ? $_POST['action_type'] : 'call';
        $pdo->prepare("UPDATE crm_weekplan SET scheduled_date=?, action_type=? WHERE id=? AND (user_id=? OR 1=?)")
            ->execute([$date ?: null, $actionType, $planId, $userId, ($_SESSION['role']==='admin'?1:0)]);
        echo json_encode(['ok'=>true]); exit;
    }

    // علامت‌گذاری انجام شد → ایجاد رکورد تماس یا ماموریت
    if ($action === 'mark_done') {
        $planId  = (int)($_POST['plan_id'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');
        $doneDate = date('Y-m-d');

        $plan = $pdo->prepare("SELECT * FROM crm_weekplan WHERE id=?");
        $plan->execute([$planId]);
        $plan = $plan->fetch(PDO::FETCH_ASSOC);
        if (!$plan || $plan['done']) { echo json_encode(['ok'=>false,'msg'=>'آیتم یافت نشد یا قبلاً انجام شده']); exit; }

        $linkedId = null;

        if ($plan['action_type'] === 'call') {
            // پیدا کردن فرصت فعال مشتری (یا null)
            $oppId = $pdo->prepare("SELECT id FROM crm_opportunities WHERE customer_id=? AND status='active' ORDER BY id DESC LIMIT 1");
            $oppId->execute([$plan['customer_id']]);
            $oppId = $oppId->fetchColumn() ?: null;

            $callDate = $plan['scheduled_date'] ?: $doneDate;
            $pdo->prepare("INSERT INTO crm_opportunity_calls (opportunity_id, customer_id, user_id, subject, status, related_to, call_date, call_type, description)
                           VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$oppId, $plan['customer_id'], $userId, 'برنامه هفتگی', 'completed', 'customer', $callDate, 'outbound', $notes]);
            $linkedId = $pdo->lastInsertId();
            $pdo->prepare("UPDATE crm_weekplan SET done=1, done_date=?, linked_call_id=?, notes=? WHERE id=?")
                ->execute([$doneDate, $linkedId, $notes, $planId]);

        } else { // visit
            $visitTypeId = $pdo->query("SELECT id FROM mission_types WHERE title='ویزیت فروش' LIMIT 1")->fetchColumn();
            if (!$visitTypeId) {
                $pdo->exec("INSERT INTO mission_types (title, is_active) VALUES ('ویزیت فروش', 1)");
                $visitTypeId = $pdo->lastInsertId();
            }
            $deptId = $pdo->prepare("SELECT department_id FROM users WHERE id=? LIMIT 1");
            $deptId->execute([$userId]);
            $deptId = $deptId->fetchColumn() ?: null;
            $fyId = $pdo->query("SELECT id FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetchColumn() ?: null;

            // ایجاد mission_request
            $pdo->prepare("INSERT INTO mission_requests (user_id, department_id, fiscal_year_id, status, created_at) VALUES (?,?,?,'approved',NOW())")
                ->execute([$userId, $deptId, $fyId]);
            $reqId = $pdo->lastInsertId();

            $visitDate = $plan['scheduled_date'] ?: $doneDate;
            $pdo->prepare("INSERT INTO mission_items (request_id, type_id, customer_id, mission_date, description) VALUES (?,?,?,?,?)")
                ->execute([$reqId, $visitTypeId, $plan['customer_id'], $visitDate, $notes ?: 'ویزیت از برنامه هفتگی CRM']);
            $linkedId = $pdo->lastInsertId();
            $pdo->prepare("UPDATE crm_weekplan SET done=1, done_date=?, linked_mission_item_id=?, notes=? WHERE id=?")
                ->execute([$doneDate, $linkedId, $notes, $planId]);
        }

        echo json_encode(['ok'=>true,'linked_id'=>$linkedId,'type'=>$plan['action_type']]); exit;
    }

    // حذف آیتم
    if ($action === 'remove') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_weekplan WHERE id=? AND user_id=?")->execute([$planId, $userId]);
        echo json_encode(['ok'=>true]); exit;
    }

    // دریافت آیتم‌های یک هفته
    if ($action === 'get_week') {
        $weekStart  = trim($_POST['week_start'] ?? '');
        $filterUser = (int)($_POST['filter_user'] ?? 0);
        $isAdmin    = ($_SESSION['role'] === 'admin');

        if ($isAdmin && $filterUser > 0) {
            $whereUser = 'AND wp.user_id = ' . $filterUser;
        } elseif (!$isAdmin) {
            $whereUser = 'AND wp.user_id = ' . $userId;
        } else {
            $whereUser = '';
        }

        $stmt = $pdo->prepare("
            SELECT wp.*, c.company_name, c.state, c.type_name,
                   u.first_name, u.last_name
            FROM crm_weekplan wp
            JOIN customers c ON c.id = wp.customer_id
            JOIN users u ON u.id = wp.user_id
            WHERE wp.week_start = ? $whereUser
            ORDER BY wp.scheduled_date ASC, wp.id ASC
        ");
        $stmt->execute([$weekStart]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'data'=>$rows]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'عملیات نامعتبر']); exit;
}

// ─── محاسبه هفته‌های شمسی ─────────────────────────────────────
function gregorianToJalali($gy, $gm, $gd) {
    $g = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = ($gm > 2) ? $gy + 1 : $gy;
    $days = 355666 + (365*$gy) + intval(($gy2+3)/4) - intval(($gy2+99)/100) + intval(($gy2+399)/400) + $gd + $g[$gm-1];
    $jy = -1595 + 33 * intval($days/12053);
    $days %= 12053;
    $jy += 4 * intval($days/1461);
    $days %= 1461;
    if ($days > 365) { $jy += intval(($days-1)/365); $days = ($days-1)%365; }
    $jm = $days < 186 ? 1+intval($days/31) : 7+intval(($days-186)/30);
    $jd = 1 + ($days < 186 ? $days%31 : ($days-186)%30);
    return [$jy,$jm,$jd];
}
function jalaliToGregorian($jy,$jm,$jd) {
    $jy2 = $jy + 1595;
    $days = -355668 + (365*$jy2) + (intval($jy2/33)*8) + intval((($jy2%33)+3)/4) + $jd + (($jm<7)?($jm-1)*31:(($jm-7)*30)+186);
    $gy = 400*intval($days/146097); $days %= 146097;
    if ($days > 36524) { $gy += 100*intval(--$days/36524); $days %= 36524; if ($days >= 365) $days++; }
    $gy += 4*intval($days/1461); $days %= 1461;
    if ($days > 365) { $gy += intval(($days-1)/365); $days = ($days-1)%365; }
    $gd = $days + 1;
    $sal_a = [0,31,29,31,30,31,30,31,31,30,31,30,31]; $gm = 0;
    for (; $gm<13 && $gd>$sal_a[$gm]; $gm++) $gd -= $sal_a[$gm];
    return [$gy,$gm,$gd];
}
function jWeekStart($jy,$jm,$jd) {
    // پیدا کردن شنبه قبلی (روز ۰ = شنبه در تقویم شمسی)
    [$gy,$gm,$gd] = jalaliToGregorian($jy,$jm,$jd);
    $dow = (date('w', mktime(0,0,0,$gm,$gd,$gy)) + 1) % 7; // شنبه=0
    $ts = mktime(0,0,0,$gm,$gd-$dow,$gy);
    return gregorianToJalali(date('Y',$ts),(int)date('m',$ts),(int)date('d',$ts));
}
function jAdd($jy,$jm,$jd,$n) {
    [$gy,$gm,$gd] = jalaliToGregorian($jy,$jm,$jd);
    $ts = mktime(0,0,0,$gm,$gd+$n,$gy);
    return gregorianToJalali(date('Y',$ts),(int)date('m',$ts),(int)date('d',$ts));
}
function jStr($j) { return sprintf('%04d/%02d/%02d',$j[0],$j[1],$j[2]); }
function gStrFromJ($jy,$jm,$jd) {
    [$gy,$gm,$gd] = jalaliToGregorian($jy,$jm,$jd);
    return sprintf('%04d-%02d-%02d',$gy,$gm,$gd);
}

// هفته جاری
$today = new DateTime();
$todayJ = gregorianToJalali((int)$today->format('Y'),(int)$today->format('m'),(int)$today->format('d'));
$weekStartJ = jWeekStart($todayJ[0],$todayJ[1],$todayJ[2]);

// تولید ۱۲ هفته (۴ قبل + جاری + ۷ آینده)
$weeks = [];
for ($i = -4; $i <= 7; $i++) {
    $ws = jAdd($weekStartJ[0],$weekStartJ[1],$weekStartJ[2], $i*7);
    $we = jAdd($ws[0],$ws[1],$ws[2], 6);
    $gws = gStrFromJ($ws[0],$ws[1],$ws[2]);
    $weeks[] = ['gdate'=>$gws, 'label'=> jStr($ws).' — '.jStr($we), 'isCurrent'=>($i===0)];
}

$jDayNames = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنج‌شنبه','جمعه'];
$isManager = ($_SESSION['role'] ?? '') === 'admin';
$currentUserId = (int)$_SESSION['user_id'];
$experts = $pdo->query("SELECT id, first_name, last_name FROM users WHERE status='active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'برنامه هفتگی فروش';
$basePath = '../../';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<style>
.wp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-top:16px}
.wp-day{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;min-height:180px}
.wp-day.today{border-color:#0ea5e9;box-shadow:0 0 0 2px #0ea5e933}
.wp-day-head{padding:8px 10px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:11px;font-weight:700;color:#475569;text-align:center}
.wp-day.today .wp-day-head{background:#eff6ff;color:#0ea5e9}
.wp-day-body{padding:6px;display:flex;flex-direction:column;gap:5px}
.wp-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:8px;font-size:11px;cursor:default;position:relative}
.wp-card.call{border-right:3px solid #0ea5e9}
.wp-card.visit{border-right:3px solid #8b5cf6}
.wp-card.done{opacity:.55;text-decoration:line-through}
.wp-card-name{font-weight:600;color:#1e293b;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wp-card-meta{color:#64748b;font-size:10px}
.wp-card-actions{display:flex;gap:3px;margin-top:5px}
.wp-btn{border:none;border-radius:4px;padding:3px 7px;font-size:10px;cursor:pointer;font-family:inherit;font-weight:600}
.wp-unsched{background:#fff;border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;margin-top:16px}
@media(max-width:900px){.wp-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.wp-grid{grid-template-columns:1fr}}
</style>

<div class="main-content" style="padding:20px;direction:rtl">
  <!-- نوار ابزار -->
  <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px">
    <h2 style="margin:0;font-size:16px;font-weight:700;color:#1e293b;margin-left:auto">📋 برنامه هفتگی فروش</h2>
    <?php if ($isManager): ?>
    <select id="filterUser" onchange="loadWeek()" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
      <option value="0">همه کارشناسان</option>
      <?php foreach ($experts as $e): ?>
      <option value="<?= $e['id'] ?>" <?= $e['id']==$currentUserId?'selected':'' ?>><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select id="weekSel" onchange="loadWeek()" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit">
      <?php foreach ($weeks as $w): ?>
      <option value="<?= $w['gdate'] ?>" <?= $w['isCurrent']?'selected':'' ?>><?= $w['label'] ?></option>
      <?php endforeach; ?>
    </select>
    <button onclick="openAddModal()" style="padding:8px 14px;background:#0ea5e9;color:#fff;border:none;border-radius:8px;font-size:13px;font-family:inherit;font-weight:600;cursor:pointer">+ افزودن مرکز</button>
  </div>

  <!-- نوار پیشرفت -->
  <div id="progressBar" style="display:none;margin-bottom:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;display:flex;align-items:center;gap:12px">
    <span style="font-size:12px;color:#64748b;white-space:nowrap">پیشرفت هفته:</span>
    <div style="flex:1;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden">
      <div id="progressFill" style="height:100%;background:#22c55e;transition:width .3s;width:0%"></div>
    </div>
    <span id="progressText" style="font-size:12px;font-weight:700;color:#16a34a;white-space:nowrap">0 / 0</span>
  </div>

  <!-- گرید ۷ روزه -->
  <div class="wp-grid" id="wpGrid">
    <?php
    foreach ($jDayNames as $i => $dayName):
        $dayJ = jAdd($weekStartJ[0],$weekStartJ[1],$weekStartJ[2], $i);
        $dayGStr = gStrFromJ($dayJ[0],$dayJ[1],$dayJ[2]);
        $isToday = ($dayGStr === $today->format('Y-m-d'));
    ?>
    <div class="wp-day <?= $isToday?'today':'' ?>" id="day_<?= $i ?>">
      <div class="wp-day-head"><?= $dayName ?><br><small><?= sprintf('%02d/%02d',$dayJ[1],$dayJ[2]) ?></small></div>
      <div class="wp-day-body" id="dayBody_<?= $i ?>">
        <div style="text-align:center;padding:20px;color:#cbd5e1;font-size:11px">—</div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- بدون تاریخ -->
  <div id="unschedSection" style="display:none" class="wp-unsched">
    <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:8px">⚠ مراکز بدون تاریخ مشخص</div>
    <div id="unschedList" style="display:flex;flex-wrap:wrap;gap:8px"></div>
  </div>
</div>

<!-- مودال افزودن مرکز -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;width:420px;max-width:95vw">
    <h3 style="margin:0 0 16px;font-size:14px;color:#1e293b">افزودن مرکز به برنامه هفته</h3>
    <input type="text" id="customerSearch" placeholder="🔍 نام یا کد مرکز را تایپ کنید..." oninput="searchCustomers()"
           style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;box-sizing:border-box;margin-bottom:8px">
    <div id="customerResults" style="max-height:180px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:12px;display:none"></div>
    <div id="selectedCustomer" style="display:none;background:#f0f9ff;border:1px solid #0ea5e9;border-radius:8px;padding:10px;margin-bottom:12px;font-size:12px"></div>
    <div style="display:flex;gap:10px;margin-bottom:12px">
      <div style="flex:1">
        <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:4px">نوع برنامه:</label>
        <select id="addActionType" style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit">
          <option value="call">📞 تماس تلفنی</option>
          <option value="visit">🤝 ویزیت حضوری</option>
        </select>
      </div>
      <?php if ($isManager): ?>
      <div style="flex:1">
        <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:4px">کارشناس:</label>
        <select id="addTargetUser" style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit">
          <?php foreach ($experts as $e): ?>
          <option value="<?= $e['id'] ?>" <?= $e['id']==$currentUserId?'selected':'' ?>><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button onclick="closeAddModal()" style="padding:8px 16px;border:1px solid #e2e8f0;border-radius:7px;cursor:pointer;font-family:inherit;font-size:13px;background:#f8fafc">انصراف</button>
      <button onclick="addToPlan()" style="padding:8px 16px;background:#0ea5e9;color:#fff;border:none;border-radius:7px;cursor:pointer;font-family:inherit;font-size:13px;font-weight:600">➕ افزودن</button>
    </div>
  </div>
</div>

<!-- مودال تنظیم تاریخ -->
<div id="schedModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9001;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;width:360px;max-width:95vw">
    <h3 style="margin:0 0 16px;font-size:14px;color:#1e293b">📅 تنظیم برنامه — <span id="schedName"></span></h3>
    <div style="margin-bottom:12px">
      <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:4px">نوع برنامه:</label>
      <select id="schedType" style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit">
        <option value="call">📞 تماس تلفنی</option>
        <option value="visit">🤝 ویزیت حضوری</option>
      </select>
    </div>
    <div style="margin-bottom:16px">
      <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:4px">تاریخ (اختیاری):</label>
      <input type="text" id="schedDate" placeholder="YYYY/MM/DD" style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit;box-sizing:border-box">
      <div style="font-size:10px;color:#94a3b8;margin-top:4px">فرمت شمسی: مثلاً ۱۴۰۳/۰۱/۱۵</div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button onclick="document.getElementById('schedModal').style.display='none'" style="padding:7px 14px;border:1px solid #e2e8f0;border-radius:7px;cursor:pointer;font-family:inherit;font-size:13px;background:#f8fafc">انصراف</button>
      <button onclick="saveSchedule()" style="padding:7px 14px;background:#0ea5e9;color:#fff;border:none;border-radius:7px;cursor:pointer;font-family:inherit;font-size:13px;font-weight:600">💾 ذخیره</button>
    </div>
  </div>
</div>

<!-- مودال انجام شد -->
<div id="doneModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9001;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;width:380px;max-width:95vw">
    <h3 style="margin:0 0 16px;font-size:14px;color:#1e293b">✅ ثبت نتیجه — <span id="doneName"></span></h3>
    <div id="doneTypeInfo" style="font-size:12px;color:#64748b;margin-bottom:12px"></div>
    <textarea id="doneNotes" placeholder="توضیحات (اختیاری)..." rows="3"
              style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;box-sizing:border-box;margin-bottom:16px"></textarea>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button onclick="document.getElementById('doneModal').style.display='none'" style="padding:7px 14px;border:1px solid #e2e8f0;border-radius:7px;cursor:pointer;font-family:inherit;font-size:13px;background:#f8fafc">انصراف</button>
      <button onclick="confirmDone()" style="padding:7px 14px;background:#16a34a;color:#fff;border:none;border-radius:7px;cursor:pointer;font-family:inherit;font-size:13px;font-weight:600">✅ ثبت انجام</button>
    </div>
  </div>
</div>

<script>
var _selectedCustomerId = 0;
var _selectedCustomerName = '';
var _currentPlanId = 0;
var _currentActionType = 'call';
var _searchTimer = null;

// تبدیل تاریخ شمسی به میلادی (برای ارسال به سرور)
function jalaliToGregorian(jy,jm,jd) {
    var jy2=jy+1595,days=-355668+(365*jy2)+(Math.floor(jy2/33)*8)+Math.floor(((jy2%33)+3)/4)+jd+((jm<7)?(jm-1)*31:((jm-7)*30)+186);
    var gy=400*Math.floor(days/146097);days%=146097;
    if(days>36524){gy+=100*Math.floor(--days/36524);days%=36524;if(days>=365)days++;}
    gy+=4*Math.floor(days/1461);days%=1461;
    if(days>365){gy+=Math.floor((days-1)/365);days=(days-1)%365;}
    var gd=days+1;var sal_a=[0,31,29,31,30,31,30,31,31,30,31,30,31];var gm=0;
    for(;gm<13&&gd>sal_a[gm];gm++)gd-=sal_a[gm];
    return [gy,gm,gd];
}
function jDateToGDate(jStr) {
    var p=jStr.replace(/[۰-۹]/g,function(d){return'0123456789'['۰۱۲۳۴۵۶۷۸۹'.indexOf(d)];}).split('/');
    if(p.length!==3)return '';
    var g=jalaliToGregorian(parseInt(p[0]),parseInt(p[1]),parseInt(p[2]));
    return g[0]+'-'+String(g[1]).padStart(2,'0')+'-'+String(g[2]).padStart(2,'0');
}

function post(data) {
    return fetch(location.href, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body: Object.entries(data).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&')
    }).then(r=>r.json());
}

function loadWeek() {
    var weekStart = document.getElementById('weekSel').value;
    var filterUser = document.getElementById('filterUser') ? document.getElementById('filterUser').value : 0;
    post({action:'get_week', week_start:weekStart, filter_user:filterUser}).then(function(d) {
        if (!d.ok) return;
        renderWeek(d.data, weekStart);
    });
}

function gDateToJStr(gDate) {
    // تبدیل میلادی به شمسی برای نمایش
    var parts = gDate.split('-');
    if (parts.length !== 3) return gDate;
    var gy=parseInt(parts[0]),gm=parseInt(parts[1]),gd=parseInt(parts[2]);
    var g_dm=[0,31,59,90,120,151,181,212,243,273,304,334];
    var gy2=(gm>2)?(gy+1):gy;
    var days=355666+(365*gy)+Math.floor((gy2+3)/4)-Math.floor((gy2+99)/100)+Math.floor((gy2+399)/400)+gd+g_dm[gm-1];
    var jy=-1595+(33*Math.floor(days/12053));days%=12053;
    jy+=4*Math.floor(days/1461);days%=1461;
    if(days>365){jy+=Math.floor((days-1)/365);days=(days-1)%365;}
    var jm=(days<186)?1+Math.floor(days/31):7+Math.floor((days-186)/30);
    var jd=1+((days<186)?(days%31):((days-186)%30));
    return jy+'/'+(jm<10?'0':'')+jm+'/'+(jd<10?'0':'')+jd;
}

function getWeekDayGDates() {
    var weekStart = document.getElementById('weekSel').value;
    var dates = [];
    for (var i=0; i<7; i++) {
        var d = new Date(weekStart); d.setDate(d.getDate()+i);
        dates.push(d.toISOString().split('T')[0]);
    }
    return dates;
}

function renderWeek(items, weekStart) {
    var dayDates = getWeekDayGDates();
    var dayBuckets = {};
    dayDates.forEach(function(d){dayBuckets[d]=[];});
    var unsched = [];
    items.forEach(function(item) {
        if (item.scheduled_date && dayBuckets[item.scheduled_date] !== undefined) {
            dayBuckets[item.scheduled_date].push(item);
        } else {
            unsched.push(item);
        }
    });
    // رندر هر روز
    dayDates.forEach(function(gDate, i) {
        var dayItems = dayBuckets[gDate] || [];
        var html = '';
        dayItems.forEach(function(it){ html += renderCard(it); });
        if (!html) html = '<div style="text-align:center;padding:20px;color:#cbd5e1;font-size:11px">—</div>';
        document.getElementById('dayBody_'+i).innerHTML = html;
    });
    // بدون تاریخ
    if (unsched.length) {
        document.getElementById('unschedSection').style.display = '';
        var html = '';
        unsched.forEach(function(it){
            html += '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:11px;min-width:160px;max-width:200px">';
            html += '<div style="font-weight:600;color:#1e293b;margin-bottom:4px">'+esc(it.company_name)+'</div>';
            html += '<div style="font-size:10px;color:#64748b;margin-bottom:6px">';
            html += it.action_type==='visit'?'🤝 ویزیت':'📞 تماس';
            html += ' · '+esc(it.first_name+' '+it.last_name)+'</div>';
            html += '<div style="display:flex;gap:4px">';
            html += '<button class="wp-btn" style="background:#f0f9ff;color:#0369a1;border:1px solid #0ea5e944" onclick="openSched('+it.id+',\''+esc(it.company_name)+'\',\''+it.action_type+'\',\'\')">📅 تاریخ</button>';
            html += '<button class="wp-btn" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5" onclick="removePlan('+it.id+')">✕</button>';
            html += '</div></div>';
        });
        document.getElementById('unschedList').innerHTML = html;
    } else {
        document.getElementById('unschedSection').style.display = 'none';
    }
    // نوار پیشرفت
    var total = items.length, done = items.filter(function(i){return i.done==1||i.done==='1';}).length;
    if (total > 0) {
        document.getElementById('progressBar').style.display = 'flex';
        document.getElementById('progressFill').style.width = Math.round(done/total*100)+'%';
        document.getElementById('progressText').textContent = done+' / '+total;
    } else {
        document.getElementById('progressBar').style.display = 'none';
    }
}

function renderCard(it) {
    var isDone = it.done==1||it.done==='1';
    var typeIcon = it.action_type==='visit'?'🤝':'📞';
    var html = '<div class="wp-card '+it.action_type+(isDone?' done':'')+'">';
    html += '<div class="wp-card-name">'+typeIcon+' '+esc(it.company_name)+'</div>';
    html += '<div class="wp-card-meta">'+esc(it.first_name+' '+it.last_name);
    if (isDone) html += ' · ✅ '+esc(it.done_date||'');
    html += '</div>';
    html += '<div class="wp-card-actions">';
    if (!isDone) {
        html += '<button class="wp-btn" style="background:#f0fdf4;color:#16a34a;border:1px solid #86efac" onclick="openDone('+it.id+',\''+esc(it.company_name)+'\',\''+it.action_type+'\')">✓ انجام شد</button>';
    }
    html += '<button class="wp-btn" style="background:#f0f9ff;color:#0369a1;border:1px solid #0ea5e944" onclick="openSched('+it.id+',\''+esc(it.company_name)+'\',\''+it.action_type+'\',\''+(it.scheduled_date||'')+'\')">📅</button>';
    html += '<button class="wp-btn" style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5" onclick="removePlan('+it.id+')">✕</button>';
    html += '</div></div>';
    return html;
}

function esc(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/'/g,'&#39;');}

// ── جستجوی مشتری ──
function searchCustomers() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(function(){
        var q = document.getElementById('customerSearch').value.trim();
        if (!q) { document.getElementById('customerResults').style.display='none'; return; }
        post({action:'search_customers', q:q}).then(function(d){
            if (!d.data || !d.data.length) {
                document.getElementById('customerResults').innerHTML='<div style="padding:10px;font-size:12px;color:#94a3b8;text-align:center">نتیجه‌ای یافت نشد</div>';
            } else {
                document.getElementById('customerResults').innerHTML = d.data.map(function(c){
                    return '<div onclick="selectCustomer('+c.id+',\''+esc(c.company_name)+'\')" style="padding:10px 12px;border-bottom:1px solid #f1f5f9;cursor:pointer;font-size:12px;transition:background .1s" onmouseover="this.style.background=\'#f0f9ff\'" onmouseout="this.style.background=\'\'">'
                        +'<span style="font-weight:600;color:#1e293b">'+esc(c.company_name)+'</span>'
                        +' <span style="color:#94a3b8;font-size:10px">'+esc(c.state||'')+'</span>'
                        +'</div>';
                }).join('');
            }
            document.getElementById('customerResults').style.display = '';
        });
    }, 300);
}

function selectCustomer(id, name) {
    _selectedCustomerId = id;
    _selectedCustomerName = name;
    document.getElementById('customerSearch').value = name;
    document.getElementById('customerResults').style.display = 'none';
    document.getElementById('selectedCustomer').style.display = '';
    document.getElementById('selectedCustomer').textContent = '✅ انتخاب شد: ' + name;
}

function openAddModal() {
    _selectedCustomerId = 0; _selectedCustomerName = '';
    document.getElementById('customerSearch').value = '';
    document.getElementById('customerResults').style.display = 'none';
    document.getElementById('selectedCustomer').style.display = 'none';
    document.getElementById('addModal').style.display = 'flex';
    setTimeout(function(){ document.getElementById('customerSearch').focus(); }, 80);
}
function closeAddModal() { document.getElementById('addModal').style.display = 'none'; }

function addToPlan() {
    if (!_selectedCustomerId) { alert('لطفاً ابتدا یک مرکز انتخاب کنید'); return; }
    var data = {
        action:'add_to_plan',
        customer_id: _selectedCustomerId,
        week_start: document.getElementById('weekSel').value,
        action_type: document.getElementById('addActionType').value
    };
    var tu = document.getElementById('addTargetUser');
    if (tu) data.target_user_id = tu.value;
    post(data).then(function(d){
        if (d.ok) { closeAddModal(); loadWeek(); }
        else alert(d.msg);
    });
}

// ── تنظیم تاریخ ──
function openSched(planId, name, type, curDate) {
    _currentPlanId = planId;
    document.getElementById('schedName').textContent = name;
    document.getElementById('schedType').value = type;
    document.getElementById('schedDate').value = curDate ? gDateToJStr(curDate) : '';
    document.getElementById('schedModal').style.display = 'flex';
}
function saveSchedule() {
    var jDate = document.getElementById('schedDate').value.trim();
    var gDate = jDate ? jDateToGDate(jDate) : '';
    post({action:'set_schedule', plan_id:_currentPlanId, scheduled_date:gDate, action_type:document.getElementById('schedType').value})
        .then(function(d){ if(d.ok){document.getElementById('schedModal').style.display='none';loadWeek();}else alert(d.msg); });
}

// ── انجام شد ──
function openDone(planId, name, type) {
    _currentPlanId = planId; _currentActionType = type;
    document.getElementById('doneName').textContent = name;
    document.getElementById('doneTypeInfo').textContent = type==='visit'
        ? '🤝 یک ویزیت حضوری در ماموریت‌ها ثبت خواهد شد'
        : '📞 یک تماس تلفنی در ماژول تماس‌ها ثبت خواهد شد';
    document.getElementById('doneNotes').value = '';
    document.getElementById('doneModal').style.display = 'flex';
}
function confirmDone() {
    post({action:'mark_done', plan_id:_currentPlanId, notes:document.getElementById('doneNotes').value})
        .then(function(d){
            if (d.ok) {
                document.getElementById('doneModal').style.display = 'none';
                loadWeek();
                var msg = d.type==='visit' ? '✅ ویزیت در ماموریت‌ها ثبت شد' : '✅ تماس در ماژول CRM ثبت شد';
                showToastWP(msg);
            } else alert(d.msg);
        });
}

function removePlan(planId) {
    if (!confirm('این آیتم از برنامه هفته حذف شود؟')) return;
    post({action:'remove', plan_id:planId}).then(function(d){ if(d.ok) loadWeek(); });
}

function showToastWP(msg) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;z-index:99999;font-family:inherit;direction:rtl';
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); }, 3000);
}

document.addEventListener('DOMContentLoaded', loadWeek);
</script>
<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
