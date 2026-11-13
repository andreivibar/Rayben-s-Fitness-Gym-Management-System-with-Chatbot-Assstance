USE gym_management;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    booking_id INT DEFAULT NULL,
    recorded_by INT DEFAULT NULL,
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
    UNIQUE KEY uq_payments_reference_no (reference_no),
    UNIQUE KEY uq_payments_checkout_session (checkout_session_id),
    KEY idx_payments_member_date (member_id, payment_date),
    KEY idx_payments_status (status),
    KEY idx_payments_paymongo_payment (paymongo_payment_id),
    CONSTRAINT fk_payments_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
