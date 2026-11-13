<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email, X-Current-Attendance-Token');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/attendance_qr_tokens.php';

function attendanceQrTokenResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    requireRole($conn, ['admin', 'administrator']);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET, OPTIONS');
        attendanceQrTokenResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $window = attendanceQrDayWindow(new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')));
    $now = $window['now'];
    $currentToken = strtolower(trim((string)($_SERVER['HTTP_X_CURRENT_ATTENDANCE_TOKEN'] ?? '')));

    $conn->beginTransaction();

    if (preg_match('/^[a-f0-9]{64}$/', $currentToken)) {
        $existingToken = findCurrentAttendanceQrToken($conn, $currentToken, $now);
        if ($existingToken) {
            $conn->commit();
            attendanceQrTokenResponse([
                'success' => true,
                'token' => $currentToken,
                'valid_for_date' => $now->format('Y-m-d'),
                'server_time' => $now->format(DateTimeInterface::ATOM),
                'expires_at' => (new DateTimeImmutable($existingToken['expires_at'], new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM)
            ]);
        }
    }

    $expires = $window['next_midnight'];
    $token = bin2hex(random_bytes(32));

    $deleteExpired = $conn->prepare('DELETE FROM attendance_qr_tokens WHERE expires_at < :cutoff');
    $deleteExpired->execute([':cutoff' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);

    $deleteRateLimits = $conn->prepare('DELETE FROM attendance_qr_rate_limits WHERE window_start < :cutoff');
    $deleteRateLimits->execute([':cutoff' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);

    $insert = $conn->prepare('INSERT INTO attendance_qr_tokens (token_hash, issued_at, expires_at) VALUES (:token_hash, :issued_at, :expires_at)');
    $insert->execute([
        ':token_hash' => hash('sha256', $token),
        ':issued_at' => $now->format('Y-m-d H:i:s'),
        ':expires_at' => $expires->format('Y-m-d H:i:s')
    ]);
    $conn->commit();

    attendanceQrTokenResponse([
        'success' => true,
        'token' => $token,
        'valid_for_date' => $now->format('Y-m-d'),
        'server_time' => $now->format(DateTimeInterface::ATOM),
        'expires_at' => $expires->format(DateTimeInterface::ATOM)
    ]);
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Attendance QR database operation failed: ' . $e->getMessage());
    if ($e->getCode() === '42S02' || (int)($e->errorInfo[1] ?? 0) === 1146) {
        attendanceQrTokenResponse([
            'success' => false,
            'message' => 'Attendance QR database setup is incomplete. Apply database/attendance_qr.sql, then retry.'
        ], 503);
    }
    attendanceQrTokenResponse(['success' => false, 'message' => 'The attendance QR service could not access its database. Check the server logs and try again.'], 503);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Unable to securely generate attendance QR token: ' . $e->getMessage());
    attendanceQrTokenResponse(['success' => false, 'message' => 'The attendance QR service could not generate a code. Check the server logs and try again.'], 500);
}

?>
