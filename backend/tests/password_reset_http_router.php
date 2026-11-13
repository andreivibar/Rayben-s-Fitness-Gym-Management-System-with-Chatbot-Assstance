<?php

$databaseName = getenv('PASSWORD_RESET_TEST_DB') ?: '';
if (!preg_match('/^gym_password_reset_test_[a-z0-9_]+$/D', $databaseName)) {
    http_response_code(500);
    exit;
}

$host = getenv('PASSWORD_RESET_TEST_HOST') ?: '127.0.0.1';
$port = getenv('PASSWORD_RESET_TEST_PORT') ?: '3306';
$username = getenv('PASSWORD_RESET_TEST_USER') ?: 'root';
$password = getenv('PASSWORD_RESET_TEST_PASSWORD') ?: '';
$dsn = "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4";
$GLOBALS['password_reset_test_connection'] = new PDO(
    $dsn,
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$GLOBALS['password_reset_test_connection']->exec("SET time_zone = '+08:00'");

if (isset($_SERVER['HTTP_X_TEST_CLIENT_IP'])
    && filter_var($_SERVER['HTTP_X_TEST_CLIENT_IP'], FILTER_VALIDATE_IP)) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_TEST_CLIENT_IP'];
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/password_reset.php') {
    define('PASSWORD_RESET_API_TESTING', true);
    define('PASSWORD_RESET_TESTING', true);
    $captureFile = getenv('PASSWORD_RESET_TEST_MAIL_CAPTURE') ?: '';
    $GLOBALS['password_reset_test_mailer'] = static function (string $recipient, string $code) use ($captureFile): void {
        if ($captureFile === '') {
            throw new RuntimeException('Test mail capture is unavailable.');
        }
        $payload = json_encode(['recipient' => $recipient, 'code' => $code], JSON_THROW_ON_ERROR);
        if (file_put_contents($captureFile, $payload . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Test mail capture failed.');
        }
    };
    require __DIR__ . '/../api/password_reset.php';
}

if ($path === '/login.php') {
    define('LOGIN_API_TESTING', true);
    $GLOBALS['login_api_test_connection'] = $GLOBALS['password_reset_test_connection'];
    require __DIR__ . '/../api/login.php';
}

http_response_code(404);
