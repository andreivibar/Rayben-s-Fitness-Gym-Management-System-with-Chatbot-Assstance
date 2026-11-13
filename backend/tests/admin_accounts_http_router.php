<?php

$databaseName = getenv('ADMIN_ACCOUNTS_TEST_DB');
if (!$databaseName || !preg_match('/^gym_admin_accounts_test_[a-z0-9_]+$/', $databaseName)) {
    http_response_code(500);
    echo 'A dedicated disposable Admin-account test schema is required.';
    exit;
}

$host = getenv('ADMIN_ACCOUNTS_TEST_HOST') ?: '127.0.0.1';
$port = getenv('ADMIN_ACCOUNTS_TEST_PORT') ?: '3306';
$username = getenv('ADMIN_ACCOUNTS_TEST_USER') ?: 'root';
$password = getenv('ADMIN_ACCOUNTS_TEST_PASSWORD') ?: '';
$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $databaseName) {
    http_response_code(500);
    echo 'The selected schema is not the disposable Admin-account test schema.';
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/login.php') {
    $GLOBALS['login_api_test_connection'] = $conn;
    define('LOGIN_API_TESTING', true);
    require __DIR__ . '/../api/login.php';
}

if ($path === '/auth_check') {
    $GLOBALS['auth_test_connection'] = $conn;
    define('AUTH_CONFIG_TESTING', true);
    require __DIR__ . '/../config/auth.php';
    $user = getAuthUser($conn);
    echo json_encode([
        'success' => true,
        'authenticated' => $user !== null,
        'user_id' => $user['id'] ?? null,
        'role' => $user['role'] ?? null,
    ]);
    exit;
}

if ($path === '/admin_accounts.php') {
    $GLOBALS['auth_test_connection'] = $conn;
    define('AUTH_CONFIG_TESTING', true);
    require __DIR__ . '/../api/admin_accounts.php';
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Test route not found.']);
