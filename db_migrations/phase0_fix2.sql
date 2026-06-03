-- ============================================================
-- Phase 0 Fix 2: اصلاح جداول crm_weekplan و fin_persons
-- ============================================================

SET NAMES utf8mb4;

-- ── جدول crm_weekplan — حذف و بازسازی با schema درست ────
DROP TABLE IF EXISTS `crm_weekplan`;
CREATE TABLE `crm_weekplan` (
  `id`                     INT AUTO_INCREMENT PRIMARY KEY,
  `week_start`             DATE NOT NULL COMMENT 'شروع هفته — شنبه',
  `user_id`                INT NOT NULL,
  `customer_id`            INT NOT NULL COMMENT 'از جدول customers',
  `action_type`            ENUM('call','visit') DEFAULT 'call',
  `scheduled_date`         DATE DEFAULT NULL,
  `done`                   TINYINT DEFAULT 0,
  `done_date`              DATE DEFAULT NULL,
  `linked_call_id`         INT DEFAULT NULL,
  `linked_mission_item_id` INT DEFAULT NULL,
  `notes`                  TEXT DEFAULT NULL,
  `created_at`             DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_week_user`  (`week_start`, `user_id`),
  KEY `idx_customer`   (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── fin_persons: اضافه کردن company_name ─────────────────
ALTER TABLE `fin_persons`
  ADD COLUMN IF NOT EXISTS `company_name` VARCHAR(200) NULL AFTER `name`;

-- ── customers: اضافه کردن ستون‌های missing ───────────────
ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `name`       VARCHAR(200) NULL AFTER `company_name`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `is_active`  TINYINT(1) NOT NULL DEFAULT 1;

-- ── fin_persons: اضافه کردن ستون phone اگر نیست ──────────
ALTER TABLE `fin_persons`
  ADD COLUMN IF NOT EXISTS `phone` VARCHAR(30) NULL AFTER `mobile`;
