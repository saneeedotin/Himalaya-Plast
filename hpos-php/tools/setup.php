<?php
declare(strict_types=1);

// Command-line server check:  php tools/setup.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\ServerCheck;

$results = ServerCheck::run();
foreach ($results as $r) {
    printf("[%-4s] %-26s %s\n", strtoupper($r['status']), $r['label'], $r['detail']);
}
$failed = ServerCheck::failed($results);
echo $failed ? "\nFix the FAIL lines above.\n" : "\nThis server can run HPOS.\n";
exit($failed ? 1 : 0);
