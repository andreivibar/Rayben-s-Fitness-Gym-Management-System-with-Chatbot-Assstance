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

if (defined('MEMBER_PROFILE_TESTING') && MEMBER_PROFILE_TESTING === true) {
    $conn = $GLOBALS['member_profile_test_connection'];
    $currentUser = $GLOBALS['member_profile_test_user'];
} else {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
}
require_once __DIR__ . '/../config/membership_workflow.php';
require_once __DIR__ . '/../config/membership_pricing.php';
require_once __DIR__ . '/../config/member_gender.php';

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    if (!defined('MEMBER_PROFILE_TESTING') || MEMBER_PROFILE_TESTING !== true) {
        $currentUser = requireRole($conn, ['member', 'admin', 'administrator']);
    }
    $rawInput = $GLOBALS['__php_input_cache'] ?? @file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];
    
    $action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? '')));
    $isUpdateRequest = ($_SERVER['REQUEST_METHOD'] === 'PUT') || ($action === 'update');

    $userRole = strtolower(trim((string)($currentUser['role'] ?? '')));
    $isAdmin = in_array($userRole, ['admin', 'administrator'], true);

    $email = trim((string)($currentUser['email'] ?? ''));
    if ($isAdmin) {
        $email = isset($input['email']) ? trim((string)$input['email']) : '';
        if (!$email && isset($_SERVER['HTTP_X_USER_EMAIL'])) {
            $email = trim((string)$_SERVER['HTTP_X_USER_EMAIL']);
        }
        if (!$email) {
            sendResponse(['success' => false, 'message' => 'Email is required.'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendResponse(['success' => false, 'message' => 'Invalid email address.'], 400);
        }

        $userStmt = $conn->prepare('SELECT id, full_name, email FROM users WHERE email = :email LIMIT 1');
        $userStmt->bindParam(':email', $email, PDO::PARAM_STR);
        $userStmt->execute();
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            sendResponse(['success' => false, 'message' => 'Authenticated user account not found.'], 401);
        }
    } else {
        $user = $currentUser;
    }

    // 1. Try finding member by user_id
    $stmt = $conn->prepare('SELECT id, user_id, member_code, first_name, middle_name, last_name, full_name, email, phone, address, date_of_birth, gender, membership_type, member_category, gym_access_status, membership_start, membership_end, status, profile_picture, created_at FROM members WHERE user_id = :user_id LIMIT 1');
    $stmt->bindParam(':user_id', $user['id'], PDO::PARAM_INT);
    $stmt->execute();
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        sendResponse(['success' => false, 'message' => 'Member profile not found for this account.'], 404);
    }
    $currentEmail = trim((string)($user['email'] ?? ''));
    $member['email'] = $currentEmail !== '' ? $currentEmail : null;

    // Handle Profile Update
    if ($isUpdateRequest) {
        // Enforce ownership: only the member themselves or an admin can update
        if (!$isAdmin && (int)$member['user_id'] !== (int)$currentUser['id']) {
            sendResponse(['success' => false, 'message' => 'Forbidden. You are not allowed to update this profile.'], 403);
        }

        $firstName = trim((string)($input['first_name'] ?? ''));
        $middleName = trim((string)($input['middle_name'] ?? ''));
        $lastName = trim((string)($input['last_name'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $address = trim((string)($input['address'] ?? ''));
        $dob = trim((string)($input['date_of_birth'] ?? ''));
        try {
            $gender = normalizeOptionalMemberGender($input['gender'] ?? null);
        } catch (InvalidArgumentException $e) {
            sendResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }

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

        if (mb_strlen($address) > 255) {
            sendResponse(['success' => false, 'message' => 'Address cannot exceed 255 characters.'], 400);
        }

        if ($dob !== '') {
            $dobTimestamp = strtotime($dob);
            if ($dobTimestamp === false) {
                sendResponse(['success' => false, 'message' => 'Invalid date of birth format.'], 400);
            }
            $todayDate = date('Y-m-d');
            if ($dob > $todayDate) {
                sendResponse(['success' => false, 'message' => 'Date of birth cannot be a future date.'], 400);
            }
        }

        // Perform safe atomic update on both members and users
        $conn->beginTransaction();
        try {
            $middleParam = $middleName !== '' ? $middleName : null;
            $dobParam = $dob !== '' ? $dob : null;

            $updateMemberStmt = $conn->prepare(
                'UPDATE members SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, full_name = :full_name, phone = :phone, address = :address, date_of_birth = :date_of_birth, gender = :gender WHERE id = :id'
            );
            $updateMemberStmt->execute([
                ':first_name' => $firstName,
                ':middle_name' => $middleParam,
                ':last_name' => $lastName,
                ':full_name' => $fullName,
                ':phone' => $phone,
                ':address' => $address,
                ':date_of_birth' => $dobParam,
                ':gender' => $gender,
                ':id' => $member['id']
            ]);

            // Sync user record
            $updateUserStmt = $conn->prepare('UPDATE users SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, full_name = :full_name WHERE id = :user_id');
            $updateUserStmt->execute([
                ':first_name' => $firstName,
                ':middle_name' => $middleParam,
                ':last_name' => $lastName,
                ':full_name' => $fullName,
                ':user_id' => $user['id']
            ]);

            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollBack();
            sendResponse(['success' => false, 'message' => 'Failed to update profile. Please try again.'], 500);
        }

        // Re-fetch updated member data
        $stmt->execute();
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        $member['email'] = $currentEmail !== '' ? $currentEmail : null;
    }

    // Normalize dates: convert '0000-00-00' to empty strings
    $member['membership_start'] = ($member['membership_start'] === '0000-00-00' ? '' : ($member['membership_start'] ?? ''));
    $member['membership_end'] = ($member['membership_end'] === '0000-00-00' ? '' : ($member['membership_end'] ?? ''));
    $member['date_of_birth'] = ($member['date_of_birth'] === '0000-00-00' ? '' : ($member['date_of_birth'] ?? ''));

    // Normalize status
    $member['status'] = trim((string)$member['status']);

    $periodStmt = $conn->prepare(
        "SELECT sp.id, sp.starts_on, sp.ends_on, sp.duration_months,
                CASE
                    WHEN sp.status = 'superseded' THEN 'superseded'
                    WHEN sp.ends_on < :today_end THEN 'expired'
                    WHEN sp.starts_on > :today_start THEN 'scheduled'
                    ELSE 'active'
                END AS status,
                sp.source,
                mp.plan_name, p.amount AS payment_amount, p.payment_method,
                p.status AS payment_status, p.verification_status, p.verified_at,
                rr.discount_status
         FROM membership_subscription_periods sp
         LEFT JOIN membership_plans mp ON mp.id = sp.membership_plan_id
         LEFT JOIN payments p ON p.id = sp.payment_id
         LEFT JOIN registration_requests rr ON rr.id = sp.registration_request_id
         WHERE sp.member_id = :member_id
         ORDER BY sp.starts_on DESC, sp.id DESC"
    );
    $periodStmt->execute([
        ':today_end' => manilaToday()->format('Y-m-d'),
        ':today_start' => manilaToday()->format('Y-m-d'),
        ':member_id' => (int)$member['id'],
    ]);
    $member['subscription_periods'] = $periodStmt->fetchAll(PDO::FETCH_ASSOC);
    $member['pending_monthly_renewal'] = null;
    if (in_array(preg_replace('/[^a-z0-9]+/', '', strtolower((string)$member['membership_type'])), ['monthly', 'monthlysubscription'], true)) {
        $pendingRenewalStmt = $conn->prepare(
            "SELECT id, amount, payment_method, status, verification_status, payment_date, duration_months
             FROM payments
             WHERE member_id = :member_id AND payment_type = 'monthly_subscription'
               AND status = 'pending' AND registration_request_id IS NULL
             ORDER BY created_at DESC, id DESC
             LIMIT 1"
        );
        $pendingRenewalStmt->execute([':member_id' => (int)$member['id']]);
        $pendingRenewal = $pendingRenewalStmt->fetch(PDO::FETCH_ASSOC);
        if ($pendingRenewal) {
            $period = calculateNextPaidSubscriptionPeriod(
                $conn,
                (int)$member['id'],
                $member['membership_end'] ?? null,
                max(1, (int)($pendingRenewal['duration_months'] ?? 1)),
                $member['membership_start'] ?? null
            );
            $pendingRenewal['scheduled_start_date'] = $period['starts_on'];
            $pendingRenewal['scheduled_end_date'] = $period['ends_on'];
            $member['pending_monthly_renewal'] = $pendingRenewal;
        }
    }

    $requestStmt = $conn->prepare(
        "SELECT id, member_category, payment_method, payment_status, discount_status,
                payment_amount, status, created_at
         FROM registration_requests
         WHERE member_id = :member_id AND membership_type = 'monthly'
         ORDER BY id DESC
         LIMIT 1"
    );
    $requestStmt->execute([':member_id' => (int)$member['id']]);
    $latestMembershipRequest = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if ($latestMembershipRequest) {
        $latestMembershipRequest['requested_duration_months'] = monthlyDurationFromAmount(
            $latestMembershipRequest['payment_amount'] ?? null,
            (string)($latestMembershipRequest['member_category'] ?? 'regular')
        );
    }
    $member['latest_membership_request'] = $latestMembershipRequest ?: null;

    $passStmt = $conn->prepare(
        'SELECT id, customer_name, pass_code, visit_date, expires_at, payment_method, amount, payment_status,
                reference_no, transaction_source, verified_by, verified_at, recorded_by, created_at, checked_in_at
         FROM daily_pass_transactions
         WHERE member_id = :member_id
         ORDER BY created_at DESC, id DESC'
    );
    $passStmt->execute([':member_id' => (int)$member['id']]);
    $dailyPasses = $passStmt->fetchAll(PDO::FETCH_ASSOC);
    $manilaNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $today = manilaToday()->format('Y-m-d');
    foreach ($dailyPasses as &$pass) {
        $expiresAt = new DateTimeImmutable($pass['expires_at'], new DateTimeZone('Asia/Manila'));
        $pass['access_eligible'] = $pass['payment_status'] === 'paid'
            && (float)$pass['amount'] === 100.0
            && $pass['visit_date'] === $today
            && $expiresAt > $manilaNow
            && $pass['checked_in_at'] === null;
        $pass['entitlement_status'] = $pass['payment_status'] !== 'paid'
            ? $pass['payment_status']
            : ($pass['access_eligible']
                ? 'active'
                : ($pass['visit_date'] > $today ? 'scheduled' : 'expired'));
        if (!$pass['access_eligible']) {
            $pass['pass_code'] = null;
        }
    }
    unset($pass);
    $member['daily_passes'] = $dailyPasses;
    $member['latest_daily_pass'] = $dailyPasses[0] ?? null;
    $member['gym_access_status'] = strtolower(trim((string)($member['gym_access_status'] ?? 'allowed')));
    $member['gym_access_reason'] = null;
    if ($member['gym_access_status'] === 'restricted') {
        $restrictionStmt = $conn->prepare(
            "SELECT reason, changed_at
             FROM member_membership_access_audit
             WHERE member_id = :member_id AND new_gym_access_status = 'restricted'
             ORDER BY changed_at DESC, id DESC
             LIMIT 1"
        );
        $restrictionStmt->execute([':member_id' => (int)$member['id']]);
        $member['gym_access_reason'] = $restrictionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $response = ['success' => true, 'member' => $member];
    if ($isUpdateRequest) {
        $response['message'] = 'Profile updated successfully.';
    }

    sendResponse($response);

} catch (PDOException $e) {
    error_log('Member profile operation failed: ' . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'Server error. Please try again later.'], 500);
}

?>
