-- ============================================================
-- Phase 5: بهینه‌سازی شاخص‌ها و ایندکس‌های دیتابیس
-- آتنا زیست درمان — مرحله ۵
-- ============================================================

SET NAMES utf8mb4;

-- ── جدول API توکن‌ها ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(64) NOT NULL UNIQUE,
  `name`       VARCHAR(100) NULL COMMENT 'نام دستگاه یا اپلیکیشن',
  `last_used`  DATETIME NULL,
  `expires_at` DATETIME NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user`  (`user_id`),
  KEY `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ایندکس‌های جداول CRM ──────────────────────────────────
ALTER TABLE `crm_opportunities`
  ADD INDEX IF NOT EXISTS `idx_stage`      (`stage_id`),
  ADD INDEX IF NOT EXISTS `idx_assigned`   (`assigned_to`),
  ADD INDEX IF NOT EXISTS `idx_created`    (`created_at`),
  ADD INDEX IF NOT EXISTS `idx_deleted`    (`is_deleted`);

ALTER TABLE `crm_opportunity_calls`
  ADD INDEX IF NOT EXISTS `idx_opp`        (`opportunity_id`),
  ADD INDEX IF NOT EXISTS `idx_date`       (`call_date`);

ALTER TABLE `crm_opportunity_activities`
  ADD INDEX IF NOT EXISTS `idx_opp_act`    (`opportunity_id`),
  ADD INDEX IF NOT EXISTS `idx_user_act`   (`user_id`);

ALTER TABLE `crm_contacts`
  ADD INDEX IF NOT EXISTS `idx_contact_cust` (`customer_id`);

-- ── ایندکس‌های جداول حسابداری ────────────────────────────
ALTER TABLE `fin_invoices`
  ADD INDEX IF NOT EXISTS `idx_inv_person`  (`person_id`),
  ADD INDEX IF NOT EXISTS `idx_inv_status`  (`status`),
  ADD INDEX IF NOT EXISTS `idx_inv_date`    (`invoice_date`),
  ADD INDEX IF NOT EXISTS `idx_inv_deleted` (`is_deleted`),
  ADD INDEX IF NOT EXISTS `idx_inv_fiscal`  (`fiscal_year_id`);

ALTER TABLE `fin_doc_rows`
  ADD INDEX IF NOT EXISTS `idx_row_account` (`account_id`),
  ADD INDEX IF NOT EXISTS `idx_row_person`  (`person_id`),
  ADD INDEX IF NOT EXISTS `idx_row_doc`     (`doc_id`);

ALTER TABLE `fin_docs`
  ADD INDEX IF NOT EXISTS `idx_doc_type`    (`type`),
  ADD INDEX IF NOT EXISTS `idx_doc_fiscal`  (`fiscal_year_id`),
  ADD INDEX IF NOT EXISTS `idx_doc_ref`     (`ref_id`, `ref_type`);

ALTER TABLE `fin_cheques`
  ADD INDEX IF NOT EXISTS `idx_chq_due`     (`due_date`),
  ADD INDEX IF NOT EXISTS `idx_chq_status`  (`status`),
  ADD INDEX IF NOT EXISTS `idx_chq_deleted` (`is_deleted`);

ALTER TABLE `fin_persons`
  ADD INDEX IF NOT EXISTS `idx_per_type`    (`type`),
  ADD INDEX IF NOT EXISTS `idx_per_deleted` (`is_deleted`);

-- ── ایندکس‌های جداول انبار ───────────────────────────────
ALTER TABLE `inv_tickets`
  ADD INDEX IF NOT EXISTS `idx_tkt_date`    (`ticket_date`),
  ADD INDEX IF NOT EXISTS `idx_tkt_type`    (`type`),
  ADD INDEX IF NOT EXISTS `idx_tkt_store`   (`storeroom_id`),
  ADD INDEX IF NOT EXISTS `idx_tkt_deleted` (`is_deleted`);

ALTER TABLE `inv_ticket_items`
  ADD INDEX IF NOT EXISTS `idx_tki_ticket`  (`ticket_id`),
  ADD INDEX IF NOT EXISTS `idx_tki_stuff`   (`stuff_id`);

ALTER TABLE `stuffs`
  ADD INDEX IF NOT EXISTS `idx_stuff_active` (`is_active`);

ALTER TABLE `stuff_price_list`
  ADD INDEX IF NOT EXISTS `idx_spl_stuff`   (`stuff_id`);

-- ── ایندکس‌های جداول HR ──────────────────────────────────
ALTER TABLE `leave_requests`
  ADD INDEX IF NOT EXISTS `idx_lr_user`   (`user_id`),
  ADD INDEX IF NOT EXISTS `idx_lr_status` (`status`);

ALTER TABLE `attendance_requests`
  ADD INDEX IF NOT EXISTS `idx_ar_user`   (`user_id`),
  ADD INDEX IF NOT EXISTS `idx_ar_date`   (`attendance_date`);

ALTER TABLE `mission_requests`
  ADD INDEX IF NOT EXISTS `idx_mr_user`   (`user_id`),
  ADD INDEX IF NOT EXISTS `idx_mr_status` (`status`);

-- ── ایندکس‌های جداول پیام‌رسانی ──────────────────────────
ALTER TABLE `messages`
  ADD INDEX IF NOT EXISTS `idx_msg_conv`    (`conversation_id`),
  ADD INDEX IF NOT EXISTS `idx_msg_sender`  (`sender_id`),
  ADD INDEX IF NOT EXISTS `idx_msg_created` (`created_at`);
