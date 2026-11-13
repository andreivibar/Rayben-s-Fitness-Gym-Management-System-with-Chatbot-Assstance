<?php

$baseUrl = 'http://127.0.0.1:8767/member-attendance-history';
$timezone = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $timezone);
$currentYear = (int)$today->format('Y');
$currentMonth = (int)$today->format('n');
$monthStart = $today->modify('first day of this month')->format('Y-m-d');
$nextMonthStart = $today->modify('first day of next month')->format('Y-m-d');
$yearStart = $today->setDate($currentYear, 1, 1)->format('Y-m-d');
$nextYearStart = $today->setDate($currentYear + 1, 1, 1)->format('Y-m-d');
$todayString = $today->format('Y-m-d');
$previousMonth = $today->modify('first day of this month')->modify('-1 month');
$fixtures = [
    ['id' => 101, 'member_id' => 41, 'attendance_date' => $todayString, 'time_in' => '08:15:00', 'time_out' => '09:30:00'],
    ['id' => 102, 'member_id' => 41, 'attendance_date' => $todayString, 'time_in' => '10:15:00', 'time_out' => null],
    ['id' => 103, 'member_id' => 41, 'attendance_date' => $today->modify('-1 day')->format('Y-m-d'), 'time_in' => '07:45:00', 'time_out' => null],
    ['id' => 104, 'member_id' => 41, 'attendance_date' => $yearStart, 'time_in' => '06:30:00', 'time_out' => '07:30:00'],
    ['id' => 105, 'member_id' => 41, 'attendance_date' => $today->modify('-1 year')->setDate($currentYear - 1, 1, 1)->format('Y-m-d'), 'time_in' => '06:00:00', 'time_out' => '07:00:00'],
    ['id' => 106, 'member_id' => 41, 'attendance_date' => $today->modify('+1 day')->format('Y-m-d'), 'time_in' => '06:00:00', 'time_out' => null],
];
$expectedCurrentMonthRows = array_values(array_filter($fixtures, static fn(array $record): bool =>
    $record['attendance_date'] >= $monthStart
    && $record['attendance_date'] < $nextMonthStart
    && $record['attendance_date'] <= $todayString
));
$expectedCurrentYearCheckIns = count(array_filter($fixtures, static fn(array $record): bool =>
    $record['attendance_date'] >= $yearStart
    && $record['attendance_date'] < $nextYearStart
    && $record['attendance_date'] <= $todayString
));
$expectedCurrentMonthDates = array_unique(array_column($expectedCurrentMonthRows, 'attendance_date'));

$cases = [
    'records' => [
        'query' => ['month' => $currentMonth, 'year' => $currentYear, 'member_id' => 42],
        'headers' => ['X-Test-Scenario: records', 'X-Test-Role: member'],
        'status' => 200,
    ],
    'empty month' => [
        'query' => ['month' => $currentMonth, 'year' => $currentYear],
        'headers' => ['X-Test-Scenario: no_records', 'X-Test-Role: member'],
        'status' => 200,
    ],
    'previous month' => [
        'query' => ['month' => $previousMonth->format('n'), 'year' => $previousMonth->format('Y')],
        'headers' => ['X-Test-Scenario: records', 'X-Test-Role: member'],
        'status' => 200,
    ],
    'role denied' => [
        'query' => ['month' => $currentMonth, 'year' => $currentYear],
        'headers' => ['X-Test-Scenario: records', 'X-Test-Role: admin'],
        'status' => 403,
    ],
    'invalid month' => [
        'query' => ['month' => 13, 'year' => $currentYear],
        'headers' => ['X-Test-Scenario: records', 'X-Test-Role: member'],
        'status' => 400,
    ],
    'future month' => [
        'query' => ['month' => $currentMonth === 12 ? 1 : $currentMonth + 1, 'year' => $currentMonth === 12 ? $currentYear + 1 : $currentYear],
        'headers' => ['X-Test-Scenario: records', 'X-Test-Role: member'],
        'status' => 400,
    ],
];

$failures = [];
foreach ($cases as $name => $case) {
    $url = $baseUrl . '?' . http_build_query($case['query']);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $case['headers']),
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
    $status = (int)($statusMatch[1] ?? 0);
    $response = json_decode((string)$body, true);
    if ($status !== $case['status'] || !is_array($response)) {
        $failures[] = $name . ': unexpected status/body (' . $status . '): ' . (string)$body;
        continue;
    }
    if ($status !== 200) {
        if (($response['success'] ?? true) !== false) $failures[] = $name . ': expected an error response.';
        continue;
    }
    if (($response['success'] ?? false) !== true) {
        $failures[] = $name . ': API returned failure: ' . json_encode($response);
        continue;
    }
    if ($name === 'records') {
        $records = $response['records'] ?? [];
        $summary = $response['summary'] ?? [];
        $expectedCount = count($expectedCurrentMonthRows);
        $expectedDays = count($expectedCurrentMonthDates);
        if (
            count($records) !== $expectedCount
            || (int)$summary['monthly_check_ins'] !== $expectedCount
            || (int)$summary['monthly_attendance_days'] !== $expectedDays
            || (int)$summary['current_year_check_ins'] !== $expectedCurrentYearCheckIns
            || ($summary['most_recent_check_in'] ?? null) !== $todayString
            || count(array_filter($records, static fn(array $record): bool => (int)$record['id'] === 201 || $record['attendance_date'] > $todayString)) !== 0
        ) {
            $failures[] = 'records: filtering, ownership, or summaries were incorrect: ' . json_encode($response, JSON_UNESCAPED_SLASHES);
            continue;
        }
        $todayRows = array_values(array_filter($records, static fn(array $record): bool => $record['attendance_date'] === $todayString));
        if (count($todayRows) !== 2 || ($todayRows[0]['time_out'] ?? null) !== '09:30:00' || ($todayRows[0]['attendance_status'] ?? null) !== 'completed' || ($todayRows[1]['attendance_status'] ?? null) !== 'checked_in') {
            $failures[] = 'records: multiple same-day check-ins or checkout details were not preserved.';
        }
    } elseif ($name === 'empty month' && ($response['records'] !== [] || (int)$response['summary']['monthly_check_ins'] !== 0 || (int)$response['summary']['monthly_attendance_days'] !== 0)) {
        $failures[] = 'empty month: expected an empty calendar and zero month totals.';
    } elseif ($name === 'previous month' && (int)$response['month'] !== (int)$case['query']['month']) {
        $failures[] = 'previous month: returned the wrong requested month.';
    }
    echo $name . ': HTTP ' . $status . PHP_EOL;
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed ' . count($cases) . ' member attendance history HTTP cases.' . PHP_EOL;

?>
