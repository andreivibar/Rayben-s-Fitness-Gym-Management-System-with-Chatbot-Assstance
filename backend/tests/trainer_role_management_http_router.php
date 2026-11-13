<?php

$databaseName = getenv('TRAINER_ROLE_TEST_DB') ?: '';
$appRoot = getenv('TRAINER_ROLE_TEST_APP_ROOT') ?: '';
if (!preg_match('/^gym_trainer_role_test_[a-z0-9_]+$/D', $databaseName)
    || $appRoot === ''
    || !is_dir($appRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'api')) {
    http_response_code(500);
    echo 'A dedicated disposable test schema and staged application copy are required.';
    exit;
}

$host = getenv('TRAINER_ROLE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('TRAINER_ROLE_TEST_PORT') ?: '3306';
$username = getenv('TRAINER_ROLE_TEST_USER') ?: 'root';
$password = getenv('TRAINER_ROLE_TEST_PASSWORD') ?: '';
$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $databaseName) {
    http_response_code(500);
    echo 'The selected schema is not the disposable Trainer Role Management schema.';
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$apiDirectory = $appRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'api';
$configDirectory = $appRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'config';

if ($path === '/auth_check') {
    require $configDirectory . DIRECTORY_SEPARATOR . 'auth.php';
    $user = getAuthUser($conn);
    echo json_encode([
        'success' => true,
        'authenticated' => $user !== null,
        'user_id' => $user['id'] ?? null,
        'role' => $user['role'] ?? null,
    ]);
    exit;
}

$routes = [
    '/login.php' => 'login.php',
    '/trainers.php' => 'trainers.php',
    '/trainer_profile.php' => 'trainer_profile.php',
    '/trainer_availability.php' => 'trainer_availability.php',
    '/trainer_attendance.php' => 'trainer_attendance.php',
    '/trainer_attendance_qr.php' => 'trainer_attendance_qr.php',
    '/trainer_attendance_records.php' => 'trainer_attendance_records.php',
    '/trainer_feedback.php' => 'trainer_feedback.php',
    '/notifications.php' => 'notifications.php',
    '/bookings.php' => 'bookings.php',
    '/admin_reset_password.php' => 'admin_reset_password.php',
    '/member_to_trainer.php' => 'member_to_trainer.php',
    '/member_profile.php' => 'member_profile.php',
];
if (isset($routes[$path])) {
    require $apiDirectory . DIRECTORY_SEPARATOR . $routes[$path];
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Test route not found.']);
