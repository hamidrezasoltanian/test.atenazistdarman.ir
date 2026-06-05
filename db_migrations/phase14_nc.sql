-- ============================================================
-- مرحله ۱۴: کنترل محصول نامنطبق — ISO 13485 §8.3
-- ============================================================

CREATE TABLE IF NOT EXISTS nc_records (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nc_number                 VARCHAR(30) NOT NULL UNIQUE COMMENT 'NC-YYYYMM-XXXX',
    source                    ENUM('incoming_inspection','in_process','customer_return',
                                   'audit_finding','inventory_check','expired','other')
                              NOT NULL DEFAULT 'incoming_inspection',
    stuff_id                  INT UNSIGNED DEFAULT NULL,
    batch_number              VARCHAR(50)  DEFAULT NULL,
    serial_number             VARCHAR(100) DEFAULT NULL,
    storeroom_id              INT UNSIGNED DEFAULT NULL COMMENT 'انبار قرنطینه',
    quantity                  DECIMAL(12,3) DEFAULT 0,
    unit                      VARCHAR(20)  DEFAULT NULL,
    description               TEXT NOT NULL COMMENT 'شرح عدم انطباق',
    detection_date            VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ کشف شمسی',
    detected_by               INT UNSIGNED DEFAULT NULL,
    severity                  ENUM('critical','major','minor') NOT NULL DEFAULT 'major',
    disposition               ENUM('under_review','quarantine','rework',
                                   'return_to_supplier','scrap','use_as_is','concession')
                              NOT NULL DEFAULT 'under_review',
    disposition_notes         TEXT DEFAULT NULL,
    disposition_by            INT UNSIGNED DEFAULT NULL,
    disposition_date          VARCHAR(12) DEFAULT NULL,
    capa_id                   INT UNSIGNED DEFAULT NULL COMMENT 'CAPA مرتبط',
    customer_notified         TINYINT(1) DEFAULT 0,
    customer_notification_date VARCHAR(12) DEFAULT NULL,
    regulatory_notification   TINYINT(1) DEFAULT 0 COMMENT 'آیا به MDMA اطلاع داده شد',
    status                    ENUM('open','quarantined','in_disposition','closed','cancelled')
                              NOT NULL DEFAULT 'open',
    closed_by                 INT UNSIGNED DEFAULT NULL,
    closed_at                 TIMESTAMP NULL DEFAULT NULL,
    created_by                INT UNSIGNED DEFAULT NULL,
    created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted                TINYINT(1) DEFAULT 0,
    INDEX idx_status          (status),
    INDEX idx_stuff           (stuff_id),
    INDEX idx_batch           (batch_number),
    INDEX idx_severity        (severity),
    INDEX idx_disposition     (disposition)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- لاگ تغییر وضعیت NC
CREATE TABLE IF NOT EXISTS nc_status_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nc_id      INT UNSIGNED NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status  VARCHAR(30) NOT NULL,
    actor_id   INT UNSIGNED DEFAULT NULL,
    notes      TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (nc_id) REFERENCES nc_records(id) ON DELETE CASCADE,
    INDEX idx_nc (nc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- فلگ قرنطینه در موجودی batch
ALTER TABLE inv_batch_stock
    ADD COLUMN IF NOT EXISTS is_quarantined TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS nc_id INT UNSIGNED DEFAULT NULL COMMENT 'اگر در قرنطینه است';
