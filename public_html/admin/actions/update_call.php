<?php
// فایل: public_html/admin/actions/update_call.php
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

    $callDateJ = trim($_POST['call_date'] ?? '');
    $callDateJ = faToEn($callDateJ);
    $p = explode('/', $callDateJ);
    if (count($p) !== 3) throw new Exception("تاریخ نامعتبر");
    $callDateG = implode('-', jalali_to_gregorian($p[0], $p[1], $p[2]));

    $stmtU = $pdo->prepare("UPDATE customer_calls SET type=?, call_date=?, call_time=?, subject=?, description=? WHERE id=?");
    $stmtU->execute([
        $_POST['call_type'] ?? 'incoming',
        $callDateG,
        $_POST['call_time'] ?? date('H:i'),
        trim($_POST['subject'] ?? ''),
        trim($_POST['description'] ?? ''),
        $callId
    ]);
    logSystem('CustomerCall', 'update', $callId, "ویرایش تماس مشتری {$customerId}");

    header("Location: ../customer_profile.php?id=$customerId&msg=updated");
} catch (Exception $e) {
    header("Location: ../customer_profile.php?id={$customerId}&error=" . urlencode($e->getMessage()));
}
