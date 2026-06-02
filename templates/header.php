<?php
// templates/header.php

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($basePath)) $basePath = '';

global $pdo;

$fullname = $_SESSION['fullname'] ?? 'کاربر ناشناس';
$role = isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? 'مدیر سیستم' : 'کاربر';

$myProfileImg = $basePath . 'assets/images/profile-icon.png';
if (function_exists('getUserProfileImage') && isset($pdo)) {
    $myProfileImg = getUserProfileImage($pdo, $_SESSION['user_id'], $basePath);
}

// ==========================================
// استخراج تعداد اعلانات و پیام‌های چت
// ==========================================
$unreadAnnouncements = 0;
$unreadChats = 0;

if (isset($pdo) && isset($_SESSION['user_id'])) {
    // 1. تعداد اعلانات سیستمی
    try {
        $stmtAnn = $pdo->prepare("SELECT COUNT(id) FROM user_notifications WHERE user_id = ? AND is_read = 0");
        $stmtAnn->execute([$_SESSION['user_id']]);
        $unreadAnnouncements = (int)$stmtAnn->fetchColumn();
    } catch (Exception $e) {}

    // 2. تعداد پیام‌های خوانده نشده در چت (استفاده از تابعی که قبلا در functions.php کامل کردیم)
    if (function_exists('getLetterBadges')) {
        $badges = getLetterBadges($pdo, $_SESSION['user_id'], ($role === 'مدیر سیستم'));
        $unreadChats = isset($badges['chat_unread']) ? (int)$badges['chat_unread'] : 0;
    }
}
// ==========================================

$extraCss = ($extraCss ?? '') . '
<style>
    .notif-container { position: relative; }
    .notif-dropdown {
        display: none; position: absolute; left: 0; top: 120%; background: #fff; width: 320px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; border: 1px solid #e5e7eb;
        z-index: 1000; overflow: hidden;
    }
    .notif-dropdown.active { display: flex; flex-direction: column; animation: fadeIn 0.2s ease-out; }
    .notif-header { padding: 12px 15px; background: #f8fafc; font-weight: bold; border-bottom: 1px solid #e5e7eb; font-size: 0.9rem; color: #1f2937; display:flex; justify-content:space-between; flex-shrink: 0; }
    .notif-body { max-height: 350px; overflow-y: auto; flex: 1; }
    .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; display: flex; gap: 10px; text-decoration: none; transition: background 0.2s; cursor: pointer; }
    .notif-item:hover { background: #f8fafc; }
    .notif-item.unread { background: #eff6ff; }
    .notif-icon { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
    .notif-text { flex: 1; min-width: 0; /* برای جلوگیری از خروج متن از کادر */ }
    .notif-title { font-size: 0.85rem; font-weight: bold; color: #111827; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .notif-desc { font-size: 0.75rem; color: #6b7280; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .notif-empty { padding: 20px; text-align: center; color: #9ca3af; font-size: 0.85rem; }
    .notif-footer { padding: 10px; text-align: center; background: #f8fafc; border-top: 1px solid #e5e7eb; flex-shrink: 0; }
    .notif-footer a { color: #2563eb; text-decoration: none; font-size: 0.8rem; font-weight: bold; display: block; }
    
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

    /* ریسپانسیو برای موبایل */
    @media (max-width: 576px) {
        .notif-dropdown {
            position: fixed;
            top: 60px; /* چسبیدن به زیر هدر */
            left: 10px;
            right: 10px;
            width: auto; /* گرفتن کل عرض با حاشیه 10 پیکسل */
            max-height: calc(100vh - 80px); /* جلوگیری از خروج باکس از پایین صفحه */
        }
    }
</style>';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo isset($pageTitle) ? $pageTitle : 'اتوماسیون اداری'; ?></title>
    <link rel="icon" type="image/png" sizes="512x512" href="<?php echo $basePath; ?>assets/images/favicon.png">
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/dashboard.css">
    <?php if(isset($extraCss)) echo $extraCss; ?>
</head>
<body>

    <div class="print-header">
        <div class="print-header-right"><img src="<?php echo $basePath; ?>assets/images/logo.png" alt="Logo" class="print-logo"></div>
        <div class="print-header-center">گزارش سیستم</div>
        <div class="print-header-left">تاریخ: <?php echo function_exists('jdate') ? jdate('Y/m/d') : date('Y/m/d'); ?></div>
    </div>

    <header class="main-header">
        <div class="header-right">
            <button class="menu-btn" onclick="toggleSidebar()">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>
            <span class="brand-title">آتنا زیست درمان</span>
        </div>

        <div class="header-left">
            <!-- آیکون چت و شمارشگر آن -->
            <a href="<?php echo $basePath; ?>admin/chat.php" class="icon-btn" title="گفتگو" id="headerChatIcon" style="position: relative;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                <?php if($unreadChats > 0): ?>
                    <span id="headerChatBadge" class="badge" style="position:absolute; top:-5px; right:-5px; background:#ef4444; color:white; border-radius:50%; padding:2px 6px; font-size:0.7rem; font-weight:bold; min-width:18px; text-align:center; box-shadow:0 2px 5px rgba(0,0,0,0.2);"><?php echo $unreadChats; ?></span>
                <?php endif; ?>
            </a>
            
            <div class="notif-container">
                <a href="javascript:void(0)" class="icon-btn" title="اعلانات شما" id="bellIcon" onclick="toggleNotifMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                    <?php if($unreadAnnouncements > 0): ?>
                        <span id="annUnreadBadge" class="badge" style="position:absolute; top:-5px; right:-5px; background:red; color:white; border-radius:50%; padding:2px 6px; font-size:0.7rem; font-weight:bold; min-width:18px; text-align:center; box-shadow:0 2px 5px rgba(0,0,0,0.2);"><?php echo $unreadAnnouncements; ?></span>
                    <?php endif; ?>
                </a>
                
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <span>اعلانات سیستم</span>
                        <span style="font-size:0.8rem; cursor:pointer; color:#2563eb;" onclick="markAllReadUi()">تیک خوانده شده</span>
                    </div>
                    <div class="notif-body" id="notifList">
                        <div class="notif-empty">در حال دریافت...</div>
                    </div>
                    <div class="notif-footer">
                        <a href="<?php echo $basePath; ?>admin/announcements.php">مشاهده تابلو اعلانات عمومی سازمان</a>
                    </div>
                </div>
            </div>
            
            <div class="profile-container" onclick="toggleProfileMenu(event)">
                <div class="profile-info">
                    <span class="profile-name"><?php echo htmlspecialchars($fullname); ?></span>
                    <span class="profile-role"><?php echo $role; ?></span>
                </div>
                <img src="<?php echo $myProfileImg; ?>" class="profile-img">
                <span class="dropdown-arrow">▼</span>
                
                <div class="dropdown-menu" id="profileDropdown">
                    <div class="dropdown-header">حساب کاربری</div>
                    <a href="<?php echo $basePath; ?>admin/profile.php" class="dropdown-item">پروفایل من</a>
                    <a href="<?php echo $basePath; ?>logout.php" class="dropdown-item logout">خروج از حساب</a>
                </div>
            </div>
        </div>
    </header>

    <script>
        window.__CSRF_TOKEN = <?php echo json_encode(function_exists('csrf_token') ? csrf_token() : ''); ?>;
        
        function toggleNotifMenu(e) { 
            e && e.stopPropagation(); 
            document.getElementById('notifDropdown').classList.toggle('active');
            document.getElementById('profileDropdown').classList.remove('active');
            if(document.getElementById('notifDropdown').classList.contains('active')) fetchNotifsForDropdown();
        }
        function toggleProfileMenu(e) { 
            e && e.stopPropagation(); 
            document.getElementById('profileDropdown').classList.toggle('active'); 
            document.getElementById('notifDropdown').classList.remove('active');
        }
        window.addEventListener('click', e => { 
            if(!e.target.closest('.notif-container')) document.getElementById('notifDropdown').classList.remove('active');
            if(!e.target.closest('.profile-container')) document.getElementById('profileDropdown').classList.remove('active'); 
        });

        (function () {
            const pollUrlBase = <?php echo json_encode($basePath . 'api/notifications_poll.php'); ?>;
            const markUrl = <?php echo json_encode($basePath . 'api/notifications_mark_read.php'); ?>;
            const markAllUrl = <?php echo json_encode($basePath . 'api/notifications_mark_all_read.php'); ?>;
            let lastId = Number(localStorage.getItem('notif_last_id') || 0);
            
            function shouldNotify() { return document.hidden || !document.hasFocus(); }
            
            function normalizeUrl(url) {
                if (!url) return '';
                try {
                    const baseUrl = new URL(<?php echo json_encode($basePath); ?>, window.location.origin);
                    return new URL(url, baseUrl).toString();
                } catch (e) { return url; }
            }

            window.clickNotif = function(id, url) {
                const target = normalizeUrl(url);
                const fd = new FormData();
                fd.append('id', String(id));
                if (window.__CSRF_TOKEN) fd.append('csrf_token', window.__CSRF_TOKEN);
                
                fetch(markUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(() => {
                    if (target) window.location.href = target;
                });
            }

            window.markAllReadUi = async function() {
                try {
                    const fd = new FormData();
                    if (window.__CSRF_TOKEN) fd.append('csrf_token', window.__CSRF_TOKEN);
                    const response = await fetch(markAllUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
                    const res = await response.json();
                    
                    if(res.status === 'success') {
                        document.getElementById('notifList').innerHTML = '<div class="notif-empty">همه اعلانات خوانده شدند.</div>';
                        const badge = document.getElementById('annUnreadBadge');
                        if(badge) badge.remove();
                    }
                } catch (e) {}
            }

            window.fetchNotifsForDropdown = async function() {
                try {
                    const r = await fetch(pollUrlBase + '?last_id=0', { credentials: 'same-origin' });
                    const j = await r.json();
                    if (j && j.status === 'success') {
                        const list = document.getElementById('notifList');
                        if (j.items.length === 0) {
                            list.innerHTML = '<div class="notif-empty">اعلان جدیدی وجود ندارد.</div>';
                            return;
                        }
                        let html = '';
                        j.items.forEach(item => {
                            const icon = item.icon_url ? normalizeUrl(item.icon_url) : normalizeUrl('assets/images/profile-icon.png');
                            html += `
                                <div class="notif-item unread" onclick="clickNotif(${item.id}, '${item.url || ''}')">
                                    <img src="${icon}" class="notif-icon">
                                    <div class="notif-text">
                                        <div class="notif-title">${item.title}</div>
                                        <div class="notif-desc">${item.body}</div>
                                    </div>
                                </div>
                            `;
                        });
                        list.innerHTML = html;
                    }
                } catch (e) {}
            }

            function showBrowserNotification(item) {
                if (!('Notification' in window) || Notification.permission !== 'granted') return;
                if (!shouldNotify()) return;
                const iconUrl = item.icon_url ? normalizeUrl(item.icon_url) : normalizeUrl('assets/images/profile-icon.png');
                const n = new Notification(item.title || 'اعلان جدید', {
                    body: item.body || '',
                    tag: 'notif-' + item.id,
                    icon: iconUrl
                });
                n.onclick = () => {
                    clickNotif(item.id, item.url);
                    n.close();
                };
            }

            async function pollNotifications() {
                try {
                    const r = await fetch(pollUrlBase + '?last_id=' + encodeURIComponent(lastId), { credentials: 'same-origin' });
                    const j = await r.json();
                    if (j && j.status === 'success') {
                        let badge = document.getElementById('annUnreadBadge');
                        if (j.unread_count > 0) {
                            if(!badge) {
                                badge = document.createElement('span');
                                badge.id = 'annUnreadBadge';
                                badge.className = 'badge';
                                badge.style.cssText = 'position:absolute; top:-5px; right:-5px; background:red; color:white; border-radius:50%; padding:2px 6px; font-size:0.7rem; font-weight:bold; min-width:18px; text-align:center; box-shadow:0 2px 5px rgba(0,0,0,0.2);';
                                document.getElementById('bellIcon').style.position = 'relative';
                                document.getElementById('bellIcon').appendChild(badge);
                            }
                            badge.textContent = j.unread_count;
                        } else {
                            if(badge) badge.remove(); 
                        }

                        if (Array.isArray(j.items) && j.items.length > 0) {
                            j.items.forEach(item => {
                                if (item.id > lastId) lastId = item.id;
                                showBrowserNotification(item);
                            });
                            localStorage.setItem('notif_last_id', String(lastId));
                        }
                    }
                } catch (e) {}
            }

            if ('Notification' in window && Notification.permission === 'default') {
                document.addEventListener('click', function requestOnce() {
                    Notification.requestPermission().catch(() => {});
                }, { once: true });
            }

            setInterval(pollNotifications, 15000);
        })();
    </script>