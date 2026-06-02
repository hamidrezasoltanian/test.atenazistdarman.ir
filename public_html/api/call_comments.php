<?php
// فایل: public_html/api/call_comments.php
// توضیحات: ثبت کامنت برای تماس + ذخیره منشن‌ها + ارسال اعلان سیستمی و پوش‌نوتیفیکیشن

session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$_SESSION['user_id'];
$userFullName = $_SESSION['fullname'] ?? 'کاربر';

if ($method === 'POST') {
    // در فایل پروفایل مشتری csrf_token ارسال می‌شود
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        echo json_encode(['status' => 'error', 'message' => 'csrf error']);
        exit;
    }

    $callId = (int)($_POST['call_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    
    // دریافت منشن‌ها (لیست ID کاربرانی که منشن شده‌اند)
    $mentionIdsRaw = $_POST['mention_ids'] ?? '[]';
    $mentionIds = json_decode($mentionIdsRaw, true);
    if (!is_array($mentionIds)) $mentionIds = [];

    if ($callId > 0 && $comment !== '') {
        try {
            $pdo->beginTransaction();

            // 1. ذخیره کامنت در دیتابیس
            $stmt = $pdo->prepare("INSERT INTO customer_call_comments (call_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$callId, $userId, $comment]);
            $commentId = $pdo->lastInsertId();

            // 2. دریافت اطلاعات تماس و مشتری برای ساخت متن اعلان
            $stmtCall = $pdo->prepare("SELECT c.customer_id, cust.company_name FROM customer_calls c JOIN customers cust ON c.customer_id = cust.id WHERE c.id = ? LIMIT 1");
            $stmtCall->execute([$callId]);
            $callInfo = $stmtCall->fetch(PDO::FETCH_ASSOC);
            $customerId = $callInfo ? $callInfo['customer_id'] : 0;
            $customerName = $callInfo ? $callInfo['company_name'] : 'مشتری نامشخص';

            // 3. ثبت منشن‌ها و ارسال اعلان (پوش‌نوتیفیکیشن)
            if (!empty($mentionIds)) {
                $stmtMention = $pdo->prepare("INSERT IGNORE INTO customer_call_comment_mentions (comment_id, user_id) VALUES (?, ?)");
                
                // فرمت لینک برای هدایت کاربر مستقیماً به همان تماس
                $notifLink = "admin/customer_profile.php?id=" . $customerId . "&call_id=" . $callId;
                $notifTitle = "🗣️ منشن جدید در نظرات";
                // کوتاه کردن متن نظر برای اعلان
                $shortComment = mb_strimwidth($comment, 0, 40, "...");
                $notifBody = "کاربر {$userFullName} شما را زیر گزارش تماس مربوط به «{$customerName}» منشن کرد:\n\"{$shortComment}\"";

                foreach ($mentionIds as $mId) {
                    $mId = (int)$mId;
                    if ($mId > 0 && $mId !== $userId) { // به خودش اعلان ندهد
                        $stmtMention->execute([$commentId, $mId]);
                        
                        // تابع send_user_notification به صورت اتوماتیک در زنگوله ثبت می‌کند 
                        // و جاوااسکریپت هدر به صورت زنده پوش‌نوتیفیکیشن مرورگر را فعال می‌کند.
                        if (function_exists('send_user_notification')) {
                            send_user_notification($pdo, $mId, $notifTitle, $notifBody, $notifLink, 'call_comment_mention', $commentId);
                        }
                    }
                }
            }

            // 4. ثبت لاگ سیستمی
            if (function_exists('logSystem')) {
                logSystem('Customer Calls', 'Add Comment', $callId, "ثبت نظر جدید برای تماس مشتری {$customerName} توسط {$userFullName}");
            }

            $pdo->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'database error']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'empty comment']);
    }
    exit;
}

if ($method === 'GET') {
    $callId = (int)($_GET['call_id'] ?? 0);
    if ($callId > 0) {
        // خواندن کامنت‌ها به همراه کسانی که در آن کامنت منشن شده‌اند
        $stmt = $pdo->prepare("
            SELECT cc.id, cc.comment, cc.created_at, u.first_name, u.last_name,
            (
                SELECT GROUP_CONCAT(CONCAT(mu.first_name, ' ', mu.last_name) SEPARATOR '||')
                FROM customer_call_comment_mentions cm
                JOIN users mu ON mu.id = cm.user_id
                WHERE cm.comment_id = cc.id
            ) as mentions_list
            FROM customer_call_comments cc
            JOIN users u ON cc.user_id = u.id
            WHERE cc.call_id = ?
            ORDER BY cc.created_at ASC
        ");
        $stmt->execute([$callId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $mentionsArr = [];
            if (!empty($r['mentions_list'])) {
                $mentionsArr = explode('||', $r['mentions_list']);
            }
            $out[] = [
                'user' => $r['first_name'] . ' ' . $r['last_name'],
                'comment' => nl2br(htmlspecialchars($r['comment'])),
                'created_at' => function_exists('jdate') ? jdate('Y/m/d H:i', strtotime($r['created_at'])) : $r['created_at'],
                'mentions' => $mentionsArr
            ];
        }
        echo json_encode(['status' => 'success', 'comments' => $out]);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}