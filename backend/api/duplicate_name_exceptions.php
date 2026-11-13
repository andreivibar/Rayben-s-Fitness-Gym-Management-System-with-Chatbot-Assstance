<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/duplicate_name_registration.php';

function duplicateNameExceptionResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        duplicateNameExceptionResponse(['success' => true]);
    }

    $input = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?? []);
    $action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? 'list')));
    $admin = requireRole($conn, ['admin']);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!in_array($action, ['list', 'history'], true)) {
            duplicateNameExceptionResponse(['success' => false, 'message' => 'Unsupported action.'], 400);
        }

        $statusFilter = $action === 'history' ? "e.status <> 'pending'" : "e.status = 'pending'";
        $stmt = $conn->query(
            "SELECT e.id, e.first_name, e.middle_name, e.last_name, e.full_name, e.username, e.email,
                    e.phone, e.address, e.date_of_birth, e.membership_type, e.member_category,
                    e.membership_plan_id, e.duration_months, e.payment_method, e.payment_amount,
                    e.payment_proof_original_name, e.discount_proof_original_name, e.status,
                    e.authorized_by, e.authorized_at, e.authorization_reason,
                    e.rejected_by, e.rejected_at, e.rejection_reason, e.registration_request_id,
                    e.created_at, authorizer.full_name AS authorized_by_name,
                    rejector.full_name AS rejected_by_name
             FROM duplicate_name_registration_exceptions e
             LEFT JOIN users authorizer ON authorizer.id = e.authorized_by
             LEFT JOIN users rejector ON rejector.id = e.rejected_by
             WHERE {$statusFilter}
             ORDER BY e.created_at DESC, e.id DESC
             LIMIT 200"
        );
        duplicateNameExceptionResponse([
            'success' => true,
            'duplicate_name_exceptions' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        duplicateNameExceptionResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }
    if (!in_array($action, ['authorize', 'reject'], true)) {
        duplicateNameExceptionResponse(['success' => false, 'message' => 'Unsupported action.'], 400);
    }
    if (($input['confirm'] ?? false) !== true) {
        duplicateNameExceptionResponse(['success' => false, 'message' => 'Confirm the duplicate-name review action before continuing.'], 400);
    }

    $caseId = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
    $reason = trim((string)($input['reason'] ?? ''));
    if (!$caseId || $caseId < 1) {
        duplicateNameExceptionResponse(['success' => false, 'message' => 'A valid duplicate-name case is required.'], 400);
    }
    if ($reason === '' || mb_strlen($reason) > 500) {
        duplicateNameExceptionResponse(['success' => false, 'message' => 'Enter a review reason of 1 to 500 characters.'], 400);
    }

    $identityStmt = $conn->prepare(
        'SELECT first_name, last_name, username, email
         FROM duplicate_name_registration_exceptions WHERE id = :id LIMIT 1'
    );
    $identityStmt->execute([':id' => $caseId]);
    $identity = $identityStmt->fetch(PDO::FETCH_ASSOC);
    if (!$identity) {
        duplicateNameExceptionResponse(['success' => false, 'message' => 'Duplicate-name case not found.'], 404);
    }

    acquireDuplicateNameRegistrationLock(
        $conn,
        (string)$identity['first_name'],
        (string)$identity['last_name'],
        (string)$identity['username'],
        (string)$identity['email']
    );
    $conn->beginTransaction();
    $lockCase = $conn->prepare(
        'SELECT id, status FROM duplicate_name_registration_exceptions WHERE id = :id FOR UPDATE'
    );
    $lockCase->execute([':id' => $caseId]);
    $case = $lockCase->fetch(PDO::FETCH_ASSOC);
    if (!$case) {
        $conn->rollBack();
        duplicateNameExceptionResponse(['success' => false, 'message' => 'Duplicate-name case not found.'], 404);
    }
    if ($case['status'] !== 'pending') {
        $conn->rollBack();
        duplicateNameExceptionResponse(['success' => false, 'message' => 'This duplicate-name case has already been reviewed.'], 409);
    }

    if ($action === 'authorize') {
        $update = $conn->prepare(
            "UPDATE duplicate_name_registration_exceptions
             SET status = 'authorized', authorized_by = :admin_id, authorized_at = NOW(),
                 authorization_reason = :reason
             WHERE id = :id AND status = 'pending'"
        );
        $message = 'Duplicate-name exception authorized. The applicant can now resubmit the same registration details; account creation and payment review remain separate.';
    } else {
        $update = $conn->prepare(
            "UPDATE duplicate_name_registration_exceptions
             SET status = 'rejected', rejected_by = :admin_id, rejected_at = NOW(),
                 rejection_reason = :reason
             WHERE id = :id AND status = 'pending'"
        );
        $message = 'Duplicate-name exception declined. No account or payment was created.';
    }

    $update->execute([
        ':admin_id' => (int)$admin['id'],
        ':reason' => $reason,
        ':id' => $caseId,
    ]);
    if ($update->rowCount() !== 1) {
        $conn->rollBack();
        duplicateNameExceptionResponse(['success' => false, 'message' => 'This duplicate-name case has already been reviewed.'], 409);
    }

    $conn->commit();
    duplicateNameExceptionResponse(['success' => true, 'message' => $message]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Duplicate-name review failed; exception=' . get_class($e));
    duplicateNameExceptionResponse([
        'success' => false,
        'message' => 'Unable to save the duplicate-name review. Please retry or contact support.',
    ], 500);
}
