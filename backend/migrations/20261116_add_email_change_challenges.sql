-- Connect to the intended database explicitly before running this migration.
-- Rehearse on a disposable clone and take a verified backup before production use.
SET NAMES utf8mb4;
SET time_zone = '+08:00';

DROP PROCEDURE IF EXISTS apply_email_change_challenges_migration;
DELIMITER //
CREATE PROCEDURE apply_email_change_challenges_migration()
BEGIN
    DECLARE v_column_count INT DEFAULT 0;
    DECLARE v_index_count INT DEFAULT 0;
    DECLARE v_index_rows INT DEFAULT 0;
    DECLARE v_index_definition_ok INT DEFAULT 0;
    DECLARE v_expression TEXT DEFAULT NULL;
    DECLARE v_email_column_type VARCHAR(64) DEFAULT NULL;
    DECLARE v_sql TEXT DEFAULT NULL;

    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Select the intended database before running this migration.';
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'users'
          AND column_name IN ('id', 'email', 'registration_status', 'registration_unique_email')
    ) <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Required users identity columns are missing; inspect prerequisite migrations.';
    END IF;

    SELECT column_type
      INTO v_email_column_type
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND column_name = 'email';

    IF LOWER(v_email_column_type) <> 'varchar(100)' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'users.email must be VARCHAR(100) for this migration.';
    END IF;

    SELECT LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(generation_expression, '`', ''), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''))
      INTO v_expression
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND column_name = 'registration_unique_email'
       AND data_type = 'varchar'
       AND character_maximum_length = 100
       AND extra = 'STORED GENERATED';
    SET v_expression = REPLACE(v_expression, 'lcase(', 'lower(');

    IF v_expression IS NULL
       OR v_expression NOT IN (
            'casewhenregistration_status=''rejected''thennullelselower(email)end',
            'casewhenregistration_status=''rejected''ornullif(trim(email),'''')isnullthennullelselower(trim(email))end'
       ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'users.registration_unique_email has an unexpected definition.';
    END IF;

    SELECT COUNT(*),
           COALESCE(SUM(
               non_unique = 0
               AND seq_in_index = 1
               AND column_name = 'registration_unique_email'
               AND sub_part IS NULL
           ), 0)
      INTO v_index_rows, v_index_definition_ok
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND index_name = 'uq_users_registration_email';

    IF v_index_rows > 0
       AND (v_index_rows <> 1 OR v_index_definition_ok <> 1) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'uq_users_registration_email has an unexpected definition.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM users
        WHERE COALESCE(registration_status, 'active') <> 'rejected'
          AND NULLIF(TRIM(email), '') IS NOT NULL
        GROUP BY LOWER(TRIM(email))
        HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Duplicate active user emails exist after trimming; resolve them before continuing.';
    END IF;

    CREATE TABLE IF NOT EXISTS email_change_challenges (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        new_email VARCHAR(100) NOT NULL,
        code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        expires_at DATETIME NOT NULL,
        attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_sent_at DATETIME NOT NULL,
        verified_at DATETIME DEFAULT NULL,
        consumed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_email_change_user_created (user_id, created_at),
        KEY idx_email_change_expiration (expires_at),
        CONSTRAINT fk_email_change_user
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    CREATE TABLE IF NOT EXISTS email_change_rate_limits (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        scope_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        window_started_at DATETIME NOT NULL,
        request_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_email_change_rate_scope_window (scope_type, scope_hash, window_started_at),
        KEY idx_email_change_rate_window (window_started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    IF (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'email_change_challenges'
          AND (
              (column_name = 'id' AND data_type = 'bigint' AND column_type LIKE '%unsigned%' AND extra LIKE '%auto_increment%')
              OR (column_name = 'user_id' AND data_type = 'int')
              OR (column_name = 'new_email' AND data_type = 'varchar' AND character_maximum_length = 100)
              OR (column_name = 'code_hash' AND data_type = 'char' AND character_maximum_length = 64)
              OR (column_name = 'expires_at' AND data_type = 'datetime')
              OR (column_name = 'attempt_count' AND data_type = 'tinyint' AND column_type LIKE '%unsigned%')
              OR (column_name = 'last_sent_at' AND data_type = 'datetime')
              OR (column_name = 'verified_at' AND data_type = 'datetime')
              OR (column_name = 'consumed_at' AND data_type = 'datetime')
              OR (column_name = 'created_at' AND data_type = 'datetime')
              OR (column_name = 'updated_at' AND data_type = 'datetime')
          )
    ) <> 11 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'email_change_challenges does not match the required definition.';
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'email_change_challenges'
          AND (
              (index_name = 'PRIMARY' AND non_unique = 0 AND seq_in_index = 1 AND column_name = 'id')
              OR (index_name = 'idx_email_change_user_created' AND seq_in_index = 1 AND column_name = 'user_id')
              OR (index_name = 'idx_email_change_user_created' AND seq_in_index = 2 AND column_name = 'created_at')
              OR (index_name = 'idx_email_change_expiration' AND seq_in_index = 1 AND column_name = 'expires_at')
          )
    ) <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'email_change_challenges indexes do not match the required definition.';
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'email_change_rate_limits'
          AND (
              (column_name = 'id' AND data_type = 'bigint' AND column_type LIKE '%unsigned%' AND extra LIKE '%auto_increment%')
              OR (column_name = 'scope_type' AND data_type = 'varchar' AND character_maximum_length = 32)
              OR (column_name = 'scope_hash' AND data_type = 'char' AND character_maximum_length = 64)
              OR (column_name = 'window_started_at' AND data_type = 'datetime')
              OR (column_name = 'request_count' AND data_type = 'smallint' AND column_type LIKE '%unsigned%')
              OR (column_name = 'created_at' AND data_type = 'datetime')
              OR (column_name = 'updated_at' AND data_type = 'datetime')
          )
    ) <> 7 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'email_change_rate_limits does not match the required definition.';
    END IF;

    IF (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'email_change_rate_limits'
          AND (
              (index_name = 'PRIMARY' AND non_unique = 0 AND seq_in_index = 1 AND column_name = 'id')
              OR (index_name = 'uq_email_change_rate_scope_window' AND non_unique = 0 AND seq_in_index = 1 AND column_name = 'scope_type')
              OR (index_name = 'uq_email_change_rate_scope_window' AND non_unique = 0 AND seq_in_index = 2 AND column_name = 'scope_hash')
              OR (index_name = 'uq_email_change_rate_scope_window' AND non_unique = 0 AND seq_in_index = 3 AND column_name = 'window_started_at')
              OR (index_name = 'idx_email_change_rate_window' AND seq_in_index = 1 AND column_name = 'window_started_at')
          )
    ) <> 5 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'email_change_rate_limits indexes do not match the required definition.';
    END IF;

    SELECT COUNT(*)
      INTO v_column_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND column_name = 'registration_unique_email_email_change';

    SELECT COUNT(*),
           COALESCE(SUM(
               non_unique = 0
               AND seq_in_index = 1
               AND column_name = 'registration_unique_email_email_change'
               AND sub_part IS NULL
           ), 0)
      INTO v_index_rows, v_index_definition_ok
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND index_name = 'uq_users_email_change_guard';

    IF v_column_count = 0 AND v_index_rows > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Email-change guard index exists without its generated column.';
    END IF;
    IF v_index_rows > 0
       AND (v_index_rows <> 1 OR v_index_definition_ok <> 1) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'uq_users_email_change_guard has an unexpected definition.';
    END IF;

    IF v_column_count = 1 THEN
        SELECT LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(generation_expression, '`', ''), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''))
          INTO v_expression
          FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'users'
           AND column_name = 'registration_unique_email_email_change'
           AND data_type = 'varchar'
           AND character_maximum_length = 100
           AND extra = 'STORED GENERATED';
        SET v_expression = REPLACE(v_expression, 'lcase(', 'lower(');
        IF v_expression IS NULL
           OR v_expression <> 'casewhenregistration_status=''rejected''ornullif(trim(email),'''')isnullthennullelselower(trim(email))end' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'The email-change guard column has an unexpected definition.';
        END IF;
    END IF;

    IF v_column_count = 0 THEN
        ALTER TABLE users
            ADD COLUMN registration_unique_email_email_change VARCHAR(100)
                GENERATED ALWAYS AS (
                    CASE
                        WHEN registration_status = 'rejected' OR NULLIF(TRIM(email), '') IS NULL THEN NULL
                        ELSE LOWER(TRIM(email))
                    END
                ) STORED,
            ADD UNIQUE INDEX uq_users_email_change_guard (registration_unique_email_email_change);
    ELSEIF v_index_rows = 0 THEN
        ALTER TABLE users
            ADD UNIQUE INDEX uq_users_email_change_guard (registration_unique_email_email_change);
    END IF;

    SELECT COUNT(*),
           COALESCE(SUM(
               non_unique = 0
               AND seq_in_index = 1
               AND column_name = 'registration_unique_email_email_change'
               AND sub_part IS NULL
           ), 0)
      INTO v_index_rows, v_index_definition_ok
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND index_name = 'uq_users_email_change_guard';
    IF v_index_rows <> 1 OR v_index_definition_ok <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The temporary email uniqueness guard was not verified.';
    END IF;

    SELECT LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(generation_expression, '`', ''), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''))
      INTO v_expression
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND column_name = 'registration_unique_email';
    SET v_expression = REPLACE(v_expression, 'lcase(', 'lower(');

    IF v_expression = 'casewhenregistration_status=''rejected''thennullelselower(email)end' THEN
        ALTER TABLE users
            MODIFY COLUMN registration_unique_email VARCHAR(100)
                GENERATED ALWAYS AS (
                    CASE
                        WHEN registration_status = 'rejected' OR NULLIF(TRIM(email), '') IS NULL THEN NULL
                        ELSE LOWER(TRIM(email))
                    END
                ) STORED;
    END IF;

    SELECT LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(generation_expression, '`', ''), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''))
      INTO v_expression
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND column_name = 'registration_unique_email';
    SET v_expression = REPLACE(v_expression, 'lcase(', 'lower(');
    IF v_expression <> 'casewhenregistration_status=''rejected''ornullif(trim(email),'''')isnullthennullelselower(trim(email))end' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The updated registration_unique_email definition was not verified.';
    END IF;

    SELECT COUNT(*),
           COALESCE(SUM(
               non_unique = 0
               AND seq_in_index = 1
               AND column_name = 'registration_unique_email'
               AND sub_part IS NULL
           ), 0)
      INTO v_index_rows, v_index_definition_ok
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND index_name = 'uq_users_registration_email';

    IF v_index_rows = 0 THEN
        ALTER TABLE users
            ADD UNIQUE INDEX uq_users_registration_email (registration_unique_email);
    ELSEIF v_index_rows <> 1 OR v_index_definition_ok <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'uq_users_registration_email could not be verified after the column update.';
    END IF;

    SELECT COUNT(*),
           COALESCE(SUM(
               non_unique = 0
               AND seq_in_index = 1
               AND column_name = 'registration_unique_email'
               AND sub_part IS NULL
           ), 0)
      INTO v_index_rows, v_index_definition_ok
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND index_name = 'uq_users_registration_email';
    IF v_index_rows <> 1 OR v_index_definition_ok <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The final registration email unique index was not verified.';
    END IF;

    SELECT COUNT(*)
      INTO v_column_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND column_name = 'registration_unique_email_email_change';
    SELECT COUNT(*)
      INTO v_index_count
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND index_name = 'uq_users_email_change_guard';

    IF v_column_count = 1 AND v_index_count = 1 THEN
        ALTER TABLE users
            DROP INDEX uq_users_email_change_guard,
            DROP COLUMN registration_unique_email_email_change;
    ELSEIF v_column_count = 1 AND v_index_count = 0 THEN
        ALTER TABLE users
            DROP COLUMN registration_unique_email_email_change;
    ELSEIF v_column_count = 0 AND v_index_count = 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Email-change guard cleanup is inconsistent; inspect the schema before continuing.';
    END IF;

    SELECT COUNT(*)
      INTO v_column_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND column_name = 'registration_unique_email_email_change';
    SELECT COUNT(*)
      INTO v_index_count
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'users'
       AND index_name = 'uq_users_email_change_guard';
    IF v_column_count <> 0 OR v_index_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Temporary email guard cleanup was not verified.';
    END IF;
END//
DELIMITER ;

CALL apply_email_change_challenges_migration();
DROP PROCEDURE apply_email_change_challenges_migration;
