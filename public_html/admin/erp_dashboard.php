<?php
// داشبورد یکپارچه ERP — آتنا زیست درمان
$root = dirname(__DIR__, 2);
require_once $root . '/Config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';

requireLogin();
$basePath = '../../';

// ── AJAX ──────────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_GET['action'] ?? '';

    if ($act === 'summary') {
        $out = [];

        // ── CRM ──
        try {
            $opps   = (int)$pdo->query('SELECT COUNT(*) FROM crm_opportunities WHERE is_deleted=0')->fetchColumn();
            $wonSql = "SELECT COUNT(*) FROM crm_opportunities o JOIN crm_board_stages s ON s.id=o.stage_id WHERE o.is_deleted=0 AND s.name LIKE '%بسته%'";
            $won    = (int)$pdo->query($wonSql)->fetchColumn();
            $totalV = (int)$pdo->query('SELECT COALESCE(SUM(value),0) FROM crm_opportunities WHERE is_deleted=0')->fetchColumn();
            $out['crm'] = ['opps' => $opps, 'won' => $won, 'total_value' => $totalV];
        } catch (Exception $e) { $out['crm'] = ['opps'=>0,'won'=>0,'total_value'=>0]; }

        // ── مالی ──
        try {
            $inv = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) t, COALESCE(SUM(paid_amount),0) p FROM fin_invoices WHERE type='sell' AND is_deleted=0")->fetch(PDO::FETCH_ASSOC);
            $chq = (int)$pdo->query("SELECT COUNT(*) FROM fin_cheques WHERE status='pending' AND is_deleted=0")->fetchColumn();
            $out['fin'] = ['invoices'=>(int)$inv['c'], 'revenue'=>(int)$inv['t'], 'collected'=>(int)$inv['p'], 'pending_cheques'=>$chq];
        } catch (Exception $e) { $out['fin'] = ['invoices'=>0,'revenue'=>0,'collected'=>0,'pending_cheques'=>0]; }

        // ── انبار ──
        try {
            $prods    = (int)$pdo->query('SELECT COUNT(*) FROM stuffs WHERE is_active=1')->fetchColumn();
            $lowstock = (int)$pdo->query('SELECT COUNT(*) FROM stuffs s JOIN stuff_price_list spl ON spl.stuff_id=s.id WHERE s.is_active=1 AND spl.total_inventory<=COALESCE(spl.minimum_stock,5) AND spl.total_inventory>0')->fetchColumn();
            $out['inv'] = ['products' => $prods, 'low_stock' => $lowstock];
        } catch (Exception $e) { $out['inv'] = ['products'=>0,'low_stock'=>0]; }

        // ── HR ──
        try {
            $leaves   = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
            $missions = (int)$pdo->query("SELECT COUNT(*) FROM mission_requests WHERE status='pending'")->fetchColumn();
            $users    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
            $out['hr'] = ['pending_leaves'=>$leaves,'pending_missions'=>$missions,'active_users'=>$users];
        } catch (Exception $e) { $out['hr'] = ['pending_leaves'=>0,'pending_missions'=>0,'active_users'=>0]; }

        // ── فعالیت‌های اخیر ──
        $activities = [];
        try {
            $rows = $pdo->query(
                "SELECT 'inv' AS src, t.ticket_number num, t.type tp, t.created_at dt, u.name uname
                 FROM inv_tickets t LEFT JOIN users u ON u.id=t.created_by WHERE t.is_deleted=0
                 UNION ALL
                 SELECT 'fin', i.invoice_number, i.type, i.created_at, u.name
                 FROM fin_invoices i LEFT JOIN users u ON u.id=i.created_by WHERE i.is_deleted=0
                 UNION ALL
                 SELECT 'crm', o.title, 'opportunity', o.created_at, u.name
                 FROM crm_opportunities o LEFT JOIN users u ON u.id=o.assigned_to WHERE o.is_deleted=0
                 ORDER BY dt DESC LIMIT 12"
            )->fetchAll(PDO::FETCH_ASSOC);
            $activities = $rows;
        } catch (Exception $e) {}

        $out['activities'] = $activities;
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'monthly_chart') {
        // درآمد ماهانه ۶ ماه اخیر
        $months = [];
        try {
            $rows = $pdo->query(
                "SELECT DATE_FORMAT(STR_TO_DATE(invoice_date,'%Y/%m/%d'),'%Y-%m') ym,
                        COALESCE(SUM(total_amount),0) total
                 FROM fin_invoices WHERE type='sell' AND is_deleted=0
                   AND STR_TO_DATE(invoice_date,'%Y/%m/%d') >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                 GROUP BY ym ORDER BY ym"
            )->fetchAll(PDO::FETCH_ASSOC);
            $months = $rows;
        } catch (Exception $e) {}
        echo json_encode(['months' => $months], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['error' => 'action نامعتبر'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── مقادیر اولیه برای render ──────────────────────────────
$pageTitle = 'داشبورد یکپارچه ERP';
$extraCss = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';

ob_start();
require_once $root . '/templates/header.php';
$headerHtml = ob_get_clean();
echo $headerHtml;
require_once $root . '/templates/sidebar.php';
?>

<div class="content-wrapper" style="min-height:100vh;background:var(--bg-secondary,#f1f5f9);">

<!-- ── نوار وضعیت زنده ──────────────────────────────────── -->
<div id="erpAlertBar" style="
    background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
    color:#fff;padding:10px 24px;display:flex;align-items:center;
    justify-content:space-between;gap:16px;font-size:.85rem;flex-wrap:wrap;">
  <div style="display:flex;align-items:center;gap:16px;">
    <span style="font-size:1.2rem;">🏢</span>
    <strong>مرکز فرماندهی ERP</strong>
    <span id="liveTime" style="opacity:.85;font-family:monospace;direction:ltr;"></span>
  </div>
  <div id="alertMessages" style="display:flex;gap:10px;flex-wrap:wrap;"></div>
</div>

<div style="padding:24px;">

<!-- ── عنوان ────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="margin:0;font-size:1.5rem;font-weight:700;color:var(--text-primary,#1e293b);">داشبورد یکپارچه</h1>
    <p style="margin:4px 0 0;color:var(--text-muted,#64748b);font-size:.875rem;">نمای کلی تمام ماژول‌های سیستم</p>
  </div>
  <a href="<?= $basePath ?>admin/testing_agent.php" style="
      display:inline-flex;align-items:center;gap:6px;padding:8px 16px;
      background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;
      border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600;">
    🤖 اجرای تست‌ها
  </a>
</div>

<!-- ── کارت‌های خلاصه ────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:28px;">

  <!-- CRM -->
  <div class="erp-module-card" data-mod="crm" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);">
    <div class="emc-icon">👥</div>
    <div class="emc-label">CRM — فرصت‌ها</div>
    <div class="emc-value" id="crmOpps">—</div>
    <div class="emc-sub" id="crmWon">در حال بارگذاری...</div>
    <a href="<?= $basePath ?>admin/crm_kanban.php" class="emc-link">بورد کانبان ←</a>
  </div>

  <!-- مالی -->
  <div class="erp-module-card" data-mod="fin" style="background:linear-gradient(135deg,#11998e 0%,#38ef7d 100%);">
    <div class="emc-icon">💰</div>
    <div class="emc-label">حسابداری — فاکتورها</div>
    <div class="emc-value" id="finInvoices">—</div>
    <div class="emc-sub" id="finRevenue">در حال بارگذاری...</div>
    <a href="<?= $basePath ?>admin/fin_invoice_sell.php" class="emc-link">فاکتور فروش ←</a>
  </div>

  <!-- انبار -->
  <div class="erp-module-card" data-mod="inv" style="background:linear-gradient(135deg,#f093fb 0%,#f5576c 100%);">
    <div class="emc-icon">📦</div>
    <div class="emc-label">انبار — محصولات</div>
    <div class="emc-value" id="invProducts">—</div>
    <div class="emc-sub" id="invLow">در حال بارگذاری...</div>
    <a href="<?= $basePath ?>admin/inv_dashboard.php" class="emc-link">داشبورد انبار ←</a>
  </div>

  <!-- HR -->
  <div class="erp-module-card" data-mod="hr" style="background:linear-gradient(135deg,#4facfe 0%,#00f2fe 100%);">
    <div class="emc-icon">🏖️</div>
    <div class="emc-label">HR — درخواست‌های معلق</div>
    <div class="emc-value" id="hrPending">—</div>
    <div class="emc-sub" id="hrUsers">در حال بارگذاری...</div>
    <a href="<?= $basePath ?>admin/leave_requests.php" class="emc-link">مرخصی‌ها ←</a>
  </div>

  <!-- چک‌های معلق -->
  <div class="erp-module-card" data-mod="cheque" style="background:linear-gradient(135deg,#f7971e 0%,#ffd200 100%);">
    <div class="emc-icon">🧾</div>
    <div class="emc-label">چک‌های در جریان وصول</div>
    <div class="emc-value" id="finCheques">—</div>
    <div class="emc-sub">منتظر وصول</div>
    <a href="<?= $basePath ?>admin/fin_cheques.php" class="emc-link">مدیریت چک‌ها ←</a>
  </div>

  <!-- دسترسی سریع API -->
  <div class="erp-module-card" data-mod="api" style="background:linear-gradient(135deg,#30cfd0 0%,#330867 100%);">
    <div class="emc-icon">🔌</div>
    <div class="emc-label">REST API v1</div>
    <div class="emc-value" style="font-size:1rem;">فعال</div>
    <div class="emc-sub">/api/v1/dashboard/summary</div>
    <a href="<?= $basePath ?>admin/testing_agent.php" class="emc-link">تست API ←</a>
  </div>

</div>

<!-- ── نمودار + فعالیت‌های اخیر ──────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px;">

  <!-- نمودار درآمد ماهانه -->
  <div style="background:#fff;border-radius:16px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06);">
    <h3 style="margin:0 0 16px;font-size:1rem;color:#1e293b;">📊 درآمد ماهانه (۶ ماه اخیر)</h3>
    <canvas id="revenueChart" height="180"></canvas>
    <p id="noChartData" style="text-align:center;color:#94a3b8;font-size:.85rem;display:none;">داده‌ای یافت نشد</p>
  </div>

  <!-- نمودار دونات وضعیت CRM -->
  <div style="background:#fff;border-radius:16px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06);">
    <h3 style="margin:0 0 16px;font-size:1rem;color:#1e293b;">🎯 وضعیت فرصت‌های CRM</h3>
    <canvas id="crmChart" height="180"></canvas>
  </div>

</div>

<!-- ── دسترسی سریع + فعالیت‌های اخیر ────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:20px;margin-bottom:28px;">

  <!-- دسترسی سریع -->
  <div style="background:#fff;border-radius:16px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06);">
    <h3 style="margin:0 0 16px;font-size:1rem;color:#1e293b;">⚡ دسترسی سریع</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
      <?php
      $shortcuts = [
        ['👥','فرصت جدید','admin/crm_opportunities.php','#667eea'],
        ['🧾','فاکتور فروش','admin/fin_invoice_sell.php','#11998e'],
        ['📦','رسید انبار','admin/inv_receipts.php','#f5576c'],
        ['💳','مدیریت چک','admin/fin_cheques.php','#f7971e'],
        ['👤','طرف حساب','admin/fin_persons.php','#4facfe'],
        ['📊','گزارش مالی','admin/fin_reports.php','#a18cd1'],
        ['📈','گزارش CRM','admin/crm_reports.php','#fda085'],
        ['🤖','ایجنت تست','admin/testing_agent.php','#30cfd0'],
      ];
      foreach ($shortcuts as [$icon, $label, $href, $color]):
      ?>
      <a href="<?= $basePath . $href ?>" style="
          display:flex;align-items:center;gap:8px;padding:10px 12px;
          background:<?= $color ?>18;border:1px solid <?= $color ?>30;
          border-radius:10px;text-decoration:none;color:#1e293b;
          font-size:.8rem;font-weight:500;transition:.2s;
          " onmouseover="this.style.background='<?= $color ?>30'" onmouseout="this.style.background='<?= $color ?>18'">
        <span style="font-size:1.1rem;"><?= $icon ?></span>
        <span><?= $label ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- فعالیت‌های اخیر -->
  <div style="background:#fff;border-radius:16px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06);">
    <h3 style="margin:0 0 16px;font-size:1rem;color:#1e293b;">⏱️ آخرین فعالیت‌ها</h3>
    <div id="activityFeed" style="max-height:300px;overflow-y:auto;">
      <div style="text-align:center;padding:32px;color:#94a3b8;">در حال بارگذاری...</div>
    </div>
  </div>

</div>

<!-- ── وضعیت سیستم ────────────────────────────────────────── -->
<div style="background:#fff;border-radius:16px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06);margin-bottom:28px;">
  <h3 style="margin:0 0 16px;font-size:1rem;color:#1e293b;">🏥 وضعیت ماژول‌ها</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;" id="moduleStatusGrid">
    <?php
    $modules = [
      ['CRM','admin/crm_kanban.php','#667eea'],
      ['حسابداری','admin/fin_accounting_dashboard.php','#11998e'],
      ['انبارداری','admin/inv_dashboard.php','#f5576c'],
      ['HR','admin/leave_requests.php','#4facfe'],
      ['گزارشات','admin/fin_reports.php','#a18cd1'],
      ['اتوماسیون','admin/letters.php','#fda085'],
    ];
    foreach ($modules as [$name, $href, $color]):
    ?>
    <a href="<?= $basePath . $href ?>" style="
        display:flex;flex-direction:column;align-items:center;gap:6px;
        padding:16px 12px;background:<?= $color ?>10;border:2px solid <?= $color ?>30;
        border-radius:12px;text-decoration:none;color:#1e293b;text-align:center;
        font-size:.8rem;font-weight:600;transition:.2s;"
       onmouseover="this.style.borderColor='<?= $color ?>'" onmouseout="this.style.borderColor='<?= $color ?>30'">
      <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#22c55e;box-shadow:0 0 6px #22c55e88;"></span>
      <?= $name ?>
      <span style="color:#22c55e;font-size:.7rem;">فعال</span>
    </a>
    <?php endforeach; ?>
  </div>
</div>

</div><!-- /padding -->
</div><!-- /content-wrapper -->

<!-- ── استایل کارت‌های ماژول ─────────────────────────────── -->
<style>
.erp-module-card {
  border-radius: 16px;
  padding: 22px 20px 16px;
  color: #fff;
  display: flex;
  flex-direction: column;
  gap: 4px;
  box-shadow: 0 4px 20px rgba(0,0,0,.15);
  transition: transform .2s, box-shadow .2s;
  position: relative;
  overflow: hidden;
}
.erp-module-card::before {
  content: '';
  position: absolute;
  top: -20px; right: -20px;
  width: 80px; height: 80px;
  background: rgba(255,255,255,.15);
  border-radius: 50%;
}
.erp-module-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,.2); }
.emc-icon { font-size: 1.8rem; margin-bottom: 4px; }
.emc-label { font-size: .75rem; opacity: .85; font-weight: 500; }
.emc-value { font-size: 2rem; font-weight: 700; line-height: 1.1; }
.emc-sub { font-size: .75rem; opacity: .8; min-height: 1.1em; }
.emc-link {
  display: inline-block;
  margin-top: 10px;
  padding: 6px 12px;
  background: rgba(255,255,255,.2);
  border-radius: 20px;
  font-size: .75rem;
  font-weight: 600;
  color: #fff;
  text-decoration: none;
  align-self: flex-start;
  transition: background .2s;
}
.emc-link:hover { background: rgba(255,255,255,.35); }

.activity-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid #f1f5f9;
  font-size: .82rem;
}
.activity-item:last-child { border-bottom: none; }
.act-badge {
  padding: 3px 8px;
  border-radius: 20px;
  font-size: .7rem;
  font-weight: 600;
  white-space: nowrap;
}
.act-crm  { background:#667eea20;color:#667eea; }
.act-fin  { background:#11998e20;color:#0a7c6b; }
.act-inv  { background:#f5576c20;color:#d63051; }
</style>

<!-- ── Chart.js ───────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const BASE = '<?= rtrim($basePath, '/') ?>';
let revenueChart = null, crmChart = null;

// ── ساعت زنده ──────────────────────────────────────────────
function updateClock() {
  const now = new Date();
  document.getElementById('liveTime').textContent =
    now.toLocaleDateString('fa-IR') + '  ' +
    now.toLocaleTimeString('fa-IR');
}
setInterval(updateClock, 1000);
updateClock();

// ── بارگذاری خلاصه ─────────────────────────────────────────
function loadSummary() {
  $.getJSON(location.pathname + '?action=summary', function(d) {
    // CRM
    if (d.crm) {
      $('#crmOpps').text(d.crm.opps || 0);
      $('#crmWon').text('بسته شده: ' + (d.crm.won || 0) + ' | ارزش: ' + formatMoney(d.crm.total_value));
    }
    // مالی
    if (d.fin) {
      $('#finInvoices').text(d.fin.invoices || 0);
      $('#finRevenue').text('درآمد: ' + formatMoney(d.fin.revenue));
      $('#finCheques').text(d.fin.pending_cheques || 0);
    }
    // انبار
    if (d.inv) {
      $('#invProducts').text(d.inv.products || 0);
      const low = d.inv.low_stock || 0;
      $('#invLow').text(low > 0 ? '⚠️ ' + low + ' قلم کم‌موجود' : '✅ موجودی سالم');
    }
    // HR
    if (d.hr) {
      const pending = (d.hr.pending_leaves || 0) + (d.hr.pending_missions || 0);
      $('#hrPending').text(pending);
      $('#hrUsers').text('کاربران فعال: ' + (d.hr.active_users || 0));
    }

    // نوار هشدار
    const alerts = [];
    if (d.fin && d.fin.pending_cheques > 0)
      alerts.push('<span style="background:rgba(255,255,255,.2);padding:3px 10px;border-radius:12px;">🧾 ' + d.fin.pending_cheques + ' چک در انتظار وصول</span>');
    if (d.inv && d.inv.low_stock > 0)
      alerts.push('<span style="background:rgba(255,100,100,.3);padding:3px 10px;border-radius:12px;">⚠️ ' + d.inv.low_stock + ' قلم کم‌موجود</span>');
    if (d.hr && (d.hr.pending_leaves + d.hr.pending_missions) > 0)
      alerts.push('<span style="background:rgba(255,255,255,.2);padding:3px 10px;border-radius:12px;">🏖️ ' + (d.hr.pending_leaves + d.hr.pending_missions) + ' درخواست معلق HR</span>');
    $('#alertMessages').html(alerts.join(''));

    // نمودار CRM
    if (d.crm) {
      const won = d.crm.won || 0;
      const open = Math.max(0, (d.crm.opps || 0) - won);
      if (crmChart) crmChart.destroy();
      crmChart = new Chart(document.getElementById('crmChart'), {
        type: 'doughnut',
        data: {
          labels: ['فرصت‌های باز', 'بسته شده'],
          datasets: [{ data: [open, won], backgroundColor: ['#667eea', '#22c55e'], borderWidth: 0 }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { position: 'bottom', labels: { font: { family: 'Vazirmatn, Tahoma' } } }
          }
        }
      });
    }

    // فعالیت‌های اخیر
    if (d.activities && d.activities.length) {
      const icons = {crm:'👥',fin:'💰',inv:'📦'};
      const labels = {crm:'CRM',fin:'مالی',inv:'انبار'};
      const html = d.activities.map(a => `
        <div class="activity-item">
          <span class="act-badge act-${a.src}">${labels[a.src]||a.src}</span>
          <span style="flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">${a.num||''}</span>
          <span style="color:#94a3b8;font-size:.75rem;">${a.uname||''}</span>
        </div>
      `).join('');
      $('#activityFeed').html(html);
    } else {
      $('#activityFeed').html('<div style="text-align:center;padding:24px;color:#94a3b8;">فعالیتی ثبت نشده</div>');
    }
  }).fail(function() {
    $('#activityFeed').html('<div style="text-align:center;padding:24px;color:#ef4444;">خطا در بارگذاری</div>');
  });
}

// ── نمودار درآمد ماهانه ──────────────────────────────────
function loadRevenueChart() {
  $.getJSON(location.pathname + '?action=monthly_chart', function(d) {
    const months = d.months || [];
    if (!months.length) {
      document.getElementById('revenueChart').style.display = 'none';
      document.getElementById('noChartData').style.display = 'block';
      return;
    }
    if (revenueChart) revenueChart.destroy();
    revenueChart = new Chart(document.getElementById('revenueChart'), {
      type: 'bar',
      data: {
        labels: months.map(m => m.ym),
        datasets: [{
          label: 'درآمد (تومان)',
          data: months.map(m => m.total),
          backgroundColor: 'rgba(102,126,234,.7)',
          borderColor: '#667eea',
          borderWidth: 2,
          borderRadius: 6,
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          y: { ticks: { font: { family: 'Vazirmatn,Tahoma' }, callback: v => formatMoney(v, true) } },
          x: { ticks: { font: { family: 'Vazirmatn,Tahoma' } } }
        }
      }
    });
  });
}

function formatMoney(v, short) {
  const n = parseInt(v) || 0;
  if (short && n >= 1000000) return (n/1000000).toFixed(1) + 'M';
  return n.toLocaleString('fa-IR') + ' ت';
}

$(function() {
  loadSummary();
  loadRevenueChart();
});
</script>

<?php require_once $root . '/templates/footer.php'; ?>
