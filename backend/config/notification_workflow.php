<?php

function notificationWorkflowCreate(
    PDO $conn,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?int $bookingId = null,
    ?string $membershipEnd = null
): void {
    if (!$conn->inTransaction()) {
        throw new LogicException('Notifications must be created in the event transaction.');
    }

    $userLock = $conn->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
    $userLock->execute([':user_id' => $userId]);
    if (!$userLock->fetchColumn()) {
        throw new RuntimeException('Notification recipient no longer exists.');
    }

    $title = mb_substr($title, 0, 150);
    $message = mb_substr($message, 0, 500);
    $existing = $conn->prepare(
        'SELECT id FROM notifications
         WHERE user_id = :user_id AND notification_type = :type
           AND message = :message AND booking_id <=> :booking_id
         LIMIT 1'
    );
    $existing->execute([
        ':user_id' => $userId,
        ':type' => $type,
        ':message' => $message,
        ':booking_id' => $bookingId,
    ]);
    if ($existing->fetchColumn()) {
        return;
    }

    $insert = $conn->prepare(
        'INSERT INTO notifications
            (user_id, title, message, notification_type, reminder_days, membership_end, booking_id)
         VALUES (:user_id, :title, :message, :type, 0, :membership_end, :booking_id)'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':title' => $title,
        ':message' => $message,
        ':type' => $type,
        ':membership_end' => $membershipEnd,
        ':booking_id' => $bookingId,
    ]);
}

function notificationWorkflowCreateForAdmins(
    PDO $conn,
    string $type,
    string $title,
    string $message
): void {
    $admins = $conn->query(
        'SELECT id FROM users WHERE LOWER(role) IN ("admin", "administrator") ORDER BY id FOR UPDATE'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $adminId) {
        notificationWorkflowCreate($conn, (int)$adminId, $type, $title, $message);
    }
}

function notificationWorkflowMembershipTypeLabel(string $type): string
{
    return match (strtolower(trim($type))) {
        'daily_pass', 'daily pass' => 'Daily Pass',
        'monthly', 'monthlysubscription', 'monthly_subscription' => 'Monthly membership',
        default => ucwords(str_replace('_', ' ', trim($type))),
    };
}

function notificationWorkflowCategoryLabel(?string $category): string
{
    $category = strtolower(trim((string)$category));
    if ($category === '') {
        return 'unspecified category';
    }
    return match ($category) {
        'pwd' => 'PWD',
        default => ucwords(str_replace('_', ' ', $category)),
    };
}
