<?php
/*
 * توابع کمکی موتور تأیید چندسطحی
 */

// پیدا کردن جریان فعال برای ماژول و مبلغ مشخص
function approvalGetFlow(PDO $pdo, string $module, int $amount): ?array
{
    $st = $pdo->prepare(
        "SELECT f.*, COUNT(s.id) AS step_count
         FROM approval_flows f
         LEFT JOIN approval_flow_steps s ON s.flow_id = f.id
         WHERE f.module = ? AND f.is_active = 1 AND f.min_amount <= ?
         GROUP BY f.id
         ORDER BY f.min_amount DESC
         LIMIT 1"
    );
    $st->execute([$module, $amount]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ایجاد یک درخواست تأیید جدید
function approvalCreate(PDO $pdo, int $flowId, string $refType, int $refId, int $requestedBy, int $totalAmount, string $desc = ''): int
{
    $pdo->prepare(
        "INSERT INTO approval_requests (flow_id, ref_type, ref_id, current_step, status, requested_by, total_amount, description)
         VALUES (?, ?, ?, 1, 'pending', ?, ?, ?)"
    )->execute([$flowId, $refType, $refId, $requestedBy, $totalAmount, $desc]);
    $reqId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO approval_request_logs (request_id, step_order, action, actor_id)
         VALUES (?, 1, 'created', ?)"
    )->execute([$reqId, $requestedBy]);

    return $reqId;
}

// وضعیت فعلی تأیید یک رکورد
function approvalGetStatus(PDO $pdo, string $refType, int $refId): ?array
{
    if (!$refId) return null;
    $st = $pdo->prepare(
        "SELECT * FROM approval_requests
         WHERE ref_type = ? AND ref_id = ? AND status IN ('pending','approved','rejected')
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$refType, $refId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// بررسی اینکه آیا کاربر می‌تواند روی این درخواست اقدام کند
function approvalCanAct(PDO $pdo, int $requestId, int $userId, string $userRole): bool
{
    $st = $pdo->prepare("SELECT ar.*, afs.approver_type, afs.approver_value
        FROM approval_requests ar
        LEFT JOIN approval_flow_steps afs
            ON afs.flow_id = ar.flow_id AND afs.step_order = ar.current_step
        WHERE ar.id = ? AND ar.status = 'pending'");
    $st->execute([$requestId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;

    if ($row['approver_type'] === 'role') {
        return strtolower($userRole) === strtolower($row['approver_value'])
            || strtolower($userRole) === 'admin';
    }
    return (int)$row['approver_value'] === $userId || strtolower($userRole) === 'admin';
}

// پردازش اقدام تأیید یا رد
function approvalProcess(PDO $pdo, int $requestId, int $actorId, string $action, string $notes = ''): array
{
    if (!in_array($action, ['approved','rejected','cancelled'], true)) {
        return ['ok' => false, 'msg' => 'اقدام نامعتبر'];
    }

    $st = $pdo->prepare("SELECT ar.*, COUNT(afs.id) AS total_steps
        FROM approval_requests ar
        LEFT JOIN approval_flow_steps afs ON afs.flow_id = ar.flow_id
        WHERE ar.id = ? AND ar.status = 'pending'
        GROUP BY ar.id");
    $st->execute([$requestId]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) return ['ok' => false, 'msg' => 'درخواست یافت نشد یا قبلاً پردازش شده'];

    $currentStep = (int)$req['current_step'];
    $totalSteps  = (int)$req['total_steps'];

    if ($action === 'approved' && $currentStep < $totalSteps) {
        // مرحله بعدی وجود دارد
        $pdo->prepare("UPDATE approval_requests SET current_step = current_step+1, updated_at=NOW() WHERE id=?")
            ->execute([$requestId]);
        $pdo->prepare("INSERT INTO approval_request_logs (request_id, step_order, action, actor_id, notes) VALUES (?,?,?,?,?)")
            ->execute([$requestId, $currentStep, 'approved', $actorId, $notes]);
        return ['ok' => true, 'completed' => false, 'msg' => 'تأیید شد — منتظر مرحله بعد'];
    }

    // آخرین مرحله یا رد/لغو
    $finalStatus = $action;
    $pdo->prepare("UPDATE approval_requests SET status=?, updated_at=NOW() WHERE id=?")
        ->execute([$finalStatus, $requestId]);
    $pdo->prepare("INSERT INTO approval_request_logs (request_id, step_order, action, actor_id, notes) VALUES (?,?,?,?,?)")
        ->execute([$requestId, $currentStep, $action, $actorId, $notes]);

    return [
        'ok'        => true,
        'completed' => true,
        'final'     => $finalStatus,
        'ref_type'  => $req['ref_type'],
        'ref_id'    => (int)$req['ref_id'],
        'msg'       => $action === 'approved' ? 'تأیید نهایی انجام شد' : 'درخواست رد شد',
    ];
}

// لیست درخواست‌های قابل اقدام توسط کاربر
function approvalGetPending(PDO $pdo, int $userId, string $userRole): array
{
    $sql = "SELECT ar.*,
                   afs.label AS step_label, afs.approver_type, afs.approver_value,
                   u.full_name AS requester_name,
                   af.name AS flow_name, af.module
            FROM approval_requests ar
            LEFT JOIN approval_flow_steps afs ON afs.flow_id = ar.flow_id AND afs.step_order = ar.current_step
            LEFT JOIN users u ON u.id = ar.requested_by
            LEFT JOIN approval_flows af ON af.id = ar.flow_id
            WHERE ar.status = 'pending'
              AND (
                  (afs.approver_type = 'role'  AND LOWER(afs.approver_value) = LOWER(?))
               OR (afs.approver_type = 'user'  AND afs.approver_value = ?)
               OR LOWER(?) = 'admin'
              )
            ORDER BY ar.created_at DESC";
    $st = $pdo->prepare($sql);
    $st->execute([$userRole, (string)$userId, $userRole]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// بج وضعیت HTML
function approvalBadge(string $status): string
{
    $map = [
        'pending'   => ['amber', 'در انتظار تأیید'],
        'approved'  => ['green', 'تأیید شده'],
        'rejected'  => ['rose',  'رد شده'],
        'cancelled' => ['gray',  'لغو شده'],
    ];
    [$cls, $label] = $map[$status] ?? ['gray', $status];
    return "<span class=\"fin-badge $cls\">$label</span>";
}

// برچسب فارسی ماژول
function approvalModuleLabel(string $module): string
{
    return match($module) {
        'invoice_buy'  => 'فاکتور خرید',
        'invoice_sell' => 'فاکتور فروش',
        'expense'      => 'هزینه',
        'petty_cash'   => 'تنخواه',
        'leave'        => 'مرخصی',
        'mission'      => 'مأموریت',
        default        => $module,
    };
}

// لینک به رکورد مرجع
function approvalRefLink(string $refType, int $refId): string
{
    return match($refType) {
        'invoice_buy'  => "fin_invoice_buy.php?view=$refId",
        'invoice_sell' => "fin_invoice_sell.php?view=$refId",
        'expense'      => "fin_expenses.php?id=$refId",
        'petty_cash'   => "fin_petty_cash.php?id=$refId",
        'leave'        => "leave_requests.php?id=$refId",
        'mission'      => "mission_view.php?id=$refId",
        default        => '#',
    };
}
