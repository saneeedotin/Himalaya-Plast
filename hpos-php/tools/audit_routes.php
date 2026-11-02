<?php
declare(strict_types=1);

/**
 * Lists every route with the permission it needs, and fails when one is unprotected by accident.
 *
 *   php tools/audit_routes.php           readable table, exit 1 on a problem
 *   php tools/audit_routes.php --json    machine-readable list (used by tools/smoke_phase7.ps1)
 *
 * Rules: a route is either public (auth = false, and on the allow-list below), or needs a
 * signed-in user. A signed-in route without a 'perm' must be on the "any user" allow-list.
 * The database enforces permissions on every call regardless; this catches a page that would
 * otherwise rely on that alone.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$router = require HPOS_ROOT . '/app/routes.php';
$routes = $router->routes();

if (in_array('--json', $argv, true)) {
    echo json_encode($routes), "\n";
    exit(0);
}

const PUBLIC_OK = ['GET /setup', 'GET /login', 'POST /login'];
const ANY_USER_OK = [
    'GET /', 'POST /logout', 'GET /account', 'POST /account', 'GET /account/password', 'POST /account/password',
    'GET /notifications', 'GET /notifications/poll', 'POST /push/status', 'POST /push/subscribe', 'POST /push/unsubscribe', 'POST /push/test', 'POST /notifications/read-all', 'POST /notifications/{id}/status', 'GET /search', 'GET /search/suggest',
];

$problems = [];
foreach ($routes as $r) {
    $key = $r['method'] . ' ' . $r['pattern'];
    if (!$r['auth'] && !in_array($key, PUBLIC_OK, true)) {
        $problems[] = "$key is public but not on the public allow-list";
    }
    if ($r['auth'] && $r['perm'] === null && !in_array($key, ANY_USER_OK, true)) {
        $problems[] = "$key needs sign-in only; add a permission or put it on the any-user allow-list";
    }
    // /scan/* and /stock/receive GET routes only show a form; saving is a POST
    if ($r['method'] === 'GET' && !str_starts_with($r['pattern'], '/scan/') && $r['pattern'] !== '/stock/receive' && preg_match('#/(delete|cancel|submit|confirm|status|receive|accept|log|start|end)$#', $r['pattern'])) {
        $problems[] = "$key changes data but uses GET";
    }
    printf("%-6s %-42s %s\n", $r['method'], $r['pattern'], !$r['auth'] ? 'public' : ($r['perm'] ? $r['perm'][0] . ':' . $r['perm'][1] : 'any signed-in user'));
}

echo "\n", count($routes), " routes. ";
if ($problems) {
    echo count($problems), " problem(s):\n - ", implode("\n - ", $problems), "\n";
    exit(1);
}
echo "No problems.\n";
