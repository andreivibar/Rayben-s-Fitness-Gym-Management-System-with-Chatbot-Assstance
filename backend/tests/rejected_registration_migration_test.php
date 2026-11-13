<?php

require_once __DIR__ . '/../config/registration_identity.php';

$databaseName = getenv('GYM_TEST_DB_NAME') ?: '';
if (!preg_match('/^gym_management_test_migration_[0-9]{8}_[0-9]{4}$/', $databaseName)) {
    fwrite(STDERR, "Set GYM_TEST_DB_NAME to a disposable migration-test database." . PHP_EOL);
    exit(1);
}

$host = getenv('GYM_TEST_DB_HOST') ?: '127.0.0.1';
$username = getenv('GYM_TEST_DB_USER') ?: 'root';
$password = getenv('GYM_TEST_DB_PASSWORD') ?: '';
$conn = new PDO(
    "mysql:host={$host};dbname={$databaseName}",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function migrationTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function migrationTestInsertUser(PDO $conn, string $username, ?string $email, string $passwordHash, string $role, string $token): int
{
    $stmt = $conn->prepare(
        'INSERT INTO users (first_name, last_name, full_name, username, email, password, role, auth_token)
         VALUES (:first_name, :last_name, :full_name, :username, :email, :password, :role, :auth_token)'
    );
    $stmt->execute([
        ':first_name' => 'Migration',
        ':last_name' => 'Test',
        ':full_name' => 'Migration Test',
        ':username' => $username,
        ':email' => $email,
        ':password' => $passwordHash,
        ':role' => $role,
        ':auth_token' => $token,
    ]);
    return (int)$conn->lastInsertId();
}

function migrationTestInsertRequest(
    PDO $conn,
    string $username,
    string $email,
    string $passwordHash,
    string $membershipType,
    string $status,
    ?int $memberId = null
): int {
    $stmt = $conn->prepare(
        'INSERT INTO registration_requests
            (first_name, last_name, full_name, username, email, password, membership_type, status, member_id,
             registration_source, payment_status, discount_status)
         VALUES
            (:first_name, :last_name, :full_name, :username, :email, :password, :membership_type, :status, :member_id,
             \'online\', \'not_required\', \'not_required\')'
    );
    $stmt->execute([
        ':first_name' => 'Migration',
        ':last_name' => 'Test',
        ':full_name' => 'Migration Test',
        ':username' => $username,
        ':email' => $email,
        ':password' => $passwordHash,
        ':membership_type' => $membershipType,
        ':status' => $status,
        ':member_id' => $memberId,
    ]);
    return (int)$conn->lastInsertId();
}

function migrationTestUser(PDO $conn, string $username): ?array
{
    $stmt = $conn->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function migrationTestUserByToken(PDO $conn, string $token): ?array
{
    $stmt = $conn->prepare('SELECT * FROM users WHERE auth_token = :auth_token LIMIT 1');
    $stmt->execute([':auth_token' => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

$phase = $argv[1] ?? '';
$prefix = 'migration_' . substr(hash('sha256', $databaseName), 0, 8) . '_';

try {
    if ($phase === 'seed') {
        migrationTestAssert(
            registrationIdentityReleaseEnabled($conn) === false,
            'Seed phase must run before the rejected-identifier migration.'
        );

        $conn->beginTransaction();
        $approvedPassword = 'ApprovedMember#1';
        $approvedUserId = migrationTestInsertUser(
            $conn,
            $prefix . 'approved',
            $prefix . 'approved@example.test',
            password_hash($approvedPassword, PASSWORD_DEFAULT),
            'member',
            $prefix . 'approved-token'
        );
        $approvedMember = $conn->prepare(
            "INSERT INTO members (user_id, member_code, first_name, last_name, full_name, email, membership_type)
             VALUES (:user_id, :member_code, 'Migration', 'Approved', 'Migration Approved', :email, 'monthly')"
        );
        $approvedMember->execute([
            ':user_id' => $approvedUserId,
            ':member_code' => $prefix . 'approved',
            ':email' => $prefix . 'approved@example.test',
        ]);

        $pendingPassword = 'PendingMember#1';
        $pendingHash = password_hash($pendingPassword, PASSWORD_DEFAULT);
        migrationTestInsertUser(
            $conn,
            $prefix . 'pending',
            $prefix . 'pending@example.test',
            $pendingHash,
            'member',
            $prefix . 'pending-token'
        );
        migrationTestInsertRequest(
            $conn,
            $prefix . 'pending',
            $prefix . 'pending@example.test',
            $pendingHash,
            'monthly',
            'pending'
        );

        $rejectedPassword = 'RejectedMember#1';
        $rejectedHash = password_hash($rejectedPassword, PASSWORD_DEFAULT);
        migrationTestInsertUser(
            $conn,
            $prefix . 'rejected',
            $prefix . 'rejected@example.test',
            $rejectedHash,
            'member',
            $prefix . 'rejected-token'
        );
        migrationTestInsertRequest(
            $conn,
            $prefix . 'rejected',
            $prefix . 'rejected@example.test',
            $rejectedHash,
            'monthly',
            'rejected'
        );

        $dailyPassPassword = 'RejectedPass#1';
        $dailyPassUserId = migrationTestInsertUser(
            $conn,
            $prefix . 'daily',
            $prefix . 'daily@example.test',
            password_hash($dailyPassPassword, PASSWORD_DEFAULT),
            'member',
            $prefix . 'daily-token'
        );
        $dailyPassMember = $conn->prepare(
            "INSERT INTO members (user_id, member_code, first_name, last_name, full_name, email, membership_type)
             VALUES (:user_id, :member_code, 'Migration', 'Daily', 'Migration Daily', :email, 'daily_pass')"
        );
        $dailyPassMember->execute([
            ':user_id' => $dailyPassUserId,
            ':member_code' => $prefix . 'daily',
            ':email' => $prefix . 'daily@example.test',
        ]);
        $dailyPassMemberId = (int)$conn->lastInsertId();
        migrationTestInsertRequest(
            $conn,
            $prefix . 'daily',
            $prefix . 'daily@example.test',
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'walk-in',
            'rejected',
            $dailyPassMemberId
        );
        $dailyPassTransaction = $conn->prepare(
            "INSERT INTO daily_pass_transactions
                (member_id, customer_name, pass_code, idempotency_key, visit_date, expires_at, payment_method, amount,
                 payment_status, transaction_source)
             VALUES (:member_id, 'Migration Daily', :pass_code, :idempotency_key, CURRENT_DATE,
                     DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY), 'cash', 100.00, 'unpaid', 'online')"
        );
        $dailyPassTransaction->execute([
            ':member_id' => $dailyPassMemberId,
            ':pass_code' => strtoupper(bin2hex(random_bytes(16))),
            ':idempotency_key' => bin2hex(random_bytes(32)),
        ]);

        $adminId = migrationTestInsertUser(
            $conn,
            $prefix . 'admin',
            $prefix . 'admin@example.test',
            password_hash('UnrelatedAdmin#1', PASSWORD_DEFAULT),
            'admin',
            $prefix . 'admin-token'
        );
        $trainerId = migrationTestInsertUser(
            $conn,
            $prefix . 'trainer',
            $prefix . 'trainer@example.test',
            password_hash('UnrelatedTrainer#1', PASSWORD_DEFAULT),
            'trainer',
            $prefix . 'trainer-token'
        );
        $trainer = $conn->prepare(
            "INSERT INTO trainers (user_id, trainer_code, status) VALUES (:user_id, :trainer_code, 'active')"
        );
        $trainer->execute([
            ':user_id' => $trainerId,
            ':trainer_code' => $prefix . 'trainer',
        ]);
        $conn->commit();
        echo 'Seeded approved, pending, rejected monthly, rejected Daily Pass, Admin, and Trainer fixtures.' . PHP_EOL;
        exit(0);
    }

    if ($phase === 'verify-first' || $phase === 'verify-second') {
        migrationTestAssert(
            registrationIdentityReleaseEnabled($conn),
            'Replacement identity indexes are not active.'
        );

        $approved = migrationTestUser($conn, $prefix . 'approved');
        migrationTestAssert($approved && $approved['registration_status'] === 'active', 'Approved member changed status.');
        migrationTestAssert($approved['auth_token'] === $prefix . 'approved-token', 'Approved member token changed.');
        $approvedLogin = findLoginAccountByIdentifier($conn, $prefix . 'approved@example.test');
        migrationTestAssert($approvedLogin && password_verify('ApprovedMember#1', $approvedLogin['password']), 'Approved member login lookup failed.');

        $pending = migrationTestUser($conn, $prefix . 'pending');
        migrationTestAssert($pending && $pending['registration_status'] === 'active', 'Pending member changed status.');
        migrationTestAssert($pending['auth_token'] === $prefix . 'pending-token', 'Pending member token changed.');
        migrationTestAssert(findLoginAccountByIdentifier($conn, $prefix . 'pending') !== null, 'Pending account login lookup changed.');

        $rejected = migrationTestUser($conn, $prefix . 'rejected');
        migrationTestAssert($rejected && $rejected['registration_status'] === 'rejected', 'Rejected monthly account was not marked rejected.');
        migrationTestAssert($rejected['auth_token'] === null, 'Rejected monthly token was not cleared.');
        if ($phase === 'verify-first') {
            migrationTestAssert(findLoginAccountByIdentifier($conn, $prefix . 'rejected') === null, 'Rejected monthly account still passes login lookup.');
        }

        $daily = migrationTestUser($conn, $prefix . 'daily');
        migrationTestAssert($daily && $daily['registration_status'] === 'rejected', 'Rejected Daily Pass account was not marked rejected.');
        migrationTestAssert($daily['auth_token'] === null, 'Rejected Daily Pass token was not cleared.');
        migrationTestAssert(findLoginAccountByIdentifier($conn, $prefix . 'daily@example.test') === null, 'Rejected Daily Pass account still passes login lookup.');

        foreach (['admin', 'trainer'] as $roleAccount) {
            $account = migrationTestUser($conn, $prefix . $roleAccount);
            migrationTestAssert($account && $account['registration_status'] === 'active', "Unrelated {$roleAccount} account changed status.");
            migrationTestAssert($account['auth_token'] === $prefix . $roleAccount . '-token', "Unrelated {$roleAccount} token changed.");
        }

        $history = $conn->prepare(
            "SELECT COUNT(*) FROM registration_requests
             WHERE username IN (:monthly_username, :daily_username) AND status = 'rejected'"
        );
        $history->execute([
            ':monthly_username' => $prefix . 'rejected',
            ':daily_username' => $prefix . 'daily',
        ]);
        migrationTestAssert((int)$history->fetchColumn() === 2, 'Rejected registration history was not preserved.');

        if ($phase === 'verify-first') {
            echo 'First-run checks passed; ready to create a replacement registration.' . PHP_EOL;
            exit(0);
        }

        $replacement = migrationTestUserByToken($conn, $prefix . 'replacement-token');
        migrationTestAssert($replacement && $replacement['registration_status'] === 'active', 'Replacement account was deactivated by rerun.');
        migrationTestAssert($replacement['auth_token'] === $prefix . 'replacement-token', 'Replacement account token changed on rerun.');
        migrationTestAssert(password_verify('ReplacementMember#1', $replacement['password']), 'Replacement password was changed.');
        migrationTestAssert(!password_verify('RejectedMember#1', $replacement['password']), 'Rejected password can authenticate as replacement.');
        $replacementLogin = findLoginAccountByIdentifier($conn, $prefix . 'rejected@example.test');
        migrationTestAssert($replacementLogin && password_verify('ReplacementMember#1', $replacementLogin['password']), 'Replacement login lookup failed.');

        $activeUserCount = $conn->prepare(
            "SELECT COUNT(*) FROM users
             WHERE LOWER(username) = LOWER(:username) AND registration_status = 'active'"
        );
        $activeUserCount->execute([':username' => $prefix . 'rejected']);
        migrationTestAssert((int)$activeUserCount->fetchColumn() === 1, 'Duplicate active username exists after reuse.');

        $activeEmailCount = $conn->prepare(
            "SELECT COUNT(*) FROM users
             WHERE LOWER(email) = LOWER(:email) AND registration_status = 'active'"
        );
        $activeEmailCount->execute([':email' => $prefix . 'rejected@example.test']);
        migrationTestAssert((int)$activeEmailCount->fetchColumn() === 1, 'Duplicate active email exists after reuse.');

        echo 'Second-run checks passed; replacement login works and rejected history remains.' . PHP_EOL;
        exit(0);
    }

    if ($phase === 'reuse') {
        migrationTestAssert(registrationIdentityReleaseEnabled($conn), 'Run the migration before re-registration.');
        $replacementPassword = 'ReplacementMember#1';
        $replacementHash = password_hash($replacementPassword, PASSWORD_DEFAULT);
        migrationTestInsertUser(
            $conn,
            $prefix . 'rejected',
            $prefix . 'rejected@example.test',
            $replacementHash,
            'member',
            $prefix . 'replacement-token'
        );
        migrationTestInsertRequest(
            $conn,
            $prefix . 'rejected',
            $prefix . 'rejected@example.test',
            $replacementHash,
            'monthly',
            'pending'
        );
        echo 'Replacement registration reused the rejected username and email.' . PHP_EOL;
        exit(0);
    }

    if ($phase === 'verify-email-semantics') {
        migrationTestAssert(registrationIdentityReleaseEnabled($conn), 'Run the migration before checking email uniqueness.');

        $conn->beginTransaction();
        migrationTestInsertUser(
            $conn,
            $prefix . 'blank1',
            '',
            password_hash('BlankEmail#1', PASSWORD_DEFAULT),
            'member',
            $prefix . 'blank-email-token'
        );
        try {
            migrationTestInsertUser(
                $conn,
                $prefix . 'blank2',
                '',
                password_hash('BlankEmail#2', PASSWORD_DEFAULT),
                'member',
                $prefix . 'blank-email-token-two'
            );
            throw new RuntimeException('Active empty-string emails were no longer unique.');
        } catch (PDOException $e) {
            migrationTestAssert((string)$e->getCode() === '23000', 'Duplicate blank email failed for an unexpected reason.');
        }

        migrationTestInsertUser(
            $conn,
            $prefix . 'null1',
            null,
            password_hash('NullEmail#1', PASSWORD_DEFAULT),
            'member',
            $prefix . 'null-email-token-one'
        );
        migrationTestInsertUser(
            $conn,
            $prefix . 'null2',
            null,
            password_hash('NullEmail#2', PASSWORD_DEFAULT),
            'member',
            $prefix . 'null-email-token-two'
        );
        $conn->commit();
        echo 'Active empty-string email uniqueness and repeatable NULL emails passed.' . PHP_EOL;
        exit(0);
    }

    throw new InvalidArgumentException('Use one of: seed, verify-first, reuse, verify-second, verify-email-semantics.');
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
