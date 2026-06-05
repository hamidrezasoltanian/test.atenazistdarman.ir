<?php
// گزارش معاملات فصلی (فرم ۱۶۹) — سازمان امور مالیاتی ایران
$root = dirname(__DIR__, 2);
require_once $root . '/Config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';

requireLogin();
$userId = $_SESSION['user_id'] ?? 0;

// ── تعریف فصل‌های شمسی ──────────────────────────────────────
$quarters = [
    1 => ['label' => 'فصل اول (فروردین–خرداد)',   'months' => [1,2,3]],
    2 => ['label' => 'فصل دوم (تیر–شهریور)',      'months' => [4,5,6]],
    3 => ['label' => 'فصل سوم (مهر–آذر)',          'months' => [7,8,9]],
    4 => ['label' => 'فصل چهارم (دی–اسفند)',      'months' => [10,11,12]],
];

// تشخیص فصل جاری
$curM = (int)jdate('m');
$curY = (int)jdate('Y');
$curQ = $curM <= 3 ? 1 : ($curM <= 6 ? 2 : ($curM <= 9 ? 3 : 4));

$selYear = (int)($_GET['year']    ?? $curY);
$selQ    = (int)($_GET['quarter'] ?? $curQ);
if (!isset($quarters[$selQ])) $selQ = 1;

$monthRange = $quarters[$selQ]['months'];
$mFrom = $monthRange[0];
$mTo   = $monthRange[2];

// ── AJAX: دانلود Excel ────────────────────────────────────────
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
if ($isAjax && ($_GET['action'] ?? '') === 'export_excel') {
    require_once $root . '/vendor/autoload.php';
    exportExcel($pdo, $selYear, $selQ, $quarters, $mFrom, $mTo);
    exit;
}

// ── AJAX: دریافت داده ─────────────────────────────────────────
if ($isAjax && ($_GET['action'] ?? '') === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    $data = fetchQuarterData($pdo, $selYear, $mFrom, $mTo);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── تابع واکشی داده فصلی ────────────────────────────────────
function fetchQuarterData(PDO $pdo, int $year, int $mFrom, int $mTo): array {
    $yearStr = sprintf('%04d', $year);
    // ساخت شرط ماه‌ها
    $mCond = "CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(fi.invoice_date,'/',2),'/',-1) AS UNSIGNED)";

    $sql = "
        SELECT
            fp.id                                              AS person_id,
            COALESCE(fp.company_name, fp.name)                AS name,
            COALESCE(fp.economic_code, fp.codeeghtesadi, '')  AS economic_code,
            COALESCE(fp.national_id,  fp.shenasemeli, '')     AS national_id,
            COALESCE(fp.state, '')                            AS state,
            COALESCE(fp.city, '')                             AS city,
            COUNT(fi.id)                                      AS invoice_count,
            COALESCE(SUM(fi.subtotal),0)                      AS subtotal,
            COALESCE(SUM(fi.discount),0)                      AS discount,
            COALESCE(SUM(fi.tax),0)                           AS tax,
            COALESCE(SUM(fi.total_amount),0)                  AS total_amount,
            fi.type                                           AS inv_type
        FROM fin_invoices fi
        JOIN fin_persons fp ON fp.id = fi.person_id
        WHERE fi.is_deleted = 0
          AND fi.status = 'confirmed'
          AND SUBSTRING_INDEX(fi.invoice_date,'/',1) = ?
          AND {$mCond} BETWEEN ? AND ?
          AND fi.type IN ('sell','buy')
        GROUP BY fi.person_id, fi.type
        ORDER BY fi.type, total_amount DESC
    ";

    $st = $pdo->prepare($sql);
    $st->execute([$yearStr, $mFrom, $mTo]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $sell = []; $buy = [];
    foreach ($rows as $r) {
        if ($r['inv_type'] === 'sell') $sell[] = $r;
        else                           $buy[]  = $r;
    }

    // خلاصه کل
    $sellTotal = array_sum(array_column($sell, 'total_amount'));
    $sellTax   = array_sum(array_column($sell, 'tax'));
    $buyTotal  = array_sum(array_column($buy,  'total_amount'));
    $buyTax    = array_sum(array_column($buy,  'tax'));

    return compact('sell','buy','sellTotal','sellTax','buyTotal','buyTax');
}

// ── تابع export Excel ────────────────────────────────────────
function exportExcel(PDO $pdo, int $year, int $selQ, array $quarters, int $mFrom, int $mTo): void {
    $data = fetchQuarterData($pdo, $year, $mFrom, $mTo);
    $quarterLabel = $quarters[$selQ]['label'] ?? "فصل {$selQ}";

    $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sp->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

    // ── شیت فروش ─────────────────────────────────────────────
    buildSheet($sp->getActiveSheet(), 'فروش فصلی', $data['sell'],
        $year, $quarterLabel, 'فروش', $data['sellTotal'], $data['sellTax']);

    // ── شیت خرید ─────────────────────────────────────────────
    $sheetBuy = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($sp, 'خرید فصلی');
    $sp->addSheet($sheetBuy);
    buildSheet($sheetBuy, 'خرید فصلی', $data['buy'],
        $year, $quarterLabel, 'خرید', $data['buyTotal'], $data['buyTax']);

    // ── شیت خلاصه ────────────────────────────────────────────
    $sheetSum = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($sp, 'خلاصه');
    $sp->addSheet($sheetSum);
    buildSummarySheet($sheetSum, $year, $quarterLabel, $data);

    $sp->setActiveSheetIndex(0);

    $fn = "form169_{$year}_Q{$selQ}.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$fn}\"");
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp);
    $writer->save('php://output');
}

function buildSheet(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws,
    string $title,
    array $rows,
    int $year,
    string $quarterLabel,
    string $type,
    float $grandTotal,
    float $grandTax
): void {
    $ws->setTitle($title);
    $ws->setRightToLeft(true);

    // عنوان
    $ws->setCellValue('A1', 'گزارش معاملات فصلی (فرم ۱۶۹) — آتنا زیست درمان');
    $ws->setCellValue('A2', "نوع: {$type} | دوره: {$quarterLabel} {$year}");
    $ws->mergeCells('A1:I1');
    $ws->mergeCells('A2:I2');

    $hStyle = [
        'font' => ['bold' => true, 'size' => 11],
        'alignment' => ['horizontal' => 'center'],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A5F']],
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    ];
    $ws->getStyle('A1:I1')->applyFromArray($hStyle);
    $ws->getStyle('A2:I2')->applyFromArray([
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '3B82F6']],
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['horizontal' => 'center'],
    ]);

    // سرستون‌ها
    $headers = ['ردیف','نام طرف معامله','کد اقتصادی','شناسه ملی','استان','شهر',
                'تعداد فاکتور','مبلغ کل (ریال)','مالیات (ریال)'];
    $ws->fromArray($headers, null, 'A4');
    $ws->getStyle('A4:I4')->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E2E8F0']],
        'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'CBD5E1']]],
        'alignment' => ['horizontal' => 'center'],
    ]);

    // داده‌ها
    $r = 5;
    foreach ($rows as $i => $row) {
        $ws->fromArray([
            $i + 1,
            $row['name'],
            $row['economic_code'] ?: '—',
            $row['national_id']   ?: '—',
            $row['state']         ?: '—',
            $row['city']          ?: '—',
            $row['invoice_count'],
            (int)$row['total_amount'],
            (int)$row['tax'],
        ], null, 'A' . $r);

        // رنگ‌بندی یک در میان
        if ($i % 2 === 1) {
            $ws->getStyle("A{$r}:I{$r}")->applyFromArray([
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F8FAFC']],
            ]);
        }
        $ws->getStyle("H{$r}:I{$r}")->getNumberFormat()->setFormatCode('#,##0');
        $r++;
    }

    // ردیف جمع کل
    $ws->fromArray(['', 'جمع کل', '', '', '', '', '', (int)$grandTotal, (int)$grandTax], null, 'A'.$r);
    $ws->getStyle("A{$r}:I{$r}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FEF3C7']],
        'borders' => ['top' => ['borderStyle' => 'medium', 'color' => ['rgb' => 'F59E0B']]],
    ]);
    $ws->getStyle("H{$r}:I{$r}")->getNumberFormat()->setFormatCode('#,##0');

    // عرض ستون‌ها
    $widths = [6, 30, 18, 18, 14, 14, 12, 20, 18];
    foreach ($widths as $ci => $w) {
        $ws->getColumnDimensionByColumn($ci + 1)->setWidth($w);
    }

    // border کل جدول
    if ($r > 5) {
        $ws->getStyle("A4:I{$r}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E2E8F0']]],
        ]);
    }
}

function buildSummarySheet(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws,
    int $year,
    string $quarterLabel,
    array $data
): void {
    $ws->setTitle('خلاصه');
    $ws->setRightToLeft(true);
    $ws->setCellValue('A1', 'خلاصه گزارش فرم ۱۶۹');
    $ws->setCellValue('A2', "دوره: {$quarterLabel} {$year}");
    $ws->setCellValue('A4', 'شرح');
    $ws->setCellValue('B4', 'مبلغ (ریال)');
    $ws->setCellValue('C4', 'مالیات (ریال)');
    $ws->setCellValue('D4', 'تعداد طرف معامله');

    $rows = [
        ['فروش فصلی',  $data['sellTotal'], $data['sellTax'],  count($data['sell'])],
        ['خرید فصلی',  $data['buyTotal'],  $data['buyTax'],   count($data['buy'])],
        ['جمع کل',
            $data['sellTotal'] + $data['buyTotal'],
            $data['sellTax']   + $data['buyTax'],
            count($data['sell']) + count($data['buy'])
        ],
    ];
    $r = 5;
    foreach ($rows as $row) {
        $ws->fromArray($row, null, 'A'.$r);
        $ws->getStyle("B{$r}:C{$r}")->getNumberFormat()->setFormatCode('#,##0');
        $r++;
    }
    $ws->getStyle('A1:D1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 13],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A5F']],
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    ]);
    $ws->getStyle('A4:D4')->applyFromArray(['font' => ['bold' => true]]);
    $ws->getStyle("A7:D7")->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FEF3C7']]]);
    $ws->getColumnDimension('A')->setWidth(20);
    $ws->getColumnDimension('B')->setWidth(22);
    $ws->getColumnDimension('C')->setWidth(18);
    $ws->getColumnDimension('D')->setWidth(18);
}

// ── رندر HTML ─────────────────────────────────────────────────
$pageTitle = 'فرم ۱۶۹ — معاملات فصلی';
ob_start();
require_once $root . '/templates/header.php';
echo ob_get_clean();
require_once $root . '/templates/sidebar.php';

// سال‌های موجود (۵ سال اخیر)
$yearOptions = [];
for ($y = $curY; $y >= $curY - 4; $y--) $yearOptions[] = $y;
?>

<div class="content-wrapper">
<div style="padding:28px 32px;max-width:1100px;">

<!-- ── عنوان ────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px;">
  <div>
    <h1 style="margin:0;font-size:1.35rem;font-weight:700;color:#0f172a;">📋 گزارش معاملات فصلی — فرم ۱۶۹</h1>
    <p style="margin:5px 0 0;color:#64748b;font-size:.85rem;">
      خرید و فروش فصلی برای ارائه به سازمان امور مالیاتی ایران
    </p>
  </div>
  <div style="display:flex;gap:10px;">
    <button onclick="exportExcel()" class="fin-btn fin-btn-primary" id="exportBtn" style="display:flex;align-items:center;gap:6px;">
      <span>⬇</span> دانلود Excel (فرم ۱۶۹)
    </button>
  </div>
</div>

<!-- ── فیلتر فصل/سال ─────────────────────────────────────────── -->
<div class="fin-panel" style="margin-bottom:20px;">
  <div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;">
    <div>
      <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:6px;">سال مالی</label>
      <select id="selYear" class="fin-input" style="width:130px;" onchange="loadData()">
        <?php foreach ($yearOptions as $y): ?>
        <option value="<?= $y ?>" <?= $y === $selYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:6px;">فصل</label>
      <select id="selQ" class="fin-input" style="width:240px;" onchange="loadData()">
        <?php foreach ($quarters as $qn => $qd): ?>
        <option value="<?= $qn ?>" <?= $qn === $selQ ? 'selected' : '' ?>><?= $qd['label'] ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <button onclick="loadData()" class="fin-btn fin-btn-primary">🔄 بروزرسانی</button>
    </div>
  </div>
</div>

<!-- ── کارت‌های خلاصه ─────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;">
  <div class="fin-stat-card blue">
    <div class="stat-label">جمع فروش فصلی</div>
    <div class="stat-value" id="statSellTotal">—</div>
    <div class="stat-sub" id="statSellCount"></div>
  </div>
  <div class="fin-stat-card green">
    <div class="stat-label">مالیات فروش</div>
    <div class="stat-value" id="statSellTax">—</div>
  </div>
  <div class="fin-stat-card amber">
    <div class="stat-label">جمع خرید فصلی</div>
    <div class="stat-value" id="statBuyTotal">—</div>
    <div class="stat-sub" id="statBuyCount"></div>
  </div>
  <div class="fin-stat-card rose">
    <div class="stat-label">مالیات خرید</div>
    <div class="stat-value" id="statBuyTax">—</div>
  </div>
</div>

<!-- ── تب‌ها ────────────────────────────────────────────────────── -->
<div class="fin-tabs" style="margin-bottom:0;">
  <button class="fin-tab active" id="tab-sell" onclick="switchTab('sell')">🧾 فروش فصلی</button>
  <button class="fin-tab" id="tab-buy" onclick="switchTab('buy')">📦 خرید فصلی</button>
</div>

<!-- ── جدول فروش ─────────────────────────────────────────────── -->
<div id="panel-sell" class="fin-panel" style="border-radius:0 0 14px 14px;margin-bottom:22px;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
    <div style="display:flex;align-items:center;gap:10px;">
      <h3 class="dash-title">فروش فصلی</h3>
      <span id="sellBadge" class="fin-badge" style="background:#eff6ff;color:#2563eb;"></span>
    </div>
    <input type="text" id="searchSell" class="fin-input" placeholder="جستجوی نام / کد اقتصادی..."
           style="width:230px;font-size:.8rem;" oninput="filterTable('sell')">
  </div>
  <div style="overflow-x:auto;">
    <table class="fin-table" id="tblSell">
      <thead>
        <tr>
          <th style="width:50px;">ردیف</th>
          <th>نام طرف معامله</th>
          <th>کد اقتصادی</th>
          <th>شناسه ملی</th>
          <th>استان / شهر</th>
          <th style="text-align:center;">فاکتور</th>
          <th style="text-align:left;">مبلغ کل (ریال)</th>
          <th style="text-align:left;">مالیات (ریال)</th>
          <th style="width:80px;"></th>
        </tr>
      </thead>
      <tbody id="bodySell">
        <tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;">در حال بارگذاری...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ── جدول خرید ─────────────────────────────────────────────── -->
<div id="panel-buy" class="fin-panel" style="border-radius:0 0 14px 14px;margin-bottom:22px;display:none;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
    <div style="display:flex;align-items:center;gap:10px;">
      <h3 class="dash-title">خرید فصلی</h3>
      <span id="buyBadge" class="fin-badge" style="background:#fff7ed;color:#c2410c;"></span>
    </div>
    <input type="text" id="searchBuy" class="fin-input" placeholder="جستجوی نام / کد اقتصادی..."
           style="width:230px;font-size:.8rem;" oninput="filterTable('buy')">
  </div>
  <div style="overflow-x:auto;">
    <table class="fin-table" id="tblBuy">
      <thead>
        <tr>
          <th style="width:50px;">ردیف</th>
          <th>نام طرف معامله</th>
          <th>کد اقتصادی</th>
          <th>شناسه ملی</th>
          <th>استان / شهر</th>
          <th style="text-align:center;">فاکتور</th>
          <th style="text-align:left;">مبلغ کل (ریال)</th>
          <th style="text-align:left;">مالیات (ریال)</th>
          <th style="width:80px;"></th>
        </tr>
      </thead>
      <tbody id="bodyBuy">
        <tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;">در حال بارگذاری...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ── هشدار کد اقتصادی ناقص ─────────────────────────────────── -->
<div id="missingAlert" style="display:none;" class="fin-panel" style="background:#fffbeb;border:1px solid #f59e0b;">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
    <span style="font-size:1.2rem;">⚠️</span>
    <strong style="color:#92400e;">طرف‌های معامله با کد اقتصادی ناقص</strong>
  </div>
  <p style="color:#78350f;font-size:.82rem;margin:0 0 10px;">
    برای ارسال صحیح فرم ۱۶۹، کد اقتصادی و شناسه ملی این طرف‌ها باید تکمیل شود:
  </p>
  <div id="missingList" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
  <a href="fin_persons.php" style="display:inline-block;margin-top:10px;font-size:.8rem;color:#3b82f6;">
    ویرایش طرف‌های معامله ←
  </a>
</div>

</div>
</div>

<script>
let sellData = [], buyData = [];

function fm(v) {
  return parseInt(v||0).toLocaleString('fa-IR');
}

function switchTab(tab) {
  document.getElementById('panel-sell').style.display = tab === 'sell' ? '' : 'none';
  document.getElementById('panel-buy').style.display  = tab === 'buy'  ? '' : 'none';
  document.getElementById('tab-sell').classList.toggle('active', tab === 'sell');
  document.getElementById('tab-buy').classList.toggle('active',  tab === 'buy');
}

function buildTableRows(rows, type) {
  if (!rows.length) return `<tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;">داده‌ای یافت نشد</td></tr>`;

  return rows.map((r, i) => {
    const hasEco  = r.economic_code && r.economic_code !== '—';
    const hasNatl = r.national_id   && r.national_id   !== '—';
    const warn = (!hasEco || !hasNatl) ? ' style="background:#fffbeb"' : '';
    return `<tr${warn}>
      <td style="text-align:center;color:#94a3b8;">${i+1}</td>
      <td><strong>${r.name}</strong></td>
      <td><span class="fin-badge" style="background:${hasEco?'#eff6ff':'#fef2f2'};color:${hasEco?'#2563eb':'#ef4444'};">${r.economic_code||'ندارد'}</span></td>
      <td><span class="fin-badge" style="background:${hasNatl?'#f0fdf4':'#fef2f2'};color:${hasNatl?'#15803d':'#ef4444'};">${r.national_id||'ندارد'}</span></td>
      <td style="font-size:.78rem;color:#64748b;">${r.state||'—'}${r.city?' / '+r.city:''}</td>
      <td style="text-align:center;">${r.invoice_count}</td>
      <td style="text-align:left;direction:ltr;font-family:monospace;font-size:.83rem;">${fm(r.total_amount)}</td>
      <td style="text-align:left;direction:ltr;font-family:monospace;font-size:.83rem;color:#ef4444;">${fm(r.tax)}</td>
      <td>
        <a href="fin_persons.php?edit=${r.person_id}" class="fin-btn" style="padding:3px 8px;font-size:.73rem;">ویرایش</a>
      </td>
    </tr>`;
  }).join('');
}

function filterTable(type) {
  const q = document.getElementById('search' + (type==='sell'?'Sell':'Buy')).value.trim().toLowerCase();
  const src = type === 'sell' ? sellData : buyData;
  const filtered = q ? src.filter(r =>
    (r.name||'').toLowerCase().includes(q) ||
    (r.economic_code||'').toLowerCase().includes(q) ||
    (r.national_id||'').toLowerCase().includes(q)
  ) : src;
  document.getElementById('body' + (type==='sell'?'Sell':'Buy')).innerHTML = buildTableRows(filtered, type);
}

function showMissingAlert(data) {
  const missing = [...(data.sell||[]), ...(data.buy||[])]
    .filter(r => !r.economic_code || r.economic_code === '—' || !r.national_id || r.national_id === '—');
  const unique = [...new Map(missing.map(r=>[r.person_id,r])).values()];

  if (unique.length) {
    document.getElementById('missingAlert').style.display = '';
    document.getElementById('missingList').innerHTML = unique.map(r =>
      `<a href="fin_persons.php?edit=${r.person_id}" class="fin-badge" style="background:#fef2f2;color:#b91c1c;text-decoration:none;">
        ⚠ ${r.name}
      </a>`
    ).join('');
  } else {
    document.getElementById('missingAlert').style.display = 'none';
  }
}

function loadData() {
  const year = document.getElementById('selYear').value;
  const q    = document.getElementById('selQ').value;

  // آپدیت URL بدون reload
  const url = new URL(location.href);
  url.searchParams.set('year', year);
  url.searchParams.set('quarter', q);
  history.replaceState({}, '', url);

  document.getElementById('bodySell').innerHTML =
    '<tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;">در حال بارگذاری...</td></tr>';
  document.getElementById('bodyBuy').innerHTML =
    '<tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;">در حال بارگذاری...</td></tr>';

  $.getJSON(`?action=data&year=${year}&quarter=${q}`, function(d) {
    sellData = d.sell || [];
    buyData  = d.buy  || [];

    // آمار
    document.getElementById('statSellTotal').textContent = fm(d.sellTotal);
    document.getElementById('statSellTax').textContent   = fm(d.sellTax);
    document.getElementById('statBuyTotal').textContent  = fm(d.buyTotal);
    document.getElementById('statBuyTax').textContent    = fm(d.buyTax);
    document.getElementById('statSellCount').textContent = sellData.length + ' طرف معامله';
    document.getElementById('statBuyCount').textContent  = buyData.length + ' طرف معامله';

    // badge
    document.getElementById('sellBadge').textContent = sellData.length + ' ردیف';
    document.getElementById('buyBadge').textContent  = buyData.length  + ' ردیف';

    document.getElementById('bodySell').innerHTML = buildTableRows(sellData, 'sell');
    document.getElementById('bodyBuy').innerHTML  = buildTableRows(buyData,  'buy');

    showMissingAlert(d);
  }).fail(function() {
    const msg = '<tr><td colspan="9" style="text-align:center;padding:40px;color:#ef4444;">خطا در بارگذاری داده</td></tr>';
    document.getElementById('bodySell').innerHTML = msg;
    document.getElementById('bodyBuy').innerHTML  = msg;
  });
}

function exportExcel() {
  const year = document.getElementById('selYear').value;
  const q    = document.getElementById('selQ').value;
  const btn  = document.getElementById('exportBtn');
  btn.disabled = true;
  btn.innerHTML = '<span>⏳</span> در حال تولید...';

  const link = document.createElement('a');
  link.href  = `?action=export_excel&year=${year}&quarter=${q}`;
  link.download = `form169_${year}_Q${q}.xlsx`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  setTimeout(() => {
    btn.disabled = false;
    btn.innerHTML = '<span>⬇</span> دانلود Excel (فرم ۱۶۹)';
  }, 3000);
}

$(function() { loadData(); });
</script>

<?php require_once $root . '/templates/footer.php'; ?>
