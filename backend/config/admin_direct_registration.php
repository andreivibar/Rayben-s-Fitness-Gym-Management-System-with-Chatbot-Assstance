<?php

function validateAdminDirectUpload(string $field, bool $required): ?array
{
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new InvalidArgumentException('Upload the required payment or eligibility document.');
        }
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Unable to receive the uploaded document.');
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Documents must be 5 MB or smaller.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) {
        throw new InvalidArgumentException('Use a JPG, PNG, or PDF document.');
    }

    return [
        'tmp_name' => $file['tmp_name'],
        'original_name' => mb_substr(basename((string)$file['name']), 0, 255),
        'mime_type' => $mime,
        'file_size' => (int)$file['size'],
        'extension' => $extensions[$mime],
    ];
}

function createAdminDirectMember(PDO $conn, array $admin, array $input): array
{
    $firstName = trim((string)($input['first_name'] ?? ''));
    $middleName = trim((string)($input['middle_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $username = trim((string)($input['username'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $phone = trim((string)($input['phone'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
    $dateOfBirth = trim((string)($input['date_of_birth'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $membershipType = strtolower(trim((string)($input['membership_type'] ?? '')));
    $memberCategory = strtolower(trim((string)($input['member_category'] ?? 'regular')));
    $paymentMethod = strtolower(trim((string)($input['payment_method'] ?? '')));
    $requestKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
    $adminId = (int)($admin['id'] ?? 0);

    try {
        $gender = normalizeOptionalMemberGender($input['gender'] ?? null);
    } catch (InvalidArgumentException $error) {
        throw $error;
    }

    if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 50
        || mb_strlen($lastName) > 50 || ($middleName !== '' && mb_strlen($middleName) > 50)
        || !preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^\d{11}$/', $phone) || $address === '' || mb_strlen($address) > 255
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)
        || strlen($password) < 8 || $adminId < 1
        || !in_array($membershipType, ['monthly', 'daily_pass'], true)
        || !in_array($paymentMethod, ['cash', 'gcash'], true)
        || !preg_match('/^[a-f0-9-]{36}$/', $requestKey)) {
        throw new InvalidArgumentException('Check the required Member registration fields and try again.');
    }
    $memberMembershipType = $membershipType === 'daily_pass' ? 'walk-in' : $membershipType;
    [$birthYear, $birthMonth, $birthDay] = array_map('intval', explode('-', $dateOfBirth));
    if (!checkdate($birthMonth, $birthDay, $birthYear) || $dateOfBirth > manilaToday()->format('Y-m-d')) {
        throw new InvalidArgumentException('Enter a valid date of birth.');
    }

    $memberCategory = $membershipType === 'monthly' ? $memberCategory : 'regular';
    if ($membershipType === 'monthly' && !in_array($memberCategory, ['regular', 'student', 'senior', 'pwd'], true)) {
        throw new InvalidArgumentException('Choose a valid monthly member category.');
    }
    try {
        $durationMonths = $membershipType === 'monthly'
            ? validateMonthlyDuration($input['duration_months'] ?? 1)
            : 0;
    } catch (InvalidArgumentException $error) {
        throw $error;
    }

    $discountVerified = filter_var($input['verify_discount_eligibility'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($membershipType === 'monthly' && $memberCategory !== 'regular' && !$discountVerified) {
        throw new InvalidArgumentException('An Admin must explicitly verify the promo eligibility document.');
    }

    $discountProof = $membershipType === 'monthly' && $memberCategory !== 'regular'
        ? validateAdminDirectUpload('discount_proof', true)
        : null;
    $paymentProof = $paymentMethod === 'gcash'
        ? validateAdminDirectUpload('payment_proof', true)
        : null;

    if ($paymentMethod === 'gcash') {
        $settings = $conn->query('SELECT gcash_account_name, gcash_account_number FROM gym_payment_settings WHERE id = 1')
            ->fetch(PDO::FETCH_ASSOC);
        if (empty($settings['gcash_account_name']) || empty($settings['gcash_account_number'])) {
            throw new RuntimeException('Configure the gym GCash account before submitting this registration.');
        }
    }

    $idempotencyHash = hash('sha256', $requestKey);
    $existing = $membershipType === 'monthly'
        ? $conn->prepare(
            "SELECT p.id AS payment_id, p.member_id, p.payment_method, p.duration_months,
                    p.amount, p.status AS payment_status, m.membership_type, m.member_category,
                    u.username, u.email, m.full_name
             FROM payments p
             JOIN members m ON m.id = p.member_id
             JOIN users u ON u.id = m.user_id
             WHERE p.idempotency_key = :key AND p.payment_type = 'monthly_subscription'
               AND p.recorded_by = :admin_id LIMIT 1"
        )
        : $conn->prepare(
            'SELECT dp.id AS pass_id, dp.member_id, dp.payment_method, dp.payment_status,
                    m.membership_type, m.member_category, u.username, u.email, m.full_name
             FROM daily_pass_transactions dp
             JOIN members m ON m.id = dp.member_id
             JOIN users u ON u.id = m.user_id
             WHERE dp.idempotency_key = :key AND dp.transaction_source = "counter"
               AND dp.recorded_by = :admin_id LIMIT 1'
        );
    $existing->execute([':key' => $idempotencyHash, ':admin_id' => $adminId]);
    $existingRecord = $existing->fetch(PDO::FETCH_ASSOC);
    if ($existingRecord) {
        $sameRequest = strcasecmp((string)$existingRecord['username'], $username) === 0
            && strcasecmp((string)$existingRecord['email'], $email) === 0
            && $existingRecord['payment_method'] === $paymentMethod
            && $existingRecord['membership_type'] === $memberMembershipType
            && $existingRecord['member_category'] === $memberCategory
            && ($membershipType !== 'monthly'
                || (int)$existingRecord['duration_months'] === $durationMonths);
        if (!$sameRequest) {
            throw new DomainException('This retry key belongs to a different registration.');
        }
        return [
            'success' => true,
            'replayed' => true,
            'member_id' => (int)$existingRecord['member_id'],
            'member_name' => (string)$existingRecord['full_name'],
            'membership_type' => $membershipType,
            'member_category' => $memberCategory,
            'payment_method' => $paymentMethod,
            'amount' => number_format($membershipType === 'monthly'
                ? (float)$existingRecord['amount']
                : GYM_DAILY_PASS_PRICE, 2, '.', ''),
            'payment_status' => (string)$existingRecord['payment_status'],
            'payment_id' => isset($existingRecord['payment_id']) ? (int)$existingRecord['payment_id'] : null,
            'daily_pass_id' => isset($existingRecord['pass_id']) ? (int)$existingRecord['pass_id'] : null,
        ];
    }

    $identityConflict = findRegistrationAccountIdentifierConflict($conn, $username, $email);
    if ($identityConflict !== null) {
        throw new DomainException($identityConflict === 'username'
            ? 'Username is already registered or awaiting approval.'
            : 'Email is already registered or awaiting approval.');
    }

    acquireDuplicateNameRegistrationLock($conn, $firstName, $lastName, $username, $email);
    if (findDuplicateNameRegistrationRecord($conn, $firstName, $lastName)) {
        throw new DomainException('A Member or pending registration with this name already exists. Review the duplicate-name case before continuing.');
    }

    $membershipPlan = null;
    $amount = GYM_DAILY_PASS_PRICE;
    if ($membershipType === 'monthly') {
        $planId = filter_var($input['membership_plan_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$planId || $planId < 1) {
            throw new InvalidArgumentException('Choose a valid membership plan.');
        }
        $planStmt = $conn->prepare(
            'SELECT id, plan_code, duration_months, regular_price, student_price, senior_price, pwd_price
             FROM membership_plans WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $planStmt->execute([':id' => $planId]);
        $membershipPlan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$membershipPlan || !isVerifiedOneMonthMembershipPlan($membershipPlan)) {
            throw new InvalidArgumentException('The selected membership plan is unavailable.');
        }
        $amount = monthlyMembershipTotal($memberCategory, $durationMonths);
    }

    $fullName = trim($firstName . ($middleName !== '' ? ' ' . $middleName : '') . ' ' . $lastName);
    $uploadDirectory = registrationUploadDirectory();
    $paymentDirectory = paymentProofDirectory();
    $storedFiles = [];
    $memberCodeLock = false;
    try {
        if (($discountProof || $paymentProof)
            && !is_dir($uploadDirectory)
            && !mkdir($uploadDirectory, 0750, true)
            && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Unable to create private registration document storage.');
        }
        if ($paymentProof && !is_dir($paymentDirectory)
            && !mkdir($paymentDirectory, 0750, true)
            && !is_dir($paymentDirectory)) {
            throw new RuntimeException('Unable to create private payment proof storage.');
        }

        $conn->beginTransaction();
        $accountConflict = findRegistrationAccountIdentifierConflict($conn, $username, $email);
        if ($accountConflict !== null) {
            throw new DomainException($accountConflict === 'username'
                ? 'Username is already registered or awaiting approval.'
                : 'Email is already registered or awaiting approval.');
        }

        $lockStmt = $conn->query("SELECT GET_LOCK('admin_member_code_sequence', 10)");
        $memberCodeLock = (int)$lockStmt->fetchColumn() === 1;
        if (!$memberCodeLock) {
            throw new RuntimeException('Unable to allocate a Member code safely. Please retry.');
        }

        $userInsert = $conn->prepare(
            'INSERT INTO users
                (first_name, middle_name, last_name, full_name, username, email, password, role,
                 registration_status, created_by_admin_id, created_at)
             VALUES
                (:first_name, :middle_name, :last_name, :full_name, :username, :email, :password, "member",
                 "active", :admin_id, NOW())'
        );
        $userInsert->execute([
            ':first_name' => $firstName,
            ':middle_name' => $middleName !== '' ? $middleName : null,
            ':last_name' => $lastName,
            ':full_name' => $fullName,
            ':username' => $username,
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':admin_id' => $adminId,
        ]);
        $userId = (int)$conn->lastInsertId();
        if ($userId < 1) {
            throw new RuntimeException('Unable to create the Member login account.');
        }
        $linkedUser = $conn->prepare(
            "SELECT id FROM users
             WHERE id = :user_id AND LOWER(TRIM(role)) = 'member'
             LIMIT 1 FOR UPDATE"
        );
        $linkedUser->execute([':user_id' => $userId]);
        if (!$linkedUser->fetchColumn()) {
            throw new RuntimeException('Unable to link the Member profile to its login account.');
        }

        $memberCodeStmt = $conn->query('SELECT COALESCE(MAX(id), 0) + 1 FROM members FOR UPDATE');
        $memberCode = sprintf('MEM-%04d', (int)$memberCodeStmt->fetchColumn());
        $memberStatus = 'active';
        $memberInsert = $conn->prepare(
            'INSERT INTO members
                (user_id, member_code, first_name, middle_name, last_name, full_name, email, phone,
                 address, date_of_birth, gender, membership_type, member_category,
                 membership_start, membership_end, status, created_at)
             VALUES
                (:user_id, :member_code, :first_name, :middle_name, :last_name, :full_name, :email, :phone,
                 :address, :date_of_birth, :gender, :membership_type, :member_category,
                 NULL, NULL, :status, NOW())'
        );
        $memberInsert->execute([
            ':user_id' => $userId,
            ':member_code' => $memberCode,
            ':first_name' => $firstName,
            ':middle_name' => $middleName !== '' ? $middleName : null,
            ':last_name' => $lastName,
            ':full_name' => $fullName,
            ':email' => $email,
            ':phone' => $phone,
            ':address' => $address,
            ':date_of_birth' => $dateOfBirth,
            ':gender' => $gender,
            ':membership_type' => $memberMembershipType,
            ':member_category' => $memberCategory,
            ':status' => $memberStatus,
        ]);
        $memberId = (int)$conn->lastInsertId();

        $paymentStatus = 'pending';
        $paymentId = null;
        $passId = null;
        $registrationRequestId = null;
        if ($membershipType === 'monthly') {
            if ($discountProof) {
                $auditStmt = $conn->prepare(
                    "INSERT INTO registration_requests
                        (first_name, middle_name, last_name, full_name, username, email, phone, address,
                         date_of_birth, gender, password, membership_type, member_category, membership_plan_id,
                         registration_source, status, member_id, payment_status, discount_status, reviewed_by,
                         reviewed_at, review_note, created_at)
                     VALUES
                        (:first_name, :middle_name, :last_name, :full_name, :username, :email, :phone, :address,
                         :date_of_birth, :gender, :password, 'monthly', :category, :plan_id,
                         'walk_in', 'approved', :member_id, 'not_required', 'verified', :reviewed_by,
                         NOW(), 'Direct Admin registration; promo eligibility document reviewed and verified.',
                         NOW())"
                );
                $auditStmt->execute([
                    ':first_name' => $firstName,
                    ':middle_name' => $middleName !== '' ? $middleName : null,
                    ':last_name' => $lastName,
                    ':full_name' => $fullName,
                    ':username' => $username,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':address' => $address,
                    ':date_of_birth' => $dateOfBirth,
                    ':gender' => $gender,
                    ':password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    ':category' => $memberCategory,
                    ':plan_id' => (int)$membershipPlan['id'],
                    ':member_id' => $memberId,
                    ':reviewed_by' => $adminId,
                ]);
                $registrationRequestId = (int)$conn->lastInsertId();
                $discountName = bin2hex(random_bytes(24)) . '.' . $discountProof['extension'];
                $discountPath = $uploadDirectory . DIRECTORY_SEPARATOR . $discountName;
                if (!move_uploaded_file($discountProof['tmp_name'], $discountPath)) {
                    throw new RuntimeException('Unable to store the private promo eligibility document.');
                }
                $storedFiles[] = $discountPath;
                $fileStmt = $conn->prepare(
                    "INSERT INTO registration_request_files
                        (request_id, document_type, stored_name, original_name, mime_type, file_size,
                         review_status, reviewed_by, reviewed_at)
                     VALUES (:request_id, 'discount_proof', :stored_name, :original_name, :mime_type,
                             :file_size, 'verified', :reviewed_by, NOW())"
                );
                $fileStmt->execute([
                    ':request_id' => $registrationRequestId,
                    ':stored_name' => $discountName,
                    ':original_name' => $discountProof['original_name'],
                    ':mime_type' => $discountProof['mime_type'],
                    ':file_size' => $discountProof['file_size'],
                    ':reviewed_by' => $adminId,
                ]);
            }

            $proofStoredName = null;
            if ($paymentProof) {
                $proofStoredName = bin2hex(random_bytes(24)) . '.' . $paymentProof['extension'];
                $proofPath = $paymentDirectory . DIRECTORY_SEPARATOR . $proofStoredName;
                if (!move_uploaded_file($paymentProof['tmp_name'], $proofPath)) {
                    throw new RuntimeException('Unable to store the private GCash payment proof.');
                }
                $storedFiles[] = $proofPath;
            }
            $paymentInsert = $conn->prepare(
                "INSERT INTO payments
                    (member_id, recorded_by, membership_plan_id, duration_months, amount, payment_type,
                     status, payment_date, payment_method, payment_channel, idempotency_key,
                     proof_stored_name, proof_original_name, proof_mime_type, proof_file_size,
                     verification_status, verified_by, verified_at)
                 VALUES
                    (:member_id, :recorded_by, :plan_id, :duration_months, :amount, 'monthly_subscription',
                     'pending', :payment_date, :payment_method, :payment_channel, :idempotency_key,
                     :proof_name, :proof_original_name, :proof_mime_type, :proof_file_size,
                     'pending', NULL, NULL)"
            );
            $paymentInsert->execute([
                ':member_id' => $memberId,
                ':recorded_by' => $adminId,
                ':plan_id' => (int)$membershipPlan['id'],
                ':duration_months' => $durationMonths,
                ':amount' => number_format($amount, 2, '.', ''),
                ':payment_date' => manilaToday()->format('Y-m-d'),
                ':payment_method' => $paymentMethod,
                ':payment_channel' => $paymentMethod === 'cash' ? 'counter' : 'gcash',
                ':idempotency_key' => $idempotencyHash,
                ':proof_name' => $proofStoredName,
                ':proof_original_name' => $paymentProof['original_name'] ?? null,
                ':proof_mime_type' => $paymentProof['mime_type'] ?? null,
                ':proof_file_size' => $paymentProof['file_size'] ?? null,
            ]);
            $paymentId = (int)$conn->lastInsertId();
        } else {
            $visitDate = manilaToday()->format('Y-m-d');
            $expiresAt = (new DateTimeImmutable($visitDate, new DateTimeZone('Asia/Manila')))
                ->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s');
            $passCode = strtoupper(bin2hex(random_bytes(16)));
            $proofStoredName = null;
            if ($paymentProof) {
                $proofStoredName = bin2hex(random_bytes(24)) . '.' . $paymentProof['extension'];
                $proofPath = dirname($uploadDirectory) . DIRECTORY_SEPARATOR . 'daily-passes'
                    . DIRECTORY_SEPARATOR . $proofStoredName;
                $dailyPassDirectory = dirname($uploadDirectory) . DIRECTORY_SEPARATOR . 'daily-passes';
                if (!is_dir($dailyPassDirectory) && !mkdir($dailyPassDirectory, 0750, true) && !is_dir($dailyPassDirectory)) {
                    throw new RuntimeException('Unable to create private Daily Pass proof storage.');
                }
                if (!move_uploaded_file($paymentProof['tmp_name'], $proofPath)) {
                    throw new RuntimeException('Unable to store the private GCash payment proof.');
                }
                $storedFiles[] = $proofPath;
            }
            $paymentStatus = $paymentMethod === 'cash' ? 'unpaid' : 'pending';
            $passInsert = $conn->prepare(
                "INSERT INTO daily_pass_transactions
                    (member_id, customer_name, customer_phone, pass_code, idempotency_key, visit_date, expires_at,
                     payment_method, amount, payment_status, transaction_source, proof_stored_name,
                     proof_original_name, proof_mime_type, proof_file_size, recorded_by)
                 VALUES
                    (:member_id, :customer_name, :customer_phone, :pass_code, :idempotency_key, :visit_date, :expires_at,
                     :payment_method, :amount, :payment_status, 'counter', :proof_stored_name,
                     :proof_original_name, :proof_mime_type, :proof_file_size, :recorded_by)"
            );
            $passInsert->execute([
                ':member_id' => $memberId,
                ':customer_name' => $fullName,
                ':customer_phone' => $phone,
                ':pass_code' => $passCode,
                ':idempotency_key' => $idempotencyHash,
                ':visit_date' => $visitDate,
                ':expires_at' => $expiresAt,
                ':payment_method' => $paymentMethod,
                ':amount' => number_format(GYM_DAILY_PASS_PRICE, 2, '.', ''),
                ':payment_status' => $paymentStatus,
                ':proof_stored_name' => $proofStoredName,
                ':proof_original_name' => $paymentProof['original_name'] ?? null,
                ':proof_mime_type' => $paymentProof['mime_type'] ?? null,
                ':proof_file_size' => $paymentProof['file_size'] ?? null,
                ':recorded_by' => $adminId,
            ]);
            $passId = (int)$conn->lastInsertId();
        }

        $conn->commit();
        return [
            'success' => true,
            'replayed' => false,
            'member_id' => $memberId,
            'member_name' => $fullName,
            'membership_type' => $membershipType,
            'member_category' => $memberCategory,
            'payment_method' => $paymentMethod,
            'amount' => number_format($amount, 2, '.', ''),
            'payment_status' => $paymentStatus,
            'payment_id' => $paymentId,
            'daily_pass_id' => $passId,
            'registration_request_id' => $registrationRequestId,
        ];
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        foreach ($storedFiles as $storedFile) {
            if (is_file($storedFile)) {
                unlink($storedFile);
            }
        }
        throw $error;
    } finally {
        if ($memberCodeLock) {
            $conn->query("SELECT RELEASE_LOCK('admin_member_code_sequence')");
        }
    }
}
