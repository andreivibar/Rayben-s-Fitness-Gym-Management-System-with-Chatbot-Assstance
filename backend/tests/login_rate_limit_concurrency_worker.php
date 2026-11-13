<?php

$databaseName = getenv('LOGIN_RATE_LIMIT_TEST_DB') ?: '';
$identifier = $argv[1] ?? '';
$userId = isset($argv[2]) ? (int)$argv[2] : 0;
$clientIp = $argv[3] ?? '';
$readyDirectory = $argv[4] ?? '';
$releaseFile = $argv[5] ?? '';
if (!preg_match('/^gym_login_rate_limit_test_[a-z0-9_]+$/D', $databaseName)
    || $databaseName === 'gym_management'
    || $databaseName === 'gym_management_test'
    || $identifier === ''
    || $userId <= 0
    || filter_var($clientIp, FILTER_VALIDATE_IP) === false
    || !is_dir($readyDirectory)
    || $releaseFile === '') {
    fwrite(STDERR, "Invalid login rate-limit concurrency worker configuration.\n");
    exit(2);
}

$host = getenv('LOGIN_RATE_LIMIT_TEST_HOST') ?: '127.0.0.1';
$port = getenv('LOGIN_RATE_LIMIT_TEST_PORT') ?: '3306';
$username = getenv('LOGIN_RATE_LIMIT_TEST_USER') ?: 'root';
$password = getenv('LOGIN_RATE_LIMIT_TEST_PASSWORD') ?: '';
$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$conn->exec("SET time_zone = '+08:00'");
$_SERVER['REMOTE_ADDR'] = $clientIp;
require_once __DIR__ . '/../config/login_rate_limit_service.php';

$readyFile = $readyDirectory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8));
if (file_put_contents($readyFile, 'ready', LOCK_EX) === false) {
    fwrite(STDERR, "Unable to signal login worker readiness.\n");
    exit(2);
}

$deadline = microtime(true) + 15;
while (!is_file($releaseFile)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for login worker release.\n");
        exit(3);
    }
    usleep(10000);
}

$result = loginRateLimitRun(
    $conn,
    $identifier,
    $userId,
    static function (): array {
        return ['authenticated' => false];
    }
);
echo $result['status'];

