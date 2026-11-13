<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/booking.php';

$tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$stmt = $conn->prepare(
    'SELECT b.id, b.booking_code, b.booking_date, b.start_time, b.member_id, b.trainer_id,
            m.user_id AS member_user_id, t.user_id AS trainer_user_id
     FROM bookings b
     JOIN members m ON m.id = b.member_id
     LEFT JOIN trainers t ON t.id = b.trainer_id
     WHERE b.booking_date = :booking_date AND b.status = "confirmed"'
);
$stmt->execute([':booking_date' => $tomorrow]);
$created = 0;

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $booking) {
    $recipients = array_unique(array_filter([
        (int)($booking['member_user_id'] ?? 0),
        (int)($booking['trainer_user_id'] ?? 0),
    ]));
    foreach ($recipients as $userId) {
        $exists = $conn->prepare(
            'SELECT id FROM notifications
             WHERE user_id = :user_id AND booking_id = :booking_id
               AND notification_type = "booking_reminder" LIMIT 1'
        );
        $exists->execute([':user_id' => $userId, ':booking_id' => (int)$booking['id']]);
        if ($exists->fetchColumn()) {
            continue;
        }
        bookingCreateNotification(
            $conn,
            $userId,
            (int)$booking['id'],
            'booking_reminder',
            'Upcoming training session',
            "Booking {$booking['booking_code']} is scheduled for {$booking['booking_date']} at " . substr((string)$booking['start_time'], 0, 5) . '.'
        );
        $created++;
    }
}

fwrite(STDOUT, "Created {$created} booking reminder notification(s).\n");
