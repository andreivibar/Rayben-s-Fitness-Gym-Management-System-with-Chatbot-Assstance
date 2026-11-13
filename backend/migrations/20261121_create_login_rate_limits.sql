-- Select the intended database before applying this migration.
SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE login_rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cooldown_until DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_login_rate_scope_hash (scope_type, scope_hash),
    KEY idx_login_rate_updated_at (updated_at),
    KEY idx_login_rate_cooldown_until (cooldown_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE login_rate_limit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_login_rate_event (scope_type, scope_hash, attempt_id),
    KEY idx_login_rate_event_window (scope_type, scope_hash, failed_at),
    KEY idx_login_rate_event_expiry (failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
