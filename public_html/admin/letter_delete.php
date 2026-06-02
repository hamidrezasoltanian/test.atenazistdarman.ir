<?php
/*
 * فایل: public_html/admin/letter_delete.php
 * توضیحات: سیستم حذف هوشمند (انتقال به زباله‌دان یا حذف فیزیکی دائمی)
 */

ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { die("عدم دسترسی"); }

$userId = (int)$_SESSION['user_id'];
$isAdmin = ($_SESSION['role'] === 'admin');

// دریافت آیدی از GET (برای انتقال به زباله دان) یا POST (برای عملیات فرم)
$letterId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['letter_id']) ? (int)$_POST['letter_id'] : 0);
$forceDelete = isset($_POST['force_delete']) && $_POST['force_delete'] == '1';
$restore = isset($_POST['restore']) && $_POST['restore'] == '1';

if ($letterId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT subject, indicator_number, created_by FROM letters WHERE id = ?");
        $stmt->execute([$letterId]);
        $letter = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($letter) {
            // فقط ادمین یا ایجاد کننده نامه حق حذف دارد
            if (!$isAdmin && $letter['created_by'] != $userId) {
                $_SESSION['flash_error'] = "⛔ شما دسترسی لازم برای حذف این نامه را ندارید.";
            } else {
                if ($restore && $isAdmin) {
                    // بازگردانی از زباله‌دان
                    $pdo->prepare("UPDATE letters SET is_deleted = 0 WHERE id = ?")->execute([$letterId]);
                    logSystem('Letters', 'restore_trash', $letterId, "بازگردانی نامه از زباله‌دان");
                    $_SESSION['flash_success'] = "نامه با موفقیت از زباله‌دان بازگردانی شد.";
                    
                } elseif ($forceDelete && $isAdmin) {
                    // حذف فیزیکی و دائمی
                    $pdo->beginTransaction();
                    
                    // حذف فایل‌ها از سرور
                    $stmtFiles = $pdo->prepare("SELECT file_path FROM letter_attachments WHERE letter_id = ?");
                    $stmtFiles->execute([$letterId]);
                    $files = $stmtFiles->fetchAll(PDO::FETCH_COLUMN);
                    $uploadDir = __DIR__ . '/../../public_html/uploads/letters/';
                    foreach ($files as $file) {
                        if (file_exists($uploadDir . $file)) @unlink($uploadDir . $file);
                    }

                    $pdo->prepare("DELETE FROM letter_attachments WHERE letter_id = ?")->execute([$letterId]);
                    $pdo->prepare("DELETE FROM letter_referrals WHERE letter_id = ?")->execute([$letterId]);
                    $pdo->prepare("DELETE FROM letters WHERE id = ?")->execute([$letterId]);
                    
                    logSystem('Letters', 'force_delete', $letterId, "حذف دائمی و فیزیکی نامه");
                    $pdo->commit();
                    $_SESSION['flash_success'] = "نامه و تمامی فایل‌های ضمیمه آن برای همیشه پاک شدند.";
                    
                } else {
                    // انتقال به زباله‌دان (Soft Delete)
                    $pdo->prepare("UPDATE letters SET is_deleted = 1 WHERE id = ?")->execute([$letterId]);
                    logSystem('Letters', 'trash', $letterId, "انتقال نامه به زباله‌دان");
                    $_SESSION['flash_success'] = "نامه به زباله‌دان منتقل شد.";
                }
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_error'] = "خطا در عملیات: " . $e->getMessage();
    }
}

$referer = $_SERVER['HTTP_REFERER'] ?? 'cartable_inbox.php';
header("Location: " . $referer);
exit;