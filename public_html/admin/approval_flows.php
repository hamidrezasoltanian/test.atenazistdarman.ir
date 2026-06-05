<?php
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/approval.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
$userRole = strtolower($_SESSION['role'] ?? '');
if (!in_array($userRole, ['admin','management'], true)) {
    die('<div style="text-align:center;margin-top:50px;color:red;font-family:Vazirmatn;">⛔ دسترسی ندارید.</div>');
}

$msg = ''; $msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // افزودن/ویرایش جریان
    if ($action === 'save_flow') {
        $id        = (int)($_POST['flow_id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $module    = $_POST['module'] ?? '';
        $minAmount = (int)str_replace([',', ' '], '', $_POST['min_amount'] ?? '0');
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $modules   = ['invoice_buy','invoice_sell','expense','petty_cash','leave','mission'];
        if (!$name || !in_array($module, $modules, true)) { $msg = '❌ اطلاعات ناقص'; $msgType = 'err'; }
        else {
            if ($id > 0) {
                $pdo->prepare("UPDATE approval_flows SET name=?,module=?,min_amount=?,is_active=?,updated_at=NOW() WHERE id=?")
                    ->execute([$name, $module, $minAmount, $isActive, $id]);
                $msg = '✅ جریان بروزرسانی شد.';
            } else {
                $pdo->prepare("INSERT INTO approval_flows (name,module,min_amount,is_active) VALUES (?,?,?,?)")
                    ->execute([$name, $module, $minAmount, $isActive]);
                $msg = '✅ جریان جدید اضافه شد.';
            }
        }
    }

    // حذف جریان
    if ($action === 'delete_flow') {
        $id = (int)($_POST['flow_id'] ?? 0);
        $pdo->prepare("DELETE FROM approval_flows WHERE id=?")->execute([$id]);
        $msg = '✅ جریان حذف شد.';
    }

    // افزودن مرحله
    if ($action === 'save_step') {
        $flowId    = (int)($_POST['flow_id'] ?? 0);
        $stepOrder = (int)($_POST['step_order'] ?? 1);
        $aType     = $_POST['approver_type'] ?? 'role';
        $aVal      = trim($_POST['approver_value'] ?? '');
        $label     = trim($_POST['label'] ?? '');
        if (!$flowId || !$aVal) { $msg = '❌ اطلاعات ناقص'; $msgType = 'err'; }
        else {
            $stepId = (int)($_POST['step_id'] ?? 0);
            if ($stepId > 0) {
                $pdo->prepare("UPDATE approval_flow_steps SET step_order=?,approver_type=?,approver_value=?,label=? WHERE id=?")
                    ->execute([$stepOrder, $aType, $aVal, $label, $stepId]);
            } else {
                $pdo->prepare("INSERT INTO approval_flow_steps (flow_id,step_order,approver_type,approver_value,label) VALUES (?,?,?,?,?)")
                    ->execute([$flowId, $stepOrder, $aType, $aVal, $label]);
            }
            $msg = '✅ مرحله ذخیره شد.';
        }
    }

    // حذف مرحله
    if ($action === 'delete_step') {
        $stepId = (int)($_POST['step_id'] ?? 0);
        $pdo->prepare("DELETE FROM approval_flow_steps WHERE id=?")->execute([$stepId]);
        $msg = '✅ مرحله حذف شد.';
    }
}

// خواندن جریان‌ها با تعداد مراحل
$flows = $pdo->query(
    "SELECT f.*, COUNT(s.id) AS step_count
     FROM approval_flows f
     LEFT JOIN approval_flow_steps s ON s.flow_id = f.id
     GROUP BY f.id ORDER BY f.module, f.min_amount"
)->fetchAll(PDO::FETCH_ASSOC);

// خواندن همه مراحل
$allSteps = $pdo->query(
    "SELECT s.*, f.name AS flow_name FROM approval_flow_steps s
     JOIN approval_flows f ON f.id = s.flow_id
     ORDER BY s.flow_id, s.step_order"
)->fetchAll(PDO::FETCH_ASSOC);

$stepsByFlow = [];
foreach ($allSteps as $s) $stepsByFlow[$s['flow_id']][] = $s;

$moduleOptions = [
    'invoice_buy'  => 'فاکتور خرید',
    'invoice_sell' => 'فاکتور فروش',
    'expense'      => 'هزینه',
    'petty_cash'   => 'تنخواه',
    'leave'        => 'مرخصی',
    'mission'      => 'مأموریت',
];

$pageTitle = 'مدیریت جریان‌های تأیید';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<div class="main-content">
<div style="max-width:900px;margin:0 auto;padding:24px 16px;">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <span style="font-size:1.3rem;font-weight:700;color:#0f172a;">⚙️ مدیریت جریان‌های تأیید</span>
    <a href="approval_inbox.php" class="fin-btn fin-btn-outline" style="font-size:.85rem;">← کارتابل تأیید</a>
  </div>

  <?php if ($msg): ?>
  <div class="fin-badge <?= $msgType==='ok'?'green':'rose' ?>" style="display:block;padding:10px 16px;margin-bottom:16px;"><?= $msg ?></div>
  <?php endif; ?>

  <!-- فرم جریان جدید -->
  <div class="fin-panel" style="margin-bottom:24px;">
    <div class="fin-panel-title">➕ جریان جدید</div>
    <form method="post" style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:flex-end;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_flow">
      <div>
        <label style="font-size:.8rem;color:#6b7280;display:block;margin-bottom:4px;">نام جریان</label>
        <input type="text" name="name" class="fin-input" style="width:100%;" placeholder="مثلاً: تأیید فاکتور خرید بالا" required>
      </div>
      <div>
        <label style="font-size:.8rem;color:#6b7280;display:block;margin-bottom:4px;">ماژول</label>
        <select name="module" class="fin-input" style="width:100%;">
          <?php foreach ($moduleOptions as $val => $label): ?>
          <option value="<?= $val ?>"><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:.8rem;color:#6b7280;display:block;margin-bottom:4px;">حداقل مبلغ (ریال)</label>
        <input type="number" name="min_amount" class="fin-input" style="width:100%;" value="0" min="0">
      </div>
      <div>
        <button type="submit" class="fin-btn fin-btn-primary">➕ اضافه</button>
      </div>
    </form>
  </div>

  <!-- لیست جریان‌ها -->
  <?php if (empty($flows)): ?>
    <div class="fin-panel" style="text-align:center;padding:32px;color:#9ca3af;">هیچ جریانی تعریف نشده</div>
  <?php else: ?>
  <?php foreach ($flows as $flow): ?>
  <div class="fin-panel" style="margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
      <div>
        <span style="font-weight:700;font-size:1rem;"><?= htmlspecialchars($flow['name']) ?></span>
        <span class="fin-badge blue" style="margin-right:8px;"><?= $moduleOptions[$flow['module']] ?? $flow['module'] ?></span>
        <?php if ($flow['min_amount'] > 0): ?>
          <span class="fin-badge amber" style="margin-right:4px;">از <?= number_format((int)$flow['min_amount']) ?> ریال</span>
        <?php endif; ?>
        <span class="fin-badge <?= $flow['is_active']?'green':'rose' ?>" style="margin-right:4px;"><?= $flow['is_active']?'فعال':'غیرفعال' ?></span>
      </div>
      <form method="post" onsubmit="return confirm('حذف شود؟');" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_flow">
        <input type="hidden" name="flow_id" value="<?= $flow['id'] ?>">
        <button type="submit" class="fin-btn" style="background:#fee2e2;color:#b91c1c;border:none;padding:5px 12px;font-size:.8rem;">🗑 حذف</button>
      </form>
    </div>

    <!-- مراحل این جریان -->
    <div style="background:#f8fafc;border-radius:10px;padding:14px;margin-bottom:12px;">
      <div style="font-size:.85rem;font-weight:600;color:#374151;margin-bottom:10px;">مراحل تأیید:</div>
      <?php $steps = $stepsByFlow[$flow['id']] ?? []; ?>
      <?php if (empty($steps)): ?>
        <div style="font-size:.82rem;color:#9ca3af;">هنوز مرحله‌ای تعریف نشده</div>
      <?php else: ?>
      <?php foreach ($steps as $step): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px dashed #e5e7eb;">
        <span style="background:#2563eb;color:#fff;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0;"><?= $step['step_order'] ?></span>
        <span style="flex:1;font-size:.88rem;"><?= htmlspecialchars($step['label'] ?: ($step['approver_type']==='role'?"نقش: {$step['approver_value']}":"کاربر #{$step['approver_value']}")) ?></span>
        <span class="fin-badge amber" style="font-size:.75rem;"><?= $step['approver_type']==='role'?'نقش':'کاربر' ?>: <?= htmlspecialchars($step['approver_value']) ?></span>
        <form method="post" onsubmit="return confirm('حذف شود؟');" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_step">
          <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
          <button type="submit" class="fin-btn" style="background:#fee2e2;color:#b91c1c;border:none;padding:3px 8px;font-size:.75rem;">✖</button>
        </form>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- افزودن مرحله -->
    <form method="post" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:8px;align-items:flex-end;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_step">
      <input type="hidden" name="flow_id" value="<?= $flow['id'] ?>">
      <div>
        <label style="font-size:.75rem;color:#6b7280;display:block;margin-bottom:3px;">شماره مرحله</label>
        <input type="number" name="step_order" class="fin-input" value="<?= count($steps)+1 ?>" min="1" style="width:100%;">
      </div>
      <div>
        <label style="font-size:.75rem;color:#6b7280;display:block;margin-bottom:3px;">نوع تأییدکننده</label>
        <select name="approver_type" class="fin-input" style="width:100%;">
          <option value="role">نقش</option>
          <option value="user">کاربر مشخص</option>
        </select>
      </div>
      <div>
        <label style="font-size:.75rem;color:#6b7280;display:block;margin-bottom:3px;">مقدار (نقش یا ID کاربر)</label>
        <input type="text" name="approver_value" class="fin-input" style="width:100%;" placeholder="مثلاً: management">
      </div>
      <div>
        <label style="font-size:.75rem;color:#6b7280;display:block;margin-bottom:3px;">برچسب مرحله</label>
        <input type="text" name="label" class="fin-input" style="width:100%;" placeholder="مثلاً: تأیید مدیریت">
      </div>
      <div>
        <button type="submit" class="fin-btn fin-btn-primary" style="white-space:nowrap;">+ مرحله</button>
      </div>
    </form>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

</div>
</div>
<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
