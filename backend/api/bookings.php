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
require_once __DIR__ . '/../config/notification_workflow.php';
require_once __DIR__ . '/../config/booking.php';
require_once __DIR__ . '/../config/booking_pricing.php';

function bookingApiResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function bookingApiIsAdmin(array $user): bool
{
    return in_array(strtolower(trim((string)($user['role'] ?? ''))), ['admin', 'administrator'], true);
}

function bookingApiResolveSessionItems(PDO $conn, array $input, bool $forUpdate = false): array
{
    $rawIds = $input['session_type_ids'] ?? null;
    if ($rawIds === null) {
        $rawId = $input['session_type_id'] ?? null;
        if ($rawId !== null && $rawId !== '') {
            $rawIds = [$rawId];
        }
    }

    if ($rawIds === null) {
        $legacyName = trim((string)($input['session_type'] ?? ''));
        if ($legacyName === '') {
            throw new RuntimeException('Select at least one session type.', 400);
        }
        $legacyStmt = $conn->prepare(
            'SELECT id, name, duration_minutes, service_category FROM booking_session_types
             WHERE name = :name AND is_active = 1 LIMIT 1'
        );
        $legacyStmt->execute([':name' => $legacyName]);
        $legacy = $legacyStmt->fetch(PDO::FETCH_ASSOC);
        if (!$legacy) {
            throw new RuntimeException('One or more selected session types are invalid or inactive.', 400);
        }
        $rawIds = [(int)$legacy['id']];
    }

    if (!is_array($rawIds) || count($rawIds) < 1) {
        throw new RuntimeException('Select at least one session type.', 400);
    }
    if (count($rawIds) > 32) {
        throw new RuntimeException('Select no more than 32 session types.', 400);
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id < 1) {
            throw new RuntimeException('Every selected session type must be valid.', 400);
        }
        if (in_array($id, $ids, true)) {
            throw new RuntimeException('A session type cannot be selected more than once.', 400);
        }
        $ids[] = $id;
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $index => $id) {
        $placeholder = ':session_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $id;
    }
    $stmt = $conn->prepare(
        'SELECT id, name, duration_minutes, service_category FROM booking_session_types
         WHERE is_active = 1 AND id IN (' . implode(',', $placeholders) . ')'
        . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute($params);
    $available = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $session) {
        $session['id'] = (int)$session['id'];
        $session['duration_minutes'] = (int)$session['duration_minutes'];
        $available[$session['id']] = $session;
    }
    if (count($available) !== count($ids)) {
        throw new RuntimeException('One or more selected session types are invalid or inactive.', 400);
    }

    $items = [];
    $totalDuration = 0;
    foreach ($ids as $id) {
        $item = $available[$id];
        if ($item['duration_minutes'] < 15 || $item['duration_minutes'] > 480 || $item['duration_minutes'] % 15 !== 0) {
            throw new RuntimeException('A selected session has an invalid configured duration.', 409);
        }
        $item['service_category'] = (string)$item['service_category'];
        $item['sort_order'] = count($items);
        $items[] = $item;
        $totalDuration += $item['duration_minutes'];
    }
    if ($totalDuration > 480) {
        throw new RuntimeException('The combined session duration cannot exceed 480 minutes.', 400);
    }
    return ['items' => $items, 'duration_minutes' => $totalDuration];
}

function bookingApiAttachSessionItems(PDO $conn, array &$bookings): void
{
    $stmt = $conn->prepare(
        'SELECT session_type_id, session_name, duration_minutes, unit_price, line_amount, currency
         FROM booking_session_items WHERE booking_id = :booking_id ORDER BY sort_order, id'
    );
    foreach ($bookings as &$booking) {
        $stmt->execute([':booking_id' => (int)$booking['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            $items = [[
                'session_type_id' => null,
                'session_name' => (string)($booking['session_type'] ?? 'Session'),
                'duration_minutes' => (int)$booking['duration_minutes'],
                'unit_price' => null,
                'line_amount' => null,
                'currency' => null,
            ]];
        }
        foreach ($items as &$item) {
            $item['session_type_id'] = $item['session_type_id'] === null ? null : (int)$item['session_type_id'];
            $item['duration_minutes'] = (int)$item['duration_minutes'];
            $item['unit_price'] = $item['unit_price'] === null ? null : (float)$item['unit_price'];
            $item['line_amount'] = $item['line_amount'] === null ? null : (float)$item['line_amount'];
        }
        unset($item);
        $booking['session_items'] = $items;
        $booking['session_type'] = implode(' + ', array_column($items, 'session_name'));
        $booking['duration_minutes'] = array_sum(array_column($items, 'duration_minutes'));
    }
    unset($booking);
}

function bookingApiSessionDescription(PDO $conn, array $booking): string
{
    $stmt = $conn->prepare(
        'SELECT session_name FROM booking_session_items
         WHERE booking_id = :booking_id ORDER BY sort_order, id'
    );
    $stmt->execute([':booking_id' => (int)($booking['booking_id'] ?? $booking['id'])]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$names) {
        $names = [(string)($booking['session_type'] ?? 'Training session')];
    }
    return implode(', ', $names) . ' (' . (int)($booking['duration_minutes'] ?? 0) . ' minutes total)';
}

function bookingApiLockParticipants(PDO $conn, int $trainerId, int $memberId): void
{
    $trainer = $conn->prepare('SELECT id FROM trainers WHERE id = :id AND status = "active" FOR UPDATE');
    $trainer->execute([':id' => $trainerId]);
    if (!$trainer->fetchColumn()) {
        throw new RuntimeException('The selected trainer is no longer available.', 409);
    }

    $member = $conn->prepare(
        'SELECT id FROM members WHERE id = :id AND status = "active" AND account_status = "active" FOR UPDATE'
    );
    $member->execute([':id' => $memberId]);
    if (!$member->fetchColumn()) {
        throw new RuntimeException('The selected member account is not active.', 409);
    }
}

function bookingApiAssertSlot(
    PDO $conn,
    int $trainerId,
    int $memberId,
    string $date,
    string $startTime,
    int $duration,
    int $excludeBookingId = 0
): string {
    if (!bookingValidDate($date) || !bookingValidTime($startTime)) {
        throw new RuntimeException('Choose a valid booking date and time.', 400);
    }
    if (new DateTimeImmutable($date, new DateTimeZone('Asia/Manila')) < new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'))) {
        throw new RuntimeException('Booking date cannot be in the past.', 400);
    }
    bookingAssertWithinAdvanceWindow($conn, $date);
    $startTime = substr($startTime, 0, 5);
    $endTime = bookingFormatTime(bookingTimeMinutes($startTime) + $duration);
    $slots = bookingGetAvailableSlots($conn, $trainerId, $date, $duration, $memberId, $excludeBookingId);
    foreach ($slots as $slot) {
        if ($slot['start_time'] === $startTime && $slot['end_time'] === $endTime) {
            return $endTime;
        }
    }
    throw new RuntimeException('That time is no longer available for the selected trainer and session. Refresh availability and choose another slot.', 409);
}

function bookingApiValidateConfirmation(PDO $conn, array $booking, int $excludeBookingId): void
{
    $start = substr((string)$booking['start_time'], 0, 5);
    $end = bookingAppointmentEnd($start, (int)$booking['duration_minutes']);
    if ($end !== substr((string)$booking['end_time'], 0, 5)) {
        throw new RuntimeException('The stored appointment end time does not match its session duration.', 409);
    }
    bookingValidateFutureAppointment((string)$booking['booking_date'], $start, (int)$booking['duration_minutes']);
    bookingAssertWithinAdvanceWindow($conn, (string)$booking['booking_date']);
    bookingAssertWithinGymHours($conn, (string)$booking['booking_date'], $start, $end);
    bookingAssertNoConfirmedConflict(
        $conn,
        (int)$booking['trainer_id'],
        (int)$booking['member_id'],
        (string)$booking['booking_date'],
        $start,
        $end,
        $excludeBookingId
    );
}

function bookingApiNotify(PDO $conn, array $booking, array $sessionNames, bool $notifyAdmins): void
{
    $detailsStmt = $conn->prepare(
        "SELECT b.booking_code, b.booking_date, b.start_time,
                m.user_id AS member_user_id,
                COALESCE(NULLIF(m.full_name, ''), NULLIF(mu.full_name, ''), 'Member') AS member_name,
                t.user_id AS trainer_user_id, tu.full_name AS trainer_name
         FROM bookings b
         JOIN members m ON m.id = b.member_id
         LEFT JOIN users mu ON mu.id = m.user_id
         JOIN trainers t ON t.id = b.trainer_id
         JOIN users tu ON tu.id = t.user_id
         WHERE b.id = :booking_id LIMIT 1"
    );
    $detailsStmt->execute([':booking_id' => (int)$booking['id']]);
    $details = $detailsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$details) {
        throw new RuntimeException('Booking participants could not be resolved for notifications.');
    }

    $sessionName = implode(', ', $sessionNames);
    $dateTime = new DateTimeImmutable(
        (string)$details['booking_date'] . ' ' . (string)$details['start_time'],
        new DateTimeZone('Asia/Manila')
    );
    $scheduled = $dateTime->format('F j, Y \a\t g:i A');
    $memberName = (string)$details['member_name'];
    $trainerName = (string)$details['trainer_name'];
    $type = 'booking_requested';

    $memberUserId = (int)$details['member_user_id'];
    if ($memberUserId > 0) {
        bookingCreateNotification(
            $conn,
            $memberUserId,
            (int)$booking['id'],
            $type,
            'Booking request submitted',
            "Your booking request for {$sessionName} with {$trainerName} on {$scheduled} has been submitted and is awaiting trainer approval."
        );
    }

    $trainerUserId = (int)$details['trainer_user_id'];
    if ($trainerUserId > 0) {
        bookingCreateNotification(
            $conn,
            $trainerUserId,
            (int)$booking['id'],
            $type,
            'New booking request',
            "You received a booking request from {$memberName} for {$sessionName} on {$scheduled}."
        );
    }

    if ($notifyAdmins) {
        notificationWorkflowCreateForAdmins(
            $conn,
            $type,
            'New booking request',
            "{$memberName} requested a booking session with {$trainerName} for {$sessionName} on {$scheduled}."
        );
    }
}

try {
    $user = requireAuth($conn);
    expireUnpaidBookingPayments($conn);
    $role = strtolower(trim((string)($user['role'] ?? '')));
    $isAdmin = bookingApiIsAdmin($user);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? 'list')));

    if ($method === 'GET' && $action === 'credit-summary') {
        if ($role !== 'member') {
            bookingApiResponse(['success' => false, 'message' => 'Only Members can view their booking-credit eligibility.'], 403);
        }
        $date = trim((string)($_GET['booking_date'] ?? ''));
        $rawIds = array_values(array_filter(explode(',', (string)($_GET['session_type_ids'] ?? ''))));
        if (!bookingValidDate($date) || !$rawIds) {
            bookingApiResponse(['success' => false, 'message' => 'Choose a valid training date and session type.'], 400);
        }
        $selection = bookingApiResolveSessionItems($conn, ['session_type_ids' => $rawIds]);
        $eligibleType = count(array_filter(
            $selection['items'],
            static fn(array $item): bool => $item['service_category'] === 'personal_training'
        )) === count($selection['items']);
        $memberId = bookingGetMemberId($conn, $user);
        $count = 0;
        if ($memberId > 0 && $eligibleType) {
            $credits = $conn->prepare(
                "SELECT COUNT(*)
                 FROM subscription_booking_credits c
                 JOIN subscription_booking_credit_grants g ON g.id = c.grant_id
                 WHERE g.member_id = :member_id AND g.starts_on <= :booking_date
                   AND g.ends_on >= :booking_date AND c.status = 'available'"
            );
            $credits->execute([':member_id' => $memberId, ':booking_date' => $date]);
            $count = (int)$credits->fetchColumn();
        }
        bookingApiResponse([
            'success' => true,
            'eligible_session_type' => $eligibleType,
            'available_credits' => $count,
            'credit_available' => $eligibleType && $count > 0,
        ]);
    }

    if ($method === 'GET' && $action === 'available_slots') {
        if (!in_array($role, ['member', 'trainer', 'admin', 'administrator'], true)) {
            bookingApiResponse(['success' => false, 'message' => 'Forbidden.'], 403);
        }
        $trainerId = (int)($_GET['trainer_id'] ?? 0);
        $date = trim((string)($_GET['date'] ?? ''));
        $sessionName = trim((string)($_GET['session_type'] ?? ''));
        $excludeBookingId = (int)($_GET['exclude_booking_id'] ?? 0);
        $requestedMemberId = (int)($_GET['member_id'] ?? 0);
        if ($trainerId < 1 || $date === '' || $sessionName === '') {
            bookingApiResponse(['success' => false, 'message' => 'Trainer, date, and session type are required.'], 400);
        }
        if (!bookingValidDate($date)) {
            bookingApiResponse(['success' => false, 'message' => 'Choose a valid booking date.'], 400);
        }
        bookingAssertWithinAdvanceWindow($conn, $date);
        if ($role === 'trainer' && bookingGetTrainerId($conn, $user) !== $trainerId) {
            bookingApiResponse(['success' => false, 'message' => 'You can only view your own booking availability.'], 403);
        }
        $session = bookingGetSessionType($conn, $sessionName);
        $memberId = $role === 'member' ? bookingGetMemberId($conn, $user) : ($isAdmin ? $requestedMemberId : 0);
        if ($excludeBookingId > 0) {
            $excludedStmt = $conn->prepare(
                'SELECT id, member_id, trainer_id, session_type, duration_minutes
                 FROM bookings WHERE id = :id LIMIT 1'
            );
            $excludedStmt->execute([':id' => $excludeBookingId]);
            $excluded = $excludedStmt->fetch(PDO::FETCH_ASSOC);
            $allowedOwner = $excluded && (
                ($isAdmin)
                || ($role === 'member' && (int)$excluded['member_id'] === bookingGetMemberId($conn, $user))
                || ($role === 'trainer' && (int)$excluded['trainer_id'] === bookingGetTrainerId($conn, $user))
            );
            if (!$allowedOwner || (int)$excluded['trainer_id'] !== $trainerId) {
                bookingApiResponse(['success' => false, 'message' => 'You cannot inspect availability for that booking.'], 403);
            }
            $memberId = (int)$excluded['member_id'];
            $session = [
                'name' => (string)$excluded['session_type'],
                'duration_minutes' => (int)$excluded['duration_minutes'],
            ];
        }
        if (!$session) {
            bookingApiResponse(['success' => false, 'message' => 'Choose an active session type.'], 400);
        }
        $trainerStmt = $conn->prepare(
            'SELECT t.id, t.trainer_code, t.status, u.full_name
             FROM trainers t JOIN users u ON u.id = t.user_id WHERE t.id = :id LIMIT 1'
        );
        $trainerStmt->execute([':id' => $trainerId]);
        $trainer = $trainerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$trainer || strtolower((string)$trainer['status']) !== 'active') {
            bookingApiResponse(['success' => false, 'message' => 'Trainer not found or inactive.'], 404);
        }
        $slots = bookingGetAvailableSlots($conn, $trainerId, $date, $session['duration_minutes'], $memberId, $excludeBookingId);
        bookingApiResponse([
            'success' => true,
            'trainer' => ['id' => (int)$trainer['id'], 'trainer_code' => $trainer['trainer_code'], 'full_name' => $trainer['full_name']],
            'date' => $date,
            'session_type' => $session['name'],
            'duration_minutes' => $session['duration_minutes'],
            'available_slots' => $slots,
        ]);
    }

    if ($method === 'GET' && $action === 'reschedule-requests') {
        if (!$isAdmin) {
            bookingApiResponse(['success' => false, 'message' => 'Only administrators can view reschedule requests.'], 403);
        }
        $stmt = $conn->query(
            'SELECT r.id, r.booking_id, r.requested_date,
                    TIME_FORMAT(r.requested_start_time, "%H:%i") AS requested_start_time,
                    TIME_FORMAT(r.requested_end_time, "%H:%i") AS requested_end_time,
                    r.status, r.created_at, b.booking_code, b.booking_date,
                    TIME_FORMAT(b.start_time, "%H:%i") AS start_time,
                    TIME_FORMAT(b.end_time, "%H:%i") AS end_time, b.session_type,
                    m.full_name AS member_name, m.member_code,
                    u.full_name AS trainer_name
             FROM booking_reschedule_requests r
             JOIN bookings b ON b.id = r.booking_id
             JOIN members m ON m.id = b.member_id
             LEFT JOIN trainers t ON t.id = b.trainer_id
             LEFT JOIN users u ON u.id = t.user_id
             WHERE r.status = "pending"
             ORDER BY r.created_at ASC'
        );
        bookingApiResponse(['success' => true, 'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'GET' && $action === 'cancellation-history') {
        if (!$isAdmin) {
            bookingApiResponse(['success' => false, 'message' => 'Only administrators can view cancellation history.'], 403);
        }
        $stmt = $conn->query(
            'SELECT h.id, h.booking_id, b.booking_code, b.booking_date,
                    TIME_FORMAT(b.start_time, "%H:%i") AS start_time,
                    h.previous_status, h.new_status, h.initiating_role, h.cancellation_reason,
                    h.cancellation_category, h.cancelled_at, h.refund_eligible,
                    h.credit_restoration_decision, h.related_payment_id, h.related_credit_id,
                    initiator.full_name AS initiating_user_name
             FROM booking_cancellation_history h
             LEFT JOIN bookings b ON b.id = h.booking_id
             LEFT JOIN users initiator ON initiator.id = h.initiating_user_id
             ORDER BY h.cancelled_at DESC, h.id DESC
             LIMIT 500'
        );
        bookingApiResponse(['success' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'GET') {
        if (!in_array($role, ['member', 'trainer', 'admin', 'administrator'], true)) {
            bookingApiResponse(['success' => false, 'message' => 'Forbidden.'], 403);
        }
        $where = [];
        $params = [];
        if ($role === 'member') {
            $memberId = bookingGetMemberId($conn, $user);
            if ($memberId < 1) {
                bookingApiResponse(['success' => true, 'bookings' => [], 'summary' => []]);
            }
            $where[] = 'b.member_id = :member_id';
            $params[':member_id'] = $memberId;
        } elseif ($role === 'trainer') {
            $trainerId = bookingGetTrainerId($conn, $user);
            if ($trainerId < 1) {
                bookingApiResponse(['success' => true, 'bookings' => [], 'summary' => []]);
            }
            $where[] = 'b.trainer_id = :trainer_id';
            $params[':trainer_id'] = $trainerId;
        } else {
            foreach (['member_id', 'trainer_id'] as $filter) {
                if (isset($_GET[$filter]) && ctype_digit((string)$_GET[$filter]) && (int)$_GET[$filter] > 0) {
                    $where[] = "b.{$filter} = :{$filter}";
                    $params[":{$filter}"] = (int)$_GET[$filter];
                }
            }
        }

        $status = strtolower(trim((string)($_GET['status'] ?? '')));
        $statuses = array_values(array_intersect(
            ['pending', 'confirmed', 'rejected', 'completed', 'cancelled', 'awaiting_member', 'awaiting_payment', 'expired', 'no_show'],
            array_map('trim', explode(',', $status))
        ));
        if ($statuses) {
            $placeholders = [];
            foreach ($statuses as $index => $value) {
                $placeholder = ':status_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $value;
            }
            $where[] = 'b.status IN (' . implode(',', $placeholders) . ')';
        }
        foreach (['date' => 'b.booking_date', 'date_from' => 'b.booking_date >=', 'date_to' => 'b.booking_date <='] as $key => $column) {
            if (!empty($_GET[$key]) && bookingValidDate((string)$_GET[$key])) {
                $where[] = $column . ($key === 'date' ? ' = ' : ' ') . ':' . $key;
                $params[':' . $key] = (string)$_GET[$key];
            }
        }
        $search = trim((string)($_GET['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(b.booking_code LIKE :search_code OR m.full_name LIKE :search_member OR t.trainer_code LIKE :search_trainer OR u.full_name LIKE :search_trainer_name OR EXISTS (SELECT 1 FROM booking_session_items si WHERE si.booking_id = b.id AND si.session_name LIKE :search_session))';
            $params[':search_code'] = '%' . $search . '%';
            $params[':search_member'] = '%' . $search . '%';
            $params[':search_trainer'] = '%' . $search . '%';
            $params[':search_trainer_name'] = '%' . $search . '%';
            $params[':search_session'] = '%' . $search . '%';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $params[':feedback_member_role'] = $role;
        $stmt = $conn->prepare(
            'SELECT b.id, b.booking_code, b.member_id, b.trainer_id, b.booking_date,
                    TIME_FORMAT(b.start_time, "%H:%i") AS start_time,
                    TIME_FORMAT(b.end_time, "%H:%i") AS end_time, b.duration_minutes,
                    b.subtotal, b.credit_amount, b.amount_due, b.price_currency, b.price_snapshot_at,
                    b.payment_deadline_at, b.response_deadline,
                    b.cancellation_reason, b.cancelled_at, b.cancelled_by_role,
                    b.refund_eligible, b.credit_restoration_decision,
                    (SELECT br.status FROM booking_refunds br WHERE br.booking_id = b.id ORDER BY br.id DESC LIMIT 1) AS refund_status,
                    (SELECT br.amount FROM booking_refunds br WHERE br.booking_id = b.id ORDER BY br.id DESC LIMIT 1) AS refund_amount,
                    (SELECT p.status FROM payments p
                     WHERE p.booking_id = b.id AND p.payment_type = "training_session"
                     ORDER BY p.id DESC LIMIT 1) AS latest_payment_status,
                    (SELECT p.payment_method FROM payments p
                     WHERE p.booking_id = b.id AND p.payment_type = "training_session"
                     ORDER BY p.id DESC LIMIT 1) AS latest_payment_method,
                    (SELECT p.verification_status FROM payments p
                     WHERE p.booking_id = b.id AND p.payment_type = "training_session"
                     ORDER BY p.id DESC LIMIT 1) AS latest_payment_verification,
                    (SELECT e.event_type FROM subscription_booking_credit_events e
                     WHERE e.booking_id = b.id ORDER BY e.id DESC LIMIT 1) AS credit_event_type,
                    b.session_type, b.notes, b.rejection_reason, b.status, b.created_at, b.updated_at,
                    CASE WHEN :feedback_member_role = "member" THEN (
                        SELECT f.id FROM member_feedback f
                        WHERE f.booking_id = b.id AND f.feedback_category = "trainer"
                        LIMIT 1
                    ) ELSE NULL END AS trainer_feedback_id,
                    m.first_name AS member_first_name, m.middle_name AS member_middle_name,
                    m.last_name AS member_last_name, m.full_name AS member_name,
                    m.member_code, m.email AS member_email, m.phone AS member_phone,
                    u.first_name AS trainer_first_name, u.middle_name AS trainer_middle_name,
                    u.last_name AS trainer_last_name, u.full_name AS trainer_name, t.trainer_code
             FROM bookings b
             JOIN members m ON b.member_id = m.id
             LEFT JOIN trainers t ON b.trainer_id = t.id
             LEFT JOIN users u ON t.user_id = u.id
             ' . $whereSql . '
             ORDER BY FIELD(b.status, "pending", "awaiting_member", "awaiting_payment", "confirmed", "completed", "rejected", "cancelled", "expired", "no_show"),
                      CASE WHEN b.status = "pending" THEN b.created_at END ASC,
                      CASE WHEN b.status = "confirmed" THEN b.booking_date END ASC,
                      CASE WHEN b.status = "confirmed" THEN b.start_time END ASC,
                      b.booking_date DESC, b.start_time ASC'
        );
        $stmt->execute($params);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        bookingApiAttachSessionItems($conn, $bookings);
        $summary = ['total' => count($bookings), 'pending' => 0, 'confirmed' => 0, 'rejected' => 0, 'completed' => 0, 'cancelled' => 0, 'awaiting_member' => 0, 'awaiting_payment' => 0, 'expired' => 0, 'no_show' => 0];
        foreach ($bookings as $booking) {
            if (isset($summary[$booking['status']])) {
                $summary[$booking['status']]++;
            }
        }
        $overlapStmt = $conn->prepare(
            'SELECT id FROM bookings
             WHERE id <> :booking_id AND booking_date = :booking_date
               AND status = "pending"
               AND (trainer_id = :trainer_id OR member_id = :member_id)
               AND start_time < :end_time AND end_time > :start_time
             LIMIT 1'
        );
        foreach ($bookings as &$booking) {
            $overlapStmt->execute([
                ':booking_id' => (int)$booking['id'],
                ':booking_date' => $booking['booking_date'],
                ':trainer_id' => (int)$booking['trainer_id'],
                ':member_id' => (int)$booking['member_id'],
                ':start_time' => substr((string)$booking['start_time'], 0, 5),
                ':end_time' => substr((string)$booking['end_time'], 0, 5),
            ]);
            $booking['has_pending_conflict'] = (bool)$overlapStmt->fetchColumn();
            $booking['within_normal_shift'] = bookingIsWithinNormalTrainerShift(
                $conn,
                (int)$booking['trainer_id'],
                (string)$booking['booking_date'],
                substr((string)$booking['start_time'], 0, 5),
                substr((string)$booking['end_time'], 0, 5)
            );
            $booking['has_confirmed_conflict'] = false;
            $booking['confirmation_issue'] = null;
            if ($booking['status'] === 'pending' || $booking['status'] === 'awaiting_member') {
                try {
                    bookingApiValidateConfirmation($conn, $booking, (int)$booking['id']);
                } catch (RuntimeException $validationIssue) {
                    $booking['confirmation_issue'] = $validationIssue->getMessage();
                    $booking['has_confirmed_conflict'] = strpos($validationIssue->getMessage(), 'confirmed appointment') !== false;
                }
            }
        }
        unset($booking);
        $proposalStmt = $conn->prepare(
            'SELECT id, booking_id, original_date,
                    TIME_FORMAT(original_start_time, "%H:%i") AS original_start_time,
                    TIME_FORMAT(original_end_time, "%H:%i") AS original_end_time,
                    proposed_date,
                    TIME_FORMAT(proposed_start_time, "%H:%i") AS proposed_start_time,
                    TIME_FORMAT(proposed_end_time, "%H:%i") AS proposed_end_time,
                    trainer_note, status
             FROM booking_alternative_proposals
             WHERE booking_id = :booking_id ORDER BY id DESC LIMIT 1'
        );
        foreach ($bookings as &$booking) {
            $proposalStmt->execute([':booking_id' => (int)$booking['id']]);
            $booking['alternative_proposal'] = $proposalStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($booking['status'] === 'awaiting_member') {
                $booking['workflow_state'] = 'awaiting_member_response';
            }
        }
        unset($booking);
        if ($role === 'trainer') {
            foreach ($bookings as &$booking) {
                unset($booking['member_email'], $booking['member_phone']);
            }
            unset($booking);
        }
        $response = ['success' => true, 'bookings' => $bookings, 'summary' => $summary];
        if ($isAdmin) {
            $requests = $conn->query(
                'SELECT r.id, r.booking_id, r.requested_date,
                        TIME_FORMAT(r.requested_start_time, "%H:%i") AS requested_start_time,
                        TIME_FORMAT(r.requested_end_time, "%H:%i") AS requested_end_time,
                        b.booking_code, b.booking_date,
                        TIME_FORMAT(b.start_time, "%H:%i") AS start_time,
                        TIME_FORMAT(b.end_time, "%H:%i") AS end_time,
                        m.full_name AS member_name
                 FROM booking_reschedule_requests r
                 JOIN bookings b ON b.id = r.booking_id
                 JOIN members m ON m.id = b.member_id
                 WHERE r.status = "pending" ORDER BY r.created_at ASC'
            );
            $response['reschedule_requests'] = $requests->fetchAll(PDO::FETCH_ASSOC);
        }
        bookingApiResponse($response);
    }

    if ($method !== 'POST') {
        bookingApiResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    if ($action === 'create') {
        if (!in_array($role, ['member', 'admin', 'administrator'], true)) {
            bookingApiResponse(['success' => false, 'message' => 'Only members and administrators can create bookings.'], 403);
        }
        $memberId = $isAdmin ? (int)($input['member_id'] ?? 0) : bookingGetMemberId($conn, $user);
        $trainerId = (int)($input['trainer_id'] ?? 0);
        $date = trim((string)($input['booking_date'] ?? ''));
        $start = trim((string)($input['start_time'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));
        $sessionSelection = bookingApiResolveSessionItems($conn, $input);
        if ($memberId < 1) {
            bookingApiResponse([
                'success' => false,
                'message' => $isAdmin
                    ? 'Select an active member.'
                    : 'No member profile is linked to your authenticated account. Contact the gym administrator.',
            ], $isAdmin ? 400 : 404);
        }
        if ($trainerId < 1) {
            bookingApiResponse(['success' => false, 'message' => 'Select an active trainer.'], 400);
        }
        $trainerStmt = $conn->prepare('SELECT id FROM trainers WHERE id = :id AND status = "active" LIMIT 1');
        $trainerStmt->execute([':id' => $trainerId]);
        if (!$trainerStmt->fetchColumn()) {
            bookingApiResponse(['success' => false, 'message' => 'The selected trainer is unavailable.'], 404);
        }
        if (mb_strlen($notes) > 2000) {
            bookingApiResponse(['success' => false, 'message' => 'Booking notes must be 2,000 characters or fewer.'], 400);
        }

        $duration = (int)$sessionSelection['duration_minutes'];
        $end = bookingValidateFutureAppointment($date, $start, $duration);
        bookingAssertWithinAdvanceWindow($conn, $date);
        $conn->beginTransaction();
        bookingApiLockParticipants($conn, $trainerId, $memberId);
        $sessionSelection = bookingApiResolveSessionItems($conn, $input, true);
        $priceTimestamp = (string)$conn->query('SELECT NOW()')->fetchColumn();
        $subtotalCents = 0;
        foreach ($sessionSelection['items'] as &$item) {
            $price = bookingPriceForSession($conn, (int)$item['id'], $priceTimestamp);
            $item['price_version_id'] = (int)$price['id'];
            $item['unit_price'] = number_format((float)$price['amount'], 2, '.', '');
            $item['line_amount'] = $item['unit_price'];
            $item['currency'] = (string)$price['currency'];
            $subtotalCents += (int)round((float)$price['amount'] * 100);
        }
        unset($item);
        $currency = $sessionSelection['items'][0]['currency'];
        foreach ($sessionSelection['items'] as $item) {
            if ($item['currency'] !== $currency) {
                throw new RuntimeException('Selected session types must use the same currency.', 409);
            }
        }
        $personalTrainingOnly = count(array_filter(
            $sessionSelection['items'],
            static fn(array $item): bool => $item['service_category'] === 'personal_training'
        )) === count($sessionSelection['items']);
        $availableCredit = bookingReserveSubscriptionCredit($conn, $memberId, $date, $personalTrainingOnly);
        $creditCents = $availableCredit ? $subtotalCents : 0;
        $dueCents = $subtotalCents - $creditCents;
        $duration = (int)$sessionSelection['duration_minutes'];
        $code = 'BKG-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $sessionNames = array_column($sessionSelection['items'], 'name');
        $insert = $conn->prepare(
            'INSERT INTO bookings
             (booking_code, member_id, trainer_id, booking_date, start_time, end_time, session_type,
              duration_minutes, subtotal, credit_amount, amount_due, price_currency, price_snapshot_at,
              notes, status, response_deadline, created_by_user_id, updated_by_user_id)
             VALUES (:code, :member, :trainer, :date, :start, :end, :session, :duration,
              :subtotal, :credit_amount, :amount_due, :currency, :price_snapshot_at,
              :notes, "pending", :response_deadline, :created_by, :updated_by)'
        );
        $insert->execute([
            ':code' => $code,
            ':member' => $memberId,
            ':trainer' => $trainerId,
            ':date' => $date,
            ':start' => substr($start, 0, 5),
            ':end' => $end,
            ':session' => $sessionNames[0],
            ':duration' => $duration,
            ':subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
            ':credit_amount' => number_format($creditCents / 100, 2, '.', ''),
            ':amount_due' => number_format($dueCents / 100, 2, '.', ''),
            ':currency' => $currency,
            ':price_snapshot_at' => $priceTimestamp,
            ':notes' => $notes !== '' ? $notes : null,
            ':response_deadline' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+24 hours')->format('Y-m-d H:i:s'),
            ':created_by' => (int)$user['id'],
            ':updated_by' => (int)$user['id'],
        ]);
        $bookingId = (int)$conn->lastInsertId();
        $itemInsert = $conn->prepare(
            'INSERT INTO booking_session_items
             (booking_id, session_type_id, session_name, duration_minutes, price_version_id,
              unit_price, line_amount, currency, sort_order)
             VALUES (:booking_id, :session_type_id, :session_name, :duration_minutes, :price_version_id,
              :unit_price, :line_amount, :currency, :sort_order)'
        );
        foreach ($sessionSelection['items'] as $item) {
            $itemInsert->execute([
                ':booking_id' => $bookingId,
                ':session_type_id' => $item['id'],
                ':session_name' => $item['name'],
                ':duration_minutes' => $item['duration_minutes'],
                ':price_version_id' => $item['price_version_id'],
                ':unit_price' => $item['unit_price'],
                ':line_amount' => $item['line_amount'],
                ':currency' => $item['currency'],
                ':sort_order' => $item['sort_order'],
            ]);
        }
        if ($availableCredit) {
            bookingLinkReservedCredit($conn, (int)$availableCredit['id'], $bookingId, (int)$user['id']);
        }
        $booking = ['id' => $bookingId, 'member_id' => $memberId, 'trainer_id' => $trainerId];
        $booking['booking_code'] = $code;
        bookingApiNotify($conn, $booking, $sessionNames, !$isAdmin);
        $conn->commit();
        bookingApiResponse([
            'success' => true,
            'message' => 'Booking request submitted and is waiting for trainer approval.',
            'booking_code' => $code,
            'duration_minutes' => $duration,
            'session_items' => $sessionSelection['items'],
            'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
            'credit_amount' => number_format($creditCents / 100, 2, '.', ''),
            'amount_due' => number_format($dueCents / 100, 2, '.', ''),
            'price_currency' => $currency,
            'credit_reserved' => (bool)$availableCredit,
        ]);
    }

    if ($action === 'mark-no-show') {
        if (!in_array($role, ['trainer', 'admin', 'administrator'], true)) {
            bookingApiResponse(['success' => false, 'message' => 'Only trainers or administrators can record a no-show.'], 403);
        }
        $bookingId = (int)($input['id'] ?? 0);
        $reason = trim((string)($input['reason'] ?? ''));
        if ($bookingId < 1) {
            bookingApiResponse(['success' => false, 'message' => 'Booking ID is required.'], 400);
        }
        if (mb_strlen($reason) > 1000) {
            bookingApiResponse(['success' => false, 'message' => 'The no-show reason must be 1,000 characters or fewer.'], 400);
        }
        $conn->beginTransaction();
        $stmt = $conn->prepare('SELECT * FROM bookings WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Booking not found.'], 404);
        }
        if ($booking['status'] !== 'confirmed') {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Only confirmed bookings can be marked as no-show.'], 409);
        }
        if ($role === 'trainer' && ((int)$booking['trainer_id'] !== bookingGetTrainerId($conn, $user))) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'You can only mark bookings assigned to you as no-show.'], 403);
        }
        $scheduledEnd = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['end_time'], new DateTimeZone('Asia/Manila'));
        if (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')) <= $scheduledEnd) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'A no-show can only be recorded after the session has ended.'], 409);
        }

        $previousStatus = (string)$booking['status'];
        $bookingPayment = bookingApiLookupPaidBookingPayment($conn, $bookingId);
        $creditId = bookingApiReleaseReservationForNoShow($conn, $bookingId, (int)$user['id']);

        $update = $conn->prepare(
            'UPDATE bookings
             SET status = "no_show",
                 cancellation_reason = :reason,
                 cancelled_by_user_id = :user_id,
                 cancelled_by_role = :role,
                 cancelled_at = NOW(),
                 refund_eligible = 0,
                 credit_restoration_decision = 0,
                 updated_by_user_id = :user_id
             WHERE id = :id'
        );
        $update->execute([
            ':reason' => $reason !== '' ? $reason : null,
            ':user_id' => (int)$user['id'],
            ':role' => $role === 'trainer' ? 'trainer' : 'admin',
            ':id' => $bookingId,
        ]);

        $noShowInsert = $conn->prepare(
            'INSERT INTO booking_no_show_records (booking_id, marked_by_user_id, marked_by_role, no_show_reason, marked_at)
             VALUES (:booking_id, :user_id, :role, :reason, NOW())'
        );
        $noShowInsert->execute([
            ':booking_id' => $bookingId,
            ':user_id' => (int)$user['id'],
            ':role' => $role === 'trainer' ? 'trainer' : 'admin',
            ':reason' => $reason !== '' ? $reason : null,
        ]);

        bookingApiCreateCancellationAudit(
            $conn,
            $bookingId,
            $previousStatus,
            'no_show',
            (int)$user['id'],
            $role === 'trainer' ? 'trainer' : 'admin',
            $reason,
            'no_show',
            false,
            false,
            $bookingPayment['id'] ?? null,
            $creditId
        );
        bookingNotifyEvent(
            $conn,
            $booking,
            'booking_no_show',
            'Booking marked as no-show',
            "Booking {$booking['booking_code']} was marked as a no-show.",
            [],
            (int)$user['id']
        );
        $conn->commit();
        bookingApiResponse(['success' => true, 'message' => 'Booking marked as no-show. No refund was issued.']);
    }

    if ($action === 'cancel') {
        $bookingId = (int)($input['id'] ?? 0);
        $reason = trim((string)($input['reason'] ?? ''));
        if ($bookingId < 1) {
            bookingApiResponse(['success' => false, 'message' => 'Booking ID is required.'], 400);
        }
        if (mb_strlen($reason) > 1000) {
            bookingApiResponse(['success' => false, 'message' => 'The cancellation reason must be 1,000 characters or fewer.'], 400);
        }
        $conn->beginTransaction();
        $stmt = $conn->prepare('SELECT * FROM bookings WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Booking not found.'], 404);
        }
        if ($role === 'member' && (int)$booking['member_id'] !== bookingGetMemberId($conn, $user)) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'You cannot cancel another member’s booking.'], 403);
        }
        if ($role === 'trainer' && (int)$booking['trainer_id'] !== bookingGetTrainerId($conn, $user)) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'You cannot cancel another trainer’s booking.'], 403);
        }
        if (!$isAdmin && !in_array($role, ['member', 'trainer'], true)) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Forbidden.'], 403);
        }
        if (!in_array($booking['status'], ['pending', 'confirmed', 'awaiting_member', 'awaiting_payment'], true)) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Only active bookings can be cancelled.'], 409);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $startAt = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['start_time'], new DateTimeZone('Asia/Manila'));
        $endAt = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['end_time'], new DateTimeZone('Asia/Manila'));
        if (($role === 'member' && $startAt <= $now)
            || ($role !== 'member' && $endAt <= $now)) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => $role === 'member'
                ? 'A booking cannot be cancelled at or after its start time. Contact the gym if you need help.'
                : 'A session that has already ended cannot be cancelled. Use the no-show action when applicable.'], 409);
        }

        $hoursUntilStart = ($startAt->getTimestamp() - $now->getTimestamp()) / 3600;
        $paidPayment = bookingApiLookupPaidBookingPayment($conn, $bookingId);
        $refundEligible = false;
        $refundPaymentId = null;
        $creditRestoration = false;
        $creditId = null;
        $category = $role === 'member' ? 'member_cancel' : ($isAdmin ? 'admin_cancel' : 'trainer_cancel');

        if ($role === 'member') {
            if ($booking['status'] === 'awaiting_payment' && $hoursUntilStart >= 24) {
                $category = 'awaiting_payment_cancel';
                $refundEligible = false;
            } elseif ($hoursUntilStart >= 24) {
                $refundEligible = $paidPayment !== null;
                $category = 'member_cancel';
                if ($refundEligible && $paidPayment) {
                    $refundPaymentId = (int)$paidPayment['id'];
                }
            } else {
                $category = 'late_member_cancel';
                $refundEligible = false;
            }
        }
        if (in_array($role, ['trainer', 'admin', 'administrator'], true)) {
            $refundEligible = $paidPayment !== null;
            $category = $isAdmin ? 'admin_cancel' : 'trainer_cancel';
            if ($refundEligible && $paidPayment) {
                $refundPaymentId = (int)$paidPayment['id'];
            }
        }

        if ($role === 'member' && $booking['status'] === 'awaiting_payment' && $hoursUntilStart < 24) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'An awaiting-payment booking inside the 24-hour cancellation cutoff cannot be cancelled.'], 409);
        }

        $previousStatus = (string)$booking['status'];
        if ($paidPayment && $refundEligible) {
            bookingApiCreateRefundRequest(
                $conn,
                $bookingId,
                (int)$paidPayment['id'],
                (int)$paidPayment['member_id'],
                (string)($paidPayment['payment_method'] ?? 'counter'),
                (float)$paidPayment['amount'],
                (int)$user['id'],
                $reason !== '' ? $reason : 'Automatic full refund request for eligible cancellation.'
            );
        }

        $stmt = $conn->prepare(
            "SELECT c.id
             FROM subscription_booking_credits c
             WHERE c.applied_booking_id = :booking_id AND c.status = 'consumed'
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':booking_id' => $bookingId]);
        $consumedCreditId = (int)$stmt->fetchColumn();
        $restoreCreditAllowed = $role !== 'member' || $hoursUntilStart >= 24;
        if ($consumedCreditId > 0 && $restoreCreditAllowed) {
            $creditId = bookingApiRestoreConsumedCredit($conn, $bookingId, (int)$user['id']);
            $creditRestoration = $creditId !== null;
        } elseif ($consumedCreditId > 0 && !$refundEligible) {
            $creditRestoration = false;
        }

        $reservedCreditId = null;
        if ($role === 'member' && $hoursUntilStart < 24) {
            $reservedCreditId = bookingForfeitReservedCredit($conn, $bookingId, (int)$user['id'], 'late_member_cancellation_forfeiture');
            $creditId = $reservedCreditId ?? $creditId;
        } elseif ($creditId === null) {
            bookingReleaseReservedCredit($conn, $bookingId, (int)$user['id']);
        }

        $statusUpdate = $conn->prepare(
            'UPDATE bookings
             SET status = "cancelled",
                 cancellation_reason = :reason,
                 cancelled_by_user_id = :user_id,
                 cancelled_by_role = :role,
                 cancelled_at = NOW(),
                 refund_eligible = :refund_eligible,
                 credit_restoration_decision = :credit_restoration,
                 updated_by_user_id = :user_id
             WHERE id = :id'
        );
        $statusUpdate->execute([
            ':reason' => $reason !== '' ? $reason : null,
            ':user_id' => (int)$user['id'],
            ':role' => $role === 'member' ? 'member' : ($isAdmin ? 'admin' : 'trainer'),
            ':refund_eligible' => $refundEligible ? 1 : 0,
            ':credit_restoration' => $creditRestoration ? 1 : 0,
            ':id' => $bookingId,
        ]);

        if ($booking['status'] === 'awaiting_payment') {
            $paymentColumns = $conn->query("SHOW COLUMNS FROM payments LIKE 'verification_status'")->fetch(PDO::FETCH_ASSOC);
            if ($paymentColumns) {
                $conn->prepare(
                    "UPDATE payments
                     SET status = 'failed', verification_status = 'rejected', verified_at = NOW(), updated_at = NOW()
                     WHERE booking_id = :booking_id AND payment_type = 'training_session' AND status IN ('pending', 'failed')"
                )->execute([':booking_id' => $bookingId]);
            } else {
                $conn->prepare(
                    "UPDATE payments
                     SET status = 'failed', updated_at = NOW()
                     WHERE booking_id = :booking_id AND payment_type = 'training_session' AND status IN ('pending', 'failed')"
                )->execute([':booking_id' => $bookingId]);
            }
        }

        bookingApiCreateCancellationAudit(
            $conn,
            $bookingId,
            $previousStatus,
            'cancelled',
            (int)$user['id'],
            $role === 'member' ? 'member' : ($isAdmin ? 'admin' : 'trainer'),
            $reason,
            $category,
            $refundEligible,
            $creditRestoration,
            $refundPaymentId,
            $creditId
        );

        $cancelRequests = $conn->prepare('UPDATE booking_reschedule_requests SET status = "cancelled" WHERE booking_id = :id AND status = "pending"');
        $cancelRequests->execute([':id' => $bookingId]);
        $cancelProposals = $conn->prepare('UPDATE booking_alternative_proposals SET status = "cancelled" WHERE booking_id = :id AND status = "pending"');
        $cancelProposals->execute([':id' => $bookingId]);
        bookingNotifyEvent(
            $conn,
            $booking,
            'booking_cancelled',
            'Booking cancelled',
            "Booking {$booking['booking_code']} (" . bookingApiSessionDescription($conn, $booking) . ") was cancelled.",
            [],
            (int)$user['id']
        );
        $conn->commit();
        bookingApiResponse([
            'success' => true,
            'message' => $refundEligible ? 'Booking cancelled and a full refund request was created.' : 'Booking cancelled.',
            'refund_requested' => $refundEligible,
            'credit_restored' => $creditRestoration,
        ]);
    }

    if ($action === 'trainer-propose-alternative') {
        if ($role !== 'trainer') {
            bookingApiResponse(['success' => false, 'message' => 'Only the assigned trainer can propose an alternative time.'], 403);
        }
        $bookingId = (int)($input['id'] ?? 0);
        $date = trim((string)($input['proposed_date'] ?? ''));
        $start = trim((string)($input['proposed_start_time'] ?? ''));
        $note = trim((string)($input['trainer_note'] ?? ''));
        if ($bookingId < 1 || mb_strlen($note) > 1000) {
            bookingApiResponse(['success' => false, 'message' => 'Provide a valid booking and an optional note up to 1,000 characters.'], 400);
        }

        $conn->beginTransaction();
        $stmt = $conn->prepare('SELECT * FROM bookings WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Booking not found.'], 404);
        }
        if ((int)$booking['trainer_id'] !== bookingGetTrainerId($conn, $user)) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'You can only manage requests assigned to you.'], 403);
        }
        if ($booking['status'] !== 'pending') {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Only pending booking requests can receive an alternative proposal.'], 409);
        }

        $end = bookingValidateFutureAppointment($date, $start, (int)$booking['duration_minutes']);
        bookingAssertWithinAdvanceWindow($conn, $date);
        bookingApiLockParticipants($conn, (int)$booking['trainer_id'], (int)$booking['member_id']);
        bookingAssertWithinGymHours($conn, $date, substr($start, 0, 5), $end);
        bookingAssertNoConfirmedConflict(
            $conn,
            (int)$booking['trainer_id'],
            (int)$booking['member_id'],
            $date,
            substr($start, 0, 5),
            $end,
            $bookingId
        );
        $existing = $conn->prepare(
            'SELECT id FROM booking_alternative_proposals WHERE booking_id = :booking_id AND status = "pending" LIMIT 1 FOR UPDATE'
        );
        $existing->execute([':booking_id' => $bookingId]);
        if ($existing->fetchColumn()) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'This booking already has an alternative awaiting the member.'], 409);
        }
        $insert = $conn->prepare(
            'INSERT INTO booking_alternative_proposals
             (booking_id, original_date, original_start_time, original_end_time,
              proposed_date, proposed_start_time, proposed_end_time, trainer_note, proposed_by_user_id)
             VALUES (:booking_id, :original_date, :original_start, :original_end,
                     :proposed_date, :proposed_start, :proposed_end, :note, :proposed_by)'
        );
        $insert->execute([
            ':booking_id' => $bookingId,
            ':original_date' => $booking['booking_date'],
            ':original_start' => $booking['start_time'],
            ':original_end' => $booking['end_time'],
            ':proposed_date' => $date,
            ':proposed_start' => substr($start, 0, 5) . ':00',
            ':proposed_end' => $end . ':00',
            ':note' => $note !== '' ? $note : null,
            ':proposed_by' => (int)$user['id'],
        ]);
        $update = $conn->prepare(
            'UPDATE bookings SET status = "awaiting_member", response_deadline = NULL,
                rejection_reason = NULL, updated_by_user_id = :user_id WHERE id = :id'
        );
        $update->execute([':user_id' => (int)$user['id'], ':id' => $bookingId]);
        bookingNotifyEvent(
            $conn,
            $booking,
            'booking_alternative_proposed',
            'Trainer proposed another time',
            "Your trainer proposed {$date} at " . substr($start, 0, 5) . " for booking {$booking['booking_code']} (" . bookingApiSessionDescription($conn, $booking) . ").",
            ['admin'],
            (int)$user['id']
        );
        $conn->commit();
        bookingApiResponse(['success' => true, 'message' => 'Alternative time sent to the member for approval.']);
    }

    if ($action === 'member-alternative-response') {
        if ($role !== 'member') {
            bookingApiResponse(['success' => false, 'message' => 'Only the member who owns this booking can respond to the alternative.'], 403);
        }
        $proposalId = (int)($input['proposal_id'] ?? 0);
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        if ($proposalId < 1 || !in_array($decision, ['accept', 'decline'], true)) {
            bookingApiResponse(['success' => false, 'message' => 'Choose whether to accept or decline the proposed time.'], 400);
        }
        $conn->beginTransaction();
        $stmt = $conn->prepare(
            'SELECT p.*, b.id AS booking_id, b.booking_code, b.member_id, b.trainer_id,
                    b.duration_minutes, b.subtotal, b.credit_amount, b.amount_due,
                    b.status AS booking_status
             FROM booking_alternative_proposals p
             JOIN bookings b ON b.id = p.booking_id
             WHERE p.id = :id FOR UPDATE'
        );
        $stmt->execute([':id' => $proposalId]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$proposal) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Alternative proposal not found.'], 404);
        }
        if ((int)$proposal['member_id'] !== bookingGetMemberId($conn, $user)) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'You cannot respond to another member’s booking.'], 403);
        }
        if ($proposal['status'] !== 'pending' || $proposal['booking_status'] !== 'awaiting_member') {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'This alternative is no longer awaiting a response.'], 409);
        }

        bookingApiLockParticipants($conn, (int)$proposal['trainer_id'], (int)$proposal['member_id']);
        if ($decision === 'accept') {
            $date = (string)$proposal['proposed_date'];
            $start = substr((string)$proposal['proposed_start_time'], 0, 5);
            $end = bookingValidateFutureAppointment($date, $start, (int)$proposal['duration_minutes']);
            bookingAssertWithinAdvanceWindow($conn, $date);
            if ($end !== substr((string)$proposal['proposed_end_time'], 0, 5)) {
                $conn->rollBack();
                bookingApiResponse(['success' => false, 'message' => 'The proposed duration is no longer valid.'], 409);
            }
            bookingAssertWithinGymHours($conn, $date, $start, $end);
            bookingAssertNoConfirmedConflict(
                $conn,
                (int)$proposal['trainer_id'],
                (int)$proposal['member_id'],
                $date,
                $start,
                $end,
                (int)$proposal['booking_id']
            );
            $approvedStatus = 'confirmed';
            $paymentDeadline = null;
            $creditAmount = (int)round((float)($proposal['credit_amount'] ?? 0) * 100);
            $subtotal = (int)round((float)($proposal['subtotal'] ?? 0) * 100);
            $amountDue = (int)round((float)($proposal['amount_due'] ?? 0) * 100);
            $creditAdjustment = null;
            if ($proposal['amount_due'] !== null && $amountDue > 0) {
                $approvedStatus = 'awaiting_payment';
                $paymentDeadline = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
                    ->modify('+24 hours')->format('Y-m-d H:i:s');
            } elseif ($proposal['amount_due'] !== null && $creditAmount > 0) {
                $creditCoversDate = bookingReservedCreditCoversDate($conn, (int)$proposal['booking_id'], $date);
                if ($creditCoversDate === null) {
                    $conn->rollBack();
                    bookingApiResponse(['success' => false, 'message' => 'The reserved free-session credit is no longer available.'], 409);
                }
                if ($creditCoversDate) {
                    if (!bookingConsumeReservedCredit($conn, (int)$proposal['booking_id'], (int)$user['id'])) {
                        $conn->rollBack();
                        bookingApiResponse(['success' => false, 'message' => 'The reserved free-session credit is no longer available.'], 409);
                    }
                } else {
                    bookingReleaseReservedCredit($conn, (int)$proposal['booking_id'], (int)$user['id']);
                    $approvedStatus = 'awaiting_payment';
                    $paymentDeadline = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
                        ->modify('+24 hours')->format('Y-m-d H:i:s');
                    $creditAdjustment = [
                        'credit_amount' => '0.00',
                        'amount_due' => number_format($subtotal / 100, 2, '.', ''),
                    ];
                }
            }
            $updateBooking = $conn->prepare(
                'UPDATE bookings SET booking_date = :date, start_time = :start, end_time = :end,
                    status = :status, payment_deadline_at = :payment_deadline, response_deadline = NULL,
                    credit_amount = COALESCE(:credit_amount, credit_amount),
                    amount_due = COALESCE(:amount_due, amount_due),
                    rejection_reason = NULL, updated_by_user_id = :user_id WHERE id = :id'
            );
            $updateBooking->execute([
                ':date' => $date,
                ':start' => $start . ':00',
                ':end' => $end . ':00',
                ':status' => $approvedStatus,
                ':payment_deadline' => $paymentDeadline,
                ':credit_amount' => $creditAdjustment['credit_amount'] ?? null,
                ':amount_due' => $creditAdjustment['amount_due'] ?? null,
                ':user_id' => (int)$user['id'],
                ':id' => (int)$proposal['booking_id'],
            ]);
            $newProposalStatus = 'accepted';
            $notificationType = 'booking_alternative_accepted';
            $notificationTitle = 'Alternative time accepted';
            $notificationMessage = "The member accepted the proposed time for booking {$proposal['booking_code']} (" . bookingApiSessionDescription($conn, $proposal) . ").";
        } else {
            bookingReleaseReservedCredit($conn, (int)$proposal['booking_id'], (int)$user['id']);
            $updateBooking = $conn->prepare(
                'UPDATE bookings SET status = "rejected", response_deadline = NULL,
                    rejection_reason = "Member declined the proposed alternative.",
                    updated_by_user_id = :user_id WHERE id = :id'
            );
            $updateBooking->execute([':user_id' => (int)$user['id'], ':id' => (int)$proposal['booking_id']]);
            $newProposalStatus = 'declined';
            $notificationType = 'booking_alternative_declined';
            $notificationTitle = 'Alternative time declined';
            $notificationMessage = "The member declined the proposed time for booking {$proposal['booking_code']} (" . bookingApiSessionDescription($conn, $proposal) . ").";
        }
        $updateProposal = $conn->prepare(
            'UPDATE booking_alternative_proposals
             SET status = :status, responded_by_user_id = :user_id WHERE id = :id AND status = "pending"'
        );
        $updateProposal->execute([
            ':status' => $newProposalStatus,
            ':user_id' => (int)$user['id'],
            ':id' => $proposalId,
        ]);
        bookingNotifyEvent(
            $conn,
            ['id' => (int)$proposal['booking_id'], 'member_id' => (int)$proposal['member_id'], 'trainer_id' => (int)$proposal['trainer_id']],
            $notificationType,
            $notificationTitle,
            $notificationMessage,
            ['admin'],
            (int)$user['id']
        );
        $conn->commit();
        bookingApiResponse([
            'success' => true,
            'message' => $decision === 'accept'
                ? ($approvedStatus === 'awaiting_payment'
                    ? 'Alternative accepted. Booking approved; payment is due within 24 hours.'
                    : 'Alternative accepted and booking confirmed.')
                : 'Alternative declined.',
            'status' => $decision === 'accept' ? $approvedStatus : 'rejected',
            'payment_deadline_at' => $decision === 'accept' ? $paymentDeadline : null,
        ]);
    }

    if ($action === 'status') {
        if (!$isAdmin && $role !== 'trainer') {
            bookingApiResponse(['success' => false, 'message' => 'Only administrators or the assigned trainer can update booking status.'], 403);
        }
        $bookingId = (int)($input['id'] ?? 0);
        $newStatus = strtolower(trim((string)($input['status'] ?? '')));
        $rejectionReason = trim((string)($input['reason'] ?? ''));
        if ($bookingId < 1 || !in_array($newStatus, ['confirmed', 'rejected', 'cancelled', 'completed'], true)) {
            bookingApiResponse(['success' => false, 'message' => 'Choose a valid booking status.'], 400);
        }
        if (mb_strlen($rejectionReason) > 1000) {
            bookingApiResponse(['success' => false, 'message' => 'The rejection reason must be 1,000 characters or fewer.'], 400);
        }
        $conn->beginTransaction();
        $stmt = $conn->prepare('SELECT * FROM bookings WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Booking not found.'], 404);
        }
        if ($role === 'trainer' && (int)$booking['trainer_id'] !== bookingGetTrainerId($conn, $user)) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'You can only update sessions assigned to you.'], 403);
        }
        $currentStatus = (string)$booking['status'];
        if ($currentStatus === 'pending' && !empty($booking['response_deadline'])
            && new DateTimeImmutable((string)$booking['response_deadline'], new DateTimeZone('Asia/Manila'))
                <= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'The staff response deadline has passed. This booking can no longer be approved.'], 409);
        }
        $allowed = $isAdmin
            ? (($currentStatus === 'pending' && in_array($newStatus, ['confirmed', 'rejected', 'cancelled'], true))
                || ($currentStatus === 'awaiting_member' && in_array($newStatus, ['rejected', 'cancelled'], true))
                || ($currentStatus === 'confirmed' && in_array($newStatus, ['cancelled', 'completed'], true)))
            : ($role === 'trainer'
                && (($currentStatus === 'pending' && in_array($newStatus, ['confirmed', 'rejected'], true))
                    || ($currentStatus === 'confirmed' && in_array($newStatus, ['cancelled', 'completed'], true))));
        if (!$allowed) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'That booking status transition is not allowed.'], 409);
        }
        if ($newStatus === 'confirmed') {
            bookingApiLockParticipants($conn, (int)$booking['trainer_id'], (int)$booking['member_id']);
            bookingApiValidateConfirmation($conn, $booking, $bookingId);
        }
        if ($newStatus === 'completed') {
            $endAt = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['end_time'], new DateTimeZone('Asia/Manila'));
            if ($endAt > new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
                $conn->rollBack();
                bookingApiResponse(['success' => false, 'message' => 'A session can be marked completed only after it has ended.'], 409);
            }
        }
        if ($newStatus === 'cancelled') {
            $startAt = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['start_time'], new DateTimeZone('Asia/Manila'));
            if ($startAt <= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
                $conn->rollBack();
                bookingApiResponse(['success' => false, 'message' => 'A booking cannot be cancelled after its start time.'], 409);
            }
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Use the centralized cancellation action so refund and credit rules are applied.'], 409);
        }
        if ($newStatus === 'cancelled' || $newStatus === 'rejected') {
            $proposalUpdate = $conn->prepare(
                'UPDATE booking_alternative_proposals SET status = "cancelled"
                 WHERE booking_id = :id AND status = "pending"'
            );
            $proposalUpdate->execute([':id' => $bookingId]);
        }
        $actualStatus = $newStatus;
        $paymentDeadline = null;
        if ($newStatus === 'confirmed' && $booking['amount_due'] !== null) {
            if ((int)round((float)$booking['amount_due'] * 100) === 0) {
                if ((int)round((float)$booking['credit_amount'] * 100) > 0
                    && !bookingConsumeReservedCredit($conn, $bookingId, (int)$user['id'])) {
                    $conn->rollBack();
                    bookingApiResponse(['success' => false, 'message' => 'The reserved free-session credit is no longer available.'], 409);
                }
            } else {
                $actualStatus = 'awaiting_payment';
                $paymentDeadline = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
                    ->modify('+24 hours')->format('Y-m-d H:i:s');
            }
        }
        if ($newStatus === 'rejected') {
            bookingReleaseReservedCredit($conn, $bookingId, (int)$user['id']);
        }
        if ($newStatus === 'cancelled') {
            bookingReleaseReservedCredit($conn, $bookingId, (int)$user['id']);
        }
        $update = $conn->prepare(
            'UPDATE bookings SET status = :status, payment_deadline_at = :payment_deadline,
                response_deadline = CASE WHEN :status_is_pending = 1 THEN response_deadline ELSE NULL END,
                rejection_reason = :reason, updated_by_user_id = :user_id WHERE id = :id'
        );
        $update->execute([
            ':status' => $actualStatus,
            ':payment_deadline' => $paymentDeadline,
            ':status_is_pending' => $actualStatus === 'pending' ? 1 : 0,
            ':reason' => $newStatus === 'rejected' && $rejectionReason !== '' ? $rejectionReason : null,
            ':user_id' => (int)$user['id'],
            ':id' => $bookingId,
        ]);
        $titles = [
            'confirmed' => 'Booking confirmed',
            'awaiting_payment' => 'Booking approved - payment required',
            'rejected' => 'Booking request declined',
            'cancelled' => 'Booking cancelled',
            'completed' => 'Session completed',
        ];
        $statusMessage = "Booking {$booking['booking_code']} (" . bookingApiSessionDescription($conn, $booking) . ") is now {$actualStatus}.";
        if ($newStatus === 'rejected' && $rejectionReason !== '') {
            $statusMessage .= ' Reason: ' . $rejectionReason;
        }
        bookingNotifyEvent(
            $conn,
            $booking,
            'booking_' . $newStatus,
            $titles[$newStatus],
            $statusMessage,
            [],
            (int)$user['id']
        );
        $conn->commit();
        bookingApiResponse([
            'success' => true,
            'message' => $actualStatus === 'awaiting_payment'
                ? 'Booking approved. The member has 24 hours to pay.'
                : 'Booking status updated.',
            'status' => $actualStatus,
            'payment_deadline_at' => $paymentDeadline,
        ]);
    }

    if ($action === 'reschedule') {
        if (!$isAdmin) {
            bookingApiResponse(['success' => false, 'message' => 'Only administrators can reschedule bookings.'], 403);
        }
        $bookingId = (int)($input['id'] ?? 0);
        $date = trim((string)($input['booking_date'] ?? ''));
        $start = trim((string)($input['start_time'] ?? ''));
        if ($bookingId < 1) {
            bookingApiResponse(['success' => false, 'message' => 'Booking ID is required.'], 400);
        }
        $conn->beginTransaction();
        $stmt = $conn->prepare('SELECT * FROM bookings WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Booking not found.'], 404);
        }
        if (!in_array($booking['status'], ['pending', 'confirmed'], true) || empty($booking['trainer_id'])) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Only pending or confirmed trainer bookings can be rescheduled.'], 409);
        }
        bookingApiLockParticipants($conn, (int)$booking['trainer_id'], (int)$booking['member_id']);
        $end = bookingValidateFutureAppointment($date, $start, (int)$booking['duration_minutes']);
        bookingAssertWithinAdvanceWindow($conn, $date);
        if ($booking['status'] === 'confirmed') {
            bookingAssertWithinGymHours($conn, $date, substr($start, 0, 5), $end);
            bookingAssertNoConfirmedConflict(
                $conn,
                (int)$booking['trainer_id'],
                (int)$booking['member_id'],
                $date,
                substr($start, 0, 5),
                $end,
                $bookingId
            );
        }
        $history = $conn->prepare(
            'INSERT INTO booking_alternative_proposals
             (booking_id, original_date, original_start_time, original_end_time,
              proposed_date, proposed_start_time, proposed_end_time, trainer_note, status,
              proposed_by_user_id, responded_by_user_id)
             VALUES (:booking_id, :original_date, :original_start, :original_end,
                     :proposed_date, :proposed_start, :proposed_end, "Rescheduled by administrator.",
                     "accepted", :proposed_by, :responded_by)'
        );
        $history->execute([
            ':booking_id' => $bookingId,
            ':original_date' => $booking['booking_date'],
            ':original_start' => $booking['start_time'],
            ':original_end' => $booking['end_time'],
            ':proposed_date' => $date,
            ':proposed_start' => substr($start, 0, 5) . ':00',
            ':proposed_end' => $end . ':00',
            ':proposed_by' => (int)$user['id'],
            ':responded_by' => (int)$user['id'],
        ]);
        $update = $conn->prepare(
            'UPDATE bookings SET booking_date = :date, start_time = :start, end_time = :end, updated_by_user_id = :user_id WHERE id = :id'
        );
        $update->execute([
            ':date' => $date,
            ':start' => substr($start, 0, 5) . ':00',
            ':end' => $end . ':00',
            ':user_id' => (int)$user['id'],
            ':id' => $bookingId,
        ]);
        $cancelRequests = $conn->prepare('UPDATE booking_reschedule_requests SET status = "cancelled" WHERE booking_id = :id AND status = "pending"');
        $cancelRequests->execute([':id' => $bookingId]);
        $booking['id'] = $bookingId;
        bookingNotifyEvent(
            $conn,
            $booking,
            'booking_rescheduled',
            'Booking rescheduled',
            "Booking {$booking['booking_code']} was moved to {$date} at {$start}.",
            [],
            (int)$user['id']
        );
        $conn->commit();
        bookingApiResponse(['success' => true, 'message' => 'Booking rescheduled.']);
    }

    if ($action === 'request-reschedule') {
        if ($role !== 'member') {
            bookingApiResponse(['success' => false, 'message' => 'Only members can request a reschedule.'], 403);
        }
        $bookingId = (int)($input['id'] ?? 0);
        $date = trim((string)($input['booking_date'] ?? ''));
        $start = trim((string)($input['start_time'] ?? ''));
        $memberId = bookingGetMemberId($conn, $user);
        if ($bookingId < 1 || $memberId < 1) {
            bookingApiResponse(['success' => false, 'message' => 'A valid booking is required.'], 400);
        }
        $conn->beginTransaction();
        $stmt = $conn->prepare('SELECT * FROM bookings WHERE id = :id AND member_id = :member_id FOR UPDATE');
        $stmt->execute([':id' => $bookingId, ':member_id' => $memberId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking || !in_array($booking['status'], ['pending', 'confirmed'], true) || empty($booking['trainer_id'])) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'This booking cannot be rescheduled.'], 409);
        }
        bookingApiLockParticipants($conn, (int)$booking['trainer_id'], $memberId);
        $end = bookingApiAssertSlot(
            $conn,
            (int)$booking['trainer_id'],
            $memberId,
            $date,
            $start,
            (int)$booking['duration_minutes'],
            $bookingId
        );
        $pending = $conn->prepare('SELECT id FROM booking_reschedule_requests WHERE booking_id = :id AND status = "pending" LIMIT 1 FOR UPDATE');
        $pending->execute([':id' => $bookingId]);
        if ($pending->fetchColumn()) {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'A reschedule request is already awaiting review.'], 409);
        }
        $request = $conn->prepare(
            'INSERT INTO booking_reschedule_requests
             (booking_id, requested_date, requested_start_time, requested_end_time, requested_by_user_id)
             VALUES (:booking_id, :date, :start, :end, :user_id)'
        );
        $request->execute([
            ':booking_id' => $bookingId,
            ':date' => $date,
            ':start' => $start,
            ':end' => $end,
            ':user_id' => (int)$user['id'],
        ]);
        bookingNotifyEvent(
            $conn,
            $booking,
            'booking_reschedule_requested',
            'Reschedule requested',
            "A new time was requested for booking {$booking['booking_code']}.",
            ['admin'],
            (int)$user['id']
        );
        $conn->commit();
        bookingApiResponse(['success' => true, 'message' => 'Reschedule request sent to the gym.']);
    }

    if ($action === 'resolve-reschedule') {
        if (!$isAdmin) {
            bookingApiResponse(['success' => false, 'message' => 'Only administrators can review reschedule requests.'], 403);
        }
        $requestId = (int)($input['request_id'] ?? 0);
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        if ($requestId < 1 || !in_array($decision, ['approve', 'reject'], true)) {
            bookingApiResponse(['success' => false, 'message' => 'Choose a valid reschedule request and decision.'], 400);
        }
        $conn->beginTransaction();
        $requestStmt = $conn->prepare(
            'SELECT r.id AS request_id, r.booking_id, r.requested_date,
                    r.requested_start_time, r.requested_end_time, r.status AS request_status,
                    b.booking_code, b.member_id, b.trainer_id, b.booking_date,
                    b.start_time, b.end_time, b.duration_minutes, b.subtotal, b.credit_amount,
                    b.status AS booking_status
             FROM booking_reschedule_requests r
             JOIN bookings b ON b.id = r.booking_id WHERE r.id = :id FOR UPDATE'
        );
        $requestStmt->execute([':id' => $requestId]);
        $row = $requestStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['request_status'] !== 'pending') {
            $conn->rollBack();
            bookingApiResponse(['success' => false, 'message' => 'Reschedule request is no longer pending.'], 409);
        }
        if ($decision === 'approve') {
            if (!in_array($row['booking_status'], ['pending', 'confirmed'], true)) {
                $conn->rollBack();
                bookingApiResponse(['success' => false, 'message' => 'Reschedule request cannot be approved.'], 409);
            }
            bookingApiLockParticipants($conn, (int)$row['trainer_id'], (int)$row['member_id']);
            $end = bookingApiAssertSlot(
                $conn,
                (int)$row['trainer_id'],
                (int)$row['member_id'],
                (string)$row['requested_date'],
                (string)$row['requested_start_time'],
                (int)$row['duration_minutes'],
                (int)$row['booking_id']
            );
            $creditAdjustment = null;
            if ((int)round((float)($row['credit_amount'] ?? 0) * 100) > 0) {
                $creditCoversDate = $row['booking_status'] === 'pending'
                    ? bookingReservedCreditCoversDate($conn, (int)$row['booking_id'], (string)$row['requested_date'])
                    : bookingConsumedCreditCoversDate($conn, (int)$row['booking_id'], (string)$row['requested_date']);
                if ($creditCoversDate === null) {
                    $conn->rollBack();
                    bookingApiResponse(['success' => false, 'message' => 'The booking credit is no longer available for this reschedule.'], 409);
                }
                if (!$creditCoversDate && $row['booking_status'] === 'pending') {
                    bookingReleaseReservedCredit($conn, (int)$row['booking_id'], (int)$user['id']);
                    $creditAdjustment = [
                        'credit_amount' => '0.00',
                        'amount_due' => number_format((int)round((float)$row['subtotal'] * 100) / 100, 2, '.', ''),
                    ];
                } elseif (!$creditCoversDate) {
                    $conn->rollBack();
                    bookingApiResponse(['success' => false, 'message' => 'A credit-covered booking can only be rescheduled within its subscription period.'], 409);
                }
            }
            $updateBooking = $conn->prepare(
                'UPDATE bookings SET booking_date = :date, start_time = :start, end_time = :end,
                    credit_amount = COALESCE(:credit_amount, credit_amount),
                    amount_due = COALESCE(:amount_due, amount_due), updated_by_user_id = :user_id
                 WHERE id = :id AND status IN ("pending", "confirmed")'
            );
            $updateBooking->execute([
                ':date' => $row['requested_date'],
                ':start' => $row['requested_start_time'],
                ':end' => $end,
                ':credit_amount' => $creditAdjustment['credit_amount'] ?? null,
                ':amount_due' => $creditAdjustment['amount_due'] ?? null,
                ':user_id' => (int)$user['id'],
                ':id' => (int)$row['booking_id'],
            ]);
            if ($updateBooking->rowCount() !== 1
                && ((string)$row['booking_date'] !== (string)$row['requested_date']
                    || substr((string)$row['start_time'], 0, 5) !== substr((string)$row['requested_start_time'], 0, 5))) {
                $conn->rollBack();
                bookingApiResponse(['success' => false, 'message' => 'The booking is no longer eligible for rescheduling.'], 409);
            }
        }
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $resolve = $conn->prepare(
            'UPDATE booking_reschedule_requests SET status = :status, resolved_by_user_id = :user_id WHERE id = :id AND status = "pending"'
        );
        $resolve->execute([':status' => $status, ':user_id' => (int)$user['id'], ':id' => (int)$row['request_id']]);
        $booking = [
            'id' => (int)$row['booking_id'],
            'booking_code' => $row['booking_code'],
            'member_id' => (int)$row['member_id'],
            'trainer_id' => (int)$row['trainer_id'],
        ];
        bookingNotifyEvent(
            $conn,
            $booking,
            'booking_reschedule_' . $status,
            $decision === 'approve' ? 'Reschedule approved' : 'Reschedule request declined',
            $decision === 'approve'
                ? ($creditAdjustment
                    ? "The new time for booking {$row['booking_code']} was approved; its subscription credit does not cover the new date, so payment will be required after trainer approval."
                    : "The new time for booking {$row['booking_code']} was approved.")
                : "The reschedule request for booking {$row['booking_code']} was declined.",
            [],
            (int)$user['id']
        );
        $conn->commit();
        bookingApiResponse(['success' => true, 'message' => 'Reschedule request reviewed.']);
    }

    bookingApiResponse(['success' => false, 'message' => 'Invalid booking action.'], 400);
} catch (RuntimeException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $status = (int)$e->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    if ($status === 500) {
        error_log('Booking operation failed: ' . $e->getMessage());
        bookingApiResponse(['success' => false, 'message' => 'Unable to process the booking. Please try again.'], 500);
    }
    bookingApiResponse(['success' => false, 'message' => $e->getMessage()], $status);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Booking operation failed: ' . $e->getMessage());
    bookingApiResponse(['success' => false, 'message' => 'Unable to process the booking. Please try again.'], 500);
}
