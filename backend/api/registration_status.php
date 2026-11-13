<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json');

function registrationStatusResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    registrationStatusResponse(['success' => true]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    registrationStatusResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/registration_storage.php';

$input = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?? []);
$action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? 'check')));
$requestId = filter_var($input['request_id'] ?? 0, FILTER_VALIDATE_INT);
$token = trim((string)($input['application_token'] ?? ''));
if (!$requestId || $requestId < 1 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    registrationStatusResponse(['success' => false, 'message' => 'Application reference or tracking key is invalid.'], 404);
}

$storedFiles = [];
try {
    $conn->beginTransaction();
    $requestStmt = $conn->prepare(
        'SELECT id, status, payment_status, discount_status, review_note
         FROM registration_requests
         WHERE id = :id AND application_token_hash = :token_hash
         LIMIT 1 FOR UPDATE'
    );
    $requestStmt->execute([
        ':id' => $requestId,
        ':token_hash' => hash('sha256', $token),
    ]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        $conn->rollBack();
        registrationStatusResponse(['success' => false, 'message' => 'Application reference or tracking key is invalid.'], 404);
    }

    if ($action === 'replace_proof') {
        if ($request['status'] !== 'pending') {
            $conn->rollBack();
            registrationStatusResponse(['success' => false, 'message' => 'This application is no longer accepting document changes.'], 409);
        }
        $documentType = strtolower(trim((string)($input['document_type'] ?? '')));
        if (!in_array($documentType, ['payment_proof', 'discount_proof'], true)) {
            $conn->rollBack();
            registrationStatusResponse(['success' => false, 'message' => 'Choose a valid document type.'], 400);
        }
        $statusColumn = $documentType === 'payment_proof' ? 'payment_status' : 'discount_status';
        if ($request[$statusColumn] !== 'needs_correction') {
            $conn->rollBack();
            registrationStatusResponse(['success' => false, 'message' => 'A new document is not currently requested.'], 409);
        }

        $file = $_FILES['document'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $conn->rollBack();
            registrationStatusResponse(['success' => false, 'message' => 'Choose a document to upload.'], 400);
        }
        if (($file['size'] ?? 0) <= 0 || $file['size'] > 5 * 1024 * 1024) {
            $conn->rollBack();
            registrationStatusResponse(['success' => false, 'message' => 'Documents must be 5 MB or smaller.'], 400);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
        if (!isset($extensions[$mime])) {
            $conn->rollBack();
            registrationStatusResponse(['success' => false, 'message' => 'Use a JPG, PNG, or PDF document.'], 400);
        }

        $uploadDirectory = registrationUploadDirectory();
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
            throw new RuntimeException('Unable to create private registration document storage.');
        }
        $storedName = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        $absolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Unable to store a corrected registration document.');
        }
        $storedFiles[] = $absolutePath;
        $fileStmt = $conn->prepare(
            "UPDATE registration_request_files
             SET stored_name = :stored_name, original_name = :original_name, mime_type = :mime_type,
                 file_size = :file_size, review_status = 'pending', review_note = NULL,
                 reviewed_by = NULL, reviewed_at = NULL
             WHERE request_id = :request_id AND document_type = :document_type"
        );
        $fileStmt->execute([
            ':request_id' => $requestId,
            ':document_type' => $documentType,
            ':stored_name' => $storedName,
            ':original_name' => mb_substr(basename((string)$file['name']), 0, 255),
            ':mime_type' => $mime,
            ':file_size' => (int)$file['size'],
        ]);
        if ($fileStmt->rowCount() !== 1) {
            $conn->rollBack();
            unlink($absolutePath);
            registrationStatusResponse(['success' => false, 'message' => 'The original document record could not be found. Contact gym staff.'], 409);
        }
        $update = $conn->prepare(
            "UPDATE registration_requests SET {$statusColumn} = 'submitted'
             WHERE id = :id AND status = 'pending'"
        );
        $update->execute([':id' => $requestId]);
        $conn->commit();
        registrationStatusResponse(['success' => true, 'message' => 'Corrected document submitted for review.']);
    }

    if ($action !== 'check') {
        $conn->rollBack();
        registrationStatusResponse(['success' => false, 'message' => 'Invalid action.'], 400);
    }

    $filesStmt = $conn->prepare(
        'SELECT f.document_type, f.review_status, f.review_note, f.original_name
         FROM registration_request_files f
         JOIN (
             SELECT document_type, MAX(id) AS latest_id
             FROM registration_request_files WHERE request_id = :request_id
             GROUP BY document_type
         ) latest ON latest.latest_id = f.id'
    );
    $filesStmt->execute([':request_id' => $requestId]);
    $conn->commit();
    registrationStatusResponse([
        'success' => true,
        'status' => $request['status'],
        'payment_status' => $request['payment_status'],
        'discount_status' => $request['discount_status'],
        'review_note' => $request['review_note'],
        'documents' => $filesStmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    foreach ($storedFiles as $storedFile) {
        if (is_file($storedFile)) unlink($storedFile);
    }
    error_log('Registration status operation failed: ' . $e->getMessage());
    registrationStatusResponse(['success' => false, 'message' => 'Unable to load or update application status. Please retry.'], 500);
}
