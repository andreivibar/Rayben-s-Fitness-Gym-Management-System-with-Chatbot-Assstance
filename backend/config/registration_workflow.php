<?php

function isInitialMonthlyRegistrationRequest(array $request): bool {
    return strtolower(trim((string)($request['membership_type'] ?? ''))) === 'monthly'
        && empty($request['member_id'])
        && in_array(strtolower(trim((string)($request['registration_source'] ?? ''))), ['online', 'walk_in'], true);
}

function isNewInitialMonthlyRegistrationAccount(
    bool $isExistingMemberUpgrade,
    string $membershipType,
    array $request,
    ?array $existingUser,
    array|false|null $existingMember
): bool {
    return !$isExistingMemberUpgrade
        && $membershipType === 'monthly'
        && strtolower(trim((string)($request['registration_source'] ?? ''))) === 'online'
        && ($request['status'] ?? null) === 'pending'
        && $existingUser !== null
        && $existingMember === false
        && strtolower(trim((string)($existingUser['role'] ?? ''))) === 'member'
        && hash_equals((string)($existingUser['password'] ?? ''), (string)($request['password'] ?? ''))
        && strtolower(trim((string)($existingUser['email'] ?? '')))
            === strtolower(trim((string)($request['email'] ?? '')));
}

function isInitialDailyPassRegistrationRequest(array $request, bool $hasOnlinePass): bool {
    return $hasOnlinePass
        && strtolower(trim((string)($request['membership_type'] ?? ''))) === 'walk-in'
        && strtolower(trim((string)($request['registration_source'] ?? ''))) === 'online'
        && !empty($request['member_id']);
}

function registrationApprovalError(array $request): ?string {
    if (strtolower(trim((string)($request['membership_type'] ?? ''))) !== 'monthly') {
        return 'Daily passes are purchased separately and cannot be approved as monthly memberships.';
    }

    $memberCategory = strtolower(trim((string)($request['member_category'] ?? 'regular')));
    $discountStatus = $request['discount_status'] ?? null;
    if ($memberCategory === 'regular') {
        if ($discountStatus !== 'not_required') {
            return 'Regular membership requests must not have a pending discount verification.';
        }
    } elseif ($discountStatus !== 'verified') {
        return 'Verify the eligibility document before approving this discounted request.';
    }

    return null;
}
