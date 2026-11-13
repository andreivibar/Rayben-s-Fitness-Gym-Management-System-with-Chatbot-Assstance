<?php

require_once __DIR__ . '/../config/password_reset_service.php';

$mode = $argv[1] ?? '';
$email = $argv[2] ?? '';
$readyDirectory = getenv('PASSWORD_RESET_TEST_READY_DIR') ?: '';
$releaseFile = getenv('PASSWORD_RESET_TEST_RELEASE_FILE') ?: '';
$payloadFile = getenv('PASSWORD_RESET_TEST_WORKER_PAYLOAD') ?: '';
$databaseName = getenv('PASSWORD_RESET_TEST_DB') ?: '';
$host = getenv('PASSWORD_RESET_TEST_HOST') ?: '127.0.0.1';
$port = getenv('PASSWORD_RESET_TEST_PORT') ?: '3306';
$username = getenv('PASSWORD_RESET_TEST_USER') ?: 'root';
$password = getenv('PASSWORD_RESET_TEST_PASSWORD') ?: '';

if (!in_array($mode, ['verify', 'complete'], true)
    || !preg_match('/^gym_password_reset_test_[a-z0-9_]+$/D', $databaseName)
    || $readyDirectory === '' || $releaseFile === '' || $payloadFile === '') {
    exit(2);
}

$payload = json_decode((string)file_get_contents($payloadFile), true);
if (!is_array($payload)) {
    exit(2);
}

$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$conn->exec("SET time_zone = '+08:00'");

$workerId = bin2hex(random_bytes(6));
file_put_contents($readyDirectory . DIRECTORY_SEPARATOR . $workerId, 'ready');
$deadline = microtime(true) + 15;
while (!is_file($releaseFile)) {
    if (microtime(true) >= $deadline) {
        exit(3);
    }
    usleep(10000);
}

if ($mode === 'verify') {
    $result = passwordResetVerifyCode($conn, $email, (string)($payload['code'] ?? ''));
} else {
    $result = passwordResetComplete(
        $conn,
        $email,
        (string)($payload['reset_token'] ?? ''),
        (string)($payload['new_password'] ?? ''),
        (string)($payload['new_password'] ?? '')
    );
}

echo json_encode(['success' => (bool)($result['success'] ?? false)]);
