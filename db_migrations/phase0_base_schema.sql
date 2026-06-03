-- ============================================================
-- Phase 0: Schema پایه — آتنا زیست درمان
-- تمام جداول اصلی پروژه (بیس اولیه)
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── کاربران و نقش‌ها ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(50) NOT NULL UNIQUE,
  `title`       VARCHAR(100) NOT NULL,
  `is_system`   TINYINT(1) NOT NULL DEFAULT 0,
  `permissions` LONGTEXT NULL COMMENT 'JSON آرایه مجوزها'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `roles` (`name`,`title`,`is_system`,`permissions`) VALUES
  ('admin','مدیر سیستم',1,'["all"]'),
  ('user','کاربر عادی',1,'[]');

CREATE TABLE IF NOT EXISTS `departments` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100) NOT NULL,
  `manager_id` INT UNSIGNED NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `departments` (`id`,`name`,`status`) VALUES (1,'مدیریت','active');

CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `first_name`     VARCHAR(80) NOT NULL,
  `last_name`      VARCHAR(80) NOT NULL,
  `username`       VARCHAR(50) NOT NULL UNIQUE,
  `password`       VARCHAR(255) NOT NULL,
  `mobile`         VARCHAR(20) NULL UNIQUE,
  `national_code`  VARCHAR(20) NULL,
  `personnel_code` VARCHAR(30) NULL,
  `birth_date`     VARCHAR(12) NULL,
  `postal_code`    VARCHAR(15) NULL,
  `address`        TEXT NULL,
  `department_id`  INT UNSIGNED NULL,
  `role`           VARCHAR(50) NOT NULL DEFAULT 'user',
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `profile_image`  VARCHAR(255) NULL DEFAULT 'default.png',
  `chat_permissions` VARCHAR(50) NULL DEFAULT 'all',
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `name`           VARCHAR(161) GENERATED ALWAYS AS (CONCAT(`first_name`,' ',`last_name`)) STORED,
  `email`          VARCHAR(100) NULL,
  `role_id`        INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- کاربر ادمین پیش‌فرض (رمز: Admin@1234)
INSERT IGNORE INTO `users`
  (`id`,`first_name`,`last_name`,`username`,`password`,`mobile`,`role`,`status`,`is_active`)
VALUES
  (1,'مدیر','سیستم','admin',
   '$2y$10$TKh8H1.PfY5WoKp3UGK.0eEzh6OhqMBYGK16jEGOLkEPJN0Hy3W0y',
   '09000000000','admin','active',1);

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(100) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`,`setting_value`) VALUES
  ('company_name','آتنا زیست درمان'),
  ('site_logo',''),
  ('sms_enabled','0');

CREATE TABLE IF NOT EXISTS `system_logs` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NULL,
  `ip_address`  VARCHAR(45) NULL,
  `section`     VARCHAR(50) NULL,
  `action`      VARCHAR(100) NULL,
  `record_id`   INT UNSIGNED NULL,
  `description` TEXT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user`    (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `title`      VARCHAR(200) NOT NULL,
  `body`       TEXT NULL,
  `url`        VARCHAR(500) NULL,
  `type`       VARCHAR(50) NULL DEFAULT 'info',
  `ref_id`     INT UNSIGNED NULL,
  `icon_url`   VARCHAR(255) NULL,
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user`    (`user_id`),
  KEY `idx_is_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── مشتریان و تگ‌ها ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tags` (
  `id`    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(100) NOT NULL,
  `color` VARCHAR(20) NULL DEFAULT '#6c757d'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customers` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_num`  VARCHAR(30) NULL,
  `company_name` VARCHAR(200) NOT NULL,
  `company_code` VARCHAR(30) NULL,
  `type_name`    VARCHAR(100) NULL,
  `manager_name` VARCHAR(100) NULL,
  `mobile`       VARCHAR(20) NULL,
  `phone`        VARCHAR(20) NULL,
  `state`        VARCHAR(50) NULL,
  `city`         VARCHAR(50) NULL,
  `address`      TEXT NULL,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_name`  (`company_name`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_followers` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_num` VARCHAR(30) NULL,
  `customer_id` INT UNSIGNED NULL,
  `full_name`   VARCHAR(100) NULL,
  `username`    VARCHAR(50) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_tag_links` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT UNSIGNED NOT NULL,
  `tag_id`      INT UNSIGNED NOT NULL,
  UNIQUE KEY `uniq` (`customer_id`,`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_addresses` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `company_num` VARCHAR(30) NULL,
  `state`       VARCHAR(50) NULL,
  `city`        VARCHAR(50) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── کالا و موجودی پایه ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `stuffs` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `stuff_name`     VARCHAR(200) NOT NULL,
  `stuff_code`     VARCHAR(50) NULL,
  `technical_code` VARCHAR(50) NULL,
  `iran_code`      VARCHAR(50) NULL,
  `is_delete`      TINYINT(1) NOT NULL DEFAULT 0,
  `active`         TINYINT(1) NOT NULL DEFAULT 1,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `save_date`      VARCHAR(12) NULL,
  `code`           VARCHAR(50) NULL,
  `name`           VARCHAR(200) NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stuff_price_list` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `stuff_id`        INT UNSIGNED NOT NULL,
  `stuff_code`      VARCHAR(50) NULL,
  `price`           BIGINT NOT NULL DEFAULT 0,
  `price_imed`      BIGINT NOT NULL DEFAULT 0,
  `price_faradis`   BIGINT NOT NULL DEFAULT 0,
  `price_dermazon`  BIGINT NOT NULL DEFAULT 0,
  `total_inventory` BIGINT NOT NULL DEFAULT 0,
  `central_store`   BIGINT NOT NULL DEFAULT 0,
  `virtual_store`   BIGINT NOT NULL DEFAULT 0,
  `scrap_store`     BIGINT NOT NULL DEFAULT 0,
  `minimum_stock`   BIGINT NOT NULL DEFAULT 0,
  `unit_price`      BIGINT NOT NULL DEFAULT 0,
  UNIQUE KEY `uniq_stuff` (`stuff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_tag_links` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `tag_id`     INT UNSIGNED NOT NULL,
  UNIQUE KEY `uniq` (`product_id`,`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discount_codes` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`            VARCHAR(50) NOT NULL UNIQUE,
  `discount_amount` BIGINT NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── سال مالی ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fiscal_years` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(100) NOT NULL,
  `start_date`  VARCHAR(12) NOT NULL COMMENT 'شمسی',
  `end_date`    VARCHAR(12) NOT NULL COMMENT 'شمسی',
  `status`      ENUM('active','closed','future') NOT NULL DEFAULT 'active',
  `description` TEXT NULL,
  `is_current`  TINYINT(1) NOT NULL DEFAULT 0,
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `fiscal_years` (`id`,`title`,`start_date`,`end_date`,`status`,`is_current`,`created_by`)
VALUES (1,'سال مالی ۱۴۰۴','1404/01/01','1404/12/29','active',1,1);

-- ── مالی پایه ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fin_categories` (
  `id`     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`  VARCHAR(100) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_accounts` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`      VARCHAR(100) NOT NULL,
  `type`       VARCHAR(30) NULL,
  `user_id`    INT UNSIGNED NULL,
  `balance`    BIGINT NOT NULL DEFAULT 0,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_expenses` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `applicant_id`   INT UNSIGNED NULL,
  `account_id`     INT UNSIGNED NULL,
  `category_id`    INT UNSIGNED NULL,
  `amount`         BIGINT NOT NULL DEFAULT 0,
  `description`    TEXT NULL,
  `invoice_date`   VARCHAR(12) NULL,
  `attachment`     VARCHAR(255) NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `report_id`      INT UNSIGNED NULL,
  `reject_reason`  TEXT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_transactions` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `fiscal_year_id`  INT UNSIGNED NOT NULL,
  `from_account_id` INT UNSIGNED NULL,
  `to_account_id`   INT UNSIGNED NULL,
  `amount`          BIGINT NOT NULL DEFAULT 0,
  `type`            VARCHAR(30) NULL,
  `reference_id`    INT UNSIGNED NULL,
  `description`     TEXT NULL,
  `created_by`      INT UNSIGNED NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_charge_requests` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `fiscal_year_id` INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `account_id`     INT UNSIGNED NULL,
  `amount`         BIGINT NOT NULL DEFAULT 0,
  `description`    TEXT NULL,
  `status`         ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
  `admin_id`       INT UNSIGNED NULL,
  `finance_id`     INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CRM ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `crm_boards` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `crm_boards` (`id`,`name`,`created_by`) VALUES (1,'بورد فروش اصلی',1);

CREATE TABLE IF NOT EXISTS `crm_board_stages` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `board_id`    INT UNSIGNED NOT NULL DEFAULT 1,
  `name`        VARCHAR(100) NOT NULL,
  `color_class` VARCHAR(50) NULL DEFAULT 'blue',
  `order_num`   INT NOT NULL DEFAULT 0,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `position`    INT NOT NULL DEFAULT 0,
  KEY `idx_board` (`board_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `crm_board_stages` (`id`,`board_id`,`name`,`color_class`,`sort_order`) VALUES
  (1,1,'بدون تماس','gray',1),
  (2,1,'تماس اولیه','blue',2),
  (3,1,'ملاقات انجام شد','purple',3),
  (4,1,'پیشنهاد ارسال شد','amber',4),
  (5,1,'قرارداد بسته شد','green',5),
  (6,1,'غیرفعال','rose',6);

CREATE TABLE IF NOT EXISTS `crm_opportunities` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `board_id`       INT UNSIGNED NOT NULL DEFAULT 1,
  `stage_id`       INT UNSIGNED NOT NULL,
  `customer_id`    INT UNSIGNED NULL,
  `title`          VARCHAR(200) NOT NULL,
  `value`          BIGINT NOT NULL DEFAULT 0,
  `assigned_to`    INT UNSIGNED NULL,
  `created_by`     INT UNSIGNED NOT NULL,
  `status`         VARCHAR(30) NOT NULL DEFAULT 'active',
  `selected_weeks` TEXT NULL,
  `description`    TEXT NULL,
  `is_deleted`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_stage`   (`stage_id`),
  KEY `idx_deleted` (`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_opportunity_assignees` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `opportunity_id` INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  KEY `idx_opp` (`opportunity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_opportunity_stage_logs` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `opportunity_id` INT UNSIGNED NOT NULL,
  `stage_id`       INT UNSIGNED NOT NULL,
  `entered_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `accumulated_time` INT NOT NULL DEFAULT 0,
  KEY `idx_opp` (`opportunity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_opportunity_calls` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `opportunity_id` INT UNSIGNED NOT NULL,
  `customer_id`    INT UNSIGNED NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `subject`        VARCHAR(200) NULL,
  `status`         VARCHAR(30) NULL DEFAULT 'completed',
  `related_to`     VARCHAR(50) NULL,
  `call_date`      VARCHAR(12) NULL,
  `call_type`      ENUM('incoming','outgoing') NOT NULL DEFAULT 'outgoing',
  `description`    TEXT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_opp` (`opportunity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_opportunity_tasks` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `opportunity_id` INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `subject`        VARCHAR(200) NOT NULL,
  `deadline`       VARCHAR(12) NULL,
  `status`         ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
  `description`    TEXT NULL,
  `file_path`      VARCHAR(255) NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_opp` (`opportunity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_opportunity_notes` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `opportunity_id` INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `note_text`      TEXT NOT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_opp` (`opportunity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_opportunity_activities` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `opportunity_id` INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `activity_type`  VARCHAR(50) NULL,
  `description`    TEXT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_opp` (`opportunity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_opportunity_comments` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `opportunity_id` INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `comment_text`   TEXT NOT NULL,
  `file_path`      VARCHAR(255) NULL,
  `file_type`      VARCHAR(50) NULL,
  `reply_to_id`    INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_opp` (`opportunity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_contacts` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT UNSIGNED NULL,
  `name`        VARCHAR(150) NOT NULL,
  `position`    VARCHAR(100) NULL,
  `phone`       VARCHAR(20) NULL,
  `mobile`      VARCHAR(20) NULL,
  `email`       VARCHAR(100) NULL,
  `notes`       TEXT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_provinces` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `province_name` VARCHAR(50) NOT NULL,
  `potential`     TINYINT NOT NULL DEFAULT 3
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `crm_provinces` (`province_name`,`potential`) VALUES
('تهران',5),('اصفهان',4),('فارس',4),('خراسان رضوی',4),('آذربایجان شرقی',3),
('آذربایجان غربی',3),('اردبیل',2),('البرز',4),('ایلام',2),('بوشهر',2),
('چهارمحال و بختیاری',2),('خراسان جنوبی',2),('خراسان شمالی',2),('خوزستان',4),
('زنجان',2),('سمنان',2),('سیستان و بلوچستان',2),('گیلان',3),('گلستان',3),
('گرمسار',2),('همدان',3),('هرمزگان',3),('کردستان',3),('کرمان',3),
('کرمانشاه',3),('کهگیلویه و بویراحمد',2),('لرستان',2),('مازندران',4),
('مرکزی',3),('قزوین',3),('قم',3),('یزد',3);

CREATE TABLE IF NOT EXISTS `crm_user_provinces` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED NOT NULL,
  `province_name` VARCHAR(50) NOT NULL,
  UNIQUE KEY `uniq` (`user_id`,`province_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_receivables` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT UNSIGNED NULL,
  `amount`      BIGINT NOT NULL DEFAULT 0,
  `due_date`    VARCHAR(12) NULL,
  `status`      ENUM('pending','paid','overdue') NOT NULL DEFAULT 'pending',
  `description` TEXT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_call_related_types` (
  `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`     VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `crm_call_related_types` (`title`) VALUES ('فروش'),('پشتیبانی'),('مشاوره'),('پیگیری');

-- ── HR ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `leave_requests` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `leave_type`     VARCHAR(50) NOT NULL DEFAULT 'hourly',
  `start_date`     VARCHAR(12) NOT NULL,
  `end_date`       VARCHAR(12) NULL,
  `start_time`     VARCHAR(10) NULL,
  `end_time`       VARCHAR(10) NULL,
  `user_reason`    TEXT NULL,
  `attachment`     VARCHAR(255) NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `manager_id`     INT UNSIGNED NULL,
  `manager_note`   TEXT NULL,
  `admin_id`       INT UNSIGNED NULL,
  `admin_note`     TEXT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_requests` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `type`           VARCHAR(30) NOT NULL DEFAULT 'correction',
  `attendance_date` VARCHAR(12) NULL,
  `target_date`    VARCHAR(12) NULL,
  `time_start`     VARCHAR(10) NULL,
  `time_end`       VARCHAR(10) NULL,
  `user_reason`    TEXT NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `manager_id`     INT UNSIGNED NULL,
  `manager_note`   TEXT NULL,
  `admin_id`       INT UNSIGNED NULL,
  `admin_note`     TEXT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_corrections` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `type`           VARCHAR(30) NOT NULL DEFAULT 'correction',
  `target_date`    VARCHAR(12) NULL,
  `time_start`     VARCHAR(10) NULL,
  `time_end`       VARCHAR(10) NULL,
  `user_reason`    TEXT NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `manager_id`     INT UNSIGNED NULL,
  `manager_note`   TEXT NULL,
  `admin_id`       INT UNSIGNED NULL,
  `admin_note`     TEXT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mission_types` (
  `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`     VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `mission_types` (`title`) VALUES ('ویزیت مشتری'),('شرکت در نمایشگاه'),('آموزش'),('اداری');

CREATE TABLE IF NOT EXISTS `mission_requests` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `status`         ENUM('pending','approved','rejected','done') NOT NULL DEFAULT 'pending',
  `description`    TEXT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mission_items` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_id`   INT UNSIGNED NOT NULL,
  `type_id`      INT UNSIGNED NULL,
  `customer_id`  INT UNSIGNED NULL,
  `mission_date` VARCHAR(12) NULL,
  `description`  TEXT NULL,
  KEY `idx_req` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mission_expenses` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_id`      INT UNSIGNED NOT NULL,
  `item_id`         INT UNSIGNED NULL,
  `journey_type`    VARCHAR(50) NULL,
  `amount`          BIGINT NOT NULL DEFAULT 0,
  `status`          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `finance_note`    TEXT NULL,
  `approved_amount` BIGINT NULL,
  `paid_at`         DATETIME NULL,
  KEY `idx_req` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ارتباطات (چت، اعلانات) ────────────────────────────────
CREATE TABLE IF NOT EXISTS `conversations` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`      VARCHAR(200) NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_participants` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL,
  UNIQUE KEY `uniq` (`conversation_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT UNSIGNED NOT NULL,
  `sender_id`       INT UNSIGNED NOT NULL,
  `message_text`    MEDIUMTEXT NULL,
  `file_path`       VARCHAR(255) NULL,
  `file_type`       VARCHAR(50) NULL,
  `is_edited`       TINYINT(1) NOT NULL DEFAULT 0,
  `is_deleted`      TINYINT(1) NOT NULL DEFAULT 0,
  `is_read`         TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_conv` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_categories` (
  `id`    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(100) NOT NULL,
  `color` VARCHAR(20) NULL DEFAULT '#6c757d'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(200) NOT NULL,
  `content`     TEXT NOT NULL,
  `priority`    ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`  INT UNSIGNED NOT NULL,
  `type`        VARCHAR(30) NULL DEFAULT 'general',
  `link`        VARCHAR(500) NULL,
  `category_id` INT UNSIGNED NULL,
  `attachment`  VARCHAR(255) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_targets` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `announcement_id` INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_reads` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `announcement_id` INT UNSIGNED NOT NULL,
  `read_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq` (`user_id`,`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_hidden_announcements` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `announcement_id` INT UNSIGNED NOT NULL,
  UNIQUE KEY `uniq` (`user_id`,`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── اتوماسیون اداری (نامه‌ها) ─────────────────────────────
CREATE TABLE IF NOT EXISTS `letter_templates` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`      VARCHAR(200) NOT NULL,
  `content`    LONGTEXT NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `letter_indicators` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `fiscal_year_id`   INT UNSIGNED NOT NULL DEFAULT 1,
  `department_prefix` VARCHAR(10) NULL,
  `letter_type`      VARCHAR(20) NOT NULL DEFAULT 'outgoing',
  `last_sequence`    INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `letters` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `letter_number`    VARCHAR(50) NULL,
  `type`             ENUM('incoming','outgoing','internal') NOT NULL DEFAULT 'internal',
  `subject`          VARCHAR(300) NOT NULL,
  `content`          LONGTEXT NULL,
  `sender_id`        INT UNSIGNED NULL,
  `created_by`       INT UNSIGNED NOT NULL,
  `status`           VARCHAR(30) NOT NULL DEFAULT 'draft',
  `signature_status` VARCHAR(30) NULL DEFAULT 'pending',
  `indicator_number` VARCHAR(50) NULL,
  `is_archived`      TINYINT(1) NOT NULL DEFAULT 0,
  `is_deleted`       TINYINT(1) NOT NULL DEFAULT 0,
  `use_letterhead`   TINYINT(1) NOT NULL DEFAULT 1,
  `registered_at`    VARCHAR(12) NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_type`    (`type`),
  KEY `idx_deleted` (`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `letter_receivers` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `letter_id`     INT UNSIGNED NOT NULL,
  `receiver_type` VARCHAR(30) NULL,
  `receiver_id`   INT UNSIGNED NULL,
  KEY `idx_letter` (`letter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `letter_referrals` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `letter_id`       INT UNSIGNED NOT NULL,
  `sender_id`       INT UNSIGNED NOT NULL,
  `receiver_id`     INT UNSIGNED NOT NULL,
  `action_type`     VARCHAR(50) NULL,
  `description`     TEXT NULL,
  `is_completed`    TINYINT(1) NOT NULL DEFAULT 0,
  `completed_at`    DATETIME NULL,
  `completion_note` TEXT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_letter` (`letter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `letter_attachments` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `letter_id`   INT UNSIGNED NOT NULL,
  `file_name`   VARCHAR(255) NULL,
  `file_path`   VARCHAR(500) NOT NULL,
  `file_type`   VARCHAR(50) NULL,
  `uploaded_by` INT UNSIGNED NULL,
  KEY `idx_letter` (`letter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── یادداشت‌ها ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notes` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `title`       VARCHAR(200) NULL,
  `content`     TEXT NULL,
  `color`       VARCHAR(20) NULL DEFAULT '#ffffff',
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
