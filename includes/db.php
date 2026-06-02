<?php

if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    die('دسترسی مستقیم مجاز نیست.');
}

try {
 
    $configPath = __DIR__ . '/../Config/config.php';
    
   
    if (!file_exists($configPath)) {
        throw new Exception("فایل تنظیمات (config.php) پیدا نشد. لطفاً مسیر را بررسی کنید.");
    }

    
    $settings = require $configPath;

    
    $dsn = "mysql:host={$settings['db_host']};dbname={$settings['db_name']};charset={$settings['charset']}";
    
   
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     
        PDO::ATTR_EMULATE_PREPARES   => false,               
    ];

  
    $pdo = new PDO($dsn, $settings['db_user'], $settings['db_pass'], $options);

} catch (PDOException $e) {
   
    error_log("Database Connection Error: " . $e->getMessage());
    
    die("خطا در برقراری ارتباط با پایگاه داده. لطفاً با مدیر سیستم تماس بگیرید.");

} catch (Exception $e) {
    
    error_log("General Error: " . $e->getMessage());
    die("خطای سیستمی رخ داده است.");
}
?>