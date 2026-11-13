<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notification_workflow.php';
require_once __DIR__ . '/../config/booking.php';
require_once __DIR__ . '/../config/booking_pricing.php';

try {
    $expiredBookings = expireUnpaidBookingPayments($conn);
    $expiredResponses = expirePendingBookingResponses($conn);
    fwrite(STDOUT, "Expired {$expiredBookings} unpaid booking(s) and {$expiredResponses} unanswered booking request(s).\n");
} catch (Throwable $e) {
    error_log('Booking expiration job failed: ' . $e->getMessage());
    fwrite(STDERR, "Booking expiration failed.\n");
    exit(1);
}
