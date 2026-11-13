<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

function notificationResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    $currentUser = requireAuth($conn);
    $userId = (int)$currentUser['id'];
    $role = strtolower(trim((string)($currentUser['role'] ?? '')));
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = strtolower(trim((string)($_GET['action'] ?? 'list')));
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $todayDate = $today->format('Y-m-d');
    $membership = null;
    $isEligible = false;

    if ($role === 'member') {
        $memberStmt = $conn->prepare(
            'SELECT membership_type, membership_end, status, account_status
             FROM members WHERE user_id = :user_id LIMIT 1'
        );
        $memberStmt->execute([':user_id' => $userId]);
        $membership = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$membership) {
            notificationResponse(['success' => false, 'message' => 'Member profile not found.'], 404);
        }
        $membershipEnd = (string)($membership['membership_end'] ?? '');
        $isEligible = $membership
            && strtolower((string)$membership['membership_type']) === 'monthly'
            && strtolower((string)$membership['status']) === 'active'
            && strtolower((string)$membership['account_status']) === 'active'
            && $membershipEnd !== ''
            && $membershipEnd !== '0000-00-00'
            && in_array($membershipEnd, [
                $today->modify('+3 days')->format('Y-m-d'),
                $today->modify('+1 day')->format('Y-m-d'),
            ], true);
    }

    if ($method === 'GET') {
        if (!in_array($action, ['list', 'count'], true)) {
            notificationResponse(['success' => false, 'message' => 'Invalid notification action.'], 400);
        }
        $notifications = [];
        if ($role === 'member' && $isEligible) {
            $membershipStmt = $conn->prepare(
                'SELECT id, title, message, notification_type, reminder_days, membership_end, booking_id,
                        is_read, created_at
                 FROM notifications
                 WHERE user_id = :user_id AND notification_type = "membership_expiration"
                   AND membership_end = :membership_end AND membership_end > :today
                 ORDER BY created_at DESC, id DESC LIMIT 50'
            );
            $membershipStmt->execute([
                ':user_id' => $userId,
                ':membership_end' => $membership['membership_end'],
                ':today' => $todayDate,
            ]);
            $notifications = $membershipStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $bookingScope = '1 = 1';
        if ($role === 'member') {
            $bookingScope = '(n.booking_id IS NULL OR EXISTS (
                SELECT 1 FROM bookings b JOIN members m ON m.id = b.member_id
                WHERE b.id = n.booking_id AND m.user_id = :booking_user_id
            ))';
        } elseif ($role === 'trainer') {
            $bookingScope = '(n.booking_id IS NULL OR EXISTS (
                SELECT 1 FROM bookings b JOIN trainers t ON t.id = b.trainer_id
                WHERE b.id = n.booking_id AND t.user_id = :booking_user_id
            ))';
        }
        $bookingStmt = $conn->prepare(
            'SELECT n.id, n.title, n.message, n.notification_type, n.reminder_days, n.membership_end, n.booking_id,
                    n.is_read, n.created_at
             FROM notifications n
             WHERE n.user_id = :user_id AND n.notification_type <> "membership_expiration"
               AND ' . $bookingScope . '
             ORDER BY n.created_at DESC, n.id DESC LIMIT 50'
        );
        $bookingParams = [':user_id' => $userId];
        if ($role === 'member' || $role === 'trainer') {
            $bookingParams[':booking_user_id'] = $userId;
        }
        $bookingStmt->execute($bookingParams);
        $notifications = array_merge($notifications, $bookingStmt->fetchAll(PDO::FETCH_ASSOC));
        usort($notifications, static function (array $first, array $second): int {
            return strcmp((string)$second['created_at'], (string)$first['created_at']);
        });
        $notifications = array_slice($notifications, 0, 50);
        foreach ($notifications as &$notification) {
            $notification['id'] = (int)$notification['id'];
            $notification['reminder_days'] = (int)$notification['reminder_days'];
            $notification['booking_id'] = $notification['booking_id'] === null ? null : (int)$notification['booking_id'];
            $notification['is_read'] = (bool)$notification['is_read'];
        }
        unset($notification);
        $unreadCount = count(array_filter($notifications, static function (array $notification): bool {
            return !$notification['is_read'];
        }));
        notificationResponse([
            'success' => true,
            'notifications' => $action === 'count' ? [] : $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    if ($method === 'POST' && $action === 'mark-read') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        $notificationId = $input['notification_id'] ?? null;
        if ((!is_int($notificationId) && !(is_string($notificationId) && ctype_digit($notificationId))) || (int)$notificationId < 1) {
            notificationResponse(['success' => false, 'message' => 'A valid notification ID is required.'], 400);
        }
        $updateStmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
        $updateStmt->execute([':id' => (int)$notificationId, ':user_id' => $userId]);
        if ($updateStmt->rowCount() === 0) {
            $existsStmt = $conn->prepare('SELECT id FROM notifications WHERE id = :id AND user_id = :user_id');
            $existsStmt->execute([':id' => (int)$notificationId, ':user_id' => $userId]);
            if (!$existsStmt->fetchColumn()) {
                notificationResponse(['success' => false, 'message' => 'Notification not found.'], 404);
            }
        }
        notificationResponse(['success' => true, 'message' => 'Notification marked as read.']);
    }

    notificationResponse(['success' => false, 'message' => 'Method or notification action not allowed.'], 405);
} catch (Throwable $e) {
    error_log('[notifications-api] outcome=failure error_code=' . ($e instanceof PDOException ? (string)$e->getCode() : 'runtime_error'));
    notificationResponse(['success' => false, 'message' => 'Unable to process notifications. Please try again later.'], 500);
}
