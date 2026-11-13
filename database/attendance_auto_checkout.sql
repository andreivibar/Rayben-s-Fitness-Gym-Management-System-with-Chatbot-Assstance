USE gym_management;

ALTER TABLE attendance
    ADD COLUMN checkout_type ENUM('manual', 'automatic') NOT NULL DEFAULT 'manual'
        AFTER time_out,
    ADD KEY idx_attendance_date_checkout (attendance_date, time_out);

-- Rollback (existing attendance records and timestamps remain intact):
-- ALTER TABLE attendance DROP INDEX idx_attendance_date_checkout;
-- ALTER TABLE attendance DROP COLUMN checkout_type;
