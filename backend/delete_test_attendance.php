<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

echo "=== DELETING TEST ATTENDANCE DATA ===" . PHP_EOL;
echo PHP_EOL;

try {
    // Get count before deletion
    $result = $conn->query("SELECT COUNT(*) as count FROM attendance;");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $memberCountBefore = $row['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM trainer_attendance;");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $trainerCountBefore = $row['count'];
    
    echo "Before Deletion:" . PHP_EOL;
    echo "  Member Attendance Records: " . $memberCountBefore . PHP_EOL;
    echo "  Trainer Attendance Records: " . $trainerCountBefore . PHP_EOL;
    echo PHP_EOL;
    
    // Delete all member attendance records
    $conn->exec("DELETE FROM attendance;");
    
    // Delete all trainer attendance records
    $conn->exec("DELETE FROM trainer_attendance;");
    
    // Verify deletion
    $result = $conn->query("SELECT COUNT(*) as count FROM attendance;");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $memberCountAfter = $row['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM trainer_attendance;");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $trainerCountAfter = $row['count'];
    
    echo "After Deletion:" . PHP_EOL;
    echo "  Member Attendance Records: " . $memberCountAfter . PHP_EOL;
    echo "  Trainer Attendance Records: " . $trainerCountAfter . PHP_EOL;
    echo PHP_EOL;
    
    if ($memberCountAfter === 0 && $trainerCountAfter === 0) {
        echo "✅ SUCCESSFULLY DELETED ALL TEST RECORDS" . PHP_EOL;
        echo "✅ Deleted Member Records: " . $memberCountBefore . PHP_EOL;
        echo "✅ Deleted Trainer Records: " . $trainerCountBefore . PHP_EOL;
        echo "✅ System ready for fresh attendance data with Asia/Manila timezone" . PHP_EOL;
    } else {
        echo "❌ ERROR: Some records remain" . PHP_EOL;
        echo "Member records: " . $memberCountAfter . " (expected 0)" . PHP_EOL;
        echo "Trainer records: " . $trainerCountAfter . " (expected 0)" . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo "❌ Error deleting records: " . $e->getMessage() . PHP_EOL;
}
?>
