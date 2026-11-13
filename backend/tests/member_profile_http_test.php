<?php

$baseUrl = 'http://127.0.0.1:8766/member-profile';
$cases = [
    'monthly' => ['membership_type' => 'monthly', 'payment_status' => null, 'entitlement_status' => null],
    'monthly_promo' => ['membership_type' => 'monthly', 'payment_status' => null, 'entitlement_status' => null],
    'pending' => ['membership_type' => 'daily_pass', 'payment_status' => 'pending', 'entitlement_status' => 'pending'],
    'verified' => ['membership_type' => 'daily_pass', 'payment_status' => 'paid', 'entitlement_status' => 'active'],
    'expired' => ['membership_type' => 'daily_pass', 'payment_status' => 'paid', 'entitlement_status' => 'expired'],
    'scheduled' => ['membership_type' => 'daily_pass', 'payment_status' => 'paid', 'entitlement_status' => 'scheduled'],
    'other_member_email' => ['membership_type' => 'daily_pass', 'payment_status' => 'pending', 'entitlement_status' => 'pending'],
    'pending_update' => ['membership_type' => 'daily_pass', 'payment_status' => 'pending', 'entitlement_status' => 'pending'],
    'current_email' => ['membership_type' => 'daily_pass', 'payment_status' => null, 'entitlement_status' => null, 'email' => 'member-current@example.test'],
];

$failures = [];
foreach ($cases as $scenario => $expected) {
    $stateId = bin2hex(random_bytes(8));
    $requestBody = $scenario === 'other_member_email'
        ? json_encode(['email' => 'another@example.invalid'])
        : '{}';
    if ($scenario === 'pending_update') {
        $requestBody = json_encode([
            'first_name' => 'Updated',
            'middle_name' => '',
            'last_name' => 'Profile',
            'phone' => '09123456789',
            'address' => 'Updated address',
            'date_of_birth' => '1990-01-01',
        ]);
    }
    $url = $scenario === 'pending_update' ? $baseUrl . '?action=update' : $baseUrl;
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'X-Auth-Token: mocked-auth-token',
                'X-Test-Scenario: ' . $scenario,
                'X-Test-State-Id: ' . $stateId,
            ]),
            'content' => $requestBody,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $responseBody = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
    $status = (int)($statusMatch[1] ?? 0);
    $response = json_decode((string)$responseBody, true);

    $statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gym_member_profile_' . $stateId . '.json';
    if (is_file($statePath)) {
        unlink($statePath);
    }

    $member = $response['member'] ?? [];
    $pass = $member['latest_daily_pass'] ?? null;
    if (
        $status !== 200
        || ($response['success'] ?? false) !== true
        || (int)($member['id'] ?? 0) !== 41
        || ($member['membership_type'] ?? null) !== $expected['membership_type']
        || ($member['email'] ?? null) !== ($expected['email'] ?? null)
        || ($pass['payment_status'] ?? null) !== $expected['payment_status']
        || ($pass['entitlement_status'] ?? null) !== $expected['entitlement_status']
        || ($scenario === 'monthly_promo' && (
            ($member['latest_membership_request']['member_category'] ?? null) !== 'student'
            || ($member['latest_membership_request']['requested_duration_months'] ?? null) !== 3
            || ($member['latest_membership_request']['discount_status'] ?? null) !== 'submitted'
            || ($member['latest_membership_request']['payment_status'] ?? null) !== 'pending'
        ))
        || ($scenario === 'pending_update' && ($member['first_name'] ?? null) !== 'Updated')
    ) {
        $failures[] = $scenario . ': unexpected profile response, status=' . $status
            . ', body=' . json_encode($response, JSON_UNESCAPED_SLASHES);
        continue;
    }

    echo $scenario . ': HTTP 200, member=' . $member['id']
        . ', type=' . $member['membership_type']
        . ', payment=' . ($pass['payment_status'] ?? 'n/a')
        . ', profile_access=allowed' . PHP_EOL;
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed ' . count($cases) . ' profile API integration cases.' . PHP_EOL;

?>
