<?php
/*
 * فایل: public_html/admin/dealers.php
 * مدیریت نمایندگان و توزیع‌کنندگان — ERP آتنا زیست درمان
 */

ob_start();

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$userId  = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// ---- بررسی دسترسی ----
$hasAccess = false;
try {
    $stmtChk = $pdo->prepare('SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?');
    $stmtChk->execute([$rawRole, $rawRole]);
    $roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);
    if ($roleData) {
        $roleName = strtolower(trim($roleData['name'] ?? $rawRole));
        $allowedRoles = ['admin','management','manager','sales','sales_manager','crm_manager'];
        if (in_array($roleName, $allowedRoles) || $roleData['is_system'] == 1) {
            $hasAccess = true;
        } else {
            $perms = json_decode($roleData['permissions'] ?? '[]', true) ?? [];
            if (in_array('dealers', $perms) || in_array('all', $perms)) $hasAccess = true;
        }
    }
    if (in_array($rawRole, ['1','2','5','10','14'])) $hasAccess = true;
} catch (Throwable $e) { $hasAccess = true; }

if (!$hasAccess) {
    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'دسترسی غیرمجاز']);
        exit;
    }
    die('<p style="font-family:Tahoma;color:#e11d48;text-align:center;padding:60px">دسترسی غیرمجاز</p>');
}

// ---- ایجاد جدول ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `dealers` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `name`            VARCHAR(200) NOT NULL,
        `code`            VARCHAR(30)  DEFAULT NULL,
        `region`          VARCHAR(100) DEFAULT NULL,
        `province_id`     INT          DEFAULT NULL,
        `contact_name`    VARCHAR(200) DEFAULT NULL,
        `phone`           VARCHAR(20)  DEFAULT NULL,
        `email`           VARCHAR(100) DEFAULT NULL,
        `address`         TEXT         DEFAULT NULL,
        `commission_rate` DECIMAL(5,2) DEFAULT 0 COMMENT 'درصد کمیسیون',
        `credit_limit`    DECIMAL(20,0) DEFAULT 0,
        `current_balance` DECIMAL(20,0) DEFAULT 0,
        `status`          ENUM('active','inactive','suspended') DEFAULT 'active',
        `notes`           TEXT         DEFAULT NULL,
        `created_by`      INT          DEFAULT NULL,
        `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted`      TINYINT(1)   DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ═══════════════════════════════════════════════════════════
// AJAX
// ═══════════════════════════════════════════════════════════
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_REQUEST['action'] ?? '');

    try {

        // ── لیست نمایندگان ──────────────────────────────────
        if ($action === 'list') {
            $search  = trim($_GET['q'] ?? '');
            $status  = trim($_GET['status'] ?? '');
            $params  = [];
            $where   = ['d.is_deleted = 0'];

            if ($search !== '') {
                $where[] = '(d.name LIKE ? OR d.code LIKE ? OR d.contact_name LIKE ? OR d.phone LIKE ?)';
                $params  = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
            }
            if ($status !== '') {
                $where[] = 'd.status = ?';
                $params[] = $status;
            }

            $sql = "SELECT d.*, p.name AS province_name,
                           COALESCE(sales.total,0) AS month_sales,
                           COALESCE(sales.invoice_count,0) AS invoice_count
                    FROM dealers d
                    LEFT JOIN crm_provinces p ON p.id = d.province_id
                    LEFT JOIN (
                        SELECT dealer_id, SUM(total_amount) AS total, COUNT(*) AS invoice_count
                        FROM fin_invoices
                        WHERE type='sell' AND status='confirmed' AND is_deleted=0
                          AND dealer_id IS NOT NULL
                          AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())
                        GROUP BY dealer_id
                    ) sales ON sales.dealer_id = d.id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY d.name";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── ذخیره (افزودن / ویرایش) ─────────────────────────
        if ($action === 'save') {
            csrf_verify();
            $id             = (int)($_POST['id'] ?? 0);
            $name           = trim($_POST['name'] ?? '');
            $code           = trim($_POST['code'] ?? '');
            $region         = trim($_POST['region'] ?? '');
            $province_id    = (int)($_POST['province_id'] ?? 0) ?: null;
            $contact_name   = trim($_POST['contact_name'] ?? '');
            $phone          = trim($_POST['phone'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $address        = trim($_POST['address'] ?? '');
            $commission_rate= round((float)($_POST['commission_rate'] ?? 0), 2);
            $credit_limit   = (int)preg_replace('/\D/', '', $_POST['credit_limit'] ?? '0');
            $status         = in_array($_POST['status'] ?? '', ['active','inactive','suspended'])
                                ? $_POST['status'] : 'active';
            $notes          = trim($_POST['notes'] ?? '');

            if ($name === '') {
                echo json_encode(['ok' => false, 'msg' => 'نام نماینده الزامی است']);
                exit;
            }

            if ($id > 0) {
                // ویرایش
                $stmt = $pdo->prepare("UPDATE dealers SET name=?, code=?, region=?, province_id=?,
                    contact_name=?, phone=?, email=?, address=?, commission_rate=?,
                    credit_limit=?, status=?, notes=?, updated_at=NOW()
                    WHERE id=? AND is_deleted=0");
                $stmt->execute([$name, $code, $region, $province_id, $contact_name, $phone,
                    $email, $address, $commission_rate, $credit_limit, $status, $notes, $id]);
                echo json_encode(['ok' => true, 'msg' => 'نماینده ویرایش شد']);
            } else {
                // افزودن
                $stmt = $pdo->prepare("INSERT INTO dealers
                    (name, code, region, province_id, contact_name, phone, email, address,
                     commission_rate, credit_limit, status, notes, created_by, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
                $stmt->execute([$name, $code, $region, $province_id, $contact_name, $phone,
                    $email, $address, $commission_rate, $credit_limit, $status, $notes, $userId]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['ok' => true, 'msg' => 'نماینده افزوده شد', 'id' => $newId]);
            }
            exit;
        }

        // ── حذف نرم ─────────────────────────────────────────
        if ($action === 'delete') {
            csrf_verify();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'شناسه نامعتبر']); exit; }
            $pdo->prepare("UPDATE dealers SET is_deleted=1, updated_at=NOW() WHERE id=?")
                ->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'نماینده حذف شد']);
            exit;
        }

        // ── گزارش عملکرد ماهانه ─────────────────────────────
        if ($action === 'get_performance') {
            $dealerId = (int)($_GET['dealer_id'] ?? 0);
            $months   = max(1, min(24, (int)($_GET['months'] ?? 12)));

            if ($dealerId <= 0) {
                // گزارش همه نمایندگان — جمع ماهانه
                $stmt = $pdo->prepare("
                    SELECT DATE_FORMAT(i.created_at, '%Y-%m') AS ym,
                           SUM(i.total_amount) AS total_sales,
                           COUNT(*) AS invoice_count,
                           COUNT(DISTINCT i.dealer_id) AS active_dealers
                    FROM fin_invoices i
                    WHERE i.type='sell' AND i.status='confirmed' AND i.is_deleted=0
                      AND i.dealer_id IS NOT NULL
                      AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                    GROUP BY ym
                    ORDER BY ym DESC
                ");
                $stmt->execute([$months]);
            } else {
                // گزارش یک نماینده
                $stmt = $pdo->prepare("
                    SELECT DATE_FORMAT(i.created_at, '%Y-%m') AS ym,
                           SUM(i.total_amount) AS total_sales,
                           COUNT(*) AS invoice_count,
                           d.name AS dealer_name,
                           d.commission_rate
                    FROM fin_invoices i
                    JOIN dealers d ON d.id = i.dealer_id
                    WHERE i.type='sell' AND i.status='confirmed' AND i.is_deleted=0
                      AND i.dealer_id = ?
                      AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                    GROUP BY ym
                    ORDER BY ym DESC
                ");
                $stmt->execute([$dealerId, $months]);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── آمار کارت‌ها ─────────────────────────────────────
        if ($action === 'stats') {
            $active = $pdo->query("SELECT COUNT(*) FROM dealers WHERE status='active' AND is_deleted=0")->fetchColumn();
            $monthSales = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM fin_invoices
                WHERE type='sell' AND status='confirmed' AND is_deleted=0 AND dealer_id IS NOT NULL
                AND YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())")->fetchColumn();

            // کمیسیون معوق — مجموع فروش * نرخ کمیسیون ماه جاری
            $commStmt = $pdo->query("
                SELECT COALESCE(SUM(i.total_amount * d.commission_rate / 100), 0)
                FROM fin_invoices i
                JOIN dealers d ON d.id = i.dealer_id
                WHERE i.type='sell' AND i.status='confirmed' AND i.is_deleted=0
                  AND i.dealer_id IS NOT NULL
                  AND YEAR(i.created_at)=YEAR(NOW()) AND MONTH(i.created_at)=MONTH(NOW())
            ");
            $pendingComm = $commStmt->fetchColumn();

            // بیشترین فروش این ماه
            $topDealer = $pdo->query("
                SELECT d.name, COALESCE(SUM(i.total_amount),0) AS total
                FROM dealers d
                JOIN fin_invoices i ON i.dealer_id = d.id
                WHERE i.type='sell' AND i.status='confirmed' AND i.is_deleted=0
                  AND YEAR(i.created_at)=YEAR(NOW()) AND MONTH(i.created_at)=MONTH(NOW())
                GROUP BY d.id, d.name
                ORDER BY total DESC
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok' => true,
                'active'       => (int)$active,
                'month_sales'  => (float)$monthSales,
                'pending_comm' => (float)$pendingComm,
                'top_dealer'   => $topDealer ?: null,
            ]);
            exit;
        }

        // ── لیست استان‌ها ────────────────────────────────────
        if ($action === 'provinces') {
            $rows = $pdo->query("SELECT id, name FROM crm_provinces ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'عملیات نامعتبر']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// HTML
// ═══════════════════════════════════════════════════════════
$pageTitle = 'مدیریت نمایندگان';
$basePath  = '../../';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<link rel="stylesheet" href="<?= $basePath ?>public_html/assets/css/fin_module.css">
<style>
.dealer-status-active    { background:#d1fae5; color:#065f46; }
.dealer-status-inactive  { background:#f3f4f6; color:#6b7280; }
.dealer-status-suspended { background:#fee2e2; color:#991b1b; }
.badge-status { padding:3px 10px; border-radius:20px; font-size:.78rem; font-weight:600; }
.perf-table th { background:#f8fafc; }
.tab-pane { display:none; }
.tab-pane.active { display:block; }
.fin-tabs .tab-btn { cursor:pointer; padding:8px 20px; border:none; background:transparent;
    border-bottom:3px solid transparent; font-family:Vazirmatn,Tahoma,sans-serif;
    font-size:.9rem; color:#64748b; transition:all .2s; }
.fin-tabs .tab-btn.active { border-bottom-color:#3b82f6; color:#1d4ed8; font-weight:600; }
</style>

<div class="main-content" dir="rtl">
  <div style="padding:24px">

    <!-- سربرگ -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <div>
        <h2 style="margin:0;font-size:1.4rem;font-weight:700;color:#1e293b">مدیریت نمایندگان</h2>
        <p style="margin:4px 0 0;color:#64748b;font-size:.85rem">مدیریت نمایندگان فروش و توزیع‌کنندگان</p>
      </div>
      <button class="fin-btn blue" onclick="openDrawer()">+ افزودن نماینده</button>
    </div>

    <!-- کارت‌های آمار -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px" id="statsRow">
      <div class="fin-stat-card blue"><div class="label">نمایندگان فعال</div><div class="value" id="statActive">…</div></div>
      <div class="fin-stat-card green"><div class="label">فروش این ماه</div><div class="value" id="statSales">…</div></div>
      <div class="fin-stat-card amber"><div class="label">کمیسیون معوق</div><div class="value" id="statComm">…</div></div>
      <div class="fin-stat-card purple"><div class="label">بیشترین فروش</div><div class="value" id="statTop" style="font-size:.85rem">…</div></div>
    </div>

    <!-- تب‌ها -->
    <div class="fin-tabs" style="margin-bottom:16px;border-bottom:1px solid #e2e8f0">
      <button class="tab-btn active" data-tab="list">📋 لیست نمایندگان</button>
      <button class="tab-btn" data-tab="performance">📊 گزارش عملکرد</button>
    </div>

    <!-- تب لیست -->
    <div class="tab-pane active" id="tab-list">
      <!-- فیلترها -->
      <div class="fin-panel" style="margin-bottom:16px;padding:12px 16px">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <input type="text" id="searchInput" placeholder="جستجوی نام، کد، تماس..." class="fin-input"
                 style="min-width:220px" oninput="loadList()">
          <select id="statusFilter" class="fin-input" style="width:160px" onchange="loadList()">
            <option value="">همه وضعیت‌ها</option>
            <option value="active">فعال</option>
            <option value="inactive">غیرفعال</option>
            <option value="suspended">تعلیق</option>
          </select>
          <button class="fin-btn" onclick="loadList()">🔄 بارگذاری</button>
        </div>
      </div>

      <div class="fin-panel" style="overflow-x:auto">
        <table class="fin-table" id="dealerTable">
          <thead>
            <tr>
              <th>#</th>
              <th>نام نماینده</th>
              <th>کد</th>
              <th>استان / منطقه</th>
              <th>نام تماس</th>
              <th>تلفن</th>
              <th>کمیسیون %</th>
              <th>فروش این ماه</th>
              <th>وضعیت</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody id="dealerBody">
            <tr><td colspan="10" style="text-align:center;padding:40px;color:#94a3b8">در حال بارگذاری…</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- تب عملکرد -->
    <div class="tab-pane" id="tab-performance">
      <div class="fin-panel" style="margin-bottom:16px;padding:12px 16px">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <select id="perfDealer" class="fin-input" style="min-width:200px">
            <option value="0">همه نمایندگان</option>
          </select>
          <select id="perfMonths" class="fin-input" style="width:140px">
            <option value="6">۶ ماه اخیر</option>
            <option value="12" selected>۱۲ ماه اخیر</option>
            <option value="24">۲۴ ماه اخیر</option>
          </select>
          <button class="fin-btn blue" onclick="loadPerformance()">نمایش گزارش</button>
        </div>
      </div>
      <div class="fin-panel" style="overflow-x:auto">
        <table class="fin-table perf-table">
          <thead>
            <tr>
              <th>ماه</th>
              <th>تعداد فاکتور</th>
              <th>مبلغ فروش (ریال)</th>
              <th>کمیسیون تخمینی (ریال)</th>
            </tr>
          </thead>
          <tbody id="perfBody">
            <tr><td colspan="4" style="text-align:center;padding:30px;color:#94a3b8">ابتدا فیلتر را اعمال کنید</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /padding -->
</div><!-- /main-content -->

<!-- ═══════════ Drawer افزودن/ویرایش ═══════════ -->
<div class="fin-drawer-overlay" id="drawerOverlay" onclick="closeDrawer()" style="display:none"></div>
<div class="fin-drawer" id="drawerDealer" style="display:none;width:460px">
  <div class="fin-drawer-header">
    <span id="drawerTitle">افزودن نماینده</span>
    <button onclick="closeDrawer()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#64748b">&times;</button>
  </div>
  <div class="fin-drawer-body" style="padding:20px;overflow-y:auto;max-height:calc(100vh - 80px)">
    <form id="dealerForm" onsubmit="saveDealer(event)">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" id="fId" name="id" value="0">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div style="grid-column:span 2">
          <label class="fin-label">نام نماینده <span style="color:#e11d48">*</span></label>
          <input type="text" name="name" id="fName" class="fin-input" required style="width:100%">
        </div>
        <div>
          <label class="fin-label">کد نماینده</label>
          <input type="text" name="code" id="fCode" class="fin-input" style="width:100%">
        </div>
        <div>
          <label class="fin-label">منطقه</label>
          <input type="text" name="region" id="fRegion" class="fin-input" style="width:100%">
        </div>
        <div>
          <label class="fin-label">استان</label>
          <select name="province_id" id="fProvince" class="fin-input" style="width:100%">
            <option value="">انتخاب استان...</option>
          </select>
        </div>
        <div>
          <label class="fin-label">نام تماس</label>
          <input type="text" name="contact_name" id="fContact" class="fin-input" style="width:100%">
        </div>
        <div>
          <label class="fin-label">تلفن</label>
          <input type="text" name="phone" id="fPhone" class="fin-input" style="width:100%">
        </div>
        <div>
          <label class="fin-label">ایمیل</label>
          <input type="email" name="email" id="fEmail" class="fin-input" style="width:100%">
        </div>
        <div>
          <label class="fin-label">درصد کمیسیون</label>
          <input type="number" name="commission_rate" id="fCommRate" class="fin-input"
                 min="0" max="100" step="0.01" value="0" style="width:100%">
        </div>
        <div>
          <label class="fin-label">سقف اعتبار (ریال)</label>
          <input type="text" name="credit_limit" id="fCredit" class="fin-input" value="0" style="width:100%">
        </div>
        <div>
          <label class="fin-label">وضعیت</label>
          <select name="status" id="fStatus" class="fin-input" style="width:100%">
            <option value="active">فعال</option>
            <option value="inactive">غیرفعال</option>
            <option value="suspended">تعلیق</option>
          </select>
        </div>
        <div style="grid-column:span 2">
          <label class="fin-label">آدرس</label>
          <textarea name="address" id="fAddress" class="fin-input" rows="2" style="width:100%"></textarea>
        </div>
        <div style="grid-column:span 2">
          <label class="fin-label">یادداشت</label>
          <textarea name="notes" id="fNotes" class="fin-input" rows="2" style="width:100%"></textarea>
        </div>
      </div>

      <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end">
        <button type="button" class="fin-btn" onclick="closeDrawer()">انصراف</button>
        <button type="submit" class="fin-btn blue" id="saveBtn">ذخیره</button>
      </div>
    </form>
  </div>
</div>

<script>
// ─── وضعیت جهانی ───────────────────────────────────────────
let provincesCache = [];

// ─── بارگذاری اولیه ────────────────────────────────────────
$(function () {
    loadStats();
    loadList();
    loadProvinces();

    // تب‌ها
    $('.tab-btn').on('click', function () {
        $('.tab-btn').removeClass('active');
        $('.tab-pane').removeClass('active');
        $(this).addClass('active');
        $('#tab-' + $(this).data('tab')).addClass('active');
        if ($(this).data('tab') === 'performance') loadPerformance();
    });
});

// ─── آمار کارت‌ها ──────────────────────────────────────────
function loadStats() {
    $.get('', { action: 'stats' }, function (r) {
        if (!r.ok) return;
        $('#statActive').text(r.active);
        $('#statSales').text(formatMoney(r.month_sales));
        $('#statComm').text(formatMoney(r.pending_comm));
        if (r.top_dealer) {
            $('#statTop').html('<span style="font-weight:700">' + r.top_dealer.name + '</span><br><small>' + formatMoney(r.top_dealer.total) + ' ریال</small>');
        } else {
            $('#statTop').text('—');
        }
    });
}

// ─── لیست نمایندگان ────────────────────────────────────────
function loadList() {
    $.get('', {
        action: 'list',
        q: $('#searchInput').val(),
        status: $('#statusFilter').val()
    }, function (r) {
        const tbody = $('#dealerBody');
        if (!r.ok || !r.data.length) {
            tbody.html('<tr><td colspan="10" style="text-align:center;padding:40px;color:#94a3b8">هیچ نماینده‌ای یافت نشد</td></tr>');
            return;
        }
        let html = '';
        r.data.forEach(function (d, i) {
            const statusMap = { active: 'فعال', inactive: 'غیرفعال', suspended: 'تعلیق' };
            const statusClass = 'dealer-status-' + d.status;
            const location = [d.province_name, d.region].filter(Boolean).join(' — ') || '—';
            html += `<tr>
                <td>${i + 1}</td>
                <td><strong>${esc(d.name)}</strong></td>
                <td><code>${esc(d.code || '—')}</code></td>
                <td>${esc(location)}</td>
                <td>${esc(d.contact_name || '—')}</td>
                <td dir="ltr">${esc(d.phone || '—')}</td>
                <td style="text-align:center">${d.commission_rate}%</td>
                <td>${formatMoney(d.month_sales)} ریال</td>
                <td><span class="badge-status ${statusClass}">${statusMap[d.status] || d.status}</span></td>
                <td>
                  <button class="fin-btn" style="padding:4px 10px;font-size:.75rem" onclick='editDealer(${JSON.stringify(d)})'>ویرایش</button>
                  <button class="fin-btn rose" style="padding:4px 10px;font-size:.75rem" onclick="deleteDealer(${d.id})">حذف</button>
                </td>
              </tr>`;
        });
        tbody.html(html);
        // بارگذاری لیست در dropdown عملکرد
        let opts = '<option value="0">همه نمایندگان</option>';
        r.data.forEach(d => { opts += `<option value="${d.id}">${esc(d.name)}</option>`; });
        $('#perfDealer').html(opts);
    });
}

// ─── استان‌ها ───────────────────────────────────────────────
function loadProvinces() {
    $.get('', { action: 'provinces' }, function (r) {
        if (!r.ok) return;
        provincesCache = r.data;
        let opts = '<option value="">انتخاب استان...</option>';
        r.data.forEach(p => { opts += `<option value="${p.id}">${esc(p.name)}</option>`; });
        $('#fProvince').html(opts);
    });
}

// ─── باز کردن drawer ────────────────────────────────────────
function openDrawer(data) {
    if (data) {
        $('#drawerTitle').text('ویرایش نماینده');
        $('#fId').val(data.id);
        $('#fName').val(data.name);
        $('#fCode').val(data.code || '');
        $('#fRegion').val(data.region || '');
        $('#fProvince').val(data.province_id || '');
        $('#fContact').val(data.contact_name || '');
        $('#fPhone').val(data.phone || '');
        $('#fEmail').val(data.email || '');
        $('#fCommRate').val(data.commission_rate || 0);
        $('#fCredit').val(data.credit_limit || 0);
        $('#fStatus').val(data.status || 'active');
        $('#fAddress').val(data.address || '');
        $('#fNotes').val(data.notes || '');
    } else {
        $('#drawerTitle').text('افزودن نماینده');
        $('#dealerForm')[0].reset();
        $('#fId').val(0);
    }
    $('#drawerOverlay, #drawerDealer').show();
}

function editDealer(data) { openDrawer(data); }

function closeDrawer() {
    $('#drawerOverlay, #drawerDealer').hide();
}

// ─── ذخیره نماینده ─────────────────────────────────────────
function saveDealer(e) {
    e.preventDefault();
    $('#saveBtn').prop('disabled', true).text('در حال ذخیره…');
    $.post('', $('#dealerForm').serialize() + '&action=save', function (r) {
        if (r.ok) {
            closeDrawer();
            loadList();
            loadStats();
            showToast(r.msg, 'success');
        } else {
            showToast(r.msg || 'خطا در ذخیره', 'error');
        }
    }).always(function () {
        $('#saveBtn').prop('disabled', false).text('ذخیره');
    });
}

// ─── حذف نماینده ───────────────────────────────────────────
function deleteDealer(id) {
    if (!confirm('آیا از حذف این نماینده مطمئن هستید؟')) return;
    $.post('', { action: 'delete', id: id, <?php if (function_exists('csrf_token')) echo "'csrf_token': '" . csrf_token() . "'"; ?> }, function (r) {
        if (r.ok) { loadList(); loadStats(); showToast(r.msg, 'success'); }
        else showToast(r.msg || 'خطا در حذف', 'error');
    });
}

// ─── گزارش عملکرد ──────────────────────────────────────────
function loadPerformance() {
    const dealerId = $('#perfDealer').val();
    const months   = $('#perfMonths').val();
    $('#perfBody').html('<tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8">در حال بارگذاری…</td></tr>');
    $.get('', { action: 'get_performance', dealer_id: dealerId, months: months }, function (r) {
        if (!r.ok || !r.data.length) {
            $('#perfBody').html('<tr><td colspan="4" style="text-align:center;padding:30px;color:#94a3b8">داده‌ای یافت نشد</td></tr>');
            return;
        }
        // نرخ کمیسیون از اولین ردیف (در صورت وجود)
        const commRate = parseFloat(r.data[0]?.commission_rate || 0);
        let html = '';
        let totalSales = 0;
        r.data.forEach(function (row) {
            const sales = parseFloat(row.total_sales || 0);
            totalSales += sales;
            const rate  = parseFloat(row.commission_rate || commRate || 0);
            const comm  = sales * rate / 100;
            html += `<tr>
                <td>${esc(row.ym)}</td>
                <td style="text-align:center">${row.invoice_count}</td>
                <td>${formatMoney(sales)} ریال</td>
                <td>${formatMoney(comm)} ریال</td>
              </tr>`;
        });
        html += `<tr style="font-weight:700;background:#f8fafc">
            <td>جمع کل</td>
            <td></td>
            <td>${formatMoney(totalSales)} ریال</td>
            <td>—</td>
          </tr>`;
        $('#perfBody').html(html);
    });
}

// ─── ابزارهای کمکی ─────────────────────────────────────────
function formatMoney(n) {
    n = parseFloat(n) || 0;
    return n.toLocaleString('fa-IR');
}
function esc(s) {
    return $('<span>').text(s || '').html();
}
function showToast(msg, type) {
    const color = type === 'success' ? '#065f46' : '#991b1b';
    const bg    = type === 'success' ? '#d1fae5' : '#fee2e2';
    const el    = $('<div>').text(msg).css({
        position: 'fixed', bottom: '24px', left: '50%', transform: 'translateX(-50%)',
        background: bg, color: color, padding: '10px 24px', borderRadius: '8px',
        fontFamily: 'Vazirmatn,Tahoma,sans-serif', zIndex: 9999, fontSize: '.9rem',
        boxShadow: '0 4px 12px rgba(0,0,0,.1)'
    }).appendTo('body');
    setTimeout(() => el.fadeOut(400, () => el.remove()), 3000);
}
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
