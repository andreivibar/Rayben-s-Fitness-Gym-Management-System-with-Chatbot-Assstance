<?php

if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ready' => true]);
    exit;
}

require_once __DIR__ . '/../config/membership_workflow.php';
require_once __DIR__ . '/member_profile_http_mock.php';

$scenario = preg_replace('/[^a-z_]/', '', strtolower((string)($_SERVER['HTTP_X_TEST_SCENARIO'] ?? '')));
$stateId = preg_replace('/[^a-z0-9_-]/i', '', (string)($_SERVER['HTTP_X_TEST_STATE_ID'] ?? ''));
if ($stateId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Test state ID is required.']);
    exit;
}

$GLOBALS['member_profile_test_connection'] = new MemberProfileMockPDO($scenario);
$GLOBALS['member_profile_test_user'] = [
    'id' => 9001,
    'role' => 'member',
    'email' => $scenario === 'current_email' ? 'member-current@example.test' : '',
];
define('MEMBER_PROFILE_TESTING', true);

$statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gym_member_profile_' . $stateId . '.json';
register_shutdown_function(function () use ($statePath): void {
    $connection = $GLOBALS['member_profile_test_connection'] ?? null;
    if ($connection instanceof MemberProfileMockPDO) {
        file_put_contents($statePath, json_encode($connection->state, JSON_UNESCAPED_SLASHES));
    }
});

require __DIR__ . '/../api/member_profile.php';

?>
