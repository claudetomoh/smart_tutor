<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'POST') {
    respondError(405, 'Only POST requests are supported.');
}

try {
    $payload = readJsonBody();
    $name = trim((string) ($payload['name'] ?? ''));
    $email = filter_var((string) ($payload['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
    $role = strtolower(trim((string) ($payload['role'] ?? 'other')));
    $message = trim((string) ($payload['message'] ?? ''));

    $allowedRoles = ['student', 'parent', 'tutor', 'organization'];
    if ($role === '' || !in_array($role, $allowedRoles, true)) {
        $role = 'other';
    }

    if ($name === '' || $email === '' || $message === '') {
        respondError(422, 'Please complete all required fields.');
    }

    $db = Database::getInstance();
    ensureContactTable($db);

    $stmt = $db->prepare('INSERT INTO contact_requests (name, email, requester_role, message, status, created_at) VALUES (?, ?, ?, ?, "new", NOW())');
    $stmt->execute([$name, $email, $role, $message]);

    respondJson([
        'status' => 'ok',
        'message' => 'Thanks for reaching out! Our team will respond shortly.',
    ], 201);
} catch (Throwable $exception) {
    respondError(500, 'Unable to submit your request right now. Please try again later.', [
        'detail' => $exception->getMessage(),
    ]);
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respondError(400, 'Invalid JSON payload received.');
    }

    return $data;
}

function respondJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function ensureContactTable(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS contact_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(190) NOT NULL,
        requester_role ENUM("student", "parent", "tutor", "organization", "other") NOT NULL DEFAULT "other",
        message TEXT NOT NULL,
        status ENUM("new", "in_progress", "closed") NOT NULL DEFAULT "new",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        handled_at TIMESTAMP NULL DEFAULT NULL,
        handled_by VARCHAR(150) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
