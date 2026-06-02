<?php
// فایل: public_html/api/notifications_mark_all_read.php
// توضیحات: مارک کردن تمام اعلانات یک کاربر به عنوان خوانده شده

session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'csrf']);
    exit;
}

try {
    $userId = (int)$_SESSION['user_id'];
    // آپدیت تمام اعلانات نخوانده این شخص به خوانده شده
    $stmt = $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'server_error']);
}