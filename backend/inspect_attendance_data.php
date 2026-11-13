<?php
$dbhost = 'localhost';
$dbname = 'gym_management';
$dbuser = 'root';
$dbpass = '';

try {
    $conn = new PDO('mysql:host=' . $dbhost . ';dbname=' . $dbname, $dbuser, $dbpass);
    
    echo "=== MEMBER ATTENDANCE RECORDS ===" . PHP_EOL;
    echo PHP_EOL;
    
    // Count member attendance records
    $result = $conn->query('SELECT COUNT(*) as count FROM attendance;');
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $memberAttendanceCount = $row['count'];
    echo "Total Member Attendance Records: " . $memberAttendanceCount . PHP_EOL;
    
    if ($memberAttendanceCount > 0) {
        // Get date range
        $result = $conn->query('SELECT MIN(attendance_date) as earliest, MAX(attendance_date) as latest FROM attendance;');
        $row = $result->fetch(PDO::FETCH_ASSOC);
        echo "Earliest Date: " . $row['earliest'] . PHP_EOL;
        echo "Latest Date: " . $row['latest'] . PHP_EOL;
        
        // Sample records
        echo PHP_EOL . "Sample Records (First 5):";
        $result = $conn->query('SELECT id, member_id, attendance_date, time_in, time_out, created_at FROM attendance ORDER BY id ASC LIMIT 5;');
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            echo PHP_EOL . "  ID: " . $row['id'] . " | Date: " . $row['attendance_date'] . " | Time In: " . $row['time_in'] . " | Time Out: " . $row['time_out'] . " | Created: " . $row['created_at'];
        }
        
        echo PHP_EOL . PHP_EOL . "Sample Records (Last 5):";
        $result = $conn->query('SELECT id, member_id, attendance_date, time_in, time_out, created_at FROM attendance ORDER BY id DESC LIMIT 5;');
        $samples = $result->fetchAll(PDO::FETCH_ASSOC);
        $samples = array_reverse($samples);
        foreach ($samples as $row) {
            echo PHP_EOL . "  ID: " . $row['id'] . " | Date: " . $row['attendance_date'] . " | Time In: " . $row['time_in'] . " | Time Out: " . $row['time_out'] . " | Created: " . $row['created_at'];
        }
    }
    
    echo PHP_EOL . PHP_EOL;
    echo "=== TRAINER ATTENDANCE RECORDS ===" . PHP_EOL;
    echo PHP_EOL;
    
    // Count trainer attendance records
    $result = $conn->query('SELECT COUNT(*) as count FROM trainer_attendance;');
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $trainerAttendanceCount = $row['count'];
    echo "Total Trainer Attendance Records: " . $trainerAttendanceCount . PHP_EOL;
    
    if ($trainerAttendanceCount > 0) {
        // Get date range
        $result = $conn->query('SELECT MIN(attendance_date) as earliest, MAX(attendance_date) as latest FROM trainer_attendance;');
        $row = $result->fetch(PDO::FETCH_ASSOC);
        echo "Earliest Date: " . $row['earliest'] . PHP_EOL;
        echo "Latest Date: " . $row['latest'] . PHP_EOL;
        
        // Sample records
        echo PHP_EOL . "Sample Records (First 5):";
        $result = $conn->query('SELECT id, trainer_id, attendance_date, time_in, time_out, status, created_at FROM trainer_attendance ORDER BY id ASC LIMIT 5;');
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            echo PHP_EOL . "  ID: " . $row['id'] . " | Date: " . $row['attendance_date'] . " | Time In: " . $row['time_in'] . " | Time Out: " . $row['time_out'] . " | Status: " . $row['status'] . " | Created: " . $row['created_at'];
        }
        
        echo PHP_EOL . PHP_EOL . "Sample Records (Last 5):";
        $result = $conn->query('SELECT id, trainer_id, attendance_date, time_in, time_out, status, created_at FROM trainer_attendance ORDER BY id DESC LIMIT 5;');
        $samples = $result->fetchAll(PDO::FETCH_ASSOC);
        $samples = array_reverse($samples);
        foreach ($samples as $row) {
            echo PHP_EOL . "  ID: " . $row['id'] . " | Date: " . $row['attendance_date'] . " | Time In: " . $row['time_in'] . " | Time Out: " . $row['time_out'] . " | Status: " . $row['status'] . " | Created: " . $row['created_at'];
        }
    }
    
    echo PHP_EOL . PHP_EOL;
    echo "=== ASSESSMENT ===" . PHP_EOL;
    echo PHP_EOL;
    
    if ($memberAttendanceCount === 0 && $trainerAttendanceCount === 0) {
        echo "✓ Both attendance tables are EMPTY" . PHP_EOL;
        echo "✓ No historical migration required" . PHP_EOL;
        echo "✓ Safe to proceed with timezone fix" . PHP_EOL;
    } else if ($memberAttendanceCount > 0 || $trainerAttendanceCount > 0) {
        echo "⚠ Historical records detected" . PHP_EOL;
        echo "⚠ These records appear to be stored with " . ($memberAttendanceCount > 0 ? "date/time values that may be in Europe/Berlin timezone" : "") . PHP_EOL;
        echo "⚠ Data migration strategy required before final timezone fix" . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
