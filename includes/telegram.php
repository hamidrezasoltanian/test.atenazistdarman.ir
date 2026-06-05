<?php
// ──────────────────────────────────────────────────────────────
// تلگرام بات — ارسال پیام و گزارش‌های خودکار
// ──────────────────────────────────────────────────────────────

function telegramSend(string $chatId, string $text, PDO $pdo = null): bool {
    if ($pdo) {
        $st = $pdo->prepare("SELECT value FROM settings WHERE `key`=?");
        $st->execute(['telegram_bot_token']);
        $token = $st->fetchColumn();
        $st->execute(['telegram_enabled']);
        $enabled = $st->fetchColumn();
        if (!$enabled || !$token) return false;
    } else {
        return false;
    }
    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = http_build_query([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $data,
        'timeout' => 8,
        'ignore_errors' => true,
    ]]);
    $result = @file_get_contents($url, false, $ctx);
    return $result !== false;
}

function telegramDailyReport(PDO $pdo): bool {
    $st = $pdo->prepare("SELECT value FROM settings WHERE `key`=?");
    $st->execute(['telegram_chat_id']);
    $chatId = $st->fetchColumn();
    if (!$chatId) return false;

    $today = jdate('Y/m/d');

    // فروش امروز
    $st = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM fin_invoices WHERE type='sell' AND status='confirmed' AND invoice_date=CURDATE()");
    $todaySell = number_format((int)$st->fetchColumn());

    // چک‌های معوق
    $st = $pdo->query("SELECT COUNT(*) FROM fin_cheques WHERE status='pending' AND due_date<=CURDATE()");
    $overdueChecks = (int)$st->fetchColumn();

    // موجودی منقضی
    $st = $pdo->query("SELECT COUNT(DISTINCT stuff_id) FROM inv_batch_stock WHERE qty>0 AND expiry_date IS NOT NULL AND expiry_date < '".jdate('Y/m/d')."' AND is_deleted=0");
    $expiredItems = (int)$st->fetchColumn();

    // فرصت‌های فروش فعال
    $st = $pdo->query("SELECT COUNT(*) FROM crm_opportunities WHERE is_deleted=0 AND status!='won' AND status!='lost'");
    $activeOpps = (int)$st->fetchColumn();

    $msg  = "📊 <b>گزارش روزانه آتنا زیست درمان</b>\n";
    $msg .= "📅 {$today}\n";
    $msg .= "──────────────\n";
    $msg .= "💰 فروش امروز: <b>{$todaySell} ريال</b>\n";
    $msg .= "📋 فرصت‌های فروش فعال: <b>{$activeOpps}</b>\n";
    if ($overdueChecks > 0)
        $msg .= "⚠️ چک‌های معوق: <b>{$overdueChecks} فقره</b>\n";
    if ($expiredItems > 0)
        $msg .= "🚨 اقلام منقضی در انبار: <b>{$expiredItems} نوع</b>\n";
    $msg .= "──────────────\n";
    $msg .= "🔗 پنل مدیریت: " . (defined('SITE_URL') ? SITE_URL . "/admin/erp_dashboard.php" : "");

    return telegramSend($chatId, $msg, $pdo);
}

function telegramExpiryAlert(PDO $pdo): bool {
    $st = $pdo->prepare("SELECT value FROM settings WHERE `key`=?");
    $st->execute(['telegram_alert_id']);
    $chatId = $st->fetchColumn();
    if (!$chatId) return false;

    $today  = jdate('Y/m/d');
    // ۳۰ روز بعد به شمسی
    $plus30 = jdate('Y/m/d', mktime(0,0,0, (int)jdate('m'), (int)jdate('d')+30, (int)jdate('Y')));

    $st = $pdo->prepare("
        SELECT s.stuff_name, b.batch_number, b.expiry_date, SUM(b.qty) AS qty
        FROM inv_batch_stock b
        JOIN stuffs s ON s.id = b.stuff_id
        WHERE b.qty > 0 AND b.expiry_date IS NOT NULL
          AND b.expiry_date BETWEEN ? AND ? AND b.is_deleted=0
        GROUP BY b.stuff_id, b.batch_number, b.expiry_date
        ORDER BY b.expiry_date ASC
        LIMIT 10
    ");
    $st->execute([$today, $plus30]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($items)) return true;

    $msg = "⏰ <b>هشدار انقضا کالا — آتنا زیست درمان</b>\n📅 {$today}\n──────────────\n";
    foreach ($items as $item) {
        $expired = ($item['expiry_date'] < $today);
        $icon = $expired ? '🔴' : '🟡';
        $msg .= "{$icon} <b>{$item['stuff_name']}</b> | Lot: {$item['batch_number']}\n";
        $msg .= "   انقضا: {$item['expiry_date']} | موجودی: {$item['qty']}\n";
    }
    return telegramSend($chatId, $msg, $pdo);
}

function telegramCheckAlert(PDO $pdo, int $chequeId): bool {
    $st = $pdo->prepare("SELECT value FROM settings WHERE `key`=?");
    $st->execute(['telegram_alert_id']);
    $chatId = $st->fetchColumn();
    if (!$chatId) return false;

    $st = $pdo->prepare("SELECT c.*, COALESCE(p.company_name,p.name,'نامشخص') AS person_name
                         FROM fin_cheques c LEFT JOIN fin_persons p ON p.id=c.person_id
                         WHERE c.id=?");
    $st->execute([$chequeId]);
    $cheque = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cheque) return false;

    $msg  = "💳 <b>هشدار سررسید چک</b>\n";
    $msg .= "👤 طرف حساب: {$cheque['person_name']}\n";
    $msg .= "💰 مبلغ: " . number_format($cheque['amount']) . " ريال\n";
    $msg .= "📅 سررسید: {$cheque['due_date']}\n";
    $msg .= "🔢 شماره چک: {$cheque['cheque_number']}\n";
    return telegramSend($chatId, $msg, $pdo);
}
