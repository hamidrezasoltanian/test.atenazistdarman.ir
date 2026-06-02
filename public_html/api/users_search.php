<?php
// public_html/api/users_search.php
session_start();
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$q = trim($_GET['q'] ?? '');
$limit = 20;

try {
    if ($q === '') {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, username, role FROM users WHERE status = 'active' ORDER BY id DESC LIMIT $limit");
        $stmt->execute();
    } else {
        $qLike = '%' . $q . '%';
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, username, role FROM users WHERE status = 'active' AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ?) ORDER BY first_name ASC LIMIT $limit");
        $stmt->execute([$qLike, $qLike, $qLike]);
    }
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($users as $u) {
        $out[] = [
            'id' => (int)$u['id'],
            'name' => trim($u['first_name'] . ' ' . $u['last_name']),
            'username' => $u['username'],
            'role' => $u['role'],
        ];
    }
    echo json_encode(['status' => 'success', 'users' => $out]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'server_error']);
}
