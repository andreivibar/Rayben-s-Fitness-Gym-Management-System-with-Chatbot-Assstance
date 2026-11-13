<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

echo "=== TIMEZONE VERIFICATION ===" . PHP_EOL;
echo PHP_EOL;

// Verify PHP timezone
$phpTz = date_default_timezone_get();
echo "PHP Default Timezone: " . $phpTz . PHP_EOL;

// Verify current PHP time
echo "PHP Current Time: " . date('Y-m-d H:i:s') . PHP_EOL;

// Verify MySQL session timezone
try {
    $result = $conn->query("SELECT @@session.time_zone, NOW() as mysql_now;");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "MySQL Session Timezone: " . $row['@@session.time_zone'] . PHP_EOL;
    echo "MySQL NOW(): " . $row['mysql_now'] . PHP_EOL;
    
    echo PHP_EOL;
    
    if ($phpTz === 'Asia/Manila' && $row['@@session.time_zone'] === '+08:00') {
        echo "✅ TIMEZONE CONFIGURATION VERIFIED" . PHP_EOL;
        echo "✅ PHP: Asia/Manila" . PHP_EOL;
        echo "✅ MySQL: +08:00 (UTC+8)" . PHP_EOL;
    } else {
        echo "⚠️ TIMEZONE MISMATCH" . PHP_EOL;
        echo "PHP: " . $phpTz . " (expected: Asia/Manila)" . PHP_EOL;
        echo "MySQL: " . $row['@@session.time_zone'] . " (expected: +08:00)" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "❌ Error verifying timezone: " . $e->getMessage() . PHP_EOL;
}
?>
