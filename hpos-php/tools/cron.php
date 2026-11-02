<?php
declare(strict_types=1);

// Scheduled jobs, for hosts that block MySQL events. Run every 15 minutes:
//   */15 * * * * php /path/to/hpos-php/tools/cron.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Core\Push;

foreach (['sp_generate_alerts', 'sp_auth_purge_sessions'] as $proc) {
    try {
        Db::call($proc);
        echo date('c') . " $proc ok\n";
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . " $proc FAILED: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// Web Push for anything raised since the last run (does nothing when nobody has turned push on).
try {
    $r = Push::dispatchPending(200);
    echo date('c') . ' push ok: ' . $r['notifications'] . ' notifications, ' . $r['sent'] . ' sent, ' . $r['dropped'] . ' browsers dropped, ' . $r['failed'] . " failed\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' push FAILED: ' . $e->getMessage() . "\n");
}
