# Automatic member checkout

The CLI job closes only open member attendance rows from the Philippine calendar date immediately before it runs. It sets `time_out` to `23:59:59` and `checkout_type` to `automatic`. It never inserts attendance rows or changes trainer attendance. Failures and the number of updated rows are written to the PHP error log configured in `C:\xampp\php\php.ini` (currently `C:\xampp\php\logs\php_error_log`). If that configured log directory is unavailable, the job falls back to `backend\cli\auto_checkout.log`; the scheduled-task account must be able to write to at least one of those locations.

## Database setup

Apply `database/attendance_auto_checkout.sql` once to the `gym_management` database. The migration adds an automatic/manual marker and an index for the daily open-session lookup; existing attendance rows are retained and default to `manual`.

## Local Windows/XAMPP schedule

The PHP CLI executable is `C:\xampp\php\php.exe`. The script is `backend\cli\auto_checkout.php` and must be run through PHP CLI, not an HTTP endpoint.

1. Confirm Apache/PHP and MySQL are installed and that MySQL is running when the job fires.
2. Open **Task Scheduler** and select **Create Task**.
3. On **General**, name the task `Raybens Gym Automatic Member Checkout`. Select **Run whether user is logged on or not** and use an account that can read the project and write to the PHP error log. The script needs no interactive browser session.
4. On **Triggers**, create a **Daily** trigger at **12:00:00 AM**. The Windows host must use Philippine time (UTC+08:00); Task Scheduler uses the host's local clock.
5. On **Actions**, choose **Start a program**:
   - Program/script: `C:\xampp\php\php.exe`
   - Add arguments: `"C:\xampp\htdocs\Gym-Management-System\backend\cli\auto_checkout.php"`
   - Start in: `C:\xampp\htdocs\Gym-Management-System\backend\cli`
6. On **Settings**, enable **Run task as soon as possible after a scheduled start is missed**. Save the task and verify its next run time and **Last Run Result** in Task Scheduler.

The task is not registered automatically by application code. It must be created and enabled on each XAMPP host.

## Linux production schedule

Install the migration and add a cron entry under an account that can run PHP and reach MySQL:

```cron
CRON_TZ=Asia/Manila
0 0 * * * /usr/bin/php /path/to/Gym-Management-System/backend/cli/auto_checkout.php
```

Configure PHP CLI/MySQL credentials using the deployment's existing protected configuration. Do not expose this script through a public web route. If the machine is unavailable for multiple days, the job intentionally processes only the immediately preceding date on its next run.
