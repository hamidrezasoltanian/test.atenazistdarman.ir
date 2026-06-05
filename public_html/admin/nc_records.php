<?php
/*
 * کنترل محصول نامنطبق (Nonconforming Product) — ISO 13485 §8.3
 * شناسایی → قرنطینه → تعیین تکلیف → بستن پرونده
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS nc_records (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nc_number VARCHAR(30) NOT NULL UNIQUE,
        source ENUM('incoming_inspection','in_process','customer_return','audit_finding','inventory_check','expired','other') NOT NULL DEFAULT 'incoming_inspection',
        stuff_id INT UNSIGNED DEFAULT NULL, batch_number VARCHAR(50) DEFAULT NULL,
        serial_number VARCHAR(100) DEFAULT NULL, storeroom_id INT UNSIGNED DEFAULT NULL,
        quantity DECIMAL(12,3) DEFAULT 0, unit VARCHAR(20) DEFAULT NULL,
        description TEXT NOT NULL, detection_date VARCHAR(12) DEFAULT NULL,
        detected_by INT UNSIGNED DEFAULT NULL,
        severity ENUM('critical','major','minor') NOT NULL DEFAULT 'major',
        disposition ENUM('under_review','quarantine','rework','return_to_supplier','scrap','use_as_is','concession') NOT NULL DEFAULT 'under_review',
        disposition_notes TEXT DEFAULT NULL, disposition_by INT UNSIGNED DEFAULT NULL,
        disposition_date VARCHAR(12) DEFAULT NULL, capa_id INT UNSIGNED DEFAULT NULL,
        customer_notified TINYINT(1) DEFAULT 0, customer_notification_date VARCHAR(12) DEFAULT NULL,
        regulatory_notification TINYINT(1) DEFAULT 0,
        status ENUM('open','quarantined','in_disposition','closed','cancelled') NOT NULL DEFAULT 'open',
        closed_by INT UNSIGNED DEFAULT NULL, closed_at TIMESTAMP NULL DEFAULT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS nc_status_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nc_id INT UNSIGNED NOT NULL, from_status VARCHAR(30) DEFAULT NULL,
        to_status VARCHAR(30) NOT NULL, actor_id INT UNSIGNED DEFAULT NULL,
        notes TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("ALTER TABLE inv_batch_stock ADD COLUMN IF NOT EXISTS is_quarantined TINYINT(1) DEFAULT 0");
    $pdo->exec("ALTER TABLE inv_batch_stock ADD COLUMN IF NOT EXISTS nc_id INT UNSIGNED DEFAULT NULL");
} catch (Throwable $ignored) {}

$sourceLabels = [
    'incoming_inspection' => 'بازرسی ورودی',
    'in_process'          => 'حین فرآیند',
    'customer_return'     => 'مرجوعی مشتری',
    'audit_finding'       => 'یافته ممیزی',
    'inventory_check'     => 'بازرسی انبار',
    'expired'             => 'منقضی‌شده',
    'other'               => 'سایر',
];
$dispositionLabels = [
    'under_review'       => 'در حال بررسی',
    'quarantine'         => '🔒 قرنطینه',
    'rework'             => '🔧 بازکاری',
    'return_to_supplier' => '↩ برگشت به تأمین‌کننده',
    'scrap'              => '🗑 اسقاط/امحاء',
    'use_as_is'          => '✅ استفاده با انحراف',
    'concession'         => '📋 ارفاق (Concession)',
];
$statusLabels = [
    'open'            => 'باز',
    'quarantined'     => 'قرنطینه',
    'in_disposition'  => 'در تعیین تکلیف',
    'closed'          => 'بسته',
    'cancelled'       => 'لغو شده',
];
$statusColors = [
    'open' => 'rose', 'quarantined' => 'amber',
    'in_disposition' => 'blue', 'closed' => 'green', 'cancelled' => 'gray',
];
$severityColors = ['critical' => 'rose', 'major' => 'amber', 'minor' => 'blue'];
$sevLabels      = ['critical' => 'بحرانی', 'major' => 'اصلی', 'minor' => 'جزئی'];

// ── AJAX ──
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_nc') {
        csrf_verify();
        $id          = (int)($_POST['id'] ?? 0);
        $source      = $_POST['source'] ?? 'incoming_inspection';
        $stuffId     = (int)($_POST['stuff_id'] ?? 0) ?: null;
        $batch       = trim($_POST['batch_number'] ?? '') ?: null;
        $serial      = trim($_POST['serial_number'] ?? '') ?: null;
        $storeroomId = (int)($_POST['storeroom_id'] ?? 0) ?: null;
        $qty         = (float)($_POST['quantity'] ?? 0);
        $unit        = trim($_POST['unit'] ?? '');
        $desc        = trim($_POST['description'] ?? '');
        $detDate     = trim($_POST['detection_date'] ?? '') ?: jdate('Y/m/d');
        $severity    = in_array($_POST['severity'] ?? '', ['critical','major','minor'], true) ? $_POST['severity'] : 'major';

        if (!$desc) { echo json_encode(['ok'=>false,'msg'=>'شرح عدم انطباق الزامی است']); exit; }

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE nc_records SET source=?,stuff_id=?,batch_number=?,serial_number=?,
                    storeroom_id=?,quantity=?,unit=?,description=?,detection_date=?,severity=?,updated_at=NOW()
                    WHERE id=? AND is_deleted=0")
                    ->execute([$source,$stuffId,$batch,$serial,$storeroomId,$qty,$unit,$desc,$detDate,$severity,$id]);
                echo json_encode(['ok'=>true,'msg'=>'رکورد بروزرسانی شد']);
            } else {
                $yymm  = jdate('Ym');
                $count = (int)$pdo->query("SELECT COUNT(*)+1 FROM nc_records WHERE nc_number LIKE 'NC-$yymm-%'")->fetchColumn();
                $num   = 'NC-' . $yymm . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO nc_records (nc_number,source,stuff_id,batch_number,serial_number,
                    storeroom_id,quantity,unit,description,detection_date,detected_by,severity,status,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$num,$source,$stuffId,$batch,$serial,$storeroomId,$qty,$unit,$desc,$detDate,$userId,$severity,'open',$userId]);
                $newId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO nc_status_logs (nc_id,from_status,to_status,actor_id,notes) VALUES (?,NULL,'open',?,?)")
                    ->execute([$newId,$userId,'ثبت اولیه']);
                logActivity($pdo,$userId,'nc_created',"عدم انطباق $num ثبت شد");
                echo json_encode(['ok'=>true,'msg'=>"رکورد $num ثبت شد",'id'=>$newId,'number'=>$num]);
            }
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'set_disposition') {
        csrf_verify();
        $id          = (int)($_POST['id'] ?? 0);
        $disposition = $_POST['disposition'] ?? 'under_review';
        $notes       = trim($_POST['disposition_notes'] ?? '');
        if (!array_key_exists($disposition, $dispositionLabels)) { echo json_encode(['ok'=>false,'msg'=>'تعیین تکلیف نامعتبر']); exit; }
        $newStatus = $disposition === 'quarantine' ? 'quarantined' : 'in_disposition';
        $r = $pdo->prepare("SELECT status, stuff_id, batch_number, storeroom_id FROM nc_records WHERE id=? AND is_deleted=0");
        $r->execute([$id]); $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'رکورد یافت نشد']); exit; }

        $pdo->prepare("UPDATE nc_records SET disposition=?,disposition_notes=?,disposition_by=?,
            disposition_date=?,status=?,updated_at=NOW() WHERE id=?")
            ->execute([$disposition,$notes,$userId,jdate('Y/m/d'),$newStatus,$id]);
        $pdo->prepare("INSERT INTO nc_status_logs (nc_id,from_status,to_status,actor_id,notes) VALUES (?,?,?,?,?)")
            ->execute([$id,$row['status'],$newStatus,$userId,"تعیین تکلیف: {$dispositionLabels[$disposition]}"]);

        // قرنطینه کردن batch در انبار
        if ($disposition === 'quarantine' && $row['batch_number'] && $row['storeroom_id']) {
            $pdo->prepare("UPDATE inv_batch_stock SET is_quarantined=1, nc_id=?
                WHERE stuff_id=? AND batch_number=? AND storeroom_id=?")
                ->execute([$id,$row['stuff_id'],$row['batch_number'],$row['storeroom_id']]);
        }
        // آزاد کردن قرنطینه اگر استفاده یا اسقاط
        if (in_array($disposition,['use_as_is','scrap','rework'],true) && $row['batch_number']) {
            $pdo->prepare("UPDATE inv_batch_stock SET is_quarantined=0, nc_id=NULL
                WHERE nc_id=?")->execute([$id]);
        }
        logActivity($pdo,$userId,'nc_disposition',"NC #$id تعیین تکلیف: $disposition");
        echo json_encode(['ok'=>true,'msg'=>'تعیین تکلیف ذخیره شد']);
        exit;
    }

    if ($action === 'close_nc') {
        csrf_verify();
        $id    = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $r = $pdo->prepare("SELECT status FROM nc_records WHERE id=? AND is_deleted=0");
        $r->execute([$id]); $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'یافت نشد']); exit; }
        $pdo->prepare("UPDATE nc_records SET status='closed',closed_by=?,closed_at=NOW(),updated_at=NOW() WHERE id=?")
            ->execute([$userId,$id]);
        $pdo->prepare("INSERT INTO nc_status_logs (nc_id,from_status,to_status,actor_id,notes) VALUES (?,?,?,?,?)")
            ->execute([$id,$row['status'],'closed',$userId,$notes?:'بستن پرونده']);
        $pdo->prepare("UPDATE inv_batch_stock SET is_quarantined=0, nc_id=NULL WHERE nc_id=?")->execute([$id]);
        logActivity($pdo,$userId,'nc_closed',"NC #$id بسته شد");
        echo json_encode(['ok'=>true,'msg'=>'پرونده عدم انطباق بسته شد']);
        exit;
    }

    if ($action === 'link_capa') {
        csrf_verify();
        $id     = (int)($_POST['id'] ?? 0);
        $capaId = (int)($_POST['capa_id'] ?? 0);
        $pdo->prepare("UPDATE nc_records SET capa_id=?, updated_at=NOW() WHERE id=?")->execute([$capaId,$id]);
        echo json_encode(['ok'=>true,'msg'=>'CAPA لینک شد']);
        exit;
    }

    if ($action === 'create_capa_from_nc') {
        csrf_verify();
        $ncId = (int)($_POST['nc_id'] ?? 0);
        $nc   = $pdo->prepare("SELECT * FROM nc_records WHERE id=? AND is_deleted=0");
        $nc->execute([$ncId]); $ncRow = $nc->fetch(PDO::FETCH_ASSOC);
        if (!$ncRow) { echo json_encode(['ok'=>false,'msg'=>'NC یافت نشد']); exit; }
        $yymm  = jdate('Ym');
        $count = (int)$pdo->query("SELECT COUNT(*)+1 FROM capa_requests WHERE capa_number LIKE 'CA-$yymm-%'")->fetchColumn();
        $cnum  = 'CA-' . $yymm . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO capa_requests
            (capa_number,type,source_type,source_id,title,problem_statement,severity,status,created_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$cnum,'corrective','nonconformity',$ncId,
                'CAPA برای NC '.$ncRow['nc_number'],
                $ncRow['description'],$ncRow['severity'],'open',$userId]);
        $capaId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE nc_records SET capa_id=?,updated_at=NOW() WHERE id=?")->execute([$capaId,$ncId]);
        logActivity($pdo,$userId,'capa_from_nc',"CAPA $cnum از NC {$ncRow['nc_number']} ایجاد شد");
        echo json_encode(['ok'=>true,'msg'=>"CAPA $cnum ایجاد شد",'capa_id'=>$capaId]);
        exit;
    }

    if ($action === 'get_nc') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT n.*, s.stuff_name, s.stuff_code,
            sr.name AS storeroom_name, u.full_name AS detector_name,
            db.full_name AS disposer_name, c.capa_number
            FROM nc_records n
            LEFT JOIN stuffs s ON s.id=n.stuff_id
            LEFT JOIN inv_storerooms sr ON sr.id=n.storeroom_id
            LEFT JOIN users u ON u.id=n.detected_by
            LEFT JOIN users db ON db.id=n.disposition_by
            LEFT JOIN capa_requests c ON c.id=n.capa_id
            WHERE n.id=? AND n.is_deleted=0");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $logs = $pdo->prepare("SELECT l.*,u.full_name AS actor_name FROM nc_status_logs l LEFT JOIN users u ON u.id=l.actor_id WHERE l.nc_id=? ORDER BY l.id");
            $logs->execute([$id]);
            $row['logs'] = $logs->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['ok'=>!!$row,'data'=>$row]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'اکشن نامعتبر']);
    exit;
}

// ── لیست ──
$fStatus   = $_GET['status'] ?? '';
$fSeverity = $_GET['severity'] ?? '';
$q         = trim($_GET['q'] ?? '');
$where = ['n.is_deleted=0']; $params = [];
if ($fStatus)   { $where[] = 'n.status=?';   $params[] = $fStatus; }
if ($fSeverity) { $where[] = 'n.severity=?'; $params[] = $fSeverity; }
if ($q) { $where[] = '(n.nc_number LIKE ? OR n.description LIKE ? OR s.stuff_name LIKE ? OR n.batch_number LIKE ?)'; $params = array_merge($params,["%$q%","%$q%","%$q%","%$q%"]); }
$whereSql = implode(' AND ',$where);

$rows = $pdo->prepare("SELECT n.*, s.stuff_name, s.stuff_code,
    sr.name AS storeroom_name, u.full_name AS detector_name, c.capa_number
    FROM nc_records n
    LEFT JOIN stuffs s ON s.id=n.stuff_id
    LEFT JOIN inv_storerooms sr ON sr.id=n.storeroom_id
    LEFT JOIN users u ON u.id=n.detected_by
    LEFT JOIN capa_requests c ON c.id=n.capa_id
    WHERE $whereSql ORDER BY n.created_at DESC LIMIT 100");
$rows->execute($params);
$records = $rows->fetchAll(PDO::FETCH_ASSOC);

$stats = $pdo->query("SELECT COUNT(*) total,
    SUM(status NOT IN ('closed','cancelled')) open_count,
    SUM(severity='critical' AND status NOT IN ('closed','cancelled')) critical_count,
    SUM(disposition='quarantine' OR status='quarantined') quarantine_count
    FROM nc_records WHERE is_deleted=0")->fetch(PDO::FETCH_ASSOC);

$users      = $pdo->query("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$storerooms = $pdo->query("SELECT id, name FROM inv_storerooms WHERE is_deleted=0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$stuff      = $pdo->query("SELECT id, stuff_code, stuff_name FROM stuffs WHERE is_delete=0 ORDER BY stuff_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
$capas      = $pdo->query("SELECT id, capa_number, title FROM capa_requests WHERE is_deleted=0 AND status NOT IN ('closed','cancelled') ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'محصول نامنطبق (NC) — ISO 13485 §8.3';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<div class="main-content">
<div style="max-width:1200px;margin:0 auto;padding:24px 16px;">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <div>
      <span style="font-size:1.3rem;font-weight:700;">⚠️ محصول نامنطبق (NC)</span>
      <span class="fin-badge rose" style="margin-right:8px;font-size:.75rem;">ISO 13485 §8.3</span>
    </div>
    <button onclick="openDrawer()" class="fin-btn fin-btn-primary">➕ ثبت عدم انطباق</button>
  </div>

  <!-- آمار -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px;">
    <div class="fin-stat-card blue"><div class="stat-value"><?= $stats['total'] ?></div><div class="stat-label">کل رکوردها</div></div>
    <div class="fin-stat-card amber"><div class="stat-value"><?= $stats['open_count'] ?></div><div class="stat-label">باز/جاری</div></div>
    <div class="fin-stat-card rose"><div class="stat-value"><?= $stats['critical_count'] ?></div><div class="stat-label">بحرانی باز</div></div>
    <div class="fin-stat-card purple"><div class="stat-value"><?= $stats['quarantine_count'] ?></div><div class="stat-label">🔒 قرنطینه</div></div>
  </div>

  <!-- فیلترها -->
  <form method="GET" class="fin-panel" style="margin-bottom:16px;padding:14px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <input type="text" name="q" class="fin-input" placeholder="جستجو..." value="<?= htmlspecialchars($q) ?>" style="flex:2;min-width:150px;">
      <select name="status" class="fin-input" style="flex:1;min-width:130px;">
        <option value="">همه وضعیت‌ها</option>
        <?php foreach ($statusLabels as $v=>$l): ?><option value="<?= $v ?>" <?= $fStatus===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
      </select>
      <select name="severity" class="fin-input" style="flex:1;min-width:110px;">
        <option value="">همه درجات</option>
        <?php foreach ($sevLabels as $v=>$l): ?><option value="<?= $v ?>" <?= $fSeverity===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="fin-btn fin-btn-primary">🔍</button>
      <a href="nc_records.php" class="fin-btn fin-btn-outline">✖</a>
    </div>
  </form>

  <!-- جدول -->
  <div class="fin-panel" style="padding:0;overflow:hidden;">
    <div style="overflow-x:auto;">
    <table class="fin-table" style="width:100%;min-width:850px;">
      <thead>
        <tr>
          <th>شماره NC</th>
          <th>منبع</th>
          <th>کالا / Lot</th>
          <th>درجه</th>
          <th>تعیین تکلیف</th>
          <th>وضعیت</th>
          <th>CAPA</th>
          <th>تاریخ</th>
          <th>اقدام</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($records)): ?>
        <tr><td colspan="9" style="text-align:center;padding:32px;color:#9ca3af;">هیچ رکوردی یافت نشد</td></tr>
        <?php else: ?>
        <?php foreach ($records as $r): ?>
        <tr style="<?= $r['disposition']==='quarantine'?'background:#fffbeb':'' ?>">
          <td>
            <span style="font-weight:700;font-size:.85rem;"><?= htmlspecialchars($r['nc_number']) ?></span>
            <?php if ($r['status']==='quarantined'): ?><br><span class="fin-badge amber" style="font-size:.7rem;">🔒 قرنطینه</span><?php endif; ?>
          </td>
          <td><span class="fin-badge gray" style="font-size:.75rem;"><?= $sourceLabels[$r['source']] ?? $r['source'] ?></span></td>
          <td style="font-size:.83rem;">
            <?= htmlspecialchars($r['stuff_name'] ?? '—') ?>
            <?php if ($r['stuff_code']): ?><br><span style="color:#6b7280;font-size:.75rem;"><?= htmlspecialchars($r['stuff_code']) ?></span><?php endif; ?>
            <?php if ($r['batch_number']): ?><br><span style="color:#2563eb;font-size:.75rem;">Lot: <?= htmlspecialchars($r['batch_number']) ?></span><?php endif; ?>
          </td>
          <td><span class="fin-badge <?= $severityColors[$r['severity']] ?>"><?= $sevLabels[$r['severity']] ?></span></td>
          <td style="font-size:.82rem;"><?= $dispositionLabels[$r['disposition']] ?? $r['disposition'] ?></td>
          <td><span class="fin-badge <?= $statusColors[$r['status']] ?>"><?= $statusLabels[$r['status']] ?></span></td>
          <td style="font-size:.8rem;">
            <?php if ($r['capa_number']): ?>
              <a href="capa.php" style="color:#2563eb;text-decoration:none;font-size:.78rem;"><?= htmlspecialchars($r['capa_number']) ?></a>
            <?php else: ?>
              <button onclick="createCapa(<?= $r['id'] ?>, '<?= $r['nc_number'] ?>')" class="fin-btn" style="padding:3px 8px;font-size:.72rem;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;">+ CAPA</button>
            <?php endif; ?>
          </td>
          <td style="font-size:.8rem;"><?= $r['detection_date'] ?: jdate('Y/m/d', strtotime($r['created_at'])) ?></td>
          <td style="white-space:nowrap;">
            <button onclick="viewNC(<?= $r['id'] ?>)" class="fin-btn fin-btn-outline" style="padding:4px 10px;font-size:.78rem;">🔍</button>
            <a href="qms_pdf.php?type=nc&id=<?= $r['id'] ?>" target="_blank" class="fin-btn" style="padding:4px 10px;font-size:.78rem;background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff;">PDF</a>
            <?php if (!in_array($r['status'],['closed','cancelled'],true)): ?>
            <button onclick="openDisposition(<?= $r['id'] ?>, '<?= $r['nc_number'] ?>')" class="fin-btn fin-btn-primary" style="padding:4px 10px;font-size:.78rem;">⚖ تعیین تکلیف</button>
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

<!-- Drawer ثبت NC -->
<div class="fin-drawer" id="ncDrawer" style="max-width:600px;">
  <div class="fin-drawer-header">
    <span>⚠️ ثبت عدم انطباق جدید</span>
    <button onclick="closeDrawer()" class="fin-drawer-close">×</button>
  </div>
  <div class="fin-drawer-body">
    <form id="ncForm" onsubmit="saveNC(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_nc">
      <input type="hidden" name="id" value="0">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div>
          <label class="fin-label">منبع کشف <span style="color:red">*</span></label>
          <select name="source" class="fin-input" style="width:100%;">
            <?php foreach ($sourceLabels as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">درجه اهمیت</label>
          <select name="severity" class="fin-input" style="width:100%;">
            <?php foreach ($sevLabels as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">کالا</label>
          <select name="stuff_id" class="fin-input" style="width:100%;">
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($stuff as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['stuff_code'].' — '.$s['stuff_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">انبار قرنطینه</label>
          <select name="storeroom_id" class="fin-input" style="width:100%;">
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($storerooms as $sr): ?><option value="<?= $sr['id'] ?>"><?= htmlspecialchars($sr['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fin-label">شماره Lot/Batch</label>
          <input type="text" name="batch_number" class="fin-input" style="width:100%;direction:ltr;">
        </div>
        <div>
          <label class="fin-label">تعداد/مقدار</label>
          <div style="display:flex;gap:6px;">
            <input type="number" name="quantity" class="fin-input" style="flex:2;" step="0.001" min="0" value="0">
            <input type="text" name="unit" class="fin-input" style="flex:1;" placeholder="عدد">
          </div>
        </div>
        <div>
          <label class="fin-label">تاریخ کشف</label>
          <input type="text" name="detection_date" class="fin-input kama-date" style="width:100%;" value="<?= jdate('Y/m/d') ?>">
        </div>
        <div style="grid-column:1/-1;">
          <label class="fin-label">شرح عدم انطباق <span style="color:red">*</span></label>
          <textarea name="description" class="fin-input" rows="4" style="width:100%;resize:vertical;"
            placeholder="چه مشکلی مشاهده شد؟ کجا؟ چه مقدار تأثیر داشت؟" required></textarea>
        </div>
      </div>
      <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px;margin-top:10px;font-size:.84rem;color:#92400e;">
        ⚠️ پس از ثبت، حتماً «تعیین تکلیف» انجام دهید. کالای نامنطبق باید شناسایی و از سایرین جدا شود.
      </div>
      <button type="submit" class="fin-btn fin-btn-primary" style="width:100%;padding:12px;margin-top:14px;">💾 ثبت عدم انطباق</button>
    </form>
  </div>
</div>
<div class="fin-drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<!-- مودال تعیین تکلیف -->
<div id="dispositionModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;justify-content:center;align-items:center;">
  <div style="background:#fff;border-radius:16px;padding:28px;width:90%;max-width:480px;">
    <h3 id="dispTitle" style="margin:0 0 16px;font-size:1.05rem;color:#0f172a;"></h3>
    <input type="hidden" id="dispNcId">
    <div style="margin-bottom:12px;">
      <label class="fin-label">تعیین تکلیف <span style="color:red">*</span></label>
      <select id="dispValue" class="fin-input" style="width:100%;">
        <?php foreach ($dispositionLabels as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="margin-bottom:16px;">
      <label class="fin-label">توضیحات / دلیل تصمیم</label>
      <textarea id="dispNotes" rows="3" class="fin-input" style="width:100%;resize:vertical;"></textarea>
    </div>
    <div style="display:flex;gap:10px;">
      <button onclick="saveDisposition()" style="flex:1;padding:10px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:inherit;font-weight:600;">⚖ ذخیره تعیین تکلیف</button>
      <button onclick="document.getElementById('dispositionModal').style.display='none'" style="flex:1;padding:10px;border:1px solid #d1d5db;border-radius:8px;cursor:pointer;font-family:inherit;background:#f9fafb;">انصراف</button>
    </div>
  </div>
</div>

<!-- مودال جزئیات -->
<div id="viewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:998;overflow:auto;">
  <div style="background:#fff;border-radius:16px;margin:30px auto;width:90%;max-width:680px;padding:28px;position:relative;">
    <button onclick="document.getElementById('viewModal').style.display='none'" style="position:absolute;top:16px;left:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#9ca3af;">×</button>
    <div id="viewContent"></div>
  </div>
</div>

<script>
const srcMap  = <?= json_encode($sourceLabels) ?>;
const dispMap = <?= json_encode($dispositionLabels) ?>;
const stMap   = <?= json_encode($statusLabels) ?>;
const sevMap  = <?= json_encode($sevLabels) ?>;
const csrf    = '<?= csrf_token() ?>';

function openDrawer(){ document.getElementById('ncDrawer').classList.add('open'); document.getElementById('drawerOverlay').classList.add('open'); }
function closeDrawer(){ document.getElementById('ncDrawer').classList.remove('open'); document.getElementById('drawerOverlay').classList.remove('open'); }

function saveNC(e){
    e.preventDefault();
    const fd=new FormData(e.target), btn=e.target.querySelector('button[type=submit]');
    btn.disabled=true; btn.textContent='⏳ ثبت...';
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{ btn.disabled=false; btn.textContent='💾 ثبت عدم انطباق'; if(d.ok){closeDrawer();location.reload();}else alert('❌ '+d.msg); })
    .catch(()=>{btn.disabled=false;alert('❌ خطای شبکه');});
}

function openDisposition(id, num){
    document.getElementById('dispNcId').value=id;
    document.getElementById('dispTitle').textContent='⚖ تعیین تکلیف برای '+num;
    document.getElementById('dispNotes').value='';
    document.getElementById('dispositionModal').style.display='flex';
}

function saveDisposition(){
    const id=document.getElementById('dispNcId').value;
    const fd=new FormData();
    fd.append('action','set_disposition'); fd.append('id',id);
    fd.append('disposition',document.getElementById('dispValue').value);
    fd.append('disposition_notes',document.getElementById('dispNotes').value);
    fd.append('csrf_token',csrf);
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        document.getElementById('dispositionModal').style.display='none';
        if(d.ok) location.reload(); else alert('❌ '+d.msg);
    });
}

function viewNC(id){
    const fd=new FormData(); fd.append('action','get_nc'); fd.append('id',id);
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(!d.ok||!d.data){alert('خطا');return;}
        const n=d.data;
        const logs=(n.logs||[]).map(l=>`<div style="font-size:.8rem;padding:4px 0;border-bottom:1px dashed #f3f4f6;color:#374151;">
            <span style="color:#9ca3af;">${l.created_at?.substring(0,16)||''}</span>
            — ${escH(l.actor_name||'سیستم')}: ${escH(l.from_status||'—')} → ${escH(l.to_status)}
            ${l.notes?'<em style="color:#6b7280;">('+escH(l.notes)+')</em>':''}</div>`).join('');
        document.getElementById('viewContent').innerHTML=`
            <h3 style="margin:0 0 4px;">${escH(n.nc_number)}</h3>
            <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
                <span class="fin-badge ${{'critical':'rose','major':'amber','minor':'blue'}[n.severity]||'gray'}">${sevMap[n.severity]||n.severity}</span>
                <span class="fin-badge gray">${srcMap[n.source]||n.source}</span>
                <span class="fin-badge ${{'open':'rose','quarantined':'amber','in_disposition':'blue','closed':'green','cancelled':'gray'}[n.status]||'gray'}">${stMap[n.status]||n.status}</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.85rem;margin-bottom:14px;">
                <div><b>کالا:</b> ${escH(n.stuff_name||'—')}</div>
                <div><b>Lot:</b> ${escH(n.batch_number||'—')}</div>
                <div><b>تعداد:</b> ${n.quantity||0} ${escH(n.unit||'')}</div>
                <div><b>انبار:</b> ${escH(n.storeroom_name||'—')}</div>
                <div><b>تعیین تکلیف:</b> ${dispMap[n.disposition]||n.disposition}</div>
                ${n.capa_number?`<div><b>CAPA:</b> ${escH(n.capa_number)}</div>`:''}
            </div>
            <div style="margin-bottom:12px;"><b>شرح:</b><p style="color:#374151;margin:4px 0;line-height:1.7;">${escH(n.description)}</p></div>
            ${n.disposition_notes?`<div style="margin-bottom:12px;background:#f8fafc;padding:10px;border-radius:8px;"><b>توضیح تعیین تکلیف:</b><p style="margin:4px 0;">${escH(n.disposition_notes)}</p></div>`:''}
            <div><b>تاریخچه:</b><div style="margin-top:6px;">${logs||'<span style="color:#9ca3af;font-size:.82rem;">بدون لاگ</span>'}</div></div>
            ${n.status!=='closed'?`<button onclick="closeNC(${n.id})" style="margin-top:14px;width:100%;padding:10px;background:#16a34a;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:inherit;font-size:.9rem;">✅ بستن پرونده NC</button>`:''}
        `;
        document.getElementById('viewModal').style.display='block';
    });
}

function closeNC(id){
    const notes=prompt('یادداشت بستن پرونده (اختیاری):') ?? '';
    const fd=new FormData();
    fd.append('action','close_nc'); fd.append('id',id); fd.append('notes',notes); fd.append('csrf_token',csrf);
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{if(d.ok){document.getElementById('viewModal').style.display='none';location.reload();}else alert('❌ '+d.msg);});
}

function createCapa(id,num){
    if(!confirm('CAPA برای NC '+num+' ایجاد شود؟'))return;
    const fd=new FormData();
    fd.append('action','create_capa_from_nc'); fd.append('nc_id',id); fd.append('csrf_token',csrf);
    fetch(location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{if(d.ok){alert('✅ '+d.msg);location.reload();}else alert('❌ '+d.msg);});
}

function escH(s){return s?String(s).replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])):''}
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
