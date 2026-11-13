<?php

$databaseName = getenv('LOGIN_RATE_LIMIT_TEST_DB') ?: '';
if (!preg_match('/^gym_login_rate_limit_test_[a-z0-9_]+$/D', $databaseName)
    || $databaseName === 'gym_management'
    || $databaseName === 'gym_management_test') {
    http_response_code(500);
    exit;
}

if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/_ready') {
    header('Content-Type: text/plain');
    echo 'ready';
    exit;
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

$testIp = (string)($_SERVER['HTTP_X_LOGIN_TEST_IP'] ?? '');
if (filter_var($testIp, FILTER_VALIDATE_IP)) {
    $_SERVER['REMOTE_ADDR'] = $testIp;
}

define('LOGIN_API_TESTING', true);
$GLOBALS['login_api_test_connection'] = $conn;
require __DIR__ . '/../api/login.php';

