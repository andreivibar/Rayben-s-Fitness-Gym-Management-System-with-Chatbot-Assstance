<?php

if (defined('PAYMENTS_API_TESTING') && PAYMENTS_API_TESTING === true) {
    $conn = $GLOBALS['payments_api_test_connection'];
    $currentUser = $GLOBALS['payments_api_test_user'];
} else {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
}
require_once __DIR__ . '/../config/membership_workflow.php';
require_once __DIR__ . '/../config/registration_storage.php';
require_once __DIR__ . '/../config/membership_pricing.php';
require_once __DIR__ . '/../config/payment_reporting.php';
require_once __DIR__ . '/../config/booking_pricing.php';
require_once __DIR__ . '/../config/booking.php';

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function normalizePaymentType($value) {
    $value = strtolower(trim((string)$value));
    return in_array($value, ['monthly_subscription', 'walk_in_fee', 'training_session'], true) ? $value : null;
}

function normalizePaymentFilterType($value) {
    $value = strtolower(trim((string)$value));
    return $value === 'daily_pass' ? $value : normalizePaymentType($value);
}

function normalizePaymentStatus($value) {
    $value = strtolower(trim((string)$value));
    return in_array($value, ['paid', 'pending', 'failed'], true) ? $value : null;
}

function membershipTypeKey($value): string {
    return preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string)$value)));
}

function hasApprovedMemberDiscount(PDO $conn, int $memberId, string $category): bool {
    if ($category === 'regular') {
        return true;
    }

    $stmt = $conn->prepare(
        "SELECT id FROM registration_requests
         WHERE member_id = :member_id AND membership_type = 'monthly'
           AND member_category = :category AND status = 'approved'
           AND discount_status = 'verified'
         LIMIT 1"
    );
    $stmt->execute([':member_id' => $memberId, ':category' => $category]);
    return (bool)$stmt->fetchColumn();
}

function paymentSelectSql() {
    return "SELECT p.id, p.member_id, p.booking_id, p.registration_request_id, p.recorded_by,
               p.duration_months, p.amount, p.payment_type, p.status,
                   p.payment_date, p.reference_no, p.payment_method, p.verification_status,
                   p.verified_by, p.verified_at, p.proof_original_name, p.created_at, p.updated_at,
                   m.member_code, m.first_name AS member_first_name, m.middle_name AS member_middle_name, m.last_name AS member_last_name, m.full_name AS member_name, m.email AS member_email,
                   m.membership_type, m.membership_start, m.membership_end, m.status AS membership_status,
                   b.booking_code, b.booking_date, TIME_FORMAT(b.start_time, '%H:%i') AS booking_start_time,
                   TIME_FORMAT(b.end_time, '%H:%i') AS booking_end_time, b.duration_minutes AS booking_duration_minutes,
                   b.session_type AS booking_session_type, b.subtotal AS booking_subtotal,
                   b.credit_amount AS booking_credit_amount, b.amount_due AS booking_amount_due,
                   b.payment_deadline_at AS booking_payment_deadline_at, b.status AS booking_status,
                   tu.full_name AS trainer_name,
                   u.full_name AS recorded_by_name, verifier.full_name AS verified_by_name,
                   sp.starts_on AS scheduled_start_date, sp.ends_on AS scheduled_end_date,
                   rr.discount_status AS member_discount_status,
                   br.id AS refund_id, br.status AS refund_status, br.amount AS refund_amount,
                   br.payment_method AS refund_method, br.external_reference AS refund_reference,
                   br.approved_at AS refund_approved_at, br.processed_at AS refund_processed_at
            FROM payments p
            JOIN members m ON p.member_id = m.id
            LEFT JOIN bookings b ON p.booking_id = b.id
            LEFT JOIN trainers t ON b.trainer_id = t.id
            LEFT JOIN users tu ON t.user_id = tu.id
            LEFT JOIN users u ON p.recorded_by = u.id
            LEFT JOIN users verifier ON p.verified_by = verifier.id
            LEFT JOIN membership_subscription_periods sp ON sp.payment_id = p.id
            LEFT JOIN registration_requests rr ON rr.id = p.registration_request_id
            LEFT JOIN booking_refunds br ON br.payment_id = p.id";
}

function isRefundAdmin(array $user): bool {
    return in_array(strtolower(trim((string)($user['role'] ?? ''))), ['admin', 'administrator'], true);
}

function approveBookingRefund(PDO $conn, array $currentUser, array $input): void {
    if (!isRefundAdmin($currentUser)) {
        sendResponse(['success' => false, 'message' => 'Only Admins can approve booking refunds.'], 403);
    }
    $refundId = filter_var($input['refund_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$refundId || $refundId < 1) {
        sendResponse(['success' => false, 'message' => 'A valid refund request is required.'], 400);
    }

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare(
            'SELECT br.id, br.status, br.amount, br.payment_id, p.amount AS paid_amount,
                    p.status AS payment_status, p.payment_type
             FROM booking_refunds br
             JOIN payments p ON p.id = br.payment_id
             WHERE br.id = :id FOR UPDATE'
        );
        $stmt->execute([':id' => $refundId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$refund) {
            throw new RuntimeException('Refund request not found.', 404);
        }
        if ($refund['status'] !== 'pending') {
            throw new RuntimeException('Only pending refund requests can be approved.', 409);
        }
        if ($refund['payment_status'] !== 'paid' || $refund['payment_type'] !== 'training_session'
            || (int)round((float)$refund['amount'] * 100) !== (int)round((float)$refund['paid_amount'] * 100)) {
            throw new RuntimeException('Refunds must equal the amount of an original paid booking payment.', 409);
        }
        $update = $conn->prepare(
            "UPDATE booking_refunds SET status = 'approved', approved_by_user_id = :admin_id,
                approved_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $update->execute([':admin_id' => (int)$currentUser['id'], ':id' => $refundId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('This refund request was already reviewed.', 409);
        }
        $conn->commit();
        sendResponse(['success' => true, 'message' => 'Full refund approved. Process the external refund before marking it processed.']);
    } catch (RuntimeException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        sendResponse(['success' => false, 'message' => $e->getMessage()], (int)$e->getCode() >= 400 ? (int)$e->getCode() : 409);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('Booking refund approval failed: ' . $e->getMessage());
        sendResponse(['success' => false, 'message' => 'Unable to approve the booking refund.'], 500);
    }
}

function processBookingRefund(PDO $conn, array $currentUser, array $input): void {
    if (!isRefundAdmin($currentUser)) {
        sendResponse(['success' => false, 'message' => 'Only Admins can process booking refunds.'], 403);
    }
    $refundId = filter_var($input['refund_id'] ?? 0, FILTER_VALIDATE_INT);
    $reference = trim((string)($input['external_reference'] ?? ''));
    $transferConfirmed = filter_var($input['confirm_external_refund'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$refundId || $refundId < 1 || $reference === '' || mb_strlen($reference) > 100 || !$transferConfirmed) {
        sendResponse(['success' => false, 'message' => 'Enter a valid refund request, actual Cash receipt or GCash refund reference (up to 100 characters), and confirm the external refund was completed.'], 400);
    }

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare(
            'SELECT br.id, br.status, br.amount, br.payment_id, p.amount AS paid_amount,
                    p.status AS payment_status, p.payment_type, p.payment_method
             FROM booking_refunds br
             JOIN payments p ON p.id = br.payment_id
             WHERE br.id = :id FOR UPDATE'
        );
        $stmt->execute([':id' => $refundId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$refund) {
            throw new RuntimeException('Refund request not found.', 404);
        }
        if ($refund['status'] !== 'approved') {
            throw new RuntimeException('Only approved, unprocessed refunds can be processed.', 409);
        }
        if ($refund['payment_status'] !== 'paid' || $refund['payment_type'] !== 'training_session'
            || !in_array($refund['payment_method'], ['cash', 'gcash'], true)
            || (int)round((float)$refund['amount'] * 100) !== (int)round((float)$refund['paid_amount'] * 100)) {
            throw new RuntimeException('The original booking payment is not eligible for this full refund.', 409);
        }
        $update = $conn->prepare(
            "UPDATE booking_refunds SET status = 'processed', processed_by_user_id = :admin_id,
                processed_at = NOW(), external_reference = :reference
             WHERE id = :id AND status = 'approved' AND processed_at IS NULL"
        );
        $update->execute([
            ':admin_id' => (int)$currentUser['id'],
            ':reference' => $reference,
            ':id' => $refundId,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('This refund was already processed.', 409);
        }
        $conn->commit();
        sendResponse(['success' => true, 'message' => 'Refund marked processed with the supplied external transaction reference.']);
    } catch (RuntimeException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        sendResponse(['success' => false, 'message' => $e->getMessage()], (int)$e->getCode() >= 400 ? (int)$e->getCode() : 409);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('Booking refund processing failed: ' . $e->getMessage());
        sendResponse(['success' => false, 'message' => 'Unable to process the booking refund.'], 500);
    }
}

function validatePaymentProof(): array {
    $file = $_FILES['payment_proof'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        sendResponse(['success' => false, 'message' => 'Upload the GCash payment proof.'], 400);
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 5 * 1024 * 1024) {
        sendResponse(['success' => false, 'message' => 'Payment proof must be 5 MB or smaller.'], 400);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) {
        sendResponse(['success' => false, 'message' => 'Use a JPG, PNG, or PDF payment proof.'], 400);
    }
    return [
        'tmp_name' => $file['tmp_name'],
        'original_name' => mb_substr(basename((string)$file['name']), 0, 255),
        'mime_type' => $mime,
        'file_size' => (int)$file['size'],
        'extension' => $extensions[$mime],
    ];
}

function submitMonthlyGcashPayment(PDO $conn, array $currentUser, array $input): void {
    if (strtolower(trim((string)($currentUser['role'] ?? ''))) !== 'member') {
        sendResponse(['success' => false, 'message' => 'Only authenticated members can submit GCash membership payments.'], 403);
    }
    $memberStmt = $conn->prepare(
        'SELECT id, member_category, membership_type, membership_start, membership_end FROM members WHERE user_id = :user_id LIMIT 1'
    );
    $memberStmt->execute([':user_id' => (int)$currentUser['id']]);
    $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) sendResponse(['success' => false, 'message' => 'Member profile not found.'], 404);
    if (!in_array(membershipTypeKey($member['membership_type']), ['monthly', 'monthlysubscription'], true)) {
        sendResponse(['success' => false, 'message' => 'Use Upgrade to Monthly Membership in your Member Dashboard to start a monthly plan.'], 409);
    }

    $planId = filter_var($input['membership_plan_id'] ?? 0, FILTER_VALIDATE_INT);
    $requestKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
    try {
        $durationMonths = validateMonthlyDuration($input['duration_months'] ?? 1);
    } catch (InvalidArgumentException $e) {
        sendResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
    if (!$planId || $planId < 1 || !preg_match('/^[a-f0-9-]{36}$/', $requestKey)) {
        sendResponse(['success' => false, 'message' => 'Choose a monthly plan and retry key.'], 400);
    }

    $keyHash = hash('sha256', $requestKey);
    $duplicateRequest = $conn->prepare(
        'SELECT id, status FROM payments WHERE idempotency_key = :key AND member_id = :member_id LIMIT 1'
    );
    $duplicateRequest->execute([':key' => $keyHash, ':member_id' => (int)$member['id']]);
    if ($duplicate = $duplicateRequest->fetch(PDO::FETCH_ASSOC)) {
        sendResponse([
            'success' => true,
            'message' => 'This GCash payment request was already received.',
            'payment_id' => (int)$duplicate['id'],
            'status' => $duplicate['status'],
        ]);
    }
    $keyUsed = $conn->prepare('SELECT id FROM payments WHERE idempotency_key = :key LIMIT 1');
    $keyUsed->execute([':key' => $keyHash]);
    if ($keyUsed->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'This retry key belongs to another transaction.'], 409);
    }
    $proof = validatePaymentProof();
    $planStmt = $conn->prepare(
        'SELECT id, plan_code, duration_months, regular_price, student_price, senior_price, pwd_price
         FROM membership_plans WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $planStmt->execute([':id' => $planId]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan || !isVerifiedOneMonthMembershipPlan($plan)) {
        sendResponse(['success' => false, 'message' => 'The selected one-month plan is unavailable.'], 409);
    }
    $category = strtolower(trim((string)$member['member_category']));
    if (!in_array($category, ['regular', 'student', 'senior', 'pwd'], true)) $category = 'regular';
    if (!hasApprovedMemberDiscount($conn, (int)$member['id'], $category)) {
        sendResponse(['success' => false, 'message' => 'Your discount eligibility must be verified before renewing at the discounted rate.'], 409);
    }
    $pendingUpgradeStmt = $conn->prepare(
        "SELECT id FROM registration_requests
         WHERE member_id = :member_id AND membership_type = 'monthly' AND status = 'pending'
         LIMIT 1"
    );
    $pendingUpgradeStmt->execute([':member_id' => (int)$member['id']]);
    if ($pendingUpgradeStmt->fetchColumn()) {
        sendResponse(['success' => false, 'message' => 'Resolve the existing monthly application before starting another renewal.'], 409);
    }
    $amount = monthlyMembershipTotal($category, $durationMonths);
    $uploadDirectory = paymentProofDirectory();
    $storedPath = null;
    try {
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
            throw new RuntimeException('Unable to create private payment proof storage.');
        }
        $storedName = bin2hex(random_bytes(24)) . '.' . $proof['extension'];
        $storedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($proof['tmp_name'], $storedPath)) {
            throw new RuntimeException('Unable to store payment proof.');
        }
        $conn->beginTransaction();
        $lockedMemberStmt = $conn->prepare(
            'SELECT id, member_category, membership_type, membership_start, membership_end FROM members WHERE id = :id FOR UPDATE'
        );
        $lockedMemberStmt->execute([':id' => (int)$member['id']]);
        $lockedMember = $lockedMemberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedMember
            || !in_array(membershipTypeKey($lockedMember['membership_type']), ['monthly', 'monthlysubscription'], true)) {
            throw new RuntimeException('This account must use the Member Dashboard upgrade workflow.');
        }
        $lockedCategory = strtolower(trim((string)$lockedMember['member_category']));
        if (!in_array($lockedCategory, ['regular', 'student', 'senior', 'pwd'], true)) $lockedCategory = 'regular';
        if ($lockedCategory !== $category) {
            throw new RuntimeException('The member category changed while this payment was being submitted. Please retry.');
        }
        $pendingPaymentStmt = $conn->prepare(
            "SELECT id FROM payments
             WHERE member_id = :member_id AND payment_type = 'monthly_subscription'
               AND status = 'pending'
               AND registration_request_id IS NULL
             LIMIT 1"
        );
        $pendingPaymentStmt->execute([':member_id' => (int)$member['id']]);
        if ($pendingPaymentStmt->fetchColumn()) {
            throw new RuntimeException('A monthly payment is already awaiting staff confirmation.');
        }
        if (!hasApprovedMemberDiscount($conn, (int)$member['id'], $category)) {
            throw new RuntimeException('Your discount eligibility must be verified before renewing at the discounted rate.');
        }
        $insert = $conn->prepare(
            "INSERT INTO payments
                (member_id, membership_plan_id, duration_months, amount, payment_type, status, payment_date,
                 reference_no, idempotency_key, proof_stored_name, proof_original_name, proof_mime_type,
                 proof_file_size, verification_status, payment_method, payment_channel)
             VALUES
                (:member_id, :plan_id, :duration_months, :amount, 'monthly_subscription', 'pending', :payment_date,
                 NULL, :idempotency_key, :proof_name, :original_name, :mime_type,
                 :file_size, 'pending', 'gcash', 'gcash')"
        );
        $insert->execute([
            ':member_id' => (int)$member['id'],
            ':plan_id' => (int)$plan['id'],
            ':duration_months' => $durationMonths,
            ':amount' => number_format($amount, 2, '.', ''),
            ':payment_date' => manilaToday()->format('Y-m-d'),
            ':idempotency_key' => $keyHash,
            ':proof_name' => $storedName,
            ':original_name' => $proof['original_name'],
            ':mime_type' => $proof['mime_type'],
            ':file_size' => $proof['file_size'],
        ]);
        $paymentId = (int)$conn->lastInsertId();
        $period = calculateNextPaidSubscriptionPeriod(
            $conn,
            (int)$member['id'],
            $lockedMember['membership_end'] ?? null,
            $durationMonths,
            $lockedMember['membership_start'] ?? null
        );
        $conn->commit();
        sendResponse([
            'success' => true,
            'message' => 'GCash payment proof submitted. Membership will activate or be scheduled only after staff verification.',
            'payment_id' => $paymentId,
            'status' => 'pending',
            'duration_months' => $durationMonths,
            'amount' => number_format($amount, 2, '.', ''),
            'scheduled_start_date' => $period['starts_on'],
            'scheduled_end_date' => $period['ends_on'],
        ], 201);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        if ($storedPath && is_file($storedPath)) unlink($storedPath);
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            sendResponse(['success' => false, 'message' => 'This GCash payment request was already recorded.'], 409);
        }
        throw $e;
    }
}

function submitMonthlyCashPayment(PDO $conn, array $currentUser, array $input): void {
            if (strtolower(trim((string)($currentUser['role'] ?? ''))) !== 'member') {
                sendResponse(['success' => false, 'message' => 'Only authenticated members can request a cash membership renewal.'], 403);
            }
            $memberStmt = $conn->prepare(
                'SELECT id, member_category, membership_type, membership_start, membership_end
                 FROM members WHERE user_id = :user_id LIMIT 1'
            );
            $memberStmt->execute([':user_id' => (int)$currentUser['id']]);
            $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) sendResponse(['success' => false, 'message' => 'Member profile not found.'], 404);
            if (!in_array(membershipTypeKey($member['membership_type']), ['monthly', 'monthlysubscription'], true)) {
                sendResponse(['success' => false, 'message' => 'Only monthly members can request a monthly renewal.'], 409);
            }

            $planId = filter_var($input['membership_plan_id'] ?? 0, FILTER_VALIDATE_INT);
            $requestKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
            try {
                $durationMonths = validateMonthlyDuration($input['duration_months'] ?? 1);
            } catch (InvalidArgumentException $e) {
                sendResponse(['success' => false, 'message' => $e->getMessage()], 400);
            }
            if (!$planId || $planId < 1 || !preg_match('/^[a-f0-9-]{36}$/', $requestKey)) {
                sendResponse(['success' => false, 'message' => 'Choose the monthly plan and retry key.'], 400);
            }
            $keyHash = hash('sha256', $requestKey);
            $duplicate = $conn->prepare(
                'SELECT id, status FROM payments WHERE idempotency_key = :key AND member_id = :member_id LIMIT 1'
            );
            $duplicate->execute([':key' => $keyHash, ':member_id' => (int)$member['id']]);
            if ($payment = $duplicate->fetch(PDO::FETCH_ASSOC)) {
                sendResponse([
                    'success' => true,
                    'message' => 'This cash renewal request was already received.',
                    'payment_id' => (int)$payment['id'],
                    'status' => $payment['status'],
                ]);
            }

            $conn->beginTransaction();
            try {
                $memberLock = $conn->prepare(
                    'SELECT id, member_category, membership_type, membership_start, membership_end
                     FROM members WHERE id = :id FOR UPDATE'
                );
                $memberLock->execute([':id' => (int)$member['id']]);
                $lockedMember = $memberLock->fetch(PDO::FETCH_ASSOC);
                if (!$lockedMember || !in_array(membershipTypeKey($lockedMember['membership_type']), ['monthly', 'monthlysubscription'], true)) {
                    throw new RuntimeException('Only monthly members can request a monthly renewal.');
                }
                $keyUsed = $conn->prepare('SELECT id FROM payments WHERE idempotency_key = :key LIMIT 1 FOR UPDATE');
                $keyUsed->execute([':key' => $keyHash]);
                if ($keyUsed->fetchColumn()) {
                    throw new RuntimeException('This retry key has already been used.');
                }
                $planStmt = $conn->prepare(
                    'SELECT id, plan_code, duration_months, regular_price, student_price, senior_price, pwd_price
                     FROM membership_plans WHERE id = :id AND is_active = 1 LIMIT 1'
                );
                $planStmt->execute([':id' => $planId]);
                $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan || !isVerifiedOneMonthMembershipPlan($plan)) {
                    throw new RuntimeException('The selected one-month plan is unavailable.');
                }
                $category = strtolower(trim((string)$lockedMember['member_category']));
                if (!in_array($category, ['regular', 'student', 'senior', 'pwd'], true)) $category = 'regular';
                if (!hasApprovedMemberDiscount($conn, (int)$member['id'], $category)) {
                    throw new RuntimeException('Discount eligibility must be verified before renewing at the discounted rate.');
                }
                $pending = $conn->prepare(
                    "SELECT id FROM payments
                     WHERE member_id = :member_id AND payment_type = 'monthly_subscription' AND status = 'pending'
                       AND registration_request_id IS NULL LIMIT 1 FOR UPDATE"
                );
                $pending->execute([':member_id' => (int)$member['id']]);
                if ($pending->fetchColumn()) {
                    throw new RuntimeException('A monthly renewal is already awaiting staff confirmation.');
                }
                $pendingRequest = $conn->prepare(
                    "SELECT id FROM registration_requests
                     WHERE member_id = :member_id AND membership_type = 'monthly' AND status = 'pending' LIMIT 1"
                );
                $pendingRequest->execute([':member_id' => (int)$member['id']]);
                if ($pendingRequest->fetchColumn()) {
                    throw new RuntimeException('Resolve the existing monthly application before starting another renewal.');
                }
                $period = calculateNextPaidSubscriptionPeriod(
                    $conn,
                    (int)$member['id'],
                    $lockedMember['membership_end'] ?? null,
                    $durationMonths,
                    $lockedMember['membership_start'] ?? null
                );
                $insert = $conn->prepare(
                    "INSERT INTO payments
                        (member_id, membership_plan_id, duration_months, amount, payment_type, status, payment_date,
                         idempotency_key, verification_status, payment_method, payment_channel)
                     VALUES
                        (:member_id, :plan_id, :duration_months, :amount, 'monthly_subscription', 'pending', :payment_date,
                         :idempotency_key, 'pending', 'cash', 'counter')"
                );
                $insert->execute([
                    ':member_id' => (int)$member['id'],
                    ':plan_id' => (int)$plan['id'],
                    ':duration_months' => $durationMonths,
                    ':amount' => number_format(monthlyMembershipTotal($category, $durationMonths), 2, '.', ''),
                    ':payment_date' => manilaToday()->format('Y-m-d'),
                    ':idempotency_key' => $keyHash,
                ]);
                $paymentId = (int)$conn->lastInsertId();
                $conn->commit();
                sendResponse([
                    'success' => true,
                    'message' => 'Cash renewal request submitted. Staff must confirm receipt before the membership is activated or scheduled.',
                    'payment_id' => $paymentId,
                    'status' => 'pending',
                    'duration_months' => $durationMonths,
                    'amount' => number_format(monthlyMembershipTotal($category, $durationMonths), 2, '.', ''),
                    'scheduled_start_date' => $period['starts_on'],
                    'scheduled_end_date' => $period['ends_on'],
                ], 201);
            } catch (RuntimeException $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                sendResponse(['success' => false, 'message' => $e->getMessage()], 409);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                error_log('Monthly cash renewal submission failed: ' . $e->getMessage());
                sendResponse(['success' => false, 'message' => 'Unable to submit the cash renewal request. Please try again.'], 500);
            }
        }

        function submitBookingPayment(PDO $conn, array $currentUser, array $input, string $method): void {
            if (strtolower(trim((string)($currentUser['role'] ?? ''))) !== 'member') {
                sendResponse(['success' => false, 'message' => 'Only the booking owner can submit a booking payment.'], 403);
            }
            $memberStmt = $conn->prepare('SELECT id FROM members WHERE user_id = :user_id LIMIT 1');
            $memberStmt->execute([':user_id' => (int)$currentUser['id']]);
            $memberId = (int)$memberStmt->fetchColumn();
            if (!$memberId) sendResponse(['success' => false, 'message' => 'Member profile not found.'], 404);

            $bookingId = filter_var($input['booking_id'] ?? 0, FILTER_VALIDATE_INT);
            $key = strtolower(trim((string)($input['idempotency_key'] ?? '')));
            if (!$bookingId || $bookingId < 1 || !preg_match('/^[a-f0-9-]{36}$/', $key)) {
                sendResponse(['success' => false, 'message' => 'A valid booking and payment retry key are required.'], 400);
            }
            $keyHash = hash('sha256', $key);
            $duplicate = $conn->prepare(
                'SELECT id, status FROM payments WHERE idempotency_key = :key AND member_id = :member_id LIMIT 1'
            );
            $duplicate->execute([':key' => $keyHash, ':member_id' => $memberId]);
            if ($existing = $duplicate->fetch(PDO::FETCH_ASSOC)) {
                sendResponse([
                    'success' => true,
                    'message' => 'This booking payment request was already received.',
                    'payment_id' => (int)$existing['id'],
                    'status' => $existing['status'],
                ]);
            }
            $proof = $method === 'gcash' ? validatePaymentProof() : null;
            $storedPath = null;
            try {
                if ($proof) {
                    $directory = paymentProofDirectory();
                    if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
                        throw new RuntimeException('Unable to create private payment proof storage.');
                    }
                    $storedName = bin2hex(random_bytes(24)) . '.' . $proof['extension'];
                    $storedPath = $directory . DIRECTORY_SEPARATOR . $storedName;
                    if (!move_uploaded_file($proof['tmp_name'], $storedPath)) {
                        throw new RuntimeException('Unable to store payment proof.');
                    }
                }

                $conn->beginTransaction();
                $bookingStmt = $conn->prepare(
                    'SELECT id, member_id, status, amount_due, payment_deadline_at
                     FROM bookings WHERE id = :id AND member_id = :member_id FOR UPDATE'
                );
                $bookingStmt->execute([':id' => $bookingId, ':member_id' => $memberId]);
                $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
                if (!$booking) {
                    throw new RuntimeException('Booking not found for this Member.', 404);
                }
                $deadline = $booking['payment_deadline_at'] !== null
                    ? new DateTimeImmutable((string)$booking['payment_deadline_at'], new DateTimeZone('Asia/Manila'))
                    : null;
                if ($booking['status'] !== 'awaiting_payment' || !$deadline
                    || $deadline <= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
                    throw new RuntimeException('This booking is no longer accepting payment. Its original payment deadline has passed or it is not awaiting payment.', 409);
                }
                $amountCents = (int)round((float)$booking['amount_due'] * 100);
                if ($amountCents <= 0) {
                    throw new RuntimeException('This booking does not have an outstanding payment.', 409);
                }
                $pending = $conn->prepare(
                    "SELECT id, payment_method FROM payments
                     WHERE booking_id = :booking_id AND payment_type = 'training_session'
                       AND status = 'pending' AND verification_status = 'pending'
                     LIMIT 1 FOR UPDATE"
                );
                $pending->execute([':booking_id' => $bookingId]);
                if ($pending->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('A booking payment is already awaiting confirmation or review.', 409);
                }
                $insert = $conn->prepare(
                    "INSERT INTO payments
                        (member_id, booking_id, amount, payment_type, status, payment_date,
                         payment_method, payment_channel, idempotency_key,
                         proof_stored_name, proof_original_name, proof_mime_type, proof_file_size,
                         verification_status)
                     VALUES
                        (:member_id, :booking_id, :amount, 'training_session', 'pending', :payment_date,
                         :payment_method, :payment_channel, :idempotency_key,
                         :proof_name, :original_name, :mime_type, :file_size, 'pending')"
                );
                $insert->execute([
                    ':member_id' => $memberId,
                    ':booking_id' => $bookingId,
                    ':amount' => number_format($amountCents / 100, 2, '.', ''),
                    ':payment_date' => manilaToday()->format('Y-m-d'),
                    ':payment_method' => $method,
                    ':payment_channel' => $method === 'cash' ? 'counter' : 'gcash',
                    ':idempotency_key' => $keyHash,
                    ':proof_name' => $proof ? $storedName : null,
                    ':original_name' => $proof['original_name'] ?? null,
                    ':mime_type' => $proof['mime_type'] ?? null,
                    ':file_size' => $proof['file_size'] ?? null,
                ]);
                $paymentId = (int)$conn->lastInsertId();
                $conn->commit();
                sendResponse([
                    'success' => true,
                    'message' => $method === 'gcash'
                        ? 'GCash proof submitted. The booking will remain held while Admin reviews it.'
                        : 'Cash payment selected. Authorized staff must confirm receipt.',
                    'payment_id' => $paymentId,
                    'status' => 'pending',
                    'amount' => number_format($amountCents / 100, 2, '.', ''),
                ], 201);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                if ($storedPath && is_file($storedPath)) unlink($storedPath);
                if ($e instanceof RuntimeException) {
                    sendResponse(['success' => false, 'message' => $e->getMessage()], (int)$e->getCode() >= 400 ? (int)$e->getCode() : 409);
                }
                if ($e instanceof PDOException && $e->getCode() === '23000') {
                    sendResponse(['success' => false, 'message' => 'This payment request was already submitted.'], 409);
                }
                throw $e;
            }
        }

        function reviewBookingPayment(PDO $conn, array $currentUser, array $input): void {
            if (!in_array(strtolower(trim((string)($currentUser['role'] ?? ''))), ['admin', 'administrator', 'staff'], true)) {
                sendResponse(['success' => false, 'message' => 'Only authorized staff can review booking payments.'], 403);
            }
            $paymentId = filter_var($input['payment_id'] ?? 0, FILTER_VALIDATE_INT);
            $decision = strtolower(trim((string)($input['decision'] ?? '')));
            if (!$paymentId || $paymentId < 1 || !in_array($decision, ['verified', 'rejected'], true)) {
                sendResponse(['success' => false, 'message' => 'Choose a valid booking payment and review decision.'], 400);
            }
            expireUnpaidBookingPayments($conn);
            $conn->beginTransaction();
            try {
                $idStmt = $conn->prepare("SELECT booking_id FROM payments WHERE id = :id AND payment_type = 'training_session'");
                $idStmt->execute([':id' => $paymentId]);
                $bookingId = (int)$idStmt->fetchColumn();
                $bookingLock = $conn->prepare('SELECT id, status, amount_due, payment_deadline_at FROM bookings WHERE id = :id FOR UPDATE');
                $bookingLock->execute([':id' => $bookingId]);
                $booking = $bookingLock->fetch(PDO::FETCH_ASSOC);
                $stmt = $conn->prepare(
                    "SELECT id, member_id, booking_id, amount, payment_method, status,
                            verification_status, created_at, proof_stored_name
                     FROM payments WHERE id = :id AND payment_type = 'training_session' FOR UPDATE"
                );
                $stmt->execute([':id' => $paymentId]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Booking payment not found.'], 404);
                }
                if (!$booking || (int)$payment['booking_id'] !== (int)$booking['id']
                    || $payment['status'] !== 'pending' || $payment['verification_status'] !== 'pending') {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'This booking payment has already been reviewed.'], 409);
                }
                if ($booking['status'] !== 'awaiting_payment' || $booking['payment_deadline_at'] === null) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'The booking is no longer awaiting payment.'], 409);
                }
                if ((int)round((float)$payment['amount'] * 100) !== (int)round((float)$booking['amount_due'] * 100)) {
                    throw new RuntimeException('The payment amount does not match the booking amount due.', 409);
                }
                $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
                $deadline = new DateTimeImmutable((string)$booking['payment_deadline_at'], new DateTimeZone('Asia/Manila'));
                if ($payment['payment_method'] === 'cash' && $now > $deadline) {
                    $expire = $conn->prepare('UPDATE bookings SET status = \'expired\' WHERE id = :id AND status = \'awaiting_payment\'');
                    $expire->execute([':id' => (int)$payment['booking_id']]);
                    $conn->commit();
                    sendResponse(['success' => false, 'message' => 'The cash payment was not confirmed before the booking deadline.'], 409);
                }
                if ($payment['payment_method'] === 'gcash'
                    && new DateTimeImmutable((string)$payment['created_at'], new DateTimeZone('Asia/Manila')) > $deadline) {
                    throw new RuntimeException('GCash proof was submitted after the original payment deadline.', 409);
                }
                if (!in_array($payment['payment_method'], ['cash', 'gcash'], true)
                    || ($payment['payment_method'] === 'gcash' && empty($payment['proof_stored_name']))) {
                    throw new RuntimeException('The booking payment is missing a valid payment method or GCash proof.', 409);
                }
                $paid = $decision === 'verified';
                $paymentUpdate = $conn->prepare(
                    'UPDATE payments
                     SET status = :status, verification_status = :verification_status,
                         verified_by = :verified_by, verified_at = NOW(),
                         recorded_by = CASE WHEN payment_method = \'cash\' THEN :recorded_by ELSE recorded_by END,
                         payment_date = CASE WHEN payment_method = \'cash\' THEN :payment_date ELSE payment_date END
                     WHERE id = :id AND status = \'pending\' AND verification_status = \'pending\''
                );
                $paymentUpdate->execute([
                    ':status' => $paid ? 'paid' : 'failed',
                    ':verification_status' => $paid ? 'verified' : 'rejected',
                    ':verified_by' => (int)$currentUser['id'],
                    ':recorded_by' => (int)$currentUser['id'],
                    ':payment_date' => manilaToday()->format('Y-m-d'),
                    ':id' => $paymentId,
                ]);
                if ($paymentUpdate->rowCount() !== 1) {
                    throw new RuntimeException('This booking payment has already been reviewed.', 409);
                }
                if ($paid) {
                    $bookingUpdate = $conn->prepare(
                        'UPDATE bookings SET status = \'confirmed\', updated_by_user_id = :user_id WHERE id = :id AND status = \'awaiting_payment\''
                    );
                    $bookingUpdate->execute([':user_id' => (int)$currentUser['id'], ':id' => (int)$payment['booking_id']]);
                    if ($bookingUpdate->rowCount() !== 1) {
                        throw new RuntimeException('The booking could not be confirmed after payment.', 409);
                    }
                } elseif ($now >= $deadline) {
                    $expire = $conn->prepare('UPDATE bookings SET status = \'expired\' WHERE id = :id AND status = \'awaiting_payment\'');
                    $expire->execute([':id' => (int)$payment['booking_id']]);
                }
                $conn->commit();
                sendResponse([
                    'success' => true,
                    'message' => $paid ? 'Booking payment verified and booking confirmed.' : 'Booking payment rejected.',
                    'payment_status' => $paid ? 'paid' : 'failed',
                    'booking_status' => $paid ? 'confirmed' : ($now >= $deadline ? 'expired' : 'awaiting_payment'),
                ]);
            } catch (RuntimeException $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                sendResponse(['success' => false, 'message' => $e->getMessage()], (int)$e->getCode() >= 400 ? (int)$e->getCode() : 409);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                error_log('Booking payment review failed: ' . $e->getMessage());
                sendResponse(['success' => false, 'message' => 'Unable to save the booking payment review.'], 500);
            }
        }

        function activateConfirmedMonthlyPayment(PDO $conn, array $payment, int $staffId): array {
            $planStmt = $conn->prepare(
                'SELECT id, plan_code, duration_months, regular_price, student_price, senior_price, pwd_price
                 FROM membership_plans WHERE id = :id AND is_active = 1 LIMIT 1'
            );
            $planStmt->execute([':id' => (int)$payment['membership_plan_id']]);
            $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
            if (!$plan || !isVerifiedOneMonthMembershipPlan($plan)) {
                throw new RuntimeException('The payment plan is no longer available for membership activation.');
            }
            $category = strtolower(trim((string)$payment['member_category']));
            if (!in_array($category, ['regular', 'student', 'senior', 'pwd'], true)) $category = 'regular';
            try {
                $durationMonths = isset($payment['duration_months']) && $payment['duration_months'] !== null
                    ? validateMonthlyDuration((string)$payment['duration_months'])
                    : (int)$plan['duration_months'];
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException('The recorded monthly duration is invalid.');
            }
            if (round((float)$payment['amount'], 2) !== monthlyMembershipTotal($category, $durationMonths)) {
                throw new RuntimeException('The recorded payment amount does not match the selected monthly duration.');
            }
            if (!hasApprovedMemberDiscount($conn, (int)$payment['member_id'], $category)) {
                throw new RuntimeException('Discount eligibility must be verified before activating this renewal.');
            }

            $period = calculateNextPaidSubscriptionPeriod(
                $conn,
                (int)$payment['member_id'],
                $payment['membership_end'] ?? null,
                $durationMonths,
                $payment['membership_start'] ?? null
            );
            $updateMember = $conn->prepare(
                "UPDATE members
                 SET membership_type = 'monthly', membership_start = :starts_on, membership_end = :ends_on, status = 'active'
                 WHERE id = :member_id"
            );
            $updateMember->execute([
                ':starts_on' => $period['membership_start'],
                ':ends_on' => $period['ends_on'],
                ':member_id' => (int)$payment['member_id'],
            ]);
            $periodId = insertSubscriptionPeriod(
                $conn,
                (int)$payment['member_id'],
                (int)$payment['membership_plan_id'],
                null,
                (int)$payment['id'],
                $durationMonths,
                $period['starts_on'],
                $period['ends_on'],
                'manual_payment'
            );
            grantMonthlySubscriptionBookingCredits(
                $conn,
                (int)$payment['member_id'],
                (int)$payment['id'],
                $periodId,
                $period['starts_on'],
                $period['ends_on']
            );
            if ((int)($payment['user_id'] ?? 0) > 0) {
                addMemberWorkflowNotification(
                    $conn,
                    (int)$payment['user_id'],
                    'membership_renewed',
                    $period['starts_on'] > manilaToday()->format('Y-m-d') ? 'Membership renewal scheduled' : 'Membership activated',
                    'Your confirmed monthly payment covers ' . $period['starts_on'] . ' through ' . $period['ends_on'] . '.',
                    $period['ends_on']
                );
            }

            return $period;
        }

function reviewMonthlyCashPayment(PDO $conn, array $currentUser, array $input): void {
            if (!in_array(strtolower(trim((string)($currentUser['role'] ?? ''))), ['admin', 'administrator', 'staff'], true)) {
                sendResponse(['success' => false, 'message' => 'Only authorized staff can confirm cash payments.'], 403);
            }
            $paymentId = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
            if (!$paymentId || $paymentId < 1) {
                sendResponse(['success' => false, 'message' => 'Choose a valid cash renewal payment.'], 400);
            }
            $conn->beginTransaction();
            try {
                $paymentStmt = $conn->prepare(
                    "SELECT p.id, p.member_id, p.membership_plan_id, p.duration_months, p.payment_type, p.payment_method, p.amount,
                            p.registration_request_id, p.status, p.verification_status,
                            m.membership_type, m.member_category, m.membership_start, m.membership_end, m.user_id
                     FROM payments p
                     JOIN members m ON m.id = p.member_id
                     WHERE p.id = :id FOR UPDATE"
                );
                $paymentStmt->execute([':id' => $paymentId]);
                $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Payment not found.'], 404);
                }
                if ($payment['payment_type'] !== 'monthly_subscription'
                    || $payment['payment_method'] !== 'cash'
                    || !empty($payment['registration_request_id'])
                    || $payment['status'] !== 'pending'
                    || $payment['verification_status'] !== 'pending'
                    || !in_array(membershipTypeKey($payment['membership_type']), ['monthly', 'monthlysubscription'], true)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Only pending cash member-renewal payments can be confirmed here.'], 409);
                }
                $updatePayment = $conn->prepare(
                    "UPDATE payments
                     SET status = 'paid', verification_status = 'not_required',
                         recorded_by = :recorded_by, verified_by = :verified_by, verified_at = NOW(),
                         payment_date = :payment_date
                     WHERE id = :id AND status = 'pending' AND verification_status = 'pending'"
                );
                $updatePayment->execute([
                    ':recorded_by' => (int)$currentUser['id'],
                    ':verified_by' => (int)$currentUser['id'],
                    ':payment_date' => manilaToday()->format('Y-m-d'),
                    ':id' => $paymentId,
                ]);
                if ($updatePayment->rowCount() !== 1) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'This cash payment has already been confirmed.'], 409);
                }
                $period = activateConfirmedMonthlyPayment($conn, $payment, (int)$currentUser['id']);
                $conn->commit();
                sendResponse([
                    'success' => true,
                    'message' => 'Cash receipt confirmed; monthly membership activated or scheduled.',
                    'status' => 'paid',
                    'scheduled_start_date' => $period['starts_on'],
                    'scheduled_end_date' => $period['ends_on'],
                ]);
            } catch (RuntimeException $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                sendResponse(['success' => false, 'message' => $e->getMessage()], 409);
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                error_log('Monthly cash payment confirmation failed: ' . $e->getMessage());
                sendResponse(['success' => false, 'message' => 'Unable to confirm cash receipt. No membership change was applied.'], 500);
            }
        }

function reviewMonthlyGcashPayment(PDO $conn, array $currentUser, array $input): void {
    if (!in_array(strtolower(trim((string)($currentUser['role'] ?? ''))), ['admin', 'administrator', 'staff'], true)) {
        sendResponse(['success' => false, 'message' => 'Only authorized staff can verify GCash payments.'], 403);
    }
    $paymentId = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
    $decision = strtolower(trim((string)($input['decision'] ?? '')));
    if (!$paymentId || $paymentId < 1 || !in_array($decision, ['verified', 'rejected'], true)) {
        sendResponse(['success' => false, 'message' => 'Choose a valid payment and review decision.'], 400);
    }
    $conn->beginTransaction();
    try {
        $paymentStmt = $conn->prepare(
            'SELECT p.id, p.member_id, p.membership_plan_id, p.duration_months, p.payment_type, p.payment_method, p.amount,
                    p.registration_request_id, p.status, p.verification_status,
                    m.membership_type, m.member_category, m.membership_start, m.membership_end, m.user_id
             FROM payments p
             JOIN members m ON m.id = p.member_id
             WHERE p.id = :id FOR UPDATE'
        );
        $paymentStmt->execute([':id' => $paymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Payment not found.'], 404);
        }
        if ($payment['payment_type'] !== 'monthly_subscription'
            || $payment['payment_method'] !== 'gcash'
            || !empty($payment['registration_request_id'])
            || $payment['status'] !== 'pending'
            || $payment['verification_status'] !== 'pending'
            || !in_array(membershipTypeKey($payment['membership_type']), ['monthly', 'monthlysubscription'], true)) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Only pending GCash member-renewal payments can be reviewed here.'], 409);
        }
        $category = strtolower(trim((string)$payment['member_category']));
        if (!in_array($category, ['regular', 'student', 'senior', 'pwd'], true)) $category = 'regular';
        if ($decision === 'verified' && !hasApprovedMemberDiscount($conn, (int)$payment['member_id'], $category)) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Discount eligibility must be verified before activating this renewal.'], 409);
        }

        $nextPaymentStatus = $decision === 'verified' ? 'paid' : 'failed';
        $updatePayment = $conn->prepare(
            'UPDATE payments
             SET status = :status, verification_status = :verification_status,
                 verified_by = :verified_by, verified_at = NOW()
             WHERE id = :id AND status = \'pending\' AND verification_status = \'pending\''
        );
        $updatePayment->execute([
            ':status' => $nextPaymentStatus,
            ':verification_status' => $decision,
            ':verified_by' => (int)$currentUser['id'],
            ':id' => $paymentId,
        ]);
        if ($updatePayment->rowCount() !== 1) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'This payment has already been reviewed.'], 409);
        }

        $period = $decision === 'verified'
            ? activateConfirmedMonthlyPayment($conn, $payment, (int)$currentUser['id'])
            : null;
        $conn->commit();
        $message = $decision === 'rejected'
            ? 'GCash payment rejected.'
            : ($period['starts_on'] > manilaToday()->format('Y-m-d')
                ? 'GCash payment verified and membership renewal scheduled.'
                : 'GCash payment verified and membership activated.');
        sendResponse([
            'success' => true,
            'message' => $message,
            'status' => $nextPaymentStatus,
            'scheduled_start_date' => $period['starts_on'] ?? null,
            'scheduled_end_date' => $period['ends_on'] ?? null,
        ]);
    } catch (RuntimeException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        sendResponse(['success' => false, 'message' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('Monthly GCash payment review failed: ' . $e->getMessage());
        sendResponse(['success' => false, 'message' => 'Unable to save the payment review. No membership change was applied.'], 500);
    }
}

try {
    if (!defined('PAYMENTS_API_TESTING') || PAYMENTS_API_TESTING !== true) {
        $currentUser = requireAuth($conn);
    }
    $role = strtolower(trim((string)($currentUser['role'] ?? '')));
    $input = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?? []);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (strtolower(trim((string)($_GET['view'] ?? ''))) === 'booking_refunds') {
            if (!isRefundAdmin($currentUser)) {
                sendResponse(['success' => false, 'message' => 'Only Admins can view booking refund requests.'], 403);
            }
            $refundStmt = $conn->query(
                'SELECT br.id, br.booking_id, br.payment_id, br.member_id, br.amount,
                        br.payment_method, br.status, br.request_reason, br.created_at,
                        br.approved_at, br.processed_at, br.external_reference,
                        b.booking_code, b.booking_date, b.cancellation_reason,
                        p.status AS payment_status, p.payment_type, p.amount AS original_amount,
                        p.payment_method AS original_payment_method,
                        m.full_name AS member_name, m.member_code,
                        approver.full_name AS approved_by_name, processor.full_name AS processed_by_name
                 FROM booking_refunds br
                 JOIN bookings b ON b.id = br.booking_id
                 JOIN payments p ON p.id = br.payment_id
                 JOIN members m ON m.id = br.member_id
                 LEFT JOIN users approver ON approver.id = br.approved_by_user_id
                 LEFT JOIN users processor ON processor.id = br.processed_by_user_id
                 ORDER BY FIELD(br.status, "pending", "approved", "processed", "rejected"), br.created_at ASC, br.id ASC'
            );
            sendResponse(['success' => true, 'refunds' => $refundStmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
        expireUnpaidBookingPayments($conn);
        $paymentId = intval($_GET['id'] ?? 0);
        $where = [];
        $params = [];

        if ($role === 'member') {
            $memberStmt = $conn->prepare('SELECT id FROM members WHERE user_id = :user_id LIMIT 1');
            $memberStmt->execute([':user_id' => (int)$currentUser['id']]);
            $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) sendResponse(['success' => false, 'message' => 'Member profile not found.'], 404);
            $where[] = 'p.member_id = :member_id';
            $params[':member_id'] = $member['id'];
            try {
                $requestedDurationMonths = validateMonthlyDuration($_GET['duration_months'] ?? 1);
            } catch (InvalidArgumentException $e) {
                sendResponse(['success' => false, 'message' => $e->getMessage()], 400);
            }
        } elseif (!in_array($role, ['admin', 'administrator', 'staff'], true)) {
            sendResponse(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        if ($paymentId > 0) {
            $where[] = 'p.id = :id';
            $params[':id'] = $paymentId;
        }
        if (in_array($role, ['admin', 'administrator', 'staff'], true)) {
            $search = trim((string)($_GET['search'] ?? ''));
            $status = normalizePaymentStatus($_GET['status'] ?? '');
            $paymentType = normalizePaymentFilterType($_GET['payment_type'] ?? '');
            if ($search !== '') {
                $where[] = '(m.full_name LIKE :search OR m.first_name LIKE :search OR m.middle_name LIKE :search OR m.last_name LIKE :search OR m.member_code LIKE :search OR m.email LIKE :search OR p.reference_no LIKE :search)';
                $params[':search'] = '%' . $search . '%';
            }
            if ($status) {
                $where[] = 'p.status = :status';
                $params[':status'] = $status;
            }
            if ($paymentType) {
                $where[] = 'p.payment_type = :payment_type';
                $params[':payment_type'] = $paymentType;
            }
        }

        $dateRange = trim((string)($_GET['date_range'] ?? ''));
        $dateRangeStart = null;
        $dateRangeEnd = null;
        if ($dateRange !== '') {
            if (!preg_match('/^\d{4}-\d{2}$/', $dateRange)) {
                sendResponse(['success' => false, 'message' => 'Date range must use YYYY-MM format.'], 400);
            }
            try {
                $rangeStart = DateTimeImmutable::createFromFormat('!Y-m', $dateRange, new DateTimeZone('Asia/Manila'));
                if (!$rangeStart || $rangeStart->format('Y-m') !== $dateRange) {
                    sendResponse(['success' => false, 'message' => 'Date range is invalid.'], 400);
                }
                $dateRangeStart = $rangeStart->format('Y-m-d');
                $dateRangeEnd = $rangeStart->modify('+1 month')->format('Y-m-d');
                $where[] = 'p.payment_date >= :range_start AND p.payment_date < :range_end';
                $params[':range_start'] = $dateRangeStart;
                $params[':range_end'] = $dateRangeEnd;
            } catch (Exception $e) {
                sendResponse(['success' => false, 'message' => 'Date range is invalid.'], 400);
            }
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $conn->prepare(paymentSelectSql() . $whereSql . ' ORDER BY p.payment_date DESC, p.id DESC');
        $stmt->execute($params);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dailyPassWhere = [];
        $dailyPassParams = [];
        if ($role === 'member') {
            $dailyPassWhere[] = 'dp.member_id = :member_id';
            $dailyPassParams[':member_id'] = (int)$member['id'];
        } elseif ($paymentType && $paymentType !== 'daily_pass') {
            $dailyPassWhere[] = '1 = 0';
        }
        if (in_array($role, ['admin', 'administrator', 'staff'], true)) {
            if ($search !== '') {
                $dailyPassWhere[] = '(COALESCE(m.full_name, dp.customer_name) LIKE :search
                    OR m.member_code LIKE :search
                    OR dp.reference_no LIKE :search
                    OR CAST(dp.id AS CHAR) LIKE :search)';
                $dailyPassParams[':search'] = '%' . $search . '%';
            }
            if ($status === 'paid') {
                $dailyPassWhere[] = "dp.payment_status = 'paid'";
            } elseif ($status === 'pending') {
                $dailyPassWhere[] = "dp.payment_status IN ('pending', 'unpaid')";
            } elseif ($status === 'failed') {
                $dailyPassWhere[] = "dp.payment_status = 'rejected'";
            }
            if ($paymentType === 'daily_pass') {
                $dailyPassWhere[] = '1 = 1';
            }
        }
        if ($dateRangeStart !== null) {
            $dailyPassWhere[] = 'COALESCE(DATE(dp.verified_at), DATE(dp.created_at)) >= :range_start
                AND COALESCE(DATE(dp.verified_at), DATE(dp.created_at)) < :range_end';
            $dailyPassParams[':range_start'] = $dateRangeStart;
            $dailyPassParams[':range_end'] = $dateRangeEnd;
        }
        $dailyPassWhereSql = $dailyPassWhere ? ' WHERE ' . implode(' AND ', $dailyPassWhere) : '';
        $dailyPassStmt = $conn->prepare(
            'SELECT dp.id, dp.member_id, dp.customer_name, dp.visit_date, dp.expires_at, dp.checked_in_at,
                    dp.payment_method, dp.amount, dp.payment_status, dp.reference_no,
                    dp.transaction_source, dp.recorded_by, dp.verified_by, dp.verified_at,
                    dp.created_at, dp.updated_at,
                    m.member_code, m.full_name AS member_name, m.email AS member_email,
                    recorder.full_name AS recorded_by_name, verifier.full_name AS verified_by_name
             FROM daily_pass_transactions dp
             LEFT JOIN members m ON m.id = dp.member_id
             LEFT JOIN users recorder ON recorder.id = dp.recorded_by
             LEFT JOIN users verifier ON verifier.id = dp.verified_by'
            . $dailyPassWhereSql
            . ' ORDER BY dp.created_at DESC, dp.id DESC'
        );
        $dailyPassStmt->execute($dailyPassParams);
        $dailyPasses = $dailyPassStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dailyPasses as $dailyPass) {
            $payments[] = normalizeDailyPassPayment($dailyPass);
        }
        foreach ($payments as &$payment) {
            if (($payment['record_type'] ?? '') === 'daily_pass'
                || ($payment['payment_type'] ?? '') !== 'monthly_subscription'
                || ($payment['status'] ?? '') !== 'pending'
                || !empty($payment['registration_request_id'])
                || !empty($payment['scheduled_start_date'])) {
                continue;
            }
            $period = calculateNextPaidSubscriptionPeriod(
                $conn,
                (int)$payment['member_id'],
                $payment['membership_end'] ?? null,
                max(1, (int)($payment['duration_months'] ?? 1)),
                $payment['membership_start'] ?? null
            );
            $payment['scheduled_start_date'] = $period['starts_on'];
            $payment['scheduled_end_date'] = $period['ends_on'];
        }
        unset($payment);
        usort($payments, static function (array $left, array $right) use ($role): int {
            $leftDate = $role === 'member' && ($left['record_type'] ?? '') === 'daily_pass'
                ? ($left['purchase_date'] ?? $left['payment_date'] ?? '')
                : ($left['payment_date'] ?? '');
            $rightDate = $role === 'member' && ($right['record_type'] ?? '') === 'daily_pass'
                ? ($right['purchase_date'] ?? $right['payment_date'] ?? '')
                : ($right['payment_date'] ?? '');
            $dateCompare = strcmp((string)$rightDate, (string)$leftDate);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return (int)($right['record_id'] ?? $right['id']) <=> (int)($left['record_id'] ?? $left['id']);
        });

        if ($paymentId > 0) {
            $monthlyPayments = array_values(array_filter($payments, static fn(array $payment): bool => ($payment['record_type'] ?? 'monthly') !== 'daily_pass'));
            if (!$monthlyPayments) sendResponse(['success' => false, 'message' => 'Payment not found.'], 404);
            sendResponse(['success' => true, 'payment' => $monthlyPayments[0]]);
        }

        $summary = ['total_paid' => 0, 'pending_amount' => 0, 'pending_count' => 0];
        $monthlySummaryWhere = $role === 'member' ? ' WHERE p.member_id = :member_id' : '';
        $monthlySummaryParams = $role === 'member' ? [':member_id' => (int)$member['id']] : [];
        if ($dateRangeStart !== null) {
            $monthlySummaryWhere .= $monthlySummaryWhere === '' ? ' WHERE ' : ' AND ';
            $monthlySummaryWhere .= 'p.payment_date >= :range_start AND p.payment_date < :range_end';
            $monthlySummaryParams[':range_start'] = $dateRangeStart;
            $monthlySummaryParams[':range_end'] = $dateRangeEnd;
        }
        $summaryStmt = $conn->prepare("SELECT
            COALESCE(SUM(CASE WHEN p.status = 'paid'
                THEN p.amount - CASE WHEN p.payment_type = 'training_session' THEN COALESCE(br.amount, 0) ELSE 0 END
                ELSE 0 END), 0) AS total_paid,
            COALESCE(SUM(CASE WHEN p.status = 'pending' THEN p.amount ELSE 0 END), 0) AS pending_amount,
            SUM(CASE WHEN p.status = 'pending' THEN 1 ELSE 0 END) AS pending_count
            FROM payments p LEFT JOIN booking_refunds br
                ON br.payment_id = p.id AND br.status = 'processed'" . $monthlySummaryWhere);
        $summaryStmt->execute($monthlySummaryParams);
        $summary = array_merge($summary, $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: []);
        $summary['total_paid'] = (float)$summary['total_paid'];
        $summary['pending_amount'] = (float)$summary['pending_amount'];
        $summary['pending_count'] = (int)$summary['pending_count'];

        $dailySummaryWhere = [];
        $dailySummaryParams = [];
        if ($role === 'member') {
            $dailySummaryWhere[] = 'member_id = :member_id';
            $dailySummaryParams[':member_id'] = (int)$member['id'];
        }
        if ($dateRangeStart !== null) {
            $dailySummaryWhere[] = 'COALESCE(DATE(verified_at), DATE(created_at)) >= :range_start
                AND COALESCE(DATE(verified_at), DATE(created_at)) < :range_end';
            $dailySummaryParams[':range_start'] = $dateRangeStart;
            $dailySummaryParams[':range_end'] = $dateRangeEnd;
        }
        $dailySummaryWhereSql = $dailySummaryWhere ? ' WHERE ' . implode(' AND ', $dailySummaryWhere) : '';
        $dailySummaryStmt = $conn->prepare(
            "SELECT payment_status, amount
             FROM daily_pass_transactions" . $dailySummaryWhereSql
        );
        $dailySummaryStmt->execute($dailySummaryParams);
        $dailySummary = summarizeDailyPassPayments($dailySummaryStmt->fetchAll(PDO::FETCH_ASSOC));
        $summary['total_paid'] += $dailySummary['total_paid'];
        $summary['pending_amount'] += $dailySummary['pending_amount'];
        $summary['pending_count'] += $dailySummary['pending_count'];

        $response = ['success' => true, 'payments' => $payments, 'summary' => $summary];
        if ($role === 'member') {
            $memberStmt = $conn->prepare('SELECT membership_type, member_category, membership_start, membership_end, status FROM members WHERE id = :id LIMIT 1');
            $memberStmt->execute([':id' => $params[':member_id']]);
            $response['membership'] = $memberStmt->fetch(PDO::FETCH_ASSOC);
            $nextPeriod = calculateNextPaidSubscriptionPeriod(
                $conn,
                (int)$member['id'],
                $response['membership']['membership_end'] ?? null,
                $requestedDurationMonths,
                $response['membership']['membership_start'] ?? null
            );
            $response['membership']['next_period'] = [
                'starts_on' => $nextPeriod['starts_on'],
                'ends_on' => $nextPeriod['ends_on'],
                'duration_months' => $requestedDurationMonths,
            ];
            $latestPass = $dailyPasses[0] ?? null;
            $response['membership']['latest_daily_pass'] = $latestPass
                ? normalizeDailyPassPayment($latestPass)
                : null;
        } else {
            $dueStmt = $conn->prepare("SELECT id, member_code, full_name, email, membership_start, membership_end, status
                                       FROM members
                                       WHERE membership_type = 'monthly'
                                         AND (membership_end IS NULL OR membership_end < :today OR status IN ('expired', 'inactive'))
                                       ORDER BY full_name ASC");
            $dueStmt->execute([':today' => manilaToday()->format('Y-m-d')]);
            $response['due_members'] = $dueStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        sendResponse($response);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = strtolower(trim((string)($input['action'] ?? '')));
        if ($action === 'submit_monthly_gcash') {
            sendResponse([
                'success' => false,
                'message' => 'Submit a new monthly request from your Member Dashboard so its category and eligibility can be reviewed.',
            ], 409);
        }
        if ($action === 'submit_monthly_cash') {
            sendResponse([
                'success' => false,
                'message' => 'Submit a new monthly request from your Member Dashboard so its category and eligibility can be reviewed.',
            ], 409);
        }
        if ($action === 'review_monthly_gcash') {
            reviewMonthlyGcashPayment($conn, $currentUser, $input);
        }
        if ($action === 'review_monthly_cash') {
            reviewMonthlyCashPayment($conn, $currentUser, $input);
        }
        if ($action === 'submit_booking_cash') {
            submitBookingPayment($conn, $currentUser, $input, 'cash');
        }
        if ($action === 'submit_booking_gcash') {
            submitBookingPayment($conn, $currentUser, $input, 'gcash');
        }
        if ($action === 'review_booking_payment') {
            reviewBookingPayment($conn, $currentUser, $input);
        }
        if ($action === 'approve_booking_refund') {
            approveBookingRefund($conn, $currentUser, $input);
        }
        if ($action === 'process_booking_refund') {
            processBookingRefund($conn, $currentUser, $input);
        }
        if (!in_array($role, ['admin', 'administrator', 'staff'], true)) {
            sendResponse(['success' => false, 'message' => 'Only authorized staff can record cash payments.'], 403);
        }
        if (strtolower(trim((string)($input['payment_method'] ?? 'cash'))) !== 'cash') {
            sendResponse(['success' => false, 'message' => 'GCash payments must use the proof submission and admin verification workflow.'], 400);
        }

        $memberId = intval($input['member_id'] ?? 0);
        $bookingId = intval($input['booking_id'] ?? 0) ?: null;
        $amount = (float)($input['amount'] ?? 0);
        $paymentType = normalizePaymentType($input['payment_type'] ?? '');
        $status = normalizePaymentStatus($input['status'] ?? 'paid');
        $paymentDate = trim((string)($input['payment_date'] ?? manilaToday()->format('Y-m-d')));
        $referenceNo = trim((string)($input['reference_no'] ?? '')) ?: null;
        $membershipPlanId = null;
        $durationMonths = null;

        if (!$memberId || !$paymentType || $paymentType === 'walk_in_fee' || !$paymentDate) {
            sendResponse(['success' => false, 'message' => 'Member, payment type, and payment date are required. Daily passes use the separate pass transaction flow.'], 400);
        }
        if ($paymentType === 'training_session') {
            sendResponse([
                'success' => false,
                'message' => 'Training-session payments must use the booking approval, payment request, and verification workflow.',
            ], 409);
        }
        if ($status !== 'paid') {
            sendResponse(['success' => false, 'message' => 'Cash receipt must be confirmed before a payment can be recorded.'], 400);
        }
        $parsedPaymentDate = DateTime::createFromFormat('!Y-m-d', $paymentDate, new DateTimeZone('Asia/Manila'));
        if (!$parsedPaymentDate || $parsedPaymentDate->format('Y-m-d') !== $paymentDate) {
            sendResponse(['success' => false, 'message' => 'Payment date must use YYYY-MM-DD format.'], 400);
        }
        if ($parsedPaymentDate > manilaToday()) {
            sendResponse(['success' => false, 'message' => 'Payment date cannot be in the future.'], 400);
        }
        if ($referenceNo !== null && mb_strlen($referenceNo) > 100) {
            sendResponse(['success' => false, 'message' => 'Reference number cannot exceed 100 characters.'], 400);
        }

        try {
            $conn->beginTransaction();
            $memberStmt = $conn->prepare('SELECT id, membership_type, member_category, membership_start, membership_end, status FROM members WHERE id = :id FOR UPDATE');
            $memberStmt->execute([':id' => $memberId]);
            $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'Member not found.'], 404);
            }

            if ($paymentType === 'monthly_subscription') {
                $conn->rollBack();
                sendResponse([
                    'success' => false,
                    'message' => 'Create and review a monthly membership request before recording payment.',
                ], 409);
            } elseif ($amount <= 0) {
                $conn->rollBack();
                sendResponse(['success' => false, 'message' => 'Amount must be greater than zero.'], 400);
            }

            if ($bookingId) {
                $bookingStmt = $conn->prepare('SELECT id FROM bookings WHERE id = :id AND member_id = :member_id LIMIT 1');
                $bookingStmt->execute([':id' => $bookingId, ':member_id' => $memberId]);
                if (!$bookingStmt->fetch()) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Booking does not belong to the selected member.'], 400);
                }
            }

            if ($referenceNo) {
                $duplicateStmt = $conn->prepare(
                    'SELECT id FROM payments WHERE reference_no = :reference1
                     UNION SELECT id FROM daily_pass_transactions WHERE reference_no = :reference2
                     LIMIT 1'
                );
                $duplicateStmt->execute([
                    ':reference1' => $referenceNo,
                    ':reference2' => $referenceNo,
                ]);
                if ($duplicateStmt->fetch()) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Reference number has already been recorded.'], 409);
                }
            }

            $idempotencyHash = null;
            $idempotencyKey = strtolower(trim((string)($input['idempotency_key'] ?? '')));
            if ($paymentType === 'monthly_subscription') {
                if (!preg_match('/^[a-f0-9-]{36}$/', $idempotencyKey)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'A valid payment retry key is required. Reopen the payment form and try again.'], 400);
                }
                $idempotencyHash = hash('sha256', $idempotencyKey);
                $existingPaymentStmt = $conn->prepare('SELECT id FROM payments WHERE idempotency_key = :key LIMIT 1 FOR UPDATE');
                $existingPaymentStmt->execute([':key' => $idempotencyHash]);
                if ($existingPaymentStmt->fetchColumn()) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'This cash payment request was already recorded.'], 409);
                }
            }

            $insert = $conn->prepare(
                "INSERT INTO payments
                    (member_id, booking_id, recorded_by, membership_plan_id, duration_months, amount,
                     payment_type, status, payment_date, reference_no, payment_method, payment_channel, idempotency_key,
                     verification_status, verified_by, verified_at)
                 VALUES
                    (:member_id, :booking_id, :recorded_by, :plan_id, :duration_months, :amount,
                     :payment_type, 'paid', :payment_date, :reference_no, 'cash', 'counter', :idempotency_key,
                     'not_required', :verified_by, NOW())"
            );
            $insert->execute([
                ':member_id' => $memberId,
                ':booking_id' => $bookingId,
                ':recorded_by' => $currentUser['id'],
                ':plan_id' => $membershipPlanId,
                ':duration_months' => $durationMonths,
                ':amount' => number_format($amount, 2, '.', ''),
                ':payment_type' => $paymentType,
                ':payment_date' => $paymentDate,
                ':reference_no' => $referenceNo,
                ':idempotency_key' => $idempotencyHash,
                ':verified_by' => (int)$currentUser['id'],
            ]);
            $paymentId = (int)$conn->lastInsertId();

            if ($paymentType === 'monthly_subscription' && $status === 'paid') {
                $period = calculateNextPaidSubscriptionPeriod(
                    $conn,
                    $memberId,
                    $member['membership_end'] ?? null,
                    $durationMonths,
                    $member['membership_start'] ?? null
                );
                $update = $conn->prepare("UPDATE members SET membership_type = 'monthly', membership_start = :membership_start, membership_end = :membership_end, status = 'active' WHERE id = :id");
                $update->execute([':membership_start' => $period['membership_start'], ':membership_end' => $period['ends_on'], ':id' => $memberId]);
                insertSubscriptionPeriod(
                    $conn,
                    $memberId,
                    $membershipPlanId,
                    null,
                    $paymentId,
                    $durationMonths,
                    $period['starts_on'],
                    $period['ends_on'],
                    'manual_payment'
                );
                $userStmt = $conn->prepare('SELECT user_id FROM members WHERE id = :id LIMIT 1');
                $userStmt->execute([':id' => $memberId]);
                $memberUserId = (int)$userStmt->fetchColumn();
                if ($memberUserId > 0) {
                    addMemberWorkflowNotification(
                        $conn,
                        $memberUserId,
                        'membership_renewed',
                        'Membership renewed',
                        'Your membership has been renewed until ' . $period['ends_on'] . '.',
                        $period['ends_on']
                    );
                }
            }

            $conn->commit();
            sendResponse(['success' => true, 'message' => 'Payment recorded successfully.', 'payment_id' => $paymentId]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') sendResponse(['success' => false, 'message' => 'Reference number has already been recorded.'], 409);
            error_log('Cash payment recording failed: ' . $e->getMessage());
            sendResponse(['success' => false, 'message' => 'Unable to record payment.'], 500);
        }
    }

    sendResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
} catch (PDOException $e) {
    error_log('Payments database operation failed: ' . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'Server error. Please try again later.'], 500);
} catch (Throwable $e) {
    error_log('Payments operation failed: ' . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'Server error. Please try again later.'], 500);
}
