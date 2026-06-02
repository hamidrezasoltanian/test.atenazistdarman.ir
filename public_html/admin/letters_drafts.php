<?php
/*
 * فایل: public_html/admin/letters_drafts.php
 * توضیحات: نمایش پیش‌نویس‌های شخصی کاربر (دکمه‌های عملیات کاملاً در یک ردیف)
 */

ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$pageTitle = '💾 پیش‌نویس‌های من';
$basePath = '../';

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = trim($_GET['q'] ?? '');
$limit = 15;
$offset = ($page - 1) * $limit;

// فقط پیش‌نویس‌های خودش و حذف نشده‌ها
$whereClause = "WHERE status = 'draft' AND created_by = :uid AND is_deleted = 0";
$params = [':uid' => $userId];

if ($search) {
    $whereClause .= " AND (subject LIKE :sq)";
    $params[':sq'] = "%$search%";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM letters $whereClause");
$countStmt->execute($params);
$totalLetters = $countStmt->fetchColumn();
$totalPages = ceil($totalLetters / $limit);

$sql = "SELECT id, subject, type, priority, classification, created_at 
        FROM letters 
        $whereClause 
        ORDER BY created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$letters = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getTypeFa($type) {
    $arr = ['internal'=>'داخلی', 'outgoing'=>'صادره', 'incoming'=>'وارده'];
    return $arr[$type] ?? 'نامشخص';
}

$extraCss = '<style>
    /* تعریف متغیرهای رنگی و استایل بیس */
    :root {
        --primary: #2563eb;
        --primary-hover: #1d4ed8;
        --bg-light: #f8fafc;
        --border-color: #e2e8f0;
        --text-dark: #0f172a;
        --text-muted: #64748b;
        --danger: #dc2626;
        --danger-bg: #fef2f2;
        --warning: #d97706;
        --warning-bg: #fef3c7;
    }

    * { box-sizing: border-box; font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    
    body { background-color: #f1f5f9; overflow-x: hidden; }

    .content-wrapper { max-width: 1600px; margin: 0 auto; width: 100%; padding: 1rem; }
    .main-card { background: #fff; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border-color); }

    .page-header-flex { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
    .page-title { font-size: 1.25rem; font-weight: 800; color: var(--text-dark); }

    .search-box { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.5rem; align-items: stretch; width: 100%; }
    .search-input { flex: 1 1 250px; min-width: 0; padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; font-size: 0.95rem; transition: 0.3s; }
    .search-input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
    .search-btn, .clear-btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: bold; text-decoration: none; cursor: pointer; transition: 0.2s; white-space: nowrap; }
    .search-btn { background: var(--primary); color: #fff; border: none; }
    .search-btn:hover { background: var(--primary-hover); }
    .clear-btn { background: #fff; color: var(--text-muted); border: 1px solid var(--border-color); }
    .clear-btn:hover { background: var(--bg-light); color: var(--text-dark); }

    .table-container { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 0.75rem; border: 1px solid var(--border-color); }
    .l-table { width: 100%; border-collapse: collapse; min-width: 800px; text-align: right; }
    .l-table th, .l-table td { padding: 1rem; border-bottom: 1px solid var(--border-color); vertical-align: middle; word-break: break-word; }
    .l-table th { background: var(--bg-light); font-weight: 800; color: var(--text-muted); white-space: nowrap; }
    .l-table tr:hover { background: var(--bg-light); }

    .status-badge { padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: bold; display: inline-flex; align-items: center; justify-content: center; }
    .st-internal { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;}
    .st-outgoing { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;}
    .st-incoming { background: #fef9c3; color: #854d0e; border: 1px solid #fde047;}

    /* دکمه‌های عملیات جدول (همیشه در یک خط nowrap) */
    .btn-group-row { display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: nowrap !important; }
    .action-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.25rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.85rem; font-weight: bold; text-decoration: none; border: 1px solid transparent; transition: 0.2s; white-space: nowrap; cursor: pointer; flex: 1; text-align: center; }
    .btn-edit { background: var(--warning-bg); color: var(--warning); border-color: #fde047; }
    .btn-edit:hover { background: #fde047; }
    .btn-delete { background: var(--danger-bg); color: var(--danger); border-color: #fecaca; }
    .btn-delete:hover { background: #fecaca; }

    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 0.5rem; margin-top: 1.5rem; }
    .page-link { padding: 0.5rem 1rem; border: 1px solid var(--border-color); border-radius: 0.5rem; text-decoration: none; color: var(--text-muted); background: #fff; font-weight: bold; transition: 0.2s;}
    .page-link:hover { background: var(--bg-light); color: var(--primary); }
    .page-link.active { background: var(--primary); color: #fff; border-color: var(--primary); }

    .btn-primary-new { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background: var(--primary); color: white; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: bold; text-decoration: none; border: none; transition: 0.2s; }
    .btn-primary-new:hover { background: var(--primary-hover); }

    @media (max-width: 992px) {
        .content-wrapper { padding: 0.75rem; }
        .main-card { padding: 1rem; }
        .search-box { flex-direction: column; }
        .search-btn, .clear-btn { width: 100%; }
        .table-container { border-radius: 0.5rem; }
    }

    @media (max-width: 768px) {
        .page-header-flex { flex-direction: column; align-items: stretch; text-align: center; }
        .btn-primary-new { width: 100%; }
        
        .table-container { border: none; background: transparent; overflow-x: visible; }
        .l-table { min-width: 100%; display: block; }
        .l-table thead { display: none; }
        .l-table tbody { display: block; width: 100%; }
        .l-table tr { display: flex; flex-direction: column; width: 100%; background: #fff; border: 1px solid var(--border-color); border-radius: 0.75rem; margin-bottom: 1rem; padding: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        
        .l-table td { 
            display: flex; 
            flex-direction: column; 
            align-items: flex-start;
            gap: 0.25rem;
            text-align: right; 
            padding: 0.75rem 0; 
            border-bottom: 1px dashed var(--border-color); 
            border-top: none; 
            border-left: none; 
            border-right: none;
            width: 100%;
        }
        .l-table td:last-child { border-bottom: none; padding-bottom: 0; padding-top: 1rem; align-items: stretch; }
        
        .l-table td::before { 
            content: attr(data-label); 
            font-weight: 800; 
            color: var(--text-muted); 
            font-size: 0.8rem;
            background: var(--bg-light);
            padding: 0.15rem 0.5rem;
            border-radius: 0.25rem;
            display: inline-block;
            margin-bottom: 0.25rem;
        }

        /* قفل کردن دکمه‌ها برای اینکه در موبایل هم دقیقاً در یک ردیف باشند */
        .btn-group-row { flex-direction: row !important; flex-wrap: nowrap !important; width: 100%; gap: 8px; }
        .btn-group-row > a.action-btn, 
        .btn-group-row > button.action-btn { flex: 1; display: flex; margin: 0; min-width: 0; font-size: 0.8rem; padding: 0.6rem 0.25rem; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        
        <div class="page-header-flex">
            <div class="page-title">💾 لیست پیش‌نویس‌های من</div>
            <a href="letter_create.php" class="btn-primary-new">
                <i class="fas fa-plus"></i> ایجاد نامه جدید
            </a>
        </div>

        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'draft_saved'): ?>
            <div class="alert alert-success fw-bold" style="border-radius: 0.5rem; margin-bottom: 1.5rem;">✅ پیش‌نویس شما با موفقیت ذخیره شد.</div>
        <?php endif; ?>

        <div class="main-card">
            
            <form method="GET" class="search-box">
                <input type="text" name="q" class="search-input" placeholder="جستجو در موضوع پیش‌نویس..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="search-btn">
                    <i class="fas fa-search me-2"></i> جستجو
                </button>
                <?php if($search): ?>
                    <a href="letters_drafts.php" class="clear-btn">
                        <i class="fas fa-times me-2"></i> لغو فیلتر
                    </a>
                <?php endif; ?>
            </form>

            <div class="table-container">
                <table class="l-table">
                    <thead>
                        <tr>
                            <th style="width: 45%;">موضوع پیش‌نویس</th>
                            <th style="width: 15%;">نوع نامه</th>
                            <th style="width: 10%;">فوریت</th>
                            <th style="width: 15%;">تاریخ ایجاد</th>
                            <th style="width: 15%; text-align: center;">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($letters) > 0): ?>
                            <?php foreach($letters as $l): 
                                $typeClass = 'st-internal';
                                if($l['type'] == 'outgoing') $typeClass = 'st-outgoing';
                                if($l['type'] == 'incoming') $typeClass = 'st-incoming';
                            ?>
                            <tr>
                                <td data-label="موضوع پیش‌نویس:" style="color: var(--primary); font-weight: bold;">
                                    <?php echo htmlspecialchars($l['subject']); ?>
                                </td>
                                <td data-label="نوع نامه:">
                                    <span class="status-badge <?php echo $typeClass; ?>">
                                        <?php echo getTypeFa($l['type']); ?>
                                    </span>
                                </td>
                                <td data-label="فوریت:">
                                    <span style="font-size: 0.85rem; font-weight: bold; color: var(--text-dark);">
                                        <?php echo ($l['priority'] == 'high' || $l['priority'] == 'immediate') ? '🔴 فوری/آنی' : '⚪ عادی'; ?>
                                    </span>
                                </td>
                                <td data-label="تاریخ ایجاد:">
                                    <span dir="ltr" style="font-size:0.9rem; font-weight:bold; color:var(--text-dark);">
                                        <?php echo function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($l['created_at'])) : $l['created_at']; ?>
                                    </span>
                                </td>
                                <td data-label="عملیات:">
                                    <div class="btn-group-row">
                                        <a href="letter_edit.php?id=<?php echo $l['id']; ?>" class="action-btn btn-edit">
                                            <i class="fas fa-edit"></i> تکمیل و ویرایش
                                        </a>
                                        <a href="letter_delete.php?id=<?php echo $l['id']; ?>" class="action-btn btn-delete" onclick="return confirm('آیا از حذف این پیش‌نویس اطمینان دارید؟')">
                                            <i class="fas fa-trash"></i> حذف
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="padding:3rem; color:var(--text-muted); text-align:center; font-weight:bold;">هیچ پیش‌نویسی در سیستم ندارید.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if($totalPages > 1): ?>
            <div class="pagination">
                <?php for($i=1; $i<=$totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<script>
    setTimeout(() => {
        const alertBox = document.querySelector('.alert-success');
        if (alertBox) alertBox.style.display = 'none';
    }, 5000);
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>