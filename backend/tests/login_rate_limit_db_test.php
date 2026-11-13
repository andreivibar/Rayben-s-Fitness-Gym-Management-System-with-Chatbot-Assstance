<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this test from the command line.\n");
    exit(2);
}

$host = getenv('LOGIN_RATE_LIMIT_TEST_HOST') ?: '127.0.0.1';
$port = getenv('LOGIN_RATE_LIMIT_TEST_PORT') ?: '3306';
$dbUser = getenv('LOGIN_RATE_LIMIT_TEST_USER') ?: 'root';
$dbPassword = getenv('LOGIN_RATE_LIMIT_TEST_PASSWORD') ?: '';
$databaseName = 'gym_login_rate_limit_test_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
$baseUrl = '';
$serverProcess = null;
$serverLogHandle = null;
$serverLogPath = '';
$workerDirectory = '';
$createdDatabase = false;
$checks = 0;
$concurrencySummary = 'not run';
$error = null;
$conn = null;

function loginTestAssert(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function loginTestCreateUser(
    PDO $conn,
    string $role,
    string $username,
    string $password,
    string $accountStatus = 'active',
    string $profileStatus = 'active',
    bool $legacyPassword = false
): array {
    $email = $username . '@example.com';
    $storedPassword = $legacyPassword ? $password : password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($storedPassword)) {
        throw new RuntimeException('Unable to prepare a test password.');
    }
    $insert = $conn->prepare(
        'INSERT INTO users
            (first_name, last_name, full_name, username, email, password, role,
             registration_status, account_status)
         VALUES
            (:first_name, :last_name, :full_name, :username, :email, :password, :role,
             \'active\', :account_status)'
    );
    $insert->execute([
        ':first_name' => ucfirst($role),
        ':last_name' => 'Fixture',
        ':full_name' => ucfirst($role) . ' Fixture',
        ':username' => $username,
        ':email' => $email,
        ':password' => $storedPassword,
        ':role' => $role,
        ':account_status' => $accountStatus,
    ]);
    $userId = (int)$conn->lastInsertId();

    if ($role === 'member') {
        $member = $conn->prepare(
            'INSERT INTO members
                (user_id, member_code, membership_type, status, account_status, membership_end)
             VALUES
                (:user_id, :member_code, \'monthly\', :status, :account_status, :membership_end)'
        );
        $member->execute([
            ':user_id' => $userId,
            ':member_code' => 'M-' . $username,
            ':status' => $profileStatus,
            ':account_status' => $accountStatus === 'deactivated' ? 'deactivated' : 'active',
            ':membership_end' => $profileStatus === 'expired' ? '2020-01-01' : null,
        ]);
    } elseif ($role === 'trainer') {
        $trainer = $conn->prepare(
            'INSERT INTO trainers (user_id, trainer_code, status)
             VALUES (:user_id, :trainer_code, :status)'
        );
        $trainer->execute([
            ':user_id' => $userId,
            ':trainer_code' => 'T-' . $username,
            ':status' => $profileStatus,
        ]);
    }

    return [
        'id' => $userId,
        'username' => $username,
        'email' => $email,
        'password' => $password,
    ];
}

function loginTestHttp(string $identifier, string $password, string $ip): array
{
    global $baseUrl;
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Login-Test-IP: {$ip}\r\n",
            'content' => json_encode(
                ['username' => $identifier, 'password' => $password],
                JSON_THROW_ON_ERROR
            ),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $body = @file_get_contents($baseUrl . '/login.php', false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
        }
    }

    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'data' => is_string($body) ? json_decode($body, true) : null,
    ];
}

function loginTestCounter(PDO $conn, string $scopeType, string $scopeHash): ?array
{
    $stmt = $conn->prepare(
        'SELECT cooldown_until
         FROM login_rate_limits
         WHERE scope_type = :scope_type AND scope_hash = :scope_hash
         LIMIT 1'
    );
    $stmt->execute([':scope_type' => $scopeType, ':scope_hash' => $scopeHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $window = $scopeType === 'ip_burst' ? 60 : 900;
    $count = $conn->prepare(
        "SELECT COUNT(*)
         FROM login_rate_limit_events
         WHERE scope_type = :scope_type
           AND scope_hash = :scope_hash
           AND failed_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$window} SECOND)"
    );
    $count->execute([':scope_type' => $scopeType, ':scope_hash' => $scopeHash]);
    return [
        'failure_count' => (int)$count->fetchColumn(),
        'window_started_at' => null,
        'cooldown_until' => $row['cooldown_until'],
    ];
}

function loginTestClearIpCounters(PDO $conn): void
{
    $conn->exec("DELETE FROM login_rate_limit_events WHERE scope_type IN ('ip_burst', 'ip_window')");
    $conn->exec(
        "UPDATE login_rate_limits
         SET cooldown_until = NULL
         WHERE scope_type IN ('ip_burst', 'ip_window')"
    );
}

function loginTestStartConcurrentWorkers(string $identifier, int $userId, string $ip): array
{
    global $workerDirectory;
    $workerCount = 10;
    $workerDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'gym_login_rate_limit_workers_' . bin2hex(random_bytes(8));
    if (!mkdir($workerDirectory, 0700)) {
        throw new RuntimeException('Unable to create the temporary worker directory.');
    }
    $releaseFile = $workerDirectory . DIRECTORY_SEPARATOR . 'release';
    $worker = __DIR__ . DIRECTORY_SEPARATOR . 'login_rate_limit_concurrency_worker.php';
    $processes = [];

    for ($index = 0; $index < $workerCount; $index++) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, $worker, $identifier, (string)$userId, $ip, $workerDirectory, $releaseFile],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start a login concurrency worker.');
        }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'pipes' => $pipes];
    }

    $deadline = microtime(true) + 15;
    do {
        $readyFiles = array_values(array_filter(
            scandir($workerDirectory) ?: [],
            static function (string $entry): bool {
                return $entry !== '.' && $entry !== '..' && $entry !== 'release';
            }
        ));
        if (count($readyFiles) >= $workerCount) {
            break;
        }
        if (microtime(true) >= $deadline) {
            file_put_contents($releaseFile, 'release');
            throw new RuntimeException('Timed out waiting for concurrent login workers.');
        }
        usleep(10000);
    } while (true);

    file_put_contents($releaseFile, 'release', LOCK_EX);
    $statuses = [];
    foreach ($processes as $workerProcess) {
        $process = $workerProcess['process'];
        $pipes = $workerProcess['pipes'];
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('A login concurrency worker failed: ' . trim((string)$stderr));
        }
        $statuses[] = trim((string)$stdout);
    }

    return $statuses;
}

function loginTestRemoveTemporaryDirectory(string $directory): void
{
    if ($directory === '' || !is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}

try {
    if (!preg_match('/^gym_login_rate_limit_test_[a-z0-9_]+$/D', $databaseName)
        || $databaseName === 'gym_management'
        || $databaseName === 'gym_management_test') {
        throw new RuntimeException('The generated test database name is unsafe.');
    }

    $hmacKey = bin2hex(random_bytes(32));
    $resetHmacKey = bin2hex(random_bytes(32));
    putenv('LOGIN_RATE_LIMIT_HMAC_KEY=' . $hmacKey);
    putenv('PASSWORD_RESET_HMAC_KEY=' . $resetHmacKey);
    putenv('LOGIN_RATE_LIMIT_TEST_DB=' . $databaseName);
    putenv('LOGIN_RATE_LIMIT_TEST_HOST=' . $host);
    putenv('LOGIN_RATE_LIMIT_TEST_PORT=' . $port);
    putenv('LOGIN_RATE_LIMIT_TEST_USER=' . $dbUser);
    putenv('LOGIN_RATE_LIMIT_TEST_PASSWORD=' . $dbPassword);
    putenv('LOGIN_ACCOUNT_FAILURE_LIMIT=5');
    putenv('LOGIN_ACCOUNT_FAILURE_WINDOW_SECONDS=900');
    putenv('LOGIN_ACCOUNT_COOLDOWN_SECONDS=900');
    putenv('LOGIN_IP_FAILURE_LIMIT=30');
    putenv('LOGIN_IP_FAILURE_WINDOW_SECONDS=900');
    putenv('LOGIN_IP_BURST_LIMIT=100');
    putenv('LOGIN_IP_BURST_WINDOW_SECONDS=60');
    putenv('LOGIN_TRUSTED_PROXIES=');

    $admin = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $dbUser,
        $dbPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $serverVersion = (string)$admin->query('SELECT VERSION()')->fetchColumn();
    loginTestAssert(
        preg_match('/^10\.11\.16-MariaDB/i', $serverVersion) === 1,
        'Tests require MariaDB 10.11.16.'
    );
    $exists = $admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name');
    $exists->execute([':name' => $databaseName]);
    loginTestAssert((int)$exists->fetchColumn() === 0, 'Generated disposable database already exists.');
    $admin->exec(
        "CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
    );
    $createdDatabase = true;

    $conn = new PDO(
        "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
        $dbUser,
        $dbPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $conn->exec("SET time_zone = '+08:00'");
    $conn->exec(
        "CREATE TABLE users (
            id INT NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(50) DEFAULT NULL,
            middle_name VARCHAR(50) DEFAULT NULL,
            last_name VARCHAR(50) DEFAULT NULL,
            full_name VARCHAR(100) DEFAULT NULL,
            username VARCHAR(30) NOT NULL,
            email VARCHAR(100) DEFAULT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','trainer','member') NOT NULL,
            registration_status ENUM('active','rejected') NOT NULL DEFAULT 'active',
            account_status ENUM('active','deactivated') NOT NULL DEFAULT 'active',
            auth_token VARCHAR(255) DEFAULT NULL,
            registration_unique_username VARCHAR(30)
                GENERATED ALWAYS AS (
                    CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(username) END
                ) STORED,
            registration_unique_email VARCHAR(100)
                GENERATED ALWAYS AS (
                    CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(email) END
                ) STORED,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_registration_username (registration_unique_username),
            UNIQUE KEY uq_users_registration_email (registration_unique_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conn->exec(
        "CREATE TABLE members (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT DEFAULT NULL,
            member_code VARCHAR(50) NOT NULL,
            membership_type VARCHAR(32) NOT NULL DEFAULT 'monthly',
            status ENUM('active','expired','inactive') DEFAULT 'active',
            account_status ENUM('active','deactivated') NOT NULL DEFAULT 'active',
            membership_end DATE DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_login_test_member_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conn->exec(
        "CREATE TABLE trainers (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            trainer_code VARCHAR(50) NOT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            PRIMARY KEY (id),
            UNIQUE KEY uq_login_test_trainer_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conn->exec(
        "CREATE TABLE registration_requests (
            id INT NOT NULL AUTO_INCREMENT,
            username VARCHAR(30) NOT NULL,
            email VARCHAR(100) DEFAULT NULL,
            password VARCHAR(255) NOT NULL,
            membership_type VARCHAR(32) DEFAULT NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            member_id INT DEFAULT NULL,
            registration_source VARCHAR(32) DEFAULT 'online',
            payment_status VARCHAR(32) DEFAULT NULL,
            discount_status VARCHAR(32) DEFAULT NULL,
            review_note TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            registration_unique_username VARCHAR(30)
                GENERATED ALWAYS AS (
                    CASE WHEN status = 'rejected' THEN NULL ELSE LOWER(username) END
                ) STORED,
            PRIMARY KEY (id),
            UNIQUE KEY uq_registration_requests_active_username (registration_unique_username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conn->exec(
        "CREATE TABLE daily_pass_transactions (
            id INT NOT NULL AUTO_INCREMENT,
            member_id INT NOT NULL,
            payment_status VARCHAR(32) DEFAULT NULL,
            transaction_source VARCHAR(32) DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $migrationPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
        . 'migrations' . DIRECTORY_SEPARATOR . '20261121_create_login_rate_limits.sql';
    $migration = file_get_contents($migrationPath);
    if (!is_string($migration)) {
        throw new RuntimeException('Unable to read the login rate-limit migration.');
    }
    $migration = preg_replace('/^\s*--.*$/m', '', $migration);
    foreach (explode(';', (string)$migration) as $statement) {
        if (trim($statement) !== '') {
            $conn->exec($statement);
        }
    }

    $conn->exec(
        "CREATE TABLE password_reset_rate_limits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            window_started_at DATETIME NOT NULL,
            request_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_password_reset_rate_scope_window (scope_type, scope_hash, window_started_at),
            KEY idx_password_reset_rate_window (window_started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conn->exec(
        "CREATE TABLE email_change_rate_limits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            window_started_at DATETIME NOT NULL,
            request_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_email_change_rate_scope_window (scope_type, scope_hash, window_started_at),
            KEY idx_email_change_rate_window (window_started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    require_once __DIR__ . '/../config/login_rate_limit_service.php';
    require_once __DIR__ . '/../config/password_reset_service.php';
    require_once __DIR__ . '/../config/email_change_service.php';
    loginTestAssert(
        passwordResetConsumeRateLimit($conn, 'ip', 'reset-regression-ip', 'request', 5),
        'Forgot Password counter should accept its first independent request.'
    );
    loginTestAssert(
        emailChangeConsumeRateLimit($conn, 'ip', 'email-regression-ip', 'request', 5),
        'Email Change counter should accept its first independent request.'
    );
    $resetRowsBefore = (int)$conn->query('SELECT COUNT(*) FROM password_reset_rate_limits')->fetchColumn();
    $emailRowsBefore = (int)$conn->query('SELECT COUNT(*) FROM email_change_rate_limits')->fetchColumn();

    $users = [];
    $users['active_member'] = loginTestCreateUser(
        $conn,
        'member',
        'member_active',
        'MemberPass#1'
    );
    $users['inactive_member'] = loginTestCreateUser(
        $conn,
        'member',
        'member_inactive',
        'MemberPass#1',
        'deactivated'
    );
    $users['active_trainer'] = loginTestCreateUser(
        $conn,
        'trainer',
        'trainer_active',
        'TrainerPass#1'
    );
    $users['inactive_trainer'] = loginTestCreateUser(
        $conn,
        'trainer',
        'trainer_inactive',
        'TrainerPass#1',
        'active',
        'inactive'
    );
    $users['active_admin'] = loginTestCreateUser(
        $conn,
        'admin',
        'admin_active',
        'AdminPass#1'
    );
    $users['inactive_admin'] = loginTestCreateUser(
        $conn,
        'admin',
        'admin_inactive',
        'AdminPass#1',
        'deactivated'
    );
    $users['expired_member'] = loginTestCreateUser(
        $conn,
        'member',
        'member_expired',
        'MemberPass#1',
        'active',
        'expired'
    );
    $users['unpaid_member'] = loginTestCreateUser(
        $conn,
        'member',
        'member_unpaid',
        'MemberPass#1'
    );
    $users['legacy_member'] = loginTestCreateUser(
        $conn,
        'member',
        'member_legacy',
        'LegacyPass#1',
        'active',
        'active',
        true
    );

    $unpaidUser = $users['unpaid_member'];
    $unpaidRegistration = $conn->prepare(
        "INSERT INTO registration_requests
            (username, email, password, membership_type, status, member_id,
             registration_source, payment_status, discount_status)
         SELECT u.username, u.email, u.password, 'monthly', 'approved', m.id,
                'online', 'unpaid', 'not_required'
         FROM users u JOIN members m ON m.user_id = u.id
         WHERE u.id = :user_id"
    );
    $unpaidRegistration->execute([':user_id' => $unpaidUser['id']]);

    $temporaryLog = tempnam(sys_get_temp_dir(), 'gym_login_test_server_');
    if ($temporaryLog === false) {
        throw new RuntimeException('Unable to create a temporary test-server log.');
    }
    $serverLogPath = $temporaryLog;
    $serverLogHandle = fopen($serverLogPath, 'ab');
    if ($serverLogHandle === false) {
        throw new RuntimeException('Unable to open the temporary test-server log.');
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketErrorMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('Unable to allocate a local test-server port.');
    }
    $socketName = stream_socket_get_name($socket, false);
    fclose($socket);
    $portSeparator = strrpos((string)$socketName, ':');
    $httpPort = $portSeparator === false ? 0 : (int)substr((string)$socketName, $portSeparator + 1);
    if ($httpPort < 1) {
        throw new RuntimeException('Unable to determine the local test-server port.');
    }
    $router = __DIR__ . DIRECTORY_SEPARATOR . 'login_rate_limit_http_router.php';
    $serverProcess = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$httpPort}", $router],
        [
            0 => ['pipe', 'r'],
            1 => $serverLogHandle,
            2 => $serverLogHandle,
        ],
        $serverPipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($serverProcess)) {
        throw new RuntimeException('Unable to start the local login test server.');
    }
    fclose($serverPipes[0]);
    $baseUrl = "http://127.0.0.1:{$httpPort}";

    $serverReady = false;
    $readyDeadline = microtime(true) + 10;
    while (microtime(true) < $readyDeadline) {
        $readyBody = @file_get_contents($baseUrl . '/_ready', false, stream_context_create([
            'http' => ['timeout' => 1],
        ]));
        if ($readyBody === 'ready') {
            $serverReady = true;
            break;
        }
        usleep(50000);
    }
    loginTestAssert($serverReady, 'Local login test server did not become ready.');

    putenv('LOGIN_TRUSTED_PROXIES=');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.80';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.80';
    loginTestAssert(
        loginRateLimitClientIp() === '198.51.100.80',
        'Forwarded IP headers should be ignored when no proxy is trusted.'
    );
    putenv('LOGIN_TRUSTED_PROXIES=198.51.100.0/24');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.80, 198.51.100.6';
    loginTestAssert(
        loginRateLimitClientIp() === '203.0.113.80',
        'Forwarded IP should be resolved through a configured trusted-proxy chain.'
    );
    $_SERVER['REMOTE_ADDR'] = '198.51.101.5';
    loginTestAssert(
        loginRateLimitClientIp() === '198.51.101.5',
        'Forwarded IP should be ignored when the immediate peer is not trusted.'
    );
    putenv('LOGIN_TRUSTED_PROXIES=');
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);

    $caseIp = '203.0.113.10';
    $primaryUser = $users['active_member'];
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $response = loginTestHttp($primaryUser['username'], 'WrongPass#1', $caseIp);
        loginTestAssert(
            $response['status'] === 401
                && ($response['data']['message'] ?? '') === LOGIN_RATE_LIMIT_GENERIC_MESSAGE,
            "Incorrect password {$attempt} should return the generic authentication error."
        );
    }
    $accountHash = loginRateLimitDigest(
        'account_ip',
        'account:' . $primaryUser['id'] . "\0" . $caseIp
    );
    $accountCounter = loginTestCounter($conn, 'account_ip', $accountHash);
    $cooldownSeconds = (int)$conn->query(
        'SELECT TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, cooldown_until)
         FROM login_rate_limits
         WHERE scope_type = \'account_ip\' AND scope_hash = ' . $conn->quote($accountHash)
    )->fetchColumn();
    loginTestAssert(
        $accountCounter !== null
            && (int)$accountCounter['failure_count'] === 5
            && $accountCounter['cooldown_until'] !== null
            && $cooldownSeconds >= 890
            && $cooldownSeconds <= 900,
        'Five failures should set the account/IP counter and temporary cooldown.'
    );
    $blockedResponse = loginTestHttp($primaryUser['username'], $primaryUser['password'], $caseIp);
    loginTestAssert(
        $blockedResponse['status'] === 401
            && ($blockedResponse['data']['message'] ?? '') === LOGIN_RATE_LIMIT_GENERIC_MESSAGE,
        'Account/IP cooldown must not reveal that the account exists.'
    );
    $conn->prepare(
        'UPDATE login_rate_limits
         SET cooldown_until = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND)
         WHERE scope_type = :scope_type AND scope_hash = :scope_hash'
    )->execute([':scope_type' => 'account_ip', ':scope_hash' => $accountHash]);
    $conn->prepare(
        'UPDATE login_rate_limit_events
         SET failed_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 16 MINUTE)
         WHERE scope_type = :scope_type AND scope_hash = :scope_hash'
    )->execute([':scope_type' => 'account_ip', ':scope_hash' => $accountHash]);
    $afterCooldown = loginTestHttp($primaryUser['username'], $primaryUser['password'], $caseIp);
    loginTestAssert(
        $afterCooldown['status'] === 200 && ($afterCooldown['data']['success'] ?? false) === true,
        'Valid login should work after controlled cooldown expiry.'
    );
    $accountCounter = loginTestCounter($conn, 'account_ip', $accountHash);
    loginTestAssert(
        $accountCounter !== null
            && (int)$accountCounter['failure_count'] === 0
            && $accountCounter['cooldown_until'] === null,
        'Successful authentication should reset the account/IP counter.'
    );

    $aliasIp = '203.0.113.11';
    loginTestHttp($primaryUser['username'], 'WrongPass#1', $aliasIp);
    loginTestHttp($primaryUser['email'], 'WrongPass#1', $aliasIp);
    $aliasHash = loginRateLimitDigest(
        'account_ip',
        'account:' . $primaryUser['id'] . "\0" . $aliasIp
    );
    $aliasCounter = loginTestCounter($conn, 'account_ip', $aliasHash);
    loginTestAssert(
        $aliasCounter !== null && (int)$aliasCounter['failure_count'] === 2,
        'Username and email failures should share the stable account counter.'
    );
    $emailLogin = loginTestHttp($primaryUser['email'], $primaryUser['password'], $aliasIp);
    loginTestAssert(
        $emailLogin['status'] === 200 && ($emailLogin['data']['success'] ?? false) === true,
        'Email-based login should succeed for the same account.'
    );
    $aliasCounter = loginTestCounter($conn, 'account_ip', $aliasHash);
    loginTestAssert(
        $aliasCounter !== null && (int)$aliasCounter['failure_count'] === 0,
        'Successful email login should reset username-based account failures.'
    );

    $firstIp = '203.0.113.12';
    $secondIp = '203.0.113.13';
    loginTestHttp($primaryUser['username'], 'WrongPass#1', $firstIp);
    $secondIpLogin = loginTestHttp($primaryUser['username'], $primaryUser['password'], $secondIp);
    $firstIpHash = loginRateLimitDigest(
        'account_ip',
        'account:' . $primaryUser['id'] . "\0" . $firstIp
    );
    $firstIpCounter = loginTestCounter($conn, 'account_ip', $firstIpHash);
    loginTestAssert(
        $secondIpLogin['status'] === 200
            && $firstIpCounter !== null
            && (int)$firstIpCounter['failure_count'] === 1,
        'A different IP should log in and must not clear the first IP account counter.'
    );

    $sharedIp = '203.0.113.14';
    $otherMember = $users['active_trainer'];
    loginTestHttp($primaryUser['username'], 'WrongPass#1', $sharedIp);
    loginTestHttp($otherMember['username'], 'WrongPass#1', $sharedIp);
    $sharedIpHash = loginRateLimitDigest('ip_window', $sharedIp);
    $sharedIpCounter = loginTestCounter($conn, 'ip_window', $sharedIpHash);
    loginTestAssert(
        $sharedIpCounter !== null && (int)$sharedIpCounter['failure_count'] === 2,
        'Failures for different accounts should accumulate in the shared IP counter.'
    );
    loginTestHttp($primaryUser['username'], $primaryUser['password'], $sharedIp);
    $sharedIpCounter = loginTestCounter($conn, 'ip_window', $sharedIpHash);
    loginTestAssert(
        $sharedIpCounter !== null && (int)$sharedIpCounter['failure_count'] === 2,
        'Successful authentication must not clear IP-wide failure history.'
    );

    $genericIp = '203.0.113.15';
    $wrongKnown = loginTestHttp($primaryUser['username'], 'WrongPass#1', $genericIp);
    $unknown = loginTestHttp('unknown_account', 'WrongPass#1', '203.0.113.16');
    loginTestAssert(
        $wrongKnown['status'] === $unknown['status']
            && ($wrongKnown['data']['message'] ?? '') === ($unknown['data']['message'] ?? ''),
        'Unknown account and incorrect password responses should be indistinguishable.'
    );
    $normalizedUnknownIp = '203.0.113.17';
    loginTestHttp('Unknown_Account', 'WrongPass#1', $normalizedUnknownIp);
    loginTestHttp('unknown_account', 'WrongPass#1', $normalizedUnknownIp);
    $normalizedUnknownHash = loginRateLimitDigest(
        'identifier_ip',
        "identifier:unknown_account\0" . $normalizedUnknownIp
    );
    $normalizedUnknownCounter = loginTestCounter($conn, 'identifier_ip', $normalizedUnknownHash);
    loginTestAssert(
        $normalizedUnknownCounter !== null && (int)$normalizedUnknownCounter['failure_count'] === 2,
        'Unknown account identifiers should be normalized before hashing and counting.'
    );

    $authCases = [
        ['active_member', '203.0.113.20', true],
        ['active_trainer', '203.0.113.21', true],
        ['active_admin', '203.0.113.22', true],
        ['inactive_member', '203.0.113.23', false],
        ['inactive_trainer', '203.0.113.24', false],
        ['inactive_admin', '203.0.113.25', false],
        ['expired_member', '203.0.113.26', true],
        ['unpaid_member', '203.0.113.27', true],
    ];
    foreach ($authCases as [$caseName, $ip, $shouldSucceed]) {
        $user = $users[$caseName];
        $response = loginTestHttp($user['username'], $user['password'], $ip);
        loginTestAssert(
            $response['status'] === ($shouldSucceed ? 200 : 401),
            "{$caseName} login result should preserve account-status and membership behavior."
        );
        if ($shouldSucceed) {
            $storedToken = $conn->prepare('SELECT auth_token FROM users WHERE id = :id');
            $storedToken->execute([':id' => $user['id']]);
            loginTestAssert(
                ($response['data']['success'] ?? false) === true
                    && ($response['data']['role'] ?? '') === (
                        strpos($caseName, 'trainer') !== false ? 'trainer'
                            : (strpos($caseName, 'admin') !== false ? 'admin' : 'member')
                    )
                    && preg_match('/^[a-f0-9]{64}$/D', (string)($response['data']['auth_token'] ?? '')) === 1
                    && hash_equals(
                        (string)$storedToken->fetchColumn(),
                        (string)$response['data']['auth_token']
                    ),
                "{$caseName} should receive the existing role and token response shape."
            );
        } else {
            loginTestAssert(
                ($response['data']['message'] ?? '') === LOGIN_RATE_LIMIT_GENERIC_MESSAGE,
                "{$caseName} should receive the generic authentication error."
            );
        }
    }

    $legacy = $users['legacy_member'];
    $legacyLogin = loginTestHttp($legacy['username'], $legacy['password'], '203.0.113.28');
    $legacyStoredPassword = $conn->prepare('SELECT password FROM users WHERE id = :id');
    $legacyStoredPassword->execute([':id' => $legacy['id']]);
    loginTestAssert(
        $legacyLogin['status'] === 200
            && password_verify($legacy['password'], (string)$legacyStoredPassword->fetchColumn()),
        'Legacy plaintext password should still be upgraded after successful authentication.'
    );

    $ipLimit = '203.0.113.30';
    for ($attempt = 1; $attempt <= 30; $attempt++) {
        $response = loginTestHttp(
            'unknown_' . str_pad((string)$attempt, 2, '0', STR_PAD_LEFT),
            'WrongPass#1',
            $ipLimit
        );
        loginTestAssert(
            $response['status'] === 401,
            "IP-wide failure {$attempt} should be processed before reaching its threshold."
        );
    }
    $unknownScopeRowsBefore = (int)$conn->query(
        "SELECT COUNT(*) FROM login_rate_limits WHERE scope_type = 'identifier_ip'"
    )->fetchColumn();
    $ipLimitedResponse = loginTestHttp('another_unknown', 'WrongPass#1', $ipLimit);
    loginTestAssert(
        $ipLimitedResponse['status'] === 429
            && !preg_match('/\d/', (string)($ipLimitedResponse['data']['message'] ?? '')),
        'The IP-wide limit should return a generic temporary-unavailable response without counts.'
    );
    for ($attempt = 0; $attempt < 3; $attempt++) {
        loginTestHttp('blocked_unknown_' . $attempt, 'WrongPass#1', $ipLimit);
    }
    loginTestAssert(
        (int)$conn->query(
            "SELECT COUNT(*) FROM login_rate_limits WHERE scope_type = 'identifier_ip'"
        )->fetchColumn() === $unknownScopeRowsBefore,
        'Blocked IP requests must not create unbounded per-identifier limiter rows.'
    );

    putenv('LOGIN_IP_BURST_LIMIT=5');
    putenv('LOGIN_IP_BURST_WINDOW_SECONDS=60');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.55';
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $result = loginRateLimitRun(
            $conn,
            'burst_unknown_' . $attempt,
            null,
            static function (): array {
                return ['authenticated' => false];
            }
        );
        loginTestAssert($result['status'] === 'failed', "Burst attempt {$attempt} should be admitted.");
    }
    $burstBlocked = loginRateLimitRun(
        $conn,
        'burst_unknown_6',
        null,
        static function (): array {
            return ['authenticated' => false];
        }
    );
    loginTestAssert(
        $burstBlocked['status'] === 'ip_limited',
        'The configurable five-per-minute burst throttle should block the next attempt.'
    );
    putenv('LOGIN_IP_BURST_LIMIT=100');
    putenv('LOGIN_IP_BURST_WINDOW_SECONDS=60');

    loginTestClearIpCounters($conn);
    $concurrentIp = '198.51.100.60';
    $concurrentStatuses = loginTestStartConcurrentWorkers(
        'member_concurrent',
        (int)$primaryUser['id'],
        $concurrentIp
    );
    $failedCount = count(array_filter($concurrentStatuses, static function (string $status): bool {
        return $status === 'failed';
    }));
    $limitedCount = count(array_filter($concurrentStatuses, static function (string $status): bool {
        return $status === 'account_limited';
    }));
    loginTestAssert(
        count($concurrentStatuses) === 10 && $failedCount === 5 && $limitedCount === 5,
        'Ten concurrent failures should admit exactly five and cooldown the remaining five.'
    );
    $concurrencySummary = "{$failedCount} admitted / {$limitedCount} cooldown responses of 10";

    $rateLimitColumns = $conn->query('SHOW COLUMNS FROM login_rate_limits')->fetchAll(PDO::FETCH_COLUMN, 0);
    $rateLimitEventColumns = $conn->query('SHOW COLUMNS FROM login_rate_limit_events')->fetchAll(PDO::FETCH_COLUMN, 0);
    loginTestAssert(
        !array_intersect(['username', 'email', 'ip_address', 'password'], $rateLimitColumns)
            && !array_intersect(['username', 'email', 'ip_address', 'password'], $rateLimitEventColumns),
        'Rate-limit storage must not contain raw identifiers, IP addresses, or passwords.'
    );
    $hashedRows = (int)$conn->query(
        "SELECT COUNT(*) FROM login_rate_limits
         WHERE scope_hash NOT REGEXP '^[a-f0-9]{64}$'"
    )->fetchColumn();
    $hashedEventRows = (int)$conn->query(
        "SELECT COUNT(*) FROM login_rate_limit_events
         WHERE scope_hash NOT REGEXP '^[a-f0-9]{64}$'"
    )->fetchColumn();
    loginTestAssert(
        $hashedRows === 0 && $hashedEventRows === 0,
        'All stored limiter identifiers should be HMAC digests.'
    );
    loginTestAssert(
        (int)$conn->query('SELECT COUNT(*) FROM password_reset_rate_limits')->fetchColumn() === $resetRowsBefore
            && (int)$conn->query('SELECT COUNT(*) FROM email_change_rate_limits')->fetchColumn() === $emailRowsBefore,
        'Login attempts must leave Forgot Password and Email Change rate counters unchanged.'
    );

    $conn->exec(
        'UPDATE login_rate_limit_events
         SET failed_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 3 DAY)
         WHERE scope_type = \'account_ip\''
    );
    $conn->exec(
        'UPDATE login_rate_limits
         SET updated_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 3 DAY),
             cooldown_until = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)
         WHERE scope_type = \'account_ip\''
    );
    $expiredEventsBefore = (int)$conn->query(
        "SELECT COUNT(*) FROM login_rate_limit_events WHERE scope_type = 'account_ip'"
    )->fetchColumn();
    $expiredScopesBefore = (int)$conn->query(
        "SELECT COUNT(*) FROM login_rate_limits WHERE scope_type = 'account_ip'"
    )->fetchColumn();
    $removedRows = loginRateLimitPruneExpiredRows($conn);
    $expiredEventsAfter = (int)$conn->query(
        "SELECT COUNT(*) FROM login_rate_limit_events WHERE scope_type = 'account_ip'"
    )->fetchColumn();
    $expiredScopesAfter = (int)$conn->query(
        "SELECT COUNT(*) FROM login_rate_limits WHERE scope_type = 'account_ip'"
    )->fetchColumn();
    loginTestAssert(
        $removedRows <= 600
            && $expiredEventsAfter === 0
            && $expiredScopesAfter === 0
            && $removedRows === $expiredEventsBefore + $expiredScopesBefore,
        "Expired event and scope cleanup should be bounded per invocation (removed={$removedRows}, events={$expiredEventsBefore}/{$expiredEventsAfter}, scopes={$expiredScopesBefore}/{$expiredScopesAfter})."
    );
} catch (Throwable $caught) {
    $error = $caught;
} finally {
    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }
    if (is_resource($serverLogHandle)) {
        fclose($serverLogHandle);
    }
    if ($serverLogPath !== '' && is_file($serverLogPath)) {
        unlink($serverLogPath);
    }
    loginTestRemoveTemporaryDirectory($workerDirectory);
    if ($createdDatabase) {
        try {
            if (!$conn instanceof PDO) {
                $conn = new PDO(
                    "mysql:host={$host};port={$port};charset=utf8mb4",
                    $dbUser,
                    $dbPassword,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            }
            if (preg_match('/^gym_login_rate_limit_test_[a-z0-9_]+$/D', $databaseName)
                && $databaseName !== 'gym_management'
                && $databaseName !== 'gym_management_test') {
                $conn->exec("DROP DATABASE `{$databaseName}`");
            }
        } catch (Throwable $cleanupError) {
            if ($error === null) {
                $error = new RuntimeException('Unable to remove the disposable login test database.');
            }
        }
    }
    putenv('LOGIN_RATE_LIMIT_HMAC_KEY=');
    putenv('PASSWORD_RESET_HMAC_KEY=');
}

if ($error !== null) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

echo "Login rate-limit database tests passed: {$checks} assertions; concurrency {$concurrencySummary}." . PHP_EOL;

