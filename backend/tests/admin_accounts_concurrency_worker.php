<?php

$databaseName = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$actorId = (int)($argv[3] ?? 0);
$targetId = (int)($argv[4] ?? 0);
$barrierDirectory = $argv[5] ?? '';
$workerId = $argv[6] ?? '';

if (!preg_match('/^gym_admin_accounts_test_[a-z0-9_]+$/', $databaseName)
    || $databaseName === 'gym_management'
    || !in_array($mode, ['create', 'deactivate'], true)
    || $actorId < 1
    || $barrierDirectory === ''
    || !preg_match('/^[a-z0-9_-]+$/', $workerId)) {
    fwrite(STDERR, "Invalid disposable Admin account test worker arguments.\n");
    exit(2);
}

require_once __DIR__ . '/../config/admin_account_service.php';

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

file_put_contents($barrierDirectory . DIRECTORY_SEPARATOR . 'ready-' . $workerId, 'ready', LOCK_EX);
$releaseFile = $barrierDirectory . DIRECTORY_SEPARATOR . 'go';
$deadline = microtime(true) + 15;
while (!is_file($releaseFile) && microtime(true) < $deadline) {
    usleep(1000);
}
if (!is_file($releaseFile)) {
    fwrite(STDERR, "Concurrent test barrier timed out.\n");
    exit(3);
}

try {
    if ($mode === 'create') {
        $created = createAdminAccount($conn, $actorId, [
            'username' => 'adminrace',
            'first_name' => 'Race',
            'middle_name' => '',
            'last_name' => 'Admin',
            'email' => 'adminrace@example.test',
            'password' => 'Concurrent-password-123',
            'confirm_password' => 'Concurrent-password-123',
        ]);
        echo json_encode(['success' => true, 'id' => $created['id']]);
        exit;
    }

    $updated = updateAdminAccountStatus($conn, $actorId, $targetId, 'deactivated');
    echo json_encode(['success' => true, 'id' => $updated['id']]);
} catch (DomainException $error) {
    echo json_encode(['success' => false, 'conflict' => true]);
} catch (PDOException $error) {
    echo json_encode(['success' => false, 'conflict' => (string)$error->getCode() === '23000']);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . "\n");
    exit(1);
}
