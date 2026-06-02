/* public_html/assets/js/customers.js */

/**
 * نمایش مودال جزئیات مشتری
 * @param {number} id شناسه مشتری در جدول
 */
function viewCustomer(id) {
    const modal = document.getElementById('customerModal');
    const loading = document.getElementById('modalLoading');
    const content = document.getElementById('detailsContent');
    
    if (!modal) return;
    
    modal.style.display = 'flex';
    loading.style.display = 'flex';
    content.innerHTML = ''; // پاک کردن محتوای قبلی

    const formData = new FormData();
    formData.append('action', 'get_details');
    formData.append('id', id);

    fetch('customers.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        loading.style.display = 'none';
        
        if (data.status === 'success') {
            const c = data.data;
            
            // تولید کدهای HTML مربوط به برچسب‌های گرافیکی
            let tagsHtml = '';
            if (c.tags && c.tags.length > 0) {
                tagsHtml = '<div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;">';
                c.tags.forEach(t => {
                    let bg = t.color || '#e2e8f0';
                    let color = '#0f172a';
                    let borderColor = 'rgba(0,0,0,0.1)';
                    
                    // هندل کردن برچسب‌های خطا دار
                    if (t.title && t.title.includes('خطا')) {
                        bg = '#fee2e2';
                        color = '#b91c1c';
                        borderColor = '#fecaca';
                    }
                    
                    tagsHtml += `<span style="background:${bg}; color:${color}; padding:5px 12px; border-radius:8px; font-size:0.8rem; font-weight:bold; border:1px solid ${borderColor};">${t.title}</span>`;
                });
                tagsHtml += '</div>';
            } else {
                tagsHtml = '<div style="margin-top:8px;"><span class="text-muted" style="font-size:0.8rem; background:#f1f5f9; padding:5px 12px; border-radius:8px;">بدون برچسب</span></div>';
            }
            
            // ساخت HTML جزئیات نهایی مودال
            let html = `
                <div class="detail-item detail-full">
                    <span class="detail-label">نام شرکت / شخص</span>
                    <span class="detail-value" style="color:#2563eb; font-size:1.2rem;">${c.company_name || '-'}</span>
                </div>
                
                <div class="detail-item detail-full" style="background:#f8fafc; padding:15px; border-radius:10px; border:1px dashed #cbd5e1; margin-bottom:10px;">
                    <span class="detail-label" style="font-size:0.9rem; color:#475569; font-weight:bold;">برچسب‌های مشتری</span>
                    ${tagsHtml}
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">کد مشتری</span>
                    <span class="detail-value">${c.company_code || '-'}</span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">نوع مشتری</span>
                    <span class="detail-value">${c.type_name || '-'}</span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">ثبت‌کننده</span>
                    <span class="detail-value">${c.manager_name || '-'}</span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">پیگیری‌کنندگان</span>
                    <span class="detail-value">${c.followers_list || '-'}</span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">موبایل</span>
                    <span class="detail-value" dir="ltr">${c.mobile || '-'}</span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">تلفن ثابت</span>
                    <span class="detail-value" dir="ltr">${c.phone || '-'}</span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">استان</span>
                    <span class="detail-value">${c.state || '-'}</span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">شهر</span>
                    <span class="detail-value">${c.city || '-'}</span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">کد پستی</span>
                    <span class="detail-value">${c.postal_code || '-'}</span>
                </div>
                
                <div class="detail-item">
                    <span class="detail-label">آخرین بروزرسانی</span>
                    <span class="detail-value">${c.updated_at || '-'}</span>
                </div>

                <div class="detail-item detail-full">
                    <span class="detail-label">آدرس کامل</span>
                    <span class="detail-value">${c.address || '-'}</span>
                </div>
                
                <div class="detail-item detail-full">
                    <span class="detail-label">شناسه یکتا فرادیس</span>
                    <span class="detail-value" style="font-size:0.8rem; color:#94a3b8;">${c.company_num || '-'}</span>
                </div>
            `;
            
            content.innerHTML = html;
        } else {
            content.innerHTML = `<div style="color:red; text-align:center; padding:20px; grid-column:1/-1;">${data.message}</div>`;
        }
    })
    .catch(error => {
        loading.style.display = 'none';
        content.innerHTML = `<div style="color:red; text-align:center; padding:20px; grid-column:1/-1;">خطا در دریافت اطلاعات.</div>`;
        console.error(error);
    });
}

function closeModal() {
    const modal = document.getElementById('customerModal');
    if (modal) modal.style.display = 'none';
}

function printAllCustomers() {
    const btn = document.querySelector('.btn-secondary');
    if(!btn) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال دریافت...';

    const urlParams = new URLSearchParams(window.location.search);
    const searchQuery = urlParams.get('q') || '';

    fetch('customers.php?print_data=1&q=' + encodeURIComponent(searchQuery))
    .then(response => response.text())
    .then(html => {
        const printContainer = document.getElementById('printTableBody');
        if(printContainer) {
            printContainer.innerHTML = html;
            btn.disabled = false;
            btn.innerHTML = originalText;
            setTimeout(() => { window.print(); }, 500);
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        alert('خطا در چاپ');
    });
}