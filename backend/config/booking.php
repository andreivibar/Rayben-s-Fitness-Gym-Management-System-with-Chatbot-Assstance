<?php

require_once __DIR__ . '/booking_pricing.php';

function bookingGetMemberId(PDO $conn, array $user): int
{
    $stmt = $conn->prepare(
        'SELECT id FROM members WHERE user_id = :user_id LIMIT 1'
    );
    $stmt->execute([':user_id' => (int)($user['id'] ?? 0)]);
    return (int)$stmt->fetchColumn();
}

function bookingGetTrainerId(PDO $conn, array $user): int
{
    $stmt = $conn->prepare('SELECT id FROM trainers WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => (int)($user['id'] ?? 0)]);
    return (int)$stmt->fetchColumn();
}

function bookingValidDate(string $date): bool
{
    $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
    return $value !== false && $value->format('Y-m-d') === $date;
}

function bookingGetMaxAdvanceDays(PDO $conn): int
{
    $table = $conn->query(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_configuration'"
    )->fetchColumn();
    if (!$table) {
        return 90;
    }

    $stmt = $conn->query('SELECT max_advance_days FROM booking_configuration WHERE id = 1');
    $days = $stmt->fetchColumn();
    return $days === false ? 90 : (int)$days;
}

function bookingAssertWithinAdvanceWindow(PDO $conn, string $date): void
{
    $days = bookingGetMaxAdvanceDays($conn);
    $timezone = new DateTimeZone('Asia/Manila');
    $lastBookableDate = (new DateTimeImmutable('today', $timezone))->modify("+{$days} days");
    $requestedDate = new DateTimeImmutable($date, $timezone);
    if ($requestedDate > $lastBookableDate) {
        throw new RuntimeException("Bookings can be requested no more than {$days} days ahead.", 400);
    }
}

function bookingValidTime(string $time): bool
{
    return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time);
}

function bookingTimeMinutes(string $time): int
{
    [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
    return ($hour * 60) + $minute;
}

function bookingFormatTime(int $minutes): string
{
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function bookingAppointmentEnd(string $start, int $durationMinutes): string
{
    $startMinutes = bookingTimeMinutes($start);
    if ($durationMinutes < 1 || $startMinutes + $durationMinutes > 1440) {
        throw new RuntimeException('The appointment duration must fit within the selected day.', 400);
    }
    return bookingFormatTime($startMinutes + $durationMinutes);
}

function bookingValidateFutureAppointment(string $date, string $start, int $durationMinutes): string
{
    if (!bookingValidDate($date) || !bookingValidTime($start)) {
        throw new RuntimeException('Choose a valid booking date and start time.', 400);
    }
    $start = substr($start, 0, 5);
    $end = bookingAppointmentEnd($start, $durationMinutes);
    $timezone = new DateTimeZone('Asia/Manila');
    $startAt = new DateTimeImmutable($date . ' ' . $start, $timezone);
    if ($startAt <= new DateTimeImmutable('now', $timezone)) {
        throw new RuntimeException('The requested booking date and time must be in the future.', 400);
    }
    return $end;
}

function bookingAssertWithinGymHours(PDO $conn, string $date, string $start, string $end): void
{
    $day = (new DateTimeImmutable($date, new DateTimeZone('Asia/Manila')))->format('l');
    $stmt = $conn->prepare(
        'SELECT is_open, opens_at, closes_at FROM gym_operating_hours WHERE day_of_week = :day LIMIT 1'
    );
    $stmt->execute([':day' => $day]);
    $hours = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hours || (int)$hours['is_open'] !== 1 || empty($hours['opens_at']) || empty($hours['closes_at'])
        || bookingTimeMinutes($start) < bookingTimeMinutes((string)$hours['opens_at'])
        || bookingTimeMinutes($end) > bookingTimeMinutes((string)$hours['closes_at'])) {
        throw new RuntimeException('The appointment must fit within the gym operating hours.', 409);
    }
}

function bookingIsWithinNormalTrainerShift(PDO $conn, int $trainerId, string $date, string $start, string $end): bool
{
    $day = (new DateTimeImmutable($date, new DateTimeZone('Asia/Manila')))->format('l');
    $startMinutes = bookingTimeMinutes($start);
    $endMinutes = bookingTimeMinutes($end);
    $stmt = $conn->prepare(
        'SELECT start_time, end_time, lunch_start, lunch_end
         FROM trainer_availability
         WHERE trainer_id = :trainer_id AND day_of_week = :day AND status = "active"'
    );
    $stmt->execute([':trainer_id' => $trainerId, ':day' => $day]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $shift) {
        if ($startMinutes < bookingTimeMinutes((string)$shift['start_time'])
            || $endMinutes > bookingTimeMinutes((string)$shift['end_time'])) {
            continue;
        }
        if ($shift['lunch_start'] && $shift['lunch_end']
            && $startMinutes < bookingTimeMinutes((string)$shift['lunch_end'])
            && $endMinutes > bookingTimeMinutes((string)$shift['lunch_start'])) {
            continue;
        }
        return true;
    }
    return false;
}

function bookingAssertNoConfirmedConflict(
    PDO $conn,
    int $trainerId,
    int $memberId,
    string $date,
    string $start,
    string $end,
    int $excludeBookingId = 0
): void {
    $trainerStmt = $conn->prepare(
        'SELECT id FROM bookings
         WHERE trainer_id = :trainer_id AND booking_date = :booking_date
           AND status IN ("confirmed", "awaiting_payment")
           AND id <> :exclude_booking_id AND start_time < :end_time AND end_time > :start_time
         LIMIT 1'
    );
    $trainerStmt->execute([
        ':trainer_id' => $trainerId,
        ':booking_date' => $date,
        ':exclude_booking_id' => $excludeBookingId,
        ':start_time' => $start,
        ':end_time' => $end,
    ]);
    if ($trainerStmt->fetchColumn()) {
        throw new RuntimeException('The trainer already has a confirmed appointment during that time.', 409);
    }

    $memberStmt = $conn->prepare(
        'SELECT id FROM bookings
         WHERE member_id = :member_id AND booking_date = :booking_date
           AND status IN ("confirmed", "awaiting_payment")
           AND id <> :exclude_booking_id AND start_time < :end_time AND end_time > :start_time
         LIMIT 1'
    );
    $memberStmt->execute([
        ':member_id' => $memberId,
        ':booking_date' => $date,
        ':exclude_booking_id' => $excludeBookingId,
        ':start_time' => $start,
        ':end_time' => $end,
    ]);
    if ($memberStmt->fetchColumn()) {
        throw new RuntimeException('The member already has a confirmed appointment during that time.', 409);
    }
}

function bookingSubtractInterval(array $intervals, int $blockStart, int $blockEnd): array
{
    $remaining = [];
    foreach ($intervals as [$start, $end]) {
        if ($blockEnd <= $start || $blockStart >= $end) {
            $remaining[] = [$start, $end];
            continue;
        }
        if ($blockStart > $start) {
            $remaining[] = [$start, min($blockStart, $end)];
        }
        if ($blockEnd < $end) {
            $remaining[] = [max($blockEnd, $start), $end];
        }
    }
    return $remaining;
}

function bookingGetSessionType(PDO $conn, string $name): ?array
{
    $stmt = $conn->prepare(
        'SELECT name, duration_minutes
         FROM booking_session_types
         WHERE name = :name AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':name' => $name]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        return null;
    }
    $session['duration_minutes'] = (int)$session['duration_minutes'];
    return $session;
}

function bookingGetAvailableSlots(
    PDO $conn,
    int $trainerId,
    string $date,
    int $durationMinutes,
    int $memberId = 0,
    int $excludeBookingId = 0,
    ?string $requestedStartTime = null
): array {
    expireUnpaidBookingPayments($conn);
    if (!bookingValidDate($date) || $durationMinutes < 1 || $durationMinutes > 480) {
        return [];
    }
    $dateValue = new DateTimeImmutable($date, new DateTimeZone('Asia/Manila'));
    if ($dateValue < new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'))) {
        return [];
    }
    $day = $dateValue->format('l');

    $hoursStmt = $conn->prepare(
        'SELECT is_open, opens_at, closes_at FROM gym_operating_hours WHERE day_of_week = :day LIMIT 1'
    );
    $hoursStmt->execute([':day' => $day]);
    $hours = $hoursStmt->fetch(PDO::FETCH_ASSOC);
    if (!$hours || (int)$hours['is_open'] !== 1 || empty($hours['opens_at']) || empty($hours['closes_at'])) {
        return [];
    }
    $gymStart = bookingTimeMinutes((string)$hours['opens_at']);
    $gymEnd = bookingTimeMinutes((string)$hours['closes_at']);
    if ($gymEnd <= $gymStart) {
        return [];
    }

    $shiftStmt = $conn->prepare(
        'SELECT start_time, end_time, lunch_start, lunch_end
         FROM trainer_availability
         WHERE trainer_id = :trainer_id AND day_of_week = :day AND status = "active"
         ORDER BY start_time'
    );
    $shiftStmt->execute([':trainer_id' => $trainerId, ':day' => $day]);
    $intervals = [];
    foreach ($shiftStmt->fetchAll(PDO::FETCH_ASSOC) as $shift) {
        $start = max($gymStart, bookingTimeMinutes((string)$shift['start_time']));
        $end = min($gymEnd, bookingTimeMinutes((string)$shift['end_time']));
        if ($end > $start) {
            $intervals[] = [$start, $end];
            if ($shift['lunch_start'] && $shift['lunch_end']) {
                $intervals = bookingSubtractInterval(
                    $intervals,
                    bookingTimeMinutes((string)$shift['lunch_start']),
                    bookingTimeMinutes((string)$shift['lunch_end'])
                );
            }
        }
    }

    $exceptionStmt = $conn->prepare(
        'SELECT exception_type, start_time, end_time
         FROM trainer_schedule_exceptions
         WHERE trainer_id = :trainer_id AND exception_date = :exception_date AND status = "active"
         ORDER BY id'
    );
    $exceptionStmt->execute([':trainer_id' => $trainerId, ':exception_date' => $date]);
    $exceptions = $exceptionStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($exceptions as $exception) {
        if ($exception['exception_type'] === 'off') {
            $intervals = [];
        }
    }
    foreach ($exceptions as $exception) {
        if ($exception['exception_type'] === 'available' && $exception['start_time'] && $exception['end_time']) {
            $start = max($gymStart, bookingTimeMinutes((string)$exception['start_time']));
            $end = min($gymEnd, bookingTimeMinutes((string)$exception['end_time']));
            if ($end > $start) {
                $intervals[] = [$start, $end];
            }
        }
    }
    foreach ($exceptions as $exception) {
        if ($exception['exception_type'] === 'blocked' && $exception['start_time'] && $exception['end_time']) {
            $intervals = bookingSubtractInterval(
                $intervals,
                bookingTimeMinutes((string)$exception['start_time']),
                bookingTimeMinutes((string)$exception['end_time'])
            );
        }
    }

    usort($intervals, static function (array $first, array $second): int {
        return $first[0] <=> $second[0];
    });
    $mergedIntervals = [];
    foreach ($intervals as [$start, $end]) {
        $lastIndex = count($mergedIntervals) - 1;
        if ($lastIndex >= 0 && $start <= $mergedIntervals[$lastIndex][1]) {
            $mergedIntervals[$lastIndex][1] = max($mergedIntervals[$lastIndex][1], $end);
        } else {
            $mergedIntervals[] = [$start, $end];
        }
    }
    $intervals = $mergedIntervals;

    $busyStmt = $conn->prepare(
        'SELECT start_time, end_time
         FROM bookings
         WHERE trainer_id = :trainer_id AND booking_date = :booking_date
           AND status IN ("confirmed", "awaiting_payment")
           AND id <> :exclude_booking_id
         ORDER BY start_time'
    );
    $busyStmt->execute([
        ':trainer_id' => $trainerId,
        ':booking_date' => $date,
        ':exclude_booking_id' => $excludeBookingId,
    ]);
    foreach ($busyStmt->fetchAll(PDO::FETCH_ASSOC) as $booking) {
        $intervals = bookingSubtractInterval(
            $intervals,
            bookingTimeMinutes((string)$booking['start_time']),
            bookingTimeMinutes((string)$booking['end_time'])
        );
    }

    if ($memberId > 0) {
        $memberBusyStmt = $conn->prepare(
            'SELECT start_time, end_time
             FROM bookings
             WHERE member_id = :member_id AND booking_date = :booking_date
               AND status IN ("confirmed", "awaiting_payment")
               AND id <> :exclude_booking_id'
        );
        $memberBusyStmt->execute([
            ':member_id' => $memberId,
            ':booking_date' => $date,
            ':exclude_booking_id' => $excludeBookingId,
        ]);
        foreach ($memberBusyStmt->fetchAll(PDO::FETCH_ASSOC) as $booking) {
            $intervals = bookingSubtractInterval(
                $intervals,
                bookingTimeMinutes((string)$booking['start_time']),
                bookingTimeMinutes((string)$booking['end_time'])
            );
        }
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $minimumStart = $date === $now->format('Y-m-d') ? ($now->format('H') * 60 + (int)$now->format('i')) : 0;
    $slots = [];
    if ($requestedStartTime !== null) {
        if (!bookingValidTime($requestedStartTime)) {
            return [];
        }
        $slotStart = bookingTimeMinutes($requestedStartTime);
        if ($slotStart < $minimumStart) {
            return [];
        }
        foreach ($intervals as [$start, $end]) {
            if ($slotStart >= $start && $slotStart + $durationMinutes <= $end) {
                return [[
                    'start_time' => bookingFormatTime($slotStart),
                    'end_time' => bookingFormatTime($slotStart + $durationMinutes),
                    'duration_minutes' => $durationMinutes,
                ]];
            }
        }
        return [];
    }

    foreach ($intervals as [$start, $end]) {
        $slotStart = (int)(ceil(max($start, $minimumStart) / 15) * 15);
        for (; $slotStart + $durationMinutes <= $end; $slotStart += 15) {
            $slots[] = [
                'start_time' => bookingFormatTime($slotStart),
                'end_time' => bookingFormatTime($slotStart + $durationMinutes),
                'duration_minutes' => $durationMinutes,
            ];
        }
    }
    return $slots;
}

function bookingFindInvalidReservations(PDO $conn, ?int $trainerId = null): array
{
    $timezone = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $timezone);
    $today = $now->format('Y-m-d');
    $sql = 'SELECT id, trainer_id, member_id, booking_date, start_time, end_time
            FROM bookings
            WHERE status IN ("confirmed", "awaiting_payment") AND booking_date >= :today';
    $params = [':today' => $today];
    if ($trainerId !== null) {
        $sql .= ' AND trainer_id = :trainer_id';
        $params[':trainer_id'] = $trainerId;
    }
    $sql .= ' ORDER BY trainer_id, booking_date, start_time, id';
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $invalid = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $booking) {
        $date = (string)$booking['booking_date'];
        $start = substr((string)$booking['start_time'], 0, 5);
        if ($date === $today && $start <= $now->format('H:i')) {
            continue;
        }

        try {
            bookingAssertWithinGymHours(
                $conn,
                $date,
                $start,
                substr((string)$booking['end_time'], 0, 5)
            );
            bookingAssertNoConfirmedConflict(
                $conn,
                (int)$booking['trainer_id'],
                (int)$booking['member_id'],
                $date,
                $start,
                substr((string)$booking['end_time'], 0, 5),
                (int)$booking['id']
            );
        } catch (RuntimeException $validationIssue) {
            $invalid[] = (int)$booking['id'];
        }
    }
    return $invalid;
}

function bookingCreateNotification(PDO $conn, int $userId, int $bookingId, string $type, string $title, string $message): void
{
    notificationWorkflowCreate($conn, $userId, $type, $title, $message, $bookingId);
}

function bookingNotifyEvent(
    PDO $conn,
    array $booking,
    string $type,
    string $title,
    string $message,
    array $notifyRoles = [],
    ?int $excludeUserId = null
): void {
    $participantsStmt = $conn->prepare(
        'SELECT m.user_id AS member_user_id, t.user_id AS trainer_user_id
         FROM bookings b
         JOIN members m ON m.id = b.member_id
         LEFT JOIN trainers t ON t.id = b.trainer_id
         WHERE b.id = :booking_id LIMIT 1'
    );
    $participantsStmt->execute([':booking_id' => (int)$booking['id']]);
    $participants = $participantsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$participants) {
        throw new RuntimeException('Booking participants could not be resolved for notifications.');
    }

    foreach (['member_user_id', 'trainer_user_id'] as $participantKey) {
        $recipientId = (int)($participants[$participantKey] ?? 0);
        if ($recipientId > 0 && $recipientId !== $excludeUserId) {
            notificationWorkflowCreate($conn, $recipientId, $type, $title, $message, (int)$booking['id']);
        }
    }

    if (in_array('admin', $notifyRoles, true)) {
        notificationWorkflowCreateForAdmins($conn, $type, $title, $message);
    }
}
