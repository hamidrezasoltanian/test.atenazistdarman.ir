-- ============================================================
-- Phase 10: رفع مشکلات معماری دیتابیس
-- آتنا زیست درمان
-- تاریخ: ۱۴۰۵/۰۳/۱۴
-- ============================================================
-- این migration سه مشکل معماری را رفع می‌کند:
--   ۱. یکپارچه‌سازی customers ↔ fin_persons (اضافه کردن person_id)
--   ۲. یکپارچه‌سازی نوع تاریخ (DATE میلادی → VARCHAR(12) شمسی)
--   ۳. یکپارچه‌سازی نوع مبلغ (DECIMAL(20,0) → BIGINT)
--   ۴. اضافه کردن Foreign Key Constraints
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ═══════════════════════════════════════════════════════════════
-- بخش ۱: یکپارچه‌سازی customers ↔ fin_persons
-- مشکل: دو جدول مجزا برای مشتری بدون رابطه بین آنها
-- راه‌حل: اضافه کردن person_id به customers برای لینک دوطرفه
-- ═══════════════════════════════════════════════════════════════

ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `person_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'ارجاع به fin_persons — NULL یعنی هنوز sync نشده',
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  ADD INDEX IF NOT EXISTS `idx_person_id` (`person_id`);

-- ═══════════════════════════════════════════════════════════════
-- بخش ۲: یکپارچه‌سازی نوع تاریخ → VARCHAR(12) شمسی
-- مشکل: جداول مرحله ۸ از DATE میلادی استفاده می‌کنند
--        اما بقیه سیستم از VARCHAR(12) شمسی
-- ═══════════════════════════════════════════════════════════════

-- contracts
ALTER TABLE `contracts`
  MODIFY COLUMN `start_date` VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ شمسی YYYY/MM/DD',
  MODIFY COLUMN `end_date`   VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ شمسی YYYY/MM/DD';

-- fin_quotes
ALTER TABLE `fin_quotes`
  MODIFY COLUMN `quote_date`   VARCHAR(12) NOT NULL DEFAULT '' COMMENT 'تاریخ شمسی YYYY/MM/DD',
  MODIFY COLUMN `valid_until`  VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ اعتبار شمسی';

-- warranty_records
ALTER TABLE `warranty_records`
  MODIFY COLUMN `sale_date`    VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ فروش شمسی',
  MODIFY COLUMN `expiry_date`  VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ انقضا شمسی';

-- crm_followup_log
ALTER TABLE `crm_followup_log`
  MODIFY COLUMN `last_order_date` VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ آخرین سفارش شمسی';

-- ═══════════════════════════════════════════════════════════════
-- بخش ۳: یکپارچه‌سازی نوع مبلغ → BIGINT (ریال)
-- مشکل: جداول مرحله ۸ از DECIMAL(20,0) استفاده می‌کنند
--        اما fin_invoices و سایر جداول مالی از BIGINT
-- راه‌حل: تبدیل DECIMAL(20,0) به BIGINT — بدون از دست رفتن داده
--          چون DECIMAL(20,0) هیچ قسمت اعشاری ندارد
-- ═══════════════════════════════════════════════════════════════

-- contracts
ALTER TABLE `contracts`
  MODIFY COLUMN `amount`      BIGINT NOT NULL DEFAULT 0 COMMENT 'مبلغ قرارداد (ریال)',
  MODIFY COLUMN `paid_amount` BIGINT NOT NULL DEFAULT 0 COMMENT 'مبلغ پرداخت‌شده (ریال)';

-- crm_sales_targets
ALTER TABLE `crm_sales_targets`
  MODIFY COLUMN `target_amount`   BIGINT NOT NULL DEFAULT 0 COMMENT 'هدف فروش (ریال)',
  MODIFY COLUMN `achieved_amount` BIGINT NOT NULL DEFAULT 0 COMMENT 'فروش محقق‌شده (ریال)';

-- fin_quotes
ALTER TABLE `fin_quotes`
  MODIFY COLUMN `subtotal`     BIGINT NOT NULL DEFAULT 0,
  MODIFY COLUMN `discount`     BIGINT NOT NULL DEFAULT 0,
  MODIFY COLUMN `tax`          BIGINT NOT NULL DEFAULT 0,
  MODIFY COLUMN `total_amount` BIGINT NOT NULL DEFAULT 0;

-- fin_quote_items
ALTER TABLE `fin_quote_items`
  MODIFY COLUMN `unit_price` BIGINT NOT NULL DEFAULT 0,
  MODIFY COLUMN `total`      BIGINT NOT NULL DEFAULT 0;

-- dealers
ALTER TABLE `dealers`
  MODIFY COLUMN `credit_limit`    BIGINT NOT NULL DEFAULT 0 COMMENT 'سقف اعتبار (ریال)',
  MODIFY COLUMN `current_balance` BIGINT NOT NULL DEFAULT 0 COMMENT 'مانده جاری (ریال)';

-- tax_invoices
ALTER TABLE `tax_invoices`
  MODIFY COLUMN `total_amount` BIGINT NOT NULL DEFAULT 0,
  MODIFY COLUMN `tax_amount`   BIGINT NOT NULL DEFAULT 0;

-- ═══════════════════════════════════════════════════════════════
-- بخش ۴: آماده‌سازی ستون‌های FK قبل از اضافه کردن Constraint
-- مشکل: person_id با DEFAULT 0 — عدد ۰ در fin_persons وجود ندارد
-- راه‌حل: تبدیل DEFAULT 0 به DEFAULT NULL + تغییر نوع به UNSIGNED
-- ═══════════════════════════════════════════════════════════════

-- مقدار ۰ را قبل از تغییر نوع به NULL تبدیل کن
UPDATE `contracts`      SET `person_id` = NULL WHERE `person_id` = 0;
UPDATE `fin_quotes`     SET `person_id` = NULL WHERE `person_id` = 0;
UPDATE `support_tickets` SET `person_id` = NULL WHERE `person_id` = 0;

-- تغییر نوع ستون‌ها به INT UNSIGNED DEFAULT NULL (مطابق fin_persons.id)
ALTER TABLE `contracts`
  MODIFY COLUMN `person_id`  INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `created_by` INT UNSIGNED NOT NULL;

ALTER TABLE `fin_quotes`
  MODIFY COLUMN `person_id`  INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `created_by` INT UNSIGNED NOT NULL,
  MODIFY COLUMN `invoice_id` INT UNSIGNED DEFAULT NULL;

ALTER TABLE `support_tickets`
  MODIFY COLUMN `person_id`   INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `invoice_id`  INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `assigned_to` INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `created_by`  INT UNSIGNED NOT NULL;

ALTER TABLE `support_ticket_replies`
  MODIFY COLUMN `ticket_id` INT UNSIGNED NOT NULL,
  MODIFY COLUMN `user_id`   INT UNSIGNED NOT NULL;

ALTER TABLE `warranty_records`
  MODIFY COLUMN `stuff_id`        INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `invoice_item_id` INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `invoice_id`      INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `person_id`       INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `created_by`      INT UNSIGNED NOT NULL;

ALTER TABLE `dealers`
  MODIFY COLUMN `province_id` INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `created_by`  INT UNSIGNED NOT NULL;

ALTER TABLE `crm_sales_targets`
  MODIFY COLUMN `user_id` INT UNSIGNED NOT NULL;

ALTER TABLE `crm_followup_rules`
  MODIFY COLUMN `created_by` INT UNSIGNED NOT NULL;

ALTER TABLE `crm_followup_log`
  MODIFY COLUMN `rule_id`   INT UNSIGNED DEFAULT NULL,
  MODIFY COLUMN `person_id` INT UNSIGNED NOT NULL;

ALTER TABLE `customer_portal_sessions`
  MODIFY COLUMN `person_id` INT UNSIGNED NOT NULL;

ALTER TABLE `tax_invoices`
  MODIFY COLUMN `invoice_id`  INT UNSIGNED NOT NULL,
  MODIFY COLUMN `created_by`  INT UNSIGNED NOT NULL;

ALTER TABLE `hr_commissions`
  MODIFY COLUMN `user_id`    INT UNSIGNED NOT NULL,
  MODIFY COLUMN `invoice_id` INT UNSIGNED NOT NULL;

ALTER TABLE `hr_kpi_scores`
  MODIFY COLUMN `user_id`     INT UNSIGNED NOT NULL,
  MODIFY COLUMN `created_by`  INT UNSIGNED NOT NULL;

ALTER TABLE `hr_payroll_periods`
  MODIFY COLUMN `created_by`  INT UNSIGNED NOT NULL,
  MODIFY COLUMN `fin_doc_id`  INT UNSIGNED DEFAULT NULL;

ALTER TABLE `hr_payroll_items`
  MODIFY COLUMN `period_id` INT UNSIGNED NOT NULL,
  MODIFY COLUMN `user_id`   INT UNSIGNED NOT NULL;

ALTER TABLE `attendance_qr_tokens`
  MODIFY COLUMN `used_by` INT UNSIGNED DEFAULT NULL;

ALTER TABLE `attendance_checkins`
  MODIFY COLUMN `user_id` INT UNSIGNED NOT NULL;

-- ═══════════════════════════════════════════════════════════════
-- بخش ۵: اضافه کردن Foreign Key Constraints
-- ═══════════════════════════════════════════════════════════════

-- contracts
ALTER TABLE `contracts`
  ADD CONSTRAINT `fk_contracts_person`
    FOREIGN KEY (`person_id`) REFERENCES `fin_persons`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_contracts_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- crm_sales_targets
ALTER TABLE `crm_sales_targets`
  ADD CONSTRAINT `fk_sales_targets_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- fin_quotes
ALTER TABLE `fin_quotes`
  ADD CONSTRAINT `fk_fin_quotes_person`
    FOREIGN KEY (`person_id`) REFERENCES `fin_persons`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fin_quotes_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fin_quotes_invoice`
    FOREIGN KEY (`invoice_id`) REFERENCES `fin_invoices`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- fin_quote_items
ALTER TABLE `fin_quote_items`
  ADD CONSTRAINT `fk_quote_items_quote`
    FOREIGN KEY (`quote_id`) REFERENCES `fin_quotes`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_quote_items_stuff`
    FOREIGN KEY (`stuff_id`) REFERENCES `stuffs`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- support_tickets
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `fk_support_tickets_person`
    FOREIGN KEY (`person_id`) REFERENCES `fin_persons`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_support_tickets_invoice`
    FOREIGN KEY (`invoice_id`) REFERENCES `fin_invoices`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_support_tickets_assigned`
    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_support_tickets_created`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- support_ticket_replies
ALTER TABLE `support_ticket_replies`
  ADD CONSTRAINT `fk_ticket_replies_ticket`
    FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ticket_replies_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- warranty_records
ALTER TABLE `warranty_records`
  ADD CONSTRAINT `fk_warranty_stuff`
    FOREIGN KEY (`stuff_id`) REFERENCES `stuffs`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_warranty_invoice`
    FOREIGN KEY (`invoice_id`) REFERENCES `fin_invoices`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_warranty_person`
    FOREIGN KEY (`person_id`) REFERENCES `fin_persons`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_warranty_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- dealers
ALTER TABLE `dealers`
  ADD CONSTRAINT `fk_dealers_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- crm_followup_rules
ALTER TABLE `crm_followup_rules`
  ADD CONSTRAINT `fk_followup_rules_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- crm_followup_log
ALTER TABLE `crm_followup_log`
  ADD CONSTRAINT `fk_followup_log_rule`
    FOREIGN KEY (`rule_id`) REFERENCES `crm_followup_rules`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_followup_log_person`
    FOREIGN KEY (`person_id`) REFERENCES `fin_persons`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- customer_portal_sessions
ALTER TABLE `customer_portal_sessions`
  ADD CONSTRAINT `fk_portal_sessions_person`
    FOREIGN KEY (`person_id`) REFERENCES `fin_persons`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- tax_invoices
ALTER TABLE `tax_invoices`
  ADD CONSTRAINT `fk_tax_invoices_invoice`
    FOREIGN KEY (`invoice_id`) REFERENCES `fin_invoices`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tax_invoices_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- hr_commissions
ALTER TABLE `hr_commissions`
  ADD CONSTRAINT `fk_hr_commissions_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_hr_commissions_invoice`
    FOREIGN KEY (`invoice_id`) REFERENCES `fin_invoices`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- hr_kpi_scores
ALTER TABLE `hr_kpi_scores`
  ADD CONSTRAINT `fk_kpi_scores_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_kpi_scores_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- hr_payroll_periods
ALTER TABLE `hr_payroll_periods`
  ADD CONSTRAINT `fk_payroll_periods_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payroll_periods_fin_doc`
    FOREIGN KEY (`fin_doc_id`) REFERENCES `fin_docs`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- hr_payroll_items
ALTER TABLE `hr_payroll_items`
  ADD CONSTRAINT `fk_payroll_items_period`
    FOREIGN KEY (`period_id`) REFERENCES `hr_payroll_periods`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payroll_items_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- attendance_qr_tokens
ALTER TABLE `attendance_qr_tokens`
  ADD CONSTRAINT `fk_qr_tokens_used_by`
    FOREIGN KEY (`used_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- attendance_checkins
ALTER TABLE `attendance_checkins`
  ADD CONSTRAINT `fk_checkins_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- customers → fin_persons (اختیاری — NULL یعنی هنوز sync نشده)
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_person`
    FOREIGN KEY (`person_id`) REFERENCES `fin_persons`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════
-- یادداشت: رابطه customers ↔ fin_persons
-- ─────────────────────────────────────────────────────────────
-- customers.person_id اکنون اختیاری است (DEFAULT NULL).
-- برای sync کردن رکوردهای موجود، یک ادمین باید:
--   ۱. fin_persons بسازد برای هر مشتری
--   ۲. customers.person_id را با id مربوطه پر کند
-- یا: با اجرای اسکریپت زیر (در صورت یکسان بودن نام/موبایل):
--
-- INSERT INTO fin_persons (name, mobile, type, created_at)
--   SELECT company_name, mobile, 'customer', NOW()
--   FROM customers
--   WHERE person_id IS NULL AND is_deleted = 0
--   ON DUPLICATE KEY UPDATE id=id;
--
-- UPDATE customers c
--   JOIN fin_persons p ON c.mobile = p.mobile
--   SET c.person_id = p.id
--   WHERE c.person_id IS NULL;
-- ═══════════════════════════════════════════════════════════════
