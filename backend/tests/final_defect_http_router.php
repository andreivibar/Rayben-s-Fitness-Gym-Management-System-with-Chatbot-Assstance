<?php

$databaseName = getenv('FINAL_DEFECT_TEST_DB') ?: '';
$appRoot = getenv('FINAL_DEFECT_TEST_APP_ROOT') ?: '';
if (!preg_match('/^gym_final_defect_test_[a-z0-9_]+$/D', $databaseName)
    || $appRoot === ''
    || !is_dir($appRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'api')) {
    http_response_code(500);
    echo 'A dedicated disposable test schema and staged application copy are required.';
    exit;
}

$host = getenv('FINAL_DEFECT_TEST_HOST') ?: '127.0.0.1';
$port = getenv('FINAL_DEFECT_TEST_PORT') ?: '3306';
$username = getenv('FINAL_DEFECT_TEST_USER') ?: 'root';
$password = getenv('FINAL_DEFECT_TEST_PASSWORD') ?: '';
$GLOBALS['final_defect_test_connection'] = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$apiDirectory = $appRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'api';
$configDirectory = $appRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'config';

if ($path === '/login.php') {
    define('LOGIN_API_TESTING', true);
    $GLOBALS['login_api_test_connection'] = $GLOBALS['final_defect_test_connection'];
    require $apiDirectory . DIRECTORY_SEPARATOR . 'login.php';
}

if ($path === '/auth_check') {
    define('AUTH_CONFIG_TESTING', true);
    $GLOBALS['auth_test_connection'] = $GLOBALS['final_defect_test_connection'];
    require $configDirectory . DIRECTORY_SEPARATOR . 'auth.php';
    $user = getAuthUser($GLOBALS['final_defect_test_connection']);
    echo json_encode([
        'success' => true,
        'authenticated' => $user !== null,
        'user_id' => $user['id'] ?? null,
        'role' => $user['role'] ?? null,
    ]);
    exit;
}

$routes = [
    '/members.php' => 'members.php',
    '/member_profile.php' => 'member_profile.php',
    '/admin_member_registration.php' => 'admin_member_registration.php',
    '/registration_requests.php' => 'registration_requests.php',
    '/daily_passes.php' => 'daily_passes.php',
    '/member_attendance_history.php' => 'member_attendance_history.php',
];
if (isset($routes[$path])) {
    require $apiDirectory . DIRECTORY_SEPARATOR . $routes[$path];
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Test route not found.']);
