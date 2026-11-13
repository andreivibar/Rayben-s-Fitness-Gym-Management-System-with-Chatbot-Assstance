<?php

function getInitialAccountApproval(PDO $conn, int $userId): ?array
{
    $dailyPassStmt = $conn->prepare(
        "SELECT r.status, r.review_note, r.created_at AS submitted_at,
                pass.payment_status, 'daily_pass' AS application_type
         FROM members m
         JOIN registration_requests r
           ON r.member_id = m.id
          AND r.registration_source = 'online'
          AND r.membership_type = 'walk-in'
         LEFT JOIN daily_pass_transactions pass ON pass.id = (
             SELECT MIN(pass_row.id)
             FROM daily_pass_transactions pass_row
             WHERE pass_row.member_id = m.id
               AND pass_row.transaction_source = 'online'
         )
         WHERE m.user_id = :user_id
           AND LOWER(TRIM(m.membership_type)) = 'daily_pass'
         ORDER BY r.id DESC
         LIMIT 1"
    );
    $dailyPassStmt->execute([':user_id' => $userId]);
    $dailyPassApproval = $dailyPassStmt->fetch(PDO::FETCH_ASSOC);
    if ($dailyPassApproval) {
        return $dailyPassApproval;
    }

    $monthlyStmt = $conn->prepare(
        "SELECT r.status, r.review_note, r.created_at AS submitted_at,
                r.payment_status, 'monthly' AS application_type
         FROM users u
         JOIN registration_requests r
           ON r.username = u.username
          AND LOWER(COALESCE(r.email, '')) = LOWER(COALESCE(u.email, ''))
          AND r.password = u.password
          AND r.membership_type = 'monthly'
          AND r.registration_source = 'online'
          AND r.member_id IS NULL
         WHERE u.id = :user_id
           AND LOWER(TRIM(u.role)) = 'member'
           AND r.status IN ('pending', 'rejected')
         ORDER BY r.id DESC
         LIMIT 1"
    );
    $monthlyStmt->execute([':user_id' => $userId]);
    $approval = $monthlyStmt->fetch(PDO::FETCH_ASSOC);

    return $approval ?: null;
}

function requiresInitialAccountApproval(?array $approval): bool
{
    return $approval !== null
        && strtolower(trim((string)($approval['status'] ?? ''))) !== 'approved';
}

?>
