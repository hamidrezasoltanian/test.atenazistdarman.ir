-- ============================================================
-- مرحله ۱۵: سوابق آموزشی ISO 13485 §6.2 + ارزیابی تأمین‌کنندگان §7.4
-- ============================================================

-- ─── §6.2 سوابق آموزشی ───

CREATE TABLE IF NOT EXISTS hr_training_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(200) NOT NULL,
    type            ENUM('internal','external','ojt','e_learning','regulatory') NOT NULL DEFAULT 'internal',
    description     TEXT DEFAULT NULL,
    trainer         VARCHAR(100) DEFAULT NULL,
    duration_hours  DECIMAL(5,1) DEFAULT NULL,
    is_mandatory    TINYINT(1) DEFAULT 0,
    valid_months    INT DEFAULT NULL COMMENT 'اعتبار گواهینامه به ماه — NULL = نامحدود',
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted      TINYINT(1) DEFAULT 0,
    INDEX idx_type (type),
    INDEX idx_mandatory (is_mandatory)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_training_records (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    session_id          INT UNSIGNED NOT NULL,
    training_date       VARCHAR(12) DEFAULT NULL COMMENT 'شمسی YYYY/MM/DD',
    score               DECIMAL(5,2) DEFAULT NULL,
    result              ENUM('pass','fail','pending') NOT NULL DEFAULT 'pending',
    certificate_number  VARCHAR(50) DEFAULT NULL,
    expiry_date         VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ انقضای گواهینامه شمسی',
    notes               TEXT DEFAULT NULL,
    recorded_by         INT UNSIGNED DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted          TINYINT(1) DEFAULT 0,
    INDEX idx_user    (user_id),
    INDEX idx_session (session_id),
    INDEX idx_result  (result),
    INDEX idx_expiry  (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ماتریس شایستگی: هر نقش چه دوره‌هایی باید داشته باشد
CREATE TABLE IF NOT EXISTS hr_competency_requirements (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name    VARCHAR(100) NOT NULL COMMENT 'نقش سیستمی (admin, sales, warehouse, ...)',
    session_id   INT UNSIGNED NOT NULL,
    is_mandatory TINYINT(1) DEFAULT 1,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_role_session (role_name, session_id),
    INDEX idx_role    (role_name),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── §7.4 ارزیابی تأمین‌کنندگان ───

CREATE TABLE IF NOT EXISTS sup_suppliers (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id        INT UNSIGNED DEFAULT NULL COMMENT 'لینک به fin_persons',
    supplier_code    VARCHAR(30) NOT NULL UNIQUE,
    company_name     VARCHAR(200) NOT NULL,
    contact_name     VARCHAR(100) DEFAULT NULL,
    phone            VARCHAR(20) DEFAULT NULL,
    email            VARCHAR(100) DEFAULT NULL,
    product_category VARCHAR(200) DEFAULT NULL,
    avl_status       ENUM('new','approved','conditional','suspended','removed') NOT NULL DEFAULT 'new',
    avl_date         VARCHAR(12) DEFAULT NULL COMMENT 'تاریخ تأیید AVL شمسی',
    initial_score    DECIMAL(5,2) DEFAULT NULL,
    last_eval_date   VARCHAR(12) DEFAULT NULL,
    notes            TEXT DEFAULT NULL,
    created_by       INT UNSIGNED DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted       TINYINT(1) DEFAULT 0,
    INDEX idx_avl    (avl_status),
    INDEX idx_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sup_evaluations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id    INT UNSIGNED NOT NULL,
    eval_period    VARCHAR(20) NOT NULL COMMENT 'مثلا 1403/Q2',
    quality_score  DECIMAL(5,2) DEFAULT NULL COMMENT '0-100',
    delivery_score DECIMAL(5,2) DEFAULT NULL,
    price_score    DECIMAL(5,2) DEFAULT NULL,
    service_score  DECIMAL(5,2) DEFAULT NULL,
    total_score    DECIMAL(5,2) DEFAULT NULL COMMENT 'محاسبه خودکار میانگین',
    result         ENUM('approved','conditional','suspended') DEFAULT NULL,
    evaluator_id   INT UNSIGNED DEFAULT NULL,
    eval_date      VARCHAR(12) DEFAULT NULL,
    notes          TEXT DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted     TINYINT(1) DEFAULT 0,
    INDEX idx_supplier (supplier_id),
    INDEX idx_period   (eval_period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
