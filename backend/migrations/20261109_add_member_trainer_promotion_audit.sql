USE gym_management;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE member_trainer_promotion_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_user_id INT(11) NOT NULL,
    member_id INT(11) NOT NULL,
    trainer_id INT(11) NOT NULL,
    previous_role ENUM('admin', 'trainer', 'member') NOT NULL,
    new_role ENUM('admin', 'trainer', 'member') NOT NULL,
    admin_user_id INT(11) NOT NULL,
    action_type ENUM('member_to_trainer_promotion') NOT NULL,
    promoted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_member_trainer_promotion_target_action (target_user_id, action_type),
    KEY idx_member_trainer_promotion_admin_time (admin_user_id, promoted_at),
    KEY idx_member_trainer_promotion_member_time (member_id, promoted_at),
    CONSTRAINT fk_member_trainer_promotion_user
        FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_member_trainer_promotion_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT,
    CONSTRAINT fk_member_trainer_promotion_trainer
        FOREIGN KEY (trainer_id) REFERENCES trainers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_member_trainer_promotion_admin
        FOREIGN KEY (admin_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
