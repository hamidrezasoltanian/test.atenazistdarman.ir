<?php
/*
 * فایل: Config/server_apps.php
 * تعریف اپ‌های سرور برای مدیریت از داشبورد
 *
 * انواع type:
 *   pm2      => مدیریت با PM2 (Node.js / هر اسکریپتی)
 *   systemd  => سرویس systemd (مثل nginx، mysql، یا سرویس سفارشی)
 *   docker   => کانتینر Docker
 *   script   => اسکریپت bash سفارشی (start/stop/status/restart مستقل)
 *
 * فیلدها:
 *   id        => شناسه یکتا (فقط حروف انگلیسی و خط تیره، بدون فاصله)
 *   name      => نام نمایشی فارسی
 *   type      => نوع مدیریت (pm2 / systemd / docker / script)
 *   path      => مسیر پوشه اپ روی سرور (برای git pull)
 *   git       => آدرس مخزن git (اختیاری، برای نمایش لینک)
 *   color     => رنگ کارت (کد hex یا نام css)
 *   icon      => آیکون SVG path یا ایموجی کوتاه
 *
 * برای pm2:
 *   pm2_name  => نام پروسه در PM2 (همان چیزی که در `pm2 list` نشان می‌دهد)
 *
 * برای systemd:
 *   service   => نام سرویس (مثال: nginx، mysql، myapp.service)
 *
 * برای docker:
 *   container => نام یا ID کانتینر
 *
 * برای script:
 *   start_cmd    => دستور اجرا (مثال: /opt/myapp/start.sh)
 *   stop_cmd     => دستور توقف
 *   restart_cmd  => دستور ریستارت
 *   status_cmd   => دستور بررسی وضعیت (خروجی باید شامل "running" یا exit code 0 باشد)
 */

return [

    // ======= مثال PM2 =======
    // [
    //     'id'       => 'my-node-app',
    //     'name'     => 'اپ Node.js اصلی',
    //     'type'     => 'pm2',
    //     'pm2_name' => 'my-node-app',
    //     'path'     => '/var/www/my-node-app',
    //     'git'      => 'https://github.com/youruser/my-node-app',
    //     'color'    => '#10b981',
    //     'icon'     => '⚙️',
    // ],

    // ======= مثال systemd =======
    // [
    //     'id'      => 'nginx',
    //     'name'    => 'وب سرور Nginx',
    //     'type'    => 'systemd',
    //     'service' => 'nginx',
    //     'path'    => '/etc/nginx',
    //     'color'   => '#2563eb',
    //     'icon'    => '🌐',
    // ],

    // ======= مثال Docker =======
    // [
    //     'id'        => 'myapp-docker',
    //     'name'      => 'اپ Docker',
    //     'type'      => 'docker',
    //     'container' => 'myapp_container',
    //     'path'      => '/opt/myapp',
    //     'git'       => 'https://github.com/youruser/myapp',
    //     'color'     => '#6366f1',
    //     'icon'      => '🐳',
    // ],

    // ===========================
    // اپ‌های خودت را اینجا اضافه کن
    // ===========================

    [
        'id'      => 'nginx',
        'name'    => 'وب سرور Nginx',
        'type'    => 'systemd',
        'service' => 'nginx',
        'path'    => '/etc/nginx',
        'color'   => '#059669',
        'icon'    => '🌐',
    ],
    [
        'id'      => 'mysql',
        'name'    => 'دیتابیس MySQL',
        'type'    => 'systemd',
        'service' => 'mysql',
        'path'    => '/var/lib/mysql',
        'color'   => '#2563eb',
        'icon'    => '🗄️',
    ],
    [
        'id'      => 'php-fpm',
        'name'    => 'PHP-FPM',
        'type'    => 'systemd',
        'service' => 'php8.2-fpm',
        'path'    => '/etc/php/8.2',
        'color'   => '#7c3aed',
        'icon'    => '🐘',
    ],

    // مثال اپ Node.js با PM2:
    // [
    //     'id'       => 'bot-telegram',
    //     'name'     => 'بات تلگرام',
    //     'type'     => 'pm2',
    //     'pm2_name' => 'telegram-bot',
    //     'path'     => '/home/ubuntu/telegram-bot',
    //     'git'      => 'https://github.com/youruser/telegram-bot',
    //     'color'    => '#0ea5e9',
    //     'icon'     => '🤖',
    // ],
];
