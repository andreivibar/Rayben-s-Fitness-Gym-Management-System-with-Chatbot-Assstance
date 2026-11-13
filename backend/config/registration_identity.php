<?php

function findLoginAccountByIdentifier(PDO $conn, string $identifier): ?array
{
    $accountColumn = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
    $registrationIdentityFilter = registrationIdentityReleaseEnabled($conn)
        ? "AND registration_status = 'active'"
        : '';
    $stmt = $conn->prepare(
        "SELECT * FROM users
         WHERE LOWER({$accountColumn}) = LOWER(:identifier)
           {$registrationIdentityFilter}
         LIMIT 1"
    );
    $stmt->execute([':identifier' => $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function registrationIdentityReleaseEnabled(PDO $conn): bool
{
    static $schemaStateByConnection = [];
    $connectionId = spl_object_id($conn);
    if (array_key_exists($connectionId, $schemaStateByConnection)) {
        return $schemaStateByConnection[$connectionId];
    }

    $userColumns = $conn->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);
    $requestColumns = $conn->query('SHOW COLUMNS FROM registration_requests')->fetchAll(PDO::FETCH_COLUMN, 0);
    $userIndexes = $conn->query('SHOW INDEX FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $requestIndexes = $conn->query('SHOW INDEX FROM registration_requests')->fetchAll(PDO::FETCH_ASSOC);

    $requiredUserColumns = ['registration_status', 'registration_unique_username', 'registration_unique_email'];
    $requiredRequestColumns = ['registration_unique_username'];
    $hasUniqueSingleColumnIndex = static function (array $indexes, string $indexName, string $columnName): bool {
        $matchingIndexRows = array_values(array_filter(
            $indexes,
            static fn(array $index): bool => $index['Key_name'] === $indexName
        ));
        return count($matchingIndexRows) === 1
            && (int)$matchingIndexRows[0]['Non_unique'] === 0
            && (int)$matchingIndexRows[0]['Seq_in_index'] === 1
            && $matchingIndexRows[0]['Column_name'] === $columnName;
    };

    $schemaStateByConnection[$connectionId] = !array_diff($requiredUserColumns, $userColumns)
        && !array_diff($requiredRequestColumns, $requestColumns)
        && $hasUniqueSingleColumnIndex($userIndexes, 'uq_users_registration_username', 'registration_unique_username')
        && $hasUniqueSingleColumnIndex($userIndexes, 'uq_users_registration_email', 'registration_unique_email')
        && $hasUniqueSingleColumnIndex(
            $requestIndexes,
            'uq_registration_requests_active_username',
            'registration_unique_username'
        );

    return $schemaStateByConnection[$connectionId];
}
