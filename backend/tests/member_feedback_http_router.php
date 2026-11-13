<?php

if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ready' => true]);
    exit;
}

require_once __DIR__ . '/member_feedback_http_mock.php';

$stateId = preg_replace('/[^a-z0-9_-]/i', '', (string)($_SERVER['HTTP_X_TEST_STATE_ID'] ?? ''));
if ($stateId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Test state ID is required.']);
    exit;
}

$statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gym_member_feedback_' . $stateId . '.json';
$savedState = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : null;
$role = strtolower(trim((string)($_SERVER['HTTP_X_TEST_ROLE'] ?? 'member')));

if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/member-feedback') {
    $GLOBALS['member_feedback_test_connection'] = new MemberFeedbackMockPDO($savedState);
    $GLOBALS['member_feedback_test_user'] = ['id' => 901, 'email' => 'member@example.test', 'role' => $role];
    define('MEMBER_FEEDBACK_TESTING', true);
} elseif (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/admin-feedback') {
    $GLOBALS['feedback_management_test_connection'] = new MemberFeedbackMockPDO($savedState);
    $GLOBALS['feedback_management_test_user'] = ['id' => 900, 'role' => $role];
    define('FEEDBACK_MANAGEMENT_TESTING', true);
} elseif (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/trainer-feedback') {
    $GLOBALS['trainer_feedback_test_connection'] = new MemberFeedbackMockPDO($savedState);
    $GLOBALS['trainer_feedback_test_user'] = ['id' => 701, 'role' => $role];
    define('TRAINER_FEEDBACK_TESTING', true);
} elseif (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/gym-system-feedback') {
    $GLOBALS['gym_system_feedback_test_connection'] = new MemberFeedbackMockPDO($savedState);
    $GLOBALS['gym_system_feedback_test_user'] = ['id' => 901, 'email' => 'member@example.test', 'role' => $role];
    define('GYM_SYSTEM_FEEDBACK_TESTING', true);
} else {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Unknown mock endpoint.']);
    exit;
}

register_shutdown_function(function () use ($statePath): void {
    foreach (['member_feedback_test_connection', 'feedback_management_test_connection', 'trainer_feedback_test_connection', 'gym_system_feedback_test_connection'] as $key) {
        $connection = $GLOBALS[$key] ?? null;
        if ($connection instanceof MemberFeedbackMockPDO) {
            file_put_contents($statePath, json_encode($connection->state, JSON_UNESCAPED_SLASHES));
            break;
        }
    }
});

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/member-feedback') {
    require __DIR__ . '/../api/member_feedback.php';
} elseif ($path === '/admin-feedback') {
    require __DIR__ . '/../api/feedback_management.php';
} elseif ($path === '/gym-system-feedback') {
    require __DIR__ . '/../api/gym_system_feedback.php';
} else {
    require __DIR__ . '/../api/trainer_feedback.php';
}

?>
