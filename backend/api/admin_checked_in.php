<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

function adminCheckedInResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    requireRole($conn, ['admin', 'administrator']);
    $method = $_SERVER['REQUEST_METHOD'] ?? '';

    if ($method === 'GET') {
        $membersStmt = $conn->query(
            "SELECT a.id AS attendance_id, a.member_id AS person_id, m.member_code AS person_code,
                    m.full_name AS person_name, a.attendance_date, a.time_in, a.checkout_type
             FROM attendance a
             JOIN members m ON m.id = a.member_id
             WHERE a.time_in IS NOT NULL AND a.time_out IS NULL
             ORDER BY a.attendance_date ASC, a.time_in ASC"
        );
        $trainersStmt = $conn->query(
            "SELECT ta.id AS attendance_id, ta.trainer_id AS person_id, t.trainer_code AS person_code,
                    u.full_name AS person_name, ta.attendance_date, ta.time_in, ta.status,
                    ta.availability_id, ta.scheduled_start, ta.scheduled_end, a.schedule_type
             FROM trainer_attendance ta
             JOIN trainers t ON t.id = ta.trainer_id
             JOIN users u ON u.id = t.user_id
             LEFT JOIN trainer_availability a ON a.id = ta.availability_id
             WHERE ta.time_in IS NOT NULL AND ta.time_out IS NULL
             ORDER BY ta.attendance_date ASC, ta.time_in ASC"
        );

        $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
        $trainers = $trainersStmt->fetchAll(PDO::FETCH_ASSOC);
        adminCheckedInResponse([
            'success' => true,
            'members' => $members,
            'trainers' => $trainers,
            'counts' => [
                'members' => count($members),
                'trainers' => count($trainers)
            ]
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST, OPTIONS');
        adminCheckedInResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        adminCheckedInResponse(['success' => false, 'message' => 'A valid JSON request is required.'], 400);
    }

    $recordType = strtolower(trim((string)($input['record_type'] ?? '')));
    $attendanceId = filter_var($input['attendance_id'] ?? null, FILTER_VALIDATE_INT);
    if (!in_array($recordType, ['member', 'trainer'], true) || !$attendanceId || $attendanceId < 1) {
        adminCheckedInResponse(['success' => false, 'message' => 'Select a valid attendance session to check out.'], 400);
    }

    $conn->beginTransaction();
    if ($recordType === 'member') {
        $find = $conn->prepare(
            'SELECT id, time_in, time_out FROM attendance
             WHERE id = :id AND time_in IS NOT NULL
             FOR UPDATE'
        );
        $find->execute([':id' => $attendanceId]);
        $record = $find->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            $conn->rollBack();
            adminCheckedInResponse(['success' => false, 'message' => 'The member attendance session could not be found. Refresh the list and try again.'], 404);
        }
        if ($record['time_out'] !== null) {
            $conn->rollBack();
            adminCheckedInResponse(['success' => false, 'message' => 'This attendance session has already been checked out. The list has changed; refresh and review it again.'], 409);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $checkout = $conn->prepare(
            "UPDATE attendance
             SET time_out = :time_out, checkout_type = 'manual'
             WHERE id = :id AND time_in IS NOT NULL AND time_out IS NULL"
        );
    } else {
        $find = $conn->prepare(
            'SELECT id, time_in, time_out FROM trainer_attendance
             WHERE id = :id AND time_in IS NOT NULL
             FOR UPDATE'
        );
        $find->execute([':id' => $attendanceId]);
        $record = $find->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            $conn->rollBack();
            adminCheckedInResponse(['success' => false, 'message' => 'The trainer attendance session could not be found. Refresh the list and try again.'], 404);
        }
        if ($record['time_out'] !== null) {
            $conn->rollBack();
            adminCheckedInResponse(['success' => false, 'message' => 'This attendance session has already been checked out. The list has changed; refresh and review it again.'], 409);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $checkout = $conn->prepare(
            'UPDATE trainer_attendance
             SET time_out = :time_out
             WHERE id = :id AND time_in IS NOT NULL AND time_out IS NULL'
        );
    }

    $checkout->execute([
        ':time_out' => $now->format('H:i:s'),
        ':id' => $attendanceId
    ]);
    if ($checkout->rowCount() !== 1) {
        $conn->rollBack();
        adminCheckedInResponse(['success' => false, 'message' => 'This attendance session was already closed. Refresh the list to see the latest status.'], 409);
    }

    $conn->commit();
    adminCheckedInResponse([
        'success' => true,
        'message' => 'Manual checkout recorded successfully.',
        'record_type' => $recordType,
        'attendance_id' => $attendanceId,
        'time_out' => $now->format('H:i:s')
    ]);
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Admin manual attendance checkout failed: ' . $e->getMessage());
    adminCheckedInResponse(['success' => false, 'message' => 'Unable to update this attendance session right now. Refresh and try again.'], 500);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Admin manual attendance checkout failed: ' . $e->getMessage());
    adminCheckedInResponse(['success' => false, 'message' => 'Unable to update this attendance session right now. Refresh and try again.'], 500);
}

?>
