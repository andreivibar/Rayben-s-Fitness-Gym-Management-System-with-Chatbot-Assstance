<?php

$databaseName = getenv('FINAL_DEFECT_TEST_DB') ?: '';
$baseUrl = rtrim(getenv('FINAL_DEFECT_TEST_URL') ?: '', '/');
$concurrentUrl = rtrim(getenv('FINAL_DEFECT_TEST_CONCURRENT_URL') ?: '', '/');
$host = getenv('FINAL_DEFECT_TEST_HOST') ?: '127.0.0.1';
$port = getenv('FINAL_DEFECT_TEST_PORT') ?: '3306';
$username = getenv('FINAL_DEFECT_TEST_USER') ?: 'root';
$password = getenv('FINAL_DEFECT_TEST_PASSWORD') ?: '';
if (!preg_match('/^gym_final_defect_test_[a-z0-9_]+$/D', $databaseName)
    || $baseUrl === ''
    || $concurrentUrl === '') {
    fwrite(STDERR, "Final defect HTTP test environment is incomplete.\n");
    exit(2);
}

$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $databaseName
    || (int)$conn->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    throw new RuntimeException('Tests require the explicitly named, empty disposable schema.');
}

$assertions = 0;
$requestCount = 0;
$testPassword = 'Safe-test-password-2026!';

function finalDefectAssert(bool $condition, string $description): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    $assertions++;
    echo 'PASS: ' . $description . PHP_EOL;
}

function finalDefectHttp(string $method, string $path, ?array $payload = null, ?string $token = null): array
{
    global $baseUrl, $requestCount;
    $requestCount++;
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'X-Auth-Token: ' . $token;
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $body = file_get_contents($baseUrl . $path, false, $context);
    if (!is_string($body)) {
        throw new RuntimeException('Unable to call the local disposable-schema API server.');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('The local API returned a non-JSON response: ' . $body);
    }
    echo "HTTP {$method} {$path}: {$status} " . json_encode($decoded, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return ['status' => $status, 'body' => $decoded];
}

function finalDefectConcurrentHttp(array $requests, ?callable $afterSend = null): array
{
    global $requestCount;
    $connections = [];

    foreach ($requests as $request) {
        $url = parse_url($request['url']);
        if (!is_array($url) || !isset($url['host'], $url['path'])) {
            throw new RuntimeException('Invalid URL in concurrent HTTP test.');
        }
        $port = (int)($url['port'] ?? 80);
        $errno = 0;
        $error = '';
        $socket = stream_socket_client(
            "tcp://{$url['host']}:{$port}",
            $errno,
            $error,
            5,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new RuntimeException("Unable to open concurrent HTTP connection: {$error}");
        }
        stream_set_timeout($socket, 15);
        $path = $url['path'] . (isset($url['query']) ? '?' . $url['query'] : '');
        $body = json_encode($request['payload'], JSON_THROW_ON_ERROR);
        $rawRequest = "POST {$path} HTTP/1.1\r\n"
            . "Host: {$url['host']}:{$port}\r\n"
            . "Content-Type: application/json\r\n"
            . "X-Auth-Token: {$request['token']}\r\n"
            . "Authorization: Bearer {$request['token']}\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
        $written = 0;
        while ($written < strlen($rawRequest)) {
            $count = fwrite($socket, substr($rawRequest, $written));
            if ($count === false || $count === 0) {
                fclose($socket);
                throw new RuntimeException('Unable to write concurrent HTTP request.');
            }
            $written += $count;
        }
        $connections[] = ['socket' => $socket, 'response' => ''];
        $requestCount++;
    }

    if ($afterSend !== null) {
        $afterSend();
    }

    $open = array_column($connections, 'socket');
    while ($open !== []) {
        $read = $open;
        $write = null;
        $except = null;
        $selected = stream_select($read, $write, $except, 15);
        if ($selected === false || $selected === 0) {
            foreach ($open as $socket) {
                fclose($socket);
            }
            throw new RuntimeException('Timed out waiting for concurrent HTTP responses.');
        }
        foreach ($read as $readySocket) {
            foreach ($connections as &$connection) {
                if ($connection['socket'] !== $readySocket) {
                    continue;
                }
                $chunk = fread($readySocket, 8192);
                if (is_string($chunk)) {
                    $connection['response'] .= $chunk;
                }
                if (feof($readySocket)) {
                    fclose($readySocket);
                    $open = array_values(array_filter(
                        $open,
                        static fn($socket): bool => $socket !== $readySocket
                    ));
                }
                break;
            }
            unset($connection);
        }
    }

    $results = [];
    foreach ($connections as $connection) {
        [$headers, $body] = array_pad(explode("\r\n\r\n", $connection['response'], 2), 2, '');
        if (!preg_match('/^HTTP\/\S+\s+(\d{3})/m', $headers, $match)) {
            throw new RuntimeException('Concurrent API response did not contain an HTTP status.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Concurrent API response was not valid JSON.');
        }
        $results[] = ['status' => (int)$match[1], 'body' => $decoded];
        echo 'HTTP concurrent POST: ' . (int)$match[1] . ' '
            . json_encode($decoded, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    return $results;
}

function finalDefectMultipart(string $path, array $fields, array $files, string $token): array
{
    global $baseUrl, $requestCount;
    $requestCount++;
    $boundary = '----GymDefectTest' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
        $body .= (string)$value . "\r\n";
    }
    foreach ($files as $name => $fileName) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"fixture.pdf\"\r\n";
        $body .= "Content-Type: application/pdf\r\n\r\n";
        $body .= "%PDF-1.4\n% disposable test proof\n%%EOF\n\r\n";
    }
    $body .= "--{$boundary}--\r\n";
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                . "X-Auth-Token: {$token}\r\nAuthorization: Bearer {$token}\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);
    $response = file_get_contents($baseUrl . $path, false, $context);
    if (!is_string($response)) {
        throw new RuntimeException('Unable to submit a local disposable upload fixture.');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('The local API returned a non-JSON multipart response.');
    }
    echo "HTTP POST {$path} [multipart]: {$status} "
        . json_encode($decoded, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return ['status' => $status, 'body' => $decoded];
}

function finalDefectAddUser(
    PDO $conn,
    string $username,
    string $email,
    string $role = 'member',
    ?string $token = null,
    string $registrationStatus = 'active',
    string $accountStatus = 'active'
): int {
    global $testPassword;
    $stmt = $conn->prepare(
        'INSERT INTO users
            (first_name, last_name, full_name, username, email, password, role,
             registration_status, account_status, auth_token)
         VALUES ("Fixture", "User", "Fixture User", :username, :email, :password, :role,
                 :registration_status, :account_status, :auth_token)'
    );
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password' => password_hash($testPassword, PASSWORD_DEFAULT),
        ':role' => $role,
        ':registration_status' => $registrationStatus,
        ':account_status' => $accountStatus,
        ':auth_token' => $token,
    ]);
    return (int)$conn->lastInsertId();
}

function finalDefectAddMember(
    PDO $conn,
    int $userId,
    string $code,
    string $membershipStatus = 'active',
    string $accountStatus = 'active',
    string $membershipType = 'monthly'
): int {
    $stmt = $conn->prepare(
        'INSERT INTO members
            (user_id, member_code, first_name, last_name, full_name, membership_type,
             member_category, status, account_status)
         VALUES (:user_id, :code, "Fixture", "User", "Fixture User", :membership_type,
                 "regular", :status, :account_status)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':code' => $code,
        ':membership_type' => $membershipType,
        ':status' => $membershipStatus,
        ':account_status' => $accountStatus,
    ]);
    return (int)$conn->lastInsertId();
}

function finalDefectAddPendingRequest(
    PDO $conn,
    string $username,
    string $email,
    string $firstName,
    string $lastName,
    ?int $existingUserId = null
): int {
    global $testPassword;
    $passwordHash = password_hash($testPassword, PASSWORD_DEFAULT);
    if ($existingUserId !== null) {
        $userPassword = $conn->prepare('SELECT password FROM users WHERE id = :id');
        $userPassword->execute([':id' => $existingUserId]);
        $storedPassword = $userPassword->fetchColumn();
        if (!is_string($storedPassword)) {
            throw new RuntimeException('The linked pending account does not exist in the disposable fixture.');
        }
        $passwordHash = $storedPassword;
    }
    $stmt = $conn->prepare(
        "INSERT INTO registration_requests
            (first_name, last_name, full_name, username, email, phone, address,
             date_of_birth, password, membership_type, member_category, status,
             registration_source, membership_plan_id, payment_method, payment_status,
             discount_status, payment_amount, member_id)
         VALUES (:first_name, :last_name, :full_name, :username, :email, '09123456789',
                 'Disposable test address', '1990-01-01', :password, 'monthly', 'regular',
                 'pending', 'online', 1, 'cash', 'pending', 'not_required', 800.00, NULL)"
    );
    $stmt->execute([
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':full_name' => $firstName . ' ' . $lastName,
        ':username' => $username,
        ':email' => $email,
        ':password' => $passwordHash,
    ]);
    return (int)$conn->lastInsertId();
}

function finalDefectAdminPayload(string $suffix, string $membershipType, string $method, string $key): array
{
    $category = $membershipType === 'monthly' && str_starts_with($suffix, 'promo') ? 'student' : 'regular';
    $usernameSuffix = preg_replace('/[^a-z0-9]/', '', strtolower($suffix));
    return [
        'first_name' => 'Fix' . ucfirst($suffix),
        'last_name' => 'Member',
        'username' => 'fix' . $usernameSuffix,
        'email' => 'fix-' . $suffix . '@example.test',
        'phone' => '09123456789',
        'address' => 'Disposable test address',
        'date_of_birth' => '1990-01-01',
        'password' => 'Safe-member-password-2026',
        'membership_type' => $membershipType,
        'member_category' => $category,
        'payment_method' => $method,
        'idempotency_key' => $key,
        'membership_plan_id' => '1',
        'duration_months' => '1',
        'verify_discount_eligibility' => $category === 'student' ? '1' : '0',
    ];
}

function finalDefectCount(PDO $conn, string $sql, array $params = []): int
{
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function finalDefectApprovalRequest(
    PDO $conn,
    string $username,
    string $email,
    string $firstName,
    string $lastName
): int {
    return finalDefectAddPendingRequest($conn, $username, $email, $firstName, $lastName);
}

$notificationColumns = $conn->query('SHOW COLUMNS FROM notifications')->fetchAll(PDO::FETCH_COLUMN, 0);
if (!in_array('booking_id', $notificationColumns, true)) {
    $conn->exec('ALTER TABLE notifications ADD COLUMN booking_id INT(11) DEFAULT NULL');
}
$conn->exec('ALTER TABLE notifications MODIFY COLUMN membership_end DATE NULL');
$conn->exec(
    "UPDATE gym_payment_settings
     SET gcash_account_name = 'Disposable Gym', gcash_account_number = '09123456789'
     WHERE id = 1"
);

$adminId = finalDefectAddUser($conn, 'fixtureadmin', 'admin@example.test', 'admin', 'admin-token');
$inactiveMembershipUserId = finalDefectAddUser($conn, 'memberinactive', 'inactive-member@example.test');
$inactiveMembershipId = finalDefectAddMember($conn, $inactiveMembershipUserId, 'FIX-INACTIVE', 'inactive');
$expiredUserId = finalDefectAddUser($conn, 'memberexpired', 'expired-member@example.test');
$expiredMemberId = finalDefectAddMember($conn, $expiredUserId, 'FIX-EXPIRED', 'expired');
$deactivatedUserId = finalDefectAddUser(
    $conn,
    'memberdeactivated',
    'deactivated-member@example.test',
    'member',
    'old-deactivated-token',
    'active',
    'active'
);
$deactivatedMemberId = finalDefectAddMember($conn, $deactivatedUserId, 'FIX-DEACTIVATED', 'active', 'deactivated');
$trainerUserId = finalDefectAddUser($conn, 'traineractive', 'trainer@example.test', 'trainer');
$trainerStmt = $conn->prepare(
    "INSERT INTO trainers (user_id, trainer_code, status) VALUES (:user_id, 'FIX-TRAINER', 'active')"
);
$trainerStmt->execute([':user_id' => $trainerUserId]);
$pendingUserId = finalDefectAddUser($conn, 'memberpending', 'pending-member@example.test');
$pendingRequestId = finalDefectAddPendingRequest(
    $conn,
    'memberpending',
    'pending-member@example.test',
    'Pending',
    'Member',
    $pendingUserId
);
$unlinkedUserId = finalDefectAddUser($conn, 'unlinkedprofile', 'unlinked-profile@example.test');
$conn->prepare(
    "INSERT INTO members
        (user_id, member_code, first_name, last_name, full_name, email, membership_type)
     VALUES (NULL, 'FIX-UNLINKED', 'Legacy', 'Profile', 'Legacy Profile',
             'unlinked-profile@example.test', 'monthly')"
)->execute();
$conn->prepare(
    "INSERT INTO payments (member_id, amount, payment_type, status, payment_date, payment_method, payment_channel)
     VALUES (:member_id, 800.00, 'monthly_subscription', 'pending', CURRENT_DATE(), 'cash', 'counter')"
)->execute([':member_id' => $expiredMemberId]);
$attendanceDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$conn->prepare(
    "INSERT INTO attendance (member_id, attendance_date, time_in, time_out)
     VALUES (:member_id, :attendance_date, '08:00:00', '09:00:00')"
)->execute([
    ':member_id' => $inactiveMembershipId,
    ':attendance_date' => $attendanceDate,
]);

$inactiveMembershipLogin = finalDefectHttp('POST', '/login.php', [
    'username' => 'memberinactive',
    'password' => $testPassword,
]);
finalDefectAssert(
    $inactiveMembershipLogin['status'] === 200
        && ($inactiveMembershipLogin['body']['role'] ?? null) === 'member'
        && !empty($inactiveMembershipLogin['body']['auth_token']),
    "Membership status 'inactive' does not disable an active account login"
);

$expiredMembershipLogin = finalDefectHttp('POST', '/login.php', [
    'email' => 'EXPIRED-MEMBER@example.test',
    'password' => $testPassword,
]);
finalDefectAssert(
    $expiredMembershipLogin['status'] === 200 && !empty($expiredMembershipLogin['body']['auth_token']),
    'Expired membership with an unpaid pending payment does not disable account login by email'
);

$pendingLogin = finalDefectHttp('POST', '/login.php', [
    'username' => 'memberpending',
    'password' => $testPassword,
]);
finalDefectAssert(
    $pendingLogin['status'] === 200 && !empty($pendingLogin['body']['auth_token']),
    'Pending initial registration can still authenticate to its account workflow'
);

$trainerLogin = finalDefectHttp('POST', '/login.php', [
    'username' => 'traineractive',
    'password' => $testPassword,
]);
finalDefectAssert(
    $trainerLogin['status'] === 200 && ($trainerLogin['body']['role'] ?? null) === 'trainer',
    'Active Trainer login remains available'
);

$deactivatedLogin = finalDefectHttp('POST', '/login.php', [
    'username' => 'memberdeactivated',
    'password' => $testPassword,
]);
finalDefectAssert(
    $deactivatedLogin['status'] === 403
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM users WHERE id = :id AND auth_token IS NULL', [':id' => $deactivatedUserId]) === 1,
    'Deactivated Member login is rejected and any previous token is cleared'
);
$oldTokenResponse = finalDefectHttp('GET', '/member_profile.php', null, 'old-deactivated-token');
finalDefectAssert(
    $oldTokenResponse['status'] === 401,
    'A deactivated Member cannot use an old token against the authenticated Member Profile API'
);

$activeTokenLogin = finalDefectHttp('POST', '/login.php', [
    'username' => 'memberinactive',
    'password' => $testPassword,
]);
$linkedProfileResponse = finalDefectHttp(
    'GET',
    '/member_profile.php',
    null,
    (string)($activeTokenLogin['body']['auth_token'] ?? '')
);
$attendanceMonth = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('n');
$attendanceYear = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y');
$linkedAttendanceResponse = finalDefectHttp(
    'GET',
    '/member_attendance_history.php?month=' . $attendanceMonth . '&year=' . $attendanceYear,
    null,
    (string)($activeTokenLogin['body']['auth_token'] ?? '')
);
finalDefectAssert(
    $linkedProfileResponse['status'] === 200
        && ($linkedProfileResponse['body']['success'] ?? false) === true
        && (int)($linkedProfileResponse['body']['member']['id'] ?? 0) === $inactiveMembershipId
        && ($linkedProfileResponse['body']['member']['email'] ?? null) === 'inactive-member@example.test'
        && $linkedAttendanceResponse['status'] === 200
        && count($linkedAttendanceResponse['body']['records'] ?? []) === 1,
    'Linked Member profile and attendance history resolve through user_id even when membership status is inactive'
);
$unlinkedLogin = finalDefectHttp('POST', '/login.php', [
    'username' => 'unlinkedprofile',
    'password' => $testPassword,
]);
$unlinkedProfileResponse = finalDefectHttp(
    'GET',
    '/member_profile.php',
    null,
    (string)($unlinkedLogin['body']['auth_token'] ?? '')
);
finalDefectAssert(
    $unlinkedLogin['status'] === 403
        && str_contains((string)($unlinkedLogin['body']['message'] ?? ''), 'not linked')
        && empty($unlinkedLogin['body']['auth_token'])
        && $unlinkedProfileResponse['status'] === 401
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM members WHERE user_id = :user_id', [':user_id' => $unlinkedUserId]) === 0,
    'An orphan legacy Member row cannot be adopted through email matching or authenticate as a linked Member'
);
$deactivateResponse = finalDefectHttp('POST', '/members.php', [
    'action' => 'account-status',
    'id' => $inactiveMembershipId,
    'account_status' => 'deactivated',
], 'admin-token');
$deactivatedTokenResponse = finalDefectHttp(
    'GET',
    '/member_profile.php',
    null,
    (string)($activeTokenLogin['body']['auth_token'] ?? '')
);
finalDefectAssert(
    $deactivateResponse['status'] === 200
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM users WHERE id = :id AND auth_token IS NULL', [':id' => $inactiveMembershipUserId]) === 1
        && $deactivatedTokenResponse['status'] === 401,
    'Admin account deactivation clears the token and blocks its reuse at another authenticated API'
);

$adminToken = 'admin-token';
$adminCases = [
    ['regular-cash', 'monthly', 'cash', '10000000-0000-4000-8000-000000000001', [], []],
    ['promo-gcash', 'monthly', 'gcash', '10000000-0000-4000-8000-000000000002', ['payment_proof' => 'proof.pdf', 'discount_proof' => 'eligibility.pdf'], []],
    ['daily-cash', 'daily_pass', 'cash', '10000000-0000-4000-8000-000000000003', [], []],
    ['daily-gcash', 'daily_pass', 'gcash', '10000000-0000-4000-8000-000000000004', ['payment_proof' => 'proof.pdf'], []],
];
foreach ($adminCases as [$suffix, $type, $method, $key, $files]) {
    $payload = finalDefectAdminPayload($suffix, $type, $method, $key);
    $first = $files
        ? finalDefectMultipart('/admin_member_registration.php', $payload, $files, $adminToken)
        : finalDefectHttp('POST', '/admin_member_registration.php', $payload, $adminToken);
    $memberId = (int)($first['body']['member_id'] ?? 0);
    $memberType = $type === 'daily_pass' ? 'walk-in' : 'monthly';
    $memberTypeStmt = $conn->prepare('SELECT membership_type FROM members WHERE id = :id');
    $memberTypeStmt->execute([':id' => $memberId]);
    $storedType = $memberTypeStmt->fetchColumn();
    $linkedCount = finalDefectCount(
        $conn,
        'SELECT COUNT(*) FROM members m JOIN users u ON u.id = m.user_id WHERE m.id = :id',
        [':id' => $memberId]
    );
    $paymentCount = finalDefectCount($conn, 'SELECT COUNT(*) FROM payments WHERE member_id = :id', [':id' => $memberId]);
    $passCount = finalDefectCount($conn, 'SELECT COUNT(*) FROM daily_pass_transactions WHERE member_id = :id', [':id' => $memberId]);
    $periodCount = finalDefectCount($conn, 'SELECT COUNT(*) FROM membership_subscription_periods WHERE member_id = :id', [':id' => $memberId]);
    finalDefectAssert(
        $first['status'] === 201
            && $memberId > 0
            && $linkedCount === 1
            && $storedType === $memberType,
        "{$suffix}: endpoint creates one linked Member using schema-supported membership type"
    );
    finalDefectAssert(
        $type === 'monthly'
            ? $paymentCount === 1 && $passCount === 0 && $periodCount === 0
            : $passCount === 1 && $paymentCount === 0 && $periodCount === 0,
        "{$suffix}: payment history uses only the correct payment system and no unverified subscription period"
    );
    if ($type === 'daily_pass') {
        $pass = $conn->prepare('SELECT payment_method, payment_status, transaction_source FROM daily_pass_transactions WHERE member_id = :id');
        $pass->execute([':id' => $memberId]);
        $passRow = $pass->fetch(PDO::FETCH_ASSOC);
        finalDefectAssert(
            $passRow['payment_method'] === $method
                && $passRow['transaction_source'] === 'counter'
                && $passRow['payment_status'] === ($method === 'cash' ? 'unpaid' : 'pending'),
            "{$suffix}: Cash remains unconfirmed and GCash remains pending in Daily Pass transaction history"
        );
    }

    $retry = $files
        ? finalDefectMultipart('/admin_member_registration.php', $payload, $files, $adminToken)
        : finalDefectHttp('POST', '/admin_member_registration.php', $payload, $adminToken);
    finalDefectAssert(
        $retry['status'] === 200
            && ($retry['body']['replayed'] ?? false) === true
            && finalDefectCount($conn, 'SELECT COUNT(*) FROM payments WHERE member_id = :id', [':id' => $memberId]) === $paymentCount
            && finalDefectCount($conn, 'SELECT COUNT(*) FROM daily_pass_transactions WHERE member_id = :id', [':id' => $memberId]) === $passCount,
        "{$suffix}: idempotent retry returns the original record without duplicate financial rows"
    );
}

$duplicateUsers = [
    ['duplicateexact', 'exact@example.test'],
    ['duplicatemixed', 'mixed@example.test'],
    ['duplicatewhitespace', '  spaced@example.test  '],
];
foreach ($duplicateUsers as [$username, $email]) {
    finalDefectAddUser($conn, $username, $email);
}
$approvalConflicts = [
    ['exact-target', 'exact-target@example.test', 'Exact', 'Target', 'exact@example.test'],
    ['mixed-target', 'mixed-target@example.test', 'Mixed', 'Target', 'MIXED@example.test'],
    ['space-target', 'space-target@example.test', 'Space', 'Target', 'spaced@example.test'],
];
foreach ($approvalConflicts as [$username, $email, $firstName, $lastName, $conflictingEmail]) {
    $requestId = finalDefectApprovalRequest($conn, $username, $conflictingEmail, $firstName, $lastName);
    $result = finalDefectHttp(
        'POST',
        '/registration_requests.php?action=approve',
        ['id' => $requestId],
        $adminToken
    );
    finalDefectAssert(
        $result['status'] === 409
            && ($result['body']['message'] ?? '') === 'Email is already registered or awaiting approval.'
            && finalDefectCount($conn, 'SELECT COUNT(*) FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email))', [':email' => $conflictingEmail]) === 1
            && finalDefectCount($conn, 'SELECT COUNT(*) FROM members WHERE user_id = (SELECT id FROM users WHERE username = :username)', [':username' => $username]) === 0,
        "Approval rejects duplicate email after trim/case normalization for {$username} without creating an account"
    );
}

$pendingEmailId = finalDefectApprovalRequest($conn, 'heldtarget', 'held@example.test', 'Held', 'Target');
finalDefectApprovalRequest($conn, 'heldpending', 'held@example.test', 'Other', 'Pending');
$pendingConflict = finalDefectHttp(
    'POST',
    '/registration_requests.php?action=approve',
    ['id' => $pendingEmailId],
    $adminToken
);
finalDefectAssert(
    $pendingConflict['status'] === 409
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM users WHERE email = "held@example.test"') === 0,
    'A pending registration continues to reserve its email during approval'
);

$rejectedUserId = finalDefectAddUser(
    $conn,
    'rejectedold',
    'reusable@example.test',
    'member',
    null,
    'rejected'
);
$rejectedRequest = finalDefectApprovalRequest($conn, 'rejectedoldreq', 'reusable@example.test', 'Rejected', 'Old');
$conn->prepare("UPDATE registration_requests SET status = 'rejected' WHERE id = :id")->execute([':id' => $rejectedRequest]);
$reuseRequestId = finalDefectApprovalRequest($conn, 'reusednew', 'reusable@example.test', 'Reuse', 'New');
$reuseResponse = finalDefectHttp(
    'POST',
    '/registration_requests.php?action=approve',
    ['id' => $reuseRequestId, 'confirm_payment' => true],
    $adminToken
);
finalDefectAssert(
    $reuseResponse['status'] === 200
        && finalDefectCount($conn, "SELECT COUNT(*) FROM users WHERE email = 'reusable@example.test' AND registration_status = 'active'") === 1
        && finalDefectCount($conn, "SELECT COUNT(*) FROM members m JOIN users u ON u.id = m.user_id WHERE u.username = 'reusednew'") === 1,
    'Rejected account identifiers are released for a new successful linked Member approval'
);

$approvalUserId = finalDefectAddUser($conn, 'approvalcandidate', 'approved@example.test');
$approvalRequestId = finalDefectAddPendingRequest(
    $conn,
    'approvalcandidate',
    'approved@example.test',
    'Approved',
    'Member',
    $approvalUserId
);
$approvalResponse = finalDefectHttp(
    'POST',
    '/registration_requests.php?action=approve',
    ['id' => $approvalRequestId, 'confirm_payment' => true],
    $adminToken
);
finalDefectAssert(
    $approvalResponse['status'] === 200
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM users WHERE id = :id', [':id' => $approvalUserId]) === 1
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM members WHERE user_id = :id', [':id' => $approvalUserId]) === 1
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM payments WHERE registration_request_id = :id', [':id' => $approvalRequestId]) === 1
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM membership_subscription_periods WHERE registration_request_id = :id', [':id' => $approvalRequestId]) === 1,
    'Approval succeeds for an existing matching pending account and creates exactly one linked Member, payment, and period'
);

$raceRequestId = finalDefectApprovalRequest($conn, 'raceresponse', 'race1062@example.test', 'Race', 'Response');
$conn->exec(
    "CREATE TRIGGER final_defect_user_insert_pause BEFORE INSERT ON users
     FOR EACH ROW
     BEGIN
       IF NEW.username = 'raceresponse'
          AND LOWER(TRIM(NEW.email)) = 'race1062@example.test' THEN
         DO SLEEP(6);
       END IF;
     END"
);
$raceResponses = finalDefectConcurrentHttp(
    [[
        'url' => $concurrentUrl . '/registration_requests.php?action=approve',
        'payload' => ['id' => $raceRequestId, 'confirm_payment' => true],
        'token' => $adminToken,
    ]],
    static function () use ($conn, $testPassword): void {
        usleep(500000);
        $conn->prepare(
            'INSERT INTO users (first_name, last_name, full_name, username, email, password, role, registration_status)
             VALUES ("Race", "Winner", "Race Winner", "racewinner", "race1062@example.test", :password, "member", "active")'
        )->execute([':password' => password_hash($testPassword, PASSWORD_DEFAULT)]);
    }
);
$conn->exec('DROP TRIGGER final_defect_user_insert_pause');
$raceResponse = $raceResponses[0] ?? ['status' => 0, 'body' => []];
finalDefectAssert(
    $raceResponse['status'] === 409
        && ($raceResponse['body']['message'] ?? '') === 'Username or email is already registered or awaiting approval.'
        && finalDefectCount($conn, "SELECT COUNT(*) FROM users WHERE email = 'race1062@example.test'") === 1
        && finalDefectCount($conn, "SELECT COUNT(*) FROM users WHERE username = 'racewinner'") === 1
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM members WHERE user_id = (SELECT id FROM users WHERE username = "raceresponse")') === 0
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM registration_requests WHERE id = :id AND status = "pending"', [':id' => $raceRequestId]) === 1,
    'A concurrent unique-email insert after preflight returns safe HTTP 409 on MariaDB error 1062 and rolls back approval'
);

$concurrentEmail = 'simultaneous@example.test';
$concurrentRequestOne = finalDefectApprovalRequest($conn, 'simultaneousone', $concurrentEmail, 'Simultaneous', 'One');
$concurrentRequestTwo = finalDefectApprovalRequest($conn, 'simultaneoustwo', $concurrentEmail, 'Simultaneous', 'Two');
$concurrentResponses = finalDefectConcurrentHttp([
    [
        'url' => $baseUrl . '/registration_requests.php?action=approve',
        'payload' => ['id' => $concurrentRequestOne, 'confirm_payment' => true],
        'token' => $adminToken,
    ],
    [
        'url' => $concurrentUrl . '/registration_requests.php?action=approve',
        'payload' => ['id' => $concurrentRequestTwo, 'confirm_payment' => true],
        'token' => $adminToken,
    ],
]);
finalDefectAssert(
    count($concurrentResponses) === 2
        && $concurrentResponses[0]['status'] === 409
        && $concurrentResponses[1]['status'] === 409
        && finalDefectCount($conn, 'SELECT COUNT(*) FROM users WHERE email = :email', [':email' => $concurrentEmail]) === 0,
    'Simultaneous approval attempts for a duplicated pending email both return HTTP 409 without creating accounts'
);

echo "All final defect HTTP integration checks passed: {$assertions} assertions against {$databaseName}." . PHP_EOL;
