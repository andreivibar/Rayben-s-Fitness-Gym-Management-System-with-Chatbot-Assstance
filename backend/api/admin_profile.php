<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin_profile_service.php';

function sendAdminProfileResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    $currentUser = requireRole($conn, ['admin', 'administrator']);
    $adminId = authenticatedAdminId($currentUser);
    $method = $_SERVER['REQUEST_METHOD'] ?? '';

    if ($method === 'GET') {
        $profile = fetchAdminProfile($conn, $adminId);
        if (!$profile) {
            sendAdminProfileResponse(['success' => false, 'message' => 'Admin profile not found.'], 404);
        }
        sendAdminProfileResponse(['success' => true, 'admin' => $profile]);
    }

    if (!in_array($method, ['POST', 'PUT'], true)) {
        header('Allow: GET, POST, PUT, OPTIONS');
        sendAdminProfileResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        sendAdminProfileResponse(['success' => false, 'message' => 'A valid JSON profile is required.'], 400);
    }

    $profile = updateAdminProfile($conn, $adminId, $input);
    sendAdminProfileResponse([
        'success' => true,
        'message' => 'Admin profile updated successfully.',
        'admin' => $profile,
    ]);
} catch (InvalidArgumentException $error) {
    sendAdminProfileResponse(['success' => false, 'message' => $error->getMessage()], 400);
} catch (DomainException $error) {
    sendAdminProfileResponse(['success' => false, 'message' => $error->getMessage()], 409);
} catch (PDOException $error) {
    if ((string)$error->getCode() === '23000') {
        sendAdminProfileResponse(['success' => false, 'message' => 'That email address is already associated with another active account.'], 409);
    }
    error_log('Admin profile database operation failed.');
    sendAdminProfileResponse(['success' => false, 'message' => 'Unable to update the Admin profile. Please try again.'], 500);
} catch (Throwable $error) {
    error_log('Admin profile operation failed.');
    sendAdminProfileResponse(['success' => false, 'message' => 'Unable to complete the Admin profile request.'], 500);
}
