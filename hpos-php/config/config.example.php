<?php
// Copy to config/config.php and fill in. config.php is git-ignored.
// Better still: keep the real file outside the web root and point the
// HPOS_CONFIG environment variable at it.
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'hpos',
        'user'    => 'hpos_app',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'session_hours' => 8,

    // true shows exception messages on the error page. Keep false in production.
    'debug' => false,

    // true enables the /setup server-check page. Turn off after go-live.
    'setup_enabled' => true,
];
