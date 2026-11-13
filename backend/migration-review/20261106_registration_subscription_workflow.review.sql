-- REVIEW ONLY: guarded, operator-stepped recovery proposal for MariaDB 10.11.
-- DO NOT run this file as a batch. Do not execute until explicitly approved.
-- DDL auto-commits. After any error, stop and resume from the recovery
-- checklist using current information_schema state; never rerun from line 1.
--
-- For every step:
--   absent object        -> run that one statement, then verify its definition;
--   exact expected object-> skip it;
--   same name/different definition, duplicate semantic FK, unexpected enum,
--   partial table, conflicting plan, or ambiguous data -> STOP for review.
-- IF NOT EXISTS only prevents duplicate names; it does not prove equivalence.
--
-- Confirmed base schema:
-- * 20261102 effects are present; do not rerun that prerequisite.
-- * payments.payment_method, payments.registration_request_id,
--   uq_payments_reference_no, uq_payments_registration_request, and
--   uq_registration_request_document already exist. Do not add/replace them.
-- * registration_requests.payment_reference is intentionally absent.
-- * payments.payment_channel already has paymongo, counter, gcash. Preserve it.
-- * member dates are not updated by this proposal. Historical values remain
--   exactly as stored; unknown duration/plan is NULL in legacy snapshots.
--
-- MariaDB FK type correction: payment_id and registration_request_id below
-- are INT(11), matching the signed INT(11) parent IDs in this live schema.

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- STEP 1 — Plan table.
-- Before execution, inspect information_schema.TABLES, COLUMNS, STATISTICS,
-- TABLE_CONSTRAINTS and CHECK_CONSTRAINTS. If the table exists, require the
-- exact definition below; a partial/incompatible table is a STOP condition,
-- not a CREATE IF NOT EXISTS success.
CREATE TABLE IF NOT EXISTS membership_plans (
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
    UNIQUE KEY uq_membership_plans_duration (duration_months),
    CONSTRAINT chk_membership_plan_approved_terms CHECK (
        plan_code = 'monthly_1'
        AND duration_months = 1
        AND regular_price = 800.00
        AND student_price = 700.00
        AND senior_price = 700.00
        AND pwd_price = 700.00
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- STEP 2 — Plan seed. First inspect all rows. Any plan_code=monthly_1 row with
-- different values, or any unapproved plan/duration, is a STOP condition.
-- Exact existing seed: skip. Missing seed and no conflicts: insert once.
SELECT id, plan_code, duration_months, regular_price, student_price,
       senior_price, pwd_price, is_active
FROM membership_plans
ORDER BY id;

INSERT INTO membership_plans
    (plan_code, plan_name, duration_months, regular_price, student_price, senior_price, pwd_price)
SELECT 'monthly_1', '1 Month', 1, 800.00, 700.00, 700.00, 700.00
WHERE NOT EXISTS (
    SELECT 1
    FROM membership_plans
    WHERE plan_code = 'monthly_1'
);

-- STEP 3 — Registration request enum expansions.
-- Inspect COLUMN_TYPE/IS_NULLABLE/COLUMN_DEFAULT first. If already exactly
-- the target enum, skip. If current values contain unexpected states, stop;
-- do not narrow or discard them.
ALTER TABLE registration_requests
    MODIFY COLUMN payment_status ENUM(
        'not_required', 'pending', 'submitted', 'verified', 'rejected', 'needs_correction'
    ) NOT NULL DEFAULT 'not_required';

ALTER TABLE registration_requests
    MODIFY COLUMN discount_status ENUM(
        'not_required', 'pending', 'submitted', 'verified', 'rejected', 'needs_correction'
    ) NOT NULL DEFAULT 'not_required';

-- STEP 4 — Registration request columns. Verify each absent/exact/incompatible
-- independently in information_schema.COLUMNS. MariaDB IF NOT EXISTS skips
-- same-name columns; it does NOT validate their definitions. Stop if a
-- present column differs from the declaration.
ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS registration_source ENUM('online', 'walk_in') NOT NULL DEFAULT 'online';
ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS membership_plan_id INT(11) DEFAULT NULL;
ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS payment_method ENUM('cash', 'gcash') DEFAULT NULL;
ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS payment_date DATE DEFAULT NULL;
ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS payment_verified_by INT(11) DEFAULT NULL;
ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS payment_verified_at DATETIME DEFAULT NULL;
ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS review_note VARCHAR(500) DEFAULT NULL;
ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS application_token_hash CHAR(64) DEFAULT NULL;

-- STEP 5 — Registration request indexes. Confirm both exact name and column
-- order first. If the name exists with different columns, stop. Also check for
-- equivalent indexes under other names and skip redundant creation.
ALTER TABLE registration_requests
    ADD UNIQUE INDEX IF NOT EXISTS uq_registration_request_token (application_token_hash);
ALTER TABLE registration_requests
    ADD INDEX IF NOT EXISTS idx_registration_requests_source_status (registration_source, status);

-- STEP 6 — Registration request FKs. FKs do not have ADD IF NOT EXISTS.
-- Inspect KEY_COLUMN_USAGE and REFERENTIAL_CONSTRAINTS before each statement.
-- Exact named/equivalent FK with the required action: skip. Same name with a
-- different target/action, or an incompatible duplicate: STOP.
ALTER TABLE registration_requests
    ADD CONSTRAINT fk_registration_requests_plan
        FOREIGN KEY (membership_plan_id) REFERENCES membership_plans (id) ON DELETE RESTRICT;
ALTER TABLE registration_requests
    ADD CONSTRAINT fk_registration_requests_payment_verifier
        FOREIGN KEY (payment_verified_by) REFERENCES users (id) ON DELETE SET NULL;

-- STEP 7 — File review enum. Inspect current values first. Exact target: skip.
-- Unexpected values: stop; do not narrow the enum.
ALTER TABLE registration_request_files
    MODIFY COLUMN review_status ENUM(
        'pending', 'verified', 'rejected', 'needs_correction'
    ) NOT NULL DEFAULT 'pending';

-- STEP 8 — Payment columns. Existing payment_method and all historical
-- PayMongo/reference columns are deliberately untouched. Check every column
-- definition first; exact: skip, absent: add, incompatible: stop.
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS membership_plan_id INT(11) DEFAULT NULL;
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS duration_months SMALLINT UNSIGNED DEFAULT NULL;
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(64) DEFAULT NULL;
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS proof_stored_name VARCHAR(80) DEFAULT NULL;
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS proof_original_name VARCHAR(255) DEFAULT NULL;
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS proof_mime_type VARCHAR(100) DEFAULT NULL;
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS proof_file_size INT UNSIGNED DEFAULT NULL;
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS verification_status
        ENUM('not_required', 'pending', 'verified', 'rejected')
        NOT NULL DEFAULT 'not_required';
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS verified_by INT(11) DEFAULT NULL;
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS verified_at DATETIME DEFAULT NULL;

-- STEP 9 — Payment indexes. Check exact index definitions and duplicate
-- non-NULL idempotency keys before adding the unique index. Duplicate values
-- are a STOP condition; do not delete, rewrite, or merge payment history.
SELECT idempotency_key, COUNT(*) AS duplicate_count
FROM payments
WHERE idempotency_key IS NOT NULL
GROUP BY idempotency_key
HAVING COUNT(*) > 1;

ALTER TABLE payments
    ADD UNIQUE INDEX IF NOT EXISTS uq_payments_idempotency_key (idempotency_key);
ALTER TABLE payments
    ADD INDEX IF NOT EXISTS idx_payments_membership_plan (membership_plan_id);

-- STEP 10 — Payment FKs. Use the same exact-name/equivalent-FK checks as
-- STEP 6. Do not add duplicates or alter the existing member/payment FKs.
ALTER TABLE payments
    ADD CONSTRAINT fk_payments_membership_plan
        FOREIGN KEY (membership_plan_id) REFERENCES membership_plans (id) ON DELETE SET NULL;
ALTER TABLE payments
    ADD CONSTRAINT fk_payments_verifier
        FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL;

-- STEP 11 — Subscription-history table.
-- Before execution, inspect all metadata. Exact table: skip. Absent table:
-- create. Any partial/incompatible table: STOP and write a separate reviewed
-- repair plan; never assume CREATE IF NOT EXISTS completes a partial table.
-- FK delete behavior intentionally preserves history: member RESTRICT; optional
-- plan/request/payment links SET NULL. Referenced IDs use signed INT(11), as in
-- the verified live parent tables. The compound unique key prevents duplicate
-- legacy snapshots for the same member/date-range/source.
CREATE TABLE IF NOT EXISTS membership_subscription_periods (
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
    UNIQUE KEY uq_subscription_period_member_dates_source
        (member_id, starts_on, ends_on, source),
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

-- STEP 12 — Legacy snapshot backfill. Run only after verifying the table and
-- constraints. Review the two exact member dates before and after. Detect any
-- duplicate snapshots before adding the compound unique index to an existing
-- partial table. The NOT EXISTS predicate makes a sequential retry safe and
-- does not update/delete existing period or member rows. Do not run concurrently.
-- Member IDs 1 and 31 are approved test-account exclusions: never backfill
-- them. If the read-only preview returns zero candidates, mark this data
-- checkpoint complete with zero inserts.
SELECT id, membership_type, membership_start, membership_end
FROM members
WHERE id IN (1, 31)
ORDER BY id;

-- Read-only preview; review this candidate set before the INSERT below.
SELECT
    m.id AS member_id,
    m.membership_start AS starts_on,
    m.membership_end AS ends_on,
    NULL AS membership_plan_id,
    NULL AS duration_months,
    CASE WHEN m.membership_end < CURRENT_DATE THEN 'expired' ELSE 'active' END AS status,
    'legacy' AS source
FROM members AS m
WHERE m.membership_type = 'monthly'
  AND m.id NOT IN (1, 31)
  AND m.membership_start IS NOT NULL
  AND m.membership_start <> '0000-00-00'
  AND m.membership_end IS NOT NULL
  AND m.membership_end <> '0000-00-00'
  AND m.membership_end >= m.membership_start
  AND NOT EXISTS (
      SELECT 1
      FROM membership_subscription_periods AS p
      WHERE p.member_id = m.id
        AND p.starts_on = m.membership_start
        AND p.ends_on = m.membership_end
        AND p.source = 'legacy'
  )
ORDER BY m.id;

SELECT member_id, starts_on, ends_on, source, COUNT(*) AS duplicate_count
FROM membership_subscription_periods
WHERE source = 'legacy'
GROUP BY member_id, starts_on, ends_on, source
HAVING COUNT(*) > 1;

INSERT INTO membership_subscription_periods
    (member_id, membership_plan_id, duration_months, starts_on, ends_on, status, source)
SELECT
    m.id, NULL, NULL, m.membership_start, m.membership_end,
    CASE WHEN m.membership_end < CURRENT_DATE THEN 'expired' ELSE 'active' END,
    'legacy'
FROM members AS m
WHERE m.membership_type = 'monthly'
  AND m.id NOT IN (1, 31)
  AND m.membership_start IS NOT NULL
  AND m.membership_start <> '0000-00-00'
  AND m.membership_end IS NOT NULL
  AND m.membership_end <> '0000-00-00'
  AND m.membership_end >= m.membership_start
  AND NOT EXISTS (
      SELECT 1
      FROM membership_subscription_periods AS p
      WHERE p.member_id = m.id
        AND p.starts_on = m.membership_start
        AND p.ends_on = m.membership_end
        AND p.source = 'legacy'
  );
