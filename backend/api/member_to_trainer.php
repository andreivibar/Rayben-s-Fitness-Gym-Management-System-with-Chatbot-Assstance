<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/member_trainer_promotion.php';

function memberTrainerPromotionResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        memberTrainerPromotionResponse(['success' => true]);
    }

    $admin = requireRole($conn, ['admin', 'administrator']);
    $action = strtolower(trim((string)($_GET['action'] ?? 'promote')));

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'audit') {
        $stmt = $conn->query(
            'SELECT a.id, a.target_user_id, a.member_id, a.trainer_id,
                    a.previous_role, a.new_role, a.admin_user_id, a.action_type, a.promoted_at,
                    target.full_name AS target_name, approver.full_name AS admin_name,
                    t.trainer_code
             FROM member_trainer_promotion_audit a
             JOIN users target ON target.id = a.target_user_id
             JOIN users approver ON approver.id = a.admin_user_id
             JOIN trainers t ON t.id = a.trainer_id
             ORDER BY a.promoted_at DESC, a.id DESC
             LIMIT 200'
        );
        memberTrainerPromotionResponse([
            'success' => true,
            'promotions' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'promote') {
        memberTrainerPromotionResponse(['success' => false, 'message' => 'Unsupported method or action.'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $memberId = filter_var(
        $input['member_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if (!$memberId) {
        memberTrainerPromotionResponse(['success' => false, 'message' => 'A valid member ID is required.'], 400);
    }

    $promotion = promoteMemberAccountToTrainer(
        $conn,
        (int)$memberId,
        (int)$admin['id']
    );
    memberTrainerPromotionResponse([
        'success' => true,
        'message' => $promotion['full_name'] . ' was promoted to Trainer. They must log in again to refresh their account permissions.',
        'promotion' => $promotion,
    ]);
} catch (MemberTrainerPromotionException $e) {
    memberTrainerPromotionResponse([
        'success' => false,
        'message' => $e->getMessage(),
    ], $e->httpStatus());
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Member-to-trainer promotion failed; exception=' . get_class($e));
    memberTrainerPromotionResponse([
        'success' => false,
        'message' => 'Unable to promote this member right now. No partial promotion was saved. Please retry or contact support.',
    ], 500);
}
