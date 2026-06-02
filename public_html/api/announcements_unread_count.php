<?php
// public_html/api/announcements_unread_count.php

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$count = 0;

try {
    // خواندن بسیار سریع از جدول اختصاصی
    $stmt = $pdo->prepare("SELECT COUNT(id) FROM user_notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$uid]);
    $count = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $count = 0;
}

echo json_encode(['status' => 'success', 'count' => $count]);