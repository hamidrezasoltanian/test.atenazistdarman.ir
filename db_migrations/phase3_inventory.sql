-- ============================================================
-- فایل: db_migrations/phase3_inventory.sql
-- ماژول انبارداری — مرحله ۳
-- تاریخ: ۱۴۰۵/۰۳/۱۲
-- ============================================================

-- تعریف انبارها
CREATE TABLE IF NOT EXISTS inv_storerooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  address VARCHAR(255) NULL,
  manager_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- حواله/رسید انبار
CREATE TABLE IF NOT EXISTS inv_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_number VARCHAR(30) NOT NULL UNIQUE,
  type ENUM('receipt','dispatch','transfer','return') NOT NULL DEFAULT 'receipt',
  ticket_date VARCHAR(12) NOT NULL COMMENT 'تاریخ شمسی',
  ticket_date_g DATE NULL,
  storeroom_id INT UNSIGNED NOT NULL COMMENT 'انبار مقصد یا مبدا',
  dest_storeroom_id INT UNSIGNED NULL COMMENT 'برای انتقال: انبار مقصد',
  ref_type VARCHAR(30) NULL COMMENT 'invoice/manual',
  ref_id INT UNSIGNED NULL COMMENT 'شناسه فاکتور مرتبط',
  person_id INT UNSIGNED NULL,
  person_name VARCHAR(150) NULL,
  description TEXT NULL,
  status ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
  created_by INT UNSIGNED NOT NULL,
  confirmed_by INT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_type (type),
  KEY idx_date (ticket_date),
  KEY idx_storeroom (storeroom_id),
  KEY idx_ref (ref_id, ref_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- اقلام حواله/رسید
CREATE TABLE IF NOT EXISTS inv_ticket_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  stuff_id INT UNSIGNED NOT NULL COMMENT 'کالا از stuffs',
  stuff_code VARCHAR(50) NULL,
  description VARCHAR(255) NULL,
  unit VARCHAR(30) NULL DEFAULT 'عدد',
  qty DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_price BIGINT NOT NULL DEFAULT 0,
  total BIGINT NOT NULL DEFAULT 0,
  sort_order TINYINT NOT NULL DEFAULT 0,
  KEY idx_ticket (ticket_id),
  KEY idx_stuff (stuff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- seed: انبارهای پیش‌فرض
INSERT IGNORE INTO inv_storerooms (code, name, is_active) VALUES
  ('ST01', 'انبار مرکزی', 1),
  ('ST02', 'انبار مجازی', 1);
