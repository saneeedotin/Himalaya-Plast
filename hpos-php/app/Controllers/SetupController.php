<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\ServerCheck;
use App\Core\View;

final class SetupController
{
    public function index(): void
    {
        // Open while no config exists (first install) or while setup_enabled is true.
        if (Config::exists() && !Config::get('setup_enabled', false)) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Page not found', 'code' => 404, 'message' => 'Page not found'], 'bare');
            return;
        }
        $results = ServerCheck::run();
        View::render('setup/index', ['title' => 'Server check', 'results' => $results, 'failed' => ServerCheck::failed($results)], 'bare');
    }
}
