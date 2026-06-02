/* public_html/assets/js/notes.js */

let currentFilter = 'active'; 

// فعال‌سازی درگ و دراپ روی لیست یادداشت‌ها
document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('notesList');
    if (el) {
        new Sortable(el, {
            animation: 150,
            ghostClass: 'sortable-ghost', // کلاس برای آیتم در حال جابجایی
            dragClass: 'sortable-drag',
            handle: '.note-card', // کل کارت قابل درگ کردن است
            delay: 100, // تاخیر کم برای جلوگیری از کلیک تصادفی در موبایل
            delayOnTouchOnly: true,
            onEnd: function (evt) {
                // اینجا می‌توانید ترتیب جدید را به سرور ارسال کنید
                // فعلاً فقط ظاهر جابجا می‌شود
                // console.log('Dropped!', evt.oldIndex, evt.newIndex);
            },
        });
    }
});

function openNoteModal(mode = 'add', noteData = null) {
    const modal = document.getElementById('noteModal');
    if (!modal) return;
    
    modal.style.display = 'flex';
    // اسکرول به بالا
    const msg = document.getElementById('modalMessage');
    if(msg) msg.style.display = 'none';

    if (mode === 'add') {
        document.getElementById('modalTitle').innerText = 'یادداشت جدید';
        document.getElementById('formAction').value = 'add';
        document.getElementById('noteId').value = '';
        document.getElementById('noteTitle').value = '';
        document.getElementById('noteContent').value = '';
        selectColor('#ffffff');
    } else if (mode === 'edit' && noteData) {
        document.getElementById('modalTitle').innerText = 'ویرایش یادداشت';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('noteId').value = noteData.id;
        document.getElementById('noteTitle').value = noteData.title;
        document.getElementById('noteContent').value = noteData.content;
        selectColor(noteData.color);
    }
}

function closeModal() {
    const modal = document.getElementById('noteModal');
    if (modal) modal.style.display = 'none';
}

function selectColor(color) {
    document.getElementById('selectedColor').value = color;
    document.querySelectorAll('.color-option').forEach(el => {
        el.classList.remove('selected');
        if (el.dataset.color === color) el.classList.add('selected');
    });
}

function filterNotes(type) {
    currentFilter = type;
    document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
    document.getElementById('btn-' + type).classList.add('active');
    loadNotes();
}

function loadNotes() {
    const search = document.getElementById('searchInput').value;
    window.location.href = `notes.php?filter=${currentFilter}&q=${encodeURIComponent(search)}`;
}

document.getElementById('noteForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const btn = this.querySelector('button[type="submit"]');
    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerText = 'در حال ذخیره...';

    fetch('notes.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            closeModal();
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(err => alert('خطا در ارتباط با سرور'))
    .finally(() => {
        btn.disabled = false;
        btn.innerText = originalText;
    });
});

function noteAction(action, id) {
    if (action === 'delete' && !confirm('آیا از حذف این یادداشت اطمینان دارید؟')) return;

    const formData = new FormData();
    formData.append('action', action);
    formData.append('id', id);

    fetch('notes.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(err => alert('خطا در انجام عملیات'));
}

document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') loadNotes();
});