<?php

class MemberTrainerPromotionException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

function promoteMemberAccountToTrainer(PDO $conn, int $memberId, int $adminUserId): array
{
    if ($conn->inTransaction()) {
        throw new LogicException('Member-to-trainer promotion must own its transaction.');
    }

    $conn->beginTransaction();
    try {
        $memberStmt = $conn->prepare(
            'SELECT m.id AS member_id, m.user_id, m.phone, m.profile_picture, m.account_status,
                    u.role, u.full_name
             FROM members m
             JOIN users u ON u.id = m.user_id
             WHERE m.id = :member_id
             FOR UPDATE'
        );
        $memberStmt->execute([':member_id' => $memberId]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            throw new MemberTrainerPromotionException(
                'This member is not linked to an existing user account and cannot be promoted.',
                409
            );
        }

        $role = strtolower(trim((string)$member['role']));
        if ($role !== 'member') {
            throw new MemberTrainerPromotionException(
                'Only an account with the Member role can be promoted.',
                409
            );
        }
        if (strtolower(trim((string)$member['account_status'])) !== 'active') {
            throw new MemberTrainerPromotionException(
                'Deactivated member accounts cannot be promoted.',
                409
            );
        }
        if (requiresInitialAccountApproval(
            getInitialAccountApproval($conn, (int)$member['user_id'])
        )) {
            throw new MemberTrainerPromotionException(
                'This member account is still awaiting initial administrator approval.',
                409
            );
        }

        $existingTrainer = $conn->prepare(
            'SELECT id FROM trainers WHERE user_id = :user_id LIMIT 1 FOR UPDATE'
        );
        $existingTrainer->execute([':user_id' => (int)$member['user_id']]);
        if ($existingTrainer->fetchColumn()) {
            throw new MemberTrainerPromotionException(
                'This account already has a Trainer profile. Contact support to resolve the account state.',
                409
            );
        }

        $temporaryCode = 'TRN-PROMO-' . strtoupper(bin2hex(random_bytes(5)));
        $insertTrainer = $conn->prepare(
            "INSERT INTO trainers (user_id, trainer_code, status, phone, profile_picture, created_at)
             VALUES (:user_id, :trainer_code, 'active', :phone, :profile_picture, NOW())"
        );
        $insertTrainer->execute([
            ':user_id' => (int)$member['user_id'],
            ':trainer_code' => $temporaryCode,
            ':phone' => $member['phone'],
            ':profile_picture' => $member['profile_picture'],
        ]);
        $trainerId = (int)$conn->lastInsertId();
        $trainerCode = sprintf('TRN-%04d', $trainerId);
        $updateTrainerCode = $conn->prepare(
            'UPDATE trainers SET trainer_code = :trainer_code WHERE id = :trainer_id'
        );
        $updateTrainerCode->execute([
            ':trainer_code' => $trainerCode,
            ':trainer_id' => $trainerId,
        ]);
        if ($updateTrainerCode->rowCount() !== 1) {
            throw new RuntimeException('Unable to assign a Trainer profile code.');
        }

        $updateUser = $conn->prepare(
            "UPDATE users
             SET role = 'trainer', auth_token = NULL
             WHERE id = :user_id AND LOWER(TRIM(role)) = 'member'"
        );
        $updateUser->execute([':user_id' => (int)$member['user_id']]);
        if ($updateUser->rowCount() !== 1) {
            throw new MemberTrainerPromotionException(
                'The account role changed during promotion. Refresh and try again.',
                409
            );
        }

        $audit = $conn->prepare(
            "INSERT INTO member_trainer_promotion_audit
                (target_user_id, member_id, trainer_id, previous_role, new_role,
                 admin_user_id, action_type, promoted_at)
             VALUES
                (:target_user_id, :member_id, :trainer_id, 'member', 'trainer',
                 :admin_user_id, 'member_to_trainer_promotion', NOW())"
        );
        $audit->execute([
            ':target_user_id' => (int)$member['user_id'],
            ':member_id' => (int)$member['member_id'],
            ':trainer_id' => $trainerId,
            ':admin_user_id' => $adminUserId,
        ]);

        $conn->commit();
        return [
            'user_id' => (int)$member['user_id'],
            'member_id' => (int)$member['member_id'],
            'trainer_id' => $trainerId,
            'trainer_code' => $trainerCode,
            'full_name' => (string)$member['full_name'],
        ];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
