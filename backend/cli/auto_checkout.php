<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function logCheckoutEvent($message)
{
    $configuredLog = ini_get('error_log');
    if ($configuredLog === '' || is_dir(dirname($configuredLog))) {
        if (error_log($message)) {
            return;
        }
    }

    $fallbackLog = __DIR__ . DIRECTORY_SEPARATOR . 'auto_checkout.log';
    if (file_put_contents($fallbackLog, $message . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, "Unable to write the automatic checkout log." . PHP_EOL);
    }
}

$databaseInitialized = false;
register_shutdown_function(function () use (&$databaseInitialized) {
    if (!$databaseInitialized) {
        logCheckoutEvent('[attendance-auto-checkout] failure=database_initialization_failed');
    }
});

require_once __DIR__ . '/../config/database.php';
$databaseInitialized = isset($conn) && $conn instanceof PDO;

$timezone = new DateTimeZone('Asia/Manila');
$runAt = new DateTimeImmutable('now', $timezone);
$attendanceDate = $runAt->modify('-1 day')->format('Y-m-d');

try {
    $conn->beginTransaction();
    $stmt = $conn->prepare(
        "UPDATE attendance
         SET time_out = '23:59:59', checkout_type = 'automatic'
         WHERE attendance_date = :attendance_date
           AND time_in IS NOT NULL
           AND time_out IS NULL"
    );
    $stmt->execute([':attendance_date' => $attendanceDate]);
    $closedCount = $stmt->rowCount();
    $conn->commit();

    $message = sprintf(
        '[attendance-auto-checkout] completed_at=%s attendance_date=%s closed_sessions=%d outcome=success',
        $runAt->format('Y-m-d H:i:s'),
        $attendanceDate,
        $closedCount
    );
    logCheckoutEvent($message);
    fwrite(STDOUT, $message . PHP_EOL);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $failureCode = $e instanceof PDOException ? (string)$e->getCode() : 'runtime_error';
    logCheckoutEvent(sprintf(
        '[attendance-auto-checkout] completed_at=%s attendance_date=%s outcome=failure error_code=%s',
        $runAt->format('Y-m-d H:i:s'),
        $attendanceDate,
        $failureCode
    ));
    fwrite(STDERR, "Automatic attendance checkout failed. See the PHP error log for details." . PHP_EOL);
    exit(1);
}
