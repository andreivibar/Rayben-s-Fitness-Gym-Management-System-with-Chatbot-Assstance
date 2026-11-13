<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    $currentUser = requireRole($conn, ['trainer', 'admin', 'administrator']);
    $rawInput = $GLOBALS['__php_input_cache'] ?? @file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];

    $action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? '')));
    $isUpdateRequest = ($_SERVER['REQUEST_METHOD'] === 'PUT') || ($action === 'update');

    $userRole = strtolower(trim((string)($currentUser['role'] ?? '')));
    $isAdmin = in_array($userRole, ['admin', 'administrator'], true);

    if ($isAdmin) {
        $targetEmail = isset($input['email']) ? trim((string)$input['email']) : '';
        if (!$targetEmail && isset($_SERVER['HTTP_X_USER_EMAIL'])) {
            $targetEmail = trim((string)$_SERVER['HTTP_X_USER_EMAIL']);
        }
        if (!$targetEmail) {
            sendResponse(['success' => false, 'message' => 'Email is required.'], 400);
        }
        if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            sendResponse(['success' => false, 'message' => 'Invalid email address.'], 400);
        }

        $userStmt = $conn->prepare(
            "SELECT id, full_name, email FROM users
             WHERE email = :email AND LOWER(TRIM(role)) = 'trainer'
             LIMIT 1"
        );
        $userStmt->bindParam(':email', $targetEmail, PDO::PARAM_STR);
        $userStmt->execute();
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            sendResponse(['success' => false, 'message' => 'Trainer account not found.'], 404);
        }
    } else {
        $user = $currentUser;
    }

    $stmt = $conn->prepare('SELECT t.id AS trainer_id, t.user_id, t.trainer_code, t.phone, t.status, t.profile_picture, t.created_at, u.first_name, u.middle_name, u.last_name, u.full_name, u.email FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = :user_id LIMIT 1');
    $stmt->bindParam(':user_id', $user['id'], PDO::PARAM_INT);
    $stmt->execute();
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trainer) {
        sendResponse(['success' => false, 'message' => 'Trainer profile not found for this account.'], 404);
    }

    // Handle Profile Update
    if ($isUpdateRequest) {
        // Enforce ownership: only the trainer themselves or an admin can update
        if (!$isAdmin && (int)$trainer['user_id'] !== (int)$currentUser['id']) {
            sendResponse(['success' => false, 'message' => 'Forbidden. You are not allowed to update this profile.'], 403);
        }

        $firstName = trim((string)($input['first_name'] ?? ''));
        $middleName = trim((string)($input['middle_name'] ?? ''));
        $lastName = trim((string)($input['last_name'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));

        // Validation
        if ($firstName === '') {
            sendResponse(['success' => false, 'message' => 'First name is required.'], 400);
        }
        if (mb_strlen($firstName) > 50) {
            sendResponse(['success' => false, 'message' => 'First name must not exceed 50 characters.'], 400);
        }

        if ($middleName !== '' && mb_strlen($middleName) > 50) {
            sendResponse(['success' => false, 'message' => 'Middle name must not exceed 50 characters.'], 400);
        }

        if ($lastName === '') {
            sendResponse(['success' => false, 'message' => 'Last name is required.'], 400);
        }
        if (mb_strlen($lastName) > 50) {
            sendResponse(['success' => false, 'message' => 'Last name must not exceed 50 characters.'], 400);
        }

        $fullName = $firstName . ($middleName !== '' ? ' ' . $middleName : '') . ' ' . $lastName;

        if ($phone === '') {
            sendResponse(['success' => false, 'message' => 'Contact number is required.'], 400);
        }
        if (!preg_match('/^\d{11}$/', $phone)) {
            sendResponse(['success' => false, 'message' => 'Contact number must contain exactly 11 digits.'], 400);
        }

        // Perform safe update
        $conn->beginTransaction();
        try {
            $middleParam = $middleName !== '' ? $middleName : null;

            $updateUserStmt = $conn->prepare('UPDATE users SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, full_name = :full_name WHERE id = :user_id');
            $updateUserStmt->execute([
                ':first_name' => $firstName,
                ':middle_name' => $middleParam,
                ':last_name' => $lastName,
                ':full_name' => $fullName,
                ':user_id' => $user['id']
            ]);

            $updateTrainerStmt = $conn->prepare('UPDATE trainers SET phone = :phone WHERE id = :trainer_id');
            $updateTrainerStmt->execute([
                ':phone' => $phone,
                ':trainer_id' => $trainer['trainer_id']
            ]);

            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Failed to update profile. Please try again.'], 500);
        }

        // Re-fetch updated trainer data
        $stmt->execute();
        $trainer = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $availabilityStmt = $conn->prepare(
        'SELECT id, day_of_week, TIME_FORMAT(start_time, "%H:%i:%s") AS start_time,
                TIME_FORMAT(end_time, "%H:%i:%s") AS end_time, schedule_type,
                TIME_FORMAT(lunch_start, "%H:%i:%s") AS lunch_start,
                TIME_FORMAT(lunch_end, "%H:%i:%s") AS lunch_end, status
         FROM trainer_availability
         WHERE trainer_id = :trainer_id AND LOWER(status) = "active"
         ORDER BY FIELD(day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"), start_time'
    );
    $availabilityStmt->execute([':trainer_id' => $trainer['trainer_id']]);
    $trainer['availability'] = $availabilityStmt->fetchAll(PDO::FETCH_ASSOC);

    $response = ['success' => true, 'trainer' => $trainer];
    if ($isUpdateRequest) {
        $response['message'] = 'Profile updated successfully.';
    }

    sendResponse($response);

} catch (PDOException $e) {
    sendResponse(['success' => false, 'message' => 'Server error. Please try again later.'], 500);
}

?>
