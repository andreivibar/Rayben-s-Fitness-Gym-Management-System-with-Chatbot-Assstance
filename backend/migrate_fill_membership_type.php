<?php

require_once __DIR__ . '/config/database.php';

try {
    $sql = "UPDATE members
            SET membership_type = 'monthly'
            WHERE (membership_type = '' OR membership_type IS NULL)
              AND (
                    (membership_start IS NOT NULL AND membership_start <> '' AND membership_start <> '0000-00-00')
                 OR (membership_end IS NOT NULL AND membership_end <> '' AND membership_end <> '0000-00-00')
              )";

    $stmt = $conn->prepare($sql);
    $stmt->execute();

    $count = $stmt->rowCount();
    echo "Backfilled {$count} membership_type rows to 'monthly'.\n";
} catch (PDOException $e) {
    echo 'Migration failed: ' . $e->getMessage() . "\n";
    exit(1);
}
