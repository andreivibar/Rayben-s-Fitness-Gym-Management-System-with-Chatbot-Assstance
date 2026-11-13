<?php

$databaseName = getenv('ADMIN_ACCOUNTS_TEST_DB');
if (!$databaseName || !preg_match('/^gym_admin_accounts_test_[a-z0-9_]+$/', $databaseName)) {
    fwrite(STDERR, "Set ADMIN_ACCOUNTS_TEST_DB to the dedicated disposable test schema.\n");
    exit(2);
}
$baseUrl = getenv('ADMIN_ACCOUNTS_TEST_URL') ?: 'http://127.0.0.1:8768';
$host = getenv('ADMIN_ACCOUNTS_TEST_HOST') ?: '127.0.0.1';
$port = getenv('ADMIN_ACCOUNTS_TEST_PORT') ?: '3306';
$username = getenv('ADMIN_ACCOUNTS_TEST_USER') ?: 'root';
$password = getenv('ADMIN_ACCOUNTS_TEST_PASSWORD') ?: '';
$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $databaseName) {
    throw new RuntimeException('HTTP tests must use the explicitly selected disposable schema.');
}

function assertAdminAccountsHttpTest(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $description);
    }
    echo 'Passed: ' . $description . PHP_EOL;
}

function adminAccountsHttpRequest(
    string $method,
    string $path,
    ?array $payload = null,
    array $headers = []
): array {
    global $baseUrl;
    $requestHeaders = array_merge(['Content-Type: application/json'], $headers);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $requestHeaders),
            'content' => $payload === null ? '' : json_encode($payload),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $body = file_get_contents($baseUrl . $path, false, $context);
    if ($body === false) {
        throw new RuntimeException('Unable to call the local disposable-schema HTTP test server.');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status = (int)$matches[1];
        }
    }
    $response = json_decode($body, true);
    if (!is_array($response)) {
        throw new RuntimeException('The local API returned a non-JSON response.');
    }
    return ['status' => $status, 'body' => $response];
}

$conn->exec("UPDATE users SET account_status='active', auth_token='admin-token-1' WHERE id=1");
$conn->exec("UPDATE users SET account_status='active', auth_token='admin-token-2' WHERE id=2");
$conn->exec("UPDATE users SET account_status='active' WHERE role='admin' AND id NOT IN (1, 2)");
$conn->exec("UPDATE users SET account_status='active', auth_token='member-token' WHERE id=3");
$conn->exec("UPDATE users SET account_status='active', auth_token='trainer-token' WHERE id=4");

$adminHeaders = ['X-Auth-Token: admin-token-1'];
$testSuffix = bin2hex(random_bytes(4));
$authResponse = adminAccountsHttpRequest('GET', '/auth_check', null, ['X-Auth-Token: admin-token-2']);
assertAdminAccountsHttpTest(
    $authResponse['body']['authenticated'] === true && (int)$authResponse['body']['user_id'] === 2,
    'active Admin token is accepted by the HTTP authentication path'
);

$list = adminAccountsHttpRequest('GET', '/admin_accounts.php', null, $adminHeaders);
$firstAccount = $list['body']['accounts'][0] ?? [];
assertAdminAccountsHttpTest(
    $list['status'] === 200 && count($list['body']['accounts'] ?? []) >= 2
        && !array_key_exists('password', $firstAccount)
        && !array_key_exists('auth_token', $firstAccount),
    'Admin account list HTTP endpoint returns accounts without password or token fields'
);

$createPayload = [
    'username' => 'httpadmin' . $testSuffix,
    'first_name' => 'HTTP',
    'last_name' => 'Admin',
    'email' => 'http-admin-' . $testSuffix . '@example.test',
    'password' => 'Safe-password-456',
    'confirm_password' => 'Safe-password-456',
];
$created = adminAccountsHttpRequest('POST', '/admin_accounts.php', $createPayload, $adminHeaders);
$createdId = (int)($created['body']['account']['id'] ?? 0);
$creatorId = $conn->prepare('SELECT created_by_admin_id FROM users WHERE id = :id');
$creatorId->execute([':id' => $createdId]);
assertAdminAccountsHttpTest(
    $created['status'] === 201 && $createdId > 0
        && !array_key_exists('password', $created['body']['account'] ?? [])
        && !array_key_exists('auth_token', $created['body']['account'] ?? [])
        && (int)$creatorId->fetchColumn() === 1,
    'Admin creation HTTP endpoint validates and attributes the new account without exposing credentials'
);
$duplicate = adminAccountsHttpRequest('POST', '/admin_accounts.php', $createPayload, $adminHeaders);
assertAdminAccountsHttpTest($duplicate['status'] === 409, 'duplicate username/email request receives a conflict response');
$invalidEmail = $createPayload;
$invalidEmail['username'] = 'httpbad' . $testSuffix;
$invalidEmail['email'] = 'invalid-email';
$invalid = adminAccountsHttpRequest('POST', '/admin_accounts.php', $invalidEmail, $adminHeaders);
assertAdminAccountsHttpTest($invalid['status'] === 400, 'invalid email receives a client validation response');

$deactivated = adminAccountsHttpRequest(
    'PUT',
    '/admin_accounts.php',
    ['id' => 2, 'account_status' => 'deactivated'],
    $adminHeaders
);
$oldToken = adminAccountsHttpRequest('GET', '/auth_check', null, ['X-Auth-Token: admin-token-2']);
$deactivatedLogin = adminAccountsHttpRequest(
    'POST',
    '/login.php',
    ['username' => 'admintwo', 'password' => 'existing-test-password']
);
assertAdminAccountsHttpTest(
    $deactivated['status'] === 200
        && ($deactivated['body']['account']['account_status'] ?? null) === 'deactivated'
        && $oldToken['body']['authenticated'] === false
        && $deactivatedLogin['status'] === 403,
    'Admin deactivation invalidates old-token access and prevents login over HTTP'
);

$selfDeactivate = adminAccountsHttpRequest(
    'PUT',
    '/admin_accounts.php',
    ['id' => 1, 'account_status' => 'deactivated'],
    $adminHeaders
);
assertAdminAccountsHttpTest($selfDeactivate['status'] === 409, 'self-deactivation is rejected by the HTTP endpoint');

$reactivated = adminAccountsHttpRequest(
    'PUT',
    '/admin_accounts.php',
    ['id' => 2, 'account_status' => 'active'],
    $adminHeaders
);
$loginAgain = adminAccountsHttpRequest(
    'POST',
    '/login.php',
    ['username' => 'admintwo', 'password' => 'existing-test-password']
);
$reactivatedOldToken = adminAccountsHttpRequest('GET', '/auth_check', null, ['X-Auth-Token: admin-token-2']);
assertAdminAccountsHttpTest(
    $reactivated['status'] === 200 && $loginAgain['status'] === 200
        && ($loginAgain['body']['role'] ?? null) === 'admin'
        && $reactivatedOldToken['body']['authenticated'] === false,
    'reactivated Admin must sign in again to receive a new valid token'
);

$memberLogin = adminAccountsHttpRequest(
    'POST',
    '/login.php',
    ['username' => 'memberone', 'password' => 'existing-test-password']
);
$trainerLogin = adminAccountsHttpRequest(
    'POST',
    '/login.php',
    ['username' => 'trainerone', 'password' => 'existing-test-password']
);
assertAdminAccountsHttpTest(
    $memberLogin['status'] === 200 && ($memberLogin['body']['role'] ?? null) === 'member'
        && $trainerLogin['status'] === 200 && ($trainerLogin['body']['role'] ?? null) === 'trainer',
    'Member and Trainer login behavior remains successful through the HTTP endpoint'
);

$memberAccess = adminAccountsHttpRequest(
    'GET',
    '/admin_accounts.php',
    null,
    ['X-Auth-Token: ' . $memberLogin['body']['auth_token']]
);
assertAdminAccountsHttpTest($memberAccess['status'] === 403, 'non-Admin requests are denied by the Admin-only HTTP route guard');

echo 'All Admin account HTTP tests passed against ' . $databaseName . '.' . PHP_EOL;
