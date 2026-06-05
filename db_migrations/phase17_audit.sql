-- ============================================================
-- مرحله ۱۷: ممیزی داخلی ISO 13485 §8.2.2 + بازنگری مدیریت §5.6
-- ============================================================

-- برنامه ممیزی
CREATE TABLE IF NOT EXISTS qms_audit_plans (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_number     VARCHAR(30) NOT NULL UNIQUE COMMENT 'AUD-YYYYMM-XXXX',
    title            VARCHAR(300) NOT NULL,
    audit_type       ENUM('internal','external','surveillance','certification') NOT NULL DEFAULT 'internal',
    scope            TEXT DEFAULT NULL COMMENT 'دامنه ممیزی',
    criteria         TEXT DEFAULT NULL COMMENT 'معیارهای ممیزی',
    standard_clauses VARCHAR(300) DEFAULT NULL COMMENT 'بندهای مرتبط: §8.2, §7.4',
    areas            TEXT DEFAULT NULL COMMENT 'دپارتمان‌ها / فرآیندهای ممیزی',
    planned_date     VARCHAR(12) DEFAULT NULL,
    actual_date      VARCHAR(12) DEFAULT NULL,
    lead_auditor_id  INT UNSIGNED DEFAULT NULL,
    audit_team       TEXT DEFAULT NULL COMMENT 'اعضای تیم ممیزی',
    status           ENUM('planned','in_progress','completed','cancelled') NOT NULL DEFAULT 'planned',
    summary          TEXT DEFAULT NULL COMMENT 'خلاصه نتایج',
    created_by       INT UNSIGNED DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted       TINYINT(1) DEFAULT 0,
    INDEX idx_status (status),
    INDEX idx_date   (planned_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- یافته‌های ممیزی
CREATE TABLE IF NOT EXISTS qms_audit_findings (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_id         INT UNSIGNED NOT NULL,
    finding_number   VARCHAR(20) DEFAULT NULL,
    finding_type     ENUM('major_nc','minor_nc','observation','opportunity') NOT NULL DEFAULT 'minor_nc',
    description      TEXT NOT NULL,
    clause_reference VARCHAR(200) DEFAULT NULL COMMENT 'بند مرتبط: §8.3.1',
    department       VARCHAR(150) DEFAULT NULL,
    evidence         TEXT DEFAULT NULL COMMENT 'شواهد یافته',
    assigned_to      INT UNSIGNED DEFAULT NULL,
    target_date      VARCHAR(12) DEFAULT NULL,
    closed_date      VARCHAR(12) DEFAULT NULL,
    capa_id          INT UNSIGNED DEFAULT NULL,
    status           ENUM('open','in_progress','closed','accepted') NOT NULL DEFAULT 'open',
    closure_notes    TEXT DEFAULT NULL,
    created_by       INT UNSIGNED DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted       TINYINT(1) DEFAULT 0,
    FOREIGN KEY (audit_id) REFERENCES qms_audit_plans(id) ON DELETE CASCADE,
    INDEX idx_audit  (audit_id),
    INDEX idx_status (status),
    INDEX idx_capa   (capa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- بازنگری مدیریت
CREATE TABLE IF NOT EXISTS qms_management_reviews (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_number            VARCHAR(30) NOT NULL UNIQUE COMMENT 'MR-YYYYMM-XXXX',
    title                    VARCHAR(300) NOT NULL,
    review_date              VARCHAR(12) DEFAULT NULL,
    participants             TEXT DEFAULT NULL,
    -- ورودی‌های بازنگری §5.6.2
    input_audit_results      TEXT DEFAULT NULL,
    input_customer_feedback  TEXT DEFAULT NULL,
    input_process_performance TEXT DEFAULT NULL,
    input_product_conformance TEXT DEFAULT NULL,
    input_capa_status        TEXT DEFAULT NULL,
    input_previous_followup  TEXT DEFAULT NULL,
    input_planned_changes    TEXT DEFAULT NULL,
    input_regulatory         TEXT DEFAULT NULL,
    -- خروجی‌های بازنگری §5.6.3
    output_improvement       TEXT DEFAULT NULL,
    output_resources         TEXT DEFAULT NULL,
    output_product_changes   TEXT DEFAULT NULL,
    output_action_items      TEXT DEFAULT NULL,
    -- متادیتا
    status                   ENUM('draft','completed') NOT NULL DEFAULT 'draft',
    next_review_date         VARCHAR(12) DEFAULT NULL,
    created_by               INT UNSIGNED DEFAULT NULL,
    created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted               TINYINT(1) DEFAULT 0,
    INDEX idx_status (status),
    INDEX idx_date   (review_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
