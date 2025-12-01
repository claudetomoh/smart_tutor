<?php

declare(strict_types=1);

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use Throwable;

require_once __DIR__ . '/lib/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError(405, 'Method not allowed.');
}

try {
    $payload = readJsonBody();
    $input = validatePayload($payload);
    $db = Database::getInstance();

    $tutor = fetchTutor($db, $input['tutor_id']);
    if (!$tutor) {
        respondError(404, 'Tutor not found.');
    }

    $reference = persistBooking($db, $input);

    respondJson([
        'status' => 'ok',
        'message' => 'Booking request received.',
        'reference' => $reference,
        'data' => [
            'tutor' => $tutor,
            'student_name' => $input['student_name'],
            'student_email' => $input['student_email'],
            'student_phone' => $input['student_phone'],
            'datetime' => $input['requested_for']->format(DateTimeInterface::ATOM),
            'timezone' => $input['timezone'],
            'message' => $input['message'],
        ],
    ], 201);
} catch (Throwable $exception) {
    respondError(500, 'Could not create booking request.', [
        'detail' => $exception->getMessage(),
    ]);
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        respondError(400, 'Empty request body.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respondError(400, 'Invalid JSON payload.');
    }

    return $decoded;
}

function validatePayload(array $payload): array
{
    $required = ['tutor_id', 'datetime', 'student_name', 'student_email'];
    $missing = [];

    foreach ($required as $field) {
        if (empty($payload[$field])) {
            $missing[] = $field;
        }
    }

    if ($missing) {
        respondError(422, 'Missing required fields.', ['fields' => $missing]);
    }

    $tutorId = (int) $payload['tutor_id'];
    if ($tutorId <= 0) {
        respondError(422, 'Tutor ID must be a positive integer.', ['field' => 'tutor_id']);
    }

    $studentName = trim((string) $payload['student_name']);
    if ($studentName === '') {
        respondError(422, 'Please provide the name of the student.', ['field' => 'student_name']);
    }

    $studentEmail = filter_var((string) $payload['student_email'], FILTER_VALIDATE_EMAIL);
    if (!$studentEmail) {
        respondError(422, 'Please provide a valid email address.', ['field' => 'student_email']);
    }

    $studentPhone = isset($payload['student_phone']) ? trim((string) $payload['student_phone']) : null;
    $timezone = isset($payload['timezone']) ? trim((string) $payload['timezone']) : null;
    $message = trim((string) ($payload['message'] ?? ''));

    $requestedFor = parseRequestedDateTime((string) $payload['datetime'], $timezone);
    $now = new DateTimeImmutable('now');
    if ($requestedFor <= $now) {
        respondError(422, 'Please choose a future date and time for the session.', ['field' => 'datetime']);
    }

    return [
        'tutor_id' => $tutorId,
        'student_name' => $studentName,
        'student_email' => strtolower($studentEmail),
        'student_phone' => $studentPhone ?: null,
        'timezone' => $timezone ?: null,
        'message' => $message,
        'requested_for' => $requestedFor,
    ];
}

function parseRequestedDateTime(string $value, ?string $timezone): DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        respondError(422, 'Please provide the desired session time.', ['field' => 'datetime']);
    }

    $tz = $timezone ?: 'UTC';

    $formats = [
        DateTimeInterface::ATOM,
        'Y-m-d\TH:i',
        'Y-m-d H:i',
    ];

    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone($tz));
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone($tz));
    }

    respondError(422, 'Unable to parse the provided date and time.', ['field' => 'datetime']);
}

function fetchTutor(PDO $db, int $tutorId): ?array
{
    $stmt = $db->prepare('SELECT id, name, email FROM users WHERE id = ? AND role = "tutor" LIMIT 1');
    $stmt->execute([$tutorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
    ];
}

function persistBooking(PDO $db, array $input): string
{
    $reference = generateReference();

    $stmt = $db->prepare('INSERT INTO booking_requests (tutor_id, student_name, student_email, student_phone, requested_for, timezone, message, reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $input['tutor_id'],
        $input['student_name'],
        $input['student_email'],
        $input['student_phone'],
        $input['requested_for']->format('Y-m-d H:i:s'),
        $input['timezone'],
        $input['message'],
        $reference,
    ]);

    return $reference;
}

function generateReference(): string
{
    return sprintf('BK-%s', strtoupper(bin2hex(random_bytes(4))));
}

function respondJson(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function respondError(int $status, string $message, array $meta = []): void
{
    respondJson([
        'status' => 'error',
        'message' => $message,
        'meta' => $meta,
    ], $status);
}

