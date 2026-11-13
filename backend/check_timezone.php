<?php
$dbhost = 'localhost';
$dbname = 'gym_management';
$dbuser = 'root';
$dbpass = '';

try {
    $conn = new PDO('mysql:host=' . $dbhost . ';dbname=' . $dbname, $dbuser, $dbpass);
    
    // Get current PHP timezone
    echo "PHP Default Timezone: " . date_default_timezone_get() . PHP_EOL;
    echo "Current PHP date/time: " . date('Y-m-d H:i:s') . PHP_EOL;
    echo PHP_EOL;
    
    // Get current session timezone
    $result = $conn->query('SELECT @@session.time_zone, @@global.time_zone;');
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "MySQL Session/Global Timezone:" . PHP_EOL;
    echo "session.time_zone: " . $row['@@session.time_zone'] . PHP_EOL;
    echo "global.time_zone: " . $row['@@global.time_zone'] . PHP_EOL;
    echo PHP_EOL;
    
    // Get attendance table structure
    $result = $conn->query('DESCRIBE attendance;');
    echo "Attendance Table Structure:" . PHP_EOL;
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "Field: " . $row['Field'] . " Type: " . $row['Type'] . PHP_EOL;
    }
    echo PHP_EOL;
    
    // Get trainer_attendance table structure
    $result = $conn->query('DESCRIBE trainer_attendance;');
    echo "Trainer Attendance Table Structure:" . PHP_EOL;
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "Field: " . $row['Field'] . " Type: " . $row['Type'] . PHP_EOL;
    }
    
    // Test what NOW() and CURDATE() return
    echo PHP_EOL . "Database Time Functions:" . PHP_EOL;
    $result = $conn->query('SELECT NOW() as now_time, CURDATE() as cur_date, CURTIME() as cur_time;');
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "NOW(): " . $row['now_time'] . PHP_EOL;
    echo "CURDATE(): " . $row['cur_date'] . PHP_EOL;
    echo "CURTIME(): " . $row['cur_time'] . PHP_EOL;
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
