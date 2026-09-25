<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || DIRECTORY_SEPARATOR === '\\') {
    fwrite(STDERR, "Run this one-time bootstrap from the Linux cPanel Terminal.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/api/config.php';

const DEFAULT_ADMIN_EMAIL = 'development@dataholics.com.mx';

function readHiddenPassword(string $prompt): string
{
    if (!function_exists('shell_exec')) {
        throw new RuntimeException('The CLI must permit stty so the password can be entered without echo.');
    }

    $terminalMode = trim((string)shell_exec('stty -g 2>/dev/null'));
    if ($terminalMode === '') {
        throw new RuntimeException('Run this command in an interactive Linux terminal.');
    }

    fwrite(STDERR, $prompt);
    shell_exec('stty -echo');
    try {
        $value = fgets(STDIN);
    } finally {
        shell_exec('stty ' . escapeshellarg($terminalMode) . ' 2>/dev/null');
        fwrite(STDERR, PHP_EOL);
    }

    if ($value === false) {
        throw new RuntimeException('Could not read the password.');
    }
    return rtrim($value, "\r\n");
}

try {
    $db = database();
    $existing = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $existing->execute(['email' => DEFAULT_ADMIN_EMAIL]);
    if ($existing->fetchColumn()) {
        throw new RuntimeException('The default admin already exists; bootstrap cannot reset its password.');
    }

    fwrite(STDERR, 'Password for ' . DEFAULT_ADMIN_EMAIL . ' (input hidden): ');
    $password = readHiddenPassword('');
    $confirmation = readHiddenPassword('Confirm password (input hidden): ');
    if (strlen($password) < 12 || !hash_equals($password, $confirmation)) {
        unset($password, $confirmation);
        throw new RuntimeException('Passwords must match and contain at least 12 characters.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    unset($password, $confirmation);
    if ($passwordHash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    $insert = $db->prepare(
        "INSERT INTO users (display_name, email, password_hash, role, status, email_verified_at)
         VALUES (:display_name, :email, :password_hash, 'admin', 'active', CURRENT_TIMESTAMP)"
    );
    $insert->execute([
        'display_name' => 'Development Admin',
        'email' => DEFAULT_ADMIN_EMAIL,
        'password_hash' => $passwordHash,
    ]);
    unset($passwordHash);

    fwrite(STDOUT, "Default admin created and email marked verified. Remove scripts/bootstrap-admin.php after confirming the account.\n");
} catch (Throwable $error) {
    unset($password, $confirmation, $passwordHash);
    error_log((string)$error);
    fwrite(STDERR, "Admin bootstrap failed. See the server error log; no password was printed.\n");
    exit(1);
}
