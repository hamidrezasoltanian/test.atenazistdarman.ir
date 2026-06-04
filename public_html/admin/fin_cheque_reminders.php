<?php
/*
 * فایل: public_html/admin/fin_cheque_reminders.php
 * یادآور سررسید چک با SMS — ارسال یادآوری به طرف حساب
 */

ob_start();

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$userId = (int)$_SESSION['user_id'];

// ── ایجاد جدول لاگ ──────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `fin_cheque_sms_log` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `cheque_id`   INT NOT NULL,
        `mobile`      VARCHAR(20) NOT NULL,
        `message`     TEXT NOT NULL,
        `status`      ENUM('sent','failed') NOT NULL DEFAULT 'sent',
        `sent_by`     INT DEFAULT NULL,
        `sent_at`     DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ── تابع ارسال SMS متن آزاد ─────────────────────────────────
function sendFreeSms(string $mobile, string $message): bool {
    $config  = require __DIR__ . '/../../Config/config.php';
    $apiKey  = $config['sms_api_key'] ?? '';
    $from    = $config['sms_from_number'] ?? '';
    if (!$apiKey) return false;

    if (substr($mobile, 0, 1) === '0') $mobile = '+98' . substr($mobile, 1);
    if (!preg_match('/^\+?\d{10,14}$/', $mobile)) return false;

    // IPPanel plain SMS endpoint
    $url  = 'https://api2.ippanel.com/api/v1/sms/send/webservice/single';
    $body = json_encode(['sender' => $from, 'recipient' => $mobile, 'message' => $message]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300);
}

// ═══════════════════════════════════════════════════════════════
// AJAX
// ═══════════════════════════════════════════════════════════════
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_REQUEST['action'] ?? '');

    try {
        // ── لیست چک‌های نزدیک سررسید و معوق ──
        if ($action === 'get_upcoming') {
            $days = max(1, min(60, (int)($_GET['days'] ?? 14)));

            $rows = $pdo->prepare(
                "SELECT c.id, c.cheque_number, c.type, c.bank_name,
                        c.amount, c.due_date, c.status, c.description,
                        COALESCE(fp.name, fp.company_name) AS person_name,
                        fp.mobile AS person_mobile,
                        DATEDIFF(c.due_date, CURDATE()) AS days_to_due,
                        (SELECT COUNT(*) FROM fin_cheque_sms_log l WHERE l.cheque_id=c.id) AS sms_count,
                        (SELECT MAX(l.sent_at) FROM fin_cheque_sms_log l WHERE l.cheque_id=c.id) AS last_sms
                 FROM fin_cheques c
                 LEFT JOIN fin_persons fp ON fp.id=c.person_id
                 WHERE c.is_deleted=0
                   AND c.status='pending'
                   AND c.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                 ORDER BY c.due_date ASC
                 LIMIT 200"
            );
            $rows->execute([$days]);
            echo json_encode(['ok' => true, 'rows' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── ارسال SMS ──
        if ($action === 'send_sms') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'خطای امنیتی']); exit;
            }
            $chequeId = (int)($_POST['cheque_id'] ?? 0);
            $mobile   = trim($_POST['mobile'] ?? '');
            $message  = trim($_POST['message'] ?? '');

            if (!$chequeId || !$mobile || !$message) {
                echo json_encode(['ok' => false, 'msg' => 'اطلاعات ناقص']); exit;
            }

            $ok = sendFreeSms($mobile, $message);
            $status = $ok ? 'sent' : 'failed';
            $pdo->prepare(
                "INSERT INTO fin_cheque_sms_log (cheque_id,mobile,message,status,sent_by) VALUES (?,?,?,?,?)"
            )->execute([$chequeId, $mobile, $message, $status, $userId]);

            echo json_encode(['ok' => $ok, 'msg' => $ok ? 'SMS با موفقیت ارسال شد.' : 'ارسال SMS ناموفق بود. لاگ ذخیره شد.']);
            exit;
        }

        // ── ارسال گروهی (همه چک‌های معوق دارای موبایل) ──
        if ($action === 'send_bulk') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'خطای امنیتی']); exit;
            }
            $days    = max(1, min(60, (int)($_POST['days'] ?? 7)));
            $tpl     = trim($_POST['template'] ?? '');
            if (!$tpl) { echo json_encode(['ok' => false, 'msg' => 'متن پیام خالی است']); exit; }

            $rows = $pdo->prepare(
                "SELECT c.id, c.cheque_number, c.amount, c.due_date,
                        COALESCE(fp.name, fp.company_name) AS person_name, fp.mobile
                 FROM fin_cheques c
                 LEFT JOIN fin_persons fp ON fp.id=c.person_id
                 WHERE c.is_deleted=0 AND c.status='pending'
                   AND c.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                   AND fp.mobile IS NOT NULL AND fp.mobile != ''
                 ORDER BY c.due_date ASC LIMIT 50"
            );
            $rows->execute([$days]);
            $cheques = $rows->fetchAll(PDO::FETCH_ASSOC);

            $sent = 0; $failed = 0;
            foreach ($cheques as $c) {
                $msg = str_replace(
                    ['{نام}', '{مبلغ}', '{سررسید}', '{شماره_چک}'],
                    [$c['person_name'], number_format((int)$c['amount']), $c['due_date'], $c['cheque_number']],
                    $tpl
                );
                $ok = sendFreeSms($c['mobile'], $msg);
                $pdo->prepare(
                    "INSERT INTO fin_cheque_sms_log (cheque_id,mobile,message,status,sent_by) VALUES (?,?,?,?,?)"
                )->execute([$c['id'], $c['mobile'], $msg, $ok ? 'sent' : 'failed', $userId]);
                $ok ? $sent++ : $failed++;
            }
            echo json_encode(['ok' => true, 'msg' => "ارسال انجام شد. موفق: $sent | ناموفق: $failed"]);
            exit;
        }

        // ── تاریخچه SMS یک چک ──
        if ($action === 'get_log') {
            $chequeId = (int)($_GET['cheque_id'] ?? 0);
            $rows = $pdo->prepare(
                "SELECT l.*, u.full_name sender_name
                 FROM fin_cheque_sms_log l
                 LEFT JOIN users u ON u.id=l.sent_by
                 WHERE l.cheque_id=?
                 ORDER BY l.sent_at DESC LIMIT 20"
            );
            $rows->execute([$chequeId]);
            echo json_encode(['ok' => true, 'rows' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'درخواست نامعتبر']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'خطای سرور: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════
// HTML
// ═══════════════════════════════════════════════════════════════
$basePath  = '../../';
$pageTitle = 'یادآور سررسید چک';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<link rel="stylesheet" href="<?= $basePath ?>public_html/assets/css/fin_module.css">
<style>
.reminder-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:1.5rem}
.reminder-stat{background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;padding:1rem 1.2rem;text-align:center}
.reminder-stat .val{font-size:1.6rem;font-weight:700}
.reminder-stat .lbl{font-size:.78rem;color:#64748b;margin-top:.2rem}
.cheq-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;padding:.7rem 1rem;border-bottom:1px solid #f1f5f9}
.cheq-row:last-child{border:none}
.cheq-row:hover{background:#fafafa}
.days-badge{display:inline-block;padding:.25em .6em;border-radius:.4rem;font-size:.75rem;font-weight:700}
.overdue{background:#fee2e2;color:#991b1b}
.today{background:#fef9c3;color:#854d0e}
.soon{background:#dbeafe;color:#1e40af}
.fin-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;z-index:9999}
.fin-modal-box{background:#fff;border-radius:1rem;padding:1.5rem;width:min(96vw,520px);max-height:90vh;overflow-y:auto}
.fin-modal-box h3{margin:0 0 1rem;font-size:1.05rem}
.form-group{margin-bottom:.9rem}
.form-group label{display:block;font-size:.82rem;color:#374151;margin-bottom:.25rem;font-weight:500}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:.48rem .7rem;border:1px solid #cbd5e1;border-radius:.45rem;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.88rem;box-sizing:border-box}
.form-group textarea{min-height:80px;resize:vertical}
.btn-row{display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem}
</style>

<div class="fin-layout" style="direction:rtl">
  <?php require_once __DIR__ . '/../../templates/sidebar.php'; ?>
  <div class="fin-main">
    <div class="fin-topbar">
      <h1 class="fin-page-title">📱 یادآور سررسید چک</h1>
      <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
        <label style="font-size:.85rem;color:#64748b">نمایش تا:</label>
        <select id="daysFilter" class="fin-select" style="width:120px" onchange="loadData()">
          <option value="0">معوق</option>
          <option value="3">۳ روز آینده</option>
          <option value="7" selected>۷ روز آینده</option>
          <option value="14">۱۴ روز آینده</option>
          <option value="30">۳۰ روز آینده</option>
        </select>
        <button class="fin-btn fin-btn-secondary" onclick="openBulk()">📤 ارسال گروهی</button>
      </div>
    </div>

    <!-- کارت‌های آماری -->
    <div class="reminder-grid" id="statsGrid" style="margin-bottom:1.5rem">
      <div class="reminder-stat"><div class="val" style="color:#dc2626" id="sOverdue">—</div><div class="lbl">معوق</div></div>
      <div class="reminder-stat"><div class="val" style="color:#f59e0b" id="sToday">—</div><div class="lbl">سررسید امروز</div></div>
      <div class="reminder-stat"><div class="val" style="color:#2563eb" id="sWeek">—</div><div class="lbl">سررسید ۷ روز آینده</div></div>
      <div class="reminder-stat"><div class="val" style="color:#64748b" id="sHasMobile">—</div><div class="lbl">دارای موبایل</div></div>
    </div>

    <!-- جدول چک‌ها -->
    <div class="fin-panel" style="padding:0;overflow:hidden">
      <div id="chequeList">
        <div style="text-align:center;padding:3rem;color:#94a3b8">در حال بارگذاری...</div>
      </div>
    </div>

  </div>
</div>

<!-- مودال: ارسال SMS تکی -->
<div class="fin-modal-overlay" id="smsModal">
  <div class="fin-modal-box">
    <h3>📱 ارسال یادآوری</h3>
    <input type="hidden" id="smsChequeId">
    <div class="form-group">
      <label>شماره موبایل</label>
      <input type="text" id="smsMobile">
    </div>
    <div class="form-group">
      <label>متن پیام</label>
      <textarea id="smsMessage" rows="4"></textarea>
    </div>
    <p style="font-size:.78rem;color:#94a3b8;margin:.5rem 0">متغیرها: {نام} {مبلغ} {سررسید} {شماره_چک}</p>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('smsModal')">لغو</button>
      <button class="fin-btn fin-btn-primary" onclick="doSendSms()">📤 ارسال SMS</button>
    </div>
  </div>
</div>

<!-- مودال: ارسال گروهی -->
<div class="fin-modal-overlay" id="bulkModal">
  <div class="fin-modal-box">
    <h3>📤 ارسال گروهی یادآوری</h3>
    <p style="font-size:.85rem;color:#64748b">پیام برای تمام چک‌های دارای موبایل در بازه انتخاب‌شده ارسال می‌شود.</p>
    <div class="form-group">
      <label>ارسال برای چک‌های معوق یا سررسید تا X روز آینده</label>
      <select id="bulkDays" class="fin-select" style="width:100%">
        <option value="0">فقط معوق</option>
        <option value="1">۱ روز</option>
        <option value="3">۳ روز</option>
        <option value="7" selected>۷ روز</option>
        <option value="14">۱۴ روز</option>
      </select>
    </div>
    <div class="form-group">
      <label>قالب پیام</label>
      <textarea id="bulkTemplate" rows="4"><?= htmlspecialchars('با سلام {نام} عزیز، چک شماره {شماره_چک} به مبلغ {مبلغ} ریال در تاریخ {سررسید} سررسید می‌گردد. لطفاً نسبت به تسویه اقدام فرمایید.') ?></textarea>
    </div>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('bulkModal')">لغو</button>
      <button class="fin-btn fin-btn-primary" onclick="doBulkSend()">📤 ارسال گروهی</button>
    </div>
  </div>
</div>

<!-- مودال: تاریخچه SMS -->
<div class="fin-modal-overlay" id="logModal">
  <div class="fin-modal-box">
    <h3>📋 تاریخچه SMS</h3>
    <div id="logRows"></div>
    <div class="btn-row"><button class="fin-btn fin-btn-secondary" onclick="closeModal('logModal')">بستن</button></div>
  </div>
</div>

<script>
var csrf = '<?= htmlspecialchars(csrf_token()) ?>';
function fmt(n){return parseInt(n||0).toLocaleString('fa-IR');}
function openModal(id){document.getElementById(id).style.display='flex';}
function closeModal(id){document.getElementById(id).style.display='none';}
function showToast(msg,type='success'){
    var t=document.createElement('div');
    t.textContent=msg;
    t.style.cssText='position:fixed;bottom:1.5rem;right:1.5rem;padding:.75rem 1.25rem;border-radius:.5rem;z-index:99999;font-size:.9rem;color:#fff;background:'+(type==='success'?'#10b981':'#ef4444');
    document.body.appendChild(t);setTimeout(()=>t.remove(),4000);
}
function esc(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

function loadData(){
    var days=parseInt(document.getElementById('daysFilter').value)||0;
    var url='fin_cheque_reminders.php?action=get_upcoming&days='+(days<=0?365:days);
    fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(function(d){
        if(!d.ok)return;
        renderStats(d.rows);
        renderList(d.rows);
    });
}

function renderStats(rows){
    var today=new Date().toISOString().split('T')[0];
    var overdue=0,todayN=0,week=0,hasMob=0;
    rows.forEach(function(r){
        var diff=parseInt(r.days_to_due||0);
        if(diff<0)overdue++;
        else if(diff===0)todayN++;
        if(diff<=7&&diff>=0)week++;
        if(r.person_mobile)hasMob++;
    });
    document.getElementById('sOverdue').textContent=overdue;
    document.getElementById('sToday').textContent=todayN;
    document.getElementById('sWeek').textContent=week;
    document.getElementById('sHasMobile').textContent=hasMob;
}

function renderList(rows){
    if(!rows.length){
        document.getElementById('chequeList').innerHTML='<div style="text-align:center;padding:3rem;color:#94a3b8">در بازه انتخاب‌شده چکی یافت نشد</div>';
        return;
    }
    var h='<table style="width:100%;border-collapse:collapse;font-size:.83rem">';
    h+='<thead><tr style="background:#f8fafc">';
    ['شماره چک','نوع','طرف حساب','موبایل','مبلغ (ریال)','سررسید','وضعیت','SMS قبلی','عملیات'].forEach(function(t){
        h+='<th style="padding:.6rem .75rem;text-align:right;color:#64748b;font-weight:600;border-bottom:2px solid #e2e8f0">'+t+'</th>';
    });
    h+='</tr></thead><tbody>';
    rows.forEach(function(r){
        var diff=parseInt(r.days_to_due||0);
        var badgeClass=diff<0?'overdue':diff===0?'today':'soon';
        var badgeText=diff<0?'معوق '+Math.abs(diff)+' روز':diff===0?'امروز':diff+' روز دیگر';
        var typeLabel=r.type==='received'?'دریافتی':'پرداختی';
        h+='<tr style="border-bottom:1px solid #f1f5f9" onmouseenter="this.style.background=\'#fafafa\'" onmouseleave="this.style.background=\'\'">';
        h+='<td style="padding:.6rem .75rem;font-weight:600;color:#0ea5e9">'+esc(r.cheque_number)+'</td>';
        h+='<td style="padding:.6rem .75rem;color:#64748b">'+typeLabel+'</td>';
        h+='<td style="padding:.6rem .75rem">'+esc(r.person_name||'—')+'</td>';
        h+='<td style="padding:.6rem .75rem;direction:ltr;color:#64748b">'+esc(r.person_mobile||'—')+'</td>';
        h+='<td style="padding:.6rem .75rem;text-align:left;font-weight:600">'+fmt(r.amount)+'</td>';
        h+='<td style="padding:.6rem .75rem;color:#64748b;white-space:nowrap">'+esc(r.due_date||'')+'</td>';
        h+='<td style="padding:.6rem .75rem"><span class="days-badge '+badgeClass+'">'+badgeText+'</span></td>';
        h+='<td style="padding:.6rem .75rem;color:#64748b">';
        if(parseInt(r.sms_count||0)>0) h+='<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="openLog('+r.id+')">📋 '+r.sms_count+'</button>';
        else h+='—';
        h+='</td>';
        h+='<td style="padding:.6rem .75rem">';
        if(r.person_mobile) h+='<button class="fin-btn fin-btn-primary fin-btn-sm" onclick="openSms('+r.id+',\''+esc(r.person_mobile)+'\',\''+esc(r.person_name||'')+'\',\''+fmt(r.amount)+'\',\''+esc(r.due_date||'')+'\',\''+esc(r.cheque_number)+'\')">📱 SMS</button>';
        else h+='<span style="color:#94a3b8;font-size:.78rem">بدون موبایل</span>';
        h+='</td>';
        h+='</tr>';
    });
    h+='</tbody></table>';
    document.getElementById('chequeList').innerHTML=h;
}

function openSms(id,mobile,name,amount,due,chequeNum){
    document.getElementById('smsChequeId').value=id;
    document.getElementById('smsMobile').value=mobile;
    var msg='با سلام '+name+' عزیز، چک شماره '+chequeNum+' به مبلغ '+amount+' ریال در تاریخ '+due+' سررسید می‌گردد. لطفاً نسبت به تسویه اقدام فرمایید.';
    document.getElementById('smsMessage').value=msg;
    openModal('smsModal');
}

function doSendSms(){
    var fd=new FormData();
    fd.append('action','send_sms');fd.append('csrf_token',csrf);
    fd.append('cheque_id',document.getElementById('smsChequeId').value);
    fd.append('mobile',document.getElementById('smsMobile').value);
    fd.append('message',document.getElementById('smsMessage').value);
    fetch('fin_cheque_reminders.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        closeModal('smsModal');
        showToast(d.msg,d.ok?'success':'error');
        if(d.ok)loadData();
    });
}

function openBulk(){openModal('bulkModal');}
function doBulkSend(){
    if(!confirm('آیا از ارسال گروهی SMS مطمئن هستید؟'))return;
    var fd=new FormData();
    fd.append('action','send_bulk');fd.append('csrf_token',csrf);
    fd.append('days',document.getElementById('bulkDays').value);
    fd.append('template',document.getElementById('bulkTemplate').value);
    fetch('fin_cheque_reminders.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        closeModal('bulkModal');
        showToast(d.msg,d.ok?'success':'error');
        if(d.ok)loadData();
    });
}

function openLog(chequeId){
    openModal('logModal');
    document.getElementById('logRows').innerHTML='<p style="text-align:center;color:#94a3b8">در حال بارگذاری...</p>';
    fetch('fin_cheque_reminders.php?action=get_log&cheque_id='+chequeId,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(function(d){
        if(!d.ok||!d.rows.length){document.getElementById('logRows').innerHTML='<p style="color:#94a3b8;text-align:center">تاریخچه‌ای ثبت نشده</p>';return;}
        var h='<table style="width:100%;border-collapse:collapse;font-size:.82rem">';
        h+='<tr style="background:#f8fafc"><th style="padding:.5rem;text-align:right">تاریخ</th><th>فرستنده</th><th>موبایل</th><th>وضعیت</th></tr>';
        d.rows.forEach(function(l){
            h+='<tr style="border-bottom:1px solid #f1f5f9">';
            h+='<td style="padding:.5rem">'+esc(l.sent_at||'')+'</td>';
            h+='<td style="padding:.5rem">'+esc(l.sender_name||'سیستم')+'</td>';
            h+='<td style="padding:.5rem;direction:ltr">'+esc(l.mobile)+'</td>';
            h+='<td style="padding:.5rem"><span style="color:'+(l.status==='sent'?'#16a34a':'#dc2626')+'">'+( l.status==='sent'?'✅ ارسال':'❌ ناموفق')+'</span></td>';
            h+='</tr>';
        });
        h+='</table>';
        document.getElementById('logRows').innerHTML=h;
    });
}

document.addEventListener('DOMContentLoaded',loadData);
</script>

<?php
ob_end_flush();
require_once __DIR__ . '/../../templates/footer.php';
?>
