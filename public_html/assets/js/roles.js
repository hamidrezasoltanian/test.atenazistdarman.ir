/* public_html/assets/js/roles.js */

function openModal(mode) {
    const modal = document.getElementById('roleModal');
    if (!modal) return;
    
    modal.style.display = 'flex';
    
    // مخفی کردن پیام‌ها و اسکرول به بالا
    const msg = document.getElementById('modalMessage');
    const modalBody = document.querySelector('.modal-body');
    if(msg) msg.style.display = 'none';
    if(modalBody) modalBody.scrollTop = 0;

    // ریست فرم
    if (mode === 'add') {
        document.getElementById('modalTitle').innerText = 'ایجاد نقش جدید';
        document.getElementById('formAction').value = 'add';
        document.getElementById('roleId').value = '';
        document.getElementById('roleTitle').value = '';
        document.getElementById('roleName').value = '';
        document.getElementById('roleName').disabled = false;
        
        // خالی کردن همه چک‌باکس‌ها
        document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
    }
}

function editRole(data) {
    openModal('edit');
    
    document.getElementById('modalTitle').innerText = 'ویرایش نقش و دسترسی‌ها';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('roleId').value = data.id;
    document.getElementById('roleTitle').value = data.title;
    document.getElementById('roleName').value = data.name;
    document.getElementById('roleName').disabled = true;
    
    let perms = [];
    try {
        perms = JSON.parse(data.permissions);
    } catch (e) {
        perms = [];
    }

    document.querySelectorAll('input[name="permissions[]"]').forEach(cb => {
        if (perms.includes('all') || perms.includes(cb.value)) {
            cb.checked = true;
        } else {
            cb.checked = false;
        }
    });
}

function closeModal() {
    const modal = document.getElementById('roleModal');
    if (modal) modal.style.display = 'none';
}

function toggleGroup(source, groupClass) {
    const checkboxes = document.querySelectorAll('.' + groupClass);
    checkboxes.forEach(cb => {
        cb.checked = source.checked;
    });
}

// ارسال فرم با AJAX
document.getElementById('roleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const form = this;
    const formData = new FormData(form);
    
    // اگر نقش name غیرفعال است (در حالت ویرایش)، مقدارش را دستی اضافه کن
    if(document.getElementById('roleName').disabled) {
        formData.append('name', document.getElementById('roleName').value);
    }
    
    const msgBox = document.getElementById('modalMessage');
    const modalBody = document.querySelector('.modal-body');
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerText;

    btn.disabled = true;
    btn.innerText = 'در حال ذخیره...';

    // اسکرول به بالا برای دیدن پیام
    if(modalBody) modalBody.scrollTo({ top: 0, behavior: 'smooth' });

    fetch('roles.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerText = originalText;
        
        if (data.status === 'success') {
            msgBox.innerHTML = '<div class="alert alert-success">' + data.message + '</div>';
            msgBox.style.display = 'block';
            // افزایش زمان تا کاربر پیام را ببیند (۱.۵ ثانیه)
            setTimeout(() => { location.reload(); }, 1500);
        } else {
            msgBox.innerHTML = '<div class="alert alert-error">' + data.message + '</div>';
            msgBox.style.display = 'block';
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerText = originalText;
        console.error('Error:', error);
        msgBox.innerHTML = '<div class="alert alert-error">خطا در ارتباط با سرور. لطفاً مجدد تلاش کنید.</div>';
        msgBox.style.display = 'block';
    });
});