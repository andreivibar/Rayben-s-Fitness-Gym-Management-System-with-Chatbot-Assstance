<?php

class MemberAttendanceHistoryMockPDO extends PDO {
    public array $attendanceRecords;
    public int $memberLookupCount = 0;
    public int $attendanceLookupCount = 0;
    public array $lastAttendanceParams = [];

    public function __construct(string $scenario) {
        $timezone = new DateTimeZone('Asia/Manila');
        $today = new DateTimeImmutable('today', $timezone);
        $yearStart = $today->setDate((int)$today->format('Y'), 1, 1);
        $yesterday = $today->modify('-1 day');
        $tomorrow = $today->modify('+1 day');
        $lastYear = $today->modify('-1 year')->setDate((int)$today->format('Y') - 1, 1, 1);
        $this->attendanceRecords = $scenario === 'no_records' ? [] : [
            ['id' => 101, 'member_id' => 41, 'attendance_date' => $today->format('Y-m-d'), 'time_in' => '08:15:00', 'time_out' => '09:30:00'],
            ['id' => 102, 'member_id' => 41, 'attendance_date' => $today->format('Y-m-d'), 'time_in' => '10:15:00', 'time_out' => null],
            ['id' => 103, 'member_id' => 41, 'attendance_date' => $yesterday->format('Y-m-d'), 'time_in' => '07:45:00', 'time_out' => null],
            ['id' => 104, 'member_id' => 41, 'attendance_date' => $yearStart->format('Y-m-d'), 'time_in' => '06:30:00', 'time_out' => '07:30:00'],
            ['id' => 105, 'member_id' => 41, 'attendance_date' => $lastYear->format('Y-m-d'), 'time_in' => '06:00:00', 'time_out' => '07:00:00'],
            ['id' => 106, 'member_id' => 41, 'attendance_date' => $tomorrow->format('Y-m-d'), 'time_in' => '06:00:00', 'time_out' => null],
            ['id' => 201, 'member_id' => 42, 'attendance_date' => $today->format('Y-m-d'), 'time_in' => '05:00:00', 'time_out' => '06:00:00'],
        ];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new MemberAttendanceHistoryMockStatement($this, $query);
    }

    public function run(string $query, array $params): array {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($query)));
        if (str_contains($sql, 'from members')) {
            $this->memberLookupCount++;
            return ['rows' => [['id' => 41]], 'affected' => 1];
        }
        if (str_contains($sql, 'from attendance') && str_contains($sql, 'sum(case')) {
            $memberId = (int)$params[':member_id'];
            $today = $params[':filter_today'];
            $monthStart = $params[':summary_month_start'];
            $nextMonthStart = $params[':summary_next_month'];
            $yearStart = $params[':year_start'];
            $nextYearStart = $params[':next_year_start'];
            $monthlyCount = 0;
            $attendanceDates = [];
            $yearCount = 0;
            $recentDate = null;
            foreach ($this->attendanceRecords as $record) {
                $date = $record['attendance_date'];
                if ($record['member_id'] !== $memberId || $record['time_in'] === null || $date > $today) continue;
                if ($date >= $monthStart && $date < $nextMonthStart) {
                    $monthlyCount++;
                    $attendanceDates[$date] = true;
                }
                if ($date >= $yearStart && $date < $nextYearStart) $yearCount++;
                if ($recentDate === null || $date > $recentDate) $recentDate = $date;
            }
            return ['rows' => [[
                'monthly_check_ins' => $monthlyCount,
                'monthly_attendance_days' => count($attendanceDates),
                'current_year_check_ins' => $yearCount,
                'most_recent_check_in' => $recentDate,
            ]], 'affected' => 1];
        }
        if (str_contains($sql, 'from attendance')) {
            $this->attendanceLookupCount++;
            $this->lastAttendanceParams = $params;
            $rows = array_values(array_filter($this->attendanceRecords, static function (array $record) use ($params): bool {
                return $record['member_id'] === (int)$params[':member_id']
                    && $record['time_in'] !== null
                    && $record['attendance_date'] >= $params[':month_start']
                    && $record['attendance_date'] < $params[':next_month_start']
                    && $record['attendance_date'] <= $params[':today'];
            }));
            usort($rows, static fn(array $left, array $right): int =>
                [$left['attendance_date'], $left['time_in'], $left['id']]
                <=> [$right['attendance_date'], $right['time_in'], $right['id']]
            );
            foreach ($rows as &$row) unset($row['member_id']);
            unset($row);
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        throw new RuntimeException('Unhandled attendance history mock SQL: ' . $sql);
    }
}

class MemberAttendanceHistoryMockStatement extends PDOStatement {
    private array $rows = [];
    private int $cursor = 0;

    public function __construct(private MemberAttendanceHistoryMockPDO $connection, private string $query) {}

    public function execute(?array $params = null): bool {
        $result = $this->connection->run($this->query, $params ?? []);
        $this->rows = $result['rows'];
        $this->cursor = 0;
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
        $row = $this->fetch(PDO::FETCH_NUM);
        return $row === false ? false : array_values($row)[$column] ?? false;
    }
}

?>
