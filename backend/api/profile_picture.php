<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/admin_profile_service.php';

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function profilePictureTarget($conn, $currentUser, $entity, $targetId) {
    $role = strtolower(trim((string)($currentUser['role'] ?? '')));
    $entity = strtolower(trim((string)$entity));
    $targetId = (int)$targetId;

    if ($entity === 'admin') {
        if (!in_array($role, ['admin', 'administrator'], true)) {
            sendResponse(['success' => false, 'message' => 'You are not allowed to manage this profile picture.'], 403);
        }
        try {
            return ['admin', getAdminProfilePictureTarget($conn, $currentUser)];
        } catch (OutOfBoundsException $error) {
            sendResponse(['success' => false, 'message' => 'Admin profile not found.'], 404);
        }
    }

    if (!in_array($entity, ['member', 'trainer'], true)) {
        sendResponse(['success' => false, 'message' => 'Invalid profile type.'], 400);
    }

    if ($role === 'admin') {
        if ($targetId <= 0) {
            sendResponse(['success' => false, 'message' => 'A valid profile target is required.'], 400);
        }
        if ($entity === 'member') {
            $stmt = $conn->prepare('SELECT id, member_code, profile_picture FROM members WHERE id = :id LIMIT 1');
        } else {
            $stmt = $conn->prepare('SELECT id, trainer_code, profile_picture FROM trainers WHERE id = :id LIMIT 1');
        }
        $stmt->execute([':id' => $targetId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) sendResponse(['success' => false, 'message' => 'Profile not found.'], 404);
        return [$entity, $target];
    }

    if ($entity === 'member' && $role === 'member') {
        $stmt = $conn->prepare('SELECT id, member_code, profile_picture FROM members WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $currentUser['id']]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) sendResponse(['success' => false, 'message' => 'Member profile not found.'], 404);
        return [$entity, $target];
    }

    if ($entity === 'trainer' && $role === 'trainer') {
        $stmt = $conn->prepare('SELECT id, trainer_code, profile_picture FROM trainers WHERE user_id = :user_id LIMIT 1');
        $stmt->execute([':user_id' => $currentUser['id']]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) sendResponse(['success' => false, 'message' => 'Trainer profile not found.'], 404);
        return [$entity, $target];
    }

    sendResponse(['success' => false, 'message' => 'You are not allowed to manage this profile picture.'], 403);
}

function profilePicturePath($entity, $code, $extension) {
    $directories = ['member' => 'members', 'trainer' => 'trainers', 'admin' => 'admins'];
    if (!isset($directories[$entity])) {
        throw new InvalidArgumentException('Invalid profile type.');
    }
    return 'uploads/' . $directories[$entity] . '/' . $code . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
}

function removeStoredPicture($relativePath) {
    if (!$relativePath) return;
    $root = realpath(__DIR__ . '/../uploads');
    $absolutePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));
    if ($root && $absolutePath && strpos($absolutePath, $root . DIRECTORY_SEPARATOR) === 0 && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        sendResponse(['success' => true], 204);
    }

    if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) {
        sendResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $currentUser = requireAuth($conn);
    $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST ?? []) : ($_GET ?? []);
    $entity = $input['entity'] ?? '';
    $targetId = $input['id'] ?? 0;
    [$entity, $target] = profilePictureTarget($conn, $currentUser, $entity, $targetId);
    $action = strtolower(trim((string)($input['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'DELETE' ? 'remove' : 'upload'))));

    if ($action === 'remove') {
        $table = ['member' => 'members', 'trainer' => 'trainers', 'admin' => 'users'][$entity];
        $stmt = $conn->prepare("UPDATE {$table} SET profile_picture = NULL WHERE id = :id");
        $stmt->execute([':id' => $target['id']]);
        removeStoredPicture($target['profile_picture']);
        sendResponse(['success' => true, 'message' => 'Profile picture removed successfully.', 'profile_picture' => null]);
    }

    if ($action !== 'upload') {
        sendResponse(['success' => false, 'message' => 'Unsupported profile picture action.'], 400);
    }

    if (empty($_FILES['profile_picture']) || !is_array($_FILES['profile_picture'])) {
        $postLimit = trim((string)ini_get('post_max_size'));
        $requestSize = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $limitBytes = 0;
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/i', $postLimit, $matches)) {
            $limitBytes = (int)((float)$matches[1] * ([
                '' => 1,
                'K' => 1024,
                'M' => 1024 * 1024,
                'G' => 1024 * 1024 * 1024,
            ][strtoupper($matches[2])]));
        }
        if ($requestSize > 0 && $limitBytes > 0 && $requestSize > $limitBytes) {
            sendResponse(['success' => false, 'message' => 'The upload exceeds the server request-size limit. Choose a smaller image and try again.'], 413);
        }
        sendResponse(['success' => false, 'message' => 'Please select an image file and try again.'], 400);
    }

    $file = $_FILES['profile_picture'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'The image exceeds the server upload-size limit. Choose a smaller image.',
            UPLOAD_ERR_FORM_SIZE => 'The image exceeds the permitted upload size.',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please select an image file and try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image. Check upload storage permissions.',
            UPLOAD_ERR_EXTENSION => 'A server extension prevented the image upload.',
        ];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        sendResponse([
            'success' => false,
            'message' => $uploadErrors[$uploadError] ?? 'The image could not be uploaded. Please try again.',
        ], $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE ? 413 : 400);
    }

    $maxSize = 5 * 1024 * 1024;
    $temporaryPath = (string)($file['tmp_name'] ?? '');
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxSize) {
        sendResponse(['success' => false, 'message' => 'Image file must be larger than 0 bytes and 5 MB or smaller.'], 400);
    }
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        sendResponse(['success' => false, 'message' => 'The uploaded image could not be read by the server. Please try again.'], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($temporaryPath);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime]) || @getimagesize($temporaryPath) === false) {
        sendResponse(['success' => false, 'message' => 'Invalid image file. Use JPG, PNG, or WEBP.'], 400);
    }

    $directory = __DIR__ . '/../uploads/' . ['member' => 'members', 'trainer' => 'trainers', 'admin' => 'admins'][$entity];
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        sendResponse(['success' => false, 'message' => 'Image storage is unavailable.'], 500);
    }
    if (!is_writable($directory)) {
        sendResponse(['success' => false, 'message' => 'Image storage is not writable. Check the upload directory permissions.'], 500);
    }

    if ($entity === 'member') {
        $code = $target['member_code'] ?? '';
    } elseif ($entity === 'trainer') {
        $code = $target['trainer_code'] ?? '';
    } else {
        $code = $target['admin_code'] ?? '';
    }
    if ($code === '') {
        sendResponse(['success' => false, 'message' => 'Unable to identify the profile image owner.'], 500);
    }
    $relativePath = profilePicturePath($entity, $code, $allowed[$mime]);
    $absolutePath = __DIR__ . '/../' . $relativePath;
    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
        sendResponse(['success' => false, 'message' => 'The server could not save the image. Check upload directory permissions and try again.'], 500);
    }

    $table = ['member' => 'members', 'trainer' => 'trainers', 'admin' => 'users'][$entity];
    try {
        $stmt = $conn->prepare("UPDATE {$table} SET profile_picture = :profile_picture WHERE id = :id");
        $stmt->execute([':profile_picture' => $relativePath, ':id' => $target['id']]);
    } catch (PDOException $e) {
        @unlink($absolutePath);
        sendResponse(['success' => false, 'message' => 'Database update failed.'], 500);
    }

    removeStoredPicture($target['profile_picture']);
    sendResponse(['success' => true, 'message' => 'Profile picture updated successfully.', 'profile_picture' => $relativePath]);
} catch (PDOException $e) {
    sendResponse(['success' => false, 'message' => 'Profile picture operation failed.'], 500);
} catch (Throwable $e) {
    sendResponse(['success' => false, 'message' => 'Invalid image file.'], 400);
}
