<?php
/*
 * فایل: public_html/admin/crm_receivables.php
 * ماژول CRM — مطالبات
 * مطالبات از فاکتورهای صادرشده خوانده می‌شود (fin_invoices)
 * تا زمانی که ماژول فاکتور پیاده‌سازی نشده، وضعیت آماده‌به‌کار نمایش داده می‌شود
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php'); exit;
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$currentUserId = (int)$_SESSION['user_id'];
$isManager = ($_SESSION['role'] ?? '') === 'admin';

// بررسی وجود جدول fin_invoices
$hasInvoices = false;
try {
    $chk = $pdo->query("SHOW TABLES LIKE 'fin_invoices'");
    $hasInvoices = $chk->rowCount() > 0;
} catch(Throwable $e) {}

// ─── AJAX ─────────────────────────────────────────────────────
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && $hasInvoices) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'get_receivables') {
        $filterUser = (int)($_POST['filter_user'] ?? 0);
        $filterProv = trim($_POST['filter_province'] ?? '');
        $dateFrom   = trim($_POST['date_from'] ?? '');
        $dateTo     = trim($_POST['date_to'] ?? '');
        $status     = trim($_POST['status'] ?? 'unpaid'); // unpaid|partial|all

        $where = ['i.is_deleted = 0', 'i.type = "sell"'];
        $params = [];
        if ($status === 'unpaid')  { $where[] = 'i.paid_amount = 0'; }
        if ($status === 'partial') { $where[] = 'i.paid_amount > 0 AND i.paid_amount < i.total_amount'; }
        if ($dateFrom) { $where[] = 'i.invoice_date >= ?'; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = 'i.invoice_date <= ?'; $params[] = $dateTo; }
        if ($filterProv) {
            $where[] = 'c.state = ?'; $params[] = $filterProv;
        }
        if (!$isManager) {
            $where[] = 'i.user_id = ?'; $params[] = $currentUserId;
        } elseif ($filterUser > 0) {
            $where[] = 'i.user_id = ?'; $params[] = $filterUser;
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount,
                   (i.total_amount - i.paid_amount) as remaining,
                   c.id as customer_id, c.company_name, c.state, c.mobile,
                   u.first_name, u.last_name
            FROM fin_invoices i
            JOIN customers c ON i.customer_id = c.id
            JOIN users u ON i.user_id = u.id
            WHERE $whereSql
            ORDER BY i.invoice_date DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = array_sum(array_column($rows,'total_amount'));
        $paid  = array_sum(array_column($rows,'paid_amount'));
        $rem   = $total - $paid;

        echo json_encode(['ok'=>true,'data'=>$rows,'stats'=>['total'=>$total,'paid'=>$paid,'remaining'=>$rem,'count'=>count($rows)]]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'عملیات نامعتبر']); exit;
}

// ─── بارگذاری صفحه ────────────────────────────────────────────
$experts = $pdo->query("SELECT id, first_name, last_name FROM users WHERE status='active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);
$provinces = [];
if ($hasInvoices) {
    try { $provinces = $pdo->query("SELECT DISTINCT state FROM customers WHERE state IS NOT NULL AND state!='' ORDER BY state")->fetchAll(PDO::FETCH_COLUMN); } catch(Throwable $e) {}
}

$pageTitle = 'مطالبات';
$basePath = '../../';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<div class="main-content" style="padding:20px;direction:rtl">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <h2 style="margin:0;font-size:16px;font-weight:700;color:#1e293b">💰 مطالبات</h2>
    <?php if ($hasInvoices): ?>
    <span style="font-size:11px;color:#16a34a;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:4px 10px">✅ اتصال به فاکتورها فعال</span>
    <?php else: ?>
    <span style="font-size:11px;color:#f59e0b;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:4px 10px">⏳ در انتظار فعال‌سازی ماژول فاکتور</span>
    <?php endif; ?>
  </div>

<?php if (!$hasInvoices): ?>
  <!-- حالت آماده‌به‌کار — ماژول فاکتور هنوز پیاده‌سازی نشده -->
  <div style="background:#fff;border:2px dashed #e2e8f0;border-radius:16px;padding:48px 32px;text-align:center">
    <div style="font-size:48px;margin-bottom:16px">📄</div>
    <h3 style="margin:0 0 12px;font-size:18px;color:#1e293b">ماژول مطالبات آماده است</h3>
    <p style="color:#64748b;font-size:14px;max-width:480px;margin:0 auto 24px">
      به محض فعال‌سازی ماژول فاکتور فروش، مطالبات به صورت خودکار از فاکتورهای صادرشده خوانده می‌شود.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center">
      <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:16px 24px;text-align:center;min-width:160px">
        <div style="font-size:22px;margin-bottom:6px">📋</div>
        <div style="font-size:12px;font-weight:700;color:#0369a1">فاکتورهای پرداخت نشده</div>
      </div>
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px 24px;text-align:center;min-width:160px">
        <div style="font-size:22px;margin-bottom:6px">⚠️</div>
        <div style="font-size:12px;font-weight:700;color:#15803d">مطالبات معوق</div>
      </div>
      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:16px 24px;text-align:center;min-width:160px">
        <div style="font-size:22px;margin-bottom:6px">📊</div>
        <div style="font-size:12px;font-weight:700;color:#92400e">فیلتر per کارشناس/استان</div>
      </div>
      <div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:10px;padding:16px 24px;text-align:center;min-width:160px">
        <div style="font-size:22px;margin-bottom:6px">🔔</div>
        <div style="font-size:12px;font-weight:700;color:#6d28d9">هشدار سررسید</div>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- فیلترها -->
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
    <div>
      <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:4px">وضعیت پرداخت:</label>
      <select id="fStatus" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit">
        <option value="unpaid">پرداخت نشده</option>
        <option value="partial">پرداخت ناقص</option>
        <option value="all">همه</option>
      </select>
    </div>
    <?php if ($isManager): ?>
    <div>
      <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:4px">کارشناس:</label>
      <select id="fUser" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit">
        <option value="0">همه</option>
        <?php foreach ($experts as $e): ?>
        <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div>
      <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:4px">استان:</label>
      <select id="fProv" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit">
        <option value="">همه استان‌ها</option>
        <?php foreach ($provinces as $pr): ?>
        <option value="<?= htmlspecialchars($pr) ?>"><?= htmlspecialchars($pr) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:4px">از تاریخ:</label>
      <input type="date" id="fDateFrom" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit">
    </div>
    <div>
      <label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:4px">تا تاریخ:</label>
      <input type="date" id="fDateTo" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:inherit">
    </div>
    <button onclick="loadReceivables()" style="padding:8px 16px;background:#0ea5e9;color:#fff;border:none;border-radius:7px;font-size:13px;font-family:inherit;font-weight:600;cursor:pointer">🔍 نمایش</button>
  </div>

  <!-- کارت‌های خلاصه -->
  <div id="statsRow" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px"></div>

  <!-- جدول مطالبات -->
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
    <div id="mtrTable">
      <div style="text-align:center;padding:48px;color:#94a3b8">فیلتر را انتخاب کنید و روی «نمایش» کلیک کنید</div>
    </div>
  </div>

<script>
function numFmt(n){return Number(n).toLocaleString('fa-IR');}

function loadReceivables() {
    var data = {
        action:'get_receivables',
        status: document.getElementById('fStatus').value,
        filter_province: document.getElementById('fProv').value,
        date_from: document.getElementById('fDateFrom').value,
        date_to: document.getElementById('fDateTo').value
    };
    var fu = document.getElementById('fUser'); if(fu) data.filter_user = fu.value;
    fetch(location.href, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body: Object.entries(data).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&')
    }).then(r=>r.json()).then(function(d){
        if (!d.ok) return;
        var s = d.stats;
        document.getElementById('statsRow').innerHTML =
            statCard('تعداد فاکتور',s.count,'📋','#0ea5e9','#f0f9ff') +
            statCard('مجموع مبلغ',numFmt(s.total)+' ریال','💰','#8b5cf6','#faf5ff') +
            statCard('پرداخت شده',numFmt(s.paid)+' ریال','✅','#16a34a','#f0fdf4') +
            statCard('مانده مطالبات',numFmt(s.remaining)+' ریال','⚠️','#dc2626','#fef2f2');
        if (!d.data.length) {
            document.getElementById('mtrTable').innerHTML = '<div style="text-align:center;padding:48px;color:#94a3b8">مطالبه‌ای یافت نشد</div>';
            return;
        }
        var html = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
        html += '<thead><tr style="background:#f8fafc;color:#64748b">';
        ['شماره فاکتور','مشتری','استان','تاریخ','مبلغ کل','پرداخت شده','مانده','کارشناس','عملیات'].forEach(function(h){
            html += '<th style="padding:10px;text-align:right;font-weight:600">'+h+'</th>';
        });
        html += '</tr></thead><tbody>';
        d.data.forEach(function(r){
            var remPct = r.total_amount > 0 ? Math.round(r.remaining/r.total_amount*100) : 0;
            var remColor = remPct > 80 ? '#dc2626' : remPct > 30 ? '#f59e0b' : '#16a34a';
            html += '<tr style="border-bottom:1px solid #f1f5f9">';
            html += '<td style="padding:10px;font-weight:600;color:#0ea5e9">#'+r.invoice_number+'</td>';
            html += '<td style="padding:10px"><a href="customer_profile.php?id='+r.customer_id+'" style="color:#1e293b;text-decoration:none;font-weight:500">'+esc(r.company_name)+'</a></td>';
            html += '<td style="padding:10px;color:#64748b">'+esc(r.state||'—')+'</td>';
            html += '<td style="padding:10px;color:#64748b;white-space:nowrap">'+esc(r.invoice_date||'')+'</td>';
            html += '<td style="padding:10px;text-align:left;font-weight:600">'+numFmt(r.total_amount)+'</td>';
            html += '<td style="padding:10px;text-align:left;color:#16a34a">'+numFmt(r.paid_amount)+'</td>';
            html += '<td style="padding:10px;text-align:left;font-weight:700;color:'+remColor+'">'+numFmt(r.remaining)+'</td>';
            html += '<td style="padding:10px;color:#64748b">'+esc(r.first_name+' '+r.last_name)+'</td>';
            html += '<td style="padding:10px"><a href="#" style="font-size:11px;color:#8b5cf6">مشاهده</a></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('mtrTable').innerHTML = html;
    });
}

function statCard(title,val,icon,color,bg) {
    return '<div style="flex:1;min-width:160px;background:'+bg+';border:1px solid '+color+'33;border-radius:10px;padding:14px 18px">'
        +'<div style="font-size:20px;margin-bottom:6px">'+icon+'</div>'
        +'<div style="font-size:11px;color:#64748b;margin-bottom:4px">'+title+'</div>'
        +'<div style="font-size:14px;font-weight:700;color:'+color+'">'+val+'</div>'
        +'</div>';
}
function esc(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
</script>

<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
