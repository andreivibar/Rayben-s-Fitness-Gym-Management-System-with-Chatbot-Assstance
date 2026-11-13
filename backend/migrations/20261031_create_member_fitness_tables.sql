USE gym_management;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE IF NOT EXISTS member_workout_weeks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    week_start DATE NOT NULL,
    weekly_goal TINYINT UNSIGNED NOT NULL DEFAULT 3,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_member_workout_week (member_id, week_start),
    CONSTRAINT chk_member_workout_weekly_goal CHECK (weekly_goal <= 14),
    CONSTRAINT fk_member_workout_week_member
        FOREIGN KEY (member_id) REFERENCES members (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS member_workout_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    week_id BIGINT UNSIGNED NOT NULL,
    workout_date DATE NOT NULL,
    title VARCHAR(100) NOT NULL,
    is_rest_day TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('planned', 'completed', 'partial', 'skipped') NOT NULL DEFAULT 'planned',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_member_workout_plan_week_date (week_id, workout_date),
    CONSTRAINT fk_member_workout_plan_week
        FOREIGN KEY (week_id) REFERENCES member_workout_weeks (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS member_workout_plan_exercises (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id BIGINT UNSIGNED NOT NULL,
    exercise_name VARCHAR(100) NOT NULL,
    target_sets TINYINT UNSIGNED NOT NULL,
    target_reps VARCHAR(40) NOT NULL,
    target_weight_kg DECIMAL(7,2) DEFAULT NULL,
    target_duration_minutes SMALLINT UNSIGNED DEFAULT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_member_workout_plan_exercises_order (plan_id, sort_order),
    CONSTRAINT fk_member_workout_plan_exercise_plan
        FOREIGN KEY (plan_id) REFERENCES member_workout_plans (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS member_workout_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    planned_workout_id BIGINT UNSIGNED DEFAULT NULL,
    workout_date DATE NOT NULL,
    status ENUM('completed', 'partial', 'skipped') NOT NULL,
    duration_minutes SMALLINT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_member_workout_session_plan (planned_workout_id),
    KEY idx_member_workout_session_date (member_id, workout_date),
    CONSTRAINT fk_member_workout_session_member
        FOREIGN KEY (member_id) REFERENCES members (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_member_workout_session_plan
        FOREIGN KEY (planned_workout_id) REFERENCES member_workout_plans (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS member_workout_exercise_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workout_session_id BIGINT UNSIGNED NOT NULL,
    planned_exercise_id BIGINT UNSIGNED DEFAULT NULL,
    exercise_name VARCHAR(100) NOT NULL,
    sets_completed TINYINT UNSIGNED DEFAULT NULL,
    reps_completed VARCHAR(40) DEFAULT NULL,
    actual_weight_kg DECIMAL(7,2) DEFAULT NULL,
    duration_minutes SMALLINT UNSIGNED DEFAULT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_member_workout_exercise_log_session (workout_session_id, sort_order),
    CONSTRAINT fk_member_workout_exercise_log_session
        FOREIGN KEY (workout_session_id) REFERENCES member_workout_sessions (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_member_workout_exercise_log_planned
        FOREIGN KEY (planned_exercise_id) REFERENCES member_workout_plan_exercises (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
