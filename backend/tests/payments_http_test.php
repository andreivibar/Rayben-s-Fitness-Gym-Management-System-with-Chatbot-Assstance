<?php

$baseUrl = 'http://127.0.0.1:8767/payments';
$cases = [
    'pending_gcash' => ['status' => 'pending', 'received' => 0, 'amount' => 100, 'count' => 1],
    'rejected_gcash' => ['status' => 'failed', 'received' => 0, 'amount' => 100, 'count' => 1],
    'verified_gcash' => ['status' => 'paid', 'received' => 100, 'amount' => 100, 'count' => 1],
    'expired_pass' => ['status' => 'paid', 'received' => 100, 'amount' => 100, 'count' => 1],
    'future_pass' => ['status' => 'paid', 'received' => 100, 'amount' => 100, 'count' => 1],
    'pending_cash' => ['status' => 'pending', 'received' => 0, 'amount' => 100, 'count' => 1],
    'confirmed_cash' => ['status' => 'paid', 'received' => 100, 'amount' => 100, 'count' => 1],
    'invalid_paid_amount' => ['status' => 'paid', 'received' => 0, 'amount' => 125, 'count' => 1],
    'multiple' => ['status' => 'mixed', 'received' => 100, 'amount' => 100, 'count' => 2],
    'monthly_and_pass' => ['status' => 'paid', 'received' => 900, 'amount' => 100, 'count' => 2],
    'ownership' => ['status' => 'paid', 'received' => 100, 'amount' => 100, 'count' => 1],
    'staff_view' => ['status' => 'paid', 'received' => 100, 'amount' => 100, 'count' => 1],
    'monthly' => ['status' => 'paid', 'received' => 800, 'amount' => 800, 'count' => 1],
];

$failures = [];
foreach ($cases as $scenario => $expected) {
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "X-Auth-Token: mocked-auth-token\r\nX-Test-Scenario: {$scenario}",
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $body = file_get_contents($baseUrl, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
    $status = (int)($statusMatch[1] ?? 0);
    $response = json_decode((string)$body, true);
    $payments = $response['payments'] ?? [];
    $dailyPasses = array_values(array_filter($payments, static fn(array $payment): bool => ($payment['record_type'] ?? '') === 'daily_pass'));
    $monthlyPayments = array_values(array_filter($payments, static fn(array $payment): bool => ($payment['record_type'] ?? '') !== 'daily_pass'));

    $payment = $dailyPasses[0] ?? ($monthlyPayments[0] ?? []);
    $received = (float)($response['summary']['total_paid'] ?? -1);
    $pendingAmount = (float)($response['summary']['pending_amount'] ?? -1);
    $isAccessExpected = in_array($scenario, ['verified_gcash', 'confirmed_cash'], true);
    if (
        $status !== 200
        || ($response['success'] ?? false) !== true
        || count($payments) !== $expected['count']
        || $received !== (float)$expected['received']
        || (float)($payment['amount'] ?? -1) !== (float)$expected['amount']
        || ($expected['status'] !== 'mixed' && $scenario !== 'monthly' && ($payment['status'] ?? null) !== $expected['status'])
        || ($scenario === 'verified_gcash' && (($payment['pass_start_date'] ?? null) !== ($payment['pass_end_date'] ?? null)))
        || ($isAccessExpected && ($payment['access_eligible'] ?? false) !== true)
        || ($scenario === 'expired_pass' && ($payment['access_eligible'] ?? true) !== false)
        || ($scenario === 'future_pass' && (($payment['access_eligible'] ?? true) !== false || ($payment['entitlement_status'] ?? null) !== 'scheduled'))
        || (in_array($scenario, ['pending_gcash', 'pending_cash', 'rejected_gcash'], true) && $received !== 0.0)
        || (in_array($scenario, ['pending_gcash', 'pending_cash', 'rejected_gcash'], true) && ($payment['access_eligible'] ?? true) !== false)
        || ($scenario === 'ownership' && (int)($payment['member_id'] ?? 0) !== 41)
        || ($scenario === 'staff_view' && (($payment['member_name'] ?? null) !== 'Test Member' || ($payment['verified_by_name'] ?? null) !== 'Verifier' || ($payment['record_reference'] ?? null) !== 'DP-113' || ($payment['reference_no'] ?? null) !== 'GCASH-113'))
        || ($scenario === 'multiple' && count(array_unique(array_column($dailyPasses, 'id'))) !== 2)
        || ($scenario === 'multiple' && (count(array_filter($dailyPasses, static fn(array $row): bool => $row['status'] === 'paid')) !== 1 || count(array_filter($dailyPasses, static fn(array $row): bool => $row['status'] === 'pending')) !== 1))
        || ($scenario === 'monthly' && (count($monthlyPayments) !== 1 || $pendingAmount !== 0.0))
        || ($scenario === 'monthly_and_pass' && (count($monthlyPayments) !== 1 || count($dailyPasses) !== 1))
    ) {
        $failures[] = $scenario . ': unexpected HTTP response: ' . json_encode($response, JSON_UNESCAPED_SLASHES);
        continue;
    }
    echo $scenario . ': HTTP 200, rows=' . count($payments) . ', received=' . $received . PHP_EOL;
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed ' . count($cases) . ' payment API integration cases.' . PHP_EOL;

?>
