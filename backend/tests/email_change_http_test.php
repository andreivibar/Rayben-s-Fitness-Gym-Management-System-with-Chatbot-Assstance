<?php

$databaseName = getenv('EMAIL_CHANGE_TEST_DB') ?: '';
$privateDirectory = getenv('EMAIL_CHANGE_TEST_PRIVATE_DIR') ?: '';
$host = getenv('EMAIL_CHANGE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('EMAIL_CHANGE_TEST_PORT') ?: '3306';
$username = getenv('EMAIL_CHANGE_TEST_USER') ?: 'root';
$password = getenv('EMAIL_CHANGE_TEST_PASSWORD') ?: '';
$hmacKey = (string)getenv('PASSWORD_RESET_HMAC_KEY');
if (!preg_match('/^gym_email_change_test_[a-z0-9_]+$/D', $databaseName)
    || $privateDirectory === ''
    || strlen($hmacKey) < 32
    || !is_dir($privateDirectory)) {
    fwrite(STDERR, "Email-change HTTP test environment is incomplete.\n");
    exit(2);
}

$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$conn->exec("SET time_zone = '+08:00'");
require_once __DIR__ . '/../config/email_change_service.php';

$checks = 0;
$requestCounter = 0;
$baseUrl = '';
$serverProcess = null;
$serverLog = $privateDirectory . DIRECTORY_SEPARATOR . 'php-server.log';
$captureFile = $privateDirectory . DIRECTORY_SEPARATOR . 'mail-capture.jsonl';

function emailChangeTestAssert(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function emailChangeTestHttp(
    string $path,
    array $payload,
    ?string $token = null,
    ?string $failMail = null,
    ?string $testIp = null
): array
{
    global $baseUrl, $requestCounter;
    $requestCounter++;
    $group = intdiv($requestCounter, 250) + 1;
    $hostOctet = (($requestCounter - 1) % 250) + 1;
    $headers = [
        'Content-Type: application/json',
        'X-Test-Client-IP: ' . ($testIp ?? ('10.0.' . $group . '.' . $hostOctet)),
    ];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
        $headers[] = 'X-Auth-Token: ' . $token;
    }
    if ($failMail !== null) {
        $headers[] = 'X-Test-Fail-Mail: ' . $failMail;
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $body = @file_get_contents($baseUrl . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
        }
    }

    return [
        'status' => $status,
        'data' => is_string($body) ? json_decode($body, true) : null,
    ];
}

function emailChangeTestLatestMail(string $kind, string $recipient): array
{
    global $captureFile;
    $messages = [];
    foreach (file($captureFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $message = json_decode($line, true);
        if (is_array($message)
            && ($message['kind'] ?? '') === $kind
            && strtolower((string)($message['recipient'] ?? '')) === strtolower($recipient)) {
            $messages[] = $message;
        }
    }
    if (!$messages) {
        throw new RuntimeException('Expected fake email was not captured.');
    }
    return $messages[count($messages) - 1];
}

function emailChangeTestAccount(PDO $conn, string $role, ?string $email): array
{
    static $sequence = 0;
    $sequence++;
    $suffix = bin2hex(random_bytes(5));
    $token = 'test-session-' . $suffix;
    $passwordHash = password_hash('CurrentPassword-123', PASSWORD_DEFAULT);
    $insert = $conn->prepare(
        'INSERT INTO users
            (first_name, last_name, full_name, username, email, password, role,
             registration_status, account_status, auth_token)
         VALUES
            (:first_name, :last_name, :full_name, :username, :email, :password, :role,
             "active", "active", :auth_token)'
    );
    $insert->execute([
        ':first_name' => ucfirst($role),
        ':last_name' => 'Fixture' . $sequence,
        ':full_name' => ucfirst($role) . ' Fixture ' . $sequence,
        ':username' => $role . '_fixture_' . $suffix,
        ':email' => $email,
        ':password' => $passwordHash,
        ':role' => $role,
        ':auth_token' => $token,
    ]);
    $userId = (int)$conn->lastInsertId();
    $profileId = null;
    if ($role === 'member') {
        $profile = $conn->prepare(
            'INSERT INTO members (user_id, email, account_status, membership_type)
             VALUES (:user_id, :email, "active", "monthly")'
        );
        $profile->execute([':user_id' => $userId, ':email' => $email]);
        $profileId = (int)$conn->lastInsertId();
    } elseif ($role === 'trainer') {
        $profile = $conn->prepare('INSERT INTO trainers (user_id, status) VALUES (:user_id, "active")');
        $profile->execute([':user_id' => $userId]);
        $profileId = (int)$conn->lastInsertId();
    }

    return [
        'id' => $userId,
        'role' => $role,
        'email' => $email,
        'token' => $token,
        'username' => $role . '_fixture_' . $suffix,
        'profile_id' => $profileId,
        'password' => 'CurrentPassword-123',
    ];
}

function emailChangeTestRequest(
    array $account,
    string $newEmail,
    ?string $password = null,
    ?string $failMail = null,
    ?string $testIp = null
): array
{
    return emailChangeTestHttp(
        '/email_change.php',
        [
            'action' => 'request-code',
            'new_email' => $newEmail,
            'current_password' => $password ?? $account['password'],
        ],
        $account['token'],
        $failMail,
        $testIp
    );
}

function emailChangeTestVerify(
    array $account,
    string $newEmail,
    string $code,
    ?string $password = null,
    ?string $failMail = null,
    ?string $testIp = null
): array
{
    return emailChangeTestHttp(
        '/email_change.php',
        [
            'action' => 'verify-code',
            'new_email' => $newEmail,
            'current_password' => $password ?? $account['password'],
            'code' => $code,
        ],
        $account['token'],
        $failMail,
        $testIp
    );
}

function emailChangeTestCapturedCode(string $email): string
{
    $message = emailChangeTestLatestMail('verification', $email);
    $code = (string)($message['code'] ?? '');
    if (preg_match('/^\d{6}$/D', $code) !== 1) {
        throw new RuntimeException('The fake mailer did not capture a six-digit code.');
    }
    return $code;
}

function emailChangeTestChallenge(PDO $conn, int $userId): array
{
    $stmt = $conn->prepare(
        'SELECT id, new_email, code_hash, expires_at, attempt_count, verified_at, consumed_at
         FROM email_change_challenges
         WHERE user_id = :user_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId]);
    $challenge = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$challenge) {
        throw new RuntimeException('Expected email-change challenge was not stored.');
    }
    return $challenge;
}

function emailChangeTestInsertRegistration(PDO $conn, string $status, string $email, ?int $memberId = null): void
{
    static $sequence = 0;
    $sequence++;
    $stmt = $conn->prepare(
        'INSERT INTO registration_requests
            (member_id, registration_source, membership_type, status, username, email, password)
         VALUES (:member_id, "online", "monthly", :status, :username, :email, "fixture-hash")'
    );
    $stmt->execute([
        ':member_id' => $memberId,
        ':status' => $status,
        ':username' => 'request_fixture_' . $sequence,
        ':email' => $email,
    ]);
}

function emailChangeTestCounts(PDO $conn, string $table, ?int $memberId): int
{
    $allowed = ['payments', 'membership_subscription_periods', 'bookings', 'attendance', 'daily_pass_transactions'];
    if (!in_array($table, $allowed, true)) {
        throw new RuntimeException('Invalid history table in test.');
    }
    $stmt = $conn->prepare("SELECT COUNT(*) FROM {$table} WHERE member_id = :member_id");
    $stmt->execute([':member_id' => $memberId]);
    return (int)$stmt->fetchColumn();
}

function emailChangeTestLogin(string $identifier, string $password): array
{
    $response = emailChangeTestHttp('/login.php', [
        'username' => $identifier,
        'password' => $password,
    ]);
    return $response;
}

function emailChangeTestRunConcurrentWorkers(array $account, string $email, string $code, string $privateDirectory): array
{
    global $databaseName, $host, $port, $username, $password, $hmacKey;
    $case = bin2hex(random_bytes(6));
    $caseDirectory = $privateDirectory . DIRECTORY_SEPARATOR . 'workers_' . $case;
    if (!mkdir($caseDirectory, 0700, true)) {
        throw new RuntimeException('Unable to create isolated concurrency test files.');
    }
    $release = $caseDirectory . DIRECTORY_SEPARATOR . 'release';
    $payload = $caseDirectory . DIRECTORY_SEPARATOR . 'payload.json';
    file_put_contents($payload, json_encode([
        'user_id' => $account['id'],
        'auth_token' => $account['token'],
        'new_email' => $email,
        'password' => $account['password'],
        'code' => $code,
    ], JSON_THROW_ON_ERROR), LOCK_EX);

    $environment = array_merge(getenv(), [
        'EMAIL_CHANGE_TEST_DB' => $databaseName,
        'EMAIL_CHANGE_TEST_HOST' => $host,
        'EMAIL_CHANGE_TEST_PORT' => (string)$port,
        'EMAIL_CHANGE_TEST_USER' => $username,
        'EMAIL_CHANGE_TEST_PASSWORD' => $password,
        'PASSWORD_RESET_HMAC_KEY' => $hmacKey,
    ]);
    $workers = [];
    for ($index = 0; $index < 2; $index++) {
        $ready = $caseDirectory . DIRECTORY_SEPARATOR . 'ready_' . $index;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/email_change_concurrency_worker.php', $payload, $ready, $release],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start an isolated concurrency worker.');
        }
        fclose($pipes[0]);
        $workers[] = ['process' => $process, 'pipes' => $pipes, 'ready' => $ready];
    }

    $deadline = microtime(true) + 10;
    do {
        $readyCount = count(array_filter($workers, static fn(array $worker): bool => is_file($worker['ready'])));
        if ($readyCount === 2) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrency workers did not become ready.');
        }
        usleep(10000);
    } while (true);
    file_put_contents($release, 'go', LOCK_EX);

    $results = [];
    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        if ($exitCode !== 0 || !is_string($stdout)) {
            throw new RuntimeException('An isolated concurrency worker failed.');
        }
        $result = json_decode($stdout, true);
        if (!is_array($result)) {
            throw new RuntimeException('A concurrency worker returned an invalid result.');
        }
        $results[] = $result;
    }

    return $results;
}

try {
    $portSocket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if (!is_resource($portSocket)) {
        throw new RuntimeException('Unable to reserve a local HTTP test port.');
    }
    $address = stream_socket_get_name($portSocket, false);
    fclose($portSocket);
    $portNumber = (int)substr(strrchr((string)$address, ':'), 1);
    $baseUrl = 'http://127.0.0.1:' . $portNumber;
    $serverEnvironment = getenv();
    $serverProcess = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $portNumber, __DIR__ . '/email_change_http_router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
        $serverPipes,
        __DIR__ . '/../../',
        $serverEnvironment
    );
    if (!is_resource($serverProcess)) {
        throw new RuntimeException('Unable to start the local HTTP test server.');
    }
    fclose($serverPipes[0]);
    $ready = false;
    $deadline = microtime(true) + 10;
    do {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $portNumber, $errorCode, $errorMessage, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    emailChangeTestAssert($ready, 'The isolated HTTP server did not start.');

    $blankPassword = password_hash('BlankEmailFixture-123', PASSWORD_DEFAULT);
    $blankUsers = $conn->prepare(
        'INSERT INTO users
            (first_name, last_name, full_name, username, email, password, role,
             registration_status, account_status, auth_token)
         VALUES
            ("Blank", "One", "Blank One", "blank_email_one", "", :password_one, "member", "active", "active", NULL),
            ("Blank", "Two", "Blank Two", "blank_email_two", "", :password_two, "member", "active", "active", NULL)'
    );
    $blankUsers->execute([':password_one' => $blankPassword, ':password_two' => $blankPassword]);
    emailChangeTestAssert((int)$conn->query("SELECT COUNT(*) FROM users WHERE email = ''")->fetchColumn() === 2, 'The migration did not allow multiple blank email values.');

    $member = emailChangeTestAccount($conn, 'member', 'member-old@example.test');
    $historyTables = ['payments', 'membership_subscription_periods', 'bookings', 'attendance', 'daily_pass_transactions'];
    $conn->exec('INSERT INTO payments (member_id, amount) VALUES (' . $member['profile_id'] . ', 10)');
    $conn->exec("INSERT INTO membership_subscription_periods (member_id, starts_on, ends_on) VALUES (" . $member['profile_id'] . ", '2026-01-01', '2026-02-01')");
    $conn->exec("INSERT INTO bookings (member_id, booking_code) VALUES (" . $member['profile_id'] . ", 'TEST-EMAIL-CHANGE')");
    $conn->exec("INSERT INTO attendance (member_id, attendance_date) VALUES (" . $member['profile_id'] . ", '2026-01-01')");
    $conn->exec("INSERT INTO daily_pass_transactions (member_id, transaction_source, payment_status) VALUES (" . $member['profile_id'] . ", 'test', 'paid')");
    $historyBefore = [];
    foreach ($historyTables as $table) {
        $historyBefore[$table] = emailChangeTestCounts($conn, $table, $member['profile_id']);
    }
    $memberChangeEmail = 'member-new@example.test';
    $response = emailChangeTestRequest($member, $memberChangeEmail);
    emailChangeTestAssert($response['status'] === 200 && ($response['data']['success'] ?? false), 'Member email-change code request failed.');
    $memberCode = emailChangeTestCapturedCode($memberChangeEmail);
    $challenge = emailChangeTestChallenge($conn, $member['id']);
    emailChangeTestAssert(
        preg_match('/^[a-f0-9]{64}$/D', (string)$challenge['code_hash']) === 1
            && !hash_equals((string)$challenge['code_hash'], hash('sha256', $memberCode)),
        'A challenge did not store only a non-plaintext HMAC digest.'
    );
    $response = emailChangeTestVerify($member, $memberChangeEmail, $memberCode);
    emailChangeTestAssert($response['status'] === 200 && ($response['data']['success'] ?? false), 'Member email verification failed.');
    $accountRow = $conn->query('SELECT email, auth_token FROM users WHERE id = ' . $member['id'])->fetch(PDO::FETCH_ASSOC);
    emailChangeTestAssert($accountRow['email'] === $memberChangeEmail && $accountRow['auth_token'] === null, 'Member email update or token invalidation failed.');
    $profileRow = $conn->query('SELECT id, email FROM members WHERE user_id = ' . $member['id'])->fetch(PDO::FETCH_ASSOC);
    emailChangeTestAssert((int)$profileRow['id'] === $member['profile_id'] && $profileRow['email'] === 'member-old@example.test', 'Member profile identity or historical email changed.');
    foreach ($historyTables as $table) {
        emailChangeTestAssert(emailChangeTestCounts($conn, $table, $member['profile_id']) === $historyBefore[$table], 'Member history changed during email update.');
    }
    $oldLogin = emailChangeTestLogin('member-old@example.test', $member['password']);
    $newLogin = emailChangeTestLogin($memberChangeEmail, $member['password']);
    $usernameLogin = emailChangeTestLogin($member['username'], $member['password']);
    emailChangeTestAssert($oldLogin['status'] === 401, 'Old email still authenticated after the change.');
    emailChangeTestAssert($newLogin['status'] === 200 && ($newLogin['data']['success'] ?? false), 'New email login failed.');
    emailChangeTestAssert($usernameLogin['status'] === 200 && ($usernameLogin['data']['success'] ?? false), 'Username login changed after email update.');

    $trainer = emailChangeTestAccount($conn, 'trainer', 'trainer-old@example.test');
    $trainerEmail = 'trainer-new@example.test';
    $response = emailChangeTestRequest($trainer, $trainerEmail);
    emailChangeTestAssert($response['status'] === 200, 'Trainer email-change code request failed.');
    $trainerCode = emailChangeTestCapturedCode($trainerEmail);
    $response = emailChangeTestVerify($trainer, $trainerEmail, $trainerCode);
    $trainerRow = $conn->query('SELECT email, auth_token FROM users WHERE id = ' . $trainer['id'])->fetch(PDO::FETCH_ASSOC);
    emailChangeTestAssert($response['status'] === 200 && $trainerRow['email'] === $trainerEmail && $trainerRow['auth_token'] === null, 'Trainer email change failed.');
    $trainerProfile = $conn->query('SELECT id FROM trainers WHERE user_id = ' . $trainer['id'])->fetchColumn();
    emailChangeTestAssert((int)$trainerProfile === $trainer['profile_id'], 'Trainer profile ID changed.');

    foreach ([
        ['member', null, 'member-null@example.test'],
        ['trainer', null, 'trainer-null@example.test'],
        ['member', '', 'member-blank@example.test'],
        ['trainer', '', 'trainer-blank@example.test'],
    ] as [$role, $current, $newEmail]) {
        $account = emailChangeTestAccount($conn, $role, $current);
        $response = emailChangeTestRequest($account, $newEmail);
        emailChangeTestAssert($response['status'] === 200, ucfirst($role) . ' first-time email addition failed.');
        $code = emailChangeTestCapturedCode($newEmail);
        $response = emailChangeTestVerify($account, $newEmail, $code);
        $savedEmail = $conn->query('SELECT email FROM users WHERE id = ' . $account['id'])->fetchColumn();
        emailChangeTestAssert($response['status'] === 200 && $savedEmail === $newEmail, ucfirst($role) . ' null/blank email addition did not persist.');
    }

    $admin = emailChangeTestAccount($conn, 'admin', 'admin@example.test');
    $response = emailChangeTestRequest($admin, 'admin-new@example.test');
    emailChangeTestAssert($response['status'] === 403, 'Admin was not excluded from email changes.');
    $response = emailChangeTestHttp('/email_change.php', ['action' => 'request-code', 'new_email' => 'unauth@example.test', 'current_password' => 'CurrentPassword-123']);
    emailChangeTestAssert($response['status'] === 401, 'Unauthenticated email-change request was accepted.');
    $other = emailChangeTestAccount($conn, 'member', 'other@example.test');
    $response = emailChangeTestHttp('/email_change.php', [
        'action' => 'request-code',
        'new_email' => 'ignored-id@example.test',
        'current_password' => $other['password'],
        'user_id' => $other['id'],
    ], $member['token']);
    emailChangeTestAssert($response['status'] === 400, 'Client-supplied user ID was not rejected.');
    emailChangeTestAssert($conn->query('SELECT email FROM users WHERE id = ' . $other['id'])->fetchColumn() === 'other@example.test', 'Client-supplied user ID affected another account.');

    $validationAccount = emailChangeTestAccount($conn, 'member', 'validation-old@example.test');
    $response = emailChangeTestRequest($validationAccount, 'valid-new@example.test', 'wrong-current-password');
    emailChangeTestAssert($response['status'] === 401, 'Incorrect current password was accepted.');
    $response = emailChangeTestRequest($validationAccount, 'invalid-email');
    emailChangeTestAssert($response['status'] === 400, 'Invalid email format was accepted.');
    $longLocal = str_repeat('a', 64) . '@' . str_repeat('b', 31) . '.' . str_repeat('c', 10);
    $response = emailChangeTestRequest($validationAccount, $longLocal);
    emailChangeTestAssert($response['status'] === 400, 'Email longer than the 100-character column limit was accepted.');
    emailChangeTestAssert(filter_var($longLocal, FILTER_VALIDATE_EMAIL) !== false, 'Over-limit test email fixture is not a valid email address.');

    $owner = emailChangeTestAccount($conn, 'member', 'Taken@example.test');
    $duplicateAccount = emailChangeTestAccount($conn, 'trainer', 'duplicate-old@example.test');
    $response = emailChangeTestRequest($duplicateAccount, 'taken@EXAMPLE.test');
    emailChangeTestAssert(
        $response['status'] === 400
            && !str_contains((string)($response['data']['message'] ?? ''), 'Taken@example.test')
            && !str_contains(strtolower((string)($response['data']['message'] ?? '')), 'already registered'),
        'Case-insensitive duplicate email protection or generic conflict messaging failed.'
    );
    emailChangeTestInsertRegistration($conn, 'pending', 'pending-id@example.test');
    $response = emailChangeTestRequest($duplicateAccount, 'pending-id@example.test');
    emailChangeTestAssert($response['status'] === 400, 'Pending registration email was not protected.');
    emailChangeTestInsertRegistration($conn, 'approved', 'approved-id@example.test');
    $response = emailChangeTestRequest($duplicateAccount, 'approved-id@example.test');
    emailChangeTestAssert($response['status'] === 400, 'Approved registration email was not protected.');
    $orphanEmail = 'orphan-member@example.test';
    $orphanMember = $conn->prepare('INSERT INTO members (user_id, email, account_status, membership_type) VALUES (NULL, :email, "active", "monthly")');
    $orphanMember->execute([':email' => $orphanEmail]);
    $response = emailChangeTestRequest($duplicateAccount, $orphanEmail);
    emailChangeTestAssert($response['status'] === 200, 'A historical unlinked Member email blocked an authenticated account email change.');
    $clearOrphanChallenge = $conn->prepare('DELETE FROM email_change_challenges WHERE user_id = :user_id');
    $clearOrphanChallenge->execute([':user_id' => $duplicateAccount['id']]);

    $rejectedUser = emailChangeTestAccount($conn, 'member', 'rejected-user@example.test');
    $reject = $conn->prepare("UPDATE users SET registration_status = 'rejected' WHERE id = :id");
    $reject->execute([':id' => $rejectedUser['id']]);
    emailChangeTestInsertRegistration($conn, 'rejected', 'released-id@example.test');
    $response = emailChangeTestRequest($duplicateAccount, 'released-id@example.test');
    emailChangeTestAssert($response['status'] === 200, 'Rejected registration identifier was not released.');

    $wrongTarget = emailChangeTestAccount($conn, 'member', 'wrong-target-old@example.test');
    $wrongTargetEmail = 'wrong-target-new@example.test';
    $response = emailChangeTestRequest($wrongTarget, $wrongTargetEmail);
    $wrongTargetCode = emailChangeTestCapturedCode($wrongTargetEmail);
    $response = emailChangeTestVerify($wrongTarget, 'different-target@example.test', $wrongTargetCode);
    emailChangeTestAssert($response['status'] === 400 && (int)emailChangeTestChallenge($conn, $wrongTarget['id'])['attempt_count'] === 1, 'Code was not bound to its proposed email.');
    $response = emailChangeTestVerify($wrongTarget, $wrongTargetEmail, $wrongTargetCode);
    emailChangeTestAssert($response['status'] === 200, 'A code could not be used for its originally bound address.');

    $attemptAccount = emailChangeTestAccount($conn, 'trainer', 'attempt-old@example.test');
    $attemptEmail = 'attempt-new@example.test';
    emailChangeTestRequest($attemptAccount, $attemptEmail);
    $attemptCode = emailChangeTestCapturedCode($attemptEmail);
    $wrongCode = $attemptCode === '999999' ? '000000' : '999999';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $response = emailChangeTestVerify($attemptAccount, $attemptEmail, $wrongCode);
        emailChangeTestAssert($response['status'] === 400, 'An incorrect verification code was accepted.');
    }
    emailChangeTestAssert((int)emailChangeTestChallenge($conn, $attemptAccount['id'])['attempt_count'] === 5, 'Five failed verification attempts were not recorded.');
    $response = emailChangeTestVerify($attemptAccount, $attemptEmail, $attemptCode);
    emailChangeTestAssert($response['status'] === 400, 'A challenge remained usable after five failed attempts.');

    $formatAccount = emailChangeTestAccount($conn, 'member', 'format-old@example.test');
    $formatEmail = 'format-new@example.test';
    emailChangeTestRequest($formatAccount, $formatEmail);
    $formatCode = emailChangeTestCapturedCode($formatEmail);
    $response = emailChangeTestVerify($formatAccount, $formatEmail, substr($formatCode, 1));
    emailChangeTestAssert(
        $response['status'] === 400 && (int)emailChangeTestChallenge($conn, $formatAccount['id'])['attempt_count'] === 1,
        'A malformed verification attempt was accepted or not counted.'
    );
    $response = emailChangeTestVerify($formatAccount, $formatEmail, $formatCode);
    emailChangeTestAssert($response['status'] === 200, 'A valid six-digit code was rejected.');
    $response = emailChangeTestVerify($formatAccount, $formatEmail, $formatCode);
    emailChangeTestAssert($response['status'] === 401, 'A consumed code could be reused with its invalidated token.');

    $expiryAccount = emailChangeTestAccount($conn, 'member', 'expiry-old@example.test');
    $expiryEmail = 'expiry-new@example.test';
    emailChangeTestRequest($expiryAccount, $expiryEmail);
    $expiryCode = emailChangeTestCapturedCode($expiryEmail);
    $expire = $conn->prepare('UPDATE email_change_challenges SET expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND) WHERE user_id = :user_id');
    $expire->execute([':user_id' => $expiryAccount['id']]);
    $response = emailChangeTestVerify($expiryAccount, $expiryEmail, $expiryCode);
    emailChangeTestAssert($response['status'] === 400, 'An expired code was accepted.');

    $cooldownAccount = emailChangeTestAccount($conn, 'trainer', 'cooldown-old@example.test');
    $cooldownEmail = 'cooldown-new@example.test';
    emailChangeTestRequest($cooldownAccount, $cooldownEmail);
    $response = emailChangeTestRequest($cooldownAccount, $cooldownEmail);
    emailChangeTestAssert($response['status'] === 429, 'The 60-second resend cooldown was not enforced.');
    $ageChallenge = $conn->prepare('UPDATE email_change_challenges SET last_sent_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 61 SECOND) WHERE user_id = :user_id');
    $ageChallenge->execute([':user_id' => $cooldownAccount['id']]);
    $response = emailChangeTestRequest($cooldownAccount, $cooldownEmail);
    emailChangeTestAssert($response['status'] === 200, 'Resending after cooldown did not work.');

    $rateAccount = emailChangeTestAccount($conn, 'member', 'rate-old@example.test');
    $accountScopeHash = emailChangeDigest('rate:request:request-account', (string)$rateAccount['id']);
    $rateInsert = $conn->prepare(
        'INSERT INTO email_change_rate_limits (scope_type, scope_hash, window_started_at, request_count)
         VALUES (:scope_type, :scope_hash, DATE_FORMAT(CURRENT_TIMESTAMP, "%Y-%m-%d %H:00:00"), :request_count)'
    );
    $rateInsert->execute([':scope_type' => 'request-account', ':scope_hash' => $accountScopeHash, ':request_count' => 5]);
    $response = emailChangeTestRequest($rateAccount, 'rate-limited@example.test');
    emailChangeTestAssert($response['status'] === 429, 'Account rate limit was not enforced.');
    $ipRateAccount = emailChangeTestAccount($conn, 'trainer', 'ip-rate-old@example.test');
    $ip = '10.22.33.44';
    $ipScopeHash = emailChangeDigest('rate:request:request-ip', $ip);
    $rateInsert->execute([':scope_type' => 'request-ip', ':scope_hash' => $ipScopeHash, ':request_count' => 20]);
    $response = emailChangeTestRequest($ipRateAccount, 'ip-rate-limited@example.test', null, null, $ip);
    emailChangeTestAssert($response['status'] === 429, 'IP request rate limit was not enforced.');
    $targetRateAccount = emailChangeTestAccount($conn, 'trainer', 'target-rate-old@example.test');
    $targetEmail = 'target-rate-limited@example.test';
    $targetScopeHash = emailChangeDigest('rate:request:request-email', $targetEmail);
    $rateInsert->execute([':scope_type' => 'request-email', ':scope_hash' => $targetScopeHash, ':request_count' => 5]);
    $response = emailChangeTestRequest($targetRateAccount, $targetEmail);
    emailChangeTestAssert($response['status'] === 429, 'Target email request rate limit was not enforced.');

    $verifyRateAccount = emailChangeTestAccount($conn, 'member', 'verify-rate-old@example.test');
    $verifyRateEmail = 'verify-rate-new@example.test';
    emailChangeTestRequest($verifyRateAccount, $verifyRateEmail);
    $verifyRateCode = emailChangeTestCapturedCode($verifyRateEmail);
    $verifyScopeHash = emailChangeDigest('rate:verify:verify-account', (string)$verifyRateAccount['id']);
    $rateInsert = $conn->prepare(
        'INSERT INTO email_change_rate_limits (scope_type, scope_hash, window_started_at, request_count)
         VALUES ("verify-account", :scope_hash, DATE_FORMAT(CURRENT_TIMESTAMP, "%Y-%m-%d %H:00:00"), 30)'
    );
    $rateInsert->execute([':scope_hash' => $verifyScopeHash]);
    $response = emailChangeTestVerify($verifyRateAccount, $verifyRateEmail, $verifyRateCode);
    emailChangeTestAssert($response['status'] === 429, 'Verification rate limit was not enforced.');

    $mailFailAccount = emailChangeTestAccount($conn, 'trainer', 'mail-fail-old@example.test');
    $mailFailEmail = 'mail-fail-new@example.test';
    $response = emailChangeTestRequest($mailFailAccount, $mailFailEmail, null, 'verification');
    $mailFailCode = emailChangeTestCapturedCode($mailFailEmail);
    $mailFailChallenge = emailChangeTestChallenge($conn, $mailFailAccount['id']);
    emailChangeTestAssert(
        $response['status'] === 503 && $mailFailChallenge['consumed_at'] !== null,
        'A mail failure did not invalidate its challenge.'
    );
    $response = emailChangeTestVerify($mailFailAccount, $mailFailEmail, $mailFailCode);
    emailChangeTestAssert($response['status'] === 400, 'A challenge from a failed delivery remained usable.');

    $notificationAccount = emailChangeTestAccount($conn, 'member', 'notification-old@example.test');
    $notificationEmail = 'notification-new@example.test';
    emailChangeTestRequest($notificationAccount, $notificationEmail);
    $notificationCode = emailChangeTestCapturedCode($notificationEmail);
    $response = emailChangeTestVerify($notificationAccount, $notificationEmail, $notificationCode, null, 'previous-address-notification');
    $notificationRow = $conn->query('SELECT email, auth_token FROM users WHERE id = ' . $notificationAccount['id'])->fetch(PDO::FETCH_ASSOC);
    emailChangeTestAssert($response['status'] === 200 && $notificationRow['email'] === $notificationEmail && $notificationRow['auth_token'] === null, 'Old-address notification failure rolled back the email change.');

    $concurrentAccount = emailChangeTestAccount($conn, 'trainer', 'concurrent-old@example.test');
    $concurrentEmail = 'concurrent-new@example.test';
    emailChangeTestRequest($concurrentAccount, $concurrentEmail);
    $concurrentCode = emailChangeTestCapturedCode($concurrentEmail);
    $concurrentResults = emailChangeTestRunConcurrentWorkers($concurrentAccount, $concurrentEmail, $concurrentCode, $privateDirectory);
    $successCount = count(array_filter($concurrentResults, static fn(array $result): bool => $result['success'] === true));
    emailChangeTestAssert($successCount === 1, 'Concurrent verification did not produce exactly one successful update.');

    $blankEmails = $conn->query("SELECT COUNT(*) FROM users WHERE email = ''")->fetchColumn();
    emailChangeTestAssert((int)$blankEmails === 2, 'The migration did not allow multiple blank email values.');
    $rejectedIdentity = emailChangeTestAccount($conn, 'member', 'rejected-account@example.test');
    $conn->prepare("UPDATE users SET registration_status = 'rejected' WHERE id = :id")->execute([':id' => $rejectedIdentity['id']]);
    $releasedIndex = emailChangeTestAccount($conn, 'trainer', 'released-index@example.test');
    $conn->prepare("UPDATE users SET email = 'rejected-account@example.test' WHERE id = :id")->execute([':id' => $releasedIndex['id']]);
    emailChangeTestAssert(true, 'Rejected account email identifier release failed.');

    $mail = emailChangeTestLatestMail('verification', $memberChangeEmail);
    emailChangeTestAssert(
        str_contains($mail['subject'], 'email verification')
            && str_contains($mail['text_body'], '10 minutes')
            && preg_match('/^\d{6}$/D', (string)$mail['code']) === 1,
        'Verification email formatting or expiration notice was incorrect.'
    );

    echo "Email-change isolated HTTP tests passed ({$checks} assertions).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Email-change HTTP tests failed: ' . $error->getMessage() . PHP_EOL);
    $GLOBALS['email_change_test_exit_code'] = 1;
} finally {
    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }
}

if (($GLOBALS['email_change_test_exit_code'] ?? 0) !== 0) {
    exit(1);
}
