<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/membership_workflow.php';

$authUser = requireAuth($conn);

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(['success' => false, 'message' => 'Method not allowed. Use POST.'], 405);
    }

    $currentRole = strtolower(trim((string)($authUser['role'] ?? '')));
    if (!in_array($currentRole, ['admin', 'administrator'], true)) {
        sendResponse(['success' => false, 'message' => 'Members must scan the current gym QR code to record attendance.'], 403);
    }

    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];
    $memberCode = trim((string)($input['pass_code'] ?? $input['member_code'] ?? ''));

    if (!$memberCode) {
        sendResponse(['success' => false, 'message' => 'member_code is required.'], 400);
    }

    // Find member by member_code
    $stmt = $conn->prepare('SELECT id, user_id, member_code, first_name, middle_name, last_name, full_name, membership_type, membership_start, membership_end, status, account_status FROM members WHERE member_code = :member_code LIMIT 1');
    $stmt->bindParam(':member_code', $memberCode, PDO::PARAM_STR);
    $stmt->execute();
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        $conn->beginTransaction();
        $passStmt = $conn->prepare(
            'SELECT id, customer_name, visit_date, expires_at, payment_status, checked_in_at
             FROM daily_pass_transactions WHERE pass_code = :pass_code LIMIT 1 FOR UPDATE'
        );
        $passStmt->execute([':pass_code' => $memberCode]);
        $dailyPass = $passStmt->fetch(PDO::FETCH_ASSOC);
        if (!$dailyPass) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Member or 1 Day Pass not found.'], 404);
        }
        if ($dailyPass['payment_status'] !== 'paid') {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => '1 Day Pass payment has not been verified.'], 403);
        }
        $timezone = new DateTimeZone('Asia/Manila');
        $nowManila = new DateTimeImmutable('now', $timezone);
        $expiresAt = new DateTimeImmutable($dailyPass['expires_at'], $timezone);
        if ($dailyPass['visit_date'] !== manilaToday()->format('Y-m-d') || $expiresAt <= $nowManila) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'This 1 Day Pass has expired. Passes are valid only on their Manila-local visit date.'], 403);
        }
        if ($dailyPass['checked_in_at'] !== null) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'This 1 Day Pass has already been checked in.'], 409);
        }
        $checkIn = $conn->prepare(
            "UPDATE daily_pass_transactions SET checked_in_at = NOW()
             WHERE id = :id AND payment_status = 'paid' AND checked_in_at IS NULL"
        );
        $checkIn->execute([':id' => (int)$dailyPass['id']]);
        if ($checkIn->rowCount() !== 1) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'This 1 Day Pass was already checked in.'], 409);
        }
        $conn->commit();
        sendResponse([
            'success' => true,
            'action' => 'time_in',
            'access_type' => 'daily_pass',
            'message' => '1 Day Pass validated. Entry recorded.',
            'member' => ['full_name' => $dailyPass['customer_name']],
            'attendance' => ['date' => $dailyPass['visit_date'], 'time_in' => $nowManila->format('H:i:s'), 'time_out' => null],
        ]);
    }

    $conn->beginTransaction();
    $memberLock = $conn->prepare('SELECT id, member_code, first_name, middle_name, last_name, full_name, membership_type, membership_end, status, account_status FROM members WHERE id = :member_id FOR UPDATE');
    $memberLock->execute([':member_id' => (int)$member['id']]);
    $member = $memberLock->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        $conn->rollBack();
        sendResponse(['success' => false, 'message' => 'Member not found.'], 404);
    }

    $statusKey = strtolower(trim((string)$member['status']));
    if ($statusKey !== 'active' || strtolower(trim((string)$member['account_status'])) !== 'active') {
        $conn->rollBack();
        sendResponse(['success' => false, 'message' => $statusKey !== 'active' ? 'Member membership is not active.' : 'Member account is not active.'], 403);
    }

    $membershipTypeKey = preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string)$member['membership_type'])));
    $isMonthly = ($membershipTypeKey === 'monthly' || $membershipTypeKey === 'monthlysubscription');
    $today = manilaToday()->format('Y-m-d');
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('H:i:s');
    if ($isMonthly) {
        $end = $member['membership_end'] ?? '';
        if ($end === '0000-00-00' || $end === null || trim($end) === '' || strtotime($end) < strtotime($today)) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Membership has expired.'], 403);
        }
    } else {
        $conn->rollBack();
        sendResponse(['success' => false, 'message' => 'A legacy 1 Day Pass account does not grant access. Present a verified pass code valid for today.'], 403);
    }

    // Lock and find any open visit, including a check-in made before midnight.
    $attStmt = $conn->prepare('SELECT id, member_id, attendance_date, time_in, time_out FROM attendance WHERE member_id = :member_id AND time_in IS NOT NULL AND time_out IS NULL ORDER BY attendance_date DESC, id DESC LIMIT 2 FOR UPDATE');
    $attStmt->bindParam(':member_id', $member['id'], PDO::PARAM_INT);
    $attStmt->execute();
    $openAttendance = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($openAttendance) > 1) {
        $conn->rollBack();
        sendResponse(['success' => false, 'message' => 'Multiple open attendance sessions require administrator assistance.'], 409);
    }

    if (!$openAttendance) {
        $completedStmt = $conn->prepare('SELECT id FROM attendance WHERE member_id = :member_id AND attendance_date = :attendance_date AND time_in IS NOT NULL AND time_out IS NOT NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $completedStmt->execute([':member_id' => (int)$member['id'], ':attendance_date' => $today]);
        if ($completedStmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->rollBack();
            sendResponse([
                'success' => false,
                'action' => 'already_completed',
                'message' => "Today's attendance has already been completed."
            ], 409);
        }

        $insert = $conn->prepare('INSERT INTO attendance (member_id, attendance_date, time_in, time_out, created_at) VALUES (:member_id, :attendance_date, :time_in, NULL, NOW())');
        $insert->bindParam(':member_id', $member['id'], PDO::PARAM_INT);
        $insert->bindParam(':attendance_date', $today, PDO::PARAM_STR);
        $insert->bindParam(':time_in', $now, PDO::PARAM_STR);
        $insert->execute();
        $conn->commit();

        $attendanceData = ['date' => $today, 'time_in' => $now, 'time_out' => null];

        sendResponse([
            'success' => true,
            'action' => 'time_in',
            'message' => 'Time in recorded successfully.',
            'member' => [
                'member_code' => $member['member_code'],
                'first_name' => $member['first_name'] ?? null,
                'middle_name' => $member['middle_name'] ?? null,
                'last_name' => $member['last_name'] ?? null,
                'full_name' => $member['full_name']
            ],
            'attendance' => $attendanceData
        ]);
    }

    if ($openAttendance) {
        $attendance = $openAttendance[0];
        $update = $conn->prepare('UPDATE attendance SET time_out = :time_out WHERE id = :id');
        $update->bindParam(':time_out', $now, PDO::PARAM_STR);
        $update->bindParam(':id', $attendance['id'], PDO::PARAM_INT);
        $update->execute();
        if ($update->rowCount() !== 1) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Attendance scan was already processed.'], 409);
        }
        $conn->commit();

        $attendanceData = ['date' => $attendance['attendance_date'], 'time_in' => $attendance['time_in'], 'time_out' => $now];

        sendResponse([
            'success' => true,
            'action' => 'time_out',
            'message' => 'Time out recorded successfully.',
            'member' => [
                'member_code' => $member['member_code'],
                'first_name' => $member['first_name'] ?? null,
                'middle_name' => $member['middle_name'] ?? null,
                'last_name' => $member['last_name'] ?? null,
                'full_name' => $member['full_name']
            ],
            'attendance' => $attendanceData
        ]);
    }

    $conn->rollBack();
    sendResponse(['success' => false, 'message' => 'Unable to record attendance.'], 500);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    sendResponse(['success' => false, 'message' => 'Server error. Please try again later.'], 500);
}

?>
