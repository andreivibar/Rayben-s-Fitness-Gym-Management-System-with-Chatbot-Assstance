USE gym_management;

CREATE TABLE booking_configuration (
    id TINYINT UNSIGNED NOT NULL,
    max_advance_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO booking_configuration (id, max_advance_days)
VALUES (1, 90);
