// public_html/assets/js/dashboard.js

// تابع باز و بسته کردن سایدبار
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const main = document.getElementById('mainContent');
    
    // در موبایل (عرض کمتر از 768)
    if (window.innerWidth <= 768) {
        sidebar.classList.toggle('active');
    } else {
        // در دسکتاپ
        sidebar.classList.toggle('collapsed');
        main.classList.toggle('expanded');
    }
}

// تابع باز و بسته کردن زیرمنو
function toggleSubmenu(element) {
    // پیدا کردن لیست زیرمنو (تگ ul بعدی)
    const submenu = element.nextElementSibling;
    const arrow = element.querySelector('.submenu-arrow');
    
    if (submenu && submenu.classList.contains('submenu')) {
        // باز/بسته کردن کلاس open
        submenu.classList.toggle('open');
        
        // چرخش فلش
        if (arrow) {
            // اگر باز شد، فلش به پایین بچرخد (90 درجه در جهت عقربه ساعت از حالت چپ)
            // چون در CSS اولیه جهت چپ (‹) است.
            if (submenu.classList.contains('open')) {
                arrow.style.transform = 'rotate(-90deg)'; // چرخش به پایین
            } else {
                arrow.style.transform = 'rotate(0deg)'; // برگشت به چپ
            }
        }
    }
}

// تابع باز و بسته کردن منوی پروفایل
function toggleProfileMenu() {
    const menu = document.getElementById('profileDropdown');
    menu.classList.toggle('active');
}

// بستن منوها با کلیک بیرون از آن‌ها
window.addEventListener('click', function(event) {
    // بستن منوی پروفایل
    if (!event.target.closest('.profile-container')) {
        const dropdowns = document.getElementsByClassName("dropdown-menu");
        for (let i = 0; i < dropdowns.length; i++) {
            const openDropdown = dropdowns[i];
            if (openDropdown.classList.contains('active')) {
                openDropdown.classList.remove('active');
            }
        }
    }

    // بستن سایدبار در موبایل اگر بیرونش کلیک شد (اختیاری)
    if (window.innerWidth <= 768) {
        const sidebar = document.getElementById('sidebar');
        const menuBtn = document.querySelector('.menu-btn');
        if (!sidebar.contains(event.target) && !menuBtn.contains(event.target) && sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
        }
    }
});