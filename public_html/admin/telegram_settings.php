<?php
ob_start();
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/telegram.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'management'], true)) {
    die('<div style="text-align:center;margin-top:50px;color:red;font-family:Vazirmatn;">⛔ دسترسی ندارید.</div>');
}

// ── اطمینان از وجود ردیف‌های تنظیمات ──
$tgKeys = ['telegram_bot_token','telegram_chat_id','telegram_alert_id','telegram_daily_hour','telegram_enabled'];
foreach ($tgKeys as $k) {
    $pdo->prepare("INSERT IGNORE INTO settings (`key`,`value`,`label`,`group`) VALUES (?,?,?,?)")
        ->execute([$k, '', $k, 'telegram']);
}

$msg = '';
$msgType = 'ok';

// ── ذخیره تنظیمات ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $fields = ['telegram_bot_token','telegram_chat_id','telegram_alert_id','telegram_daily_hour','telegram_enabled'];
        $st = $pdo->prepare("INSERT INTO settings (`key`,`value`,`label`,`group`) VALUES (?,?,?,?)
                             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
        foreach ($fields as $f) {
            $val = trim($_POST[$f] ?? '');
            $st->execute([$f, $val, $f, 'telegram']);
        }
        $msg = '✅ تنظیمات تلگرام ذخیره شد.';
        logActivity($pdo, $_SESSION['user_id'], 'telegram_settings_saved', 'تنظیمات تلگرام بات ذخیره شد');
    }

    if ($action === 'test_message') {
        $chatId = trim($_POST['test_chat_id'] ?? '');
        if ($chatId) {
            $ok = telegramSend($chatId, '✅ تست ارتباط آتنا زیست درمان — موفق', $pdo);
            $msg = $ok ? '✅ پیام آزمایشی ارسال شد.' : '❌ ارسال ناموفق — توکن یا Chat ID را بررسی کنید.';
            $msgType = $ok ? 'ok' : 'err';
        }
    }

    if ($action === 'send_daily') {
        $ok = telegramDailyReport($pdo);
        $msg = $ok ? '✅ گزارش روزانه ارسال شد.' : '❌ خطا در ارسال — تنظیمات Chat ID را بررسی کنید.';
        $msgType = $ok ? 'ok' : 'err';
    }

    if ($action === 'send_expiry') {
        $ok = telegramExpiryAlert($pdo);
        $msg = $ok ? '✅ هشدار انقضا ارسال شد.' : '❌ خطا در ارسال هشدار.';
        $msgType = $ok ? 'ok' : 'err';
    }
}

// ── خواندن تنظیمات فعلی ──
$settings = [];
$st = $pdo->query("SELECT `key`, `value` FROM settings WHERE `group`='telegram'");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $settings[$row['key']] = $row['value'];
}
$s = fn($k) => htmlspecialchars($settings[$k] ?? '');

$pageTitle = 'تنظیمات تلگرام بات';
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/sidebar.php';
?>
<div class="main-content">
<div style="max-width:700px;margin:0 auto;padding:24px 16px;">

  <div class="fin-panel-title" style="font-size:1.3rem;margin-bottom:20px;">🤖 تنظیمات تلگرام بات</div>

  <?php if ($msg): ?>
  <div class="fin-badge <?= $msgType === 'ok' ? 'green' : 'rose' ?>" style="display:block;padding:10px 16px;margin-bottom:16px;font-size:.95rem;"><?= $msg ?></div>
  <?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">
    <div class="fin-panel" style="margin-bottom:20px;">
      <div class="fin-panel-title">⚙️ پیکربندی ربات</div>
      <div style="display:grid;gap:14px;">
        <div>
          <label style="display:block;font-size:.85rem;color:#6b7280;margin-bottom:4px;">توکن ربات (Bot Token)</label>
          <input type="text" name="telegram_bot_token" class="fin-input" style="width:100%;direction:ltr;"
            value="<?= $s('telegram_bot_token') ?>" placeholder="1234567890:AAF...">
          <div style="font-size:.78rem;color:#9ca3af;margin-top:4px;">از @BotFather در تلگرام دریافت کنید</div>
        </div>
        <div>
          <label style="display:block;font-size:.85rem;color:#6b7280;margin-bottom:4px;">Chat ID گزارش روزانه</label>
          <input type="text" name="telegram_chat_id" class="fin-input" style="width:100%;direction:ltr;"
            value="<?= $s('telegram_chat_id') ?>" placeholder="-1001234567890 یا شناسه کانال">
          <div style="font-size:.78rem;color:#9ca3af;margin-top:4px;">گروه، کانال یا چت خصوصی برای گزارش روزانه</div>
        </div>
        <div>
          <label style="display:block;font-size:.85rem;color:#6b7280;margin-bottom:4px;">Chat ID هشدارهای فوری (انقضا، چک)</label>
          <input type="text" name="telegram_alert_id" class="fin-input" style="width:100%;direction:ltr;"
            value="<?= $s('telegram_alert_id') ?>" placeholder="-1001234567890">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label style="display:block;font-size:.85rem;color:#6b7280;margin-bottom:4px;">ساعت ارسال گزارش روزانه</label>
            <input type="number" name="telegram_daily_hour" class="fin-input" min="0" max="23"
              value="<?= $s('telegram_daily_hour') ?: '8' ?>" style="width:100%;">
          </div>
          <div style="display:flex;align-items:center;gap:10px;padding-top:20px;">
            <input type="checkbox" name="telegram_enabled" value="1" id="tgEnabled"
              <?= ($settings['telegram_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
            <label for="tgEnabled" style="font-size:.9rem;">فعال‌سازی تلگرام بات</label>
          </div>
        </div>
      </div>
      <div style="margin-top:16px;">
        <button type="submit" class="fin-btn fin-btn-primary">💾 ذخیره تنظیمات</button>
      </div>
    </div>
  </form>

  <!-- تست ارتباط -->
  <div class="fin-panel" style="margin-bottom:20px;">
    <div class="fin-panel-title">🧪 تست و ارسال دستی</div>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test_message">
      <div style="flex:1;min-width:200px;">
        <label style="display:block;font-size:.85rem;color:#6b7280;margin-bottom:4px;">Chat ID برای تست</label>
        <input type="text" name="test_chat_id" class="fin-input" style="width:100%;direction:ltr;"
          value="<?= $s('telegram_chat_id') ?>" placeholder="Chat ID">
      </div>
      <button type="submit" class="fin-btn fin-btn-outline">📤 ارسال پیام آزمایشی</button>
    </form>
    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_daily">
        <button type="submit" class="fin-btn fin-btn-outline">📊 ارسال گزارش روزانه الان</button>
      </form>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_expiry">
        <button type="submit" class="fin-btn fin-btn-outline" style="border-color:#f59e0b;color:#f59e0b;">⏰ ارسال هشدار انقضا الان</button>
      </form>
    </div>
  </div>

  <!-- راهنما -->
  <div class="fin-panel" style="background:#f8fafc;">
    <div class="fin-panel-title">📖 راهنمای راه‌اندازی</div>
    <ol style="line-height:2;font-size:.88rem;color:#374151;padding-right:20px;">
      <li>در تلگرام به @BotFather پیام بدید و /newbot بزنید</li>
      <li>نام ربات را وارد کنید — توکن را کپی کنید</li>
      <li>ربات را به گروه/کانال خود اضافه کنید (Admin)</li>
      <li>Chat ID گروه را از @userinfobot بگیرید</li>
      <li>توکن و Chat ID را در بالا وارد کرده ذخیره کنید</li>
      <li>با دکمه «ارسال پیام آزمایشی» تست کنید</li>
    </ol>
  </div>

</div>
</div>
<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
