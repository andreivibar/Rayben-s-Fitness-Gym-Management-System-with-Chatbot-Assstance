<?php

require_once __DIR__ . '/../config/duplicate_name_registration.php';

$normalizationCases = [
    ['  Jane   Marie ', 'jane marie'],
    ["\tJOHN\nDOE  ", 'john doe'],
    ['Élodie', 'élodie'],
];

$failures = [];
foreach ($normalizationCases as [$input, $expected]) {
    if (normalizeDuplicateNamePart($input) !== $expected) {
        $failures[] = 'normalization: ' . json_encode($input, JSON_UNESCAPED_UNICODE);
    }
}

$sameNameKeys = [
    duplicateNameRegistrationLockKey(' Jane  ', 'DOE'),
    duplicateNameRegistrationLockKey('jane', ' doe '),
];
if ($sameNameKeys[0] !== $sameNameKeys[1]) {
    $failures[] = 'case and whitespace variants must share a concurrency lock';
}

if (
    duplicateNameRegistrationLockKey('Jane', 'Doe')
    === duplicateNameRegistrationLockKey('Janet', 'Doe')
) {
    $failures[] = 'different first names must have different lock keys';
}

if (!duplicateNameExceptionMatchesSubmission(
    [
        'first_name' => 'Jane',
        'middle_name' => 'Marie',
        'last_name' => 'Doe',
        'username' => 'janedoe',
        'email' => 'jane@example.com',
        'phone' => '09123456789',
        'address' => '12 Main Street',
        'date_of_birth' => '1990-01-02',
        'membership_type' => 'monthly',
        'member_category' => 'regular',
        'membership_plan_id' => 1,
        'duration_months' => 1,
        'payment_method' => 'cash',
        'payment_amount' => '800.00',
    ],
    [
        'first_name' => ' JANE ',
        'middle_name' => ' MARIE ',
        'last_name' => 'DOE',
        'username' => 'JaneDoe',
        'email' => 'JANE@example.com',
        'phone' => '09123456789',
        'address' => '12   MAIN street',
        'date_of_birth' => '1990-01-02',
        'membership_type' => 'monthly',
        'member_category' => 'regular',
        'membership_plan_id' => 1,
        'duration_months' => 1,
        'payment_method' => 'cash',
        'payment_amount' => 800,
    ]
)) {
    $failures[] = 'authorized submission accepts only equivalent normalized details';
}

$mismatchedSubmission = [
    'first_name' => 'Jane',
    'middle_name' => 'Marie',
    'last_name' => 'Doe',
    'username' => 'janedoe',
    'email' => 'jane@example.com',
    'phone' => '09123456789',
    'address' => '12 Main Street',
    'date_of_birth' => '1990-01-02',
    'membership_type' => 'monthly',
    'member_category' => 'regular',
    'membership_plan_id' => 1,
    'duration_months' => 1,
    'payment_method' => 'cash',
    'payment_amount' => '800.00',
];
$mismatchedSubmission['payment_method'] = 'gcash';
if (duplicateNameExceptionMatchesSubmission([
    'first_name' => 'Jane',
    'middle_name' => 'Marie',
    'last_name' => 'Doe',
    'username' => 'janedoe',
    'email' => 'jane@example.com',
    'phone' => '09123456789',
    'address' => '12 Main Street',
    'date_of_birth' => '1990-01-02',
    'membership_type' => 'monthly',
    'member_category' => 'regular',
    'membership_plan_id' => 1,
    'duration_months' => 1,
    'payment_method' => 'cash',
    'payment_amount' => '800.00',
], $mismatchedSubmission)) {
    $failures[] = 'authorized submission rejects changed payment details';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed duplicate-name normalization and concurrency-key cases.' . PHP_EOL;
