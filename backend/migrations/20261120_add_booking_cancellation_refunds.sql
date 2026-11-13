-- Apply only after selecting the intended database with the database client.
-- This migration never selects a database itself. It is safe to rerun after DDL interruption.
-- Prerequisites: InnoDB bookings, users, members, payments, and
-- subscription_booking_credits tables with integer primary keys.

DELIMITER //

DROP PROCEDURE IF EXISTS feature9_assert_prerequisites//
CREATE PROCEDURE feature9_assert_prerequisites()
BEGIN
    DECLARE v_database VARCHAR(64);
    DECLARE v_missing INT DEFAULT 0;
    SET v_database = DATABASE();
    IF v_database IS NULL OR v_database = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Select the target database before applying Feature #9 migration.';
    END IF;
    SELECT COUNT(*) INTO v_missing
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = v_database
       AND TABLE_NAME IN ('bookings', 'users', 'members', 'payments', 'subscription_booking_credits')
       AND ENGINE = 'InnoDB';
    IF v_missing <> 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Feature #9 prerequisites are missing or are not InnoDB tables.';
    END IF;
    SELECT COUNT(*) INTO v_missing
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = v_database
       AND ((TABLE_NAME = 'bookings' AND COLUMN_NAME IN ('id', 'status', 'notes', 'created_at'))
         OR (TABLE_NAME = 'users' AND COLUMN_NAME = 'id')
         OR (TABLE_NAME = 'members' AND COLUMN_NAME = 'id')
         OR (TABLE_NAME = 'payments' AND COLUMN_NAME IN ('id', 'booking_id', 'member_id'))
         OR (TABLE_NAME = 'subscription_booking_credits' AND COLUMN_NAME IN ('id', 'applied_booking_id', 'status')));
    IF v_missing <> 12 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Feature #9 prerequisite columns are missing.';
    END IF;
    SELECT COUNT(*) INTO v_missing
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = v_database
       AND ((TABLE_NAME = 'bookings' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'int(11)')
         OR (TABLE_NAME = 'users' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'int(11)')
         OR (TABLE_NAME = 'members' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'int(11)')
         OR (TABLE_NAME = 'payments' AND COLUMN_NAME IN ('id', 'booking_id', 'member_id') AND COLUMN_TYPE = 'int(11)')
         OR (TABLE_NAME = 'subscription_booking_credits' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'bigint(20) unsigned')
         OR (TABLE_NAME = 'subscription_booking_credits' AND COLUMN_NAME = 'applied_booking_id' AND COLUMN_TYPE = 'int(11)'));
    IF v_missing <> 8 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Feature #9 prerequisite key columns have incompatible types.';
    END IF;
END//

DROP PROCEDURE IF EXISTS feature9_ensure_column//
CREATE PROCEDURE feature9_ensure_column(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition VARCHAR(255),
    IN p_expected_type VARCHAR(255),
    IN p_expected_nullable VARCHAR(3)
)
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_type VARCHAR(255);
    DECLARE v_nullable VARCHAR(3);
    DECLARE v_sql TEXT;
    SELECT COUNT(*), MAX(COLUMN_TYPE), MAX(IS_NULLABLE)
      INTO v_count, v_type, v_nullable
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column;
    IF v_count = 0 THEN
        SET v_sql = CONCAT('ALTER TABLE `', REPLACE(p_table, '`', '``'), '` ADD COLUMN `',
                           REPLACE(p_column, '`', '``'), '` ', p_definition);
        SET @feature9_sql = v_sql;
        PREPARE feature9_stmt FROM @feature9_sql;
        EXECUTE feature9_stmt;
        DEALLOCATE PREPARE feature9_stmt;
        SELECT COLUMN_TYPE, IS_NULLABLE INTO v_type, v_nullable
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column;
    END IF;
    IF v_type <> p_expected_type OR v_nullable <> p_expected_nullable THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing Feature #9 column has an unexpected definition; inspect it before recovery.';
    END IF;
END//

DROP PROCEDURE IF EXISTS feature9_ensure_index//
CREATE PROCEDURE feature9_ensure_index(
    IN p_table VARCHAR(64),
    IN p_name VARCHAR(64),
    IN p_columns VARCHAR(255),
    IN p_unique TINYINT
)
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_columns VARCHAR(255);
    DECLARE v_non_unique INT;
    DECLARE v_sql TEXT;
    SELECT COUNT(DISTINCT INDEX_NAME), GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX),
           MAX(NON_UNIQUE)
      INTO v_count, v_columns, v_non_unique
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_name;
    IF v_count = 0 THEN
        SET v_sql = CONCAT('ALTER TABLE `', REPLACE(p_table, '`', '``'), '` ADD ',
                           IF(p_unique = 1, 'UNIQUE ', ''), 'INDEX `',
                           REPLACE(p_name, '`', '``'), '` (', p_columns, ')');
        SET @feature9_sql = v_sql;
        PREPARE feature9_stmt FROM @feature9_sql;
        EXECUTE feature9_stmt;
        DEALLOCATE PREPARE feature9_stmt;
    ELSEIF v_columns <> p_columns OR v_non_unique <> IF(p_unique = 1, 0, 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing Feature #9 index has an unexpected definition; inspect it before recovery.';
    END IF;
END//

DROP PROCEDURE IF EXISTS feature9_ensure_fk//
CREATE PROCEDURE feature9_ensure_fk(
    IN p_table VARCHAR(64),
    IN p_name VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_reference_table VARCHAR(64),
    IN p_reference_column VARCHAR(64),
    IN p_delete_rule VARCHAR(16)
)
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_column VARCHAR(64);
    DECLARE v_reference_table VARCHAR(64);
    DECLARE v_reference_column VARCHAR(64);
    DECLARE v_delete_rule VARCHAR(16);
    DECLARE v_sql TEXT;
    SELECT COUNT(*), MAX(COLUMN_NAME), MAX(REFERENCED_TABLE_NAME),
           MAX(REFERENCED_COLUMN_NAME)
      INTO v_count, v_column, v_reference_table, v_reference_column
      FROM information_schema.KEY_COLUMN_USAGE
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table
       AND CONSTRAINT_NAME = p_name AND REFERENCED_TABLE_NAME IS NOT NULL;
    IF v_count > 0 THEN
        SELECT MAX(DELETE_RULE) INTO v_delete_rule
          FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table
           AND CONSTRAINT_NAME = p_name;
    END IF;
    IF v_count > 0 AND (
        v_column <> p_column OR v_reference_table <> p_reference_table
        OR v_reference_column <> p_reference_column OR v_delete_rule <> p_delete_rule
    ) THEN
        SET v_sql = CONCAT('ALTER TABLE `', REPLACE(p_table, '`', '``'),
                           '` DROP FOREIGN KEY `', REPLACE(p_name, '`', '``'), '`');
        SET @feature9_sql = v_sql;
        PREPARE feature9_stmt FROM @feature9_sql;
        EXECUTE feature9_stmt;
        DEALLOCATE PREPARE feature9_stmt;
        SET v_count = 0;
    END IF;
    IF v_count = 0 THEN
        SET v_sql = CONCAT('ALTER TABLE `', REPLACE(p_table, '`', '``'),
                           '` ADD CONSTRAINT `', REPLACE(p_name, '`', '``'),
                           '` FOREIGN KEY (`', REPLACE(p_column, '`', '``'),
                           '`) REFERENCES `', REPLACE(p_reference_table, '`', '``'),
                           '` (`', REPLACE(p_reference_column, '`', '``'),
                           '`) ON DELETE ', p_delete_rule);
        SET @feature9_sql = v_sql;
        PREPARE feature9_stmt FROM @feature9_sql;
        EXECUTE feature9_stmt;
        DEALLOCATE PREPARE feature9_stmt;
    END IF;
END//

DELIMITER ;

CALL feature9_assert_prerequisites();

ALTER TABLE bookings
    MODIFY COLUMN status ENUM(
        'pending', 'confirmed', 'rejected', 'cancelled', 'completed',
        'awaiting_member', 'awaiting_payment', 'expired', 'no_show'
    ) NOT NULL DEFAULT 'pending';

CALL feature9_ensure_column('bookings', 'cancellation_reason', 'VARCHAR(1000) DEFAULT NULL', 'varchar(1000)', 'YES');
CALL feature9_ensure_column('bookings', 'cancelled_by_user_id', 'INT(11) DEFAULT NULL', 'int(11)', 'YES');
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS cancelled_by_role ENUM('member', 'trainer', 'admin', 'system') DEFAULT NULL;
ALTER TABLE bookings
    MODIFY COLUMN cancelled_by_role ENUM('member', 'trainer', 'admin', 'system') DEFAULT NULL;
CALL feature9_ensure_column('bookings', 'cancelled_at', 'DATETIME DEFAULT NULL', 'datetime', 'YES');
CALL feature9_ensure_column('bookings', 'refund_eligible', 'TINYINT(1) NOT NULL DEFAULT 0', 'tinyint(1)', 'NO');
CALL feature9_ensure_column('bookings', 'credit_restoration_decision', 'TINYINT(1) NOT NULL DEFAULT 0', 'tinyint(1)', 'NO');
CALL feature9_ensure_column('bookings', 'response_deadline', 'DATETIME DEFAULT NULL', 'datetime', 'YES');

UPDATE bookings
   SET response_deadline = DATE_ADD(created_at, INTERVAL 24 HOUR)
 WHERE status = 'pending' AND response_deadline IS NULL;

CREATE TABLE IF NOT EXISTS booking_cancellation_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT(11) NOT NULL,
    previous_status ENUM('pending', 'confirmed', 'rejected', 'cancelled', 'completed', 'awaiting_member', 'awaiting_payment', 'expired', 'no_show') NOT NULL,
    new_status ENUM('pending', 'confirmed', 'rejected', 'cancelled', 'completed', 'awaiting_member', 'awaiting_payment', 'expired', 'no_show') NOT NULL,
    initiating_user_id INT(11) DEFAULT NULL,
    initiating_role ENUM('member', 'trainer', 'admin', 'system') NOT NULL,
    cancellation_reason VARCHAR(1000) DEFAULT NULL,
    cancellation_category ENUM('member_cancel', 'late_member_cancel', 'awaiting_payment_cancel', 'trainer_cancel', 'admin_cancel', 'rejected', 'no_show', 'staff_timeout') NOT NULL,
    cancelled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    refund_eligible TINYINT(1) NOT NULL DEFAULT 0,
    credit_restoration_decision TINYINT(1) NOT NULL DEFAULT 0,
    related_payment_id INT(11) DEFAULT NULL,
    related_credit_id BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS booking_refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT(11) NOT NULL,
    payment_id INT(11) NOT NULL,
    member_id INT(11) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    status ENUM('pending', 'approved', 'processed', 'rejected') NOT NULL DEFAULT 'pending',
    request_reason VARCHAR(1000) DEFAULT NULL,
    created_by_user_id INT(11) DEFAULT NULL,
    approved_by_user_id INT(11) DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    processed_by_user_id INT(11) DEFAULT NULL,
    external_reference VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS booking_no_show_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT(11) NOT NULL,
    marked_by_user_id INT(11) NOT NULL,
    marked_by_role ENUM('trainer', 'admin') NOT NULL,
    no_show_reason VARCHAR(1000) DEFAULT NULL,
    marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE booking_cancellation_history
    MODIFY COLUMN initiating_role ENUM('member', 'trainer', 'admin', 'system') NOT NULL,
    MODIFY COLUMN cancellation_category ENUM(
        'member_cancel', 'late_member_cancel', 'awaiting_payment_cancel',
        'trainer_cancel', 'admin_cancel', 'rejected', 'no_show', 'staff_timeout'
    ) NOT NULL;
ALTER TABLE booking_refunds
    MODIFY COLUMN status ENUM('pending', 'approved', 'processed', 'rejected') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS approved_by_user_id INT(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS external_reference VARCHAR(100) DEFAULT NULL;

CALL feature9_ensure_column('booking_cancellation_history', 'booking_id', 'INT(11) NOT NULL', 'int(11)', 'NO');
CALL feature9_ensure_column('booking_cancellation_history', 'previous_status', "ENUM('pending','confirmed','rejected','cancelled','completed','awaiting_member','awaiting_payment','expired','no_show') NOT NULL", "enum('pending','confirmed','rejected','cancelled','completed','awaiting_member','awaiting_payment','expired','no_show')", 'NO');
CALL feature9_ensure_column('booking_cancellation_history', 'new_status', "ENUM('pending','confirmed','rejected','cancelled','completed','awaiting_member','awaiting_payment','expired','no_show') NOT NULL", "enum('pending','confirmed','rejected','cancelled','completed','awaiting_member','awaiting_payment','expired','no_show')", 'NO');
CALL feature9_ensure_column('booking_cancellation_history', 'initiating_user_id', 'INT(11) DEFAULT NULL', 'int(11)', 'YES');
CALL feature9_ensure_column('booking_cancellation_history', 'initiating_role', "ENUM('member','trainer','admin','system') NOT NULL", "enum('member','trainer','admin','system')", 'NO');
CALL feature9_ensure_column('booking_cancellation_history', 'cancellation_reason', 'VARCHAR(1000) DEFAULT NULL', 'varchar(1000)', 'YES');
CALL feature9_ensure_column('booking_cancellation_history', 'cancellation_category', "ENUM('member_cancel','late_member_cancel','awaiting_payment_cancel','trainer_cancel','admin_cancel','rejected','no_show','staff_timeout') NOT NULL", "enum('member_cancel','late_member_cancel','awaiting_payment_cancel','trainer_cancel','admin_cancel','rejected','no_show','staff_timeout')", 'NO');
CALL feature9_ensure_column('booking_cancellation_history', 'cancelled_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', 'NO');
CALL feature9_ensure_column('booking_cancellation_history', 'refund_eligible', 'TINYINT(1) NOT NULL DEFAULT 0', 'tinyint(1)', 'NO');
CALL feature9_ensure_column('booking_cancellation_history', 'credit_restoration_decision', 'TINYINT(1) NOT NULL DEFAULT 0', 'tinyint(1)', 'NO');
CALL feature9_ensure_column('booking_cancellation_history', 'related_payment_id', 'INT(11) DEFAULT NULL', 'int(11)', 'YES');
CALL feature9_ensure_column('booking_cancellation_history', 'related_credit_id', 'BIGINT UNSIGNED DEFAULT NULL', 'bigint(20) unsigned', 'YES');

CALL feature9_ensure_column('booking_refunds', 'booking_id', 'INT(11) NOT NULL', 'int(11)', 'NO');
CALL feature9_ensure_column('booking_refunds', 'payment_id', 'INT(11) NOT NULL', 'int(11)', 'NO');
CALL feature9_ensure_column('booking_refunds', 'member_id', 'INT(11) NOT NULL', 'int(11)', 'NO');
CALL feature9_ensure_column('booking_refunds', 'amount', 'DECIMAL(10, 2) NOT NULL', 'decimal(10,2)', 'NO');
CALL feature9_ensure_column('booking_refunds', 'payment_method', 'VARCHAR(50) NOT NULL', 'varchar(50)', 'NO');
CALL feature9_ensure_column('booking_refunds', 'status', "ENUM('pending','approved','processed','rejected') NOT NULL DEFAULT 'pending'", "enum('pending','approved','processed','rejected')", 'NO');
CALL feature9_ensure_column('booking_refunds', 'request_reason', 'VARCHAR(1000) DEFAULT NULL', 'varchar(1000)', 'YES');
CALL feature9_ensure_column('booking_refunds', 'created_by_user_id', 'INT(11) DEFAULT NULL', 'int(11)', 'YES');
CALL feature9_ensure_column('booking_refunds', 'approved_by_user_id', 'INT(11) DEFAULT NULL', 'int(11)', 'YES');
CALL feature9_ensure_column('booking_refunds', 'approved_at', 'DATETIME DEFAULT NULL', 'datetime', 'YES');
CALL feature9_ensure_column('booking_refunds', 'processed_by_user_id', 'INT(11) DEFAULT NULL', 'int(11)', 'YES');
CALL feature9_ensure_column('booking_refunds', 'external_reference', 'VARCHAR(100) DEFAULT NULL', 'varchar(100)', 'YES');
CALL feature9_ensure_column('booking_refunds', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', 'NO');
CALL feature9_ensure_column('booking_refunds', 'processed_at', 'DATETIME DEFAULT NULL', 'datetime', 'YES');

CALL feature9_ensure_column('booking_no_show_records', 'booking_id', 'INT(11) NOT NULL', 'int(11)', 'NO');
CALL feature9_ensure_column('booking_no_show_records', 'marked_by_user_id', 'INT(11) NOT NULL', 'int(11)', 'NO');
CALL feature9_ensure_column('booking_no_show_records', 'marked_by_role', "ENUM('trainer','admin') NOT NULL", "enum('trainer','admin')", 'NO');
CALL feature9_ensure_column('booking_no_show_records', 'no_show_reason', 'VARCHAR(1000) DEFAULT NULL', 'varchar(1000)', 'YES');
CALL feature9_ensure_column('booking_no_show_records', 'marked_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'datetime', 'NO');

CALL feature9_ensure_index('bookings', 'idx_bookings_cancelled_at', 'cancelled_at', 0);
CALL feature9_ensure_index('bookings', 'idx_bookings_cancelled_user', 'cancelled_by_user_id', 0);
CALL feature9_ensure_index('bookings', 'idx_bookings_response_deadline', 'status,response_deadline', 0);
CALL feature9_ensure_index('booking_cancellation_history', 'idx_booking_cancellation_history_booking', 'booking_id,cancelled_at', 0);
CALL feature9_ensure_index('booking_cancellation_history', 'idx_booking_cancellation_history_user', 'initiating_user_id', 0);
CALL feature9_ensure_index('booking_cancellation_history', 'idx_booking_cancellation_history_payment', 'related_payment_id', 0);
CALL feature9_ensure_index('booking_cancellation_history', 'idx_booking_cancellation_history_credit', 'related_credit_id', 0);
CALL feature9_ensure_index('booking_refunds', 'uq_booking_refund_payment', 'payment_id', 1);
CALL feature9_ensure_index('booking_refunds', 'idx_booking_refund_booking', 'booking_id', 0);
CALL feature9_ensure_index('booking_refunds', 'idx_booking_refund_member', 'member_id', 0);
CALL feature9_ensure_index('booking_no_show_records', 'uq_booking_no_show_booking', 'booking_id', 1);
CALL feature9_ensure_index('booking_no_show_records', 'idx_booking_no_show_marked_by', 'marked_by_user_id', 0);

CALL feature9_ensure_fk('bookings', 'fk_bookings_cancelled_by', 'cancelled_by_user_id', 'users', 'id', 'SET NULL');
CALL feature9_ensure_fk('booking_cancellation_history', 'fk_booking_cancellation_history_booking', 'booking_id', 'bookings', 'id', 'RESTRICT');
CALL feature9_ensure_fk('booking_cancellation_history', 'fk_booking_cancellation_history_user', 'initiating_user_id', 'users', 'id', 'SET NULL');
CALL feature9_ensure_fk('booking_cancellation_history', 'fk_booking_cancellation_history_payment', 'related_payment_id', 'payments', 'id', 'RESTRICT');
CALL feature9_ensure_fk('booking_cancellation_history', 'fk_booking_cancellation_history_credit', 'related_credit_id', 'subscription_booking_credits', 'id', 'RESTRICT');
CALL feature9_ensure_fk('booking_refunds', 'fk_booking_refund_booking', 'booking_id', 'bookings', 'id', 'RESTRICT');
CALL feature9_ensure_fk('booking_refunds', 'fk_booking_refund_payment', 'payment_id', 'payments', 'id', 'RESTRICT');
CALL feature9_ensure_fk('booking_refunds', 'fk_booking_refund_member', 'member_id', 'members', 'id', 'RESTRICT');
CALL feature9_ensure_fk('booking_refunds', 'fk_booking_refund_creator', 'created_by_user_id', 'users', 'id', 'SET NULL');
CALL feature9_ensure_fk('booking_refunds', 'fk_booking_refund_approver', 'approved_by_user_id', 'users', 'id', 'SET NULL');
CALL feature9_ensure_fk('booking_refunds', 'fk_booking_refund_processor', 'processed_by_user_id', 'users', 'id', 'SET NULL');
CALL feature9_ensure_fk('booking_no_show_records', 'fk_booking_no_show_booking', 'booking_id', 'bookings', 'id', 'RESTRICT');
CALL feature9_ensure_fk('booking_no_show_records', 'fk_booking_no_show_user', 'marked_by_user_id', 'users', 'id', 'RESTRICT');

DROP PROCEDURE IF EXISTS feature9_assert_prerequisites;
DROP PROCEDURE IF EXISTS feature9_ensure_column;
DROP PROCEDURE IF EXISTS feature9_ensure_index;
DROP PROCEDURE IF EXISTS feature9_ensure_fk;
