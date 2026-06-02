<?php
/*
 * فایل: testing_agent.php
 * ایجنت تست خودکار — آتنا زیست درمان
 * این فایل تمام نقاط کلیدی نرم‌افزار را شبیه یک کاربر واقعی تست می‌کند.
 */
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// دسترسی: فقط ادمین
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
$rawRole = $_SESSION['role'] ?? '';
$stmtChk = $pdo->prepare("SELECT name, is_system FROM roles WHERE id=? OR name=?");
$stmtChk->execute([$rawRole, $rawRole]);
$roleData = $stmtChk->fetch();
if (!$roleData || ($roleData['name'] !== 'admin' && $roleData['is_system'] != 1)) {
    die('<div style="text-align:center;padding:50px;font-family:Tahoma;color:red;">⛔ فقط ادمین می‌تواند این صفحه را مشاهده کند.</div>');
}

// ── اجرای یک سوئیت تست ────────────────────────────────────────
$results = [];
$testCount = 0;
$passCount = 0;
$failCount = 0;

function runTest(string $name, callable $fn): array {
    global $testCount, $passCount, $failCount;
    $testCount++;
    try {
        $result = $fn();
        if ($result === true || (is_array($result) && ($result['pass'] ?? false))) {
            $passCount++;
            $detail = is_array($result) ? ($result['detail'] ?? '') : '';
            return ['name' => $name, 'pass' => true, 'msg' => '✅ موفق', 'detail' => $detail];
        } else {
            $failCount++;
            $msg = is_array($result) ? ($result['msg'] ?? 'خطای ناشناخته') : (string)$result;
            return ['name' => $name, 'pass' => false, 'msg' => '❌ ' . $msg, 'detail' => ''];
        }
    } catch (Throwable $e) {
        $failCount++;
        return ['name' => $name, 'pass' => false, 'msg' => '❌ Exception: ' . $e->getMessage(), 'detail' => ''];
    }
}

// ── تست ۱: اتصال دیتابیس ──────────────────────────────────────
$results[] = runTest('اتصال به دیتابیس', function() use ($pdo) {
    $r = $pdo->query("SELECT 1")->fetchColumn();
    return $r == 1 ? true : ['pass'=>false,'msg'=>'کوئری SELECT 1 نتیجه‌ای برنگرداند'];
});

// ── تست ۲: جداول اصلی وجود دارند ─────────────────────────────
$coreTables = ['users','roles','settings','departments','fiscal_years'];
foreach ($coreTables as $tbl) {
    $results[] = runTest("جدول اصلی: $tbl", function() use ($pdo, $tbl) {
        $r = $pdo->query("SHOW TABLES LIKE '$tbl'")->fetchColumn();
        return $r ? true : ['pass'=>false,'msg'=>"جدول $tbl وجود ندارد"];
    });
}

// ── تست ۳: جداول CRM ─────────────────────────────────────────
$crmTables = ['crm_boards','crm_opportunities','crm_contacts','crm_provinces'];
foreach ($crmTables as $tbl) {
    $results[] = runTest("جدول CRM: $tbl", function() use ($pdo, $tbl) {
        $r = $pdo->query("SHOW TABLES LIKE '$tbl'")->fetchColumn();
        return $r ? true : ['pass'=>false,'msg'=>"جدول $tbl وجود ندارد"];
    });
}

// ── تست ۴: جداول حسابداری مرحله ۲ ───────────────────────────
$finTables = ['fin_persons','fin_chart_of_accounts','fin_docs','fin_doc_rows','fin_invoices','fin_invoice_items','fin_bank_accounts','fin_cashdesks','fin_cheques'];
foreach ($finTables as $tbl) {
    $results[] = runTest("جدول حسابداری: $tbl", function() use ($pdo, $tbl) {
        $r = $pdo->query("SHOW TABLES LIKE '$tbl'")->fetchColumn();
        return $r ? true : ['pass'=>false,'msg'=>"جدول $tbl وجود ندارد — migration اجرا نشده"];
    });
}

// ── تست ۵: پلان حساب‌های پیش‌فرض seed شده ────────────────────
$results[] = runTest('پلان حساب‌های ایران seed شده', function() use ($pdo) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM fin_chart_of_accounts WHERE is_system=1")->fetchColumn();
        return $count >= 5 ? ['pass'=>true,'detail'=>"$count حساب سیستمی"] : ['pass'=>false,'msg'=>"فقط $count حساب سیستمی — seed ناقص"];
    } catch (PDOException $e) {
        return ['pass'=>false,'msg'=>'جدول وجود ندارد: ' . $e->getMessage()];
    }
});

// ── تست ۶: سال مالی فعال ─────────────────────────────────────
$results[] = runTest('سال مالی فعال وجود دارد', function() use ($pdo) {
    $r = $pdo->query("SELECT id,title FROM fiscal_years WHERE is_current=1 AND status='active' LIMIT 1")->fetch();
    return $r ? ['pass'=>true,'detail'=>"سال: " . $r['title']] : ['pass'=>false,'msg'=>'سال مالی فعال تعریف نشده'];
});

// ── تست ۷: کاربران سیستم ─────────────────────────────────────
$results[] = runTest('حداقل یک کاربر ادمین وجود دارد', function() use ($pdo) {
    $count = $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON (r.id=u.role_id OR r.name=u.role) WHERE r.name='admin' OR r.is_system=1")->fetchColumn();
    return $count > 0 ? ['pass'=>true,'detail'=>"$count کاربر ادمین"] : ['pass'=>false,'msg'=>'هیچ ادمینی در سیستم نیست'];
});

// ── تست ۸: CRUD طرف حساب (INSERT + SELECT + DELETE) ──────────
$results[] = runTest('CRUD طرف حساب — ایجاد و حذف تستی', function() use ($pdo) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO fin_persons (name,type,phone,created_at,updated_at) VALUES (?,?,?,NOW(),NOW())");
        $stmt->execute(['تست‌کننده خودکار', 'customer', '09000000000']);
        $newId = $pdo->lastInsertId();
        if (!$newId) throw new Exception('INSERT ناموفق');
        $row = $pdo->prepare("SELECT id,name FROM fin_persons WHERE id=?");
        $row->execute([$newId]);
        $fetched = $row->fetch();
        if (!$fetched || $fetched['name'] !== 'تست‌کننده خودکار') throw new Exception('SELECT ناموفق');
        $pdo->prepare("DELETE FROM fin_persons WHERE id=?")->execute([$newId]);
        $pdo->commit();
        return ['pass'=>true,'detail'=>"id=$newId ایجاد و حذف شد"];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['pass'=>false,'msg'=>$e->getMessage()];
    }
});

// ── تست ۹: CRUD فاکتور (INSERT + SELECT + DELETE) ────────────
$results[] = runTest('CRUD فاکتور — ایجاد و حذف تستی', function() use ($pdo) {
    try {
        $pdo->beginTransaction();
        $fyId = $pdo->query("SELECT id FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetchColumn() ?: 1;
        $stmt = $pdo->prepare("INSERT INTO fin_invoices (invoice_number,type,customer_name,invoice_date,subtotal,discount,tax,total_amount,paid_amount,status,fiscal_year_id,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
        $stmt->execute(['TEST-999','sell','مشتری تستی','1404/03/12',1000000,0,90000,1090000,0,'draft',$fyId,(int)$_SESSION['user_id']]);
        $invId = $pdo->lastInsertId();
        if (!$invId) throw new Exception('INSERT فاکتور ناموفق');
        $check = $pdo->prepare("SELECT total_amount FROM fin_invoices WHERE id=?");
        $check->execute([$invId]);
        $amt = $check->fetchColumn();
        if ($amt != 1090000) throw new Exception("مبلغ نادرست: $amt");
        $pdo->prepare("DELETE FROM fin_invoices WHERE id=?")->execute([$invId]);
        $pdo->commit();
        return ['pass'=>true,'detail'=>"فاکتور id=$invId , مبلغ 1,090,000 تومان"];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['pass'=>false,'msg'=>$e->getMessage()];
    }
});

// ── تست ۱۰: CRUD چک ─────────────────────────────────────────
$results[] = runTest('CRUD چک — ایجاد و حذف تستی', function() use ($pdo) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO fin_cheques (cheque_number,type,bank_name,amount,due_date,due_date_j,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())");
        $stmt->execute(['CHK-TEST-1','received','ملت',5000000,'2025-09-06','1404/06/15','pending',(int)$_SESSION['user_id']]);
        $chqId = $pdo->lastInsertId();
        if (!$chqId) throw new Exception('INSERT چک ناموفق');
        $pdo->prepare("DELETE FROM fin_cheques WHERE id=?")->execute([$chqId]);
        $pdo->commit();
        return ['pass'=>true,'detail'=>"چک id=$chqId ایجاد و حذف شد"];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['pass'=>false,'msg'=>$e->getMessage()];
    }
});

// ── تست ۱۱: حساب بانکی ───────────────────────────────────────
$results[] = runTest('CRUD حساب بانکی', function() use ($pdo) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO fin_bank_accounts (bank_name,balance,is_active,created_at) VALUES (?,?,?,NOW())");
        $stmt->execute(['بانک تستی', 0, 1]);
        $bid = $pdo->lastInsertId();
        $pdo->prepare("DELETE FROM fin_bank_accounts WHERE id=?")->execute([$bid]);
        $pdo->commit();
        return ['pass'=>true,'detail'=>"حساب id=$bid"];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['pass'=>false,'msg'=>$e->getMessage()];
    }
});

// ── تست ۱۲: منطق حسابداری دوطرفه ────────────────────────────
$results[] = runTest('منطق سند حسابداری دوطرفه (تراز بدهکار/بستانکار)', function() use ($pdo) {
    try {
        $pdo->beginTransaction();
        $fyId = $pdo->query("SELECT id FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetchColumn() ?: 1;
        // ایجاد سند
        $pdo->prepare("INSERT INTO fin_docs (doc_number,doc_date,type,description,fiscal_year_id,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())")->execute(['TEST-DOC-1',jdate('Y/m/d'),'manual','تست دوطرفه',$fyId,(int)$_SESSION['user_id']]);
        $docId = $pdo->lastInsertId();
        // ردیف بدهکار (حسابهای دریافتنی)
        $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,debit,credit) VALUES (?,?,?,?)")->execute([$docId, 1, 1000000, 0]);
        // ردیف بستانکار (درآمد فروش)
        $pdo->prepare("INSERT INTO fin_doc_rows (doc_id,account_id,debit,credit) VALUES (?,?,?,?)")->execute([$docId, 1, 0, 1000000]);
        // بررسی تراز
        $chk = $pdo->prepare("SELECT SUM(debit)-SUM(credit) AS diff FROM fin_doc_rows WHERE doc_id=?");
        $chk->execute([$docId]);
        $diff = (int)$chk->fetchColumn();
        // پاکسازی
        $pdo->prepare("DELETE FROM fin_doc_rows WHERE doc_id=?")->execute([$docId]);
        $pdo->prepare("DELETE FROM fin_docs WHERE id=?")->execute([$docId]);
        $pdo->commit();
        return $diff === 0 ? ['pass'=>true,'detail'=>'سند متراز است (بدهکار = بستانکار = 1,000,000)'] : ['pass'=>false,'msg'=>"سند نامتراز: diff=$diff"];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['pass'=>false,'msg'=>$e->getMessage()];
    }
});

// ── تست ۱۳: فایل‌های PHP وجود دارند ───────────────────────────
$phpFiles = [
    'admin/fin_accounting_dashboard.php' => 'داشبورد حسابداری',
    'admin/fin_invoice_sell.php'         => 'فاکتور فروش',
    'admin/fin_persons.php'              => 'طرف حساب‌ها',
    'admin/fin_accounts.php'             => 'پلان حساب‌ها',
    'admin/fin_cheques.php'              => 'مدیریت چک‌ها',
    'admin/crm_provinces.php'            => 'مدیریت استان‌ها',
    'admin/crm_weekplan.php'             => 'برنامه هفتگی',
    'admin/crm_receivables.php'          => 'مطالبات',
    // مرحله ۳ — انبارداری
    'admin/inv_dashboard.php'            => 'داشبورد انبار',
    'admin/inv_storerooms.php'           => 'مدیریت انبارها',
    'admin/inv_receipts.php'             => 'رسید و حواله',
    'admin/inv_kardex.php'               => 'کاردکس کالا',
];
$basedir = __DIR__ . '/../../public_html/';
foreach ($phpFiles as $path => $label) {
    $fullpath = __DIR__ . '/' . (strpos($path,'admin/')===0 ? basename($path) : $path);
    // مسیر نسبی از پوشه admin
    $fullpath2 = __DIR__ . '/../' . $path;
    $exists = file_exists(__DIR__ . '/' . basename($path));
    $results[] = runTest("فایل موجود: $label", function() use ($path, $exists, $label) {
        return $exists ? ['pass'=>true,'detail'=>$path] : ['pass'=>false,'msg'=>"$path یافت نشد"];
    });
}

// ── تست ۱۴: جداول انبارداری مرحله ۳ ─────────────────────────
$invTables = ['inv_storerooms','inv_tickets','inv_ticket_items'];
foreach ($invTables as $tbl) {
    $results[] = runTest("جدول انبار: $tbl", function() use ($pdo, $tbl) {
        $r = $pdo->query("SHOW TABLES LIKE '$tbl'")->fetchColumn();
        return $r ? true : ['pass'=>false,'msg'=>"جدول $tbl وجود ندارد — phase3 migration اجرا نشده"];
    });
}

// ── تست ۱۵: CRUD رسید انبار ──────────────────────────────────
$results[] = runTest('CRUD رسید انبار — ایجاد و حذف تستی', function() use ($pdo) {
    try {
        $pdo->beginTransaction();
        // یک انبار پیش‌فرض پیدا کن
        $srId = $pdo->query("SELECT id FROM inv_storerooms LIMIT 1")->fetchColumn();
        if (!$srId) throw new Exception('هیچ انباری تعریف نشده (seed اجرا نشده)');
        $stmt = $pdo->prepare("INSERT INTO inv_tickets (ticket_number,type,ticket_date,ticket_date_g,storeroom_id,description,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())");
        $stmt->execute(['TEST-RC-999','receipt',jdate('Y/m/d'),date('Y-m-d'),$srId,'تست خودکار','draft',(int)$_SESSION['user_id']]);
        $tid = $pdo->lastInsertId();
        if (!$tid) throw new Exception('INSERT رسید ناموفق');
        $pdo->prepare("DELETE FROM inv_tickets WHERE id=?")->execute([$tid]);
        $pdo->commit();
        return ['pass'=>true,'detail'=>"رسید id=$tid ایجاد و حذف شد"];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['pass'=>false,'msg'=>$e->getMessage()];
    }
});

// ── تست CSRF توکن ─────────────────────────────────────────────
$results[] = runTest('سیستم CSRF Token فعال است', function() {
    $token = csrf_token();
    return (!empty($token) && strlen($token) >= 32) ? ['pass'=>true,'detail'=>"طول توکن: " . strlen($token)] : ['pass'=>false,'msg'=>'توکن CSRF خالی یا کوتاه'];
});

// ── تست ۱۵: تاریخ شمسی ──────────────────────────────────────
$results[] = runTest('تبدیل تاریخ شمسی صحیح است', function() {
    $jdate = jdate('Y/m/d', mktime(0,0,0,3,21,2025));
    return (strpos($jdate,'1404') !== false) ? ['pass'=>true,'detail'=>"تاریخ: $jdate"] : ['pass'=>false,'msg'=>"تاریخ غلط: $jdate"];
});

// ── صفحه تست ─────────────────────────────────────────────────
$pageTitle = 'ایجنت تست خودکار';
$basePath  = '../../';
include __DIR__ . '/../../templates/header.php';
?>
<style>
.ta-wrap { max-width: 960px; margin: 0 auto; padding: 24px; }
.ta-header {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    border-radius: 18px;
    padding: 28px 32px;
    color: #fff;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.ta-header h1 { font-size: 1.4rem; font-weight: 900; margin: 0 0 6px; }
.ta-header p  { font-size: 0.85rem; opacity: 0.75; margin: 0; }
.ta-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}
.ta-sum-card {
    border-radius: 14px;
    padding: 20px 22px;
    text-align: center;
    color: #fff;
    font-weight: 900;
}
.ta-sum-card.all   { background: linear-gradient(135deg,#2563eb,#3b82f6); }
.ta-sum-card.pass  { background: linear-gradient(135deg,#059669,#10b981); }
.ta-sum-card.fail  { background: linear-gradient(135deg,#e11d48,#f43f5e); }
.ta-sum-num { font-size: 2.4rem; line-height: 1; margin-bottom: 6px; }
.ta-sum-lbl { font-size: 0.8rem; opacity: 0.88; }
.ta-group {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    margin-bottom: 20px;
    overflow: hidden;
}
.ta-group-title {
    background: #f8fafc;
    padding: 13px 20px;
    font-size: 0.85rem;
    font-weight: 800;
    color: #475569;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ta-row {
    display: flex;
    align-items: center;
    padding: 13px 20px;
    border-bottom: 1px solid #f1f5f9;
    gap: 14px;
    font-size: 0.875rem;
    transition: background 0.15s;
}
.ta-row:last-child { border-bottom: none; }
.ta-row:hover { background: #fafafa; }
.ta-row.pass { border-right: 3px solid #059669; }
.ta-row.fail { border-right: 3px solid #e11d48; background: #fffafa; }
.ta-row-name { flex: 1; font-weight: 600; color: #1e293b; }
.ta-row-msg  { font-weight: 700; white-space: nowrap; }
.ta-row-detail { font-size: 0.78rem; color: #94a3b8; margin-top: 2px; }
.ta-pass-rate {
    text-align: center;
    margin-bottom: 24px;
    font-size: 0.9rem;
    color: #475569;
    font-weight: 700;
}
.ta-progress { height: 10px; background: #e2e8f0; border-radius: 10px; margin-bottom: 6px; overflow: hidden; }
.ta-progress-bar { height: 100%; border-radius: 10px; background: linear-gradient(90deg,#059669,#34d399); transition: width 0.5s; }
.ta-btn-run {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 22px; border-radius: 10px;
    background: #2563eb; color: #fff; font-family: inherit;
    font-size: 0.9rem; font-weight: 700; border: none; cursor: pointer;
    transition: all 0.2s;
}
.ta-btn-run:hover { background: #1d4ed8; transform: translateY(-1px); }
</style>

<div class="ta-wrap">
    <!-- هدر -->
    <div class="ta-header">
        <div>
            <h1>🤖 ایجنت تست خودکار</h1>
            <p>بررسی جامع سلامت نرم‌افزار — <?php echo jdate('l، j F Y'); ?></p>
        </div>
        <button class="ta-btn-run" onclick="location.reload()">🔄 اجرای مجدد</button>
    </div>

    <!-- خلاصه نتایج -->
    <div class="ta-summary">
        <div class="ta-sum-card all">
            <div class="ta-sum-num"><?php echo $testCount; ?></div>
            <div class="ta-sum-lbl">کل تست‌ها</div>
        </div>
        <div class="ta-sum-card pass">
            <div class="ta-sum-num"><?php echo $passCount; ?></div>
            <div class="ta-sum-lbl">موفق</div>
        </div>
        <div class="ta-sum-card fail">
            <div class="ta-sum-num"><?php echo $failCount; ?></div>
            <div class="ta-sum-lbl">ناموفق</div>
        </div>
    </div>

    <!-- نوار پیشرفت -->
    <?php $rate = $testCount > 0 ? round($passCount / $testCount * 100) : 0; ?>
    <div class="ta-pass-rate">نرخ موفقیت: <?php echo $rate; ?>%</div>
    <div class="ta-progress" style="margin-bottom:24px;">
        <div class="ta-progress-bar" style="width:<?php echo $rate; ?>%;
            background:<?php echo $rate >= 80 ? 'linear-gradient(90deg,#059669,#34d399)' : ($rate >= 50 ? 'linear-gradient(90deg,#d97706,#fbbf24)' : 'linear-gradient(90deg,#e11d48,#f87171)'); ?>;"></div>
    </div>

    <?php
    // گروه‌بندی نتایج
    $groups = [
        'زیرساخت و دیتابیس' => array_filter($results, fn($r) => str_contains($r['name'],'دیتابیس') || str_contains($r['name'],'جدول اصلی') || str_contains($r['name'],'سال مالی') || str_contains($r['name'],'کاربر') || str_contains($r['name'],'CSRF') || str_contains($r['name'],'تاریخ')),
        'جداول CRM'          => array_filter($results, fn($r) => str_contains($r['name'],'CRM')),
        'جداول حسابداری'     => array_filter($results, fn($r) => str_contains($r['name'],'حسابداری')),
        'پلان حساب و Seed'   => array_filter($results, fn($r) => str_contains($r['name'],'پلان') || str_contains($r['name'],'seed')),
        'تست CRUD'           => array_filter($results, fn($r) => str_contains($r['name'],'CRUD') || str_contains($r['name'],'منطق')),
        'فایل‌های PHP'        => array_filter($results, fn($r) => str_contains($r['name'],'فایل')),
    ];
    foreach ($groups as $groupTitle => $groupTests):
        if (empty($groupTests)) continue;
        $gPass = count(array_filter($groupTests, fn($r) => $r['pass']));
        $gTotal = count($groupTests);
        $icon = $gPass === $gTotal ? '✅' : ($gPass === 0 ? '❌' : '⚠️');
    ?>
    <div class="ta-group">
        <div class="ta-group-title">
            <?php echo $icon; ?> <?php echo $groupTitle; ?>
            <span style="margin-right:auto;font-size:0.78rem;font-weight:700;
                color:<?php echo $gPass==$gTotal?'#059669':($gPass==0?'#e11d48':'#d97706'); ?>">
                <?php echo $gPass; ?>/<?php echo $gTotal; ?>
            </span>
        </div>
        <?php foreach ($groupTests as $test): ?>
        <div class="ta-row <?php echo $test['pass'] ? 'pass' : 'fail'; ?>">
            <div style="flex:1;">
                <div class="ta-row-name"><?php echo htmlspecialchars($test['name']); ?></div>
                <?php if ($test['detail']): ?>
                <div class="ta-row-detail"><?php echo htmlspecialchars($test['detail']); ?></div>
                <?php endif; ?>
            </div>
            <div class="ta-row-msg"><?php echo $test['msg']; ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- نتیجه نهایی -->
    <div style="text-align:center;padding:20px;background:#f8fafc;border-radius:14px;border:1px solid #e2e8f0;">
        <?php if ($failCount === 0): ?>
        <div style="font-size:2rem;margin-bottom:8px;">🎉</div>
        <div style="font-weight:900;color:#059669;font-size:1.1rem;">همه تست‌ها موفق بودند!</div>
        <div style="color:#64748b;font-size:0.85rem;margin-top:4px;">نرم‌افزار آماده استفاده است.</div>
        <?php elseif ($failCount <= 3): ?>
        <div style="font-size:2rem;margin-bottom:8px;">⚠️</div>
        <div style="font-weight:900;color:#d97706;font-size:1.1rem;"><?php echo $failCount; ?> تست ناموفق</div>
        <div style="color:#64748b;font-size:0.85rem;margin-top:4px;">موارد قرمز را بررسی کنید. احتمالاً migration SQL اجرا نشده.</div>
        <?php else: ?>
        <div style="font-size:2rem;margin-bottom:8px;">🔴</div>
        <div style="font-weight:900;color:#e11d48;font-size:1.1rem;"><?php echo $failCount; ?> تست ناموفق</div>
        <div style="color:#64748b;font-size:0.85rem;margin-top:4px;">لطفاً ابتدا فایل migration SQL را روی سرور اجرا کنید.</div>
        <div style="margin-top:12px;background:#fff;border:1px solid #fecdd3;border-radius:10px;padding:12px 16px;text-align:right;font-size:0.82rem;color:#9f1239;">
            <strong>راه‌حل:</strong> فایل <code>db_migrations/phase2_accounting.sql</code> را در phpMyAdmin یا MySQL CLI اجرا کنید.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
