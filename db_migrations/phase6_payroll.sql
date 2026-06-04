-- مرحله ۶: حقوق و دستمزد + پورسانت + KPI
-- -----------------------------------------------

CREATE TABLE IF NOT EXISTS `hr_commission_plans` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `name`          VARCHAR(100) NOT NULL DEFAULT 'پلن پیش‌فرض',
    `base_rate`     DECIMAL(7,4) NOT NULL DEFAULT 1.0000 COMMENT 'درصد پایه پورسانت',
    `threshold`     BIGINT       NOT NULL DEFAULT 2000000000 COMMENT 'آستانه پلکان (ریال)',
    `step_size`     BIGINT       NOT NULL DEFAULT 500000000  COMMENT 'اندازه هر پله (ریال)',
    `step_rate`     DECIMAL(7,4) NOT NULL DEFAULT 0.1000     COMMENT 'افزایش نرخ به ازای هر پله',
    `kpi_step_rate` DECIMAL(7,4) NOT NULL DEFAULT 0.2000     COMMENT 'افزایش نرخ با KPI بالا',
    `kpi_min_score` DECIMAL(5,2) NOT NULL DEFAULT 80.00      COMMENT 'حداقل نمره KPI برای نرخ دوتایی',
    `is_active`     TINYINT      NOT NULL DEFAULT 1,
    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `hr_commission_plans`
    (name, base_rate, threshold, step_size, step_rate, kpi_step_rate, kpi_min_score, is_active)
VALUES
    ('پلن پیش‌فرض', 1.0, 2000000000, 500000000, 0.1, 0.2, 80.00, 1);

CREATE TABLE IF NOT EXISTS `hr_kpi_scores` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT NOT NULL,
    `year`       SMALLINT NOT NULL,
    `quarter`    TINYINT  NOT NULL COMMENT '1-4',
    `score`      DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `notes`      TEXT DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_period` (`user_id`, `year`, `quarter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hr_commissions` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`           INT     NOT NULL,
    `invoice_id`        INT     NOT NULL,
    `year`              SMALLINT NOT NULL,
    `month`             TINYINT  NOT NULL,
    `invoice_amount`    BIGINT  NOT NULL DEFAULT 0,
    `extra_discount`    BIGINT  NOT NULL DEFAULT 0 COMMENT 'تخفیف اضافی به پزشک/بیمارستان',
    `net_base`          BIGINT  NOT NULL DEFAULT 0 COMMENT 'مبنای محاسبه = invoice_amount - extra_discount',
    `cumulative_before` BIGINT  NOT NULL DEFAULT 0 COMMENT 'مجموع فروش قبل از این فاکتور در ماه',
    `commission_rate`   DECIMAL(7,4) NOT NULL DEFAULT 0,
    `commission_amount` BIGINT  NOT NULL DEFAULT 0,
    `status`            ENUM('pending','payable','paid','cancelled') NOT NULL DEFAULT 'pending',
    `payroll_item_id`   INT DEFAULT NULL,
    `notes`             TEXT DEFAULT NULL,
    `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hr_payroll_periods` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `year`       SMALLINT NOT NULL,
    `month`      TINYINT  NOT NULL,
    `status`     ENUM('open','closed') NOT NULL DEFAULT 'open',
    `closed_at`  DATETIME DEFAULT NULL,
    `fin_doc_id` INT DEFAULT NULL,
    `notes`      TEXT DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_year_month` (`year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hr_payroll_items` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `period_id`        INT NOT NULL,
    `user_id`          INT NOT NULL,
    `base_salary`      BIGINT NOT NULL DEFAULT 0,
    `overtime_hours`   DECIMAL(6,2) NOT NULL DEFAULT 0,
    `overtime_rate`    BIGINT NOT NULL DEFAULT 0 COMMENT 'نرخ اضافه‌کار ساعتی',
    `overtime_amount`  BIGINT NOT NULL DEFAULT 0,
    `deductions`       BIGINT NOT NULL DEFAULT 0,
    `commission_amount`BIGINT NOT NULL DEFAULT 0,
    `bonus`            BIGINT NOT NULL DEFAULT 0,
    `gross_salary`     BIGINT NOT NULL DEFAULT 0,
    `net_salary`       BIGINT NOT NULL DEFAULT 0,
    `notes`            TEXT DEFAULT NULL,
    `fin_doc_id`       INT DEFAULT NULL,
    `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_period_user` (`period_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ایندکس‌های کارایی
CREATE INDEX IF NOT EXISTS `idx_commissions_user_year_month` ON `hr_commissions` (`user_id`, `year`, `month`);
CREATE INDEX IF NOT EXISTS `idx_commissions_invoice_id`      ON `hr_commissions` (`invoice_id`);
CREATE INDEX IF NOT EXISTS `idx_payroll_items_period`        ON `hr_payroll_items` (`period_id`);
CREATE INDEX IF NOT EXISTS `idx_kpi_user_year`               ON `hr_kpi_scores` (`user_id`, `year`);
