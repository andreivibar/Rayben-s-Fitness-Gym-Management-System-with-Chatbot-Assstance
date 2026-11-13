<?php

function attendanceQrDayWindow(DateTimeImmutable $time): array {
    $manila = new DateTimeZone('Asia/Manila');
    $now = $time->setTimezone($manila);
    $start = $now->setTime(0, 0, 0);
    $nextMidnight = $start->modify('+1 day');

    return [
        'now' => $now,
        'start' => $start,
        'next_midnight' => $nextMidnight
    ];
}

function attendanceQrTokenIsValidForDate($issuedAt, $expiresAt, DateTimeImmutable $time): bool {
    $window = attendanceQrDayWindow($time);
    $timezone = new DateTimeZone('Asia/Manila');

    try {
        $issued = new DateTimeImmutable((string)$issuedAt, $timezone);
        $expires = new DateTimeImmutable((string)$expiresAt, $timezone);
    } catch (Exception $e) {
        return false;
    }

    return $issued >= $window['start']
        && $issued <= $window['now']
        && $expires > $window['now']
        && $expires <= $window['next_midnight'];
}

function findCurrentAttendanceQrToken(PDO $conn, string $token, DateTimeImmutable $now): ?array {
    $stmt = $conn->prepare(
        'SELECT id, issued_at, expires_at
         FROM attendance_qr_tokens
         WHERE token_hash = :token_hash
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !attendanceQrTokenIsValidForDate($row['issued_at'], $row['expires_at'], $now)) {
        return null;
    }

    return $row;
}

?>
