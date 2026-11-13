<?php

if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ready' => true]);
    exit;
}

require_once __DIR__ . '/payments_http_mock.php';
$scenario = preg_replace('/[^a-z_]/', '', strtolower((string)($_SERVER['HTTP_X_TEST_SCENARIO'] ?? '')));
$GLOBALS['payments_api_test_connection'] = new PaymentsApiMockPDO($scenario);
$role = in_array($scenario, ['staff_view', 'review_cash_success', 'review_gcash_success', 'discount_denied'], true)
    ? 'staff'
    : 'member';
$GLOBALS['payments_api_test_user'] = ['id' => 9001, 'role' => $role, 'email' => 'member@example.invalid'];
define('PAYMENTS_API_TESTING', true);
require __DIR__ . '/../api/payments.php';

?>
