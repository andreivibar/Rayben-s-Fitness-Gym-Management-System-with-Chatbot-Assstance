USE gym_management;

CREATE TABLE IF NOT EXISTS trainer_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trainer_id INT NOT NULL,
    availability_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    scheduled_start TIME NOT NULL,
    scheduled_end TIME NOT NULL,
    time_in TIME DEFAULT NULL,
    time_out TIME DEFAULT NULL,
    status ENUM('scheduled', 'present', 'late', 'absent') NOT NULL DEFAULT 'scheduled',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_trainer_attendance_shift (
        trainer_id,
        availability_id,
        attendance_date
    ),

    KEY idx_trainer_attendance_date (attendance_date),
    KEY idx_trainer_attendance_status (status),

    CONSTRAINT fk_trainer_attendance_trainer
        FOREIGN KEY (trainer_id)
        REFERENCES trainers(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_trainer_attendance_availability
        FOREIGN KEY (availability_id)
        REFERENCES trainer_availability(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
