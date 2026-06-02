<?php
/*
 * فایل: public_html/admin/fin_dashboard.php
 * توضیحات: داشبورد اصلی ماژول مالی و تنخواه‌گردان + دسترسی داینامیک
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// --- ⭐ سیستم بررسی دسترسی کاملاً داینامیک ⭐ ---
$isAdmin = false;
$hasAccess = false;
$requiredPermission = 'fin_dashboard'; 

$stmtChk = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$rawRole, $rawRole]);
$roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($roleData) {
    if ($roleData['name'] === 'admin' || $roleData['is_system'] == 1) {
        $isAdmin = true;
        $hasAccess = true;
    } else {
        $perms = json_decode($roleData['permissions'], true) ?? [];
        if (in_array($requiredPermission, $perms) || in_array('all', $perms)) {
            $hasAccess = true;
        }
    }
}

if (!$hasAccess) {
    die('<div style="text-align:center; padding:50px; font-family:Tahoma; color:red; font-weight:bold; font-size:1.1rem; line-height:1.6;">⛔ دسترسی غیرمجاز.<br><span style="font-size:0.85rem; color:#64748b;">(دسترسی مشاهده داشبورد مالی باید در مدیریت نقش‌ها برای شما فعال شود)</span></div>');
}
// ------------------------------------------------

$fiscalYear = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? $fiscalYear['id'] : 0;

// ۱. محاسبه مجموع موجودی حساب‌های اصلی شرکت (بانک و گاوصندوق)
$bankSafeBalance = $pdo->query("SELECT SUM(balance) FROM fin_accounts WHERE type IN ('bank', 'safe') AND status='active'")->fetchColumn() ?: 0;

// ۲. محاسبه مجموع موجودی در دست تنخواه‌داران
$pettyCashBalance = $pdo->query("SELECT SUM(balance) FROM fin_accounts WHERE type = 'petty_cash' AND status='active'")->fetchColumn() ?: 0;

// ۳. محاسبه کل هزینه‌های ثبت و تایید شده در این سال مالی
$totalExpensesStmt = $pdo->prepare("SELECT SUM(amount) FROM fin_transactions WHERE type='expense' AND fiscal_year_id=?");
$totalExpensesStmt->execute([$fiscalYearId]);
$totalExpenses = $totalExpensesStmt->fetchColumn() ?: 0;

// ۴. تعداد درخواست‌های شارژ در انتظار
$pendingChargeReqs = $pdo->prepare("SELECT COUNT(id) FROM fin_charge_requests WHERE fiscal_year_id=? AND status IN ('pending_admin', 'pending_finance')");
$pendingChargeReqs->execute([$fiscalYearId]);
$pendingReqsCount = $pendingChargeReqs->fetchColumn();

// ۵. دریافت ५ تراکنش آخر برای نمایش در جدول
$recentTransactions = $pdo->prepare("
    SELECT t.*, u.first_name, u.last_name 
    FROM fin_transactions t 
    LEFT JOIN users u ON t.created_by = u.id 
    WHERE t.fiscal_year_id=? 
    ORDER BY t.created_at DESC LIMIT 5
");
$recentTransactions->execute([$fiscalYearId]);
$recentTx = $recentTransactions->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'داشبورد مالی و تنخواه';
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: #fff; border-radius: 16px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 20px; transition: transform 0.3s; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
    .stat-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; }
    .icon-blue { background: #eff6ff; color: #3b82f6; }
    .icon-emerald { background: #ecfdf5; color: #10b981; }
    .icon-rose { background: #fff1f2; color: #f43f5e; }
    .icon-amber { background: #fffbeb; color: #f59e0b; }
    .stat-info h3 { margin: 0; font-size: 0.9rem; color: #64748b; font-weight: bold; }
    .stat-value { font-size: 1.5rem; font-weight: 900; color: #1e293b; margin-top: 5px; direction: ltr; text-align: left; }
    .panel-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
    .panel-title { font-size: 1.1rem; font-weight: 900; color: #1e293b; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px dashed #e2e8f0; }
    .simple-table { width: 100%; border-collapse: collapse; }
    .simple-table th { background: #f8fafc; padding: 12px; text-align: right; color: #475569; font-weight: bold; font-size: 0.85rem; }
    .simple-table td { padding: 12px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.9rem; }
    .type-expense { color: #ef4444; font-weight: bold; }
    .type-charge { color: #10b981; font-weight: bold; }
    @media (max-width: 768px) { .container-centered { padding: 0 10px; } .simple-table { display: block; overflow-x: auto; white-space: nowrap; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            
            <div class="page-header" style="margin-bottom: 25px;">
                <div><span class="page-title">📊 داشبورد مالی و تنخواه</span></div>
                <div style="font-size: 0.85rem; color: #64748b; margin-top: 5px;">سال مالی فعال: <strong style="color:#2563eb;"><?php echo $fiscalYear ? htmlspecialchars($fiscalYear['title']) : 'نامشخص'; ?></strong></div>
            </div>

            <?php if(!$fiscalYearId): ?>
                <div class="alert alert-error" style="font-weight:bold;">سال مالی فعال در سیستم تعریف نشده است. لطفاً ابتدا سال مالی را ایجاد و فعال کنید.</div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon icon-blue">🏦</div>
                    <div class="stat-info">
                        <h3>موجودی صندوق</h3>
                        <div class="stat-value"><?php echo number_format($bankSafeBalance); ?> <span style="font-size:0.7rem; color:#94a3b8;">تومان</span></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon icon-amber">💸</div>
                    <div class="stat-info">
                        <h3>مجموع پول نزد تنخواه‌داران</h3>
                        <div class="stat-value"><?php echo number_format($pettyCashBalance); ?> <span style="font-size:0.7rem; color:#94a3b8;">تومان</span></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon icon-rose">📉</div>
                    <div class="stat-info">
                        <h3>مجموع هزینه‌های قطعی امسال</h3>
                        <div class="stat-value"><?php echo number_format($totalExpenses); ?> <span style="font-size:0.7rem; color:#94a3b8;">تومان</span></div>
                    </div>
                </div>
                <div class="stat-card" style="cursor:pointer;" onclick="window.location.href='fin_charge_requests.php';">
                    <div class="stat-icon icon-emerald">🔔</div>
                    <div class="stat-info">
                        <h3>درخواست‌های شارژ در انتظار</h3>
                        <div class="stat-value" style="color:#10b981;"><?php echo $pendingReqsCount; ?> <span style="font-size:0.7rem; color:#94a3b8;">مورد</span></div>
                    </div>
                </div>
            </div>

            <div class="panel-card">
                <div class="panel-title">📋 ۵ تراکنش مالی اخیر ثبت شده در سیستم</div>
                <?php if(empty($recentTx)): ?>
                    <div style="text-align:center; padding:30px; color:#94a3b8;">تراکنشی در این سال مالی ثبت نشده است.</div>
                <?php else: ?>
                    <table class="simple-table">
                        <thead>
                            <tr>
                                <th>شماره سند</th>
                                <th>مبلغ (تومان)</th>
                                <th>نوع</th>
                                <th>شرح تراکنش</th>
                                <th>ثبت‌کننده</th>
                                <th>تاریخ ثبت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recentTx as $tx): ?>
                            <tr>
                                <td style="font-weight:bold; color:#64748b;">#<?php echo $tx['id']; ?></td>
                                <td style="font-weight:bold; direction:ltr; text-align:right;">
                                    <span class="<?php echo $tx['type'] == 'expense' ? 'type-expense' : 'type-charge'; ?>">
                                        <?php echo number_format($tx['amount']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    if($tx['type'] == 'expense') echo 'هزینه قطعی';
                                    elseif($tx['type'] == 'charge') echo 'شارژ حساب';
                                    elseif($tx['type'] == 'return') echo 'برگشت وجه';
                                    else echo 'انتقال';
                                    ?>
                                </td>
                                <td style="max-width:300px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($tx['description']); ?>">
                                    <?php echo htmlspecialchars($tx['description']); ?>
                                </td>
                                <td style="font-size:0.85rem;"><?php echo htmlspecialchars($tx['first_name'].' '.$tx['last_name']); ?></td>
                                <td style="direction:ltr; text-align:right; font-size:0.85rem; color:#64748b;">
                                    <?php echo function_exists('jdate') ? jdate('Y/m/d - H:i', strtotime($tx['created_at'])) : $tx['created_at']; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:20px; text-align:left;">
                        <a href="fin_transactions.php" class="btn btn-outline" style="font-size:0.85rem; padding:8px 15px;">مشاهده تمام تراکنش‌ها ⬅️</a>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>