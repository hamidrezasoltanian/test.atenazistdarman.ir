<?php
// ماژول واردات — آتنا زیست درمان
ob_start();
$root = dirname(__DIR__, 2);
require_once $root . '/Config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';

requireLogin();
$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$basePath = '../../';
$isAdmin  = in_array($userRole, ['admin','management'], true);
$uploadDir = __DIR__ . '/../../public_html/uploads/imports/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

// ── ایجاد جداول ──────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `import_orders` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_number` VARCHAR(30) NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `supplier_name` VARCHAR(255),
        `supplier_country` VARCHAR(100),
        `person_id` INT DEFAULT NULL,
        `currency` VARCHAR(10) DEFAULT 'USD',
        `est_cost` DECIMAL(18,2) DEFAULT 0,
        `notes` TEXT,
        `status` ENUM('active','completed','cancelled') DEFAULT 'active',
        `created_by` INT,
        `created_at` DATETIME DEFAULT NOW(),
        `updated_at` DATETIME DEFAULT NOW() ON UPDATE NOW(),
        `is_deleted` TINYINT(1) DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `import_stages_def` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(200) NOT NULL,
        `description` TEXT,
        `icon` VARCHAR(10) DEFAULT '📋',
        `color` VARCHAR(20) DEFAULT '#3b82f6',
        `sort_order` INT DEFAULT 0,
        `is_system` TINYINT(1) DEFAULT 0,
        `is_active` TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // seed مراحل پیش‌فرض
    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM import_stages_def')->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO import_stages_def (name,icon,color,sort_order,is_system) VALUES
            ('ثبت سفارش خرید','🛒','#3b82f6',1,1),
            ('مراحل اداره کل تجهیزات','🏛','#8b5cf6',2,1),
            ('حمل‌ونقل و بیمه','🚢','#06b6d4',3,1),
            ('ترخیص گمرک','🛃','#f59e0b',4,1),
            ('انبارداری و توزیع','📦','#10b981',5,1)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `import_order_stages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `stage_id` INT NOT NULL,
        `status` ENUM('pending','in_progress','completed','skipped') DEFAULT 'pending',
        `start_date` VARCHAR(12),
        `end_date` VARCHAR(12),
        `notes` TEXT,
        `assigned_to` INT DEFAULT NULL,
        `updated_at` DATETIME DEFAULT NOW() ON UPDATE NOW(),
        UNIQUE KEY uq_order_stage (`order_id`,`stage_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `import_costs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `stage_id` INT DEFAULT NULL,
        `cost_type` VARCHAR(100),
        `description` VARCHAR(500),
        `amount` DECIMAL(18,2) DEFAULT 0,
        `currency` VARCHAR(10) DEFAULT 'IRR',
        `paid_at` VARCHAR(12),
        `receipt_file` VARCHAR(300),
        `created_by` INT,
        `created_at` DATETIME DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `import_documents` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `stage_id` INT DEFAULT NULL,
        `filename` VARCHAR(300),
        `original_name` VARCHAR(300),
        `doc_type` VARCHAR(100),
        `uploaded_by` INT,
        `created_at` DATETIME DEFAULT NOW(),
        `is_deleted` TINYINT(1) DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── AJAX ─────────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'] ?? $_GET['action'] ?? '';

    // کارت‌های KPI
    if ($act === 'kpi') {
        try {
            $active   = (int)$pdo->query("SELECT COUNT(*) FROM import_orders WHERE status='active' AND is_deleted=0")->fetchColumn();
            $done     = (int)$pdo->query("SELECT COUNT(*) FROM import_orders WHERE status='completed' AND is_deleted=0")->fetchColumn();
            $totalCost= (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM import_costs ic JOIN import_orders io ON io.id=ic.order_id WHERE io.is_deleted=0")->fetchColumn();
            $pending  = (int)$pdo->query("SELECT COUNT(DISTINCT order_id) FROM import_order_stages WHERE status='in_progress'")->fetchColumn();
            echo json_encode(['active'=>$active,'done'=>$done,'total_cost'=>$totalCost,'in_progress'=>$pending], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { echo json_encode(['active'=>0,'done'=>0,'total_cost'=>0,'in_progress'=>0]); }
        exit;
    }

    // لیست سفارشات
    if ($act === 'list') {
        try {
            $filterStatus = $_GET['status'] ?? 'active';
            $rows = $pdo->prepare(
                "SELECT io.*, u.name creator_name,
                        COALESCE(SUM(ic.amount),0) actual_cost,
                        (SELECT s.status FROM import_order_stages s WHERE s.order_id=io.id AND s.status='in_progress' ORDER BY s.id LIMIT 1) cur_status,
                        (SELECT d.name FROM import_stages_def d JOIN import_order_stages s ON s.stage_id=d.id WHERE s.order_id=io.id AND s.status='in_progress' ORDER BY d.sort_order LIMIT 1) cur_stage
                 FROM import_orders io
                 LEFT JOIN users u ON u.id=io.created_by
                 LEFT JOIN import_costs ic ON ic.order_id=io.id
                 WHERE io.is_deleted=0 AND io.status=?
                 GROUP BY io.id ORDER BY io.id DESC"
            );
            $rows->execute([$filterStatus]);
            echo json_encode(['data' => $rows->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { echo json_encode(['data'=>[]]); }
        exit;
    }

    // جزئیات یک سفارش
    if ($act === 'detail') {
        $oid = (int)($_GET['id'] ?? 0);
        try {
            $order = $pdo->prepare("SELECT io.*, fp.name person_name FROM import_orders io LEFT JOIN fin_persons fp ON fp.id=io.person_id WHERE io.id=? AND io.is_deleted=0");
            $order->execute([$oid]);
            $o = $order->fetch(PDO::FETCH_ASSOC);
            if (!$o) { echo json_encode(['error'=>'یافت نشد']); exit; }

            // مراحل
            $stages = $pdo->prepare(
                "SELECT d.*, COALESCE(s.status,'pending') st_status, s.start_date, s.end_date, s.notes st_notes, s.assigned_to, u.name assignee_name
                 FROM import_stages_def d
                 LEFT JOIN import_order_stages s ON s.stage_id=d.id AND s.order_id=?
                 LEFT JOIN users u ON u.id=s.assigned_to
                 WHERE d.is_active=1 ORDER BY d.sort_order"
            );
            $stages->execute([$oid]);
            $o['stages'] = $stages->fetchAll(PDO::FETCH_ASSOC);

            // هزینه‌ها
            $costs = $pdo->prepare(
                "SELECT ic.*, d.name stage_name FROM import_costs ic LEFT JOIN import_stages_def d ON d.id=ic.stage_id WHERE ic.order_id=? ORDER BY ic.id DESC"
            );
            $costs->execute([$oid]);
            $o['costs'] = $costs->fetchAll(PDO::FETCH_ASSOC);

            // مدارک
            $docs = $pdo->prepare(
                "SELECT id.*, d.name stage_name FROM import_documents id LEFT JOIN import_stages_def d ON d.id=id.stage_id WHERE id.order_id=? AND id.is_deleted=0 ORDER BY id.id DESC"
            );
            $docs->execute([$oid]);
            $o['documents'] = $docs->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($o, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ایجاد سفارش جدید
    if ($act === 'create_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST["csrf_token"] ?? "")) { echo json_encode(["ok"=>false,"msg"=>"CSRF invalid"]); exit; }
        try {
            $num = 'IMP-' . date('Y') . '-' . str_pad($pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM import_orders")->fetchColumn(), 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("INSERT INTO import_orders (order_number,title,supplier_name,supplier_country,person_id,currency,est_cost,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $num,
                htmlspecialchars(trim($_POST['title'] ?? '')),
                htmlspecialchars(trim($_POST['supplier_name'] ?? '')),
                htmlspecialchars(trim($_POST['supplier_country'] ?? '')),
                !empty($_POST['person_id']) ? (int)$_POST['person_id'] : null,
                $_POST['currency'] ?? 'USD',
                (float)faToEn($_POST['est_cost'] ?? '0'),
                htmlspecialchars(trim($_POST['notes'] ?? '')),
                $userId,
            ]);
            $oid = (int)$pdo->lastInsertId();

            // ایجاد ردیف مرحله برای هر مرحله فعال
            $stageDefs = $pdo->query("SELECT id FROM import_stages_def WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
            $ins = $pdo->prepare("INSERT IGNORE INTO import_order_stages (order_id,stage_id,status) VALUES (?,?,'pending')");
            foreach ($stageDefs as $sid) $ins->execute([$oid, $sid]);

            echo json_encode(['ok'=>true,'id'=>$oid,'order_number'=>$num], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    // آپدیت وضعیت مرحله
    if ($act === 'update_stage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST["csrf_token"] ?? "")) { echo json_encode(["ok"=>false,"msg"=>"CSRF invalid"]); exit; }
        try {
            $oid   = (int)$_POST['order_id'];
            $sid   = (int)$_POST['stage_id'];
            $st    = in_array($_POST['status'] ?? '', ['pending','in_progress','completed','skipped']) ? $_POST['status'] : 'pending';
            $start = trim(faToEn($_POST['start_date'] ?? ''));
            $end   = trim(faToEn($_POST['end_date'] ?? ''));
            $notes = htmlspecialchars(trim($_POST['notes'] ?? ''));

            $pdo->prepare("INSERT INTO import_order_stages (order_id,stage_id,status,start_date,end_date,notes)
                           VALUES (?,?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE status=VALUES(status),start_date=VALUES(start_date),end_date=VALUES(end_date),notes=VALUES(notes)"
            )->execute([$oid,$sid,$st,$start?:null,$end?:null,$notes]);

            // اگر همه مراحل completed بود → سفارش را completed کن
            $ndStmt = $pdo->prepare("SELECT COUNT(*) FROM import_order_stages WHERE order_id=? AND status NOT IN ('completed','skipped')");
            $ndStmt->execute([$oid]);
            if ((int)$ndStmt->fetchColumn() === 0) {
                $pdo->prepare("UPDATE import_orders SET status='completed' WHERE id=?")->execute([$oid]);
            }

            echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    // افزودن هزینه
    if ($act === 'add_cost' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST["csrf_token"] ?? "")) { echo json_encode(["ok"=>false,"msg"=>"CSRF invalid"]); exit; }
        try {
            $receiptFile = null;
            if (!empty($_FILES['receipt']['name'])) {
                $allowed = ['image/jpeg','image/png','application/pdf',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $result = upload_secure_file($_FILES['receipt']['tmp_name'], $_FILES['receipt']['name'], $uploadDir, $allowed, 'rcpt_');
                if (!$result) throw new Exception('نوع فایل مجاز نیست یا آپلود ناموفق');
                $receiptFile = $result['name'];
            }
            $stmt = $pdo->prepare("INSERT INTO import_costs (order_id,stage_id,cost_type,description,amount,currency,paid_at,receipt_file,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                (int)$_POST['order_id'],
                !empty($_POST['stage_id']) ? (int)$_POST['stage_id'] : null,
                htmlspecialchars($_POST['cost_type'] ?? ''),
                htmlspecialchars(trim($_POST['description'] ?? '')),
                (float)faToEn($_POST['amount'] ?? '0'),
                $_POST['currency'] ?? 'IRR',
                trim(faToEn($_POST['paid_at'] ?? '')) ?: null,
                $receiptFile,
                $userId,
            ]);
            echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    // آپلود مدرک
    if ($act === 'upload_doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST["csrf_token"] ?? "")) { echo json_encode(["ok"=>false,"msg"=>"CSRF invalid"]); exit; }
        try {
            if (empty($_FILES['file']['name'])) throw new Exception('فایلی انتخاب نشده');
            $docAllowed = ['image/jpeg','image/png','application/pdf',
                           'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                           'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                           'application/zip','application/x-rar-compressed','application/x-zip-compressed'];
            $docResult = upload_secure_file($_FILES['file']['tmp_name'], $_FILES['file']['name'], $uploadDir, $docAllowed, 'doc_');
            if (!$docResult) throw new Exception('نوع فایل مجاز نیست یا آپلود ناموفق');
            $fn = $docResult['name'];
            $stmt = $pdo->prepare("INSERT INTO import_documents (order_id,stage_id,filename,original_name,doc_type,uploaded_by) VALUES (?,?,?,?,?,?)");
            $stmt->execute([
                (int)$_POST['order_id'],
                !empty($_POST['stage_id']) ? (int)$_POST['stage_id'] : null,
                $fn,
                htmlspecialchars($_FILES['file']['name']),
                htmlspecialchars($_POST['doc_type'] ?? ''),
                $userId,
            ]);
            echo json_encode(['ok'=>true,'filename'=>$fn,'original'=>$_FILES['file']['name']], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    // حذف مدرک
    if ($act === 'delete_doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST["csrf_token"] ?? "")) { echo json_encode(["ok"=>false,"msg"=>"CSRF invalid"]); exit; }
        $pdo->prepare("UPDATE import_documents SET is_deleted=1 WHERE id=?")->execute([(int)$_POST['id']]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // لیست مراحل تعریف‌شده
    if ($act === 'list_stages') {
        $rows = $pdo->query("SELECT * FROM import_stages_def ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ذخیره مرحله جدید یا ویرایش
    if ($act === 'save_stage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST["csrf_token"] ?? "")) { echo json_encode(["ok"=>false,"msg"=>"CSRF invalid"]); exit; }
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'دسترسی ندارید']); exit; }
        $id   = (int)($_POST['id'] ?? 0);
        $name = htmlspecialchars(trim($_POST['name'] ?? ''));
        $icon = htmlspecialchars(trim($_POST['icon'] ?? '📋'));
        $clr  = htmlspecialchars(trim($_POST['color'] ?? '#3b82f6'));
        $desc = htmlspecialchars(trim($_POST['description'] ?? ''));
        $ord  = (int)($_POST['sort_order'] ?? 99);
        if ($id > 0) {
            $pdo->prepare("UPDATE import_stages_def SET name=?,icon=?,color=?,description=?,sort_order=? WHERE id=?")->execute([$name,$icon,$clr,$desc,$ord,$id]);
        } else {
            $pdo->prepare("INSERT INTO import_stages_def (name,icon,color,description,sort_order) VALUES (?,?,?,?,?)")->execute([$name,$icon,$clr,$desc,$ord]);
            $id = (int)$pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // فعال/غیرفعال مرحله
    if ($act === 'toggle_stage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST["csrf_token"] ?? "")) { echo json_encode(["ok"=>false,"msg"=>"CSRF invalid"]); exit; }
        if (!$isAdmin) { echo json_encode(['ok'=>false,'msg'=>'دسترسی ندارید']); exit; }
        $id = (int)$_POST['id'];
        $row = $pdo->prepare("SELECT is_system,is_active FROM import_stages_def WHERE id=?");
        $row->execute([$id]);
        $r = $row->fetch();
        if ($r && $r['is_system'] && $r['is_active']) { echo json_encode(['ok'=>false,'msg'=>'مراحل سیستمی را نمی‌توان غیرفعال کرد']); exit; }
        $pdo->prepare("UPDATE import_stages_def SET is_active=1-is_active WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['error'=>'action نامعتبر'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── HTML ─────────────────────────────────────────────────
$pageTitle = 'ماژول واردات';
$extraCss  = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';

require_once $root . '/templates/header.php';
require_once $root . '/templates/sidebar.php';

// لیست مراحل و طرف حساب‌ها برای فرم‌ها
$stageDefs = $pdo->query("SELECT * FROM import_stages_def WHERE is_active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$persons   = $pdo->query("SELECT id,name FROM fin_persons WHERE is_deleted=0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="content-wrapper" style="min-height:100vh;background:#f0f4f8;">

<!-- ── هدر صفحه ──────────────────────────────────────────── -->
<div style="background:linear-gradient(135deg,#0f172a,#1e40af);color:#fff;padding:20px 28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="margin:0;font-size:1.35rem;font-weight:700;">🚢 ماژول مدیریت واردات</h1>
    <p style="margin:4px 0 0;opacity:.7;font-size:.82rem;">ردیابی سفارشات، مراحل، هزینه‌ها و مدارک</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if ($isAdmin): ?>
    <button onclick="openStagesModal()" style="padding:9px 18px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:10px;cursor:pointer;font-family:inherit;font-size:.84rem;">⚙️ مدیریت مراحل</button>
    <?php endif; ?>
    <button onclick="openNewOrderModal()" style="padding:9px 18px;background:#3b82f6;color:#fff;border:none;border-radius:10px;cursor:pointer;font-family:inherit;font-size:.84rem;font-weight:600;">+ سفارش جدید</button>
  </div>
</div>

<div style="padding:24px 28px;">

<!-- ── KPI ──────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
  <?php
  $kpis = [
    ['id'=>'kpiActive',   'label'=>'سفارش در جریان',    'ico'=>'🚢', 'clr'=>'#3b82f6', 'bg'=>'#eff6ff'],
    ['id'=>'kpiInProg',   'label'=>'مرحله در حال انجام', 'ico'=>'⚙️', 'clr'=>'#f59e0b', 'bg'=>'#fffbeb'],
    ['id'=>'kpiDone',     'label'=>'سفارش تکمیل‌شده',   'ico'=>'✅', 'clr'=>'#10b981', 'bg'=>'#f0fdf4'],
    ['id'=>'kpiCost',     'label'=>'مجموع هزینه‌ها',     'ico'=>'💰', 'clr'=>'#8b5cf6', 'bg'=>'#f5f3ff'],
  ];
  foreach ($kpis as $k): ?>
  <div style="background:#fff;border-radius:14px;padding:18px;box-shadow:0 1px 8px rgba(0,0,0,.06);border-right:4px solid <?= $k['clr'] ?>;display:flex;align-items:center;gap:14px;">
    <div style="width:44px;height:44px;border-radius:12px;background:<?= $k['bg'] ?>;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;"><?= $k['ico'] ?></div>
    <div>
      <div id="<?= $k['id'] ?>" style="font-size:1.4rem;font-weight:700;color:#0f172a;">—</div>
      <div style="font-size:.72rem;color:#94a3b8;margin-top:2px;"><?= $k['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── تب‌های فیلتر ──────────────────────────────────────── -->
<div style="background:#fff;border-radius:16px;box-shadow:0 1px 8px rgba(0,0,0,.06);overflow:hidden;">
  <div style="display:flex;border-bottom:2px solid #f1f5f9;padding:0 20px;gap:4px;">
    <?php foreach ([['active','در جریان'],['completed','تکمیل شده'],['cancelled','لغو شده']] as [$st,$lbl]): ?>
    <button class="imp-tab-btn" data-status="<?= $st ?>" onclick="loadOrders('<?= $st ?>')"
      style="padding:14px 20px;border:none;background:none;cursor:pointer;font-family:inherit;font-size:.84rem;color:#64748b;font-weight:500;border-bottom:2px solid transparent;margin-bottom:-2px;transition:.2s;">
      <?= $lbl ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- لیست سفارشات -->
  <div style="padding:0;">
    <div id="ordersLoading" style="padding:48px;text-align:center;color:#94a3b8;">در حال بارگذاری...</div>
    <div id="ordersList" style="display:none;"></div>
  </div>
</div>

</div><!-- /padding -->
</div><!-- /content-wrapper -->

<!-- ═══ مودال سفارش جدید ════════════════════════════════ -->
<div id="newOrderModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:20px;width:90%;max-width:560px;max-height:90vh;overflow-y:auto;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
      <h3 style="margin:0;font-size:1.1rem;font-weight:700;">🚢 ثبت سفارش واردات جدید</h3>
      <button onclick="closeModal('newOrderModal')" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;">✕</button>
    </div>
    <form id="newOrderForm" onsubmit="submitNewOrder(event)">
      <?= csrf_field() ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div style="grid-column:1/-1;">
          <label style="font-size:.8rem;color:#64748b;font-weight:600;display:block;margin-bottom:4px;">عنوان سفارش *</label>
          <input name="title" required placeholder="مثال: واردات دستگاه‌های پزشکی" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:9px;font-family:inherit;font-size:.9rem;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:.8rem;color:#64748b;font-weight:600;display:block;margin-bottom:4px;">نام تأمین‌کننده</label>
          <input name="supplier_name" placeholder="نام شرکت خارجی" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:9px;font-family:inherit;font-size:.9rem;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:.8rem;color:#64748b;font-weight:600;display:block;margin-bottom:4px;">کشور مبدأ</label>
          <input name="supplier_country" placeholder="مثال: آلمان" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:9px;font-family:inherit;font-size:.9rem;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:.8rem;color:#64748b;font-weight:600;display:block;margin-bottom:4px;">طرف حساب (داخلی)</label>
          <select name="person_id" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:9px;font-family:inherit;font-size:.9rem;box-sizing:border-box;">
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($persons as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:.8rem;color:#64748b;font-weight:600;display:block;margin-bottom:4px;">ارز</label>
          <select name="currency" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:9px;font-family:inherit;font-size:.9rem;box-sizing:border-box;">
            <option value="USD">USD — دلار</option>
            <option value="EUR">EUR — یورو</option>
            <option value="IRR">IRR — ریال</option>
            <option value="CNY">CNY — یوان</option>
          </select>
        </div>
        <div>
          <label style="font-size:.8rem;color:#64748b;font-weight:600;display:block;margin-bottom:4px;">هزینه تخمینی</label>
          <input name="est_cost" placeholder="۰" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:9px;font-family:inherit;font-size:.9rem;box-sizing:border-box;">
        </div>
        <div style="grid-column:1/-1;">
          <label style="font-size:.8rem;color:#64748b;font-weight:600;display:block;margin-bottom:4px;">توضیحات</label>
          <textarea name="notes" rows="3" placeholder="جزئیات سفارش..." style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:9px;font-family:inherit;font-size:.9rem;resize:vertical;box-sizing:border-box;"></textarea>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" style="flex:1;padding:12px;background:#3b82f6;color:#fff;border:none;border-radius:10px;font-family:inherit;font-size:.9rem;font-weight:700;cursor:pointer;">ثبت سفارش</button>
        <button type="button" onclick="closeModal('newOrderModal')" style="padding:12px 20px;background:#f1f5f9;color:#64748b;border:none;border-radius:10px;font-family:inherit;cursor:pointer;">انصراف</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ Drawer جزئیات سفارش ════════════════════════════ -->
<div id="orderDrawer" style="display:none;position:fixed;inset:0;z-index:900;">
  <div onclick="closeDrawer()" style="position:absolute;inset:0;background:rgba(0,0,0,.45);"></div>
  <div style="position:absolute;left:0;top:0;bottom:0;width:min(700px,100%);background:#fff;overflow-y:auto;box-shadow:4px 0 30px rgba(0,0,0,.2);">
    <div id="drawerContent" style="padding:0;"></div>
  </div>
</div>

<!-- ═══ مودال مدیریت مراحل ═════════════════════════════ -->
<div id="stagesModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:20px;width:90%;max-width:620px;max-height:90vh;overflow-y:auto;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
      <h3 style="margin:0;font-size:1.1rem;font-weight:700;">⚙️ مدیریت مراحل واردات</h3>
      <button onclick="closeModal('stagesModal')" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#94a3b8;">✕</button>
    </div>
    <div id="stagesList"></div>
    <div style="margin-top:18px;border-top:1px solid #f1f5f9;padding-top:18px;">
      <h4 style="margin:0 0 12px;font-size:.9rem;color:#1e293b;">افزودن مرحله جدید</h4>
      <form id="newStageForm" onsubmit="saveStage(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="0">
        <div style="display:grid;grid-template-columns:1fr 60px 80px 60px;gap:10px;align-items:end;">
          <div>
            <label style="font-size:.75rem;color:#64748b;display:block;margin-bottom:4px;">نام مرحله *</label>
            <input name="name" required style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.85rem;box-sizing:border-box;">
          </div>
          <div>
            <label style="font-size:.75rem;color:#64748b;display:block;margin-bottom:4px;">آیکون</label>
            <input name="icon" value="📋" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.85rem;text-align:center;">
          </div>
          <div>
            <label style="font-size:.75rem;color:#64748b;display:block;margin-bottom:4px;">رنگ</label>
            <input type="color" name="color" value="#3b82f6" style="width:100%;height:40px;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer;padding:2px;">
          </div>
          <div>
            <label style="font-size:.75rem;color:#64748b;display:block;margin-bottom:4px;">ترتیب</label>
            <input type="number" name="sort_order" value="10" min="1" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.85rem;">
          </div>
        </div>
        <button type="submit" style="margin-top:12px;padding:10px 24px;background:#3b82f6;color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:700;cursor:pointer;">+ افزودن مرحله</button>
      </form>
    </div>
  </div>
</div>

<style>
.imp-tab-btn.active { color:#3b82f6 !important; border-bottom-color:#3b82f6 !important; font-weight:700 !important; }
.imp-order-row { display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid #f8fafc;cursor:pointer;transition:background .15s; }
.imp-order-row:hover { background:#f8fafc; }
.imp-stage-chip { display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:600; }
.stage-bar { height:6px;border-radius:4px;background:#f1f5f9;overflow:hidden; }
.stage-bar-fill { height:6px;border-radius:4px;transition:width .4s; }
.drawer-stage-row { padding:14px 0;border-bottom:1px solid #f8fafc; }
.drawer-stage-row:last-child { border:none; }
</style>

<script>
const CSRF = '<?= $_SESSION['csrf_token'] ?? '' ?>';
let currentOrderId = null;
let currentStatus = 'active';

// ── KPI ──────────────────────────────────────────────────
function loadKpi() {
  $.getJSON(location.pathname + '?action=kpi', function(d) {
    $('#kpiActive').text((d.active||0).toLocaleString('fa-IR'));
    $('#kpiInProg').text((d.in_progress||0).toLocaleString('fa-IR'));
    $('#kpiDone').text((d.done||0).toLocaleString('fa-IR'));
    const c = parseInt(d.total_cost)||0;
    $('#kpiCost').text(c >= 1e9 ? (c/1e9).toFixed(1)+' میلیارد ت' : c >= 1e6 ? (c/1e6).toFixed(1)+' M ت' : c.toLocaleString('fa-IR')+' ت');
  });
}

// ── لیست سفارشات ────────────────────────────────────────
function loadOrders(status) {
  currentStatus = status;
  $('.imp-tab-btn').removeClass('active');
  $(`.imp-tab-btn[data-status="${status}"]`).addClass('active');
  $('#ordersLoading').show(); $('#ordersList').hide();
  $.getJSON(location.pathname + '?action=list&status=' + status, function(d) {
    $('#ordersLoading').hide();
    const rows = d.data || [];
    if (!rows.length) {
      $('#ordersList').html('<div style="padding:48px;text-align:center;color:#94a3b8;">سفارشی یافت نشد</div>').show();
      return;
    }
    const stColors = {'active':'#3b82f6','completed':'#10b981','cancelled':'#ef4444'};
    let html = '';
    rows.forEach(r => {
      const cost = parseFloat(r.actual_cost)||0;
      const costStr = cost >= 1e6 ? (cost/1e6).toFixed(1)+' M' : cost.toLocaleString('fa-IR');
      html += `<div class="imp-order-row" onclick="openOrderDetail(${r.id})">
        <div style="width:44px;height:44px;border-radius:12px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">🚢</div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;color:#0f172a;font-size:.9rem;">${r.title}</div>
          <div style="color:#64748b;font-size:.76rem;margin-top:2px;">${r.order_number} | ${r.supplier_name||'—'} | ${r.supplier_country||'—'}</div>
        </div>
        <div style="text-align:center;min-width:100px;">
          ${r.cur_stage ? `<div style="font-size:.72rem;background:#eff6ff;color:#1d4ed8;padding:4px 10px;border-radius:20px;display:inline-block;">${r.cur_stage}</div>` : '<span style="color:#94a3b8;font-size:.75rem;">—</span>'}
        </div>
        <div style="text-align:left;direction:ltr;min-width:90px;">
          <div style="font-size:.82rem;font-weight:700;color:#0f172a;">${costStr} ت</div>
          <div style="font-size:.68rem;color:#94a3b8;">${r.currency||'IRR'}</div>
        </div>
        <div style="color:#cbd5e1;font-size:1rem;">←</div>
      </div>`;
    });
    $('#ordersList').html(html).show();
  });
}

// ── جزئیات سفارش ────────────────────────────────────────
function openOrderDetail(id) {
  currentOrderId = id;
  $('#drawerContent').html('<div style="padding:40px;text-align:center;color:#94a3b8;">در حال بارگذاری...</div>');
  $('#orderDrawer').css('display','flex');
  $.getJSON(location.pathname + '?action=detail&id=' + id, function(d) {
    if (d.error) { $('#drawerContent').html('<div style="padding:40px;color:red;">'+d.error+'</div>'); return; }
    renderDrawer(d);
  });
}
function closeDrawer() { $('#orderDrawer').hide(); loadOrders(currentStatus); loadKpi(); }

function renderDrawer(d) {
  const totalCost = (d.costs||[]).reduce((s,c) => s + parseFloat(c.amount||0), 0);
  const stDone  = (d.stages||[]).filter(s => s.st_status==='completed').length;
  const stTotal = (d.stages||[]).length;
  const pct = stTotal ? Math.round(stDone/stTotal*100) : 0;

  const stColors = {pending:'#e2e8f0',in_progress:'#fbbf24',completed:'#10b981',skipped:'#94a3b8'};
  const stLabels = {pending:'در انتظار',in_progress:'در حال انجام',completed:'تکمیل شده',skipped:'رد شد'};

  let stagesHtml = '';
  (d.stages||[]).forEach((s,i) => {
    const sc = stColors[s.st_status] || '#e2e8f0';
    const sl = stLabels[s.st_status] || s.st_status;
    stagesHtml += `<div class="drawer-stage-row">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
        <div style="width:34px;height:34px;border-radius:50%;background:${sc}22;border:2px solid ${sc};display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0;">${s.icon||'📋'}</div>
        <div style="flex:1;">
          <div style="font-weight:600;font-size:.88rem;color:#0f172a;">${s.name}</div>
          <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700;background:${sc}22;color:${s.st_status==='completed'?'#059669':s.st_status==='in_progress'?'#b45309':'#64748b'};">${sl}</span>
        </div>
        <button onclick="openStageEdit(${d.id},${s.id},'${s.st_status}','${s.start_date||''}','${s.end_date||''}')" style="padding:5px 12px;background:#f1f5f9;border:none;border-radius:8px;cursor:pointer;font-family:inherit;font-size:.75rem;">ویرایش</button>
      </div>
      ${s.st_notes ? `<div style="font-size:.77rem;color:#64748b;background:#f8fafc;padding:8px 12px;border-radius:8px;margin-top:6px;">${s.st_notes}</div>` : ''}
      ${(s.start_date||s.end_date) ? `<div style="font-size:.72rem;color:#94a3b8;margin-top:4px;">شروع: ${s.start_date||'—'} | پایان: ${s.end_date||'—'}</div>` : ''}
    </div>`;
  });

  let costsHtml = '';
  (d.costs||[]).forEach(c => {
    costsHtml += `<div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #f8fafc;font-size:.82rem;">
      <div style="flex:1;"><span style="color:#334155;font-weight:500;">${c.description||c.cost_type||'—'}</span><br><span style="color:#94a3b8;font-size:.7rem;">${c.stage_name||'عمومی'} | ${c.paid_at||'—'}</span></div>
      <div style="font-weight:700;color:#0f172a;">${parseFloat(c.amount||0).toLocaleString('fa-IR')} ${c.currency}</div>
      ${c.receipt_file ? `<a href="../../uploads/imports/${c.receipt_file}" target="_blank" style="color:#3b82f6;font-size:.72rem;">رسید</a>` : ''}
    </div>`;
  });
  if (!costsHtml) costsHtml = '<div style="color:#94a3b8;font-size:.8rem;padding:12px 0;">هزینه‌ای ثبت نشده</div>';

  let docsHtml = '';
  (d.documents||[]).forEach(doc => {
    const ext = doc.original_name.split('.').pop().toLowerCase();
    const ico = ['jpg','jpeg','png'].includes(ext) ? '🖼' : ext === 'pdf' ? '📄' : '📎';
    docsHtml += `<div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid #f8fafc;font-size:.8rem;">
      <span style="font-size:1rem;">${ico}</span>
      <a href="../../uploads/imports/${doc.filename}" target="_blank" style="flex:1;color:#3b82f6;text-decoration:none;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">${doc.original_name}</a>
      <span style="color:#94a3b8;font-size:.7rem;">${doc.stage_name||'عمومی'}</span>
      <button onclick="deleteDoc(${doc.id})" style="background:none;border:none;cursor:pointer;color:#ef4444;font-size:.8rem;">✕</button>
    </div>`;
  });
  if (!docsHtml) docsHtml = '<div style="color:#94a3b8;font-size:.8rem;padding:12px 0;">مدرکی آپلود نشده</div>';

  const stageSel = (d.stages||[]).map(s => `<option value="${s.id}">${s.name}</option>`).join('');

  $('#drawerContent').html(`
    <div style="background:linear-gradient(135deg,#0f172a,#1e3a8a);color:#fff;padding:22px 24px;">
      <div style="font-size:.75rem;opacity:.7;">${d.order_number}</div>
      <div style="font-size:1.15rem;font-weight:700;margin:4px 0;">${d.title}</div>
      <div style="font-size:.8rem;opacity:.8;">${d.supplier_name||''} ${d.supplier_country?'| '+d.supplier_country:''}</div>
      <div style="margin-top:14px;background:rgba(255,255,255,.15);border-radius:8px;height:8px;">
        <div style="background:#22c55e;width:${pct}%;height:8px;border-radius:8px;transition:width .5s;"></div>
      </div>
      <div style="font-size:.72rem;opacity:.8;margin-top:5px;">پیشرفت: ${stDone} از ${stTotal} مرحله (${pct}٪)</div>
    </div>

    <div style="padding:20px 24px;border-bottom:1px solid #f1f5f9;">
      <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <div><span style="font-size:.72rem;color:#94a3b8;">مجموع هزینه</span><div style="font-size:1.1rem;font-weight:700;color:#0f172a;">${totalCost.toLocaleString('fa-IR')} ت</div></div>
        <div><span style="font-size:.72rem;color:#94a3b8;">هزینه تخمینی</span><div style="font-size:1.1rem;font-weight:700;color:#0f172a;">${parseFloat(d.est_cost||0).toLocaleString('fa-IR')} ${d.currency}</div></div>
        <div><span style="font-size:.72rem;color:#94a3b8;">طرف حساب</span><div style="font-size:.9rem;font-weight:600;color:#334155;">${d.person_name||'—'}</div></div>
      </div>
    </div>

    <!-- تب‌ها -->
    <div style="display:flex;border-bottom:2px solid #f1f5f9;padding:0 24px;">
      ${[['stages','مراحل'],['costs','هزینه‌ها'],['docs','مدارک']].map(([k,l],i) => `<button class="dtab" data-tab="${k}" onclick="showDrawerTab('${k}')" style="padding:12px 16px;border:none;background:none;cursor:pointer;font-family:inherit;font-size:.82rem;color:${i===0?'#3b82f6':'#64748b'};font-weight:${i===0?700:500};border-bottom:2px solid ${i===0?'#3b82f6':'transparent'};margin-bottom:-2px;">${l}</button>`).join('')}
    </div>

    <div id="dtab-stages" style="padding:16px 24px;">${stagesHtml}</div>

    <div id="dtab-costs" style="padding:16px 24px;display:none;">
      ${costsHtml}
      <form onsubmit="submitCost(event)" style="margin-top:16px;border-top:1px dashed #e2e8f0;padding-top:16px;">
        <div style="font-size:.82rem;font-weight:700;margin-bottom:10px;color:#1e293b;">+ افزودن هزینه</div>
        <input type="hidden" name="order_id" value="${d.id}">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
          <select name="stage_id" style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.82rem;">
            <option value="">— مرحله —</option>${stageSel}
          </select>
          <input name="cost_type" placeholder="نوع هزینه (گمرک، کرایه...)" style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.82rem;">
          <input name="description" placeholder="شرح" style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.82rem;">
          <input name="amount" placeholder="مبلغ" style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.82rem;">
          <select name="currency" style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.82rem;">
            <option value="IRR">ریال</option><option value="USD">دلار</option><option value="EUR">یورو</option>
          </select>
          <input name="paid_at" placeholder="تاریخ پرداخت ۱۴۰۴/..." style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.82rem;">
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
          <label style="font-size:.78rem;color:#64748b;">رسید/فاکتور: <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf" style="font-size:.75rem;"></label>
          <button type="submit" style="padding:8px 20px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-family:inherit;cursor:pointer;font-size:.82rem;font-weight:700;">ثبت</button>
        </div>
      </form>
    </div>

    <div id="dtab-docs" style="padding:16px 24px;display:none;">
      ${docsHtml}
      <form onsubmit="submitDoc(event)" style="margin-top:16px;border-top:1px dashed #e2e8f0;padding-top:16px;">
        <div style="font-size:.82rem;font-weight:700;margin-bottom:10px;color:#1e293b;">+ آپلود مدرک جدید</div>
        <input type="hidden" name="order_id" value="${d.id}">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
          <select name="stage_id" style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.82rem;">
            <option value="">— مرحله —</option>${stageSel}
          </select>
          <input name="doc_type" placeholder="نوع مدرک (پروفرما، بارنامه...)" style="padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.82rem;">
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="file" name="file" required style="flex:1;font-size:.78rem;">
          <button type="submit" style="padding:8px 20px;background:#10b981;color:#fff;border:none;border-radius:8px;font-family:inherit;cursor:pointer;font-size:.82rem;font-weight:700;">آپلود</button>
        </div>
      </form>
    </div>
  `);
}

function showDrawerTab(tab) {
  $('.dtab').each(function() {
    const active = $(this).data('tab') === tab;
    $(this).css({'color': active ? '#3b82f6' : '#64748b', 'font-weight': active ? 700 : 500, 'border-bottom-color': active ? '#3b82f6' : 'transparent'});
  });
  $('#dtab-stages, #dtab-costs, #dtab-docs').hide();
  $(`#dtab-${tab}`).show();
}

// ── ویرایش مرحله ──────────────────────────────────────
function openStageEdit(orderId, stageId, status, startDate, endDate) {
  const sl = {pending:'در انتظار',in_progress:'در حال انجام',completed:'تکمیل شده',skipped:'رد شد'};
  const opts = Object.entries(sl).map(([v,l]) => `<option value="${v}" ${v===status?'selected':''}>${l}</option>`).join('');
  const modal = document.createElement('div');
  modal.id = 'stageEditModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;display:flex;align-items:center;justify-content:center;';
  modal.innerHTML = `<div style="background:#fff;border-radius:16px;padding:24px;width:380px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <h4 style="margin:0 0 16px;font-size:1rem;">ویرایش وضعیت مرحله</h4>
    <form onsubmit="saveStageStatus(event,${orderId},${stageId})">
      <select name="status" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:9px;font-family:inherit;margin-bottom:10px;">${opts}</select>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
        <div><label style="font-size:.75rem;color:#64748b;">تاریخ شروع</label><input name="start_date" value="${startDate}" placeholder="۱۴۰۴/..." style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.85rem;box-sizing:border-box;margin-top:4px;"></div>
        <div><label style="font-size:.75rem;color:#64748b;">تاریخ پایان</label><input name="end_date" value="${endDate}" placeholder="۱۴۰۴/..." style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.85rem;box-sizing:border-box;margin-top:4px;"></div>
      </div>
      <textarea name="notes" rows="2" placeholder="یادداشت..." style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:.85rem;resize:vertical;box-sizing:border-box;margin-bottom:12px;"></textarea>
      <div style="display:flex;gap:8px;">
        <button type="submit" style="flex:1;padding:10px;background:#3b82f6;color:#fff;border:none;border-radius:9px;font-family:inherit;cursor:pointer;font-weight:700;">ذخیره</button>
        <button type="button" onclick="document.getElementById('stageEditModal').remove()" style="padding:10px 16px;background:#f1f5f9;color:#64748b;border:none;border-radius:9px;font-family:inherit;cursor:pointer;">انصراف</button>
      </div>
    </form>
  </div>`;
  document.body.appendChild(modal);
}

function saveStageStatus(e, orderId, stageId) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action', 'update_stage');
  fd.append('order_id', orderId);
  fd.append('stage_id', stageId);
  fd.append('_csrf', CSRF);
  $.ajax({url: location.pathname, method:'POST', data: fd, processData:false, contentType:false,
    success: function() {
      document.getElementById('stageEditModal').remove();
      openOrderDetail(orderId);
    }
  });
}

// ── ثبت سفارش جدید ───────────────────────────────────
function openNewOrderModal() { $('#newOrderModal').css('display','flex'); }
function submitNewOrder(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action','create_order');
  $.ajax({url:location.pathname,method:'POST',data:fd,processData:false,contentType:false,
    success: function(r) {
      if (r.ok) { closeModal('newOrderModal'); e.target.reset(); loadOrders('active'); loadKpi(); openOrderDetail(r.id); }
      else alert(r.msg||'خطا');
    }
  });
}

// ── هزینه ─────────────────────────────────────────────
function submitCost(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action','add_cost'); fd.append('_csrf',CSRF);
  $.ajax({url:location.pathname,method:'POST',data:fd,processData:false,contentType:false,
    success: function(r) { if (r.ok) { openOrderDetail(currentOrderId); } else alert(r.msg||'خطا'); }
  });
}

// ── آپلود مدرک ────────────────────────────────────────
function submitDoc(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action','upload_doc'); fd.append('_csrf',CSRF);
  $.ajax({url:location.pathname,method:'POST',data:fd,processData:false,contentType:false,
    success: function(r) { if (r.ok) { openOrderDetail(currentOrderId); } else alert(r.msg||'خطا'); }
  });
}

function deleteDoc(id) {
  if (!confirm('مدرک حذف شود؟')) return;
  $.post(location.pathname, {action:'delete_doc',id:id,_csrf:CSRF}, function(r) {
    if (r.ok) openOrderDetail(currentOrderId);
  });
}

// ── مدیریت مراحل ──────────────────────────────────────
function openStagesModal() {
  $('#stagesModal').css('display','flex');
  loadStagesList();
}
function loadStagesList() {
  $.getJSON(location.pathname + '?action=list_stages', function(rows) {
    let html = '';
    rows.forEach(s => {
      html += `<div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9;">
        <span style="font-size:1.2rem;">${s.icon}</span>
        <div style="flex:1;">
          <div style="font-size:.85rem;font-weight:600;color:#0f172a;">${s.name}</div>
          <div style="font-size:.72rem;color:#94a3b8;">ترتیب: ${s.sort_order} | ${s.is_system?'سیستمی':''}</div>
        </div>
        <div style="width:14px;height:14px;border-radius:50%;background:${s.color};"></div>
        <span style="padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;background:${s.is_active?'#dcfce7':'#fee2e2'};color:${s.is_active?'#166534':'#991b1b'};">${s.is_active?'فعال':'غیرفعال'}</span>
        ${!s.is_system ? `<button onclick="toggleStage(${s.id})" style="padding:4px 10px;background:#f1f5f9;border:none;border-radius:8px;cursor:pointer;font-family:inherit;font-size:.72rem;">${s.is_active?'غیرفعال':'فعال'} کن</button>` : ''}
      </div>`;
    });
    $('#stagesList').html(html);
  });
}
function toggleStage(id) {
  $.post(location.pathname, {action:'toggle_stage',id:id,_csrf:CSRF}, function(r) {
    if (r.ok) loadStagesList(); else alert(r.msg);
  });
}
function saveStage(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action','save_stage');
  $.ajax({url:location.pathname,method:'POST',data:fd,processData:false,contentType:false,
    success: function(r) { if (r.ok) { e.target.reset(); loadStagesList(); } else alert(r.msg); }
  });
}

function closeModal(id) { $('#'+id).hide(); }
function openModal(id)  { $('#'+id).css('display','flex'); }

$(function() {
  loadKpi();
  loadOrders('active');
  $('[data-status="active"]').addClass('active');
});
</script>

<?php require_once $root . '/templates/footer.php'; ?>
