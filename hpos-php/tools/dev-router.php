<?php
declare(strict_types=1);

// Local development only. Lets PHP's built-in server serve real files (css, js) itself
// and send everything else to the app:
//   php -S 127.0.0.1:8080 -t public tools/dev-router.php
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/' && is_file(dirname(__DIR__) . '/public' . $path)) {
    return false;
}
require dirname(__DIR__) . '/public/index.php';
