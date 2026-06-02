-- ============================================================
-- Phase 2: Accounting Module — SQL Migrations
-- آتنا زیست درمان — مرحله ۲: ماژول حسابداری
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── ۱. طرف حساب‌ها (مشتری، تامین‌کننده، هر دو) ───────────────
CREATE TABLE IF NOT EXISTS `fin_persons` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`         VARCHAR(20) NULL COMMENT 'کد طرف حساب',
  `name`         VARCHAR(150) NOT NULL,
  `type`         ENUM('customer','supplier','both') NOT NULL DEFAULT 'customer',
  `national_id`  VARCHAR(20) NULL,
  `phone`        VARCHAR(30) NULL,
  `mobile`       VARCHAR(30) NULL,
  `email`        VARCHAR(100) NULL,
  `address`      TEXT NULL,
  `credit_limit` BIGINT NOT NULL DEFAULT 0 COMMENT 'سقف اعتبار (تومان)',
  `opening_balance` BIGINT NOT NULL DEFAULT 0 COMMENT 'مانده اول دوره',
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `notes`        TEXT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted`   TINYINT(1) NOT NULL DEFAULT 0,
  KEY `idx_type` (`type`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ۲. پلان حساب‌ها ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fin_chart_of_accounts` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`        VARCHAR(20) NOT NULL UNIQUE,
  `name`        VARCHAR(150) NOT NULL,
  `type`        ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  `nature`      ENUM('debit','credit') NOT NULL COMMENT 'ماهیت: بدهکار/بستانکار',
  `level`       TINYINT NOT NULL DEFAULT 1 COMMENT '1=گروه، 2=کل، 3=معین',
  `parent_id`   INT UNSIGNED NULL,
  `is_system`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'حساب سیستمی قابل حذف نیست',
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_code`   (`code`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ۳. اسناد حسابداری ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fin_docs` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `doc_number`      VARCHAR(30) NOT NULL,
  `doc_date`        VARCHAR(12) NOT NULL COMMENT 'تاریخ شمسی',
  `doc_date_g`      DATE NULL COMMENT 'تاریخ میلادی',
  `type`            VARCHAR(30) NOT NULL DEFAULT 'manual' COMMENT 'manual/sell/buy/payment/receipt/cheque',
  `description`     TEXT NULL,
  `fiscal_year_id`  INT UNSIGNED NOT NULL,
  `ref_id`          INT UNSIGNED NULL COMMENT 'شناسه سند مرجع (فاکتور و...)',
  `ref_type`        VARCHAR(30) NULL,
  `created_by`      INT UNSIGNED NOT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_docnum` (`doc_number`),
  KEY `idx_date`   (`doc_date`),
  KEY `idx_fiscal` (`fiscal_year_id`),
  KEY `idx_ref`    (`ref_id`,`ref_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ۴. ردیف‌های سند حسابداری ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `fin_doc_rows` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `doc_id`      INT UNSIGNED NOT NULL,
  `account_id`  INT UNSIGNED NOT NULL,
  `description` VARCHAR(255) NULL,
  `debit`       BIGINT NOT NULL DEFAULT 0,
  `credit`      BIGINT NOT NULL DEFAULT 0,
  `person_id`   INT UNSIGNED NULL COMMENT 'طرف حساب (اختیاری)',
  `sort_order`  TINYINT NOT NULL DEFAULT 0,
  KEY `idx_doc`     (`doc_id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ۵. فاکتورها (فروش و خرید) ────────────────────────────────
CREATE TABLE IF NOT EXISTS `fin_invoices` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_number`  VARCHAR(30) NOT NULL UNIQUE,
  `type`            ENUM('sell','buy') NOT NULL DEFAULT 'sell',
  `person_id`       INT UNSIGNED NULL COMMENT 'طرف حساب',
  `customer_name`   VARCHAR(150) NULL COMMENT 'نام مستقیم (اختیاری)',
  `invoice_date`    VARCHAR(12) NOT NULL COMMENT 'تاریخ شمسی',
  `due_date`        VARCHAR(12) NULL COMMENT 'تاریخ سررسید',
  `subtotal`        BIGINT NOT NULL DEFAULT 0,
  `discount`        BIGINT NOT NULL DEFAULT 0,
  `tax`             BIGINT NOT NULL DEFAULT 0,
  `total_amount`    BIGINT NOT NULL DEFAULT 0,
  `paid_amount`     BIGINT NOT NULL DEFAULT 0,
  `payment_method`  VARCHAR(30) NULL DEFAULT 'cash',
  `status`          ENUM('draft','confirmed','paid','partial','cancelled') NOT NULL DEFAULT 'draft',
  `notes`           TEXT NULL,
  `opportunity_id`  INT UNSIGNED NULL COMMENT 'فرصت CRM مرتبط',
  `fiscal_year_id`  INT UNSIGNED NULL,
  `fin_doc_id`      INT UNSIGNED NULL COMMENT 'سند حسابداری صادرشده',
  `created_by`      INT UNSIGNED NOT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted`      TINYINT(1) NOT NULL DEFAULT 0,
  KEY `idx_type`    (`type`),
  KEY `idx_status`  (`status`),
  KEY `idx_date`    (`invoice_date`),
  KEY `idx_person`  (`person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ۶. اقلام فاکتور ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fin_invoice_items` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_id`   INT UNSIGNED NOT NULL,
  `stuff_id`     INT UNSIGNED NULL COMMENT 'کالا از جدول stuffs',
  `description`  VARCHAR(255) NOT NULL,
  `unit`         VARCHAR(30) NULL DEFAULT 'عدد',
  `qty`          DECIMAL(12,2) NOT NULL DEFAULT 1,
  `unit_price`   BIGINT NOT NULL DEFAULT 0,
  `discount_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `discount_amt` BIGINT NOT NULL DEFAULT 0,
  `tax_pct`      DECIMAL(5,2) NOT NULL DEFAULT 0,
  `tax_amt`      BIGINT NOT NULL DEFAULT 0,
  `total`        BIGINT NOT NULL DEFAULT 0,
  `sort_order`   TINYINT NOT NULL DEFAULT 0,
  KEY `idx_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ۷. حساب‌های بانکی ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fin_bank_accounts` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bank_name`      VARCHAR(100) NOT NULL,
  `branch_name`    VARCHAR(100) NULL,
  `account_number` VARCHAR(30)  NULL,
  `sheba_number`   VARCHAR(30)  NULL,
  `account_owner`  VARCHAR(100) NULL,
  `balance`        BIGINT NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ۸. صندوق‌های نقدی ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fin_cashdesks` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `user_id`     INT UNSIGNED NULL COMMENT 'مسئول صندوق',
  `balance`     BIGINT NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ۹. چک‌ها ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `fin_cheques` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `cheque_number`   VARCHAR(30) NOT NULL,
  `type`            ENUM('received','issued') NOT NULL DEFAULT 'received',
  `bank_name`       VARCHAR(100) NULL,
  `branch`          VARCHAR(100) NULL,
  `amount`          BIGINT NOT NULL DEFAULT 0,
  `due_date`        DATE NOT NULL COMMENT 'تاریخ سررسید میلادی',
  `due_date_j`      VARCHAR(12) NULL COMMENT 'تاریخ سررسید شمسی',
  `status`          ENUM('pending','cleared','bounced','transferred') NOT NULL DEFAULT 'pending',
  `person_id`       INT UNSIGNED NULL,
  `person_name`     VARCHAR(150) NULL,
  `description`     TEXT NULL,
  `invoice_id`      INT UNSIGNED NULL,
  `bank_account_id` INT UNSIGNED NULL,
  `cleared_date`    DATE NULL,
  `created_by`      INT UNSIGNED NOT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted`      TINYINT(1) NOT NULL DEFAULT 0,
  KEY `idx_type`    (`type`),
  KEY `idx_status`  (`status`),
  KEY `idx_due`     (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── پلان حساب‌های پیش‌فرض ایران ─────────────────────────────
INSERT IGNORE INTO `fin_chart_of_accounts` (`code`,`name`,`type`,`nature`,`level`,`parent_id`,`is_system`) VALUES
-- گروه‌های اصلی
('1','دارایی‌ها','asset','debit',1,NULL,1),
('2','بدهی‌ها','liability','credit',1,NULL,1),
('3','حقوق صاحبان سهام','equity','credit',1,NULL,1),
('4','درآمدها','revenue','credit',1,NULL,1),
('5','هزینه‌ها','expense','debit',1,NULL,1),
-- دارایی جاری
('11','دارایی‌های جاری','asset','debit',2,NULL,1),
('1101','صندوق','asset','debit',3,NULL,1),
('1102','بانک','asset','debit',3,NULL,1),
('1103','حسابهای دریافتنی','asset','debit',3,NULL,1),
('1104','پیش پرداخت‌ها','asset','debit',3,NULL,1),
('1105','موجودی کالا','asset','debit',3,NULL,1),
-- دارایی ثابت
('12','دارایی‌های ثابت','asset','debit',2,NULL,1),
('1201','اموال و ماشین‌آلات','asset','debit',3,NULL,1),
('1202','استهلاک انباشته','asset','credit',3,NULL,1),
-- بدهی جاری
('21','بدهی‌های جاری','liability','credit',2,NULL,1),
('2101','حسابهای پرداختنی','liability','credit',3,NULL,1),
('2102','پیش دریافت‌ها','liability','credit',3,NULL,1),
('2103','مالیات پرداختنی','liability','credit',3,NULL,1),
('2104','حقوق و دستمزد پرداختنی','liability','credit',3,NULL,1),
-- حقوق صاحبان سهام
('31','سرمایه','equity','credit',2,NULL,1),
('3101','سرمایه صاحبان','equity','credit',3,NULL,1),
('3102','سود و زیان انباشته','equity','credit',3,NULL,1),
-- درآمدها
('41','درآمد عملیاتی','revenue','credit',2,NULL,1),
('4001','درآمد فروش','revenue','credit',3,NULL,1),
('4002','سایر درآمدها','revenue','credit',3,NULL,1),
-- هزینه‌ها
('51','هزینه‌های عملیاتی','expense','debit',2,NULL,1),
('5001','هزینه حقوق و دستمزد','expense','debit',3,NULL,1),
('5002','هزینه اجاره','expense','debit',3,NULL,1),
('5003','هزینه تبلیغات','expense','debit',3,NULL,1),
('5004','هزینه اداری','expense','debit',3,NULL,1),
('5005','بهای تمام‌شده کالای فروش‌رفته','expense','debit',3,NULL,1);

SET foreign_key_checks = 1;
