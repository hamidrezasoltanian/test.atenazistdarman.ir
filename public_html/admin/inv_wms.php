<?php
/*
 * فایل: public_html/admin/inv_wms.php
 * ماژول انبارداری پیشرفته WMS
 * ورود/خروج با لات‌بندی، FEFO، جریان تأیید چندمرحله‌ای
 */
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$userId  = (int)$_SESSION['user_id'];
$rawRole = strtolower(trim($_SESSION['role'] ?? ''));
$isManager = in_array($rawRole, ['admin','manager','management','inv_manager','warehouse_manager','1','2']);

// ---- ایجاد جداول ----
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `inv_lots` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `lot_number`     VARCHAR(100) NOT NULL,
        `stuff_id`       INT NOT NULL,
        `storeroom_id`   INT NOT NULL,
        `qty`            DECIMAL(12,3) NOT NULL DEFAULT 0,
        `entry_qty`      DECIMAL(12,3) NOT NULL DEFAULT 0,
        `expiry_date`    DATE NULL,
        `purchase_price` BIGINT DEFAULT 0,
        `person_id`      INT NULL,
        `ticket_id`      INT NULL,
        `status`         ENUM('active','exhausted','expired') DEFAULT 'active',
        `notes`          TEXT NULL,
        `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_deleted`     TINYINT DEFAULT 0,
        KEY `idx_lot_stuff`     (`stuff_id`),
        KEY `idx_lot_storeroom` (`storeroom_id`),
        KEY `idx_lot_expiry`    (`expiry_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// افزودن ستون‌های جدید در صورت نبود
foreach ([
    "ALTER TABLE `inv_tickets` ADD COLUMN IF NOT EXISTS `approved_by` INT NULL AFTER `confirmed_by`",
    "ALTER TABLE `inv_tickets` ADD COLUMN IF NOT EXISTS `approved_at` DATETIME NULL",
    "ALTER TABLE `inv_tickets` ADD COLUMN IF NOT EXISTS `reject_reason` TEXT NULL",
    "ALTER TABLE `inv_tickets` MODIFY COLUMN `status` ENUM('draft','pending','confirmed','rejected') NOT NULL DEFAULT 'draft'",
    "ALTER TABLE `inv_ticket_items` ADD COLUMN IF NOT EXISTS `lot_id` INT NULL",
    "ALTER TABLE `inv_ticket_items` ADD COLUMN IF NOT EXISTS `lot_number` VARCHAR(100) NULL",
    "ALTER TABLE `inv_ticket_items` ADD COLUMN IF NOT EXISTS `expiry_date` DATE NULL",
] as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }

// ---- برچسب‌های نوع بلیت ----
$typeLabels = [
    'purchase'       => 'خرید',
    'sales_return'   => 'برگشت از فروش',
    'consignment_in' => 'امانت دریافتی',
    'transfer_in'    => 'انتقال دریافتی',
    'initial'        => 'موجودی اولیه',
    'sale'           => 'فروش',
    'supplier_return'=> 'برگشت به تأمین‌کننده',
    'consignment_out'=> 'امانت خروجی',
    'internal'       => 'مصرف داخلی',
    'transfer_out'   => 'انتقال خروجی',
    'sample'         => 'نمونه',
];
$entryTypes = ['purchase','sales_return','consignment_in','transfer_in','initial'];
$exitTypes  = ['sale','supplier_return','consignment_out','internal','transfer_out','sample'];

// ---- تابع به‌روزرسانی موجودی ----
function updateInventoryOnConfirm($pdo, $ticketId) {
    $tk = $pdo->prepare("SELECT * FROM inv_tickets WHERE id=?");
    $tk->execute([$ticketId]);
    $ticket = $tk->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) return;

    $items = $pdo->prepare("SELECT * FROM inv_ticket_items WHERE ticket_id=?");
    $items->execute([$ticketId]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);

    $isEntry = in_array($ticket['type'], ['receipt','purchase','sales_return','consignment_in','transfer_in','initial']);
    $isExit  = in_array($ticket['type'], ['dispatch','sale','supplier_return','consignment_out','internal','transfer_out','sample']);

    foreach ($rows as $row) {
        $qty = (float)$row['qty'];
        $stuffId = (int)$row['stuff_id'];
        if (!$stuffId || $qty <= 0) continue;

        if ($isEntry) {
            // ایجاد یا به‌روزرسانی لات
            $lotNum = $row['lot_number'] ?: ('L-'.$ticketId.'-'.$row['id']);
            $expiry = $row['expiry_date'] ?: null;
            // جستجوی لات موجود با همان شماره در همین انبار
            $existLot = $pdo->prepare("SELECT id,qty FROM inv_lots WHERE lot_number=? AND stuff_id=? AND storeroom_id=? AND is_deleted=0 LIMIT 1");
            $existLot->execute([$lotNum, $stuffId, $ticket['storeroom_id']]);
            $lot = $existLot->fetch(PDO::FETCH_ASSOC);
            if ($lot) {
                $pdo->prepare("UPDATE inv_lots SET qty=qty+?, entry_qty=entry_qty+?, status='active', updated_at=NOW() WHERE id=?")
                    ->execute([$qty, $qty, $lot['id']]);
                if ($row['lot_id']) {
                    $pdo->prepare("UPDATE inv_ticket_items SET lot_id=? WHERE id=?")->execute([$lot['id'], $row['id']]);
                }
            } else {
                $pdo->prepare("INSERT INTO inv_lots (lot_number,stuff_id,storeroom_id,qty,entry_qty,expiry_date,purchase_price,person_id,ticket_id) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$lotNum,$stuffId,$ticket['storeroom_id'],$qty,$qty,$expiry,(int)$row['unit_price'],$ticket['person_id'],$ticketId]);
                $newLotId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE inv_ticket_items SET lot_id=?, lot_number=? WHERE id=?")->execute([$newLotId,$lotNum,$row['id']]);
            }
            // به‌روزرسانی جدول موجودی کلی
            $pdo->prepare("INSERT INTO stuff_price_list (stuff_id,total_inventory,central_store) VALUES (?,?,?) ON DUPLICATE KEY UPDATE total_inventory=total_inventory+?, central_store=central_store+?")
                ->execute([$stuffId,$qty,$qty,$qty,$qty]);

        } elseif ($isExit) {
            $remaining = $qty;
            // FEFO: ابتدا لات‌های نزدیک به انقضا
            $lotStmt = $pdo->prepare(
                "SELECT id, qty FROM inv_lots WHERE stuff_id=? AND storeroom_id=? AND qty>0 AND status='active' AND is_deleted=0
                 ORDER BY expiry_date IS NULL ASC, expiry_date ASC, id ASC"
            );
            $lotStmt->execute([$stuffId, $ticket['storeroom_id']]);
            $availLots = $lotStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($availLots as $al) {
                if ($remaining <= 0) break;
                $take = min($remaining, (float)$al['qty']);
                $pdo->prepare("UPDATE inv_lots SET qty=qty-?, updated_at=NOW() WHERE id=?")->execute([$take, $al['id']]);
                $pdo->prepare("UPDATE inv_lots SET status='exhausted' WHERE id=? AND qty<=0")->execute([$al['id']]);
                $remaining -= $take;
            }
            // کاهش موجودی کلی
            $pdo->prepare("UPDATE stuff_price_list SET total_inventory=GREATEST(0,total_inventory-?), central_store=GREATEST(0,central_store-?) WHERE stuff_id=?")
                ->execute([$qty,$qty,$stuffId]);
        }
    }
}

// ---- شماره‌گذاری بلیت ----
function nextTicketNumber($pdo, $prefix) {
    $year = date('Y');
    $seq  = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(ticket_number,'-',-1) AS UNSIGNED)),0)+1 FROM inv_tickets WHERE ticket_number LIKE '$prefix-$year-%'")->fetchColumn();
    return $prefix . '-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ============================================================
// AJAX
// ============================================================
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action'] ?? $_GET['action'] ?? '');

    try {
        // ---- آمار داشبورد ----
        if ($action === 'get_stats') {
            $pendingEntry = $pdo->query("SELECT COUNT(*) FROM inv_tickets WHERE status='pending' AND type IN ('receipt','purchase','sales_return','consignment_in','transfer_in','initial') AND is_deleted=0")->fetchColumn();
            $pendingExit  = $pdo->query("SELECT COUNT(*) FROM inv_tickets WHERE status='pending' AND type IN ('dispatch','sale','supplier_return','consignment_out','internal','transfer_out','sample') AND is_deleted=0")->fetchColumn();
            $lowStock = $pdo->query("SELECT COUNT(*) FROM stuff_price_list WHERE total_inventory <= min_stock AND min_stock > 0")->fetchColumn();
            $expiring = $pdo->query("SELECT COUNT(*) FROM inv_lots WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(NOW(),INTERVAL 30 DAY) AND qty>0 AND status='active' AND is_deleted=0")->fetchColumn();
            echo json_encode(['ok'=>true,'pending_entry'=>(int)$pendingEntry,'pending_exit'=>(int)$pendingExit,'low_stock'=>(int)$lowStock,'expiring'=>(int)$expiring]);
            exit;
        }

        // ---- لیست بلیت‌ها ----
        if ($action === 'list_tickets') {
            $mode     = $_POST['mode'] ?? 'entry'; // entry | exit | pending
            $status   = trim($_POST['status'] ?? '');
            $srId     = (int)($_POST['storeroom_id'] ?? 0);
            $search   = trim($_POST['search'] ?? '');
            $page     = max(1,(int)($_POST['page'] ?? 1));
            $perPage  = 20;
            $offset   = ($page-1)*$perPage;

            if ($mode === 'pending') {
                $where = "t.status='pending' AND t.is_deleted=0";
                $params = [];
            } else {
                $typeList = $mode === 'entry' ? "('receipt','purchase','sales_return','consignment_in','transfer_in','initial')" : "('dispatch','sale','supplier_return','consignment_out','internal','transfer_out','sample')";
                $where = "t.type IN $typeList AND t.is_deleted=0";
                $params = [];
                if ($status) { $where .= " AND t.status=?"; $params[] = $status; }
                if ($srId)   { $where .= " AND t.storeroom_id=?"; $params[] = $srId; }
                if ($search) { $where .= " AND (t.ticket_number LIKE ? OR t.person_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
            }

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM inv_tickets t WHERE $where");
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();

            $st = $pdo->prepare(
                "SELECT t.id,t.ticket_number,t.type,t.ticket_date,t.status,t.person_name,
                        s.name AS storeroom_name, t.description,
                        u.first_name, u.last_name,
                        (SELECT COUNT(*) FROM inv_ticket_items WHERE ticket_id=t.id) AS item_count
                 FROM inv_tickets t
                 LEFT JOIN inv_storerooms s ON s.id=t.storeroom_id
                 LEFT JOIN users u ON u.id=t.created_by
                 WHERE $where ORDER BY t.id DESC LIMIT $perPage OFFSET $offset"
            );
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'rows'=>$rows,'total'=>$total,'pages'=>max(1,(int)ceil($total/$perPage))]);
            exit;
        }

        // ---- جستجوی کالا ----
        if ($action === 'search_stuffs') {
            $q = trim($_POST['q'] ?? '');
            if (strlen($q) < 1) { echo json_encode(['ok'=>true,'results']==['ok'=>true,'results'=>[]]); exit; }
            $st = $pdo->prepare("SELECT s.id, s.name, s.unit, s.code, COALESCE(spl.total_inventory,0) AS stock FROM stuffs s LEFT JOIN stuff_price_list spl ON spl.stuff_id=s.id WHERE s.is_deleted=0 AND (s.name LIKE ? OR s.code LIKE ?) LIMIT 15");
            $st->execute(["%$q%","%$q%"]);
            echo json_encode(['ok'=>true,'results'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ---- جستجوی طرف حساب ----
        if ($action === 'search_persons') {
            $q = trim($_POST['q'] ?? '');
            $st = $pdo->prepare("SELECT id, COALESCE(company_name,name) AS label, type FROM fin_persons WHERE is_deleted=0 AND (name LIKE ? OR company_name LIKE ?) LIMIT 15");
            $st->execute(["%$q%","%$q%"]);
            echo json_encode(['ok'=>true,'results'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ---- لیست انبارها ----
        if ($action === 'get_storerooms') {
            $st = $pdo->query("SELECT id,code,name FROM inv_storerooms WHERE is_active=1 AND is_deleted=0 ORDER BY name");
            echo json_encode(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ---- FEFO پیشنهاد لات ----
        if ($action === 'get_fefo') {
            $stuffId    = (int)($_POST['stuff_id'] ?? 0);
            $storeroomId = (int)($_POST['storeroom_id'] ?? 0);
            if (!$stuffId) { echo json_encode(['ok'=>false,'msg'=>'کالا انتخاب نشده']); exit; }
            $where = "stuff_id=? AND qty>0 AND status='active' AND is_deleted=0";
            $params = [$stuffId];
            if ($storeroomId) { $where .= " AND storeroom_id=?"; $params[] = $storeroomId; }
            $st = $pdo->prepare(
                "SELECT l.id, l.lot_number, l.qty, l.expiry_date, l.purchase_price,
                        s.name AS storeroom_name,
                        DATEDIFF(l.expiry_date, CURDATE()) AS days_left
                 FROM inv_lots l
                 LEFT JOIN inv_storerooms s ON s.id=l.storeroom_id
                 WHERE $where
                 ORDER BY l.expiry_date IS NULL ASC, l.expiry_date ASC, l.id ASC"
            );
            $st->execute($params);
            $lots = $st->fetchAll(PDO::FETCH_ASSOC);
            $totalAvail = array_sum(array_column($lots,'qty'));
            echo json_encode(['ok'=>true,'lots'=>$lots,'total_available'=>(float)$totalAvail]);
            exit;
        }

        // ---- بررسی موجودی ----
        if ($action === 'check_stock') {
            $stuffId    = (int)($_POST['stuff_id'] ?? 0);
            $storeroomId = (int)($_POST['storeroom_id'] ?? 0);
            $qtyNeeded  = (float)($_POST['qty'] ?? 0);
            $st = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM inv_lots WHERE stuff_id=? AND storeroom_id=? AND qty>0 AND status='active' AND is_deleted=0");
            $st->execute([$stuffId, $storeroomId]);
            $available = (float)$st->fetchColumn();
            echo json_encode(['ok'=>true,'available'=>$available,'short'=>max(0,$qtyNeeded-$available),'enough'=>($available>=$qtyNeeded)]);
            exit;
        }

        // ---- ذخیره بلیت ----
        if ($action === 'save_ticket') {
            $ticketId   = (int)($_POST['ticket_id'] ?? 0);
            $type       = trim($_POST['type'] ?? 'purchase');
            $dateJ      = trim($_POST['ticket_date'] ?? jdate('Y/m/d'));
            $storeroomId = (int)($_POST['storeroom_id'] ?? 0);
            $destSrId   = (int)($_POST['dest_storeroom_id'] ?? 0) ?: null;
            $personName = trim($_POST['person_name'] ?? '');
            $personId   = (int)($_POST['person_id'] ?? 0) ?: null;
            $desc       = trim($_POST['description'] ?? '');
            $submit     = !empty($_POST['submit']); // اگر submit=1 → وضعیت pending
            $items      = $_POST['items'] ?? [];

            if (!$storeroomId) { echo json_encode(['ok'=>false,'msg'=>'انتخاب انبار الزامی است']); exit; }
            if (empty($items)) { echo json_encode(['ok'=>false,'msg'=>'حداقل یک ردیف وارد کنید']); exit; }

            // تبدیل تاریخ جلالی به میلادی
            $dateG = $dateJ;
            if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $dateJ, $m)) {
                $g = jalaliToGregorianPHP((int)$m[1],(int)$m[2],(int)$m[3]);
                $dateG = sprintf('%04d-%02d-%02d', $g[0],$g[1],$g[2]);
            }

            $status = $submit ? 'pending' : 'draft';

            // نوع انبار برای ورودی یا خروجی
            $isEntry = in_array($type, $entryTypes);
            $dbType  = $isEntry ? 'receipt' : 'dispatch';
            if ($type === 'transfer_in' || $type === 'transfer_out') $dbType = 'transfer';

            if ($ticketId > 0) {
                // ویرایش
                $chk = $pdo->prepare("SELECT status FROM inv_tickets WHERE id=? AND is_deleted=0");
                $chk->execute([$ticketId]);
                $cur = $chk->fetchColumn();
                if ($cur && $cur !== 'draft') { echo json_encode(['ok'=>false,'msg'=>'فقط پیش‌نویس قابل ویرایش است']); exit; }
                $pdo->prepare("UPDATE inv_tickets SET type=?,ticket_date=?,ticket_date_g=?,storeroom_id=?,dest_storeroom_id=?,person_id=?,person_name=?,description=?,status=?,updated_at=NOW() WHERE id=?")
                    ->execute([$type,$dateJ,$dateG,$storeroomId,$destSrId,$personId,$personName,$desc,$status,$ticketId]);
                $pdo->prepare("DELETE FROM inv_ticket_items WHERE ticket_id=?")->execute([$ticketId]);
            } else {
                $prefix = $isEntry ? 'RC' : 'DS';
                if ($dbType === 'transfer') $prefix = 'TR';
                $tNum = nextTicketNumber($pdo, $prefix);
                $pdo->prepare("INSERT INTO inv_tickets (ticket_number,type,ticket_date,ticket_date_g,storeroom_id,dest_storeroom_id,person_id,person_name,description,status,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
                    ->execute([$tNum,$type,$dateJ,$dateG,$storeroomId,$destSrId,$personId,$personName,$desc,$status,$userId]);
                $ticketId = (int)$pdo->lastInsertId();
            }

            // درج ردیف‌ها
            $stItem = $pdo->prepare("INSERT INTO inv_ticket_items (ticket_id,stuff_id,stuff_code,description,unit,qty,unit_price,total,lot_number,expiry_date,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($items as $i => $item) {
                $desc2  = trim($item['description'] ?? '');
                if (!$desc2) continue;
                $qty    = (float)($item['qty'] ?? 0);
                $price  = (int)($item['unit_price'] ?? 0);
                $sid    = (int)($item['stuff_id'] ?? 0) ?: null;
                $sCode  = trim($item['stuff_code'] ?? '');
                $unit   = trim($item['unit'] ?? 'عدد');
                $lotNum = trim($item['lot_number'] ?? '');
                $expiry = trim($item['expiry_date'] ?? '') ?: null;
                $stItem->execute([$ticketId,$sid,$sCode,$desc2,$unit,$qty,$price,(int)($qty*$price),$lotNum,$expiry,$i+1]);
            }

            $tNum2 = $pdo->query("SELECT ticket_number FROM inv_tickets WHERE id=$ticketId")->fetchColumn();
            echo json_encode(['ok'=>true,'ticket_id'=>$ticketId,'ticket_number'=>$tNum2,'status'=>$status,'msg'=>$submit?'بلیت ارسال شد و در انتظار تأیید است':'پیش‌نویس ذخیره شد']);
            exit;
        }

        // ---- دریافت بلیت برای ویرایش ----
        if ($action === 'get_ticket') {
            $id = (int)($_POST['id'] ?? 0);
            $tk = $pdo->prepare("SELECT t.*, s.name AS storeroom_name FROM inv_tickets t LEFT JOIN inv_storerooms s ON s.id=t.storeroom_id WHERE t.id=? AND t.is_deleted=0");
            $tk->execute([$id]);
            $ticket = $tk->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) { echo json_encode(['ok'=>false,'msg'=>'یافت نشد']); exit; }
            $items = $pdo->prepare("SELECT ti.*, s.name AS stuff_name FROM inv_ticket_items ti LEFT JOIN stuffs s ON s.id=ti.stuff_id WHERE ti.ticket_id=? ORDER BY sort_order");
            $items->execute([$id]);
            $ticket['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'ticket'=>$ticket]);
            exit;
        }

        // ---- حذف بلیت (فقط پیش‌نویس) ----
        if ($action === 'delete_ticket') {
            $id = (int)($_POST['id'] ?? 0);
            $chk = $pdo->prepare("SELECT status FROM inv_tickets WHERE id=? AND is_deleted=0");
            $chk->execute([$id]);
            $s = $chk->fetchColumn();
            if (!$s) { echo json_encode(['ok'=>false,'msg'=>'یافت نشد']); exit; }
            if ($s !== 'draft') { echo json_encode(['ok'=>false,'msg'=>'فقط پیش‌نویس قابل حذف است']); exit; }
            $pdo->prepare("UPDATE inv_tickets SET is_deleted=1, updated_at=NOW() WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true,'msg'=>'حذف شد']);
            exit;
        }

        // ---- تأیید بلیت ----
        if ($action === 'approve_ticket') {
            if (!$isManager) { echo json_encode(['ok'=>false,'msg'=>'دسترسی ندارید']); exit; }
            $id   = (int)($_POST['id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            $chk  = $pdo->prepare("SELECT status FROM inv_tickets WHERE id=? AND is_deleted=0");
            $chk->execute([$id]);
            $s = $chk->fetchColumn();
            if (!$s) { echo json_encode(['ok'=>false,'msg'=>'یافت نشد']); exit; }
            if (!in_array($s,['draft','pending'])) { echo json_encode(['ok'=>false,'msg'=>'وضعیت قابل تأیید نیست']); exit; }
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE inv_tickets SET status='confirmed',confirmed_by=?,confirmed_at=NOW(),approved_by=?,approved_at=NOW(),description=CONCAT(COALESCE(description,''),' ',?) WHERE id=?")
                ->execute([$userId,$userId,$note?'['.$note.']':'',$id]);
            updateInventoryOnConfirm($pdo, $id);
            $pdo->commit();
            echo json_encode(['ok'=>true,'msg'=>'تأیید و موجودی به‌روز شد']);
            exit;
        }

        // ---- رد بلیت ----
        if ($action === 'reject_ticket') {
            if (!$isManager) { echo json_encode(['ok'=>false,'msg'=>'دسترسی ندارید']); exit; }
            $id     = (int)($_POST['id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if (!$reason) { echo json_encode(['ok'=>false,'msg'=>'دلیل رد الزامی است']); exit; }
            $pdo->prepare("UPDATE inv_tickets SET status='rejected',reject_reason=?,updated_at=NOW() WHERE id=? AND status='pending'")
                ->execute([$reason,$id]);
            echo json_encode(['ok'=>true,'msg'=>'رد شد']);
            exit;
        }

        // ---- لیست لات‌ها ----
        if ($action === 'list_lots') {
            $stuffId = (int)($_POST['stuff_id'] ?? 0);
            $srId    = (int)($_POST['storeroom_id'] ?? 0);
            $showAll = !empty($_POST['show_all']);
            $where = "l.is_deleted=0";
            $params = [];
            if ($stuffId) { $where .= " AND l.stuff_id=?"; $params[] = $stuffId; }
            if ($srId)    { $where .= " AND l.storeroom_id=?"; $params[] = $srId; }
            if (!$showAll) { $where .= " AND l.status='active' AND l.qty>0"; }
            $st = $pdo->prepare(
                "SELECT l.*, s.name AS stuff_name, s.unit, sr.name AS storeroom_name,
                        DATEDIFF(l.expiry_date,CURDATE()) AS days_left,
                        COALESCE(fp.company_name,fp.name) AS supplier_name
                 FROM inv_lots l
                 LEFT JOIN stuffs s ON s.id=l.stuff_id
                 LEFT JOIN inv_storerooms sr ON sr.id=l.storeroom_id
                 LEFT JOIN fin_persons fp ON fp.id=l.person_id
                 WHERE $where ORDER BY l.expiry_date IS NULL ASC, l.expiry_date ASC, l.id DESC"
            );
            $st->execute($params);
            echo json_encode(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        echo json_encode(['ok'=>false,'msg'=>'درخواست نامعتبر']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'خطای سرور: '.$e->getMessage()]);
    }
    exit;
}

// ---- تابع تبدیل تاریخ جلالی به میلادی ----
function jalaliToGregorianPHP($jy,$jm,$jd) {
    $jy -= 979; $jm -= 1; $jd -= 1;
    $j_day_no = 365*$jy + (int)($jy/4)*8 - (int)($jy/100)*67 + (int)($jy/400)*24 + (int)($jy/100)*2;
    for ($i=0;$i<$jm;++$i) $j_day_no += [31,31,31,31,31,31,30,30,30,30,30,29][$i];
    $j_day_no += $jd;
    $g_day_no = $j_day_no + 79;
    $gy = 1600 + 400*(int)($g_day_no/146097); $g_day_no %= 146097;
    $leap = true;
    if ($g_day_no >= 36525) { $g_day_no--; $gy += 100*(int)($g_day_no/36524); $g_day_no %= 36524; if ($g_day_no >= 365) $g_day_no++; else $leap = false; }
    $gy += 4*(int)($g_day_no/1461); $g_day_no %= 1461;
    if ($g_day_no >= 366) { $leap = false; $g_day_no--; $gy += (int)($g_day_no/365); $g_day_no %= 365; }
    $gDays = [31,($leap?29:28),31,30,31,30,31,31,30,31,30,31];
    $gm = 0;
    for ($i=0;$i<12&&$g_day_no>=$gDays[$i];$i++) $g_day_no -= $gDays[$i++];
    $gm = $i+1; $gd = $g_day_no+1;
    return [$gy,$gm,$gd];
}

$basePath  = '../../';
$pageTitle = 'انبار پیشرفته WMS';
require_once __DIR__ . '/../../templates/header.php';
?>
<link rel="stylesheet" href="<?= $basePath ?>assets/css/fin_module.css">
<style>
.wms-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:20px;flex-wrap:wrap}
.wms-tab{padding:10px 22px;cursor:pointer;font-size:.9rem;font-weight:600;color:#64748b;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s;font-family:Vazirmatn,Tahoma,sans-serif;background:none;border-top:none;border-left:none;border-right:none}
.wms-tab.active{color:#0891b2;border-bottom-color:#0891b2}
.wms-tab:hover:not(.active){color:#0891b2;background:#f0fdff}
.wms-tab-content{display:none}.wms-tab-content.active{display:block}
.wms-form-panel{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:20px;margin-bottom:16px}
.wms-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:16px}
.wms-item-thead{display:grid;grid-template-columns:24px 1fr 60px 80px 110px 110px 110px 32px;gap:6px;padding:6px 10px;background:#f8fafc;border-radius:8px;font-size:.76rem;font-weight:700;color:#64748b;margin-bottom:4px}
.wms-item-row{display:grid;grid-template-columns:24px 1fr 60px 80px 110px 110px 110px 32px;gap:6px;padding:6px 10px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:4px;align-items:center;font-size:.82rem;background:#fafafa}
.wms-item-row:hover{background:#f0fdff;border-color:#bae6fd}
.wms-inp{border:1px solid #e2e8f0;border-radius:7px;padding:5px 8px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.82rem;width:100%;box-sizing:border-box;background:#fff}
.wms-inp:focus{outline:none;border-color:#0891b2;box-shadow:0 0 0 2px rgba(8,145,178,.12)}
.fefo-box{background:#f0fdff;border:1px solid #bae6fd;border-radius:10px;padding:10px;margin-top:6px;font-size:.8rem}
.fefo-lot{display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:7px;cursor:pointer;margin-bottom:3px;transition:background .12s}
.fefo-lot:hover{background:#bae6fd}
.fefo-lot.warn{background:#fffbeb;border:1px solid #fcd34d}
.fefo-lot.danger{background:#fff1f2;border:1px solid #fca5a5}
.badge-draft{background:#f1f5f9;color:#475569;padding:2px 9px;border-radius:20px;font-size:.75rem;font-weight:600}
.badge-pending{background:#fffbeb;color:#b45309;padding:2px 9px;border-radius:20px;font-size:.75rem;font-weight:600}
.badge-confirmed{background:#dcfce7;color:#166534;padding:2px 9px;border-radius:20px;font-size:.75rem;font-weight:600}
.badge-rejected{background:#fee2e2;color:#991b1b;padding:2px 9px;border-radius:20px;font-size:.75rem;font-weight:600}
.wms-stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:20px}
.wms-stat{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:16px;display:flex;align-items:center;gap:14px}
.wms-stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
.wms-stat-label{font-size:.78rem;color:#64748b;margin-bottom:3px}
.wms-stat-val{font-size:1.4rem;font-weight:800}
.suggest-list{position:absolute;z-index:200;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;width:100%}
.suggest-item{padding:8px 14px;cursor:pointer;font-size:.83rem;border-bottom:1px solid #f1f5f9}
.suggest-item:hover{background:#f0fdff}
.suggest-wrap{position:relative}
@media(max-width:760px){.wms-item-thead{display:none}.wms-item-row{grid-template-columns:1fr 1fr;gap:6px}.wms-grid{grid-template-columns:1fr}}
</style>

<main class="main-content">
    <div class="inv-page-header">
        <div class="inv-page-title">
            <div style="width:42px;height:42px;background:linear-gradient(135deg,#0891b2,#0e7490);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem">📦</div>
            <div>
                <h1 style="margin:0;font-size:1.2rem;font-weight:800">انبار پیشرفته WMS</h1>
                <p style="margin:0;font-size:.8rem;color:#64748b">مدیریت ورود/خروج با لات‌بندی و FEFO</p>
            </div>
        </div>
        <div style="display:flex;gap:10px">
            <button class="fin-btn fin-btn-primary" onclick="openNewForm('entry')" style="background:#0891b2;border-color:#0e7490">+ ورود کالا</button>
            <button class="fin-btn fin-btn-primary" onclick="openNewForm('exit')" style="background:#7c3aed;border-color:#6d28d9">+ خروج / حواله</button>
        </div>
    </div>

    <!-- تب‌ها -->
    <div class="wms-tabs">
        <button class="wms-tab active" onclick="switchTab('dash')">📊 داشبورد</button>
        <button class="wms-tab" onclick="switchTab('entry')">📥 ورود کالا</button>
        <button class="wms-tab" onclick="switchTab('exit')">📤 خروج / حواله</button>
        <button class="wms-tab" onclick="switchTab('pending')" id="tabPendingBtn">⏳ در انتظار تأیید</button>
        <button class="wms-tab" onclick="switchTab('lots')">🗂️ موجودی لات‌ها</button>
    </div>

    <!-- داشبورد -->
    <div id="tab-dash" class="wms-tab-content active">
        <div class="wms-stat-grid">
            <div class="wms-stat"><div class="wms-stat-icon" style="background:#e0f2fe">📥</div><div><div class="wms-stat-label">ورودی در انتظار</div><div class="wms-stat-val" id="st-pEntry" style="color:#0891b2">—</div></div></div>
            <div class="wms-stat"><div class="wms-stat-icon" style="background:#f3e8ff">📤</div><div><div class="wms-stat-label">خروجی در انتظار</div><div class="wms-stat-val" id="st-pExit" style="color:#7c3aed">—</div></div></div>
            <div class="wms-stat"><div class="wms-stat-icon" style="background:#fee2e2">⚠️</div><div><div class="wms-stat-label">کم‌موجودی</div><div class="wms-stat-val" id="st-lowStock" style="color:#dc2626">—</div></div></div>
            <div class="wms-stat"><div class="wms-stat-icon" style="background:#fffbeb">⏰</div><div><div class="wms-stat-label">نزدیک انقضا (۳۰ روز)</div><div class="wms-stat-val" id="st-expiring" style="color:#d97706">—</div></div></div>
        </div>
        <div class="fin-panel" id="recentPendingWrap">
            <div style="font-weight:700;margin-bottom:12px">📋 بلیت‌های در انتظار تأیید</div>
            <div id="recentPending"><div style="text-align:center;color:#9ca3af;padding:24px">در حال بارگذاری...</div></div>
        </div>
    </div>

    <!-- ورود کالا -->
    <div id="tab-entry" class="wms-tab-content">
        <div id="entryForm" style="display:none">
            <div class="wms-form-panel">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                    <div style="font-weight:800;font-size:1rem;color:#0891b2">📥 <span id="entFormTitle">بلیت ورود جدید</span></div>
                    <button onclick="closeForm('entry')" style="background:#f1f5f9;border:1px solid #e2e8f0;padding:6px 14px;border-radius:8px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.82rem;color:#64748b">✕ بستن</button>
                </div>
                <input type="hidden" id="entTicketId" value="0">
                <div class="wms-grid">
                    <div>
                        <label class="fin-label">نوع ورود *</label>
                        <select id="entType" class="wms-inp">
                            <option value="purchase">خرید</option>
                            <option value="sales_return">برگشت از فروش</option>
                            <option value="consignment_in">امانت دریافتی</option>
                            <option value="transfer_in">انتقال دریافتی</option>
                            <option value="initial">موجودی اولیه</option>
                        </select>
                    </div>
                    <div>
                        <label class="fin-label">تاریخ *</label>
                        <input type="text" id="entDate" class="wms-inp" placeholder="۱۴۰۳/۰۶/۱۵">
                    </div>
                    <div>
                        <label class="fin-label">انبار مقصد *</label>
                        <select id="entStoreroom" class="wms-inp"></select>
                    </div>
                    <div>
                        <label class="fin-label">تأمین‌کننده / طرف حساب</label>
                        <div class="suggest-wrap">
                            <input type="text" id="entPersonSearch" class="wms-inp" placeholder="نام تأمین‌کننده..." autocomplete="off" oninput="searchPersons(this,'entPersonId','entPersonList')">
                            <input type="hidden" id="entPersonId">
                            <div class="suggest-list" id="entPersonList" style="display:none"></div>
                        </div>
                    </div>
                    <div style="grid-column:span 2">
                        <label class="fin-label">توضیحات</label>
                        <input type="text" id="entDesc" class="wms-inp" placeholder="توضیحات اختیاری...">
                    </div>
                </div>
                <!-- ردیف‌ها -->
                <div class="wms-item-thead">
                    <span>#</span><span>کالا</span><span>واحد</span><span>تعداد</span>
                    <span>قیمت خرید</span><span>شماره لات</span><span>تاریخ انقضا</span><span></span>
                </div>
                <div id="entItems"></div>
                <button onclick="addEntryRow()" style="margin-top:8px;background:#e0f2fe;color:#0891b2;border:1px dashed #7dd3fc;padding:7px 18px;border-radius:8px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.82rem">➕ افزودن ردیف</button>
                <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap">
                    <button onclick="saveTicket('entry','draft')" style="background:#f0fdff;color:#0891b2;border:1px solid #bae6fd;padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;font-weight:700">💾 ذخیره پیش‌نویس</button>
                    <button onclick="saveTicket('entry','submit')" style="background:#0891b2;color:#fff;border:none;padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;font-weight:700">📨 ارسال برای تأیید</button>
                    <?php if ($isManager): ?>
                    <button onclick="saveTicket('entry','approve')" style="background:#16a34a;color:#fff;border:none;padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;font-weight:700">✅ ذخیره و تأیید</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- لیست ورودی‌ها -->
        <div id="entryList">
            <div class="fin-panel" style="margin-bottom:12px">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                    <div style="flex:1;min-width:180px"><input type="text" id="entSearch" class="wms-inp" placeholder="جستجو در بلیت‌های ورودی..." oninput="debounce(()=>loadList('entry'),400)"></div>
                    <select id="entStatusFilter" class="wms-inp" style="width:140px" onchange="loadList('entry')">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="draft">پیش‌نویس</option>
                        <option value="pending">در انتظار</option>
                        <option value="confirmed">تأیید شده</option>
                        <option value="rejected">رد شده</option>
                    </select>
                    <select id="entSrFilter" class="wms-inp" style="width:160px" onchange="loadList('entry')"><option value="">همه انبارها</option></select>
                </div>
            </div>
            <div id="entryListBody"></div>
        </div>
    </div>

    <!-- خروج / حواله -->
    <div id="tab-exit" class="wms-tab-content">
        <div id="exitForm" style="display:none">
            <div class="wms-form-panel">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                    <div style="font-weight:800;font-size:1rem;color:#7c3aed">📤 <span id="exitFormTitle">حواله خروج جدید</span></div>
                    <button onclick="closeForm('exit')" style="background:#f1f5f9;border:1px solid #e2e8f0;padding:6px 14px;border-radius:8px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.82rem;color:#64748b">✕ بستن</button>
                </div>
                <input type="hidden" id="exitTicketId" value="0">
                <div class="wms-grid">
                    <div>
                        <label class="fin-label">نوع خروج *</label>
                        <select id="exitType" class="wms-inp">
                            <option value="sale">فروش</option>
                            <option value="supplier_return">برگشت به تأمین‌کننده</option>
                            <option value="consignment_out">امانت خروجی</option>
                            <option value="internal">مصرف داخلی</option>
                            <option value="transfer_out">انتقال خروجی</option>
                            <option value="sample">نمونه</option>
                        </select>
                    </div>
                    <div>
                        <label class="fin-label">تاریخ *</label>
                        <input type="text" id="exitDate" class="wms-inp" placeholder="۱۴۰۳/۰۶/۱۵">
                    </div>
                    <div>
                        <label class="fin-label">انبار مبدأ *</label>
                        <select id="exitStoreroom" class="wms-inp" onchange="refreshExitFefos()"></select>
                    </div>
                    <div>
                        <label class="fin-label">مشتری / طرف حساب</label>
                        <div class="suggest-wrap">
                            <input type="text" id="exitPersonSearch" class="wms-inp" placeholder="نام مشتری..." autocomplete="off" oninput="searchPersons(this,'exitPersonId','exitPersonList')">
                            <input type="hidden" id="exitPersonId">
                            <div class="suggest-list" id="exitPersonList" style="display:none"></div>
                        </div>
                    </div>
                    <div style="grid-column:span 2">
                        <label class="fin-label">توضیحات</label>
                        <input type="text" id="exitDesc" class="wms-inp" placeholder="توضیحات اختیاری...">
                    </div>
                </div>
                <div class="wms-item-thead">
                    <span>#</span><span>کالا</span><span>واحد</span><span>تعداد</span>
                    <span>قیمت فروش</span><span>لات پیشنهادی (FEFO)</span><span>موجودی لات</span><span></span>
                </div>
                <div id="exitItems"></div>
                <button onclick="addExitRow()" style="margin-top:8px;background:#f3e8ff;color:#7c3aed;border:1px dashed #c4b5fd;padding:7px 18px;border-radius:8px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.82rem">➕ افزودن ردیف</button>
                <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap">
                    <button onclick="saveTicket('exit','draft')" style="background:#f3e8ff;color:#7c3aed;border:1px solid #c4b5fd;padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;font-weight:700">💾 ذخیره پیش‌نویس</button>
                    <button onclick="saveTicket('exit','submit')" style="background:#7c3aed;color:#fff;border:none;padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;font-weight:700">📨 ارسال برای تأیید</button>
                    <?php if ($isManager): ?>
                    <button onclick="saveTicket('exit','approve')" style="background:#16a34a;color:#fff;border:none;padding:10px 20px;border-radius:10px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.9rem;font-weight:700">✅ ذخیره و تأیید</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div id="exitList">
            <div class="fin-panel" style="margin-bottom:12px">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                    <div style="flex:1;min-width:180px"><input type="text" id="exitSearch" class="wms-inp" placeholder="جستجو در حواله‌ها..." oninput="debounce(()=>loadList('exit'),400)"></div>
                    <select id="exitStatusFilter" class="wms-inp" style="width:140px" onchange="loadList('exit')">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="draft">پیش‌نویس</option>
                        <option value="pending">در انتظار</option>
                        <option value="confirmed">تأیید شده</option>
                        <option value="rejected">رد شده</option>
                    </select>
                    <select id="exitSrFilter" class="wms-inp" style="width:160px" onchange="loadList('exit')"><option value="">همه انبارها</option></select>
                </div>
            </div>
            <div id="exitListBody"></div>
        </div>
    </div>

    <!-- در انتظار تأیید -->
    <div id="tab-pending" class="wms-tab-content">
        <div class="fin-panel" id="pendingListWrap">
            <div style="font-weight:700;margin-bottom:12px;font-size:.95rem">⏳ بلیت‌های در انتظار تأیید مدیر</div>
            <div id="pendingListBody"></div>
        </div>
    </div>

    <!-- موجودی لات‌ها -->
    <div id="tab-lots" class="wms-tab-content">
        <div class="fin-panel" style="margin-bottom:12px">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                <div style="flex:1;min-width:180px">
                    <div class="suggest-wrap">
                        <input type="text" id="lotStuffSearch" class="wms-inp" placeholder="جستجوی کالا..." autocomplete="off" oninput="searchLotStuff(this.value)">
                        <input type="hidden" id="lotStuffId">
                        <div class="suggest-list" id="lotStuffList" style="display:none"></div>
                    </div>
                </div>
                <select id="lotSrFilter" class="wms-inp" style="width:160px" onchange="loadLots()"><option value="">همه انبارها</option></select>
                <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;cursor:pointer">
                    <input type="checkbox" id="lotShowAll" onchange="loadLots()"> نمایش تمام لات‌ها (شامل تمام‌شده)
                </label>
                <button class="fin-btn fin-btn-primary" onclick="loadLots()" style="background:#0891b2;border-color:#0e7490">🔍 جستجو</button>
            </div>
        </div>
        <div id="lotsTableWrap"></div>
    </div>
</main>

<!-- مودال تأیید -->
<div id="approveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:16px;padding:28px;min-width:360px;max-width:460px;width:90%">
        <div style="font-weight:800;font-size:1.05rem;margin-bottom:16px;color:#166534">✅ تأیید بلیت</div>
        <input type="hidden" id="approveId">
        <label class="fin-label">یادداشت (اختیاری)</label>
        <textarea id="approveNote" rows="3" class="wms-inp" placeholder="توضیح تأیید..." style="resize:vertical;margin-bottom:16px"></textarea>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button onclick="document.getElementById('approveModal').style.display='none'" style="background:#f1f5f9;border:1px solid #e2e8f0;padding:9px 18px;border-radius:9px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif">انصراف</button>
            <button onclick="doApprove()" style="background:#16a34a;color:#fff;border:none;padding:9px 20px;border-radius:9px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-weight:700">✅ تأیید</button>
        </div>
    </div>
</div>

<!-- مودال رد -->
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:16px;padding:28px;min-width:360px;max-width:460px;width:90%">
        <div style="font-weight:800;font-size:1.05rem;margin-bottom:16px;color:#dc2626">❌ رد بلیت</div>
        <input type="hidden" id="rejectId">
        <label class="fin-label">دلیل رد *</label>
        <textarea id="rejectReason" rows="3" class="wms-inp" placeholder="دلیل رد کردن را وارد کنید..." style="resize:vertical;margin-bottom:16px"></textarea>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button onclick="document.getElementById('rejectModal').style.display='none'" style="background:#f1f5f9;border:1px solid #e2e8f0;padding:9px 18px;border-radius:9px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif">انصراف</button>
            <button onclick="doReject()" style="background:#dc2626;color:#fff;border:none;padding:9px 20px;border-radius:9px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-weight:700">رد کردن</button>
        </div>
    </div>
</div>

<script>
(function(){
var storerooms = [];
var entRowCnt=0, exitRowCnt=0;
var debTimer={};

// ===== ابزارها =====
function n2fa(n){return String(n).replace(/\d/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d];})}
function numFmt(n){return n2fa(Number(n||0).toLocaleString('en'));}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function debounce(fn,ms){clearTimeout(debTimer[fn]);debTimer[fn]=setTimeout(fn,ms);}

function post(data,isForm){
    var fd = isForm ? data : (function(){var f=new FormData();for(var k in data)f.append(k,data[k]);return f;})();
    return fetch(window.location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(function(r){return r.json();});
}

function badgeHtml(status){
    var m={draft:'badge-draft پیش‌نویس',pending:'badge-pending در انتظار',confirmed:'badge-confirmed تأیید شده',rejected:'badge-rejected رد شده'};
    var parts=(m[status]||'badge-draft '+status).split(' ');
    var cls=parts.shift(); var label=parts.join(' ');
    return '<span class="'+cls+'">'+label+'</span>';
}

// ===== تب‌ها =====
window.switchTab=function(t){
    document.querySelectorAll('.wms-tab').forEach(function(b){b.classList.remove('active');});
    document.querySelectorAll('.wms-tab-content').forEach(function(c){c.classList.remove('active');});
    document.querySelector('[onclick="switchTab(\''+t+'\')"]').classList.add('active');
    document.getElementById('tab-'+t).classList.add('active');
    if(t==='dash'){loadDash();}
    else if(t==='entry'){loadList('entry');}
    else if(t==='exit'){loadList('exit');}
    else if(t==='pending'){loadPending();}
    else if(t==='lots'){loadLots();}
};

// ===== انبارها =====
function loadStorerooms(){
    post({action:'get_storerooms'}).then(function(res){
        storerooms=res.rows||[];
        var opts='<option value="">انتخاب انبار...</option>'+storerooms.map(function(s){return '<option value="'+s.id+'">'+esc(s.name)+'</option>';}).join('');
        ['entStoreroom','exitStoreroom'].forEach(function(id){document.getElementById(id).innerHTML=opts;});
        var filterOpts='<option value="">همه انبارها</option>'+storerooms.map(function(s){return '<option value="'+s.id+'">'+esc(s.name)+'</option>';}).join('');
        ['entSrFilter','exitSrFilter','lotSrFilter'].forEach(function(id){if(document.getElementById(id))document.getElementById(id).innerHTML+=storerooms.map(function(s){return '<option value="'+s.id+'">'+esc(s.name)+'</option>';}).join('');});
    });
}

// ===== داشبورد =====
function loadDash(){
    post({action:'get_stats'}).then(function(res){
        if(!res.ok)return;
        document.getElementById('st-pEntry').textContent=n2fa(res.pending_entry);
        document.getElementById('st-pExit').textContent=n2fa(res.pending_exit);
        document.getElementById('st-lowStock').textContent=n2fa(res.low_stock);
        document.getElementById('st-expiring').textContent=n2fa(res.expiring);
        var total=res.pending_entry+res.pending_exit;
        if(total>0) document.getElementById('tabPendingBtn').textContent='⏳ در انتظار تأیید ('+n2fa(total)+')';
    });
    post({action:'list_tickets',mode:'pending',page:1}).then(function(res){
        var el=document.getElementById('recentPending');
        if(!res.ok||!res.rows||!res.rows.length){el.innerHTML='<div style="text-align:center;color:#9ca3af;padding:20px">هیچ بلیتی در انتظار تأیید نیست ✅</div>';return;}
        var h='<div style="overflow-x:auto"><table class="fin-table"><thead><tr><th>شماره</th><th>نوع</th><th>طرف حساب</th><th>انبار</th><th>اقلام</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody>';
        res.rows.forEach(function(r){
            h+='<tr><td style="font-weight:700;color:#0891b2;direction:ltr">'+esc(r.ticket_number)+'</td>'
              +'<td>'+esc(typeLabel(r.type))+'</td>'
              +'<td>'+esc(r.person_name||'—')+'</td>'
              +'<td>'+esc(r.storeroom_name||'—')+'</td>'
              +'<td style="text-align:center">'+n2fa(r.item_count)+'</td>'
              +'<td>'+esc(r.ticket_date||'')+'</td>'
              +'<td style="white-space:nowrap">'
              +'<button onclick="openApprove('+r.id+')" style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:3px 10px;border-radius:6px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.75rem;margin-left:4px">✅ تأیید</button>'
              +'<button onclick="openReject('+r.id+')" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:3px 10px;border-radius:6px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.75rem">❌ رد</button>'
              +'</td></tr>';
        });
        h+='</tbody></table></div>';
        el.innerHTML=h;
    });
}

// ===== لیست ورود/خروج =====
function loadList(mode){
    var search=document.getElementById(mode==='entry'?'entSearch':'exitSearch').value;
    var status=document.getElementById(mode==='entry'?'entStatusFilter':'exitStatusFilter').value;
    var srId=document.getElementById(mode==='entry'?'entSrFilter':'exitSrFilter').value;
    var el=document.getElementById(mode==='entry'?'entryListBody':'exitListBody');
    el.innerHTML='<div style="text-align:center;padding:30px;color:#9ca3af">در حال بارگذاری...</div>';
    post({action:'list_tickets',mode:mode,status:status,storeroom_id:srId,search:search,page:1}).then(function(res){
        if(!res.ok){el.innerHTML='<div style="color:#e11d48;padding:12px">خطا: '+esc(res.msg)+'</div>';return;}
        if(!res.rows||!res.rows.length){el.innerHTML='<div style="text-align:center;color:#9ca3af;padding:40px">نتیجه‌ای یافت نشد</div>';return;}
        var h='<div style="overflow-x:auto"><table class="fin-table"><thead><tr><th>شماره</th><th>نوع</th><th>طرف حساب</th><th>انبار</th><th>اقلام</th><th>تاریخ</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
        res.rows.forEach(function(r){
            h+='<tr><td style="font-weight:700;color:'+(mode==='entry'?'#0891b2':'#7c3aed')+';direction:ltr">'+esc(r.ticket_number)+'</td>'
              +'<td>'+esc(typeLabel(r.type))+'</td>'
              +'<td>'+esc(r.person_name||'—')+'</td>'
              +'<td>'+esc(r.storeroom_name||'—')+'</td>'
              +'<td style="text-align:center">'+n2fa(r.item_count)+'</td>'
              +'<td>'+esc(r.ticket_date||'')+'</td>'
              +'<td>'+badgeHtml(r.status)+'</td>'
              +'<td style="white-space:nowrap">'
              +(r.status==='draft'?'<button onclick="editTicket('+r.id+',\''+mode+'\')" style="background:#f8fafc;border:1px solid #e2e8f0;padding:3px 10px;border-radius:6px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.75rem;margin-left:4px">✏️ ویرایش</button>':'')
              +(r.status==='pending'&&<?= $isManager?'true':'false'?>?'<button onclick="openApprove('+r.id+')" style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:3px 10px;border-radius:6px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.75rem;margin-left:4px">✅</button>':'')
              +(r.status==='pending'&&<?= $isManager?'true':'false'?>?'<button onclick="openReject('+r.id+')" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:3px 10px;border-radius:6px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.75rem;margin-left:4px">❌</button>':'')
              +(r.status==='draft'?'<button onclick="deleteTicket('+r.id+',\''+mode+'\')" style="background:#fff1f2;border:1px solid #fca5a5;padding:3px 10px;border-radius:6px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.75rem">🗑️</button>':'')
              +'</td></tr>';
        });
        h+='</tbody></table></div>';
        el.innerHTML=h;
    });
}

// ===== بلیت‌های در انتظار =====
function loadPending(){
    var el=document.getElementById('pendingListBody');
    el.innerHTML='<div style="text-align:center;padding:30px;color:#9ca3af">در حال بارگذاری...</div>';
    post({action:'list_tickets',mode:'pending',page:1}).then(function(res){
        if(!res.ok||!res.rows||!res.rows.length){el.innerHTML='<div style="text-align:center;color:#9ca3af;padding:40px">هیچ بلیتی در انتظار تأیید نیست ✅</div>';return;}
        var h='<div style="overflow-x:auto"><table class="fin-table"><thead><tr><th>شماره</th><th>نوع</th><th>طرف حساب</th><th>انبار</th><th>اقلام</th><th>تاریخ</th><th>ثبت‌کننده</th><th>عملیات</th></tr></thead><tbody>';
        res.rows.forEach(function(r){
            h+='<tr><td style="font-weight:700;direction:ltr">'+esc(r.ticket_number)+'</td>'
              +'<td>'+esc(typeLabel(r.type))+'</td>'
              +'<td>'+esc(r.person_name||'—')+'</td>'
              +'<td>'+esc(r.storeroom_name||'—')+'</td>'
              +'<td style="text-align:center">'+n2fa(r.item_count)+'</td>'
              +'<td>'+esc(r.ticket_date||'')+'</td>'
              +'<td>'+esc((r.first_name||'')+' '+(r.last_name||''))+'</td>'
              +'<td style="white-space:nowrap">'
              +'<button onclick="openApprove('+r.id+')" style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:4px 12px;border-radius:7px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.78rem;margin-left:5px">✅ تأیید</button>'
              +'<button onclick="openReject('+r.id+')" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:4px 12px;border-radius:7px;cursor:pointer;font-family:Vazirmatn,Tahoma,sans-serif;font-size:.78rem">❌ رد</button>'
              +'</td></tr>';
        });
        h+='</tbody></table></div>';
        el.innerHTML=h;
    });
}

// ===== فرم ورود =====
window.openNewForm=function(mode){
    switchTab(mode==='entry'?'entry':'exit');
    setTimeout(function(){
        if(mode==='entry'){
            document.getElementById('entTicketId').value='0';
            document.getElementById('entFormTitle').textContent='بلیت ورود جدید';
            document.getElementById('entItems').innerHTML='';
            entRowCnt=0;
            document.getElementById('entType').value='purchase';
            document.getElementById('entDate').value=todayJalali();
            document.getElementById('entStoreroom').value=storerooms.length?storerooms[0].id:'';
            document.getElementById('entPersonSearch').value='';document.getElementById('entPersonId').value='';
            document.getElementById('entDesc').value='';
            document.getElementById('entryForm').style.display='block';
            addEntryRow();
        } else {
            document.getElementById('exitTicketId').value='0';
            document.getElementById('exitFormTitle').textContent='حواله خروج جدید';
            document.getElementById('exitItems').innerHTML='';
            exitRowCnt=0;
            document.getElementById('exitType').value='sale';
            document.getElementById('exitDate').value=todayJalali();
            document.getElementById('exitStoreroom').value=storerooms.length?storerooms[0].id:'';
            document.getElementById('exitPersonSearch').value='';document.getElementById('exitPersonId').value='';
            document.getElementById('exitDesc').value='';
            document.getElementById('exitForm').style.display='block';
            addExitRow();
        }
        window.scrollTo({top:0,behavior:'smooth'});
    },50);
};

window.closeForm=function(mode){
    document.getElementById(mode==='entry'?'entryForm':'exitForm').style.display='none';
};

// ===== ردیف ورود =====
window.addEntryRow=function(item){
    var i=++entRowCnt;
    item=item||{};
    var html='<div class="wms-item-row" id="entRow-'+i+'">'
      +'<span style="color:#94a3b8;font-size:.75rem;text-align:center">'+n2fa(i)+'</span>'
      +'<div class="suggest-wrap"><input type="text" class="wms-inp" id="entStuff-'+i+'" placeholder="نام کالا..." autocomplete="off" value="'+esc(item.stuff_name||'')+'" oninput="searchRowStuff(this,'+i+',\'ent\')">'
      +'<input type="hidden" id="entSid-'+i+'" value="'+esc(item.stuff_id||'')+'"><input type="hidden" id="entScode-'+i+'" value="'+esc(item.stuff_code||'')+'">'
      +'<div class="suggest-list" id="entSuggest-'+i+'" style="display:none"></div></div>'
      +'<input type="text" class="wms-inp" id="entUnit-'+i+'" placeholder="عدد" value="'+esc(item.unit||'عدد')+'">'
      +'<input type="number" class="wms-inp" id="entQty-'+i+'" placeholder="تعداد" min="0.001" step="any" value="'+esc(item.qty||1)+'">'
      +'<input type="number" class="wms-inp" id="entPrice-'+i+'" placeholder="قیمت" min="0" value="'+esc(item.unit_price||0)+'">'
      +'<input type="text" class="wms-inp" id="entLot-'+i+'" placeholder="شماره لات" value="'+esc(item.lot_number||'')+'">'
      +'<input type="date" class="wms-inp" id="entExp-'+i+'" value="'+esc(item.expiry_date||'')+'">'
      +'<button onclick="removeRow(\'entRow-'+i+'\')" style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;width:28px;height:28px;font-size:.85rem">✕</button>'
      +'</div>';
    document.getElementById('entItems').insertAdjacentHTML('beforeend',html);
};

// ===== ردیف خروج =====
window.addExitRow=function(item){
    var i=++exitRowCnt;
    item=item||{};
    var html='<div class="wms-item-row" id="exitRow-'+i+'">'
      +'<span style="color:#94a3b8;font-size:.75rem;text-align:center">'+n2fa(i)+'</span>'
      +'<div class="suggest-wrap"><input type="text" class="wms-inp" id="exitStuff-'+i+'" placeholder="نام کالا..." autocomplete="off" value="'+esc(item.stuff_name||'')+'" oninput="searchRowStuff(this,'+i+',\'exit\')">'
      +'<input type="hidden" id="exitSid-'+i+'" value="'+esc(item.stuff_id||'')+'"><input type="hidden" id="exitScode-'+i+'" value="'+esc(item.stuff_code||'')+'">'
      +'<div class="suggest-list" id="exitSuggest-'+i+'" style="display:none"></div></div>'
      +'<input type="text" class="wms-inp" id="exitUnit-'+i+'" placeholder="عدد" value="'+esc(item.unit||'عدد')+'">'
      +'<input type="number" class="wms-inp" id="exitQty-'+i+'" placeholder="تعداد" min="0.001" step="any" value="'+esc(item.qty||1)+'" oninput="checkExitStock('+i+')">'
      +'<input type="number" class="wms-inp" id="exitPrice-'+i+'" placeholder="قیمت فروش" min="0" value="'+esc(item.unit_price||0)+'">'
      +'<div id="fefoBox-'+i+'" style="font-size:.78rem;color:#7c3aed"><input type="hidden" id="exitLot-'+i+'" value="'+esc(item.lot_number||'')+'"><span id="fefoLabel-'+i+'">—</span></div>'
      +'<div id="stockInfo-'+i+'" style="font-size:.78rem;color:#64748b">—</div>'
      +'<button onclick="removeRow(\'exitRow-'+i+'\')" style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;width:28px;height:28px;font-size:.85rem">✕</button>'
      +'</div>';
    document.getElementById('exitItems').insertAdjacentHTML('beforeend',html);
};

window.removeRow=function(id){document.getElementById(id).remove();};

// ===== جستجوی کالا در ردیف =====
var stuffSearchTimer={};
window.searchRowStuff=function(inp,i,mode){
    clearTimeout(stuffSearchTimer[i]);
    stuffSearchTimer[i]=setTimeout(function(){
        var q=inp.value.trim();
        var listEl=document.getElementById(mode+'Suggest-'+i);
        if(q.length<1){listEl.style.display='none';return;}
        post({action:'search_stuffs',q:q}).then(function(res){
            if(!res.results||!res.results.length){listEl.style.display='none';return;}
            listEl.innerHTML=res.results.map(function(s){
                return '<div class="suggest-item" onclick="selectRowStuff('+s.id+',\''+esc(s.name)+'\',\''+esc(s.unit)+'\',\''+esc(s.code||'')+'\','+i+',\''+mode+'\')">'
                    +'<strong>'+esc(s.name)+'</strong>'
                    +(s.code?' <small style="color:#94a3b8">'+esc(s.code)+'</small>':'')
                    +' — موجودی: '+numFmt(s.stock)+' '+esc(s.unit||'')
                    +'</div>';
            }).join('');
            listEl.style.display='block';
        });
    },300);
};

window.selectRowStuff=function(id,name,unit,code,i,mode){
    document.getElementById(mode+'Stuff-'+i).value=name;
    document.getElementById(mode+'Sid-'+i).value=id;
    document.getElementById(mode+'Scode-'+i).value=code;
    document.getElementById(mode+'Unit-'+i).value=unit;
    document.getElementById(mode+'Suggest-'+i).style.display='none';
    if(mode==='exit') loadFEFO(i,id);
};

// ===== FEFO =====
function loadFEFO(rowIdx,stuffId){
    if(!stuffId)return;
    var srId=document.getElementById('exitStoreroom').value;
    post({action:'get_fefo',stuff_id:stuffId,storeroom_id:srId}).then(function(res){
        if(!res.ok)return;
        var label=document.getElementById('fefoLabel-'+rowIdx);
        var info=document.getElementById('stockInfo-'+rowIdx);
        if(!res.lots||!res.lots.length){
            label.innerHTML='<span style="color:#e11d48">⚠️ موجودی ندارد</span>';
            if(info)info.textContent='۰';
            return;
        }
        var first=res.lots[0];
        var daysLeft=first.days_left;
        var cls=daysLeft===null?'':'( daysLeft<=7?\'danger\':daysLeft<=30?\'warn\':\'\' )';
        // پیشنهاد اولین لات (نزدیک‌ترین انقضا)
        document.getElementById('exitLot-'+rowIdx).value=first.lot_number;
        var html='<select onchange="onLotSelect(this,'+rowIdx+')" style="border:1px solid #c4b5fd;border-radius:7px;padding:3px 6px;font-size:.75rem;font-family:Vazirmatn,Tahoma,sans-serif;max-width:180px">';
        res.lots.forEach(function(l){
            var exp=l.expiry_date?l.expiry_date:'بدون انقضا';
            var dStr=l.days_left!==null?' ('+n2fa(l.days_left)+' روز)':'';
            html+='<option value="'+esc(l.lot_number)+'" data-qty="'+l.qty+'" '+(l.lot_number===first.lot_number?'selected':'')+'>لات '+esc(l.lot_number)+' — '+exp+dStr+' — '+numFmt(l.qty)+'</option>';
        });
        html+='</select>';
        label.innerHTML=html;
        if(info)info.innerHTML='<span style="color:#166534;font-weight:700">'+numFmt(res.total_available)+'</span>';
    });
}

window.onLotSelect=function(sel,rowIdx){
    var opt=sel.options[sel.selectedIndex];
    document.getElementById('exitLot-'+rowIdx).value=sel.value;
    var avail=parseFloat(opt.dataset.qty)||0;
    document.getElementById('stockInfo-'+rowIdx).innerHTML='<span style="color:#166534;font-weight:700">'+numFmt(avail)+'</span>';
};

window.refreshExitFefos=function(){
    document.querySelectorAll('[id^="exitSid-"]').forEach(function(el){
        var i=el.id.replace('exitSid-','');
        if(el.value) loadFEFO(i,el.value);
    });
};

window.checkExitStock=function(i){
    var sid=document.getElementById('exitSid-'+i).value;
    var qty=parseFloat(document.getElementById('exitQty-'+i).value)||0;
    var srId=document.getElementById('exitStoreroom').value;
    if(!sid||!srId||!qty) return;
    post({action:'check_stock',stuff_id:sid,storeroom_id:srId,qty:qty}).then(function(res){
        var info=document.getElementById('stockInfo-'+i);
        if(!info)return;
        if(res.enough){
            info.innerHTML='<span style="color:#166534">✅ موجود: '+numFmt(res.available)+'</span>';
        } else {
            info.innerHTML='<span style="color:#dc2626">⚠️ کمبود: '+numFmt(res.short)+'</span>';
        }
    });
};

// ===== جستجوی طرف حساب =====
window.searchPersons=function(inp,hiddenId,listId){
    var q=inp.value.trim();
    var listEl=document.getElementById(listId);
    if(q.length<1){listEl.style.display='none';return;}
    post({action:'search_persons',q:q}).then(function(res){
        if(!res.results||!res.results.length){listEl.style.display='none';return;}
        listEl.innerHTML=res.results.map(function(p){
            return '<div class="suggest-item" onclick="selectPerson(\''+hiddenId+'\',\''+listId+'\','+p.id+',\''+esc(p.label||'')+'\',\''+inp.id+'\')">'+esc(p.label||'')+'</div>';
        }).join('');
        listEl.style.display='block';
    });
};
window.selectPerson=function(hidId,listId,id,name,inpId){
    document.getElementById(hidId).value=id;
    document.getElementById(inpId).value=name;
    document.getElementById(listId).style.display='none';
};
document.addEventListener('click',function(e){
    if(!e.target.classList.contains('wms-inp')&&!e.target.classList.contains('suggest-item')){
        document.querySelectorAll('.suggest-list').forEach(function(el){el.style.display='none';});
    }
});

// ===== ذخیره بلیت =====
window.saveTicket=function(mode,submitMode){
    var isEntry=(mode==='entry');
    var prefix=isEntry?'ent':'exit';
    var storeroomId=document.getElementById(prefix+'Storeroom').value;
    var date=document.getElementById(prefix+'Date').value;
    if(!storeroomId){alert('انتخاب انبار الزامی است');return;}
    if(!date){alert('تاریخ الزامی است');return;}

    var fd=new FormData();
    fd.append('action','save_ticket');
    fd.append('ticket_id',document.getElementById(prefix+'TicketId').value||'0');
    fd.append('type',document.getElementById(prefix+'Type').value);
    fd.append('ticket_date',date);
    fd.append('storeroom_id',storeroomId);
    fd.append('person_id',document.getElementById(prefix+'PersonId').value||'0');
    fd.append('person_name',document.getElementById(prefix+'PersonSearch').value||'');
    fd.append('description',document.getElementById(prefix+'Desc').value||'');
    if(submitMode==='submit'||submitMode==='approve') fd.append('submit','1');

    var rowSelector=isEntry?'[id^="entRow-"]':'[id^="exitRow-"]';
    var idx=0;
    document.querySelectorAll(rowSelector).forEach(function(row){
        var i=row.id.replace(prefix+'Row-','');
        var desc=document.getElementById(prefix+'Stuff-'+i).value.trim();
        if(!desc)return;
        fd.append('items['+idx+'][stuff_id]',document.getElementById(prefix+'Sid-'+i).value||'');
        fd.append('items['+idx+'][stuff_code]',document.getElementById(prefix+'Scode-'+i).value||'');
        fd.append('items['+idx+'][description]',desc);
        fd.append('items['+idx+'][unit]',document.getElementById(prefix+'Unit-'+i).value||'عدد');
        fd.append('items['+idx+'][qty]',document.getElementById(prefix+'Qty-'+i).value||'1');
        fd.append('items['+idx+'][unit_price]',document.getElementById(prefix+'Price-'+i).value||'0');
        if(isEntry){
            fd.append('items['+idx+'][lot_number]',document.getElementById('entLot-'+i).value||'');
            fd.append('items['+idx+'][expiry_date]',document.getElementById('entExp-'+i).value||'');
        } else {
            fd.append('items['+idx+'][lot_number]',document.getElementById('exitLot-'+i).value||'');
        }
        idx++;
    });
    if(idx===0){alert('حداقل یک ردیف با نام کالا وارد کنید');return;}

    post(fd,true).then(function(res){
        alert(res.ok?'✅ '+res.msg:'❌ '+res.msg);
        if(res.ok){
            if(submitMode==='approve'){
                // تأیید مستقیم
                post({action:'approve_ticket',id:res.ticket_id,note:''}).then(function(r2){
                    alert(r2.ok?'✅ تأیید شد و موجودی به‌روز شد':'⚠️ ذخیره شد اما: '+r2.msg);
                    closeForm(mode);
                    loadList(mode);
                    loadDash();
                });
            } else {
                closeForm(mode);
                loadList(mode);
                loadDash();
            }
        }
    });
};

// ===== ویرایش بلیت =====
window.editTicket=function(id,mode){
    post({action:'get_ticket',id:id}).then(function(res){
        if(!res.ok){alert('❌ '+res.msg);return;}
        var t=res.ticket;
        var isEntry=(mode==='entry');
        var prefix=isEntry?'ent':'exit';
        document.getElementById(prefix+'TicketId').value=t.id;
        document.getElementById(prefix+'FormTitle').textContent='ویرایش بلیت '+t.ticket_number;
        document.getElementById(prefix+'Type').value=t.type;
        document.getElementById(prefix+'Date').value=t.ticket_date||'';
        document.getElementById(prefix+'Storeroom').value=t.storeroom_id||'';
        document.getElementById(prefix+'PersonSearch').value=t.person_name||'';
        document.getElementById(prefix+'PersonId').value=t.person_id||'';
        document.getElementById(prefix+'Desc').value=t.description||'';
        document.getElementById(prefix+'Items').innerHTML='';
        if(isEntry){entRowCnt=0;(t.items||[]).forEach(function(item){addEntryRow(item);});}
        else{exitRowCnt=0;(t.items||[]).forEach(function(item){addExitRow(item);});}
        document.getElementById(prefix+'Form').style.display='block';
        window.scrollTo({top:0,behavior:'smooth'});
    });
};

// ===== حذف =====
window.deleteTicket=function(id,mode){
    if(!confirm('آیا از حذف این بلیت اطمینان دارید؟'))return;
    post({action:'delete_ticket',id:id}).then(function(res){
        alert(res.ok?'✅ '+res.msg:'❌ '+res.msg);
        if(res.ok){loadList(mode);loadDash();}
    });
};

// ===== تأیید / رد =====
window.openApprove=function(id){
    document.getElementById('approveId').value=id;
    document.getElementById('approveNote').value='';
    document.getElementById('approveModal').style.display='flex';
};
window.doApprove=function(){
    var id=document.getElementById('approveId').value;
    var note=document.getElementById('approveNote').value;
    post({action:'approve_ticket',id:id,note:note}).then(function(res){
        document.getElementById('approveModal').style.display='none';
        alert(res.ok?'✅ '+res.msg:'❌ '+res.msg);
        if(res.ok){loadDash();loadPending();if(document.getElementById('tab-entry').classList.contains('active'))loadList('entry');if(document.getElementById('tab-exit').classList.contains('active'))loadList('exit');}
    });
};
window.openReject=function(id){
    document.getElementById('rejectId').value=id;
    document.getElementById('rejectReason').value='';
    document.getElementById('rejectModal').style.display='flex';
};
window.doReject=function(){
    var id=document.getElementById('rejectId').value;
    var reason=document.getElementById('rejectReason').value.trim();
    if(!reason){alert('دلیل رد الزامی است');return;}
    post({action:'reject_ticket',id:id,reason:reason}).then(function(res){
        document.getElementById('rejectModal').style.display='none';
        alert(res.ok?'✅ '+res.msg:'❌ '+res.msg);
        if(res.ok){loadDash();loadPending();}
    });
};

// ===== لات‌ها =====
function loadLots(){
    var stuffId=document.getElementById('lotStuffId').value||0;
    var srId=document.getElementById('lotSrFilter').value||0;
    var showAll=document.getElementById('lotShowAll').checked?1:0;
    var el=document.getElementById('lotsTableWrap');
    el.innerHTML='<div style="text-align:center;padding:30px;color:#9ca3af">در حال بارگذاری...</div>';
    post({action:'list_lots',stuff_id:stuffId,storeroom_id:srId,show_all:showAll}).then(function(res){
        if(!res.ok||!res.rows||!res.rows.length){el.innerHTML='<div style="text-align:center;color:#9ca3af;padding:40px">نتیجه‌ای یافت نشد</div>';return;}
        var h='<div style="overflow-x:auto"><table class="fin-table"><thead><tr><th>کالا</th><th>لات</th><th>انبار</th><th>موجودی</th><th>ورودی</th><th>تاریخ انقضا</th><th>روز مانده</th><th>قیمت خرید</th><th>تأمین‌کننده</th><th>وضعیت</th></tr></thead><tbody>';
        res.rows.forEach(function(r){
            var dl=r.days_left;
            var dlStr=dl===null||dl===''?'—':n2fa(dl)+' روز';
            var dlColor=dl===null?'':dl<=0?'color:#dc2626;font-weight:700':dl<=7?'color:#d97706;font-weight:700':dl<=30?'color:#f59e0b':'color:#166534';
            var statusBadge={'active':'<span style="background:#dcfce7;color:#166534;padding:2px 9px;border-radius:20px;font-size:.75rem">فعال</span>','exhausted':'<span style="background:#f1f5f9;color:#64748b;padding:2px 9px;border-radius:20px;font-size:.75rem">تمام شده</span>','expired':'<span style="background:#fee2e2;color:#991b1b;padding:2px 9px;border-radius:20px;font-size:.75rem">منقضی</span>'}[r.status]||r.status;
            h+='<tr>'
              +'<td>'+esc(r.stuff_name||'—')+'</td>'
              +'<td style="font-weight:700;direction:ltr">'+esc(r.lot_number||'—')+'</td>'
              +'<td>'+esc(r.storeroom_name||'—')+'</td>'
              +'<td style="font-weight:800;color:#0891b2">'+numFmt(r.qty)+' '+esc(r.unit||'')+'</td>'
              +'<td style="color:#64748b">'+numFmt(r.entry_qty)+'</td>'
              +'<td style="direction:ltr">'+esc(r.expiry_date||'—')+'</td>'
              +'<td style="'+dlColor+'">'+dlStr+'</td>'
              +'<td style="direction:ltr">'+numFmt(r.purchase_price)+'</td>'
              +'<td>'+esc(r.supplier_name||'—')+'</td>'
              +'<td>'+statusBadge+'</td>'
              +'</tr>';
        });
        h+='</tbody></table></div>';
        el.innerHTML=h;
    });
}

window.searchLotStuff=function(q){
    var listEl=document.getElementById('lotStuffList');
    if(q.length<1){listEl.style.display='none';return;}
    post({action:'search_stuffs',q:q}).then(function(res){
        if(!res.results||!res.results.length){listEl.style.display='none';return;}
        listEl.innerHTML=res.results.map(function(s){
            return '<div class="suggest-item" onclick="document.getElementById(\'lotStuffId\').value='+s.id+';document.getElementById(\'lotStuffSearch\').value=\''+esc(s.name)+'\';document.getElementById(\'lotStuffList\').style.display=\'none\';loadLots();">'+esc(s.name)+'</div>';
        }).join('');
        listEl.style.display='block';
    });
};

// ===== برچسب نوع =====
function typeLabel(t){
    var m={purchase:'خرید',sales_return:'برگشت از فروش',consignment_in:'امانت دریافتی',transfer_in:'انتقال دریافتی',initial:'موجودی اولیه',sale:'فروش',supplier_return:'برگشت به تأمین',consignment_out:'امانت خروجی',internal:'مصرف داخلی',transfer_out:'انتقال خروجی',sample:'نمونه',receipt:'ورود',dispatch:'خروج',transfer:'انتقال',return:'مرجوعی'};
    return m[t]||t;
}

// ===== تاریخ امروز جلالی =====
function todayJalali(){
    var now=new Date();
    return '<?= jdate('Y/m/d') ?>';
}

// ===== راه‌اندازی =====
loadStorerooms();
loadDash();

})();
</script>

<?php
$basePath = '../../';
require_once __DIR__ . '/../../templates/footer.php';
