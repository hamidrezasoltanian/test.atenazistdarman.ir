/* public_html/assets/js/users.js */

let isDatepickerInitialized = false;

function openModal(mode) {
    const modal = document.getElementById('userModal');
    if (!modal) return;
    
    modal.style.display = 'flex';
    const msgBox = document.getElementById('modalMessage');
    if(msgBox) msgBox.style.display = 'none';

    // راه‌اندازی تقویم با تاخیر
    setTimeout(() => {
        const dateInput = document.getElementById('birthDate');
        if (dateInput) {
            dateInput.removeAttribute('readonly');
            dateInput.addEventListener('click', function() {
                this.focus();
            });
        }

        if (typeof kamaDatepicker === 'function' && !isDatepickerInitialized) {
            kamaDatepicker('birthDate', {
                buttonsColor: "#2563eb",
                forceFarsiDigits: true,
                markToday: true,
                gotoToday: true,
                closeAfterSelect: true,
                nextButtonIcon: "بعدی",
                previousButtonIcon: "قبلی"
            });
            isDatepickerInitialized = true;
        }
    }, 200);

    if (mode === 'add') {
        document.getElementById('modalTitle').innerText = 'افزودن کاربر جدید';
        document.getElementById('formAction').value = 'add';
        document.getElementById('userId').value = '';
        
        const inputs = modal.querySelectorAll('input:not([type=hidden]):not([type=submit]), select');
        inputs.forEach(el => el.value = '');

        const passHelp = document.getElementById('passwordHelp');
        if (passHelp) passHelp.style.display = 'none';
        
        const status = document.getElementById('status');
        if (status) status.value = 'active';
        
        const preview = document.getElementById('profilePreview');
        if (preview) preview.src = '../assets/images/profile-icon.png';
    }
}

function editUser(data) {
    openModal('edit');
    document.getElementById('modalTitle').innerText = 'ویرایش کاربر';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('userId').value = data.id;
    
    document.getElementById('firstName').value = data.first_name || '';
    document.getElementById('lastName').value = data.last_name || '';
    document.getElementById('username').value = data.username || '';
    document.getElementById('mobile').value = data.mobile || '';
    document.getElementById('nationalCode').value = data.national_code || '';
    document.getElementById('personnelCode').value = data.personnel_code || '';
    document.getElementById('birthDate').value = data.birth_date || ''; 
    document.getElementById('postalCode').value = data.postal_code || '';
    document.getElementById('address').value = data.address || '';
    
    if(document.getElementById('department')) document.getElementById('department').value = data.department_id || '';
    if(document.getElementById('role')) document.getElementById('role').value = data.role || '';
    if(document.getElementById('status')) document.getElementById('status').value = data.status || 'active';

    const pass = document.getElementById('password');
    if (pass) {
        pass.value = '';
        pass.placeholder = 'فقط در صورت تغییر وارد کنید';
    }
    const passHelp = document.getElementById('passwordHelp');
    if (passHelp) passHelp.style.display = 'block';

    const preview = document.getElementById('profilePreview');
    if (preview) {
        if (data.profile_image && data.profile_image !== 'default.png') {
            preview.src = '../uploads/profiles/' + data.profile_image;
            preview.onerror = function() { this.src = '../assets/images/profile-icon.png'; };
        } else {
            preview.src = '../assets/images/profile-icon.png';
        }
    }
}

function closeModal() {
    const modal = document.getElementById('userModal');
    if (modal) modal.style.display = 'none';
}

function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('profilePreview');
            if (preview) preview.src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function checkNationalCode(code) {
    if (code.length !== 10 || isNaN(code)) return false;
    var codeArr = code.split('');
    var sum = 0;
    for (var i = 0; i < 9; i++) sum += parseInt(codeArr[i]) * (10 - i);
    var reminder = sum % 11;
    var check = parseInt(codeArr[9]);
    return (reminder < 2 && check === reminder) || (reminder >= 2 && check === 11 - reminder);
}

// تابع جدید برای اسکرول به بالای مودال
function scrollToTop() {
    const modal = document.querySelector('.modal'); // المنت اسکرول‌دار در موبایل
    if (modal) {
        modal.scrollTo({ top: 0, behavior: 'smooth' });
        modal.scrollTop = 0; // برای اطمینان در مرورگرهای قدیمی‌تر
    }
    
    const overlay = document.querySelector('.modal-overlay');
    if (overlay) overlay.scrollTop = 0;
    
    // اسکرول ویندو اصلی
    window.scrollTo(0, 0);
}

function submitUserForm(event) {
    event.preventDefault();
    
    const nationalCode = document.getElementById('nationalCode').value;
    const mobile = document.getElementById('mobile').value;
    const msgBox = document.getElementById('modalMessage');
    
    if(msgBox) msgBox.style.display = 'none';
    
    let errors = [];
    if (nationalCode && !checkNationalCode(nationalCode)) errors.push('کد ملی نامعتبر است.');
    if (!/^09[0-9]{9}$/.test(mobile)) errors.push('شماره موبایل نامعتبر است (فرمت صحیح: 09xxxxxxxxx).');
    
    if (errors.length > 0) {
        if(msgBox) {
            msgBox.innerHTML = errors.join('<br>');
            msgBox.className = 'alert modal-error';
            msgBox.style.display = 'block';
            scrollToTop(); // اسکرول به بالا برای دیدن خطا
        } else {
            alert(errors.join('\n'));
        }
        return;
    }

    const form = document.getElementById('userForm');
    const formData = new FormData(form);
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerText;
    
    btn.disabled = true;
    btn.innerText = 'در حال پردازش...';

    fetch('users.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.text())
    .then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error("Invalid JSON response:", text);
            throw new Error("پاسخ سرور معتبر نیست."); 
        }
    })
    .then(data => {
        btn.disabled = false;
        btn.innerText = originalText;
        
        if(msgBox) {
            if (data.status === 'success') {
                msgBox.innerHTML = data.message;
                msgBox.className = 'alert modal-success';
                msgBox.style.display = 'block';
                
                scrollToTop(); // اسکرول به بالا برای دیدن پیام موفقیت
                
                setTimeout(() => { location.reload(); }, 1000);
            } else {
                msgBox.innerHTML = data.message;
                msgBox.className = 'alert modal-error';
                msgBox.style.display = 'block';
                
                scrollToTop(); // اسکرول به بالا برای دیدن پیام خطا
            }
        } else {
            alert(data.message);
            if(data.status === 'success') location.reload();
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerText = originalText;
        
        if(msgBox) {
            msgBox.innerHTML = 'خطا در ارتباط: ' + error.message;
            msgBox.className = 'alert modal-error';
            msgBox.style.display = 'block';
            scrollToTop(); // اسکرول به بالا
        } else {
            alert('خطا در ارتباط: ' + error.message);
        }
        console.error('Error:', error);
    });
}

function printAllUsers() {
    const btn = document.querySelector('.btn-secondary');
    if(!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ آماده‌سازی...';

    const urlParams = new URLSearchParams(window.location.search);
    const searchQuery = urlParams.get('q') || '';

    fetch('users.php?print_data=1&q=' + encodeURIComponent(searchQuery))
    .then(response => response.text())
    .then(html => {
        const printContainer = document.getElementById('printTableBody');
        if(printContainer) {
            printContainer.innerHTML = html;
            btn.disabled = false;
            btn.innerHTML = originalText;
            setTimeout(() => { window.print(); }, 500);
        } else {
            throw new Error('کانتینر جدول پرینت یافت نشد.');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        alert('خطا در دریافت اطلاعات برای چاپ: ' + error.message);
    });
}