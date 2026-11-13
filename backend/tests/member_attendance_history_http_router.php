<?php

if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ready' => true]);
    exit;
}

require_once __DIR__ . '/member_attendance_history_http_mock.php';

$scenario = preg_replace('/[^a-z_]/', '', strtolower((string)($_SERVER['HTTP_X_TEST_SCENARIO'] ?? 'records')));
$GLOBALS['member_attendance_history_test_connection'] = new MemberAttendanceHistoryMockPDO($scenario);
$GLOBALS['member_attendance_history_test_user'] = [
    'id' => 9001,
    'email' => 'member@example.invalid',
    'role' => strtolower(trim((string)($_SERVER['HTTP_X_TEST_ROLE'] ?? 'member'))),
];
define('MEMBER_ATTENDANCE_HISTORY_TESTING', true);

require __DIR__ . '/../api/member_attendance_history.php';

?>
