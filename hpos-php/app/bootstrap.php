<?php
declare(strict_types=1);

define('HPOS_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';

use App\Core\Config;

Config::load();

date_default_timezone_set(Config::get('timezone', 'Asia/Kolkata'));

if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

$secure = is_https();
session_name('hpos_flash');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if (PHP_SAPI !== 'cli') {
    session_start();
}

// Web Push: once a request that changes data has answered, send what it raised (an order confirmed, a machine
// stopped, a QC reject...). Cheap when nobody has turned push on, and it never changes the response.
if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(30);
        try {
            App\Core\Push::dispatchPending(10);
        } catch (Throwable) {
            // nothing to do: the next request or the cron job tries again
        }
    });
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[hpos] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        // command-line tools: a plain message and a failing exit code, never an HTML page
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    try {
        App\Core\View::render('errors/500', ['title' => 'Something went wrong', 'detail' => Config::get('debug') ? $e->getMessage() : null], 'bare');
    } catch (Throwable) {
        echo 'Something went wrong.';
    }
});
