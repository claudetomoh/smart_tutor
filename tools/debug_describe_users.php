<?php
require __DIR__ . '/../connect.php';
if (!isset($conn)) {
    exit("No connection\n");
}
$result = $conn->query('SHOW CREATE TABLE users');
if (!$result) {
    exit($conn->error . "\n");
}
$row = $result->fetch_assoc();
echo $row['Create Table'] ?? '';
$result->free();
