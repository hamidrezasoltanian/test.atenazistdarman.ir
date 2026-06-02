<?php
/*
 * فایل: public_html/admin/create_chat.php
 * توضیحات: ایجاد گروه یا کانال (ادغام شده، هوشمند و ایمن)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$type = $_GET['type'] ?? 'group';
if (!in_array($type, ['group', 'channel'])) { $type = 'group'; }

$permRequired = ($type === 'channel') ? 'chat_channel' : 'chat_group';
if (function_exists('hasChatPermission') && !hasChatPermission($permRequired)) {
    die('<div style="text-align:center; padding:50px; font-family:tahoma; color:red; font-weight:bold;">⛔ شما مجوز ایجاد ' . ($type==='group'?'گروه':'کانال') . ' را ندارید. <a href="chat.php">بازگشت</a></div>');
}

$pageTitle = ($type === 'channel') ? 'ایجاد کانال جدید' : 'ایجاد گروه جدید';
$basePath = '../';

$usersStmt = $pdo->prepare("SELECT id, first_name, last_name, username, profile_image FROM users WHERE id != ? AND status = 'active' ORDER BY last_name ASC");
$usersStmt->execute([$_SESSION['user_id']]);
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$extraCss = '<style>
    .group-avatar-upload { width: 120px; height: 120px; border-radius: 50%; border: 2px dashed #d1d5db; display: flex; align-items: center; justify-content: center; cursor: pointer; overflow: hidden; margin: 0 auto; background: #f9fafb; transition: 0.2s; }
    .group-avatar-upload:hover { border-color: #2563eb; background: #eff6ff; }
    .group-avatar-upload img { width: 100%; height: 100%; object-fit: cover; }
    .members-list-container { max-height: 250px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; background: #f9fafb; }
    .member-item { display: flex; align-items: center; padding: 8px; border-bottom: 1px solid #eee; transition: 0.2s; cursor: pointer; }
    .member-item:last-child { border-bottom: none; }
    .member-item:hover { background: #fff; }
    .member-item input { margin-left: 10px; width: 16px; height: 16px; cursor: pointer; }
    .member-avatar { width: 32px; height: 32px; border-radius: 50%; margin-left: 10px; object-fit: cover; border: 1px solid #ddd; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title"><?php echo $pageTitle; ?></span></div>
            <div><a href="chat.php" class="btn btn-secondary">بازگشت به پیام‌رسان</a></div>
        </div>

        <div class="card" style="background: white; padding: 30px; border-radius: 12px; border: 1px solid #e5e7eb; max-width: 900px; margin: 0 auto; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
            <form id="createChatForm" onsubmit="handleCreateChat(event)" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                <input type="hidden" name="action" value="create_chat">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
                
                <div class="row" style="display: flex; flex-wrap: wrap; gap: 20px;">
                    <div class="col" style="flex: 0 0 150px; text-align: center;">
                        <label class="form-label" style="display:block; margin-bottom:10px;">تصویر <?php echo ($type=='group')?'گروه':'کانال'; ?></label>
                        <div class="group-avatar-upload" onclick="document.getElementById('chatImg').click()">
                            <img id="previewImg" src="../assets/images/profile-icon.png" alt="Icon">
                        </div>
                        <input type="file" id="chatImg" name="avatar" hidden accept="image/*" onchange="previewImage(this)">
                        <small style="display:block; margin-top:5px; color:#6b7280; font-size:0.8rem;">تغییر تصویر</small>
                    </div>
                    
                    <div class="col" style="flex: 1; min-width: 250px;">
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label class="form-label">نام <?php echo ($type=='group')?'گروه':'کانال'; ?> <span style="color:red">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="مثلاً: تیم فنی">
                        </div>
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label class="form-label">توضیحات (Bio)</label>
                            <textarea name="bio" class="form-control" rows="3" placeholder="توضیحات و قوانین..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 30px;">
                    <label class="form-label">افزودن <?php echo ($type=='channel')?'مشترکین':'اعضا'; ?></label>
                    <input type="text" id="memberSearch" class="form-control" placeholder="جستجوی نام..." style="margin-bottom:10px;" onkeyup="filterMembers()">
                    
                    <div class="members-list-container" id="membersListContainer">
                        <?php if (count($users) > 0): ?>
                            <?php foreach($users as $u): 
                                $uImg = (!empty($u['profile_image']) && $u['profile_image']!='default.png' && file_exists(__DIR__ . '/../../public_html/uploads/profiles/' . $u['profile_image'])) 
                                        ? '../uploads/profiles/' . $u['profile_image'] 
                                        : '../assets/images/profile-icon.png';
                            ?>
                            <label class="member-item" data-name="<?php echo htmlspecialchars($u['first_name'].' '.$u['last_name'].' '.$u['username']); ?>">
                                <input type="checkbox" name="members[]" value="<?= $u['id'] ?>">
                                <img src="<?= $uImg ?>" class="member-avatar">
                                <div>
                                    <div style="font-weight:bold; font-size:0.9rem;"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></div>
                                    <div style="font-size:0.8rem; color:#9ca3af;">@<?= htmlspecialchars($u['username']) ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align:center; padding:20px; color:#999;">کاربری یافت نشد.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="text-align: left; margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <a href="chat.php" class="btn btn-outline">انصراف</a>
                    <button type="submit" class="btn btn-primary">ایجاد <?php echo ($type=='group')?'گروه':'کانال'; ?></button>
                </div>
            </form>
        </div>
    </div>
    <footer class="main-footer page-actions">© <?php echo date("Y"); ?> سامانه اتوماسیون اداری</footer>
</main>

<script>
    function previewImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) { document.getElementById('previewImg').src = e.target.result; }
            reader.readAsDataURL(input.files[0]);
        }
    }
    
    function filterMembers() {
        const filter = document.getElementById('memberSearch').value.toLowerCase();
        const items = document.querySelectorAll('.member-item');
        items.forEach(item => {
            const name = item.getAttribute('data-name').toLowerCase();
            item.style.display = name.includes(filter) ? 'flex' : 'none';
        });
    }

    function handleCreateChat(e) {
        e.preventDefault();
        const form = e.target;
        const btn = form.querySelector('button[type="submit"]');
        const originalText = btn.innerText;
        
        const name = form.querySelector('input[name="name"]').value.trim();
        if(!name) { alert('نام الزامی است'); return; }

        btn.disabled = true; btn.innerText = 'در حال پردازش...';
        const fd = new FormData(form);
        
        fetch('../api/chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                alert(res.message); window.location.href = 'chat.php';
            } else {
                alert(res.message || 'خطا در ایجاد'); btn.disabled = false; btn.innerText = originalText;
            }
        })
        .catch(err => {
            alert('خطا در ارتباط با سرور.'); btn.disabled = false; btn.innerText = originalText;
        });
    }
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>