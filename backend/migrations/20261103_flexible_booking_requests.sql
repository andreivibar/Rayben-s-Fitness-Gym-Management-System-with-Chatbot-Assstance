USE gym_management;

ALTER TABLE bookings
    MODIFY status ENUM('pending', 'confirmed', 'rejected', 'cancelled', 'completed', 'awaiting_member') DEFAULT 'pending',
    ADD COLUMN rejection_reason VARCHAR(1000) DEFAULT NULL AFTER notes;

CREATE TABLE booking_alternative_proposals (
    id INT(11) NOT NULL AUTO_INCREMENT,
    booking_id INT(11) NOT NULL,
    original_date DATE NOT NULL,
    original_start_time TIME NOT NULL,
    original_end_time TIME NOT NULL,
    proposed_date DATE NOT NULL,
    proposed_start_time TIME NOT NULL,
    proposed_end_time TIME NOT NULL,
    trainer_note VARCHAR(1000) DEFAULT NULL,
    status ENUM('pending', 'accepted', 'declined', 'cancelled') NOT NULL DEFAULT 'pending',
    proposed_by_user_id INT(11) NOT NULL,
    responded_by_user_id INT(11) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_booking_alternative_booking_status (booking_id, status),
    CONSTRAINT fk_booking_alternative_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_alternative_proposed_by FOREIGN KEY (proposed_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_alternative_responded_by FOREIGN KEY (responded_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
