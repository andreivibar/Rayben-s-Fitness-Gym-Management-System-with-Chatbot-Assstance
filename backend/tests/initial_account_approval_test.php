<?php

require_once __DIR__ . '/../config/initial_account_approval.php';

$cases = [
    'pending daily pass account approval is restricted even if payment is paid' => [
        ['status' => 'pending', 'payment_status' => 'paid'],
        true,
    ],
    'pending monthly account approval is restricted even if payment is verified' => [
        ['status' => 'pending', 'application_type' => 'monthly', 'payment_status' => 'verified'],
        true,
    ],
    'rejected initial account approval remains restricted' => [
        ['status' => 'rejected'],
        true,
    ],
    'rejected monthly account remains restricted' => [
        ['status' => 'rejected', 'application_type' => 'monthly'],
        true,
    ],
    'approved monthly account is unrestricted independently of payment state' => [
        ['status' => 'approved', 'payment_status' => 'pending'],
        false,
    ],
    'unrecognized initial approval state fails closed' => [
        ['status' => 'unexpected'],
        true,
    ],
    'members without an initial daily pass request are unaffected' => [
        null,
        false,
    ],
];

$failures = [];
foreach ($cases as $name => [$approval, $expected]) {
    if (requiresInitialAccountApproval($approval) !== $expected) {
        $failures[] = $name;
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed ' . count($cases) . ' initial account approval cases.' . PHP_EOL;

?>
