ALTER TABLE attendance_qr_token_uses
    ADD COLUMN attendance_id INT(11) NULL AFTER member_id,
    ADD KEY idx_attendance_qr_use_attendance (attendance_id),
    ADD CONSTRAINT fk_attendance_qr_use_attendance
        FOREIGN KEY (attendance_id) REFERENCES attendance(id)
        ON DELETE RESTRICT;

CREATE TABLE member_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attendance_id INT(11) NOT NULL,
    feedback_category ENUM('gym', 'trainer') NOT NULL,
    trainer_id INT(11) DEFAULT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comments VARCHAR(1000) DEFAULT NULL,
    suggestions VARCHAR(1000) DEFAULT NULL,
    is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
    is_hidden TINYINT(1) NOT NULL DEFAULT 0,
    moderation_reason VARCHAR(500) DEFAULT NULL,
    moderated_by_user_id INT(11) DEFAULT NULL,
    moderated_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_member_feedback_visit_category (attendance_id, feedback_category),
    KEY idx_member_feedback_trainer_created (trainer_id, created_at),
    KEY idx_member_feedback_category_created (feedback_category, created_at),
    KEY idx_member_feedback_hidden_created (is_hidden, created_at),
    CONSTRAINT fk_member_feedback_attendance
        FOREIGN KEY (attendance_id) REFERENCES attendance(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_member_feedback_trainer
        FOREIGN KEY (trainer_id) REFERENCES trainers(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_member_feedback_moderator
        FOREIGN KEY (moderated_by_user_id) REFERENCES users(id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_member_feedback_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT chk_member_feedback_trainer CHECK (
        (feedback_category = 'gym' AND trainer_id IS NULL)
        OR (feedback_category = 'trainer' AND trainer_id IS NOT NULL)
    ),
    CONSTRAINT chk_member_feedback_moderation CHECK (
        (is_hidden = 0 AND moderation_reason IS NULL AND moderated_by_user_id IS NULL AND moderated_at IS NULL)
        OR (is_hidden = 1 AND moderation_reason IS NOT NULL AND moderated_by_user_id IS NOT NULL AND moderated_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
