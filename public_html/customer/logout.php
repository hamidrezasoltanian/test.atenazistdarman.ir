<?php
/*
 * فایل: public_html/customer/logout.php
 * توضیحات: خروج از پورتال مشتریان — پاک‌کردن کوکی و غیرفعال‌کردن سشن
 */

require_once __DIR__ . '/../../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// غیرفعال‌سازی توکن در دیتابیس
$token = $_COOKIE['cp_token'] ?? '';
if ($token) {
    try {
        $pdo->prepare("UPDATE customer_portal_sessions SET is_active=0 WHERE token=?")
            ->execute([$token]);
    } catch (Throwable $e) {}
}

// پاک‌کردن کوکی
setcookie('cp_token', '', time() - 3600, '/', '', false, true);

// پاک‌کردن سشن
unset($_SESSION['portal_phone']);

header('Location: index.php');
exit;
