<?php
// فایل: public_html/api/tags.php
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
    $q = trim($_GET['q'] ?? '');
    try {
        if ($q === '') {
            // اضافه شدن ستون category به خروجی
            $stmt = $pdo->query("SELECT id, title, color, category FROM tags ORDER BY id DESC LIMIT 100");
        } else {
            // اضافه شدن ستون category به خروجی
            $stmt = $pdo->prepare("SELECT id, title, color, category FROM tags WHERE title LIKE ? ORDER BY title ASC LIMIT 100");
            $stmt->execute(['%' . $q . '%']);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'tags' => $rows]);
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
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $color = trim($_POST['color'] ?? '#93c5fd');
        $category = trim($_POST['category'] ?? 'type'); // دریافت فیلد category
        
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'invalid_title']);
            exit;
        }
        try {
            // ثبت category در دیتابیس
            $stmt = $pdo->prepare("INSERT INTO tags (title, color, category) VALUES (?, ?, ?)");
            $stmt->execute([$title, $color, $category]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $color = trim($_POST['color'] ?? '#93c5fd');
        $category = trim($_POST['category'] ?? 'type'); // دریافت فیلد category
        
        if ($id <= 0 || $title === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'invalid_data']);
            exit;
        }
        try {
            // آپدیت category در دیتابیس
            $stmt = $pdo->prepare("UPDATE tags SET title = ?, color = ?, category = ? WHERE id = ?");
            $stmt->execute([$title, $color, $category, $id]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'server_error']);
        }
        exit;
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'invalid_id']);
            exit;
        }
        try {
            $pdo->prepare("DELETE FROM customer_tag_links WHERE tag_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tags WHERE id = ?")->execute([$id]);
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