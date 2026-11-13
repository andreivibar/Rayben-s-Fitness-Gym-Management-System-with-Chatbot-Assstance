USE gym_management;

CREATE TABLE booking_session_items (
    id INT(11) NOT NULL AUTO_INCREMENT,
    booking_id INT(11) NOT NULL,
    session_type_id INT(11) DEFAULT NULL,
    session_name VARCHAR(64) NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_session_item_type (booking_id, session_type_id),
    KEY idx_booking_session_items_order (booking_id, sort_order, id),
    CONSTRAINT fk_booking_session_items_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_session_items_type
        FOREIGN KEY (session_type_id) REFERENCES booking_session_types (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO booking_session_items
    (booking_id, session_type_id, session_name, duration_minutes, sort_order)
SELECT b.id, s.id, COALESCE(NULLIF(b.session_type, ''), 'Legacy session'), b.duration_minutes, 0
FROM bookings b
LEFT JOIN booking_session_types s ON s.name = b.session_type;
