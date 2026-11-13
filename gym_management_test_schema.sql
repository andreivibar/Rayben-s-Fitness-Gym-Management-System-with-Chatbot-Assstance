-- Fresh schema for the Smart Gym Management System.
-- This file creates missing objects only; it does not drop, alter, or seed data.
-- Import only into an empty gym_management_test database.

CREATE DATABASE IF NOT EXISTS gym_management_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE gym_management_test;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE IF NOT EXISTS users (
    id INT(11) NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(50) DEFAULT NULL,
    middle_name VARCHAR(50) DEFAULT NULL,
    last_name VARCHAR(50) DEFAULT NULL,
    full_name VARCHAR(100) DEFAULT NULL,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'trainer', 'member') NOT NULL,
    registration_status ENUM('active', 'rejected') NOT NULL DEFAULT 'active',
    auth_token VARCHAR(255) DEFAULT NULL,
    registration_unique_username VARCHAR(30)
        GENERATED ALWAYS AS (
            CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(username) END
        ) STORED,
    registration_unique_email VARCHAR(100)
        GENERATED ALWAYS AS (
            CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(email) END
        ) STORED,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_registration_username (registration_unique_username),
    UNIQUE KEY uq_users_registration_email (registration_unique_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS members (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) DEFAULT NULL,
    member_code VARCHAR(50) NOT NULL,
    first_name VARCHAR(50) DEFAULT NULL,
    middle_name VARCHAR(50) DEFAULT NULL,
    last_name VARCHAR(50) DEFAULT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    date_of_birth DATE DEFAULT NULL,
    membership_type ENUM('monthly', 'walk-in') NOT NULL,
    member_category ENUM('regular', 'student', 'senior', 'pwd') NOT NULL DEFAULT 'regular',
    membership_start DATE DEFAULT NULL,
    membership_end DATE DEFAULT NULL,
    status ENUM('active', 'expired', 'inactive') DEFAULT 'active',
    account_status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    profile_picture VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_members_member_code (member_code),
    UNIQUE KEY uq_members_user_id (user_id),
    KEY idx_members_email (email),
    CONSTRAINT fk_member_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS trainers (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    trainer_code VARCHAR(20) NOT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    profile_picture VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trainers_trainer_code (trainer_code),
    KEY idx_trainers_user_id (user_id),
    CONSTRAINT fk_trainer_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS trainer_availability (
    id INT(11) NOT NULL AUTO_INCREMENT,
    trainer_id INT(11) NOT NULL,
    day_of_week VARCHAR(16) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    schedule_type ENUM('custom', 'morning', 'afternoon', 'night') NOT NULL DEFAULT 'custom',
    lunch_start TIME DEFAULT NULL,
    lunch_end TIME DEFAULT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_trainer_day (trainer_id, day_of_week),
    KEY idx_trainer_status (trainer_id, status),
    CONSTRAINT fk_trainer_availability_trainer
        FOREIGN KEY (trainer_id) REFERENCES trainers (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS bookings (
    id INT(11) NOT NULL AUTO_INCREMENT,
    booking_code VARCHAR(32) NOT NULL,
    member_id INT(11) NOT NULL,
    trainer_id INT(11) DEFAULT NULL,
    booking_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    session_type VARCHAR(64) DEFAULT 'Personal Training',
    notes TEXT DEFAULT NULL,
    cancellation_reason VARCHAR(1000) DEFAULT NULL,
    cancelled_by_user_id INT(11) DEFAULT NULL,
    cancelled_by_role ENUM('member', 'trainer', 'admin', 'system') DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    response_deadline DATETIME DEFAULT NULL,
    refund_eligible TINYINT(1) NOT NULL DEFAULT 0,
    credit_restoration_decision TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending', 'confirmed', 'rejected', 'cancelled', 'completed', 'awaiting_member', 'awaiting_payment', 'expired', 'no_show') NOT NULL DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bookings_booking_code (booking_code),
    KEY idx_bookings_member (member_id),
    KEY idx_bookings_trainer_date_status (trainer_id, booking_date, status),
    KEY idx_bookings_cancelled_at (cancelled_at),
    KEY idx_bookings_cancelled_user (cancelled_by_user_id),
    CONSTRAINT fk_bookings_member
        FOREIGN KEY (member_id) REFERENCES members (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_bookings_trainer
        FOREIGN KEY (trainer_id) REFERENCES trainers (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bookings_cancelled_by
        FOREIGN KEY (cancelled_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    attendance_date DATE NOT NULL,
    time_in TIME NOT NULL,
    time_out TIME DEFAULT NULL,
    checkout_type ENUM('manual', 'automatic') NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attendance_member (member_id),
    KEY idx_attendance_date_checkout (attendance_date, time_out),
    CONSTRAINT fk_attendance_member
        FOREIGN KEY (member_id) REFERENCES members (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS trainer_attendance (
    id INT(11) NOT NULL AUTO_INCREMENT,
    trainer_id INT(11) NOT NULL,
    availability_id INT(11) DEFAULT NULL,
    attendance_date DATE NOT NULL,
    scheduled_start TIME DEFAULT NULL,
    scheduled_end TIME DEFAULT NULL,
    time_in TIME DEFAULT NULL,
    time_out TIME DEFAULT NULL,
    status ENUM('scheduled', 'present', 'late', 'absent') NOT NULL DEFAULT 'scheduled',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_trainer_attendance_shift (trainer_id, availability_id, attendance_date),
    KEY idx_trainer_attendance_availability (availability_id),
    KEY idx_trainer_attendance_date (attendance_date),
    KEY idx_trainer_attendance_status (status),
    CONSTRAINT fk_trainer_attendance_availability
        FOREIGN KEY (availability_id) REFERENCES trainer_availability (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_trainer_attendance_trainer
        FOREIGN KEY (trainer_id) REFERENCES trainers (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS registration_requests (
    id INT(11) NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(50) DEFAULT NULL,
    middle_name VARCHAR(50) DEFAULT NULL,
    last_name VARCHAR(50) DEFAULT NULL,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    date_of_birth DATE DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    membership_type ENUM('monthly', 'walk-in') NOT NULL,
    member_category ENUM('regular', 'student', 'senior', 'pwd') DEFAULT 'regular',
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    registration_unique_username VARCHAR(30)
        GENERATED ALWAYS AS (
            CASE WHEN status = 'rejected' THEN NULL ELSE LOWER(username) END
        ) STORED,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registration_requests_active_username (registration_unique_username),
    KEY idx_registration_requests_email (email),
    KEY idx_registration_requests_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS payments (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    booking_id INT(11) DEFAULT NULL,
    recorded_by INT(11) DEFAULT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_type ENUM('monthly_subscription', 'walk_in_fee', 'training_session') NOT NULL,
    status ENUM('paid', 'pending', 'failed', 'expired') NOT NULL DEFAULT 'pending',
    payment_date DATE NOT NULL,
    reference_no VARCHAR(100) DEFAULT NULL,
    checkout_session_id VARCHAR(128) DEFAULT NULL,
    paymongo_payment_id VARCHAR(128) DEFAULT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    payment_channel ENUM('paymongo', 'counter') NOT NULL DEFAULT 'counter',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_reference_no (reference_no),
    UNIQUE KEY uq_payments_checkout_session (checkout_session_id),
    KEY idx_payments_member_date (member_id, payment_date),
    KEY idx_payments_status (status),
    KEY idx_payments_booking (booking_id),
    KEY idx_payments_recorded_by (recorded_by),
    KEY idx_payments_paymongo_payment (paymongo_payment_id),
    CONSTRAINT fk_payments_member
        FOREIGN KEY (member_id) REFERENCES members (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_payments_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_payments_recorded_by
        FOREIGN KEY (recorded_by) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance_qr_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    issued_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_qr_token_hash (token_hash),
    KEY idx_attendance_qr_token_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance_qr_token_uses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    qr_token_id BIGINT UNSIGNED NOT NULL,
    member_id INT(11) NOT NULL,
    used_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_qr_token_member (qr_token_id, member_id),
    CONSTRAINT fk_attendance_qr_use_token
        FOREIGN KEY (qr_token_id) REFERENCES attendance_qr_tokens (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_attendance_qr_use_member
        FOREIGN KEY (member_id) REFERENCES members (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance_qr_rate_limits (
    member_id INT(11) NOT NULL,
    window_start DATETIME NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (member_id, window_start),
    CONSTRAINT fk_attendance_qr_rate_member
        FOREIGN KEY (member_id) REFERENCES members (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance_qr_trainer_token_uses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    qr_token_id BIGINT UNSIGNED NOT NULL,
    trainer_id INT(11) NOT NULL,
    used_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_qr_token_trainer (qr_token_id, trainer_id),
    CONSTRAINT fk_attendance_qr_trainer_use_token
        FOREIGN KEY (qr_token_id) REFERENCES attendance_qr_tokens (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_attendance_qr_trainer_use_trainer
        FOREIGN KEY (trainer_id) REFERENCES trainers (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance_qr_trainer_rate_limits (
    trainer_id INT(11) NOT NULL,
    window_start DATETIME NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (trainer_id, window_start),
    CONSTRAINT fk_attendance_qr_trainer_rate_trainer
        FOREIGN KEY (trainer_id) REFERENCES trainers (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
