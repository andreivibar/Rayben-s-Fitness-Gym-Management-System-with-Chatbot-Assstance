<?php

function normalizeDailyPassPayment(array $pass): array {
    $paymentStatus = strtolower(trim((string)($pass['payment_status'] ?? '')));
    $status = match ($paymentStatus) {
        'paid' => 'paid',
        'rejected' => 'failed',
        'pending', 'unpaid' => 'pending',
        default => 'failed',
    };
    $statusLabel = match ($paymentStatus) {
        'paid' => 'Verified / Paid',
        'pending' => 'Pending',
        'unpaid' => 'Unpaid',
        'rejected' => 'Rejected',
        default => 'Invalid',
    };
    $recordId = (int)$pass['id'];
    $createdAt = $pass['created_at'] ?? null;
    $verifiedAt = $pass['verified_at'] ?? null;
    $paymentDate = $verifiedAt ?: $createdAt;
    $visitDate = $pass['visit_date'] ?? null;
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $today = $now->format('Y-m-d');
    $accessEligible = false;
    if ($visitDate !== null && isset($pass['expires_at'])) {
        try {
            $expiresAt = new DateTimeImmutable((string)$pass['expires_at'], new DateTimeZone('Asia/Manila'));
            $accessEligible = $paymentStatus === 'paid'
                && (float)($pass['amount'] ?? 0) === 100.0
                && (string)$visitDate === $now->format('Y-m-d')
                && $expiresAt > $now
                && ($pass['checked_in_at'] ?? null) === null;
        } catch (Exception $e) {
            $accessEligible = false;
        }
    }

    return [
        'id' => 'daily-pass-' . $recordId,
        'record_id' => $recordId,
        'record_type' => 'daily_pass',
        'member_id' => isset($pass['member_id']) ? (int)$pass['member_id'] : null,
        'member_code' => $pass['member_code'] ?? null,
        'member_name' => ($pass['member_name'] ?? null) ?: ($pass['customer_name'] ?? 'Walk-in customer'),
        'member_email' => $pass['member_email'] ?? null,
        'booking_id' => null,
        'booking_code' => null,
        'recorded_by' => isset($pass['recorded_by']) ? (int)$pass['recorded_by'] : null,
        'recorded_by_name' => $pass['recorded_by_name'] ?? null,
        'amount' => (float)($pass['amount'] ?? 0),
        'payment_type' => 'daily_pass',
        'status' => $status,
        'display_status' => $statusLabel,
        'payment_status' => $paymentStatus,
        'payment_date' => $paymentDate ? substr((string)$paymentDate, 0, 10) : null,
        'purchase_date' => $createdAt,
        'pass_start_date' => $visitDate,
        'pass_end_date' => $visitDate,
        'access_eligible' => $accessEligible,
        'entitlement_status' => $paymentStatus === 'paid'
            ? ($accessEligible ? 'active' : ((string)$visitDate > $today ? 'scheduled' : 'expired'))
            : $paymentStatus,
        'reference_no' => $pass['reference_no'] ?? null,
        'record_reference' => 'DP-' . $recordId,
        'payment_method' => $pass['payment_method'] ?? null,
        'verification_status' => match ($paymentStatus) {
            'paid' => 'verified',
            'pending' => 'pending',
            'rejected' => 'rejected',
            default => 'not_required',
        },
        'verified_by' => isset($pass['verified_by']) ? (int)$pass['verified_by'] : null,
        'verified_by_name' => $pass['verified_by_name'] ?? null,
        'verified_at' => $verifiedAt,
        'verification_date' => $verifiedAt,
        'proof_original_name' => null,
        'created_at' => $createdAt,
        'updated_at' => $pass['updated_at'] ?? null,
        'membership_type' => 'daily_pass',
        'membership_start' => $visitDate,
        'membership_end' => $visitDate,
        'membership_status' => null,
        'transaction_source' => $pass['transaction_source'] ?? null,
    ];
}

function summarizeDailyPassPayments(array $passes): array {
    $summary = ['total_paid' => 0.0, 'pending_amount' => 0.0, 'pending_count' => 0];
    foreach ($passes as $pass) {
        $status = strtolower(trim((string)($pass['payment_status'] ?? '')));
        $amount = (float)($pass['amount'] ?? 0);
        if ($status === 'paid' && $amount === 100.0) {
            $summary['total_paid'] += 100.0;
        } elseif (in_array($status, ['pending', 'unpaid'], true)) {
            $summary['pending_amount'] += $amount;
            $summary['pending_count']++;
        }
    }

    return $summary;
}

?>
