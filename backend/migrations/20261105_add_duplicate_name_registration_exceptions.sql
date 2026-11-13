USE gym_management;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE duplicate_name_registration_exceptions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) DEFAULT NULL,
    last_name VARCHAR(50) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(100) NOT NULL DEFAULT '',
    phone VARCHAR(20) NOT NULL,
    address VARCHAR(255) NOT NULL,
    date_of_birth DATE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    membership_type ENUM('monthly', 'daily_pass') NOT NULL,
    member_category ENUM('regular', 'student', 'senior', 'pwd') NOT NULL DEFAULT 'regular',
    membership_plan_id INT(11) DEFAULT NULL,
    duration_months SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    payment_method ENUM('cash', 'gcash') NOT NULL,
    payment_amount DECIMAL(10, 2) NOT NULL,
    submission_key_hash CHAR(64) NOT NULL,
    daily_pass_idempotency_key CHAR(64) DEFAULT NULL,
    payment_proof_stored_name VARCHAR(80) DEFAULT NULL,
    payment_proof_original_name VARCHAR(255) DEFAULT NULL,
    payment_proof_mime_type VARCHAR(100) DEFAULT NULL,
    payment_proof_file_size INT UNSIGNED DEFAULT NULL,
    discount_proof_stored_name VARCHAR(80) DEFAULT NULL,
    discount_proof_original_name VARCHAR(255) DEFAULT NULL,
    discount_proof_mime_type VARCHAR(100) DEFAULT NULL,
    discount_proof_file_size INT UNSIGNED DEFAULT NULL,
    status ENUM('pending', 'authorized', 'rejected') NOT NULL DEFAULT 'pending',
    authorized_by INT(11) DEFAULT NULL,
    authorized_at DATETIME DEFAULT NULL,
    authorization_reason VARCHAR(500) DEFAULT NULL,
    rejected_by INT(11) DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    rejection_reason VARCHAR(500) DEFAULT NULL,
    registration_request_id INT(11) DEFAULT NULL,
    member_id INT(11) DEFAULT NULL,
    daily_pass_transaction_id BIGINT(20) UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_duplicate_exception_submission_key (submission_key_hash),
    UNIQUE KEY uq_duplicate_exception_registration_request (registration_request_id),
    KEY idx_duplicate_exception_status_created (status, created_at),
    KEY idx_duplicate_exception_name (first_name, last_name),
    CONSTRAINT fk_duplicate_exception_plan
        FOREIGN KEY (membership_plan_id) REFERENCES membership_plans (id) ON DELETE RESTRICT,
    CONSTRAINT fk_duplicate_exception_authorizer
        FOREIGN KEY (authorized_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_duplicate_exception_rejector
        FOREIGN KEY (rejected_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_duplicate_exception_request
        FOREIGN KEY (registration_request_id) REFERENCES registration_requests (id) ON DELETE SET NULL,
    CONSTRAINT fk_duplicate_exception_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE SET NULL,
    CONSTRAINT fk_duplicate_exception_daily_pass
        FOREIGN KEY (daily_pass_transaction_id) REFERENCES daily_pass_transactions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
