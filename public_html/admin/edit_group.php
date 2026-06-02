<?php
/*
 * فایل: public_html/admin/edit_group.php
 * توضیحات: پنل پیشرفته مدیریت اطلاعات و اعضای گروه / کانال (رسپانسیو و استاندارد)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'user';
$isAdmin = ($userRole === 'admin');

$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$type = $_GET['type'] ?? 'group';

// بررسی وجود گفتگو و نقش کاربر
$stmt = $pdo->prepare("SELECT c.*, p.role as my_role FROM conversations c LEFT JOIN conversation_participants p ON c.id = p.conversation_id AND p.user_id = ? WHERE c.id = ? AND c.type IN ('group', 'channel')");
$stmt->execute([$userId, $groupId]);
$chat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$chat) { die('<div style="text-align:center; padding:50px; font-family:Tahoma;">گروه یا کانال یافت نشد!</div>'); }

$isManager = ($isAdmin || in_array($chat['my_role'], ['owner', 'admin']));

// واکشی لیست اعضای فعلی
$stmtMem = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.username, u.profile_image, p.role FROM conversation_participants p JOIN users u ON p.user_id = u.id WHERE p.conversation_id = ? ORDER BY FIELD(p.role, 'owner', 'admin', 'member'), u.last_name ASC");
$stmtMem->execute([$groupId]);
$members = $stmtMem->fetchAll(PDO::FETCH_ASSOC);

// واکشی کاربرانی که عضو گروه "نیستند" برای نمایش در مودال افزودن عضو
$stmtOut = $pdo->prepare("SELECT id, first_name, last_name, username, profile_image FROM users WHERE status='active' AND id NOT IN (SELECT user_id FROM conversation_participants WHERE conversation_id = ?) ORDER BY last_name ASC");
$stmtOut->execute([$groupId]);
$usersOutside = $stmtOut->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'تنظیمات ' . ($chat['type'] == 'channel' ? 'کانال' : 'گروه');
$basePath = '../';
$extraCss = '<style>
    .card { background: white; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom:20px; }
    
    /* Responsive Member Item */
    .member-item { display: flex; align-items: center; justify-content: space-between; padding: 15px 12px; border-bottom: 1px solid #f1f5f9; transition: 0.2s; flex-wrap: wrap; gap: 15px; }
    .member-item:hover { background: #f8fafc; }
    .member-item:last-child { border-bottom: none; }
    .member-info { display: flex; align-items: center; gap: 15px; flex: 1; min-width: 220px; }
    .member-avatar { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 1px solid #cbd5e1; }
    .role-badge { padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; margin-right: 5px; display: inline-block; margin-top: 4px; }
    .role-owner { background: #fee2e2; color: #991b1b; }
    .role-admin { background: #dcfce7; color: #166534; }
    .role-member { background: #f1f5f9; color: #475569; }
    
    /* استایل دکمه‌های مدیریت پیشرفته (بدون آیکون، وسط‌چین، فونت استاندارد) */
    .member-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .action-btn { display: inline-flex; justify-content: center; align-items: center; text-align: center; font-family: inherit; background: #fff; border-radius: 8px; padding: 8px 16px; cursor: pointer; font-size: 0.9rem; font-weight: bold; transition: 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    .btn-promote { color: #047857; border: 1px solid #a7f3d0; }
    .btn-promote:hover { background: #10b981; color: #fff; border-color: #10b981; }
    .btn-demote { color: #b45309; border: 1px solid #fde68a; }
    .btn-demote:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; }
    .btn-kick { color: #b91c1c; border: 1px solid #fecaca; }
    .btn-kick:hover { background: #ef4444; color: #fff; border-color: #ef4444; }
    
    .group-avatar-upload { width: 100px; height: 100px; border-radius: 50%; border: 2px dashed #cbd5e1; display: flex; align-items: center; justify-content: center; cursor: pointer; overflow: hidden; margin: 0 auto; background: #f9fafb; transition: 0.2s; }
    .group-avatar-upload:hover { border-color: #3b82f6; }
    .group-avatar-upload img { width: 100%; height: 100%; object-fit: cover; }
    
    .flex-row { display: flex; flex-wrap: wrap; gap: 20px; }
    .flex-col-1 { flex: 1; min-width: 300px; }
    .flex-col-2 { flex: 2; min-width: 100%; }
    
    /* Media Queries برای موبایل و تبلت */
    @media (min-width: 992px) {
        .flex-col-2 { min-width: 400px; }
    }
    @media (max-width: 576px) {
        .member-actions { width: 100%; justify-content: space-between; }
        .action-btn { flex: 1; } /* دکمه‌ها تمام عرض را مساوی می‌گیرند */
    }

    /* استایل مودال افزودن عضو */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-box { background: #fff; width: 90%; max-width: 450px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.15); display: flex; flex-direction: column; max-height: 85vh; }
    .modal-header { padding: 18px 20px; background: #f8fafc; font-weight: 800; border-bottom: 1px solid #e2e8f0; color: #1e293b; display:flex; justify-content: space-between; align-items: center;}
    .modal-close { cursor: pointer; font-size: 1.5rem; color: #64748b; line-height: 1; }
    .modal-close:hover { color: #ef4444; }
    .modal-body { padding: 15px; overflow-y: auto; flex: 1; }
    .modal-footer { padding: 15px; border-top: 1px solid #e2e8f0; background: #f8fafc; display:flex; gap:10px;}
    
    .add-member-item { display: flex; align-items: center; padding: 10px; cursor: pointer; transition: 0.2s; border-radius: 8px; border: 1px solid transparent;}
    .add-member-item:hover { background: #eff6ff; border-color: #bfdbfe; }
    .add-member-check { width: 18px; height: 18px; margin-left: 15px; cursor: pointer; accent-color: #3b82f6;}
    
    .btn-center { display: flex; justify-content: center; align-items: center; text-align: center; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title"><i class="fas fa-cog"></i> <?php echo $pageTitle; ?></span></div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <?php if($chat['my_role'] !== 'owner'): ?>
                    <button onclick="leaveGroup()" class="btn btn-danger btn-center" style="font-weight:bold;">خروج از <?php echo ($chat['type'] == 'channel' ? 'کانال' : 'گروه'); ?></button>
                <?php endif; ?>
                <a href="chat.php" class="btn btn-secondary btn-center">بازگشت به گفتگو</a>
            </div>
        </div>

        <div class="flex-row">
            <div class="flex-col-1">
                <div class="card">
                    <h3 style="font-size:1.1rem; color:#1e293b; margin-bottom:20px; border-bottom:2px solid #e2e8f0; padding-bottom:10px;">اطلاعات پایه</h3>
                    <?php if($isManager): ?>
                        <form id="editGroupForm" onsubmit="handleEdit(event)">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="action" value="edit_group">
                            <input type="hidden" name="group_id" value="<?php echo $groupId; ?>">
                            
                            <div style="text-align: center; margin-bottom: 20px;">
                                <?php $imgSrc = ($chat['avatar'] && $chat['avatar'] !== 'default_group.png' && file_exists(__DIR__ . '/../../public_html/uploads/chat_avatars/' . $chat['avatar'])) ? '../uploads/chat_avatars/'.$chat['avatar'] : '../assets/images/profile-icon.png'; ?>
                                <div class="group-avatar-upload" onclick="document.getElementById('avatarInput').click()">
                                    <img src="<?php echo $imgSrc; ?>" id="previewImg">
                                </div>
                                <input type="file" id="avatarInput" name="avatar" hidden accept="image/*" onchange="previewImage(this)">
                                <div style="font-size:0.8rem; color:#64748b; margin-top:8px;">تغییر تصویر</div>
                            </div>

                            <div class="form-group" style="margin-bottom: 15px;">
                                <label class="form-label">نام <?php echo ($chat['type'] == 'channel' ? 'کانال' : 'گروه'); ?></label>
                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($chat['title']); ?>" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label class="form-label">توضیحات (Bio)</label>
                                <textarea name="bio" class="form-control" rows="3"><?php echo htmlspecialchars($chat['description']); ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-center" style="width: 100%; padding: 12px;">ذخیره تغییرات</button>
                        </form>
                    <?php else: ?>
                        <div style="text-align: center; margin-bottom: 20px;">
                            <?php $imgSrc = ($chat['avatar'] && $chat['avatar'] !== 'default_group.png' && file_exists(__DIR__ . '/../../public_html/uploads/chat_avatars/' . $chat['avatar'])) ? '../uploads/chat_avatars/'.$chat['avatar'] : '../assets/images/profile-icon.png'; ?>
                            <img src="<?php echo $imgSrc; ?>" style="width:100px; height:100px; border-radius:50%; object-fit:cover; border:2px solid #e2e8f0;">
                        </div>
                        <div style="text-align:center;">
                            <h4 style="font-weight:900; color:#0f172a; margin-bottom:5px;"><?php echo htmlspecialchars($chat['title']); ?></h4>
                            <p style="color:#64748b; font-size:0.9rem; line-height:1.6;"><?php echo nl2br(htmlspecialchars($chat['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex-col-2">
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:2px solid #e2e8f0; padding-bottom:10px; flex-wrap:wrap; gap:10px;">
                        <h3 style="font-size:1.1rem; color:#1e293b; margin:0;">اعضای <?php echo ($chat['type'] == 'channel' ? 'کانال' : 'گروه'); ?> (<?php echo count($members); ?> نفر)</h3>
                        <?php if($isManager): ?>
                            <button onclick="document.getElementById('addMemberModal').style.display='flex'" class="btn btn-outline btn-center" style="font-size:0.85rem; font-weight:bold; color:#2563eb; border-color:#bfdbfe; background:#eff6ff;">افزودن اعضای جدید</button>
                        <?php endif; ?>
                    </div>
                    
                    <div id="membersList" style="max-height: 500px; overflow-y:auto; padding-right:5px;">
                        <?php foreach($members as $m): 
                            $uImg = ($m['profile_image'] && $m['profile_image'] !== 'default.png' && file_exists(__DIR__ . '/../../public_html/uploads/profiles/' . $m['profile_image'])) ? '../uploads/profiles/'.$m['profile_image'] : '../assets/images/profile-icon.png';
                            $roleFa = ($m['role'] == 'owner') ? 'مالک (Owner)' : (($m['role'] == 'admin') ? 'مدیر (Admin)' : 'عضو (Member)');
                        ?>
                        <div class="member-item">
                            <div class="member-info">
                                <img src="<?php echo $uImg; ?>" class="member-avatar">
                                <div>
                                    <div style="font-weight:bold; color:#1e293b;">
                                        <?php echo htmlspecialchars($m['first_name'].' '.$m['last_name']); ?>
                                        <div class="role-badge role-<?php echo $m['role']; ?>"><?php echo $roleFa; ?></div>
                                    </div>
                                    <div style="font-size:0.8rem; color:#64748b;">@<?php echo htmlspecialchars($m['username']); ?></div>
                                </div>
                            </div>
                            
                            <?php if($isManager && $m['role'] !== 'owner'): ?>
                                <div class="member-actions">
                                    <?php if($m['role'] === 'member' && ($chat['my_role'] === 'owner' || $isAdmin)): ?>
                                        <button onclick="manageUser(<?php echo $m['id']; ?>, 'promote')" class="action-btn btn-promote">ارتقا به مدیر</button>
                                    <?php elseif($m['role'] === 'admin' && ($chat['my_role'] === 'owner' || $isAdmin)): ?>
                                        <button onclick="manageUser(<?php echo $m['id']; ?>, 'demote')" class="action-btn btn-demote">عزل از مدیریت</button>
                                    <?php endif; ?>
                                    
                                    <button onclick="manageUser(<?php echo $m['id']; ?>, 'kick')" class="action-btn btn-kick">اخراج</button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</main>

<div id="addMemberModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <span>افزودن عضو جدید</span>
            <span class="modal-close" onclick="document.getElementById('addMemberModal').style.display='none'">&times;</span>
        </div>
        <div class="modal-body">
            <input type="text" id="userSearch" placeholder="جستجوی نام یا نام کاربری..." class="form-control" style="margin-bottom: 15px;" onkeyup="filterModalUsers()">
            
            <div id="modalUserList">
                <?php if(empty($usersOutside)): ?>
                    <div style="text-align:center; padding:20px; color:#94a3b8;">تمامی پرسنل فعال عضو این گروه هستند.</div>
                <?php else: ?>
                    <?php foreach($usersOutside as $uo): 
                        $uoImg = ($uo['profile_image'] && $uo['profile_image'] !== 'default.png' && file_exists(__DIR__ . '/../../public_html/uploads/profiles/' . $uo['profile_image'])) ? '../uploads/profiles/'.$uo['profile_image'] : '../assets/images/profile-icon.png';
                    ?>
                    <label class="add-member-item" data-name="<?php echo htmlspecialchars($uo['first_name'].' '.$uo['last_name'].' '.$uo['username']); ?>">
                        <input type="checkbox" class="add-member-check" value="<?php echo $uo['id']; ?>">
                        <img src="<?php echo $uoImg; ?>" class="member-avatar">
                        <div>
                            <div style="font-weight:bold; color:#1e293b;"><?php echo htmlspecialchars($uo['first_name'].' '.$uo['last_name']); ?></div>
                            <div style="font-size:0.8rem; color:#64748b;">@<?php echo htmlspecialchars($uo['username']); ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="document.getElementById('addMemberModal').style.display='none'" class="btn btn-outline btn-center" style="flex:1;">انصراف</button>
            <button onclick="submitNewMembers()" class="btn btn-primary btn-center" style="flex:1;">افزودن انتخاب شده‌ها</button>
        </div>
    </div>
</div>

<script>
    // بستن مودال با کلیک بیرون
    document.getElementById('addMemberModal').addEventListener('click', function(e) {
        if(e.target === this) this.style.display = 'none';
    });

    // جستجوی زنده در مودال
    function filterModalUsers() {
        const query = document.getElementById('userSearch').value.toLowerCase();
        document.querySelectorAll('.add-member-item').forEach(item => {
            const name = item.dataset.name.toLowerCase();
            item.style.display = name.includes(query) ? 'flex' : 'none';
        });
    }

    // پیش‌نمایش عکس آپلودی
    function previewImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) { document.getElementById('previewImg').src = e.target.result; }
            reader.readAsDataURL(input.files[0]);
        }
    }

    // ذخیره تغییرات عکس و نام گروه
    function handleEdit(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        const origText = btn.innerText;

        btn.disabled = true;
        btn.innerText = 'در حال ذخیره...';

        fetch('../api/chat_api.php', { method: 'POST', body: new FormData(e.target) })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                alert(res.message);
                location.reload();
            } else {
                alert(res.message || 'خطا در ارتباط با سرور');
            }
        })
        .catch(() => alert('خطا در شبکه'))
        .finally(() => { btn.disabled = false; btn.innerText = origText; });
    }

    // ارتقا، عزل و اخراج تک کاربر (با ارسال توکن CSRF)
    function manageUser(userId, actionType) {
        let msg = actionType === 'promote' ? 'آیا از ارتقای این کاربر به "مدیر" اطمینان دارید؟' : 
                 (actionType === 'demote' ? 'آیا از عزل این کاربر اطمینان دارید؟' : 'آیا از اخراج این کاربر مطمئن هستید؟');
                 
        if(actionType !== 'add' && !confirm(msg)) return;

        const fd = new FormData();
        fd.append('action', 'manage_group_member');
        fd.append('group_id', <?php echo $groupId; ?>);
        fd.append('user_id', userId);
        fd.append('do', actionType);
        fd.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');

        fetch('../api/chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                location.reload(); 
            } else {
                alert(res.message || 'خطا در سرور');
            }
        }).catch(() => alert('خطا در ارتباط با سرور'));
    }

    // افزودن گروهی اعضای انتخاب شده از مودال
    function submitNewMembers() {
        const selected = Array.from(document.querySelectorAll('.add-member-check:checked')).map(cb => cb.value);
        if(selected.length === 0) { alert('لطفاً حداقل یک نفر را انتخاب کنید.'); return; }

        const fd = new FormData();
        fd.append('action', 'add_group_members');
        fd.append('group_id', <?php echo $groupId; ?>);
        fd.append('members', JSON.stringify(selected));
        fd.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');

        fetch('../api/chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                alert(res.message);
                location.reload();
            } else {
                alert(res.message || 'خطا در افزودن اعضا');
            }
        }).catch(() => alert('خطا در ارتباط با سرور'));
    }

    // خروج از گروه (داوطلبانه)
    function leaveGroup() {
        if(!confirm('آیا از خروج از این گفتگو اطمینان دارید؟ در صورت خروج دیگر به پیام‌های آن دسترسی نخواهید داشت.')) return;

        const fd = new FormData();
        fd.append('action', 'leave_group');
        fd.append('group_id', <?php echo $groupId; ?>);
        fd.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');

        fetch('../api/chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                alert('شما با موفقیت خارج شدید.');
                window.location.href = 'chat.php';
            } else {
                alert(res.message || 'خطا در خروج');
            }
        }).catch(() => alert('خطا در ارتباط با سرور'));
    }
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>