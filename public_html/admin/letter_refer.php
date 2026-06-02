<?php
/*
 * فایل: public_html/admin/letter_refer.php
 * تغییرات: تکمیل هوشمند و خودکار کارتابل فرستنده (مختومه کردن) پس از ارجاع + جلوگیری از ارجاع تکراری
 */

ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$letterId = (int)($_GET['id'] ?? 0);
$refId = (int)($_GET['ref_id'] ?? 0); 

if ($letterId <= 0) die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma;">شناسه نامه نامعتبر است.</div>');

$stmt = $pdo->prepare("SELECT subject, indicator_number, status FROM letters WHERE id = ?");
$stmt->execute([$letterId]);
$letter = $stmt->fetch();

if (!$letter) die('<div style="text-align:center; padding:50px; font-family:Vazirmatn, Tahoma;">نامه یافت نشد.</div>');

$msg = ''; $msgType = '';

// پردازش ارجاع
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_referral'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");

    $recipients = $_POST['recipients'] ?? []; 
    $actionType = $_POST['action_type'] ?? 'for_action';
    $description = trim($_POST['description'] ?? '');
    $privateNote = trim($_POST['private_note'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';

    if (empty($recipients)) {
        $msg = "لطفاً حداقل یک نفر را برای ارجاع انتخاب کنید.";
        $msgType = "error";
    } else {
        try {
            $pdo->beginTransaction();

            $stmtCheckDuplicate = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND is_completed = 0");
            $stmtRef = $pdo->prepare("INSERT INTO letter_referrals (letter_id, sender_id, receiver_id, description, private_note, action_type, priority, is_read, is_completed, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())");
            
            $referredCount = 0;
            $duplicateCount = 0;

            foreach ($recipients as $recId) {
                // بررسی ارجاع تکراری فعال برای این شخص
                $stmtCheckDuplicate->execute([$letterId, $recId]);
                if ($stmtCheckDuplicate->fetchColumn() > 0) {
                    $duplicateCount++;
                    continue; 
                }

                $stmtRef->execute([$letterId, $userId, $recId, $description, $privateNote, $actionType, $priority]);
                $newRefId = $pdo->lastInsertId();
                $referredCount++;
                
                // ارسال نوتیفیکیشن
                if (function_exists('send_user_notification')) {
                    $senderName = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
                    $title = "📥 ارجاع نامه جدید";
                    $body = "نامه‌ای با موضوع «" . $letter['subject'] . "» جهت " . getActionTypeLabelFa($actionType) . " از طرف $senderName به کارتابل شما آمد.";
                    $link = "admin/letter_view.php?id=$letterId&ref_id=$newRefId";
                    send_user_notification($pdo, $recId, $title, $body, $link, 'letter', $letterId);
                }
            }

            if ($referredCount > 0) {
                // --- مهم: مختومه کردن خودکار کارتابل فعلی فرد پس از ارجاع ---
                if ($refId > 0) {
                    $pdo->prepare("UPDATE letter_referrals SET is_completed = 1, completed_at = NOW(), completion_note = 'با ارجاع مجدد مختومه شد' WHERE id = ? AND receiver_id = ?")
                        ->execute([$refId, $userId]);
                }

                // آپدیت وضعیت نامه اصلی
                $pdo->prepare("UPDATE letters SET status = 'in_referral' WHERE id = ?")->execute([$letterId]);
                
                // ثبت لاگ
                logSystem('Letters', 'refer', $letterId, "ارجاع نامه به " . $referredCount . " نفر جهت " . $actionType);

                $pdo->commit();
                
                // هدایت به کارتابل با پیام موفقیت
                $flashMsg = "ارجاع به $referredCount نفر با موفقیت انجام شد.";
                if ($duplicateCount > 0) {
                    $flashMsg .= " ($duplicateCount نفر قبلاً این نامه را در کارتابل خود (اقدام نشده) داشتند و ارجاع تکراری برای آن‌ها نادیده گرفته شد.)";
                }
                $_SESSION['flash_success'] = $flashMsg;
                header("Location: cartable_inbox.php");
                exit;
            } else {
                $pdo->rollBack();
                $msg = "همه افراد انتخاب شده در حال حاضر این نامه را در کارتابل خود (اقدام نشده) دارند. ارجاع جدیدی ثبت نشد!";
                $msgType = "warning";
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "خطا در ارجاع: " . $e->getMessage();
            $msgType = "error";
        }
    }
}

function getActionTypeLabelFa($type) {
    $labels = [
        'for_action' => 'اقدام', 'for_signature' => 'امضا', 'for_order' => 'دستور',
        'for_followup' => 'پیگیری', 'for_awareness' => 'استحضار', 'for_info' => 'اطلاع'
    ];
    return $labels[$type] ?? 'اقدام';
}

$users = $pdo->query("SELECT id, first_name, last_name, role FROM users WHERE status='active' AND id != $userId ORDER BY last_name")->fetchAll();

$pageTitle = 'ارجاع نامه | آتنا زیست درمان';
$basePath = '../';
$extraCss = '
<style>
    * { font-family: "Vazirmatn", Tahoma, sans-serif !important; box-sizing: border-box; }
    
    .refer-container { max-width: 950px; margin: 40px auto; padding: 0 15px; width: 100%; }
    .page-header-custom { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
    .refer-card { background: #fff; border-radius: 16px; padding: 35px; box-shadow: 0 10px 30px rgba(15,23,42,0.04); border: 1px solid #e2e8f0; }
    
    .subject-box { background: #eff6ff; border: 1px solid #bfdbfe; border-right: 5px solid #2563eb; border-radius: 12px; padding: 20px 25px; margin-bottom: 35px; }
    .subject-box .lbl { font-size: 0.85rem; color: #475569; font-weight: bold; margin-bottom: 8px; }
    .subject-box .val { font-size: 1.2rem; font-weight: 900; color: #1e3a8a; line-height: 1.6; }
    
    .form-section-title { font-size: 1.15rem; font-weight: 800; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
    .form-label { font-weight: 700; color: #334155; margin-bottom: 10px; display: block; }
    .form-control { border-radius: 10px; border: 1px solid #cbd5e1; padding: 12px 15px; font-size: 0.95rem; width: 100%; transition: 0.3s; }
    .form-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); outline: none;}
    
    .form-row-custom { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 25px; }
    .form-col-custom { flex: 1; min-width: 250px; }
    .mb-custom { margin-bottom: 25px; }
    .input-btn-group { display: flex; gap: 10px; align-items: stretch; width: 100%; }
    .input-btn-group .select-wrapper { flex-grow: 1; min-width: 0; }
    
    .chip-container { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; min-height: 55px; padding: 12px; border: 1px dashed #cbd5e1; border-radius: 10px; background: #f8fafc; align-items: center; }
    .chip { background: #0f172a; color: #fff; padding: 8px 16px; border-radius: 25px; font-size: 0.9rem; font-weight: bold; display: inline-flex; align-items: center; gap: 10px; animation: fadeIn 0.3s ease; box-shadow: 0 4px 6px rgba(15,23,42,0.2); }
    .chip span { cursor: pointer; color: #f87171; font-size: 1.3rem; line-height: 1; transition: 0.2s; }
    .chip span:hover { color: #fca5a5; transform: scale(1.1); }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    
    @media (max-width: 768px) {
        .refer-container { margin: 15px auto; }
        .refer-card { padding: 20px; }
        .input-btn-group { flex-direction: column; }
        .input-btn-group .btn { width: 100%; padding: 12px; }
        .form-col-custom { min-width: 100%; }
        .btn-submit { width: 100%; padding: 15px; font-size: 1.1rem; }
        .subject-box { padding: 15px; }
        .subject-box .val { font-size: 1.05rem; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="refer-container">
            <div class="page-header-custom">
                <div><span class="page-title fw-bold" style="font-size:1.3rem;">↪️ ارجاع نامه</span></div>
                <a href="cartable_inbox.php" class="btn btn-secondary fw-bold px-4">بازگشت به کارتابل</a>
            </div>

            <?php if($msg): ?>
                <div class="alert alert-<?php echo $msgType; ?> fw-bold"><?php echo $msg; ?></div>
            <?php endif; ?>

            <div class="refer-card">
                <div class="subject-box">
                    <div class="lbl">موضوع نامه تحت ارجاع:</div>
                    <div class="val"><?php echo htmlspecialchars($letter['subject']); ?></div>
                </div>

                <form method="POST" id="referForm">
                    <?php echo csrf_field(); ?>
                    
                    <div class="form-section-title"><i class="fas fa-user-tag text-primary"></i> تعیین گیرندگان ارجاع</div>
                    
                    <div class="mb-custom">
                        <label class="form-label">انتخاب پرسنل جهت ارجاع <span class="text-danger">*</span></label>
                        <div class="input-btn-group">
                            <div class="select-wrapper">
                                <select id="user-select" class="form-control">
                                    <option value="">-- فرد مورد نظر را انتخاب کنید --</option>
                                    <?php foreach($users as $u): ?>
                                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name'].' ('.$u['role'].')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="button" class="btn btn-primary px-4 fw-bold" onclick="addUser()">➕ افزودن</button>
                        </div>
                        <div id="selected-users" class="chip-container">
                            <div class="text-muted small w-100 text-center py-2" id="empty-text">لیست گیرندگان ارجاع خالی است.</div>
                        </div>
                    </div>

                    <div class="form-row-custom">
                        <div class="form-col-custom">
                            <label class="form-label">جهت ارجاع (نوع فرایند)</label>
                            <select name="action_type" class="form-control">
                                <option value="for_action">🛠️ جهت اقدام</option>
                                <option value="for_signature">✍️ جهت امضا</option>
                                <option value="for_order">📜 جهت دستور</option>
                                <option value="for_followup">⏳ جهت پیگیری</option>
                                <option value="for_awareness">🔔 جهت استحضار</option>
                                <option value="for_info">ℹ️ جهت اطلاع</option>
                            </select>
                        </div>
                        <div class="form-col-custom">
                            <label class="form-label">فوریت ارجاع</label>
                            <select name="priority" class="form-control">
                                <option value="normal">⚪ عادی</option>
                                <option value="high">🟡 فوری</option>
                                <option value="immediate">🔴 آنی</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-custom">
                        <label class="form-label">توضیحات / هامش ارجاع</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="توضیحات عمومی ارجاع برای گیرندگان..."></textarea>
                    </div>

                    <div class="mb-custom">
                        <label class="form-label text-danger">یادداشت محرمانه (فقط گیرندگان می‌بینند)</label>
                        <textarea name="private_note" class="form-control" rows="2" style="background: #fff8f8;" placeholder="نکته خصوصی برای گیرنده..."></textarea>
                    </div>

                    <div class="text-start mt-4 pt-4" style="border-top: 1px solid #f1f5f9;">
                        <button type="submit" name="submit_referral" class="btn btn-primary btn-lg px-5 fw-bold btn-submit" style="background: #0f172a; border: none; box-shadow: 0 4px 15px rgba(15,23,42,0.25);">
                            🚀 تایید و ارسال ارجاع
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
function addUser() {
    const sel = document.getElementById('user-select');
    const container = document.getElementById('selected-users');
    const emptyText = document.getElementById('empty-text');
    const val = sel.value;
    const text = sel.options[sel.selectedIndex].text;

    if(!val) return;
    if(document.querySelector(`input[name="recipients[]"][value="${val}"]`)) {
        alert("این شخص قبلاً اضافه شده است.");
        return;
    }

    if(emptyText) emptyText.style.display = 'none';

    const chip = document.createElement('div');
    chip.className = 'chip';
    chip.innerHTML = `${text} <span onclick="removeUser(this)" title="حذف">&times;</span>
                      <input type="hidden" name="recipients[]" value="${val}">`;
    container.appendChild(chip);
    sel.value = "";
}

function removeUser(el) {
    el.parentElement.remove();
    const container = document.getElementById('selected-users');
    if (container.querySelectorAll('.chip').length === 0) {
        document.getElementById('empty-text').style.display = 'block';
    }
}
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>