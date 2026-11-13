<?php

function attendanceQrCheckMemberStatus(array $member): ?string {
    if (strtolower(trim((string)($member['account_status'] ?? 'active'))) !== 'active') {
        return 'Your member account is deactivated.';
    }

    if (strtolower(trim((string)($member['status'] ?? ''))) !== 'active') {
        return 'Your membership is not active.';
    }

    return null;
}

function attendanceQrEvaluateAccess(array $member, array $subscriptionPeriods, array $dailyPasses, DateTimeImmutable $now): array {
    $memberStatusError = attendanceQrCheckMemberStatus($member);
    if ($memberStatusError !== null) {
        return ['eligible' => false, 'message' => $memberStatusError];
    }
    if (strtolower(trim((string)($member['gym_access_status'] ?? 'allowed'))) === 'restricted') {
        return ['eligible' => false, 'message' => 'Gym access has been restricted. Please contact gym staff.'];
    }

    $manilaNow = $now->setTimezone(new DateTimeZone('Asia/Manila'));
    $today = $manilaNow->format('Y-m-d');
    $upcomingSubscriptionStart = null;

    foreach ($subscriptionPeriods as $period) {
        if (
            strtolower(trim((string)($period['status'] ?? ''))) === 'scheduled'
            && (string)($period['starts_on'] ?? '') > $today
            && ($upcomingSubscriptionStart === null || $period['starts_on'] < $upcomingSubscriptionStart)
        ) {
            $upcomingSubscriptionStart = (string)$period['starts_on'];
        }
        if (
            strtolower(trim((string)($period['status'] ?? ''))) === 'active'
            && (string)($period['starts_on'] ?? '') <= $today
            && (string)($period['ends_on'] ?? '') >= $today
        ) {
            return ['eligible' => true, 'access_type' => 'monthly', 'daily_pass_id' => null];
        }
    }

    $membershipType = preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string)($member['membership_type'] ?? ''))));
    if ($subscriptionPeriods === [] && in_array($membershipType, ['monthly', 'monthlysubscription'], true)) {
        $membershipStart = trim((string)($member['membership_start'] ?? ''));
        $membershipEnd = trim((string)($member['membership_end'] ?? ''));
        if (
            $membershipEnd !== ''
            && $membershipEnd !== '0000-00-00'
            && $membershipEnd >= $today
            && ($membershipStart === '' || $membershipStart === '0000-00-00' || $membershipStart <= $today)
        ) {
            return ['eligible' => true, 'access_type' => 'monthly', 'daily_pass_id' => null];
        }
    }

    $pendingPayment = false;
    $unpaidCash = false;
    $rejectedPayment = false;
    $invalidPaidPass = false;
    $expiredPaidPass = false;

    foreach ($dailyPasses as $pass) {
        $visitDate = (string)($pass['visit_date'] ?? '');
        if ($visitDate !== $today) {
            if ($visitDate < $today && strtolower(trim((string)($pass['payment_status'] ?? ''))) === 'paid') {
                $expiredPaidPass = true;
            }
            continue;
        }

        $paymentStatus = strtolower(trim((string)($pass['payment_status'] ?? '')));
        if ($paymentStatus === 'pending') {
            $pendingPayment = true;
            continue;
        }
        if ($paymentStatus === 'unpaid') {
            $unpaidCash = true;
            continue;
        }
        if ($paymentStatus === 'rejected') {
            $rejectedPayment = true;
            continue;
        }
        if ($paymentStatus !== 'paid') {
            continue;
        }

        $expiresAtValue = (string)($pass['expires_at'] ?? '');
        try {
            $expiresAt = new DateTimeImmutable($expiresAtValue, new DateTimeZone('Asia/Manila'));
            $validExpiry = $expiresAt->format('Y-m-d H:i:s') === $expiresAtValue
                && $expiresAt > $manilaNow;
        } catch (Exception $e) {
            $validExpiry = false;
        }

        if (
            (float)($pass['amount'] ?? 0) === 100.0
            && $validExpiry
            && ($pass['checked_in_at'] ?? null) === null
        ) {
            return [
                'eligible' => true,
                'access_type' => 'daily_pass',
                'daily_pass_id' => (int)$pass['id'],
            ];
        }

        $invalidPaidPass = true;
    }

    if ($pendingPayment) {
        return ['eligible' => false, 'message' => 'Payment is still pending. Please wait for admin verification.'];
    }
    if ($unpaidCash) {
        return ['eligible' => false, 'message' => 'Payment is not confirmed yet. Please wait for staff to confirm your cash payment.'];
    }
    if ($rejectedPayment) {
        return ['eligible' => false, 'message' => 'Your 1 Day Pass payment was rejected. Please contact gym staff.'];
    }
    if ($invalidPaidPass || $expiredPaidPass) {
        return ['eligible' => false, 'message' => 'Your 1 Day Pass has expired or is no longer valid.'];
    }

    if (in_array($membershipType, ['monthly', 'monthlysubscription'], true)) {
        if ($upcomingSubscriptionStart !== null) {
            return [
                'eligible' => false,
                'message' => 'Your monthly membership begins on ' . $upcomingSubscriptionStart . '.',
            ];
        }
        $membershipStart = trim((string)($member['membership_start'] ?? ''));
        if ($membershipStart > $today && $membershipStart !== '0000-00-00') {
            return ['eligible' => false, 'message' => 'Your monthly membership has not started yet.'];
        }
        return ['eligible' => false, 'message' => 'Your monthly membership has expired and you do not have a valid 1 Day Pass for today.'];
    }

    return ['eligible' => false, 'message' => 'You do not have a valid paid 1 Day Pass for today.'];
}

?>
