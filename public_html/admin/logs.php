<?php
/*
 * فایل: public_html/admin/logs.php
 */
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }
if (!hasPermission('logs_view') && $_SESSION['role'] !== 'admin') { die('دسترسی ندارید'); }

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if($page < 1) $page = 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// فیلترها
$userFilter = $_GET['user'] ?? '';
$where = "1=1";
$params = [];

if ($userFilter) {
    $where .= " AND l.user_id = ?";
    $params[] = $userFilter;
}

// کوئری لاگ
$sql = "SELECT l.*, u.username, u.first_name, u.last_name 
        FROM system_logs l 
        LEFT JOIN users u ON l.user_id = u.id 
        WHERE $where 
        ORDER BY l.created_at DESC 
        LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// تعداد کل
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM system_logs l WHERE $where");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$users = $pdo->query("SELECT id, username, first_name, last_name FROM users")->fetchAll();

$pageTitle = 'لاگ سیستم';
$basePath = '../';
include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header">
            <div><span class="page-title">تاریخچه فعالیت‌ها (Log)</span></div>
        </div>

        <div style="background:white; padding:15px; border-radius:10px; border:1px solid #eee; margin-bottom:20px;">
            <form method="GET" style="display:flex; gap:10px; align-items:end;">
                <div style="flex-grow:1; max-width:300px;">
                    <label style="font-size:0.8rem;">فیلتر بر اساس کاربر:</label>
                    <select name="user" class="form-control" style="padding:5px;">
                        <option value="">همه کاربران</option>
                        <?php foreach($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $userFilter==$u['id']?'selected':''; ?>>
                                <?php echo htmlspecialchars($u['first_name'].' '.$u['last_name'].' ('.$u['username'].')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="padding:6px 15px;">اعمال</button>
            </form>
        </div>

        <div class="table-responsive screen-table">
            <table class="table">
                <thead>
                    <tr>
                        <th width="50">شناسه</th>
                        <th width="150">کاربر</th>
                        <th width="100">بخش</th>
                        <th width="100">عملیات</th>
                        <th>توضیحات</th>
                        <th width="100">IP</th>
                        <th width="150">زمان</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($logs as $log): ?>
                    <tr>
                        <td><?php echo $log['id']; ?></td>
                        <td>
                            <?php if($log['username']): ?>
                                <span style="font-weight:bold; color:#2563eb;"><?php echo htmlspecialchars($log['username']); ?></span>
                            <?php else: ?>
                                <span style="color:#999;">سیستم/ناشناس</span>
                            <?php endif; ?>
                        </td>
                        <td><span style="background:#f3f4f6; padding:2px 8px; border-radius:4px; font-size:0.8rem;"><?php echo htmlspecialchars($log['section']); ?></span></td>
                        <td><?php echo htmlspecialchars($log['action']); ?></td>
                        <td style="font-size:0.9rem;"><?php echo htmlspecialchars($log['description']); ?></td>
                        <td style="direction:ltr; text-align:center; font-size:0.85rem;"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                        <td style="direction:ltr; font-size:0.85rem;"><?php echo jdate('Y/m/d H:i', strtotime($log['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination page-actions">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&user=<?php echo $userFilter; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>
    
    <!-- فوتر اصلاح شده و یکسان -->
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری آتنا زیست درمان</footer>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>