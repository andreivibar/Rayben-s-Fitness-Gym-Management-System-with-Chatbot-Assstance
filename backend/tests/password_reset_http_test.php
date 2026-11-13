<?php

$databaseName = getenv('PASSWORD_RESET_TEST_DB') ?: '';
$baseUrl = rtrim((string)(getenv('PASSWORD_RESET_TEST_URL') ?: 'http://127.0.0.1:8769'), '/');
$captureFile = getenv('PASSWORD_RESET_TEST_MAIL_CAPTURE') ?: '';
$privateDirectory = getenv('PASSWORD_RESET_TEST_PRIVATE_DIR') ?: '';
$host = getenv('PASSWORD_RESET_TEST_HOST') ?: '127.0.0.1';
$port = getenv('PASSWORD_RESET_TEST_PORT') ?: '3306';
$username = getenv('PASSWORD_RESET_TEST_USER') ?: 'root';
$password = getenv('PASSWORD_RESET_TEST_PASSWORD') ?: '';
$hmacKey = (string)getenv('PASSWORD_RESET_HMAC_KEY');

if (!preg_match('/^gym_password_reset_test_[a-z0-9_]+$/D', $databaseName)
    || $captureFile === '' || $privateDirectory === '' || strlen($hmacKey) < 32) {
    fwrite(STDERR, "Password reset HTTP test environment is incomplete.\n");
    exit(2);
}

$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$conn->exec("SET time_zone = '+08:00'");
$checks = 0;

function resetTestAssert(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function resetTestHttp(string $path, array $payload, string $testIp): array
{
    global $baseUrl;
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Test-Client-IP: {$testIp}\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $body = file_get_contents($baseUrl . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
            break;
        }
    }

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'data' => is_string($body) ? json_decode($body, true) : null,
    ];
}

function resetTestRequest(string $email, string $ip): array
{
    return resetTestHttp('/password_reset.php', ['action' => 'request-code', 'email' => $email], $ip);
}

function resetTestCaptureMessages(): array
{
    global $captureFile;
    if (!is_file($captureFile)) {
        return [];
    }

    $messages = [];
    foreach (file($captureFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $message = json_decode($line, true);
        if (is_array($message)) {
            $messages[] = $message;
        }
    }

    return $messages;
}

function resetTestLastCode(string $email): string
{
    $messages = resetTestCaptureMessages();
    for ($index = count($messages) - 1; $index >= 0; $index--) {
        if (strtolower((string)$messages[$index]['recipient']) === strtolower($email)) {
            return (string)$messages[$index]['code'];
        }
    }
    throw new RuntimeException('Expected fake SMTP message was not captured.');
}

function resetTestCreateUser(PDO $conn, string $role, string $email, string $status = 'active'): int
{
    $suffix = bin2hex(random_bytes(4));
    $passwordHash = password_hash('Before-Reset-123', PASSWORD_DEFAULT);
    $user = $conn->prepare(
        'INSERT INTO users
            (first_name, middle_name, last_name, full_name, username, email, password, role,
             registration_status, account_status, auth_token)
         VALUES
            (:first_name, NULL, :last_name, :full_name, :username, :email, :password, :role,
             :registration_status, :account_status, :auth_token)'
    );
    $user->execute([
        ':first_name' => ucfirst($role),
        ':last_name' => 'Fixture',
        ':full_name' => ucfirst($role) . ' Fixture',
        ':username' => $role . '_' . $suffix,
        ':email' => $email,
        ':password' => $passwordHash,
        ':role' => $role,
        ':registration_status' => 'active',
        ':account_status' => 'active',
        ':auth_token' => 'fixture-token-' . $suffix,
    ]);
    $userId = (int)$conn->lastInsertId();

    if ($role === 'member') {
        $member = $conn->prepare(
            'INSERT INTO members (user_id, email, account_status, membership_type)
             VALUES (:user_id, :email, :status, "monthly")'
        );
        $member->execute([
            ':user_id' => $userId,
            ':email' => $email,
            ':status' => $status,
        ]);
    } elseif ($role === 'trainer') {
        $trainer = $conn->prepare('INSERT INTO trainers (user_id, status) VALUES (:user_id, :status)');
        $trainer->execute([
            ':user_id' => $userId,
            ':status' => $status,
        ]);
    }

    return $userId;
}

function resetTestVerify(string $email, string $code, string $ip): array
{
    return resetTestHttp('/password_reset.php', [
        'action' => 'verify-code',
        'email' => $email,
        'code' => $code,
    ], $ip);
}

function resetTestComplete(string $email, string $token, string $newPassword, string $ip): array
{
    return resetTestHttp('/password_reset.php', [
        'action' => 'complete-reset',
        'email' => $email,
        'reset_token' => $token,
        'new_password' => $newPassword,
        'confirm_password' => $newPassword,
    ], $ip);
}

function resetTestRunConcurrentWorkers(string $mode, string $email, array $payload): array
{
    global $privateDirectory, $databaseName, $host, $port, $username, $password, $hmacKey;
    $caseDirectory = $privateDirectory . DIRECTORY_SEPARATOR . 'workers_' . bin2hex(random_bytes(6));
    if (!mkdir($caseDirectory, 0700, true)) {
        throw new RuntimeException('Unable to prepare concurrency test workspace.');
    }
    $readyDirectory = $caseDirectory . DIRECTORY_SEPARATOR . 'ready';
    if (!mkdir($readyDirectory, 0700)) {
        throw new RuntimeException('Unable to prepare worker readiness markers.');
    }
    $payloadFile = $caseDirectory . DIRECTORY_SEPARATOR . 'payload.json';
    $releaseFile = $caseDirectory . DIRECTORY_SEPARATOR . 'release';
    if (file_put_contents($payloadFile, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Unable to prepare worker payload.');
    }

    $environment = array_merge(getenv(), [
        'PASSWORD_RESET_TEST_DB' => $databaseName,
        'PASSWORD_RESET_TEST_HOST' => $host,
        'PASSWORD_RESET_TEST_PORT' => $port,
        'PASSWORD_RESET_TEST_USER' => $username,
        'PASSWORD_RESET_TEST_PASSWORD' => $password,
        'PASSWORD_RESET_HMAC_KEY' => $hmacKey,
        'PASSWORD_RESET_TEST_READY_DIR' => $readyDirectory,
        'PASSWORD_RESET_TEST_RELEASE_FILE' => $releaseFile,
        'PASSWORD_RESET_TEST_WORKER_PAYLOAD' => $payloadFile,
    ]);
    $workerFile = __DIR__ . '/password_reset_concurrency_worker.php';
    $processes = [];
    for ($index = 0; $index < 2; $index++) {
        $command = [PHP_BINARY, $workerFile, $mode, $email];
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start a concurrency test worker.');
        }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'pipes' => $pipes];
    }

    $deadline = microtime(true) + 10;
    do {
        $readyWorkers = glob($readyDirectory . DIRECTORY_SEPARATOR . '*') ?: [];
        if (count($readyWorkers) === 2) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    if (count($readyWorkers) !== 2) {
        file_put_contents($releaseFile, 'release');
        foreach ($processes as $worker) {
            stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
            if ($stderr !== '') {
                throw new RuntimeException('Concurrent reset worker failed: ' . trim($stderr));
            }
        }
        throw new RuntimeException('Concurrent reset workers did not reach the barrier.');
    }

    file_put_contents($releaseFile, 'release');
    $results = [];
    foreach ($processes as $worker) {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        if ($exitCode !== 0 || $stderr !== '') {
            throw new RuntimeException('A concurrent reset worker failed.');
        }
        $result = json_decode($stdout, true);
        if (!is_array($result)) {
            throw new RuntimeException('A concurrent reset worker returned invalid output.');
        }
        $results[] = (bool)($result['success'] ?? false);
    }

    return $results;
}

try {
    $adminSeed = $conn->prepare(
        'INSERT INTO users
            (id, first_name, last_name, full_name, username, email, password, role,
             registration_status, account_status, auth_token)
         VALUES
            (1, "Admin", "Fixture", "Admin Fixture", "admin_fixture", "admin.fixture@example.test",
             :password, "admin", "active", "active", "admin-original-token")'
    );
    $adminSeed->execute([':password' => password_hash('Admin-Password-123', PASSWORD_DEFAULT)]);

    $memberId = resetTestCreateUser($conn, 'member', 'member.fixture@example.test');
    $trainerId = resetTestCreateUser($conn, 'trainer', 'trainer.fixture@example.test');
    $attemptId = resetTestCreateUser($conn, 'member', 'attempt.fixture@example.test');
    resetTestCreateUser($conn, 'member', 'expiry.fixture@example.test');
    resetTestCreateUser($conn, 'member', 'race-verify.fixture@example.test');
    resetTestCreateUser($conn, 'trainer', 'race-reset.fixture@example.test');
    resetTestCreateUser($conn, 'member', 'inactive-member.fixture@example.test', 'deactivated');
    resetTestCreateUser($conn, 'trainer', 'inactive-trainer.fixture@example.test', 'inactive');

    $registeredResponse = resetTestRequest('member.fixture@example.test', '192.0.2.10');
    $unknownResponse = resetTestRequest('unknown.fixture@example.test', '192.0.2.11');
    resetTestAssert($registeredResponse['status'] === 200 && $unknownResponse['status'] === 200, 'Request-code status must be generic.');
    resetTestAssert($registeredResponse['body'] === $unknownResponse['body'], 'Registered and unregistered responses differ.');
    resetTestAssert(
        ($registeredResponse['data']['message'] ?? '') === 'If an eligible account matches that email address, password reset instructions will be sent.',
        'The request-code response message was unexpected.'
    );

    $capturedMessages = resetTestCaptureMessages();
    resetTestAssert(count($capturedMessages) === 1, 'Fake SMTP should capture one eligible Member message.');
    resetTestAssert(
        $capturedMessages[0]['recipient'] === 'member.fixture@example.test'
            && preg_match('/^\d{6}$/D', $capturedMessages[0]['code']) === 1,
        'Fake SMTP recipient or code format was invalid.'
    );
    resetTestAssert(
        strpos($registeredResponse['body'], (string)$capturedMessages[0]['code']) === false,
        'Verification code appeared in the API response.'
    );

    $memberCode = resetTestLastCode('member.fixture@example.test');
    $challengeQuery = $conn->prepare(
        'SELECT code_hash, attempt_count, consumed_at
         FROM password_reset_challenges WHERE user_id = :user_id ORDER BY id DESC LIMIT 1'
    );
    $challengeQuery->execute([':user_id' => $memberId]);
    $memberChallenge = $challengeQuery->fetch(PDO::FETCH_ASSOC);
    resetTestAssert(
        is_array($memberChallenge)
            && hash_equals(
                hash_hmac('sha256', 'verification-code:' . $memberCode, $hmacKey),
                (string)$memberChallenge['code_hash']
            )
            && !hash_equals($memberCode, (string)$memberChallenge['code_hash']),
        'Verification code was not stored only as an HMAC hash.'
    );

    $resendResponse = resetTestRequest('member.fixture@example.test', '192.0.2.10');
    resetTestAssert($resendResponse['body'] === $registeredResponse['body'], 'Resend response differed during cooldown.');
    resetTestAssert(count(resetTestCaptureMessages()) === 1, 'A resend was delivered before the 60-second cooldown.');

    $adminBefore = $conn->query("SELECT password, auth_token FROM users WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $adminRequest = resetTestRequest('admin.fixture@example.test', '192.0.2.12');
    $adminVerify = resetTestVerify('admin.fixture@example.test', '123456', '192.0.2.12');
    $adminAfter = $conn->query("SELECT password, auth_token FROM users WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    resetTestAssert($adminRequest['body'] === $registeredResponse['body'], 'Admin request response revealed account eligibility.');
    resetTestAssert(($adminVerify['data']['success'] ?? true) === false, 'Admin verification unexpectedly succeeded.');
    resetTestAssert($adminBefore === $adminAfter, 'Admin password or session changed.');
    resetTestAssert(count(resetTestCaptureMessages()) === 1, 'An Admin reset email was sent.');

    $trainerLogin = resetTestHttp('/login.php', [
        'username' => 'trainer.fixture@example.test',
        'password' => 'Before-Reset-123',
    ], '192.0.2.13');
    resetTestAssert(($trainerLogin['data']['success'] ?? false) === true, 'Existing Trainer login regression failed.');

    $attemptResponse = resetTestRequest('attempt.fixture@example.test', '192.0.2.14');
    $wrongAttemptCode = resetTestLastCode('attempt.fixture@example.test');
    $wrongCode = $wrongAttemptCode === '999999' ? '999998' : '999999';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $wrongResponse = resetTestVerify('attempt.fixture@example.test', $wrongCode, '192.0.2.14');
        resetTestAssert(($wrongResponse['data']['success'] ?? true) === false, 'An incorrect verification code was accepted.');
    }
    $attemptChallengeQuery = $conn->prepare(
        'SELECT attempt_count FROM password_reset_challenges WHERE user_id = :user_id ORDER BY id DESC LIMIT 1'
    );
    $attemptChallengeQuery->execute([':user_id' => $attemptId]);
    resetTestAssert((int)$attemptChallengeQuery->fetchColumn() === 5, 'Verification attempts were not capped at five.');
    $correctAfterLockout = resetTestVerify('attempt.fixture@example.test', $wrongAttemptCode, '192.0.2.14');
    resetTestAssert(($correctAfterLockout['data']['success'] ?? true) === false, 'A code was accepted after five failed attempts.');

    resetTestRequest('expiry.fixture@example.test', '192.0.2.15');
    $expiryCode = resetTestLastCode('expiry.fixture@example.test');
    $conn->exec(
        "UPDATE password_reset_challenges
         SET expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND)
         WHERE user_id = (SELECT id FROM users WHERE email = 'expiry.fixture@example.test')"
    );
    $expiredResponse = resetTestVerify('expiry.fixture@example.test', $expiryCode, '192.0.2.15');
    resetTestAssert(($expiredResponse['data']['success'] ?? true) === false, 'An expired code was accepted.');

    for ($request = 0; $request < 6; $request++) {
        $rateResponse = resetTestRequest('rate.fixture@example.test', '192.0.2.' . (20 + $request));
        resetTestAssert($rateResponse['body'] === $registeredResponse['body'], 'Rate-limited request did not retain a generic response.');
    }
    $emailRateHash = hash_hmac('sha256', 'rate:request:email:rate.fixture@example.test', $hmacKey);
    $emailRateStmt = $conn->prepare(
        'SELECT request_count FROM password_reset_rate_limits
         WHERE scope_type = "email" AND scope_hash = :scope_hash
         ORDER BY window_started_at DESC LIMIT 1'
    );
    $emailRateStmt->execute([':scope_hash' => $emailRateHash]);
    resetTestAssert((int)$emailRateStmt->fetchColumn() === 6, 'Email rate-limit bucket did not count the six requests.');
    resetTestAssert(
        count(array_filter(
            resetTestCaptureMessages(),
            static fn(array $message): bool => $message['recipient'] === 'rate.fixture@example.test'
        )) === 0,
        'Unregistered rate-limit test address received an email.'
    );

    $memberCode = resetTestLastCode('member.fixture@example.test');
    $memberVerify = resetTestVerify('member.fixture@example.test', $memberCode, '192.0.2.10');
    $resetToken = (string)($memberVerify['data']['reset_token'] ?? '');
    resetTestAssert(
        ($memberVerify['data']['success'] ?? false) === true && preg_match('/^[a-f0-9]{64}$/D', $resetToken) === 1,
        'Valid Member verification did not issue a high-entropy reset token.'
    );
    $memberTokenHashStmt = $conn->prepare(
        'SELECT reset_token_hash, consumed_at
         FROM password_reset_challenges WHERE user_id = :user_id ORDER BY id DESC LIMIT 1'
    );
    $memberTokenHashStmt->execute([':user_id' => $memberId]);
    $memberTokenStored = $memberTokenHashStmt->fetch(PDO::FETCH_ASSOC);
    resetTestAssert(
        hash_equals(
            hash_hmac('sha256', 'reset-token:' . $resetToken, $hmacKey),
            (string)$memberTokenStored['reset_token_hash']
        ) && $memberTokenStored['consumed_at'] !== null,
        'Reset token was not stored hashed or the code was not consumed.'
    );
    $codeReplay = resetTestVerify('member.fixture@example.test', $memberCode, '192.0.2.10');
    resetTestAssert(($codeReplay['data']['success'] ?? true) === false, 'A verification code was reusable.');

    $newMemberPassword = 'Member-Reset-123';
    $memberComplete = resetTestComplete('member.fixture@example.test', $resetToken, $newMemberPassword, '192.0.2.10');
    resetTestAssert(($memberComplete['data']['success'] ?? false) === true, 'Member password reset did not complete.');
    $memberRecordStmt = $conn->prepare('SELECT password, auth_token FROM users WHERE id = :id');
    $memberRecordStmt->execute([':id' => $memberId]);
    $memberRecord = $memberRecordStmt->fetch(PDO::FETCH_ASSOC);
    resetTestAssert(
        password_verify($newMemberPassword, (string)$memberRecord['password'])
            && $memberRecord['auth_token'] === null,
        'Member password hash or auth-token invalidation was incorrect.'
    );
    $memberTokenReplay = resetTestComplete('member.fixture@example.test', $resetToken, $newMemberPassword, '192.0.2.10');
    resetTestAssert(($memberTokenReplay['data']['success'] ?? true) === false, 'A reset token was reusable.');
    $memberLogin = resetTestHttp('/login.php', [
        'username' => 'member.fixture@example.test',
        'password' => $newMemberPassword,
    ], '192.0.2.16');
    resetTestAssert(($memberLogin['data']['success'] ?? false) === true, 'Existing login flow rejected the reset Member password.');

    resetTestRequest('trainer.fixture@example.test', '192.0.2.17');
    $trainerCode = resetTestLastCode('trainer.fixture@example.test');
    $trainerVerify = resetTestVerify('trainer.fixture@example.test', $trainerCode, '192.0.2.17');
    $trainerToken = (string)($trainerVerify['data']['reset_token'] ?? '');
    $newTrainerPassword = 'Trainer-Reset-123';
    $trainerComplete = resetTestComplete('trainer.fixture@example.test', $trainerToken, $newTrainerPassword, '192.0.2.17');
    resetTestAssert(($trainerComplete['data']['success'] ?? false) === true, 'Trainer password reset did not complete.');
    $trainerRecordStmt = $conn->prepare('SELECT password, auth_token FROM users WHERE id = :id');
    $trainerRecordStmt->execute([':id' => $trainerId]);
    $trainerRecord = $trainerRecordStmt->fetch(PDO::FETCH_ASSOC);
    resetTestAssert(
        password_verify($newTrainerPassword, (string)$trainerRecord['password'])
            && $trainerRecord['auth_token'] === null,
        'Trainer password hash or auth-token invalidation was incorrect.'
    );
    $trainerPostResetLogin = resetTestHttp('/login.php', [
        'username' => 'trainer.fixture@example.test',
        'password' => $newTrainerPassword,
    ], '192.0.2.18');
    resetTestAssert(($trainerPostResetLogin['data']['success'] ?? false) === true, 'Existing login flow rejected the reset Trainer password.');

    resetTestRequest('race-verify.fixture@example.test', '192.0.2.19');
    $raceVerifyCode = resetTestLastCode('race-verify.fixture@example.test');
    $verifyRaceResults = resetTestRunConcurrentWorkers(
        'verify',
        'race-verify.fixture@example.test',
        ['code' => $raceVerifyCode]
    );
    resetTestAssert(count(array_filter($verifyRaceResults)) === 1, 'Concurrent verification did not permit exactly one success.');

    resetTestRequest('race-reset.fixture@example.test', '192.0.2.20');
    $raceResetCode = resetTestLastCode('race-reset.fixture@example.test');
    $raceResetVerification = resetTestVerify('race-reset.fixture@example.test', $raceResetCode, '192.0.2.20');
    $raceResetToken = (string)($raceResetVerification['data']['reset_token'] ?? '');
    $racePassword = 'Race-Reset-123';
    $resetRaceResults = resetTestRunConcurrentWorkers(
        'complete',
        'race-reset.fixture@example.test',
        ['reset_token' => $raceResetToken, 'new_password' => $racePassword]
    );
    resetTestAssert(count(array_filter($resetRaceResults)) === 1, 'Concurrent reset did not permit exactly one success.');

    $ipLimitResponses = [];
    for ($request = 0; $request < 21; $request++) {
        $ipLimitResponses[] = resetTestRequest(
            'ip-' . $request . '.fixture@example.test',
            '198.51.100.40'
        );
    }
    resetTestAssert(
        count(array_filter($ipLimitResponses, static fn(array $response): bool => $response['body'] !== $registeredResponse['body'])) === 0,
        'IP rate-limited responses were not generic.'
    );
    $ipRateHash = hash_hmac('sha256', 'rate:request:ip:198.51.100.40', $hmacKey);
    $ipRateStmt = $conn->prepare(
        'SELECT request_count FROM password_reset_rate_limits
         WHERE scope_type = "ip" AND scope_hash = :scope_hash
         ORDER BY window_started_at DESC LIMIT 1'
    );
    $ipRateStmt->execute([':scope_hash' => $ipRateHash]);
    resetTestAssert((int)$ipRateStmt->fetchColumn() === 21, 'IP rate-limit bucket did not count the request burst.');

    $sensitiveValues = [
        $memberCode,
        $resetToken,
        $trainerCode,
        $trainerToken,
        $raceVerifyCode,
        $raceResetToken,
        $hmacKey,
    ];
    foreach ($sensitiveValues as $secret) {
        resetTestAssert($secret !== '' && !str_contains($registeredResponse['body'], $secret), 'A secret appeared in an API response.');
    }

    echo json_encode([
        'success' => true,
        'assertions' => $checks,
        'registered_unknown_response_equivalence' => true,
        'member_and_trainer_reset_login' => true,
        'admin_reset_rejected' => true,
        'code_expiry_attempts_cooldown_rate_limits' => true,
        'concurrent_verify_single_success' => true,
        'concurrent_reset_single_success' => true,
        'fake_mail_messages' => count(resetTestCaptureMessages()),
        'secrets_printed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Password reset HTTP test failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
