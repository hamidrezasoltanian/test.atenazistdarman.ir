-- ============================================================
-- مرحله ۱۳: شکایات ISO 13485 + CAPA (اقدام اصلاحی/پیشگیرانه)
-- بند ۸.۲.۱، ۸.۲.۶، ۸.۵.۲، ۸.۵.۳
-- ============================================================

-- شکایات پیشرفته با دسته‌بندی ISO 13485
CREATE TABLE IF NOT EXISTS qms_complaints (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_number     VARCHAR(30) NOT NULL UNIQUE COMMENT 'CMP-YYYYMM-XXXX',
    person_id            INT UNSIGNED DEFAULT NULL COMMENT 'مشتری/تأمین‌کننده',
    ref_ticket_id        INT UNSIGNED DEFAULT NULL COMMENT 'تیکت پشتیبانی مرتبط',
    stuff_id             INT UNSIGNED DEFAULT NULL COMMENT 'کالای مرتبط',
    batch_number         VARCHAR(50) DEFAULT NULL,
    serial_number        VARCHAR(100) DEFAULT NULL,
    source               ENUM('customer','internal','supplier','regulatory','returned_goods','adverse_event','other') NOT NULL DEFAULT 'customer',
    category             ENUM('quality','safety','performance','labeling','packaging','delivery','other') NOT NULL DEFAULT 'quality',
    severity             ENUM('critical','major','minor','observation') NOT NULL DEFAULT 'minor',
    description          TEXT NOT NULL,
    initial_assessment   TEXT DEFAULT NULL,
    regulatory_reportable TINYINT(1) DEFAULT 0 COMMENT 'آیا باید به MDMA گزارش شود؟',
    report_deadline      VARCHAR(12) DEFAULT NULL COMMENT 'مهلت گزارش به سازمان (شمسی)',
    regulatory_reported_at VARCHAR(12) DEFAULT NULL,
    assigned_to          INT UNSIGNED DEFAULT NULL,
    status               ENUM('open','investigating','pending_capa','resolved','closed') DEFAULT 'open',
    resolution           TEXT DEFAULT NULL,
    reported_by          INT UNSIGNED DEFAULT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted           TINYINT(1) DEFAULT 0,
    INDEX idx_status     (status),
    INDEX idx_person     (person_id),
    INDEX idx_stuff      (stuff_id),
    INDEX idx_severity   (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- درخواست‌های CAPA (اقدام اصلاحی / پیشگیرانه) — بند ۸.۵.۲ و ۸.۵.۳
CREATE TABLE IF NOT EXISTS capa_requests (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    capa_number           VARCHAR(30) NOT NULL UNIQUE COMMENT 'CA-YYYYMM-XXXX یا PA-YYYYMM-XXXX',
    type                  ENUM('corrective','preventive') NOT NULL DEFAULT 'corrective',
    source_type           ENUM('complaint','nonconformity','audit','management_review','trend','other') DEFAULT 'complaint',
    source_id             INT UNSIGNED DEFAULT NULL COMMENT 'شناسه منبع (مثلاً complaint_id)',
    title                 VARCHAR(200) NOT NULL,
    problem_statement     TEXT NOT NULL,
    severity              ENUM('critical','major','minor') NOT NULL DEFAULT 'minor',
    root_cause_method     ENUM('5why','fishbone','fault_tree','brainstorming','other') DEFAULT '5why',
    root_cause            TEXT DEFAULT NULL,
    action_plan           TEXT DEFAULT NULL,
    assigned_to           INT UNSIGNED DEFAULT NULL,
    due_date              VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ شمسی سررسید اجرا',
    implementation_notes  TEXT DEFAULT NULL,
    effectiveness_criteria TEXT DEFAULT NULL COMMENT 'معیار تأیید اثربخشی',
    effectiveness_check_date VARCHAR(12) DEFAULT NULL,
    effectiveness_result  ENUM('effective','not_effective','pending') DEFAULT 'pending',
    status                ENUM('open','investigation','action_plan','implementation','verification','closed','cancelled') DEFAULT 'open',
    closed_by             INT UNSIGNED DEFAULT NULL,
    closed_at             TIMESTAMP NULL DEFAULT NULL,
    created_by            INT UNSIGNED DEFAULT NULL,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted            TINYINT(1) DEFAULT 0,
    INDEX idx_status      (status),
    INDEX idx_type        (type),
    INDEX idx_source      (source_type, source_id),
    INDEX idx_assigned    (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- اقدامات تفصیلی هر CAPA
CREATE TABLE IF NOT EXISTS capa_actions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    capa_id      INT UNSIGNED NOT NULL,
    description  TEXT NOT NULL,
    assigned_to  INT UNSIGNED DEFAULT NULL,
    due_date     VARCHAR(12) DEFAULT NULL,
    completed_at VARCHAR(12) DEFAULT NULL,
    status       ENUM('pending','in_progress','done','cancelled') DEFAULT 'pending',
    notes        TEXT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY  (capa_id) REFERENCES capa_requests(id) ON DELETE CASCADE,
    INDEX idx_capa (capa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- لاگ تغییر وضعیت CAPA (audit trail کامل)
CREATE TABLE IF NOT EXISTS capa_status_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    capa_id     INT UNSIGNED NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status   VARCHAR(30) NOT NULL,
    actor_id    INT UNSIGNED DEFAULT NULL,
    notes       TEXT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (capa_id) REFERENCES capa_requests(id) ON DELETE CASCADE,
    INDEX idx_capa (capa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
