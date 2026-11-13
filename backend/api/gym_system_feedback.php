<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (defined('GYM_SYSTEM_FEEDBACK_TESTING') && GYM_SYSTEM_FEEDBACK_TESTING === true) {
    $conn = $GLOBALS['gym_system_feedback_test_connection'];
    $currentUser = $GLOBALS['gym_system_feedback_test_user'];
} else {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
    $currentUser = requireRole($conn, ['member']);
}

function gymSystemFeedbackResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (strtolower(trim((string)($currentUser['role'] ?? ''))) !== 'member') {
    gymSystemFeedbackResponse(['success' => false, 'message' => 'Only members can submit or view gym and system feedback.'], 403);
}

function gymSystemFeedbackRating($value, $fieldName) {
    if ($value === null || $value === '') return null;
    $rating = filter_var($value, FILTER_VALIDATE_INT);
    if ($rating === false || $rating < 1 || $rating > 5) {
        gymSystemFeedbackResponse(['success' => false, 'message' => $fieldName . ' must be a whole number from 1 to 5.'], 400);
    }
    return $rating;
}

function gymSystemFeedbackText($value, $fieldName) {
    if ($value === null) return null;
    if (!is_string($value)) {
        gymSystemFeedbackResponse(['success' => false, 'message' => $fieldName . ' must be text.'], 400);
    }

    $value = trim($value);
    if ($value === '') return null;
    if (preg_match('//u', $value) !== 1) {
        gymSystemFeedbackResponse(['success' => false, 'message' => $fieldName . ' contains invalid text.'], 400);
    }
    preg_match_all('/./us', $value, $characters);
    if (count($characters[0]) > 1000) {
        gymSystemFeedbackResponse(['success' => false, 'message' => $fieldName . ' must not exceed 1000 characters.'], 400);
    }
    return $value;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST, OPTIONS');
        gymSystemFeedbackResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $memberStmt = $conn->prepare(
        'SELECT id, account_status
         FROM members
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $memberStmt->execute([':user_id' => (int)$currentUser['id']]);
    $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        gymSystemFeedbackResponse(['success' => false, 'message' => 'Member profile not found for this account.'], 404);
    }
    if (strtolower(trim((string)($member['account_status'] ?? ''))) !== 'active') {
        gymSystemFeedbackResponse(['success' => false, 'message' => 'Only members with an active account can submit or view feedback.'], 403);
    }
    $memberId = (int)$member['id'];
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $todayDate = $today->format('Y-m-d');

    if ($method === 'GET') {
        $todayStmt = $conn->prepare(
            'SELECT id, member_id, feedback_date, overall_rating, overall_comments,
                    facilities_rating, facilities_comments, services_rating, services_comments,
                    system_rating, system_comments, is_hidden, moderation_reason,
                    moderated_at, submitted_at
             FROM gym_system_feedback
             WHERE member_id = :member_id AND feedback_date = :feedback_date
             LIMIT 1'
        );
        $todayStmt->execute([':member_id' => $memberId, ':feedback_date' => $todayDate]);
        $todayFeedback = $todayStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $historyStmt = $conn->prepare(
            'SELECT id, feedback_date, overall_rating, overall_comments,
                    facilities_rating, facilities_comments, services_rating, services_comments,
                    system_rating, system_comments, is_hidden, moderation_reason,
                    moderated_at, submitted_at
             FROM gym_system_feedback
             WHERE member_id = :member_id
             ORDER BY feedback_date DESC, id DESC'
        );
        $historyStmt->execute([':member_id' => $memberId]);

        $legacyHistoryStmt = $conn->prepare(
            'SELECT f.id, f.rating, f.comments, f.suggestions, f.is_hidden,
                    f.moderation_reason, f.created_at, a.attendance_date, a.time_in
             FROM member_feedback f
             JOIN attendance a ON a.id = f.attendance_id
             WHERE a.member_id = :member_id AND f.feedback_category = \'gym\'
             ORDER BY f.created_at DESC, f.id DESC'
        );
        $legacyHistoryStmt->execute([':member_id' => $memberId]);

        gymSystemFeedbackResponse([
            'success' => true,
            'today' => $todayDate,
            'already_submitted' => $todayFeedback !== null,
            'today_feedback' => $todayFeedback,
            'feedback' => $historyStmt->fetchAll(PDO::FETCH_ASSOC),
            'legacy_feedback' => $legacyHistoryStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        gymSystemFeedbackResponse(['success' => false, 'message' => 'A valid JSON request is required.'], 400);
    }

    $fields = [
        'overall_rating' => ['rating', 'Overall gym experience'],
        'overall_comments' => ['text', 'Overall gym experience comments'],
        'facilities_rating' => ['rating', 'Facilities and equipment rating'],
        'facilities_comments' => ['text', 'Facilities and equipment comments'],
        'services_rating' => ['rating', 'Gym services rating'],
        'services_comments' => ['text', 'Gym services comments'],
        'system_rating' => ['rating', 'System experience rating'],
        'system_comments' => ['text', 'System experience comments'],
    ];
    $values = [];
    $hasContent = false;
    foreach ($fields as $column => [$type, $label]) {
        $value = $type === 'rating'
            ? gymSystemFeedbackRating($input[$column] ?? null, $label)
            : gymSystemFeedbackText($input[$column] ?? null, $label);
        $values[$column] = $value;
        if ($value !== null) $hasContent = true;
    }
    if (!$hasContent) {
        gymSystemFeedbackResponse(['success' => false, 'message' => 'Provide at least one rating or written comment before submitting.'], 400);
    }

    $conn->beginTransaction();
    $memberLock = $conn->prepare(
        'SELECT id, account_status FROM members WHERE id = :member_id LIMIT 1 FOR UPDATE'
    );
    $memberLock->execute([':member_id' => $memberId]);
    $lockedMember = $memberLock->fetch(PDO::FETCH_ASSOC);
    if (!$lockedMember || strtolower(trim((string)($lockedMember['account_status'] ?? ''))) !== 'active') {
        $conn->rollBack();
        gymSystemFeedbackResponse(['success' => false, 'message' => 'Only members with an active account can submit feedback.'], 403);
    }

    $duplicateStmt = $conn->prepare(
        'SELECT id FROM gym_system_feedback
         WHERE member_id = :member_id AND feedback_date = :feedback_date
         LIMIT 1 FOR UPDATE'
    );
    $duplicateStmt->execute([':member_id' => $memberId, ':feedback_date' => $todayDate]);
    if ($duplicateStmt->fetchColumn()) {
        $conn->rollBack();
        gymSystemFeedbackResponse([
            'success' => false,
            'already_submitted' => true,
            'message' => 'You have already submitted feedback for today. You can submit again after the next Philippine calendar day begins.',
        ], 409);
    }

    $insertStmt = $conn->prepare(
        'INSERT INTO gym_system_feedback
            (member_id, feedback_date, overall_rating, overall_comments,
             facilities_rating, facilities_comments, services_rating, services_comments,
             system_rating, system_comments, submitted_at)
         VALUES
            (:member_id, :feedback_date, :overall_rating, :overall_comments,
             :facilities_rating, :facilities_comments, :services_rating, :services_comments,
             :system_rating, :system_comments, :submitted_at)'
    );
    try {
        $insertStmt->execute([
            ':member_id' => $memberId,
            ':feedback_date' => $todayDate,
            ':overall_rating' => $values['overall_rating'],
            ':overall_comments' => $values['overall_comments'],
            ':facilities_rating' => $values['facilities_rating'],
            ':facilities_comments' => $values['facilities_comments'],
            ':services_rating' => $values['services_rating'],
            ':services_comments' => $values['services_comments'],
            ':system_rating' => $values['system_rating'],
            ':system_comments' => $values['system_comments'],
            ':submitted_at' => $today->format('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        $conn->rollBack();
        if (($e->errorInfo[1] ?? null) === 1062) {
            gymSystemFeedbackResponse([
                'success' => false,
                'already_submitted' => true,
                'message' => 'You have already submitted feedback for today. You can submit again after the next Philippine calendar day begins.',
            ], 409);
        }
        throw $e;
    }

    $feedbackId = (int)$conn->lastInsertId();
    $conn->commit();
    gymSystemFeedbackResponse([
        'success' => true,
        'feedback_id' => $feedbackId,
        'feedback_date' => $todayDate,
        'submitted_at' => $today->format('Y-m-d H:i:s'),
        'message' => 'Thank you for sharing your feedback.',
    ], 201);
} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('Gym and system feedback database operation failed.');
    gymSystemFeedbackResponse(['success' => false, 'message' => 'Unable to process feedback right now. Please try again.'], 500);
}

?>
