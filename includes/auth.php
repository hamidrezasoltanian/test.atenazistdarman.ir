<?php
// includes/auth.php — احراز هویت و کنترل دسترسی

if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    die('دسترسی مستقیم مجاز نیست.');
}

function requireLogin(string $redirect = '/login.php'): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/admin');
        header('Location: ' . $base . $redirect);
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('دسترسی مجاز نیست.');
    }
}

function isAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}
