/* public_html/assets/js/departments.js */

function openModal(mode) {
    const modal = document.getElementById('deptModal');
    if (!modal) return;
    
    modal.style.display = 'flex';
    
    // مخفی کردن پیام‌های قبلی
    const msgBox = document.getElementById('modalMessage');
    if(msgBox) msgBox.style.display = 'none';

    if (mode === 'add') {
        document.getElementById('modalTitle').innerText = 'افزودن دپارتمان جدید';
        document.getElementById('formAction').value = 'add';
        document.getElementById('deptId').value = '';
        document.getElementById('deptName').value = '';
        document.getElementById('deptManager').value = '';
        document.getElementById('deptStatus').value = 'active';
    }
}

function editDept(data) {
    openModal('edit');
    document.getElementById('modalTitle').innerText = 'ویرایش دپارتمان';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('deptId').value = data.id;
    document.getElementById('deptName').value = data.name;
    document.getElementById('deptManager').value = data.manager_id || '';
    document.getElementById('deptStatus').value = data.status;
}

function closeModal() {
    const modal = document.getElementById('deptModal');
    if (modal) modal.style.display = 'none';
}

function submitDeptForm(event) {
    event.preventDefault();
    
    const form = document.getElementById('deptForm');
    const formData = new FormData(form);
    const msgBox = document.getElementById('modalMessage');
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerText;
    
    btn.disabled = true;
    btn.innerText = 'در حال ذخیره...';

    fetch('departments.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerText = originalText;
        
        if (data.status === 'success') {
            msgBox.innerHTML = '<div class="alert alert-success">' + data.message + '</div>';
            msgBox.style.display = 'block';
            setTimeout(() => { location.reload(); }, 1000);
        } else {
            msgBox.innerHTML = '<div class="alert alert-error">' + data.message + '</div>';
            msgBox.style.display = 'block';
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerText = originalText;
        msgBox.innerHTML = '<div class="alert alert-error">خطا در ارتباط با سرور</div>';
        msgBox.style.display = 'block';
    });
}