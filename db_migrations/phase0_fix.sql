-- ============================================================
-- Phase 0 Fix: ستون‌های missing از schema پایه
-- ============================================================

SET NAMES utf8mb4;

-- ── ستون last_seen در جدول users ─────────────────────────
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `last_seen` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `pin_code`  VARCHAR(10) NULL,
  ADD COLUMN IF NOT EXISTS `otp_code`  VARCHAR(10) NULL,
  ADD COLUMN IF NOT EXISTS `otp_expiry` DATETIME NULL;

-- ── status 'published' در announcements ──────────────────
-- جدول announcements با ENUM('active','inactive') ساخته شد
-- اما کد از 'published' استفاده می‌کند — ستون را تغییر می‌دهیم
ALTER TABLE `announcements`
  MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'published';

UPDATE `announcements` SET `status` = 'published' WHERE `status` = 'active';

-- ── ستون‌های missing در fin_bank_accounts ─────────────────
ALTER TABLE `fin_bank_accounts`
  ADD COLUMN IF NOT EXISTS `branch_name`   VARCHAR(100) NULL AFTER `bank_name`,
  ADD COLUMN IF NOT EXISTS `account_owner` VARCHAR(100) NULL AFTER `account_number`,
  ADD COLUMN IF NOT EXISTS `sheba_number`  VARCHAR(30)  NULL AFTER `account_owner`,
  ADD COLUMN IF NOT EXISTS `is_active`     TINYINT(1) NOT NULL DEFAULT 1 AFTER `balance`;

-- ── ستون‌های missing در fin_cashdesks ────────────────────
ALTER TABLE `fin_cashdesks`
  ADD COLUMN IF NOT EXISTS `user_id`     INT UNSIGNED NULL AFTER `description`,
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `is_active`   TINYINT(1) NOT NULL DEFAULT 1 AFTER `balance`,
  ADD COLUMN IF NOT EXISTS `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- ── ستون‌های missing در inv_storerooms ───────────────────
ALTER TABLE `inv_storerooms`
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `created_by`  INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS `is_deleted`  TINYINT(1) NOT NULL DEFAULT 0;

-- ── ستون‌های missing در inv_tickets ──────────────────────
ALTER TABLE `inv_tickets`
  ADD COLUMN IF NOT EXISTS `ticket_date`  VARCHAR(12) NULL AFTER `ticket_number`,
  ADD COLUMN IF NOT EXISTS `description`  TEXT NULL,
  ADD COLUMN IF NOT EXISTS `status`       ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
  ADD COLUMN IF NOT EXISTS `created_by`   INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ── ستون‌های missing در inv_ticket_items ─────────────────
ALTER TABLE `inv_ticket_items`
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `sort_order`  TINYINT NOT NULL DEFAULT 0;

-- ── ستون‌های missing در fin_invoices ─────────────────────
-- (جدول کامل از phase2 ساخته شد ولی چک می‌کنیم)
ALTER TABLE `fin_invoices`
  ADD COLUMN IF NOT EXISTS `due_date`       VARCHAR(12) NULL AFTER `invoice_date`,
  ADD COLUMN IF NOT EXISTS `customer_name`  VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(30) NULL DEFAULT 'cash';

-- ── ستون‌های missing در crm_opportunities ────────────────
ALTER TABLE `crm_opportunities`
  ADD COLUMN IF NOT EXISTS `province`    VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS `probability` TINYINT NOT NULL DEFAULT 50,
  ADD COLUMN IF NOT EXISTS `close_date`  VARCHAR(12) NULL;

-- ── جدول crm_weekplan اگر وجود ندارد ─────────────────────
CREATE TABLE IF NOT EXISTS `crm_weekplan` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `week_number`    INT NOT NULL,
  `fiscal_year_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `plan_data`      LONGTEXT NULL COMMENT 'JSON',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user_week` (`user_id`,`week_number`,`fiscal_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── جدول api_tokens اگر وجود ندارد ──────────────────────
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(64) NOT NULL UNIQUE,
  `name`       VARCHAR(100) NULL,
  `last_used`  DATETIME NULL,
  `expires_at` DATETIME NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user`  (`user_id`),
  KEY `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
