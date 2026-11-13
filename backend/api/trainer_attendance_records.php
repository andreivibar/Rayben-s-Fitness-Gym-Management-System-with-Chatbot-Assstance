<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function dateTimeFor($date, $time = '00:00:00') {
    return new DateTime($date . ' ' . $time);
}

function finalizeTrainerDate($conn, $trainerId, $date, $slots, $now) {
    foreach ($slots as $slot) {
        $end = dateTimeFor($date, $slot['scheduled_end']);
        if ($end >= $now) continue;
        $find = $conn->prepare('SELECT id, time_in, status FROM trainer_attendance WHERE trainer_id = :trainer_id AND availability_id = :availability_id AND attendance_date = :attendance_date LIMIT 1');
        $find->execute([':trainer_id' => $trainerId, ':availability_id' => $slot['id'], ':attendance_date' => $date]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            try {
                $insert = $conn->prepare("INSERT INTO trainer_attendance (trainer_id, availability_id, attendance_date, scheduled_start, scheduled_end, status, created_at) VALUES (:trainer_id, :availability_id, :attendance_date, :scheduled_start, :scheduled_end, 'absent', NOW())");
                $insert->execute([':trainer_id' => $trainerId, ':availability_id' => $slot['id'], ':attendance_date' => $date, ':scheduled_start' => $slot['scheduled_start'], ':scheduled_end' => $slot['scheduled_end']]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
            }
        } elseif (empty($row['time_in']) && $row['status'] !== 'absent') {
            $update = $conn->prepare("UPDATE trainer_attendance SET status = 'absent' WHERE id = :id AND time_in IS NULL");
            $update->execute([':id' => $row['id']]);
        }
    }
}

try {
    $authUser = requireAuth($conn);
    $role = strtolower(trim((string)$authUser['role']));
    if (!in_array($role, ['admin', 'trainer'], true)) sendResponse(['success' => false, 'message' => 'Forbidden.'], 403);

    $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) sendResponse(['success' => false, 'message' => 'Invalid attendance date.'], 400);
    $day = $dateObject->format('l');
    $now = new DateTime('now');
    $search = trim((string)($_GET['search'] ?? ''));
    $statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
    if (!in_array($statusFilter, ['all', 'scheduled', 'present', 'late', 'absent'], true)) $statusFilter = 'all';

    $trainerWhere = [];
    $trainerParams = [];
    if ($role === 'trainer') {
        $trainerWhere[] = 't.user_id = :user_id';
        $trainerParams[':user_id'] = $authUser['id'];
    }
    if ($search !== '' && $role === 'admin') {
        $trainerWhere[] = '(u.full_name LIKE :search OR u.first_name LIKE :search OR u.middle_name LIKE :search OR u.last_name LIKE :search OR t.trainer_code LIKE :search)';
        $trainerParams[':search'] = '%' . $search . '%';
    }
    $trainerSql = 'SELECT t.id, t.trainer_code, u.first_name, u.middle_name, u.last_name, u.full_name FROM trainers t JOIN users u ON u.id = t.user_id' . ($trainerWhere ? ' WHERE ' . implode(' AND ', $trainerWhere) : '') . ' ORDER BY u.full_name ASC';
    $trainerStmt = $conn->prepare($trainerSql);
    $trainerStmt->execute($trainerParams);
    $trainers = $trainerStmt->fetchAll(PDO::FETCH_ASSOC);

    $records = [];
    foreach ($trainers as $trainer) {
        $slotStmt = $conn->prepare("SELECT a.id,
                                           TIME_FORMAT(a.start_time, '%H:%i:%s') AS scheduled_start,
                                           TIME_FORMAT(a.end_time, '%H:%i:%s') AS scheduled_end
                                    FROM trainer_availability a
                                    LEFT JOIN trainer_attendance ta
                                      ON ta.availability_id = a.id
                                     AND ta.trainer_id = a.trainer_id
                                     AND ta.attendance_date = :attendance_date
                                    WHERE a.trainer_id = :trainer_id
                                      AND a.day_of_week = :day
                                      AND (LOWER(a.status) = 'active' OR ta.id IS NOT NULL)
                                    ORDER BY a.start_time ASC");
        $slotStmt->execute([':trainer_id' => $trainer['id'], ':day' => $day, ':attendance_date' => $date]);
        $slots = $slotStmt->fetchAll(PDO::FETCH_ASSOC);
        finalizeTrainerDate($conn, $trainer['id'], $date, $slots, $now);

        $attendanceStmt = $conn->prepare('SELECT id, availability_id, time_in, time_out, status FROM trainer_attendance WHERE trainer_id = :trainer_id AND attendance_date = :attendance_date');
        $attendanceStmt->execute([':trainer_id' => $trainer['id'], ':attendance_date' => $date]);
        $attendanceBySlot = [];
        foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $attendance) $attendanceBySlot[(int)$attendance['availability_id']] = $attendance;

        foreach ($slots as $slot) {
            $attendance = $attendanceBySlot[(int)$slot['id']] ?? null;
            $status = $attendance['status'] ?? 'scheduled';
            if (!$attendance && $date < date('Y-m-d')) $status = 'absent';
            if ($statusFilter !== 'all' && $status !== $statusFilter) continue;
            $records[] = [
                'id' => $attendance['id'] ?? null,
                'trainer_id' => (int)$trainer['id'],
                'trainer_name' => $trainer['full_name'],
                'trainer_code' => $trainer['trainer_code'],
                'availability_id' => (int)$slot['id'],
                'attendance_date' => $date,
                'scheduled_start' => $attendance['scheduled_start'] ?? $slot['scheduled_start'],
                'scheduled_end' => $attendance['scheduled_end'] ?? $slot['scheduled_end'],
                'time_in' => $attendance['time_in'] ?? null,
                'time_out' => $attendance['time_out'] ?? null,
                'status' => $status,
                'attendance_source' => 'schedule'
            ];
        }

        $qrAttendanceStmt = $conn->prepare(
            'SELECT id, attendance_date, time_in, time_out, status
             FROM trainer_attendance
             WHERE trainer_id = :trainer_id AND attendance_date = :attendance_date
               AND availability_id IS NULL
             ORDER BY time_in ASC'
        );
        $qrAttendanceStmt->execute([':trainer_id' => $trainer['id'], ':attendance_date' => $date]);
        foreach ($qrAttendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $attendance) {
            if ($statusFilter !== 'all' && $attendance['status'] !== $statusFilter) continue;
            $records[] = [
                'id' => (int)$attendance['id'],
                'trainer_id' => (int)$trainer['id'],
                'trainer_name' => $trainer['full_name'],
                'trainer_code' => $trainer['trainer_code'],
                'availability_id' => null,
                'attendance_date' => $date,
                'scheduled_start' => null,
                'scheduled_end' => null,
                'time_in' => $attendance['time_in'],
                'time_out' => $attendance['time_out'],
                'status' => $attendance['status'],
                'attendance_source' => 'qr'
            ];
        }
    }

    $summary = ['scheduled' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
    foreach ($records as $record) $summary[$record['status']]++;
    sendResponse(['success' => true, 'attendance_date' => $date, 'records' => $records, 'summary' => $summary]);
} catch (PDOException $e) {
    sendResponse(['success' => false, 'message' => 'Trainer attendance records service error.'], 500);
}
