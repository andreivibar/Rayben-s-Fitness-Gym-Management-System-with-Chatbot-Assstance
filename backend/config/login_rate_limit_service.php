<?php

const LOGIN_RATE_LIMIT_GENERIC_MESSAGE = 'Incorrect username or password. If you have tried repeatedly, sign-in may be temporarily unavailable; wait before trying again.';
const LOGIN_RATE_LIMIT_IP_MESSAGE = 'Login is temporarily unavailable due to repeated attempts. Please try again later.';

function loginRateLimitEnvironmentVariable(string $name): string
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
            $lines = @file($environmentFile, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                throw new RuntimeException('Login rate-limit configuration is unavailable.');
            }
            $fileValues = [];
            foreach ($lines as $line) {
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

function loginRateLimitHmacKey(): string
{
    static $key = null;
    if ($key === null) {
        $configuredKey = loginRateLimitEnvironmentVariable('LOGIN_RATE_LIMIT_HMAC_KEY');
        if (strlen($configuredKey) < 32) {
            throw new RuntimeException('Login rate-limit security configuration is unavailable.');
        }
        $key = $configuredKey;
    }

    return $key;
}

function loginRateLimitIntegerSetting(string $name, int $default, int $minimum, int $maximum): int
{
    $configured = loginRateLimitEnvironmentVariable($name);
    if ($configured === '') {
        return $default;
    }
    if (!preg_match('/^\d+$/D', $configured)) {
        throw new RuntimeException('Login rate-limit configuration is invalid.');
    }

    $value = (int)$configured;
    if ($value < $minimum || $value > $maximum) {
        throw new RuntimeException('Login rate-limit configuration is invalid.');
    }

    return $value;
}

function loginRateLimitNormalizeIdentifier(string $identifier): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', $identifier));
    if (filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
        return strtolower($normalized);
    }

    return strtolower($normalized);
}

function loginRateLimitDigest(string $purpose, string $value): string
{
    return hash_hmac('sha256', 'login-rate-limit:' . $purpose . ':' . $value, loginRateLimitHmacKey());
}

function loginRateLimitAddressInCidr(string $address, string $cidr): bool
{
    $parts = explode('/', $cidr, 2);
    $network = @inet_pton($parts[0]);
    $packedAddress = @inet_pton($address);
    if ($network === false || $packedAddress === false || strlen($network) !== strlen($packedAddress)) {
        return false;
    }

    $prefixLength = isset($parts[1]) ? filter_var($parts[1], FILTER_VALIDATE_INT) : strlen($network) * 8;
    if ($prefixLength === false || $prefixLength < 0 || $prefixLength > strlen($network) * 8) {
        return false;
    }

    $wholeBytes = intdiv($prefixLength, 8);
    if (substr($network, 0, $wholeBytes) !== substr($packedAddress, 0, $wholeBytes)) {
        return false;
    }

    $remainingBits = $prefixLength % 8;
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($network[$wholeBytes]) & $mask) === (ord($packedAddress[$wholeBytes]) & $mask);
}

function loginRateLimitTrustedProxies(): array
{
    $configured = loginRateLimitEnvironmentVariable('LOGIN_TRUSTED_PROXIES');
    if ($configured === '') {
        return [];
    }

    $proxies = [];
    foreach (explode(',', $configured) as $entry) {
        $cidr = trim($entry);
        $address = explode('/', $cidr, 2)[0];
        if ($cidr === '' || filter_var($address, FILTER_VALIDATE_IP) === false
            || (strpos($cidr, '/') !== false && !loginRateLimitAddressInCidr($address, $cidr))) {
            throw new RuntimeException('Login trusted-proxy configuration is invalid.');
        }
        $proxies[] = $cidr;
    }

    return $proxies;
}

function loginRateLimitIsTrustedProxy(string $address, array $trustedProxies): bool
{
    foreach ($trustedProxies as $cidr) {
        if (loginRateLimitAddressInCidr($address, $cidr)) {
            return true;
        }
    }

    return false;
}

function loginRateLimitClientIp(): string
{
    $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        throw new RuntimeException('The client network address could not be determined.');
    }

    $trustedProxies = loginRateLimitTrustedProxies();
    if (!$trustedProxies || !loginRateLimitIsTrustedProxy($remoteAddress, $trustedProxies)) {
        return $remoteAddress;
    }

    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded === '') {
        return $remoteAddress;
    }

    $chain = array_map('trim', explode(',', $forwarded));
    foreach ($chain as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return $remoteAddress;
        }
    }

    $chain[] = $remoteAddress;
    for ($index = count($chain) - 1; $index >= 0; $index--) {
        if (!loginRateLimitIsTrustedProxy($chain[$index], $trustedProxies)) {
            return $chain[$index];
        }
    }

    return $chain[0];
}

function loginRateLimitBucketDefinitions(string $identifier, ?int $userId, string $clientIp): array
{
    $accountLimit = loginRateLimitIntegerSetting('LOGIN_ACCOUNT_FAILURE_LIMIT', 5, 1, 1000);
    $accountWindow = loginRateLimitIntegerSetting('LOGIN_ACCOUNT_FAILURE_WINDOW_SECONDS', 900, 1, 86400);
    $accountCooldown = loginRateLimitIntegerSetting('LOGIN_ACCOUNT_COOLDOWN_SECONDS', 900, 1, 86400);
    $ipLimit = loginRateLimitIntegerSetting('LOGIN_IP_FAILURE_LIMIT', 30, 1, 10000);
    $ipWindow = loginRateLimitIntegerSetting('LOGIN_IP_FAILURE_WINDOW_SECONDS', 900, 1, 86400);
    $burstLimit = loginRateLimitIntegerSetting('LOGIN_IP_BURST_LIMIT', 5, 1, 10000);
    $burstWindow = loginRateLimitIntegerSetting('LOGIN_IP_BURST_WINDOW_SECONDS', 60, 1, 86400);

    $identityScope = $userId !== null
        ? ['account_ip', 'account:' . $userId]
        : ['identifier_ip', 'identifier:' . loginRateLimitNormalizeIdentifier($identifier)];
    $buckets = [
        [
            'scope_type' => $identityScope[0],
            'scope_hash' => loginRateLimitDigest($identityScope[0], $identityScope[1] . "\0" . $clientIp),
            'limit' => $accountLimit,
            'window' => $accountWindow,
            'cooldown' => $accountCooldown,
            'account_related' => true,
        ],
        [
            'scope_type' => 'ip_burst',
            'scope_hash' => loginRateLimitDigest('ip_burst', $clientIp),
            'limit' => $burstLimit,
            'window' => $burstWindow,
            'cooldown' => 0,
            'account_related' => false,
        ],
        [
            'scope_type' => 'ip_window',
            'scope_hash' => loginRateLimitDigest('ip_window', $clientIp),
            'limit' => $ipLimit,
            'window' => $ipWindow,
            'cooldown' => 0,
            'account_related' => false,
        ],
    ];

    usort($buckets, static function (array $left, array $right): int {
        return [$left['scope_type'], $left['scope_hash']] <=> [$right['scope_type'], $right['scope_hash']];
    });

    return $buckets;
}

function loginRateLimitPruneExpiredRows(PDO $conn): int
{
    $deleteEvents = $conn->prepare(
        'DELETE FROM login_rate_limit_events
         WHERE failed_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 DAY)
         ORDER BY failed_at
         LIMIT 500'
    );
    $deleteEvents->execute();

    $deleteScopes = $conn->prepare(
        'DELETE FROM login_rate_limits
         WHERE updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 DAY)
           AND (cooldown_until IS NULL OR cooldown_until <= CURRENT_TIMESTAMP)
           AND NOT EXISTS (
               SELECT 1
               FROM login_rate_limit_events e
               WHERE e.scope_type = login_rate_limits.scope_type
                 AND e.scope_hash = login_rate_limits.scope_hash
           )
         ORDER BY updated_at
         LIMIT 100'
    );
    $deleteScopes->execute();

    return $deleteEvents->rowCount() + $deleteScopes->rowCount();
}

function loginRateLimitEnsureBucketRows(PDO $conn, array $buckets): void
{
    $insert = $conn->prepare(
        'INSERT IGNORE INTO login_rate_limits (scope_type, scope_hash, cooldown_until)
         VALUES (:scope_type, :scope_hash, NULL)'
    );
    foreach ($buckets as $bucket) {
        $insert->execute([
            ':scope_type' => $bucket['scope_type'],
            ':scope_hash' => $bucket['scope_hash'],
        ]);
    }
}

function loginRateLimitIpScopesBlocked(PDO $conn, array $buckets): bool
{
    foreach ($buckets as $bucket) {
        if ($bucket['account_related']) {
            continue;
        }
        $window = (int)$bucket['window'];
        $check = $conn->prepare(
            "SELECT
                (r.cooldown_until > CURRENT_TIMESTAMP) AS cooldown_active,
                (SELECT COUNT(*)
                 FROM login_rate_limit_events e
                 WHERE e.scope_type = r.scope_type
                   AND e.scope_hash = r.scope_hash
                   AND e.failed_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$window} SECOND)
                ) AS recent_failures
             FROM login_rate_limits r
             WHERE r.scope_type = :scope_type AND r.scope_hash = :scope_hash
             LIMIT 1"
        );
        $check->execute([
            ':scope_type' => $bucket['scope_type'],
            ':scope_hash' => $bucket['scope_hash'],
        ]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ($row && ((int)$row['cooldown_active'] === 1
            || (int)$row['recent_failures'] >= (int)$bucket['limit'])) {
            return true;
        }
    }

    return false;
}

function loginRateLimitTransientDatabaseError(PDOException $error): bool
{
    $driverCode = isset($error->errorInfo[1]) ? (int)$error->errorInfo[1] : 0;
    return $error->getCode() === '40001' || in_array($driverCode, [1205, 1213], true);
}

function loginRateLimitRun(PDO $conn, string $identifier, ?int $userId, callable $authenticate): array
{
    if ($conn->inTransaction()) {
        throw new RuntimeException('Login rate limiting must own its database transaction.');
    }

    $clientIp = loginRateLimitClientIp();
    $buckets = loginRateLimitBucketDefinitions($identifier, $userId, $clientIp);
    $ipBuckets = array_values(array_filter(
        $buckets,
        static function (array $bucket): bool {
            return !$bucket['account_related'];
        }
    ));

    loginRateLimitEnsureBucketRows($conn, $ipBuckets);
    if (loginRateLimitIpScopesBlocked($conn, $ipBuckets)) {
        return ['status' => 'ip_limited'];
    }

    if (random_int(1, 100) === 1) {
        loginRateLimitPruneExpiredRows($conn);
    }

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            loginRateLimitEnsureBucketRows($conn, $buckets);
            $conn->beginTransaction();
            $lockedBuckets = [];
            foreach ($buckets as $bucket) {
                $select = $conn->prepare(
                    'SELECT scope_type, scope_hash, cooldown_until,
                            (cooldown_until > CURRENT_TIMESTAMP) AS cooldown_active
                     FROM login_rate_limits
                     WHERE scope_type = :scope_type AND scope_hash = :scope_hash
                     FOR UPDATE'
                );
                $select->execute([
                    ':scope_type' => $bucket['scope_type'],
                    ':scope_hash' => $bucket['scope_hash'],
                ]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException('Login rate-limit state could not be locked.');
                }
                $count = $conn->prepare(
                    "SELECT COUNT(*)
                     FROM login_rate_limit_events
                     WHERE scope_type = :scope_type
                       AND scope_hash = :scope_hash
                       AND failed_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL " . (int)$bucket['window'] . " SECOND)"
                );
                $count->execute([
                    ':scope_type' => $bucket['scope_type'],
                    ':scope_hash' => $bucket['scope_hash'],
                ]);
                $lockedBuckets[] = $bucket + [
                    'row' => $row,
                    'failure_count' => (int)$count->fetchColumn(),
                ];
            }

            $accountLimited = false;
            $ipLimited = false;
            foreach ($lockedBuckets as $bucket) {
                $row = $bucket['row'];
                $limitReached = $bucket['failure_count'] >= (int)$bucket['limit'];
                if ((int)$row['cooldown_active'] !== 1 && !$limitReached) {
                    continue;
                }
                if ($bucket['account_related']) {
                    $accountLimited = true;
                } else {
                    $ipLimited = true;
                }
            }

            if ($ipLimited || $accountLimited) {
                $touch = $conn->prepare(
                    'UPDATE login_rate_limits
                     SET updated_at = CURRENT_TIMESTAMP
                     WHERE scope_type = :scope_type AND scope_hash = :scope_hash'
                );
                foreach ($lockedBuckets as $bucket) {
                    $touch->execute([
                        ':scope_type' => $bucket['scope_type'],
                        ':scope_hash' => $bucket['scope_hash'],
                    ]);
                }
                $conn->commit();
                return ['status' => $ipLimited ? 'ip_limited' : 'account_limited'];
            }

            $authentication = $authenticate($conn);
            if (!is_array($authentication) || !array_key_exists('authenticated', $authentication)) {
                throw new RuntimeException('Login authentication did not return a valid result.');
            }

            if ($authentication['authenticated'] === true) {
                foreach ($lockedBuckets as $bucket) {
                    if (!$bucket['account_related']) {
                        continue;
                    }
                    $deleteFailures = $conn->prepare(
                        'DELETE FROM login_rate_limit_events
                         WHERE scope_type = :scope_type AND scope_hash = :scope_hash'
                    );
                    $deleteFailures->execute([
                        ':scope_type' => $bucket['scope_type'],
                        ':scope_hash' => $bucket['scope_hash'],
                    ]);
                    $reset = $conn->prepare(
                        'UPDATE login_rate_limits
                         SET cooldown_until = NULL, updated_at = CURRENT_TIMESTAMP
                         WHERE scope_type = :scope_type AND scope_hash = :scope_hash'
                    );
                    $reset->execute([
                        ':scope_type' => $bucket['scope_type'],
                        ':scope_hash' => $bucket['scope_hash'],
                    ]);
                }
                $conn->commit();
                return ['status' => 'authenticated', 'response' => $authentication['response'] ?? []];
            }

            $attemptId = bin2hex(random_bytes(16));
            $insertFailure = $conn->prepare(
                'INSERT INTO login_rate_limit_events
                    (scope_type, scope_hash, attempt_id, failed_at)
                 VALUES
                    (:scope_type, :scope_hash, :attempt_id, CURRENT_TIMESTAMP)'
            );
            foreach ($lockedBuckets as $bucket) {
                $insertFailure->execute([
                    ':scope_type' => $bucket['scope_type'],
                    ':scope_hash' => $bucket['scope_hash'],
                    ':attempt_id' => $attemptId,
                ]);
                $setCooldown = $bucket['account_related']
                    && $bucket['failure_count'] + 1 >= $bucket['limit']
                    && $bucket['cooldown'] > 0;
                $updateScope = $conn->prepare(
                    "UPDATE login_rate_limits
                     SET cooldown_until = IF(
                         :set_cooldown = 1,
                         DATE_ADD(CURRENT_TIMESTAMP, INTERVAL " . max(1, (int)$bucket['cooldown']) . " SECOND),
                         NULL
                     ),
                     updated_at = CURRENT_TIMESTAMP
                     WHERE scope_type = :scope_type AND scope_hash = :scope_hash"
                );
                $updateScope->execute([
                    ':set_cooldown' => $setCooldown ? 1 : 0,
                    ':scope_type' => $bucket['scope_type'],
                    ':scope_hash' => $bucket['scope_hash'],
                ]);
            }

            $conn->commit();
            return ['status' => 'failed'];
        } catch (PDOException $error) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            if ($attempt < 3 && loginRateLimitTransientDatabaseError($error)) {
                usleep(random_int(1000, 10000) * $attempt);
                continue;
            }
            throw $error;
        } catch (Throwable $error) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $error;
        }
    }

    throw new RuntimeException('Login rate-limit transaction could not be completed.');
}

function loginRateLimitBurnDummyPasswordCheck(string $password): void
{
    static $dummyHash = null;
    if ($dummyHash === null) {
        $dummyHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        if (!is_string($dummyHash)) {
            throw new RuntimeException('Unable to prepare login verification.');
        }
    }

    password_verify($password, $dummyHash);
}

