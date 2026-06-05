<?php
/*
 * فایل: public_html/admin/inv_dashboard.php
 * ماژول انبارداری — داشبورد اصلی
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// بررسی دسترسی
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php'); exit;
}
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

// ─── ساخت خودکار جداول در صورت نبود ──────────────────────────
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
} catch (Throwable $e) {}

try {
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
        KEY `idx_date` (`ticket_date`),
        KEY `idx_storeroom` (`storeroom_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `inv_ticket_items` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `ticket_id` INT UNSIGNED NOT NULL,
        `stuff_id` INT UNSIGNED NOT NULL,
        `stuff_code` VARCHAR(50) NULL,
        `description` VARCHAR(255) NULL,
        `unit` VARCHAR(30) NULL DEFAULT 'عدد',
        `qty` DECIMAL(12,3) NOT NULL DEFAULT 1,
        `unit_price` BIGINT NOT NULL DEFAULT 0,
        `total` BIGINT NOT NULL DEFAULT 0,
        `sort_order` TINYINT NOT NULL DEFAULT 0,
        KEY `idx_ticket` (`ticket_id`),
        KEY `idx_stuff` (`stuff_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

try {
    $pdo->exec("INSERT IGNORE INTO `inv_storerooms` (code, name, is_active) VALUES ('ST01','انبار مرکزی',1), ('ST02','انبار مجازی',1)");
} catch (Throwable $e) {}

// ─── واکشی داده‌ها ─────────────────────────────────────────────

// تعداد کل کالاها
$totalStuffs = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM stuffs WHERE is_delete=0");
    $totalStuffs = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// جمع موجودی کل از لیست قیمت
$totalInventory = 0;
try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(total_inventory),0) FROM stuff_price_list");
    $totalInventory = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// کالاهای نزدیک انقضا یا منقضی (از جدول inv_batch_stock)
$expiryAlerts  = [];
$expiredItems  = [];
$todayJalali   = jdate('Y/m/d');
$plus30Jalali  = jdate('Y/m/d', mktime(0,0,0,(int)jdate('m'),(int)jdate('d')+30,(int)jdate('Y')));
try {
    $stmt = $pdo->prepare("
        SELECT s.stuff_name, b.batch_number, b.expiry_date, SUM(b.qty) AS qty
        FROM inv_batch_stock b
        JOIN stuffs s ON s.id = b.stuff_id
        WHERE b.qty > 0 AND b.expiry_date IS NOT NULL
          AND b.expiry_date < ? AND b.is_deleted = 0
        GROUP BY b.stuff_id, b.batch_number, b.expiry_date
        ORDER BY b.expiry_date ASC LIMIT 10
    ");
    $stmt->execute([$todayJalali]);
    $expiredItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
try {
    $stmt = $pdo->prepare("
        SELECT s.stuff_name, b.batch_number, b.expiry_date, SUM(b.qty) AS qty
        FROM inv_batch_stock b
        JOIN stuffs s ON s.id = b.stuff_id
        WHERE b.qty > 0 AND b.expiry_date IS NOT NULL
          AND b.expiry_date >= ? AND b.expiry_date <= ? AND b.is_deleted = 0
        GROUP BY b.stuff_id, b.batch_number, b.expiry_date
        ORDER BY b.expiry_date ASC LIMIT 15
    ");
    $stmt->execute([$todayJalali, $plus30Jalali]);
    $expiryAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// کالاهای با موجودی کم (بین ۱ تا ۵)
$lowStockItems = [];
try {
    $stmt = $pdo->query("
        SELECT s.stuff_name, s.stuff_code, p.total_inventory
        FROM stuffs s
        JOIN stuff_price_list p ON s.stuff_code = p.stuff_code
        WHERE p.total_inventory <= 5 AND p.total_inventory > 0 AND s.is_delete = 0
        ORDER BY p.total_inventory ASC
        LIMIT 10
    ");
    $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// تعداد کالاهای بدون موجودی
$zeroStockCount = 0;
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM stuffs s
        LEFT JOIN stuff_price_list p ON s.stuff_code = p.stuff_code
        WHERE (p.total_inventory IS NULL OR p.total_inventory = 0) AND s.is_delete = 0
    ");
    $zeroStockCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// تعداد انبارهای فعال
$storeroomCount = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM inv_storerooms WHERE is_active = 1");
    $storeroomCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// آخرین رسید/حواله‌ها
$recentTickets = [];
try {
    $stmt = $pdo->query("
        SELECT t.*, s.name AS storeroom_name
        FROM inv_tickets t
        LEFT JOIN inv_storerooms s ON t.storeroom_id = s.id
        WHERE t.is_deleted = 0
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    $recentTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// پرتحرک‌ترین کالاها بر اساس فرکانس
$topItems = [];
try {
    $stmt = $pdo->query("
        SELECT s.stuff_name, SUM(i.qty) AS total_qty, COUNT(*) AS freq
        FROM inv_ticket_items i
        JOIN stuffs s ON i.stuff_id = s.id
        JOIN inv_tickets t ON i.ticket_id = t.id
        WHERE t.is_deleted = 0 AND t.status = 'confirmed'
        GROUP BY i.stuff_id
        ORDER BY freq DESC
        LIMIT 8
    ");
    $topItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// تاریخ امروز شمسی
$todayJalali = jdate('Y/m/d');

// ─── آماده‌سازی متغیرهای صفحه ─────────────────────────────────
$pageTitle = 'داشبورد انبارداری';
$basePath = '../../';

include __DIR__ . '/../../templates/header.php';
?>
<link rel="stylesheet" href="../../assets/css/fin_module.css">
<style>
  /* استایل‌های اختصاصی داشبورد انبار */
  .inv-header {
    background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
    border-radius: 12px;
    padding: 24px 28px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
  }
  .inv-header-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 4px; }
  .inv-header-sub { font-size: 0.875rem; opacity: .8; }

  .inv-two-col {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 20px;
    align-items: start;
  }
  @media (max-width: 900px) { .inv-two-col { grid-template-columns: 1fr; } }

  /* progress bar موجودی کم */
  .inv-stock-bar {
    height: 8px;
    border-radius: 4px;
    background: #e5e7eb;
    overflow: hidden;
    margin-top: 4px;
  }
  .inv-stock-bar-fill { height: 100%; border-radius: 4px; transition: width .3s; }
  .inv-stock-bar-fill.red   { background: #ef4444; }
  .inv-stock-bar-fill.amber { background: #f59e0b; }

  /* کارت انبارها */
  .inv-storeroom-card {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    margin-bottom: 16px;
  }
  .inv-storeroom-card .count { font-size: 2.5rem; font-weight: 800; color: #16a34a; }
  .inv-storeroom-card .label { color: #6b7280; font-size: 0.875rem; margin-top: 4px; }

  /* رتبه‌بندی کالاها */
  .top-item-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f3f4f6;
  }
  .top-item-row:last-child { border-bottom: none; }
  .top-item-rank {
    width: 28px; height: 28px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 700; flex-shrink: 0;
  }
  .rank-1 { background:#fef3c7; color:#92400e; }
  .rank-2 { background:#f3f4f6; color:#374151; }
  .rank-3 { background:#fde8d8; color:#9a3412; }
  .rank-other { background:#f0f9ff; color:#0369a1; }

  .top-item-name { flex: 1; font-size: 0.875rem; color: #374151; }
  .top-item-qty { font-size: 0.8rem; color: #6b7280; white-space: nowrap; }
</style>

<div class="container-fluid py-3 px-3 px-md-4" dir="rtl">

  <!-- هدر صفحه -->
  <div class="inv-header">
    <div>
      <div class="inv-header-title">📦 داشبورد انبارداری</div>
      <div class="inv-header-sub">مدیریت موجودی، رسید و حواله کالا — <?= htmlspecialchars($todayJalali) ?></div>
    </div>
    <div style="font-size:3rem; opacity:.3;">🏭</div>
  </div>

  <!-- دکمه‌های سریع -->
  <div class="fin-quick-actions mb-4">
    <a href="inv_receipts.php?type=receipt" class="fin-quick-btn green">
      <span>📥</span> رسید جدید
    </a>
    <a href="inv_receipts.php?type=dispatch" class="fin-quick-btn amber">
      <span>📤</span> حواله جدید
    </a>
    <a href="inv_receipts.php?type=transfer" class="fin-quick-btn blue">
      <span>🔄</span> انتقال بین انبار
    </a>
    <a href="inv_storerooms.php" class="fin-quick-btn purple">
      <span>🏪</span> مدیریت انبارها
    </a>
    <a href="inv_kardex.php" class="fin-quick-btn" style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;">
      <span>📊</span> کاردکس
    </a>
  </div>

  <!-- کارت‌های آماری -->
  <div class="fin-stats-grid mb-4" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">

    <!-- تعداد کالاها -->
    <div class="fin-stat-card blue">
      <div class="fin-stat-icon">📦</div>
      <div class="fin-stat-label">تعداد کالاها</div>
      <div class="fin-stat-value"><?= number_format($totalStuffs) ?></div>
      <div class="fin-stat-unit">قلم کالای ثبت‌شده</div>
    </div>

    <!-- موجودی کل -->
    <div class="fin-stat-card green">
      <div class="fin-stat-icon">📊</div>
      <div class="fin-stat-label">موجودی کل</div>
      <div class="fin-stat-value"><?= number_format($totalInventory) ?></div>
      <div class="fin-stat-unit">واحد در انبار</div>
    </div>

    <!-- کالاهای کم‌موجودی -->
    <div class="fin-stat-card amber">
      <div class="fin-stat-icon">⚠️</div>
      <div class="fin-stat-label">کالاهای کم‌موجودی</div>
      <div class="fin-stat-value"><?= count($lowStockItems) ?></div>
      <div class="fin-stat-unit">موجودی ۱ تا ۵ واحد</div>
    </div>

    <!-- کالاهای اتمام موجودی -->
    <div class="fin-stat-card rose">
      <div class="fin-stat-icon">🚫</div>
      <div class="fin-stat-label">اتمام موجودی</div>
      <div class="fin-stat-value"><?= number_format($zeroStockCount) ?></div>
      <div class="fin-stat-unit">کالا بدون موجودی</div>
    </div>

  </div>

  <!-- چیدمان دو ستونی -->
  <div class="inv-two-col">

    <!-- ستون چپ: جداول اصلی -->
    <div>

      <!-- آخرین رسید و حواله‌ها -->
      <div class="fin-panel mb-4">
        <div class="fin-panel-title">
          آخرین رسید و حواله‌ها
          <a href="inv_receipts.php" class="fin-btn fin-btn-sm fin-btn-outline me-auto" style="margin-right:auto;">مشاهده همه</a>
        </div>

        <?php if (empty($recentTickets)): ?>
          <div class="fin-empty">
            <div style="font-size:3rem;">📋</div>
            <div>هنوز رسید یا حواله‌ای ثبت نشده است.</div>
            <a href="inv_receipts.php?type=receipt" class="fin-btn fin-btn-primary fin-btn-sm mt-2">ثبت اولین رسید</a>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="fin-table">
              <thead>
                <tr>
                  <th>شماره</th>
                  <th>نوع</th>
                  <th>انبار</th>
                  <th>تاریخ</th>
                  <th>وضعیت</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentTickets as $t): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($t['ticket_number']) ?></strong></td>
                  <td>
                    <?php
                    $typeMap = [
                      'receipt'  => ['label'=>'رسید',  'class'=>'green'],
                      'dispatch' => ['label'=>'حواله', 'class'=>'amber'],
                      'transfer' => ['label'=>'انتقال','class'=>'blue'],
                      'return'   => ['label'=>'مرجوع', 'class'=>'rose'],
                    ];
                    $tm = $typeMap[$t['type']] ?? ['label'=>$t['type'],'class'=>''];
                    ?>
                    <span class="fin-badge <?= $tm['class'] ?>"><?= $tm['label'] ?></span>
                  </td>
                  <td><?= htmlspecialchars($t['storeroom_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($t['ticket_date'] ?? '—') ?></td>
                  <td>
                    <?php if ($t['status'] === 'confirmed'): ?>
                      <span class="fin-badge green">تأیید شده</span>
                    <?php else: ?>
                      <span class="fin-badge" style="background:#f3f4f6;color:#6b7280;">پیش‌نویس</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="inv_receipts.php?id=<?= (int)$t['id'] ?>" class="fin-btn fin-btn-sm fin-btn-outline fin-btn-icon" title="مشاهده">👁</a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- هشدار انقضا و نزدیک به انقضا -->
      <?php if (!empty($expiredItems) || !empty($expiryAlerts)): ?>
      <div class="fin-panel" style="border-right:4px solid #ef4444;">
        <div class="fin-panel-title">🚨 هشدار تاریخ انقضا</div>
        <?php if (!empty($expiredItems)): ?>
          <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:12px;">
            <div style="font-weight:700;color:#dc2626;margin-bottom:8px;">🔴 منقضی‌شده (موجود در انبار)</div>
            <table class="fin-table" style="font-size:.82rem;">
              <thead><tr><th>کالا</th><th>Lot/Batch</th><th>تاریخ انقضا</th><th>موجودی</th></tr></thead>
              <tbody>
                <?php foreach ($expiredItems as $ei): ?>
                <tr>
                  <td><?= htmlspecialchars($ei['stuff_name']) ?></td>
                  <td><code><?= htmlspecialchars($ei['batch_number']) ?></code></td>
                  <td style="color:#ef4444;font-weight:600;"><?= htmlspecialchars($ei['expiry_date']) ?></td>
                  <td><?= number_format((float)$ei['qty']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php if (!empty($expiryAlerts)): ?>
          <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;">
            <div style="font-weight:700;color:#d97706;margin-bottom:8px;">🟡 منقضی‌شدنی ظرف ۳۰ روز</div>
            <table class="fin-table" style="font-size:.82rem;">
              <thead><tr><th>کالا</th><th>Lot/Batch</th><th>تاریخ انقضا</th><th>موجودی</th></tr></thead>
              <tbody>
                <?php foreach ($expiryAlerts as $ea): ?>
                <tr>
                  <td><?= htmlspecialchars($ea['stuff_name']) ?></td>
                  <td><code><?= htmlspecialchars($ea['batch_number']) ?></code></td>
                  <td style="color:#f59e0b;font-weight:600;"><?= htmlspecialchars($ea['expiry_date']) ?></td>
                  <td><?= number_format((float)$ea['qty']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- هشدار کالاهای کم‌موجودی -->
      <div class="fin-panel">
        <div class="fin-panel-title">⚠️ هشدار کالاهای کم‌موجودی</div>

        <?php if (empty($lowStockItems)): ?>
          <div class="fin-empty">
            <div style="font-size:2.5rem;">✅</div>
            <div>همه کالاها موجودی کافی دارند.</div>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="fin-table">
              <thead>
                <tr>
                  <th>نام کالا</th>
                  <th>کد</th>
                  <th>موجودی فعلی</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($lowStockItems as $item): ?>
                <?php
                  $qty = (int)$item['total_inventory'];
                  $pct = min(100, ($qty / 5) * 100);
                  $barClass = $qty <= 2 ? 'red' : 'amber';
                ?>
                <tr>
                  <td><?= htmlspecialchars($item['stuff_name']) ?></td>
                  <td><code><?= htmlspecialchars($item['stuff_code']) ?></code></td>
                  <td style="min-width:120px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                      <strong style="color:<?= $barClass==='red'?'#ef4444':'#f59e0b' ?>; min-width:24px;"><?= $qty ?></strong>
                      <div style="flex:1;">
                        <div class="inv-stock-bar">
                          <div class="inv-stock-bar-fill <?= $barClass ?>" style="width:<?= $pct ?>%;"></div>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div><!-- /ستون چپ -->

    <!-- ستون راست -->
    <div>

      <!-- کارت تعداد انبارها -->
      <div class="inv-storeroom-card">
        <div class="count"><?= $storeroomCount ?></div>
        <div class="label">انبار فعال</div>
        <a href="inv_storerooms.php" class="fin-btn fin-btn-sm fin-btn-outline mt-3" style="border-color:#16a34a;color:#16a34a;">مدیریت انبارها ←</a>
      </div>

      <!-- پرتحرک‌ترین کالاها -->
      <div class="fin-panel">
        <div class="fin-panel-title">🏆 پرتحرک‌ترین کالاها</div>

        <?php if (empty($topItems)): ?>
          <div class="fin-empty" style="padding:20px 0;">
            <div style="font-size:2rem;">📦</div>
            <div style="font-size:.85rem;">هنوز حواله‌ای تأیید نشده است.</div>
          </div>
        <?php else: ?>
          <?php foreach ($topItems as $idx => $item): ?>
          <?php
            $rank = $idx + 1;
            $rankClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other'));
          ?>
          <div class="top-item-row">
            <div class="top-item-rank <?= $rankClass ?>"><?= $rank ?></div>
            <div class="top-item-name"><?= htmlspecialchars($item['stuff_name']) ?></div>
            <div class="top-item-qty">
              <?= number_format((float)$item['total_qty'], 0) ?> واحد
              <div style="font-size:.75rem;color:#9ca3af;"><?= (int)$item['freq'] ?> بار</div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div><!-- /ستون راست -->

  </div><!-- /two-col -->

</div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
