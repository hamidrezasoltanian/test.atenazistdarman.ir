<?php
/* کنترل مدارک با نسخه‌بندی — ISO 13485 §4.2.4 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$userId   = $_SESSION['user_id'];
$userRole = strtolower($_SESSION['role'] ?? 'staff');

/* ─── جداول ─── */
$pdo->exec("
CREATE TABLE IF NOT EXISTS doc_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_number VARCHAR(30) NOT NULL UNIQUE,
    title VARCHAR(300) NOT NULL,
    category ENUM('quality_system','procedure','work_instruction','form','policy','specification','regulatory','supplier','hr','other') NOT NULL DEFAULT 'procedure',
    is_external TINYINT(1) DEFAULT 0,
    current_version VARCHAR(10) NOT NULL DEFAULT '1.0',
    status ENUM('draft','review','approved','obsolete') NOT NULL DEFAULT 'draft',
    responsible_id INT UNSIGNED DEFAULT NULL,
    approver_id INT UNSIGNED DEFAULT NULL,
    effective_date VARCHAR(12) DEFAULT NULL,
    next_review_date VARCHAR(12) DEFAULT NULL,
    storage_location VARCHAR(200) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    INDEX idx_status(status), INDEX idx_category(category), INDEX idx_review(next_review_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("
CREATE TABLE IF NOT EXISTS doc_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id INT UNSIGNED NOT NULL,
    version_number VARCHAR(10) NOT NULL,
    change_summary TEXT DEFAULT NULL,
    file_path VARCHAR(300) DEFAULT NULL,
    file_name VARCHAR(200) DEFAULT NULL,
    prepared_by INT UNSIGNED DEFAULT NULL,
    reviewed_by INT UNSIGNED DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approval_date VARCHAR(12) DEFAULT NULL,
    effective_date VARCHAR(12) DEFAULT NULL,
    status ENUM('draft','review','approved','superseded') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (doc_id) REFERENCES doc_documents(id) ON DELETE CASCADE,
    INDEX idx_doc(doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("
CREATE TABLE IF NOT EXISTS doc_distribution (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id INT UNSIGNED NOT NULL,
    version_id INT UNSIGNED DEFAULT NULL,
    distributed_to INT UNSIGNED DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    distribution_date VARCHAR(12) DEFAULT NULL,
    method ENUM('electronic','print','email') NOT NULL DEFAULT 'electronic',
    acknowledged TINYINT(1) DEFAULT 0,
    ack_date VARCHAR(12) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doc_id) REFERENCES doc_documents(id) ON DELETE CASCADE,
    INDEX idx_doc(doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("
CREATE TABLE IF NOT EXISTS doc_status_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id INT UNSIGNED NOT NULL,
    version_id INT UNSIGNED DEFAULT NULL,
    from_status VARCHAR(20) DEFAULT NULL,
    to_status VARCHAR(20) NOT NULL,
    actor_id INT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doc_id) REFERENCES doc_documents(id) ON DELETE CASCADE,
    INDEX idx_doc(doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* پوشه آپلود */
$uploadDir = __DIR__ . '/../../public_html/uploads/documents/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

/* شماره‌گذاری */
function nextDocNumber(PDO $pdo): string {
    $y = jdate('Y');
    $last = $pdo->query("SELECT doc_number FROM doc_documents WHERE doc_number LIKE 'DOC-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq  = $last ? ((int)substr($last, -4) + 1) : 1;
    return sprintf('DOC-%s-%04d', $y, $seq);
}

/* ─── AJAX ─── */
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    csrf_verify();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    /* ذخیره / ویرایش مدرک */
    if ($action === 'save_document') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $category    = $_POST['category'] ?? 'procedure';
        $isExternal  = isset($_POST['is_external']) ? 1 : 0;
        $respId      = (int)($_POST['responsible_id'] ?? 0) ?: null;
        $approverId  = (int)($_POST['approver_id'] ?? 0) ?: null;
        $effDate     = trim($_POST['effective_date'] ?? '');
        $reviewDate  = trim($_POST['next_review_date'] ?? '');
        $storage     = trim($_POST['storage_location'] ?? '');
        $desc        = trim($_POST['description'] ?? '');
        if (!$title) { echo json_encode(['ok'=>false,'msg'=>'عنوان الزامی است']); exit; }
        if ($id) {
            $s = $pdo->prepare("UPDATE doc_documents SET title=?,category=?,is_external=?,responsible_id=?,approver_id=?,effective_date=?,next_review_date=?,storage_location=?,description=? WHERE id=? AND is_deleted=0");
            $s->execute([$title,$category,$isExternal,$respId,$approverId,$effDate,$reviewDate,$storage,$desc,$id]);
        } else {
            $num = nextDocNumber($pdo);
            $s = $pdo->prepare("INSERT INTO doc_documents (doc_number,title,category,is_external,responsible_id,approver_id,effective_date,next_review_date,storage_location,description,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $s->execute([$num,$title,$category,$isExternal,$respId,$approverId,$effDate,$reviewDate,$storage,$desc,$userId]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }

    /* ارسال نسخه جدید (با آپلود فایل) */
    if ($action === 'add_version') {
        $docId   = (int)($_POST['doc_id'] ?? 0);
        $version = trim($_POST['version_number'] ?? '');
        $summary = trim($_POST['change_summary'] ?? '');
        $effDate = trim($_POST['effective_date'] ?? '');
        if (!$docId || !$version) { echo json_encode(['ok'=>false,'msg'=>'شماره مدرک و نسخه الزامی است']); exit; }

        $filePath = null; $fileName = null;
        if (!empty($_FILES['doc_file']['name'])) {
            $mime = mime_content_type($_FILES['doc_file']['tmp_name']);
            $allowed = ['application/pdf','application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'image/jpeg','image/png'];
            if (!in_array($mime, $allowed)) { echo json_encode(['ok'=>false,'msg'=>'فرمت فایل مجاز نیست (PDF، Word، Excel، تصویر)']); exit; }
            $ext  = pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION);
            $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', pathinfo($_FILES['doc_file']['name'], PATHINFO_FILENAME));
            $fileName = $safe . '_v' . str_replace('.', '_', $version) . '_' . time() . '.' . $ext;
            $filePath = 'uploads/documents/' . $fileName;
            move_uploaded_file($_FILES['doc_file']['tmp_name'], $uploadDir . $fileName);
        }

        /* نسخه‌های قبلی approved را superseded کن */
        $pdo->prepare("UPDATE doc_versions SET status='superseded' WHERE doc_id=? AND status='approved'")->execute([$docId]);

        $s = $pdo->prepare("INSERT INTO doc_versions (doc_id,version_number,change_summary,file_path,file_name,prepared_by,effective_date,status) VALUES (?,?,?,?,?,?,?,'draft')");
        $s->execute([$docId,$version,$summary,$filePath,$fileName,$userId,$effDate]);
        $vId = $pdo->lastInsertId();

        /* آپدیت نسخه جاری در مدرک */
        $pdo->prepare("UPDATE doc_documents SET current_version=?, status='draft' WHERE id=?")->execute([$version,$docId]);

        /* لاگ */
        $pdo->prepare("INSERT INTO doc_status_logs (doc_id,version_id,from_status,to_status,actor_id,notes) VALUES (?,?,?,?,'draft',?)")->execute([$docId,$vId,null,'draft',$userId,'نسخه جدید '.$version.' ثبت شد']);

        echo json_encode(['ok'=>true,'version_id'=>$vId]); exit;
    }

    /* تغییر وضعیت نسخه */
    if ($action === 'advance_version_status') {
        $docId   = (int)($_POST['doc_id'] ?? 0);
        $vId     = (int)($_POST['version_id'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');
        $next    = ['draft'=>'review','review'=>'approved'];
        $ver     = $pdo->prepare("SELECT * FROM doc_versions WHERE id=? AND doc_id=?");
        $ver->execute([$vId,$docId]);
        $v = $ver->fetch(PDO::FETCH_ASSOC);
        if (!$v || !isset($next[$v['status']])) { echo json_encode(['ok'=>false,'msg'=>'وضعیت قابل پیشرفت نیست']); exit; }
        $newStatus = $next[$v['status']];
        $now = jdate('Y/m/d');
        $extra = [];
        if ($newStatus === 'review') {
            $extra = ['reviewed_by' => $userId];
        } elseif ($newStatus === 'approved') {
            $extra = ['approved_by' => $userId, 'approval_date' => $now];
        }
        $setClauses = 'status=?';
        $params = [$newStatus];
        foreach ($extra as $col => $val) { $setClauses .= ", {$col}=?"; $params[] = $val; }
        $params[] = $vId;
        $pdo->prepare("UPDATE doc_versions SET {$setClauses} WHERE id=?")->execute($params);

        /* اگر approved شد، مدرک هم approved می‌شود */
        if ($newStatus === 'approved') {
            $ver2 = $pdo->prepare("SELECT version_number FROM doc_versions WHERE id=?");
            $ver2->execute([$vId]);
            $vNum = $ver2->fetchColumn();
            $pdo->prepare("UPDATE doc_documents SET status='approved', current_version=?, effective_date=? WHERE id=?")->execute([$vNum,$now,$docId]);
        }

        $pdo->prepare("INSERT INTO doc_status_logs (doc_id,version_id,from_status,to_status,actor_id,notes) VALUES (?,?,?,?,?,?)")
            ->execute([$docId,$vId,$v['status'],$newStatus,$userId,$notes]);

        echo json_encode(['ok'=>true,'new_status'=>$newStatus]); exit;
    }

    /* منسوخ کردن مدرک */
    if ($action === 'obsolete_document') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $cur   = $pdo->prepare("SELECT status FROM doc_documents WHERE id=?");
        $cur->execute([$docId]);
        $from = $cur->fetchColumn();
        $pdo->prepare("UPDATE doc_documents SET status='obsolete' WHERE id=?")->execute([$docId]);
        $pdo->prepare("INSERT INTO doc_status_logs (doc_id,from_status,to_status,actor_id,notes) VALUES (?,?,?,?,?)")
            ->execute([$docId,$from,'obsolete',$userId,$notes]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* حذف مدرک (soft) */
    if ($action === 'delete_document') {
        $pdo->prepare("UPDATE doc_documents SET is_deleted=1 WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* بارگذاری نسخه‌ها و لاگ یک مدرک */
    if ($action === 'get_doc_detail') {
        $docId = (int)($_GET['doc_id'] ?? 0);
        $vers  = $pdo->prepare("SELECT v.*, u1.name prepared_name, u2.name reviewed_name, u3.name approved_name
            FROM doc_versions v
            LEFT JOIN users u1 ON u1.id=v.prepared_by
            LEFT JOIN users u2 ON u2.id=v.reviewed_by
            LEFT JOIN users u3 ON u3.id=v.approved_by
            WHERE v.doc_id=? ORDER BY v.id DESC");
        $vers->execute([$docId]);
        $logs = $pdo->prepare("SELECT l.*, u.name actor_name FROM doc_status_logs l LEFT JOIN users u ON u.id=l.actor_id WHERE l.doc_id=? ORDER BY l.id DESC LIMIT 30");
        $logs->execute([$docId]);
        $dist = $pdo->prepare("SELECT d.*, u.name recipient_name FROM doc_distribution d LEFT JOIN users u ON u.id=d.distributed_to WHERE d.doc_id=? ORDER BY d.id DESC");
        $dist->execute([$docId]);
        echo json_encode(['ok'=>true,'versions'=>$vers->fetchAll(PDO::FETCH_ASSOC),'logs'=>$logs->fetchAll(PDO::FETCH_ASSOC),'distribution'=>$dist->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    /* ثبت توزیع */
    if ($action === 'save_distribution') {
        $docId   = (int)($_POST['doc_id'] ?? 0);
        $vId     = (int)($_POST['version_id'] ?? 0) ?: null;
        $toUser  = (int)($_POST['distributed_to'] ?? 0) ?: null;
        $dept    = trim($_POST['department'] ?? '');
        $method  = $_POST['method'] ?? 'electronic';
        $date    = trim($_POST['distribution_date'] ?? jdate('Y/m/d'));
        $notes   = trim($_POST['notes'] ?? '');
        $s = $pdo->prepare("INSERT INTO doc_distribution (doc_id,version_id,distributed_to,department,distribution_date,method,notes,created_by) VALUES (?,?,?,?,?,?,?,?)");
        $s->execute([$docId,$vId,$toUser,$dept,$date,$method,$notes,$userId]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit;
    }

    /* تأیید دریافت */
    if ($action === 'acknowledge') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE doc_distribution SET acknowledged=1, ack_date=? WHERE id=?")->execute([jdate('Y/m/d'),$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'عملیات نامعتبر']); exit;
}

/* ─── داده صفحه ─── */
$docs = $pdo->query("
    SELECT d.*, u1.name resp_name, u2.name approver_name
    FROM doc_documents d
    LEFT JOIN users u1 ON u1.id=d.responsible_id
    LEFT JOIN users u2 ON u2.id=d.approver_id
    WHERE d.is_deleted=0
    ORDER BY d.status='approved' DESC, d.doc_number
")->fetchAll(PDO::FETCH_ASSOC);

$users = $pdo->query("SELECT id, name, role FROM users WHERE is_deleted=0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$today     = jdate('Y/m/d');
$in30days  = jdate('Y/m/d', strtotime('+30 days'));

$statApproved  = count(array_filter($docs, fn($d) => $d['status']==='approved'));
$statDraft     = count(array_filter($docs, fn($d) => $d['status']==='draft'));
$statReview    = count(array_filter($docs, fn($d) => $d['status']==='review'));
$statDueReview = count(array_filter($docs, fn($d) => $d['next_review_date'] && $d['next_review_date'] <= $in30days && $d['status']==='approved'));

$statusLabels = ['draft'=>'پیش‌نویس','review'=>'در بررسی','approved'=>'تأییدشده','obsolete'=>'منسوخ'];
$statusColors = ['draft'=>'#e0f2fe','review'=>'#fef9c3','approved'=>'#dcfce7','obsolete'=>'#f3f4f6'];
$statusTxtColors = ['draft'=>'#0369a1','review'=>'#854d0e','approved'=>'#15803d','obsolete'=>'#6b7280'];
$catLabels = [
    'quality_system'=>'سیستم کیفیت','procedure'=>'روش اجرایی','work_instruction'=>'دستورالعمل کار',
    'form'=>'فرم','policy'=>'خط‌مشی','specification'=>'مشخصه فنی',
    'regulatory'=>'الزامات قانونی','supplier'=>'تأمین‌کننده','hr'=>'منابع انسانی','other'=>'سایر'
];

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<link rel="stylesheet" href="/assets/css/fin_module.css">
<style>
.doc-status-chip{display:inline-block;padding:3px 12px;border-radius:12px;font-size:12px;font-weight:600}
.version-timeline{list-style:none;padding:0;margin:0}
.version-timeline li{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9}
.version-timeline li:last-child{border-bottom:none}
.v-dot{width:10px;height:10px;border-radius:50%;background:#2563eb;margin-top:5px;flex-shrink:0}
.v-dot.approved{background:#16a34a}
.v-dot.superseded{background:#9ca3af}
.v-dot.review{background:#d97706}
.audit-row{font-size:12px;padding:6px 0;border-bottom:1px solid #f1f5f9;display:flex;gap:12px}
.audit-row:last-child{border-bottom:none}
.version-actions{display:flex;gap:6px;margin-top:6px}
</style>

<div class="fin-page-wrap" dir="rtl">
<div class="fin-page-header">
  <h1 class="fin-page-title">کنترل مدارک — ISO 13485 §4.2.4</h1>
  <button class="fin-btn fin-btn-primary" onclick="openDrawer('Doc')">+ مدرک جدید</button>
</div>

<!-- آمار -->
<div class="fin-stats-row">
  <div class="fin-stat-card green"><div class="fin-stat-value"><?= $statApproved ?></div><div class="fin-stat-label">تأییدشده و فعال</div></div>
  <div class="fin-stat-card blue"><div class="fin-stat-value"><?= $statDraft ?></div><div class="fin-stat-label">پیش‌نویس</div></div>
  <div class="fin-stat-card amber"><div class="fin-stat-value"><?= $statReview ?></div><div class="fin-stat-label">در بررسی</div></div>
  <div class="fin-stat-card rose"><div class="fin-stat-value"><?= $statDueReview ?></div><div class="fin-stat-label">نیاز به بازنگری (۳۰ روز)</div></div>
</div>

<div class="fin-panel">
<div class="fin-tabs">
  <button class="fin-tab active" data-tab="tab-docs">فهرست مدارک</button>
  <button class="fin-tab" data-tab="tab-review-due">نیاز به بازنگری</button>
</div>

<!-- فهرست -->
<div id="tab-docs" class="fin-tab-content active" style="padding:16px">
  <!-- فیلتر -->
  <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap">
    <select id="filterStatus" class="fin-input" style="width:150px" onchange="filterDocs()">
      <option value="">همه وضعیت‌ها</option>
      <option value="draft">پیش‌نویس</option><option value="review">در بررسی</option>
      <option value="approved">تأییدشده</option><option value="obsolete">منسوخ</option>
    </select>
    <select id="filterCat" class="fin-input" style="width:180px" onchange="filterDocs()">
      <option value="">همه دسته‌ها</option>
      <?php foreach ($catLabels as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
    </select>
    <input type="text" id="filterSearch" class="fin-input" style="width:220px" placeholder="جستجو عنوان / شماره..." oninput="filterDocs()">
  </div>
  <table class="fin-table" id="docsTable">
    <thead><tr><th>شماره مدرک</th><th>عنوان</th><th>دسته</th><th>نوع</th><th>نسخه</th><th>وضعیت</th><th>بازنگری بعدی</th><th>مسئول</th><th>عملیات</th></tr></thead>
    <tbody>
    <?php foreach ($docs as $doc):
      $dueReview = $doc['next_review_date'] && $doc['next_review_date'] <= $in30days && $doc['status']==='approved';
      $overdue   = $doc['next_review_date'] && $doc['next_review_date'] < $today && $doc['status']==='approved';
    ?>
    <tr data-status="<?= $doc['status'] ?>" data-cat="<?= $doc['category'] ?>" data-search="<?= strtolower(htmlspecialchars($doc['doc_number'].' '.$doc['title'])) ?>">
      <td><code><?= htmlspecialchars($doc['doc_number']) ?></code></td>
      <td>
        <strong><?= htmlspecialchars($doc['title']) ?></strong>
        <?php if ($doc['is_external']): ?><span class="fin-badge" style="background:#f3e8ff;color:#7e22ce;font-size:11px">خارجی</span><?php endif; ?>
      </td>
      <td><span class="fin-badge"><?= $catLabels[$doc['category']] ?? $doc['category'] ?></span></td>
      <td><?= $doc['is_external'] ? 'خارجی' : 'داخلی' ?></td>
      <td><strong style="color:#2563eb">v<?= htmlspecialchars($doc['current_version']) ?></strong></td>
      <td>
        <span class="doc-status-chip" style="background:<?= $statusColors[$doc['status']] ?>;color:<?= $statusTxtColors[$doc['status']] ?>">
          <?= $statusLabels[$doc['status']] ?>
        </span>
      </td>
      <td style="<?= $overdue ? 'color:#dc2626;font-weight:600' : ($dueReview ? 'color:#d97706;font-weight:600' : '') ?>">
        <?= $doc['next_review_date'] ?: '—' ?>
        <?php if ($overdue): ?> ⚠️<?php elseif ($dueReview): ?> ⏰<?php endif; ?>
      </td>
      <td><?= htmlspecialchars($doc['resp_name'] ?: '—') ?></td>
      <td style="white-space:nowrap">
        <button class="fin-btn fin-btn-xs fin-btn-secondary" onclick="showDetail(<?= $doc['id'] ?>, '<?= htmlspecialchars(addslashes($doc['title'])) ?>')">جزئیات</button>
        <button class="fin-btn fin-btn-xs" style="background:#e0f2fe;color:#0369a1" onclick='editDoc(<?= json_encode($doc) ?>)'>ویرایش</button>
        <?php if ($doc['status'] !== 'obsolete'): ?>
        <button class="fin-btn fin-btn-xs" style="background:#fef2f2;color:#dc2626" onclick="obsoleteDoc(<?= $doc['id'] ?>)">منسوخ</button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$docs): ?><tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:32px">مدرکی ثبت نشده</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<!-- نیاز به بازنگری -->
<div id="tab-review-due" class="fin-tab-content" style="padding:16px">
<table class="fin-table">
  <thead><tr><th>شماره</th><th>عنوان</th><th>نسخه جاری</th><th>تاریخ بازنگری</th><th>وضعیت</th><th>مسئول</th><th>عملیات</th></tr></thead>
  <tbody>
  <?php
  $dueDocs = array_filter($docs, fn($d) => $d['next_review_date'] && $d['next_review_date'] <= $in30days && $d['status']==='approved');
  foreach ($dueDocs as $doc):
    $overdue = $doc['next_review_date'] < $today;
  ?>
  <tr>
    <td><code><?= $doc['doc_number'] ?></code></td>
    <td><?= htmlspecialchars($doc['title']) ?></td>
    <td>v<?= $doc['current_version'] ?></td>
    <td style="color:<?= $overdue ? '#dc2626' : '#d97706' ?>;font-weight:600">
      <?= $doc['next_review_date'] ?> <?= $overdue ? '⚠️ گذشته' : '⏰ نزدیک' ?>
    </td>
    <td><span class="doc-status-chip" style="background:<?= $statusColors[$doc['status']] ?>;color:<?= $statusTxtColors[$doc['status']] ?>"><?= $statusLabels[$doc['status']] ?></span></td>
    <td><?= htmlspecialchars($doc['resp_name'] ?: '—') ?></td>
    <td><button class="fin-btn fin-btn-xs fin-btn-secondary" onclick="showDetail(<?= $doc['id'] ?>, '<?= htmlspecialchars(addslashes($doc['title'])) ?>')">شروع بازنگری</button></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$dueDocs): ?><tr><td colspan="7" style="text-align:center;color:#6b7280;padding:40px">همه مدارک در موعد مقرر هستند ✅</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
</div>

<!-- ─── Modal جزئیات مدرک ─── -->
<div id="modalDetail" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;overflow-y:auto" dir="rtl">
<div style="background:#fff;max-width:860px;margin:40px auto;border-radius:12px;overflow:hidden">
  <div style="background:#1e293b;color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center">
    <h3 id="modalDocTitle" style="margin:0;font-size:16px"></h3>
    <button onclick="closeModal()" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer">✕</button>
  </div>
  <div style="padding:20px">
    <!-- تب‌های مودال -->
    <div class="fin-tabs" id="modalTabs">
      <button class="fin-tab active" data-tab="mtab-versions">نسخه‌ها</button>
      <button class="fin-tab" data-tab="mtab-upload">نسخه جدید</button>
      <button class="fin-tab" data-tab="mtab-dist">توزیع</button>
      <button class="fin-tab" data-tab="mtab-log">لاگ تغییرات</button>
    </div>
    <div style="margin-top:14px">

    <!-- نسخه‌ها -->
    <div id="mtab-versions" class="fin-tab-content active">
      <div id="versionsContent" style="min-height:100px"><div style="text-align:center;color:#9ca3af;padding:30px">در حال بارگذاری...</div></div>
    </div>

    <!-- آپلود نسخه جدید -->
    <div id="mtab-upload" class="fin-tab-content">
      <form id="formVersion" onsubmit="submitVersion(event)" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_version">
        <input type="hidden" name="doc_id" id="v_doc_id" value="">
        <div class="fin-form-row">
          <div class="fin-form-group"><label>شماره نسخه *</label><input type="text" name="version_number" id="v_ver" class="fin-input" placeholder="مثلا: 1.1 یا 2.0" required></div>
          <div class="fin-form-group"><label>تاریخ لازم‌الاجرا</label><input type="text" name="effective_date" id="v_eff" class="fin-input" placeholder="۱۴۰۳/۰۱/۰۱"></div>
        </div>
        <div class="fin-form-group"><label>خلاصه تغییرات</label><textarea name="change_summary" class="fin-input" rows="3" placeholder="چه تغییراتی نسبت به نسخه قبل اعمال شده..."></textarea></div>
        <div class="fin-form-group">
          <label>فایل مدرک (PDF، Word، Excel، تصویر)</label>
          <input type="file" name="doc_file" id="v_file" class="fin-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
        </div>
        <button type="submit" class="fin-btn fin-btn-primary">ثبت نسخه جدید</button>
      </form>
    </div>

    <!-- توزیع -->
    <div id="mtab-dist" class="fin-tab-content">
      <div id="distContent" style="min-height:80px"></div>
      <hr style="margin:16px 0">
      <form id="formDist" onsubmit="submitDist(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_distribution">
        <input type="hidden" name="doc_id" id="d_doc_id" value="">
        <input type="hidden" name="version_id" id="d_ver_id" value="">
        <div class="fin-form-row">
          <div class="fin-form-group"><label>گیرنده</label>
            <select name="distributed_to" class="fin-input">
              <option value="">انتخاب کنید</option>
              <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="fin-form-group"><label>دپارتمان</label><input type="text" name="department" class="fin-input" placeholder="اختیاری"></div>
        </div>
        <div class="fin-form-row">
          <div class="fin-form-group"><label>روش توزیع</label>
            <select name="method" class="fin-input">
              <option value="electronic">الکترونیک</option><option value="print">چاپ</option><option value="email">ایمیل</option>
            </select>
          </div>
          <div class="fin-form-group"><label>تاریخ</label><input type="text" name="distribution_date" class="fin-input" value="<?= $today ?>"></div>
        </div>
        <button type="submit" class="fin-btn fin-btn-primary">ثبت توزیع</button>
      </form>
    </div>

    <!-- لاگ -->
    <div id="mtab-log" class="fin-tab-content">
      <div id="logContent" style="min-height:80px"></div>
    </div>

    </div>
  </div>
</div>
</div>

<!-- ─── Drawer مدرک ─── -->
<div class="fin-drawer-overlay" id="drawerDoc">
<div class="fin-drawer">
  <div class="fin-drawer-header"><h3 id="docDrawerTitle">مدرک جدید</h3><button onclick="closeDrawer('Doc')">✕</button></div>
  <div class="fin-drawer-body">
    <form id="formDoc" onsubmit="submitDoc(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_document">
      <input type="hidden" name="id" id="doc_id" value="0">
      <div class="fin-form-group"><label>عنوان مدرک *</label><input type="text" name="title" id="doc_title" class="fin-input" required></div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>دسته</label>
          <select name="category" id="doc_cat" class="fin-input">
            <?php foreach ($catLabels as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="fin-form-group"><label>مسئول مدرک</label>
          <select name="responsible_id" id="doc_resp" class="fin-input">
            <option value="">—</option>
            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>تأییدکننده</label>
          <select name="approver_id" id="doc_approver" class="fin-input">
            <option value="">—</option>
            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="fin-form-group"><label>تاریخ لازم‌الاجرا</label><input type="text" name="effective_date" id="doc_eff" class="fin-input" placeholder="۱۴۰۳/۰۱/۰۱"></div>
      </div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>تاریخ بازنگری بعدی</label><input type="text" name="next_review_date" id="doc_review" class="fin-input" placeholder="۱۴۰۴/۰۱/۰۱"></div>
        <div class="fin-form-group"><label>محل نگهداری</label><input type="text" name="storage_location" id="doc_storage" class="fin-input" placeholder="مثلا: کمد A-3"></div>
      </div>
      <div class="fin-form-group"><label><input type="checkbox" name="is_external" id="doc_ext"> مدرک خارجی (استاندارد، قانون، ...)</label></div>
      <div class="fin-form-group"><label>توضیحات</label><textarea name="description" id="doc_desc" class="fin-input" rows="2"></textarea></div>
      <div class="fin-drawer-footer"><button type="submit" class="fin-btn fin-btn-primary">ذخیره</button><button type="button" class="fin-btn fin-btn-secondary" onclick="closeDrawer('Doc')">انصراف</button></div>
    </form>
  </div>
</div>
</div>

</div>

<script>
let currentDocId = null;

/* تب‌های اصلی */
document.querySelectorAll('#mainTabs .fin-tab, #tab-docs .fin-tabs .fin-tab, .fin-panel > .fin-tabs .fin-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        const parent = btn.closest('.fin-tabs').parentElement;
        parent.querySelectorAll('.fin-tab,.fin-tab-content').forEach(el => el.classList.remove('active'));
        btn.classList.add('active');
        const tc = parent.querySelector('#'+btn.dataset.tab);
        if (tc) tc.classList.add('active');
    });
});
/* تب‌های مودال */
document.querySelectorAll('#modalTabs .fin-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('#modalTabs .fin-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('#modalDetail .fin-tab-content').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

function openDrawer(n) { document.getElementById('drawer'+n).classList.add('open'); }
function closeDrawer(n) { document.getElementById('drawer'+n).classList.remove('open'); }

function editDoc(d) {
    document.getElementById('doc_id').value      = d.id;
    document.getElementById('doc_title').value   = d.title;
    document.getElementById('doc_cat').value     = d.category;
    document.getElementById('doc_resp').value    = d.responsible_id || '';
    document.getElementById('doc_approver').value= d.approver_id || '';
    document.getElementById('doc_eff').value     = d.effective_date || '';
    document.getElementById('doc_review').value  = d.next_review_date || '';
    document.getElementById('doc_storage').value = d.storage_location || '';
    document.getElementById('doc_ext').checked   = !!parseInt(d.is_external);
    document.getElementById('doc_desc').value    = d.description || '';
    document.getElementById('docDrawerTitle').textContent = 'ویرایش: ' + d.title.substring(0,40);
    openDrawer('Doc');
}

function submitDoc(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    if (!fd.get('is_external')) fd.delete('is_external');
    $.ajax({url:'', method:'POST', data: Object.fromEntries(fd),
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){location.reload();}else{alert(r.msg);} }
    });
}

function showDetail(docId, title) {
    currentDocId = docId;
    document.getElementById('v_doc_id').value = docId;
    document.getElementById('d_doc_id').value = docId;
    document.getElementById('modalDocTitle').textContent = title;
    document.getElementById('modalDetail').style.display = 'block';
    /* reset tabs */
    document.querySelectorAll('#modalTabs .fin-tab').forEach((b,i) => b.classList.toggle('active',i===0));
    document.querySelectorAll('#modalDetail .fin-tab-content').forEach((t,i) => t.classList.toggle('active',i===0));
    loadDetail(docId);
}
function closeModal() { document.getElementById('modalDetail').style.display='none'; }

function loadDetail(docId) {
    $.ajax({url:'?action=get_doc_detail&doc_id='+docId,
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => {
            if (!r.ok) return;
            renderVersions(r.versions);
            renderLog(r.logs);
            renderDist(r.distribution, r.versions);
        }
    });
}

function renderVersions(vers) {
    if (!vers.length) { document.getElementById('versionsContent').innerHTML='<p style="color:#9ca3af;text-align:center;padding:20px">نسخه‌ای ثبت نشده — از تب «نسخه جدید» شروع کنید</p>'; return; }
    const colors = {draft:'#e0f2fe',review:'#fef9c3',approved:'#dcfce7',superseded:'#f3f4f6'};
    const labels = {draft:'پیش‌نویس',review:'در بررسی',approved:'تأییدشده',superseded:'جایگزین‌شده'};
    let html = '<ul class="version-timeline">';
    vers.forEach(v => {
        const c = colors[v.status]||'#f3f4f6';
        const l = labels[v.status]||v.status;
        const dotCls = v.status === 'approved' ? 'approved' : v.status === 'superseded' ? 'superseded' : v.status === 'review' ? 'review' : '';
        html += `<li><div class="v-dot ${dotCls}"></div><div style="flex:1">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <strong>نسخه ${v.version_number}</strong>
                <span style="background:${c};padding:2px 10px;border-radius:10px;font-size:11px">${l}</span>
            </div>`;
        if (v.change_summary) html += `<div style="font-size:13px;color:#374151;margin-top:4px">${v.change_summary}</div>`;
        html += `<div style="font-size:12px;color:#6b7280;margin-top:4px">
            تهیه: ${v.prepared_name||'—'} | بررسی: ${v.reviewed_name||'—'} | تأیید: ${v.approved_name||'—'}`;
        if (v.effective_date) html += ` | لازم‌الاجرا: ${v.effective_date}`;
        html += `</div>`;
        if (v.file_path) html += `<div style="margin-top:6px"><a href="/${v.file_path}" target="_blank" class="fin-btn fin-btn-xs" style="background:#e0f2fe;color:#0369a1">📄 دانلود فایل</a></div>`;
        const nextStatus = {draft:'بررسی',review:'تأیید نهایی'};
        if (nextStatus[v.status]) {
            html += `<div class="version-actions">
                <button class="fin-btn fin-btn-xs fin-btn-primary" onclick="advanceVersion(${currentDocId}, ${v.id}, '${nextStatus[v.status]}')">▶ ${nextStatus[v.status]}</button>
                <input type="text" id="vnotes_${v.id}" class="fin-input" style="width:200px;height:30px;font-size:12px" placeholder="یادداشت (اختیاری)">
                <input type="hidden" id="d_ver_id_v${v.id}" value="${v.id}">
            </div>`;
        }
        html += `</div></li>`;
    });
    html += '</ul>';
    document.getElementById('versionsContent').innerHTML = html;
    /* v_doc_id برای توزیع — از جدیدترین نسخه approved */
    const approved = vers.find(v => v.status==='approved');
    if (approved) { document.getElementById('d_ver_id').value = approved.id; }
}

function renderLog(logs) {
    if (!logs.length) { document.getElementById('logContent').innerHTML='<p style="color:#9ca3af;text-align:center;padding:20px">لاگی ثبت نشده</p>'; return; }
    let html = '<div>';
    logs.forEach(l => {
        html += `<div class="audit-row">
            <span style="color:#9ca3af">${l.created_at}</span>
            <span>${l.actor_name||'سیستم'}</span>
            <span>${l.from_status||'—'} → <strong>${l.to_status}</strong></span>
            <span style="color:#6b7280">${l.notes||''}</span>
        </div>`;
    });
    html += '</div>';
    document.getElementById('logContent').innerHTML = html;
}

function renderDist(dist, vers) {
    if (!dist.length) { document.getElementById('distContent').innerHTML='<p style="color:#9ca3af;text-align:center;padding:16px">توزیعی ثبت نشده</p>'; return; }
    let html = '<table class="fin-table" style="font-size:12px"><thead><tr><th>گیرنده</th><th>دپارتمان</th><th>روش</th><th>تاریخ</th><th>تأیید دریافت</th></tr></thead><tbody>';
    dist.forEach(d => {
        const ack = d.acknowledged ? `<span style="color:#16a34a">✅ ${d.ack_date||''}</span>` : `<button class="fin-btn fin-btn-xs" onclick="acknowledge(${d.id})">تأیید</button>`;
        html += `<tr><td>${d.recipient_name||'—'}</td><td>${d.department||'—'}</td><td>${d.method}</td><td>${d.distribution_date||'—'}</td><td>${ack}</td></tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('distContent').innerHTML = html;
}

function advanceVersion(docId, vId, label) {
    const notes = document.getElementById('vnotes_'+vId)?.value || '';
    if (!confirm('ارسال به مرحله «' + label + '»؟')) return;
    $.ajax({url:'', method:'POST',
        data:{action:'advance_version_status',doc_id:docId,version_id:vId,notes:notes,_csrf:$('[name=_csrf]').first().val()},
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok) { loadDetail(docId); if(r.new_status==='approved') location.reload(); } else { alert(r.msg||'خطا'); } }
    });
}

function submitVersion(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    $.ajax({url:'', method:'POST', data:fd, processData:false, contentType:false,
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){loadDetail(currentDocId); document.getElementById('formVersion').reset(); document.getElementById('v_doc_id').value=currentDocId; alert('نسخه ثبت شد');}else{alert(r.msg);} }
    });
}

function submitDist(e) {
    e.preventDefault();
    $.ajax({url:'', method:'POST', data: $(e.target).serialize(),
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){loadDetail(currentDocId); alert('توزیع ثبت شد');}else{alert(r.msg);} }
    });
}

function acknowledge(distId) {
    $.ajax({url:'', method:'POST',
        data:{action:'acknowledge',id:distId,_csrf:$('[name=_csrf]').first().val()},
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok) loadDetail(currentDocId); }
    });
}

function obsoleteDoc(id) {
    const notes = prompt('یادداشت برای منسوخ کردن مدرک:','');
    if (notes === null) return;
    $.ajax({url:'', method:'POST',
        data:{action:'obsolete_document',doc_id:id,notes:notes,_csrf:$('[name=_csrf]').first().val()},
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok) location.reload(); }
    });
}

function filterDocs() {
    const status  = document.getElementById('filterStatus').value;
    const cat     = document.getElementById('filterCat').value;
    const search  = document.getElementById('filterSearch').value.toLowerCase();
    document.querySelectorAll('#docsTable tbody tr').forEach(row => {
        const s = !status || row.dataset.status === status;
        const c = !cat    || row.dataset.cat    === cat;
        const q = !search || row.dataset.search.includes(search);
        row.style.display = (s && c && q) ? '' : 'none';
    });
}

/* بستن modal با کلیک بیرون */
document.getElementById('modalDetail').addEventListener('click', e => {
    if (e.target === document.getElementById('modalDetail')) closeModal();
});
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
