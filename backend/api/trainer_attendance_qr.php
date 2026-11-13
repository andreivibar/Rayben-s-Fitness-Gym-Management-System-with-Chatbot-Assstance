<?php

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/attendance_qr_tokens.php';

function trainerAttendanceQrResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function trainerAttendanceQrActiveSlots($conn, $trainerId, $day) {
    $stmt = $conn->prepare(
        "SELECT id, TIME_FORMAT(start_time, '%H:%i:%s') AS scheduled_start,
                TIME_FORMAT(end_time, '%H:%i:%s') AS scheduled_end
         FROM trainer_availability
         WHERE trainer_id = :trainer_id AND day_of_week = :day AND LOWER(status) = 'active'
         ORDER BY start_time ASC"
    );
    $stmt->execute([':trainer_id' => $trainerId, ':day' => $day]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function trainerAttendanceQrSelectSlot($slots, DateTimeImmutable $now) {
    $currentSlots = [];
    $upcomingSlots = [];
    foreach ($slots as $slot) {
        $start = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $slot['scheduled_start'], $now->getTimezone());
        $end = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $slot['scheduled_end'], $now->getTimezone());
        $graceStart = $start->modify('-5 minutes');
        if ($now >= $start && $now <= $end) {
            $currentSlots[] = $slot;
        } elseif ($now >= $graceStart && $now < $start) {
            $upcomingSlots[] = $slot;
        }
    }

    if (count($currentSlots) === 1) {
        return $currentSlots[0];
    }
    if (count($currentSlots) > 1 || count($upcomingSlots) > 1) {
        return null;
    }
    return $upcomingSlots[0] ?? null;
}

try {
    $authUser = requireRole($conn, ['trainer']);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST, OPTIONS');
        trainerAttendanceQrResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        trainerAttendanceQrResponse(['success' => false, 'message' => 'A valid JSON request is required.'], 400);
    }

    $operation = strtolower(trim((string)($input['operation'] ?? '')));
    $token = strtolower(trim((string)($input['token'] ?? '')));
    if (!in_array($operation, ['preview', 'confirm'], true)) {
        trainerAttendanceQrResponse(['success' => false, 'message' => 'A valid attendance operation is required.'], 400);
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        trainerAttendanceQrResponse(['success' => false, 'message' => 'The gym QR code is invalid. Scan the current code displayed at the gym.'], 400);
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $trainerIdentityStmt = $conn->prepare('SELECT id FROM trainers WHERE user_id = :user_id LIMIT 1');
    $trainerIdentityStmt->execute([':user_id' => (int)$authUser['id']]);
    $trainerId = (int)$trainerIdentityStmt->fetchColumn();
    if (!$trainerId) {
        trainerAttendanceQrResponse(['success' => false, 'message' => 'Trainer profile not found.'], 404);
    }

    $windowStart = $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0);
    $deleteOldLimits = $conn->prepare(
        'DELETE FROM attendance_qr_trainer_rate_limits WHERE window_start < :cutoff'
    );
    $deleteOldLimits->execute([':cutoff' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);
    $rateLimitStmt = $conn->prepare(
        'INSERT INTO attendance_qr_trainer_rate_limits (trainer_id, window_start, request_count)
         VALUES (:trainer_id, :window_start, 1)
         ON DUPLICATE KEY UPDATE request_count = request_count + 1'
    );
    $rateLimitStmt->execute([
        ':trainer_id' => $trainerId,
        ':window_start' => $windowStart->format('Y-m-d H:i:s')
    ]);
    $rateCountStmt = $conn->prepare(
        'SELECT request_count FROM attendance_qr_trainer_rate_limits
         WHERE trainer_id = :trainer_id AND window_start = :window_start'
    );
    $rateCountStmt->execute([
        ':trainer_id' => $trainerId,
        ':window_start' => $windowStart->format('Y-m-d H:i:s')
    ]);
    if ((int)$rateCountStmt->fetchColumn() > 12) {
        trainerAttendanceQrResponse(['success' => false, 'message' => 'Too many attendance requests. Please wait a minute and try again.'], 429);
    }

    $conn->beginTransaction();

    $trainerStmt = $conn->prepare(
        'SELECT t.id, t.trainer_code, t.status, u.full_name
         FROM trainers t
         JOIN users u ON u.id = t.user_id
         WHERE t.user_id = :user_id
         LIMIT 1
         FOR UPDATE'
    );
    $trainerStmt->execute([':user_id' => (int)$authUser['id']]);
    $trainer = $trainerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$trainer) {
        $conn->rollBack();
        trainerAttendanceQrResponse(['success' => false, 'message' => 'Trainer profile not found.'], 404);
    }

    $tokenRow = findCurrentAttendanceQrToken($conn, $token, $now);
    if (!$tokenRow) {
        $conn->rollBack();
        trainerAttendanceQrResponse(['success' => false, 'message' => 'This gym QR code is invalid or has expired. Scan the latest code displayed at the gym.'], 410);
    }

    $openStmt = $conn->prepare(
        'SELECT id, availability_id, attendance_date, scheduled_start, scheduled_end, time_in, time_out, status
         FROM trainer_attendance
         WHERE trainer_id = :trainer_id AND time_in IS NOT NULL AND time_out IS NULL
         ORDER BY attendance_date DESC, id DESC
         LIMIT 2
         FOR UPDATE'
    );
    $openStmt->execute([':trainer_id' => (int)$trainer['id']]);
    $openRows = $openStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($openRows) > 1) {
        $conn->rollBack();
        trainerAttendanceQrResponse(['success' => false, 'message' => 'Multiple open trainer attendance sessions require administrator assistance.'], 409);
    }

    $action = $openRows ? 'time_out' : 'time_in';
    if (strtolower(trim((string)$trainer['status'])) !== 'active' && $action === 'time_in') {
        $conn->rollBack();
        trainerAttendanceQrResponse(['success' => false, 'message' => 'Inactive trainers cannot start an attendance session.'], 403);
    }

    $useStmt = $conn->prepare(
        'SELECT id FROM attendance_qr_trainer_token_uses
         WHERE qr_token_id = :token_id AND trainer_id = :trainer_id
         LIMIT 1
         FOR UPDATE'
    );
    $useStmt->execute([
        ':token_id' => (int)$tokenRow['id'],
        ':trainer_id' => (int)$trainer['id']
    ]);
    $tokenUse = $useStmt->fetch(PDO::FETCH_ASSOC);
    if ($tokenUse && $action !== 'time_out') {
        $conn->rollBack();
        trainerAttendanceQrResponse(['success' => false, 'message' => 'This QR code was already used. Scan the current code displayed at the gym.'], 409);
    }

    $attendance = $openRows[0] ?? null;
    $selectedSlot = null;
    $today = $now->format('Y-m-d');
    if ($action === 'time_in') {
        $dayName = $now->format('l');
        $selectedSlot = trainerAttendanceQrSelectSlot(
            trainerAttendanceQrActiveSlots($conn, (int)$trainer['id'], $dayName),
            $now
        );

        if ($selectedSlot) {
            $existingStmt = $conn->prepare(
                'SELECT id FROM trainer_attendance
                 WHERE trainer_id = :trainer_id AND availability_id = :availability_id
                   AND attendance_date = :attendance_date
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingStmt->execute([
                ':trainer_id' => (int)$trainer['id'],
                ':availability_id' => (int)$selectedSlot['id'],
                ':attendance_date' => $today
            ]);
        } else {
            $existingStmt = $conn->prepare(
                'SELECT id FROM trainer_attendance
                 WHERE trainer_id = :trainer_id AND availability_id IS NULL
                   AND attendance_date = :attendance_date
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingStmt->execute([
                ':trainer_id' => (int)$trainer['id'],
                ':attendance_date' => $today
            ]);
        }
        if ($existingStmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->rollBack();
            trainerAttendanceQrResponse(['success' => false, 'message' => 'Attendance for this trainer session has already been completed.'], 409);
        }
    }

    if ($operation === 'preview') {
        $conn->commit();
        trainerAttendanceQrResponse([
            'success' => true,
            'trainer' => [
                'full_name' => $trainer['full_name'],
                'trainer_code' => $trainer['trainer_code']
            ],
            'action' => $action,
            'attendance' => $attendance ? [
                'attendance_date' => $attendance['attendance_date'],
                'time_in' => $attendance['time_in'],
                'time_out' => $attendance['time_out']
            ] : null
        ]);
    }

    $expectedAction = strtolower(trim((string)($input['expected_action'] ?? '')));
    if (!in_array($expectedAction, ['time_in', 'time_out'], true) || $expectedAction !== $action) {
        $conn->rollBack();
        trainerAttendanceQrResponse([
            'success' => false,
            'message' => 'Your attendance status changed. Scan the current gym QR code again to review the next action.'
        ], 409);
    }

    if (!$tokenUse) {
        $insertUse = $conn->prepare(
            'INSERT INTO attendance_qr_trainer_token_uses (qr_token_id, trainer_id, used_at)
             VALUES (:token_id, :trainer_id, :used_at)'
        );
        $insertUse->execute([
            ':token_id' => (int)$tokenRow['id'],
            ':trainer_id' => (int)$trainer['id'],
            ':used_at' => $now->format('Y-m-d H:i:s')
        ]);
    }

    $time = $now->format('H:i:s');
    if ($action === 'time_in') {
        $status = 'present';
        $availabilityId = null;
        $scheduledStart = null;
        $scheduledEnd = null;
        if ($selectedSlot) {
            $availabilityId = (int)$selectedSlot['id'];
            $scheduledStart = $selectedSlot['scheduled_start'];
            $scheduledEnd = $selectedSlot['scheduled_end'];
            $scheduledStartAt = new DateTimeImmutable($today . ' ' . $scheduledStart, $now->getTimezone());
            $status = $now <= $scheduledStartAt->modify('+5 minutes') ? 'present' : 'late';
        }

        $insertAttendance = $conn->prepare(
            'INSERT INTO trainer_attendance
             (trainer_id, availability_id, attendance_date, scheduled_start, scheduled_end, time_in, status, created_at)
             VALUES (:trainer_id, :availability_id, :attendance_date, :scheduled_start, :scheduled_end, :time_in, :status, NOW())'
        );
        $insertAttendance->execute([
            ':trainer_id' => (int)$trainer['id'],
            ':availability_id' => $availabilityId,
            ':attendance_date' => $today,
            ':scheduled_start' => $scheduledStart,
            ':scheduled_end' => $scheduledEnd,
            ':time_in' => $time,
            ':status' => $status
        ]);
        $attendance = [
            'attendance_date' => $today,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
            'time_in' => $time,
            'time_out' => null,
            'status' => $status
        ];
    } else {
        $updateAttendance = $conn->prepare(
            'UPDATE trainer_attendance
             SET time_out = :time_out
             WHERE id = :id AND time_in IS NOT NULL AND time_out IS NULL'
        );
        $updateAttendance->execute([':time_out' => $time, ':id' => (int)$attendance['id']]);
        if ($updateAttendance->rowCount() !== 1) {
            $conn->rollBack();
            trainerAttendanceQrResponse(['success' => false, 'message' => 'This attendance session was already checked out. Scan again to review your status.'], 409);
        }
        $attendance['time_out'] = $time;
    }

    $conn->commit();
    trainerAttendanceQrResponse([
        'success' => true,
        'action' => $action,
        'message' => $action === 'time_in' ? 'Trainer check-in recorded successfully.' : 'Trainer check-out recorded successfully.',
        'trainer' => [
            'full_name' => $trainer['full_name'],
            'trainer_code' => $trainer['trainer_code']
        ],
        'attendance' => [
            'attendance_date' => $attendance['attendance_date'],
            'scheduled_start' => $attendance['scheduled_start'] ?? null,
            'scheduled_end' => $attendance['scheduled_end'] ?? null,
            'time_in' => $attendance['time_in'],
            'time_out' => $attendance['time_out'],
            'status' => $attendance['status'],
            'recorded_at' => $now->format('Y-m-d H:i:s')
        ]
    ]);
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($e->getCode() === '23000') {
        trainerAttendanceQrResponse(['success' => false, 'message' => 'This attendance scan was already processed. Review your attendance and try again.'], 409);
    }
    if ($e->getCode() === '42S02' || $e->getCode() === '42S22' || (int)($e->errorInfo[1] ?? 0) === 1146) {
        trainerAttendanceQrResponse(['success' => false, 'message' => 'Trainer QR attendance database setup is incomplete. Apply database/trainer_qr_attendance.sql, then retry.'], 503);
    }
    error_log('Trainer QR attendance database operation failed.');
    trainerAttendanceQrResponse(['success' => false, 'message' => 'Unable to record trainer attendance right now. Please try again.'], 500);
}

?>
