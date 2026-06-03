<?php
/*
 * فایل: public_html/admin/fin_persons.php
 * ماژول مالی — مدیریت طرف حساب‌ها (مشتریان / تامین‌کنندگان / هر دو)
 * نویسنده: سیستم ERP آتنا زیست درمان
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

/* ─── شناسایی درخواست AJAX ─────────────────────────────────────── */
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/* ─── بررسی احراز هویت ─────────────────────────────────────────── */
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'نشست منقضی شده است']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}

/* ─── ساخت جدول در صورت عدم وجود ──────────────────────────────── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_persons` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `code`            VARCHAR(20) UNIQUE,
        `name`            VARCHAR(200) NOT NULL,
        `company_name`    VARCHAR(200) DEFAULT NULL,
        `type`            ENUM('customer','supplier','both') DEFAULT 'customer',
        `national_id`     VARCHAR(20)  DEFAULT NULL,
        `economic_code`   VARCHAR(20)  DEFAULT NULL,
        `tel`             VARCHAR(20)  DEFAULT NULL,
        `mobile`          VARCHAR(20)  DEFAULT NULL,
        `email`           VARCHAR(100) DEFAULT NULL,
        `address`         TEXT         DEFAULT NULL,
        `state`           VARCHAR(100) DEFAULT NULL,
        `city`            VARCHAR(100) DEFAULT NULL,
        `opening_balance` DECIMAL(20,0) DEFAULT 0,
        `customer_id`     INT          DEFAULT NULL,
        `notes`           TEXT         DEFAULT NULL,
        `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted`      TINYINT      DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");
} catch (Throwable $e) {
    /* اگر کالیشن وجود نداشت، بدون آن بساز */
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_persons` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `code`            VARCHAR(20) UNIQUE,
            `name`            VARCHAR(200) NOT NULL,
            `company_name`    VARCHAR(200) DEFAULT NULL,
            `type`            ENUM('customer','supplier','both') DEFAULT 'customer',
            `national_id`     VARCHAR(20)  DEFAULT NULL,
            `economic_code`   VARCHAR(20)  DEFAULT NULL,
            `tel`             VARCHAR(20)  DEFAULT NULL,
            `mobile`          VARCHAR(20)  DEFAULT NULL,
            `email`           VARCHAR(100) DEFAULT NULL,
            `address`         TEXT         DEFAULT NULL,
            `state`           VARCHAR(100) DEFAULT NULL,
            `city`            VARCHAR(100) DEFAULT NULL,
            `opening_balance` DECIMAL(20,0) DEFAULT 0,
            `customer_id`     INT          DEFAULT NULL,
            `notes`           TEXT         DEFAULT NULL,
            `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `is_deleted`      TINYINT      DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e2) { /* جدول از قبل وجود دارد */ }
}

/* ─── تابع تولید کد خودکار ─────────────────────────────────────── */
function generatePersonCode(PDO $pdo): string {
    $row = $pdo->query("SELECT MAX(CAST(SUBSTRING(code,2) AS UNSIGNED)) AS mx
                        FROM fin_persons WHERE code REGEXP '^P[0-9]+$'")->fetch(PDO::FETCH_ASSOC);
    $next = (int)($row['mx'] ?? 0) + 1;
    return 'P' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

/* ─── پردازش AJAX ───────────────────────────────────────────────── */
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = trim($_POST['action'] ?? '');

    /* ── لیست طرف حساب‌ها ── */
    if ($action === 'list') {
        $search = trim($_POST['search'] ?? '');
        $type   = trim($_POST['type']   ?? '');
        $where  = ['p.is_deleted = 0'];
        $params = [];
        if ($search !== '') {
            $where[] = '(p.name LIKE ? OR p.company_name LIKE ? OR p.code LIKE ? OR p.mobile LIKE ?)';
            $like = "%{$search}%";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }
        if (in_array($type, ['customer','supplier','both'])) {
            $where[] = 'p.type = ?';
            $params[] = $type;
        }
        $sql = "SELECT p.*
                FROM fin_persons p
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.id DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    /* ── ذخیره (افزودن / ویرایش) ── */
    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $name         = trim($_POST['name']          ?? '');
        $nikename     = trim($_POST['nikename']      ?? '');
        $company      = trim($_POST['company_name']  ?? '');
        $type         = trim($_POST['type']          ?? 'customer');
        $code         = trim($_POST['code']          ?? '');
        $national_id  = trim($_POST['national_id']   ?? '');
        $economic     = trim($_POST['economic_code'] ?? '');
        $tel          = trim($_POST['tel']           ?? '');
        $mobile       = trim($_POST['mobile']        ?? '');
        $email        = trim($_POST['email']         ?? '');
        $address      = trim($_POST['address']       ?? '');
        $state        = trim($_POST['state']         ?? '');
        $city         = trim($_POST['city']          ?? '');
        $opening      = (int)preg_replace('/[^0-9\-]/','',$_POST['opening_balance'] ?? '0');
        $customer_id  = (int)($_POST['customer_id']  ?? 0) ?: null;
        $notes        = trim($_POST['notes']         ?? '');
        // نام تجاری خودکار اگر خالی بود
        if (!$nikename) $nikename = $company ?: $name;

        if (!$name) { echo json_encode(['ok' => false, 'msg' => 'نام طرف حساب الزامی است']); exit; }
        if (!in_array($type, ['customer','supplier','both'])) {
            echo json_encode(['ok' => false, 'msg' => 'نوع طرف حساب نامعتبر است']); exit;
        }

        /* تولید کد خودکار اگر خالی بود */
        if ($code === '') {
            $code = generatePersonCode($pdo);
        }

        if ($id > 0) {
            /* ویرایش */
            $pdo->prepare("UPDATE fin_persons SET
                name=?, nikename=?, company_name=?, type=?, code=?,
                national_id=?, economic_code=?, shenasemeli=?, codeeghtesadi=?,
                tel=?, mobile=?, email=?, address=?, state=?, city=?,
                opening_balance=?, notes=?
                WHERE id=? AND is_deleted=0")->execute([
                $name, $nikename ?: null, $company ?: null, $type, $code,
                $national_id ?: null, $economic ?: null, $national_id ?: null, $economic ?: null,
                $tel ?: null, $mobile ?: null, $email ?: null, $address ?: null,
                $state ?: null, $city ?: null, $opening, $notes ?: null, $id
            ]);
            echo json_encode(['ok' => true, 'msg' => 'طرف حساب با موفقیت ویرایش شد', 'code' => $code]);
        } else {
            /* افزودن */
            $check = $pdo->prepare("SELECT id FROM fin_persons WHERE code=? AND is_deleted=0");
            $check->execute([$code]);
            if ($check->fetch()) { $code = generatePersonCode($pdo); }
            $pdo->prepare("INSERT INTO fin_persons
                (name, nikename, company_name, type, code,
                 national_id, economic_code, shenasemeli, codeeghtesadi,
                 tel, mobile, email, address, state, city, opening_balance, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $name, $nikename ?: null, $company ?: null, $type, $code,
                $national_id ?: null, $economic ?: null, $national_id ?: null, $economic ?: null,
                $tel ?: null, $mobile ?: null, $email ?: null, $address ?: null,
                $state ?: null, $city ?: null, $opening, $notes ?: null
            ]);
            echo json_encode(['ok' => true, 'msg' => 'طرف حساب با موفقیت افزوده شد', 'code' => $code]);
        }
        exit;
    }

    /* ── دریافت یک رکورد برای ویرایش ── */
    if ($action === 'get_one') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'msg' => 'شناسه نامعتبر']); exit; }
        $stmt = $pdo->prepare("SELECT * FROM fin_persons WHERE id=? AND is_deleted=0");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => false, 'msg' => 'رکورد یافت نشد']); exit; }
        echo json_encode(['ok' => true, 'data' => $row]);
        exit;
    }

    /* ── حذف نرم ── */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'msg' => 'شناسه نامعتبر']); exit; }
        $pdo->prepare("UPDATE fin_persons SET is_deleted=1 WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true, 'msg' => 'طرف حساب حذف شد']);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'عملیات نامعتبر']);
    exit;
}

/* ─── آمار خلاصه ────────────────────────────────────────────────── */
$stats = ['total' => 0, 'customers' => 0, 'suppliers' => 0, 'both' => 0, 'balance' => 0];
try {
    $r = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(type='customer') AS customers,
        SUM(type='supplier') AS suppliers,
        SUM(type='both') AS both_t,
        SUM(opening_balance) AS balance_sum
        FROM fin_persons WHERE is_deleted=0")->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $stats['total']     = (int)$r['total'];
        $stats['customers'] = (int)$r['customers'];
        $stats['suppliers'] = (int)$r['suppliers'];
        $stats['both']      = (int)$r['both_t'];
        $stats['balance']   = (int)$r['balance_sum'];
    }
} catch (Throwable $e) {}

/* ─── لیست مشتریان برای پیوند ─────────────────────────────────── */
$customersList = [];
try {
    $customersList = $pdo->query("SELECT id, first_name, last_name, company_name
                                   FROM customers WHERE is_deleted=0 ORDER BY first_name")
                         ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ─── استان‌های ایران ───────────────────────────────────────────── */
$iranStates = ['آذربایجان شرقی','آذربایجان غربی','اردبیل','اصفهان','البرز','ایلام',
               'بوشهر','تهران','چهارمحال و بختیاری','خراسان جنوبی','خراسان رضوی',
               'خراسان شمالی','خوزستان','زنجان','سمنان','سیستان و بلوچستان','فارس',
               'قزوین','قم','کردستان','کرمان','کرمانشاه','کهگیلویه و بویراحمد',
               'گلستان','گیلان','لرستان','مازندران','مرکزی','هرمزگان','همدان','یزد'];

/* ─── بارگذاری هدر ──────────────────────────────────────────────── */
$pageTitle = 'مدیریت طرف حساب‌ها';
$basePath  = '../../';
$extraCss  = '<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>

<div class="main-content" style="padding:0;direction:rtl;background:#f1f5f9;min-height:100vh">
<div style="padding:24px">

  <!-- ===== هدر صفحه ===== -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px">
    <div style="display:flex;align-items:center;gap:14px">
      <div style="width:50px;height:50px;border-radius:14px;background:linear-gradient(135deg,#2563eb,#3b82f6);
                  display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 4px 12px rgba(37,99,235,.3)">
        👥
      </div>
      <div>
        <h1 style="margin:0 0 3px;font-size:20px;font-weight:900;color:#0f172a">مدیریت طرف حساب‌ها</h1>
        <p style="margin:0;font-size:12px;color:#64748b">مشتریان، تامین‌کنندگان و طرف‌های حساب تجاری</p>
      </div>
    </div>
    <button onclick="openDrawer()" class="fin-btn fin-btn-primary" style="gap:8px">
      <span style="font-size:16px">＋</span> افزودن طرف حساب
    </button>
  </div>

  <!-- ===== کارت‌های آمار ===== -->
  <div class="fin-stats-grid" style="margin-bottom:24px">
    <div class="fin-stat-card blue">
      <div class="fin-stat-icon">👥</div>
      <div>
        <div class="fin-stat-label">مجموع طرف حساب‌ها</div>
        <div class="fin-stat-value"><?= number_format($stats['total']) ?></div>
      </div>
    </div>
    <div class="fin-stat-card green">
      <div class="fin-stat-icon">🛒</div>
      <div>
        <div class="fin-stat-label">مشتریان</div>
        <div class="fin-stat-value"><?= number_format($stats['customers']) ?></div>
      </div>
    </div>
    <div class="fin-stat-card amber">
      <div class="fin-stat-icon">🏭</div>
      <div>
        <div class="fin-stat-label">تامین‌کنندگان</div>
        <div class="fin-stat-value"><?= number_format($stats['suppliers']) ?></div>
      </div>
    </div>
    <div class="fin-stat-card purple">
      <div class="fin-stat-icon">🔄</div>
      <div>
        <div class="fin-stat-label">مشتری / تامین‌کننده</div>
        <div class="fin-stat-value"><?= number_format($stats['both']) ?></div>
      </div>
    </div>
    <div class="fin-stat-card cyan">
      <div class="fin-stat-icon">💰</div>
      <div>
        <div class="fin-stat-label">مجموع مانده افتتاحیه</div>
        <div class="fin-stat-value"><?= number_format($stats['balance']) ?><span class="fin-stat-unit">ریال</span></div>
      </div>
    </div>
  </div>

  <!-- ===== پنل اصلی ===== -->
  <div class="fin-panel" style="padding:0">

    <!-- نوار جستجو -->
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:16px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;border-radius:16px 16px 0 0">
      <div style="position:relative;flex:1;min-width:200px">
        <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none">🔍</span>
        <input type="text" id="searchInput" placeholder="جستجو در نام، شرکت، کد، موبایل..."
               oninput="loadList()"
               style="width:100%;padding:8px 36px 8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;
                      font-size:13px;font-family:inherit;outline:none;box-sizing:border-box;direction:rtl"
               onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#e2e8f0'">
      </div>
      <select id="typeFilter" onchange="loadList()" class="fin-select"
              style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;outline:none;cursor:pointer">
        <option value="">همه انواع</option>
        <option value="customer">🛒 مشتری</option>
        <option value="supplier">🏭 تامین‌کننده</option>
        <option value="both">🔄 هر دو</option>
      </select>
      <span id="listCount" style="font-size:12px;color:#94a3b8;white-space:nowrap"></span>
    </div>

    <!-- جدول -->
    <div style="overflow-x:auto">
      <table class="fin-table" id="personsTable">
        <thead>
          <tr>
            <th style="width:80px">کد</th>
            <th>نام / شرکت</th>
            <th style="text-align:center;width:100px">نوع</th>
            <th style="width:120px">موبایل</th>
            <th style="width:120px">استان</th>
            <th style="text-align:left;width:140px;direction:ltr">مانده افتتاحیه</th>
            <th style="text-align:center;width:110px">عملیات</th>
          </tr>
        </thead>
        <tbody id="personsTbody">
          <tr>
            <td colspan="7">
              <div style="display:flex;align-items:center;justify-content:center;padding:40px;gap:10px;color:#94a3b8;font-size:13px">
                <div style="width:20px;height:20px;border:3px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:finSpin .7s linear infinite"></div>
                در حال بارگذاری...
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

  </div><!-- /fin-panel -->

</div><!-- /padding -->
</div><!-- /main-content -->

<!-- ===== Overlay کشوی فرم ===== -->
<div class="fin-drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<!-- ===== کشوی فرم افزودن / ویرایش ===== -->
<div class="fin-drawer" id="formDrawer">
  <div class="fin-drawer-header">
    <span id="drawerTitle">افزودن طرف حساب</span>
    <button class="fin-drawer-close" onclick="closeDrawer()" title="بستن">✕</button>
  </div>

  <div class="fin-drawer-body">
    <form id="personForm" onsubmit="savePerson(event)">
      <input type="hidden" id="fId" name="id" value="0">

      <!-- بخش اطلاعات پایه -->
      <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">
        اطلاعات پایه
      </div>

      <div class="fin-form-row">
        <div class="fin-form-group" style="margin-bottom:0">
          <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">
            نام کامل <span style="color:#e11d48">*</span>
          </label>
          <input type="text" id="fName" name="name" class="fin-input" placeholder="نام و نام خانوادگی یا نام شرکت" required>
        </div>
        <div class="fin-form-group" style="margin-bottom:0">
          <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">نام تجاری / مستعار</label>
          <input type="text" id="fNikename" name="nikename" class="fin-input" placeholder="نام اختصاری (مثل hesabix)">
        </div>
      </div>

      <div style="margin-bottom:12px"></div>

      <div class="fin-form-row">
        <div class="fin-form-group" style="margin-bottom:0">
          <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">نام شرکت</label>
          <input type="text" id="fCompany" name="company_name" class="fin-input" placeholder="اختیاری">
        </div>
        <div class="fin-form-group" style="margin-bottom:0">
          <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">
            نوع <span style="color:#e11d48">*</span>
          </label>
          <select id="fType" name="type" class="fin-select">
            <option value="customer">🛒 مشتری</option>
            <option value="supplier">🏭 تامین‌کننده</option>
            <option value="both">🔄 هر دو</option>
          </select>
        </div>
      </div>

      <div style="margin-bottom:16px"></div>

      <div class="fin-form-row">
        <div class="fin-form-group" style="margin-bottom:0">
          <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">کد طرف حساب</label>
          <input type="text" id="fCode" name="code" class="fin-input" placeholder="P001 (خودکار اگر خالی باشد)">
        </div>
        <div class="fin-form-group" style="margin-bottom:0">
          <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">کد ملی / شناسه</label>
          <input type="text" id="fNationalId" name="national_id" class="fin-input ltr-num" placeholder="0000000000">
        </div>
      </div>

      <div style="margin-bottom:16px"></div>

      <div class="fin-form-group">
        <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">کد اقتصادی</label>
        <input type="text" id="fEconomic" name="economic_code" class="fin-input ltr-num" placeholder="اختیاری">
      </div>

      <hr style="border:none;border-top:1px dashed #e2e8f0;margin:16px 0">

      <!-- بخش تماس -->
      <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">
        اطلاعات تماس
      </div>

      <div class="fin-form-row">
        <div class="fin-form-group" style="margin-bottom:0">
          <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">تلفن ثابت</label>
          <input type="text" id="fTel" name="tel" class="fin-input ltr-num" placeholder="021xxxxxxxx">
        </div>
        <div class="fin-form-group" style="margin-bottom:0">
          <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">موبایل</label>
          <input type="text" id="fMobile" name="mobile" class="fin-input ltr-num" placeholder="09xxxxxxxxx">
        </div>
      </div>

      <div style="margin-bottom:16px"></div>

      <div class="fin-form-group">
        <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">ایمیل</label>
        <input type="email" id="fEmail" name="email" class="fin-input ltr-num" placeholder="example@mail.com">
      </div>

      <hr style="border:none;border-top:1px dashed #e2e8f0;margin:16px 0">

      <!-- بخش آدرس -->
      <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">
        آدرس
      </div>

      <div class="fin-form-row">
        <div class="fin-form-group" style="margin-bottom:0">
          <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">استان</label>
          <select id="fState" name="state" class="fin-select">
            <option value="">— انتخاب استان —</option>
            <?php foreach ($iranStates as $st): ?>
            <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fin-form-group" style="margin-bottom:0">
          <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">شهر</label>
          <input type="text" id="fCity" name="city" class="fin-input" placeholder="نام شهر">
        </div>
      </div>

      <div style="margin-bottom:16px"></div>

      <div class="fin-form-group">
        <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">آدرس کامل</label>
        <textarea id="fAddress" name="address" class="fin-textarea" rows="2" placeholder="آدرس دقیق..."></textarea>
      </div>

      <hr style="border:none;border-top:1px dashed #e2e8f0;margin:16px 0">

      <!-- بخش مالی -->
      <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">
        اطلاعات مالی
      </div>

      <div class="fin-form-group">
        <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">مانده افتتاحیه (ریال)</label>
        <input type="number" id="fOpening" name="opening_balance" class="fin-input ltr-num" value="0" placeholder="0">
        <div style="font-size:11px;color:#94a3b8;margin-top:4px">مثبت = طرف حساب بدهکار است | منفی = بستانکار است</div>
      </div>

      <?php if (!empty($customersList)): ?>
      <div class="fin-form-group">
        <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">پیوند به مشتری CRM</label>
        <select id="fCustomerId" name="customer_id" class="fin-select">
          <option value="">— بدون پیوند —</option>
          <?php foreach ($customersList as $cust): ?>
          <option value="<?= $cust['id'] ?>">
            <?= htmlspecialchars(trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')))
                ?: htmlspecialchars($cust['company_name'] ?? '') ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:#94a3b8;margin-top:4px">اتصال این طرف حساب به پرونده CRM</div>
      </div>
      <?php endif; ?>

      <div class="fin-form-group">
        <label style="font-size:.82rem;font-weight:700;color:#475569;display:block;margin-bottom:6px">یادداشت</label>
        <textarea id="fNotes" name="notes" class="fin-textarea" rows="2" placeholder="یادداشت اختیاری..."></textarea>
      </div>

    </form>
  </div><!-- /drawer-body -->

  <div class="fin-drawer-footer">
    <button type="button" class="fin-btn fin-btn-outline" onclick="closeDrawer()">انصراف</button>
    <button type="submit" form="personForm" id="saveBtn" class="fin-btn fin-btn-primary">
      💾 ذخیره طرف حساب
    </button>
  </div>
</div><!-- /fin-drawer -->

<!-- ===== مودال تأیید حذف ===== -->
<div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;
     display:none;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:28px;width:340px;max-width:92vw;text-align:center">
    <div style="font-size:40px;margin-bottom:12px">🗑️</div>
    <h3 style="margin:0 0 8px;font-size:15px;color:#0f172a">حذف طرف حساب</h3>
    <p style="margin:0 0 22px;font-size:13px;color:#64748b">آیا از حذف این طرف حساب اطمینان دارید؟ این عمل قابل بازگشت نیست.</p>
    <div style="display:flex;gap:10px;justify-content:center">
      <button onclick="closeDeleteModal()" class="fin-btn fin-btn-outline">انصراف</button>
      <button onclick="confirmDelete()" class="fin-btn fin-btn-danger">بله، حذف شود</button>
    </div>
  </div>
</div>

<!-- ===== Toast اعلانات ===== -->
<div id="toastWrap" style="position:fixed;bottom:24px;left:24px;z-index:9999;display:flex;flex-direction:column;gap:8px"></div>

<style>
@keyframes finSpin { to { transform: rotate(360deg); } }
@keyframes toastIn { from { transform:translateY(16px);opacity:0 } to { transform:translateY(0);opacity:1 } }
.badge-customer { background:#d1fae5;color:#065f46;border:1px solid #6ee7b7; }
.badge-supplier { background:#fef3c7;color:#92400e;border:1px solid #fcd34d; }
.badge-both     { background:#e0e7ff;color:#3730a3;border:1px solid #a5b4fc; }
</style>

<script>
/* ─── متغیرهای اصلی ──────────────────────────────────────────── */
var _deleteId = 0;
var _editId   = 0;

/* ─── بارگذاری اولیه ─────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    loadList();
});

/* ─── بارگذاری لیست ─────────────────────────────────────────── */
function loadList() {
    var search = document.getElementById('searchInput').value;
    var type   = document.getElementById('typeFilter').value;
    var tbody  = document.getElementById('personsTbody');
    tbody.innerHTML = '<tr><td colspan="7"><div style="display:flex;align-items:center;justify-content:center;padding:40px;gap:10px;color:#94a3b8;font-size:13px">' +
        '<div style="width:20px;height:20px;border:3px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:finSpin .7s linear infinite"></div>' +
        'در حال بارگذاری...</div></td></tr>';

    var fd = new FormData();
    fd.append('action', 'list');
    fd.append('search', search);
    fd.append('type', type);

    fetch('fin_persons.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.ok) { showToast(res.msg || 'خطا', 'error'); return; }
        renderList(res.data);
        var cnt = document.getElementById('listCount');
        if (cnt) cnt.textContent = res.data.length + ' رکورد';
    })
    .catch(function(e) { showToast('خطا در ارتباط با سرور', 'error'); });
}

/* ─── رندر جدول ─────────────────────────────────────────────── */
function renderList(rows) {
    var tbody = document.getElementById('personsTbody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="7"><div class="fin-empty"><div class="empty-icon">👥</div><p>هیچ طرف حسابی یافت نشد</p></div></td></tr>';
        return;
    }
    var html = '';
    rows.forEach(function(p) {
        var badge = badgeType(p.type);
        var bal   = parseInt(p.opening_balance) || 0;
        var balHtml = bal > 0
            ? '<span style="color:#16a34a;font-weight:700">' + num(bal) + '</span>'
            : bal < 0
                ? '<span style="color:#dc2626;font-weight:700">' + num(bal) + '</span>'
                : '<span style="color:#94a3b8">—</span>';

        html += '<tr style="transition:background .15s" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">'
            + '<td><code style="font-size:11px;background:#f1f5f9;padding:2px 7px;border-radius:5px;color:#475569">' + esc(p.code) + '</code></td>'
            + '<td>'
                + '<div style="font-weight:700;font-size:13px;color:#0f172a">' + esc(p.name) + '</div>'
                + (p.company_name ? '<div style="font-size:11px;color:#64748b;margin-top:2px">🏢 ' + esc(p.company_name) + '</div>' : '')
            + '</td>'
            + '<td style="text-align:center"><span class="fin-badge ' + badge.cls + '">' + badge.label + '</span></td>'
            + '<td style="font-size:12px;direction:ltr;text-align:right">' + (p.mobile ? esc(p.mobile) : '<span style="color:#cbd5e1">—</span>') + '</td>'
            + '<td style="font-size:12px">' + (p.state ? esc(p.state) : '<span style="color:#cbd5e1">—</span>') + '</td>'
            + '<td style="direction:ltr;text-align:left;font-size:12px">' + balHtml + (bal !== 0 ? ' <span style="font-size:10px;color:#94a3b8">ریال</span>' : '') + '</td>'
            + '<td style="text-align:center">'
                + '<button onclick="editPerson(' + p.id + ')" title="ویرایش" class="fin-btn fin-btn-sm" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;margin-left:4px">✏️</button>'
                + '<button onclick="deletePerson(' + p.id + ')" title="حذف" class="fin-btn fin-btn-sm" style="background:#fff1f2;color:#e11d48;border:1px solid #fecdd3">🗑️</button>'
            + '</td>'
            + '</tr>';
    });
    tbody.innerHTML = html;
}

/* ─── باز کردن کشو (افزودن) ─────────────────────────────────── */
function openDrawer() {
    _editId = 0;
    document.getElementById('drawerTitle').textContent = 'افزودن طرف حساب جدید';
    document.getElementById('personForm').reset();
    document.getElementById('fId').value = '0';
    document.getElementById('fOpening').value = '0';
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('formDrawer').classList.add('open');
}

/* ─── ویرایش ─────────────────────────────────────────────────── */
function editPerson(id) {
    var fd = new FormData();
    fd.append('action', 'get_one');
    fd.append('id', id);
    fetch('fin_persons.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.ok) { showToast(res.msg || 'خطا', 'error'); return; }
        var d = res.data;
        _editId = id;
        document.getElementById('drawerTitle').textContent = 'ویرایش طرف حساب';
        setVal('fId', d.id);
        setVal('fName', d.name);
        setVal('fNikename', d.nikename);
        setVal('fCompany', d.company_name);
        setVal('fType', d.type);
        setVal('fCode', d.code);
        setVal('fNationalId', d.shenasemeli || d.national_id);
        setVal('fEconomic', d.codeeghtesadi || d.economic_code);
        setVal('fTel', d.tel);
        setVal('fMobile', d.mobile);
        setVal('fEmail', d.email);
        setVal('fState', d.state);
        setVal('fCity', d.city);
        setVal('fAddress', d.address);
        setVal('fOpening', d.opening_balance);
        setVal('fNotes', d.notes);
        var ci = document.getElementById('fCustomerId');
        if (ci) ci.value = d.customer_id || '';
        document.getElementById('drawerOverlay').classList.add('open');
        document.getElementById('formDrawer').classList.add('open');
    });
}

/* ─── ذخیره فرم ─────────────────────────────────────────────── */
function savePerson(e) {
    e.preventDefault();
    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = '⏳ در حال ذخیره...';

    var fd = new FormData(document.getElementById('personForm'));
    fd.append('action', 'save');

    fetch('fin_persons.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.textContent = '💾 ذخیره طرف حساب';
        if (!res.ok) { showToast(res.msg || 'خطا', 'error'); return; }
        showToast(res.msg, 'success');
        closeDrawer();
        loadList();
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = '💾 ذخیره طرف حساب';
        showToast('خطا در ارتباط با سرور', 'error');
    });
}

/* ─── حذف ───────────────────────────────────────────────────── */
function deletePerson(id) {
    _deleteId = id;
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    _deleteId = 0;
}
function confirmDelete() {
    if (!_deleteId) return;
    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', _deleteId);
    fetch('fin_persons.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        closeDeleteModal();
        if (!res.ok) { showToast(res.msg || 'خطا', 'error'); return; }
        showToast(res.msg, 'success');
        loadList();
    });
}

/* ─── بستن کشو ──────────────────────────────────────────────── */
function closeDrawer() {
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('formDrawer').classList.remove('open');
}

/* ─── توابع کمکی ─────────────────────────────────────────────── */
function badgeType(type) {
    if (type === 'customer') return { cls: 'badge-customer', label: '🛒 مشتری' };
    if (type === 'supplier') return { cls: 'badge-supplier', label: '🏭 تامین‌کننده' };
    return { cls: 'badge-both', label: '🔄 هر دو' };
}
function num(n) {
    return parseInt(n).toLocaleString('fa-IR');
}
function esc(s) {
    return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function setVal(id, val) {
    var el = document.getElementById(id);
    if (el) el.value = val || '';
}

/* ─── Toast ─────────────────────────────────────────────────── */
function showToast(msg, type) {
    type = type || 'info';
    var wrap = document.getElementById('toastWrap');
    var t = document.createElement('div');
    var icons = { success: '✅', error: '❌', info: 'ℹ️' };
    t.style.cssText = 'background:#1e293b;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;' +
        'font-family:inherit;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:toastIn .3s ease;' +
        'display:flex;align-items:center;gap:8px;max-width:320px;direction:rtl;' +
        (type==='success' ? 'border-right:4px solid #22c55e' : type==='error' ? 'border-right:4px solid #ef4444' : 'border-right:4px solid #3b82f6');
    t.innerHTML = (icons[type] || '') + ' ' + esc(msg);
    wrap.appendChild(t);
    setTimeout(function() { if (t.parentNode) t.parentNode.removeChild(t); }, 3500);
}

/* بستن مودال حذف با کلیک خارج */
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
