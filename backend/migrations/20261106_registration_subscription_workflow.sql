USE gym_management;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE membership_plans (
    id INT(11) NOT NULL AUTO_INCREMENT,
    plan_code VARCHAR(40) NOT NULL,
    plan_name VARCHAR(80) NOT NULL,
    duration_months SMALLINT UNSIGNED NOT NULL,
    regular_price DECIMAL(10, 2) NOT NULL,
    student_price DECIMAL(10, 2) NOT NULL,
    senior_price DECIMAL(10, 2) NOT NULL,
    pwd_price DECIMAL(10, 2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_membership_plans_code (plan_code),
    UNIQUE KEY uq_membership_plans_duration (duration_months)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO membership_plans
    (plan_code, plan_name, duration_months, regular_price, student_price, senior_price, pwd_price)
VALUES
    ('monthly_1', '1 Month', 1, 800.00, 700.00, 700.00, 700.00);

ALTER TABLE registration_requests
    MODIFY COLUMN payment_status ENUM(
        'not_required', 'pending', 'submitted', 'verified', 'rejected', 'needs_correction'
    ) NOT NULL DEFAULT 'not_required',
    MODIFY COLUMN discount_status ENUM(
        'not_required', 'pending', 'submitted', 'verified', 'rejected', 'needs_correction'
    ) NOT NULL DEFAULT 'not_required',
    ADD COLUMN registration_source ENUM('online', 'walk_in') NOT NULL DEFAULT 'online' AFTER status,
    ADD COLUMN membership_plan_id INT(11) DEFAULT NULL AFTER membership_type,
    ADD COLUMN payment_method ENUM('cash', 'gcash') DEFAULT NULL AFTER membership_plan_id,
    ADD COLUMN payment_date DATE DEFAULT NULL AFTER payment_amount,
    ADD COLUMN payment_reference VARCHAR(100) DEFAULT NULL AFTER payment_amount,
    ADD COLUMN payment_verified_by INT(11) DEFAULT NULL AFTER payment_reference,
    ADD COLUMN payment_verified_at DATETIME DEFAULT NULL AFTER payment_verified_by,
    ADD COLUMN review_note VARCHAR(500) DEFAULT NULL AFTER reviewed_by,
    ADD COLUMN application_token_hash CHAR(64) DEFAULT NULL AFTER payment_reference,
    ADD UNIQUE KEY uq_registration_request_token (application_token_hash),
    ADD UNIQUE KEY uq_registration_payment_reference (payment_reference),
    ADD KEY idx_registration_requests_source_status (registration_source, status),
    ADD CONSTRAINT fk_registration_requests_plan
        FOREIGN KEY (membership_plan_id) REFERENCES membership_plans (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_registration_requests_payment_verifier
        FOREIGN KEY (payment_verified_by) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE registration_request_files
    MODIFY COLUMN review_status ENUM(
        'pending', 'verified', 'rejected', 'needs_correction'
    ) NOT NULL DEFAULT 'pending',
    DROP INDEX uq_registration_request_document,
    ADD KEY idx_registration_request_document_latest (request_id, document_type, id);

ALTER TABLE payments
    ADD COLUMN membership_plan_id INT(11) DEFAULT NULL AFTER registration_request_id,
    ADD COLUMN duration_months SMALLINT UNSIGNED DEFAULT NULL AFTER membership_plan_id,
    ADD COLUMN payment_method ENUM('cash', 'gcash') DEFAULT NULL AFTER reference_no,
    ADD COLUMN idempotency_key VARCHAR(64) DEFAULT NULL AFTER payment_method,
    ADD COLUMN proof_stored_name VARCHAR(80) DEFAULT NULL AFTER idempotency_key,
    ADD COLUMN proof_original_name VARCHAR(255) DEFAULT NULL AFTER proof_stored_name,
    ADD COLUMN proof_mime_type VARCHAR(100) DEFAULT NULL AFTER proof_original_name,
    ADD COLUMN proof_file_size INT UNSIGNED DEFAULT NULL AFTER proof_mime_type,
    ADD COLUMN verification_status ENUM('not_required', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'not_required' AFTER proof_file_size,
    ADD COLUMN verified_by INT(11) DEFAULT NULL AFTER verification_status,
    ADD COLUMN verified_at DATETIME DEFAULT NULL AFTER verified_by,
    ADD UNIQUE KEY uq_payments_idempotency_key (idempotency_key),
    ADD UNIQUE KEY uq_payments_reference_no (reference_no),
    ADD KEY idx_payments_membership_plan (membership_plan_id),
    ADD CONSTRAINT fk_payments_membership_plan
        FOREIGN KEY (membership_plan_id) REFERENCES membership_plans (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_payments_verifier
        FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL;

CREATE TABLE membership_subscription_periods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    membership_plan_id INT(11) DEFAULT NULL,
    registration_request_id INT(11) DEFAULT NULL,
    payment_id INT(11) DEFAULT NULL,
    duration_months SMALLINT UNSIGNED DEFAULT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    status ENUM('active', 'expired', 'superseded') NOT NULL DEFAULT 'active',
    source ENUM('registration', 'manual_payment', 'paymongo', 'legacy') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscription_period_request (registration_request_id),
    UNIQUE KEY uq_subscription_period_payment (payment_id),
    KEY idx_subscription_period_member_dates (member_id, starts_on, ends_on),
    CONSTRAINT fk_subscription_period_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_period_plan
        FOREIGN KEY (membership_plan_id) REFERENCES membership_plans (id) ON DELETE SET NULL,
    CONSTRAINT fk_subscription_period_request
        FOREIGN KEY (registration_request_id) REFERENCES registration_requests (id) ON DELETE SET NULL,
    CONSTRAINT fk_subscription_period_payment
        FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE SET NULL,
    CONSTRAINT chk_subscription_period_dates CHECK (ends_on >= starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Preserve only known legacy date ranges. Historical plan and duration are unknown.
INSERT INTO membership_subscription_periods
    (member_id, membership_plan_id, duration_months, starts_on, ends_on, status, source)
SELECT
    m.id,
    NULL,
    NULL,
    m.membership_start,
    m.membership_end,
    CASE
        WHEN m.membership_end < CURRENT_DATE THEN 'expired'
        ELSE 'active'
    END,
    'legacy'
FROM members m
WHERE m.membership_type = 'monthly'
  AND m.membership_start IS NOT NULL
  AND m.membership_start <> '0000-00-00'
  AND m.membership_end IS NOT NULL
  AND m.membership_end <> '0000-00-00'
  AND m.membership_end >= m.membership_start;
