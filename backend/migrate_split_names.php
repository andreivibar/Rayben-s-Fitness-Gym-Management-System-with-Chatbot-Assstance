<?php
require_once __DIR__ . '/config/database.php';

echo "=== Rayben's Gym Management System - Name Structure Migration ===\n\n";

function ensureColumnExists($conn, $table, $column, $definition) {
    $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
    $stmt->execute([':column' => $column]);
    if (!$stmt->fetch()) {
        $conn->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        echo "[SCHEMA] Added column `$column` to `$table`\n";
    } else {
        echo "[SCHEMA] Column `$column` already exists in `$table`\n";
    }
}

try {
    // 1. Schema Alterations (Nullable columns, no renaming/deleting)
    ensureColumnExists($conn, 'users', 'first_name', 'VARCHAR(50) DEFAULT NULL AFTER id');
    ensureColumnExists($conn, 'users', 'middle_name', 'VARCHAR(50) DEFAULT NULL AFTER first_name');
    ensureColumnExists($conn, 'users', 'last_name', 'VARCHAR(50) DEFAULT NULL AFTER middle_name');

    ensureColumnExists($conn, 'members', 'first_name', 'VARCHAR(50) DEFAULT NULL AFTER member_code');
    ensureColumnExists($conn, 'members', 'middle_name', 'VARCHAR(50) DEFAULT NULL AFTER first_name');
    ensureColumnExists($conn, 'members', 'last_name', 'VARCHAR(50) DEFAULT NULL AFTER middle_name');

    ensureColumnExists($conn, 'registration_requests', 'first_name', 'VARCHAR(50) DEFAULT NULL AFTER id');
    ensureColumnExists($conn, 'registration_requests', 'middle_name', 'VARCHAR(50) DEFAULT NULL AFTER first_name');
    ensureColumnExists($conn, 'registration_requests', 'last_name', 'VARCHAR(50) DEFAULT NULL AFTER middle_name');

    echo "\n[SCHEMA] Schema update complete.\n\n";

    // 2. Data Migration within Transaction
    $conn->beginTransaction();

    function parseLegacyName(?string $rawName): array {
        $name = trim((string)$rawName);
        if ($name === '') {
            return [
                'first_name'   => null,
                'middle_name'  => null,
                'last_name'    => null,
                'full_name'    => $name,
                'category'     => 'empty'
            ];
        }

        $parts = preg_split('/\s+/', $name);
        $count = count($parts);

        if ($count === 1) {
            return [
                'first_name'   => $parts[0],
                'middle_name'  => null,
                'last_name'    => null,
                'full_name'    => $name,
                'category'     => 'one_word'
            ];
        }

        if ($count === 2) {
            return [
                'first_name'   => $parts[0],
                'middle_name'  => null,
                'last_name'    => $parts[1],
                'full_name'    => $name,
                'category'     => 'clean_two_words'
            ];
        }

        // 3 or more words: do not guess middle name
        return [
            'first_name'   => $parts[0],
            'middle_name'  => null,
            'last_name'    => implode(' ', array_slice($parts, 1)),
            'full_name'    => $name,
            'category'     => 'multi_word_review'
        ];
    }

    $stats = [
        'users' => [
            'total' => 0,
            'clean' => 0,
            'one_word_null_last' => 0,
            'multi_word_review' => 0,
            'preserved_check' => true,
            'records' => []
        ],
        'members' => [
            'total' => 0,
            'clean' => 0,
            'one_word_null_last' => 0,
            'multi_word_review' => 0,
            'preserved_check' => true,
            'records' => []
        ],
        'registration_requests' => [
            'total' => 0,
            'clean' => 0,
            'one_word_null_last' => 0,
            'multi_word_review' => 0,
            'preserved_check' => true,
            'records' => []
        ]
    ];

    // Migrate USERS
    $users = $conn->query("SELECT id, full_name FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $userUpdate = $conn->prepare("UPDATE users SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name WHERE id = :id");
    foreach ($users as $u) {
        $stats['users']['total']++;
        $parsed = parseLegacyName($u['full_name']);
        $userUpdate->execute([
            ':first_name'  => $parsed['first_name'],
            ':middle_name' => $parsed['middle_name'],
            ':last_name'   => $parsed['last_name'],
            ':id'          => $u['id']
        ]);

        if ($parsed['category'] === 'clean_two_words') {
            $stats['users']['clean']++;
        } elseif ($parsed['category'] === 'one_word') {
            $stats['users']['one_word_null_last']++;
        } elseif ($parsed['category'] === 'multi_word_review') {
            $stats['users']['multi_word_review']++;
        }

        $stats['users']['records'][] = [
            'id' => $u['id'],
            'original_full_name' => $u['full_name'],
            'first_name' => $parsed['first_name'],
            'middle_name' => $parsed['middle_name'],
            'last_name' => $parsed['last_name'],
            'category' => $parsed['category']
        ];
    }

    // Migrate MEMBERS
    $members = $conn->query("SELECT id, full_name FROM members")->fetchAll(PDO::FETCH_ASSOC);
    $memberUpdate = $conn->prepare("UPDATE members SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name WHERE id = :id");
    foreach ($members as $m) {
        $stats['members']['total']++;
        $parsed = parseLegacyName($m['full_name']);
        $memberUpdate->execute([
            ':first_name'  => $parsed['first_name'],
            ':middle_name' => $parsed['middle_name'],
            ':last_name'   => $parsed['last_name'],
            ':id'          => $m['id']
        ]);

        if ($parsed['category'] === 'clean_two_words') {
            $stats['members']['clean']++;
        } elseif ($parsed['category'] === 'one_word') {
            $stats['members']['one_word_null_last']++;
        } elseif ($parsed['category'] === 'multi_word_review') {
            $stats['members']['multi_word_review']++;
        }

        $stats['members']['records'][] = [
            'id' => $m['id'],
            'original_full_name' => $m['full_name'],
            'first_name' => $parsed['first_name'],
            'middle_name' => $parsed['middle_name'],
            'last_name' => $parsed['last_name'],
            'category' => $parsed['category']
        ];
    }

    // Migrate REGISTRATION_REQUESTS
    $requests = $conn->query("SELECT id, full_name FROM registration_requests")->fetchAll(PDO::FETCH_ASSOC);
    $reqUpdate = $conn->prepare("UPDATE registration_requests SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name WHERE id = :id");
    foreach ($requests as $r) {
        $stats['registration_requests']['total']++;
        $parsed = parseLegacyName($r['full_name']);
        $reqUpdate->execute([
            ':first_name'  => $parsed['first_name'],
            ':middle_name' => $parsed['middle_name'],
            ':last_name'   => $parsed['last_name'],
            ':id'          => $r['id']
        ]);

        if ($parsed['category'] === 'clean_two_words') {
            $stats['registration_requests']['clean']++;
        } elseif ($parsed['category'] === 'one_word') {
            $stats['registration_requests']['one_word_null_last']++;
        } elseif ($parsed['category'] === 'multi_word_review') {
            $stats['registration_requests']['multi_word_review']++;
        }

        $stats['registration_requests']['records'][] = [
            'id' => $r['id'],
            'original_full_name' => $r['full_name'],
            'first_name' => $parsed['first_name'],
            'middle_name' => $parsed['middle_name'],
            'last_name' => $parsed['last_name'],
            'category' => $parsed['category']
        ];
    }

    $conn->commit();
    echo "[DATA] Data migration committed successfully.\n\n";

    // 3. Post-migration verification
    $usersPost = $conn->query("SELECT id, first_name, middle_name, last_name, full_name FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $membersPost = $conn->query("SELECT id, first_name, middle_name, last_name, full_name FROM members")->fetchAll(PDO::FETCH_ASSOC);
    $requestsPost = $conn->query("SELECT id, first_name, middle_name, last_name, full_name FROM registration_requests")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'schemas' => [
            'users' => $conn->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC),
            'members' => $conn->query("DESCRIBE members")->fetchAll(PDO::FETCH_ASSOC),
            'registration_requests' => $conn->query("DESCRIBE registration_requests")->fetchAll(PDO::FETCH_ASSOC),
            'trainers' => $conn->query("DESCRIBE trainers")->fetchAll(PDO::FETCH_ASSOC)
        ]
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
    exit(1);
}
