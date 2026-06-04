<?php
/*
 * فایل: public_html/admin/attendance_checkin_manage.php
 * پنل ادمین مدیریت حضور و غیاب QR
 */
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$userId = (int)$_SESSION['user_id'];

function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v !== false) ? $v : $default;
    } catch (Throwable $e) { return $default; }
}

// ── جدول‌سازی ────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `attendance_checkins` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `check_in_time` DATETIME NULL,
        `check_out_time` DATETIME NULL,
        `check_in_ip` VARCHAR(45) NULL,
        `check_out_ip` VARCHAR(45) NULL,
        `check_in_device` VARCHAR(255) NULL,
        `check_out_device` VARCHAR(255) NULL,
        `ip_valid` TINYINT(1) NOT NULL DEFAULT 0,
        `mock_flag` TINYINT(1) NOT NULL DEFAULT 0,
        `status` ENUM('present','partial','flagged') NOT NULL DEFAULT 'present',
        `work_date` DATE NOT NULL,
        `notes` TEXT NULL,
        `admin_note` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_user_date` (`user_id`, `work_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// ── AJAX ──────────────────────────────────────────────────────
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=UTF-8');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // آمار امروز (برای صفحه نمایش QR)
    if ($action === 'today_stats') {
        try {
            $today = date('Y-m-d');
            $present    = (int)$pdo->prepare("SELECT COUNT(*) FROM attendance_checkins WHERE work_date=? AND check_in_time IS NOT NULL")->execute([$today]) ? $pdo->prepare("SELECT COUNT(*) FROM attendance_checkins WHERE work_date=? AND check_in_time IS NOT NULL")->execute([$today]) && 0 : 0;
            $stP = $pdo->prepare("SELECT COUNT(*) FROM attendance_checkins WHERE work_date=? AND check_in_time IS NOT NULL");
            $stP->execute([$today]);
            $present = (int)$stP->fetchColumn();
            $stO = $pdo->prepare("SELECT COUNT(*) FROM attendance_checkins WHERE work_date=? AND check_out_time IS NOT NULL");
            $stO->execute([$today]);
            $checkedOut = (int)$stO->fetchColumn();
            echo json_encode(['ok'=>true,'present'=>$present,'checked_out'=>$checkedOut]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    // لیست حضور
    if ($action === 'list') {
        $date = $_GET['date'] ?? date('Y-m-d');
        try {
            $sql = "SELECT ac.*,
                           CONCAT(u.first_name,' ',u.last_name) AS full_name,
                           u.department_id,
                           d.name AS dept_name
                    FROM attendance_checkins ac
                    JOIN users u ON u.id = ac.user_id
                    LEFT JOIN departments d ON d.id = u.department_id
                    WHERE ac.work_date = ?
                    ORDER BY ac.check_in_time ASC, u.first_name ASC";
            $st = $pdo->prepare($sql);
            $st->execute([$date]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // محاسبه مدت حضور
            foreach ($rows as &$r) {
                $r['duration'] = '';
                if ($r['check_in_time'] && $r['check_out_time']) {
                    $secs = strtotime($r['check_out_time']) - strtotime($r['check_in_time']);
                    $h = floor($secs/3600); $m = floor(($secs%3600)/60);
                    $r['duration'] = "{$h}h {$m}m";
                }
            }
            unset($r);
            echo json_encode(['ok'=>true,'data'=>$rows]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    // آمار کلی (range)
    if ($action === 'range_stats') {
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');
        try {
            $st = $pdo->prepare("SELECT
                COUNT(*) AS total,
                SUM(check_in_time IS NOT NULL) AS present,
                SUM(check_out_time IS NOT NULL) AS full_day,
                SUM(mock_flag=1) AS flagged,
                SUM(ip_valid=0 AND mock_flag=1) AS ip_mismatch
                FROM attendance_checkins
                WHERE work_date BETWEEN ? AND ?");
            $st->execute([$from, $to]);
            echo json_encode(['ok'=>true,'data'=>$st->fetch(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    // یادداشت ادمین
    if ($action === 'admin_note') {
        $id   = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        try {
            $pdo->prepare("UPDATE attendance_checkins SET admin_note=? WHERE id=?")->execute([$note, $id]);
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    // ویرایش ساعت
    if ($action === 'edit_time') {
        $id      = (int)($_POST['id'] ?? 0);
        $inTime  = trim($_POST['check_in_time']  ?? '');
        $outTime = trim($_POST['check_out_time'] ?? '');
        try {
            $pdo->prepare("UPDATE attendance_checkins SET check_in_time=?, check_out_time=?, admin_note=CONCAT(IFNULL(admin_note,''), '\n[ادمین ویرایش کرد: ', NOW(), ']') WHERE id=?")
                ->execute([$inTime ?: null, $outTime ?: null, $id]);
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    // تنظیمات ذخیره
    if ($action === 'save_settings') {
        $fields = ['checkin_office_subnet','checkin_subnet_strict','checkin_qr_ttl','checkin_workday_start','checkin_workday_end'];
        $up = $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?");
        foreach ($fields as $f) {
            $v = trim($_POST[$f] ?? '');
            $up->execute([$f,$v,$v]);
        }
        echo json_encode(['ok'=>true,'msg'=>'تنظیمات ذخیره شد']);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'اکشن نامعتبر']);
    exit;
}

// ── تنظیمات صفحه ─────────────────────────────────────────────
$pageTitle = 'مدیریت حضور و غیاب — QR';
$basePath  = '../../';
$subnet    = getSetting($pdo, 'checkin_office_subnet', '');
$strict    = getSetting($pdo, 'checkin_subnet_strict', '0');
$ttl       = getSetting($pdo, 'checkin_qr_ttl', '90');

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<div style="padding:24px;min-height:100vh;background:#f8fafc">

    <!-- هدر -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:1.4rem;font-weight:700;color:#1e293b;margin:0">📡 حضور و غیاب آنلاین</h1>
            <p style="margin:4px 0 0;color:#64748b;font-size:.88rem">سیستم QR یک‌بار مصرف + بررسی شبکه + Device Fingerprint</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="attendance_qr_display.php" target="_blank"
               style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:#0f172a;color:#38bdf8;border-radius:10px;text-decoration:none;font-size:.88rem;font-weight:700">
               📺 باز کردن صفحه نمایش QR
            </a>
            <button onclick="showSettings()" style="padding:9px 18px;background:#4f46e5;color:#fff;border:none;border-radius:10px;font-family:Vazirmatn,sans-serif;font-size:.85rem;font-weight:700;cursor:pointer">
                ⚙️ تنظیمات
            </button>
        </div>
    </div>

    <!-- فیلتر تاریخ -->
    <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.07);padding:16px 20px;margin-bottom:20px;display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap">
        <div>
            <label style="font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">تاریخ</label>
            <input type="date" id="filterDate" value="<?php echo date('Y-m-d') ?>"
                   onchange="loadList()" class="fin-input" style="font-family:monospace">
        </div>
        <div style="display:flex;gap:8px">
            <button onclick="setDate('today')"     class="day-btn">امروز</button>
            <button onclick="setDate('yesterday')" class="day-btn">دیروز</button>
        </div>
        <div style="margin-right:auto">
            <span id="statBadges" style="display:flex;gap:8px;flex-wrap:wrap"></span>
        </div>
    </div>

    <!-- جدول -->
    <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.07);overflow:hidden">
        <div id="tableLoading" style="text-align:center;padding:40px;color:#94a3b8">در حال بارگذاری...</div>
        <div id="tableContent" style="display:none;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                <thead>
                    <tr style="background:#f1f5f9">
                        <th style="padding:10px 14px;text-align:right;border-bottom:2px solid #e5e7eb">نام</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">دپارتمان</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">ورود</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">خروج</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">مدت</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">شبکه</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">وضعیت</th>
                        <th style="padding:10px 14px;text-align:center;border-bottom:2px solid #e5e7eb">عملیات</th>
                    </tr>
                </thead>
                <tbody id="checkinTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال تنظیمات -->
<div id="settingsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:16px;padding:28px;width:min(500px,94vw);max-height:90vh;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
            <h2 style="font-size:1.1rem;font-weight:700;margin:0">⚙️ تنظیمات حضور QR</h2>
            <button onclick="closeSettings()" style="background:none;border:none;font-size:1.3rem;cursor:pointer">✕</button>
        </div>
        <form onsubmit="saveSettings(event)">
            <div style="margin-bottom:16px">
                <label class="fin-lbl">مدت اعتبار QR (ثانیه)</label>
                <input type="number" name="checkin_qr_ttl" id="s-ttl" class="fin-input" value="<?php echo $ttl ?>" min="30" max="300">
                <small style="color:#94a3b8;font-size:.75rem">پیشنهاد: ۹۰ ثانیه</small>
            </div>
            <div style="margin-bottom:16px">
                <label class="fin-lbl">محدوده IP شبکه دفتر (CIDR)</label>
                <input type="text" name="checkin_office_subnet" id="s-subnet" class="fin-input" value="<?php echo htmlspecialchars($subnet) ?>" placeholder="192.168.1.0/24 یا خالی=غیرفعال" dir="ltr">
                <small style="color:#94a3b8;font-size:.75rem">چند range با کاما جدا کنید. خالی = فقط Flag می‌شود</small>
            </div>
            <div style="margin-bottom:16px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="checkin_subnet_strict" id="s-strict" value="1" <?php if($strict==='1') echo 'checked' ?>>
                    <span style="font-size:.88rem">اجبار به شبکه دفتر — اتصال خارج از subnet = بلاک</span>
                </label>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
                <div>
                    <label class="fin-lbl">شروع ساعت کاری</label>
                    <input type="time" name="checkin_workday_start" id="s-start" class="fin-input" value="<?php echo getSetting($pdo,'checkin_workday_start','07:00') ?>">
                </div>
                <div>
                    <label class="fin-lbl">پایان ساعت کاری</label>
                    <input type="time" name="checkin_workday_end" id="s-end" class="fin-input" value="<?php echo getSetting($pdo,'checkin_workday_end','20:00') ?>">
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" onclick="closeSettings()" class="fin-btn">انصراف</button>
                <button type="submit" class="fin-btn fin-btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:14px;padding:28px;width:min(420px,94vw)">
        <h3 style="margin:0 0 16px;font-size:1rem;font-weight:700">ویرایش ساعت — <span id="editName"></span></h3>
        <input type="hidden" id="editId">
        <div style="margin-bottom:14px">
            <label class="fin-lbl">ساعت ورود</label>
            <input type="datetime-local" id="editIn" class="fin-input">
        </div>
        <div style="margin-bottom:14px">
            <label class="fin-lbl">ساعت خروج</label>
            <input type="datetime-local" id="editOut" class="fin-input">
        </div>
        <div style="margin-bottom:14px">
            <label class="fin-lbl">یادداشت ادمین</label>
            <textarea id="editNote" class="fin-input" rows="2" placeholder="دلیل ویرایش..."></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button onclick="closeEdit()" class="fin-btn">انصراف</button>
            <button onclick="submitEdit()" class="fin-btn fin-btn-primary">ذخیره</button>
        </div>
    </div>
</div>

<style>
.fin-input { width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-family:Vazirmatn,sans-serif;font-size:.88rem;background:#f9fafb;color:#111827;transition:border-color .2s;box-sizing:border-box }
.fin-input:focus { outline:none;border-color:#4f46e5;background:#fff }
.fin-btn { padding:8px 18px;border:1.5px solid #d1d5db;background:#fff;border-radius:8px;font-family:Vazirmatn,sans-serif;font-size:.85rem;cursor:pointer;font-weight:600;transition:all .2s }
.fin-btn-primary { background:#4f46e5;color:#fff;border-color:#4f46e5 }
.fin-lbl { font-size:.78rem;font-weight:600;color:#6b7280;display:block;margin-bottom:4px }
.day-btn { padding:7px 14px;border:1.5px solid #d1d5db;background:#fff;border-radius:8px;font-family:Vazirmatn,sans-serif;font-size:.8rem;cursor:pointer;font-weight:600;transition:all .2s }
.day-btn:hover { border-color:#4f46e5;color:#4f46e5 }
.badge { display:inline-block;padding:4px 12px;border-radius:20px;font-size:.76rem;font-weight:700 }
.badge-green { background:#d1fae5;color:#065f46 }
.badge-amber { background:#fef3c7;color:#92400e }
.badge-rose  { background:#ffe4e6;color:#9f1239 }
.badge-gray  { background:#f3f4f6;color:#374151 }
</style>

<script>
function loadList() {
    const date = document.getElementById('filterDate').value;
    document.getElementById('tableLoading').style.display = '';
    document.getElementById('tableContent').style.display = 'none';

    fetch(`attendance_checkin_manage.php?action=list&date=${date}`, {
        headers: {'X-Requested-With':'XMLHttpRequest'}
    })
    .then(r=>r.json())
    .then(res=>{
        document.getElementById('tableLoading').style.display = 'none';
        document.getElementById('tableContent').style.display = '';

        const tb = document.getElementById('checkinTableBody');
        if (!res.ok || !res.data.length) {
            tb.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8">رکوردی یافت نشد</td></tr>';
            updateStatBadges([]);
            return;
        }
        updateStatBadges(res.data);
        tb.innerHTML = res.data.map(r => {
            const inT  = r.check_in_time  ? r.check_in_time.substring(11,16)  : '—';
            const outT = r.check_out_time ? r.check_out_time.substring(11,16) : '—';
            const ipBadge   = r.ip_valid == 1
                ? '<span class="badge badge-green">✓ شبکه دفتر</span>'
                : (r.mock_flag == 1 ? '<span class="badge badge-amber">⚠ خارج</span>' : '<span class="badge badge-gray">—</span>');
            const statusMap = {present:'حاضر',partial:'ناقص',flagged:'مشکوک'};
            const statusCls = {present:'badge-green',partial:'badge-amber',flagged:'badge-rose'};
            return `<tr>
                <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;font-weight:600">${r.full_name||'—'}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;text-align:center;font-size:.78rem;color:#64748b">${r.dept_name||'—'}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;text-align:center;font-weight:600;color:${r.check_in_time?'#059669':'#94a3b8'}">${inT}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;text-align:center;font-weight:600;color:${r.check_out_time?'#dc2626':'#94a3b8'}">${outT}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;text-align:center;font-size:.82rem">${r.duration||'—'}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;text-align:center">${ipBadge}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;text-align:center">
                    <span class="badge ${statusCls[r.status]||'badge-gray'}">${statusMap[r.status]||r.status}</span>
                </td>
                <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;text-align:center">
                    <button class="fin-btn" style="font-size:.75rem;padding:4px 10px"
                        onclick="openEdit(${r.id},'${r.full_name}','${r.check_in_time||''}','${r.check_out_time||''}','${(r.admin_note||'').replace(/'/g,'')}')">
                        ویرایش
                    </button>
                </td>
            </tr>`;
        }).join('');
    });
}

function updateStatBadges(rows) {
    const present    = rows.filter(r => r.check_in_time).length;
    const full       = rows.filter(r => r.check_in_time && r.check_out_time).length;
    const flagged    = rows.filter(r => r.mock_flag == 1).length;
    const el = document.getElementById('statBadges');
    el.innerHTML = `
        <span class="badge badge-green">✓ حاضر: ${present}</span>
        <span class="badge badge-gray">کامل: ${full}</span>
        ${flagged ? `<span class="badge badge-rose">⚠ مشکوک: ${flagged}</span>` : ''}
    `;
}

function setDate(w) {
    const d = new Date();
    if (w === 'yesterday') d.setDate(d.getDate() - 1);
    document.getElementById('filterDate').value = d.toISOString().split('T')[0];
    loadList();
}

function showSettings() { document.getElementById('settingsModal').style.display = 'flex'; }
function closeSettings() { document.getElementById('settingsModal').style.display = 'none'; }
function saveSettings(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action','save_settings');
    fd.set('checkin_subnet_strict', document.getElementById('s-strict').checked ? '1' : '0');
    fetch('attendance_checkin_manage.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(res=>{ if(res.ok) { closeSettings(); alert(res.msg); } else alert(res.msg); });
}

function openEdit(id, name, inT, outT, note) {
    document.getElementById('editId').value = id;
    document.getElementById('editName').textContent = name;
    document.getElementById('editIn').value  = inT  ? inT.replace(' ','T').substring(0,16) : '';
    document.getElementById('editOut').value = outT ? outT.replace(' ','T').substring(0,16) : '';
    document.getElementById('editNote').value = note;
    document.getElementById('editModal').style.display = 'flex';
}
function closeEdit() { document.getElementById('editModal').style.display = 'none'; }
function submitEdit() {
    const fd = new FormData();
    fd.append('action','edit_time');
    fd.append('id',             document.getElementById('editId').value);
    fd.append('check_in_time',  document.getElementById('editIn').value.replace('T',' ') + ':00');
    fd.append('check_out_time', document.getElementById('editOut').value ? document.getElementById('editOut').value.replace('T',' ') + ':00' : '');
    fd.append('note',           document.getElementById('editNote').value);
    fetch('attendance_checkin_manage.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(res=>{ if(res.ok) { closeEdit(); loadList(); } else alert(res.msg); });
}

document.addEventListener('DOMContentLoaded', loadList);
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
