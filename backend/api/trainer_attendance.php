<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

const TRAINER_ATTENDANCE_GRACE_MINUTES = 5;

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function currentServerDateTime() {
    return new DateTime('now');
}

function getAuthenticatedTrainer($conn, $authUser) {
    $stmt = $conn->prepare('SELECT t.id, t.trainer_code, u.full_name, u.email, t.status FROM trainers t JOIN users u ON u.id = t.user_id WHERE t.user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $authUser['id']]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$trainer) sendResponse(['success' => false, 'message' => 'Trainer profile not found.'], 404);
    return $trainer;
}

function getTrainerByCode($conn, $trainerCode) {
    $stmt = $conn->prepare('SELECT t.id, t.trainer_code, u.full_name, u.email, t.status FROM trainers t JOIN users u ON u.id = t.user_id WHERE t.trainer_code = :trainer_code LIMIT 1');
    $stmt->execute([':trainer_code' => trim((string)$trainerCode)]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getTodaySlots($conn, $trainerId, $todayName) {
    $stmt = $conn->prepare("SELECT a.id, a.day_of_week,
                                  TIME_FORMAT(a.start_time, '%H:%i:%s') AS scheduled_start,
                                  TIME_FORMAT(a.end_time, '%H:%i:%s') AS scheduled_end
                           FROM trainer_availability a
                           LEFT JOIN trainer_attendance ta
                             ON ta.availability_id = a.id
                            AND ta.trainer_id = a.trainer_id
                            AND ta.attendance_date = :attendance_date
                           WHERE a.trainer_id = :trainer_id
                             AND a.day_of_week = :day_of_week
                             AND (LOWER(a.status) = 'active' OR ta.id IS NOT NULL)
                           ORDER BY a.start_time ASC");
    $stmt->execute([':trainer_id' => $trainerId, ':day_of_week' => $todayName, ':attendance_date' => date('Y-m-d')]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function slotDateTime($date, $time) {
    return new DateTime($date . ' ' . $time);
}

function selectTrainerAttendanceSlot($slots, $date, $now) {
    $currentSlots = [];
    $upcomingSlots = [];
    foreach ($slots as $slot) {
        $start = slotDateTime($date, $slot['scheduled_start']);
        $end = slotDateTime($date, $slot['scheduled_end']);
        $graceStart = (clone $start)->modify('-' . TRAINER_ATTENDANCE_GRACE_MINUTES . ' minutes');
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

function finalizeExpiredSlots($conn, $trainerId, $today, $slots, $now) {
    foreach ($slots as $slot) {
        $end = slotDateTime($today, $slot['scheduled_end']);
        if ($end >= $now) continue;

        $existing = $conn->prepare('SELECT id, time_in, status FROM trainer_attendance WHERE trainer_id = :trainer_id AND availability_id = :availability_id AND attendance_date = :attendance_date LIMIT 1');
        $existing->execute([
            ':trainer_id' => $trainerId,
            ':availability_id' => $slot['id'],
            ':attendance_date' => $today
        ]);
        $attendance = $existing->fetch(PDO::FETCH_ASSOC);

        if (!$attendance) {
            try {
                $insert = $conn->prepare("INSERT INTO trainer_attendance (trainer_id, availability_id, attendance_date, scheduled_start, scheduled_end, status, created_at)
                                          VALUES (:trainer_id, :availability_id, :attendance_date, :scheduled_start, :scheduled_end, 'absent', NOW())");
                $insert->execute([
                    ':trainer_id' => $trainerId,
                    ':availability_id' => $slot['id'],
                    ':attendance_date' => $today,
                    ':scheduled_start' => $slot['scheduled_start'],
                    ':scheduled_end' => $slot['scheduled_end']
                ]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
            }
        } elseif (empty($attendance['time_in']) && $attendance['status'] !== 'absent') {
            $update = $conn->prepare("UPDATE trainer_attendance SET status = 'absent' WHERE id = :id AND time_in IS NULL");
            $update->execute([':id' => $attendance['id']]);
        }
    }
}

function attendanceRows($conn, $trainerId, $today, $slots) {
    $stmt = $conn->prepare('SELECT id, availability_id, attendance_date, scheduled_start, scheduled_end, time_in, time_out, status FROM trainer_attendance WHERE trainer_id = :trainer_id AND attendance_date = :attendance_date ORDER BY scheduled_start ASC');
    $stmt->execute([':trainer_id' => $trainerId, ':attendance_date' => $today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $bySlot = [];
    foreach ($rows as $row) $bySlot[(int)$row['availability_id']] = $row;

    $result = [];
    foreach ($slots as $slot) {
        $result[] = $bySlot[(int)$slot['id']] ?? [
            'id' => null,
            'availability_id' => $slot['id'],
            'attendance_date' => $today,
            'scheduled_start' => $slot['scheduled_start'],
            'scheduled_end' => $slot['scheduled_end'],
            'time_in' => null,
            'time_out' => null,
            'status' => 'scheduled'
        ];
    }
    return $result;
}

function responseTrainer($trainer, $attendance) {
    return [
        'trainer_name' => $trainer['full_name'],
        'trainer_code' => $trainer['trainer_code'],
        'attendance_date' => $attendance['attendance_date'],
        'scheduled_start' => $attendance['scheduled_start'],
        'scheduled_end' => $attendance['scheduled_end'],
        'time_in' => $attendance['time_in'],
        'time_out' => $attendance['time_out'],
        'status' => $attendance['status']
    ];
}

try {
    $authUser = requireAuth($conn);
    $role = strtolower(trim((string)$authUser['role']));
    if (!in_array($role, ['admin', 'trainer'], true)) {
        sendResponse(['success' => false, 'message' => 'Forbidden. Trainer attendance is restricted.'], 403);
    }

    $todayDateTime = currentServerDateTime();
    $today = $todayDateTime->format('Y-m-d');
    $todayName = $todayDateTime->format('l');
    $nowTime = $todayDateTime->format('H:i:s');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $trainer = $role === 'trainer'
            ? getAuthenticatedTrainer($conn, $authUser)
            : ($authUser['id'] ? null : null);
        if ($role === 'admin') {
            $requestedCode = trim((string)($_GET['trainer_code'] ?? ''));
            if (!$requestedCode) sendResponse(['success' => false, 'message' => 'trainer_code is required for admin queries.'], 400);
            $trainer = getTrainerByCode($conn, $requestedCode);
            if (!$trainer) sendResponse(['success' => false, 'message' => 'Trainer not found.'], 404);
        }

        $slots = getTodaySlots($conn, $trainer['id'], $todayName);
        finalizeExpiredSlots($conn, $trainer['id'], $today, $slots, $todayDateTime);
        $schedule = attendanceRows($conn, $trainer['id'], $today, $slots);
        $qrAttendanceStmt = $conn->prepare(
            'SELECT id, attendance_date, time_in, time_out, status
             FROM trainer_attendance
             WHERE trainer_id = :trainer_id AND attendance_date = :attendance_date
               AND availability_id IS NULL
             ORDER BY time_in ASC'
        );
        $qrAttendanceStmt->execute([':trainer_id' => $trainer['id'], ':attendance_date' => $today]);
        foreach ($qrAttendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $qrAttendance) {
            $schedule[] = [
                'id' => (int)$qrAttendance['id'],
                'availability_id' => null,
                'attendance_date' => $today,
                'scheduled_start' => null,
                'scheduled_end' => null,
                'time_in' => $qrAttendance['time_in'],
                'time_out' => $qrAttendance['time_out'],
                'status' => $qrAttendance['status'],
                'attendance_source' => 'qr'
            ];
        }
        $summary = ['scheduled' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
        foreach ($schedule as $row) $summary[$row['status']]++;
        $activeAttendanceStmt = $conn->prepare(
            'SELECT attendance_date, time_in, time_out
             FROM trainer_attendance
             WHERE trainer_id = :trainer_id AND time_in IS NOT NULL AND time_out IS NULL
             ORDER BY attendance_date DESC, id DESC
             LIMIT 1'
        );
        $activeAttendanceStmt->execute([':trainer_id' => $trainer['id']]);
        $activeAttendance = $activeAttendanceStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        sendResponse([
            'success' => true,
            'trainer' => ['full_name' => $trainer['full_name'], 'trainer_code' => $trainer['trainer_code']],
            'attendance_date' => $today,
            'schedule' => $schedule,
            'active_attendance' => $activeAttendance,
            'summary' => $summary,
            'grace_period_minutes' => TRAINER_ATTENDANCE_GRACE_MINUTES
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    if ($role !== 'admin') {
        sendResponse(['success' => false, 'message' => 'Only administrators or authorized staff may scan trainer QR codes.'], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $scannedCode = trim((string)($input['trainer_code'] ?? ''));
    if ($scannedCode === '') sendResponse(['success' => false, 'message' => 'trainer_code is required.'], 400);

    $trainer = getTrainerByCode($conn, $scannedCode);
    if (!$trainer) sendResponse(['success' => false, 'message' => 'Trainer not found.'], 404);
    if (strtolower((string)$trainer['status']) !== 'active') sendResponse(['success' => false, 'message' => 'Trainer account is not active.'], 403);

    $slots = getTodaySlots($conn, $trainer['id'], $todayName);
    if (!$slots) sendResponse(['success' => false, 'message' => 'Trainer is not scheduled to work today.'], 409);
    finalizeExpiredSlots($conn, $trainer['id'], $today, $slots, $todayDateTime);

    $openStmt = $conn->prepare('SELECT id, availability_id, attendance_date, scheduled_start, scheduled_end, time_in, time_out, status FROM trainer_attendance WHERE trainer_id = :trainer_id AND attendance_date = :attendance_date AND time_in IS NOT NULL AND time_out IS NULL ORDER BY scheduled_start DESC LIMIT 1');
    $openStmt->execute([':trainer_id' => $trainer['id'], ':attendance_date' => $today]);
    $openAttendance = $openStmt->fetch(PDO::FETCH_ASSOC);

    if ($openAttendance) {
        $update = $conn->prepare('UPDATE trainer_attendance SET time_out = :time_out WHERE id = :id AND time_in IS NOT NULL AND time_out IS NULL');
        $update->execute([':time_out' => $nowTime, ':id' => $openAttendance['id']]);
        if ($update->rowCount() !== 1) sendResponse(['success' => false, 'message' => 'Attendance scan was already processed.'], 409);
        $openAttendance['time_out'] = $nowTime;
        sendResponse(['success' => true, 'action' => 'time_out', 'message' => 'Trainer time out recorded successfully.', 'attendance' => responseTrainer($trainer, $openAttendance)]);
    }

    $selectedSlot = selectTrainerAttendanceSlot($slots, $today, $todayDateTime);
    if (!$selectedSlot) sendResponse(['success' => false, 'message' => 'No active trainer shift is available for this scan time.'], 409);

    $existingStmt = $conn->prepare('SELECT id, availability_id, attendance_date, scheduled_start, scheduled_end, time_in, time_out, status FROM trainer_attendance WHERE trainer_id = :trainer_id AND availability_id = :availability_id AND attendance_date = :attendance_date LIMIT 1');
    $existingStmt->execute([':trainer_id' => $trainer['id'], ':availability_id' => $selectedSlot['id'], ':attendance_date' => $today]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) sendResponse(['success' => false, 'message' => 'This trainer shift attendance has already been completed or finalized.', 'attendance' => responseTrainer($trainer, $existing)], 409);

    $scheduledStart = slotDateTime($today, $selectedSlot['scheduled_start']);
    $status = $todayDateTime <= (clone $scheduledStart)->modify('+' . TRAINER_ATTENDANCE_GRACE_MINUTES . ' minutes') ? 'present' : 'late';
    try {
        $insert = $conn->prepare("INSERT INTO trainer_attendance (trainer_id, availability_id, attendance_date, scheduled_start, scheduled_end, time_in, status, created_at)
                                  VALUES (:trainer_id, :availability_id, :attendance_date, :scheduled_start, :scheduled_end, :time_in, :status, NOW())");
        $insert->execute([
            ':trainer_id' => $trainer['id'],
            ':availability_id' => $selectedSlot['id'],
            ':attendance_date' => $today,
            ':scheduled_start' => $selectedSlot['scheduled_start'],
            ':scheduled_end' => $selectedSlot['scheduled_end'],
            ':time_in' => $nowTime,
            ':status' => $status
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') sendResponse(['success' => false, 'message' => 'Attendance scan was already processed.'], 409);
        throw $e;
    }

    sendResponse(['success' => true, 'action' => 'time_in', 'message' => 'Trainer time in recorded successfully.', 'attendance' => responseTrainer($trainer, [
        'attendance_date' => $today,
        'scheduled_start' => $selectedSlot['scheduled_start'],
        'scheduled_end' => $selectedSlot['scheduled_end'],
        'time_in' => $nowTime,
        'time_out' => null,
        'status' => $status
    ])]);
} catch (PDOException $e) {
    sendResponse(['success' => false, 'message' => 'Trainer attendance service error.'], 500);
}
