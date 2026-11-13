<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json');

function registrationUpload($field, $required) {
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Please upload the required verification document."]);
            exit;
        }
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Unable to receive the uploaded document. Please try again."]);
        exit;
    }

    if (!is_uploaded_file($file['tmp_name']) || ($file['size'] ?? 0) <= 0 || $file['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Documents must be 5 MB or smaller."]);
        exit;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Use a JPG, PNG, or PDF document."]);
        exit;
    }

    return [
        'tmp_name' => $file['tmp_name'],
        'original_name' => mb_substr(basename((string)$file['name']), 0, 255),
        'mime_type' => $mime,
        'file_size' => (int)$file['size'],
        'extension' => $extensions[$mime],
    ];
}

function registrationDevelopmentDiagnosticsEnabled(): bool {
    return strtolower(trim((string)getenv('APP_ENV'))) === 'development'
        || in_array(strtolower(trim((string)getenv('APP_DEBUG'))), ['1', 'true', 'yes'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

require_once __DIR__ . '/../config/membership_workflow.php';
require_once __DIR__ . '/../config/membership_pricing.php';
require_once __DIR__ . '/../config/notification_workflow.php';
require_once __DIR__ . '/../config/member_gender.php';

$rawInput = file_get_contents('php://input');
$input = $_POST ?: json_decode($rawInput, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid request payload."]);
    exit;
}

$firstName = trim((string)($input['first_name'] ?? ''));
$middleName = trim((string)($input['middle_name'] ?? ''));
$lastName = trim((string)($input['last_name'] ?? ''));
$legacyFullName = trim((string)($input['full_name'] ?? ''));

// If first_name / last_name not supplied, attempt fallback from legacy full_name
if ($firstName === '' && $lastName === '' && $legacyFullName !== '') {
    $parts = preg_split('/\s+/', $legacyFullName);
    $firstName = $parts[0] ?? '';
    if (count($parts) > 1) {
        $lastName = implode(' ', array_slice($parts, 1));
    }
}

// Compute standard full_name
$computedFullName = trim($firstName . ($middleName !== '' ? ' ' . $middleName : '') . ($lastName !== '' ? ' ' . $lastName : ''));
if ($computedFullName === '' && $legacyFullName !== '') {
    $computedFullName = $legacyFullName;
}

$requestedUsername = trim((string)($input['username'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$phone = trim((string)($input['phone'] ?? ''));
$address = trim((string)($input['address'] ?? ''));
$dateOfBirth = trim((string)($input['date_of_birth'] ?? ''));
$genderInput = $input['gender'] ?? null;
try {
    $gender = normalizeOptionalMemberGender($genderInput);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Gender must be one of: Male, Female, Prefer not to say."]);
    exit;
}
$password = $input['password'] ?? '';
$membershipType = trim((string)($input['membership_type'] ?? ''));
$paymentMethod = strtolower(trim((string)($input['payment_method'] ?? 'gcash')));
$memberCategory = isset($input['member_category']) && $input['member_category'] !== null
    ? strtolower(trim((string)$input['member_category']))
    : null;
$membershipPlanId = null;

if ($requestedUsername === '') {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Username is required."]);
    exit;
}

$requestedUsername = preg_replace('/\s+/', ' ', $requestedUsername);
$requestedUsername = trim($requestedUsername);
if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $requestedUsername)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Username must be 3-30 characters and contain letters, numbers, or underscores only."]);
    exit;
}

// Validate required names
if ($firstName === '') {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "First name is required."]);
    exit;
}
if (mb_strlen($firstName) > 50) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "First name cannot exceed 50 characters."]);
    exit;
}

if ($middleName !== '' && mb_strlen($middleName) > 50) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Middle name cannot exceed 50 characters."]);
    exit;
}

if ($lastName === '') {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Last name is required."]);
    exit;
}
if (mb_strlen($lastName) > 50) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Last name cannot exceed 50 characters."]);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Please enter a valid email address."]);
    exit;
}

if (!$phone) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Phone number is required."]);
    exit;
}

if (!preg_match('/^\d{11}$/', $phone)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Phone number must contain exactly 11 digits."]);
    exit;
}

if (!$address) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Address is required."]);
    exit;
}

if (!$dateOfBirth) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Date of birth is required."]);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Date of birth must use YYYY-MM-DD format."]);
    exit;
}
[$birthYear, $birthMonth, $birthDay] = array_map('intval', explode('-', $dateOfBirth));
if (!checkdate($birthMonth, $birthDay, $birthYear)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Please enter a valid date of birth."]);
    exit;
}
if ($dateOfBirth > manilaToday()->format('Y-m-d')) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Date of birth cannot be in the future."]);
    exit;
}

if (!$password) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Password is required."]);
    exit;
}

if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Password must be at least 8 characters."]);
    exit;
}

if (!in_array($membershipType, ['monthly', 'daily_pass'], true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Choose Monthly Membership or a 1 Day Pass."]);
    exit;
}

if ($membershipType === 'daily_pass') {
    $memberCategory = 'regular';
}

if (!in_array($paymentMethod, ['cash', 'gcash'], true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Choose Cash or GCash as the payment method."]);
    exit;
}

if ($membershipType === 'monthly' && !in_array($memberCategory, ['regular', 'student', 'senior', 'pwd'], true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Please select a valid member category."]);
    exit;
}

$paymentProof = $paymentMethod === 'gcash' ? registrationUpload('payment_proof', true) : null;
$discountProof = $membershipType === 'monthly' && $memberCategory !== 'regular'
    ? registrationUpload('discount_proof', true)
    : null;
$paymentStatus = $paymentMethod === 'gcash' ? 'submitted' : 'pending';
$discountStatus = $discountProof ? 'submitted' : 'not_required';
$paymentAmount = null;
$membershipPlan = null;
$durationMonths = 1;
if ($membershipType === 'monthly') {
    $membershipPlanId = filter_var($input['membership_plan_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$membershipPlanId || $membershipPlanId < 1) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Choose a valid membership plan."]);
        exit;
    }
    try {
        $durationMonths = validateMonthlyDuration($input['duration_months'] ?? 1);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
        exit;
    }
}

// Securely hash the password before storing in the registration request.
$storePassword = password_hash($password, PASSWORD_DEFAULT);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/registration_storage.php';
require_once __DIR__ . '/../config/membership_pricing.php';
require_once __DIR__ . '/../config/duplicate_name_registration.php';
require_once __DIR__ . '/../config/registration_identity.php';

$storedFiles = [];
$registrationOperation = 'validating_registration';
try {
    $registrationIdentityEnabled = registrationIdentityReleaseEnabled($conn);
    $userActiveFilter = $registrationIdentityEnabled
        ? "AND registration_status <> 'rejected'"
        : '';
    $requestActiveFilter = $registrationIdentityEnabled
        ? "AND status <> 'rejected'"
        : '';
    if ($membershipType === 'daily_pass') {
        $registrationOperation = 'validating_daily_pass_registration';
        $requestKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
        if (!preg_match('/^[a-f0-9-]{36}$/', $requestKey)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Refresh the registration form and submit again."]);
            exit;
        }

        if ($paymentMethod === 'gcash') {
            $settingsStmt = $conn->query(
                'SELECT gcash_account_name, gcash_account_number FROM gym_payment_settings WHERE id = 1'
            );
            $gcashSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
            if (empty($gcashSettings['gcash_account_name']) || empty($gcashSettings['gcash_account_number'])) {
                http_response_code(503);
                echo json_encode(["success" => false, "message" => "GCash payments are unavailable until the gym configures payment details."]);
                exit;
            }
        }

        $existingRequest = $conn->prepare(
            'SELECT id FROM daily_pass_transactions WHERE idempotency_key = :key LIMIT 1'
        );
        $existingRequest->execute([':key' => hash('sha256', $requestKey)]);
        if ($existingRequest->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "This registration was already submitted. Log in using the account credentials you created."]);
            exit;
        }

        $checkUsername = $conn->prepare(
            "SELECT id FROM users
             WHERE LOWER(username) = LOWER(:username) {$userActiveFilter}
             LIMIT 1"
        );
        $checkUsername->execute([':username' => $requestedUsername]);
        if ($checkUsername->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "Username is already taken. Please choose another one."]);
            exit;
        }
        $checkPendingUsername = $conn->prepare(
            'SELECT id FROM registration_requests WHERE LOWER(username) = LOWER(:username) AND status = \'pending\' LIMIT 1'
        );
        $checkPendingUsername->execute([':username' => $requestedUsername]);
        if ($checkPendingUsername->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "Username is already pending approval. Please choose another one."]);
            exit;
        }
        if ($email !== '') {
            $checkEmail = $conn->prepare(
                "SELECT id FROM users
                 WHERE LOWER(email) = LOWER(:email) {$userActiveFilter}
                 LIMIT 1"
            );
            $checkEmail->execute([':email' => $email]);
            if ($checkEmail->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409);
                echo json_encode(["success" => false, "message" => "Email is already registered."]);
                exit;
            }
            $checkPendingEmail = $conn->prepare(
                'SELECT id FROM registration_requests WHERE LOWER(email) = LOWER(:email) AND status = \'pending\' LIMIT 1'
            );
            $checkPendingEmail->execute([':email' => $email]);
            if ($checkPendingEmail->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409);
                echo json_encode(["success" => false, "message" => "Email is already registered or pending approval."]);
                exit;
            }
        }

        acquireDuplicateNameRegistrationLock($conn, $firstName, $lastName, $requestedUsername, $email);
        $submissionKeyHash = hash('sha256', $requestKey);
        $priorException = $conn->prepare(
            'SELECT * FROM duplicate_name_registration_exceptions
             WHERE submission_key_hash = :submission_key_hash LIMIT 1'
        );
        $priorException->execute([':submission_key_hash' => $submissionKeyHash]);
        $existingException = $priorException->fetch(PDO::FETCH_ASSOC);
        $authorizedExceptionId = null;
        if ($existingException) {
            if ($existingException['status'] !== 'authorized') {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'code' => 'duplicate_name_review_required',
                    'message' => 'An account with this first name and last name already exists. Please check your information or contact the gym administrator.',
                    'case_id' => (int)$existingException['id'],
                    'case_status' => $existingException['status'],
                ]);
                exit;
            }
            if ($existingException['registration_request_id'] !== null) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'code' => 'duplicate_registration_already_submitted',
                    'message' => 'This authorized registration has already been submitted. Please log in or contact the gym administrator.',
                    'case_id' => (int)$existingException['id'],
                    'case_status' => 'authorized',
                ]);
                exit;
            }
            if (!duplicateNameExceptionMatchesSubmission($existingException, [
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'username' => $requestedUsername,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'date_of_birth' => $dateOfBirth,
                'membership_type' => 'daily_pass',
                'member_category' => 'regular',
                'gender' => $gender,
                'membership_plan_id' => null,
                'duration_months' => 1,
                'payment_method' => $paymentMethod,
                'payment_amount' => 100,
            ])) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'code' => 'duplicate_exception_submission_mismatch',
                    'message' => 'The submitted details differ from the registration reviewed by the administrator. Contact the gym administrator before changing this registration.',
                    'case_id' => (int)$existingException['id'],
                    'case_status' => 'authorized',
                ]);
                exit;
            }
            $authorizedExceptionId = (int)$existingException['id'];
        }

        $pendingAccountException = findPendingDuplicateNameRegistrationByAccount(
            $conn,
            $requestedUsername,
            $email
        );
        if (
            $pendingAccountException
            && (int)$pendingAccountException['id'] !== $authorizedExceptionId
        ) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'code' => 'duplicate_registration_pending',
                'message' => $pendingAccountException['status'] === 'authorized'
                    ? 'This registration has been authorized. Return to the original form and resubmit the same details to complete registration.'
                    : 'A registration using this username or email is already awaiting administrator review.',
                'case_id' => (int)$pendingAccountException['id'],
                'case_status' => $pendingAccountException['status'],
            ]);
            exit;
        }

        $accountConflict = findRegistrationAccountIdentifierConflict($conn, $requestedUsername, $email);
        if ($accountConflict !== null) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => $accountConflict === 'username'
                    ? 'Username is already registered or pending approval. Please choose another one.'
                    : 'Email is already registered or pending approval.',
            ]);
            exit;
        }

        $duplicateRecord = $authorizedExceptionId === null
            ? findDuplicateNameRegistrationRecord($conn, $firstName, $lastName)
            : null;
        $uploadDirectory = dirname(registrationUploadDirectory()) . DIRECTORY_SEPARATOR . 'daily-passes';
        $storedPath = null;
        $conn->beginTransaction();
        try {
            if ($duplicateRecord !== null) {
                $caseId = stageDuplicateNameRegistration(
                    $conn,
                    [
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'last_name' => $lastName,
                        'full_name' => $computedFullName,
                        'username' => $requestedUsername,
                        'email' => $email,
                        'phone' => $phone,
                        'address' => $address,
                        'date_of_birth' => $dateOfBirth,
                        'membership_type' => 'daily_pass',
                        'member_category' => 'regular',
                        'membership_plan_id' => null,
                        'duration_months' => 1,
                        'payment_method' => $paymentMethod,
                        'payment_amount' => 100,
                        'submission_key_hash' => $submissionKeyHash,
                        'daily_pass_idempotency_key' => hash('sha256', $requestKey),
                    ],
                    $paymentProof,
                    null,
                    $uploadDirectory,
                    registrationUploadDirectory(),
                    $storedFiles
                );
                notificationWorkflowCreateForAdmins(
                    $conn,
                    'member_registered',
                    'Duplicate-name registration needs review',
                    "Duplicate-name registration case #{$caseId} for {$computedFullName} is awaiting administrator identity review."
                );
                $conn->commit();
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'code' => 'duplicate_name_review_required',
                    'message' => 'An account with this first name and last name already exists. Please check your information or contact the gym administrator.',
                    'case_id' => $caseId,
                    'case_status' => 'pending',
                ]);
                exit;
            }

            $registrationOperation = 'creating_daily_pass_user';
            $insertUser = $conn->prepare(
                'INSERT INTO users (first_name, middle_name, last_name, full_name, username, email, password, role, created_at)
                 VALUES (:first_name, :middle_name, :last_name, :full_name, :username, :email, :password, "member", NOW())'
            );
            $insertUser->execute([
                ':first_name' => $firstName,
                ':middle_name' => $middleName !== '' ? $middleName : null,
                ':last_name' => $lastName,
                ':full_name' => $computedFullName,
                ':username' => $requestedUsername,
                ':email' => $email !== '' ? $email : null,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int)$conn->lastInsertId();
            if ($userId < 1) {
                throw new RuntimeException('Unable to create the Member login account.');
            }

            $registrationOperation = 'creating_daily_pass_member';
            $linkedUser = $conn->prepare(
                "SELECT id FROM users
                 WHERE id = :user_id AND LOWER(TRIM(role)) = 'member'
                 LIMIT 1 FOR UPDATE"
            );
            $linkedUser->execute([':user_id' => $userId]);
            if (!$linkedUser->fetchColumn()) {
                throw new RuntimeException('Unable to link the Member profile to its login account.');
            }
            $codeStmt = $conn->query('SELECT MAX(id) AS max_id FROM members');
            $nextId = (int)($codeStmt->fetchColumn() ?: 0) + 1;
            $memberCode = sprintf('MEM-%04d', $nextId);
            $insertMember = $conn->prepare(
                'INSERT INTO members
                    (user_id, member_code, first_name, middle_name, last_name, full_name, email, phone, address,
                     date_of_birth, gender, membership_type, member_category, membership_start, membership_end, status, created_at)
                 VALUES
                    (:user_id, :member_code, :first_name, :middle_name, :last_name, :full_name, :email, :phone, :address,
                      :date_of_birth, :gender, "daily_pass", :member_category, NULL, NULL, "active", NOW())'
            );
            $insertMember->execute([
                ':user_id' => $userId,
                ':member_code' => $memberCode,
                ':first_name' => $firstName,
                ':middle_name' => $middleName !== '' ? $middleName : null,
                ':last_name' => $lastName,
                ':full_name' => $computedFullName,
                ':email' => $email !== '' ? $email : null,
                ':phone' => $phone,
                ':address' => $address,
                ':date_of_birth' => $dateOfBirth,
                ':gender' => $gender,
                ':member_category' => $memberCategory,
            ]);
            $memberId = (int)$conn->lastInsertId();

            $visitDate = manilaToday()->format('Y-m-d');
            $timezone = new DateTimeZone('Asia/Manila');
            $expiresAt = (new DateTimeImmutable($visitDate, $timezone))
                ->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s');
            $passCode = strtoupper(bin2hex(random_bytes(16)));
            $paymentStatus = $paymentMethod === 'gcash' ? 'pending' : 'unpaid';
            $storedName = null;
            if ($paymentProof) {
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
                    throw new RuntimeException('Unable to create private daily pass storage.');
                }
                $storedName = bin2hex(random_bytes(24)) . '.' . $paymentProof['extension'];
                $storedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
                if (!move_uploaded_file($paymentProof['tmp_name'], $storedPath)) {
                    throw new RuntimeException('Unable to store the private GCash payment proof.');
                }
                $storedFiles[] = $storedPath;
            }
            $registrationOperation = 'creating_daily_pass_transaction';
            $insertPass = $conn->prepare(
                'INSERT INTO daily_pass_transactions
                    (member_id, customer_name, customer_phone, pass_code, idempotency_key, visit_date, expires_at,
                     payment_method, amount, payment_status, transaction_source,
                     proof_stored_name, proof_original_name, proof_mime_type, proof_file_size)
                 VALUES
                    (:member_id, :customer_name, :customer_phone, :pass_code, :idempotency_key, :visit_date, :expires_at,
                     :payment_method, :amount, :payment_status, "online",
                     :proof_stored_name, :proof_original_name, :proof_mime_type, :proof_file_size)'
            );
            $insertPass->execute([
                ':member_id' => $memberId,
                ':customer_name' => $computedFullName,
                ':customer_phone' => $phone,
                ':pass_code' => $passCode,
                ':idempotency_key' => hash('sha256', $requestKey),
                ':visit_date' => $visitDate,
                ':expires_at' => $expiresAt,
                ':payment_method' => $paymentMethod,
                ':amount' => number_format(GYM_DAILY_PASS_PRICE, 2, '.', ''),
                ':payment_status' => $paymentStatus,
                ':proof_stored_name' => $storedName,
                ':proof_original_name' => $paymentProof['original_name'] ?? null,
                ':proof_mime_type' => $paymentProof['mime_type'] ?? null,
                ':proof_file_size' => $paymentProof['file_size'] ?? null,
            ]);
            $passId = (int)$conn->lastInsertId();

            $registrationOperation = 'linking_daily_pass_approval_request';
            $insertApprovalRequest = $conn->prepare(
                'INSERT INTO registration_requests
                    (first_name, middle_name, last_name, full_name, username, email, phone, address,
                     date_of_birth, gender, password, membership_type, member_category, status, member_id,
                     payment_status, discount_status, registration_source, created_at)
                 VALUES
                    (:first_name, :middle_name, :last_name, :full_name, :username, :email, :phone, :address,
                     :date_of_birth, :gender, :password, "walk-in", :member_category, "pending", :member_id,
                     "not_required", "not_required", "online", NOW())'
            );
            $insertApprovalRequest->execute([
                ':first_name' => $firstName,
                ':middle_name' => $middleName !== '' ? $middleName : null,
                ':last_name' => $lastName,
                ':full_name' => $computedFullName,
                ':username' => $requestedUsername,
                ':email' => $email,
                ':phone' => $phone,
                ':address' => $address,
                ':date_of_birth' => $dateOfBirth,
                ':gender' => $gender,
                ':password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                ':member_category' => $memberCategory,
                ':member_id' => $memberId,
            ]);
            $approvalRequestId = (int)$conn->lastInsertId();

            if ($authorizedExceptionId !== null) {
                $linkException = $conn->prepare(
                    "UPDATE duplicate_name_registration_exceptions
                     SET registration_request_id = :request_id, member_id = :member_id,
                         daily_pass_transaction_id = :pass_id
                     WHERE id = :id AND status = 'authorized'
                       AND registration_request_id IS NULL"
                );
                $linkException->execute([
                    ':request_id' => $approvalRequestId,
                    ':member_id' => $memberId,
                    ':pass_id' => $passId,
                    ':id' => $authorizedExceptionId,
                ]);
                if ($linkException->rowCount() !== 1) {
                    throw new RuntimeException('Unable to link the authorized Daily Pass registration.');
                }
            }

            $registrationOperation = 'notifying_administrators';
            $paymentNote = $paymentMethod === 'gcash'
                ? 'GCash payment proof is awaiting verification.'
                : 'Cash payment is awaiting staff confirmation.';
            notificationWorkflowCreateForAdmins(
                $conn,
                'member_registered',
                'New member registration',
                "New Daily Pass account registration: {$computedFullName} is awaiting initial account approval. " .
                    "Submitted " . manilaToday()->format('F j, Y') .
                    ". Payment review remains separate: {$paymentNote} Pass #{$passId}, application #{$approvalRequestId}."
            );
            $conn->commit();
            $registrationOperation = 'completed';
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Your registration was submitted and is awaiting administrator approval. Account approval and Daily Pass payment verification are separate.',
                'membership_type' => 'daily_pass',
                'pass_id' => $passId,
                'payment_status' => $paymentStatus,
                'approval_status' => 'pending',
            ]);
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            foreach ($storedFiles as $storedFile) {
                if (is_file($storedFile)) unlink($storedFile);
            }
            throw $e;
        }
    }

    if ($membershipType === 'monthly') {
        $planStmt = $conn->prepare(
            'SELECT id, plan_code, plan_name, duration_months, regular_price, student_price, senior_price, pwd_price
             FROM membership_plans WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $planStmt->execute([':id' => $membershipPlanId]);
        $membershipPlan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$membershipPlan || !isVerifiedOneMonthMembershipPlan($membershipPlan)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Only the verified one-month membership plan is available."]);
            exit;
        }
        $paymentAmount = monthlyMembershipTotal(
            $memberCategory,
            $durationMonths
        );

        if ($paymentMethod === 'gcash') {
            $settingsStmt = $conn->query(
                'SELECT gcash_account_name, gcash_account_number FROM gym_payment_settings WHERE id = 1'
            );
            $gcashSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
            if (empty($gcashSettings['gcash_account_name']) || empty($gcashSettings['gcash_account_number'])) {
                http_response_code(503);
                echo json_encode([
                    "success" => false,
                    "message" => "Monthly GCash registration is temporarily unavailable because payment details are not configured."
                ]);
                exit;
            }
        }
    }

    $checkUsernameSql = "SELECT id FROM users
                         WHERE LOWER(username) = LOWER(:username)
                           {$userActiveFilter}
                         LIMIT 1";
    $stmt = $conn->prepare($checkUsernameSql);
    $stmt->bindParam(':username', $requestedUsername, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Username is already taken. Please choose another one."]);
        exit;
    }

    $checkRequestUsernameSql = "SELECT id FROM registration_requests
                                WHERE LOWER(username) = LOWER(:username)
                                  {$requestActiveFilter}
                                LIMIT 1";
    $stmt = $conn->prepare($checkRequestUsernameSql);
    $stmt->bindParam(':username', $requestedUsername, PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Username is already pending approval. Please choose another one."]);
        exit;
    }

    if ($email !== '') {
        $checkRegistrationSql = "SELECT id FROM registration_requests
                                 WHERE LOWER(email) = LOWER(:email)
                                   {$requestActiveFilter}
                                 LIMIT 1";
        $stmt = $conn->prepare($checkRegistrationSql);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "Email is already registered or pending approval."]);
            exit;
        }

        $checkUserSql = "SELECT id FROM users
                         WHERE LOWER(email) = LOWER(:email)
                           {$userActiveFilter}
                         LIMIT 1";
        $stmt = $conn->prepare($checkUserSql);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "Email is already registered."]);
            exit;
        }
    }

    $submissionKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
    if (!preg_match('/^[a-f0-9-]{36}$/i', $submissionKey)) {
        $submissionKey = bin2hex(random_bytes(16));
    }
    $submissionKeyHash = hash('sha256', $submissionKey);
    acquireDuplicateNameRegistrationLock($conn, $firstName, $lastName, $requestedUsername, $email);
    $priorException = $conn->prepare(
        'SELECT * FROM duplicate_name_registration_exceptions
         WHERE submission_key_hash = :submission_key_hash LIMIT 1'
    );
    $priorException->execute([':submission_key_hash' => $submissionKeyHash]);
    $existingException = $priorException->fetch(PDO::FETCH_ASSOC);
    $authorizedExceptionId = null;
    if ($existingException) {
        if ($existingException['status'] !== 'authorized') {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'code' => 'duplicate_name_review_required',
                'message' => 'An account with this first name and last name already exists. Please check your information or contact the gym administrator.',
                'case_id' => (int)$existingException['id'],
                'case_status' => $existingException['status'],
            ]);
            exit;
        }
        if ($existingException['registration_request_id'] !== null) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'code' => 'duplicate_registration_already_submitted',
                'message' => 'This authorized registration has already been submitted. Please log in or contact the gym administrator.',
                'case_id' => (int)$existingException['id'],
                'case_status' => 'authorized',
            ]);
            exit;
        }
        if (!duplicateNameExceptionMatchesSubmission($existingException, [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'username' => $requestedUsername,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'date_of_birth' => $dateOfBirth,
            'membership_type' => 'monthly',
            'member_category' => $memberCategory,
            'gender' => $gender,
            'membership_plan_id' => $membershipPlanId,
            'duration_months' => $durationMonths,
            'payment_method' => $paymentMethod,
            'payment_amount' => $paymentAmount,
        ])) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'code' => 'duplicate_exception_submission_mismatch',
                'message' => 'The submitted details differ from the registration reviewed by the administrator. Contact the gym administrator before changing this registration.',
                'case_id' => (int)$existingException['id'],
                'case_status' => 'authorized',
            ]);
            exit;
        }
        $authorizedExceptionId = (int)$existingException['id'];
    }

    $pendingAccountException = findPendingDuplicateNameRegistrationByAccount(
        $conn,
        $requestedUsername,
        $email
    );
    if (
        $pendingAccountException
        && (int)$pendingAccountException['id'] !== $authorizedExceptionId
    ) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'code' => 'duplicate_registration_pending',
            'message' => $pendingAccountException['status'] === 'authorized'
                ? 'This registration has been authorized. Return to the original form and resubmit the same details to complete registration.'
                : 'A registration using this username or email is already awaiting administrator review.',
            'case_id' => (int)$pendingAccountException['id'],
            'case_status' => $pendingAccountException['status'],
        ]);
        exit;
    }

    $accountConflict = findRegistrationAccountIdentifierConflict($conn, $requestedUsername, $email);
    if ($accountConflict !== null) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => $accountConflict === 'username'
                ? 'Username is already registered or pending approval. Please choose another one.'
                : 'Email is already registered or pending approval.',
        ]);
        exit;
    }

    $duplicateRecord = $authorizedExceptionId === null
        ? findDuplicateNameRegistrationRecord($conn, $firstName, $lastName)
        : null;
    $applicationToken = bin2hex(random_bytes(32));
    $conn->beginTransaction();
    if ($duplicateRecord !== null) {
        try {
            $caseId = stageDuplicateNameRegistration(
                $conn,
                [
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'last_name' => $lastName,
                    'full_name' => $computedFullName,
                    'username' => $requestedUsername,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address,
                    'date_of_birth' => $dateOfBirth,
                    'membership_type' => 'monthly',
                    'member_category' => $memberCategory,
                    'gender' => $gender,
                    'membership_plan_id' => $membershipPlanId,
                    'duration_months' => $durationMonths,
                    'payment_method' => $paymentMethod,
                    'payment_amount' => $paymentAmount,
                    'submission_key_hash' => $submissionKeyHash,
                    'daily_pass_idempotency_key' => null,
                ],
                $paymentProof,
                $discountProof,
                registrationUploadDirectory(),
                registrationUploadDirectory(),
                $storedFiles
            );
            notificationWorkflowCreateForAdmins(
                $conn,
                'member_registered',
                'Duplicate-name registration needs review',
                "Duplicate-name registration case #{$caseId} for {$computedFullName} is awaiting administrator identity review."
            );
            $conn->commit();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'code' => 'duplicate_name_review_required',
                'message' => 'An account with this first name and last name already exists. Please check your information or contact the gym administrator.',
                'case_id' => $caseId,
                'case_status' => 'pending',
            ]);
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    $paymentDate = $paymentMethod === 'gcash' ? manilaToday()->format('Y-m-d') : null;
    $registrationOperation = 'creating_monthly_member_login';
    $insertUser = $conn->prepare(
        'INSERT INTO users (first_name, middle_name, last_name, full_name, username, email, password, role, created_at)
         VALUES (:first_name, :middle_name, :last_name, :full_name, :username, :email, :password, "member", NOW())'
    );
    $insertUser->execute([
        ':first_name' => $firstName,
        ':middle_name' => $middleName !== '' ? $middleName : null,
        ':last_name' => $lastName,
        ':full_name' => $computedFullName,
        ':username' => $requestedUsername,
        ':email' => $email !== '' ? $email : null,
        ':password' => $storePassword,
    ]);

    $registrationOperation = 'creating_monthly_registration_request';
    // Store separated names along with auto-generated full_name in the registration request
    $insertSql = "INSERT INTO registration_requests
        (first_name, middle_name, last_name, full_name, username, email, phone, address, date_of_birth, gender, password,
         membership_type, member_category, membership_plan_id, payment_method, payment_date,
         application_token_hash, registration_source, status,
         payment_status, discount_status, payment_amount)
        VALUES
        (:first_name, :middle_name, :last_name, :full_name, :username, :email, :phone, :address, :date_of_birth, :gender, :password,
         :membership_type, :member_category, :membership_plan_id, :payment_method, :payment_date,
         :application_token_hash,
         'online', 'pending', :payment_status, :discount_status, :payment_amount)";
    $stmt = $conn->prepare($insertSql);
    $stmt->bindValue(':first_name', $firstName, PDO::PARAM_STR);
    $stmt->bindValue(':middle_name', $middleName !== '' ? $middleName : null, PDO::PARAM_STR);
    $stmt->bindValue(':last_name', $lastName, PDO::PARAM_STR);
    $stmt->bindValue(':full_name', $computedFullName, PDO::PARAM_STR);
    $stmt->bindValue(':username', $requestedUsername, PDO::PARAM_STR);
    // Older databases may still require this column; an empty string represents no email.
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
    $stmt->bindParam(':address', $address, PDO::PARAM_STR);
    $stmt->bindParam(':date_of_birth', $dateOfBirth, PDO::PARAM_STR);
    $stmt->bindValue(':gender', $gender, $gender === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':password', $storePassword, PDO::PARAM_STR);
    $stmt->bindParam(':membership_type', $membershipType, PDO::PARAM_STR);
    $stmt->bindValue(':member_category', $memberCategory, $memberCategory === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':membership_plan_id', $membershipPlanId, $membershipPlanId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':payment_method', $paymentMethod, PDO::PARAM_STR);
    $stmt->bindValue(':payment_date', $paymentDate, $paymentDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $applicationTokenHash = hash('sha256', $applicationToken);
    $stmt->bindValue(':application_token_hash', $applicationTokenHash, PDO::PARAM_STR);
    $stmt->bindValue(':payment_status', $paymentStatus, PDO::PARAM_STR);
    $stmt->bindValue(':discount_status', $discountStatus, PDO::PARAM_STR);
    $stmt->bindValue(':payment_amount', $paymentAmount, $paymentAmount === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->execute();

    $requestId = (int)$conn->lastInsertId();
    $uploadDirectory = registrationUploadDirectory();
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
        throw new RuntimeException('Unable to create private registration document storage.');
    }

    $documents = [
        'payment_proof' => $paymentProof,
        'discount_proof' => $discountProof,
    ];
    $fileStmt = $conn->prepare(
        'INSERT INTO registration_request_files (request_id, document_type, stored_name, original_name, mime_type, file_size)
         VALUES (:request_id, :document_type, :stored_name, :original_name, :mime_type, :file_size)'
    );
    foreach ($documents as $documentType => $document) {
        if (!$document) continue;
        $storedName = bin2hex(random_bytes(24)) . '.' . $document['extension'];
        $absolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($document['tmp_name'], $absolutePath)) {
            throw new RuntimeException('Unable to store a registration document.');
        }
        $storedFiles[] = $absolutePath;
        $fileStmt->execute([
            ':request_id' => $requestId,
            ':document_type' => $documentType,
            ':stored_name' => $storedName,
            ':original_name' => $document['original_name'],
            ':mime_type' => $document['mime_type'],
            ':file_size' => $document['file_size'],
        ]);
    }

    if ($authorizedExceptionId !== null) {
        $linkException = $conn->prepare(
            "UPDATE duplicate_name_registration_exceptions
             SET registration_request_id = :request_id
             WHERE id = :id AND status = 'authorized'
               AND registration_request_id IS NULL"
        );
        $linkException->execute([
            ':request_id' => $requestId,
            ':id' => $authorizedExceptionId,
        ]);
        if ($linkException->rowCount() !== 1) {
            throw new RuntimeException('Unable to link the authorized monthly registration.');
        }
    }

    $conn->commit();
    $registrationOperation = 'completed';
    $successMessage = $paymentMethod === 'gcash'
        ? ($discountProof
            ? 'Registration submitted. GCash payment and eligibility documents are awaiting administrator review.'
            : 'Registration submitted. Your GCash payment proof is awaiting administrator review.')
        : 'Registration submitted. An administrator must record and confirm cash receipt and approve your application before activation.';
    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => $successMessage,
        "request_id" => $requestId,
        "application_token" => $applicationToken,
    ]);
    exit;
} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    foreach ($storedFiles as $storedFile) {
        if (is_file($storedFile)) unlink($storedFile);
    }
    $sqlState = (string)$e->getCode();
    $driverErrorCode = $e->errorInfo[1] ?? null;
    error_log(
        'Registration database operation failed; operation=' . ($registrationOperation ?? 'unknown') .
        '; sqlstate=' . $sqlState .
        '; driver_code=' . ($driverErrorCode === null ? 'unknown' : (string)$driverErrorCode)
    );

    $driverErrorMessage = $e->errorInfo[2] ?? '';
    if ($driverErrorCode === 1062
        && (strpos($driverErrorMessage, 'uq_registration_requests_username') !== false
            || strpos($driverErrorMessage, 'uq_registration_requests_active_username') !== false)) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Username is already pending approval. Please choose another one."]);
        exit;
    }
    if ($driverErrorCode === 1062
        && (strpos($driverErrorMessage, 'uq_users_registration_username') !== false
            || strpos($driverErrorMessage, 'uq_users_registration_email') !== false)) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "This username or email is already registered. Please use another one."]);
        exit;
    }
    http_response_code(500);
    $response = ["success" => false, "message" => "Registration could not be completed. Please retry or contact gym administration."];
    if (registrationDevelopmentDiagnosticsEnabled()) {
        $response['diagnostic'] = [
            'operation' => $registrationOperation ?? 'unknown',
            'sqlstate' => (string)$e->getCode(),
            'driver_code' => $driverErrorCode,
        ];
    }
    echo json_encode($response);
    exit;
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    foreach ($storedFiles as $storedFile) {
        if (is_file($storedFile)) unlink($storedFile);
    }
    error_log(
        'Registration operation failed; operation=' . ($registrationOperation ?? 'unknown') .
        '; exception=' . get_class($e)
    );
    http_response_code(500);
    $response = ["success" => false, "message" => "Registration could not be completed. Please retry or contact gym administration."];
    if (registrationDevelopmentDiagnosticsEnabled()) {
        $response['diagnostic'] = [
            'operation' => $registrationOperation ?? 'unknown',
            'exception' => get_class($e),
        ];
    }
    echo json_encode($response);
    exit;
}
