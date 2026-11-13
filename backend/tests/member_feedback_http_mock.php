<?php

class MemberFeedbackMockPDO extends PDO {
    public array $state;
    private $transactionSnapshot = null;

    public function __construct(?array $state = null) {
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        $this->state = $state ?? [
            'attendance' => [
                ['id' => 501, 'member_id' => 41, 'attendance_date' => $today->format('Y-m-d'), 'time_in' => '08:00:00', 'time_out' => null, 'qr_linked' => true],
                ['id' => 502, 'member_id' => 41, 'attendance_date' => $today->modify('-1 day')->format('Y-m-d'), 'time_in' => '09:00:00', 'time_out' => '10:00:00', 'qr_linked' => true],
                ['id' => 503, 'member_id' => 41, 'attendance_date' => $today->format('Y-m-d'), 'time_in' => '11:00:00', 'time_out' => null, 'qr_linked' => false],
                ['id' => 504, 'member_id' => 42, 'attendance_date' => $today->format('Y-m-d'), 'time_in' => '12:00:00', 'time_out' => null, 'qr_linked' => true],
                ['id' => 505, 'member_id' => 41, 'attendance_date' => $today->modify('+1 day')->format('Y-m-d'), 'time_in' => '13:00:00', 'time_out' => null, 'qr_linked' => true],
            ],
            'bookings' => [
                ['id' => 601, 'member_id' => 41, 'trainer_id' => 9, 'status' => 'completed', 'booking_date' => $today->modify('-2 days')->format('Y-m-d'), 'booking_code' => 'BKG-601'],
                ['id' => 602, 'member_id' => 41, 'trainer_id' => 9, 'status' => 'pending', 'booking_date' => $today->modify('-6 days')->format('Y-m-d'), 'booking_code' => 'BKG-602'],
                ['id' => 603, 'member_id' => 41, 'trainer_id' => 9, 'status' => 'confirmed', 'booking_date' => $today->modify('-5 days')->format('Y-m-d'), 'booking_code' => 'BKG-603'],
                ['id' => 604, 'member_id' => 41, 'trainer_id' => 9, 'status' => 'cancelled', 'booking_date' => $today->modify('-4 days')->format('Y-m-d'), 'booking_code' => 'BKG-604'],
                ['id' => 605, 'member_id' => 41, 'trainer_id' => 9, 'status' => 'rejected', 'booking_date' => $today->modify('-3 days')->format('Y-m-d'), 'booking_code' => 'BKG-605'],
                ['id' => 606, 'member_id' => 41, 'trainer_id' => 9, 'status' => 'no_show', 'booking_date' => $today->modify('-1 day')->format('Y-m-d'), 'booking_code' => 'BKG-606'],
                ['id' => 607, 'member_id' => 42, 'trainer_id' => 9, 'status' => 'completed', 'booking_date' => $today->modify('-7 days')->format('Y-m-d'), 'booking_code' => 'BKG-607'],
                ['id' => 608, 'member_id' => 41, 'trainer_id' => 10, 'status' => 'completed', 'booking_date' => $today->modify('-8 days')->format('Y-m-d'), 'booking_code' => 'BKG-608'],
                ['id' => 609, 'member_id' => 41, 'trainer_id' => 9, 'status' => 'completed', 'booking_date' => $today->modify('-9 days')->format('Y-m-d'), 'booking_code' => 'BKG-609'],
            ],
            'feedback' => [[
                'id' => 800,
                'attendance_id' => 504,
                'feedback_category' => 'gym',
                'trainer_id' => null,
                'rating' => 1,
                'comments' => 'Another member feedback.',
                'suggestions' => null,
                'is_anonymous' => 0,
                'is_hidden' => 0,
                'moderation_reason' => null,
                'moderated_by_user_id' => null,
                'moderated_at' => null,
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),
            ]],
            'gym_system_feedback' => [[
                'id' => 999,
                'member_id' => 42,
                'feedback_date' => $today->modify('-2 days')->format('Y-m-d'),
                'overall_rating' => 2,
                'overall_comments' => 'Another member feedback.',
                'facilities_rating' => null,
                'facilities_comments' => null,
                'services_rating' => null,
                'services_comments' => null,
                'system_rating' => null,
                'system_comments' => null,
                'is_hidden' => 0,
                'moderation_reason' => null,
                'moderated_at' => null,
                'submitted_at' => $today->modify('-2 days')->format('Y-m-d H:i:s'),
            ]],
            'next_feedback_id' => 901,
            'next_gym_system_feedback_id' => 1000,
            'member_account_status' => 'active',
            'moderations' => [],
        ];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new MemberFeedbackMockStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $statement = new MemberFeedbackMockStatement($this, $query);
        $statement->execute();
        return $statement;
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
        return (string)($this->state['last_insert_id'] ?? ($this->state['next_feedback_id'] - 1));
    }

    public function run(string $query, array $params): array {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($query)));

        if (str_contains($sql, 'from members') && str_contains($sql, 'where id = :member_id')) {
            return ['rows' => [['id' => 41, 'account_status' => $this->state['member_account_status'] ?? 'active']], 'affected' => 1];
        }
        if (str_contains($sql, 'from members')
            && str_contains($sql, 'where user_id = :user_id')
            && str_contains($sql, 'account_status')) {
            return ['rows' => [['id' => 41, 'account_status' => $this->state['member_account_status'] ?? 'active']], 'affected' => 1];
        }
        if (str_contains($sql, 'from members') && str_contains($sql, 'where user_id = :user_id')) {
            return ['rows' => [['id' => 41]], 'affected' => 1];
        }
        if (str_contains($sql, 'from gym_system_feedback') && str_contains($sql, 'feedback_date = :feedback_date')) {
            $entry = null;
            foreach ($this->state['gym_system_feedback'] ?? [] as $feedback) {
                if ($feedback['member_id'] === (int)$params[':member_id']
                    && $feedback['feedback_date'] === $params[':feedback_date']) {
                    $entry = $feedback;
                    break;
                }
            }
            return ['rows' => $entry ? [$entry] : [], 'affected' => $entry ? 1 : 0];
        }
        if (str_contains($sql, 'from gym_system_feedback') && str_contains($sql, 'where member_id = :member_id')) {
            $rows = array_values(array_filter(
                $this->state['gym_system_feedback'] ?? [],
                static fn(array $entry): bool => $entry['member_id'] === (int)$params[':member_id']
            ));
            usort($rows, static fn(array $left, array $right): int => [$right['feedback_date'], $right['id']] <=> [$left['feedback_date'], $left['id']]);
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        if (str_starts_with($sql, 'update gym_system_feedback set is_hidden = 1')) {
            foreach ($this->state['gym_system_feedback'] as &$entry) {
                if ($entry['id'] === (int)$params[':feedback_id'] && (int)$entry['is_hidden'] === 0) {
                    $entry['is_hidden'] = 1;
                    $entry['moderation_reason'] = $params[':reason'];
                    $entry['moderated_by_user_id'] = (int)$params[':admin_user_id'];
                    $entry['moderated_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
                    unset($entry);
                    return ['rows' => [], 'affected' => 1];
                }
            }
            unset($entry);
            return ['rows' => [], 'affected' => 0];
        }
        if (str_contains($sql, 'date_format(f.feedback_date')) {
            $grouped = [];
            foreach ($this->state['gym_system_feedback'] ?? [] as $entry) {
                $month = substr($entry['feedback_date'], 0, 7);
                $grouped[$month][] = $entry;
            }
            $rows = [];
            foreach ($grouped as $month => $entries) {
                $row = ['month' => $month, 'submissions' => count($entries)];
                foreach (['overall', 'facilities', 'services', 'system'] as $section) {
                    $column = $section . '_rating';
                    $ratings = array_values(array_filter(array_map(
                        static fn(array $entry): ?int => $entry[$column] === null ? null : (int)$entry[$column],
                        $entries
                    ), static fn(?int $value): bool => $value !== null));
                    $row[$section . '_total'] = count($ratings);
                    $row[$section . '_average'] = $ratings ? array_sum($ratings) / count($ratings) : null;
                }
                $rows[] = $row;
            }
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        if (str_contains($sql, 'from gym_system_feedback f')
            && str_contains($sql, 'select count(*) as total')) {
            $ratings = [];
            foreach ($this->state['gym_system_feedback'] ?? [] as $entry) {
                foreach (['overall_rating', 'facilities_rating', 'services_rating', 'system_rating'] as $column) {
                    if ($entry[$column] !== null) $ratings[] = (int)$entry[$column];
                }
            }
            $row = [
                'total' => count($this->state['gym_system_feedback'] ?? []),
                'rating_total' => count($ratings),
                'average_rating' => $ratings ? array_sum($ratings) / count($ratings) : null,
            ];
            for ($star = 1; $star <= 5; $star++) {
                $row['rating_' . $star] = count(array_filter($ratings, static fn(int $value): bool => $value === $star));
            }
            return ['rows' => [$row], 'affected' => 1];
        }
        if (str_contains($sql, 'from gym_system_feedback f')
            && preg_match('/select count\\(f\\.(\\w+)\\) as total/', $sql, $columnMatch)) {
            $column = $columnMatch[1];
            $ratings = array_values(array_filter(array_map(
                static fn(array $entry): ?int => $entry[$column] === null ? null : (int)$entry[$column],
                $this->state['gym_system_feedback'] ?? []
            ), static fn(?int $value): bool => $value !== null));
            $row = [
                'total' => count($ratings),
                'average_rating' => $ratings ? array_sum($ratings) / count($ratings) : null,
            ];
            for ($star = 1; $star <= 5; $star++) {
                $row['rating_' . $star] = count(array_filter($ratings, static fn(int $value): bool => $value === $star));
            }
            return ['rows' => [$row], 'affected' => 1];
        }
        if (str_contains($sql, 'select count(*) from gym_system_feedback f join members')) {
            return ['rows' => [['count' => count($this->state['gym_system_feedback'] ?? [])]], 'affected' => 1];
        }
        if (str_contains($sql, "'gym_system' as feedback_type")) {
            $rows = $this->state['gym_system_feedback'] ?? [];
            usort($rows, static fn(array $left, array $right): int => [$right['feedback_date'], $right['submitted_at'], $right['id']] <=> [$left['feedback_date'], $left['submitted_at'], $left['id']]);
            if (isset($params[':offset'])) $rows = array_slice($rows, (int)$params[':offset'], (int)$params[':per_page']);
            foreach ($rows as &$entry) {
                $entry['feedback_type'] = 'gym_system';
                $entry['member_name'] = $entry['member_id'] === 41 ? 'Test Member' : 'Other Member';
            }
            unset($entry);
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        if (str_starts_with($sql, 'insert into gym_system_feedback')) {
            if (!empty($this->state['simulate_gym_system_feedback_race'])) {
                $error = new PDOException('Duplicate daily feedback.', 23000);
                $error->errorInfo = ['23000', 1062, 'Duplicate entry'];
                throw $error;
            }
            foreach ($this->state['gym_system_feedback'] ?? [] as $entry) {
                if ($entry['member_id'] === (int)$params[':member_id']
                    && $entry['feedback_date'] === $params[':feedback_date']) {
                    $error = new PDOException('Duplicate daily feedback.', 23000);
                    $error->errorInfo = ['23000', 1062, 'Duplicate entry'];
                    throw $error;
                }
            }
            $entry = [
                'id' => $this->state['next_gym_system_feedback_id']++,
                'member_id' => (int)$params[':member_id'],
                'feedback_date' => $params[':feedback_date'],
                'overall_rating' => $params[':overall_rating'],
                'overall_comments' => $params[':overall_comments'],
                'facilities_rating' => $params[':facilities_rating'],
                'facilities_comments' => $params[':facilities_comments'],
                'services_rating' => $params[':services_rating'],
                'services_comments' => $params[':services_comments'],
                'system_rating' => $params[':system_rating'],
                'system_comments' => $params[':system_comments'],
                'is_hidden' => 0,
                'moderation_reason' => null,
                'moderated_at' => null,
                'submitted_at' => $params[':submitted_at'],
            ];
            $this->state['gym_system_feedback'][] = $entry;
            $this->state['last_insert_id'] = $entry['id'];
            return ['rows' => [], 'affected' => 1];
        }
        if (str_contains($sql, 'select t.id from trainers t') && str_contains($sql, 'where t.id = :trainer_id')) {
            return ['rows' => ((int)$params[':trainer_id'] === 9) ? [['id' => 9]] : [], 'affected' => 1];
        }
        if (str_contains($sql, 'select t.id from trainers t') && str_contains($sql, 'where t.user_id = :user_id')) {
            return ['rows' => ((int)$params[':user_id'] === 701) ? [['id' => 9]] : [], 'affected' => 1];
        }
        if (str_contains($sql, 'from bookings b') && str_contains($sql, 'where b.id = :booking_id')) {
            foreach ($this->state['bookings'] as $booking) {
                if ($booking['id'] === (int)$params[':booking_id']
                    && $booking['member_id'] === (int)$params[':member_id']
                    && $booking['status'] === 'completed') {
                    return ['rows' => [['trainer_id' => $booking['trainer_id']]], 'affected' => 1];
                }
            }
            return ['rows' => [], 'affected' => 0];
        }
        if (str_contains($sql, 'from attendance a') && str_contains($sql, 'left join member_feedback gym')) {
            $rows = [];
            foreach ($this->state['attendance'] as $visit) {
                if ($visit['member_id'] !== (int)$params[':member_id']
                    || !$visit['qr_linked']
                    || $visit['attendance_date'] > $params[':today']) continue;
                $gym = $this->feedbackForVisit($visit['id'], 'gym');
                $trainer = $this->feedbackForVisit($visit['id'], 'trainer');
                $rows[] = [
                    'attendance_id' => $visit['id'],
                    'attendance_date' => $visit['attendance_date'],
                    'time_in' => $visit['time_in'],
                    'time_out' => $visit['time_out'],
                    'gym_feedback_id' => $gym['id'] ?? null,
                    'trainer_feedback_id' => $trainer['id'] ?? null,
                ];
            }
            usort($rows, static fn(array $left, array $right): int => [$right['attendance_date'], $right['time_in']] <=> [$left['attendance_date'], $left['time_in']]);
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        if (str_contains($sql, 'from attendance a') && isset($params[':attendance_id'])) {
            foreach ($this->state['attendance'] as $visit) {
                if ($visit['id'] === (int)$params[':attendance_id']
                    && $visit['member_id'] === (int)$params[':member_id']
                    && $visit['qr_linked']
                    && $visit['time_in'] !== null
                    && $visit['attendance_date'] <= $params[':today']) {
                    return ['rows' => [['id' => $visit['id']]], 'affected' => 1];
                }
            }
            return ['rows' => [], 'affected' => 0];
        }
        if (str_contains($sql, 'from trainers t join users u on u.id = t.user_id')
            && str_contains($sql, 'as trainer_name')
            && !str_contains($sql, 'left join member_feedback f')) {
            return ['rows' => [['trainer_id' => 9, 'trainer_name' => 'Test Trainer']], 'affected' => 1];
        }
        if (str_starts_with($sql, 'select t.id as trainer_id, u.full_name')
            && !str_contains($sql, 'left join member_feedback f')) {
            return ['rows' => [['trainer_id' => 9, 'full_name' => 'Test Trainer', 'trainer_code' => 'TR-9']], 'affected' => 1];
        }
        if (str_contains($sql, 'from member_feedback f')
            && str_contains($sql, 'join members m on m.id = coalesce(a.member_id, b.member_id)')
            && (str_contains($sql, 'count(*)') || str_contains($sql, 'limit :offset, :per_page'))) {
            if (str_contains($sql, 'count(*)')) {
                return ['rows' => [['count' => count($this->filteredFeedback($params, $sql))]], 'affected' => 1];
            }
            $rows = $this->filteredFeedback($params, $sql);
            usort($rows, static fn(array $left, array $right): int => [$right['created_at'], $right['id']] <=> [$left['created_at'], $left['id']]);
            if (isset($params[':offset'])) $rows = array_slice($rows, (int)$params[':offset'], (int)$params[':per_page']);
            return ['rows' => array_map(fn(array $entry): array => $this->adminFeedbackRow($entry), $rows), 'affected' => count($rows)];
        }
        if (str_contains($sql, 'from member_feedback f')
            && str_contains($sql, 'left join attendance a on a.id = f.attendance_id')
            && str_contains($sql, 'where m.id = :member_id')) {
            $rows = [];
            foreach ($this->state['feedback'] as $entry) {
                $visit = $this->attendanceById((int)($entry['attendance_id'] ?? 0));
                $booking = $this->bookingById((int)($entry['booking_id'] ?? 0));
                $ownerId = $visit['member_id'] ?? $booking['member_id'] ?? null;
                if ($ownerId !== (int)$params[':member_id']) continue;
                $rows[] = array_merge($entry, [
                    'attendance_date' => $visit['attendance_date'] ?? $booking['booking_date'],
                    'time_in' => $visit['time_in'] ?? null,
                    'booking_code' => $booking['booking_code'] ?? null,
                    'trainer_name' => $entry['trainer_id'] ? 'Test Trainer' : null,
                ]);
            }
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        if (str_contains($sql, 'from member_feedback f')
            && str_contains($sql, 'where a.member_id = :member_id')
            && str_contains($sql, "f.feedback_category = 'gym'")) {
            $rows = [];
            foreach ($this->state['feedback'] as $entry) {
                if ($entry['feedback_category'] !== 'gym') continue;
                $visit = $this->attendanceById((int)($entry['attendance_id'] ?? 0));
                if (($visit['member_id'] ?? null) !== (int)$params[':member_id']) continue;
                $rows[] = array_merge($entry, [
                    'attendance_date' => $visit['attendance_date'],
                    'time_in' => $visit['time_in'],
                ]);
            }
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        if (str_contains($sql, 'from member_feedback f') && str_contains($sql, 'group by f.feedback_category')) {
            $rows = [];
            foreach (['gym', 'trainer'] as $category) {
                $entries = array_values(array_filter($this->filteredFeedback($params, $sql), static fn(array $entry): bool => $entry['feedback_category'] === $category));
                if (!$entries) continue;
                $rows[] = $this->aggregateRow($category, $entries);
            }
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        if (str_contains($sql, 'from trainers t') && str_contains($sql, 'left join member_feedback f')) {
            $entries = array_values(array_filter($this->filteredFeedback($params, $sql), static fn(array $entry): bool =>
                $entry['feedback_category'] === 'trainer' && $entry['trainer_id'] === 9
            ));
            $aggregate = $this->aggregateRow('trainer', $entries);
            return ['rows' => [[
                'trainer_id' => 9,
                'trainer_name' => 'Test Trainer',
                'total' => $aggregate['total'] ?? 0,
                'average_rating' => $aggregate['average_rating'] ?? null,
                'rating_1' => $aggregate['rating_1'] ?? 0,
                'rating_2' => $aggregate['rating_2'] ?? 0,
                'rating_3' => $aggregate['rating_3'] ?? 0,
                'rating_4' => $aggregate['rating_4'] ?? 0,
                'rating_5' => $aggregate['rating_5'] ?? 0,
            ]], 'affected' => 1];
        }
        if (str_contains($sql, 'date_format(f.created_at')) {
            $grouped = [];
            foreach ($this->filteredFeedback($params, $sql) as $entry) {
                if ($entry['created_at'] < $params[':trend_start']) continue;
                $key = substr($entry['created_at'], 0, 7) . '|' . $entry['feedback_category'];
                $grouped[$key][] = $entry;
            }
            $rows = [];
            foreach ($grouped as $key => $entries) {
                [$month, $category] = explode('|', $key);
                $rows[] = ['month' => $month, 'feedback_category' => $category, 'total' => count($entries), 'average_rating' => array_sum(array_column($entries, 'rating')) / count($entries)];
            }
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        if (str_contains($sql, 'select count(*) as total, avg(rating)')) {
            $entries = array_values(array_filter($this->state['feedback'], static fn(array $entry): bool =>
                $entry['feedback_category'] === 'trainer' && $entry['trainer_id'] === (int)$params[':trainer_id']
            ));
            $row = $this->aggregateRow('trainer', $entries);
            $row['hidden_total'] = count(array_filter($entries, static fn(array $entry): bool => (int)$entry['is_hidden'] === 1));
            return ['rows' => [$row], 'affected' => 1];
        }
        if (str_contains($sql, 'from member_feedback f') && str_contains($sql, 'f.is_hidden = 0')) {
            $rows = [];
            foreach ($this->state['feedback'] as $entry) {
                if ($entry['feedback_category'] !== 'trainer' || $entry['trainer_id'] !== (int)$params[':trainer_id'] || (int)$entry['is_hidden'] === 1) continue;
                $visit = $this->attendanceById((int)($entry['attendance_id'] ?? 0));
                $booking = $this->bookingById((int)($entry['booking_id'] ?? 0));
                $rows[] = [
                    'id' => $entry['id'],
                    'rating' => $entry['rating'],
                    'comments' => $entry['comments'],
                    'suggestions' => $entry['suggestions'],
                    'is_anonymous' => $entry['is_anonymous'],
                    'created_at' => $entry['created_at'],
                    'feedback_date' => $visit['attendance_date'] ?? $booking['booking_date'] ?? null,
                ];
            }
            return ['rows' => $rows, 'affected' => count($rows)];
        }
        if (str_starts_with($sql, 'insert into member_feedback')) {
            foreach ($this->state['feedback'] as $entry) {
                $sameAttendance = $params[':attendance_id'] !== null
                    && ($entry['attendance_id'] ?? null) === (int)$params[':attendance_id'];
                $sameBooking = $params[':booking_id'] !== null
                    && ($entry['booking_id'] ?? null) === (int)$params[':booking_id'];
                if (($sameAttendance || $sameBooking) && $entry['feedback_category'] === $params[':feedback_category']) {
                    $error = new PDOException('Duplicate feedback.', 23000);
                    $error->errorInfo = ['23000', 1062, 'Duplicate entry'];
                    throw $error;
                }
            }
            $this->state['feedback'][] = [
                'id' => $this->state['next_feedback_id']++,
                'attendance_id' => $params[':attendance_id'] === null ? null : (int)$params[':attendance_id'],
                'booking_id' => $params[':booking_id'] === null ? null : (int)$params[':booking_id'],
                'feedback_category' => $params[':feedback_category'],
                'trainer_id' => $params[':trainer_id'] === null ? null : (int)$params[':trainer_id'],
                'rating' => (int)$params[':rating'],
                'comments' => $params[':comments'],
                'suggestions' => $params[':suggestions'],
                'is_anonymous' => (int)$params[':is_anonymous'],
                'is_hidden' => 0,
                'moderation_reason' => null,
                'moderated_by_user_id' => null,
                'moderated_at' => null,
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),
            ];
            $this->state['last_insert_id'] = $this->state['next_feedback_id'] - 1;
            return ['rows' => [], 'affected' => 1];
        }
        if (str_starts_with($sql, 'update member_feedback set is_hidden = 1')) {
            foreach ($this->state['feedback'] as &$entry) {
                if ($entry['id'] === (int)$params[':feedback_id'] && (int)$entry['is_hidden'] === 0) {
                    $entry['is_hidden'] = 1;
                    $entry['moderation_reason'] = $params[':reason'];
                    $entry['moderated_by_user_id'] = (int)$params[':admin_user_id'];
                    $entry['moderated_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
                    unset($entry);
                    return ['rows' => [], 'affected' => 1];
                }
            }
            unset($entry);
            return ['rows' => [], 'affected' => 0];
        }
        if (str_starts_with($sql, 'select t.id as trainer_id, u.full_name as trainer_name')) {
            return ['rows' => [['trainer_id' => 9, 'trainer_name' => 'Test Trainer']], 'affected' => 1];
        }

        throw new RuntimeException('Unhandled member feedback mock SQL: ' . $sql);
    }

    private function attendanceById(int $attendanceId): ?array {
        foreach ($this->state['attendance'] as $visit) {
            if ($visit['id'] === $attendanceId) return $visit;
        }
        return null;
    }

    private function bookingById(int $bookingId): ?array {
        foreach ($this->state['bookings'] as $booking) {
            if ($booking['id'] === $bookingId) return $booking;
        }
        return null;
    }

    private function feedbackForVisit(int $attendanceId, string $category): ?array {
        foreach ($this->state['feedback'] as $entry) {
            if ($entry['attendance_id'] === $attendanceId && $entry['feedback_category'] === $category) return $entry;
        }
        return null;
    }

    private function filteredFeedback(array $params, string $sql = ''): array {
        return array_values(array_filter($this->state['feedback'], static function (array $entry) use ($params, $sql): bool {
            if (isset($params[':filter_category']) && $entry['feedback_category'] !== $params[':filter_category']) return false;
            if (isset($params[':filter_rating']) && $entry['rating'] !== (int)$params[':filter_rating']) return false;
            if (isset($params[':filter_trainer_id']) && $entry['trainer_id'] !== (int)$params[':filter_trainer_id']) return false;
            if (isset($params[':filter_date_from']) && $entry['created_at'] < $params[':filter_date_from']) return false;
            if (isset($params[':filter_date_to']) && $entry['created_at'] >= $params[':filter_date_to']) return false;
            if (str_contains($sql, 'f.is_anonymous = 1') && (int)$entry['is_anonymous'] !== 1) return false;
            if (str_contains($sql, 'f.is_anonymous = 0') && (int)$entry['is_anonymous'] !== 0) return false;
            if (str_contains($sql, 'f.is_hidden = 1') && (int)$entry['is_hidden'] !== 1) return false;
            if (str_contains($sql, 'f.is_hidden = 0') && (int)$entry['is_hidden'] !== 0) return false;
            return true;
        }));
    }

    private function aggregateRow(string $category, array $entries): array {
        $row = [
            'feedback_category' => $category,
            'total' => count($entries),
            'average_rating' => $entries ? array_sum(array_column($entries, 'rating')) / count($entries) : null,
            'positive_total' => 0,
            'negative_total' => 0,
        ];
        for ($star = 1; $star <= 5; $star++) {
            $row['rating_' . $star] = count(array_filter($entries, static fn(array $entry): bool => (int)$entry['rating'] === $star));
        }
        foreach ($entries as $entry) {
            if ((int)$entry['rating'] >= 4) $row['positive_total']++;
            if ((int)$entry['rating'] <= 2) $row['negative_total']++;
        }
        return $row;
    }

    private function adminFeedbackRow(array $entry): array {
        $visit = $this->attendanceById((int)($entry['attendance_id'] ?? 0));
        $booking = $this->bookingById((int)($entry['booking_id'] ?? 0));
        $memberId = $visit['member_id'] ?? $booking['member_id'] ?? null;
        return array_merge($entry, [
            'time_in' => $visit['time_in'] ?? null,
            'attendance_date' => $visit['attendance_date'] ?? $booking['booking_date'] ?? null,
            'booking_code' => $booking['booking_code'] ?? null,
            'member_id' => $memberId,
            'member_name' => $memberId === 41 ? 'Test Member' : 'Other Member',
            'trainer_name' => $entry['trainer_id'] ? 'Test Trainer' : null,
        ]);
    }
}

class MemberFeedbackMockStatement extends PDOStatement {
    private array $rows = [];
    private int $cursor = 0;
    private int $affected = 0;
    private array $bound = [];

    public function __construct(private MemberFeedbackMockPDO $connection, private string $query) {}

    public function execute(?array $params = null): bool {
        $result = $this->connection->run($this->query, $params ?? $this->bound);
        $this->rows = $result['rows'];
        $this->affected = $result['affected'];
        $this->cursor = 0;
        return true;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        $this->bound[$param] = $value;
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

    public function rowCount(): int {
        return $this->affected;
    }
}

?>
