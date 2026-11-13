# 20261106 / 20261107 safe recovery checklist

**Review only.** This is a stepwise approval plan, not authorization to apply
schema changes. No migration, DDL, or data-changing SQL was run for this review.
Keep the original files in `backend/migrations/` unchanged.

## Safety rules

- Take and verify a restorable database backup before approval.
- Stop the affected registration, monthly-payment, and daily-pass workflows
  during the change window so no application writes race the migration.
- Use `gym_management` explicitly. Run `verification.sql` first; it is
  SELECT-only. The previously confirmed baseline had the 20261102 prerequisite
  structure, no migration ledger, no 20261106/07 feature tables, no payments
  or registration requests, and two monthly members.
- Apply one reviewed statement at a time. After each statement, inspect
  `information_schema` and record that step as absent, exact/present, or
  incompatible. Do not select/run the entire review SQL file as a batch.
- If an object is already exact, skip its DDL. If it is partially present,
  differs from the target, has a conflicting same-name object, or reveals
  ambiguous data, stop. Do not drop/recreate, rename, coerce, or delete anything
  to make the script proceed. Prepare a separate reviewed repair step.
- Do not run original `20261102`: its effects are already present and there is
  no ledger proving which script created them.
- Do not insert or edit a migration ledger as part of this proposal.

## Statements that can leave partial progress

MariaDB DDL commits independently of a surrounding transaction. Treat the
result of each original statement as unknown after a timeout/disconnect until
metadata confirms it. These are all original 20261106/07 migration statements:

| Migration | Original statement/operation | Possible state after interruption/failure | Recovery evidence |
|---|---|---|---|
| 20261106 | `CREATE TABLE membership_plans` | Table absent, fully created, or pre-existing/incompatible table | `TABLES`, all `COLUMNS`, `STATISTICS`, table/check constraints |
| 20261106 | Insert `monthly_1` seed | Row absent, exact row present, or conflicting row present | Inspect all plan rows and values; never overwrite a mismatch |
| 20261106 | Multi-action `ALTER registration_requests` | Some/all enum changes, columns, indexes, or FKs may already exist | Inspect each column definition, index order/uniqueness, and FK target/action separately |
| 20261106 | `ALTER registration_request_files` | `review_status` may be old or target enum | Inspect `COLUMN_TYPE`, nullability, and default |
| 20261106 | Multi-action `ALTER payments` | Some/all new columns/indexes/FKs may already exist | Inspect each column, index, and FK separately; preserve existing reference and PayMongo fields |
| 20261106 | `CREATE TABLE membership_subscription_periods` | Table absent, exact, or partial/incompatible | Inspect all columns, indexes, FKs/delete rules, and check constraints |
| 20261106 | Legacy-period `INSERT ... SELECT` | No rows, some rows, or all eligible snapshots inserted | Compare against member dates; inspect `source='legacy'`; rerun only with the verified anti-duplicate guard |
| 20261107 | `ALTER members ... membership_type` | Old enum or target enum | Inspect exact enum definition; stop on unexpected enum values |
| 20261107 | `CREATE TABLE daily_pass_transactions` | Table absent, exact, or partial/incompatible | Inspect all columns, indexes, FKs/delete rules, and check constraints |

The review files split grouped ALTERs into individual operations so a failure
has a narrow checkpoint. This does not make DDL transactional: always inspect
after an error before resuming.

## Recovery and rerun order

### A. Preflight / checkpoint

1. Run the SELECT-only `verification.sql` against `gym_management`.
2. Save its output with the change record. Confirm the prerequisite objects
   and live enum/index definitions are still as previously reported.
3. Specifically confirm these dates before any step that touches member
   membership data:

   - Member 1: `2026-10-31` through `2026-11-01`
   - Member 31: `2026-10-31` through `2026-12-01`

4. If either date differs from the known baseline, stop and investigate. Do
   not restore or infer dates from the prior report.
5. Confirm no migration ledger appeared and no one else is running these
   migrations.

### B. 20261106 — plan and subscription workflow

Proceed in file order in
[`20261106_registration_subscription_workflow.review.sql`](./20261106_registration_subscription_workflow.review.sql).

1. **`membership_plans`:** if absent, create it. If present, require the exact
   definition including plan-code/duration unique keys and approved-terms
   check. A partial/incompatible table is a hard stop; do not rely on
   `CREATE TABLE IF NOT EXISTS`.
2. **Seed:** inspect every existing plan. Exactly one approved plan is allowed:
   `monthly_1`, duration 1, regular ₱800, student/senior/PWD ₱700. If that
   exact plan exists, skip the insert. If absent and no other plan/duration or
   conflicting `monthly_1` exists, the guarded insert may be run once. Any
   mismatch is a hard stop; never update/replace the existing row.
3. **Registration enums and columns:** for each field, exact target means skip;
   absent means add/modify only that field; any incompatible existing
   definition/value set means stop. `IF NOT EXISTS` only avoids a duplicate
   column name and does not check the existing type/default.
4. **Registration indexes:** inspect the index name, uniqueness, and ordered
   columns. Skip an exact or equivalent existing index; stop if the same name
   has another definition. If `application_token_hash` exists, check duplicates
   before adding the unique index; if the column is absent, skip this query
   until after the column step:

   ```sql
   SELECT application_token_hash, COUNT(*) AS duplicate_count
   FROM gym_management.registration_requests
   WHERE application_token_hash IS NOT NULL
   GROUP BY application_token_hash
   HAVING COUNT(*) > 1;
   ```

   Any result blocks adding the unique index until reviewed. Do not delete or
   rewrite requests.
5. **Registration FKs:** these have no `ADD ... IF NOT EXISTS`. Inspect
   `KEY_COLUMN_USAGE` and `REFERENTIAL_CONSTRAINTS` for the expected name and
   equivalent relationships. Exact relationship/action means skip. A
   conflicting name or incompatible equivalent means stop.
6. **File status enum:** skip if exact target; stop if additional existing enum
   states would be lost by modification.
7. **Payment columns/indexes/FKs:** add only absent objects. Never add another
   `payment_method`, `reference_no`, `payment_channel`, registration-request
   link, or PayMongo field/index. If `idempotency_key` exists, check duplicate
   non-NULL values before adding its unique index; if absent, add the column
   first, then inspect it before the index step. Any duplicate is a stop; do
   not edit payment records. Existing `payment_channel` already contains
   `paymongo`, `counter`, and `gcash` and must remain unchanged.
8. **Subscription history table:** if absent, create it. If exact, skip. If
   partial or incompatible, stop for a bespoke non-destructive recovery patch.
   The review definition matches signed `INT(11)` parent IDs for
   `registration_requests.id` and `payments.id`; do not change these back to
   unsigned BIGINT. FK actions are:
   `member_id ON DELETE RESTRICT`; optional plan/request/payment references
   `ON DELETE SET NULL`. No cascade can erase subscription history.
9. **Legacy backfill:** Member IDs 1 and 31 are approved test-account
   exclusions and must never receive legacy subscription rows. Preview all
   remaining candidates with `m.id NOT IN (1, 31)` before any insert. If there
   are zero, mark the data checkpoint complete with zero inserts. Before any
   nonzero retry, inspect existing history and check
   for duplicate `(member_id, starts_on, ends_on, source='legacy')` rows. The
   unique key prevents duplicates; the insert's `NOT EXISTS` is an additional
   sequential retry guard. Do not run concurrent copies. Snapshot only
   complete, nonzero, non-reversed monthly date ranges. Plan and duration stay
   `NULL`; no member row is updated. Verify members 1 and 31 retain their exact
   dates and have no corresponding legacy snapshot rows.
10. Run the post-migration read-only metadata and data checks before enabling
    application workflows.

### C. 20261107 — daily passes

Proceed only after 20261106 is verified, in
[`20261107_add_daily_pass_transactions.review.sql`](./20261107_add_daily_pass_transactions.review.sql).

1. Inspect `members.membership_type`. If it is already exactly
   `ENUM('monthly','walk-in','daily_pass') NOT NULL`, skip the enum ALTER. If
   it is exactly the known old enum, apply the one-value extension. Any other
   enum definition is a hard stop; do not remove unknown values.
2. Inspect `daily_pass_transactions`. If absent, create it. If exactly present,
   skip. If partial/incompatible, stop; do not recreate or infer customer/pass
   data. A separate patch must account for every existing row before adding
   any required non-null column or constraint.
3. Confirm exact columns, indexes, foreign keys, delete rules, and checks.
   `amount` must be constrained to ₱100; `payment_method` is cash/GCash;
   reference number is nullable and not unique. Member/user FKs use `SET NULL`.
4. Keep daily-pass entitlements out of `membership_subscription_periods`.
   Expiration must not change `members.account_status` or `members.status`.
5. Run the post-migration read-only checks before enabling pass endpoints.

## Exact preservation and stop conditions

- Do not modify original migration files or any live database object during
  this review.
- Do not delete, update, replace, merge, or truncate members, payment records,
  registration requests, files, subscription rows, or daily-pass transactions.
- Do not invent historical plan/duration values, extend dates, or seed plans
  other than the approved one-month plan.
- Stop if table definitions are partial/incompatible, an enum contains
  unrecognized values, a same-name index/FK differs, duplicate data blocks a
  unique constraint, or parent key types do not match.
- Do not expose private GCash proof files or require a GCash reference number.
- The new DDL is still pending explicit manual approval and a verified backup.
