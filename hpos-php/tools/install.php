<?php
declare(strict_types=1);

/**
 * One-command install for a new machine (laptop, test server). It creates the database, creates an
 * account that may only run stored procedures, loads the twenty SQL files in order, sets the first
 * administrator's password, writes config/config.php and runs the server check.
 *
 *   php tools/install.php                      # asks for the MySQL admin password and the new HPOS password
 *   php tools/install.php --host=127.0.0.1 --port=3306 --admin-user=root --db=hpos
 *
 * Options (all optional):
 *   --host=  --port=       MySQL / MariaDB server (default 127.0.0.1:3306)
 *   --admin-user=          account that may create databases and users (default root)
 *   --admin-pass=          its password; or set HPOS_DB_ADMIN_PASS; otherwise it is asked for
 *   --db=                  database name (default hpos)
 *   --app-user=            name of the procedures-only account (default hpos_app)
 *   --app-pass=            its password (default: a random one, written to config/config.php)
 *   --app-host=            host part of that account (default localhost)
 *   --admin-email=         first administrator's email (default admin@himalayaplast.local)
 *   --password=            first administrator's password; or HPOS_ADMIN_PASSWORD; otherwise asked for
 *   --mysql=               path to the mysql client (default: found on PATH or in the usual install folders)
 *   --no-config            do not write config/config.php; print the app account instead
 *   --demo                 a practice copy: database defaults to hpos_demo, the app account gets full rights on it
 *                          (the demo loader reads tables), and a demo factory is loaded. Use it for trying things
 *                          out, never for real data.
 *
 * It never drops anything: it stops if the database already has tables, if config/config.php exists,
 * or if the app account already exists without --app-pass.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const OK = "  OK    ";
const FAIL = "  FAIL  ";

$root = dirname(__DIR__);
$sqlDir = dirname($root) . '/database/mysql';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    } else {
        fwrite(STDERR, "Unknown argument: $a\n");
        exit(2);
    }
}

function stop(string $msg, int $code = 1): never
{
    fwrite(STDERR, FAIL . $msg . "\n");
    exit($code);
}

function ask(string $prompt, bool $allowEmpty = false): string
{
    fwrite(STDERR, $prompt);
    $v = fgets(STDIN);
    if ($v === false) {
        stop('No input available. Pass the value as an option or environment variable instead.');
    }
    $v = rtrim($v, "\r\n");
    if ($v === '' && !$allowEmpty) {
        stop('A value is required.');
    }
    return $v;
}

function ident(string $v, string $what): string
{
    if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $v)) {
        stop("$what may contain only letters, digits and underscores (max 32).");
    }
    return $v;
}

$host = $opt['host'] ?? '127.0.0.1';
$port = (int) ($opt['port'] ?? 3306);
$adminUser = $opt['admin-user'] ?? 'root';
$demo = isset($opt['demo']);
$db = ident($opt['db'] ?? ($demo ? 'hpos_demo' : 'hpos'), 'Database name');
if ($demo && !preg_match('/_(demo|scratch|test)$/', $db)) {
    stop('--demo needs a database name ending in _demo, _scratch or _test.');
}
if ($demo && isset($opt['no-config'])) {
    stop('--demo needs config/config.php, so it cannot be combined with --no-config.');
}
$appUser = ident($opt['app-user'] ?? 'hpos_app', 'App account name');
$appHost = $opt['app-host'] ?? 'localhost';
$email = $opt['admin-email'] ?? 'admin@himalayaplast.local';
$writeConfig = !isset($opt['no-config']);
$configFile = $root . '/config/config.php';

echo "HPOS install\n\n";

// ---- 1. what is needed before we touch anything -----------------------------------------------------------------
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    stop('PHP 8.0 or newer is required (this is ' . PHP_VERSION . ').');
}
foreach (['pdo_mysql', 'mbstring', 'json', 'openssl'] as $ext) {
    if (!extension_loaded($ext)) {
        stop("PHP extension '$ext' is not loaded. Enable it in php.ini and run this again.");
    }
}
$files = glob($sqlDir . '/[0-9][0-9]_*.sql') ?: [];
sort($files);
if (count($files) < 20) {
    stop('Expected the twenty SQL files in ' . $sqlDir . ' but found ' . count($files) . '.');
}
if ($writeConfig && is_file($configFile)) {
    stop('config/config.php already exists, so this looks like an installed copy. Delete it first, or use --no-config.');
}

$mysqlCandidates = array_filter([
    $opt['mysql'] ?? null,
    'mysql',
    'mariadb',
    'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysql.exe',
    'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin\\mysql.exe',
    'C:\\xampp\\mysql\\bin\\mysql.exe',
    'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysql.exe',
    '/usr/local/mysql/bin/mysql',
    '/opt/homebrew/bin/mysql',
]);
$mysqlBin = null;
foreach ($mysqlCandidates as $cand) {
    $p = proc_open([$cand, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($p)) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        if (proc_close($p) === 0) {
            $mysqlBin = $cand;
            break;
        }
    }
}
if ($mysqlBin === null) {
    stop("The 'mysql' command-line client was not found. Install MySQL / MariaDB client tools, or pass --mysql=<path>.");
}
echo OK . "PHP " . PHP_VERSION . ", extensions present, " . count($files) . " SQL files, client: $mysqlBin\n";

// ---- 2. credentials ---------------------------------------------------------------------------------------------
$adminPass = $opt['admin-pass'] ?? getenv('HPOS_DB_ADMIN_PASS');
if ($adminPass === false) {
    $adminPass = ask("MySQL password for '$adminUser' (visible as you type; Enter for none): ", true);
}
$password = $opt['password'] ?? getenv('HPOS_ADMIN_PASSWORD');
if ($password === false) {
    $password = ask("New HPOS administrator password for $email (at least 10 characters): ");
}
if (strlen($password) < 10) {
    stop('The HPOS administrator password needs at least 10 characters.');
}
$appPassGiven = isset($opt['app-pass']);
$appPass = $opt['app-pass'] ?? bin2hex(random_bytes(12));

// ---- 3. connect and check the server ----------------------------------------------------------------------------
try {
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $adminUser, $adminPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    stop("Cannot connect to $host:$port as '$adminUser': " . $e->getMessage());
}
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
$isMaria = stripos($version, 'mariadb') !== false;
preg_match('/\d+\.\d+\.\d+/', $version, $vm);
$numeric = $vm[0] ?? '0.0.0';
if ($isMaria ? version_compare($numeric, '10.6.0', '<') : version_compare($numeric, '8.0.16', '<')) {
    stop("Server $version is too old. HPOS needs MySQL 8.0.16+ or MariaDB 10.6+.");
}
echo OK . "Connected to $host:$port, server $version" . ($isMaria ? " (MariaDB has not been tested with HPOS)" : '') . "\n";

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
$st->execute([$db]);
if ((int) $st->fetchColumn() > 0) {
    stop("Database '$db' already has tables. Nothing was changed. Use another name with --db=, or drop it yourself if it is disposable.");
}
$st = $pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE user = ? AND host = ?');
$st->execute([$appUser, $appHost]);
if ((int) $st->fetchColumn() > 0 && !$appPassGiven) {
    stop("Account '$appUser'@'$appHost' already exists. Nothing was changed. Use --app-user=<other name>, or --app-pass=<its password> to reuse it.");
}

// ---- 4. the procedures-only account -----------------------------------------------------------------------------
$q = fn (string $v): string => $pdo->quote($v);
$acct = $q($appUser) . '@' . $q($appHost);
$pdo->exec("CREATE USER IF NOT EXISTS $acct IDENTIFIED BY " . $q($appPass));
if ($demo) {
    $pdo->exec("GRANT ALL ON `$db`.* TO $acct");
    echo OK . "Account '$appUser'@'$appHost' has full rights on `$db` (demo only)\n";
} else {
    $pdo->exec("GRANT EXECUTE ON `$db`.* TO $acct");
    echo OK . "Account '$appUser'@'$appHost' can only run procedures on `$db`\n";
}

// ---- 5. load the SQL files in order -----------------------------------------------------------------------------
$tmpDir = sys_get_temp_dir();
foreach ($files as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);
    $src = $file;
    $tmp = null;
    if ($db !== 'hpos') {
        $sql = preg_replace(['/\bUSE\s+hpos\s*;/i', '/CREATE DATABASE IF NOT EXISTS hpos\b/i'], ["USE `$db`;", "CREATE DATABASE IF NOT EXISTS `$db`"], $sql);
        $tmp = tempnam($tmpDir, 'hpos');
        file_put_contents($tmp, $sql);
        $src = $tmp;
    }
    $cmd = [$mysqlBin, "--host=$host", "--port=$port", "--user=$adminUser", '--default-character-set=utf8mb4', '--batch'];
    $env = array_merge(getenv(), ['MYSQL_PWD' => (string) $adminPass]);
    $proc = proc_open($cmd, [0 => ['file', $src, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $rc = proc_close($proc);
    if ($tmp !== null) {
        @unlink($tmp);
    }
    if ($rc !== 0) {
        fwrite(STDERR, trim($err . $out) . "\n");
        stop("$name failed. The database `$db` is half loaded: drop it and run this again after fixing the error above.");
    }
    echo OK . "Loaded $name\n";
}

// ---- 6. first administrator -------------------------------------------------------------------------------------
$hash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
$st = $pdo->prepare("UPDATE `$db`.users SET password_hash = ?, email = ? WHERE email = 'admin@himalayaplast.local'");
$st->execute([$hash, $email]);
if ($st->rowCount() !== 1) {
    stop('Could not find the seeded administrator row to set the password on.');
}
echo OK . "Administrator $email can sign in\n";

// ---- 7. config --------------------------------------------------------------------------------------------------
$config = [
    'db' => ['host' => $host, 'port' => $port, 'name' => $db, 'user' => $appUser, 'pass' => $appPass, 'charset' => 'utf8mb4'],
    'session_hours' => 8,
    'debug' => false,
    'setup_enabled' => true,
];
if ($writeConfig) {
    $php = "<?php\n// Written by tools/install.php. Git-ignored. Keep it private.\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($configFile, $php, LOCK_EX) === false) {
        stop('Could not write ' . $configFile . ' (is config/ writable?).');
    }
    echo OK . "Wrote config/config.php\n";
} else {
    echo OK . "Skipped config/config.php. Put this in your config: user '$appUser', password '$appPass'\n";
}

// ---- 8. server check --------------------------------------------------------------------------------------------
if ($writeConfig) {
    echo "\nServer check (tools/setup.php):\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/setup.php'), $rc);
}

// ---- 9. demo factory (only with --demo) -------------------------------------------------------------------------
if ($demo) {
    echo "\nLoading the demo factory (tools/demo_seed.php):\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/demo_seed.php') . ' --as=' . escapeshellarg($email), $rc);
    if ($rc !== 0) {
        stop('The demo loader failed (see above). The database itself is installed and usable without demo data.');
    }
}

echo "\nDone. Start it with:\n\n    php -S 127.0.0.1:8080 -t public tools/dev-router.php\n\n";
echo "then open http://127.0.0.1:8080/login and sign in as $email.\n";
