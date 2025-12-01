<?php
/**
 * Simple helper script to generate bcrypt hashes for local admin passwords.
 * Usage:
 *   1. Update the $plainText value below, or provide ?password=... in the URL.
 *   2. Run via browser:    http://localhost/SmartTutor/tools/hash.php?password=Secret123!
 *      or via CLI:         php tools/hash.php "Secret123!"
 */

declare(strict_types=1);

function resolvePassword(): string
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        if (!empty($argv[1])) {
            return $argv[1];
        }
    }

    if (isset($_GET['password']) && $_GET['password'] !== '') {
        return (string) $_GET['password'];
    }

    return 'ChangeMe123!';
}

$plainText = resolvePassword();
$hash = password_hash($plainText, PASSWORD_DEFAULT);

echo "Plain password: {$plainText}\n";
echo "Bcrypt hash:   {$hash}\n";

echo "\nPaste the hash into the users.password_hash column.";
