<?php
/*
 * فایل: public_html/admin/server_manager.php
 * داشبورد مدیریت سرور - استارت/استاپ/ریستارت/آپدیت گیت
 * فقط ادمین دسترسی دارد
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    die('<div style="text-align:center;padding:50px;font-family:Vazir,sans-serif;">شما مجوز دسترسی ندارید.</div>');
}

$pageTitle = 'مدیریت سرور';
$basePath  = '../';
$apps      = require __DIR__ . '/../../Config/server_apps.php';
$csrf      = csrf_token();

$extraCss = '
<style>
/* ===================== Server Manager Styles ===================== */
.sm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 20px;
    padding: 20px;
}
.sm-card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: box-shadow 0.2s;
}
.sm-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
.sm-card-header {
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid #f3f4f6;
}
.sm-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.sm-card-title { font-size: 1rem; font-weight: 700; color: #111827; margin-bottom: 3px; }
.sm-card-type  { font-size: 0.75rem; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; }
.sm-status-badge {
    margin-right: auto;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 600;
    display: flex; align-items: center; gap: 5px;
}
.sm-status-badge::before {
    content: "";
    width: 8px; height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.status-running  { background: #d1fae5; color: #065f46; }
.status-running::before  { background: #10b981; animation: pulse 1.5s infinite; }
.status-stopped  { background: #fee2e2; color: #991b1b; }
.status-stopped::before  { background: #ef4444; }
.status-starting { background: #fef3c7; color: #92400e; }
.status-starting::before { background: #f59e0b; animation: pulse 0.8s infinite; }
.status-unknown  { background: #f3f4f6; color: #6b7280; }
.status-unknown::before  { background: #9ca3af; }

@keyframes pulse {
    0%,100% { opacity: 1; }
    50%      { opacity: 0.4; }
}

.sm-info-text {
    padding: 8px 20px;
    font-size: 0.78rem;
    color: #6b7280;
    min-height: 28px;
    border-bottom: 1px solid #f3f4f6;
    direction: ltr;
    text-align: right;
}
.sm-actions {
    padding: 12px 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.sm-btn {
    padding: 7px 14px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-family: inherit;
    font-size: 0.82rem;
    font-weight: 600;
    display: flex; align-items: center; gap: 5px;
    transition: all 0.15s;
    flex: 1 1 auto;
    justify-content: center;
    min-width: 70px;
}
.sm-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.sm-btn:active { transform: scale(0.97); }
.btn-start   { background: #d1fae5; color: #065f46; }
.btn-start:hover   { background: #6ee7b7; }
.btn-stop    { background: #fee2e2; color: #991b1b; }
.btn-stop:hover    { background: #fca5a5; }
.btn-restart { background: #fef3c7; color: #92400e; }
.btn-restart:hover { background: #fcd34d; }
.btn-git     { background: #ede9fe; color: #5b21b6; }
.btn-git:hover     { background: #c4b5fd; }
.btn-logs    { background: #f0f9ff; color: #0369a1; }
.btn-logs:hover    { background: #bae6fd; }
.btn-loading { position: relative; pointer-events: none; }
.btn-loading::after {
    content: "";
    width: 14px; height: 14px;
    border: 2px solid currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    display: inline-block;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Stats bar */
.sm-stats-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 0;
}
.sm-stat-item {
    background: #fff;
    border-radius: 12px;
    padding: 14px 16px;
    border: 1px solid #e5e7eb;
    display: flex; align-items: center; gap: 12px;
}
.sm-stat-icon { font-size: 1.5rem; }
.sm-stat-label { font-size: 0.75rem; color: #6b7280; margin-bottom: 2px; }
.sm-stat-value { font-size: 1rem; font-weight: 700; color: #111827; }
.sm-page-header {
    padding: 20px;
    border-bottom: 1px solid #e5e7eb;
    background: #fff;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
}
.sm-page-header h1 { font-size: 1.2rem; font-weight: 800; color: #111827; margin: 0; }
.sm-refresh-btn {
    padding: 8px 16px; border-radius: 8px; border: 1px solid #e5e7eb;
    background: #fff; cursor: pointer; font-family: inherit; font-size: 0.85rem;
    color: #4b5563; display: flex; align-items: center; gap: 6px;
    transition: background 0.15s;
}
.sm-refresh-btn:hover { background: #f3f4f6; }

/* لاگ مودال */
.sm-log-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.5); z-index: 9000;
    align-items: center; justify-content: center;
}
.sm-log-overlay.active { display: flex; }
.sm-log-modal {
    background: #1e1e2e;
    border-radius: 16px;
    width: 90vw; max-width: 760px;
    max-height: 80vh;
    display: flex; flex-direction: column;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
}
.sm-log-header {
    padding: 14px 20px;
    background: #2d2d3f;
    display: flex; align-items: center; justify-content: space-between;
    color: #e2e8f0;
    font-weight: 700;
    font-size: 0.9rem;
}
.sm-log-body {
    padding: 16px;
    overflow-y: auto;
    flex: 1;
    font-family: "Courier New", monospace;
    font-size: 0.78rem;
    line-height: 1.6;
    color: #a6e3a1;
    white-space: pre-wrap;
    word-break: break-all;
    direction: ltr;
    text-align: left;
}
.sm-log-close {
    background: none; border: none; color: #94a3b8;
    font-size: 1.2rem; cursor: pointer; padding: 4px 8px;
    transition: color 0.15s;
}
.sm-log-close:hover { color: #f87171; }
.sm-toast {
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
    background: #1f2937; color: #fff; padding: 12px 24px; border-radius: 10px;
    font-size: 0.9rem; z-index: 9999; opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
    max-width: 90vw; text-align: center;
}
.sm-toast.show { opacity: 1; }
.sm-toast.success { background: #065f46; }
.sm-toast.error   { background: #991b1b; }

.sm-git-path {
    padding: 6px 20px 10px;
    font-size: 0.75rem; color: #9ca3af;
    direction: ltr; text-align: right;
}
.sm-empty {
    padding: 60px 20px; text-align: center; color: #9ca3af;
    font-size: 1rem;
}

@media (max-width: 600px) {
    .sm-grid { padding: 12px; gap: 12px; }
    .sm-actions { gap: 6px; }
    .sm-btn { font-size: 0.78rem; padding: 6px 10px; }
}
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<!-- Toast -->
<div class="sm-toast" id="smToast"></div>

<!-- Log Modal -->
<div class="sm-log-overlay" id="smLogOverlay" onclick="smCloseLog(event)">
    <div class="sm-log-modal">
        <div class="sm-log-header">
            <span id="smLogTitle">لاگ</span>
            <div style="display:flex;gap:10px;align-items:center;">
                <select id="smLogLines" onchange="smReloadLog()" style="background:#2d2d3f;border:1px solid #4a4a6a;color:#e2e8f0;border-radius:6px;padding:4px 8px;font-size:0.8rem;cursor:pointer;">
                    <option value="30">۳۰ خط</option>
                    <option value="50" selected>۵۰ خط</option>
                    <option value="100">۱۰۰ خط</option>
                    <option value="200">۲۰۰ خط</option>
                </select>
                <button class="sm-log-close" onclick="smCloseLog()">✕</button>
            </div>
        </div>
        <div class="sm-log-body" id="smLogBody">در حال دریافت لاگ...</div>
    </div>
</div>

<div class="main-content" style="padding:0;">

    <!-- هدر صفحه -->
    <div class="sm-page-header">
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:1.6rem;">🖥️</span>
            <div>
                <h1>مدیریت سرور</h1>
                <div style="font-size:0.78rem;color:#6b7280;margin-top:2px;">
                    کنترل اپ‌ها و سرویس‌های سرور از یک جا
                </div>
            </div>
        </div>
        <button class="sm-refresh-btn" onclick="smRefreshAll(this)">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
            بروزرسانی وضعیت
        </button>
    </div>

    <!-- آمار سرور -->
    <div class="sm-stats-bar" id="smServerStats">
        <div class="sm-stat-item">
            <div class="sm-stat-icon">💾</div>
            <div>
                <div class="sm-stat-label">RAM</div>
                <div class="sm-stat-value" id="statRam">...</div>
            </div>
        </div>
        <div class="sm-stat-item">
            <div class="sm-stat-icon">⚡</div>
            <div>
                <div class="sm-stat-label">CPU Load</div>
                <div class="sm-stat-value" id="statCpu">...</div>
            </div>
        </div>
        <div class="sm-stat-item">
            <div class="sm-stat-icon">💿</div>
            <div>
                <div class="sm-stat-label">دیسک</div>
                <div class="sm-stat-value" id="statDisk">...</div>
            </div>
        </div>
        <div class="sm-stat-item">
            <div class="sm-stat-icon">⏱️</div>
            <div>
                <div class="sm-stat-label">آپتایم سرور</div>
                <div class="sm-stat-value" id="statUptime">...</div>
            </div>
        </div>
        <div class="sm-stat-item">
            <div class="sm-stat-icon">🟢</div>
            <div>
                <div class="sm-stat-label">اپ‌های فعال</div>
                <div class="sm-stat-value" id="statRunning">—</div>
            </div>
        </div>
    </div>

    <?php if (empty($apps)): ?>
        <div class="sm-empty">
            <div style="font-size:3rem;margin-bottom:12px;">📭</div>
            <div>هیچ اپی تعریف نشده است.</div>
            <div style="font-size:0.85rem;margin-top:8px;">فایل <code>Config/server_apps.php</code> را ویرایش کنید و اپ‌هایتان را اضافه کنید.</div>
        </div>
    <?php else: ?>
    <!-- کارت‌های اپ -->
    <div class="sm-grid" id="smAppGrid">
        <?php foreach ($apps as $app): ?>
        <?php
            $typeLabels = ['pm2' => 'PM2', 'systemd' => 'Systemd', 'docker' => 'Docker', 'script' => 'Script'];
            $typeLabel  = $typeLabels[$app['type']] ?? strtoupper($app['type']);
            $color      = $app['color'] ?? '#6366f1';
            $icon       = $app['icon'] ?? '⚙️';
            $hasGit     = !empty($app['path']) && is_dir(($app['path'] ?? '') . '/.git');
        ?>
        <div class="sm-card" id="card-<?= htmlspecialchars($app['id']) ?>">
            <div class="sm-card-header">
                <div class="sm-icon" style="background:<?= htmlspecialchars($color) ?>22;">
                    <?= htmlspecialchars($icon) ?>
                </div>
                <div>
                    <div class="sm-card-title"><?= htmlspecialchars($app['name']) ?></div>
                    <div class="sm-card-type"><?= $typeLabel ?><?php if(!empty($app['pm2_name'])): ?> · <?= htmlspecialchars($app['pm2_name']) ?><?php elseif(!empty($app['service'])): ?> · <?= htmlspecialchars($app['service']) ?><?php elseif(!empty($app['container'])): ?> · <?= htmlspecialchars($app['container']) ?><?php endif; ?></div>
                </div>
                <span class="sm-status-badge status-unknown" id="badge-<?= htmlspecialchars($app['id']) ?>">نامشخص</span>
            </div>

            <div class="sm-info-text" id="info-<?= htmlspecialchars($app['id']) ?>">در حال بررسی وضعیت...</div>

            <?php if (!empty($app['path'])): ?>
            <div class="sm-git-path">
                📁 <?= htmlspecialchars($app['path']) ?>
                <?php if (!empty($app['git'])): ?>
                  · <a href="<?= htmlspecialchars($app['git']) ?>" target="_blank" rel="noopener" style="color:#6366f1;">🔗 مخزن</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="sm-actions">
                <button class="sm-btn btn-start"
                        onclick="smAction('<?= htmlspecialchars($app['id']) ?>', 'start', this)"
                        title="شروع">
                    ▶ شروع
                </button>
                <button class="sm-btn btn-stop"
                        onclick="smAction('<?= htmlspecialchars($app['id']) ?>', 'stop', this)"
                        title="توقف">
                    ■ توقف
                </button>
                <button class="sm-btn btn-restart"
                        onclick="smAction('<?= htmlspecialchars($app['id']) ?>', 'restart', this)"
                        title="ریستارت">
                    ↺ ریستارت
                </button>
                <button class="sm-btn btn-git"
                        onclick="smAction('<?= htmlspecialchars($app['id']) ?>', 'git_pull', this)"
                        title="آپدیت از گیت"
                        <?= (empty($app['path'])) ? 'disabled' : '' ?>>
                    ↓ Git Pull
                </button>
                <button class="sm-btn btn-logs"
                        onclick="smShowLog('<?= htmlspecialchars($app['id']) ?>', '<?= htmlspecialchars(addslashes($app['name'])) ?>')"
                        title="مشاهده لاگ">
                    📋 لاگ
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="padding:16px 20px;font-size:0.78rem;color:#9ca3af;text-align:center;">
        برای آپدیت خودکار سرویس‌ها هنگام بوت سرور، از <code>systemctl enable</code> یا <code>pm2 startup && pm2 save</code> استفاده کنید.
        <br>
        تنظیمات اپ‌ها: <code>Config/server_apps.php</code>
    </div>
</div>

<script>
const SM_CSRF   = <?= json_encode($csrf) ?>;
const SM_AJAX   = '<?= $basePath ?>admin/ajax/server_action.php';

let smCurrentLogApp  = null;
let smCurrentLogName = null;

// ===================== اکشن اصلی =====================
async function smAction(appId, action, btn) {
    const label = btn ? btn.textContent.trim() : action;
    if (btn) {
        btn.classList.add('btn-loading');
        btn.disabled = true;
        btn.dataset.origText = btn.innerHTML;
        btn.innerHTML = '';
    }

    try {
        const fd = new FormData();
        fd.append('csrf',   SM_CSRF);
        fd.append('action', action);
        fd.append('app_id', appId);

        const res  = await fetch(SM_AJAX, { method: 'POST', body: fd });
        const data = await res.json();

        smToast(data.msg || (data.ok ? 'انجام شد' : 'خطا'), data.ok ? 'success' : 'error');

        // بروزرسانی وضعیت کارت بعد از اکشن
        if (['start','stop','restart'].includes(action)) {
            setTimeout(() => smUpdateCard(appId), 1500);
        }

    } catch (e) {
        smToast('خطا در ارتباط با سرور', 'error');
    } finally {
        if (btn) {
            btn.classList.remove('btn-loading');
            btn.disabled = false;
            btn.innerHTML = btn.dataset.origText || label;
        }
    }
}

// ===================== وضعیت یک کارت =====================
async function smUpdateCard(appId) {
    try {
        const fd = new FormData();
        fd.append('csrf',   SM_CSRF);
        fd.append('action', 'status');
        fd.append('app_id', appId);

        const res  = await fetch(SM_AJAX, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok && data.status) {
            applyCardStatus(appId, data.status.status, data.status.info);
        }
    } catch (e) {}
}

// ===================== همه وضعیت‌ها =====================
async function smRefreshAll(btn) {
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.6';
    }
    try {
        const url  = SM_AJAX + '?action=status_all&csrf=' + encodeURIComponent(SM_CSRF);
        const res  = await fetch(url);
        const data = await res.json();

        if (data.ok && data.statuses) {
            let running = 0;
            for (const [id, st] of Object.entries(data.statuses)) {
                applyCardStatus(id, st.status, st.info);
                if (st.status === 'running') running++;
            }
            document.getElementById('statRunning').textContent = running + ' / <?= count($apps) ?>';
        }
    } catch (e) {
        smToast('خطا در بروزرسانی وضعیت', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
    }

    smLoadStats();
}

function applyCardStatus(appId, status, info) {
    const badge = document.getElementById('badge-' + appId);
    const infoEl = document.getElementById('info-' + appId);

    if (!badge) return;

    badge.className = 'sm-status-badge status-' + status;
    const labels = { running: 'در حال اجرا', stopped: 'متوقف', starting: 'در حال شروع', unknown: 'نامشخص' };
    badge.textContent = labels[status] || status;

    if (infoEl) {
        infoEl.textContent = info || (status === 'running' ? 'سرویس فعال است' : status === 'stopped' ? 'سرویس متوقف است' : '...');
    }
}

// ===================== لاگ =====================
function smShowLog(appId, appName) {
    smCurrentLogApp  = appId;
    smCurrentLogName = appName;
    document.getElementById('smLogTitle').textContent = 'لاگ: ' + appName;
    document.getElementById('smLogBody').textContent  = 'در حال دریافت...';
    document.getElementById('smLogOverlay').classList.add('active');
    smLoadLog();
}
async function smLoadLog() {
    if (!smCurrentLogApp) return;
    const lines = document.getElementById('smLogLines').value;
    try {
        const url  = SM_AJAX + '?action=logs&app_id=' + encodeURIComponent(smCurrentLogApp) + '&lines=' + lines + '&csrf=' + encodeURIComponent(SM_CSRF);
        const res  = await fetch(url);
        const data = await res.json();
        const body = document.getElementById('smLogBody');
        body.textContent = data.logs || '(خالی)';
        body.scrollTop   = body.scrollHeight;
    } catch (e) {
        document.getElementById('smLogBody').textContent = 'خطا در دریافت لاگ.';
    }
}
function smReloadLog() { smLoadLog(); }
function smCloseLog(e) {
    if (e && e.target !== document.getElementById('smLogOverlay')) return;
    document.getElementById('smLogOverlay').classList.remove('active');
}

// ===================== آمار سرور =====================
async function smLoadStats() {
    try {
        // RAM
        const ramUrl  = SM_AJAX + '?action=status_all&csrf=' + encodeURIComponent(SM_CSRF);

        // استفاده از دستورات shell برای آمار از طریق PHP
        const statsUrl = SM_AJAX + '?action=server_stats&csrf=' + encodeURIComponent(SM_CSRF);
        const res = await fetch(statsUrl);
        const data = await res.json();
        if (data.ok) {
            if (data.ram)    document.getElementById('statRam').textContent    = data.ram;
            if (data.cpu)    document.getElementById('statCpu').textContent    = data.cpu;
            if (data.disk)   document.getElementById('statDisk').textContent   = data.disk;
            if (data.uptime) document.getElementById('statUptime').textContent = data.uptime;
        }
    } catch (e) {}
}

// ===================== Toast =====================
let smToastTimer;
function smToast(msg, type = 'info') {
    const el = document.getElementById('smToast');
    el.textContent = msg;
    el.className   = 'sm-toast show ' + (type || '');
    clearTimeout(smToastTimer);
    smToastTimer = setTimeout(() => el.classList.remove('show'), 3500);
}

// ===================== راه‌اندازی =====================
document.addEventListener('DOMContentLoaded', () => {
    smRefreshAll(null);
    // بروزرسانی خودکار هر ۳۰ ثانیه
    setInterval(() => smRefreshAll(null), 30000);
});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
