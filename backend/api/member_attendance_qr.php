<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (defined('MEMBER_ATTENDANCE_QR_TESTING') && MEMBER_ATTENDANCE_QR_TESTING === true) {
    $conn = $GLOBALS['member_attendance_qr_test_connection'];
    $authUser = $GLOBALS['member_attendance_qr_test_user'];
} else {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
}
require_once __DIR__ . '/../config/attendance_qr_tokens.php';
require_once __DIR__ . '/../config/member_attendance_eligibility.php';

function memberAttendanceQrResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function attendanceQrActionForMember($conn, $memberId, $today) {
    $open = $conn->prepare('SELECT id, attendance_date, time_in, time_out FROM attendance WHERE member_id = :member_id AND time_in IS NOT NULL AND time_out IS NULL ORDER BY attendance_date DESC, id DESC LIMIT 2 FOR UPDATE');
    $open->execute([':member_id' => $memberId]);
    $openRows = $open->fetchAll(PDO::FETCH_ASSOC);

    if (count($openRows) > 1) {
        return [
            'error' => 'More than one open attendance session needs administrator assistance.',
            'status' => 409
        ];
    }

    if ($openRows) {
        return ['action' => 'time_out', 'attendance' => $openRows[0]];
    }

    $completed = $conn->prepare('SELECT id FROM attendance WHERE member_id = :member_id AND attendance_date = :attendance_date AND time_in IS NOT NULL AND time_out IS NOT NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $completed->execute([':member_id' => $memberId, ':attendance_date' => $today]);
    if ($completed->fetch(PDO::FETCH_ASSOC)) {
        return [
            'error' => "Today's attendance has already been completed.",
            'status' => 409,
            'action' => 'already_completed'
        ];
    }

    return ['action' => 'time_in', 'attendance' => null];
}

try {
    if (!defined('MEMBER_ATTENDANCE_QR_TESTING') || MEMBER_ATTENDANCE_QR_TESTING !== true) {
        $authUser = requireRole($conn, ['member']);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST, OPTIONS');
        memberAttendanceQrResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        memberAttendanceQrResponse(['success' => false, 'message' => 'A valid JSON request is required.'], 400);
    }

    $operation = strtolower(trim((string)($input['operation'] ?? '')));
    $token = strtolower(trim((string)($input['token'] ?? '')));
    if (!in_array($operation, ['preview', 'confirm'], true)) {
        memberAttendanceQrResponse(['success' => false, 'message' => 'A valid attendance operation is required.'], 400);
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        memberAttendanceQrResponse(['success' => false, 'message' => 'The gym QR code is invalid. Scan the current code displayed at the gym.'], 400);
    }

    $requestTime = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));

    $conn->beginTransaction();
    $memberStmt = $conn->prepare('SELECT id, first_name, middle_name, last_name, full_name, status, account_status, gym_access_status, membership_type, membership_start, membership_end FROM members WHERE user_id = :user_id LIMIT 1 FOR UPDATE');
    $memberStmt->execute([':user_id' => (int)$authUser['id']]);
    $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        $conn->rollBack();
        memberAttendanceQrResponse(['success' => false, 'message' => 'Your member profile could not be found.'], 404);
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $nowString = $now->format('Y-m-d H:i:s');
    $today = $now->format('Y-m-d');

    $memberStatusError = attendanceQrCheckMemberStatus($member);
    if ($memberStatusError !== null) {
        $conn->rollBack();
        memberAttendanceQrResponse(['success' => false, 'message' => $memberStatusError], 403);
    }

    $tokenRow = findCurrentAttendanceQrToken($conn, $token, $now);
    if (!$tokenRow) {
        $conn->rollBack();
        memberAttendanceQrResponse(['success' => false, 'message' => 'This gym QR code is invalid or has expired. Scan the latest code displayed at the gym.'], 410);
    }

    $usedStmt = $conn->prepare('SELECT id FROM attendance_qr_token_uses WHERE qr_token_id = :token_id AND member_id = :member_id LIMIT 1 FOR UPDATE');
    $usedStmt->execute([':token_id' => (int)$tokenRow['id'], ':member_id' => (int)$member['id']]);
    $tokenUse = $usedStmt->fetch(PDO::FETCH_ASSOC);

    $state = attendanceQrActionForMember($conn, (int)$member['id'], $today);
    if ($tokenUse && ($state['action'] ?? null) !== 'time_out') {
        $conn->rollBack();
        memberAttendanceQrResponse(['success' => false, 'message' => 'This QR code was already used. Scan the current code displayed at the gym.'], 409);
    }

    if (isset($state['error'])) {
        $conn->rollBack();
        memberAttendanceQrResponse([
            'success' => false,
            'action' => $state['action'] ?? null,
            'message' => $state['error']
        ], $state['status']);
    }

    $access = null;
    if ($state['action'] === 'time_in') {
        $periodStmt = $conn->prepare(
            'SELECT status, starts_on, ends_on
             FROM membership_subscription_periods
             WHERE member_id = :member_id
             ORDER BY starts_on DESC, id DESC
             FOR UPDATE'
        );
        $periodStmt->execute([':member_id' => (int)$member['id']]);
        $subscriptionPeriods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);

        $passStmt = $conn->prepare(
            'SELECT id, visit_date, expires_at, payment_status, amount, checked_in_at
             FROM daily_pass_transactions
             WHERE member_id = :member_id
             ORDER BY id DESC
             FOR UPDATE'
        );
        $passStmt->execute([':member_id' => (int)$member['id']]);
        $dailyPasses = $passStmt->fetchAll(PDO::FETCH_ASSOC);
        $access = attendanceQrEvaluateAccess($member, $subscriptionPeriods, $dailyPasses, $now);
        if (!$access['eligible']) {
            $conn->rollBack();
            memberAttendanceQrResponse(['success' => false, 'message' => $access['message']], 403);
        }
    }

    $memberName = trim((string)($member['full_name'] ?? ''));
    if ($memberName === '') {
        $memberName = trim(implode(' ', array_filter([
            $member['first_name'] ?? '',
            $member['middle_name'] ?? '',
            $member['last_name'] ?? ''
        ])));
    }

    if ($operation === 'preview') {
        $windowStart = $requestTime->setTime((int)$requestTime->format('H'), (int)$requestTime->format('i'), 0)->format('Y-m-d H:i:s');
        $rateLimit = $conn->prepare('INSERT INTO attendance_qr_rate_limits (member_id, window_start, request_count) VALUES (:member_id, :window_start, 1) ON DUPLICATE KEY UPDATE request_count = request_count + 1');
        $rateLimit->execute([':member_id' => (int)$member['id'], ':window_start' => $windowStart]);
        $rateCountStmt = $conn->prepare('SELECT request_count FROM attendance_qr_rate_limits WHERE member_id = :member_id AND window_start = :window_start');
        $rateCountStmt->execute([':member_id' => (int)$member['id'], ':window_start' => $windowStart]);
        if ((int)$rateCountStmt->fetchColumn() > 12) {
            $conn->rollBack();
            memberAttendanceQrResponse(['success' => false, 'message' => 'Too many attendance requests. Please wait a minute and try again.'], 429);
        }

        $conn->commit();
        memberAttendanceQrResponse([
            'success' => true,
            'member' => ['full_name' => $memberName],
            'action' => $state['action'],
            'attendance_date' => $state['attendance']['attendance_date'] ?? $today
        ]);
    }

    $expectedAction = strtolower(trim((string)($input['expected_action'] ?? '')));
    if (!in_array($expectedAction, ['time_in', 'time_out'], true) || $expectedAction !== $state['action']) {
        $conn->rollBack();
        memberAttendanceQrResponse([
            'success' => false,
            'message' => 'Your attendance status changed. Scan the current gym QR code again to review the next action.'
        ], 409);
    }

    $windowStart = $requestTime->setTime((int)$requestTime->format('H'), (int)$requestTime->format('i'), 0)->format('Y-m-d H:i:s');
    $rateLimit = $conn->prepare('INSERT INTO attendance_qr_rate_limits (member_id, window_start, request_count) VALUES (:member_id, :window_start, 1) ON DUPLICATE KEY UPDATE request_count = request_count + 1');
    $rateLimit->execute([':member_id' => (int)$member['id'], ':window_start' => $windowStart]);
    $rateCountStmt = $conn->prepare('SELECT request_count FROM attendance_qr_rate_limits WHERE member_id = :member_id AND window_start = :window_start');
    $rateCountStmt->execute([':member_id' => (int)$member['id'], ':window_start' => $windowStart]);
    if ((int)$rateCountStmt->fetchColumn() > 12) {
        $conn->rollBack();
        memberAttendanceQrResponse(['success' => false, 'message' => 'Too many attendance requests. Please wait a minute and try again.'], 429);
    }

    if (!$tokenUse) {
        $useStmt = $conn->prepare('INSERT INTO attendance_qr_token_uses (qr_token_id, member_id, used_at) VALUES (:token_id, :member_id, :used_at)');
        try {
            $useStmt->execute([
                ':token_id' => (int)$tokenRow['id'],
                ':member_id' => (int)$member['id'],
                ':used_at' => $nowString
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $conn->rollBack();
                memberAttendanceQrResponse(['success' => false, 'message' => 'This QR code was already used. Scan the current code displayed at the gym.'], 409);
            }
            throw $e;
        }
    }

    $nowTime = $now->format('H:i:s');
    if ($state['action'] === 'time_in') {
        if ($access['access_type'] === 'daily_pass') {
            $markPassUsed = $conn->prepare(
                "UPDATE daily_pass_transactions
                 SET checked_in_at = :checked_in_at
                 WHERE id = :id AND payment_status = 'paid' AND checked_in_at IS NULL"
            );
            $markPassUsed->execute([
                ':checked_in_at' => $nowString,
                ':id' => $access['daily_pass_id'],
            ]);
            if ($markPassUsed->rowCount() !== 1) {
                $conn->rollBack();
                memberAttendanceQrResponse([
                    'success' => false,
                    'message' => 'This 1 Day Pass is no longer valid or has already been used.'
                ], 409);
            }
        }

        $insertAttendance = $conn->prepare('INSERT INTO attendance (member_id, attendance_date, time_in, time_out, created_at) VALUES (:member_id, :attendance_date, :time_in, NULL, NOW())');
        $insertAttendance->execute([
            ':member_id' => (int)$member['id'],
            ':attendance_date' => $today,
            ':time_in' => $nowTime
        ]);
        $attendanceId = (int)$conn->lastInsertId();
        $linkAttendance = $conn->prepare(
            'UPDATE attendance_qr_token_uses
             SET attendance_id = :attendance_id
             WHERE qr_token_id = :token_id
               AND member_id = :member_id
               AND attendance_id IS NULL'
        );
        $linkAttendance->execute([
            ':attendance_id' => $attendanceId,
            ':token_id' => (int)$tokenRow['id'],
            ':member_id' => (int)$member['id'],
        ]);
        if ($linkAttendance->rowCount() !== 1) {
            $conn->rollBack();
            error_log('Member QR check-in could not link its attendance record.');
            memberAttendanceQrResponse([
                'success' => false,
                'message' => 'Unable to record attendance right now. Please try again.'
            ], 500);
        }
        $attendanceDate = $today;
        $timeIn = $nowTime;
        $timeOut = null;
        $message = 'Check-in recorded successfully.';
    } else {
        $updateAttendance = $conn->prepare('UPDATE attendance SET time_out = :time_out WHERE id = :id AND time_out IS NULL');
        $updateAttendance->execute([':time_out' => $nowTime, ':id' => (int)$state['attendance']['id']]);
        if ($updateAttendance->rowCount() !== 1) {
            $conn->rollBack();
            memberAttendanceQrResponse(['success' => false, 'message' => 'The active attendance session could not be checked out. Scan again or ask an administrator for help.'], 409);
        }
        $attendanceDate = $state['attendance']['attendance_date'];
        $timeIn = $state['attendance']['time_in'];
        $timeOut = $nowTime;
        $message = 'Check-out recorded successfully.';
    }

    $conn->commit();
    memberAttendanceQrResponse([
        'success' => true,
        'action' => $state['action'],
        'message' => $message,
        'member' => ['full_name' => $memberName],
        'attendance' => [
            'date' => $attendanceDate,
            'time_in' => $timeIn,
            'time_out' => $timeOut
        ]
    ]);
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Member attendance QR database operation failed.');
    memberAttendanceQrResponse(['success' => false, 'message' => 'Unable to record attendance right now. Please try again.'], 500);
}

?>
