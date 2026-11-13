<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Manila');

$databaseInitialized = false;
register_shutdown_function(function () use (&$databaseInitialized) {
    if (!$databaseInitialized) {
        $message = '[membership-reminder-job] outcome=failure error=database_initialization_failed';
        error_log($message);
        fwrite(STDERR, $message . PHP_EOL);
    }
});

require_once __DIR__ . '/../config/database.php';
if (!isset($conn) || !$conn instanceof PDO) {
    fwrite(STDERR, "Membership reminder job could not initialize the database connection." . PHP_EOL);
    exit(1);
}
$databaseInitialized = true;

$dryRun = in_array('--dry-run', $argv, true);
$timezone = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $timezone);
$todayDate = $today->format('Y-m-d');
$expirationIntervals = [
    $today->modify('+3 days')->format('Y-m-d') => 3,
    $today->modify('+1 day')->format('Y-m-d') => 1,
];

$messages = [
    3 => [
        'title' => 'Membership expires in 3 days',
        'message' => 'Your gym membership will expire in 3 days. Renew your membership to continue enjoying your gym activities.',
    ],
    1 => [
        'title' => 'Membership expires tomorrow',
        'message' => 'Your gym membership expires tomorrow. Please renew your membership to avoid interruption.',
    ],
];

try {
    $placeholders = [];
    $queryParams = [':today' => $todayDate];
    foreach ($expirationIntervals as $expirationDate => $days) {
        $placeholder = ':expiration_' . $days;
        $placeholders[] = $placeholder;
        $queryParams[$placeholder] = $expirationDate;
    }

    $membersStmt = $conn->prepare(
        "SELECT DISTINCT u.id AS user_id, m.membership_end
         FROM members m
         JOIN users u
           ON u.id = m.user_id
         WHERE LOWER(TRIM(u.role)) = 'member'
           AND LOWER(TRIM(m.membership_type)) = 'monthly'
           AND LOWER(TRIM(m.status)) = 'active'
           AND LOWER(TRIM(m.account_status)) = 'active'
           AND m.membership_end > :today
           AND m.membership_end IN (" . implode(', ', $placeholders) . ')'
    );
    $membersStmt->execute($queryParams);
    $eligibleMembers = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

    $insertStmt = null;
    if (!$dryRun) {
        $insertStmt = $conn->prepare(
            'INSERT INTO notifications
                (user_id, title, message, notification_type, reminder_days, membership_end)
             VALUES
                (:user_id, :title, :message, :notification_type, :reminder_days, :membership_end)
             ON DUPLICATE KEY UPDATE id = id'
        );
    }

    $createdCount = 0;
    $threeDayCount = 0;
    $oneDayCount = 0;
    foreach ($eligibleMembers as $member) {
        $membershipEnd = (string)$member['membership_end'];
        $reminderDays = $expirationIntervals[$membershipEnd] ?? null;
        if ($reminderDays === null) {
            continue;
        }

        if ($reminderDays === 3) {
            $threeDayCount++;
        } else {
            $oneDayCount++;
        }

        if ($dryRun) {
            continue;
        }

        $insertStmt->execute([
            ':user_id' => (int)$member['user_id'],
            ':title' => $messages[$reminderDays]['title'],
            ':message' => $messages[$reminderDays]['message'],
            ':notification_type' => 'membership_expiration',
            ':reminder_days' => $reminderDays,
            ':membership_end' => $membershipEnd,
        ]);
        if ($insertStmt->rowCount() > 0) {
            $createdCount++;
        }
    }

    $outcome = $dryRun ? 'dry_run' : 'success';
    $summary = sprintf(
        '[membership-reminder-job] date=%s outcome=%s eligible_3_day=%d eligible_1_day=%d created=%d',
        $todayDate,
        $outcome,
        $threeDayCount,
        $oneDayCount,
        $createdCount
    );
    fwrite(STDOUT, $summary . PHP_EOL);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $errorCode = $e instanceof PDOException ? (string)$e->getCode() : 'runtime_error';
    $message = sprintf(
        '[membership-reminder-job] date=%s outcome=failure error_code=%s',
        $todayDate,
        $errorCode
    );
    error_log($message);
    fwrite(STDERR, "Membership reminder job failed. Check the PHP error log." . PHP_EOL);
    exit(1);
}
