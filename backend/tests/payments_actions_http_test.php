<?php

$baseUrl = 'http://127.0.0.1:8767/payments';
$cases = [
    'legacy_submit_cash_requires_request' => [
        'action' => 'submit_monthly_cash',
        'membership_plan_id' => 7,
        'idempotency_key' => '123e4567-e89b-42d3-a456-426614174000',
        'status' => 409,
        'expected_message' => 'Submit a new monthly request',
    ],
    'discount_denied' => [
        'action' => 'review_monthly_gcash',
        'id' => 600,
        'decision' => 'verified',
        'status' => 409,
        'expected_message' => 'Discount eligibility must be verified',
    ],
    'review_cash_success' => [
        'action' => 'review_monthly_cash',
        'id' => 600,
        'status' => 200,
        'expected_status' => 'paid',
    ],
    'review_gcash_success' => [
        'action' => 'review_monthly_gcash',
        'id' => 600,
        'decision' => 'verified',
        'status' => 200,
        'expected_status' => 'paid',
    ],
];

$failures = [];
foreach ($cases as $scenario => $expected) {
    $request = $expected;
    unset($request['status'], $request['expected_status'], $request['expected_message'], $request['expected_duration_months'], $request['expected_amount']);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Content-Type: application/json',
            'X-Auth-Token: mocked-auth-token',
            'X-Test-Scenario: ' . $scenario,
        ]),
        'content' => json_encode($request),
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $body = file_get_contents($baseUrl, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
    $status = (int)($statusMatch[1] ?? 0);
    $response = json_decode((string)$body, true);

    if (
        $status !== $expected['status']
        || ($response['success'] ?? false) !== ($expected['status'] < 400)
        || (isset($expected['expected_status']) && ($response['status'] ?? null) !== $expected['expected_status'])
        || (isset($expected['expected_message']) && !str_contains($response['message'] ?? '', $expected['expected_message']))
        || (isset($expected['expected_duration_months']) && (int)($response['duration_months'] ?? 0) !== $expected['expected_duration_months'])
        || (isset($expected['expected_amount']) && (float)($response['amount'] ?? 0) !== (float)$expected['expected_amount'])
        || ($expected['status'] < 400 && (empty($response['scheduled_start_date']) || empty($response['scheduled_end_date'])))
    ) {
        $failures[] = $scenario . ': unexpected HTTP response: ' . json_encode($response, JSON_UNESCAPED_SLASHES);
        continue;
    }

    echo $scenario . ': HTTP ' . $status . (!empty($response['status']) ? ', payment=' . $response['status'] : '') . PHP_EOL;
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed ' . count($cases) . ' monthly renewal HTTP action cases.' . PHP_EOL;

?>
