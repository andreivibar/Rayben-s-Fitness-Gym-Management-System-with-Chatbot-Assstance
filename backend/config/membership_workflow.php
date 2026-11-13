<?php

function manilaToday(): DateTimeImmutable {
    return new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
}

function addCalendarMonths(DateTimeImmutable $date, int $months): DateTimeImmutable {
    if ($months < 1 || $months > 120) {
        throw new InvalidArgumentException('Membership duration must be between 1 and 120 months.');
    }

    $timezone = new DateTimeZone('Asia/Manila');
    $firstDay = new DateTimeImmutable($date->format('Y-m-01'), $timezone);
    $targetMonth = $firstDay->modify('+' . $months . ' months');
    $lastDay = (int)$targetMonth->modify('last day of this month')->format('j');
    $day = min((int)$date->format('j'), $lastDay);

    return $targetMonth->setDate(
        (int)$targetMonth->format('Y'),
        (int)$targetMonth->format('n'),
        $day
    );
}

function calculateSubscriptionPeriod(?string $existingEnd, int $durationMonths, ?string $existingStart = null): array {
    $today = manilaToday();
    $existingDate = null;
    if ($existingEnd && $existingEnd !== '0000-00-00') {
        $existingDate = new DateTimeImmutable($existingEnd, new DateTimeZone('Asia/Manila'));
    }

    $startsOn = $existingDate && $existingDate >= $today
        ? $existingDate->modify('+1 day')
        : $today;
    $endsOn = addCalendarMonths($startsOn, $durationMonths);
    $currentStart = $existingStart && $existingStart !== '0000-00-00'
        ? new DateTimeImmutable($existingStart, new DateTimeZone('Asia/Manila'))
        : null;

    return [
        'starts_on' => $startsOn->format('Y-m-d'),
        'ends_on' => $endsOn->format('Y-m-d'),
        'membership_start' => $existingDate && $existingDate >= $today && $currentStart
            ? $currentStart->format('Y-m-d')
            : $startsOn->format('Y-m-d'),
    ];
}

function calculateNextPaidSubscriptionPeriod(
    PDO $conn,
    int $memberId,
    ?string $membershipEnd,
    int $durationMonths,
    ?string $membershipStart = null
): array {
    $periodStmt = $conn->prepare(
        'SELECT MAX(ends_on) FROM membership_subscription_periods WHERE member_id = :member_id'
    );
    $periodStmt->execute([':member_id' => $memberId]);
    $latestPeriodEnd = trim((string)$periodStmt->fetchColumn());
    if ($latestPeriodEnd !== '' && ($membershipEnd === null || $latestPeriodEnd > $membershipEnd)) {
        $membershipEnd = $latestPeriodEnd;
    }

    return calculateSubscriptionPeriod($membershipEnd, $durationMonths, $membershipStart);
}

function calculateNextDailyPassVisitDate(?string $latestVisitDate, DateTimeImmutable $today): string {
    $manilaToday = $today->setTimezone(new DateTimeZone('Asia/Manila'))->setTime(0, 0);
    if (!$latestVisitDate || $latestVisitDate < $manilaToday->format('Y-m-d')) {
        return $manilaToday->format('Y-m-d');
    }

    $latest = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $latestVisitDate,
        new DateTimeZone('Asia/Manila')
    );
    if (!$latest || $latest->format('Y-m-d') !== $latestVisitDate) {
        throw new InvalidArgumentException('Existing pass visit date is invalid.');
    }

    return $latest->modify('+1 day')->format('Y-m-d');
}

function insertSubscriptionPeriod(
    PDO $conn,
    int $memberId,
    ?int $planId,
    ?int $registrationRequestId,
    ?int $paymentId,
    int $durationMonths,
    string $startsOn,
    string $endsOn,
    string $source
): int {
    $stmt = $conn->prepare(
        "INSERT INTO membership_subscription_periods
            (member_id, membership_plan_id, registration_request_id, payment_id, duration_months,
             starts_on, ends_on, status, source)
         VALUES
            (:member_id, :plan_id, :request_id, :payment_id, :duration_months,
             :starts_on, :ends_on, 'active', :source)"
    );
    $stmt->execute([
        ':member_id' => $memberId,
        ':plan_id' => $planId,
        ':request_id' => $registrationRequestId,
        ':payment_id' => $paymentId,
        ':duration_months' => $durationMonths,
        ':starts_on' => $startsOn,
        ':ends_on' => $endsOn,
        ':source' => $source,
    ]);
    return (int)$conn->lastInsertId();
}

function addMemberWorkflowNotification(
    PDO $conn,
    int $userId,
    string $type,
    string $title,
    string $message,
    string $membershipEnd
): void {
    $existing = $conn->prepare(
        'SELECT id FROM notifications
         WHERE user_id = :user_id AND notification_type = :type
           AND reminder_days = 0 AND membership_end = :membership_end LIMIT 1'
    );
    $existing->execute([
        ':user_id' => $userId,
        ':type' => $type,
        ':membership_end' => $membershipEnd,
    ]);
    if ($existing->fetch(PDO::FETCH_ASSOC)) {
        return;
    }

    $insert = $conn->prepare(
        'INSERT INTO notifications
            (user_id, title, message, notification_type, reminder_days, membership_end, is_read)
         VALUES (:user_id, :title, :message, :type, 0, :membership_end, 0)'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':title' => mb_substr($title, 0, 150),
        ':message' => mb_substr($message, 0, 500),
        ':type' => $type,
        ':membership_end' => $membershipEnd,
    ]);
}
