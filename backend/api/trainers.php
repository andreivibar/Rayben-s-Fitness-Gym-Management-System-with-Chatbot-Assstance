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

function validateInitialAvailability($availability) {
    if ($availability === null || $availability === '') return [];
    if (!is_array($availability)) {
        sendResponse(['success' => false, 'message' => 'Invalid weekly work schedule.'], 400);
    }

    $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $validated = [];
    foreach ($availability as $slot) {
        $day = trim((string)($slot['day_of_week'] ?? ''));
        $start = trim((string)($slot['start_time'] ?? ''));
        $end = trim((string)($slot['end_time'] ?? ''));
        if (!in_array($day, $validDays, true) || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            sendResponse(['success' => false, 'message' => 'Each schedule slot needs a valid day, start time, and end time.'], 400);
        }
        if ($end <= $start) {
            sendResponse(['success' => false, 'message' => 'Schedule end time must be later than start time.'], 400);
        }
        $validated[] = ['day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'status' => 'active'];
    }

    foreach ($validated as $index => $slot) {
        foreach ($validated as $otherIndex => $other) {
            if ($index >= $otherIndex || $slot['day_of_week'] !== $other['day_of_week']) continue;
            if ($slot['start_time'] < $other['end_time'] && $other['start_time'] < $slot['end_time']) {
                sendResponse(['success' => false, 'message' => 'Schedule slots on the same day cannot overlap.'], 400);
            }
        }
    }
    return $validated;
}

try {
    $authUser = requireAuth($conn);
    // Ensure phone column exists on trainers table (safe, idempotent)
    $colStmt = $conn->prepare("SHOW COLUMNS FROM trainers LIKE 'phone'");
    $colStmt->execute();
    $hasPhone = $colStmt->fetch(PDO::FETCH_ASSOC);
    if (!$hasPhone) {
        // Add phone column
        $conn->exec("ALTER TABLE trainers ADD COLUMN phone VARCHAR(50) DEFAULT NULL AFTER trainer_code");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = strtolower(trim((string)($_GET['action'] ?? 'list')));
        $userRole = strtolower(trim((string)($authUser['role'] ?? '')));

        if ($action === 'options' && $userRole === 'member') {
            $stmt = $conn->query("SELECT t.id AS trainer_id, t.trainer_code, u.first_name, u.middle_name, u.last_name, u.full_name, t.status
                                  FROM trainers t
                                  JOIN users u ON t.user_id = u.id
                                  WHERE t.status = 'active'
                                  ORDER BY u.full_name ASC");
            sendResponse(['success' => true, 'trainers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if (!in_array($userRole, ['admin', 'administrator'], true)) {
            sendResponse(['success' => false, 'message' => 'Forbidden. Trainer list access is restricted to administrators.'], 403);
        }

        // list trainers with search and pagination
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : '';
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $per_page = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : 25;
        $offset = ($page - 1) * $per_page;

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(t.trainer_code LIKE :search OR u.full_name LIKE :search OR u.first_name LIKE :search OR u.middle_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR t.phone LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }
        if ($status !== '' && $status !== 'all') {
            $where[] = 't.status = :status';
            $params[':status'] = $status;
        }
        $whereSql = '';
        if (!empty($where)) $whereSql = 'WHERE ' . implode(' AND ', $where);

        // counts for summary
        $countSql = "SELECT COUNT(*) FROM trainers t JOIN users u ON t.user_id = u.id {$whereSql}";
        $countStmt = $conn->prepare($countSql);
        foreach ($params as $k=>$v) $countStmt->bindValue($k, $v);
        $countStmt->execute();
        $total = intval($countStmt->fetchColumn());

        $sql = "SELECT t.id AS trainer_id, t.user_id, t.trainer_code, t.status, t.phone, t.profile_picture, t.created_at, u.first_name, u.middle_name, u.last_name, u.full_name, u.email
                FROM trainers t
                JOIN users u ON t.user_id = u.id
                {$whereSql}
                ORDER BY t.id DESC
                LIMIT :offset, :per_page";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // summary counts overall (no search)
        $summaryStmt = $conn->prepare("SELECT 
            COUNT(*) AS total_trainers,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive
            FROM trainers");
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

        sendResponse([ 'success' => true, 'trainers' => $rows, 'meta' => ['page'=>$page,'per_page'=>$per_page,'total'=>$total,'total_pages'=>$per_page>0?ceil($total/$per_page):0], 'summary' => $summary ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $userRole = strtolower(trim((string)($authUser['role'] ?? '')));
        if (!in_array($userRole, ['admin', 'administrator'], true)) {
            sendResponse(['success' => false, 'message' => 'Only administrators can manage trainers and their schedules.'], 403);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = isset($_GET['action']) ? $_GET['action'] : ($input['action'] ?? 'create');

        if ($action === 'create') {
            // Validate
            $first_name = trim($input['first_name'] ?? '');
            $middle_name = trim($input['middle_name'] ?? '');
            $last_name = trim($input['last_name'] ?? '');
            $username = trim((string)($input['username'] ?? ''));
            $email = trim($input['email'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $password = $input['password'] ?? '';
            $passwordConfirm = $input['confirm_password'] ?? '';
            $availability = validateInitialAvailability($input['availability'] ?? []);

            if ($first_name === '') sendResponse(['success'=>false,'message'=>'First name is required.'], 400);
            if (mb_strlen($first_name) > 50) sendResponse(['success'=>false,'message'=>'First name must not exceed 50 characters.'], 400);
            if ($middle_name !== '' && mb_strlen($middle_name) > 50) sendResponse(['success'=>false,'message'=>'Middle name must not exceed 50 characters.'], 400);
            if ($last_name === '') sendResponse(['success'=>false,'message'=>'Last name is required.'], 400);
            if (mb_strlen($last_name) > 50) sendResponse(['success'=>false,'message'=>'Last name must not exceed 50 characters.'], 400);
            if ($username === '') sendResponse(['success'=>false,'message'=>'Username is required.'], 400);
            if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) sendResponse(['success'=>false,'message'=>'Username must be 3-30 characters and can contain letters, numbers, or underscores only.'], 400);

            $full_name = $first_name . ($middle_name !== '' ? ' ' . $middle_name : '') . ' ' . $last_name;

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) sendResponse(['success'=>false,'message'=>'A valid email is required when provided.'], 400);
            if (!preg_match('/^\d{11}$/', $phone)) sendResponse(['success'=>false,'message'=>'Phone number must contain exactly 11 digits.'], 400);
            if ($password === '' || strlen($password) < 6) sendResponse(['success'=>false,'message'=>'Password must be at least 6 characters.' ], 400);
            if ($password !== $passwordConfirm) sendResponse(['success'=>false,'message'=>'Password confirmation does not match.'], 400);

            // check unique username/email
            $existsStmt = $conn->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
            $existsStmt->bindParam(':username', $username);
            $existsStmt->execute();
            if ($existsStmt->fetch()) sendResponse(['success'=>false,'message'=>'Username already in use.'], 409);

            if ($email !== '') {
                $emailStmt = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
                $emailStmt->bindParam(':email', $email);
                $emailStmt->execute();
                if ($emailStmt->fetch()) sendResponse(['success'=>false,'message'=>'Email already in use.'], 409);
            }

            // Transaction: create user then trainer
            try {
                $conn->beginTransaction();

                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($hash === false) {
                    throw new RuntimeException('Unable to hash trainer password.');
                }
                $uInsert = $conn->prepare('INSERT INTO users (first_name, middle_name, last_name, full_name, username, email, password, role, created_at) VALUES (:first_name, :middle_name, :last_name, :full_name, :username, :email, :password, :role, NOW())');
                $role = 'trainer';
                $middleParam = $middle_name !== '' ? $middle_name : null;
                $uInsert->bindParam(':first_name', $first_name);
                $uInsert->bindParam(':middle_name', $middleParam);
                $uInsert->bindParam(':last_name', $last_name);
                $uInsert->bindParam(':full_name', $full_name);
                $uInsert->bindParam(':username', $username);
                $uInsert->bindValue(':email', $email !== '' ? $email : null, $email !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $uInsert->bindParam(':password', $hash);
                $uInsert->bindParam(':role', $role);
                $uInsert->execute();
                $userId = intval($conn->lastInsertId());

                // compute next trainer code
                $row = $conn->query('SELECT MAX(id) AS max_id FROM trainers')->fetch(PDO::FETCH_ASSOC);
                $nextId = $row ? intval($row['max_id']) + 1 : 1;
                $trainerCode = sprintf('TRN-%04d', $nextId);

                $tInsert = $conn->prepare('INSERT INTO trainers (user_id, trainer_code, status, phone, created_at) VALUES (:user_id, :trainer_code, :status, :phone, NOW())');
                $status = 'active';
                $tInsert->bindParam(':user_id', $userId, PDO::PARAM_INT);
                $tInsert->bindParam(':trainer_code', $trainerCode);
                $tInsert->bindParam(':status', $status);
                $tInsert->bindParam(':phone', $phone);
                $tInsert->execute();
                $trainerId = (int)$conn->lastInsertId();

                if ($availability) {
                    $availabilityInsert = $conn->prepare('INSERT INTO trainer_availability (trainer_id, day_of_week, start_time, end_time, status, created_at) VALUES (:trainer_id, :day_of_week, :start_time, :end_time, :status, NOW())');
                    foreach ($availability as $slot) {
                        $availabilityInsert->execute([
                            ':trainer_id' => $trainerId,
                            ':day_of_week' => $slot['day_of_week'],
                            ':start_time' => $slot['start_time'],
                            ':end_time' => $slot['end_time'],
                            ':status' => $slot['status']
                        ]);
                    }
                }

                $conn->commit();
                sendResponse(['success'=>true,'message'=>'Trainer created successfully.','availability_slots'=>count($availability)]);
            } catch (PDOException $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log('Trainer account creation failed: ' . $e->getMessage());
                if (($e->errorInfo[1] ?? null) === 1062 && strpos((string)($e->errorInfo[2] ?? ''), 'username') !== false) {
                    sendResponse(['success'=>false,'message'=>'Username already in use.'], 409);
                }
                sendResponse(['success'=>false,'message'=>'Server error. Please try again later.'], 500);
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log('Trainer account creation failed: ' . $e->getMessage());
                sendResponse(['success'=>false,'message'=>'Server error. Please try again later.'], 500);
            }
        }

        if ($action === 'update') {
            $trainerId = intval($input['id'] ?? 0);
            if (!$trainerId) sendResponse(['success'=>false,'message'=>'Trainer ID is required.'], 400);

            $first_name = trim($input['first_name'] ?? '');
            $middle_name = trim($input['middle_name'] ?? '');
            $last_name = trim($input['last_name'] ?? '');
            $email = trim($input['email'] ?? '');
            $phone = trim($input['phone'] ?? '');

            if ($first_name === '') sendResponse(['success'=>false,'message'=>'First name is required.'], 400);
            if (mb_strlen($first_name) > 50) sendResponse(['success'=>false,'message'=>'First name must not exceed 50 characters.'], 400);
            if ($middle_name !== '' && mb_strlen($middle_name) > 50) sendResponse(['success'=>false,'message'=>'Middle name must not exceed 50 characters.'], 400);
            if ($last_name === '') sendResponse(['success'=>false,'message'=>'Last name is required.'], 400);
            if (mb_strlen($last_name) > 50) sendResponse(['success'=>false,'message'=>'Last name must not exceed 50 characters.'], 400);

            $full_name = $first_name . ($middle_name !== '' ? ' ' . $middle_name : '') . ' ' . $last_name;

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) sendResponse(['success'=>false,'message'=>'A valid email is required.'], 400);
            if (!preg_match('/^\d{11}$/', $phone)) sendResponse(['success'=>false,'message'=>'Phone number must contain exactly 11 digits.'], 400);

            // load existing trainer
            $tStmt = $conn->prepare('SELECT * FROM trainers WHERE id = :id LIMIT 1');
            $tStmt->bindParam(':id', $trainerId, PDO::PARAM_INT);
            $tStmt->execute();
            $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tRow) sendResponse(['success'=>false,'message'=>'Trainer not found.'], 404);

            $userId = intval($tRow['user_id']);

            // check email uniqueness among other users
            $existsStmt = $conn->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
            $existsStmt->bindParam(':email', $email);
            $existsStmt->bindParam(':id', $userId, PDO::PARAM_INT);
            $existsStmt->execute();
            if ($existsStmt->fetch()) sendResponse(['success'=>false,'message'=>'Email already in use by another account.'], 400);

            try {
                $conn->beginTransaction();
                $uUpdate = $conn->prepare('UPDATE users SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, full_name = :full_name, email = :email WHERE id = :id');
                $middleParam = $middle_name !== '' ? $middle_name : null;
                $uUpdate->bindParam(':first_name', $first_name);
                $uUpdate->bindParam(':middle_name', $middleParam);
                $uUpdate->bindParam(':last_name', $last_name);
                $uUpdate->bindParam(':full_name', $full_name);
                $uUpdate->bindParam(':email', $email);
                $uUpdate->bindParam(':id', $userId, PDO::PARAM_INT);
                $uUpdate->execute();

                $tUpdate = $conn->prepare('UPDATE trainers SET phone = :phone WHERE id = :id');
                $tUpdate->bindParam(':phone', $phone);
                $tUpdate->bindParam(':id', $trainerId, PDO::PARAM_INT);
                $tUpdate->execute();

                $conn->commit();
                sendResponse(['success'=>true,'message'=>'Trainer updated successfully.']);
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                sendResponse(['success'=>false,'message'=>'Server error. Please try again later.'], 500);
            }
        }

        if ($action === 'status') {
            $trainerId = intval($input['id'] ?? 0);
            $status = strtolower(trim((string)($input['status'] ?? '')));
            if (!$trainerId) sendResponse(['success'=>false,'message'=>'Trainer ID is required.'], 400);
            if (!in_array($status, ['active', 'inactive'], true)) {
                sendResponse(['success'=>false,'message'=>'Choose a valid trainer status.'], 400);
            }

            try {
                $conn->beginTransaction();
                $trainerStmt = $conn->prepare(
                    'SELECT user_id FROM trainers WHERE id = :id LIMIT 1 FOR UPDATE'
                );
                $trainerStmt->execute([':id' => $trainerId]);
                $userId = $trainerStmt->fetchColumn();
                if (!$userId) {
                    $conn->rollBack();
                    sendResponse(['success'=>false,'message'=>'Trainer not found.'], 404);
                }

                $statusStmt = $conn->prepare('UPDATE trainers SET status = :status WHERE id = :id');
                $statusStmt->execute([':status' => $status, ':id' => $trainerId]);

                $tokenStmt = $conn->prepare('UPDATE users SET auth_token = NULL WHERE id = :user_id');
                $tokenStmt->execute([':user_id' => (int)$userId]);

                $conn->commit();
                sendResponse(['success'=>true,'message'=>'Trainer status updated successfully.']);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
        }

        if ($action === 'reset-password') {
            $trainerId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            $password = $input['password'] ?? '';
            if (!$trainerId || $trainerId < 1) sendResponse(['success'=>false,'message'=>'A valid trainer ID is required.'], 400);
            if (!is_string($password) || strlen($password) < 8) sendResponse(['success'=>false,'message'=>'Password must be at least 8 characters.'], 400);
            if (strlen($password) > 72) sendResponse(['success'=>false,'message'=>'Password must not exceed 72 bytes.'], 400);

            try {
                $conn->beginTransaction();
                $trainerStmt = $conn->prepare(
                    "SELECT t.user_id FROM trainers t
                 JOIN users u ON u.id = t.user_id
                 WHERE t.id = :id AND LOWER(TRIM(u.role)) = 'trainer'
                 LIMIT 1 FOR UPDATE"
                );
                $trainerStmt->execute([':id' => $trainerId]);
                $userId = $trainerStmt->fetchColumn();
                if (!$userId) {
                    $conn->rollBack();
                    sendResponse(['success'=>false,'message'=>'Trainer account not found.'], 404);
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                if ($passwordHash === false) {
                    $conn->rollBack();
                    sendResponse(['success'=>false,'message'=>'Unable to reset the trainer password right now.'], 500);
                }

                $update = $conn->prepare(
                    "UPDATE users
                     SET password = :password, auth_token = NULL
                     WHERE id = :user_id AND LOWER(TRIM(role)) = 'trainer'"
                );
                $update->execute([':password' => $passwordHash, ':user_id' => (int)$userId]);
                if ($update->rowCount() !== 1) {
                    $conn->rollBack();
                    sendResponse(['success'=>false,'message'=>'Trainer account was not updated. Refresh the trainer list and try again.'], 409);
                }

                $conn->commit();
                sendResponse(['success'=>true,'message'=>'Trainer password reset successfully.']);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
        }

        sendResponse(['success'=>false,'message'=>'Invalid action.'], 400);
    }

    sendResponse(['success'=>false,'message'=>'Method not allowed.'], 405);
} catch (PDOException $e) {
    sendResponse(['success'=>false,'message'=>'Server error. Please try again later.'], 500);
} catch (Throwable $e) {
    sendResponse(['success'=>false,'message'=>'Server error. Please try again later.'], 500);
}

?>
