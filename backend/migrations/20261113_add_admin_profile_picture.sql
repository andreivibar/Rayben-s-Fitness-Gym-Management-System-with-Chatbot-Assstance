-- Add profile-picture storage for user accounts; existing rows remain NULL.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL;
