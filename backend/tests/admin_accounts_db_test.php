<?php

$databaseName = getenv('ADMIN_ACCOUNTS_TEST_DB');
if (!$databaseName
    || !preg_match('/^gym_admin_accounts_test_[a-z0-9_]+$/', $databaseName)
    || $databaseName === 'gym_management') {
    fwrite(STDERR, "Set ADMIN_ACCOUNTS_TEST_DB to a new dedicated gym_admin_accounts_test_* schema.\n");
    exit(2);
}

$host = getenv('ADMIN_ACCOUNTS_TEST_HOST') ?: '127.0.0.1';
$port = getenv('ADMIN_ACCOUNTS_TEST_PORT') ?: '3306';
$username = getenv('ADMIN_ACCOUNTS_TEST_USER') ?: 'root';
$password = getenv('ADMIN_ACCOUNTS_TEST_PASSWORD') ?: '';
$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $databaseName) {
    throw new RuntimeException('Connected database did not match the explicitly selected disposable schema.');
}
if ($conn->query("SHOW TABLES LIKE 'users'")->fetchColumn()) {
    throw new RuntimeException('The disposable schema must be newly created and must not already contain tables.');
}

$conn->exec(
    "CREATE TABLE users (
        id INT(11) NOT NULL AUTO_INCREMENT,
        first_name VARCHAR(50) DEFAULT NULL,
        middle_name VARCHAR(50) DEFAULT NULL,
        last_name VARCHAR(50) DEFAULT NULL,
        full_name VARCHAR(100) DEFAULT NULL,
        username VARCHAR(30) NOT NULL,
        email VARCHAR(100) DEFAULT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'trainer', 'member') NOT NULL,
        registration_status ENUM('active', 'rejected') NOT NULL DEFAULT 'active',
        auth_token VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        registration_unique_username VARCHAR(30)
            GENERATED ALWAYS AS (
                CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(username) END
            ) STORED,
        registration_unique_email VARCHAR(100)
            GENERATED ALWAYS AS (
                CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(email) END
            ) STORED,
        profile_picture VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_registration_username (registration_unique_username),
        UNIQUE KEY uq_users_registration_email (registration_unique_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$fixturePassword = password_hash('existing-test-password', PASSWORD_DEFAULT);
$seed = $conn->prepare(
    'INSERT INTO users
        (id, first_name, middle_name, last_name, full_name, username, email, password, role, auth_token)
     VALUES
        (:id, :first_name, NULL, :last_name, :full_name, :username, :email, :password, :role, :auth_token)'
);
$fixtures = [
    [1, 'First', 'Admin', 'First Admin', 'adminone', 'admin1@example.test', 'admin', 'admin-token-1'],
    [2, 'Second', 'Admin', 'Second Admin', 'admintwo', 'admin2@example.test', 'admin', 'admin-token-2'],
    [3, 'Member', 'One', 'Member One', 'memberone', 'member@example.test', 'member', 'member-token'],
    [4, 'Trainer', 'One', 'Trainer One', 'trainerone', 'trainer@example.test', 'trainer', 'trainer-token'],
];
foreach ($fixtures as $fixture) {
    $seed->execute([
        ':id' => $fixture[0],
        ':first_name' => $fixture[1],
        ':last_name' => $fixture[2],
        ':full_name' => $fixture[3],
        ':username' => $fixture[4],
        ':email' => $fixture[5],
        ':password' => $fixturePassword,
        ':role' => $fixture[6],
        ':auth_token' => $fixture[7],
    ]);
}

$conn->exec(
    "CREATE TABLE members (
        id INT NOT NULL AUTO_INCREMENT,
        user_id INT DEFAULT NULL,
        email VARCHAR(100) DEFAULT NULL,
        account_status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active',
        membership_type VARCHAR(30) NOT NULL DEFAULT 'monthly',
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->exec(
    "CREATE TABLE trainers (
        id INT NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->exec(
    "CREATE TABLE registration_requests (
        id INT NOT NULL AUTO_INCREMENT,
        username VARCHAR(30) NOT NULL,
        email VARCHAR(100) DEFAULT NULL,
        password VARCHAR(255) NOT NULL,
        membership_type VARCHAR(30) DEFAULT NULL,
        registration_source VARCHAR(30) DEFAULT NULL,
        member_id INT DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'approved',
        review_note TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        payment_status VARCHAR(30) DEFAULT NULL,
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
        transaction_source VARCHAR(30) DEFAULT NULL,
        payment_status VARCHAR(30) DEFAULT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->exec("INSERT INTO members (user_id, email, account_status, membership_type) VALUES (3, 'member@example.test', 'active', 'monthly')");
$conn->exec("INSERT INTO trainers (user_id, status) VALUES (4, 'active')");

$migration = file_get_contents(__DIR__ . '/../migrations/20261114_add_admin_account_management.sql');
if ($migration === false) {
    throw new RuntimeException('Unable to read the Admin account management migration.');
}
foreach (preg_split('/;[ \t]*(?:\r?\n|$)/', trim($migration)) as $statement) {
    $statement = trim(preg_replace('/^\s*--[^\r\n]*(?:\r?\n|$)/m', '', $statement));
    if ($statement !== '') {
        $conn->exec($statement);
    }
}

function assertAdminAccountsTest(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $description);
    }
    echo 'Passed: ' . $description . PHP_EOL;
}

require_once __DIR__ . '/../config/admin_account_service.php';

$columns = $conn->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN, 0);
$statusColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'account_status'")->fetch();
$creatorColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'created_by_admin_id'")->fetch();
assertAdminAccountsTest(
    $statusColumn && $statusColumn['Type'] === "enum('active','deactivated')"
        && $statusColumn['Null'] === 'NO' && $statusColumn['Default'] === 'active',
    'migration adds a non-null active/deactivated account status with active default'
);
assertAdminAccountsTest(
    $creatorColumn && $creatorColumn['Type'] === 'int(11)' && $creatorColumn['Null'] === 'YES',
    'migration adds a nullable creator column matching the users.id type'
);
assertAdminAccountsTest(
    (int)$conn->query('SELECT COUNT(*) FROM users')->fetchColumn() === 4
        && (int)$conn->query("SELECT COUNT(*) FROM users WHERE account_status='active' AND created_by_admin_id IS NULL")->fetchColumn() === 4,
    'existing users remain present, active by default, and without fabricated creator attribution'
);
$foreignKey = $conn->query(
    "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'created_by_admin_id'
       AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'id'"
)->fetchColumn();
$creatorIndex = $conn->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_created_by_admin_id'")->fetchColumn();
assertAdminAccountsTest((int)$foreignKey === 1 && $creatorIndex !== false, 'creator attribution has its self-reference and lookup index');

$beforeRows = $conn->query('SELECT id, username, email, role, profile_picture FROM users ORDER BY id')->fetchAll();
$accounts = listAdminAccounts($conn);
assertAdminAccountsTest(
    count($accounts) === 2
        && !array_key_exists('password', $accounts[0])
        && !array_key_exists('auth_token', $accounts[0]),
    'Admin list returns only Admin accounts and excludes password and token fields'
);

try {
    normalizeAdminAccountInput([]);
    throw new RuntimeException('Empty account input was accepted.');
} catch (InvalidArgumentException $error) {
    assertAdminAccountsTest(true, 'required-field validation rejects an empty account');
}
try {
    normalizeAdminAccountInput([
        'username' => 'validuser', 'first_name' => 'Valid', 'last_name' => 'Admin',
        'email' => 'not-an-email', 'password' => 'Valid-password-123',
        'confirm_password' => 'Valid-password-123',
    ]);
    throw new RuntimeException('Invalid email was accepted.');
} catch (InvalidArgumentException $error) {
    assertAdminAccountsTest(true, 'invalid email address is rejected');
}
try {
    normalizeAdminAccountInput([
        'username' => 'validuser', 'first_name' => 'Valid', 'last_name' => 'Admin',
        'email' => 'valid@example.test', 'password' => 'Valid-password-123',
        'confirm_password' => 'different-password',
    ]);
    throw new RuntimeException('Password confirmation mismatch was accepted.');
} catch (InvalidArgumentException $error) {
    assertAdminAccountsTest(true, 'password confirmation mismatch is rejected');
}
try {
    createAdminAccount($conn, 1, [
        'username' => 'adminone', 'first_name' => 'Duplicate', 'last_name' => 'Admin',
        'email' => 'new@example.test', 'password' => 'Valid-password-123',
        'confirm_password' => 'Valid-password-123',
    ]);
    throw new RuntimeException('Duplicate username was accepted.');
} catch (DomainException $error) {
    assertAdminAccountsTest(true, 'duplicate username is rejected');
}
try {
    createAdminAccount($conn, 1, [
        'username' => 'newadmin', 'first_name' => 'Duplicate', 'last_name' => 'Admin',
        'email' => 'ADMIN1@example.test', 'password' => 'Valid-password-123',
        'confirm_password' => 'Valid-password-123',
    ]);
    throw new RuntimeException('Duplicate email was accepted.');
} catch (DomainException $error) {
    assertAdminAccountsTest(true, 'duplicate email is rejected case-insensitively');
}

$created = createAdminAccount($conn, 1, [
    'username' => 'createdadmin',
    'first_name' => 'Created',
    'middle_name' => 'Test',
    'last_name' => 'Admin',
    'email' => 'created@example.test',
    'password' => 'Safe-password-123',
    'confirm_password' => 'Safe-password-123',
    'role' => 'member',
    'account_status' => 'deactivated',
]);
$createdRecord = $conn->prepare('SELECT * FROM users WHERE id = :id');
$createdRecord->execute([':id' => $created['id']]);
$createdRecord = $createdRecord->fetch();
assertAdminAccountsTest(
    $created['account_status'] === 'active'
        && (int)$created['created_by_admin_id'] === 1
        && $createdRecord['role'] === 'admin'
        && password_verify('Safe-password-123', $createdRecord['password'])
        && !array_key_exists('password', $created)
        && !array_key_exists('auth_token', $created),
    'created account is active, role-assigned server-side, attributed, hashed, and returned without secrets'
);

try {
    updateAdminAccountStatus($conn, 1, 1, 'deactivated');
    throw new RuntimeException('Self-deactivation was accepted.');
} catch (DomainException $error) {
    assertAdminAccountsTest(true, 'self-deactivation is rejected');
}
try {
    updateAdminAccountStatus($conn, 1, 3, 'deactivated');
    throw new RuntimeException('A non-Admin target was accepted.');
} catch (OutOfBoundsException $error) {
    assertAdminAccountsTest(true, 'status changes cannot target a Member or Trainer');
}

$deactivated = updateAdminAccountStatus($conn, 1, 2, 'deactivated');
$deactivatedRecord = $conn->query('SELECT account_status, auth_token FROM users WHERE id=2')->fetch();
assertAdminAccountsTest(
    $deactivated['account_status'] === 'deactivated' && $deactivatedRecord['auth_token'] === null,
    'deactivation invalidates the target Admin token'
);
$reactivated = updateAdminAccountStatus($conn, 1, 2, 'active');
$reactivatedRecord = $conn->query('SELECT account_status, auth_token FROM users WHERE id=2')->fetch();
assertAdminAccountsTest(
    $reactivated['account_status'] === 'active' && $reactivatedRecord['auth_token'] === null,
    'reactivation leaves the previous token invalid so the Admin must sign in again'
);
try {
    updateAdminAccountStatus($conn, 1, 2, 'active');
    throw new RuntimeException('A repeated status transition was accepted.');
} catch (DomainException $error) {
    assertAdminAccountsTest(true, 'a status transition to the current status is rejected');
}
try {
    assertAdminDeactivationLeavesActiveAdmin(1);
    throw new RuntimeException('The last active Admin deactivation guard allowed a transition.');
} catch (DomainException $error) {
    assertAdminAccountsTest(true, 'last-active-Admin guard rejects deactivation when only one Admin remains');
}

$conn->exec("UPDATE users SET account_status='deactivated', auth_token=NULL WHERE id=" . (int)$created['id']);
$conn->exec("UPDATE users SET account_status='active' WHERE id IN (1,2)");
$conn->exec("UPDATE users SET account_status='deactivated', auth_token='old-admin-token' WHERE id=2");
$activeTokenQuery = $conn->prepare(
    "SELECT id FROM users WHERE auth_token = :token
     AND (LOWER(TRIM(role)) <> 'admin' OR account_status = 'active')"
);
$activeTokenQuery->execute([':token' => 'old-admin-token']);
assertAdminAccountsTest(!$activeTokenQuery->fetchColumn(), 'deactivated Admin token cannot pass the authenticated-user status predicate');
$activeTokenQuery->execute([':token' => 'member-token']);
assertAdminAccountsTest((int)$activeTokenQuery->fetchColumn() === 3, 'Member token remains eligible under the Admin-only status predicate');
$activeTokenQuery->execute([':token' => 'trainer-token']);
assertAdminAccountsTest((int)$activeTokenQuery->fetchColumn() === 4, 'Trainer token remains eligible under the Admin-only status predicate');

$authSource = file_get_contents(__DIR__ . '/../config/auth.php');
$loginSource = file_get_contents(__DIR__ . '/../api/login.php');
assertAdminAccountsTest(
    strpos($authSource, "\$adminAccountStatusFilter = authHasAdminAccountStatusColumn") !== false
        && substr_count($authSource, '{$adminAccountStatusFilter}') >= 2,
    'both bearer-token and email/token authentication paths enforce active Admin status'
);
assertAdminAccountsTest(
    strpos($loginSource, "\$user['account_status'] !== 'active'") !== false
        && strpos($loginSource, "OR account_status = 'active'") !== false,
    'login rejects inactive Admins and conditionally issues tokens only while active'
);

$unchangedExisting = $conn->query('SELECT id, username, email, role, profile_picture FROM users WHERE id <= 4 ORDER BY id')->fetchAll();
assertAdminAccountsTest($beforeRows === $unchangedExisting, 'Member, Trainer, and pre-existing Admin identity/history columns remain unchanged');

function runAdminAccountConcurrency(array $workers, string $databaseName): array
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admin_accounts_test_' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create the isolated concurrency barrier directory.');
    }

    $processes = [];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $workerPath = __DIR__ . DIRECTORY_SEPARATOR . 'admin_accounts_concurrency_worker.php';
    try {
        foreach ($workers as $index => $worker) {
            $workerId = 'worker-' . $index;
            $process = proc_open(
                [
                    PHP_BINARY,
                    $workerPath,
                    $databaseName,
                    $worker['mode'],
                    (string)$worker['actor_id'],
                    (string)($worker['target_id'] ?? 0),
                    $directory,
                    $workerId,
                ],
                $descriptors,
                $pipes,
                __DIR__
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start a concurrent database test worker.');
            }
            fclose($pipes[0]);
            $processes[] = ['process' => $process, 'pipes' => $pipes, 'worker_id' => $workerId];
        }

        $deadline = microtime(true) + 20;
        do {
            $ready = true;
            foreach ($processes as $worker) {
                if (!is_file($directory . DIRECTORY_SEPARATOR . 'ready-' . $worker['worker_id'])) {
                    $ready = false;
                    break;
                }
            }
            if (!$ready) {
                usleep(1000);
            }
        } while (!$ready && microtime(true) < $deadline);
        if (!$ready) {
            throw new RuntimeException('Concurrent database test workers did not reach the barrier.');
        }
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'go', 'go', LOCK_EX);

        $results = [];
        foreach ($processes as $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exitCode = proc_close($worker['process']);
            $result = json_decode($stdout, true);
            if ($exitCode !== 0 || !is_array($result)) {
                throw new RuntimeException('Concurrent worker failed: ' . trim((string)$stderr));
            }
            $results[] = $result;
        }
        return $results;
    } finally {
        foreach ($processes as $worker) {
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($directory);
    }
}

$duplicateRace = runAdminAccountConcurrency([
    ['mode' => 'create', 'actor_id' => 1],
    ['mode' => 'create', 'actor_id' => 2],
], $databaseName);
assertAdminAccountsTest(
    count(array_filter($duplicateRace, static fn(array $result): bool => !empty($result['success']))) === 1
        && count(array_filter($duplicateRace, static fn(array $result): bool => !empty($result['conflict']))) === 1
        && (int)$conn->query("SELECT COUNT(*) FROM users WHERE username='adminrace'")->fetchColumn() === 1,
    'concurrent duplicate Admin creation is resolved by the existing unique identity indexes'
);

$raceAdminId = (int)$conn->query("SELECT id FROM users WHERE username='adminrace'")->fetchColumn();
$conn->exec("UPDATE users SET account_status='active', auth_token='admin-token-1' WHERE id=1");
$conn->exec("UPDATE users SET account_status='active', auth_token='admin-token-2' WHERE id=2");
$conn->exec("UPDATE users SET account_status='deactivated', auth_token=NULL WHERE id=" . $raceAdminId);
$statusRace = runAdminAccountConcurrency([
    ['mode' => 'deactivate', 'actor_id' => 1, 'target_id' => 2],
    ['mode' => 'deactivate', 'actor_id' => 2, 'target_id' => 1],
], $databaseName);
$activeAfterRace = (int)$conn->query(
    "SELECT COUNT(*) FROM users WHERE role='admin' AND account_status='active'"
)->fetchColumn();
assertAdminAccountsTest(
    $activeAfterRace >= 1
        && count(array_filter($statusRace, static fn(array $result): bool => !empty($result['success']))) === 1
        && count(array_filter($statusRace, static fn(array $result): bool => !empty($result['conflict']))) === 1,
    'concurrent cross-deactivation requests serialize and cannot deactivate all active Admins'
);

echo 'All Admin account database tests passed in ' . $databaseName . '.' . PHP_EOL;
