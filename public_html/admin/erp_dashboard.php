<?php
// داشبورد یکپارچه ERP — آتنا زیست درمان
$root = dirname(__DIR__, 2);
require_once $root . '/Config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';

requireLogin();
$basePath  = '../../';
$userName  = $_SESSION['name'] ?? ($_SESSION['first_name'] ?? 'کاربر');

// ── AJAX ──────────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_GET['action'] ?? '';

    if ($act === 'summary') {
        $out = [];

        // CRM
        try {
            $out['crm'] = [
                'opps'       => (int)$pdo->query('SELECT COUNT(*) FROM crm_opportunities WHERE is_deleted=0')->fetchColumn(),
                'won'        => (int)$pdo->query("SELECT COUNT(*) FROM crm_opportunities o JOIN crm_board_stages s ON s.id=o.stage_id WHERE o.is_deleted=0 AND s.name LIKE '%بسته%'")->fetchColumn(),
                'total_value'=> (int)$pdo->query('SELECT COALESCE(SUM(value),0) FROM crm_opportunities WHERE is_deleted=0')->fetchColumn(),
            ];
        } catch (Exception $e) { $out['crm'] = ['opps'=>0,'won'=>0,'total_value'=>0]; }

        // مالی
        try {
            $revThis = (int)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM fin_invoices WHERE type='sell' AND is_deleted=0 AND STR_TO_DATE(invoice_date,'%Y/%m/%d') >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
            $revLast = (int)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM fin_invoices WHERE type='sell' AND is_deleted=0 AND STR_TO_DATE(invoice_date,'%Y/%m/%d') BETWEEN DATE_SUB(CURDATE(),INTERVAL 60 DAY) AND DATE_SUB(CURDATE(),INTERVAL 31 DAY)")->fetchColumn();
            $revChg  = $revLast > 0 ? round(($revThis - $revLast) / $revLast * 100) : 0;
            $inv     = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(total_amount),0) t, COALESCE(SUM(paid_amount),0) p FROM fin_invoices WHERE type='sell' AND is_deleted=0")->fetch(PDO::FETCH_ASSOC);
            $out['fin'] = [
                'invoices'      => (int)$inv['c'],
                'revenue'       => (int)$inv['t'],
                'collected'     => (int)$inv['p'],
                'pending_cheques'=> (int)$pdo->query("SELECT COUNT(*) FROM fin_cheques WHERE status='pending' AND is_deleted=0")->fetchColumn(),
                'rev_this'      => $revThis,
                'rev_change'    => $revChg,
                'due_week'      => (int)$pdo->query("SELECT COUNT(*) FROM fin_cheques WHERE status='pending' AND is_deleted=0 AND STR_TO_DATE(due_date,'%Y/%m/%d') BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)")->fetchColumn(),
                'due_week_amt'  => (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM fin_cheques WHERE status='pending' AND is_deleted=0 AND STR_TO_DATE(due_date,'%Y/%m/%d') BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)")->fetchColumn(),
                'overdue_amt'   => (int)$pdo->query("SELECT COALESCE(SUM(total_amount-paid_amount),0) FROM fin_invoices WHERE type='sell' AND is_deleted=0 AND paid_amount < total_amount")->fetchColumn(),
                'overdue_count' => (int)$pdo->query("SELECT COUNT(*) FROM fin_invoices WHERE type='sell' AND is_deleted=0 AND paid_amount < total_amount")->fetchColumn(),
            ];
        } catch (Exception $e) {
            $out['fin'] = ['invoices'=>0,'revenue'=>0,'collected'=>0,'pending_cheques'=>0,'rev_this'=>0,'rev_change'=>0,'due_week'=>0,'due_week_amt'=>0,'overdue_amt'=>0,'overdue_count'=>0];
        }

        // انبار
        try {
            $out['inv'] = [
                'products' => (int)$pdo->query('SELECT COUNT(*) FROM stuffs WHERE is_active=1')->fetchColumn(),
                'low_stock'=> (int)$pdo->query('SELECT COUNT(*) FROM stuffs s JOIN stuff_price_list spl ON spl.stuff_id=s.id WHERE s.is_active=1 AND spl.total_inventory<=COALESCE(spl.minimum_stock,5) AND spl.total_inventory>=0')->fetchColumn(),
            ];
        } catch (Exception $e) { $out['inv'] = ['products'=>0,'low_stock'=>0]; }

        // HR
        try {
            $out['hr'] = [
                'pending_leaves'  => (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn(),
                'pending_missions'=> (int)$pdo->query("SELECT COUNT(*) FROM mission_requests WHERE status IN ('pending','pending_admin')")->fetchColumn(),
                'active_users'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(),
            ];
        } catch (Exception $e) { $out['hr'] = ['pending_leaves'=>0,'pending_missions'=>0,'active_users'=>0]; }

        // QMS
        try {
            $today = date('Y/m/d');
            $in30  = date('Y/m/d', strtotime('+30 days'));
            $out['qms'] = [
                'nc_open'         => (int)$pdo->query("SELECT COUNT(*) FROM nc_records WHERE status NOT IN ('closed','cancelled') AND is_deleted=0")->fetchColumn(),
                'nc_critical'     => (int)$pdo->query("SELECT COUNT(*) FROM nc_records WHERE severity='critical' AND status NOT IN ('closed','cancelled') AND is_deleted=0")->fetchColumn(),
                'nc_quarantine'   => (int)$pdo->query("SELECT COUNT(*) FROM nc_records WHERE status='quarantined' AND is_deleted=0")->fetchColumn(),
                'capa_open'       => (int)$pdo->query("SELECT COUNT(*) FROM capa_requests WHERE status NOT IN ('closed','cancelled') AND is_deleted=0")->fetchColumn(),
                'capa_overdue'    => (int)$pdo->prepare("SELECT COUNT(*) FROM capa_requests WHERE status NOT IN ('closed','cancelled') AND target_date IS NOT NULL AND target_date < ? AND is_deleted=0")->execute([$today]) ? $pdo->query("SELECT COUNT(*) FROM capa_requests WHERE status NOT IN ('closed','cancelled') AND target_date IS NOT NULL AND target_date < '$today' AND is_deleted=0")->fetchColumn() : 0,
                'doc_review_due'  => (int)$pdo->prepare("SELECT COUNT(*) FROM doc_documents WHERE status='approved' AND next_review_date IS NOT NULL AND next_review_date <= ? AND is_deleted=0")->execute([$in30]) ? $pdo->query("SELECT COUNT(*) FROM doc_documents WHERE status='approved' AND next_review_date IS NOT NULL AND next_review_date <= '$in30' AND is_deleted=0")->fetchColumn() : 0,
                'training_expire' => (int)$pdo->prepare("SELECT COUNT(*) FROM hr_training_records WHERE result='pass' AND expiry_date IS NOT NULL AND expiry_date <= ? AND is_deleted=0")->execute([$in30]) ? $pdo->query("SELECT COUNT(*) FROM hr_training_records WHERE result='pass' AND expiry_date IS NOT NULL AND expiry_date <= '$in30' AND is_deleted=0")->fetchColumn() : 0,
                'audit_planned'   => (int)$pdo->query("SELECT COUNT(*) FROM qms_audit_plans WHERE status='planned' AND is_deleted=0")->fetchColumn(),
            ];
        } catch (Exception $e) { $out['qms'] = ['nc_open'=>0,'nc_critical'=>0,'nc_quarantine'=>0,'capa_open'=>0,'capa_overdue'=>0,'doc_review_due'=>0,'training_expire'=>0,'audit_planned'=>0]; }

        // فعالیت‌های اخیر
        try {
            $out['activities'] = $pdo->query(
                "SELECT 'inv' AS src, t.ticket_number num, t.type tp, t.created_at dt, u.name uname
                 FROM inv_tickets t LEFT JOIN users u ON u.id=t.created_by WHERE t.is_deleted=0
                 UNION ALL
                 SELECT 'fin', i.invoice_number, i.type, i.created_at, u.name
                 FROM fin_invoices i LEFT JOIN users u ON u.id=i.created_by WHERE i.is_deleted=0
                 UNION ALL
                 SELECT 'crm', o.title, 'opportunity', o.created_at, u.name
                 FROM crm_opportunities o LEFT JOIN users u ON u.id=o.assigned_to WHERE o.is_deleted=0
                 ORDER BY dt DESC LIMIT 15"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $out['activities'] = []; }

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'monthly_chart') {
        try {
            $rows = $pdo->query(
                "SELECT DATE_FORMAT(STR_TO_DATE(invoice_date,'%Y/%m/%d'),'%Y-%m') ym,
                        COALESCE(SUM(total_amount),0) total
                 FROM fin_invoices WHERE type='sell' AND is_deleted=0
                   AND STR_TO_DATE(invoice_date,'%Y/%m/%d') >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                 GROUP BY ym ORDER BY ym"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $rows = []; }
        echo json_encode(['months' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'alerts') {
        $data = [];
        try {
            $data['upcoming_cheques'] = $pdo->query(
                "SELECT fc.cheque_number, fc.amount, fc.due_date, fp.name person_name
                 FROM fin_cheques fc LEFT JOIN fin_persons fp ON fp.id=fc.person_id
                 WHERE fc.status='pending' AND fc.is_deleted=0
                   AND STR_TO_DATE(fc.due_date,'%Y/%m/%d') BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY)
                 ORDER BY STR_TO_DATE(fc.due_date,'%Y/%m/%d') ASC LIMIT 6"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $data['upcoming_cheques'] = []; }
        try {
            $data['low_stock'] = $pdo->query(
                "SELECT s.name, spl.total_inventory, COALESCE(spl.minimum_stock,5) min_stock
                 FROM stuffs s JOIN stuff_price_list spl ON spl.stuff_id=s.id
                 WHERE s.is_active=1 AND spl.total_inventory <= COALESCE(spl.minimum_stock,5)
                 ORDER BY spl.total_inventory ASC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $data['low_stock'] = []; }
        try {
            $today = date('Y/m/d');
            $st = $pdo->prepare(
                "SELECT cr.capa_number, cr.title, cr.target_date, cr.type
                 FROM capa_requests cr
                 WHERE cr.is_deleted=0 AND cr.status NOT IN ('closed','cancelled')
                   AND cr.target_date IS NOT NULL AND cr.target_date < ?
                 ORDER BY cr.target_date ASC LIMIT 5"
            );
            $st->execute([$today]);
            $data['qms_capa_overdue'] = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $data['qms_capa_overdue'] = []; }
        try {
            $data['qms_nc_quarantine'] = $pdo->query(
                "SELECT nc_number, title, severity FROM nc_records
                 WHERE status='quarantined' AND is_deleted=0
                 ORDER BY id DESC LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $data['qms_nc_quarantine'] = []; }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['error' => 'action نامعتبر'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pageTitle = 'مرکز فرماندهی ERP';
$extraCss  = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';

ob_start();
require_once $root . '/templates/header.php';
echo ob_get_clean();
require_once $root . '/templates/sidebar.php';
?>

<div class="content-wrapper" style="min-height:100vh;background:#f0f4f8;">

<!-- ── نوار وضعیت زنده ────────────────────────────────── -->
<div id="erpAlertBar" style="
    background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);
    color:#fff;padding:11px 24px;display:flex;align-items:center;
    justify-content:space-between;gap:16px;font-size:.84rem;flex-wrap:wrap;">
  <div style="display:flex;align-items:center;gap:14px;">
    <div style="display:flex;align-items:center;gap:8px;">
      <div style="width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 8px #22c55e80;animation:livepulse 2s infinite;"></div>
      <strong style="font-size:.9rem;">آتنا زیست درمان</strong>
    </div>
    <span id="liveTime" style="opacity:.6;font-family:monospace;direction:ltr;font-size:.78rem;"></span>
  </div>
  <div id="alertChips" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;"></div>
</div>

<div style="padding:26px 30px;">

<!-- ── عنوان ────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:26px;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="margin:0;font-size:1.5rem;font-weight:700;color:#0f172a;">سلام، <?= htmlspecialchars($userName) ?> 👋</h1>
    <p id="todayDate" style="margin:5px 0 0;color:#64748b;font-size:.875rem;"></p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="<?= $basePath ?>admin/fin_invoice_sell.php" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#10b981;color:#fff;border-radius:10px;text-decoration:none;font-size:.85rem;font-weight:600;box-shadow:0 2px 8px #10b98140;">+ فاکتور فروش</a>
    <a href="<?= $basePath ?>admin/crm_opportunities.php" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#3b82f6;color:#fff;border-radius:10px;text-decoration:none;font-size:.85rem;font-weight:600;box-shadow:0 2px 8px #3b82f640;">+ فرصت جدید</a>
  </div>
</div>

<!-- ── KPI اصلی — ردیف ۱ ────────────────────────────────── -->
<div class="kpi-grid-primary">

  <div class="kpi-card" style="--kc:#3b82f6;">
    <div class="kpi-inner">
      <div class="kpi-texts">
        <div class="kpi-lbl">درآمد (۳۰ روز اخیر)</div>
        <div class="kpi-val" id="kpiRevThis">—</div>
        <div class="kpi-meta" id="kpiRevTrend"></div>
      </div>
      <div class="kpi-ico" style="background:#eff6ff;color:#3b82f6;">💰</div>
    </div>
    <a href="<?= $basePath ?>admin/fin_reports.php" class="kpi-foot">گزارش مالی</a>
  </div>

  <div class="kpi-card" style="--kc:#ef4444;">
    <div class="kpi-inner">
      <div class="kpi-texts">
        <div class="kpi-lbl">مطالبات دریافت‌نشده</div>
        <div class="kpi-val" id="kpiOverdue">—</div>
        <div class="kpi-meta" id="kpiOverdueCnt" style="color:#ef4444;"></div>
      </div>
      <div class="kpi-ico" style="background:#fef2f2;color:#ef4444;">📋</div>
    </div>
    <a href="<?= $basePath ?>admin/crm_receivables.php" class="kpi-foot">مدیریت مطالبات</a>
  </div>

  <div class="kpi-card" style="--kc:#f59e0b;">
    <div class="kpi-inner">
      <div class="kpi-texts">
        <div class="kpi-lbl">چک سررسید (۷ روز آینده)</div>
        <div class="kpi-val" id="kpiDueAmt">—</div>
        <div class="kpi-meta" id="kpiDueCnt" style="color:#f59e0b;"></div>
      </div>
      <div class="kpi-ico" style="background:#fffbeb;color:#f59e0b;">🧾</div>
    </div>
    <a href="<?= $basePath ?>admin/fin_cheque_calendar.php" class="kpi-foot">تقویم سررسید</a>
  </div>

  <div class="kpi-card" style="--kc:#8b5cf6;">
    <div class="kpi-inner">
      <div class="kpi-texts">
        <div class="kpi-lbl">پایپ‌لاین فروش (CRM)</div>
        <div class="kpi-val" id="kpiPipe">—</div>
        <div class="kpi-meta" id="kpiPipeSub" style="color:#8b5cf6;"></div>
      </div>
      <div class="kpi-ico" style="background:#f5f3ff;color:#8b5cf6;">📈</div>
    </div>
    <a href="<?= $basePath ?>admin/crm_kanban.php" class="kpi-foot">بورد کانبان</a>
  </div>

</div>

<!-- ── KPI ثانویه — ردیف ۲ ──────────────────────────────── -->
<div class="kpi-grid-secondary">

  <div class="kpi-mini" style="--km:#10b981;">
    <div class="kpi-mini-ico" style="background:#ecfdf5;color:#10b981;">📦</div>
    <div class="kpi-mini-body">
      <div class="kpi-mini-val" id="invProducts">—</div>
      <div class="kpi-mini-lbl">قلم کالا (فعال)</div>
      <div id="invLowHint" style="font-size:.7rem;margin-top:2px;"></div>
    </div>
    <a href="<?= $basePath ?>admin/inv_dashboard.php" class="kpi-mini-arrow">←</a>
  </div>

  <div class="kpi-mini" style="--km:#06b6d4;">
    <div class="kpi-mini-ico" style="background:#ecfeff;color:#06b6d4;">🏖️</div>
    <div class="kpi-mini-body">
      <div class="kpi-mini-val" id="hrPend">—</div>
      <div class="kpi-mini-lbl">درخواست HR معلق</div>
      <div id="hrActive" style="font-size:.7rem;margin-top:2px;color:#94a3b8;"></div>
    </div>
    <a href="<?= $basePath ?>admin/leave_requests.php" class="kpi-mini-arrow">←</a>
  </div>

  <div class="kpi-mini" style="--km:#f97316;">
    <div class="kpi-mini-ico" style="background:#fff7ed;color:#f97316;">📄</div>
    <div class="kpi-mini-body">
      <div class="kpi-mini-val" id="finInv">—</div>
      <div class="kpi-mini-lbl">فاکتور فروش (کل)</div>
      <div id="finCollected" style="font-size:.7rem;margin-top:2px;color:#94a3b8;"></div>
    </div>
    <a href="<?= $basePath ?>admin/fin_invoice_sell.php" class="kpi-mini-arrow">←</a>
  </div>

  <div class="kpi-mini" style="--km:#ec4899;">
    <div class="kpi-mini-ico" style="background:#fdf2f8;color:#ec4899;">💳</div>
    <div class="kpi-mini-body">
      <div class="kpi-mini-val" id="finChq">—</div>
      <div class="kpi-mini-lbl">چک در جریان وصول</div>
    </div>
    <a href="<?= $basePath ?>admin/fin_cheques.php" class="kpi-mini-arrow">←</a>
  </div>

  <div class="kpi-mini" style="--km:#64748b;">
    <div class="kpi-mini-ico" style="background:#f8fafc;color:#64748b;">👥</div>
    <div class="kpi-mini-body">
      <div class="kpi-mini-val" id="crmOpps">—</div>
      <div class="kpi-mini-lbl">فرصت باز CRM</div>
      <div id="crmWon" style="font-size:.7rem;margin-top:2px;color:#94a3b8;"></div>
    </div>
    <a href="<?= $basePath ?>admin/crm_kanban.php" class="kpi-mini-arrow">←</a>
  </div>

</div>

<!-- ── QMS KPIs ─────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;">

  <div class="kpi-mini" style="--km:#ef4444;">
    <div class="kpi-mini-ico" style="background:#fef2f2;color:#ef4444;">⛔</div>
    <div class="kpi-mini-body">
      <div class="kpi-mini-val" id="qmsNcOpen">—</div>
      <div class="kpi-mini-lbl">NC باز <span id="qmsNcCrit"></span></div>
    </div>
    <a href="<?= $basePath ?>admin/nc_records.php" class="kpi-mini-arrow">←</a>
  </div>

  <div class="kpi-mini" style="--km:#f97316;">
    <div class="kpi-mini-ico" style="background:#fff7ed;color:#f97316;">🔧</div>
    <div class="kpi-mini-body">
      <div class="kpi-mini-val" id="qmsCapaOpen">—</div>
      <div class="kpi-mini-lbl">CAPA باز <span id="qmsCapaOver"></span></div>
    </div>
    <a href="<?= $basePath ?>admin/capa.php" class="kpi-mini-arrow">←</a>
  </div>

  <div class="kpi-mini" style="--km:#8b5cf6;">
    <div class="kpi-mini-ico" style="background:#f5f3ff;color:#8b5cf6;">📄</div>
    <div class="kpi-mini-body">
      <div class="kpi-mini-val" id="qmsDocDue">—</div>
      <div class="kpi-mini-lbl">مدرک نیاز به بازنگری</div>
    </div>
    <a href="<?= $basePath ?>admin/doc_control.php" class="kpi-mini-arrow">←</a>
  </div>

  <div class="kpi-mini" style="--km:#0ea5e9;">
    <div class="kpi-mini-ico" style="background:#f0f9ff;color:#0ea5e9;">🎓</div>
    <div class="kpi-mini-body">
      <div class="kpi-mini-val" id="qmsTrainExp">—</div>
      <div class="kpi-mini-lbl">گواهینامه در حال انقضا</div>
    </div>
    <a href="<?= $basePath ?>admin/hr_training.php" class="kpi-mini-arrow">←</a>
  </div>

</div>

<!-- ── نمودار + هشدارها ──────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1.85fr 1fr;gap:20px;margin-bottom:22px;">

  <div class="dash-panel">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
      <h3 class="dash-title">📊 درآمد ماهانه (۶ ماه اخیر)</h3>
      <a href="<?= $basePath ?>admin/fin_reports.php" style="font-size:.75rem;color:#3b82f6;text-decoration:none;">مشاهده کامل ←</a>
    </div>
    <canvas id="revenueChart" height="185"></canvas>
    <p id="noChartData" style="text-align:center;color:#94a3b8;font-size:.85rem;display:none;padding:40px 0;">داده‌ای ثبت نشده</p>
  </div>

  <div class="dash-panel" style="display:flex;flex-direction:column;">
    <h3 class="dash-title" style="margin-bottom:14px;">⚠️ هشدارها و سررسیدها</h3>
    <div id="alertsPanel" style="flex:1;overflow-y:auto;max-height:280px;">
      <div style="color:#94a3b8;font-size:.82rem;text-align:center;padding:30px 0;">در حال بارگذاری...</div>
    </div>
  </div>

</div>

<!-- ── دسترسی سریع + فعالیت‌ها ──────────────────────────── -->
<div style="display:grid;grid-template-columns:260px 1fr;gap:20px;margin-bottom:24px;">

  <div class="dash-panel">
    <h3 class="dash-title" style="margin-bottom:14px;">⚡ دسترسی سریع</h3>
    <div style="display:flex;flex-direction:column;gap:7px;">
      <?php
      $shortcuts = [
        ['👥','فرصت فروش جدید','admin/crm_opportunities.php','#3b82f6'],
        ['🧾','فاکتور فروش','admin/fin_invoice_sell.php','#10b981'],
        ['📦','رسید انبار','admin/inv_receipts.php','#ef4444'],
        ['💳','مدیریت چک','admin/fin_cheques.php','#f59e0b'],
        ['👤','طرف حساب‌ها','admin/fin_persons.php','#06b6d4'],
        ['📊','گزارش مالی','admin/fin_reports.php','#8b5cf6'],
        ['📈','گزارش CRM','admin/crm_reports.php','#f97316'],
        ['🔖','پیش‌فاکتور','admin/fin_preinvoice.php','#ec4899'],
        ['🤝','قراردادها','admin/contracts.php','#64748b'],
        ['🎟','تیکت پشتیبانی','admin/support_tickets.php','#0ea5e9'],
      ];
      foreach ($shortcuts as [$ico,$lbl,$href,$col]): ?>
      <a href="<?= $basePath . $href ?>" style="
          display:flex;align-items:center;gap:9px;padding:9px 11px;
          background:#f8fafc;border-radius:9px;text-decoration:none;
          color:#334155;font-size:.8rem;font-weight:500;
          border:1px solid #f1f5f9;transition:.15s;"
         onmouseover="this.style.background='<?= $col ?>12';this.style.borderColor='<?= $col ?>40'"
         onmouseout="this.style.background='#f8fafc';this.style.borderColor='#f1f5f9'">
        <span style="font-size:.95rem;width:20px;text-align:center;"><?= $ico ?></span>
        <span style="flex:1;"><?= $lbl ?></span>
        <span style="color:#cbd5e1;font-size:.7rem;">←</span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="dash-panel">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
      <h3 class="dash-title">⏱️ آخرین فعالیت‌های سیستم</h3>
    </div>
    <div id="actFeed" style="max-height:380px;overflow-y:auto;">
      <div style="text-align:center;padding:40px;color:#94a3b8;">در حال بارگذاری...</div>
    </div>
  </div>

</div>

</div><!-- /padding -->
</div><!-- /content-wrapper -->

<style>
@keyframes livepulse { 0%,100%{opacity:1;box-shadow:0 0 8px #22c55e80} 50%{opacity:.5;box-shadow:0 0 2px #22c55e30} }

.kpi-grid-primary {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 18px;
  margin-bottom: 16px;
}
.kpi-grid-secondary {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 14px;
  margin-bottom: 22px;
}

/* ── KPI کارت اصلی ── */
.kpi-card {
  background: #fff;
  border-radius: 16px;
  padding: 20px 20px 0;
  box-shadow: 0 1px 10px rgba(0,0,0,.06);
  border-right: 4px solid var(--kc);
  display: flex;
  flex-direction: column;
  transition: transform .15s, box-shadow .15s;
}
.kpi-card:hover { transform: translateY(-3px); box-shadow: 0 6px 24px rgba(0,0,0,.1); }
.kpi-inner { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }
.kpi-texts { flex: 1; }
.kpi-lbl  { font-size: .72rem; color: #94a3b8; font-weight: 500; margin-bottom: 7px; }
.kpi-val  { font-size: 1.55rem; font-weight: 700; color: #0f172a; line-height: 1.1; }
.kpi-meta { margin-top: 5px; min-height: 1.3em; font-size: .75rem; }
.kpi-ico  { font-size: 1.35rem; width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-right: 12px; }
.kpi-foot {
  display: block; padding: 10px 2px;
  border-top: 1px solid #f1f5f9;
  font-size: .73rem; color: var(--kc); text-decoration: none;
  font-weight: 600; transition: opacity .15s;
}
.kpi-foot:hover { opacity: .7; }

/* trend badge */
.kpi-trend { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: .71rem; font-weight: 700; }
.kpi-trend.up   { background: #dcfce7; color: #166534; }
.kpi-trend.down { background: #fee2e2; color: #991b1b; }
.kpi-trend.flat { background: #f1f5f9; color: #64748b; }

/* ── KPI Mini ── */
.kpi-mini {
  background: #fff;
  border-radius: 14px;
  padding: 14px 16px;
  box-shadow: 0 1px 8px rgba(0,0,0,.05);
  border-bottom: 3px solid var(--km);
  display: flex; align-items: center; gap: 12px;
  transition: transform .15s;
}
.kpi-mini:hover { transform: translateY(-2px); }
.kpi-mini-ico { font-size: 1.15rem; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.kpi-mini-body { flex: 1; min-width: 0; }
.kpi-mini-val { font-size: 1.2rem; font-weight: 700; color: #0f172a; }
.kpi-mini-lbl { font-size: .7rem; color: #94a3b8; margin-top: 1px; }
.kpi-mini-arrow { color: var(--km); text-decoration: none; font-size: 1rem; font-weight: 700; flex-shrink: 0; }

/* ── Panel ── */
.dash-panel {
  background: #fff;
  border-radius: 16px;
  padding: 20px 22px;
  box-shadow: 0 1px 10px rgba(0,0,0,.06);
}
.dash-title { margin: 0; font-size: .95rem; font-weight: 700; color: #0f172a; }

/* ── فعالیت‌ها ── */
.act-row {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 0; border-bottom: 1px solid #f8fafc;
}
.act-row:last-child { border-bottom: none; }
.act-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.act-badge { padding: 3px 8px; border-radius: 20px; font-size: .67rem; font-weight: 700; white-space: nowrap; flex-shrink: 0; }
.act-crm { background:#eff6ff; color:#2563eb; }
.act-fin { background:#f0fdf4; color:#15803d; }
.act-inv { background:#fff7ed; color:#c2410c; }

/* ── alert chips ── */
.ach { padding: 4px 12px; border-radius: 20px; font-size: .74rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }

@media (max-width: 900px) {
  .kpi-grid-primary, .kpi-grid-secondary { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
  .kpi-grid-primary, .kpi-grid-secondary { grid-template-columns: 1fr; }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const BASE = '<?= rtrim($basePath, '/') ?>';
let revChart = null;

// ساعت زنده
function clock() {
  const n = new Date();
  document.getElementById('liveTime').textContent = n.toLocaleDateString('fa-IR') + '  ' + n.toLocaleTimeString('fa-IR');
  document.getElementById('todayDate').textContent = 'امروز: ' + n.toLocaleDateString('fa-IR',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
}
setInterval(clock, 1000); clock();

// فرمت پول
function fm(v, s) {
  const n = parseInt(v) || 0;
  if (s && n >= 1e9) return (n/1e9).toFixed(1) + ' میلیارد ت';
  if (s && n >= 1e6) return (n/1e6).toFixed(1) + ' M ت';
  return n.toLocaleString('fa-IR') + ' ت';
}

// badge روند
function trendBadge(p) {
  if (!p) return '<span class="kpi-trend flat">بدون تغییر</span>';
  return p > 0
    ? `<span class="kpi-trend up">↑ ${Math.abs(p)}٪ نسبت به ماه قبل</span>`
    : `<span class="kpi-trend down">↓ ${Math.abs(p)}٪ نسبت به ماه قبل</span>`;
}

function loadSummary() {
  $.getJSON(location.pathname + '?action=summary', function(d) {
    const f = d.fin || {}, c = d.crm || {}, i = d.inv || {}, h = d.hr || {}, q = d.qms || {};

    // KPI اصلی
    $('#kpiRevThis').text(fm(f.rev_this, true));
    $('#kpiRevTrend').html(trendBadge(f.rev_change));
    $('#kpiOverdue').text(fm(f.overdue_amt, true));
    $('#kpiOverdueCnt').text((f.overdue_count||0) + ' فاکتور دریافت‌نشده');
    $('#kpiDueAmt').text(fm(f.due_week_amt, true));
    $('#kpiDueCnt').text((f.due_week||0) + ' چک در ۷ روز آینده');
    $('#kpiPipe').text(fm(c.total_value, true));
    $('#kpiPipeSub').text((c.opps||0) + ' فرصت باز | ' + (c.won||0) + ' بسته');

    // KPI ثانویه
    $('#invProducts').text(((i.products||0)).toLocaleString('fa-IR'));
    const low = i.low_stock || 0;
    $('#invLowHint').html(low > 0
      ? `<span style="color:#ef4444;font-weight:600;">⚠ ${low} قلم کم‌موجود</span>`
      : `<span style="color:#10b981;">✓ موجودی سالم</span>`);
    $('#hrPend').text(((h.pending_leaves||0)+(h.pending_missions||0)).toLocaleString('fa-IR'));
    $('#hrActive').text('کاربران فعال: ' + (h.active_users||0));
    $('#finInv').text((f.invoices||0).toLocaleString('fa-IR'));
    $('#finCollected').text('وصول‌شده: ' + fm(f.collected, true));
    $('#finChq').text((f.pending_cheques||0).toLocaleString('fa-IR'));
    $('#crmOpps').text((c.opps||0).toLocaleString('fa-IR'));
    $('#crmWon').text('بسته شده: ' + (c.won||0));

    // QMS KPIs
    $('#qmsNcOpen').text((q.nc_open||0).toLocaleString('fa-IR'));
    $('#qmsNcCrit').html(q.nc_critical > 0 ? `<span style="color:#ef4444;font-weight:700;">(${q.nc_critical} بحرانی)</span>` : '');
    $('#qmsCapaOpen').text((q.capa_open||0).toLocaleString('fa-IR'));
    $('#qmsCapaOver').html(q.capa_overdue > 0 ? `<span style="color:#ef4444;font-weight:700;">(${q.capa_overdue} معوق)</span>` : '');
    $('#qmsDocDue').text((q.doc_review_due||0).toLocaleString('fa-IR'));
    $('#qmsTrainExp').text((q.training_expire||0).toLocaleString('fa-IR'));

    // نوار هشدار
    const chips = [];
    if (f.due_week > 0)
      chips.push(`<span class="ach" style="background:rgba(245,158,11,.18);color:#fbbf24;">🧾 ${f.due_week} چک سررسید</span>`);
    if (f.overdue_count > 0)
      chips.push(`<span class="ach" style="background:rgba(239,68,68,.18);color:#fca5a5;">📋 ${f.overdue_count} فاکتور معوق</span>`);
    if (low > 0)
      chips.push(`<span class="ach" style="background:rgba(249,115,22,.18);color:#fdba74;">📦 ${low} قلم کم‌موجود</span>`);
    const hrPend = (h.pending_leaves||0) + (h.pending_missions||0);
    if (hrPend > 0)
      chips.push(`<span class="ach" style="background:rgba(6,182,212,.18);color:#67e8f9;">🏖 ${hrPend} درخواست HR</span>`);
    if ((q.capa_overdue||0) > 0)
      chips.push(`<span class="ach" style="background:rgba(249,115,22,.18);color:#fdba74;">🔧 ${q.capa_overdue} CAPA معوق</span>`);
    if ((q.nc_open||0) > 0)
      chips.push(`<span class="ach" style="background:rgba(239,68,68,.18);color:#fca5a5;">⛔ ${q.nc_open} NC باز</span>`);
    $('#alertChips').html(chips.length ? chips.join('') : '<span style="opacity:.45;font-size:.78rem;">همه چیز عادی است ✓</span>');

    // فعالیت‌ها
    const acts = d.activities || [];
    if (acts.length) {
      const lbl = {crm:'CRM', fin:'مالی', inv:'انبار'};
      const clr = {crm:'#2563eb', fin:'#15803d', inv:'#c2410c'};
      const typeL = {sell:'فروش', buy:'خرید', receipt:'رسید', dispatch:'حواله', transfer:'انتقال', opportunity:'فرصت'};
      const html = acts.map(a => {
        const dt = new Date(a.dt.replace(' ','T'));
        const time = dt.toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit'});
        const day  = dt.toLocaleDateString('fa-IR',{month:'short',day:'numeric'});
        return `<div class="act-row">
          <div class="act-dot" style="background:${clr[a.src]||'#94a3b8'};"></div>
          <span class="act-badge act-${a.src}">${lbl[a.src]||a.src}</span>
          <span style="flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#334155;font-size:.82rem;">${a.num||''}${typeL[a.tp] ? ' — '+typeL[a.tp] : ''}</span>
          <span style="color:#94a3b8;font-size:.72rem;white-space:nowrap;flex-shrink:0;">${a.uname||''}  ${day} ${time}</span>
        </div>`;
      }).join('');
      $('#actFeed').html(html);
    } else {
      $('#actFeed').html('<div style="text-align:center;padding:40px;color:#94a3b8;">فعالیتی ثبت نشده</div>');
    }
  }).fail(function(){
    $('#actFeed').html('<div style="text-align:center;padding:40px;color:#ef4444;">خطا در بارگذاری</div>');
  });
}

function loadChart() {
  $.getJSON(location.pathname + '?action=monthly_chart', function(d) {
    const months = d.months || [];
    if (!months.length) {
      $('#revenueChart').hide(); $('#noChartData').show(); return;
    }
    if (revChart) revChart.destroy();
    revChart = new Chart(document.getElementById('revenueChart'), {
      type: 'bar',
      data: {
        labels: months.map(m => m.ym),
        datasets: [{
          data: months.map(m => m.total),
          backgroundColor: months.map((_, i) => i === months.length-1 ? '#3b82f6' : '#bfdbfe'),
          borderRadius: 8,
          borderSkipped: false,
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => ' ' + fm(ctx.raw, true) } }
        },
        scales: {
          y: { grid:{color:'#f1f5f9'}, ticks:{font:{family:'Vazirmatn,Tahoma'}, callback: v => fm(v, true)} },
          x: { grid:{display:false}, ticks:{font:{family:'Vazirmatn,Tahoma'}} }
        }
      }
    });
  });
}

function loadAlerts() {
  $.getJSON(location.pathname + '?action=alerts', function(d) {
    let html = '';

    const chqs = d.upcoming_cheques || [];
    if (chqs.length) {
      html += '<div style="font-size:.73rem;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">🧾 چک‌های سررسید ۱۴ روز آینده</div>';
      chqs.forEach(c => {
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f8fafc;">
          <span style="color:#334155;font-size:.8rem;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:50%;">${c.person_name||'—'}</span>
          <div style="text-align:left;direction:ltr;flex-shrink:0;">
            <div style="color:#f59e0b;font-weight:700;font-size:.8rem;">${fm(c.amount)}</div>
            <div style="color:#94a3b8;font-size:.69rem;">${c.due_date||''}</div>
          </div>
        </div>`;
      });
    }

    const low = d.low_stock || [];
    if (low.length) {
      html += `<div style="font-size:.73rem;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:.5px;margin:${chqs.length?'14px':0} 0 8px;">📦 کالاهای کم‌موجود</div>`;
      low.forEach(s => {
        const pct = Math.min(Math.round(s.total_inventory / (s.min_stock||1) * 100), 100);
        const barClr = pct < 30 ? '#ef4444' : '#f59e0b';
        html += `<div style="padding:7px 0;border-bottom:1px solid #f8fafc;">
          <div style="display:flex;justify-content:space-between;font-size:.79rem;margin-bottom:5px;">
            <span style="color:#334155;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:65%;">${s.name}</span>
            <span style="color:${barClr};font-weight:700;">${s.total_inventory} عدد</span>
          </div>
          <div style="background:#f1f5f9;border-radius:4px;height:5px;">
            <div style="background:${barClr};width:${pct}%;height:5px;border-radius:4px;transition:width .4s;"></div>
          </div>
        </div>`;
      });
    }

    const capas = d.qms_capa_overdue || [];
    if (capas.length) {
      html += `<div style="font-size:.73rem;font-weight:700;color:#f97316;text-transform:uppercase;letter-spacing:.5px;margin:${chqs.length||low.length?'14px':0} 0 8px;">🔧 CAPA‌های معوق</div>`;
      capas.forEach(ca => {
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f8fafc;">
          <span style="color:#334155;font-size:.79rem;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:60%;">${ca.capa_number} — ${ca.title||''}</span>
          <span style="color:#ef4444;font-size:.73rem;font-weight:600;">سررسید: ${ca.target_date||''}</span>
        </div>`;
      });
    }
    const ncs = d.qms_nc_quarantine || [];
    if (ncs.length) {
      html += `<div style="font-size:.73rem;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:.5px;margin:${chqs.length||low.length||capas.length?'14px':0} 0 8px;">⛔ محصولات در قرنطینه</div>`;
      const sevL = {critical:'بحرانی', major:'اصلی', minor:'جزئی'};
      ncs.forEach(nc => {
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f8fafc;">
          <span style="color:#334155;font-size:.79rem;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:65%;">${nc.nc_number} — ${nc.title||''}</span>
          <span style="background:#fef2f2;color:#ef4444;padding:2px 7px;border-radius:10px;font-size:.69rem;font-weight:700;">${sevL[nc.severity]||nc.severity}</span>
        </div>`;
      });
    }

    if (!html) html = '<div style="text-align:center;padding:28px;color:#10b981;font-size:.85rem;">✓ هشداری وجود ندارد</div>';
    $('#alertsPanel').html(html);
  });
}

$(function() {
  loadSummary();
  loadChart();
  loadAlerts();
  setInterval(loadSummary, 60000);
});
</script>

<?php require_once $root . '/templates/footer.php'; ?>
