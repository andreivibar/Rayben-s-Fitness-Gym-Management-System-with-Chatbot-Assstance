USE gym_management;

CREATE TABLE subscription_booking_credit_grants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    payment_id INT(11) NOT NULL,
    subscription_period_id BIGINT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    credits_granted TINYINT UNSIGNED NOT NULL DEFAULT 2,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_credit_grant_payment (payment_id),
    UNIQUE KEY uq_booking_credit_grant_period (subscription_period_id),
    KEY idx_booking_credit_grant_member_dates (member_id, starts_on, ends_on),
    CONSTRAINT fk_booking_credit_grant_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT,
    CONSTRAINT fk_booking_credit_grant_payment
        FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_booking_credit_grant_period
        FOREIGN KEY (subscription_period_id) REFERENCES membership_subscription_periods (id) ON DELETE RESTRICT,
    CONSTRAINT chk_booking_credit_grant_count CHECK (credits_granted = 2),
    CONSTRAINT chk_booking_credit_grant_dates CHECK (ends_on >= starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE subscription_booking_credits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    grant_id BIGINT UNSIGNED NOT NULL,
    credit_number TINYINT UNSIGNED NOT NULL,
    status ENUM('available', 'reserved', 'consumed', 'expired') NOT NULL DEFAULT 'available',
    applied_booking_id INT(11) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscription_booking_credit_number (grant_id, credit_number),
    UNIQUE KEY uq_subscription_booking_credit_booking (applied_booking_id),
    KEY idx_subscription_booking_credit_available (status, grant_id),
    CONSTRAINT fk_subscription_booking_credit_grant
        FOREIGN KEY (grant_id) REFERENCES subscription_booking_credit_grants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_booking_credit_booking
        FOREIGN KEY (applied_booking_id) REFERENCES bookings (id) ON DELETE RESTRICT,
    CONSTRAINT chk_subscription_booking_credit_number CHECK (credit_number IN (1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE subscription_booking_credit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credit_id BIGINT UNSIGNED NOT NULL,
    booking_id INT(11) DEFAULT NULL,
    event_type ENUM('granted', 'reserved', 'consumed', 'released', 'expired') NOT NULL,
    event_key VARCHAR(100) NOT NULL,
    actor_user_id INT(11) DEFAULT NULL,
    details VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscription_booking_credit_event_key (event_key),
    KEY idx_subscription_booking_credit_event (credit_id, created_at),
    KEY idx_subscription_booking_credit_event_booking (booking_id, event_type),
    CONSTRAINT fk_subscription_booking_credit_event_credit
        FOREIGN KEY (credit_id) REFERENCES subscription_booking_credits (id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_booking_credit_event_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE RESTRICT,
    CONSTRAINT fk_subscription_booking_credit_event_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
