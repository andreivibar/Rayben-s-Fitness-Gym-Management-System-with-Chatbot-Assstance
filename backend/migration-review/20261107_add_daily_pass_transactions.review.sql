-- REVIEW ONLY: guarded, operator-stepped recovery proposal for MariaDB 10.11.
-- DO NOT run this file as a batch. Do not execute until explicitly approved.
-- DDL auto-commits. After any error, stop and resume from current
-- information_schema state using the recovery checklist.
--
-- For every step:
--   absent object         -> run that one statement, then verify its definition;
--   exact expected object -> skip it;
--   incompatible enum, same-name/different definition, duplicate semantic FK,
--   partial table, or ambiguous state -> STOP; do not repair automatically.
-- CREATE TABLE IF NOT EXISTS does not validate or complete a partial table.
--
-- Live preflight: members.membership_type is ENUM('monthly','walk-in');
-- daily_pass_transactions is absent. Existing member rows/dates are preserved.
-- Pass transactions remain separate from monthly subscriptions. No unique
-- index or input requirement is imposed on the optional GCash reference_no.
-- Pass expiration never changes members.account_status or members.status.

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- STEP 1 — Inspect the membership enum before changing it. It must be exactly
-- the known old enum or exactly the target enum. If it contains other values,
-- stop; do not discard them by MODIFY. If already target, skip STEP 2.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'gym_management'
  AND TABLE_NAME = 'members'
  AND COLUMN_NAME = 'membership_type';

-- STEP 2 — Add only daily_pass, retaining the legacy walk-in value. Run only
-- when STEP 1 shows precisely enum('monthly','walk-in') with NOT NULL. The
-- statement can be safely skipped if the column is already the exact target.
ALTER TABLE members
    MODIFY COLUMN membership_type ENUM('monthly', 'walk-in', 'daily_pass') NOT NULL;

-- STEP 3 — Inspect table existence and complete metadata. If absent, create.
-- If present and exactly matching the definition below, skip. Any partial or
-- incompatible table is a STOP condition; do not use IF NOT EXISTS as proof
-- that it is correct and do not drop/recreate it.
CREATE TABLE IF NOT EXISTS daily_pass_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT(11) DEFAULT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_phone VARCHAR(20) DEFAULT NULL,
    pass_code CHAR(32) NOT NULL,
    idempotency_key VARCHAR(64) NOT NULL,
    visit_date DATE NOT NULL,
    expires_at DATETIME NOT NULL,
    payment_method ENUM('cash', 'gcash') NOT NULL,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 100.00,
    payment_status ENUM('unpaid', 'pending', 'paid', 'rejected') NOT NULL DEFAULT 'pending',
    reference_no VARCHAR(100) DEFAULT NULL,
    transaction_source ENUM('online', 'counter') NOT NULL,
    proof_stored_name VARCHAR(80) DEFAULT NULL,
    proof_original_name VARCHAR(255) DEFAULT NULL,
    proof_mime_type VARCHAR(100) DEFAULT NULL,
    proof_file_size INT UNSIGNED DEFAULT NULL,
    recorded_by INT(11) DEFAULT NULL,
    verified_by INT(11) DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    checked_in_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_daily_pass_code (pass_code),
    UNIQUE KEY uq_daily_pass_idempotency (idempotency_key),
    KEY idx_daily_pass_date_status (visit_date, payment_status),
    KEY idx_daily_pass_member_date (member_id, visit_date),
    CONSTRAINT fk_daily_pass_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE SET NULL,
    CONSTRAINT fk_daily_pass_recorder
        FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_daily_pass_verifier
        FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_daily_pass_price CHECK (amount = 100.00),
    CONSTRAINT chk_daily_pass_expiry CHECK (expires_at > CONCAT(visit_date, ' 00:00:00'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- STEP 4 — Postcondition checks. These are SELECT-only. Require the exact
-- membership_type enum, all required columns/indexes/FKs/checks, and no
-- unexpected payment method/status/amount definitions before enabling the
-- application workflow. Do not add a unique index on reference_no.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'gym_management'
  AND (
      (TABLE_NAME = 'members' AND COLUMN_NAME = 'membership_type')
      OR (TABLE_NAME = 'daily_pass_transactions')
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'gym_management'
  AND TABLE_NAME = 'daily_pass_transactions'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME,
       k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
       r.DELETE_RULE, r.UPDATE_RULE
FROM information_schema.KEY_COLUMN_USAGE AS k
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r
  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
 AND r.TABLE_NAME = k.TABLE_NAME
 AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
WHERE k.CONSTRAINT_SCHEMA = 'gym_management'
  AND k.TABLE_NAME = 'daily_pass_transactions'
  AND k.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY k.CONSTRAINT_NAME;

SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
FROM information_schema.TABLE_CONSTRAINTS AS tc
JOIN information_schema.CHECK_CONSTRAINTS AS cc
  ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
 AND cc.TABLE_NAME = tc.TABLE_NAME
 AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
WHERE tc.CONSTRAINT_SCHEMA = 'gym_management'
  AND tc.TABLE_NAME = 'daily_pass_transactions'
  AND tc.CONSTRAINT_TYPE = 'CHECK'
ORDER BY tc.CONSTRAINT_NAME;
