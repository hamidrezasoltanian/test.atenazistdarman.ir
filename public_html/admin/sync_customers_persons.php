<?php
/*
 * sync_customers_persons.php
 * همگام‌سازی خودکار جدول customers ↔ fin_persons
 * بر اساس شماره موبایل + ایجاد طرف حساب برای مشتریان جدید
 */

session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

requireLogin();
$isAdmin = ($_SESSION['role'] ?? '') === 'admin' || ($_SESSION['is_admin'] ?? 0) == 1;
if (!$isAdmin) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['error' => 'دسترسی ندارید']); exit; }
    header('Location: ../../index.php'); exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ── تابع تولید کد طرف حساب ────────────────────────────────────────
function generatePersonCode(PDO $pdo): string {
    $row  = $pdo->query("SELECT MAX(CAST(SUBSTRING(code,2) AS UNSIGNED)) AS mx FROM fin_persons WHERE code REGEXP '^P[0-9]+'")
                ->fetch(PDO::FETCH_ASSOC);
    $next = (int)($row['mx'] ?? 0) + 1;
    return 'P' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

// ── AJAX: آمار وضعیت فعلی ─────────────────────────────────────────
if ($isAjax && $action === 'stats') {
    header('Content-Type: application/json; charset=utf-8');

    $total   = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $linked  = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE person_id IS NOT NULL")->fetchColumn();
    $unlinked= $total - $linked;

    // تعداد مشتریانی که موبایل آن‌ها با یک fin_person تطابق دارد ولی هنوز لینک نشده‌اند
    $potential = (int)$pdo->query(
        "SELECT COUNT(*) FROM customers c
         JOIN fin_persons fp ON REPLACE(fp.mobile,' ','')=REPLACE(c.mobile,' ','')
         WHERE c.mobile IS NOT NULL AND c.mobile!=''
           AND fp.mobile IS NOT NULL AND fp.mobile!=''
           AND fp.is_deleted=0
           AND (c.person_id IS NULL OR c.person_id != fp.id)"
    )->fetchColumn();

    $orphanPersons = (int)$pdo->query(
        "SELECT COUNT(*) FROM fin_persons WHERE customer_id IS NULL AND type='customer' AND is_deleted=0"
    )->fetchColumn();

    echo json_encode(compact('total','linked','unlinked','potential','orphanPersons'), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: تطابق خودکار بر اساس موبایل ────────────────────────────
if ($isAjax && $action === 'auto_match') {
    header('Content-Type: application/json; charset=utf-8');

    // پیدا کردن جفت‌های تطابق
    $matches = $pdo->query(
        "SELECT c.id AS cid, fp.id AS fpid
         FROM customers c
         JOIN fin_persons fp ON REPLACE(fp.mobile,' ','')=REPLACE(c.mobile,' ','')
         WHERE c.mobile IS NOT NULL AND c.mobile!=''
           AND fp.mobile IS NOT NULL AND fp.mobile!=''
           AND fp.is_deleted=0
           AND c.person_id IS NULL"
    )->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    $updC  = $pdo->prepare("UPDATE customers SET person_id=? WHERE id=?");
    $updFP = $pdo->prepare("UPDATE fin_persons SET customer_id=? WHERE id=?");

    foreach ($matches as $m) {
        $updC->execute([$m['fpid'], $m['cid']]);
        $updFP->execute([$m['cid'], $m['fpid']]);
        $count++;
    }

    echo json_encode(['ok' => true, 'matched' => $count], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: ایجاد fin_person برای مشتریان بدون اتصال ───────────────
if ($isAjax && $action === 'create_missing') {
    header('Content-Type: application/json; charset=utf-8');

    $unlinked = $pdo->query(
        "SELECT * FROM customers WHERE person_id IS NULL ORDER BY id LIMIT 500"
    )->fetchAll(PDO::FETCH_ASSOC);

    $created = 0;
    $ins = $pdo->prepare(
        "INSERT INTO fin_persons (code, name, company_name, type, mobile, tel, state, city, address, customer_id, created_at)
         VALUES (?, ?, ?, 'customer', ?, ?, ?, ?, ?, ?, NOW())"
    );
    $updC = $pdo->prepare("UPDATE customers SET person_id=? WHERE id=?");

    foreach ($unlinked as $c) {
        $code = generatePersonCode($pdo);
        $name = $c['company_name'] ?? ('مشتری ' . ($c['company_code'] ?? $c['id']));
        $ins->execute([
            $code, $name, $c['company_name'] ?? null,
            $c['mobile'] ?? null, $c['phone'] ?? null,
            $c['state'] ?? null, $c['city'] ?? null,
            $c['address'] ?? null, $c['id'],
        ]);
        $newId = (int)$pdo->lastInsertId();
        $updC->execute([$newId, $c['id']]);
        $created++;
    }

    echo json_encode(['ok' => true, 'created' => $created], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: لینک دستی یک مشتری به یک fin_person ────────────────────
if ($isAjax && $action === 'manual_link') {
    header('Content-Type: application/json; charset=utf-8');
    $cid  = (int)($_POST['customer_id'] ?? 0);
    $fpid = (int)($_POST['person_id']   ?? 0);
    if (!$cid || !$fpid) { echo json_encode(['error' => 'پارامتر ناقص']); exit; }
    $pdo->prepare("UPDATE customers   SET person_id=?   WHERE id=?")->execute([$fpid, $cid]);
    $pdo->prepare("UPDATE fin_persons SET customer_id=? WHERE id=?")->execute([$cid, $fpid]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: لیست مشتریان بدون اتصال ────────────────────────────────
if ($isAjax && $action === 'unlinked_list') {
    header('Content-Type: application/json; charset=utf-8');
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset= ($page - 1) * $limit;

    $total = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE person_id IS NULL")->fetchColumn();
    $rows  = $pdo->query(
        "SELECT id, company_code, company_name, type_name, mobile, state, city
         FROM customers WHERE person_id IS NULL ORDER BY id DESC LIMIT $limit OFFSET $offset"
    )->fetchAll(PDO::FETCH_ASSOC);

    // برای هر کدام، بررسی تطابق احتمالی در fin_persons
    foreach ($rows as &$r) {
        $r['potential_person'] = null;
        if ($r['mobile']) {
            $match = $pdo->prepare(
                "SELECT id, code, name FROM fin_persons
                 WHERE REPLACE(mobile,' ','')=? AND is_deleted=0 AND customer_id IS NULL LIMIT 1"
            );
            $match->execute([str_replace(' ', '', $r['mobile'])]);
            $r['potential_person'] = $match->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    unset($r);

    echo json_encode(['total' => $total, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: جستجوی fin_persons برای لینک دستی ──────────────────────
if ($isAjax && $action === 'search_persons') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode([]); exit; }
    $like = "%$q%";
    $stmt = $pdo->prepare(
        "SELECT id, code, name, mobile FROM fin_persons
         WHERE is_deleted=0 AND (name LIKE ? OR code LIKE ? OR mobile LIKE ?) LIMIT 20"
    );
    $stmt->execute([$like, $like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── صفحه وب ──────────────────────────────────────────────────────
$pageTitle = 'همگام‌سازی مشتریان ↔ طرف حساب‌ها';
$basePath  = '../';
require_once $root . '/templates/header.php';
require_once $root . '/templates/sidebar.php';
?>

<style>
.sync-page { padding: 24px; max-width: 1100px; }

.sync-stat-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(180px,1fr)); gap: 14px; margin-bottom: 28px; }
.sync-stat { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px; display:flex; align-items:center; gap:14px; }
.sync-stat-icon { width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0; }
.sync-stat-val  { font-size:1.5rem;font-weight:800;color:#0f172a;line-height:1; }
.sync-stat-lbl  { font-size:.75rem;color:#64748b;margin-top:3px; }
.icon-blue  { background:#eff6ff; }
.icon-green { background:#f0fdf4; }
.icon-amber { background:#fffbeb; }
.icon-rose  { background:#fff1f2; }
.icon-purple{ background:#faf5ff; }

.sync-actions { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:24px; }
.sync-btn {
    display:inline-flex;align-items:center;gap:8px;
    padding:10px 20px;border-radius:11px;font-family:Vazirmatn,sans-serif;
    font-size:.875rem;font-weight:600;cursor:pointer;border:none;
    transition:all .15s;
}
.sync-btn-primary { background:#3b82f6;color:#fff; }
.sync-btn-primary:hover { background:#2563eb; }
.sync-btn-success { background:#10b981;color:#fff; }
.sync-btn-success:hover { background:#059669; }
.sync-btn-secondary { background:#fff;color:#475569;border:1.5px solid #e2e8f0; }
.sync-btn-secondary:hover { border-color:#3b82f6;color:#2563eb;background:#eff6ff; }
.sync-btn:disabled { opacity:.5;cursor:not-allowed; }

.progress-bar-wrap { background:#f1f5f9;border-radius:999px;height:8px;overflow:hidden;margin:8px 0; }
.progress-bar-fill { height:100%;border-radius:999px;background:linear-gradient(90deg,#3b82f6,#06b6d4);transition:width .5s; }

.fin-panel { background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:20px; }
.fin-panel-head { padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:10px; }
.fin-panel-head h3 { font-size:.92rem;font-weight:700;color:#0f172a;margin:0; }

table.sync-tbl { width:100%;border-collapse:collapse; }
table.sync-tbl th { background:#f8fafc;color:#475569;font-size:.77rem;font-weight:700;padding:10px 14px;text-align:right;border-bottom:1px solid #e2e8f0; }
table.sync-tbl td { padding:10px 14px;font-size:.82rem;color:#334155;border-bottom:1px solid #f1f5f9;vertical-align:middle; }
table.sync-tbl tr:last-child td { border-bottom:none; }
table.sync-tbl tr:hover td { background:#f8fafc; }

.badge-link { display:inline-flex;align-items:center;padding:2px 10px;border-radius:999px;font-size:.72rem;font-weight:600; }
.badge-link.linked   { background:#f0fdf4;color:#16a34a; }
.badge-link.unlinked { background:#fff1f2;color:#e11d48; }
.badge-link.partial  { background:#fffbeb;color:#d97706; }

.match-chip { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;background:#eff6ff;border:1px solid #bfdbfe;font-size:.75rem;color:#1d4ed8; }

.search-persons-wrap { position:relative; }
.search-persons-wrap input { width:100%;padding:7px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:Vazirmatn,sans-serif;font-size:.82rem;box-sizing:border-box; }
.search-persons-wrap input:focus { outline:none;border-color:#3b82f6; }
.persons-dropdown { position:absolute;top:100%;right:0;left:0;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:100;display:none;max-height:200px;overflow-y:auto; }
.persons-dropdown.open { display:block; }
.persons-dd-item { padding:8px 12px;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center; }
.persons-dd-item:hover { background:#f8fafc; }
.persons-dd-item:last-child { border-bottom:none; }

.toast {
    position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
    padding:12px 22px;border-radius:12px;font-size:.87rem;font-weight:600;
    box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:9999;
    animation:toastIn .25s ease;display:none;
}
.toast.success { background:#10b981;color:#fff; }
.toast.error   { background:#ef4444;color:#fff; }
@keyframes toastIn { from{opacity:0;transform:translateX(-50%) translateY(10px)} to{opacity:1;transform:translateX(-50%) translateY(0)} }

.log-box { background:#0f172a;color:#e2e8f0;border-radius:10px;padding:14px;font-size:.78rem;line-height:1.8;max-height:200px;overflow-y:auto;margin-top:12px;direction:ltr;text-align:left;display:none; }
</style>

<div class="content-wrapper sync-page">

    <!-- هدر -->
    <div style="margin-bottom:24px;">
        <h1 style="font-size:1.15rem;font-weight:800;color:#0f172a;margin:0 0 4px;">🔗 همگام‌سازی مشتریان ↔ طرف حساب‌ها</h1>
        <p style="font-size:.82rem;color:#64748b;margin:0;">
            اتصال خودکار جدول <code>customers</code> به <code>fin_persons</code> بر اساس شماره موبایل
        </p>
    </div>

    <!-- کارت‌های آمار -->
    <div class="sync-stat-grid" id="statGrid">
        <div class="sync-stat"><div class="sync-stat-icon icon-blue">👥</div><div><div class="sync-stat-val" id="s-total">—</div><div class="sync-stat-lbl">کل مشتریان</div></div></div>
        <div class="sync-stat"><div class="sync-stat-icon icon-green">✅</div><div><div class="sync-stat-val" id="s-linked">—</div><div class="sync-stat-lbl">لینک‌شده</div></div></div>
        <div class="sync-stat"><div class="sync-stat-icon icon-rose">❗</div><div><div class="sync-stat-val" id="s-unlinked">—</div><div class="sync-stat-lbl">بدون اتصال</div></div></div>
        <div class="sync-stat"><div class="sync-stat-icon icon-amber">🔍</div><div><div class="sync-stat-val" id="s-potential">—</div><div class="sync-stat-lbl">تطابق احتمالی</div></div></div>
        <div class="sync-stat"><div class="sync-stat-icon icon-purple">🧩</div><div><div class="sync-stat-val" id="s-orphan">—</div><div class="sync-stat-lbl">طرف حساب بدون مشتری</div></div></div>
    </div>

    <!-- نوار پیشرفت لینک‌شده -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin-bottom:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <span style="font-size:.82rem;font-weight:600;color:#0f172a;">درصد مشتریان لینک‌شده</span>
            <span id="linkPct" style="font-size:.82rem;color:#3b82f6;font-weight:700;">—</span>
        </div>
        <div class="progress-bar-wrap"><div class="progress-bar-fill" id="linkBar" style="width:0%"></div></div>
    </div>

    <!-- دکمه‌های عملیات -->
    <div class="sync-actions">
        <button class="sync-btn sync-btn-primary" onclick="runAutoMatch()">
            🔄 تطابق خودکار بر اساس موبایل
        </button>
        <button class="sync-btn sync-btn-success" onclick="runCreateMissing()">
            ➕ ایجاد طرف حساب برای مشتریان بدون اتصال
        </button>
        <button class="sync-btn sync-btn-secondary" onclick="refreshStats()">
            ↻ به‌روزرسانی آمار
        </button>
    </div>

    <!-- لاگ عملیات -->
    <div class="log-box" id="logBox"></div>

    <!-- جدول مشتریان بدون اتصال -->
    <div class="fin-panel">
        <div class="fin-panel-head">
            <h3>مشتریان بدون طرف حساب</h3>
            <span id="unlinkedCount" style="font-size:.8rem;color:#64748b;">در حال بارگذاری...</span>
        </div>
        <div style="overflow-x:auto;">
            <table class="sync-tbl">
                <thead>
                    <tr>
                        <th>کد</th>
                        <th>نام مشتری</th>
                        <th>نوع</th>
                        <th>موبایل</th>
                        <th>استان / شهر</th>
                        <th>تطابق پیشنهادی</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody id="unlinkedTbody">
                    <tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">در حال بارگذاری...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="unlinkedPagination" style="padding:12px 18px;border-top:1px solid #f1f5f9;display:none;"></div>
    </div>

</div>

<!-- مودال لینک دستی -->
<div id="manualModal" style="position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:20px;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:440px;box-shadow:0 25px 60px rgba(15,23,42,.25);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #f1f5f9;">
            <h3 style="font-size:.95rem;font-weight:700;color:#0f172a;margin:0;">لینک دستی به طرف حساب</h3>
            <button onclick="closeManualModal()" style="width:30px;height:30px;border-radius:8px;border:none;background:#f1f5f9;cursor:pointer;font-size:1rem;">✕</button>
        </div>
        <div style="padding:20px;">
            <p style="font-size:.83rem;color:#64748b;margin:0 0 14px;">
                مشتری: <strong id="mm-customer-name" style="color:#0f172a;"></strong>
            </p>
            <div class="search-persons-wrap">
                <input type="text" id="mm-search" placeholder="جستجو در طرف حساب‌ها..." autocomplete="off"
                       oninput="searchPersons(this.value)">
                <div class="persons-dropdown" id="personsDropdown"></div>
            </div>
            <div id="mm-selected" style="margin-top:12px;display:none;">
                <div style="padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;font-size:.83rem;color:#16a34a;display:flex;align-items:center;gap:8px;">
                    ✅ انتخاب‌شده: <strong id="mm-selected-name"></strong>
                    <button onclick="clearPersonSelection()" style="margin-right:auto;background:none;border:none;color:#ef4444;cursor:pointer;font-size:.8rem;">✕</button>
                </div>
            </div>
            <div style="margin-top:18px;display:flex;gap:10px;">
                <button onclick="confirmManualLink()" class="sync-btn sync-btn-primary" style="flex:1;" id="mmConfirmBtn" disabled>ذخیره اتصال</button>
                <button onclick="closeManualModal()" class="sync-btn sync-btn-secondary">انصراف</button>
            </div>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
(function() {
'use strict';

let currentCustomerId = null;
let selectedPersonId  = null;
let selectedPersonName= null;
let unlinkedPage = 1;

// ── آمار ─────────────────────────────────────────────────────────
function refreshStats() {
    fetch('sync_customers_persons.php?action=stats', {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r => r.json())
        .then(d => {
            document.getElementById('s-total').textContent    = d.total.toLocaleString('fa-IR');
            document.getElementById('s-linked').textContent   = d.linked.toLocaleString('fa-IR');
            document.getElementById('s-unlinked').textContent = d.unlinked.toLocaleString('fa-IR');
            document.getElementById('s-potential').textContent= d.potential.toLocaleString('fa-IR');
            document.getElementById('s-orphan').textContent   = d.orphanPersons.toLocaleString('fa-IR');
            const pct = d.total > 0 ? Math.round(d.linked / d.total * 100) : 0;
            document.getElementById('linkPct').textContent = pct + '٪';
            document.getElementById('linkBar').style.width  = pct + '%';
        });
}

// ── لیست مشتریان بدون اتصال ──────────────────────────────────────
function loadUnlinked(page) {
    unlinkedPage = page || 1;
    fetch(`sync_customers_persons.php?action=unlinked_list&page=${unlinkedPage}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r => r.json())
        .then(d => {
            document.getElementById('unlinkedCount').textContent = d.total.toLocaleString('fa-IR') + ' مشتری';
            const tbody = document.getElementById('unlinkedTbody');
            if (!d.rows.length) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#10b981;font-weight:600;">✅ همه مشتریان لینک شده‌اند!</td></tr>';
                document.getElementById('unlinkedPagination').style.display = 'none';
                return;
            }
            tbody.innerHTML = d.rows.map(r => {
                const pp = r.potential_person;
                const matchHtml = pp
                    ? `<span class="match-chip" title="کد: ${esc(pp.code)}">🔍 ${esc(pp.name)}</span>
                       <button onclick="quickLink(${r.id},${pp.id})" style="margin-right:6px;padding:2px 8px;border-radius:6px;border:1px solid #bbf7d0;background:#f0fdf4;color:#16a34a;cursor:pointer;font-size:.72rem;font-family:Vazirmatn,sans-serif;">اتصال سریع</button>`
                    : '<span style="color:#cbd5e1;font-size:.75rem;">—</span>';
                return `<tr>
                    <td><span style="font-size:.75rem;color:#94a3b8;">${esc(r.company_code||'')}</span></td>
                    <td style="font-weight:600;color:#0f172a;">${esc(r.company_name||'')}</td>
                    <td><span class="badge-link unlinked">${esc(r.type_name||'—')}</span></td>
                    <td dir="ltr" style="text-align:right;">${esc(r.mobile||'—')}</td>
                    <td style="font-size:.78rem;color:#64748b;">${esc([r.state,r.city].filter(Boolean).join(' / ')||'—')}</td>
                    <td>${matchHtml}</td>
                    <td>
                        <button onclick="openManualLink(${r.id},'${esc(r.company_name||'')}')"
                            style="padding:4px 12px;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:.75rem;cursor:pointer;font-family:Vazirmatn,sans-serif;">
                            🔗 لینک دستی
                        </button>
                    </td>
                </tr>`;
            }).join('');

            // pagination
            const totalPages = Math.ceil(d.total / 20);
            const pg = document.getElementById('unlinkedPagination');
            if (totalPages > 1) {
                pg.style.display = 'flex';
                pg.style.gap = '6px';
                let html = '';
                for (let i = 1; i <= totalPages; i++) {
                    html += `<button onclick="loadUnlinked(${i})"
                        style="min-width:32px;height:32px;border-radius:8px;border:1.5px solid ${i===unlinkedPage?'#3b82f6':'#e2e8f0'};background:${i===unlinkedPage?'#3b82f6':'#fff'};color:${i===unlinkedPage?'#fff':'#475569'};font-family:Vazirmatn,sans-serif;font-size:.8rem;cursor:pointer;">${i}</button>`;
                }
                pg.innerHTML = html;
            } else {
                pg.style.display = 'none';
            }
        });
}

// ── تطابق خودکار ─────────────────────────────────────────────────
window.runAutoMatch = function() {
    log('در حال اجرای تطابق خودکار...');
    fetch('sync_customers_persons.php?action=auto_match', {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r => r.json())
        .then(d => {
            log(`✅ تطابق خودکار: ${d.matched} جفت اتصال برقرار شد`);
            showToast(`${d.matched} مشتری با طرف حساب لینک شد`, 'success');
            refreshStats();
            loadUnlinked(1);
        })
        .catch(() => { log('❌ خطا در اجرا'); showToast('خطا در اتصال', 'error'); });
};

// ── ایجاد طرف حساب برای مشتریان بدون اتصال ──────────────────────
window.runCreateMissing = function() {
    if (!confirm('برای همه مشتریان بدون طرف حساب، یک رکورد جدید در fin_persons ایجاد می‌شود. ادامه دهید؟')) return;
    log('در حال ایجاد طرف حساب‌ها...');
    fetch('sync_customers_persons.php?action=create_missing', {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r => r.json())
        .then(d => {
            log(`✅ ${d.created} طرف حساب جدید ایجاد و لینک شد`);
            showToast(`${d.created} طرف حساب ایجاد شد`, 'success');
            refreshStats();
            loadUnlinked(1);
        })
        .catch(() => { log('❌ خطا'); showToast('خطا در اتصال', 'error'); });
};

// ── لینک سریع (از تطابق پیشنهادی) ──────────────────────────────
window.quickLink = function(cid, fpid) {
    const fd = new FormData();
    fd.append('action', 'manual_link');
    fd.append('customer_id', cid);
    fd.append('person_id', fpid);
    fetch('sync_customers_persons.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
        .then(r => r.json())
        .then(() => {
            showToast('اتصال برقرار شد', 'success');
            refreshStats();
            loadUnlinked(unlinkedPage);
        });
};

// ── مودال لینک دستی ──────────────────────────────────────────────
window.openManualLink = function(cid, name) {
    currentCustomerId   = cid;
    selectedPersonId    = null;
    selectedPersonName  = null;
    document.getElementById('mm-customer-name').textContent = name;
    document.getElementById('mm-search').value = '';
    document.getElementById('personsDropdown').innerHTML = '';
    document.getElementById('personsDropdown').classList.remove('open');
    document.getElementById('mm-selected').style.display = 'none';
    document.getElementById('mmConfirmBtn').disabled = true;
    document.getElementById('manualModal').style.display = 'flex';
};

window.closeManualModal = function() {
    document.getElementById('manualModal').style.display = 'none';
};

let searchTimer = null;
window.searchPersons = function(val) {
    clearTimeout(searchTimer);
    if (val.length < 2) { document.getElementById('personsDropdown').classList.remove('open'); return; }
    searchTimer = setTimeout(() => {
        fetch(`sync_customers_persons.php?action=search_persons&q=${encodeURIComponent(val)}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(r => r.json())
            .then(items => {
                const dd = document.getElementById('personsDropdown');
                if (!items.length) { dd.innerHTML = '<div class="persons-dd-item" style="color:#94a3b8;">موردی یافت نشد</div>'; dd.classList.add('open'); return; }
                dd.innerHTML = items.map(p => `
                    <div class="persons-dd-item" onclick="selectPerson(${p.id},'${esc(p.name)}')">
                        <span>${esc(p.name)}</span>
                        <span style="font-size:.72rem;color:#94a3b8;">${esc(p.code)} ${p.mobile?'· '+p.mobile:''}</span>
                    </div>`).join('');
                dd.classList.add('open');
            });
    }, 250);
};

window.selectPerson = function(id, name) {
    selectedPersonId   = id;
    selectedPersonName = name;
    document.getElementById('personsDropdown').classList.remove('open');
    document.getElementById('mm-selected').style.display = 'block';
    document.getElementById('mm-selected-name').textContent = name;
    document.getElementById('mmConfirmBtn').disabled = false;
};

window.clearPersonSelection = function() {
    selectedPersonId = null;
    document.getElementById('mm-selected').style.display = 'none';
    document.getElementById('mmConfirmBtn').disabled = true;
};

window.confirmManualLink = function() {
    if (!currentCustomerId || !selectedPersonId) return;
    const fd = new FormData();
    fd.append('action', 'manual_link');
    fd.append('customer_id', currentCustomerId);
    fd.append('person_id', selectedPersonId);
    fetch('sync_customers_persons.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
        .then(r => r.json())
        .then(d => {
            closeManualModal();
            showToast('اتصال برقرار شد', 'success');
            refreshStats();
            loadUnlinked(unlinkedPage);
        });
};

// ── toast و log ───────────────────────────────────────────────────
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type;
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 3500);
}

function log(msg) {
    const box = document.getElementById('logBox');
    box.style.display = 'block';
    box.innerHTML += '[' + new Date().toLocaleTimeString('fa-IR') + '] ' + msg + '\n';
    box.scrollTop = box.scrollHeight;
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

// بستن dropdown با کلیک بیرون
document.addEventListener('click', e => {
    if (!e.target.closest('.search-persons-wrap'))
        document.getElementById('personsDropdown').classList.remove('open');
});

// کلید Esc برای بستن مودال
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeManualModal(); });

// expose برای دکمه‌های خارج از IIFE
window.refreshStats = refreshStats;
window.loadUnlinked = loadUnlinked;

// اجرای اولیه
refreshStats();
loadUnlinked(1);

})();
</script>

<?php require_once $root . '/templates/footer.php'; ?>
