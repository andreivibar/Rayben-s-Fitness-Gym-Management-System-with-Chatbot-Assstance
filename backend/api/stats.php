<?php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$currentUser = requireRole($conn, ['admin', 'trainer', 'member']);

try {
    // Use PHP date to avoid MySQL timezone mismatch
    $manila = new DateTimeZone('Asia/Manila');
    $today = (new DateTimeImmutable('now', $manila))->format('Y-m-d');

    // Total members
    $stmt = $conn->prepare('SELECT COUNT(*) as total_members FROM members');
    $stmt->execute();
    $totalMembers = intval($stmt->fetchColumn());

    // Keep active visits visible after midnight while date-based totals remain Manila-local.
    $summarySql = "SELECT
        (SELECT COUNT(*) FROM attendance WHERE attendance_date = :today_total) as total_today,
        (SELECT COUNT(*) FROM attendance WHERE time_in IS NOT NULL AND time_out IS NULL) as checked_in,
        (SELECT COUNT(*) FROM attendance WHERE attendance_date = :today_completed AND time_in IS NOT NULL AND time_out IS NOT NULL) as completed";

    $sStmt = $conn->prepare($summarySql);
    $sStmt->bindValue(':today_total', $today, PDO::PARAM_STR);
    $sStmt->bindValue(':today_completed', $today, PDO::PARAM_STR);
    $sStmt->execute();
    $summary = $sStmt->fetch(PDO::FETCH_ASSOC);

    // Bookings summary
    $newBookings = 0;
    $todayBookings = 0;
    try {
        $bkgStmt = $conn->prepare("SELECT
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as new_bookings,
            SUM(CASE WHEN booking_date = :today_bookings AND status IN ('pending', 'confirmed') THEN 1 ELSE 0 END) as today_bookings
            FROM bookings");
        $bkgStmt->execute([':today_bookings' => $today]);
        if ($bkgRow = $bkgStmt->fetch(PDO::FETCH_ASSOC)) {
            $newBookings = intval($bkgRow['new_bookings'] ?? 0);
            $todayBookings = intval($bkgRow['today_bookings'] ?? 0);
        }
    } catch (PDOException $e) {
        error_log('Dashboard booking statistics query failed: ' . $e->getMessage());
    }

    $response = [
        'success' => true,
        'total_members' => $totalMembers,
        'summary' => [
            'total_today' => intval($summary['total_today'] ?? 0),
            'checked_in' => intval($summary['checked_in'] ?? 0),
            'completed' => intval($summary['completed'] ?? 0),
            'date' => $today
        ],
        'bookings' => [
            'new_bookings' => $newBookings,
            'today_bookings' => $todayBookings
        ],
        'timestamp' => (new DateTimeImmutable('now', $manila))->format(DATE_ATOM)
    ];

    $role = strtolower(trim((string)($currentUser['role'] ?? '')));
    if (in_array($role, ['admin', 'administrator'], true)) {
        $monthStart = (new DateTimeImmutable('first day of this month', $manila))
            ->setTime(0, 0)
            ->modify('-11 months')
            ->format('Y-m-d');
        $monthlyRevenueStmt = $conn->prepare(
            "SELECT revenue_month, SUM(revenue) AS revenue
             FROM (
                 SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS revenue_month, SUM(p.amount) AS revenue
                 FROM payments p
                 WHERE p.payment_date >= :payment_start
                   AND ((p.payment_method = 'cash' AND p.status = 'paid')
                     OR (p.payment_method = 'gcash' AND p.status = 'paid' AND p.verification_status = 'verified'))
                 GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
                 UNION ALL
                 SELECT DATE_FORMAT(br.processed_at, '%Y-%m') AS revenue_month, -SUM(br.amount) AS revenue
                 FROM booking_refunds br
                 WHERE br.status = 'processed' AND br.processed_at >= :refund_start
                 GROUP BY DATE_FORMAT(br.processed_at, '%Y-%m')
                 UNION ALL
                 SELECT DATE_FORMAT(COALESCE(DATE(dp.verified_at), DATE(dp.created_at)), '%Y-%m') AS revenue_month,
                        SUM(100) AS revenue
                 FROM daily_pass_transactions dp
                 WHERE COALESCE(DATE(dp.verified_at), DATE(dp.created_at)) >= :pass_start
                   AND dp.amount = 100
                   AND ((dp.payment_method = 'cash' AND dp.payment_status = 'paid')
                     OR (dp.payment_method = 'gcash' AND dp.payment_status = 'paid' AND dp.verified_at IS NOT NULL))
                 GROUP BY DATE_FORMAT(COALESCE(DATE(dp.verified_at), DATE(dp.created_at)), '%Y-%m')
             ) confirmed_revenue
             GROUP BY revenue_month
             ORDER BY revenue_month"
        );
        $monthlyRevenueStmt->execute([
            ':payment_start' => $monthStart,
            ':refund_start' => $monthStart,
            ':pass_start' => $monthStart,
        ]);
        $response['monthly_revenue'] = $monthlyRevenueStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode($response);
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}

?>
