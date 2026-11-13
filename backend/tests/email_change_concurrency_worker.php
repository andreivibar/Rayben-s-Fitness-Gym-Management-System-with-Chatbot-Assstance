<?php

$payloadPath = $argv[1] ?? '';
$readyPath = $argv[2] ?? '';
$releasePath = $argv[3] ?? '';
$payload = json_decode((string)@file_get_contents($payloadPath), true);
$databaseName = getenv('EMAIL_CHANGE_TEST_DB') ?: '';
if (!is_array($payload)
    || !preg_match('/^gym_email_change_test_[a-z0-9_]+$/D', $databaseName)
    || $readyPath === ''
    || $releasePath === '') {
    fwrite(STDERR, "Invalid email-change concurrency test configuration.\n");
    exit(2);
}

$host = getenv('EMAIL_CHANGE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('EMAIL_CHANGE_TEST_PORT') ?: '3306';
$username = getenv('EMAIL_CHANGE_TEST_USER') ?: 'root';
$password = getenv('EMAIL_CHANGE_TEST_PASSWORD') ?: '';
$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$conn->exec("SET time_zone = '+08:00'");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define('EMAIL_CHANGE_TESTING', true);
$GLOBALS['email_change_test_mailer'] = static function (): void {
};

require __DIR__ . '/../config/email_change_service.php';
require __DIR__ . '/../config/email_change_mailer.php';
if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
    fwrite(STDERR, "Unable to signal test worker readiness.\n");
    exit(2);
}

$deadline = microtime(true) + 10;
while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for concurrency test release.\n");
        exit(2);
    }
    usleep(10000);
}

$result = emailChangeVerifyCode(
    $conn,
    ['id' => (int)$payload['user_id'], 'auth_token' => (string)$payload['auth_token']],
    (string)$payload['new_email'],
    (string)$payload['password'],
    (string)$payload['code']
);
echo json_encode(['success' => (bool)$result['success'], 'status' => (int)$result['status']]);
