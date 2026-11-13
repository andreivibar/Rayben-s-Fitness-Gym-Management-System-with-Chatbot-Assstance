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
require_once __DIR__ . '/../config/registration_workflow.php';
require_once __DIR__ . '/../config/membership_workflow.php';
require_once __DIR__ . '/../config/registration_storage.php';
require_once __DIR__ . '/../config/membership_pricing.php';
require_once __DIR__ . '/../config/notification_workflow.php';
require_once __DIR__ . '/../config/member_gender.php';
require_once __DIR__ . '/../config/duplicate_name_registration.php';
require_once __DIR__ . '/../config/booking_pricing.php';

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function validateWalkInUpload(string $field, bool $required): ?array {
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            sendResponse(['success' => false, 'message' => 'Upload the required payment or eligibility document.'], 400);
        }
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        sendResponse(['success' => false, 'message' => 'Unable to receive the uploaded document.'], 400);
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 5 * 1024 * 1024) {
        sendResponse(['success' => false, 'message' => 'Documents must be 5 MB or smaller.'], 400);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) {
        sendResponse(['success' => false, 'message' => 'Use a JPG, PNG, or PDF document.'], 400);
    }
    return [
        'tmp_name' => $file['tmp_name'],
        'original_name' => mb_substr(basename((string)$file['name']), 0, 255),
        'mime_type' => $mime,
        'file_size' => (int)$file['size'],
        'extension' => $extensions[$mime],
    ];
}

function activateVerifiedMonthlyRequest(PDO $conn, array $request, int $reviewerId): bool {
    if (($request['status'] ?? '') !== 'approved'
        || ($request['membership_type'] ?? '') !== 'monthly'
        || ($request['payment_status'] ?? '') !== 'verified'
        || empty($request['member_id'])) {
        return false;
    }

    $existingPayment = $conn->prepare(
        'SELECT id FROM payments WHERE registration_request_id = :request_id LIMIT 1 FOR UPDATE'
    );
    $existingPayment->execute([':request_id' => (int)$request['id']]);
    if ($existingPayment->fetchColumn()) {
        return false;
    }

    $memberStmt = $conn->prepare(
        'SELECT id, user_id, membership_type, membership_start, membership_end
         FROM members WHERE id = :member_id FOR UPDATE'
    );
    $memberStmt->execute([':member_id' => (int)$request['member_id']]);
    $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member || empty($member['user_id'])) {
        throw new RuntimeException('Approved monthly request has no linked member account.');
    }

    $category = strtolower(trim((string)($request['member_category'] ?? 'regular')));
    $durationMonths = monthlyDurationFromAmount($request['payment_amount'] ?? null, $category);
    $planId = !empty($request['membership_plan_id']) ? (int)$request['membership_plan_id'] : null;
    if ($durationMonths === null || !$planId) {
        throw new RuntimeException('Verified monthly request has invalid plan or payment details.');
    }

    $planStmt = $conn->prepare(
        'SELECT id, plan_code, duration_months, regular_price, student_price, senior_price, pwd_price
         FROM membership_plans WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $planStmt->execute([':id' => $planId]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan || !isVerifiedOneMonthMembershipPlan($plan)) {
        throw new RuntimeException('Verified monthly request no longer has an active approved plan.');
    }

    $period = calculateNextPaidSubscriptionPeriod(
        $conn,
        (int)$member['id'],
        $member['membership_end'] ?: null,
        $durationMonths,
        $member['membership_start'] ?: null
    );
    $paymentMethod = in_array($request['payment_method'] ?? '', ['cash', 'gcash'], true)
        ? $request['payment_method']
        : null;
    if ($paymentMethod === null) {
        throw new RuntimeException('Verified monthly request has an invalid payment method.');
    }
    $verifiedBy = !empty($request['payment_verified_by'])
        ? (int)$request['payment_verified_by']
        : $reviewerId;
    $paymentDate = $request['payment_date'] ?: manilaToday()->format('Y-m-d');

    $paymentStmt = $conn->prepare(
        "INSERT INTO payments
            (member_id, registration_request_id, membership_plan_id, duration_months,
             amount, payment_type, status, payment_date, reference_no, payment_method,
             payment_channel, verification_status, verified_by, verified_at, recorded_by, created_at, updated_at)
         VALUES
            (:member_id, :request_id, :plan_id, :duration_months,
             :amount, 'monthly_subscription', 'paid', :payment_date, NULL, :payment_method,
             :payment_channel, 'verified', :verified_by, :verified_at, :recorded_by, NOW(), NOW())"
    );
    $paymentStmt->execute([
        ':member_id' => (int)$member['id'],
        ':request_id' => (int)$request['id'],
        ':plan_id' => $planId,
        ':duration_months' => $durationMonths,
        ':amount' => $request['payment_amount'],
        ':payment_date' => $paymentDate,
        ':payment_method' => $paymentMethod,
        ':payment_channel' => $paymentMethod === 'cash' ? 'counter' : 'gcash',
        ':verified_by' => $verifiedBy,
        ':verified_at' => $request['payment_verified_at'] ?: manilaToday()->format('Y-m-d H:i:s'),
        ':recorded_by' => $paymentMethod === 'cash' ? $verifiedBy : null,
    ]);
    $paymentId = (int)$conn->lastInsertId();

    $updateMember = $conn->prepare(
        "UPDATE members
         SET membership_type = 'monthly', member_category = :category,
             membership_start = :membership_start, membership_end = :membership_end, status = 'active'
         WHERE id = :member_id"
    );
    $updateMember->execute([
        ':category' => $category,
        ':membership_start' => $period['membership_start'],
        ':membership_end' => $period['ends_on'],
        ':member_id' => (int)$member['id'],
    ]);
    $periodId = insertSubscriptionPeriod(
        $conn,
        (int)$member['id'],
        $planId,
        (int)$request['id'],
        $paymentId,
        $durationMonths,
        $period['starts_on'],
        $period['ends_on'],
        'registration'
    );
    grantMonthlySubscriptionBookingCredits(
        $conn,
        (int)$member['id'],
        $paymentId,
        $periodId,
        $period['starts_on'],
        $period['ends_on']
    );
    addMemberWorkflowNotification(
        $conn,
        (int)$member['user_id'],
        'membership_payment_verified',
        'Monthly payment verified',
        'Your monthly payment has been verified and your membership is active or scheduled.',
        $period['ends_on']
    );

    return true;
}

try {
    $input = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST ?: (json_decode(file_get_contents('php://input'), true) ?? []))
        : [];
    $action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? '')));
    if ($action === 'create_member_upgrade') {
        $authUser = requireRole($conn, ['member']);
    } elseif (in_array($action, ['confirm_cash_payment', 'review_payment_proof', 'payment_reviews'], true)) {
        $authUser = requireRole($conn, ['admin', 'administrator', 'staff']);
    } else {
        $authUser = requireRole($conn, ['admin']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $paymentReviewMode = $action === 'payment_reviews';
        $applicationHistoryMode = $action === 'application_history';
        $stmt = $conn->prepare(
            "SELECT r.id, r.member_id, r.first_name, r.middle_name, r.last_name, r.full_name, r.email, r.phone, r.address,
                    r.date_of_birth, r.membership_type, r.membership_plan_id, mp.plan_name,
                    mp.duration_months, r.registration_source, r.member_category,
                    current_member.membership_type AS current_membership_type,
                    CASE WHEN r.registration_source = 'online' AND r.membership_type = 'walk-in'
                               AND EXISTS (
                                   SELECT 1 FROM daily_pass_transactions initial_pass
                                   WHERE initial_pass.member_id = r.member_id
                                     AND initial_pass.transaction_source = 'online'
                               )
                         THEN 1 ELSE 0 END AS is_initial_daily_pass_approval,
                    (SELECT daily_pass.id
                     FROM daily_pass_transactions daily_pass
                     WHERE daily_pass.member_id = r.member_id
                       AND daily_pass.transaction_source = 'online'
                     ORDER BY daily_pass.id ASC LIMIT 1) AS initial_pass_id,
                    (SELECT daily_pass.payment_status
                     FROM daily_pass_transactions daily_pass
                     WHERE daily_pass.member_id = r.member_id
                       AND daily_pass.transaction_source = 'online'
                     ORDER BY daily_pass.id ASC LIMIT 1) AS initial_pass_payment_status,
                    (SELECT daily_pass.payment_method
                     FROM daily_pass_transactions daily_pass
                     WHERE daily_pass.member_id = r.member_id
                       AND daily_pass.transaction_source = 'online'
                     ORDER BY daily_pass.id ASC LIMIT 1) AS initial_pass_payment_method,
                    (SELECT daily_pass.amount
                     FROM daily_pass_transactions daily_pass
                     WHERE daily_pass.member_id = r.member_id
                       AND daily_pass.transaction_source = 'online'
                     ORDER BY daily_pass.id ASC LIMIT 1) AS initial_pass_payment_amount,
                    (SELECT daily_pass.proof_original_name
                     FROM daily_pass_transactions daily_pass
                     WHERE daily_pass.member_id = r.member_id
                       AND daily_pass.transaction_source = 'online'
                     ORDER BY daily_pass.id ASC LIMIT 1) AS initial_pass_proof_name,
                    (SELECT daily_pass.verified_at
                     FROM daily_pass_transactions daily_pass
                     WHERE daily_pass.member_id = r.member_id
                       AND daily_pass.transaction_source = 'online'
                     ORDER BY daily_pass.id ASC LIMIT 1) AS initial_pass_verified_at,
                    (SELECT pass_verifier.full_name
                     FROM daily_pass_transactions daily_pass
                     LEFT JOIN users pass_verifier ON pass_verifier.id = daily_pass.verified_by
                     WHERE daily_pass.member_id = r.member_id
                       AND daily_pass.transaction_source = 'online'
                     ORDER BY daily_pass.id ASC LIMIT 1) AS initial_pass_verified_by_name,
                    current_member.member_category AS current_member_category,
                    current_member.membership_start AS current_membership_start,
                    current_member.membership_end AS current_membership_end,
                    current_member.created_at AS member_created_at,
                    r.payment_method, r.payment_date, r.payment_verified_by, r.payment_verified_at,
                    r.status, r.payment_status, r.discount_status, r.payment_amount, r.review_note,
                    r.created_at, r.reviewed_at,
                    pf.id AS payment_file_id, pf.original_name AS payment_file_name,
                    pf.review_status AS payment_file_status, pf.review_note AS payment_file_note,
                    pf.reviewed_at AS payment_file_reviewed_at, payment_reviewer.full_name AS payment_reviewer_name,
                    df.id AS discount_file_id, df.original_name AS discount_file_name,
                    df.review_status AS discount_file_status, df.review_note AS discount_file_note,
                    df.reviewed_at AS discount_file_reviewed_at, document_reviewer.full_name AS discount_reviewer_name
             FROM registration_requests r
             LEFT JOIN membership_plans mp ON mp.id = r.membership_plan_id
             LEFT JOIN members current_member ON current_member.id = r.member_id
             LEFT JOIN users document_reviewer ON document_reviewer.id = (
                 SELECT df_latest.reviewed_by FROM registration_request_files df_latest
                 WHERE df_latest.request_id = r.id AND df_latest.document_type = 'discount_proof'
                 ORDER BY df_latest.id DESC LIMIT 1
             )
             LEFT JOIN registration_request_files pf ON pf.id = (
                 SELECT MAX(pf_latest.id) FROM registration_request_files pf_latest
                 WHERE pf_latest.request_id = r.id AND pf_latest.document_type = 'payment_proof'
             )
             LEFT JOIN users payment_reviewer ON payment_reviewer.id = COALESCE(pf.reviewed_by, r.payment_verified_by)
             LEFT JOIN registration_request_files df ON df.id = (
                 SELECT MAX(df_latest.id) FROM registration_request_files df_latest
                 WHERE df_latest.request_id = r.id AND df_latest.document_type = 'discount_proof'
             )
             WHERE " . ($paymentReviewMode
                ? "r.membership_type = 'monthly' AND r.member_id IS NOT NULL
                   AND r.status IN ('pending', 'approved')
                   AND ((r.payment_method = 'cash' AND r.payment_status = 'pending')
                     OR (r.payment_method = 'gcash' AND r.payment_status IN ('pending', 'submitted', 'needs_correction')))"
                : ($applicationHistoryMode
                    ? "r.status IN ('approved', 'rejected')"
                    : 'r.status = :status')) . "
             ORDER BY r.created_at DESC"
        );
        if (!$paymentReviewMode && !$applicationHistoryMode) {
            $pendingStatus = 'pending';
            $stmt->bindParam(':status', $pendingStatus, PDO::PARAM_STR);
        }
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure full_name is formatted if missing
        foreach ($requests as &$req) {
            if (empty($req['full_name'])) {
                $fn = trim((string)($req['first_name'] ?? ''));
                $mn = trim((string)($req['middle_name'] ?? ''));
                $ln = trim((string)($req['last_name'] ?? ''));
                $req['full_name'] = trim($fn . ($mn !== '' ? ' ' . $mn : '') . ($ln !== '' ? ' ' . $ln : ''));
            }
            $req['requested_duration_months'] = $req['membership_type'] === 'monthly'
                ? monthlyDurationFromAmount($req['payment_amount'] ?? null, (string)($req['member_category'] ?? 'regular'))
                : null;
            $req['requested_subscription_period'] = null;
            if (!empty($req['member_id']) && $req['requested_duration_months']) {
                $req['requested_subscription_period'] = calculateNextPaidSubscriptionPeriod(
                    $conn,
                    (int)$req['member_id'],
                    $req['current_membership_end'] ?: null,
                    (int)$req['requested_duration_months'],
                    $req['current_membership_start'] ?: null
                );
            }
        }
        unset($req);

        if ($paymentReviewMode) {
            sendResponse(['success' => true, 'payment_reviews' => $requests]);
        }
        if ($applicationHistoryMode) {
            sendResponse(['success' => true, 'application_history' => $requests]);
        }
        sendResponse(['success' => true, 'registration_requests' => $requests]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestId = $input['id'] ?? null;

        if ($action === 'create_member_upgrade') {
            $memberCategory = strtolower(trim((string)($input['member_category'] ?? 'regular')));
            $paymentMethod = strtolower(trim((string)($input['payment_method'] ?? '')));
            $planId = filter_var($input['membership_plan_id'] ?? 0, FILTER_VALIDATE_INT);
            try {
                $durationMonths = validateMonthlyDuration($input['duration_months'] ?? 1);
            } catch (InvalidArgumentException $e) {
                sendResponse(['success' => false, 'message' => $e->getMessage()], 400);
            }
            if (!in_array($memberCategory, ['regular', 'student', 'senior', 'pwd'], true)
                || !in_array($paymentMethod, ['cash', 'gcash'], true)
                || !$planId || $planId < 1) {
                sendResponse(['success' => false, 'message' => 'Choose a valid monthly plan, member category, and payment method.'], 400);
            }

            $planStmt = $conn->prepare(
                'SELECT id, plan_code, duration_months, regular_price, student_price, senior_price, pwd_price
                 FROM membership_plans WHERE id = :id AND is_active = 1 LIMIT 1'
            );
            $planStmt->execute([':id' => $planId]);
            $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
            if (!$plan || !isVerifiedOneMonthMembershipPlan($plan)) {
                sendResponse(['success' => false, 'message' => 'The selected one-month membership plan is unavailable.'], 409);
            }

            $paymentProof = $paymentMethod === 'gcash'
                ? validateWalkInUpload('payment_proof', true)
                : null;
            $discountProof = $memberCategory !== 'regular'
                ? validateWalkInUpload('discount_proof', true)
                : null;
            if ($paymentMethod === 'gcash') {
                $settings = $conn->query(
                    'SELECT gcash_account_name, gcash_account_number FROM gym_payment_settings WHERE id = 1'
                )->fetch(PDO::FETCH_ASSOC);
                if (empty($settings['gcash_account_name']) || empty($settings['gcash_account_number'])) {
                    sendResponse(['success' => false, 'message' => 'GCash payments are unavailable until the gym configures payment details.'], 503);
                }
            }

            $conn->beginTransaction();
            $memberStmt = $conn->prepare(
                'SELECT m.* FROM members m WHERE m.user_id = :user_id FOR UPDATE'
            );
            $memberStmt->execute([':user_id' => (int)$authUser['id']]);
            $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'Member profile not found for this account.'], 404);
            }
            $pendingStmt = $conn->prepare(
                "SELECT id FROM registration_requests
                 WHERE member_id = :member_id AND membership_type = 'monthly' AND status = 'pending'
                 LIMIT 1"
            );
            $pendingStmt->execute([':member_id' => (int)$member['id']]);
            if ($pendingStmt->fetch(PDO::FETCH_ASSOC)) {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'You already have a monthly membership request awaiting review.'], 409);
            }
            $pendingPaymentStmt = $conn->prepare(
                "SELECT id FROM payments
                 WHERE member_id = :member_id AND payment_type = 'monthly_subscription'
                   AND status = 'pending' AND registration_request_id IS NULL
                 LIMIT 1 FOR UPDATE"
            );
            $pendingPaymentStmt->execute([':member_id' => (int)$member['id']]);
            if ($pendingPaymentStmt->fetch(PDO::FETCH_ASSOC)) {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'Resolve the existing monthly payment request before starting another subscription.'], 409);
            }
            $paymentAmount = monthlyMembershipTotal($memberCategory, $durationMonths);
            $paymentStatus = $paymentMethod === 'gcash' ? 'submitted' : 'pending';
            $discountStatus = $discountProof ? 'submitted' : 'not_required';
            $requestToken = bin2hex(random_bytes(32));
            $username = 'upgrade_' . (int)$member['id'] . '_' . bin2hex(random_bytes(3));
            $unusablePassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $insert = $conn->prepare(
                "INSERT INTO registration_requests
                    (member_id, first_name, middle_name, last_name, full_name, username, email, phone, address,
                     date_of_birth, password, membership_type, member_category, membership_plan_id,
                     payment_method, payment_date, application_token_hash,
                     registration_source, status, payment_status, discount_status, payment_amount)
                 VALUES
                    (:member_id, :first_name, :middle_name, :last_name, :full_name, :username, :email, :phone, :address,
                     :date_of_birth, :password, 'monthly', :member_category, :plan_id,
                     :payment_method, :payment_date, :token_hash,
                     'online', 'pending', :payment_status, :discount_status, :payment_amount)"
            );
            $insert->execute([
                ':member_id' => (int)$member['id'],
                ':first_name' => $member['first_name'],
                ':middle_name' => $member['middle_name'] ?: null,
                ':last_name' => $member['last_name'],
                ':full_name' => $member['full_name'],
                ':username' => $username,
                ':email' => $member['email'] ?: '',
                ':phone' => $member['phone'],
                ':address' => $member['address'],
                ':date_of_birth' => $member['date_of_birth'],
                ':password' => $unusablePassword,
                ':member_category' => $memberCategory,
                ':plan_id' => (int)$plan['id'],
                ':payment_method' => $paymentMethod,
                ':payment_date' => $paymentMethod === 'gcash' ? manilaToday()->format('Y-m-d') : null,
                ':token_hash' => hash('sha256', $requestToken),
                ':payment_status' => $paymentStatus,
                ':discount_status' => $discountStatus,
                ':payment_amount' => number_format($paymentAmount, 2, '.', ''),
            ]);
            $newRequestId = (int)$conn->lastInsertId();
            $isRenewal = in_array(
                strtolower(trim((string)$member['membership_type'])),
                ['monthly', 'monthlysubscription', 'monthly_subscription'],
                true
            );
            $eventType = $isRenewal ? 'membership_renewal_requested' : 'membership_upgrade_requested';
            $eventTitle = $isRenewal ? 'Membership renewal request' : 'Membership upgrade request';
            $currentType = notificationWorkflowMembershipTypeLabel((string)$member['membership_type']);
            $requestedType = notificationWorkflowMembershipTypeLabel('monthly');
            $currentCategory = notificationWorkflowCategoryLabel($member['member_category'] ?? null);
            $requestedCategory = notificationWorkflowCategoryLabel($memberCategory);
            $durationLabel = $durationMonths . ' ' . ($durationMonths === 1 ? 'month' : 'months');
            $paymentNote = $paymentMethod === 'cash'
                ? 'Cash payment is pending staff confirmation.'
                : 'GCash proof was submitted and is pending verification.';
            $approvalNote = $discountStatus === 'submitted'
                ? 'Eligibility document verification and membership approval are pending.'
                : 'Membership approval is pending.';
            $eventMessage = $isRenewal
                ? "{$member['full_name']} submitted a monthly membership renewal request for {$durationLabel}, from {$currentCategory} to {$requestedCategory}."
                : "{$member['full_name']} submitted a membership upgrade request from {$currentType} ({$currentCategory}) to {$requestedType} ({$requestedCategory}) for {$durationLabel}.";
            $eventMessage .= " Payment method: " . ucfirst($paymentMethod) . ". {$paymentNote} {$approvalNote} Submitted " .
                manilaToday()->format('F j, Y') . ". Request #{$newRequestId}.";
            $uploadDirectory = registrationUploadDirectory();
            $storedFiles = [];
            try {
                if (($paymentProof || $discountProof) && !is_dir($uploadDirectory)
                    && !mkdir($uploadDirectory, 0750, true)) {
                    throw new RuntimeException('Unable to create private membership document storage.');
                }
                $fileInsert = $conn->prepare(
                    'INSERT INTO registration_request_files
                        (request_id, document_type, stored_name, original_name, mime_type, file_size)
                     VALUES (:request_id, :document_type, :stored_name, :original_name, :mime_type, :file_size)'
                );
                foreach (['payment_proof' => $paymentProof, 'discount_proof' => $discountProof] as $documentType => $document) {
                    if (!$document) continue;
                    $storedName = bin2hex(random_bytes(24)) . '.' . $document['extension'];
                    $path = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
                    if (!move_uploaded_file($document['tmp_name'], $path)) {
                        throw new RuntimeException('Unable to store a private membership document.');
                    }
                    $storedFiles[] = $path;
                    $fileInsert->execute([
                        ':request_id' => $newRequestId,
                        ':document_type' => $documentType,
                        ':stored_name' => $storedName,
                        ':original_name' => $document['original_name'],
                        ':mime_type' => $document['mime_type'],
                        ':file_size' => $document['file_size'],
                    ]);
                }
                notificationWorkflowCreateForAdmins(
                    $conn,
                    $eventType,
                    $eventTitle,
                    $eventMessage
                );
                $conn->commit();
                sendResponse([
                    'success' => true,
                    'request_id' => $newRequestId,
                    'payment_amount' => $paymentAmount,
                    'payment_status' => $paymentStatus,
                    'message' => 'Monthly membership request submitted for your existing account. Your account and 1 Day Pass history are unchanged; gym access changes only after required approvals.',
                ], 201);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                foreach ($storedFiles as $storedFile) {
                    if (is_file($storedFile)) unlink($storedFile);
                }
                throw $e;
            }
        }

        if ($action === 'create_walk_in') {
            $firstName = trim((string)($input['first_name'] ?? ''));
            $middleName = trim((string)($input['middle_name'] ?? ''));
            $lastName = trim((string)($input['last_name'] ?? ''));
            $fullName = trim($firstName . ($middleName !== '' ? ' ' . $middleName : '') . ($lastName !== '' ? ' ' . $lastName : ''));
            $username = trim((string)($input['username'] ?? ''));
            $email = trim((string)($input['email'] ?? ''));
            $phone = trim((string)($input['phone'] ?? ''));
            $address = trim((string)($input['address'] ?? ''));
            $dateOfBirth = trim((string)($input['date_of_birth'] ?? ''));
            try {
                $gender = normalizeOptionalMemberGender($input['gender'] ?? null);
            } catch (InvalidArgumentException $e) {
                sendResponse(['success' => false, 'message' => $e->getMessage()], 400);
            }
            $password = (string)($input['password'] ?? '');
            $memberCategory = strtolower(trim((string)($input['member_category'] ?? 'regular')));
            $planId = filter_var($input['membership_plan_id'] ?? 0, FILTER_VALIDATE_INT);
            $paymentMethod = strtolower(trim((string)($input['payment_method'] ?? 'cash')));
            try {
                $durationMonths = validateMonthlyDuration($input['duration_months'] ?? 1);
            } catch (InvalidArgumentException $e) {
                sendResponse(['success' => false, 'message' => $e->getMessage()], 400);
            }

            if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 50 || mb_strlen($lastName) > 50
                || ($middleName !== '' && mb_strlen($middleName) > 50)
                || !preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)
                || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || !preg_match('/^\d{11}$/', $phone) || $address === '' || mb_strlen($address) > 255
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)
                || $dateOfBirth > manilaToday()->format('Y-m-d')
                || strlen($password) < 8
                || !in_array($memberCategory, ['regular', 'student', 'senior', 'pwd'], true)
                || !in_array($paymentMethod, ['cash', 'gcash'], true)
                || !$planId || $planId < 1) {
                sendResponse(['success' => false, 'message' => 'Check the required in-person membership registration fields and try again.'], 400);
            }
            [$birthYear, $birthMonth, $birthDay] = array_map('intval', explode('-', $dateOfBirth));
            if (!checkdate($birthMonth, $birthDay, $birthYear)) {
                sendResponse(['success' => false, 'message' => 'Enter a valid date of birth.'], 400);
            }

            $paymentProof = validateWalkInUpload('payment_proof', $paymentMethod === 'gcash');
            $discountProof = $memberCategory !== 'regular'
                ? validateWalkInUpload('discount_proof', true)
                : null;
            $planStmt = $conn->prepare(
                'SELECT id, plan_code, duration_months, regular_price, student_price, senior_price, pwd_price
                 FROM membership_plans WHERE id = :id AND is_active = 1 LIMIT 1'
            );
            $planStmt->execute([':id' => $planId]);
            $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
            if (!$plan || !isVerifiedOneMonthMembershipPlan($plan)) {
                sendResponse(['success' => false, 'message' => 'The selected membership plan is unavailable.'], 400);
            }
            $paymentAmount = monthlyMembershipTotal($memberCategory, $durationMonths);
            acquireDuplicateNameRegistrationLock($conn, $firstName, $lastName, $username, $email);
            $registrationIdentityEnabled = registrationIdentityReleaseEnabled($conn);
            $userActiveFilter = $registrationIdentityEnabled
                ? "AND registration_status <> 'rejected'"
                : '';
            $requestStatusFilter = $registrationIdentityEnabled
                ? "status <> 'rejected'"
                : "status = 'pending'";
            if ($paymentMethod === 'gcash') {
                $settings = $conn->query('SELECT gcash_account_name, gcash_account_number FROM gym_payment_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
                if (empty($settings['gcash_account_name']) || empty($settings['gcash_account_number'])) {
                    sendResponse(['success' => false, 'message' => 'Configure the gym GCash account before submitting this registration.'], 503);
                }
            }

            $duplicate = $conn->prepare(
                "SELECT id FROM users
                 WHERE (LOWER(username) = LOWER(:username1) OR LOWER(email) = LOWER(:email1))
                   {$userActiveFilter}
                 UNION SELECT id FROM registration_requests
                 WHERE {$requestStatusFilter}
                   AND (LOWER(username) = LOWER(:username2) OR LOWER(email) = LOWER(:email3))
                 LIMIT 1"
            );
            $duplicate->execute([
                ':username1' => $username,
                ':email1' => $email,
                ':username2' => $username,
                ':email3' => $email,
            ]);
            if ($duplicate->fetch(PDO::FETCH_ASSOC)) {
                sendResponse(['success' => false, 'message' => 'The username or email is already registered or awaiting approval.'], 409);
            }
            $storedFiles = [];
            try {
                $applicationToken = bin2hex(random_bytes(32));
                $conn->beginTransaction();
                $paymentDate = $paymentMethod === 'gcash' ? manilaToday()->format('Y-m-d') : null;
                $paymentVerifiedAt = null;
                $paymentStatus = $paymentMethod === 'cash' ? 'pending' : 'submitted';
                $insert = $conn->prepare(
                    "INSERT INTO registration_requests
                        (first_name, middle_name, last_name, full_name, username, email, phone, address,
                         date_of_birth, gender, password, membership_type, member_category, membership_plan_id,
                         payment_method, payment_date, payment_verified_by, payment_verified_at,
                         application_token_hash, registration_source, status, payment_status, discount_status, payment_amount)
                     VALUES
                        (:first_name, :middle_name, :last_name, :full_name, :username, :email, :phone, :address,
                         :date_of_birth, :gender, :password, 'monthly', :member_category, :plan_id,
                         :payment_method, :payment_date, :payment_verified_by, :payment_verified_at,
                         :token_hash, 'walk_in', 'pending', :payment_status, :discount_status, :payment_amount)"
                );
                $insert->execute([
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
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':member_category' => $memberCategory,
                    ':plan_id' => (int)$plan['id'],
                    ':payment_method' => $paymentMethod,
                    ':payment_date' => $paymentDate,
                    ':payment_verified_by' => null,
                    ':payment_verified_at' => $paymentVerifiedAt,
                    ':token_hash' => hash('sha256', $applicationToken),
                    ':payment_status' => $paymentStatus,
                    ':discount_status' => $discountProof ? 'submitted' : 'not_required',
                    ':payment_amount' => number_format($paymentAmount, 2, '.', ''),
                ]);
                $newRequestId = (int)$conn->lastInsertId();
                $uploadDirectory = registrationUploadDirectory();
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
                    throw new RuntimeException('Unable to create private registration document storage.');
                }
                $fileInsert = $conn->prepare(
                    'INSERT INTO registration_request_files
                        (request_id, document_type, stored_name, original_name, mime_type, file_size)
                     VALUES (:request_id, :document_type, :stored_name, :original_name, :mime_type, :file_size)'
                );
                foreach (['payment_proof' => $paymentProof, 'discount_proof' => $discountProof] as $documentType => $document) {
                    if (!$document) continue;
                    $storedName = bin2hex(random_bytes(24)) . '.' . $document['extension'];
                    $absolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
                    if (!move_uploaded_file($document['tmp_name'], $absolutePath)) {
                        throw new RuntimeException('Unable to store a registration document.');
                    }
                    $storedFiles[] = $absolutePath;
                    $fileInsert->execute([
                        ':request_id' => $newRequestId,
                        ':document_type' => $documentType,
                        ':stored_name' => $storedName,
                        ':original_name' => $document['original_name'],
                        ':mime_type' => $document['mime_type'],
                        ':file_size' => $document['file_size'],
                    ]);
                }
                $conn->commit();
                sendResponse([
                    'success' => true,
                    'message' => $paymentMethod === 'cash'
                        ? 'In-person monthly membership application submitted. Cash payment is pending staff confirmation; required eligibility and application approvals are also pending.'
                        : 'In-person monthly membership application submitted. GCash payment and application review are pending.',
                    'request_id' => $newRequestId,
                    'application_token' => $applicationToken,
                ], 201);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                foreach ($storedFiles as $storedFile) {
                    if (is_file($storedFile)) unlink($storedFile);
                }
                $driverErrorCode = $e instanceof PDOException ? ($e->errorInfo[1] ?? null) : null;
                $driverErrorMessage = $e instanceof PDOException ? (string)($e->errorInfo[2] ?? '') : '';
                if ($driverErrorCode === 1062
                    && (strpos($driverErrorMessage, 'uq_registration_requests_username') !== false
                        || strpos($driverErrorMessage, 'uq_registration_requests_active_username') !== false)) {
                    sendResponse([
                        'success' => false,
                        'message' => 'Username is already registered or awaiting approval.',
                    ], 409);
                }
                error_log('Walk-in registration failed: ' . $e->getMessage());
                sendResponse(['success' => false, 'message' => 'Unable to submit walk-in registration. Please try again.'], 500);
            }
        }

        if (!$requestId) {
            sendResponse(['success' => false, 'message' => 'Registration request ID is required.'], 400);
        }

        if (in_array($action, ['review_proof', 'review_payment_proof'], true)) {
            $isPaymentReview = $action === 'review_payment_proof';
            $documentType = $isPaymentReview
                ? 'payment_proof'
                : strtolower(trim((string)($input['document_type'] ?? '')));
            $decision = strtolower(trim((string)($input['decision'] ?? '')));
            $note = trim((string)($input['note'] ?? ''));
            if (!in_array($documentType, ['payment_proof', 'discount_proof'], true)
                || ($isPaymentReview && $documentType !== 'payment_proof')
                || (!$isPaymentReview && $documentType !== 'discount_proof')
                || !in_array($decision, ['verified', 'rejected', 'needs_correction'], true)
                || mb_strlen($note) > 500
                || (in_array($decision, ['rejected', 'needs_correction'], true) && $note === '')) {
                sendResponse(['success' => false, 'message' => 'Invalid document review details.'], 400);
            }

            $conn->beginTransaction();
            try {
                $requestStmt = $conn->prepare(
                    'SELECT id, status, member_id, membership_type, membership_plan_id, member_category, registration_source,
                            payment_method, payment_status, payment_amount, payment_date,
                            payment_verified_by, payment_verified_at
                     FROM registration_requests WHERE id = :id FOR UPDATE'
                );
                $requestStmt->execute([':id' => (int)$requestId]);
                $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
                if (!$request) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Registration request not found.'], 404);
                }
                if (!in_array($request['status'], ['pending', 'approved'], true)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Only active registration payment reviews can be updated.'], 409);
                }
                if (!$isPaymentReview && $request['status'] !== 'pending') {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Only pending applications can have promo documents reviewed.'], 409);
                }
                if ($documentType === 'payment_proof'
                    && (($request['payment_method'] ?? null) !== 'gcash'
                        || !in_array($request['payment_status'] ?? '', ['pending', 'submitted', 'needs_correction'], true))) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Only pending GCash proof can be reviewed.'], 409);
                }
                if ($documentType === 'payment_proof'
                    && $decision === 'verified'
                    && isInitialMonthlyRegistrationRequest($request)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Verify a new registration payment together with account approval in Member Management → Applications.'], 409);
                }

                $fileStmt = $conn->prepare(
                    'SELECT id FROM registration_request_files
                     WHERE request_id = :request_id AND document_type = :document_type
                     ORDER BY id DESC LIMIT 1 FOR UPDATE'
                );
                $fileStmt->execute([
                    ':request_id' => (int)$requestId,
                    ':document_type' => $documentType,
                ]);
                $file = $fileStmt->fetch(PDO::FETCH_ASSOC);
                if (!$file) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'The requested document was not submitted.'], 404);
                }

                $updateFile = $conn->prepare(
                    'UPDATE registration_request_files
                     SET review_status = :decision, review_note = :note, reviewed_by = :reviewed_by, reviewed_at = NOW()
                     WHERE id = :id'
                );
                $updateFile->execute([
                    ':decision' => $decision,
                    ':note' => $note !== '' ? $note : null,
                    ':reviewed_by' => (int)$authUser['id'],
                    ':id' => (int)$file['id'],
                ]);
                $statusColumn = $documentType === 'payment_proof' ? 'payment_status' : 'discount_status';
                $requestUpdateSql = $documentType === 'payment_proof'
                    ? "UPDATE registration_requests
                       SET payment_status = :decision,
                           payment_verified_by = CASE WHEN :decision_verified = 'verified' THEN :reviewed_by ELSE NULL END,
                           payment_verified_at = CASE WHEN :decision_at = 'verified' THEN NOW() ELSE NULL END
                       WHERE id = :id AND status IN ('pending', 'approved')"
                    : "UPDATE registration_requests SET {$statusColumn} = :decision WHERE id = :id AND status IN ('pending', 'approved')";
                $updateRequest = $conn->prepare($requestUpdateSql);
                $updateParams = [':decision' => $decision, ':id' => (int)$requestId];
                if ($documentType === 'payment_proof') {
                    $updateParams[':decision_verified'] = $decision;
                    $updateParams[':decision_at'] = $decision;
                    $updateParams[':reviewed_by'] = (int)$authUser['id'];
                }
                $updateRequest->execute($updateParams);
                if ($updateRequest->rowCount() !== 1) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'This payment review was already processed.'], 409);
                }
                $request['payment_status'] = $decision;
                if ($documentType === 'payment_proof' && $decision === 'verified') {
                    $request['payment_verified_by'] = (int)$authUser['id'];
                    $request['payment_verified_at'] = manilaToday()->format('Y-m-d H:i:s');
                }
                activateVerifiedMonthlyRequest($conn, $request, (int)$authUser['id']);
                if (!empty($request['member_id'])) {
                    $memberUser = $conn->prepare('SELECT user_id FROM members WHERE id = :id LIMIT 1');
                    $memberUser->execute([':id' => (int)$request['member_id']]);
                    $userId = (int)$memberUser->fetchColumn();
                    if ($userId > 0) {
                        $event = $documentType === 'payment_proof' ? 'payment' : 'discount';
                        $decisionLabel = $decision === 'needs_correction' ? 'needs correction' : $decision;
                        addMemberWorkflowNotification(
                            $conn,
                            $userId,
                            $event . '_' . $decision,
                            ucfirst($event) . ' review update',
                            'Your ' . $event . ' verification ' . $decisionLabel . '.',
                            manilaToday()->format('Y-m-d')
                        );
                    }
                }
                $conn->commit();
                sendResponse(['success' => true, 'message' => 'Document review saved.']);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                error_log('Registration document review failed: ' . $e->getMessage());
                sendResponse(['success' => false, 'message' => 'Unable to save the document review. Please try again.'], 500);
            }
        }

        if ($action === 'confirm_cash_payment') {
            $conn->beginTransaction();
            try {
                $cashRequestStmt = $conn->prepare(
                    'SELECT id, status, member_id, membership_type, registration_source, payment_method, payment_status
                     FROM registration_requests WHERE id = :id FOR UPDATE'
                );
                $cashRequestStmt->execute([':id' => (int)$requestId]);
                $cashRequest = $cashRequestStmt->fetch(PDO::FETCH_ASSOC);
                if (!$cashRequest) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Registration request not found.'], 404);
                }
                if (!in_array($cashRequest['status'], ['pending', 'approved'], true)
                    || $cashRequest['payment_method'] !== 'cash'
                    || $cashRequest['payment_status'] !== 'pending') {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Only pending cash payments for active applications can be confirmed.'], 409);
                }
                if (isInitialMonthlyRegistrationRequest($cashRequest)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Confirm a new registration cash payment together with account approval in Member Management → Applications.'], 409);
                }
                $confirmCash = $conn->prepare(
                    "UPDATE registration_requests
                     SET payment_status = 'verified', payment_date = :payment_date,
                         payment_verified_by = :verified_by, payment_verified_at = NOW()
                     WHERE id = :id AND status IN ('pending', 'approved') AND payment_method = 'cash' AND payment_status = 'pending'"
                );
                $confirmCash->execute([
                    ':payment_date' => manilaToday()->format('Y-m-d'),
                    ':verified_by' => (int)$authUser['id'],
                    ':id' => (int)$requestId,
                ]);
                if ($confirmCash->rowCount() !== 1) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Cash payment was already confirmed.'], 409);
                }
                $cashRequest['id'] = (int)$requestId;
                $cashRequest['payment_status'] = 'verified';
                $cashRequest['payment_verified_by'] = (int)$authUser['id'];
                $cashRequest['payment_verified_at'] = manilaToday()->format('Y-m-d H:i:s');
                $cashRequestStmt = $conn->prepare(
                    'SELECT id, status, member_id, membership_type, membership_plan_id, member_category,
                            payment_method, payment_status, payment_amount, payment_date,
                            payment_verified_by, payment_verified_at
                     FROM registration_requests WHERE id = :id'
                );
                $cashRequestStmt->execute([':id' => (int)$requestId]);
                activateVerifiedMonthlyRequest($conn, $cashRequestStmt->fetch(PDO::FETCH_ASSOC) ?: [], (int)$authUser['id']);
                $conn->commit();
                sendResponse(['success' => true, 'message' => 'Cash receipt confirmed. Payment verification remains separate from account approval and gym-entry eligibility.']);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                error_log('Cash membership payment confirmation failed: ' . $e->getMessage());
                sendResponse(['success' => false, 'message' => 'Unable to confirm cash receipt. Please retry.'], 500);
            }
        }

        if (!in_array($action, ['approve', 'reject'], true)) {
            sendResponse(['success' => false, 'message' => 'Invalid action.'], 400);
        }

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        if ($action === 'approve') {
            $preflightStmt = $conn->prepare(
                'SELECT r.id, r.username, r.email, r.status, r.member_id,
                        linked_member.user_id AS linked_user_id
                 FROM registration_requests r
                 LEFT JOIN members linked_member ON linked_member.id = r.member_id
                 WHERE r.id = :id LIMIT 1'
            );
            $preflightStmt->execute([':id' => (int)$requestId]);
            $preflightRequest = $preflightStmt->fetch(PDO::FETCH_ASSOC);
            if (!$preflightRequest) {
                sendResponse(['success' => false, 'message' => 'Request not found.'], 404);
            }
            if ($preflightRequest['status'] === 'pending') {
                $identityReleaseEnabled = registrationIdentityReleaseEnabled($conn);
                $preflightUserFilter = $identityReleaseEnabled
                    ? "AND registration_status <> 'rejected'"
                    : '';
                $preflightUserStmt = $conn->prepare(
                    "SELECT id FROM users
                     WHERE LOWER(TRIM(username)) = LOWER(TRIM(:username))
                       {$preflightUserFilter}
                     LIMIT 1"
                );
                $preflightUserStmt->execute([':username' => (string)$preflightRequest['username']]);
                $preflightUserId = $preflightUserStmt->fetchColumn();
                if (!empty($preflightRequest['member_id']) && !empty($preflightRequest['linked_user_id'])) {
                    $preflightUserId = (int)$preflightRequest['linked_user_id'];
                }
                if (findRegistrationEmailConflict(
                    $conn,
                    (string)($preflightRequest['email'] ?? ''),
                    $preflightUserId === false ? null : (int)$preflightUserId,
                    (int)$preflightRequest['id'],
                    !empty($preflightRequest['member_id']) ? (int)$preflightRequest['member_id'] : null
                )) {
                    sendResponse([
                        'success' => false,
                        'message' => 'Email is already registered or awaiting approval.',
                    ], 409);
                }
            }

            $conn->beginTransaction();
            $requestStmt = $conn->prepare('SELECT * FROM registration_requests WHERE id = :id FOR UPDATE');
            $requestStmt->bindParam(':id', $requestId, PDO::PARAM_INT);
            $requestStmt->execute();
            $requestRow = $requestStmt->fetch(PDO::FETCH_ASSOC);

            if (!$requestRow) {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'Request not found.'], 404);
            }

            if ($requestRow['status'] !== 'pending') {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'Only pending registration requests can be approved.'], 409);
            }

            $hasInitialOnlinePass = false;
            if (!empty($requestRow['member_id'])) {
                $passApplicationStmt = $conn->prepare(
                    "SELECT id FROM daily_pass_transactions
                     WHERE member_id = :member_id AND transaction_source = 'online'
                     LIMIT 1"
                );
                $passApplicationStmt->execute([':member_id' => (int)$requestRow['member_id']]);
                $hasInitialOnlinePass = (bool)$passApplicationStmt->fetchColumn();
            }
            $isInitialDailyPassApproval = isInitialDailyPassRegistrationRequest($requestRow, $hasInitialOnlinePass);
            if ($isInitialDailyPassApproval) {
                $memberStmt = $conn->prepare(
                    "SELECT m.id, m.user_id
                     FROM members m
                     JOIN daily_pass_transactions pass ON pass.member_id = m.id
                     WHERE m.id = :member_id
                       AND pass.transaction_source = 'online'
                     FOR UPDATE"
                );
                $memberStmt->execute([':member_id' => (int)$requestRow['member_id']]);
                $dailyPassMember = $memberStmt->fetch(PDO::FETCH_ASSOC);
                if (!$dailyPassMember || empty($dailyPassMember['user_id'])) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'The Daily Pass member account could not be linked safely.'], 409);
                }

                $memberUserStmt = $conn->prepare(
                    "SELECT id FROM users WHERE id = :user_id AND LOWER(TRIM(role)) = 'member' FOR UPDATE"
                );
                $memberUserStmt->execute([':user_id' => (int)$dailyPassMember['user_id']]);
                $memberUserId = (int)$memberUserStmt->fetchColumn();
                if ($memberUserId <= 0) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'The Daily Pass login account could not be found.'], 409);
                }

                $passStmt = $conn->prepare(
                    "SELECT id, payment_method, payment_status, amount, proof_stored_name
                     FROM daily_pass_transactions
                     WHERE member_id = :member_id AND transaction_source = 'online'
                     ORDER BY id ASC LIMIT 1 FOR UPDATE"
                );
                $passStmt->execute([':member_id' => (int)$requestRow['member_id']]);
                $initialPass = $passStmt->fetch(PDO::FETCH_ASSOC);
                if (!$initialPass || round((float)$initialPass['amount'], 2) !== 100.00) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'The initial Daily Pass transaction could not be verified safely.'], 409);
                }

                if ($initialPass['payment_status'] !== 'paid') {
                    $confirmPayment = filter_var($input['confirm_payment'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    if (!$confirmPayment) {
                        $conn->rollBack();
                        sendResponse(['success' => false, 'message' => 'Confirm the Daily Pass payment in this approval action before approving the account.'], 409);
                    }

                    if ($initialPass['payment_method'] === 'cash' && $initialPass['payment_status'] === 'unpaid') {
                        $verifyPass = $conn->prepare(
                            "UPDATE daily_pass_transactions
                             SET payment_status = 'paid', recorded_by = :recorded_by,
                                 verified_by = :reviewed_by, verified_at = NOW()
                             WHERE id = :id AND payment_status = 'unpaid'"
                        );
                    } elseif ($initialPass['payment_method'] === 'gcash'
                        && $initialPass['payment_status'] === 'pending'
                        && preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/', (string)$initialPass['proof_stored_name'])) {
                        $verifyPass = $conn->prepare(
                            "UPDATE daily_pass_transactions
                             SET payment_status = 'paid', verified_by = :reviewed_by, verified_at = NOW()
                             WHERE id = :id AND payment_status = 'pending'"
                        );
                    } else {
                        $conn->rollBack();
                        sendResponse(['success' => false, 'message' => 'This Daily Pass payment is not eligible for confirmation. Review the payment status and proof before approving.'], 409);
                    }
                    $verifyPassParams = [
                        ':reviewed_by' => (int)$authUser['id'],
                        ':id' => (int)$initialPass['id'],
                    ];
                    if ($initialPass['payment_method'] === 'cash') {
                        $verifyPassParams[':recorded_by'] = (int)$authUser['id'];
                    }
                    $verifyPass->execute($verifyPassParams);
                    if ($verifyPass->rowCount() !== 1) {
                        $conn->rollBack();
                        sendResponse(['success' => false, 'message' => 'This Daily Pass payment was already processed. Refresh the application and try again.'], 409);
                    }
                }

                $approveAccount = $conn->prepare(
                    "UPDATE registration_requests
                     SET status = 'approved', reviewed_by = :reviewed_by, reviewed_at = NOW()
                     WHERE id = :id AND status = 'pending'"
                );
                $approveAccount->execute([
                    ':reviewed_by' => (int)$authUser['id'],
                    ':id' => (int)$requestId,
                ]);
                if ($approveAccount->rowCount() !== 1) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'This account application was already processed.'], 409);
                }

                notificationWorkflowCreate(
                    $conn,
                    $memberUserId,
                    'initial_account_approved',
                    'Your Account Has Been Approved!',
                    "Good news! Your Rayben's Fitness Gym account has been approved and activated. You can now access your Member Dashboard and explore the available features. Welcome to Rayben's Fitness Gym!"
                );
                $conn->commit();
                sendResponse([
                    'success' => true,
                    'message' => 'Daily Pass account approved and payment confirmation recorded.',
                ]);
            }

            $username = trim((string)($requestRow['username'] ?? ''));
            $email = trim((string)($requestRow['email'] ?? ''));
            $firstName = trim((string)($requestRow['first_name'] ?? ''));
            $middleName = trim((string)($requestRow['middle_name'] ?? ''));
            $lastName = trim((string)($requestRow['last_name'] ?? ''));
            $fullName = trim((string)($requestRow['full_name'] ?? ''));
            $phone = trim((string)$requestRow['phone']);
            $address = trim((string)$requestRow['address']);
            $dateOfBirth = $requestRow['date_of_birth'];
            $membershipType = strtolower(trim((string)$requestRow['membership_type']));
            $memberCategory = strtolower(trim((string)($requestRow['member_category'] ?? 'regular')));

            if (!in_array($memberCategory, ['regular', 'student', 'senior', 'pwd'], true)) {
                $memberCategory = 'regular';
            }

            // Fallback parsing if legacy request had only full_name
            if ($firstName === '' && $lastName === '' && $fullName !== '') {
                $parts = preg_split('/\s+/', $fullName);
                $firstName = $parts[0] ?? '';
                if (count($parts) > 1) {
                    $lastName = implode(' ', array_slice($parts, 1));
                }
            }

            // Ensure full_name is correctly formatted
            if ($fullName === '') {
                $fullName = trim($firstName . ($middleName !== '' ? ' ' . $middleName : '') . ($lastName !== '' ? ' ' . $lastName : ''));
            }

            // Validation before approval
            if ($firstName === '') {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'Cannot approve request: First name is missing.'], 400);
            }
            if ($lastName === '') {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'Cannot approve request: Last name is missing.'], 400);
            }
            if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'Cannot approve request: Username is invalid.'], 400);
            }
            if ($membershipType !== 'monthly') {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => '1 Day Pass transactions are recorded separately and cannot be approved as memberships.'], 409);
            }
            $approvalError = registrationApprovalError($requestRow);
            if ($approvalError !== null) {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => $approvalError], 409);
            }
            $membershipPlanId = !empty($requestRow['membership_plan_id'])
                ? (int)$requestRow['membership_plan_id']
                : null;
            if ($membershipType !== 'monthly' || !$membershipPlanId) {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'A valid active monthly plan is required before approval.'], 409);
            }
            $durationMonths = 0;
            if ($membershipPlanId) {
                $planStmt = $conn->prepare(
                    'SELECT id, plan_code, duration_months, regular_price, student_price, senior_price, pwd_price
                     FROM membership_plans WHERE id = :id AND is_active = 1 LIMIT 1'
                );
                $planStmt->execute([':id' => $membershipPlanId]);
                $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan || !isVerifiedOneMonthMembershipPlan($plan)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Only the verified one-month membership plan can be approved.'], 409);
                }
                $durationMonths = monthlyDurationFromAmount(
                    $requestRow['payment_amount'] ?? null,
                    $memberCategory
                );
                if ($durationMonths === null) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'The recorded payment amount does not match a valid monthly duration.'], 409);
                }
                $expectedAmount = monthlyMembershipTotal($memberCategory, $durationMonths);
                if (round((float)$requestRow['payment_amount'], 2) !== $expectedAmount) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'The recorded payment amount does not match the selected monthly duration.'], 409);
                }
            }

            $isInitialMonthlyRegistration = isInitialMonthlyRegistrationRequest($requestRow);
            if ($isInitialMonthlyRegistration && ($requestRow['payment_status'] ?? '') !== 'verified') {
                if (!filter_var($input['confirm_payment'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Confirm the registration payment in this approval action before approving the new account.'], 409);
                }

                $paymentMethod = strtolower(trim((string)($requestRow['payment_method'] ?? '')));
                if ($paymentMethod === 'cash' && in_array($requestRow['payment_status'] ?? '', ['pending', 'unpaid'], true)) {
                    $confirmCash = $conn->prepare(
                        "UPDATE registration_requests
                         SET payment_status = 'verified', payment_date = :payment_date,
                             payment_verified_by = :verified_by, payment_verified_at = NOW()
                         WHERE id = :id AND status = 'pending'
                           AND payment_method = 'cash' AND payment_status IN ('pending', 'unpaid')"
                    );
                    $confirmCash->execute([
                        ':payment_date' => manilaToday()->format('Y-m-d'),
                        ':verified_by' => (int)$authUser['id'],
                        ':id' => (int)$requestId,
                    ]);
                    if ($confirmCash->rowCount() !== 1) {
                        $conn->rollBack();
                        sendResponse(['success' => false, 'message' => 'This registration cash payment was already processed. Refresh the application and try again.'], 409);
                    }
                    $requestRow['payment_status'] = 'verified';
                    $requestRow['payment_date'] = manilaToday()->format('Y-m-d');
                    $requestRow['payment_verified_by'] = (int)$authUser['id'];
                    $requestRow['payment_verified_at'] = manilaToday()->format('Y-m-d H:i:s');
                } elseif ($paymentMethod === 'gcash'
                    && in_array($requestRow['payment_status'] ?? '', ['pending', 'submitted'], true)) {
                    $proofStmt = $conn->prepare(
                        "SELECT id, review_status FROM registration_request_files
                         WHERE request_id = :request_id AND document_type = 'payment_proof'
                         ORDER BY id DESC LIMIT 1 FOR UPDATE"
                    );
                    $proofStmt->execute([':request_id' => (int)$requestId]);
                    $paymentProof = $proofStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$paymentProof || in_array($paymentProof['review_status'] ?? '', ['rejected', 'needs_correction'], true)) {
                        $conn->rollBack();
                        sendResponse(['success' => false, 'message' => 'A valid GCash proof must be available before payment can be verified and the account approved.'], 409);
                    }

                    $verifyProof = $conn->prepare(
                        "UPDATE registration_request_files
                         SET review_status = 'verified', review_note = NULL,
                             reviewed_by = :reviewed_by, reviewed_at = NOW()
                         WHERE id = :id"
                    );
                    $verifyProof->execute([
                        ':reviewed_by' => (int)$authUser['id'],
                        ':id' => (int)$paymentProof['id'],
                    ]);
                    $verifyRequestPayment = $conn->prepare(
                        "UPDATE registration_requests
                         SET payment_status = 'verified', payment_verified_by = :reviewed_by,
                             payment_verified_at = NOW()
                         WHERE id = :id AND status = 'pending'
                           AND payment_method = 'gcash' AND payment_status IN ('pending', 'submitted')"
                    );
                    $verifyRequestPayment->execute([
                        ':reviewed_by' => (int)$authUser['id'],
                        ':id' => (int)$requestId,
                    ]);
                    if ($verifyRequestPayment->rowCount() !== 1) {
                        $conn->rollBack();
                        sendResponse(['success' => false, 'message' => 'This GCash payment was already processed. Refresh the application and try again.'], 409);
                    }
                    $requestRow['payment_status'] = 'verified';
                    $requestRow['payment_verified_by'] = (int)$authUser['id'];
                    $requestRow['payment_verified_at'] = manilaToday()->format('Y-m-d H:i:s');
                } else {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'This registration payment cannot be confirmed from its current status. Review the payment and try again.'], 409);
                }
            }

            // Check username/email uniqueness across account and registration records
            $isExistingMemberUpgrade = !empty($requestRow['member_id']);
            $existingUser = null;
            $existingMember = null;
            if ($isExistingMemberUpgrade) {
                $memberStmt = $conn->prepare(
                    'SELECT id, user_id, membership_type, member_category, membership_start, membership_end
                     FROM members WHERE id = :member_id FOR UPDATE'
                );
                $memberStmt->execute([':member_id' => (int)$requestRow['member_id']]);
                $existingMember = $memberStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingMember || empty($existingMember['user_id'])) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'The existing member account could not be linked safely.'], 409);
                }
                $userStmt = $conn->prepare('SELECT id FROM users WHERE id = :id AND LOWER(role) = \'member\' LIMIT 1');
                $userStmt->execute([':id' => (int)$existingMember['user_id']]);
                $existingUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingUser) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'The existing Member login account could not be found.'], 409);
                }
            } else {
                $registrationIdentityEnabled = registrationIdentityReleaseEnabled($conn);
                $userActiveFilter = $registrationIdentityEnabled
                    ? "AND registration_status <> 'rejected'"
                    : '';
                $userStmt = $conn->prepare(
                    "SELECT id, password, email, role FROM users
                     WHERE LOWER(username) = LOWER(:username)
                       {$userActiveFilter}
                     LIMIT 1"
                );
                $userStmt->bindParam(':username', $username, PDO::PARAM_STR);
                $userStmt->execute();
                $fetchedUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                $existingUser = $fetchedUser ?: null;
            }
            if (!$isExistingMemberUpgrade && $existingUser) {
                $memberStmt = $conn->prepare(
                    'SELECT id, user_id, membership_start, membership_end
                     FROM members
                     WHERE user_id = :user_id
                     LIMIT 1'
                );
                $memberStmt->bindValue(':user_id', (int)$existingUser['id'], PDO::PARAM_INT);
                $memberStmt->execute();
                $existingMember = $memberStmt->fetch(PDO::FETCH_ASSOC);
            }

            acquireDuplicateNameRegistrationLock($conn, $firstName, $lastName, $username, $email);
            if (findRegistrationEmailConflict(
                $conn,
                $email,
                $existingUser ? (int)$existingUser['id'] : null,
                (int)$requestId,
                $isExistingMemberUpgrade ? (int)$requestRow['member_id'] : null
            )) {
                $conn->rollBack();
                sendResponse([
                    'success' => false,
                    'message' => 'Email is already registered or awaiting approval.',
                ], 409);
            }

            $isNewInitialMonthlyAccount = isNewInitialMonthlyRegistrationAccount(
                $isExistingMemberUpgrade,
                $membershipType,
                $requestRow,
                $existingUser,
                $existingMember
            );

            if ($existingUser && !$existingMember && !$isNewInitialMonthlyAccount) {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'The username is already in use by another account.'], 409);
            }
            // Password hash handling
            $storedPwd = $requestRow['password'];
            $pwdInfo = password_get_info($storedPwd);
            if (!empty($pwdInfo) && !empty($pwdInfo['algo']) && $pwdInfo['algo'] !== 0) {
                $hashedPassword = $storedPwd;
            } else {
                $hashedPassword = password_hash($storedPwd, PASSWORD_DEFAULT);
            }

            try {
                // 1. Create or retrieve user record
                $userWasCreated = false;
                if ($existingUser) {
                    $userId = (int)$existingUser['id'];
                        if ($isExistingMemberUpgrade) {
                            $updateUser = $conn->prepare(
                                'UPDATE users SET first_name = :first_name, middle_name = :middle_name,
                                 last_name = :last_name, full_name = :full_name WHERE id = :id'
                            );
                            $updateUser->execute([
                                ':first_name' => $firstName,
                                ':middle_name' => $middleName !== '' ? $middleName : null,
                                ':last_name' => $lastName,
                                ':full_name' => $fullName,
                                ':id' => $userId,
                            ]);
                        } else {
                            $updateUser = $conn->prepare('UPDATE users SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, full_name = :full_name, username = :username, email = :email WHERE id = :id');
                            $updateUser->execute([
                                ':first_name'  => $firstName,
                                ':middle_name' => $middleName !== '' ? $middleName : null,
                                ':last_name'   => $lastName,
                                ':full_name'   => $fullName,
                                ':username'    => $username,
                                ':email'       => $email !== '' ? $email : null,
                                ':id'          => $userId
                            ]);
                        }
                } else {
                    $insertUser = $conn->prepare(
                        'INSERT INTO users (first_name, middle_name, last_name, full_name, username, email, password, role, created_at)
                         VALUES (:first_name, :middle_name, :last_name, :full_name, :username, :email, :password, "member", NOW())'
                    );
                    $insertUser->execute([
                        ':first_name'  => $firstName,
                        ':middle_name' => $middleName !== '' ? $middleName : null,
                        ':last_name'   => $lastName,
                        ':full_name'   => $fullName,
                        ':username'    => $username,
                        ':email'       => $email !== '' ? $email : null,
                        ':password'    => $hashedPassword
                    ]);
                    $userId = (int)$conn->lastInsertId();
                    $userWasCreated = true;
                }

                $linkedUser = $conn->prepare(
                    "SELECT id FROM users
                     WHERE id = :user_id AND LOWER(TRIM(role)) = 'member'
                     LIMIT 1 FOR UPDATE"
                );
                $linkedUser->execute([':user_id' => $userId]);
                if ($userId < 1 || !$linkedUser->fetchColumn()) {
                    throw new RuntimeException('Unable to link the Member profile to its login account.');
                }

                $today = manilaToday();
                $hasVerifiedPayment = $membershipType === 'monthly' && $requestRow['payment_status'] === 'verified';
                $period = $hasVerifiedPayment
                ? calculateNextPaidSubscriptionPeriod(
                    $conn,
                    (int)$requestRow['member_id'],
                    $existingMember['membership_end'] ?? null,
                    $durationMonths,
                    $existingMember['membership_start'] ?? null
                )
                : null;
                $membershipStart = $period['membership_start'] ?? null;
                $membershipEnd = $period['ends_on'] ?? null;

                // 2. Create member record
                if ($existingMember) {
                    if ($hasVerifiedPayment) {
                        $updateMember = $conn->prepare('UPDATE members SET user_id = :user_id, first_name = :first_name, middle_name = :middle_name, last_name = :last_name, full_name = :full_name, gender = :gender, membership_type = :membership_type, member_category = :member_category, membership_start = :membership_start, membership_end = :membership_end, status = "active" WHERE id = :id');
                        $updateMember->execute([
                            ':user_id'     => $userId,
                            ':first_name'  => $firstName,
                            ':middle_name' => $middleName !== '' ? $middleName : null,
                            ':last_name'   => $lastName,
                            ':full_name'   => $fullName,
                            ':gender'      => $requestRow['gender'] ?? null,
                            ':membership_type' => $membershipType,
                            ':member_category' => $memberCategory,
                            ':membership_start' => $membershipStart,
                            ':membership_end' => $membershipEnd,
                            ':id'          => $existingMember['id']
                        ]);
                    } else {
                        $updateMember = $conn->prepare('UPDATE members SET user_id = :user_id, first_name = :first_name, middle_name = :middle_name, last_name = :last_name, full_name = :full_name, gender = :gender WHERE id = :id');
                        $updateMember->execute([
                            ':user_id'     => $userId,
                            ':first_name'  => $firstName,
                            ':middle_name' => $middleName !== '' ? $middleName : null,
                            ':last_name'   => $lastName,
                            ':full_name'   => $fullName,
                            ':gender'      => $requestRow['gender'] ?? null,
                            ':id'          => $existingMember['id']
                        ]);
                    }
                    $memberId = (int)$existingMember['id'];
                } else {
                    $codeStmt = $conn->prepare('SELECT MAX(id) AS max_id FROM members');
                    $codeStmt->execute();
                    $codeRow = $codeStmt->fetch(PDO::FETCH_ASSOC);
                    $nextId = $codeRow ? intval($codeRow['max_id']) + 1 : 1;
                    $memberCode = sprintf('MEM-%04d', $nextId);
                    $memberStatus = 'active';

                    $insertMember = $conn->prepare(
                        'INSERT INTO members (user_id, member_code, first_name, middle_name, last_name, full_name, email, phone, address, date_of_birth, gender, membership_type, member_category, membership_start, membership_end, status, created_at)
                         VALUES (:user_id, :member_code, :first_name, :middle_name, :last_name, :full_name, :email, :phone, :address, :date_of_birth, :gender, :membership_type, :member_category, :membership_start, :membership_end, :status, NOW())'
                    );
                    $insertMember->execute([
                        ':user_id'          => $userId,
                        ':member_code'      => $memberCode,
                        ':first_name'       => $firstName,
                        ':middle_name'      => $middleName !== '' ? $middleName : null,
                        ':last_name'        => $lastName,
                        ':full_name'        => $fullName,
                        ':email'            => $email,
                        ':phone'            => $phone,
                        ':address'          => $address,
                        ':date_of_birth'    => $dateOfBirth,
                        ':gender'           => $requestRow['gender'] ?? null,
                        ':membership_type'  => $membershipType,
                        ':member_category'  => $membershipType === 'monthly' ? $memberCategory : null,
                        ':membership_start' => $membershipStart,
                        ':membership_end'   => $membershipEnd,
                        ':status'           => $memberStatus
                    ]);
                    $memberId = (int)$conn->lastInsertId();
                }

                if ($hasVerifiedPayment) {
                    $paymentMethod = in_array($requestRow['payment_method'] ?? '', ['cash', 'gcash'], true)
                        ? $requestRow['payment_method']
                        : 'gcash';
                    $paymentChannel = $paymentMethod === 'cash' ? 'counter' : 'gcash';
                    $paymentStmt = $conn->prepare(
                        "INSERT INTO payments
                            (member_id, registration_request_id, membership_plan_id, duration_months,
                             amount, payment_type, status, payment_date, reference_no, payment_method,
                             payment_channel, verification_status, verified_by, verified_at, recorded_by, created_at, updated_at)
                         VALUES
                            (:member_id, :request_id, :plan_id, :duration_months,
                             :amount, 'monthly_subscription', 'paid', :payment_date, NULL, :payment_method,
                             :payment_channel, 'verified', :verified_by, :verified_at, :recorded_by, NOW(), NOW())"
                    );
                    $paymentStmt->execute([
                        ':member_id' => $memberId,
                        ':request_id' => (int)$requestId,
                        ':plan_id' => $membershipPlanId,
                        ':duration_months' => $durationMonths,
                        ':amount' => $requestRow['payment_amount'],
                        ':payment_date' => $requestRow['payment_date'] ?: $today->format('Y-m-d'),
                        ':payment_method' => $paymentMethod,
                        ':payment_channel' => $paymentChannel,
                        ':verified_by' => $requestRow['payment_verified_by'] ?: (int)$authUser['id'],
                        ':verified_at' => $requestRow['payment_verified_at'] ?: $today->format('Y-m-d H:i:s'),
                        ':recorded_by' => $paymentMethod === 'cash'
                            ? ($requestRow['payment_verified_by'] ?: (int)$authUser['id'])
                            : null,
                    ]);
                    $paymentId = (int)$conn->lastInsertId();
                    $periodId = insertSubscriptionPeriod(
                        $conn,
                        $memberId,
                        $membershipPlanId,
                        (int)$requestId,
                        $paymentId,
                        $durationMonths,
                        $period['starts_on'],
                        $membershipEnd,
                        'registration'
                    );
                    grantMonthlySubscriptionBookingCredits(
                        $conn,
                        $memberId,
                        $paymentId,
                        $periodId,
                        $period['starts_on'],
                        $membershipEnd
                    );
                }

                $updateRequest = $conn->prepare(
                    "UPDATE registration_requests
                     SET status = :status, member_id = :member_id, reviewed_by = :reviewed_by,
                         reviewed_at = NOW(), password = '!redacted-after-approval!'
                     WHERE id = :id AND status = 'pending'"
                );
                $updateRequest->execute([
                    ':status' => $newStatus,
                    ':member_id' => $memberId,
                    ':reviewed_by' => (int)$authUser['id'],
                    ':id' => (int)$requestId,
                ]);
                if ($updateRequest->rowCount() !== 1) {
                    throw new RuntimeException('Registration request was already processed.');
                }
                if ($userWasCreated) {
                    $registrationMessage = "New member registration: {$fullName} successfully registered an account on " .
                        $today->format('F j, Y') . ". Registration request #{$requestId}.";
                    if ($hasVerifiedPayment && $period) {
                        $registrationMessage .= $period['starts_on'] > $today->format('Y-m-d')
                            ? " Monthly membership is scheduled from {$period['starts_on']} through {$membershipEnd}."
                            : " Monthly membership is active through {$membershipEnd}.";
                    }
                    notificationWorkflowCreateForAdmins(
                        $conn,
                        'member_registered',
                        'New member registration',
                        $registrationMessage
                    );
                }

                if (!empty($userId) && $hasVerifiedPayment && $period) {
                    $isExistingMemberRenewal = $isExistingMemberUpgrade
                        && in_array(
                            strtolower(trim((string)($existingMember['membership_type'] ?? ''))),
                            ['monthly', 'monthlysubscription', 'monthly_subscription'],
                            true
                        );
                    $isExistingMemberUpgradeEvent = $isExistingMemberUpgrade && !$isExistingMemberRenewal;
                    $scheduled = $period['starts_on'] > $today->format('Y-m-d');
                    if ($isExistingMemberUpgradeEvent) {
                        $notificationType = 'membership_upgrade_activated';
                        $notificationTitle = $scheduled ? 'Membership upgrade scheduled' : 'Membership upgraded';
                        $oldType = notificationWorkflowMembershipTypeLabel((string)$existingMember['membership_type']);
                        $oldCategory = notificationWorkflowCategoryLabel($existingMember['member_category'] ?? null);
                        $newCategory = notificationWorkflowCategoryLabel($memberCategory);
                        $notificationMessage = $scheduled
                            ? "Your membership upgrade from {$oldType} ({$oldCategory}) to Monthly membership ({$newCategory}) was approved and is scheduled from {$period['starts_on']} through {$membershipEnd}."
                            : "Your membership was upgraded from {$oldType} ({$oldCategory}) to Monthly membership ({$newCategory}) and is active through {$membershipEnd}.";
                    } elseif ($isExistingMemberRenewal) {
                        $notificationType = 'membership_renewed';
                        $notificationTitle = $scheduled ? 'Membership renewal scheduled' : 'Membership renewed';
                        $notificationMessage = $scheduled
                            ? "Your monthly membership renewal was approved and is scheduled from {$period['starts_on']} through {$membershipEnd}."
                            : "Your monthly membership was renewed from {$period['starts_on']} through {$membershipEnd}.";
                    } else {
                        $notificationType = 'membership_activated';
                        $notificationTitle = $scheduled ? 'Membership activation scheduled' : 'Membership activated';
                        $notificationMessage = $scheduled
                            ? "Your membership was approved and is scheduled from {$period['starts_on']} through {$membershipEnd}."
                            : "Your membership is active until {$membershipEnd}.";
                    }
                    addMemberWorkflowNotification(
                        $conn,
                        (int)$userId,
                        $notificationType,
                        $notificationTitle,
                        $notificationMessage,
                        $membershipEnd ?: $today->format('Y-m-d')
                    );
                } elseif (!empty($userId) && !$isExistingMemberUpgrade) {
                    addMemberWorkflowNotification(
                        $conn,
                        (int)$userId,
                        'membership_activated',
                        'Member account approved',
                        'Your member account has been approved.',
                        $membershipEnd ?: $today->format('Y-m-d')
                    );
                }

                if ($isInitialMonthlyRegistration) {
                    notificationWorkflowCreate(
                        $conn,
                        (int)$userId,
                        'initial_account_approved',
                        'Your Account Has Been Approved!',
                        "Good news! Your Rayben's Fitness Gym account has been approved and activated. You can now access your Member Dashboard and explore the available features. Welcome to Rayben's Fitness Gym!"
                    );
                }

                $conn->commit();
                sendResponse([
                    'success' => true,
                    'message' => $hasVerifiedPayment
                        ? 'Registration request approved and verified payment recorded.'
                        : 'Registration request approved and member account created.'
                ]);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                    sendResponse([
                        'success' => false,
                        'message' => 'Username or email is already registered or awaiting approval.',
                    ], 409);
                }
                error_log('Registration approval failed: ' . $e->getMessage());
                sendResponse(['success' => false, 'message' => 'Server error during approval. Please try again.'], 500);
            }
        }

        if ($action === 'reject') {
            $reason = trim((string)($input['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 500) {
                sendResponse(['success' => false, 'message' => 'Provide a rejection reason up to 500 characters.'], 400);
            }

            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare(
                    "UPDATE registration_requests
                     SET status = :status, review_note = :reason, reviewed_by = :reviewed_by, reviewed_at = NOW()
                     WHERE id = :id AND status = 'pending'"
                );
                $stmt->execute([
                    ':status' => $newStatus,
                    ':reason' => $reason,
                    ':reviewed_by' => (int)$authUser['id'],
                    ':id' => (int)$requestId,
                ]);
                if ($stmt->rowCount() !== 1) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Request not found or already processed.'], 409);
                }

                $rejectedRequestStmt = $conn->prepare(
                    'SELECT username, email, password, membership_type, registration_source, member_id
                     FROM registration_requests WHERE id = :id LIMIT 1'
                );
                $rejectedRequestStmt->execute([':id' => (int)$requestId]);
                $rejectedRequest = $rejectedRequestStmt->fetch(PDO::FETCH_ASSOC);
                $registrationIdentityEnabled = registrationIdentityReleaseEnabled($conn);
                if (
                    $registrationIdentityEnabled
                    && $rejectedRequest
                    && $rejectedRequest['registration_source'] === 'online'
                    && $rejectedRequest['membership_type'] === 'monthly'
                    && empty($rejectedRequest['member_id'])
                ) {
                    $matchingRequestCount = $conn->prepare(
                        "SELECT COUNT(*)
                         FROM registration_requests
                         WHERE LOWER(username) = LOWER(:username)
                           AND LOWER(COALESCE(email, '')) = LOWER(COALESCE(:email, ''))
                           AND BINARY password = BINARY :password
                           AND registration_source = 'online'
                           AND membership_type = 'monthly'
                           AND member_id IS NULL"
                    );
                    $matchingRequestCount->execute([
                        ':username' => (string)$rejectedRequest['username'],
                        ':email' => (string)($rejectedRequest['email'] ?? ''),
                        ':password' => (string)$rejectedRequest['password'],
                    ]);
                    if ((int)$matchingRequestCount->fetchColumn() === 1) {
                        $matchingUsers = $conn->prepare(
                            "SELECT id
                             FROM users
                             WHERE LOWER(username) = LOWER(:username)
                               AND LOWER(COALESCE(email, '')) = LOWER(COALESCE(:email, ''))
                               AND BINARY password = BINARY :password
                               AND LOWER(TRIM(role)) = 'member'
                               AND registration_status = 'active'
                             LIMIT 2"
                        );
                        $matchingUsers->execute([
                            ':username' => (string)$rejectedRequest['username'],
                            ':email' => (string)($rejectedRequest['email'] ?? ''),
                            ':password' => (string)$rejectedRequest['password'],
                        ]);
                        $matchingUserIds = $matchingUsers->fetchAll(PDO::FETCH_COLUMN);
                        if (count($matchingUserIds) === 1) {
                            $rejectInitialUser = $conn->prepare(
                                "UPDATE users
                                 SET registration_status = 'rejected', auth_token = NULL
                                 WHERE id = :id AND registration_status = 'active'"
                            );
                            $rejectInitialUser->execute([':id' => (int)$matchingUserIds[0]]);
                        }
                    }
                } elseif (
                    $registrationIdentityEnabled
                    && $rejectedRequest
                    && $rejectedRequest['registration_source'] === 'online'
                    && $rejectedRequest['membership_type'] === 'walk-in'
                    && !empty($rejectedRequest['member_id'])
                ) {
                    $matchingDailyPassRequestCount = $conn->prepare(
                        "SELECT COUNT(*)
                         FROM registration_requests
                         WHERE member_id = :member_id
                           AND registration_source = 'online'
                           AND membership_type = 'walk-in'"
                    );
                    $matchingDailyPassRequestCount->execute([
                        ':member_id' => (int)$rejectedRequest['member_id'],
                    ]);
                    if ((int)$matchingDailyPassRequestCount->fetchColumn() === 1) {
                        $rejectDailyPassUser = $conn->prepare(
                            "UPDATE users u
                             JOIN members m ON m.user_id = u.id
                             SET u.registration_status = 'rejected', u.auth_token = NULL
                             WHERE m.id = :member_id
                               AND LOWER(TRIM(m.membership_type)) = 'daily_pass'
                               AND LOWER(TRIM(u.role)) = 'member'
                               AND u.registration_status = 'active'
                               AND EXISTS (
                                   SELECT 1
                                   FROM daily_pass_transactions pass
                                   WHERE pass.member_id = m.id
                                     AND pass.transaction_source = 'online'
                               )"
                        );
                        $rejectDailyPassUser->execute([
                            ':member_id' => (int)$rejectedRequest['member_id'],
                        ]);
                    }
                }

                $dailyPassUserStmt = $conn->prepare(
                    "SELECT m.user_id
                     FROM registration_requests r
                     JOIN members m ON m.id = r.member_id
                     WHERE r.id = :id
                       AND r.registration_source = 'online'
                       AND r.membership_type = 'walk-in'
                       AND EXISTS (
                          SELECT 1 FROM daily_pass_transactions pass
                          WHERE pass.member_id = m.id
                            AND pass.transaction_source = 'online'
                       )
                     LIMIT 1"
                );
                $dailyPassUserStmt->execute([':id' => (int)$requestId]);
                $dailyPassUserId = (int)$dailyPassUserStmt->fetchColumn();
                if ($dailyPassUserId > 0) {
                    notificationWorkflowCreate(
                        $conn,
                        $dailyPassUserId,
                        'initial_account_rejected',
                        'Daily Pass registration rejected',
                        'Your Daily Pass account registration was rejected. Reason: ' . $reason
                    );
                }

                $conn->commit();
                sendResponse(['success' => true, 'message' => 'Registration request has been rejected.']);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                error_log('Registration rejection failed: ' . $e->getMessage());
                sendResponse(['success' => false, 'message' => 'Unable to reject registration request. Please try again.'], 500);
            }
        }
    }

    sendResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) $conn->rollBack();
    error_log('Registration requests database operation failed: ' . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'Server error. Please try again later.'], 500);
}

?>
