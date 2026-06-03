-- =====================================================================
-- مرحله ۶: انبارداری پیشرفته — لات‌بندی، FEFO، تأیید چندمرحله‌ای
-- =====================================================================

-- جدول لات‌های موجودی
CREATE TABLE IF NOT EXISTS `inv_lots` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `lot_number`     VARCHAR(100) NOT NULL,
    `stuff_id`       INT NOT NULL,
    `storeroom_id`   INT NOT NULL,
    `qty`            DECIMAL(12,3) NOT NULL DEFAULT 0,
    `entry_qty`      DECIMAL(12,3) NOT NULL DEFAULT 0,
    `expiry_date`    DATE NULL,
    `purchase_price` BIGINT DEFAULT 0,
    `person_id`      INT NULL,
    `ticket_id`      INT NULL,
    `status`         ENUM('active','exhausted','expired') DEFAULT 'active',
    `notes`          TEXT NULL,
    `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `is_deleted`     TINYINT(1) DEFAULT 0,
    KEY `idx_lot_stuff`     (`stuff_id`),
    KEY `idx_lot_storeroom` (`storeroom_id`),
    KEY `idx_lot_expiry`    (`expiry_date`),
    KEY `idx_lot_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- افزودن ستون‌های تأیید به inv_tickets (در صورتی که وجود ندارند)
ALTER TABLE `inv_tickets`
    ADD COLUMN IF NOT EXISTS `approved_by`    INT NULL AFTER `confirmed_by`,
    ADD COLUMN IF NOT EXISTS `approved_at`    DATETIME NULL AFTER `approved_by`,
    ADD COLUMN IF NOT EXISTS `reject_reason`  TEXT NULL AFTER `approved_at`;

-- تغییر enum وضعیت (اضافه کردن pending و rejected)
ALTER TABLE `inv_tickets`
    MODIFY COLUMN `status` ENUM('draft','pending','confirmed','rejected') NOT NULL DEFAULT 'draft';

-- افزودن ستون‌های لات به inv_ticket_items
ALTER TABLE `inv_ticket_items`
    ADD COLUMN IF NOT EXISTS `lot_id`      INT NULL AFTER `total`,
    ADD COLUMN IF NOT EXISTS `lot_number`  VARCHAR(100) NULL AFTER `lot_id`,
    ADD COLUMN IF NOT EXISTS `expiry_date` DATE NULL AFTER `lot_number`;

-- افزودن نوع فاکتور (رسمی / تنظیمی) به fin_invoices
ALTER TABLE `fin_invoices`
    ADD COLUMN IF NOT EXISTS `invoice_type` ENUM('official','adjustment') NOT NULL DEFAULT 'official' AFTER `type`;

-- ایندکس عملکرد روی inv_lots
CREATE INDEX IF NOT EXISTS `idx_lot_qty_active`
    ON `inv_lots` (`stuff_id`, `storeroom_id`, `qty`, `status`);
