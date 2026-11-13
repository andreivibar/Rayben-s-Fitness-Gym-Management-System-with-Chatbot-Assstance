<?php

$databaseName = getenv('ADMIN_PROFILE_TEST_DB');
if (!$databaseName || strpos($databaseName, 'gym_admin_profile_test_') !== 0 || $databaseName === 'gym_management') {
    fwrite(STDERR, "Set ADMIN_PROFILE_TEST_DB to a dedicated gym_admin_profile_test_* database.\n");
    exit(2);
}

$host = getenv('ADMIN_PROFILE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('ADMIN_PROFILE_TEST_PORT') ?: '3306';
$username = getenv('ADMIN_PROFILE_TEST_USER') ?: 'root';
$password = getenv('ADMIN_PROFILE_TEST_PASSWORD') ?: '';
$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$conn->exec(
    "CREATE TEMPORARY TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(50) NOT NULL,
        middle_name VARCHAR(50) NULL,
        last_name VARCHAR(50) NOT NULL,
        full_name VARCHAR(155) NOT NULL,
        email VARCHAR(100) NOT NULL,
        username VARCHAR(30) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'administrator', 'trainer', 'member') NOT NULL,
        auth_token VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        registration_status ENUM('active', 'rejected') NOT NULL DEFAULT 'active',
        registration_unique_email VARCHAR(100)
            GENERATED ALWAYS AS (
                CASE WHEN registration_status = 'rejected' THEN NULL ELSE LOWER(email) END
            ) STORED,
        UNIQUE KEY uq_registration_email (registration_unique_email)
    ) ENGINE=InnoDB"
);

$hash = password_hash('old-password-123', PASSWORD_DEFAULT);
$insert = $conn->prepare(
    'INSERT INTO users
        (id, first_name, middle_name, last_name, full_name, email, username, password, role, auth_token)
     VALUES
        (:id, :first_name, :middle_name, :last_name, :full_name, :email, :username, :password, :role, :auth_token)'
);
$users = [
    [1, 'First', 'Admin', 'One', 'First Admin One', 'admin1@example.test', 'adminone', 'admin', 'admin-token-1'],
    [2, 'Second', null, 'Admin', 'Second Admin', 'admin2@example.test', 'admintwo', 'administrator', 'admin-token-2'],
    [3, 'Member', null, 'One', 'Member One', 'member@example.test', 'memberone', 'member', 'member-token'],
    [4, 'Trainer', null, 'One', 'Trainer One', 'trainer@example.test', 'trainerone', 'trainer', 'trainer-token'],
];
foreach ($users as $user) {
    $insert->execute([
        ':id' => $user[0],
        ':first_name' => $user[1],
        ':middle_name' => $user[2],
        ':last_name' => $user[3],
        ':full_name' => $user[4],
        ':email' => $user[5],
        ':username' => $user[6],
        ':password' => $hash,
        ':role' => $user[7],
        ':auth_token' => $user[8],
    ]);
}

$migration = file_get_contents(__DIR__ . '/../migrations/20261113_add_admin_profile_picture.sql');
if ($migration === false) {
    throw new RuntimeException('Unable to read the Admin profile-picture migration.');
}
$conn->exec($migration);
$conn->exec($migration);
$pictureColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_picture'")->fetch();
if (!$pictureColumn || $pictureColumn['Null'] !== 'YES') {
    throw new RuntimeException('The migration did not create a nullable profile_picture column.');
}
if ((int)$conn->query('SELECT COUNT(*) FROM users WHERE profile_picture IS NULL')->fetchColumn() !== 4) {
    throw new RuntimeException('Existing test accounts were not preserved with NULL profile pictures.');
}

require_once __DIR__ . '/../config/admin_profile_service.php';

function assertAdminProfileTest(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $description);
    }
    echo 'Passed: ' . $description . PHP_EOL;
}

assertAdminProfileTest(
    authenticatedAdminId(['id' => 1, 'role' => 'admin']) === 1,
    'authenticated Admin identity is resolved from the server-side user'
);
try {
    authenticatedAdminId(['id' => 3, 'role' => 'member']);
    throw new RuntimeException('A non-Admin role passed the Admin authorization guard.');
} catch (DomainException $error) {
    assertAdminProfileTest(true, 'non-Admin role is rejected');
}

$profile = fetchAdminProfile($conn, 1);
assertAdminProfileTest(
    $profile && (int)$profile['id'] === 1
        && !array_key_exists('password', $profile)
        && !array_key_exists('auth_token', $profile),
    'profile response omits password and authentication token'
);

$updated = updateAdminProfile($conn, 1, [
    'first_name' => 'Updated',
    'middle_name' => 'A',
    'last_name' => 'Admin',
    'email' => 'updated@example.test',
]);
assertAdminProfileTest(
    $updated['full_name'] === 'Updated A Admin' && $updated['email'] === 'updated@example.test',
    'valid profile edits update only the authenticated Admin'
);

try {
    updateAdminProfile($conn, 1, [
        'first_name' => 'Updated',
        'middle_name' => '',
        'last_name' => 'Admin',
        'email' => 'member@example.test',
    ]);
    throw new RuntimeException('A duplicate active email was accepted.');
} catch (DomainException $error) {
    assertAdminProfileTest(true, 'duplicate active email is rejected');
}

try {
    updateAdminProfile($conn, 1, [
        'first_name' => '',
        'middle_name' => '',
        'last_name' => 'Admin',
        'email' => 'invalid',
    ]);
    throw new RuntimeException('Invalid profile fields were accepted.');
} catch (InvalidArgumentException $error) {
    assertAdminProfileTest(true, 'invalid profile fields are rejected');
}

try {
    updateAdminPassword($conn, 1, [
        'current_password' => 'incorrect',
        'new_password' => 'new-password-123',
        'confirm_password' => 'new-password-123',
    ]);
    throw new RuntimeException('An incorrect current password was accepted.');
} catch (DomainException $error) {
    assertAdminProfileTest(
        $conn->query('SELECT auth_token FROM users WHERE id = 1')->fetchColumn() === 'admin-token-1',
        'incorrect current password does not invalidate the token'
    );
}

try {
    updateAdminPassword($conn, 1, [
        'current_password' => 'old-password-123',
        'new_password' => ' new-password-123',
        'confirm_password' => ' new-password-123',
    ]);
    throw new RuntimeException('A password that login would trim was accepted.');
} catch (InvalidArgumentException $error) {
    assertAdminProfileTest(true, 'new passwords that login would trim are rejected');
}

updateAdminPassword($conn, 1, [
    'current_password' => 'old-password-123',
    'new_password' => 'new-password-123',
    'confirm_password' => 'new-password-123',
]);
$updatedCredentials = $conn->query('SELECT password, auth_token FROM users WHERE id = 1')->fetch();
assertAdminProfileTest(
    password_verify('new-password-123', $updatedCredentials['password'])
        && $updatedCredentials['auth_token'] === null,
    'successful password change hashes the password and invalidates the Admin token'
);
assertAdminProfileTest(
    (int)$conn->query('SELECT COUNT(*) FROM users WHERE id IN (2, 3, 4) AND auth_token IS NOT NULL')->fetchColumn() === 3,
    'other Admin, Member, and Trainer tokens remain unchanged'
);

$pictureTarget = getAdminProfilePictureTarget($conn, ['id' => 1, 'role' => 'admin']);
assertAdminProfileTest(
    (int)$pictureTarget['id'] === 1 && $pictureTarget['admin_code'] === 'ADMIN-1',
    'profile picture target is resolved from the authenticated Admin identity'
);

echo 'Passed: nullable picture migration is safe to rerun and existing test accounts remain intact.' . PHP_EOL;
