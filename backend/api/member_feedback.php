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

if (defined('MEMBER_FEEDBACK_TESTING') && MEMBER_FEEDBACK_TESTING === true) {
    $conn = $GLOBALS['member_feedback_test_connection'];
    $currentUser = $GLOBALS['member_feedback_test_user'];
} else {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
    $currentUser = requireRole($conn, ['member']);
}

function memberFeedbackResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (strtolower(trim((string)($currentUser['role'] ?? ''))) !== 'member') {
    memberFeedbackResponse(['success' => false, 'message' => 'Only members can submit or view their feedback.'], 403);
}

function memberFeedbackText($value, $fieldName) {
    if ($value === null) return null;
    if (!is_string($value)) {
        memberFeedbackResponse(['success' => false, 'message' => $fieldName . ' must be text.'], 400);
    }

    $value = trim($value);
    if ($value === '') return null;
    if (preg_match('//u', $value) !== 1) {
        memberFeedbackResponse(['success' => false, 'message' => $fieldName . ' contains invalid text.'], 400);
    }
    preg_match_all('/./us', $value, $characters);
    if (count($characters[0]) > 1000) {
        memberFeedbackResponse(['success' => false, 'message' => $fieldName . ' must not exceed 1000 characters.'], 400);
    }
    return $value;
}

try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
        header('Allow: GET, POST, OPTIONS');
        memberFeedbackResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $memberStmt = $conn->prepare(
        'SELECT id FROM members
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $memberStmt->execute([':user_id' => (int)$currentUser['id']]);
    $memberId = $memberStmt->fetchColumn();
    if (!$memberId) {
        memberFeedbackResponse(['success' => false, 'message' => 'Member profile not found for this account.'], 404);
    }
    $memberId = (int)$memberId;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $visitsStmt = $conn->prepare(
            'SELECT a.id AS attendance_id, a.attendance_date, a.time_in, a.time_out,
                    gym.id AS gym_feedback_id, trainer_feedback.id AS trainer_feedback_id
             FROM attendance a
             LEFT JOIN member_feedback gym
               ON gym.attendance_id = a.id AND gym.feedback_category = \'gym\'
             LEFT JOIN member_feedback trainer_feedback
               ON trainer_feedback.attendance_id = a.id AND trainer_feedback.feedback_category = \'trainer\'
             WHERE a.member_id = :member_id
               AND a.time_in IS NOT NULL
               AND a.attendance_date <= :today
               AND EXISTS (
                   SELECT 1 FROM attendance_qr_token_uses q
                   WHERE q.attendance_id = a.id AND q.member_id = a.member_id
               )
             ORDER BY a.attendance_date DESC, a.time_in DESC, a.id DESC'
        );
        $visitsStmt->execute([':member_id' => $memberId, ':today' => $today]);

        $historyStmt = $conn->prepare(
            'SELECT f.id, f.attendance_id, f.booking_id, f.feedback_category, f.trainer_id,
                    f.rating, f.comments, f.suggestions, f.is_anonymous,
                    f.is_hidden, f.moderation_reason, f.created_at,
                    COALESCE(a.attendance_date, b.booking_date) AS attendance_date,
                    a.time_in, b.booking_code, u.full_name AS trainer_name
             FROM member_feedback f
             LEFT JOIN attendance a ON a.id = f.attendance_id
             LEFT JOIN bookings b ON b.id = f.booking_id
             JOIN members m ON m.id = COALESCE(a.member_id, b.member_id)
             LEFT JOIN trainers t ON t.id = f.trainer_id
             LEFT JOIN users u ON u.id = t.user_id
             WHERE m.id = :member_id
             ORDER BY f.created_at DESC, f.id DESC'
        );
        $historyStmt->execute([':member_id' => $memberId]);

        memberFeedbackResponse([
            'success' => true,
            'visits' => $visitsStmt->fetchAll(PDO::FETCH_ASSOC),
            'feedback' => $historyStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        memberFeedbackResponse(['success' => false, 'message' => 'A valid JSON request is required.'], 400);
    }

    $category = strtolower(trim((string)($input['feedback_category'] ?? '')));
    if ($category === 'gym') {
        memberFeedbackResponse([
            'success' => false,
            'message' => 'Gym and system feedback is now available from the dedicated daily feedback page.',
        ], 410);
    }
    $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);
    if ($category !== 'trainer' || $rating === false || $rating < 1 || $rating > 5) {
        memberFeedbackResponse(['success' => false, 'message' => 'Choose a completed booking, the trainer feedback category, and a rating from 1 to 5.'], 400);
    }

    $bookingId = filter_var($input['booking_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$bookingId || !empty($input['attendance_id'])) {
        memberFeedbackResponse(['success' => false, 'message' => 'Trainer feedback must be linked to a completed booking.'], 400);
    }
    if (!empty($input['trainer_id'])) {
        memberFeedbackResponse(['success' => false, 'message' => 'The trainer is determined by the booking and cannot be selected.'], 400);
    }

    $comments = memberFeedbackText($input['comments'] ?? null, 'Comments');
    $suggestions = memberFeedbackText($input['suggestions'] ?? null, 'Suggestions');
    $anonymous = filter_var($input['is_anonymous'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($anonymous === null) {
        memberFeedbackResponse(['success' => false, 'message' => 'Choose whether this feedback is anonymous.'], 400);
    }

    $conn->beginTransaction();
    $trainerId = null;
    if ($category === 'trainer') {
        $bookingStmt = $conn->prepare(
            "SELECT b.trainer_id
             FROM bookings b
             WHERE b.id = :booking_id
               AND b.member_id = :member_id
               AND b.status = 'completed'
               AND b.trainer_id IS NOT NULL
             LIMIT 1
             FOR UPDATE"
        );
        $bookingStmt->execute([
            ':booking_id' => (int)$bookingId,
            ':member_id' => $memberId,
        ]);
        $trainerId = $bookingStmt->fetchColumn();
        if (!$trainerId) {
            $conn->rollBack();
            memberFeedbackResponse(['success' => false, 'message' => 'That booking is not an eligible completed booking for your account.'], 404);
        }
        $trainerId = (int)$trainerId;
    }

    $insertStmt = $conn->prepare(
        'INSERT INTO member_feedback
            (attendance_id, booking_id, feedback_category, trainer_id, rating, comments, suggestions, is_anonymous)
         VALUES
            (:attendance_id, :booking_id, :feedback_category, :trainer_id, :rating, :comments, :suggestions, :is_anonymous)'
    );
    try {
        $insertStmt->execute([
            ':attendance_id' => null,
            ':booking_id' => $bookingId === null ? null : (int)$bookingId,
            ':feedback_category' => $category,
            ':trainer_id' => $trainerId === null ? null : (int)$trainerId,
            ':rating' => $rating,
            ':comments' => $comments,
            ':suggestions' => $suggestions,
            ':is_anonymous' => $anonymous ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        $conn->rollBack();
        if (($e->errorInfo[1] ?? null) === 1062) {
            memberFeedbackResponse(['success' => false, 'message' => 'A trainer rating for this booking has already been submitted.'], 409);
        }
        throw $e;
    }

    $feedbackId = (int)$conn->lastInsertId();
    $conn->commit();
    memberFeedbackResponse(['success' => true, 'feedback_id' => $feedbackId, 'message' => 'Thank you for sharing your feedback.'], 201);
} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('Member feedback database operation failed.');
    memberFeedbackResponse(['success' => false, 'message' => 'Unable to process feedback right now. Please try again.'], 500);
}

?>
