USE gym_management;

ALTER TABLE bookings
    MODIFY COLUMN status ENUM(
        'pending', 'confirmed', 'rejected', 'cancelled', 'completed',
        'awaiting_member', 'awaiting_payment', 'expired'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN payment_deadline_at DATETIME DEFAULT NULL AFTER price_snapshot_at,
    ADD KEY idx_bookings_payment_expiry (status, payment_deadline_at);

ALTER TABLE payments
    MODIFY COLUMN payment_channel ENUM('paymongo', 'counter', 'gcash') NOT NULL DEFAULT 'counter',
    ADD KEY idx_payments_booking_status (booking_id, payment_type, status, verification_status);
