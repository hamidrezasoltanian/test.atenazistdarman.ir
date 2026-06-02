<?php
ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'user';
$isAdmin = ($userRole === 'admin');

$pageTitle = 'گفتگو و پیام‌رسان';
$basePath = '../';

$extraCss = '<link rel="stylesheet" href="../assets/css/chat.css">';
$extraCss .= '<meta name="csrf-token" content="' . ($_SESSION['csrf_token'] ?? '') . '">';
$extraCss .= '<style>
    /* استایل‌های پایه و ساختار کلی */
    .chat-container { display: flex; width: 100%; height: calc(100vh - 100px); overflow: hidden; background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; position: relative; }
    .chat-sidebar { width: 320px; border-left: 1px solid #e5e7eb; display: flex; flex-direction: column; background: #f8fafc; transition: 0.3s; z-index: 5; }
    /* overflow: hidden در chat-main بسیار حیاتی است تا فوتر اسکرول نخورد */
    .chat-main { flex: 1; display: flex; flex-direction: column; background: #fff; position: relative; min-width: 0; overflow: hidden; } 
    
    .back-btn { display: none; }

    /* =========================================
       طراحی رسپانسیو و تمام‌صفحه و قفل شده برای موبایل 
       ========================================= */
    @media (max-width: 768px) {
        body, html { overflow: hidden !important; height: 100% !important; position: fixed; width: 100%; } 
        .main-content { padding-top: 70px !important; padding-bottom: 0 !important; padding-left: 0 !important; padding-right: 0 !important; margin: 0 !important; height: 100dvh; }
        .content-wrapper { padding: 0 !important; margin: 0 !important; max-width: 100% !important; border-radius: 0 !important; box-shadow: none !important; height: calc(100dvh - 70px); }
        
        /* قفل کردن کامل کادر چت به صفحه گوشی */
        .chat-container { 
            position: fixed !important; 
            top: 70px; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            width: 100vw !important; 
            height: calc(100dvh - 70px) !important; 
            border-radius: 0 !important; 
            border: none !important; 
            margin: 0 !important; 
        }
        
        .chat-sidebar { width: 100%; border-left: none; }
        .chat-main { position: absolute; top: 0; left: 0; width: 100%; height: 100%; transform: translateX(-100%); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 10; display: none; background:#fff;}
        
        .show-chat .chat-main { transform: translateX(0); display: flex; }
        .show-chat .chat-sidebar { display: none; }
        .back-btn { display: flex !important; align-items: center; justify-content: center; margin-left: 15px; color: #3b82f6; cursor: pointer; }
    }

    /* =========================================
       طراحی فوتر چت (دقیقاً مشابه واتس‌اپ در یک ردیف)
       ========================================= */
    .chat-footer { 
        display: flex; 
        align-items: flex-end; 
        padding: 8px 10px; 
        /* safe-area برای آیفون‌ها تا خط پایین گوشی روی فوتر نیفتد */
        padding-bottom: calc(8px + env(safe-area-inset-bottom));
        background: #f0f2f5; 
        border-top: 1px solid #e5e7eb; 
        gap: 5px; 
        position: relative; 
        flex-wrap: nowrap !important; 
        width: 100%;
        box-sizing: border-box;
        flex-shrink: 0; /* جلوگیری از مچاله شدن فوتر */
    }
    
    .chat-footer .icon-btn, .chat-footer .send-btn { 
        width: 40px; 
        height: 40px; 
        flex-shrink: 0; 
        background: transparent; 
        border: none; 
        outline: none; 
        cursor: pointer; 
        padding: 0; 
        color: #64748b; 
        border-radius: 50%; 
        transition: 0.2s; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
    }
    .chat-footer .icon-btn:hover { background: #e2e8f0; color: #3b82f6; }
    
    .chat-input { 
        flex: 1; 
        background: #fff; 
        padding: 10px 15px; 
        border-radius: 20px; 
        border: 1px solid #cbd5e1; 
        outline: none; 
        max-height: 100px; 
        overflow-y: auto; 
        font-size: 0.95rem; 
        line-height: 1.4; 
        min-width: 0; 
        word-wrap: break-word;
        white-space: pre-wrap;
    }
    .chat-input:empty:before { content: attr(placeholder); color: #94a3b8; pointer-events: none; display: block; }
    
    .chat-footer .send-btn { background: #3b82f6; color: #fff; box-shadow: 0 2px 6px rgba(59,130,246,0.3); }
    .chat-footer .send-btn:hover { background: #2563eb; transform: scale(1.05); }

    .file-attachment-preview, .emoji-panel, .upload-progress-container, .reply-input-preview {
        position: absolute; bottom: 100%; left: 0; width: 100%; z-index: 20;
    }
    
    .recording-ui {
        position: absolute; left: 50px; right: 50px; bottom: calc(8px + env(safe-area-inset-bottom));
        background: #fff; height: 40px; border-radius: 20px; display: none; align-items: center; padding: 0 15px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1); z-index: 10;
    }
    .recording-ui.active { display: flex !important; }

    /* استایل اختصاصی پنل ایموجی */
    .emoji-panel {
        display: none; flex-wrap: wrap; gap: 6px; background: #fff; padding: 12px;
        border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 -5px 20px rgba(0,0,0,0.1);
        max-height: 200px; overflow-y: auto; justify-content: center; align-items: center;
    }
    .emoji-panel span { font-size: 1.6rem !important; cursor: pointer; padding: 5px !important; transition: transform 0.2s; border-radius: 8px; }
    .emoji-panel span:hover { transform: scale(1.2); background: #f1f5f9; }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="chat-container">
            
            <div class="chat-sidebar">
                <div class="sidebar-header" style="display:flex; align-items:center; gap:10px;">
                    <div class="search-wrapper" style="flex:1;">
                        <input type="text" id="contactSearch" placeholder="جستجو مخاطب...">
                    </div>
                    <?php if($isAdmin): ?>
                        <a href="chat_settings.php" title="تنظیمات دسترسی چت" style="color:#64748b; padding:5px; transition:0.2s;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="chat-tabs">
                    <div class="chat-tab active" data-type="all">همه</div>
                    <div class="chat-tab" data-type="groups">گروه‌ها</div>
                    <div class="chat-tab" data-type="members">اعضا</div>
                </div>
                
                <div class="chat-list" id="chatList">
                    <div style="text-align:center; padding:20px; color:#999;">در حال بارگذاری...</div>
                </div>

                <div class="fab-btn" id="fabBtn">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                </div>
                <div class="fab-menu" id="fabMenu">
                    <a href="create_chat.php?type=group" class="fab-item" style="text-decoration:none; color:inherit;">👥 گروه جدید</a>
                    <a href="create_chat.php?type=channel" class="fab-item" style="text-decoration:none; color:inherit;">📢 کانال جدید</a>
                </div>
            </div>

            <div class="chat-main" id="chatMain">
                <div id="emptyState" style="height:100%; display:flex; align-items:center; justify-content:center; color:#777; flex-direction:column;">
                    <img src="../assets/images/logo.png" style="width:100px; opacity:0.5; margin-bottom:20px;">
                    <span style="background:rgba(255,255,255,0.8); padding:8px 20px; border-radius:20px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">یک گفتگو را انتخاب کنید</span>
                </div>

                <div id="chatInterface" style="display:none; height:100%; flex-direction:column;">
                    <div class="chat-header">
                        <div class="header-profile">
                            <span class="back-btn" id="backToListBtn">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                            </span>
                            
                            <img src="" id="currentAvatar" style="width:42px; height:42px; border-radius:50%; margin-left:12px; object-fit:cover;">
                            <div>
                                <div class="header-name" id="currentName"></div>
                                <div class="header-status" id="currentStatus"></div>
                            </div>
                        </div>
                        <div style="position:relative;">
                            <div id="chatMenuBtn" style="cursor:pointer; font-size:1.2rem; color:#707579; padding:5px;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>
                            </div>
                            <div id="chatHeaderMenu" style="display:none; position:absolute; left:0; top:35px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; min-width:180px; box-shadow:0 10px 20px rgba(0,0,0,0.08); z-index:50; overflow:hidden;">
                                <div data-menu-action="edit-group" data-visible-for="group" style="padding:10px 12px; cursor:pointer; border-bottom:1px solid #f1f5f9;"><i class="fas fa-cog"></i> مدیریت گروه/کانال</div>
                                <div data-menu-action="delete-group" data-visible-for="group" style="padding:10px 12px; cursor:pointer; color:#dc2626;"><i class="fas fa-trash"></i> حذف گروه/کانال</div>
                                <div data-menu-action="delete-conversation" data-visible-for="private" style="padding:10px 12px; cursor:pointer; color:#dc2626;"><i class="fas fa-eraser"></i> حذف تاریخچه چت</div>
                            </div>
                        </div>
                    </div>

                    <div class="chat-messages" id="messagesArea"></div>

                    <div class="chat-footer">
                        <div class="upload-progress-container"><div class="upload-progress-bar"></div></div>
                        <div class="file-attachment-preview"></div>
                        <div class="emoji-panel" id="emojiPanel"></div>

                        <button class="icon-btn" id="emojiBtn" title="ایموجی">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg>
                        </button>
                        
                        <button class="icon-btn" id="attachBtn" title="ارسال فایل">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                        </button>
                        <input type="file" id="fileInput" hidden>
                        
                        <div class="recording-ui" id="recordingUI">
                            <div class="recording-dot"></div>
                            <div class="recording-timer" id="recTimer">00:00</div>
                            <div style="flex:1; color:#777; font-size:0.9rem; margin-right:10px;">در حال ضبط...</div>
                            <button class="icon-btn" id="cancelRecBtn" style="color:#ef4444; width:30px; height:30px;" title="لغو">✖</button>
                            <button class="icon-btn" id="sendRecBtn" style="color:#2563eb; width:30px; height:30px;" title="ارسال">▲</button>
                        </div>

                        <div class="chat-input" contenteditable="true" id="messageInput" placeholder="پیام خود را بنویسید..."></div>
                        
                        <button class="icon-btn" id="micBtn" title="ضبط صدا">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>
                        </button>
                        
                        <button class="send-btn" id="sendBtn" style="display:none;" title="ارسال">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:-2px;"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<script src="../assets/js/chat.js"></script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>