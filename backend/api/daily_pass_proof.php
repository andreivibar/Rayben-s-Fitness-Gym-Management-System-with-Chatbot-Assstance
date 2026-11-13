<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/registration_storage.php';

try {
    requireRole($conn, ['admin', 'administrator', 'staff']);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $passId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$passId || $passId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid pass transaction is required.']);
        exit;
    }
    $stmt = $conn->prepare(
        'SELECT proof_stored_name, proof_mime_type FROM daily_pass_transactions WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $passId]);
    $proof = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proof || !preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/', (string)$proof['proof_stored_name'])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment proof not found.']);
        exit;
    }

    $configured = getenv('REGISTRATION_UPLOAD_DIR');
    $privateBase = $configured !== false && trim($configured) !== ''
        ? rtrim(trim($configured), DIRECTORY_SEPARATOR)
        : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'gym-management-private';
    $directory = realpath($privateBase . DIRECTORY_SEPARATOR . 'daily-passes');
    $path = $directory
        ? realpath($directory . DIRECTORY_SEPARATOR . $proof['proof_stored_name'])
        : false;
    if (!$directory || !$path || strpos($path, $directory . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment proof is unavailable.']);
        exit;
    }
    if (!in_array($proof['proof_mime_type'], ['image/jpeg', 'image/png', 'application/pdf'], true)) {
        http_response_code(415);
        echo json_encode(['success' => false, 'message' => 'Unsupported payment proof format.']);
        exit;
    }

    header('Content-Type: ' . $proof['proof_mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="daily-pass-proof-' . $passId . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
} catch (Throwable $e) {
    error_log('Daily pass proof retrieval failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unable to retrieve payment proof.']);
}
