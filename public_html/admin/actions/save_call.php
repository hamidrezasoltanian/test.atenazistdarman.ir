<?php
// فایل: public_html/admin/actions/save_call.php
session_start();
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../../login.php"); exit;
}

try {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        throw new Exception("نشست نامعتبر است. لطفاً صفحه را رفرش کنید.");
    }

    $pdo->beginTransaction();
    
    $cId = (int)$_POST['customer_id'];
    $userId = $_SESSION['user_id'];
    $callDateJ = trim($_POST['call_date'] ?? '');
    
    // تبدیل تاریخ
    $callDateJ = faToEn($callDateJ);
    $p = explode('/', $callDateJ);
    if (count($p) !== 3) throw new Exception("تاریخ نامعتبر");
    $callDateG = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));

    // ثبت تماس
    $stmt = $pdo->prepare("INSERT INTO customer_calls (customer_id, user_id, type, call_date, call_time, subject, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $cId, $userId, $_POST['call_type'] ?? 'incoming', $callDateG, 
        $_POST['call_time'] ?? date('H:i'), trim($_POST['subject'] ?? ''), trim($_POST['description'] ?? '')
    ]);
    $callId = $pdo->lastInsertId();
    logSystem('CustomerCall', 'create', $callId, "ثبت تماس برای مشتری {$cId}");

    // ثبت منشن‌ها (JSON Parse)
    $mentions = json_decode($_POST['mention_ids'] ?? '[]', true);
    if (!empty($mentions) && is_array($mentions)) {
        $mentions = array_values(array_unique(array_map('intval', $mentions)));
        $stmtM = $pdo->prepare("INSERT INTO customer_call_mentions (call_id, mentioned_user_id) VALUES (?, ?)");
        foreach ($mentions as $uid) {
            if ($uid <= 0) continue;
            $stmtM->execute([$callId, (int)$uid]);
        }
    }

    $pdo->commit();

    // ثبت اعلان + لاگ بعد از ذخیره موفق
    if (!empty($mentions)) {
        $notifTitle = "منشن در ثبت تماس";
        $notifBody = "شما در ثبت تماس/مذاکره مشتری منشن شدید.";
        $notifUrl = "admin/customer_profile.php?id={$cId}&call_id={$callId}";
        $iconUrl = function_exists('getUserProfileImage') ? getUserProfileImage($pdo, $userId, '') : '';
        if ($iconUrl && $iconUrl[0] !== '/' && !preg_match('#^https?://#i', $iconUrl)) {
            $iconUrl = '/' . $iconUrl;
        }
        foreach ($mentions as $uid) {
            try {
                if (function_exists('db_has_column') && db_has_column($pdo, 'user_notifications', 'icon_url')) {
                    $pdo->prepare("INSERT INTO user_notifications (user_id, title, body, url, type, ref_id, icon_url) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([(int)$uid, $notifTitle, $notifBody, $notifUrl, 'call_mention', (int)$callId, $iconUrl]);
                } else {
                    $pdo->prepare("INSERT INTO user_notifications (user_id, title, body, url, type, ref_id) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([(int)$uid, $notifTitle, $notifBody, $notifUrl, 'call_mention', (int)$callId]);
                }
                logSystem('CallMention', 'create', $callId, "Mention user_id={$uid} در ثبت تماس مشتری {$cId}");
            } catch (Exception $e) {
                error_log("Notification Error: " . $e->getMessage());
            }
        }
    }

    // ثبت در تابلو اعلانات (هدفمند برای منشن‌ها)
    if (!empty($mentions)) {
        try {
            $stmtC = $pdo->prepare("SELECT company_name FROM customers WHERE id = ? LIMIT 1");
            $stmtC->execute([$cId]);
            $cName = $stmtC->fetchColumn() ?: 'مشتری';
            $typeLabel = ['incoming'=>'تماس ورودی','outgoing'=>'تماس خروجی','meeting'=>'جلسه حضوری'][$_POST['call_type'] ?? 'incoming'] ?? 'تماس';
            $subject = trim($_POST['subject'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $title = "ثبت {$typeLabel} - {$cName}";
            $content = "ثبت‌کننده: {$_SESSION['fullname']}\n"
                     . "تاریخ/ساعت: {$callDateJ} - " . ($_POST['call_time'] ?? date('H:i')) . "\n"
                     . "نوع: {$typeLabel}\n"
                     . ($subject ? "موضوع: {$subject}\n" : '')
                     . ($desc ? "گزارش: {$desc}" : '');

            $cols = ['title','content','priority','status','category_id','attachment','created_by','type'];
            $vals = [$title, $content, 'normal', 'published', 1, null, $userId, 'call_mention'];
            if (function_exists('db_has_column') && db_has_column($pdo, 'announcements', 'target_role')) {
                $cols[] = 'target_role'; $vals[] = null;
            }
            if (function_exists('db_has_column') && db_has_column($pdo, 'announcements', 'target_department_id')) {
                $cols[] = 'target_department_id'; $vals[] = null;
            }
            $placeholders = rtrim(str_repeat('?,', count($cols)), ',');
            $pdo->prepare("INSERT INTO announcements (" . implode(',', $cols) . ") VALUES ($placeholders)")->execute($vals);
            $annId = $pdo->lastInsertId();

            if (function_exists('db_has_column') && db_has_column($pdo, 'announcement_targets', 'announcement_id')) {
                $stmtT = $pdo->prepare("INSERT IGNORE INTO announcement_targets (announcement_id, user_id) VALUES (?, ?)");
                foreach ($mentions as $uid) {
                    $stmtT->execute([(int)$annId, (int)$uid]);
                }
            }
        } catch (Exception $e) {
            error_log("Announcement Error: " . $e->getMessage());
        }
    }
    header("Location: ../customer_profile.php?id=$cId&msg=saved");

} catch (Exception $e) {
    $pdo->rollBack();
    header("Location: ../customer_profile.php?id={$_POST['customer_id']}&error=" . urlencode($e->getMessage()));
}
?>
