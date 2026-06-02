<?php
// public_html/api/notifications_mark_read.php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'csrf']);
    exit;
}
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'invalid_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'server_error']);
}
