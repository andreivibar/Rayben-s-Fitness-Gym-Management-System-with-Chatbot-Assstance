<?php

require_once __DIR__ . '/../config/membership_pricing.php';
require_once __DIR__ . '/../config/membership_workflow.php';

$failures = [];
$durationCases = [1, 2, 3, 6, 12, 24, 120];
foreach ($durationCases as $duration) {
    foreach (['regular' => 800, 'student' => 700, 'senior' => 700, 'pwd' => 700] as $category => $monthlyRate) {
        $amount = monthlyMembershipTotal($category, $duration);
        $expectedAmount = $monthlyRate * $duration;
        if ($amount !== (float)$expectedAmount) {
            $failures[] = "{$category} {$duration}-month total: expected {$expectedAmount}, got {$amount}";
        }
        if (monthlyDurationFromAmount(number_format($amount, 2, '.', ''), $category) !== $duration) {
            $failures[] = "{$category} {$duration}-month request amount did not preserve its duration";
        }
    }
}

$invalidDurations = [0, 121, '1.5', 'not-a-number'];
foreach ($invalidDurations as $duration) {
    try {
        validateMonthlyDuration($duration);
        $failures[] = 'Invalid duration was accepted: ' . (string)$duration;
    } catch (InvalidArgumentException $e) {
        // Expected validation failure.
    }
}

if (validateMonthlyDuration(120) !== 120 || monthlyDurationFromAmount('96000.00', 'regular') !== 120) {
    $failures[] = 'The maximum 120-month duration was not accepted.';
}

if (monthlyDurationFromAmount('801.00', 'regular') !== null
    || monthlyDurationFromAmount('96800.00', 'regular') !== null) {
    $failures[] = 'A non-integral or over-limit amount was accepted as a subscription duration.';
}

$timezone = new DateTimeZone('Asia/Manila');
$start = new DateTimeImmutable('2024-01-31', $timezone);
$expectedEnds = [
    1 => '2024-02-29',
    2 => '2024-03-31',
    3 => '2024-04-30',
    6 => '2024-07-31',
    12 => '2025-01-31',
    120 => '2034-01-31',
];
foreach ($expectedEnds as $duration => $expectedEnd) {
    $actualEnd = addCalendarMonths($start, $duration)->format('Y-m-d');
    if ($actualEnd !== $expectedEnd) {
        $failures[] = "{$duration}-month period end: expected {$expectedEnd}, got {$actualEnd}";
    }
}
try {
    addCalendarMonths($start, 121);
    $failures[] = 'A 121-month subscription period was accepted.';
} catch (InvalidArgumentException $e) {
    // Expected validation failure.
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed monthly duration pricing, validation, legacy request derivation, and Manila calendar-month cases.' . PHP_EOL;

?>
