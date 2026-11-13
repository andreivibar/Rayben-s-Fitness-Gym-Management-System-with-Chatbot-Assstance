<?php

require_once __DIR__ . '/../config/notification_workflow.php';
require_once __DIR__ . '/../config/booking.php';

class BookingEventTestPDO extends PDO
{
    public array $users = [1 => 'member', 2 => 'trainer', 3 => 'admin', 4 => 'administrator'];
    public array $notifications = [];
    public array $bookings = [501 => ['member_user_id' => 1, 'trainer_user_id' => 2]];
    private bool $transactionOpen = false;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new BookingEventTestStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = $this->prepare($query);
        $statement->execute();
        return $statement;
    }

    public function beginTransaction(): bool
    {
        $this->transactionOpen = true;
        return true;
    }

    public function commit(): bool
    {
        $this->transactionOpen = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transactionOpen;
    }

    public function run(string $query, array $params): array
    {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($query)));
        if (str_starts_with($sql, 'select m.user_id as member_user_id')) {
            $bookingId = (int)($params[':booking_id'] ?? 0);
            return ['rows' => isset($this->bookings[$bookingId]) ? [$this->bookings[$bookingId]] : []];
        }
        if (str_starts_with($sql, 'select id from users where lower(role)')) {
            $rows = [];
            foreach ($this->users as $id => $role) {
                if (in_array(strtolower($role), ['admin', 'administrator'], true)) $rows[] = ['id' => $id];
            }
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
                'notification_type' => $params[':type'],
                'message' => $params[':message'],
                'booking_id' => $params[':booking_id'] === null ? null : (int)$params[':booking_id'],
            ];
            return ['rows' => []];
        }
        throw new RuntimeException('Unhandled booking-event test SQL: ' . $sql);
    }
}

class BookingEventTestStatement extends PDOStatement
{
    private array $rows = [];
    private int $cursor = 0;

    public function __construct(private BookingEventTestPDO $connection, private string $query) {}

    public function execute(?array $params = null): bool
    {
        $result = $this->connection->run($this->query, $params ?? []);
        $this->rows = $result['rows'];
        $this->cursor = 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[$this->cursor++] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(static fn(array $row): mixed => array_values($row)[0] ?? null, $this->rows);
        }
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->rows[$this->cursor++] ?? null;
        return $row === null ? false : array_values($row)[$column] ?? false;
    }
}

$conn = new BookingEventTestPDO();
$conn->beginTransaction();
bookingNotifyEvent(
    $conn,
    ['id' => 501],
    'booking_alternative_proposed',
    'Trainer proposed another time',
    'A new time was proposed.',
    ['admin'],
    2
);
bookingNotifyEvent(
    $conn,
    ['id' => 501],
    'booking_alternative_proposed',
    'Trainer proposed another time',
    'A new time was proposed.',
    ['admin'],
    2
);
$conn->commit();

$recipients = array_column($conn->notifications, 'user_id');
sort($recipients);
if ($recipients !== [1, 3, 4]) {
    fwrite(STDERR, 'Expected one deduplicated notification each for the member and admin aliases; trainer actor must be excluded.' . PHP_EOL);
    exit(1);
}

echo "Booking event notification routing passed." . PHP_EOL;
