-- db_migrations/phase7b_qr_checkin.sql
-- سیستم حضور و غیاب آنلاین با QR یک‌بار مصرف + بررسی شبکه + Device Fingerprint

CREATE TABLE IF NOT EXISTS `attendance_qr_tokens` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `token`       VARCHAR(64) NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  DATETIME NOT NULL,
  `used_by`     INT UNSIGNED DEFAULT NULL COMMENT 'user_id که استفاده کرد',
  `used_at`     DATETIME DEFAULT NULL,
  `check_type`  ENUM('in','out') DEFAULT NULL,
  `used_ip`     VARCHAR(45) DEFAULT NULL,
  UNIQUE KEY `uq_token` (`token`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول ثبت check-in/out (جایگزین attendance_checkins ساده‌تر)
CREATE TABLE IF NOT EXISTS `attendance_checkins` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `check_in_time`   DATETIME NULL,
  `check_out_time`  DATETIME NULL,
  `check_in_ip`     VARCHAR(45) NULL,
  `check_out_ip`    VARCHAR(45) NULL,
  `check_in_device` VARCHAR(255) NULL COMMENT 'device fingerprint',
  `check_out_device`VARCHAR(255) NULL,
  `ip_valid`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=IP از subnet دفتر',
  `mock_flag`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=مشکوک',
  `status`          ENUM('present','partial','flagged') NOT NULL DEFAULT 'present',
  `work_date`       DATE NOT NULL,
  `notes`           TEXT NULL,
  `admin_note`      TEXT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_user_date` (`user_id`, `work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تنظیمات
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('checkin_qr_ttl',        '90'),
('checkin_office_subnet', ''),
('checkin_subnet_strict', '0'),
('checkin_workday_start', '07:00'),
('checkin_workday_end',   '20:00');
