<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json');

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    jsonResponse(['success' => false, 'message' => 'Administrator authentication required.'], 403);
}

require_once __DIR__ . '/../../connect.php';
define('ADMIN_ACTIONS_EMBEDDED', true);
require_once __DIR__ . '/../../pages/admin_actions.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        handleBookingsIndex($conn);
        break;
    case 'POST':
        handleBookingsAction($conn, (int) ($_SESSION['user_id'] ?? 0));
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$conn->close();
exit;

function handleBookingsIndex(mysqli $conn): void
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(50, max(5, (int) ($_GET['per_page'] ?? 15)));
    $status = trim((string) ($_GET['status'] ?? ''));
    $search = trim((string) ($_GET['search'] ?? ''));

    $types = '';
    $params = [];
    $clauses = [];

    if ($status !== '') {
        $clauses[] = 'br.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $clauses[] = '(br.reference LIKE ? OR br.student_name LIKE ? OR t.name LIKE ?)';
        $types .= 'sss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

    $countSql = "SELECT COUNT(*) FROM booking_requests br LEFT JOIN users t ON t.id = br.tutor_id {$whereSql}";
    $total = runScalarQuery($conn, $countSql, $types, $params);

    $offset = ($page - 1) * $perPage;
    $listSql = "SELECT br.id, br.reference, br.status, br.requested_for, br.created_at, br.student_name, br.student_email, br.student_id,
                       br.tutor_id, br.status_changed_by, br.cancellation_reason,
                       t.name AS tutor_name, t.email AS tutor_email,
                       sc.name AS status_changed_by_name
                FROM booking_requests br
                LEFT JOIN users t ON t.id = br.tutor_id
                LEFT JOIN users sc ON sc.id = br.status_changed_by
                {$whereSql}
                ORDER BY br.created_at DESC
                LIMIT ? OFFSET ?";

    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $rows = runSelectQuery($conn, $listSql, $listTypes, $listParams);

    jsonResponse([
        'success' => true,
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ],
    ]);
}

function handleBookingsAction(mysqli $conn, int $adminId): void
{
    if ($adminId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Session expired. Please sign in again.'], 401);
    }

    $payload = parseRequestPayload();
    $action = $payload['action'] ?? '';

    switch ($action) {
        case 'booking_cancel':
            $bookingId = (int) ($payload['booking_id'] ?? 0);
            $reason = trim((string) ($payload['reason'] ?? ''));
            $result = performBookingCancel($conn, $adminId, $bookingId, $reason);
            jsonResponse($result, $result['success'] ? 200 : 422);
            break;
        case 'booking_notify':
            $bookingId = (int) ($payload['booking_id'] ?? 0);
            $target = (string) ($payload['target'] ?? 'tutor');
            $message = trim((string) ($payload['message'] ?? ''));
            $result = performBookingNotify($conn, $adminId, $bookingId, $target, $message);
            jsonResponse($result, $result['success'] ? 200 : 422);
            break;
        case 'booking_update_status':
            $bookingId = (int) ($payload['booking_id'] ?? 0);
            $status = (string) ($payload['status'] ?? '');
            $note = trim((string) ($payload['note'] ?? ''));
            $result = performBookingUpdateStatus($conn, $adminId, $bookingId, $status, $note);
            jsonResponse($result, $result['success'] ? 200 : 422);
            break;
        case 'booking_flag':
            $bookingId = (int) ($payload['booking_id'] ?? 0);
            $severity = (string) ($payload['severity'] ?? 'medium');
            $note = trim((string) ($payload['note'] ?? ''));
            $result = performBookingFlag($conn, $adminId, $bookingId, $severity, $note);
            jsonResponse($result, $result['success'] ? 200 : 422);
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Unsupported action.'], 400);
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

function runScalarQuery(mysqli $conn, string $sql, string $types, array $params): int
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

function runSelectQuery(mysqli $conn, string $sql, string $types, array $params): array
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

    $stmt->bind_param($types, ...$params);
}

