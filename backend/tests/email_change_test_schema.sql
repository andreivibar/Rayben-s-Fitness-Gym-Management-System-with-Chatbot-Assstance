CREATE TABLE users (
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
    account_status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active',
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

CREATE TABLE members (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    account_status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active',
    membership_type VARCHAR(30) NOT NULL DEFAULT 'monthly',
    PRIMARY KEY (id),
    UNIQUE KEY uq_test_members_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE trainers (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    PRIMARY KEY (id),
    UNIQUE KEY uq_test_trainers_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE registration_requests (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) DEFAULT NULL,
    registration_source VARCHAR(20) DEFAULT NULL,
    membership_type VARCHAR(30) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    review_note TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    payment_status VARCHAR(20) DEFAULT NULL,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    password VARCHAR(255) DEFAULT NULL,
    registration_unique_username VARCHAR(30)
        GENERATED ALWAYS AS (
            CASE WHEN status = 'rejected' THEN NULL ELSE LOWER(username) END
        ) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registration_requests_active_username (registration_unique_username),
    KEY idx_registration_requests_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE daily_pass_transactions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    transaction_source VARCHAR(20) DEFAULT NULL,
    payment_status VARCHAR(20) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_test_daily_pass_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE payments (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE membership_subscription_periods (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE bookings (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    booking_code VARCHAR(32) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE attendance (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    attendance_date DATE NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
