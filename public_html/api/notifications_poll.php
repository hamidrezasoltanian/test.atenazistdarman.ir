<?php
// فایل: public_html/api/notifications_poll.php
session_start();
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$lastId = (int)($_GET['last_id'] ?? 0);
$limit = 10;

try {
    // گرفتن آیتم‌های جدید برای پاپ‌آپ
    $stmt = $pdo->prepare("SELECT id, title, body, url, type, ref_id, created_at, icon_url
                           FROM user_notifications 
                           WHERE user_id = ? AND (is_read = 0 OR is_read IS NULL) AND id > ?
                           ORDER BY id ASC
                           LIMIT $limit");
    $stmt->execute([$userId, $lastId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // گرفتن تعداد کل اعلانات نخوانده (برای آپدیت زنده زنگوله)
    $stmtCount = $pdo->prepare("SELECT COUNT(id) FROM user_notifications WHERE user_id = ? AND is_read = 0");
    $stmtCount->execute([$userId]);
    $unreadCount = (int)$stmtCount->fetchColumn();
    
    echo json_encode([
        'status' => 'success', 
        'items' => $rows,
        'unread_count' => $unreadCount
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'server_error']);
}