<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

// Handle CORS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = isset($input['username']) ? trim((string) $input['username']) : '';
if ($username === '' && isset($input['email'])) {
    $username = trim((string) $input['email']);
}
$userPassword = isset($input['password']) ? (string) $input['password'] : '';

if (!$username || !$userPassword) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Username and password are required."]);
    exit;
}

$username = preg_replace('/\s+/', ' ', $username);
$username = trim($username);

if (!preg_match('/^(?:[A-Za-z0-9_]{3,30}|[^\s@]+@[^\s@]+\.[^\s@]+)$/', $username)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Username must be 3-30 characters using letters, numbers, or underscores, or provide a valid email address."]);
    exit;
}

if (strlen($userPassword) < 6) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Password must be at least 6 characters long."]);
    exit;
}

if (defined('LOGIN_API_TESTING') && LOGIN_API_TESTING === true) {
    $conn = $GLOBALS['login_api_test_connection'] ?? null;
    if (!$conn instanceof PDO) {
        throw new RuntimeException('The login API test connection is unavailable.');
    }
} else {
    require_once __DIR__ . '/../config/database.php';
}
require_once __DIR__ . '/../config/registration_identity.php';
require_once __DIR__ . '/../config/initial_account_approval.php';
require_once __DIR__ . '/../config/login_rate_limit_service.php';

try {
    $matchedUser = findLoginAccountByIdentifier($conn, $username);
    $authTokenColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'auth_token'")->fetch(PDO::FETCH_ASSOC);
    if (!$authTokenColumn) {
        $conn->exec('ALTER TABLE users ADD COLUMN auth_token VARCHAR(255) DEFAULT NULL AFTER role');
    }

    $result = loginRateLimitRun(
        $conn,
        $username,
        $matchedUser ? (int)$matchedUser['id'] : null,
        static function (PDO $conn) use ($matchedUser, $username, $userPassword): array {
            if (!$matchedUser) {
                loginRateLimitBurnDummyPasswordCheck($userPassword);
                return ['authenticated' => false];
            }

            $currentMatch = findLoginAccountByIdentifier($conn, $username);
            if (!$currentMatch || (int)$currentMatch['id'] !== (int)$matchedUser['id']) {
                loginRateLimitBurnDummyPasswordCheck($userPassword);
                return ['authenticated' => false];
            }

            $userStmt = $conn->prepare('SELECT * FROM users WHERE id = :id FOR UPDATE');
            $userStmt->execute([':id' => (int)$matchedUser['id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                loginRateLimitBurnDummyPasswordCheck($userPassword);
                return ['authenticated' => false];
            }

            if (registrationIdentityReleaseEnabled($conn)
                && strtolower(trim((string)($user['registration_status'] ?? ''))) !== 'active') {
                loginRateLimitBurnDummyPasswordCheck($userPassword);
                return ['authenticated' => false];
            }

            $storedPassword = (string)$user['password'];
            $passwordValid = password_verify($userPassword, $storedPassword);
            if (!$passwordValid) {
                $isHashed = strpos($storedPassword, '$2y$') === 0
                    || strpos($storedPassword, '$2b$') === 0
                    || strpos($storedPassword, '$2a$') === 0;
                if (!$isHashed && $userPassword === $storedPassword) {
                    $newHash = password_hash($userPassword, PASSWORD_BCRYPT);
                    if (!is_string($newHash)) {
                        throw new RuntimeException('Unable to secure the account password.');
                    }
                    $updatePassword = $conn->prepare('UPDATE users SET password = :hash WHERE id = :id');
                    $updatePassword->execute([
                        ':hash' => $newHash,
                        ':id' => (int)$user['id'],
                    ]);
                    $passwordValid = true;
                }
            }

            if (!$passwordValid) {
                return ['authenticated' => false];
            }

            $role = isset($user['role']) ? strtolower(trim((string)$user['role'])) : null;
            $hasAdminAccountStatus = array_key_exists('account_status', $user);
            if ($role === 'admin' && $hasAdminAccountStatus && $user['account_status'] !== 'active') {
                return ['authenticated' => false];
            }

            $initialApproval = $role === 'member'
                ? getInitialAccountApproval($conn, (int)$user['id'])
                : null;
            if ($role === 'member') {
                $accountStmt = $conn->prepare(
                    'SELECT account_status FROM members WHERE user_id = :user_id LIMIT 1 FOR UPDATE'
                );
                $accountStmt->execute([':user_id' => (int)$user['id']]);
                $accountStatus = $accountStmt->fetchColumn();
                if ($accountStatus === false && !requiresInitialAccountApproval($initialApproval)) {
                    return ['authenticated' => false];
                }
                if ($accountStatus !== false && $accountStatus !== 'active') {
                    $invalidateToken = $conn->prepare('UPDATE users SET auth_token = NULL WHERE id = :user_id');
                    $invalidateToken->execute([':user_id' => (int)$user['id']]);
                    return ['authenticated' => false];
                }
            }

            if ($role === 'trainer') {
                $trainerStmt = $conn->prepare(
                    'SELECT status FROM trainers WHERE user_id = :user_id LIMIT 1 FOR UPDATE'
                );
                $trainerStmt->execute([':user_id' => (int)$user['id']]);
                $trainerStatus = $trainerStmt->fetchColumn();
                if ($trainerStatus === false || strtolower(trim((string)$trainerStatus)) !== 'active') {
                    return ['authenticated' => false];
                }
            }

            $authToken = bin2hex(random_bytes(32));
            $adminActiveCondition = $hasAdminAccountStatus
                ? "AND (LOWER(TRIM(role)) <> 'admin' OR account_status = 'active')"
                : '';
            $memberActiveCondition = $role === 'member'
                ? "AND NOT EXISTS (
                       SELECT 1 FROM members
                       WHERE user_id = users.id AND COALESCE(account_status, '') <> 'active'
                   )"
                : '';
            $trainerActiveCondition = $role === 'trainer'
                ? "AND EXISTS (
                       SELECT 1 FROM trainers
                       WHERE user_id = users.id AND LOWER(TRIM(status)) = 'active'
                   )"
                : '';
            $tokenStmt = $conn->prepare(
                "UPDATE users SET auth_token = :token
                 WHERE id = :id {$adminActiveCondition} {$memberActiveCondition} {$trainerActiveCondition}"
            );
            $tokenStmt->execute([':token' => $authToken, ':id' => (int)$user['id']]);
            if ($tokenStmt->rowCount() !== 1) {
                return ['authenticated' => false];
            }

            return [
                'authenticated' => true,
                'response' => [
                    'success' => true,
                    'message' => 'Login successful.',
                    'role' => $role,
                    'auth_token' => $authToken,
                    'user' => [
                        'id' => $user['id'],
                        'first_name' => $user['first_name'] ?? null,
                        'middle_name' => $user['middle_name'] ?? null,
                        'last_name' => $user['last_name'] ?? null,
                        'full_name' => $user['full_name'],
                        'username' => $user['username'] ?? null,
                        'email' => $user['email'],
                        'role' => $role,
                        'initial_approval' => $initialApproval,
                    ],
                ],
            ];
        }
    );

    if ($result['status'] === 'authenticated') {
        echo json_encode($result['response']);
        exit;
    }
    if ($result['status'] === 'ip_limited') {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => LOGIN_RATE_LIMIT_IP_MESSAGE]);
        exit;
    }

    http_response_code(401);
    echo json_encode(['success' => false, 'message' => LOGIN_RATE_LIMIT_GENERIC_MESSAGE]);
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error. Please try again later."]);
    exit;
} catch (RuntimeException $e) {
    error_log('Login rate-limit configuration or transaction failed.');
    http_response_code(503);
    echo json_encode(["success" => false, "message" => "Login is temporarily unavailable. Please try again later."]);
    exit;
}
