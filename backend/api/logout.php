<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST, OPTIONS');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $user = requireAuth($conn, true);
    $stmt = $conn->prepare('UPDATE users SET auth_token = NULL WHERE id = :id AND auth_token = :auth_token');
    $stmt->execute([
        ':id' => (int)$user['id'],
        ':auth_token' => (string)$user['auth_token']
    ]);

    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
} catch (PDOException $e) {
    error_log('Unable to invalidate the authenticated login token.');
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'The server could not invalidate your session.']);
}
?>
