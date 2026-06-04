<?php
/*
 * فایل: public_html/admin/fin_tax_integration.php
 * یکپارچگی با سامانه مالیاتی — پشتیبانی از فرمت قبوض الکترونیکی
 * نکته: یکپارچگی کامل با سامانه مؤدیان نیاز به مجوز API دارد
 */
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$userId = (int)$_SESSION['user_id'];

// ── جدول‌سازی ──────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tax_invoices` (
        `id`                INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_id`        INT NOT NULL COMMENT 'شناسه فاکتور در سیستم',
        `invoice_number`    VARCHAR(30) DEFAULT NULL,
        `tax_serial`        VARCHAR(100) DEFAULT NULL COMMENT 'شماره منحصربفرد مالیاتی',
        `tax_uid`           VARCHAR(200) DEFAULT NULL COMMENT 'شناسه یکتا در سامانه مؤدیان',
        `status`            ENUM('pending','sent','confirmed','rejected','error') DEFAULT 'pending',
        `total_amount`      DECIMAL(20,0) DEFAULT 0,
        `tax_amount`        DECIMAL(20,0) DEFAULT 0,
        `response_code`     VARCHAR(50) DEFAULT NULL,
        `response_message`  TEXT DEFAULT NULL,
        `sent_at`           DATETIME DEFAULT NULL,
        `confirmed_at`      DATETIME DEFAULT NULL,
        `created_by`        INT NOT NULL,
        `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_invoice_id` (`invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // تنظیمات اتصال سامانه مؤدیان
    $defaults = [
        ['tax_api_enabled',    '0'],
        ['tax_api_base_url',   'https://tp.tax.gov.ir/req/api'],
        ['tax_company_name',   'آتنا زیست درمان'],
        ['tax_economic_code',  ''],
        ['tax_national_id',    ''],
        ['tax_postal_code',    ''],
        ['tax_token',          ''],
        ['tax_private_key',    ''],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?,?)");
    foreach ($defaults as [$k,$v]) $ins->execute([$k,$v]);
} catch (Throwable $e) {}

// ── بارگذاری تنظیمات ─────────────────────────────────────────────
function getTaxSettings(PDO $pdo): array {
    try {
        $keys = ['tax_api_enabled','tax_api_base_url','tax_company_name','tax_economic_code',
                 'tax_national_id','tax_postal_code','tax_token','tax_private_key'];
        $in = implode(',', array_fill(0, count($keys), '?'));
        $st = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($in)");
        $st->execute($keys);
        $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows;
    } catch (Throwable $e) { return []; }
}

// ── AJAX ──────────────────────────────────────────────────────────
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=UTF-8');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        csrf_verify();
        $fields = ['tax_api_enabled','tax_api_base_url','tax_company_name',
                   'tax_economic_code','tax_national_id','tax_postal_code','tax_token','tax_private_key'];
        $up = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
        foreach ($fields as $f) {
            $v = trim($_POST[$f] ?? '');
            $up->execute([$f, $v, $v]);
        }
        logSystem($pdo, $userId, 'tax_settings_saved', 'تنظیمات مالیاتی به‌روزرسانی شد');
        echo json_encode(['ok' => true, 'msg' => 'تنظیمات ذخیره شد']);
        exit;
    }

    if ($action === 'list_invoices') {
        $status = $_GET['status'] ?? '';
        $sql = "SELECT fi.id, fi.invoice_number, fi.invoice_date, fi.total_amount,
                       fp.name AS person_name,
                       ti.id AS tax_id, ti.tax_serial, ti.status AS tax_status,
                       ti.sent_at, ti.response_message
                FROM fin_invoices fi
                LEFT JOIN fin_persons fp ON fp.id = fi.person_id
                LEFT JOIN tax_invoices ti ON ti.invoice_id = fi.id
                WHERE fi.type='sell' AND fi.status='confirmed' AND fi.is_deleted=0";
        $params = [];
        if ($status === 'unsent') $sql .= ' AND ti.id IS NULL';
        elseif ($status)         { $sql .= ' AND ti.status = ?'; $params[] = $status; }
        $sql .= ' ORDER BY fi.id DESC LIMIT 100';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'get_stats') {
        try {
            $total  = (int)$pdo->query("SELECT COUNT(*) FROM fin_invoices WHERE type='sell' AND status='confirmed' AND is_deleted=0")->fetchColumn();
            $sent   = (int)$pdo->query("SELECT COUNT(*) FROM tax_invoices WHERE status IN ('sent','confirmed')")->fetchColumn();
            $confirmed = (int)$pdo->query("SELECT COUNT(*) FROM tax_invoices WHERE status='confirmed'")->fetchColumn();
            $errors = (int)$pdo->query("SELECT COUNT(*) FROM tax_invoices WHERE status IN ('rejected','error')")->fetchColumn();
            $pending= $total - $sent - $errors;
            echo json_encode(['ok'=>true,'total'=>$total,'sent'=>$sent,'confirmed'=>$confirmed,'errors'=>$errors,'pending'=>max(0,$pending)]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'generate_xml') {
        // تولید XML معاملات فصلی برای ارسال دستی
        $fyId = (int)($_GET['fiscal_year_id'] ?? 0);
        try {
            $fy = $pdo->prepare("SELECT * FROM fiscal_years WHERE id=? LIMIT 1");
            $fy->execute([$fyId ?: null]);
            $fyRow = $fyId ? $fy->fetch(PDO::FETCH_ASSOC) : $pdo->query("SELECT * FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $sql = "SELECT fi.invoice_number, fi.invoice_date, fi.total_amount,
                           fp.name AS buyer_name, fp.national_code, fp.phone
                    FROM fin_invoices fi
                    LEFT JOIN fin_persons fp ON fp.id = fi.person_id
                    WHERE fi.type='sell' AND fi.status='confirmed' AND fi.is_deleted=0";
            $params = [];
            if ($fyRow) { $sql .= ' AND fi.invoice_date BETWEEN ? AND ?'; $params = [$fyRow['start_date'], $fyRow['end_date']]; }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $invoices = $st->fetchAll(PDO::FETCH_ASSOC);
            $settings = getTaxSettings($pdo);
            $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<Transactions xmlns="http://irsystem.ir/standard">' . "\n";
            $xml .= '  <Seller>' . "\n";
            $xml .= '    <Name>' . htmlspecialchars($settings['tax_company_name'] ?? '') . '</Name>' . "\n";
            $xml .= '    <EconomicCode>' . htmlspecialchars($settings['tax_economic_code'] ?? '') . '</EconomicCode>' . "\n";
            $xml .= '  </Seller>' . "\n";
            foreach ($invoices as $inv) {
                $xml .= '  <Invoice>' . "\n";
                $xml .= '    <InvoiceNumber>' . htmlspecialchars($inv['invoice_number']) . '</InvoiceNumber>' . "\n";
                $xml .= '    <Date>' . htmlspecialchars($inv['invoice_date']) . '</Date>' . "\n";
                $xml .= '    <Amount>' . (int)$inv['total_amount'] . '</Amount>' . "\n";
                $xml .= '    <Buyer>' . htmlspecialchars($inv['buyer_name'] ?? '') . '</Buyer>' . "\n";
                $xml .= '  </Invoice>' . "\n";
            }
            $xml .= '</Transactions>';
            echo json_encode(['ok' => true, 'xml' => $xml, 'count' => count($invoices)]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    if ($action === 'mark_sent') {
        csrf_verify();
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $serial    = trim($_POST['tax_serial'] ?? '');
        try {
            $st = $pdo->prepare("SELECT id FROM tax_invoices WHERE invoice_id=?");
            $st->execute([$invoiceId]);
            if ($st->fetchColumn()) {
                $pdo->prepare("UPDATE tax_invoices SET status='sent',tax_serial=?,sent_at=NOW() WHERE invoice_id=?")->execute([$serial,$invoiceId]);
            } else {
                $pdo->prepare("INSERT INTO tax_invoices (invoice_id,tax_serial,status,created_by,sent_at) VALUES (?,?,'sent',?,NOW())")->execute([$invoiceId,$serial,$userId]);
            }
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'اکشن نامعتبر']);
    exit;
}

$taxSettings  = getTaxSettings($pdo);
$pageTitle    = 'یکپارچگی سامانه مالیاتی';
$basePath     = '../../';
$fiscalYears  = [];
try { $fiscalYears = $pdo->query("SELECT id,title,is_current FROM fiscal_years ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<div style="padding:24px;min-height:100vh;background:#f8fafc">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:1.4rem;font-weight:700;color:#1e293b;margin:0">🏛 یکپارچگی سامانه مالیاتی</h1>
            <p style="margin:4px 0 0;color:#64748b;font-size:.88rem">مدیریت فاکتورهای الکترونیکی و ارسال به سامانه مؤدیان</p>
        </div>
        <div style="display:flex;gap:8px">
            <button class="fin-btn" onclick="generateXml()" style="background:#7c3aed;color:#fff;border-color:#7c3aed">📄 صدور XML معاملاتی</button>
            <button class="fin-btn fin-btn-primary" onclick="showSettings()">⚙️ تنظیمات API</button>
        </div>
    </div>

    <!-- هشدار -->
    <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:12px;padding:16px 20px;margin-bottom:24px;display:flex;gap:12px;align-items:flex-start">
        <span style="font-size:1.4rem">⚠️</span>
        <div>
            <strong style="color:#92400e">یکپارچگی مستقیم در حال توسعه</strong>
            <p style="margin:4px 0 0;font-size:.86rem;color:#92400e">اتصال مستقیم به API سامانه مؤدیان مالیاتی نیاز به مجوز از سازمان امور مالیاتی و گواهی امضای دیجیتال دارد. در حال حاضر می‌توانید XML معاملات فصلی را صادر کرده و به‌صورت دستی آپلود کنید، یا وضعیت ارسال فاکتورها را ثبت نمایید.</p>
        </div>
    </div>

    <!-- کارت‌های آماری -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px" id="statsCards">
        <div class="fin-stat-card blue"><div class="stat-val" id="st-total">—</div><div class="stat-lbl">کل فاکتورهای تأییدشده</div></div>
        <div class="fin-stat-card green"><div class="stat-val" id="st-confirmed">—</div><div class="stat-lbl">ارسال‌شده و تأییدشده</div></div>
        <div class="fin-stat-card amber"><div class="stat-val" id="st-pending">—</div><div class="stat-lbl">در انتظار ارسال</div></div>
        <div class="fin-stat-card rose"><div class="stat-val" id="st-errors">—</div><div class="stat-lbl">خطا / رد‌شده</div></div>
    </div>

    <!-- فیلتر -->
    <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.07);padding:16px 20px;margin-bottom:20px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div>
            <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">وضعیت</label>
            <select id="taxStatusFilter" class="fin-input" onchange="loadInvoices()">
                <option value="">همه فاکتورها</option>
                <option value="unsent">ارسال‌نشده</option>
                <option value="pending">در انتظار</option>
                <option value="sent">ارسال‌شده</option>
                <option value="confirmed">تأییدشده</option>
                <option value="rejected">رد‌شده</option>
                <option value="error">خطا</option>
            </select>
        </div>
        <div>
            <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">سال مالی (برای XML)</label>
            <select id="taxFySelect" class="fin-input">
                <?php foreach($fiscalYears as $fy): ?>
                <option value="<?php echo $fy['id'] ?>"<?php if($fy['is_current']) echo ' selected' ?>><?php echo htmlspecialchars($fy['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- جدول -->
    <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.07);overflow:hidden">
        <div id="invLoading" style="text-align:center;padding:40px;color:#94a3b8">در حال بارگذاری...</div>
        <div id="invContent" style="display:none;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.86rem">
                <thead>
                    <tr style="background:#f1f5f9">
                        <th style="padding:10px 14px;text-align:right;border-bottom:2px solid #e5e7eb">شماره فاکتور</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">تاریخ</th>
                        <th style="padding:10px 14px;text-align:right;border-bottom:2px solid #e5e7eb">مشتری</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">مبلغ</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">وضعیت مالیاتی</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">شماره مالیاتی</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">عملیات</th>
                    </tr>
                </thead>
                <tbody id="invTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال تنظیمات -->
<div id="settingsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:none;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:16px;padding:30px;width:min(560px,94vw);max-height:90vh;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
            <h2 style="font-size:1.1rem;font-weight:700;margin:0">⚙️ تنظیمات API مالیاتی</h2>
            <button onclick="closeSettings()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#6b7280">✕</button>
        </div>
        <form onsubmit="saveSettings(event)">
            <?php echo csrf_field(); ?>
            <div style="margin-bottom:16px">
                <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">فعال‌سازی API</label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" id="s-enabled" value="1" <?php if($taxSettings['tax_api_enabled']??'0') echo 'checked' ?>>
                    <span style="font-size:.88rem">ارسال مستقیم به سامانه مؤدیان فعال باشد</span>
                </label>
            </div>
            <?php
            $fields = [
                'tax_company_name'  => 'نام شرکت',
                'tax_economic_code' => 'کد اقتصادی',
                'tax_national_id'   => 'شناسه ملی شرکت',
                'tax_postal_code'   => 'کد پستی',
                'tax_api_base_url'  => 'آدرس API سامانه مؤدیان',
                'tax_token'         => 'Token دسترسی (User Token)',
            ];
            foreach ($fields as $key => $label):
            ?>
            <div style="margin-bottom:14px">
                <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px"><?php echo $label ?></label>
                <input type="<?php echo $key === 'tax_token' ? 'password' : 'text'; ?>" name="<?php echo $key ?>" id="s-<?php echo str_replace('_','-',$key) ?>"
                       class="fin-input" value="<?php echo htmlspecialchars($taxSettings[$key] ?? '') ?>"
                       placeholder="<?php echo $label ?>" dir="ltr">
            </div>
            <?php endforeach; ?>
            <div style="margin-bottom:14px">
                <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">کلید خصوصی RSA (PEM)</label>
                <textarea name="tax_private_key" id="s-tax-private-key" class="fin-input" rows="4" dir="ltr" placeholder="-----BEGIN PRIVATE KEY-----"><?php echo htmlspecialchars($taxSettings['tax_private_key']??'') ?></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="fin-btn" onclick="closeSettings()">انصراف</button>
                <button type="submit" class="fin-btn fin-btn-primary">ذخیره تنظیمات</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ثبت ارسال دستی -->
<div id="markSentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:14px;padding:28px;width:min(420px,94vw)">
        <h3 style="margin:0 0 16px;font-size:1rem;font-weight:700">ثبت ارسال به سامانه مالیاتی</h3>
        <input type="hidden" id="markInvoiceId" value="">
        <?php echo csrf_field(); ?>
        <div style="margin-bottom:14px">
            <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">شماره مالیاتی (اختیاری)</label>
            <input type="text" id="markTaxSerial" class="fin-input" placeholder="شماره ارجاع از سامانه مؤدیان" dir="ltr">
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button class="fin-btn" onclick="closeMarkSent()">انصراف</button>
            <button class="fin-btn fin-btn-primary" onclick="submitMarkSent()">ثبت ارسال</button>
        </div>
    </div>
</div>

<!-- مودال XML -->
<div id="xmlModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:14px;padding:28px;width:min(700px,96vw);max-height:90vh;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h3 style="margin:0;font-size:1rem;font-weight:700">📄 XML معاملات فصلی</h3>
            <button onclick="closeXml()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#6b7280">✕</button>
        </div>
        <div id="xmlContent" style="background:#f8fafc;border-radius:8px;padding:16px;font-family:monospace;font-size:.78rem;direction:ltr;white-space:pre-wrap;max-height:400px;overflow-y:auto;border:1px solid #e5e7eb"></div>
        <div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end">
            <button class="fin-btn" onclick="copyXml()">📋 کپی</button>
            <button class="fin-btn" onclick="downloadXml()">⬇ دانلود</button>
            <button class="fin-btn" onclick="closeXml()">بستن</button>
        </div>
    </div>
</div>

<style>
.fin-input { width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,sans-serif;font-size:.88rem;background:#f9fafb;color:#111827;transition:border-color .2s;box-sizing:border-box }
.fin-input:focus { outline:none;border-color:#4f46e5;background:#fff }
.fin-btn { padding:8px 18px;border:1.5px solid #d1d5db;background:#fff;border-radius:8px;font-family:Vazirmatn,sans-serif;font-size:.85rem;cursor:pointer;font-weight:600;transition:all .2s }
.fin-btn-primary { background:#4f46e5;color:#fff;border-color:#4f46e5 }
.fin-btn-primary:hover { background:#4338ca }
.fin-stat-card { background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 1px 8px rgba(0,0,0,.07);border-right:4px solid }
.fin-stat-card.blue  { border-color:#2563eb } .fin-stat-card.green { border-color:#059669 }
.fin-stat-card.amber { border-color:#d97706 } .fin-stat-card.rose  { border-color:#e11d48 }
.stat-val { font-size:1.6rem;font-weight:800;color:#1e293b }
.stat-lbl { font-size:.78rem;color:#6b7280;margin-top:4px }
.tax-badge-unsent    { background:#f1f5f9;color:#374151 }
.tax-badge-pending   { background:#fef3c7;color:#92400e }
.tax-badge-sent      { background:#dbeafe;color:#1d4ed8 }
.tax-badge-confirmed { background:#d1fae5;color:#065f46 }
.tax-badge-rejected, .tax-badge-error { background:#ffe4e6;color:#9f1239 }
.tax-badge { display:inline-block;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700 }
</style>

<script>
const CSRF = '<?php echo csrf_token(); ?>';
function moneyFa(n) { return new Intl.NumberFormat('fa-IR').format(Math.round(n)) + ' تومان'; }

const TAX_STATUS = {unsent:'ارسال‌نشده',pending:'در انتظار',sent:'ارسال‌شده',confirmed:'تأییدشده',rejected:'رد‌شده',error:'خطا'};
const TAX_CLS    = {unsent:'tax-badge-unsent',pending:'tax-badge-pending',sent:'tax-badge-sent',confirmed:'tax-badge-confirmed',rejected:'tax-badge-rejected',error:'tax-badge-error'};

function loadStats() {
    fetch('fin_tax_integration.php?action=get_stats',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(res=>{
        if(!res.ok) return;
        document.getElementById('st-total').textContent     = res.total;
        document.getElementById('st-confirmed').textContent = res.confirmed;
        document.getElementById('st-pending').textContent   = res.pending;
        document.getElementById('st-errors').textContent    = res.errors;
    });
}

function loadInvoices() {
    const status = document.getElementById('taxStatusFilter').value;
    document.getElementById('invLoading').style.display = '';
    document.getElementById('invContent').style.display = 'none';
    fetch(`fin_tax_integration.php?action=list_invoices&status=${status}`,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(res=>{
        document.getElementById('invLoading').style.display = 'none';
        document.getElementById('invContent').style.display = '';
        const tb = document.getElementById('invTableBody');
        if(!res.ok||!res.data.length) { tb.innerHTML='<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8">فاکتوری یافت نشد</td></tr>'; return; }
        tb.innerHTML = res.data.map(r => {
            const ts = r.tax_status || 'unsent';
            return `<tr>
                <td style="padding:9px 14px;border-bottom:1px solid #f1f5f9;font-weight:600;color:#4f46e5">${r.invoice_number||'—'}</td>
                <td style="padding:9px 14px;border-bottom:1px solid #f1f5f9;text-align:center">${r.invoice_date||'—'}</td>
                <td style="padding:9px 14px;border-bottom:1px solid #f1f5f9">${r.person_name||'—'}</td>
                <td style="padding:9px 14px;border-bottom:1px solid #f1f5f9;text-align:center;font-weight:600">${moneyFa(r.total_amount)}</td>
                <td style="padding:9px 14px;border-bottom:1px solid #f1f5f9;text-align:center"><span class="tax-badge ${TAX_CLS[ts]||'tax-badge-unsent'}">${TAX_STATUS[ts]||ts}</span></td>
                <td style="padding:9px 14px;border-bottom:1px solid #f1f5f9;text-align:center;font-family:monospace;font-size:.78rem">${r.tax_serial||'—'}</td>
                <td style="padding:9px 14px;border-bottom:1px solid #f1f5f9;text-align:center">
                    <button class="fin-btn" style="font-size:.75rem;padding:4px 10px" onclick="openMarkSent(${r.id})">ثبت ارسال</button>
                </td>
            </tr>`;
        }).join('');
    });
}

function showSettings() { document.getElementById('settingsModal').style.display = 'flex'; }
function closeSettings() { document.getElementById('settingsModal').style.display = 'none'; }
function saveSettings(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action','save_settings');
    fd.set('tax_api_enabled', document.getElementById('s-enabled').checked ? '1' : '0');
    fd.append('csrf_token', CSRF);
    fetch('fin_tax_integration.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(res=>{ if(res.ok) { closeSettings(); alert(res.msg); } else alert(res.msg); });
}

function openMarkSent(id) {
    document.getElementById('markInvoiceId').value = id;
    document.getElementById('markTaxSerial').value = '';
    document.getElementById('markSentModal').style.display = 'flex';
}
function closeMarkSent() { document.getElementById('markSentModal').style.display = 'none'; }
function submitMarkSent() {
    const fd = new FormData();
    fd.append('action','mark_sent');
    fd.append('csrf_token', CSRF);
    fd.append('invoice_id', document.getElementById('markInvoiceId').value);
    fd.append('tax_serial',  document.getElementById('markTaxSerial').value);
    fetch('fin_tax_integration.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(res=>{ if(res.ok) { closeMarkSent(); loadInvoices(); loadStats(); } else alert(res.msg); });
}

let xmlData = '';
function generateXml() {
    const fyId = document.getElementById('taxFySelect').value;
    fetch(`fin_tax_integration.php?action=generate_xml&fiscal_year_id=${fyId}`,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(res=>{
        if(!res.ok) { alert(res.msg); return; }
        xmlData = res.xml;
        document.getElementById('xmlContent').textContent = res.xml;
        document.getElementById('xmlModal').style.display = 'flex';
    });
}
function closeXml() { document.getElementById('xmlModal').style.display = 'none'; }
function copyXml() { navigator.clipboard.writeText(xmlData).then(()=>alert('XML کپی شد')); }
function downloadXml() {
    const blob = new Blob([xmlData], {type:'application/xml'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'tax_transactions_' + new Date().getFullYear() + '.xml';
    a.click();
}

document.addEventListener('DOMContentLoaded', () => { loadStats(); loadInvoices(); });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
