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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../config/auth.php';

$user = requireAuth($conn, true);
if (strtolower(trim((string)$user['role'])) !== 'member') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This status is available only to the authenticated member.']);
    exit;
}

$approval = getInitialAccountApproval($conn, (int)$user['id']);
echo json_encode([
    'success' => true,
    'initial_approval' => $approval ?? [
        'status' => 'approved',
        'review_note' => null,
        'submitted_at' => null,
        'payment_status' => null,
    ],
]);
?>
