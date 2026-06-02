<?php
// فایل: public_html/admin/actions/delete_call.php
session_start();
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../../login.php"); exit;
}

try {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        throw new Exception("نشست نامعتبر است. لطفاً صفحه را رفرش کنید.");
    }

    $callId = (int)($_POST['call_id'] ?? 0);
    $customerId = (int)($_POST['customer_id'] ?? 0);
    if ($callId <= 0 || $customerId <= 0) throw new Exception("درخواست نامعتبر");

    $stmt = $pdo->prepare("SELECT user_id FROM customer_calls WHERE id = ? LIMIT 1");
    $stmt->execute([$callId]);
    $ownerId = (int)$stmt->fetchColumn();
    if ($ownerId <= 0) throw new Exception("تماس یافت نشد");

    $role = $_SESSION['role'] ?? '';
    $canEdit = ($ownerId === (int)$_SESSION['user_id']) || in_array($role, ['admin','manager'], true);
    if (!$canEdit) throw new Exception("عدم دسترسی");

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM customer_call_mentions WHERE call_id = ?")->execute([$callId]);
    $pdo->prepare("DELETE FROM customer_call_comments WHERE call_id = ?")->execute([$callId]);
    $pdo->prepare("DELETE FROM customer_calls WHERE id = ?")->execute([$callId]);
    $pdo->commit();

    logSystem('CustomerCall', 'delete', $callId, "حذف تماس مشتری {$customerId}");
    header("Location: ../customer_profile.php?id=$customerId&msg=deleted");
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header("Location: ../customer_profile.php?id={$customerId}&error=" . urlencode($e->getMessage()));
}
