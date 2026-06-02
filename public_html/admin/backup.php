<?php
/*
 * فایل: public_html/admin/backup.php
 * توضیحات: پنل پشتیبان‌گیری دستی (کد + دیتابیس + فایل‌ها)
 */
ob_start();
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
if ($_SESSION['role'] !== 'admin' && !hasPermission('backup_view')) {
    die('<div style="text-align:center; padding:50px;">شما مجوز دسترسی ندارید.</div>');
}

$pageTitle = 'پشتیبان گیری';
$basePath = '../';
$message = '';
$msgType = 'info';

$config = require __DIR__ . '/../../Config/config.php';
$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
$backupScript = $isWindows
    ? __DIR__ . '/../../backups/backup.ps1'
    : __DIR__ . '/../../backups/backup.sh';
$outputDir = realpath(__DIR__ . '/../../backups/output');

// دانلود امن فایل‌های بکاپ (فقط .enc)
if (isset($_GET['download']) && isset($_GET['file'])) {
    $backupDirName = basename((string)$_GET['download']);
    $fileName = basename((string)$_GET['file']);
    if ($backupDirName && $fileName && preg_match('/\.enc$/i', $fileName)) {
        $base = $outputDir ?: '';
        $target = $base . DIRECTORY_SEPARATOR . $backupDirName . DIRECTORY_SEPARATOR . $fileName;
        $realTarget = realpath($target);
        if ($realTarget && $base && strpos($realTarget, $base) === 0 && is_file($realTarget)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($realTarget) . '"');
            header('Content-Length: ' . filesize($realTarget));
            readfile($realTarget);
            exit;
        }
    }
    $message = 'فایل مورد نظر برای دانلود یافت نشد.';
    $msgType = 'danger';
}

// حذف بکاپ
if (isset($_POST['delete_backup'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $message = 'نشست نامعتبر است. لطفاً صفحه را رفرش کنید.';
        $msgType = 'danger';
    } else {
        $delDir = basename((string)$_POST['delete_backup']);
        $base = $outputDir ?: '';
        $target = $base . DIRECTORY_SEPARATOR . $delDir;
        $realTarget = realpath($target);
        if ($realTarget && $base && strpos($realTarget, $base) === 0 && is_dir($realTarget)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($realTarget, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($realTarget);
            $message = 'بکاپ حذف شد.';
            $msgType = 'success';
        } else {
            $message = 'بکاپ مورد نظر یافت نشد.';
            $msgType = 'danger';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $message = 'نشست نامعتبر است. لطفاً صفحه را رفرش کنید.';
        $msgType = 'danger';
    } else {
        $accountPass = trim($_POST['account_password'] ?? '');
        $backupPass = trim($_POST['backup_password'] ?? '');
        if ($accountPass === '' || $backupPass === '') {
            $message = 'رمز حساب و رمز پشتیبان الزامی است.';
            $msgType = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$_SESSION['user_id']]);
                $hash = $stmt->fetchColumn();
                if (!$hash || !password_verify($accountPass, $hash)) {
                    $message = 'رمز حساب اشتباه است.';
                    $msgType = 'danger';
                } elseif (!file_exists($backupScript)) {
                    $message = 'اسکریپت پشتیبان‌گیری پیدا نشد: ' . htmlspecialchars($backupScript);
                    $msgType = 'danger';
                } else {
                    set_time_limit(0);
                    if (!is_callable('shell_exec')) {
                        $message = 'تابع shell_exec غیرفعال است. برای اجرای بکاپ باید در سرور فعال شود.';
                        $msgType = 'danger';
                    } else {
                    if ($isWindows) {
                        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($backupScript)
                             . ' -DbHost ' . escapeshellarg($config['db_host'])
                             . ' -DbName ' . escapeshellarg($config['db_name'])
                             . ' -DbUser ' . escapeshellarg($config['db_user'])
                             . ' -DbPass ' . escapeshellarg($config['db_pass'])
                             . ' -EncPass ' . escapeshellarg($backupPass);
                    } else {
                        $cmd = 'bash ' . escapeshellarg($backupScript)
                             . ' --db-host ' . escapeshellarg($config['db_host'])
                             . ' --db-name ' . escapeshellarg($config['db_name'])
                             . ' --db-user ' . escapeshellarg($config['db_user'])
                             . ' --db-pass ' . escapeshellarg($config['db_pass'])
                             . ' --enc-pass ' . escapeshellarg($backupPass);
                    }

                    $output = shell_exec($cmd);
                    logSystem('Backup', 'create', 0, 'اجرای پشتیبان‌گیری از طریق پنل');
                    $message = 'پشتیبان‌گیری انجام شد.';
                    if ($output) $message .= ' خروجی: ' . htmlspecialchars($output);
                    $msgType = 'success';
                    }
                }
            } catch (Exception $e) {
                $message = 'خطا در پشتیبان‌گیری: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

// لیست آخرین پشتیبان‌ها
$backupList = [];
if ($outputDir && is_dir($outputDir)) {
    $dirs = array_filter(glob($outputDir . '/*'), 'is_dir');
    rsort($dirs);
    foreach (array_slice($dirs, 0, 10) as $dir) {
        $backupList[] = basename($dir);
    }
}

$extraCss = '<style>
    .backup-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px; box-shadow:0 2px 6px rgba(0,0,0,0.04); }
    .backup-grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
    .backup-grid .full { grid-column: 1 / -1; }
    .form-label { font-size:0.85rem; color:#64748b; margin-bottom:6px; display:block; }
    .form-control { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; font-family:"Vazirmatn", Tahoma, sans-serif; }
    .backup-list { margin:0; padding:0; list-style:none; }
    .backup-list li { padding:8px 0; border-bottom:1px dashed #e5e7eb; font-size:0.9rem; }
    .backup-list .btn { white-space: nowrap; }
    @media (max-width: 768px) { .backup-grid { grid-template-columns: 1fr; } }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions">
            <div><span class="page-title">پشتیبان گیری</span></div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $msgType; ?> mb-4"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="backup-card">
            <form method="post">
                <?php echo csrf_field(); ?>
                <div class="backup-grid">
                    <div class="full">
                        <p class="text-muted small mb-2">پشتیبان‌گیری به صورت جداگانه از کد، فایل‌های بارگذاری‌شده و دیتابیس انجام می‌شود و با رمز شما رمزگذاری خواهد شد.</p>
                    </div>
                    <div>
                        <label class="form-label">رمز حساب کاربری</label>
                        <input type="password" class="form-control" name="account_password" required>
                        <div class="text-muted small mt-1">برای تایید هویت شما</div>
                    </div>
                    <div>
                        <label class="form-label">رمز پشتیبان (برای رمزگذاری)</label>
                        <input type="password" class="form-control" name="backup_password" required>
                        <div class="text-muted small mt-1">این رمز برای بازکردن فایل‌های بکاپ است</div>
                    </div>
                    <div class="full d-flex justify-content-end gap-2">
                        <button type="submit" class="btn btn-primary">شروع پشتیبان‌گیری</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="backup-card mt-4">
            <h6 class="mb-3">آخرین پشتیبان‌ها</h6>
            <?php if (empty($backupList)): ?>
                <div class="text-muted small">هنوز پشتیبانی ثبت نشده است.</div>
            <?php else: ?>
                <ul class="backup-list">
                    <?php foreach ($backupList as $b): ?>
                        <li>
                            <div class="d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($b); ?></span>
                                <div class="d-flex gap-2">
                                    <?php
                                        $files = ['db.sql.enc', 'code.tar.gz.enc', 'uploads.tar.gz.enc'];
                                        foreach ($files as $f):
                                            $link = 'backup.php?download=' . urlencode($b) . '&file=' . urlencode($f);
                                    ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo $link; ?>"><?php echo htmlspecialchars($f); ?></a>
                                    <?php endforeach; ?>
                                    <form method="post" onsubmit="return confirm('آیا از حذف این بکاپ مطمئن هستید؟');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="delete_backup" value="<?php echo htmlspecialchars($b); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">حذف</button>
                                    </form>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
