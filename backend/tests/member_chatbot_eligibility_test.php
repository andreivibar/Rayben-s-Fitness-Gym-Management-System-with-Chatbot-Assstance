<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/member_chatbot.php';

$conn->exec(
    "CREATE TEMPORARY TABLE membership_subscription_periods (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        member_id INT NOT NULL,
        registration_request_id INT NULL,
        payment_id INT NULL,
        starts_on DATE NOT NULL,
        ends_on DATE NOT NULL,
        status VARCHAR(16) NOT NULL
    ) ENGINE=InnoDB"
);
$conn->exec(
    "CREATE TEMPORARY TABLE payments (
        id INT NOT NULL PRIMARY KEY,
        status VARCHAR(16) NOT NULL,
        verification_status VARCHAR(16) NOT NULL
    ) ENGINE=InnoDB"
);
$conn->exec(
    "CREATE TEMPORARY TABLE registration_requests (
        id INT NOT NULL PRIMARY KEY,
        status VARCHAR(16) NOT NULL,
        payment_status VARCHAR(16) NOT NULL,
        discount_status VARCHAR(16) NOT NULL
    ) ENGINE=InnoDB"
);

$today = manilaToday();
$validStart = $today->modify('-1 day')->format('Y-m-d');
$validEnd = $today->modify('+1 month')->format('Y-m-d');
$tomorrow = $today->modify('+1 day')->format('Y-m-d');
$yesterday = $today->modify('-1 day')->format('Y-m-d');
$cases = [
    'daily pass only is denied' => [
        'periods' => [],
        'expected' => false,
    ],
    'pending-only monthly subscription is denied' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'pending', 'verification_status' => 'pending'],
        'registration' => null,
        'expected' => false,
    ],
    'unpaid monthly subscription is denied' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'unpaid', 'verification_status' => 'not_required'],
        'registration' => null,
        'expected' => false,
    ],
    'unverified monthly subscription is denied' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'pending'],
        'registration' => null,
        'expected' => false,
    ],
    'expired monthly subscription only is denied' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $yesterday, 'ends_on' => $yesterday, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'verified'],
        'registration' => null,
        'expected' => false,
    ],
    'active paid monthly subscription plus pending renewal qualifies' => [
        'periods' => [
            [
                'period' => ['starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
                'payment' => ['status' => 'paid', 'verification_status' => 'verified'],
                'registration' => null,
            ],
            [
                'period' => ['starts_on' => $tomorrow, 'ends_on' => $validEnd, 'status' => 'active'],
                'payment' => ['status' => 'pending', 'verification_status' => 'pending'],
                'registration' => null,
            ],
        ],
        'expected' => true,
    ],
    'expired previous subscription plus pending renewal is denied' => [
        'periods' => [
            [
                'period' => ['starts_on' => $yesterday, 'ends_on' => $yesterday, 'status' => 'active'],
                'payment' => ['status' => 'paid', 'verification_status' => 'verified'],
                'registration' => null,
            ],
            [
                'period' => ['starts_on' => $tomorrow, 'ends_on' => $validEnd, 'status' => 'active'],
                'payment' => ['status' => 'pending', 'verification_status' => 'pending'],
                'registration' => null,
            ],
        ],
        'expected' => false,
    ],
    'active approved promo monthly subscription with verified payment qualifies' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'verified'],
        'registration' => ['status' => 'approved', 'payment_status' => 'verified', 'discount_status' => 'verified'],
        'expected' => true,
    ],
    'paid cash monthly subscription qualifies' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'not_required'],
        'registration' => null,
        'expected' => true,
    ],
    'verified GCash monthly subscription qualifies' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'verified'],
        'registration' => null,
        'expected' => true,
    ],
    'pending payment is denied' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'pending', 'verification_status' => 'pending'],
        'registration' => null,
        'expected' => false,
    ],
    'unverified GCash payment is denied' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'pending'],
        'registration' => null,
        'expected' => false,
    ],
    'future subscription is denied' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $tomorrow, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'not_required'],
        'registration' => null,
        'expected' => false,
    ],
    'expired subscription is denied' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $yesterday, 'ends_on' => $yesterday, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'not_required'],
        'registration' => null,
        'expected' => false,
    ],
    'superseded subscription is denied' => [
        'period' => ['registration_request_id' => null, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'superseded'],
        'payment' => ['status' => 'paid', 'verification_status' => 'not_required'],
        'registration' => null,
        'expected' => false,
    ],
    'unapproved application is denied' => [
        'period' => ['registration_request_id' => 1, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'verified'],
        'registration' => ['status' => 'pending', 'payment_status' => 'verified', 'discount_status' => 'not_required'],
        'expected' => false,
    ],
    'unverified promotional document is denied' => [
        'period' => ['registration_request_id' => 2, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'verified'],
        'registration' => ['status' => 'approved', 'payment_status' => 'verified', 'discount_status' => 'pending'],
        'expected' => false,
    ],
    'approved verified promotional application qualifies' => [
        'period' => ['registration_request_id' => 3, 'starts_on' => $validStart, 'ends_on' => $validEnd, 'status' => 'active'],
        'payment' => ['status' => 'paid', 'verification_status' => 'verified'],
        'registration' => ['status' => 'approved', 'payment_status' => 'verified', 'discount_status' => 'verified'],
        'expected' => true,
    ],
];

$failures = [];
$results = [];
foreach ($cases as $name => $case) {
    $id = array_search($name, array_keys($cases), true) + 1;
    $periodCases = $case['periods'] ?? [$case];
    foreach ($periodCases as $periodIndex => $periodCase) {
        $recordId = ($id * 10) + $periodIndex + 1;
        $conn->prepare(
            'INSERT INTO payments (id, status, verification_status) VALUES (:id, :status, :verification_status)'
        )->execute([
            ':id' => $recordId,
            ':status' => $periodCase['payment']['status'],
            ':verification_status' => $periodCase['payment']['verification_status'],
        ]);

        $registrationId = $periodCase['registration'] === null ? null : $recordId;
        if ($periodCase['registration'] !== null) {
            $conn->prepare(
                'INSERT INTO registration_requests (id, status, payment_status, discount_status)
                 VALUES (:id, :status, :payment_status, :discount_status)'
            )->execute([
                ':id' => $registrationId,
                ':status' => $periodCase['registration']['status'],
                ':payment_status' => $periodCase['registration']['payment_status'],
                ':discount_status' => $periodCase['registration']['discount_status'],
            ]);
        }

        $conn->prepare(
            'INSERT INTO membership_subscription_periods
                (id, member_id, registration_request_id, payment_id, starts_on, ends_on, status)
             VALUES (:id, :member_id, :registration_request_id, :payment_id, :starts_on, :ends_on, :status)'
        )->execute([
            ':id' => $recordId,
            ':member_id' => $id,
            ':registration_request_id' => $registrationId,
            ':payment_id' => $recordId,
            ':starts_on' => $periodCase['period']['starts_on'],
            ':ends_on' => $periodCase['period']['ends_on'],
            ':status' => $periodCase['period']['status'],
        ]);
    }

    $actual = memberChatHasActiveMonthlySubscription($conn, $id);
    $results[] = $name . ': ' . ($actual ? 'allowed' : 'denied');
    if ($actual !== $case['expected']) {
        $failures[] = $name . ': expected ' . ($case['expected'] ? 'eligible' : 'ineligible')
            . ', got ' . ($actual ? 'eligible' : 'ineligible');
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo implode(PHP_EOL, $results) . PHP_EOL;
echo 'Passed ' . count($cases) . ' monthly subscription eligibility cases.' . PHP_EOL;
