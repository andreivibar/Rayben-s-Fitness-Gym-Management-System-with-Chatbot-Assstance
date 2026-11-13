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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed. Use POST."]); 
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = isset($input['email']) ? trim($input['email']) : '';
$newPwd = isset($input['password']) ? $input['password'] : '';

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Valid email is required."]); exit;
}

if (!$newPwd || strlen($newPwd) < 8) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Password must be at least 8 characters."]); exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$currentUser = requireRole($conn, ['admin']);

try {
    $conn->beginTransaction();
    $stmt = $conn->prepare('SELECT id, role FROM users WHERE email = :email LIMIT 1 FOR UPDATE');
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "User not found."]); exit;
    }

    $newHash = password_hash($newPwd, PASSWORD_BCRYPT);
    if ($newHash === false) {
        throw new RuntimeException('Unable to secure the new password.');
    }

    $update = $conn->prepare(
        "UPDATE users
         SET password = :hash,
             auth_token = CASE
                 WHEN LOWER(TRIM(role)) = 'trainer' THEN NULL
                 ELSE auth_token
             END
         WHERE id = :id"
    );
    $update->execute([':hash' => $newHash, ':id' => (int)$user['id']]);
    $conn->commit();

    echo json_encode(["success" => true, "message" => "Password updated successfully."]); exit;
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error. Please try again later."]); exit;
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error. Please try again later."]); exit;
}

?>
