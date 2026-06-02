<?php
/*
 * فایل: public_html/admin/inv_kardex.php
 * توضیحات: کاردکس موجودی کالا — تاریخچه ورود و خروج با موجودی تجمعی
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// بررسی ورود به سیستم
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>'نشست منقضی']); exit; }
    header('Location: ../login.php'); exit;
}

// ──────────────────────────────────────────────────────────────
// پردازش درخواست‌های AJAX
// ──────────────────────────────────────────────────────────────
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'] ?? '';

    // ── جستجوی کالا ────────────────────────────────────────────
    if ($action === 'search') {
        $q = trim(faToEn($_GET['q'] ?? ''));
        $stmt = $pdo->prepare("SELECT s.id, s.stuff_name, s.stuff_code, s.unit,
                COALESCE(p.total_inventory,0) AS total_inventory,
                COALESCE(p.central_store,0) AS central_store,
                COALESCE(p.virtual_store,0) AS virtual_store,
                COALESCE(p.scrap_store,0) AS scrap_store,
                COALESCE(p.price,0) AS price
            FROM stuffs s
            LEFT JOIN stuff_price_list p ON p.stuff_code = s.stuff_code
            WHERE s.is_delete = 0 AND (s.stuff_name LIKE ? OR s.stuff_code LIKE ? OR s.technical_code LIKE ?)
            ORDER BY s.stuff_name ASC
            LIMIT 20");
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
        echo json_encode(['status'=>'ok','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── کاردکس یک کالا ────────────────────────────────────────
    if ($action === 'kardex') {
        $stuffId  = (int)($_GET['stuff_id'] ?? 0);
        $dateFrom = trim(faToEn($_GET['date_from'] ?? ''));
        $dateTo   = trim(faToEn($_GET['date_to'] ?? ''));

        if ($stuffId < 1) { echo json_encode(['status'=>'error','message'=>'شناسه کالا نامعتبر']); exit; }

        // اطلاعات پایه کالا
        $stmtStuff = $pdo->prepare("SELECT s.id, s.stuff_name, s.stuff_code, s.unit,
                COALESCE(p.total_inventory,0) AS total_inventory,
                COALESCE(p.central_store,0)   AS central_store,
                COALESCE(p.virtual_store,0)   AS virtual_store,
                COALESCE(p.scrap_store,0)     AS scrap_store,
                COALESCE(p.price,0)           AS price
            FROM stuffs s
            LEFT JOIN stuff_price_list p ON p.stuff_code = s.stuff_code
            WHERE s.id = ? AND s.is_delete = 0");
        $stmtStuff->execute([$stuffId]);
        $stuff = $stmtStuff->fetch(PDO::FETCH_ASSOC);
        if (!$stuff) { echo json_encode(['status'=>'error','message'=>'کالا یافت نشد']); exit; }

        // تاریخچه حرکات از اسناد تأیید شده
        $sql = "SELECT
                    t.id AS ticket_id,
                    t.ticket_number,
                    t.type,
                    t.ticket_date,
                    t.ticket_date_g,
                    t.person_name,
                    t.description AS ticket_desc,
                    s.name AS storeroom_name,
                    d.name AS dest_storeroom_name,
                    i.qty,
                    i.unit_price,
                    i.total,
                    i.unit,
                    i.description AS item_desc
                FROM inv_ticket_items i
                JOIN inv_tickets t ON t.id = i.ticket_id
                JOIN inv_storerooms s ON s.id = t.storeroom_id
                LEFT JOIN inv_storerooms d ON d.id = t.dest_storeroom_id
                WHERE i.stuff_id = ?
                  AND t.is_deleted = 0
                  AND t.status = 'confirmed'";
        $params = [$stuffId];

        // فیلتر تاریخ میلادی
        if ($dateFrom !== '') {
            $sql .= " AND t.ticket_date >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= " AND t.ticket_date <= ?";
            $params[] = $dateTo;
        }
        $sql .= " ORDER BY t.ticket_date_g ASC, t.id ASC, i.id ASC";

        $stmtMov = $pdo->prepare($sql);
        $stmtMov->execute($params);
        $rawRows = $stmtMov->fetchAll(PDO::FETCH_ASSOC);

        // محاسبه موجودی تجمعی (rolling balance) در PHP
        $balance     = 0;
        $totalIn     = 0;
        $totalOut    = 0;
        $movements   = [];

        foreach ($rawRows as $row) {
            $qty = (float)$row['qty'];
            $isIn  = in_array($row['type'], ['receipt']);
            $isOut = in_array($row['type'], ['dispatch', 'return']);
            $isTransfer = ($row['type'] === 'transfer');

            $inQty  = 0;
            $outQty = 0;

            if ($isIn) {
                $inQty   = $qty;
                $balance += $qty;
                $totalIn += $qty;
            } elseif ($isOut) {
                $outQty   = $qty;
                $balance -= $qty;
                $totalOut += $qty;
            } elseif ($isTransfer) {
                // انتقال: خروج از مبدا، ورود به مقصد — نمایش به عنوان حرکت انتقالی
                $outQty   = $qty;
                $inQty    = 0;
                $balance -= $qty; // در کاردکس مبدا کسر می‌شود
                $totalOut += $qty;
            }

            $row['in_qty']   = $inQty;
            $row['out_qty']  = $outQty;
            $row['balance']  = $balance;
            $movements[] = $row;
        }

        echo json_encode([
            'status'    => 'ok',
            'stuff'     => $stuff,
            'movements' => $movements,
            'total_in'  => $totalIn,
            'total_out' => $totalOut,
            'balance'   => $balance,
        ]);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'عملیات نامعتبر']); exit;
}

// ──────────────────────────────────────────────────────────────
// رندر صفحه HTML
// ──────────────────────────────────────────────────────────────
$pageTitle = 'کاردکس کالا';
$basePath  = '../..';
$todayJalali = jdate('Y/m/d');

// اگر ارگومان کالا از URL آمد
$initStuffId = (int)($_GET['stuff_id'] ?? 0);

include __DIR__ . '/../../templates/header.php';
?>
<link rel="stylesheet" href="../../assets/css/fin_module.css">
<style>
/* ── استایل کاردکس ── */
.kardex-header-gradient {
    background: linear-gradient(135deg, #1e3a5f 0%, #1d4ed8 60%, #2563eb 100%);
    border-radius: 16px;
    padding: 24px 28px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}
.kardex-header-gradient h1 { margin: 0; font-size: 1.4rem; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.kardex-header-gradient p  { margin: 4px 0 0; opacity: .8; font-size: .9rem; }

/* جستجو */
.search-wrap { position: relative; }
.search-dropdown {
    position: absolute; top: calc(100% + 4px); right: 0; left: 0; z-index: 9999;
    background: #fff; border: 1px solid #d1d5db; border-radius: 10px;
    max-height: 260px; overflow-y: auto; box-shadow: 0 6px 24px rgba(0,0,0,.12);
}
.search-item {
    padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f3f4f6;
    display: flex; justify-content: space-between; align-items: center; gap: 12px;
}
.search-item:last-child { border-bottom: none; }
.search-item:hover { background: #eff6ff; }
.search-item-name { font-weight: 600; font-size: .9rem; }
.search-item-meta { font-size: .78rem; color: #6b7280; }
.search-item-stock { font-size: .82rem; font-weight: 600; color: #059669; }

/* کارت کالای انتخاب‌شده */
.stuff-card {
    background: linear-gradient(135deg, #f0fdf4, #dcfce7);
    border: 1px solid #86efac;
    border-radius: 12px;
    padding: 18px 22px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}
.stuff-card-icon { font-size: 2.4rem; }
.stuff-card-info h3 { margin: 0 0 4px; font-size: 1.15rem; font-weight: 700; color: #14532d; }
.stuff-card-info p  { margin: 0; font-size: .85rem; color: #166534; }
.stuff-card-stocks  { display: flex; gap: 10px; margin-right: auto; flex-wrap: wrap; }
.stock-chip {
    background: #fff; border-radius: 8px; padding: 6px 14px;
    font-size: .82rem; font-weight: 600; border: 1px solid #bbf7d0;
    display: flex; flex-direction: column; align-items: center; gap: 2px;
}
.stock-chip .chip-label { font-size: .7rem; color: #6b7280; font-weight: 400; }
.stock-chip.central { border-color: #6ee7b7; color: #065f46; }
.stock-chip.virtual  { border-color: #93c5fd; color: #1e40af; }
.stock-chip.scrap    { border-color: #fca5a5; color: #991b1b; }
.stock-chip.total    { border-color: #fbbf24; color: #92400e; background: #fffbeb; }

/* فیلتر تاریخ */
.kardex-filter {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 14px 18px; margin-bottom: 20px;
}
.kardex-filter label { font-size: .85rem; font-weight: 600; color: #374151; white-space: nowrap; }

/* جدول کاردکس */
.kardex-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.kardex-table th {
    background: #1e3a5f; color: #fff; padding: 10px 12px;
    text-align: center; white-space: nowrap; font-weight: 600;
}
.kardex-table td { padding: 9px 12px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; }
.kardex-table tr:hover td { background: #f8fafc; }
.kardex-table .in-qty  { color: #059669; font-weight: 700; }
.kardex-table .out-qty { color: #dc2626; font-weight: 700; }
.kardex-table .balance-col { font-weight: 700; color: #1d4ed8; }
.kardex-table tfoot td {
    background: #f0f9ff; font-weight: 700; padding: 10px 12px;
    border-top: 2px solid #bfdbfe;
}

/* نشانگر نوع */
.badge-receipt  { background: #d1fae5; color: #065f46; padding: 3px 9px; border-radius: 6px; font-size: .75rem; font-weight: 600; }
.badge-dispatch { background: #fef3c7; color: #92400e; padding: 3px 9px; border-radius: 6px; font-size: .75rem; font-weight: 600; }
.badge-transfer { background: #dbeafe; color: #1e40af; padding: 3px 9px; border-radius: 6px; font-size: .75rem; font-weight: 600; }
.badge-return   { background: #ffe4e6; color: #9f1239; padding: 3px 9px; border-radius: 6px; font-size: .75rem; font-weight: 600; }

/* حالت خالی */
.kardex-empty {
    text-align: center; padding: 60px 20px; color: #9ca3af;
}
.kardex-empty .empty-icon { font-size: 3rem; margin-bottom: 12px; }
.kardex-empty p { font-size: .95rem; margin: 0; }

/* تراشه آمار پایین جدول */
.summary-row { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 16px; }
.summary-chip {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 10px 18px; display: flex; align-items: center; gap: 10px;
    font-size: .88rem;
}
.summary-chip .s-label { color: #6b7280; }
.summary-chip .s-value { font-weight: 700; font-size: 1rem; }
.summary-chip.in-chip  { border-color: #6ee7b7; background: #f0fdf4; }
.summary-chip.in-chip  .s-value { color: #059669; }
.summary-chip.out-chip { border-color: #fca5a5; background: #fff1f2; }
.summary-chip.out-chip .s-value { color: #dc2626; }
.summary-chip.bal-chip { border-color: #93c5fd; background: #eff6ff; }
.summary-chip.bal-chip .s-value { color: #1d4ed8; }

/* دکمه چاپ */
@media print {
    .no-print, .fin-sidebar, .fin-topbar, nav, .kardex-header-gradient { display: none !important; }
    .main-content { margin: 0 !important; padding: 10px !important; }
    .stuff-card { border: 1px solid #ccc; background: #fff !important; }
    .kardex-table th { background: #1e3a5f !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<div class="main-content" style="padding: 20px;">

    <!-- هدر صفحه -->
    <div class="kardex-header-gradient no-print">
        <div>
            <h1>📊 کاردکس کالا</h1>
            <p>مشاهده تاریخچه کامل ورود، خروج و موجودی تجمعی هر کالا</p>
        </div>
        <button class="fin-btn" onclick="window.print()" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;">
            🖨️ چاپ
        </button>
    </div>

    <!-- جستجوی کالا -->
    <div class="fin-panel no-print" style="padding:20px;margin-bottom:20px;">
        <div class="fin-panel-title"><span class="title-icon">🔍</span> جستجوی کالا</div>
        <div class="search-wrap" style="max-width:500px;">
            <input type="text" id="stuffSearch" class="fin-input" placeholder="نام کالا، کد کالا یا کد فنی را وارد کنید..."
                autocomplete="off" oninput="onSearchInput(this.value)" style="font-size:1rem;padding:12px 16px;">
            <div class="search-dropdown" id="searchDropdown" style="display:none;"></div>
        </div>
    </div>

    <!-- کارت کالا + فیلتر -->
    <div id="stuffCardArea" style="display:none;">

        <!-- کارت اطلاعات کالا -->
        <div class="stuff-card" id="stuffCard">
            <div class="stuff-card-icon">📦</div>
            <div class="stuff-card-info">
                <h3 id="scName">—</h3>
                <p id="scMeta">—</p>
            </div>
            <div class="stuff-card-stocks">
                <div class="stock-chip total">
                    <span class="chip-label">کل موجودی</span>
                    <span id="scTotal">۰</span>
                </div>
                <div class="stock-chip central">
                    <span class="chip-label">انبار مرکزی</span>
                    <span id="scCentral">۰</span>
                </div>
                <div class="stock-chip virtual">
                    <span class="chip-label">انبار مجازی</span>
                    <span id="scVirtual">۰</span>
                </div>
                <div class="stock-chip scrap">
                    <span class="chip-label">اسقاط</span>
                    <span id="scScrap">۰</span>
                </div>
            </div>
        </div>

        <!-- فیلتر تاریخ -->
        <div class="kardex-filter no-print">
            <label>از تاریخ:</label>
            <input type="text" id="fDateFrom" class="fin-input" placeholder="۱۴۰۳/۰۱/۰۱" style="width:140px;">
            <label>تا تاریخ:</label>
            <input type="text" id="fDateTo" class="fin-input" placeholder="<?= $todayJalali ?>" style="width:140px;">
            <button class="fin-btn fin-btn-primary" onclick="loadKardex()">🔍 نمایش</button>
            <button class="fin-btn fin-btn-outline" onclick="clearDates()">پاک کردن فیلتر</button>
        </div>

        <!-- جدول کاردکس -->
        <div class="fin-panel" style="padding:0;overflow:hidden;">
            <div style="overflow-x:auto;">
                <table class="kardex-table" id="kardexTable">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>تاریخ</th>
                            <th>شماره سند</th>
                            <th>نوع</th>
                            <th>انبار</th>
                            <th>طرف حساب</th>
                            <th>ورودی</th>
                            <th>خروجی</th>
                            <th>موجودی تجمعی</th>
                            <th>قیمت واحد</th>
                            <th>توضیحات</th>
                        </tr>
                    </thead>
                    <tbody id="kardexBody">
                        <tr><td colspan="11" class="kardex-empty"><div class="empty-icon">📊</div><p>در حال بارگذاری...</p></td></tr>
                    </tbody>
                    <tfoot id="kardexFoot" style="display:none;">
                        <tr>
                            <td colspan="6" style="text-align:left;">جمع</td>
                            <td class="in-qty" id="footTotalIn">۰</td>
                            <td class="out-qty" id="footTotalOut">۰</td>
                            <td class="balance-col" id="footBalance">۰</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- خلاصه آماری -->
            <div class="summary-row no-print" id="summaryRow" style="display:none;padding:16px 20px;">
                <div class="summary-chip in-chip">
                    <span>📥</span>
                    <span class="s-label">جمع ورودی:</span>
                    <span class="s-value" id="sumIn">۰</span>
                </div>
                <div class="summary-chip out-chip">
                    <span>📤</span>
                    <span class="s-label">جمع خروجی:</span>
                    <span class="s-value" id="sumOut">۰</span>
                </div>
                <div class="summary-chip bal-chip">
                    <span>📊</span>
                    <span class="s-label">موجودی انتهای دوره:</span>
                    <span class="s-value" id="sumBal">۰</span>
                </div>
            </div>
        </div>

    </div>

    <!-- حالت اولیه: هنوز کالایی انتخاب نشده -->
    <div id="noStuffState" class="fin-panel">
        <div class="kardex-empty">
            <div class="empty-icon">🔍</div>
            <p>برای مشاهده کاردکس، یک کالا را در بالا جستجو و انتخاب کنید</p>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>

<script>
/* ══════════════════════════════════════════════════════════════
   متغیرهای سراسری
══════════════════════════════════════════════════════════════ */
let selectedStuffId   = <?= $initStuffId ?: 0 ?>;
let selectedStuffData = null;
let searchDebTimer    = null;

/* ══════════════════════════════════════════════════════════════
   بارگذاری اولیه (در صورت داشتن پارامتر stuff_id)
══════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    if (selectedStuffId > 0) loadKardex();
});

/* ══════════════════════════════════════════════════════════════
   جستجوی کالا
══════════════════════════════════════════════════════════════ */
function onSearchInput(q) {
    clearTimeout(searchDebTimer);
    const dropdown = document.getElementById('searchDropdown');
    if (q.trim().length < 1) { dropdown.style.display = 'none'; return; }
    searchDebTimer = setTimeout(() => {
        fetch(`inv_kardex.php?action=search&q=${encodeURIComponent(q)}`, { headers: {'X-Requested-With':'XMLHttpRequest'} })
            .then(r => r.json()).then(res => {
                if (!res.data || res.data.length === 0) {
                    dropdown.innerHTML = '<div style="padding:12px 14px;text-align:center;color:#9ca3af;font-size:.85rem;">نتیجه‌ای یافت نشد</div>';
                    dropdown.style.display = 'block';
                    return;
                }
                dropdown.innerHTML = res.data.map(s => `
                    <div class="search-item" onclick="selectStuff(${s.id},'${escAttr(s.stuff_name)}','${escAttr(s.stuff_code)}','${escAttr(s.unit||'عدد')}',${s.total_inventory},${s.central_store},${s.virtual_store},${s.scrap_store},${s.price})">
                        <div>
                            <div class="search-item-name">${esc(s.stuff_name)}</div>
                            <div class="search-item-meta">کد: ${esc(s.stuff_code)}</div>
                        </div>
                        <div class="search-item-stock">موجودی: ${fmtNum(s.total_inventory)}</div>
                    </div>`).join('');
                dropdown.style.display = 'block';
            }).catch(() => { dropdown.style.display = 'none'; });
    }, 350);
}

// بستن dropdown با کلیک خارج
document.addEventListener('click', e => {
    if (!e.target.closest('.search-wrap')) {
        document.getElementById('searchDropdown').style.display = 'none';
    }
});

/* ══════════════════════════════════════════════════════════════
   انتخاب کالا
══════════════════════════════════════════════════════════════ */
function selectStuff(id, name, code, unit, total, central, virtual_, scrap, price) {
    selectedStuffId = id;
    selectedStuffData = { id, name, code, unit, total, central, virtual: virtual_, scrap, price };

    // بروزرسانی فیلد جستجو
    document.getElementById('stuffSearch').value = name;
    document.getElementById('searchDropdown').style.display = 'none';

    // بروزرسانی کارت کالا
    document.getElementById('scName').textContent    = name;
    document.getElementById('scMeta').textContent    = 'کد: ' + code + ' | واحد: ' + unit;
    document.getElementById('scTotal').textContent   = fmtNum(total);
    document.getElementById('scCentral').textContent = fmtNum(central);
    document.getElementById('scVirtual').textContent = fmtNum(virtual_);
    document.getElementById('scScrap').textContent   = fmtNum(scrap);

    // نمایش ناحیه کاردکس
    document.getElementById('stuffCardArea').style.display  = 'block';
    document.getElementById('noStuffState').style.display   = 'none';

    // بارگذاری کاردکس
    loadKardex();
}

/* ══════════════════════════════════════════════════════════════
   بارگذاری کاردکس
══════════════════════════════════════════════════════════════ */
function loadKardex() {
    if (!selectedStuffId) return;
    const dateFrom = document.getElementById('fDateFrom') ? document.getElementById('fDateFrom').value.trim() : '';
    const dateTo   = document.getElementById('fDateTo')   ? document.getElementById('fDateTo').value.trim()   : '';

    const tbody = document.getElementById('kardexBody');
    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:24px;color:#9ca3af;">در حال بارگذاری...</td></tr>';
    document.getElementById('kardexFoot').style.display  = 'none';
    document.getElementById('summaryRow').style.display  = 'none';

    const params = new URLSearchParams({ action:'kardex', stuff_id:selectedStuffId });
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo)   params.set('date_to',   dateTo);

    fetch('inv_kardex.php?' + params, { headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'ok') {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center;color:#dc2626;padding:24px;">${esc(res.message)}</td></tr>`;
                return;
            }

            // بروزرسانی کارت با داده‌های تازه
            const s = res.stuff;
            document.getElementById('scName').textContent    = s.stuff_name;
            document.getElementById('scMeta').textContent    = 'کد: ' + s.stuff_code + ' | واحد: ' + (s.unit||'عدد');
            document.getElementById('scTotal').textContent   = fmtNum(s.total_inventory);
            document.getElementById('scCentral').textContent = fmtNum(s.central_store);
            document.getElementById('scVirtual').textContent = fmtNum(s.virtual_store);
            document.getElementById('scScrap').textContent   = fmtNum(s.scrap_store);

            const movements = res.movements || [];

            if (movements.length === 0) {
                tbody.innerHTML = `<tr><td colspan="11"><div class="kardex-empty"><div class="empty-icon">📭</div><p>در این بازه زمانی هیچ حرکتی ثبت نشده است</p></div></td></tr>`;
                return;
            }

            const typeLabels = { receipt:'رسید', dispatch:'حواله', transfer:'انتقال', return:'مرجوعی' };

            tbody.innerHTML = movements.map((m, i) => {
                const inQty  = parseFloat(m.in_qty)  || 0;
                const outQty = parseFloat(m.out_qty) || 0;
                const bal    = parseFloat(m.balance) || 0;
                const desc   = m.item_desc || m.ticket_desc || '';
                return `<tr>
                    <td style="text-align:center;color:#9ca3af;">${i+1}</td>
                    <td style="white-space:nowrap;">${esc(m.ticket_date)}</td>
                    <td><strong style="font-size:.82rem;">${esc(m.ticket_number)}</strong></td>
                    <td><span class="badge-${m.type}">${typeLabels[m.type]||m.type}</span></td>
                    <td style="white-space:nowrap;">${esc(m.storeroom_name||'—')}</td>
                    <td>${esc(m.person_name||'—')}</td>
                    <td class="${inQty>0?'in-qty':''}" style="text-align:left;">${inQty>0?fmtNum(inQty):'—'}</td>
                    <td class="${outQty>0?'out-qty':''}" style="text-align:left;">${outQty>0?fmtNum(outQty):'—'}</td>
                    <td class="balance-col" style="text-align:left;">${fmtNum(bal)}</td>
                    <td style="text-align:left;font-size:.82rem;color:#6b7280;">${fmtNum(m.unit_price)}</td>
                    <td style="font-size:.82rem;color:#6b7280;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(desc)}">${esc(desc)||'—'}</td>
                </tr>`;
            }).join('');

            // جمع‌بندی
            const totalIn  = parseFloat(res.total_in)  || 0;
            const totalOut = parseFloat(res.total_out) || 0;
            const balance  = parseFloat(res.balance)   || 0;

            document.getElementById('footTotalIn').textContent  = fmtNum(totalIn);
            document.getElementById('footTotalOut').textContent = fmtNum(totalOut);
            document.getElementById('footBalance').textContent  = fmtNum(balance);
            document.getElementById('kardexFoot').style.display = 'table-footer-group';

            document.getElementById('sumIn').textContent  = fmtNum(totalIn);
            document.getElementById('sumOut').textContent = fmtNum(totalOut);
            document.getElementById('sumBal').textContent = fmtNum(balance);
            document.getElementById('summaryRow').style.display = 'flex';
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;color:#dc2626;padding:24px;">خطا در بارگذاری داده‌ها</td></tr>';
        });
}

/* ══════════════════════════════════════════════════════════════
   پاک‌سازی فیلترها
══════════════════════════════════════════════════════════════ */
function clearDates() {
    document.getElementById('fDateFrom').value = '';
    document.getElementById('fDateTo').value   = '';
    loadKardex();
}

/* ══════════════════════════════════════════════════════════════
   ابزارها
══════════════════════════════════════════════════════════════ */
function esc(str) {
    if (!str && str !== 0) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(str) {
    if (!str) return '';
    return String(str).replace(/\\/g,'\\\\').replace(/'/g,"\\'");
}
function fmtNum(n) {
    const v = parseFloat(n) || 0;
    if (v === 0) return '۰';
    return v % 1 === 0
        ? Number(v).toLocaleString('fa-IR')
        : Number(v).toLocaleString('fa-IR', { maximumFractionDigits: 3 });
}
</script>
