-- ============================================================
-- مرحله ۱۲: جریان تأیید چندسطحی (Multi-level Approval Workflow)
-- ============================================================

CREATE TABLE IF NOT EXISTS approval_flows (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    module      ENUM('invoice_buy','invoice_sell','expense','petty_cash','leave','mission') NOT NULL,
    min_amount  BIGINT DEFAULT 0 COMMENT 'حداقل مبلغ برای فعال‌سازی — 0 یعنی همیشه',
    is_active   TINYINT(1) DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_module (module, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_flow_steps (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flow_id        INT UNSIGNED NOT NULL,
    step_order     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    approver_type  ENUM('role','user') NOT NULL DEFAULT 'role',
    approver_value VARCHAR(100) NOT NULL COMMENT 'نام نقش یا شناسه کاربر',
    label          VARCHAR(100) DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (flow_id) REFERENCES approval_flows(id) ON DELETE CASCADE,
    INDEX idx_flow_step (flow_id, step_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flow_id      INT UNSIGNED DEFAULT NULL,
    ref_type     VARCHAR(50) NOT NULL COMMENT 'invoice_buy / expense / ...',
    ref_id       INT UNSIGNED NOT NULL COMMENT 'شناسه رکورد در جدول مربوطه',
    current_step TINYINT UNSIGNED DEFAULT 1,
    status       ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
    requested_by INT UNSIGNED DEFAULT NULL,
    total_amount BIGINT DEFAULT 0,
    description  TEXT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ref       (ref_type, ref_id),
    INDEX idx_status    (status),
    INDEX idx_requester (requested_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_request_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    step_order TINYINT UNSIGNED DEFAULT 1,
    action     ENUM('created','approved','rejected','cancelled','delegated') NOT NULL,
    actor_id   INT UNSIGNED DEFAULT NULL,
    notes      TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES approval_requests(id) ON DELETE CASCADE,
    INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جریان‌های پیش‌فرض
INSERT IGNORE INTO approval_flows (id, name, module, min_amount, is_active) VALUES
(1, 'تأیید فاکتور خرید (بالای ۱۰۰ میلیون تومان)', 'invoice_buy', 1000000000, 1),
(2, 'تأیید هزینه (بالای ۵۰ میلیون تومان)',         'expense',     500000000,  1);

INSERT IGNORE INTO approval_flow_steps (flow_id, step_order, approver_type, approver_value, label) VALUES
(1, 1, 'role', 'management', 'تأیید مدیریت'),
(2, 1, 'role', 'management', 'تأیید مدیریت');
