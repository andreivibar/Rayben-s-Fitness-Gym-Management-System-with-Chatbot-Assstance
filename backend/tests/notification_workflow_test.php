<?php

require_once __DIR__ . '/../config/notification_workflow.php';

class NotificationWorkflowTestPDO extends PDO {
    public array $users;
    public array $notifications = [];
    private bool $transactionOpen = false;

    public function __construct(array $users) {
        $this->users = $users;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new NotificationWorkflowTestStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $statement = $this->prepare($query);
        $statement->execute();
        return $statement;
    }

    public function beginTransaction(): bool {
        $this->transactionOpen = true;
        return true;
    }

    public function commit(): bool {
        $this->transactionOpen = false;
        return true;
    }

    public function inTransaction(): bool {
        return $this->transactionOpen;
    }

    public function run(string $query, array $params): array {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($query)));
        if (str_starts_with($sql, 'select id from users where lower(role)')) {
            $rows = [];
            foreach ($this->users as $id => $role) {
                if (in_array(strtolower($role), ['admin', 'administrator'], true)) {
                    $rows[] = ['id' => $id];
                }
            }
            ksort($rows);
            return ['rows' => $rows];
        }
        if (str_starts_with($sql, 'select id from users where id =')) {
            $id = (int)($params[':user_id'] ?? 0);
            return ['rows' => isset($this->users[$id]) ? [['id' => $id]] : []];
        }
        if (str_starts_with($sql, 'select id from notifications')) {
            foreach ($this->notifications as $notification) {
                if ($notification['user_id'] === (int)$params[':user_id']
                    && $notification['notification_type'] === $params[':type']
                    && $notification['message'] === $params[':message']
                    && $notification['booking_id'] === ($params[':booking_id'] === null ? null : (int)$params[':booking_id'])) {
                    return ['rows' => [['id' => $notification['id']]]];
                }
            }
            return ['rows' => []];
        }
        if (str_starts_with($sql, 'insert into notifications')) {
            $this->notifications[] = [
                'id' => count($this->notifications) + 1,
                'user_id' => (int)$params[':user_id'],
                'title' => $params[':title'],
                'message' => $params[':message'],
                'notification_type' => $params[':type'],
                'booking_id' => $params[':booking_id'] === null ? null : (int)$params[':booking_id'],
            ];
            return ['rows' => []];
        }
        throw new RuntimeException('Unhandled notification test SQL: ' . $sql);
    }
}

class NotificationWorkflowTestStatement extends PDOStatement {
    private array $rows = [];
    private int $cursor = 0;

    public function __construct(private NotificationWorkflowTestPDO $connection, private string $query) {}

    public function execute(?array $params = null): bool {
        $result = $this->connection->run($this->query, $params ?? []);
        $this->rows = $result['rows'];
        $this->cursor = 0;
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(static fn(array $row): mixed => array_values($row)[0] ?? null, $this->rows);
        }
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed {
        $row = $this->rows[$this->cursor++] ?? null;
        return $row === null ? false : array_values($row)[$column] ?? false;
    }
}

function assertNotificationWorkflow(bool $condition, string $message, array &$failures): void {
    if (!$condition) $failures[] = $message;
}

$failures = [];
$conn = new NotificationWorkflowTestPDO([
    1 => 'member',
    2 => 'trainer',
    3 => 'admin',
    4 => 'administrator',
    5 => 'staff',
]);
$conn->beginTransaction();

foreach ([
    [1, 'Your booking request for Strength Training with Alex on November 5, 2026 at 3:00 PM has been submitted.'],
    [2, 'You received a booking request from Sam for Strength Training on November 5, 2026 at 3:00 PM.'],
] as [$userId, $message]) {
    notificationWorkflowCreate($conn, $userId, 'booking_requested', 'Booking request', $message, 501);
    notificationWorkflowCreate($conn, $userId, 'booking_requested', 'Booking request', $message, 501);
}
notificationWorkflowCreateForAdmins(
    $conn,
    'booking_requested',
    'New booking request',
    'Sam requested a session with Alex for Strength Training on November 5, 2026 at 3:00 PM.'
);
notificationWorkflowCreateForAdmins(
    $conn,
    'booking_requested',
    'New booking request',
    'Sam requested a session with Alex for Strength Training on November 5, 2026 at 3:00 PM.'
);
notificationWorkflowCreateForAdmins(
    $conn,
    'membership_upgrade_requested',
    'Membership upgrade request',
    'Sam submitted an upgrade request. GCash proof is pending verification. Request #710.'
);
notificationWorkflowCreateForAdmins(
    $conn,
    'membership_upgrade_requested',
    'Membership upgrade request',
    'Sam submitted an upgrade request. GCash proof is pending verification. Request #710.'
);
notificationWorkflowCreate(
    $conn,
    1,
    'initial_account_approved',
    'Your Account Has Been Approved!',
    "Good news! Your Rayben's Fitness Gym account has been approved and activated. You can now access your Member Dashboard and explore the available features. Welcome to Rayben's Fitness Gym!"
);
notificationWorkflowCreate(
    $conn,
    1,
    'initial_account_approved',
    'Your Account Has Been Approved!',
    "Good news! Your Rayben's Fitness Gym account has been approved and activated. You can now access your Member Dashboard and explore the available features. Welcome to Rayben's Fitness Gym!"
);
$conn->commit();

$bookingRows = array_values(array_filter(
    $conn->notifications,
    static fn(array $item): bool => $item['notification_type'] === 'booking_requested'
));
$upgradeRows = array_values(array_filter(
    $conn->notifications,
    static fn(array $item): bool => $item['notification_type'] === 'membership_upgrade_requested'
));
$accountApprovalRows = array_values(array_filter(
    $conn->notifications,
    static fn(array $item): bool => $item['notification_type'] === 'initial_account_approved'
));
assertNotificationWorkflow(count($bookingRows) === 4, 'Booking notices should be role-targeted and deduplicated: ' . json_encode($bookingRows), $failures);
assertNotificationWorkflow(count(array_unique(array_column($bookingRows, 'message'))) === 3, 'Booking notices should use recipient-specific wording.', $failures);
assertNotificationWorkflow(count($upgradeRows) === 2, 'An upgrade request should notify each administrator once: ' . json_encode($upgradeRows), $failures);
assertNotificationWorkflow(
    count($accountApprovalRows) === 1
        && $accountApprovalRows[0]['user_id'] === 1
        && $accountApprovalRows[0]['title'] === 'Your Account Has Been Approved!'
        && $accountApprovalRows[0]['message'] === "Good news! Your Rayben's Fitness Gym account has been approved and activated. You can now access your Member Dashboard and explore the available features. Welcome to Rayben's Fitness Gym!",
    'Initial account approval should notify only its member with the specified deduplicated message.',
    $failures
);
assertNotificationWorkflow(
    array_column($upgradeRows, 'user_id') === [3, 4],
    'Membership requests should not notify members, trainers, or staff: ' . json_encode(array_column($upgradeRows, 'user_id')),
    $failures
);
assertNotificationWorkflow(
    notificationWorkflowMembershipTypeLabel('daily_pass') === 'Daily Pass'
        && notificationWorkflowCategoryLabel('pwd') === 'PWD',
    'Membership labels should use readable notification wording.',
    $failures
);

try {
    notificationWorkflowCreate($conn, 1, 'test', 'Test', 'Outside transaction');
    $failures[] = 'Notification creation outside the event transaction should be rejected.';
} catch (LogicException) {
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Passed notification recipient, role wording, transaction, and duplicate-prevention cases.' . PHP_EOL;

?>
