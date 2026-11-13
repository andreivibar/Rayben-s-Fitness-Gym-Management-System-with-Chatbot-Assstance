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

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/registration_workflow.php';
require_once __DIR__ . '/../config/registration_storage.php';
require_once __DIR__ . '/../config/membership_workflow.php';
require_once __DIR__ . '/../config/membership_pricing.php';
require_once __DIR__ . '/../config/member_gender.php';
require_once __DIR__ . '/../config/duplicate_name_registration.php';
require_once __DIR__ . '/../config/admin_direct_registration.php';

try {
    $admin = requireRole($conn, ['admin']);
    $input = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?? []);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid registration payload.']);
        exit;
    }

    $result = createAdminDirectMember($conn, $admin, $input);
    http_response_code($result['replayed'] ? 200 : 201);
    echo json_encode($result);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (DomainException $error) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (PDOException $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ((int)($error->errorInfo[1] ?? 0) === 1062) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Username, email, or Member code is already in use.']);
        exit;
    }
    error_log('Admin direct Member registration failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create the Member registration. Please try again.']);
} catch (RuntimeException $error) {
    error_log('Admin direct Member registration could not be completed.');
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Admin direct Member registration failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create the Member registration. Please try again.']);
}
