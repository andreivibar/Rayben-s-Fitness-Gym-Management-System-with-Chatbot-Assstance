ALTER TABLE users
    ADD COLUMN IF NOT EXISTS username VARCHAR(30) NULL AFTER full_name;

UPDATE users
SET username = CONCAT('user', id)
WHERE username IS NULL OR TRIM(username) = '';

ALTER TABLE users
    MODIFY username VARCHAR(30) NOT NULL;

CREATE UNIQUE INDEX uq_users_username ON users (username);

ALTER TABLE registration_requests
    ADD COLUMN IF NOT EXISTS username VARCHAR(30) NULL AFTER full_name;

UPDATE registration_requests
SET username = CONCAT('user', id)
WHERE username IS NULL OR TRIM(username) = '';

ALTER TABLE registration_requests
    MODIFY username VARCHAR(30) NOT NULL;

CREATE UNIQUE INDEX uq_registration_requests_username ON registration_requests (username);
