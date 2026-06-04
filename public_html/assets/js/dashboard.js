// public_html/assets/js/dashboard.js

// باز/بسته کردن سایدبار
function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var main    = document.getElementById('mainContent');
    if (!sidebar) return;
    if (window.innerWidth <= 768) {
        sidebar.classList.toggle('active');
    } else {
        sidebar.classList.toggle('collapsed');
        if (main) main.classList.toggle('expanded');
    }
}

// باز/بسته کردن زیرمنو
function toggleSubmenu(element) {
    var submenu = element.nextElementSibling;
    var arrow   = element.querySelector('.submenu-arrow');
    if (submenu && submenu.classList.contains('submenu')) {
        var isOpen = submenu.classList.toggle('open');
        if (arrow) arrow.style.transform = isOpen ? 'rotate(-90deg)' : 'rotate(0deg)';
    }
}

// بستن منوها با کلیک بیرون
window.addEventListener('click', function (event) {
    if (!event.target.closest('.profile-container')) {
        document.querySelectorAll('.dropdown-menu.active').forEach(function (d) {
            d.classList.remove('active');
        });
    }
    if (window.innerWidth <= 768) {
        var sidebar = document.getElementById('sidebar');
        var menuBtn = document.querySelector('.menu-btn');
        if (sidebar && menuBtn &&
            !sidebar.contains(event.target) &&
            !menuBtn.contains(event.target) &&
            sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
        }
    }
});

// فلش زیرمنوهای باز (صفحه‌ای که از قبل active است) هنگام بارگذاری
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.submenu.open').forEach(function (submenu) {
        var arrow = submenu.previousElementSibling && submenu.previousElementSibling.querySelector('.submenu-arrow');
        if (arrow) arrow.style.transform = 'rotate(-90deg)';
    });
});
