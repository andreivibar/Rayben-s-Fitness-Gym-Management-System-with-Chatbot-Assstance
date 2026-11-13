USE gym_management;

ALTER TABLE member_workout_plans
    ADD COLUMN description TEXT DEFAULT NULL AFTER title;
