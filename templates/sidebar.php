<?php
// templates/sidebar.php

// تنظیم مسیر پایه اگر تعریف نشده باشد
if (!isset($basePath)) $basePath = '';

// بارگذاری منو اگر موجود نباشد
if (!isset($menuItems) || !is_array($menuItems)) {
    $possiblePaths = [
        __DIR__ . '/../includes/menu_config.php',
        $_SERVER['DOCUMENT_ROOT'] . '/includes/menu_config.php',
        __DIR__ . '/../../includes/menu_config.php'
    ];
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $menuItems = require $path;
            break;
        }
    }
}
if (!isset($menuItems)) $menuItems = [];

// تابع دسترسی
if (!function_exists('hasPermission')) { function hasPermission($perm) { return true; } }

// محاسبه نشانگرهای عددی (Badges) برای منوها
global $pdo;
$letterBadges = [];
if (isset($_SESSION['user_id']) && isset($pdo) && function_exists('getLetterBadges')) {
    $isAdminUser = (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager']));
    $letterBadges = getLetterBadges($pdo, $_SESSION['user_id'], $isAdminUser);
}
?>
<aside class="sidebar" id="sidebar">
    <ul class="sidebar-menu">
        <?php foreach ($menuItems as $item): 
            if (isset($item['type']) && $item['type'] === 'separator'): ?>
                <li class="sidebar-separator-title"><?php echo $item['title']; ?></li>
                <?php continue; 
            endif; 
            
            // بررسی دسترسی
            if (isset($item['perm']) && !hasPermission($item['perm'])) continue; 
            
            $hasSubmenu = isset($item['submenu']) && is_array($item['submenu']);
            
            // محاسبه مجموع بج‌های زیرمنو برای نمایش در منوی اصلی (پدر)
            $parentBadgeSum = 0;
            if ($hasSubmenu) {
                foreach ($item['submenu'] as $sub) {
                    if (isset($sub['badge']) && isset($letterBadges[$sub['badge']])) {
                        $parentBadgeSum += (int)$letterBadges[$sub['badge']];
                    }
                }
            }
        ?>
        
        <li class="sidebar-item">
            <?php if ($hasSubmenu): ?>
                <div class="sidebar-link submenu-toggle" onclick="toggleSubmenu(this)">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:<?php echo $item['icon_color']; ?>"><?php echo $item['svg']; ?></svg>
                        <span><?php echo $item['title']; ?></span>
                        
                        <?php if($parentBadgeSum > 0): ?>
                            <span class="parent-badge" style="background:#ef4444; color:#fff; border-radius:12px; padding:2px 6px; font-size:0.75rem; font-weight:bold; margin-right:5px;">
                                <?php echo $parentBadgeSum; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <span class="submenu-arrow">‹</span>
                </div>
                <ul class="submenu">
                    <?php foreach ($item['submenu'] as $sub): 
                        if (isset($sub['perm']) && !hasPermission($sub['perm'])) continue;

                        $link = $sub['link'];
                        if ($link != '#' && strpos($link, 'http') === false) {
                            $link = $basePath . $link;
                        }
                        
                        $badgeVal = (isset($sub['badge']) && isset($letterBadges[$sub['badge']])) ? $letterBadges[$sub['badge']] : 0;
                    ?>
                    <li>
                        <a href="<?php echo $link; ?>" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                            <span><?php echo $sub['title']; ?></span>
                            
                            <?php if (isset($sub['badge']) && $badgeVal > 0): ?>
                                <span class="sub-badge-val" style="background-color: #ef4444; color: #fff; border-radius: 12px; padding: 3px 8px; font-size: 0.75rem; font-weight: bold; line-height: 1; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);">
                                    <?php echo $badgeVal; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: 
                $link = $item['link'];
                if ($link != '#' && strpos($link, 'http') === false) {
                    $link = $basePath . $link;
                }
            ?>
                <a href="<?php echo $link; ?>" class="sidebar-link">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:<?php echo $item['icon_color']; ?>"><?php echo $item['svg']; ?></svg>
                        <span><?php echo $item['title']; ?></span>
                    </div>
                </a>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</aside>