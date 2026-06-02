<?php
/*
 * فایل: public_html/admin/letters_archive.php
 * توضیحات: بایگانی هوشمند با رفع خطای PDO (ارور 500)، رسپانسیو خالص، پرینت استاندارد، ستون گیرندگان و قابلیت مرتب‌سازی
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$isAdmin = (in_array($_SESSION['role'] ?? '', ['admin', 'manager']));

$msg = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'restored') {
    $msg = "✅ نامه از بایگانی خارج شد و به چرخه کارتابل بازگشت.";
}

// پردازش عملیات بازگردانی
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_letter'])) {
    if (!$isAdmin) die("دسترسی غیرمجاز");
    $letterId = (int)$_POST['letter_id'];
    
    $stmtCheck = $pdo->prepare("SELECT type FROM letters WHERE id = ?");
    $stmtCheck->execute([$letterId]);
    $lType = $stmtCheck->fetchColumn();
    
    if ($lType === 'outgoing') {
        $pdo->prepare("UPDATE letters SET is_archived = 0, status = 'approved_for_sign' WHERE id = ?")->execute([$letterId]);
        if(function_exists('logSystem')) logSystem('Letters', 'restore_archive', $letterId, "بازگشت نامه صادره به صف امضا");
    } else {
        $pdo->prepare("UPDATE letters SET is_archived = 0, status = 'pending_action' WHERE id = ?")->execute([$letterId]);
        if(function_exists('logSystem')) logSystem('Letters', 'restore_archive', $letterId, "بازگشت نامه به چرخه کارتابل");
    }
    header("Location: letters_archive.php?msg=restored");
    exit;
}

// --- پارامترهای صفحه‌بندی، فیلتر و مرتب‌سازی ---
$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$searchSubject = $_GET['search_subject'] ?? '';
$searchIndicator = $_GET['search_indicator'] ?? '';
$searchType = $_GET['search_type'] ?? '';
$dateFromFa = trim($_GET['date_from_fa'] ?? '');
$dateToFa = trim($_GET['date_to_fa'] ?? '');

$dateFrom = '';
$dateTo = '';

// تبدیل تاریخ شمسی ورودی به میلادی برای دیتابیس
if (!empty($dateFromFa)) {
    $parts = explode('/', faToEn($dateFromFa));
    if (count($parts) === 3) {
        $g = jalali_to_gregorian($parts[0], $parts[1], $parts[2]);
        $dateFrom = $g[0] . '-' . sprintf('%02d', $g[1]) . '-' . sprintf('%02d', $g[2]);
    }
}
if (!empty($dateToFa)) {
    $parts = explode('/', faToEn($dateToFa));
    if (count($parts) === 3) {
        $g = jalali_to_gregorian($parts[0], $parts[1], $parts[2]);
        $dateTo = $g[0] . '-' . sprintf('%02d', $g[1]) . '-' . sprintf('%02d', $g[2]);
    }
}

// تنظیمات مرتب‌سازی (Sort)
$allowedSortCols = ['indicator_number', 'type', 'subject', 'created_at'];
$sortCol = isset($_GET['sort']) && in_array($_GET['sort'], $allowedSortCols) ? $_GET['sort'] : 'created_at';
$sortDir = isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC' ? 'ASC' : 'DESC';

// تابعی برای ساخت لینک مرتب‌سازی
$queryParams = $_GET;
unset($queryParams['page'], $queryParams['sort'], $queryParams['dir']);
function getSortLink($col, $currentSort, $currentDir, $queryParams) {
    $newDir = ($currentSort === $col && $currentDir === 'DESC') ? 'ASC' : 'DESC';
    $icon = ($currentSort === $col) ? ($currentDir === 'ASC' ? ' <i class="fas fa-sort-up text-primary"></i>' : ' <i class="fas fa-sort-down text-primary"></i>') : ' <i class="fas fa-sort text-muted" style="opacity:0.3;"></i>';
    $params = array_merge($queryParams, ['sort' => $col, 'dir' => $newDir]);
    return ['url' => '?' . http_build_query($params), 'icon' => $icon];
}

// --- ساخت کوئری داینامیک ---
$where = " WHERE l.is_archived = 1 AND (l.is_deleted = 0 OR l.is_deleted IS NULL) ";
$params = [];

// امنیت: محدودیت مشاهده نامه‌های محرمانه و سری برای غیر ادمین
if (!$isAdmin) {
    $where .= " AND l.classification NOT IN ('confidential', 'secret') ";
    $where .= " AND (l.created_by = :uid1 OR l.id IN (SELECT letter_id FROM letter_referrals WHERE receiver_id = :uid2 OR sender_id = :uid3)) ";
    $params[':uid1'] = $userId;
    $params[':uid2'] = $userId;
    $params[':uid3'] = $userId;
}

// فیلترهای جستجو
if ($searchSubject) {
    $where .= " AND l.subject LIKE :subj ";
    $params[':subj'] = "%$searchSubject%";
}
if ($searchIndicator) {
    $where .= " AND l.indicator_number LIKE :ind ";
    $params[':ind'] = "%$searchIndicator%";
}
if ($searchType) {
    $where .= " AND l.type = :type ";
    $params[':type'] = $searchType;
}
if ($dateFrom) {
    $where .= " AND l.created_at >= :dfrom ";
    $params[':dfrom'] = $dateFrom . " 00:00:00";
}
if ($dateTo) {
    $where .= " AND l.created_at <= :dto ";
    $params[':dto'] = $dateTo . " 23:59:59";
}

// ساب‌کوئری استخراج گیرندگان
$receiversSubQuery = "(SELECT GROUP_CONCAT(COALESCE(c.company_name, CONCAT(ur.first_name, ' ', ur.last_name)) SEPARATOR '، ') 
                       FROM letter_receivers lr 
                       LEFT JOIN users ur ON lr.receiver_type = 'user' AND lr.receiver_id = ur.id 
                       LEFT JOIN customers c ON lr.receiver_type = 'external' AND lr.receiver_id = c.id 
                       WHERE lr.letter_id = l.id) as receivers_names";

// --- خروجی اکسل ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Archive_Report_'.date('Y-m-d').'.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($output, ['ایجاد کننده', 'شماره اندیکاتور', 'موضوع', 'گیرندگان', 'نوع', 'تاریخ ثبت (شمسی)', 'طبقه بندی']);
    
    $stmtEx = $pdo->prepare("SELECT l.*, u.first_name, u.last_name, $receiversSubQuery 
                             FROM letters l LEFT JOIN users u ON l.created_by = u.id 
                             $where ORDER BY l.$sortCol $sortDir");
    $stmtEx->execute($params);
    while($row = $stmtEx->fetch(PDO::FETCH_ASSOC)) {
        $typeFa = ($row['type'] == 'internal' ? 'داخلی' : ($row['type'] == 'outgoing' ? 'صادره' : 'وارده'));
        $classFa = ($row['classification'] == 'confidential' ? 'محرمانه' : ($row['classification'] == 'secret' ? 'سری' : 'عادی'));
        $dateFa = function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($row['created_at'])) : $row['created_at'];
        
        fputcsv($output, [
            $row['first_name'].' '.$row['last_name'],
            $row['indicator_number'],
            $row['subject'],
            $row['receivers_names'] ?: 'نامشخص',
            $typeFa,
            $dateFa,
            $classFa
        ]);
    }
    fclose($output);
    exit;
}

// --- اجرای کوئری با صفحه‌بندی و مرتب‌سازی ---
$countSql = "SELECT COUNT(*) FROM letters l $where";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalItems = $stmtCount->fetchColumn();
$totalPages = ceil($totalItems / $limit);

$dataSql = "SELECT l.*, u.first_name, u.last_name, $receiversSubQuery
            FROM letters l 
            LEFT JOIN users u ON l.created_by = u.id 
            $where 
            ORDER BY l.$sortCol $sortDir 
            LIMIT $limit OFFSET $offset";
$stmtData = $pdo->prepare($dataSql);
$stmtData->execute($params);
$archivedLetters = $stmtData->fetchAll(PDO::FETCH_ASSOC);

// آماده‌سازی اطلاعات چاپ
$printDate = function_exists('jdate') ? jdate('Y/m/d H:i') : date('Y-m-d H:i');
$printUser = htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

$pageTitle = '🗄️ بایگانی کل نامه‌ها';
$basePath = '../';

$extraCss = '
<link rel="stylesheet" href="../vendor/kamaDatepicker/kamadatepicker.min.css">
<style>
    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    
    .filter-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    .filter-wrapper { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .filter-item { flex: 1; min-width: 160px; }
    .filter-btn { flex: 0 0 auto; width: 120px; }
    
    .table-container { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px;}
    
    /* استایل استاندارد جدول */
    .l-table { width: 100%; border-collapse: collapse; background: #fff; min-width: 1000px; table-layout: auto; }
    .l-table th, .l-table td { padding: 12px 10px; text-align: right; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .l-table th { background: #f8fafc; font-weight: 800; color: #475569; }
    .l-table tr:hover { background: #f8fafc; }
    
    /* تنظیم عرض ستون‌ها */
    .col-creator { width: 12%; }
    .col-ind { width: 14%; }
    .col-subj { width: 22%; }
    .col-rec { width: 20%; line-height: 1.6; }
    .col-type { width: 8%; }
    .col-date { width: 10%; }
    .col-class { width: 6%; }
    .col-actions { width: 8%; text-align: center; }

    /* لینک هدرها برای مرتب‌سازی */
    .l-table th a { color: #475569; text-decoration: none; display: flex; justify-content: space-between; align-items: center; }
    .l-table th a:hover { color: #2563eb; }

    /* ابزارهای کمکی ستون‌ها */
    .status-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; }
    .btn-group-row { display: flex; align-items: center; justify-content: center; gap: 5px; flex-wrap: nowrap; }
    .action-btn { padding: 6px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 4px; border: none; cursor: pointer; text-decoration: none; white-space: nowrap; }
    .btn-view { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
    .btn-restore { background: #fef9c3; color: #b45309; border: 1px solid #fde047; }
    .action-btn:hover { opacity: 0.85; transform: translateY(-1px); }
    
    /* صفحه‌بندی */
    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 5px; margin-top: 20px; }
    .page-link { padding: 8px 16px; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #475569; background: #fff; font-weight: bold; transition: 0.2s;}
    .page-link:hover { background: #f1f5f9; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }
    
    /* کلاس‌های مخفی‌سازی و قالب‌بندی متون */
    .print-only-header { display: none; }
    @media (min-width: 769px) {
        .nowrap-desktop { white-space: nowrap !important; }
        .wrap-subject { white-space: normal !important; word-wrap: break-word !important; line-height: 1.8; }
    }

    @media (max-width: 900px) {
        .filter-item { min-width: 45%; }
        .filter-btn { width: 100%; }
    }

    /* نسخه موبایل جدول */
    @media (max-width: 768px) {
        .content-wrapper { padding: 10px !important; }
        .filter-item { min-width: 100%; }
        
        .table-container { border: none; overflow: hidden; background: transparent; padding: 0;}
        .l-table, .l-table tbody, .l-table tr, .l-table td { display: block; width: 100%; min-width: 100%; }
        .l-table thead { display: none; }
        .l-table tr { margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .l-table td { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #e2e8f0; padding: 10px 0; text-align: left; word-break: break-word; }
        .l-table td:last-child { border-bottom: none; justify-content: center; padding-top: 15px; flex-direction: column; gap: 10px; }
        .l-table td::before { content: attr(data-label); font-weight: bold; color: #64748b; margin-left: 10px; text-align: right; white-space: nowrap; }
        
        .btn-group-row { flex-wrap: wrap; width: 100%; gap: 10px; }
        .action-btn { flex: 1; width: 100%; padding: 10px; }
        .btn-group-row form { width: 100%; margin: 0; }
        .btn-group-row form button { width: 100%; padding: 10px; }
    }

    /* --- استایل استاندارد و حرفه‌ای چاپ --- */
    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        body, html { 
            margin: 0 !important; padding: 0 !important; background: #fff !important; 
            font-size: 11pt !important; color: #000 !important; direction: rtl !important; 
        }
        
        .no-print, header, footer, nav, aside, .sidebar, .main-header, .main-footer, 
        .filter-card, .pagination, .page-header, form { 
            display: none !important; 
        }
        
        .main-content, .content-wrapper, .card { 
            margin: 0 !important; padding: 0 !important; width: 100% !important; 
            border: none !important; box-shadow: none !important; background: #fff !important; 
        }

        .table-container { overflow: visible !important; border: none !important; margin: 0 !important; padding: 0 !important; }

        /* هدر چاپی */
        .print-only-header { 
            display: flex !important; justify-content: space-between; align-items: center;
            border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; 
        }
        .print-only-header h2 { margin: 0 0 5px 0; font-size: 16pt; font-weight: 900; color: #000; }
        .print-only-header .sub-title { font-size: 11pt; color: #000; }
        .print-only-header .meta { font-size: 10pt; text-align: left; line-height: 1.6; }

        /* لغو استایل موبایل در چاپ و بازگرداندن جدول به حالت عادی */
        .l-table { display: table !important; width: 100% !important; border-collapse: collapse !important; border: 1.5px solid #000 !important; }
        .l-table thead { display: table-header-group !important; }
        .l-table tbody { display: table-row-group !important; }
        .l-table tr { display: table-row !important; page-break-inside: avoid !important; break-inside: avoid !important; }
        
        .l-table th, .l-table td { 
            display: table-cell !important; border: 1px solid #000 !important; 
            padding: 10px 8px !important; color: #000 !important; text-align: center !important; 
            vertical-align: middle !important; 
        }
        .l-table td::before { display: none !important; }

        .l-table th { background-color: #f1f5f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-size: 11pt !important; font-weight: bold !important; }
        .l-table td { font-size: 10pt !important; }
        
        /* تخصیص عرض ستون‌ها در پرینت برای زیبایی بیشتر */
        .col-creator { width: 12% !important; }
        .col-ind { width: 12% !important; }
        .col-subj { width: 30% !important; text-align: right !important; }
        .col-rec { width: 22% !important; text-align: right !important; }
        .col-type { width: 6% !important; }
        .col-date { width: 10% !important; }
        .col-class { width: 8% !important; }

        .status-badge { border: 1px solid #000 !important; color: #000 !important; background: transparent !important; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content">
    <div class="content-wrapper">
        
        <!-- هدر سایت (در چاپ مخفی می‌شود) -->
        <div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div><span class="page-title fw-bold" style="font-size:1.2rem;">🗄️ بایگانی نامه‌ها</span></div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm fw-bold px-3">🖨️ چاپ لیست</button>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-success btn-sm fw-bold px-3">💹 خروجی اکسل</a>
            </div>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-success fw-bold" style="border-radius: 8px;"><?php echo $msg; ?></div>
        <?php endif; ?>

        <!-- بخش فیلترها (در چاپ مخفی می‌شود) -->
        <div class="filter-card">
            <form method="GET">
                <div class="filter-wrapper">
                    <div class="filter-item">
                        <label class="form-label small fw-bold">موضوع نامه</label>
                        <input type="text" name="search_subject" class="form-control" value="<?php echo htmlspecialchars($searchSubject); ?>" placeholder="جستجو در موضوع...">
                    </div>
                    <div class="filter-item">
                        <label class="form-label small fw-bold">شماره اندیکاتور</label>
                        <input type="text" name="search_indicator" class="form-control" value="<?php echo htmlspecialchars($searchIndicator); ?>" placeholder="مثال: الف/د-...">
                    </div>
                    <div class="filter-item">
                        <label class="form-label small fw-bold">نوع نامه</label>
                        <select name="search_type" class="form-control">
                            <option value="">همه موارد</option>
                            <option value="internal" <?php echo $searchType=='internal'?'selected':''; ?>>داخلی</option>
                            <option value="outgoing" <?php echo $searchType=='outgoing'?'selected':''; ?>>صادره</option>
                            <option value="incoming" <?php echo $searchType=='incoming'?'selected':''; ?>>وارده</option>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label class="form-label small fw-bold">از تاریخ (ثبت)</label>
                        <input type="text" name="date_from_fa" id="dateFrom" class="form-control" placeholder="140X/XX/XX" value="<?php echo htmlspecialchars($dateFromFa); ?>" autocomplete="off">
                    </div>
                    <div class="filter-item">
                        <label class="form-label small fw-bold">تا تاریخ (ثبت)</label>
                        <input type="text" name="date_to_fa" id="dateTo" class="form-control" placeholder="140X/XX/XX" value="<?php echo htmlspecialchars($dateToFa); ?>" autocomplete="off">
                    </div>
                    <div class="filter-btn">
                        <button type="submit" class="btn btn-primary w-100 fw-bold" style="height: 38px;">🔍 فیلتر</button>
                    </div>
                </div>
                <!-- فیلدهای مخفی برای حفظ مرتب‌سازی زمان فیلتر -->
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortCol); ?>">
                <input type="hidden" name="dir" value="<?php echo htmlspecialchars($sortDir); ?>">
            </form>
        </div>

        <div class="card" style="padding: 20px; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
            
            <!-- هدر اختصاصی پرینت -->
            <div class="print-only-header">
                <div>
                    <h2>گزارش بایگانی نامه‌ها</h2>
                    <div class="sub-title">سیستم اتوماسیون اداری آتنا زیست درمان</div>
                </div>
                <div class="meta">
                    <div>تاریخ گزارش: <span dir="ltr"><?php echo $printDate; ?></span></div>
                    <div>تهیه کننده: <?php echo $printUser; ?></div>
                </div>
            </div>

            <div class="table-container">
                <table class="l-table">
                    <thead>
                        <tr>
                            <th class="col-creator nowrap-desktop">ایجاد کننده</th>
                            
                            <?php $sInd = getSortLink('indicator_number', $sortCol, $sortDir, $queryParams); ?>
                            <th class="col-ind nowrap-desktop"><a href="<?php echo $sInd['url']; ?>">شماره اندیکاتور <?php echo $sInd['icon']; ?></a></th>
                            
                            <?php $sSubj = getSortLink('subject', $sortCol, $sortDir, $queryParams); ?>
                            <th class="col-subj text-right"><a href="<?php echo $sSubj['url']; ?>">موضوع نامه <?php echo $sSubj['icon']; ?></a></th>
                            
                            <th class="col-rec text-right">گیرندگان</th>
                            
                            <?php $sType = getSortLink('type', $sortCol, $sortDir, $queryParams); ?>
                            <th class="col-type nowrap-desktop"><a href="<?php echo $sType['url']; ?>">نوع <?php echo $sType['icon']; ?></a></th>
                            
                            <?php $sDate = getSortLink('created_at', $sortCol, $sortDir, $queryParams); ?>
                            <th class="col-date nowrap-desktop"><a href="<?php echo $sDate['url']; ?>">تاریخ بایگانی <?php echo $sDate['icon']; ?></a></th>
                            
                            <th class="col-class nowrap-desktop">طبقه‌بندی</th>
                            <th class="col-actions nowrap-desktop no-print">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($archivedLetters as $l): ?>
                        <tr>
                            <td class="nowrap-desktop" data-label="ایجاد کننده:"><?php echo htmlspecialchars($l['first_name'].' '.$l['last_name']); ?></td>
                            <td class="nowrap-desktop" data-label="شماره:"><strong dir="ltr"><?php echo $l['indicator_number'] ?: '---'; ?></strong></td>
                            <td class="wrap-subject fw-bold" style="color:#2563eb;" data-label="موضوع:"><?php echo htmlspecialchars($l['subject']); ?></td>
                            <td class="wrap-subject" style="color:#475569;" data-label="گیرندگان:"><?php echo htmlspecialchars($l['receivers_names'] ?: 'نامشخص'); ?></td>
                            <td class="nowrap-desktop" data-label="نوع:">
                                <?php 
                                    $types = ['internal'=>'داخلی', 'outgoing'=>'صادره', 'incoming'=>'وارده'];
                                    echo '<span style="font-size:0.85rem; font-weight:bold; color:#475569;">'.($types[$l['type']] ?? '---').'</span>';
                                ?>
                            </td>
                            <td class="nowrap-desktop" data-label="تاریخ بایگانی:">
                                <span dir="ltr" class="fw-bold" style="font-size:0.9rem; color:#0f172a;">
                                    <?php 
                                        $ts = strtotime($l['updated_at'] ?: $l['created_at']);
                                        echo function_exists('jdate') ? jdate('Y/m/d', $ts) : date('Y/m/d', $ts);
                                    ?>
                                </span>
                            </td>
                            <td class="nowrap-desktop" data-label="طبقه‌بندی:">
                                <?php 
                                    if($l['classification'] == 'confidential') echo '<span class="status-badge" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca;">محرمانه</span>';
                                    elseif($l['classification'] == 'secret') echo '<span class="status-badge" style="background:#450a0a; color:#fff;">سری</span>';
                                    else echo '<span class="status-badge" style="background:#f1f5f9; color:#475569;">عادی</span>';
                                ?>
                            </td>
                            <td class="no-print" data-label="عملیات:">
                                <div class="btn-group-row">
                                    <a href="letter_view.php?id=<?php echo $l['id']; ?>" class="action-btn btn-view">👁️ مشاهده</a>
                                    <?php if($isAdmin): ?>
                                        <form method="POST" onsubmit="return confirm('از بازگرداندن این نامه به جریان کارتابل اطمینان دارید؟');" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="restore_letter" value="1">
                                            <input type="hidden" name="letter_id" value="<?php echo $l['id']; ?>">
                                            <button type="submit" class="action-btn btn-restore">🔄 بازگشت</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($archivedLetters)) echo '<tr><td colspan="8" class="text-center py-5 fw-bold" style="color:#94a3b8;">موردی با این مشخصات یافت نشد.</td></tr>'; ?>
                    </tbody>
                </table>
            </div>

            <!-- صفحه‌بندی -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination no-print">
                    <?php for ($i = 1; $i <= $totalPages; $i++): 
                        // استفاده از http_build_query برای حفظ کلیه فیلترها و مرتب‌سازی در لینک‌های صفحات
                        $pParams = array_merge($_GET, ['page' => $i]);
                        $pUrl = '?' . http_build_query($pParams);
                    ?>
                        <a href="<?php echo $pUrl; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>">
                           <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script src="../vendor/kamaDatepicker/kamadatepicker.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // مخفی کردن پیام موفقیت
        const alertBox = document.querySelector('.alert-success');
        if (alertBox) {
            setTimeout(() => { alertBox.style.display = 'none'; }, 5000);
        }

        // فعال‌سازی تقویم شمسی
        if (typeof kamaDatepicker === 'function') {
            kamaDatepicker('dateFrom', { forceFarsiDigits: true, markToday: true, gotoToday: true });
            kamaDatepicker('dateTo', { forceFarsiDigits: true, markToday: true, gotoToday: true });
        }
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>