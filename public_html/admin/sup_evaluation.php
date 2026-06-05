<?php
/* ارزیابی تأمین‌کنندگان + AVL — ISO 13485 §7.4 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$userId   = $_SESSION['user_id'];
$userRole = strtolower($_SESSION['role'] ?? 'staff');

/* ─── جداول ─── */
$pdo->exec("
CREATE TABLE IF NOT EXISTS sup_suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id INT UNSIGNED DEFAULT NULL,
    supplier_code VARCHAR(30) NOT NULL UNIQUE,
    company_name VARCHAR(200) NOT NULL,
    contact_name VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    product_category VARCHAR(200) DEFAULT NULL,
    avl_status ENUM('new','approved','conditional','suspended','removed') NOT NULL DEFAULT 'new',
    avl_date VARCHAR(12) DEFAULT NULL,
    initial_score DECIMAL(5,2) DEFAULT NULL,
    last_eval_date VARCHAR(12) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    INDEX idx_avl(avl_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS sup_evaluations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    eval_period VARCHAR(20) NOT NULL,
    quality_score DECIMAL(5,2) DEFAULT NULL,
    delivery_score DECIMAL(5,2) DEFAULT NULL,
    price_score DECIMAL(5,2) DEFAULT NULL,
    service_score DECIMAL(5,2) DEFAULT NULL,
    total_score DECIMAL(5,2) DEFAULT NULL,
    result ENUM('approved','conditional','suspended') DEFAULT NULL,
    evaluator_id INT UNSIGNED DEFAULT NULL,
    eval_date VARCHAR(12) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    INDEX idx_supplier(supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* شماره‌گذاری تأمین‌کننده */
function nextSupplierCode(PDO $pdo): string {
    $y = jdate('Y');
    $last = $pdo->query("SELECT supplier_code FROM sup_suppliers WHERE supplier_code LIKE 'SUP-{$y}-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $seq = $last ? ((int)substr($last, -4) + 1) : 1;
    return sprintf('SUP-%s-%04d', $y, $seq);
}

/* ─── AJAX ─── */
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    csrf_verify();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    /* ذخیره تأمین‌کننده */
    if ($action === 'save_supplier') {
        $id       = (int)($_POST['id'] ?? 0);
        $company  = trim($_POST['company_name'] ?? '');
        $contact  = trim($_POST['contact_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $cat      = trim($_POST['product_category'] ?? '');
        $avl      = $_POST['avl_status'] ?? 'new';
        $avlDate  = trim($_POST['avl_date'] ?? '');
        $score    = strlen($_POST['initial_score'] ?? '') ? (float)$_POST['initial_score'] : null;
        $notes    = trim($_POST['notes'] ?? '');
        $personId = (int)($_POST['person_id'] ?? 0) ?: null;
        if (!$company) { echo json_encode(['ok'=>false,'msg'=>'نام شرکت الزامی است']); exit; }
        if ($id) {
            $s = $pdo->prepare("UPDATE sup_suppliers SET company_name=?,contact_name=?,phone=?,email=?,product_category=?,avl_status=?,avl_date=?,initial_score=?,notes=?,person_id=? WHERE id=? AND is_deleted=0");
            $s->execute([$company,$contact,$phone,$email,$cat,$avl,$avlDate,$score,$notes,$personId,$id]);
        } else {
            $code = nextSupplierCode($pdo);
            $s = $pdo->prepare("INSERT INTO sup_suppliers (supplier_code,company_name,contact_name,phone,email,product_category,avl_status,avl_date,initial_score,notes,person_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $s->execute([$code,$company,$contact,$phone,$email,$cat,$avl,$avlDate,$score,$notes,$personId,$userId]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }

    /* به‌روزرسانی وضعیت AVL */
    if ($action === 'update_avl') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['avl_status'] ?? 'new';
        $date   = trim($_POST['avl_date'] ?? jdate('Y/m/d'));
        $pdo->prepare("UPDATE sup_suppliers SET avl_status=?, avl_date=? WHERE id=?")->execute([$status,$date,$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* حذف تأمین‌کننده */
    if ($action === 'delete_supplier') {
        $pdo->prepare("UPDATE sup_suppliers SET is_deleted=1 WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* ذخیره ارزیابی */
    if ($action === 'save_evaluation') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $period     = trim($_POST['eval_period'] ?? '');
        $q = (float)($_POST['quality_score'] ?? 0);
        $d = (float)($_POST['delivery_score'] ?? 0);
        $p = (float)($_POST['price_score'] ?? 0);
        $sv = (float)($_POST['service_score'] ?? 0);
        $total = round(($q + $d + $p + $sv) / 4, 2);
        $result = $total >= 75 ? 'approved' : ($total >= 50 ? 'conditional' : 'suspended');
        $date   = trim($_POST['eval_date'] ?? jdate('Y/m/d'));
        $notes  = trim($_POST['notes'] ?? '');
        if (!$supplierId || !$period) { echo json_encode(['ok'=>false,'msg'=>'تأمین‌کننده و دوره الزامی است']); exit; }
        $s = $pdo->prepare("INSERT INTO sup_evaluations (supplier_id,eval_period,quality_score,delivery_score,price_score,service_score,total_score,result,evaluator_id,eval_date,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $s->execute([$supplierId,$period,$q,$d,$p,$sv,$total,$result,$userId,$date,$notes]);
        $evalId = $pdo->lastInsertId();
        /* به‌روزرسانی وضعیت AVL تأمین‌کننده بر اساس نتیجه */
        $pdo->prepare("UPDATE sup_suppliers SET avl_status=?, last_eval_date=? WHERE id=?")->execute([$result,$date,$supplierId]);
        echo json_encode(['ok'=>true,'id'=>$evalId,'total'=>$total,'result'=>$result]); exit;
    }

    /* لیست ارزیابی‌های یک تأمین‌کننده */
    if ($action === 'get_evals') {
        $supplierId = (int)($_GET['supplier_id'] ?? 0);
        $rows = $pdo->prepare("SELECT e.*, u.name evaluator_name FROM sup_evaluations e LEFT JOIN users u ON u.id=e.evaluator_id WHERE e.supplier_id=? AND e.is_deleted=0 ORDER BY e.id DESC");
        $rows->execute([$supplierId]);
        echo json_encode(['ok'=>true,'rows'=>$rows->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'عملیات نامعتبر']); exit;
}

/* ─── داده صفحه ─── */
$suppliers = $pdo->query("SELECT s.*, fp.name fp_name FROM sup_suppliers s LEFT JOIN fin_persons fp ON fp.id=s.person_id WHERE s.is_deleted=0 ORDER BY s.company_name")->fetchAll(PDO::FETCH_ASSOC);
$finPersons = $pdo->query("SELECT id, name FROM fin_persons WHERE is_deleted=0 AND type='supplier' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$evalRecent = $pdo->query("
    SELECT e.*, s.company_name, s.supplier_code, u.name evaluator_name
    FROM sup_evaluations e
    JOIN sup_suppliers s ON s.id=e.supplier_id
    LEFT JOIN users u ON u.id=e.evaluator_id
    WHERE e.is_deleted=0
    ORDER BY e.id DESC LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

/* آمار */
$statApproved    = count(array_filter($suppliers, fn($s) => $s['avl_status']==='approved'));
$statConditional = count(array_filter($suppliers, fn($s) => $s['avl_status']==='conditional'));
$statSuspended   = count(array_filter($suppliers, fn($s) => $s['avl_status']==='suspended'));
$statNew         = count(array_filter($suppliers, fn($s) => $s['avl_status']==='new'));

$avlLabels  = ['new'=>'جدید','approved'=>'تأییدشده','conditional'=>'مشروط','suspended'=>'تعلیق','removed'=>'حذف'];
$avlColors  = ['new'=>'#e0f2fe','approved'=>'#dcfce7','conditional'=>'#fef9c3','suspended'=>'#fee2e2','removed'=>'#f3f4f6'];
$avlTxtColors = ['new'=>'#0369a1','approved'=>'#15803d','conditional'=>'#854d0e','suspended'=>'#dc2626','removed'=>'#6b7280'];
$evalResult = ['approved'=>'تأییدشده','conditional'=>'مشروط','suspended'=>'تعلیق'];
$evalResultColor = ['approved'=>'green','conditional'=>'amber','suspended'=>'rose'];

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<link rel="stylesheet" href="/assets/css/fin_module.css">
<style>
.score-bar{display:flex;align-items:center;gap:8px}
.score-bar-fill{height:8px;border-radius:4px;background:#2563eb;transition:width .3s}
.avl-chip{display:inline-block;padding:3px 12px;border-radius:12px;font-size:12px;font-weight:600}
.eval-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}
</style>

<div class="fin-page-wrap" dir="rtl">
<div class="fin-page-header">
  <h1 class="fin-page-title">ارزیابی تأمین‌کنندگان — ISO 13485 §7.4</h1>
  <div style="display:flex;gap:8px">
    <button class="fin-btn fin-btn-primary" onclick="openDrawer('supplier')">+ تأمین‌کننده جدید</button>
    <button class="fin-btn fin-btn-secondary" onclick="openDrawer('eval')">+ ارزیابی جدید</button>
  </div>
</div>

<!-- آمار -->
<div class="fin-stats-row">
  <div class="fin-stat-card green"><div class="fin-stat-value"><?= $statApproved ?></div><div class="fin-stat-label">تأییدشده (AVL)</div></div>
  <div class="fin-stat-card amber"><div class="fin-stat-value"><?= $statConditional ?></div><div class="fin-stat-label">مشروط</div></div>
  <div class="fin-stat-card rose"><div class="fin-stat-value"><?= $statSuspended ?></div><div class="fin-stat-label">تعلیق</div></div>
  <div class="fin-stat-card blue"><div class="fin-stat-value"><?= $statNew ?></div><div class="fin-stat-label">جدید / در بررسی</div></div>
</div>

<div class="fin-panel">
<div class="fin-tabs">
  <button class="fin-tab active" data-tab="tab-avl">فهرست تأمین‌کنندگان (AVL)</button>
  <button class="fin-tab" data-tab="tab-evals">تاریخچه ارزیابی‌ها</button>
</div>

<!-- AVL -->
<div id="tab-avl" class="fin-tab-content active" style="padding:16px">
<table class="fin-table">
  <thead><tr><th>کد</th><th>شرکت</th><th>دسته کالا</th><th>تماس</th><th>وضعیت AVL</th><th>آخرین ارزیابی</th><th>امتیاز اولیه</th><th>عملیات</th></tr></thead>
  <tbody>
  <?php foreach ($suppliers as $sup): ?>
  <tr>
    <td><code><?= htmlspecialchars($sup['supplier_code']) ?></code></td>
    <td>
      <strong><?= htmlspecialchars($sup['company_name']) ?></strong>
      <?php if ($sup['fp_name']): ?><br><small style="color:#6b7280">↔ <?= htmlspecialchars($sup['fp_name']) ?></small><?php endif; ?>
    </td>
    <td><?= htmlspecialchars($sup['product_category'] ?: '—') ?></td>
    <td>
      <?= htmlspecialchars($sup['contact_name'] ?: '—') ?>
      <?php if ($sup['phone']): ?><br><small><?= htmlspecialchars($sup['phone']) ?></small><?php endif; ?>
    </td>
    <td>
      <span class="avl-chip" style="background:<?= $avlColors[$sup['avl_status']] ?>;color:<?= $avlTxtColors[$sup['avl_status']] ?>">
        <?= $avlLabels[$sup['avl_status']] ?>
      </span>
      <?php if ($sup['avl_date']): ?><br><small style="color:#6b7280"><?= $sup['avl_date'] ?></small><?php endif; ?>
    </td>
    <td><?= $sup['last_eval_date'] ?: '—' ?></td>
    <td><?= $sup['initial_score'] !== null ? $sup['initial_score'].'%' : '—' ?></td>
    <td style="white-space:nowrap">
      <button class="fin-btn fin-btn-xs fin-btn-secondary" onclick='editSupplier(<?= json_encode($sup) ?>)'>ویرایش</button>
      <button class="fin-btn fin-btn-xs" style="background:#e0f2fe;color:#0369a1" onclick="openEvalFor(<?= $sup['id'] ?>, '<?= htmlspecialchars($sup['company_name']) ?>')">ارزیابی</button>
      <button class="fin-btn fin-btn-xs" style="background:#fef2f2;color:#dc2626" onclick="deleteSupplier(<?= $sup['id'] ?>)">حذف</button>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$suppliers): ?><tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:32px">تأمین‌کننده‌ای ثبت نشده</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<!-- ارزیابی‌ها -->
<div id="tab-evals" class="fin-tab-content" style="padding:16px">
<table class="fin-table">
  <thead><tr><th>تأمین‌کننده</th><th>دوره</th><th>کیفیت</th><th>تحویل</th><th>قیمت</th><th>خدمات</th><th>کل</th><th>نتیجه</th><th>تاریخ</th><th>ارزیاب</th></tr></thead>
  <tbody>
  <?php foreach ($evalRecent as $e):
    $scoreColor = $e['total_score'] >= 75 ? '#16a34a' : ($e['total_score'] >= 50 ? '#d97706' : '#dc2626');
  ?>
  <tr>
    <td><?= htmlspecialchars($e['company_name']) ?><br><code style="font-size:11px"><?= $e['supplier_code'] ?></code></td>
    <td><?= htmlspecialchars($e['eval_period']) ?></td>
    <td><?= $e['quality_score'] ?>%</td>
    <td><?= $e['delivery_score'] ?>%</td>
    <td><?= $e['price_score'] ?>%</td>
    <td><?= $e['service_score'] ?>%</td>
    <td><strong style="color:<?= $scoreColor ?>"><?= $e['total_score'] ?>%</strong></td>
    <td><?php if ($e['result']): ?><span class="fin-badge fin-badge-<?= $evalResultColor[$e['result']] ?>"><?= $evalResult[$e['result']] ?></span><?php else: ?>—<?php endif; ?></td>
    <td><?= $e['eval_date'] ?: '—' ?></td>
    <td><?= htmlspecialchars($e['evaluator_name'] ?: '—') ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$evalRecent): ?><tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:32px">ارزیابی‌ای ثبت نشده</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
</div>

<!-- ─── Drawer تأمین‌کننده ─── -->
<div class="fin-drawer-overlay" id="drawerSupplier">
<div class="fin-drawer">
  <div class="fin-drawer-header"><h3 id="supplierDrawerTitle">تأمین‌کننده جدید</h3><button onclick="closeDrawer('Supplier')">✕</button></div>
  <div class="fin-drawer-body">
    <form id="formSupplier" onsubmit="submitSupplier(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_supplier">
      <input type="hidden" name="id" id="sup_id" value="0">
      <div class="fin-form-group"><label>نام شرکت *</label><input type="text" name="company_name" id="sup_company" class="fin-input" required></div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>نام مخاطب</label><input type="text" name="contact_name" id="sup_contact" class="fin-input"></div>
        <div class="fin-form-group"><label>تلفن</label><input type="text" name="phone" id="sup_phone" class="fin-input" dir="ltr"></div>
      </div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>ایمیل</label><input type="email" name="email" id="sup_email" class="fin-input" dir="ltr"></div>
        <div class="fin-form-group"><label>دسته کالا</label><input type="text" name="product_category" id="sup_cat" class="fin-input" placeholder="مثلا: تجهیزات پزشکی"></div>
      </div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>وضعیت AVL</label>
          <select name="avl_status" id="sup_avl" class="fin-input">
            <option value="new">جدید</option>
            <option value="approved">تأییدشده</option>
            <option value="conditional">مشروط</option>
            <option value="suspended">تعلیق</option>
            <option value="removed">حذف‌شده</option>
          </select>
        </div>
        <div class="fin-form-group"><label>تاریخ تأیید AVL</label><input type="text" name="avl_date" id="sup_avldate" class="fin-input" placeholder="۱۴۰۳/۰۱/۰۱"></div>
      </div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>امتیاز اولیه (%)</label><input type="number" name="initial_score" id="sup_score" class="fin-input" min="0" max="100" step="0.01"></div>
        <div class="fin-form-group"><label>لینک به طرف حساب</label>
          <select name="person_id" id="sup_person" class="fin-input">
            <option value="">—</option>
            <?php foreach ($finPersons as $fp): ?>
            <option value="<?= $fp['id'] ?>"><?= htmlspecialchars($fp['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="fin-form-group"><label>یادداشت</label><textarea name="notes" id="sup_notes" class="fin-input" rows="2"></textarea></div>
      <div class="fin-drawer-footer"><button type="submit" class="fin-btn fin-btn-primary">ذخیره</button><button type="button" class="fin-btn fin-btn-secondary" onclick="closeDrawer('Supplier')">انصراف</button></div>
    </form>
  </div>
</div>
</div>

<!-- ─── Drawer ارزیابی ─── -->
<div class="fin-drawer-overlay" id="drawerEval">
<div class="fin-drawer">
  <div class="fin-drawer-header"><h3 id="evalDrawerTitle">ارزیابی تأمین‌کننده</h3><button onclick="closeDrawer('Eval')">✕</button></div>
  <div class="fin-drawer-body">
    <form id="formEval" onsubmit="submitEval(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_evaluation">
      <input type="hidden" name="supplier_id" id="eval_supplier_id" value="">
      <div class="fin-form-row">
        <div class="fin-form-group"><label>تأمین‌کننده *</label>
          <select name="supplier_id" id="eval_supplier_select" class="fin-input" required>
            <option value="">انتخاب کنید</option>
            <?php foreach ($suppliers as $sup): ?>
            <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['company_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fin-form-group"><label>دوره ارزیابی</label><input type="text" name="eval_period" id="eval_period" class="fin-input" placeholder="۱۴۰۳/Q2"></div>
      </div>
      <p style="font-size:13px;color:#6b7280;margin:8px 0">هر معیار از ۰ تا ۱۰۰ امتیاز دهید:</p>
      <div class="eval-grid">
        <div class="fin-form-group"><label>کیفیت محصول 🎯</label><input type="number" name="quality_score" id="eval_q" class="fin-input" min="0" max="100" step="1" placeholder="0-100" oninput="calcTotal()"></div>
        <div class="fin-form-group"><label>تحویل به موقع 🚚</label><input type="number" name="delivery_score" id="eval_d" class="fin-input" min="0" max="100" step="1" placeholder="0-100" oninput="calcTotal()"></div>
        <div class="fin-form-group"><label>رقابت‌پذیری قیمت 💰</label><input type="number" name="price_score" id="eval_p" class="fin-input" min="0" max="100" step="1" placeholder="0-100" oninput="calcTotal()"></div>
        <div class="fin-form-group"><label>خدمات پس از فروش 🛠️</label><input type="number" name="service_score" id="eval_s" class="fin-input" min="0" max="100" step="1" placeholder="0-100" oninput="calcTotal()"></div>
      </div>
      <div id="totalScoreDisplay" style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center;font-size:18px;font-weight:700;margin-bottom:12px;display:none"></div>
      <div class="fin-form-row">
        <div class="fin-form-group"><label>تاریخ ارزیابی</label><input type="text" name="eval_date" id="eval_date" class="fin-input" placeholder="<?= jdate('Y/m/d') ?>"></div>
      </div>
      <div class="fin-form-group"><label>یادداشت</label><textarea name="notes" id="eval_notes" class="fin-input" rows="2"></textarea></div>
      <div style="font-size:12px;color:#6b7280;padding:8px;background:#f8fafc;border-radius:6px;margin-bottom:12px">
        <strong>معیار تصمیم‌گیری:</strong> ≥۷۵% تأییدشده | ۵۰–۷۵% مشروط | زیر ۵۰% تعلیق<br>
        وضعیت AVL تأمین‌کننده پس از ذخیره خودکار به‌روز می‌شود.
      </div>
      <div class="fin-drawer-footer"><button type="submit" class="fin-btn fin-btn-primary">ثبت ارزیابی</button><button type="button" class="fin-btn fin-btn-secondary" onclick="closeDrawer('Eval')">انصراف</button></div>
    </form>
  </div>
</div>
</div>

</div><!-- fin-page-wrap -->

<script>
/* تب‌ها */
document.querySelectorAll('.fin-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.fin-tab,.fin-tab-content').forEach(el => el.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

function openDrawer(type) {
    document.getElementById('drawer'+type).classList.add('open');
}
function closeDrawer(type) {
    document.getElementById('drawer'+type).classList.remove('open');
}

function editSupplier(s) {
    document.getElementById('sup_id').value      = s.id;
    document.getElementById('sup_company').value = s.company_name;
    document.getElementById('sup_contact').value = s.contact_name || '';
    document.getElementById('sup_phone').value   = s.phone || '';
    document.getElementById('sup_email').value   = s.email || '';
    document.getElementById('sup_cat').value     = s.product_category || '';
    document.getElementById('sup_avl').value     = s.avl_status;
    document.getElementById('sup_avldate').value = s.avl_date || '';
    document.getElementById('sup_score').value   = s.initial_score || '';
    document.getElementById('sup_person').value  = s.person_id || '';
    document.getElementById('sup_notes').value   = s.notes || '';
    document.getElementById('supplierDrawerTitle').textContent = 'ویرایش: ' + s.company_name;
    openDrawer('Supplier');
}

function openEvalFor(supplierId, name) {
    document.getElementById('eval_supplier_select').value = supplierId;
    document.getElementById('eval_supplier_id').value = supplierId;
    document.getElementById('evalDrawerTitle').textContent = 'ارزیابی: ' + name;
    document.getElementById('eval_date').value = '';
    document.getElementById('eval_period').value = '';
    document.getElementById('eval_q').value = document.getElementById('eval_d').value = '';
    document.getElementById('eval_p').value = document.getElementById('eval_s').value = '';
    document.getElementById('totalScoreDisplay').style.display = 'none';
    openDrawer('Eval');
}

function calcTotal() {
    const q = parseFloat(document.getElementById('eval_q').value) || 0;
    const d = parseFloat(document.getElementById('eval_d').value) || 0;
    const p = parseFloat(document.getElementById('eval_p').value) || 0;
    const s = parseFloat(document.getElementById('eval_s').value) || 0;
    const count = [q,d,p,s].filter(v => v > 0).length;
    if (!count) { document.getElementById('totalScoreDisplay').style.display='none'; return; }
    const total = ((q+d+p+s)/4).toFixed(1);
    const color = total >= 75 ? '#16a34a' : total >= 50 ? '#d97706' : '#dc2626';
    const label = total >= 75 ? 'تأییدشده' : total >= 50 ? 'مشروط' : 'تعلیق';
    document.getElementById('totalScoreDisplay').innerHTML = `امتیاز کل: <span style="color:${color}">${total}% — ${label}</span>`;
    document.getElementById('totalScoreDisplay').style.display = 'block';
}

function submitSupplier(e) {
    e.preventDefault();
    $.ajax({url:'', method:'POST', data: $(e.target).serialize(),
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok){location.reload();}else{alert(r.msg);} }
    });
}
function submitEval(e) {
    e.preventDefault();
    /* اگر supplier_id از select انتخاب شده باشد override کن */
    const sel = document.getElementById('eval_supplier_select').value;
    if (sel) document.getElementById('eval_supplier_id').value = sel;
    $.ajax({url:'', method:'POST', data: $(e.target).serialize(),
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => {
            if (r.ok) {
                const color = r.total >= 75 ? '#16a34a' : r.total >= 50 ? '#d97706' : '#dc2626';
                alert('ارزیابی ثبت شد.\nامتیاز کل: ' + r.total + '%\nوضعیت AVL: ' + r.result);
                location.reload();
            } else { alert(r.msg); }
        }
    });
}
function deleteSupplier(id) {
    if (!confirm('این تأمین‌کننده حذف شود؟')) return;
    $.ajax({url:'', method:'POST',
        data:{action:'delete_supplier',id:id,_csrf:$('[name=_csrf]').first().val()},
        headers:{'X-Requested-With':'XMLHttpRequest'},
        success: r => { if(r.ok) location.reload(); }
    });
}
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
