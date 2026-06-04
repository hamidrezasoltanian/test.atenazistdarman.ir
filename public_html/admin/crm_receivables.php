<?php
/*
 * فایل: public_html/admin/crm_receivables.php
 * ماژول CRM — مطالبات (دریافتنی‌ها)
 * داده از fin_invoices + fin_persons + users خوانده می‌شود
 */
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax      = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$currentUserId = (int)$_SESSION['user_id'];
$isManager   = in_array(($_SESSION['role'] ?? ''), ['admin', 'manager']);

// ─── AJAX ──────────────────────────────────────────────────────────────────────
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // ── خلاصه مشتری ──────────────────────────────────────────────
    if ($action === 'get_receivables_summary') {
        $status    = trim($_GET['status']    ?? 'all');
        $filterUser= (int)($_GET['filter_user'] ?? 0);
        $dateFrom  = trim($_GET['date_from']  ?? '');
        $dateTo    = trim($_GET['date_to']    ?? '');
        $today     = date('Y-m-d');

        $where  = ["i.is_deleted = 0", "i.type = 'sell'", "(i.total_amount - i.paid_amount) > 0"];
        $params = [];

        if ($status === 'unpaid') {
            $where[] = "i.paid_amount = 0";
        } elseif ($status === 'partial') {
            $where[] = "i.paid_amount > 0 AND i.paid_amount < i.total_amount";
        } elseif ($status === 'overdue') {
            $where[] = "i.due_date < ?";
            $where[] = "(i.total_amount - i.paid_amount) > 0";
            $params[] = $today;
        }

        if ($dateFrom) { $where[] = "i.invoice_date >= ?"; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = "i.invoice_date <= ?"; $params[] = $dateTo;   }

        if (!$isManager) {
            $where[] = "i.created_by = ?";
            $params[] = $currentUserId;
        } elseif ($filterUser > 0) {
            $where[] = "i.created_by = ?";
            $params[] = $filterUser;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT
                fp.id                                           AS person_id,
                COALESCE(fp.company_name, fp.name, i.customer_name) AS company_name,
                fp.mobile,
                COUNT(i.id)                                     AS invoice_count,
                SUM(i.total_amount)                             AS total_amount,
                SUM(i.paid_amount)                              AS paid_amount,
                SUM(i.total_amount - i.paid_amount)             AS remaining,
                MIN(i.due_date)                                 AS oldest_due_date,
                GREATEST(0, DATEDIFF(?, MIN(i.due_date)))       AS days_overdue
            FROM fin_invoices i
            LEFT JOIN fin_persons fp ON i.person_id = fp.id
            WHERE $whereSql
            GROUP BY fp.id, COALESCE(fp.company_name, fp.name, i.customer_name), fp.mobile
            ORDER BY remaining DESC
            LIMIT 500
        ";

        // DATEDIFF پارامتر اول (today)
        array_unshift($params, $today);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // آمار کلی
        $totalCustomers = count($rows);
        $totalAmount    = array_sum(array_column($rows, 'total_amount'));
        $totalPaid      = array_sum(array_column($rows, 'paid_amount'));
        $totalRemaining = array_sum(array_column($rows, 'remaining'));
        $totalOverdue   = 0;
        foreach ($rows as $r) {
            if ($r['oldest_due_date'] && $r['oldest_due_date'] < $today && $r['remaining'] > 0) {
                $totalOverdue += $r['remaining'];
            }
        }

        echo json_encode([
            'ok'   => true,
            'data' => $rows,
            'stats'=> [
                'customer_count' => $totalCustomers,
                'total_amount'   => $totalAmount,
                'total_paid'     => $totalPaid,
                'total_remaining'=> $totalRemaining,
                'total_overdue'  => $totalOverdue,
            ]
        ]);
        exit;
    }

    // ── ریز فاکتور ───────────────────────────────────────────────
    if ($action === 'get_receivables_detail') {
        $personId  = (int)($_GET['person_id'] ?? 0);
        $status    = trim($_GET['status']     ?? 'all');
        $filterUser= (int)($_GET['filter_user'] ?? 0);
        $dateFrom  = trim($_GET['date_from']   ?? '');
        $dateTo    = trim($_GET['date_to']     ?? '');
        $today     = date('Y-m-d');

        $where  = ["i.is_deleted = 0", "i.type = 'sell'", "(i.total_amount - i.paid_amount) > 0"];
        $params = [];

        if ($personId > 0) {
            $where[] = "i.person_id = ?";
            $params[] = $personId;
        }

        if ($status === 'unpaid') {
            $where[] = "i.paid_amount = 0";
        } elseif ($status === 'partial') {
            $where[] = "i.paid_amount > 0 AND i.paid_amount < i.total_amount";
        } elseif ($status === 'overdue') {
            $where[] = "i.due_date < ?";
            $params[] = $today;
        }

        if ($dateFrom) { $where[] = "i.invoice_date >= ?"; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = "i.invoice_date <= ?"; $params[] = $dateTo;   }

        if (!$isManager) {
            $where[] = "i.created_by = ?";
            $params[] = $currentUserId;
        } elseif ($filterUser > 0) {
            $where[] = "i.created_by = ?";
            $params[] = $filterUser;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT
                i.id,
                i.invoice_number,
                i.invoice_date,
                i.due_date,
                i.total_amount,
                i.paid_amount,
                (i.total_amount - i.paid_amount)                AS remaining,
                i.status,
                GREATEST(0, DATEDIFF(?, i.due_date))            AS days_overdue,
                COALESCE(fp.company_name, fp.name, i.customer_name) AS company_name,
                fp.id                                           AS person_id,
                u.full_name                                     AS expert_name
            FROM fin_invoices i
            LEFT JOIN fin_persons fp ON i.person_id = fp.id
            LEFT JOIN users u ON i.created_by = u.id
            WHERE $whereSql
            ORDER BY i.due_date ASC, i.invoice_date DESC
            LIMIT 1000
        ";

        array_unshift($params, $today);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'عملیات نامعتبر']);
    exit;
}

// ─── بارگذاری صفحه ─────────────────────────────────────────────────────────────
$experts = $pdo->query(
    "SELECT id, full_name FROM users WHERE is_deleted = 0 OR is_deleted IS NULL ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'مطالبات';
$basePath  = '../../';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<link rel="stylesheet" href="<?= $basePath ?>public_html/assets/css/fin_module.css">
<style>
:root{--red:#dc2626;--amber:#f59e0b;--green:#16a34a;--blue:#0ea5e9;--purple:#8b5cf6;}
.rcv-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:16px}
.rcv-panel-body{padding:16px 20px}
.rcv-filter-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.rcv-filter-row label{font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:4px}
.rcv-filter-row select,.rcv-filter-row input[type=date]{
    padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;
    font-size:13px;font-family:inherit;background:#fff;color:#1e293b}
.rcv-btn{padding:8px 18px;border:none;border-radius:7px;font-size:13px;font-family:inherit;font-weight:600;cursor:pointer;transition:.15s}
.rcv-btn-primary{background:var(--blue);color:#fff}
.rcv-btn-primary:hover{background:#0284c7}
.rcv-btn-sm{padding:4px 10px;font-size:11px;border-radius:5px;border:none;cursor:pointer;font-family:inherit;font-weight:600}
.rcv-stat-cards{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.rcv-stat-card{flex:1;min-width:160px;border-radius:10px;padding:14px 18px;border:1px solid transparent}
.rcv-stat-card .val{font-size:15px;font-weight:700;margin-top:4px}
.rcv-stat-card .lbl{font-size:11px;color:#64748b}
.rcv-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:0}
.rcv-tab{padding:10px 20px;font-size:13px;font-weight:600;cursor:pointer;color:#64748b;border-bottom:2px solid transparent;margin-bottom:-2px;transition:.15s;background:none;border-top:none;border-left:none;border-right:none;font-family:inherit}
.rcv-tab.active{color:var(--blue);border-bottom-color:var(--blue)}
.rcv-tbl{width:100%;border-collapse:collapse;font-size:12px}
.rcv-tbl th{padding:10px 12px;text-align:right;font-weight:600;color:#64748b;background:#f8fafc;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.rcv-tbl td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.rcv-tbl tr:last-child td{border-bottom:none}
.rcv-tbl tr:hover td{background:#fafafa}
.rcv-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700}
.badge-red{background:#fef2f2;color:var(--red)}
.badge-amber{background:#fffbeb;color:var(--amber)}
.badge-green{background:#f0fdf4;color:var(--green)}
.badge-blue{background:#f0f9ff;color:var(--blue)}
.empty-state{text-align:center;padding:48px 20px;color:#94a3b8}
.empty-state .icon{font-size:40px;margin-bottom:12px}
</style>

<div class="main-content" style="padding:20px;direction:rtl;font-family:Vazirmatn,sans-serif">

  <!-- عنوان -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <h2 style="margin:0;font-size:16px;font-weight:700;color:#1e293b">مطالبات (دریافتنی‌ها)</h2>
  </div>

  <!-- فیلترها -->
  <div class="rcv-panel">
    <div class="rcv-panel-body">
      <div class="rcv-filter-row">

        <div>
          <label>وضعیت پرداخت:</label>
          <select id="fStatus">
            <option value="all">مانده‌دار (همه)</option>
            <option value="unpaid">پرداخت نشده</option>
            <option value="partial">پرداخت ناقص</option>
            <option value="overdue">معوق (گذشته از سررسید)</option>
          </select>
        </div>

        <?php if ($isManager): ?>
        <div>
          <label>کارشناس:</label>
          <select id="fUser">
            <option value="0">همه</option>
            <?php foreach ($experts as $e): ?>
            <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div>
          <label>از تاریخ فاکتور:</label>
          <input type="date" id="fDateFrom">
        </div>
        <div>
          <label>تا تاریخ فاکتور:</label>
          <input type="date" id="fDateTo">
        </div>

        <button class="rcv-btn rcv-btn-primary" onclick="loadSummary()">نمایش</button>
      </div>
    </div>
  </div>

  <!-- کارت‌های آمار -->
  <div class="rcv-stat-cards" id="statCards">
    <div class="rcv-stat-card" style="background:#f0f9ff;border-color:#bae6fd">
      <div class="lbl">تعداد مشتری</div>
      <div class="val" id="sc-customers" style="color:var(--blue)">—</div>
    </div>
    <div class="rcv-stat-card" style="background:#faf5ff;border-color:#e9d5ff">
      <div class="lbl">مجموع مطالبات (ریال)</div>
      <div class="val" id="sc-remaining" style="color:var(--purple)">—</div>
    </div>
    <div class="rcv-stat-card" style="background:#fef2f2;border-color:#fecaca">
      <div class="lbl">معوق (ریال)</div>
      <div class="val" id="sc-overdue" style="color:var(--red)">—</div>
    </div>
    <div class="rcv-stat-card" style="background:#f0fdf4;border-color:#bbf7d0">
      <div class="lbl">دریافت‌شده از این فیلتر (ریال)</div>
      <div class="val" id="sc-paid" style="color:var(--green)">—</div>
    </div>
  </div>

  <!-- تب‌ها + محتوا -->
  <div class="rcv-panel">
    <div style="padding:0 4px;border-bottom:2px solid #e2e8f0;display:flex;gap:0">
      <button class="rcv-tab active" id="tabSummary" onclick="switchTab('summary')">خلاصه مشتری</button>
      <button class="rcv-tab" id="tabDetail"  onclick="switchTab('detail')">ریز فاکتور</button>
    </div>

    <!-- نمای خلاصه -->
    <div id="viewSummary">
      <div id="summaryContent" class="empty-state">
        <div class="icon">📊</div>
        <div>فیلتر را اعمال کنید و روی «نمایش» کلیک کنید</div>
      </div>
    </div>

    <!-- نمای ریز فاکتور -->
    <div id="viewDetail" style="display:none">
      <div id="detailContent" class="empty-state">
        <div class="icon">📄</div>
        <div>ابتدا خلاصه را بارگذاری کنید و سپس روی «دیدن فاکتورها» کلیک کنید<br>یا تب ریز فاکتور را مستقیم باز کنید</div>
      </div>
    </div>
  </div>

</div><!-- /main-content -->

<script>
var currentTab = 'summary';
var lastFilters = {};

/* ── ابزارها ── */
function numFmt(n){ return Number(n||0).toLocaleString('fa-IR'); }
function esc(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function getFilters(){
    var f = {
        status:    document.getElementById('fStatus').value,
        date_from: document.getElementById('fDateFrom').value,
        date_to:   document.getElementById('fDateTo').value
    };
    var fu = document.getElementById('fUser'); if(fu) f.filter_user = fu.value;
    return f;
}
function buildQS(obj){
    return Object.entries(obj).map(function(kv){ return encodeURIComponent(kv[0])+'='+encodeURIComponent(kv[1]); }).join('&');
}

/* ── سوئیچ تب ── */
function switchTab(tab){
    currentTab = tab;
    document.getElementById('viewSummary').style.display = tab==='summary' ? '' : 'none';
    document.getElementById('viewDetail').style.display  = tab==='detail'  ? '' : 'none';
    document.getElementById('tabSummary').classList.toggle('active', tab==='summary');
    document.getElementById('tabDetail').classList.toggle('active',  tab==='detail');
    if(tab==='detail' && Object.keys(lastFilters).length){
        loadDetail(0);
    }
}

/* ── بارگذاری خلاصه ── */
function loadSummary(){
    lastFilters = getFilters();
    document.getElementById('summaryContent').innerHTML = '<div class="empty-state"><div class="icon" style="font-size:28px">⏳</div><div>در حال بارگذاری...</div></div>';
    fetch(location.pathname + '?action=get_receivables_summary&' + buildQS(lastFilters), {
        headers: {'X-Requested-With':'XMLHttpRequest'}
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(!d.ok){ alert(d.msg||'خطا'); return; }
        var s = d.stats;
        document.getElementById('sc-customers').textContent = numFmt(s.customer_count);
        document.getElementById('sc-remaining').textContent = numFmt(s.total_remaining);
        document.getElementById('sc-overdue').textContent   = numFmt(s.total_overdue);
        document.getElementById('sc-paid').textContent      = numFmt(s.total_paid);

        if(!d.data.length){
            document.getElementById('summaryContent').innerHTML = '<div class="empty-state"><div class="icon">🔍</div><div>مطالبه‌ای یافت نشد</div></div>';
            return;
        }
        var today = new Date(); today.setHours(0,0,0,0);
        var html = '<div style="overflow-x:auto"><table class="rcv-tbl"><thead><tr>'
            +'<th>شرکت / مشتری</th><th>موبایل</th><th>تعداد فاکتور</th>'
            +'<th style="text-align:left">مجموع (ریال)</th>'
            +'<th style="text-align:left">پرداخت‌شده (ریال)</th>'
            +'<th style="text-align:left">مانده (ریال)</th>'
            +'<th>قدیمی‌ترین سررسید</th><th>عملیات</th>'
            +'</tr></thead><tbody>';

        d.data.forEach(function(r){
            var isOverdue = r.oldest_due_date && r.days_overdue > 0;
            var remColor  = isOverdue ? 'var(--red)' : (r.paid_amount > 0 ? 'var(--amber)' : '#1e293b');
            var dueBadge  = '';
            if(isOverdue){
                dueBadge = ' <span class="rcv-badge badge-red">'+numFmt(r.days_overdue)+' روز</span>';
            }
            html += '<tr>'
                + '<td style="font-weight:600;color:#1e293b">'+esc(r.company_name||'—')+'</td>'
                + '<td style="color:#64748b;direction:ltr;text-align:right">'+esc(r.mobile||'—')+'</td>'
                + '<td style="text-align:center"><span class="rcv-badge badge-blue">'+numFmt(r.invoice_count)+'</span></td>'
                + '<td style="text-align:left;font-weight:600">'+numFmt(r.total_amount)+'</td>'
                + '<td style="text-align:left;color:var(--green)">'+numFmt(r.paid_amount)+'</td>'
                + '<td style="text-align:left;font-weight:700;color:'+remColor+'">'+numFmt(r.remaining)+'</td>'
                + '<td style="white-space:nowrap;color:'+(isOverdue?'var(--red)':'#64748b')+'">'+esc(r.oldest_due_date||'—')+dueBadge+'</td>'
                + '<td><button class="rcv-btn-sm" style="background:#ede9fe;color:var(--purple)" onclick="showDetailFor('+r.person_id+')">فاکتورها</button></td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('summaryContent').innerHTML = html;
    })
    .catch(function(e){ console.error(e); alert('خطا در دریافت داده'); });
}

/* ── نمایش جزئیات یک مشتری خاص ── */
function showDetailFor(personId){
    switchTab('detail');
    loadDetail(personId);
}

/* ── بارگذاری ریز فاکتور ── */
function loadDetail(personId){
    var filters = Object.assign({}, lastFilters || getFilters(), {person_id: personId||0});
    document.getElementById('detailContent').innerHTML = '<div class="empty-state"><div class="icon" style="font-size:28px">⏳</div><div>در حال بارگذاری...</div></div>';
    fetch(location.pathname + '?action=get_receivables_detail&' + buildQS(filters), {
        headers: {'X-Requested-With':'XMLHttpRequest'}
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(!d.ok){ alert(d.msg||'خطا'); return; }
        if(!d.data.length){
            document.getElementById('detailContent').innerHTML = '<div class="empty-state"><div class="icon">🔍</div><div>فاکتوری یافت نشد</div></div>';
            return;
        }
        var html = '<div style="overflow-x:auto"><table class="rcv-tbl"><thead><tr>'
            +'<th>شماره</th><th>شرکت / مشتری</th><th>تاریخ فاکتور</th>'
            +'<th>سررسید</th>'
            +'<th style="text-align:left">مبلغ (ریال)</th>'
            +'<th style="text-align:left">پرداخت (ریال)</th>'
            +'<th style="text-align:left">مانده (ریال)</th>'
            +'<th>روز معوق</th><th>کارشناس</th><th>عملیات</th>'
            +'</tr></thead><tbody>';

        d.data.forEach(function(r){
            var overdue   = parseInt(r.days_overdue||0, 10);
            var isOverdue = overdue > 0;
            var remColor  = isOverdue ? 'var(--red)' : (r.paid_amount > 0 ? 'var(--amber)' : '#1e293b');
            var overdueCell = isOverdue
                ? '<span class="rcv-badge badge-red">'+numFmt(overdue)+' روز</span>'
                : '<span style="color:#94a3b8">—</span>';

            html += '<tr>'
                + '<td style="font-weight:600;color:var(--blue)">#'+esc(r.invoice_number)+'</td>'
                + '<td style="color:#1e293b">'+esc(r.company_name||'—')+'</td>'
                + '<td style="color:#64748b;white-space:nowrap">'+esc(r.invoice_date||'—')+'</td>'
                + '<td style="white-space:nowrap;color:'+(isOverdue?'var(--red)':'#64748b')+'">'+esc(r.due_date||'—')+'</td>'
                + '<td style="text-align:left;font-weight:600">'+numFmt(r.total_amount)+'</td>'
                + '<td style="text-align:left;color:var(--green)">'+numFmt(r.paid_amount)+'</td>'
                + '<td style="text-align:left;font-weight:700;color:'+remColor+'">'+numFmt(r.remaining)+'</td>'
                + '<td style="text-align:center">'+overdueCell+'</td>'
                + '<td style="color:#64748b;font-size:11px">'+esc(r.expert_name||'—')+'</td>'
                + '<td style="white-space:nowrap">'
                +   '<a href="fin_invoice_pdf.php?id='+r.id+'&type=sell" target="_blank" class="rcv-btn-sm" style="background:#f0f9ff;color:var(--blue);text-decoration:none;display:inline-block;margin-left:4px">PDF</a>'
                +   '<a href="fin_receive_pay.php?invoice_id='+r.id+'" class="rcv-btn-sm" style="background:#f0fdf4;color:var(--green);text-decoration:none;display:inline-block">ثبت دریافت</a>'
                + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('detailContent').innerHTML = html;
    })
    .catch(function(e){ console.error(e); alert('خطا در دریافت داده'); });
}

/* بارگذاری اولیه */
loadSummary();
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
