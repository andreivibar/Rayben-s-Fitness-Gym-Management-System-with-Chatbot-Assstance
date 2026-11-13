-- Select the intended database before applying this migration.
SET NAMES utf8mb4;
SET time_zone = '+08:00';

ALTER TABLE users
    ADD COLUMN account_status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active'
        AFTER registration_status,
    ADD COLUMN created_by_admin_id INT(11) DEFAULT NULL
        AFTER account_status,
    ADD KEY idx_users_created_by_admin_id (created_by_admin_id),
    ADD CONSTRAINT fk_users_created_by_admin
        FOREIGN KEY (created_by_admin_id) REFERENCES users (id)
        ON DELETE SET NULL;
