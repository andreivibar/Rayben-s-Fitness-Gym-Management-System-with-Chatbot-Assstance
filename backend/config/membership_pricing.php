<?php

const GYM_MONTHLY_RATES = [
    'regular' => 800.00,
    'student' => 700.00,
    'senior' => 700.00,
    'pwd' => 700.00,
];

const GYM_DAILY_PASS_PRICE = 100.00;

function monthlyMembershipPrice(string $category): float {
    $normalized = strtolower(trim($category));
    return GYM_MONTHLY_RATES[$normalized] ?? GYM_MONTHLY_RATES['regular'];
}

function validateMonthlyDuration($value): int {
    if (!is_int($value) && (!is_string($value) || !preg_match('/^\d+$/', $value))) {
        throw new InvalidArgumentException('Choose a whole-number membership duration.');
    }

    $durationMonths = (int)$value;
    if ($durationMonths < 1 || $durationMonths > 120) {
        throw new InvalidArgumentException('Membership duration must be between 1 and 120 months.');
    }

    return $durationMonths;
}

function monthlyMembershipTotal(string $category, int $durationMonths): float {
    $normalized = strtolower(trim($category));
    if (!array_key_exists($normalized, GYM_MONTHLY_RATES)) {
        throw new InvalidArgumentException('Choose a valid member category.');
    }

    $durationMonths = validateMonthlyDuration($durationMonths);
    $monthlyRateCents = (int)round(GYM_MONTHLY_RATES[$normalized] * 100);
    return ($monthlyRateCents * $durationMonths) / 100;
}

function monthlyDurationFromAmount($amount, string $category): ?int {
    $normalized = strtolower(trim($category));
    if (!array_key_exists($normalized, GYM_MONTHLY_RATES) || !is_numeric($amount)) {
        return null;
    }

    $monthlyRateCents = (int)round(GYM_MONTHLY_RATES[$normalized] * 100);
    $amountCents = (int)round((float)$amount * 100);
    if ($amountCents <= 0 || $amountCents % $monthlyRateCents !== 0) {
        return null;
    }

    $durationMonths = intdiv($amountCents, $monthlyRateCents);
    return $durationMonths <= 120 ? $durationMonths : null;
}

function isVerifiedOneMonthMembershipPlan(array $plan): bool {
    if (($plan['plan_code'] ?? null) !== 'monthly_1'
        || (int)($plan['duration_months'] ?? 0) !== 1) {
        return false;
    }

    foreach (GYM_MONTHLY_RATES as $category => $price) {
        if (!isset($plan[$category . '_price'])
            || round((float)$plan[$category . '_price'], 2) !== $price) {
            return false;
        }
    }

    return true;
}
