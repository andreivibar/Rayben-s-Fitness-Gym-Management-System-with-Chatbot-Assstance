<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/membership_workflow.php';
require_once __DIR__ . '/../config/registration_storage.php';
require_once __DIR__ . '/../config/membership_pricing.php';

function dailyPassResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function dailyPassUploadDirectory(): string {
    return dirname(registrationUploadDirectory()) . DIRECTORY_SEPARATOR . 'daily-passes';
}

function dailyPassExpiry(string $visitDate): string {
    $timezone = new DateTimeZone('Asia/Manila');
    return (new DateTimeImmutable($visitDate, $timezone))
        ->modify('+1 day')
        ->setTime(0, 0)
        ->format('Y-m-d H:i:s');
}

function nextMemberDailyPassDate(PDO $conn, int $memberId): string {
    $today = manilaToday();
    $latestStmt = $conn->prepare(
        "SELECT MAX(visit_date) FROM daily_pass_transactions
         WHERE member_id = :member_id AND payment_status <> 'rejected'"
    );
    $latestStmt->execute([':member_id' => $memberId]);
    $latestDate = trim((string)$latestStmt->fetchColumn());
    return calculateNextDailyPassVisitDate($latestDate !== '' ? $latestDate : null, $today);
}

function dailyPassHasUnapprovedInitialApplication(PDO $conn, int $passId): bool {
    $stmt = $conn->prepare(
        "SELECT 1
         FROM daily_pass_transactions dp
         JOIN registration_requests r ON r.member_id = dp.member_id
         WHERE dp.id = :pass_id
           AND dp.transaction_source = 'online'
           AND dp.id = (
               SELECT MIN(initial_pass.id)
               FROM daily_pass_transactions initial_pass
               WHERE initial_pass.member_id = dp.member_id
                 AND initial_pass.transaction_source = 'online'
           )
           AND r.membership_type = 'walk-in'
           AND r.registration_source = 'online'
           AND r.status IN ('pending', 'rejected')
         LIMIT 1"
    );
    $stmt->execute([':pass_id' => $passId]);
    return (bool)$stmt->fetchColumn();
}

function validateDailyPassProof(): array {
    $file = $_FILES['payment_proof'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        dailyPassResponse(['success' => false, 'message' => 'Upload your GCash payment proof.'], 400);
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 5 * 1024 * 1024) {
        dailyPassResponse(['success' => false, 'message' => 'Payment proof must be 5 MB or smaller.'], 400);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) {
        dailyPassResponse(['success' => false, 'message' => 'Use a JPG, PNG, or PDF payment proof.'], 400);
    }
    return [
        'tmp_name' => $file['tmp_name'],
        'original_name' => mb_substr(basename((string)$file['name']), 0, 255),
        'mime_type' => $mime,
        'file_size' => (int)$file['size'],
        'extension' => $extensions[$mime],
    ];
}

function dailyPassCode(): string {
    return strtoupper(bin2hex(random_bytes(16)));
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        dailyPassResponse(['success' => true]);
    }

    $input = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?? []);
    $action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? '')));

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'member_purchase_preview') {
            $memberUser = requireRole($conn, ['member']);
            $memberStmt = $conn->prepare('SELECT id FROM members WHERE user_id = :user_id LIMIT 1');
            $memberStmt->execute([':user_id' => (int)$memberUser['id']]);
            $memberId = (int)$memberStmt->fetchColumn();
            if ($memberId < 1) {
                dailyPassResponse(['success' => false, 'message' => 'Member profile not found for this account.'], 404);
            }
            dailyPassResponse([
                'success' => true,
                'scheduled_visit_date' => nextMemberDailyPassDate($conn, $memberId),
            ]);
        }
        requireRole($conn, ['admin', 'administrator', 'staff']);
        $stmt = $conn->query(
            "SELECT dp.id, dp.member_id, m.member_code, dp.customer_name, dp.customer_phone, dp.pass_code, dp.visit_date, dp.expires_at,
                    dp.payment_method, dp.amount, dp.payment_status, dp.reference_no, dp.transaction_source,
                    dp.proof_original_name, dp.recorded_by, dp.verified_by, dp.verified_at, dp.checked_in_at, dp.created_at,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM registration_requests r
                        WHERE r.member_id = dp.member_id
                          AND r.membership_type = 'walk-in'
                          AND r.registration_source = 'online'
                          AND r.status IN ('pending', 'rejected')
                          AND dp.id = (
                              SELECT MIN(initial_pass.id)
                              FROM daily_pass_transactions initial_pass
                              WHERE initial_pass.member_id = dp.member_id
                                AND initial_pass.transaction_source = 'online'
                          )
                    ) THEN 1 ELSE 0 END AS requires_initial_approval,
                    recorder.full_name AS recorded_by_name, verifier.full_name AS verified_by_name
             FROM daily_pass_transactions dp
             LEFT JOIN members m ON m.id = dp.member_id
             LEFT JOIN users recorder ON recorder.id = dp.recorded_by
             LEFT JOIN users verifier ON verifier.id = dp.verified_by
             ORDER BY dp.created_at DESC, dp.id DESC"
        );
        dailyPassResponse(['success' => true, 'daily_passes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        dailyPassResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    if ($action === 'status') {
        $requestKey = trim((string)($input['idempotency_key'] ?? ''));
        if (!preg_match('/^[a-f0-9-]{36}$/i', $requestKey)) {
            dailyPassResponse(['success' => false, 'message' => 'Pass tracking key is invalid.'], 400);
        }
        $stmt = $conn->prepare(
            'SELECT customer_name, visit_date, expires_at, payment_method, amount,
                    payment_status, reference_no, pass_code
             FROM daily_pass_transactions WHERE idempotency_key = :key LIMIT 1'
        );
        $stmt->execute([':key' => hash('sha256', strtolower($requestKey))]);
        $pass = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pass) dailyPassResponse(['success' => false, 'message' => 'Pass transaction not found.'], 404);
        $passCode = $pass['pass_code'];
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $isUsable = $pass['payment_status'] === 'paid'
            && $pass['visit_date'] === manilaToday()->format('Y-m-d')
            && new DateTimeImmutable($pass['expires_at'], new DateTimeZone('Asia/Manila')) > $now;
        unset($pass['pass_code']);
        if ($isUsable) {
            $pass['pass_code'] = $passCode;
        }
        dailyPassResponse(['success' => true, 'daily_pass' => $pass]);
    }

    if ($action === 'submit_gcash') {
        $settingsStmt = $conn->query(
            'SELECT gcash_account_name, gcash_account_number FROM gym_payment_settings WHERE id = 1'
        );
        $gcashSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
        if (empty($gcashSettings['gcash_account_name']) || empty($gcashSettings['gcash_account_number'])) {
            dailyPassResponse(['success' => false, 'message' => 'GCash payments are temporarily unavailable because account details are not configured.'], 503);
        }
        $name = trim((string)($input['customer_name'] ?? ''));
        $phone = trim((string)($input['customer_phone'] ?? ''));
        $requestKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
        if ($name === '' || mb_strlen($name) > 150
            || ($phone !== '' && !preg_match('/^\d{11}$/', $phone))
            || !preg_match('/^[a-f0-9-]{36}$/', $requestKey)) {
            dailyPassResponse(['success' => false, 'message' => 'Enter your name, a valid phone number, and retry key.'], 400);
        }

        $existing = $conn->prepare(
            'SELECT id, payment_status, visit_date FROM daily_pass_transactions WHERE idempotency_key = :key LIMIT 1'
        );
        $existing->execute([':key' => hash('sha256', $requestKey)]);
        $existingPass = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingPass) {
            dailyPassResponse([
                'success' => true,
                'message' => 'This pass request was already received.',
                'pass_id' => (int)$existingPass['id'],
                'payment_status' => $existingPass['payment_status'],
            ], 200);
        }

        $proof = validateDailyPassProof();
        $visitDate = manilaToday()->format('Y-m-d');
        $passCode = dailyPassCode();
        $uploadDirectory = dailyPassUploadDirectory();
        $storedPath = null;
        try {
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
                throw new RuntimeException('Unable to create private daily pass storage.');
            }
            $storedName = bin2hex(random_bytes(24)) . '.' . $proof['extension'];
            $storedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($proof['tmp_name'], $storedPath)) {
                throw new RuntimeException('Unable to store daily pass payment proof.');
            }
            $stmt = $conn->prepare(
                "INSERT INTO daily_pass_transactions
                    (customer_name, customer_phone, pass_code, idempotency_key, visit_date, expires_at,
                     payment_method, amount, payment_status, transaction_source,
                     proof_stored_name, proof_original_name, proof_mime_type, proof_file_size)
                 VALUES
                    (:name, :phone, :pass_code, :idempotency_key, :visit_date, :expires_at,
                     'gcash', :amount, 'pending', 'online',
                     :stored_name, :original_name, :mime_type, :file_size)"
            );
            $stmt->execute([
                ':name' => $name,
                ':phone' => $phone !== '' ? $phone : null,
                ':pass_code' => $passCode,
                ':idempotency_key' => hash('sha256', $requestKey),
                ':visit_date' => $visitDate,
                ':expires_at' => dailyPassExpiry($visitDate),
                ':amount' => number_format(GYM_DAILY_PASS_PRICE, 2, '.', ''),
                ':stored_name' => $storedName,
                ':original_name' => $proof['original_name'],
                ':mime_type' => $proof['mime_type'],
                ':file_size' => $proof['file_size'],
            ]);
            dailyPassResponse([
                'success' => true,
                'message' => 'Your GCash proof was submitted. The 1 Day Pass is usable only after admin verification and only on the visit date.',
                'pass_id' => (int)$conn->lastInsertId(),
                'payment_status' => 'pending',
            ], 201);
        } catch (Throwable $e) {
            if ($storedPath && is_file($storedPath)) unlink($storedPath);
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                dailyPassResponse(['success' => false, 'message' => 'This pass request was already processed. Check your pass status or contact staff.'], 409);
            }
            throw $e;
        }
    }

    if ($action === 'member_purchase') {
        $memberUser = requireRole($conn, ['member']);
        $method = strtolower(trim((string)($input['payment_method'] ?? '')));
        $requestKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
        if (!in_array($method, ['cash', 'gcash'], true)
            || !preg_match('/^[a-f0-9-]{36}$/', $requestKey)) {
            dailyPassResponse(['success' => false, 'message' => 'Choose Cash or GCash and provide the required transaction details.'], 400);
        }

        $memberStmt = $conn->prepare(
            'SELECT id, full_name, phone FROM members WHERE user_id = :user_id LIMIT 1'
        );
        $memberStmt->execute([':user_id' => (int)$memberUser['id']]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            dailyPassResponse(['success' => false, 'message' => 'Member profile not found for this account.'], 404);
        }
        $existing = $conn->prepare(
            'SELECT id, payment_status FROM daily_pass_transactions WHERE idempotency_key = :key LIMIT 1'
        );
        $existing->execute([':key' => hash('sha256', $requestKey)]);
        $existingPass = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingPass) {
            dailyPassResponse([
                'success' => true,
                'pass_id' => (int)$existingPass['id'],
                'payment_status' => $existingPass['payment_status'],
                'scheduled_visit_date' => $existingPass['visit_date'],
                'message' => 'This pass purchase was already received.',
            ]);
        }

        $proof = null;
        if ($method === 'gcash') {
            $settings = $conn->query(
                'SELECT gcash_account_name, gcash_account_number FROM gym_payment_settings WHERE id = 1'
            )->fetch(PDO::FETCH_ASSOC);
            if (empty($settings['gcash_account_name']) || empty($settings['gcash_account_number'])) {
                dailyPassResponse(['success' => false, 'message' => 'GCash payments are unavailable until the gym configures payment details.'], 503);
            }
            $proof = validateDailyPassProof();
        }

        $storedPath = null;
        try {
            $storedName = null;
            if ($proof) {
                $directory = dailyPassUploadDirectory();
                if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
                    throw new RuntimeException('Unable to create private daily pass storage.');
                }
                $storedName = bin2hex(random_bytes(24)) . '.' . $proof['extension'];
                $storedPath = $directory . DIRECTORY_SEPARATOR . $storedName;
                if (!move_uploaded_file($proof['tmp_name'], $storedPath)) {
                    throw new RuntimeException('Unable to store private GCash payment proof.');
                }
            }
            $conn->beginTransaction();
            $memberLock = $conn->prepare('SELECT id FROM members WHERE id = :id FOR UPDATE');
            $memberLock->execute([':id' => (int)$member['id']]);
            if (!$memberLock->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Member profile was not found while scheduling the pass.');
            }
            $existing = $conn->prepare(
                'SELECT id, payment_status, visit_date FROM daily_pass_transactions WHERE idempotency_key = :key LIMIT 1 FOR UPDATE'
            );
            $existing->execute([':key' => hash('sha256', $requestKey)]);
            $existingPass = $existing->fetch(PDO::FETCH_ASSOC);
            if ($existingPass) {
                $conn->rollBack();
                if ($storedPath && is_file($storedPath)) unlink($storedPath);
                dailyPassResponse([
                    'success' => true,
                    'pass_id' => (int)$existingPass['id'],
                    'payment_status' => $existingPass['payment_status'],
                    'scheduled_visit_date' => $existingPass['visit_date'],
                    'message' => 'This pass purchase was already received.',
                ]);
            }
            $visitDate = nextMemberDailyPassDate($conn, (int)$member['id']);
            $stmt = $conn->prepare(
                'INSERT INTO daily_pass_transactions
                    (member_id, customer_name, customer_phone, pass_code, idempotency_key, visit_date, expires_at,
                     payment_method, amount, payment_status, transaction_source,
                     proof_stored_name, proof_original_name, proof_mime_type, proof_file_size)
                 VALUES
                    (:member_id, :customer_name, :customer_phone, :pass_code, :idempotency_key, :visit_date, :expires_at,
                     :payment_method, :amount, :payment_status, "online",
                     :proof_stored_name, :proof_original_name, :proof_mime_type, :proof_file_size)'
            );
            $stmt->execute([
                ':member_id' => (int)$member['id'],
                ':customer_name' => $member['full_name'],
                ':customer_phone' => $member['phone'],
                ':pass_code' => dailyPassCode(),
                ':idempotency_key' => hash('sha256', $requestKey),
                ':visit_date' => $visitDate,
                ':expires_at' => dailyPassExpiry($visitDate),
                ':payment_method' => $method,
                ':amount' => number_format(GYM_DAILY_PASS_PRICE, 2, '.', ''),
                ':payment_status' => $method === 'gcash' ? 'pending' : 'unpaid',
                ':proof_stored_name' => $storedName,
                ':proof_original_name' => $proof['original_name'] ?? null,
                ':proof_mime_type' => $proof['mime_type'] ?? null,
                ':proof_file_size' => $proof['file_size'] ?? null,
            ]);
            $conn->commit();
            dailyPassResponse([
                'success' => true,
                'pass_id' => (int)$conn->lastInsertId(),
                'payment_status' => $method === 'gcash' ? 'pending' : 'unpaid',
                'scheduled_visit_date' => $visitDate,
                'message' => $method === 'gcash'
                    ? 'GCash proof submitted. The pass is unavailable until staff verifies payment.'
                    : 'Cash selected. The pass is unavailable until staff confirms the ₱100 payment.',
            ], 201);
        } catch (Throwable $e) {
            if ($storedPath && is_file($storedPath)) unlink($storedPath);
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                dailyPassResponse(['success' => false, 'message' => 'This pass purchase was already processed.'], 409);
            }
            throw $e;
        }
    }

    $authUser = requireRole($conn, ['admin', 'administrator', 'staff']);

    if ($action === 'confirm_cash') {
        $passId = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$passId || $passId < 1) {
            dailyPassResponse(['success' => false, 'message' => 'A valid pass transaction is required.'], 400);
        }
        $conn->beginTransaction();
        $lock = $conn->prepare(
            'SELECT id, payment_method, payment_status FROM daily_pass_transactions WHERE id = :id FOR UPDATE'
        );
        $lock->execute([':id' => $passId]);
        $pass = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$pass) {
            $conn->rollBack();
            dailyPassResponse(['success' => false, 'message' => '1 Day Pass transaction not found.'], 404);
        }
        if ($pass['payment_method'] !== 'cash' || $pass['payment_status'] !== 'unpaid') {
            $conn->rollBack();
            dailyPassResponse(['success' => false, 'message' => 'Only unpaid cash pass transactions can be confirmed.'], 409);
        }
        if (dailyPassHasUnapprovedInitialApplication($conn, (int)$passId)) {
            $conn->rollBack();
            dailyPassResponse(['success' => false, 'message' => 'Initial registration payment confirmation must happen together with account approval in Member Management → Applications.'], 409);
        }
        $confirm = $conn->prepare(
            "UPDATE daily_pass_transactions
             SET payment_status = 'paid', recorded_by = :recorded_by, verified_by = :verified_by, verified_at = NOW()
             WHERE id = :id AND payment_status = 'unpaid'"
        );
        $confirm->execute([
            ':recorded_by' => (int)$authUser['id'],
            ':verified_by' => (int)$authUser['id'],
            ':id' => $passId,
        ]);
        if ($confirm->rowCount() !== 1) {
            $conn->rollBack();
            dailyPassResponse(['success' => false, 'message' => 'This cash payment was already processed.'], 409);
        }
        $conn->commit();
        dailyPassResponse(['success' => true, 'message' => '₱100 cash payment confirmed.', 'payment_status' => 'paid']);
    }

    if ($action === 'record_cash') {
        $name = trim((string)($input['customer_name'] ?? ''));
        $phone = trim((string)($input['customer_phone'] ?? ''));
        $requestedMemberId = filter_var($input['member_id'] ?? 0, FILTER_VALIDATE_INT);
        $requestKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
        if ($name === '' || mb_strlen($name) > 150
            || ($phone !== '' && !preg_match('/^\d{11}$/', $phone))
            || !preg_match('/^[a-f0-9-]{36}$/', $requestKey)) {
            dailyPassResponse(['success' => false, 'message' => 'Enter the customer name, valid phone number, and retry key.'], 400);
        }
        $existing = $conn->prepare(
            'SELECT id, pass_code FROM daily_pass_transactions WHERE idempotency_key = :key LIMIT 1'
        );
        $existing->execute([':key' => hash('sha256', $requestKey)]);
        $existingPass = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingPass) {
            dailyPassResponse([
                'success' => true,
                'message' => 'This cash transaction was already recorded.',
                'pass_id' => (int)$existingPass['id'],
                'pass_code' => $existingPass['pass_code'],
            ]);
        }

        $member = null;
        if ($requestedMemberId) {
            $memberStmt = $conn->prepare('SELECT id, full_name, phone FROM members WHERE id = :id LIMIT 1');
            $memberStmt->execute([':id' => $requestedMemberId]);
            $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                dailyPassResponse(['success' => false, 'message' => 'Selected Member account was not found.'], 404);
            }
        } elseif ($phone !== '') {
            $memberStmt = $conn->prepare(
                'SELECT id, full_name, phone FROM members WHERE phone = :phone ORDER BY id LIMIT 2'
            );
            $memberStmt->execute([':phone' => $phone]);
            $matchingMembers = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($matchingMembers) > 1) {
                dailyPassResponse(['success' => false, 'message' => 'More than one Member account matches this phone number. Resolve the matching account before recording this pass.'], 409);
            }
            $member = $matchingMembers[0] ?? null;
        }
        if ($member) {
            $name = (string)$member['full_name'];
            $phone = (string)$member['phone'];
        }

        $conn->beginTransaction();
        if ($member) {
            $memberLock = $conn->prepare('SELECT id, full_name, phone FROM members WHERE id = :id FOR UPDATE');
            $memberLock->execute([':id' => (int)$member['id']]);
            $lockedMember = $memberLock->fetch(PDO::FETCH_ASSOC);
            if (!$lockedMember) {
                $conn->rollBack();
                dailyPassResponse(['success' => false, 'message' => 'Selected Member account was not found.'], 404);
            }
            $member = $lockedMember;
            $name = (string)$member['full_name'];
            $phone = (string)$member['phone'];
        }
        $existing = $conn->prepare(
            'SELECT id, pass_code FROM daily_pass_transactions WHERE idempotency_key = :key LIMIT 1 FOR UPDATE'
        );
        $existing->execute([':key' => hash('sha256', $requestKey)]);
        $existingPass = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingPass) {
            $conn->rollBack();
            dailyPassResponse([
                'success' => true,
                'message' => 'This cash transaction was already recorded.',
                'pass_id' => (int)$existingPass['id'],
                'pass_code' => $existingPass['pass_code'],
            ]);
        }
        $visitDate = $member
            ? nextMemberDailyPassDate($conn, (int)$member['id'])
            : manilaToday()->format('Y-m-d');
        $stmt = $conn->prepare(
            "INSERT INTO daily_pass_transactions
                (member_id, customer_name, customer_phone, pass_code, idempotency_key, visit_date, expires_at,
                 payment_method, amount, payment_status, transaction_source, recorded_by, verified_by, verified_at)
             VALUES
                (:member_id, :name, :phone, :pass_code, :idempotency_key, :visit_date, :expires_at,
                 'cash', :amount, 'paid', 'counter', :recorded_by, :verified_by, NOW())"
        );
        $passCode = dailyPassCode();
        $stmt->execute([
            ':member_id' => $member ? (int)$member['id'] : null,
            ':name' => $name,
            ':phone' => $phone !== '' ? $phone : null,
            ':pass_code' => $passCode,
            ':idempotency_key' => hash('sha256', $requestKey),
            ':visit_date' => $visitDate,
            ':expires_at' => dailyPassExpiry($visitDate),
            ':amount' => number_format(GYM_DAILY_PASS_PRICE, 2, '.', ''),
            ':recorded_by' => (int)$authUser['id'],
            ':verified_by' => (int)$authUser['id'],
        ]);
        $conn->commit();
        dailyPassResponse([
            'success' => true,
            'message' => '₱100 cash payment recorded and confirmed.',
            'pass_id' => (int)$conn->lastInsertId(),
            'pass_code' => $passCode,
            'scheduled_visit_date' => $visitDate,
        ], 201);
    }

    if (in_array($action, ['verify', 'reject'], true)) {
        $passId = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
        $note = trim((string)($input['note'] ?? ''));
        if (!$passId || $passId < 1 || ($action === 'reject' && ($note === '' || mb_strlen($note) > 500))) {
            dailyPassResponse(['success' => false, 'message' => 'A valid pass ID and, when rejecting, a reason are required.'], 400);
        }
        $conn->beginTransaction();
        $lock = $conn->prepare(
            'SELECT id, payment_method, payment_status FROM daily_pass_transactions WHERE id = :id FOR UPDATE'
        );
        $lock->execute([':id' => $passId]);
        $pass = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$pass) {
            $conn->rollBack();
            dailyPassResponse(['success' => false, 'message' => '1 Day Pass transaction not found.'], 404);
        }
        if ($pass['payment_method'] !== 'gcash' || $pass['payment_status'] !== 'pending') {
            $conn->rollBack();
            dailyPassResponse(['success' => false, 'message' => 'Only pending GCash pass payments can be reviewed.'], 409);
        }
        if (dailyPassHasUnapprovedInitialApplication($conn, (int)$passId)) {
            $conn->rollBack();
            dailyPassResponse(['success' => false, 'message' => 'Initial registration payment confirmation must happen together with account approval in Member Management → Applications.'], 409);
        }
        $newStatus = $action === 'verify' ? 'paid' : 'rejected';
        $update = $conn->prepare(
            'UPDATE daily_pass_transactions
             SET payment_status = :status, verified_by = :verified_by, verified_at = NOW()
             WHERE id = :id AND payment_status = \'pending\''
        );
        $update->execute([
            ':status' => $newStatus,
            ':verified_by' => (int)$authUser['id'],
            ':id' => $passId,
        ]);
        if ($update->rowCount() !== 1) {
            $conn->rollBack();
            dailyPassResponse(['success' => false, 'message' => 'This payment review was already processed.'], 409);
        }
        $conn->commit();
        dailyPassResponse([
            'success' => true,
            'message' => $action === 'verify' ? 'GCash payment verified.' : 'GCash payment rejected.',
            'payment_status' => $newStatus,
        ]);
    }

    dailyPassResponse(['success' => false, 'message' => 'Invalid daily pass action.'], 400);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) $conn->rollBack();
    error_log('Daily pass transaction failed: ' . $e->getMessage());
    dailyPassResponse(['success' => false, 'message' => 'Unable to process the 1 Day Pass transaction. Please try again.'], 500);
}
