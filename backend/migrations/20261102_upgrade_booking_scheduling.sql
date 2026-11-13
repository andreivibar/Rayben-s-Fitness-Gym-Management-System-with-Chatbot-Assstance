USE gym_management;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

ALTER TABLE bookings
    MODIFY status ENUM('pending', 'confirmed', 'rejected', 'completed', 'cancelled') DEFAULT 'pending',
    ADD COLUMN duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60 AFTER session_type,
    ADD COLUMN created_by_user_id INT(11) DEFAULT NULL AFTER notes,
    ADD COLUMN updated_by_user_id INT(11) DEFAULT NULL AFTER created_by_user_id,
    ADD KEY idx_bookings_member_date_status (member_id, booking_date, status),
    ADD CONSTRAINT fk_bookings_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_bookings_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL;

UPDATE bookings
SET duration_minutes = GREATEST(
    1,
    TIMESTAMPDIFF(MINUTE, CONCAT(booking_date, ' ', start_time), CONCAT(booking_date, ' ', end_time))
);

CREATE TABLE booking_session_types (
    id INT(11) NOT NULL AUTO_INCREMENT,
    name VARCHAR(64) NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_session_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO booking_session_types (name, duration_minutes) VALUES
    ('Personal Training', 60),
    ('Fitness Coaching', 60),
    ('Weightlifting Guidance', 60),
    ('Cardio & Conditioning', 60);

CREATE TABLE gym_operating_hours (
    day_of_week VARCHAR(16) NOT NULL,
    is_open TINYINT(1) NOT NULL DEFAULT 0,
    opens_at TIME DEFAULT NULL,
    closes_at TIME DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE trainer_schedule_exceptions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    trainer_id INT(11) NOT NULL,
    exception_date DATE NOT NULL,
    exception_type ENUM('off', 'available', 'blocked') NOT NULL,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by_user_id INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_trainer_exception_date_status (trainer_id, exception_date, status),
    CONSTRAINT fk_trainer_schedule_exception_trainer FOREIGN KEY (trainer_id) REFERENCES trainers (id) ON DELETE CASCADE,
    CONSTRAINT fk_trainer_schedule_exception_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE booking_reschedule_requests (
    id INT(11) NOT NULL AUTO_INCREMENT,
    booking_id INT(11) NOT NULL,
    requested_date DATE NOT NULL,
    requested_start_time TIME NOT NULL,
    requested_end_time TIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    requested_by_user_id INT(11) NOT NULL,
    resolved_by_user_id INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_booking_reschedule_status (booking_id, status),
    CONSTRAINT fk_booking_reschedule_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_reschedule_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_reschedule_resolved_by FOREIGN KEY (resolved_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE notifications
    MODIFY reminder_days TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
    MODIFY membership_end DATE DEFAULT NULL,
    ADD COLUMN booking_id INT(11) DEFAULT NULL AFTER membership_end,
    ADD KEY idx_notifications_booking (booking_id),
    ADD CONSTRAINT fk_notifications_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE SET NULL;
