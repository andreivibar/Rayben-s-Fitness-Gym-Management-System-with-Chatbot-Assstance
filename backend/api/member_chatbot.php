<?php

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/member_chatbot.php';

function memberChatbotRespond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function memberChatbotInput(): array
{
    $raw = $GLOBALS['__php_input_cache'] ?? file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        memberChatbotRespond(['success' => false, 'message' => 'The request body must be valid JSON.'], 400);
    }
    return $data;
}

function memberChatbotFindConversation(PDO $conn, int $memberId, int $conversationId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, title, created_at, updated_at
         FROM member_chat_conversations
         WHERE id = :conversation_id AND member_id = :member_id
         LIMIT 1'
    );
    $stmt->execute([
        ':conversation_id' => $conversationId,
        ':member_id' => $memberId,
    ]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    return $conversation ?: null;
}

function memberChatbotUpdateTitleFromMessage(PDO $conn, int $conversationId, string $message): void
{
    $stmt = $conn->prepare(
        "SELECT title FROM member_chat_conversations
         WHERE id = :conversation_id
         LIMIT 1"
    );
    $stmt->execute([':conversation_id' => $conversationId]);
    if ($stmt->fetchColumn() !== 'New conversation') {
        return;
    }

    $title = preg_replace('/\s+/u', ' ', trim($message));
    if (preg_match('/^.{1,80}/us', (string)$title, $matches)) {
        $title = trim($matches[0]);
    }
    if ($title === '') {
        return;
    }

    $update = $conn->prepare(
        'UPDATE member_chat_conversations
         SET title = :title, updated_at = CURRENT_TIMESTAMP
         WHERE id = :conversation_id'
    );
    $update->execute([
        ':title' => $title,
        ':conversation_id' => $conversationId,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

$currentUser = requireRole($conn, ['member']);
$memberStmt = $conn->prepare(
    "SELECT id, membership_type, status, account_status
     FROM members
     WHERE user_id = :user_id
     LIMIT 1"
);
$memberStmt->execute([':user_id' => (int)$currentUser['id']]);
$member = $memberStmt->fetch(PDO::FETCH_ASSOC);

if (
    !$member
    || strtolower(trim((string)$member['account_status'])) !== 'active'
    || strtolower(trim((string)$member['status'])) !== 'active'
    || strtolower(trim((string)$member['membership_type'])) !== 'monthly'
    || !memberChatHasActiveMonthlySubscription($conn, (int)$member['id'])
) {
    memberChatbotRespond([
        'success' => false,
        'code' => 'monthly_subscription_required',
        'message' => 'The gym assistant is available to members with an active, verified Monthly Subscription.',
    ], 403);
}

$memberId = (int)$member['id'];
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST' ? memberChatbotInput() : [];
$action = (string)($input['action'] ?? $_GET['action'] ?? '');

try {
    if ($method === 'GET' && $action === 'conversations') {
        $stmt = $conn->prepare(
            'SELECT id, title, created_at, updated_at
             FROM member_chat_conversations
             WHERE member_id = :member_id
             ORDER BY updated_at DESC, id DESC
             LIMIT 100'
        );
        $stmt->execute([':member_id' => $memberId]);
        memberChatbotRespond(['success' => true, 'conversations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'GET' && $action === 'messages') {
        $conversationId = filter_var($_GET['conversation_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$conversationId || !memberChatbotFindConversation($conn, $memberId, (int)$conversationId)) {
            memberChatbotRespond(['success' => false, 'message' => 'Conversation not found.'], 404);
        }

        $stmt = $conn->prepare(
            'SELECT id, role, content, created_at
             FROM member_chat_messages
             WHERE conversation_id = :conversation_id
             ORDER BY id ASC'
        );
        $stmt->execute([':conversation_id' => (int)$conversationId]);
        memberChatbotRespond(['success' => true, 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'POST' && $action === 'create') {
        $countStmt = $conn->prepare(
            'SELECT COUNT(*) FROM member_chat_conversations WHERE member_id = :member_id'
        );
        $countStmt->execute([':member_id' => $memberId]);
        if ((int)$countStmt->fetchColumn() >= 100) {
            memberChatbotRespond(['success' => false, 'message' => 'Delete an older conversation before creating another.'], 409);
        }

        $stmt = $conn->prepare(
            "INSERT INTO member_chat_conversations (member_id, title)
             VALUES (:member_id, 'New conversation')"
        );
        $stmt->execute([':member_id' => $memberId]);
        $conversation = memberChatbotFindConversation($conn, $memberId, (int)$conn->lastInsertId());
        memberChatbotRespond(['success' => true, 'conversation' => $conversation], 201);
    }

    if ($method === 'POST' && $action === 'rename') {
        $conversationId = filter_var($input['conversation_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim((string)($input['title'] ?? ''));
        if (!$conversationId || $title === '' || mb_strlen($title, 'UTF-8') > 120) {
            memberChatbotRespond(['success' => false, 'message' => 'Enter a conversation title of 1 to 120 characters.'], 400);
        }
        if (!memberChatbotFindConversation($conn, $memberId, (int)$conversationId)) {
            memberChatbotRespond(['success' => false, 'message' => 'Conversation not found.'], 404);
        }

        $stmt = $conn->prepare(
            'UPDATE member_chat_conversations
             SET title = :title, updated_at = CURRENT_TIMESTAMP
             WHERE id = :conversation_id AND member_id = :member_id'
        );
        $stmt->execute([
            ':title' => $title,
            ':conversation_id' => (int)$conversationId,
            ':member_id' => $memberId,
        ]);
        $conversation = memberChatbotFindConversation($conn, $memberId, (int)$conversationId);
        memberChatbotRespond(['success' => true, 'conversation' => $conversation]);
    }

    if ($method === 'POST' && $action === 'send') {
        $conversationId = filter_var($input['conversation_id'] ?? null, FILTER_VALIDATE_INT);
        $message = trim((string)($input['message'] ?? ''));
        if (!$conversationId || $message === '' || mb_strlen($message, 'UTF-8') > 4000) {
            memberChatbotRespond(['success' => false, 'message' => 'Enter a message of 1 to 4,000 characters.'], 400);
        }

        if (trim((string)getenv('GEMINI_API_KEY')) === '') {
            memberChatbotRespond(['success' => false, 'code' => 'chatbot_not_configured', 'message' => 'The gym assistant is not configured yet.'], 503);
        }

        $conn->beginTransaction();
        $lockMember = $conn->prepare(
            'SELECT id FROM members WHERE id = :member_id AND user_id = :user_id FOR UPDATE'
        );
        $lockMember->execute([
            ':member_id' => $memberId,
            ':user_id' => (int)$currentUser['id'],
        ]);
        if (!$lockMember->fetchColumn() || !memberChatHasActiveMonthlySubscription($conn, $memberId)) {
            $conn->rollBack();
            memberChatbotRespond([
                'success' => false,
                'code' => 'monthly_subscription_required',
                'message' => 'The gym assistant is available to members with an active, verified Monthly Subscription.',
            ], 403);
        }

        if (!memberChatbotFindConversation($conn, $memberId, (int)$conversationId)) {
            $conn->rollBack();
            memberChatbotRespond(['success' => false, 'message' => 'Conversation not found.'], 404);
        }

        $rateStmt = $conn->prepare(
            "SELECT COUNT(*)
             FROM member_chat_messages msg
             JOIN member_chat_conversations conv ON conv.id = msg.conversation_id
             WHERE conv.member_id = :member_id
               AND msg.role = 'user'
               AND msg.created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        $rateStmt->execute([':member_id' => $memberId]);
        if ((int)$rateStmt->fetchColumn() >= 6) {
            $conn->rollBack();
            memberChatbotRespond(['success' => false, 'message' => 'You are sending messages too quickly. Please wait a minute and try again.'], 429);
        }

        $insertUserMessage = $conn->prepare(
            "INSERT INTO member_chat_messages (conversation_id, role, content)
             VALUES (:conversation_id, 'user', :content)"
        );
        $insertUserMessage->execute([
            ':conversation_id' => (int)$conversationId,
            ':content' => $message,
        ]);
        $userMessageId = (int)$conn->lastInsertId();
        memberChatbotUpdateTitleFromMessage($conn, (int)$conversationId, $message);
        $conn->commit();

        $historyStmt = $conn->prepare(
            'SELECT role, content
             FROM (
                 SELECT id, role, content
                 FROM member_chat_messages
                 WHERE conversation_id = :conversation_id
                 ORDER BY id DESC
                 LIMIT 12
             ) recent
             ORDER BY id ASC'
        );
        $historyStmt->execute([':conversation_id' => (int)$conversationId]);
        $reply = memberChatRequestGemini(
            $historyStmt->fetchAll(PDO::FETCH_ASSOC),
            memberChatGymKnowledge($conn)
        );

        $insertAssistantMessage = $conn->prepare(
            "INSERT INTO member_chat_messages (conversation_id, role, content)
             VALUES (:conversation_id, 'assistant', :content)"
        );
        $insertAssistantMessage->execute([
            ':conversation_id' => (int)$conversationId,
            ':content' => $reply,
        ]);
        $assistantMessageId = (int)$conn->lastInsertId();
        $updateConversation = $conn->prepare(
            'UPDATE member_chat_conversations
             SET updated_at = CURRENT_TIMESTAMP
             WHERE id = :conversation_id AND member_id = :member_id'
        );
        $updateConversation->execute([
            ':conversation_id' => (int)$conversationId,
            ':member_id' => $memberId,
        ]);

        memberChatbotRespond([
            'success' => true,
            'messages' => [
                ['id' => $userMessageId, 'role' => 'user', 'content' => $message],
                ['id' => $assistantMessageId, 'role' => 'assistant', 'content' => $reply],
            ],
        ]);
    }

    if ($method === 'DELETE' && $action === 'delete') {
        $conversationId = filter_var($_GET['conversation_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$conversationId) {
            memberChatbotRespond(['success' => false, 'message' => 'A valid conversation ID is required.'], 400);
        }
        $stmt = $conn->prepare(
            'DELETE FROM member_chat_conversations
             WHERE id = :conversation_id AND member_id = :member_id'
        );
        $stmt->execute([
            ':conversation_id' => (int)$conversationId,
            ':member_id' => $memberId,
        ]);
        if ($stmt->rowCount() !== 1) {
            memberChatbotRespond(['success' => false, 'message' => 'Conversation not found.'], 404);
        }
        memberChatbotRespond(['success' => true]);
    }

    memberChatbotRespond(['success' => false, 'message' => 'Unsupported chatbot action.'], 400);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $status = $exception->getCode() >= 400 && $exception->getCode() <= 599
        ? (int)$exception->getCode()
        : 500;
    if ($status === 500) {
        error_log('Member chatbot request failed: ' . $exception->getMessage());
        $message = 'The gym assistant could not complete the request. Please try again later.';
    } else {
        $message = $exception->getMessage();
    }
    memberChatbotRespond(['success' => false, 'message' => $message], $status);
}
