<?php
/*
 * فایل: public_html/admin/fin_transactions.php
 * توضیحات: دفتر کل تراکنش‌ها + نمایش هوشمند برداشت/واریز دستی + مانده لحظه‌ای + صفحه‌بندی (حل مشکل بیرون‌زدگی متن)
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
$requiredPermission = 'fin_transactions'; 

$stmtChk = $pdo->prepare("SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?");
$stmtChk->execute([$rawRole, $rawRole]);
$roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);

if ($roleData) {
    if ($roleData['name'] === 'admin' || $roleData['is_system'] == 1) {
        $isAdmin = true; $hasAccess = true;
    } else {
        $perms = json_decode($roleData['permissions'], true) ?? [];
        if (in_array($requiredPermission, $perms) || in_array('all', $perms)) $hasAccess = true;
    }
}

if (!$hasAccess) die('<div style="text-align:center; padding:50px; font-family:Tahoma; color:red; font-weight:bold;">⛔ دسترسی غیرمجاز.</div>');

$fiscalYear = getActiveFiscalYear();
$fiscalYearId = $fiscalYear ? $fiscalYear['id'] : 0;

$allAccounts = $pdo->query("SELECT id, title, type FROM fin_accounts ORDER BY type ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC);
$allUsers = $pdo->query("SELECT id, first_name, last_name FROM users ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- ⭐ فیلترهای جستجو ⭐ ---
$where = ["t.fiscal_year_id = ?"];
$params = [$fiscalYearId];

$filterType = $_GET['type'] ?? '';
$filterFromAcc = $_GET['from_account'] ?? '';
$filterToAcc = $_GET['to_account'] ?? '';
$filterCreatedBy = $_GET['created_by'] ?? '';
$filterAmount = str_replace(',', '', trim($_GET['amount'] ?? ''));
$filterFromDate = trim($_GET['from_date'] ?? '');
$filterToDate = trim($_GET['to_date'] ?? '');
$filterSearch = trim($_GET['search'] ?? '');

$hasActiveFilters = (!empty($filterType) || !empty($filterFromAcc) || !empty($filterToAcc) || !empty($filterCreatedBy) || !empty($filterAmount) || !empty($filterFromDate) || !empty($filterToDate) || !empty($filterSearch));

if ($filterType) { $where[] = "t.type = ?"; $params[] = $filterType; }
if ($filterFromAcc) { $where[] = "t.from_account_id = ?"; $params[] = (int)$filterFromAcc; }
if ($filterToAcc) { $where[] = "t.to_account_id = ?"; $params[] = (int)$filterToAcc; }
if ($filterCreatedBy) { $where[] = "t.created_by = ?"; $params[] = (int)$filterCreatedBy; }
if ($filterAmount && is_numeric($filterAmount)) { $where[] = "t.amount = ?"; $params[] = (int)$filterAmount; }
if ($filterSearch) {
    $where[] = "(t.description LIKE ? OR t.id = ?)";
    $params[] = "%$filterSearch%"; $params[] = (int)$filterSearch;
}

if ($filterFromDate) {
    $p = explode('/', faToEn($filterFromDate));
    if (count($p) == 3) {
        $gDate = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2])) . ' 00:00:00';
        $where[] = "t.created_at >= ?"; $params[] = $gDate;
    }
}
if ($filterToDate) {
    $p = explode('/', faToEn($filterToDate));
    if (count($p) == 3) {
        $gDate = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2])) . ' 23:59:59';
        $where[] = "t.created_at <= ?"; $params[] = $gDate;
    }
}

$whereClause = implode(" AND ", $where);

// --- ⭐ صفحه‌بندی ۲۰ تایی ⭐ ---
$limit = 20; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$urlParams = $_GET; unset($urlParams['page']);
$queryString = http_build_query($urlParams);
$pageSuffix = $queryString ? '&' . $queryString : '';

$sqlCount = "SELECT COUNT(t.id) FROM fin_transactions t WHERE $whereClause";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// دریافت تراکنش‌ها با محاسبه لحظه‌ای مانده (حتی برای واریز/برداشت‌های تک‌طرفه)
$sql = "
    SELECT t.*, 
           acc_from.title as from_account, acc_from.type as from_type,
           acc_to.title as to_account, acc_to.type as to_type,
           u.first_name, u.last_name,
           
           (acc_from.balance - COALESCE((
               SELECT SUM(CASE WHEN t2.to_account_id = t.from_account_id THEN t2.amount ELSE -t2.amount END)
               FROM fin_transactions t2
               WHERE (t2.from_account_id = t.from_account_id OR t2.to_account_id = t.from_account_id)
                 AND (t2.created_at > t.created_at OR (t2.created_at = t.created_at AND t2.id > t.id))
           ), 0)) AS from_balance,
           
           (acc_to.balance - COALESCE((
               SELECT SUM(CASE WHEN t2.to_account_id = t.to_account_id THEN t2.amount ELSE -t2.amount END)
               FROM fin_transactions t2
               WHERE (t2.from_account_id = t.to_account_id OR t2.to_account_id = t.to_account_id)
                 AND (t2.created_at > t.created_at OR (t2.created_at = t.created_at AND t2.id > t.id))
           ), 0)) AS to_balance

    FROM fin_transactions t
    LEFT JOIN fin_accounts acc_from ON t.from_account_id = acc_from.id
    LEFT JOIN fin_accounts acc_to ON t.to_account_id = acc_to.id
    LEFT JOIN users u ON t.created_by = u.id
    WHERE $whereClause
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'دفتر روزنامه تراکنش‌ها';
$basePath = '../';
$extraCss = '<style>
    .container-centered { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
    .filter-accordion-label { display: flex; justify-content: space-between; align-items: center; background: #fff; border: 1px solid #e5e7eb; padding: 15px 20px; border-radius: 12px; margin-bottom: 15px; cursor: pointer; font-weight: 900; color: #1e293b; }
    .filter-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; margin-bottom: 25px; }
    .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; align-items: end; }
    .filter-group { display: flex; flex-direction: column; gap: 8px; }
    .filter-actions { display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end; border-top: 1px dashed #e2e8f0; padding-top: 20px; }
    
    .ledger-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); overflow-x: auto; }
    .ledger-table { width: 100%; border-collapse: collapse; min-width: 1000px; }
    .ledger-table th { background: #f8fafc; padding: 15px; text-align: right; color: #475569; font-weight: 900; border-bottom: 2px solid #e2e8f0; }
    .ledger-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    
    .type-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; display: inline-block; text-align: center; min-width: 90px; }
    .type-charge { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
    .type-expense { background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; }
    .type-transfer-in { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; } /* واریز دستی */
    .type-transfer-out { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; } /* برداشت دستی */
    .type-return { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
    
    .acc-name { font-weight: bold; color: #3b82f6; background: #eff6ff; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem; display: inline-block; margin-bottom: 5px; }
    .acc-balance { font-size: 0.75rem; font-weight: bold; display: block; }
    .minus { color: #ef4444; } .plus { color: #10b981; }

    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; margin-top: 25px; padding-top: 20px; border-top: 1px dashed #e2e8f0; }
    .page-link { font-family: inherit; padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 8px; text-decoration: none; color: #475569; font-weight: bold; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }

    @media (max-width: 768px) { .container-centered { padding: 0 10px; } .filter-grid { grid-template-columns: 1fr; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';

// تابع هوشمند تشخیص نوع تراکنش برای نمایش در جدول
function getTxDisplay($t) {
    if($t['type'] == 'charge') return ['شارژ تنخواه', 'type-charge'];
    if($t['type'] == 'expense') return ['هزینه قطعی', 'type-expense'];
    if($t['type'] == 'return') return ['برگشت وجه', 'type-return'];
    if($t['type'] == 'transfer') {
        // اگر تراکنش دستی بود، ببینیم برداشت بوده یا واریز
        if($t['from_account_id'] && !$t['to_account_id']) return ['➖ برداشت دستی', 'type-transfer-out'];
        if(!$t['from_account_id'] && $t['to_account_id']) return ['➕ واریز دستی', 'type-transfer-in'];
        return ['جابجایی وجه', 'type-transfer-in'];
    }
    return ['ناشناخته', ''];
}
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="container-centered">
            
            <div class="page-header" style="margin-bottom: 25px;">
                <div><span class="page-title">📔 دفتر روزنامه تراکنش‌های مالی</span></div>
            </div>

            <div class="filter-accordion-label" onclick="toggleFilters()">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span>🔍</span><span>فیلترهای جستجو</span>
                    <?php if($hasActiveFilters): ?><span style="background:#ef4444; color:#fff; font-size:0.7rem; padding:2px 6px; border-radius:10px;">فعال</span><?php endif; ?>
                </div>
                <span id="filterArrow">🔽</span>
            </div>

            <form method="GET" class="filter-card" id="filterCard" style="display: <?php echo $hasActiveFilters ? 'block' : 'none'; ?>;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>نوع تراکنش</label>
                        <select name="type" class="form-control">
                            <option value="">همه موارد</option>
                            <option value="charge" <?php if($filterType=='charge') echo 'selected'; ?>>شارژ تنخواه</option>
                            <option value="expense" <?php if($filterType=='expense') echo 'selected'; ?>>هزینه قطعی</option>
                            <option value="transfer" <?php if($filterType=='transfer') echo 'selected'; ?>>واریز / برداشت دستی</option>
                            <option value="return" <?php if($filterType=='return') echo 'selected'; ?>>برگشت وجه</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>از حساب (مبدأ)</label>
                        <select name="from_account" class="form-control">
                            <option value="">همه حساب‌ها</option>
                            <?php foreach($allAccounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>" <?php if($filterFromAcc==$acc['id']) echo 'selected'; ?>><?php echo htmlspecialchars($acc['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>به حساب (مقصد)</label>
                        <select name="to_account" class="form-control">
                            <option value="">همه حساب‌ها</option>
                            <?php foreach($allAccounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>" <?php if($filterToAcc==$acc['id']) echo 'selected'; ?>><?php echo htmlspecialchars($acc['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>مبلغ دقیق</label>
                        <input type="text" name="amount" class="form-control amount-input" value="<?php echo $filterAmount; ?>" dir="ltr">
                    </div>
                </div>
                <div class="filter-actions">
                    <a href="fin_transactions.php" class="btn btn-outline">🧹 حذف فیلتر</a>
                    <button type="submit" class="btn btn-primary">🔍 اعمال فیلتر</button>
                </div>
            </form>

            <div class="ledger-card">
                <table class="ledger-table">
                    <thead>
                        <tr>
                            <th>کد</th>
                            <th>نوع تراکنش</th>
                            <th>مبلغ (تومان)</th>
                            <th>از حساب / مانده</th>
                            <th>به حساب / مانده</th>
                            <th>شرح سند</th>
                            <th>ثبت‌کننده / تاریخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($transactions as $t): 
                            list($typeLabel, $typeClass) = getTxDisplay($t);
                            $isExpense = ($t['type'] == 'expense' || ($t['type'] == 'transfer' && $t['from_account_id'] && !$t['to_account_id']));
                        ?>
                        <tr>
                            <td style="font-weight:bold; color:#64748b;">#<?php echo $t['id']; ?></td>
                            <td><span class="type-badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span></td>
                            <td style="font-weight:900; direction:ltr; text-align:right; color:<?php echo $isExpense ? '#ef4444' : '#10b981'; ?>;">
                                <?php echo ($isExpense ? '-' : '+') . ' ' . number_format($t['amount']); ?>
                            </td>
                            
                            <td>
                                <?php if($t['from_account']): ?>
                                    <span class="acc-name"><?php echo htmlspecialchars($t['from_account']); ?></span>
                                    <span class="acc-balance minus" dir="ltr">مانده: <?php echo number_format((float)$t['from_balance']); ?></span>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">---</span>
                                <?php endif; ?>
                            </td>
                            
                            <td>
                                <?php if($t['to_account']): ?>
                                    <span class="acc-name" style="color:#0d9488; background:#ecfeff;"><?php echo htmlspecialchars($t['to_account']); ?></span>
                                    <span class="acc-balance plus" dir="ltr">مانده: <?php echo number_format((float)$t['to_balance']); ?></span>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">---</span>
                                <?php endif; ?>
                            </td>
                            
                            <td style="max-width: 250px; min-width: 200px; white-space: normal; word-wrap: break-word; overflow-wrap: break-word; word-break: break-word; font-size: 0.85rem; line-height: 1.6;">
                                <?php echo htmlspecialchars($t['description']); ?>
                            </td>
                            
                            <td>
                                <div style="font-size:0.8rem; font-weight:bold;">👤 <?php echo htmlspecialchars($t['first_name'].' '.$t['last_name']); ?></div>
                                <div style="direction:ltr; text-align:right; font-size:0.75rem; color:#64748b; margin-top:4px;">
                                    <?php echo function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($t['created_at'])) : $t['created_at']; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?php echo $i . $pageSuffix; ?>" class="page-link <?php echo $page == $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script>
    function toggleFilters() {
        const card = document.getElementById('filterCard');
        const arrow = document.getElementById('filterArrow');
        if (card.style.display === 'none') {
            card.style.display = 'block'; arrow.innerText = '🔼';
        } else {
            card.style.display = 'none'; arrow.innerText = '🔽';
        }
    }
    document.querySelectorAll('.amount-input').forEach(i => i.addEventListener('input', e => {
        let v = e.target.value.replace(/\D/g, '');
        e.target.value = v ? parseInt(v).toLocaleString('en-US') : '';
    }));
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>