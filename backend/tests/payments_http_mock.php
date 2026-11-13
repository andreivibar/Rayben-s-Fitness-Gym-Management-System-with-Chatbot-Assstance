<?php

class PaymentsApiMockPDO extends PDO {
    private array $dailyPasses;
    private string $scenario;
    private bool $transactionOpen = false;

    public function __construct(string $scenario) {
        $this->scenario = $scenario;
        $manila = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable('now', $manila);
        $today = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');

        $passData = [
            'pending_gcash' => [['id' => 101, 'payment_status' => 'pending', 'payment_method' => 'gcash', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => null]],
            'rejected_gcash' => [['id' => 102, 'payment_status' => 'rejected', 'payment_method' => 'gcash', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => null]],
            'verified_gcash' => [['id' => 103, 'payment_status' => 'paid', 'payment_method' => 'gcash', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => $now->format('Y-m-d H:i:s')]],
            'expired_pass' => [['id' => 104, 'payment_status' => 'paid', 'payment_method' => 'gcash', 'visit_date' => $yesterday, 'expires_at' => $today . ' 00:00:00', 'verified_at' => $now->format('Y-m-d H:i:s')]],
            'future_pass' => [['id' => 114, 'payment_status' => 'paid', 'payment_method' => 'gcash', 'visit_date' => $tomorrow, 'expires_at' => $now->modify('+2 days')->format('Y-m-d') . ' 00:00:00', 'verified_at' => $now->format('Y-m-d H:i:s')]],
            'pending_cash' => [['id' => 105, 'payment_status' => 'unpaid', 'payment_method' => 'cash', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => null]],
            'confirmed_cash' => [['id' => 106, 'payment_status' => 'paid', 'payment_method' => 'cash', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => $now->format('Y-m-d H:i:s')]],
            'invalid_paid_amount' => [['id' => 111, 'payment_status' => 'paid', 'payment_method' => 'gcash', 'amount' => '125.00', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => $now->format('Y-m-d H:i:s')]],
            'staff_view' => [['id' => 113, 'payment_status' => 'paid', 'payment_method' => 'gcash', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => $now->format('Y-m-d H:i:s'), 'reference_no' => 'GCASH-113', 'verified_by' => 7, 'verified_by_name' => 'Verifier', 'recorded_by' => 8, 'recorded_by_name' => 'Recorder']],
            'multiple' => [
                ['id' => 107, 'payment_status' => 'paid', 'payment_method' => 'gcash', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => $now->format('Y-m-d H:i:s')],
                ['id' => 108, 'payment_status' => 'pending', 'payment_method' => 'gcash', 'visit_date' => $tomorrow, 'expires_at' => $now->modify('+2 days')->format('Y-m-d') . ' 00:00:00', 'verified_at' => null],
            ],
            'monthly_and_pass' => [['id' => 112, 'payment_status' => 'paid', 'payment_method' => 'cash', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => $now->format('Y-m-d H:i:s')]],
            'ownership' => [
                ['id' => 109, 'member_id' => 41, 'payment_status' => 'paid', 'payment_method' => 'cash', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => $now->format('Y-m-d H:i:s')],
                ['id' => 110, 'member_id' => 42, 'payment_status' => 'paid', 'payment_method' => 'cash', 'visit_date' => $today, 'expires_at' => $tomorrow . ' 00:00:00', 'verified_at' => $now->format('Y-m-d H:i:s')],
            ],
        ];
        $this->dailyPasses = array_map(
            static fn(array $pass): array => array_merge([
                'member_id' => 41,
                'customer_name' => 'Test Member',
                'member_code' => 'TEST-41',
                'member_name' => 'Test Member',
                'member_email' => 'member@example.invalid',
                'amount' => '100.00',
                'reference_no' => null,
                'transaction_source' => 'online',
                'recorded_by' => null,
                'recorded_by_name' => null,
                'verified_by' => null,
                'verified_by_name' => null,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'checked_in_at' => null,
            ], $pass),
            $passData[$scenario] ?? []
        );
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new PaymentsApiMockStatement($this, $query);
    }

    public function beginTransaction(): bool {
        $this->transactionOpen = true;
        return true;
    }

    public function commit(): bool {
        $this->transactionOpen = false;
        return true;
    }

    public function rollBack(): bool {
        $this->transactionOpen = false;
        return true;
    }

    public function inTransaction(): bool {
        return $this->transactionOpen;
    }

    public function lastInsertId(?string $name = null): string|false {
        return '601';
    }

    public function run(string $query, array $params): array {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($query)));
        if (str_starts_with($sql, 'select id, member_category, membership_type') && str_contains($sql, 'where user_id')) {
            return ['rows' => [[
                'id' => 41, 'member_category' => $this->scenario === 'discount_denied' ? 'student' : 'regular',
                'membership_type' => 'monthly', 'membership_start' => date('Y-m-d'),
                'membership_end' => date('Y-m-d'), 'status' => 'active',
            ]]];
        }
        if (str_starts_with($sql, 'select id, member_category, membership_type') && str_contains($sql, 'where id')) {
            return ['rows' => [[
                'id' => 41, 'member_category' => $this->scenario === 'discount_denied' ? 'student' : 'regular',
                'membership_type' => 'monthly', 'membership_start' => date('Y-m-d'),
                'membership_end' => date('Y-m-d'), 'status' => 'active',
            ]]];
        }
        if (str_starts_with($sql, 'select id, status from payments where idempotency_key')) {
            return ['rows' => []];
        }
        if (str_starts_with($sql, 'select id from payments where idempotency_key')) {
            return ['rows' => []];
        }
        if (str_starts_with($sql, 'select id, plan_code, duration_months')) {
            return ['rows' => [[
                'id' => 7, 'plan_code' => 'monthly_1', 'duration_months' => 1,
                'regular_price' => '800.00', 'student_price' => '700.00',
                'senior_price' => '700.00', 'pwd_price' => '700.00',
            ]]];
        }
        if (str_starts_with($sql, 'select id from registration_requests') && str_contains($sql, 'discount_status')) {
            return ['rows' => []];
        }
        if (str_starts_with($sql, 'select id from registration_requests')) {
            return ['rows' => []];
        }
        if (str_starts_with($sql, 'select id from payments where member_id')
            && str_contains($sql, "payment_type = 'monthly_subscription'")) {
            return ['rows' => $this->scenario === 'pending_renewal' ? [['id' => 600]] : []];
        }
        if (str_starts_with($sql, 'select id from payments where id = :id and member_id')
            && str_contains($sql, "payment_type = 'monthly_subscription'")) {
            return ['rows' => [['id' => (int)($params[':id'] ?? 0)]]];
        }
        if (str_starts_with($sql, 'select id from membership_subscription_periods where id = :id')) {
            return ['rows' => [['id' => (int)($params[':id'] ?? 0)]]];
        }
        if (str_starts_with($sql, 'select id from subscription_booking_credit_grants where payment_id')) {
            return ['rows' => []];
        }
        if (str_starts_with($sql, 'select c.id from subscription_booking_credits c')) {
            return ['rows' => []];
        }
        if (str_starts_with($sql, 'select p.id, p.member_id, p.membership_plan_id')) {
            if (!in_array($this->scenario, ['review_cash_success', 'review_gcash_success', 'discount_denied'], true)) {
                return ['rows' => []];
            }
            return ['rows' => [[
                'id' => 600, 'member_id' => 41, 'membership_plan_id' => 7,
                'payment_type' => 'monthly_subscription',
                'payment_method' => $this->scenario === 'review_cash_success' ? 'cash' : 'gcash',
                'amount' => '800.00', 'registration_request_id' => null,
                'status' => 'pending', 'verification_status' => 'pending',
                'membership_type' => 'monthly',
                'member_category' => $this->scenario === 'discount_denied' ? 'student' : 'regular',
                'membership_start' => date('Y-m-d'), 'membership_end' => date('Y-m-d'),
                'user_id' => 9001,
            ]]];
        }
        if (str_starts_with($sql, 'select max(ends_on) from membership_subscription_periods')) {
            return ['rows' => [[null]]];
        }
        if (str_starts_with($sql, 'select id from notifications')) {
            return ['rows' => []];
        }
        if (str_starts_with($sql, 'insert into payments')
            || str_starts_with($sql, 'update payments')
            || str_starts_with($sql, 'update bookings b')
            || str_starts_with($sql, 'update members')
            || str_starts_with($sql, 'insert into membership_subscription_periods')
            || str_starts_with($sql, 'insert into subscription_booking_credit_grants')
            || str_starts_with($sql, 'insert into subscription_booking_credits')
            || str_starts_with($sql, 'insert into subscription_booking_credit_events')
            || str_starts_with($sql, 'insert into notifications')) {
            return ['rows' => [], 'affected' => 1];
        }
        if (str_starts_with($sql, 'select id from members where user_id')) {
            return ['rows' => [($params[':user_id'] ?? 0) === 9001 ? ['id' => 41] : ['id' => 42]]];
        }
        if (str_starts_with($sql, 'select p.id,')) {
            if (!in_array($this->scenario, ['monthly', 'monthly_and_pass'], true)) return ['rows' => []];
            return ['rows' => [[
                'id' => 501, 'member_id' => 41, 'booking_id' => null, 'recorded_by' => 1,
                'amount' => '800.00', 'payment_type' => 'monthly_subscription', 'status' => 'paid',
                'payment_date' => date('Y-m-d'), 'reference_no' => 'MONTH-501', 'payment_method' => 'cash',
                'verification_status' => 'verified', 'verified_by' => 1, 'verified_at' => date('Y-m-d H:i:s'),
                'proof_original_name' => null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => null,
                'member_code' => 'TEST-41', 'member_first_name' => 'Test', 'member_middle_name' => null,
                'member_last_name' => 'Member', 'member_name' => 'Test Member', 'member_email' => 'member@example.invalid',
                'membership_type' => 'monthly', 'membership_start' => date('Y-m-d'), 'membership_end' => date('Y-m-d', strtotime('+1 month')),
                'membership_status' => 'active', 'booking_code' => null, 'recorded_by_name' => 'Staff',
                'verified_by_name' => 'Staff',
            ]]];
        }
        if (str_starts_with($sql, 'select max(ends_on) from membership_subscription_periods')) {
            return ['rows' => [[null]]];
        }
        if (str_contains($sql, 'from daily_pass_transactions dp')) {
            $memberId = $params[':member_id'] ?? 41;
            $rows = array_values(array_filter($this->dailyPasses, static fn(array $pass): bool => (int)$pass['member_id'] === (int)$memberId));
            usort($rows, static fn(array $left, array $right): int => strcmp((string)$right['created_at'], (string)$left['created_at']) ?: ((int)$right['id'] <=> (int)$left['id']));
            return ['rows' => $rows];
        }
        if (str_starts_with($sql, 'select coalesce(sum(case when p.status')) {
            return ['rows' => [[
                'total_paid' => in_array($this->scenario, ['monthly', 'monthly_and_pass'], true) ? '800.00' : '0.00',
                'pending_amount' => '0.00',
                'pending_count' => 0,
            ]]];
        }
        if (str_starts_with($sql, 'select payment_status, amount from daily_pass_transactions')) {
            return ['rows' => array_map(static fn(array $pass): array => [
                'payment_status' => $pass['payment_status'],
                'amount' => $pass['amount'],
            ], array_values(array_filter($this->dailyPasses, static fn(array $pass): bool => (int)$pass['member_id'] === (int)($params[':member_id'] ?? 41))))];
        }
        if (str_starts_with($sql, 'select membership_type, member_category')) {
            return ['rows' => [[
                'membership_type' => $this->scenario === 'monthly' ? 'monthly' : 'daily_pass',
                'membership_start' => null, 'membership_end' => null, 'status' => 'active',
            ]]];
        }
        if (str_starts_with($sql, 'select id, member_code, full_name, email, membership_start')) {
            return ['rows' => []];
        }

        throw new RuntimeException('Unhandled payments mock SQL: ' . $sql);
    }
}

class PaymentsApiMockStatement extends PDOStatement {
    private array $rows = [];
    private int $cursor = 0;
    private int $affectedRows = 0;

    public function __construct(private PaymentsApiMockPDO $connection, private string $query) {}

    public function execute(?array $params = null): bool {
        $result = $this->connection->run($this->query, $params ?? []);
        $this->rows = $result['rows'];
        $this->cursor = 0;
        $this->affectedRows = $result['affected'] ?? 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return $this->rows[$this->cursor++] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);
        return $rows;
    }

    public function fetchColumn(int $column = 0): mixed {
        $row = $this->rows[$this->cursor++] ?? false;
        if ($row === false) return false;
        return array_values($row)[$column] ?? false;
    }

    public function rowCount(): int {
        return $this->affectedRows;
    }
}

?>
