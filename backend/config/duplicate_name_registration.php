<?php

require_once __DIR__ . '/registration_identity.php';

function normalizeDuplicateNamePart(string $value): string
{
    $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $value);
    if ($normalized === null) {
        throw new InvalidArgumentException('Name contains invalid text.');
    }

    return mb_strtolower(trim($normalized), 'UTF-8');
}

function duplicateNameRegistrationLockKey(string $firstName, string $lastName): string
{
    $nameKey = normalizeDuplicateNamePart($firstName) . "\0" . normalizeDuplicateNamePart($lastName);
    return 'duplicate_name_' . substr(hash('sha256', $nameKey), 0, 48);
}

function acquireDuplicateNameRegistrationLock(
    PDO $conn,
    string $firstName,
    string $lastName,
    string $username = '',
    string $email = ''
): void
{
    $lockNames = [duplicateNameRegistrationLockKey($firstName, $lastName)];
    $normalizedUsername = mb_strtolower(trim($username), 'UTF-8');
    if ($normalizedUsername !== '') {
        $lockNames[] = 'duplicate_account_u_' . substr(hash('sha256', $normalizedUsername), 0, 44);
    }
    $normalizedEmail = mb_strtolower(trim($email), 'UTF-8');
    if ($normalizedEmail !== '') {
        $lockNames[] = 'duplicate_account_e_' . substr(hash('sha256', $normalizedEmail), 0, 44);
    }
    sort($lockNames, SORT_STRING);
    $stmt = $conn->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $acquired = [];
    foreach ($lockNames as $lockName) {
        $stmt->execute([':lock_name' => $lockName]);
        if ((int)$stmt->fetchColumn() !== 1) {
            foreach (array_reverse($acquired) as $acquiredLock) {
                $release = $conn->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute([':lock_name' => $acquiredLock]);
            }
            throw new RuntimeException('Unable to safely check duplicate registration details. Please retry.');
        }
        $acquired[] = $lockName;
    }

    register_shutdown_function(static function () use ($conn, $acquired): void {
        foreach (array_reverse($acquired) as $lockName) {
            try {
                $release = $conn->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute([':lock_name' => $lockName]);
            } catch (Throwable $e) {
                error_log('Unable to release duplicate-registration lock; exception=' . get_class($e));
            }
        }
    });
}

function findDuplicateNameRegistrationRecord(PDO $conn, string $firstName, string $lastName): ?array
{
    $memberAccountFilter = registrationIdentityReleaseEnabled($conn)
        ? "AND COALESCE(u.registration_status, 'active') <> 'rejected'"
        : '';
    $memberAccountJoin = $memberAccountFilter !== '' ? 'LEFT JOIN users u ON u.id = m.user_id' : '';
    $stmt = $conn->query(
        "SELECT m.id, m.first_name, m.last_name, 'member' AS record_type
         FROM members m
         {$memberAccountJoin}
         WHERE m.first_name IS NOT NULL AND m.last_name IS NOT NULL
           {$memberAccountFilter}
         UNION ALL
         SELECT id, first_name, last_name, 'application' AS record_type
         FROM registration_requests
         WHERE status = 'pending'
           AND first_name IS NOT NULL AND last_name IS NOT NULL
         UNION ALL
         SELECT id, first_name, last_name, 'duplicate_exception' AS record_type
         FROM duplicate_name_registration_exceptions
         WHERE status = 'pending'
         ORDER BY id"
    );
    $normalizedFirst = normalizeDuplicateNamePart($firstName);
    $normalizedLast = normalizeDuplicateNamePart($lastName);

    while ($record = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (
            normalizeDuplicateNamePart((string)$record['first_name']) === $normalizedFirst
            && normalizeDuplicateNamePart((string)$record['last_name']) === $normalizedLast
        ) {
            return $record;
        }
    }

    return null;
}

function findPendingDuplicateNameRegistrationByAccount(
    PDO $conn,
    string $username,
    string $email
): ?array {
    $stmt = $conn->prepare(
        'SELECT id, status
         FROM duplicate_name_registration_exceptions
         WHERE status IN ("pending", "authorized")
           AND registration_request_id IS NULL
           AND (
               LOWER(username) = LOWER(:username)
               OR (:email_filter <> "" AND LOWER(email) = LOWER(:email_match))
           )
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':username' => $username,
        ':email_filter' => $email,
        ':email_match' => $email,
    ]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    return $case ?: null;
}

function duplicateNameExceptionMatchesSubmission(array $case, array $submission): bool
{
    $normalizedFields = ['first_name', 'middle_name', 'last_name', 'address'];
    foreach ($normalizedFields as $field) {
        if (
            normalizeDuplicateNamePart((string)($case[$field] ?? ''))
            !== normalizeDuplicateNamePart((string)($submission[$field] ?? ''))
        ) {
            return false;
        }
    }

    if (
        mb_strtolower(trim((string)$case['username']), 'UTF-8')
            !== mb_strtolower(trim((string)$submission['username']), 'UTF-8')
        || mb_strtolower(trim((string)$case['email']), 'UTF-8')
            !== mb_strtolower(trim((string)$submission['email']), 'UTF-8')
    ) {
        return false;
    }

    foreach (['phone', 'date_of_birth', 'membership_type', 'member_category', 'payment_method', 'gender'] as $field) {
        if ((string)($case[$field] ?? '') !== (string)($submission[$field] ?? '')) {
            return false;
        }
    }

    foreach (['membership_plan_id', 'duration_months'] as $field) {
        if ((int)($case[$field] ?? 0) !== (int)($submission[$field] ?? 0)) {
            return false;
        }
    }

    return number_format((float)$case['payment_amount'], 2, '.', '')
        === number_format((float)$submission['payment_amount'], 2, '.', '');
}

function findRegistrationAccountIdentifierConflict(PDO $conn, string $username, string $email): ?string
{
    $identityReleaseEnabled = registrationIdentityReleaseEnabled($conn);
    $userAccountFilter = $identityReleaseEnabled ? "AND registration_status <> 'rejected'" : '';
    $requestAccountFilter = $identityReleaseEnabled ? "AND status <> 'rejected'" : '';
    $usernameStmt = $conn->prepare(
        "SELECT id FROM users
         WHERE LOWER(TRIM(username)) = LOWER(TRIM(:user_username))
           {$userAccountFilter}
         UNION ALL
         SELECT id FROM registration_requests
         WHERE LOWER(TRIM(username)) = LOWER(TRIM(:request_username))
           {$requestAccountFilter}
         LIMIT 1"
    );
    $usernameStmt->execute([
        ':user_username' => $username,
        ':request_username' => $username,
    ]);
    if ($usernameStmt->fetchColumn()) {
        return 'username';
    }

    if ($email === '') {
        return null;
    }
    $emailStmt = $conn->prepare(
        "SELECT id FROM users
         WHERE LOWER(TRIM(COALESCE(email, ''))) = LOWER(TRIM(:user_email))
           {$userAccountFilter}
         UNION ALL
         SELECT id FROM registration_requests
         WHERE LOWER(TRIM(COALESCE(email, ''))) = LOWER(TRIM(:request_email))
           {$requestAccountFilter}
         LIMIT 1"
    );
    $emailStmt->execute([
        ':user_email' => $email,
        ':request_email' => $email,
    ]);
    return $emailStmt->fetchColumn() ? 'email' : null;
}

function findRegistrationEmailConflict(
    PDO $conn,
    string $email,
    ?int $excludeUserId = null,
    ?int $excludeRequestId = null,
    ?int $excludeMemberId = null
): bool {
    $normalizedEmail = mb_strtolower(trim($email), 'UTF-8');
    if ($normalizedEmail === '') {
        return false;
    }

    $identityReleaseEnabled = registrationIdentityReleaseEnabled($conn);
    $userAccountFilter = $identityReleaseEnabled ? "AND registration_status <> 'rejected'" : '';
    $requestAccountFilter = $identityReleaseEnabled ? "AND status <> 'rejected'" : '';
    if ($excludeUserId !== null) {
        $userAccountFilter .= ' AND id <> :excluded_user_id';
    }
    if ($excludeRequestId !== null) {
        $requestAccountFilter .= ' AND id <> :excluded_request_id';
    }
    if ($excludeMemberId !== null) {
        $requestAccountFilter .= ' AND member_id <> :excluded_member_id';
    }

    $stmt = $conn->prepare(
        "SELECT id FROM users
         WHERE LOWER(TRIM(COALESCE(email, ''))) = :user_email
           {$userAccountFilter}
         UNION ALL
         SELECT id FROM registration_requests
         WHERE LOWER(TRIM(COALESCE(email, ''))) = :request_email
           {$requestAccountFilter}
         LIMIT 1"
    );
    $params = [
        ':user_email' => $normalizedEmail,
        ':request_email' => $normalizedEmail,
    ];
    if ($excludeUserId !== null) {
        $params[':excluded_user_id'] = $excludeUserId;
    }
    if ($excludeRequestId !== null) {
        $params[':excluded_request_id'] = $excludeRequestId;
    }
    if ($excludeMemberId !== null) {
        $params[':excluded_member_id'] = $excludeMemberId;
    }
    $stmt->execute($params);

    return (bool)$stmt->fetchColumn();
}

function storeDuplicateNameExceptionUpload(?array $upload, string $directory, array &$storedFiles): ?array
{
    if ($upload === null) {
        return null;
    }

    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create private registration document storage.');
    }

    $storedName = bin2hex(random_bytes(24)) . '.' . $upload['extension'];
    $absolutePath = $directory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to store a private registration document.');
    }
    $storedFiles[] = $absolutePath;

    return [
        'stored_name' => $storedName,
        'original_name' => $upload['original_name'],
        'mime_type' => $upload['mime_type'],
        'file_size' => $upload['file_size'],
    ];
}

function stageDuplicateNameRegistration(
    PDO $conn,
    array $application,
    ?array $paymentProof,
    ?array $discountProof,
    string $paymentProofDirectory,
    string $registrationDirectory,
    array &$storedFiles
): int {
    $paymentFile = storeDuplicateNameExceptionUpload(
        $paymentProof,
        $paymentProofDirectory,
        $storedFiles
    );
    $discountFile = storeDuplicateNameExceptionUpload(
        $discountProof,
        $registrationDirectory,
        $storedFiles
    );
    $stmt = $conn->prepare(
        'INSERT INTO duplicate_name_registration_exceptions
            (first_name, middle_name, last_name, full_name, username, email, phone, address,
             date_of_birth, password_hash, membership_type, member_category, membership_plan_id,
             duration_months, payment_method, payment_amount, submission_key_hash,
             daily_pass_idempotency_key, payment_proof_stored_name, payment_proof_original_name,
             payment_proof_mime_type, payment_proof_file_size, discount_proof_stored_name,
             discount_proof_original_name, discount_proof_mime_type, discount_proof_file_size)
         VALUES
            (:first_name, :middle_name, :last_name, :full_name, :username, :email, :phone, :address,
             :date_of_birth, :password_hash, :membership_type, :member_category, :membership_plan_id,
             :duration_months, :payment_method, :payment_amount, :submission_key_hash,
             :daily_pass_idempotency_key, :payment_proof_stored_name, :payment_proof_original_name,
             :payment_proof_mime_type, :payment_proof_file_size, :discount_proof_stored_name,
             :discount_proof_original_name, :discount_proof_mime_type, :discount_proof_file_size)'
    );
    $stmt->execute([
        ':first_name' => $application['first_name'],
        ':middle_name' => $application['middle_name'] !== '' ? $application['middle_name'] : null,
        ':last_name' => $application['last_name'],
        ':full_name' => $application['full_name'],
        ':username' => $application['username'],
        ':email' => $application['email'],
        ':phone' => $application['phone'],
        ':address' => $application['address'],
        ':date_of_birth' => $application['date_of_birth'],
        ':password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        ':membership_type' => $application['membership_type'],
        ':member_category' => $application['member_category'],
        ':membership_plan_id' => $application['membership_plan_id'],
        ':duration_months' => $application['duration_months'],
        ':payment_method' => $application['payment_method'],
        ':payment_amount' => number_format((float)$application['payment_amount'], 2, '.', ''),
        ':submission_key_hash' => $application['submission_key_hash'],
        ':daily_pass_idempotency_key' => $application['daily_pass_idempotency_key'],
        ':payment_proof_stored_name' => $paymentFile['stored_name'] ?? null,
        ':payment_proof_original_name' => $paymentFile['original_name'] ?? null,
        ':payment_proof_mime_type' => $paymentFile['mime_type'] ?? null,
        ':payment_proof_file_size' => $paymentFile['file_size'] ?? null,
        ':discount_proof_stored_name' => $discountFile['stored_name'] ?? null,
        ':discount_proof_original_name' => $discountFile['original_name'] ?? null,
        ':discount_proof_mime_type' => $discountFile['mime_type'] ?? null,
        ':discount_proof_file_size' => $discountFile['file_size'] ?? null,
    ]);

    return (int)$conn->lastInsertId();
}
