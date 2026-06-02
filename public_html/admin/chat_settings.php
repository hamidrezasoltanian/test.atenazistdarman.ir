<?php
/*
 * فایل: public_html/admin/chat_settings.php
 * توضیحات: مدیریت دسترسی کاربران + انتخاب کانال مرکزی منشن‌ها (با رفع مشکل نمایش نام گروه)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

// اطمینان از وجود جدول تنظیمات سیستم برای ذخیره کانال منشن
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// --- ذخیره تغییرات ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_perms'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        die("خطای امنیتی CSRF.");
    }
    
    try {
        $pdo->beginTransaction();
        
        // ذخیره شناسه کانال منشن
        if (isset($_POST['mention_channel_id'])) {
            $mentionChannelId = (int)$_POST['mention_channel_id'];
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('mentions_channel_id', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                ->execute([$mentionChannelId, $mentionChannelId]);
        }

        // ذخیره دسترسی کاربران
        $validPerms = ['chat_private', 'chat_group', 'chat_channel'];
        $editedUserIds = $_POST['user_ids'] ?? [];
        
        foreach ($editedUserIds as $uId) {
            $uId = (int)$uId;
            $postedPerms = $_POST['perms'][$uId] ?? [];
            $cleanPerms = array_intersect($postedPerms, $validPerms);
            $jsonPerms = !empty($cleanPerms) ? json_encode(array_values($cleanPerms)) : json_encode([]);
            
            $stmt = $pdo->prepare("UPDATE users SET chat_permissions = ? WHERE id = ?");
            $stmt->execute([$jsonPerms, $uId]);
        }
        
        $pdo->commit();
        $msg = "تنظیمات با موفقیت ذخیره شد.";
        $msgType = "success";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "خطا: " . $e->getMessage();
        $msgType = "error";
    }
}

// واکشی کانال منشن فعلی
$currentMentionChannel = 0;
try {
    $currentMentionChannel = (int)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'mentions_channel_id'")->fetchColumn();
} catch (Exception $e) {}

// واکشی لیست تمام گروه‌ها و کانال‌ها
$allGroups = $pdo->query("SELECT id, title, type FROM conversations WHERE type IN ('group', 'channel')")->fetchAll(PDO::FETCH_ASSOC);

// --- تنظیمات جستجو و صفحه‌بندی ---
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = trim($_GET['q'] ?? '');
$limit = 20;
$offset = ($page - 1) * $limit;

$whereClause = "WHERE u.status = 'active'";
$params = [];

if ($search) {
    $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereClause");
$countStmt->execute($params);
$totalUsers = $countStmt->fetchColumn();
$totalPages = ceil($totalUsers / $limit);

$sql = "SELECT u.id, u.first_name, u.last_name, u.username, u.role, u.profile_image, u.chat_permissions, r.title as role_title 
        FROM users u 
        LEFT JOIN roles r ON u.role = r.name 
        $whereClause 
        ORDER BY u.id DESC 
        LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usersList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'تنظیمات پیام‌رسان';
$basePath = '../';
$extraCss = '<style>
    * { box-sizing: border-box; }
    
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; }
    
    .perm-table { width: 100%; border-collapse: collapse; background: #fff; min-width: 650px; }
    .perm-table th, .perm-table td { padding: 12px 15px; text-align: center; border-bottom: 1px solid #eee; vertical-align: middle; }
    .perm-table th { background: #f8fafc; font-weight: 800; color: #334155; text-align: center; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .perm-table th:first-child { text-align: right; }
    .perm-table td:first-child { text-align: right; }
    
    .user-info { display: flex; align-items: center; gap: 12px; }
    .user-avatar { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 1px solid #e2e8f0; flex-shrink: 0; }
    .perm-checkbox { width: 20px; height: 20px; cursor: pointer; accent-color: #2563eb; }
    
    .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 5px; margin-top: 20px; }
    .page-link { padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 6px; color: #475569; text-decoration: none; font-weight: bold; transition: 0.2s; }
    .page-link:hover { background: #f1f5f9; }
    .page-link.active { background: #2563eb; color: #fff; border-color: #2563eb; }
    
    .search-box { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
    .search-input { padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; width: 300px; max-width: 100%; font-family: inherit; font-size: 0.9rem; }
    .search-input:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
    .role-badge { font-size: 0.75rem; background: #e0f2fe; padding: 3px 8px; border-radius: 12px; color: #0369a1; margin-right: 5px; font-weight: bold; white-space: nowrap; display: inline-block;}

    .save-btn-container { display: flex; justify-content: center; margin-top: 25px; }
    .save-btn-container .btn { 
        padding: 12px 40px; font-weight: 900; font-size: 1rem; box-shadow: 0 4px 10px rgba(37,99,235,0.2); 
        text-align: center; display: flex; justify-content: center; align-items: center; 
    }
    
    .settings-section { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 25px; }
    .settings-section h3 { margin-top: 0; color: #1e293b; font-size: 1.1rem; border-bottom: 2px solid #cbd5e1; padding-bottom: 10px; margin-bottom: 15px; }
    
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 8px; color: #334155; }
    .form-control { width: 100%; max-width: 400px; padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; }

    @media (max-width: 768px) {
        .content-wrapper { padding: 10px !important; }
        .card { padding: 15px !important; border: none !important; box-shadow: none !important; border-radius: 0 !important;}
        
        .search-box { flex-direction: column; width: 100%; }
        .search-box .search-input { width: 100%; }
        .search-box .btn { width: 100%; text-align: center; justify-content: center; display: flex; }
        .form-control { max-width: 100%; }

        .table-responsive { 
            border: 1px solid #e2e8f0; 
            border-radius: 8px; 
            box-shadow: inset 0 0 10px rgba(0,0,0,0.02);
            margin-bottom: 20px;
        }
        
        .save-btn-container { margin-top: 20px !important; width: 100%; display: flex; justify-content: center; }
        .save-btn-container .btn { width: 100%; max-width: 300px; padding: 12px; font-size: 1rem; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">تنظیمات پیام‌رسان</span></div>
            <div><a href="chat.php" class="btn btn-secondary">بازگشت به پیام‌رسان</a></div>
        </div>

        <?php if(isset($msg)): ?>
            <div id="alertMsg" class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="card" style="padding: 25px; background: white; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
            
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_perms" value="1">
                
                <!-- بخش تنظیمات گروه منشن -->
                <div class="settings-section">
                    <h3>🔔 تنظیمات کانال مرکزی منشن‌ها</h3>
                    <div class="form-group">
                        <label for="mention_channel">گروه یا کانالی که تمام منشن‌های سیستم در آن ثبت شود:</label>
                        <select name="mention_channel_id" id="mention_channel" class="form-control">
                            <option value="0">-- غیرفعال (عدم ثبت در کانال) --</option>
                            <?php foreach($allGroups as $g): ?>
                                <option value="<?php echo $g['id']; ?>" <?php echo ($currentMentionChannel == $g['id']) ? 'selected' : ''; ?>>
                                    <!-- رفع باگ نمایش نام گروه‌ها در سلکت باکس -->
                                    <?php echo htmlspecialchars($g['title']); ?> (<?php echo $g['type'] == 'channel' ? 'کانال' : 'گروه'; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="display:block; margin-top:5px; color:#64748b;">کاربرانی که منشن (تگ) می‌شوند، علاوه بر دریافت نوتیفیکیشن، یک لینک مستقیم به پیام در این گروه دریافت می‌کنند.</small>
                    </div>
                </div>

                <!-- بخش فیلتر و جستجوی کاربران -->
                <div class="search-box">
                    <input type="text" name="q" form="searchForm" class="search-input" placeholder="جستجو بر اساس نام، نام خانوادگی یا کاربری..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" form="searchForm" class="btn btn-primary" style="padding: 10px 20px;">جستجو</button>
                    <?php if($search): ?><a href="chat_settings.php" class="btn btn-outline" style="padding: 10px 20px; display:flex; align-items:center; justify-content:center;">حذف فیلتر</a><?php endif; ?>
                </div>
                
                <!-- جدول دسترسی‌ها -->
                <div class="table-responsive">
                    <table class="perm-table">
                        <thead>
                            <tr>
                                <th>مشخصات پرسنل</th>
                                <th width="150">دسترسی پیام شخصی</th>
                                <th width="150">دسترسی ایجاد گروه</th>
                                <th width="150">دسترسی ایجاد کانال</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($usersList) > 0): ?>
                                <?php foreach($usersList as $u): 
                                    $perms = json_decode($u['chat_permissions'] ?? '[]', true) ?: [];
                                    $imgSrc = (!empty($u['profile_image']) && $u['profile_image'] !== 'default.png' && file_exists(__DIR__ . '/../../public_html/uploads/profiles/' . $u['profile_image'])) 
                                                ? '../uploads/profiles/' . $u['profile_image'] 
                                                : '../assets/images/profile-icon.png';
                                    
                                    $roleTitle = $u['role_title'] ?? $u['role'];
                                    $isSuperUser = ($u['role'] === 'admin' || $u['username'] === 'ai_assistant');
                                ?>
                                <tr>
                                    <td>
                                        <?php if(!$isSuperUser): ?>
                                            <input type="hidden" name="user_ids[]" value="<?php echo $u['id']; ?>">
                                        <?php endif; ?>
                                        
                                        <div class="user-info">
                                            <img src="<?php echo $imgSrc; ?>" class="user-avatar">
                                            <div>
                                                <div style="font-weight:900; color:#1e293b; margin-bottom: 3px; white-space: nowrap;"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></div>
                                                <div style="font-size:0.8rem; color:#64748b; display: flex; align-items: center; flex-wrap: wrap; gap: 5px;">
                                                    <span class="username-text" style="direction: ltr; display: inline-block;">@<?php echo htmlspecialchars($u['username']); ?></span>
                                                    <span class="role-badge"><?php echo htmlspecialchars($roleTitle); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="checkbox" class="perm-checkbox" name="perms[<?php echo $u['id']; ?>][]" value="chat_private" <?php echo ($isSuperUser || in_array('chat_private', $perms)) ? 'checked' : ''; ?> <?php echo $isSuperUser ? 'disabled' : ''; ?>>
                                    </td>
                                    <td>
                                        <input type="checkbox" class="perm-checkbox" name="perms[<?php echo $u['id']; ?>][]" value="chat_group" <?php echo ($isSuperUser || in_array('chat_group', $perms)) ? 'checked' : ''; ?> <?php echo $isSuperUser ? 'disabled' : ''; ?>>
                                    </td>
                                    <td>
                                        <input type="checkbox" class="perm-checkbox" name="perms[<?php echo $u['id']; ?>][]" value="chat_channel" <?php echo ($isSuperUser || in_array('chat_channel', $perms)) ? 'checked' : ''; ?> <?php echo $isSuperUser ? 'disabled' : ''; ?>>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="padding:40px; color:#94a3b8; text-align:center; font-weight:bold;">هیچ کاربری یافت نشد.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="save-btn-container">
                    <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
                </div>
            </form>

            <form id="searchForm" method="GET"></form>

            <?php if($totalPages > 1): ?>
            <div class="pagination page-actions">
                <?php for($i=1; $i<=$totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
</main>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const alertBox = document.getElementById('alertMsg');
        if (alertBox) { setTimeout(() => alertBox.remove(), 4000); }
    });
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>