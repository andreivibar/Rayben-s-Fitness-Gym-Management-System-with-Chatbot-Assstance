USE gym_management;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

ALTER TABLE members
    ADD COLUMN gym_access_status ENUM('allowed', 'restricted') NOT NULL DEFAULT 'allowed'
    AFTER account_status;

CREATE TABLE member_membership_access_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    admin_user_id INT(11) NOT NULL,
    previous_membership_type VARCHAR(50) NOT NULL,
    new_membership_type VARCHAR(50) NOT NULL,
    previous_member_category ENUM('regular', 'student', 'senior', 'pwd') NOT NULL,
    new_member_category ENUM('regular', 'student', 'senior', 'pwd') NOT NULL,
    previous_gym_access_status ENUM('allowed', 'restricted') NOT NULL,
    new_gym_access_status ENUM('allowed', 'restricted') NOT NULL,
    previous_membership_start DATE DEFAULT NULL,
    previous_membership_end DATE DEFAULT NULL,
    new_membership_start DATE DEFAULT NULL,
    new_membership_end DATE DEFAULT NULL,
    reason VARCHAR(500) DEFAULT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_member_membership_access_audit_member_changed (member_id, changed_at),
    KEY idx_member_membership_access_audit_admin_changed (admin_user_id, changed_at),
    CONSTRAINT fk_member_membership_access_audit_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT,
    CONSTRAINT fk_member_membership_access_audit_admin
        FOREIGN KEY (admin_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
