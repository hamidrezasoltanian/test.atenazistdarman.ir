<?php
/*
 * فایل: public_html/admin/letters_outgoing.php
 * توضیحات: مدیریت نامه‌های صادره (حل مشکل نمایش نام گیرندگان چندگانه، فیلتر هوشمند، رسپانسیو، چاپ و شکستن متن‌های طولانی)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// بررسی لاگین و دسترسی
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }
if (function_exists('hasPermission') && !hasPermission('letters_outgoing') && $_SESSION['role'] !== 'admin') {
    die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma; color:red; font-weight:bold;">⛔ شما مجوز مشاهده نامه‌های صادره را ندارید.</div>');
}

$userId = (int)$_SESSION['user_id'];
$isAdmin = (in_array($_SESSION['role'] ?? '', ['admin', 'manager']));

$pageTitle = '📤 نامه‌های صادره';
$letterType = 'outgoing';
$basePath = '../';

// --- پارامترهای صفحه‌بندی و فیلتر ---
$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$searchSubject = $_GET['search_subject'] ?? '';
$searchIndicator = $_GET['search_indicator'] ?? '';
$searchReceiver = $_GET['search_receiver'] ?? '';
$dateFromFa = trim($_GET['date_from_fa'] ?? '');
$dateToFa = trim($_GET['date_to_fa'] ?? '');

$dateFrom = '';
$dateTo = '';

// تبدیل تاریخ شمسی ورودی به میلادی برای جستجو در دیتابیس
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

// --- ساخت کوئری داینامیک ---
$where = " WHERE l.type = 'outgoing' AND (l.is_archived = 0 OR l.is_archived IS NULL) AND (l.is_deleted = 0 OR l.is_deleted IS NULL) ";
$params = [];

// امنیت: محدودیت مشاهده نامه‌های صادره برای غیر ادمین
if (!$isAdmin) {
    $where .= " AND (
        l.created_by = :uid1 
        OR l.id IN (SELECT letter_id FROM letter_signers WHERE user_id = :uid2)
        OR l.id IN (SELECT letter_id FROM letter_referrals WHERE receiver_id = :uid3 OR sender_id = :uid4)
    ) ";
    $params[':uid1'] = $userId;
    $params[':uid2'] = $userId;
    $params[':uid3'] = $userId;
    $params[':uid4'] = $userId;
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
if ($searchReceiver) {
    // جستجو در بین گیرندگان ثبت شده در جدول واسط (letter_receivers) و فیلد متنی قدیمی
    $where .= " AND (
        l.receiver_external_name LIKE :rec 
        OR EXISTS (
            SELECT 1 FROM letter_receivers lr 
            LEFT JOIN customers c ON lr.receiver_type = 'external' AND lr.receiver_id = c.id 
            LEFT JOIN users u2 ON lr.receiver_type = 'user' AND lr.receiver_id = u2.id
            WHERE lr.letter_id = l.id AND (c.company_name LIKE :rec OR u2.last_name LIKE :rec)
        )
    ) ";
    $params[':rec'] = "%$searchReceiver%";
}
if ($dateFrom) {
    $where .= " AND l.created_at >= :dfrom ";
    $params[':dfrom'] = $dateFrom . " 00:00:00";
}
if ($dateTo) {
    $where .= " AND l.created_at <= :dto ";
    $params[':dto'] = $dateTo . " 23:59:59";
}

// --- خروجی اکسل ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Outgoing_Letters_'.date('Y-m-d').'.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($output, ['ایجاد کننده', 'شماره اندیکاتور', 'موضوع', 'گیرنده', 'تاریخ ثبت (شمسی)', 'وضعیت']);
    
    // واکشی گیرندگان با ساب‌کوئری
    $stmtEx = $pdo->prepare("SELECT l.*, u.first_name, u.last_name,
                             (SELECT GROUP_CONCAT(COALESCE(c.company_name, CONCAT(ur.first_name, ' ', ur.last_name)) SEPARATOR '، ') 
                              FROM letter_receivers lr 
                              LEFT JOIN users ur ON lr.receiver_type = 'user' AND lr.receiver_id = ur.id 
                              LEFT JOIN customers c ON lr.receiver_type = 'external' AND lr.receiver_id = c.id 
                              WHERE lr.letter_id = l.id) as receivers_names
                             FROM letters l 
                             LEFT JOIN users u ON l.created_by = u.id 
                             $where ORDER BY l.created_at DESC");
    $stmtEx->execute($params);
    while($row = $stmtEx->fetch(PDO::FETCH_ASSOC)) {
        $statusFa = ($row['status'] == 'draft' ? 'پیش‌نویس' : 'ثبت/در جریان');
        $dateFa = function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($row['created_at'])) : $row['created_at'];
        $recName = $row['receivers_names'] ?: $row['receiver_external_name'];
        
        fputcsv($output, [
            $row['first_name'].' '.$row['last_name'],
            $row['indicator_number'] ?: '---',
            $row['subject'],
            $recName ?: '---',
            $dateFa,
            $statusFa
        ]);
    }
    fclose($output);
    exit;
}

// --- اجرای کوئری با صفحه‌بندی ---
$countSql = "SELECT COUNT(*) FROM letters l $where";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalItems = $stmtCount->fetchColumn();
$totalPages = ceil($totalItems / $limit);

$dataSql = "SELECT l.id, l.subject, l.indicator_number, l.status, l.receiver_external_name, l.registered_at, l.created_at,
                   u.first_name, u.last_name,
                   (SELECT GROUP_CONCAT(COALESCE(c.company_name, CONCAT(ur.first_name, ' ', ur.last_name)) SEPARATOR '، ') 
                    FROM letter_receivers lr 
                    LEFT JOIN users ur ON lr.receiver_type = 'user' AND lr.receiver_id = ur.id 
                    LEFT JOIN customers c ON lr.receiver_type = 'external' AND lr.receiver_id = c.id 
                    WHERE lr.letter_id = l.id) as receivers_names
            FROM letters l 
            LEFT JOIN users u ON l.created_by = u.id 
            $where 
            ORDER BY l.id DESC 
            LIMIT $limit OFFSET $offset";
$stmtData = $pdo->prepare($dataSql);
$stmtData->execute($params);
$letters = $stmtData->fetchAll(PDO::FETCH_ASSOC);

$printDate = function_exists('jdate') ? jdate('Y/m/d H:i') : date('Y-m-d H:i');
$printUser = htmlspecialchars($_SESSION['first_name'] ?? '') . ' ' . htmlspecialchars($_SESSION['last_name'] ?? '');

$extraCss = '
<link rel="stylesheet" href="../vendor/kamaDatepicker/kamadatepicker.min.css">
<style>
    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    
    .filter-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    .filter-wrapper { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .filter-item { flex: 1; min-width: 180px; }
    .filter-btn { flex: 0 0 auto; width: 120px; }

    .table-container { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px;}
    
    /* قفل کردن عرض جدول با fixed */
    .l-table { width: 100%; border-collapse: collapse; background: #fff; min-width: 950px; table-layout: fixed; }
    /* اضافه شدن استایل‌های پیشرفته برای شکستن کلمات و Wrap Text */
    .l-table th, .l-table td { 
        padding: 12px 10px; 
        text-align: right; 
        border-bottom: 1px solid #f1f5f9; 
        vertical-align: middle; 
        white-space: normal !important;
        word-wrap: break-word !important; 
        overflow-wrap: break-word !important;
        word-break: break-word !important;
    }
    .l-table th { background: #f8fafc; font-weight: 800; color: #475569; }
    .l-table tr:hover { background: #f8fafc; }
    
    /* تقسیم بندی دقیق عرض ستون‌ها */
    .col-creator { width: 13%; }
    .col-ind { width: 12%; }
    .col-subj { width: 24%; line-height: 1.6; }
    .col-receiver { width: 17%; }
    .col-date { width: 10%; }
    .col-status { width: 7%; }
    .col-actions { width: 17%; text-align: center; }

    /* کلاس اختصاصی برای متن‌های طولانی */
    .wrap-text {
        white-space: normal !important;
        word-wrap: break-word !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        line-height: 1.8;
    }

    .status-badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; display: inline-block;}
    .st-draft { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .st-registered { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    
    .btn-group-row { display: flex; align-items: center; justify-content: center; gap: 5px; flex-wrap: nowrap; }
    .btn-group-row form { margin: 0; display: flex; align-items: center; }
    .action-btn { padding: 6px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 4px; border: none; cursor: pointer; text-decoration: none; white-space: nowrap; }
    .btn-view { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
    .btn-edit { background: #fef9c3; color: #b45309; border: 1px solid #fde047; }
    .btn-delete { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
    .action-btn:hover { opacity: 0.85; transform: translateY(-1px); }

    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 5px; margin-top: 20px; }
    .page-link { padding: 8px 16px; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #475569; background: #fff; font-weight: bold; transition: 0.2s;}
    .page-link:hover { background: #f1f5f9; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }

    .print-only-header { display: none; }

    @media (max-width: 900px) {
        .filter-item { min-width: 45%; }
        .filter-btn { width: 100%; }
    }

    @media (max-width: 768px) {
        .content-wrapper { padding: 10px !important; overflow-x: hidden; }
        .filter-item { min-width: 100%; }
        
        .table-container { border: none; overflow: visible; background: transparent; padding: 0;}
        .l-table { display: block; table-layout: auto; min-width: 100%; border: none;}
        .l-table tbody, .l-table tr, .l-table td { display: block; width: 100%; box-sizing: border-box;}
        .l-table thead { display: none; }
        .l-table tr { margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; background: #fff; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .l-table td { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #e2e8f0; padding: 10px 0; text-align: left; }
        .l-table td:last-child { border-bottom: none; justify-content: center; padding-top: 15px; flex-direction: column; gap: 10px; }
        .l-table td::before { content: attr(data-label); font-weight: bold; color: #64748b; margin-left: 10px; text-align: right; white-space: nowrap; }
        
        .btn-group-row { flex-direction: row; flex-wrap: nowrap; justify-content: space-between; gap: 10px; width: 100%; }
        .action-btn { flex: 1; text-align: center; justify-content: center; padding: 10px 5px; font-size: 0.9rem; margin: 0; white-space: nowrap; }
    }

    /* --- استایل استاندارد و حرفه‌ای چاپ --- */
    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        body, html { margin: 0 !important; padding: 0 !important; background: #fff !important; font-size: 11pt !important; color: #000 !important; direction: rtl !important; }
        .no-print, header, footer, nav, aside, .sidebar, .main-header, .main-footer, .filter-card, .pagination, .page-header, form { display: none !important; }
        .main-content, .content-wrapper, .card { margin: 0 !important; padding: 0 !important; width: 100% !important; border: none !important; box-shadow: none !important; background: #fff !important; }
        
        .table-container { overflow: visible !important; border: none !important; margin: 0 !important; padding: 0 !important; }

        .print-only-header { display: flex !important; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
        .print-only-header h2 { margin: 0 0 5px 0; font-size: 16pt; font-weight: 900; color: #000; }
        .print-only-header .sub-title { font-size: 11pt; color: #000; }
        .print-only-header .meta { font-size: 10pt; text-align: left; line-height: 1.6; }

        /* اجبار جدول به حفظ ساختار دقیق در پرینت */
        .l-table { display: table !important; width: 100% !important; border-collapse: collapse !important; border: 1.5px solid #000 !important; table-layout: fixed !important; min-width: 100% !important; }
        .l-table thead { display: table-header-group !important; }
        .l-table tbody { display: table-row-group !important; }
        .l-table tr { display: table-row !important; page-break-inside: avoid !important; break-inside: avoid !important; }
        .l-table th, .l-table td { display: table-cell !important; border: 1px solid #000 !important; padding: 8px 5px !important; color: #000 !important; text-align: center !important; vertical-align: middle !important; word-wrap: break-word !important; }
        .l-table td::before { display: none !important; }
        
        .l-table th { background-color: #f1f5f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-size: 11pt !important; font-weight: bold !important; }
        .l-table td { font-size: 10pt !important; }
        .l-table th:last-child, .l-table td:last-child { display: none !important; }
        
        /* توزیع دقیق ستون‌ها برای پرینت */
        .col-creator { width: 15% !important; }
        .col-ind { width: 15% !important; }
        .col-subj { width: 35% !important; text-align: right !important; }
        .col-receiver { width: 15% !important; }
        .col-date { width: 10% !important; }
        .col-status { width: 10% !important; }

        .status-badge { border: 1px solid #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; color: #000 !important; background: transparent !important; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div><span class="page-title fw-bold" style="font-size:1.2rem;">📤 نامه‌های صادره</span></div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="letter_create.php" class="btn btn-primary btn-sm fw-bold px-3">+ نامه جدید</a>
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm fw-bold px-3">🖨️ چاپ لیست</button>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-success btn-sm fw-bold px-3">💹 خروجی اکسل</a>
            </div>
        </div>

        <?php if(isset($_SESSION['flash_success'])): ?>
            <div id="alertMsg" class="alert alert-success fw-bold" style="border-radius: 8px;"><?php echo $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></div>
        <?php endif; ?>
        <?php if(isset($_SESSION['flash_error'])): ?>
            <div id="alertMsg" class="alert alert-danger fw-bold" style="background:#fee2e2; color:#b91c1c; border-color:#fca5a5; border-radius: 8px;"><?php echo $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?></div>
        <?php endif; ?>

        <!-- فیلترها -->
        <div class="filter-card">
            <form method="GET">
                <div class="filter-wrapper">
                    <div class="filter-item">
                        <label class="form-label small fw-bold">موضوع نامه</label>
                        <input type="text" name="search_subject" class="form-control" value="<?php echo htmlspecialchars($searchSubject); ?>" placeholder="جستجو در موضوع...">
                    </div>
                    <div class="filter-item">
                        <label class="form-label small fw-bold">شماره اندیکاتور</label>
                        <input type="text" name="search_indicator" class="form-control" value="<?php echo htmlspecialchars($searchIndicator); ?>" placeholder="شماره...">
                    </div>
                    <div class="filter-item">
                        <label class="form-label small fw-bold">گیرنده</label>
                        <input type="text" name="search_receiver" class="form-control" value="<?php echo htmlspecialchars($searchReceiver); ?>" placeholder="نام شخص یا شرکت...">
                    </div>
                    <div class="filter-item">
                        <label class="form-label small fw-bold">از تاریخ</label>
                        <input type="text" name="date_from_fa" id="dateFrom" class="form-control" placeholder="140X/XX/XX" value="<?php echo htmlspecialchars($dateFromFa); ?>" autocomplete="off">
                    </div>
                    <div class="filter-item">
                        <label class="form-label small fw-bold">تا تاریخ</label>
                        <input type="text" name="date_to_fa" id="dateTo" class="form-control" placeholder="140X/XX/XX" value="<?php echo htmlspecialchars($dateToFa); ?>" autocomplete="off">
                    </div>
                    <div class="filter-btn">
                        <button type="submit" class="btn btn-primary w-100 fw-bold" style="height: 38px;">🔍 فیلتر</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card" style="padding: 20px; background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
            
            <div class="print-only-header">
                <div>
                    <h2>گزارش نامه‌های صادره</h2>
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
                            <th class="col-creator">ایجاد کننده</th>
                            <th class="col-ind">شماره اندیکاتور</th>
                            <th class="col-subj text-right">موضوع نامه</th>
                            <th class="col-receiver">گیرنده</th>
                            <th class="col-date">تاریخ ثبت</th>
                            <th class="col-status">وضعیت</th>
                            <th class="col-actions no-print">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($letters) > 0): ?>
                            <?php foreach($letters as $l): 
                                $isDraft = ($l['status'] == 'draft');
                                $statusFa = $isDraft ? 'پیش‌نویس' : 'ثبت / در جریان';
                                $statusClass = $isDraft ? 'st-draft' : 'st-registered';
                                $recDisplay = $l['receivers_names'] ?: $l['receiver_external_name'];
                            ?>
                            <tr>
                                <td data-label="ایجاد کننده:"><?php echo htmlspecialchars($l['first_name'].' '.$l['last_name']); ?></td>
                                <td data-label="شماره:"><strong dir="ltr" style="color:#0f172a;"><?php echo $l['indicator_number'] ?: '---'; ?></strong></td>
                                <td class="fw-bold wrap-text" style="color:#2563eb;" data-label="موضوع:"><?php echo htmlspecialchars($l['subject']); ?></td>
                                <td class="wrap-text" data-label="گیرنده:"><?php echo htmlspecialchars($recDisplay ?: '---'); ?></td>
                                <td data-label="تاریخ:">
                                    <span dir="ltr" style="font-size:0.85rem; color:#64748b; font-weight:bold;">
                                        <?php echo $l['registered_at'] ? (function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($l['registered_at'])) : $l['registered_at']) : (function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($l['created_at'])) : $l['created_at']); ?>
                                    </span>
                                </td>
                                <td data-label="وضعیت:"><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusFa; ?></span></td>
                                <td class="no-print" data-label="عملیات:">
                                    <div class="btn-group-row">
                                        <?php if($isDraft): ?>
                                            <a href="letter_edit.php?id=<?php echo $l['id']; ?>" class="action-btn btn-edit">✏️ ویرایش</a>
                                        <?php else: ?>
                                            <a href="letter_view.php?id=<?php echo $l['id']; ?>" class="action-btn btn-view">👁️ مشاهده</a>
                                            <a href="letter_edit.php?id=<?php echo $l['id']; ?>" class="action-btn btn-edit">✏️ ویرایش</a>
                                        <?php endif; ?>
                                        
                                        <?php if($isAdmin): ?>
                                            <a href="letter_delete.php?id=<?php echo $l['id']; ?>" class="action-btn btn-delete" onclick="return confirm('⚠️ اخطار مهم:\nآیا از حذف کامل این نامه اطمینان دارید؟\nاین عملیات غیرقابل بازگشت است!')">❌ حذف</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="padding:40px; color:#94a3b8; text-align:center; font-weight:bold;">هیچ نامه‌ای یافت نشد.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if($totalPages > 1): ?>
            <div class="pagination no-print">
                <?php for($i=1; $i<=$totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search_subject=<?php echo urlencode($searchSubject); ?>&search_indicator=<?php echo urlencode($searchIndicator); ?>&search_receiver=<?php echo urlencode($searchReceiver); ?>&date_from_fa=<?php echo urlencode($dateFromFa); ?>&date_to_fa=<?php echo urlencode($dateToFa); ?>" 
                       class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<script src="../vendor/kamaDatepicker/kamadatepicker.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // حذف خودکار پیام‌ها
        const alertBox = document.getElementById('alertMsg');
        if (alertBox) { setTimeout(() => alertBox.remove(), 5000); }

        // فعال‌سازی تقویم شمسی
        if (typeof kamaDatepicker === 'function') {
            kamaDatepicker('dateFrom', { forceFarsiDigits: true, markToday: true, gotoToday: true });
            kamaDatepicker('dateTo', { forceFarsiDigits: true, markToday: true, gotoToday: true });
        }
    });
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>