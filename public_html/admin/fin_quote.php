<?php
/*
 * فایل: public_html/admin/fin_quote.php
 * آفر / قیمت‌نامه رسمی — قابل ارسال به مشتری و تبدیل به فاکتور
 */
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$userId  = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// ── جدول‌سازی خودکار ─────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_quotes` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `quote_number`  VARCHAR(30) NOT NULL,
        `quote_date`    DATE NOT NULL,
        `valid_until`   DATE DEFAULT NULL,
        `person_id`     INT NOT NULL DEFAULT 0,
        `customer_name` VARCHAR(200) DEFAULT NULL,
        `customer_phone` VARCHAR(20) DEFAULT NULL,
        `subtotal`      DECIMAL(20,0) DEFAULT 0,
        `discount`      DECIMAL(20,0) DEFAULT 0,
        `tax`           DECIMAL(20,0) DEFAULT 0,
        `total_amount`  DECIMAL(20,0) DEFAULT 0,
        `status`        ENUM('draft','sent','accepted','rejected','converted') DEFAULT 'draft',
        `notes`         TEXT DEFAULT NULL,
        `invoice_id`    INT DEFAULT NULL,
        `created_by`    INT NOT NULL,
        `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted`    TINYINT DEFAULT 0,
        UNIQUE KEY `uq_fq_number` (`quote_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_quote_items` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `quote_id`      INT NOT NULL,
        `stuff_id`      INT DEFAULT NULL,
        `description`   VARCHAR(500) NOT NULL DEFAULT '',
        `unit`          VARCHAR(30) DEFAULT NULL,
        `qty`           DECIMAL(12,4) NOT NULL DEFAULT 1,
        `unit_price`    DECIMAL(20,0) NOT NULL DEFAULT 0,
        `discount_pct`  DECIMAL(5,2) DEFAULT 0,
        `tax_pct`       DECIMAL(5,2) DEFAULT 9,
        `total`         DECIMAL(20,0) NOT NULL DEFAULT 0,
        `row_order`     INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ── شماره‌گذاری ──────────────────────────────────────────────────
function nextQuoteNum(PDO $pdo): string {
    $jYear = (int)jdate('Y');
    try {
        $last = $pdo->query("SELECT MAX(CAST(SUBSTRING_INDEX(quote_number,'-',-1) AS UNSIGNED))
                              FROM fin_quotes WHERE quote_number LIKE 'OFR-{$jYear}-%'")->fetchColumn();
    } catch (Throwable $e) { $last = 0; }
    return 'OFR-' . $jYear . '-' . str_pad((int)$last + 1, 4, '0', STR_PAD_LEFT);
}

// ── AJAX ─────────────────────────────────────────────────────────
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=UTF-8');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'list') {
        $search = trim($_GET['q'] ?? '');
        $status = $_GET['status'] ?? '';
        $sql = "SELECT q.id, q.quote_number, q.quote_date, q.valid_until, q.customer_name,
                       q.total_amount, q.status, q.customer_phone,
                       CONCAT(u.first_name,' ',u.last_name) AS creator
                FROM fin_quotes q
                LEFT JOIN users u ON u.id = q.created_by
                WHERE q.is_deleted = 0";
        $params = [];
        if ($search) { $sql .= ' AND (q.quote_number LIKE ? OR q.customer_name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($status)  { $sql .= ' AND q.status = ?'; $params[] = $status; }
        $sql .= ' ORDER BY q.id DESC LIMIT 100';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM fin_quotes WHERE id=? AND is_deleted=0");
        $st->execute([$id]);
        $q = $st->fetch(PDO::FETCH_ASSOC);
        if (!$q) { echo json_encode(['ok'=>false,'msg'=>'آفر یافت نشد']); exit; }
        $si = $pdo->prepare("SELECT * FROM fin_quote_items WHERE quote_id=? ORDER BY row_order");
        $si->execute([$id]);
        $q['items'] = $si->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'data' => $q]);
        exit;
    }

    if ($action === 'save') {
        csrf_verify();
        $id           = (int)($_POST['id'] ?? 0);
        $personId     = (int)($_POST['person_id'] ?? 0);
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone= trim($_POST['customer_phone'] ?? '');
        $quoteDate    = trim($_POST['quote_date'] ?? '');
        $validUntil   = trim($_POST['valid_until'] ?? '') ?: null;
        $notes        = trim($_POST['notes'] ?? '');
        $status       = in_array($_POST['status'] ?? '', ['draft','sent','accepted','rejected']) ? $_POST['status'] : 'draft';
        $itemsRaw     = json_decode($_POST['items'] ?? '[]', true) ?: [];
        // تبدیل تاریخ شمسی به میلادی
        if ($quoteDate) $quoteDate = jalali_to_gregorian((int)substr($quoteDate,0,4),(int)substr($quoteDate,5,2),(int)substr($quoteDate,8,2),'-');
        if ($validUntil) $validUntil = jalali_to_gregorian((int)substr($validUntil,0,4),(int)substr($validUntil,5,2),(int)substr($validUntil,8,2),'-');
        // محاسبه جمع
        $subtotal = 0; $taxTotal = 0;
        $items = [];
        foreach ($itemsRaw as $it) {
            $qty   = (float)($it['qty'] ?? 1);
            $price = (int)($it['unit_price'] ?? 0);
            $disc  = (float)($it['discount_pct'] ?? 0);
            $taxP  = (float)($it['tax_pct'] ?? 9);
            $lineNet  = $qty * $price * (1 - $disc/100);
            $lineTax  = $lineNet * $taxP / 100;
            $lineTotal= (int)round($lineNet + $lineTax);
            $subtotal += (int)round($lineNet);
            $taxTotal += (int)round($lineTax);
            $items[] = ['stuff_id'=>(int)($it['stuff_id']??0),'description'=>trim($it['description']??''),
                        'unit'=>trim($it['unit']??''),'qty'=>$qty,'unit_price'=>$price,
                        'discount_pct'=>$disc,'tax_pct'=>$taxP,'total'=>$lineTotal,'row_order'=>(int)($it['row_order']??0)];
        }
        $total = $subtotal + $taxTotal;
        try {
            if ($id) {
                $pdo->prepare("UPDATE fin_quotes SET person_id=?,customer_name=?,customer_phone=?,quote_date=?,valid_until=?,
                                notes=?,status=?,subtotal=?,tax=?,total_amount=?,updated_at=NOW() WHERE id=? AND is_deleted=0")
                    ->execute([$personId,$customerName,$customerPhone,$quoteDate,$validUntil,$notes,$status,$subtotal,$taxTotal,$total,$id]);
                $pdo->prepare("DELETE FROM fin_quote_items WHERE quote_id=?")->execute([$id]);
            } else {
                $num = nextQuoteNum($pdo);
                $pdo->prepare("INSERT INTO fin_quotes (quote_number,quote_date,valid_until,person_id,customer_name,customer_phone,subtotal,tax,total_amount,status,notes,created_by)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$num,$quoteDate,$validUntil,$personId,$customerName,$customerPhone,$subtotal,$taxTotal,$total,$status,$notes,$userId]);
                $id = (int)$pdo->lastInsertId();
            }
            $si = $pdo->prepare("INSERT INTO fin_quote_items (quote_id,stuff_id,description,unit,qty,unit_price,discount_pct,tax_pct,total,row_order) VALUES (?,?,?,?,?,?,?,?,?,?)");
            foreach ($items as $it) $si->execute([$id,$it['stuff_id'],$it['description'],$it['unit'],$it['qty'],$it['unit_price'],$it['discount_pct'],$it['tax_pct'],$it['total'],$it['row_order']]);
            echo json_encode(['ok'=>true,'id'=>$id,'msg'=>'ذخیره شد']);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'delete') {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE fin_quotes SET is_deleted=1 WHERE id=? AND created_by=?")->execute([$id,$userId]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'change_status') {
        csrf_verify();
        $id  = (int)($_POST['id'] ?? 0);
        $st  = in_array($_POST['status']??'',['draft','sent','accepted','rejected','converted']) ? $_POST['status'] : 'draft';
        $pdo->prepare("UPDATE fin_quotes SET status=?,updated_at=NOW() WHERE id=?")->execute([$st,$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'convert_to_invoice') {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM fin_quotes WHERE id=? AND is_deleted=0");
        $st->execute([$id]);
        $q = $st->fetch(PDO::FETCH_ASSOC);
        if (!$q) { echo json_encode(['ok'=>false,'msg'=>'آفر یافت نشد']); exit; }
        if ($q['status'] === 'converted') { echo json_encode(['ok'=>false,'msg'=>'قبلاً تبدیل شده است']); exit; }
        try {
            // ساخت فاکتور فروش
            $fyRow = $pdo->query("SELECT id FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $fyId  = $fyRow ? (int)$fyRow['id'] : null;
            $invNum = 'INV-' . jdate('Y') . '-' . str_pad($pdo->query("SELECT COALESCE(MAX(id),0)+1 FROM fin_invoices")->fetchColumn(), 4, '0', STR_PAD_LEFT);
            $today  = date('Y-m-d');
            $pdo->prepare("INSERT INTO fin_invoices (invoice_number,invoice_date,person_id,type,status,total_amount,paid_amount,fiscal_year_id,created_by,notes,is_deleted)
                            VALUES (?,?,?,'sell','draft',?,0,?,?,?,0)")
                ->execute([$invNum,$today,$q['person_id'],$q['total_amount'],$fyId,$userId,$q['notes']]);
            $invId = (int)$pdo->lastInsertId();
            // کپی آیتم‌ها
            $si = $pdo->prepare("SELECT * FROM fin_quote_items WHERE quote_id=? ORDER BY row_order");
            $si->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO fin_invoice_items (invoice_id,stuff_id,description,unit,quantity,unit_price,discount_percent,tax_percent,total_price) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($si->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $ins->execute([$invId,$it['stuff_id'],$it['description'],$it['unit'],$it['qty'],$it['unit_price'],$it['discount_pct'],$it['tax_pct'],$it['total']]);
            }
            $pdo->prepare("UPDATE fin_quotes SET status='converted',invoice_id=?,updated_at=NOW() WHERE id=?")->execute([$invId,$id]);
            echo json_encode(['ok'=>true,'invoice_id'=>$invId,'msg'=>'فاکتور ساخته شد']);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'persons_search') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $st = $pdo->prepare("SELECT id, name, phone FROM fin_persons WHERE is_deleted=0 AND name LIKE ? LIMIT 20");
        $st->execute([$q]);
        echo json_encode(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'products_search') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $st = $pdo->prepare("SELECT spl.id, spl.stuff_name AS name, spl.sell_price AS price, spl.unit FROM stuff_price_list spl WHERE spl.is_deleted=0 AND spl.stuff_name LIKE ? LIMIT 20");
        $st->execute([$q]);
        echo json_encode(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'اکشن نامعتبر']);
    exit;
}

// ── تنظیمات صفحه ─────────────────────────────────────────────────
$pageTitle = 'آفر / قیمت‌نامه رسمی';
$basePath  = '../../';
include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<div class="fin-page-wrap" style="padding:24px">

    <!-- هدر -->
    <div class="fin-page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:1.4rem;font-weight:700;color:#1e293b;margin:0">📋 آفر / قیمت‌نامه رسمی</h1>
            <p style="margin:4px 0 0;color:#64748b;font-size:.88rem">مدیریت آفرها و تبدیل به فاکتور رسمی</p>
        </div>
        <button class="fin-btn fin-btn-primary" onclick="openNew()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            آفر جدید
        </button>
    </div>

    <!-- فیلتر -->
    <div class="fin-panel" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;padding:16px 20px">
        <div style="flex:1;min-width:180px">
            <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">جستجو</label>
            <input type="text" id="searchInput" class="fin-input" placeholder="شماره آفر یا نام مشتری..." onkeyup="debounceLoad()">
        </div>
        <div>
            <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">وضعیت</label>
            <select id="statusFilter" class="fin-input" onchange="loadList()">
                <option value="">همه</option>
                <option value="draft">پیش‌نویس</option>
                <option value="sent">ارسال‌شده</option>
                <option value="accepted">قبول‌شده</option>
                <option value="rejected">رد‌شده</option>
                <option value="converted">تبدیل‌شده</option>
            </select>
        </div>
    </div>

    <!-- جدول -->
    <div class="fin-panel">
        <div id="listLoading" style="text-align:center;padding:40px;color:#94a3b8">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" class="spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
            <div style="margin-top:8px">در حال بارگذاری...</div>
        </div>
        <div id="listContent" style="display:none">
            <table class="fin-table" style="width:100%">
                <thead>
                    <tr>
                        <th>شماره آفر</th>
                        <th>تاریخ</th>
                        <th>اعتبار تا</th>
                        <th>مشتری</th>
                        <th>مبلغ کل</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody id="quoteTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── درِوِر فرم آفر ───────────────────────────────────────────── -->
<div id="quoteDrawer" class="fin-drawer-overlay" style="display:none" onclick="if(event.target===this)closeDrawer()">
    <div class="fin-drawer" style="width:min(760px,96vw)">
        <div class="fin-drawer-header">
            <span id="drawerTitle">آفر جدید</span>
            <button onclick="closeDrawer()" class="fin-drawer-close">✕</button>
        </div>
        <div class="fin-drawer-body" style="overflow-y:auto;max-height:calc(90vh - 120px)">
            <form id="quoteForm" onsubmit="saveQuote(event)">
                <input type="hidden" id="qId" value="0">
                <?php echo csrf_field(); ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
                    <div>
                        <label class="fin-label">مشتری / طرف حساب</label>
                        <input type="hidden" id="qPersonId" value="0">
                        <input type="text" id="qPersonSearch" class="fin-input" placeholder="نام مشتری..." autocomplete="off" oninput="searchPersons(this.value)">
                        <div id="personDropdown" style="position:relative"></div>
                    </div>
                    <div>
                        <label class="fin-label">شماره موبایل مشتری</label>
                        <input type="text" id="qPhone" class="fin-input" placeholder="09xxxxxxxxx" dir="ltr">
                    </div>
                    <div>
                        <label class="fin-label">تاریخ آفر</label>
                        <input type="text" id="qDate" class="fin-input kama-date" placeholder="<?php echo jdate('Y/m/d'); ?>">
                    </div>
                    <div>
                        <label class="fin-label">اعتبار تا تاریخ</label>
                        <input type="text" id="qValidUntil" class="fin-input kama-date" placeholder="اختیاری">
                    </div>
                    <div>
                        <label class="fin-label">وضعیت</label>
                        <select id="qStatus" class="fin-input">
                            <option value="draft">پیش‌نویس</option>
                            <option value="sent">ارسال‌شده</option>
                            <option value="accepted">قبول‌شده</option>
                            <option value="rejected">رد‌شده</option>
                        </select>
                    </div>
                    <div>
                        <label class="fin-label">توضیحات</label>
                        <input type="text" id="qNotes" class="fin-input" placeholder="یادداشت...">
                    </div>
                </div>

                <!-- جدول آیتم‌ها -->
                <div style="background:#f8fafc;border-radius:10px;padding:14px;margin-bottom:20px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                        <strong style="color:#374151">آیتم‌های آفر</strong>
                        <button type="button" class="fin-btn fin-btn-sm" onclick="addRow()">+ ردیف</button>
                    </div>
                    <div style="overflow-x:auto">
                        <table style="width:100%;font-size:.82rem;border-collapse:collapse" id="itemsTable">
                            <thead>
                                <tr style="background:#e2e8f0">
                                    <th style="padding:7px;text-align:right">شرح کالا</th>
                                    <th style="padding:7px;text-align:center;width:60px">تعداد</th>
                                    <th style="padding:7px;text-align:center;width:120px">واحد قیمت</th>
                                    <th style="padding:7px;text-align:center;width:70px">تخفیف%</th>
                                    <th style="padding:7px;text-align:center;width:70px">مالیات%</th>
                                    <th style="padding:7px;text-align:center;width:120px">جمع</th>
                                    <th style="padding:7px;width:30px"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody"></tbody>
                        </table>
                    </div>
                    <!-- جمع‌کل -->
                    <div style="text-align:left;margin-top:12px;font-size:.88rem">
                        <span style="color:#6b7280">مبلغ کل: </span>
                        <strong style="color:#4f46e5;font-size:1rem" id="grandTotal">۰ تومان</strong>
                    </div>
                </div>

                <div style="display:flex;gap:10px;justify-content:flex-end">
                    <button type="button" class="fin-btn" onclick="closeDrawer()">انصراف</button>
                    <button type="button" class="fin-btn" style="background:#0ea5e9;color:#fff" onclick="printQuote()">🖨 چاپ</button>
                    <button type="submit" class="fin-btn fin-btn-primary" id="saveBtn">ذخیره</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- چاپ -->
<div id="printArea" style="display:none"></div>

<style>
.fin-page-wrap { min-height:100vh; background:#f8fafc; }
.fin-label { font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px }
.fin-input { width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,sans-serif;font-size:.88rem;background:#f9fafb;color:#111827;transition:border-color .2s;box-sizing:border-box }
.fin-input:focus { outline:none;border-color:#4f46e5;background:#fff }
.fin-btn { padding:8px 18px;border:1.5px solid #d1d5db;background:#fff;border-radius:8px;font-family:Vazirmatn,sans-serif;font-size:.85rem;cursor:pointer;font-weight:600;transition:all .2s }
.fin-btn-primary { background:#4f46e5;color:#fff;border-color:#4f46e5 }
.fin-btn-primary:hover { background:#4338ca }
.fin-btn-sm { padding:5px 12px;font-size:.78rem }
.fin-panel { background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.07);padding:20px }
.fin-table { width:100%;border-collapse:collapse;font-size:.86rem }
.fin-table th { background:#f1f5f9;padding:10px 14px;text-align:right;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb }
.fin-table td { padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#374151 }
.fin-table tr:hover td { background:#f8fafc }
.fin-badge { display:inline-block;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700 }
.badge-amber { background:#fef3c7;color:#92400e }
.badge-blue  { background:#dbeafe;color:#1d4ed8 }
.badge-green { background:#d1fae5;color:#065f46 }
.badge-rose  { background:#ffe4e6;color:#9f1239 }
.badge-gray  { background:#f3f4f6;color:#374151 }
.fin-drawer-overlay { position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;display:flex;align-items:flex-start;justify-content:flex-end }
.fin-drawer { background:#fff;height:100vh;overflow-y:auto;box-shadow:-4px 0 20px rgba(0,0,0,.15) }
.fin-drawer-header { padding:18px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;font-weight:700;font-size:1rem;color:#1e293b;position:sticky;top:0;background:#fff;z-index:10 }
.fin-drawer-close { background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6b7280 }
.fin-drawer-body { padding:20px }
.person-dd-item { padding:8px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:.85rem }
.person-dd-item:hover { background:#f0f9ff }
@keyframes spin { to { transform:rotate(360deg) } }
.spin { animation:spin 1s linear infinite }
@media print {
    body * { visibility:hidden }
    #printArea, #printArea * { visibility:visible }
    #printArea { position:fixed;inset:0;background:#fff;padding:30px;z-index:9999 }
}
</style>

<script>
const CSRF = '<?php echo csrf_token(); ?>';
let debTimer;
function debounceLoad() { clearTimeout(debTimer); debTimer = setTimeout(loadList, 400); }

function moneyFa(n) { return new Intl.NumberFormat('fa-IR').format(Math.round(n)) + ' تومان'; }

const STATUS_LABEL = {draft:'پیش‌نویس',sent:'ارسال‌شده',accepted:'قبول‌شده',rejected:'رد‌شده',converted:'تبدیل‌شده'};
const STATUS_CLASS = {draft:'badge-amber',sent:'badge-blue',accepted:'badge-green',rejected:'badge-rose',converted:'badge-gray'};

function loadList() {
    const q = document.getElementById('searchInput').value;
    const st = document.getElementById('statusFilter').value;
    document.getElementById('listLoading').style.display = '';
    document.getElementById('listContent').style.display = 'none';
    fetch(`fin_quote.php?action=list&q=${encodeURIComponent(q)}&status=${st}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(res=>{
        document.getElementById('listLoading').style.display = 'none';
        document.getElementById('listContent').style.display = '';
        const tb = document.getElementById('quoteTableBody');
        if (!res.ok || !res.data.length) { tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8">آفری یافت نشد</td></tr>'; return; }
        tb.innerHTML = res.data.map(q => `<tr>
            <td><strong style="color:#4f46e5">${q.quote_number}</strong></td>
            <td>${q.quote_date||'—'}</td>
            <td>${q.valid_until||'—'}</td>
            <td>${q.customer_name||'—'}</td>
            <td style="font-weight:600">${moneyFa(q.total_amount)}</td>
            <td><span class="fin-badge ${STATUS_CLASS[q.status]||'badge-gray'}">${STATUS_LABEL[q.status]||q.status}</span></td>
            <td>
                <button class="fin-btn fin-btn-sm" onclick="editQuote(${q.id})">ویرایش</button>
                ${q.status!=='converted' ? `<button class="fin-btn fin-btn-sm" style="background:#059669;color:#fff;border-color:#059669;margin-right:4px" onclick="convertToInvoice(${q.id})">تبدیل به فاکتور</button>` : ''}
                <button class="fin-btn fin-btn-sm" style="color:#e11d48;border-color:#e11d48;margin-right:4px" onclick="deleteQuote(${q.id})">حذف</button>
            </td>
        </tr>`).join('');
    });
}

// ── درِوِر ──────────────────────────────────────────────────────
function openNew() {
    document.getElementById('drawerTitle').textContent = 'آفر جدید';
    document.getElementById('qId').value = '0';
    document.getElementById('qPersonId').value = '0';
    document.getElementById('qPersonSearch').value = '';
    document.getElementById('qPhone').value = '';
    document.getElementById('qDate').value = '<?php echo jdate('Y/m/d'); ?>';
    document.getElementById('qValidUntil').value = '';
    document.getElementById('qStatus').value = 'draft';
    document.getElementById('qNotes').value = '';
    document.getElementById('itemsBody').innerHTML = '';
    addRow();
    updateTotal();
    document.getElementById('quoteDrawer').style.display = 'flex';
}

function closeDrawer() { document.getElementById('quoteDrawer').style.display = 'none'; }

function editQuote(id) {
    fetch(`fin_quote.php?action=get&id=${id}`,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(res=>{
        if(!res.ok) return alert(res.msg);
        const q = res.data;
        document.getElementById('drawerTitle').textContent = 'ویرایش آفر ' + q.quote_number;
        document.getElementById('qId').value = q.id;
        document.getElementById('qPersonId').value = q.person_id;
        document.getElementById('qPersonSearch').value = q.customer_name||'';
        document.getElementById('qPhone').value = q.customer_phone||'';
        document.getElementById('qDate').value = q.quote_date||'';
        document.getElementById('qValidUntil').value = q.valid_until||'';
        document.getElementById('qStatus').value = q.status||'draft';
        document.getElementById('qNotes').value = q.notes||'';
        document.getElementById('itemsBody').innerHTML = '';
        (q.items||[]).forEach(it=>addRow(it));
        updateTotal();
        document.getElementById('quoteDrawer').style.display = 'flex';
    });
}

// ── جستجوی طرف حساب ─────────────────────────────────────────────
function searchPersons(val) {
    if (!val || val.length < 2) { document.getElementById('personDropdown').innerHTML = ''; return; }
    fetch(`fin_quote.php?action=persons_search&q=${encodeURIComponent(val)}`,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(res=>{
        const dd = document.getElementById('personDropdown');
        if(!res.ok||!res.data.length) { dd.innerHTML=''; return; }
        dd.innerHTML = `<div style="position:absolute;z-index:200;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);width:100%;max-height:200px;overflow-y:auto">
            ${res.data.map(p=>`<div class="person-dd-item" onclick="selectPerson(${p.id},'${(p.name||'').replace(/'/g,'')}','${p.phone||''}')">${p.name} ${p.phone?'<span style=color:#94a3b8;font-size:.8rem>('+p.phone+')</span>':''}</div>`).join('')}
        </div>`;
    });
}
function selectPerson(id, name, phone) {
    document.getElementById('qPersonId').value = id;
    document.getElementById('qPersonSearch').value = name;
    if (!document.getElementById('qPhone').value) document.getElementById('qPhone').value = phone;
    document.getElementById('personDropdown').innerHTML = '';
}

// ── آیتم‌ها ──────────────────────────────────────────────────────
let rowIdx = 0;
function addRow(it) {
    rowIdx++;
    const i = rowIdx;
    const desc  = it?.description || '';
    const qty   = it?.qty || 1;
    const price = it?.unit_price || 0;
    const disc  = it?.discount_pct || 0;
    const tax   = it?.tax_pct ?? 9;
    const unit  = it?.unit || '';
    const stuffId = it?.stuff_id || 0;
    const tbody = document.getElementById('itemsBody');
    tbody.insertAdjacentHTML('beforeend', `<tr id="row-${i}">
        <td style="padding:4px">
            <input type="hidden" class="r-stuff-id" value="${stuffId}">
            <input type="text" class="fin-input r-desc" value="${desc}" placeholder="جستجو..." oninput="searchProduct(this,${i})" style="font-size:.8rem">
            <div id="prod-dd-${i}" style="position:relative"></div>
        </td>
        <td style="padding:4px"><input type="number" class="fin-input r-qty" value="${qty}" min="0" step="0.01" oninput="calcRow(${i})" style="font-size:.8rem;text-align:center"></td>
        <td style="padding:4px"><input type="number" class="fin-input r-price" value="${price}" min="0" oninput="calcRow(${i})" style="font-size:.8rem;text-align:center"></td>
        <td style="padding:4px"><input type="number" class="fin-input r-disc" value="${disc}" min="0" max="100" step="0.1" oninput="calcRow(${i})" style="font-size:.8rem;text-align:center"></td>
        <td style="padding:4px"><input type="number" class="fin-input r-tax" value="${tax}" min="0" max="100" step="0.1" oninput="calcRow(${i})" style="font-size:.8rem;text-align:center"></td>
        <td style="padding:4px;font-weight:600;text-align:center;font-size:.8rem" id="row-total-${i}">۰</td>
        <td style="padding:4px;text-align:center"><button type="button" onclick="removeRow(${i})" style="background:none;border:none;color:#e11d48;cursor:pointer;font-size:1rem">✕</button></td>
    </tr>`);
    if (it) calcRow(i);
}
function removeRow(i) { const r = document.getElementById('row-'+i); if(r) r.remove(); updateTotal(); }
function calcRow(i) {
    const row = document.getElementById('row-'+i);
    if (!row) return;
    const qty   = parseFloat(row.querySelector('.r-qty').value)||0;
    const price = parseFloat(row.querySelector('.r-price').value)||0;
    const disc  = parseFloat(row.querySelector('.r-disc').value)||0;
    const taxP  = parseFloat(row.querySelector('.r-tax').value)||0;
    const net   = qty * price * (1 - disc/100);
    const total = net * (1 + taxP/100);
    row.querySelector(`#row-total-${i}`).textContent = new Intl.NumberFormat('fa-IR').format(Math.round(total));
    updateTotal();
}
function updateTotal() {
    let sum = 0;
    document.querySelectorAll('#itemsBody tr').forEach(row => {
        const qEl = row.querySelector('.r-qty');
        const pEl = row.querySelector('.r-price');
        const dEl = row.querySelector('.r-disc');
        const tEl = row.querySelector('.r-tax');
        if (!qEl) return;
        const net = (parseFloat(qEl.value)||0) * (parseFloat(pEl.value)||0) * (1 - (parseFloat(dEl.value)||0)/100);
        sum += net * (1 + (parseFloat(tEl.value)||0)/100);
    });
    document.getElementById('grandTotal').textContent = new Intl.NumberFormat('fa-IR').format(Math.round(sum)) + ' تومان';
}
function searchProduct(inp, i) {
    const val = inp.value;
    if (!val || val.length < 2) { const dd = document.getElementById('prod-dd-'+i); if(dd) dd.innerHTML=''; return; }
    fetch(`fin_quote.php?action=products_search&q=${encodeURIComponent(val)}`,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(res=>{
        const dd = document.getElementById('prod-dd-'+i);
        if(!dd||!res.ok||!res.data.length) { if(dd) dd.innerHTML=''; return; }
        dd.innerHTML = `<div style="position:absolute;z-index:200;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);width:220px;max-height:180px;overflow-y:auto">
            ${res.data.map(p=>`<div class="person-dd-item" onclick="selectProduct(${i},${p.id},'${(p.name||'').replace(/'/g,'')}',${p.price||0},'${p.unit||''}')">${p.name} <span style="color:#4f46e5;font-size:.78rem">${new Intl.NumberFormat('fa-IR').format(p.price||0)}</span></div>`).join('')}
        </div>`;
    });
}
function selectProduct(i, id, name, price, unit) {
    const row = document.getElementById('row-'+i);
    if(!row) return;
    row.querySelector('.r-stuff-id').value = id;
    row.querySelector('.r-desc').value = name;
    row.querySelector('.r-price').value = price;
    const dd = document.getElementById('prod-dd-'+i);
    if(dd) dd.innerHTML = '';
    calcRow(i);
}

// ── ذخیره ────────────────────────────────────────────────────────
function saveQuote(e) {
    e.preventDefault();
    const items = [];
    document.querySelectorAll('#itemsBody tr').forEach((row, idx) => {
        const qEl = row.querySelector('.r-qty');
        if(!qEl) return;
        items.push({
            stuff_id:     row.querySelector('.r-stuff-id').value,
            description:  row.querySelector('.r-desc').value,
            qty:          qEl.value,
            unit_price:   row.querySelector('.r-price').value,
            discount_pct: row.querySelector('.r-disc').value,
            tax_pct:      row.querySelector('.r-tax').value,
            row_order:    idx
        });
    });
    const fd = new FormData();
    fd.append('action','save');
    fd.append('csrf_token', CSRF);
    fd.append('id',         document.getElementById('qId').value);
    fd.append('person_id',  document.getElementById('qPersonId').value);
    fd.append('customer_name', document.getElementById('qPersonSearch').value);
    fd.append('customer_phone', document.getElementById('qPhone').value);
    fd.append('quote_date', document.getElementById('qDate').value);
    fd.append('valid_until', document.getElementById('qValidUntil').value);
    fd.append('status',     document.getElementById('qStatus').value);
    fd.append('notes',      document.getElementById('qNotes').value);
    fd.append('items',      JSON.stringify(items));
    document.getElementById('saveBtn').disabled = true;
    fetch('fin_quote.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(res=>{
        document.getElementById('saveBtn').disabled = false;
        if(res.ok) { closeDrawer(); loadList(); } else alert(res.msg);
    });
}

function deleteQuote(id) {
    if(!confirm('حذف این آفر؟')) return;
    const fd = new FormData();
    fd.append('action','delete');
    fd.append('csrf_token', CSRF);
    fd.append('id', id);
    fetch('fin_quote.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(res=>{ if(res.ok) loadList(); });
}

function convertToInvoice(id) {
    if(!confirm('این آفر به فاکتور فروش تبدیل شود؟')) return;
    const fd = new FormData();
    fd.append('action','convert_to_invoice');
    fd.append('csrf_token', CSRF);
    fd.append('id', id);
    fetch('fin_quote.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(res=>{
        if(res.ok) { alert('فاکتور با موفقیت ساخته شد — شناسه: ' + res.invoice_id); loadList(); }
        else alert(res.msg);
    });
}

function printQuote() {
    const id = document.getElementById('qId').value;
    if(id === '0') { alert('ابتدا آفر را ذخیره کنید'); return; }
    window.open('fin_invoice_pdf.php?quote_id=' + id + '&type=quote', '_blank');
}

// ── شروع ────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', loadList);
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
