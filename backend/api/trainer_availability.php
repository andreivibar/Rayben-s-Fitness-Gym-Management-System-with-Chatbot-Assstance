<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/booking.php';

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function ensureTrainerSchedulePreservesBookings($conn, $trainerId) {
    if (bookingFindInvalidReservations($conn, (int)$trainerId)) {
        $conn->rollBack();
        sendResponse([
            'success' => false,
            'message' => 'This schedule change would make an existing booking unavailable. Resolve the affected bookings first.',
        ], 409);
    }
}

function getAllowedDays() {
    return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
}

function isValidTimeFormat($value) {
    if (!is_string($value) || $value === '') {
        return false;
    }

    $date = DateTime::createFromFormat('!H:i', $value);
    return $date && $date->format('H:i') === $value;
}

function getCurrentTrainerId($conn, $authUser, $requestedTrainerId = 0) {
    $authRole = strtolower(trim((string)($authUser['role'] ?? '')));
    if (in_array($authRole, ['admin', 'administrator'], true)) {
        $trainerId = (int)$requestedTrainerId;
        if ($trainerId <= 0) {
            sendResponse(['success' => false, 'message' => 'Trainer ID is required for administrator schedule management.'], 400);
        }
        $stmt = $conn->prepare('SELECT id FROM trainers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $trainerId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            sendResponse(['success' => false, 'message' => 'Trainer profile not found.'], 404);
        }
        return $trainerId;
    }

    if ($authRole !== 'trainer') {
        sendResponse(['success' => false, 'message' => 'Only trainers or administrators can view trainer availability.'], 403);
    }

    $stmt = $conn->prepare('SELECT id FROM trainers WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => (int)$authUser['id']]);
    $trainerId = (int)$stmt->fetchColumn();
    if (!$trainerId) {
        sendResponse(['success' => false, 'message' => 'Trainer profile not found for this account.'], 404);
    }
    return $trainerId;
}

function validateScheduleValues($input, $existing = null) {
    $days = getAllowedDays();
    $shiftTypes = ['custom', 'morning', 'afternoon', 'night'];
    $day = trim((string)($input['day_of_week'] ?? $existing['day_of_week'] ?? ''));
    $start = trim((string)($input['start_time'] ?? $existing['start_time'] ?? ''));
    $end = trim((string)($input['end_time'] ?? $existing['end_time'] ?? ''));
    $shiftType = strtolower(trim((string)($input['shift_type'] ?? $input['schedule_type'] ?? $existing['schedule_type'] ?? 'custom')));
    $status = strtolower(trim((string)($input['status'] ?? $existing['status'] ?? 'active')));

    if (!in_array($day, $days, true)) {
        sendResponse(['success' => false, 'message' => 'Select a valid day of the week.'], 400);
    }
    if (!in_array($shiftType, $shiftTypes, true)) {
        sendResponse(['success' => false, 'message' => 'Choose Morning, Afternoon, or Night Shift.'], 400);
    }
    if (!isValidTimeFormat($start) || !isValidTimeFormat($end) || $end <= $start) {
        sendResponse(['success' => false, 'message' => 'Choose valid working hours with an end time later than the start time.'], 400);
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        sendResponse(['success' => false, 'message' => 'Status must be active or inactive.'], 400);
    }

    if (array_key_exists('lunch_enabled', $input)) {
        if (!is_bool($input['lunch_enabled'])) {
            sendResponse(['success' => false, 'message' => 'Choose whether to include a lunch break.'], 400);
        }
        $lunchEnabled = $input['lunch_enabled'];
    } else {
        $lunchEnabled = !empty($existing['lunch_start']) && !empty($existing['lunch_end']);
    }

    if ($lunchEnabled && ($start > '12:00' || $end < '13:00')) {
        sendResponse(['success' => false, 'message' => "The 12:00 PM–1:00 PM lunch break must fall within {$day}'s working hours. Disable lunch or adjust this shift."], 400);
    }

    return [
        'day_of_week' => $day,
        'start_time' => $start . ':00',
        'end_time' => $end . ':00',
        'schedule_type' => $shiftType,
        'lunch_start' => $lunchEnabled ? '12:00:00' : null,
        'lunch_end' => $lunchEnabled ? '13:00:00' : null,
        'status' => $status
    ];
}

function assertNoScheduleOverlap($conn, $trainerId, $values, $excludeId = 0) {
    $stmt = $conn->prepare(
        'SELECT id FROM trainer_availability
         WHERE trainer_id = :trainer_id AND day_of_week = :day AND LOWER(status) = "active"
           AND id <> :exclude_id
           AND NOT (end_time <= :start_time OR start_time >= :end_time)
         LIMIT 1'
    );
    $stmt->execute([
        ':trainer_id' => $trainerId,
        ':day' => $values['day_of_week'],
        ':exclude_id' => $excludeId,
        ':start_time' => $values['start_time'],
        ':end_time' => $values['end_time']
    ]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        sendResponse(['success' => false, 'message' => 'This shift overlaps another active shift for this trainer and day. Adjust one of the shift times.'], 409);
    }
}

function lockTrainer($conn, $trainerId) {
    $stmt = $conn->prepare('SELECT id FROM trainers WHERE id = :trainer_id FOR UPDATE');
    $stmt->execute([':trainer_id' => $trainerId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        sendResponse(['success' => false, 'message' => 'Trainer profile not found.'], 404);
    }
}

function insertScheduleRow($conn, $trainerId, $values) {
    $stmt = $conn->prepare(
        'INSERT INTO trainer_availability
         (trainer_id, day_of_week, start_time, end_time, schedule_type, lunch_start, lunch_end, status, created_at)
         VALUES (:trainer_id, :day, :start_time, :end_time, :schedule_type, :lunch_start, :lunch_end, :status, NOW())'
    );
    $stmt->execute([
        ':trainer_id' => $trainerId,
        ':day' => $values['day_of_week'],
        ':start_time' => $values['start_time'],
        ':end_time' => $values['end_time'],
        ':schedule_type' => $values['schedule_type'],
        ':lunch_start' => $values['lunch_start'],
        ':lunch_end' => $values['lunch_end'],
        ':status' => $values['status']
    ]);
    return (int)$conn->lastInsertId();
}

try {
    $authUser = requireRole($conn, ['trainer', 'admin', 'administrator']);
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS trainer_availability (
            id INT AUTO_INCREMENT PRIMARY KEY,
            trainer_id INT NOT NULL,
            day_of_week VARCHAR(16) NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            schedule_type ENUM('custom', 'morning', 'afternoon', 'night') NOT NULL DEFAULT 'custom',
            lunch_start TIME DEFAULT NULL,
            lunch_end TIME DEFAULT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_trainer_day (trainer_id, day_of_week),
            KEY idx_trainer_status (trainer_id, status),
            CONSTRAINT fk_trainer_availability_trainer FOREIGN KEY (trainer_id) REFERENCES trainers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = [];
    }

    $action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? 'list')));
    $isAdmin = in_array(strtolower(trim((string)($authUser['role'] ?? ''))), ['admin', 'administrator'], true);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'PUT') {
        $action = 'update';
    } elseif ($method === 'DELETE') {
        $action = 'delete';
    }

    if ($method !== 'GET' && !$isAdmin) {
        sendResponse(['success' => false, 'message' => 'Only administrators can change trainer schedules.'], 403);
    }

    $trainerId = getCurrentTrainerId($conn, $authUser, $_GET['trainer_id'] ?? $input['trainer_id'] ?? 0);

    if ($method === 'GET') {
        $days = getAllowedDays();
        $stmt = $conn->prepare(
            'SELECT id, day_of_week, TIME_FORMAT(start_time, "%H:%i:%s") AS start_time,
                    TIME_FORMAT(end_time, "%H:%i:%s") AS end_time, schedule_type,
                    TIME_FORMAT(lunch_start, "%H:%i:%s") AS lunch_start,
                    TIME_FORMAT(lunch_end, "%H:%i:%s") AS lunch_end, status
             FROM trainer_availability WHERE trainer_id = :trainer_id
             ORDER BY FIELD(day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"), start_time ASC, id ASC'
        );
        $stmt->execute([':trainer_id' => $trainerId]);
        $grouped = array_fill_keys($days, []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[$row['day_of_week']][] = [
                'id' => (int)$row['id'],
                'day_of_week' => $row['day_of_week'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'schedule_type' => $row['schedule_type'],
                'lunch_start' => $row['lunch_start'],
                'lunch_end' => $row['lunch_end'],
                'status' => strtolower((string)$row['status'])
            ];
        }
        $exceptionStmt = $conn->prepare(
            'SELECT id, DATE_FORMAT(exception_date, "%Y-%m-%d") AS exception_date,
                    exception_type, TIME_FORMAT(start_time, "%H:%i") AS start_time,
                    TIME_FORMAT(end_time, "%H:%i") AS end_time, reason, status
             FROM trainer_schedule_exceptions
             WHERE trainer_id = :trainer_id AND status = "active"
               AND exception_date >= CURDATE()
             ORDER BY exception_date, start_time, id'
        );
        $exceptionStmt->execute([':trainer_id' => $trainerId]);
        sendResponse([
            'success' => true,
            'schedule' => $grouped,
            'exceptions' => $exceptionStmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    }

    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        sendResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    if ($action === 'assign') {
        sendResponse(['success' => false, 'message' => 'Bulk schedule replacement is no longer supported. Add, update, or remove individual shifts instead.'], 400);
    }

    if ($action === 'exception-create') {
        $date = trim((string)($input['exception_date'] ?? ''));
        $type = strtolower(trim((string)($input['exception_type'] ?? '')));
        $reason = trim((string)($input['reason'] ?? ''));
        $start = trim((string)($input['start_time'] ?? ''));
        $end = trim((string)($input['end_time'] ?? ''));
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
        if (!$dateValue || $dateValue->format('Y-m-d') !== $date || $dateValue < new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'))) {
            sendResponse(['success' => false, 'message' => 'Choose a valid date today or later.'], 400);
        }
        if (!in_array($type, ['off', 'available', 'blocked'], true)) {
            sendResponse(['success' => false, 'message' => 'Choose a day off, temporary working shift, or blocked period.'], 400);
        }
        if ($type !== 'off' && (!isValidTimeFormat($start) || !isValidTimeFormat($end) || $end <= $start)) {
            sendResponse(['success' => false, 'message' => 'Choose valid start and end times.'], 400);
        }
        if (mb_strlen($reason) > 255) {
            sendResponse(['success' => false, 'message' => 'The note must not exceed 255 characters.'], 400);
        }
        $conn->beginTransaction();
        lockTrainer($conn, $trainerId);
        $insert = $conn->prepare(
            'INSERT INTO trainer_schedule_exceptions
             (trainer_id, exception_date, exception_type, start_time, end_time, reason, created_by_user_id)
             VALUES (:trainer_id, :date, :type, :start_time, :end_time, :reason, :created_by)'
        );
        $insert->execute([
            ':trainer_id' => $trainerId,
            ':date' => $date,
            ':type' => $type,
            ':start_time' => $type === 'off' ? null : $start . ':00',
            ':end_time' => $type === 'off' ? null : $end . ':00',
            ':reason' => $reason !== '' ? $reason : null,
            ':created_by' => (int)$authUser['id'],
        ]);
        ensureTrainerSchedulePreservesBookings($conn, $trainerId);
        $conn->commit();
        sendResponse(['success' => true, 'id' => (int)$conn->lastInsertId(), 'message' => 'Schedule exception saved.']);
    }

    if ($action === 'exception-delete') {
        $exceptionId = (int)($input['id'] ?? 0);
        if ($exceptionId < 1) {
            sendResponse(['success' => false, 'message' => 'Schedule exception ID is required.'], 400);
        }
        $conn->beginTransaction();
        lockTrainer($conn, $trainerId);
        $updateException = $conn->prepare(
            'UPDATE trainer_schedule_exceptions SET status = "inactive"
             WHERE id = :id AND trainer_id = :trainer_id AND status = "active"'
        );
        $updateException->execute([':id' => $exceptionId, ':trainer_id' => $trainerId]);
        if ($updateException->rowCount() !== 1) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Active schedule exception not found.'], 404);
        }
        ensureTrainerSchedulePreservesBookings($conn, $trainerId);
        $conn->commit();
        sendResponse(['success' => true, 'message' => 'Schedule exception removed.']);
    }

    if ($action === 'create') {
        $values = validateScheduleValues($input);
        $conn->beginTransaction();
        lockTrainer($conn, $trainerId);
        if ($values['status'] === 'active') {
            assertNoScheduleOverlap($conn, $trainerId, $values);
        }
        $newId = insertScheduleRow($conn, $trainerId, $values);
        ensureTrainerSchedulePreservesBookings($conn, $trainerId);
        $conn->commit();
        sendResponse(['success' => true, 'id' => $newId, 'message' => 'Trainer shift added successfully.']);
    }

    if ($action === 'update') {
        $slotId = (int)($input['id'] ?? 0);
        if ($slotId <= 0) {
            sendResponse(['success' => false, 'message' => 'Shift ID is required.'], 400);
        }
        $values = validateScheduleValues($input);
        $conn->beginTransaction();
        lockTrainer($conn, $trainerId);
        $slotStmt = $conn->prepare('SELECT * FROM trainer_availability WHERE id = :id AND trainer_id = :trainer_id FOR UPDATE');
        $slotStmt->execute([':id' => $slotId, ':trainer_id' => $trainerId]);
        $existing = $slotStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Shift not found for this trainer.'], 404);
        }
        if (strtolower((string)$existing['status']) !== 'active') {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'This shift is inactive and can no longer be edited.'], 409);
        }
        if ($values['status'] === 'active') {
            assertNoScheduleOverlap($conn, $trainerId, $values, $slotId);
        }

        $retire = $conn->prepare('UPDATE trainer_availability SET status = "inactive" WHERE id = :id AND status = "active"');
        $retire->execute([':id' => $slotId]);
        $newId = insertScheduleRow($conn, $trainerId, $values);
        ensureTrainerSchedulePreservesBookings($conn, $trainerId);
        $conn->commit();
        sendResponse(['success' => true, 'id' => $newId, 'message' => 'Trainer shift updated successfully.']);
    }

    if ($action === 'delete' || $action === 'status') {
        $slotId = (int)($input['id'] ?? 0);
        if ($slotId <= 0) {
            sendResponse(['success' => false, 'message' => 'Shift ID is required.'], 400);
        }
        $status = $action === 'delete' ? 'inactive' : strtolower(trim((string)($input['status'] ?? '')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            sendResponse(['success' => false, 'message' => 'Status must be active or inactive.'], 400);
        }

        $conn->beginTransaction();
        lockTrainer($conn, $trainerId);
        $slotStmt = $conn->prepare('SELECT * FROM trainer_availability WHERE id = :id AND trainer_id = :trainer_id FOR UPDATE');
        $slotStmt->execute([':id' => $slotId, ':trainer_id' => $trainerId]);
        $slot = $slotStmt->fetch(PDO::FETCH_ASSOC);
        if (!$slot) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Shift not found for this trainer.'], 404);
        }
        if ($status === 'active') {
            assertNoScheduleOverlap($conn, $trainerId, [
                'day_of_week' => $slot['day_of_week'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time']
            ], $slotId);
        }
        $update = $conn->prepare('UPDATE trainer_availability SET status = :status WHERE id = :id');
        $update->execute([':status' => $status, ':id' => $slotId]);
        ensureTrainerSchedulePreservesBookings($conn, $trainerId);
        $conn->commit();
        sendResponse([
            'success' => true,
            'message' => $status === 'inactive' ? 'Trainer shift removed. Attendance history was preserved.' : 'Trainer shift activated successfully.'
        ]);
    }

    sendResponse(['success' => false, 'message' => 'Invalid schedule action.'], 400);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        sendResponse(['success' => false, 'message' => 'This schedule change conflicts with an existing record. Refresh and try again.'], 409);
    }
    error_log('Trainer schedule operation failed: ' . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'Unable to process the trainer schedule. Please try again.'], 500);
}

?>
