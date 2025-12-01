<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['tutor', 'admin'], true)) {
    jsonResponse(['success' => false, 'message' => 'Tutor authentication required.'], 403);
}

require_once __DIR__ . '/../../connect.php';

define('ADMIN_ACTIONS_EMBEDDED', true);
require_once __DIR__ . '/../../pages/admin_actions.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        handleTutorBookingsIndex($conn, $userId);
        break;
    case 'POST':
        handleTutorBookingAction($conn, $userId);
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$conn->close();
exit;

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function handleTutorBookingsIndex(mysqli $conn, int $tutorId): void
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(25, max(5, (int) ($_GET['per_page'] ?? 10)));
    $status = trim((string) ($_GET['status'] ?? ''));

    $conditions = ['br.tutor_id = ?'];
    $types = 'i';
    $params = [$tutorId];

    if ($status !== '') {
        $conditions[] = 'br.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    $search = trim((string) ($_GET['search'] ?? ''));
    if ($search !== '') {
        $conditions[] = '(br.reference LIKE ? OR br.student_name LIKE ? OR br.student_email LIKE ?)';
        $types .= 'sss';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $total = fetchScalarPrepared(
        $conn,
        "SELECT COUNT(*) FROM booking_requests br {$where}",
        $types,
        $params
    );

    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $rows = fetchAssocPrepared(
        $conn,
        "SELECT br.id, br.reference, br.status, br.requested_for, br.created_at, br.student_name, br.student_email,
                br.student_id, br.message, br.cancellation_reason, br.status_changed_by,
                sc.name AS status_changed_by_name
         FROM booking_requests br
         LEFT JOIN users sc ON sc.id = br.status_changed_by
         {$where}
         ORDER BY br.created_at DESC
         LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$perPage, $offset])
    );

    jsonResponse([
        'success' => true,
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
        ],
    ]);
}

function handleTutorBookingAction(mysqli $conn, int $tutorId): void
{
    $payload = parseRequestPayload();
    $action = $payload['action'] ?? '';
    $bookingId = (int) ($payload['booking_id'] ?? 0);

    switch ($action) {
        case 'tutor_accept':
            $result = tutorUpdateBookingStatus($conn, $tutorId, $bookingId, 'accepted', null);
            jsonResponse($result, $result['success'] ? 200 : 422);
            break;
        case 'tutor_decline':
            $reason = trim((string) ($payload['reason'] ?? ''));
            $result = tutorUpdateBookingStatus($conn, $tutorId, $bookingId, 'declined', $reason);
            jsonResponse($result, $result['success'] ? 200 : 422);
            break;
        case 'tutor_cancel':
            $reason = trim((string) ($payload['reason'] ?? ''));
            $result = tutorUpdateBookingStatus($conn, $tutorId, $bookingId, 'cancelled', $reason);
            jsonResponse($result, $result['success'] ? 200 : 422);
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Unsupported tutor action.'], 400);
    }
}

function parseRequestPayload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = (string) file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        return [];
    }

    return $_POST;
}

function fetchScalarPrepared(mysqli $conn, string $sql, string $types, array $params): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    bindStatementParams($stmt, $types, $params);

    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $stmt->bind_result($value);
    $stmt->fetch();
    $stmt->close();

    return (int) ($value ?? 0);
}

function fetchAssocPrepared(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    bindStatementParams($stmt, $types, $params);

    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function bindStatementParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }

    $stmt->bind_param($types, ...$refs);
}

function tutorUpdateBookingStatus(mysqli $conn, int $tutorId, int $bookingId, string $newStatus, ?string $reason): array
{
    if ($tutorId <= 0 || $bookingId <= 0) {
        return ['success' => false, 'message' => 'Invalid booking reference.'];
    }

    $allowed = ['accepted', 'declined', 'cancelled'];
    if (!in_array($newStatus, $allowed, true)) {
        return ['success' => false, 'message' => 'Unsupported booking status update.'];
    }

    $booking = fetchTutorBooking($conn, $tutorId, $bookingId);
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found or already handled.'];
    }

    $currentStatus = $booking['status'] ?? 'pending';
    if ($newStatus === 'accepted' && $currentStatus !== 'pending') {
        return ['success' => false, 'message' => 'Only pending requests may be accepted.'];
    }

    if (in_array($newStatus, ['declined', 'cancelled'], true) && $reason === null) {
        return ['success' => false, 'message' => 'Please include a short note explaining the update.'];
    }

    $stmt = $conn->prepare('UPDATE booking_requests SET status = ?, status_changed_by = ?, cancellation_reason = ?, updated_at = NOW() WHERE id = ? AND tutor_id = ?');
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to update booking status.'];
    }

    $reasonValue = $reason !== null ? $reason : null;
    $stmt->bind_param('sisii', $newStatus, $tutorId, $reasonValue, $bookingId, $tutorId);

    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to update booking.'];
    }
    $stmt->close();

    if (!empty($booking['student_id'])) {
        $level = $newStatus === 'accepted' ? 'success' : 'warning';
        $title = 'Booking update';
        $body = buildStudentMessageBody($booking, $newStatus, $reasonValue);
        recordUserNotification($conn, (int) $booking['student_id'], $tutorId, $title, $body, $level, [
            'booking_reference' => $booking['reference'] ?? null,
            'status' => $newStatus,
        ]);
    }

    return [
        'success' => true,
        'message' => sprintf('Booking %s marked as %s.', $booking['reference'] ?? '#', $newStatus),
        'data' => [
            'booking_id' => $bookingId,
            'status' => $newStatus,
        ],
    ];
}

function fetchTutorBooking(mysqli $conn, int $tutorId, int $bookingId): ?array
{
    $stmt = $conn->prepare('SELECT id, reference, status, student_id FROM booking_requests WHERE id = ? AND tutor_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $bookingId, $tutorId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function buildStudentMessageBody(array $booking, string $status, ?string $reason): string
{
    $reference = $booking['reference'] ?? '#';
    $statusLabel = ucfirst($status);

    if ($status === 'accepted') {
        return "Great news! Your booking {$reference} was accepted. We will follow up with scheduling details.";
    }

    $reasonText = $reason ? ' Reason: ' . $reason : '';
    return "Booking {$reference} was {$statusLabel}." . $reasonText;
}
