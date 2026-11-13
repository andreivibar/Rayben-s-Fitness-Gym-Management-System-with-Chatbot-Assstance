<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/membership_pricing.php';

function gcashSettingsResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $requestUser = getAuthUser($conn);
        $stmt = $conn->query('SELECT gcash_account_name, gcash_account_number, updated_by, updated_at FROM gym_payment_settings WHERE id = 1');
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'gcash_account_name' => null,
            'gcash_account_number' => null,
            'updated_by' => null,
            'updated_at' => null,
        ];
        if ($requestUser && strtolower(trim((string)($requestUser['role'] ?? ''))) === 'admin') {
            $settings['updated_by_admin_name'] = null;
            if (!empty($settings['updated_by'])) {
                $updaterStmt = $conn->prepare(
                    "SELECT full_name, first_name, middle_name, last_name
                     FROM users
                     WHERE id = :id AND LOWER(TRIM(role)) = 'admin'
                     LIMIT 1"
                );
                $updaterStmt->execute([':id' => (int)$settings['updated_by']]);
                $updater = $updaterStmt->fetch(PDO::FETCH_ASSOC);
                if ($updater) {
                    $name = trim((string)($updater['full_name'] ?? ''));
                    if ($name === '') {
                        $name = implode(' ', array_filter([
                            trim((string)($updater['first_name'] ?? '')),
                            trim((string)($updater['middle_name'] ?? '')),
                            trim((string)($updater['last_name'] ?? '')),
                        ]));
                    }
                    $settings['updated_by_admin_name'] = $name !== '' ? $name : null;
                }
            }
        }
        unset($settings['updated_by']);
        $planStmt = $conn->query(
            'SELECT id, plan_code, plan_name, duration_months, regular_price, student_price, senior_price, pwd_price
             FROM membership_plans WHERE is_active = 1 ORDER BY duration_months'
        );
        gcashSettingsResponse([
            'success' => true,
            'settings' => $settings,
            'monthly_rates' => [
                'regular' => monthlyMembershipPrice('regular'),
                'student' => monthlyMembershipPrice('student'),
                'senior' => monthlyMembershipPrice('senior'),
                'pwd' => monthlyMembershipPrice('pwd'),
            ],
            'daily_pass_price' => GYM_DAILY_PASS_PRICE,
            'membership_plans' => $planStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        gcashSettingsResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $currentUser = requireRole($conn, ['admin']);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        gcashSettingsResponse(['success' => false, 'message' => 'Invalid request payload.'], 400);
    }

    $accountName = trim((string)($input['gcash_account_name'] ?? ''));
    $accountNumber = preg_replace('/[\s-]+/', '', (string)($input['gcash_account_number'] ?? ''));
    if ($accountName === '' || mb_strlen($accountName) > 120) {
        gcashSettingsResponse(['success' => false, 'message' => 'Enter a GCash account name up to 120 characters.'], 400);
    }
    if (!preg_match('/^(09\d{9}|639\d{9})$/', $accountNumber)) {
        gcashSettingsResponse(['success' => false, 'message' => 'Enter a valid GCash mobile number (09xxxxxxxxx or 639xxxxxxxxx).'], 400);
    }

    $stmt = $conn->prepare(
        'UPDATE gym_payment_settings
         SET gcash_account_name = :account_name, gcash_account_number = :account_number, updated_by = :updated_by
         WHERE id = 1'
    );
    $stmt->execute([
        ':account_name' => $accountName,
        ':account_number' => $accountNumber,
        ':updated_by' => (int)$currentUser['id'],
    ]);
    $updaterName = trim((string)($currentUser['full_name'] ?? ''));
    if ($updaterName === '') {
        $updaterName = implode(' ', array_filter([
            trim((string)($currentUser['first_name'] ?? '')),
            trim((string)($currentUser['middle_name'] ?? '')),
            trim((string)($currentUser['last_name'] ?? '')),
        ]));
    }

    gcashSettingsResponse([
        'success' => true,
        'message' => 'GCash payment details updated.',
        'settings' => [
            'gcash_account_name' => $accountName,
            'gcash_account_number' => $accountNumber,
            'updated_by_admin_name' => $updaterName !== '' ? $updaterName : null,
        ],
    ]);
} catch (Throwable $e) {
    error_log('GCash settings operation failed: ' . $e->getMessage());
    gcashSettingsResponse(['success' => false, 'message' => 'Unable to update GCash payment details.'], 500);
}
