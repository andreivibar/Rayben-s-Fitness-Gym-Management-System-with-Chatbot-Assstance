<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (defined('TRAINER_FEEDBACK_TESTING') && TRAINER_FEEDBACK_TESTING === true) {
    $conn = $GLOBALS['trainer_feedback_test_connection'];
    $trainerUser = $GLOBALS['trainer_feedback_test_user'];
} else {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
    $trainerUser = requireRole($conn, ['trainer']);
}

function trainerFeedbackResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (strtolower(trim((string)($trainerUser['role'] ?? ''))) !== 'trainer') {
    trainerFeedbackResponse(['success' => false, 'message' => 'Only trainers can view their service feedback.'], 403);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET, OPTIONS');
        trainerFeedbackResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $trainerStmt = $conn->prepare(
        "SELECT t.id
         FROM trainers t
         JOIN users u ON u.id = t.user_id
         WHERE t.user_id = :user_id
           AND LOWER(TRIM(t.status)) = 'active'
           AND LOWER(TRIM(u.role)) = 'trainer'
         LIMIT 1"
    );
    $trainerStmt->execute([':user_id' => (int)$trainerUser['id']]);
    $trainerId = $trainerStmt->fetchColumn();
    if (!$trainerId) {
        trainerFeedbackResponse(['success' => false, 'message' => 'Trainer profile not found.'], 404);
    }
    $trainerId = (int)$trainerId;

    $aggregateStmt = $conn->prepare(
        "SELECT COUNT(*) AS total, AVG(rating) AS average_rating,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS rating_1,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) AS rating_2,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) AS rating_3,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) AS rating_4,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS rating_5,
                SUM(CASE WHEN is_hidden = 1 THEN 1 ELSE 0 END) AS hidden_total
         FROM member_feedback
         WHERE trainer_id = :trainer_id AND feedback_category = 'trainer'"
    );
    $aggregateStmt->execute([':trainer_id' => $trainerId]);
    $aggregate = $aggregateStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $feedbackStmt = $conn->prepare(
        "SELECT f.id, f.rating, f.comments, f.suggestions, f.is_anonymous,
                f.created_at, COALESCE(a.attendance_date, b.booking_date) AS feedback_date
         FROM member_feedback f
         LEFT JOIN attendance a ON a.id = f.attendance_id
         LEFT JOIN bookings b ON b.id = f.booking_id
         WHERE f.trainer_id = :trainer_id
           AND f.feedback_category = 'trainer'
           AND f.is_hidden = 0
         ORDER BY f.created_at DESC, f.id DESC
         LIMIT 20"
    );
    $feedbackStmt->execute([':trainer_id' => $trainerId]);

    trainerFeedbackResponse([
        'success' => true,
        'aggregates_include_hidden' => true,
        'summary' => [
            'total' => (int)($aggregate['total'] ?? 0),
            'average_rating' => $aggregate['average_rating'] === null ? null : round((float)$aggregate['average_rating'], 2),
            'hidden_total' => (int)($aggregate['hidden_total'] ?? 0),
            'distribution' => [
                1 => (int)($aggregate['rating_1'] ?? 0),
                2 => (int)($aggregate['rating_2'] ?? 0),
                3 => (int)($aggregate['rating_3'] ?? 0),
                4 => (int)($aggregate['rating_4'] ?? 0),
                5 => (int)($aggregate['rating_5'] ?? 0),
            ],
        ],
        'feedback' => $feedbackStmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (PDOException $e) {
    error_log('Trainer feedback database operation failed.');
    trainerFeedbackResponse(['success' => false, 'message' => 'Unable to load trainer feedback right now. Please try again.'], 500);
}

?>
