<?php
/*
 * فایل: public_html/admin/support_tickets.php
 * توضیحات: سیستم تیکت پشتیبانی پس از فروش
 */

ob_start();

require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$userId  = (int)$_SESSION['user_id'];
$rawRole = $_SESSION['role'] ?? '';

// ---- بررسی دسترسی ----
$hasAccess = false;
try {
    $stmtChk = $pdo->prepare('SELECT name, is_system, permissions FROM roles WHERE id = ? OR name = ?');
    $stmtChk->execute([$rawRole, $rawRole]);
    $roleData = $stmtChk->fetch(PDO::FETCH_ASSOC);
    if ($roleData) {
        $roleName = strtolower(trim($roleData['name'] ?? $rawRole));
        $allowedRoles = ['admin','management','manager','support','sales','sales_manager','finance_manager'];
        if (in_array($roleName, $allowedRoles) || $roleData['is_system'] == 1) {
            $hasAccess = true;
        } else {
            $perms = json_decode($roleData['permissions'] ?? '[]', true) ?? [];
            if (in_array('support', $perms) || in_array('all', $perms)) $hasAccess = true;
        }
    }
    if (in_array($rawRole, ['1','2','5','10','14'])) $hasAccess = true;
} catch (Throwable $e) { $hasAccess = true; }

if (!$hasAccess) {
    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'دسترسی غیرمجاز']);
        exit;
    }
    die('<p style="font-family:Tahoma;color:#e11d48;text-align:center;padding:60px">دسترسی غیرمجاز</p>');
}

// ---- ایجاد جداول در صورت نبود ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `support_tickets` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ticket_number` VARCHAR(20) NOT NULL,
        `subject` VARCHAR(200) NOT NULL,
        `person_id` INT NOT NULL DEFAULT 0,
        `customer_name` VARCHAR(200) DEFAULT NULL,
        `invoice_id` INT DEFAULT NULL,
        `priority` ENUM('low','medium','high','critical') DEFAULT 'medium',
        `status` ENUM('open','in_progress','waiting','resolved','closed') DEFAULT 'open',
        `category` VARCHAR(100) DEFAULT NULL,
        `assigned_to` INT DEFAULT NULL,
        `created_by` INT NOT NULL,
        `description` TEXT DEFAULT NULL,
        `resolution` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `resolved_at` DATETIME DEFAULT NULL,
        `is_deleted` TINYINT(1) DEFAULT 0,
        UNIQUE KEY `uq_ticket_number` (`ticket_number`),
        KEY `idx_status` (`status`),
        KEY `idx_person` (`person_id`),
        KEY `idx_assigned` (`assigned_to`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `support_ticket_replies` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ticket_id` INT NOT NULL,
        `user_id` INT NOT NULL,
        `message` TEXT NOT NULL,
        `is_internal` TINYINT(1) DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_ticket` (`ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { /* جداول از قبل وجود داشتند */ }

// ---- شماره‌گذاری خودکار تیکت ----
function nextTicketNumber($pdo) {
    $ym = date('Ym');
    try {
        $last = $pdo->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(ticket_number,'-',-1) AS UNSIGNED))
             FROM support_tickets WHERE ticket_number LIKE 'TKT-{$ym}-%'"
        )->fetchColumn();
    } catch (Throwable $e) { $last = 0; }
    $seq = (int)$last + 1;
    return 'TKT-' . $ym . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ---- برچسب رنگی وضعیت ----
function statusBadge($status) {
    $map = [
        'open'        => ['rose',   'باز'],
        'in_progress' => ['amber',  'در حال بررسی'],
        'waiting'     => ['purple', 'انتظار'],
        'resolved'    => ['green',  'حل‌شده'],
        'closed'      => ['gray',   'بسته'],
    ];
    $d = $map[$status] ?? ['gray', $status];
    return ['color' => $d[0], 'label' => $d[1]];
}

// ---- برچسب رنگی اولویت ----
function priorityBadge($priority) {
    $map = [
        'low'      => ['green',  'کم'],
        'medium'   => ['amber',  'متوسط'],
        'high'     => ['rose',   'بالا'],
        'critical' => ['red',    'بحرانی'],
    ];
    $d = $map[$priority] ?? ['gray', $priority];
    return ['color' => $d[0], 'label' => $d[1]];
}

// ===========================================================
// پردازش درخواست‌های AJAX
// ===========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action'] ?? '');

    try {

        // ---- لیست تیکت‌ها ----
        if ($action === 'list') {
            $page    = max(1, (int)($_POST['page'] ?? 1));
            $perPage = 25;
            $offset  = ($page - 1) * $perPage;
            $search  = trim($_POST['search'] ?? '');
            $status  = trim($_POST['status'] ?? '');
            $priority = trim($_POST['priority'] ?? '');

            $where  = ['t.is_deleted = 0'];
            $params = [];

            if ($search) {
                $where[] = '(t.ticket_number LIKE ? OR t.subject LIKE ? OR t.customer_name LIKE ?)';
                $s = "%$search%";
                array_push($params, $s, $s, $s);
            }
            if ($status)   { $where[] = 't.status = ?';   $params[] = $status; }
            if ($priority) { $where[] = 't.priority = ?'; $params[] = $priority; }

            $whereStr = implode(' AND ', $where);

            $total = (int)$pdo->prepare("SELECT COUNT(*) FROM support_tickets t WHERE $whereStr")
                ->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM support_tickets t WHERE $whereStr") : 0;
            $stCount = $pdo->prepare("SELECT COUNT(*) FROM support_tickets t WHERE $whereStr");
            $stCount->execute($params);
            $total = (int)$stCount->fetchColumn();

            $stList = $pdo->prepare(
                "SELECT t.*, u.fullname AS assigned_name,
                        (SELECT COUNT(*) FROM support_ticket_replies r WHERE r.ticket_id = t.id) AS reply_count
                 FROM support_tickets t
                 LEFT JOIN users u ON u.id = t.assigned_to
                 WHERE $whereStr
                 ORDER BY FIELD(t.priority,'critical','high','medium','low'), t.created_at DESC
                 LIMIT $perPage OFFSET $offset"
            );
            $stList->execute($params);
            $rows = $stList->fetchAll(PDO::FETCH_ASSOC);

            // شمارش باز برای تب
            $openCount = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status='open' AND is_deleted=0")->fetchColumn();

            $result = [];
            foreach ($rows as $r) {
                $sb = statusBadge($r['status']);
                $pb = priorityBadge($r['priority']);
                $result[] = [
                    'id'            => $r['id'],
                    'ticket_number' => $r['ticket_number'],
                    'subject'       => $r['subject'],
                    'customer_name' => $r['customer_name'],
                    'category'      => $r['category'],
                    'priority'      => $r['priority'],
                    'priority_label'=> $pb['label'],
                    'priority_color'=> $pb['color'],
                    'status'        => $r['status'],
                    'status_label'  => $sb['label'],
                    'status_color'  => $sb['color'],
                    'assigned_name' => $r['assigned_name'],
                    'reply_count'   => (int)$r['reply_count'],
                    'created_at'    => $r['created_at'] ? jdate('Y/m/d H:i', strtotime($r['created_at'])) : '',
                    'resolved_at'   => $r['resolved_at'] ? jdate('Y/m/d', strtotime($r['resolved_at'])) : '',
                ];
            }

            echo json_encode([
                'ok'         => true,
                'rows'       => $result,
                'total'      => $total,
                'pages'      => ceil($total / $perPage),
                'page'       => $page,
                'open_count' => $openCount,
            ]);
            exit;
        }

        // ---- دریافت جزئیات تیکت ----
        if ($action === 'get_ticket') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT t.*, u.fullname AS assigned_name, c.fullname AS creator_name
                                  FROM support_tickets t
                                  LEFT JOIN users u ON u.id = t.assigned_to
                                  LEFT JOIN users c ON c.id = t.created_by
                                  WHERE t.id = ? AND t.is_deleted = 0");
            $st->execute([$id]);
            $ticket = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) { echo json_encode(['ok' => false, 'msg' => 'تیکت یافت نشد']); exit; }

            // پاسخ‌ها
            $stR = $pdo->prepare(
                "SELECT r.*, u.fullname AS user_name, u.profile_image
                 FROM support_ticket_replies r
                 LEFT JOIN users u ON u.id = r.user_id
                 WHERE r.ticket_id = ?
                 ORDER BY r.created_at ASC"
            );
            $stR->execute([$id]);
            $replies = $stR->fetchAll(PDO::FETCH_ASSOC);

            $replyList = [];
            foreach ($replies as $rr) {
                $replyList[] = [
                    'id'          => $rr['id'],
                    'user_name'   => $rr['user_name'],
                    'message'     => $rr['message'],
                    'is_internal' => (int)$rr['is_internal'],
                    'created_at'  => $rr['created_at'] ? jdate('Y/m/d H:i', strtotime($rr['created_at'])) : '',
                ];
            }

            $sb = statusBadge($ticket['status']);
            $pb = priorityBadge($ticket['priority']);

            echo json_encode([
                'ok'     => true,
                'ticket' => [
                    'id'            => $ticket['id'],
                    'ticket_number' => $ticket['ticket_number'],
                    'subject'       => $ticket['subject'],
                    'customer_name' => $ticket['customer_name'],
                    'category'      => $ticket['category'],
                    'priority'      => $ticket['priority'],
                    'priority_label'=> $pb['label'],
                    'priority_color'=> $pb['color'],
                    'status'        => $ticket['status'],
                    'status_label'  => $sb['label'],
                    'status_color'  => $sb['color'],
                    'assigned_name' => $ticket['assigned_name'],
                    'creator_name'  => $ticket['creator_name'],
                    'description'   => $ticket['description'],
                    'resolution'    => $ticket['resolution'],
                    'invoice_id'    => $ticket['invoice_id'],
                    'created_at'    => $ticket['created_at'] ? jdate('Y/m/d H:i', strtotime($ticket['created_at'])) : '',
                    'resolved_at'   => $ticket['resolved_at'] ? jdate('Y/m/d', strtotime($ticket['resolved_at'])) : '',
                ],
                'replies' => $replyList,
            ]);
            exit;
        }

        // ---- ذخیره تیکت (ایجاد/ویرایش) ----
        if ($action === 'save_ticket') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'توکن امنیتی نامعتبر']); exit;
            }

            $id          = (int)($_POST['id'] ?? 0);
            $subject     = trim($_POST['subject'] ?? '');
            $personId    = (int)($_POST['person_id'] ?? 0);
            $customerName= trim($_POST['customer_name'] ?? '');
            $invoiceId   = (int)($_POST['invoice_id'] ?? 0) ?: null;
            $priority    = trim($_POST['priority'] ?? 'medium');
            $category    = trim($_POST['category'] ?? '');
            $assignedTo  = (int)($_POST['assigned_to'] ?? 0) ?: null;
            $description = trim($_POST['description'] ?? '');

            if (!$subject) { echo json_encode(['ok' => false, 'msg' => 'موضوع الزامی است']); exit; }

            $validPriorities = ['low','medium','high','critical'];
            if (!in_array($priority, $validPriorities)) $priority = 'medium';

            if ($id > 0) {
                // ویرایش
                $st = $pdo->prepare(
                    "UPDATE support_tickets SET subject=?, person_id=?, customer_name=?, invoice_id=?,
                     priority=?, category=?, assigned_to=?, description=?, updated_at=NOW()
                     WHERE id=? AND is_deleted=0"
                );
                $st->execute([$subject, $personId, $customerName, $invoiceId, $priority, $category, $assignedTo, $description, $id]);
                echo json_encode(['ok' => true, 'msg' => 'تیکت به‌روزرسانی شد', 'id' => $id]);
            } else {
                // ایجاد
                $ticketNum = nextTicketNumber($pdo);
                $st = $pdo->prepare(
                    "INSERT INTO support_tickets
                     (ticket_number, subject, person_id, customer_name, invoice_id, priority, status,
                      category, assigned_to, created_by, description, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,'open',?,?,?,NOW(),NOW())"
                );
                $st->execute([$ticketNum, $subject, $personId, $customerName, $invoiceId, $priority, $category, $assignedTo, $userId]);
                $newId = (int)$pdo->lastInsertId();
                echo json_encode(['ok' => true, 'msg' => 'تیکت ثبت شد', 'id' => $newId, 'ticket_number' => $ticketNum]);
            }
            exit;
        }

        // ---- افزودن پاسخ ----
        if ($action === 'add_reply') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'توکن امنیتی نامعتبر']); exit;
            }

            $ticketId   = (int)($_POST['ticket_id'] ?? 0);
            $message    = trim($_POST['message'] ?? '');
            $isInternal = (int)($_POST['is_internal'] ?? 0);

            if (!$ticketId || !$message) {
                echo json_encode(['ok' => false, 'msg' => 'پیام الزامی است']); exit;
            }

            // بررسی وجود تیکت
            $stChk = $pdo->prepare("SELECT id, status FROM support_tickets WHERE id=? AND is_deleted=0");
            $stChk->execute([$ticketId]);
            $tk = $stChk->fetch(PDO::FETCH_ASSOC);
            if (!$tk) { echo json_encode(['ok' => false, 'msg' => 'تیکت یافت نشد']); exit; }

            $stR = $pdo->prepare(
                "INSERT INTO support_ticket_replies (ticket_id, user_id, message, is_internal, created_at)
                 VALUES (?,?,?,?,NOW())"
            );
            $stR->execute([$ticketId, $userId, $message, $isInternal]);

            // اگر تیکت باز باشد و پاسخ داده شود → در حال بررسی
            if ($tk['status'] === 'open') {
                $pdo->prepare("UPDATE support_tickets SET status='in_progress', updated_at=NOW() WHERE id=?")
                    ->execute([$ticketId]);
            }

            echo json_encode(['ok' => true, 'msg' => 'پاسخ ثبت شد']);
            exit;
        }

        // ---- تغییر وضعیت ----
        if ($action === 'change_status') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'msg' => 'توکن امنیتی نامعتبر']); exit;
            }

            $ticketId  = (int)($_POST['ticket_id'] ?? 0);
            $newStatus = trim($_POST['status'] ?? '');
            $resolution = trim($_POST['resolution'] ?? '');

            $validStatuses = ['open','in_progress','waiting','resolved','closed'];
            if (!in_array($newStatus, $validStatuses)) {
                echo json_encode(['ok' => false, 'msg' => 'وضعیت نامعتبر']); exit;
            }

            $resolvedAt = ($newStatus === 'resolved') ? ', resolved_at=NOW()' : '';
            $resSql     = $resolution ? ', resolution=?' : '';
            $params     = [];
            if ($resolution) $params[] = $resolution;
            $params[] = $newStatus;
            $params[] = $ticketId;

            $pdo->prepare("UPDATE support_tickets SET status=? $resolvedAt $resSql, updated_at=NOW() WHERE id=? AND is_deleted=0")
                ->execute(array_merge($resolution ? [$resolution] : [], [$newStatus, $ticketId]));

            echo json_encode(['ok' => true, 'msg' => 'وضعیت به‌روز شد']);
            exit;
        }

        // ---- آمار سریع ----
        if ($action === 'stats') {
            $open       = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status='open' AND is_deleted=0")->fetchColumn();
            $today      = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE DATE(created_at)=CURDATE() AND is_deleted=0")->fetchColumn();
            $resolvedWk = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status='resolved' AND resolved_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) AND is_deleted=0")->fetchColumn();

            // میانگین زمان پاسخ اولیه (اولین پاسخ پس از ایجاد تیکت)
            $avgReply = $pdo->query(
                "SELECT AVG(TIMESTAMPDIFF(HOUR, t.created_at, r.created_at))
                 FROM support_tickets t
                 JOIN support_ticket_replies r ON r.ticket_id = t.id
                 WHERE r.id = (SELECT MIN(r2.id) FROM support_ticket_replies r2 WHERE r2.ticket_id = t.id)
                 AND t.is_deleted = 0"
            )->fetchColumn();

            echo json_encode([
                'ok'         => true,
                'open'       => $open,
                'today'      => $today,
                'resolved_wk'=> $resolvedWk,
                'avg_reply'  => $avgReply ? round((float)$avgReply, 1) . ' ساعت' : '—',
            ]);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'عملیات نامشخص']);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'خطا: ' . $e->getMessage()]);
    }
    exit;
}

// ---- بارگذاری لیست کارشناسان برای منوی dropdown ----
$agents = [];
try {
    $stA = $pdo->query("SELECT id, fullname FROM users WHERE is_active=1 ORDER BY fullname");
    $agents = $stA->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ---- بارگذاری طرف‌حساب‌ها ----
$persons = [];
try {
    $stP = $pdo->query("SELECT id, name, company_name FROM fin_persons WHERE is_deleted=0 ORDER BY name LIMIT 300");
    $persons = $stP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$csrfToken  = csrf_token();
$basePath   = '../../';
$pageTitle  = 'تیکت‌های پشتیبانی';
$activePage = 'support_tickets';

$extraCss = '
<link rel="stylesheet" href="' . $basePath . 'assets/css/fin_module.css">
<style>
/* ===== support tickets custom styles ===== */
.sup-page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.sup-page-title { display: flex; align-items: center; gap: 14px; }
.sup-page-icon {
    width: 52px; height: 52px; border-radius: 14px;
    background: linear-gradient(135deg, #1e40af, #3b82f6);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; color: #fff; flex-shrink: 0;
}
.sup-page-title h1 { font-size: 1.4rem; font-weight: 700; color: #1e293b; margin: 0 0 4px; }
.sup-page-title p  { font-size: 0.82rem; color: #64748b; margin: 0; }

/* تب‌ها */
.sup-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 20px; }
.sup-tab {
    padding: 8px 18px; border-radius: 8px; border: 1.5px solid #e2e8f0;
    background: #f8fafc; color: #475569; font-size: 0.82rem; cursor: pointer;
    font-family: Vazirmatn, sans-serif; transition: all 0.2s; display: flex; align-items: center; gap: 6px;
}
.sup-tab:hover { border-color: #3b82f6; color: #2563eb; }
.sup-tab.active { background: #2563eb; color: #fff; border-color: #2563eb; font-weight: 600; }
.sup-tab-badge {
    background: #ef4444; color: #fff; font-size: 0.7rem;
    padding: 1px 7px; border-radius: 20px; font-weight: 700;
}
.sup-tab.active .sup-tab-badge { background: rgba(255,255,255,0.3); }

/* جدول */
.sup-table { width: 100%; border-collapse: collapse; }
.sup-table th { background: #f1f5f9; color: #475569; font-size: 0.78rem; font-weight: 600;
                 padding: 10px 14px; text-align: right; border-bottom: 2px solid #e2e8f0; }
.sup-table td { padding: 11px 14px; border-bottom: 1px solid #f1f5f9; font-size: 0.82rem; color: #334155;
                 vertical-align: middle; }
.sup-table tr:hover td { background: #f8fafc; cursor: pointer; }
.sup-table tr:last-child td { border-bottom: none; }

/* badge رنگی */
.badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-size: 0.74rem; font-weight: 600; white-space: nowrap;
}
.badge-rose   { background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; }
.badge-amber  { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.badge-green  { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.badge-purple { background: #faf5ff; color: #7c3aed; border: 1px solid #e9d5ff; }
.badge-gray   { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
.badge-red    { background: #fff1f2; color: #b91c1c; border: 1px solid #fca5a5; font-weight: 800; }
.badge-blue   { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }

/* درِ کشویی */
.sup-drawer-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1200;
}
.sup-drawer-overlay.active { display: block; }
.sup-drawer {
    position: fixed; top: 0; left: -680px; width: 680px; max-width: 98vw;
    height: 100vh; background: #fff; box-shadow: 4px 0 30px rgba(0,0,0,0.15);
    z-index: 1201; display: flex; flex-direction: column;
    transition: left 0.3s cubic-bezier(0.4,0,0.2,1);
    font-family: Vazirmatn, sans-serif;
}
.sup-drawer.open { left: 0; }
.sup-drawer-head {
    padding: 18px 22px; background: linear-gradient(135deg,#1e40af,#3b82f6);
    color: #fff; display: flex; align-items: flex-start; justify-content: space-between; flex-shrink: 0;
}
.sup-drawer-head h3 { margin: 0 0 6px; font-size: 1rem; font-weight: 700; }
.sup-drawer-head p  { margin: 0; font-size: 0.78rem; opacity: 0.85; }
.sup-drawer-close {
    background: rgba(255,255,255,0.2); border: none; color: #fff; width: 34px; height: 34px;
    border-radius: 8px; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.sup-drawer-close:hover { background: rgba(255,255,255,0.35); }
.sup-drawer-body {
    flex: 1; overflow-y: auto; padding: 20px 22px;
}
.sup-drawer-footer {
    padding: 16px 22px; border-top: 1px solid #e2e8f0; flex-shrink: 0; background: #f8fafc;
}
.sup-info-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px;
}
.sup-info-item label { font-size: 0.74rem; color: #94a3b8; display: block; margin-bottom: 3px; }
.sup-info-item span  { font-size: 0.85rem; color: #1e293b; font-weight: 500; }
.sup-desc-box {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 14px; font-size: 0.84rem; color: #334155; line-height: 1.8; white-space: pre-wrap; margin-bottom: 20px;
}
.sup-replies { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
.sup-reply {
    background: #f1f5f9; border-radius: 10px; padding: 12px 14px;
    border-right: 3px solid #cbd5e1;
}
.sup-reply.internal { background: #fffbeb; border-color: #fde68a; }
.sup-reply.mine     { background: #eff6ff; border-color: #93c5fd; }
.sup-reply-head { display: flex; justify-content: space-between; margin-bottom: 6px; }
.sup-reply-user { font-size: 0.78rem; font-weight: 700; color: #1e293b; }
.sup-reply-time { font-size: 0.72rem; color: #94a3b8; }
.sup-reply-msg  { font-size: 0.83rem; color: #334155; line-height: 1.75; white-space: pre-wrap; }

/* فرم ایجاد تیکت */
.sup-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media(max-width:600px) { .sup-form-grid { grid-template-columns: 1fr; } .sup-info-grid { grid-template-columns: 1fr; } }

.sup-status-bar {
    display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; padding-bottom: 16px;
    border-bottom: 1px solid #e2e8f0;
}
.sup-status-btn {
    padding: 5px 14px; border-radius: 20px; border: 1.5px solid #e2e8f0;
    background: #f8fafc; color: #475569; font-size: 0.76rem; cursor: pointer;
    font-family: Vazirmatn, sans-serif; transition: all 0.15s;
}
.sup-status-btn:hover { border-color: #3b82f6; color: #2563eb; }
.sup-status-btn.active { border-color: #2563eb; background: #eff6ff; color: #1d4ed8; font-weight: 700; }

.empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; font-size: 0.9rem; }
.empty-state-icon { font-size: 3rem; margin-bottom: 12px; }
</style>
';

require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content">
    <div class="sup-page-header">
        <div class="sup-page-title">
            <div class="sup-page-icon">🎫</div>
            <div>
                <h1>تیکت‌های پشتیبانی</h1>
                <p>مدیریت درخواست‌های پشتیبانی و پیگیری مشکلات مشتریان</p>
            </div>
        </div>
        <button class="fin-btn fin-btn-primary" onclick="openNewTicketDrawer()" style="background:#2563eb;border-color:#1d4ed8">
            ➕ تیکت جدید
        </button>
    </div>

    <!-- کارت‌های آمار -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px">
        <div class="fin-stat-card blue">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#1e40af,#2563eb)">🟥</div>
            <div><div class="fin-stat-label">تیکت‌های باز</div><div class="fin-stat-num" id="statOpen">—</div></div>
        </div>
        <div class="fin-stat-card amber">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#92400e,#d97706)">⏱</div>
            <div><div class="fin-stat-label">میانگین زمان پاسخ</div><div class="fin-stat-num" id="statAvgReply">—</div></div>
        </div>
        <div class="fin-stat-card cyan">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#164e63,#0891b2)">📅</div>
            <div><div class="fin-stat-label">تیکت‌های امروز</div><div class="fin-stat-num" id="statToday">—</div></div>
        </div>
        <div class="fin-stat-card green">
            <div class="fin-stat-icon" style="background:linear-gradient(135deg,#14532d,#16a34a)">✅</div>
            <div><div class="fin-stat-label">حل‌شده این هفته</div><div class="fin-stat-num" id="statResolvedWk">—</div></div>
        </div>
    </div>

    <!-- تب‌ها -->
    <div class="sup-tabs" id="supTabs">
        <button class="sup-tab active" data-status="" onclick="switchTab(this,'')">
            همه تیکت‌ها
        </button>
        <button class="sup-tab" data-status="open" onclick="switchTab(this,'open')">
            باز <span class="sup-tab-badge" id="openBadge" style="display:none">0</span>
        </button>
        <button class="sup-tab" data-status="in_progress" onclick="switchTab(this,'in_progress')">در حال بررسی</button>
        <button class="sup-tab" data-status="waiting" onclick="switchTab(this,'waiting')">انتظار</button>
        <button class="sup-tab" data-status="resolved" onclick="switchTab(this,'resolved')">حل‌شده</button>
        <button class="sup-tab" data-status="closed" onclick="switchTab(this,'closed')">بسته</button>
    </div>

    <!-- فیلترها -->
    <div class="fin-panel" style="margin-bottom:16px;padding:14px 16px">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:200px">
                <label class="fin-label">جستجو</label>
                <input type="text" id="fSearch" class="fin-input" placeholder="شماره تیکت / موضوع / نام مشتری..." onkeyup="debounceList()">
            </div>
            <div>
                <label class="fin-label">اولویت</label>
                <select id="fPriority" class="fin-input" onchange="loadList(1)">
                    <option value="">همه</option>
                    <option value="critical">بحرانی</option>
                    <option value="high">بالا</option>
                    <option value="medium">متوسط</option>
                    <option value="low">کم</option>
                </select>
            </div>
            <button class="fin-btn" onclick="loadList(1)" style="background:#3b82f6;color:#fff;border-color:#2563eb">🔍 جستجو</button>
        </div>
    </div>

    <!-- جدول لیست -->
    <div class="fin-panel" style="padding:0">
        <div style="overflow-x:auto">
            <table class="sup-table">
                <thead>
                    <tr>
                        <th>شماره تیکت</th>
                        <th>موضوع</th>
                        <th>مشتری</th>
                        <th>دسته‌بندی</th>
                        <th>اولویت</th>
                        <th>وضعیت</th>
                        <th>کارشناس</th>
                        <th>پاسخ</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody id="ticketListBody">
                    <tr><td colspan="9" class="empty-state"><div class="empty-state-icon">⏳</div>در حال بارگذاری...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="ticketPagination" style="padding:14px 16px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap"></div>
    </div>
</main>

<!-- ======= Overlay ======= -->
<div class="sup-drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

<!-- ======= Drawer: جزئیات/پاسخ تیکت ======= -->
<div class="sup-drawer" id="ticketDrawer">
    <div class="sup-drawer-head">
        <div>
            <h3 id="drawerTitle">جزئیات تیکت</h3>
            <p id="drawerSubtitle">بارگذاری...</p>
        </div>
        <button class="sup-drawer-close" onclick="closeDrawer()">✕</button>
    </div>
    <div class="sup-drawer-body" id="drawerBody">
        <div class="empty-state"><div class="empty-state-icon">🎫</div>در حال بارگذاری...</div>
    </div>
    <div class="sup-drawer-footer" id="drawerFooter" style="display:none">
        <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center">
            <label style="font-size:0.8rem;color:#475569">نوع پاسخ:</label>
            <label style="font-size:0.8rem;display:flex;align-items:center;gap:4px;cursor:pointer">
                <input type="radio" name="replyType" value="0" checked> عمومی
            </label>
            <label style="font-size:0.8rem;display:flex;align-items:center;gap:4px;cursor:pointer">
                <input type="radio" name="replyType" value="1"> داخلی (یادداشت)
            </label>
        </div>
        <textarea id="replyMsg" class="fin-input" rows="3" placeholder="متن پاسخ را بنویسید..." style="width:100%;resize:vertical"></textarea>
        <div style="display:flex;justify-content:space-between;margin-top:10px;gap:8px;flex-wrap:wrap">
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <select id="changeStatusSel" class="fin-input" style="font-size:0.8rem">
                    <option value="">— تغییر وضعیت —</option>
                    <option value="open">باز</option>
                    <option value="in_progress">در حال بررسی</option>
                    <option value="waiting">انتظار</option>
                    <option value="resolved">حل‌شده</option>
                    <option value="closed">بسته</option>
                </select>
            </div>
            <button class="fin-btn" onclick="submitReply()" style="background:#2563eb;color:#fff;border-color:#1d4ed8">
                📨 ارسال پاسخ
            </button>
        </div>
    </div>
</div>

<!-- ======= Drawer: ایجاد تیکت جدید ======= -->
<div class="sup-drawer" id="newTicketDrawer" style="width:600px">
    <div class="sup-drawer-head">
        <div>
            <h3>تیکت جدید</h3>
            <p>ثبت درخواست پشتیبانی جدید</p>
        </div>
        <button class="sup-drawer-close" onclick="closeNewDrawer()">✕</button>
    </div>
    <div class="sup-drawer-body">
        <form id="newTicketForm" onsubmit="return false">
            <?= csrf_field() ?>
            <div class="sup-form-grid" style="margin-bottom:14px">
                <div>
                    <label class="fin-label">موضوع *</label>
                    <input type="text" name="subject" class="fin-input" placeholder="موضوع تیکت" required>
                </div>
                <div>
                    <label class="fin-label">مشتری</label>
                    <select name="person_id" class="fin-input" onchange="fillCustomerName(this)">
                        <option value="0">— انتخاب طرف حساب —</option>
                        <?php foreach ($persons as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" data-name="<?= htmlspecialchars($p['company_name'] ?: $p['name'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($p['company_name'] ?: $p['name'], ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="fin-label">نام مشتری (اگر ثبت‌نشده)</label>
                    <input type="text" name="customer_name" id="ntCustomerName" class="fin-input" placeholder="نام مشتری">
                </div>
                <div>
                    <label class="fin-label">اولویت</label>
                    <select name="priority" class="fin-input">
                        <option value="low">کم</option>
                        <option value="medium" selected>متوسط</option>
                        <option value="high">بالا</option>
                        <option value="critical">بحرانی</option>
                    </select>
                </div>
                <div>
                    <label class="fin-label">دسته‌بندی</label>
                    <select name="category" class="fin-input">
                        <option value="">— انتخاب —</option>
                        <option value="نصب و راه‌اندازی">نصب و راه‌اندازی</option>
                        <option value="خرابی دستگاه">خرابی دستگاه</option>
                        <option value="سوال فنی">سوال فنی</option>
                        <option value="شکایت">شکایت</option>
                        <option value="درخواست خدمات">درخواست خدمات</option>
                        <option value="مرجوعی کالا">مرجوعی کالا</option>
                        <option value="سایر">سایر</option>
                    </select>
                </div>
                <div>
                    <label class="fin-label">کارشناس مسئول</label>
                    <select name="assigned_to" class="fin-input">
                        <option value="0">— انتخاب کارشناس —</option>
                        <?php foreach ($agents as $ag): ?>
                        <option value="<?= (int)$ag['id'] ?>"><?= htmlspecialchars($ag['fullname'], ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:14px">
                <label class="fin-label">شرح مشکل *</label>
                <textarea name="description" class="fin-input" rows="5" placeholder="شرح کامل مشکل یا درخواست..." style="width:100%;resize:vertical" required></textarea>
            </div>
            <input type="hidden" name="action" value="save_ticket">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        </form>
    </div>
    <div class="sup-drawer-footer">
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="fin-btn" onclick="closeNewDrawer()" style="background:#f1f5f9;color:#475569;border-color:#e2e8f0">انصراف</button>
            <button class="fin-btn" onclick="submitNewTicket()" style="background:#2563eb;color:#fff;border-color:#1d4ed8">
                ✅ ثبت تیکت
            </button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>

<script>
'use strict';
var currentStatus = '';
var currentPage   = 1;
var currentTicketId = 0;
var debounceTimer = null;
var csrfToken = <?= json_encode($csrfToken) ?>;

// ---- بارگذاری اولیه ----
document.addEventListener('DOMContentLoaded', function() {
    loadStats();
    loadList(1);
});

function loadStats() {
    $.post('', { action: 'stats' }, function(res) {
        if (!res.ok) return;
        $('#statOpen').text(res.open);
        $('#statAvgReply').text(res.avg_reply);
        $('#statToday').text(res.today);
        $('#statResolvedWk').text(res.resolved_wk);
    }, 'json').fail(function() {});
}

function switchTab(btn, status) {
    $('.sup-tab').removeClass('active');
    $(btn).addClass('active');
    currentStatus = status;
    loadList(1);
}

function debounceList() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function() { loadList(1); }, 350);
}

function loadList(page) {
    currentPage = page;
    var params = {
        action:   'list',
        page:     page,
        search:   $('#fSearch').val(),
        status:   currentStatus,
        priority: $('#fPriority').val(),
    };
    $('#ticketListBody').html('<tr><td colspan="9" class="empty-state"><div class="empty-state-icon">⏳</div>در حال بارگذاری...</td></tr>');
    $.post('', params, function(res) {
        if (!res.ok) { showErrRow(); return; }

        // به‌روز کردن badge تب باز
        if (res.open_count > 0) {
            $('#openBadge').text(res.open_count).show();
        } else {
            $('#openBadge').hide();
        }

        if (!res.rows || res.rows.length === 0) {
            $('#ticketListBody').html('<tr><td colspan="9" class="empty-state"><div class="empty-state-icon">🎫</div>تیکتی یافت نشد</td></tr>');
            $('#ticketPagination').html('');
            return;
        }

        var html = '';
        res.rows.forEach(function(r) {
            html += '<tr onclick="openTicketDrawer(' + r.id + ')" style="cursor:pointer">';
            html += '<td><span style="font-family:monospace;font-weight:700;color:#1d4ed8">' + esc(r.ticket_number) + '</span></td>';
            html += '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(r.subject) + '">' + esc(r.subject) + '</td>';
            html += '<td>' + esc(r.customer_name || '—') + '</td>';
            html += '<td>' + esc(r.category || '—') + '</td>';
            html += '<td><span class="badge badge-' + r.priority_color + '">' + r.priority_label + '</span></td>';
            html += '<td><span class="badge badge-' + r.status_color + '">' + r.status_label + '</span></td>';
            html += '<td>' + esc(r.assigned_name || '—') + '</td>';
            html += '<td style="text-align:center"><span style="background:#f1f5f9;border-radius:20px;padding:2px 10px;font-size:0.76rem">' + r.reply_count + '</span></td>';
            html += '<td style="font-size:0.78rem;color:#64748b">' + r.created_at + '</td>';
            html += '</tr>';
        });
        $('#ticketListBody').html(html);

        // صفحه‌بندی
        buildPagination(res.pages, res.page);
    }, 'json').fail(showErrRow);
}

function showErrRow() {
    $('#ticketListBody').html('<tr><td colspan="9" class="empty-state" style="color:#e11d48"><div class="empty-state-icon">⚠️</div>خطا در بارگذاری</td></tr>');
}

function buildPagination(pages, current) {
    if (pages <= 1) { $('#ticketPagination').html(''); return; }
    var html = '';
    for (var i = 1; i <= pages; i++) {
        var active = i === current ? 'style="background:#2563eb;color:#fff;border-color:#1d4ed8"' : '';
        html += '<button class="fin-btn" ' + active + ' onclick="loadList(' + i + ')">' + i + '</button>';
    }
    $('#ticketPagination').html(html);
}

// ---- drawer جزئیات تیکت ----
function openTicketDrawer(id) {
    currentTicketId = id;
    $('#drawerBody').html('<div class="empty-state"><div class="empty-state-icon">⏳</div>در حال بارگذاری...</div>');
    $('#drawerFooter').hide();
    openDrawer('ticketDrawer');

    $.post('', { action: 'get_ticket', id: id }, function(res) {
        if (!res.ok) { $('#drawerBody').html('<div class="empty-state" style="color:#e11d48">خطا در بارگذاری</div>'); return; }
        var t = res.ticket;
        $('#drawerTitle').text(t.ticket_number + ' — ' + t.subject);
        $('#drawerSubtitle').text(t.customer_name || '');

        var html = '';
        // نوار تغییر وضعیت سریع
        html += '<div class="sup-status-bar">';
        var statuses = [
            {v:'open', l:'باز'},
            {v:'in_progress', l:'در حال بررسی'},
            {v:'waiting', l:'انتظار'},
            {v:'resolved', l:'حل‌شده'},
            {v:'closed', l:'بسته'},
        ];
        statuses.forEach(function(s) {
            var act = t.status === s.v ? 'active' : '';
            html += '<button class="sup-status-btn ' + act + '" onclick="quickStatus(' + id + ',\'' + s.v + '\')">' + s.l + '</button>';
        });
        html += '</div>';

        // اطلاعات کلی
        html += '<div class="sup-info-grid">';
        html += '<div class="sup-info-item"><label>وضعیت</label><span><span class="badge badge-' + t.status_color + '">' + t.status_label + '</span></span></div>';
        html += '<div class="sup-info-item"><label>اولویت</label><span><span class="badge badge-' + t.priority_color + '">' + t.priority_label + '</span></span></div>';
        html += '<div class="sup-info-item"><label>مشتری</label><span>' + esc(t.customer_name || '—') + '</span></div>';
        html += '<div class="sup-info-item"><label>دسته‌بندی</label><span>' + esc(t.category || '—') + '</span></div>';
        html += '<div class="sup-info-item"><label>کارشناس مسئول</label><span>' + esc(t.assigned_name || '—') + '</span></div>';
        html += '<div class="sup-info-item"><label>ثبت‌کننده</label><span>' + esc(t.creator_name || '—') + '</span></div>';
        html += '<div class="sup-info-item"><label>تاریخ ثبت</label><span>' + t.created_at + '</span></div>';
        html += '<div class="sup-info-item"><label>تاریخ حل</label><span>' + (t.resolved_at || '—') + '</span></div>';
        html += '</div>';

        // شرح مشکل
        html += '<div style="margin-bottom:10px;font-size:0.8rem;font-weight:700;color:#475569">شرح مشکل</div>';
        html += '<div class="sup-desc-box">' + esc(t.description || '—') + '</div>';

        // نتیجه/راه‌حل
        if (t.resolution) {
            html += '<div style="margin-bottom:10px;font-size:0.8rem;font-weight:700;color:#16a34a">راه‌حل / نتیجه</div>';
            html += '<div class="sup-desc-box" style="border-color:#bbf7d0;background:#f0fdf4">' + esc(t.resolution) + '</div>';
        }

        // پاسخ‌ها
        html += '<div style="margin-bottom:12px;font-size:0.8rem;font-weight:700;color:#475569">پاسخ‌ها (' + res.replies.length + ')</div>';
        if (res.replies.length > 0) {
            html += '<div class="sup-replies">';
            res.replies.forEach(function(r) {
                var cls = r.is_internal ? 'internal' : '';
                html += '<div class="sup-reply ' + cls + '">';
                html += '<div class="sup-reply-head">';
                html += '<span class="sup-reply-user">' + esc(r.user_name) + (r.is_internal ? ' <span style="font-size:0.72rem;color:#d97706">(داخلی)</span>' : '') + '</span>';
                html += '<span class="sup-reply-time">' + r.created_at + '</span>';
                html += '</div>';
                html += '<div class="sup-reply-msg">' + esc(r.message) + '</div>';
                html += '</div>';
            });
            html += '</div>';
        } else {
            html += '<div style="color:#94a3b8;font-size:0.82rem;margin-bottom:16px">هنوز پاسخی ثبت نشده</div>';
        }

        $('#drawerBody').html(html);
        $('#drawerFooter').show();
        // تنظیم وضعیت dropdown
        $('#changeStatusSel').val('');
    }, 'json').fail(function() {
        $('#drawerBody').html('<div class="empty-state" style="color:#e11d48">⚠️ خطا در بارگذاری</div>');
    });
}

function quickStatus(id, status) {
    $.post('', {
        action: 'change_status',
        ticket_id: id,
        status: status,
        csrf_token: csrfToken
    }, function(res) {
        if (res.ok) {
            openTicketDrawer(id);
            loadList(currentPage);
            loadStats();
        } else {
            alert(res.msg || 'خطا');
        }
    }, 'json');
}

function submitReply() {
    var msg = $('#replyMsg').val().trim();
    if (!msg) { alert('متن پاسخ خالی است'); return; }
    var isInternal = $('input[name="replyType"]:checked').val();
    var newStatus  = $('#changeStatusSel').val();

    $.post('', {
        action: 'add_reply',
        ticket_id: currentTicketId,
        message: msg,
        is_internal: isInternal,
        csrf_token: csrfToken
    }, function(res) {
        if (!res.ok) { alert(res.msg || 'خطا'); return; }
        $('#replyMsg').val('');
        if (newStatus) {
            $.post('', {
                action: 'change_status',
                ticket_id: currentTicketId,
                status: newStatus,
                csrf_token: csrfToken
            }, function() {
                openTicketDrawer(currentTicketId);
                loadList(currentPage);
                loadStats();
            }, 'json');
        } else {
            openTicketDrawer(currentTicketId);
            loadList(currentPage);
            loadStats();
        }
    }, 'json');
}

// ---- drawer تیکت جدید ----
function openNewTicketDrawer() {
    document.getElementById('newTicketForm').reset();
    openDrawer('newTicketDrawer');
}
function closeNewDrawer() { closeSpecificDrawer('newTicketDrawer'); }

function fillCustomerName(sel) {
    var opt = sel.options[sel.selectedIndex];
    var name = opt.dataset.name || '';
    document.getElementById('ntCustomerName').value = name;
}

function submitNewTicket() {
    var form = document.getElementById('newTicketForm');
    var subject = form.subject.value.trim();
    var description = form.description.value.trim();
    if (!subject) { alert('موضوع الزامی است'); return; }
    if (!description) { alert('شرح مشکل الزامی است'); return; }

    var data = $(form).serializeArray();
    data.push({ name: 'action', value: 'save_ticket' });
    data.push({ name: 'csrf_token', value: csrfToken });

    $.post('', data, function(res) {
        if (!res.ok) { alert(res.msg || 'خطا'); return; }
        closeNewDrawer();
        loadList(1);
        loadStats();
        setTimeout(function() { openTicketDrawer(res.id); }, 300);
    }, 'json').fail(function() { alert('خطا در ارتباط با سرور'); });
}

// ---- مدیریت drawer ----
function openDrawer(id) {
    document.getElementById('drawerOverlay').classList.add('active');
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDrawer() {
    document.getElementById('drawerOverlay').classList.remove('active');
    document.getElementById('ticketDrawer').classList.remove('open');
    document.body.style.overflow = '';
}
function closeSpecificDrawer(id) {
    if (id !== 'ticketDrawer') {
        document.getElementById('drawerOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }
    document.getElementById(id).classList.remove('open');
}

// ---- escape HTML ----
function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
