<?php

require_once __DIR__ . '/../config/membership_workflow.php';

$timezone = new DateTimeZone('Asia/Manila');
$today = manilaToday();
$yesterday = $today->modify('-1 day')->format('Y-m-d');
$tomorrow = $today->modify('+1 day')->format('Y-m-d');
$nextMonth = addCalendarMonths($today, 1)->format('Y-m-d');

$subscriptionCases = [
    'expired membership starts today' => [
        calculateSubscriptionPeriod($yesterday, 1),
        ['starts_on' => $today->format('Y-m-d'), 'ends_on' => $nextMonth],
    ],
    'renewal after inclusive current end starts the next day' => [
        calculateSubscriptionPeriod($today->format('Y-m-d'), 1, $today->modify('-1 month')->format('Y-m-d')),
        ['starts_on' => $tomorrow],
    ],
];

$dailyPassCases = [
    'first pass starts today' => [calculateNextDailyPassVisitDate(null, $today), $today->format('Y-m-d')],
    'past pass does not delay a new purchase' => [calculateNextDailyPassVisitDate($yesterday, $today), $today->format('Y-m-d')],
    'same-day pass schedules tomorrow' => [calculateNextDailyPassVisitDate($today->format('Y-m-d'), $today), $tomorrow],
    'future pass schedules after the latest date' => [
        calculateNextDailyPassVisitDate($tomorrow, $today),
        $today->modify('+2 days')->format('Y-m-d'),
    ],
];

$failures = [];
foreach ($subscriptionCases as $name => [$actual, $expected]) {
    foreach ($expected as $key => $value) {
        if (($actual[$key] ?? null) !== $value) {
            $failures[] = $name . ': expected ' . $key . '=' . $value . ', received ' . ($actual[$key] ?? 'null');
        }
    }
}
foreach ($dailyPassCases as $name => [$actual, $expected]) {
    if ($actual !== $expected) {
        $failures[] = $name . ': expected ' . $expected . ', received ' . $actual;
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed ' . (count($subscriptionCases) + count($dailyPassCases)) . ' membership scheduling cases.' . PHP_EOL;

?>
