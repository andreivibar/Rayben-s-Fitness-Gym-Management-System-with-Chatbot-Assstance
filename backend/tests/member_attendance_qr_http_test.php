<?php

$baseUrl = 'http://127.0.0.1:8765/attendance';
$token = str_repeat('a', 64);
$testCases = [
    'pending' => [403, false, 'Payment is still pending. Please wait for admin verification.'],
    'pending_preview' => [403, false, 'Payment is still pending. Please wait for admin verification.'],
    'rejected' => [403, false, 'Your 1 Day Pass payment was rejected. Please contact gym staff.'],
    'expired_pass' => [403, false, 'Your 1 Day Pass has expired or is no longer valid.'],
    'unpaid_cash' => [403, false, 'Payment is not confirmed yet. Please wait for staff to confirm your cash payment.'],
    'verified_pass' => [200, true, 'time_in'],
    'active_monthly' => [200, true, 'time_in'],
    'expired_monthly' => [403, false, 'Your monthly membership has expired and you do not have a valid 1 Day Pass for today.'],
    'restricted_daily_pass' => [403, false, 'Gym access has been restricted. Please contact gym staff.'],
    'restricted_monthly' => [403, false, 'Gym access has been restricted. Please contact gym staff.'],
];

$failures = [];
foreach ($testCases as $scenario => [$expectedStatus, $expectedSuccess, $expectedMessage]) {
    $stateId = bin2hex(random_bytes(8));
    $operation = str_ends_with($scenario, '_preview') ? 'preview' : 'confirm';
    $body = json_encode([
        'operation' => $operation,
        'token' => $token,
        'expected_action' => 'time_in',
    ]);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'X-Auth-Token: mocked-auth-token',
                'X-Test-Scenario: ' . $scenario,
                'X-Test-State-Id: ' . $stateId,
            ]),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $responseBody = file_get_contents($baseUrl, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
    $actualStatus = (int)($statusMatch[1] ?? 0);
    $response = json_decode((string)$responseBody, true);

    $statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gym_attendance_qr_' . $stateId . '.json';
    $state = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : null;
    if (is_file($statePath)) {
        unlink($statePath);
    }

    $actualMessage = $expectedSuccess
        ? ($response['action'] ?? null)
        : ($response['message'] ?? null);
    if (
        $actualStatus !== $expectedStatus
        || ($response['success'] ?? null) !== $expectedSuccess
        || $actualMessage !== $expectedMessage
        || !is_array($state)
    ) {
        $failures[] = $scenario . ': unexpected HTTP response or missing state snapshot; status='
            . $actualStatus . '; body=' . json_encode($response) . '; state='
            . json_encode($state, JSON_UNESCAPED_SLASHES);
        continue;
    }

    $expectedWrites = $expectedSuccess && $operation === 'confirm' ? 1 : 0;
    $expectedRateLimit = $expectedSuccess ? 1 : 0;
    if (
        count($state['attendance']) !== $expectedWrites
        || count($state['token_uses']) !== $expectedWrites
        || $state['rate_limit_count'] !== $expectedRateLimit
    ) {
        $failures[] = $scenario . ': attendance/token/rate-limit writes were unexpected.';
        continue;
    }
    if ($expectedSuccess && $operation === 'confirm'
        && (int)($state['token_uses'][0]['attendance_id'] ?? 0) !== (int)$state['attendance'][0]['id']) {
        $failures[] = $scenario . ': successful QR check-in was not linked to its attendance record.';
        continue;
    }
    if ($scenario === 'verified_pass' && $state['daily_passes'][0]['checked_in_at'] === null) {
        $failures[] = 'verified_pass: pass was not atomically marked as used.';
        continue;
    }

    echo $scenario . ': HTTP ' . $actualStatus . ', ' . ($expectedSuccess ? 'allowed' : 'denied')
        . ', attendance=' . count($state['attendance'])
        . ', token_uses=' . count($state['token_uses'])
        . ', rate_limit=' . $state['rate_limit_count'] . PHP_EOL;
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed ' . count($testCases) . ' HTTP integration cases.' . PHP_EOL;

?>
