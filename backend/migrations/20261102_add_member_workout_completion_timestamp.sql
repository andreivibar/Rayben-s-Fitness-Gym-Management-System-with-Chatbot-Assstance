USE gym_management;

ALTER TABLE member_workout_plans
    ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER status;

UPDATE member_workout_plans
SET completed_at = updated_at
WHERE status = 'completed'
  AND completed_at IS NULL;
