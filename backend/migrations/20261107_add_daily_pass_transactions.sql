USE gym_management;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE daily_pass_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT(11) DEFAULT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_phone VARCHAR(20) DEFAULT NULL,
    pass_code CHAR(32) NOT NULL,
    idempotency_key VARCHAR(64) NOT NULL,
    visit_date DATE NOT NULL,
    expires_at DATETIME NOT NULL,
    payment_method ENUM('cash', 'gcash') NOT NULL,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 100.00,
    payment_status ENUM('unpaid', 'pending', 'paid', 'rejected') NOT NULL DEFAULT 'pending',
    reference_no VARCHAR(100) DEFAULT NULL,
    transaction_source ENUM('online', 'counter') NOT NULL,
    proof_stored_name VARCHAR(80) DEFAULT NULL,
    proof_original_name VARCHAR(255) DEFAULT NULL,
    proof_mime_type VARCHAR(100) DEFAULT NULL,
    proof_file_size INT UNSIGNED DEFAULT NULL,
    recorded_by INT(11) DEFAULT NULL,
    verified_by INT(11) DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    checked_in_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_daily_pass_code (pass_code),
    UNIQUE KEY uq_daily_pass_idempotency (idempotency_key),
    UNIQUE KEY uq_daily_pass_reference (reference_no),
    KEY idx_daily_pass_date_status (visit_date, payment_status),
    KEY idx_daily_pass_member_date (member_id, visit_date),
    CONSTRAINT fk_daily_pass_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE SET NULL,
    CONSTRAINT fk_daily_pass_recorder
        FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_daily_pass_verifier
        FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_daily_pass_price CHECK (amount = 100.00),
    CONSTRAINT chk_daily_pass_expiry CHECK (expires_at > CONCAT(visit_date, ' 00:00:00'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
