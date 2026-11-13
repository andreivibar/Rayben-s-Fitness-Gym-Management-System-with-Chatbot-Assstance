<?php

function bookingPriceForSession(PDO $conn, int $sessionTypeId, string $at): array
{
    $stmt = $conn->prepare(
        'SELECT id, amount, currency
         FROM booking_session_prices
         WHERE session_type_id = :session_type_id
           AND effective_from <= :effective_at
           AND (retired_at IS NULL OR retired_at > :effective_at)
         ORDER BY effective_from DESC, id DESC
         LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([
        ':session_type_id' => $sessionTypeId,
        ':effective_at' => $at,
    ]);
    $price = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$price) {
        throw new RuntimeException('A price has not been configured for every selected session type.', 409);
    }
    return $price;
}

function bookingReserveSubscriptionCredit(PDO $conn, int $memberId, string $bookingDate, bool $eligible): ?array
{
    if (!$eligible) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT c.id, g.starts_on, g.ends_on
         FROM subscription_booking_credits c
         JOIN subscription_booking_credit_grants g ON g.id = c.grant_id
         WHERE g.member_id = :member_id
           AND g.starts_on <= :booking_date AND g.ends_on >= :booking_date
           AND c.status = 'available'
         ORDER BY g.ends_on, g.id, c.credit_number
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':member_id' => $memberId, ':booking_date' => $bookingDate]);
    $credit = $stmt->fetch(PDO::FETCH_ASSOC);
    return $credit ?: null;
}

function bookingLinkReservedCredit(PDO $conn, int $creditId, int $bookingId, int $actorUserId): void
{
    $update = $conn->prepare(
        "UPDATE subscription_booking_credits
         SET status = 'reserved', applied_booking_id = :booking_id
         WHERE id = :id AND status = 'available' AND applied_booking_id IS NULL"
    );
    $update->execute([':booking_id' => $bookingId, ':id' => $creditId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The available booking credit was reserved by another request.', 409);
    }
    bookingRecordCreditEvent($conn, $creditId, $bookingId, 'reserved', 'reserved:' . $bookingId, $actorUserId);
}

function bookingRecordCreditEvent(
    PDO $conn,
    int $creditId,
    ?int $bookingId,
    string $eventType,
    string $eventKey,
    ?int $actorUserId,
    ?string $details = null
): void {
    $stmt = $conn->prepare(
        'INSERT INTO subscription_booking_credit_events
            (credit_id, booking_id, event_type, event_key, actor_user_id, details)
         VALUES (:credit_id, :booking_id, :event_type, :event_key, :actor_user_id, :details)'
    );
    $stmt->execute([
        ':credit_id' => $creditId,
        ':booking_id' => $bookingId,
        ':event_type' => $eventType,
        ':event_key' => $eventKey,
        ':actor_user_id' => $actorUserId,
        ':details' => $details,
    ]);
}

function bookingApiLookupPaidBookingPayment(PDO $conn, int $bookingId): ?array
{
    $stmt = $conn->prepare(
        "SELECT p.id, p.member_id, p.amount, p.payment_method, p.payment_channel, p.status, p.booking_id, p.payment_type
         FROM payments p
         WHERE p.booking_id = :booking_id AND p.payment_type = 'training_session' AND p.status = 'paid'
         ORDER BY p.id DESC
         LIMIT 1"
    );
    $stmt->execute([':booking_id' => $bookingId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    return $payment ?: null;
}

function bookingApiCreateRefundRequest(
    PDO $conn,
    int $bookingId,
    int $paymentId,
    int $memberId,
    string $paymentMethod,
    float $amount,
    int $actorUserId,
    string $reason
): void {
    $existing = $conn->prepare(
        'SELECT id, booking_id, amount, status FROM booking_refunds
         WHERE payment_id = :payment_id LIMIT 1 FOR UPDATE'
    );
    $existing->execute([':payment_id' => $paymentId]);
    $existingRefund = $existing->fetch(PDO::FETCH_ASSOC);
    if ($existingRefund) {
        if ((int)$existingRefund['booking_id'] !== $bookingId
            || (int)round((float)$existingRefund['amount'] * 100) !== (int)round($amount * 100)) {
            throw new RuntimeException('An existing refund does not match the original payment amount.', 409);
        }
        return;
    }
    if ((int)round($amount * 100) < 1) {
        throw new RuntimeException('A refund must be greater than zero.', 409);
    }

    $paymentStmt = $conn->prepare(
        "SELECT amount, status, payment_type, payment_method
         FROM payments WHERE id = :id AND booking_id = :booking_id FOR UPDATE"
    );
    $paymentStmt->execute([':id' => $paymentId, ':booking_id' => $bookingId]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment || $payment['status'] !== 'paid' || $payment['payment_type'] !== 'training_session'
        || !in_array(strtolower(trim((string)$payment['payment_method'])), ['cash', 'gcash'], true)
        || strtolower(trim($paymentMethod)) !== strtolower(trim((string)$payment['payment_method']))
        || (int)round((float)$payment['amount'] * 100) !== (int)round($amount * 100)) {
        throw new RuntimeException('Only a verified booking payment can be refunded, and refunds must equal the amount paid.', 409);
    }

    $stmt = $conn->prepare(
        'INSERT INTO booking_refunds
            (booking_id, payment_id, member_id, amount, payment_method, status, request_reason, created_by_user_id, created_at)
         VALUES (:booking_id, :payment_id, :member_id, :amount, :payment_method, "pending", :reason, :actor_user_id, NOW())'
    );
    $stmt->execute([
        ':booking_id' => $bookingId,
        ':payment_id' => $paymentId,
        ':member_id' => $memberId,
        ':amount' => number_format($amount, 2, '.', ''),
        ':payment_method' => $paymentMethod,
        ':reason' => $reason !== '' ? $reason : 'Requested by booking cancellation policy.',
        ':actor_user_id' => $actorUserId,
    ]);
}

function bookingApiCreateCancellationAudit(
    PDO $conn,
    int $bookingId,
    string $previousStatus,
    string $newStatus,
    int $actorUserId,
    string $actorRole,
    string $reason,
    string $category,
    bool $refundEligible,
    bool $creditRestoration,
    ?int $paymentId,
    ?int $creditId
): void {
    $stmt = $conn->prepare(
        'INSERT INTO booking_cancellation_history
            (booking_id, previous_status, new_status, initiating_user_id, initiating_role,
             cancellation_reason, cancellation_category, cancelled_at, refund_eligible,
             credit_restoration_decision, related_payment_id, related_credit_id)
         VALUES (:booking_id, :previous_status, :new_status, :actor_user_id, :actor_role,
                 :reason, :category, NOW(), :refund_eligible, :credit_restoration, :payment_id, :credit_id)'
    );
    $stmt->execute([
        ':booking_id' => $bookingId,
        ':previous_status' => $previousStatus,
        ':new_status' => $newStatus,
        ':actor_user_id' => $actorUserId,
        ':actor_role' => $actorRole,
        ':reason' => $reason,
        ':category' => $category,
        ':refund_eligible' => $refundEligible ? 1 : 0,
        ':credit_restoration' => $creditRestoration ? 1 : 0,
        ':payment_id' => $paymentId,
        ':credit_id' => $creditId,
    ]);
}

function bookingApiRestoreConsumedCredit(PDO $conn, int $bookingId, int $actorUserId): ?int
{
    $stmt = $conn->prepare(
        "SELECT c.id, g.starts_on, g.ends_on
         FROM subscription_booking_credits c
         JOIN subscription_booking_credit_grants g ON g.id = c.grant_id
         WHERE c.applied_booking_id = :booking_id AND c.status = 'consumed'
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':booking_id' => $bookingId]);
    $credit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$credit) {
        return null;
    }
    $creditId = (int)$credit['id'];
    $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $expired = $today > (string)$credit['ends_on'];
    $status = $expired ? 'expired' : 'available';

    $update = $conn->prepare(
        "UPDATE subscription_booking_credits
         SET status = :status, applied_booking_id = NULL
         WHERE id = :id AND status = 'consumed' AND applied_booking_id = :booking_id"
    );
    $update->execute([':status' => $status, ':id' => $creditId, ':booking_id' => $bookingId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The original booking credit could not be restored.', 409);
    }
    bookingRecordCreditEvent(
        $conn,
        $creditId,
        $bookingId,
        $expired ? 'expired' : 'released',
        ($expired ? 'restoration-expired:' : 'restored:') . $bookingId . ':' . $creditId,
        $actorUserId,
        $expired ? 'cancellation_restoration_after_grant_expiry' : 'restored_after_cancellation'
    );
    return $creditId;
}

function bookingForfeitReservedCredit(PDO $conn, int $bookingId, int $actorUserId, string $reason): ?int
{
    $stmt = $conn->prepare(
        "SELECT id FROM subscription_booking_credits
         WHERE applied_booking_id = :booking_id AND status = 'reserved'
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':booking_id' => $bookingId]);
    $creditId = (int)$stmt->fetchColumn();
    if ($creditId < 1) {
        return null;
    }

    $update = $conn->prepare(
        "UPDATE subscription_booking_credits SET status = 'consumed'
         WHERE id = :id AND status = 'reserved' AND applied_booking_id = :booking_id"
    );
    $update->execute([':id' => $creditId, ':booking_id' => $bookingId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The reserved booking credit could not be forfeited.', 409);
    }
    bookingRecordCreditEvent(
        $conn,
        $creditId,
        $bookingId,
        'consumed',
        'forfeited:' . $bookingId . ':' . $creditId,
        $actorUserId,
        $reason
    );
    return $creditId;
}

function bookingApiReleaseReservationForNoShow(PDO $conn, int $bookingId, int $actorUserId): ?int
{
    $stmt = $conn->prepare(
        "SELECT c.id
         FROM subscription_booking_credits c
         WHERE c.applied_booking_id = :booking_id AND c.status = 'reserved'
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':booking_id' => $bookingId]);
    $creditId = (int)$stmt->fetchColumn();
    if ($creditId < 1) {
        return null;
    }

    $consume = $conn->prepare(
        "UPDATE subscription_booking_credits
         SET status = 'consumed'
         WHERE id = :id AND status = 'reserved' AND applied_booking_id = :booking_id"
    );
    $consume->execute([':id' => $creditId, ':booking_id' => $bookingId]);
    if ($consume->rowCount() !== 1) {
        throw new RuntimeException('The booking credit could not be forfeited for the no-show.', 409);
    }
    bookingRecordCreditEvent($conn, $creditId, $bookingId, 'consumed', 'forfeited:' . $bookingId . ':' . $creditId, $actorUserId, 'no_show_forfeiture');
    return $creditId;
}

function bookingConsumeReservedCredit(PDO $conn, int $bookingId, int $actorUserId): bool
{
    $stmt = $conn->prepare(
        "SELECT id FROM subscription_booking_credits
         WHERE applied_booking_id = :booking_id AND status = 'reserved'
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':booking_id' => $bookingId]);
    $creditId = (int)$stmt->fetchColumn();
    if (!$creditId) {
        return false;
    }
    $update = $conn->prepare(
        "UPDATE subscription_booking_credits SET status = 'consumed'
         WHERE id = :id AND status = 'reserved' AND applied_booking_id = :booking_id"
    );
    $update->execute([':id' => $creditId, ':booking_id' => $bookingId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The reserved booking credit could not be consumed.', 409);
    }
    bookingRecordCreditEvent($conn, $creditId, $bookingId, 'consumed', 'consumed:' . $bookingId, $actorUserId);
    return true;
}

function bookingAppliedCreditCoversDate(PDO $conn, int $bookingId, string $bookingDate, string $status): ?bool
{
    if (!in_array($status, ['reserved', 'consumed'], true)) {
        throw new InvalidArgumentException('Invalid booking-credit status.');
    }
    $stmt = $conn->prepare(
        "SELECT g.starts_on, g.ends_on
         FROM subscription_booking_credits c
         JOIN subscription_booking_credit_grants g ON g.id = c.grant_id
         WHERE c.applied_booking_id = :booking_id AND c.status = :status
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':booking_id' => $bookingId, ':status' => $status]);
    $grant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grant) {
        return null;
    }
    return $bookingDate >= (string)$grant['starts_on'] && $bookingDate <= (string)$grant['ends_on'];
}

function bookingReservedCreditCoversDate(PDO $conn, int $bookingId, string $bookingDate): ?bool
{
    return bookingAppliedCreditCoversDate($conn, $bookingId, $bookingDate, 'reserved');
}

function bookingConsumedCreditCoversDate(PDO $conn, int $bookingId, string $bookingDate): ?bool
{
    return bookingAppliedCreditCoversDate($conn, $bookingId, $bookingDate, 'consumed');
}

function bookingReleaseReservedCredit(PDO $conn, int $bookingId, ?int $actorUserId): ?int
{
    $stmt = $conn->prepare(
        "SELECT c.id, g.ends_on
         FROM subscription_booking_credits c
         JOIN subscription_booking_credit_grants g ON g.id = c.grant_id
         WHERE c.applied_booking_id = :booking_id AND c.status = 'reserved'
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([':booking_id' => $bookingId]);
    $credit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$credit) {
        return null;
    }
    $expired = (string)$credit['ends_on'] < (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $status = $expired ? 'expired' : 'available';
    $update = $conn->prepare(
        'UPDATE subscription_booking_credits
         SET status = :status, applied_booking_id = NULL
         WHERE id = :id AND status = \'reserved\' AND applied_booking_id = :booking_id'
    );
    $update->execute([
        ':status' => $status,
        ':id' => (int)$credit['id'],
        ':booking_id' => $bookingId,
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The reserved booking credit could not be released.', 409);
    }
    bookingRecordCreditEvent(
        $conn,
        (int)$credit['id'],
        $bookingId,
        $status === 'expired' ? 'expired' : 'released',
        ($status === 'expired' ? 'expired:' : 'released:') . $bookingId . ':' . (int)$credit['id'],
        $actorUserId
    );
    return (int)$credit['id'];
}

function grantMonthlySubscriptionBookingCredits(
    PDO $conn,
    int $memberId,
    int $paymentId,
    int $periodId,
    string $startsOn,
    string $endsOn
): void {
    if (!$conn->inTransaction()) {
        throw new LogicException('Booking credits must be granted in the subscription payment transaction.');
    }
    $paymentStmt = $conn->prepare(
        "SELECT id FROM payments
         WHERE id = :id AND member_id = :member_id
           AND payment_type = 'monthly_subscription' AND status = 'paid'
         FOR UPDATE"
    );
    $paymentStmt->execute([':id' => $paymentId, ':member_id' => $memberId]);
    if (!$paymentStmt->fetchColumn()) {
        throw new RuntimeException('Only a successfully paid monthly subscription can grant booking credits.');
    }
    $periodStmt = $conn->prepare(
        'SELECT id FROM membership_subscription_periods
         WHERE id = :id AND member_id = :member_id AND payment_id = :payment_id
           AND starts_on = :starts_on AND ends_on = :ends_on
         FOR UPDATE'
    );
    $periodStmt->execute([
        ':id' => $periodId,
        ':member_id' => $memberId,
        ':payment_id' => $paymentId,
        ':starts_on' => $startsOn,
        ':ends_on' => $endsOn,
    ]);
    if (!$periodStmt->fetchColumn()) {
        throw new RuntimeException('Booking credits must match the period purchased by the payment.');
    }
    $existing = $conn->prepare('SELECT id FROM subscription_booking_credit_grants WHERE payment_id = :payment_id LIMIT 1 FOR UPDATE');
    $existing->execute([':payment_id' => $paymentId]);
    if ($existing->fetchColumn()) {
        return;
    }
    $grant = $conn->prepare(
        'INSERT INTO subscription_booking_credit_grants
            (member_id, payment_id, subscription_period_id, starts_on, ends_on, credits_granted)
         VALUES (:member_id, :payment_id, :period_id, :starts_on, :ends_on, 2)'
    );
    $grant->execute([
        ':member_id' => $memberId,
        ':payment_id' => $paymentId,
        ':period_id' => $periodId,
        ':starts_on' => $startsOn,
        ':ends_on' => $endsOn,
    ]);
    $grantId = (int)$conn->lastInsertId();
    $credit = $conn->prepare(
        "INSERT INTO subscription_booking_credits (grant_id, credit_number, status)
         VALUES (:grant_id, :credit_number, 'available')"
    );
    for ($number = 1; $number <= 2; $number++) {
        $credit->execute([':grant_id' => $grantId, ':credit_number' => $number]);
        $creditId = (int)$conn->lastInsertId();
        bookingRecordCreditEvent($conn, $creditId, null, 'granted', 'granted:' . $paymentId . ':' . $number, null);
    }
}

function expireAvailableSubscriptionBookingCredits(PDO $conn): int
{
    $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $stmt = $conn->prepare(
        "SELECT c.id
         FROM subscription_booking_credits c
         JOIN subscription_booking_credit_grants g ON g.id = c.grant_id
         WHERE c.status = 'available' AND g.ends_on < :today
         ORDER BY c.id FOR UPDATE"
    );
    $stmt->execute([':today' => $today]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) {
        return 0;
    }
    $update = $conn->prepare(
        "UPDATE subscription_booking_credits SET status = 'expired'
         WHERE id = :id AND status = 'available'"
    );
    foreach ($ids as $id) {
        $update->execute([':id' => $id]);
        if ($update->rowCount() === 1) {
            bookingRecordCreditEvent($conn, $id, null, 'expired', 'expired:' . $id, null);
        }
    }
    return count($ids);
}

function expireUnpaidBookingPayments(PDO $conn): int
{
    $startedTransaction = !$conn->inTransaction();
    if ($startedTransaction) {
        $conn->beginTransaction();
    }
    expireAvailableSubscriptionBookingCredits($conn);
    try {
        $stmt = $conn->prepare(
            "UPDATE bookings b
             SET b.status = 'expired', b.updated_at = NOW()
             WHERE b.status = 'awaiting_payment'
               AND b.payment_deadline_at IS NOT NULL AND b.payment_deadline_at <= NOW()
               AND NOT EXISTS (
                   SELECT 1 FROM payments p
                   WHERE p.booking_id = b.id AND p.payment_type = 'training_session'
                     AND p.payment_method = 'gcash' AND p.status = 'pending'
                     AND p.verification_status = 'pending' AND p.created_at <= b.payment_deadline_at
               )"
        );
        $stmt->execute();
        $expiredCount = $stmt->rowCount();
        $payments = $conn->prepare(
            "UPDATE payments p
             JOIN bookings b ON b.id = p.booking_id
             SET p.status = 'failed', p.verification_status = 'rejected', p.verified_at = NOW()
             WHERE b.status = 'expired' AND p.payment_type = 'training_session'
               AND p.status = 'pending' AND p.verification_status = 'pending'
               AND NOT (p.payment_method = 'gcash' AND p.created_at <= b.payment_deadline_at)"
        );
        $payments->execute();
        if ($startedTransaction) {
            $conn->commit();
        }
        return $expiredCount;
    } catch (Throwable $e) {
        if ($startedTransaction && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function expirePendingBookingResponses(PDO $conn): int
{
    if (!$conn->inTransaction()) {
        $conn->beginTransaction();
    } else {
        throw new LogicException('Pending booking response expiration requires its own transaction.');
    }

    try {
        $stmt = $conn->query(
            "SELECT id, booking_code, member_id, status, response_deadline
             FROM bookings
             WHERE status = 'pending' AND response_deadline IS NOT NULL
               AND response_deadline <= NOW()
             ORDER BY response_deadline, id
             FOR UPDATE"
        );
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $expiredCount = 0;
        $memberStmt = $conn->prepare('SELECT user_id FROM members WHERE id = :id LIMIT 1');
        $historyStmt = $conn->prepare(
            'INSERT INTO booking_cancellation_history
                (booking_id, previous_status, new_status, initiating_user_id, initiating_role,
                 cancellation_reason, cancellation_category, cancelled_at, refund_eligible,
                 credit_restoration_decision, related_payment_id, related_credit_id)
             VALUES (:booking_id, "pending", "expired", NULL, "system",
                     :reason, "staff_timeout", NOW(), 0, 0, NULL, :credit_id)'
        );
        $updateStmt = $conn->prepare(
            "UPDATE bookings
             SET status = 'expired', cancellation_reason = :reason, cancelled_at = NOW(),
                 refund_eligible = 0, credit_restoration_decision = 0,
                 cancelled_by_user_id = NULL, cancelled_by_role = 'system', updated_at = NOW()
             WHERE id = :id AND status = 'pending'
               AND response_deadline IS NOT NULL AND response_deadline <= NOW()"
        );

        foreach ($bookings as $booking) {
            $bookingId = (int)$booking['id'];
            $reason = 'Automatically expired because no Trainer or Admin decision was made within 24 hours.';
            $updateStmt->execute([':reason' => $reason, ':id' => $bookingId]);
            if ($updateStmt->rowCount() !== 1) {
                continue;
            }
            $creditId = bookingReleaseReservedCredit($conn, $bookingId, null);
            $historyStmt->execute([
                ':booking_id' => $bookingId,
                ':reason' => $reason,
                ':credit_id' => $creditId,
            ]);
            $memberStmt->execute([':id' => (int)$booking['member_id']]);
            $memberUserId = (int)$memberStmt->fetchColumn();
            if ($memberUserId > 0 && function_exists('notificationWorkflowCreate')) {
                notificationWorkflowCreate(
                    $conn,
                    $memberUserId,
                    'booking_expired',
                    'Booking request expired',
                    "Booking {$booking['booking_code']} expired because no Trainer or Admin responded within 24 hours.",
                    $bookingId
                );
            }
            $expiredCount++;
        }
        $conn->commit();
        return $expiredCount;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
