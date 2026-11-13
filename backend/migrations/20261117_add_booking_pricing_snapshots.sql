USE gym_management;

ALTER TABLE booking_session_types
    ADD COLUMN service_category ENUM('personal_training', 'other') NOT NULL DEFAULT 'other' AFTER duration_minutes;

UPDATE booking_session_types
SET service_category = 'personal_training'
WHERE name = 'Personal Training';

CREATE TABLE booking_session_prices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_type_id INT(11) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    effective_from DATETIME NOT NULL,
    retired_at DATETIME DEFAULT NULL,
    retired_by INT(11) DEFAULT NULL,
    created_by INT(11) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_price_effective (session_type_id, effective_from),
    KEY idx_booking_price_current (session_type_id, effective_from, retired_at),
    CONSTRAINT fk_booking_price_type
        FOREIGN KEY (session_type_id) REFERENCES booking_session_types (id) ON DELETE RESTRICT,
    CONSTRAINT fk_booking_price_creator
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_booking_price_retire_user
        FOREIGN KEY (retired_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_booking_price_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE bookings
    ADD COLUMN subtotal DECIMAL(10, 2) DEFAULT NULL AFTER duration_minutes,
    ADD COLUMN credit_amount DECIMAL(10, 2) DEFAULT NULL AFTER subtotal,
    ADD COLUMN amount_due DECIMAL(10, 2) DEFAULT NULL AFTER credit_amount,
    ADD COLUMN price_currency CHAR(3) DEFAULT NULL AFTER amount_due,
    ADD COLUMN price_snapshot_at DATETIME DEFAULT NULL AFTER price_currency;

ALTER TABLE booking_session_items
    ADD COLUMN price_version_id BIGINT UNSIGNED DEFAULT NULL AFTER duration_minutes,
    ADD COLUMN unit_price DECIMAL(10, 2) DEFAULT NULL AFTER price_version_id,
    ADD COLUMN line_amount DECIMAL(10, 2) DEFAULT NULL AFTER unit_price,
    ADD COLUMN currency CHAR(3) DEFAULT NULL AFTER line_amount,
    ADD CONSTRAINT fk_booking_item_price_version
        FOREIGN KEY (price_version_id) REFERENCES booking_session_prices (id) ON DELETE RESTRICT;
