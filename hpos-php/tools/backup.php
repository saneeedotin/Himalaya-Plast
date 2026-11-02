<?php
declare(strict_types=1);

/**
 * Compressed backup of the whole database: tables, data, views, procedures, functions and triggers.
 *
 *   php tools/backup.php                          writes backups/hpos-YYYYmmdd-HHMMSS.sql.gz, keeps the newest 14
 *   php tools/backup.php --dir=/safe/place --keep=30
 *
 * Schedule it nightly from cron, for example:
 *   30 2 * * * php /home/you/hpos-php/tools/backup.php --dir=/home/you/backups
 * and copy the folder off the server. A backup on the same disk does not survive the disk.
 *
 * Credentials: config.php 'backup' => ['user' => ..., 'pass' => ...] if present, else the app's own
 * (HPOS_DB_USER / HPOS_DB_PASS in the environment override both). The account needs SELECT, SHOW VIEW,
 * TRIGGER, LOCK TABLES and, on MySQL 8, SHOW_ROUTINE; on shared hosting the cPanel database user has these.
 * The password goes to mysqldump through a temporary option file, never on the command line.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    }
}
$dir = rtrim($opts['dir'] ?? HPOS_ROOT . '/backups', '/\\');
$keep = max(1, (int) ($opts['keep'] ?? 14));

$db = (array) Config::get('db', []);
$user = getenv('HPOS_DB_USER') ?: (string) Config::get('backup.user', $db['user'] ?? '');
$pass = getenv('HPOS_DB_PASS') !== false && getenv('HPOS_DB_PASS') !== '' ? (string) getenv('HPOS_DB_PASS') : (string) Config::get('backup.pass', $db['pass'] ?? '');
$name = (string) ($db['name'] ?? '');
if ($name === '' || $user === '') {
    fwrite(STDERR, "No database settings found. Is config/config.php in place?\n");
    exit(2);
}

$bin = (string) Config::get('backup.mysqldump', 'mysqldump');
$help = (string) shell_exec(escapeshellarg($bin) . ' --help 2>&1');
if ($help === '' || stripos($help, 'Usage') === false) {
    fwrite(STDERR, "Cannot run '$bin'. Install the MySQL client tools or set backup.mysqldump in config.php.\n");
    exit(2);
}

if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(2);
}

$cnf = tempnam(sys_get_temp_dir(), 'hpos');
file_put_contents($cnf, "[client]\nuser=" . $user . "\npassword=\"" . addcslashes($pass, "\"\\") . "\"\nhost=" . ($db['host'] ?? '127.0.0.1') . "\nport=" . (int) ($db['port'] ?? 3306) . "\n");
chmod($cnf, 0600);

$flags = ['--single-transaction', '--routines', '--triggers', '--events', '--default-character-set=utf8mb4', '--hex-blob'];
if (stripos($help, 'no-tablespaces') !== false) {
    $flags[] = '--no-tablespaces';   // MySQL 8 otherwise needs the PROCESS privilege
}
$file = $dir . '/hpos-' . date('Ymd-His') . '.sql.gz';
$cmd = escapeshellarg($bin) . ' --defaults-extra-file=' . escapeshellarg($cnf) . ' ' . implode(' ', $flags) . ' ' . escapeshellarg($name);

$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$ok = is_resource($proc);
$bytes = 0;
if ($ok) {
    $gz = gzopen($file, 'wb9');
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1 << 20);
        if ($chunk === false || $chunk === '') {
            continue;
        }
        gzwrite($gz, $chunk);
        $bytes += strlen($chunk);
    }
    gzclose($gz);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($proc);
    $ok = $code === 0;
}
@unlink($cnf);

if (!$ok || $bytes < 1000) {
    @unlink($file);
    fwrite(STDERR, "Backup FAILED. " . trim((string) ($err ?? '')) . "\n");
    exit(1);
}

// a dump that does not end with the mysqldump trailer was cut short
$tail = '';
$gz = gzopen($file, 'rb');
while (!gzeof($gz)) {
    $tail = substr($tail . (string) gzread($gz, 65536), -400);
}
gzclose($gz);
if (stripos($tail, 'Dump completed') === false) {
    @unlink($file);
    fwrite(STDERR, "Backup FAILED: the dump was incomplete.\n");
    exit(1);
}

$all = glob($dir . '/hpos-*.sql.gz') ?: [];
rsort($all);
foreach (array_slice($all, $keep) as $old) {
    @unlink($old);
}
printf("Backup written: %s (%s KB, kept the newest %d)\n", $file, number_format(filesize($file) / 1024, 0), $keep);
