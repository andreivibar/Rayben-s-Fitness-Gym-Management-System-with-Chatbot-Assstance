<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

function memberFitnessResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function memberFitnessDateIsValid(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
    return $date !== false && $date->format('Y-m-d') === $value;
}

function memberFitnessWeekStart(string $value): bool
{
    if (!memberFitnessDateIsValid($value)) {
        return false;
    }
    return (new DateTimeImmutable($value, new DateTimeZone('Asia/Manila')))->format('N') === '1';
}

function memberFitnessCurrentWeekStart(): string
{
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    return $today->modify('-' . ((int)$today->format('N') - 1) . ' days')->format('Y-m-d');
}

function memberFitnessPositiveInteger($value, string $label, int $maximum, bool $nullable = false): ?int
{
    if ($nullable && ($value === null || $value === '')) {
        return null;
    }
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        memberFitnessResponse(['success' => false, 'message' => $label . ' must be a whole number.'], 400);
    }
    $number = (int)$value;
    if ($number < 1 || $number > $maximum) {
        memberFitnessResponse(['success' => false, 'message' => $label . ' must be between 1 and ' . $maximum . '.'], 400);
    }
    return $number;
}

function memberFitnessOptionalWeight($value, string $label): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value) || (float)$value <= 0 || (float)$value > 9999.99) {
        memberFitnessResponse(['success' => false, 'message' => $label . ' must be greater than 0 and no more than 9999.99 kg.'], 400);
    }
    return number_format((float)$value, 2, '.', '');
}

function memberFitnessExercises($value, bool $planned): array
{
    if (!is_array($value) || count($value) > 40) {
        memberFitnessResponse(['success' => false, 'message' => 'Provide up to 40 exercises as a list.'], 400);
    }

    $exercises = [];
    foreach ($value as $index => $exercise) {
        if (!is_array($exercise)) {
            memberFitnessResponse(['success' => false, 'message' => 'Each exercise must be an object.'], 400);
        }
        $name = trim((string)($exercise['exercise_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            memberFitnessResponse(['success' => false, 'message' => 'Exercise names are required and must be 100 characters or fewer.'], 400);
        }

        if ($planned) {
            $sets = memberFitnessPositiveInteger($exercise['target_sets'] ?? null, 'Target sets', 100);
            $reps = trim((string)($exercise['target_reps'] ?? ''));
            if ($reps === '' || mb_strlen($reps) > 40) {
                memberFitnessResponse(['success' => false, 'message' => 'Target repetitions are required and must be 40 characters or fewer.'], 400);
            }
            $exercises[] = [
                'exercise_name' => $name,
                'target_sets' => $sets,
                'target_reps' => $reps,
                'target_weight_kg' => memberFitnessOptionalWeight($exercise['target_weight_kg'] ?? null, 'Target weight'),
                'target_duration_minutes' => memberFitnessPositiveInteger($exercise['target_duration_minutes'] ?? null, 'Target duration', 1440, true),
                'sort_order' => $index,
            ];
            continue;
        }

        $setsCompleted = memberFitnessPositiveInteger($exercise['sets_completed'] ?? null, 'Completed sets', 100, true);
        $repsCompleted = trim((string)($exercise['reps_completed'] ?? ''));
        if (mb_strlen($repsCompleted) > 40) {
            memberFitnessResponse(['success' => false, 'message' => 'Completed repetitions must be 40 characters or fewer.'], 400);
        }
        $plannedExerciseId = isset($exercise['planned_exercise_id']) && $exercise['planned_exercise_id'] !== ''
            ? memberFitnessPositiveInteger($exercise['planned_exercise_id'], 'Planned exercise ID', PHP_INT_MAX)
            : null;
        $exercises[] = [
            'planned_exercise_id' => $plannedExerciseId,
            'exercise_name' => $name,
            'sets_completed' => $setsCompleted,
            'reps_completed' => $repsCompleted !== '' ? $repsCompleted : null,
            'actual_weight_kg' => memberFitnessOptionalWeight($exercise['actual_weight_kg'] ?? null, 'Actual weight'),
            'duration_minutes' => memberFitnessPositiveInteger($exercise['duration_minutes'] ?? null, 'Exercise duration', 1440, true),
            'is_completed' => !empty($exercise['is_completed']) ? 1 : 0,
            'sort_order' => $index,
        ];
    }
    return $exercises;
}

function memberFitnessEnsureWeek(PDO $conn, int $memberId, string $weekStart): int
{
    $insert = $conn->prepare(
        'INSERT INTO member_workout_weeks (member_id, week_start)
         VALUES (:member_id, :week_start)
         ON DUPLICATE KEY UPDATE id = id'
    );
    $insert->execute([':member_id' => $memberId, ':week_start' => $weekStart]);

    $select = $conn->prepare(
        'SELECT id FROM member_workout_weeks
         WHERE member_id = :member_id AND week_start = :week_start
         LIMIT 1'
    );
    $select->execute([':member_id' => $memberId, ':week_start' => $weekStart]);
    return (int)$select->fetchColumn();
}

try {
    $authUser = requireRole($conn, ['member']);
    $memberStmt = $conn->prepare('SELECT id FROM members WHERE user_id = :user_id LIMIT 1');
    $memberStmt->execute([':user_id' => (int)$authUser['id']]);
    $memberId = (int)$memberStmt->fetchColumn();
    if ($memberId < 1) {
        memberFitnessResponse(['success' => false, 'message' => 'Member profile not found.'], 404);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $action = strtolower(trim((string)($_GET['action'] ?? 'week')));
    $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $input = $method === 'POST' ? json_decode($rawInput, true) : [];
    if ($method === 'POST' && !is_array($input)) {
        memberFitnessResponse(['success' => false, 'message' => 'A valid JSON request is required.'], 400);
    }

    if ($method === 'GET' && in_array($action, ['week', 'progress'], true)) {
        $timezone = new DateTimeZone('Asia/Manila');
        $today = new DateTimeImmutable('today', $timezone);
        $defaultWeekStart = $today->modify('-' . ((int)$today->format('N') - 1) . ' days')->format('Y-m-d');
        $weekStart = trim((string)($_GET['week_start'] ?? $defaultWeekStart));
        if (!memberFitnessWeekStart($weekStart)) {
            memberFitnessResponse(['success' => false, 'message' => 'Week start must be a valid Monday date.'], 400);
        }
        $weekEnd = (new DateTimeImmutable($weekStart, $timezone))->modify('+6 days')->format('Y-m-d');

        $weekStmt = $conn->prepare(
            'SELECT id, weekly_goal FROM member_workout_weeks
             WHERE member_id = :member_id AND week_start = :week_start
             LIMIT 1'
        );
        $weekStmt->execute([':member_id' => $memberId, ':week_start' => $weekStart]);
        $week = $weekStmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => null, 'weekly_goal' => 3];
        $planStmt = $conn->prepare(
            "SELECT p.id, p.workout_date, p.title, p.description, p.is_rest_day, p.status
             FROM member_workout_plans p
             JOIN member_workout_weeks w ON w.id = p.week_id
             WHERE w.member_id = :member_id AND w.week_start = :week_start
             ORDER BY p.workout_date ASC, p.id ASC"
        );
        $planStmt->execute([':member_id' => $memberId, ':week_start' => $weekStart]);
        $plans = $planStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($plans as &$plan) {
            $plan['id'] = (int)$plan['id'];
            $plan['is_rest_day'] = (bool)$plan['is_rest_day'];
            $plan['exercises'] = [];
        }
        unset($plan);

        if ($plans) {
            $planIds = array_column($plans, 'id');
            $placeholders = implode(',', array_fill(0, count($planIds), '?'));
            $exerciseStmt = $conn->prepare(
                "SELECT id, plan_id, exercise_name, target_sets, target_reps,
                        target_weight_kg, target_duration_minutes, sort_order
                 FROM member_workout_plan_exercises
                 WHERE plan_id IN ($placeholders)
                 ORDER BY plan_id, sort_order, id"
            );
            $exerciseStmt->execute($planIds);
            $exercisesByPlan = [];
            foreach ($exerciseStmt->fetchAll(PDO::FETCH_ASSOC) as $exercise) {
                $exercise['id'] = (int)$exercise['id'];
                $exercise['target_sets'] = (int)$exercise['target_sets'];
                if ($exercise['target_duration_minutes'] !== null) {
                    $exercise['target_duration_minutes'] = (int)$exercise['target_duration_minutes'];
                }
                $exercisesByPlan[(int)$exercise['plan_id']][] = $exercise;
            }
            foreach ($plans as &$plan) {
                $plan['exercises'] = $exercisesByPlan[$plan['id']] ?? [];
            }
            unset($plan);
        }

        if ($action === 'week') {
            memberFitnessResponse([
                'success' => true,
                'week' => [
                    'week_start' => $weekStart,
                    'week_end' => $weekEnd,
                    'weekly_goal' => (int)$week['weekly_goal'],
                ],
                'plans' => $plans,
            ]);
        }

        $statsStmt = $conn->prepare(
            "SELECT
                (SELECT COUNT(*) FROM member_workout_plans p
                 JOIN member_workout_weeks w ON w.id = p.week_id
                 WHERE w.member_id = :plan_member_id
                   AND w.week_start = :plan_week_start
                   AND p.is_rest_day = 0) AS planned_count,
                (SELECT COUNT(*) FROM member_workout_plans p
                 JOIN member_workout_weeks w ON w.id = p.week_id
                 WHERE w.member_id = :completed_member_id
                   AND w.week_start = :completed_week_start
                   AND p.is_rest_day = 0
                   AND p.status = 'completed') AS completed_count,
                (SELECT COALESCE(SUM(s.duration_minutes), 0) FROM member_workout_sessions s
                 WHERE s.member_id = :duration_member_id
                   AND s.workout_date BETWEEN :duration_week_start AND :duration_week_end) AS duration_minutes,
                (SELECT COUNT(*) FROM member_workout_exercise_logs e
                 JOIN member_workout_sessions s ON s.id = e.workout_session_id
                 WHERE s.member_id = :exercise_member_id
                   AND s.workout_date BETWEEN :exercise_week_start AND :exercise_week_end
                   AND e.is_completed = 1) AS exercises_completed"
        );
        $statsStmt->execute([
            ':plan_member_id' => $memberId,
            ':plan_week_start' => $weekStart,
            ':completed_member_id' => $memberId,
            ':completed_week_start' => $weekStart,
            ':duration_member_id' => $memberId,
            ':duration_week_start' => $weekStart,
            ':duration_week_end' => $weekEnd,
            ':exercise_member_id' => $memberId,
            ':exercise_week_start' => $weekStart,
            ':exercise_week_end' => $weekEnd,
        ]);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $plannedCount = (int)($stats['planned_count'] ?? 0);
        $completedCount = (int)($stats['completed_count'] ?? 0);
        $recordsStmt = $conn->prepare(
            'SELECT e.exercise_name, MAX(e.actual_weight_kg) AS max_weight_kg
             FROM member_workout_exercise_logs e
             JOIN member_workout_sessions s ON s.id = e.workout_session_id
             WHERE s.member_id = :member_id
               AND e.is_completed = 1
               AND e.actual_weight_kg IS NOT NULL
             GROUP BY e.exercise_name
             ORDER BY max_weight_kg DESC, e.exercise_name ASC
             LIMIT 5'
        );
        $recordsStmt->execute([':member_id' => $memberId]);
        memberFitnessResponse([
            'success' => true,
            'week' => ['week_start' => $weekStart, 'week_end' => $weekEnd],
            'stats' => [
                'planned_workouts' => $plannedCount,
                'completed_workouts' => $completedCount,
                'remaining_workouts' => max(0, $plannedCount - $completedCount),
                'completion_percentage' => $plannedCount > 0
                    ? min(100, (int)round(($completedCount / $plannedCount) * 100))
                    : null,
                'duration_minutes' => (int)($stats['duration_minutes'] ?? 0),
                'exercises_completed' => (int)($stats['exercises_completed'] ?? 0),
                'personal_records' => $recordsStmt->fetchAll(PDO::FETCH_ASSOC),
            ],
        ]);
    }

    if ($method === 'GET' && $action === 'history') {
        $timezone = new DateTimeZone('Asia/Manila');
        $today = new DateTimeImmutable('today', $timezone);
        $defaultStart = $today->modify('-6 days')->format('Y-m-d');
        $from = trim((string)($_GET['from'] ?? $defaultStart));
        $to = trim((string)($_GET['to'] ?? $today->format('Y-m-d')));
        if (!memberFitnessDateIsValid($from) || !memberFitnessDateIsValid($to) || $from > $to) {
            memberFitnessResponse(['success' => false, 'message' => 'History dates must be valid and the start date must not follow the end date.'], 400);
        }

        $sessionsStmt = $conn->prepare(
            "SELECT s.id, s.planned_workout_id, s.workout_date, s.status,
                    s.duration_minutes, s.notes, s.created_at,
                    p.title AS planned_title, p.description AS planned_description
             FROM member_workout_sessions s
             LEFT JOIN member_workout_plans p
               ON p.id = s.planned_workout_id
              AND EXISTS (
                  SELECT 1 FROM member_workout_weeks pw
                  WHERE pw.id = p.week_id AND pw.member_id = s.member_id
              )
             WHERE s.member_id = :member_id
               AND (
                   s.workout_date BETWEEN :date_from AND :date_to
                   OR (
                       p.status = 'completed'
                       AND p.completed_at IS NOT NULL
                       AND DATE(p.completed_at) BETWEEN :plan_date_from AND :plan_date_to
                   )
               )
             ORDER BY s.workout_date DESC, s.id DESC
               LIMIT 100"
        );
        $sessionsStmt->execute([
            ':member_id' => $memberId,
            ':date_from' => $from,
            ':date_to' => $to,
            ':plan_date_from' => $from,
            ':plan_date_to' => $to,
        ]);
        $sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sessions as &$session) {
            $session['id'] = (int)$session['id'];
            if ($session['planned_workout_id'] !== null) {
                $session['planned_workout_id'] = (int)$session['planned_workout_id'];
            }
            $sessionDate = new DateTimeImmutable($session['workout_date'], $timezone);
            $session['week_start'] = $sessionDate
                ->modify('-' . ((int)$sessionDate->format('N') - 1) . ' days')
                ->format('Y-m-d');
            if ($session['duration_minutes'] !== null) {
                $session['duration_minutes'] = (int)$session['duration_minutes'];
            }
            $session['exercises'] = [];
        }
        unset($session);
        if ($sessions) {
            $sessionIds = array_column($sessions, 'id');
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $exerciseStmt = $conn->prepare(
                "SELECT id, workout_session_id, planned_exercise_id, exercise_name,
                        sets_completed, reps_completed, actual_weight_kg, duration_minutes,
                        is_completed, sort_order
                 FROM member_workout_exercise_logs
                 WHERE workout_session_id IN ($placeholders)
                 ORDER BY workout_session_id, sort_order, id"
            );
            $exerciseStmt->execute($sessionIds);
            $exerciseMap = [];
            foreach ($exerciseStmt->fetchAll(PDO::FETCH_ASSOC) as $exercise) {
                $exercise['id'] = (int)$exercise['id'];
                $exercise['is_completed'] = (bool)$exercise['is_completed'];
                $exerciseMap[(int)$exercise['workout_session_id']][] = $exercise;
            }
            foreach ($sessions as &$session) {
                $session['exercises'] = $exerciseMap[$session['id']] ?? [];
            }
            unset($session);
        }
        $plannedCompletionsStmt = $conn->prepare(
            "SELECT p.id AS plan_id, p.title, p.description, p.workout_date,
                    DATE(p.completed_at) AS completion_date, w.week_start
             FROM member_workout_plans p
             JOIN member_workout_weeks w ON w.id = p.week_id
             WHERE w.member_id = :member_id
               AND p.is_rest_day = 0
               AND p.status = 'completed'
               AND p.completed_at IS NOT NULL
               AND DATE(p.completed_at) BETWEEN :date_from AND :date_to
             ORDER BY w.week_start DESC, p.completed_at DESC, p.id DESC
             LIMIT 100"
        );
        $plannedCompletionsStmt->execute([
            ':member_id' => $memberId,
            ':date_from' => $from,
            ':date_to' => $to,
        ]);
        $plannedCompletions = $plannedCompletionsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($plannedCompletions as &$completion) {
            $completion['plan_id'] = (int)$completion['plan_id'];
        }
        unset($completion);
        memberFitnessResponse([
            'success' => true,
            'sessions' => $sessions,
            'planned_completions' => $plannedCompletions,
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST, OPTIONS');
        memberFitnessResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    if ($action === 'save-goal') {
        $weekStart = trim((string)($input['week_start'] ?? ''));
        if (!memberFitnessWeekStart($weekStart)) {
            memberFitnessResponse(['success' => false, 'message' => 'Week start must be a valid Monday date.'], 400);
        }
        $goal = filter_var($input['weekly_goal'] ?? null, FILTER_VALIDATE_INT);
        if ($goal === false || $goal < 0 || $goal > 14) {
            memberFitnessResponse(['success' => false, 'message' => 'Weekly workout goal must be between 0 and 14.'], 400);
        }
        $weekId = memberFitnessEnsureWeek($conn, $memberId, $weekStart);
        $update = $conn->prepare('UPDATE member_workout_weeks SET weekly_goal = :weekly_goal WHERE id = :id AND member_id = :member_id');
        $update->execute([':weekly_goal' => $goal, ':id' => $weekId, ':member_id' => $memberId]);
        memberFitnessResponse(['success' => true, 'weekly_goal' => $goal]);
    }

    if ($action === 'save-plan') {
        if (!is_string($input['week_start'] ?? null) || !is_string($input['workout_date'] ?? null)) {
            memberFitnessResponse(['success' => false, 'message' => 'A valid week start and workout date are required.'], 400);
        }
        $weekStart = trim($input['week_start']);
        $workoutDate = trim($input['workout_date']);
        if (!memberFitnessWeekStart($weekStart) || !memberFitnessDateIsValid($workoutDate)) {
            memberFitnessResponse(['success' => false, 'message' => 'Provide a valid Monday week start and workout date.'], 400);
        }
        $weekEnd = (new DateTimeImmutable($weekStart, new DateTimeZone('Asia/Manila')))->modify('+6 days')->format('Y-m-d');
        if ($workoutDate < $weekStart || $workoutDate > $weekEnd) {
            memberFitnessResponse(['success' => false, 'message' => 'Workout date must fall within the selected week.'], 400);
        }
        $isRestDay = !empty($input['is_rest_day']);
        if (!$isRestDay && !is_string($input['title'] ?? null)) {
            memberFitnessResponse(['success' => false, 'message' => 'Workout title is required and must be 100 characters or fewer.'], 400);
        }
        if (!is_string($input['description'] ?? '')) {
            memberFitnessResponse(['success' => false, 'message' => 'Workout notes must be text and 1000 characters or fewer.'], 400);
        }
        $title = $isRestDay ? 'Rest day' : trim($input['title']);
        $description = trim($input['description'] ?? '');
        $planId = isset($input['plan_id']) && $input['plan_id'] !== ''
            ? memberFitnessPositiveInteger($input['plan_id'], 'Workout plan ID', PHP_INT_MAX)
            : null;
        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        if ($workoutDate > $today && $planId !== null) {
            $completedPlanStmt = $conn->prepare(
                "SELECT p.status FROM member_workout_plans p
                 JOIN member_workout_weeks w ON w.id = p.week_id
                 WHERE p.id = :plan_id AND w.member_id = :member_id
                   AND w.week_start = :week_start
                 LIMIT 1"
            );
            $completedPlanStmt->execute([
                ':plan_id' => $planId,
                ':member_id' => $memberId,
                ':week_start' => $weekStart,
            ]);
            if ($completedPlanStmt->fetchColumn() === 'completed') {
                memberFitnessResponse([
                    'success' => false,
                    'message' => 'A completed workout cannot be moved to a future day.',
                ], 400);
            }
        }
        if ($weekStart !== memberFitnessCurrentWeekStart()) {
            memberFitnessResponse(['success' => false, 'message' => 'Only this week\'s workout activities can be changed.'], 400);
        }
        if ($title === '' || mb_strlen($title) > 100) {
            memberFitnessResponse(['success' => false, 'message' => 'Workout title is required and must be 100 characters or fewer.'], 400);
        }
        if (mb_strlen($description) > 1000) {
            memberFitnessResponse(['success' => false, 'message' => 'Workout notes must be 1000 characters or fewer.'], 400);
        }
        $exercises = memberFitnessExercises($input['exercises'] ?? [], true);
        if ($isRestDay && $exercises) {
            memberFitnessResponse(['success' => false, 'message' => 'Rest days cannot include planned exercises.'], 400);
        }

        $conn->beginTransaction();
        try {
            $weekId = memberFitnessEnsureWeek($conn, $memberId, $weekStart);
            $weekLock = $conn->prepare(
                'SELECT id FROM member_workout_weeks
                 WHERE id = :week_id AND member_id = :member_id
                 LIMIT 1 FOR UPDATE'
            );
            $weekLock->execute([':week_id' => $weekId, ':member_id' => $memberId]);
            if (!$weekLock->fetchColumn()) {
                $conn->rollBack();
                memberFitnessResponse(['success' => false, 'message' => 'Workout week not found. Please retry.'], 409);
            }
            if ($isRestDay && $planId !== null) {
                $restPlanCheck = $conn->prepare(
                    'SELECT p.is_rest_day FROM member_workout_plans p
                     JOIN member_workout_weeks w ON w.id = p.week_id
                     WHERE p.id = :plan_id AND w.member_id = :member_id
                       AND w.week_start = :week_start
                     LIMIT 1 FOR UPDATE'
                );
                $restPlanCheck->execute([
                    ':plan_id' => $planId,
                    ':member_id' => $memberId,
                    ':week_start' => $weekStart,
                ]);
                $existingIsRestDay = $restPlanCheck->fetchColumn();
                if ($existingIsRestDay === false) {
                    $conn->rollBack();
                    memberFitnessResponse(['success' => false, 'message' => 'Workout plan not found.'], 404);
                }
                if (!(bool)$existingIsRestDay) {
                    $conn->rollBack();
                    memberFitnessResponse([
                        'success' => false,
                        'message' => 'This workout cannot be converted into a rest day. Your workout record was preserved.',
                    ], 409);
                }
            }
            $duplicateStmt = $conn->prepare(
                'SELECT p.id FROM member_workout_plans p
                 JOIN member_workout_weeks w ON w.id = p.week_id
                 WHERE w.member_id = :member_id
                   AND w.week_start = :week_start
                   AND p.workout_date = :workout_date
                   AND LOWER(TRIM(p.title)) = LOWER(:title)
                   AND p.is_rest_day = :is_rest_day
                   AND (:plan_id_null IS NULL OR p.id <> :plan_id_match)
                 LIMIT 1'
            );
            $duplicateStmt->execute([
                ':member_id' => $memberId,
                ':week_start' => $weekStart,
                ':workout_date' => $workoutDate,
                ':title' => $title,
                ':is_rest_day' => $isRestDay ? 1 : 0,
                ':plan_id_null' => $planId,
                ':plan_id_match' => $planId,
            ]);
            if ($duplicateStmt->fetchColumn()) {
                $conn->rollBack();
                memberFitnessResponse(['success' => false, 'message' => 'An activity with this name already exists for that day.'], 409);
            }
            $dayConflictStmt = $conn->prepare(
                'SELECT p.id FROM member_workout_plans p
                 JOIN member_workout_weeks w ON w.id = p.week_id
                 WHERE w.member_id = :member_id
                   AND w.week_start = :week_start
                   AND p.workout_date = :workout_date
                   AND p.is_rest_day = :conflicting_rest_day
                   AND (:plan_id_null IS NULL OR p.id <> :plan_id_match)
                 LIMIT 1'
            );
            $dayConflictStmt->execute([
                ':member_id' => $memberId,
                ':week_start' => $weekStart,
                ':workout_date' => $workoutDate,
                ':conflicting_rest_day' => $isRestDay ? 0 : 1,
                ':plan_id_null' => $planId,
                ':plan_id_match' => $planId,
            ]);
            if ($dayConflictStmt->fetchColumn()) {
                $conn->rollBack();
                $message = $isRestDay
                    ? 'This day already has workout activities. Remove them before marking it as a rest day; no activities were changed.'
                    : 'This day is marked as a rest day. Undo the rest day before adding a workout.';
                memberFitnessResponse(['success' => false, 'message' => $message], 409);
            }
            if ($planId !== null) {
                $planCheck = $conn->prepare(
                    'SELECT p.id, p.status FROM member_workout_plans p
                     JOIN member_workout_weeks w ON w.id = p.week_id
                     WHERE p.id = :plan_id AND w.member_id = :member_id AND w.week_start = :week_start
                     LIMIT 1 FOR UPDATE'
                );
                $planCheck->execute([
                    ':plan_id' => $planId,
                    ':member_id' => $memberId,
                    ':week_start' => $weekStart,
                ]);
                $existingPlan = $planCheck->fetch(PDO::FETCH_ASSOC);
                if (!$existingPlan) {
                    $conn->rollBack();
                    memberFitnessResponse(['success' => false, 'message' => 'Workout plan not found.'], 404);
                }
                if ($existingPlan['status'] === 'completed') {
                    $conn->rollBack();
                    memberFitnessResponse([
                        'success' => false,
                        'message' => 'Uncheck this activity before editing it so its completion history stays unchanged.',
                    ], 409);
                }
                $updatePlan = $conn->prepare(
                    'UPDATE member_workout_plans
                     SET week_id = :week_id, workout_date = :workout_date,
                         title = :title, description = :description, is_rest_day = :is_rest_day
                     WHERE id = :id'
                );
                $updatePlan->execute([
                    ':week_id' => $weekId,
                    ':workout_date' => $workoutDate,
                    ':title' => $title,
                    ':description' => $description !== '' ? $description : null,
                    ':is_rest_day' => $isRestDay ? 1 : 0,
                    ':id' => $planId,
                ]);
            } else {
                $insertPlan = $conn->prepare(
                    "INSERT INTO member_workout_plans
                        (week_id, workout_date, title, description, is_rest_day, status)
                     VALUES (:week_id, :workout_date, :title, :description, :is_rest_day, 'planned')"
                );
                $insertPlan->execute([
                    ':week_id' => $weekId,
                    ':workout_date' => $workoutDate,
                    ':title' => $title,
                    ':description' => $description !== '' ? $description : null,
                    ':is_rest_day' => $isRestDay ? 1 : 0,
                ]);
                $planId = (int)$conn->lastInsertId();
            }

            if (array_key_exists('exercises', $input)) {
                $deleteExercises = $conn->prepare('DELETE FROM member_workout_plan_exercises WHERE plan_id = :plan_id');
                $deleteExercises->execute([':plan_id' => $planId]);
                if (!$isRestDay && $exercises) {
                    $insertExercise = $conn->prepare(
                        'INSERT INTO member_workout_plan_exercises
                            (plan_id, exercise_name, target_sets, target_reps, target_weight_kg,
                             target_duration_minutes, sort_order)
                         VALUES
                            (:plan_id, :exercise_name, :target_sets, :target_reps, :target_weight_kg,
                             :target_duration_minutes, :sort_order)'
                    );
                    foreach ($exercises as $exercise) {
                        $insertExercise->execute([
                            ':plan_id' => $planId,
                            ':exercise_name' => $exercise['exercise_name'],
                            ':target_sets' => $exercise['target_sets'],
                            ':target_reps' => $exercise['target_reps'],
                            ':target_weight_kg' => $exercise['target_weight_kg'],
                            ':target_duration_minutes' => $exercise['target_duration_minutes'],
                            ':sort_order' => $exercise['sort_order'],
                        ]);
                    }
                }
            }
            $conn->commit();
            memberFitnessResponse(['success' => true, 'plan_id' => $planId]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    if ($action === 'delete-plan') {
        $planId = memberFitnessPositiveInteger($input['plan_id'] ?? null, 'Workout plan ID', PHP_INT_MAX);
        $check = $conn->prepare(
            'SELECT p.status FROM member_workout_plans p
             JOIN member_workout_weeks w ON w.id = p.week_id
             WHERE p.id = :plan_id AND w.member_id = :member_id
               AND w.week_start = :week_start
             LIMIT 1'
        );
        $check->execute([
            ':plan_id' => $planId,
            ':member_id' => $memberId,
            ':week_start' => memberFitnessCurrentWeekStart(),
        ]);
        $existingPlanStatus = $check->fetchColumn();
        if ($existingPlanStatus === false) {
            memberFitnessResponse(['success' => false, 'message' => 'Workout plan not found.'], 404);
        }
        if ($existingPlanStatus === 'completed') {
            memberFitnessResponse([
                'success' => false,
                'message' => 'Uncheck this activity before deleting it so its completion history stays unchanged.',
            ], 409);
        }
        $delete = $conn->prepare(
            'DELETE p FROM member_workout_plans p
             JOIN member_workout_weeks w ON w.id = p.week_id
             WHERE p.id = :plan_id AND w.member_id = :member_id
               AND w.week_start = :week_start AND p.status <> \'completed\''
        );
        $delete->execute([
            ':plan_id' => $planId,
            ':member_id' => $memberId,
            ':week_start' => memberFitnessCurrentWeekStart(),
        ]);
        if ($delete->rowCount() < 1) {
            memberFitnessResponse(['success' => false, 'message' => 'Workout plan not found.'], 404);
        }
        memberFitnessResponse(['success' => true, 'message' => 'Workout plan deleted. Recorded workout history is preserved.']);
    }

    if ($action === 'delete-rest-day') {
        $planId = memberFitnessPositiveInteger($input['plan_id'] ?? null, 'Rest day ID', PHP_INT_MAX);
        $delete = $conn->prepare(
            'DELETE p FROM member_workout_plans p
             JOIN member_workout_weeks w ON w.id = p.week_id
             WHERE p.id = :plan_id AND w.member_id = :member_id
               AND w.week_start = :week_start AND p.is_rest_day = 1'
        );
        $delete->execute([
            ':plan_id' => $planId,
            ':member_id' => $memberId,
            ':week_start' => memberFitnessCurrentWeekStart(),
        ]);
        if ($delete->rowCount() < 1) {
            memberFitnessResponse(['success' => false, 'message' => 'Rest day not found.'], 404);
        }
        memberFitnessResponse(['success' => true, 'message' => 'Rest day undone. Workout activities were preserved.']);
    }

    if ($action === 'set-plan-status') {
        $planId = memberFitnessPositiveInteger($input['plan_id'] ?? null, 'Workout plan ID', PHP_INT_MAX);
        $status = strtolower(trim((string)($input['status'] ?? '')));
        if (!in_array($status, ['planned', 'completed', 'partial', 'skipped'], true)) {
            memberFitnessResponse(['success' => false, 'message' => 'Choose a valid workout status.'], 400);
        }
        $check = $conn->prepare(
            'SELECT p.id, p.workout_date FROM member_workout_plans p
             JOIN member_workout_weeks w ON w.id = p.week_id
             WHERE p.id = :plan_id AND w.member_id = :member_id AND p.is_rest_day = 0
               AND w.week_start = :week_start
             LIMIT 1'
        );
        $currentWeekStart = memberFitnessCurrentWeekStart();
        $check->execute([
            ':plan_id' => $planId,
            ':member_id' => $memberId,
            ':week_start' => $currentWeekStart,
        ]);
        $plan = $check->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            memberFitnessResponse(['success' => false, 'message' => 'Workout plan not found.'], 404);
        }
        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        if ($status === 'completed' && $plan['workout_date'] > $today) {
            memberFitnessResponse([
                'success' => false,
                'message' => 'Future workouts cannot be marked complete.',
            ], 400);
        }
        $completedAt = $status === 'completed'
            ? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s')
            : null;
        $update = $conn->prepare(
            'UPDATE member_workout_plans p
             JOIN member_workout_weeks w ON w.id = p.week_id
             SET p.status = :status,
                 p.completed_at = CASE WHEN :completed_flag = 1 THEN :completed_at ELSE NULL END
             WHERE p.id = :plan_id AND w.member_id = :member_id
               AND p.is_rest_day = 0 AND w.week_start = :week_start'
        );
        $update->execute([
            ':status' => $status,
            ':completed_flag' => $status === 'completed' ? 1 : 0,
            ':completed_at' => $completedAt,
            ':plan_id' => $planId,
            ':member_id' => $memberId,
            ':week_start' => $currentWeekStart,
        ]);
        memberFitnessResponse(['success' => true, 'status' => $status]);
    }

    if ($action === 'save-workout') {
        $workoutDate = trim((string)($input['workout_date'] ?? ''));
        if (!memberFitnessDateIsValid($workoutDate)) {
            memberFitnessResponse(['success' => false, 'message' => 'Workout date must use YYYY-MM-DD format.'], 400);
        }
        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        if ($workoutDate > $today) {
            memberFitnessResponse(['success' => false, 'message' => 'Actual workout records cannot be dated in the future.'], 400);
        }
        $status = strtolower(trim((string)($input['status'] ?? '')));
        if (!in_array($status, ['completed', 'partial', 'skipped'], true)) {
            memberFitnessResponse(['success' => false, 'message' => 'Choose completed, partially completed, or skipped.'], 400);
        }
        $duration = memberFitnessPositiveInteger($input['duration_minutes'] ?? null, 'Workout duration', 1440, true);
        $notes = trim((string)($input['notes'] ?? ''));
        if (mb_strlen($notes) > 2000) {
            memberFitnessResponse(['success' => false, 'message' => 'Workout notes must be 2000 characters or fewer.'], 400);
        }
        $exercises = memberFitnessExercises($input['exercises'] ?? [], false);
        $planId = isset($input['planned_workout_id']) && $input['planned_workout_id'] !== ''
            ? memberFitnessPositiveInteger($input['planned_workout_id'], 'Workout plan ID', PHP_INT_MAX)
            : null;
        $sessionId = isset($input['session_id']) && $input['session_id'] !== ''
            ? memberFitnessPositiveInteger($input['session_id'], 'Workout record ID', PHP_INT_MAX)
            : null;
        $requestKey = trim((string)($input['request_key'] ?? ''));
        if ($sessionId === null && $planId === null
            && !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $requestKey)) {
            memberFitnessResponse(['success' => false, 'message' => 'A valid request key is required when logging a new workout. Please reopen the form and retry.'], 400);
        }

        $conn->beginTransaction();
        try {
            if ($planId !== null) {
                $planStmt = $conn->prepare(
                    'SELECT p.id, p.workout_date
                     FROM member_workout_plans p
                     JOIN member_workout_weeks w ON w.id = p.week_id
                     WHERE p.id = :plan_id AND w.member_id = :member_id AND p.is_rest_day = 0
                     LIMIT 1 FOR UPDATE'
                );
                $planStmt->execute([':plan_id' => $planId, ':member_id' => $memberId]);
                $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
                if (!$plan || $plan['workout_date'] !== $workoutDate) {
                    $conn->rollBack();
                    memberFitnessResponse(['success' => false, 'message' => 'Workout plan not found for this member and date.'], 404);
                }
            }

            foreach ($exercises as $exercise) {
                if ($exercise['planned_exercise_id'] !== null) {
                    $exerciseCheck = $conn->prepare(
                        'SELECT e.id FROM member_workout_plan_exercises e
                         JOIN member_workout_plans p ON p.id = e.plan_id
                         JOIN member_workout_weeks w ON w.id = p.week_id
                         WHERE e.id = :exercise_id AND w.member_id = :member_id
                           AND (:plan_id_null IS NULL OR p.id = :plan_id_match)
                         LIMIT 1'
                    );
                    $exerciseCheck->execute([
                        ':exercise_id' => $exercise['planned_exercise_id'],
                        ':member_id' => $memberId,
                        ':plan_id_null' => $planId,
                        ':plan_id_match' => $planId,
                    ]);
                    if (!$exerciseCheck->fetchColumn()) {
                        $conn->rollBack();
                        memberFitnessResponse(['success' => false, 'message' => 'Planned exercise not found for this member.'], 404);
                    }
                }
            }

            if ($sessionId !== null) {
                $sessionCheck = $conn->prepare(
                    'SELECT id FROM member_workout_sessions
                     WHERE id = :session_id AND member_id = :member_id
                     LIMIT 1 FOR UPDATE'
                );
                $sessionCheck->execute([':session_id' => $sessionId, ':member_id' => $memberId]);
                if (!$sessionCheck->fetchColumn()) {
                    $conn->rollBack();
                    memberFitnessResponse(['success' => false, 'message' => 'Workout record not found.'], 404);
                }
            } elseif ($planId !== null) {
                $sessionCheck = $conn->prepare(
                    'SELECT id FROM member_workout_sessions
                     WHERE planned_workout_id = :plan_id AND member_id = :member_id
                     LIMIT 1 FOR UPDATE'
                );
                $sessionCheck->execute([':plan_id' => $planId, ':member_id' => $memberId]);
                $existingSession = $sessionCheck->fetchColumn();
                if ($existingSession) {
                    $sessionId = (int)$existingSession;
                }
            } elseif ($requestKey !== '') {
                $sessionCheck = $conn->prepare(
                    'SELECT id FROM member_workout_sessions
                     WHERE member_id = :member_id AND request_key = :request_key
                     LIMIT 1 FOR UPDATE'
                );
                $sessionCheck->execute([
                    ':member_id' => $memberId,
                    ':request_key' => $requestKey,
                ]);
                $existingSession = $sessionCheck->fetchColumn();
                if ($existingSession) {
                    $sessionId = (int)$existingSession;
                }
            }

            if ($sessionId !== null) {
                $updateSession = $conn->prepare(
                    'UPDATE member_workout_sessions
                     SET planned_workout_id = :plan_id, request_key = COALESCE(request_key, :request_key),
                         workout_date = :workout_date,
                         status = :status, duration_minutes = :duration_minutes, notes = :notes
                     WHERE id = :id AND member_id = :member_id'
                );
                $updateSession->execute([
                    ':plan_id' => $planId,
                    ':request_key' => $planId === null ? $requestKey : null,
                    ':workout_date' => $workoutDate,
                    ':status' => $status,
                    ':duration_minutes' => $duration,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':id' => $sessionId,
                    ':member_id' => $memberId,
                ]);
            } else {
                $insertSession = $conn->prepare(
                    'INSERT INTO member_workout_sessions
                        (member_id, planned_workout_id, request_key, workout_date, status, duration_minutes, notes)
                     VALUES
                        (:member_id, :plan_id, :request_key, :workout_date, :status, :duration_minutes, :notes)
                     ON DUPLICATE KEY UPDATE
                        id = LAST_INSERT_ID(id),
                        workout_date = VALUES(workout_date),
                        status = VALUES(status),
                        duration_minutes = VALUES(duration_minutes),
                        notes = VALUES(notes)'
                );
                $insertSession->execute([
                    ':member_id' => $memberId,
                    ':plan_id' => $planId,
                    ':request_key' => $planId === null ? $requestKey : null,
                    ':workout_date' => $workoutDate,
                    ':status' => $status,
                    ':duration_minutes' => $duration,
                    ':notes' => $notes !== '' ? $notes : null,
                ]);
                $sessionId = (int)$conn->lastInsertId();
            }

            $deleteLogs = $conn->prepare('DELETE FROM member_workout_exercise_logs WHERE workout_session_id = :session_id');
            $deleteLogs->execute([':session_id' => $sessionId]);
            if ($exercises) {
                $insertLog = $conn->prepare(
                    'INSERT INTO member_workout_exercise_logs
                        (workout_session_id, planned_exercise_id, exercise_name, sets_completed,
                         reps_completed, actual_weight_kg, duration_minutes, is_completed, sort_order)
                     VALUES
                        (:session_id, :planned_exercise_id, :exercise_name, :sets_completed,
                         :reps_completed, :actual_weight_kg, :duration_minutes, :is_completed, :sort_order)'
                );
                foreach ($exercises as $exercise) {
                    $insertLog->execute([
                        ':session_id' => $sessionId,
                        ':planned_exercise_id' => $exercise['planned_exercise_id'],
                        ':exercise_name' => $exercise['exercise_name'],
                        ':sets_completed' => $exercise['sets_completed'],
                        ':reps_completed' => $exercise['reps_completed'],
                        ':actual_weight_kg' => $exercise['actual_weight_kg'],
                        ':duration_minutes' => $exercise['duration_minutes'],
                        ':is_completed' => $exercise['is_completed'],
                        ':sort_order' => $exercise['sort_order'],
                    ]);
                }
            }
            if ($planId !== null) {
                $updatePlan = $conn->prepare(
                    'UPDATE member_workout_plans p
                     JOIN member_workout_weeks w ON w.id = p.week_id
                     SET p.status = :status,
                         p.completed_at = CASE WHEN :completed_flag = 1 THEN NOW() ELSE NULL END
                     WHERE p.id = :id AND w.member_id = :member_id
                       AND w.week_start = :week_start'
                );
                $updatePlan->execute([
                    ':status' => $status,
                    ':completed_flag' => $status === 'completed' ? 1 : 0,
                    ':id' => $planId,
                    ':member_id' => $memberId,
                    ':week_start' => memberFitnessCurrentWeekStart(),
                ]);
            }
            $conn->commit();
            memberFitnessResponse(['success' => true, 'session_id' => $sessionId]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    memberFitnessResponse(['success' => false, 'message' => 'Unknown fitness action.'], 400);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $errorCode = $e instanceof PDOException ? (string)$e->getCode() : 'runtime_error';
    error_log('[member-fitness-api] outcome=failure error_code=' . $errorCode);
    memberFitnessResponse(['success' => false, 'message' => 'Unable to process your fitness data. Please try again later.'], 500);
}
