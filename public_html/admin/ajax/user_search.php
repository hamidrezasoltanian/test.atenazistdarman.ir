<?php
// فایل: public_html/admin/ajax/user_search.php
require_once __DIR__ . '/../../../includes/db.php';
session_start();

if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }

$q = $_GET['q'] ?? '';
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

$stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE (first_name LIKE ? OR last_name LIKE ?) AND status='active' LIMIT 10");
$stmt->execute(["%$q%", "%$q%"]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>