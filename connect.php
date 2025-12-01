<?php
declare(strict_types=1);

(function (): void {
    $envFile = __DIR__ . '/.env';
    if (!is_readable($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        $key = trim($parts[0] ?? '');
        if ($key === '') {
            continue;
        }

        $value = trim($parts[1] ?? '');
        $value = trim($value, "\"' ");

        putenv(sprintf('%s=%s', $key, $value));
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
})();

$config = require __DIR__ . '/api/config/database.php';

$host = $config['host'] ?? 'localhost';
$port = (int) ($config['port'] ?? 3306);
$database = $config['database'] ?? 'webtech_2025A_tomoh_ikfingeh';
$username = $config['username'] ?? 'tomoh.ikfingeh';
$password = $config['password'] ?? 'STCL@ude20@?';
$charset = $config['charset'] ?? 'utf8mb4';

$conn = new mysqli($host, $username, $password, '', $port);

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$escapedDb = $conn->real_escape_string($database);
$sql = "CREATE DATABASE IF NOT EXISTS `{$escapedDb}` CHARACTER SET {$charset} COLLATE utf8mb4_unicode_ci";
if (!$conn->query($sql)) {
    die('Error creating database: ' . $conn->error);
}

if (!$conn->select_db($database)) {
    die('Error selecting database: ' . $conn->error);
}

if (!$conn->set_charset($charset)) {
    die('Error setting charset: ' . $conn->error);
}
?>
