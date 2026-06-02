/* public_html/assets/js/profile.js */

function openEditModal() {
    const modal = document.getElementById('profileModal');
    if (modal) {
        modal.style.display = 'flex';
        // مخفی کردن پیام‌های قبلی
        const msg = document.getElementById('modalMessage');
        if(msg) msg.style.display = 'none';
        
        // اسکرول به بالا در هنگام باز شدن
        scrollToTop();
    }
}

function closeModal() {
    const modal = document.getElementById('profileModal');
    if (modal) modal.style.display = 'none';
}

function previewProfileImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('modalPreview').src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// الگوریتم صحت کد ملی ایران
function checkNationalCode(code) {
    if (code.length !== 10 || isNaN(code)) return false;
    var codeArr = code.split('');
    var sum = 0;
    for (var i = 0; i < 9; i++) sum += parseInt(codeArr[i]) * (10 - i);
    var reminder = sum % 11;
    var check = parseInt(codeArr[9]);
    return (reminder < 2 && check === reminder) || (reminder >= 2 && check === 11 - reminder);
}

// تابع اسکرول به بالا
function scrollToTop() {
    // اسکرول مودال (برای حالت موبایل که اورلی اسکرول دارد)
    const overlay = document.querySelector('.modal-overlay');
    if (overlay) overlay.scrollTop = 0;
    
    // اسکرول بادی مودال (اگر خود مودال اسکرول دارد)
    const modal = document.querySelector('.modal');
    if (modal) modal.scrollTop = 0;
    
    // اسکرول پنجره اصلی
    window.scrollTo(0, 0);
}

// هندل کردن ارسال فرم
const profileForm = document.getElementById('profileForm');
if (profileForm) {
    profileForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const form = this;
        const formData = new FormData(form);
        const msgBox = document.getElementById('modalMessage');
        const btn = form.querySelector('button[type="submit"]');
        const originalText = btn.innerText;
        
        // اعتبارسنجی سمت کاربر
        const mobile = document.getElementById('mobile').value;
        const nationalCode = document.getElementById('nationalCode').value;
        
        if (nationalCode && !checkNationalCode(nationalCode)) {
            alert('کد ملی وارد شده معتبر نیست.');
            return;
        }
        if (!/^09[0-9]{9}$/.test(mobile)) {
            alert('شماره موبایل صحیح نیست (فرمت: 09xxxxxxxxx).');
            return;
        }

        btn.disabled = true;
        btn.innerText = 'در حال پردازش...';

        fetch('profile.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.text()) // استفاده از text برای دیباگ بهتر
        .then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("Invalid JSON:", text);
                throw new Error("پاسخ سرور معتبر نیست. (متن خطا در کنسول)");
            }
        })
        .then(data => {
            btn.disabled = false;
            btn.innerText = originalText;
            
            if (data.status === 'success') {
                msgBox.innerHTML = '<div class="alert alert-success">' + data.message + '</div>';
                msgBox.className = 'alert modal-success'; 
                msgBox.style.display = 'block';
                
                scrollToTop(); // اسکرول به بالا برای دیدن پیام

                setTimeout(() => { location.reload(); }, 1000);
            } else {
                msgBox.innerHTML = '<div class="alert alert-error">' + data.message + '</div>';
                msgBox.className = 'alert modal-error';
                msgBox.style.display = 'block';
                
                scrollToTop(); // اسکرول به بالا برای دیدن پیام
            }
        })
        .catch(error => {
            btn.disabled = false;
            btn.innerText = originalText;
            console.error('Fetch Error:', error);
            msgBox.innerHTML = '<div class="alert alert-error">خطا در ارتباط با سرور: ' + error.message + '</div>';
            msgBox.className = 'alert modal-error';
            msgBox.style.display = 'block';
            
            scrollToTop(); // اسکرول به بالا برای دیدن پیام
        });
    });
}