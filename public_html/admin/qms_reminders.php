<?php
// یادآور خودکار QMS — قابل اجرا از cron یا دستی
// cron پیشنهادی: هر روز ساعت ۸ صبح
// 0 8 * * * php /var/www/html/public_html/admin/qms_reminders.php

$root = dirname(__DIR__, 2);
require_once $root . '/Config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/telegram.php';

$isCli   = php_sapi_name() === 'cli';
$isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
$isAdmin = false;

// دسترسی: CLI یا ادمین لاگین‌شده
if (!$isCli) {
    requireLogin();
    $isAdmin = ($_SESSION['role'] ?? '') === 'admin' || ($_SESSION['is_admin'] ?? 0) == 1;
    if (!$isAdmin) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['error' => 'دسترسی ندارید']); exit; }
        header('Location: ../../index.php'); exit;
    }
}

// ── AJAX run now ──────────────────────────────────────────────
if ($isAjax && ($_GET['action'] ?? '') === 'run') {
    header('Content-Type: application/json; charset=utf-8');
    $results = runReminders($pdo);
    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── تابع اصلی ─────────────────────────────────────────────────
function runReminders(PDO $pdo): array {
    $results = [];
    $today   = jdate('Y/m/d');
    $in7     = jdate('Y/m/d', mktime(0,0,0, (int)jdate('m'), (int)jdate('d')+7, (int)jdate('Y')));
    $in30    = jdate('Y/m/d', mktime(0,0,0, (int)jdate('m'), (int)jdate('d')+30, (int)jdate('Y')));

    // ۱. هشدار تلگرام QMS
    try {
        $sent = telegramQmsAlert($pdo);
        $results[] = ['type' => 'telegram_qms', 'status' => $sent ? 'sent' : 'skipped'];
    } catch (Exception $e) {
        $results[] = ['type' => 'telegram_qms', 'status' => 'error', 'msg' => $e->getMessage()];
    }

    // ۲. اعلان داخلی — CAPA‌های سررسید ۷ روز آینده
    try {
        $st = $pdo->prepare(
            "SELECT cr.id, cr.capa_number, cr.title, cr.target_date, cr.responsible_id
             FROM capa_requests cr
             WHERE cr.is_deleted=0 AND cr.status NOT IN ('closed','cancelled')
               AND cr.target_date IS NOT NULL
               AND cr.target_date BETWEEN ? AND ?
               AND cr.responsible_id IS NOT NULL"
        );
        $st->execute([$today, $in7]);
        $capas = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($capas as $ca) {
            // بررسی عدم ارسال تکراری امروز
            $chk = $pdo->prepare(
                "SELECT COUNT(*) FROM user_notifications
                 WHERE user_id=? AND type='capa_due' AND related_id=?
                   AND DATE(created_at)=CURDATE()"
            );
            $chk->execute([$ca['responsible_id'], $ca['id']]);
            if ($chk->fetchColumn() > 0) continue;

            $ins = $pdo->prepare(
                "INSERT INTO user_notifications (user_id, title, body, type, related_id, created_at)
                 VALUES (?, ?, ?, 'capa_due', ?, NOW())"
            );
            $ins->execute([
                $ca['responsible_id'],
                'سررسید CAPA نزدیک است',
                "CAPA {$ca['capa_number']} — {$ca['title']} تا {$ca['target_date']} باید بسته شود.",
                $ca['id'],
            ]);
        }
        $results[] = ['type' => 'capa_notifications', 'count' => count($capas)];
    } catch (Exception $e) {
        $results[] = ['type' => 'capa_notifications', 'status' => 'error', 'msg' => $e->getMessage()];
    }

    // ۳. اعلان داخلی — گواهینامه‌های در حال انقضا
    try {
        $st = $pdo->prepare(
            "SELECT tr.id, tr.user_id, ts.name AS session_name, tr.expiry_date
             FROM hr_training_records tr
             JOIN hr_training_sessions ts ON ts.id=tr.session_id
             WHERE tr.is_deleted=0 AND tr.result='pass'
               AND tr.expiry_date IS NOT NULL
               AND tr.expiry_date BETWEEN ? AND ?"
        );
        $st->execute([$today, $in30]);
        $recs = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recs as $r) {
            $chk = $pdo->prepare(
                "SELECT COUNT(*) FROM user_notifications
                 WHERE user_id=? AND type='training_expiry' AND related_id=?
                   AND DATE(created_at)=CURDATE()"
            );
            $chk->execute([$r['user_id'], $r['id']]);
            if ($chk->fetchColumn() > 0) continue;

            $ins = $pdo->prepare(
                "INSERT INTO user_notifications (user_id, title, body, type, related_id, created_at)
                 VALUES (?, ?, ?, 'training_expiry', ?, NOW())"
            );
            $ins->execute([
                $r['user_id'],
                'گواهینامه آموزشی در حال انقضا',
                "گواهینامه دوره «{$r['session_name']}» تا {$r['expiry_date']} منقضی می‌شود.",
                $r['id'],
            ]);
        }
        $results[] = ['type' => 'training_notifications', 'count' => count($recs)];
    } catch (Exception $e) {
        $results[] = ['type' => 'training_notifications', 'status' => 'error', 'msg' => $e->getMessage()];
    }

    // ۴. اعلان داخلی — مدارک نیاز به بازنگری
    try {
        $st = $pdo->prepare(
            "SELECT id, doc_number, title, owner_id, next_review_date
             FROM doc_documents
             WHERE is_deleted=0 AND status='approved'
               AND next_review_date IS NOT NULL
               AND next_review_date <= ?
               AND owner_id IS NOT NULL"
        );
        $st->execute([$in30]);
        $docs = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($docs as $doc) {
            $chk = $pdo->prepare(
                "SELECT COUNT(*) FROM user_notifications
                 WHERE user_id=? AND type='doc_review' AND related_id=?
                   AND DATE(created_at)=CURDATE()"
            );
            $chk->execute([$doc['owner_id'], $doc['id']]);
            if ($chk->fetchColumn() > 0) continue;

            $ins = $pdo->prepare(
                "INSERT INTO user_notifications (user_id, title, body, type, related_id, created_at)
                 VALUES (?, ?, ?, 'doc_review', ?, NOW())"
            );
            $ins->execute([
                $doc['owner_id'],
                'مدرک نیاز به بازنگری دارد',
                "مدرک {$doc['doc_number']} — {$doc['title']} تا {$doc['next_review_date']} باید بازنگری شود.",
                $doc['id'],
            ]);
        }
        $results[] = ['type' => 'doc_review_notifications', 'count' => count($docs)];
    } catch (Exception $e) {
        $results[] = ['type' => 'doc_review_notifications', 'status' => 'error', 'msg' => $e->getMessage()];
    }

    return $results;
}

// ── اجرای CLI ─────────────────────────────────────────────────
if ($isCli) {
    $res = runReminders($pdo);
    foreach ($res as $r) {
        echo "[{$r['type']}] " . ($r['status'] ?? 'ok') . ' ' . ($r['count'] ?? '') . ($r['msg'] ?? '') . PHP_EOL;
    }
    exit(0);
}

// ── رابط وب ───────────────────────────────────────────────────
$pageTitle = 'یادآورهای QMS';
$root = dirname(__DIR__, 2);
ob_start();
require_once $root . '/templates/header.php';
echo ob_get_clean();
require_once $root . '/templates/sidebar.php';
?>

<div class="content-wrapper">
<div class="fin-panel" style="max-width:700px;margin:30px auto;">
  <h2 style="margin:0 0 6px;font-size:1.1rem;font-weight:700;color:#0f172a;">🔔 یادآورهای خودکار QMS</h2>
  <p style="color:#64748b;font-size:.85rem;margin:0 0 24px;">
    این صفحه یادآورهای QMS را ارسال می‌کند. برای اجرای خودکار روزانه، cron job زیر را تنظیم کنید:
  </p>
  <pre style="background:#0f172a;color:#e2e8f0;padding:14px 18px;border-radius:10px;font-size:.8rem;direction:ltr;overflow-x:auto;">0 8 * * * php <?= htmlspecialchars(__FILE__) ?></pre>

  <div style="margin-top:24px;">
    <button onclick="runNow()" class="fin-btn fin-btn-primary" id="runBtn">▶ اجرا اکنون</button>
  </div>

  <div id="runResult" style="margin-top:20px;display:none;">
    <h3 style="font-size:.9rem;font-weight:700;margin:0 0 12px;">نتیجه اجرا:</h3>
    <div id="runResultBody"></div>
  </div>
</div>
</div>

<script>
function runNow() {
  $('#runBtn').prop('disabled', true).text('در حال اجرا...');
  $.getJSON('?action=run', {}, function(d) {
    let html = '';
    if (d.results) {
      d.results.forEach(r => {
        const ok = r.status !== 'error';
        const clr = ok ? '#10b981' : '#ef4444';
        html += `<div style="padding:8px 12px;margin-bottom:6px;background:#f8fafc;border-radius:8px;border-right:3px solid ${clr};display:flex;justify-content:space-between;font-size:.82rem;">
          <span style="color:#334155;">${r.type}</span>
          <span style="color:${clr};font-weight:600;">${r.status || 'ok'} ${r.count !== undefined ? '('+r.count+' مورد)' : ''} ${r.msg||''}</span>
        </div>`;
      });
    }
    $('#runResult').show();
    $('#runResultBody').html(html || '<p style="color:#64748b;">نتیجه‌ای نیست.</p>');
    $('#runBtn').prop('disabled', false).text('▶ اجرا اکنون');
  }).fail(function(){
    alert('خطا در اجرا');
    $('#runBtn').prop('disabled', false).text('▶ اجرا اکنون');
  });
}
</script>

<?php require_once $root . '/templates/footer.php'; ?>
