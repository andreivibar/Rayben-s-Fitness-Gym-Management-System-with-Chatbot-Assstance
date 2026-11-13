<?php

require_once __DIR__ . '/../config/auth.php';
$currentUser = requireRole($conn, ['admin', 'administrator']);
require_once __DIR__ . '/../config/admin_profile_service.php';
require_once __DIR__ . '/../config/admin_account_service.php';

function adminAccountsResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    $actorAdminId = authenticatedAdminId($currentUser);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        adminAccountsResponse(['success' => true, 'accounts' => listAdminAccounts($conn)]);
    }

    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        adminAccountsResponse(['success' => false, 'message' => 'A valid JSON request is required.'], 400);
    }

    if ($method === 'POST') {
        $created = createAdminAccount($conn, $actorAdminId, $input);
        adminAccountsResponse([
            'success' => true,
            'message' => 'Admin account created successfully.',
            'account' => $created,
        ], 201);
    }

    if ($method === 'PUT') {
        $targetId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if ($targetId === false || $targetId === null || $targetId < 1) {
            adminAccountsResponse(['success' => false, 'message' => 'A valid Admin account ID is required.'], 400);
        }
        $updated = updateAdminAccountStatus(
            $conn,
            $actorAdminId,
            $targetId,
            (string)($input['account_status'] ?? '')
        );
        adminAccountsResponse([
            'success' => true,
            'message' => 'Admin account status updated successfully.',
            'account' => $updated,
        ]);
    }

    header('Allow: GET, POST, PUT, OPTIONS');
    adminAccountsResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
} catch (InvalidArgumentException $error) {
    adminAccountsResponse(['success' => false, 'message' => $error->getMessage()], 400);
} catch (OutOfBoundsException $error) {
    adminAccountsResponse(['success' => false, 'message' => $error->getMessage()], 404);
} catch (DomainException $error) {
    adminAccountsResponse(['success' => false, 'message' => $error->getMessage()], 409);
} catch (PDOException $error) {
    if ((string)$error->getCode() === '23000') {
        adminAccountsResponse([
            'success' => false,
            'message' => 'That username or email address is already associated with an account.',
        ], 409);
    }
    error_log('Admin account database operation failed.');
    adminAccountsResponse(['success' => false, 'message' => 'Unable to complete the Admin account request.'], 500);
} catch (Throwable $error) {
    error_log('Admin account operation failed.');
    adminAccountsResponse(['success' => false, 'message' => 'Unable to complete the Admin account request.'], 500);
}
