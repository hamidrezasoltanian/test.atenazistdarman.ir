<?php
/*
 * فایل: public_html/admin/missions.php
 * توضیحات: کارتابل ماموریت (باکس فیلتر هوشمند + صفحه‌بندی مستقل ۲۰تایی برای هر جدول)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';

$isAdmin = in_array($userRole, ['admin', 'management'], true);
$isFinance = in_array($userRole, ['finance_expert', 'finance_manager'], true);

$stmtMgr = $pdo->prepare("SELECT id FROM departments WHERE manager_id = ?");
$stmtMgr->execute([$userId]);
$managedDeptIds = $stmtMgr->fetchAll(PDO::FETCH_COLUMN);
$isDeptManager = count($managedDeptIds) > 0;

$filterSql = "";
if ($filter === 'pending') {
    $filterSql = " AND r.status IN ('pending', 'pending_admin')";
} elseif ($filter === 'finance') {
    $filterSql = " AND r.status IN ('expenses_open', 'expenses_submitted', 'finance_review', 'finance_process')";
} elseif ($filter === 'completed') {
    $filterSql = " AND r.status = 'completed'";
} elseif ($filter === 'archive') {
    $filterSql = " AND r.status IN ('completed', 'rejected', 'approved')";
}

// تنظیمات صفحه‌بندی
$limit = 20;
$t_page = isset($_GET['t_page']) ? max(1, (int)$_GET['t_page']) : 1; 
$m_page = isset($_GET['m_page']) ? max(1, (int)$_GET['m_page']) : 1; 
$t_offset = ($t_page - 1) * $limit;
$m_offset = ($m_page - 1) * $limit;

// --- دریافت وظایف (کارتابل مدیریت) ---
$tasks = [];
$totalTasks = 0;
try {
    $customerSubQuery = "(SELECT GROUP_CONCAT(COALESCE(c.company_name, 'آزاد') SEPARATOR '، ') 
                          FROM mission_items mi 
                          LEFT JOIN customers c ON mi.customer_id = c.id 
                          WHERE mi.request_id = r.id) as customer_names";

    if ($isAdmin) {
        $adminBaseWhere = $filter ? "1=1" : "r.status IN ('pending', 'pending_admin', 'finance_review')";
        $where = "$adminBaseWhere $filterSql";
        
        $totalTasks = $pdo->query("SELECT COUNT(*) FROM mission_requests r JOIN users u ON r.user_id = u.id WHERE $where")->fetchColumn();
        
        $sql = "SELECT r.*, u.first_name, u.last_name, 
                (SELECT COUNT(*) FROM mission_items WHERE request_id = r.id) as item_count,
                (SELECT COUNT(*) FROM mission_expenses WHERE request_id = r.id AND status != 'paid') as not_paid_count,
                $customerSubQuery
                FROM mission_requests r
                JOIN users u ON r.user_id = u.id
                WHERE $where
                ORDER BY r.created_at DESC LIMIT $limit OFFSET $t_offset";
        $tasks = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($isDeptManager) {
        $deptPlaceholders = implode(',', array_fill(0, count($managedDeptIds), '?'));
        $deptBaseWhere = $filter ? "1=1" : "r.status IN ('pending', 'pending_admin')";
        $where = "$deptBaseWhere $filterSql AND r.department_id IN ($deptPlaceholders)";
        
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mission_requests r JOIN users u ON r.user_id = u.id WHERE $where");
        $countStmt->execute($managedDeptIds);
        $totalTasks = $countStmt->fetchColumn();

        $sql = "SELECT r.*, u.first_name, u.last_name,
                (SELECT COUNT(*) FROM mission_items WHERE request_id = r.id) as item_count,
                (SELECT COUNT(*) FROM mission_expenses WHERE request_id = r.id AND status != 'paid') as not_paid_count,
                $customerSubQuery
                FROM mission_requests r
                JOIN users u ON r.user_id = u.id
                WHERE $where
                ORDER BY r.created_at DESC LIMIT $limit OFFSET $t_offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($managedDeptIds);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($isFinance) {
        $financeBaseWhere = $filter ? "1=1" : "r.status IN ('finance_review', 'finance_process')";
        $where = "$financeBaseWhere $filterSql";
        
        $totalTasks = $pdo->query("SELECT COUNT(*) FROM mission_requests r JOIN users u ON r.user_id = u.id WHERE $where")->fetchColumn();
        
        $sql = "SELECT r.*, u.first_name, u.last_name,
                (SELECT COUNT(*) FROM mission_items WHERE request_id = r.id) as item_count,
                (SELECT COUNT(*) FROM mission_expenses WHERE request_id = r.id AND status != 'paid') as not_paid_count,
                $customerSubQuery
                FROM mission_requests r
                JOIN users u ON r.user_id = u.id
                WHERE $where
                ORDER BY r.created_at DESC LIMIT $limit OFFSET $t_offset";
        $tasks = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) { $tasks = []; }
$t_totalPages = ceil($totalTasks / $limit);

// --- دریافت درخواست‌های من ---
$myRequests = [];
$totalMyRequests = 0;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mission_requests r WHERE r.user_id = ? $filterSql");
    $countStmt->execute([$userId]);
    $totalMyRequests = $countStmt->fetchColumn();
    $m_totalPages = ceil($totalMyRequests / $limit);

    $stmt = $pdo->prepare("SELECT r.*, 
                           u.first_name, u.last_name,
                           (SELECT COUNT(*) FROM mission_items WHERE request_id = r.id) as item_count,
                           (SELECT GROUP_CONCAT(COALESCE(c.company_name, 'آزاد') SEPARATOR '، ') 
                            FROM mission_items mi 
                            LEFT JOIN customers c ON mi.customer_id = c.id 
                            WHERE mi.request_id = r.id) as customer_names
                           FROM mission_requests r
                           LEFT JOIN users u ON r.user_id = u.id
                           WHERE r.user_id = ? $filterSql
                           ORDER BY r.created_at DESC LIMIT $limit OFFSET $m_offset");
    $stmt->execute([$userId]);
    $myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $myRequests = []; }

function mission_status_label($status, $role = '') {
    // نمایش مرحله دقیق برحسب نقش کاربر
    $mapAdmin = [
        'pending'            => '⏳ در انتظار تأیید مدیر',
        'pending_admin'      => '⏳ در انتظار تأیید مدیریت',
        'approved'           => '✅ تأیید شده',
        'expenses_open'      => '📝 ثبت هزینه توسط کارمند',
        'expenses_submitted' => '📤 ارسال هزینه به مالی',
        'finance_process'    => '💼 در بررسی مالی',
        'finance_review'     => '💼 در بررسی مالی',
        'completed'          => '✔ پایان یافته',
        'rejected'           => '✖ رد شده',
    ];
    $mapEmployee = [
        'pending'            => '⏳ در انتظار تأیید',
        'pending_admin'      => '⏳ در انتظار تأیید',
        'approved'           => '✅ تأیید شده — ثبت هزینه',
        'expenses_open'      => '📝 در حال ثبت هزینه',
        'expenses_submitted' => '📤 ارسال شده به مالی',
        'finance_process'    => '💼 بررسی مالی',
        'finance_review'     => '💼 بررسی مالی',
        'completed'          => '✔ تسویه شده',
        'rejected'           => '✖ رد شده',
    ];
    $map = in_array($role, ['admin','management','finance_expert','finance_manager']) ? $mapAdmin : $mapEmployee;
    return $map[$status] ?? $status;
}

// پله‌های پیشرفت ماموریت برای نمایش Timeline
function mission_step($status) {
    $steps = ['pending','approved','expenses_open','expenses_submitted','finance_review','completed'];
    $cur = array_search($status === 'pending_admin' ? 'pending' : ($status === 'finance_process' ? 'finance_review' : $status), $steps);
    return $cur !== false ? $cur : ($status === 'rejected' ? -1 : 0);
}

$pageTitle = 'کارتابل ماموریت';
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1300px; margin: 0 auto; padding: 0 20px; }
    .table th { font-size: 0.85rem; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table td { font-size: 0.9rem; vertical-align: middle; }
    .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; display: inline-block; white-space: nowrap; font-weight:bold; }
    .status-pending, .status-pending_admin { background: #fef08a; color: #854d0e; border: 1px solid #fde047; }
    .status-approved, .status-expenses_open { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    .status-finance_process, .status-finance_review, .status-expenses_submitted { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
    .status-completed { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .status-rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .content-wrapper * { font-family: "Vazirmatn", Tahoma, sans-serif !important; }
    @media(max-width: 768px) { .container-centered { padding: 0 10px; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">

            <div class="page-header page-actions">
                <div>
                    <span class="page-title">کارتابل ماموریت</span>
                </div>
                <div>
                    <a href="mission_create.php" class="btn btn-primary" style="font-family: 'Vazirmatn', Tahoma, sans-serif !important;">+ درخواست جدید</a>
                </div>
            </div>

            <form method="GET" class="page-actions mb-4" style="background: #fff; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 20px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                <div style="flex: 1; min-width: 250px;">
                    <label class="form-label" style="font-weight:bold; color:#475569; margin-bottom:8px; display:block;">فیلتر وضعیت کارتابل و آرشیو</label>
                    <select name="filter" class="form-control" onchange="this.form.submit()" style="border-radius: 8px; padding: 10px; border:1px solid #cbd5e1; font-family: 'Vazirmatn', Tahoma, sans-serif;">
                        <option value="">صندوق ورودی (نیازمند اقدام من)</option>
                        <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>در انتظار تایید (اولیه/نهایی)</option>
                        <option value="finance" <?php echo $filter === 'finance' ? 'selected' : ''; ?>>در حال امور مالی</option>
                        <option value="archive" <?php echo $filter === 'archive' ? 'selected' : ''; ?>>آرشیو (تایید شده / رد شده / پایان یافته)</option>
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>همه موارد</option>
                    </select>
                </div>
                <div>
                    <a href="missions.php" class="btn btn-outline" style="padding: 10px 20px; font-weight:bold; border-radius: 8px; font-family: 'Vazirmatn', Tahoma, sans-serif;">بازنشانی فیلتر</a>
                </div>
            </form>

            <div class="row">
                <div class="col-md-5 mb-4">
                    <?php if (!empty($tasks)): 
                        $cardTitle = "کارتابل (نیاز به اقدام شما)";
                        $cardIcon = "fa-inbox";
                        if ($filter === 'archive') { $cardTitle = "آرشیو درخواست‌ها"; $cardIcon = "fa-archive"; }
                        elseif ($filter === 'all') { $cardTitle = "همه درخواست‌ها"; $cardIcon = "fa-list"; }
                    ?>
                        <div class="card mb-4 shadow-sm" style="border: 1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
                            <div class="card-header d-flex justify-content-between align-items-center" style="background:#f8fafc; border-bottom:1px solid #e5e7eb;">
                                <strong><i class="fas <?php echo $cardIcon; ?> me-2 text-primary"></i> <?php echo $cardTitle; ?></strong>
                                <span class="badge bg-primary text-white" style="border-radius:20px;"><?php echo number_format($totalTasks); ?> مورد</span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>کارمند / شرکت</th>
                                                <th>وضعیت</th>
                                                <th style="width: 150px;">عملیات</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tasks as $t): ?>
                                                <tr>
                                                    <td>
                                                        <span class="fw-bold d-block text-dark"><?php echo htmlspecialchars($t['first_name'].' '.$t['last_name']); ?></span>
                                                        <small class="text-muted d-block" style="white-space: normal; word-wrap: break-word; line-height: 1.6; min-width: 150px;" title="<?php echo htmlspecialchars($t['customer_names'] ?? '-'); ?>">
                                                            <?php echo htmlspecialchars($t['customer_names'] ?? '-'); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $t['status']; ?>">
                                                            <?php echo mission_status_label($t['status'], $userRole); ?>
                                                        </span>
                                                    </td>
                                                    <td data-label="عملیات">
                                                        <div style="display: flex; gap: 5px;">
                                                            <?php if ($isFinance && in_array($t['status'], ['finance_review', 'finance_process', 'completed'])): ?>
                                                                <?php if ($t['status'] === 'completed'): ?>
                                                                    <?php if (!isset($t['not_paid_count']) || $t['not_paid_count'] > 0): ?>
                                                                        <a href="mission_finance.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-success py-1 px-2" style="font-family: 'Vazirmatn', Tahoma, sans-serif !important;">پرداخت</a>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <a href="mission_finance.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary py-1 px-2" style="font-family: 'Vazirmatn', Tahoma, sans-serif !important;">بررسی مالی</a>
                                                                <?php endif; ?>
                                                                <a href="mission_view.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-family: 'Vazirmatn', Tahoma, sans-serif !important;">مشاهده</a>
                                                            <?php elseif (in_array($t['status'], ['completed', 'rejected', 'approved'])): ?>
                                                                <a href="mission_view.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-family: 'Vazirmatn', Tahoma, sans-serif !important;">مشاهده</a>
                                                            <?php else: ?>
                                                                <a href="mission_view.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary py-1 px-2" style="font-family: 'Vazirmatn', Tahoma, sans-serif !important;">بررسی</a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <?php if ($t_totalPages > 1): ?>
                                <div class="card-footer bg-white border-top">
                                    <div class="pagination" style="display:flex; justify-content:center; gap:5px; flex-wrap:wrap;">
                                        <?php 
                                        $qParams = $_GET; unset($qParams['t_page']); 
                                        for ($i = 1; $i <= $t_totalPages; $i++): 
                                            $qParams['t_page'] = $i;
                                            $qs = http_build_query($qParams);
                                        ?>
                                            <a href="?<?php echo $qs; ?>" class="btn btn-sm <?php echo ($i == $t_page) ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $i; ?></a>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($isAdmin || $isFinance || $isDeptManager): ?>
                        <div class="card border-0 shadow-sm mb-4 d-flex flex-column align-items-center justify-content-center text-center" 
                             style="background-color: #10b981; color: #ffffff; border-radius: 15px; padding: 30px;">
                            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                                <div style="width: 90px; height: 90px; background: rgba(255,255,255,0.2); color: #ffffff; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 25px;">
                                    <i class="fas fa-check fa-4x"></i>
                                </div>
                                <h4 class="fw-bold mb-3">موردی یافت نشد!</h4>
                                <p class="mb-0 fs-6" style="opacity: 0.95;">
                                    در حال حاضر با فیلتر انتخاب شده، هیچ درخواستی در صف انتظار شما وجود ندارد.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="<?php echo ($isAdmin || $isFinance || $isDeptManager) ? 'col-md-7' : 'col-12'; ?>">
                    <div class="card shadow-sm border-0" style="border-radius:12px; overflow:hidden; border:1px solid #e5e7eb;">
                        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                            <strong><i class="fas fa-paper-plane me-2 text-primary"></i> درخواست‌های من</strong>
                            <?php if ($totalMyRequests > 0): ?>
                                <span class="badge bg-secondary text-white" style="border-radius:20px;"><?php echo number_format($totalMyRequests); ?> مورد</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>طرف حساب</th>
                                            <th>آیتم‌ها</th>
                                            <th>وضعیت</th>
                                            <th style="width: 150px;">عملیات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($myRequests)): ?>
                                            <tr><td colspan="5" class="text-center py-5 text-muted">هیچ درخواستی در این بخش وجود ندارد.</td></tr>
                                        <?php else: foreach ($myRequests as $r): ?>
                                            <tr>
                                                <td class="text-muted fw-bold">#<?php echo (int)$r['id']; ?></td>
                                                <td>
                                                    <small class="text-dark fw-bold d-block" style="white-space: normal; word-wrap: break-word; line-height: 1.6; min-width: 150px;"><?php echo htmlspecialchars($r['customer_names'] ?? '-'); ?></small>
                                                    <small class="text-muted" dir="ltr"><?php echo jdate('Y/m/d', strtotime($r['created_at'])); ?></small>
                                                </td>
                                                <td><span style="background:#f1f5f9; padding:2px 8px; border-radius:6px; font-size:0.8rem;"><?php echo $r['item_count']; ?> مورد</span></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $r['status']; ?>">
                                                        <?php echo mission_status_label($r['status'], $userRole); ?>
                                                    </span>
                                                </td>
                                                <td data-label="عملیات">
                                                    <div style="display: flex; gap: 5px;">
                                                        <a href="mission_view.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" style="font-family: 'Vazirmatn', Tahoma, sans-serif !important;">مشاهده</a>
                                                        <?php if($r['status'] == 'expenses_open' || $r['status'] == 'approved'): ?>
                                                            <a href="mission_expenses.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-primary py-1 px-2" title="ثبت هزینه" style="font-family: 'Vazirmatn', Tahoma, sans-serif !important;">هزینه‌ها</a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <?php if ($m_totalPages > 1): ?>
                            <div class="card-footer bg-white border-top">
                                <div class="pagination" style="display:flex; justify-content:center; gap:5px; flex-wrap:wrap;">
                                    <?php 
                                    $qParams = $_GET; unset($qParams['m_page']); 
                                    for ($i = 1; $i <= $m_totalPages; $i++): 
                                        $qParams['m_page'] = $i;
                                        $qs = http_build_query($qParams);
                                    ?>
                                        <a href="?<?php echo $qs; ?>" class="btn btn-sm <?php echo ($i == $m_page) ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $i; ?></a>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری</footer>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>