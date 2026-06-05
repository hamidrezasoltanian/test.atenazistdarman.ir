<?php
/*
 * ماژول CAPA — اقدام اصلاحی / پیشگیرانه — ISO 13485 بند ۸.۵.۲ و ۸.۵.۳
 * چرخه کامل: باز → بررسی → برنامه اقدام → اجرا → تأیید اثربخشی → بسته
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }

$userId   = (int)$_SESSION['user_id'];
$userRole = strtolower($_SESSION['role'] ?? '');
$isAjax   = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

// ── ایجاد جداول ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS capa_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        capa_number VARCHAR(30) NOT NULL UNIQUE,
        type ENUM('corrective','preventive') NOT NULL DEFAULT 'corrective',
        source_type ENUM('complaint','nonconformity','audit','management_review','trend','other') DEFAULT 'complaint',
        source_id INT UNSIGNED DEFAULT NULL,
        title VARCHAR(200) NOT NULL,
        problem_statement TEXT NOT NULL,
        severity ENUM('critical','major','minor') NOT NULL DEFAULT 'minor',
        root_cause_method ENUM('5why','fishbone','fault_tree','brainstorming','other') DEFAULT '5why',
        root_cause TEXT DEFAULT NULL,
        action_plan TEXT DEFAULT NULL,
        assigned_to INT UNSIGNED DEFAULT NULL,
        due_date VARCHAR(12) DEFAULT NULL,
        implementation_notes TEXT DEFAULT NULL,
        effectiveness_criteria TEXT DEFAULT NULL,
        effectiveness_check_date VARCHAR(12) DEFAULT NULL,
        effectiveness_result ENUM('effective','not_effective','pending') DEFAULT 'pending',
        status ENUM('open','investigation','action_plan','implementation','verification','closed','cancelled') DEFAULT 'open',
        closed_by INT UNSIGNED DEFAULT NULL,
        closed_at TIMESTAMP NULL DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS capa_actions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        capa_id INT UNSIGNED NOT NULL,
        description TEXT NOT NULL,
        assigned_to INT UNSIGNED DEFAULT NULL,
        due_date VARCHAR(12) DEFAULT NULL,
        completed_at VARCHAR(12) DEFAULT NULL,
        status ENUM('pending','in_progress','done','cancelled') DEFAULT 'pending',
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS capa_status_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        capa_id INT UNSIGNED NOT NULL,
        from_status VARCHAR(30) DEFAULT NULL,
        to_status VARCHAR(30) NOT NULL,
        actor_id INT UNSIGNED DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $ignored) {}

$typeLabels     = ['corrective'=>'اصلاحی (CA)','preventive'=>'پیشگیرانه (PA)'];
$sourceLabels   = ['complaint'=>'شکایت','nonconformity'=>'عدم انطباق','audit'=>'ممیزی',
    'management_review'=>'بازنگری مدیریت','trend'=>'آنالیز روند','other'=>'سایر'];
$methodLabels   = ['5why'=>'۵ چرا','fishbone'=>'نمودار استخوان ماهی','fault_tree'=>'درخت خطا',
    'brainstorming'=>'طوفان فکری','other'=>'سایر'];
$statusLabels   = ['open'=>'باز','investigation'=>'در حال بررسی','action_plan'=>'برنامه اقدام',
    'implementation'=>'در حال اجرا','verification'=>'تأیید اثربخشی','closed'=>'بسته','cancelled'=>'لغو شده'];
$severityLabels = ['critical'=>'بحرانی','major'=>'اصلی','minor'=>'جزئی'];
$statusColors   = ['open'=>'rose','investigation'=>'amber','action_plan'=>'blue',
    'implementation'=>'purple','verification'=>'cyan','closed'=>'green','cancelled'=>'gray'];
$efLabels       = ['effective'=>'✅ مؤثر','not_effective'=>'❌ غیرمؤثر','pending'=>'⏳ در انتظار'];

// مراحل بعدی وضعیت
$nextStatus = ['open'=>'investigation','investigation'=>'action_plan','action_plan'=>'implementation',
    'implementation'=>'verification','verification'=>'closed'];
$nextBtnLabel = ['open'=>'شروع بررسی','investigation'=>'تأیید برنامه اقدام',
    'action_plan'=>'شروع اجرا','implementation'=>'ارسال برای تأیید اثربخشی','verification'=>'بستن CAPA'];

// ── AJAX handlers ──
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_capa') {
        csrf_verify();
        $id               = (int)($_POST['id'] ?? 0);
        $type             = in_array($_POST['type']??'',['corrective','preventive'],true)?$_POST['type']:'corrective';
        $sourceType       = $_POST['source_type'] ?? 'other';
        $title            = trim($_POST['title'] ?? '');
        $problem          = trim($_POST['problem_statement'] ?? '');
        $severity         = in_array($_POST['severity']??'',['critical','major','minor'],true)?$_POST['severity']:'minor';
        $rootMethod       = $_POST['root_cause_method'] ?? '5why';
        $rootCause        = trim($_POST['root_cause'] ?? '');
        $actionPlan       = trim($_POST['action_plan'] ?? '');
        $assignedTo       = (int)($_POST['assigned_to'] ?? 0) ?: null;
        $dueDate          = trim($_POST['due_date'] ?? '') ?: null;
        $implNotes        = trim($_POST['implementation_notes'] ?? '');
        $effCriteria      = trim($_POST['effectiveness_criteria'] ?? '');
        $effCheckDate     = trim($_POST['effectiveness_check_date'] ?? '') ?: null;
        $effResult        = in_array($_POST['effectiveness_result']??'',['effective','not_effective','pending'],true)?$_POST['effectiveness_result']:'pending';

        if (!$title || !$problem) { echo json_encode(['ok'=>false,'msg'=>'عنوان و توضیح مشکل الزامی است']); exit; }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE capa_requests SET type=?,source_type=?,title=?,problem_statement=?,severity=?,
                    root_cause_method=?,root_cause=?,action_plan=?,assigned_to=?,due_date=?,
                    implementation_notes=?,effectiveness_criteria=?,effectiveness_check_date=?,
                    effectiveness_result=?,updated_at=NOW() WHERE id=? AND is_deleted=0")
                    ->execute([$type,$sourceType,$title,$problem,$severity,$rootMethod,$rootCause,
                        $actionPlan,$assignedTo,$dueDate,$implNotes,$effCriteria,$effCheckDate,$effResult,$id]);
                echo json_encode(['ok'=>true,'msg'=>'CAPA بروزرسانی شد']);
            } else {
                $prefix = $type === 'preventive' ? 'PA' : 'CA';
                $yymm   = jdate('Ym');
                $count  = (int)$pdo->query("SELECT COUNT(*)+1 FROM capa_requests WHERE capa_number LIKE '$prefix-$yymm-%'")->fetchColumn();
                $cnum   = "$prefix-$yymm-" . str_pad($count, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO capa_requests (capa_number,type,source_type,title,problem_statement,severity,
                    root_cause_method,root_cause,action_plan,assigned_to,due_date,implementation_notes,
                    effectiveness_criteria,effectiveness_check_date,effectiveness_result,status,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$cnum,$type,$sourceType,$title,$problem,$severity,$rootMethod,$rootCause,
                        $actionPlan,$assignedTo,$dueDate,$implNotes,$effCriteria,$effCheckDate,$effResult,'open',$userId]);
                $newId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO capa_status_logs (capa_id,from_status,to_status,actor_id,notes) VALUES (?,NULL,?,?,?)")
                    ->execute([$newId,'open',$userId,'ایجاد CAPA']);
                logActivity($pdo,$userId,'capa_created',"CAPA $cnum ایجاد شد");
                echo json_encode(['ok'=>true,'msg'=>"CAPA $cnum ثبت شد",'id'=>$newId,'number'=>$cnum]);
            }
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false,'msg'=>'خطا: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'advance_status') {
        csrf_verify();
        $id    = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $st    = $pdo->prepare("SELECT status FROM capa_requests WHERE id=? AND is_deleted=0");
        $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'CAPA یافت نشد']); exit; }
        $cur  = $row['status'];
        $next = $nextStatus[$cur] ?? null;
        if (!$next) { echo json_encode(['ok'=>false,'msg'=>'وضعیت قابل پیشروی نیست']); exit; }
        $extra = [];
        if ($next === 'closed') { $extra = ['closed_by=?'=>$userId,'closed_at=NOW()'=>null]; }
        $pdo->prepare("UPDATE capa_requests SET status=?,updated_at=NOW() WHERE id=?")->execute([$next,$id]);
        if ($next === 'closed') {
            $pdo->prepare("UPDATE capa_requests SET closed_by=?,closed_at=NOW() WHERE id=?")->execute([$userId,$id]);
        }
        $pdo->prepare("INSERT INTO capa_status_logs (capa_id,from_status,to_status,actor_id,notes) VALUES (?,?,?,?,?)")
            ->execute([$id,$cur,$next,$userId,$notes]);
        logActivity($pdo,$userId,'capa_status_changed',"CAPA #$id: $cur → $next");
        echo json_encode(['ok'=>true,'msg'=>'وضعیت به «'.$statusLabels[$next].'» تغییر یافت','new_status'=>$next]);
        exit;
    }

    if ($action === 'add_action') {
        csrf_verify();
        $capaId  = (int)($_POST['capa_id'] ?? 0);
        $desc    = trim($_POST['description'] ?? '');
        $asigned = (int)($_POST['assigned_to'] ?? 0) ?: null;
        $due     = trim($_POST['due_date'] ?? '') ?: null;
        if (!$capaId || !$desc) { echo json_encode(['ok'=>false,'msg'=>'اطلاعات ناقص']); exit; }
        $pdo->prepare("INSERT INTO capa_actions (capa_id,description,assigned_to,due_date) VALUES (?,?,?,?)")
            ->execute([$capaId,$desc,$asigned,$due]);
        echo json_encode(['ok'=>true,'msg'=>'اقدام اضافه شد']);
        exit;
    }

    if ($action === 'update_action_status') {
        csrf_verify();
        $actionId = (int)($_POST['action_id'] ?? 0);
        $status   = $_POST['status'] ?? 'pending';
        $pdo->prepare("UPDATE capa_actions SET status=?, completed_at=? WHERE id=?")
            ->execute([$status, $status==='done'?jdate('Y/m/d'):null, $actionId]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'get_capa') {
        $id = (int)($_POST['id'] ?? 0);
        $c  = $pdo->prepare("SELECT r.*, u.full_name AS assignee_name, cb.full_name AS closer_name
            FROM capa_requests r
            LEFT JOIN users u ON u.id=r.assigned_to
            LEFT JOIN users cb ON cb.id=r.closed_by
            WHERE r.id=? AND r.is_deleted=0");
        $c->execute([$id]);
        $capa = $c->fetch(PDO::FETCH_ASSOC);
        if (!$capa) { echo json_encode(['ok'=>false]); exit; }
        $acts = $pdo->prepare("SELECT a.*, u.full_name AS assignee_name FROM capa_actions a LEFT JOIN users u ON u.id=a.assigned_to WHERE a.capa_id=? ORDER BY a.id");
        $acts->execute([$id]);
        $capa['actions'] = $acts->fetchAll(PDO::FETCH_ASSOC);
        $logs = $pdo->prepare("SELECT l.*, u.full_name AS actor_name FROM capa_status_logs l LEFT JOIN users u ON u.id=l.actor_id WHERE l.capa_id=? ORDER BY l.id");
        $logs->execute([$id]);
        $capa['logs'] = $logs->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'data'=>$capa]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'اکشن نامعتبر']);
    exit;
}

// ── لیست CAPA با تب ──
$fTab     = $_GET['tab'] ?? 'open';
$fType    = $_GET['type'] ?? '';
$tabWhere = ['closed','cancelled'];
$isOpenTab = !in_array($fTab, $tabWhere, true);
$whereBase = "r.is_deleted=0 AND " . ($fTab==='all' ? '1=1' : "r.status='" . $pdo->quote($fTab) . "'");
if ($fType) $whereBase .= " AND r.type='" . $pdo->quote($fType) . "'";

$capas = $pdo->query(
    "SELECT r.*, u.full_name AS assignee_name
     FROM capa_requests r
     LEFT JOIN users u ON u.id=r.assigned_to
     WHERE $whereBase ORDER BY r.created_at DESC LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

// آمار
$statRow = $pdo->query("SELECT
    COUNT(*) total,
    SUM(status NOT IN ('closed','cancelled')) open_count,
    SUM(severity='critical' AND status NOT IN ('closed','cancelled')) critical_open,
    SUM(type='corrective') ca_count,
    SUM(type='preventive') pa_count
    FROM capa_requests WHERE is_deleted=0")->fetch(PDO::FETCH_ASSOC);

$users = $pdo->query("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'CAPA — ISO 13485 §8.5';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<div class="main-content">
<div style="max-width:1200px;margin:0 auto;padding:24px 16px;">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <div>
      <span style="font-size:1.3rem;font-weight:700;">🔄 CAPA — اقدام اصلاحی / پیشگیرانه</span>
      <span class="fin-badge blue" style="margin-right:8px;font-size:.75rem;">ISO 13485 §8.5.2/8.5.3</span>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="qms_complaints.php" class="fin-btn fin-btn-outline" style="font-size:.85rem;">📋 شکایات</a>
      <button onclick="openDrawer()" class="fin-btn fin-btn-primary">➕ CAPA جدید</button>
    </div>
  </div>

  <!-- کارت‌های آمار -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:20px;">
    <div class="fin-stat-card blue"><div class="stat-value"><?= $statRow['total'] ?></div><div class="stat-label">کل CAPA</div></div>
    <div class="fin-stat-card amber"><div class="stat-value"><?= $statRow['open_count'] ?></div><div class="stat-label">باز/جاری</div></div>
    <div class="fin-stat-card rose"><div class="stat-value"><?= $statRow['critical_open'] ?></div><div class="stat-label">بحرانی باز</div></div>
    <div class="fin-stat-card green"><div class="stat-value"><?= $statRow['ca_count'] ?></div><div class="stat-label">اصلاحی (CA)</div></div>
    <div class="fin-stat-card purple"><div class="stat-value"><?= $statRow['pa_count'] ?></div><div class="stat-label">پیشگیرانه (PA)</div></div>
  </div>

  <!-- تب‌ها -->
  <div class="fin-tabs" style="margin-bottom:16px;">
    <?php foreach (['open'=>'باز','investigation'=>'بررسی','action_plan'=>'برنامه اقدام',
        'implementation'=>'اجرا','verification'=>'تأیید اثربخشی','closed'=>'بسته','all'=>'همه'] as $tab=>$lbl): ?>
    <a href="?tab=<?= $tab ?><?= $fType?"&type=$fType":'' ?>"
       class="fin-tab<?= $fTab===$tab?' active':'' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <!-- جدول -->
  <div class="fin-panel" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
    <table class="fin-table" style="width:100%;min-width:750px;">
      <thead>
        <tr>
          <th>شماره</th>
          <th>نوع</th>
          <th>عنوان</th>
          <th>درجه</th>
          <th>وضعیت</th>
          <th>مسئول</th>
          <th>سررسید</th>
          <th>اقدام</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($capas)): ?>
        <tr><td colspan="8" style="text-align:center;padding:32px;color:#9ca3af;">هیچ موردی یافت نشد</td></tr>
        <?php else: ?>
        <?php foreach ($capas as $c): ?>
        <?php
          $isOverdue = $c['due_date'] && $c['due_date'] < jdate('Y/m/d') && !in_array($c['status'],['closed','cancelled'],true);
        ?>
        <tr style="<?= $isOverdue?'background:#fff5f5':'' ?>">
          <td><span style="font-weight:700;font-size:.85rem;"><?= htmlspecialchars($c['capa_number']) ?></span></td>
          <td><span class="fin-badge <?= $c['type']==='corrective'?'blue':'purple' ?>"><?= $typeLabels[$c['type']] ?></span></td>
          <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($c['title']) ?>"><?= htmlspecialchars($c['title']) ?></td>
          <td><span class="fin-badge <?= ['critical'=>'rose','major'=>'amber','minor'=>'blue'][$c['severity']] ?>"><?= $severityLabels[$c['severity']] ?></span></td>
          <td><span class="fin-badge <?= $statusColors[$c['status']] ?>"><?= $statusLabels[$c['status']] ?></span></td>
          <td style="font-size:.85rem;"><?= htmlspecialchars($c['assignee_name'] ?? '—') ?></td>
          <td style="font-size:.82rem;<?= $isOverdue?'color:#dc2626;font-weight:600':'' ?>"><?= $c['due_date'] ?: '—' ?><?= $isOverdue?' ⚠️':'' ?></td>
          <td style="white-space:nowrap;">
            <button onclick="viewCapa(<?= $c['id'] ?>)" class="fin-btn fin-btn-outline" style="padding:4px 10px;font-size:.78rem;">🔍 جزئیات</button>
            <a href="qms_pdf.php?type=capa&id=<?= $c['id'] ?>" target="_blank" class="fin-btn" style="padding:4px 10px;font-size:.78rem;background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff;">PDF</a>
            <?php if (isset($nextStatus[$c['status']])): ?>
            <button onclick="advanceStatus(<?= $c['id'] ?>, '<?= $c['capa_number'] ?>', '<?= $statusLabels[$c['status']] ?>')" class="fin-btn fin-btn-primary" style="padding:4px 10px;font-size:.78rem;">← <?= $nextBtnLabel[$c['status']] ?? 'پیشروی' ?></button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

</div>
</div>

<!-- Drawer CAPA جدید -->
<div class="fin-drawer" id="capaDrawer" style="max-width:620px;">
  <div class="fin-drawer-header">
    <span>➕ CAPA جدید</span>
    <button onclick="closeDrawer()" class="fin-drawer-close">×</button>
  </div>
  <div class="fin-drawer-body">
    <form id="capaForm" onsubmit="saveCapa(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_capa">
      <input type="hidden" name="id" value="0">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
          <label class="fin-label">نوع <span style="color:red">*</span></label>
          <select name="type" class="fin-input" style="width:100%;">
            <option value="corrective">اصلاحی (Corrective)</option>
            <option value="preventive">پیشگیرانه (Preventive)</option>
          </select>
        </div>
        <div>
          <label class="fin-label">منبع</label>
          <select name="source_type" class="fin-input" style="width:100%;">
            <?php foreach ($sourceLabels as $v=>$l): ?>
            <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="grid-column:1/-1;">
          <label class="fin-label">عنوان <span style="color:red">*</span></label>
          <input type="text" name="title" class="fin-input" style="width:100%;" placeholder="خلاصه مشکل یا اقدام..." required>
        </div>
        <div>
          <label class="fin-label">درجه اهمیت</label>
          <select name="severity" class="fin-input" style="width:100%;">
            <?php foreach ($severityLabels as $v=>$l): ?>
            <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">روش تحلیل ریشه</label>
          <select name="root_cause_method" class="fin-input" style="width:100%;">
            <?php foreach ($methodLabels as $v=>$l): ?>
            <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="grid-column:1/-1;">
          <label class="fin-label">بیان مسئله <span style="color:red">*</span></label>
          <textarea name="problem_statement" class="fin-input" rows="3" style="width:100%;resize:vertical;" placeholder="چه اتفاقی افتاد؟ چه کسی تأثیر دید؟ کِی؟" required></textarea>
        </div>
        <div style="grid-column:1/-1;">
          <label class="fin-label">تحلیل ریشه</label>
          <textarea name="root_cause" class="fin-input" rows="2" style="width:100%;resize:vertical;" placeholder="چرا؟ چرا؟ چرا؟ چرا؟ چرا؟"></textarea>
        </div>
        <div style="grid-column:1/-1;">
          <label class="fin-label">برنامه اقدام</label>
          <textarea name="action_plan" class="fin-input" rows="2" style="width:100%;resize:vertical;" placeholder="چه اقداماتی انجام می‌شود؟"></textarea>
        </div>
        <div>
          <label class="fin-label">مسئول اجرا</label>
          <select name="assigned_to" class="fin-input" style="width:100%;">
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">سررسید اجرا (شمسی)</label>
          <input type="text" name="due_date" class="fin-input kama-date" style="width:100%;" placeholder="۱۴۰۴/۰۵/۱۵">
        </div>
        <div style="grid-column:1/-1;">
          <label class="fin-label">معیار تأیید اثربخشی</label>
          <textarea name="effectiveness_criteria" class="fin-input" rows="2" style="width:100%;resize:vertical;" placeholder="چگونه می‌دانیم اقدام مؤثر بوده؟"></textarea>
        </div>
      </div>
      <button type="submit" class="fin-btn fin-btn-primary" style="width:100%;padding:12px;margin-top:16px;">💾 ثبت CAPA</button>
    </form>
  </div>
</div>
<div class="fin-drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<!-- مودال جزئیات CAPA -->
<div id="capaDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:998;overflow:auto;">
  <div style="background:#fff;border-radius:16px;margin:30px auto;width:90%;max-width:780px;padding:28px;position:relative;">
    <button onclick="document.getElementById('capaDetailModal').style.display='none'" style="position:absolute;top:16px;left:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#9ca3af;">×</button>
    <div id="capaDetailContent"></div>
  </div>
</div>

<!-- مودال پیشروی وضعیت -->
<div id="advanceModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;justify-content:center;align-items:center;">
  <div style="background:#fff;border-radius:16px;padding:28px;width:90%;max-width:420px;">
    <h3 id="advanceTitle" style="margin:0 0 16px;font-size:1.05rem;"></h3>
    <textarea id="advanceNotes" rows="3" placeholder="یادداشت (اختیاری)..." style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-family:inherit;font-size:.9rem;resize:vertical;box-sizing:border-box;"></textarea>
    <div style="display:flex;gap:10px;margin-top:14px;">
      <button id="advanceConfirmBtn" style="flex:1;padding:10px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:inherit;font-weight:600;">تأیید پیشروی</button>
      <button onclick="document.getElementById('advanceModal').style.display='none'" style="flex:1;padding:10px;border:1px solid #d1d5db;border-radius:8px;cursor:pointer;font-family:inherit;background:#f9fafb;">انصراف</button>
    </div>
  </div>
</div>

<script>
const statusLabels = <?= json_encode($statusLabels) ?>;
const typeLabels   = <?= json_encode($typeLabels) ?>;
const sevLabels    = <?= json_encode($severityLabels) ?>;
const srcLabels    = <?= json_encode($sourceLabels) ?>;
const efLabels     = <?= json_encode($efLabels) ?>;
const csrfToken    = '<?= csrf_token() ?>';

function openDrawer(){ document.getElementById('capaDrawer').classList.add('open'); document.getElementById('drawerOverlay').classList.add('open'); }
function closeDrawer(){ document.getElementById('capaDrawer').classList.remove('open'); document.getElementById('drawerOverlay').classList.remove('open'); }

function saveCapa(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const btn = e.target.querySelector('button[type=submit]');
    btn.disabled = true; btn.textContent = '⏳ ثبت...';
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        btn.disabled=false; btn.textContent='💾 ثبت CAPA';
        if(d.ok){ closeDrawer(); location.reload(); }
        else alert('❌ '+d.msg);
    }).catch(()=>{ btn.disabled=false; alert('❌ خطای شبکه'); });
}

let _advId = null;
function advanceStatus(id, num, curLabel) {
    _advId = id;
    document.getElementById('advanceTitle').textContent = `پیشروی CAPA ${num} از «${curLabel}»`;
    document.getElementById('advanceNotes').value = '';
    document.getElementById('advanceModal').style.display = 'flex';
}
document.getElementById('advanceConfirmBtn').addEventListener('click', function() {
    if (!_advId) return;
    const fd = new FormData();
    fd.append('action','advance_status'); fd.append('id',_advId);
    fd.append('notes',document.getElementById('advanceNotes').value);
    fd.append('csrf_token',csrfToken);
    this.disabled=true; this.textContent='⏳...';
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        document.getElementById('advanceModal').style.display='none';
        this.disabled=false; this.textContent='تأیید پیشروی';
        if(d.ok) location.reload();
        else alert('❌ '+d.msg);
    });
});

function viewCapa(id) {
    const fd = new FormData(); fd.append('action','get_capa'); fd.append('id',id);
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(!d.ok||!d.data){ alert('خطا'); return; }
        const c = d.data;
        const acts = c.actions||[];
        const logs = c.logs||[];
        let actsHtml = acts.length?acts.map(a=>`
            <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px dashed #e5e7eb;">
                <span style="flex:1;font-size:.85rem;">${escH(a.description)}</span>
                <span style="font-size:.78rem;color:#6b7280;">${escH(a.assignee_name||'—')}</span>
                <span class="fin-badge ${a.status==='done'?'green':a.status==='in_progress'?'amber':'gray'}" style="font-size:.72rem;">${{'pending':'در انتظار','in_progress':'جاری','done':'انجام شد','cancelled':'لغو'}[a.status]||a.status}</span>
            </div>`).join(''):'<div style="color:#9ca3af;font-size:.85rem;">اقدامی ثبت نشده</div>';
        let logsHtml = logs.map(l=>`
            <div style="display:flex;gap:8px;padding:5px 0;border-bottom:1px dashed #f3f4f6;font-size:.8rem;">
                <span style="color:#9ca3af;flex-shrink:0;">${l.created_at?.substring(0,16)||''}</span>
                <span style="color:#374151;">${escH(l.actor_name||'سیستم')}: ${escH(l.from_status||'—')} ← ${escH(l.to_status)} ${l.notes?'('+escH(l.notes)+')':''}</span>
            </div>`).join('');
        document.getElementById('capaDetailContent').innerHTML = `
            <h3 style="margin:0 0 4px;">${escH(c.capa_number)} — ${escH(c.title)}</h3>
            <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
                <span class="fin-badge ${c.type==='corrective'?'blue':'purple'}">${typeLabels[c.type]||c.type}</span>
                <span class="fin-badge ${{'critical':'rose','major':'amber','minor':'blue'}[c.severity]||'gray'}">${sevLabels[c.severity]||''}</span>
                <span class="fin-badge green">${statusLabels[c.status]||''}</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.85rem;margin-bottom:16px;">
                <div><b>منبع:</b> ${srcLabels[c.source_type]||c.source_type}</div>
                <div><b>مسئول:</b> ${escH(c.assignee_name||'—')}</div>
                <div><b>سررسید:</b> ${c.due_date||'—'}</div>
                <div><b>اثربخشی:</b> ${efLabels[c.effectiveness_result]||c.effectiveness_result}</div>
            </div>
            <div style="margin-bottom:12px;"><b>بیان مسئله:</b><p style="color:#374151;margin:4px 0;line-height:1.7;">${escH(c.problem_statement)}</p></div>
            ${c.root_cause?`<div style="margin-bottom:12px;"><b>تحلیل ریشه (${escH(c.root_cause_method)}):</b><p style="color:#374151;margin:4px 0;line-height:1.7;">${escH(c.root_cause)}</p></div>`:''}
            ${c.action_plan?`<div style="margin-bottom:12px;"><b>برنامه اقدام:</b><p style="color:#374151;margin:4px 0;line-height:1.7;">${escH(c.action_plan)}</p></div>`:''}
            ${c.effectiveness_criteria?`<div style="margin-bottom:12px;background:#f0fdf4;padding:10px;border-radius:8px;"><b>معیار تأیید اثربخشی:</b><p style="margin:4px 0;">${escH(c.effectiveness_criteria)}</p></div>`:''}
            <div style="margin-bottom:12px;"><b>اقدامات:</b><div style="margin-top:8px;">${actsHtml}</div></div>
            <div><b>تاریخچه وضعیت:</b><div style="margin-top:6px;">${logsHtml}</div></div>
        `;
        document.getElementById('capaDetailModal').style.display = 'block';
    });
}

function escH(s){ return s?String(s).replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])):''; }
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
