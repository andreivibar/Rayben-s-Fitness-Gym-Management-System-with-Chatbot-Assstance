<?php

require_once __DIR__ . '/registration_identity.php';
// Set application timezone to Asia/Manila for consistent timestamp handling
date_default_timezone_set('Asia/Manila');

function emitCorsHeaders() {
    $allowedOrigins = [
        'http://localhost',
        'http://localhost:5173',
        'http://localhost:5174',
        'http://127.0.0.1',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:5174',
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    $originTrimmed = '';
    if ($origin) {
        $parts = parse_url($origin);
        if ($parts && isset($parts['scheme']) && isset($parts['host'])) {
            $portSuffix = isset($parts['port']) ? ':' . $parts['port'] : '';
            $originTrimmed = $parts['scheme'] . '://' . $parts['host'] . $portSuffix;
        }
    }

    $useOrigin = '';
    if ($originTrimmed && in_array(strtolower($originTrimmed), array_map('strtolower', $allowedOrigins), true)) {
        $useOrigin = $originTrimmed;
    }

    if (!$useOrigin) {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $selfOrigin = $scheme . '://' . $host;
        $useOrigin = $selfOrigin;
    }

    header('Access-Control-Allow-Origin: ' . $useOrigin, true);
    header('Access-Control-Allow-Credentials: true', true);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS', true);
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email', true);
    header('Access-Control-Expose-Headers: Content-Type, Authorization, X-Auth-Token', true);
    header('Vary: Origin', true);
    header('Content-Type: application/json', true);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
emitCorsHeaders();

if (defined('AUTH_CONFIG_TESTING') && AUTH_CONFIG_TESTING === true) {
    $conn = $GLOBALS['auth_test_connection'] ?? null;
    if (!$conn instanceof PDO) {
        throw new RuntimeException('The auth test connection is unavailable.');
    }
} else {
    require_once __DIR__ . '/database.php';
}
require_once __DIR__ . '/initial_account_approval.php';

// Ensure auth_token column exists on users table
if (!(defined('AUTH_CONFIG_TESTING') && AUTH_CONFIG_TESTING === true)) {
    try {
        $colStmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'auth_token'");
        $colStmt->execute();
        if (!$colStmt->fetch()) {
            $conn->exec("ALTER TABLE users ADD COLUMN auth_token VARCHAR(255) DEFAULT NULL AFTER role");
        }
    } catch (Exception $e) {
        // ignore if alter fails
    }
}

function getBearerToken() {
    // 1. Check server environment variables
    $headers = $_SERVER['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTH_TOKEN'] ?? null;

    // 2. Apache request headers (use lowercase keys for reliable lookup)
    if (!$headers && function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if (is_array($requestHeaders)) {
            $lowerHeaders = array_change_key_case($requestHeaders, CASE_LOWER);
            $headers = $lowerHeaders['authorization'] ?? $lowerHeaders['x-auth-token'] ?? null;
        }
    }

    if (!empty($headers)) {
        if (preg_match('/Bearer\s+(\S+)/i', $headers, $matches)) {
            return $matches[1];
        }
        return trim($headers);
    }

    // 3. Query string or body payload fallback
    if (!empty($_GET['token'])) return trim($_GET['token']);
    if (!empty($_GET['auth_token'])) return trim($_GET['auth_token']);

    $inputRaw = @file_get_contents('php://input');
    $GLOBALS['__php_input_cache'] = $inputRaw;
    $input = @json_decode($inputRaw, true);
    if (!empty($input['auth_token'])) return trim($input['auth_token']);
    if (!empty($input['token'])) return trim($input['token']);

    return null;
}

function authHasAdminAccountStatusColumn(PDO $conn): bool
{
    static $schemaStateByConnection = [];
    $connectionId = spl_object_id($conn);
    if (!array_key_exists($connectionId, $schemaStateByConnection)) {
        $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'account_status'");
        $schemaStateByConnection[$connectionId] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    return $schemaStateByConnection[$connectionId];
}

function getAuthUser($conn) {
    $token = getBearerToken();
    $registrationIdentityFilter = registrationIdentityReleaseEnabled($conn)
        ? "AND u.registration_status = 'active'"
        : '';
    $adminAccountStatusFilter = authHasAdminAccountStatusColumn($conn)
        ? "AND (LOWER(TRIM(u.role)) <> 'admin' OR u.account_status = 'active')"
        : '';

    if ($token) {
        $stmt = $conn->prepare("SELECT u.id, u.first_name, u.middle_name, u.last_name, u.full_name, u.email, u.role, u.auth_token
                                FROM users u
                                WHERE u.auth_token = :token
                                  {$registrationIdentityFilter}
                                  {$adminAccountStatusFilter}
                                  AND (LOWER(TRIM(u.role)) <> 'member' OR NOT EXISTS (
                                      SELECT 1 FROM members m
                                      WHERE m.user_id = u.id
                                        AND COALESCE(m.account_status, '') <> 'active'
                                  ))
                                  AND (LOWER(TRIM(u.role)) <> 'trainer' OR EXISTS (
                                      SELECT 1 FROM trainers t
                                      WHERE t.user_id = u.id AND LOWER(TRIM(t.status)) = 'active'
                                  ))
                                LIMIT 1");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return $user;
        }
    }

    // Safe cross-verification fallback: Only accept if BOTH X-User-Email AND
    // token/X-Auth-Token match the same user. This is NOT a bypass -- it
    // requires the caller to have two pieces of info (email + valid token).
    $email = $_SERVER['HTTP_X_USER_EMAIL'] ?? $_GET['email'] ?? null;
    if (!$email) {
        $inputRaw = $GLOBALS['__php_input_cache'] ?? @file_get_contents('php://input');
        $input = @json_decode($inputRaw, true);
        $email = $input['email'] ?? $input['member_email'] ?? null;
    }
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && $token) {
        $stmt = $conn->prepare("SELECT u.id, u.first_name, u.middle_name, u.last_name, u.full_name, u.email, u.role, u.auth_token
                                FROM users u
                                WHERE u.email = :email
                                  {$registrationIdentityFilter}
                                  {$adminAccountStatusFilter}
                                  AND (LOWER(TRIM(u.role)) <> 'member' OR NOT EXISTS (
                                      SELECT 1 FROM members m
                                      WHERE m.user_id = u.id
                                        AND COALESCE(m.account_status, '') <> 'active'
                                  ))
                                  AND (LOWER(TRIM(u.role)) <> 'trainer' OR EXISTS (
                                      SELECT 1 FROM trainers t
                                      WHERE t.user_id = u.id AND LOWER(TRIM(t.status)) = 'active'
                                  ))
                                LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && !empty($user['auth_token']) && hash_equals($user['auth_token'], $token)) {
            return $user;
        }
    }

    return null;
}

function requireAuth($conn, bool $allowInitialApprovalStatusCheck = false) {
    $user = getAuthUser($conn);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Invalid or missing authentication token.']);
        exit;
    }

    $isMember = strtolower(trim((string)$user['role'])) === 'member';
    $requiresInitialApproval = $isMember
        && requiresInitialAccountApproval(
            getInitialAccountApproval($conn, (int)$user['id'])
        );
    if (!$allowInitialApprovalStatusCheck && $requiresInitialApproval) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'code' => 'initial_account_approval_required',
            'message' => 'Member features are unavailable until your initial account registration is approved.',
        ]);
        exit;
    }

    if ($isMember && !($allowInitialApprovalStatusCheck && $requiresInitialApproval)) {
        $memberStmt = $conn->prepare('SELECT id FROM members WHERE user_id = :user_id LIMIT 1');
        $memberStmt->execute([':user_id' => (int)$user['id']]);
        if (!$memberStmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Member profile not found for this account.']);
            exit;
        }
    }

    return $user;
}

function requireRole($conn, array $allowedRoles) {
    $user = requireAuth($conn);
    $userRole = strtolower(trim((string)$user['role']));
    $allowed = array_map(function($r) { return strtolower(trim($r)); }, $allowedRoles);

    if (!in_array($userRole, $allowed, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden. Access restricted for your user role.']);
        exit;
    }
    return $user;
}

?>
