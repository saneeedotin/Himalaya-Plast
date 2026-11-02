<?php
declare(strict_types=1);

/**
 * Restores a backup made by tools/backup.php into a database.
 *
 *   php tools/restore.php backups/hpos-20261101-020000.sql.gz --database=hpos_restore
 *
 * The target database must exist and should be empty; it is NOT created or emptied for you, so a
 * restore can never overwrite live data by accident. To replace the live database: restore into a
 * new database first, check it, then point config.php at it (or rename).
 * Credentials: HPOS_DB_USER / HPOS_DB_PASS in the environment, else config.php 'backup', else the app's.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;

$file = $argv[1] ?? '';
$target = '';
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--database=([A-Za-z0-9_]+)$/', $a, $m)) {
        $target = $m[1];
    }
}
if (!is_file($file) || $target === '') {
    fwrite(STDERR, "usage: php tools/restore.php <backup.sql.gz> --database=<empty database>\n");
    exit(2);
}
$db = (array) Config::get('db', []);
if ($target === ($db['name'] ?? '')) {
    fwrite(STDERR, "Refusing to restore over the live database '$target'. Restore into a new database and switch over after checking it.\n");
    exit(2);
}

$user = getenv('HPOS_DB_USER') ?: (string) Config::get('backup.user', $db['user'] ?? '');
$pass = getenv('HPOS_DB_PASS') !== false && getenv('HPOS_DB_PASS') !== '' ? (string) getenv('HPOS_DB_PASS') : (string) Config::get('backup.pass', $db['pass'] ?? '');
$bin = (string) Config::get('backup.mysql', 'mysql');

$cnf = tempnam(sys_get_temp_dir(), 'hpos');
file_put_contents($cnf, "[client]\nuser=" . $user . "\npassword=\"" . addcslashes($pass, "\"\\") . "\"\nhost=" . ($db['host'] ?? '127.0.0.1') . "\nport=" . (int) ($db['port'] ?? 3306) . "\n");
chmod($cnf, 0600);

$proc = proc_open(escapeshellarg($bin) . ' --defaults-extra-file=' . escapeshellarg($cnf) . ' --default-character-set=utf8mb4 ' . escapeshellarg($target), [0 => ['pipe', 'r'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) {
    @unlink($cnf);
    fwrite(STDERR, "Cannot run '$bin'.\n");
    exit(2);
}
// A dump names the account that owned each view, procedure and trigger. On another host, or under another
// database user, that account does not exist and the import stops. Dropping the DEFINER clause makes the
// restoring account the owner instead.
$gz = gzopen($file, 'rb');
while (($line = gzgets($gz, 1 << 22)) !== false) {
    if (str_contains($line, 'DEFINER')) {
        $line = preg_replace('/\s*DEFINER\s*=\s*(`[^`]*`|\'[^\']*\')@(`[^`]*`|\'[^\']*\')/', '', $line) ?? $line;
    }
    fwrite($pipes[0], $line);
}
gzclose($gz);
fclose($pipes[0]);
$err = stream_get_contents($pipes[2]);
$code = proc_close($proc);
@unlink($cnf);

if ($code !== 0) {
    fwrite(STDERR, "Restore FAILED: " . trim($err) . "\n");
    exit(1);
}
echo "Restored $file into $target.\n";
