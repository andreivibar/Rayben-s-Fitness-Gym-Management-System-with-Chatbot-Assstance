# Booking and trainer scheduling

## Database update

The original schedule foundation is installed by [`20261102_upgrade_booking_scheduling.sql`](../backend/migrations/20261102_upgrade_booking_scheduling.sql). The flexible trainer-approved workflow additionally requires the forward-only [`20261103_flexible_booking_requests.sql`](../backend/migrations/20261103_flexible_booking_requests.sql). Back up `gym_management` and run the incremental migration once before deploying the updated APIs. It adds `awaiting_member`, an optional rejection reason, and a separate proposal-history table; it preserves existing bookings and does not reset records. Do not rerun either migration after success.

The migration seeds the four session types already present in the old booking form with a configurable 60-minute duration. Confirm or adjust those durations in **Admin → Bookings → Booking Settings**. No gym operating hours are assumed or seeded: configure each open day and hours there before accepting requests. Trainers' weekly shifts remain managed in **Trainer Management → Assign Schedule**. An optional incremental [`20261104_add_booking_advance_window.sql`](../backend/migrations/20261104_add_booking_advance_window.sql) migration adds the Admin-configurable booking horizon (1–365 days, default 90). Without that migration, booking validation continues to use the 90-day default.

Multiple sessions in one booking require the incremental [`20261105_add_booking_session_items.sql`](../backend/migrations/20261105_add_booking_session_items.sql) migration. It creates ordered session-item snapshots and backfills each existing booking with its current single session name and duration. Back up `gym_management`, apply this migration once, and only then deploy the multi-session API/UI. It preserves booking IDs, statuses, schedules, and proposal history.

## Availability rules

The server intersects configured gym operating hours with the selected trainer's weekly shift, removes lunch, dated blocks and confirmed reservations, and offers starts in 15-minute increments only when the full configured session duration fits. A date-specific **day off** suppresses regular shifts; **temporary working shift** adds an interval; **blocked period** removes an interval. Pending requests do not reserve a slot; confirmation checks confirmed overlaps while holding a trainer lock, so concurrent requests cannot both be confirmed. Rejected and cancelled bookings do not reserve a slot.

Members may request a preferred date/time without selecting a generated slot. Trainer shifts identify whether a request falls inside the trainer's normal shift; outside-shift requests can remain pending and require explicit trainer approval. Confirmed sessions must fit gym operating hours and must not overlap a confirmed session for either the trainer or member. A trainer's alternative time is stored separately and only becomes the booking time after the member accepts it and the server repeats these checks.

A booking can contain multiple distinct active session types. The server snapshots each selected type's name and duration in order, calculates the total duration itself (maximum 480 minutes), and treats the entire request as one continuous appointment. Trainer confirmation and alternative-time acceptance apply to all items together.

Booking creation, confirmation, and rescheduling enforce the advance-booking window and re-check the schedule inside a transaction while locking the trainer and member records. This serializes conflicting changes without altering existing booking ownership. Member cancellation is allowed before a pending or confirmed session starts. Member rescheduling requests do not move or release the original reservation until an Admin approves them.

All dates/times are interpreted in `Asia/Manila`. Past dates and times are rejected. The maximum advance period defaults to 90 days and can be changed in **Admin → Bookings → Booking Settings** after applying the optional booking-window migration.

## Session reminders

Lifecycle notifications appear in the existing in-app notification center. Automatic one-day reminders require a daily Windows Task Scheduler job that runs:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\Gym-Management-System\backend\scripts\booking_reminders.php
```

The script only creates reminders for confirmed sessions on the following local calendar day and is idempotent for each booking and recipient. Without this scheduled task, lifecycle notifications still work but upcoming-session reminders are not generated automatically.

## Cancellation, refunds, and staff response expiry

The Feature #9 migration [`20261120_add_booking_cancellation_refunds.sql`](../backend/migrations/20261120_add_booking_cancellation_refunds.sql) requires an explicitly selected target database containing the InnoDB `bookings`, `users`, `members`, `payments`, and `subscription_booking_credits` tables and their primary/relationship columns. It does not issue `USE` or select a database. Apply it only after the booking-payment lifecycle and subscription-credit schema are present. It checks column, index, and foreign-key definitions; its additive DDL can be rerun after an interruption. If a preflight reports a mismatched definition, inspect and reconcile it before retrying instead of forcing the migration.

The root fresh-schema scripts create the base booking table only. Cancellation/refund audit tables are created by the Feature #9 migration after their payment and credit dependencies exist. Apply both schema and required migrations only to a newly created disposable database when validating locally; never point validation commands at `gym_management` or `gym_management_test`.

Run [`expire_booking_payments.php`](../backend/scripts/expire_booking_payments.php) from the scheduled task to process both unpaid-payment deadlines and unanswered initial booking requests. The latter use a separate 24-hour `response_deadline`; `awaiting_member` alternative-time proposals are not expired by this rule. Repeated execution is idempotent. Eligible refunds are recorded as requests; Admins must approve, perform the Cash/GCash transfer outside the application, and enter the actual receipt/transaction reference before marking a refund processed.

Run [`grant_subscription_booking_credits.php`](../backend/cli/grant_subscription_booking_credits.php) daily using the PHP CLI to issue two credits for each paid, verified monthly subscription period on or after its start date. The job preserves each period's original start and end dates, skips expired and future-start periods, and safely validates existing grants on retries. Its dry-run mode reports eligible and future-start counts without creating credits. Configure it in Windows Task Scheduler with:

```text
C:\xampp\php\php.exe C:\xampp\htdocs\Gym-Management-System\backend\cli\grant_subscription_booking_credits.php
```
