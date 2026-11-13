ALTER TABLE members
    ADD COLUMN IF NOT EXISTS gender ENUM('Male', 'Female', 'Prefer not to say') NULL AFTER date_of_birth;

ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS gender ENUM('Male', 'Female', 'Prefer not to say') NULL AFTER date_of_birth;
