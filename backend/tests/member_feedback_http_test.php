<?php

$baseUrl = 'http://127.0.0.1:8766';
$stateId = bin2hex(random_bytes(10));

function feedbackRequest(string $baseUrl, string $path, string $stateId, string $role, string $method = 'GET', array $query = [], ?array $payload = null): array {
    $url = $baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = ['X-Test-State-Id: ' . $stateId, 'X-Test-Role: ' . $role];
    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 5,
    ];
    if ($payload !== null) {
        $options['header'] .= "\r\nContent-Type: application/json";
        $options['content'] = json_encode($payload);
    }
    $context = stream_context_create(['http' => $options]);
    $body = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
    return [
        'status' => (int)($statusMatch[1] ?? 0),
        'body' => json_decode((string)$body, true),
    ];
}

function assertFeedbackCase(bool $condition, string $message, array &$failures): void {
    if (!$condition) $failures[] = $message;
}

$failures = [];
$memberList = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member');
assertFeedbackCase($memberList['status'] === 200, 'member list: expected HTTP 200.', $failures);
$eligibleVisits = array_column($memberList['body']['visits'] ?? [], 'attendance_id');
assertFeedbackCase($eligibleVisits === [501, 502], 'member list: only owned, successful, non-future QR visits should be eligible.', $failures);

$gymRating = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'attendance_id' => 501,
    'feedback_category' => 'gym',
    'rating' => 5,
    'is_anonymous' => true,
]);
assertFeedbackCase($gymRating['status'] === 410, 'legacy QR-linked gym feedback: new submissions should use the dedicated daily workflow.', $failures);

$qrTrainerRating = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'attendance_id' => 501,
    'feedback_category' => 'trainer',
    'trainer_id' => 9,
    'rating' => 3,
]);
assertFeedbackCase($qrTrainerRating['status'] === 400, 'trainer feedback: QR attendance must not qualify without a completed booking.', $failures);

$duplicate = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'attendance_id' => 501,
    'feedback_category' => 'gym',
    'rating' => 1,
]);
assertFeedbackCase($duplicate['status'] === 410, 'legacy QR-linked gym feedback: submission should be disabled.', $failures);

$secondVisit = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'attendance_id' => 502,
    'feedback_category' => 'gym',
    'rating' => 4,
]);
assertFeedbackCase($secondVisit['status'] === 410, 'legacy QR-linked gym feedback: a second visit should not create a separate workflow.', $failures);

foreach ([503 => 'unlinked visit', 504 => 'another member visit', 505 => 'future visit', 999 => 'nonexistent visit'] as $attendanceId => $label) {
    $invalidVisit = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
        'attendance_id' => $attendanceId,
        'feedback_category' => 'gym',
        'rating' => 5,
    ]);
    assertFeedbackCase($invalidVisit['status'] === 410, $label . ': legacy gym submission should direct members to the daily workflow.', $failures);
}

$spoofedTrainer = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'booking_id' => 601,
    'feedback_category' => 'trainer',
    'trainer_id' => 10,
    'rating' => 3,
]);
assertFeedbackCase($spoofedTrainer['status'] === 400, 'trainer feedback: a frontend-supplied trainer ID must be rejected.', $failures);

foreach ([
    602 => 'pending booking',
    603 => 'confirmed booking',
    604 => 'cancelled booking',
    605 => 'rejected booking',
    606 => 'no-show booking',
    607 => 'another member booking',
] as $bookingId => $label) {
    $invalidBooking = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
        'booking_id' => $bookingId,
        'feedback_category' => 'trainer',
        'rating' => 4,
    ]);
    assertFeedbackCase($invalidBooking['status'] === 404, $label . ': expected HTTP 404.', $failures);
}

$wrongAssignedTrainer = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'booking_id' => 608,
    'feedback_category' => 'trainer',
    'trainer_id' => 9,
    'rating' => 4,
]);
assertFeedbackCase($wrongAssignedTrainer['status'] === 400, 'trainer feedback: a different trainer cannot be selected for a booking.', $failures);

$trainerRating = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'booking_id' => 601,
    'feedback_category' => 'trainer',
    'rating' => 3,
    'comments' => 'Helpful session.',
    'is_anonymous' => false,
]);
assertFeedbackCase($trainerRating['status'] === 201, 'completed booking: expected the assigned-trainer rating to succeed.', $failures);

$duplicateTrainerRating = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'booking_id' => 601,
    'feedback_category' => 'trainer',
    'rating' => 2,
]);
assertFeedbackCase($duplicateTrainerRating['status'] === 409, 'completed booking: duplicate trainer rating must return HTTP 409.', $failures);

$secondTrainerRating = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'booking_id' => 609,
    'feedback_category' => 'trainer',
    'rating' => 5,
    'comments' => 'Excellent coaching.',
    'is_anonymous' => true,
]);
assertFeedbackCase($secondTrainerRating['status'] === 201, 'second completed booking: a separate trainer rating should succeed.', $failures);

$assignedTrainerRating = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'booking_id' => 608,
    'feedback_category' => 'trainer',
    'rating' => 4,
]);
assertFeedbackCase($assignedTrainerRating['status'] === 201, 'completed booking: rating must use the trainer assigned to that booking.', $failures);

$invalidRating = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'attendance_id' => 502,
    'feedback_category' => 'gym',
    'rating' => 6,
]);
assertFeedbackCase($invalidRating['status'] === 410, 'legacy gym feedback: submission should be disabled regardless of rating.', $failures);

$longText = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member', 'POST', [], [
    'attendance_id' => 502,
    'feedback_category' => 'gym',
    'rating' => 4,
    'comments' => str_repeat('x', 1001),
]);
assertFeedbackCase($longText['status'] === 410, 'legacy gym feedback: submission should be disabled regardless of comment.', $failures);

$wrongMemberRole = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'admin');
assertFeedbackCase($wrongMemberRole['status'] === 403, 'member endpoint: non-member role should be denied.', $failures);

$adminSummary = feedbackRequest($baseUrl, '/admin-feedback', $stateId, 'admin');
$gymStats = $adminSummary['body']['aggregates']['gym'] ?? [];
$trainerStats = $adminSummary['body']['aggregates']['trainer'] ?? [];
assertFeedbackCase($adminSummary['status'] === 200, 'admin report: expected HTTP 200.', $failures);
assertFeedbackCase(($gymStats['total'] ?? null) === 1 && (float)($gymStats['average_rating'] ?? 0) === 1.0, 'admin report: historical gym average or total is incorrect: ' . json_encode($adminSummary['body']), $failures);
assertFeedbackCase(($trainerStats['total'] ?? null) === 3 && (float)($trainerStats['average_rating'] ?? 0) === 4.0, 'admin report: trainer average or total is incorrect: ' . json_encode($adminSummary['body']), $failures);
assertFeedbackCase(($gymStats['positive_total'] ?? null) === 0 && ($gymStats['negative_total'] ?? null) === 1, 'admin report: historical gym positive/negative counts are incorrect.', $failures);
assertFeedbackCase(($adminSummary['body']['pagination']['total'] ?? null) === 4, 'admin report: admins should be able to review feedback from all members.', $failures);
assertFeedbackCase(count(array_filter($adminSummary['body']['feedback'] ?? [], static fn(array $entry): bool => !empty($entry['booking_id']))) === 3, 'admin report: booking-linked trainer ratings should include booking references.', $failures);

$filteredReport = feedbackRequest($baseUrl, '/admin-feedback', $stateId, 'admin', 'GET', ['category' => 'gym', 'rating' => 4]);
assertFeedbackCase(($filteredReport['body']['pagination']['total'] ?? null) === 0, 'admin filters: category/rating filters did not narrow historical records.', $failures);
$anonymousReport = feedbackRequest($baseUrl, '/admin-feedback', $stateId, 'admin', 'GET', ['identity' => 'anonymous']);
assertFeedbackCase(($anonymousReport['body']['pagination']['total'] ?? null) === 1, 'admin filters: anonymous filter did not work.', $failures);
$invalidFilter = feedbackRequest($baseUrl, '/admin-feedback', $stateId, 'admin', 'GET', ['page' => '0']);
assertFeedbackCase($invalidFilter['status'] === 400, 'admin filters: invalid page input should be rejected.', $failures);

$trainerReport = feedbackRequest($baseUrl, '/admin-feedback', $stateId, 'admin', 'GET', ['category' => 'trainer', 'trainer_id' => 9]);
$trainerEntry = null;
foreach (($trainerReport['body']['feedback'] ?? []) as $entry) {
    if ($entry['feedback_category'] === 'trainer') $trainerEntry = $entry;
}
$moderation = $trainerEntry
    ? feedbackRequest($baseUrl, '/admin-feedback', $stateId, 'admin', 'POST', [], [
        'action' => 'hide',
        'feedback_id' => $trainerEntry['id'],
        'moderation_reason' => 'Contains inappropriate language.',
    ])
    : ['status' => 0, 'body' => []];
assertFeedbackCase($moderation['status'] === 200, 'admin moderation: expected the feedback to be hidden.', $failures);
$hiddenReport = feedbackRequest($baseUrl, '/admin-feedback', $stateId, 'admin', 'GET', ['moderation' => 'hidden']);
assertFeedbackCase(($hiddenReport['body']['pagination']['total'] ?? null) === 1, 'admin filters: hidden-feedback filter did not work.', $failures);

$memberHistory = feedbackRequest($baseUrl, '/member-feedback', $stateId, 'member');
assertFeedbackCase(count($memberHistory['body']['feedback'] ?? []) === 3, 'member history: expected only the member’s own trainer ratings.', $failures);
assertFeedbackCase(count(array_filter($memberHistory['body']['feedback'] ?? [], static fn(array $entry): bool => !empty($entry['booking_id']))) === 3, 'member history: completed-booking ratings should be included.', $failures);

$trainerView = feedbackRequest($baseUrl, '/trainer-feedback', $stateId, 'trainer');
assertFeedbackCase(($trainerView['body']['summary']['total'] ?? null) === 2, 'trainer view: own aggregate should include booking-linked ratings.', $failures);
assertFeedbackCase(($trainerView['body']['summary']['hidden_total'] ?? null) === 1, 'trainer view: hidden rating count should be disclosed.', $failures);
assertFeedbackCase(count($trainerView['body']['feedback'] ?? []) === 1, 'trainer view: only visible feedback for their own trainer profile should be shown.', $failures);
assertFeedbackCase(!str_contains(json_encode($trainerView['body']), 'Test Member'), 'trainer view: member identity must not be exposed.', $failures);

$wrongAdminRole = feedbackRequest($baseUrl, '/admin-feedback', $stateId, 'member');
assertFeedbackCase($wrongAdminRole['status'] === 403, 'admin endpoint: member role should be denied.', $failures);

$wrongTrainerRole = feedbackRequest($baseUrl, '/trainer-feedback', $stateId, 'member');
assertFeedbackCase($wrongTrainerRole['status'] === 403, 'trainer endpoint: member role should be denied.', $failures);

$dailyStatus = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member');
assertFeedbackCase($dailyStatus['status'] === 200, 'daily feedback: member should load status without an attendance record or booking.', $failures);
assertFeedbackCase(($dailyStatus['body']['already_submitted'] ?? null) === false, 'daily feedback: initial status should allow optional feedback.', $failures);

$invalidDailyRating = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member', 'POST', [], [
    'overall_rating' => 6,
]);
assertFeedbackCase($invalidDailyRating['status'] === 400, 'daily feedback: ratings outside 1–5 should be rejected.', $failures);

$longDailyComment = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member', 'POST', [], [
    'system_comments' => str_repeat('x', 1001),
]);
assertFeedbackCase($longDailyComment['status'] === 400, 'daily feedback: comments over 1000 characters should be rejected.', $failures);

$overallOnly = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member', 'POST', [], [
    'overall_rating' => 5,
]);
assertFeedbackCase($overallOnly['status'] === 201, 'daily feedback: an overall-only rating should succeed without attendance or booking.', $failures);

$sameDayDuplicate = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member', 'POST', [], [
    'system_comments' => 'A second submission should be blocked.',
]);
assertFeedbackCase($sameDayDuplicate['status'] === 409, 'daily feedback: a second same-day submission should return HTTP 409.', $failures);

$emptyDailyFeedback = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member', 'POST', [], []);
assertFeedbackCase($emptyDailyFeedback['status'] === 400, 'daily feedback: empty records should be rejected.', $failures);

$dailyStatePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gym_member_feedback_' . $stateId . '.json';
$dailyState = json_decode((string)file_get_contents($dailyStatePath), true);
$yesterday = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d');
foreach ($dailyState['gym_system_feedback'] as &$dailyEntry) {
    if ($dailyEntry['member_id'] === 41 && $dailyEntry['id'] !== 999) {
        $dailyEntry['feedback_date'] = $yesterday;
        $dailyEntry['submitted_at'] = $yesterday . ' 23:59:00';
    }
}
unset($dailyEntry);
file_put_contents($dailyStatePath, json_encode($dailyState, JSON_UNESCAPED_SLASHES));

$commentOnlyNextDay = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member', 'POST', [], [
    'facilities_comments' => 'The equipment area is clean.',
]);
assertFeedbackCase($commentOnlyNextDay['status'] === 201, 'daily feedback: comment-only submission should be accepted on the next Philippine calendar day.', $failures);

$multiCategoryState = json_decode((string)file_get_contents($dailyStatePath), true);
foreach ($multiCategoryState['gym_system_feedback'] as &$dailyEntry) {
    if ($dailyEntry['member_id'] === 41 && $dailyEntry['id'] !== 999) {
        $dailyEntry['feedback_date'] = $yesterday;
        $dailyEntry['submitted_at'] = $yesterday . ' 23:59:00';
    }
}
unset($dailyEntry);
file_put_contents($dailyStatePath, json_encode($multiCategoryState, JSON_UNESCAPED_SLASHES));
$multiCategory = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member', 'POST', [], [
    'overall_rating' => 4,
    'facilities_comments' => 'The equipment is in good condition.',
    'system_rating' => 5,
]);
assertFeedbackCase($multiCategory['status'] === 201, 'daily feedback: multiple optional categories should be accepted together.', $failures);

$raceState = json_decode((string)file_get_contents($dailyStatePath), true);
foreach ($raceState['gym_system_feedback'] as &$dailyEntry) {
    if ($dailyEntry['member_id'] === 41 && $dailyEntry['id'] !== 999) {
        $dailyEntry['feedback_date'] = $yesterday;
        $dailyEntry['submitted_at'] = $yesterday . ' 23:59:00';
    }
}
unset($dailyEntry);
$raceState['simulate_gym_system_feedback_race'] = true;
file_put_contents($dailyStatePath, json_encode($raceState, JSON_UNESCAPED_SLASHES));
$simultaneousDuplicate = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member', 'POST', [], [
    'services_rating' => 4,
]);
assertFeedbackCase($simultaneousDuplicate['status'] === 409, 'daily feedback: mocked unique-key race should return HTTP 409.', $failures);

$dailyHistory = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member', 'GET', ['member_id' => 42]);
assertFeedbackCase(count($dailyHistory['body']['feedback'] ?? []) === 3, 'daily feedback: history must include only the authenticated member despite a spoofed member_id query.', $failures);
assertFeedbackCase(count($dailyHistory['body']['legacy_feedback'] ?? []) === 0, 'daily feedback: legacy visit ratings from another member must not appear in history.', $failures);
assertFeedbackCase(($dailyHistory['body']['already_submitted'] ?? null) === false, 'daily feedback: status should reflect the current Manila date after moving the prior record to yesterday.', $failures);

$systemAdminReport = feedbackRequest($baseUrl, '/admin-feedback', $stateId, 'admin', 'GET', ['category' => 'gym_system']);
assertFeedbackCase($systemAdminReport['status'] === 200, 'admin report: gym/system feedback should be available separately.', $failures);
assertFeedbackCase(($systemAdminReport['body']['aggregates']['gym_system']['total'] ?? null) === 4, 'admin report: gym/system submission totals should include new and other-member records.', $failures);
assertFeedbackCase(count(array_filter($systemAdminReport['body']['feedback'] ?? [], static fn(array $entry): bool => ($entry['feedback_type'] ?? '') === 'gym_system')) === 4, 'admin report: gym/system records must be labeled separately from trainer ratings.', $failures);
$systemTrend = $systemAdminReport['body']['monthly_trends'][0] ?? [];
foreach (['overall', 'facilities', 'services', 'system'] as $section) {
    assertFeedbackCase(
        array_key_exists($section . '_average', $systemTrend) && array_key_exists($section . '_total', $systemTrend),
        'admin report: monthly trends should return separate ' . $section . ' rating data.',
        $failures
    );
    $summary = $systemAdminReport['body']['category_summaries'][$section] ?? [];
    $distributionTotal = array_sum($summary['distribution'] ?? []);
    assertFeedbackCase(
        $distributionTotal === ($summary['total'] ?? null),
        'admin report: ' . $section . ' distribution should count only provided ratings.',
        $failures
    );
}

$inactiveState = json_decode((string)file_get_contents($dailyStatePath), true);
$inactiveState['member_account_status'] = 'deactivated';
file_put_contents($dailyStatePath, json_encode($inactiveState, JSON_UNESCAPED_SLASHES));
$inactiveMember = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'member');
assertFeedbackCase($inactiveMember['status'] === 403, 'daily feedback: deactivated member accounts must not access the workflow.', $failures);

$wrongDailyRole = feedbackRequest($baseUrl, '/gym-system-feedback', $stateId, 'admin');
assertFeedbackCase($wrongDailyRole['status'] === 403, 'daily feedback: non-member roles should be denied.', $failures);

$dailyMigration = (string)file_get_contents(__DIR__ . '/../migrations/20261104_add_gym_system_feedback.sql');
assertFeedbackCase(
    str_contains($dailyMigration, 'UNIQUE KEY uq_gym_system_feedback_member_date (member_id, feedback_date)'),
    'daily feedback migration: database must enforce one submission per member per calendar date.',
    $failures
);

$statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gym_member_feedback_' . $stateId . '.json';
if (is_file($statePath)) unlink($statePath);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed trainer and legacy gym feedback cases plus mocked daily feedback, Manila date, ownership, validation, duplicate, and uniqueness-race cases.' . PHP_EOL;

?>
