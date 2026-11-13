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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST, OPTIONS');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (defined('EMAIL_CHANGE_API_TESTING') && EMAIL_CHANGE_API_TESTING === true) {
    $conn = $GLOBALS['email_change_test_connection'] ?? null;
    if (!$conn instanceof PDO) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Email change is temporarily unavailable.']);
        exit;
    }
    require_once __DIR__ . '/../config/auth.php';
} else {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
}
require_once __DIR__ . '/../config/email_change_service.php';
require_once __DIR__ . '/../config/email_change_mailer.php';

function emailChangeApiRespond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    emailChangeApiRespond(['success' => false, 'message' => 'Invalid request payload.'], 400);
}
if (array_key_exists('user_id', $input) || array_key_exists('id', $input)) {
    emailChangeApiRespond(['success' => false, 'message' => 'Account identifiers cannot be supplied in this request.'], 400);
}

try {
    $user = requireAuth($conn);
    $role = strtolower(trim((string)($user['role'] ?? '')));
    if (!in_array($role, ['member', 'trainer'], true)) {
        emailChangeApiRespond(['success' => false, 'message' => 'This feature is available to Members and Trainers only.'], 403);
    }
    emailChangePruneExpiredRows($conn);

    $action = strtolower(trim((string)($input['action'] ?? '')));
    $newEmail = is_string($input['new_email'] ?? null) ? $input['new_email'] : '';
    $currentPassword = is_string($input['current_password'] ?? null) ? $input['current_password'] : '';

    if ($action === 'request-code') {
        $result = emailChangeRequestCode($conn, $user, $newEmail, $currentPassword);
    } elseif ($action === 'verify-code') {
        $code = is_string($input['code'] ?? null) ? $input['code'] : '';
        $result = emailChangeVerifyCode($conn, $user, $newEmail, $currentPassword, $code);
    } else {
        emailChangeApiRespond(['success' => false, 'message' => 'Invalid email-change action.'], 400);
    }

    $status = (int)($result['status'] ?? 500);
    unset($result['status'], $result['old_email'], $result['challenge_id'], $result['recipient'], $result['code']);
    emailChangeApiRespond($result, $status);
} catch (Throwable $error) {
    error_log('Email-change operation failed; exception=' . get_class($error));
    emailChangeApiRespond(['success' => false, 'message' => 'Email change is temporarily unavailable. Please try again later.'], 503);
}
