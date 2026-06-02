<?php
/*
 * فایل: public_html/admin/inv_storerooms.php
 * ماژول انبارداری — مدیریت انبارها
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// بررسی احراز هویت
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'نشست منقضی شده است.']);
        exit;
    }
    header('Location: ../login.php'); exit;
}

$userId  = (int)$_SESSION['user_id'];
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

// ─── ساخت جداول در صورت نبود ──────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `inv_storerooms` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `code` VARCHAR(20) NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `address` VARCHAR(255) NULL,
        `manager_id` INT UNSIGNED NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `notes` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `inv_tickets` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `ticket_number` VARCHAR(30) NOT NULL,
        `type` ENUM('receipt','dispatch','transfer','return') NOT NULL DEFAULT 'receipt',
        `ticket_date` VARCHAR(12) NOT NULL,
        `ticket_date_g` DATE NULL,
        `storeroom_id` INT UNSIGNED NOT NULL,
        `dest_storeroom_id` INT UNSIGNED NULL,
        `ref_type` VARCHAR(30) NULL,
        `ref_id` INT UNSIGNED NULL,
        `person_id` INT UNSIGNED NULL,
        `person_name` VARCHAR(150) NULL,
        `description` TEXT NULL,
        `status` ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
        `created_by` INT UNSIGNED NOT NULL,
        `confirmed_by` INT UNSIGNED NULL,
        `confirmed_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY `uq_ticket_number` (`ticket_number`),
        KEY `idx_type` (`type`),
        KEY `idx_storeroom` (`storeroom_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT IGNORE INTO `inv_storerooms` (code, name, is_active) VALUES ('ST01','انبار مرکزی',1), ('ST02','انبار مجازی',1)");
} catch (Throwable $e) {}

// ─── AJAX handlers ────────────────────────────────────────────
if ($isAjax) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // ── لیست انبارها ──────────────────────────────────────────
    if ($action === 'list') {
        try {
            $stmt = $pdo->query("
                SELECT
                    s.id, s.code, s.name, s.address, s.is_active,
                    s.notes, s.manager_id,
                    COALESCE(u.fullname, '') AS manager_name,
                    COUNT(t.id) AS ticket_count
                FROM inv_storerooms s
                LEFT JOIN users u ON s.manager_id = u.id
                LEFT JOIN inv_tickets t ON t.storeroom_id = s.id AND t.is_deleted = 0
                GROUP BY s.id
                ORDER BY s.is_active DESC, s.code ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'data' => $rows]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'خطا در واکشی داده‌ها']);
        }
        exit;
    }

    // ── دریافت یک انبار ───────────────────────────────────────
    if ($action === 'get_one') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'شناسه نامعتبر']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT * FROM inv_storerooms WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'msg' => 'انبار یافت نشد']); exit; }
            echo json_encode(['ok' => true, 'data' => $row]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'خطا در واکشی']);
        }
        exit;
    }

    // ── ذخیره (افزودن / ویرایش) ───────────────────────────────
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['ok' => false, 'msg' => 'توکن امنیتی نامعتبر']); exit;
        }

        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $code       = trim($_POST['code'] ?? '');
        $address    = trim($_POST['address'] ?? '');
        $managerId  = (int)($_POST['manager_id'] ?? 0) ?: null;
        $notes      = trim($_POST['notes'] ?? '');
        $isActive   = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            echo json_encode(['ok' => false, 'msg' => 'نام انبار الزامی است']); exit;
        }

        try {
            // تولید کد خودکار در صورت خالی بودن
            if ($code === '') {
                $maxStmt = $pdo->query("SELECT code FROM inv_storerooms WHERE code REGEXP '^ST[0-9]+$' ORDER BY CAST(SUBSTRING(code,3) AS UNSIGNED) DESC LIMIT 1");
                $lastCode = $maxStmt->fetchColumn();
                if ($lastCode) {
                    $lastNum = (int)substr($lastCode, 2);
                    $code = 'ST' . str_pad($lastNum + 1, 2, '0', STR_PAD_LEFT);
                } else {
                    $code = 'ST01';
                }
            }

            if ($id > 0) {
                // ویرایش: بررسی تکراری نبودن کد (به جز خودش)
                $chk = $pdo->prepare("SELECT id FROM inv_storerooms WHERE code = ? AND id != ?");
                $chk->execute([$code, $id]);
                if ($chk->fetchColumn()) {
                    echo json_encode(['ok' => false, 'msg' => 'این کد انبار قبلاً استفاده شده است']); exit;
                }

                $stmt = $pdo->prepare("
                    UPDATE inv_storerooms
                    SET code=?, name=?, address=?, manager_id=?, notes=?, is_active=?
                    WHERE id=?
                ");
                $stmt->execute([$code, $name, $address ?: null, $managerId, $notes ?: null, $isActive, $id]);
                echo json_encode(['ok' => true, 'msg' => 'انبار با موفقیت ویرایش شد']);
            } else {
                // افزودن: بررسی تکراری نبودن کد
                $chk = $pdo->prepare("SELECT id FROM inv_storerooms WHERE code = ?");
                $chk->execute([$code]);
                if ($chk->fetchColumn()) {
                    echo json_encode(['ok' => false, 'msg' => 'این کد انبار قبلاً استفاده شده است']); exit;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO inv_storerooms (code, name, address, manager_id, notes, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$code, $name, $address ?: null, $managerId, $notes ?: null, $isActive]);
                echo json_encode(['ok' => true, 'msg' => 'انبار با موفقیت افزوده شد', 'new_id' => $pdo->lastInsertId()]);
            }
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'خطا در ذخیره‌سازی: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── تغییر وضعیت فعال/غیرفعال ─────────────────────────────
    if ($action === 'toggle_active' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['ok' => false, 'msg' => 'توکن امنیتی نامعتبر']); exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'شناسه نامعتبر']); exit; }
        try {
            $stmt = $pdo->prepare("UPDATE inv_storerooms SET is_active = 1 - is_active WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'وضعیت انبار تغییر یافت']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'خطا در تغییر وضعیت']);
        }
        exit;
    }

    // ── حذف انبار ─────────────────────────────────────────────
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['ok' => false, 'msg' => 'توکن امنیتی نامعتبر']); exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'شناسه نامعتبر']); exit; }
        try {
            // بررسی وجود حواله/رسید برای این انبار
            $chk = $pdo->prepare("SELECT COUNT(*) FROM inv_tickets WHERE storeroom_id = ? AND is_deleted = 0");
            $chk->execute([$id]);
            if ((int)$chk->fetchColumn() > 0) {
                echo json_encode(['ok' => false, 'msg' => 'این انبار دارای رسید/حواله است و قابل حذف نیست']); exit;
            }
            $stmt = $pdo->prepare("DELETE FROM inv_storerooms WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'انبار با موفقیت حذف شد']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'خطا در حذف']);
        }
        exit;
    }

    // اکشن ناشناخته
    echo json_encode(['ok' => false, 'msg' => 'درخواست نامعتبر']); exit;
}

// ─── داده‌های مورد نیاز صفحه ──────────────────────────────────

// لیست کاربران برای انتخاب مدیر انبار
$usersList = [];
try {
    $stmt = $pdo->query("SELECT id, fullname FROM users WHERE status='active' ORDER BY fullname");
    $usersList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// آمار انبارها برای کارت‌های بالا
$statTotal  = 0;
$statActive = 0;
$statInactive = 0;
try {
    $st = $pdo->query("SELECT COUNT(*) AS total, SUM(is_active) AS active FROM inv_storerooms");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $statTotal    = (int)($row['total'] ?? 0);
    $statActive   = (int)($row['active'] ?? 0);
    $statInactive = $statTotal - $statActive;
} catch (Throwable $e) {}

// توکن CSRF برای JS
$csrfToken = csrf_field(); // HTML hidden input — مقدار توکن را جداگانه نیاز داریم
// استخراج مستقیم توکن از session
$rawCsrfToken = $_SESSION['csrf_token'] ?? '';

$pageTitle = 'مدیریت انبارها';
$basePath  = '../..';
include __DIR__ . '/../../templates/header.php';
?>
<link rel="stylesheet" href="../../assets/css/fin_module.css">
<style>
  /* استایل‌های اختصاصی مدیریت انبارها */
  .inv-header {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border-radius: 12px;
    padding: 24px 28px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
  }
  .inv-header-title { font-size: 1.4rem; font-weight: 700; margin-bottom: 4px; }
  .inv-header-sub   { font-size: .875rem; opacity: .8; }

  /* جدول */
  .action-btn-group { display: flex; gap: 6px; }
</style>

<div class="container-fluid py-3 px-3 px-md-4" dir="rtl">

  <!-- هدر صفحه -->
  <div class="inv-header">
    <div>
      <div class="inv-header-title">🏪 مدیریت انبارها</div>
      <div class="inv-header-sub">تعریف، ویرایش و مدیریت انبارهای سازمان</div>
    </div>
    <button class="fin-btn fin-btn-primary" onclick="openDrawer()">
      + انبار جدید
    </button>
  </div>

  <!-- کارت‌های آماری -->
  <div class="fin-stats-grid mb-4" style="grid-template-columns:repeat(3,1fr); max-width:600px;">
    <div class="fin-stat-card blue">
      <div class="fin-stat-icon">🏭</div>
      <div class="fin-stat-label">کل انبارها</div>
      <div class="fin-stat-value" id="statTotal"><?= $statTotal ?></div>
      <div class="fin-stat-unit">انبار تعریف‌شده</div>
    </div>
    <div class="fin-stat-card green">
      <div class="fin-stat-icon">✅</div>
      <div class="fin-stat-label">انبارهای فعال</div>
      <div class="fin-stat-value" id="statActive"><?= $statActive ?></div>
      <div class="fin-stat-unit">در حال استفاده</div>
    </div>
    <div class="fin-stat-card rose">
      <div class="fin-stat-icon">⛔</div>
      <div class="fin-stat-label">غیرفعال</div>
      <div class="fin-stat-value" id="statInactive"><?= $statInactive ?></div>
      <div class="fin-stat-unit">معلق</div>
    </div>
  </div>

  <!-- جدول انبارها -->
  <div class="fin-panel">
    <div class="fin-panel-title">لیست انبارها</div>
    <div id="tableWrapper" style="overflow-x:auto;">
      <table class="fin-table" id="storeroomTable">
        <thead>
          <tr>
            <th style="width:50px;">ردیف</th>
            <th>کد</th>
            <th>نام انبار</th>
            <th>آدرس</th>
            <th>مدیر انبار</th>
            <th>رسید/حواله</th>
            <th>وضعیت</th>
            <th style="width:140px;">عملیات</th>
          </tr>
        </thead>
        <tbody id="storeroomBody">
          <tr>
            <td colspan="8" style="text-align:center; padding:40px; color:#9ca3af;">
              <div style="font-size:1.5rem;">⏳</div>
              در حال بارگذاری...
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /container -->

<!-- ─── Drawer فرم انبار ─────────────────────────────────────── -->
<div class="fin-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="fin-drawer" id="storeroomDrawer">
  <div class="fin-drawer-header">
    <span id="drawerTitle">انبار جدید</span>
    <button class="fin-drawer-close" onclick="closeDrawer()">✕</button>
  </div>
  <div class="fin-drawer-body">
    <form id="storeroomForm" onsubmit="saveForm(event)">
      <input type="hidden" id="fId" name="id" value="0">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="csrf_token" id="fCsrf" value="<?= htmlspecialchars($rawCsrfToken) ?>">

      <!-- کد انبار -->
      <div class="fin-form-group">
        <label for="fCode">کد انبار</label>
        <input type="text" class="fin-input" id="fCode" name="code"
               placeholder="خودکار تولید می‌شود (اختیاری)" maxlength="20">
        <small style="color:#6b7280;">اگر خالی باشد، کد به‌صورت خودکار تعیین می‌شود.</small>
      </div>

      <!-- نام انبار -->
      <div class="fin-form-group">
        <label for="fName">نام انبار <span style="color:#ef4444;">*</span></label>
        <input type="text" class="fin-input" id="fName" name="name"
               placeholder="مثال: انبار مرکزی" maxlength="100" required>
      </div>

      <!-- آدرس -->
      <div class="fin-form-group">
        <label for="fAddress">آدرس</label>
        <input type="text" class="fin-input" id="fAddress" name="address"
               placeholder="آدرس انبار (اختیاری)" maxlength="255">
      </div>

      <!-- مدیر انبار -->
      <div class="fin-form-group">
        <label for="fManager">مدیر انبار</label>
        <select class="fin-select" id="fManager" name="manager_id">
          <option value="">— بدون مدیر —</option>
          <?php foreach ($usersList as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['fullname']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- یادداشت -->
      <div class="fin-form-group">
        <label for="fNotes">یادداشت</label>
        <textarea class="fin-textarea" id="fNotes" name="notes"
                  rows="3" placeholder="توضیحات اضافی..."></textarea>
      </div>

      <!-- وضعیت فعال -->
      <div class="fin-form-group" style="display:flex; align-items:center; gap:10px;">
        <input type="checkbox" id="fIsActive" name="is_active" value="1"
               style="width:18px;height:18px;" checked>
        <label for="fIsActive" style="margin:0; cursor:pointer;">انبار فعال باشد</label>
      </div>
    </form>
  </div>
  <div class="fin-drawer-footer">
    <button class="fin-btn fin-btn-success" onclick="saveForm()">
      💾 ذخیره
    </button>
    <button class="fin-btn fin-btn-outline" onclick="closeDrawer()">
      انصراف
    </button>
  </div>
</div>

<!-- ─── Toast اعلان ─────────────────────────────────────────── -->
<div id="toastContainer" style="position:fixed; bottom:24px; left:24px; z-index:9999; display:flex; flex-direction:column; gap:8px;"></div>

<script>
// ─── متغیرهای سراسری ─────────────────────────────────────────
const CSRF_TOKEN = <?= json_encode($rawCsrfToken) ?>;

// ─── نمایش toast ─────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const c = document.getElementById('toastContainer');
    const t = document.createElement('div');
    const colors = {
        success: { bg:'#dcfce7', border:'#16a34a', color:'#166534' },
        error:   { bg:'#fee2e2', border:'#ef4444', color:'#991b1b' },
        info:    { bg:'#dbeafe', border:'#2563eb', color:'#1e40af' },
    };
    const cl = colors[type] || colors.info;
    t.style.cssText = `
        background:${cl.bg}; border:1px solid ${cl.border}; color:${cl.color};
        padding:12px 20px; border-radius:8px; font-size:.875rem;
        box-shadow:0 4px 12px rgba(0,0,0,.1); min-width:220px; max-width:340px;
        animation: fadeInUp .3s ease;
    `;
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

// ─── بارگذاری لیست انبارها ────────────────────────────────────
function loadList() {
    fetch('inv_storerooms.php?action=list', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.ok) { showToast(res.msg || 'خطا در بارگذاری', 'error'); return; }
        renderTable(res.data);
        updateStats(res.data);
    })
    .catch(() => showToast('خطا در ارتباط با سرور', 'error'));
}

// ─── رندر جدول ───────────────────────────────────────────────
function renderTable(rows) {
    const tbody = document.getElementById('storeroomBody');
    if (!rows || rows.length === 0) {
        tbody.innerHTML = `
            <tr>
              <td colspan="8">
                <div class="fin-empty">
                  <div style="font-size:2.5rem;">🏭</div>
                  <div>هنوز انباری تعریف نشده است.</div>
                  <button class="fin-btn fin-btn-primary fin-btn-sm mt-2" onclick="openDrawer()">افزودن انبار</button>
                </div>
              </td>
            </tr>`;
        return;
    }
    tbody.innerHTML = rows.map((r, idx) => {
        const statusBadge = r.is_active == 1
            ? `<span class="fin-badge green">فعال</span>`
            : `<span class="fin-badge" style="background:#f3f4f6;color:#6b7280;">غیرفعال</span>`;

        const hasTickets = parseInt(r.ticket_count) > 0;
        const deleteBtn = hasTickets
            ? `<button class="fin-btn fin-btn-sm fin-btn-danger fin-btn-icon" disabled title="دارای رسید/حواله — قابل حذف نیست" style="opacity:.4;">🗑️</button>`
            : `<button class="fin-btn fin-btn-sm fin-btn-danger fin-btn-icon" onclick="deleteStoreroom(${r.id}, '${escHtml(r.name)}')" title="حذف">🗑️</button>`;

        const toggleTitle = r.is_active == 1 ? 'غیرفعال‌سازی' : 'فعال‌سازی';

        return `<tr>
            <td>${idx + 1}</td>
            <td><code>${escHtml(r.code)}</code></td>
            <td><strong>${escHtml(r.name)}</strong></td>
            <td>${r.address ? escHtml(r.address) : '<span style="color:#9ca3af;">—</span>'}</td>
            <td>${r.manager_name ? escHtml(r.manager_name) : '<span style="color:#9ca3af;">—</span>'}</td>
            <td>
                ${parseInt(r.ticket_count) > 0
                    ? `<span class="fin-badge blue">${r.ticket_count} سند</span>`
                    : '<span style="color:#9ca3af;">—</span>'}
            </td>
            <td>${statusBadge}</td>
            <td>
                <div class="action-btn-group">
                    <button class="fin-btn fin-btn-sm fin-btn-outline fin-btn-icon"
                            onclick="openDrawer(${JSON.stringify(r)})" title="ویرایش">✏️</button>
                    <button class="fin-btn fin-btn-sm fin-btn-outline fin-btn-icon"
                            onclick="toggleActive(${r.id})" title="${toggleTitle}">🔄</button>
                    ${deleteBtn}
                </div>
            </td>
        </tr>`;
    }).join('');
}

// ─── به‌روزرسانی کارت‌های آماری ──────────────────────────────
function updateStats(rows) {
    const total    = rows.length;
    const active   = rows.filter(r => r.is_active == 1).length;
    const inactive = total - active;
    document.getElementById('statTotal').textContent    = total;
    document.getElementById('statActive').textContent   = active;
    document.getElementById('statInactive').textContent = inactive;
}

// ─── باز کردن drawer (افزودن یا ویرایش) ─────────────────────
function openDrawer(data = null) {
    const form = document.getElementById('storeroomForm');
    form.reset();
    document.getElementById('fIsActive').checked = true;

    if (data) {
        document.getElementById('drawerTitle').textContent = 'ویرایش انبار';
        document.getElementById('fId').value        = data.id;
        document.getElementById('fCode').value      = data.code || '';
        document.getElementById('fName').value      = data.name || '';
        document.getElementById('fAddress').value   = data.address || '';
        document.getElementById('fManager').value   = data.manager_id || '';
        document.getElementById('fNotes').value     = data.notes || '';
        document.getElementById('fIsActive').checked = data.is_active == 1;
    } else {
        document.getElementById('drawerTitle').textContent = 'انبار جدید';
        document.getElementById('fId').value = '0';
    }

    document.getElementById('fCsrf').value = CSRF_TOKEN;
    document.getElementById('storeroomDrawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('fName').focus();
}

// ─── بستن drawer ─────────────────────────────────────────────
function closeDrawer() {
    document.getElementById('storeroomDrawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
}

// ─── ذخیره فرم ───────────────────────────────────────────────
function saveForm(e) {
    if (e) e.preventDefault();

    const name = document.getElementById('fName').value.trim();
    if (!name) { showToast('نام انبار الزامی است', 'error'); document.getElementById('fName').focus(); return; }

    const formData = new FormData(document.getElementById('storeroomForm'));
    // اگر checkbox تیک نخورده باشد، مقدار آن در FormData نیست
    if (!document.getElementById('fIsActive').checked) {
        formData.delete('is_active');
    }

    fetch('inv_storerooms.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            showToast(res.msg, 'success');
            closeDrawer();
            loadList();
        } else {
            showToast(res.msg || 'خطا در ذخیره', 'error');
        }
    })
    .catch(() => showToast('خطا در ارتباط با سرور', 'error'));
}

// ─── تغییر وضعیت فعال/غیرفعال ───────────────────────────────
function toggleActive(id) {
    if (!confirm('وضعیت این انبار تغییر می‌کند. ادامه می‌دهید؟')) return;

    const fd = new FormData();
    fd.append('action', 'toggle_active');
    fd.append('id', id);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('inv_storerooms.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) { showToast(res.msg, 'success'); loadList(); }
        else showToast(res.msg || 'خطا', 'error');
    })
    .catch(() => showToast('خطا در ارتباط', 'error'));
}

// ─── حذف انبار ───────────────────────────────────────────────
function deleteStoreroom(id, name) {
    if (!confirm(`آیا از حذف انبار "${name}" اطمینان دارید؟\nاین عمل قابل بازگشت نیست.`)) return;

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('inv_storerooms.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) { showToast(res.msg, 'success'); loadList(); }
        else showToast(res.msg || 'خطا در حذف', 'error');
    })
    .catch(() => showToast('خطا در ارتباط', 'error'));
}

// ─── تابع کمکی: escape HTML ──────────────────────────────────
function escHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ─── انیمیشن toast ───────────────────────────────────────────
const toastStyle = document.createElement('style');
toastStyle.textContent = `
@keyframes fadeInUp {
    from { opacity:0; transform:translateY(16px); }
    to   { opacity:1; transform:translateY(0); }
}`;
document.head.appendChild(toastStyle);

// ─── بارگذاری اولیه ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', loadList);
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
