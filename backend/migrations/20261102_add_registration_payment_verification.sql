USE gym_management;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

ALTER TABLE registration_requests
    ADD COLUMN member_id INT(11) DEFAULT NULL AFTER status,
    ADD COLUMN payment_status ENUM('not_required', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'not_required' AFTER member_id,
    ADD COLUMN discount_status ENUM('not_required', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'not_required' AFTER payment_status,
    ADD COLUMN payment_amount DECIMAL(10, 2) DEFAULT NULL AFTER discount_status,
    ADD COLUMN reviewed_by INT(11) DEFAULT NULL AFTER payment_amount,
    ADD KEY idx_registration_requests_member (member_id),
    ADD CONSTRAINT fk_registration_requests_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_registration_requests_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL;

CREATE TABLE registration_request_files (
    id INT(11) NOT NULL AUTO_INCREMENT,
    request_id INT(11) NOT NULL,
    document_type ENUM('payment_proof', 'discount_proof') NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    review_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(500) DEFAULT NULL,
    reviewed_by INT(11) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registration_request_document (request_id, document_type),
    KEY idx_registration_request_files_review (review_status, created_at),
    CONSTRAINT fk_registration_request_files_request
        FOREIGN KEY (request_id) REFERENCES registration_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_registration_request_files_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE payments
    MODIFY COLUMN payment_channel ENUM('paymongo', 'counter', 'gcash') NOT NULL DEFAULT 'counter',
    ADD COLUMN registration_request_id INT(11) DEFAULT NULL AFTER booking_id,
    ADD UNIQUE KEY uq_payments_registration_request (registration_request_id),
    ADD CONSTRAINT fk_payments_registration_request
        FOREIGN KEY (registration_request_id) REFERENCES registration_requests (id) ON DELETE SET NULL;

CREATE TABLE gym_payment_settings (
    id TINYINT UNSIGNED NOT NULL,
    gcash_account_name VARCHAR(120) DEFAULT NULL,
    gcash_account_number VARCHAR(32) DEFAULT NULL,
    updated_by INT(11) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_gym_payment_settings_singleton CHECK (id = 1),
    CONSTRAINT fk_gym_payment_settings_user
        FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO gym_payment_settings (id, gcash_account_name, gcash_account_number)
VALUES (1, NULL, NULL);
