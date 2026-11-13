USE gym_management;

ALTER TABLE trainer_attendance
    MODIFY COLUMN availability_id INT(11) NULL,
    MODIFY COLUMN scheduled_start TIME NULL,
    MODIFY COLUMN scheduled_end TIME NULL;

CREATE TABLE IF NOT EXISTS attendance_qr_trainer_token_uses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    qr_token_id BIGINT UNSIGNED NOT NULL,
    trainer_id INT(11) NOT NULL,
    used_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_qr_token_trainer (qr_token_id, trainer_id),
    CONSTRAINT fk_attendance_qr_trainer_use_token
        FOREIGN KEY (qr_token_id) REFERENCES attendance_qr_tokens(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_attendance_qr_trainer_use_trainer
        FOREIGN KEY (trainer_id) REFERENCES trainers(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_qr_trainer_rate_limits (
    trainer_id INT(11) NOT NULL,
    window_start DATETIME NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (trainer_id, window_start),
    CONSTRAINT fk_attendance_qr_trainer_rate_trainer
        FOREIGN KEY (trainer_id) REFERENCES trainers(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
