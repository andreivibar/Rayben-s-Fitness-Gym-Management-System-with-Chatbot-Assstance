<?php

if (getenv('MEMBER_TRAINER_PROMOTION_TEST_TEMP_TABLES') !== '1') {
    echo "Skipped member-to-trainer promotion database tests; set MEMBER_TRAINER_PROMOTION_TEST_TEMP_TABLES=1 to run temporary-table cases." . PHP_EOL;
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

function getInitialAccountApproval(PDO $conn, int $userId): ?array
{
    return $userId === 6 ? ['status' => 'pending'] : null;
}

function requiresInitialAccountApproval(?array $approval): bool
{
    return $approval !== null
        && strtolower(trim((string)($approval['status'] ?? ''))) !== 'approved';
}

require_once __DIR__ . '/../config/member_trainer_promotion.php';

$conn->exec(
    "CREATE TEMPORARY TABLE users (
        id INT NOT NULL PRIMARY KEY,
        first_name VARCHAR(50),
        middle_name VARCHAR(50),
        last_name VARCHAR(50),
        full_name VARCHAR(100),
        email VARCHAR(100),
        password VARCHAR(255) NOT NULL,
        role ENUM('admin','trainer','member') NOT NULL,
        auth_token VARCHAR(255)
    ) ENGINE=InnoDB"
);
$conn->exec(
    "CREATE TEMPORARY TABLE members (
        id INT NOT NULL PRIMARY KEY,
        user_id INT NULL,
        phone VARCHAR(20),
        profile_picture VARCHAR(255),
        account_status ENUM('active','deactivated') NOT NULL DEFAULT 'active',
        membership_type VARCHAR(50),
        membership_start DATE NULL,
        membership_end DATE NULL,
        gym_access_status VARCHAR(16)
    ) ENGINE=InnoDB"
);
$conn->exec(
    "CREATE TEMPORARY TABLE trainers (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        trainer_code VARCHAR(20) NOT NULL UNIQUE,
        status ENUM('active','inactive') NOT NULL,
        phone VARCHAR(50),
        profile_picture VARCHAR(255),
        created_at DATETIME
    ) ENGINE=InnoDB"
);
$conn->exec(
    "CREATE TEMPORARY TABLE member_trainer_promotion_audit (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        target_user_id INT NOT NULL,
        member_id INT NOT NULL,
        trainer_id INT NOT NULL,
        previous_role ENUM('admin','trainer','member') NOT NULL,
        new_role ENUM('admin','trainer','member') NOT NULL,
        admin_user_id INT NOT NULL,
        action_type ENUM('member_to_trainer_promotion') NOT NULL,
        promoted_at DATETIME NOT NULL,
        UNIQUE KEY uq_target_action (target_user_id, action_type),
        CONSTRAINT chk_force_audit_failure CHECK (target_user_id <> 5)
    ) ENGINE=InnoDB"
);

$conn->exec(
    "INSERT INTO users
        (id, first_name, middle_name, last_name, full_name, email, password, role, auth_token)
     VALUES
        (1, 'Admin', NULL, 'User', 'Admin User', 'admin@example.com', 'admin-hash', 'admin', 'admin-token'),
        (2, 'Jane', 'Marie', 'Doe', 'Jane Marie Doe', 'jane@example.com', 'member-hash', 'member', 'old-member-token'),
        (3, 'Admin', 'Target', 'User', 'Admin Target User', 'target-admin@example.com', 'admin-target-hash', 'admin', 'admin-target-token'),
        (4, 'Trainer', NULL, 'User', 'Trainer User', 'trainer@example.com', 'trainer-hash', 'trainer', 'trainer-token'),
        (5, 'Rollback', NULL, 'Target', 'Rollback Target', 'rollback@example.com', 'rollback-hash', 'member', 'rollback-token'),
        (6, 'Pending', NULL, 'Approval', 'Pending Approval', 'pending@example.com', 'pending-hash', 'member', 'pending-token'),
        (7, 'Inactive', NULL, 'Member', 'Inactive Member', 'inactive@example.com', 'inactive-hash', 'member', 'inactive-token')"
);
$conn->exec(
    "INSERT INTO members
        (id, user_id, phone, profile_picture, account_status, membership_type, membership_start, membership_end, gym_access_status)
     VALUES
        (22, 2, '09123456789', 'member-picture.jpg', 'active', 'monthly', '2026-01-01', '2026-12-31', 'allowed'),
        (33, 3, '09111111111', NULL, 'active', 'monthly', NULL, NULL, 'allowed'),
        (44, 4, '09222222222', NULL, 'active', 'monthly', NULL, NULL, 'allowed'),
        (55, 5, '09333333333', NULL, 'active', 'daily_pass', NULL, NULL, 'restricted'),
        (66, 6, '09444444444', NULL, 'active', 'monthly', NULL, NULL, 'allowed'),
        (77, 7, '09555555555', NULL, 'deactivated', 'monthly', NULL, NULL, 'allowed')"
);
$conn->exec(
    "INSERT INTO trainers (user_id, trainer_code, status, phone, created_at)
     VALUES (4, 'TRN-0001', 'active', '09222222222', NOW())"
);

$failures = [];
$initialUserCount = (int)$conn->query('SELECT COUNT(*) FROM users')->fetchColumn();
$promotion = promoteMemberAccountToTrainer($conn, 22, 1);
$user = $conn->query('SELECT * FROM users WHERE id = 2')->fetch(PDO::FETCH_ASSOC);
$member = $conn->query('SELECT * FROM members WHERE id = 22')->fetch(PDO::FETCH_ASSOC);
$trainer = $conn->query('SELECT * FROM trainers WHERE user_id = 2')->fetch(PDO::FETCH_ASSOC);
$audit = $conn->query('SELECT * FROM member_trainer_promotion_audit WHERE target_user_id = 2')->fetch(PDO::FETCH_ASSOC);

if (
    (int)$promotion['user_id'] !== 2
    || (int)$promotion['member_id'] !== 22
    || (int)$user['id'] !== 2
    || $user['role'] !== 'trainer'
    || $user['auth_token'] !== null
    || $user['password'] !== 'member-hash'
    || $user['first_name'] !== 'Jane'
    || $user['middle_name'] !== 'Marie'
    || $user['last_name'] !== 'Doe'
    || (int)$member['id'] !== 22
    || (int)$member['user_id'] !== 2
    || $member['membership_type'] !== 'monthly'
    || $member['membership_start'] !== '2026-01-01'
    || $member['membership_end'] !== '2026-12-31'
    || $member['gym_access_status'] !== 'allowed'
    || $trainer['status'] !== 'active'
    || $trainer['phone'] !== '09123456789'
    || $trainer['profile_picture'] !== 'member-picture.jpg'
    || !preg_match('/^TRN-\d{4,}$/', $trainer['trainer_code'])
    || (int)$audit['target_user_id'] !== 2
    || (int)$audit['member_id'] !== 22
    || (int)$audit['trainer_id'] !== (int)$trainer['id']
    || $audit['previous_role'] !== 'member'
    || $audit['new_role'] !== 'trainer'
    || (int)$audit['admin_user_id'] !== 1
    || $audit['action_type'] !== 'member_to_trainer_promotion'
) {
    $failures[] = 'successful promotion preserves existing identities and profile/history data';
}
if ((int)$conn->query('SELECT COUNT(*) FROM users')->fetchColumn() !== $initialUserCount) {
    $failures[] = 'promotion does not create another user account';
}

foreach ([22, 33, 44] as $memberId) {
    try {
        promoteMemberAccountToTrainer($conn, $memberId, 1);
        $failures[] = "rejects repeated, admin, or existing-trainer promotion for member {$memberId}";
    } catch (MemberTrainerPromotionException $e) {
        if ($e->httpStatus() !== 409) {
            $failures[] = "returns conflict for ineligible member {$memberId}";
        }
    }
}

foreach ([66, 77] as $memberId) {
    try {
        promoteMemberAccountToTrainer($conn, $memberId, 1);
        $failures[] = "rejects pending-approval or deactivated member {$memberId}";
    } catch (MemberTrainerPromotionException $e) {
        if ($e->httpStatus() !== 409) {
            $failures[] = "returns conflict for ineligible member {$memberId}";
        }
    }
}

try {
    promoteMemberAccountToTrainer($conn, 55, 1);
    $failures[] = 'audit failure should abort the promotion';
} catch (PDOException $e) {
    $failedUser = $conn->query('SELECT role, auth_token FROM users WHERE id = 5')->fetch(PDO::FETCH_ASSOC);
    $failedTrainer = $conn->query('SELECT COUNT(*) FROM trainers WHERE user_id = 5')->fetchColumn();
    if ($failedUser['role'] !== 'member' || $failedUser['auth_token'] !== 'rollback-token' || (int)$failedTrainer !== 0) {
        $failures[] = 'failed audit insert rolls back role, auth token, and trainer profile';
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed 8 member-to-trainer promotion database cases using connection-scoped temporary tables.' . PHP_EOL;
