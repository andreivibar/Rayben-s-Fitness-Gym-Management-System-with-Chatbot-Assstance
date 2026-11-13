USE gym_management;

ALTER TABLE members
    ADD COLUMN account_status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active'
    AFTER status;

UPDATE members
SET account_status = 'deactivated'
WHERE status = 'inactive';

-- Rollback (the legacy status column and all member/history records remain intact):
-- ALTER TABLE members DROP COLUMN account_status;
