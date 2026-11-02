<?php
declare(strict_types=1);

// Prints a password hash for the first admin. The password is read from stdin so it
// never lands in shell history:
//   php tools/hash_password.php
// then:  UPDATE users SET password_hash='<hash>' WHERE email='admin@himalayaplast.local';
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

fwrite(STDERR, 'New password: ');
$password = rtrim((string) fgets(STDIN), "\r\n");
if (strlen($password) < 10) {
    fwrite(STDERR, "Use at least 10 characters.\n");
    exit(1);
}
echo password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT), "\n";
