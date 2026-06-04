-- db_migrations/phase8_new_features.sql
-- مرحله ۸ — ۱۲ فیچر جدید: قرارداد، هدف فروش، آفر، SMS، تیکت، گارانتی، نماینده، پیگیری، پورتال، مالیاتی

-- ─── قراردادها ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `contracts` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `contract_number` VARCHAR(30) NOT NULL,
  `title`           VARCHAR(200) NOT NULL,
  `person_id`       INT NOT NULL DEFAULT 0,
  `customer_name`   VARCHAR(200) DEFAULT NULL,
  `start_date`      DATE DEFAULT NULL,
  `end_date`        DATE DEFAULT NULL,
  `amount`          DECIMAL(20,0) DEFAULT 0,
  `paid_amount`     DECIMAL(20,0) DEFAULT 0,
  `status`          ENUM('draft','active','expired','cancelled') DEFAULT 'draft',
  `description`     TEXT DEFAULT NULL,
  `file_path`       VARCHAR(255) DEFAULT NULL,
  `created_by`      INT NOT NULL,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted`      TINYINT(1) DEFAULT 0,
  UNIQUE KEY `uq_contract_number` (`contract_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── هدف‌گذاری فروش ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `crm_sales_targets` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT NOT NULL,
  `year`            INT NOT NULL,
  `month`           INT NOT NULL,
  `target_amount`   DECIMAL(20,0) DEFAULT 0,
  `achieved_amount` DECIMAL(20,0) DEFAULT 0,
  `notes`           TEXT DEFAULT NULL,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_year_month` (`user_id`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── آفر / قیمت‌نامه رسمی ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fin_quotes` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `quote_number`   VARCHAR(30) NOT NULL,
  `quote_date`     DATE NOT NULL,
  `valid_until`    DATE DEFAULT NULL,
  `person_id`      INT NOT NULL DEFAULT 0,
  `customer_name`  VARCHAR(200) DEFAULT NULL,
  `customer_phone` VARCHAR(20) DEFAULT NULL,
  `subtotal`       DECIMAL(20,0) DEFAULT 0,
  `discount`       DECIMAL(20,0) DEFAULT 0,
  `tax`            DECIMAL(20,0) DEFAULT 0,
  `total_amount`   DECIMAL(20,0) DEFAULT 0,
  `status`         ENUM('draft','sent','accepted','rejected','converted') DEFAULT 'draft',
  `notes`          TEXT DEFAULT NULL,
  `invoice_id`     INT DEFAULT NULL,
  `created_by`     INT NOT NULL,
  `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted`     TINYINT(1) DEFAULT 0,
  UNIQUE KEY `uq_fq_number` (`quote_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_quote_items` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `quote_id`     INT NOT NULL,
  `stuff_id`     INT DEFAULT NULL,
  `description`  VARCHAR(500) NOT NULL DEFAULT '',
  `unit`         VARCHAR(30) DEFAULT NULL,
  `qty`          DECIMAL(12,4) NOT NULL DEFAULT 1,
  `unit_price`   DECIMAL(20,0) NOT NULL DEFAULT 0,
  `discount_pct` DECIMAL(5,2) DEFAULT 0,
  `tax_pct`      DECIMAL(5,2) DEFAULT 9,
  `total`        DECIMAL(20,0) NOT NULL DEFAULT 0,
  `row_order`    INT DEFAULT 0,
  INDEX `idx_quote_id` (`quote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── اعلانات SMS خودکار ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sms_automation_rules` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `event_type`       ENUM('invoice_confirmed','payment_due','payment_received','cheque_due','leave_approved','contract_expiry','welcome') NOT NULL,
  `name`             VARCHAR(200) NOT NULL,
  `message_template` TEXT NOT NULL,
  `is_active`        TINYINT(1) DEFAULT 1,
  `send_days_before` INT DEFAULT 0,
  `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sms_automation_log` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `rule_id`        INT DEFAULT NULL,
  `event_type`     VARCHAR(50) DEFAULT NULL,
  `phone`          VARCHAR(20) NOT NULL,
  `recipient_name` VARCHAR(200) DEFAULT NULL,
  `message`        TEXT NOT NULL,
  `status`         ENUM('sent','failed','pending') DEFAULT 'pending',
  `sent_at`        DATETIME DEFAULT NULL,
  `error_msg`      VARCHAR(500) DEFAULT NULL,
  INDEX `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `sms_automation_rules` (`event_type`, `name`, `message_template`, `is_active`) VALUES
('invoice_confirmed',  'تأیید فاکتور',    'مشتری گرامی {نام}، فاکتور شماره {شماره} به مبلغ {مبلغ} تومان صادر شد.', 1),
('payment_due',        'یادآور سررسید',   '{نام} عزیز، سررسید پرداخت {مبلغ} تومان در تاریخ {تاریخ} است.', 1),
('payment_received',   'تأیید دریافت',    'دریافت مبلغ {مبلغ} تومان از شما تأیید شد. ممنون از پرداخت به‌موقع.', 1),
('cheque_due',         'سررسید چک',       'یادآوری: چک شماره {شماره} به مبلغ {مبلغ} تومان در {تاریخ} سررسید دارد.', 1),
('leave_approved',     'تأیید مرخصی',     '{نام} عزیز، درخواست مرخصی شما برای تاریخ {تاریخ} تأیید شد.', 1),
('contract_expiry',    'انقضای قرارداد',  '{نام} گرامی، قرارداد شما در تاریخ {تاریخ} منقضی می‌شود. لطفاً برای تمدید اقدام فرمایید.', 1),
('welcome',            'خوش‌آمدگویی',     'به آتنا زیست درمان خوش آمدید {نام}. برای اطلاعات بیشتر با ما تماس بگیرید.', 1);

-- ─── تیکت پشتیبانی پس از فروش ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_number`  VARCHAR(20) NOT NULL,
  `subject`        VARCHAR(200) NOT NULL,
  `person_id`      INT DEFAULT NULL,
  `customer_name`  VARCHAR(200) DEFAULT NULL,
  `invoice_id`     INT DEFAULT NULL,
  `priority`       ENUM('low','medium','high','critical') DEFAULT 'medium',
  `status`         ENUM('open','in_progress','waiting','resolved','closed') DEFAULT 'open',
  `category`       VARCHAR(100) DEFAULT NULL,
  `assigned_to`    INT DEFAULT NULL,
  `created_by`     INT NOT NULL,
  `description`    TEXT DEFAULT NULL,
  `resolution`     TEXT DEFAULT NULL,
  `resolved_at`    DATETIME DEFAULT NULL,
  `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted`     TINYINT(1) DEFAULT 0,
  UNIQUE KEY `uq_ticket_number` (`ticket_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_ticket_replies` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id`   INT NOT NULL,
  `user_id`     INT NOT NULL,
  `message`     TEXT NOT NULL,
  `is_internal` TINYINT(1) DEFAULT 0,
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── گارانتی / وارانتی ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `warranty_records` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `serial_number`   VARCHAR(100) NOT NULL,
  `stuff_id`        INT DEFAULT NULL,
  `stuff_name`      VARCHAR(200) DEFAULT NULL,
  `invoice_item_id` INT DEFAULT NULL,
  `invoice_id`      INT DEFAULT NULL,
  `person_id`       INT DEFAULT NULL,
  `customer_name`   VARCHAR(200) DEFAULT NULL,
  `sale_date`       DATE DEFAULT NULL,
  `warranty_months` INT DEFAULT 12,
  `expiry_date`     DATE DEFAULT NULL,
  `status`          ENUM('active','expired','claimed','void') DEFAULT 'active',
  `notes`           TEXT DEFAULT NULL,
  `created_by`      INT NOT NULL,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted`      TINYINT(1) DEFAULT 0,
  KEY `idx_serial` (`serial_number`),
  KEY `idx_expiry` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── نمایندگان / توزیع‌کنندگان ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `dealers` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(200) NOT NULL,
  `code`             VARCHAR(30) DEFAULT NULL,
  `region`           VARCHAR(100) DEFAULT NULL,
  `province_id`      INT DEFAULT NULL,
  `contact_name`     VARCHAR(200) DEFAULT NULL,
  `phone`            VARCHAR(20) DEFAULT NULL,
  `email`            VARCHAR(100) DEFAULT NULL,
  `address`          TEXT DEFAULT NULL,
  `commission_rate`  DECIMAL(5,2) DEFAULT 0,
  `credit_limit`     DECIMAL(20,0) DEFAULT 0,
  `current_balance`  DECIMAL(20,0) DEFAULT 0,
  `status`           ENUM('active','inactive','suspended') DEFAULT 'active',
  `notes`            TEXT DEFAULT NULL,
  `created_by`       INT NOT NULL,
  `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted`       TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── پیگیری خودکار مشتریان ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `crm_followup_rules` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(200) NOT NULL,
  `days_since_last_order` INT DEFAULT 30,
  `message_template` TEXT DEFAULT NULL,
  `send_sms`         TINYINT(1) DEFAULT 0,
  `is_active`        TINYINT(1) DEFAULT 1,
  `created_by`       INT NOT NULL,
  `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_followup_log` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `rule_id`          INT DEFAULT NULL,
  `person_id`        INT NOT NULL,
  `customer_name`    VARCHAR(200) DEFAULT NULL,
  `last_order_date`  DATE DEFAULT NULL,
  `sent_at`          DATETIME DEFAULT CURRENT_TIMESTAMP,
  `sms_status`       VARCHAR(50) DEFAULT NULL,
  `notes`            TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── پورتال مشتری ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `customer_portal_sessions` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `person_id`   INT NOT NULL,
  `token`       VARCHAR(64) NOT NULL,
  `phone`       VARCHAR(20) NOT NULL,
  `otp`         VARCHAR(6) DEFAULT NULL,
  `otp_expires` DATETIME DEFAULT NULL,
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_used`   DATETIME DEFAULT NULL,
  `is_active`   TINYINT(1) DEFAULT 1,
  UNIQUE KEY `uq_token` (`token`),
  INDEX `idx_person_id` (`person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── سامانه مالیاتی ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tax_invoices` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id`       INT NOT NULL,
  `invoice_number`   VARCHAR(30) DEFAULT NULL,
  `tax_serial`       VARCHAR(100) DEFAULT NULL,
  `tax_uid`          VARCHAR(200) DEFAULT NULL,
  `status`           ENUM('pending','sent','confirmed','rejected','error') DEFAULT 'pending',
  `total_amount`     DECIMAL(20,0) DEFAULT 0,
  `tax_amount`       DECIMAL(20,0) DEFAULT 0,
  `response_code`    VARCHAR(50) DEFAULT NULL,
  `response_message` TEXT DEFAULT NULL,
  `sent_at`          DATETIME DEFAULT NULL,
  `confirmed_at`     DATETIME DEFAULT NULL,
  `created_by`       INT NOT NULL,
  `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_invoice_id` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── تنظیمات مرتبط ──────────────────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('tax_api_enabled',    '0'),
('tax_api_base_url',   'https://tp.tax.gov.ir/req/api'),
('tax_company_name',   'آتنا زیست درمان'),
('tax_economic_code',  ''),
('tax_national_id',    ''),
('tax_postal_code',    ''),
('tax_token',          ''),
('tax_private_key',    '');
