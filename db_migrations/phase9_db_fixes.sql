-- ============================================================
-- Phase 9: رفع مشکلات ساختاری دیتابیس
-- آتنا زیست درمان
-- تاریخ: ۱۴۰۵/۰۳/۱۴
-- ============================================================
-- این migration مشکلات زیر را برطرف می‌کند:
--   ۱. اضافه کردن AUTO_INCREMENT به fin_chart_of_accounts
--   ۲. اضافه کردن is_deleted به جداول فاقد soft-delete
--   ۳. اضافه کردن created_at/updated_at به جداول فاقد timestamp
--   ۴. اضافه کردن ایندکس‌های missing روی ستون‌های FK
--   ۵. اضافه کردن ایندکس روی ستون‌های پرکوئری
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- ۱. رفع fin_chart_of_accounts — اضافه کردن AUTO_INCREMENT
--    مشکل: id از نوع INT NOT NULL بدون AUTO_INCREMENT بود
--    هر INSERT دستی بدون id صریح با خطا مواجه می‌شد
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `fin_chart_of_accounts`
  MODIFY COLUMN `id` INT NOT NULL AUTO_INCREMENT;

-- ─────────────────────────────────────────────────────────────
-- ۲. اضافه کردن is_deleted به جداول فاقد soft delete
-- ─────────────────────────────────────────────────────────────

ALTER TABLE `fin_quote_items`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `support_ticket_replies`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `sms_automation_rules`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `crm_followup_rules`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `warranty_records`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `hr_commission_plans`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `hr_payroll_periods`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `hr_payroll_items`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `hr_commissions`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `hr_kpi_scores`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `dealers`
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0;

-- ─────────────────────────────────────────────────────────────
-- ۳. اضافه کردن created_at / updated_at به جداول فاقد timestamp
-- ─────────────────────────────────────────────────────────────

-- fin_quote_items
ALTER TABLE `fin_quote_items`
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- support_ticket_replies (فقط updated_at ندارد)
ALTER TABLE `support_ticket_replies`
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- sms_automation_rules (فقط updated_at ندارد)
ALTER TABLE `sms_automation_rules`
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- crm_followup_rules (فقط updated_at ندارد)
ALTER TABLE `crm_followup_rules`
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- hr_commission_plans (فقط updated_at ندارد)
ALTER TABLE `hr_commission_plans`
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- hr_payroll_periods (فقط updated_at ندارد)
ALTER TABLE `hr_payroll_periods`
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- attendance_checkins (فقط updated_at ندارد)
ALTER TABLE `attendance_checkins`
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ─────────────────────────────────────────────────────────────
-- ۴. اضافه کردن ایندکس‌های missing روی FK columns
-- ─────────────────────────────────────────────────────────────

-- crm_followup_log
ALTER TABLE `crm_followup_log`
  ADD INDEX IF NOT EXISTS `idx_rule_id`   (`rule_id`),
  ADD INDEX IF NOT EXISTS `idx_person_id` (`person_id`);

-- hr_commissions — user_id
ALTER TABLE `hr_commissions`
  ADD INDEX IF NOT EXISTS `idx_user_id` (`user_id`);

-- hr_payroll_items — user_id
ALTER TABLE `hr_payroll_items`
  ADD INDEX IF NOT EXISTS `idx_user_id` (`user_id`);

-- attendance_qr_tokens — used_by
ALTER TABLE `attendance_qr_tokens`
  ADD INDEX IF NOT EXISTS `idx_used_by` (`used_by`);

-- attendance_checkins — user_id
ALTER TABLE `attendance_checkins`
  ADD INDEX IF NOT EXISTS `idx_user_id` (`user_id`);

-- ─────────────────────────────────────────────────────────────
-- ۵. ایندکس‌های عملکردی اضافی روی ستون‌های پرکوئری
-- ─────────────────────────────────────────────────────────────

-- contracts — person_id و status (برای گزارش‌ها)
ALTER TABLE `contracts`
  ADD INDEX IF NOT EXISTS `idx_person_id` (`person_id`),
  ADD INDEX IF NOT EXISTS `idx_status`    (`status`),
  ADD INDEX IF NOT EXISTS `idx_end_date`  (`end_date`);

-- fin_quotes — person_id
ALTER TABLE `fin_quotes`
  ADD INDEX IF NOT EXISTS `idx_person_id` (`person_id`),
  ADD INDEX IF NOT EXISTS `idx_status`    (`status`);

-- support_tickets — person_id, assigned_to, status
ALTER TABLE `support_tickets`
  ADD INDEX IF NOT EXISTS `idx_person_id`   (`person_id`),
  ADD INDEX IF NOT EXISTS `idx_assigned_to` (`assigned_to`),
  ADD INDEX IF NOT EXISTS `idx_status`      (`status`);

-- warranty_records — stuff_id, expiry_date
ALTER TABLE `warranty_records`
  ADD INDEX IF NOT EXISTS `idx_stuff_id`    (`stuff_id`),
  ADD INDEX IF NOT EXISTS `idx_expiry_date` (`expiry_date`);

-- dealers — is_deleted
ALTER TABLE `dealers`
  ADD INDEX IF NOT EXISTS `idx_is_deleted` (`is_deleted`);

-- crm_sales_targets — user_id, year, month برای گزارش کارشناسان
ALTER TABLE `crm_sales_targets`
  ADD INDEX IF NOT EXISTS `idx_user_year_month` (`user_id`, `year`, `month`);

-- hr_kpi_scores — user_id, period
ALTER TABLE `hr_kpi_scores`
  ADD INDEX IF NOT EXISTS `idx_user_period` (`user_id`, `year`, `quarter`);

-- import_orders — status (اگر جدول وجود داشته باشد)
ALTER TABLE `import_orders`
  ADD INDEX IF NOT EXISTS `idx_status`     (`status`),
  ADD INDEX IF NOT EXISTS `idx_created_at` (`created_at`);

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────
-- یادداشت‌های معماری (نیازمند تصمیم‌گیری جداگانه)
-- ─────────────────────────────────────────────────────────────
-- ۱. customers در مقابل fin_persons:
--    دو جدول مجزا برای موجودیت مشتری وجود دارد.
--    توصیه: یک ستون person_id در customers اضافه شود تا دو جدول sync بمانند.
--
-- ۲. ذخیره تاریخ:
--    جداول قدیمی از VARCHAR(12) برای تاریخ شمسی استفاده می‌کنند.
--    جداول جدید (مرحله ۸) از نوع DATE میلادی استفاده می‌کنند.
--    توصیه: یکپارچه‌سازی به VARCHAR(12) شمسی در همه جداول.
--
-- ۳. نوع DECIMAL در جداول مالی:
--    fin_quotes و contracts از DECIMAL(20,0) استفاده می‌کنند
--    اما fin_invoices و سایر جداول مالی از BIGINT.
--    توصیه: تبدیل به BIGINT برای یکپارچگی.
