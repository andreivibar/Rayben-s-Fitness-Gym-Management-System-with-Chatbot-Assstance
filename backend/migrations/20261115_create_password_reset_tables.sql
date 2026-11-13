-- Select the intended database before applying this migration.
SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE password_reset_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NOT NULL,
    verified_at DATETIME DEFAULT NULL,
    reset_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    reset_token_expires_at DATETIME DEFAULT NULL,
    consumed_at DATETIME DEFAULT NULL,
    reset_completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token_hash (reset_token_hash),
    KEY idx_password_reset_user_created (user_id, created_at),
    KEY idx_password_reset_expiration (expires_at),
    CONSTRAINT fk_password_reset_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE password_reset_rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at DATETIME NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_rate_scope_window (scope_type, scope_hash, window_started_at),
    KEY idx_password_reset_rate_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
