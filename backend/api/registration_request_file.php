<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/registration_storage.php';

try {
    $currentUser = requireRole($conn, ['admin', 'administrator', 'staff']);

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $fileId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$fileId || $fileId < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid document ID is required.']);
        exit;
    }

    $stmt = $conn->prepare(
        'SELECT id, stored_name, mime_type, document_type FROM registration_request_files WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        exit;
    }
    if (strtolower(trim((string)($currentUser['role'] ?? ''))) === 'staff'
        && $file['document_type'] !== 'payment_proof') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Staff may only retrieve payment proof documents.']);
        exit;
    }

    if (!preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/', (string)$file['stored_name'])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        exit;
    }

    $directories = [
        registrationUploadDirectory(),
        __DIR__ . '/../uploads/registration',
    ];
    $path = false;
    foreach ($directories as $candidateDirectory) {
        $directory = realpath($candidateDirectory);
        $candidatePath = $directory
            ? realpath($directory . DIRECTORY_SEPARATOR . $file['stored_name'])
            : false;
        if ($directory && $candidatePath
            && strpos($candidatePath, $directory . DIRECTORY_SEPARATOR) === 0
            && is_file($candidatePath)) {
            $path = $candidatePath;
            break;
        }
    }
    if (!$path) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document file is unavailable.']);
        exit;
    }

    $allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!in_array($file['mime_type'], $allowedMimeTypes, true)) {
        http_response_code(415);
        echo json_encode(['success' => false, 'message' => 'Unsupported document format.']);
        exit;
    }

    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . filesize($path));
    $extension = strtolower(pathinfo($file['stored_name'], PATHINFO_EXTENSION));
    header('Content-Disposition: attachment; filename="registration-proof-' . (int)$file['id'] . '.' . $extension . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
} catch (Throwable $e) {
    error_log('Registration proof retrieval failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unable to retrieve the document.']);
}
