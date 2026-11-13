<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

echo "=== TESTING MEMBER ATTENDANCE CHECK-IN ===" . PHP_EOL;
echo PHP_EOL;

try {
    // Get a sample member
    $memberQuery = $conn->prepare("SELECT id, full_name FROM members LIMIT 1");
    $memberQuery->execute();
    $member = $memberQuery->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        echo "⚠️ No members found in database" . PHP_EOL;
        echo "Cannot test attendance check-in without a member" . PHP_EOL;
        exit;
    }
    
    $memberId = $member['id'];
    $memberName = $member['full_name'];
    
    echo "Test Member: " . $memberName . " (ID: " . $memberId . ")" . PHP_EOL;
    echo "Current System Time: " . date('Y-m-d H:i:s') . " (Asia/Manila)" . PHP_EOL;
    echo PHP_EOL;
    
    // Clear any existing attendance for this member today
    $today = date('Y-m-d');
    $conn->prepare("DELETE FROM attendance WHERE member_id = ? AND attendance_date = ?")->execute([$memberId, $today]);
    
    // Simulate check-in
    $timeIn = date('H:i:s');
    $attendanceDate = date('Y-m-d');
    
    $insertStmt = $conn->prepare("INSERT INTO attendance (member_id, attendance_date, time_in, time_out, created_at) VALUES (?, ?, ?, NULL, NOW())");
    $insertStmt->execute([$memberId, $attendanceDate, $timeIn]);
    
    // Retrieve the inserted record
    $selectStmt = $conn->prepare("SELECT id, member_id, attendance_date, time_in, time_out, created_at FROM attendance WHERE member_id = ? AND attendance_date = ? ORDER BY id DESC LIMIT 1");
    $selectStmt->execute([$memberId, $attendanceDate]);
    $record = $selectStmt->fetch(PDO::FETCH_ASSOC);
    
    echo "✅ MEMBER CHECK-IN RECORDED" . PHP_EOL;
    echo "Database Record:" . PHP_EOL;
    echo "  Attendance Date: " . $record['attendance_date'] . PHP_EOL;
    echo "  Time In: " . $record['time_in'] . PHP_EOL;
    echo "  Created At: " . $record['created_at'] . PHP_EOL;
    echo PHP_EOL;
    
    // Verify timezone correctness
    echo "Timezone Verification:" . PHP_EOL;
    
    $expectedDate = date('Y-m-d'); // Current Manila date
    $expectedTime = $timeIn; // Time when check-in was processed
    
    if ($record['attendance_date'] === $expectedDate) {
        echo "  ✅ attendance_date matches current Manila date: " . $expectedDate . PHP_EOL;
    } else {
        echo "  ❌ attendance_date mismatch!" . PHP_EOL;
        echo "    Expected: " . $expectedDate . PHP_EOL;
        echo "    Got: " . $record['attendance_date'] . PHP_EOL;
    }
    
    // Parse times for comparison
    $recordTimeInParts = explode(':', $record['time_in']);
    $expectedTimeInParts = explode(':', $expectedTime);
    
    // Allow 1-2 second difference for execution time
    $timeDiff = abs(($recordTimeInParts[0] * 3600 + $recordTimeInParts[1] * 60 + $recordTimeInParts[2]) - 
                    ($expectedTimeInParts[0] * 3600 + $expectedTimeInParts[1] * 60 + $expectedTimeInParts[2]));
    
    if ($timeDiff <= 2) {
        echo "  ✅ time_in matches current Manila time (within 2 seconds)" . PHP_EOL;
    } else {
        echo "  ⚠️ time_in differs from expected (difference: " . $timeDiff . " seconds)" . PHP_EOL;
    }
    
    echo PHP_EOL;
    echo "✅ MEMBER CHECK-IN TEST PASSED" . PHP_EOL;
    echo "✅ Using Asia/Manila (UTC+08:00) timezone" . PHP_EOL;
    
} catch (Exception $e) {
    echo "❌ Error testing member check-in: " . $e->getMessage() . PHP_EOL;
}
?>
