<?php
/*
 * فایل: public_html/admin/hr_payroll.php
 * توضیحات: حقوق و دستمزد + پورسانت پلکانی + KPI
 * جداول: hr_payroll_periods, hr_payroll_items, hr_commissions, hr_commission_plans, hr_kpi_scores
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
        $hrRoles  = ['admin','management','manager','hr_manager','hr_expert'];
        if (in_array($roleName, $hrRoles) || $roleData['is_system'] == 1) $hasAccess = true;
        else {
            $perms = json_decode($roleData['permissions'] ?? '[]', true) ?? [];
            if (in_array('hr_payroll', $perms) || in_array('all', $perms)) $hasAccess = true;
        }
    }
    if (in_array($rawRole, ['1','2'])) $hasAccess = true;
} catch (Throwable $e) { $hasAccess = true; }

if (!$hasAccess) {
    if ($isAjax) { ob_clean(); echo json_encode(['ok' => false, 'msg' => 'دسترسی غیرمجاز']); exit; }
    die('<p style="font-family:Tahoma;color:#e11d48;text-align:center;padding:60px">دسترسی غیرمجاز</p>');
}

// ---- ایجاد جداول در صورت نیاز ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `hr_commission_plans` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `name`          VARCHAR(100) NOT NULL DEFAULT 'پلن پیش‌فرض',
        `base_rate`     DECIMAL(7,4) NOT NULL DEFAULT 1.0000,
        `threshold`     BIGINT NOT NULL DEFAULT 2000000000,
        `step_size`     BIGINT NOT NULL DEFAULT 500000000,
        `step_rate`     DECIMAL(7,4) NOT NULL DEFAULT 0.1000,
        `kpi_step_rate` DECIMAL(7,4) NOT NULL DEFAULT 0.2000,
        `kpi_min_score` DECIMAL(5,2) NOT NULL DEFAULT 80.00,
        `is_active`     TINYINT NOT NULL DEFAULT 1,
        `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $cnt = $pdo->query("SELECT COUNT(*) FROM hr_commission_plans")->fetchColumn();
    if (!$cnt) {
        $pdo->exec("INSERT INTO hr_commission_plans (name) VALUES ('پلن پیش‌فرض')");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `hr_kpi_scores` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `year` SMALLINT NOT NULL,
        `quarter` TINYINT NOT NULL,
        `score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        `notes` TEXT DEFAULT NULL,
        `created_by` INT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_user_period` (`user_id`,`year`,`quarter`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `hr_commissions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `invoice_id` INT NOT NULL,
        `year` SMALLINT NOT NULL,
        `month` TINYINT NOT NULL,
        `invoice_amount` BIGINT NOT NULL DEFAULT 0,
        `extra_discount` BIGINT NOT NULL DEFAULT 0,
        `net_base` BIGINT NOT NULL DEFAULT 0,
        `cumulative_before` BIGINT NOT NULL DEFAULT 0,
        `commission_rate` DECIMAL(7,4) NOT NULL DEFAULT 0,
        `commission_amount` BIGINT NOT NULL DEFAULT 0,
        `status` ENUM('pending','payable','paid','cancelled') NOT NULL DEFAULT 'pending',
        `payroll_item_id` INT DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_invoice` (`invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `hr_payroll_periods` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `year` SMALLINT NOT NULL,
        `month` TINYINT NOT NULL,
        `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
        `closed_at` DATETIME DEFAULT NULL,
        `fin_doc_id` INT DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `created_by` INT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_year_month` (`year`,`month`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `hr_payroll_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `period_id` INT NOT NULL,
        `user_id` INT NOT NULL,
        `base_salary` BIGINT NOT NULL DEFAULT 0,
        `overtime_hours` DECIMAL(6,2) NOT NULL DEFAULT 0,
        `overtime_rate` BIGINT NOT NULL DEFAULT 0,
        `overtime_amount` BIGINT NOT NULL DEFAULT 0,
        `deductions` BIGINT NOT NULL DEFAULT 0,
        `commission_amount` BIGINT NOT NULL DEFAULT 0,
        `bonus` BIGINT NOT NULL DEFAULT 0,
        `gross_salary` BIGINT NOT NULL DEFAULT 0,
        `net_salary` BIGINT NOT NULL DEFAULT 0,
        `notes` TEXT DEFAULT NULL,
        `fin_doc_id` INT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_period_user` (`period_id`,`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// ---- تابع کمکی: سال مالی فعال ----
function getActiveFiscalYear($pdo) {
    try {
        $r = $pdo->query("SELECT id FROM fiscal_years WHERE status='active' ORDER BY id DESC LIMIT 1")->fetchColumn();
        return $r ?: 1;
    } catch (Throwable $e) { return 1; }
}

// ---- تابع کمکی: شماره سند حسابداری ----
function nextDocNumber($pdo) {
    try {
        $max = $pdo->query("SELECT COALESCE(MAX(CAST(doc_number AS UNSIGNED)),0) FROM fin_docs")->fetchColumn();
        return str_pad((int)$max + 1, 5, '0', STR_PAD_LEFT);
    } catch (Throwable $e) { return date('YmdHis'); }
}

// ---- تابع: محاسبه مجدد پورسانت یک ردیف ----
function recalcCommission($pdo, $commId) {
    $row = $pdo->prepare("SELECT * FROM hr_commissions WHERE id=?")->execute([$commId]);
    $comm = $pdo->query("SELECT * FROM hr_commissions WHERE id=$commId")->fetch(PDO::FETCH_ASSOC);
    if (!$comm) return;

    $plan = $pdo->query("SELECT * FROM hr_commission_plans WHERE is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$plan) return;

    $invYear    = $comm['year'];
    $invMonth   = $comm['month'];
    $invQuarter = (int)ceil($invMonth / 3);

    $kpiStmt = $pdo->prepare("SELECT score FROM hr_kpi_scores WHERE user_id=? AND year=? AND quarter=?");
    $kpiStmt->execute([$comm['user_id'], $invYear, $invQuarter]);
    $kpiScore = (float)($kpiStmt->fetchColumn() ?: 0);
    $stepRate = $kpiScore >= (float)$plan['kpi_min_score'] ? (float)$plan['kpi_step_rate'] : (float)$plan['step_rate'];

    $netBase         = max(0, (int)$comm['invoice_amount'] - (int)$comm['extra_discount']);
    $cumulativeBefore = (int)$comm['cumulative_before'];
    $cumulativeAfter  = $cumulativeBefore + $netBase;
    $threshold       = (int)$plan['threshold'];
    $stepSize        = (int)$plan['step_size'];
    $baseRate        = (float)$plan['base_rate'];

    if ($cumulativeAfter <= $threshold || $stepSize <= 0) {
        $commRate = $baseRate;
    } else {
        $steps    = (int)floor(($cumulativeAfter - $threshold) / $stepSize);
        $commRate = $baseRate + $steps * $stepRate;
    }

    $commAmount = (int)round($netBase * $commRate / 100);

    $pdo->prepare(
        "UPDATE hr_commissions SET net_base=?, commission_rate=?, commission_amount=?, updated_at=NOW() WHERE id=?"
    )->execute([$netBase, $commRate, $commAmount, $commId]);
}

// ═══════════════════════════════════════════════════════════════════
// AJAX
// ═══════════════════════════════════════════════════════════════════
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_REQUEST['action'] ?? '');

    try {

        // ── لیست دوره‌های حقوق ──
        if ($action === 'get_periods') {
            $rows = $pdo->query(
                "SELECT p.*, u.full_name creator_name,
                        (SELECT COUNT(*) FROM hr_payroll_items WHERE period_id=p.id) item_count,
                        (SELECT COALESCE(SUM(net_salary),0) FROM hr_payroll_items WHERE period_id=p.id) total_net
                 FROM hr_payroll_periods p
                 LEFT JOIN users u ON u.id = p.created_by
                 ORDER BY p.year DESC, p.month DESC LIMIT 36"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'rows' => $rows]);
            exit;
        }

        // ── ایجاد دوره جدید ──
        if ($action === 'create_period') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $year  = (int)($_POST['year']  ?? date('Y'));
            $month = (int)($_POST['month'] ?? date('n'));
            if ($month < 1 || $month > 12) { echo json_encode(['ok'=>false,'msg'=>'ماه نامعتبر']); exit; }
            try {
                $pdo->prepare("INSERT INTO hr_payroll_periods (year,month,created_by) VALUES (?,?,?)")
                    ->execute([$year, $month, $userId]);
                $pid = (int)$pdo->lastInsertId();
                echo json_encode(['ok'=>true,'msg'=>'دوره حقوق ایجاد شد.','period_id'=>$pid]);
            } catch (Throwable $e) {
                echo json_encode(['ok'=>false,'msg'=>'این دوره قبلاً ایجاد شده است.']);
            }
            exit;
        }

        // ── لیست ردیف‌های یک دوره ──
        if ($action === 'get_period_items') {
            $pid = (int)($_GET['period_id'] ?? 0);
            $rows = $pdo->prepare(
                "SELECT pi.*, u.full_name, u.username
                 FROM hr_payroll_items pi
                 JOIN users u ON u.id = pi.user_id
                 WHERE pi.period_id = ?
                 ORDER BY u.full_name"
            );
            $rows->execute([$pid]);
            echo json_encode(['ok'=>true,'rows'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── ذخیره/ویرایش ردیف حقوق ──
        if ($action === 'save_payroll_item') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $pid        = (int)($_POST['period_id']      ?? 0);
            $uid        = (int)($_POST['user_id']        ?? 0);
            $base       = (int)str_replace([',',' '], '', faToEn($_POST['base_salary']     ?? '0'));
            $otHours    = (float)faToEn($_POST['overtime_hours'] ?? '0');
            $otRate     = (int)str_replace([',',' '], '', faToEn($_POST['overtime_rate']   ?? '0'));
            $otAmt      = (int)round($otHours * $otRate);
            $deduct     = (int)str_replace([',',' '], '', faToEn($_POST['deductions']      ?? '0'));
            $commAmt    = (int)str_replace([',',' '], '', faToEn($_POST['commission_amount']?? '0'));
            $bonus      = (int)str_replace([',',' '], '', faToEn($_POST['bonus']           ?? '0'));
            $notes      = trim($_POST['notes'] ?? '');
            $gross      = $base + $otAmt + $commAmt + $bonus;
            $net        = max(0, $gross - $deduct);

            if (!$pid || !$uid) { echo json_encode(['ok'=>false,'msg'=>'اطلاعات ناقص']); exit; }

            // بررسی وضعیت دوره
            $pStatus = $pdo->prepare("SELECT status FROM hr_payroll_periods WHERE id=?")->execute([$pid]);
            $pStatus = $pdo->query("SELECT status FROM hr_payroll_periods WHERE id=$pid")->fetchColumn();
            if ($pStatus === 'closed') { echo json_encode(['ok'=>false,'msg'=>'دوره بسته است.']); exit; }

            $pdo->prepare(
                "INSERT INTO hr_payroll_items
                    (period_id,user_id,base_salary,overtime_hours,overtime_rate,overtime_amount,
                     deductions,commission_amount,bonus,gross_salary,net_salary,notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    base_salary=VALUES(base_salary),overtime_hours=VALUES(overtime_hours),
                    overtime_rate=VALUES(overtime_rate),overtime_amount=VALUES(overtime_amount),
                    deductions=VALUES(deductions),commission_amount=VALUES(commission_amount),
                    bonus=VALUES(bonus),gross_salary=VALUES(gross_salary),net_salary=VALUES(net_salary),
                    notes=VALUES(notes),updated_at=NOW()"
            )->execute([$pid,$uid,$base,$otHours,$otRate,$otAmt,$deduct,$commAmt,$bonus,$gross,$net,$notes]);

            echo json_encode(['ok'=>true,'msg'=>'ذخیره شد.','net'=>$net]);
            exit;
        }

        // ── تأیید و بستن دوره + سند حسابداری ──
        if ($action === 'close_period') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $pid = (int)($_POST['period_id'] ?? 0);

            $period = $pdo->query("SELECT * FROM hr_payroll_periods WHERE id=$pid")->fetch(PDO::FETCH_ASSOC);
            if (!$period) { echo json_encode(['ok'=>false,'msg'=>'دوره یافت نشد']); exit; }
            if ($period['status'] === 'closed') { echo json_encode(['ok'=>false,'msg'=>'دوره قبلاً بسته شده است.']); exit; }

            $items = $pdo->prepare("SELECT pi.*, u.full_name FROM hr_payroll_items pi JOIN users u ON u.id=pi.user_id WHERE pi.period_id=?");
            $items->execute([$pid]);
            $items = $items->fetchAll(PDO::FETCH_ASSOC);
            if (empty($items)) { echo json_encode(['ok'=>false,'msg'=>'ردیف حقوقی وجود ندارد.']); exit; }

            $totalNet = array_sum(array_column($items, 'net_salary'));
            $fyId     = getActiveFiscalYear($pdo);
            $docNum   = nextDocNumber($pdo);
            $docDate  = date('Y-m-d');
            $docDesc  = 'سند حقوق و دستمزد — ' . $period['year'] . '/' . str_pad($period['month'],2,'0',STR_PAD_LEFT);

            // کدهای حسابداری حقوق
            $accSalaryExp   = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE code='71'  AND is_deleted=0 LIMIT 1")->fetchColumn();
            $accSalaryPayable = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE code='22' AND is_deleted=0 LIMIT 1")->fetchColumn();
            // fallback
            if (!$accSalaryExp)     $accSalaryExp     = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE LOWER(name) LIKE '%حقوق%' AND is_deleted=0 LIMIT 1")->fetchColumn();
            if (!$accSalaryPayable) $accSalaryPayable = $pdo->query("SELECT id FROM fin_chart_of_accounts WHERE (LOWER(name) LIKE '%پرداختنی%' OR LOWER(name) LIKE '%دستمزد%') AND is_deleted=0 LIMIT 1")->fetchColumn();

            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO fin_docs (doc_number,doc_date,type,description,fiscal_year_id,ref_id,ref_type,created_by,created_at)
                 VALUES (?,?,'payroll',?,?,?,?,?,NOW())"
            )->execute([$docNum,$docDate,$docDesc,$fyId,$pid,'payroll',$userId]);
            $docId = (int)$pdo->lastInsertId();

            if ($accSalaryExp) {
                $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,row_order) VALUES (?,?,?,?,?,?)")
                    ->execute([$docId,$accSalaryExp,$totalNet,0,'بدهکار — هزینه حقوق و دستمزد',1]);
            }
            if ($accSalaryPayable) {
                $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,bd,bs,description,row_order) VALUES (?,?,?,?,?,?)")
                    ->execute([$docId,$accSalaryPayable,0,$totalNet,'بستانکار — حقوق و دستمزد پرداختنی',2]);
            }

            $pdo->prepare("UPDATE hr_payroll_periods SET status='closed',closed_at=NOW(),fin_doc_id=? WHERE id=?")
                ->execute([$docId,$pid]);

            // علامت پرداخت پورسانت‌های included در این دوره
            foreach ($items as $item) {
                if ($item['commission_amount'] > 0) {
                    $itemId = $item['id'];
                    $pdo->prepare(
                        "UPDATE hr_commissions SET status='paid',payroll_item_id=?,updated_at=NOW()
                         WHERE user_id=? AND year=? AND month=? AND status='payable'"
                    )->execute([$itemId, $item['user_id'], $period['year'], $period['month']]);
                }
            }

            $pdo->commit();
            echo json_encode(['ok'=>true,'msg'=>'دوره بسته شد. سند حسابداری ثبت گردید.','doc_id'=>$docId]);
            exit;
        }

        // ── لیست پورسانت‌ها ──
        if ($action === 'get_commissions') {
            $year   = (int)($_GET['year']   ?? date('Y'));
            $month  = (int)($_GET['month']  ?? 0);
            $uid    = (int)($_GET['user_id']?? 0);
            $status = trim($_GET['status']  ?? '');

            $where = ["c.year = $year"];
            if ($month)  $where[] = "c.month = $month";
            if ($uid)    $where[] = "c.user_id = $uid";
            if ($status) $where[] = "c.status = " . $pdo->quote($status);

            $sql = "SELECT c.*, u.full_name, i.invoice_number, i.total_amount inv_total
                    FROM hr_commissions c
                    JOIN users u ON u.id=c.user_id
                    LEFT JOIN fin_invoices i ON i.id=c.invoice_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY c.year DESC, c.month DESC, c.id DESC LIMIT 200";
            echo json_encode(['ok'=>true,'rows'=>$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── ویرایش تخفیف اضافی پورسانت ──
        if ($action === 'update_commission_discount') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $cid      = (int)($_POST['id']             ?? 0);
            $discount = (int)str_replace([',',' '], '', faToEn($_POST['extra_discount'] ?? '0'));
            $notes    = trim($_POST['notes'] ?? '');

            $chk = $pdo->prepare("SELECT status FROM hr_commissions WHERE id=?");
            $chk->execute([$cid]);
            $st = $chk->fetchColumn();
            if ($st === 'paid') { echo json_encode(['ok'=>false,'msg'=>'پورسانت پرداخت‌شده قابل ویرایش نیست.']); exit; }

            $pdo->prepare("UPDATE hr_commissions SET extra_discount=?,notes=?,updated_at=NOW() WHERE id=?")
                ->execute([$discount,$notes,$cid]);
            recalcCommission($pdo, $cid);
            echo json_encode(['ok'=>true,'msg'=>'تخفیف اضافی ثبت و پورسانت محاسبه مجدد شد.']);
            exit;
        }

        // ── خلاصه پورسانت یک کارمند در یک ماه ──
        if ($action === 'get_commission_summary') {
            $uid   = (int)($_GET['user_id'] ?? 0);
            $year  = (int)($_GET['year']  ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('n'));
            $rows  = $pdo->prepare(
                "SELECT c.*, i.invoice_number, i.invoice_date
                 FROM hr_commissions c
                 LEFT JOIN fin_invoices i ON i.id=c.invoice_id
                 WHERE c.user_id=? AND c.year=? AND c.month=?
                 ORDER BY c.id"
            );
            $rows->execute([$uid,$year,$month]);
            $data = $rows->fetchAll(PDO::FETCH_ASSOC);
            $total = array_sum(array_column(array_filter($data, fn($r) => $r['status']!=='cancelled'), 'commission_amount'));
            echo json_encode(['ok'=>true,'rows'=>$data,'total_payable'=>$total]);
            exit;
        }

        // ── لیست نمرات KPI ──
        if ($action === 'get_kpi_scores') {
            $year = (int)($_GET['year'] ?? date('Y'));
            $rows = $pdo->prepare(
                "SELECT k.*, u.full_name
                 FROM hr_kpi_scores k
                 JOIN users u ON u.id=k.user_id
                 WHERE k.year=?
                 ORDER BY k.quarter, u.full_name"
            );
            $rows->execute([$year]);
            echo json_encode(['ok'=>true,'rows'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── ثبت/ویرایش نمره KPI ──
        if ($action === 'save_kpi_score') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $uid     = (int)($_POST['user_id']  ?? 0);
            $year    = (int)($_POST['year']     ?? date('Y'));
            $quarter = (int)($_POST['quarter']  ?? 0);
            $score   = min(100, max(0, (float)faToEn($_POST['score'] ?? '0')));
            $notes   = trim($_POST['notes'] ?? '');

            if (!$uid || $quarter < 1 || $quarter > 4) { echo json_encode(['ok'=>false,'msg'=>'اطلاعات ناقص']); exit; }

            $pdo->prepare(
                "INSERT INTO hr_kpi_scores (user_id,year,quarter,score,notes,created_by)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE score=VALUES(score),notes=VALUES(notes),updated_at=NOW()"
            )->execute([$uid,$year,$quarter,$score,$notes,$userId]);

            echo json_encode(['ok'=>true,'msg'=>'نمره KPI ثبت شد.']);
            exit;
        }

        // ── دریافت پلن پورسانت ──
        if ($action === 'get_plan') {
            $plan = $pdo->query("SELECT * FROM hr_commission_plans WHERE is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'plan'=>$plan]);
            exit;
        }

        // ── ذخیره پلن پورسانت ──
        if ($action === 'save_plan') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $pid       = (int)($_POST['plan_id']     ?? 0);
            $name      = trim($_POST['name']         ?? 'پلن پیش‌فرض');
            $baseRate  = (float)faToEn($_POST['base_rate']     ?? '1');
            $threshold = (int)str_replace([',',' '], '', faToEn($_POST['threshold']  ?? '2000000000'));
            $stepSize  = (int)str_replace([',',' '], '', faToEn($_POST['step_size']  ?? '500000000'));
            $stepRate  = (float)faToEn($_POST['step_rate']     ?? '0.1');
            $kpiRate   = (float)faToEn($_POST['kpi_step_rate'] ?? '0.2');
            $kpiMin    = (float)faToEn($_POST['kpi_min_score'] ?? '80');

            if ($pid) {
                $pdo->prepare(
                    "UPDATE hr_commission_plans SET name=?,base_rate=?,threshold=?,step_size=?,step_rate=?,kpi_step_rate=?,kpi_min_score=? WHERE id=?"
                )->execute([$name,$baseRate,$threshold,$stepSize,$stepRate,$kpiRate,$kpiMin,$pid]);
            } else {
                $pdo->exec("UPDATE hr_commission_plans SET is_active=0");
                $pdo->prepare(
                    "INSERT INTO hr_commission_plans (name,base_rate,threshold,step_size,step_rate,kpi_step_rate,kpi_min_score,is_active) VALUES (?,?,?,?,?,?,?,1)"
                )->execute([$name,$baseRate,$threshold,$stepSize,$stepRate,$kpiRate,$kpiMin]);
            }
            echo json_encode(['ok'=>true,'msg'=>'پلن پورسانت ذخیره شد.']);
            exit;
        }

        // ── لیست کارمندان ──
        if ($action === 'get_users') {
            $rows = $pdo->query(
                "SELECT id, full_name, username FROM users WHERE is_deleted=0 ORDER BY full_name"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'rows'=>$rows]);
            exit;
        }

        // ── اضافه‌کردن همه کارمندان به یک دوره ──
        if ($action === 'add_all_users_to_period') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'خطای امنیتی']); exit; }
            $pid = (int)($_POST['period_id'] ?? 0);
            $pStatus = $pdo->query("SELECT status FROM hr_payroll_periods WHERE id=$pid")->fetchColumn();
            if ($pStatus === 'closed') { echo json_encode(['ok'=>false,'msg'=>'دوره بسته است.']); exit; }

            $users = $pdo->query("SELECT id FROM users WHERE is_deleted=0")->fetchAll(PDO::FETCH_COLUMN);
            $cnt = 0;
            foreach ($users as $uid) {
                try {
                    $pdo->prepare("INSERT IGNORE INTO hr_payroll_items (period_id,user_id) VALUES (?,?)")->execute([$pid,$uid]);
                    $cnt++;
                } catch (Throwable $e) {}
            }
            echo json_encode(['ok'=>true,'msg'=>"$cnt کارمند اضافه شدند."]);
            exit;
        }

        // ── خلاصه آماری داشبورد ──
        if ($action === 'get_dashboard_stats') {
            $year  = (int)date('Y');
            $month = (int)date('n');
            $stats = [
                'pending_commissions'  => (int)$pdo->query("SELECT COALESCE(SUM(commission_amount),0) FROM hr_commissions WHERE status='pending'")->fetchColumn(),
                'payable_commissions'  => (int)$pdo->query("SELECT COALESCE(SUM(commission_amount),0) FROM hr_commissions WHERE status='payable'")->fetchColumn(),
                'open_periods'         => (int)$pdo->query("SELECT COUNT(*) FROM hr_payroll_periods WHERE status='open'")->fetchColumn(),
                'this_month_total_net' => (int)$pdo->query("SELECT COALESCE(SUM(pi.net_salary),0) FROM hr_payroll_items pi JOIN hr_payroll_periods pp ON pp.id=pi.period_id WHERE pp.year=$year AND pp.month=$month")->fetchColumn(),
            ];
            echo json_encode(['ok'=>true,'stats'=>$stats]);
            exit;
        }

        echo json_encode(['ok'=>false,'msg'=>'درخواست نامعتبر']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'خطای سرور: '.$e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════
// HTML
// ═══════════════════════════════════════════════════════════════════
$basePath = '../../';
$pageTitle = 'حقوق و دستمزد';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';

// شماره سال جاری شمسی
$jNow = explode('/', jdate('Y/n/j'));
$jYear  = (int)$jNow[0];
$jMonth = (int)$jNow[1];
?>
<link rel="stylesheet" href="<?= $basePath ?>public_html/assets/css/fin_module.css">
<style>
.pay-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:1.5rem}
.pay-tab{padding:.6rem 1.2rem;cursor:pointer;font-size:.9rem;border-bottom:2px solid transparent;margin-bottom:-2px;color:#64748b;transition:.2s}
.pay-tab.active{color:#7c3aed;border-bottom-color:#7c3aed;font-weight:600}
.pay-tab:hover:not(.active){color:#374151}
.pay-section{display:none}.pay-section.active{display:block}
.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
.stat-card{background:#fff;border-radius:.75rem;padding:1rem 1.2rem;border:1px solid #e2e8f0;text-align:center}
.stat-card .val{font-size:1.5rem;font-weight:700;color:#7c3aed}
.stat-card .lbl{font-size:.8rem;color:#64748b;margin-top:.25rem}
.period-card{background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;padding:1rem 1.2rem;margin-bottom:.75rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.period-card.closed{border-color:#a7f3d0;background:#f0fdf4}
.period-card.open{border-color:#ddd6fe}
.fin-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;z-index:9999;display:none}
.fin-modal-box{background:#fff;border-radius:1rem;padding:1.5rem;width:min(96vw,640px);max-height:90vh;overflow-y:auto;position:relative}
.fin-modal-box h3{margin:0 0 1rem;font-size:1.1rem;color:#1e293b}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.85rem;color:#374151;margin-bottom:.3rem;font-weight:500}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;box-sizing:border-box}
.form-group textarea{min-height:60px;resize:vertical}
.btn-row{display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem}
table.data-tbl{width:100%;border-collapse:collapse}
table.data-tbl th{background:#f8fafc;padding:.6rem .75rem;font-size:.8rem;color:#64748b;border-bottom:2px solid #e2e8f0;text-align:right}
table.data-tbl td{padding:.6rem .75rem;font-size:.85rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
table.data-tbl tr:hover td{background:#fafafa}
.badge{display:inline-block;padding:.2em .6em;border-radius:.4rem;font-size:.75rem;font-weight:600}
.badge-pending{background:#fef9c3;color:#854d0e}
.badge-payable{background:#dbeafe;color:#1e40af}
.badge-paid{background:#dcfce7;color:#166534}
.badge-cancelled{background:#f1f5f9;color:#94a3b8}
.badge-open{background:#ede9fe;color:#5b21b6}
.badge-closed{background:#dcfce7;color:#166534}
</style>

<div class="fin-layout" style="direction:rtl">
  <?php require_once __DIR__ . '/../../templates/sidebar.php'; ?>
  <div class="fin-main">
    <div class="fin-topbar">
      <h1 class="fin-page-title">💼 حقوق و دستمزد</h1>
    </div>

    <!-- کارت‌های آماری -->
    <div class="stat-row" id="dashStats">
      <div class="stat-card"><div class="val" id="sPending">—</div><div class="lbl">پورسانت در انتظار</div></div>
      <div class="stat-card"><div class="val" id="sPayable">—</div><div class="lbl">پورسانت قابل پرداخت</div></div>
      <div class="stat-card"><div class="val" id="sOpenPeriods">—</div><div class="lbl">دوره‌های باز</div></div>
      <div class="stat-card"><div class="val" id="sMonthNet">—</div><div class="lbl">خالص حقوق ماه جاری</div></div>
    </div>

    <!-- تب‌ها -->
    <div class="pay-tabs">
      <div class="pay-tab active" onclick="switchPayTab('payroll')">📋 حقوق</div>
      <div class="pay-tab" onclick="switchPayTab('commission')">💰 پورسانت</div>
      <div class="pay-tab" onclick="switchPayTab('kpi')">🎯 KPI</div>
      <div class="pay-tab" onclick="switchPayTab('plan')">⚙️ پلن پورسانت</div>
    </div>

    <!-- ═══ بخش حقوق ═══ -->
    <div class="pay-section active" id="sec-payroll">
      <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
        <button class="fin-btn fin-btn-primary" onclick="openCreatePeriod()">➕ دوره جدید</button>
        <span style="color:#64748b;font-size:.85rem">دوره‌های حقوقی موجود:</span>
      </div>
      <div id="periodsList">در حال بارگذاری...</div>
    </div>

    <!-- ═══ بخش پورسانت ═══ -->
    <div class="pay-section" id="sec-commission">
      <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
        <select id="commYear"  class="fin-select" style="width:100px" onchange="loadCommissions()">
          <?php for ($y=$jYear;$y>=$jYear-2;$y--) echo "<option value='$y'>$y</option>"; ?>
        </select>
        <select id="commMonth" class="fin-select" style="width:80px" onchange="loadCommissions()">
          <option value="">همه</option>
          <?php for ($m=1;$m<=12;$m++) echo "<option value='$m'>$m</option>"; ?>
        </select>
        <select id="commUser"  class="fin-select" style="width:160px" onchange="loadCommissions()">
          <option value="">همه کارمندان</option>
        </select>
        <select id="commStatus" class="fin-select" style="width:120px" onchange="loadCommissions()">
          <option value="">همه وضعیت‌ها</option>
          <option value="pending">در انتظار</option>
          <option value="payable">قابل پرداخت</option>
          <option value="paid">پرداخت‌شده</option>
          <option value="cancelled">لغو</option>
        </select>
      </div>
      <div id="commTableWrap" style="overflow-x:auto">
        <table class="data-tbl fin-table">
          <thead><tr>
            <th>کارمند</th><th>فاکتور</th><th>ماه</th>
            <th>مبلغ فاکتور</th><th>تخفیف اضافی</th><th>مبنا</th>
            <th>نرخ %</th><th>پورسانت</th><th>وضعیت</th><th>عملیات</th>
          </tr></thead>
          <tbody id="commRows"><tr><td colspan="10" style="text-align:center;padding:2rem;color:#94a3b8">داده‌ای بارگذاری نشده</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ═══ بخش KPI ═══ -->
    <div class="pay-section" id="sec-kpi">
      <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
        <select id="kpiYear" class="fin-select" style="width:100px" onchange="loadKpi()">
          <?php for ($y=$jYear;$y>=$jYear-2;$y--) echo "<option value='$y'>$y</option>"; ?>
        </select>
        <button class="fin-btn fin-btn-secondary" onclick="openAddKpi()">➕ ثبت نمره</button>
      </div>
      <div id="kpiTableWrap" style="overflow-x:auto">
        <table class="data-tbl fin-table">
          <thead><tr><th>کارمند</th><th>سال</th><th>فصل</th><th>نمره</th><th>یادداشت</th><th>عملیات</th></tr></thead>
          <tbody id="kpiRows"><tr><td colspan="6" style="text-align:center;padding:2rem;color:#94a3b8">داده‌ای بارگذاری نشده</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ═══ بخش پلن پورسانت ═══ -->
    <div class="pay-section" id="sec-plan">
      <div class="fin-panel" style="max-width:560px">
        <h3 style="margin:0 0 1.25rem;font-size:1rem">⚙️ تنظیمات پلن پورسانت</h3>
        <form id="planForm" onsubmit="savePlan(event)">
          <input type="hidden" id="planId" value="">
          <div class="form-group">
            <label>نام پلن</label>
            <input type="text" id="planName" value="پلن پیش‌فرض">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <div class="form-group">
              <label>نرخ پایه (%)</label>
              <input type="number" id="planBaseRate" step="0.01" value="1.00">
            </div>
            <div class="form-group">
              <label>آستانه پلکان (ریال)</label>
              <input type="text" id="planThreshold" value="2,000,000,000">
            </div>
            <div class="form-group">
              <label>اندازه هر پله (ریال)</label>
              <input type="text" id="planStepSize" value="500,000,000">
            </div>
            <div class="form-group">
              <label>افزایش نرخ هر پله (%)</label>
              <input type="number" id="planStepRate" step="0.01" value="0.10">
            </div>
            <div class="form-group">
              <label>افزایش نرخ با KPI بالا (%)</label>
              <input type="number" id="planKpiStepRate" step="0.01" value="0.20">
            </div>
            <div class="form-group">
              <label>حداقل نمره KPI</label>
              <input type="number" id="planKpiMin" step="0.01" value="80.00">
            </div>
          </div>
          <div class="btn-row">
            <button type="submit" class="fin-btn fin-btn-primary">💾 ذخیره پلن</button>
          </div>
        </form>
        <div id="planDesc" style="margin-top:1.5rem;padding:1rem;background:#f8fafc;border-radius:.5rem;font-size:.85rem;color:#374151;line-height:1.8">
          <strong>توضیح پلن:</strong><br>
          نرخ پایه پورسانت <strong id="desc_base">۱٪</strong> از خالص فاکتور است.<br>
          پس از رسیدن فروش ماهانه به آستانه <strong id="desc_thresh">۲ میلیارد</strong>، به ازای هر <strong id="desc_step">۵۰۰ میلیون</strong> فروش اضافی، <strong id="desc_rate">۰.۱٪</strong> به نرخ افزوده می‌شود.<br>
          در صورت کسب نمره KPI بالاتر از <strong id="desc_kpi_min">۸۰</strong> در فصل، این افزایش به <strong id="desc_kpi_rate">۰.۲٪</strong> ارتقا می‌یابد.<br>
          پورسانت پس از <em>تسویه کامل</em> فاکتور قابل پرداخت می‌شود.
        </div>
      </div>
    </div>

  </div><!-- /fin-main -->
</div><!-- /fin-layout -->

<!-- مودال: ایجاد دوره -->
<div class="fin-modal-overlay" id="createPeriodModal">
  <div class="fin-modal-box">
    <h3>➕ دوره حقوقی جدید</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="form-group">
        <label>سال شمسی</label>
        <input type="number" id="newPYear" value="<?= $jYear ?>">
      </div>
      <div class="form-group">
        <label>ماه (۱–۱۲)</label>
        <input type="number" id="newPMonth" min="1" max="12" value="<?= $jMonth ?>">
      </div>
    </div>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('createPeriodModal')">لغو</button>
      <button class="fin-btn fin-btn-primary" onclick="doCreatePeriod()">ایجاد دوره</button>
    </div>
  </div>
</div>

<!-- مودال: ردیف‌های حقوق یک دوره -->
<div class="fin-modal-overlay" id="periodItemsModal">
  <div class="fin-modal-box" style="width:min(96vw,900px)">
    <h3 id="piModalTitle">ردیف‌های حقوق</h3>
    <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
      <button class="fin-btn fin-btn-secondary" onclick="addAllUsers()">👥 اضافه همه کارمندان</button>
      <button class="fin-btn fin-btn-secondary" onclick="openAddPayrollItem()">➕ اضافه کارمند</button>
      <button class="fin-btn fin-btn-primary" onclick="closePeriodConfirm()" id="closePeriodBtn">🔒 بستن دوره</button>
    </div>
    <div style="overflow-x:auto">
      <table class="data-tbl fin-table" id="piTable">
        <thead><tr>
          <th>کارمند</th><th>حقوق پایه</th><th>اضافه‌کار</th><th>پورسانت</th><th>پاداش</th><th>کسورات</th><th>خالص</th><th>عملیات</th>
        </tr></thead>
        <tbody id="piRows"></tbody>
        <tfoot><tr>
          <td colspan="5" style="text-align:left;font-weight:600;font-size:.85rem">جمع کل خالص:</td>
          <td colspan="3" id="piTotal" style="font-weight:700;color:#7c3aed"></td>
        </tr></tfoot>
      </table>
    </div>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('periodItemsModal')">بستن</button>
    </div>
  </div>
</div>

<!-- مودال: افزودن/ویرایش ردیف حقوق -->
<div class="fin-modal-overlay" id="payrollItemModal">
  <div class="fin-modal-box">
    <h3 id="piItemTitle">ردیف حقوق</h3>
    <input type="hidden" id="piPeriodId">
    <div class="form-group">
      <label>کارمند</label>
      <select id="piUserId" class="fin-select" style="width:100%"></select>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="form-group">
        <label>حقوق پایه (ریال)</label>
        <input type="text" id="piBase" value="0" oninput="calcPayroll()">
      </div>
      <div class="form-group">
        <label>ساعت اضافه‌کار</label>
        <input type="number" id="piOtHours" value="0" step="0.5" oninput="calcPayroll()">
      </div>
      <div class="form-group">
        <label>نرخ ساعتی اضافه‌کار (ریال)</label>
        <input type="text" id="piOtRate" value="0" oninput="calcPayroll()">
      </div>
      <div class="form-group">
        <label>پورسانت (ریال) <small style="color:#94a3b8">از ماژول پورسانت</small></label>
        <input type="text" id="piComm" value="0" oninput="calcPayroll()">
      </div>
      <div class="form-group">
        <label>پاداش (ریال)</label>
        <input type="text" id="piBonus" value="0" oninput="calcPayroll()">
      </div>
      <div class="form-group">
        <label>کسورات (ریال)</label>
        <input type="text" id="piDeduct" value="0" oninput="calcPayroll()">
      </div>
    </div>
    <div style="background:#f8fafc;border-radius:.5rem;padding:.75rem;margin-bottom:1rem">
      <strong>خالص پرداختی: </strong><span id="piNetCalc" style="color:#7c3aed;font-weight:700">۰</span> ریال
    </div>
    <div class="form-group">
      <label>یادداشت</label>
      <textarea id="piNotes"></textarea>
    </div>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('payrollItemModal')">لغو</button>
      <button class="fin-btn fin-btn-primary" onclick="savePayrollItem()">💾 ذخیره</button>
    </div>
  </div>
</div>

<!-- مودال: ویرایش تخفیف اضافی پورسانت -->
<div class="fin-modal-overlay" id="commDiscountModal">
  <div class="fin-modal-box" style="width:min(96vw,420px)">
    <h3>ویرایش تخفیف اضافی</h3>
    <p style="font-size:.85rem;color:#64748b">تخفیفی که به پزشک، دستیار یا سیستم خرید بیمارستان داده شده و باید از مبنای پورسانت کسر شود.</p>
    <input type="hidden" id="cdCommId">
    <div class="form-group">
      <label>تخفیف اضافی (ریال)</label>
      <input type="text" id="cdDiscount" value="0">
    </div>
    <div class="form-group">
      <label>یادداشت</label>
      <textarea id="cdNotes"></textarea>
    </div>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('commDiscountModal')">لغو</button>
      <button class="fin-btn fin-btn-primary" onclick="saveCommDiscount()">💾 ذخیره</button>
    </div>
  </div>
</div>

<!-- مودال: ثبت نمره KPI -->
<div class="fin-modal-overlay" id="kpiModal">
  <div class="fin-modal-box" style="width:min(96vw,420px)">
    <h3 id="kpiModalTitle">ثبت نمره KPI</h3>
    <div class="form-group">
      <label>کارمند</label>
      <select id="kpiUserId" class="fin-select" style="width:100%"></select>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
      <div class="form-group">
        <label>سال</label>
        <input type="number" id="kpiScoreYear" value="<?= $jYear ?>">
      </div>
      <div class="form-group">
        <label>فصل (۱-۴)</label>
        <select id="kpiQuarter" class="fin-select">
          <option value="1">فصل ۱</option>
          <option value="2">فصل ۲</option>
          <option value="3">فصل ۳</option>
          <option value="4">فصل ۴</option>
        </select>
      </div>
      <div class="form-group">
        <label>نمره (۰-۱۰۰)</label>
        <input type="number" id="kpiScore" min="0" max="100" step="0.01" value="0">
      </div>
    </div>
    <div class="form-group">
      <label>یادداشت</label>
      <textarea id="kpiNotes"></textarea>
    </div>
    <div class="btn-row">
      <button class="fin-btn fin-btn-secondary" onclick="closeModal('kpiModal')">لغو</button>
      <button class="fin-btn fin-btn-primary" onclick="saveKpiScore()">💾 ثبت نمره</button>
    </div>
  </div>
</div>

<script>
var csrf = '<?= htmlspecialchars(csrf_token()) ?>';
var allUsers = [];
var activePeriodId = null;
var activePeriodStatus = 'open';

function fmt(n){return parseInt(n||0).toLocaleString('fa-IR');}
function fmtN(n){return parseFloat(n||0).toLocaleString('fa-IR');}

function showToast(msg,type='success'){
    var t=document.createElement('div');
    t.textContent=msg;
    t.style.cssText='position:fixed;bottom:1.5rem;right:1.5rem;padding:.75rem 1.25rem;border-radius:.5rem;z-index:99999;font-size:.9rem;color:#fff;background:'+(type==='success'?'#10b981':'#ef4444');
    document.body.appendChild(t);
    setTimeout(()=>t.remove(),3500);
}

function switchPayTab(tab) {
    document.querySelectorAll('.pay-tab').forEach((el,i) => {
        var tabs = ['payroll','commission','kpi','plan'];
        el.classList.toggle('active', tabs[i]===tab);
    });
    document.querySelectorAll('.pay-section').forEach(el => el.classList.remove('active'));
    document.getElementById('sec-'+tab).classList.add('active');
    if (tab==='commission') { populateUserSelects(); loadCommissions(); }
    if (tab==='kpi')        { populateUserSelects(); loadKpi(); }
    if (tab==='plan')       { loadPlan(); }
}

function openModal(id)  { document.getElementById(id).style.display='flex'; }
function closeModal(id) { document.getElementById(id).style.display='none'; }

// ── داشبورد آماری ──
function loadDashStats(){
    fetch('hr_payroll.php?action=get_dashboard_stats',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.ok)return;
        document.getElementById('sPending').textContent=fmt(d.stats.pending_commissions);
        document.getElementById('sPayable').textContent=fmt(d.stats.payable_commissions);
        document.getElementById('sOpenPeriods').textContent=d.stats.open_periods;
        document.getElementById('sMonthNet').textContent=fmt(d.stats.this_month_total_net);
    });
}

// ── لیست کارمندان ──
function loadUsers(){
    return fetch('hr_payroll.php?action=get_users',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.ok) allUsers=d.rows;
    });
}

function populateUserSelects(){
    var selects=['piUserId','commUser','kpiUserId'];
    selects.forEach(sid=>{
        var el=document.getElementById(sid);
        if(!el)return;
        var cur=el.value;
        while(el.options.length>(sid==='commUser'?1:0)) el.remove(sid==='commUser'?1:el.options.length-1);
        allUsers.forEach(u=>{
            var o=document.createElement('option');
            o.value=u.id; o.textContent=u.full_name||u.username;
            el.appendChild(o);
        });
        if(cur) el.value=cur;
    });
}

// ══ بخش حقوق ══
function loadPeriods(){
    fetch('hr_payroll.php?action=get_periods',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.ok){document.getElementById('periodsList').innerHTML='<p style="color:#e11d48">خطا در بارگذاری</p>';return;}
        var monthNames=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        if(!d.rows.length){document.getElementById('periodsList').innerHTML='<p style="color:#64748b;text-align:center;padding:2rem">دوره‌ای ایجاد نشده. روی «دوره جدید» کلیک کنید.</p>';return;}
        var h='';
        d.rows.forEach(function(p){
            var mName=monthNames[(p.month-1)%12]||p.month;
            h+='<div class="period-card '+(p.status==='closed'?'closed':'open')+'">';
            h+='<div>';
            h+='<strong>'+p.year+' — '+mName+'</strong> ';
            h+='<span class="badge badge-'+p.status+'">'+(p.status==='closed'?'بسته':'باز')+'</span>';
            h+='<div style="font-size:.8rem;color:#64748b;margin-top:.25rem">'+p.item_count+' نفر | خالص: '+fmt(p.total_net)+' ریال</div>';
            h+='</div>';
            h+='<div style="display:flex;gap:.5rem">';
            h+='<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="openPeriodItems('+p.id+',\''+p.year+'/'+mName+'\',\''+p.status+'\')">📋 مشاهده</button>';
            h+='</div>';
            h+='</div>';
        });
        document.getElementById('periodsList').innerHTML=h;
    });
}

function openCreatePeriod(){ openModal('createPeriodModal'); }

function doCreatePeriod(){
    var y=document.getElementById('newPYear').value;
    var m=document.getElementById('newPMonth').value;
    var fd=new FormData();
    fd.append('action','create_period');fd.append('csrf_token',csrf);
    fd.append('year',y);fd.append('month',m);
    fetch('hr_payroll.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){closeModal('createPeriodModal');loadPeriods();loadDashStats();showToast(d.msg);}
        else showToast(d.msg,'error');
    });
}

function openPeriodItems(pid, title, status){
    activePeriodId=pid; activePeriodStatus=status;
    document.getElementById('piModalTitle').textContent='📋 دوره: '+title;
    document.getElementById('closePeriodBtn').style.display=status==='closed'?'none':'inline-flex';
    loadPeriodItems(pid);
    openModal('periodItemsModal');
}

function loadPeriodItems(pid){
    fetch('hr_payroll.php?action=get_period_items&period_id='+pid,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.ok)return;
        var h=''; var tot=0;
        d.rows.forEach(function(row){
            tot+=parseInt(row.net_salary||0);
            h+='<tr>';
            h+='<td>'+(row.full_name||row.username)+'</td>';
            h+='<td>'+fmt(row.base_salary)+'</td>';
            h+='<td>'+fmt(row.overtime_amount)+'</td>';
            h+='<td>'+fmt(row.commission_amount)+'</td>';
            h+='<td>'+fmt(row.bonus)+'</td>';
            h+='<td>'+fmt(row.deductions)+'</td>';
            h+='<td style="font-weight:700;color:#7c3aed">'+fmt(row.net_salary)+'</td>';
            h+='<td>';
            if(activePeriodStatus!=='closed') h+='<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="editPayrollItem('+JSON.stringify(row)+')">✏️</button>';
            h+='</td>';
            h+='</tr>';
        });
        if(!d.rows.length) h='<tr><td colspan="8" style="text-align:center;padding:1.5rem;color:#94a3b8">ردیفی وجود ندارد</td></tr>';
        document.getElementById('piRows').innerHTML=h;
        document.getElementById('piTotal').textContent=fmt(tot)+' ریال';
    });
}

function addAllUsers(){
    if(!confirm('آیا همه کارمندان به این دوره اضافه شوند؟')) return;
    var fd=new FormData();
    fd.append('action','add_all_users_to_period');fd.append('csrf_token',csrf);
    fd.append('period_id',activePeriodId);
    fetch('hr_payroll.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){loadPeriodItems(activePeriodId);showToast(d.msg);}
        else showToast(d.msg,'error');
    });
}

function openAddPayrollItem(){
    populateUserSelects();
    document.getElementById('piItemTitle').textContent='➕ افزودن ردیف حقوق';
    document.getElementById('piPeriodId').value=activePeriodId;
    ['piBase','piOtRate','piComm','piBonus','piDeduct'].forEach(id=>document.getElementById(id).value='0');
    document.getElementById('piOtHours').value='0';
    document.getElementById('piNotes').value='';
    calcPayroll();
    openModal('payrollItemModal');
}

function editPayrollItem(row){
    populateUserSelects();
    document.getElementById('piItemTitle').textContent='✏️ ویرایش ردیف حقوق';
    document.getElementById('piPeriodId').value=activePeriodId;
    document.getElementById('piUserId').value=row.user_id;
    document.getElementById('piBase').value=row.base_salary||0;
    document.getElementById('piOtHours').value=row.overtime_hours||0;
    document.getElementById('piOtRate').value=row.overtime_rate||0;
    document.getElementById('piComm').value=row.commission_amount||0;
    document.getElementById('piBonus').value=row.bonus||0;
    document.getElementById('piDeduct').value=row.deductions||0;
    document.getElementById('piNotes').value=row.notes||'';
    calcPayroll();
    openModal('payrollItemModal');
}

function calcPayroll(){
    var base   =parseInt(document.getElementById('piBase').value.replace(/,/g,'')||0);
    var otH    =parseFloat(document.getElementById('piOtHours').value||0);
    var otR    =parseInt(document.getElementById('piOtRate').value.replace(/,/g,'')||0);
    var comm   =parseInt(document.getElementById('piComm').value.replace(/,/g,'')||0);
    var bonus  =parseInt(document.getElementById('piBonus').value.replace(/,/g,'')||0);
    var deduct =parseInt(document.getElementById('piDeduct').value.replace(/,/g,'')||0);
    var net    =Math.max(0,base+Math.round(otH*otR)+comm+bonus-deduct);
    document.getElementById('piNetCalc').textContent=net.toLocaleString('fa-IR');
}

function savePayrollItem(){
    var fd=new FormData();
    fd.append('action','save_payroll_item');fd.append('csrf_token',csrf);
    fd.append('period_id',document.getElementById('piPeriodId').value);
    fd.append('user_id',document.getElementById('piUserId').value);
    fd.append('base_salary',document.getElementById('piBase').value.replace(/,/g,''));
    fd.append('overtime_hours',document.getElementById('piOtHours').value);
    fd.append('overtime_rate',document.getElementById('piOtRate').value.replace(/,/g,''));
    fd.append('commission_amount',document.getElementById('piComm').value.replace(/,/g,''));
    fd.append('bonus',document.getElementById('piBonus').value.replace(/,/g,''));
    fd.append('deductions',document.getElementById('piDeduct').value.replace(/,/g,''));
    fd.append('notes',document.getElementById('piNotes').value);
    fetch('hr_payroll.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){closeModal('payrollItemModal');loadPeriodItems(activePeriodId);showToast(d.msg);}
        else showToast(d.msg,'error');
    });
}

function closePeriodConfirm(){
    if(!confirm('آیا از بستن این دوره و ثبت سند حسابداری مطمئن هستید؟ این عملیات غیرقابل بازگشت است.')) return;
    var fd=new FormData();
    fd.append('action','close_period');fd.append('csrf_token',csrf);
    fd.append('period_id',activePeriodId);
    fetch('hr_payroll.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){
            closeModal('periodItemsModal');loadPeriods();loadDashStats();
            showToast(d.msg);
        } else showToast(d.msg,'error');
    });
}

// ══ بخش پورسانت ══
function loadCommissions(){
    var y=document.getElementById('commYear').value;
    var m=document.getElementById('commMonth').value;
    var u=document.getElementById('commUser').value;
    var s=document.getElementById('commStatus').value;
    var url='hr_payroll.php?action=get_commissions&year='+y+(m?'&month='+m:'')+(u?'&user_id='+u:'')+(s?'&status='+s:'');
    fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.ok)return;
        var h='';
        var statusMap={pending:'در انتظار',payable:'قابل پرداخت',paid:'پرداخت‌شده',cancelled:'لغو'};
        d.rows.forEach(function(row){
            h+='<tr>';
            h+='<td>'+(row.full_name||'—')+'</td>';
            h+='<td>'+(row.invoice_number||'—')+'</td>';
            h+='<td>'+row.year+'/'+row.month+'</td>';
            h+='<td>'+fmt(row.invoice_amount)+'</td>';
            h+='<td>'+fmt(row.extra_discount)+'</td>';
            h+='<td>'+fmt(row.net_base)+'</td>';
            h+='<td>'+parseFloat(row.commission_rate||0).toFixed(2)+'٪</td>';
            h+='<td style="font-weight:700;color:#7c3aed">'+fmt(row.commission_amount)+'</td>';
            h+='<td><span class="badge badge-'+row.status+'">'+(statusMap[row.status]||row.status)+'</span></td>';
            h+='<td>';
            if(row.status!=='paid' && row.status!=='cancelled')
                h+='<button class="fin-btn fin-btn-outline fin-btn-sm" onclick="openCommDiscount('+row.id+','+row.extra_discount+',\''+escHtml(row.notes||'')+'\')">✏️ تخفیف</button>';
            h+='</td>';
            h+='</tr>';
        });
        if(!d.rows.length) h='<tr><td colspan="10" style="text-align:center;padding:2rem;color:#94a3b8">نتیجه‌ای یافت نشد</td></tr>';
        document.getElementById('commRows').innerHTML=h;
    });
}

function openCommDiscount(id,disc,notes){
    document.getElementById('cdCommId').value=id;
    document.getElementById('cdDiscount').value=disc||0;
    document.getElementById('cdNotes').value=notes||'';
    openModal('commDiscountModal');
}

function saveCommDiscount(){
    var fd=new FormData();
    fd.append('action','update_commission_discount');fd.append('csrf_token',csrf);
    fd.append('id',document.getElementById('cdCommId').value);
    fd.append('extra_discount',document.getElementById('cdDiscount').value.replace(/,/g,''));
    fd.append('notes',document.getElementById('cdNotes').value);
    fetch('hr_payroll.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){closeModal('commDiscountModal');loadCommissions();showToast(d.msg);}
        else showToast(d.msg,'error');
    });
}

// ══ بخش KPI ══
function loadKpi(){
    var y=document.getElementById('kpiYear').value;
    fetch('hr_payroll.php?action=get_kpi_scores&year='+y,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.ok)return;
        var h='';
        var qNames=['فصل اول','فصل دوم','فصل سوم','فصل چهارم'];
        d.rows.forEach(function(row){
            var sc=parseFloat(row.score||0);
            h+='<tr>';
            h+='<td>'+(row.full_name||'—')+'</td>';
            h+='<td>'+row.year+'</td>';
            h+='<td>'+qNames[(row.quarter-1)%4]+'</td>';
            h+='<td style="color:'+(sc>=80?'#16a34a':'#dc2626')+';font-weight:700">'+sc.toFixed(1)+'</td>';
            h+='<td>'+(row.notes||'—')+'</td>';
            h+='<td><button class="fin-btn fin-btn-outline fin-btn-sm" onclick="editKpi('+JSON.stringify(row)+')">✏️</button></td>';
            h+='</tr>';
        });
        if(!d.rows.length) h='<tr><td colspan="6" style="text-align:center;padding:2rem;color:#94a3b8">نمره‌ای ثبت نشده</td></tr>';
        document.getElementById('kpiRows').innerHTML=h;
    });
}

function openAddKpi(){
    populateUserSelects();
    document.getElementById('kpiModalTitle').textContent='➕ ثبت نمره KPI';
    document.getElementById('kpiScore').value='0';
    document.getElementById('kpiNotes').value='';
    openModal('kpiModal');
}

function editKpi(row){
    populateUserSelects();
    document.getElementById('kpiModalTitle').textContent='✏️ ویرایش نمره KPI';
    document.getElementById('kpiUserId').value=row.user_id;
    document.getElementById('kpiScoreYear').value=row.year;
    document.getElementById('kpiQuarter').value=row.quarter;
    document.getElementById('kpiScore').value=row.score||0;
    document.getElementById('kpiNotes').value=row.notes||'';
    openModal('kpiModal');
}

function saveKpiScore(){
    var fd=new FormData();
    fd.append('action','save_kpi_score');fd.append('csrf_token',csrf);
    fd.append('user_id',document.getElementById('kpiUserId').value);
    fd.append('year',document.getElementById('kpiScoreYear').value);
    fd.append('quarter',document.getElementById('kpiQuarter').value);
    fd.append('score',document.getElementById('kpiScore').value);
    fd.append('notes',document.getElementById('kpiNotes').value);
    fetch('hr_payroll.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){closeModal('kpiModal');loadKpi();showToast(d.msg);}
        else showToast(d.msg,'error');
    });
}

// ══ بخش پلن ══
function loadPlan(){
    fetch('hr_payroll.php?action=get_plan',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.ok||!d.plan)return;
        var p=d.plan;
        document.getElementById('planId').value=p.id||'';
        document.getElementById('planName').value=p.name||'پلن پیش‌فرض';
        document.getElementById('planBaseRate').value=parseFloat(p.base_rate||1).toFixed(2);
        document.getElementById('planThreshold').value=parseInt(p.threshold||2000000000).toLocaleString('fa-IR');
        document.getElementById('planStepSize').value=parseInt(p.step_size||500000000).toLocaleString('fa-IR');
        document.getElementById('planStepRate').value=parseFloat(p.step_rate||0.1).toFixed(2);
        document.getElementById('planKpiStepRate').value=parseFloat(p.kpi_step_rate||0.2).toFixed(2);
        document.getElementById('planKpiMin').value=parseFloat(p.kpi_min_score||80).toFixed(2);
        updatePlanDesc(p);
    });
}

function updatePlanDesc(p){
    document.getElementById('desc_base').textContent=parseFloat(p.base_rate||1)+'٪';
    document.getElementById('desc_thresh').textContent=parseInt(p.threshold||2000000000).toLocaleString('fa-IR')+' ریال';
    document.getElementById('desc_step').textContent=parseInt(p.step_size||500000000).toLocaleString('fa-IR')+' ریال';
    document.getElementById('desc_rate').textContent=parseFloat(p.step_rate||0.1)+'٪';
    document.getElementById('desc_kpi_min').textContent=parseFloat(p.kpi_min_score||80);
    document.getElementById('desc_kpi_rate').textContent=parseFloat(p.kpi_step_rate||0.2)+'٪';
}

function savePlan(e){
    e.preventDefault();
    var fd=new FormData();
    fd.append('action','save_plan');fd.append('csrf_token',csrf);
    fd.append('plan_id',document.getElementById('planId').value);
    fd.append('name',document.getElementById('planName').value);
    fd.append('base_rate',document.getElementById('planBaseRate').value);
    fd.append('threshold',document.getElementById('planThreshold').value.replace(/[,\s,]/g,''));
    fd.append('step_size',document.getElementById('planStepSize').value.replace(/[,\s,]/g,''));
    fd.append('step_rate',document.getElementById('planStepRate').value);
    fd.append('kpi_step_rate',document.getElementById('planKpiStepRate').value);
    fd.append('kpi_min_score',document.getElementById('planKpiMin').value);
    fetch('hr_payroll.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.ok){showToast(d.msg);loadPlan();}
        else showToast(d.msg,'error');
    });
}

function escHtml(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── بارگذاری اولیه ──
document.addEventListener('DOMContentLoaded', function(){
    loadUsers().then(function(){
        loadPeriods();
        loadDashStats();
    });
});
</script>

<?php
ob_end_flush();
require_once __DIR__ . '/../../templates/footer.php';
?>
