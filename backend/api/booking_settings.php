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
require_once __DIR__ . '/../config/booking.php';
require_once __DIR__ . '/../config/booking_pricing.php';

function bookingSettingsResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function bookingSettingsIsAdmin(array $user): bool
{
    return in_array(strtolower(trim((string)($user['role'] ?? ''))), ['admin', 'administrator'], true);
}

try {
    $user = requireAuth($conn);
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? 'list')));

    if ($method === 'GET') {
        $sessionStmt = $conn->query(
            'SELECT id, name, duration_minutes, service_category, is_active FROM booking_session_types ORDER BY name'
        );
        $priceHistoryFilter = bookingSettingsIsAdmin($user)
            ? ''
            : 'WHERE p.retired_at IS NULL OR p.retired_at > NOW()';
        $priceStmt = $conn->query(
            'SELECT p.id, p.session_type_id, p.amount, p.currency, p.effective_from, p.retired_at,
                    p.created_at, p.created_by, p.retired_by,
                    (p.effective_from <= NOW() AND (p.retired_at IS NULL OR p.retired_at > NOW())) AS is_current,
                    (p.effective_from > NOW() AND p.retired_at IS NULL) AS is_scheduled
             FROM booking_session_prices p ' . $priceHistoryFilter . '
             ORDER BY p.session_type_id, p.effective_from DESC, p.id DESC'
        );
        $hoursStmt = $conn->query(
            'SELECT day_of_week, is_open, TIME_FORMAT(opens_at, "%H:%i") AS opens_at,
                    TIME_FORMAT(closes_at, "%H:%i") AS closes_at
             FROM gym_operating_hours'
        );
        $hoursByDay = [];
        foreach ($hoursStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hoursByDay[$row['day_of_week']] = [
                'is_open' => (bool)$row['is_open'],
                'opens_at' => $row['opens_at'],
                'closes_at' => $row['closes_at'],
            ];
        }
        foreach ($days as $day) {
            if (!isset($hoursByDay[$day])) {
                $hoursByDay[$day] = ['is_open' => false, 'opens_at' => null, 'closes_at' => null];
            }
        }
        $maxAdvanceDays = bookingGetMaxAdvanceDays($conn);
        bookingSettingsResponse([
            'success' => true,
            'session_types' => $sessionStmt->fetchAll(PDO::FETCH_ASSOC),
            'price_versions' => $priceStmt->fetchAll(PDO::FETCH_ASSOC),
            'operating_hours' => $hoursByDay,
            'max_advance_days' => $maxAdvanceDays,
            'can_manage' => bookingSettingsIsAdmin($user),
        ]);
    }

    if ($method !== 'POST') {
        bookingSettingsResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }
    if (!bookingSettingsIsAdmin($user)) {
        bookingSettingsResponse(['success' => false, 'message' => 'Only administrators can change booking settings.'], 403);
    }

    if ($action === 'save-session-type') {
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $duration = filter_var($input['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        $active = !empty($input['is_active']) ? 1 : 0;
        $serviceCategory = strtolower(trim((string)($input['service_category'] ?? 'other')));
        if (!in_array($serviceCategory, ['personal_training', 'other'], true)) {
            bookingSettingsResponse(['success' => false, 'message' => 'Choose a valid session category.'], 400);
        }
        if ($name === '' || mb_strlen($name) > 64) {
            bookingSettingsResponse(['success' => false, 'message' => 'Session name is required and must be 64 characters or fewer.'], 400);
        }
        if ($duration === false || $duration < 15 || $duration > 480 || $duration % 15 !== 0) {
            bookingSettingsResponse(['success' => false, 'message' => 'Session duration must be from 15 to 480 minutes in 15-minute increments.'], 400);
        }

        if ($id > 0) {
            $stmt = $conn->prepare(
                'UPDATE booking_session_types SET name = :name, duration_minutes = :duration, service_category = :category, is_active = :active WHERE id = :id'
            );
            $stmt->execute([':name' => $name, ':duration' => $duration, ':category' => $serviceCategory, ':active' => $active, ':id' => $id]);
            if ($stmt->rowCount() === 0) {
                $exists = $conn->prepare('SELECT id FROM booking_session_types WHERE id = :id');
                $exists->execute([':id' => $id]);
                if (!$exists->fetchColumn()) {
                    bookingSettingsResponse(['success' => false, 'message' => 'Session type not found.'], 404);
                }
            }
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO booking_session_types (name, duration_minutes, service_category, is_active) VALUES (:name, :duration, :category, :active)'
            );
            $stmt->execute([':name' => $name, ':duration' => $duration, ':category' => $serviceCategory, ':active' => $active]);
        }
        bookingSettingsResponse(['success' => true, 'message' => 'Session type saved.']);
    }

    if ($action === 'create-price') {
        $sessionTypeId = filter_var($input['session_type_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = trim((string)($input['amount'] ?? ''));
        $effectiveFrom = trim((string)($input['effective_from'] ?? ''));
        if (!$sessionTypeId || $sessionTypeId < 1
            || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)
            || (float)$amount <= 0
            || (float)$amount > 99999999.99) {
            bookingSettingsResponse(['success' => false, 'message' => 'Choose a session type and enter a valid positive price.'], 400);
        }
        if ($effectiveFrom === '') {
            $effectiveFrom = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        } else {
            $effectiveFrom = str_replace('T', ' ', $effectiveFrom);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $effectiveFrom)) {
                $effectiveFrom .= ':00';
            }
            $effectiveAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $effectiveFrom, new DateTimeZone('Asia/Manila'));
            if (!$effectiveAt || $effectiveAt->format('Y-m-d H:i:s') !== $effectiveFrom
                || $effectiveAt < new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
                bookingSettingsResponse(['success' => false, 'message' => 'Effective date must be a valid current or future Manila date and time.'], 400);
            }
        }

        $conn->beginTransaction();
        $typeStmt = $conn->prepare('SELECT id FROM booking_session_types WHERE id = :id FOR UPDATE');
        $typeStmt->execute([':id' => $sessionTypeId]);
        if (!$typeStmt->fetchColumn()) {
            $conn->rollBack();
            bookingSettingsResponse(['success' => false, 'message' => 'Session type not found.'], 404);
        }
        $future = $conn->prepare(
            'SELECT id FROM booking_session_prices
             WHERE session_type_id = :session_type_id AND effective_from >= :effective_from
               AND retired_at IS NULL LIMIT 1 FOR UPDATE'
        );
        $future->execute([':session_type_id' => $sessionTypeId, ':effective_from' => $effectiveFrom]);
        if ($future->fetchColumn()) {
            $conn->rollBack();
            bookingSettingsResponse(['success' => false, 'message' => 'A price is already scheduled at or after this effective time. Retire it before scheduling another version.'], 409);
        }
        $closePrevious = $conn->prepare(
            'UPDATE booking_session_prices SET retired_at = :effective_from
             WHERE session_type_id = :session_type_id AND effective_from < :effective_from
               AND retired_at IS NULL'
        );
        $closePrevious->execute([':effective_from' => $effectiveFrom, ':session_type_id' => $sessionTypeId]);
        $insertPrice = $conn->prepare(
            'INSERT INTO booking_session_prices
                (session_type_id, amount, currency, effective_from, created_by)
             VALUES (:session_type_id, :amount, \'PHP\', :effective_from, :created_by)'
        );
        $insertPrice->execute([
            ':session_type_id' => $sessionTypeId,
            ':amount' => number_format((float)$amount, 2, '.', ''),
            ':effective_from' => $effectiveFrom,
            ':created_by' => (int)$user['id'],
        ]);
        $conn->commit();
        bookingSettingsResponse(['success' => true, 'message' => 'Booking price scheduled.']);
    }

    if ($action === 'retire-price') {
        $priceId = filter_var($input['price_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$priceId || $priceId < 1) {
            bookingSettingsResponse(['success' => false, 'message' => 'Choose a valid price version.'], 400);
        }
        $conn->beginTransaction();
        $priceStmt = $conn->prepare('SELECT id, session_type_id, effective_from, retired_at FROM booking_session_prices WHERE id = :id FOR UPDATE');
        $priceStmt->execute([':id' => $priceId]);
        $price = $priceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$price) {
            $conn->rollBack();
            bookingSettingsResponse(['success' => false, 'message' => 'Price version not found.'], 404);
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        if ($price['retired_at'] !== null && (string)$price['retired_at'] <= $now->format('Y-m-d H:i:s')) {
            $conn->rollBack();
            bookingSettingsResponse(['success' => false, 'message' => 'This price version is already retired.'], 409);
        }
        if ((string)$price['effective_from'] > $now->format('Y-m-d H:i:s')) {
            $previous = $conn->prepare(
                'SELECT id FROM booking_session_prices
                 WHERE session_type_id = :session_type_id AND effective_from < :effective_from
                 ORDER BY effective_from DESC, id DESC LIMIT 1 FOR UPDATE'
            );
            $previous->execute([
                ':session_type_id' => (int)$price['session_type_id'],
                ':effective_from' => $price['effective_from'],
            ]);
            $previousId = (int)$previous->fetchColumn();
            if ($previousId) {
                $restore = $conn->prepare('UPDATE booking_session_prices SET retired_at = NULL WHERE id = :id AND retired_at = :retired_at');
                $restore->execute([':id' => $previousId, ':retired_at' => $price['effective_from']]);
            }
        }
        $retire = $conn->prepare(
            'UPDATE booking_session_prices SET retired_at = :retired_at, retired_by = :retired_by
             WHERE id = :id AND (retired_at IS NULL OR retired_at > :now_compare)'
        );
        $retire->execute([
            ':retired_at' => $now->format('Y-m-d H:i:s'),
            ':now_compare' => $now->format('Y-m-d H:i:s'),
            ':retired_by' => (int)$user['id'],
            ':id' => $priceId,
        ]);
        $conn->commit();
        bookingSettingsResponse(['success' => true, 'message' => 'Price version retired.']);
    }

    if ($action === 'save-booking-window') {
        $days = filter_var($input['max_advance_days'] ?? null, FILTER_VALIDATE_INT);
        if ($days === false || $days === null || $days < 1 || $days > 365) {
            bookingSettingsResponse(['success' => false, 'message' => 'The booking window must be between 1 and 365 days.'], 400);
        }
        $configurationTable = $conn->query(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_configuration'"
        )->fetchColumn();
        if (!$configurationTable) {
            bookingSettingsResponse([
                'success' => false,
                'message' => 'Apply the booking-window migration before saving this setting. Booking requests currently use the 90-day default.',
            ], 503);
        }
        $stmt = $conn->prepare(
            'INSERT INTO booking_configuration (id, max_advance_days)
             VALUES (1, :days)
             ON DUPLICATE KEY UPDATE max_advance_days = VALUES(max_advance_days)'
        );
        $stmt->execute([':days' => $days]);
        bookingSettingsResponse(['success' => true, 'message' => 'Maximum advance-booking window saved.']);
    }

    if ($action === 'save-operating-hours') {
        $hours = $input['operating_hours'] ?? null;
        if (!is_array($hours)) {
            bookingSettingsResponse(['success' => false, 'message' => 'Operating hours must include each day of the week.'], 400);
        }

        $conn->beginTransaction();
        $conn->query('SELECT id FROM trainers ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $conn->prepare(
            'INSERT INTO gym_operating_hours (day_of_week, is_open, opens_at, closes_at)
             VALUES (:day, :is_open, :opens_at, :closes_at)
             ON DUPLICATE KEY UPDATE is_open = VALUES(is_open), opens_at = VALUES(opens_at), closes_at = VALUES(closes_at)'
        );
        foreach ($days as $day) {
            $row = $hours[$day] ?? null;
            if (!is_array($row) || !array_key_exists('is_open', $row) || !is_bool($row['is_open'])) {
                $conn->rollBack();
                bookingSettingsResponse(['success' => false, 'message' => "Choose whether the gym is open on {$day}."], 400);
            }
            $open = $row['is_open'];
            $start = trim((string)($row['opens_at'] ?? ''));
            $end = trim((string)($row['closes_at'] ?? ''));
            if ($open && (!bookingValidTime($start) || !bookingValidTime($end) || $end <= $start)) {
                $conn->rollBack();
                bookingSettingsResponse(['success' => false, 'message' => "Set valid opening and closing times for {$day}."], 400);
            }
            $stmt->execute([
                ':day' => $day,
                ':is_open' => $open ? 1 : 0,
                ':opens_at' => $open ? substr($start, 0, 5) . ':00' : null,
                ':closes_at' => $open ? substr($end, 0, 5) . ':00' : null,
            ]);
        }
        if (bookingFindInvalidReservations($conn)) {
            $conn->rollBack();
            bookingSettingsResponse([
                'success' => false,
                'message' => 'These operating hours would make an existing booking unavailable. Resolve the affected bookings first.',
            ], 409);
        }
        $conn->commit();
        bookingSettingsResponse(['success' => true, 'message' => 'Gym operating hours saved.']);
    }

    bookingSettingsResponse(['success' => false, 'message' => 'Invalid booking settings action.'], 400);
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Booking settings operation failed: ' . $e->getMessage());
    if (($e->errorInfo[1] ?? null) === 1062) {
        bookingSettingsResponse(['success' => false, 'message' => 'A session type with that name already exists.'], 409);
    }
    bookingSettingsResponse(['success' => false, 'message' => 'Unable to save booking settings. Please try again.'], 500);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Booking settings operation failed: ' . $e->getMessage());
    bookingSettingsResponse(['success' => false, 'message' => 'Unable to save booking settings. Please try again.'], 500);
}
