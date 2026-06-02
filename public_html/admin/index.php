<?php
/*
 * مسیر فایل: public_html/admin/index.php
 * کاربرد: رفع خطای 500 هنگام ورود مدیر
 */

// نمایش خطاها
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// بررسی دسترسی
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// فعلاً مدیر را هم به داشبورد اصلی هدایت می‌کنیم تا پنل ادمین طراحی شود
// با این کار خطای 500 بعد از لاگین ادمین رفع می‌شود
header("Location: ../dashboard.php");
exit;
?>