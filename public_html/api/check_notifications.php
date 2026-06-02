<?php
// فایل: public_html/api/check_notifications.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
// تنظیم زمان بررسی به ۱ دقیقه قبل برای جلوگیری از از دست رفتن اعلانات
$lastChecked = isset($_SESSION['last_notif_check']) ? $_SESSION['last_notif_check'] : date('Y-m-d H:i:s', strtotime('-1 minute'));

// آپدیت زمان چک کردن در سشن برای دفعه بعد
$_SESSION['last_notif_check'] = date('Y-m-d H:i:s');

try {
    // اصلاح بسیار مهم: خواندن از user_notifications به جای announcement_targets
    // با این کار پاپ‌آپ مرورگر دقیقاً با زنگوله سیستم سینک می‌شود
    $sql = "SELECT id, title, body AS content, url AS link 
            FROM user_notifications 
            WHERE user_id = ? AND created_at >= ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $lastChecked]);
    $newNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'has_new' => count($newNotifications) > 0,
        'items' => $newNotifications
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}