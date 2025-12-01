<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    respondJson(['success' => false, 'message' => 'Authentication required.'], 401);
}

require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/RealtimeNotifier.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? 'student';
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        if (isset($_GET['thread_id'])) {
            $threadId = (int) $_GET['thread_id'];
            $messages = listMessages($db, $threadId, $userId);
            respondJson(['success' => true, 'data' => $messages]);
        }

        $threads = listThreads($db, $userId);
        respondJson(['success' => true, 'data' => $threads]);
    }

    if ($method === 'POST') {
        $payload = parseJsonBody();
        $action = $payload['action'] ?? 'send';

        if ($action !== 'send') {
            respondJson(['success' => false, 'message' => 'Unsupported action.'], 400);
        }

        $messageText = trim((string) ($payload['message'] ?? ''));
        if ($messageText === '') {
            respondJson(['success' => false, 'message' => 'Message body cannot be empty.'], 422);
        }

        $threadId = isset($payload['thread_id']) ? (int) $payload['thread_id'] : 0;
        $recipientId = isset($payload['recipient_id']) ? (int) $payload['recipient_id'] : 0;

        if ($threadId <= 0 && $recipientId <= 0) {
            respondJson(['success' => false, 'message' => 'Select a recipient to start a conversation.'], 422);
        }

        $message = sendMessage($db, $userId, $userRole, $threadId, $recipientId, $messageText);
        respondJson(['success' => true, 'data' => $message], 201);
    }

    respondJson(['success' => false, 'message' => 'Method not allowed.'], 405);
} catch (Throwable $exception) {
    respondJson(['success' => false, 'message' => 'Messaging service unavailable.', 'detail' => $exception->getMessage()], 500);
}

function respondJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function parseJsonBody(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [];
    }

    return $decoded;
}

function listThreads(PDO $db, int $userId): array
{
    $sql = 'SELECT mt.id,
                   mt.subject,
                   mt.last_message_at,
                   mt.last_message_preview,
                   tp_self.last_read_at AS self_last_read_at,
                   CASE
                       WHEN mt.last_message_at IS NULL THEN 0
                       WHEN mt.last_message_at > COALESCE(tp_self.last_read_at, "1970-01-01") THEN 1
                       ELSE 0
                   END AS has_unread,
                   (
                       SELECT COUNT(*)
                       FROM messages m
                       WHERE m.thread_id = mt.id
                         AND m.created_at > COALESCE(tp_self.last_read_at, "1970-01-01")
                   ) AS unread_count,
                   GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ", ") AS participants
            FROM message_threads mt
            INNER JOIN thread_participants tp_self ON tp_self.thread_id = mt.id AND tp_self.user_id = :user_id
            INNER JOIN thread_participants tp ON tp.thread_id = mt.id
            INNER JOIN users u ON u.id = tp.user_id
            GROUP BY mt.id, mt.subject, mt.last_message_at, mt.last_message_preview, tp_self.last_read_at
            ORDER BY mt.last_message_at DESC
            LIMIT 25';

    $stmt = $db->prepare($sql);
    $stmt->execute(['user_id' => $userId]);

    return $stmt->fetchAll() ?: [];
}

function listMessages(PDO $db, int $threadId, int $userId): array
{
    ensureThreadMembership($db, $threadId, $userId);

    $sql = 'SELECT m.id, m.sender_id, s.name AS sender_name, m.message_text, m.created_at
            FROM messages m
            INNER JOIN users s ON s.id = m.sender_id
            WHERE m.thread_id = :thread_id
            ORDER BY m.created_at ASC
            LIMIT 100';

    $stmt = $db->prepare($sql);
    $stmt->execute(['thread_id' => $threadId]);
    $messages = $stmt->fetchAll() ?: [];

    $update = $db->prepare('UPDATE thread_participants SET last_read_at = NOW() WHERE thread_id = :thread_id AND user_id = :user_id');
    $update->execute(['thread_id' => $threadId, 'user_id' => $userId]);

    return $messages;
}

function sendMessage(PDO $db, int $senderId, string $senderRole, int $threadId, int $recipientId, string $body): array
{
    if ($threadId > 0) {
        $participantIds = ensureThreadMembership($db, $threadId, $senderId);
        $recipientId = resolveRecipient($participantIds, $senderId, $recipientId);
    } else {
        $recipient = fetchUserProfile($db, $recipientId);
        if (!$recipient) {
            respondJson(['success' => false, 'message' => 'Recipient not found.'], 404);
        }
        $threadId = createThread($db, $senderId, $senderRole, $recipientId, $recipient['role'] ?? 'student');
        $participantIds = [$senderId, $recipientId];
    }

    $stmt = $db->prepare('INSERT INTO messages (thread_id, sender_id, recipient_id, message_text, context_type) VALUES (:thread_id, :sender_id, :recipient_id, :message, :context)');
    $stmt->execute([
        'thread_id' => $threadId,
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'message' => $body,
        'context' => 'general',
    ]);

    $messageId = (int) $db->lastInsertId();

    $preview = mb_substr($body, 0, 140);
    $updateThread = $db->prepare('UPDATE message_threads SET last_message_at = NOW(), last_message_preview = :preview WHERE id = :thread_id');
    $updateThread->execute(['preview' => $preview, 'thread_id' => $threadId]);

    $db->prepare('UPDATE thread_participants SET last_read_at = NOW() WHERE thread_id = :thread_id AND user_id = :user_id')
        ->execute(['thread_id' => $threadId, 'user_id' => $senderId]);

    if ($recipientId > 0) {
        notifyRecipient($db, $recipientId, $senderId, $body, $threadId);
    }

    $targets = array_values(array_filter($participantIds, static function (int $id) use ($senderId): bool {
        return $id > 0 && $id !== $senderId;
    }));

    if (count($targets) > 0) {
        RealtimeNotifier::sendMessageEvent($targets, $threadId, $body, $senderId);
    }

    return [
        'id' => $messageId,
        'thread_id' => $threadId,
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'message_text' => $body,
    ];
}

function ensureThreadMembership(PDO $db, int $threadId, int $userId): array
{
    $sql = 'SELECT user_id FROM thread_participants WHERE thread_id = :thread_id';
    $stmt = $db->prepare($sql);
    $stmt->execute(['thread_id' => $threadId]);
    $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$participants || !in_array($userId, array_map('intval', $participants), true)) {
        respondJson(['success' => false, 'message' => 'You do not have access to this conversation.'], 403);
    }

    return array_map('intval', $participants);
}

function resolveRecipient(array $participants, int $senderId, int $fallback): int
{
    foreach ($participants as $participant) {
        if ($participant !== $senderId) {
            return $participant;
        }
    }

    return $fallback > 0 ? $fallback : $senderId;
}

function fetchUserProfile(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare('SELECT id, role, name FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function createThread(PDO $db, int $senderId, string $senderRole, int $recipientId, string $recipientRole): int
{
    $subject = 'Direct message';
    $stmt = $db->prepare('INSERT INTO message_threads (subject, context_type, created_by) VALUES (:subject, :context, :created_by)');
    $stmt->execute([
        'subject' => $subject,
        'context' => 'general',
        'created_by' => $senderId,
    ]);

    $threadId = (int) $db->lastInsertId();

    $insertParticipant = $db->prepare('INSERT INTO thread_participants (thread_id, user_id, role, last_read_at) VALUES (:thread_id, :user_id, :role, NOW())');
    $insertParticipant->execute(['thread_id' => $threadId, 'user_id' => $senderId, 'role' => normalizeRole($senderRole)]);
    $insertParticipant->execute(['thread_id' => $threadId, 'user_id' => $recipientId, 'role' => normalizeRole($recipientRole)]);

    return $threadId;
}

function normalizeRole(string $role): string
{
    return match ($role) {
        'admin' => 'admin',
        'tutor' => 'tutor',
        default => 'student',
    };
}

function notifyRecipient(PDO $db, int $recipientId, int $senderId, string $body, int $threadId): void
{
    $title = 'New direct message';
    $preview = mb_substr($body, 0, 140);
    $data = json_encode(['thread_id' => $threadId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $db->prepare('INSERT INTO user_notifications (user_id, source, title, body, level, data, created_by) VALUES (:user_id, :source, :title, :body, :level, :data, :created_by)');
    $stmt->execute([
        'user_id' => $recipientId,
        'source' => 'system',
        'title' => $title,
        'body' => $preview,
        'level' => 'info',
        'data' => $data,
        'created_by' => $senderId,
    ]);
}
