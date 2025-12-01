<?php
session_start();
require_once __DIR__ . '/../connect.php';
ensureBioColumn($conn);

$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$password_confirmation = $_POST['password_confirmation'] ?? '';
$account_type = $_POST['account_type'] ?? 'student';
$bio = trim($_POST['bio'] ?? '');

$name = trim($first_name . ' ' . $last_name);

if (!$name || !$email || !$password) failWithMessage('Please fill in all required fields.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) failWithMessage('Invalid email format.');
if ($password !== $password_confirmation) failWithMessage('Passwords do not match.');

$allowedRoles = ['student', 'tutor', 'admin'];
if (!in_array($account_type, $allowedRoles, true)) {
    $account_type = 'student';
}

$stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
if (!$stmt) {
    failWithMessage('Unable to check existing accounts right now.', 500, $conn->error);
}
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) failWithMessage('Email already registered.');
$stmt->close();

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare('INSERT INTO users (role, name, email, password_hash, bio) VALUES (?, ?, ?, ?, ?)');
if (!$stmt) {
    failWithMessage('Unable to create your account right now.', 500, $conn->error);
}
$stmt->bind_param('sssss', $account_type, $name, $email, $hashedPassword, $bio);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    header("Location: sign-in.html");
    exit();
} else {
    failWithMessage('An unexpected error occurred. Please try again later.', 500, $conn->error);
}

function failWithMessage(string $message, int $status = 400, ?string $debugDetail = null): void
{
    if ($debugDetail) {
        error_log('[register] ' . $debugDetail);
    }

    http_response_code($status);
    echo $message;
    exit();
}

function ensureBioColumn(mysqli $conn): void
{
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'bio'");
    if ($result && $result->num_rows > 0) {
        $result->free();
        return;
    }

    if ($result) {
        $result->free();
    }

    $alter = "ALTER TABLE users ADD COLUMN bio TEXT NULL AFTER name";
    if (!$conn->query($alter)) {
        error_log('[register] Unable to add bio column: ' . $conn->error);
    }
}
?>
