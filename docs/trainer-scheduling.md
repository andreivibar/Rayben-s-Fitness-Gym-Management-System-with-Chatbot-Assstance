# Trainer Scheduling

## Schedule assignment

Administrators can open **Trainer Management → Assign Schedule**, select a trainer, and use **Add Shift** to create as many independent shift entries as needed. Each entry has its own day, Morning/Afternoon/Night category, manually selected start/end times, and optional lunch break. Multiple categories and multiple shifts may be assigned to the same trainer and day. Adding, editing, or removing one entry does not replace the trainer's other shifts. Shift categories do not apply preset times.

The optional initial availability fields on the create-trainer form use separate 12-hour hour, minute, and AM/PM controls. Configure categorized shifts from the trainer list after creating the account.

- The default lunch break is 12:00 PM–1:00 PM on every selected day, including Sunday. Administrators may disable it for a day; if enabled, it must fit within that day’s working hours.

The shift category, manually configured hours, and lunch interval are stored on each `trainer_availability` row. The lunch interval is displayed separately from work time and is automatically disabled in the editor when a shift cannot contain the full break. Trainer dashboards and profiles show every assigned shift read-only. Trainers can read only their own schedule; members cannot access schedule management.

## Database migration

The existing `trainer_availability` table already has an auto-increment primary key per shift and a non-unique `(trainer_id, day_of_week)` index, so it supports multiple shifts on the same day. No additional schema migration is required for multiple assignments. The earlier [`trainer_schedule_presets.sql`](../database/trainer_schedule_presets.sql) migration added shift categories and lunch columns; it does not impose a one-shift-per-day constraint.

The API creates, updates, and removes individual shifts. On edit it marks the old availability row inactive and inserts a replacement, preserving the original ID for any attendance history that references it. Removal marks a row inactive rather than deleting it. Attendance reports include inactive availability when an attendance record references it.

## Verification

After applying the migration:

1. Add several shifts for one trainer, including different categories on the same day and custom hours.
2. Verify adding one shift does not replace existing entries and overlapping shift times are rejected.
3. Edit and remove individual entries; verify saved AM/PM values and lunch settings after reopening.
4. Sign in as that trainer and verify every assigned shift appears read-only in the dashboard/profile.
5. Confirm a trainer or member cannot submit schedule changes directly to `trainer_availability.php`.
