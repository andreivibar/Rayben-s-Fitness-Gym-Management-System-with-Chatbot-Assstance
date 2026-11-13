USE gym_management;

SET @add_schedule_type = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE trainer_availability ADD COLUMN schedule_type ENUM(''custom'', ''morning'', ''afternoon'') NOT NULL DEFAULT ''custom'' AFTER end_time',
        'SELECT 1')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'trainer_availability' AND column_name = 'schedule_type'
);
PREPARE trainer_schedule_stmt FROM @add_schedule_type;
EXECUTE trainer_schedule_stmt;
DEALLOCATE PREPARE trainer_schedule_stmt;

SET @add_night_shift = (
    SELECT IF(COLUMN_TYPE NOT LIKE '%night%',
        'ALTER TABLE trainer_availability MODIFY COLUMN schedule_type ENUM(''custom'', ''morning'', ''afternoon'', ''night'') NOT NULL DEFAULT ''custom''',
        'SELECT 1')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'trainer_availability' AND column_name = 'schedule_type'
);
PREPARE trainer_schedule_stmt FROM @add_night_shift;
EXECUTE trainer_schedule_stmt;
DEALLOCATE PREPARE trainer_schedule_stmt;

SET @add_lunch_start = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE trainer_availability ADD COLUMN lunch_start TIME NULL AFTER schedule_type',
        'SELECT 1')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'trainer_availability' AND column_name = 'lunch_start'
);
PREPARE trainer_schedule_stmt FROM @add_lunch_start;
EXECUTE trainer_schedule_stmt;
DEALLOCATE PREPARE trainer_schedule_stmt;

SET @add_lunch_end = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE trainer_availability ADD COLUMN lunch_end TIME NULL AFTER lunch_start',
        'SELECT 1')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'trainer_availability' AND column_name = 'lunch_end'
);
PREPARE trainer_schedule_stmt FROM @add_lunch_end;
EXECUTE trainer_schedule_stmt;
DEALLOCATE PREPARE trainer_schedule_stmt;

-- Existing availability rows remain custom schedules and their linked
-- attendance history is not changed.
-- Rollback:
-- ALTER TABLE trainer_availability MODIFY COLUMN schedule_type ENUM('custom', 'morning', 'afternoon') NOT NULL DEFAULT 'custom';
-- ALTER TABLE trainer_availability DROP COLUMN lunch_end, DROP COLUMN lunch_start, DROP COLUMN schedule_type;
