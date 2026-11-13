<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST, OPTIONS');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (defined('PASSWORD_RESET_API_TESTING') && PASSWORD_RESET_API_TESTING === true) {
    $conn = $GLOBALS['password_reset_test_connection'] ?? null;
    if (!$conn instanceof PDO) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Password reset is temporarily unavailable.']);
        exit;
    }
} else {
    require_once __DIR__ . '/../config/database.php';
}

require_once __DIR__ . '/../config/password_reset_service.php';
require_once __DIR__ . '/../config/password_reset_mailer.php';

function passwordResetApiRespond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    passwordResetApiRespond(['success' => false, 'message' => 'Invalid request payload.'], 400);
}

$action = strtolower(trim((string)($input['action'] ?? '')));
$email = is_string($input['email'] ?? null) ? $input['email'] : '';

try {
    if ($action === 'request-code') {
        passwordResetPruneExpiredRows($conn);
        passwordResetApiRespond(passwordResetRequestCode($conn, $email));
    }

    if ($action === 'verify-code') {
        $code = is_string($input['code'] ?? null) ? $input['code'] : '';
        $result = passwordResetVerifyCode($conn, $email, $code);
        passwordResetApiRespond($result, $result['success'] ? 200 : 400);
    }

    if ($action === 'complete-reset') {
        $resetToken = is_string($input['reset_token'] ?? null) ? $input['reset_token'] : '';
        $newPassword = is_string($input['new_password'] ?? null) ? $input['new_password'] : '';
        $confirmation = is_string($input['confirm_password'] ?? null) ? $input['confirm_password'] : '';
        $result = passwordResetComplete($conn, $email, $resetToken, $newPassword, $confirmation);
        passwordResetApiRespond($result, $result['success'] ? 200 : 400);
    }

    passwordResetApiRespond(['success' => false, 'message' => 'Invalid password reset action.'], 400);
} catch (Throwable $error) {
    error_log('Password reset operation failed.');
    if ($action === 'request-code') {
        passwordResetApiRespond([
            'success' => true,
            'message' => PASSWORD_RESET_GENERIC_MESSAGE,
        ]);
    }
    if ($action === 'verify-code') {
        passwordResetApiRespond([
            'success' => false,
            'message' => PASSWORD_RESET_INVALID_CODE_MESSAGE,
        ], 400);
    }
    if ($action === 'complete-reset') {
        passwordResetApiRespond([
            'success' => false,
            'message' => PASSWORD_RESET_INVALID_TOKEN_MESSAGE,
        ], 400);
    }

    passwordResetApiRespond(['success' => false, 'message' => 'Password reset is temporarily unavailable.'], 503);
}
