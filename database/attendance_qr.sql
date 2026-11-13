USE gym_management;

CREATE TABLE IF NOT EXISTS attendance_qr_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    issued_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_qr_token_hash (token_hash),
    KEY idx_attendance_qr_token_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_qr_token_uses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    qr_token_id BIGINT UNSIGNED NOT NULL,
    member_id INT(11) NOT NULL,
    used_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_qr_token_member (qr_token_id, member_id),
    CONSTRAINT fk_attendance_qr_use_token
        FOREIGN KEY (qr_token_id) REFERENCES attendance_qr_tokens(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_attendance_qr_use_member
        FOREIGN KEY (member_id) REFERENCES members(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_qr_rate_limits (
    member_id INT(11) NOT NULL,
    window_start DATETIME NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (member_id, window_start),
    CONSTRAINT fk_attendance_qr_rate_member
        FOREIGN KEY (member_id) REFERENCES members(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback (only removes the new QR support tables):
-- DROP TABLE attendance_qr_rate_limits;
-- DROP TABLE attendance_qr_token_uses;
-- DROP TABLE attendance_qr_tokens;
