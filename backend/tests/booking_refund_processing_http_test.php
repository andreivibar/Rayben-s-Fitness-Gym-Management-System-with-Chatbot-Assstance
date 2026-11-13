<?php

$databaseName = getenv('BOOKING_REFUND_TEST_DB') ?: '';
$host = getenv('BOOKING_REFUND_TEST_HOST') ?: '';
$port = getenv('BOOKING_REFUND_TEST_PORT') ?: '';
$baseUrl = rtrim(getenv('BOOKING_REFUND_TEST_URL') ?: '', '/');
$username = getenv('BOOKING_REFUND_TEST_USER') ?: 'root';
$password = getenv('BOOKING_REFUND_TEST_PASSWORD') ?: '';

if (!preg_match('/^gym_trainer_role_test_[a-z0-9_]+$/D', $databaseName)
    || $host !== '127.0.0.1'
    || !in_array($port, ['13317', '13319'], true)
    || $baseUrl === '') {
    fwrite(STDERR, "Refund processing test requires an isolated, explicitly named disposable database and HTTP endpoint.\n");
    exit(2);
}

$conn = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$selectedDatabase = $conn->query('SELECT DATABASE()')->fetchColumn();
$selectedPort = (string)$conn->query('SELECT @@port')->fetchColumn();
if ($selectedDatabase !== $databaseName || $selectedPort !== $port) {
    throw new RuntimeException('Refund tests require the selected disposable schema on the allowlisted isolated port.');
}

$conn->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'trainer', 'member', 'staff') NOT NULL");
$assertions = 0;
$suffix = bin2hex(random_bytes(4));

function refundTestAssert(bool $condition, string $description): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    $assertions++;
    echo 'PASS: ' . $description . PHP_EOL;
}

function refundTestHttp(string $method, string $path, ?array $payload, string $token): array
{
    global $baseUrl;
    $headers = [
        'Content-Type: application/json',
        'X-Auth-Token: ' . $token,
        'Authorization: Bearer ' . $token,
    ];
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $body = file_get_contents($baseUrl . $path, false, $context);
    if (!is_string($body)) {
        throw new RuntimeException('Unable to call the isolated refund API endpoint.');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Refund API returned non-JSON data: ' . $body);
    }
    return ['status' => $status, 'body' => $decoded];
}

function refundTestCreateUser(PDO $conn, string $role, string $suffix, string $token): int
{
    $stmt = $conn->prepare(
        'INSERT INTO users
            (first_name, last_name, full_name, username, email, password, role, auth_token)
         VALUES (:first_name, :last_name, :full_name, :username, :email, :password, :role, :auth_token)'
    );
    $stmt->execute([
        ':first_name' => ucfirst($role),
        ':last_name' => 'RefundTest',
        ':full_name' => ucfirst($role) . ' RefundTest',
        ':username' => 'refund' . $role . $suffix,
        ':email' => 'refund-' . $role . '-' . $suffix . '@example.test',
        ':password' => password_hash('Synthetic-refund-test-123', PASSWORD_DEFAULT),
        ':role' => $role,
        ':auth_token' => $token,
    ]);
    return (int)$conn->lastInsertId();
}

function refundTestMonthlyRevenue(array $response, string $month): float
{
    if (($response['status'] ?? 0) !== 200 || !isset($response['body']['monthly_revenue'])) {
        throw new RuntimeException('Admin revenue reporting did not return monthly revenue: ' . json_encode($response));
    }
    foreach ($response['body']['monthly_revenue'] as $row) {
        if (($row['revenue_month'] ?? '') === $month) {
            return (float)$row['revenue'];
        }
    }
    return 0.0;
}

$tokens = [
    'admin' => bin2hex(random_bytes(32)),
    'staff' => bin2hex(random_bytes(32)),
    'trainer' => bin2hex(random_bytes(32)),
    'member' => bin2hex(random_bytes(32)),
];
$userIds = [];
foreach ($tokens as $role => $token) {
    $userIds[$role] = refundTestCreateUser($conn, $role, $suffix, $token);
}

$memberInsert = $conn->prepare(
    "INSERT INTO members
        (user_id, member_code, full_name, email, membership_type, member_category,
         membership_start, membership_end, status, account_status)
     VALUES
        (:user_id, :member_code, :full_name, :email, 'monthly', 'regular',
         CURRENT_DATE, DATE_ADD(CURRENT_DATE, INTERVAL 1 YEAR), 'active', 'active')"
);
$memberInsert->execute([
    ':user_id' => $userIds['member'],
    ':member_code' => 'RFM-' . $suffix,
    ':full_name' => 'Refund Test Member',
    ':email' => 'refund-member-' . $suffix . '@example.test',
]);
$memberId = (int)$conn->lastInsertId();

$trainerInsert = $conn->prepare(
    'INSERT INTO trainers (user_id, trainer_code, status) VALUES (:user_id, :code, "active")'
);
$trainerInsert->execute([':user_id' => $userIds['trainer'], ':code' => 'RFT-' . $suffix]);
$trainerId = (int)$conn->lastInsertId();

$baselineResponse = refundTestHttp('GET', '/stats.php', null, $tokens['admin']);
$month = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m');
$baselineRevenue = refundTestMonthlyRevenue($baselineResponse, $month);

$fixture = [];
foreach (['cash' => 125.00, 'gcash' => 175.00] as $method => $amount) {
    $bookingInsert = $conn->prepare(
        "INSERT INTO bookings
            (booking_code, member_id, trainer_id, booking_date, start_time, end_time,
             session_type, duration_minutes, status, created_by_user_id, updated_by_user_id)
         VALUES
            (:booking_code, :member_id, :trainer_id, CURRENT_DATE, '09:00:00', '10:00:00',
             'Personal Training', 60, 'cancelled', :created_by, :updated_by)"
    );
    $bookingInsert->execute([
        ':booking_code' => 'RFB-' . strtoupper($method) . '-' . $suffix,
        ':member_id' => $memberId,
        ':trainer_id' => $trainerId,
        ':created_by' => $userIds['member'],
        ':updated_by' => $userIds['member'],
    ]);
    $bookingId = (int)$conn->lastInsertId();

    $paymentInsert = $conn->prepare(
        "INSERT INTO payments
            (member_id, booking_id, recorded_by, amount, payment_type, status, payment_date,
             reference_no, payment_method, payment_channel, verification_status, verified_by, verified_at)
         VALUES
            (:member_id, :booking_id, :recorded_by, :amount, 'training_session', 'paid', CURRENT_DATE,
             :reference_no, :payment_method, :payment_channel, :verification_status, :verified_by, :verified_at)"
    );
    $paymentInsert->execute([
        ':member_id' => $memberId,
        ':booking_id' => $bookingId,
        ':recorded_by' => $userIds['admin'],
        ':amount' => $amount,
        ':reference_no' => 'SYNTHETIC-' . strtoupper($method) . '-' . $suffix,
        ':payment_method' => $method,
        ':payment_channel' => $method === 'gcash' ? 'gcash' : 'counter',
        ':verification_status' => $method === 'gcash' ? 'verified' : 'not_required',
        ':verified_by' => $method === 'gcash' ? $userIds['admin'] : null,
        ':verified_at' => $method === 'gcash' ? date('Y-m-d H:i:s') : null,
    ]);
    $paymentId = (int)$conn->lastInsertId();

    $refundInsert = $conn->prepare(
        "INSERT INTO booking_refunds
            (booking_id, payment_id, member_id, amount, payment_method, status, request_reason, created_by_user_id)
         VALUES (:booking_id, :payment_id, :member_id, :amount, :payment_method, 'pending', 'Synthetic verification only', :created_by)"
    );
    $refundInsert->execute([
        ':booking_id' => $bookingId,
        ':payment_id' => $paymentId,
        ':member_id' => $memberId,
        ':amount' => $amount,
        ':payment_method' => $method,
        ':created_by' => $userIds['member'],
    ]);
    $fixture[$method] = [
        'booking_id' => $bookingId,
        'payment_id' => $paymentId,
        'refund_id' => (int)$conn->lastInsertId(),
        'amount' => $amount,
        'payment_before' => $conn->query('SELECT id, amount, status, payment_method, payment_date, reference_no, verification_status, verified_by, verified_at FROM payments WHERE id = ' . $paymentId)->fetch(),
    ];
}

$noReference = refundTestHttp('POST', '/payments.php', [
    'action' => 'process_booking_refund',
    'refund_id' => $fixture['cash']['refund_id'],
    'confirm_external_refund' => true,
], $tokens['admin']);
refundTestAssert($noReference['status'] === 400, 'processing rejects a missing external receipt/reference');

foreach (['staff', 'trainer', 'member'] as $role) {
    $forbidden = refundTestHttp('POST', '/payments.php', [
        'action' => 'process_booking_refund',
        'refund_id' => $fixture['cash']['refund_id'],
        'external_reference' => 'SYNTHETIC-DENIED-' . strtoupper($role) . '-' . $suffix,
        'confirm_external_refund' => true,
    ], $tokens[$role]);
    refundTestAssert($forbidden['status'] === 403, $role . ' cannot process booking refunds');
}

$notApproved = refundTestHttp('POST', '/payments.php', [
    'action' => 'process_booking_refund',
    'refund_id' => $fixture['cash']['refund_id'],
    'external_reference' => 'SYNTHETIC-NOT-APPROVED-' . $suffix,
    'confirm_external_refund' => true,
], $tokens['admin']);
refundTestAssert($notApproved['status'] === 409, 'an Admin cannot process a refund before approval');

foreach ($fixture as $method => $refund) {
    $approved = refundTestHttp('POST', '/payments.php', [
        'action' => 'approve_booking_refund',
        'refund_id' => $refund['refund_id'],
    ], $tokens['admin']);
    $state = $conn->prepare('SELECT status, approved_by_user_id FROM booking_refunds WHERE id = :id');
    $state->execute([':id' => $refund['refund_id']]);
    $approvalState = $state->fetch();
    refundTestAssert(
        $approved['status'] === 200 && $approvalState['status'] === 'approved'
            && (int)$approvalState['approved_by_user_id'] === $userIds['admin'],
        'same Admin approves the full ' . strtoupper($method) . ' refund'
    );
}

$approvedRevenueResponse = refundTestHttp('GET', '/stats.php', null, $tokens['admin']);
refundTestAssert(
    abs(refundTestMonthlyRevenue($approvedRevenueResponse, $month) - ($baselineRevenue + 300.0)) < 0.01,
    'pending and approved-but-unprocessed refunds do not reduce net booking revenue'
);

$adminRefundList = refundTestHttp('GET', '/payments.php?view=booking_refunds', null, $tokens['admin']);
refundTestAssert($adminRefundList['status'] === 200, 'Admin can retrieve the booking refund request list');

$processCash = refundTestHttp('POST', '/payments.php', [
    'action' => 'process_booking_refund',
    'refund_id' => $fixture['cash']['refund_id'],
    'external_reference' => 'SYNTHETIC-CASH-RECEIPT-' . $suffix,
    'confirm_external_refund' => true,
], $tokens['admin']);
$cashStateStmt = $conn->prepare(
    'SELECT status, approved_by_user_id, processed_by_user_id, external_reference, processed_at
     FROM booking_refunds WHERE id = :id'
);
$cashStateStmt->execute([':id' => $fixture['cash']['refund_id']]);
$cashState = $cashStateStmt->fetch();
refundTestAssert(
    $processCash['status'] === 200 && $cashState['status'] === 'processed'
        && (int)$cashState['approved_by_user_id'] === $userIds['admin']
        && (int)$cashState['processed_by_user_id'] === $userIds['admin']
        && $cashState['external_reference'] === 'SYNTHETIC-CASH-RECEIPT-' . $suffix
        && $cashState['processed_at'] !== null,
    'same Admin processes Cash refund with synthetic receipt reference'
);

$cashRevenueResponse = refundTestHttp('GET', '/stats.php', null, $tokens['admin']);
refundTestAssert(
    abs(refundTestMonthlyRevenue($cashRevenueResponse, $month) - ($baselineRevenue + 175.0)) < 0.01,
    'processed Cash refund reduces net booking revenue exactly once'
);

$duplicateCash = refundTestHttp('POST', '/payments.php', [
    'action' => 'process_booking_refund',
    'refund_id' => $fixture['cash']['refund_id'],
    'external_reference' => 'SYNTHETIC-CASH-DUPLICATE-' . $suffix,
    'confirm_external_refund' => true,
], $tokens['admin']);
refundTestAssert($duplicateCash['status'] === 409, 'duplicate processing of a Cash refund is rejected');

$processGcash = refundTestHttp('POST', '/payments.php', [
    'action' => 'process_booking_refund',
    'refund_id' => $fixture['gcash']['refund_id'],
    'external_reference' => 'SYNTHETIC-GCASH-TRANSACTION-' . $suffix,
    'confirm_external_refund' => true,
], $tokens['admin']);
$gcashStateStmt = $conn->prepare(
    'SELECT status, approved_by_user_id, processed_by_user_id, external_reference, processed_at
     FROM booking_refunds WHERE id = :id'
);
$gcashStateStmt->execute([':id' => $fixture['gcash']['refund_id']]);
$gcashState = $gcashStateStmt->fetch();
refundTestAssert(
    $processGcash['status'] === 200 && $gcashState['status'] === 'processed'
        && (int)$gcashState['approved_by_user_id'] === $userIds['admin']
        && (int)$gcashState['processed_by_user_id'] === $userIds['admin']
        && $gcashState['external_reference'] === 'SYNTHETIC-GCASH-TRANSACTION-' . $suffix
        && $gcashState['processed_at'] !== null,
    'same Admin processes verified GCash refund with synthetic transaction reference'
);

$unchangedPayments = true;
foreach ($fixture as $refund) {
    $paymentStmt = $conn->prepare(
        'SELECT id, amount, status, payment_method, payment_date, reference_no, verification_status, verified_by, verified_at
         FROM payments WHERE id = :id'
    );
    $paymentStmt->execute([':id' => $refund['payment_id']]);
    $unchangedPayments = $unchangedPayments && $paymentStmt->fetch() === $refund['payment_before'];
}
refundTestAssert($unchangedPayments, 'original Cash and GCash payments remain unchanged after refunds');

$finalRevenueResponse = refundTestHttp('GET', '/stats.php', null, $tokens['admin']);
$finalRevenue = refundTestMonthlyRevenue($finalRevenueResponse, $month);
refundTestAssert(
    abs($finalRevenue - $baselineRevenue) < 0.01,
    'both processed refunds reduce net booking revenue exactly once'
);
$repeatRevenueResponse = refundTestHttp('GET', '/stats.php', null, $tokens['admin']);
refundTestAssert(
    abs(refundTestMonthlyRevenue($repeatRevenueResponse, $month) - $finalRevenue) < 0.01,
    'repeated revenue reporting does not subtract processed refunds again'
);

$duplicateGcash = refundTestHttp('POST', '/payments.php', [
    'action' => 'process_booking_refund',
    'refund_id' => $fixture['gcash']['refund_id'],
    'external_reference' => 'SYNTHETIC-GCASH-DUPLICATE-' . $suffix,
    'confirm_external_refund' => true,
], $tokens['admin']);
refundTestAssert($duplicateGcash['status'] === 409, 'duplicate processing of a GCash refund is rejected');

echo "All booking refund processing HTTP checks passed: {$assertions} assertions against {$databaseName} on port {$port}." . PHP_EOL;
