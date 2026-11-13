<?php

const PASSWORD_RESET_GENERIC_MESSAGE = 'If an eligible account matches that email address, password reset instructions will be sent.';
const PASSWORD_RESET_INVALID_CODE_MESSAGE = 'The verification code is invalid or expired.';
const PASSWORD_RESET_INVALID_TOKEN_MESSAGE = 'The reset link is invalid or expired. Request a new verification code.';

function passwordResetEnvironmentVariable(string $name): string
{
    $value = getenv($name);
    if ($value !== false && trim($value) !== '') {
        return trim($value);
    }

    static $fileValues = null;
    if ($fileValues === null) {
        $environmentFile = dirname(__DIR__) . '/.env';
        if (!is_file($environmentFile)) {
            $fileValues = [];
        } else {
            $environmentLines = @file($environmentFile, FILE_IGNORE_NEW_LINES);
            if ($environmentLines === false) {
                throw new RuntimeException('Password reset environment configuration is unavailable.');
            }
            $fileValues = [];
            foreach ($environmentLines as $line) {
                if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $match)) {
                    continue;
                }
                $value = $match[2];
                if (strlen($value) >= 2
                    && (($value[0] === '"' && substr($value, -1) === '"')
                        || ($value[0] === "'" && substr($value, -1) === "'"))) {
                    $value = substr($value, 1, -1);
                } else {
                    $value = preg_replace('/\s+#.*$/', '', $value);
                }
                $fileValues[$match[1]] = $value;
            }
        }
    }

    $fileValue = $fileValues[$name] ?? '';
    return is_string($fileValue) ? trim($fileValue) : '';
}

function passwordResetHmacKey(): string
{
    $key = passwordResetEnvironmentVariable('PASSWORD_RESET_HMAC_KEY');
    if (strlen($key) < 32) {
        throw new RuntimeException('Password reset security configuration is unavailable.');
    }

    return $key;
}

function passwordResetDigest(string $purpose, string $value): string
{
    return hash_hmac('sha256', $purpose . ':' . $value, passwordResetHmacKey());
}

function passwordResetNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function passwordResetValidEmail(string $email): bool
{
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function passwordResetClientIp(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function passwordResetConsumeRateLimit(
    PDO $conn,
    string $scopeType,
    string $scopeValue,
    string $action,
    int $limit
): bool {
    $scopeHash = passwordResetDigest('rate:' . $action . ':' . $scopeType, $scopeValue);
    $insert = $conn->prepare(
        'INSERT INTO password_reset_rate_limits (scope_type, scope_hash, window_started_at, request_count)
         VALUES (:scope_type, :scope_hash, DATE_FORMAT(CURRENT_TIMESTAMP, "%Y-%m-%d %H:00:00"), 1)
         ON DUPLICATE KEY UPDATE request_count = LEAST(request_count + 1, 65535)'
    );
    $insert->execute([
        ':scope_type' => $scopeType,
        ':scope_hash' => $scopeHash,
    ]);

    $lookup = $conn->prepare(
        'SELECT request_count
         FROM password_reset_rate_limits
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

function passwordResetWithinRateLimits(
    PDO $conn,
    string $action,
    string $email,
    int $emailLimit,
    int $ipLimit
): bool {
    $ipAllowed = passwordResetConsumeRateLimit($conn, 'ip', passwordResetClientIp(), $action, $ipLimit);
    $emailAllowed = $email === ''
        || passwordResetConsumeRateLimit($conn, 'email', $email, $action, $emailLimit);

    return $ipAllowed && $emailAllowed;
}

function passwordResetFindEligibleAccount(PDO $conn, string $email, bool $forUpdate = false): ?array
{
    $sql = "SELECT u.id, u.email, u.role
            FROM users u
            WHERE LOWER(TRIM(u.email)) = :email
              AND LOWER(TRIM(u.registration_status)) = 'active'
              AND (
                  (LOWER(TRIM(u.role)) = 'member' AND EXISTS (
                      SELECT 1
                      FROM members m
                      WHERE m.user_id = u.id
                        AND LOWER(TRIM(m.account_status)) <> 'deactivated'
                  ))
                  OR
                  (LOWER(TRIM(u.role)) = 'trainer' AND EXISTS (
                      SELECT 1
                      FROM trainers t
                      WHERE t.user_id = u.id
                        AND LOWER(TRIM(t.status)) = 'active'
                  ))
              )
            LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([':email' => $email]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    return $account ?: null;
}

function passwordResetPruneExpiredRows(PDO $conn): void
{
    $conn->exec(
        'DELETE FROM password_reset_rate_limits
         WHERE window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 DAY)'
    );
    $conn->exec(
        'DELETE FROM password_reset_challenges
         WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)'
    );
}

function passwordResetRequestCode(PDO $conn, string $email): array
{
    $normalizedEmail = passwordResetNormalizeEmail($email);
    $genericResponse = ['success' => true, 'message' => PASSWORD_RESET_GENERIC_MESSAGE];

    if (!passwordResetWithinRateLimits($conn, 'request', $normalizedEmail, 5, 20)) {
        return $genericResponse;
    }
    if (!passwordResetValidEmail($normalizedEmail)) {
        return $genericResponse;
    }

    $account = passwordResetFindEligibleAccount($conn, $normalizedEmail);
    if (!$account) {
        return $genericResponse;
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = passwordResetDigest('verification-code', $code);

    $conn->beginTransaction();
    try {
        $lockedAccount = passwordResetFindEligibleAccount($conn, $normalizedEmail, true);
        if (!$lockedAccount || (int)$lockedAccount['id'] !== (int)$account['id']) {
            $conn->commit();
            return $genericResponse;
        }

        $latest = $conn->prepare(
            'SELECT last_sent_at
             FROM password_reset_challenges
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $latest->execute([':user_id' => (int)$lockedAccount['id']]);
        $lastSentAt = $latest->fetchColumn();
        if ($lastSentAt !== false) {
            $cooldown = $conn->prepare(
                'SELECT TIMESTAMPDIFF(SECOND, :last_sent_at, CURRENT_TIMESTAMP) < 60'
            );
            $cooldown->execute([':last_sent_at' => $lastSentAt]);
            if ((int)$cooldown->fetchColumn() === 1) {
                $conn->commit();
                return $genericResponse;
            }
        }

        $invalidate = $conn->prepare(
            'UPDATE password_reset_challenges
             SET consumed_at = COALESCE(consumed_at, CURRENT_TIMESTAMP),
                 reset_token_hash = NULL,
                 reset_token_expires_at = NULL
             WHERE user_id = :user_id
               AND reset_completed_at IS NULL
               AND (consumed_at IS NULL OR reset_token_hash IS NOT NULL)'
        );
        $invalidate->execute([':user_id' => (int)$lockedAccount['id']]);

        $insert = $conn->prepare(
            'INSERT INTO password_reset_challenges
                (user_id, code_hash, expires_at, last_sent_at)
             VALUES
                (:user_id, :code_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE), CURRENT_TIMESTAMP)'
        );
        $insert->execute([
            ':user_id' => (int)$lockedAccount['id'],
            ':code_hash' => $codeHash,
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }

    try {
        passwordResetSendEmail((string)$lockedAccount['email'], $code);
    } catch (Throwable $mailError) {
        error_log('Password reset email delivery failed.');
    }

    return $genericResponse;
}

function passwordResetVerifyCode(PDO $conn, string $email, string $code): array
{
    $normalizedEmail = passwordResetNormalizeEmail($email);
    if (!passwordResetWithinRateLimits($conn, 'verify', $normalizedEmail, 30, 60)) {
        return ['success' => false, 'message' => PASSWORD_RESET_INVALID_CODE_MESSAGE];
    }

    if (!passwordResetValidEmail($normalizedEmail)) {
        return ['success' => false, 'message' => PASSWORD_RESET_INVALID_CODE_MESSAGE];
    }

    $conn->beginTransaction();
    try {
        $account = passwordResetFindEligibleAccount($conn, $normalizedEmail, true);
        if (!$account) {
            $conn->rollBack();
            return ['success' => false, 'message' => PASSWORD_RESET_INVALID_CODE_MESSAGE];
        }

        $challengeStmt = $conn->prepare(
            'SELECT id, code_hash, attempt_count
             FROM password_reset_challenges
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
            return ['success' => false, 'message' => PASSWORD_RESET_INVALID_CODE_MESSAGE];
        }

        $validFormat = preg_match('/^\d{6}$/D', $code) === 1;
        $expectedHash = passwordResetDigest('verification-code', $code);
        $matches = $validFormat && hash_equals((string)$challenge['code_hash'], $expectedHash);
        $attemptCount = (int)$challenge['attempt_count'] + 1;
        if (!$matches) {
            $updateAttempts = $conn->prepare(
                'UPDATE password_reset_challenges
                 SET attempt_count = :attempt_count
                 WHERE id = :id'
            );
            $updateAttempts->execute([
                ':attempt_count' => $attemptCount,
                ':id' => (int)$challenge['id'],
            ]);
            $conn->commit();
            return ['success' => false, 'message' => PASSWORD_RESET_INVALID_CODE_MESSAGE];
        }

        $resetToken = bin2hex(random_bytes(32));
        $resetTokenHash = passwordResetDigest('reset-token', $resetToken);
        $verified = $conn->prepare(
            'UPDATE password_reset_challenges
             SET attempt_count = :attempt_count,
                 verified_at = CURRENT_TIMESTAMP,
                 consumed_at = CURRENT_TIMESTAMP,
                 reset_token_hash = :reset_token_hash,
                 reset_token_expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE)
             WHERE id = :id AND consumed_at IS NULL AND attempt_count < 5'
        );
        $verified->execute([
            ':attempt_count' => $attemptCount,
            ':reset_token_hash' => $resetTokenHash,
            ':id' => (int)$challenge['id'],
        ]);
        if ($verified->rowCount() !== 1) {
            throw new RuntimeException('Password reset challenge could not be consumed.');
        }
        $conn->commit();

        return ['success' => true, 'reset_token' => $resetToken];
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}

function passwordResetComplete(
    PDO $conn,
    string $email,
    string $resetToken,
    string $newPassword,
    string $confirmation
): array {
    $normalizedEmail = passwordResetNormalizeEmail($email);
    if (!passwordResetWithinRateLimits($conn, 'complete', $normalizedEmail, 10, 30)) {
        return ['success' => false, 'message' => PASSWORD_RESET_INVALID_TOKEN_MESSAGE];
    }

    if (!passwordResetValidEmail($normalizedEmail)
        || preg_match('/^[a-f0-9]{64}$/D', $resetToken) !== 1) {
        return ['success' => false, 'message' => PASSWORD_RESET_INVALID_TOKEN_MESSAGE];
    }
    if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
        return ['success' => false, 'message' => 'Password must be between 8 and 72 bytes.'];
    }
    if (preg_match('/^\s|\s$/u', $newPassword) === 1) {
        return ['success' => false, 'message' => 'Password cannot start or end with whitespace.'];
    }
    if (!hash_equals($newPassword, $confirmation)) {
        return ['success' => false, 'message' => 'Passwords do not match.'];
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!is_string($passwordHash)) {
        throw new RuntimeException('Unable to secure the new password.');
    }
    $resetTokenHash = passwordResetDigest('reset-token', $resetToken);

    $conn->beginTransaction();
    try {
        $account = passwordResetFindEligibleAccount($conn, $normalizedEmail, true);
        if (!$account) {
            $conn->rollBack();
            return ['success' => false, 'message' => PASSWORD_RESET_INVALID_TOKEN_MESSAGE];
        }

        $challengeStmt = $conn->prepare(
            'SELECT id
             FROM password_reset_challenges
             WHERE user_id = :user_id
               AND reset_token_hash = :reset_token_hash
               AND reset_token_expires_at > CURRENT_TIMESTAMP
               AND reset_completed_at IS NULL
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $challengeStmt->execute([
            ':user_id' => (int)$account['id'],
            ':reset_token_hash' => $resetTokenHash,
        ]);
        $challengeId = $challengeStmt->fetchColumn();
        if (!$challengeId) {
            $conn->rollBack();
            return ['success' => false, 'message' => PASSWORD_RESET_INVALID_TOKEN_MESSAGE];
        }

        $updateUser = $conn->prepare(
            "UPDATE users
             SET password = :password, auth_token = NULL
             WHERE id = :id
               AND LOWER(TRIM(role)) IN ('member', 'trainer')
               AND LOWER(TRIM(registration_status)) = 'active'"
        );
        $updateUser->execute([
            ':password' => $passwordHash,
            ':id' => (int)$account['id'],
        ]);
        if ($updateUser->rowCount() !== 1) {
            throw new RuntimeException('Eligible account was not updated.');
        }

        $consumeToken = $conn->prepare(
            'UPDATE password_reset_challenges
             SET reset_completed_at = CURRENT_TIMESTAMP,
                 reset_token_hash = NULL,
                 reset_token_expires_at = NULL
             WHERE id = :id AND reset_completed_at IS NULL'
        );
        $consumeToken->execute([':id' => (int)$challengeId]);
        if ($consumeToken->rowCount() !== 1) {
            throw new RuntimeException('Password reset token could not be consumed.');
        }

        $invalidateOtherChallenges = $conn->prepare(
            'UPDATE password_reset_challenges
             SET consumed_at = COALESCE(consumed_at, CURRENT_TIMESTAMP),
                 reset_token_hash = NULL,
                 reset_token_expires_at = NULL
             WHERE user_id = :user_id
               AND id <> :id
               AND reset_completed_at IS NULL
               AND (consumed_at IS NULL OR reset_token_hash IS NOT NULL)'
        );
        $invalidateOtherChallenges->execute([
            ':user_id' => (int)$account['id'],
            ':id' => (int)$challengeId,
        ]);

        $conn->commit();
        return ['success' => true, 'message' => 'Password reset successfully. You can now sign in.'];
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}
