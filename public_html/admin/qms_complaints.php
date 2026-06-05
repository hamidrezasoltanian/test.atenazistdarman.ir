<?php
/*
 * ماژول شکایات ISO 13485 — بند ۸.۲.۱ و ۸.۲.۶
 * ثبت، پیگیری و بستن شکایات با audit trail کامل
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }

$userId   = (int)$_SESSION['user_id'];
$userRole = strtolower($_SESSION['role'] ?? '');
$isAjax   = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

// ── ایجاد جداول اگر وجود ندارد ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS qms_complaints (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        complaint_number VARCHAR(30) NOT NULL UNIQUE,
        person_id INT UNSIGNED DEFAULT NULL,
        ref_ticket_id INT UNSIGNED DEFAULT NULL,
        stuff_id INT UNSIGNED DEFAULT NULL,
        batch_number VARCHAR(50) DEFAULT NULL,
        serial_number VARCHAR(100) DEFAULT NULL,
        source ENUM('customer','internal','supplier','regulatory','returned_goods','adverse_event','other') NOT NULL DEFAULT 'customer',
        category ENUM('quality','safety','performance','labeling','packaging','delivery','other') NOT NULL DEFAULT 'quality',
        severity ENUM('critical','major','minor','observation') NOT NULL DEFAULT 'minor',
        description TEXT NOT NULL,
        initial_assessment TEXT DEFAULT NULL,
        regulatory_reportable TINYINT(1) DEFAULT 0,
        report_deadline VARCHAR(12) DEFAULT NULL,
        regulatory_reported_at VARCHAR(12) DEFAULT NULL,
        assigned_to INT UNSIGNED DEFAULT NULL,
        status ENUM('open','investigating','pending_capa','resolved','closed') DEFAULT 'open',
        resolution TEXT DEFAULT NULL,
        reported_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $ignored) {}

// برچسب‌ها
$sourceLabels = ['customer'=>'مشتری','internal'=>'داخلی','supplier'=>'تأمین‌کننده',
    'regulatory'=>'نهاد ناظر','returned_goods'=>'کالای مرجوعی','adverse_event'=>'رویداد نامطلوب','other'=>'سایر'];
$categoryLabels = ['quality'=>'کیفیت','safety'=>'ایمنی','performance'=>'عملکرد',
    'labeling'=>'برچسب‌گذاری','packaging'=>'بسته‌بندی','delivery'=>'تحویل','other'=>'سایر'];
$severityLabels = ['critical'=>'بحرانی','major'=>'اصلی','minor'=>'جزئی','observation'=>'مشاهده'];
$statusLabels   = ['open'=>'باز','investigating'=>'در حال بررسی','pending_capa'=>'انتظار CAPA',
    'resolved'=>'رفع شده','closed'=>'بسته'];
$severityColors = ['critical'=>'rose','major'=>'amber','minor'=>'blue','observation'=>'gray'];
$statusColors   = ['open'=>'rose','investigating'=>'amber','pending_capa'=>'purple','resolved'=>'blue','closed'=>'green'];

// ── AJAX handlers ──
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_complaint') {
        csrf_verify();
        $id          = (int)($_POST['id'] ?? 0);
        $personId    = (int)($_POST['person_id'] ?? 0) ?: null;
        $stuffId     = (int)($_POST['stuff_id'] ?? 0) ?: null;
        $source      = $_POST['source'] ?? 'customer';
        $category    = $_POST['category'] ?? 'quality';
        $severity    = $_POST['severity'] ?? 'minor';
        $desc        = trim($_POST['description'] ?? '');
        $assessment  = trim($_POST['initial_assessment'] ?? '');
        $batch       = trim($_POST['batch_number'] ?? '') ?: null;
        $serial      = trim($_POST['serial_number'] ?? '') ?: null;
        $regReport   = isset($_POST['regulatory_reportable']) ? 1 : 0;
        $assignedTo  = (int)($_POST['assigned_to'] ?? 0) ?: null;
        $status      = $_POST['status'] ?? 'open';

        if (!$desc) { echo json_encode(['ok'=>false,'msg'=>'توضیح شکایت الزامی است']); exit; }
        if (!in_array($source, array_keys($sourceLabels), true)) $source = 'other';
        if (!in_array($category, array_keys($categoryLabels), true)) $category = 'other';
        if (!in_array($severity, array_keys($severityLabels), true)) $severity = 'minor';
        if (!in_array($status, array_keys($statusLabels), true)) $status = 'open';

        // محاسبه مهلت گزارش (۳۰ روز شمسی برای رویداد نامطلوب)
        $deadline = null;
        if ($regReport) {
            $deadline = jdate('Y/m/d', strtotime('+30 days'));
        }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE qms_complaints SET person_id=?,stuff_id=?,source=?,category=?,
                    severity=?,description=?,initial_assessment=?,batch_number=?,serial_number=?,
                    regulatory_reportable=?,report_deadline=?,assigned_to=?,status=?,updated_at=NOW()
                    WHERE id=? AND is_deleted=0")
                    ->execute([$personId,$stuffId,$source,$category,$severity,$desc,$assessment,
                        $batch,$serial,$regReport,$deadline,$assignedTo,$status,$id]);
                logActivity($pdo,$userId,'complaint_updated',"شکایت #$id ویرایش شد");
                echo json_encode(['ok'=>true,'msg'=>'شکایت بروزرسانی شد']);
            } else {
                // شماره‌گذاری خودکار
                $yymm = jdate('Ym');
                $count = (int)$pdo->query("SELECT COUNT(*)+1 FROM qms_complaints WHERE complaint_number LIKE 'CMP-$yymm-%'")->fetchColumn();
                $num   = 'CMP-' . $yymm . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO qms_complaints (complaint_number,person_id,stuff_id,source,category,severity,
                    description,initial_assessment,batch_number,serial_number,regulatory_reportable,report_deadline,
                    assigned_to,status,reported_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$num,$personId,$stuffId,$source,$category,$severity,$desc,$assessment,
                        $batch,$serial,$regReport,$deadline,$assignedTo,$status,$userId]);
                $newId = (int)$pdo->lastInsertId();
                logActivity($pdo,$userId,'complaint_created',"شکایت $num ثبت شد");
                echo json_encode(['ok'=>true,'msg'=>"شکایت $num ثبت شد",'id'=>$newId,'number'=>$num]);
            }
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false,'msg'=>'خطای دیتابیس: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_complaint') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT c.*, p.company_name, p.name AS person_name,
            s.stuff_name, s.stuff_code,
            u.full_name AS assignee_name
            FROM qms_complaints c
            LEFT JOIN fin_persons p ON p.id=c.person_id
            LEFT JOIN stuffs s ON s.id=c.stuff_id
            LEFT JOIN users u ON u.id=c.assigned_to
            WHERE c.id=? AND c.is_deleted=0");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>!!$row,'data'=>$row]);
        exit;
    }

    if ($action === 'create_capa') {
        csrf_verify();
        $complaintId = (int)($_POST['complaint_id'] ?? 0);
        if (!$complaintId) { echo json_encode(['ok'=>false,'msg'=>'شناسه شکایت نامعتبر']); exit; }
        $comp = $pdo->prepare("SELECT * FROM qms_complaints WHERE id=? AND is_deleted=0");
        $comp->execute([$complaintId]);
        $complaint = $comp->fetch(PDO::FETCH_ASSOC);
        if (!$complaint) { echo json_encode(['ok'=>false,'msg'=>'شکایت یافت نشد']); exit; }

        // ایجاد CAPA از روی شکایت
        $yymm  = jdate('Ym');
        $count = (int)$pdo->query("SELECT COUNT(*)+1 FROM capa_requests WHERE capa_number LIKE 'CA-$yymm-%'")->fetchColumn();
        $cnum  = 'CA-' . $yymm . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO capa_requests
            (capa_number,type,source_type,source_id,title,problem_statement,severity,status,created_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$cnum,'corrective','complaint',$complaintId,
                'CAPA برای شکایت '.$complaint['complaint_number'],
                $complaint['description'], $complaint['severity'], 'open', $userId]);
        $capaId = (int)$pdo->lastInsertId();

        // آپدیت وضعیت شکایت
        $pdo->prepare("UPDATE qms_complaints SET status='pending_capa', updated_at=NOW() WHERE id=?")
            ->execute([$complaintId]);

        logActivity($pdo,$userId,'capa_created',"CAPA $cnum از شکایت {$complaint['complaint_number']} ایجاد شد");
        echo json_encode(['ok'=>true,'msg'=>"CAPA $cnum ایجاد شد",'capa_id'=>$capaId,'capa_number'=>$cnum]);
        exit;
    }

    if ($action === 'close_complaint') {
        csrf_verify();
        $id         = (int)($_POST['id'] ?? 0);
        $resolution = trim($_POST['resolution'] ?? '');
        if (!$resolution) { echo json_encode(['ok'=>false,'msg'=>'متن رفع شکایت الزامی است']); exit; }
        $pdo->prepare("UPDATE qms_complaints SET status='closed', resolution=?, updated_at=NOW() WHERE id=? AND is_deleted=0")
            ->execute([$resolution, $id]);
        echo json_encode(['ok'=>true,'msg'=>'شکایت بسته شد']);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'اکشن نامعتبر']);
    exit;
}

// ── فیلترها و لیست ──
$fStatus   = $_GET['status'] ?? '';
$fSeverity = $_GET['severity'] ?? '';
$fSource   = $_GET['source'] ?? '';
$q         = trim($_GET['q'] ?? '');

$where = ['c.is_deleted=0'];
$params = [];
if ($fStatus)   { $where[] = 'c.status=?';   $params[] = $fStatus; }
if ($fSeverity) { $where[] = 'c.severity=?'; $params[] = $fSeverity; }
if ($fSource)   { $where[] = 'c.source=?';   $params[] = $fSource; }
if ($q) {
    $where[] = '(c.complaint_number LIKE ? OR c.description LIKE ? OR p.company_name LIKE ?)';
    $params  = array_merge($params, ["%$q%","%$q%","%$q%"]);
}
$whereSql = implode(' AND ', $where);

$complaints = $pdo->prepare(
    "SELECT c.*, COALESCE(p.company_name, p.name,'—') AS person_name,
            s.stuff_name, u.full_name AS assignee_name
     FROM qms_complaints c
     LEFT JOIN fin_persons p ON p.id=c.person_id
     LEFT JOIN stuffs s ON s.id=c.stuff_id
     LEFT JOIN users u ON u.id=c.assigned_to
     WHERE $whereSql ORDER BY c.created_at DESC LIMIT 100"
);
$complaints->execute($params);
$rows = $complaints->fetchAll(PDO::FETCH_ASSOC);

// آمار سریع
$stats = $pdo->query("SELECT
    COUNT(*) total,
    SUM(status='open') open_count,
    SUM(severity='critical') critical_count,
    SUM(regulatory_reportable=1 AND regulatory_reported_at IS NULL) unreported_count
    FROM qms_complaints WHERE is_deleted=0")->fetch(PDO::FETCH_ASSOC);

// لیست کاربران برای تخصیص
$users = $pdo->query("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// لیست مشتریان
$persons = $pdo->query("SELECT id, COALESCE(company_name,name) AS n FROM fin_persons WHERE is_deleted=0 ORDER BY n LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'مدیریت شکایات — ISO 13485';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<div class="main-content">
<div style="max-width:1200px;margin:0 auto;padding:24px 16px;">

  <!-- هدر -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <div>
      <span style="font-size:1.3rem;font-weight:700;">📋 مدیریت شکایات</span>
      <span class="fin-badge blue" style="margin-right:8px;font-size:.75rem;">ISO 13485 §8.2.1</span>
    </div>
    <button onclick="openDrawer()" class="fin-btn fin-btn-primary">➕ شکایت جدید</button>
  </div>

  <!-- کارت‌های آمار -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px;">
    <div class="fin-stat-card blue"><div class="stat-value"><?= $stats['total'] ?></div><div class="stat-label">کل شکایات</div></div>
    <div class="fin-stat-card rose"><div class="stat-value"><?= $stats['open_count'] ?></div><div class="stat-label">باز</div></div>
    <div class="fin-stat-card amber"><div class="stat-value"><?= $stats['critical_count'] ?></div><div class="stat-label">بحرانی</div></div>
    <div class="fin-stat-card purple"><div class="stat-value"><?= $stats['unreported_count'] ?></div><div class="stat-label">⚠️ گزارش‌نشده به MDMA</div></div>
  </div>

  <!-- فیلترها -->
  <form method="GET" class="fin-panel" style="margin-bottom:16px;padding:14px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <input type="text" name="q" class="fin-input" placeholder="جستجو..." value="<?= htmlspecialchars($q) ?>" style="flex:2;min-width:150px;">
      <select name="status" class="fin-input" style="flex:1;min-width:130px;">
        <option value="">همه وضعیت‌ها</option>
        <?php foreach ($statusLabels as $v => $l): ?>
        <option value="<?= $v ?>" <?= $fStatus===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <select name="severity" class="fin-input" style="flex:1;min-width:120px;">
        <option value="">همه درجات</option>
        <?php foreach ($severityLabels as $v => $l): ?>
        <option value="<?= $v ?>" <?= $fSeverity===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <select name="source" class="fin-input" style="flex:1;min-width:130px;">
        <option value="">همه منابع</option>
        <?php foreach ($sourceLabels as $v => $l): ?>
        <option value="<?= $v ?>" <?= $fSource===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="fin-btn fin-btn-primary">🔍 فیلتر</button>
      <a href="qms_complaints.php" class="fin-btn fin-btn-outline">✖</a>
    </div>
  </form>

  <!-- جدول -->
  <div class="fin-panel" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
    <table class="fin-table" style="width:100%;min-width:800px;">
      <thead>
        <tr>
          <th>شماره</th>
          <th>منبع</th>
          <th>دسته</th>
          <th>درجه</th>
          <th>مشتری/طرف</th>
          <th>کالا / Lot</th>
          <th>وضعیت</th>
          <th>تاریخ</th>
          <th>اقدام</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
        <tr><td colspan="9" style="text-align:center;padding:32px;color:#9ca3af;">هیچ شکایتی یافت نشد</td></tr>
        <?php else: ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <span style="font-weight:700;font-size:.85rem;"><?= htmlspecialchars($r['complaint_number']) ?></span>
            <?php if ($r['regulatory_reportable'] && !$r['regulatory_reported_at']): ?>
              <span class="fin-badge rose" style="font-size:.7rem;display:block;margin-top:2px;">⚠️ گزارش MDMA</span>
            <?php endif; ?>
          </td>
          <td><span class="fin-badge gray" style="font-size:.75rem;"><?= $sourceLabels[$r['source']] ?? $r['source'] ?></span></td>
          <td style="font-size:.85rem;"><?= $categoryLabels[$r['category']] ?? $r['category'] ?></td>
          <td><span class="fin-badge <?= $severityColors[$r['severity']] ?? 'gray' ?>"><?= $severityLabels[$r['severity']] ?? $r['severity'] ?></span></td>
          <td style="font-size:.85rem;"><?= htmlspecialchars($r['person_name'] ?? '—') ?></td>
          <td style="font-size:.8rem;">
            <?= htmlspecialchars($r['stuff_name'] ?? '—') ?>
            <?php if ($r['batch_number']): ?><br><span style="color:#6b7280;">Lot: <?= htmlspecialchars($r['batch_number']) ?></span><?php endif; ?>
          </td>
          <td><span class="fin-badge <?= $statusColors[$r['status']] ?? 'gray' ?>"><?= $statusLabels[$r['status']] ?? $r['status'] ?></span></td>
          <td style="font-size:.8rem;"><?= jdate('Y/m/d', strtotime($r['created_at'])) ?></td>
          <td style="white-space:nowrap;">
            <button onclick="viewComplaint(<?= $r['id'] ?>)" class="fin-btn fin-btn-outline" style="padding:4px 10px;font-size:.78rem;">🔍</button>
            <?php if (in_array($r['status'], ['open','investigating'], true)): ?>
            <button onclick="createCapa(<?= $r['id'] ?>, '<?= htmlspecialchars($r['complaint_number']) ?>')" class="fin-btn" style="padding:4px 10px;font-size:.78rem;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;">CAPA</button>
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

<!-- Drawer ثبت شکایت -->
<div class="fin-drawer" id="complaintDrawer">
  <div class="fin-drawer-header">
    <span id="drawerTitle">➕ شکایت جدید</span>
    <button onclick="closeDrawer()" class="fin-drawer-close">×</button>
  </div>
  <div class="fin-drawer-body">
    <form id="complaintForm" onsubmit="saveComplaint(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_complaint">
      <input type="hidden" name="id" id="fId" value="0">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div style="grid-column:1/-1;">
          <label class="fin-label">طرف حساب (مشتری/تأمین‌کننده)</label>
          <select name="person_id" id="fPerson" class="fin-input" style="width:100%;">
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($persons as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['n']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">منبع شکایت <span style="color:red">*</span></label>
          <select name="source" id="fSource" class="fin-input" style="width:100%;">
            <?php foreach ($sourceLabels as $v => $l): ?>
            <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">دسته‌بندی <span style="color:red">*</span></label>
          <select name="category" id="fCategory" class="fin-input" style="width:100%;">
            <?php foreach ($categoryLabels as $v => $l): ?>
            <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">درجه اهمیت <span style="color:red">*</span></label>
          <select name="severity" id="fSeverity" class="fin-input" style="width:100%;">
            <?php foreach ($severityLabels as $v => $l): ?>
            <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">شماره Lot/Batch</label>
          <input type="text" name="batch_number" id="fBatch" class="fin-input" style="width:100%;direction:ltr;" placeholder="مثلاً: LOT-2024-001">
        </div>
        <div>
          <label class="fin-label">شماره سریال</label>
          <input type="text" name="serial_number" id="fSerial" class="fin-input" style="width:100%;direction:ltr;">
        </div>
        <div>
          <label class="fin-label">تخصیص به</label>
          <select name="assigned_to" id="fAssignee" class="fin-input" style="width:100%;">
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">وضعیت</label>
          <select name="status" id="fStatus" class="fin-input" style="width:100%;">
            <?php foreach ($statusLabels as $v => $l): ?>
            <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="grid-column:1/-1;">
          <label class="fin-label">شرح شکایت <span style="color:red">*</span></label>
          <textarea name="description" id="fDesc" class="fin-input" rows="3" style="width:100%;resize:vertical;" placeholder="توضیح کامل مشکل..."></textarea>
        </div>
        <div style="grid-column:1/-1;">
          <label class="fin-label">ارزیابی اولیه</label>
          <textarea name="initial_assessment" id="fAssessment" class="fin-input" rows="2" style="width:100%;resize:vertical;" placeholder="اقدام اولیه یا نتیجه بررسی اولیه..."></textarea>
        </div>
        <div style="grid-column:1/-1;display:flex;align-items:center;gap:10px;padding:10px;background:#fef3c7;border-radius:8px;border:1px solid #fcd34d;">
          <input type="checkbox" name="regulatory_reportable" id="fRegReport" value="1">
          <label for="fRegReport" style="font-size:.88rem;font-weight:600;cursor:pointer;">
            ⚠️ این رویداد باید به MDMA (سازمان غذا و دارو) گزارش شود — بند ۸.۲.۳
          </label>
        </div>
      </div>

      <button type="submit" class="fin-btn fin-btn-primary" style="width:100%;padding:12px;">💾 ثبت شکایت</button>
    </form>
  </div>
</div>
<div class="fin-drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<!-- مودال جزئیات -->
<div id="viewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;overflow:auto;">
  <div style="background:#fff;border-radius:16px;margin:40px auto;width:90%;max-width:680px;padding:28px;position:relative;">
    <button onclick="document.getElementById('viewModal').style.display='none'" style="position:absolute;top:16px;left:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#9ca3af;">×</button>
    <div id="viewModalContent"></div>
  </div>
</div>

<script>
function openDrawer(){ document.getElementById('complaintDrawer').classList.add('open'); document.getElementById('drawerOverlay').classList.add('open'); }
function closeDrawer(){ document.getElementById('complaintDrawer').classList.remove('open'); document.getElementById('drawerOverlay').classList.remove('open'); }

function saveComplaint(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const btn = e.target.querySelector('button[type=submit]');
    btn.disabled = true; btn.textContent = '⏳ در حال ثبت...';
    fetch(location.href, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
    .then(r=>r.json()).then(d=>{
        btn.disabled = false;
        if (d.ok) { closeDrawer(); location.reload(); }
        else { btn.textContent = '💾 ثبت شکایت'; alert('❌ ' + d.msg); }
    }).catch(()=>{ btn.disabled=false; alert('❌ خطای شبکه'); });
}

function viewComplaint(id) {
    const fd = new FormData();
    fd.append('action','get_complaint'); fd.append('id',id);
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if (!d.ok||!d.data) { alert('خطا'); return; }
        const c = d.data;
        const srcMap = <?= json_encode($sourceLabels) ?>;
        const catMap = <?= json_encode($categoryLabels) ?>;
        const sevMap = <?= json_encode($severityLabels) ?>;
        const stMap  = <?= json_encode($statusLabels) ?>;
        document.getElementById('viewModalContent').innerHTML = `
            <h3 style="margin:0 0 16px;font-size:1.1rem;">${escH(c.complaint_number)}</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.88rem;">
                <div><b>منبع:</b> ${srcMap[c.source]||c.source}</div>
                <div><b>دسته:</b> ${catMap[c.category]||c.category}</div>
                <div><b>درجه:</b> ${sevMap[c.severity]||c.severity}</div>
                <div><b>وضعیت:</b> ${stMap[c.status]||c.status}</div>
                <div><b>مشتری:</b> ${escH(c.person_name||'—')}</div>
                <div><b>کالا:</b> ${escH(c.stuff_name||'—')}</div>
                <div><b>Lot:</b> ${escH(c.batch_number||'—')}</div>
                <div><b>سریال:</b> ${escH(c.serial_number||'—')}</div>
                <div><b>تخصیص:</b> ${escH(c.assignee_name||'—')}</div>
                <div><b>گزارش MDMA:</b> ${c.regulatory_reportable=='1'?'⚠️ بله':'خیر'}</div>
                ${c.report_deadline?`<div style="grid-column:1/-1;color:#dc2626;"><b>⏰ مهلت گزارش:</b> ${escH(c.report_deadline)}</div>`:''}
            </div>
            <div style="margin-top:12px;"><b>شرح:</b><p style="color:#374151;margin:4px 0;line-height:1.7;">${escH(c.description)}</p></div>
            ${c.initial_assessment?`<div style="margin-top:8px;"><b>ارزیابی اولیه:</b><p style="color:#374151;margin:4px 0;line-height:1.7;">${escH(c.initial_assessment)}</p></div>`:''}
            ${c.resolution?`<div style="margin-top:8px;background:#f0fdf4;padding:10px;border-radius:8px;"><b>✅ نتیجه:</b><p style="margin:4px 0;">${escH(c.resolution)}</p></div>`:''}
        `;
        document.getElementById('viewModal').style.display = 'block';
    });
}

function createCapa(id, num) {
    if (!confirm(`آیا می‌خواهید یک CAPA برای شکایت ${num} ایجاد کنید؟`)) return;
    const fd = new FormData();
    fd.append('action','create_capa'); fd.append('complaint_id',id);
    fd.append('csrf_token','<?= csrf_token() ?>');
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if (d.ok) { alert('✅ ' + d.msg + '\nبه صفحه CAPA هدایت می‌شوید.'); location.href='capa.php'; }
        else alert('❌ ' + d.msg);
    });
}

function escH(s){ return s?String(s).replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])):''; }
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
