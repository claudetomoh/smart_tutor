<?php
if ($argc < 2) {
    exit("Usage: php debug_show_table.php <table>\n");
}
$table = $argv[1];
if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
    exit("Invalid table name\n");
}
require __DIR__ . '/../connect.php';
$result = $conn->query("SHOW FULL COLUMNS FROM `{$table}`");
if (!$result) {
    exit($conn->error . "\n");
}
while ($row = $result->fetch_assoc()) {
    printf("%s | %s | %s | %s\n", $row['Field'], $row['Type'], $row['Null'], $row['Comment']);
}
$result->free();
