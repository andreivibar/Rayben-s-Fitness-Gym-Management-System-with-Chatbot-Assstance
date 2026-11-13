<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden.');
}

// Migration script: hashes plain-text passwords in `users` using bcrypt.
// Run from CLI: `php migrate_hash_passwords.php`

require_once __DIR__ . '/../config/database.php';

try {
    // Ensure password column can store bcrypt hashes
    $conn->exec("ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL");
} catch (PDOException $e) {
    // Non-fatal: maybe column already large enough or user lacks ALTER privileges
}

try {
    $stmt = $conn->query("SELECT id, password, email FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    foreach ($users as $u) {
        $pwd = $u['password'];
        $isHashed = (strpos($pwd, '$2y$') === 0 || strpos($pwd, '$2b$') === 0 || strpos($pwd, '$2a$') === 0);
        if ($isHashed) continue;

        // Skip empty passwords
        if ($pwd === null || $pwd === '') continue;

        $newHash = password_hash($pwd, PASSWORD_BCRYPT);
        $update = $conn->prepare("UPDATE users SET password = :hash WHERE id = :id");
        $update->bindParam(':hash', $newHash, PDO::PARAM_STR);
        $update->bindParam(':id', $u['id'], PDO::PARAM_INT);
        $update->execute();
        $updated++;
        echo "Updated user: {$u['email']} (id={$u['id']})\n";
    }

    echo "Migration complete. Total updated: $updated\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
