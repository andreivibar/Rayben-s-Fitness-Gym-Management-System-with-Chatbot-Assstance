<?php

require_once __DIR__ . '/../config/registration_workflow.php';

$cases = [
    'pending cash does not block account approval' => [
        ['membership_type' => 'monthly', 'payment_status' => 'pending', 'member_category' => 'regular', 'discount_status' => 'not_required'],
        null,
    ],
    'unverified GCash does not block account approval' => [
        ['membership_type' => 'monthly', 'payment_status' => 'submitted', 'member_category' => 'regular', 'discount_status' => 'not_required'],
        null,
    ],
    'verified promotional document remains required' => [
        ['membership_type' => 'monthly', 'payment_status' => 'pending', 'member_category' => 'student', 'discount_status' => 'submitted'],
        'Verify the eligibility document before approving this discounted request.',
    ],
    'regular requests do not require a promo document' => [
        ['membership_type' => 'monthly', 'payment_status' => 'pending', 'member_category' => 'regular', 'discount_status' => 'not_required'],
        null,
    ],
    'daily pass is not approved as a monthly membership' => [
        ['membership_type' => 'walk-in', 'payment_status' => 'pending'],
        'Daily passes are purchased separately and cannot be approved as monthly memberships.',
    ],
];

$failures = [];
foreach ($cases as $name => [$request, $expected]) {
    if (registrationApprovalError($request) !== $expected) {
        $failures[] = $name;
    }
}

$initialMonthlyCases = [
    'new online monthly account requires combined payment confirmation' => [
        ['membership_type' => 'monthly', 'registration_source' => 'online', 'member_id' => null],
        true,
    ],
    'new walk-in monthly account requires combined payment confirmation' => [
        ['membership_type' => 'monthly', 'registration_source' => 'walk_in', 'member_id' => null],
        true,
    ],
    'existing member renewal is not an initial registration' => [
        ['membership_type' => 'monthly', 'registration_source' => 'online', 'member_id' => 42],
        false,
    ],
    'daily pass is not classified as a monthly registration' => [
        ['membership_type' => 'walk-in', 'registration_source' => 'online', 'member_id' => null],
        false,
    ],
];
foreach ($initialMonthlyCases as $name => [$request, $expected]) {
    if (isInitialMonthlyRegistrationRequest($request) !== $expected) {
        $failures[] = $name;
    }
}

$matchingPasswordHash = password_hash('Registration-test-password-2026!', PASSWORD_DEFAULT);
$ownAccountRequest = [
    'membership_type' => 'monthly',
    'registration_source' => 'online',
    'status' => 'pending',
    'email' => 'Gym.Test+Member@gmail.com',
    'password' => $matchingPasswordHash,
];
$ownAccountUser = [
    'role' => 'member',
    'email' => 'gym.test+member@gmail.com',
    'password' => $matchingPasswordHash,
];
$ownAccountCases = [
    'PDO false fetch result recognizes matching own account' => [
        false,
        $ownAccountRequest,
        $ownAccountUser,
        false,
        true,
    ],
    'null member result is not mistaken for PDO no-row result' => [
        null,
        $ownAccountRequest,
        $ownAccountUser,
        false,
        false,
    ],
    'existing member profile is not a new initial account' => [
        ['id' => 42],
        $ownAccountRequest,
        $ownAccountUser,
        false,
        false,
    ],
    'different account password hash is not accepted' => [
        false,
        $ownAccountRequest,
        ['role' => 'member', 'email' => 'gym.test+member@gmail.com', 'password' => password_hash('different-password', PASSWORD_DEFAULT)],
        false,
        false,
    ],
    'different account email is not accepted' => [
        false,
        $ownAccountRequest,
        ['role' => 'member', 'email' => 'other@example.com', 'password' => $matchingPasswordHash],
        false,
        false,
    ],
    'different account username owner role is not accepted' => [
        false,
        $ownAccountRequest,
        ['role' => 'admin', 'email' => 'gym.test+member@gmail.com', 'password' => $matchingPasswordHash],
        false,
        false,
    ],
    'existing member upgrade is not treated as an initial account' => [
        false,
        $ownAccountRequest,
        $ownAccountUser,
        true,
        false,
    ],
];
foreach ($ownAccountCases as $name => [$existingMember, $request, $user, $isUpgrade, $expected]) {
    if (isNewInitialMonthlyRegistrationAccount($isUpgrade, 'monthly', $request, $user, $existingMember) !== $expected) {
        $failures[] = $name;
    }
}

$initialDailyPassCases = [
    'linked new online daily pass with online transaction is initial approval' => [
        ['membership_type' => 'walk-in', 'registration_source' => 'online', 'member_id' => 42],
        true,
        true,
    ],
    'daily pass without an online transaction is not initial approval' => [
        ['membership_type' => 'walk-in', 'registration_source' => 'online', 'member_id' => 42],
        false,
        false,
    ],
    'existing monthly member is not a daily pass registration' => [
        ['membership_type' => 'monthly', 'registration_source' => 'online', 'member_id' => 42],
        true,
        false,
    ],
    'unlinked daily pass request is not initial account approval' => [
        ['membership_type' => 'walk-in', 'registration_source' => 'online', 'member_id' => null],
        true,
        false,
    ],
];
foreach ($initialDailyPassCases as $name => [$request, $hasOnlinePass, $expected]) {
    if (isInitialDailyPassRegistrationRequest($request, $hasOnlinePass) !== $expected) {
        $failures[] = $name;
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed ' . (count($cases) + count($initialMonthlyCases) + count($ownAccountCases) + count($initialDailyPassCases)) . ' registration approval workflow cases.' . PHP_EOL;

?>
