<?php

require_once __DIR__ . '/../config/member_chatbot.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$runScriptedRequest = static function (array $responses, array &$sleeps, array &$timeoutLimits = []): array {
    $calls = 0;
    $result = memberChatGeminiRequestWithRetries(
        static function (int $timeoutMilliseconds) use (&$calls, $responses, &$timeoutLimits): array {
            $timeoutLimits[] = $timeoutMilliseconds;
            $response = $responses[min($calls, count($responses) - 1)];
            $calls++;
            return $response;
        },
        static function (int $milliseconds) use (&$sleeps): void {
            $sleeps[] = $milliseconds;
        }
    );
    $result['calls'] = $calls;
    return $result;
};

$temporary = ['http_status' => 503, 'provider_status' => 'UNAVAILABLE'];
$success = ['http_status' => 200, 'provider_status' => ''];
$sleeps = [];
$result = $runScriptedRequest([$temporary, $temporary, $success], $sleeps);
$assert($result['calls'] === 3 && $result['http_status'] === 200, '503 failures should retry twice and accept a successful response.');
$assert($sleeps === [250, 500], 'Retries without Retry-After should use short exponential delays.');

$sleeps = [];
$rateLimited = ['http_status' => 429, 'provider_status' => 'RESOURCE_EXHAUSTED', 'retry_after' => '1'];
$result = $runScriptedRequest([$rateLimited, $success], $sleeps);
$assert($result['calls'] === 2 && $sleeps === [1000], '429 should respect Retry-After.');

foreach ([
    ['http_status' => 403, 'provider_status' => 'PERMISSION_DENIED'],
    ['http_status' => 404, 'provider_status' => 'NOT_FOUND'],
    ['http_status' => 400, 'provider_status' => 'INVALID_ARGUMENT'],
] as $permanentFailure) {
    $sleeps = [];
    $result = $runScriptedRequest([$permanentFailure, $success], $sleeps);
    $assert($result['calls'] === 1 && $sleeps === [], 'Permanent API errors must not be retried.');
}

$sleeps = [];
$longRetryAfter = ['http_status' => 503, 'provider_status' => 'UNAVAILABLE', 'retry_after' => '10'];
$result = $runScriptedRequest([$longRetryAfter, $success], $sleeps);
$assert($result['calls'] === 1 && $sleeps === [], 'A long Retry-After must not be retried early or block a PHP worker.');

$sleeps = [];
$result = $runScriptedRequest([$temporary], $sleeps);
$assert($result['calls'] === 3 && $result['http_status'] === 503, 'Persistent temporary errors should stop after three total attempts.');

$sleeps = [];
$timeoutLimits = [];
$timeout = ['http_status' => 0, 'curl_error_number' => CURLE_OPERATION_TIMEDOUT];
$result = $runScriptedRequest([$timeout, $success], $sleeps, $timeoutLimits);
$assert($result['calls'] === 2 && $result['http_status'] === 200, 'A cURL operation timeout should receive one bounded retry.');
$assert($sleeps === [250], 'A timeout retry should use a short backoff.');
$assert($timeoutLimits[0] === 60000 && $timeoutLimits[1] <= 60000, 'Each request timeout must be capped at 60 seconds.');

$sleeps = [];
$result = $runScriptedRequest([$timeout], $sleeps);
$assert($result['calls'] === 2 && $result['curl_error_number'] === CURLE_OPERATION_TIMEDOUT, 'Repeated timeouts must stop after one retry.');

$sleeps = [];
$otherTransportFailure = ['http_status' => 0, 'curl_error_number' => CURLE_COULDNT_CONNECT];
$result = $runScriptedRequest([$otherTransportFailure, $success], $sleeps);
$assert($result['calls'] === 1 && $sleeps === [], 'Non-timeout cURL errors must not be retried.');

$sleeps = [];
$permanentTimeout = [
    'http_status' => 403,
    'provider_status' => 'PERMISSION_DENIED',
    'curl_error_number' => CURLE_OPERATION_TIMEDOUT,
];
$result = $runScriptedRequest([$permanentTimeout, $success], $sleeps);
$assert($result['calls'] === 1 && $sleeps === [], 'A timed-out response with a permanent provider error must not be retried.');

$sleeps = [];
$result = memberChatGeminiRequestWithRetries(
    static fn (int $timeoutMilliseconds): array => $timeout,
    static function (int $milliseconds) use (&$sleeps): void {
        $sleeps[] = $milliseconds;
    },
    1
);
$assert($result['curl_error_number'] === CURLE_OPERATION_TIMEDOUT && $sleeps === [], 'The overall time budget must prevent a retry when no time remains.');

$assert(memberChatGeminiShouldRetry(503, 'INVALID_ARGUMENT') === false, 'Invalid request errors must not be retried even with HTTP 503.');
$assert(memberChatGeminiRetryDelayMilliseconds(1, '') === 250, 'First retry delay should be 250 ms.');
$assert(memberChatGeminiRetryDelayMilliseconds(2, '') === 500, 'Second retry delay should be 500 ms.');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed 18 Gemini retry handling checks.' . PHP_EOL;
