<?php
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/approval.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }

$userId   = (int)$_SESSION['user_id'];
$userRole = strtolower($_SESSION['role'] ?? '');
$isAjax   = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

// ── AJAX: اقدام تأیید/رد ──
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json');
    csrf_verify();

    $action    = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);
    $notes     = trim($_POST['notes'] ?? '');

    if (!$requestId) { echo json_encode(['ok'=>false,'msg'=>'درخواست نامعتبر']); exit; }
    if (!approvalCanAct($pdo, $requestId, $userId, $userRole)) {
        echo json_encode(['ok'=>false,'msg'=>'دسترسی تأیید این درخواست را ندارید']); exit;
    }

    if (!in_array($action, ['approved','rejected'], true)) {
        echo json_encode(['ok'=>false,'msg'=>'اقدام نامعتبر']); exit;
    }

    try {
        $result = approvalProcess($pdo, $requestId, $userId, $action, $notes);

        // اگر فاکتور خرید تأیید نهایی شد، آن را confirm کن
        if ($result['completed'] && $result['final'] === 'approved') {
            $refType = $result['ref_type'];
            $refId   = $result['ref_id'];

            if ($refType === 'invoice_buy') {
                $pdo->prepare("UPDATE fin_invoices SET status='confirmed', updated_at=NOW() WHERE id=? AND is_deleted=0 AND type='buy'")
                    ->execute([$refId]);
                logActivity($pdo, $userId, 'invoice_buy_approved', "فاکتور خرید #$refId پس از تأیید کارتابل confirm شد");
            } elseif ($refType === 'invoice_sell') {
                $pdo->prepare("UPDATE fin_invoices SET status='confirmed', updated_at=NOW() WHERE id=? AND is_deleted=0 AND type='sell'")
                    ->execute([$refId]);
                logActivity($pdo, $userId, 'invoice_sell_approved', "فاکتور فروش #$refId پس از تأیید کارتابل confirm شد");
            } elseif ($refType === 'expense') {
                $pdo->prepare("UPDATE fin_expenses SET status='approved', updated_at=NOW() WHERE id=?")
                    ->execute([$refId]);
            }

            // اطلاع‌رسانی تلگرام به درخواست‌کننده اگر موجود باشد
            try {
                require_once __DIR__ . '/../../includes/telegram.php';
                $reqRow = $pdo->prepare("SELECT requested_by, ref_type, total_amount FROM approval_requests WHERE id=?");
                $reqRow->execute([$requestId]);
                $reqData = $reqRow->fetch(PDO::FETCH_ASSOC);
                if ($reqData) {
                    $moduleLabel = approvalModuleLabel($refType);
                    $amountFmt   = number_format((int)$reqData['total_amount']);
                    $tgMsg = "✅ درخواست تأیید شما موافقت شد\n📋 $moduleLabel #$refId\n💰 مبلغ: $amountFmt ریال";
                    telegramSend('', $tgMsg, $pdo);
                }
            } catch (Throwable $ignored) {}
        }

        echo json_encode($result);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'msg'=>'خطای سرور: '.$e->getMessage()]);
    }
    exit;
}

// ── لیست درخواست‌های قابل اقدام ──
$pending = approvalGetPending($pdo, $userId, $userRole);

// همه درخواست‌های اخیر (برای مدیران)
$allRecent = [];
if (in_array($userRole, ['admin','management'], true)) {
    $st = $pdo->prepare(
        "SELECT ar.*, u.full_name AS requester_name, af.name AS flow_name, af.module
         FROM approval_requests ar
         LEFT JOIN users u ON u.id = ar.requested_by
         LEFT JOIN approval_flows af ON af.id = ar.flow_id
         ORDER BY ar.updated_at DESC LIMIT 50"
    );
    $st->execute();
    $allRecent = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'کارتابل تأیید';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<div class="main-content">
<div style="max-width:1100px;margin:0 auto;padding:24px 16px;">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <div>
      <span style="font-size:1.3rem;font-weight:700;color:#0f172a;">✅ کارتابل تأیید</span>
      <?php if (count($pending) > 0): ?>
        <span class="fin-badge rose" style="margin-right:10px;"><?= count($pending) ?> در انتظار</span>
      <?php endif; ?>
    </div>
    <?php if (in_array($userRole, ['admin','management'], true)): ?>
    <a href="approval_flows.php" class="fin-btn fin-btn-outline" style="font-size:.85rem;">⚙️ مدیریت جریان‌های تأیید</a>
    <?php endif; ?>
  </div>

  <!-- درخواست‌های قابل اقدام -->
  <div class="fin-panel" style="margin-bottom:24px;">
    <div class="fin-panel-title">⏳ در انتظار اقدام شما (<?= count($pending) ?>)</div>
    <?php if (empty($pending)): ?>
      <div style="text-align:center;padding:32px;color:#9ca3af;">هیچ درخواستی در انتظار تأیید شما نیست ✔️</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="fin-table" style="width:100%;">
      <thead>
        <tr>
          <th>#</th>
          <th>نوع</th>
          <th>مبلغ (ریال)</th>
          <th>درخواست‌دهنده</th>
          <th>مرحله فعلی</th>
          <th>تاریخ ثبت</th>
          <th>اقدام</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending as $req): ?>
        <tr>
          <td><a href="<?= approvalRefLink($req['ref_type'], (int)$req['ref_id']) ?>" style="color:#2563eb;font-weight:600;">#<?= $req['ref_id'] ?></a></td>
          <td><span class="fin-badge blue"><?= approvalModuleLabel($req['ref_type'] ?? $req['module'] ?? '') ?></span></td>
          <td style="direction:ltr;text-align:right;font-weight:600;"><?= number_format((int)$req['total_amount']) ?></td>
          <td><?= htmlspecialchars($req['requester_name'] ?? '—') ?></td>
          <td><?= htmlspecialchars($req['step_label'] ?? "مرحله {$req['current_step']}") ?></td>
          <td><?= jdate('Y/m/d', strtotime($req['created_at'])) ?></td>
          <td style="white-space:nowrap;">
            <button onclick="doAction(<?= $req['id'] ?>,'approved')" class="fin-btn fin-btn-primary" style="padding:5px 12px;font-size:.8rem;">✔ تأیید</button>
            <button onclick="doAction(<?= $req['id'] ?>,'rejected')" class="fin-btn" style="padding:5px 12px;font-size:.8rem;background:#fee2e2;color:#b91c1c;border:none;">✖ رد</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- تاریخچه کامل (فقط مدیران) -->
  <?php if (!empty($allRecent)): ?>
  <div class="fin-panel">
    <div class="fin-panel-title">📋 تاریخچه کامل درخواست‌ها</div>
    <div style="overflow-x:auto;">
    <table class="fin-table" style="width:100%;">
      <thead>
        <tr>
          <th>#</th>
          <th>نوع</th>
          <th>مبلغ</th>
          <th>درخواست‌دهنده</th>
          <th>وضعیت</th>
          <th>تاریخ</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allRecent as $req): ?>
        <tr>
          <td><a href="<?= approvalRefLink($req['ref_type'], (int)$req['ref_id']) ?>" style="color:#2563eb;">#<?= $req['ref_id'] ?></a></td>
          <td><?= approvalModuleLabel($req['ref_type'] ?? $req['module'] ?? '') ?></td>
          <td style="direction:ltr;text-align:right;"><?= number_format((int)$req['total_amount']) ?></td>
          <td><?= htmlspecialchars($req['requester_name'] ?? '—') ?></td>
          <td><?= approvalBadge($req['status']) ?></td>
          <td><?= jdate('Y/m/d', strtotime($req['updated_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

</div>
</div>

<!-- مودال یادداشت -->
<div id="actionModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;justify-content:center;align-items:center;">
  <div style="background:#fff;border-radius:16px;padding:28px;width:90%;max-width:440px;box-shadow:0 20px 40px rgba(0,0,0,.2);">
    <h3 id="modalTitle" style="margin:0 0 16px;font-size:1.1rem;color:#0f172a;"></h3>
    <textarea id="modalNotes" rows="3" placeholder="یادداشت (اختیاری)..." style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-family:inherit;font-size:.9rem;resize:vertical;box-sizing:border-box;"></textarea>
    <div style="display:flex;gap:10px;margin-top:16px;">
      <button id="modalConfirmBtn" style="flex:1;padding:10px;border:none;border-radius:8px;cursor:pointer;font-family:inherit;font-size:.9rem;font-weight:600;color:#fff;">تأیید</button>
      <button onclick="document.getElementById('actionModal').style.display='none'" style="flex:1;padding:10px;border:1px solid #d1d5db;border-radius:8px;cursor:pointer;font-family:inherit;font-size:.9rem;background:#f9fafb;">انصراف</button>
    </div>
  </div>
</div>

<script>
let _pendingAction = null, _pendingId = null;

function doAction(requestId, action) {
    _pendingId     = requestId;
    _pendingAction = action;
    const modal = document.getElementById('actionModal');
    document.getElementById('modalTitle').textContent = action === 'approved' ? '✔ تأیید درخواست' : '✖ رد درخواست';
    document.getElementById('modalNotes').value = '';
    const btn = document.getElementById('modalConfirmBtn');
    btn.style.background = action === 'approved' ? '#16a34a' : '#dc2626';
    btn.textContent = action === 'approved' ? 'تأیید کن' : 'رد کن';
    modal.style.display = 'flex';
}

document.getElementById('modalConfirmBtn').addEventListener('click', function() {
    if (!_pendingId) return;
    const notes = document.getElementById('modalNotes').value.trim();
    const btn = this;
    btn.disabled = true; btn.textContent = '⏳ در حال پردازش...';

    const fd = new FormData();
    fd.append('action', _pendingAction);
    fd.append('request_id', _pendingId);
    fd.append('notes', notes);
    fd.append('csrf_token', '<?= csrf_token() ?>');

    fetch(window.location.href, {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        document.getElementById('actionModal').style.display = 'none';
        if (d.ok) {
            location.reload();
        } else {
            alert('❌ ' + (d.msg || 'خطا'));
            btn.disabled = false; btn.textContent = _pendingAction === 'approved' ? 'تأیید کن' : 'رد کن';
        }
    })
    .catch(() => { alert('❌ خطای شبکه'); btn.disabled = false; });
});
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
