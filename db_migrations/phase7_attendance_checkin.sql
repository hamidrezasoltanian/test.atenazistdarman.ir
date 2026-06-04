-- db_migrations/phase7_attendance_checkin.sql
-- سیستم ورود/خروج آنلاین با GPS ماهواره‌ای + سلفی

-- جدول اصلی ورود/خروج لحظه‌ای
CREATE TABLE IF NOT EXISTS `attendance_checkins` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `check_in_time`   DATETIME NULL,
  `check_out_time`  DATETIME NULL,
  `check_in_lat`    DECIMAL(10,7) NULL,
  `check_in_lng`    DECIMAL(10,7) NULL,
  `check_in_acc`    FLOAT NULL COMMENT 'دقت GPS به متر',
  `check_in_dist`   INT NULL COMMENT 'فاصله از دفتر به متر',
  `check_out_lat`   DECIMAL(10,7) NULL,
  `check_out_lng`   DECIMAL(10,7) NULL,
  `check_out_acc`   FLOAT NULL,
  `check_out_dist`  INT NULL,
  `check_in_selfie` VARCHAR(255) NULL COMMENT 'فایل سلفی ورود',
  `check_out_selfie`VARCHAR(255) NULL COMMENT 'فایل سلفی خروج',
  `check_in_device` VARCHAR(255) NULL COMMENT 'user agent دستگاه',
  `check_in_ip`     VARCHAR(45) NULL,
  `geo_valid`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=داخل محدوده جغرافیایی',
  `mock_flag`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=مشکوک به جعل موقعیت',
  `status`          ENUM('present','absent','pending','flagged') NOT NULL DEFAULT 'present',
  `work_date`       DATE NOT NULL,
  `notes`           TEXT NULL,
  `admin_note`      TEXT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_user_date` (`user_id`, `work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تنظیمات موقعیت دفتر
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('office_lat',      '35.6892'),
('office_lng',      '51.3890'),
('office_radius',   '200'),
('checkin_require_gps',    '1'),
('checkin_require_selfie', '1'),
('checkin_allow_manual',   '0');
