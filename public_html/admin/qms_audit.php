<?php
/* ممیزی داخلی + بازنگری مدیریت — ISO 13485 §8.2.2 / §5.6 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$userId   = $_SESSION['user_id'];
$userRole = strtolower($_SESSION['role'] ?? 'staff');

/* ─── جداول ─── */
$pdo->exec("
CREATE TABLE IF NOT EXISTS qms_audit_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_number VARCHAR(30) NOT NULL UNIQUE,
    title VARCHAR(300) NOT NULL,
    audit_type ENUM('internal','external','surveillance','certification') NOT NULL DEFAULT 'internal',
    scope TEXT DEFAULT NULL,
    criteria TEXT DEFAULT NULL,
    standard_clauses VARCHAR(300) DEFAULT NULL,
    areas TEXT DEFAULT NULL,
    planned_date VARCHAR(12) DEFAULT NULL,
    actual_date VARCHAR(12) DEFAULT NULL,
    lead_auditor_id INT UNSIGNED DEFAULT NULL,
    audit_team TEXT DEFAULT NULL,
    status ENUM('planned','in_progress','completed','cancelled') NOT NULL DEFAULT 'planned',
    summary TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    INDEX idx_status(status), INDEX idx_date(planned_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("
CREATE TABLE IF NOT EXISTS qms_audit_findings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_id INT UNSIGNED NOT NULL,
    finding_number VARCHAR(20) DEFAULT NULL,
    finding_type ENUM('major_nc','minor_nc','observation','opportunity') NOT NULL DEFAULT 'minor_nc',
    description TEXT NOT NULL,
    clause_reference VARCHAR(200) DEFAULT NULL,
    department VARCHAR(150) DEFAULT NULL,
    evidence TEXT DEFAULT NULL,
    assigned_to INT UNSIGNED DEFAULT NULL,
    target_date VARCHAR(12) DEFAULT NULL,
    closed_date VARCHAR(12) DEFAULT NULL,
    capa_id INT UNSIGNED DEFAULT NULL,
    status ENUM('open','in_progress','closed','accepted') NOT NULL DEFAULT 'open',
    closure_notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    FOREIGN KEY (audit_id) REFERENCES qms_audit_plans(id) ON DELETE CASCADE,
    INDEX idx_audit(audit_id), INDEX idx_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("
CREATE TABLE IF NOT EXISTS qms_management_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_number VARCHAR(30) NOT NULL UNIQUE,
    title VARCHAR(300) NOT NULL,
    review_date VARCHAR(12) DEFAULT NULL,
    participants TEXT DEFAULT NULL,
    input_audit_results TEXT DEFAULT NULL,
    input_customer_feedback TEXT DEFAULT NULL,
    input_process_performance TEXT DEFAULT NULL,
    input_product_conformance TEXT DEFAULT NULL,
    input_capa_status TEXT DEFAULT NULL,
    input_previous_followup TEXT DEFAULT NULL,
    input_planned_changes TEXT DEFAULT NULL,
    input_regulatory TEXT DEFAULT NULL,
    output_improvement TEXT DEFAULT NULL,
    output_resources TEXT DEFAULT NULL,
    output_product_changes TEXT DEFAULT NULL,
    output_action_items TEXT DEFAULT NULL,
    status ENUM('draft','completed') NOT NULL DEFAULT 'draft',
    next_review_date VARCHAR(12) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    INDEX idx_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* شماره‌گذاری */
function nextAuditNumber(PDO $pdo): string {
    $ym = jdate('Ym');
    $last = $pdo->query("SELECT audit_number FROM qms_audit_plans WHERE audit_number LIKE 'AUD-{$ym}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    return sprintf('AUD-%s-%04d', $ym, $last ? ((int)substr($last,-4)+1) : 1);
}
function nextReviewNumber(PDO $pdo): string {
    $ym = jdate('Ym');
    $last = $pdo->query("SELECT review_number FROM qms_management_reviews WHERE review_number LIKE 'MR-{$ym}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    return sprintf('MR-%s-%04d', $ym, $last ? ((int)substr($last,-4)+1) : 1);
}

/* ─── AJAX ─── */
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    csrf_verify();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    /* ذخیره برنامه ممیزی */
    if ($action === 'save_audit') {
        $id       = (int)($_POST['id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $type     = $_POST['audit_type'] ?? 'internal';
        $scope    = trim($_POST['scope'] ?? '');
        $criteria = trim($_POST['criteria'] ?? '');
        $clauses  = trim($_POST['standard_clauses'] ?? '');
        $areas    = trim($_POST['areas'] ?? '');
        $planned  = trim($_POST['planned_date'] ?? '');
        $leadId   = (int)($_POST['lead_auditor_id'] ?? 0) ?: null;
        $team     = trim($_POST['audit_team'] ?? '');
        if (!$title) { echo json_encode(['ok'=>false,'msg'=>'عنوان الزامی است']); exit; }
        if ($id) {
            $s = $pdo->prepare("UPDATE qms_audit_plans SET title=?,audit_type=?,scope=?,criteria=?,standard_clauses=?,areas=?,planned_date=?,lead_auditor_id=?,audit_team=? WHERE id=? AND is_deleted=0");
            $s->execute([$title,$type,$scope,$criteria,$clauses,$areas,$planned,$leadId,$team,$id]);
        } else {
            $num = nextAuditNumber($pdo);
            $s = $pdo->prepare("INSERT INTO qms_audit_plans (audit_number,title,audit_type,scope,criteria,standard_clauses,areas,planned_date,lead_auditor_id,audit_team,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $s->execute([$num,$title,$type,$scope,$criteria,$clauses,$areas,$planned,$leadId,$team,$userId]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }

    /* تغییر وضعیت ممیزی */
    if ($action === 'update_audit_status') {
        $id      = (int)($_POST['id'] ?? 0);
        $status  = $_POST['status'] ?? '';
        $actual  = trim($_POST['actual_date'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $allowed = ['planned','in_progress','completed','cancelled'];
        if (!in_array($status, $allowed)) { echo json_encode(['ok'=>false,'msg'=>'وضعیت نامعتبر']); exit; }
        $pdo->prepare("UPDATE qms_audit_plans SET status=?,actual_date=?,summary=? WHERE id=?")->execute([$status,$actual,$summary,$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* ذخیره یافته ممیزی */
    if ($action === 'save_finding') {
        $fid     = (int)($_POST['id'] ?? 0);
        $auditId = (int)($_POST['audit_id'] ?? 0);
        $type    = $_POST['finding_type'] ?? 'minor_nc';
        $desc    = trim($_POST['description'] ?? '');
        $clause  = trim($_POST['clause_reference'] ?? '');
        $dept    = trim($_POST['department'] ?? '');
        $evid    = trim($_POST['evidence'] ?? '');
        $assignId= (int)($_POST['assigned_to'] ?? 0) ?: null;
        $target  = trim($_POST['target_date'] ?? '');
        if (!$desc || !$auditId) { echo json_encode(['ok'=>false,'msg'=>'شرح یافته و شناسه ممیزی الزامی است']); exit; }
        if ($fid) {
            $s = $pdo->prepare("UPDATE qms_audit_findings SET finding_type=?,description=?,clause_reference=?,department=?,evidence=?,assigned_to=?,target_date=? WHERE id=? AND is_deleted=0");
            $s->execute([$type,$desc,$clause,$dept,$evid,$assignId,$target,$fid]);
        } else {
            /* شماره یافته: F-XXXX */
            $cnt = $pdo->prepare("SELECT COUNT(*)+1 FROM qms_audit_findings WHERE audit_id=? AND is_deleted=0");
            $cnt->execute([$auditId]);
            $fNum = 'F-' . sprintf('%03d', $cnt->fetchColumn());
            $s = $pdo->prepare("INSERT INTO qms_audit_findings (audit_id,finding_number,finding_type,description,clause_reference,department,evidence,assigned_to,target_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $s->execute([$auditId,$fNum,$type,$desc,$clause,$dept,$evid,$assignId,$target,$userId]);
            $fid = $pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$fid]); exit;
    }

    /* تغییر وضعیت یافته */
    if ($action === 'update_finding_status') {
        $fid    = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['closure_notes'] ?? '');
        $capaId = (int)($_POST['capa_id'] ?? 0) ?: null;
        $closed = ($status === 'closed') ? jdate('Y/m/d') : null;
        $pdo->prepare("UPDATE qms_audit_findings SET status=?,closure_notes=?,capa_id=?,closed_date=? WHERE id=?")->execute([$status,$notes,$capaId,$closed,$fid]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* ایجاد CAPA از یافته */
    if ($action === 'create_capa_from_finding') {
        $fid = (int)($_POST['finding_id'] ?? 0);
        $row = $pdo->prepare("SELECT f.*, a.audit_number FROM qms_audit_findings f JOIN qms_audit_plans a ON a.id=f.audit_id WHERE f.id=?");
        $row->execute([$fid]);
        $f = $row->fetch(PDO::FETCH_ASSOC);
        if (!$f) { echo json_encode(['ok'=>false,'msg'=>'یافته یافت نشد']); exit; }
        $isMajor = in_array($f['finding_type'], ['major_nc','minor_nc']);
        $capaType = $isMajor ? 'corrective' : 'preventive';
        $ym   = jdate('Ym');
        $last = $pdo->query("SELECT capa_number FROM capa_requests WHERE capa_number LIKE '%{$ym}%' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $seq  = $last ? ((int)substr($last,-4)+1) : 1;
        $prefix = $capaType === 'corrective' ? 'CA' : 'PA';
        $capaNum = sprintf('%s-%s-%04d', $prefix, $ym, $seq);
        $title   = 'یافته ممیزی ' . $f['audit_number'] . ': ' . mb_substr($f['description'],0,80);
        $s = $pdo->prepare("INSERT INTO capa_requests (capa_number,type,title,source,description,status,created_by) VALUES (?,?,?,'internal_audit',?,?,?)");
        $s->execute([$capaNum,$capaType,$title,'رفع یافته: '.$f['description'],'open',$userId]);
        $capaId = $pdo->lastInsertId();
        $pdo->prepare("UPDATE qms_audit_findings SET capa_id=?,status='in_progress' WHERE id=?")->execute([$capaId,$fid]);
        echo json_encode(['ok'=>true,'capa_id'=>$capaId,'capa_number'=>$capaNum]); exit;
    }

    /* لیست یافته‌های یک ممیزی */
    if ($action === 'get_findings') {
        $auditId = (int)($_GET['audit_id'] ?? 0);
        $rows = $pdo->prepare("SELECT f.*, u.name assigned_name, c.capa_number
            FROM qms_audit_findings f
            LEFT JOIN users u ON u.id=f.assigned_to
            LEFT JOIN capa_requests c ON c.id=f.capa_id
            WHERE f.audit_id=? AND f.is_deleted=0 ORDER BY f.id");
        $rows->execute([$auditId]);
        echo json_encode(['ok'=>true,'rows'=>$rows->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    /* ذخیره بازنگری مدیریت */
    if ($action === 'save_review') {
        $rid   = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $date  = trim($_POST['review_date'] ?? '');
        $parts = trim($_POST['participants'] ?? '');
        if (!$title) { echo json_encode(['ok'=>false,'msg'=>'عنوان الزامی است']); exit; }
        $fields = ['input_audit_results','input_customer_feedback','input_process_performance',
                   'input_product_conformance','input_capa_status','input_previous_followup',
                   'input_planned_changes','input_regulatory','output_improvement',
                   'output_resources','output_product_changes','output_action_items'];
        $vals = [];
        foreach ($fields as $f) $vals[$f] = trim($_POST[$f] ?? '');
        $nextRev = trim($_POST['next_review_date'] ?? '');
        if ($rid) {
            $setCl = implode('=?,', $fields) . '=?';
            $params = array_values($vals);
            array_push($params, $title, $date, $parts, $nextRev, $rid);
            $pdo->prepare("UPDATE qms_management_reviews SET {$setCl},title=?,review_date=?,participants=?,next_review_date=? WHERE id=? AND is_deleted=0")->execute($params);
        } else {
            $num = nextReviewNumber($pdo);
            $cols = implode(',', $fields);
            $ph   = implode(',', array_fill(0, count($fields), '?'));
            $params = array_values($vals);
            array_push($params, $num, $title, $date, $parts, $nextRev, $userId);
            $pdo->prepare("INSERT INTO qms_management_reviews ({$cols},review_number,title,review_date,participants,next_review_date,created_by) VALUES ({$ph},?,?,?,?,?,?)")->execute($params);
            $rid = $pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$rid]); exit;
    }

    /* تکمیل بازنگری */
    if ($action === 'complete_review') {
        $pdo->prepare("UPDATE qms_management_reviews SET status='completed' WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* دریافت بازنگری برای ویرایش */
    if ($action === 'get_review') {
        $row = $pdo->prepare("SELECT * FROM qms_management_reviews WHERE id=? AND is_deleted=0");
        $row->execute([(int)($_GET['id'] ?? 0)]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>!!$r,'data'=>$r]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'عملیات نامعتبر']); exit;
}

/* ─── داده صفحه ─── */
$audits = $pdo->query("
    SELECT a.*, u.name lead_name,
        (SELECT COUNT(*) FROM qms_audit_findings f WHERE f.audit_id=a.id AND f.is_deleted=0) findings_count,
        (SELECT COUNT(*) FROM qms_audit_findings f WHERE f.audit_id=a.id AND f.status='open' AND f.is_deleted=0) open_count
    FROM qms_audit_plans a
    LEFT JOIN users u ON u.id=a.lead_auditor_id
    WHERE a.is_deleted=0
    ORDER BY a.planned_date DESC, a.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$allFindings = $pdo->query("
    SELECT f.*, u.name assigned_name, a.audit_number, a.title audit_title, c.capa_number
    FROM qms_audit_findings f
    JOIN qms_audit_plans a ON a.id=f.audit_id
    LEFT JOIN users u ON u.id=f.assigned_to
    LEFT JOIN capa_requests c ON c.id=f.capa_id
    WHERE f.is_deleted=0
    ORDER BY f.status='open' DESC, f.target_date, f.id DESC
    LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC);

$reviews = $pdo->query("
    SELECT * FROM qms_management_reviews WHERE is_deleted=0 ORDER BY review_date DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$users = $pdo->query("SELECT id, name, role FROM users WHERE is_deleted=0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$today   = jdate('Y/m/d');
$in30    = jdate('Y/m/d', strtotime('+30 days'));

$statPlanned   = count(array_filter($audits, fn($a) => $a['status']==='planned'));
$statProgress  = count(array_filter($audits, fn($a) => $a['status']==='in_progress'));
$statOpenFind  = count(array_filter($allFindings, fn($f) => $f['status']==='open'));
$statOverdue   = count(array_filter($allFindings, fn($f) => $f['status']==='open' && $f['target_date'] && $f['target_date'] < $today));

$auditStatusLabels = ['planned'=>'برنامه‌ریزی‌شده','in_progress'=>'در جریان','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده'];
$auditStatusColors = ['planned'=>'blue','in_progress'=>'amber','completed'=>'green','cancelled'=>'rose'];
$auditTypeLabels   = ['internal'=>'داخلی','external'=>'خارجی','surveillance'=>'نظارتی','certification'=>'صدور گواهی'];
$findingTypeLabels = ['major_nc'=>'NC اصلی','minor_nc'=>'NC جزئی','observation'=>'مشاهده','opportunity'=>'فرصت بهبود'];
$findingTypeColors = ['major_nc'=>'rose','minor_nc'=>'amber','observation'=>'blue','opportunity'=>'green'];
$findStatusLabels  = ['open'=>'باز','in_progress'=>'در پیگیری','closed'=>'بسته','accepted'=>'پذیرفته‌شده'];

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<link rel="stylesheet" href="/assets/css/fin_module.css">
<style>
.audit-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:12px;transition:box-shadow .2s}
.audit-card:hover{box-shadow:0 2px 12px rgba(0,0,0,.08)}
.audit-card-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
.audit-meta{display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:#6b7280;margin-top:6px}
.finding-row{padding:10px 0;border-bottom:1px solid #f1f5f9}
.finding-row:last-child{border-bottom:none}
.review-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:12px}
.review-section{margin-bottom:20px}
.review-section h4{font-size:13px;font-weight:600;color:#374151;margin:0 0 8px;padding-bottom:6px;border-bottom:2px solid #e2e8f0}
.review-section h4.input-h{border-bottom-color:#3b82f6}
.review-section h4.output-h{border-bottom-color:#16a34a}
textarea.fin-input{resize:vertical}
</style>

<div class="fin-page-wrap" dir="rtl">
<div class="fin-page-header">
  <h1 class="fin-page-title">ممیزی داخلی + بازنگری مدیریت — ISO 13485 §8.2.2 / §5.6</h1>
  <div style="display:flex;gap:8px">
    <button class="fin-btn fin-btn-primary" onclick="openDrawer('Audit')">+ ممیزی جدید</button>
    <button class="fin-btn fin-btn-secondary" onclick="openDrawer('Review')">+ بازنگری مدیریت</button>
  </div>
</div>

<!-- آمار -->
<div class="fin-stats-row">
  <div class="fin-stat-card blue"><div class="fin-stat-value"><?= $statPlanned ?></div><div class="fin-stat-label">ممیزی برنامه‌ریزی‌شده</div></div>
  <div class="fin-stat-card amber"><div class="fin-stat-value"><?= $statProgress ?></div><div class="fin-stat-label">در جریان</div></div>
  <div class="fin-stat-card rose"><div class="fin-stat-value"><?= $statOpenFind ?></div><div class="fin-stat-label">یافته‌های باز</div></div>
  <div class="fin-stat-card <?= $statOverdue > 0 ? 'rose' : 'green' ?>"><div class="fin-stat-value"><?= $statOverdue ?></div><div class="fin-stat-label">یافته گذشته از سررسید</div></div>
</div>

<div class="fin-panel">
<div class="fin-tabs">
  <button class="fin-tab active" data-tab="tab-audits">برنامه ممیزی</button>
  <button class="fin-tab" data-tab="tab-findings">فهرست یافته‌ها</button>
  <button class="fin-tab" data-tab="tab-reviews">بازنگری مدیریت</button>
</div>

<!-- برنامه ممیزی -->
<div id="tab-audits" class="fin-tab-content active" style="padding:16px">
<?php if (!$audits): ?>
<div style="text-align:center;color:#9ca3af;padding:48px">ممیزی‌ای ثبت نشده — از دکمه «ممیزی جدید» شروع کنید</div>
<?php endif; ?>
<?php foreach ($audits as $aud):
  $statusColor = $auditStatusColors[$aud['status']] ?? 'blue';
  $isOverdue = $aud['planned_date'] && $aud['planned_date'] < $today && $aud['status']==='planned';
?>
<div class="audit-card">
  <div class="audit-card-header">
    <div>
      <code style="font-size:12px;color:#6b7280"><?= $aud['audit_number'] ?></code>
      <h3 style="margin:4px 0;font-size:15px"><?= htmlspecialchars($aud['title']) ?></h3>
      <div class="audit-meta">
        <span>📋 <?= $auditTypeLabels[$aud['audit_type']] ?></span>
        <?php if ($aud['planned_date']): ?><span style="<?= $isOverdue ? 'color:#dc2626;font-weight:600' : '' ?>">📅 <?= $aud['planned_date'] ?><?= $isOverdue?' ⚠️':'' ?></span><?php endif; ?>
        <?php if ($aud['lead_name']): ?><span>👤 <?= htmlspecialchars($aud['lead_name']) ?></span><?php endif; ?>
        <?php if ($aud['standard_clauses']): ?><span>📌 <?= htmlspecialchars($aud['standard_clauses']) ?></span><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px">
      <span class="fin-badge fin-badge-<?= $statusColor ?>"><?= $auditStatusLabels[$aud['status']] ?></span>
      <div style="font-size:12px;color:#6b7280">
        <?php if ($aud['findings_count']): ?><span style="<?= $aud['open_count'] ? 'color:#d97706;font-weight:600' : '' ?>">یافته: <?= $aud['findings_count'] ?> (<?= $aud['open_count'] ?> باز)</span><?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($aud['summary']): ?><p style="font-size:13px;color:#374151;margin:0 0 10px"><?= nl2br(htmlspecialchars($aud['summary'])) ?></p><?php endif; ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button class="fin-btn fin-btn-xs fin-btn-secondary" onclick='editAudit(<?= json_encode($aud) ?>)'>ویرایش</button>
    <button class="fin-btn fin-btn-xs" style="background:#dcfce7;color:#15803d" onclick="showFindings(<?= $aud['id'] ?>, '<?= htmlspecialchars(addslashes($aud['title'])) ?>')">📋 یافته‌ها</button>
    <a href="qms_pdf.php?type=audit&id=<?= $aud['id'] ?>" target="_blank" class="fin-btn fin-btn-xs" style="background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff">PDF گزارش</a>
    <?php if ($aud['status'] === 'planned'): ?>
    <button class="fin-btn fin-btn-xs" style="background:#fef9c3;color:#854d0e" onclick="changeAuditStatus(<?= $aud['id'] ?>, 'in_progress')">▶ شروع ممیزی</button>
    <?php elseif ($aud['status'] === 'in_progress'): ?>
    <button class="fin-btn fin-btn-xs" style="background:#dcfce7;color:#15803d" onclick="changeAuditStatus(<?= $aud['id'] ?>, 'completed')">✅ تکمیل ممیزی</button>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- یافته‌ها -->
<div id="tab-findings" class="fin-tab-content" style="padding:16px">
<table class="fin-table">
  <thead><tr><th>شماره</th><th>ممیزی</th><th>نوع</th><th>شرح</th><th>بند</th><th>مسئول</th><th>سررسید</th><th>وضعیت</th><th>CAPA</th><th>عملیات</th></tr></thead>
  <tbody>
  <?php foreach ($allFindings as $f):
    $isOD = $f['status']==='open' && $f['target_date'] && $f['target_date'] < $today;
  ?>
  <tr>
    <td><code><?= $f['finding_number'] ?: '—' ?></code></td>
    <td><small><?= htmlspecialchars($f['audit_title'] ?? '') ?></small><br><code style="font-size:10px"><?= $f['audit_number'] ?></code></td>
    <td><span class="fin-badge fin-badge-<?= $findingTypeColors[$f['finding_type']] ?>"><?= $findingTypeLabels[$f['finding_type']] ?></span></td>
    <td style="max-width:200px;font-size:13px"><?= htmlspecialchars(mb_substr($f['description'],0,80)) ?>...</td>
    <td><small><?= htmlspecialchars($f['clause_reference'] ?: '—') ?></small></td>
    <td><?= htmlspecialchars($f['assigned_name'] ?: '—') ?></td>
    <td style="<?= $isOD ? 'color:#dc2626;font-weight:600' : '' ?>"><?= $f['target_date'] ?: '—' ?><?= $isOD ? ' ⚠️' : '' ?></td>
    <td><span class="fin-badge"><?= $findStatusLabels[$f['status']] ?></span></td>
    <td>
      <?php if ($f['capa_number']): ?>
        <a href="capa.php" style="color:#2563eb;font-size:12px"><?= $f['capa_number'] ?></a>
      <?php else: ?>
        <button class="fin-btn fin-btn-xs" style="background:#f3e8ff;color:#7e22ce" onclick="createCAPAFromFinding(<?= $f['id'] ?>)">+CAPA</button>
      <?php endif; ?>
    </td>
    <td>
      <button class="fin-btn fin-btn-xs fin-btn-secondary" onclick='openFindingUpdate(<?= json_encode($f) ?>)'>به‌روزرسانی</button>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$allFindings): ?><tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:32px">یافته‌ای ثبت نشده</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<!-- بازنگری مدیریت -->
<div id="tab-reviews" class="fin-tab-content" style="padding:16px">
<?php if (!$reviews): ?>
<div style="text-align:center;color:#9ca3af;padding:48px">بازنگری مدیریتی ثبت نشده — از دکمه «بازنگری مدیریت» شروع کنید</div>
<?php endif; ?>
<?php foreach ($reviews as $rev): ?>
<div class="review-card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <div>
      <code style="font-size:12px;color:#6b7280"><?= $rev['review_number'] ?></code>
      <h3 style="margin:4px 0;font-size:15px"><?= htmlspecialchars($rev['title']) ?></h3>
      <?php if ($rev['review_date']): ?><small style="color:#6b7280">📅 <?= $rev['review_date'] ?></small><?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <span class="fin-badge fin-badge-<?= $rev['status']==='completed'?'green':'amber' ?>"><?= $rev['status']==='completed'?'تکمیل‌شده':'پیش‌نویس' ?></span>
      <button class="fin-btn fin-btn-xs fin-btn-secondary" onclick="editReview(<?= $rev['id'] ?>)">ویرایش / مشاهده</button>
      <?php if ($rev['status'] === 'draft'): ?><button class="fin-btn fin-btn-xs" style="background:#dcfce7;color:#15803d" onclick="completeReview(<?= $rev['id'] ?>)">✅ تکمیل</button><?php endif; ?>
    </div>
  </div>
  <?php if ($rev['participants']): ?><p style="font-size:12px;color:#6b7280;margin:0">شرکت‌کنندگان: <?= htmlspecialchars($rev['participants']) ?></p><?php endif; ?>
  <?php if ($rev['next_review_date']): ?><p style="font-size:12px;color:#6b7280;margin:4px 0 0">بازنگری بعدی: <?= $rev['next_review_date'] ?></p><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</div><!-- fin-panel -->

<!-- ─── Drawer ممیزی ─── -->
<div class="fin-drawer-overlay" id="drawerAudit">
<div class="fin-drawer">
  <div class="fin-drawer-header"><h3 id="auditDrawerTitle">ممیزی جدید</h3><button onclick="closeDrawer('Audit')">✕</button></div>
  <div class="fin-drawer-body">
    <form id="formAudit" onsubmit="submitAudit(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_audit">
      <input type="hidden" name="id" id="a_id" value="0">
      <div class="fin-form-group"><label>عنوان ممیزی *</label><input type="text" name="title" id="a_title" class="fin-input" required></div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>نوع</label>
          <select name="audit_type" id="a_type" class="fin-input">
            <option value="internal">داخلی</option><option value="external">خارجی</option>
            <option value="surveillance">نظارتی</option><option value="certification">صدور گواهی</option>
          </select>
        </div>
        <div class="fin-form-group"><label>تاریخ برنامه‌ریزی‌شده</label><input type="text" name="planned_date" id="a_date" class="fin-input" placeholder="۱۴۰۳/۰۶/۰۱"></div>
      </div>
      <div class="fin-form-group"><label>سرممیز</label>
        <select name="lead_auditor_id" id="a_lead" class="fin-input">
          <option value="">—</option>
          <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="fin-form-group"><label>بندهای ISO 13485 مرتبط</label><input type="text" name="standard_clauses" id="a_clauses" class="fin-input" placeholder="مثلا: §8.2, §7.4, §8.3"></div>
      <div class="fin-form-group"><label>دامنه ممیزی</label><textarea name="scope" id="a_scope" class="fin-input" rows="2" placeholder="کدام دپارتمان‌ها / فرآیندها ممیزی می‌شوند"></textarea></div>
      <div class="fin-form-group"><label>معیارهای ممیزی</label><textarea name="criteria" id="a_criteria" class="fin-input" rows="2" placeholder="الزامات ISO 13485 + روش‌های اجرایی داخلی"></textarea></div>
      <div class="fin-form-group"><label>اعضای تیم ممیزی</label><input type="text" name="audit_team" id="a_team" class="fin-input" placeholder="نام‌ها با کاما جدا کنید"></div>
      <div class="fin-drawer-footer"><button type="submit" class="fin-btn fin-btn-primary">ذخیره</button><button type="button" class="fin-btn fin-btn-secondary" onclick="closeDrawer('Audit')">انصراف</button></div>
    </form>
  </div>
</div>
</div>

<!-- ─── Modal یافته‌ها ─── -->
<div id="modalFindings" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;overflow-y:auto" dir="rtl">
<div style="background:#fff;max-width:900px;margin:40px auto;border-radius:12px;overflow:hidden">
  <div style="background:#1e293b;color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center">
    <h3 id="findingsModalTitle" style="margin:0;font-size:15px"></h3>
    <button onclick="closeFindingsModal()" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer">✕</button>
  </div>
  <div style="padding:20px">
    <div id="findingsList"></div>
    <hr style="margin:16px 0">
    <h4 style="margin:0 0 12px">+ یافته جدید</h4>
    <form id="formFinding" onsubmit="submitFinding(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_finding">
      <input type="hidden" name="audit_id" id="f_audit_id" value="">
      <div class="fin-form-row">
        <div class="fin-form-group"><label>نوع یافته</label>
          <select name="finding_type" class="fin-input">
            <option value="major_nc">NC اصلی</option><option value="minor_nc">NC جزئی</option>
            <option value="observation">مشاهده</option><option value="opportunity">فرصت بهبود</option>
          </select>
        </div>
        <div class="fin-form-group"><label>بند مرتبط</label><input type="text" name="clause_reference" class="fin-input" placeholder="§8.3.1"></div>
      </div>
      <div class="fin-form-group"><label>شرح یافته *</label><textarea name="description" class="fin-input" rows="3" required></textarea></div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>شواهد</label><input type="text" name="evidence" class="fin-input" placeholder="سند/رکورد یافت‌شده"></div>
        <div class="fin-form-group"><label>دپارتمان</label><input type="text" name="department" class="fin-input"></div>
      </div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>مسئول پاسخگویی</label>
          <select name="assigned_to" class="fin-input">
            <option value="">—</option>
            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="fin-form-group"><label>سررسید اقدام</label><input type="text" name="target_date" class="fin-input" placeholder="<?= $today ?>"></div>
      </div>
      <button type="submit" class="fin-btn fin-btn-primary">ثبت یافته</button>
    </form>
  </div>
</div>
</div>

<!-- ─── Modal به‌روزرسانی یافته ─── -->
<div id="modalFindingUpdate" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9100" dir="rtl">
<div style="background:#fff;max-width:520px;margin:80px auto;border-radius:12px;overflow:hidden">
  <div style="background:#1e293b;color:#fff;padding:14px 18px;display:flex;justify-content:space-between">
    <h4 style="margin:0">به‌روزرسانی یافته</h4>
    <button onclick="document.getElementById('modalFindingUpdate').style.display='none'" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer">✕</button>
  </div>
  <div style="padding:18px">
    <form id="formFindingUpdate" onsubmit="submitFindingUpdate(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_finding_status">
      <input type="hidden" name="id" id="fu_id" value="">
      <div class="fin-form-group"><label>وضعیت جدید</label>
        <select name="status" id="fu_status" class="fin-input">
          <option value="open">باز</option><option value="in_progress">در پیگیری</option>
          <option value="closed">بسته</option><option value="accepted">پذیرفته‌شده</option>
        </select>
      </div>
      <div class="fin-form-group"><label>یادداشت بستن</label><textarea name="closure_notes" id="fu_notes" class="fin-input" rows="3"></textarea></div>
      <div class="fin-drawer-footer"><button type="submit" class="fin-btn fin-btn-primary">ذخیره</button></div>
    </form>
  </div>
</div>
</div>

<!-- ─── Drawer بازنگری مدیریت ─── -->
<div class="fin-drawer-overlay" id="drawerReview" style="z-index:8500">
<div class="fin-drawer" style="max-width:700px">
  <div class="fin-drawer-header"><h3>بازنگری مدیریت — §5.6</h3><button onclick="closeDrawer('Review')">✕</button></div>
  <div class="fin-drawer-body">
    <form id="formReview" onsubmit="submitReview(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_review">
      <input type="hidden" name="id" id="rev_id" value="0">
      <div class="fin-form-row">
        <div class="fin-form-group"><label>عنوان *</label><input type="text" name="title" id="rev_title" class="fin-input" required placeholder="مثلا: بازنگری مدیریت فصل سوم ۱۴۰۳"></div>
        <div class="fin-form-group"><label>تاریخ جلسه</label><input type="text" name="review_date" id="rev_date" class="fin-input" placeholder="<?= $today ?>"></div>
      </div>
      <div class="fin-form-group"><label>شرکت‌کنندگان</label><input type="text" name="participants" id="rev_parts" class="fin-input" placeholder="نام مدیران و اعضای کلیدی"></div>

      <div class="review-section">
        <h4 class="input-h">ورودی‌های بازنگری §5.6.2</h4>
        <div class="fin-form-group"><label>نتایج ممیزی‌ها</label><textarea name="input_audit_results" id="rev_audit" class="fin-input" rows="2"></textarea></div>
        <div class="fin-form-group"><label>بازخورد مشتریان (شکایات، رضایت)</label><textarea name="input_customer_feedback" id="rev_cust" class="fin-input" rows="2"></textarea></div>
        <div class="fin-form-group"><label>عملکرد فرآیندها و انطباق محصول</label><textarea name="input_process_performance" id="rev_proc" class="fin-input" rows="2"></textarea></div>
        <div class="fin-form-group"><label>انطباق محصول (NC، مرجوعی، گارانتی)</label><textarea name="input_product_conformance" id="rev_prod" class="fin-input" rows="2"></textarea></div>
        <div class="fin-form-group"><label>وضعیت CAPA‌های قبلی</label><textarea name="input_capa_status" id="rev_capa" class="fin-input" rows="2"></textarea></div>
        <div class="fin-form-group"><label>پیگیری جلسه قبلی</label><textarea name="input_previous_followup" id="rev_prev" class="fin-input" rows="2"></textarea></div>
        <div class="fin-form-group"><label>تغییرات برنامه‌ریزی‌شده</label><textarea name="input_planned_changes" id="rev_changes" class="fin-input" rows="2"></textarea></div>
        <div class="fin-form-group"><label>الزامات قانونی و نظارتی جدید</label><textarea name="input_regulatory" id="rev_reg" class="fin-input" rows="2"></textarea></div>
      </div>

      <div class="review-section">
        <h4 class="output-h">خروجی‌های بازنگری §5.6.3</h4>
        <div class="fin-form-group"><label>تصمیمات بهبود سیستم کیفیت</label><textarea name="output_improvement" id="rev_imp" class="fin-input" rows="2"></textarea></div>
        <div class="fin-form-group"><label>نیازهای منابع (نیروی انسانی، تجهیزات)</label><textarea name="output_resources" id="rev_res" class="fin-input" rows="2"></textarea></div>
        <div class="fin-form-group"><label>تغییرات لازم در محصول/فرآیند</label><textarea name="output_product_changes" id="rev_pchg" class="fin-input" rows="2"></textarea></div>
        <div class="fin-form-group"><label>اقدامات مصوب با مسئول و سررسید</label><textarea name="output_action_items" id="rev_actions" class="fin-input" rows="3" placeholder="۱. ... — مسئول: ... — سررسید: ..."></textarea></div>
      </div>

      <div class="fin-form-group"><label>تاریخ بازنگری بعدی</label><input type="text" name="next_review_date" id="rev_next" class="fin-input" placeholder="۱۴۰۴/۰۱/۰۱"></div>
      <div class="fin-drawer-footer"><button type="submit" class="fin-btn fin-btn-primary">ذخیره</button><button type="button" class="fin-btn fin-btn-secondary" onclick="closeDrawer('Review')">انصراف</button></div>
    </form>
  </div>
</div>
</div>

</div><!-- fin-page-wrap -->

<script>
/* تب‌ها */
document.querySelectorAll('.fin-panel > .fin-tabs .fin-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.fin-panel > .fin-tabs .fin-tab, .fin-panel .fin-tab-content').forEach(el => el.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

function openDrawer(n) { document.getElementById('drawer'+n).classList.add('open'); }
function closeDrawer(n) { document.getElementById('drawer'+n).classList.remove('open'); }

function editAudit(a) {
    document.getElementById('a_id').value      = a.id;
    document.getElementById('a_title').value   = a.title;
    document.getElementById('a_type').value    = a.audit_type;
    document.getElementById('a_date').value    = a.planned_date || '';
    document.getElementById('a_lead').value    = a.lead_auditor_id || '';
    document.getElementById('a_clauses').value = a.standard_clauses || '';
    document.getElementById('a_scope').value   = a.scope || '';
    document.getElementById('a_criteria').value= a.criteria || '';
    document.getElementById('a_team').value    = a.audit_team || '';
    document.getElementById('auditDrawerTitle').textContent = 'ویرایش ممیزی';
    openDrawer('Audit');
}

function submitAudit(e) {
    e.preventDefault();
    $.ajax({url:'', method:'POST', data:$(e.target).serialize(), headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){location.reload();}else{alert(r.msg);} }
    });
}

function changeAuditStatus(id, status) {
    let actual = '', summary = '';
    if (status === 'completed') {
        actual  = prompt('تاریخ انجام واقعی ممیزی:', '<?= $today ?>');
        if (actual === null) return;
        summary = prompt('خلاصه نتایج ممیزی:', '');
        if (summary === null) return;
    } else if (status === 'in_progress') {
        actual = '<?= $today ?>';
    }
    $.ajax({url:'', method:'POST',
        data:{action:'update_audit_status',id:id,status:status,actual_date:actual,summary:summary,_csrf:$('[name=_csrf]').first().val()},
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok) location.reload(); }
    });
}

let currentAuditId = null;
function showFindings(auditId, title) {
    currentAuditId = auditId;
    document.getElementById('f_audit_id').value = auditId;
    document.getElementById('findingsModalTitle').textContent = 'یافته‌های ممیزی: ' + title;
    loadFindings(auditId);
    document.getElementById('modalFindings').style.display = 'block';
}
function closeFindingsModal() { document.getElementById('modalFindings').style.display='none'; }

function loadFindings(auditId) {
    $.ajax({url:'?action=get_findings&audit_id='+auditId, headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => {
            if (!r.rows.length) { document.getElementById('findingsList').innerHTML='<p style="color:#9ca3af;text-align:center">یافته‌ای ثبت نشده</p>'; return; }
            const typeColor = {major_nc:'#fef2f2',minor_nc:'#fef9c3',observation:'#eff6ff',opportunity:'#f0fdf4'};
            const typeLbl   = {major_nc:'NC اصلی',minor_nc:'NC جزئی',observation:'مشاهده',opportunity:'فرصت بهبود'};
            const statusLbl = {open:'باز',in_progress:'در پیگیری',closed:'بسته',accepted:'پذیرفته‌شده'};
            let html = '';
            r.rows.forEach(f => {
                const bg = typeColor[f.finding_type]||'#f8fafc';
                html += `<div class="finding-row" style="background:${bg};padding:10px;border-radius:6px;margin-bottom:8px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                        <strong>${f.finding_number||''} — ${typeLbl[f.finding_type]}</strong>
                        <span class="fin-badge">${statusLbl[f.status]}</span>
                    </div>
                    <p style="margin:0 0 6px;font-size:13px">${f.description}</p>
                    <div style="font-size:12px;color:#6b7280;display:flex;gap:12px">
                        ${f.clause_reference?'<span>بند: '+f.clause_reference+'</span>':''}
                        ${f.assigned_name?'<span>مسئول: '+f.assigned_name+'</span>':''}
                        ${f.target_date?'<span>سررسید: '+f.target_date+'</span>':''}
                        ${f.capa_number?'<span style="color:#7e22ce">CAPA: '+f.capa_number+'</span>':''}
                    </div>
                </div>`;
            });
            document.getElementById('findingsList').innerHTML = html;
        }
    });
}

function submitFinding(e) {
    e.preventDefault();
    $.ajax({url:'', method:'POST', data:$(e.target).serialize(), headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){loadFindings(currentAuditId); e.target.reset(); document.getElementById('f_audit_id').value=currentAuditId;}else{alert(r.msg);} }
    });
}

function openFindingUpdate(f) {
    document.getElementById('fu_id').value     = f.id;
    document.getElementById('fu_status').value = f.status;
    document.getElementById('fu_notes').value  = f.closure_notes || '';
    document.getElementById('modalFindingUpdate').style.display = 'block';
}
function submitFindingUpdate(e) {
    e.preventDefault();
    $.ajax({url:'', method:'POST', data:$(e.target).serialize(), headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){document.getElementById('modalFindingUpdate').style.display='none'; location.reload();}else{alert(r.msg||'خطا');} }
    });
}

function createCAPAFromFinding(fid) {
    if (!confirm('یک CAPA جدید از این یافته ایجاد شود؟')) return;
    $.ajax({url:'', method:'POST',
        data:{action:'create_capa_from_finding',finding_id:fid,_csrf:$('[name=_csrf]').first().val()},
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => {
            if (r.ok) { alert('CAPA شماره ' + r.capa_number + ' ایجاد شد'); location.reload(); }
            else { alert(r.msg||'خطا'); }
        }
    });
}

function submitReview(e) {
    e.preventDefault();
    $.ajax({url:'', method:'POST', data:$(e.target).serialize(), headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){location.reload();}else{alert(r.msg);} }
    });
}

function editReview(id) {
    $.ajax({url:'?action=get_review&id='+id, headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => {
            if (!r.ok) return;
            const d = r.data;
            document.getElementById('rev_id').value      = d.id;
            document.getElementById('rev_title').value   = d.title;
            document.getElementById('rev_date').value    = d.review_date||'';
            document.getElementById('rev_parts').value   = d.participants||'';
            document.getElementById('rev_audit').value   = d.input_audit_results||'';
            document.getElementById('rev_cust').value    = d.input_customer_feedback||'';
            document.getElementById('rev_proc').value    = d.input_process_performance||'';
            document.getElementById('rev_prod').value    = d.input_product_conformance||'';
            document.getElementById('rev_capa').value    = d.input_capa_status||'';
            document.getElementById('rev_prev').value    = d.input_previous_followup||'';
            document.getElementById('rev_changes').value = d.input_planned_changes||'';
            document.getElementById('rev_reg').value     = d.input_regulatory||'';
            document.getElementById('rev_imp').value     = d.output_improvement||'';
            document.getElementById('rev_res').value     = d.output_resources||'';
            document.getElementById('rev_pchg').value    = d.output_product_changes||'';
            document.getElementById('rev_actions').value = d.output_action_items||'';
            document.getElementById('rev_next').value    = d.next_review_date||'';
            openDrawer('Review');
        }
    });
}

function completeReview(id) {
    if (!confirm('این بازنگری مدیریت به وضعیت «تکمیل‌شده» تغییر یابد؟')) return;
    $.ajax({url:'', method:'POST',
        data:{action:'complete_review',id:id,_csrf:$('[name=_csrf]').first().val()},
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok) location.reload(); }
    });
}

document.getElementById('modalFindings').addEventListener('click', e => { if(e.target===document.getElementById('modalFindings')) closeFindingsModal(); });
document.getElementById('modalFindingUpdate').addEventListener('click', e => { if(e.target===document.getElementById('modalFindingUpdate')) document.getElementById('modalFindingUpdate').style.display='none'; });
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
