<?php
declare(strict_types=1);

/**
 * Calls one stored procedure and prints its result sets as JSON. For tests and one-off checks;
 * "@admin" in the arguments stands for the seeded admin's id.
 *
 *   php tools/dbcall.php sp_po_receive '["@admin","<po id>"]'
 *
 * Refuses to run unless the database name ends in _scratch or _test.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Db;

if (!preg_match('/_(scratch|test)$/', (string) Config::get('db.name'))) {
    fwrite(STDERR, "Refusing to run against '" . Config::get('db.name') . "'.\n");
    exit(2);
}

$proc = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '[]', true);
if ($proc === '' || !is_array($args)) {
    fwrite(STDERR, "usage: php tools/dbcall.php <procedure> '<json array of arguments>'\n");
    exit(2);
}

// through a procedure, so this works for an account that may only EXECUTE
// "@admin" is the administrator in config/dev-admin.txt (line 1), else the seeded admin@himalayaplast.local
$devAdmin = is_file(__DIR__ . '/../config/dev-admin.txt') ? trim((string) (file(__DIR__ . '/../config/dev-admin.txt')[0] ?? '')) : '';
$admin = (string) (Db::row('sp_auth_get_user_by_email', [$devAdmin !== '' ? $devAdmin : 'admin@himalayaplast.local'])['id'] ?? '');
$args = array_map(static fn ($a) => $a === '@admin' ? $admin : $a, $args);

try {
    echo json_encode(Db::call($proc, $args), JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
