<?php

if (getenv('DUPLICATE_NAME_REGISTRATION_TEST_TEMP_TABLES') !== '1') {
    echo "Skipped duplicate-name database tests; set DUPLICATE_NAME_REGISTRATION_TEST_TEMP_TABLES=1 to run temporary-table cases." . PHP_EOL;
    exit(0);
}

require_once __DIR__ . '/../config/duplicate_name_registration.php';
require_once __DIR__ . '/../config/registration_workflow.php';

$testDatabaseName = getenv('GYM_TEST_DB_NAME') ?: '';
if (!preg_match('/^gym_management_test_migration_[0-9]{8}_[0-9]{4}$/', $testDatabaseName)) {
    fwrite(STDERR, "Set GYM_TEST_DB_NAME to a disposable gym_management_test_migration_YYYYMMDD_HHMM database." . PHP_EOL);
    exit(1);
}
$testDbHost = getenv('GYM_TEST_DB_HOST') ?: 'localhost';
$testDbPort = getenv('GYM_TEST_DB_PORT') ?: '3306';
$testDbUser = getenv('GYM_TEST_DB_USER') ?: 'root';
$testDbPassword = getenv('GYM_TEST_DB_PASSWORD') ?: '';
$testDsn = "mysql:host={$testDbHost};port={$testDbPort};dbname={$testDatabaseName}";
$conn = new PDO($testDsn, $testDbUser, $testDbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$conn->exec(
    "CREATE TEMPORARY TABLE users (
        id INT NOT NULL PRIMARY KEY,
        username VARCHAR(30),
        email VARCHAR(100),
        role VARCHAR(16),
        registration_status VARCHAR(16),
        registration_unique_username VARCHAR(30)
            GENERATED ALWAYS AS (
                CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(username) END
            ) STORED,
        registration_unique_email VARCHAR(100)
            GENERATED ALWAYS AS (
                CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(email) END
            ) STORED,
        UNIQUE KEY uq_users_registration_username (registration_unique_username),
        UNIQUE KEY uq_users_registration_email (registration_unique_email)
    )"
);
$conn->exec(
    'CREATE TEMPORARY TABLE members (
        id INT NOT NULL PRIMARY KEY,
        user_id INT NULL,
        email VARCHAR(100),
        first_name VARCHAR(50),
        last_name VARCHAR(50)
    )'
);
$conn->exec(
    "CREATE TEMPORARY TABLE registration_requests (
        id INT NOT NULL PRIMARY KEY,
        first_name VARCHAR(50),
        last_name VARCHAR(50),
        status VARCHAR(16),
        username VARCHAR(30),
        email VARCHAR(100),
        member_id INT NULL,
        registration_unique_username VARCHAR(30)
            GENERATED ALWAYS AS (
                CASE WHEN status = 'rejected' THEN NULL ELSE LOWER(username) END
            ) STORED,
        UNIQUE KEY uq_registration_requests_active_username (registration_unique_username)
    )"
);
$conn->exec(
    'CREATE TEMPORARY TABLE duplicate_name_registration_exceptions (
        id INT NOT NULL PRIMARY KEY,
        first_name VARCHAR(50),
        last_name VARCHAR(50),
        status VARCHAR(16),
        username VARCHAR(30),
        email VARCHAR(100),
        registration_request_id INT NULL
    )'
);
$conn->exec(
    'CREATE TEMPORARY TABLE approval_members (
        id INT NOT NULL PRIMARY KEY,
        email VARCHAR(100)
    )'
);
$conn->exec(
    "INSERT INTO users (id, username, email, role, registration_status)
     VALUES
        (1, 'active_jane', 'jane@example.com', 'member', 'active'),
        (2, 'rejected_member', 'rejected-member@example.com', 'member', 'rejected'),
        (8, 'gmail_member', 'gym.test@gmail.com', 'member', 'active'),
        (9, 'outlook_member', 'gym.test@outlook.com', 'member', 'active')"
);
$conn->exec(
    "INSERT INTO members (id, user_id, email, first_name, last_name)
     VALUES
        (1, 1, 'jane@example.com', 'Jane   Marie', 'DOE'),
        (2, 2, 'rejected-member@example.com', 'Rejected Member', 'Applicant'),
        (3, NULL, 'orphan-member@example.com', 'Legacy Member', 'Applicant')"
);
$conn->exec(
    "INSERT INTO registration_requests (id, first_name, last_name, status, username, email, member_id)
     VALUES
        (2, 'Samuel', 'Brown', 'pending', 'pending_user', 'pending@example.com', NULL),
        (3, 'Rejected', 'Applicant', 'rejected', 'rejected_applicant', 'rejected@example.com', NULL),
        (7, 'Approved', 'Applicant', 'approved', 'approved_applicant', 'approved@example.com', NULL),
        (8, 'Jane', 'Renewal', 'approved', 'jane_renewal', 'jane@example.com', 1)"
);
$conn->exec(
    "INSERT INTO duplicate_name_registration_exceptions (id, first_name, last_name, status)
     VALUES (4, 'Pending', 'Exception', 'pending')"
);
$conn->exec(
    "INSERT INTO duplicate_name_registration_exceptions
        (id, first_name, last_name, status, username, email, registration_request_id)
     VALUES
        (5, 'Authorized', 'Applicant', 'authorized', 'authorized_user', 'authorized@example.com', NULL),
        (6, 'Completed', 'Applicant', 'authorized', 'completed_user', 'completed@example.com', 99)"
);

$failures = [];
$cases = [
    'existing member ignores case and repeated spaces' => [
        'jane marie',
        '  doe ',
        'member',
    ],
    'pending application is a duplicate' => [
        'SAMUEL',
        'Brown',
        'application',
    ],
    'pending duplicate exception remains blocked' => [
        'Pending',
        'Exception',
        'duplicate_exception',
    ],
];

foreach ($cases as $name => [$firstName, $lastName, $expectedType]) {
    $match = findDuplicateNameRegistrationRecord($conn, $firstName, $lastName);
    if (($match['record_type'] ?? null) !== $expectedType) {
        $failures[] = $name;
    }
}

foreach ([
    ['same first name, different last name', 'Jane Marie', 'Smith'],
    ['same last name, different first name', 'Alex', 'Doe'],
    ['rejected member account does not count as a name duplicate', 'Rejected Member', 'Applicant'],
    ['rejected application does not count as pending', 'Rejected', 'Applicant'],
    ['authorized exception is no longer a blocking name duplicate', 'Authorized', 'Applicant'],
] as [$name, $firstName, $lastName]) {
    if (findDuplicateNameRegistrationRecord($conn, $firstName, $lastName) !== null) {
        $failures[] = $name;
    }
}

$authorizedAccountCase = findPendingDuplicateNameRegistrationByAccount(
    $conn,
    'authorized_user',
    'authorized@example.com'
);
if ((int)($authorizedAccountCase['id'] ?? 0) !== 5 || $authorizedAccountCase['status'] !== 'authorized') {
    $failures[] = 'authorized exception can be resumed by its account identifiers';
}
if (findPendingDuplicateNameRegistrationByAccount($conn, 'completed_user', 'completed@example.com') !== null) {
    $failures[] = 'completed exception cannot be submitted a second time';
}

$conflicts = [
    'active account username remains reserved' => ['active_jane', '', 'username'],
    'rejected account username is released' => ['rejected_member', '', null],
    'pending application username remains reserved' => ['pending_user', '', 'username'],
    'approved application username remains reserved' => ['approved_applicant', '', 'username'],
    'pending application email remains reserved' => ['new_user', 'pending@example.com', 'email'],
    'approved application email remains reserved' => ['new_user', 'approved@example.com', 'email'],
    'rejected application email is released' => ['new_user', 'rejected@example.com', null],
    'active member email remains reserved' => ['new_user', 'jane@example.com', 'email'],
    'rejected member email is released' => ['new_user', 'rejected-member@example.com', null],
    'unlinked legacy member email does not identify an account' => ['new_user', 'orphan-member@example.com', null],
    'Gmail active account email remains reserved' => ['new_user', 'GYM.TEST@gmail.com', 'email'],
    'Outlook active account email remains reserved' => ['new_user', 'GYM.TEST@outlook.com', 'email'],
];
foreach ($conflicts as $name => [$username, $email, $expected]) {
    if (findRegistrationAccountIdentifierConflict($conn, $username, $email) !== $expected) {
        $failures[] = $name;
    }
}

if (findRegistrationEmailConflict($conn, 'jane@example.com', 1, 10, 1)) {
    $failures[] = 'member renewal ignores its own account and prior requests';
}
$conn->exec(
    "INSERT INTO registration_requests (id, first_name, last_name, status, username, email, member_id)
     VALUES (9, 'Other', 'Member', 'approved', 'other_member_request', 'jane@example.com', 99)"
);
if (!findRegistrationEmailConflict($conn, 'jane@example.com', 1, 10, 1)) {
    $failures[] = 'member renewal still conflicts with another member request';
}

$noMemberStmt = $conn->prepare('SELECT id FROM approval_members WHERE email = :email LIMIT 1');
$noMemberStmt->execute([':email' => 'approval.test@gmail.com']);
$pdoNoMemberResult = $noMemberStmt->fetch(PDO::FETCH_ASSOC);
$approvalHash = password_hash('Disposable approval test password', PASSWORD_DEFAULT);
$approvalRequest = [
    'membership_type' => 'monthly',
    'registration_source' => 'online',
    'status' => 'pending',
    'email' => 'approval.test@gmail.com',
    'password' => $approvalHash,
];
$approvalUser = [
    'role' => 'member',
    'email' => 'APPROVAL.TEST@GMAIL.COM',
    'password' => $approvalHash,
];
if ($pdoNoMemberResult !== false) {
    $failures[] = 'PDO returns false when no Member row matches';
}
if (!isNewInitialMonthlyRegistrationAccount(false, 'monthly', $approvalRequest, $approvalUser, $pdoNoMemberResult)) {
    $failures[] = 'PDO false no-member result recognizes the matching initial monthly account';
}

try {
    $conn->exec(
        "INSERT INTO users (id, username, email, role, registration_status)
         VALUES (3, 'rejected_member', 'rejected-member@example.com', 'member', 'active')"
    );
} catch (PDOException $e) {
    $failures[] = 'new active account can reuse rejected user identifiers';
}
try {
    $conn->exec(
        "INSERT INTO registration_requests (id, first_name, last_name, status, username, email)
         VALUES (4, 'Reapplied', 'Applicant', 'pending', 'rejected_applicant', 'rejected@example.com')"
    );
} catch (PDOException $e) {
    $failures[] = 'new application can reuse rejected request identifiers';
}
try {
    $conn->exec(
        "INSERT INTO users (id, username, email, role, registration_status)
         VALUES (5, 'active_jane', 'another@example.com', 'member', 'active')"
    );
    $failures[] = 'active username uniqueness is preserved';
} catch (PDOException $e) {
    if ((string)$e->getCode() !== '23000') {
        $failures[] = 'active username uniqueness returned a database error';
    }
}
try {
    $conn->exec(
        "INSERT INTO users (id, username, email, role, registration_status)
         VALUES (7, 'another_active_user', 'jane@example.com', 'member', 'active')"
    );
    $failures[] = 'active email uniqueness is preserved';
} catch (PDOException $e) {
    if ((string)$e->getCode() !== '23000') {
        $failures[] = 'active email uniqueness returned a database error';
    }
}
$secondConnection = new PDO($testDsn, $testDbUser, $testDbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lockName = duplicateNameRegistrationLockKey('  Concurrent ', 'NAME');
$firstLock = $conn->prepare('SELECT GET_LOCK(:name, 0)');
$firstLock->execute([':name' => $lockName]);
$firstAcquired = (int)$firstLock->fetchColumn() === 1;
$secondLock = $secondConnection->prepare('SELECT GET_LOCK(:name, 0)');
$secondLock->execute([':name' => $lockName]);
$secondBlocked = (int)$secondLock->fetchColumn() === 0;
$releaseFirst = $conn->prepare('SELECT RELEASE_LOCK(:name)');
$releaseFirst->execute([':name' => $lockName]);
$secondLock->execute([':name' => $lockName]);
$secondAcquired = (int)$secondLock->fetchColumn() === 1;
$releaseSecond = $secondConnection->prepare('SELECT RELEASE_LOCK(:name)');
$releaseSecond->execute([':name' => $lockName]);

if (!$firstAcquired || !$secondBlocked || !$secondAcquired) {
    $failures[] = 'simultaneous name submissions serialize on the same lock';
}

try {
    $conn->exec("INSERT INTO users (id, username, email, role, registration_status) VALUES (10, 'blank_email_one', '', 'member', 'active')");
    try {
        $conn->exec("INSERT INTO users (id, username, email, role, registration_status) VALUES (11, 'blank_email_two', '', 'member', 'active')");
        $failures[] = 'active empty-string email uniqueness is preserved';
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') {
            $failures[] = 'empty-string email uniqueness returned a database error';
        }
    }
    $conn->exec("INSERT INTO users (id, username, email, role, registration_status) VALUES (12, 'null_email_one', NULL, 'member', 'active'), (13, 'null_email_two', NULL, 'member', 'active')");
} catch (PDOException $e) {
    $failures[] = 'NULL emails remain repeatable while blank strings remain unique';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed duplicate-name, identity-release, email-nullability, and concurrency cases using disposable test-database temporary tables.' . PHP_EOL;
