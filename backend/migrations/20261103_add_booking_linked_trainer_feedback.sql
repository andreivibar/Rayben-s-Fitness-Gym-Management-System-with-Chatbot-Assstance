ALTER TABLE member_feedback
  MODIFY attendance_id INT(11) NULL,
  ADD COLUMN booking_id INT(11) NULL AFTER attendance_id,
  ADD UNIQUE KEY uq_member_feedback_booking_category (booking_id, feedback_category),
  ADD KEY idx_member_feedback_booking_created (booking_id, created_at),
  ADD CONSTRAINT fk_member_feedback_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,
  ADD CONSTRAINT chk_member_feedback_source CHECK (
    (feedback_category = 'gym' AND attendance_id IS NOT NULL AND booking_id IS NULL)
    OR
    (feedback_category = 'trainer' AND (
      (attendance_id IS NOT NULL AND booking_id IS NULL)
      OR (attendance_id IS NULL AND booking_id IS NOT NULL)
    ))
  );
