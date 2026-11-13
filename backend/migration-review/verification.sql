-- READ-ONLY MIGRATION COMPATIBILITY INSPECTION
-- This file contains SELECT statements only. No schema or data is changed.
-- All application tables are qualified with `gym_management`; this does not
-- rely on phpMyAdmin's selected/default database.
--
-- Run in phpMyAdmin's SQL tab. Start with sections 1-7, which use only
-- information_schema. Data queries in sections 8-11 use only the already
-- confirmed existing base tables: members, payments, registration_requests,
-- and users. Migration-only tables are inspected by metadata only in this
-- script so a missing pending table cannot cause an unknown-table error.

-- 1. Context and required-table inventory.
SELECT DATABASE() AS selected_database, VERSION() AS server_version;

SELECT
    expected.table_name,
    CASE WHEN actual.TABLE_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS table_status
FROM (
    SELECT 'users' AS table_name
    UNION ALL SELECT 'members'
    UNION ALL SELECT 'payments'
    UNION ALL SELECT 'registration_requests'
    UNION ALL SELECT 'registration_request_files'
    UNION ALL SELECT 'gym_payment_settings'
    UNION ALL SELECT 'membership_plans'
    UNION ALL SELECT 'membership_subscription_periods'
    UNION ALL SELECT 'daily_pass_transactions'
) AS expected
LEFT JOIN `information_schema`.`TABLES` AS actual
    ON actual.TABLE_SCHEMA = 'gym_management'
   AND actual.TABLE_NAME = expected.table_name
ORDER BY expected.table_name;

-- 2. Columns, types, enum values, nullability, defaults, and extras.
SELECT
    TABLE_NAME,
    ORDINAL_POSITION,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    EXTRA
FROM `information_schema`.`COLUMNS`
WHERE TABLE_SCHEMA = 'gym_management'
  AND TABLE_NAME IN (
      'users', 'members', 'payments', 'registration_requests',
      'registration_request_files', 'gym_payment_settings',
      'membership_plans', 'membership_subscription_periods',
      'daily_pass_transactions'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 3. Indexes and unique constraints.
SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME,
    SUB_PART,
    INDEX_TYPE
FROM `information_schema`.`STATISTICS`
WHERE TABLE_SCHEMA = 'gym_management'
  AND TABLE_NAME IN (
      'members', 'payments', 'registration_requests',
      'registration_request_files', 'gym_payment_settings',
      'membership_plans', 'membership_subscription_periods',
      'daily_pass_transactions'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- 4. Foreign keys and delete/update behavior.
SELECT
    k.TABLE_NAME,
    k.CONSTRAINT_NAME,
    k.COLUMN_NAME,
    k.REFERENCED_TABLE_NAME,
    k.REFERENCED_COLUMN_NAME,
    r.DELETE_RULE,
    r.UPDATE_RULE
FROM `information_schema`.`KEY_COLUMN_USAGE` AS k
LEFT JOIN `information_schema`.`REFERENTIAL_CONSTRAINTS` AS r
    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
   AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
   AND r.TABLE_NAME = k.TABLE_NAME
WHERE k.TABLE_SCHEMA = 'gym_management'
  AND k.REFERENCED_TABLE_NAME IS NOT NULL
  AND k.TABLE_NAME IN (
      'payments', 'registration_requests', 'registration_request_files',
      'gym_payment_settings', 'membership_subscription_periods',
      'daily_pass_transactions'
  )
ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION;

-- 5. Check-constraint definitions, including fixed-price and date rules.
-- CHECK_CONSTRAINTS is available on the verified MariaDB 10.11 server.
SELECT
    tc.TABLE_NAME,
    tc.CONSTRAINT_NAME,
    cc.CHECK_CLAUSE
FROM `information_schema`.`TABLE_CONSTRAINTS` AS tc
JOIN `information_schema`.`CHECK_CONSTRAINTS` AS cc
  ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
 AND cc.TABLE_NAME = tc.TABLE_NAME
 AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
WHERE tc.CONSTRAINT_SCHEMA = 'gym_management'
  AND tc.CONSTRAINT_TYPE = 'CHECK'
  AND tc.TABLE_NAME IN (
      'gym_payment_settings', 'membership_plans',
      'membership_subscription_periods', 'daily_pass_transactions'
  )
ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME;

-- 6. Discover a migration ledger, if one exists. Project files do not define
-- a migration ledger, so this checks metadata and does not query an unknown
-- ledger table.
SELECT TABLE_NAME
FROM `information_schema`.`TABLES`
WHERE TABLE_SCHEMA = 'gym_management'
  AND TABLE_TYPE = 'BASE TABLE'
  AND (
      LOWER(TABLE_NAME) LIKE '%migration%'
      OR LOWER(TABLE_NAME) LIKE '%schema_version%'
      OR LOWER(TABLE_NAME) LIKE '%version_history%'
  )
ORDER BY TABLE_NAME;

-- 7. Critical migration columns and current enum definitions.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM `information_schema`.`COLUMNS`
WHERE TABLE_SCHEMA = 'gym_management'
  AND (
      (TABLE_NAME = 'members' AND COLUMN_NAME IN
          ('membership_type', 'member_category', 'membership_start',
           'membership_end', 'status', 'account_status', 'user_id'))
      OR (TABLE_NAME = 'payments' AND COLUMN_NAME IN
          ('member_id', 'registration_request_id', 'amount', 'payment_type',
           'status', 'payment_date', 'reference_no', 'payment_method',
           'payment_channel', 'checkout_session_id', 'paymongo_payment_id',
           'booking_id', 'recorded_by', 'membership_plan_id', 'duration_months',
           'idempotency_key', 'proof_stored_name', 'proof_original_name',
           'proof_mime_type', 'proof_file_size', 'verification_status',
           'verified_by', 'verified_at'))
      OR (TABLE_NAME = 'registration_requests' AND COLUMN_NAME IN
          ('membership_type', 'status', 'payment_status', 'discount_status',
           'payment_amount', 'reviewed_by', 'reviewed_at', 'review_note', 'payment_reference',
           'member_id', 'membership_plan_id', 'payment_method', 'payment_date',
           'payment_verified_by', 'payment_verified_at', 'registration_source',
           'application_token_hash'))
      OR (TABLE_NAME = 'registration_request_files' AND COLUMN_NAME IN
          ('request_id', 'document_type', 'review_status', 'reviewed_by'))
      OR (TABLE_NAME = 'users' AND COLUMN_NAME IN ('id', 'role'))
      OR (TABLE_NAME = 'gym_payment_settings' AND COLUMN_NAME IN
          ('id', 'gcash_account_name', 'gcash_account_number', 'updated_by', 'updated_at'))
      OR (TABLE_NAME = 'membership_plans' AND COLUMN_NAME IN
          ('id', 'plan_code', 'plan_name', 'duration_months', 'regular_price',
           'student_price', 'senior_price', 'pwd_price', 'is_active'))
      OR (TABLE_NAME = 'membership_subscription_periods' AND COLUMN_NAME IN
          ('id', 'member_id', 'membership_plan_id', 'registration_request_id',
           'payment_id', 'duration_months', 'starts_on', 'ends_on', 'status', 'source'))
      OR (TABLE_NAME = 'daily_pass_transactions' AND COLUMN_NAME IN
          ('id', 'member_id', 'customer_name', 'customer_phone', 'pass_code',
           'idempotency_key', 'visit_date', 'expires_at', 'payment_method', 'amount',
           'payment_status', 'reference_no', 'transaction_source', 'proof_stored_name',
           'proof_original_name', 'proof_mime_type', 'proof_file_size', 'recorded_by',
           'verified_by', 'verified_at', 'checked_in_at'))
  )
ORDER BY TABLE_NAME, COLUMN_NAME;

-- Optional-column guards. Use these metadata results before any data SELECT
-- that names an optional workflow column. A missing row means that column
-- does not exist and a query referencing it must not be run.
SELECT
    expected.TABLE_NAME,
    expected.COLUMN_NAME,
    CASE WHEN actual.COLUMN_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS column_status,
    actual.COLUMN_TYPE,
    actual.IS_NULLABLE,
    actual.COLUMN_DEFAULT
FROM (
    SELECT 'payments' AS TABLE_NAME, 'idempotency_key' AS COLUMN_NAME
    UNION ALL SELECT 'payments', 'membership_plan_id'
    UNION ALL SELECT 'payments', 'duration_months'
    UNION ALL SELECT 'registration_requests', 'application_token_hash'
    UNION ALL SELECT 'registration_requests', 'membership_plan_id'
) AS expected
LEFT JOIN `information_schema`.`COLUMNS` AS actual
  ON actual.TABLE_SCHEMA = 'gym_management'
 AND actual.TABLE_NAME = expected.TABLE_NAME
 AND actual.COLUMN_NAME = expected.COLUMN_NAME
ORDER BY expected.TABLE_NAME, expected.COLUMN_NAME;

-- 8. Member data audit. Names and contact details are intentionally omitted.
SELECT id, user_id, membership_type, member_category, membership_start,
       membership_end, status, account_status
FROM `gym_management`.`members`
ORDER BY id;

SELECT membership_type, member_category, status, account_status,
       COUNT(*) AS member_count
FROM `gym_management`.`members`
GROUP BY membership_type, member_category, status, account_status
ORDER BY membership_type, member_category, status, account_status;

-- Invalid legacy monthly date records; expected to remain unchanged.
SELECT id, membership_start, membership_end
FROM `gym_management`.`members`
WHERE membership_type = 'monthly'
  AND (
      membership_start IS NULL
      OR membership_end IS NULL
      OR membership_start = '0000-00-00'
      OR membership_end = '0000-00-00'
      OR membership_end < membership_start
  )
ORDER BY id;

-- Exact supplied member dates for preservation verification.
SELECT id, membership_type, membership_start, membership_end, status, account_status
FROM `gym_management`.`members`
WHERE id IN (1, 31)
ORDER BY id;

-- 9. Payment history audit without revealing reference values or PayMongo IDs.
SELECT payment_type, status, payment_method, payment_channel,
       COUNT(*) AS payment_count, SUM(amount) AS amount_total,
       SUM(checkout_session_id IS NOT NULL) AS checkout_link_count,
       SUM(paymongo_payment_id IS NOT NULL) AS paymongo_link_count
FROM `gym_management`.`payments`
GROUP BY payment_type, status, payment_method, payment_channel
ORDER BY payment_type, status, payment_method, payment_channel;

-- Duplicate non-empty legacy payment references; expected no rows.
SELECT COUNT(*) AS duplicate_reference_groups
FROM (
    SELECT reference_no
    FROM `gym_management`.`payments`
    WHERE reference_no IS NOT NULL AND reference_no <> ''
    GROUP BY reference_no
    HAVING COUNT(*) > 1
) AS duplicate_references;

-- Payment links to registration requests; expected zero linked records based
-- on the previously supplied live-database findings.
SELECT
    COUNT(*) AS linked_payment_count,
    COUNT(DISTINCT registration_request_id) AS distinct_registration_count
FROM `gym_management`.`payments`
WHERE registration_request_id IS NOT NULL;

-- 10. Registration/payment/discount status audit. No reference number or PII
-- is selected. payment_reference presence is checked via metadata in section 7.
SELECT membership_type, status, payment_status, discount_status,
       COUNT(*) AS request_count,
       SUM(payment_amount IS NULL) AS missing_payment_amount_count
FROM `gym_management`.`registration_requests`
GROUP BY membership_type, status, payment_status, discount_status
ORDER BY membership_type, status, payment_status, discount_status;

-- Discounted legacy accounts without verified discount approval evidence.
-- A zero count means every current discounted-category member has a linked
-- verified discount-proof file through a verified registration request.
SELECT
    m.member_category,
    COUNT(DISTINCT m.id) AS discounted_member_count,
    COUNT(DISTINCT CASE WHEN f.id IS NULL THEN m.id END) AS missing_verified_evidence_count
FROM `gym_management`.`members` AS m
LEFT JOIN `gym_management`.`registration_requests` AS r
    ON r.member_id = m.id
   AND r.discount_status = 'verified'
LEFT JOIN `gym_management`.`registration_request_files` AS f
    ON f.request_id = r.id
   AND f.document_type = 'discount_proof'
   AND f.review_status = 'verified'
WHERE m.member_category IN ('student', 'senior', 'pwd')
GROUP BY m.member_category
ORDER BY m.member_category;

-- 11. Orphan checks on confirmed base-schema relationships.
SELECT 'payments.member_id' AS relationship, COUNT(*) AS orphan_count
FROM `gym_management`.`payments` AS p
LEFT JOIN `gym_management`.`members` AS m ON m.id = p.member_id
WHERE p.member_id IS NOT NULL AND m.id IS NULL
UNION ALL
SELECT 'payments.booking_id', COUNT(*)
FROM `gym_management`.`payments` AS p
LEFT JOIN `gym_management`.`bookings` AS b ON b.id = p.booking_id
WHERE p.booking_id IS NOT NULL AND b.id IS NULL
UNION ALL
SELECT 'payments.registration_request_id', COUNT(*)
FROM `gym_management`.`payments` AS p
LEFT JOIN `gym_management`.`registration_requests` AS r
    ON r.id = p.registration_request_id
WHERE p.registration_request_id IS NOT NULL AND r.id IS NULL
UNION ALL
SELECT 'members.user_id', COUNT(*)
FROM `gym_management`.`members` AS m
LEFT JOIN `gym_management`.`users` AS u ON u.id = m.user_id
WHERE m.user_id IS NOT NULL AND u.id IS NULL
UNION ALL
SELECT 'registration_requests.member_id', COUNT(*)
FROM `gym_management`.`registration_requests` AS r
LEFT JOIN `gym_management`.`members` AS m ON m.id = r.member_id
WHERE r.member_id IS NOT NULL AND m.id IS NULL
UNION ALL
SELECT 'registration_requests.reviewed_by', COUNT(*)
FROM `gym_management`.`registration_requests` AS r
LEFT JOIN `gym_management`.`users` AS u ON u.id = r.reviewed_by
WHERE r.reviewed_by IS NOT NULL AND u.id IS NULL
UNION ALL
SELECT 'registration_request_files.request_id', COUNT(*)
FROM `gym_management`.`registration_request_files` AS f
LEFT JOIN `gym_management`.`registration_requests` AS r ON r.id = f.request_id
WHERE r.id IS NULL
UNION ALL
SELECT 'registration_request_files.reviewed_by', COUNT(*)
FROM `gym_management`.`registration_request_files` AS f
LEFT JOIN `gym_management`.`users` AS u ON u.id = f.reviewed_by
WHERE f.reviewed_by IS NOT NULL AND u.id IS NULL
UNION ALL
SELECT 'payments.recorded_by', COUNT(*)
FROM `gym_management`.`payments` AS p
LEFT JOIN `gym_management`.`users` AS u ON u.id = p.recorded_by
WHERE p.recorded_by IS NOT NULL AND u.id IS NULL
UNION ALL
SELECT 'gym_payment_settings.updated_by', COUNT(*)
FROM `gym_management`.`gym_payment_settings` AS s
LEFT JOIN `gym_management`.`users` AS u ON u.id = s.updated_by
WHERE s.updated_by IS NOT NULL AND u.id IS NULL;

-- 12. Migration readiness from metadata (does not query the pending tables).
SELECT
    '20261102_add_registration_payment_verification' AS migration_name,
    (SELECT COUNT(*) FROM `information_schema`.`TABLES`
      WHERE TABLE_SCHEMA = 'gym_management'
        AND TABLE_NAME IN ('registration_request_files', 'gym_payment_settings')) AS existing_required_tables,
    (SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
      WHERE TABLE_SCHEMA = 'gym_management' AND TABLE_NAME = 'registration_requests'
        AND COLUMN_NAME IN ('member_id', 'payment_status', 'discount_status', 'payment_amount',
                            'reviewed_by', 'reviewed_at')) AS existing_required_columns
UNION ALL
SELECT
    '20261106_registration_subscription_workflow',
    (SELECT COUNT(*) FROM `information_schema`.`TABLES`
      WHERE TABLE_SCHEMA = 'gym_management'
        AND TABLE_NAME IN ('membership_plans', 'membership_subscription_periods')),
    (SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
      WHERE TABLE_SCHEMA = 'gym_management' AND TABLE_NAME = 'registration_requests'
        AND COLUMN_NAME IN ('membership_plan_id', 'payment_method', 'payment_date',
                            'payment_verified_by', 'payment_verified_at',
                            'registration_source', 'application_token_hash'))
UNION ALL
SELECT
    '20261107_add_daily_pass_transactions',
    (SELECT COUNT(*) FROM `information_schema`.`TABLES`
      WHERE TABLE_SCHEMA = 'gym_management'
        AND TABLE_NAME = 'daily_pass_transactions'),
    (SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
      WHERE TABLE_SCHEMA = 'gym_management' AND TABLE_NAME = 'members'
        AND COLUMN_NAME = 'membership_type'
        AND COLUMN_TYPE LIKE '%daily_pass%');

-- 13. Plan, subscription-period, and daily-pass data checks are intentionally
-- not run here: those tables were reported absent in the supplied findings.
-- Sections 1-7 and 12 establish their actual presence/absence without risking
-- an unknown-table error. The following SELECTs are commented out because
-- the respective tables do not exist in the live database. Run an individual
-- query only after the inventory confirms its table is present and section 2
-- confirms the required columns.
--
-- 14. Run after 20261106 creates membership_plans and
-- membership_subscription_periods.
-- SELECT plan_code, duration_months, regular_price, student_price,
--        senior_price, pwd_price, is_active
-- FROM `gym_management`.`membership_plans`
-- WHERE plan_code <> 'monthly_1' OR duration_months <> 1
--    OR regular_price <> 800.00 OR student_price <> 700.00
--    OR senior_price <> 700.00 OR pwd_price <> 700.00;
--
-- SELECT id, member_id, starts_on, ends_on, duration_months, status, source
-- FROM `gym_management`.`membership_subscription_periods`
-- WHERE starts_on IS NULL OR ends_on IS NULL OR ends_on < starts_on;
--
-- SELECT p1.member_id, p1.id AS period_id_1, p2.id AS period_id_2,
--        p1.starts_on AS starts_on_1, p1.ends_on AS ends_on_1,
--        p2.starts_on AS starts_on_2, p2.ends_on AS ends_on_2
-- FROM `gym_management`.`membership_subscription_periods` AS p1
-- JOIN `gym_management`.`membership_subscription_periods` AS p2
--   ON p1.member_id = p2.member_id
--  AND p1.id < p2.id
--  AND p1.status = 'active'
--  AND p2.status = 'active'
--  AND p1.starts_on <= p2.ends_on
--  AND p2.starts_on <= p1.ends_on;
--
-- 15. Run after 20261107 creates daily_pass_transactions.
-- SELECT payment_method, payment_status, amount, COUNT(*) AS transaction_count
-- FROM `gym_management`.`daily_pass_transactions`
-- GROUP BY payment_method, payment_status, amount
-- ORDER BY payment_method, payment_status, amount;
--
-- SELECT id, amount, payment_method, payment_status
-- FROM `gym_management`.`daily_pass_transactions`
-- WHERE amount <> 100.00
--    OR payment_method NOT IN ('cash', 'gcash')
--    OR payment_status NOT IN ('unpaid', 'pending', 'paid', 'rejected');
