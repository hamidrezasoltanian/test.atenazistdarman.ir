-- ============================================================
-- مرحله ۱۶: کنترل مدارک با نسخه‌بندی — ISO 13485 §4.2.4
-- ============================================================

-- فهرست مدارک کنترل‌شده
CREATE TABLE IF NOT EXISTS doc_documents (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_number        VARCHAR(30) NOT NULL UNIQUE COMMENT 'DOC-YYYY-XXXX',
    title             VARCHAR(300) NOT NULL,
    category          ENUM('quality_system','procedure','work_instruction','form',
                           'policy','specification','regulatory','supplier','hr','other')
                      NOT NULL DEFAULT 'procedure',
    is_external       TINYINT(1) DEFAULT 0 COMMENT 'مدرک خارجی (استاندارد، قانون، ...)',
    current_version   VARCHAR(10) NOT NULL DEFAULT '1.0',
    status            ENUM('draft','review','approved','obsolete') NOT NULL DEFAULT 'draft',
    responsible_id    INT UNSIGNED DEFAULT NULL COMMENT 'مسئول مدرک',
    approver_id       INT UNSIGNED DEFAULT NULL COMMENT 'تأییدکننده',
    effective_date    VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ لازم‌الاجرا شمسی',
    next_review_date  VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ بازنگری بعدی',
    storage_location  VARCHAR(200) DEFAULT NULL COMMENT 'محل نگهداری فیزیکی',
    description       TEXT DEFAULT NULL,
    created_by        INT UNSIGNED DEFAULT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted        TINYINT(1) DEFAULT 0,
    INDEX idx_status   (status),
    INDEX idx_category (category),
    INDEX idx_review   (next_review_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- نسخه‌های مدارک
CREATE TABLE IF NOT EXISTS doc_versions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id           INT UNSIGNED NOT NULL,
    version_number   VARCHAR(10) NOT NULL COMMENT '1.0، 1.1، 2.0',
    change_summary   TEXT DEFAULT NULL COMMENT 'خلاصه تغییرات',
    file_path        VARCHAR(300) DEFAULT NULL COMMENT 'مسیر فایل آپلودشده',
    file_name        VARCHAR(200) DEFAULT NULL,
    prepared_by      INT UNSIGNED DEFAULT NULL,
    reviewed_by      INT UNSIGNED DEFAULT NULL,
    approved_by      INT UNSIGNED DEFAULT NULL,
    approval_date    VARCHAR(12) DEFAULT NULL,
    effective_date   VARCHAR(12) DEFAULT NULL,
    status           ENUM('draft','review','approved','superseded') NOT NULL DEFAULT 'draft',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (doc_id) REFERENCES doc_documents(id) ON DELETE CASCADE,
    INDEX idx_doc    (doc_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- لاگ توزیع مدارک
CREATE TABLE IF NOT EXISTS doc_distribution (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id            INT UNSIGNED NOT NULL,
    version_id        INT UNSIGNED DEFAULT NULL,
    distributed_to    INT UNSIGNED DEFAULT NULL COMMENT 'user_id',
    department        VARCHAR(100) DEFAULT NULL,
    distribution_date VARCHAR(12) DEFAULT NULL,
    method            ENUM('electronic','print','email') NOT NULL DEFAULT 'electronic',
    acknowledged      TINYINT(1) DEFAULT 0,
    ack_date          VARCHAR(12) DEFAULT NULL,
    notes             TEXT DEFAULT NULL,
    created_by        INT UNSIGNED DEFAULT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doc_id) REFERENCES doc_documents(id) ON DELETE CASCADE,
    INDEX idx_doc  (doc_id),
    INDEX idx_user (distributed_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- لاگ تغییر وضعیت مدرک
CREATE TABLE IF NOT EXISTS doc_status_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id      INT UNSIGNED NOT NULL,
    version_id  INT UNSIGNED DEFAULT NULL,
    from_status VARCHAR(20) DEFAULT NULL,
    to_status   VARCHAR(20) NOT NULL,
    actor_id    INT UNSIGNED DEFAULT NULL,
    notes       TEXT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doc_id) REFERENCES doc_documents(id) ON DELETE CASCADE,
    INDEX idx_doc (doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
