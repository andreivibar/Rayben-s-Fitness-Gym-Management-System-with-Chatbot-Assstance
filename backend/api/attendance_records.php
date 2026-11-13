<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token, X-User-Email');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    $currentUser = requireAuth($conn);
    $currentUserRole = strtolower(trim((string)($currentUser['role'] ?? '')));
    // Accept GET parameters for filtering and pagination
    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    $attendance_date = isset($_GET['attendance_date']) ? trim((string)$_GET['attendance_date']) : '';
    $status = isset($_GET['status']) ? trim((string)$_GET['status']) : 'all';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : 25;

    $offset = ($page - 1) * $per_page;

    // Build WHERE clauses
    $where = [];
    $params = [];

    // RBAC: members and trainers can only see their OWN attendance; admins see all
    if ($currentUserRole === 'member') {
        $mStmt = $conn->prepare('SELECT id FROM members WHERE user_id = :user_id LIMIT 1');
        $mStmt->execute([':user_id' => (int)$currentUser['id']]);
        $memberRow = $mStmt->fetch(PDO::FETCH_ASSOC);
        if ($memberRow) {
            $where[] = 'a.member_id = :self_member_id';
            $params[':self_member_id'] = intval($memberRow['id']);
        } else {
            sendResponse(['success' => false, 'message' => 'Member profile not found.'], 404);
        }
    } elseif ($currentUserRole === 'trainer') {
        sendResponse([
            'success' => false,
            'message' => 'Forbidden. Trainers cannot view attendance records.'
        ], 403);
    }

    if ($search !== '') {
        $where[] = '(m.full_name LIKE :search OR m.first_name LIKE :search OR m.middle_name LIKE :search OR m.last_name LIKE :search OR m.member_code LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($attendance_date !== '') {
        $where[] = 'a.attendance_date = :attendance_date';
        $params[':attendance_date'] = $attendance_date;
        $summaryDate = $attendance_date;
    } else {
        $summaryDate = date('Y-m-d');
    }

    if ($status === 'checked_in') {
        $where[] = 'a.time_in IS NOT NULL AND a.time_out IS NULL';
    } elseif ($status === 'completed') {
        $where[] = 'a.time_in IS NOT NULL AND a.time_out IS NOT NULL';
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

    // Count total
    $countSql = "SELECT COUNT(*) as cnt FROM attendance a JOIN members m ON a.member_id = m.id {$whereSql}";
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $total = intval($countStmt->fetchColumn());

    // Main query
    $sql = "SELECT a.id, a.member_id, m.member_code, m.first_name, m.middle_name, m.last_name, m.full_name, a.attendance_date, a.time_in, a.time_out, a.checkout_type, a.created_at
            FROM attendance a
            JOIN members m ON a.member_id = m.id
            {$whereSql}
            ORDER BY a.attendance_date DESC, a.time_in DESC
            LIMIT :offset, :per_page";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary for the summary cards (for the selected date or today) - respect filters
    $summaryParams = [];
    $summaryParams[':summaryDate'] = $summaryDate;
    $summaryWhere = ['a.attendance_date = :summaryDate'];
    foreach ($where as $w) {
        // Avoid duplicating attendance_date if already present in where
        if (strpos($w, 'attendance_date') === false) {
            $summaryWhere[] = $w;
        }
    }
    // Only copy params that are actually referenced in summaryWhereSql
    foreach ($params as $pk => $pv) {
        $placeholderKey = trim($pk, ':');
        $found = false;
        foreach ($summaryWhere as $sw) {
            if (strpos($sw, ':' . $placeholderKey) !== false) {
                $found = true;
                break;
            }
        }
        if ($found) {
            $summaryParams[$pk] = $pv;
        }
    }
    $summaryWhereSql = 'WHERE ' . implode(' AND ', $summaryWhere);

    $summarySql = "SELECT 
        COUNT(*) as total_today,
        SUM(CASE WHEN a.time_in IS NOT NULL AND a.time_out IS NULL THEN 1 ELSE 0 END) as checked_in,
        SUM(CASE WHEN a.time_in IS NOT NULL AND a.time_out IS NOT NULL THEN 1 ELSE 0 END) as completed
        FROM attendance a
        LEFT JOIN members m ON a.member_id = m.id
        {$summaryWhereSql}";

    $summaryStmt = $conn->prepare($summarySql);
    foreach ($summaryParams as $k => $v) $summaryStmt->bindValue($k, $v);
    $summaryStmt->execute();
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    sendResponse([
        'success' => true,
        'records' => $records,
        'meta' => [
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $per_page > 0 ? ceil($total / $per_page) : 0
        ],
        'summary' => [
            'total_today' => intval($summary['total_today'] ?? 0),
            'checked_in' => intval($summary['checked_in'] ?? 0),
            'completed' => intval($summary['completed'] ?? 0),
            'date' => $summaryDate
        ]
    ]);

} catch (PDOException $e) {
    sendResponse(['success' => false, 'message' => 'DEBUG SERVER ERROR: ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
}

?>
