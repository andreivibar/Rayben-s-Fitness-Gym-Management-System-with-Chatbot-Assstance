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

if (defined('FEEDBACK_MANAGEMENT_TESTING') && FEEDBACK_MANAGEMENT_TESTING === true) {
    $conn = $GLOBALS['feedback_management_test_connection'];
    $adminUser = $GLOBALS['feedback_management_test_user'];
} else {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
    $adminUser = requireRole($conn, ['admin', 'administrator']);
}

function feedbackManagementResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (!in_array(strtolower(trim((string)($adminUser['role'] ?? ''))), ['admin', 'administrator'], true)) {
    feedbackManagementResponse(['success' => false, 'message' => 'Only administrators can manage feedback.'], 403);
}

function feedbackManagementDate($value, $field) {
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        feedbackManagementResponse(['success' => false, 'message' => $field . ' must be a valid YYYY-MM-DD date.'], 400);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
    if (!$date || $date->format('Y-m-d') !== $value) {
        feedbackManagementResponse(['success' => false, 'message' => $field . ' must be a valid YYYY-MM-DD date.'], 400);
    }
    return $value;
}

function feedbackManagementText($value, $field, $maximum) {
    if (!is_string($value)) return null;
    $value = trim($value);
    if ($value === '' || preg_match('//u', $value) !== 1) return null;
    preg_match_all('/./us', $value, $characters);
    if (count($characters[0]) > $maximum) {
        feedbackManagementResponse(['success' => false, 'message' => $field . ' must not exceed ' . $maximum . ' characters.'], 400);
    }
    return $value;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $rawInput = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (!is_array($input) || ($input['action'] ?? '') !== 'hide') {
            feedbackManagementResponse(['success' => false, 'message' => 'A valid moderation request is required.'], 400);
        }
        $feedbackId = filter_var($input['feedback_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = feedbackManagementText($input['moderation_reason'] ?? null, 'Moderation reason', 500);
        if (!$feedbackId || $reason === null) {
            feedbackManagementResponse(['success' => false, 'message' => 'A feedback record and moderation reason are required.'], 400);
        }

        $feedbackType = strtolower(trim((string)($input['feedback_type'] ?? 'trainer')));
        if ($feedbackType === 'gym_system') {
            $updateStmt = $conn->prepare(
                'UPDATE gym_system_feedback
                 SET is_hidden = 1, moderation_reason = :reason,
                     moderated_by_user_id = :admin_user_id, moderated_at = NOW()
                 WHERE id = :feedback_id AND is_hidden = 0'
            );
        } elseif (in_array($feedbackType, ['trainer', 'gym'], true)) {
            $updateStmt = $conn->prepare(
                'UPDATE member_feedback
                 SET is_hidden = 1, moderation_reason = :reason,
                     moderated_by_user_id = :admin_user_id, moderated_at = NOW()
                 WHERE id = :feedback_id AND is_hidden = 0'
            );
        } else {
            feedbackManagementResponse(['success' => false, 'message' => 'Feedback type is invalid.'], 400);
        }
        $updateStmt->execute([
            ':reason' => $reason,
            ':admin_user_id' => (int)$adminUser['id'],
            ':feedback_id' => (int)$feedbackId,
        ]);
        if ($updateStmt->rowCount() !== 1) {
            feedbackManagementResponse(['success' => false, 'message' => 'Feedback was not found or has already been moderated.'], 404);
        }
        feedbackManagementResponse(['success' => true, 'message' => 'Feedback comments were hidden. The original rating and record were preserved.']);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET, POST, OPTIONS');
        feedbackManagementResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $category = strtolower(trim((string)($_GET['category'] ?? 'all')));
    $rating = $_GET['rating'] ?? '';
    $identity = strtolower(trim((string)($_GET['identity'] ?? 'all')));
    $moderation = strtolower(trim((string)($_GET['moderation'] ?? 'all')));
    $trainerId = $_GET['trainer_id'] ?? '';
    if (!in_array($category, ['all', 'gym', 'trainer', 'gym_system'], true)
        || !in_array($identity, ['all', 'anonymous', 'identified'], true)
        || !in_array($moderation, ['all', 'visible', 'hidden'], true)) {
        feedbackManagementResponse(['success' => false, 'message' => 'One or more feedback filters are invalid.'], 400);
    }
    if ($rating !== '' && (!filter_var($rating, FILTER_VALIDATE_INT) || (int)$rating < 1 || (int)$rating > 5)) {
        feedbackManagementResponse(['success' => false, 'message' => 'Rating filter must be from 1 to 5.'], 400);
    }
    if ($trainerId !== '' && (!filter_var($trainerId, FILTER_VALIDATE_INT) || (int)$trainerId < 1)) {
        feedbackManagementResponse(['success' => false, 'message' => 'Trainer filter is invalid.'], 400);
    }
    $dateFrom = feedbackManagementDate($_GET['date_from'] ?? null, 'Start date');
    $dateTo = feedbackManagementDate($_GET['date_to'] ?? null, 'End date');
    if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
        feedbackManagementResponse(['success' => false, 'message' => 'Start date must not be after end date.'], 400);
    }

    if ($category === 'gym_system') {
        $feedbackSection = strtolower(trim((string)($_GET['feedback_section'] ?? 'all')));
        $search = $_GET['search'] ?? '';
        $sectionColumns = [
            'overall' => ['overall_rating', 'overall_comments', 'Overall gym experience'],
            'facilities' => ['facilities_rating', 'facilities_comments', 'Facilities and equipment'],
            'services' => ['services_rating', 'services_comments', 'Gym services'],
            'system' => ['system_rating', 'system_comments', 'System experience'],
        ];
        if ($feedbackSection !== 'all' && !isset($sectionColumns[$feedbackSection])) {
            feedbackManagementResponse(['success' => false, 'message' => 'Feedback category filter is invalid.'], 400);
        }
        if (!is_string($search)) {
            feedbackManagementResponse(['success' => false, 'message' => 'Search must be text.'], 400);
        }
        $search = trim($search);
        if (preg_match('//u', $search) !== 1) {
            feedbackManagementResponse(['success' => false, 'message' => 'Search contains invalid text.'], 400);
        }
        preg_match_all('/./us', $search, $searchCharacters);
        if (count($searchCharacters[0]) > 100) {
            feedbackManagementResponse(['success' => false, 'message' => 'Search must not exceed 100 characters.'], 400);
        }

        $where = [];
        $params = [];
        if ($moderation === 'hidden') $where[] = 'f.is_hidden = 1';
        if ($moderation === 'visible') $where[] = 'f.is_hidden = 0';
        if ($dateFrom !== null) {
            $where[] = 'f.feedback_date >= :filter_date_from';
            $params[':filter_date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $where[] = 'f.feedback_date <= :filter_date_to';
            $params[':filter_date_to'] = $dateTo;
        }
        if ($feedbackSection !== 'all') {
            [$ratingColumn, $commentsColumn] = $sectionColumns[$feedbackSection];
            $where[] = '(' . $ratingColumn . ' IS NOT NULL OR ' . $commentsColumn . ' IS NOT NULL)';
        }
        if ($rating !== '') {
            $ratingColumns = $feedbackSection === 'all'
                ? array_column($sectionColumns, 0)
                : [$sectionColumns[$feedbackSection][0]];
            $ratingChecks = [];
            foreach ($ratingColumns as $index => $column) {
                $parameter = ':filter_rating_' . $index;
                $ratingChecks[] = 'f.' . $column . ' = ' . $parameter;
                $params[$parameter] = (int)$rating;
            }
            $where[] = '(' . implode(' OR ', $ratingChecks) . ')';
        }
        if ($search !== '') {
            $where[] = '(f.overall_comments LIKE :search_overall
                OR f.facilities_comments LIKE :search_facilities
                OR f.services_comments LIKE :search_services
                OR f.system_comments LIKE :search_system
                OR EXISTS (
                    SELECT 1 FROM members search_member
                    WHERE search_member.id = f.member_id
                      AND search_member.full_name LIKE :search_member
                ))';
            foreach (['overall', 'facilities', 'services', 'system'] as $section) {
                $params[':search_' . $section] = '%' . $search . '%';
            }
            $params[':search_member'] = '%' . $search . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $perPage = filter_var($_GET['per_page'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $perPage === false) {
            feedbackManagementResponse(['success' => false, 'message' => 'Page must be positive and page size must be from 1 to 100.'], 400);
        }
        $offset = ($page - 1) * $perPage;

        $ratingColumns = $feedbackSection === 'all'
            ? array_column($sectionColumns, 0)
            : [$sectionColumns[$feedbackSection][0]];
        $ratingCountParts = array_map(static fn($column) => 'COUNT(f.' . $column . ')', $ratingColumns);
        $ratingSumParts = array_map(static fn($column) => 'COALESCE(SUM(f.' . $column . '), 0)', $ratingColumns);
        $ratingCountExpression = implode(' + ', $ratingCountParts);
        $ratingSumExpression = implode(' + ', $ratingSumParts);
        $distribution = [];
        for ($star = 1; $star <= 5; $star++) {
            $distributionParts = array_map(
                static fn($column) => 'SUM(CASE WHEN f.' . $column . ' = ' . $star . ' THEN 1 ELSE 0 END)',
                $ratingColumns
            );
            $distribution[] = '(' . implode(' + ', $distributionParts) . ') AS rating_' . $star;
        }

        $aggregateStmt = $conn->prepare(
            'SELECT COUNT(*) AS total,
                    (' . $ratingSumExpression . ') / NULLIF((' . $ratingCountExpression . '), 0) AS average_rating,
                    (' . $ratingCountExpression . ') AS rating_total,
                    ' . implode(', ', $distribution) . '
             FROM gym_system_feedback f' . $whereSql
        );
        $aggregateStmt->execute($params);
        $aggregateRow = $aggregateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $ratingDistribution = [];
        for ($star = 1; $star <= 5; $star++) {
            $ratingDistribution[$star] = (int)($aggregateRow['rating_' . $star] ?? 0);
        }
        $aggregate = [
            'total' => (int)($aggregateRow['total'] ?? 0),
            'rating_total' => (int)($aggregateRow['rating_total'] ?? 0),
            'average_rating' => $aggregateRow['average_rating'] === null ? null : round((float)$aggregateRow['average_rating'], 2),
            'distribution' => $ratingDistribution,
            'positive_total' => $ratingDistribution[4] + $ratingDistribution[5],
            'negative_total' => $ratingDistribution[1] + $ratingDistribution[2],
        ];

        $categorySummaries = [];
        foreach ($sectionColumns as $section => [$ratingColumn]) {
            if ($feedbackSection !== 'all' && $feedbackSection !== $section) {
                $categorySummaries[$section] = [
                    'total' => 0,
                    'average_rating' => null,
                    'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                ];
                continue;
            }
            $categoryDistribution = [];
            for ($star = 1; $star <= 5; $star++) {
                $categoryDistribution[] = 'SUM(CASE WHEN f.' . $ratingColumn . ' = ' . $star . ' THEN 1 ELSE 0 END) AS rating_' . $star;
            }
            $categoryStmt = $conn->prepare(
                'SELECT COUNT(f.' . $ratingColumn . ') AS total, AVG(f.' . $ratingColumn . ') AS average_rating,
                        ' . implode(', ', $categoryDistribution) . '
                 FROM gym_system_feedback f' . $whereSql
            );
            $categoryStmt->execute($params);
            $row = $categoryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $sectionDistribution = [];
            for ($star = 1; $star <= 5; $star++) {
                $sectionDistribution[$star] = (int)($row['rating_' . $star] ?? 0);
            }
            $categorySummaries[$section] = [
                'total' => (int)($row['total'] ?? 0),
                'average_rating' => $row['average_rating'] === null ? null : round((float)$row['average_rating'], 2),
                'distribution' => $sectionDistribution,
            ];
        }

        $countStmt = $conn->prepare(
            'SELECT COUNT(*) FROM gym_system_feedback f
             JOIN members m ON m.id = f.member_id' . $whereSql
        );
        $countStmt->execute($params);
        $totalRecords = (int)$countStmt->fetchColumn();

        $feedbackStmt = $conn->prepare(
            'SELECT f.id, \'gym_system\' AS feedback_type, f.member_id, m.full_name AS member_name,
                    f.feedback_date, f.submitted_at,
                    f.overall_rating, f.overall_comments, f.facilities_rating, f.facilities_comments,
                    f.services_rating, f.services_comments, f.system_rating, f.system_comments,
                    f.is_hidden, f.moderation_reason, f.moderated_by_user_id, f.moderated_at,
                    moderator.full_name AS moderated_by_name
             FROM gym_system_feedback f
             JOIN members m ON m.id = f.member_id
             LEFT JOIN users moderator ON moderator.id = f.moderated_by_user_id' . $whereSql . '
             ORDER BY f.feedback_date DESC, f.submitted_at DESC, f.id DESC
             LIMIT :offset, :per_page'
        );
        foreach ($params as $key => $value) $feedbackStmt->bindValue($key, $value);
        $feedbackStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $feedbackStmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);
        $feedbackStmt->execute();

        $trendStart = (new DateTimeImmutable('first day of this month', new DateTimeZone('Asia/Manila')))
            ->modify('-11 months')->format('Y-m-d');
        $trendWhere = $where;
        $trendParams = $params;
        $trendWhere[] = 'f.feedback_date >= :trend_start';
        $trendParams[':trend_start'] = $trendStart;
        $trendStmt = $conn->prepare(
            'SELECT DATE_FORMAT(f.feedback_date, \'%Y-%m\') AS month,
                    COUNT(*) AS submissions,
                    COUNT(f.overall_rating) AS overall_total,
                    AVG(f.overall_rating) AS overall_average,
                    COUNT(f.facilities_rating) AS facilities_total,
                    AVG(f.facilities_rating) AS facilities_average,
                    COUNT(f.services_rating) AS services_total,
                    AVG(f.services_rating) AS services_average,
                    COUNT(f.system_rating) AS system_total,
                    AVG(f.system_rating) AS system_average
             FROM gym_system_feedback f
             WHERE ' . implode(' AND ', $trendWhere) . '
             GROUP BY DATE_FORMAT(f.feedback_date, \'%Y-%m\')
             ORDER BY month ASC'
        );
        $trendStmt->execute($trendParams);

        feedbackManagementResponse([
            'success' => true,
            'aggregates_include_hidden' => true,
            'aggregates' => ['gym_system' => $aggregate],
            'category_summaries' => $categorySummaries,
            'trainer_ratings' => [],
            'monthly_trends' => $trendStmt->fetchAll(PDO::FETCH_ASSOC),
            'feedback' => $feedbackStmt->fetchAll(PDO::FETCH_ASSOC),
            'trainers' => [],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalRecords,
                'pages' => (int)ceil($totalRecords / $perPage),
            ],
        ]);
    }

    $search = $_GET['search'] ?? '';
    if (!is_string($search)) {
        feedbackManagementResponse(['success' => false, 'message' => 'Search must be text.'], 400);
    }
    $search = trim($search);
    if (preg_match('//u', $search) !== 1) {
        feedbackManagementResponse(['success' => false, 'message' => 'Search contains invalid text.'], 400);
    }
    preg_match_all('/./us', $search, $searchCharacters);
    if (count($searchCharacters[0]) > 100) {
        feedbackManagementResponse(['success' => false, 'message' => 'Search must not exceed 100 characters.'], 400);
    }

    $where = [];
    $params = [];
    if ($category !== 'all') {
        $where[] = 'f.feedback_category = :filter_category';
        $params[':filter_category'] = $category;
    }
    if ($rating !== '') {
        $where[] = 'f.rating = :filter_rating';
        $params[':filter_rating'] = (int)$rating;
    }
    if ($trainerId !== '') {
        $where[] = 'f.trainer_id = :filter_trainer_id';
        $params[':filter_trainer_id'] = (int)$trainerId;
    }
    if ($identity === 'anonymous') $where[] = 'f.is_anonymous = 1';
    if ($identity === 'identified') $where[] = 'f.is_anonymous = 0';
    if ($moderation === 'hidden') $where[] = 'f.is_hidden = 1';
    if ($moderation === 'visible') $where[] = 'f.is_hidden = 0';
    if ($dateFrom !== null) {
        $where[] = 'f.created_at >= :filter_date_from';
        $params[':filter_date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== null) {
        $where[] = 'f.created_at < :filter_date_to';
        $params[':filter_date_to'] = (new DateTimeImmutable($dateTo, new DateTimeZone('Asia/Manila')))
            ->modify('+1 day')->format('Y-m-d 00:00:00');
    }
    if ($search !== '') {
        $where[] = '(f.comments LIKE :search_comments
            OR f.suggestions LIKE :search_suggestions
            OR EXISTS (
                SELECT 1 FROM bookings search_booking
                WHERE search_booking.id = f.booking_id
                  AND search_booking.booking_code LIKE :search_booking_code
            )
            OR EXISTS (
                SELECT 1 FROM bookings search_booking_member
                JOIN members search_member ON search_member.id = search_booking_member.member_id
                WHERE search_booking_member.id = f.booking_id
                  AND search_member.full_name LIKE :search_member_name
            )
            OR EXISTS (
                SELECT 1 FROM trainers search_trainer
                JOIN users search_trainer_user ON search_trainer_user.id = search_trainer.user_id
                WHERE search_trainer.id = f.trainer_id
                  AND search_trainer_user.full_name LIKE :search_trainer_name
            ))';
        $params[':search_comments'] = '%' . $search . '%';
        $params[':search_suggestions'] = '%' . $search . '%';
        $params[':search_booking_code'] = '%' . $search . '%';
        $params[':search_member_name'] = '%' . $search . '%';
        $params[':search_trainer_name'] = '%' . $search . '%';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $perPage = filter_var($_GET['per_page'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($page === false || $perPage === false) {
        feedbackManagementResponse(['success' => false, 'message' => 'Page must be positive and page size must be from 1 to 100.'], 400);
    }
    $offset = ($page - 1) * $perPage;

    $aggregateStmt = $conn->prepare(
        'SELECT f.feedback_category, COUNT(*) AS total,
                AVG(f.rating) AS average_rating,
                SUM(CASE WHEN f.rating = 1 THEN 1 ELSE 0 END) AS rating_1,
                SUM(CASE WHEN f.rating = 2 THEN 1 ELSE 0 END) AS rating_2,
                SUM(CASE WHEN f.rating = 3 THEN 1 ELSE 0 END) AS rating_3,
                SUM(CASE WHEN f.rating = 4 THEN 1 ELSE 0 END) AS rating_4,
                SUM(CASE WHEN f.rating = 5 THEN 1 ELSE 0 END) AS rating_5,
                SUM(CASE WHEN f.rating IN (4, 5) THEN 1 ELSE 0 END) AS positive_total,
                SUM(CASE WHEN f.rating IN (1, 2) THEN 1 ELSE 0 END) AS negative_total
         FROM member_feedback f' . $whereSql . '
         GROUP BY f.feedback_category'
    );
    $aggregateStmt->execute($params);
    $aggregates = ['gym' => null, 'trainer' => null];
    foreach ($aggregateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $distribution = [];
        for ($star = 1; $star <= 5; $star++) $distribution[$star] = (int)$row['rating_' . $star];
        $aggregates[$row['feedback_category']] = [
            'total' => (int)$row['total'],
            'average_rating' => round((float)$row['average_rating'], 2),
            'distribution' => $distribution,
            'positive_total' => (int)$row['positive_total'],
            'negative_total' => (int)$row['negative_total'],
        ];
    }
    foreach ($aggregates as $key => $aggregate) {
        if ($aggregate === null) {
            $aggregates[$key] = [
                'total' => 0,
                'average_rating' => null,
                'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                'positive_total' => 0,
                'negative_total' => 0,
            ];
        }
    }

    $trainerStatsStmt = $conn->prepare(
        "SELECT t.id AS trainer_id, u.full_name AS trainer_name,
                COUNT(f.id) AS total, AVG(f.rating) AS average_rating,
                SUM(CASE WHEN f.rating = 1 THEN 1 ELSE 0 END) AS rating_1,
                SUM(CASE WHEN f.rating = 2 THEN 1 ELSE 0 END) AS rating_2,
                SUM(CASE WHEN f.rating = 3 THEN 1 ELSE 0 END) AS rating_3,
                SUM(CASE WHEN f.rating = 4 THEN 1 ELSE 0 END) AS rating_4,
                SUM(CASE WHEN f.rating = 5 THEN 1 ELSE 0 END) AS rating_5
         FROM trainers t
         JOIN users u ON u.id = t.user_id
         LEFT JOIN member_feedback f
           ON f.trainer_id = t.id AND f.feedback_category = 'trainer'" .
        ($where ? ' AND ' . implode(' AND ', array_map(
            static fn($condition) => preg_replace('/\bf\./', 'f.', $condition),
            $where
        )) : '') .
        " GROUP BY t.id, u.full_name
          ORDER BY CASE WHEN COUNT(f.id) = 0 THEN 1 ELSE 0 END ASC,
                   AVG(f.rating) DESC, total DESC, u.full_name ASC"
    );
    $trainerStatsStmt->execute($params);
    $trainerStats = [];
    foreach ($trainerStatsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $trainerStats[] = [
            'trainer_id' => (int)$row['trainer_id'],
            'trainer_name' => $row['trainer_name'],
            'total' => (int)$row['total'],
            'average_rating' => $row['average_rating'] === null ? null : round((float)$row['average_rating'], 2),
            'distribution' => [
                1 => (int)$row['rating_1'], 2 => (int)$row['rating_2'],
                3 => (int)$row['rating_3'], 4 => (int)$row['rating_4'],
                5 => (int)$row['rating_5'],
            ],
        ];
    }

    $trendStart = (new DateTimeImmutable('first day of this month', new DateTimeZone('Asia/Manila')))
        ->modify('-11 months')->format('Y-m-d 00:00:00');
    $trendWhere = $where;
    $trendWhere[] = 'f.created_at >= :trend_start';
    $trendParams = $params;
    $trendParams[':trend_start'] = $trendStart;
    $trendStmt = $conn->prepare(
        "SELECT DATE_FORMAT(f.created_at, '%Y-%m') AS month,
                f.feedback_category, COUNT(*) AS total, AVG(f.rating) AS average_rating
         FROM member_feedback f
         WHERE " . implode(' AND ', $trendWhere) . '
         GROUP BY DATE_FORMAT(f.created_at, \'%Y-%m\'), f.feedback_category
         ORDER BY month ASC'
    );
    $trendStmt->execute($trendParams);

    $countStmt = $conn->prepare(
        'SELECT COUNT(*) FROM member_feedback f
         LEFT JOIN attendance a ON a.id = f.attendance_id
         LEFT JOIN bookings b ON b.id = f.booking_id
         JOIN members m ON m.id = COALESCE(a.member_id, b.member_id)' . $whereSql
    );
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();

    $feedbackStmt = $conn->prepare(
        'SELECT f.id, f.attendance_id, f.booking_id, f.feedback_category, f.trainer_id,
                f.rating, f.comments, f.suggestions, f.is_anonymous, f.is_hidden,
                f.moderation_reason, f.moderated_by_user_id, f.moderated_at,
                f.created_at, COALESCE(a.attendance_date, b.booking_date) AS attendance_date,
                a.time_in, b.booking_code,
                m.id AS member_id, m.full_name AS member_name,
                u.full_name AS trainer_name,
                moderator.full_name AS moderated_by_name
         FROM member_feedback f
         LEFT JOIN attendance a ON a.id = f.attendance_id
         LEFT JOIN bookings b ON b.id = f.booking_id
         JOIN members m ON m.id = COALESCE(a.member_id, b.member_id)
         LEFT JOIN trainers t ON t.id = f.trainer_id
         LEFT JOIN users u ON u.id = t.user_id
         LEFT JOIN users moderator ON moderator.id = f.moderated_by_user_id' . $whereSql . '
         ORDER BY f.created_at DESC, f.id DESC
         LIMIT :offset, :per_page'
    );
    foreach ($params as $key => $value) $feedbackStmt->bindValue($key, $value);
    $feedbackStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $feedbackStmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);
    $feedbackStmt->execute();

    $trainersStmt = $conn->query(
        'SELECT t.id AS trainer_id, u.full_name AS trainer_name
         FROM trainers t JOIN users u ON u.id = t.user_id
         ORDER BY u.full_name ASC, t.id ASC'
    );

    feedbackManagementResponse([
        'success' => true,
        'aggregates_include_hidden' => true,
        'aggregates' => $aggregates,
        'trainer_ratings' => $trainerStats,
        'monthly_trends' => $trendStmt->fetchAll(PDO::FETCH_ASSOC),
        'feedback' => $feedbackStmt->fetchAll(PDO::FETCH_ASSOC),
        'trainers' => $trainersStmt->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalRecords,
            'pages' => (int)ceil($totalRecords / $perPage),
        ],
    ]);
} catch (PDOException $e) {
    error_log('Feedback management database operation failed: ' . $e->getMessage());
    feedbackManagementResponse(['success' => false, 'message' => 'Unable to load feedback management right now. Please try again.'], 500);
}

?>
