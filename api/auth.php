<?php

declare(strict_types=1);

use JsonException;
use PDO;

require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Security.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}

enforceRateLimit(8, 60); // at most 8 requests per minute per IP

try {
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

	if ($method === 'GET') {
		handleSessionValidation();
		exit;
	}

	if ($method === 'POST') {
		$action = strtolower((string) ($_GET['action'] ?? 'login'));
		$payload = readJsonBody();

		switch ($action) {
			case 'register':
				handleRegister($payload);
				break;
			case 'login':
				handleLogin($payload);
				break;
			default:
				respondError(400, 'Unsupported action requested.');
		}

		exit;
	}

	respondError(405, 'Method not allowed.');
} catch (Throwable $exception) {
	respondError(500, 'Unexpected server error.', [
		'detail' => $exception->getMessage(),
	]);
}

/**
 * Handle POST /auth.php?action=register
 */
function handleRegister(array $payload): void
{
	$email = filterEmail($payload['email'] ?? null);
	$role = strtolower(trim((string) ($payload['role'] ?? 'student')));
	$firstName = trim((string) ($payload['first_name'] ?? ''));
	$lastName = trim((string) ($payload['last_name'] ?? ''));
	$displayName = trim((string) ($payload['name'] ?? trim($firstName . ' ' . $lastName)));
	$password = (string) ($payload['password'] ?? '');

	$validRoles = ['student', 'tutor', 'admin'];

	if (!$email) {
		respondError(422, 'Please supply a valid email address.', ['field' => 'email']);
	}

	if (!in_array($role, $validRoles, true)) {
		respondError(422, 'Please choose a valid account type.', ['field' => 'role']);
	}

	if ($displayName === '') {
		respondError(422, 'Please provide your full name.', ['field' => 'name']);
	}

	$passwordIssues = Security::validatePassword($password);
	if (!empty($passwordIssues)) {
		respondError(422, $passwordIssues[0], ['field' => 'password', 'issues' => $passwordIssues]);
	}

	$db = Database::getInstance();

	$stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
	$stmt->execute([$email]);

	if ($stmt->fetch()) {
		respondError(409, 'That email is already registered. Try signing in instead.', ['field' => 'email']);
	}

	$passwordHash = password_hash($password, PASSWORD_DEFAULT);

	$insert = $db->prepare('INSERT INTO users (email, name, password_hash, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, "active", NOW(), NOW())');
	$insert->execute([$email, $displayName, $passwordHash, $role]);

	$userId = (int) $db->lastInsertId();

	$user = fetchSafeUserById($db, $userId);

	respondJson([
		'status' => 'ok',
		'message' => 'Registration successful. Redirecting to your dashboard...',
		'token' => issueToken($user),
		'user' => $user,
	], 201);
}

/**
 * Handle POST /auth.php?action=login
 */
function handleLogin(array $payload): void
{
	$email = filterEmail($payload['email'] ?? null);
	$password = (string) ($payload['password'] ?? '');

	if (!$email) {
		respondError(422, 'Please provide the email you registered with.', ['field' => 'email']);
	}

	if ($password === '') {
		respondError(422, 'Please enter your password.', ['field' => 'password']);
	}

	if (Security::isAccountLocked($email)) {
		respondError(423, 'This account is temporarily locked due to repeated failed attempts. Please try again later or reset your password.');
	}

	$db = Database::getInstance();
	$stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
	$stmt->execute([$email]);
	$userRow = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$userRow || !password_verify($password, (string) $userRow['password_hash'])) {
		if ($userRow && isset($userRow['id'])) {
			$db->prepare('UPDATE users SET failed_login_attempts = failed_login_attempts + 1, last_failed_login = NOW() WHERE id = ?')->execute([(int) $userRow['id']]);
		}
		Security::logLoginAttempt($email, getClientIp(), false);
		respondError(401, 'Invalid email or password.');
	}

	if ($userRow['status'] !== 'active') {
		Security::logLoginAttempt($email, getClientIp(), false);
		respondError(403, 'Your account is not active. Please contact support.');
	}

	Security::logLoginAttempt($email, getClientIp(), true);

	$db->prepare('UPDATE users SET last_login = NOW(), failed_login_attempts = 0, last_failed_login = NULL WHERE id = ?')->execute([(int) $userRow['id']]);

	$user = sanitiseUser($userRow);

	respondJson([
		'status' => 'ok',
		'message' => 'Login successful.',
		'token' => issueToken($user),
		'user' => $user,
	]);
}

/**
 * Handle GET /auth.php (session validation)
 */
function handleSessionValidation(): void
{
	$headers = getallheaders();
	$token = trim(str_replace('Bearer', '', (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '')));

	if ($token === '') {
		respondError(401, 'No token provided.');
	}

	$payload = validateToken($token);
	if (!$payload) {
		respondError(401, 'Invalid or expired token.');
	}

	$db = Database::getInstance();
	$user = fetchSafeUserById($db, (int) $payload['sub']);

	if (!$user) {
		respondError(401, 'User not found.');
	}

	respondJson([
		'status' => 'ok',
		'user' => $user,
	]);
}

/**
 * @return array|null
 */
function validateToken(string $token)
{
	$parts = explode('.', $token);

	if (count($parts) !== 3) {
		return null;
	}

	[$headerB64, $payloadB64, $signatureB64] = $parts;

	$expected = base64UrlEncode(hash_hmac('sha256', $headerB64 . '.' . $payloadB64, getJwtSecret(), true));

	if (!hash_equals($expected, $signatureB64)) {
		return null;
	}

	try {
		$payload = json_decode(base64UrlDecode($payloadB64), true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $exception) {
		return null;
	}

	if (!is_array($payload)) {
		return null;
	}

	if (!isset($payload['exp']) || (int) $payload['exp'] < time()) {
		return null;
	}

	return $payload;
}

function issueToken(array $user): string
{
	$header = base64UrlEncode(json_encode([
		'alg' => 'HS256',
		'typ' => 'JWT',
	], JSON_THROW_ON_ERROR));

	$payload = base64UrlEncode(json_encode([
		'iss' => 'smarttutor-connect',
		'sub' => $user['id'],
		'email' => $user['email'],
		'role' => $user['role'],
		'exp' => time() + (60 * 60 * 24), // 24 hours
		'iat' => time(),
		'jti' => bin2hex(random_bytes(16)),
	], JSON_THROW_ON_ERROR));

	$signature = base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, getJwtSecret(), true));

	return $header . '.' . $payload . '.' . $signature;
}

function fetchSafeUserById(PDO $db, int $userId): ?array
{
	$stmt = $db->prepare('SELECT id, email, name, role, status, created_at, updated_at, last_login FROM users WHERE id = ? LIMIT 1');
	$stmt->execute([$userId]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	return $row ? sanitiseUser($row) : null;
}

function sanitiseUser(array $row): array
{
	return [
		'id' => (int) $row['id'],
		'email' => $row['email'],
		'name' => $row['name'] ?? '',
		'role' => $row['role'] ?? 'student',
		'status' => $row['status'] ?? 'active',
		'created_at' => $row['created_at'] ?? null,
		'updated_at' => $row['updated_at'] ?? null,
		'last_login' => $row['last_login'] ?? null,
	];
}

function readJsonBody(): array
{
	$input = file_get_contents('php://input') ?: '';

	if ($input === '') {
		respondError(400, 'Empty request body.');
	}

	try {
		$decoded = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $exception) {
		respondError(400, 'Invalid JSON payload.');
	}

	if (!is_array($decoded)) {
		respondError(400, 'Invalid JSON payload.');
	}

	return $decoded;
}

function respondJson(array $data, int $statusCode = 200): void
{
	http_response_code($statusCode);
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function respondError(int $statusCode, string $message, array $meta = []): void
{
	respondJson([
		'status' => 'error',
		'message' => $message,
		'meta' => $meta,
	], $statusCode);
}

function base64UrlEncode(string $data): string
{
	return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string
{
	return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

function getJwtSecret(): string
{
	$secret = getenv('JWT_SECRET');

	if ($secret && $secret !== '') {
		return $secret;
	}

	$configPath = __DIR__ . '/config/app.php';

	if (file_exists($configPath)) {
		/** @phpstan-ignore-next-line */
		$config = require $configPath;
		if (is_array($config) && !empty($config['jwt_secret'])) {
			return (string) $config['jwt_secret'];
		}
	}

	return 'change-me-in-env';
}

function filterEmail(?string $value): ?string
{
	$email = filter_var((string) $value, FILTER_VALIDATE_EMAIL);
	return $email ? strtolower($email) : null;
}

function enforceRateLimit(int $limit, int $windowSeconds): void
{
	$clientIp = getClientIp();
	$cacheFile = sys_get_temp_dir() . '/smarttutor_rate_' . md5($clientIp);

	$attempts = [];
	if (file_exists($cacheFile)) {
		$stored = @file_get_contents($cacheFile);
		if ($stored !== false) {
			$attempts = @unserialize($stored) ?: [];
		}
	}

	$now = time();
	$attempts = array_filter($attempts, static function ($timestamp) use ($now, $windowSeconds) {
		return ($timestamp >= ($now - $windowSeconds));
	});

	if (count($attempts) >= $limit) {
		respondError(429, 'Too many requests. Please slow down.');
	}

	$attempts[] = $now;
	@file_put_contents($cacheFile, serialize($attempts));
}

function getClientIp(): string
{
	$keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

	foreach ($keys as $key) {
		if (!empty($_SERVER[$key])) {
			$value = trim((string) $_SERVER[$key]);

			if ($key === 'HTTP_X_FORWARDED_FOR' && strpos($value, ',') !== false) {
				$parts = array_map('trim', explode(',', $value));
				$value = $parts[0];
			}

			return $value;
		}
	}

	return '0.0.0.0';
}



