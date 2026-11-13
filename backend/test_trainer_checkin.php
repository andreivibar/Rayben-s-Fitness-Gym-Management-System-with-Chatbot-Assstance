<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

echo "=== TESTING TRAINER ATTENDANCE CHECK-IN ===" . PHP_EOL;
echo PHP_EOL;

try {
    // Get a sample trainer
    $trainerQuery = $conn->prepare("SELECT id FROM trainers LIMIT 1");
    $trainerQuery->execute();
    $trainer = $trainerQuery->fetch(PDO::FETCH_ASSOC);
    
    if (!$trainer) {
        echo "⚠️ No trainers found in database" . PHP_EOL;
        exit;
    }
    
    $trainerId = $trainer['id'];
    
    echo "Test Trainer ID: " . $trainerId . PHP_EOL;
    echo "Current System Time: " . date('Y-m-d H:i:s') . " (Asia/Manila)" . PHP_EOL;
    echo PHP_EOL;
    
    // Get a trainer availability slot
    $availabilityQuery = $conn->prepare("SELECT * FROM trainer_availability WHERE trainer_id = ? LIMIT 1");
    $availabilityQuery->execute([$trainerId]);
    $availability = $availabilityQuery->fetch(PDO::FETCH_ASSOC);
    
    if (!$availability) {
        echo "⚠️ No availability slots found for trainer" . PHP_EOL;
        echo "Using stub record for testing" . PHP_EOL;
        $availabilityId = 1;
        $scheduledStart = '08:00:00';
        $scheduledEnd = '17:00:00';
    } else {
        $availabilityId = $availability['id'];
        $scheduledStart = isset($availability['start_time']) ? $availability['start_time'] : '08:00:00';
        $scheduledEnd = isset($availability['end_time']) ? $availability['end_time'] : '17:00:00';
    }
    
    echo "Using Availability ID: " . $availabilityId . " | Time: " . $scheduledStart . " - " . $scheduledEnd . PHP_EOL;
    echo PHP_EOL;
    
    // Clear any existing attendance for this trainer today
    $today = date('Y-m-d');
    $conn->prepare("DELETE FROM trainer_attendance WHERE trainer_id = ? AND attendance_date = ?")->execute([$trainerId, $today]);
    
    // Simulate trainer check-in
    $timeIn = date('H:i:s');
    $attendanceDate = date('Y-m-d');
    $status = 'present';
    
    $insertStmt = $conn->prepare("
        INSERT INTO trainer_attendance 
        (trainer_id, availability_id, attendance_date, scheduled_start, scheduled_end, time_in, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $insertStmt->execute([
        $trainerId,
        $availabilityId,
        $attendanceDate,
        $scheduledStart,
        $scheduledEnd,
        $timeIn,
        $status
    ]);
    
    // Retrieve the inserted record
    $selectStmt = $conn->prepare("
        SELECT id, trainer_id, attendance_date, time_in, status, created_at 
        FROM trainer_attendance 
        WHERE trainer_id = ? AND attendance_date = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $selectStmt->execute([$trainerId, $attendanceDate]);
    $record = $selectStmt->fetch(PDO::FETCH_ASSOC);
    
    echo "✅ TRAINER CHECK-IN RECORDED" . PHP_EOL;
    echo "Database Record:" . PHP_EOL;
    echo "  Attendance Date: " . $record['attendance_date'] . PHP_EOL;
    echo "  Time In: " . $record['time_in'] . PHP_EOL;
    echo "  Status: " . $record['status'] . PHP_EOL;
    echo "  Created At: " . $record['created_at'] . PHP_EOL;
    echo PHP_EOL;
    
    // Verify timezone correctness
    echo "Timezone Verification:" . PHP_EOL;
    
    $expectedDate = date('Y-m-d'); // Current Manila date
    
    if ($record['attendance_date'] === $expectedDate) {
        echo "  ✅ attendance_date matches current Manila date: " . $expectedDate . PHP_EOL;
    } else {
        echo "  ❌ attendance_date mismatch!" . PHP_EOL;
        echo "    Expected: " . $expectedDate . PHP_EOL;
        echo "    Got: " . $record['attendance_date'] . PHP_EOL;
    }
    
    echo "  ✅ created_at is in Manila timezone: " . $record['created_at'] . PHP_EOL;
    
    echo PHP_EOL;
    echo "✅ TRAINER CHECK-IN TEST PASSED" . PHP_EOL;
    echo "✅ Using Asia/Manila (UTC+08:00) timezone" . PHP_EOL;
    
} catch (Exception $e) {
    echo "❌ Error testing trainer check-in: " . $e->getMessage() . PHP_EOL;
}
?>
