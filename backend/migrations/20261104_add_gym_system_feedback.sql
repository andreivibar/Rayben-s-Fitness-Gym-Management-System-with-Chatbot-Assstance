CREATE TABLE gym_system_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    feedback_date DATE NOT NULL,
    overall_rating TINYINT UNSIGNED DEFAULT NULL,
    overall_comments VARCHAR(1000) DEFAULT NULL,
    facilities_rating TINYINT UNSIGNED DEFAULT NULL,
    facilities_comments VARCHAR(1000) DEFAULT NULL,
    services_rating TINYINT UNSIGNED DEFAULT NULL,
    services_comments VARCHAR(1000) DEFAULT NULL,
    system_rating TINYINT UNSIGNED DEFAULT NULL,
    system_comments VARCHAR(1000) DEFAULT NULL,
    is_hidden TINYINT(1) NOT NULL DEFAULT 0,
    moderation_reason VARCHAR(500) DEFAULT NULL,
    moderated_by_user_id INT(11) DEFAULT NULL,
    moderated_at DATETIME DEFAULT NULL,
    submitted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gym_system_feedback_member_date (member_id, feedback_date),
    KEY idx_gym_system_feedback_date_rating (feedback_date, overall_rating),
    KEY idx_gym_system_feedback_moderation (is_hidden, feedback_date),
    CONSTRAINT fk_gym_system_feedback_member
        FOREIGN KEY (member_id) REFERENCES members(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_gym_system_feedback_moderator
        FOREIGN KEY (moderated_by_user_id) REFERENCES users(id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_gym_system_feedback_ratings CHECK (
        (overall_rating IS NULL OR overall_rating BETWEEN 1 AND 5)
        AND (facilities_rating IS NULL OR facilities_rating BETWEEN 1 AND 5)
        AND (services_rating IS NULL OR services_rating BETWEEN 1 AND 5)
        AND (system_rating IS NULL OR system_rating BETWEEN 1 AND 5)
    ),
    CONSTRAINT chk_gym_system_feedback_content CHECK (
        overall_rating IS NOT NULL OR NULLIF(TRIM(overall_comments), '') IS NOT NULL
        OR facilities_rating IS NOT NULL OR NULLIF(TRIM(facilities_comments), '') IS NOT NULL
        OR services_rating IS NOT NULL OR NULLIF(TRIM(services_comments), '') IS NOT NULL
        OR system_rating IS NOT NULL OR NULLIF(TRIM(system_comments), '') IS NOT NULL
    ),
    CONSTRAINT chk_gym_system_feedback_moderation CHECK (
        (is_hidden = 0 AND moderation_reason IS NULL AND moderated_by_user_id IS NULL AND moderated_at IS NULL)
        OR (is_hidden = 1 AND moderation_reason IS NOT NULL AND moderated_by_user_id IS NOT NULL AND moderated_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
