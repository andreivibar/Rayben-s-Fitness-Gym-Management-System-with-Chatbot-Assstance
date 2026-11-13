<?php

$databaseName = getenv('EMAIL_CHANGE_TEST_DB') ?: '';
if (!preg_match('/^gym_email_change_test_[a-z0-9_]+$/D', $databaseName)) {
    http_response_code(500);
    exit;
}

$host = getenv('EMAIL_CHANGE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('EMAIL_CHANGE_TEST_PORT') ?: '3306';
$username = getenv('EMAIL_CHANGE_TEST_USER') ?: 'root';
$password = getenv('EMAIL_CHANGE_TEST_PASSWORD') ?: '';
$dsn = "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4";
$GLOBALS['email_change_test_connection'] = new PDO(
    $dsn,
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$GLOBALS['email_change_test_connection']->exec("SET time_zone = '+08:00'");
$GLOBALS['auth_test_connection'] = $GLOBALS['email_change_test_connection'];
define('AUTH_CONFIG_TESTING', true);
define('EMAIL_CHANGE_API_TESTING', true);
define('EMAIL_CHANGE_TESTING', true);

if (isset($_SERVER['HTTP_X_TEST_CLIENT_IP'])
    && filter_var($_SERVER['HTTP_X_TEST_CLIENT_IP'], FILTER_VALIDATE_IP)) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_TEST_CLIENT_IP'];
}

$captureFile = getenv('EMAIL_CHANGE_TEST_MAIL_CAPTURE') ?: '';
$GLOBALS['email_change_test_mailer'] = static function (
    string $kind,
    string $recipient,
    ?string $code,
    string $subject,
    string $htmlBody,
    string $textBody
) use ($captureFile): void {
    if ($captureFile === '') {
        throw new RuntimeException('Email-change test mail capture is unavailable.');
    }

    $payload = json_encode([
        'kind' => $kind,
        'recipient' => $recipient,
        'code' => $code,
        'subject' => $subject,
        'html_body' => $htmlBody,
        'text_body' => $textBody,
    ], JSON_THROW_ON_ERROR);
    if (file_put_contents($captureFile, $payload . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Email-change test mail capture failed.');
    }
    if (($_SERVER['HTTP_X_TEST_FAIL_MAIL'] ?? '') === $kind) {
        throw new RuntimeException('Simulated test mail failure.');
    }
};

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/email_change.php') {
    require __DIR__ . '/../api/email_change.php';
}
if ($path === '/login.php') {
    define('LOGIN_API_TESTING', true);
    $GLOBALS['login_api_test_connection'] = $GLOBALS['email_change_test_connection'];
    require __DIR__ . '/../api/login.php';
}

http_response_code(404);
