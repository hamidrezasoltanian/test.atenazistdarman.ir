<?php
/*
 * فایل: public_html/admin/fin_expense_reports.php
 * توضیحات: ارسال صورت تنخواه به مالی + سوابق صورت‌حساب‌ها + حل قطعی باگ تاریخ
 */

ob_start(); session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// --- ⭐ بررسی دسترسی داینامیک ⭐ ---
$isAdmin = false;
$hasAccess = false;

$stmtChk = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$rawRole, $rawRole]);
$roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($roleData) {
    if ($roleData['name'] === 'admin' || $roleData['is_system'] == 1) {
        $isAdmin = true; $hasAccess = true;
    } else {
        $perms = json_decode($roleData['permissions'], true) ?? [];
        if (in_array('fin_expense_reports', $perms) || in_array('all', $perms)) $hasAccess = true;
    }
}

if (!$hasAccess) {
    die('<div style="text-align:center; padding:50px; font-family:Tahoma; color:red; font-weight:bold; line-height:1.6;">⛔ دسترسی غیرمجاز.<br><span style="font-size:0.85rem; color:#64748b;">(دسترسی این بخش باید در مدیریت نقش‌ها برای شما فعال شود)</span></div>');
}

$fiscalYear = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? $fiscalYear['id'] : null;

$msg = ''; $msgType = '';
if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; $msgType = $_SESSION['flash_type'] ?? 'success'; unset($_SESSION['flash_msg'], $_SESSION['flash_type']); }

// ====================================================
// ⭐ پردازش فرم ارسال صورت‌حساب جدید ⭐
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $fiscalYearId) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_report') {
        try {
            $pdo->beginTransaction();
            
            // دریافت تمام فاکتورهای آماده به ارسالِ این کاربر
            $stmt = $pdo->prepare("SELECT id, amount FROM fin_expenses WHERE user_id = ? AND fiscal_year_id = ? AND status = 'pending' FOR UPDATE");
            $stmt->execute([$userId, $fiscalYearId]);
            $pending = $stmt->fetchAll();

            if (count($pending) == 0) throw new Exception('هیچ فاکتور آماده ارسالی وجود ندارد.');

            $totalAmount = 0;
            $expIds = [];
            foreach ($pending as $p) {
                $totalAmount += $p['amount'];
                $expIds[] = $p['id'];
            }

            $title = trim($_POST['title'] ?? '');
            if(empty($title)) {
                $currentDate = function_exists('jdate') ? jdate('Y/m/d') : date('Y/m/d');
                $title = 'صورت‌حساب تنخواه - ' . $currentDate;
            }

            // ۱. ساخت پرونده صورت‌حساب
            $stmtIns = $pdo->prepare("INSERT INTO fin_expense_reports (fiscal_year_id, user_id, title, total_amount, status, sent_at) VALUES (?, ?, ?, ?, 'sent', NOW())");
            $stmtIns->execute([$fiscalYearId, $userId, $title, $totalAmount]);
            $reportId = $pdo->lastInsertId();

            // ۲. متصل کردن فاکتورها به این پرونده و تغییر وضعیت به sent
            $inQuery = implode(',', array_fill(0, count($expIds), '?'));
            $stmtUpd = $pdo->prepare("UPDATE fin_expenses SET report_id = ?, status = 'sent' WHERE id IN ($inQuery)");
            $params = array_merge([$reportId], $expIds);
            $stmtUpd->execute($params);

            // ۳. ارسال اعلان به مدیران مالی
            if(function_exists('send_user_notification')) {
                $stmtFins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'finance_manager', 'finance_expert', 'accountant', '1', '5', '10', '14') AND status = 'active'");
                while ($fUser = $stmtFins->fetch()) {
                    send_user_notification($pdo, $fUser['id'], "📋 صورت تنخواه جدید", "یک صورت‌حساب جدید به مبلغ " . number_format($totalAmount) . " جهت بررسی ارسال شد.", "admin/fin_report_review.php", 'finance', $reportId);
                }
            }

            $pdo->commit();
            $_SESSION['flash_msg'] = "صورت‌حساب با موفقیت ایجاد و برای ممیزی به واحد مالی ارسال شد.";
            $_SESSION['flash_type'] = "success";
            header("Location: fin_expense_reports.php"); exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_msg'] = $e->getMessage(); $_SESSION['flash_type'] = "error";
            header("Location: fin_expense_reports.php"); exit;
        }
    }
}

// --- دریافت فاکتورهای آماده به ارسال ---
$stmtPend = $pdo->prepare("SELECT e.*, c.title as cat_title, a.title as account_title FROM fin_expenses e LEFT JOIN fin_expense_categories c ON e.category_id = c.id LEFT JOIN fin_accounts a ON e.account_id = a.id WHERE e.user_id = ? AND e.fiscal_year_id = ? AND e.status = 'pending' ORDER BY e.invoice_date ASC");
$stmtPend->execute([$userId, $fiscalYearId]);
$pendingExps = $stmtPend->fetchAll(PDO::FETCH_ASSOC);

$totalPendingAmount = 0;
foreach($pendingExps as $p) $totalPendingAmount += $p['amount'];

// --- ⭐ منطق صفحه‌بندی برای سوابق ⭐ ---
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$stmtCount = $pdo->prepare("SELECT COUNT(id) FROM fin_expense_reports WHERE user_id = ? AND fiscal_year_id = ?");
$stmtCount->execute([$userId, $fiscalYearId]);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// --- دریافت سوابق صورت‌حساب‌ها ---
$stmtReps = $pdo->prepare("SELECT * FROM fin_expense_reports WHERE user_id = ? AND fiscal_year_id = ? ORDER BY sent_at DESC LIMIT $limit OFFSET $offset");
$stmtReps->execute([$userId, $fiscalYearId]);
$myReports = $stmtReps->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'ارسال صورت تنخواه';
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    .panel-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
    
    .table-scroll-hint { display: none; text-align: center; font-size: 0.8rem; color: #64748b; background: #f8fafc; padding: 8px; border-radius: 8px; margin-bottom: 10px; border: 1px dashed #cbd5e1; }
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    
    .styled-table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; color: #475569; font-weight: 900; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 0.95rem; vertical-align: middle; }
    .styled-table tr:hover td { background: #f8fafc; }
    
    .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; display: inline-block; text-align: center; min-width: 100px; }
    .st-pending { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; } 
    .st-sent { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; } 
    .st-approved { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    
    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; margin-top: 25px; padding-top: 20px; border-top: 1px dashed #e2e8f0; }
    .page-link { font-family: inherit; display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; border-radius: 8px; background: #fff; border: 1px solid #cbd5e1; color: #475569; font-weight: bold; text-decoration: none; transition: 0.2s; }
    .page-link:hover { background: #f1f5f9; border-color: #94a3b8; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }

    @media (max-width: 768px) { 
        .container-centered { padding: 0 10px; } 
        .table-scroll-hint { display: block; } 
        .panel-card { padding: 15px 10px; } 
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            
            <div class="page-header" style="margin-bottom: 25px;">
                <div><span class="page-title">📤 ارسال صورت تنخواه به مالی</span></div>
            </div>

            <?php if($msg): ?><div class="alert alert-<?php echo $msgType; ?>" style="font-weight:bold; margin-bottom:20px; text-align: right;"><?php echo $msg; ?></div><?php endif; ?>

            <div class="panel-card" style="border-top: 4px solid #f59e0b;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap:wrap; gap:15px;">
                    <h3 style="margin:0; font-size:1.1rem; color:#b45309; font-weight:900;">⏳ فاکتورهای آماده به ارسال</h3>
                    <span style="font-weight:bold; color:#fff; background:#f59e0b; padding:5px 15px; border-radius:8px;">جمع ریالی: <?php echo number_format($totalPendingAmount); ?> تومان</span>
                </div>
                
                <?php if(empty($pendingExps)): ?>
                    <div style="text-align:center; padding:30px; color:#94a3b8; font-weight:bold; background:#f8fafc; border-radius:8px; border:1px dashed #cbd5e1;">
                        فاکتور خامی برای ارسال وجود ندارد. ابتدا فاکتورهای خود را ثبت کنید.
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <div class="table-scroll-hint">👈 برای مشاهده کامل جدول به چپ و راست بکشید 👉</div>
                        <div class="table-responsive">
                            <table class="styled-table">
                                <thead>
                                    <tr>
                                        <th>ردیف</th>
                                        <th>تاریخ فاکتور</th>
                                        <th>حساب تنخواه</th>
                                        <th>سرفصل هزینه</th>
                                        <th>شرح هزینه</th>
                                        <th>مبلغ (تومان)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $c=1; foreach($pendingExps as $exp): ?>
                                    <tr>
                                        <td style="font-weight:bold; color:#94a3b8;"><?php echo $c++; ?></td>
                                        <td style="font-weight:bold; color:#475569; direction:ltr; text-align:right;">
                                            <?php echo htmlspecialchars(str_replace('-', '/', $exp['invoice_date'])); ?>
                                        </td>
                                        <td style="color:#64748b; font-size:0.85rem; font-weight:bold;"><?php echo htmlspecialchars($exp['account_title']); ?></td>
                                        <td><span style="background:#e0f2fe; color:#0369a1; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;"><?php echo htmlspecialchars($exp['cat_title']); ?></span></td>
                                        <td style="max-width: 250px; white-space: normal; line-height: 1.6; font-size: 0.9rem;">
                                            <?php echo htmlspecialchars($exp['description']); ?>
                                        </td>
                                        <td style="font-weight:900; color:#1e293b; direction:ltr; text-align:right;">
                                            <?php echo number_format($exp['amount']); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <form method="POST" style="margin-top: 25px; background: #fffbeb; padding: 20px; border-radius: 12px; border: 1px solid #fde68a;" onsubmit="return confirm('آیا از ارسال این فاکتورها به واحد مالی اطمینان دارید؟\nپس از ارسال، امکان ویرایش فاکتورها تا زمان بررسی مالی وجود نخواهد داشت.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="create_report">
                        <div style="margin-bottom: 15px;">
                            <label style="font-weight:bold; color:#b45309; display:block; margin-bottom:8px;">عنوان صورت‌حساب (اختیاری):</label>
                            <input type="text" name="title" class="form-control" placeholder="مثلاً: هزینه‌های جاری هفته اول مرداد" style="width:100%; max-width:400px; font-family:inherit; padding:10px;">
                        </div>
                        <button type="submit" class="btn btn-primary" style="font-family:inherit; font-size:1rem; padding:10px 25px; box-shadow:0 4px 6px rgba(37,99,235,0.2);">
                            📤 ارسال فاکتورها به عنوان صورت‌حساب جدید
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="panel-card">
                <h3 style="margin:0 0 20px 0; font-size:1.1rem; color:#1e293b; font-weight:900; border-bottom:2px solid #e2e8f0; padding-bottom:10px;">📁 سوابق صورت‌حساب‌های ارسال شده</h3>
                
                <?php if(empty($myReports)): ?>
                    <div style="text-align:center; padding:30px; color:#94a3b8; font-weight:bold;">تاکنون صورت‌حسابی ارسال نکرده‌اید.</div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <div class="table-scroll-hint">👈 برای مشاهده کامل جدول به چپ و راست بکشید 👉</div>
                        <div class="table-responsive">
                            <table class="styled-table">
                                <thead>
                                    <tr>
                                        <th>شماره پیگیری</th>
                                        <th>عنوان صورت‌حساب</th>
                                        <th>مجموع مبلغ (تومان)</th>
                                        <th>تاریخ ارسال</th>
                                        <th>وضعیت پرونده</th>
                                        <th style="width: 120px;">عملیات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($myReports as $rep): ?>
                                    <tr>
                                        <td style="font-weight:bold; color:#64748b;">#<?php echo $rep['id']; ?></td>
                                        <td style="font-weight:bold; color:#0f766e;"><?php echo htmlspecialchars($rep['title']); ?></td>
                                        <td style="font-weight:900; color:#1e293b; direction:ltr; text-align:right;">
                                            <?php echo number_format($rep['total_amount']); ?>
                                        </td>
                                        <td style="direction:ltr; text-align:right; font-size:0.85rem; font-weight:bold; color:#475569;">
                                            <?php echo function_exists('jdate') ? jdate('Y/m/d - H:i', strtotime($rep['sent_at'])) : $rep['sent_at']; ?>
                                        </td>
                                        <td>
                                            <?php if($rep['status'] === 'sent'): ?>
                                                <span class="status-badge st-sent">⏳ در حال ممیزی مالی</span>
                                            <?php else: ?>
                                                <span class="status-badge st-approved">✅ پرونده بسته شده</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="fin_report_details.php?id=<?php echo $rep['id']; ?>" class="btn btn-outline" style="font-size:0.8rem; padding:6px 12px; font-family:inherit; text-decoration:none;">👁️ مشاهده</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $page == $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</main>

<script>setTimeout(() => document.querySelectorAll('.alert').forEach(a => a.remove()), 5000);</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>