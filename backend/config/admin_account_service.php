<?php

function adminAccountDisplayName(array $account): string
{
    $name = trim(implode(' ', array_filter([
        trim((string)($account['first_name'] ?? '')),
        trim((string)($account['middle_name'] ?? '')),
        trim((string)($account['last_name'] ?? '')),
    ], static fn(string $part): bool => $part !== '')));

    return $name !== '' ? $name : (string)($account['full_name'] ?? '');
}

function listAdminAccounts(PDO $conn): array
{
    $stmt = $conn->query(
        "SELECT admin.id, admin.first_name, admin.middle_name, admin.last_name,
                admin.full_name, admin.username, admin.email, admin.account_status,
                admin.created_at, admin.created_by_admin_id,
                creator.full_name AS creator_full_name,
                creator.username AS creator_username
         FROM users admin
         LEFT JOIN users creator ON creator.id = admin.created_by_admin_id
         WHERE LOWER(TRIM(admin.role)) = 'admin'
         ORDER BY admin.id"
    );
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($accounts as &$account) {
        $account['id'] = (int)$account['id'];
        $account['created_by_admin_id'] = $account['created_by_admin_id'] === null
            ? null
            : (int)$account['created_by_admin_id'];
        $account['full_name'] = adminAccountDisplayName($account);
        $creatorName = trim((string)($account['creator_full_name'] ?? ''));
        if ($creatorName === '') {
            $creatorName = (string)($account['creator_username'] ?? '');
        }
        $account['creator_name'] = $creatorName !== '' ? $creatorName : null;
        unset($account['first_name'], $account['middle_name'], $account['last_name']);
        unset($account['creator_full_name'], $account['creator_username']);
    }
    unset($account);

    return $accounts;
}

function normalizeAdminAccountInput(array $input): array
{
    $username = trim((string)($input['username'] ?? ''));
    $firstName = trim((string)($input['first_name'] ?? ''));
    $middleName = trim((string)($input['middle_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirmation = (string)($input['confirm_password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
        throw new InvalidArgumentException('Username must be 3–30 characters using letters, numbers, or underscores.');
    }
    if ($firstName === '' || mb_strlen($firstName) > 50) {
        throw new InvalidArgumentException('First name is required and must be 50 characters or fewer.');
    }
    if (mb_strlen($middleName) > 50) {
        throw new InvalidArgumentException('Middle name must be 50 characters or fewer.');
    }
    if ($lastName === '' || mb_strlen($lastName) > 50) {
        throw new InvalidArgumentException('Last name is required and must be 50 characters or fewer.');
    }
    $fullName = $firstName . ($middleName !== '' ? ' ' . $middleName : '') . ' ' . $lastName;
    if (mb_strlen($fullName) > 100) {
        throw new InvalidArgumentException('The combined name must be 100 characters or fewer.');
    }
    if ($email === '' || mb_strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address of 100 characters or fewer.');
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        throw new InvalidArgumentException('Password must be between 8 and 72 bytes.');
    }
    if (preg_match('/^\s|\s$/u', $password) === 1) {
        throw new InvalidArgumentException('Password cannot start or end with whitespace.');
    }
    if (!hash_equals($password, $confirmation)) {
        throw new InvalidArgumentException('Password and confirmation do not match.');
    }

    return [
        'username' => $username,
        'first_name' => $firstName,
        'middle_name' => $middleName !== '' ? $middleName : null,
        'last_name' => $lastName,
        'full_name' => $fullName,
        'email' => $email,
        'password' => $password,
    ];
}

function createAdminAccount(PDO $conn, int $creatorAdminId, array $input): array
{
    if ($creatorAdminId < 1) {
        throw new DomainException('An authenticated Admin account is required.');
    }
    $account = normalizeAdminAccountInput($input);

    $creator = $conn->prepare(
        "SELECT id FROM users
         WHERE id = :id AND LOWER(TRIM(role)) = 'admin'
           AND account_status = 'active'
         LIMIT 1"
    );
    $creator->execute([':id' => $creatorAdminId]);
    if (!$creator->fetchColumn()) {
        throw new DomainException('The authenticated Admin account is not active.');
    }

    $duplicates = $conn->prepare(
        'SELECT username, email FROM users
         WHERE registration_unique_username = LOWER(:username)
            OR registration_unique_email = LOWER(:email)
         LIMIT 1'
    );
    $duplicates->execute([
        ':username' => $account['username'],
        ':email' => $account['email'],
    ]);
    $duplicate = $duplicates->fetch(PDO::FETCH_ASSOC);
    if ($duplicate) {
        if (strcasecmp((string)$duplicate['username'], $account['username']) === 0) {
            throw new DomainException('That username is already in use.');
        }
        throw new DomainException('That email address is already associated with an account.');
    }

    $passwordHash = password_hash($account['password'], PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        throw new RuntimeException('Unable to secure the Admin password.');
    }

    $insert = $conn->prepare(
        "INSERT INTO users
            (first_name, middle_name, last_name, full_name, username, email, password,
             role, account_status, created_by_admin_id)
         VALUES
            (:first_name, :middle_name, :last_name, :full_name, :username, :email,
             :password, 'admin', 'active', :created_by_admin_id)"
    );
    $insert->execute([
        ':first_name' => $account['first_name'],
        ':middle_name' => $account['middle_name'],
        ':last_name' => $account['last_name'],
        ':full_name' => $account['full_name'],
        ':username' => $account['username'],
        ':email' => $account['email'],
        ':password' => $passwordHash,
        ':created_by_admin_id' => $creatorAdminId,
    ]);

    $createdId = (int)$conn->lastInsertId();
    $fetch = $conn->prepare(
        "SELECT id, first_name, middle_name, last_name, full_name, username, email,
                account_status, created_at, created_by_admin_id
         FROM users
         WHERE id = :id AND role = 'admin'
         LIMIT 1"
    );
    $fetch->execute([':id' => $createdId]);
    $created = $fetch->fetch(PDO::FETCH_ASSOC);
    if (!$created) {
        throw new RuntimeException('Unable to reload the created Admin account.');
    }
    $created['id'] = (int)$created['id'];
    $created['created_by_admin_id'] = (int)$created['created_by_admin_id'];
    $created['creator_name'] = null;
    $created['full_name'] = adminAccountDisplayName($created);
    unset($created['first_name'], $created['middle_name'], $created['last_name']);

    return $created;
}

function assertAdminDeactivationLeavesActiveAdmin(int $activeAdminCount): void
{
    if ($activeAdminCount <= 1) {
        throw new DomainException('The last active Admin account cannot be deactivated.');
    }
}

function updateAdminAccountStatus(
    PDO $conn,
    int $actorAdminId,
    int $targetAdminId,
    string $requestedStatus
): array {
    if (!in_array($requestedStatus, ['active', 'deactivated'], true)) {
        throw new InvalidArgumentException('Choose a valid Admin account status.');
    }
    if ($actorAdminId < 1 || $targetAdminId < 1) {
        throw new InvalidArgumentException('A valid Admin account is required.');
    }
    if ($requestedStatus === 'deactivated' && $actorAdminId === $targetAdminId) {
        throw new DomainException('You cannot deactivate your own Admin account.');
    }

    $conn->beginTransaction();
    try {
        $admins = $conn->query(
            "SELECT id, account_status FROM users
             WHERE LOWER(TRIM(role)) = 'admin'
             ORDER BY id
             FOR UPDATE"
        )->fetchAll(PDO::FETCH_ASSOC);

        $actorIsActive = false;
        $target = null;
        $activeCount = 0;
        foreach ($admins as $admin) {
            $id = (int)$admin['id'];
            $status = (string)$admin['account_status'];
            if ($id === $actorAdminId && $status === 'active') {
                $actorIsActive = true;
            }
            if ($id === $targetAdminId) {
                $target = ['id' => $id, 'account_status' => $status];
            }
            if ($status === 'active') {
                $activeCount++;
            }
        }

        if (!$actorIsActive) {
            throw new DomainException('The authenticated Admin account is not active.');
        }
        if (!$target) {
            throw new OutOfBoundsException('Admin account not found.');
        }
        if ($target['account_status'] === $requestedStatus) {
            throw new DomainException('The Admin account already has that status.');
        }
        if ($requestedStatus === 'deactivated') {
            assertAdminDeactivationLeavesActiveAdmin($activeCount);
        }

        $update = $conn->prepare(
            "UPDATE users
             SET account_status = :account_status,
                 auth_token = CASE WHEN :deactivated = 'deactivated' THEN NULL ELSE auth_token END
             WHERE id = :id AND role = 'admin' AND account_status = :previous_status"
        );
        $update->execute([
            ':account_status' => $requestedStatus,
            ':deactivated' => $requestedStatus,
            ':id' => $targetAdminId,
            ':previous_status' => $target['account_status'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('The Admin account status changed before this request completed. Refresh and try again.');
        }
        $conn->commit();
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }

    $fetch = $conn->prepare(
        "SELECT id, first_name, middle_name, last_name, full_name, username, email,
                account_status, created_at, created_by_admin_id
         FROM users
         WHERE id = :id AND role = 'admin'
         LIMIT 1"
    );
    $fetch->execute([':id' => $targetAdminId]);
    $updated = $fetch->fetch(PDO::FETCH_ASSOC);
    if (!$updated) {
        throw new OutOfBoundsException('Admin account not found after the status update.');
    }
    $updated['id'] = (int)$updated['id'];
    $updated['created_by_admin_id'] = $updated['created_by_admin_id'] === null
        ? null
        : (int)$updated['created_by_admin_id'];
    $updated['creator_name'] = null;
    $updated['full_name'] = adminAccountDisplayName($updated);
    unset($updated['first_name'], $updated['middle_name'], $updated['last_name']);

    return $updated;
}
