<?php
/*
 * فایل: public_html/admin/cartable.php
 * توضیحات: کارتابل نامه‌ها (طراحی تب حرفه‌ای + جدول با اسکرول افقی + فیلتر گیرنده/فرستنده)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$isAdmin = in_array($userRole, ['admin', 'management'], true);

$tab = $_GET['tab'] ?? 'inbox'; // inbox, sent, drafts

// --- دریافت مقادیر فیلتر جستجو ---
$filterType = $_GET['f_type'] ?? '';
$filterSign = $_GET['f_sign'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');
$filterPerson = trim($_GET['f_person'] ?? '');

// خودترمیم دیتابیس
try {
    $pdo->exec("ALTER TABLE `letters` ADD COLUMN IF NOT EXISTS `signature_status` VARCHAR(20) DEFAULT 'none' AFTER `status`");
    $pdo->exec("ALTER TABLE `letters` ADD COLUMN IF NOT EXISTS `use_letterhead` TINYINT(1) DEFAULT 0 AFTER `signature_status`");
} catch (\Exception $e) {}

// --- پردازش درخواست امضا یا ویرایش امضا ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'sign_letter') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");
    try {
        $signLetterId = (int)$_POST['sign_letter_id'];
        
        $stmtCheckType = $pdo->prepare("SELECT type FROM letters WHERE id = ?");
        $stmtCheckType->execute([$signLetterId]);
        if ($stmtCheckType->fetchColumn() !== 'outgoing') {
            throw new Exception("ثبت و ویرایش امضا فقط برای نامه‌های صادره امکان‌پذیر است.");
        }
        
        $enteredPin = trim($_POST['pin_code'] ?? '');
        
        $persianNum = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $arabicNum  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $engNum     = ['0','1','2','3','4','5','6','7','8','9'];
        $enteredPin = str_replace(array_merge($persianNum, $arabicNum), array_merge($engNum, $engNum), $enteredPin);
        
        $signType = $_POST['sign_type'] ?? 'simple';
        $useLetterhead = isset($_POST['use_letterhead']) ? 1 : 0;
        
        $stmtPin = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'signature_pin'");
        $realPinHash = $stmtPin->fetchColumn();
        if (!$realPinHash) $realPinHash = '1234'; 
        
        $isValid = false;
        if (password_verify($enteredPin, $realPinHash)) {
            $isValid = true;
        } elseif ($enteredPin === $realPinHash) {
            $isValid = true;
        }
        
        if (!$isValid) throw new Exception("پین‌کد وارد شده اشتباه است!");

        $pdo->prepare("UPDATE letters SET signature_status = ?, use_letterhead = ? WHERE id = ?")->execute([$signType, $useLetterhead, $signLetterId]);
        $_SESSION['flash_msg'] = "✅ تنظیمات امضا و سربرگ نامه با موفقیت اعمال گردید.";
        
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "❌ خطا: " . $e->getMessage();
    }
    header("Location: cartable.php?tab=sent");
    exit;
}

if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_error'])) { $err = $_SESSION['flash_error']; unset($_SESSION['flash_error']); }

// -------------------------------------------------------------
// ساخت شروط فیلتر برای کوئری‌ها
// -------------------------------------------------------------
$baseWhere = "";
$baseParams = [];

if ($filterType !== '') {
    $baseWhere .= " AND l.type = ? ";
    $baseParams[] = $filterType;
}

if ($filterSign !== '') {
    if ($filterSign === 'signed') {
        $baseWhere .= " AND l.signature_status != 'none' AND l.signature_status IS NOT NULL ";
    } elseif ($filterSign === 'unsigned') {
        $baseWhere .= " AND (l.signature_status = 'none' OR l.signature_status IS NULL) ";
    }
}

if ($searchQuery !== '') {
    $baseWhere .= " AND (l.subject LIKE ? OR l.indicator_number LIKE ?) ";
    $baseParams[] = "%$searchQuery%";
    $baseParams[] = "%$searchQuery%";
}

// -------------------------------------------------------------
// استخراج داده‌ها بر اساس تب فعال
// -------------------------------------------------------------
$items = [];

if ($tab === 'inbox') {
    $sql = "SELECT r.id as ref_id, r.is_read, r.priority, r.created_at as ref_date, 
                   l.id as letter_id, l.subject, l.indicator_number, l.type, l.signature_status,
                   u.first_name as s_fn, u.last_name as s_ln
            FROM letter_referrals r 
            JOIN letters l ON r.letter_id = l.id 
            JOIN users u ON r.sender_id = u.id 
            WHERE r.receiver_id = ? AND r.is_completed = 0 $baseWhere";
    
    $params = array_merge([$userId], $baseParams);
    
    if ($filterPerson !== '') {
        $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ?) ";
        $params[] = "%$filterPerson%";
        $params[] = "%$filterPerson%";
    }
    
    $sql .= " ORDER BY r.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($tab === 'sent') {
    $params = [];
    
    $sql1 = "SELECT r.id as ref_id, r.is_read, r.is_completed, r.created_at as ref_date, 
                    l.id as letter_id, l.subject, l.indicator_number, l.type, l.signature_status,
                    u.first_name as r_fn, u.last_name as r_ln
             FROM letter_referrals r 
             JOIN letters l ON r.letter_id = l.id 
             JOIN users u ON r.receiver_id = u.id 
             WHERE r.sender_id = ? $baseWhere";
    $params[] = $userId;
    foreach($baseParams as $p) $params[] = $p;
    
    if ($filterPerson !== '') {
        $sql1 .= " AND (u.first_name LIKE ? OR u.last_name LIKE ?) ";
        $params[] = "%$filterPerson%";
        $params[] = "%$filterPerson%";
    }

    $sql2 = "SELECT 0 as ref_id, 1 as is_read, 1 as is_completed, l.registered_at as ref_date, 
                    l.id as letter_id, l.subject, l.indicator_number, l.type, l.signature_status,
                    l.receiver_external_name as r_fn, '' as r_ln
             FROM letters l
             WHERE l.created_by = ? AND l.status = 'registered' AND l.type = 'outgoing' $baseWhere";
    $params[] = $userId;
    foreach($baseParams as $p) $params[] = $p;
    
    if ($filterPerson !== '') {
        $sql2 .= " AND l.receiver_external_name LIKE ? ";
        $params[] = "%$filterPerson%";
    }

    $sqlAdmin = "";
    if ($isAdmin) {
        $sqlAdmin = " UNION SELECT 0 as ref_id, 1 as is_read, 1 as is_completed, l.registered_at as ref_date, 
                            l.id as letter_id, l.subject, l.indicator_number, l.type, l.signature_status,
                            l.receiver_external_name as r_fn, '' as r_ln
                     FROM letters l
                     WHERE l.status = 'registered' AND l.type = 'outgoing' $baseWhere";
        
        if ($filterPerson !== '') {
            $sqlAdmin .= " AND l.receiver_external_name LIKE ? ";
        }
        
        foreach($baseParams as $p) $params[] = $p;
        if ($filterPerson !== '') {
            $params[] = "%$filterPerson%";
        }
    }

    $finalSql = "$sql1 UNION $sql2 $sqlAdmin ORDER BY ref_date DESC";
    $stmt = $pdo->prepare($finalSql);
    $stmt->execute($params);
    
    $tempItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $uniqueLetterIds = [];
    foreach ($tempItems as $ti) {
        if (!in_array($ti['letter_id'], $uniqueLetterIds)) {
            $uniqueLetterIds[] = $ti['letter_id'];
            $items[] = $ti;
        }
    }

} elseif ($tab === 'drafts') {
    $sql = "SELECT l.id as letter_id, l.subject, l.type, l.priority, l.classification, l.created_at as ref_date, l.signature_status 
            FROM letters l
            WHERE l.created_by = ? AND l.status = 'draft' $baseWhere";
    
    $params = array_merge([$userId], $baseParams);
    $sql .= " ORDER BY l.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'کارتابل مکاتبات';
$basePath = '../';
$extraCss = '<style>
    * { box-sizing: border-box; }
    body, .main-content, .content-wrapper {
        overflow-x: hidden !important;
        max-width: 100% !important;
    }
    
    /* تب‌بندی حرفه‌ای و مدرن */
    .cartable-tabs { 
        display: flex; gap: 10px; margin-bottom: 20px; 
        background: #f8fafc; padding: 8px; border-radius: 12px;
        border: 1px solid #e2e8f0; overflow-x: auto; white-space: nowrap; 
        -webkit-overflow-scrolling: touch;
    }
    .c-tab { 
        padding: 10px 20px; font-weight: bold; color: #64748b; text-decoration: none; 
        border-radius: 8px; transition: 0.3s all ease; display: inline-flex; align-items: center; gap: 8px;
    }
    .c-tab:hover { background: #e2e8f0; color: #1e293b; }
    .c-tab.active { background: #fff; color: #2563eb; box-shadow: 0 2px 6px rgba(0,0,0,0.05); border: 1px solid #cbd5e1; }

    /* استایل فیلتر جستجو */
    .filter-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; box-shadow: 0 2px 8px rgba(0,0,0,0.02);}
    .filter-col { flex: 1; min-width: 180px; display: flex; flex-direction: column; gap: 6px; }
    .filter-col label { font-size: 0.85rem; font-weight: bold; color: #475569; }
    .filter-col input, .filter-col select { border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; font-family: inherit; font-size: 0.9rem; transition: 0.2s; width: 100%; }
    .filter-col input:focus, .filter-col select:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
    .filter-btn-col { display: flex; gap: 8px; align-items: flex-end; }
    .filter-btn-col button, .filter-btn-col a { padding: 10px 20px; border-radius: 8px; font-weight: bold; font-size: 0.9rem; text-decoration: none; height: 42px; display: inline-flex; align-items: center; justify-content: center;}

    /* کانتینر جدول با اسکرول افقی */
    .table-wrapper {
        width: 100%;
        overflow-x: auto;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
        border-radius: 12px;
        position: relative;
    }
    
    /* استایل جدول دسکتاپ */
    .c-table { 
        width: 100%; 
        min-width: 800px; /* حداقل عرض برای دسکتاپ */
        border-collapse: collapse; 
        background: #fff; 
        border-radius: 12px; 
        box-shadow: 0 4px 6px rgba(0,0,0,0.02);
    }
    
    .c-table thead { background: #f8fafc; }
    .c-table th, .c-table td { 
        padding: 15px; 
        text-align: right; 
        border-bottom: 1px solid #f1f5f9; 
        vertical-align: middle;
        white-space: normal;
        word-wrap: break-word;
        min-width: 120px;
    }
    .c-table th { font-weight: 800; color: #475569; }
    .c-table tr:hover { background: #f8fafc; }
    
    /* ستون عملیات عرض کمتر */
    .c-table th:last-child,
    .c-table td:last-child {
        min-width: 200px;
    }
    
    /* ستون موضوع عرض بیشتر */
    .c-table th:nth-child(3),
    .c-table td:nth-child(3) {
        min-width: 250px;
    }
    
    .badge-let { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; display: inline-block; white-space: nowrap; }
    .bg-internal { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    .bg-outgoing { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .bg-incoming { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
    
    /* استایل دکمه‌های عملیات */
    .action-group { display: flex; gap: 6px; justify-content: center; align-items: center; flex-wrap: wrap; }
    .action-btn { 
        font-family: "Vazirmatn", Tahoma, sans-serif !important; 
        background: #eff6ff; color: #2563eb; padding: 6px 12px; border-radius: 6px; 
        text-decoration: none; font-size: 0.8rem; font-weight: bold; transition: 0.2s; 
        display: inline-flex; align-items: center; justify-content: center; border:none; cursor:pointer; white-space: nowrap;
    }
    .action-btn:hover { background: #3b82f6; color: #fff; }
    .edit-btn { background: #fef3c7; color: #d97706; }
    .edit-btn:hover { background: #f59e0b; color: #fff; }
    .view-btn { background: #f1f5f9; color: #475569; }
    .view-btn:hover { background: #e2e8f0; color: #1e293b; }
    .sign-btn { background: #10b981; color: #fff; border: 1px solid #059669; }
    .sign-btn:hover { background: #059669; transform: translateY(-2px); }
    .edit-sign-btn { background: #2563eb; color: #fff; border: 1px solid #1d4ed8; }
    .edit-sign-btn:hover { background: #1d4ed8; transform: translateY(-2px); }
    
    /* مودال */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-box { background: #fff; width: 90%; max-width: 450px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    .modal-header { padding: 20px; background: #f8fafc; font-weight: 900; font-size:1.1rem; border-bottom: 1px solid #e2e8f0; display:flex; justify-content: space-between; align-items: center;}
    .modal-body { padding: 25px; }
    
    /* شماره نامه */
    .indicator-num {
        direction: ltr;
        display: block;
        font-size: 0.75rem;
        color: #64748b;
        font-weight: bold;
        margin-bottom: 4px;
        font-family: monospace;
    }
    
    .subject-text {
        font-weight: bold;
        color: #0f172a;
        line-height: 1.4;
        word-wrap: break-word;
    }
    
    /* راهنمای اسکرول برای موبایل */
    .scroll-hint {
        display: none;
        text-align: center;
        font-size: 0.7rem;
        color: #94a3b8;
        margin-bottom: 10px;
        padding: 5px;
        background: #f8fafc;
        border-radius: 20px;
        direction: rtl;
    }
    
    /* موبایل و تبلت */
    @media (max-width: 992px) {
        .content-wrapper {
            padding: 10px !important;
        }
        
        .filter-box {
            flex-direction: column;
            align-items: stretch;
            padding: 15px;
            gap: 12px;
        }
        
        .filter-col {
            min-width: auto;
            width: 100%;
        }
        
        .filter-btn-col {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-btn-col button,
        .filter-btn-col a {
            width: 100%;
        }
        
        .cartable-tabs {
            gap: 6px;
            padding: 6px;
        }
        
        .c-tab {
            padding: 8px 14px;
            font-size: 0.85rem;
        }
        
        .page-header {
            flex-direction: column;
            align-items: flex-start !important;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .page-header .btn {
            width: 100%;
            text-align: center;
        }
        
        /* نمایش راهنما فقط در موبایل */
        .scroll-hint {
            display: block;
        }
        
        /* تنظیم جدول برای اسکرول افقی */
        .c-table {
            min-width: 700px;
        }
        
        .c-table th,
        .c-table td {
            padding: 12px 10px;
            font-size: 0.85rem;
        }
        
        .c-table th:last-child,
        .c-table td:last-child {
            min-width: 180px;
        }
        
        .action-btn {
            padding: 5px 10px;
            font-size: 0.75rem;
            white-space: nowrap;
        }
    }
    
    @media (max-width: 768px) {
        .c-table {
            min-width: 650px;
        }
        
        .c-table th:nth-child(3),
        .c-table td:nth-child(3) {
            min-width: 200px;
        }
        
        .action-group {
            flex-direction: column;
            gap: 5px;
        }
        
        .action-btn {
            width: 100%;
            white-space: normal;
            text-align: center;
        }
    }
    
    @media (max-width: 480px) {
        .c-table {
            min-width: 580px;
        }
        
        .c-table th,
        .c-table td {
            padding: 10px 8px;
            font-size: 0.75rem;
        }
        
        .badge-let {
            font-size: 0.65rem;
            white-space: normal;
        }
        
        .indicator-num {
            font-size: 0.65rem;
        }
    }
    
    /* استایل اسکرول بار */
    .table-wrapper::-webkit-scrollbar {
        height: 6px;
    }
    
    .table-wrapper::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 10px;
    }
    
    .table-wrapper::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }
    
    .table-wrapper::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';

function getTypeLabel($type) {
    if($type == 'internal') return ['داخلی', 'bg-internal'];
    if($type == 'outgoing') return ['صادره', 'bg-outgoing'];
    return ['وارده', 'bg-incoming'];
}
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">🗂️ کارتابل مکاتبات</span></div>
            <div><a href="letter_create.php" class="btn btn-primary" style="font-weight:bold;">+ نامه جدید</a></div>
        </div>

        <?php if(!empty($msg)): ?><div class="alert alert-success" id="alertBox"><?php echo $msg; ?></div><?php endif; ?>
        <?php if(!empty($err)): ?><div class="alert alert-error" style="background:#fee2e2; color:#b91c1c;" id="alertBoxErr"><?php echo $err; ?></div><?php endif; ?>

        <form method="GET" class="filter-box page-actions">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div class="filter-col">
                <label>نوع نامه:</label>
                <select name="f_type">
                    <option value="">همه نامه‌ها</option>
                    <option value="internal" <?php if($filterType=='internal') echo 'selected'; ?>>داخلی</option>
                    <option value="outgoing" <?php if($filterType=='outgoing') echo 'selected'; ?>>صادره</option>
                    <option value="incoming" <?php if($filterType=='incoming') echo 'selected'; ?>>وارده</option>
                </select>
            </div>
            <div class="filter-col">
                <label>وضعیت امضا:</label>
                <select name="f_sign">
                    <option value="">همه موارد</option>
                    <option value="signed" <?php if($filterSign=='signed') echo 'selected'; ?>>امضا شده</option>
                    <option value="unsigned" <?php if($filterSign=='unsigned') echo 'selected'; ?>>بدون امضا</option>
                </select>
            </div>
            <div class="filter-col">
                <label>گیرنده / فرستنده:</label>
                <input type="text" name="f_person" placeholder="نام شخص یا شرکت..." value="<?php echo htmlspecialchars($filterPerson); ?>">
            </div>
            <div class="filter-col" style="flex: 1.5;">
                <label>جستجو در موضوع / شماره:</label>
                <input type="text" name="q" placeholder="تایپ کنید..." value="<?php echo htmlspecialchars($searchQuery); ?>">
            </div>
            <div class="filter-btn-col">
                <button type="submit" class="btn btn-primary">جستجو</button>
                <?php if($filterType !== '' || $filterSign !== '' || $searchQuery !== '' || $filterPerson !== ''): ?>
                    <a href="?tab=<?php echo htmlspecialchars($tab); ?>" class="btn btn-outline" style="background:#fff;">حذف فیلتر</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="cartable-tabs">
            <a href="?tab=inbox" class="c-tab <?php echo $tab=='inbox'?'active':''; ?>"><i class="fas fa-inbox"></i> در جریان (ارجاعات من)</a>
            <a href="?tab=sent" class="c-tab <?php echo $tab=='sent'?'active':''; ?>"><i class="fas fa-paper-plane"></i> ارسال شده‌ها</a>
            <a href="?tab=drafts" class="c-tab <?php echo $tab=='drafts'?'active':''; ?>"><i class="fas fa-save"></i> پیش‌نویس‌ها</a>
        </div>

        <!-- راهنمای اسکرول افقی برای موبایل -->
        <div class="scroll-hint">
            👉 برای دیدن اطلاعات بیشتر، جدول را اسکرول کنید 👈
        </div>

        <!-- کانتینر با قابلیت اسکرول افقی -->
        <div class="table-wrapper">
            <table class="c-table">
                <thead>
                    <tr>
                        <?php if($tab == 'inbox'): ?><th>فرستنده</th><?php elseif($tab == 'sent'): ?><th>گیرنده</th><?php else: ?><th>نوع</th><?php endif; ?>
                        <th>نوع نامه</th>
                        <th>شماره / موضوع</th>
                        <th>تاریخ ثبت</th>
                        <th>وضعیت امضا</th>
                        <th style="text-align:center;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($items) == 0): ?>
                        <tr><td colspan="6" style="text-align:center; padding:40px; color:#94a3b8; font-weight:bold;">موردی یافت نشد.</td></tr>
                    <?php else: foreach($items as $i): 
                        list($typeFa, $typeClass) = getTypeLabel($i['type']);
                        $dateFa = $i['ref_date'] ? (function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($i['ref_date'])) : $i['ref_date']) : '---';
                        $isSigned = (!empty($i['signature_status']) && $i['signature_status'] !== 'none');
                        $receiverName = trim(($i['r_fn'] ?? '') . ' ' . ($i['r_ln'] ?? '')) ?: 'مشتری خارجی';
                    ?>
                        <tr>
                            <?php if($tab == 'inbox'): ?>
                                <td>
                                    <?php if($i['is_read'] == 0): ?>
                                        <span style="display:inline-block; width:8px; height:8px; background:#ef4444; border-radius:50%; margin-left:6px;"></span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($i['s_fn'].' '.$i['s_ln']); ?>
                                </td>
                            <?php elseif($tab == 'sent'): ?>
                                <td><?php echo htmlspecialchars($receiverName); ?></td>
                            <?php else: ?>
                                <td>پیش‌نویس</td>
                            <?php endif; ?>
                            
                            <td><span class="badge-let <?php echo $typeClass; ?>"><?php echo $typeFa; ?></span></td>
                            
                            <td>
                                <?php if(!empty($i['indicator_number'])): ?>
                                    <div class="indicator-num"><?php echo htmlspecialchars($i['indicator_number']); ?></div>
                                <?php endif; ?>
                                <div class="subject-text"><?php echo htmlspecialchars($i['subject']); ?></div>
                            </td>
                            
                            <td><span style="direction:ltr; display:inline-block; font-size:0.8rem; color:#64748b;"><?php echo $dateFa; ?></span></td>
                            
                            <td>
                                <?php if($isSigned): ?>
                                    <span class="badge-let" style="background:#ecfeff; color:#0f766e; border:1px solid #a5f3fc;">✅ امضا شده</span>
                                <?php else: ?>
                                    <span class="badge-let" style="background:#f1f5f9; color:#475569;">⭕ بدون امضا</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="action-group">
                                    <?php if($tab == 'drafts'): ?>
                                        <a href="letter_edit.php?id=<?php echo $i['letter_id']; ?>" class="action-btn edit-btn">✏️ ویرایش</a>
                                    <?php elseif($tab == 'sent'): ?>
                                        <a href="letter_view.php?id=<?php echo $i['letter_id']; ?>" class="action-btn view-btn">👁️ مشاهده</a>
                                        
                                        <?php if($i['type'] == 'outgoing' && $isAdmin): ?>
                                            <?php if(!$isSigned): ?>
                                                <button type="button" class="action-btn sign-btn" onclick="openSignModal(<?php echo $i['letter_id']; ?>)">🖊️ ثبت امضا</button>
                                            <?php else: ?>
                                                <button type="button" class="action-btn edit-sign-btn" onclick="openSignModal(<?php echo $i['letter_id']; ?>)">✏️ تغییر امضا</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                    <?php else: ?>
                                        <a href="letter_view.php?id=<?php echo $i['letter_id']; ?>&ref_id=<?php echo $i['ref_id'] ?? 0; ?>" class="action-btn">👁️ مشاهده و اقدام</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div id="signModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <span>✍️ تنظیمات امضای الکترونیکی</span>
            <span style="cursor:pointer; color:#ef4444; font-size:24px;" onclick="document.getElementById('signModal').style.display='none'">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action_type" value="sign_letter">
                <input type="hidden" name="sign_letter_id" id="mdl_sign_letter_id">
                
                <div style="margin-bottom:15px;">
                    <label style="font-weight:bold; font-size:0.85rem; color:#475569; display:block; margin-bottom:5px;">نوع درج امضا در پرینت</label>
                    <select name="sign_type" class="form-control" style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-family:inherit;">
                        <option value="simple" selected>متن ساده (نام و سمت بدون عکس)</option>
                        <option value="sign">تصویر امضا + متن</option>
                        <option value="stamp">مهر شرکت + متن</option>
                        <option value="both">تصویر امضا + مهر شرکت + متن</option>
                    </select>
                </div>

                <div style="margin-bottom:15px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:bold; font-size:0.85rem; color:#0f766e; background:#ecfeff; padding:12px; border-radius:8px; border:1px solid #a5f3fc;">
                        <input type="checkbox" name="use_letterhead" value="1" style="width:20px; height:20px;">
                        🖨️ چاپ نامه روی سربرگ شرکت (پس‌زمینه رنگی)
                    </label>
                </div>

                <div style="margin-bottom:25px;">
                    <label style="font-weight:bold; font-size:0.85rem; color:#475569; display:block; margin-bottom:5px;">پین‌کد امنیتی مدیر</label>
                    <input type="password" name="pin_code" required class="form-control" placeholder="****" style="width:100%; padding:12px; border-radius:8px; border:1px solid #cbd5e1; text-align:center; letter-spacing:5px; font-size:1.2rem; font-weight:bold;">
                    <small style="color:#64748b; display:block; margin-top:5px; text-align:center;">با وارد کردن پین‌کد می‌توانید تنظیمات را ثبت یا ویرایش کنید.</small>
                </div>

                <div style="display:flex; gap:10px;">
                    <button type="button" class="btn btn-outline" style="flex:1;" onclick="document.getElementById('signModal').style.display='none'">انصراف</button>
                    <button type="submit" class="btn btn-success" style="flex:1; background:#10b981; font-weight:bold;">🔐 اعمال روی نامه</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function openSignModal(id) {
        document.getElementById('mdl_sign_letter_id').value = id;
        document.getElementById('signModal').style.display = 'flex';
    }
    
    // بستن مودال با کلیک روی overlay
    document.getElementById('signModal').addEventListener('click', function(e) {
        if(e.target === this) {
            this.style.display = 'none';
        }
    });
    
    setTimeout(() => {
        if(document.getElementById('alertBox')) document.getElementById('alertBox').remove();
        if(document.getElementById('alertBoxErr')) document.getElementById('alertBoxErr').remove();
    }, 5000);
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>