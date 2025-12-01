<?php
session_start();
require_once __DIR__ . '/../connect.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	header('Location: sign-in.html');
	exit();
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
	returnWithError('Please enter both email and password.');
}

$stmt = $conn->prepare('SELECT id, role, name, password_hash FROM users WHERE email = ? LIMIT 1');
if (!$stmt) {
	returnWithError('Something went wrong while checking your account. Please try again.', $conn->error);
}

$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
	$stmt->close();
	returnWithError('Invalid email or password.');
}

$stmt->bind_result($user_id, $role, $name, $hashedPassword);
$stmt->fetch();

if (!password_verify($password, $hashedPassword ?? '')) {
	$stmt->close();
	returnWithError('Invalid email or password.');
}

$_SESSION['user_id'] = $user_id;
$_SESSION['name'] = $name;
$_SESSION['role'] = $role;

$stmt->close();
$conn->close();

$redirectMap = [
	'admin' => 'admin.php',
	'tutor' => 'dashboard.php',
	'student' => 'dashboard.php',
];

$target = $redirectMap[$role] ?? 'dashboard.php';

header("Location: {$target}");
exit();

function returnWithError(string $message, ?string $debug = null): void
{
	if ($debug) {
		error_log('[sign-in] ' . $debug);
	}

	$location = 'sign-in.html?error=' . urlencode($message);
	header("Location: {$location}");
	exit();
}
?>
