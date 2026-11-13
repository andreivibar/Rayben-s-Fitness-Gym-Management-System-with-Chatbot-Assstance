<?php

function authenticatedAdminId(array $user): int
{
    $role = strtolower(trim((string)($user['role'] ?? '')));
    $userId = (int)($user['id'] ?? 0);
    if (!in_array($role, ['admin', 'administrator'], true) || $userId < 1) {
        throw new DomainException('An authenticated Admin account is required.');
    }

    return $userId;
}

function normalizeAdminProfileInput(array $input): array
{
    $firstName = trim((string)($input['first_name'] ?? ''));
    $middleName = trim((string)($input['middle_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));

    if ($firstName === '' || mb_strlen($firstName) > 50) {
        throw new InvalidArgumentException('First name is required and must be 50 characters or fewer.');
    }
    if (mb_strlen($middleName) > 50) {
        throw new InvalidArgumentException('Middle name must be 50 characters or fewer.');
    }
    if ($lastName === '' || mb_strlen($lastName) > 50) {
        throw new InvalidArgumentException('Last name is required and must be 50 characters or fewer.');
    }
    if ($email === '' || mb_strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address of 100 characters or fewer.');
    }

    return [
        'first_name' => $firstName,
        'middle_name' => $middleName !== '' ? $middleName : null,
        'last_name' => $lastName,
        'full_name' => $firstName . ($middleName !== null && $middleName !== '' ? ' ' . $middleName : '') . ' ' . $lastName,
        'email' => $email,
    ];
}

function fetchAdminProfile(PDO $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, first_name, middle_name, last_name, full_name, email, role, created_at, profile_picture
         FROM users
         WHERE id = :id AND LOWER(TRIM(role)) IN ('admin', 'administrator')
         LIMIT 1"
    );
    $stmt->execute([':id' => $userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    return $profile ?: null;
}

function updateAdminProfile(PDO $conn, int $userId, array $input): array
{
    $profile = normalizeAdminProfileInput($input);
    $duplicate = $conn->prepare(
        "SELECT id FROM users
         WHERE registration_unique_email = LOWER(:email)
           AND id <> :id
         LIMIT 1"
    );
    $duplicate->execute([
        ':email' => $profile['email'],
        ':id' => $userId,
    ]);
    if ($duplicate->fetchColumn()) {
        throw new DomainException('That email address is already associated with another active account.');
    }

    $update = $conn->prepare(
        "UPDATE users
         SET first_name = :first_name,
             middle_name = :middle_name,
             last_name = :last_name,
             full_name = :full_name,
             email = :email
         WHERE id = :id AND LOWER(TRIM(role)) IN ('admin', 'administrator')"
    );
    $update->execute([
        ':first_name' => $profile['first_name'],
        ':middle_name' => $profile['middle_name'],
        ':last_name' => $profile['last_name'],
        ':full_name' => $profile['full_name'],
        ':email' => $profile['email'],
        ':id' => $userId,
    ]);

    $updatedProfile = fetchAdminProfile($conn, $userId);
    if (!$updatedProfile) {
        throw new RuntimeException('Unable to reload the authenticated Admin profile.');
    }

    return $updatedProfile;
}

function updateAdminPassword(PDO $conn, int $userId, array $input): void
{
    $currentPassword = (string)($input['current_password'] ?? '');
    $newPassword = (string)($input['new_password'] ?? '');
    $confirmation = (string)($input['confirm_password'] ?? '');

    if ($currentPassword === '') {
        throw new InvalidArgumentException('Current password is required.');
    }
    if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
        throw new InvalidArgumentException('New password must be between 8 and 72 bytes.');
    }
    if (preg_match('/^\s|\s$/u', $newPassword) === 1) {
        throw new InvalidArgumentException('New password cannot start or end with whitespace.');
    }
    if (!hash_equals($newPassword, $confirmation)) {
        throw new InvalidArgumentException('New password and confirmation do not match.');
    }

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare(
            "SELECT password FROM users
             WHERE id = :id AND LOWER(TRIM(role)) IN ('admin', 'administrator')
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':id' => $userId]);
        $storedHash = $stmt->fetchColumn();
        if (!is_string($storedHash) || !password_verify($currentPassword, $storedHash)) {
            throw new DomainException('Current password is incorrect.');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($newHash === false) {
            throw new RuntimeException('Unable to secure the new password.');
        }

        $update = $conn->prepare(
            "UPDATE users
             SET password = :password, auth_token = NULL
             WHERE id = :id AND LOWER(TRIM(role)) IN ('admin', 'administrator')"
        );
        $update->execute([
            ':password' => $newHash,
            ':id' => $userId,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The Admin password could not be updated.');
        }
        $conn->commit();
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}

function getAdminProfilePictureTarget(PDO $conn, array $currentUser): array
{
    $userId = authenticatedAdminId($currentUser);
    $stmt = $conn->prepare(
        "SELECT id, profile_picture
         FROM users
         WHERE id = :id AND LOWER(TRIM(role)) IN ('admin', 'administrator')
         LIMIT 1"
    );
    $stmt->execute([':id' => $userId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        throw new OutOfBoundsException('Admin profile not found.');
    }
    $target['admin_code'] = 'ADMIN-' . $userId;

    return $target;
}
