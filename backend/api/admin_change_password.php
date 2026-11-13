<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin_profile_service.php';

function sendAdminPasswordResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    $currentUser = requireRole($conn, ['admin', 'administrator']);
    $adminId = authenticatedAdminId($currentUser);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST, OPTIONS');
        sendAdminPasswordResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        sendAdminPasswordResponse(['success' => false, 'message' => 'A valid JSON password request is required.'], 400);
    }

    updateAdminPassword($conn, $adminId, $input);
    sendAdminPasswordResponse([
        'success' => true,
        'message' => 'Password changed successfully. Please sign in again.',
    ]);
} catch (InvalidArgumentException $error) {
    sendAdminPasswordResponse(['success' => false, 'message' => $error->getMessage()], 400);
} catch (DomainException $error) {
    sendAdminPasswordResponse(['success' => false, 'message' => $error->getMessage()], 403);
} catch (PDOException $error) {
    error_log('Admin password update failed.');
    sendAdminPasswordResponse(['success' => false, 'message' => 'Unable to change the password. Please try again.'], 500);
} catch (Throwable $error) {
    error_log('Admin password operation failed.');
    sendAdminPasswordResponse(['success' => false, 'message' => 'Unable to change the password. Please try again.'], 500);
}
