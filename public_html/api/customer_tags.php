<?php
// فایل: public_html/api/customer_tags.php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'invalid_customer']);
        exit;
    }
    try {
        // اضافه شدن ستون t.category به خروجی برای استفاده در رابط کاربری
        $stmt = $pdo->prepare("SELECT t.id, t.title, t.color, t.category
                               FROM customer_tag_links l
                               JOIN tags t ON t.id = l.tag_id
                               WHERE l.customer_id = ?
                               ORDER BY l.id DESC");
        $stmt->execute([$customerId]);
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'tags' => $tags]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'server_error']);
    }
    exit;
}

if ($method === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'csrf']);
        exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'assign') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $tagId = (int)($_POST['tag_id'] ?? 0);
        if ($customerId <= 0 || $tagId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'invalid_data']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO customer_tag_links (customer_id, tag_id) VALUES (?, ?)");
            $stmt->execute([$customerId, $tagId]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }
    if ($action === 'remove') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $tagId = (int)($_POST['tag_id'] ?? 0);
        if ($customerId <= 0 || $tagId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'invalid_data']);
            exit;
        }
        try {
            $pdo->prepare("DELETE FROM customer_tag_links WHERE customer_id = ? AND tag_id = ?")->execute([$customerId, $tagId]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'method_not_allowed']);