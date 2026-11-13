<?php

if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ready' => true]);
    exit;
}

require_once __DIR__ . '/member_attendance_qr_http_mock.php';

$scenario = preg_replace('/[^a-z_]/', '', strtolower((string)($_SERVER['HTTP_X_TEST_SCENARIO'] ?? '')));
$stateId = preg_replace('/[^a-z0-9_-]/i', '', (string)($_SERVER['HTTP_X_TEST_STATE_ID'] ?? ''));
if ($stateId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Test state ID is required.']);
    exit;
}

$GLOBALS['member_attendance_qr_test_connection'] = new MemberAttendanceQrMockPDO($scenario);
$GLOBALS['member_attendance_qr_test_user'] = ['id' => 901, 'role' => 'member'];
define('MEMBER_ATTENDANCE_QR_TESTING', true);

$statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gym_attendance_qr_' . $stateId . '.json';
register_shutdown_function(function () use ($statePath): void {
    $connection = $GLOBALS['member_attendance_qr_test_connection'] ?? null;
    if ($connection instanceof MemberAttendanceQrMockPDO) {
        file_put_contents($statePath, json_encode($connection->state, JSON_UNESCAPED_SLASHES));
    }
});

require __DIR__ . '/../api/member_attendance_qr.php';

?>
