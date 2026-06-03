<?php
/*
 * فایل: public_html/admin/fin_invoice_pdf.php
 * توضیحات: تولید PDF فاکتور فروش یا خرید با TCPDF
 * پارامترها: id=<invoice_id>&type=sell|buy
 * فونت: vazirmatn — پشتیبانی کامل از فارسی و RTL
 */

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// ---- پارامترهای ورودی ----
$id   = (int)($_GET['id'] ?? 0);
$type = in_array($_GET['type'] ?? '', ['sell', 'buy']) ? $_GET['type'] : 'sell';

if ($id <= 0) {
    die('شناسه فاکتور نامعتبر است.');
}

// ---- بارگذاری فاکتور ----
$stmtInv = $pdo->prepare(
    "SELECT i.*,
            COALESCE(p.company_name, p.name) AS person_name,
            p.address                         AS person_address,
            p.mobile                          AS person_mobile,
            p.codeeghtesadi
     FROM fin_invoices i
     LEFT JOIN fin_persons p ON p.id = i.person_id
     WHERE i.id = ? AND i.is_deleted = 0"
);
$stmtInv->execute([$id]);
$inv = $stmtInv->fetch(PDO::FETCH_ASSOC);

if (!$inv) {
    die('فاکتور یافت نشد یا حذف شده است.');
}

// ---- بارگذاری ردیف‌های فاکتور ----
$stmtItems = $pdo->prepare(
    "SELECT * FROM fin_invoice_items WHERE invoice_id = ? ORDER BY row_order"
);
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// ---- بارگذاری اطلاعات شرکت از settings ----
$settingKeys = ['company_name', 'company_address', 'company_phone', 'company_logo'];
$settings    = [];
$stmtSet     = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name','company_address','company_phone','company_logo')");
$stmtSet->execute();
foreach ($stmtSet->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$companyName    = $settings['company_name']    ?? 'آتنا زیست درمان';
$companyAddress = $settings['company_address'] ?? '';
$companyPhone   = $settings['company_phone']   ?? '';

// ---- توابع کمکی ----
function toPersian($n): string {
    return str_replace(
        ['0','1','2','3','4','5','6','7','8','9'],
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
        (string)$n
    );
}

function numFa($n): string {
    return toPersian(number_format((int)$n));
}

function statusLabel(string $s): string {
    $map = [
        'draft'     => 'پیش‌نویس',
        'confirmed' => 'تأیید شده',
        'paid'      => 'پرداخت کامل',
        'partial'   => 'پرداخت ناقص',
        'cancelled' => 'لغو شده',
    ];
    return $map[$s] ?? $s;
}

function invoiceTypeLabel(string $t): string {
    return $t === 'adjustment' ? 'فاکتور تنظیمی' : 'فاکتور رسمی';
}

// ---- رنگ‌بندی بر اساس نوع ----
// فروش: آبی  |  خرید: بنفش
$headerR = ($type === 'sell') ? 30  : 88;
$headerG = ($type === 'sell') ? 100 : 50;
$headerB = ($type === 'sell') ? 180 : 160;

$titleText = ($type === 'sell') ? 'فاکتور فروش' : 'فاکتور خرید';
$partyLabel = ($type === 'sell') ? 'خریدار' : 'تأمین‌کننده';

// ---- مقادیر مالی ----
$subtotal     = (float)($inv['subtotal']      ?? 0);
$discountAmt  = (float)($inv['discount_amount'] ?? 0);
$taxAmt       = (float)($inv['tax_amount']     ?? 0);
$shippingAmt  = (float)($inv['shipping_cost']  ?? 0);
$totalAmount  = (float)($inv['total_amount']   ?? 0);

// ---- بارگذاری TCPDF ----
define('K_PATH_FONTS', __DIR__ . '/../../vendor/tcpdf/fonts/');
require_once __DIR__ . '/../../vendor/tcpdf/tcpdf.php';

// ---- ایجاد کلاس سفارشی برای header/footer ----
class InvoicePDF extends TCPDF {

    public string $companyName    = '';
    public string $companyAddress = '';
    public string $companyPhone   = '';
    public string $titleText      = '';
    public string $invoiceNumber  = '';
    public string $invoiceDate    = '';
    public array  $headerColor    = [30, 100, 180];

    public function Header(): void {
        // نوار رنگی بالا
        $this->SetFillColor(...$this->headerColor);
        $this->Rect(0, 0, 210, 28, 'F');

        // نام شرکت — راست
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('vazirmatn', 'B', 16);
        $this->SetXY(0, 6);
        $this->Cell(140, 10, $this->companyName, 0, 0, 'R');

        // آدرس و تلفن زیر نام شرکت
        if ($this->companyAddress || $this->companyPhone) {
            $this->SetFont('vazirmatn', '', 8);
            $this->SetXY(0, 16);
            $info = trim(($this->companyAddress ? $this->companyAddress . '  ' : '') . ($this->companyPhone ? 'تلفن: ' . $this->companyPhone : ''));
            $this->Cell(140, 6, $info, 0, 0, 'R');
        }

        // عنوان فاکتور — چپ
        $this->SetFont('vazirmatn', 'B', 14);
        $this->SetXY(65, 6);
        $this->Cell(80, 10, $this->titleText, 0, 0, 'C');

        // شماره و تاریخ
        $this->SetFont('vazirmatn', '', 9);
        $this->SetXY(10, 16);
        $this->Cell(60, 6, 'شماره: ' . $this->invoiceNumber . '   تاریخ: ' . $this->invoiceDate, 0, 0, 'L');

        // خط جداکننده
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.3);
        $this->Line(10, 30, 200, 30);

        $this->SetTextColor(0, 0, 0);
    }

    public function Footer(): void {
        $this->SetY(-22);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);

        $this->SetFont('vazirmatn', '', 8);
        $this->SetTextColor(100, 100, 100);

        // ستون چپ: شماره صفحه
        $this->SetX(10);
        $this->Cell(60, 6, 'صفحه ' . toPersian($this->getAliasNumPage()) . ' از ' . toPersian($this->getAliasNbPages()), 0, 0, 'L');

        // ستون وسط: جمله قانونی
        $this->SetX(70);
        $this->Cell(70, 6, 'این فاکتور به منزله قرارداد می‌باشد', 0, 0, 'C');

        // ستون راست: تاریخ چاپ
        $this->SetX(140);
        $this->Cell(60, 6, 'تاریخ چاپ: ' . jdate('Y/m/d'), 0, 0, 'R');
    }
}

// ---- پیکربندی PDF ----
$pdf = new InvoicePDF('P', 'mm', 'A4', true, 'UTF-8', false);

$pdf->companyName    = $companyName;
$pdf->companyAddress = $companyAddress;
$pdf->companyPhone   = $companyPhone;
$pdf->titleText      = $titleText;
$pdf->invoiceNumber  = toPersian($inv['invoice_number'] ?? $id);
$pdf->invoiceDate    = toPersian($inv['issue_date']     ?? jdate('Y/m/d'));
$pdf->headerColor    = [$headerR, $headerG, $headerB];

$pdf->SetCreator('آتنا زیست درمان ERP');
$pdf->SetAuthor($companyName);
$pdf->SetTitle($titleText . ' شماره ' . ($inv['invoice_number'] ?? $id));
$pdf->SetSubject($titleText);
$pdf->SetKeywords('invoice, فاکتور, آتنا زیست درمان');

$pdf->setRTL(true);
$pdf->SetMargins(12, 35, 12);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(15);
$pdf->SetAutoPageBreak(true, 25);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

$pdf->AddPage();
$pdf->SetFont('vazirmatn', '', 10);

// ================================================================
// بخش ۱ — جعبه اطلاعات طرفین
// ================================================================
$pdf->SetFillColor(245, 247, 250);
$pdf->SetDrawColor(200, 210, 225);
$pdf->SetLineWidth(0.3);
$pdf->RoundedRect(12, 33, 186, 38, 2, '1111', 'DF');

// ---- ستون راست: اطلاعات طرف حساب ----
$pdf->SetFont('vazirmatn', 'B', 9);
$pdf->SetTextColor($headerR, $headerG, $headerB);
$pdf->SetXY(110, 35);
$pdf->Cell(85, 6, $partyLabel . ' / طرف حساب', 0, 1, 'R');

$pdf->SetFont('vazirmatn', '', 9);
$pdf->SetTextColor(40, 40, 40);

$personName    = $inv['person_name']    ?? $inv['customer_name'] ?? '---';
$personAddress = $inv['person_address'] ?? '';
$personMobile  = $inv['person_mobile']  ?? '';
$personCode    = $inv['codeeghtesadi']  ?? '';

$pdf->SetXY(110, 41);
$pdf->Cell(85, 5, 'نام: ' . $personName, 0, 1, 'R');

if ($personAddress) {
    $pdf->SetXY(110, 46);
    $pdf->MultiCell(85, 5, 'آدرس: ' . $personAddress, 0, 'R', false, 1);
}

$infoY = $pdf->GetY();
if ($infoY < 51) $infoY = 51;

if ($personMobile) {
    $pdf->SetXY(110, $infoY);
    $pdf->Cell(85, 5, 'موبایل: ' . toPersian($personMobile), 0, 1, 'R');
    $infoY += 5;
}
if ($personCode) {
    $pdf->SetXY(110, $infoY);
    $pdf->Cell(85, 5, 'کد اقتصادی: ' . toPersian($personCode), 0, 1, 'R');
}

// ---- ستون چپ: مشخصات فاکتور ----
$pdf->SetFont('vazirmatn', 'B', 9);
$pdf->SetTextColor($headerR, $headerG, $headerB);
$pdf->SetXY(12, 35);
$pdf->Cell(90, 6, 'مشخصات فاکتور', 0, 1, 'L');

$pdf->SetFont('vazirmatn', '', 9);
$pdf->SetTextColor(40, 40, 40);

$pdf->SetXY(12, 41);
$pdf->Cell(44, 5, 'شماره فاکتور:', 0, 0, 'L');
$pdf->Cell(44, 5, toPersian($inv['invoice_number'] ?? $id), 0, 1, 'L');

$pdf->SetXY(12, 46);
$pdf->Cell(44, 5, 'تاریخ صدور:', 0, 0, 'L');
$pdf->Cell(44, 5, toPersian($inv['issue_date'] ?? ''), 0, 1, 'L');

if (!empty($inv['due_date'])) {
    $pdf->SetXY(12, 51);
    $pdf->Cell(44, 5, 'سررسید:', 0, 0, 'L');
    $pdf->Cell(44, 5, toPersian($inv['due_date']), 0, 1, 'L');
}

$pdf->SetXY(12, 56);
$pdf->Cell(44, 5, 'نوع:', 0, 0, 'L');
$pdf->Cell(44, 5, invoiceTypeLabel($inv['invoice_type'] ?? 'official'), 0, 1, 'L');

$pdf->SetXY(12, 61);
$pdf->Cell(44, 5, 'وضعیت:', 0, 0, 'L');

// رنگ‌گذاری وضعیت
$status = $inv['status'] ?? 'draft';
$statusColors = [
    'draft'     => [150, 150, 150],
    'confirmed' => [30,  130,  30],
    'paid'      => [ 20, 100, 200],
    'partial'   => [200, 130,  20],
    'cancelled' => [200,  40,  40],
];
[$sr, $sg, $sb] = $statusColors[$status] ?? [80, 80, 80];
$pdf->SetTextColor($sr, $sg, $sb);
$pdf->Cell(44, 5, statusLabel($status), 0, 1, 'L');
$pdf->SetTextColor(40, 40, 40);

// ================================================================
// بخش ۲ — جدول ردیف‌های فاکتور
// ================================================================
$pdf->SetY(75);
$pdf->SetFont('vazirmatn', 'B', 9);
$pdf->SetFillColor($headerR, $headerG, $headerB);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetDrawColor(180, 190, 210);
$pdf->SetLineWidth(0.2);

// عرض ستون‌ها (RTL — از راست به چپ ولی TCPDF با RTL خودش مدیریت می‌کند)
// ردیف | شرح کالا/خدمت | واحد | تعداد | قیمت واحد | تخفیف٪ | مالیات٪ | مبلغ کل
$colW = [10, 55, 15, 15, 25, 15, 15, 26]; // جمع = 176

$headers = ['ردیف', 'شرح کالا/خدمت', 'واحد', 'تعداد', 'قیمت واحد', 'تخفیف٪', 'مالیات٪', 'مبلغ کل'];
$aligns  = ['C',    'R',              'C',    'C',      'C',          'C',       'C',        'C'      ];

// خط هدر جدول
$startX = 12;
$pdf->SetX($startX);
foreach ($headers as $i => $h) {
    $pdf->Cell($colW[$i], 8, $h, 1, 0, $aligns[$i], true);
}
$pdf->Ln();

// ردیف‌های جدول
$pdf->SetFont('vazirmatn', '', 8.5);
$pdf->SetTextColor(30, 30, 30);

$rowBg    = [255, 255, 255];
$altRowBg = [245, 248, 255];
$rowNum   = 1;

foreach ($items as $item) {
    $qty      = (float)($item['quantity']       ?? 0);
    $unitPrice= (float)($item['unit_price']      ?? 0);
    $discPct  = (float)($item['discount_percent'] ?? 0);
    $taxPct   = (float)($item['tax_percent']      ?? 0);
    $lineTotal= (float)($item['total_price']      ?? ($qty * $unitPrice * (1 - $discPct/100) * (1 + $taxPct/100)));
    $unit     = $item['unit'] ?? '';
    $desc     = $item['description'] ?? ($item['stuff_name'] ?? '');

    $bg = ($rowNum % 2 === 0) ? $altRowBg : $rowBg;
    $pdf->SetFillColor(...$bg);
    $pdf->SetDrawColor(210, 215, 225);

    $pdf->SetX($startX);
    $pdf->Cell($colW[0], 7, toPersian($rowNum),           1, 0, 'C', true);
    $pdf->Cell($colW[1], 7, $desc,                        1, 0, 'R', true);
    $pdf->Cell($colW[2], 7, $unit,                        1, 0, 'C', true);
    $pdf->Cell($colW[3], 7, toPersian((int)$qty),         1, 0, 'C', true);
    $pdf->Cell($colW[4], 7, numFa($unitPrice),            1, 0, 'C', true);
    $pdf->Cell($colW[5], 7, toPersian(number_format($discPct, 0)), 1, 0, 'C', true);
    $pdf->Cell($colW[6], 7, toPersian(number_format($taxPct,  0)), 1, 0, 'C', true);
    $pdf->Cell($colW[7], 7, numFa($lineTotal),            1, 0, 'C', true);
    $pdf->Ln();

    $rowNum++;
}

// اگر ردیفی نباشد
if (empty($items)) {
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetX($startX);
    $pdf->SetFont('vazirmatn', '', 9);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->Cell(array_sum($colW), 8, 'ردیفی ثبت نشده است', 1, 1, 'C', true);
    $pdf->SetTextColor(30, 30, 30);
}

// ================================================================
// بخش ۳ — جمع‌بندی مالی
// ================================================================
$pdf->Ln(4);

// جعبه جمع‌بندی — سمت چپ (در RTL یعنی گوشه راست صفحه)
$boxW = 86;
$boxX = 12; // چون RTL است، این سمت چپ فیزیکی است اما RTL آن را راست نشان می‌دهد
$pdf->SetX($boxX);
$pdf->SetFillColor(248, 250, 255);
$pdf->SetDrawColor(180, 195, 220);
$pdf->SetLineWidth(0.3);

$currentY = $pdf->GetY();
$pdf->RoundedRect($boxX, $currentY, $boxW, 46, 2, '1111', 'DF');

$pdf->SetFont('vazirmatn', '', 9);
$pdf->SetTextColor(60, 60, 60);
$labelW = 38;
$valW   = 44;

// جمع کالاها
$pdf->SetXY($boxX + 2, $currentY + 3);
$pdf->Cell($labelW, 7, 'جمع کالاها:', 0, 0, 'R');
$pdf->Cell($valW,   7, numFa($subtotal) . ' ریال', 0, 1, 'L');

// تخفیف
$pdf->SetX($boxX + 2);
$pdf->SetTextColor(200, 50, 50);
$pdf->Cell($labelW, 7, 'تخفیف:', 0, 0, 'R');
$pdf->Cell($valW,   7, '( ' . numFa($discountAmt) . ' ریال )', 0, 1, 'L');
$pdf->SetTextColor(60, 60, 60);

// مالیات
$pdf->SetX($boxX + 2);
$pdf->Cell($labelW, 7, 'مالیات:', 0, 0, 'R');
$pdf->Cell($valW,   7, numFa($taxAmt) . ' ریال', 0, 1, 'L');

// حمل
if ($shippingAmt > 0) {
    $pdf->SetX($boxX + 2);
    $pdf->Cell($labelW, 7, 'هزینه حمل:', 0, 0, 'R');
    $pdf->Cell($valW,   7, numFa($shippingAmt) . ' ریال', 0, 1, 'L');
}

// خط جدا
$pdf->SetDrawColor(160, 175, 210);
$pdf->SetLineWidth(0.4);
$lineY = $pdf->GetY() + 1;
$pdf->Line($boxX + 4, $lineY, $boxX + $boxW - 4, $lineY);
$pdf->Ln(3);

// مبلغ قابل پرداخت — پررنگ
$pdf->SetFont('vazirmatn', 'B', 11);
$pdf->SetTextColor($headerR, $headerG, $headerB);
$pdf->SetX($boxX + 2);
$pdf->Cell($labelW, 8, 'مبلغ قابل پرداخت:', 0, 0, 'R');
$pdf->Cell($valW,   8, numFa($totalAmount) . ' ریال', 0, 1, 'L');

// ================================================================
// بخش ۴ — توضیحات فاکتور (اگر وجود داشته باشد)
// ================================================================
if (!empty($inv['description'])) {
    $notesY = $currentY;
    $notesX = $boxX + $boxW + 6;
    $notesW = 186 - $boxW - 8;

    $pdf->SetFont('vazirmatn', 'B', 9);
    $pdf->SetTextColor($headerR, $headerG, $headerB);
    $pdf->SetXY($notesX, $notesY);
    $pdf->Cell($notesW, 7, 'توضیحات:', 0, 1, 'R');

    $pdf->SetFont('vazirmatn', '', 8.5);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->SetXY($notesX, $notesY + 7);
    $pdf->SetFillColor(252, 252, 252);
    $pdf->MultiCell($notesW, 5, $inv['description'], 1, 'R', true);
}

// ================================================================
// بخش ۵ — امضاها
// ================================================================
$sigY = max($pdf->GetY() + 8, $currentY + 55);

// سه ستون امضا
$pdf->SetFont('vazirmatn', '', 9);
$pdf->SetTextColor(60, 60, 60);
$pdf->SetDrawColor(180, 195, 220);
$pdf->SetLineWidth(0.3);
$sigBoxH = 22;
$sigBoxW = 56;

// امضای فروشنده/تأمین‌کننده — راست
$pdf->RoundedRect(131, $sigY, $sigBoxW, $sigBoxH, 2, '1111', 'D');
$pdf->SetXY(131, $sigY + 2);
$pdf->Cell($sigBoxW, 5, ($type === 'sell' ? 'امضا و مهر فروشنده' : 'امضا و مهر تأمین‌کننده'), 0, 1, 'C');

// امضای خریدار — وسط
$pdf->RoundedRect(77, $sigY, $sigBoxW, $sigBoxH, 2, '1111', 'D');
$pdf->SetXY(77, $sigY + 2);
$pdf->Cell($sigBoxW, 5, ($type === 'sell' ? 'امضا خریدار' : 'امضا خریدار / واحد مالی'), 0, 1, 'C');

// تأیید مدیریت — چپ
$pdf->RoundedRect(12, $sigY, $sigBoxW - 4, $sigBoxH, 2, '1111', 'D');
$pdf->SetXY(12, $sigY + 2);
$pdf->Cell($sigBoxW - 4, 5, 'تأیید مدیریت', 0, 1, 'C');

// ================================================================
// خروجی PDF
// ================================================================
$filename = 'invoice-' . ($type === 'sell' ? 'sell' : 'buy') . '-' . $id . '.pdf';
$pdf->Output($filename, 'I');
exit;
