<?php
/* فایل: public_html/admin/fin_report_review.php */
ob_start(); session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// ⭐ سیستم بررسی دسترسی کاملاً داینامیک ⭐
$stmtUserRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmtUserRole->execute([$userId]);
$dbRole = $stmtUserRole->fetchColumn();

$roleName = '';
if (is_numeric($dbRole)) {
    $stmtR = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
    $stmtR->execute([$dbRole]);
    $roleName = strtolower(trim($stmtR->fetchColumn()));
} else {
    $roleName = strtolower(trim($dbRole));
}

$financeRoles = ['admin', 'finance_manager', 'finance_expert', 'accountant', '1', '5', '10', '14'];
$isFinance = in_array($roleName, $financeRoles) || in_array($dbRole, $financeRoles);

if (!$isFinance) {
    die('<div style="text-align:center; padding:50px; font-family:Tahoma; color:red; font-weight:bold; font-size:1.1rem;">⛔ دسترسی غیرمجاز.</div>');
}

$fiscalYear = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? $fiscalYear['id'] : null;

$msg = ''; $msgType = '';
if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; $msgType = $_SESSION['flash_type'] ?? 'success'; unset($_SESSION['flash_msg'], $_SESSION['flash_type']); }

$limit = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$sqlCount = "SELECT COUNT(id) FROM fin_expense_reports WHERE fiscal_year_id = ?";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute([$fiscalYearId]);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$sqlReps = "SELECT r.*, u.first_name, u.last_name 
            FROM fin_expense_reports r 
            JOIN users u ON r.user_id = u.id 
            WHERE r.fiscal_year_id = ? 
            ORDER BY CASE WHEN r.status = 'sent' THEN 1 ELSE 2 END, r.sent_at DESC 
            LIMIT $limit OFFSET $offset";
$stmtReps = $pdo->prepare($sqlReps);
$stmtReps->execute([$fiscalYearId]);
$reports = $stmtReps->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'ممیزی صورت تنخواه‌ها';
$basePath = '../';
$extraCss = '<style>
    @font-face {
        font-family: "Vazirmatn";
        src: url("../assets/fonts/Vazirmatn-Regular.ttf") format("truetype");
        font-weight: normal;
    }
    
    body, .styled-table, .page-header, .alert, .btn-action, .page-link {
        font-family: "Vazirmatn", Tahoma, Arial, sans-serif !important;
    }

    .container-centered { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    .panel-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
    .table-scroll-hint { display: none; text-align: center; font-size: 0.8rem; color: #64748b; background: #f8fafc; padding: 8px; border-radius: 8px; margin-bottom: 10px; border: 1px dashed #cbd5e1; }
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .styled-table { width: 100%; border-collapse: collapse; min-width: 900px; }
    .styled-table th { background: #f8fafc; padding: 15px; text-align: right; color: #475569; font-weight: 900; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .styled-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 0.95rem; vertical-align: middle; }
    .styled-table tr:hover td { background: #f8fafc; }
    
    .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; display: inline-block; text-align: center; min-width: 110px; }
    .st-sent { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; } 
    .st-approved { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    
    .btn-action { font-family: inherit; padding: 8px 15px; font-size: 0.85rem; font-weight: bold; border-radius: 8px; border: 1px solid transparent; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; text-decoration: none;}
    .btn-review { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; } .btn-review:hover { background: #dbeafe; }
    .btn-view { background: #f1f5f9; color: #475569; border-color: #cbd5e1; } .btn-view:hover { background: #e2e8f0; }
    .btn-print { background: #f8fafc; color: #475569; border-color: #cbd5e1; margin-right: 5px; } .btn-print:hover { background: #e2e8f0; }

    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; margin-top: 25px; padding-top: 20px; border-top: 1px dashed #e2e8f0; }
    .page-link { font-family: inherit; display: inline-flex; align-items: center; justify-content: center; width: 35px; height: 35px; border-radius: 8px; background: #fff; border: 1px solid #cbd5e1; color: #475569; font-weight: bold; text-decoration: none; transition: 0.2s; }
    .page-link:hover { background: #f1f5f9; border-color: #94a3b8; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }

    @media (max-width: 768px) { .container-centered { padding: 0 10px; } .table-scroll-hint { display: block; } .panel-card { padding: 15px 10px; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            <div class="page-header" style="margin-bottom: 25px;"><div><span class="page-title">⚖️ کارتابل ممیزی صورت تنخواه‌ها</span></div></div>
            <?php if($msg): ?><div class="alert alert-<?php echo $msgType; ?>" style="font-weight:bold; margin-bottom:20px; text-align: right;"><?php echo $msg; ?></div><?php endif; ?>

            <div class="panel-card">
                <?php if(empty($reports)): ?>
                    <div style="text-align:center; padding:40px; color:#94a3b8; font-weight:bold;">صورت تنخواهی برای بررسی وجود ندارد.</div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <div class="table-scroll-hint">👈 برای مشاهده کامل جدول به چپ و راست بکشید 👉</div>
                        <div class="table-responsive">
                            <table class="styled-table">
                                <thead>
                                    <tr>
                                        <th>کد رهگیری</th>
                                        <th>کارمند / ثبت‌کننده</th>
                                        <th>عنوان صورت‌حساب</th>
                                        <th>مجموع مبلغ (تومان)</th>
                                        <th>تاریخ ارسال</th>
                                        <th>وضعیت</th>
                                        <th style="min-width: 250px;">عملیات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($reports as $rep): ?>
                                    <tr>
                                        <td style="font-weight:bold; color:#64748b;">#<?php echo $rep['id']; ?></td>
                                        <td style="font-weight:bold;">👤 <?php echo htmlspecialchars($rep['first_name'].' '.$rep['last_name']); ?></td>
                                        <td style="color:#0f766e; font-weight:bold;"><?php echo htmlspecialchars($rep['title']); ?></td>
                                        <td style="font-weight:900; color:#1e293b; direction:ltr; text-align:right;"><?php echo number_format($rep['total_amount']); ?></td>
                                        <td style="direction:ltr; text-align:right; font-size:0.85rem; font-weight:bold; color:#475569;">
                                            <?php 
                                            echo function_exists('jdate') ? jdate('Y/m/d', strtotime($rep['sent_at'])) . ' - ' . date('H:i', strtotime($rep['sent_at'])) : date('Y/m/d - H:i', strtotime($rep['sent_at'])); 
                                            ?>
                                        </td>
                                        <td>
                                            <?php if($rep['status'] === 'sent'): ?>
                                                <span class="status-badge st-sent">در انتظار بررسی مالی</span>
                                            <?php else: ?>
                                                <span class="status-badge st-approved">بررسی و بسته شده</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <div style="display:flex; gap:5px; flex-wrap:nowrap; align-items:center;">
                                                <a href="fin_report_details.php?id=<?php echo $rep['id']; ?>" class="btn-action <?php echo $rep['status'] === 'sent' ? 'btn-review' : 'btn-view'; ?>">
                                                    <?php echo $rep['status'] === 'sent' ? '🔍 بررسی فاکتورها' : '👁️ مشاهده پرونده'; ?>
                                                </a>
                                                <a href="fin_report_details.php?id=<?php echo $rep['id']; ?>&print=1" target="_blank" class="btn-action btn-print">🖨️ چاپ</a>
                                            </div>
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