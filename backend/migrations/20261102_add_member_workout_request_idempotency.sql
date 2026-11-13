USE gym_management;

ALTER TABLE member_workout_sessions
    ADD COLUMN request_key CHAR(36) DEFAULT NULL AFTER planned_workout_id,
    ADD UNIQUE KEY uq_member_workout_session_request (member_id, request_key);
