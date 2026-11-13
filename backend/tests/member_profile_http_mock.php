<?php

class MemberProfileMockPDO extends PDO {
    public array $state;
    private $transactionSnapshot = null;

    public function __construct(string $scenario) {
        $today = manilaToday()->format('Y-m-d');
        $tomorrow = (new DateTimeImmutable($today, new DateTimeZone('Asia/Manila')))->modify('+1 day')->format('Y-m-d');
        $yesterday = (new DateTimeImmutable($today, new DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d');
        $isMonthly = in_array($scenario, ['monthly', 'monthly_promo'], true);
        $paymentStatus = [
            'pending' => 'pending',
            'verified' => 'paid',
            'expired' => 'paid',
            'scheduled' => 'paid',
            'other_member_email' => 'pending',
            'pending_update' => 'pending',
        ][$scenario] ?? null;

        $member = [
            'id' => 41,
            'user_id' => 9001,
            'member_code' => 'MEM-TEST-41',
            'first_name' => 'Profile',
            'middle_name' => null,
            'last_name' => 'Test',
            'full_name' => 'Profile Test',
            'email' => $scenario === 'current_email' ? 'stale-member@example.test' : null,
            'phone' => '09123456789',
            'address' => 'Test address',
            'date_of_birth' => '1990-01-01',
            'membership_type' => $isMonthly ? 'monthly' : 'daily_pass',
            'membership_start' => $isMonthly ? $today : null,
            'membership_end' => $isMonthly ? $tomorrow : null,
            'status' => 'active',
            'profile_picture' => null,
            'created_at' => $today . ' 10:00:00',
        ];
        $pass = null;
        if ($paymentStatus !== null) {
            $pass = [
                'id' => 501,
                'customer_name' => 'Profile Test',
                'pass_code' => 'AABBCCDD',
                'visit_date' => $scenario === 'expired' ? $yesterday : ($scenario === 'scheduled' ? $tomorrow : $today),
                'expires_at' => $scenario === 'expired' ? $today . ' 00:00:00' : ($scenario === 'scheduled' ? (new DateTimeImmutable($tomorrow, new DateTimeZone('Asia/Manila')))->modify('+1 day')->format('Y-m-d') . ' 00:00:00' : $tomorrow . ' 00:00:00'),
                'payment_method' => 'gcash',
                'amount' => '100.00',
                'payment_status' => $paymentStatus,
                'reference_no' => null,
                'transaction_source' => 'online',
                'verified_by' => $paymentStatus === 'paid' ? 1 : null,
                'verified_at' => $paymentStatus === 'paid' ? $today . ' 09:00:00' : null,
                'recorded_by' => null,
                'created_at' => $today . ' 08:00:00',
                'checked_in_at' => null,
            ];
        }

        $this->state = [
            'member' => $member,
            'other_member' => array_merge($member, [
                'id' => 42,
                'user_id' => 9002,
                'member_code' => 'MEM-TEST-42',
                'email' => 'another@example.invalid',
                'full_name' => 'Another Member',
            ]),
            'subscription_periods' => [],
            'daily_passes' => $pass ? [$pass] : [],
            'latest_membership_request' => $scenario === 'monthly_promo' ? [
                'id' => 77,
                'member_category' => 'student',
                'payment_method' => 'cash',
                'payment_status' => 'pending',
                'discount_status' => 'submitted',
                'payment_amount' => '2100.00',
                'status' => 'pending',
                'created_at' => $today . ' 11:00:00',
            ] : null,
        ];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new MemberProfileMockStatement($this, $query);
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

    public function run(string $query, array $params): array {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($query)));

        if (str_starts_with($sql, 'update members set first_name')) {
            foreach (['first_name', 'middle_name', 'last_name', 'full_name', 'phone', 'address', 'date_of_birth'] as $field) {
                $this->state['member'][$field] = $params[':' . $field];
            }
            return ['rows' => [], 'affected' => 1];
        }
        if (str_starts_with($sql, 'update users set first_name')) {
            return ['rows' => [], 'affected' => 1];
        }
        if (str_starts_with($sql, 'select id, user_id, member_code')) {
            if (($params[':user_id'] ?? null) === 9001) {
                return ['rows' => [$this->state['member']], 'affected' => 1];
            }
            if (($params[':user_id'] ?? null) === 9002) {
                return ['rows' => [$this->state['other_member']], 'affected' => 1];
            }
            return ['rows' => [], 'affected' => 0];
        }
        if (str_contains($sql, 'from membership_subscription_periods')) {
            return ['rows' => $this->state['subscription_periods'], 'affected' => 0];
        }
        if (str_contains($sql, 'from payments')) {
            return ['rows' => [], 'affected' => 0];
        }
        if (str_contains($sql, 'from registration_requests')) {
            $request = $this->state['latest_membership_request'];
            return ['rows' => $request ? [$request] : [], 'affected' => $request ? 1 : 0];
        }
        if (str_contains($sql, 'from daily_pass_transactions')) {
            return ['rows' => $this->state['daily_passes'], 'affected' => count($this->state['daily_passes'])];
        }

        throw new RuntimeException('Unhandled mock SQL: ' . $sql);
    }
}

class MemberProfileMockStatement extends PDOStatement {
    private array $rows = [];
    private int $cursor = 0;
    private array $bound = [];

    public function __construct(private MemberProfileMockPDO $connection, private string $query) {}

    public function bindParam(
        string|int $param,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null
    ): bool {
        $this->bound[$param] = &$var;
        return true;
    }

    public function execute(?array $params = null): bool {
        $resolvedParams = [];
        foreach ($this->bound as $key => &$value) {
            $resolvedParams[$key] = $value;
        }
        unset($value);
        $result = $this->connection->run($this->query, array_merge($resolvedParams, $params ?? []));
        $this->rows = $result['rows'];
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
}

?>
