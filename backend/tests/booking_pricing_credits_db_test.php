<?php

$database = getenv('GYM_DB_NAME') ?: '';
$host = getenv('GYM_DB_HOST') ?: '';
$port = getenv('GYM_DB_PORT') ?: '';
$allowedValidationTarget = preg_match('/^gym_booking_features_validation_\d{8}$/', $database)
    && $port === '13317';
$allowedFinalReviewTarget = $database === 'gym_booking_final_review_20261111'
    && $port === '13319';
if ((!$allowedValidationTarget && !$allowedFinalReviewTarget) || $host !== '127.0.0.1') {
    fwrite(STDERR, "Refusing to run without the explicitly configured disposable MariaDB database.\n");
    exit(2);
}

$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$database}",
    getenv('GYM_DB_USER') ?: 'root',
    getenv('GYM_DB_PASSWORD') ?: ''
);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->exec("SET time_zone='+08:00'");
require_once __DIR__ . '/../config/booking_pricing.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$conn->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(5));
    $password = password_hash('Disposable-test-password-2026!', PASSWORD_DEFAULT);
    $userInsert = $conn->prepare(
        'INSERT INTO users (first_name, last_name, full_name, username, email, password, role)
         VALUES (:first_name, :last_name, :full_name, :username, :email, :password, :role)'
    );
    $users = [];
    foreach (['admin', 'trainer', 'member'] as $role) {
        $userInsert->execute([
            ':first_name' => ucfirst($role),
            ':last_name' => 'Booking Test',
            ':full_name' => ucfirst($role) . ' Booking Test',
            ':username' => $role . '_booking_' . $suffix,
            ':email' => $role . '_' . $suffix . '@example.invalid',
            ':password' => $password,
            ':role' => $role,
        ]);
        $users[$role] = (int)$conn->lastInsertId();
    }

    $memberInsert = $conn->prepare(
        "INSERT INTO members
            (user_id, member_code, full_name, membership_type, member_category, status, account_status)
         VALUES (:user_id, :code, 'Member Booking Test', 'monthly', 'regular', 'active', 'active')"
    );
    $memberInsert->execute([':user_id' => $users['member'], ':code' => 'MB-' . strtoupper($suffix)]);
    $memberId = (int)$conn->lastInsertId();
    $trainerInsert = $conn->prepare(
        "INSERT INTO trainers (user_id, trainer_code, status) VALUES (:user_id, :code, 'active')"
    );
    $trainerInsert->execute([':user_id' => $users['trainer'], ':code' => 'TR-' . strtoupper($suffix)]);
    $trainerId = (int)$conn->lastInsertId();

    $sessionInsert = $conn->prepare(
        "INSERT INTO booking_session_types (name, duration_minutes, service_category, is_active)
         VALUES (:name, 60, 'personal_training', 1)"
    );
    $sessionInsert->execute([':name' => 'Credit Test ' . $suffix]);
    $sessionId = (int)$conn->lastInsertId();
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $today = $now->format('Y-m-d');
    $tomorrowAt = $now->modify('+1 day')->format('Y-m-d 00:00:00');
    $priceInsert = $conn->prepare(
        'INSERT INTO booking_session_prices
            (session_type_id, amount, currency, effective_from, retired_at, created_by)
         VALUES (:session_id, :amount, \'PHP\', :effective_from, :retired_at, :created_by)'
    );
    $priceInsert->execute([
        ':session_id' => $sessionId,
        ':amount' => '1500.00',
        ':effective_from' => '2020-01-01 00:00:00',
        ':retired_at' => $tomorrowAt,
        ':created_by' => $users['admin'],
    ]);
    $oldPriceId = (int)$conn->lastInsertId();
    $priceInsert->execute([
        ':session_id' => $sessionId,
        ':amount' => '1750.00',
        ':effective_from' => $tomorrowAt,
        ':retired_at' => null,
        ':created_by' => $users['admin'],
    ]);
    $newPriceId = (int)$conn->lastInsertId();
    $assert((int)bookingPriceForSession($conn, $sessionId, $now->format('Y-m-d H:i:s'))['id'] === $oldPriceId, 'Current price lookup did not preserve the active price version.');
    $assert((int)bookingPriceForSession($conn, $sessionId, $now->modify('+2 days')->format('Y-m-d H:i:s'))['id'] === $newPriceId, 'Future price lookup did not select the scheduled version.');

    $paymentInsert = $conn->prepare(
        "INSERT INTO payments (member_id, amount, payment_type, status, payment_date, payment_method, duration_months, verification_status)
         VALUES (:member_id, '800.00', 'monthly_subscription', 'paid', :payment_date, 'cash', :duration_months, 'not_required')"
    );
    $paymentInsert->execute([':member_id' => $memberId, ':payment_date' => $today, ':duration_months' => 1]);
    $paymentId = (int)$conn->lastInsertId();
    $paymentChannel = $conn->query("SHOW COLUMNS FROM payments LIKE 'payment_channel'")->fetch(PDO::FETCH_ASSOC);
    $assert(str_contains((string)($paymentChannel['Type'] ?? ''), "'gcash'"), 'Booking GCash channel is not supported by the payment schema.');
    $channelInsert = $conn->prepare(
        "INSERT INTO payments
            (member_id, amount, payment_type, status, payment_date, payment_method, payment_channel, verification_status)
         VALUES (:member_id, '100.00', 'training_session', 'pending', :payment_date, 'gcash', 'gcash', 'pending')"
    );
    $channelInsert->execute([':member_id' => $memberId, ':payment_date' => $today]);
    $channel = $conn->prepare('SELECT payment_channel FROM payments WHERE id = :id');
    $channel->execute([':id' => (int)$conn->lastInsertId()]);
    $assert($channel->fetchColumn() === 'gcash', 'Booking GCash payment channel was not persisted.');
    $periodStart = $today;
    $periodEnd = $now->modify('+1 month')->format('Y-m-d');
    $periodInsert = $conn->prepare(
        "INSERT INTO membership_subscription_periods
            (member_id, payment_id, duration_months, starts_on, ends_on, status, source)
         VALUES (:member_id, :payment_id, :duration_months, :starts_on, :ends_on, 'active', 'legacy')"
    );
    $periodInsert->execute([
        ':member_id' => $memberId,
        ':payment_id' => $paymentId,
        ':duration_months' => 1,
        ':starts_on' => $periodStart,
        ':ends_on' => $periodEnd,
    ]);
    $periodId = (int)$conn->lastInsertId();
    grantMonthlySubscriptionBookingCredits($conn, $memberId, $paymentId, $periodId, $periodStart, $periodEnd);
    grantMonthlySubscriptionBookingCredits($conn, $memberId, $paymentId, $periodId, $periodStart, $periodEnd);
    $purchaseCreditCount = $conn->prepare(
        'SELECT COUNT(*) FROM subscription_booking_credits c
         JOIN subscription_booking_credit_grants g ON g.id = c.grant_id
         WHERE g.payment_id = :payment_id'
    );
    $purchaseCreditCount->execute([':payment_id' => $paymentId]);
    $assert((int)$purchaseCreditCount->fetchColumn() === 2, 'A paid subscription must create exactly two credits and duplicate grant calls must be idempotent.');

    $grantDates = $conn->prepare(
        'SELECT g.starts_on, g.ends_on, sp.starts_on AS period_starts_on, sp.ends_on AS period_ends_on
         FROM subscription_booking_credit_grants g
         JOIN membership_subscription_periods sp ON sp.id = g.subscription_period_id
         WHERE g.payment_id = :payment_id'
    );
    foreach ([3, 6, 1] as $durationMonths) {
        $paymentInsert->execute([
            ':member_id' => $memberId,
            ':payment_date' => $today,
            ':duration_months' => $durationMonths,
        ]);
        $independentPaymentId = (int)$conn->lastInsertId();
        $periodStart = $now->modify('+' . ($durationMonths === 1 ? 1 : $durationMonths) . ' months')->format('Y-m-d');
        $periodEnd = $now->modify('+' . ($durationMonths + 1) . ' months')->format('Y-m-d');
        $periodInsert->execute([
            ':member_id' => $memberId,
            ':payment_id' => $independentPaymentId,
            ':duration_months' => $durationMonths,
            ':starts_on' => $periodStart,
            ':ends_on' => $periodEnd,
        ]);
        $independentPeriodId = (int)$conn->lastInsertId();
        grantMonthlySubscriptionBookingCredits(
            $conn,
            $memberId,
            $independentPaymentId,
            $independentPeriodId,
            $periodStart,
            $periodEnd
        );
        grantMonthlySubscriptionBookingCredits(
            $conn,
            $memberId,
            $independentPaymentId,
            $independentPeriodId,
            $periodStart,
            $periodEnd
        );
        $purchaseCreditCount->execute([':payment_id' => $independentPaymentId]);
        $assert((int)$purchaseCreditCount->fetchColumn() === 2, "{$durationMonths}-month subscription purchase did not receive exactly two idempotent credits.");
        $grantDates->execute([':payment_id' => $independentPaymentId]);
        $dates = $grantDates->fetch(PDO::FETCH_ASSOC);
        $assert($dates['starts_on'] === $dates['period_starts_on'] && $dates['ends_on'] === $dates['period_ends_on'], "{$durationMonths}-month credit grant dates did not match its purchased subscription period.");
    }

    $unpaidPaymentInsert = $conn->prepare(
        "INSERT INTO payments (member_id, amount, payment_type, status, payment_date, payment_method, duration_months, verification_status)
         VALUES (:member_id, '800.00', 'monthly_subscription', :status, :payment_date, 'cash', 3, 'pending')"
    );
    foreach (['pending', 'failed'] as $unpaidStatus) {
        $unpaidPaymentInsert->execute([
            ':member_id' => $memberId,
            ':status' => $unpaidStatus,
            ':payment_date' => $today,
        ]);
        $unpaidPaymentId = (int)$conn->lastInsertId();
        $unpaidStart = $now->modify('+8 months')->format('Y-m-d');
        $unpaidEnd = $now->modify('+11 months')->format('Y-m-d');
        $periodInsert->execute([
            ':member_id' => $memberId,
            ':payment_id' => $unpaidPaymentId,
            ':duration_months' => 3,
            ':starts_on' => $unpaidStart,
            ':ends_on' => $unpaidEnd,
        ]);
        $unpaidPeriodId = (int)$conn->lastInsertId();
        try {
            grantMonthlySubscriptionBookingCredits(
                $conn,
                $memberId,
                $unpaidPaymentId,
                $unpaidPeriodId,
                $unpaidStart,
                $unpaidEnd
            );
            $assert(false, "{$unpaidStatus} subscription payment incorrectly granted booking credits.");
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'successfully paid'), "{$unpaidStatus} payment failed for an unexpected reason.");
        }
        $purchaseCreditCount->execute([':payment_id' => $unpaidPaymentId]);
        $assert((int)$purchaseCreditCount->fetchColumn() === 0, "{$unpaidStatus} payment created a credit grant.");
    }

    $bookingInsert = $conn->prepare(
        "INSERT INTO bookings
            (booking_code, member_id, trainer_id, booking_date, start_time, end_time, session_type, status)
         VALUES (:code, :member_id, :trainer_id, :booking_date, '10:00:00', '11:00:00', 'Personal Training', 'pending')"
    );
    $bookingInsert->execute([
        ':code' => 'BK-' . strtoupper($suffix) . '-1',
        ':member_id' => $memberId,
        ':trainer_id' => $trainerId,
        ':booking_date' => $today,
    ]);
    $bookingId = (int)$conn->lastInsertId();
    $credit = bookingReserveSubscriptionCredit($conn, $memberId, $today, true);
    $assert($credit !== null, 'Eligible member could not reserve an available subscription credit.');
    bookingLinkReservedCredit($conn, (int)$credit['id'], $bookingId, $users['member']);
    $assert(bookingReservedCreditCoversDate($conn, $bookingId, $today) === true, 'Reserved credit did not match its subscription period.');
    $assert(bookingReservedCreditCoversDate($conn, $bookingId, $now->modify('+2 months')->format('Y-m-d')) === false, 'Reserved credit incorrectly covered a date outside its subscription period.');
    $assert(bookingConsumedCreditCoversDate($conn, $bookingId, $today) === null, 'An unconsumed credit was reported as consumed.');
    $secondBookingId = $bookingId + 1;
    try {
        bookingLinkReservedCredit($conn, (int)$credit['id'], $secondBookingId, $users['member']);
        $assert(false, 'A reserved credit was linked to a second booking.');
    } catch (RuntimeException $error) {
        $assert($error->getCode() === 409, 'Duplicate credit reservation did not return a conflict.');
    }
    $assert(bookingConsumeReservedCredit($conn, $bookingId, $users['member']), 'Reserved credit was not consumed.');
    $assert(!bookingConsumeReservedCredit($conn, $bookingId, $users['member']), 'Consumed credit was consumed a second time.');
    $assert(bookingConsumedCreditCoversDate($conn, $bookingId, $today) === true, 'Consumed credit did not remain linked to its eligible subscription period.');
    $assert(bookingConsumedCreditCoversDate($conn, $bookingId, $now->modify('+2 months')->format('Y-m-d')) === false, 'Consumed credit incorrectly covered a rescheduled date outside its subscription period.');
    $assert(bookingReserveSubscriptionCredit($conn, $memberId, $today, false) === null, 'A non-eligible session reserved a free credit.');
    $bookingInsert->execute([
        ':code' => 'BK-' . strtoupper($suffix) . '-3',
        ':member_id' => $memberId,
        ':trainer_id' => $trainerId,
        ':booking_date' => $today,
    ]);
    $releaseBookingId = (int)$conn->lastInsertId();
    $releaseCredit = bookingReserveSubscriptionCredit($conn, $memberId, $today, true);
    $assert($releaseCredit !== null, 'The second subscription credit was not available.');
    bookingLinkReservedCredit($conn, (int)$releaseCredit['id'], $releaseBookingId, $users['member']);
    bookingReleaseReservedCredit($conn, $releaseBookingId, $users['member']);
    $creditState = $conn->prepare('SELECT status, applied_booking_id FROM subscription_booking_credits WHERE id = :id');
    $creditState->execute([':id' => (int)$releaseCredit['id']]);
    $releasedState = $creditState->fetch(PDO::FETCH_ASSOC);
    $assert($releasedState['status'] === 'available' && $releasedState['applied_booking_id'] === null, 'A released booking credit was not returned to the available pool.');

    $expiredPaymentInsert = $conn->prepare(
        "INSERT INTO payments (member_id, amount, payment_type, status, payment_date, payment_method, verification_status)
         VALUES (:member_id, '800.00', 'monthly_subscription', 'paid', :payment_date, 'cash', 'not_required')"
    );
    $expiredPaymentInsert->execute([':member_id' => $memberId, ':payment_date' => $today]);
    $expiredPaymentId = (int)$conn->lastInsertId();
    $expiredEnd = $now->modify('-1 day')->format('Y-m-d');
    $periodInsert->execute([
        ':member_id' => $memberId,
        ':payment_id' => $expiredPaymentId,
        ':duration_months' => 1,
        ':starts_on' => $now->modify('-1 month')->format('Y-m-d'),
        ':ends_on' => $expiredEnd,
    ]);
    grantMonthlySubscriptionBookingCredits(
        $conn,
        $memberId,
        $expiredPaymentId,
        (int)$conn->lastInsertId(),
        $now->modify('-1 month')->format('Y-m-d'),
        $expiredEnd
    );
    $expiredCountBefore = (int)$conn->query(
        "SELECT COUNT(*) FROM subscription_booking_credits WHERE status = 'expired'"
    )->fetchColumn();
    expireAvailableSubscriptionBookingCredits($conn);
    $expiredCountAfter = (int)$conn->query(
        "SELECT COUNT(*) FROM subscription_booking_credits WHERE status = 'expired'"
    )->fetchColumn();
    $assert($expiredCountAfter - $expiredCountBefore === 2, 'Expired subscription credits were not transitioned.');
    $assert(
        bookingReserveSubscriptionCredit($conn, $memberId, $expiredEnd, true) === null,
        'An expired subscription credit was available for booking.'
    );

    $expiredBookingInsert = $conn->prepare(
        "INSERT INTO bookings
            (booking_code, member_id, trainer_id, booking_date, start_time, end_time, session_type, status, payment_deadline_at)
         VALUES (:code, :member_id, :trainer_id, :booking_date, '11:00:00', '12:00:00', 'Personal Training', 'awaiting_payment', :deadline)"
    );
    $expiredBookingInsert->execute([
        ':code' => 'BK-' . strtoupper($suffix) . '-2',
        ':member_id' => $memberId,
        ':trainer_id' => $trainerId,
        ':booking_date' => $today,
        ':deadline' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
    ]);
    $expiredBookingId = (int)$conn->lastInsertId();
    $bookingPaymentInsert = $conn->prepare(
        "INSERT INTO payments
            (member_id, booking_id, amount, payment_type, status, payment_date, payment_method, verification_status)
         VALUES (:member_id, :booking_id, '1500.00', 'training_session', 'pending', :payment_date, 'cash', 'pending')"
    );
    $bookingPaymentInsert->execute([
        ':member_id' => $memberId,
        ':booking_id' => $expiredBookingId,
        ':payment_date' => $today,
    ]);
    $assert(expireUnpaidBookingPayments($conn) === 1, 'Expired unpaid booking was not transitioned.');
    $status = $conn->prepare('SELECT status FROM bookings WHERE id = :id');
    $status->execute([':id' => $expiredBookingId]);
    $assert($status->fetchColumn() === 'expired', 'Unpaid booking did not reach expired state.');
    $status = $conn->prepare('SELECT status FROM payments WHERE booking_id = :id AND payment_type = \'training_session\'');
    $status->execute([':id' => $expiredBookingId]);
    $assert($status->fetchColumn() === 'failed', 'Unpaid booking payment was not marked failed.');

    $conn->rollBack();
    echo "Passed {$assertions} disposable database assertions.\n";
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

?>
