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
require_once __DIR__ . '/../config/membership_workflow.php';

function normalizeMembershipType($value) {
    $typeKey = preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string)$value)));
    if ($typeKey === 'monthlysubscription' || $typeKey === 'monthly') {
        return 'monthly';
    }
    if ($typeKey === 'walkin' || $typeKey === 'walkinmembership') {
        return 'walk-in';
    }
    if ($typeKey === '1daypass') {
        return 'daily_pass';
    }
    return trim((string)$value);
}

function displayMembershipType($value) {
    $typeKey = preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string)$value)));
    if ($typeKey === 'monthly' || $typeKey === 'monthlysubscription') {
        return 'Monthly Subscription';
    }
    if ($typeKey === 'walkin' || $typeKey === 'walkinmembership') {
        return '1 Day Pass (legacy)';
    }
    if ($typeKey === 'dailypass' || $typeKey === '1daypass') {
        return '1 Day Pass';
    }
    return trim((string)$value);
}

function normalizeStatus($value) {
    $statusKey = preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string)$value)));
    if ($statusKey === 'active') {
        return 'active';
    }
    if ($statusKey === 'expired') {
        return 'expired';
    }
    if ($statusKey === 'inactive') {
        return 'inactive';
    }
    return trim((string)$value);
}

function inferMembershipType($membershipType, $start, $end) {
    $cleanType = normalizeMembershipType($membershipType);
    if (trim((string)$membershipType) !== '') {
        return $cleanType;
    }

    $hasValidDates = (
        ($start && $start !== '0000-00-00') ||
        ($end && $end !== '0000-00-00')
    );

    return $hasValidDates ? 'monthly' : 'walk-in';
}

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    $authUser = requireRole($conn, ['admin', 'administrator']);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = strtolower(trim((string)($_GET['action'] ?? '')));
        if ($action === 'membership-access-history') {
            $memberId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$memberId) {
                sendResponse(['success' => false, 'message' => 'A valid member ID is required.'], 400);
            }
            $historyStmt = $conn->prepare(
                'SELECT a.id, a.previous_membership_type, a.new_membership_type,
                        a.previous_member_category, a.new_member_category,
                        a.previous_gym_access_status, a.new_gym_access_status,
                        a.previous_membership_start, a.previous_membership_end,
                        a.new_membership_start, a.new_membership_end, a.reason,
                        a.changed_at, u.full_name AS admin_name
                 FROM member_membership_access_audit a
                 LEFT JOIN users u ON u.id = a.admin_user_id
                 WHERE a.member_id = :member_id
                 ORDER BY a.changed_at DESC, a.id DESC'
            );
            $historyStmt->execute([':member_id' => $memberId]);
            sendResponse(['success' => true, 'history' => $historyStmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        $stmt = $conn->prepare(
            "SELECT m.*, u.id AS account_user_id, u.role AS account_role,
                (SELECT dp.visit_date FROM daily_pass_transactions dp
                 WHERE dp.member_id = m.id AND dp.payment_status = 'paid'
                 ORDER BY dp.visit_date DESC, dp.id DESC LIMIT 1) AS latest_paid_pass_visit_date,
                (SELECT dp.expires_at FROM daily_pass_transactions dp
                 WHERE dp.member_id = m.id AND dp.payment_status = 'paid'
                 ORDER BY dp.visit_date DESC, dp.id DESC LIMIT 1) AS latest_paid_pass_expires_at,
                (SELECT dp.payment_status FROM daily_pass_transactions dp
                 WHERE dp.member_id = m.id ORDER BY dp.id DESC LIMIT 1) AS latest_pass_payment_status,
                (SELECT CONCAT(rr.payment_status, '|', rr.discount_status)
                 FROM registration_requests rr
                 WHERE rr.member_id = m.id AND rr.membership_type = 'monthly' AND rr.status = 'pending'
                 ORDER BY rr.id DESC LIMIT 1) AS pending_monthly_review_status
             FROM members m
             LEFT JOIN users u ON u.id = m.user_id
             ORDER BY m.id DESC"
        );
        $stmt->execute();
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $today = manilaToday()->format('Y-m-d');

        foreach ($members as &$member) {
            $inferredType = inferMembershipType(
                $member['membership_type'],
                $member['membership_start'],
                $member['membership_end']
            );

            $member['membership_type'] = $inferredType;
            $storedStatus = normalizeStatus($member['status']);
            $membershipEnd = trim((string)($member['membership_end'] ?? ''));
            $hasMembershipEnd = $membershipEnd !== '' && $membershipEnd !== '0000-00-00';
            $isExpiredByDate = $inferredType === 'monthly' && $hasMembershipEnd && $membershipEnd < $today;
            $membershipStatus = $storedStatus === 'expired' || $isExpiredByDate ? 'expired' : 'active';
            $member['status'] = $membershipStatus;
            $member['membership_status'] = $membershipStatus;
            $member['account_status'] = strtolower(trim((string)($member['account_status'] ?? 'active'))) === 'deactivated'
                ? 'deactivated'
                : 'active';

            $membershipStart = trim((string)($member['membership_start'] ?? ''));
            $hasMembershipStart = $membershipStart !== '' && $membershipStart !== '0000-00-00';
            $isMonthlyEntitled = $inferredType === 'monthly'
                && $hasMembershipStart
                && $hasMembershipEnd
                && $membershipStart <= $today
                && $membershipEnd >= $today;
            $accessStatus = 'No Valid Gym Access';
            if ($isMonthlyEntitled) {
                $accessStatus = 'Active Monthly Subscription';
            } else {
                $passVisitDate = trim((string)($member['latest_paid_pass_visit_date'] ?? ''));
                $passExpiresAt = trim((string)($member['latest_paid_pass_expires_at'] ?? ''));
                $nowManila = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
                $passIsCurrent = $passVisitDate === $today
                    && $passExpiresAt !== ''
                    && $passExpiresAt > $nowManila;
                if ($passIsCurrent) {
                    $accessStatus = 'Valid 1 Day Pass';
                } else {
                    $latestPassStatus = strtolower(trim((string)($member['latest_pass_payment_status'] ?? '')));
                    $pendingReview = strtolower(trim((string)($member['pending_monthly_review_status'] ?? '')));
                    if (in_array($latestPassStatus, ['pending', 'unpaid'], true) || $pendingReview !== '') {
                        $accessStatus = 'Pending Payment or Approval';
                    } elseif ($passVisitDate !== '') {
                        $accessStatus = 'Expired 1 Day Pass';
                    }
                }
            }
            $member['gym_entry_status'] = $accessStatus;
        }
        unset($member);

        sendResponse(['success' => true, 'members' => $members]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
        $input = json_decode($rawInput, true) ?? [];
        $action = isset($_GET['action']) ? $_GET['action'] : ($input['action'] ?? 'create');

        if ($action === 'membership-access') {
            $memberId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $category = strtolower(trim((string)($input['member_category'] ?? '')));
            $accessStatus = strtolower(trim((string)($input['gym_access_status'] ?? '')));
            $reason = trim((string)($input['reason'] ?? ''));
            if (!$memberId || !in_array($category, ['regular', 'student', 'senior', 'pwd'], true)) {
                sendResponse(['success' => false, 'message' => 'A valid member ID and membership category are required.'], 400);
            }
            if (!in_array($accessStatus, ['allowed', 'restricted'], true)) {
                sendResponse(['success' => false, 'message' => 'Gym access must be allowed or restricted.'], 400);
            }
            if ($reason === '' || strlen($reason) > 500) {
                sendResponse(['success' => false, 'message' => 'A reason of 1 to 500 characters is required.'], 400);
            }
            if (!array_key_exists('membership_start', $input) || !array_key_exists('membership_end', $input)) {
                sendResponse(['success' => false, 'message' => 'Both membership dates must be provided.'], 400);
            }

            $parseDate = static function ($value) {
                $value = trim((string)$value);
                if ($value === '') {
                    return null;
                }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
                $errors = DateTimeImmutable::getLastErrors();
                if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                    || $date->format('Y-m-d') !== $value) {
                    return false;
                }
                return $value;
            };
            $membershipStart = $parseDate($input['membership_start']);
            $membershipEnd = $parseDate($input['membership_end']);
            if ($membershipStart === false || $membershipEnd === false) {
                sendResponse(['success' => false, 'message' => 'Membership dates must be valid Manila calendar dates in YYYY-MM-DD format.'], 400);
            }
            if (($membershipStart === null) !== ($membershipEnd === null)
                || ($membershipStart !== null && $membershipEnd < $membershipStart)) {
                sendResponse(['success' => false, 'message' => 'Provide both membership dates, with the end date on or after the start date.'], 400);
            }

            try {
                $conn->beginTransaction();
                $memberStmt = $conn->prepare(
                    'SELECT id, membership_type, member_category, gym_access_status,
                            membership_start, membership_end, status
                     FROM members WHERE id = :id FOR UPDATE'
                );
                $memberStmt->execute([':id' => $memberId]);
                $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
                if (!$member) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Member not found.'], 404);
                }

                $membershipType = normalizeMembershipType($member['membership_type']);
                $oldStart = trim((string)($member['membership_start'] ?? ''));
                $oldEnd = trim((string)($member['membership_end'] ?? ''));
                if ($oldStart === '' || $oldStart === '0000-00-00') $oldStart = null;
                if ($oldEnd === '' || $oldEnd === '0000-00-00') $oldEnd = null;
                if ($membershipType !== 'monthly') {
                    $membershipStart = $oldStart;
                    $membershipEnd = $oldEnd;
                } elseif (($membershipStart === null) !== ($membershipEnd === null)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Both membership dates are required for a monthly member.'], 400);
                }

                $datesChanged = $membershipStart !== $oldStart || $membershipEnd !== $oldEnd;
                if ($datesChanged && $membershipType === 'monthly') {
                    if ($membershipStart === null || $membershipEnd === null) {
                        $conn->rollBack();
                        sendResponse(['success' => false, 'message' => 'Monthly subscription dates cannot be cleared.'], 400);
                    }
                    $today = manilaToday()->format('Y-m-d');
                    $periodStmt = $conn->prepare(
                        "SELECT sp.id, sp.starts_on, sp.ends_on
                         FROM membership_subscription_periods sp
                         JOIN payments p ON p.id = sp.payment_id
                         LEFT JOIN registration_requests rr ON rr.id = sp.registration_request_id
                         WHERE sp.member_id = :member_id AND sp.status = 'active'
                           AND sp.ends_on >= :today
                           AND p.status = 'paid'
                           AND p.verification_status IN ('not_required', 'verified')
                           AND (sp.registration_request_id IS NULL OR (
                               rr.status = 'approved' AND rr.payment_status = 'verified'
                               AND rr.discount_status IN ('not_required', 'verified')
                           ))
                         ORDER BY CASE WHEN sp.starts_on <= :today_order THEN 0 ELSE 1 END,
                                  CASE WHEN sp.starts_on <= :today_recent THEN sp.starts_on END DESC,
                                  CASE WHEN sp.starts_on > :today_future THEN sp.starts_on END ASC,
                                  sp.id ASC
                         FOR UPDATE"
                    );
                    $periodStmt->execute([
                        ':member_id' => $memberId,
                        ':today' => $today,
                        ':today_order' => $today,
                        ':today_recent' => $today,
                        ':today_future' => $today,
                    ]);
                    $periods = $periodStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!$periods) {
                        $conn->rollBack();
                        sendResponse(['success' => false, 'message' => 'Subscription dates can only be changed for an existing paid and verified period.'], 409);
                    }
                    $targetPeriod = $periods[0];
                    $overlapStmt = $conn->prepare(
                        "SELECT id FROM membership_subscription_periods
                         WHERE member_id = :member_id AND id <> :period_id AND status <> 'superseded'
                           AND starts_on <= :new_end AND ends_on >= :new_start
                         LIMIT 1 FOR UPDATE"
                    );
                    $overlapStmt->execute([
                        ':member_id' => $memberId,
                        ':period_id' => (int)$targetPeriod['id'],
                        ':new_end' => $membershipEnd,
                        ':new_start' => $membershipStart,
                    ]);
                    if ($overlapStmt->fetch(PDO::FETCH_ASSOC)) {
                        $conn->rollBack();
                        sendResponse(['success' => false, 'message' => 'These dates overlap another subscription period. Adjust only the intended period without changing the others.'], 409);
                    }
                    if ($targetPeriod['starts_on'] !== $membershipStart || $targetPeriod['ends_on'] !== $membershipEnd) {
                        $updatePeriod = $conn->prepare(
                            'UPDATE membership_subscription_periods
                             SET starts_on = :starts_on, ends_on = :ends_on
                             WHERE id = :id AND member_id = :member_id AND status = \'active\''
                        );
                        $updatePeriod->execute([
                            ':starts_on' => $membershipStart,
                            ':ends_on' => $membershipEnd,
                            ':id' => (int)$targetPeriod['id'],
                            ':member_id' => $memberId,
                        ]);
                        if ($updatePeriod->rowCount() !== 1) {
                            $conn->rollBack();
                            sendResponse(['success' => false, 'message' => 'The paid subscription period changed while it was being edited. Please reload and try again.'], 409);
                        }
                    }
                }

                $oldCategory = strtolower(trim((string)($member['member_category'] ?? 'regular')));
                if (!in_array($oldCategory, ['regular', 'student', 'senior', 'pwd'], true)) {
                    $oldCategory = 'regular';
                }
                $oldAccessStatus = strtolower(trim((string)($member['gym_access_status'] ?? 'allowed')));
                if (!in_array($oldAccessStatus, ['allowed', 'restricted'], true)) {
                    $oldAccessStatus = 'allowed';
                }
                $newMemberStatus = $membershipType === 'monthly' && $membershipEnd !== null && $membershipEnd < manilaToday()->format('Y-m-d')
                    ? 'expired'
                    : ($datesChanged && $membershipType === 'monthly' ? 'active' : $member['status']);
                $updateMember = $conn->prepare(
                    'UPDATE members
                     SET member_category = :member_category, gym_access_status = :gym_access_status,
                         membership_start = :membership_start, membership_end = :membership_end,
                         status = :status
                     WHERE id = :id'
                );
                $updateMember->execute([
                    ':member_category' => $category,
                    ':gym_access_status' => $accessStatus,
                    ':membership_start' => $membershipStart,
                    ':membership_end' => $membershipEnd,
                    ':status' => $newMemberStatus,
                    ':id' => $memberId,
                ]);

                $auditStmt = $conn->prepare(
                    'INSERT INTO member_membership_access_audit (
                        member_id, admin_user_id, previous_membership_type, new_membership_type,
                        previous_member_category, new_member_category,
                        previous_gym_access_status, new_gym_access_status,
                        previous_membership_start, previous_membership_end,
                        new_membership_start, new_membership_end, reason
                     ) VALUES (
                        :member_id, :admin_user_id, :previous_membership_type, :new_membership_type,
                        :previous_member_category, :new_member_category,
                        :previous_gym_access_status, :new_gym_access_status,
                        :previous_membership_start, :previous_membership_end,
                        :new_membership_start, :new_membership_end, :reason
                     )'
                );
                $auditStmt->execute([
                    ':member_id' => $memberId,
                    ':admin_user_id' => (int)$authUser['id'],
                    ':previous_membership_type' => $member['membership_type'],
                    ':new_membership_type' => $member['membership_type'],
                    ':previous_member_category' => $oldCategory,
                    ':new_member_category' => $category,
                    ':previous_gym_access_status' => $oldAccessStatus,
                    ':new_gym_access_status' => $accessStatus,
                    ':previous_membership_start' => $oldStart,
                    ':previous_membership_end' => $oldEnd,
                    ':new_membership_start' => $membershipStart,
                    ':new_membership_end' => $membershipEnd,
                    ':reason' => $reason,
                ]);
                $conn->commit();
                sendResponse(['success' => true, 'message' => 'Membership and gym access updated successfully.']);
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
        }

        if ($action === 'create') {
            sendResponse([
                'success' => false,
                'message' => 'New Member accounts must use the approved in-person registration workflow.',
            ], 409);
        }

        if ($action === 'update') {
            if (empty($input['id'])) {
                sendResponse(['success' => false, 'message' => 'Member ID is required.'], 400);
            }
            try {
                $conn->beginTransaction();

                $memberStmt = $conn->prepare('SELECT * FROM members WHERE id = :id FOR UPDATE');
                $memberStmt->execute([':id' => (int)$input['id']]);
                $memberRow = $memberStmt->fetch(PDO::FETCH_ASSOC);
                if (!$memberRow) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Member not found.'], 404);
                }

                $fields = [
                    'first_name', 'middle_name', 'last_name', 'email', 'phone', 'address',
                    'date_of_birth', 'membership_type', 'membership_start', 'membership_end'
                ];
                $updated = [];
                foreach ($fields as $field) {
                    $updated[$field] = array_key_exists($field, $input)
                        ? trim((string)($input[$field] ?? ''))
                        : (string)($memberRow[$field] ?? '');
                }

                if ($updated['first_name'] === '' || $updated['last_name'] === '') {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'First and last names are required.'], 400);
                }
                if ($updated['email'] !== '' && !filter_var($updated['email'], FILTER_VALIDATE_EMAIL)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'A valid email address is required when provided.'], 400);
                }
                if (!preg_match('/^\d{11}$/', $updated['phone'])) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Phone number must contain exactly 11 digits.'], 400);
                }

                $membershipType = normalizeMembershipType($updated['membership_type']);
                if (!in_array($membershipType, ['monthly', 'walk-in', 'daily_pass'], true)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Invalid membership type.'], 400);
                }
                $existingType = normalizeMembershipType($memberRow['membership_type']);
                if ($membershipType === 'monthly' && $existingType !== 'monthly') {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Use the Member Dashboard upgrade workflow to activate a monthly membership.'], 409);
                }
                if (in_array($membershipType, ['walk-in', 'daily_pass'], true)
                    && $membershipType !== $existingType) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'A 1 Day Pass must be recorded as a daily pass transaction, not as a member subscription.'], 400);
                }
                if (in_array($membershipType, ['walk-in', 'daily_pass'], true)) {
                    $updated['membership_start'] = '';
                    $updated['membership_end'] = '';
                }
                if ($updated['membership_start'] === '0000-00-00') $updated['membership_start'] = '';
                if ($updated['membership_end'] === '0000-00-00') $updated['membership_end'] = '';
                $storedMembershipStart = trim((string)($memberRow['membership_start'] ?? ''));
                $storedMembershipEnd = trim((string)($memberRow['membership_end'] ?? ''));
                if ($storedMembershipStart === '0000-00-00') $storedMembershipStart = '';
                if ($storedMembershipEnd === '0000-00-00') $storedMembershipEnd = '';
                if ($existingType === 'monthly'
                    && ($updated['membership_start'] !== $storedMembershipStart
                        || $updated['membership_end'] !== $storedMembershipEnd)) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Subscription dates can only change through the approved renewal workflow.'], 409);
                }

                $firstName = $updated['first_name'];
                $middleName = $updated['middle_name'];
                $lastName = $updated['last_name'];
                $fullName = $firstName . ($middleName !== '' ? ' ' . $middleName : '') . ' ' . $lastName;

                if ($updated['email'] !== '') {
                    $duplicateEmail = $conn->prepare('SELECT id FROM members WHERE email = :email AND id <> :id LIMIT 1');
                    $duplicateEmail->execute([':email' => $updated['email'], ':id' => (int)$input['id']]);
                    if ($duplicateEmail->fetch(PDO::FETCH_ASSOC)) {
                        $conn->rollBack();
                        sendResponse(['success' => false, 'message' => 'Email is already registered to another member.'], 409);
                    }
                }
                if (!empty($memberRow['user_id'])) {
                    $uUpdate = $conn->prepare('UPDATE users SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, full_name = :full_name, email = :email WHERE id = :id');
                    $uUpdate->execute([
                        ':first_name' => $firstName,
                        ':middle_name' => $middleName !== '' ? $middleName : null,
                        ':last_name' => $lastName,
                        ':full_name' => $fullName,
                        ':email' => $updated['email'] !== '' ? $updated['email'] : null,
                        ':id' => $memberRow['user_id']
                    ]);
                }

                $stmt = $conn->prepare(
                    'UPDATE members SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, full_name = :full_name, email = :email, phone = :phone, address = :address,
                     date_of_birth = :date_of_birth, membership_type = :membership_type, membership_start = :membership_start,
                     membership_end = :membership_end WHERE id = :id'
                );

                $stmt->execute([
                    ':first_name' => $firstName,
                    ':middle_name' => $middleName !== '' ? $middleName : null,
                    ':last_name' => $lastName,
                    ':full_name' => $fullName,
                    ':email' => $updated['email'] !== '' ? $updated['email'] : null,
                    ':phone' => $updated['phone'],
                    ':address' => $updated['address'] !== '' ? $updated['address'] : null,
                    ':date_of_birth' => $updated['date_of_birth'] !== '' ? $updated['date_of_birth'] : null,
                    ':membership_type' => $membershipType,
                    ':membership_start' => $updated['membership_start'] !== '' ? $updated['membership_start'] : null,
                    ':membership_end' => $updated['membership_end'] !== '' ? $updated['membership_end'] : null,
                    ':id' => (int)$input['id']
                ]);

                $conn->commit();
                sendResponse(['success' => true, 'message' => 'Member updated successfully.']);
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }
        }

        if ($action === 'status') {
            sendResponse(['success' => false, 'message' => 'Use the account-status action to reactivate or deactivate an account.'], 400);
        }

        if ($action === 'account-status') {
            $memberId = (int)($input['id'] ?? 0);
            $accountStatus = strtolower(trim((string)($input['account_status'] ?? '')));
            if (!$memberId || !in_array($accountStatus, ['active', 'deactivated'], true)) {
                sendResponse(['success' => false, 'message' => 'A valid member ID and account status are required.'], 400);
            }

            try {
                $conn->beginTransaction();
                $memberStmt = $conn->prepare('SELECT id, user_id, membership_type, membership_end, status FROM members WHERE id = :id FOR UPDATE');
                $memberStmt->execute([':id' => $memberId]);
                $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
                if (!$member) {
                    $conn->rollBack();
                    sendResponse(['success' => false, 'message' => 'Member not found.'], 404);
                }

                if ($accountStatus === 'active' && strtolower((string)$member['status']) === 'inactive') {
                    $membershipEnd = trim((string)($member['membership_end'] ?? ''));
                    $isExpired = normalizeMembershipType($member['membership_type']) === 'monthly'
                        && $membershipEnd !== ''
                        && $membershipEnd !== '0000-00-00'
                        && $membershipEnd < manilaToday()->format('Y-m-d');
                    $membershipStatus = $isExpired ? 'expired' : 'active';
                    $statusUpdate = $conn->prepare('UPDATE members SET account_status = :account_status, status = :status WHERE id = :id');
                    $statusUpdate->execute([
                        ':account_status' => $accountStatus,
                        ':status' => $membershipStatus,
                        ':id' => $memberId
                    ]);
                } else {
                    $statusUpdate = $conn->prepare('UPDATE members SET account_status = :account_status WHERE id = :id');
                    $statusUpdate->execute([':account_status' => $accountStatus, ':id' => $memberId]);
                }
                if ($accountStatus === 'deactivated' && !empty($member['user_id'])) {
                    $tokenUpdate = $conn->prepare(
                        "UPDATE users SET auth_token = NULL
                         WHERE id = :user_id AND LOWER(TRIM(role)) = 'member'"
                    );
                    $tokenUpdate->execute([':user_id' => (int)$member['user_id']]);
                }
                $conn->commit();
                sendResponse(['success' => true, 'account_status' => $accountStatus, 'message' => 'Member account ' . ($accountStatus === 'active' ? 'reactivated' : 'deactivated') . ' successfully.']);
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                throw $e;
            }
        }

        if ($action === 'delete' || $action === 'deactivate') {
            if (empty($input['id'])) {
                sendResponse(['success' => false, 'message' => 'Member ID is required.'], 400);
            }

            $stmt = $conn->prepare(
                "UPDATE members m
                 LEFT JOIN users u ON u.id = m.user_id AND LOWER(TRIM(u.role)) = 'member'
                 SET m.account_status = 'deactivated', u.auth_token = NULL
                 WHERE m.id = :id"
            );
            $stmt->bindValue(':id', (int)$input['id'], PDO::PARAM_INT);
            $stmt->execute();

            sendResponse(['success' => true, 'message' => 'Member deactivated successfully. Historical records preserved.']);
        }

        sendResponse(['success' => false, 'message' => 'Invalid action.'], 400);
    }

    sendResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
} catch (PDOException $e) {
    sendResponse(['success' => false, 'message' => 'Server error. Please try again later.'], 500);
}
