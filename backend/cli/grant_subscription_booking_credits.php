<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/booking_pricing.php';

if (!isset($conn) || !$conn instanceof PDO) {
    fwrite(STDERR, "Subscription booking-credit job could not initialize the database connection." . PHP_EOL);
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

try {
    $conn->beginTransaction();

    $eligibleStmt = $conn->prepare(
        "SELECT sp.id AS period_id, sp.member_id, sp.payment_id, sp.starts_on, sp.ends_on
         FROM membership_subscription_periods sp
         JOIN payments p ON p.id = sp.payment_id
         JOIN members m ON m.id = sp.member_id
         WHERE sp.status = 'active'
           AND sp.starts_on <= :today
           AND sp.ends_on >= :today
           AND p.status = 'paid'
           AND p.verification_status = 'verified'
           AND p.payment_type = 'monthly_subscription'
           AND m.status = 'active'
         ORDER BY sp.id
         FOR UPDATE"
    );
    $eligibleStmt->execute([':today' => $today]);
    $eligiblePeriods = $eligibleStmt->fetchAll(PDO::FETCH_ASSOC);

    $futureStmt = $conn->prepare(
        "SELECT COUNT(*)
         FROM membership_subscription_periods sp
         JOIN payments p ON p.id = sp.payment_id
         JOIN members m ON m.id = sp.member_id
         WHERE sp.status = 'active'
           AND sp.starts_on > :today
           AND p.status = 'paid'
           AND p.verification_status = 'verified'
           AND p.payment_type = 'monthly_subscription'
           AND m.status = 'active'"
    );
    $futureStmt->execute([':today' => $today]);
    $futureCount = (int)$futureStmt->fetchColumn();

    $existingStmt = $conn->prepare(
        'SELECT g.id, g.member_id, g.subscription_period_id, g.starts_on, g.ends_on,
                g.credits_granted,
                (SELECT COUNT(*) FROM subscription_booking_credits c WHERE c.grant_id = g.id) AS credit_count,
                (SELECT COUNT(DISTINCT c.credit_number) FROM subscription_booking_credits c WHERE c.grant_id = g.id) AS credit_number_count
         FROM subscription_booking_credit_grants g
         WHERE g.payment_id = :payment_id
         FOR UPDATE'
    );

    $created = 0;
    $alreadyGranted = 0;
    foreach ($eligiblePeriods as $period) {
        $paymentId = (int)$period['payment_id'];
        $memberId = (int)$period['member_id'];
        $periodId = (int)$period['period_id'];
        $startsOn = (string)$period['starts_on'];
        $endsOn = (string)$period['ends_on'];

        $existingStmt->execute([':payment_id' => $paymentId]);
        $grant = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($grant) {
            if ((int)$grant['member_id'] !== $memberId
                || (int)$grant['subscription_period_id'] !== $periodId
                || (string)$grant['starts_on'] !== $startsOn
                || (string)$grant['ends_on'] !== $endsOn
                || (int)$grant['credits_granted'] !== 2
                || (int)$grant['credit_count'] !== 2
                || (int)$grant['credit_number_count'] !== 2) {
                throw new RuntimeException('An existing subscription credit grant does not match its purchase and period.');
            }
            $alreadyGranted++;
            continue;
        }

        if (!$dryRun) {
            grantMonthlySubscriptionBookingCredits(
                $conn,
                $memberId,
                $paymentId,
                $periodId,
                $startsOn,
                $endsOn
            );
            $created++;
        }
    }

    if ($dryRun) {
        $conn->rollBack();
    } else {
        $conn->commit();
    }

    $planned = $dryRun ? count($eligiblePeriods) - $alreadyGranted : $created;
    fwrite(
        STDOUT,
        sprintf(
            '[subscription-credit-job] date=%s outcome=%s eligible=%d created=%d already_granted=%d future_start=%d credits_created=%d',
            $today,
            $dryRun ? 'dry_run' : 'success',
            count($eligiblePeriods),
            $dryRun ? 0 : $created,
            $alreadyGranted,
            $futureCount,
            $planned * 2
        ) . PHP_EOL
    );
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log('[subscription-credit-job] outcome=failure error_code=' . ($e instanceof PDOException ? (string)$e->getCode() : 'runtime_error'));
    fwrite(STDERR, "Subscription booking-credit job failed. Check the PHP error log." . PHP_EOL);
    exit(1);
}
