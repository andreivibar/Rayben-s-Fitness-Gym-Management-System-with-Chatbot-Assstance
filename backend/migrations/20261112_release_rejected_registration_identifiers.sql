-- Connect to the intended database explicitly before running this migration.
-- Do not run against production without an independently verified backup.
-- On MariaDB 10.4, DDL commits implicitly; this ordering keeps the old unique
-- indexes until all replacement indexes have been created and verified.

DROP PROCEDURE IF EXISTS assert_registration_identity_prerequisites;
DELIMITER //
CREATE PROCEDURE assert_registration_identity_prerequisites()
BEGIN
    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Select the intended database before running this migration.';
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name IN ('username', 'email', 'password', 'role', 'auth_token')
    ) <> 5 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Required users columns are missing; inspect the schema before continuing.';
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'registration_requests'
          AND column_name IN ('username', 'email', 'password', 'status', 'membership_type', 'registration_source', 'member_id')
    ) <> 7 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Required registration_requests columns are missing; apply prerequisite migrations first.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND non_unique = 0
          AND seq_in_index = 1
          AND column_name IN ('username', 'email')
          AND index_name NOT IN (
              'uq_users_username',
              'uq_users_email',
              'uq_users_registration_username',
              'uq_users_registration_email'
          )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Unexpected unique index on users username/email; inspect indexes before continuing.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'registration_requests'
          AND non_unique = 0
          AND seq_in_index = 1
          AND column_name = 'username'
          AND index_name NOT IN (
              'uq_registration_requests_username',
              'uq_registration_requests_active_username'
          )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Unexpected unique index on registration_requests.username; inspect indexes before continuing.';
    END IF;
END//
DELIMITER ;
CALL assert_registration_identity_prerequisites();
DROP PROCEDURE assert_registration_identity_prerequisites;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS registration_status ENUM('active', 'rejected') NOT NULL DEFAULT 'active' AFTER role,
    ADD COLUMN IF NOT EXISTS registration_unique_username VARCHAR(30)
        GENERATED ALWAYS AS (
            CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(username) END
        ) STORED,
    ADD COLUMN IF NOT EXISTS registration_unique_email VARCHAR(100)
        GENERATED ALWAYS AS (
            CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(email) END
        ) STORED;

ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS registration_unique_username VARCHAR(30)
        GENERATED ALWAYS AS (
            CASE WHEN status = 'rejected' THEN NULL ELSE LOWER(username) END
        ) STORED;

DROP PROCEDURE IF EXISTS assert_registration_identity_columns;
DELIMITER //
CREATE PROCEDURE assert_registration_identity_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'registration_status'
          AND LOWER(column_type) = 'enum(''active'',''rejected'')'
          AND is_nullable = 'NO'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The users.registration_status column does not match the required definition.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'registration_unique_username'
          AND data_type = 'varchar'
          AND character_maximum_length = 30
          AND extra = 'STORED GENERATED'
          AND LOWER(generation_expression) LIKE '%registration_status%'
          AND LOWER(generation_expression) LIKE '%rejected%'
          AND LOWER(generation_expression) LIKE '%username%'
          AND LOWER(generation_expression) LIKE '%null%'
          AND LOWER(generation_expression) LIKE '%else%'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The users.registration_unique_username generated column was not verified.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name = 'registration_unique_email'
          AND data_type = 'varchar'
          AND character_maximum_length = 100
          AND extra = 'STORED GENERATED'
          AND LOWER(generation_expression) LIKE '%registration_status%'
          AND LOWER(generation_expression) LIKE '%rejected%'
          AND LOWER(generation_expression) LIKE '%email%'
          AND LOWER(generation_expression) LIKE '%null%'
          AND LOWER(generation_expression) LIKE '%else%'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The users.registration_unique_email generated column was not verified.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'registration_requests'
          AND column_name = 'registration_unique_username'
          AND data_type = 'varchar'
          AND character_maximum_length = 30
          AND extra = 'STORED GENERATED'
          AND LOWER(generation_expression) LIKE '%status%'
          AND LOWER(generation_expression) LIKE '%rejected%'
          AND LOWER(generation_expression) LIKE '%username%'
          AND LOWER(generation_expression) LIKE '%null%'
          AND LOWER(generation_expression) LIKE '%else%'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The registration_requests generated username column was not verified.';
    END IF;
END//
DELIMITER ;
CALL assert_registration_identity_columns();
DROP PROCEDURE assert_registration_identity_columns;

DROP PROCEDURE IF EXISTS assert_registration_identity_preflight;
DELIMITER //
CREATE PROCEDURE assert_registration_identity_preflight()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM users
        WHERE registration_unique_username IS NOT NULL
        GROUP BY registration_unique_username
        HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Duplicate active usernames exist in users; resolve them before continuing.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM users
        WHERE registration_unique_email IS NOT NULL
        GROUP BY registration_unique_email
        HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Duplicate active email values exist in users; resolve them before continuing.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM registration_requests
        WHERE registration_unique_username IS NOT NULL
        GROUP BY registration_unique_username
        HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Duplicate non-rejected usernames exist in registration_requests; resolve them before continuing.';
    END IF;
END//
DELIMITER ;
CALL assert_registration_identity_preflight();
DROP PROCEDURE assert_registration_identity_preflight;

ALTER TABLE users
    ADD UNIQUE INDEX IF NOT EXISTS uq_users_registration_username (registration_unique_username);

ALTER TABLE users
    ADD UNIQUE INDEX IF NOT EXISTS uq_users_registration_email (registration_unique_email);

ALTER TABLE registration_requests
    ADD UNIQUE INDEX IF NOT EXISTS uq_registration_requests_active_username (registration_unique_username);

DROP PROCEDURE IF EXISTS assert_registration_identity_indexes;
DELIMITER //
CREATE PROCEDURE assert_registration_identity_indexes()
BEGIN
    IF (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND index_name = 'uq_users_registration_username'
          AND non_unique = 0
          AND seq_in_index = 1
          AND column_name = 'registration_unique_username'
          AND (
              SELECT COUNT(*)
              FROM information_schema.statistics s2
              WHERE s2.table_schema = DATABASE()
                AND s2.table_name = 'users'
                AND s2.index_name = 'uq_users_registration_username'
          ) = 1
    ) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Replacement unique username index on users was not verified.';
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND index_name = 'uq_users_registration_email'
          AND non_unique = 0
          AND seq_in_index = 1
          AND column_name = 'registration_unique_email'
          AND (
              SELECT COUNT(*)
              FROM information_schema.statistics s2
              WHERE s2.table_schema = DATABASE()
                AND s2.table_name = 'users'
                AND s2.index_name = 'uq_users_registration_email'
          ) = 1
    ) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Replacement unique email index on users was not verified.';
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'registration_requests'
          AND index_name = 'uq_registration_requests_active_username'
          AND non_unique = 0
          AND seq_in_index = 1
          AND column_name = 'registration_unique_username'
          AND (
              SELECT COUNT(*)
              FROM information_schema.statistics s2
              WHERE s2.table_schema = DATABASE()
                AND s2.table_name = 'registration_requests'
                AND s2.index_name = 'uq_registration_requests_active_username'
          ) = 1
    ) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Replacement unique username index on registration_requests was not verified.';
    END IF;
END//
DELIMITER ;
CALL assert_registration_identity_indexes();
DROP PROCEDURE assert_registration_identity_indexes;

ALTER TABLE users
    DROP INDEX IF EXISTS uq_users_username,
    DROP INDEX IF EXISTS uq_users_email;

ALTER TABLE registration_requests
    DROP INDEX IF EXISTS uq_registration_requests_username;

-- Monthly public registration stores the same password hash in users and its
-- initial request. Backfill only a unique exact association; uncertain legacy
-- rows are intentionally left active and their tokens are not invalidated.
UPDATE users u
JOIN registration_requests r
  ON LOWER(u.username) = LOWER(r.username)
 AND LOWER(COALESCE(u.email, '')) = LOWER(COALESCE(r.email, ''))
 AND BINARY u.password = BINARY r.password
SET u.registration_status = 'rejected', u.auth_token = NULL
WHERE u.registration_status = 'active'
  AND LOWER(TRIM(u.role)) = 'member'
  AND r.status = 'rejected'
  AND r.registration_source = 'online'
  AND r.membership_type = 'monthly'
  AND r.member_id IS NULL
  AND (
      SELECT COUNT(*)
      FROM registration_requests r2
      WHERE LOWER(r2.username) = LOWER(r.username)
        AND LOWER(COALESCE(r2.email, '')) = LOWER(COALESCE(r.email, ''))
        AND BINARY r2.password = BINARY r.password
        AND r2.registration_source = 'online'
        AND r2.membership_type = 'monthly'
        AND r2.member_id IS NULL
  ) = 1;

-- Online Daily Pass applications have a direct registration_request.member_id
-- -> members.user_id -> users relationship. If the optional transaction table
-- is absent, skip this backfill rather than failing after the schema changes.
DROP PROCEDURE IF EXISTS backfill_rejected_daily_pass_accounts;
DELIMITER //
CREATE PROCEDURE backfill_rejected_daily_pass_accounts()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'daily_pass_transactions'
    ) THEN
        SET @daily_pass_backfill_sql = '
            UPDATE users u
            JOIN members m ON m.user_id = u.id
            JOIN registration_requests r ON r.member_id = m.id
            SET u.registration_status = ''rejected'', u.auth_token = NULL
            WHERE u.registration_status = ''active''
              AND LOWER(TRIM(u.role)) = ''member''
              AND LOWER(TRIM(m.membership_type)) = ''daily_pass''
              AND r.status = ''rejected''
              AND r.registration_source = ''online''
              AND r.membership_type = ''walk-in''
              AND (
                  SELECT COUNT(*)
                  FROM registration_requests r2
                  WHERE r2.member_id = m.id
                    AND r2.registration_source = ''online''
                    AND r2.membership_type = ''walk-in''
              ) = 1
              AND EXISTS (
                  SELECT 1
                  FROM daily_pass_transactions dp
                  WHERE dp.member_id = m.id
                    AND dp.transaction_source = ''online''
              )';
        PREPARE daily_pass_backfill_stmt FROM @daily_pass_backfill_sql;
        EXECUTE daily_pass_backfill_stmt;
        DEALLOCATE PREPARE daily_pass_backfill_stmt;
    END IF;
END//
DELIMITER ;
CALL backfill_rejected_daily_pass_accounts();
DROP PROCEDURE backfill_rejected_daily_pass_accounts;
