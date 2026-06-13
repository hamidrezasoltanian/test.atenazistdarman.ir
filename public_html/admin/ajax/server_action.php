<?php
/*
 * فایل: public_html/admin/ajax/server_action.php
 * AJAX handler برای عملیات مدیریت سرور
 * فقط ادمین دسترسی دارد
 */

ob_start();
session_start();

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// بررسی دسترسی
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['ok' => false, 'msg' => 'دسترسی غیرمجاز']);
    exit;
}

// بررسی CSRF
$token = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
if (!csrf_verify($token)) {
    echo json_encode(['ok' => false, 'msg' => 'توکن نامعتبر است. صفحه را رفرش کنید.']);
    exit;
}

$apps   = require __DIR__ . '/../../../Config/server_apps.php';
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
$appId  = trim($_POST['app_id'] ?? $_GET['app_id'] ?? '');

// پیدا کردن اپ
$app = null;
foreach ($apps as $a) {
    if ($a['id'] === $appId) { $app = $a; break; }
}

// اکشن وضعیت همه اپ‌ها (بدون نیاز به app_id)
if ($action === 'status_all') {
    $result = [];
    foreach ($apps as $a) {
        $result[$a['id']] = getAppStatus($a);
    }
    echo json_encode(['ok' => true, 'statuses' => $result]);
    exit;
}

// آمار سرور
if ($action === 'server_stats') {
    $stats = [];

    // RAM
    exec("free -m 2>/dev/null", $freeOut);
    if (!empty($freeOut[1])) {
        $parts = preg_split('/\s+/', trim($freeOut[1]));
        $total = (int)($parts[1] ?? 0);
        $used  = (int)($parts[2] ?? 0);
        if ($total > 0) {
            $pct = round($used / $total * 100);
            $stats['ram'] = round($used/1024,1) . 'GB / ' . round($total/1024,1) . 'GB (' . $pct . '%)';
        }
    }

    // CPU Load Average
    $load = sys_getloadavg();
    if ($load) {
        $stats['cpu'] = number_format($load[0], 2) . ' / ' . number_format($load[1], 2) . ' / ' . number_format($load[2], 2);
    }

    // Disk
    exec("df -h / 2>/dev/null", $dfOut);
    if (!empty($dfOut[1])) {
        $parts = preg_split('/\s+/', trim($dfOut[1]));
        $used  = $parts[2] ?? '?';
        $total = $parts[1] ?? '?';
        $pct   = $parts[4] ?? '?';
        $stats['disk'] = $used . ' / ' . $total . ' (' . $pct . ')';
    }

    // Uptime
    exec("uptime -p 2>/dev/null", $upOut);
    if (!empty($upOut[0])) {
        $stats['uptime'] = str_replace(['up ', 'hours', 'minutes', 'days', 'hour', 'minute', 'day'],
                                       ['', 'ساعت', 'دقیقه', 'روز', 'ساعت', 'دقیقه', 'روز'],
                                       $upOut[0]);
    } else {
        exec("cat /proc/uptime 2>/dev/null", $uptRaw);
        if (!empty($uptRaw[0])) {
            $secs  = (int)explode(' ', $uptRaw[0])[0];
            $days  = floor($secs / 86400);
            $hrs   = floor(($secs % 86400) / 3600);
            $mins  = floor(($secs % 3600) / 60);
            $stats['uptime'] = ($days > 0 ? $days . 'روز ' : '') . $hrs . 'ساعت ' . $mins . 'دقیقه';
        }
    }

    echo json_encode(['ok' => true] + $stats);
    exit;
}

// اکشن‌های تکی (نیاز به app_id)
if (!$app) {
    echo json_encode(['ok' => false, 'msg' => 'اپ مورد نظر یافت نشد.']);
    exit;
}

$allowedActions = ['start', 'stop', 'restart', 'git_pull', 'logs', 'status'];

if (!in_array($action, $allowedActions)) {
    echo json_encode(['ok' => false, 'msg' => 'عملیات نامعتبر.']);
    exit;
}

// اجرای عملیات
switch ($action) {
    case 'status':
        $status = getAppStatus($app);
        echo json_encode(['ok' => true, 'status' => $status]);
        break;

    case 'start':
        $out = runAppCommand($app, 'start');
        logSystem('server_manager', 'start', $app['id'], 'شروع اپ: ' . $app['name']);
        echo json_encode(['ok' => $out['code'] === 0, 'msg' => $out['output'] ?: 'اجرا شد.', 'code' => $out['code']]);
        break;

    case 'stop':
        $out = runAppCommand($app, 'stop');
        logSystem('server_manager', 'stop', $app['id'], 'توقف اپ: ' . $app['name']);
        echo json_encode(['ok' => $out['code'] === 0, 'msg' => $out['output'] ?: 'متوقف شد.', 'code' => $out['code']]);
        break;

    case 'restart':
        $out = runAppCommand($app, 'restart');
        logSystem('server_manager', 'restart', $app['id'], 'ریستارت اپ: ' . $app['name']);
        echo json_encode(['ok' => $out['code'] === 0, 'msg' => $out['output'] ?: 'ریستارت شد.', 'code' => $out['code']]);
        break;

    case 'git_pull':
        $path = realpath($app['path'] ?? '');
        if (!$path || !is_dir($path)) {
            echo json_encode(['ok' => false, 'msg' => 'مسیر پروژه یافت نشد: ' . ($app['path'] ?? '')]);
            exit;
        }
        if (!is_dir($path . '/.git')) {
            echo json_encode(['ok' => false, 'msg' => 'این پوشه یک مخزن git نیست.']);
            exit;
        }
        $cmd = 'cd ' . escapeshellarg($path) . ' && git pull 2>&1';
        exec($cmd, $output, $code);
        $outputStr = implode("\n", $output);
        logSystem('server_manager', 'git_pull', $app['id'], 'آپدیت گیت: ' . $app['name'] . ' - ' . ($code === 0 ? 'موفق' : 'خطا'));
        echo json_encode(['ok' => $code === 0, 'msg' => $outputStr ?: ($code === 0 ? 'آپدیت انجام شد.' : 'خطا در آپدیت.')]);
        break;

    case 'logs':
        $lines = (int)($_GET['lines'] ?? 50);
        $lines = max(10, min(200, $lines));
        $out   = getAppLogs($app, $lines);
        echo json_encode(['ok' => true, 'logs' => $out]);
        break;
}

// =====================================================================
// توابع کمکی
// =====================================================================

function getAppStatus(array $app): array
{
    $type = $app['type'] ?? 'systemd';
    $status = 'unknown';
    $info   = '';

    switch ($type) {
        case 'pm2':
            $name = escapeshellarg($app['pm2_name'] ?? $app['id']);
            exec("pm2 show $name 2>&1", $out, $code);
            $output = implode("\n", $out);
            if ($code !== 0 || stripos($output, 'error') !== false) {
                $status = 'stopped';
            } elseif (stripos($output, 'online') !== false) {
                $status = 'running';
                preg_match('/↺\s+(\d+)/', $output, $m);
                $info = isset($m[1]) ? 'ریستارت: ' . $m[1] . ' بار' : '';
                preg_match('/uptime\s+│\s+([^\│]+)/i', $output, $um);
                if (isset($um[1])) $info = 'آپتایم: ' . trim($um[1]) . ($info ? ' | ' . $info : '');
            } else {
                $status = 'stopped';
            }
            break;

        case 'systemd':
            $svc = escapeshellarg($app['service'] ?? $app['id']);
            exec("systemctl is-active $svc 2>&1", $out, $code);
            $active = trim(implode('', $out));
            if ($active === 'active') {
                $status = 'running';
                exec("systemctl show $svc --property=ActiveEnterTimestamp 2>&1", $tsOut);
                $ts = trim(str_replace('ActiveEnterTimestamp=', '', implode('', $tsOut)));
                if ($ts) $info = 'از: ' . $ts;
            } elseif ($active === 'activating') {
                $status = 'starting';
            } else {
                $status = 'stopped';
            }
            break;

        case 'docker':
            $ctr = escapeshellarg($app['container'] ?? $app['id']);
            exec("docker inspect --format='{{.State.Status}}' $ctr 2>&1", $out, $code);
            $state = trim(implode('', $out));
            if ($state === 'running') {
                $status = 'running';
                exec("docker inspect --format='{{.State.StartedAt}}' $ctr 2>&1", $tOut);
                $info = 'شروع: ' . trim(implode('', $tOut));
            } elseif (in_array($state, ['restarting', 'paused'])) {
                $status = 'starting';
            } else {
                $status = 'stopped';
            }
            break;

        case 'script':
            $cmd = $app['status_cmd'] ?? '';
            if ($cmd) {
                exec($cmd . ' 2>&1', $out, $code);
                $output = strtolower(implode(' ', $out));
                if ($code === 0 || stripos($output, 'running') !== false || stripos($output, 'active') !== false) {
                    $status = 'running';
                } else {
                    $status = 'stopped';
                }
            }
            break;
    }

    return ['status' => $status, 'info' => $info];
}

function runAppCommand(array $app, string $action): array
{
    $type = $app['type'] ?? 'systemd';
    $output = '';
    $code   = 1;

    switch ($type) {
        case 'pm2':
            $name = escapeshellarg($app['pm2_name'] ?? $app['id']);
            $map  = ['start' => 'start', 'stop' => 'stop', 'restart' => 'restart'];
            $cmd  = 'pm2 ' . $map[$action] . " $name 2>&1";
            exec($cmd, $out, $code);
            $output = implode("\n", $out);
            break;

        case 'systemd':
            $svc = escapeshellarg($app['service'] ?? $app['id']);
            $cmd = "sudo systemctl $action $svc 2>&1";
            exec($cmd, $out, $code);
            $output = implode("\n", $out);
            break;

        case 'docker':
            $ctr = escapeshellarg($app['container'] ?? $app['id']);
            $map = ['start' => 'start', 'stop' => 'stop', 'restart' => 'restart'];
            $cmd = 'docker ' . $map[$action] . " $ctr 2>&1";
            exec($cmd, $out, $code);
            $output = implode("\n", $out);
            break;

        case 'script':
            $cmdKey = $action . '_cmd';
            $cmd    = $app[$cmdKey] ?? '';
            if ($cmd) {
                exec($cmd . ' 2>&1', $out, $code);
                $output = implode("\n", $out);
            } else {
                $output = 'دستور ' . $action . ' برای این اپ تعریف نشده.';
                $code   = 1;
            }
            break;
    }

    return ['code' => $code, 'output' => $output];
}

function getAppLogs(array $app, int $lines): string
{
    $type = $app['type'] ?? 'systemd';

    switch ($type) {
        case 'pm2':
            $name = escapeshellarg($app['pm2_name'] ?? $app['id']);
            exec("pm2 logs $name --lines $lines --nostream 2>&1", $out);
            return implode("\n", $out);

        case 'systemd':
            $svc = escapeshellarg($app['service'] ?? $app['id']);
            exec("journalctl -u $svc -n $lines --no-pager 2>&1", $out);
            return implode("\n", $out);

        case 'docker':
            $ctr = escapeshellarg($app['container'] ?? $app['id']);
            exec("docker logs --tail $lines $ctr 2>&1", $out);
            return implode("\n", $out);

        case 'script':
            $logFile = $app['log_file'] ?? '';
            if ($logFile && file_exists($logFile)) {
                exec("tail -n $lines " . escapeshellarg($logFile) . ' 2>&1', $out);
                return implode("\n", $out);
            }
            return 'فایل لاگ تعریف نشده یا یافت نشد.';
    }

    return 'نوع اپ پشتیبانی نمی‌شود.';
}
