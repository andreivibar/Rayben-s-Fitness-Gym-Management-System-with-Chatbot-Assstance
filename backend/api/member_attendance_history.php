<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (defined('MEMBER_ATTENDANCE_HISTORY_TESTING') && MEMBER_ATTENDANCE_HISTORY_TESTING === true) {
    $conn = $GLOBALS['member_attendance_history_test_connection'];
    $currentUser = $GLOBALS['member_attendance_history_test_user'];
} else {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
    $currentUser = requireRole($conn, ['member']);
}

function memberAttendanceHistoryResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    if (strtolower(trim((string)($currentUser['role'] ?? ''))) !== 'member') {
        memberAttendanceHistoryResponse(['success' => false, 'message' => 'Only members can view their attendance history.'], 403);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET, OPTIONS');
        memberAttendanceHistoryResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $manilaNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $currentYear = (int)$manilaNow->format('Y');
    $currentMonth = (int)$manilaNow->format('n');
    $yearValue = $_GET['year'] ?? $currentYear;
    $monthValue = $_GET['month'] ?? $currentMonth;
    $year = filter_var($yearValue, FILTER_VALIDATE_INT);
    $month = filter_var($monthValue, FILTER_VALIDATE_INT);
    if ($year === false || $year === null || $year < 2000 || $year > $currentYear) {
        memberAttendanceHistoryResponse(['success' => false, 'message' => 'Year must be between 2000 and the current Manila year.'], 400);
    }
    if ($month === false || $month === null || $month < 1 || $month > 12) {
        memberAttendanceHistoryResponse(['success' => false, 'message' => 'Month must be a number from 1 to 12.'], 400);
    }
    if ($year === $currentYear && $month > $currentMonth) {
        memberAttendanceHistoryResponse(['success' => false, 'message' => 'Attendance history is not available for a future month.'], 400);
    }

    $memberStmt = $conn->prepare(
        'SELECT id FROM members
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $memberStmt->execute([
        ':user_id' => (int)$currentUser['id'],
    ]);
    $memberId = $memberStmt->fetchColumn();
    if (!$memberId) {
        memberAttendanceHistoryResponse(['success' => false, 'message' => 'Member profile not found for this account.'], 404);
    }

    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $nextMonthStart = (new DateTimeImmutable($monthStart, new DateTimeZone('Asia/Manila')))
        ->modify('first day of next month')
        ->format('Y-m-d');
    $today = $manilaNow->format('Y-m-d');

    $attendanceStmt = $conn->prepare(
        'SELECT id, attendance_date, time_in, time_out
         FROM attendance
         WHERE member_id = :member_id
           AND time_in IS NOT NULL
           AND attendance_date >= :month_start
           AND attendance_date < :next_month_start
           AND attendance_date <= :today
         ORDER BY attendance_date ASC, time_in ASC, id ASC'
    );
    $attendanceStmt->execute([
        ':member_id' => (int)$memberId,
        ':month_start' => $monthStart,
        ':next_month_start' => $nextMonthStart,
        ':today' => $today,
    ]);
    $records = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($records as &$record) {
        $record['id'] = (int)$record['id'];
        $record['attendance_status'] = $record['time_out'] === null ? 'checked_in' : 'completed';
    }
    unset($record);

    $yearStart = sprintf('%04d-01-01', $currentYear);
    $nextYearStart = sprintf('%04d-01-01', $currentYear + 1);
    $summaryStmt = $conn->prepare(
        'SELECT
            SUM(CASE
                WHEN attendance_date >= :summary_month_start
                 AND attendance_date < :summary_next_month
                 AND attendance_date <= :summary_month_today
                THEN 1 ELSE 0
            END) AS monthly_check_ins,
            COUNT(DISTINCT CASE
                WHEN attendance_date >= :days_month_start
                 AND attendance_date < :days_next_month
                 AND attendance_date <= :days_month_today
                THEN attendance_date
            END) AS monthly_attendance_days,
            SUM(CASE
                WHEN attendance_date >= :year_start
                 AND attendance_date < :next_year_start
                THEN 1 ELSE 0
            END) AS current_year_check_ins,
            MAX(attendance_date) AS most_recent_check_in
         FROM attendance
         WHERE member_id = :member_id
           AND time_in IS NOT NULL
           AND attendance_date <= :filter_today'
    );
    $summaryStmt->execute([
        ':summary_month_start' => $monthStart,
        ':summary_next_month' => $nextMonthStart,
        ':summary_month_today' => $today,
        ':days_month_start' => $monthStart,
        ':days_next_month' => $nextMonthStart,
        ':days_month_today' => $today,
        ':year_start' => $yearStart,
        ':next_year_start' => $nextYearStart,
        ':member_id' => (int)$memberId,
        ':filter_today' => $today,
    ]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    memberAttendanceHistoryResponse([
        'success' => true,
        'month' => $month,
        'year' => $year,
        'records' => $records,
        'summary' => [
            'monthly_check_ins' => (int)($summary['monthly_check_ins'] ?? 0),
            'monthly_attendance_days' => (int)($summary['monthly_attendance_days'] ?? 0),
            'most_recent_check_in' => $summary['most_recent_check_in'] ?: null,
            'current_year_check_ins' => (int)($summary['current_year_check_ins'] ?? 0),
            'current_year' => $currentYear,
        ],
    ]);
} catch (PDOException $e) {
    error_log('Member attendance history query failed.');
    memberAttendanceHistoryResponse(['success' => false, 'message' => 'Unable to load attendance history right now. Please try again.'], 500);
}

?>
