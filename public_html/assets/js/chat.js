let currentChatId = null;
let currentChatType = 'private';
let lastMessageId = 0;
let firstMessageId = 0; 
let hasMoreOlderMessages = true;
let isLoadingOlder = false;

let pollingInterval = null;
let currentTab = 'all';
let mediaRecorder = null;
let audioChunks = [];
let recordingInterval = null;
let isUserScrolling = false;
let currentReplyId = null;
let isLoadingMessages = false;
let isCheckingNew = false;
let typingPingTimer = null;
let lastTypingState = false;
let needsReloadMessages = false;
let currentChatIsGroup = false;

window.currentPinnedMessages = [];
window.currentPinnedIndex = 0;

let currentPollRate = 2000; 
const ACTIVE_POLL_RATE = 2000;
const IDLE_POLL_RATE = 10000; 
const HIDDEN_POLL_RATE = 30000; 
let idleTimer = null;

window.editingMessageId = null;
window.currentGroupMembers = []; 

let chatLastMessageIds = {}; 

const MAX_SIZE_MB = 20;
const COMMON_EMOJIS = ['😀','😂','😍','😭','😡','👍','👎','🙏','❤️','💔','🎉','🔥','👀','✅','❌'];

// ==================================================================
// تولید صدای نوتیفیکیشن بدون نیاز به فایل Mp3 (Web Audio API)
// ==================================================================
function playNotifSound() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = 'sine';
        osc.frequency.setValueAtTime(600, ctx.currentTime);
        gain.gain.setValueAtTime(0.2, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.2);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.2);
    } catch(e) {}
}

document.addEventListener('DOMContentLoaded', function() {
    if ("Notification" in window && Notification.permission !== "granted" && Notification.permission !== "denied") {
        Notification.requestPermission();
    }
    
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            currentPollRate = HIDDEN_POLL_RATE;
            startPolling();
        } else {
            resetIdleTimer();
            if (currentChatId) silentUpdateContacts();
        }
    });

    ['mousemove', 'keydown', 'scroll', 'click', 'touchstart'].forEach(evt => {
        document.addEventListener(evt, resetIdleTimer);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const lightbox = document.getElementById('imageLightbox');
            if (lightbox && lightbox.style.display === 'flex') lightbox.style.display = 'none';
            hideContextMenu();
            
            const searchPanel = document.getElementById('chatSearchPanel');
            if (searchPanel) searchPanel.style.display = 'none';
        }
    });

    const style = document.createElement('style');
    style.innerHTML = `
        .chat-item.has-unread { background: #eff6ff !important; border-right: 4px solid #3b82f6 !important; transition: all 0.3s ease; }
        .chat-item.has-unread .chat-name::after { content: ''; display: inline-block; width: 8px; height: 8px; background: #ef4444; border-radius: 50%; margin-right: 8px; box-shadow: 0 0 5px rgba(239,68,68,0.5); vertical-align: middle; }
        
        .chat-context-menu { display: none; position: fixed; background: #fff; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); border: 1px solid #e2e8f0; z-index: 10000; min-width: 160px; overflow: hidden; font-family: 'Vazirmatn', Tahoma, sans-serif; }
        .chat-context-menu ul { list-style: none; margin: 0; padding: 5px 0; }
        .chat-context-menu li { padding: 10px 15px; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.85rem; color: #334155; transition: 0.2s; font-weight: bold;}
        .chat-context-menu li:hover { background: #f1f5f9; color: #2563eb; }
        .chat-context-menu li svg { width: 16px; height: 16px; opacity: 0.7; }
        .chat-context-menu li.delete-btn { color: #ef4444; border-top: 1px solid #f1f5f9; }
        .chat-context-menu li.delete-btn:hover { background: #fef2f2; color: #dc2626;}

        .msg-reactions { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
        .reaction-badge { background: rgba(0,0,0,0.05); border: 1px solid rgba(0,0,0,0.05); border-radius: 12px; padding: 2px 6px; font-size: 0.75rem; color: #334155; cursor: default; }
        .msg-out .reaction-badge { background: rgba(255,255,255,0.2); border-color: rgba(255,255,255,0.3); color: #fff; }
        
        .message-bubble { transition: transform 0.2s; }
        .chat-main.drag-over { background: #f1f5f9 !important; border: 2px dashed #3b82f6; opacity: 0.8; }
        
        /* کلاس‌های بهینه شده برای موبایل */
        .pin-bar { display:none; background:#f8fafc; padding:8px 10px; border-bottom:1px solid #e2e8f0; cursor:pointer; align-items:center; gap:8px; border-left: 3px solid #3b82f6; width: 100%; box-sizing: border-box; flex-shrink:0; }
        .pin-bar-content { flex: 1; min-width: 0; overflow: hidden; }
        .pin-bar-header { display: flex; justify-content: space-between; align-items: center; }
        .pin-title { font-size: 0.75rem; color: #3b82f6; font-weight: bold; white-space: nowrap; }
        .pin-counter { font-size: 0.7rem; color: #94a3b8; font-weight: bold; white-space: nowrap; margin-right: 5px; }
        .pin-text { font-size: 0.85rem; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pin-close { color: #94a3b8; font-size: 1.4rem; cursor: pointer; padding: 0 5px; line-height: 1; flex-shrink: 0; }
        
        .search-panel-wrapper { display:none; background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:10px; flex-shrink:0; z-index:20; width: 100%; box-sizing: border-box; }
        .search-panel-inner { display:flex; gap:10px; width: 100%; align-items:center; }
        .search-input-box { flex:1; border-radius:20px; font-size:0.85rem; padding:6px 15px; border:1px solid #cbd5e1; min-width: 0; }
        .search-res-item:hover { background: #eff6ff; }
        .mention-item:hover { background: #f8fafc; }

        #offlineBanner { display:none; position:fixed; top:0; left:0; width:100%; background:#ef4444; color:#fff; text-align:center; padding:6px; z-index:999999; font-size:0.85rem; font-weight:bold; transition: 0.3s; }

        @media (max-width: 576px) {
            .pin-bar { padding: 5px 8px; }
            .pin-text { font-size: 0.8rem; }
            .search-panel-wrapper { padding: 6px; }
            .search-input-box { padding: 5px 10px; font-size: 0.8rem; }
            #chatSearchBtnToggle { margin-left: 0 !important; }
        }
    `;
    document.head.appendChild(style);

    // ==================================================================
    // تزریق نوار قطعی اینترنت (Offline Detector)
    // ==================================================================
    document.body.insertAdjacentHTML('afterbegin', `<div id="offlineBanner">عدم اتصال به اینترنت... در حال تلاش مجدد</div>`);
    
    window.addEventListener('offline', () => {
        const ob = document.getElementById('offlineBanner');
        ob.style.display = 'block';
        ob.style.background = '#ef4444';
        ob.innerText = 'عدم اتصال به اینترنت... در حال تلاش مجدد';
    });
    
    window.addEventListener('online', () => {
        const ob = document.getElementById('offlineBanner');
        ob.style.background = '#10b981';
        ob.innerText = 'اتصال اینترنت برقرار شد';
        setTimeout(() => { ob.style.display = 'none'; }, 2500);
        
        // به محض وصل شدن اینترنت، وضعیت را زنده کن
        if(currentChatId) checkAndLoadNewMessages();
        silentUpdateContacts();
    });

    const contextHtml = `
    <div id="chatContextMenu" class="chat-context-menu">
        <ul>
            <li onclick="contextAction('reply')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"></polyline><path d="M20 18v-2a4 4 0 0 0-4-4H4"></path></svg> پاسخ دادن</li>
            <li onclick="contextAction('pin')" id="contextPinBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 17v5"></path><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1v3.76z"></path></svg> سنجاق پیام</li>
            <li onclick="contextAction('edit')" class="edit-btn" id="contextEditBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg> ویرایش پیام</li>
            <li onclick="contextAction('react')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg> پسندیدن (لایک)</li>
            <li onclick="contextAction('forward')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 14 20 9 15 4"></polyline><path d="M4 20v-7a4 4 0 0 1 4-4h12"></path></svg> فوروارد</li>
            <li onclick="contextAction('copy')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg> کپی متن</li>
            <li onclick="contextAction('delete')" class="delete-btn" id="contextDeleteBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> حذف پیام</li>
        </ul>
    </div>
    
    <div id="imageLightbox" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:100000; align-items:center; justify-content:center; backdrop-filter:blur(5px);">
        <span onclick="document.getElementById('imageLightbox').style.display='none'" style="position:absolute; top:20px; right:30px; color:white; font-size:40px; cursor:pointer; line-height:1;">&times;</span>
        <img id="lightboxImg" src="" style="max-width:90%; max-height:90%; object-fit:contain; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.5);">
    </div>`;
    document.body.insertAdjacentHTML('beforeend', contextHtml);

    window.openImageModal = function(src) {
        document.getElementById('lightboxImg').src = src;
        document.getElementById('imageLightbox').style.display = 'flex';
    };

    window.jumpToChatAndMessage = function(convId, msgId) {
        const targetChat = window.allContacts && window.allContacts.find(c => c.id == convId);
        if (!targetChat) {
            alert('شما در حال حاضر عضو آن گفتگو نیستید.');
            return;
        }
        
        openChat(targetChat.id, targetChat.name, targetChat.avatar, targetChat.type, targetChat.is_online == 1);
        
        let attempts = 0;
        let checkInt = setInterval(() => {
            attempts++;
            if (!isLoadingMessages) {
                let el = document.getElementById('msg-' + msgId);
                if (el) {
                    scrollToMessage(msgId);
                    el.querySelector('.message-bubble').style.background = '#fff3cd';
                    setTimeout(() => el.querySelector('.message-bubble').style.background = '', 3000);
                    clearInterval(checkInt);
                } else if (attempts > 10) {
                    loadOlderMessages();
                    attempts = 5; 
                }
                if (attempts > 30) clearInterval(checkInt);
            }
        }, 300);
    };

    const urlParams = new URLSearchParams(window.location.search);
    const jumpConv = urlParams.get('jump_conv');
    const jumpMsg = urlParams.get('jump_msg');
    
    if (jumpConv && jumpMsg) {
        setTimeout(() => {
            jumpToChatAndMessage(jumpConv, jumpMsg);
            window.history.replaceState({}, document.title, window.location.pathname);
        }, 1500);
    }

    loadContacts('all');
    
    const msgArea = document.getElementById('messagesArea');
    if(msgArea) {
        msgArea.addEventListener('scroll', function() {
            if (this.scrollHeight - this.scrollTop - this.clientHeight > 150) {
                isUserScrolling = true;
            } else {
                isUserScrolling = false;
            }

            if (this.scrollTop === 0 && !isLoadingOlder && hasMoreOlderMessages && firstMessageId > 0) {
                loadOlderMessages();
            }
        });

        msgArea.addEventListener('contextmenu', function(e) {
            const msgEl = e.target.closest('.msg-wrapper');
            if (!msgEl) return;
            e.preventDefault();
            showContextMenu(e.pageX, e.pageY, msgEl);
        });

        msgArea.addEventListener('dblclick', function(e) {
            const msgEl = e.target.closest('.msg-wrapper');
            if (msgEl) {
                const msgId = parseInt(msgEl.id.replace('msg-', ''));
                const contentEl = msgEl.querySelector('.msg-content');
                const text = contentEl ? contentEl.innerText.replace('📥 دانلود فایل پیوست', '').trim() : '';
                startReply(msgId, text);
                
                const bubble = msgEl.querySelector('.message-bubble');
                if(bubble) {
                    bubble.style.transform = 'scale(1.02)';
                    setTimeout(() => bubble.style.transform = 'scale(1)', 150);
                }
            }
        });

        let pressTimer;
        let startX = 0, startY = 0;

        msgArea.addEventListener('touchstart', function(e) {
            const msgEl = e.target.closest('.msg-wrapper');
            if (!msgEl) return;
            startX = e.touches[0].pageX;
            startY = e.touches[0].pageY;
            
            pressTimer = window.setTimeout(() => {
                showContextMenu(e.touches[0].pageX, e.touches[0].pageY, msgEl);
                if (navigator.vibrate) navigator.vibrate(50);
            }, 400); 
        }, {passive: true});

        msgArea.addEventListener('touchmove', function(e) {
            if (Math.abs(e.touches[0].pageX - startX) > 15 || Math.abs(e.touches[0].pageY - startY) > 15) {
                clearTimeout(pressTimer);
            }
        }, {passive: true});

        msgArea.addEventListener('touchend', () => clearTimeout(pressTimer));
        msgArea.addEventListener('touchcancel', () => clearTimeout(pressTimer));
    }

    startPolling();
    setupUI();
    setupForwardModal();
});

function resetIdleTimer() {
    if (document.hidden) return;
    if (currentPollRate !== ACTIVE_POLL_RATE) {
        currentPollRate = ACTIVE_POLL_RATE;
        startPolling(); 
    }
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => {
        currentPollRate = IDLE_POLL_RATE; 
        startPolling();
    }, 30000); 
}

let activeContextMsgId = null;
let activeContextMsgText = '';
let activeContextIsMine = false;

function showContextMenu(x, y, msgEl) {
    const menu = document.getElementById('chatContextMenu');
    activeContextMsgId = parseInt(msgEl.id.replace('msg-', ''));
    activeContextIsMine = msgEl.classList.contains('msg-out');
    
    const isSystemAlert = msgEl.querySelector('.mention-alert-box') !== null;
    if (isSystemAlert) return;
    
    const contentEl = msgEl.querySelector('.msg-content');
    activeContextMsgText = contentEl ? contentEl.innerText.replace('📥 دانلود فایل پیوست', '').trim() : '';

    document.getElementById('contextDeleteBtn').style.display = activeContextIsMine ? 'flex' : 'none';
    document.getElementById('contextEditBtn').style.display = activeContextIsMine ? 'flex' : 'none';

    menu.style.display = 'block';
    
    const mw = menu.offsetWidth;
    const mh = menu.offsetHeight;
    if (x + mw > window.innerWidth) x = window.innerWidth - mw - 10;
    if (y + mh > window.innerHeight) y = window.innerHeight - mh - 10;
    
    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
}

function hideContextMenu() {
    const menu = document.getElementById('chatContextMenu');
    if (menu) menu.style.display = 'none';
}

window.contextAction = function(action) {
    hideContextMenu();
    if (!activeContextMsgId) return;

    if (action === 'reply') {
        startReply(activeContextMsgId, activeContextMsgText);
    } else if (action === 'forward') {
        openForwardModal(activeContextMsgId);
    } else if (action === 'copy') {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(activeContextMsgText).then(() => alert('متن کپی شد'));
        } else {
            const textArea = document.createElement("textarea");
            textArea.value = activeContextMsgText;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand("Copy");
            textArea.remove();
            alert('متن کپی شد');
        }
    } else if (action === 'delete') {
        if (confirm('آیا از حذف این پیام مطمئن هستید؟')) {
            const fd = new FormData();
            fd.append('action', 'delete_message');
            fd.append('msg_id', activeContextMsgId);
            fetch('../api/chat_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res && res.status === 'success') {
                    const el = document.getElementById('msg-' + activeContextMsgId);
                    if (el) el.remove(); 
                } else {
                    alert(res.message || 'خطا در حذف');
                }
            }).catch(()=> alert('خطا در ارتباط با سرور'));
        }
    } else if (action === 'edit') {
        startEdit(activeContextMsgId, activeContextMsgText);
    } else if (action === 'react') {
        const fd = new FormData();
        fd.append('action', 'react_message');
        fd.append('msg_id', activeContextMsgId);
        fd.append('emoji', '👍');
        fetch('../api/chat_api.php', { method: 'POST', body: fd })
        .then(() => {
            lastMessageId = 0; 
            document.getElementById('messagesArea').innerHTML = '';
            loadMessages(currentChatId); 
        });
    } else if (action === 'pin') {
        const fd = new FormData();
        fd.append('action', 'pin_message');
        fd.append('msg_id', activeContextMsgId);
        fetch('../api/chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res && res.status === 'success') {
                lastMessageId = 0; 
                document.getElementById('messagesArea').innerHTML = '';
                loadMessages(currentChatId); 
            } else {
                alert(res.message || 'شما مجوز سنجاق کردن ندارید');
            }
        });
    }
};

function startEdit(id, text) {
    window.editingMessageId = id;
    const footer = document.querySelector('.chat-footer');
    let editBox = document.getElementById('editPreviewBox');
    
    if (!editBox) {
        editBox = document.createElement('div');
        editBox.id = 'editPreviewBox';
        editBox.className = 'reply-input-preview'; 
        editBox.innerHTML = `
            <div class="reply-info">
                <div class="reply-sender-name" style="color:#10b981;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;vertical-align:middle;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg> در حال ویرایش پیام...</div>
                <div class="reply-text-short" id="editPreviewText"></div>
            </div>
            <div class="reply-close-btn" data-action="cancel-edit">✖</div>
        `;
        footer.appendChild(editBox);
    }
    
    document.getElementById('editPreviewText').innerText = text;
    editBox.classList.add('active');
    
    const input = document.getElementById('messageInput');
    input.innerText = text;
    input.focus();
    toggleInputButtons();
}

function cancelEdit() {
    window.editingMessageId = null;
    const editBox = document.getElementById('editPreviewBox');
    if (editBox) editBox.classList.remove('active');
    document.getElementById('messageInput').innerText = '';
    toggleInputButtons();
}

function escapeHtml(text) {
    if (!text) return "";
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function escapeAttr(text) {
    return String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function setupUI() {
    const menuBtn = document.getElementById('chatMenuBtn');
    if (menuBtn && !document.getElementById('chatSearchBtnToggle')) {
        const parent = menuBtn.parentElement;
        if (parent) {
            parent.style.display = 'flex';
            parent.style.alignItems = 'center';
            parent.style.gap = '10px';
        }
        menuBtn.insertAdjacentHTML('beforebegin', `
            <div id="chatSearchBtnToggle" style="cursor:pointer; font-size:1.2rem; color:#707579; padding:5px; display:flex; align-items:center; justify-content:center; flex-shrink:0;" title="جستجو در پیام‌ها">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </div>
        `);
    }

    const chatHeader = document.querySelector('.chat-header');
    if (chatHeader && !document.getElementById('chatSearchPanel')) {
        chatHeader.insertAdjacentHTML('afterend', `
            <div id="chatSearchPanel" class="search-panel-wrapper">
                <div class="search-panel-inner">
                    <input type="text" id="chatSearchInput" class="search-input-box" placeholder="جستجو در این گفتگو...">
                    <button id="chatSearchCloseBtn" class="btn btn-secondary" style="border-radius:20px; padding:4px 12px; font-size:0.8rem; border:1px solid #ccc; background:#fff; flex-shrink:0;">بستن</button>
                </div>
                <div id="chatSearchResults" style="max-height:250px; overflow-y:auto; margin-top:10px;"></div>
            </div>
        `);
    }

    const chatFooter = document.querySelector('.chat-footer');
    if (chatFooter && !document.getElementById('mentionDropdown')) {
        chatFooter.insertAdjacentHTML('beforeend', `
            <div id="mentionDropdown" style="display:none; position:absolute; bottom:100%; right:15px; width:250px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 -5px 15px rgba(0,0,0,0.1); max-height:200px; overflow-y:auto; z-index:50;">
            </div>
        `);
    }

    const msgInput = document.getElementById('messageInput');
    const fileInput = document.getElementById('fileInput');
    const searchInput = document.getElementById('contactSearch');
    const emojiPanel = document.getElementById('emojiPanel');
    const chatList = document.getElementById('chatList');
    const backBtn = document.getElementById('backToListBtn');
    const fabBtn = document.getElementById('fabBtn');
    const emojiBtn = document.getElementById('emojiBtn');
    const attachBtn = document.getElementById('attachBtn');
    const micBtn = document.getElementById('micBtn');
    const sendBtn = document.getElementById('sendBtn');
    const chatMenu = document.getElementById('chatHeaderMenu');
    const chatTabWrap = document.querySelector('.chat-tabs');
    const cancelRecBtn = document.getElementById('cancelRecBtn');
    const sendRecBtn = document.getElementById('sendRecBtn');
    
    if (msgInput) {
        // ==================================================================
        // حل مشکل کیبورد موبایل (Virtual Keyboard Scroll Fix)
        // ==================================================================
        msgInput.addEventListener('focus', () => {
            setTimeout(() => { if (!isUserScrolling) scrollToBottom(); }, 300);
        });
        
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', () => {
                if (document.activeElement === msgInput) {
                    scrollToBottom();
                }
            });
        }

        msgInput.addEventListener('paste', function(e) {
            if (e.clipboardData && e.clipboardData.files.length > 0) {
                e.preventDefault();
                const file = e.clipboardData.files[0];
                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                showFilePreview(file.name, file.size);
                toggleInputButtons();
            }
        });

        msgInput.addEventListener('input', function() {
            toggleInputButtons();
            scheduleTypingPing();
            handleMentionsInput();
        });
        
        msgInput.addEventListener('keypress', function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }

    const searchToggleBtn = document.getElementById('chatSearchBtnToggle');
    if (searchToggleBtn) {
        searchToggleBtn.addEventListener('click', () => {
            const p = document.getElementById('chatSearchPanel');
            p.style.display = p.style.display === 'none' ? 'block' : 'none';
            if (p.style.display === 'block') document.getElementById('chatSearchInput').focus();
        });
    }
    const searchCloseBtn = document.getElementById('chatSearchCloseBtn');
    if (searchCloseBtn) {
        searchCloseBtn.addEventListener('click', () => {
            document.getElementById('chatSearchPanel').style.display = 'none';
        });
    }

    let chatSearchTimer;
    const chatSearchInput = document.getElementById('chatSearchInput');
    if (chatSearchInput) {
        chatSearchInput.addEventListener('input', function() {
            const q = this.value.trim();
            const resBox = document.getElementById('chatSearchResults');
            if (q.length < 2) { resBox.innerHTML = ''; return; }
            
            clearTimeout(chatSearchTimer);
            chatSearchTimer = setTimeout(() => {
                resBox.innerHTML = '<div style="text-align:center; font-size:0.8rem; color:#64748b;">در حال جستجو...</div>';
                const fd = new FormData();
                fd.append('action', 'search_messages');
                fd.append('conv_id', currentChatId);
                fd.append('keyword', q);
                fetch('../api/chat_api.php', { method:'POST', body:fd })
                .then(r=>r.json())
                .then(res => {
                    if (res && res.status === 'success') {
                        if (res.data.length === 0) {
                            resBox.innerHTML = '<div style="text-align:center; padding:10px; font-size:0.85rem; color:#ef4444;">موردی یافت نشد.</div>';
                        } else {
                            resBox.innerHTML = res.data.map(m => `
                                <div class="search-res-item" onclick="window.scrollToSearchMsg(${m.id})" style="padding:10px; border-bottom:1px solid #e2e8f0; cursor:pointer; transition:0.2s;">
                                    <div style="font-size:0.75rem; font-weight:bold; color:#3b82f6; margin-bottom:4px;">${escapeHtml(m.sender)} <span style="color:#94a3b8; font-weight:normal; margin-right:5px; font-size:0.7rem;">${m.time}</span></div>
                                    <div style="font-size:0.85rem; color:#334155; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(m.text)}</div>
                                </div>
                            `).join('');
                        }
                    } else {
                         resBox.innerHTML = '<div style="text-align:center; padding:10px; font-size:0.85rem; color:#ef4444;">خطا در جستجو.</div>';
                    }
                });
            }, 500);
        });
    }

    window.scrollToSearchMsg = function(id) {
        const el = document.getElementById('msg-' + id);
        if (el) {
            scrollToMessage(id);
            document.getElementById('chatSearchPanel').style.display = 'none';
        } else {
            alert('این پیام در تاریخچه قدیمی است. لطفاً به بالا اسکرول کنید تا بارگذاری شود.');
        }
    };

    const chatMainContainer = document.querySelector('.chat-main');
    if (chatMainContainer) {
        chatMainContainer.addEventListener('dragover', function(e) {
            e.preventDefault();
            chatMainContainer.classList.add('drag-over');
        });
        chatMainContainer.addEventListener('dragleave', function(e) {
            e.preventDefault();
            chatMainContainer.classList.remove('drag-over');
        });
        chatMainContainer.addEventListener('drop', function(e) {
            e.preventDefault();
            chatMainContainer.classList.remove('drag-over');
            if (e.dataTransfer && e.dataTransfer.files.length > 0) {
                const file = e.dataTransfer.files[0];
                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                showFilePreview(file.name, file.size);
                toggleInputButtons();
            }
        });
    }

    if (backBtn) backBtn.addEventListener('click', backToList);
    if (fabBtn) fabBtn.addEventListener('click', toggleFab);
    if (emojiBtn) emojiBtn.addEventListener('click', toggleEmojiPanel);

    if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', function() { fileInput.click(); });
    }

    if (micBtn) micBtn.addEventListener('click', startRecording);
    if (cancelRecBtn) cancelRecBtn.addEventListener('click', cancelRecording);
    if (sendRecBtn) sendRecBtn.addEventListener('click', stopAndSendRecording);
    if (sendBtn) sendBtn.addEventListener('click', sendMessage);

    if (menuBtn && chatMenu) {
        menuBtn.addEventListener('click', function() {
            chatMenu.style.display = (chatMenu.style.display === 'block') ? 'none' : 'block';
        });

        chatMenu.addEventListener('click', function(e) {
            const item = e.target.closest('[data-menu-action]') || e.target.closest('[data-action]');
            if (!item) return;
            const action = item.dataset.menuAction || item.dataset.action;
            if (!action) return;
            chatMenu.style.display = 'none';

            if (action === 'edit-group') {
                if (!currentChatId || currentChatType === 'private') return;
                window.location.href = `edit_group.php?group_id=${currentChatId}&type=${encodeURIComponent(currentChatType)}`;
                return;
            }

            if (action === 'delete-group') {
                if (!currentChatId || currentChatType === 'private') return;
                if (!confirm('آیا از حذف این گفتگو مطمئن هستید؟')) return;
                const fd = new FormData();
                fd.append('action', 'delete_group');
                fd.append('group_id', currentChatId);
                fetch('../api/chat_api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res && res.status === 'success') {
                        alert(res.message || 'حذف شد');
                        backToList();
                        loadContacts(currentTab);
                    } else {
                        alert((res && res.message) ? res.message : 'خطا در حذف');
                    }
                }).catch(() => alert('خطا در ارتباط با سرور'));
                return;
            }

            if (action === 'delete-conversation') {
                if (!currentChatId || currentChatType !== 'private') return;
                if (!confirm('آیا از حذف این گفتگو مطمئن هستید؟')) return;
                const fd = new FormData();
                fd.append('action', 'delete_conversation');
                fd.append('target_id', currentChatId);
                fd.append('chat_type', 'private');
                fetch('../api/chat_api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res && res.status === 'success') {
                        alert('حذف شد');
                        backToList();
                        loadContacts(currentTab);
                    } else {
                        alert((res && res.message) ? res.message : 'خطا در حذف');
                    }
                }).catch(() => alert('خطا در ارتباط با سرور'));
                return;
            }
        });
    }

    if (chatTabWrap) {
        chatTabWrap.addEventListener('click', function(e) {
            const tab = e.target.closest('.chat-tab');
            if (!tab) return;
            const type = tab.dataset.type;
            if (type) filterChats(type);
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                showFilePreview(this.files[0].name, this.files[0].size);
            } else {
                hideFilePreview();
            }
            toggleInputButtons();
        });
    }

    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const val = this.value.toLowerCase();
            document.querySelectorAll('#chatList .chat-item').forEach(item => {
                const text = item.querySelector('.chat-name').innerText.toLowerCase();
                item.style.display = text.includes(val) ? 'flex' : 'none';
            });
        });
    }

    if (chatList) {
        chatList.addEventListener('click', function(e) {
            const item = e.target.closest('.chat-item');
            if (!item) return;
            const id = parseInt(item.dataset.id || '0');
            const name = item.dataset.name || '';
            const avatar = item.dataset.avatar || '../assets/images/profile-icon.png';
            const chatType = item.dataset.chatType || 'private';
            const isOnline = item.dataset.online === '1'; 
            window.currentChatBio = item.dataset.bio || '';
            if (id) openChat(id, name, avatar, chatType, isOnline);
        });
    }

    if (emojiPanel) {
        COMMON_EMOJIS.forEach(emo => {
            const span = document.createElement('span');
            span.style.fontSize = '1.5rem';
            span.style.cursor = 'pointer';
            span.style.padding = '5px';
            span.innerText = emo;
            span.onclick = () => {
                msgInput.innerText += emo;
                toggleInputButtons();
            };
            emojiPanel.appendChild(span);
        });
    }

    document.addEventListener('click', function(e) {
        const fab = document.querySelector('.fab-btn');
        const fabMenu = document.getElementById('fabMenu');
        if (fab && fabMenu && !fab.contains(e.target) && !fabMenu.contains(e.target)) fabMenu.style.display = 'none';
        
        const emoPanel = document.getElementById('emojiPanel');
        const emoBtn = document.querySelector('button[title="ایموجی"]');
        if (emoPanel && emoBtn && !emoPanel.contains(e.target) && !emoBtn.contains(e.target)) emoPanel.style.display = 'none';
        
        const fModal = document.getElementById('forwardModal');
        if (fModal && e.target === fModal) fModal.style.display = 'none';

        const chatMenu = document.getElementById('chatHeaderMenu');
        const menuBtn = document.getElementById('chatMenuBtn');
        if (chatMenu && menuBtn && chatMenu.style.display === 'block' && !chatMenu.contains(e.target) && !menuBtn.contains(e.target)) {
            chatMenu.style.display = 'none';
        }

        if (!e.target.closest('.chat-context-menu')) hideContextMenu();

        const mi = e.target.closest('.mention-item');
        if (mi) {
            const username = mi.dataset.username;
            const text = msgInput.innerText.replace(/\n+$/, '');
            msgInput.innerText = text.replace(/@([^\s<]*)$/u, '@' + username + ' ');
            document.getElementById('mentionDropdown').style.display = 'none';
            
            const range = document.createRange();
            const sel = window.getSelection();
            range.selectNodeContents(msgInput);
            range.collapse(false);
            sel.removeAllRanges();
            sel.addRange(range);
            
            toggleInputButtons();
        }

        const actionEl = e.target.closest('[data-action]');
        if (actionEl) {
            const act = actionEl.dataset.action;
            if (act === 'clear-file') { clearFileInput(); return; }
            if (act === 'cancel-reply') { cancelReply(); return; }
            if (act === 'cancel-edit') { cancelEdit(); return; }
        }
    });
}

function handleMentionsInput() {
    const md = document.getElementById('mentionDropdown');
    if (!md) return;
    if (!currentChatIsGroup || !window.currentGroupMembers || window.currentGroupMembers.length === 0) {
        md.style.display = 'none';
        return;
    }
    const msgInput = document.getElementById('messageInput');
    const text = msgInput.innerText.replace(/\n+$/, ''); 
    
    const match = text.match(/@([^\s<]+)$/u);
    if (match) {
        const q = match[1].toLowerCase();
        const filtered = window.currentGroupMembers.filter(m => 
            (m.username && m.username.toLowerCase().includes(q)) || 
            (m.name && m.name.toLowerCase().includes(q))
        );
        if (filtered.length > 0) {
            md.innerHTML = filtered.map(m => `
                <div class="mention-item" data-username="${m.username || m.name}" style="padding:8px 15px; display:flex; align-items:center; gap:10px; cursor:pointer; border-bottom:1px solid #f1f5f9;">
                    <img src="${escapeAttr(m.avatar)}" style="width:24px; height:24px; border-radius:50%; object-fit:cover;">
                    <div>
                        <div style="font-size:0.8rem; font-weight:bold;">${escapeHtml(m.name)}</div>
                        <div style="font-size:0.7rem; color:#64748b;">@${escapeHtml(m.username || '')}</div>
                    </div>
                </div>
            `).join('');
            md.style.display = 'block';
        } else {
            md.style.display = 'none';
        }
    } else {
        md.style.display = 'none';
    }
}

window.unpinMessage = function(e) {
    if (e) e.stopPropagation(); 
    if (!window.currentPinnedMessages || window.currentPinnedMessages.length === 0) return;
    
    const pin = window.currentPinnedMessages[window.currentPinnedIndex];
    if (!confirm("آیا از برداشتن سنجاق این پیام مطمئن هستید؟")) return;
    
    const fd = new FormData();
    fd.append('action', 'unpin_message');
    fd.append('conv_id', currentChatId);
    fd.append('msg_id', pin.id);
    
    fetch('../api/chat_api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res && res.status === 'success') {
            lastMessageId = 0;
            document.getElementById('messagesArea').innerHTML = '';
            loadMessages(currentChatId);
        } else {
            alert(res.message || 'خطا در عملیات');
        }
    });
};

window.cyclePinnedMessage = function() {
    if (!window.currentPinnedMessages || window.currentPinnedMessages.length === 0) return;
    
    const currentPin = window.currentPinnedMessages[window.currentPinnedIndex];
    scrollToMessage(currentPin.id);
    
    window.currentPinnedIndex = (window.currentPinnedIndex + 1) % window.currentPinnedMessages.length;
    updatePinnedBarUI();
};

function updatePinnedBarUI() {
    const arr = window.currentPinnedMessages;
    const idx = window.currentPinnedIndex;
    if (!arr || arr.length === 0) return;
    
    const pin = arr[idx];
    document.getElementById('pinnedMessageText').innerText = pin.text;
    
    const counterEl = document.getElementById('pinnedMessageCounter');
    if (arr.length > 1) {
        counterEl.innerText = `${idx + 1} از ${arr.length}`;
    } else {
        counterEl.innerText = '';
    }
}

function renderPinnedBar(pinnedArray) {
    const chatHeader = document.querySelector('.chat-header');
    if (!chatHeader) return;
    
    let pinBar = document.getElementById('pinnedMessageBar');
    if (!pinBar) {
        chatHeader.insertAdjacentHTML('afterend', `
            <div id="pinnedMessageBar" class="pin-bar">
                <div class="pin-bar-content" onclick="cyclePinnedMessage()">
                    <div class="pin-bar-header">
                        <div class="pin-title" id="pinnedMessageTitle">پیام سنجاق شده</div>
                        <div class="pin-counter" id="pinnedMessageCounter"></div>
                    </div>
                    <div class="pin-text" id="pinnedMessageText"></div>
                </div>
                <div class="pin-close" onclick="unpinMessage(event)" title="برداشتن سنجاق">&times;</div>
            </div>
        `);
        pinBar = document.getElementById('pinnedMessageBar');
    }

    if (pinnedArray && pinnedArray.length > 0) {
        window.currentPinnedMessages = pinnedArray;
        if (window.currentPinnedIndex >= pinnedArray.length) {
            window.currentPinnedIndex = pinnedArray.length - 1;
        }
        updatePinnedBarUI();
        pinBar.style.display = 'flex';
    } else {
        pinBar.style.display = 'none';
        window.currentPinnedMessages = [];
        window.currentPinnedIndex = 0;
    }
}

function syncGlobalBadgeWithExactCount(exactTotal) {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        const chatLink = sidebar.querySelector('a[href*="chat.php"]'); 
        if (chatLink) {
            let badgeEl = chatLink.querySelector('.sub-badge-val');
            
            if (!badgeEl && exactTotal > 0) {
                badgeEl = document.createElement('span');
                badgeEl.className = 'sub-badge-val';
                badgeEl.style.cssText = 'background-color: #ef4444; color: #fff; border-radius: 12px; padding: 3px 8px; font-size: 0.75rem; font-weight: bold; line-height: 1; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);';
                chatLink.appendChild(badgeEl);
            }

            if (badgeEl) {
                if (exactTotal <= 0) {
                    badgeEl.style.display = 'none';
                    badgeEl.innerText = '0';
                } else {
                    badgeEl.style.display = 'inline-block';
                    badgeEl.innerText = exactTotal;
                }
            }

            const parentItem = chatLink.closest('.sidebar-item');
            if (parentItem) {
                let parentBadgeEl = parentItem.querySelector('.parent-badge');
                if (!parentBadgeEl && exactTotal > 0) {
                    const toggleDiv = parentItem.querySelector('.submenu-toggle div');
                    if (toggleDiv) {
                        parentBadgeEl = document.createElement('span');
                        parentBadgeEl.className = 'parent-badge';
                        parentBadgeEl.style.cssText = 'background:#ef4444; color:#fff; border-radius:12px; padding:2px 6px; font-size:0.75rem; font-weight:bold; margin-right:5px;';
                        toggleDiv.appendChild(parentBadgeEl);
                    }
                }
                
                if (parentBadgeEl) {
                    if (exactTotal <= 0) {
                        parentBadgeEl.style.display = 'none';
                        parentBadgeEl.innerText = '0';
                    } else {
                        parentBadgeEl.style.display = 'inline-block';
                        parentBadgeEl.innerText = exactTotal;
                    }
                }
            }
        }
    }

    let headerChatIcon = document.getElementById('headerChatIcon');
    if (!headerChatIcon) {
        headerChatIcon = document.querySelector('header.main-header a[href*="chat.php"]');
        if(headerChatIcon) {
            headerChatIcon.id = 'headerChatIcon';
            headerChatIcon.style.position = 'relative';
        }
    }

    if (headerChatIcon) {
        let headerBadge = document.getElementById('headerChatBadge');
        if (!headerBadge && exactTotal > 0) {
            headerBadge = document.createElement('span');
            headerBadge.id = 'headerChatBadge';
            headerBadge.className = 'badge';
            headerBadge.style.cssText = 'position:absolute; top:-5px; right:-5px; background:#ef4444; color:white; border-radius:50%; padding:2px 6px; font-size:0.7rem; font-weight:bold; min-width:18px; text-align:center; box-shadow:0 2px 5px rgba(0,0,0,0.2);';
            headerChatIcon.appendChild(headerBadge);
        }

        if (headerBadge) {
            if (exactTotal <= 0) {
                headerBadge.style.display = 'none';
                headerBadge.innerText = '0';
            } else {
                headerBadge.style.display = 'inline-block';
                headerBadge.innerText = exactTotal;
            }
        }
    }
}

function startPolling() {
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(() => {
        if (currentChatId) checkAndLoadNewMessages();
        silentUpdateContacts();
    }, currentPollRate);
}

function silentUpdateContacts() {
    fetch(`../api/chat_api.php?action=get_conversations&tab=${currentTab}&_t=${Date.now()}`)
    .then(r => r.json())
    .then(res => {
        if (res && res.status === 'success' && res.data) {
            let totalUnreadFromApi = 0;
            
            res.data.reverse().forEach(chat => {
                const cid = chat.id;
                const lId = parseInt(chat.last_id || '0');
                let realUnreadCount = parseInt(chat.unread_count || '0');
                
                const isCurrentChat = (currentChatId == cid);
                const isMyMsg = (chat.is_my_last_msg == 1); 
                
                if (isCurrentChat && !document.hidden) {
                    realUnreadCount = 0; 
                    const chatItem = document.querySelector(`.chat-item[data-id="${cid}"]`);
                    if (chatItem) {
                        chatItem.classList.remove('has-unread');
                        chatItem.style.backgroundColor = '';
                        chatItem.style.borderRight = '';
                        const badgeEl = chatItem.querySelector('.chat-unread-badge');
                        if (badgeEl) badgeEl.remove();
                        const nameSpan = chatItem.querySelector('.chat-name span:first-child');
                        if(nameSpan) { nameSpan.style.fontWeight = ''; nameSpan.style.color = ''; }
                    }
                }
                
                totalUnreadFromApi += realUnreadCount;
                
                if (chatLastMessageIds[cid] === undefined) {
                    chatLastMessageIds[cid] = lId; 
                } else if (lId > chatLastMessageIds[cid]) {
                    chatLastMessageIds[cid] = lId;
                    
                    if (!isMyMsg) {
                        if (!isCurrentChat || document.hidden) {
                            // پخش صدا برای دریافت پیام جدید
                            playNotifSound();
                            
                            if ("Notification" in window && Notification.permission === "granted") {
                                const notif = new Notification("پیام جدید از " + chat.name, {
                                    body: chat.last_msg || 'پیام جدید',
                                    icon: chat.avatar || '../assets/images/logo.png'
                                });
                                notif.onclick = function() {
                                    window.focus();
                                    openChat(cid, chat.name, chat.avatar, chat.type, chat.is_online === 1);
                                };
                            }
                            
                            const chatItem = document.querySelector(`.chat-item[data-id="${cid}"]`);
                            if (chatItem) {
                                chatItem.style.backgroundColor = '#eff6ff';
                                chatItem.style.borderRight = '4px solid #3b82f6';
                                
                                const nameSpan = chatItem.querySelector('.chat-name span:first-child');
                                if(nameSpan) { nameSpan.style.fontWeight = 'bold'; nameSpan.style.color = '#1e3a8a'; }

                                let badgeEl = chatItem.querySelector('.chat-unread-badge');
                                if (!badgeEl && realUnreadCount > 0) {
                                    const titleRow = chatItem.querySelector('.chat-title-row');
                                    if(titleRow) titleRow.insertAdjacentHTML('beforeend', `<span class="chat-unread-badge" style="background:#ef4444; color:#fff; font-size:0.75rem; padding:3px 7px; border-radius:12px; margin-right:auto; box-shadow:0 2px 4px rgba(239,68,68,0.4); font-weight:bold;">${realUnreadCount}</span>`);
                                } else if (badgeEl) {
                                    badgeEl.innerText = realUnreadCount;
                                }

                                const preview = chatItem.querySelector('.chat-preview');
                                if (preview) preview.innerHTML = `<span style="color:#3b82f6; font-weight:bold;">${escapeHtml(chat.last_msg)}</span>`;
                                
                                const listNode = document.getElementById('chatList');
                                if (listNode) listNode.insertBefore(chatItem, listNode.firstChild);
                            }
                        }
                    }
                }
            });
            
            syncGlobalBadgeWithExactCount(totalUnreadFromApi);
        }
    }).catch(() => {});
}

function checkAndLoadNewMessages() {
    if (!currentChatId || isCheckingNew) return;
    isCheckingNew = true;

    const formData = new FormData();
    formData.append('action', 'check_new_messages');
    formData.append('target_id', currentChatId);
    formData.append('last_id', lastMessageId);
    formData.append('chat_type', currentChatType);

    fetch(`../api/chat_api.php?_t=${Date.now()}`, { method: 'POST', body: formData })
    .then(async (r) => {
        const status = r.status;
        const txt = await r.text();
        let res;
        try { res = JSON.parse(txt); } catch (e) { throw new Error(`BAD_JSON_${status}`); }
        return res;
    })
    .then(res => {
        updateTypingUI(!!(res && res.typing));
        if (res && typeof res.seen_upto !== 'undefined') {
            updateSeenTicks(parseInt(res.seen_upto || '0'));
        }
        if (res && res.status === 'success' && res.has_new) {
            loadMessages(currentChatId);
        }
    })
    .catch(() => {})
    .finally(() => { isCheckingNew = false; });
}

function loadOlderMessages() {
    isLoadingOlder = true;
    const msgArea = document.getElementById('messagesArea');
    const prevHeight = msgArea.scrollHeight;
    
    const formData = new FormData();
    formData.append('action', 'get_older_messages');
    formData.append('target_id', currentChatId);
    formData.append('chat_type', currentChatType);
    formData.append('first_id', firstMessageId);

    fetch(`../api/chat_api.php?_t=${Date.now()}`, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res && res.status === 'success' && res.data.length > 0) {
            firstMessageId = parseInt(res.data[0].id);
            let html = '';
            res.data.forEach(msg => { html += createMessageHTML(msg); });
            
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            
            while(tempDiv.firstChild) {
                msgArea.insertBefore(tempDiv.firstChild, msgArea.firstChild);
            }
            
            msgArea.scrollTop = msgArea.scrollHeight - prevHeight;
        } else {
            hasMoreOlderMessages = false; 
        }
    })
    .finally(() => { isLoadingOlder = false; });
}

function scheduleTypingPing() {
    if (!currentChatId) return;
    if (typingPingTimer) clearTimeout(typingPingTimer);
    typingPingTimer = setTimeout(() => {
        const fd = new FormData();
        fd.append('action', 'typing_ping');
        fd.append('target_id', currentChatId);
        fd.append('chat_type', currentChatType);
        fetch('../api/chat_api.php', { method: 'POST', body: fd }).catch(() => {});
    }, 400);
}

function updateTypingUI(isTyping) {
   if (currentChatType !== 'private') return;
   if (lastTypingState === isTyping) return;
    lastTypingState = isTyping;
    const el = document.getElementById('currentStatus');
    if (!el) return;
    if (isTyping) {
        el.innerText = '✍️ در حال تایپ...';
        el.style.color = '#2563eb';
    } else {
        const activeChatItem = document.querySelector(`.chat-item.active`);
        const isOnline = activeChatItem ? activeChatItem.dataset.online === '1' : false;
        
        el.innerText = isOnline ? '🟢 آنلاین' : '⚪ آفلاین';
        el.style.color = isOnline ? '#10b981' : '#9ca3af';
    }
}

function openChat(id, name, avatar, chatType = 'private', isOnline = false) {
     if (currentChatId === id) return;
     currentChatId = id;
     currentChatType = (chatType === 'user' || chatType === 'users') ? 'private' : (chatType || 'private');
     currentChatIsGroup = (currentChatType !== 'private');
     lastMessageId = 0;
     firstMessageId = 0;
     hasMoreOlderMessages = true;
     isUserScrolling = false;
     currentReplyId = null; 
     window.currentGroupMembers = []; 
     cancelReply();
     cancelEdit();

    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('chatInterface').style.display = 'flex';
    document.getElementById('currentName').innerText = name;
    
    if (currentChatIsGroup) {
        fetch(`../api/chat_api.php?action=get_chat_members&conv_id=${id}`)
        .then(r => r.json())
        .then(res => { if(res && res.status === 'success') window.currentGroupMembers = res.data; });
    }
    
    const avatarImg = document.getElementById('currentAvatar');
    avatarImg.src = avatar;
    avatarImg.onerror = function() { this.src = '../assets/images/profile-icon.png'; };

    const statusEl = document.getElementById('currentStatus') || document.querySelector('.header-status');
    if (statusEl) {
        statusEl.id = 'currentStatus';
        if (currentChatType === 'private') {
            statusEl.innerText = isOnline ? '🟢 آنلاین' : '⚪ آفلاین';
            statusEl.style.color = isOnline ? '#10b981' : '#9ca3af';
        } else {
            statusEl.innerText = (window.currentChatBio || '').trim() || (currentChatType === 'channel' ? 'کانال' : 'گروه');
            statusEl.style.color = '#64748b';
        }
    }

    const menu = document.getElementById('chatHeaderMenu');
    if (menu) {
        const isPrivate = (currentChatType === 'private');
        menu.querySelectorAll('[data-visible-for]').forEach(el => {
            const v = el.getAttribute('data-visible-for');
            if (v === 'private') el.style.display = isPrivate ? 'block' : 'none';
            if (v === 'group') el.style.display = isPrivate ? 'none' : 'block';
        });
    }
    
    document.querySelector('.chat-container').classList.add('show-chat');
    document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
    
    const activeItem = document.querySelector(`.chat-item[data-id="${id}"]`);
    if(activeItem) {
        activeItem.classList.add('active');
        activeItem.style.backgroundColor = '';
        activeItem.style.borderRight = '';
        
        const badgeEl = activeItem.querySelector('.chat-unread-badge');
        if(badgeEl) {
            let unreadInThisChat = parseInt(badgeEl.innerText) || 0;
            
            const sidebarBadge = document.querySelector('#sidebar a[href*="chat.php"] .sub-badge-val');
            let currentTotal = sidebarBadge ? parseInt(sidebarBadge.innerText) || 0 : 0;
            
            syncGlobalBadgeWithExactCount(Math.max(0, currentTotal - unreadInThisChat));
            
            badgeEl.remove();
        }
        
        const preview = activeItem.querySelector('.chat-preview');
        if (preview && preview.querySelector('span')) {
            preview.innerHTML = preview.innerText; 
        }
        
        const nameSpan = activeItem.querySelector('.chat-name span:first-child');
        if(nameSpan) { nameSpan.style.fontWeight = ''; nameSpan.style.color = ''; }
    }
    
    const pinBar = document.getElementById('pinnedMessageBar');
    if (pinBar) pinBar.style.display = 'none';
    
    const searchPanel = document.getElementById('chatSearchPanel');
    if (searchPanel) searchPanel.style.display = 'none';

    document.getElementById('messagesArea').innerHTML = '<div style="text-align:center; padding:20px; color:#999;" id="loadingMsg">درحال دریافت پیام‌ها...</div>';
    
    loadMessages(id);
    document.getElementById('messageInput').innerText = '';
    clearFileInput();
    resetRecordingUI();
}

function backToList() {
    document.querySelector('.chat-container').classList.remove('show-chat');
    currentChatId = null;
    currentChatType = 'private';
    lastMessageId = 0;
    firstMessageId = 0;
}

function loadMessages(id) {
    if (currentChatId !== id) return;
    if (isLoadingMessages) { needsReloadMessages = true; return; }
    isLoadingMessages = true;
    const formData = new FormData();
    formData.append('action', 'get_messages');
    formData.append('target_id', id);
    formData.append('last_id', lastMessageId);
    formData.append('chat_type', currentChatType);

    fetch(`../api/chat_api.php?_t=${Date.now()}`, { method: 'POST', body: formData })
    .then(async (r) => {
        const txt = await r.text();
        return JSON.parse(txt);
    })
    .then(res => {
        const area = document.getElementById('messagesArea');
        const loadingMsg = document.getElementById('loadingMsg');

        if (!res || res.status !== 'success') {
            if (loadingMsg) loadingMsg.innerText = (res && res.message) ? res.message : 'خطا در دریافت پیام‌ها';
            return;
        }

        if (lastMessageId === 0) {
            renderPinnedBar(res.pinned || null);
        }

        if (res.data.length > 0) {
            if (loadingMsg) loadingMsg.remove();
            let hasNew = false;
            
            if (lastMessageId === 0) {
                area.innerHTML = ''; 
                firstMessageId = parseInt(res.data[0].id); 
            }

            res.data.forEach(msg => {
                let mId = parseInt(msg.id);
                if (mId > lastMessageId) {
                    lastMessageId = mId;
                    chatLastMessageIds[currentChatId] = mId; 
                    area.insertAdjacentHTML('beforeend', createMessageHTML(msg));
                    hasNew = true;
                }
            });
            
            if (hasNew) {
                if (!isUserScrolling) scrollToBottom();
            }
        } else if (lastMessageId === 0 && loadingMsg) {
            loadingMsg.innerText = 'هنوز پیامی ارسال نشده است.';
        }
    })
    .catch(() => {
        const loadingMsg = document.getElementById('loadingMsg');
        if (loadingMsg) loadingMsg.innerText = 'خطا در ارتباط با سرور';
    })
    .finally(() => {
        isLoadingMessages = false;
        if (needsReloadMessages) {
            needsReloadMessages = false;
            if (currentChatId) loadMessages(currentChatId);
        }
    });
}

function createMessageHTML(msg) {
    let typeClass = msg.is_me ? 'msg-out' : 'msg-in';
    let safeText = escapeHtml(msg.message_text);
    let content = safeText ? safeText.replace(/\n/g, '<br>') : '';
    
    // باکس اعلان منشن سیستمی
    if (msg.file_type === 'mention_alert') {
        let payload = {};
        try { payload = JSON.parse(msg.message_text); } catch(e){}
        let dateTimeStr = msg.mention_date_time || msg.time;
        return `
        <div class="msg-wrapper msg-in mention-alert-box" id="msg-${msg.id}">
            <div class="message-bubble" style="background:#eff6ff; border:1px solid #3b82f6; cursor:pointer;" onclick="jumpToChatAndMessage(${payload.target_conv_id}, ${payload.target_msg_id})">
                <div style="font-weight:bold; color:#1e3a8a; margin-bottom:8px; display:flex; justify-content:space-between; border-bottom:1px solid #bfdbfe; padding-bottom:5px;">
                    <span>🔔 سیستم اعلانات</span>
                    <span style="font-size:0.7rem; color:#64748b; font-weight:normal;">${dateTimeStr}</span>
                </div>
                <div style="font-size:0.85rem; color:#334155; line-height:1.6;">
                    شما توسط <b>${escapeHtml(payload.sender_name)}</b> در «${escapeHtml(payload.chat_title)}» منشن شدید.<br>
                    <span style="color:#3b82f6; font-size:0.75rem; font-weight:bold;">(برای مشاهده پیام اینجا کلیک کنید)</span>
                </div>
            </div>
        </div>`;
    }

    if (msg.file_path) {
        const fileExt = msg.file_path.split('.').pop().toLowerCase();
        const path = `../uploads/chat/${msg.file_path}`;
        if (['jpg','jpeg','png','gif'].includes(fileExt)) {
            content = `<img src="${path}" onclick="openImageModal('${path}')" style="max-width: 220px; max-height: 220px; border-radius: 8px; object-fit: cover; margin-bottom: 8px; cursor: zoom-in; border: 1px solid #e5e7eb; box-shadow: 0 2px 5px rgba(0,0,0,0.05);" alt="تصویر">${content}`;
        } else if (['mp3','wav','ogg','webm','m4a','aac','opus'].includes(fileExt)) {
            const audioMime = (function() {
                if (fileExt === 'webm') return 'audio/webm';
                if (fileExt === 'ogg' || fileExt === 'opus') return 'audio/ogg';
                if (fileExt === 'wav') return 'audio/wav';
                if (fileExt === 'm4a' || fileExt === 'aac') return 'audio/mp4';
                return 'audio/mpeg';
            })();
            content = `<audio controls style="max-width: 250px; height: 40px; margin-bottom: 8px;"><source src="${path}" type="${audioMime}"></audio><br>${content}`;
        } else if (['mp4','mkv'].includes(fileExt)) {
            content = `<video controls style="max-width: 250px; border-radius: 8px; margin-bottom: 8px;"><source src="${path}" type="video/mp4"></video><br>${content}`;
        } else {
            content = `<a href="${path}" target="_blank" style="display:inline-block; padding:8px 12px; background:#f1f5f9; border-radius:6px; color:#2563eb; text-decoration:none; font-size:0.85rem; font-weight:bold; margin-bottom:8px;">📥 دانلود فایل پیوست</a><br>${content}`;
        }
    }

    let replyHtml = '';
    if (msg.reply_to_id && msg.reply_text) {
        replyHtml = `<div class="reply-preview-in-msg" data-reply-to-id="${msg.reply_to_id}">
                        <div class="reply-sender-name">پاسخ به:</div>
                        <div class="reply-text-short">${escapeHtml(msg.reply_text)}</div>
                     </div>`;
    }

    let forwardHtml = '';
    if (msg.forward_from_id) {
        const fromName = msg.forward_from_name ? escapeHtml(msg.forward_from_name) : 'کاربر';
        forwardHtml = `<div class="forward-preview-in-msg"><div class="forward-sender-name">فوروارد شده از: ${fromName}</div></div>`;
    }

    let seenHtml = '';
    if (msg.is_me && currentChatType === 'private') {
        const seen = !!(msg.is_read && parseInt(msg.is_read) === 1);
        seenHtml = `<span class="msg-seen ${seen ? 'seen' : ''}" data-msg-id="${msg.id}">${renderSeenSvg(seen)}</span>`;
    }

    let editedHtml = msg.is_edited ? '<span style="font-size:0.65rem; color:#9ca3af; margin-right:5px; font-style:italic;">(ویرایش شده)</span>' : '';

    let reactionsHtml = '';
    if (msg.reactions && msg.reactions.length > 0) {
        let reactCounts = {};
        msg.reactions.forEach(r => { reactCounts[r.emoji] = (reactCounts[r.emoji] || 0) + 1; });
        reactionsHtml = '<div class="msg-reactions">';
        for (let emo in reactCounts) {
            reactionsHtml += `<span class="reaction-badge">${emo} ${reactCounts[emo]}</span>`;
        }
        reactionsHtml += '</div>';
    }

    return `
    <div class="msg-wrapper ${typeClass}" id="msg-${msg.id}">
        <div class="message-bubble">
            ${replyHtml}
            ${forwardHtml}
            <div class="msg-content">${content}</div>
            ${reactionsHtml}
            <div class="msg-meta-row">
                ${editedHtml}
                <span class="msg-time">${msg.time}</span>
                <span class="msg-meta-right">${seenHtml}</span>
            </div>
        </div>
    </div>`;
}

function renderSeenSvg(seen) {
    if (seen) {
        return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 11 15 7 11"></polyline><polyline points="17 6 8 15 4 11"></polyline></svg>`;
    }
    return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
}

function updateSeenTicks(seenUpto) {
    if (!seenUpto || seenUpto <= 0) return;
    const area = document.getElementById('messagesArea');
    if (!area) return;
    area.querySelectorAll('.msg-wrapper.msg-out .msg-seen[data-msg-id]').forEach(el => {
        const id = parseInt(el.dataset.msgId || '0');
        if (id > 0 && id <= seenUpto && !el.classList.contains('seen')) {
            el.classList.add('seen');
            el.innerHTML = renderSeenSvg(true);
        }
    });
}

function startReply(id, text) {
    cancelEdit();
    currentReplyId = id;
    const footer = document.querySelector('.chat-footer');
    let replyBox = document.getElementById('replyPreviewBox');
    
    if (!replyBox) {
        replyBox = document.createElement('div');
        replyBox.id = 'replyPreviewBox';
        replyBox.className = 'reply-input-preview';
        replyBox.innerHTML = `
            <div class="reply-info">
                <div class="reply-sender-name">در حال پاسخ...</div>
                <div class="reply-text-short" id="replyPreviewText"></div>
            </div>
            <div class="reply-close-btn" data-action="cancel-reply">✖</div>
        `;
        footer.appendChild(replyBox);
    }
    
    document.getElementById('replyPreviewText').innerText = text;
    replyBox.classList.add('active');
    document.getElementById('messageInput').focus();
}

function cancelReply() {
    currentReplyId = null;
    const replyBox = document.getElementById('replyPreviewBox');
    if (replyBox) replyBox.classList.remove('active');
}

function scrollToMessage(id) {
    const el = document.getElementById(`msg-${id}`);
    const area = document.getElementById('messagesArea');
    if (el && area) {
        const footer = document.querySelector('.chat-footer');
        const footerH = footer ? footer.offsetHeight : 0;
        const areaRect = area.getBoundingClientRect();
        const elRect = el.getBoundingClientRect();
        const currentScroll = area.scrollTop;
        const elTopInArea = (elRect.top - areaRect.top) + currentScroll;
        const targetTop = Math.max(0, elTopInArea - (area.clientHeight / 2) + (footerH / 2));
        area.scrollTo({ top: targetTop, behavior: 'smooth' });
        const bubble = el.querySelector('.message-bubble');
        if (bubble) {
            bubble.style.background = '#fff3cd'; 
            setTimeout(() => { bubble.style.background = ''; }, 2000);
        }
    }
}

function scrollToBottom() {
    const area = document.getElementById('messagesArea');
    if (area) area.scrollTo({ top: area.scrollHeight, behavior: 'smooth' });
}

function sendMessage() {
    const input = document.getElementById('messageInput');
    const text = input.innerText.trim();
    const fileInput = document.getElementById('fileInput');
    
    if (!text && fileInput.files.length === 0) return;

    if (fileInput.files.length > 0) {
        if (fileInput.files[0].size > MAX_SIZE_MB * 1024 * 1024) { alert(`حجم فایل بیش از ${MAX_SIZE_MB} مگابایت است.`); return; }
    }

    const fd = new FormData();
    
    if (window.editingMessageId) {
        fd.append('action', 'edit_message');
        fd.append('msg_id', window.editingMessageId);
        fd.append('message', text);
        
        fetch('../api/chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res && res.status === 'success') {
                input.innerText = '';
                cancelEdit();
                lastMessageId = 0; 
                document.getElementById('messagesArea').innerHTML = '';
                loadMessages(currentChatId);
            } else {
                alert((res && res.message) ? res.message : 'خطا در ویرایش');
            }
        }).catch(()=> alert('خطا در ارتباط با سرور'));
        return;
    }

    fd.append('action', 'send_message');
    fd.append('target_id', currentChatId);
    fd.append('chat_type', currentChatType);
    fd.append('message', text);
    
    if (currentReplyId) fd.append('reply_to_id', currentReplyId);
    if (fileInput.files.length > 0) fd.append('file', fileInput.files[0]);

    const sendBtn = document.getElementById('sendBtn');
    if(sendBtn) { sendBtn.disabled = true; sendBtn.style.opacity = '0.5'; }

    fetch('../api/chat_api.php', { method: 'POST', body: fd })
    .then(async (r) => {
        const txt = await r.text();
        return JSON.parse(txt);
    })
    .then(res => {
        if (res && res.status === 'success') {
            input.innerText = '';
            clearFileInput();
            cancelReply();
            if (typingPingTimer) clearTimeout(typingPingTimer);
            lastTypingState = false;
            updateTypingUI(false);
            
            if (res.id) chatLastMessageIds[currentChatId] = parseInt(res.id);

            loadMessages(currentChatId);
            scrollToBottom();
        } else {
            alert((res && res.message) ? res.message : 'خطا در ارسال');
        }
    })
    .catch(() => {
        alert('خطا در اتصال به سرور');
    })
    .finally(() => {
        if(sendBtn) { sendBtn.disabled = false; sendBtn.style.opacity = '1'; }
        toggleInputButtons();
    });
}

function toggleInputButtons() {
    const msgInput = document.getElementById('messageInput');
    const fileInput = document.getElementById('fileInput');
    const sendBtn = document.getElementById('sendBtn');
    const micBtn = document.getElementById('micBtn');
    
    const hasContent = (msgInput && msgInput.innerText.trim().length > 0) || (fileInput && fileInput.files.length > 0);
    
    if (hasContent) {
        if(sendBtn) sendBtn.style.display = 'flex';
        if(micBtn) micBtn.style.display = 'none';
    } else {
        if(sendBtn) sendBtn.style.display = 'none';
        if(micBtn) micBtn.style.display = 'flex';
    }
}

function showFilePreview(name, size) {
    let previewBox = document.querySelector('.file-attachment-preview');
    if (!previewBox) {
        const footer = document.querySelector('.chat-footer');
        previewBox = document.createElement('div');
        previewBox.className = 'file-attachment-preview';
        footer.appendChild(previewBox);
    }
    const sizeMb = (size / (1024*1024)).toFixed(2);
    previewBox.innerHTML = `<span class="file-preview-info">📎 <b>${escapeHtml(name)}</b> <small>(${sizeMb} MB)</small></span><span class="file-preview-close" data-action="clear-file">✖</span>`;
    previewBox.classList.add('active');
}

function hideFilePreview() {
    const previewBox = document.querySelector('.file-attachment-preview');
    if (previewBox) previewBox.classList.remove('active');
}

function clearFileInput() {
    const fi = document.getElementById('fileInput');
    if(fi) fi.value = '';
    hideFilePreview();
    toggleInputButtons();
}

function toggleFab() {
    const menu = document.getElementById('fabMenu');
    menu.style.display = (menu.style.display === 'none' || menu.style.display === '') ? 'flex' : 'none';
}

function toggleEmojiPanel() {
    const panel = document.getElementById('emojiPanel');
    panel.style.display = (panel.style.display === 'flex') ? 'none' : 'flex';
}

function filterChats(type) {
    currentTab = type;
    document.querySelectorAll('.chat-tab').forEach(tab => tab.classList.remove('active'));
    const activeTab = document.querySelector(`.chat-tab[data-type="${type}"]`);
    if (activeTab) activeTab.classList.add('active');
    loadContacts(type);
}

function loadContacts(type) {
    const list = document.getElementById('chatList');
    if (list) list.innerHTML = '<div style="text-align:center; padding:20px; color:#999;">در حال بارگذاری...</div>';

    fetch(`../api/chat_api.php?action=get_conversations&tab=${type}&_t=${Date.now()}`)
    .then(r => r.text())
    .then(txt => JSON.parse(txt))
    .then(res => {
        if (!res || res.status !== 'success') {
            if (list) list.innerHTML = `<div style="text-align:center; padding:20px; color:#999;">${(res && res.message) ? escapeHtml(res.message) : 'خطا در دریافت لیست گفتگوها'}</div>`;
            return;
        }

        if (res.status === 'success') {
            window.allContacts = res.data || [];
            if(!res.data || res.data.length === 0) {
                list.innerHTML = '<div style="text-align:center; padding:20px; color:#999;">مخاطبی یافت نشد.</div>';
                return;
            }
            
            let html = '';
            let totalUnreadInStart = 0;
            
            res.data.forEach(user => {
                chatLastMessageIds[user.id] = parseInt(user.last_id || '0'); 

                let badge = user.role === 'admin' ? '<span class="admin-badge">مدیر</span>' : '';
                let activeClass = (currentChatId == user.id) ? 'active' : '';
                
                let unreadCount = parseInt(user.unread_count || '0');
                const isCurrentChat = (currentChatId == user.id);
                
                if (isCurrentChat && !document.hidden) {
                    unreadCount = 0;
                }
                
                totalUnreadInStart += unreadCount;
                
                let bgStyle = unreadCount > 0 ? 'background-color: #eff6ff; border-right: 4px solid #3b82f6;' : '';
                let titleStyle = unreadCount > 0 ? 'font-weight:bold; color:#1e3a8a;' : '';
                let unreadBadgeHtml = unreadCount > 0 ? `<span class="chat-unread-badge" style="background:#ef4444; color:#fff; font-size:0.75rem; padding:3px 7px; border-radius:12px; margin-right:auto; box-shadow:0 2px 4px rgba(239,68,68,0.4); font-weight:bold;">${unreadCount}</span>` : '';

                let avatarSrc = user.avatar || '../assets/images/profile-icon.png';
                let chatType = user.type || (user.is_group ? (user.type || 'group') : 'private');
                let safeName = escapeHtml(user.name);
                let safeAvatar = escapeHtml(avatarSrc);
                let isOnline = (user.is_online == 1) ? '1' : '0';
                
                html += `
                <div class="chat-item ${activeClass}" data-id="${user.id}" data-name="${safeName}" data-avatar="${safeAvatar}" data-chat-type="${chatType}" data-bio="${escapeHtml(user.bio || '')}" data-online="${isOnline}" style="${bgStyle}">
                    <img src="${avatarSrc}" class="chat-avatar" onerror="this.src='../assets/images/profile-icon.png'">
                    <div class="chat-info">
                        <div class="chat-title-row" style="display:flex; align-items:center; width:100%;">
                            <div class="chat-name"><span style="${titleStyle}">${user.name}</span> ${badge}</div>
                            ${unreadBadgeHtml}
                        </div>
                        <div class="chat-preview">${escapeHtml(user.last_msg) || '...'} </div>
                    </div>
                </div>`;
            });
            list.innerHTML = html;
            
            syncGlobalBadgeWithExactCount(totalUnreadInStart);
        }
    })
    .catch(() => {
        if (list) list.innerHTML = `<div style="text-align:center; padding:20px; color:#999;">خطا در ارتباط با سرور</div>`;
    });
}

function setupForwardModal() {
    const style = document.createElement('style');
    style.innerHTML = `
        .forward-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .forward-modal-content { background: #fff; width: 90%; max-width: 400px; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.15); display: flex; flex-direction: column; max-height: 80vh; }
        .forward-modal-header { padding: 18px 20px; background: #f8fafc; font-weight: 800; border-bottom: 1px solid #e2e8f0; color: #1e293b; text-align: center; }
        .forward-modal-body { padding: 10px; overflow-y: auto; flex: 1; }
        .forward-modal-footer { padding: 15px; border-top: 1px solid #e2e8f0; text-align: center; background: #f8fafc; }
        .forward-contact-item { display: flex; align-items: center; padding: 10px 15px; cursor: pointer; transition: 0.2s; border-radius: 10px; margin-bottom: 5px; font-weight:bold; color: #334155; }
        .forward-contact-item:hover { background: #eff6ff; color: #1d4ed8; }
        .forward-avatar { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; margin-left: 15px; border: 2px solid #e2e8f0; }
        .btn-cancel { background: #fff; border: 1px solid #cbd5e1; padding: 10px 25px; border-radius: 8px; cursor: pointer; color: #475569; font-weight: bold; width:100%; }
        .btn-cancel:hover { background: #f1f5f9; }
    `;
    document.head.appendChild(style);

    const modalHtml = `
    <div id="forwardModal" class="forward-modal-overlay">
        <div class="forward-modal-content">
            <div class="forward-modal-header">انتخاب مخاطب برای فوروارد</div>
            <div id="forwardList" class="forward-modal-body"></div>
            <div class="forward-modal-footer">
                <button id="forwardCancelBtn" class="btn-cancel">لغو و بازگشت</button>
            </div>
        </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    const cancelBtn = document.getElementById('forwardCancelBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            const modal = document.getElementById('forwardModal');
            if (modal) modal.style.display = 'none';
        });
    }

    const forwardList = document.getElementById('forwardList');
    if (forwardList) {
        forwardList.addEventListener('click', function(e) {
            const item = e.target.closest('.forward-contact-item');
            if (!item) return;
            const id = parseInt(item.dataset.id || '0');
            const name = item.dataset.name || '';
            const ct = item.dataset.chatType || 'private';
            if (id) confirmForward(id, name, ct);
        });
    }
}

let pendingForwardMessageId = null;

function fetchContactsForForwardModal() {
    return fetch(`../api/chat_api.php?action=get_conversations&tab=all&_t=${Date.now()}`)
    .then(r => r.json())
    .then(res => {
        if (!res || res.status !== 'success') throw new Error('BAD_API');
        return res.data || [];
    });
}

async function openForwardModal(msgId) {
    pendingForwardMessageId = msgId;
    const modal = document.getElementById('forwardModal');
    const list = document.getElementById('forwardList');

    list.innerHTML = '<div style="padding:20px; text-align:center; color:#64748b;">در حال بارگذاری مخاطبین...</div>';

    let contactsToShow = window.allContacts || [];
    if (!Array.isArray(contactsToShow) || contactsToShow.length === 0) {
        try {
            contactsToShow = await fetchContactsForForwardModal();
            window.allContacts = contactsToShow;
        } catch (e) {
            contactsToShow = [];
        }
    }

    let html = '';
    contactsToShow.forEach(user => {
        const safeName = escapeHtml(user.name);
        let avatar = user.avatar || '../assets/images/profile-icon.png';
        if (avatar && typeof avatar === 'string') avatar = avatar.trim();
        if (!avatar || /group-icon\.png$/i.test(avatar)) avatar = '../assets/images/profile-icon.png';

        const cType = user.type || (user.is_group ? (user.type || 'group') : 'private');
        html += `<div class="forward-contact-item" data-id="${user.id}" data-name="${safeName}" data-chat-type="${escapeHtml(cType)}">
            <img src="${escapeAttr(avatar)}" class="forward-avatar" alt="">
            <span>${safeName}</span>
        </div>`;
    });
    
    list.innerHTML = html || '<div style="padding:20px; text-align:center; color:#ef4444;">مخاطبی یافت نشد</div>';

    list.querySelectorAll('img.forward-avatar').forEach(img => {
        img.addEventListener('error', function() {
            if (this.dataset.fallbackDone) return;
            this.dataset.fallbackDone = '1';
            this.src = '../assets/images/profile-icon.png';
        });
    });

    modal.style.display = 'flex';
}

function confirmForward(targetId, targetName, chatType = 'private') {
    if (!pendingForwardMessageId) return;
    if (confirm(`آیا پیام به ${targetName} فوروارد شود؟`)) {
        const fd = new FormData();
        fd.append('action', 'send_message');
        fd.append('target_id', targetId);
        fd.append('chat_type', chatType || 'private');
        fd.append('is_forward', 1);
        fd.append('forward_from_msg_id', pendingForwardMessageId);
        
        fetch('../api/chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res || res.status !== 'success') {
                alert((res && res.message) ? res.message : 'خطا در فوروارد');
                return;
            }
            document.getElementById('forwardModal').style.display = 'none';
            if (parseInt(currentChatId || '0') === parseInt(targetId || '0') && currentChatType === (chatType || 'private')) {
                loadMessages(targetId);
            } else {
                alert('پیام با موفقیت فوروارد شد 🚀');
            }
        })
        .catch(() => alert('خطا در پاسخ سرور'));
    }
}

async function startRecording() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert("مرورگر شما ضبط صدا را پشتیبانی نمی‌کند."); return;
    }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
        const chosen = candidates.find(t => {
            try { return MediaRecorder.isTypeSupported(t); } catch(e) { return false; }
        }) || '';

        mediaRecorder = chosen ? new MediaRecorder(stream, { mimeType: chosen }) : new MediaRecorder(stream);
        audioChunks = [];
        mediaRecorder.ondataavailable = event => { if (event.data.size > 0) audioChunks.push(event.data); };
        mediaRecorder.start();
        
        document.getElementById('recordingUI').classList.add('active');
        document.getElementById('messageInput').style.display = 'none';
        document.getElementById('micBtn').style.display = 'none';
        
        let seconds = 0;
        const timerEl = document.getElementById('recTimer');
        recordingInterval = setInterval(() => {
            seconds++;
            const m = Math.floor(seconds / 60).toString().padStart(2, '0');
            const s = (seconds % 60).toString().padStart(2, '0');
            timerEl.innerText = `${m}:${s}`;
        }, 1000);
    } catch (err) { alert("دسترسی به میکروفون داده نشد."); }
}

function cancelRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
        mediaRecorder.stream.getTracks().forEach(track => track.stop());
    }
    clearInterval(recordingInterval);
    resetRecordingUI();
}

function stopAndSendRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.onstop = () => {
            const mime = (mediaRecorder && mediaRecorder.mimeType) ? mediaRecorder.mimeType : '';
            const audioBlob = new Blob(audioChunks, { type: mime || (audioChunks[0] ? audioChunks[0].type : '') });
            uploadVoice(audioBlob);
        };
        mediaRecorder.stop();
        mediaRecorder.stream.getTracks().forEach(track => track.stop());
    }
    clearInterval(recordingInterval);
    resetRecordingUI();
}

function resetRecordingUI() {
    document.getElementById('recordingUI').classList.remove('active');
    document.getElementById('messageInput').style.display = 'block';
    document.getElementById('micBtn').style.display = 'flex';
}

function uploadVoice(blob) {
    const fd = new FormData();
    fd.append('action', 'send_message');
    fd.append('target_id', currentChatId);
    fd.append('chat_type', currentChatType);
    fd.append('type', 'voice');
    const t = (blob && blob.type) ? blob.type : '';
    const ext = (t.includes('webm') || t.includes('opus')) ? 'webm' : (t.includes('ogg') ? 'ogg' : 'mp4');
    fd.append('file', blob, `voice_${Date.now()}.${ext}`);

    fetch('../api/chat_api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res && res.status === 'success') {
            if (res.id) chatLastMessageIds[currentChatId] = parseInt(res.id);
            loadMessages(currentChatId);
            scrollToBottom();
        } else {
            alert((res && res.message) ? res.message : 'خطا در ارسال پیام صوتی');
        }
    });
}