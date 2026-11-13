CREATE TABLE users (
    id INT(11) NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) DEFAULT NULL,
    last_name VARCHAR(50) NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    registration_status VARCHAR(20) NOT NULL DEFAULT 'active',
    account_status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active',
    auth_token VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_test_users_username (username),
    UNIQUE KEY uq_test_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE members (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) DEFAULT NULL,
    email VARCHAR(254) NOT NULL,
    account_status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active',
    membership_type VARCHAR(30) NOT NULL DEFAULT 'monthly',
    PRIMARY KEY (id),
    KEY idx_test_members_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE trainers (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    PRIMARY KEY (id),
    KEY idx_test_trainers_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE registration_requests (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) DEFAULT NULL,
    registration_source VARCHAR(20) DEFAULT NULL,
    membership_type VARCHAR(30) DEFAULT NULL,
    status VARCHAR(20) DEFAULT NULL,
    review_note TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    payment_status VARCHAR(20) DEFAULT NULL,
    username VARCHAR(30) DEFAULT NULL,
    email VARCHAR(254) DEFAULT NULL,
    password VARCHAR(255) DEFAULT NULL,
    registration_unique_username VARCHAR(30) DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE daily_pass_transactions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    transaction_source VARCHAR(20) DEFAULT NULL,
    payment_status VARCHAR(20) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_test_daily_pass_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
