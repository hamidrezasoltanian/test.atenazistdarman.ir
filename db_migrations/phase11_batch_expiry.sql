-- ═══════════════════════════════════════════════════════════════
-- مرحله ۱۱ — ردیابی Lot/Batch، تاریخ انقضا، کد IRC، تلگرام بات
-- ═══════════════════════════════════════════════════════════════

-- ── ۱۱.۱ ستون‌های batch و expiry در inv_ticket_items ──
ALTER TABLE inv_ticket_items
  ADD COLUMN IF NOT EXISTS batch_number VARCHAR(50) NULL DEFAULT NULL AFTER description,
  ADD COLUMN IF NOT EXISTS expiry_date  VARCHAR(12) NULL DEFAULT NULL AFTER batch_number;

ALTER TABLE inv_ticket_items
  ADD INDEX IF NOT EXISTS idx_batch  (batch_number),
  ADD INDEX IF NOT EXISTS idx_expiry (expiry_date);

-- ── ۱۱.۲ کد IRC و نوع محصول در stuffs ──
ALTER TABLE stuffs
  ADD COLUMN IF NOT EXISTS irc_code          VARCHAR(20)  NULL DEFAULT NULL AFTER stuff_code,
  ADD COLUMN IF NOT EXISTS product_type      ENUM('medical_device','pharmaceutical','consumable','other')
                                             NOT NULL DEFAULT 'other' AFTER irc_code,
  ADD COLUMN IF NOT EXISTS shelf_life_months TINYINT UNSIGNED NULL DEFAULT NULL AFTER product_type;

ALTER TABLE stuffs
  ADD INDEX IF NOT EXISTS idx_irc (irc_code);

-- ── ۱۱.۳ جدول موجودی per-Batch ──
CREATE TABLE IF NOT EXISTS inv_batch_stock (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stuff_id     INT UNSIGNED NOT NULL,
  stuff_code   VARCHAR(50)  NOT NULL,
  storeroom_id INT UNSIGNED NOT NULL DEFAULT 1,
  batch_number VARCHAR(50)  NOT NULL,
  expiry_date  VARCHAR(12)  NULL DEFAULT NULL,
  qty          DECIMAL(12,3) NOT NULL DEFAULT 0,
  is_deleted   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_stuff_batch (stuff_id, batch_number),
  INDEX idx_storeroom   (storeroom_id),
  INDEX idx_expiry      (expiry_date),
  INDEX idx_batch_num   (batch_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ۱۱.۴ تنظیمات تلگرام بات ──
INSERT IGNORE INTO settings (`key`, `value`, `label`, `group`)
VALUES
  ('telegram_bot_token',   '', 'توکن ربات تلگرام',        'telegram'),
  ('telegram_chat_id',     '', 'Chat ID گزارش روزانه',     'telegram'),
  ('telegram_alert_id',    '', 'Chat ID هشدار فوری',       'telegram'),
  ('telegram_daily_hour',  '8','ساعت ارسال گزارش روزانه', 'telegram'),
  ('telegram_enabled',     '0','فعال‌سازی تلگرام بات',    'telegram');
