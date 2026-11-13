<?php

$databaseName = getenv('TRAINER_ROLE_TEST_DB') ?: '';
$baseUrl = rtrim(getenv('TRAINER_ROLE_TEST_URL') ?: '', '/');
$host = getenv('TRAINER_ROLE_TEST_HOST') ?: '127.0.0.1';
$port = getenv('TRAINER_ROLE_TEST_PORT') ?: '3306';
$username = getenv('TRAINER_ROLE_TEST_USER') ?: 'root';
$password = getenv('TRAINER_ROLE_TEST_PASSWORD') ?: '';
if (!preg_match('/^gym_trainer_role_test_[a-z0-9_]+$/D', $databaseName) || $baseUrl === '') {
    fwrite(STDERR, "Trainer Role Management HTTP test environment is incomplete.\n");
    exit(2);
}

$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $databaseName) {
    throw new RuntimeException('Tests require the explicitly named disposable schema.');
}

$assertions = 0;
$suffix = bin2hex(random_bytes(4));
$passwordBefore = 'Trainer-before-reset-123';
$passwordAfter = 'Trainer-after-reset-456';
$trainerToken = 'trainer-old-token-' . $suffix;
$secondTrainerToken = 'trainer-second-token-' . $suffix;
$memberToken = 'member-old-token-' . $suffix;
$adminToken = 'admin-test-token-' . $suffix;

function trainerRoleAssert(bool $condition, string $description): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    $assertions++;
    echo 'PASS: ' . $description . PHP_EOL;
}

function trainerRoleHttp(string $method, string $path, ?array $payload = null, ?string $token = null, array $extraHeaders = []): array
{
    global $baseUrl;
    $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
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
        throw new RuntimeException('The local API returned non-JSON data: ' . $body);
    }
    echo "HTTP {$method} {$path}: {$status} " . json_encode($decoded, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return ['status' => $status, 'body' => $decoded];
}

function trainerRoleInsertUser(PDO $conn, string $role, string $username, string $email, string $plainPassword, ?string $token): int
{
    $insert = $conn->prepare(
        'INSERT INTO users
            (first_name, middle_name, last_name, full_name, username, email, password, role,
             registration_status, account_status, auth_token)
         VALUES
            (:first_name, NULL, :last_name, :full_name, :username, :email, :password, :role,
             "active", "active", :auth_token)'
    );
    $name = ucfirst($role) . ' ' . ucfirst($username);
    $insert->execute([
        ':first_name' => ucfirst($role),
        ':last_name' => ucfirst($username),
        ':full_name' => $name,
        ':username' => $username,
        ':email' => $email,
        ':password' => password_hash($plainPassword, PASSWORD_DEFAULT),
        ':role' => $role,
        ':auth_token' => $token,
    ]);
    $id = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $id]);
    return $id;
}

function trainerRoleInsertProfile(PDO $conn, int $userId, string $code, string $status = 'active', string $phone = '09123456789'): int
{
    $insert = $conn->prepare(
        'INSERT INTO trainers (user_id, trainer_code, status, phone, created_at)
         VALUES (:user_id, :code, :status, :phone, NOW())'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':code' => $code,
        ':status' => $status,
        ':phone' => $phone,
    ]);
    return (int)$conn->lastInsertId();
}

function trainerRoleLogin(string $identifier, string $plainPassword): array
{
    return trainerRoleHttp('POST', '/login.php', ['username' => $identifier, 'password' => $plainPassword]);
}

$conn->exec("SET time_zone = '+08:00'");
$adminId = trainerRoleInsertUser($conn, 'admin', 'admin' . $suffix, 'admin-' . $suffix . '@example.test', 'Admin-password-123', $adminToken);
$trainerId = trainerRoleInsertUser($conn, 'trainer', 'trainer' . $suffix, 'trainer-' . $suffix . '@example.test', $passwordBefore, $trainerToken);
$trainerProfileId = trainerRoleInsertProfile($conn, $trainerId, 'TRN-TEST-' . $suffix);
$availabilityInsert = $conn->prepare(
    "INSERT INTO trainer_availability
        (trainer_id, day_of_week, start_time, end_time, schedule_type, status)
     VALUES (:trainer_id, 'Monday', '09:00:00', '12:00:00', 'custom', 'active')"
);
$availabilityInsert->execute([':trainer_id' => $trainerProfileId]);
$trainerAvailabilityId = (int)$conn->lastInsertId();
$trainerAttendanceInsert = $conn->prepare(
    "INSERT INTO trainer_attendance
        (trainer_id, availability_id, attendance_date, scheduled_start, scheduled_end, time_in, status)
     VALUES (:trainer_id, :availability_id, '2026-11-02', '09:00:00', '12:00:00', '09:01:00', 'present')"
);
$trainerAttendanceInsert->execute([
    ':trainer_id' => $trainerProfileId,
    ':availability_id' => $trainerAvailabilityId,
]);
$secondTrainerId = trainerRoleInsertUser($conn, 'trainer', 'trainer2' . $suffix, 'trainer2-' . $suffix . '@example.test', $passwordBefore, $secondTrainerToken);
$secondTrainerProfileId = trainerRoleInsertProfile($conn, $secondTrainerId, 'TRN-TEST2-' . $suffix);
$inactiveId = trainerRoleInsertUser($conn, 'trainer', 'inactive' . $suffix, 'inactive-' . $suffix . '@example.test', $passwordBefore, 'inactive-token-' . $suffix);
trainerRoleInsertProfile($conn, $inactiveId, 'TRN-I-' . $suffix, 'inactive');
$memberId = trainerRoleInsertUser($conn, 'member', 'member' . $suffix, 'member-' . $suffix . '@example.test', 'Member-password-123', $memberToken);
$memberInsert = $conn->prepare(
    "INSERT INTO members
        (user_id, member_code, full_name, email, phone, membership_type, member_category,
         membership_start, membership_end, status, account_status)
     VALUES
        (:user_id, :member_code, :full_name, :email, '09111111111', 'monthly', 'regular',
         '2026-01-01', '2027-01-01', 'active', 'active')"
);
$memberInsert->execute([
    ':user_id' => $memberId,
    ':member_code' => 'MEM-TEST-' . $suffix,
    ':full_name' => 'Member ' . $suffix,
    ':email' => 'member-' . $suffix . '@example.test',
]);
$memberProfileId = (int)$conn->lastInsertId();
$attendance = $conn->prepare(
    "INSERT INTO attendance (member_id, attendance_date, time_in, checkout_type)
     VALUES (:member_id, '2026-11-01', '09:00:00', 'manual')"
);
$attendance->execute([':member_id' => $memberProfileId]);
$attendanceId = (int)$conn->lastInsertId();

trainerRoleAssert($adminId > 0 && $trainerProfileId > 0 && $secondTrainerProfileId > 0, 'disposable linked Admin and Trainer fixtures were created');

$unauthenticated = trainerRoleHttp('GET', '/trainers.php');
$memberLogin = trainerRoleLogin('member' . $suffix, 'Member-password-123');
$trainerLogin = trainerRoleLogin('trainer' . $suffix, $passwordBefore);
$inactiveLogin = trainerRoleLogin('inactive' . $suffix, $passwordBefore);
trainerRoleAssert($unauthenticated['status'] === 401, 'unauthenticated Trainer Management access is rejected');
trainerRoleAssert($memberLogin['status'] === 200 && ($memberLogin['body']['role'] ?? '') === 'member', 'active Member login remains operational');
trainerRoleAssert($trainerLogin['status'] === 200 && ($trainerLogin['body']['role'] ?? '') === 'trainer', 'active Trainer login issues a token');
trainerRoleAssert($inactiveLogin['status'] === 403, 'inactive Trainer login is rejected');

$memberToken = (string)$memberLogin['body']['auth_token'];
$trainerToken = (string)$trainerLogin['body']['auth_token'];
$adminHeaders = ['X-Auth-Token: ' . $adminToken, 'Authorization: Bearer ' . $adminToken];
$trainerForbidden = trainerRoleHttp('GET', '/trainers.php', null, $trainerToken);
$memberForbidden = trainerRoleHttp('GET', '/trainers.php', null, $memberToken);
trainerRoleAssert($trainerForbidden['status'] === 403 && $memberForbidden['status'] === 403, 'Trainer and Member roles cannot access Admin-only Trainer management');

$list = trainerRoleHttp('GET', '/trainers.php?search=' . rawurlencode('trainer-' . $suffix) . '&page=1&per_page=10', null, $adminToken);
trainerRoleAssert($list['status'] === 200 && count($list['body']['trainers'] ?? []) === 1, 'Admin can list and search Trainer records');

$newUsername = 'newtrainer' . $suffix;
$newEmail = 'new-trainer-' . $suffix . '@example.test';
$created = trainerRoleHttp('POST', '/trainers.php?action=create', [
    'first_name' => 'New',
    'last_name' => 'Trainer',
    'username' => $newUsername,
    'email' => $newEmail,
    'phone' => '09222222222',
    'password' => 'New-trainer-password-123',
    'confirm_password' => 'New-trainer-password-123',
    'availability' => [['day_of_week' => 'Monday', 'start_time' => '09:00', 'end_time' => '12:00']],
], $adminToken);
$createdUserStmt = $conn->prepare('SELECT id FROM users WHERE username = :username AND role = "trainer"');
$createdUserStmt->execute([':username' => $newUsername]);
$createdUserId = (int)$createdUserStmt->fetchColumn();
$createdTrainerStmt = $conn->prepare('SELECT id FROM trainers WHERE user_id = :user_id');
$createdTrainerStmt->execute([':user_id' => $createdUserId]);
$createdTrainerId = (int)$createdTrainerStmt->fetchColumn();
trainerRoleAssert(
    $created['status'] === 200 && $created['body']['success'] === true
        && $createdUserId > 0 && $createdTrainerId > 0
        && (int)$conn->query('SELECT COUNT(*) FROM trainer_availability WHERE trainer_id = ' . $createdTrainerId)->fetchColumn() === 1,
    'Admin creates a linked Trainer with initial availability'
);

$updated = trainerRoleHttp('POST', '/trainers.php?action=update', [
    'id' => $createdTrainerId,
    'first_name' => 'Updated',
    'last_name' => 'Trainer',
    'email' => $newEmail,
    'phone' => '09333333333',
], $adminToken);
$updatedUser = $conn->query('SELECT first_name, email FROM users WHERE id = ' . $createdUserId)->fetch(PDO::FETCH_ASSOC);
trainerRoleAssert(
    $updated['status'] === 200 && $updatedUser['first_name'] === 'Updated'
        && $updatedUser['email'] === $newEmail,
    'Admin can edit linked Trainer information'
);

$profile = trainerRoleHttp('GET', '/trainer_profile.php', null, $trainerToken, ['X-User-Email: trainer2-' . $suffix . '@example.test']);
trainerRoleAssert(
    $profile['status'] === 200
        && (int)($profile['body']['trainer']['user_id'] ?? 0) === $trainerId
        && ($profile['body']['trainer']['email'] ?? '') === 'trainer-' . $suffix . '@example.test',
    'Trainer profile resolves by authenticated user_id and ignores another account email header'
);
$selfProfileUpdate = trainerRoleHttp('PUT', '/trainer_profile.php', [
    'first_name' => 'SelfUpdated',
    'middle_name' => '',
    'last_name' => 'Trainer',
    'phone' => '09444444444',
], $trainerToken);
$selfProfileState = $conn->query(
    'SELECT u.full_name, t.phone FROM users u JOIN trainers t ON t.user_id = u.id WHERE u.id = ' . $trainerId
)->fetch(PDO::FETCH_ASSOC);
trainerRoleAssert(
    $selfProfileUpdate['status'] === 200
        && ($selfProfileState['full_name'] ?? '') === 'SelfUpdated Trainer'
        && ($selfProfileState['phone'] ?? '') === '09444444444',
    'Trainer self-service profile updates remain linked to the authenticated user'
);

$adminProfile = trainerRoleHttp(
    'GET',
    '/trainer_profile.php',
    null,
    $adminToken,
    ['X-User-Email: trainer2-' . $suffix . '@example.test']
);
trainerRoleAssert(
    $adminProfile['status'] === 200
        && (int)($adminProfile['body']['trainer']['user_id'] ?? 0) === $secondTrainerId,
    'Admin retains authorized access to a selected linked Trainer profile'
);

$orphanId = trainerRoleInsertUser($conn, 'trainer', 'orphan' . $suffix, 'orphan-' . $suffix . '@example.test', $passwordBefore, null);
$orphanProfile = trainerRoleHttp('GET', '/trainer_profile.php', ['email' => 'orphan-' . $suffix . '@example.test'], $adminToken);
trainerRoleAssert(
    $orphanProfile['status'] === 404
        && (int)$conn->query('SELECT COUNT(*) FROM trainers WHERE user_id = ' . $orphanId)->fetchColumn() === 0,
    'Admin cannot retrieve a Trainer profile without a valid user_id relationship'
);
$orphanLogin = trainerRoleLogin('orphan' . $suffix, $passwordBefore);
trainerRoleAssert($orphanLogin['status'] === 403, 'Trainer account without a linked Trainer profile cannot authenticate');

$profileIsolation = trainerRoleHttp('GET', '/trainer_profile.php', ['email' => 'trainer2-' . $suffix . '@example.test'], $trainerToken);
trainerRoleAssert(
    $profileIsolation['status'] === 200
        && (int)($profileIsolation['body']['trainer']['user_id'] ?? 0) === $trainerId,
    'Trainer cannot select another Trainer profile through client-supplied email'
);

$availability = trainerRoleHttp('GET', '/trainer_availability.php', null, $trainerToken);
trainerRoleAssert($availability['status'] === 200 && isset($availability['body']['schedule']), 'Trainer can retrieve their own schedule');
$attendanceDashboard = trainerRoleHttp('GET', '/trainer_attendance.php', null, $trainerToken);
trainerRoleAssert($attendanceDashboard['status'] === 200 && isset($attendanceDashboard['body']['schedule']), 'Trainer attendance dashboard endpoint remains accessible');
$bookingList = trainerRoleHttp('GET', '/bookings.php', null, $trainerToken);
trainerRoleAssert($bookingList['status'] === 200 && is_array($bookingList['body']['bookings'] ?? null), 'Trainer can retrieve their authorized booking list');
$qrValidation = trainerRoleHttp('POST', '/trainer_attendance_qr.php', [
    'operation' => 'preview',
    'token' => 'invalid',
], $trainerToken);
trainerRoleAssert($qrValidation['status'] === 400, 'Trainer QR attendance endpoint still enforces token validation after role authentication');
$records = trainerRoleHttp('GET', '/trainer_attendance_records.php?date=2026-11-01', null, $trainerToken);
trainerRoleAssert($records['status'] === 200, 'Trainer attendance records endpoint remains accessible to the authenticated Trainer');
$feedback = trainerRoleHttp('GET', '/trainer_feedback.php', null, $trainerToken);
trainerRoleAssert($feedback['status'] === 200, 'Trainer feedback endpoint remains accessible to the authenticated Trainer');
$notifications = trainerRoleHttp('GET', '/notifications.php', null, $trainerToken);
trainerRoleAssert($notifications['status'] === 200, 'Trainer notifications endpoint remains accessible to the authenticated Trainer');

$options = trainerRoleHttp('GET', '/trainers.php?action=options', null, $memberToken);
trainerRoleAssert($options['status'] === 200 && count($options['body']['trainers'] ?? []) >= 2, 'Member booking options continue listing active Trainers');
$memberProfile = trainerRoleHttp('GET', '/member_profile.php', null, $memberToken);
trainerRoleAssert(
    $memberProfile['status'] === 200
        && (int)($memberProfile['body']['member']['user_id'] ?? 0) === $memberId,
    'Member login and linked profile access remain operational'
);

$invalidStatus = trainerRoleHttp('POST', '/trainers.php?action=status', ['id' => $trainerProfileId, 'status' => 'paused'], $adminToken);
trainerRoleAssert($invalidStatus['status'] === 400, 'invalid Trainer activation states are rejected instead of silently deactivating');

$deactivated = trainerRoleHttp('POST', '/trainers.php?action=status', ['id' => $trainerProfileId, 'status' => 'inactive'], $adminToken);
$storedStatus = $conn->query('SELECT status FROM trainers WHERE id = ' . $trainerProfileId)->fetchColumn();
$storedToken = $conn->query('SELECT auth_token FROM users WHERE id = ' . $trainerId)->fetchColumn();
$preservedProfile = (int)$conn->query('SELECT COUNT(*) FROM trainers WHERE id = ' . $trainerProfileId)->fetchColumn();
$preservedAvailability = (int)$conn->query('SELECT COUNT(*) FROM trainer_availability WHERE id = ' . $trainerAvailabilityId)->fetchColumn();
$preservedTrainerAttendance = (int)$conn->query(
    'SELECT COUNT(*) FROM trainer_attendance WHERE trainer_id = ' . $trainerProfileId
        . ' AND availability_id = ' . $trainerAvailabilityId
)->fetchColumn();
$oldTokenResponse = trainerRoleHttp('GET', '/trainer_profile.php', null, $trainerToken);
trainerRoleAssert(
    $deactivated['status'] === 200 && $storedStatus === 'inactive' && $storedToken === null
        && $preservedProfile === 1 && $preservedAvailability === 1
        && $preservedTrainerAttendance === 1 && $oldTokenResponse['status'] === 401,
    'deactivation transaction revokes the token, rejects old requests, and preserves Trainer, availability, and attendance records'
);

$reactivated = trainerRoleHttp('POST', '/trainers.php?action=status', ['id' => $trainerProfileId, 'status' => 'active'], $adminToken);
$oldTokenAfterReactivation = trainerRoleHttp('GET', '/trainer_profile.php', null, $trainerToken);
$freshTrainerLogin = trainerRoleLogin('trainer' . $suffix, $passwordBefore);
trainerRoleAssert(
    $reactivated['status'] === 200 && $oldTokenAfterReactivation['status'] === 401
        && $freshTrainerLogin['status'] === 200
        && ($freshTrainerLogin['body']['auth_token'] ?? '') !== $trainerToken,
    'reactivation does not restore the old token and fresh login issues a new token'
);
$trainerToken = (string)$freshTrainerLogin['body']['auth_token'];

$reset = trainerRoleHttp('POST', '/trainers.php?action=reset-password', [
    'id' => $trainerProfileId,
    'password' => $passwordAfter,
], $adminToken);
$resetToken = $conn->query('SELECT auth_token FROM users WHERE id = ' . $trainerId)->fetchColumn();
$oldTokenAfterReset = trainerRoleHttp('GET', '/trainer_profile.php', null, $trainerToken);
$newPasswordLogin = trainerRoleLogin('trainer' . $suffix, $passwordAfter);
trainerRoleAssert(
    $reset['status'] === 200 && $resetToken === null && $oldTokenAfterReset['status'] === 401
        && $newPasswordLogin['status'] === 200
        && !array_key_exists('password', $reset['body']),
    'Trainer Management password reset revokes the session and enables new-password login without exposing secrets'
);
$trainerToken = (string)$newPasswordLogin['body']['auth_token'];

$secondAdminReset = trainerRoleHttp(
    'POST',
    '/admin_reset_password.php',
    ['email' => 'trainer-' . $suffix . '@example.test', 'password' => 'Admin-reset-password-789'],
    $adminToken
);
$tokenAfterAdminReset = $conn->query('SELECT auth_token FROM users WHERE id = ' . $trainerId)->fetchColumn();
$newAdminResetLogin = trainerRoleLogin('trainer' . $suffix, 'Admin-reset-password-789');
trainerRoleAssert(
    $secondAdminReset['status'] === 200 && $tokenAfterAdminReset === null
        && $newAdminResetLogin['status'] === 200,
    'General Admin password reset revokes Trainer token while preserving password reset functionality'
);
$trainerToken = (string)$newAdminResetLogin['body']['auth_token'];

$memberAdminReset = trainerRoleHttp(
    'POST',
    '/admin_reset_password.php',
    ['email' => 'member-' . $suffix . '@example.test', 'password' => 'Member-reset-password-123'],
    $adminToken
);
$memberTokenAfterReset = $conn->query('SELECT auth_token FROM users WHERE id = ' . $memberId)->fetchColumn();
trainerRoleAssert(
    $memberAdminReset['status'] === 200 && $memberTokenAfterReset === $memberToken,
    'general Admin password management leaves Member session behavior unchanged'
);

$trainerAdminResetForbidden = trainerRoleHttp(
    'POST',
    '/admin_reset_password.php',
    ['email' => 'trainer-' . $suffix . '@example.test', 'password' => 'Forbidden-reset-password-123'],
    $trainerToken
);
$promotionForbidden = trainerRoleHttp('POST', '/member_to_trainer.php', ['member_id' => $memberProfileId], $memberToken);
trainerRoleAssert(
    $trainerAdminResetForbidden['status'] === 403 && $promotionForbidden['status'] === 403,
    'Trainer password administration and Member role promotion remain Admin-only'
);

$memberAttendanceBefore = (int)$conn->query('SELECT COUNT(*) FROM attendance WHERE member_id = ' . $memberProfileId)->fetchColumn();
$promoted = trainerRoleHttp('POST', '/member_to_trainer.php', ['member_id' => $memberProfileId], $adminToken);
$promotionUser = $conn->query('SELECT role, auth_token FROM users WHERE id = ' . $memberId)->fetch(PDO::FETCH_ASSOC);
$promotionTrainerCount = (int)$conn->query('SELECT COUNT(*) FROM trainers WHERE user_id = ' . $memberId)->fetchColumn();
$promotionAuditCount = (int)$conn->query('SELECT COUNT(*) FROM member_trainer_promotion_audit WHERE target_user_id = ' . $memberId)->fetchColumn();
$memberAttendanceAfter = (int)$conn->query('SELECT COUNT(*) FROM attendance WHERE member_id = ' . $memberProfileId)->fetchColumn();
trainerRoleAssert(
    $promoted['status'] === 200 && $promotionUser['role'] === 'trainer'
        && $promotionUser['auth_token'] === null && $promotionTrainerCount === 1
        && $promotionAuditCount === 1 && $memberAttendanceAfter === $memberAttendanceBefore
        && $attendanceId > 0,
    'promotion creates exactly one linked Trainer, revokes old token, audits transition, and preserves Member history'
);
$memberTokenAfterPromotion = trainerRoleHttp('GET', '/member_profile.php', null, $memberToken);
trainerRoleAssert($memberTokenAfterPromotion['status'] === 401, 'promoted Member cannot continue with the old Member token');

$duplicateRelationshipUser = trainerRoleInsertUser($conn, 'trainer', 'duplicate' . $suffix, 'duplicate-' . $suffix . '@example.test', $passwordBefore, null);
trainerRoleInsertProfile($conn, $duplicateRelationshipUser, 'TRN-DUP1-' . $suffix);
$duplicateRelationshipAllowed = true;
try {
    trainerRoleInsertProfile($conn, $duplicateRelationshipUser, 'TRN-DUP2-' . $suffix);
} catch (PDOException $error) {
    $duplicateRelationshipAllowed = false;
}
$duplicateCount = (int)$conn->query('SELECT COUNT(*) FROM trainers WHERE user_id = ' . $duplicateRelationshipUser)->fetchColumn();
$trainerUserIndex = $conn->query(
    "SELECT NON_UNIQUE FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trainers' AND COLUMN_NAME = 'user_id'
     ORDER BY SEQ_IN_INDEX LIMIT 1"
)->fetchColumn();
trainerRoleAssert(
    $duplicateRelationshipAllowed && $duplicateCount === 2 && (int)$trainerUserIndex === 1,
    'disposable schema confirms duplicate Trainer profiles per user are currently permitted by the non-unique user_id index'
);
$duplicatePromotion = trainerRoleHttp('POST', '/member_to_trainer.php', ['member_id' => $memberProfileId], $adminToken);
trainerRoleAssert($duplicatePromotion['status'] === 409, 'promotion rejects a target account that already has a Trainer profile');

echo "All Trainer Role Management HTTP integration checks passed: {$assertions} assertions against {$databaseName}." . PHP_EOL;
