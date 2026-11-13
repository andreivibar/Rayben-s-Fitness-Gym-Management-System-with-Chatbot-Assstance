<?php

class MemberAttendanceQrMockPDO extends PDO {
    public array $state;
    private $transactionSnapshot = null;

    public function __construct(string $scenario) {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $tomorrow = (new DateTimeImmutable($today, new DateTimeZone('Asia/Manila')))->modify('+1 day')->format('Y-m-d');
        $yesterday = (new DateTimeImmutable($today, new DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d');
        $isMonthly = in_array($scenario, ['active_monthly', 'expired_monthly', 'restricted_monthly'], true);

        $member = [
            'id' => 41,
            'first_name' => 'HTTP',
            'middle_name' => null,
            'last_name' => 'Test',
            'full_name' => 'HTTP Test',
            'status' => 'active',
            'account_status' => 'active',
            'gym_access_status' => str_starts_with($scenario, 'restricted_') ? 'restricted' : 'allowed',
            'membership_type' => $isMonthly ? 'monthly' : 'daily_pass',
            'membership_end' => $scenario === 'expired_monthly' ? $yesterday : $tomorrow,
        ];
        $subscriptionPeriods = [];
        if (in_array($scenario, ['active_monthly', 'restricted_monthly'], true)) {
            $subscriptionPeriods[] = ['status' => 'active', 'starts_on' => $today, 'ends_on' => $tomorrow];
        } elseif ($scenario === 'expired_monthly') {
            $subscriptionPeriods[] = ['status' => 'expired', 'starts_on' => $yesterday, 'ends_on' => $yesterday];
        }

        $paymentStatus = [
            'pending' => 'pending',
            'rejected' => 'rejected',
            'expired_pass' => 'paid',
            'verified_pass' => 'paid',
            'restricted_daily_pass' => 'paid',
            'active_monthly' => 'pending',
            'expired_monthly' => 'pending',
            'unpaid_cash' => 'unpaid',
        ][$scenario] ?? 'pending';
        $visitDate = $scenario === 'expired_pass' ? $yesterday : $today;
        $expiresAt = $scenario === 'expired_pass'
            ? $today . ' 00:00:00'
            : $tomorrow . ' 00:00:00';
        $dailyPasses = [];
        if (!$isMonthly || in_array($scenario, ['active_monthly', 'restricted_monthly'], true)) {
            $dailyPasses[] = [
                'id' => 501,
                'visit_date' => $visitDate,
                'expires_at' => $expiresAt,
                'payment_status' => $paymentStatus,
                'amount' => '100.00',
                'checked_in_at' => null,
            ];
        }

        $this->state = [
            'member' => $member,
            'subscription_periods' => $subscriptionPeriods,
            'daily_passes' => $dailyPasses,
            'attendance' => [],
            'token_uses' => [],
            'next_attendance_id' => 801,
            'rate_limit_count' => 0,
            'scenario' => $scenario,
        ];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new MemberAttendanceQrMockStatement($this, $query);
    }

    public function beginTransaction(): bool {
        $this->transactionSnapshot = unserialize(serialize($this->state));
        return true;
    }

    public function commit(): bool {
        $this->transactionSnapshot = null;
        return true;
    }

    public function rollBack(): bool {
        if ($this->transactionSnapshot !== null) {
            $this->state = $this->transactionSnapshot;
            $this->transactionSnapshot = null;
        }
        return true;
    }

    public function inTransaction(): bool {
        return $this->transactionSnapshot !== null;
    }

    public function lastInsertId(?string $name = null): string|false {
        return (string)($this->state['next_attendance_id'] - 1);
    }

    public function run(string $query, array $params): array {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($query)));

        if (str_contains($sql, 'from members where user_id = :user_id')) {
            return ['rows' => [$this->state['member']], 'affected' => 1];
        }
        if (str_contains($sql, 'from membership_subscription_periods')) {
            return ['rows' => $this->state['subscription_periods'], 'affected' => count($this->state['subscription_periods'])];
        }
        if (str_contains($sql, 'from daily_pass_transactions')) {
            return ['rows' => $this->state['daily_passes'], 'affected' => count($this->state['daily_passes'])];
        }
        if (str_contains($sql, 'from attendance_qr_tokens')) {
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
            return ['rows' => [[
                'id' => 77,
                'issued_at' => $now->setTime(0, 0)->format('Y-m-d H:i:s'),
                'expires_at' => $now->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'),
            ]], 'affected' => 1];
        }
        if (str_contains($sql, 'from attendance_qr_token_uses')) {
            $rows = array_values(array_filter(
                $this->state['token_uses'],
                fn(array $use): bool => $use['member_id'] === (int)$params[':member_id']
            ));
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        if (str_contains($sql, 'from attendance where') && str_contains($sql, 'time_out is null')) {
            return ['rows' => [], 'affected' => 0];
        }
        if (str_contains($sql, 'from attendance where')) {
            return ['rows' => [], 'affected' => 0];
        }
        if (str_starts_with($sql, 'insert into attendance_qr_rate_limits')) {
            $this->state['rate_limit_count']++;
            return ['rows' => [], 'affected' => 1];
        }
        if (str_starts_with($sql, 'select request_count from attendance_qr_rate_limits')) {
            return ['rows' => [['request_count' => $this->state['rate_limit_count']]], 'affected' => 1];
        }
        if (str_starts_with($sql, 'insert into attendance_qr_token_uses')) {
            $this->state['token_uses'][] = [
                'qr_token_id' => (int)$params[':token_id'],
                'member_id' => (int)$params[':member_id'],
                'attendance_id' => null,
            ];
            return ['rows' => [], 'affected' => 1];
        }
        if (str_starts_with($sql, 'update attendance_qr_token_uses')) {
            foreach ($this->state['token_uses'] as &$use) {
                if (
                    $use['qr_token_id'] === (int)$params[':token_id']
                    && $use['member_id'] === (int)$params[':member_id']
                    && $use['attendance_id'] === null
                ) {
                    $use['attendance_id'] = (int)$params[':attendance_id'];
                    unset($use);
                    return ['rows' => [], 'affected' => 1];
                }
            }
            unset($use);
            return ['rows' => [], 'affected' => 0];
        }
        if (str_starts_with($sql, 'update daily_pass_transactions')) {
            foreach ($this->state['daily_passes'] as &$pass) {
                if (
                    $pass['id'] === (int)$params[':id']
                    && $pass['payment_status'] === 'paid'
                    && $pass['checked_in_at'] === null
                ) {
                    $pass['checked_in_at'] = $params[':checked_in_at'];
                    unset($pass);
                    return ['rows' => [], 'affected' => 1];
                }
            }
            unset($pass);
            return ['rows' => [], 'affected' => 0];
        }
        if (str_starts_with($sql, 'insert into attendance ')) {
            $attendanceId = $this->state['next_attendance_id']++;
            $this->state['attendance'][] = [
                'id' => $attendanceId,
                'member_id' => (int)$params[':member_id'],
                'attendance_date' => $params[':attendance_date'],
                'time_in' => $params[':time_in'],
                'time_out' => null,
            ];
            return ['rows' => [], 'affected' => 1];
        }

        throw new RuntimeException('Unhandled mock SQL: ' . $sql);
    }
}

class MemberAttendanceQrMockStatement extends PDOStatement {
    private array $rows = [];
    private int $cursor = 0;
    private int $affected = 0;

    public function __construct(private MemberAttendanceQrMockPDO $connection, private string $query) {}

    public function execute(?array $params = null): bool {
        $result = $this->connection->run($this->query, $params ?? []);
        $this->rows = $result['rows'];
        $this->affected = $result['affected'];
        $this->cursor = 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        return $this->rows[$this->cursor++];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);
        return $rows;
    }

    public function fetchColumn(int $column = 0): mixed {
        $row = $this->fetch(PDO::FETCH_NUM);
        if ($row === false) {
            return false;
        }
        return array_values($row)[$column] ?? false;
    }

    public function rowCount(): int {
        return $this->affected;
    }
}

?>
