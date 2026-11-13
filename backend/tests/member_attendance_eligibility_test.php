<?php

require_once __DIR__ . '/../config/member_attendance_eligibility.php';

$now = new DateTimeImmutable('2026-11-03 12:00:00', new DateTimeZone('Asia/Manila'));
$today = $now->format('Y-m-d');
$tomorrow = $now->modify('+1 day')->format('Y-m-d');
$yesterday = $now->modify('-1 day')->format('Y-m-d');
$dailyPassMember = [
    'account_status' => 'active',
    'status' => 'active',
    'membership_type' => 'daily_pass',
];
$monthlyMember = [
    'account_status' => 'active',
    'status' => 'active',
    'membership_type' => 'monthly',
    'membership_end' => '2026-11-30',
];
$validPass = [
    'id' => 101,
    'visit_date' => $today,
    'expires_at' => $tomorrow . ' 00:00:00',
    'payment_status' => 'paid',
    'amount' => '100.00',
    'checked_in_at' => null,
];

$cases = [
    'pending GCash pass is denied' => [
        $dailyPassMember,
        [],
        [array_merge($validPass, ['payment_status' => 'pending'])],
        false,
        'Payment is still pending. Please wait for admin verification.',
    ],
    'rejected GCash pass is denied' => [
        $dailyPassMember,
        [],
        [array_merge($validPass, ['payment_status' => 'rejected'])],
        false,
        'Your 1 Day Pass payment was rejected. Please contact gym staff.',
    ],
    'verified pass for today is allowed' => [
        $dailyPassMember,
        [],
        [$validPass],
        true,
        'daily_pass',
    ],
    'verified expired pass is denied' => [
        $dailyPassMember,
        [],
        [array_merge($validPass, [
            'expires_at' => $today . ' 10:00:00',
        ])],
        false,
        'Your 1 Day Pass has expired or is no longer valid.',
    ],
    'paid pass for another date is denied' => [
        $dailyPassMember,
        [],
        [array_merge($validPass, ['visit_date' => $yesterday])],
        false,
        'Your 1 Day Pass has expired or is no longer valid.',
    ],
    'future pass does not grant early access' => [
        $dailyPassMember,
        [],
        [array_merge($validPass, ['visit_date' => $tomorrow, 'expires_at' => $tomorrow . ' 00:00:00'])],
        false,
        'You do not have a valid paid 1 Day Pass for today.',
    ],
    'already used pass is denied' => [
        $dailyPassMember,
        [],
        [array_merge($validPass, ['checked_in_at' => $today . ' 09:00:00'])],
        false,
        'Your 1 Day Pass has expired or is no longer valid.',
    ],
    'unconfirmed cash pass is denied' => [
        $dailyPassMember,
        [],
        [array_merge($validPass, ['payment_status' => 'unpaid'])],
        false,
        'Payment is not confirmed yet. Please wait for staff to confirm your cash payment.',
    ],
    'paid pass with an invalid amount is denied' => [
        $dailyPassMember,
        [],
        [array_merge($validPass, ['amount' => '50.00'])],
        false,
        'Your 1 Day Pass has expired or is no longer valid.',
    ],
    'active monthly subscription is allowed independently' => [
        $dailyPassMember,
        [['status' => 'active', 'starts_on' => $yesterday, 'ends_on' => $tomorrow]],
        [array_merge($validPass, ['payment_status' => 'pending'])],
        true,
        'monthly',
    ],
    'restricted member with a paid monthly period is denied' => [
        array_merge($monthlyMember, ['gym_access_status' => 'restricted']),
        [['status' => 'active', 'starts_on' => $yesterday, 'ends_on' => $tomorrow]],
        [],
        false,
        'Gym access has been restricted. Please contact gym staff.',
    ],
    'restricted member with a valid paid daily pass is denied' => [
        array_merge($dailyPassMember, ['gym_access_status' => 'restricted']),
        [],
        [$validPass],
        false,
        'Gym access has been restricted. Please contact gym staff.',
    ],
    'allowed status does not bypass invalid daily-pass payment' => [
        array_merge($dailyPassMember, ['gym_access_status' => 'allowed']),
        [],
        [array_merge($validPass, ['payment_status' => 'pending'])],
        false,
        'Payment is still pending. Please wait for admin verification.',
    ],
    'expired monthly member without a valid pass is denied' => [
        array_merge($monthlyMember, ['membership_end' => $yesterday]),
        [['status' => 'expired', 'starts_on' => $yesterday, 'ends_on' => $yesterday]],
        [],
        false,
        'Your monthly membership has expired and you do not have a valid 1 Day Pass for today.',
    ],
    'scheduled monthly subscription is denied before start' => [
        $monthlyMember,
        [['status' => 'scheduled', 'starts_on' => $tomorrow, 'ends_on' => '2026-12-03']],
        [],
        false,
        'Your monthly membership begins on ' . $tomorrow . '.',
    ],
    'legacy monthly membership with a future start is denied' => [
        array_merge($monthlyMember, ['membership_start' => $tomorrow]),
        [],
        [],
        false,
        'Your monthly membership has not started yet.',
    ],
    'legacy monthly membership remains supported without period history' => [
        $monthlyMember,
        [],
        [],
        true,
        'monthly',
    ],
];

$failures = [];
foreach ($cases as $name => [$member, $periods, $passes, $expectedEligible, $expectedResult]) {
    $result = attendanceQrEvaluateAccess($member, $periods, $passes, $now);
    $actualResult = $expectedEligible ? ($result['access_type'] ?? null) : ($result['message'] ?? null);
    if (($result['eligible'] ?? false) !== $expectedEligible || $actualResult !== $expectedResult) {
        $failures[] = $name . ': ' . json_encode($result, JSON_UNESCAPED_SLASHES);
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed ' . count($cases) . ' member attendance eligibility cases.' . PHP_EOL;

?>
