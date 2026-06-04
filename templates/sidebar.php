<?php
// templates/sidebar.php

if (!isset($basePath)) $basePath = '';

if (!isset($menuItems) || !is_array($menuItems)) {
    $possiblePaths = [
        __DIR__ . '/../includes/menu_config.php',
        $_SERVER['DOCUMENT_ROOT'] . '/includes/menu_config.php',
        __DIR__ . '/../../includes/menu_config.php'
    ];
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) { $menuItems = require $path; break; }
    }
}
if (!isset($menuItems)) $menuItems = [];

if (!function_exists('hasPermission')) { function hasPermission($perm) { return true; } }

global $pdo;
$letterBadges = [];
if (isset($_SESSION['user_id']) && isset($pdo) && function_exists('getLetterBadges')) {
    $isAdminUser = (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager']));
    $letterBadges = getLetterBadges($pdo, $_SESSION['user_id'], $isAdminUser);
}

// تشخیص صفحه فعال
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
?>
<aside class="sidebar" id="sidebar">
    <ul class="sidebar-menu">
        <?php foreach ($menuItems as $item):
            if (isset($item['type']) && $item['type'] === 'separator'): ?>
                <li class="sidebar-separator-title"><?= htmlspecialchars($item['title']) ?></li>
                <?php continue; endif;
            if (isset($item['perm']) && !hasPermission($item['perm'])) continue;

            $hasSubmenu = isset($item['submenu']) && is_array($item['submenu']);

            // شمارش بج والد
            $parentBadgeSum = 0;
            // تشخیص اینکه آیا یکی از زیرمنوها فعال است
            $parentIsActive = false;
            if ($hasSubmenu) {
                foreach ($item['submenu'] as $sub) {
                    if (isset($sub['badge']) && isset($letterBadges[$sub['badge']])) {
                        $parentBadgeSum += (int)$letterBadges[$sub['badge']];
                    }
                    if (isset($sub['link']) && basename($sub['link']) === $currentScript) {
                        $parentIsActive = true;
                    }
                }
            } else {
                if (isset($item['link']) && basename($item['link']) === $currentScript) {
                    $parentIsActive = true;
                }
            }
        ?>
        <li class="sidebar-item">
            <?php if ($hasSubmenu): ?>
                <div class="sidebar-link submenu-toggle<?= $parentIsActive ? ' active-page' : '' ?>"
                     onclick="toggleSubmenu(this)"
                     data-auto-open="<?= $parentIsActive ? 'true' : 'false' ?>">
                    <div>
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             style="color:<?= $item['icon_color'] ?>"><?= $item['svg'] ?></svg>
                        <span><?= htmlspecialchars($item['title']) ?></span>
                        <?php if ($parentBadgeSum > 0): ?>
                            <span class="parent-badge"><?= $parentBadgeSum ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="submenu-arrow">‹</span>
                </div>
                <ul class="submenu<?= $parentIsActive ? ' open' : '' ?>">
                    <?php foreach ($item['submenu'] as $sub):
                        if (isset($sub['perm']) && !hasPermission($sub['perm'])) continue;
                        $link = $sub['link'];
                        if ($link !== '#' && strpos($link, 'http') === false) $link = $basePath . $link;
                        $badgeVal = (isset($sub['badge']) && isset($letterBadges[$sub['badge']])) ? $letterBadges[$sub['badge']] : 0;
                        $isActive = (basename($sub['link']) === $currentScript);
                    ?>
                    <li>
                        <a href="<?= $link ?>" class="<?= $isActive ? 'active-page' : '' ?>">
                            <span><?= htmlspecialchars($sub['title']) ?></span>
                            <?php if ($badgeVal > 0): ?>
                                <span class="sub-badge-val"><?= $badgeVal ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php else:
                $link = $item['link'];
                if ($link !== '#' && strpos($link, 'http') === false) $link = $basePath . $link;
            ?>
                <a href="<?= $link ?>" class="sidebar-link<?= $parentIsActive ? ' active-page' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         style="color:<?= $item['icon_color'] ?>"><?= $item['svg'] ?></svg>
                    <span><?= htmlspecialchars($item['title']) ?></span>
                </a>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</aside>

<script>
// باز کردن خودکار زیرمنوی فعال هنگام بارگذاری
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.submenu-toggle[data-auto-open="true"]').forEach(function (toggle) {
        const submenu = toggle.nextElementSibling;
        const arrow = toggle.querySelector('.submenu-arrow');
        if (submenu && submenu.classList.contains('submenu')) {
            submenu.classList.add('open');
            if (arrow) arrow.style.transform = 'rotate(-90deg)';
        }
    });
});
</script>
