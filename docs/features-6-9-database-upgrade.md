# Features #6–9 database upgrade guide

This guide covers the membership subscription workflow (#6), booking pricing (#7),
subscription booking credits (#8), and booking payment/cancellation/refund workflows
(#9). It does not certify the migration state of any existing deployment. Determine
that state from a verified backup or disposable clone, not by probing a live database.

## Supported starting schema

Start from the application schema immediately before the subscription workflow
migration, with the booking-scheduling prerequisites already present. At minimum,
the schema must have:

- InnoDB `users`, `members`, `bookings`, `trainers`, `trainer_availability`,
  `notifications`, `registration_requests`, `registration_request_files`, and
  `payments` tables.
- Integer primary keys matching the migration foreign-key types.
- The pre-Feature #6 registration and payment columns that the migration alters:
  `registration_requests.payment_status`, `discount_status`, `payment_amount`,
  `reviewed_by`, `membership_type`, and `member_category`; the registration file
  review columns and `uq_registration_request_document`; and the payment
  `registration_request_id`, `member_id`, `booking_id`, `recorded_by`, `amount`,
  `payment_type`, `status`, `payment_date`, and `reference_no` columns.
- The booking structures created by scheduling migrations: `booking_session_types`,
  `booking_session_items`, and `bookings.duration_minutes`, plus the user/member/
  trainer relationships needed by their foreign keys.

`gym_management_test_schema.sql` is a test snapshot, not a supported starting point
for replaying the historical chain. For example, it does not provide the complete
registration-file workflow expected by Feature #6 and already has overlapping
payment columns. It therefore fails both prerequisite and duplicate-column
assumptions of some historical migrations. Do not try to make it an upgrade source
by blindly replaying the chain.

## Required execution order

Use an isolated clone and verify `SELECT DATABASE()` before each migration. The
relevant order for a database that has not yet received these features is:

1. `20261102_upgrade_booking_scheduling.sql` — creates the booking schedule and
   trainer exception foundations.
2. `20261103_flexible_booking_requests.sql` — adds the alternative-time Member
   response workflow.
3. `20261105_add_booking_session_items.sql` — creates ordered session snapshots;
   requires the session-type table from step 1.
4. `20261106_registration_subscription_workflow.sql` — creates membership plans
   and subscription periods and extends registration/payment workflows.
5. `20261117_add_booking_pricing_snapshots.sql` — requires the booking session
   types and session items from steps 1 and 3.
6. `20261118_add_subscription_booking_credits.sql` — requires
   `membership_subscription_periods`, `payments`, `bookings`, `members`, and
   `users`.
7. `20261119_add_booking_payment_lifecycle.sql` — extends booking/payment status
   and payment deadline metadata.
8. `20261120_add_booking_cancellation_refunds.sql` — requires the Feature #8 credit
   table and the Feature #9 payment lifecycle schema.

The Feature #9 migration requires InnoDB `bookings`, `users`, `members`, `payments`,
and `subscription_booking_credits`. It also checks the exact key-column types and
the prerequisite columns before changing anything.

Several historical files contain `USE gym_management;`. That statement overrides a
client-selected database. For disposable rehearsal, use reviewed scratch copies with
only that selector changed to the disposable schema, or another verified mechanism
that cannot target the live database. The Feature #9 migration deliberately does
not select a database; select the disposable target explicitly before applying it.
Do not edit the checked-in historical migrations to redirect them.

## Rerun safety

Do not treat the migration directory as a replayable migration runner. These
historical migrations are not safely rerunnable after success:

- `20261102_upgrade_booking_scheduling.sql`
- `20261105_add_booking_session_items.sql`
- `20261106_registration_subscription_workflow.sql`
- `20261117_add_booking_pricing_snapshots.sql`
- `20261118_add_subscription_booking_credits.sql`
- `20261119_add_booking_payment_lifecycle.sql`

They contain non-idempotent `ALTER TABLE`, `CREATE TABLE`, fixed seed inserts, or
index/constraint creation. A partially applied statement may have committed even
when a later statement failed.

`20261120_add_booking_cancellation_refunds.sql` is designed to resume after DDL
interruption. It checks its prerequisites and uses metadata-aware column, index,
foreign-key, and table creation steps. Rerun only this migration, against the same
explicitly selected disposable target, after checking the failed state. If it reports
an unexpected existing definition, stop and inspect it; do not force or hand-wave
past the mismatch.

## Partial-failure recovery

1. Stop the migration sequence at the first error. Do not continue to a later
   migration and do not replay the failed historical file.
2. Confirm the selected schema with `SELECT DATABASE()` and confirm the server
   endpoint/port independently.
3. On the disposable clone, inspect the migration's affected tables, columns,
   indexes, constraints, and row counts. Compare those definitions with the source
   migration and its immediate prerequisites.
4. For a failed non-rerunnable historical migration, restore a verified pre-migration
   disposable snapshot and reapply a reviewed, one-time recovery script containing
   only the missing operations. If no reliable pre-migration snapshot exists, keep
   the database isolated and reconcile the partial state manually; do not run the
   full file again.
5. For Feature #9, inspect all metadata first, then rerun the actual migration if
   the definitions it already created are compatible. Investigate any unexpected
   column or index definition before proceeding.
6. Re-run the post-migration checks below and the relevant database-backed tests
   before considering the upgrade complete.

## Post-migration metadata checks

Run these checks only on the disposable clone, with the target schema selected:

```sql
SELECT DATABASE() AS selected_database, @@port AS server_port;

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'membership_plans', 'membership_subscription_periods',
    'booking_session_prices', 'booking_session_items',
    'subscription_booking_credit_grants', 'subscription_booking_credits',
    'subscription_booking_credit_events',
    'booking_cancellation_history', 'booking_refunds',
    'booking_no_show_records'
  )
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
    (TABLE_NAME = 'bookings' AND COLUMN_NAME IN (
      'duration_minutes', 'subtotal', 'credit_amount', 'amount_due',
      'price_currency', 'price_snapshot_at', 'payment_deadline_at',
      'response_deadline', 'cancelled_by_user_id', 'cancelled_at',
      'refund_eligible', 'credit_restoration_decision'
    ))
    OR (TABLE_NAME = 'payments' AND COLUMN_NAME IN (
      'membership_plan_id', 'registration_request_id', 'payment_method',
      'verification_status', 'verified_at', 'payment_channel'
    ))
    OR (TABLE_NAME = 'subscription_booking_credits' AND COLUMN_NAME IN (
      'grant_id', 'credit_number', 'status', 'applied_booking_id'
    ))
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'subscription_booking_credit_grants',
    'subscription_booking_credits',
    'subscription_booking_credit_events',
    'booking_cancellation_history', 'booking_refunds',
    'booking_no_show_records'
  )
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
ORDER BY TABLE_NAME, INDEX_NAME;

SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME,
       REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
  AND TABLE_NAME IN (
    'subscription_booking_credit_grants',
    'subscription_booking_credits',
    'subscription_booking_credit_events',
    'booking_cancellation_history', 'booking_refunds',
    'booking_no_show_records'
  )
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;
```

Verify in particular:

- All Feature #8 and #9 tables use InnoDB and have the expected primary keys.
- `uq_booking_credit_grant_payment` and
  `uq_booking_credit_grant_period` enforce one grant per payment and period.
- `uq_subscription_booking_credit_number`,
  `uq_subscription_booking_credit_booking`, and
  `uq_subscription_booking_credit_event_key` enforce credit and event uniqueness.
- `uq_booking_refund_payment` prevents multiple refund requests for one payment;
  refund foreign keys preserve the original payment and linked booking/member.
- The booking status enum includes the payment lifecycle and no-show states, and
  the payment channel enum includes `gcash`.
- No existing member, payment, booking, or credit history was deleted or rewritten
  as part of applying the additive schema changes.
