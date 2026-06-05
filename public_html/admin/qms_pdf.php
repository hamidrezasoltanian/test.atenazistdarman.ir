<?php
/*
 * qms_pdf.php — گزارش PDF برای ماژول‌های QMS
 * پارامترها: type=nc|capa|audit&id=<id>
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

if (!in_array($type, ['nc','capa','audit']) || $id <= 0) {
    die('پارامترهای نامعتبر. نوع باید nc یا capa یا audit باشد.');
}

define('K_PATH_FONTS', __DIR__ . '/../../vendor/tcpdf/fonts/');
require_once __DIR__ . '/../../vendor/tcpdf/tcpdf.php';

/* ─── کلاس پایه PDF ─── */
class QmsPDF extends TCPDF {
    public string $docTitle   = '';
    public string $docNumber  = '';
    public string $docDate    = '';
    public array  $hColor     = [30, 80, 160];

    public function Header(): void {
        $this->SetFillColor(...$this->hColor);
        $this->Rect(0, 0, 210, 26, 'F');
        $this->SetTextColor(255,255,255);
        $this->SetFont('vazirmatn','B',14);
        $this->SetXY(0,5);
        $this->Cell(140,9,'آتنا زیست درمان',0,0,'R');
        $this->SetFont('vazirmatn','',9);
        $this->SetXY(0,15);
        $this->Cell(140,6,'سیستم مدیریت کیفیت ISO 13485',0,0,'R');
        $this->SetFont('vazirmatn','B',13);
        $this->SetXY(140,5);
        $this->Cell(60,9,$this->docTitle,0,0,'C');
        $this->SetFont('vazirmatn','',8);
        $this->SetXY(140,15);
        $this->Cell(60,6,$this->docNumber . '  |  ' . $this->docDate,0,0,'C');
    }
    public function Footer(): void {
        $this->SetY(-14);
        $this->SetFont('vazirmatn','',8);
        $this->SetTextColor(150,150,150);
        $this->Cell(0,5,'صفحه '.$this->getAliasNumPage().' از '.$this->getAliasNbPages().'   |   تاریخ چاپ: '.jdate('Y/m/d'),0,0,'C');
    }
}

/* ─── تابع کمکی رسم جدول ─── */
function pdfRow(QmsPDF $pdf, string $label, string $value, bool $gray = false): void {
    if ($gray) $pdf->SetFillColor(248,250,252);
    else        $pdf->SetFillColor(255,255,255);
    $pdf->SetFont('vazirmatn','B',9);
    $pdf->SetTextColor(71,85,105);
    $pdf->Cell(45,8,$label,1,0,'R',true);
    $pdf->SetFont('vazirmatn','',9);
    $pdf->SetTextColor(15,23,42);
    $pdf->Cell(135,8,$value,1,1,'R',true);
}

function pdfSection(QmsPDF $pdf, string $title, array $hColor): void {
    $pdf->SetFillColor(...$hColor);
    $pdf->SetTextColor(255,255,255);
    $pdf->SetFont('vazirmatn','B',10);
    $pdf->Cell(180,8,$title,0,1,'R',true);
    $pdf->SetTextColor(15,23,42);
    $pdf->Ln(1);
}

function pdfTextBlock(QmsPDF $pdf, string $label, string $text): void {
    if (!$text) return;
    $pdf->SetFillColor(248,250,252);
    $pdf->SetFont('vazirmatn','B',9);
    $pdf->SetTextColor(71,85,105);
    $pdf->Cell(180,7,$label,1,1,'R',true);
    $pdf->SetFont('vazirmatn','',9);
    $pdf->SetTextColor(15,23,42);
    $pdf->SetFillColor(255,255,255);
    $pdf->MultiCell(180,6,$text,1,'R',true,1);
    $pdf->Ln(2);
}

/* ─── بارگذاری شرکت ─── */
$companyName = '—';
try {
    $st = $pdo->query("SELECT value FROM settings WHERE `key`='company_name' LIMIT 1");
    $companyName = $st ? ($st->fetchColumn() ?: 'آتنا زیست درمان') : 'آتنا زیست درمان';
} catch (Exception $e) {}

/* ════════════════════════════════════════════
   NC — گزارش محصول نامنطبق
   ════════════════════════════════════════════ */
if ($type === 'nc') {
    $st = $pdo->prepare("
        SELECT n.*,
               s.name  stuff_name,
               u1.name detected_name,
               u2.name disposition_name,
               u3.name closed_name,
               u4.name created_name,
               sr.name storeroom_name
        FROM nc_records n
        LEFT JOIN stuffs s    ON s.id = n.stuff_id
        LEFT JOIN users  u1   ON u1.id = n.detected_by
        LEFT JOIN users  u2   ON u2.id = n.disposition_by
        LEFT JOIN users  u3   ON u3.id = n.closed_by
        LEFT JOIN users  u4   ON u4.id = n.created_by
        LEFT JOIN inv_storerooms sr ON sr.id = n.storeroom_id
        WHERE n.id = ? AND n.is_deleted = 0
    ");
    $st->execute([$id]);
    $nc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$nc) die('رکورد NC یافت نشد.');

    /* لاگ وضعیت */
    $logSt = $pdo->prepare("SELECT l.*, u.name actor_name FROM nc_status_logs l LEFT JOIN users u ON u.id=l.actor_id WHERE l.nc_id=? ORDER BY l.id");
    $logSt->execute([$id]);
    $logs = $logSt->fetchAll(PDO::FETCH_ASSOC);

    $sourceMap = ['incoming_inspection'=>'بازرسی ورودی','in_process'=>'حین فرآیند','customer_return'=>'مرجوعی مشتری','audit_finding'=>'یافته ممیزی','inventory_check'=>'بررسی انبار','expired'=>'منقضی','other'=>'سایر'];
    $dispMap   = ['under_review'=>'در بررسی','quarantine'=>'قرنطینه','rework'=>'دوباره‌کاری','return_to_supplier'=>'مرجوع به تأمین‌کننده','scrap'=>'اسقاط','use_as_is'=>'استفاده با مجوز','concession'=>'امتیاز'];
    $sevMap    = ['critical'=>'بحرانی','major'=>'اصلی','minor'=>'جزئی'];
    $statusMap = ['open'=>'باز','quarantined'=>'قرنطینه','in_disposition'=>'در تصمیم‌گیری','closed'=>'بسته','cancelled'=>'لغوشده'];

    $pdf = new QmsPDF('P','mm','A4',true,'UTF-8',false);
    $pdf->docTitle  = 'گزارش عدم انطباق (NC)';
    $pdf->docNumber = $nc['nc_number'];
    $pdf->docDate   = $nc['detection_date'] ?: jdate('Y/m/d');
    $pdf->hColor    = [220,38,38];
    $pdf->SetCreator('آتنا زیست درمان QMS');
    $pdf->SetTitle('NC: '.$nc['nc_number']);
    $pdf->SetMargins(10,35,10);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(true,20);
    $pdf->AddPage();
    $pdf->setRTL(true);
    $pdf->SetFont('vazirmatn','',10);
    $pdf->Ln(2);

    pdfSection($pdf,'اطلاعات اصلی',[30,58,138]);
    $gray = false;
    pdfRow($pdf,'شماره NC',$nc['nc_number'],$gray); $gray=!$gray;
    pdfRow($pdf,'وضعیت',$statusMap[$nc['status']] ?? $nc['status'],$gray); $gray=!$gray;
    pdfRow($pdf,'شدت',$sevMap[$nc['severity']] ?? $nc['severity'],$gray); $gray=!$gray;
    pdfRow($pdf,'منبع کشف',$sourceMap[$nc['source']] ?? $nc['source'],$gray); $gray=!$gray;
    pdfRow($pdf,'تاریخ کشف',$nc['detection_date'] ?: '—',$gray); $gray=!$gray;
    pdfRow($pdf,'کشف‌کننده',$nc['detected_name'] ?: '—',$gray); $gray=!$gray;
    pdfRow($pdf,'کالا',$nc['stuff_name'] ?: '—',$gray); $gray=!$gray;
    pdfRow($pdf,'شماره بچ',$nc['batch_number'] ?: '—',$gray); $gray=!$gray;
    pdfRow($pdf,'شماره سریال',$nc['serial_number'] ?: '—',$gray); $gray=!$gray;
    pdfRow($pdf,'مقدار',$nc['quantity'].' '.($nc['unit']?:''),$gray); $gray=!$gray;
    pdfRow($pdf,'انبار قرنطینه',$nc['storeroom_name'] ?: '—',$gray); $gray=!$gray;
    $pdf->Ln(4);

    pdfSection($pdf,'تصمیم‌گیری (Disposition)',[55,65,81]);
    pdfRow($pdf,'نوع تصمیم',$dispMap[$nc['disposition']] ?? $nc['disposition']);
    pdfRow($pdf,'تصمیم‌گیرنده',$nc['disposition_name'] ?: '—',true);
    pdfRow($pdf,'تاریخ تصمیم',$nc['disposition_date'] ?: '—');
    $pdf->Ln(4);

    pdfTextBlock($pdf,'شرح عدم انطباق',$nc['description']);
    if ($nc['disposition_notes']) pdfTextBlock($pdf,'یادداشت تصمیم‌گیری',$nc['disposition_notes']);

    if ($nc['customer_notified'] || $nc['regulatory_notification']) {
        pdfSection($pdf,'اطلاع‌رسانی',[30,100,80]);
        pdfRow($pdf,'اطلاع به مشتری',$nc['customer_notified'] ? 'بله — '.$nc['customer_notification_date'] : 'خیر');
        pdfRow($pdf,'اطلاع به MDMA',$nc['regulatory_notification'] ? 'بله' : 'خیر',true);
        $pdf->Ln(4);
    }

    if ($nc['capa_id']) {
        pdfSection($pdf,'CAPA مرتبط',[124,58,237]);
        $capaSt = $pdo->prepare("SELECT capa_number FROM capa_requests WHERE id=?");
        $capaSt->execute([$nc['capa_id']]);
        pdfRow($pdf,'شماره CAPA',$capaSt->fetchColumn() ?: '—');
        $pdf->Ln(4);
    }

    if ($logs) {
        pdfSection($pdf,'لاگ تغییر وضعیت',[71,85,105]);
        $pdf->SetFont('vazirmatn','',8);
        $pdf->SetFillColor(241,245,249);
        $pdf->Cell(30,7,'تاریخ',1,0,'C',true);
        $pdf->Cell(40,7,'توسط',1,0,'C',true);
        $pdf->Cell(50,7,'از وضعیت',1,0,'C',true);
        $pdf->Cell(60,7,'به وضعیت',1,1,'C',true);
        $f = false;
        foreach ($logs as $lg) {
            $pdf->SetFillColor($f ? 255:248, $f ? 255:250, $f ? 255:252);
            $pdf->Cell(30,6,$lg['created_at'],1,0,'C',$f=!$f);
            $pdf->Cell(40,6,$lg['actor_name']??'سیستم',1,0,'C',true);
            $pdf->Cell(50,6,$statusMap[$lg['from_status']??'']??($lg['from_status']??'—'),1,0,'C',true);
            $pdf->Cell(60,6,$statusMap[$lg['to_status']]??$lg['to_status'],1,1,'C',true);
        }
    }

    ob_clean();
    $pdf->Output('NC_'.$nc['nc_number'].'.pdf','I');
    exit;
}

/* ════════════════════════════════════════════
   CAPA — گزارش اقدام اصلاحی/پیشگیرانه
   ════════════════════════════════════════════ */
if ($type === 'capa') {
    $st = $pdo->prepare("
        SELECT c.*,
               u1.name created_name, u2.name owner_name, u3.name verifier_name
        FROM capa_requests c
        LEFT JOIN users u1 ON u1.id=c.created_by
        LEFT JOIN users u2 ON u2.id=c.owner_id
        LEFT JOIN users u3 ON u3.id=c.verifier_id
        WHERE c.id=? AND c.is_deleted=0
    ");
    $st->execute([$id]);
    $capa = $st->fetch(PDO::FETCH_ASSOC);
    if (!$capa) die('رکورد CAPA یافت نشد.');

    $actions = $pdo->prepare("SELECT a.*, u.name responsible_name FROM capa_actions a LEFT JOIN users u ON u.id=a.responsible_id WHERE a.capa_id=? AND a.is_deleted=0 ORDER BY a.id");
    $actions->execute([$id]);
    $actions = $actions->fetchAll(PDO::FETCH_ASSOC);

    $statusLogs = $pdo->prepare("SELECT l.*, u.name actor_name FROM capa_status_logs l LEFT JOIN users u ON u.id=l.actor_id WHERE l.capa_id=? ORDER BY l.id");
    $statusLogs->execute([$id]);
    $statusLogs = $statusLogs->fetchAll(PDO::FETCH_ASSOC);

    $typeLabel   = $capa['type']==='corrective' ? 'اقدام اصلاحی (CA)' : 'اقدام پیشگیرانه (PA)';
    $statusMap   = ['open'=>'باز','investigation'=>'در بررسی','action_plan'=>'برنامه اقدام','implementation'=>'در اجرا','verification'=>'تأیید اثربخشی','closed'=>'بسته','cancelled'=>'لغوشده'];
    $sourceMap   = ['customer_complaint'=>'شکایت مشتری','internal_audit'=>'ممیزی داخلی','nc_record'=>'محصول نامنطبق','management_review'=>'بازنگری مدیریت','process_monitoring'=>'پایش فرآیند','other'=>'سایر'];
    $methodMap   = ['5why'=>'۵ چرا','fishbone'=>'استخوان ماهی','fta'=>'درخت خطا','other'=>'سایر'];
    $hColorCapa  = $capa['type']==='corrective' ? [220,38,38] : [37,99,235];

    $pdf = new QmsPDF('P','mm','A4',true,'UTF-8',false);
    $pdf->docTitle  = $typeLabel;
    $pdf->docNumber = $capa['capa_number'];
    $pdf->docDate   = $capa['detection_date'] ?? jdate('Y/m/d');
    $pdf->hColor    = $hColorCapa;
    $pdf->SetTitle('CAPA: '.$capa['capa_number']);
    $pdf->SetMargins(10,35,10);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(true,20);
    $pdf->AddPage();
    $pdf->setRTL(true);
    $pdf->SetFont('vazirmatn','',10);
    $pdf->Ln(2);

    pdfSection($pdf,'اطلاعات اصلی',[30,58,138]);
    $g=false;
    pdfRow($pdf,'شماره CAPA',$capa['capa_number'],$g); $g=!$g;
    pdfRow($pdf,'نوع',$typeLabel,$g); $g=!$g;
    pdfRow($pdf,'وضعیت',$statusMap[$capa['status']] ?? $capa['status'],$g); $g=!$g;
    pdfRow($pdf,'منبع',$sourceMap[$capa['source']??''] ?? ($capa['source']??'—'),$g); $g=!$g;
    pdfRow($pdf,'تاریخ کشف',$capa['detection_date'] ?? '—',$g); $g=!$g;
    pdfRow($pdf,'سررسید',$capa['target_date'] ?? '—',$g); $g=!$g;
    pdfRow($pdf,'مسئول',$capa['owner_name'] ?? '—',$g); $g=!$g;
    pdfRow($pdf,'روش تحلیل ریشه',$methodMap[$capa['root_cause_method']??''] ?? ($capa['root_cause_method']??'—'),$g); $g=!$g;
    $pdf->Ln(4);

    pdfTextBlock($pdf,'شرح مسئله',$capa['description']??'');
    pdfTextBlock($pdf,'تحلیل ریشه‌ای',$capa['root_cause']??'');
    pdfTextBlock($pdf,'برنامه اقدام',$capa['action_plan']??'');
    pdfTextBlock($pdf,'نتیجه تأیید اثربخشی',$capa['effectiveness_result']??'');

    if ($actions) {
        $pdf->Ln(2);
        pdfSection($pdf,'اقدامات تفصیلی',[55,65,81]);
        $pdf->SetFont('vazirmatn','',8);
        $pdf->SetFillColor(241,245,249);
        $pdf->Cell(80,7,'شرح اقدام',1,0,'R',true);
        $pdf->Cell(40,7,'مسئول',1,0,'C',true);
        $pdf->Cell(30,7,'سررسید',1,0,'C',true);
        $pdf->Cell(30,7,'وضعیت',1,1,'C',true);
        $acStMap = ['pending'=>'در انتظار','in_progress'=>'در جریان','completed'=>'تکمیل','verified'=>'تأیید'];
        $f=false;
        foreach ($actions as $a) {
            $pdf->SetFillColor($f?255:248,$f?255:250,$f?255:252);
            $pdf->MultiCell(80,6,$a['description'],1,'R',$f=!$f,0);
            $pdf->Cell(40,6,$a['responsible_name']??'—',1,0,'C',true);
            $pdf->Cell(30,6,$a['target_date']??'—',1,0,'C',true);
            $pdf->Cell(30,6,$acStMap[$a['status']??'']??($a['status']??'—'),1,1,'C',true);
        }
    }

    if ($statusLogs) {
        $pdf->Ln(4);
        pdfSection($pdf,'تاریخچه وضعیت',[71,85,105]);
        $pdf->SetFont('vazirmatn','',8);
        $pdf->SetFillColor(241,245,249);
        $pdf->Cell(35,7,'تاریخ',1,0,'C',true);
        $pdf->Cell(40,7,'توسط',1,0,'C',true);
        $pdf->Cell(45,7,'از وضعیت',1,0,'C',true);
        $pdf->Cell(60,7,'به وضعیت',1,1,'C',true);
        $f=false;
        foreach ($statusLogs as $lg) {
            $pdf->SetFillColor($f?255:248,$f?255:250,$f?255:252);
            $pdf->Cell(35,6,$lg['created_at'],1,0,'C',$f=!$f);
            $pdf->Cell(40,6,$lg['actor_name']??'—',1,0,'C',true);
            $pdf->Cell(45,6,$statusMap[$lg['from_status']??'']??($lg['from_status']??'—'),1,0,'C',true);
            $pdf->Cell(60,6,$statusMap[$lg['to_status']]??$lg['to_status'],1,1,'C',true);
        }
    }

    ob_clean();
    $pdf->Output('CAPA_'.$capa['capa_number'].'.pdf','I');
    exit;
}

/* ════════════════════════════════════════════
   AUDIT — گزارش ممیزی + یافته‌ها
   ════════════════════════════════════════════ */
if ($type === 'audit') {
    $st = $pdo->prepare("
        SELECT a.*, u.name lead_name
        FROM qms_audit_plans a
        LEFT JOIN users u ON u.id=a.lead_auditor_id
        WHERE a.id=? AND a.is_deleted=0
    ");
    $st->execute([$id]);
    $audit = $st->fetch(PDO::FETCH_ASSOC);
    if (!$audit) die('ممیزی یافت نشد.');

    $findings = $pdo->prepare("
        SELECT f.*, u.name assigned_name, c.capa_number
        FROM qms_audit_findings f
        LEFT JOIN users u ON u.id=f.assigned_to
        LEFT JOIN capa_requests c ON c.id=f.capa_id
        WHERE f.audit_id=? AND f.is_deleted=0 ORDER BY f.finding_type, f.id
    ");
    $findings->execute([$id]);
    $findings = $findings->fetchAll(PDO::FETCH_ASSOC);

    $typeMap   = ['internal'=>'داخلی','external'=>'خارجی','surveillance'=>'نظارتی','certification'=>'صدور گواهی'];
    $statusMap = ['planned'=>'برنامه‌ریزی','in_progress'=>'در جریان','completed'=>'تکمیل','cancelled'=>'لغو'];
    $ftMap     = ['major_nc'=>'NC اصلی','minor_nc'=>'NC جزئی','observation'=>'مشاهده','opportunity'=>'فرصت بهبود'];
    $fStMap    = ['open'=>'باز','in_progress'=>'در پیگیری','closed'=>'بسته','accepted'=>'پذیرفته'];

    $pdf = new QmsPDF('P','mm','A4',true,'UTF-8',false);
    $pdf->docTitle  = 'گزارش ممیزی';
    $pdf->docNumber = $audit['audit_number'];
    $pdf->docDate   = $audit['actual_date'] ?: ($audit['planned_date'] ?: jdate('Y/m/d'));
    $pdf->hColor    = [30,80,160];
    $pdf->SetTitle('Audit: '.$audit['audit_number']);
    $pdf->SetMargins(10,35,10);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(true,20);
    $pdf->AddPage();
    $pdf->setRTL(true);
    $pdf->SetFont('vazirmatn','',10);
    $pdf->Ln(2);

    pdfSection($pdf,'اطلاعات ممیزی',[30,58,138]);
    $g=false;
    pdfRow($pdf,'شماره',$audit['audit_number'],$g); $g=!$g;
    pdfRow($pdf,'عنوان',$audit['title'],$g); $g=!$g;
    pdfRow($pdf,'نوع',$typeMap[$audit['audit_type']] ?? $audit['audit_type'],$g); $g=!$g;
    pdfRow($pdf,'وضعیت',$statusMap[$audit['status']] ?? $audit['status'],$g); $g=!$g;
    pdfRow($pdf,'تاریخ برنامه',$audit['planned_date'] ?: '—',$g); $g=!$g;
    pdfRow($pdf,'تاریخ اجرا',$audit['actual_date'] ?: '—',$g); $g=!$g;
    pdfRow($pdf,'سرممیز',$audit['lead_name'] ?: '—',$g); $g=!$g;
    pdfRow($pdf,'بندهای ISO',$audit['standard_clauses'] ?: '—',$g); $g=!$g;
    $pdf->Ln(4);

    pdfTextBlock($pdf,'دامنه ممیزی',$audit['scope']??'');
    pdfTextBlock($pdf,'معیارهای ممیزی',$audit['criteria']??'');
    pdfTextBlock($pdf,'اعضای تیم',$audit['audit_team']??'');
    pdfTextBlock($pdf,'خلاصه نتایج',$audit['summary']??'');

    if ($findings) {
        $pdf->AddPage();
        pdfSection($pdf,'یافته‌های ممیزی ('.count($findings).' مورد)',[55,65,81]);
        $pdf->SetFont('vazirmatn','',8);
        $cntMajor = count(array_filter($findings, fn($f)=>$f['finding_type']==='major_nc'));
        $cntMinor = count(array_filter($findings, fn($f)=>$f['finding_type']==='minor_nc'));
        $cntObs   = count(array_filter($findings, fn($f)=>$f['finding_type']==='observation'));
        $cntOpp   = count(array_filter($findings, fn($f)=>$f['finding_type']==='opportunity'));
        $pdf->SetFillColor(254,242,242);
        $pdf->Cell(45,6,'NC اصلی: '.$cntMajor,1,0,'C',true);
        $pdf->SetFillColor(255,249,195);
        $pdf->Cell(45,6,'NC جزئی: '.$cntMinor,1,0,'C',true);
        $pdf->SetFillColor(239,246,255);
        $pdf->Cell(45,6,'مشاهده: '.$cntObs,1,0,'C',true);
        $pdf->SetFillColor(240,253,244);
        $pdf->Cell(45,6,'فرصت: '.$cntOpp,1,1,'C',true);
        $pdf->Ln(3);

        foreach ($findings as $i => $f) {
            $pdf->SetFillColor(241,245,249);
            $pdf->SetFont('vazirmatn','B',9);
            $ftLabel = $ftMap[$f['finding_type']] ?? $f['finding_type'];
            $pdf->Cell(180,7,($i+1).'. '.$f['finding_number'].' — '.$ftLabel.' | بند: '.($f['clause_reference']?:'—').' | وضعیت: '.($fStMap[$f['status']]??$f['status']),1,1,'R',true);
            $pdf->SetFont('vazirmatn','',9);
            $pdf->SetFillColor(255,255,255);
            $pdf->MultiCell(180,6,$f['description'],1,'R',true,1);
            if ($f['evidence']) {
                $pdf->SetFont('vazirmatn','',8);
                $pdf->SetFillColor(248,250,252);
                $pdf->Cell(180,5,'شواهد: '.$f['evidence'],1,1,'R',true);
            }
            if ($f['assigned_name'] || $f['target_date'] || $f['capa_number']) {
                $pdf->SetFont('vazirmatn','',8);
                $meta = 'مسئول: '.($f['assigned_name']?:'—').'  |  سررسید: '.($f['target_date']?:'—').($f['capa_number']?'  |  CAPA: '.$f['capa_number']:'');
                $pdf->Cell(180,5,$meta,1,1,'R',true);
            }
            $pdf->Ln(2);
        }
    }

    ob_clean();
    $pdf->Output('Audit_'.$audit['audit_number'].'.pdf','I');
    exit;
}
