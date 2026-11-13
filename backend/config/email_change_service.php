<?php

require_once __DIR__ . '/password_reset_service.php';

const EMAIL_CHANGE_INVALID_CODE_MESSAGE = 'The verification code is invalid or expired.';
const EMAIL_CHANGE_GENERIC_ERROR_MESSAGE = 'Unable to complete this email change. Check the details and try again.';

function emailChangeNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function emailChangeDigest(string $purpose, string $value): string
{
    return hash_hmac(
        'sha256',
        'email-change:' . $purpose . ':' . $value,
        passwordResetHmacKey()
    );
}

function emailChangeEmailLimit(PDO $conn): int
{
    $column = $conn->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch(PDO::FETCH_ASSOC);
    if (!$column || !preg_match('/^varchar\((\d+)\)$/i', (string)$column['Type'], $match)) {
        throw new RuntimeException('The user email column could not be validated.');
    }

    return (int)$match[1];
}

function emailChangeValidateEmail(PDO $conn, string $email): bool
{
    $email = trim($email);
    return $email !== ''
        && strlen($email) <= emailChangeEmailLimit($conn)
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function emailChangeEmailLockName(string $email): string
{
    $normalizedEmail = mb_strtolower(trim($email), 'UTF-8');
    return 'duplicate_account_e_' . substr(hash('sha256', $normalizedEmail), 0, 44);
}

function emailChangeWithRegistrationEmailLock(PDO $conn, string $email, callable $operation)
{
    $lockName = emailChangeEmailLockName($email);
    $lock = $conn->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute([':lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Unable to safely check email availability.');
    }

    try {
        return $operation();
    } finally {
        $release = $conn->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute([':lock_name' => $lockName]);
    }
}

function emailChangeAccount(PDO $conn, array $authenticatedUser, bool $forUpdate): ?array
{
    $sql = "SELECT id, email, password, role, auth_token
            FROM users
            WHERE id = :user_id
              AND auth_token = :auth_token
              AND LOWER(TRIM(role)) IN ('member', 'trainer')
              AND LOWER(TRIM(registration_status)) = 'active'
            LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':user_id' => (int)$authenticatedUser['id'],
        ':auth_token' => (string)$authenticatedUser['auth_token'],
    ]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    return $account ?: null;
}

function emailChangeMemberId(PDO $conn, int $userId): ?int
{
    $stmt = $conn->prepare('SELECT id FROM members WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $userId]);
    $memberId = $stmt->fetchColumn();
    return $memberId === false ? null : (int)$memberId;
}

function emailChangeEmailAvailable(PDO $conn, int $userId, string $email, ?int $memberId): bool
{
    $normalizedEmail = emailChangeNormalizeEmail($email);
    $users = $conn->prepare(
        "SELECT id
         FROM users
         WHERE id <> :user_id
           AND COALESCE(registration_status, 'active') <> 'rejected'
           AND NULLIF(TRIM(email), '') IS NOT NULL
           AND LOWER(TRIM(email)) = :email
         LIMIT 1"
    );
    $users->execute([':user_id' => $userId, ':email' => $normalizedEmail]);
    if ($users->fetchColumn() !== false) {
        return false;
    }

    $requests = $conn->prepare(
        "SELECT id
         FROM registration_requests
         WHERE COALESCE(status, 'pending') <> 'rejected'
           AND NULLIF(TRIM(email), '') IS NOT NULL
           AND LOWER(TRIM(email)) = :email
           AND (:member_id IS NULL OR member_id IS NULL OR member_id <> :member_id_match)
         LIMIT 1"
    );
    $requests->execute([
        ':email' => $normalizedEmail,
        ':member_id' => $memberId,
        ':member_id_match' => $memberId,
    ]);
    if ($requests->fetchColumn() !== false) {
        return false;
    }

    return true;
}

function emailChangeConsumeRateLimit(
    PDO $conn,
    string $scopeType,
    string $scopeValue,
    string $action,
    int $limit
): bool {
    $scopeHash = emailChangeDigest(
        'rate:' . $action . ':' . $scopeType,
        $scopeValue
    );
    $insert = $conn->prepare(
        'INSERT INTO email_change_rate_limits
            (scope_type, scope_hash, window_started_at, request_count)
         VALUES
            (:scope_type, :scope_hash, DATE_FORMAT(CURRENT_TIMESTAMP, "%Y-%m-%d %H:00:00"), 1)
         ON DUPLICATE KEY UPDATE request_count = LEAST(request_count + 1, 65535)'
    );
    $insert->execute([
        ':scope_type' => $scopeType,
        ':scope_hash' => $scopeHash,
    ]);

    $lookup = $conn->prepare(
        'SELECT request_count
         FROM email_change_rate_limits
         WHERE scope_type = :scope_type
           AND scope_hash = :scope_hash
           AND window_started_at = DATE_FORMAT(CURRENT_TIMESTAMP, "%Y-%m-%d %H:00:00")
         LIMIT 1'
    );
    $lookup->execute([
        ':scope_type' => $scopeType,
        ':scope_hash' => $scopeHash,
    ]);

    return (int)$lookup->fetchColumn() <= $limit;
}

function emailChangeWithinRateLimits(
    PDO $conn,
    string $action,
    int $userId,
    string $email
): bool {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = 'unknown';
    }

    $limits = $action === 'request'
        ? [['account', (string)$userId, 5], ['ip', $ip, 20], ['email', emailChangeNormalizeEmail($email), 5]]
        : [['account', (string)$userId, 30], ['ip', $ip, 60], ['email', emailChangeNormalizeEmail($email), 30]];
    $allowed = true;
    foreach ($limits as [$scopeType, $scopeValue, $limit]) {
        $scopeType = $action . '-' . $scopeType;
        if (!emailChangeConsumeRateLimit($conn, $scopeType, $scopeValue, $action, $limit)) {
            $allowed = false;
        }
    }

    return $allowed;
}

function emailChangePruneExpiredRows(PDO $conn): void
{
    $conn->exec(
        'DELETE FROM email_change_rate_limits
         WHERE window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 DAY)'
    );
    $conn->exec(
        'DELETE FROM email_change_challenges
         WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)'
    );
}

function emailChangeRequestCode(
    PDO $conn,
    array $authenticatedUser,
    string $newEmail,
    string $currentPassword
): array {
    $newEmail = trim($newEmail);
    if (!emailChangeValidateEmail($conn, $newEmail)) {
        return ['success' => false, 'status' => 400, 'message' => 'Enter a valid email address of no more than 100 characters.'];
    }
    if ($currentPassword === '') {
        return ['success' => false, 'status' => 400, 'message' => 'Your current password is required.'];
    }

    $normalizedEmail = emailChangeNormalizeEmail($newEmail);
    try {
        $challenge = emailChangeWithRegistrationEmailLock(
            $conn,
            $normalizedEmail,
            function () use ($conn, $authenticatedUser, $newEmail, $normalizedEmail, $currentPassword): array {
                $conn->beginTransaction();
                try {
                    $account = emailChangeAccount($conn, $authenticatedUser, true);
                    if (!$account) {
                        $conn->rollBack();
                        return ['success' => false, 'status' => 401, 'message' => 'Your session is no longer valid. Sign in again.'];
                    }
                    if (!password_verify($currentPassword, (string)$account['password'])) {
                        $conn->rollBack();
                        return ['success' => false, 'status' => 401, 'message' => 'The current password is incorrect.'];
                    }
                    if (emailChangeNormalizeEmail((string)($account['email'] ?? '')) === $normalizedEmail) {
                        $conn->rollBack();
                        return ['success' => false, 'status' => 400, 'message' => 'Enter an email address different from the one currently registered.'];
                    }

                    $userId = (int)$account['id'];
                    if (!emailChangeWithinRateLimits($conn, 'request', $userId, $normalizedEmail)) {
                        $conn->commit();
                        return ['success' => false, 'status' => 429, 'message' => 'Too many verification requests. Please try again later.'];
                    }

                    $latest = $conn->prepare(
                        'SELECT last_sent_at
                         FROM email_change_challenges
                         WHERE user_id = :user_id
                         ORDER BY id DESC
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $latest->execute([':user_id' => $userId]);
                    $lastSentAt = $latest->fetchColumn();
                    if ($lastSentAt !== false) {
                        $cooldown = $conn->prepare(
                            'SELECT TIMESTAMPDIFF(SECOND, :last_sent_at, CURRENT_TIMESTAMP) < 60'
                        );
                        $cooldown->execute([':last_sent_at' => $lastSentAt]);
                        if ((int)$cooldown->fetchColumn() === 1) {
                            $conn->commit();
                            return ['success' => false, 'status' => 429, 'message' => 'Please wait before requesting another verification code.'];
                        }
                    }

                    $memberId = strtolower(trim((string)$account['role'])) === 'member'
                        ? emailChangeMemberId($conn, $userId)
                        : null;
                    if (!emailChangeEmailAvailable($conn, $userId, $normalizedEmail, $memberId)) {
                        $conn->commit();
                        return ['success' => false, 'status' => 400, 'message' => 'Unable to use this email address. Check the address and try again.'];
                    }

                    $invalidate = $conn->prepare(
                        'UPDATE email_change_challenges
                         SET consumed_at = COALESCE(consumed_at, CURRENT_TIMESTAMP)
                         WHERE user_id = :user_id AND consumed_at IS NULL'
                    );
                    $invalidate->execute([':user_id' => $userId]);

                    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $codeHash = emailChangeDigest(
                        'verification-code',
                        $userId . ':' . $normalizedEmail . ':' . $code
                    );
                    $insert = $conn->prepare(
                        'INSERT INTO email_change_challenges
                            (user_id, new_email, code_hash, expires_at, last_sent_at)
                         VALUES
                            (:user_id, :new_email, :code_hash,
                             DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE), CURRENT_TIMESTAMP)'
                    );
                    $insert->execute([
                        ':user_id' => $userId,
                        ':new_email' => $newEmail,
                        ':code_hash' => $codeHash,
                    ]);
                    $challengeId = (int)$conn->lastInsertId();
                    $conn->commit();

                    return [
                        'success' => true,
                        'challenge_id' => $challengeId,
                        'recipient' => $newEmail,
                        'code' => $code,
                    ];
                } catch (Throwable $error) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    throw $error;
                }
            }
        );
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') {
            return ['success' => false, 'status' => 400, 'message' => 'Unable to use this email address. Check the address and try again.'];
        }
        throw $error;
    }

    if (empty($challenge['success'])) {
        return $challenge;
    }

    try {
        emailChangeSendVerificationEmail($challenge['recipient'], $challenge['code']);
    } catch (Throwable $mailError) {
        error_log('Email-change verification email delivery failed.');
        try {
            $consume = $conn->prepare(
                'UPDATE email_change_challenges
                 SET consumed_at = COALESCE(consumed_at, CURRENT_TIMESTAMP)
                 WHERE id = :id AND consumed_at IS NULL'
            );
            $consume->execute([':id' => $challenge['challenge_id']]);
        } catch (Throwable $consumeError) {
            error_log('Email-change failed-delivery challenge cleanup failed.');
        }
        return [
            'success' => false,
            'status' => 503,
            'message' => 'The verification email could not be delivered. Please try again later.',
            'retry_after_seconds' => 60,
        ];
    }

    return ['success' => true, 'status' => 200, 'message' => 'A verification code was sent to the new email address.'];
}

function emailChangeVerifyCode(
    PDO $conn,
    array $authenticatedUser,
    string $newEmail,
    string $currentPassword,
    string $code
): array {
    $newEmail = trim($newEmail);
    if (!emailChangeValidateEmail($conn, $newEmail)
        || $currentPassword === '') {
        return ['success' => false, 'status' => 400, 'message' => EMAIL_CHANGE_INVALID_CODE_MESSAGE];
    }

    $normalizedEmail = emailChangeNormalizeEmail($newEmail);
    try {
        $result = emailChangeWithRegistrationEmailLock(
            $conn,
            $normalizedEmail,
            function () use ($conn, $authenticatedUser, $newEmail, $normalizedEmail, $currentPassword, $code): array {
                $conn->beginTransaction();
                try {
                    $account = emailChangeAccount($conn, $authenticatedUser, true);
                    if (!$account || !password_verify($currentPassword, (string)$account['password'])) {
                        $conn->rollBack();
                        return ['success' => false, 'status' => 401, 'message' => 'The current password is incorrect or your session has expired.'];
                    }

                    if (!emailChangeWithinRateLimits($conn, 'verify', (int)$account['id'], $normalizedEmail)) {
                        $conn->commit();
                        return ['success' => false, 'status' => 429, 'message' => EMAIL_CHANGE_INVALID_CODE_MESSAGE];
                    }

                    $challengeStmt = $conn->prepare(
                        'SELECT id, new_email, code_hash, attempt_count
                         FROM email_change_challenges
                         WHERE user_id = :user_id
                           AND expires_at > CURRENT_TIMESTAMP
                           AND consumed_at IS NULL
                           AND attempt_count < 5
                         ORDER BY id DESC
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $challengeStmt->execute([':user_id' => (int)$account['id']]);
                    $challenge = $challengeStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$challenge) {
                        $conn->rollBack();
                        return ['success' => false, 'status' => 400, 'message' => EMAIL_CHANGE_INVALID_CODE_MESSAGE];
                    }

                    $boundEmail = emailChangeNormalizeEmail((string)$challenge['new_email']);
                    $validFormat = preg_match('/^\d{6}$/D', $code) === 1;
                    $expectedHash = emailChangeDigest(
                        'verification-code',
                        (int)$account['id'] . ':' . $boundEmail . ':' . ($validFormat ? $code : 'invalid-format')
                    );
                    $matches = $validFormat
                        && hash_equals((string)$challenge['code_hash'], $expectedHash)
                        && hash_equals($boundEmail, $normalizedEmail);
                    if (!$matches) {
                        $attemptCount = (int)$challenge['attempt_count'] + 1;
                        $updateAttempts = $conn->prepare(
                            'UPDATE email_change_challenges
                             SET attempt_count = :attempt_count,
                                 consumed_at = IF(:consume = 1, CURRENT_TIMESTAMP, consumed_at)
                             WHERE id = :id AND consumed_at IS NULL'
                        );
                        $updateAttempts->execute([
                            ':attempt_count' => $attemptCount,
                            ':consume' => $attemptCount >= 5 ? 1 : 0,
                            ':id' => (int)$challenge['id'],
                        ]);
                        $conn->commit();
                        return ['success' => false, 'status' => 400, 'message' => EMAIL_CHANGE_INVALID_CODE_MESSAGE];
                    }

                    if ($normalizedEmail === emailChangeNormalizeEmail((string)($account['email'] ?? ''))) {
                        $consume = $conn->prepare(
                            'UPDATE email_change_challenges
                             SET consumed_at = CURRENT_TIMESTAMP
                             WHERE id = :id AND consumed_at IS NULL'
                        );
                        $consume->execute([':id' => (int)$challenge['id']]);
                        $conn->commit();
                        return ['success' => false, 'status' => 400, 'message' => 'Enter an email address different from the one currently registered.'];
                    }

                    $memberId = strtolower(trim((string)$account['role'])) === 'member'
                        ? emailChangeMemberId($conn, (int)$account['id'])
                        : null;
                    if (!emailChangeEmailAvailable($conn, (int)$account['id'], $normalizedEmail, $memberId)) {
                        $consume = $conn->prepare(
                            'UPDATE email_change_challenges
                             SET consumed_at = CURRENT_TIMESTAMP
                             WHERE id = :id AND consumed_at IS NULL'
                        );
                        $consume->execute([':id' => (int)$challenge['id']]);
                        $conn->commit();
                        return ['success' => false, 'status' => 400, 'message' => 'Unable to use this email address. Check the address and try again.'];
                    }

                    $oldEmail = trim((string)($account['email'] ?? ''));
                    $updateUser = $conn->prepare(
                        "UPDATE users
                         SET email = :email, auth_token = NULL
                         WHERE id = :user_id
                           AND auth_token = :auth_token
                           AND LOWER(TRIM(role)) IN ('member', 'trainer')
                           AND LOWER(TRIM(registration_status)) = 'active'"
                    );
                    $updateUser->execute([
                        ':email' => $newEmail,
                        ':user_id' => (int)$account['id'],
                        ':auth_token' => (string)$authenticatedUser['auth_token'],
                    ]);
                    if ($updateUser->rowCount() !== 1) {
                        throw new RuntimeException('The authenticated account could not be updated.');
                    }

                    $consume = $conn->prepare(
                        'UPDATE email_change_challenges
                         SET verified_at = CURRENT_TIMESTAMP,
                             consumed_at = CURRENT_TIMESTAMP
                         WHERE id = :id AND consumed_at IS NULL AND attempt_count < 5'
                    );
                    $consume->execute([':id' => (int)$challenge['id']]);
                    if ($consume->rowCount() !== 1) {
                        throw new RuntimeException('The email-change challenge could not be consumed.');
                    }

                    $conn->commit();
                    return [
                        'success' => true,
                        'status' => 200,
                        'message' => 'Email updated. Sign in again using your new email address.',
                        'old_email' => $oldEmail,
                    ];
                } catch (Throwable $error) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    throw $error;
                }
            }
        );
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') {
            return ['success' => false, 'status' => 400, 'message' => 'Unable to use this email address. Check the address and try again.'];
        }
        throw $error;
    }

    if (!empty($result['success']) && $result['old_email'] !== '') {
        try {
            emailChangeSendPreviousAddressNotification($result['old_email'], $newEmail);
        } catch (Throwable $mailError) {
            error_log('Email-change previous-address notification delivery failed.');
        }
        unset($result['old_email']);
    }

    return $result;
}
